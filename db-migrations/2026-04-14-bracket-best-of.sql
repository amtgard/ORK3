-- Best-of-N match configuration per bracket.
-- 1 = single bout (Amtgard default), 3/5/7/9 supported.
ALTER TABLE ork_bracket
	ADD COLUMN IF NOT EXISTS best_of TINYINT NOT NULL DEFAULT 1
	AFTER duration_minutes;
