-- Tournament-level team registration: an ork_participant_teams row with
-- bracket_id IS NULL is a team registered to the tournament but not yet assigned
-- to any bracket. Mirrors the individual registration model (ork_participant).
ALTER TABLE ork_participant_teams MODIFY bracket_id INT(11) NULL DEFAULT NULL;
-- Tournament-stable team identity, shared across every bracket the team is in.
ALTER TABLE ork_participant_teams ADD COLUMN IF NOT EXISTS team_number INT(11) NOT NULL DEFAULT 0;
ALTER TABLE ork_participant_teams ADD INDEX IF NOT EXISTS idx_pteams_tourn_number (tournament_id, team_number);
