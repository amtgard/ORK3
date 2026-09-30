-- When any sheet of the court packet was last printed. Backs the "printed <date>"
-- stamp on the paper and the "the plan changed since this was printed" warning on
-- the Record Court view. See docs/superpowers/specs/2026-09-01-court-recs-workflow-design.md §4/§5.
ALTER TABLE ork_court
  ADD COLUMN IF NOT EXISTS last_printed_at DATETIME NULL DEFAULT NULL AFTER recorder_mundane_id;
