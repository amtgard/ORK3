-- B3: ork_attendance_link event-column FKs
--
-- The 2026-04-12-add-attendance-link-event.sql migration added event_id and
-- event_calendardetail_id columns (both INT(11) NOT NULL DEFAULT 0) plus
-- indexes — but no foreign keys. When an event or one of its calendar details
-- is deleted, stale attendance_link rows linger and can be used to credit
-- attendance against a non-existent event.
--
-- We use ON DELETE CASCADE here (matches B5's intent: deleting an event
-- detail revokes the link). Note both columns default to 0, so we only
-- enforce FKs against non-zero values — but MySQL/MariaDB FKs check on
-- the literal value, so rows with the 0 sentinel would fail FK validation
-- against a missing row 0. We therefore scrub orphans (non-zero, missing
-- parent) but leave the 0-default rows in place. The FK is conditional in
-- practice because MySQL allows 0 only if a matching parent row exists.
--
-- To accommodate the 0 sentinel, we change the column default to NULL and
-- backfill existing 0 values to NULL prior to adding the FK.

-- 1. Allow NULL on the FK columns and migrate the 0 sentinel to NULL.
ALTER TABLE ork_attendance_link
    MODIFY COLUMN event_id INT(11) NULL DEFAULT NULL,
    MODIFY COLUMN event_calendardetail_id INT(11) NULL DEFAULT NULL;

UPDATE ork_attendance_link SET event_id = NULL WHERE event_id = 0;
UPDATE ork_attendance_link SET event_calendardetail_id = NULL WHERE event_calendardetail_id = 0;

-- 2. Scrub orphans (non-NULL values pointing at missing parents).
DELETE FROM ork_attendance_link
 WHERE event_id IS NOT NULL
   AND event_id NOT IN (SELECT event_id FROM ork_event);

DELETE FROM ork_attendance_link
 WHERE event_calendardetail_id IS NOT NULL
   AND event_calendardetail_id NOT IN (
       SELECT event_calendardetail_id FROM ork_event_calendardetail
   );

-- 3. Add cascading FKs.
ALTER TABLE ork_attendance_link
    ADD CONSTRAINT fk_alink_event
    FOREIGN KEY (event_id)
    REFERENCES ork_event (event_id)
    ON DELETE CASCADE;

ALTER TABLE ork_attendance_link
    ADD CONSTRAINT fk_alink_detail
    FOREIGN KEY (event_calendardetail_id)
    REFERENCES ork_event_calendardetail (event_calendardetail_id)
    ON DELETE CASCADE;
