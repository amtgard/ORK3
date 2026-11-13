-- Store per-bout results alongside the match result
ALTER TABLE ork_match ADD COLUMN IF NOT EXISTS bouts TEXT NOT NULL DEFAULT '' AFTER score;
