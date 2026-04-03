-- Sign-in Link feature: stores temporary shareable attendance links
-- Run: docker exec -i ork3-php8-db mariadb -u root -proot ork < migration_attendance_link.sql

CREATE TABLE IF NOT EXISTS `ork_attendance_link` (
  `link_id`    int(11)       NOT NULL AUTO_INCREMENT,
  `token`      varchar(64)   NOT NULL,
  `park_id`    int(11)       NOT NULL DEFAULT 0,
  `kingdom_id` int(11)       NOT NULL DEFAULT 0,
  `by_whom_id` int(11)       NOT NULL,
  `credits`    double(4,2)   NOT NULL DEFAULT 1.00,
  `expires_at` datetime      NOT NULL,
  `created_at` datetime      NOT NULL,
  PRIMARY KEY (`link_id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
