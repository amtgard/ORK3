-- Add Player Since override to ork_mundane — 2026-06-17
--
-- player_since_override is an admin-set authoritative "Player Since" date.
-- NULL means "use the computed value" (MIN(attendance.date) across all parks,
-- via class.Player::get_earliest_attendance_date). A date overrides it.
-- Mirrors the existing park_member_since override.
--
-- NOTE: this column MUST exist before UpdatePlayer writes it. Under PDO
-- ERRMODE_WARNING an unknown column silently rolls back the ENTIRE mundane
-- UPDATE (taking park_member_since, active, etc. with it). Run this first.

ALTER TABLE `ork_mundane`
    ADD COLUMN `player_since_override` DATE NULL DEFAULT NULL AFTER `park_member_since`;
