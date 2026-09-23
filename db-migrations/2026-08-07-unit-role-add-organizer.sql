-- ============================================================================
-- F013 — "Organizer" is a role the database cannot store.
--
-- The Add/Edit Member selects (Admin_unit.tpl, Unit_index.tpl) offer Organizer,
-- and Unit::CreateUnit assigns role 'organizer' to the founder of every Event
-- unit. But ork_unit_mundane.role is enum('captain','lord','member','owner') —
-- 'organizer' is not a member of it. With @@sql_mode empty (which is how this
-- server runs), MySQL silently coerces the out-of-range value to ''. The save
-- reports success and the row then renders with no role at all.
--
-- Knock-on: Unit::ClaimUnit permits self-claim only for captain/lord/organizer,
-- so an "Organizer" could never claim their own unit.
--
-- This migration:
--   1. Adds 'organizer' to the enum so the value the application already writes
--      can actually be stored.
--   2. Backfills the rows that were silently blanked.
--
-- On the backfill: at time of writing production held 228 rows with role = ''
-- spread across Household (174), Company (43) and Event (10) units, so they are
-- NOT all failed "Organizer" saves — the original intent is unrecoverable. They
-- are all set to 'member', which is the least-privilege choice and preserves
-- current behaviour exactly: ClaimUnit excludes '' and 'member' alike, so no
-- one gains authority from this. Only the display changes, from blank to
-- "Member". Future Event founders will correctly get 'organizer'.
--
-- Idempotent: safe to re-run. The ALTER is a no-op once applied, and the
-- backfill only touches rows still holding ''.
-- ============================================================================

ALTER TABLE `ork_unit_mundane`
    MODIFY `role` enum('captain','lord','member','owner','organizer') NOT NULL;

UPDATE `ork_unit_mundane`
SET `role` = 'member'
WHERE `role` = '';
