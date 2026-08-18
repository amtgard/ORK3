-- Index for player-centric unit lookups — 2026-05-08
--
-- Problem: ork_unit_mundane only has (unit_id, mundane_id) as its unique key,
-- which can answer "is this mundane in this unit?" but cannot drive the
-- reverse "what units is this mundane in?". Player profile loads call
-- Report::UnitSummary($MundaneId) which then full-scans ork_unit (3534 rows)
-- to find the player's companies, with four correlated subqueries running
-- per surviving group.
--
-- This index lets the planner enter via mundane_id, look up the player's
-- ~10–30 unit rows directly, and PRIMARY-key the corresponding ork_unit
-- rows. Combined with rewriting the LEFT JOIN to an INNER JOIN with the
-- predicate inline, EXPLAIN goes from type=ALL / 3534 rows on ork_unit to
-- type=ref / ~17 rows on ork_unit_mundane.
--
-- Measured on dev: cold-cache profile load 78ms → 26ms (3×) for the worst
-- player in the DB. 0-company players unchanged (~5ms either way).
--
-- 26k rows; build is effectively instant. Safe to run on live production.

CREATE INDEX IF NOT EXISTS ix_um_mundane
    ON ork_unit_mundane (mundane_id, active);
