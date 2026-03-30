ALTER TABLE `ork_kingdom`
  ADD COLUMN `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `parent_kingdom_id`,
  ADD COLUMN `url` varchar(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `description`;
