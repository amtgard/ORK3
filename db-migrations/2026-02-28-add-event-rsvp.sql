CREATE TABLE `ork_event_rsvp` (
    `rsvp_id` int(11) NOT NULL AUTO_INCREMENT,
    `event_calendardetail_id` int(11) NOT NULL,
    `mundane_id` int(11) NOT NULL,
    `status` ENUM('going', 'interested') NOT NULL DEFAULT 'going',
    `modified` timestamp NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`rsvp_id`),
    UNIQUE KEY `detail_mundane` (`event_calendardetail_id`, `mundane_id`),
    KEY `mundane_id` (`mundane_id`),
    CONSTRAINT `fk_rsvp_detail` FOREIGN KEY (`event_calendardetail_id`) REFERENCES `ork_event_calendardetail` (`event_calendardetail_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rsvp_mundane` FOREIGN KEY (`mundane_id`) REFERENCES `ork_mundane` (`mundane_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
