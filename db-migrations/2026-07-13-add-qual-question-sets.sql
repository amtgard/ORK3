-- Qualification-test question SETS (versioning).
--
-- Design: docs/superpowers/plans/2026-07-13-qual-test-question-sets.md
--
-- Problem: a live test draws ANY active question, so editing / archiving / adding a
-- question changes the live test immediately. There was no way to build the "8.7 bank"
-- while 8.6 stayed live, short of taking the test offline for everyone.
--
-- A per-question `draft` status does NOT solve this: publishing by swapping all drafts
-- to active would force cloning every carried-over question (30 rows to ship 5 changes)
-- and would reset each clone's qual_question_stat / detach its qual_report flags.
--
-- The fix: versioning belongs to the SET, not the question. A question is a MEMBER of
-- many sets, so a question unchanged between 8.6 and 8.7 simply belongs to both — zero
-- duplication, identity and stats preserved. The live test draws from the one published
-- set:  draw = (member of published set) AND (question.status = 'active').
--
-- question.status stays and is ORTHOGONAL to membership:
--   status='archived'      -> the question is dead everywhere (global kill switch)
--   not in the draft set   -> not part of v2, but still LIVE in v1 until you publish
--
-- This migration is BEHAVIOR-PRESERVING: it backfills one 'published' set per
-- kingdom+test containing exactly today's active questions, so
-- (published ∩ active) returns precisely what status='active' returned before.
-- Nothing changes until someone creates a draft.

CREATE TABLE IF NOT EXISTS `ork_qual_question_set` (
    `qual_question_set_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kingdom_id`           INT UNSIGNED NOT NULL,
    `test_type`            ENUM('reeve','corpora') NOT NULL,
    `name`                 VARCHAR(100) NOT NULL,
    -- Required (non-empty) to publish, but NOT required to differ from the previous set:
    -- a new GMR may publish a fresh bank under an unchanged ruleset.
    `rules_version`        VARCHAR(100) NOT NULL DEFAULT '',
    `status`               ENUM('draft','published','retired') NOT NULL DEFAULT 'draft',
    `created_by`           INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`           DATETIME NOT NULL DEFAULT current_timestamp(),
    `published_at`         DATETIME NULL,

    -- Exactly one published, and at most one draft, per kingdom+test — enforced by the
    -- DB. The slot is NULL for other statuses, and MariaDB permits many NULLs in a
    -- UNIQUE index, so unlimited 'retired' sets coexist while these two stay singular.
    -- This also makes a double-publish race impossible.
    `published_slot`       TINYINT UNSIGNED AS (IF(`status` = 'published', 1, NULL)) STORED,
    `draft_slot`           TINYINT UNSIGNED AS (IF(`status` = 'draft',     1, NULL)) STORED,

    PRIMARY KEY (`qual_question_set_id`),
    UNIQUE KEY `uq_one_published` (`kingdom_id`, `test_type`, `published_slot`),
    UNIQUE KEY `uq_one_draft`     (`kingdom_id`, `test_type`, `draft_slot`),
    KEY `idx_kingdom_type_status` (`kingdom_id`, `test_type`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Many-to-many membership. THIS is what avoids duplicating carried-over questions.
CREATE TABLE IF NOT EXISTS `ork_qual_set_question` (
    `qual_question_set_id` INT UNSIGNED NOT NULL,
    `qual_question_id`     INT UNSIGNED NOT NULL,
    PRIMARY KEY (`qual_question_set_id`, `qual_question_id`),
    KEY `idx_question` (`qual_question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Stamp WHICH SET a player sat, alongside rules_version. Needed because a new GMR may
-- publish a fresh bank under the SAME rules_version, so the version label alone cannot
-- identify the test taken. set_name is a free-text snapshot (like rules_version) so it
-- stays truthful if the set is later renamed.
ALTER TABLE `ork_qual_attempt`
    ADD COLUMN IF NOT EXISTS `qual_question_set_id` INT UNSIGNED NULL AFTER `rules_version`,
    ADD COLUMN IF NOT EXISTS `set_name`             VARCHAR(100) NOT NULL DEFAULT '' AFTER `qual_question_set_id`;

ALTER TABLE `ork_qual_result`
    ADD COLUMN IF NOT EXISTS `qual_question_set_id` INT UNSIGNED NULL AFTER `rules_version`,
    ADD COLUMN IF NOT EXISTS `set_name`             VARCHAR(100) NOT NULL DEFAULT '' AFTER `qual_question_set_id`;

-- ── Backfill (behavior-preserving) ──────────────────────────────────────────────
-- One 'Current' published set per kingdom+test that has any questions...
INSERT INTO `ork_qual_question_set`
       (`kingdom_id`, `test_type`, `name`, `rules_version`, `status`, `created_at`, `published_at`)
SELECT DISTINCT q.`kingdom_id`, q.`test_type`, 'Current',
       COALESCE(c.`rules_version`, ''), 'published', NOW(), NOW()
FROM `ork_qual_question` q
LEFT JOIN `ork_qual_config` c
       ON c.`kingdom_id` = q.`kingdom_id` AND c.`test_type` = q.`test_type`
WHERE NOT EXISTS (
    SELECT 1 FROM `ork_qual_question_set` s
     WHERE s.`kingdom_id` = q.`kingdom_id` AND s.`test_type` = q.`test_type`
);

-- ...containing exactly today's ACTIVE questions. Archived ones are deliberately not
-- members: they're dead regardless, and the draw also filters on status='active'.
INSERT IGNORE INTO `ork_qual_set_question` (`qual_question_set_id`, `qual_question_id`)
SELECT s.`qual_question_set_id`, q.`qual_question_id`
FROM `ork_qual_question` q
JOIN `ork_qual_question_set` s
       ON s.`kingdom_id` = q.`kingdom_id`
      AND s.`test_type`  = q.`test_type`
      AND s.`status`     = 'published'
WHERE q.`status` = 'active';
