ALTER TABLE `ork_award` ADD `crown_points` INT(2) NOT NULL DEFAULT '0' AFTER `peerage`;
ALTER TABLE `ork_award` ADD `crown_limit` INT(2) NOT NULL DEFAULT '0' AFTER `crown_points`;
ALTER TABLE `ork_award` CHANGE `peerage` `peerage` ENUM('Knight','Squire','Man-At-Arms','Page','Lords-Page','None','Master','Paragon','Apprentice','Kingdom-Level-Award') CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT 'None';
INSERT INTO `ork_award` (`award_id`, `name`, `proposed_name`, `deprecate`, `is_ladder`, `is_title`, `title_class`, `peerage`, `crown_points`, `crown_limit`) VALUES (NULL, 'Principality Monarch', '', '0', '0', '0', '', 'Kingdom-Level-Award', '2', '0'), (NULL, 'Principality Regent', '', '0', '0', '0', '', 'Kingdom-Level-Award', '1', '0'), (NULL, 'Principality Champion', '', '0', '0', '0', '', 'Kingdom-Level-Award', '1', '0'), (NULL, 'Principality Prime Minister', '', '0', '0', '0', '', 'Kingdom-Level-Award', '1', '0');
insert into ork_kingdomaward (is_title, title_class, kingdom_id, award_id, name) select is_title, title_class, kingdom_id, award_id, a.name from ork_kingdom k cross join ork_award a where a.award_id in (234, 235, 236, 237);
update ork_award set crown_points = 1 where award_id in (75, 76, 79, 80, 83, 84, 87, 88, 89, 90, 91, 235, 236, 237);\
update ork_award set crown_points = 2 where award_id in (73, 74, 92);
update ork_award set peerage = 'Kingdom-Level-Award' where award_id in (89, 90, 91, 92);
update ork_award set crown_limit = 1 where award_id in (87, 88, 89, 236);