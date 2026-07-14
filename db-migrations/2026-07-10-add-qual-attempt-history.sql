-- Durable qualification-test attempt history ("Review Your Answers", for all time).
--
-- Today the ONLY persisted record of a test is ork_qual_result, which is an
-- upsert keyed unique on (player_id, kingdom_id, test_type): it holds exactly
-- one row per player per test and is overwritten on each pass. It records
-- current qualification STATE, not history, and it is written ONLY when a player
-- passes -- so a failure leaves no trace and "never took" is indistinguishable
-- from "took and failed."
--
-- These two tables add an APPEND-ONLY log alongside that state. ork_qual_result
-- is left untouched (it stays the authoritative "currently qualified" record).
--
-- Why SNAPSHOT the text instead of foreign-keying to the live question/answer
-- rows: QualTest::saveQuestion() DELETEs and re-INSERTs a question's answer rows
-- on every edit (brand-new qual_answer_id each time), and questions can be
-- archived. A review that joined to the live rows would render blank or wrong
-- the moment a question was ever edited. So we copy the exact question + option
-- text the player saw into the attempt at submit time; the *_id columns are kept
-- only as soft references (nullable, no FK) for optional analytics linkage and
-- are NOT trusted for rendering.

-- One row per test submission -- pass OR fail -- kept forever.
CREATE TABLE IF NOT EXISTS `ork_qual_attempt` (
    `qual_attempt_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `player_id`       INT UNSIGNED NOT NULL,
    `kingdom_id`      INT UNSIGNED NOT NULL,
    `test_type`       ENUM('reeve','corpora') NOT NULL,
    `score_percent`   INT NOT NULL,
    -- Threshold in force AT attempt time; config PassPercent drifts, so snapshot
    -- it too or an old review can't honestly say "you needed X%".
    `pass_percent`    INT NOT NULL,
    `passed`          TINYINT(1) NOT NULL,
    `taken_at`        DATETIME NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`qual_attempt_id`),
    KEY `idx_player_type_taken` (`player_id`, `test_type`, `taken_at`),
    KEY `idx_kingdom_type_taken` (`kingdom_id`, `test_type`, `taken_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per (attempt, question, option-shown): a full snapshot of every option
-- the player was presented, with flags for which were correct and which they
-- picked. Storing every option (not just picked/correct) lets the review render
-- the rejected distractors even after the question is later edited or archived.
CREATE TABLE IF NOT EXISTS `ork_qual_attempt_answer` (
    `qual_attempt_answer_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `qual_attempt_id`        INT UNSIGNED NOT NULL,
    `qual_question_id`       INT UNSIGNED NULL,   -- soft ref (question survives edits); analytics only
    `question_text`          TEXT NOT NULL,       -- snapshot
    `answer_mode`            ENUM('single','multi') NOT NULL,
    `question_order`         INT NOT NULL DEFAULT 0, -- presentation order within the attempt
    `qual_answer_id`         INT UNSIGNED NULL,   -- soft ref only; regenerated on edit, NOT trusted
    `answer_text`            TEXT NOT NULL,       -- snapshot of this option
    `is_correct`             TINYINT(1) NOT NULL, -- was this option correct, at attempt time
    `was_selected`           TINYINT(1) NOT NULL, -- did the player pick it
    PRIMARY KEY (`qual_attempt_answer_id`),
    KEY `idx_attempt` (`qual_attempt_id`),
    KEY `idx_question` (`qual_question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
