-- Registration/assignment support: speed up registrant lookups by stable number,
-- and roster/assignment scans.

-- Stable-identity lookups (ensureRegistrant, GetRegistrants assignment join):
ALTER TABLE ork_participant
  ADD INDEX IF NOT EXISTS idx_participant_tourn_number (tournament_id, participant_number);

-- Roster scan (bracket_id IS NULL) and per-bracket entrant scan:
ALTER TABLE ork_participant
  ADD INDEX IF NOT EXISTS idx_participant_tourn_bracket (tournament_id, bracket_id);

-- Registration rows live as ork_participant with bracket_id IS NULL
-- (registered to the tournament, not yet assigned to any bracket).
ALTER TABLE ork_participant         MODIFY bracket_id INT(11) NULL DEFAULT NULL;
ALTER TABLE ork_participant_mundane MODIFY bracket_id INT(11) NULL DEFAULT NULL;
