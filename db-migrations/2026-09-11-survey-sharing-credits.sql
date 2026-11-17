-- Survey module: results sharing (rolldown) and bonus credits.
-- Spec: docs/superpowers/specs/2026-09-10-survey-sharing-and-credits-design.md §2–§4.
-- Idempotent: ADD ... IF NOT EXISTS, CREATE TABLE IF NOT EXISTS and a guarded
-- backfill. After applying: docker restart ork3-php8-app (APCu schema cache).
--
-- 2026-11-11: the survey credit became a BONUS credit (ork_bonus_credit, the
-- 2026-10-14 migration) instead of an attendance row. This file never ran in
-- production, so the new shape is folded in here rather than converted by a
-- later migration. A dev or staging database that applied the earlier shape
-- must DROP ork_survey_credit and ork_survey_credit_grant before re-running it
-- (the old columns mode/event_id/event_calendardetail_id and attendance_id are
-- gone; the 'survey' value this file once appended to ork_attendance.entry_method
-- is no longer written and may stay in such a database's ENUM harmlessly).

ALTER TABLE ork_survey
  ADD COLUMN IF NOT EXISTS results_share ENUM('none','scoped','all') NOT NULL DEFAULT 'none' AFTER data_gate_enabled,
  -- When shared viewers get results: live, or 24h after the survey stops taking responses.
  ADD COLUMN IF NOT EXISTS results_share_timing ENUM('ongoing','after_close') NOT NULL DEFAULT 'after_close' AFTER results_share,
  -- The survey's own bonus-credit category (ork_bonus_credit_category with
  -- source_type 'survey', source_id = survey_id), created by the system the
  -- first time any org turns credit on. NULL until then. One per survey:
  -- kingdom-scope for a kingdom or park survey (the owning kingdom), ork-scope
  -- for an Amtgard-wide one.
  ADD COLUMN IF NOT EXISTS bonus_category_id INT UNSIGNED NULL AFTER results_share_timing;

ALTER TABLE ork_survey_response
  ADD COLUMN IF NOT EXISTS park_id INT NULL AFTER kingdom_id,
  ADD INDEX IF NOT EXISTS idx_survey_park (survey_id, park_id);

-- 1 when the data gate showed this Any ORK Data respondent a bonus-credit
-- line before they chose (spec D1). Only such responses are ever credited, by
-- the live grant or a backfill. No backfill: existing rows were never told.
ALTER TABLE ork_survey_response
  ADD COLUMN IF NOT EXISTS credit_notice TINYINT(1) NOT NULL DEFAULT 0 AFTER park_id;

-- Park snapshot for existing Any ORK Data rows only; never overwrites a snapshot.
UPDATE ork_survey_response r
  JOIN ork_mundane m ON m.mundane_id = r.mundane_id
   SET r.park_id = NULLIF(m.park_id, 0)
 WHERE r.consent = 'full' AND r.mundane_id IS NOT NULL AND r.park_id IS NULL;

-- One org's switch: "credit the players I cover for this survey". A park
-- covers its snapshotted members, a kingdom its kingdom and principalities,
-- the survey's OWNER everyone who answered (visitors included, subject to
-- their kingdom accepting outside credits). Switching off stamps disabled_at:
-- responses submitted before it are still owed, later ones are not; switching
-- on again clears the stamps. Precedence among covering switches: earliest
-- enabled_at wins.
CREATE TABLE IF NOT EXISTS ork_survey_credit (
  credit_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  survey_id      INT UNSIGNED NOT NULL,
  grantor_type   ENUM('kingdom','park') NOT NULL,
  grantor_id     INT NOT NULL,
  enabled_by     INT NOT NULL,          -- becomes granted_by on every credit
  enabled_at     DATETIME NOT NULL,     -- precedence: earliest wins
  disabled_by    INT NULL,
  disabled_at    DATETIME NULL,
  PRIMARY KEY (credit_id),
  UNIQUE KEY uq_survey_grantor (survey_id, grantor_type, grantor_id),
  KEY idx_survey_enabled (survey_id, enabled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;

-- One credit per player per survey, written in the same transaction as the
-- bonus credit. The row stays when the credit is revoked, so a revoked credit
-- is never granted again.
CREATE TABLE IF NOT EXISTS ork_survey_credit_grant (
  survey_id        INT UNSIGNED NOT NULL,
  mundane_id       INT NOT NULL,
  credit_id        INT UNSIGNED NOT NULL,
  bonus_credit_id  INT UNSIGNED NOT NULL,
  PRIMARY KEY (survey_id, mundane_id),
  KEY idx_credit (credit_id),
  KEY idx_bonus (bonus_credit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
