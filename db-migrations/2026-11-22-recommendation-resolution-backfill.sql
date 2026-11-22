-- Deploy order: after 2026-11-22-recommendation-resolution.sql. Safe before or
-- after the code deploy, and safe to re-run: it only touches closed rows whose
-- resolution is still NULL, so it never overrides what the app recorded.
--
-- Classifies every historic close (deleted_by > 0, resolution NULL):
--   granted   - a Court Planner line was drawn from it (ork_court_award links the
--               rec), OR the recipient holds a matching award: same player, same
--               kingdomaward, rank at or above the recommended rank (a rank-0 rec
--               matches any rank), dated on or after the recommendation.
--   dismissed - everything else.
-- Rows with deleted_by = 0 / NULL are live (see Player::LiveRecommendationClause)
-- and are left alone.

UPDATE ork_recommendations r
   SET r.resolution = 'granted'
 WHERE r.deleted_by > 0
   AND r.resolution IS NULL
   AND (
        EXISTS (SELECT 1 FROM ork_court_award ca
                 WHERE ca.recommendations_id = r.recommendations_id)
     OR EXISTS (SELECT 1 FROM ork_awards a
                 WHERE a.mundane_id = r.mundane_id
                   AND a.kingdomaward_id = r.kingdomaward_id
                   AND (r.rank = 0 OR a.rank >= r.rank)
                   AND a.date >= r.date_recommended)
   );

UPDATE ork_recommendations
   SET resolution = 'dismissed'
 WHERE deleted_by > 0
   AND resolution IS NULL;
