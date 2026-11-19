-- Migration: RBAC keys for bonus credits and courts
-- Run via: docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-11-03-rbac-bonus-credit-court-permissions.sql
--
-- Depends on: 2026-08-25-01-rbac-tables.sql, -02-rbac-seed.sql, 2026-08-31-rbac-coverage-expansion.sql
-- Idempotent: INSERT IGNORE throughout; the corrective global-scope cleanup (section 3)
-- re-runs clean, and the post-checks (section 4) only read.
--
-- Bonus Credits and the Court Planner / Recommendations Manager were the last officer
-- surfaces gated only by legacy ork_authorization rows. A kingdom could not let someone
-- hand out a survey's credits, or plan a court, without giving them kingdom EDIT -- which
-- is every other EDIT power in the kingdom too. These six keys put both surfaces on the
-- one gate (AuthorizationGate::checkPermissionOrAuthority), which is additive: every
-- current legacy holder keeps access, and role holders are added.
--
--   global.bonus_credit.manage  Amtgard-wide credits           (was the all-zero admin row)
--   kingdom.bonus_credit.define define credits, appoint granters, outside-credit setting
--                                                              (was kingdom CREATE)
--   kingdom.bonus_credit.grant  hand out credits kingdom-wide  (was kingdom EDIT)
--   park.bonus_credit.grant     hand out credits at a park     (was park EDIT)
--   kingdom.court.manage        court planner + recs, kingdom  (was kingdom EDIT)
--   park.court.manage           court planner + recs, park     (was park EDIT)
--
-- The per-credit granter list (ork_bonus_credit_granter) is NOT replaced. It is a
-- data-level delegation -- "this person may give this one survey's credit" -- that a
-- kingdom-wide role cannot express, and it keeps working beside the keys.

-- ============================================================
-- 1. NEW PERMISSIONS
-- ============================================================
-- Text matches PermissionRegistry exactly, so bin/sync-permission-registry.php reports
-- no drift. Bonus credits sit in 'event' beside park.attendance.manage: a bonus credit is
-- a class credit given outside attendance, and the officers who sign players in are the
-- ones who give it. Courts sit in 'award' beside player.recommendation.manage.
INSERT IGNORE INTO `ork_permission` (`key`, `display_name`, `description`, `scope_type`, `category`, `is_system`) VALUES
('global.bonus_credit.manage',  'Manage Amtgard-wide Bonus Credits', 'Define, archive, and restore Amtgard-wide bonus credits, and adopt them on a kingdom''s behalf', 'global', 'event', 1),
('kingdom.bonus_credit.define', 'Define Kingdom Bonus Credits',      'Create, archive, and restore the kingdom''s bonus credits, adopt Amtgard-wide ones, appoint granters, and choose whether other kingdoms'' credits are accepted', 'kingdom', 'event', 1),
('kingdom.bonus_credit.grant',  'Grant Bonus Credits in Kingdom',    'Give and remove bonus credits for players anywhere in the kingdom', 'kingdom', 'event', 1),
('kingdom.court.manage',        'Manage Kingdom Courts',             'Plan kingdom courts and work the kingdom''s award recommendations',  'kingdom', 'award', 1),
('park.bonus_credit.grant',     'Grant Bonus Credits in Park',       'Give and remove bonus credits for the park''s players',              'park',    'event', 1),
('park.court.manage',           'Manage Park Courts',                'Plan park courts and work the park''s award recommendations',        'park',    'award', 1);

-- ============================================================
-- 2. ROLE MAPPINGS
-- ============================================================

-- Monarch / Regent / Prime Minister hold every scoped permission. The seed's CROSS JOIN
-- only ever covered the keys that existed the day it ran, so it is re-run here rather
-- than listing the new keys (RbacRegistryParityTest::testCrownRolesHoldEveryScopedPermission
-- pins this). Global keys are excluded: a Monarch is not an installation operator, and
-- Amtgard-wide credits reach every kingdom that adopts them.
INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `ork_role` r
CROSS JOIN `ork_permission` p
WHERE LOWER(r.name) IN ('monarch', 'regent', 'prime_minister')
  AND r.kingdom_id = 0
  AND p.`scope_type` <> 'global';

-- The ORK Administrator role holds every global permission, so the new one too
-- (pinned by RbacRegistryParityTest::testOrkAdminRoleHoldsEveryGlobalPermission).
INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `ork_role` r
CROSS JOIN `ork_permission` p
WHERE LOWER(r.name) = 'ork_admin' AND r.kingdom_id = 0
  AND p.`scope_type` = 'global';

-- Utility roles pick up only what their names already promise. Shared templates only
-- (r.kingdom_id = 0), for the reason 2026-08-31 section 2 gives: a role name is not
-- provenance, and a kingdom's own role that happens to share a template's name must not
-- be handed keys no Monarch granted it.
--
-- Award Manager ("Delegated award granting and recommendation management") gets both
-- court keys: the Court Planner and the Recommendations Manager are where a kingdom
-- does exactly that work, and without them the role could grant an award but not open
-- the page the recommendations are worked from. Both keys, like Heraldry Manager's banner
-- keys, because the role is assignable at kingdom or park scope -- and a park assignment
-- cannot satisfy the kingdom key, so this widens nothing.
INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `ork_role` r
CROSS JOIN `ork_permission` p
WHERE LOWER(r.name) = 'award_manager'
  AND r.kingdom_id = 0
  AND p.`key` IN ('kingdom.court.manage', 'park.court.manage');

-- Deliberately NOT mapped:
--   * Bonus credit keys to any utility role. No template's name promises them: Attendance
--     Clerk is "attendance only", Treasurer is the books, and Event Coordinator / Champion
--     / GMR run events, tournaments and rules. Who runs a survey's credits is a
--     per-kingdom choice -- a custom role, or the granter list.
--   * Court keys to anyone else. Court is the crown's and its award delegates' business.

-- ============================================================
-- 3. CORRECTIVE: no non-admin role may hold a global permission
-- ============================================================
-- Same invariant, same statement, as 2026-08-31 section 4. This file sorts after that
-- one, and 2026-08-25-02 and -08 still carry unfiltered CROSS JOINs that are documented as
-- re-runnable -- so a by-hand re-apply of either after a production reload would hand
-- every Monarch global.bonus_credit.manage. Re-running this file restores the invariant.
-- Exempts the SYSTEM ork_admin row only (kingdom_id = 0), not the name.
DELETE rp FROM `ork_role_permission` rp
JOIN `ork_permission` p ON p.permission_id = rp.permission_id
JOIN `ork_role` r       ON r.role_id = rp.role_id
WHERE p.`scope_type` = 'global'
  AND NOT (LOWER(r.name) = 'ork_admin' AND r.kingdom_id = 0);

-- ============================================================
-- 4. POST-CHECKS (printed by the run; nothing is written)
-- ============================================================
-- A name-matched grant that matched no role is a silent no-op, so report what was found.
-- Only surfaces on a manual `mariadb < file` run; the ork-db apply path discards stdout.
SELECT 'new key held by' AS check_name,
       p.`key`,
       COUNT(rp.role_id) AS roles_holding
FROM `ork_permission` p
LEFT JOIN `ork_role_permission` rp ON rp.permission_id = p.permission_id
WHERE p.`key` IN ('global.bonus_credit.manage', 'kingdom.bonus_credit.define', 'kingdom.bonus_credit.grant',
                  'park.bonus_credit.grant', 'kingdom.court.manage', 'park.court.manage')
GROUP BY p.`key`
ORDER BY p.`key`;

-- ============================================================
-- VERIFY (informational; run by hand)
-- ============================================================
-- -- Expect 3 crown roles x 5 scoped keys + ork_admin x 1 global + award_manager x 2 = 18:
-- SELECT r.name, p.`key`
--   FROM ork_role r
--   JOIN ork_role_permission rp ON rp.role_id = r.role_id
--   JOIN ork_permission p       ON p.permission_id = rp.permission_id
--  WHERE p.`key` LIKE '%.bonus_credit.%' OR p.`key` LIKE '%.court.manage'
--  ORDER BY r.name, p.`key`;
