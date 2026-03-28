-- Add snooze columns to ork_recommendations
-- Snooze hides a recommendation until the monarch or regent changes.
-- snoozed_by_id:      mundane_id of who snoozed it (for audit)
-- snoozed_monarch_id: monarch mundane_id at snooze time (0 = no monarch)
-- snoozed_regent_id:  regent mundane_id at snooze time (0 = no regent)
-- The rec stays snoozed while BOTH stored values match current officers.

ALTER TABLE ork_recommendations
  ADD COLUMN snoozed_by_id      INT NULL DEFAULT NULL,
  ADD COLUMN snoozed_monarch_id INT NULL DEFAULT NULL,
  ADD COLUMN snoozed_regent_id  INT NULL DEFAULT NULL;
