-- Player Profile Customization columns
-- Adds fields for: About section (markdown), color scheme, name prefix/suffix, photo focus point

ALTER TABLE `ork_mundane`
  ADD COLUMN `about_persona` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `corpora_qualified_until`,
  ADD COLUMN `about_story` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `about_persona`,
  ADD COLUMN `color_primary` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `about_story`,
  ADD COLUMN `color_accent` varchar(7) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `color_primary`,
  ADD COLUMN `name_prefix` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `color_accent`,
  ADD COLUMN `name_suffix` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `name_prefix`,
  ADD COLUMN `photo_focus_x` tinyint(3) unsigned DEFAULT 50 AFTER `name_suffix`,
  ADD COLUMN `photo_focus_y` tinyint(3) unsigned DEFAULT 50 AFTER `photo_focus_x`,
  ADD COLUMN `photo_focus_size` tinyint(3) unsigned DEFAULT 100 AFTER `photo_focus_y`;
