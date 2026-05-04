ALTER TABLE `ork_mundane`
  ADD COLUMN `suffix_comma` tinyint(1) unsigned NOT NULL DEFAULT 0 AFTER `name_suffix`;
