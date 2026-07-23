-- Schema-only excerpt from 2022-11-17-pronoun-feature (pronoun rows come from extract).

ALTER TABLE ork_mundane
ADD pronoun_id int(11) DEFAULT NULL
AFTER `other_name`;

ALTER TABLE ork_mundane
ADD pronoun_custom varchar(255) DEFAULT NULL
AFTER `pronoun_id`;

CREATE TABLE `ork_pronoun` (
  `pronoun_id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `subject` varchar(30) DEFAULT NULL,
  `object` varchar(30) DEFAULT NULL,
  `possessive` varchar(30) DEFAULT NULL,
  `possessivepronoun` varchar(30) DEFAULT NULL,
  `reflexive` varchar(30) DEFAULT NULL,
  PRIMARY KEY (`pronoun_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
