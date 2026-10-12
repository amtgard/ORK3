-- Repair: ork_session.user_agent / ork_session.ip on environments that already
-- ran 2026-07-13-add-ork-session-table.sql — 2026-08-18
--
-- The device-metadata columns were added by editing the ORIGINAL migration in
-- place. That file leads with CREATE TABLE IF NOT EXISTS, so re-running it on a
-- database that created ork_session from the pre-metadata version is a no-op:
-- the columns never appear. CreateSession() then INSERTs user_agent/ip into a
-- table that has neither, the insert fails, the read-back finds no row, and
-- CreateSession returns '' — every password login dies with "Could not
-- establish a session." Fresh installs are fine (ork.sql and the current
-- migration both declare the columns); only already-migrated environments break.
--
-- Idempotent, so it is safe to run everywhere regardless of which version of the
-- original migration an environment applied.

ALTER TABLE `ork_session`
  ADD COLUMN IF NOT EXISTS `user_agent` VARCHAR(255) NOT NULL DEFAULT '' AFTER `expires`;

ALTER TABLE `ork_session`
  ADD COLUMN IF NOT EXISTS `ip` VARCHAR(45) NOT NULL DEFAULT '' AFTER `user_agent`;
