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

ALTER TABLE `ork_officer_position`
  ADD COLUMN `parent_position_id` INT NULL DEFAULT NULL,
  ADD COLUMN `hide_when_vacant` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `ork_officer_position` ADD INDEX `idx_parent_position` (`parent_position_id`);
