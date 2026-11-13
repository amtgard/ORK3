-- Snapshot of Order-of-the-Warrior level (0-12) at time of competition.
-- 0 = unranked .. 10 = OotW rank, 11 = Warlord, 12 = Knight of the Sword.
ALTER TABLE ork_participant
  ADD COLUMN IF NOT EXISTS warrior_level TINYINT NOT NULL DEFAULT 0;
