-- Record WHICH throne a recommendation snooze was taken against.
-- Snooze stores the Monarch/Regent pair at snooze time and lifts when either
-- seat changes. Before this, both the write and the reads looked the seat up by
-- the RECIPIENT's park, so a kingdom-scope snooze snapshotted the recipient's
-- local park monarchy instead of the Crown, and a Coronation never lifted it.
-- snoozed_kingdom_id / snoozed_park_id pin the seat that was snapshotted
-- (park_id = 0 for a kingdom-level snooze). NULL means a pre-existing snooze
-- with no recorded scope; the reads fall back to the recipient's park for those.
ALTER TABLE ork_recommendations
  ADD COLUMN IF NOT EXISTS snoozed_kingdom_id INT NULL DEFAULT NULL AFTER snoozed_regent_id,
  ADD COLUMN IF NOT EXISTS snoozed_park_id    INT NULL DEFAULT NULL AFTER snoozed_kingdom_id;
