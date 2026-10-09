-- Tournament module Phase 3 additions
-- Adds seed/eliminated/bracket_side to participants, bracket_side to matches,
-- and status to brackets and tournaments.

ALTER TABLE ork_participant ADD COLUMN IF NOT EXISTS seed        INT          NOT NULL DEFAULT 0   AFTER kingdom_id;
ALTER TABLE ork_participant ADD COLUMN IF NOT EXISTS eliminated  TINYINT      NOT NULL DEFAULT 0   AFTER seed;
ALTER TABLE ork_participant ADD COLUMN IF NOT EXISTS bracket_side ENUM('winners','losers','') NOT NULL DEFAULT '' AFTER eliminated;

ALTER TABLE ork_match       ADD COLUMN IF NOT EXISTS bracket_side ENUM('winners','losers','grand-final','') NOT NULL DEFAULT '' AFTER score;

ALTER TABLE ork_bracket     ADD COLUMN IF NOT EXISTS status ENUM('setup','active','complete') NOT NULL DEFAULT 'setup' AFTER seeding;

ALTER TABLE ork_tournament  ADD COLUMN IF NOT EXISTS status ENUM('setup','active','complete') NOT NULL DEFAULT 'setup' AFTER url;

-- Fix: result column must allow NULL so unplayed matches don't default to '1-wins'
ALTER TABLE ork_match MODIFY result ENUM('1-wins','2-wins','tie','1-forfeits','2-forfeits','1-is-disqualified','2-is-disqualified','1-is-bye','2-is-bye','score') NULL DEFAULT NULL;
