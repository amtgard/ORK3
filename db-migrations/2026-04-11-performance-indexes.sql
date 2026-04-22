-- Performance indexes migration — 2026-04-11
-- Run against MariaDB 10.x. Each statement uses IF NOT EXISTS for idempotency.
-- Pre-migration audit confirmed the following already exist and are NOT re-added:
--   ork_attendance:  idx_park_date (park_id, date)
--   ork_officer:     unique key (kingdom_id, park_id, role) — (kingdom_id, park_id) is a prefix, no separate index needed

-- -------------------------------------------------------------------------
-- ork_attendance  (highest-priority table)
-- -------------------------------------------------------------------------

-- Covering index for park-level attendance aggregations
CREATE INDEX IF NOT EXISTS idx_attendance_park_date_mundane
    ON ork_attendance (park_id, date, mundane_id);

-- NOTE: idx_attendance_kingdom_date_mundane (kingdom_id, date, mundane_id) was previously
-- included but is a strict prefix of idx_attendance_kingdom_date_mundane_park (4-col); the
-- optimizer always prefers the superset index. Drop it if already applied, and do not recreate.
DROP INDEX IF EXISTS idx_attendance_kingdom_date_mundane ON ork_attendance;

-- NOTE: idx_park_date (park_id, date) is a pre-existing index that is now a strict prefix of
-- idx_attendance_park_date_mundane (park_id, date, mundane_id) added above. Drop the redundant one.
DROP INDEX IF EXISTS idx_park_date ON ork_attendance;

-- Covering index for park_averages_json queries (kingdom + date + mundane + park)
CREATE INDEX IF NOT EXISTS idx_attendance_kingdom_date_mundane_park
    ON ork_attendance (kingdom_id, date, mundane_id, park_id);

-- GROUP BY on computed week columns
CREATE INDEX IF NOT EXISTS idx_attendance_year_week_mundane
    ON ork_attendance (date_year, date_week3, mundane_id);

-- GROUP BY on computed month columns
CREATE INDEX IF NOT EXISTS idx_attendance_year_month_mundane
    ON ork_attendance (date_year, date_month, mundane_id);

-- Per-player park attendance lookups
CREATE INDEX IF NOT EXISTS idx_attendance_mundane_park_date
    ON ork_attendance (mundane_id, park_id, date);

-- -------------------------------------------------------------------------
-- ork_park
-- -------------------------------------------------------------------------

-- Park filtering in parkday joins (kingdom + active status)
CREATE INDEX IF NOT EXISTS idx_park_kingdom_active
    ON ork_park (kingdom_id, active);

-- -------------------------------------------------------------------------
-- ork_mundane
-- -------------------------------------------------------------------------

-- Park member filtering in roster queries
CREATE INDEX IF NOT EXISTS idx_mundane_park_suspended_active
    ON ork_mundane (park_id, suspended, active);

-- -------------------------------------------------------------------------
-- ork_event_calendardetail
-- -------------------------------------------------------------------------

-- Event calendar date range filtering
CREATE INDEX IF NOT EXISTS idx_eventcd_event_start
    ON ork_event_calendardetail (event_id, event_start);

-- -------------------------------------------------------------------------
-- ork_event_rsvp
-- -------------------------------------------------------------------------

-- RSVP count queries by detail and status
CREATE INDEX IF NOT EXISTS idx_rsvp_detail_status
    ON ork_event_rsvp (event_calendardetail_id, status);
