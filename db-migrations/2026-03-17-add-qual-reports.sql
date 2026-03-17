-- Player question feedback / reports
CREATE TABLE IF NOT EXISTS `ork_qual_report` (
    `qual_report_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `qual_question_id` INT UNSIGNED NOT NULL,
    `player_id`        INT UNSIGNED NOT NULL DEFAULT 0,
    `reason`           ENUM('wording','correct','outdated','other') NOT NULL,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`qual_report_id`),
    INDEX `idx_question` (`qual_question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
