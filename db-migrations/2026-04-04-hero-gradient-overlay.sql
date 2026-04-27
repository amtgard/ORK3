ALTER TABLE `ork_mundane`
  ADD COLUMN `color_secondary` varchar(7) DEFAULT NULL AFTER `color_accent`,
  ADD COLUMN `hero_overlay` varchar(4) NOT NULL DEFAULT 'med' AFTER `color_secondary`;
