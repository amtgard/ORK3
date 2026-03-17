-- Max retakes config per kingdom+type
ALTER TABLE `ork_qual_config`
    ADD COLUMN `max_retakes` INT NOT NULL DEFAULT 0 AFTER `valid_until`;
-- 0 = unlimited

-- Per-player retake counter (tracks total submissions, pass or fail)
CREATE TABLE IF NOT EXISTS `ork_qual_retake` (
    `qual_retake_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `player_id`      INT UNSIGNED NOT NULL,
    `kingdom_id`     INT UNSIGNED NOT NULL,
    `test_type`      ENUM('reeve','corpora') NOT NULL,
    `retake_count`   INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`qual_retake_id`),
    UNIQUE KEY `uq_player_kingdom_type` (`player_id`, `kingdom_id`, `test_type`),
    INDEX `idx_kingdom_type` (`kingdom_id`, `test_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
