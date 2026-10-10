-- How many awards were on the court when its packet was last printed. Drift is
-- "the sheet has a different number of rows than the paper in your hand", which
-- marking rows must never trigger — court_award.modified is ON UPDATE
-- CURRENT_TIMESTAMP, so a timestamp comparison would fire on every mark.
ALTER TABLE ork_court
  ADD COLUMN IF NOT EXISTS last_printed_award_count INT NULL DEFAULT NULL AFTER last_printed_at;
