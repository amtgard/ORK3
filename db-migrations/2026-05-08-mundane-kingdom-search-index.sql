-- Index for kingdom-scoped player search — 2026-05-08
--
-- Problem: KingdomAjax/playersearch scopes to a single kingdom then runs
-- OR LIKE '%term%' across four columns (persona, given_name, surname,
-- username). With only single-column indexes on kingdom_id and persona,
-- the planner chose the persona index and scanned it globally, applying
-- the kingdom_id / active / suspended filters as a post-scan WHERE.
-- That reads ~494 rows in persona order for a typical query but still
-- evaluates all four LIKE conditions per row, and still has to visit
-- rows outside the target kingdom. Result: ~190ms per keystroke.
--
-- This composite index (kingdom_id, active, suspended, persona) lets
-- the planner enter directly via the target kingdom's 3,575 active rows
-- (type=ref), evaluate LIKE only on those, and deliver results in persona
-- order without a filesort. LIMIT 15 then short-circuits early.
--
-- Measured on dev (KingdomId=16): 190ms -> 2-5ms for both match and
-- no-match cases.
--
-- Safe to run on live production: ADD INDEX IF NOT EXISTS uses online DDL
-- in MariaDB 10.x+ and does not block reads or writes during build.

ALTER TABLE ork_mundane
    ADD INDEX IF NOT EXISTS idx_mundane_kid_active_susp_persona
        (kingdom_id, active, suspended, persona);
