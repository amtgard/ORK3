-- Schema supplements applied after ork.sql so extracted catalogs and generated rows match mirror shape.

ALTER TABLE `ork_award`
  ADD COLUMN `proposed_name` varchar(100) NOT NULL DEFAULT '' AFTER `name`,
  ADD COLUMN `deprecate` tinyint(1) NOT NULL DEFAULT '0' AFTER `proposed_name`,
  ADD COLUMN `crown_points` int(2) NOT NULL DEFAULT '0' AFTER `peerage`,
  ADD COLUMN `crown_limit` int(2) NOT NULL DEFAULT '0' AFTER `crown_points`,
  ADD COLUMN `officer_role` enum('kingdom','park','principality','bod','other-officer','none') NOT NULL DEFAULT 'none' AFTER `crown_limit`;

ALTER TABLE `ork_mundane`
  ADD COLUMN `pronoun_id` int(11) DEFAULT NULL AFTER `other_name`,
  ADD COLUMN `pronoun_custom` varchar(255) DEFAULT NULL AFTER `pronoun_id`;

CREATE TABLE IF NOT EXISTS `ork_pronoun` (
  `pronoun_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `subject` varchar(30) DEFAULT NULL,
  `object` varchar(30) DEFAULT NULL,
  `possessive` varchar(30) DEFAULT NULL,
  `possessivepronoun` varchar(30) DEFAULT NULL,
  `reflexive` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`pronoun_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
