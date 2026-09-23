-- Schema-only excerpt from 2018-06-18-crown-points.sql (award content comes from extract).

ALTER TABLE `ork_award` ADD `crown_points` INT(2) NOT NULL DEFAULT '0' AFTER `peerage`;
ALTER TABLE `ork_award` ADD `crown_limit` INT(2) NOT NULL DEFAULT '0' AFTER `crown_points`;
ALTER TABLE `ork_award` CHANGE `peerage` `peerage` ENUM('Knight','Squire','Man-At-Arms','Page','Lords-Page','None','Master','Paragon','Apprentice','Kingdom-Level-Award') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'None';
