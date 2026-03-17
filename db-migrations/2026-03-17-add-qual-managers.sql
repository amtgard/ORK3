-- Qual Test Managers
-- Players listed here can manage qualification tests for the kingdom
-- even if they don't hold standard officer/editor auth.
CREATE TABLE IF NOT EXISTS `ork_qual_manager` (
    `qual_manager_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kingdom_id`      INT UNSIGNED NOT NULL,
    `mundane_id`      INT UNSIGNED NOT NULL,
    `added_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`qual_manager_id`),
    UNIQUE KEY `uq_kingdom_mundane` (`kingdom_id`, `mundane_id`),
    INDEX `idx_kingdom` (`kingdom_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
