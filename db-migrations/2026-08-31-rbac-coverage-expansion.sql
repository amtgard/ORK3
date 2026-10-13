-- Migration: RBAC coverage expansion
-- Run via: docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-08-31-rbac-coverage-expansion.sql
--
-- Depends on: 2026-08-25-01-rbac-tables.sql, -02-rbac-seed.sql, -08-qualtest-permissions.sql
-- Idempotent: INSERT IGNORE throughout; the two key deletions and the corrective global-scope
-- cleanup (section 4) all re-run clean, and the post-checks (section 5) only read.
--
-- Brings ork_permission in line with PermissionRegistry after the coverage audit.
-- Three kinds of change:
--
--   1. ADDS 22 permissions for actions that had none. The largest group is the new
--      `global` scope -- installation-operator capabilities that were reachable only by
--      holding an all-zero-scope `admin` row, which is all-or-nothing. The rest cover
--      surfaces the first pass missed entirely: the treasury ledger, banners, calendar
--      items, unit lifecycle, event publish/fees/schedule, and tournament creation
--      (which previously required nothing beyond being logged in).
--
--   2. SPLITS role administration out of kingdom.auth.manage. Defining what a role means
--      and handing that role to a person are different acts; a kingdom could not delegate
--      the upkeep of legacy authorization rows without also delegating the permission
--      system. Every role that currently holds kingdom.auth.manage is granted both new
--      keys, so nothing an existing role could do stops working.
--
--   3. REMOVES two keys. kingdom.park.bulk_edit names a feature that does not exist.
--      park.officer.position.manage names a capability that must not exist: officer
--      positions are a per-KINGDOM registry, and RetirePosition vacates every holder of
--      a position across every scope in the kingdom, so a park-scoped grant would let one
--      park's officer strip officers from every other park. OfficerPosition::PermissionKeyFor()
--      now resolves the whole position family to the kingdom key regardless of ParkId.
--
--   NOT changed: kingdom.park.create and kingdom.park.claim. Park creation and
--   cross-kingdom transfer are reserved to the ORK team by policy and their gates
--   deliberately do not consult these keys. They stay in the catalog so the permissions
--   grid can show a kingdom what those actions are; the consoles render the tiles
--   disabled with an explanatory tip for anyone who is not an ORK Administrator.

-- ============================================================
-- 1. NEW PERMISSIONS
-- ============================================================

-- Global scope (8) — ORK Administrator capabilities.
-- global.admin.grant is READ-ONLY on purpose. AuthorizationGate::GrantScopedAuthorization()
-- and ::RevokeScopedAuthorization() both refuse a holder of this key alone on the all-zero
-- admin row (see the guards at class.AuthorizationGate.php:158 and :211, and the two
-- AuthorizationGatePermissionTest cases that pin the refusal). The only thing it actually
-- confers is Administration::GetGlobalAdminGrants(), which reads. The label says so, so the
-- permissions grid does not offer a tile that promises add/remove and delivers a list.
INSERT IGNORE INTO `ork_permission` (`key`, `display_name`, `description`, `scope_type`, `category`, `is_system`) VALUES
('global.admin.grant',             'View ORK Administrator Grants', 'See who holds installation-wide administrator access',                              'global', 'auth',   1),
('global.maintenance.run',         'Run System Maintenance',       'Purge logs and optimize database tables',                                            'global', 'system', 1),
('global.health.view',             'View Server Health',           'View database status, running processes, and service diagnostics',                   'global', 'system', 1),
('global.award_catalog.manage',    'Manage Shared Award Catalog',  'Create, edit, and remove the award definitions shared by every kingdom',             'global', 'award',  1),
('global.attendance_class.manage', 'Manage Attendance Classes',    'Create and edit the attendance class list shared by every park',                     'global', 'event',  1),
('global.kingdom.manage',          'Manage Kingdoms',              'Create kingdoms, retire or restore them, and set principality parentage',            'global', 'config', 1),
('global.player.merge',            'Merge Player Records',         'Merge one player record into another across kingdoms',                              'global', 'player', 1),
('global.player.ban',              'Ban Player from ORK',          'Set or clear an installation-wide ban on a player account',                          'global', 'player', 1);

-- Kingdom scope (4).
INSERT IGNORE INTO `ork_permission` (`key`, `display_name`, `description`, `scope_type`, `category`, `is_system`) VALUES
('kingdom.role.manage',     'Manage Roles',                    'Create, edit, and delete the kingdom''s custom roles and their permission sets', 'kingdom', 'auth',     1),
('kingdom.role.grant',      'Assign Roles',                    'Grant and revoke roles for players at kingdom, park, event, or unit scope',      'kingdom', 'auth',     1),
('kingdom.banner.manage',   'Manage Kingdom Banner',           'Upload, configure, and remove the kingdom profile banner',                       'kingdom', 'heraldry', 1),
('kingdom.calendar.manage', 'Manage Kingdom Calendar Items',   'Create, edit, and delete kingdom calendar entries',                              'kingdom', 'event',    1);

-- Park scope (3).
INSERT IGNORE INTO `ork_permission` (`key`, `display_name`, `description`, `scope_type`, `category`, `is_system`) VALUES
('park.banner.manage',   'Manage Park Banner',            'Upload, configure, and remove the park profile banner',      'park', 'heraldry',  1),
('park.calendar.manage', 'Manage Park Calendar Items',    'Create, edit, and delete park calendar entries',             'park', 'event',     1),
('park.treasury.manage', 'Manage Treasury Accounts',      'Open accounts and record or remove treasury transactions',   'park', 'financial', 1);

-- Event scope (5).
INSERT IGNORE INTO `ork_permission` (`key`, `display_name`, `description`, `scope_type`, `category`, `is_system`) VALUES
('event.publish',         'Publish Event',                   'Move an event between draft and published, and cancel or restore it', 'event', 'event',    1),
('event.fees.manage',     'Manage Event Fees & Links',       'Set event fees and registration links',                               'event', 'event',    1),
('event.schedule.manage', 'Manage Event Schedule & Staff',   'Build the event schedule and assign staff to schedule slots',         'event', 'event',    1),
('event.banner.manage',   'Manage Event Banner',             'Upload, configure, and remove the event profile banner',              'event', 'heraldry', 1),
('tournament.create',     'Create Tournament',               'Create a tournament under a kingdom, park, or event',                 'event', 'event',    1);

-- Unit scope (2).
INSERT IGNORE INTO `ork_permission` (`key`, `display_name`, `description`, `scope_type`, `category`, `is_system`) VALUES
('unit.lifecycle.manage', 'Retire, Restore & Transfer Units', 'Retire or restore a unit and transfer its ownership to another player', 'unit', 'config',   1),
('unit.banner.manage',    'Manage Unit Banner',               'Upload, configure, and remove the unit profile banner',                 'unit', 'heraldry', 1);

-- ============================================================
-- 2. ROLE MAPPINGS
-- ============================================================

-- Monarch / Regent / Prime Minister hold ALL permissions. rbac-seed.sql granted that
-- with a CROSS JOIN at seed time, which by definition cannot cover permissions added
-- afterwards -- so the join is re-run here rather than listing the new keys. (The
-- qualtest migration did the same thing; RbacRegistryParityTest now asserts the
-- invariant so the next migration that forgets this is caught by the test suite
-- instead of by an officer.)
--
-- Global permissions are EXCLUDED. A kingdom's Monarch is not an installation operator:
-- granting them global.maintenance.run would hand every crowned head in the game the
-- ability to purge the logs. They go to the new ork_admin role below.
INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `ork_role` r
CROSS JOIN `ork_permission` p
WHERE LOWER(r.name) IN ('monarch', 'regent', 'prime_minister')
  AND r.kingdom_id = 0
  AND p.`scope_type` <> 'global';

-- An installation-operator role for the ORK team. Created but assigned to nobody: the
-- existing all-zero-scope `admin` rows keep working through RBACService::IsAdmin(), and
-- this role is what lets the team hand out one capability without handing out all of
-- them. Only a true global admin can assign it (RBACService::GrantRole).
INSERT IGNORE INTO `ork_role` (`name`, `display_name`, `description`, `scope_type`, `is_system`, `kingdom_id`) VALUES
('ork_admin', 'ORK Administrator', 'Installation-wide operator capabilities: maintenance, diagnostics, the shared award catalog, kingdom lifecycle, and admin grants', 'global', 1, 0);

INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `ork_role` r
CROSS JOIN `ork_permission` p
WHERE LOWER(r.name) = 'ork_admin' AND r.kingdom_id = 0
  AND p.`scope_type` = 'global';

-- Role administration for every role that already holds kingdom.auth.manage. Without
-- this, splitting the gates would silently strip role management from every custom role
-- a kingdom has already built.
INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT rp.role_id, new_p.permission_id
FROM `ork_role_permission` rp
JOIN `ork_permission` old_p ON old_p.permission_id = rp.permission_id AND old_p.`key` = 'kingdom.auth.manage'
CROSS JOIN `ork_permission` new_p
WHERE new_p.`key` IN ('kingdom.role.manage', 'kingdom.role.grant');

-- Utility roles pick up the keys that match what their names already promise.
--
-- Restricted to r.kingdom_id = 0 -- the SHARED TEMPLATES ONLY -- exactly like the crown
-- CROSS JOIN above. A role name is not provenance: nothing reserves these names, so a
-- kingdom may have created its own locally-scoped role called "Treasurer" that is
-- deliberately more restricted than the template. Matching on the name alone would hand
-- every holder of that role park.treasury.manage (or tournament.create, or the banner
-- keys) with no Monarch having granted it, no audit trail separating the intent from the
-- collision, and no way for the kingdom to detect or opt out. Contrast the role.manage
-- back-grant above, which keys off a permission the role demonstrably already holds.
--
-- A per-kingdom clone that genuinely needs these keys is a follow-up migration that proves
-- it is a clone by diffing its permission set against the template's, not this one.
--
-- FORWARD-ONLY, on purpose, and deliberately unlike section 4. This restriction stops a
-- name collision from being granted; it does NOT revoke a row an earlier draft of this
-- file already wrote to a name-colliding per-kingdom role. Section 4 can re-assert its
-- invariant because "no non-admin role holds a global permission" is true by construction,
-- so a delete there can never destroy an intentional grant. Here the two cases are
-- indistinguishable: park.treasury.manage on a kingdom's own "Treasurer" looks identical
-- whether this file put it there or a Monarch did, and revoking a Monarch's grant is worse
-- than leaving a stale one. Nothing shipped ever ran the unrestricted version (this file is
-- unshipped, and the local mirror has no kingdom_id <> 0 roles at all); a sandbox that took
-- the draft and has custom roles must clean up by hand.
--
-- LOWER() is belt-and-braces only: these tables are utf8mb4_unicode_ci, so the bare
-- comparison is already case-insensitive; the explicit call documents the intent and
-- survives a future column recollated to a _bin variant.

-- Treasurer: the ledger itself. park.dues.manage only ever covered a player's dues row,
-- so the role could not touch the books it is named for.
INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `ork_role` r
CROSS JOIN `ork_permission` p
WHERE LOWER(r.name) = 'treasurer'
  AND r.kingdom_id = 0
  AND p.`key` = 'park.treasury.manage';

-- Event Coordinator: publishing, fees, and the schedule are event management. Without
-- these the role could edit an event but never put it on the calendar.
INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `ork_role` r
CROSS JOIN `ork_permission` p
WHERE LOWER(r.name) = 'event_coordinator'
  AND r.kingdom_id = 0
  AND p.`key` IN ('event.publish', 'event.fees.manage', 'event.schedule.manage', 'event.banner.manage', 'tournament.create');

-- Champion: already holds tournament.bracket.manage and tournament.delete, and could not
-- create the tournament those act on.
INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `ork_role` r
CROSS JOIN `ork_permission` p
WHERE LOWER(r.name) = 'champion'
  AND r.kingdom_id = 0
  AND p.`key` = 'tournament.create';

-- Heraldry Manager: banners are a display asset in the same way heraldry is, and the
-- role's whole scope is display assets.
INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `ork_role` r
CROSS JOIN `ork_permission` p
WHERE LOWER(r.name) = 'heraldry_manager'
  AND r.kingdom_id = 0
  AND p.`key` IN ('kingdom.banner.manage', 'park.banner.manage', 'event.banner.manage', 'unit.banner.manage');

-- ============================================================
-- 3. REMOVALS
-- ============================================================
-- Order matters: clear the mapping rows before the permission rows. There is no FK
-- between ork_role_permission and ork_permission, so a permission deleted first would
-- leave orphaned mappings that no query can explain.

DELETE rp FROM `ork_role_permission` rp
JOIN `ork_permission` p ON p.permission_id = rp.permission_id
WHERE p.`key` IN ('kingdom.park.bulk_edit', 'park.officer.position.manage');

-- Any live assignment of these two is also meaningless now. They were only ever held via
-- a role, so this is belt-and-braces against a hand-written row.
DELETE FROM `ork_permission`
WHERE `key` IN ('kingdom.park.bulk_edit', 'park.officer.position.manage');

-- ============================================================
-- 3b. TEXT RECONCILIATION for the two ORK-Administrator keys
-- ============================================================
-- kingdom.park.create and kingdom.park.claim were seeded by 2026-08-25-02 with plain
-- labels. PermissionRegistry now names them "(ORK Administrator)" so the permissions grid
-- and the officer builder say who actually performs them -- but every INSERT in this file
-- is INSERT IGNORE, which by definition cannot update a row that already exists. Without
-- this block the registry and ork_permission disagree on display_name/description forever,
-- and bin/sync-permission-registry.php reports drift on every run.
--
-- UPDATE rather than DELETE+INSERT: these two rows are referenced by ork_role_permission
-- (the crown roles hold both keys by design -- see the header note), and there are no
-- foreign keys, so re-inserting would orphan those mappings behind a new permission_id.
UPDATE `ork_permission`
   SET `display_name` = 'Create Parks (ORK Administrator)',
       `description`  = 'Create new parks within the kingdom — performed by the ORK team'
 WHERE `key` = 'kingdom.park.create';

UPDATE `ork_permission`
   SET `display_name` = 'Claim/Transfer Parks (ORK Administrator)',
       `description`  = 'Claim or transfer parks between kingdoms — performed by the ORK team'
 WHERE `key` = 'kingdom.park.claim';

-- Same reason, for a database that already took an earlier run of THIS file while
-- global.admin.grant still claimed add/remove: the INSERT IGNORE above cannot correct a
-- row that exists, so the corrected text is applied here too.
UPDATE `ork_permission`
   SET `display_name` = 'View ORK Administrator Grants',
       `description`  = 'See who holds installation-wide administrator access'
 WHERE `key` = 'global.admin.grant';

-- ============================================================
-- 4. CORRECTIVE: no non-admin role may hold a global permission
-- ============================================================
-- Belt and braces against RUN ORDER, not against this file. 2026-08-25-02-rbac-seed.sql
-- and -08-qualtest-permissions.sql both grant the crown roles every permission with an
-- unfiltered CROSS JOIN, and both are INSERT IGNORE and documented as re-runnable -- this
-- project re-applies unshipped branch migrations by hand after a production reload. Either
-- one applied AFTER this file silently hands every Monarch, Regent and Prime Minister
-- global.admin.grant, global.maintenance.run and global.player.ban, and because the failure
-- GRANTS rather than denies, nothing in production would notice.
--
-- Those two files are already shipped and are not being rewritten. Re-running THIS file
-- restores the invariant instead. It is scoped to global-scope permissions and spares the
-- ork_admin role, so it is idempotent and deletes nothing a role is entitled to.
--
-- The exemption is the SYSTEM ork_admin row (kingdom_id = 0), not the name: nothing
-- reserves role names, so RBACService::create_role_internal() will happily let a kingdom
-- create its own role called "ork_admin" at its own kingdom_id, and exempting by name
-- alone would let that copy keep every global permission this section exists to strip.
DELETE rp FROM `ork_role_permission` rp
JOIN `ork_permission` p ON p.permission_id = rp.permission_id
JOIN `ork_role` r       ON r.role_id = rp.role_id
WHERE p.`scope_type` = 'global'
  AND NOT (LOWER(r.name) = 'ork_admin' AND r.kingdom_id = 0);

-- ============================================================
-- 5. POST-CHECKS (printed by the run; nothing is written)
-- ============================================================
-- INSERT IGNORE and a WHERE that matches no role are indistinguishable -- both are a
-- silent no-op -- so the grants above report what they actually found: a role renamed,
-- retired or never seeded on this database is exactly how a name-matched grant reaches zero
-- rows and says nothing about it. Counted at kingdom_id = 0 only, because that is the sole
-- place the grants above look.
--
-- NOTE: these SELECTs only surface on a manual `mariadb < file` run. The ork-db apply path
-- (tools/ork-db Apply.php) discards stdout on success, so a rendered sandbox rebuild will
-- not show them.
SELECT 'named role found?' AS check_name,
       n.role_name,
       (SELECT COUNT(*) FROM `ork_role` r WHERE LOWER(r.name) = n.role_name AND r.kingdom_id = 0) AS template_roles_matched
FROM (
    SELECT 'monarch' AS role_name UNION ALL SELECT 'regent' UNION ALL SELECT 'prime_minister'
    UNION ALL SELECT 'ork_admin' UNION ALL SELECT 'treasurer' UNION ALL SELECT 'event_coordinator'
    UNION ALL SELECT 'champion' UNION ALL SELECT 'heraldry_manager'
) n
ORDER BY n.role_name;

-- A role whose ONLY permission was one of the two keys removed in section 3 is now holding
-- nothing: still in ork_role, still assignable, still granting its holders exactly zero.
-- Nothing distinguishes that from a role deliberately left empty, so it is reported here
-- for an operator to retire or backfill.
SELECT 'role left with no permissions' AS check_name,
       r.role_id,
       r.name,
       r.kingdom_id
FROM `ork_role` r
LEFT JOIN `ork_role_permission` rp ON rp.role_id = r.role_id
WHERE rp.role_id IS NULL
ORDER BY r.kingdom_id, r.name;

-- ============================================================
-- VERIFY (informational; run by hand)
-- ============================================================
-- -- Catalog size should match PermissionRegistry::Count() (79 at time of writing):
-- SELECT COUNT(*) FROM ork_permission;
--
-- -- Crown roles should hold every non-global permission and no global one:
-- SELECT r.name, SUM(p.scope_type <> 'global') AS scoped, SUM(p.scope_type = 'global') AS global_held
--   FROM ork_role r
--   JOIN ork_role_permission rp ON rp.role_id = r.role_id
--   JOIN ork_permission p       ON p.permission_id = rp.permission_id
--  WHERE r.name IN ('monarch','regent','prime_minister') AND r.kingdom_id = 0
--  GROUP BY r.name;
--
-- -- Nothing should reference the two removed keys:
-- SELECT COUNT(*) FROM ork_permission WHERE `key` IN ('kingdom.park.bulk_edit','park.officer.position.manage');
