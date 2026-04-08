-- Migration: Seed RBAC tables with permissions, system roles, and role-permission mappings
-- Run via: docker exec -i ork3-php8-db mariadb -u root -proot ork < migrations/rbac-seed.sql
--
-- Depends on: rbac-tables.sql (tables must exist first)
-- Idempotent: Uses INSERT IGNORE to skip existing rows

-- ============================================================
-- PERMISSIONS — 54 atomic permissions
-- ============================================================

-- Kingdom-Scoped (15)
INSERT IGNORE INTO `ork_permission` (`key`, `display_name`, `description`, `scope_type`, `category`, `is_system`) VALUES
('kingdom.details.edit', 'Edit Kingdom Details', 'Edit kingdom name, description, and basic details', 'kingdom', 'config', 1),
('kingdom.config.edit', 'Edit Kingdom Config', 'Edit kingdom configuration settings', 'kingdom', 'config', 1),
('kingdom.parktitle.manage', 'Manage Park Titles', 'Create, edit, and remove park title definitions', 'kingdom', 'config', 1),
('kingdom.award.create', 'Create Kingdom Award', 'Create new kingdom award definitions', 'kingdom', 'award', 1),
('kingdom.award.edit', 'Edit Kingdom Award', 'Edit existing kingdom award definitions', 'kingdom', 'award', 1),
('kingdom.award.remove', 'Remove Kingdom Award', 'Remove kingdom award definitions', 'kingdom', 'award', 1),
('kingdom.officer.set', 'Set Kingdom Officer', 'Appoint kingdom-level officers', 'kingdom', 'officer', 1),
('kingdom.officer.vacate', 'Vacate Kingdom Officer', 'Remove kingdom-level officers from office', 'kingdom', 'officer', 1),
('kingdom.officer_history.manage', 'Manage Officer History', 'Create, edit, and delete officer history records', 'kingdom', 'officer', 1),
('kingdom.heraldry.manage', 'Manage Kingdom Heraldry', 'Upload and remove kingdom heraldry', 'kingdom', 'heraldry', 1),
('kingdom.auth.manage', 'Manage Kingdom Authorizations', 'Add and remove kingdom-level authorizations', 'kingdom', 'auth', 1),
('kingdom.park.create', 'Create Parks', 'Create new parks within the kingdom', 'kingdom', 'config', 1),
('kingdom.park.retire', 'Retire/Restore Parks', 'Retire or restore parks within the kingdom', 'kingdom', 'config', 1),
('kingdom.park.bulk_edit', 'Bulk Edit Parks', 'Bulk edit park settings across the kingdom', 'kingdom', 'config', 1),
('kingdom.park.claim', 'Claim/Transfer Parks', 'Claim or transfer parks between kingdoms', 'kingdom', 'config', 1);

-- Park-Scoped (12)
INSERT IGNORE INTO `ork_permission` (`key`, `display_name`, `description`, `scope_type`, `category`, `is_system`) VALUES
('park.details.edit', 'Edit Park Details', 'Edit park name, description, and basic details', 'park', 'config', 1),
('park.officer.set', 'Set Park Officer', 'Appoint park-level officers', 'park', 'officer', 1),
('park.officer.vacate', 'Vacate Park Officer', 'Remove park-level officers from office', 'park', 'officer', 1),
('park.officer_history.manage', 'Manage Park Officer History', 'Create, edit, and delete park officer history records', 'park', 'officer', 1),
('park.heraldry.manage', 'Manage Park Heraldry', 'Upload and remove park heraldry', 'park', 'heraldry', 1),
('park.auth.manage', 'Manage Park Authorizations', 'Add and remove park-level authorizations', 'park', 'auth', 1),
('park.parkday.manage', 'Manage Park Days', 'Create, edit, and delete park day schedules', 'park', 'config', 1),
('park.event.create', 'Create Park Events', 'Create events for the park', 'park', 'event', 1),
('park.attendance.manage', 'Manage Attendance', 'Record, edit, and delete attendance entries', 'park', 'event', 1),
('park.report.view', 'View Park Reports', 'Access park-level reports', 'park', 'config', 1),
('park.dues.manage', 'Manage Dues', 'Record and manage player dues', 'park', 'financial', 1),
('park.reconcile_credits', 'Set Reconciled Credits', 'Set reconciled credit amounts for players', 'park', 'financial', 1);

-- Player-Scoped at park level (12)
INSERT IGNORE INTO `ork_permission` (`key`, `display_name`, `description`, `scope_type`, `category`, `is_system`) VALUES
('player.create', 'Create Player', 'Create new player accounts', 'park', 'player', 1),
('player.edit', 'Edit Other Player Details', 'Edit other players profile details', 'park', 'player', 1),
('player.move', 'Move Player Between Parks', 'Transfer players between parks', 'park', 'player', 1),
('player.merge', 'Merge Players', 'Merge duplicate player records', 'park', 'player', 1),
('player.suspend', 'Set Player Suspension', 'Suspend or unsuspend player accounts', 'park', 'player', 1),
('player.waiver.manage', 'Manage Waivers & Restrictions', 'Manage player waivers and restrictions', 'park', 'player', 1),
('player.qualification.edit', 'Edit Reeve/Corpora Qualifications', 'Edit player reeve and corpora qualification status', 'park', 'player', 1),
('player.heraldry.manage', 'Manage Other Player Heraldry/Image', 'Upload and remove other players heraldry and images', 'park', 'heraldry', 1),
('player.note.manage', 'Manage Other Player Notes', 'Create, edit, and delete notes on other players', 'park', 'player', 1),
('player.award.manage', 'Manage Player Awards', 'Grant, edit, and remove player awards', 'park', 'award', 1),
('player.recommendation.manage', 'Manage Award Recommendations', 'Manage award recommendations for players', 'park', 'award', 1),
('player.active_status.set', 'Set Player Active Status', 'Set player active/inactive status', 'park', 'player', 1);

-- Event-Scoped (8)
INSERT IGNORE INTO `ork_permission` (`key`, `display_name`, `description`, `scope_type`, `category`, `is_system`) VALUES
('event.edit', 'Edit Event', 'Edit event name, dates, and basic details', 'event', 'event', 1),
('event.delete', 'Delete Event', 'Delete events', 'event', 'event', 1),
('event.detail.manage', 'Manage Event Details', 'Manage event locations, descriptions, and details', 'event', 'event', 1),
('event.heraldry.manage', 'Manage Event Heraldry', 'Upload and remove event heraldry', 'event', 'heraldry', 1),
('event.attendance.manage', 'Manage Event Attendance', 'Record, edit, and delete event attendance', 'event', 'event', 1),
('event.reconcile', 'Reconcile Event Attendance', 'Reconcile event attendance records', 'event', 'event', 1),
('event.auth.manage', 'Manage Event Authorizations', 'Add and remove event-level authorizations', 'event', 'auth', 1),
('event.rsvp.manage', 'Manage RSVPs (admin)', 'Manage event RSVPs on behalf of other players', 'event', 'event', 1);

-- Unit-Scoped (5)
INSERT IGNORE INTO `ork_permission` (`key`, `display_name`, `description`, `scope_type`, `category`, `is_system`) VALUES
('unit.edit', 'Edit Unit Details', 'Edit unit name, description, and details', 'unit', 'config', 1),
('unit.member.manage', 'Manage Unit Members', 'Add, remove, and manage unit members', 'unit', 'player', 1),
('unit.heraldry.manage', 'Manage Unit Heraldry', 'Upload and remove unit heraldry', 'unit', 'heraldry', 1),
('unit.convert', 'Convert Unit Type', 'Convert unit between company and household types', 'unit', 'config', 1),
('unit.auth.manage', 'Manage Unit Authorizations', 'Add and remove unit-level authorizations', 'unit', 'auth', 1);

-- Tournament (2)
INSERT IGNORE INTO `ork_permission` (`key`, `display_name`, `description`, `scope_type`, `category`, `is_system`) VALUES
('tournament.bracket.manage', 'Manage Tournament Brackets', 'Create, edit, and manage tournament brackets', 'event', 'event', 1),
('tournament.delete', 'Delete Tournament', 'Delete tournament records', 'event', 'event', 1);


-- ============================================================
-- SYSTEM ROLES — 5 officer roles + 5 utility roles
-- ============================================================

-- Officer roles (is_system=1, kingdom_id=0 = system-wide)
INSERT IGNORE INTO `ork_role` (`name`, `display_name`, `description`, `scope_type`, `is_system`, `kingdom_id`) VALUES
('monarch', 'Monarch', 'Kingdom or park Monarch — full permissions at scope', 'kingdom', 1, 0),
('regent', 'Regent', 'Kingdom or park Regent — full permissions at scope', 'kingdom', 1, 0),
('prime_minister', 'Prime Minister', 'Kingdom or park Prime Minister — full permissions at scope', 'kingdom', 1, 0),
('champion', 'Champion', 'Kingdom or park Champion — tournament and attendance permissions', 'kingdom', 1, 0),
('gmr', 'GMR', 'Kingdom or park GMR — qualification, compliance, and rules permissions', 'kingdom', 1, 0);

-- Utility roles (is_system=1, kingdom_id=0 = available in all kingdoms)
INSERT IGNORE INTO `ork_role` (`name`, `display_name`, `description`, `scope_type`, `is_system`, `kingdom_id`) VALUES
('award_manager', 'Award Manager', 'Delegated award granting and recommendation management', 'park', 1, 0),
('event_coordinator', 'Event Coordinator', 'Full event management including creation and attendance', 'park', 1, 0),
('attendance_clerk', 'Attendance Clerk', 'Record and manage attendance only', 'park', 1, 0),
('treasurer', 'Treasurer', 'Financial management including dues and reconciliation', 'park', 1, 0),
('heraldry_manager', 'Heraldry Manager', 'Manage heraldry across assigned scope', 'park', 1, 0);


-- ============================================================
-- ROLE-PERMISSION MAPPINGS
-- ============================================================
-- We use subqueries to resolve IDs so this is order-independent.

-- Monarch: ALL permissions (full access at scope — same as today)
INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `ork_role` r
CROSS JOIN `ork_permission` p
WHERE r.name = 'monarch' AND r.kingdom_id = 0;

-- Regent: ALL permissions (identical to Monarch for Phase 1)
INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `ork_role` r
CROSS JOIN `ork_permission` p
WHERE r.name = 'regent' AND r.kingdom_id = 0;

-- Prime Minister: ALL permissions (identical to Monarch for Phase 1)
INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `ork_role` r
CROSS JOIN `ork_permission` p
WHERE r.name = 'prime_minister' AND r.kingdom_id = 0;

-- Champion: tournament + attendance permissions
INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `ork_role` r
CROSS JOIN `ork_permission` p
WHERE r.name = 'champion' AND r.kingdom_id = 0
  AND p.`key` IN ('tournament.bracket.manage', 'tournament.delete', 'park.attendance.manage');

-- GMR: qualification, compliance, attendance, reconcile permissions
INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `ork_role` r
CROSS JOIN `ork_permission` p
WHERE r.name = 'gmr' AND r.kingdom_id = 0
  AND p.`key` IN ('player.qualification.edit', 'player.waiver.manage', 'park.attendance.manage', 'event.reconcile');

-- Award Manager: award + recommendation permissions
INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `ork_role` r
CROSS JOIN `ork_permission` p
WHERE r.name = 'award_manager' AND r.kingdom_id = 0
  AND p.`key` IN ('player.award.manage', 'player.recommendation.manage');

-- Event Coordinator: all event permissions + park event creation + attendance
INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `ork_role` r
CROSS JOIN `ork_permission` p
WHERE r.name = 'event_coordinator' AND r.kingdom_id = 0
  AND p.`key` IN (
    'event.edit', 'event.delete', 'event.detail.manage', 'event.heraldry.manage',
    'event.attendance.manage', 'event.reconcile', 'event.auth.manage', 'event.rsvp.manage',
    'park.event.create', 'park.attendance.manage'
  );

-- Attendance Clerk: attendance only
INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `ork_role` r
CROSS JOIN `ork_permission` p
WHERE r.name = 'attendance_clerk' AND r.kingdom_id = 0
  AND p.`key` IN ('park.attendance.manage');

-- Treasurer: financial permissions
INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `ork_role` r
CROSS JOIN `ork_permission` p
WHERE r.name = 'treasurer' AND r.kingdom_id = 0
  AND p.`key` IN ('park.dues.manage', 'park.reconcile_credits', 'park.report.view');

-- Heraldry Manager: all heraldry permissions across scopes
INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
SELECT r.role_id, p.permission_id
FROM `ork_role` r
CROSS JOIN `ork_permission` p
WHERE r.name = 'heraldry_manager' AND r.kingdom_id = 0
  AND p.`key` IN (
    'kingdom.heraldry.manage', 'park.heraldry.manage', 'player.heraldry.manage',
    'event.heraldry.manage', 'unit.heraldry.manage'
  );
