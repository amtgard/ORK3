CREATE TABLE `ork_recommendations` (
  `recommendations_id` int(11) NOT NULL AUTO_INCREMENT,
  `mundane_id` int(11) NOT NULL,
  `kingdomaward_id` int(11) NOT NULL,
  `award_id` int(11) NOT NULL,
  `rank` int(11) NOT NULL,
  `recommended_by_id` int(11) NOT NULL,
  `date_recommended` date NOT NULL,
  `reason` varchar(400) NOT NULL,
  PRIMARY KEY (`recommendations_id`),
  KEY `award_id` (`award_id`),
  KEY `recommended_by_id` (`recommended_by_id`),
  KEY `mundane_id` (`mundane_id`),
  KEY `kingdomaward_id` (`kingdomaward_id`)
) ENGINE=MyISAM AUTO_INCREMENT=1 DEFAULT CHARSET=utf8;