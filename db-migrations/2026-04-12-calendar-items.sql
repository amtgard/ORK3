-- Calendar Items: lightweight non-event entries for the calendar.
-- Owned by a kingdom or a park (mutually exclusive on semantic ownership;
-- park items always carry their parent kingdom_id so kingdom-scoped queries
-- can find them without a join, mirroring ork_event).

CREATE TABLE IF NOT EXISTS `ork_calendar_item` (
	`calendar_item_id` INT(11) NOT NULL AUTO_INCREMENT,
	`kingdom_id`       INT(11) NOT NULL DEFAULT 0,
	`park_id`          INT(11) NOT NULL DEFAULT 0,
	`name`             VARCHAR(120) NOT NULL,
	`description`      MEDIUMTEXT NULL,
	`all_day`          TINYINT(1) NOT NULL DEFAULT 0,
	`event_start`      DATETIME NOT NULL,
	`event_end`        DATETIME NOT NULL,
	`created_by`       INT(11) NOT NULL DEFAULT 0,
	`created`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
	`modified`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	PRIMARY KEY (`calendar_item_id`),
	KEY `idx_ci_kingdom_start` (`kingdom_id`, `event_start`),
	KEY `idx_ci_park_start`    (`park_id`,    `event_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
