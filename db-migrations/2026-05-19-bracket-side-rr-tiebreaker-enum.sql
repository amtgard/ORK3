-- Add 'tiebreaker' to ork_match.bracket_side ENUM for round-robin first-place tiebreaker matches.
-- This is distinct from 'tiebreaker-3rd' (single-elim 3rd-place tiebreaker).
-- DEFAULT stays '' (not 'winners'): insert_match() always sets bracket_side explicitly.
ALTER TABLE ork_match
  MODIFY COLUMN bracket_side ENUM('winners','losers','grand-final','tiebreaker-3rd','tiebreaker','') NOT NULL DEFAULT '';
