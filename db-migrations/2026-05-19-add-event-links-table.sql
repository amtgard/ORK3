-- ork_event_links — external link rows displayed on the event detail page
-- (Facebook, Discord, ticket vendor, etc.). Referenced by controller.Event.php
-- since the event-planning expansion landed (commit 2fd50c72). The schema was
-- never captured in a migration file — this catches dev environments up.
-- Safe to run against production: CREATE TABLE IF NOT EXISTS no-ops if the
-- table is already present.

CREATE TABLE IF NOT EXISTS `ork_event_links` (
  `event_links_id`           int(11)        NOT NULL AUTO_INCREMENT,
  `event_calendardetail_id`  int(11)        NOT NULL,
  `title`                    varchar(100)   NOT NULL DEFAULT '',
  `url`                      varchar(500)   NOT NULL DEFAULT '',
  `icon`                     varchar(50)    NOT NULL DEFAULT 'fas fa-link',
  `sort_order`               int(11)        NOT NULL DEFAULT 0,
  PRIMARY KEY (`event_links_id`),
  KEY `ix_detail` (`event_calendardetail_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
