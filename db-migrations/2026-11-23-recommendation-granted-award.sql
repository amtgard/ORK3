-- Recommendation granted award: which ork_awards row a GRANTED recommendation was
-- granted as. Player::grant_recommendation writes it (the backing award it found)
-- on every grant / relabel, and RestoreAwardRecommendation clears it. Lets the
-- closed-recs views (Recs Manager, Kingdom/Park closed-recs panels) link a granted
-- row to the award itself instead of being a dead end.
--   0 = live, dismissed, or a granted close the backfill could not tie to an award
-- Historic granted rows are filled by
-- 2026-11-23-recommendation-granted-award-backfill.sql (run after this file).
--
-- DEPLOY ORDER: this ALTER MUST be applied BEFORE the code that ships with it.
-- class.Report.php selects recs.granted_awards_id; code deployed first throws
-- "Unknown column 'granted_awards_id'" on the closed-recs reads. After applying,
-- reload php-fpm (yapo caches column lists in APCu).
-- ork_recommendations is MyISAM: one ALTER, one table copy. Idempotent.
ALTER TABLE ork_recommendations
  ADD COLUMN IF NOT EXISTS granted_awards_id INT NOT NULL DEFAULT 0 AFTER resolution,
  ADD INDEX IF NOT EXISTS idx_recs_granted_awards_id (granted_awards_id);
