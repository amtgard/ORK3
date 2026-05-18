-- B13: ork_attendance_link revocation columns + composite indexes
--
-- Adds the audit fields needed for explicit revocation (separate from
-- expiry) and the composite indexes that the scope-bounded queries in
-- GetActiveEventsAtScope / DeleteEventDetail rely on for index-only access.
--
-- Columns:
--   revoked_at  DATETIME NULL — when an officer manually killed the link.
--   revoked_by  INT(11)  NULL — mundane_id of the officer who did so.
-- Both NULL by default so existing rows do not need backfilling.
--
-- Indexes:
--   idx_event_expires (event_id, expires_at) — supports "active links for
--       this event" lookups bounded by expiry.
--   idx_park_expires  (park_id,  expires_at) — supports the equivalent
--       park-scoped query (legacy non-event links).
--
-- The 2026-04-03-add-attendance-link.sql CREATE TABLE confirms both
-- event_id (added 2026-04-12) and park_id columns exist with INT(11).

ALTER TABLE ork_attendance_link
    ADD COLUMN revoked_at DATETIME NULL AFTER expires_at,
    ADD COLUMN revoked_by INT(11)  NULL AFTER revoked_at,
    ADD KEY idx_event_expires (event_id, expires_at),
    ADD KEY idx_park_expires  (park_id,  expires_at);
