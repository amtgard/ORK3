-- Event sign-in link feature: associate attendance links with events
-- Run: docker exec -i ork3-php8-db mariadb -u root -proot ork < migration_attendance_link_event.sql

ALTER TABLE `ork_attendance_link`
	ADD COLUMN `event_id` INT(11) NOT NULL DEFAULT 0 AFTER `kingdom_id`,
	ADD COLUMN `event_calendardetail_id` INT(11) NOT NULL DEFAULT 0 AFTER `event_id`,
	ADD KEY `idx_event` (`event_id`),
	ADD KEY `idx_eventdetail` (`event_calendardetail_id`);
