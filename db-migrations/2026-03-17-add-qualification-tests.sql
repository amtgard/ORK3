-- Qualification Tests Module
-- Questions bank (kingdom-scoped)
CREATE TABLE IF NOT EXISTS `ork_qual_question` (
    `qual_question_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kingdom_id`       INT UNSIGNED NOT NULL,
    `test_type`        ENUM('reeve','corpora') NOT NULL,
    `question_text`    TEXT NOT NULL,
    `status`           ENUM('active','archived') NOT NULL DEFAULT 'active',
    `created_by`       INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`qual_question_id`),
    INDEX `idx_kingdom_type_status` (`kingdom_id`, `test_type`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Multiple-choice answers per question
CREATE TABLE IF NOT EXISTS `ork_qual_answer` (
    `qual_answer_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `qual_question_id`  INT UNSIGNED NOT NULL,
    `answer_text`       TEXT NOT NULL,
    `is_correct`        TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`qual_answer_id`),
    INDEX `idx_question` (`qual_question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Per-kingdom test configuration (one row per kingdom+type)
CREATE TABLE IF NOT EXISTS `ork_qual_config` (
    `qual_config_id`  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kingdom_id`      INT UNSIGNED NOT NULL,
    `test_type`       ENUM('reeve','corpora') NOT NULL,
    `question_count`  INT NOT NULL DEFAULT 10,
    `pass_percent`    INT NOT NULL DEFAULT 70,
    `valid_days`      INT NOT NULL DEFAULT 365,
    PRIMARY KEY (`qual_config_id`),
    UNIQUE KEY `uq_kingdom_type` (`kingdom_id`, `test_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Player test results (one row per player+kingdom+type; upserted on retake)
CREATE TABLE IF NOT EXISTS `ork_qual_result` (
    `qual_result_id`  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `player_id`       INT UNSIGNED NOT NULL,
    `kingdom_id`      INT UNSIGNED NOT NULL,
    `test_type`       ENUM('reeve','corpora') NOT NULL,
    `score_percent`   INT NOT NULL DEFAULT 0,
    `passed_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`      DATETIME NOT NULL,
    PRIMARY KEY (`qual_result_id`),
    UNIQUE KEY `uq_player_kingdom_type` (`player_id`, `kingdom_id`, `test_type`),
    INDEX `idx_player` (`player_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
