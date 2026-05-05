-- Covering index for cross-park monthly attendance aggregations — 2026-05-05
--
-- Problem: Two queries were doing full table scans of ork_attendance (3.27M rows,
-- ~3s each) because no index covered all the columns they needed after the date
-- range filter:
--
--   1. park_averages_json (kingdom profile page AJAX):
--        SELECT ... WHERE date > ? AND mundane_id > 0
--        GROUP BY date_year, date_month, park_id
--
--   2. GetActiveKingdomsSummary avg_monthly_att subquery (home page):
--        SELECT ... FROM ork_attendance JOIN ork_park ...
--        WHERE date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND mundane_id > 0
--        GROUP BY date_year, date_month, p2.kingdom_id
--
-- With this index the optimizer can satisfy both queries entirely from the index
-- (covering index / index-only scan on ork_attendance), avoiding full row fetches
-- and the resulting filesort.
--
-- The existing idx_attendance_park_date_mundane (park_id, date, mundane_id) starts
-- with park_id and cannot efficiently range-scan on date across all parks. This new
-- index leads with date so the range filter is applied first, then all remaining
-- columns for GROUP BY and COUNT DISTINCT are available in the index.
--
-- Safe to run on live production: CREATE INDEX ... IF NOT EXISTS uses an online DDL
-- in MariaDB 10.x+ and does not block reads or writes during build.

CREATE INDEX IF NOT EXISTS idx_att_date_park_ym_mundane
    ON ork_attendance (date, park_id, date_year, date_month, mundane_id);
