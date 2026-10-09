-- 2026-10-02 Tournament Quick Bracket: remember the starting draw size a quick bracket was
-- created with. NULL for every bracket created via Add Bracket. A bracket with draw_size set,
-- status 'setup', and no matches renders as the Quick Bracket draft draw.
ALTER TABLE ork_bracket ADD COLUMN IF NOT EXISTS draw_size SMALLINT UNSIGNED NULL DEFAULT NULL AFTER first_round_mode;
