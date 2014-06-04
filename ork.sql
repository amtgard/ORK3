-- phpMyAdmin SQL Dump
-- version 3.3.10.4
-- http://www.phpmyadmin.net
--
-- Host: mysql.amtgard.com
-- Generation Time: Nov 06, 2013 at 12:53 PM
-- Server version: 5.1.56
-- PHP Version: 5.3.27

SET SQL_MODE="NO_AUTO_VALUE_ON_ZERO";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8 */;

--
-- Database: `amtgard_ork3production`
--

-- --------------------------------------------------------

--
-- Table structure for table `ork_account`
--

DROP TABLE IF EXISTS `ork_account`;
CREATE TABLE IF NOT EXISTS `ork_account` (
  `account_id` int(11) NOT NULL AUTO_INCREMENT,
  `parent_id` int(11) NOT NULL,
  `type` enum('Imbalance','Income','Expense','Asset','Liability','Equity') NOT NULL,
  `name` varchar(50) NOT NULL,
  `kingdom_id` int(11) NOT NULL,
  `park_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  PRIMARY KEY (`account_id`),
  KEY `event_id` (`event_id`),
  KEY `kingdom_id` (`kingdom_id`),
  KEY `parent_id` (`parent_id`),
  KEY `park_id` (`park_id`),
  KEY `unit_id` (`unit_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=10003 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_application`
--

DROP TABLE IF EXISTS `ork_application`;
CREATE TABLE IF NOT EXISTS `ork_application` (
  `application_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `description` varchar(255) NOT NULL,
  `url` varchar(255) NOT NULL,
  `mundane_id` int(11) NOT NULL,
  `appid` varchar(35) NOT NULL,
  `app_salt` varchar(35) NOT NULL,
  `appid_expires` datetime NOT NULL,
  PRIMARY KEY (`application_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_application_auth`
--

DROP TABLE IF EXISTS `ork_application_auth`;
CREATE TABLE IF NOT EXISTS `ork_application_auth` (
  `application_auth_id` int(11) NOT NULL AUTO_INCREMENT,
  `application_id` int(11) NOT NULL,
  `mundane_id` int(11) NOT NULL,
  `approved` enum('submitted','approved','rejected') NOT NULL DEFAULT 'submitted',
  `appauthkey` varchar(35) NOT NULL,
  `token` varchar(35) NOT NULL,
  `token_expires` datetime NOT NULL,
  PRIMARY KEY (`application_auth_id`),
  UNIQUE KEY `application_id` (`application_id`,`mundane_id`),
  UNIQUE KEY `appauthkey` (`appauthkey`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_attendance`
--

DROP TABLE IF EXISTS `ork_attendance`;
CREATE TABLE IF NOT EXISTS `ork_attendance` (
  `attendance_id` int(11) NOT NULL AUTO_INCREMENT,
  `mundane_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `date` date NOT NULL,
  `park_id` int(11) NOT NULL,
  `kingdom_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `event_calendardetail_id` int(11) NOT NULL,
  `credits` double(4,2) NOT NULL,
  `persona` varchar(200) NOT NULL,
  `flavor` varchar(20) NOT NULL,
  `note` varchar(20) NOT NULL,
  PRIMARY KEY (`attendance_id`),
  UNIQUE KEY `mundane_id` (`mundane_id`,`date`,`park_id`,`kingdom_id`,`event_id`,`event_calendardetail_id`,`persona`,`note`),
  KEY `class_id` (`class_id`),
  KEY `date` (`date`),
  KEY `event_calendardetail_id` (`event_calendardetail_id`),
  KEY `event_id` (`event_id`),
  KEY `kingdom_id` (`kingdom_id`),
  KEY `mundane_id_2` (`mundane_id`),
  KEY `park_id` (`park_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1222742 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_authorization`
--

DROP TABLE IF EXISTS `ork_authorization`;
CREATE TABLE IF NOT EXISTS `ork_authorization` (
  `authorization_id` int(11) NOT NULL AUTO_INCREMENT,
  `mundane_id` int(11) NOT NULL,
  `park_id` int(11) NOT NULL,
  `kingdom_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `role` enum('edit','create','admin') NOT NULL DEFAULT 'edit',
  `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`authorization_id`),
  KEY `event_id` (`event_id`),
  KEY `kingdom_id` (`kingdom_id`),
  KEY `mundane_id` (`mundane_id`),
  KEY `park_id` (`park_id`),
  KEY `role` (`role`),
  KEY `unit_id` (`unit_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=2358 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_award`
--

DROP TABLE IF EXISTS `ork_award`;
CREATE TABLE IF NOT EXISTS `ork_award` (
  `award_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `is_ladder` tinyint(1) NOT NULL DEFAULT '0',
  `is_title` tinyint(1) NOT NULL DEFAULT '0',
  `title_class` int(11) NOT NULL,
  `peerage` enum('Knight','Squire','Man-At-Arms','Page','Lords-Page','None','Master') NOT NULL DEFAULT 'None',
  PRIMARY KEY (`award_id`),
  KEY `is_ladder` (`is_ladder`),
  KEY `is_title` (`is_title`),
  KEY `name` (`name`),
  KEY `peerage` (`peerage`),
  KEY `title_class` (`title_class`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=203 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_awards`
--

DROP TABLE IF EXISTS `ork_awards`;
CREATE TABLE IF NOT EXISTS `ork_awards` (
  `awards_id` int(11) NOT NULL AUTO_INCREMENT,
  `kingdomaward_id` int(11) NOT NULL,
  `mundane_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `park_id` int(11) NOT NULL,
  `kingdom_id` int(11) NOT NULL,
  `team_id` int(11) NOT NULL,
  `rank` int(11) NOT NULL,
  `date` date NOT NULL,
  `given_by_id` int(11) NOT NULL,
  `note` varchar(400) NOT NULL,
  `at_park_id` int(11) NOT NULL,
  `at_kingdom_id` int(11) NOT NULL,
  `at_event_id` int(11) NOT NULL,
  `custom_name` varchar(64) NOT NULL,
  `award_id` int(11) NOT NULL,
  PRIMARY KEY (`awards_id`),
  KEY `at_event_id` (`at_event_id`),
  KEY `at_kingdom_id` (`at_kingdom_id`),
  KEY `at_park_id` (`at_park_id`),
  KEY `given_by_id` (`given_by_id`),
  KEY `kingdom_id` (`kingdom_id`),
  KEY `kingdomaward_id` (`kingdomaward_id`),
  KEY `mundane_id` (`mundane_id`),
  KEY `park_id` (`park_id`),
  KEY `team_id` (`team_id`),
  KEY `unit_id` (`unit_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=115100 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_bracket`
--

DROP TABLE IF EXISTS `ork_bracket`;
CREATE TABLE IF NOT EXISTS `ork_bracket` (
  `bracket_id` int(11) NOT NULL AUTO_INCREMENT,
  `tournament_id` int(11) NOT NULL,
  `style` enum('Single Sword','Florentine','Sword and Shield','Great Weapon','Missile','Other','Jugging','Battlegame','Quest') NOT NULL,
  `style_note` varchar(255) NOT NULL,
  `method` enum('single','double','swiss','round-robin','ironman','score') NOT NULL,
  `rings` int(11) NOT NULL,
  `participants` enum('individual','team') NOT NULL,
  `seeding` enum('manual','glicko2','random','glicko2-manual','random-manual') NOT NULL,
  PRIMARY KEY (`bracket_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_bracket_officiant`
--

DROP TABLE IF EXISTS `ork_bracket_officiant`;
CREATE TABLE IF NOT EXISTS `ork_bracket_officiant` (
  `bracket_officiant_id` int(11) NOT NULL AUTO_INCREMENT,
  `tournament_id` int(11) NOT NULL,
  `bracket_id` int(11) NOT NULL,
  `mundane_id` int(11) NOT NULL,
  `ring` varchar(50) NOT NULL,
  PRIMARY KEY (`bracket_officiant_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_class`
--

DROP TABLE IF EXISTS `ork_class`;
CREATE TABLE IF NOT EXISTS `ork_class` (
  `class_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`class_id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=18 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_class_reconciliation`
--

DROP TABLE IF EXISTS `ork_class_reconciliation`;
CREATE TABLE IF NOT EXISTS `ork_class_reconciliation` (
  `class_reconciliation_id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `mundane_id` int(11) NOT NULL,
  `reconciled` int(11) NOT NULL,
  PRIMARY KEY (`class_reconciliation_id`),
  UNIQUE KEY `class_id` (`class_id`,`mundane_id`),
  KEY `mundane_id` (`mundane_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=71189 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_configuration`
--

DROP TABLE IF EXISTS `ork_configuration`;
CREATE TABLE IF NOT EXISTS `ork_configuration` (
  `configuration_id` int(11) NOT NULL AUTO_INCREMENT,
  `type` enum('Service','Application','Kingdom','Park','Event','Tournament','Unit') NOT NULL DEFAULT 'Service',
  `id` int(11) NOT NULL,
  `key` varchar(50) NOT NULL,
  `value` mediumtext NOT NULL,
  `user_setting` tinyint(1) NOT NULL DEFAULT '1',
  `allowed_values` mediumtext NOT NULL,
  `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `var_type` enum('string','fixed','mixed','number','date','color') NOT NULL,
  PRIMARY KEY (`configuration_id`),
  UNIQUE KEY `type` (`type`,`id`,`key`),
  KEY `id` (`id`),
  KEY `type_2` (`type`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=923 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_credential`
--

DROP TABLE IF EXISTS `ork_credential`;
CREATE TABLE IF NOT EXISTS `ork_credential` (
  `key` varchar(150) NOT NULL,
  `expiration` datetime NOT NULL,
  `resetrequest` tinyint(1) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `expiration` (`expiration`),
  KEY `resetrequest` (`resetrequest`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table structure for table `ork_event`
--

DROP TABLE IF EXISTS `ork_event`;
CREATE TABLE IF NOT EXISTS `ork_event` (
  `event_id` int(11) NOT NULL AUTO_INCREMENT,
  `kingdom_id` int(11) NOT NULL,
  `park_id` int(11) NOT NULL,
  `mundane_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `has_heraldry` tinyint(1) NOT NULL DEFAULT '0',
  `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`event_id`),
  KEY `kingdom_id` (`kingdom_id`),
  KEY `mundane_id` (`mundane_id`),
  KEY `park_id` (`park_id`),
  KEY `unit_id` (`unit_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=8735 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_event_calendardetail`
--

DROP TABLE IF EXISTS `ork_event_calendardetail`;
CREATE TABLE IF NOT EXISTS `ork_event_calendardetail` (
  `event_calendardetail_id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) NOT NULL,
  `current` tinyint(1) NOT NULL DEFAULT '1',
  `price` float(6,2) NOT NULL,
  `event_start` datetime NOT NULL,
  `event_end` datetime NOT NULL,
  `description` mediumtext NOT NULL,
  `url` varchar(255) NOT NULL,
  `url_name` varchar(40) NOT NULL,
  `address` varchar(255) NOT NULL,
  `province` varchar(35) NOT NULL,
  `postal_code` varchar(10) NOT NULL,
  `city` varchar(50) NOT NULL,
  `country` varchar(50) NOT NULL,
  `map_url` mediumtext NOT NULL,
  `map_url_name` varchar(40) NOT NULL,
  `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `google_geocode` mediumtext NOT NULL,
  `location` mediumtext NOT NULL,
  PRIMARY KEY (`event_calendardetail_id`),
  KEY `event_id` (`event_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=257 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_game`
--

DROP TABLE IF EXISTS `ork_game`;
CREATE TABLE IF NOT EXISTS `ork_game` (
  `game_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `mundane_id` int(11) NOT NULL,
  `configuration` mediumtext NOT NULL,
  `created` datetime NOT NULL,
  `type` enum('flag-capture','custom') NOT NULL,
  `state` mediumtext NOT NULL,
  `code` varchar(6) NOT NULL,
  PRIMARY KEY (`game_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_game_objective`
--

DROP TABLE IF EXISTS `ork_game_objective`;
CREATE TABLE IF NOT EXISTS `ork_game_objective` (
  `game_objective_id` int(11) NOT NULL AUTO_INCREMENT,
  `game_id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `state` mediumtext NOT NULL,
  PRIMARY KEY (`game_objective_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_game_team`
--

DROP TABLE IF EXISTS `ork_game_team`;
CREATE TABLE IF NOT EXISTS `ork_game_team` (
  `game_team_id` int(11) NOT NULL AUTO_INCREMENT,
  `game_id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  PRIMARY KEY (`game_team_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_glicko2`
--

DROP TABLE IF EXISTS `ork_glicko2`;
CREATE TABLE IF NOT EXISTS `ork_glicko2` (
  `glicko2_id` int(11) NOT NULL AUTO_INCREMENT,
  `kingdom_id` int(11) NOT NULL,
  `park_id` int(11) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `team_id` int(11) NOT NULL,
  `mu` double(12,5) NOT NULL,
  `phi` double(12,5) NOT NULL,
  `sigma` double(12,5) NOT NULL,
  `modified` datetime NOT NULL,
  `style_specific` tinyint(1) NOT NULL DEFAULT '0',
  `style` enum('Single Sword','Florentine','Sword and Shield','Great Weapon','Missile','Other','Jugging','Battlegame','Quest') NOT NULL,
  PRIMARY KEY (`glicko2_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_kingdom`
--

DROP TABLE IF EXISTS `ork_kingdom`;
CREATE TABLE IF NOT EXISTS `ork_kingdom` (
  `kingdom_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `abbreviation` varchar(3) NOT NULL,
  `has_heraldry` tinyint(1) NOT NULL DEFAULT '0',
  `parent_kingdom_id` int(11) NOT NULL,
  `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `active` enum('Active','Retired') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`kingdom_id`),
  KEY `name` (`name`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=35 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_kingdomaward`
--

DROP TABLE IF EXISTS `ork_kingdomaward`;
CREATE TABLE IF NOT EXISTS `ork_kingdomaward` (
  `kingdomaward_id` int(11) NOT NULL AUTO_INCREMENT,
  `is_title` tinyint(1) NOT NULL DEFAULT '0',
  `title_class` int(11) NOT NULL DEFAULT '0',
  `kingdom_id` int(11) NOT NULL,
  `award_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `reign_limit` tinyint(1) NOT NULL DEFAULT '0',
  `month_limit` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`kingdomaward_id`),
  UNIQUE KEY `kingdom_id` (`kingdom_id`,`award_id`,`name`),
  KEY `award_id` (`award_id`),
  KEY `is_title` (`is_title`),
  KEY `kingdom_id_2` (`kingdom_id`),
  KEY `name` (`name`),
  KEY `title_class` (`title_class`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=6045 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_log`
--

DROP TABLE IF EXISTS `ork_log`;
CREATE TABLE IF NOT EXISTS `ork_log` (
  `log_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `mundane_id` int(11) NOT NULL,
  `action_type` enum('add','remove','edit','retire','restore','note') NOT NULL,
  `action_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `action` mediumtext NOT NULL,
  PRIMARY KEY (`log_id`),
  KEY `action_time` (`action_time`),
  KEY `name` (`name`),
  FULLTEXT KEY `action` (`action`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=3826629 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_match`
--

DROP TABLE IF EXISTS `ork_match`;
CREATE TABLE IF NOT EXISTS `ork_match` (
  `match_id` int(11) NOT NULL AUTO_INCREMENT,
  `participant_1_id` int(11) NOT NULL,
  `participant_2_id` int(11) NOT NULL,
  `result` enum('1-wins','2-wins','tie','1-forfeits','2-forfeits','1-is-disqualified','2-is-disqualified','1-is-bye','2-is-bye','score') NOT NULL,
  `tournament_id` int(11) NOT NULL,
  `bracket_id` int(11) NOT NULL,
  `round` varchar(20) NOT NULL,
  `match` varchar(20) NOT NULL,
  `order` int(11) NOT NULL,
  `resolution_order` int(11) NOT NULL,
  `score` double(12,4) NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`match_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_mundane`
--

DROP TABLE IF EXISTS `ork_mundane`;
CREATE TABLE IF NOT EXISTS `ork_mundane` (
  `mundane_id` int(11) NOT NULL AUTO_INCREMENT,
  `given_name` varchar(50) NOT NULL,
  `surname` varchar(50) NOT NULL,
  `other_name` varchar(50) NOT NULL,
  `username` varchar(200) NOT NULL,
  `persona` varchar(100) NOT NULL,
  `email` varchar(165) NOT NULL,
  `park_id` int(11) NOT NULL,
  `kingdom_id` int(11) NOT NULL,
  `token` varchar(35) NOT NULL,
  `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `restricted` tinyint(1) NOT NULL DEFAULT '0',
  `waivered` tinyint(1) NOT NULL DEFAULT '0',
  `waiver_ext` varchar(8) NOT NULL,
  `has_heraldry` tinyint(1) NOT NULL DEFAULT '0',
  `has_image` tinyint(1) NOT NULL DEFAULT '0',
  `company_id` int(11) NOT NULL DEFAULT '0',
  `token_expires` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `password_expires` datetime NOT NULL,
  `password_salt` varchar(35) NOT NULL,
  `xtoken` varchar(35) NOT NULL,
  `penalty_box` tinyint(4) NOT NULL DEFAULT '0',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`mundane_id`),
  UNIQUE KEY `username` (`username`),
  KEY `active` (`active`),
  KEY `email` (`email`),
  KEY `given_name` (`given_name`),
  KEY `has_heraldry` (`has_heraldry`),
  KEY `kingdom_id` (`kingdom_id`),
  KEY `park_id` (`park_id`),
  KEY `persona` (`persona`),
  KEY `surname` (`surname`),
  KEY `token` (`token`),
  KEY `waivered` (`waivered`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=71313 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_mundane_note`
--

DROP TABLE IF EXISTS `ork_mundane_note`;
CREATE TABLE IF NOT EXISTS `ork_mundane_note` (
  `mundane_note_id` int(11) NOT NULL AUTO_INCREMENT,
  `mundane_id` int(11) NOT NULL,
  `note` varchar(400) NOT NULL,
  `description` mediumtext NOT NULL,
  `given_by` varchar(200) NOT NULL,
  `date` date NOT NULL,
  `date_complete` date NOT NULL,
  PRIMARY KEY (`mundane_note_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=32701 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_officer`
--

DROP TABLE IF EXISTS `ork_officer`;
CREATE TABLE IF NOT EXISTS `ork_officer` (
  `officer_id` int(11) NOT NULL AUTO_INCREMENT,
  `kingdom_id` int(11) NOT NULL,
  `park_id` int(11) NOT NULL,
  `mundane_id` int(11) NOT NULL,
  `role` enum('Monarch','Regent','Prime Minister','Champion') NOT NULL,
  `system` tinyint(1) NOT NULL DEFAULT '0',
  `authorization_id` int(11) NOT NULL,
  `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`officer_id`),
  UNIQUE KEY `kingdom_id` (`kingdom_id`,`park_id`,`role`),
  KEY `kingdom_id_2` (`kingdom_id`),
  KEY `mundane_id` (`mundane_id`),
  KEY `park_id` (`park_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=2477 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_park`
--

DROP TABLE IF EXISTS `ork_park`;
CREATE TABLE IF NOT EXISTS `ork_park` (
  `park_id` int(11) NOT NULL AUTO_INCREMENT,
  `kingdom_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `abbreviation` varchar(3) NOT NULL,
  `has_heraldry` tinyint(1) NOT NULL DEFAULT '0',
  `url` varchar(255) NOT NULL,
  `parktitle_id` int(11) NOT NULL DEFAULT '1',
  `active` enum('Active','Retired') NOT NULL DEFAULT 'Active',
  `address` varchar(255) NOT NULL,
  `city` varchar(50) NOT NULL,
  `province` varchar(35) NOT NULL,
  `postal_code` varchar(10) NOT NULL,
  `google_geocode` mediumtext NOT NULL,
  `latitude` double NOT NULL,
  `longitude` double NOT NULL,
  `location` mediumtext NOT NULL,
  `map_url` mediumtext NOT NULL,
  `description` mediumtext NOT NULL,
  `directions` mediumtext NOT NULL,
  `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`park_id`),
  UNIQUE KEY `kingdom_id` (`kingdom_id`,`name`),
  KEY `kingdom_id_2` (`kingdom_id`),
  KEY `name` (`name`)
) ENGINE=MyISAM  DEFAULT CHARSET=latin1 AUTO_INCREMENT=586 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_parkday`
--

DROP TABLE IF EXISTS `ork_parkday`;
CREATE TABLE IF NOT EXISTS `ork_parkday` (
  `parkday_id` int(11) NOT NULL AUTO_INCREMENT,
  `park_id` int(11) NOT NULL,
  `recurrence` enum('weekly','monthly','week-of-month') NOT NULL DEFAULT 'weekly',
  `week_of_month` int(4) NOT NULL,
  `week_day` enum('None','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `month_day` int(4) NOT NULL,
  `time` time NOT NULL,
  `purpose` enum('park-day','fighter-practice','arts-day','other') NOT NULL,
  `description` varchar(50) NOT NULL,
  `alternate_location` tinyint(1) NOT NULL DEFAULT '0',
  `address` varchar(255) NOT NULL,
  `city` varchar(50) NOT NULL,
  `province` varchar(35) NOT NULL,
  `postal_code` varchar(10) NOT NULL,
  `map_url` mediumtext NOT NULL,
  `location_url` varchar(255) NOT NULL,
  PRIMARY KEY (`parkday_id`),
  UNIQUE KEY `park_id` (`park_id`,`week_day`,`month_day`,`purpose`,`description`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=409 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_parktitle`
--

DROP TABLE IF EXISTS `ork_parktitle`;
CREATE TABLE IF NOT EXISTS `ork_parktitle` (
  `parktitle_id` int(11) NOT NULL AUTO_INCREMENT,
  `kingdom_id` int(11) NOT NULL,
  `title` varchar(50) NOT NULL,
  `class` int(11) NOT NULL DEFAULT '0',
  `minimumattendance` int(11) NOT NULL DEFAULT '5',
  `minimumcutoff` int(11) NOT NULL DEFAULT '1',
  `period` enum('month','week') NOT NULL DEFAULT 'month',
  `period_length` int(11) NOT NULL DEFAULT '6',
  PRIMARY KEY (`parktitle_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=171 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_participant`
--

DROP TABLE IF EXISTS `ork_participant`;
CREATE TABLE IF NOT EXISTS `ork_participant` (
  `participant_id` int(11) NOT NULL AUTO_INCREMENT,
  `tournament_id` int(11) NOT NULL,
  `bracket_id` int(11) NOT NULL,
  `alias` varchar(100) NOT NULL,
  `unit_id` int(11) NOT NULL,
  `park_id` int(11) NOT NULL,
  `kingdom_id` int(11) NOT NULL,
  PRIMARY KEY (`participant_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_participant_mundane`
--

DROP TABLE IF EXISTS `ork_participant_mundane`;
CREATE TABLE IF NOT EXISTS `ork_participant_mundane` (
  `participant_mundane_id` int(11) NOT NULL AUTO_INCREMENT,
  `participant_id` int(11) NOT NULL,
  `mundane_id` int(11) NOT NULL,
  `tournament_id` int(11) NOT NULL,
  `bracket_id` int(11) NOT NULL,
  PRIMARY KEY (`participant_mundane_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_seed`
--

DROP TABLE IF EXISTS `ork_seed`;
CREATE TABLE IF NOT EXISTS `ork_seed` (
  `seed_id` int(11) NOT NULL AUTO_INCREMENT,
  `tournament_id` int(11) NOT NULL,
  `bracket_id` int(11) NOT NULL,
  `participant_id` int(11) NOT NULL,
  `order` int(11) NOT NULL,
  PRIMARY KEY (`seed_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_split`
--

DROP TABLE IF EXISTS `ork_split`;
CREATE TABLE IF NOT EXISTS `ork_split` (
  `split_id` int(11) NOT NULL AUTO_INCREMENT,
  `account_id` int(11) NOT NULL,
  `src_mundane_id` int(11) NOT NULL,
  `is_dues` tinyint(1) NOT NULL DEFAULT '0',
  `dues_through` date DEFAULT NULL,
  `amount` double(10,4) NOT NULL,
  `transaction_id` int(11) NOT NULL,
  PRIMARY KEY (`split_id`),
  KEY `account_id` (`account_id`),
  KEY `dues_through` (`dues_through`),
  KEY `is_dues` (`is_dues`),
  KEY `src_mundane_id` (`src_mundane_id`),
  KEY `transaction_id` (`transaction_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=6069 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_team`
--

DROP TABLE IF EXISTS `ork_team`;
CREATE TABLE IF NOT EXISTS `ork_team` (
  `team_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  PRIMARY KEY (`team_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8 AUTO_INCREMENT=1 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_tournament`
--

DROP TABLE IF EXISTS `ork_tournament`;
CREATE TABLE IF NOT EXISTS `ork_tournament` (
  `tournament_id` int(11) NOT NULL AUTO_INCREMENT,
  `kingdom_id` int(11) NOT NULL,
  `park_id` int(11) NOT NULL,
  `event_calendardetail_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `description` mediumtext NOT NULL,
  `url` varchar(255) NOT NULL,
  `date_time` datetime NOT NULL,
  PRIMARY KEY (`tournament_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=14 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_transaction`
--

DROP TABLE IF EXISTS `ork_transaction`;
CREATE TABLE IF NOT EXISTS `ork_transaction` (
  `transaction_id` int(11) NOT NULL AUTO_INCREMENT,
  `recorded_by` int(11) NOT NULL,
  `date_created` datetime NOT NULL,
  `description` varchar(255) NOT NULL,
  `memo` mediumtext NOT NULL,
  `transaction_date` date NOT NULL,
  PRIMARY KEY (`transaction_id`),
  KEY `date_created` (`date_created`),
  KEY `recorded_by` (`recorded_by`),
  KEY `transaction_date` (`transaction_date`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1518 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_unit`
--

DROP TABLE IF EXISTS `ork_unit`;
CREATE TABLE IF NOT EXISTS `ork_unit` (
  `unit_id` int(11) NOT NULL AUTO_INCREMENT,
  `type` enum('Company','Household','Event') NOT NULL DEFAULT 'Household',
  `name` varchar(100) NOT NULL,
  `has_heraldry` int(1) NOT NULL DEFAULT '0',
  `description` mediumtext NOT NULL,
  `history` mediumtext NOT NULL,
  `url` varchar(255) NOT NULL,
  `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`unit_id`),
  KEY `type` (`type`),
  KEY `name` (`name`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=2852 ;

-- --------------------------------------------------------

--
-- Table structure for table `ork_unit_mundane`
--

DROP TABLE IF EXISTS `ork_unit_mundane`;
CREATE TABLE IF NOT EXISTS `ork_unit_mundane` (
  `unit_mundane_id` int(11) NOT NULL AUTO_INCREMENT,
  `unit_id` int(11) NOT NULL,
  `mundane_id` int(11) NOT NULL,
  `role` enum('captain','lord','member') NOT NULL,
  `title` varchar(100) NOT NULL,
  `active` enum('Active','Retired') NOT NULL DEFAULT 'Active',
  PRIMARY KEY (`unit_mundane_id`),
  UNIQUE KEY `unit_id` (`unit_id`,`mundane_id`)
) ENGINE=MyISAM  DEFAULT CHARSET=utf8 AUTO_INCREMENT=10340 ;
