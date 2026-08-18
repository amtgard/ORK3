CREATE TABLE `ork_whats_new_seen` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mundane_id` int(11) NOT NULL,
  `version` varchar(32) NOT NULL,
  `seen_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_version` (`mundane_id`, `version`),
  KEY `mundane_id` (`mundane_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
