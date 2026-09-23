-- Sign-in Link feature: temporary shareable attendance links scoped to
-- park, kingdom, event, or event-detail.
-- Consolidated migration (Rose-cycle, fresh-deploy edition):
--   * original CREATE
--   * + event_id / event_calendardetail_id columns (NULL-able; the 0
--     sentinel used in the per-step migration is collapsed away)
--   * + FKs to ork_event and ork_event_calendardetail ON DELETE CASCADE
--   * + revocation audit columns (revoked_at, revoked_by)
--   * + composite (event,expires) / (park,expires) indexes used by
--     GetActiveEventsAtScope and DeleteEventDetail
--
-- Run: docker exec -i ork3-php8-db mariadb -u root -proot ork < 2026-04-03-add-attendance-link.sql

-- Ensure FK parent (ork_event) is InnoDB; legacy installs ship it as
-- MyISAM, which rejects FK declarations (errno 150). No-op if already InnoDB.
ALTER TABLE ork_event ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `ork_attendance_link` (
  `link_id`                 int(11)       NOT NULL AUTO_INCREMENT,
  `token`                   varchar(64)   NOT NULL,
  `park_id`                 int(11)       NOT NULL DEFAULT 0,
  `kingdom_id`              int(11)       NOT NULL DEFAULT 0,
  `event_id`                int(11)       NULL DEFAULT NULL,
  `event_calendardetail_id` int(11)       NULL DEFAULT NULL,
  `by_whom_id`              int(11)       NOT NULL,
  `credits`                 double(4,2)   NOT NULL DEFAULT 1.00,
  `expires_at`              datetime      NOT NULL,
  `revoked_at`              datetime      NULL,
  `revoked_by`              int(11)       NULL,
  `created_at`              datetime      NOT NULL,
  PRIMARY KEY (`link_id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_expires`        (`expires_at`),
  KEY `idx_event`          (`event_id`),
  KEY `idx_eventdetail`    (`event_calendardetail_id`),
  KEY `idx_event_expires`  (`event_id`, `expires_at`),
  KEY `idx_park_expires`   (`park_id`,  `expires_at`),
  CONSTRAINT `fk_alink_event` FOREIGN KEY (`event_id`)
    REFERENCES `ork_event` (`event_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_alink_detail` FOREIGN KEY (`event_calendardetail_id`)
    REFERENCES `ork_event_calendardetail` (`event_calendardetail_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
