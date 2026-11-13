-- Add 'tiebreaker-3rd' to bracket_side enum on ork_match
-- Without this, CreateTiebreakerMatch silently stores empty string (sql_mode is off)
-- DEFAULT stays '' (not 'winners'): insert_match() always sets bracket_side explicitly,
-- so a 'winners' default would silently mislabel any future non-elimination insert that
-- forgot to pass it. '' is the safe "unset" sentinel matching the original schema.
ALTER TABLE ork_match
  MODIFY COLUMN bracket_side ENUM('winners','losers','grand-final','tiebreaker-3rd','') NOT NULL DEFAULT '';

-- Fix any existing tiebreaker matches that were silently stored with empty bracket_side.
-- They are identifiable as matches whose round = max winners round, but whose participant pair
-- matches two semifinal losers rather than the final's participants.
-- However, the safest generic fix: if a bracket has status = 'complete' AND a match at
-- max_round with empty bracket_side that is NOT the final (not match 1 at max_round in single-elim),
-- it's likely a tiebreaker. But that heuristic is fragile.
-- Instead, just note: after running this migration, if tiebreaker matches already exist with
-- wrong bracket_side, they need manual correction or re-creation.
