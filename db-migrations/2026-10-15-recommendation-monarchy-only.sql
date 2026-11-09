-- Monarchy Only recommendations: per-recommendation visibility restriction.
-- 0 = public, 1 = park-level (park + kingdom officers), 2 = kingdom-level
-- (kingdom officers only). A kingdom-level rec that is passed_to_local is read
-- as park-level by the app; nothing is rewritten on pass-down.
-- ork_recommendations is MyISAM: one ALTER, one table copy.
ALTER TABLE ork_recommendations
  ADD COLUMN IF NOT EXISTS monarchy_only TINYINT NOT NULL DEFAULT 0,
  ADD INDEX IF NOT EXISTS idx_recs_monarchy_only (monarchy_only);
