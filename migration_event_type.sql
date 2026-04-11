-- Add event_type column to event_calendardetail
-- Used for categorizing event occurrences (Coronation, Midreign, Warmaster, etc.)
ALTER TABLE ork_event_calendardetail
    ADD COLUMN IF NOT EXISTS event_type VARCHAR(50) NULL DEFAULT NULL;
