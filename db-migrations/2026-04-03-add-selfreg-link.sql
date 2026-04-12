-- Self-Registration QR feature: stores temporary self-reg tokens
-- Run: docker exec -i ork3-php8-db mariadb -u root -proot ork < migration_selfreg_link.sql

CREATE TABLE IF NOT EXISTS `ork_selfreg_link` (
  `selfreg_id`  int(11)      NOT NULL AUTO_INCREMENT,
  `token`       char(48)     NOT NULL,
  `park_id`     int(11) unsigned NOT NULL,
  `created_by`  int(11) unsigned NOT NULL,
  `created_at`  datetime     NOT NULL,
  `expires_at`  datetime     NOT NULL,
  `used_by`     int(11) unsigned DEFAULT NULL COMMENT 'mundane_id of the player who used this token (NULL if unused)',
  `used_at`     datetime     DEFAULT NULL,
  PRIMARY KEY (`selfreg_id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_expires` (`expires_at`),
  KEY `idx_park` (`park_id`),
  KEY `idx_park_active` (`park_id`, `used_by`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
