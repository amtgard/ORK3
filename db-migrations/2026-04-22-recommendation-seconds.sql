-- Award Recommendation Seconds
-- Adds support for players to "second" an existing award recommendation,
-- optionally with notes, without filing their own duplicate recommendation.

CREATE TABLE `ork_recommendation_seconds` (
  `recommendation_seconds_id` int(11) NOT NULL AUTO_INCREMENT,
  `recommendations_id` int(11) NOT NULL,
  `supporter_mundane_id` int(11) NOT NULL,
  `notes` varchar(400) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`recommendation_seconds_id`),
  UNIQUE KEY `uniq_rec_supporter` (`recommendations_id`, `supporter_mundane_id`),
  KEY `idx_supporter` (`supporter_mundane_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
