-- Global Question Library opt-in flag (reeve type only, but stored on all rows)
ALTER TABLE `ork_qual_config`
    ADD COLUMN `share_questions` TINYINT(1) NOT NULL DEFAULT 0 AFTER `max_retakes`;
