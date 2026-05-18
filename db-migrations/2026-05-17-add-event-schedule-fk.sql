-- B2 + B4: ork_event_schedule FK + ENGINE/charset normalization
--
-- The original 2026-03-16-add-event-schedule.sql CREATE TABLE omitted both
-- ENGINE and CHARSET, so on a fresh install ork_event_schedule defaults to
-- the server's defaults (often MyISAM on legacy boxes — and MyISAM does not
-- support foreign keys). Force InnoDB + utf8mb4 before attempting the FK.
--
-- The FK ties schedule rows to ork_event_calendardetail so that deleting a
-- detail (occurrence) cascades and removes its schedule items, preventing
-- orphan schedule rows from accumulating.

-- 1. Ensure storage engine + charset support FKs and unicode payloads.
ALTER TABLE ork_event_schedule ENGINE=InnoDB;
ALTER TABLE ork_event_schedule CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 2. Scrub orphans (schedule rows whose parent detail no longer exists).
--    Must run before the FK is added or the constraint will fail to validate.
DELETE FROM ork_event_schedule
 WHERE event_calendardetail_id NOT IN (
     SELECT event_calendardetail_id FROM ork_event_calendardetail
 );

-- 3. Add the cascading FK.
ALTER TABLE ork_event_schedule
    ADD CONSTRAINT fk_sched_detail
    FOREIGN KEY (event_calendardetail_id)
    REFERENCES ork_event_calendardetail (event_calendardetail_id)
    ON DELETE CASCADE;
