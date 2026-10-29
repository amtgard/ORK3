-- Griffon-level snapshot, mirroring the existing warrior_level column.
-- Captured at registration time (Order of the Griffin level 0-12) so the
-- tournament results export can show "Griffons on Date" alongside the live
-- "Griffons Today" count. Greenfield: existing rows default to 0 (no backfill).
ALTER TABLE ork_participant
  ADD COLUMN IF NOT EXISTS griffon_level TINYINT(4) NOT NULL DEFAULT 0 AFTER warrior_level;
