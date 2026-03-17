CREATE TABLE `ork_event_staff` (
    `event_staff_id` int(11) NOT NULL AUTO_INCREMENT,
    `event_calendardetail_id` int(11) NOT NULL,
    `mundane_id` int(11) NOT NULL,
    `role_name` varchar(100) NOT NULL DEFAULT '',
    `can_manage` tinyint(1) NOT NULL DEFAULT 0,
    `can_attendance` tinyint(1) NOT NULL DEFAULT 0,
    `modified` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`event_staff_id`),
    KEY `event_calendardetail_id` (`event_calendardetail_id`),
    KEY `mundane_id` (`mundane_id`),
    CONSTRAINT `fk_staff_detail` FOREIGN KEY (`event_calendardetail_id`) REFERENCES `ork_event_calendardetail` (`event_calendardetail_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_staff_mundane` FOREIGN KEY (`mundane_id`) REFERENCES `ork_mundane` (`mundane_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
