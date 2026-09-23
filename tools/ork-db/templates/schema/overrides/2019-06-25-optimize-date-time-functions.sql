-- Schema-only excerpt from 2019-06-25-optimize-date-time-functions (backfill excluded).

ALTER TABLE `ork_attendance` ADD `date_year` INT(11) NOT NULL AFTER `date`, ADD `date_month` INT(11) NOT NULL AFTER `date_year`, ADD `date_week3` INT(11) NOT NULL AFTER `date_month`, ADD `date_week6` INT(11) NOT NULL AFTER `date_week3`;
ALTER TABLE `ork_attendance` DROP INDEX `mundane_id`, ADD UNIQUE `unique_attendance` (`mundane_id`, `date`, `park_id`, `kingdom_id`, `event_id`, `event_calendardetail_id`, `persona`, `note`, `class_id`) USING BTREE;
