-- Add is_historical column to ork_awards
-- Backfill existing records that meet the all-zeros condition (no given_by, park, kingdom, or event)
ALTER TABLE ork_awards
    ADD COLUMN is_historical TINYINT(1) NOT NULL DEFAULT 0 AFTER at_event_id;

UPDATE ork_awards
SET is_historical = 1
WHERE given_by_id = 0
  AND at_park_id = 0
  AND at_kingdom_id = 0
  AND at_event_id = 0;
