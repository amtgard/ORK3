-- Migration: Unify feast/meal into schedule (Option B)
-- Meals become ork_event_schedule rows with category='Feast and Food'.
-- ork_event_meal is dropped after its data is copied over.

-- -----------------------------------------------------------------------
-- 1. Add nullable meal columns to ork_event_schedule (after description)
-- -----------------------------------------------------------------------
ALTER TABLE ork_event_schedule
    ADD COLUMN menu      TEXT           NULL AFTER description,
    ADD COLUMN cost      DECIMAL(8,2)   NULL AFTER menu,
    ADD COLUMN dietary   VARCHAR(500)   NULL AFTER cost,
    ADD COLUMN allergens VARCHAR(500)   NULL AFTER dietary;

-- -----------------------------------------------------------------------
-- 2. Copy existing ork_event_meal rows into ork_event_schedule
--    start_time  = event's event_start (no time stored on meal row)
--    end_time    = event_start + 1 hour (reasonable feast default)
--    category    = 'Feast and Food'
--    location/secondary_category left as empty string (table default)
-- -----------------------------------------------------------------------
INSERT INTO ork_event_schedule
    (event_calendardetail_id, title, start_time, end_time, category, menu, cost, dietary, allergens)
SELECT
    m.event_calendardetail_id,
    m.title,
    cd.event_start,
    DATE_ADD(cd.event_start, INTERVAL 1 HOUR),
    'Feast and Food',
    m.menu,
    m.cost,
    m.dietary,
    m.allergens
FROM ork_event_meal m
JOIN ork_event_calendardetail cd ON cd.event_calendardetail_id = m.event_calendardetail_id;

-- -----------------------------------------------------------------------
-- 3. Drop the now-obsolete ork_event_meal table
-- -----------------------------------------------------------------------
DROP TABLE IF EXISTS ork_event_meal;
