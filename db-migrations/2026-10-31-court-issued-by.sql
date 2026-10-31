-- Who a park-hosted court's awards are given from. The kingdom can visit a local
-- park event and hold court there: the court stays at the park and event (park_id,
-- event_calendardetail_id), but its default givers and printed scrolls are the
-- kingdom's. Meaningless on a kingdom's own court (park_id = 0), which is always
-- the kingdom's.
--
-- Idempotent.

ALTER TABLE `ork_court`
  ADD COLUMN IF NOT EXISTS `issued_by` enum('local','kingdom') NOT NULL DEFAULT 'local' AFTER `park_id`;
