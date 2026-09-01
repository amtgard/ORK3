<?php

/***
 * PermissionRegistry
 *
 * Source of truth for all RBAC permissions. Defines the complete list of
 * atomic permissions used by the system. The database ork_permission table
 * is seeded from this registry via SyncToDatabase().
 *
 * Auto-loaded by startup.php as Ork3::$Lib->permissionregistry
 ***/

class PermissionRegistry extends Ork3
{
    /**
     * Master list of all permissions.
     * Format: 'key' => ['display_name', 'description', 'scope_type', 'category']
     *
     * Naming convention: {scope}.{resource}.{action}
     */
    private static $permissions = [

        // ========================================
        // Global-Scoped — ORK Administrator capabilities
        // ========================================
        // These have no org-unit scope: they act on the installation itself. Before
        // this block every one of them was reachable only by holding an all-zero-scope
        // `admin` row in ork_authorization, which is all-or-nothing -- a kingdom could
        // not be handed "read server health" without also being handed "purge the logs"
        // and "edit the shared award catalog". They are granted at scope_type 'global'
        // with scope_id 0 (see RBACService::HasPermission), and the legacy admin row
        // still satisfies every one of them through the IsAdmin() bypass.
        // READ-ONLY in practice, and named for what it actually confers. It gates
        // Administration::GetGlobalAdminGrants() -- seeing who holds installation-wide
        // admin -- but it cannot MINT one: AuthorizationGate refuses an AUTH_ADMIN grant
        // to anyone who is not already a true all-zero-scope admin, which
        // testGlobalAdminGrantAloneCannotMintAnAdminAuthorization pins. The old name
        // ("Grant ORK Administrator" / "Add and remove ...") advertised a capability the
        // code deliberately withholds, which is worse than no key at all.
        'global.admin.grant' => [
            'View ORK Administrator Grants',
            'See who holds installation-wide administrator access',
            'global', 'auth'
        ],
        'global.maintenance.run' => [
            'Run System Maintenance',
            'Purge logs and optimize database tables',
            'global', 'system'
        ],
        'global.health.view' => [
            'View Server Health',
            'View database status, running processes, and service diagnostics',
            'global', 'system'
        ],
        'global.award_catalog.manage' => [
            'Manage Shared Award Catalog',
            'Create, edit, and remove the award definitions shared by every kingdom',
            'global', 'award'
        ],
        'global.attendance_class.manage' => [
            'Manage Attendance Classes',
            'Create and edit the attendance class list shared by every park',
            'global', 'event'
        ],
        'global.kingdom.manage' => [
            'Manage Kingdoms',
            'Create kingdoms, retire or restore them, and set principality parentage',
            'global', 'config'
        ],
        'global.player.merge' => [
            'Merge Player Records',
            'Merge one player record into another across kingdoms',
            'global', 'player'
        ],
        'global.player.ban' => [
            'Ban Player from ORK',
            'Set or clear an installation-wide ban on a player account',
            'global', 'player'
        ],

        // ========================================
        // Kingdom-Scoped
        // ========================================
        'kingdom.details.edit' => [
            'Edit Kingdom Details',
            'Edit kingdom name, description, and basic details',
            'kingdom', 'config'
        ],
        'kingdom.config.edit' => [
            'Edit Kingdom Config',
            'Edit kingdom configuration settings',
            'kingdom', 'config'
        ],
        'kingdom.parktitle.manage' => [
            'Manage Park Titles',
            'Create, edit, and remove park title definitions',
            'kingdom', 'config'
        ],
        'kingdom.award.create' => [
            'Create Kingdom Award',
            'Create new kingdom award definitions',
            'kingdom', 'award'
        ],
        'kingdom.award.edit' => [
            'Edit Kingdom Award',
            'Edit existing kingdom award definitions',
            'kingdom', 'award'
        ],
        'kingdom.award.remove' => [
            'Remove Kingdom Award',
            'Remove kingdom award definitions',
            'kingdom', 'award'
        ],
        'kingdom.officer.set' => [
            'Set Kingdom Officer',
            'Appoint kingdom-level officers',
            'kingdom', 'officer'
        ],
        'kingdom.officer.position.manage' => [
            'Manage Kingdom Officer Positions',
            'Create, edit, classify, retire, and reinstate kingdom officer positions',
            'kingdom', 'officer'
        ],
        'kingdom.officer.vacate' => [
            'Vacate Kingdom Officer',
            'Remove kingdom-level officers from office',
            'kingdom', 'officer'
        ],
        'kingdom.officer_history.manage' => [
            'Manage Officer History',
            'Create, edit, and delete officer history records',
            'kingdom', 'officer'
        ],
        'kingdom.heraldry.manage' => [
            'Manage Kingdom Heraldry',
            'Upload and remove kingdom heraldry',
            'kingdom', 'heraldry'
        ],
        'kingdom.auth.manage' => [
            'Manage Kingdom Authorizations',
            'Add and remove kingdom-level authorizations',
            'kingdom', 'auth'
        ],

        // Role administration, split out of kingdom.auth.manage. Defining what a role
        // MEANS and handing that role to a person are different acts with different
        // blast radii, and a kingdom that wants to delegate the upkeep of legacy
        // authorization rows should not have to delegate the permission system with it.
        // Escalation prevention still applies on top of both (RBACService::CheckEscalation).
        'kingdom.role.manage' => [
            'Manage Roles',
            'Create, edit, and delete the kingdom\'s custom roles and their permission sets',
            'kingdom', 'auth'
        ],
        'kingdom.role.grant' => [
            'Assign Roles',
            'Grant and revoke roles for players at kingdom, park, event, or unit scope',
            'kingdom', 'auth'
        ],

        // ORK-ADMINISTRATOR ACTIONS, LISTED FOR VISIBILITY ONLY.
        // Park creation and cross-kingdom park transfer are reserved to the ORK team by
        // policy: Park::CreatePark / TransferPark / MergeParks gate on a true global
        // admin row and deliberately do NOT consult these keys. They stay in the registry
        // so the permissions grid can show a kingdom what those actions are and who
        // performs them; the consoles render the corresponding tiles disabled with an
        // explanatory tip for anyone who is not an ORK Administrator.
        'kingdom.park.create' => [
            'Create Parks (ORK Administrator)',
            'Create new parks within the kingdom — performed by the ORK team',
            'kingdom', 'config'
        ],
        'kingdom.park.retire' => [
            'Retire/Restore Parks',
            'Retire or restore parks within the kingdom',
            'kingdom', 'config'
        ],
        'kingdom.park.claim' => [
            'Claim/Transfer Parks (ORK Administrator)',
            'Claim or transfer parks between kingdoms — performed by the ORK team',
            'kingdom', 'config'
        ],
        'kingdom.banner.manage' => [
            'Manage Kingdom Banner',
            'Upload, configure, and remove the kingdom profile banner',
            'kingdom', 'heraldry'
        ],
        'kingdom.calendar.manage' => [
            'Manage Kingdom Calendar Items',
            'Create, edit, and delete kingdom calendar entries',
            'kingdom', 'event'
        ],

        // Qualification tests (Walker). Split three ways because the workflow has
        // three distinct audiences: an officer sets the rules, a subject-matter expert
        // writes the questions, and only an officer decides when a draft goes live.
        'kingdom.qualtest.config' => [
            'Configure Qualification Tests',
            'Set pass percent, question count, validity period, retakes and test managers',
            'kingdom', 'config'
        ],
        'kingdom.qualtest.questions.edit' => [
            'Edit Test Question Banks',
            'Author and edit Reeve/Corpora questions and draft question sets',
            'kingdom', 'config'
        ],
        'kingdom.qualtest.publish' => [
            'Publish Qualification Tests',
            'Publish a draft question set so players can take the test',
            'kingdom', 'config'
        ],
        'kingdom.qualtest.results.view' => [
            'View Test Results',
            'View Reeve/Corpora results, attempt detail and question statistics',
            'kingdom', 'player'
        ],

        // ========================================
        // Park-Scoped
        // ========================================
        // NOTE: there is deliberately no park.officer.position.manage. ork_officer_position
        // is a per-KINGDOM registry whose rows are shared by every park, and RetirePosition
        // vacates every holder of a position across every scope in the kingdom -- so a
        // park-scoped grant would let one park's officer strip officers from every other
        // park. PermissionKeyFor() resolves the whole 'position' family to the kingdom key
        // regardless of ParkId; occupancy (set/vacate/history) stays genuinely per-scope.
        'park.details.edit' => [
            'Edit Park Details',
            'Edit park name, description, and basic details',
            'park', 'config'
        ],
        'park.officer.set' => [
            'Set Park Officer',
            'Appoint park-level officers',
            'park', 'officer'
        ],
        'park.officer.vacate' => [
            'Vacate Park Officer',
            'Remove park-level officers from office',
            'park', 'officer'
        ],
        'park.officer_history.manage' => [
            'Manage Park Officer History',
            'Create, edit, and delete park officer history records',
            'park', 'officer'
        ],
        'park.heraldry.manage' => [
            'Manage Park Heraldry',
            'Upload and remove park heraldry',
            'park', 'heraldry'
        ],
        'park.auth.manage' => [
            'Manage Park Authorizations',
            'Add and remove park-level authorizations',
            'park', 'auth'
        ],
        'park.parkday.manage' => [
            'Manage Park Days',
            'Create, edit, and delete park day schedules',
            'park', 'config'
        ],
        'park.event.create' => [
            'Create Park Events',
            'Create events for the park',
            'park', 'event'
        ],
        'park.attendance.manage' => [
            'Manage Attendance',
            'Record, edit, and delete attendance entries',
            'park', 'event'
        ],
        'park.banner.manage' => [
            'Manage Park Banner',
            'Upload, configure, and remove the park profile banner',
            'park', 'heraldry'
        ],
        'park.calendar.manage' => [
            'Manage Park Calendar Items',
            'Create, edit, and delete park calendar entries',
            'park', 'event'
        ],
        'park.report.view' => [
            'View Park Reports',
            'Access park-level reports',
            'park', 'config'
        ],
        'park.dues.manage' => [
            'Manage Dues',
            'Record and manage player dues',
            'park', 'financial'
        ],
        'park.reconcile_credits' => [
            'Set Reconciled Credits',
            'Set reconciled credit amounts for players',
            'park', 'financial'
        ],
        // Dues rows are a player record; the ledger is the books. park.dues.manage never
        // reached Treasury's accounts or transactions, which left the Treasurer role with
        // no permission covering the thing a treasurer actually keeps.
        'park.treasury.manage' => [
            'Manage Treasury Accounts',
            'Open accounts and record or remove treasury transactions',
            'park', 'financial'
        ],

        // ========================================
        // Player-Scoped at park level
        // ========================================
        'player.create' => [
            'Create Player',
            'Create new player accounts',
            'park', 'player'
        ],
        'player.edit' => [
            'Edit Other Player Details',
            'Edit other players profile details',
            'park', 'player'
        ],
        'player.move' => [
            'Move Player Between Parks',
            'Transfer players between parks',
            'park', 'player'
        ],
        'player.suspend' => [
            'Set Player Suspension',
            'Suspend or unsuspend player accounts',
            'park', 'player'
        ],
        'player.waiver.manage' => [
            'Manage Waivers & Restrictions',
            'Manage player waivers and restrictions',
            'park', 'player'
        ],
        'player.qualification.edit' => [
            'Edit Reeve/Corpora Qualifications',
            'Edit player reeve and corpora qualification status',
            'park', 'player'
        ],
        'player.heraldry.manage' => [
            'Manage Other Player Heraldry/Image',
            'Upload and remove other players heraldry and images',
            'park', 'heraldry'
        ],
        'player.note.manage' => [
            'Manage Other Player Notes',
            'Create, edit, and delete notes on other players',
            'park', 'player'
        ],
        'player.award.manage' => [
            'Manage Player Awards',
            'Grant, edit, and remove player awards',
            'park', 'award'
        ],
        'player.recommendation.manage' => [
            'Manage Award Recommendations',
            'Manage award recommendations for players',
            'park', 'award'
        ],
        'player.active_status.set' => [
            'Set Player Active Status',
            'Set player active/inactive status',
            'park', 'player'
        ],

        // ========================================
        // Event-Scoped
        // ========================================
        // Split the same way the qualification-test keys are: writing an event and
        // publishing it are different acts with different audiences. Editing a draft is
        // routine staff work; flipping it to published puts it on a kingdom's calendar,
        // and setting the fees decides what people are charged.
        'event.edit' => [
            'Edit Event',
            'Edit event name, dates, and basic details',
            'event', 'event'
        ],
        'event.delete' => [
            'Delete Event',
            'Delete events',
            'event', 'event'
        ],
        'event.detail.manage' => [
            'Manage Event Details',
            'Manage event locations, descriptions, and details',
            'event', 'event'
        ],
        'event.heraldry.manage' => [
            'Manage Event Heraldry',
            'Upload and remove event heraldry',
            'event', 'heraldry'
        ],
        'event.attendance.manage' => [
            'Manage Event Attendance',
            'Record, edit, and delete event attendance',
            'event', 'event'
        ],
        'event.reconcile' => [
            'Reconcile Event Attendance',
            'Reconcile event attendance records',
            'event', 'event'
        ],
        'event.auth.manage' => [
            'Manage Event Authorizations',
            'Add and remove event-level authorizations',
            'event', 'auth'
        ],
        'event.rsvp.manage' => [
            'Manage RSVPs (admin)',
            'Manage event RSVPs on behalf of other players',
            'event', 'event'
        ],
        'event.publish' => [
            'Publish Event',
            'Move an event between draft and published, and cancel or restore it',
            'event', 'event'
        ],
        'event.fees.manage' => [
            'Manage Event Fees & Links',
            'Set event fees and registration links',
            'event', 'event'
        ],
        'event.schedule.manage' => [
            'Manage Event Schedule & Staff',
            'Build the event schedule and assign staff to schedule slots',
            'event', 'event'
        ],
        'event.banner.manage' => [
            'Manage Event Banner',
            'Upload, configure, and remove the event profile banner',
            'event', 'heraldry'
        ],

        // ========================================
        // Unit-Scoped
        // ========================================
        'unit.edit' => [
            'Edit Unit Details',
            'Edit unit name, description, and details',
            'unit', 'config'
        ],
        'unit.member.manage' => [
            'Manage Unit Members',
            'Add, remove, and manage unit members',
            'unit', 'player'
        ],
        'unit.heraldry.manage' => [
            'Manage Unit Heraldry',
            'Upload and remove unit heraldry',
            'unit', 'heraldry'
        ],
        'unit.convert' => [
            'Convert Unit Type',
            'Convert unit between company and household types',
            'unit', 'config'
        ],
        'unit.auth.manage' => [
            'Manage Unit Authorizations',
            'Add and remove unit-level authorizations',
            'unit', 'auth'
        ],
        'unit.lifecycle.manage' => [
            'Retire, Restore & Transfer Units',
            'Retire or restore a unit and transfer its ownership to another player',
            'unit', 'config'
        ],
        'unit.banner.manage' => [
            'Manage Unit Banner',
            'Upload, configure, and remove the unit profile banner',
            'unit', 'heraldry'
        ],

        // ========================================
        // Tournament
        // ========================================
        // tournament.create is separate from bracket.manage on purpose: creating a
        // tournament stamps it onto a kingdom, park, or event, so it is authorized
        // against that org unit BEFORE the row exists. bracket.manage authorizes
        // against the tournament's own recorded scope, which only works afterwards.
        'tournament.create' => [
            'Create Tournament',
            'Create a tournament under a kingdom, park, or event',
            'event', 'event'
        ],
        'tournament.bracket.manage' => [
            'Manage Tournament Brackets',
            'Create, edit, and manage tournament brackets',
            'event', 'event'
        ],
        'tournament.delete' => [
            'Delete Tournament',
            'Delete tournament records',
            'event', 'event'
        ],
    ];

    /**
     * Map from officer role display name (as stored in ork_officer.role)
     * to the RBAC system role name (as stored in ork_role.name).
     */
    private static $officerRoleMap = [
        'Monarch'        => 'monarch',
        'Regent'         => 'regent',
        'Prime Minister' => 'prime_minister',
        'Champion'       => 'champion',
        'GMR'            => 'gmr',
    ];

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Get the full permissions array.
     *
     * @return array  Keyed by permission key string
     */
    public static function GetAll()
    {
        return self::$permissions;
    }

    /**
     * Get a single permission definition by key.
     *
     * @param string $key  Permission key, e.g. 'kingdom.award.create'
     * @return array|null  [display_name, description, scope_type, category] or null
     */
    public static function Get($key)
    {
        return isset(self::$permissions[$key]) ? self::$permissions[$key] : null;
    }

    /**
     * Check if a permission key exists in the registry.
     *
     * @param string $key
     * @return bool
     */
    public static function Exists($key)
    {
        return isset(self::$permissions[$key]);
    }

    /**
     * Get the permission keys whose DECLARED scope type is exactly $scope_type.
     *
     * CAVEAT -- this is almost never the list a scope-filtered UI wants. A key's
     * scope_type is only its NARROWEST direct-check scope; RBACService's cascade lets a
     * grant at a broader scope satisfy it, so the set of permissions meaningfully
     * grantable at, say, kingdom scope is every key at kingdom scope AND every key
     * below it. OfficerAdminAjax::actionPermissions() built its role-permission grid
     * from GetPermissionsByScope('kingdom') for exactly this reason and offered 23 of
     * 79 keys; see the note there. Use GetAll() and group by scope for display.
     *
     * @deprecated Kept for the category/scope grouping metadata only. No caller should
     *             use it to decide what may be granted.
     *
     * @param string $scope_type  One of: 'kingdom', 'park', 'event', 'unit'
     * @return array  Array of permission key strings
     */
    public static function GetByScope($scope_type)
    {
        $result = [];
        foreach (self::$permissions as $key => $def) {
            if ($def[2] === $scope_type) {
                $result[] = $key;
            }
        }
        return $result;
    }

    /**
     * Get all permission keys for a given category.
     *
     * @param string $category  One of: 'config', 'award', 'officer', 'heraldry', 'auth', 'event', 'player', 'financial'
     * @return array  Array of permission key strings
     */
    public static function GetByCategory($category)
    {
        $result = [];
        foreach (self::$permissions as $key => $def) {
            if ($def[3] === $category) {
                $result[] = $key;
            }
        }
        return $result;
    }

    /**
     * Canonical key for an officer role, accepting EITHER form.
     *
     * ork_officer.role used to hold the display name ('Prime Minister'). The officer
     * position migration rewrites it to the canonical key ('prime_minister') for every
     * existing row. Single-word roles survived that rename by accident -- the column is
     * utf8mb4_unicode_ci, so 'monarch' still matches 'Monarch' in SQL -- but PHP string
     * comparison is case-sensitive and 'prime_minister' never equals 'Prime Minister'
     * in either language. Read sites must normalize through here rather than compare
     * against a literal, or they silently stop matching the officer they were written for.
     *
     * @param string $officer_role  Either form: 'Prime Minister' or 'prime_minister'
     * @return string|null  Canonical key, or null if this is not a known crown role
     */
    public static function CanonicalOfficerRole($officer_role)
    {
        $role = trim((string) $officer_role);
        if ($role === '') {
            return null;
        }
        if (isset(self::$officerRoleMap[$role])) {
            return self::$officerRoleMap[$role];
        }
        // Already canonical, or a case variant of one.
        $slug = strtolower(str_replace(' ', '_', $role));
        return in_array($slug, self::$officerRoleMap, true) ? $slug : null;
    }

    /**
     * Display label for an officer role, accepting EITHER form. The counterpart to
     * CanonicalOfficerRole() for anything user-facing: after the rename a raw
     * ork_officer.role renders as 'prime_minister' in the UI.
     *
     * @param string $officer_role  Either form
     * @return string  Display label; the input unchanged if it is not a known crown role
     */
    public static function OfficerRoleLabel($officer_role)
    {
        $canonical = self::CanonicalOfficerRole($officer_role);
        if ($canonical === null) {
            return (string) $officer_role;
        }
        $label = array_search($canonical, self::$officerRoleMap, true);
        return $label === false ? (string) $officer_role : $label;
    }

    /**
     * Both stored forms of a crown role, for a SQL IN() list that must match rows
     * written before AND after the officer position migration.
     *
     * @param array $officer_roles  Roles in either form
     * @return array  Distinct display names and canonical keys
     */
    public static function OfficerRoleVariants(array $officer_roles)
    {
        $out = [];
        foreach ($officer_roles as $role) {
            $canonical = self::CanonicalOfficerRole($role);
            if ($canonical === null) {
                $out[] = (string) $role;
                continue;
            }
            $out[] = $canonical;
            $label = array_search($canonical, self::$officerRoleMap, true);
            if ($label !== false) {
                $out[] = $label;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Map an officer role to the RBAC system role name. Accepts either stored form.
     *
     * @param string $officer_role  E.g. 'Monarch', 'Prime Minister', 'prime_minister'
     * @return string|null  RBAC role name (e.g. 'monarch', 'prime_minister') or null if not mapped
     */
    /** @deprecated Legacy fallback for position_id=0 officer rows; positions now bind via ork_officer_position.rbac_role_id. */
    public static function OfficerRoleToRbacRole($officer_role)
    {
        // The RBAC role name and the canonical officer key are the same vocabulary.
        return self::CanonicalOfficerRole($officer_role);
    }

    /**
     * Get the officer role map (officer display name => RBAC role name).
     *
     * @return array
     */
    /** @deprecated See OfficerRoleToRbacRole(). Do NOT register new officer roles here — they live in ork_officer_position. */
    public static function GetOfficerRoleMap()
    {
        return self::$officerRoleMap;
    }

    /**
     * Get total count of registered permissions.
     *
     * @return int
     */
    public static function Count()
    {
        return count(self::$permissions);
    }

    /**
     * Read the permission catalog as the database currently holds it.
     *
     * The single answer to "what does the database think the catalog is". The sync CLI,
     * RbacRegistryParityTest and the migration VERIFY blocks each used to spell this
     * query themselves, so the catalog had four readers and no owner.
     *
     * @return array  key => ['display_name' => , 'description' => , 'scope_type' => , 'category' => ]
     */
    public function GetDatabaseDefinitions()
    {
        global $DB;
        $rows = [];

        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT `key`, `display_name`, `description`, `scope_type`, `category` FROM '
            . DB_PREFIX . 'permission ORDER BY `key`'
        );
        if ($rs === false) {
            return $rows;
        }

        while ($rs->Next()) {
            $rows[$rs->key] = [
                'display_name' => (string) $rs->display_name,
                'description'  => (string) $rs->description,
                'scope_type'   => (string) $rs->scope_type,
                'category'     => (string) $rs->category,
            ];
        }

        return $rows;
    }

    /**
     * The permission keys the database holds, sorted.
     *
     * @return array
     */
    public function GetDatabaseKeys()
    {
        return array_keys($this->GetDatabaseDefinitions());
    }

    /**
     * Compare the in-code registry with the database catalog.
     *
     * `drifted` matters as much as the other two: a key present on both sides with a
     * stale scope_type still resolves, but HasPermission()'s cascade and global-scope
     * logic then run against the wrong scope, which a key-only diff reports as agreement.
     *
     * @return array  ['missing' => list, 'orphans' => list, 'drifted' => key => list of columns]
     */
    public function DiffAgainstDatabase()
    {
        $database = $this->GetDatabaseDefinitions();

        $registry_keys = array_keys(self::$permissions);
        sort($registry_keys);
        $database_keys = array_keys($database);
        sort($database_keys);

        $drifted = [];
        foreach (self::$permissions as $key => $def) {
            if (!isset($database[$key])) {
                continue;
            }

            list($display_name, $description, $scope_type, $category) = $def;
            $expected = [
                'display_name' => (string) $display_name,
                'description'  => (string) $description,
                'scope_type'   => (string) $scope_type,
                'category'     => (string) $category,
            ];

            $columns = [];
            foreach ($expected as $column => $value) {
                if ($database[$key][$column] !== $value) {
                    $columns[] = $column;
                }
            }
            if (count($columns) > 0) {
                $drifted[$key] = $columns;
            }
        }

        return [
            'missing' => array_values(array_diff($registry_keys, $database_keys)),
            'orphans' => array_values(array_diff($database_keys, $registry_keys)),
            'drifted' => $drifted,
        ];
    }

    /**
     * Sync the in-code permission registry to the ork_permission database table.
     * Uses INSERT ... ON DUPLICATE KEY UPDATE for idempotent upserts.
     *
     * Call this on deploy or when permissions change.
     *
     * One transaction for the whole catalog: it is 79-odd single-row upserts, and an
     * interrupted run used to leave ork_permission half-migrated with nothing to
     * distinguish that from a run that had not happened.
     *
     * Never deletes. A row in the table with no registry entry may belong to an
     * undeployed branch, and dropping it would silently revoke it from every role that
     * holds it; removals stay deliberate, in a migration that clears ork_role_permission
     * first. Use DiffAgainstDatabase()['orphans'] to see them.
     *
     * @return array  ['synced' => int count, 'errors' => array]
     */
    public function SyncToDatabase()
    {
        global $DB;
        $synced = 0;
        $errors = [];

        $DB->BeginTrans();

        foreach (self::$permissions as $key => $def) {
            list($display_name, $description, $scope_type, $category) = $def;

            $DB->Clear();
            $DB->perm_key = $key;
            $DB->display_name = $display_name;
            $DB->perm_desc = $description;
            $DB->scope_type = $scope_type;
            $DB->category = $category;
            $DB->upd_display_name = $display_name;
            $DB->upd_perm_desc = $description;
            $DB->upd_scope_type = $scope_type;
            $DB->upd_category = $category;
            $sql = "INSERT INTO " . DB_PREFIX . "permission (`key`, `display_name`, `description`, `scope_type`, `category`, `is_system`)
				VALUES (:perm_key, :display_name, :perm_desc, :scope_type, :category, 1)
				ON DUPLICATE KEY UPDATE
					`display_name` = :upd_display_name,
					`description` = :upd_perm_desc,
					`scope_type` = :upd_scope_type,
					`category` = :upd_category";

            // ExecuteChecked(), not Execute(): Execute() reports nothing, so a failed
            // statement was indistinguishable from a successful one and the rollback arm
            // below could never fire -- a half-migrated ork_permission still reported
            // "synced = 79, errors = []".
            if ($DB->ExecuteChecked($sql) === false) {
                $errors[] = "Failed to sync permission: " . $key;
            } else {
                $synced++;
            }
        }

        if (count($errors) > 0) {
            $DB->RollbackTrans();
            return [ 'synced' => 0, 'errors' => $errors ];
        }

        if ($DB->CommitTrans() === false) {
            return [ 'synced' => 0, 'errors' => [ 'Failed to commit the permission sync transaction.' ] ];
        }

        return [ 'synced' => $synced, 'errors' => $errors ];
    }
}
