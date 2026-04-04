ALTER TABLE `ork_mundane`
  ADD COLUMN `pronunciation_guide` varchar(200) DEFAULT NULL AFTER `show_beltline`,
  ADD COLUMN `show_mundane_first` tinyint(1) unsigned NOT NULL DEFAULT 1 AFTER `pronunciation_guide`,
  ADD COLUMN `show_mundane_last` tinyint(1) unsigned NOT NULL DEFAULT 1 AFTER `show_mundane_first`,
  ADD COLUMN `show_email` tinyint(1) unsigned NOT NULL DEFAULT 1 AFTER `show_mundane_last`;
