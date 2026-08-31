-- Migration: RBAC permissions for qualification tests (Walker release)
-- Run via: docker exec -i ork3-php8-db mariadb -u root -proot ork < migrations/qualtest-permissions.sql
--
-- Depends on: rbac-tables.sql, rbac-seed.sql (roles and the permission table must exist)
-- Idempotent: INSERT IGNORE throughout; safe to re-run.
--
-- Why these four and not one:
--   The Walker workflow has three distinct audiences. An officer sets the rules
--   (pass percent, validity, retakes). A subject-matter expert -- who is often NOT
--   an officer, which is the entire reason ork_qual_manager exists -- writes the
--   questions. And only an officer should decide when a written draft goes live to
--   players. Collapsing those into a single "manage tests" permission would mean
--   handing a question author the power to lower the pass mark and publish it.
--   Reading results is separate again: it is the one capability a kingdom may want
--   to give someone who should never touch the bank.
--
-- Before this migration, qualification-test access was resolved entirely outside
-- RBAC (hardcoded officer-role strings plus the ork_qual_manager table), so the
-- Permissions Grid could not show it and a custom role could not grant it.

-- ============================================================
-- PERMISSIONS
-- ============================================================
INSERT IGNORE INTO `ork_permission` (`key`, `display_name`, `description`, `scope_type`, `category`, `is_system`) VALUES
('kingdom.qualtest.config',         'Configure Qualification Tests', 'Set pass percent, question count, validity period, retakes and test managers', 'kingdom', 'config', 1),
('kingdom.qualtest.questions.edit', 'Edit Test Question Banks',      'Author and edit Reeve/Corpora questions and draft question sets',             'kingdom', 'config', 1),
('kingdom.qualtest.publish',        'Publish Qualification Tests',   'Publish a draft question set so players can take the test',                   'kingdom', 'config', 1),
('kingdom.qualtest.results.view',   'View Test Results',             'View Reeve/Corpora results, attempt detail and question statistics',          'kingdom', 'player', 1);

-- ============================================================
-- ROLE-PERMISSION MAPPINGS
-- ============================================================

-- Monarch / Regent / Prime Minister hold ALL permissions. rbac-seed.sql granted that
-- with a CROSS JOIN at seed time, which by definition could not cover permissions
-- added afterwards -- so the join is re-run here rather than listing the new keys.
INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `ork_role` r
CROSS JOIN `ork_permission` p
WHERE r.name IN ('monarch', 'regent', 'prime_minister') AND r.kingdom_id = 0;

-- GMR: the Corpora makes the GMR the test administrator ("Shall write and administer
-- the Reeve and Corpora tests"; "All Reeve and Corpora testing is administered and
-- approved by the current GMR"), so the GMR gets all four, publish included.
INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `ork_role` r
CROSS JOIN `ork_permission` p
WHERE r.name = 'gmr' AND r.kingdom_id = 0
  AND p.`key` IN (
    'kingdom.qualtest.config',
    'kingdom.qualtest.questions.edit',
    'kingdom.qualtest.publish',
    'kingdom.qualtest.results.view'
  );

-- ============================================================
-- VERIFY (informational; run by hand)
-- ============================================================
-- SELECT r.name, p.`key`
--   FROM ork_role_permission rp
--   JOIN ork_role r       ON r.role_id = rp.role_id
--   JOIN ork_permission p ON p.permission_id = rp.permission_id
--  WHERE p.`key` LIKE 'kingdom.qualtest.%'
--  ORDER BY r.name, p.`key`;
