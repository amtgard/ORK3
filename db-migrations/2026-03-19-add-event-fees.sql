-- Add ork_event_fees table for per-occurrence admission and fee tracking
CREATE TABLE IF NOT EXISTS `ork_event_fees` (
  `event_fees_id` int NOT NULL AUTO_INCREMENT,
  `event_calendardetail_id` int NOT NULL,
  `admission_type` varchar(100) NOT NULL DEFAULT '',
  `cost` decimal(8,2) NOT NULL DEFAULT '0.00',
  `sort_order` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`event_fees_id`),
  KEY `idx_ef_detail` (`event_calendardetail_id`),
  CONSTRAINT `fk_ef_detail` FOREIGN KEY (`event_calendardetail_id`)
    REFERENCES `ork_event_calendardetail` (`event_calendardetail_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
