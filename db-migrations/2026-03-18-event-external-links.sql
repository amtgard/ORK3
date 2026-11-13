-- Migration: External Links for Event Occurrences
-- Replaces the single Url/UrlName website field with a flexible multi-link table.

CREATE TABLE IF NOT EXISTS `ork_event_links` (
  `event_link_id` int(11) NOT NULL AUTO_INCREMENT,
  `event_calendardetail_id` int(11) NOT NULL,
  `title` varchar(100) NOT NULL DEFAULT '',
  `url` varchar(500) NOT NULL DEFAULT '',
  `icon` varchar(50) NOT NULL DEFAULT 'fas fa-link',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`event_link_id`),
  KEY `event_calendardetail_id` (`event_calendardetail_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
