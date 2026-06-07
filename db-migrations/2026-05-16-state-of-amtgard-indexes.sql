-- =============================================================================
-- State of Amtgard report — composite indexes for hot query paths
-- Date: 2026-05-16
-- =============================================================================
--
-- Hot queries are in system/lib/ork3/class.StateOfAmtgard.php and aggregate
-- ork_attendance rows by (kingdom|park|mundane|class) within a date range.
--
-- AUDIT OF EXISTING INDEXES (skip-list):
--
--   ork_attendance:
--     idx_attendance_kingdom_date_mundane_park (kingdom_id, date, mundane_id, park_id)
--       → covers (kingdom_id, date) prefix. SKIP proposed idx_sor_kingdom_date.
--     idx_attendance_park_date_mundane (park_id, date, mundane_id)
--       → covers (park_id, date) prefix. SKIP proposed idx_sor_park_date.
--     idx_attendance_mundane_park_date (mundane_id, park_id, date)
--       → leading column is mundane_id, but date is positioned AFTER park_id,
--         so a (mundane_id, date) range scan can NOT use this index for the
--         date predicate (park_id sits between them). KEEP idx_sor_mundane_date.
--     class_id (class_id) — single-column only, no date.
--       → KEEP idx_sor_class_date for class-by-date aggregation.
--
--   ork_park:
--     idx_park_kingdom_active (kingdom_id, active) — already exists.
--       → SKIP proposed idx_sor_park_kingdom_active (exact duplicate).
--
-- NET RESULT: 2 of 5 proposed indexes are needed.
-- =============================================================================

-- Per-player aggregation (getPlayerCohorts / getPlayerStats sub-selects).
-- Pattern: SELECT mundane_id, ... FROM ork_attendance WHERE date BETWEEN ? AND ? GROUP BY mundane_id
ALTER TABLE ork_attendance
	ADD INDEX IF NOT EXISTS idx_sor_mundane_date (mundane_id, date);

-- Class-level aggregation (getClassSignIns).
-- Pattern: JOIN ork_class c ON c.class_id = a.class_id WHERE a.date BETWEEN ? AND ? GROUP BY class_id
ALTER TABLE ork_attendance
	ADD INDEX IF NOT EXISTS idx_sor_class_date (class_id, date);
