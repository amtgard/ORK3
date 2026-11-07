-- Deploy order: run immediately before, or in the same step as, the code deploy
-- (migration first is the safer order).
-- Court Planner deeper tracking statuses: value 1 is now "Assigned" (an artisan
-- agreed to make it) and the new 0 is "Pending" (needed, nobody has it). Lines
-- that were 1 ("Custom Design" / "Needed") with no maker move to Pending; lines
-- with a maker stay Assigned. Legacy rows store "no maker" as either NULL or 0,
-- so both count. Run ONCE, before the new statuses are in use:
-- afterwards a line can legitimately be Assigned with no maker (picked in the
-- status picker), and a re-run would reset those to Pending.
UPDATE ork_court_award SET scroll_status = 0, row_version = row_version + 1 WHERE scroll_status = 1 AND (scroll_maker_id IS NULL OR scroll_maker_id = 0);
UPDATE ork_court_award SET regalia_status = 0, row_version = row_version + 1 WHERE regalia_status = 1 AND (regalia_maker_id IS NULL OR regalia_maker_id = 0);
