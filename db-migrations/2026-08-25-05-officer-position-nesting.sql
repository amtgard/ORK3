-- Migration: Officer Position Nesting + Hide-When-Vacant
-- Run via: docker exec -i ork3-php8-db mariadb -u root -proot ork < migrations/officer-position-nesting.sql
--
-- Adds two columns to the position registry:
--   parent_position_id  -- "Reports To" nesting (NULL = top-level). References
--                          ork_officer_position.position_id; cycle/scope rules
--                          are enforced at the app layer (MyISAM: no FK).
--   hide_when_vacant     -- when 1, a supporting (non-crown) position is hidden
--                          from read-only profile sidebars while it has no
--                          occupant. Forced to 0 for crown/pinned/system rows.

-- Idempotent. officer-position.sql's CREATE TABLE now defines both columns and
-- the index inline, so on a fresh build this migration is a no-op; it still
-- matters for a database created before that change. Without IF NOT EXISTS it
-- aborted with "Duplicate column name 'parent_position_id'" on every fresh
-- build, which is why it never completed on this environment.
ALTER TABLE `ork_officer_position`
  ADD COLUMN IF NOT EXISTS `parent_position_id` INT NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `hide_when_vacant` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `ork_officer_position` ADD INDEX IF NOT EXISTS `idx_parent_position` (`parent_position_id`);
