-- Migration: Backfill ork_user_role from existing ork_officer records
-- Run via: docker exec -i ork3-php8-db mariadb -u root -proot ork < migrations/rbac-migrate-officers.sql
--
-- Depends on: rbac-tables.sql and rbac-seed.sql (tables + seed data must exist)
-- Idempotent: Uses INSERT IGNORE to skip already-migrated entries
--
-- This maps each active officer (mundane_id > 0) to the corresponding
-- system role in ork_user_role, preserving their kingdom_id and park_id scope.

-- Monarch officers -> monarch role
INSERT IGNORE INTO `ork_user_role` (`mundane_id`, `role_id`, `kingdom_id`, `park_id`, `event_id`, `unit_id`, `granted_by`)
SELECT
  o.mundane_id,
  r.role_id,
  o.kingdom_id,
  o.park_id,
  0,
  0,
  NULL
FROM `ork_officer` o
JOIN `ork_role` r ON r.name = 'monarch' AND r.kingdom_id = 0
WHERE o.role = 'Monarch'
  AND o.mundane_id > 0;

-- Regent officers -> regent role
INSERT IGNORE INTO `ork_user_role` (`mundane_id`, `role_id`, `kingdom_id`, `park_id`, `event_id`, `unit_id`, `granted_by`)
SELECT
  o.mundane_id,
  r.role_id,
  o.kingdom_id,
  o.park_id,
  0,
  0,
  NULL
FROM `ork_officer` o
JOIN `ork_role` r ON r.name = 'regent' AND r.kingdom_id = 0
WHERE o.role = 'Regent'
  AND o.mundane_id > 0;

-- Prime Minister officers -> prime_minister role
INSERT IGNORE INTO `ork_user_role` (`mundane_id`, `role_id`, `kingdom_id`, `park_id`, `event_id`, `unit_id`, `granted_by`)
SELECT
  o.mundane_id,
  r.role_id,
  o.kingdom_id,
  o.park_id,
  0,
  0,
  NULL
FROM `ork_officer` o
JOIN `ork_role` r ON r.name = 'prime_minister' AND r.kingdom_id = 0
WHERE o.role = 'Prime Minister'
  AND o.mundane_id > 0;

-- Champion officers -> champion role
INSERT IGNORE INTO `ork_user_role` (`mundane_id`, `role_id`, `kingdom_id`, `park_id`, `event_id`, `unit_id`, `granted_by`)
SELECT
  o.mundane_id,
  r.role_id,
  o.kingdom_id,
  o.park_id,
  0,
  0,
  NULL
FROM `ork_officer` o
JOIN `ork_role` r ON r.name = 'champion' AND r.kingdom_id = 0
WHERE o.role = 'Champion'
  AND o.mundane_id > 0;

-- GMR officers -> gmr role
INSERT IGNORE INTO `ork_user_role` (`mundane_id`, `role_id`, `kingdom_id`, `park_id`, `event_id`, `unit_id`, `granted_by`)
SELECT
  o.mundane_id,
  r.role_id,
  o.kingdom_id,
  o.park_id,
  0,
  0,
  NULL
FROM `ork_officer` o
JOIN `ork_role` r ON r.name = 'gmr' AND r.kingdom_id = 0
WHERE o.role = 'GMR'
  AND o.mundane_id > 0;
