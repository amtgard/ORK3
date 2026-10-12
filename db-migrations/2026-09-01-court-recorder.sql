-- Names the officer responsible for recording this court's grants.
-- Defaults to the Prime Minister (Corpora record-keeping responsibility);
-- see docs/superpowers/specs/2026-09-01-court-recs-workflow-design.md 0.7.
ALTER TABLE ork_court
  ADD COLUMN IF NOT EXISTS recorder_mundane_id INT NULL DEFAULT NULL AFTER finalized_by;
