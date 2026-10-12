-- Per-bracket display flag: how to present a bye-dominated first round.
-- 'byes'    = legacy layout (every X-vs-Bye match shown in round 1)
-- 'play-in' = round 1 shows only contested matches, labeled "Play-In"
-- Display-only: does not affect match generation or advancement.
ALTER TABLE ork_bracket
  ADD COLUMN IF NOT EXISTS first_round_mode ENUM('byes','play-in') NOT NULL DEFAULT 'byes' AFTER best_of;
