-- Deploy order: after 2026-11-23-recommendation-granted-award.sql and after
-- 2026-11-22-recommendation-resolution-backfill.sql (it reads resolution). Safe
-- before or after the code deploy, and safe to re-run: it only fills granted rows
-- whose granted_awards_id is still 0, so it never overrides what the app recorded.
--
-- Ties each granted recommendation to the award that backs it, with the same rule
-- the app uses (Player::backing_award_id) and the resolution backfill applied:
--   1. the award a Court Planner line drawn from the rec committed, when it is still
--      live on the recipient's record;
--   2. else the newest live award on the recipient's record that matches the rec:
--        * same kingdomaward, OR the same canonical award (r.award_id > 0 AND
--          a.award_id = r.award_id), except the generic catch-alls 94 Custom Award /
--          246 Custom Title (AwardProvenance::GENERIC_AWARD_IDS);
--        * ladder: award rank at or above the recommended rank (rank 0 matches any);
--        * given or entered on/after the recommendation.
-- A granted row with no such award stays 0 (rendered as a plain "Granted" label).

UPDATE ork_recommendations r
   SET r.granted_awards_id = COALESCE(
        (SELECT ca.award_id FROM ork_court_award ca
           JOIN ork_awards ca_a ON ca_a.awards_id = ca.award_id
          WHERE ca.recommendations_id = r.recommendations_id
            AND ca.award_id > 0
            AND ca_a.mundane_id = r.mundane_id
            AND COALESCE(ca_a.revoked, 0) = 0
          ORDER BY ca.award_id DESC LIMIT 1),
        (SELECT a.awards_id FROM ork_awards a
          WHERE a.mundane_id = r.mundane_id
            AND COALESCE(a.revoked, 0) = 0
            AND (a.kingdomaward_id = r.kingdomaward_id
                 OR (r.award_id > 0 AND r.award_id NOT IN (94, 246)
                     AND a.award_id = r.award_id))
            AND (COALESCE(r.rank, 0) = 0 OR a.rank >= COALESCE(r.rank, 0))
            AND GREATEST(a.date,
                         IF(a.entered_at > '1000-01-01', DATE(a.entered_at), a.date))
                >= r.date_recommended
          ORDER BY a.awards_id DESC LIMIT 1),
        0)
 WHERE r.resolution = 'granted'
   AND r.deleted_by > 0
   AND r.granted_awards_id = 0;
