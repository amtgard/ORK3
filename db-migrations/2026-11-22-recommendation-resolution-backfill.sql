-- Deploy order: after 2026-11-22-recommendation-resolution.sql. Safe before or
-- after the code deploy, and safe to re-run: it only touches closed rows whose
-- resolution is still NULL, so it never overrides what the app recorded.
--
-- Classifies every historic close (deleted_by > 0, resolution NULL) with the same
-- "an award backs this recommendation" rule the app uses (Player::backing_award_id):
--   granted   - the recipient holds a live (not revoked) award that matches it:
--                 * same kingdomaward, OR the same canonical award
--                   (r.award_id > 0 AND a.award_id = r.award_id), except the
--                   generic catch-alls 94 Custom Award / 246 Custom Title, whose
--                   one id spans unrelated honours (AwardProvenance::GENERIC_AWARD_IDS);
--                 * ladder: award rank at or above the recommended rank
--                   (a rank-0 / NULL-rank rec matches any rank);
--                 * given or entered on/after the recommendation:
--                   GREATEST(a.date, DATE(a.entered_at)) >= r.date_recommended
--                   (a zero entered_at falls back to a.date).
--               A Court Planner line drawn from the rec counts only when the award
--               it committed is still live on the recipient's record.
--   dismissed - everything else.
-- Rows with deleted_by = 0 / NULL are live (see Player::LiveRecommendationClause)
-- and are left alone.

UPDATE ork_recommendations r
   SET r.resolution = 'granted'
 WHERE r.deleted_by > 0
   AND r.resolution IS NULL
   AND (
        EXISTS (SELECT 1 FROM ork_court_award ca
                  JOIN ork_awards ca_a ON ca_a.awards_id = ca.award_id
                 WHERE ca.recommendations_id = r.recommendations_id
                   AND ca_a.mundane_id = r.mundane_id
                   AND COALESCE(ca_a.revoked, 0) = 0)
     OR EXISTS (SELECT 1 FROM ork_awards a
                 WHERE a.mundane_id = r.mundane_id
                   AND COALESCE(a.revoked, 0) = 0
                   AND (a.kingdomaward_id = r.kingdomaward_id
                        OR (r.award_id > 0 AND r.award_id NOT IN (94, 246)
                            AND a.award_id = r.award_id))
                   AND (COALESCE(r.rank, 0) = 0 OR a.rank >= COALESCE(r.rank, 0))
                   AND GREATEST(a.date,
                                IF(a.entered_at > '1000-01-01', DATE(a.entered_at), a.date))
                       >= r.date_recommended)
   );

UPDATE ork_recommendations
   SET resolution = 'dismissed'
 WHERE deleted_by > 0
   AND resolution IS NULL;
