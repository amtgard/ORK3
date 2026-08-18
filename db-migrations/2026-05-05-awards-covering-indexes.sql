-- Covering indexes for PlayerAwardRecommendations correlated subqueries — 2026-05-05
--
-- Problem: PlayerAwardRecommendations fires 6 correlated subqueries per row against
-- ork_awards (28k+ recommendations × 6 = 168k+ subquery executions). The table had
-- only single-column indexes on mundane_id and kingdomaward_id separately, and no
-- index on award_id at all. Unscoped query took ~4s locally; kingdom-scoped runs
-- were piling up concurrently in production causing worker saturation.
--
-- These two covering indexes let each subquery resolve entirely from the index:
--   mundane_id + kingdomaward_id/award_id narrows to the player's awards for that
--   award type, rank filters inline, and date is read from the index without
--   touching table rows.
--
-- Result: 4.0s → 0.66s locally on 28k unscoped rows (83% reduction).
-- Production impact will be greater since queries are always kingdom/park scoped.
--
-- Safe to run on live production: CREATE INDEX ... IF NOT EXISTS uses online DDL
-- in MariaDB 10.x+ and does not block reads or writes during build.

CREATE INDEX IF NOT EXISTS idx_awards_mundane_ka_rank_date
    ON ork_awards (mundane_id, kingdomaward_id, rank, date);

CREATE INDEX IF NOT EXISTS idx_awards_mundane_award_rank_date
    ON ork_awards (mundane_id, award_id, rank, date);
