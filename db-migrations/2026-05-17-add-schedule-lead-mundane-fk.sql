-- B12: ork_event_schedule_lead missing FK on mundane_id
--
-- The 2026-03-19-add-event-schedule-leads.sql migration added a cascading FK
-- on event_schedule_id but never added one on mundane_id. If a mundane is
-- merged or hard-deleted, orphan lead rows persist and can show up as
-- "unknown leader" entries on the schedule UI.

-- 1. Scrub orphans before adding the FK.
DELETE FROM ork_event_schedule_lead
 WHERE mundane_id NOT IN (SELECT mundane_id FROM ork_mundane);

-- 2. Add cascading FK.
ALTER TABLE ork_event_schedule_lead
    ADD CONSTRAINT fk_sched_lead_mundane
    FOREIGN KEY (mundane_id)
    REFERENCES ork_mundane (mundane_id)
    ON DELETE CASCADE;
