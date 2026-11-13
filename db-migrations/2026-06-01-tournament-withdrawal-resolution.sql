-- Issue 3: mid-tournament withdrawal resolution for round-robin brackets.
-- withdraw_mode: how a withdrawn participant's matches were resolved.
ALTER TABLE ork_participant
  ADD COLUMN IF NOT EXISTS `withdraw_mode` enum('forfeit','annul') NULL DEFAULT NULL;

-- voided: match excluded from standings + completion (annul). auto_resolved:
-- result was written by withdrawal forfeit (not a human) so undo can revert it.
ALTER TABLE ork_match
  ADD COLUMN IF NOT EXISTS `voided` tinyint(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `auto_resolved` tinyint(1) NOT NULL DEFAULT 0;
