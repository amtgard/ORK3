-- Migration: Create ork_officer_history table for officer change tracking
-- Run via: docker exec -i ork3-php8-db mariadb -u root -proot ork < migrations/officer_history.sql

CREATE TABLE IF NOT EXISTS `ork_officer_history` (
  `officer_history_id` int(11) NOT NULL AUTO_INCREMENT,
  `kingdom_id` int(11) NOT NULL,
  `park_id` int(11) NOT NULL DEFAULT 0,
  `mundane_id` int(11) NOT NULL,
  `role` enum('Monarch','Regent','Prime Minister','Champion','GMR') NOT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`officer_history_id`),
  KEY `idx_oh_kingdom_park_role` (`kingdom_id`, `park_id`, `role`),
  KEY `idx_oh_mundane` (`mundane_id`),
  KEY `idx_oh_kingdom_park_role_end` (`kingdom_id`, `park_id`, `role`, `end_date`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
