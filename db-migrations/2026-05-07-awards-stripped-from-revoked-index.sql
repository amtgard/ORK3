-- Index for player profile revoked awards/titles queries — 2026-05-07
--
-- Problem: controller.Player.php fires two queries per admin profile load
-- against ork_awards filtered by (stripped_from, revoked) and ordered by
-- (revoked_at DESC, date DESC). ork_awards had no index on stripped_from or
-- revoked, so each query did a full table scan (~385k rows) plus a filesort,
-- showing up in the processlist as "Creating sort index" at ~1s each.
--
-- This composite index covers the WHERE filter and the ORDER BY: stripped_from
-- + revoked narrow to a handful of rows; revoked_at + date sit in index order
-- so MariaDB scans the index backward to satisfy DESC, DESC without sorting.
--
-- Result: EXPLAIN went from type=ALL / 385,477 rows / Using filesort to
-- type=ref / 1 row / Using where (no filesort). Two seconds removed from
-- every admin profile load.
--
-- Safe to run on live production: CREATE INDEX ... IF NOT EXISTS uses online
-- DDL in MariaDB 10.x+ and does not block reads or writes during build.

CREATE INDEX IF NOT EXISTS idx_awards_stripped_revoked_dates
    ON ork_awards (stripped_from, revoked, revoked_at, date);
