-- Player Milestones: custom user-defined milestones + per-player milestone display config

CREATE TABLE IF NOT EXISTS `ork_player_milestones` (
  `milestone_id` int(11) NOT NULL AUTO_INCREMENT,
  `mundane_id` int(11) NOT NULL,
  `icon` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'fa-star',
  `description` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `milestone_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`milestone_id`),
  KEY `mundane_id` (`mundane_id`),
  KEY `milestone_date` (`milestone_date`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `ork_mundane`
  ADD COLUMN `milestone_config` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;
