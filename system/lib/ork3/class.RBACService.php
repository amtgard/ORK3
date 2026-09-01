<?php

/***
 * RBACService
 *
 * Core RBAC engine for ORK3. Provides permission checking with scope cascade,
 * role management (CRUD), grant/revoke with escalation prevention, audit logging,
 * and GhettoCache integration with generation-counter invalidation.
 *
 * Auto-loaded by startup.php as Ork3::$Lib->rbacservice
 *
 * Key methods:
 *   HasPermission()  — Check if a user has a specific permission at a scope
 *   GrantRole()      — Assign a role to a user at a scope (with escalation guard)
 *   RevokeRole()     — Remove a role assignment from a user
 *   CreateRole()     — Create a custom kingdom-level role
 *   EditRole()       — Edit a custom role's permissions
 *   DeleteRole()     — Delete a custom role
 *   sync_officer_role() — Dual-write: create/update ork_user_role for an officer change
 ***/

class RBACService extends Ork3
{
    private $cache;
    private $cache_ttl = 120; // seconds

    // Per-request memos for the admin/ban probes. Both run on EVERY HasPermission()
    // call, above the GhettoCache lookup (they have to -- the cache stores only the
    // direct+cascade answer, so an admin would read a cached `false`). Granting a
    // 55-permission role therefore fired 55 identical pairs of queries before this.
    private $adminMemo = [];
    private $bannedMemo = [];

    public function __construct()
    {
        parent::__construct();
        $this->cache = new Ghettocache();
    }

    // ================================================================
    // PERMISSION CHECKING
    // ================================================================

    /**
     * Check if a user has a specific permission at a given scope.
     *
     * Logic flow:
     *   1. Admin bypass (check ork_authorization for role='admin')
     *   2. Ban check (ork_mundane.penalty_box == 1 => false)
     *   3. Cache check (GhettoCache with generation counter)
     *   4. Direct query (ork_user_role JOIN ork_role_permission JOIN ork_permission)
     *   5. Scope cascade (park -> kingdom, event -> park -> kingdom, unit -> kingdom)
     *   6. Cache result
     *
     * @param int    $mundane_id     User ID
     * @param string $permission_key Permission key, e.g. 'kingdom.award.create'
     * @param string $scope_type     One of: 'global', 'kingdom', 'park', 'event', 'unit'
     * @param int    $scope_id       The ID of the scoped entity; ignored for 'global'
     * @return bool
     */
    public function HasPermission($mundane_id, $permission_key, $scope_type, $scope_id)
    {
        $mundane_id = (int) $mundane_id;
        $scope_id = (int) $scope_id;

        // 'global' is the one scope with no entity behind it: it addresses the
        // installation, so its assignment row carries all-zero scope columns and a
        // scope_id is meaningless. Every other scope still requires a real id.
        if ($scope_type === 'global') {
            $scope_id = 0;
        } elseif ($scope_id <= 0) {
            return false;
        }

        if ($mundane_id <= 0) {
            return false;
        }

        // Validate permission key exists in registry
        if (!PermissionRegistry::Exists($permission_key)) {
            logtrace('RBACService::HasPermission', 'Unknown permission key: ' . $permission_key);
            return false;
        }

        // 1. Admin bypass — check ork_authorization for role='admin'
        if ($this->IsAdmin($mundane_id)) {
            return true;
        }

        // 2. Ban check
        if ($this->IsBanned($mundane_id)) {
            return false;
        }

        // 3. Cache check
        $gen = $this->GetGenerationCounter($mundane_id);
        $cache_key = $this->BuildCacheKey($gen, $mundane_id, $permission_key, $scope_type, $scope_id);
        $cached = $this->cache->get('rbac', $cache_key, $this->cache_ttl);
        if ($cached !== false) {
            return (bool) $cached;
        }

        // 4. Direct query at the requested scope
        $has_perm = $this->CheckPermissionDirect($mundane_id, $permission_key, $scope_type, $scope_id);

        // 5. Scope cascade if no direct match
        if (!$has_perm) {
            $has_perm = $this->CheckPermissionCascade($mundane_id, $permission_key, $scope_type, $scope_id);
        }

        // 6. Cache result (store 1 or 0 since GhettoCache returns false for cache miss)
        $this->cache->cache('rbac', $cache_key, $has_perm ? 1 : 0);

        return $has_perm;
    }

    /**
     * Check for a permission directly at the given scope (no cascade).
     */
    private function CheckPermissionDirect($mundane_id, $permission_key, $scope_type, $scope_id)
    {
        global $DB;

        $scope_clause = $this->ScopeMatchClause($scope_type, $scope_id);
        if ($scope_clause === null) {
            return false;
        }

        $DB->Clear();
        $DB->perm_key = $permission_key;
        $sql = "SELECT 1
			FROM " . DB_PREFIX . "user_role ur
			JOIN " . DB_PREFIX . "role_permission rp ON rp.role_id = ur.role_id
			JOIN " . DB_PREFIX . "permission p ON p.permission_id = rp.permission_id
			WHERE ur.mundane_id = " . (int) $mundane_id . "
			  AND " . $scope_clause . "
			  AND p.`key` = :perm_key
			  AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
			LIMIT 1";

        $result = $DB->DataSet($sql);
        return ($result !== false && $result->size() > 0);
    }

    /**
     * SQL fragment matching the ork_user_role rows that are a grant AT exactly
     * ($scope_type, $scope_id) -- the single place the scope-resolution rule lives.
     *
     * An assignment row is read by its NARROWEST non-zero scope column, because that
     * is how the writers fill it in: sync_officer_role() stores a PARK officer as
     * (kingdom_id = K, park_id = P), where kingdom_id records which kingdom the park
     * belongs to, not the reach of the grant. Matching a kingdom-scope check on
     * `ur.kingdom_id = K` alone therefore handed every park officer in the kingdom
     * every kingdom.* permission -- the exact park-to-kingdom escalation the role model
     * exists to prevent, and one the legacy HasAuthority(AUTH_KINGDOM) never allowed.
     *
     * Narrowness order is unit_id, event_id, park_id, kingdom_id. Only a row whose sole
     * non-zero column is kingdom_id is a kingdom grant; only an all-zero row is global
     * (the same rule Authorization::HasAuthority uses for true global admin).
     * Broadening in the other direction is the cascade's job, not this clause's.
     *
     * @return string|null  NULL for an unknown scope type or a missing scope id.
     */
    private function ScopeMatchClause($scope_type, $scope_id)
    {
        $scope_id = (int) $scope_id;

        if ($scope_type === 'global') {
            return 'ur.kingdom_id = 0 AND ur.park_id = 0 AND ur.event_id = 0 AND ur.unit_id = 0';
        }

        if ($scope_id <= 0 || $this->ScopeTypeToColumn($scope_type) === null) {
            return null;
        }

        switch ($scope_type) {
            case 'kingdom':
                return 'ur.kingdom_id = ' . $scope_id
                    . ' AND ur.park_id = 0 AND ur.event_id = 0 AND ur.unit_id = 0';
            case 'park':
                return 'ur.park_id = ' . $scope_id . ' AND ur.event_id = 0 AND ur.unit_id = 0';
            case 'event':
                return 'ur.event_id = ' . $scope_id . ' AND ur.unit_id = 0';
            case 'unit':
                return 'ur.unit_id = ' . $scope_id;
        }

        return null;
    }

    /**
     * Which kingdom owns ($scope_type, $scope_id)?
     *
     * The one resolver for the scope hierarchy. CheckPermissionCascade(),
     * GranterPermissionKeysAtScope(), ScopeBelongsToKingdom() and RevokeRole() each
     * used to re-derive this with their own queries; ScopeBelongsToKingdom is the
     * check standing between a scoped grant and cross-kingdom escalation and
     * RevokeRole's copy gates who may revoke, so drift between them was a silent
     * security regression. They all read it from here now.
     *
     * @return int  0 when the scope has no resolvable kingdom.
     */
    private function ResolveOwningKingdomId($scope_type, $scope_id)
    {
        global $DB;

        $scope_id = (int) $scope_id;
        if ($scope_id <= 0) {
            return 0;
        }

        switch ($scope_type) {
            case 'kingdom':
                return $scope_id;

            case 'park':
                $park = new yapo($this->db, DB_PREFIX . 'park');
                $park->clear();
                $park->park_id = $scope_id;
                return ($park->find() && valid_id($park->kingdom_id)) ? (int) $park->kingdom_id : 0;

            case 'event':
                $event = new yapo($this->db, DB_PREFIX . 'event');
                $event->clear();
                $event->event_id = $scope_id;
                if (!$event->find()) {
                    return 0;
                }
                if (valid_id($event->kingdom_id)) {
                    return (int) $event->kingdom_id;
                }
                // An event may carry only a park; resolve that park's kingdom.
                return valid_id($event->park_id)
                    ? $this->ResolveOwningKingdomId('park', (int) $event->park_id)
                    : 0;

            case 'unit':
                // ork_unit has NEITHER park_id NOR kingdom_id -- a unit's scope is the set
                // of parks and kingdoms its ROSTER sits in, which is exactly what
                // Authorization::unit_roster_scopes() resolves for the KPM bypass. This
                // branch used to JOIN ork_park ON p.park_id = u.park_id: a column that does
                // not exist, so MariaDB raised "Unknown column 'u.park_id'", DataSet()
                // swallowed it (PDO ERRMODE_WARNING + YapoDb::handle_errors() returns true)
                // and every unit-scope resolution silently answered 0.
                //
                // Prefer the ACTIVE roster and fall back to everyone who was ever on it, so
                // an emptied unit does not drop out of every officer's reach -- the same
                // two-step unit_roster_scopes() uses. Most-common kingdom wins: a roster can
                // legitimately span kingdoms, and returning the modal one keeps a single
                // owning kingdom without inventing a tie-break that authorization would
                // then depend on.
                foreach ([" AND um.active = 'Active'", ''] as $activeClause) {
                    $DB->Clear();
                    $sql = "SELECT m.kingdom_id, COUNT(*) AS roster_count
						FROM " . DB_PREFIX . "unit_mundane um
						JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = um.mundane_id
						WHERE um.unit_id = " . $scope_id . $activeClause . "
						  AND m.kingdom_id > 0
						GROUP BY m.kingdom_id
						ORDER BY roster_count DESC, m.kingdom_id ASC
						LIMIT 1";
                    $result = $DB->DataSet($sql);
                    if ($result !== false && $result->size() > 0 && $result->Next() && valid_id($result->kingdom_id)) {
                        return (int) $result->kingdom_id;
                    }
                }
                return 0;
        }

        return 0;
    }

    /**
     * The kingdom itself followed by its parent_kingdom_id chain, nearest first.
     *
     * Principalities are sub-groups of their parent kingdom: parent-kingdom officers
     * hold the same authority over a principality (and its parks) as over the kingdom
     * itself, which Authorization::HasAuthority() has always honoured by walking
     * parent_kingdom_id. Role-based delegation has to walk the same chain or a parent
     * kingdom can delegate nothing into its principality. Guarded against a corrupt or
     * cyclic parent_kingdom_id (A->B->A, or a self-parent) with a visited set plus the
     * same hard depth cap of 10 HasAuthority uses.
     *
     * @return array  List of kingdom ids, [$kingdom_id, parent, grandparent, ...].
     */
    private function KingdomAncestry($kingdom_id)
    {
        $kingdom_id = (int) $kingdom_id;
        if ($kingdom_id <= 0) {
            return [];
        }

        $chain = [];
        $current = $kingdom_id;
        while ($current > 0 && !in_array($current, $chain, true) && count($chain) < 10) {
            $chain[] = $current;
            $kingdom = new yapo($this->db, DB_PREFIX . 'kingdom');
            $kingdom->clear();
            $kingdom->kingdom_id = $current;
            $current = ($kingdom->find() && valid_id($kingdom->parent_kingdom_id))
                ? (int) $kingdom->parent_kingdom_id
                : 0;
        }

        return $chain;
    }

    /**
     * Cascade permission check up the scope hierarchy:
     *   park -> kingdom
     *   event -> park -> kingdom
     *   unit -> kingdom
     *   kingdom -> parent kingdom (principality -> parent), repeated up the chain
     *
     * 'global' is deliberately NOT the top of this chain. A global role is an
     * installation-operator role, not a super-kingdom: its permissions (purge logs,
     * edit the shared award catalog) name actions that have no per-kingdom meaning,
     * and letting it satisfy every scoped check would silently recreate the
     * all-or-nothing admin row this scope exists to replace. Global permissions are
     * checked at global scope only; true global admins still short-circuit everything
     * through IsAdmin().
     */
    private function CheckPermissionCascade($mundane_id, $permission_key, $scope_type, $scope_id)
    {
        if ($scope_type === 'global') {
            return false;
        }

        // Event scope has one extra rung below the kingdom: its own park.
        if ($scope_type === 'event') {
            $event = new yapo($this->db, DB_PREFIX . 'event');
            $event->clear();
            $event->event_id = $scope_id;
            if ($event->find() && valid_id($event->park_id)
                && $this->CheckPermissionDirect($mundane_id, $permission_key, 'park', $event->park_id)) {
                return true;
            }
        }

        $owning_kingdom_id = $this->ResolveOwningKingdomId($scope_type, $scope_id);
        if ($owning_kingdom_id <= 0) {
            logtrace(
                'RBACService::CheckPermissionCascade',
                'No owning kingdom for scope ' . $scope_type . ' ' . (int) $scope_id
            );
            return false;
        }

        // The owning kingdom, then its parent chain: a parent-kingdom officer holds
        // authority over a principality and its parks, exactly as HasAuthority() has
        // always resolved it. A check already AT kingdom scope skips the first entry --
        // HasPermission() ran CheckPermissionDirect() on it before calling the cascade.
        $chain = $this->KingdomAncestry($owning_kingdom_id);
        if ($scope_type === 'kingdom') {
            array_shift($chain);
        }

        foreach ($chain as $kingdom_id) {
            if ($this->CheckPermissionDirect($mundane_id, $permission_key, 'kingdom', $kingdom_id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map scope type string to the ork_user_role column name.
     */
    private function ScopeTypeToColumn($scope_type)
    {
        switch ($scope_type) {
            case 'kingdom': return 'kingdom_id';
            case 'park':    return 'park_id';
            case 'event':   return 'event_id';
            case 'unit':    return 'unit_id';
            default:        return null;
        }
    }

    /**
     * Does ($scope_type, $scope_id) sit inside $kingdom_id?
     *
     * Grant/revoke authorize against a kingdom, then write a row at a scope the caller
     * names. This is the check that keeps those two facts in agreement. Resolution
     * mirrors CheckPermissionCascade -- same ResolveOwningKingdomId(), same parent
     * chain -- so a scope that would cascade UP to this kingdom is exactly the set of
     * scopes this kingdom may be granted at. The parent chain matters: a parent-kingdom
     * officer acting for the parent must be able to grant at a principality's kingdom,
     * park or event, which an exact kingdom match refused.
     *
     * A global scope belongs to no kingdom, so it is refused here by design: global
     * roles are assigned by an ORK administrator, through GrantRole()'s own
     * `$scope_type === 'global'` branch, not through a kingdom's role console.
     *
     * @param string $scope_type
     * @param int    $scope_id
     * @param int    $kingdom_id  The kingdom the actor was authorized against
     * @return bool
     */
    private function ScopeBelongsToKingdom($scope_type, $scope_id, $kingdom_id)
    {
        $scope_id = (int) $scope_id;
        $kingdom_id = (int) $kingdom_id;

        if ($kingdom_id <= 0 || $scope_id <= 0 || $scope_type === 'global') {
            return false;
        }

        $owning_kingdom_id = $this->ResolveOwningKingdomId($scope_type, $scope_id);
        if ($owning_kingdom_id <= 0) {
            return false;
        }

        return in_array($kingdom_id, $this->KingdomAncestry($owning_kingdom_id), true);
    }

    /**
     * Public reader for the same scope-belongs-to-kingdom rule the grant/revoke gate
     * uses. Callers outside this class (a console endpoint validating a scope the user
     * picked, for instance) MUST use this instead of re-deriving the rule: an exact
     * kingdom match plus a park lookup silently disagrees with the gate, because this
     * rule also accepts a scope reached through the parent_kingdom_id chain. Read-only,
     * no side effects, safe to expose.
     *
     * @param string $scope_type
     * @param int    $scope_id
     * @param int    $kingdom_id
     * @return bool
     */
    public function ScopeIsInKingdom($scope_type, $scope_id, $kingdom_id)
    {
        return $this->ScopeBelongsToKingdom($scope_type, $scope_id, $kingdom_id);
    }

    // ================================================================
    // ADMIN / BAN HELPERS
    // ================================================================

    /**
     * Check if a user is a TRUE global admin.
     *
     * Delegates rather than re-querying ork_authorization. The hand-rolled find()
     * this replaced accepted ANY row with role='admin', including a park- or
     * kingdom-scoped one -- Authorization::HasAuthority() only honours a grant whose
     * park_id/kingdom_id/unit_id/event_id are all zero, precisely because a scoped
     * admin row silently conferring system-wide authority is the bug that let
     * compromised park-officer accounts edit players in other kingdoms. This is the
     * global bypass for every RBAC check plus the escalation guards on
     * Grant/Create/Edit/DeleteRole, so it has to agree with that rule exactly.
     */
    private function IsAdmin($mundane_id)
    {
        $mundane_id = (int) $mundane_id;
        if (!isset($this->adminMemo[$mundane_id])) {
            $this->adminMemo[$mundane_id] =
                Ork3::$Lib->authorizationgate->check($mundane_id, AUTH_ADMIN, 0, AUTH_CREATE);
        }
        return $this->adminMemo[$mundane_id];
    }

    /**
     * Check if a user is banned (penalty_box == 1).
     */
    private function IsBanned($mundane_id)
    {
        $mundane_id = (int) $mundane_id;
        if (isset($this->bannedMemo[$mundane_id])) {
            return $this->bannedMemo[$mundane_id];
        }

        global $DB;
        $mundane = new yapo($DB, DB_PREFIX . 'mundane');
        $mundane->clear();
        $mundane->mundane_id = $mundane_id;
        $banned = $mundane->find() ? ($mundane->penalty_box == 1) : true; // unknown user => banned

        $this->bannedMemo[$mundane_id] = $banned;
        return $banned;
    }

    // ================================================================
    // CACHE MANAGEMENT (Generation Counter Pattern)
    // ================================================================

    /**
     * Get the RBAC generation counter for a user.
     * Used as part of cache keys so incrementing invalidates all cached permissions.
     */
    private function GetGenerationCounter($mundane_id)
    {
        $gen = $this->cache->get('rbac_gen', (string) $mundane_id, 3600);
        if ($gen === false) {
            $gen = 1;
            $this->cache->cache('rbac_gen', (string) $mundane_id, $gen);
        }
        return (int) $gen;
    }

    /**
     * Increment the generation counter for a user, invalidating all cached permissions.
     */
    private function IncrementGenerationCounter($mundane_id)
    {
        $gen = $this->GetGenerationCounter($mundane_id);
        $gen++;
        $this->cache->bust('rbac_gen', (string) $mundane_id);
        $this->cache->cache('rbac_gen', (string) $mundane_id, $gen);
    }

    /**
     * Public cache-invalidation hook for position-registry reconciliation
     * (OfficerPosition::ReconcileRoleBinding writes ork_user_role rows directly
     * and must bust the affected user's permission cache). Thin wrapper over the
     * private generation-counter bump.
     *
     * @param int $mundane_id
     */
    public function InvalidateUserCache($mundane_id)
    {
        $this->IncrementGenerationCounter((int) $mundane_id);
    }

    /**
     * Build a cache key for a permission check.
     */
    private function BuildCacheKey($gen, $mundane_id, $permission_key, $scope_type, $scope_id)
    {
        return $gen . '.' . $mundane_id . '.' . $permission_key . '.' . $scope_type . '.' . $scope_id;
    }

    // ================================================================
    // ROLE ASSIGNMENT (Grant / Revoke)
    // ================================================================

    /**
     * Write the danger-audit row for a token-gated mutation, but only when it actually
     * succeeded. Every entry point in this class carried this block inline; keeping the
     * Status check, the Token strip and the audit contract in ONE place stops the six
     * copies from drifting apart the next time the contract changes.
     *
     * __FUNCTION__ has to come from the CALLER -- evaluated here it would name this
     * helper, silently rewriting every audit row's method_call.
     *
     * @param array  $r          The response array the internal method returned.
     * @param string $method     Caller's __FUNCTION__.
     * @param array  $request    The inbound request; its Token is stripped before logging.
     * @param int    $entity_id  Kingdom the mutation is attributed to.
     * @param array|null $post   Optional post-state payload.
     */
    private function AuditIfSuccess($r, $method, $request, $entity_id, $post = null)
    {
        if (!is_array($r) || (int) ($r['Status'] ?? 1) !== 0) {
            return;
        }

        $safe = $request;
        unset($safe['Token']);
        Ork3::$Lib->dangeraudit->audit(
            __CLASS__ . '::' . $method,
            $safe,
            'Kingdom',
            (int) $entity_id,
            null,
            $post
        );
    }

    /**
     * Token-gated entry point. Grant a role to a user at a specific scope.
     * Resolves the acting user from the token -- the caller cannot name the
     * actor -- then delegates to grantRoleInternal() with the proven identity.
     *
     * @param array $request  Token, KingdomId (gate scope), MundaneId (target),
     *                        RoleId, ScopeType, ScopeId, ExpiresAt (optional)
     * @return array  Standard ORK response array
     */
    public function GrantRole($request)
    {
        if (($actor_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '')) == 0) {
            return NoAuthorization();
        }

        $kingdom_id = (int) ($request['KingdomId'] ?? 0);
        $scope_type = (string) ($request['ScopeType'] ?? 'kingdom');
        $scope_id = (int) ($request['ScopeId'] ?? $kingdom_id);

        if ($scope_type === 'global') {
            // A global assignment hands out installation-operator permissions, which no
            // kingdom's role console may do. Only a true all-zero-scope admin can, and
            // grantRoleInternal's escalation check still applies on top.
            if (!$this->IsAdmin($actor_id)) {
                return NoAuthorization(null, 'Only an ORK Administrator can assign an installation-wide role.');
            }
            $scope_id = 0;
        } else {
            if (!Ork3::$Lib->authorizationgate->checkPermissionOrAuthority(
                $actor_id,
                'kingdom.role.grant',
                'kingdom',
                $kingdom_id,
                AUTH_CREATE
            )) {
                return NoAuthorization();
            }

            // The gate above proved authority over $kingdom_id and nothing else. ScopeType /
            // ScopeId arrive from the caller, so without this the same request could name a
            // park in another kingdom: escalation prevention refuses that for any role that
            // carries permissions, but a role carrying NONE short-circuits CheckEscalation
            // and the row would be written anyway -- a cross-kingdom assignment nobody in
            // the affected kingdom authorized.
            if (!$this->ScopeBelongsToKingdom($scope_type, $scope_id, $kingdom_id)) {
                return NoAuthorization(null, 'That scope does not belong to the kingdom you are acting for.');
            }
        }

        $r = $this->grantRoleInternal(
            $actor_id,
            (int) ($request['MundaneId'] ?? 0),
            (int) ($request['RoleId'] ?? 0),
            $scope_type,
            $scope_id,
            $request['ExpiresAt'] ?? null
        );

        $this->AuditIfSuccess($r, __FUNCTION__, $request, $kingdom_id);

        return $r;
    }

    /**
     * Grant a role to a user at a specific scope.
     *
     * Includes escalation prevention: the granter must hold ALL permissions
     * that are in the target role, at >= the target scope.
     *
     * @param int    $granter_id    Who is granting
     * @param int    $target_id     Who receives the role
     * @param int    $role_id       The role to grant
     * @param string $scope_type    Scope type
     * @param int    $scope_id      Scope entity ID
     * @param string|null $expires_at  Optional expiration (MySQL datetime), or null for permanent
     * @return array  Standard ORK response array
     */
    private function grantRoleInternal($granter_id, $target_id, $role_id, $scope_type, $scope_id, $expires_at = null)
    {
        global $DB;

        $granter_id = (int) $granter_id;
        $target_id = (int) $target_id;
        $role_id = (int) $role_id;
        $scope_id = (int) $scope_id;

        if (!valid_id($granter_id) || !valid_id($target_id) || !valid_id($role_id)) {
            return InvalidParameter(null, 'Invalid IDs provided.');
        }

        // Load the role
        $role = new yapo($DB, DB_PREFIX . 'role');
        $role->clear();
        $role->role_id = $role_id;
        if (!$role->find()) {
            return InvalidParameter(null, 'Role not found.');
        }

        // A role id is not proof of ownership: the caller's gate proved authority over the
        // SCOPE, never over the ROLE. Without this, kingdom B's custom role could be
        // assigned at kingdom A's own scope whenever escalation prevention passes -- which
        // it does trivially for a role carrying no permissions. Mirrors
        // OfficerPosition::ValidateRoleBinding and the guard edit/deleteRoleInternal carry.
        // The ancestry chain, not an exact match: a parent kingdom's role granted into its
        // own principality is a delegation downward, exactly what ScopeBelongsToKingdom()
        // already allows on the scope side.
        if ((int) $role->kingdom_id !== 0 && !$this->IsAdmin($granter_id)) {
            $owning_kingdom_id = $this->ResolveOwningKingdomId($scope_type, $scope_id);
            if (
                $owning_kingdom_id <= 0
                || !in_array((int) $role->kingdom_id, $this->KingdomAncestry($owning_kingdom_id), true)
            ) {
                return NoAuthorization(null, 'That role belongs to another kingdom.');
            }
        }

        // Self-appointment guard for officer roles (BUG-2): block self-grant of ANY
        // role bound to a non-retired officer position (system OR kingdom-custom).
        if ($granter_id == $target_id) {
            $officer_bound = $this->RoleIsOfficerBound($role_id);
            // Only fall back to the hardcoded Core-Five check when the registry query
            // errored (null). A definitive false is trusted as-is, so a custom kingdom
            // role merely named like an officer is not wrongly blocked.
            if ($officer_bound === null) {
                $officer_roles = [ 'monarch', 'regent', 'prime_minister', 'champion', 'gmr' ];
                $officer_bound = ($role->is_system && in_array($role->name, $officer_roles));
            }
            if ($officer_bound) {
                return NoAuthorization(null, 'Cannot assign officer roles to yourself.');
            }
        }

        // Escalation prevention: granter must hold ALL permissions in the target role
        if (!$this->IsAdmin($granter_id)) {
            $missing = $this->CheckEscalation($granter_id, $role_id, $scope_type, $scope_id);
            if (count($missing) > 0) {
                return NoAuthorization(null, 'Escalation prevented: you lack permissions: ' . implode(', ', $missing));
            }
        }

        // Determine scope columns. 'global' has no column of its own: it is the row
        // with every scope column zero, so it passes this check by name rather than
        // through ScopeTypeToColumn().
        if ($scope_type !== 'global' && $this->ScopeTypeToColumn($scope_type) === null) {
            return InvalidParameter(null, 'Invalid scope type.');
        }

        // Insert user role assignment
        $kingdom_id = ($scope_type === 'kingdom') ? $scope_id : 0;
        $park_id = ($scope_type === 'park') ? $scope_id : 0;
        $event_id = ($scope_type === 'event') ? $scope_id : 0;
        $unit_id = ($scope_type === 'unit') ? $scope_id : 0;

        // Validate expires_at is not in the past
        if ($expires_at !== null) {
            $exp_time = strtotime($expires_at);
            if ($exp_time === false || $exp_time < time()) {
                return InvalidParameter(null, 'Expiration date must be in the future.');
            }
        }

        $DB->Clear();
        if ($expires_at !== null) {
            $DB->expires_at = $expires_at;
            $expires_sql = ':expires_at';
        } else {
            $expires_sql = 'NULL';
        }
        $sql = "INSERT IGNORE INTO " . DB_PREFIX . "user_role
			(mundane_id, role_id, kingdom_id, park_id, event_id, unit_id, granted_by, expires_at)
			VALUES (" . $target_id . ", " . $role_id . ", " . $kingdom_id . ", " . $park_id . ", " . $event_id . ", " . $unit_id . ", " . $granter_id . ", " . $expires_sql . ")";
        // ExecuteChecked(), not Execute(): Execute() reports nothing, so a failed INSERT
        // would fall straight through to the audit log and a green Success() toast while
        // the target never actually received the role.
        if ($DB->ExecuteChecked($sql) === false) {
            return ProcessingError(null, 'Could not grant the role. Please try again.');
        }

        // Invalidate target user's cache
        $this->IncrementGenerationCounter($target_id);

        // Audit log
        $this->AuditLog(
            $granter_id,
            'grant_role',
            $target_id,
            $role_id,
            null,
            $kingdom_id,
            $park_id,
            $event_id,
            $unit_id,
            'Granted role ' . $role->display_name . ' at ' . $scope_type . ':' . $scope_id
        );

        return Success();
    }

    /**
     * Token-gated entry point. Revoke a role from a user. Resolves the acting
     * user from the token, reads the target row's own scope (KingdomId in the
     * request is NOT trusted for the gate -- see the security note below), then
     * delegates to revokeRoleInternal() with the proven identity.
     *
     * @param array $request  Token, UserRoleId
     * @return array  Standard ORK response array
     */
    public function RevokeRole($request)
    {
        global $DB;

        if (($actor_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '')) == 0) {
            return NoAuthorization();
        }

        $user_role_id = (int) ($request['UserRoleId'] ?? 0);

        // Authorize against the ROW's own scope, not the caller's claim. Without
        // this, a caller holding kingdom.role.grant for their own kingdom could
        // pass the gate by naming that kingdom, then revoke any OTHER kingdom's
        // grant by id -- the row was only ever looked up by id, nothing tied the
        // authorized scope to the row being changed. Mirrors
        // OfficerPosition::EditHistoryTerm/DeleteHistoryTerm, same defect class.
        if ($user_role_id <= 0) {
            return InvalidParameter(null, 'That role assignment does not exist.');
        }

        $DB->Clear();
        $DB->rr_id = $user_role_id;
        $row = $DB->DataSet(
            'SELECT kingdom_id, park_id, event_id, unit_id FROM ' . DB_PREFIX . 'user_role
              WHERE user_role_id = :rr_id LIMIT 1'
        );
        if ($row === false || $row->size() === 0 || !$row->Next()) {
            // A missing row answers with the SAME generic denial an existing-but-forbidden
            // row gets. Distinguishing them let any authenticated caller enumerate which
            // UserRoleIds exist -- including installation-admin assignments -- purely from
            // response shape, before a single authorization check had run.
            return NoAuthorization();
        }

        $row_kingdom_id = (int) $row->kingdom_id;
        $row_park_id    = (int) $row->park_id;
        $row_event_id   = (int) $row->event_id;
        $row_unit_id    = (int) $row->unit_id;

        // Read the row by its NARROWEST non-zero scope column, the same rule
        // ScopeMatchClause() applies on the read side: an officer-synced park grant
        // carries both kingdom_id and park_id, and it is a PARK grant.
        if ($row_unit_id > 0) {
            $row_scope_type = 'unit';
            $row_scope_id = $row_unit_id;
        } elseif ($row_event_id > 0) {
            $row_scope_type = 'event';
            $row_scope_id = $row_event_id;
        } elseif ($row_park_id > 0) {
            $row_scope_type = 'park';
            $row_scope_id = $row_park_id;
        } elseif ($row_kingdom_id > 0) {
            $row_scope_type = 'kingdom';
            $row_scope_id = $row_kingdom_id;
        } else {
            // An all-zero row is a GLOBAL assignment by design -- that is exactly how
            // GrantRole()'s global branch writes it. Falling through to the kingdom
            // gate below made ork_admin unrevokable by anyone at all, since
            // checkPermissionOrAuthority()/HasAuthority() both refuse scope_id = 0.
            // Mirror the grant side: only a true all-zero-scope admin may undo it.
            if (!$this->IsAdmin($actor_id)) {
                return NoAuthorization(null, 'Only an ORK Administrator can revoke an installation-wide role.');
            }

            $r = $this->revokeRoleInternal($actor_id, $user_role_id);

            $this->AuditIfSuccess($r, __FUNCTION__, $request, 0);

            return $r;
        }

        // Resolve UP to the owning kingdom through the shared resolver, so
        // kingdom.role.grant stays a kingdom-level gate for every scope type and this
        // agrees with the cascade by construction. Gating on the row's raw kingdom_id
        // alone would be wrong in both directions: a park/event/unit row that happens to
        // carry kingdom_id = 0 would be unrevokable, and an officer-synced park row
        // (kingdom_id = K, park_id = P) would be gated as if it were a kingdom grant --
        // which is why the row's scope is read narrowest-first above.
        $owning_kingdom_id = $this->ResolveOwningKingdomId($row_scope_type, $row_scope_id);

        if ($owning_kingdom_id <= 0 || !Ork3::$Lib->authorizationgate->checkPermissionOrAuthority(
            $actor_id,
            'kingdom.role.grant',
            'kingdom',
            $owning_kingdom_id,
            AUTH_CREATE
        )) {
            return NoAuthorization();
        }

        $r = $this->revokeRoleInternal($actor_id, $user_role_id);

        $this->AuditIfSuccess($r, __FUNCTION__, $request, $owning_kingdom_id);

        return $r;
    }

    /**
     * Revoke a role from a user.
     *
     * @param int $revoker_id  Who is revoking
     * @param int $user_role_id  The ork_user_role.user_role_id to remove
     * @return array  Standard ORK response array
     */
    private function revokeRoleInternal($revoker_id, $user_role_id)
    {
        global $DB;

        $revoker_id = (int) $revoker_id;
        $user_role_id = (int) $user_role_id;

        if (!valid_id($revoker_id) || !valid_id($user_role_id)) {
            return InvalidParameter(null, 'Invalid IDs provided.');
        }

        // Load the user_role record
        $ur = new yapo($DB, DB_PREFIX . 'user_role');
        $ur->clear();
        $ur->user_role_id = $user_role_id;
        if (!$ur->find()) {
            return InvalidParameter(null, 'User role assignment not found.');
        }

        $target_id = $ur->mundane_id;
        $role_id = $ur->role_id;

        // Delete the assignment
        $ur->delete();

        // Invalidate target user's cache
        $this->IncrementGenerationCounter($target_id);

        // Audit log
        $this->AuditLog(
            $revoker_id,
            'revoke_role',
            $target_id,
            $role_id,
            null,
            $ur->kingdom_id,
            $ur->park_id,
            $ur->event_id,
            $ur->unit_id,
            'Revoked role assignment #' . $user_role_id
        );

        return Success();
    }

    /**
     * Public face of the escalation check, for callers that bind a role by id without
     * going through grantRoleInternal() -- notably OfficerPosition, which writes
     * officer_position.rbac_role_id and thereby hands every future holder of that office
     * the role's permissions. Binding is a deferred grant, so it must clear the same bar
     * a direct grant does; admins bypass it exactly as grantRoleInternal lets them.
     *
     * @return array  List of permission keys the actor is missing (empty = allowed)
     */
    public function MissingRolePermissions($actor_id, $role_id, $scope_type, $scope_id)
    {
        if ($this->IsAdmin((int) $actor_id)) {
            return [];
        }
        return $this->CheckEscalation((int) $actor_id, (int) $role_id, $scope_type, (int) $scope_id);
    }

    /**
     * Check which permissions in a role the granter lacks (escalation check).
     *
     * @return array  List of permission keys the granter is missing
     */
    private function CheckEscalation($granter_id, $role_id, $scope_type, $scope_id)
    {
        global $DB;
        $missing = [];

        // Get all permissions in the target role
        $DB->Clear();
        $sql = "SELECT p.`key`
			FROM " . DB_PREFIX . "role_permission rp
			JOIN " . DB_PREFIX . "permission p ON p.permission_id = rp.permission_id
			WHERE rp.role_id = " . (int) $role_id;
        $result = $DB->DataSet($sql);

        $target_keys = [];
        if ($result !== false && $result->size() > 0) {
            while ($result->Next()) {
                $target_keys[] = $result->key;
            }
        }
        if (count($target_keys) == 0) {
            return $missing;
        }

        // A banned granter holds nothing. HasPermission() refuses every key for them
        // before it looks at any assignment, but the aggregate fast path below filters
        // only on mundane_id, scope and expires_at -- so without this a banned granter's
        // keys were accepted without ever reaching the ban check, in the one guard whose
        // whole job is escalation prevention. Same ordering HasPermission uses: admins
        // never get here (both callers bypass on IsAdmin), bans deny outright.
        if ($this->IsBanned($granter_id)) {
            return $target_keys;
        }

        // Performance: instead of one HasPermission() (each ~1-2 DB hits) per target
        // key, fetch the set of permission keys the granter effectively holds at this
        // scope in a single query. We resolve the same scope chain HasPermission uses
        // (direct at scope, plus the park->kingdom / event->park->kingdom / unit->kingdom
        // cascade) so any key in this set is one HasPermission() would also grant.
        $granter_keys = $this->GranterPermissionKeysAtScope($granter_id, $scope_type, $scope_id);

        foreach ($target_keys as $perm_key) {
            if (isset($granter_keys[ $perm_key ])) {
                continue;
            }
            // Not in the aggregate set — fall back to the authoritative per-key check
            // to preserve exact semantics (admin bypass, ban, any cascade nuance).
            if (!$this->HasPermission($granter_id, $perm_key, $scope_type, $scope_id)) {
                $missing[] = $perm_key;
            }
        }

        return $missing;
    }

    /**
     * Collect the set of permission keys a user effectively holds across the given
     * scope and every scope it cascades to (mirroring CheckPermissionCascade:
     * park->kingdom, event->park->kingdom, unit->kingdom, kingdom->parent kingdom),
     * in a single query.
     *
     * @return array  Map of permission_key => true for fast isset() lookup.
     */
    private function GranterPermissionKeysAtScope($mundane_id, $scope_type, $scope_id)
    {
        $mundane_id = (int) $mundane_id;
        $scope_id = (int) $scope_id;

        // 'global' cascades to nothing; match it with its own clause and stop.
        if ($scope_type === 'global') {
            return $this->PermissionKeysMatching(
                $mundane_id,
                [ '(' . $this->ScopeMatchClause('global', 0) . ')' ]
            );
        }

        // Build the list of scopes to match against ur, replicating CheckPermissionCascade
        // exactly -- same ScopeMatchClause() narrowest-column rule, same event->park rung,
        // same ResolveOwningKingdomId() + parent chain. This is the escalation guard's
        // fast path, so any divergence from the cascade is a way to grant a permission the
        // granter does not actually hold (or to be refused one they do).
        $scopes = [ [ $scope_type, $scope_id ] ];

        if ($scope_type === 'event') {
            $event = new yapo($this->db, DB_PREFIX . 'event');
            $event->clear();
            $event->event_id = $scope_id;
            if ($event->find() && valid_id($event->park_id)) {
                $scopes[] = [ 'park', (int) $event->park_id ];
            }
        }

        $owning_kingdom_id = $this->ResolveOwningKingdomId($scope_type, $scope_id);
        if ($owning_kingdom_id > 0) {
            foreach ($this->KingdomAncestry($owning_kingdom_id) as $kingdom_id) {
                $scopes[] = [ 'kingdom', $kingdom_id ];
            }
        }

        $scope_clauses = [];
        foreach ($scopes as $scope) {
            $clause = $this->ScopeMatchClause($scope[0], $scope[1]);
            if ($clause !== null) {
                $scope_clauses[] = '(' . $clause . ')';
            }
        }

        return $this->PermissionKeysMatching($mundane_id, $scope_clauses);
    }

    /**
     * Run the aggregate "which permission keys does this user hold" query for a
     * pre-built set of scope clauses, OR-ed together. Shared by the scoped and global
     * paths of GranterPermissionKeysAtScope() so both spell the join once.
     *
     * @param int   $mundane_id
     * @param array $scope_clauses  Raw SQL fragments, already parenthesised where they
     *                              contain AND; callers build these from cast ints only.
     * @return array  Map of permission_key => true
     */
    private function PermissionKeysMatching($mundane_id, array $scope_clauses)
    {
        global $DB;

        if (count($scope_clauses) === 0) {
            return [];
        }

        $DB->Clear();
        $sql = "SELECT DISTINCT p.`key`
			FROM " . DB_PREFIX . "user_role ur
			JOIN " . DB_PREFIX . "role_permission rp ON rp.role_id = ur.role_id
			JOIN " . DB_PREFIX . "permission p ON p.permission_id = rp.permission_id
			WHERE ur.mundane_id = " . (int) $mundane_id . "
			  AND ( " . implode(' OR ', $scope_clauses) . " )
			  AND (ur.expires_at IS NULL OR ur.expires_at > NOW())";
        $result = $DB->DataSet($sql);

        $keys = [];
        if ($result !== false && $result->size() > 0) {
            while ($result->Next()) {
                if (PermissionRegistry::Exists($result->key)) {
                    $keys[ $result->key ] = true;
                }
            }
        }
        return $keys;
    }

    // ================================================================
    // ROLE MANAGEMENT (CRUD for Custom Roles)
    // ================================================================

    /**
     * Token-gated entry point. Create a custom role for a kingdom. Resolves the
     * acting user from the token, then delegates to create_role_internal() with the proven
     * identity.
     *
     * NOTE: create_role_internal() also has an internal caller --
     * OfficerPosition::createPositionInternal() -- which already holds a proven
     * actor_id from its own token-gated wrapper and calls create_role_internal() directly,
     * bypassing this token check. That is intentional: it is a domain-to-domain
     * call with an already-verified identity, not a second HTTP entry point.
     *
     * @param array $request  Token, KingdomId (owning kingdom + gate scope), Name,
     *                        DisplayName, Description, ScopeType, Permissions
     * @return array  Standard ORK response array (Detail = new role_id on success)
     */
    public function CreateRole($request)
    {
        if (($actor_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '')) == 0) {
            return NoAuthorization();
        }

        $kingdom_id = (int) ($request['KingdomId'] ?? 0);
        if (!Ork3::$Lib->authorizationgate->checkPermissionOrAuthority(
            $actor_id,
            'kingdom.role.manage',
            'kingdom',
            $kingdom_id,
            AUTH_CREATE
        )) {
            return NoAuthorization();
        }

        $permission_keys = $request['Permissions'] ?? [];
        $r = $this->create_role_internal(
            $actor_id,
            $kingdom_id,
            (string) ($request['Name'] ?? ''),
            (string) ($request['DisplayName'] ?? ''),
            (string) ($request['Description'] ?? ''),
            (string) ($request['ScopeType'] ?? 'kingdom'),
            is_array($permission_keys) ? $permission_keys : []
        );

        $this->AuditIfSuccess($r, __FUNCTION__, $request, $kingdom_id, ['RoleId' => (int) ($r['Detail'] ?? 0)]);

        return $r;
    }

    /**
     * Create a custom role for a kingdom.
     *
     * @param int    $creator_id    Who is creating the role
     * @param int    $kingdom_id    Kingdom that owns the role
     * @param string $name          Role machine name (lowercase, underscores)
     * @param string $display_name  Human-readable name
     * @param string $description   Description
     * @param string $scope_type    Scope type for the role
     * @param array  $permission_keys  Array of permission key strings
     * @return array  Standard ORK response array (Detail = new role_id on success)
     */
    public function create_role_internal($creator_id, $kingdom_id, $name, $display_name, $description, $scope_type, $permission_keys = [])
    {
        global $DB;

        $creator_id = (int) $creator_id;
        $kingdom_id = (int) $kingdom_id;

        if (!valid_id($creator_id) || !valid_id($kingdom_id)) {
            return InvalidParameter(null, 'Invalid creator or kingdom ID.');
        }

        // Validate permission keys
        $invalid_keys = [];
        foreach ($permission_keys as $key) {
            if (!PermissionRegistry::Exists($key)) {
                $invalid_keys[] = $key;
            }
        }
        if (count($invalid_keys) > 0) {
            return InvalidParameter(null, 'Invalid permission keys: ' . implode(', ', $invalid_keys));
        }

        // Escalation check: creator must hold every permission they're adding
        if (!$this->IsAdmin($creator_id)) {
            $missing = [];
            foreach ($permission_keys as $key) {
                if (!$this->HasPermission($creator_id, $key, 'kingdom', $kingdom_id)) {
                    $missing[] = $key;
                }
            }
            if (count($missing) > 0) {
                return NoAuthorization(null, 'Cannot create role with permissions you lack: ' . implode(', ', $missing));
            }
        }

        // Insert the role
        $DB->Clear();
        $DB->role_name = trim($name);
        $DB->display_name = trim($display_name);
        $DB->role_desc = trim($description);
        $DB->scope_type = $scope_type;
        $sql = "INSERT INTO " . DB_PREFIX . "role (`name`, `display_name`, `description`, `scope_type`, `is_system`, `kingdom_id`, `created_by`)
			VALUES (:role_name, :display_name, :role_desc, :scope_type, 0, " . $kingdom_id . ", " . $creator_id . ")";
        $DB->Execute($sql);

        // Prefer the driver's last-insert-id accessor over a race-prone SELECT-after-INSERT.
        $new_role_id = (int) $DB->GetLastInsertId();
        if ($new_role_id <= 0) {
            // Fallback: recover by name + kingdom (custom role names are unique per kingdom).
            $DB->Clear();
            $DB->role_name = trim($name);
            $sql = "SELECT role_id FROM " . DB_PREFIX . "role WHERE `name` = :role_name AND kingdom_id = " . $kingdom_id;
            $result = $DB->DataSet($sql);
            if ($result === false || $result->size() == 0 || !$result->Next()) {
                return ProcessingError(null, 'Failed to create role.');
            }
            $new_role_id = (int) $result->role_id;
        }

        // Map permissions to the role
        foreach ($permission_keys as $key) {
            $DB->Clear();
            $DB->perm_key = $key;
            $sql = "INSERT IGNORE INTO " . DB_PREFIX . "role_permission (role_id, permission_id)
				SELECT " . (int) $new_role_id . ", permission_id
				FROM " . DB_PREFIX . "permission
				WHERE `key` = :perm_key";
            $DB->Execute($sql);
        }

        // Audit log
        $this->AuditLog(
            $creator_id,
            'create_role',
            null,
            $new_role_id,
            null,
            $kingdom_id,
            0,
            0,
            0,
            'Created custom role: ' . $display_name . ' with ' . count($permission_keys) . ' permissions'
        );

        return Success($new_role_id);
    }

    /**
     * Token-gated entry point. Edit a custom role's permissions. Resolves the
     * acting user from the token, then delegates to edit_role_internal() with the proven
     * identity.
     *
     * NOTE: edit_role_internal() also has an internal caller --
     * OfficerPosition::editPositionInternal() -- which already holds a proven
     * actor_id from its own token-gated wrapper and calls edit_role_internal() directly,
     * bypassing this token check. That is intentional: it is a domain-to-domain
     * call with an already-verified identity, not a second HTTP entry point.
     *
     * @param array $request  Token, KingdomId (gate scope + acting kingdom), RoleId,
     *                        Permissions, DisplayName (optional), Description (optional)
     * @return array  Standard ORK response
     */
    public function EditRole($request)
    {
        if (($actor_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '')) == 0) {
            return NoAuthorization();
        }

        $kingdom_id = (int) ($request['KingdomId'] ?? 0);
        if (!Ork3::$Lib->authorizationgate->checkPermissionOrAuthority(
            $actor_id,
            'kingdom.role.manage',
            'kingdom',
            $kingdom_id,
            AUTH_CREATE
        )) {
            return NoAuthorization();
        }

        $permission_keys = $request['Permissions'] ?? [];
        $r = $this->edit_role_internal(
            $actor_id,
            (int) ($request['RoleId'] ?? 0),
            is_array($permission_keys) ? $permission_keys : [],
            array_key_exists('DisplayName', $request) ? $request['DisplayName'] : null,
            array_key_exists('Description', $request) ? $request['Description'] : null,
            $kingdom_id
        );

        $this->AuditIfSuccess($r, __FUNCTION__, $request, $kingdom_id);

        return $r;
    }

    /**
     * Edit a custom role's permissions.
     * System roles (is_system=1) cannot be edited.
     *
     * @param int   $editor_id        Who is editing
     * @param int   $role_id          Role to edit
     * @param array $permission_keys  New complete set of permission key strings
     * @param string|null $display_name  Optional new display name
     * @param string|null $description   Optional new description
     * @return array  Standard ORK response
     */
    /**
     * @param int $acting_kingdom_id  Kingdom the CALLER was authorized for. The role must
     *                                belong to it. 0 skips the check (admin/CLI callers only).
     */
    public function edit_role_internal($editor_id, $role_id, $permission_keys, $display_name = null, $description = null, $acting_kingdom_id = 0)
    {
        global $DB;

        $editor_id = (int) $editor_id;
        $role_id = (int) $role_id;
        $acting_kingdom_id = (int) $acting_kingdom_id;

        // Load the role
        $role = new yapo($DB, DB_PREFIX . 'role');
        $role->clear();
        $role->role_id = $role_id;
        if (!$role->find()) {
            return InvalidParameter(null, 'Role not found.');
        }

        // Cannot edit system roles
        if ($role->is_system) {
            return NoAuthorization(null, 'System roles cannot be edited.');
        }

        // The role must belong to the kingdom the caller was authorized for. Without this
        // a kingdom's admin could edit ANOTHER kingdom's custom role: the escalation check
        // below only iterates $permission_keys, so an EMPTY array skips it entirely and the
        // unconditional "DELETE FROM role_permission" further down still runs -- stripping a
        // foreign role bare, and renaming it, with no permission of any kind in that kingdom.
        if ($acting_kingdom_id > 0 && (int) $role->kingdom_id !== $acting_kingdom_id && !$this->IsAdmin($editor_id)) {
            return NoAuthorization(null, 'That role belongs to another kingdom.');
        }

        // Validate permission keys
        foreach ($permission_keys as $key) {
            if (!PermissionRegistry::Exists($key)) {
                return InvalidParameter(null, 'Invalid permission key: ' . $key);
            }
        }

        // Escalation check
        if (!$this->IsAdmin($editor_id)) {
            $missing = [];
            foreach ($permission_keys as $key) {
                if (!$this->HasPermission($editor_id, $key, 'kingdom', $role->kingdom_id)) {
                    $missing[] = $key;
                }
            }
            if (count($missing) > 0) {
                return NoAuthorization(null, 'Cannot add permissions you lack: ' . implode(', ', $missing));
            }
        }

        // Update display_name / description if provided
        if ($display_name !== null) {
            $role->display_name = $display_name;
        }
        if ($description !== null) {
            $role->description = $description;
        }
        $role->save();

        // Replace permissions: delete all current, then batch-insert the new set.
        $DB->Clear();
        $DB->Execute("DELETE FROM " . DB_PREFIX . "role_permission WHERE role_id = " . $role_id);

        if (count($permission_keys) > 0) {
            // Resolve all permission_ids in one query (unknown keys are simply absent,
            // preserving the prior "ignore unknown keys" semantics). Bind each key as a
            // named parameter, matching the bound-param style used elsewhere.
            $DB->Clear();
            $placeholders = [];
            $i = 0;
            foreach ($permission_keys as $key) {
                $ph = 'pk' . $i;
                $DB->$ph = $key;
                $placeholders[] = ':' . $ph;
                $i++;
            }
            $pr = $DB->DataSet("SELECT permission_id FROM " . DB_PREFIX . "permission WHERE `key` IN (" . implode(',', $placeholders) . ")");

            $value_rows = [];
            if ($pr !== false && $pr->size() > 0) {
                while ($pr->Next()) {
                    $value_rows[] = "(" . $role_id . ", " . (int) $pr->permission_id . ")";
                }
            }

            if (count($value_rows) > 0) {
                $DB->Clear();
                $DB->Execute("INSERT IGNORE INTO " . DB_PREFIX . "role_permission (role_id, permission_id) VALUES " . implode(',', $value_rows));
            }
        }

        // Invalidate cache for all users with this role
        $this->InvalidateCacheForRole($role_id);

        // Audit log
        $this->AuditLog(
            $editor_id,
            'edit_role',
            null,
            $role_id,
            null,
            $role->kingdom_id,
            0,
            0,
            0,
            'Edited custom role: ' . $role->display_name . ' — now has ' . count($permission_keys) . ' permissions'
        );

        return Success();
    }

    /**
     * Token-gated entry point. Delete a custom role. Resolves the acting user
     * from the token, then delegates to deleteRoleInternal() with the proven identity.
     *
     * @param array $request  Token, KingdomId (gate scope + acting kingdom), RoleId
     * @return array  Standard ORK response
     */
    public function DeleteRole($request)
    {
        if (($actor_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '')) == 0) {
            return NoAuthorization();
        }

        $kingdom_id = (int) ($request['KingdomId'] ?? 0);
        if (!Ork3::$Lib->authorizationgate->checkPermissionOrAuthority(
            $actor_id,
            'kingdom.role.manage',
            'kingdom',
            $kingdom_id,
            AUTH_CREATE
        )) {
            return NoAuthorization();
        }

        $r = $this->deleteRoleInternal($actor_id, (int) ($request['RoleId'] ?? 0), $kingdom_id);

        $this->AuditIfSuccess($r, __FUNCTION__, $request, $kingdom_id);

        return $r;
    }

    /**
     * Delete a custom role. System roles cannot be deleted.
     *
     * @param int $deleter_id  Who is deleting
     * @param int $role_id     Role to delete
     * @return array  Standard ORK response
     */
    private function deleteRoleInternal($deleter_id, $role_id, $acting_kingdom_id = 0)
    {
        global $DB;

        $deleter_id = (int) $deleter_id;
        $role_id = (int) $role_id;

        $role = new yapo($DB, DB_PREFIX . 'role');
        $role->clear();
        $role->role_id = $role_id;
        if (!$role->find()) {
            return InvalidParameter(null, 'Role not found.');
        }

        if ($role->is_system) {
            return NoAuthorization(null, 'System roles cannot be deleted.');
        }

        // Same cross-kingdom guard as EditRole: a role id alone is not proof of ownership.
        if ((int) $acting_kingdom_id > 0 && (int) $role->kingdom_id !== (int) $acting_kingdom_id && !$this->IsAdmin($deleter_id)) {
            return NoAuthorization(null, 'That role belongs to another kingdom.');
        }

        // ork_officer_position.rbac_role_id has no FK, so deleting a bound role leaves the
        // binding dangling: the next appointment writes an ork_user_role row pointing at a
        // role that no longer exists, silently disarming the office for every future
        // holder. Fail closed on null (a failed query) exactly as the self-grant guard does.
        $officer_bound = $this->RoleIsOfficerBound($role_id);
        if ($officer_bound !== false) {
            return NoAuthorization(
                null,
                'That role is assigned to an officer position. Unbind it from the position before deleting it.'
            );
        }

        // Invalidate cache for all users with this role before deleting
        $this->InvalidateCacheForRole($role_id);

        $kingdom_id = $role->kingdom_id;
        $display_name = $role->display_name;

        // Delete role-permission mappings
        $DB->Clear();
        $DB->Execute("DELETE FROM " . DB_PREFIX . "role_permission WHERE role_id = " . $role_id);

        // How many holders are about to lose this role. Read BEFORE the DELETE so the
        // audit trail (and the caller) records the real number -- a park-scoped grant is
        // invisible to the kingdom-scoped badge on the roles page, so the UI can claim
        // "0 users" while this DELETE strips a dozen park officers.
        $assignment_count = $this->GetRoleUserCount($role_id);

        // Delete user-role assignments
        $DB->Clear();
        $DB->Execute("DELETE FROM " . DB_PREFIX . "user_role WHERE role_id = " . $role_id);

        // Delete the role itself
        $role->delete();

        // Audit log
        $this->AuditLog(
            $deleter_id,
            'delete_role',
            null,
            $role_id,
            null,
            $kingdom_id,
            0,
            0,
            0,
            'Deleted custom role: ' . $display_name . ' (' . $assignment_count . ' assignment(s) removed)'
        );

        return Success('Deleted custom role: ' . $display_name . ' (' . $assignment_count . ' assignment(s) removed)');
    }

    // ================================================================
    // OFFICER DUAL-WRITE
    // ================================================================

    /**
     * LEGACY fallback path. Sync an officer change to ork_user_role by ROLE NAME.
     *
     * Called from Common::set_officer() only when no position_id is available. The
     * current path is sync_officer_role_by_position_id(), which resolves the RBAC role from
     * ork_officer_position.rbac_role_id instead of from a role string; this one remains
     * for callers that still pass position_id = 0.
     *
     * When a new officer is set:
     *   1. Remove old officer's RBAC role assignment for this position+scope
     *   2. Create new officer's RBAC role assignment (if new_officer_id > 0)
     *
     * @param int    $kingdom_id      Kingdom ID
     * @param int    $park_id         Park ID (0 for kingdom-level)
     * @param int    $old_officer_id  Previous officer mundane_id (0 if none)
     * @param int    $new_officer_id  New officer mundane_id (0 if vacating)
     * @param string $role            Officer role display name ('Monarch', 'Regent', etc.)
     * @param int    $changed_by      Who made the change (0 = system)
     */
    /**
     * @deprecated Legacy string-map sync. Used ONLY as a fallback for un-migrated
     * ork_officer rows with position_id = 0. New code path is sync_officer_role_by_position_id().
     * TODO: remove once all ork_officer rows have position_id > 0.
     */
    public function sync_officer_role($kingdom_id, $park_id, $old_officer_id, $new_officer_id, $role, $changed_by = 0)
    {
        global $DB;

        $kingdom_id = (int) $kingdom_id;
        $park_id = (int) $park_id;
        $old_officer_id = (int) $old_officer_id;
        $new_officer_id = (int) $new_officer_id;
        $changed_by = (int) $changed_by;

        // Map officer role to RBAC role name
        $rbac_role_name = PermissionRegistry::OfficerRoleToRbacRole($role);
        if ($rbac_role_name === null) {
            logtrace('RBACService::sync_officer_role', 'Unknown officer role: ' . $role);
            return;
        }

        // Look up the system role_id
        $DB->Clear();
        $DB->rbac_role_name = $rbac_role_name;
        $sql = "SELECT role_id FROM " . DB_PREFIX . "role
			WHERE `name` = :rbac_role_name
			  AND kingdom_id = 0 AND is_system = 1
			LIMIT 1";
        $result = $DB->DataSet($sql);
        if ($result === false || $result->size() == 0 || !$result->Next()) {
            logtrace('RBACService::sync_officer_role', 'System role not found for: ' . $rbac_role_name);
            return;
        }
        $rbac_role_id = (int) $result->role_id;

        // Remove old officer's role assignment for this position+scope
        if ($old_officer_id > 0) {
            $DB->Clear();
            $ok = $DB->ExecuteChecked(
                "DELETE FROM " . DB_PREFIX . "user_role
				 WHERE mundane_id = " . $old_officer_id . "
				   AND role_id = " . $rbac_role_id . "
				   AND kingdom_id = " . $kingdom_id . "
				   AND park_id = " . $park_id
            );
            if ($ok === false) {
                logtrace('RBACService::sync_officer_role', 'ERROR: revoke failed for mundane:' . $old_officer_id . ' role:' . $rbac_role_id . ' kingdom:' . $kingdom_id . ' park:' . $park_id . ' -- ork_officer and ork_user_role are now out of sync');
            }
            $this->IncrementGenerationCounter($old_officer_id);
        }

        // Create new officer's role assignment (skip if vacating, i.e. new_officer_id = 0)
        if ($new_officer_id > 0) {
            $granted_by_sql = ($changed_by > 0) ? $changed_by : 'NULL';
            $DB->Clear();
            $ok = $DB->ExecuteChecked(
                "INSERT IGNORE INTO " . DB_PREFIX . "user_role
				 (mundane_id, role_id, kingdom_id, park_id, event_id, unit_id, granted_by, expires_at)
				 VALUES (" . $new_officer_id . ", " . $rbac_role_id . ", " . $kingdom_id . ", " . $park_id . ", 0, 0, " . $granted_by_sql . ", NULL)"
            );
            if ($ok === false) {
                logtrace('RBACService::sync_officer_role', 'ERROR: grant failed for mundane:' . $new_officer_id . ' role:' . $rbac_role_id . ' kingdom:' . $kingdom_id . ' park:' . $park_id . ' -- ork_officer and ork_user_role are now out of sync');
            }
            $this->IncrementGenerationCounter($new_officer_id);
        }
    }

    /**
     * BUG-2 helper: is this role_id bound as the rbac_role_id of any non-retired
     * officer position (system or kingdom-custom)?
     *
     * @param int $role_id
     * @return bool|null  true/false on a definitive answer, null if the query failed
     */
    public function RoleIsOfficerBound($role_id)
    {
        global $DB;
        $DB->Clear();
        $DB->rb_role_id = (int) $role_id;
        $r = $DB->DataSet("SELECT 1 AS bound FROM " . DB_PREFIX . "officer_position WHERE rbac_role_id = :rb_role_id AND retired_at IS NULL LIMIT 1");
        // Return null on query failure so callers can distinguish a DB error from a
        // definitive "not officer-bound" answer (BUG-2 self-appointment guard).
        // DataSet() ALWAYS returns a YapoResultSet -- it never returns false -- so a
        // failed statement would otherwise arrive here as size() === 0, i.e. "not
        // bound", failing the guard OPEN. The real signal is the statement's own
        // SQLSTATE, recorded on the result set at construction.
        if ($r === false) {
            return null;
        }
        $sqlstate = isset($r->__ERROR[0][1]) ? $r->__ERROR[0][1] : null;
        if ($sqlstate !== null && $sqlstate !== '00000' && $sqlstate !== '') {
            return null;
        }
        return ($r->size() > 0);
    }

    /**
     * Sync officer RBAC role using the position registry (no string map).
     * Reads ork_officer_position.rbac_role_id directly, revokes from outgoing,
     * grants to incoming. On vacate (new=0) only revokes.
     *
     * @param int $old_officer_mundane_id
     * @param int $new_officer_mundane_id
     * @param int $position_id
     * @param int $kingdom_id
     * @param int $park_id
     * @param int $changed_by
     */
    public function sync_officer_role_by_position_id($old_officer_mundane_id, $new_officer_mundane_id, $position_id, $kingdom_id, $park_id, $changed_by)
    {
        global $DB;
        $old_officer_mundane_id = (int) $old_officer_mundane_id;
        $new_officer_mundane_id = (int) $new_officer_mundane_id;
        $position_id = (int) $position_id;
        $kingdom_id  = (int) $kingdom_id;
        $park_id     = (int) $park_id;
        $changed_by  = (int) $changed_by;

        if ($position_id <= 0) {
            return;
        }

        $DB->Clear();
        $DB->pid = $position_id;
        $pr = $DB->DataSet("SELECT rbac_role_id FROM " . DB_PREFIX . "officer_position WHERE position_id = :pid LIMIT 1");
        if ($pr === false || $pr->size() == 0 || !$pr->Next()) {
            return;
        }
        $rbac_role_id = (int) $pr->rbac_role_id;
        if ($rbac_role_id <= 0) {
            return;
        }

        if ($old_officer_mundane_id > 0) {
            $DB->Clear();
            $ok = $DB->ExecuteChecked(
                "DELETE FROM " . DB_PREFIX . "user_role
				 WHERE mundane_id = " . $old_officer_mundane_id . "
				   AND role_id = " . $rbac_role_id . "
				   AND kingdom_id = " . $kingdom_id . "
				   AND park_id = " . $park_id
            );
            if ($ok === false) {
                logtrace('RBACService::sync_officer_role_by_position_id', 'ERROR: revoke failed for mundane:' . $old_officer_mundane_id . ' role:' . $rbac_role_id . ' position:' . $position_id . ' -- ork_officer and ork_user_role are now out of sync');
            }
            $this->IncrementGenerationCounter($old_officer_mundane_id);
        }

        if ($new_officer_mundane_id > 0) {
            $granted_by_sql = ($changed_by > 0) ? $changed_by : 'NULL';
            $DB->Clear();
            $ok = $DB->ExecuteChecked(
                "INSERT IGNORE INTO " . DB_PREFIX . "user_role
				 (mundane_id, role_id, kingdom_id, park_id, event_id, unit_id, granted_by, expires_at)
				 VALUES (" . $new_officer_mundane_id . ", " . $rbac_role_id . ", " . $kingdom_id . ", " . $park_id . ", 0, 0, " . $granted_by_sql . ", NULL)"
            );
            if ($ok === false) {
                logtrace('RBACService::sync_officer_role_by_position_id', 'ERROR: grant failed for mundane:' . $new_officer_mundane_id . ' role:' . $rbac_role_id . ' position:' . $position_id . ' -- ork_officer and ork_user_role are now out of sync');
            }
            $this->IncrementGenerationCounter($new_officer_mundane_id);
        }
    }

    /**
     * Create RBAC role assignments for newly created officer slots.
     * Called from Common::create_officer() — since new officers start with mundane_id=0,
     * this is a no-op but exists for completeness and future use.
     *
     * @param int    $kingdom_id  Kingdom ID
     * @param int    $park_id     Park ID (0 for kingdom-level)
     * @param string $role        Officer role display name
     */
    public function sync_new_officer_slot($kingdom_id, $park_id, $role, $position_id = 0)
    {
        // New officer slots are created with mundane_id = 0, so there's nothing
        // to write to ork_user_role. When an actual officer is appointed via
        // set_officer(), sync_officer_role() will handle the RBAC assignment.
        logtrace('RBACService::sync_new_officer_slot', 'Slot created for ' . $role . ' at kingdom:' . $kingdom_id . ' park:' . $park_id . ' position:' . (int) $position_id);
    }

    // ================================================================
    // ROLE QUERY HELPERS
    // ================================================================

    /**
     * Get all roles assigned to a user (optionally filtered by scope).
     *
     * @param int         $mundane_id
     * @param string|null $scope_type   Optional filter
     * @param int|null    $scope_id     Optional filter
     * @return array  Array of role assignment records
     */
    public function GetUserRoles($mundane_id, $scope_type = null, $scope_id = null)
    {
        global $DB;
        $mundane_id = (int) $mundane_id;
        $roles = [];

        $where = "ur.mundane_id = " . $mundane_id;
        $where .= " AND (ur.expires_at IS NULL OR ur.expires_at > NOW())";

        if ($scope_type !== null && $scope_id !== null) {
            // Same narrowest-non-zero-column rule the permission checks use. Filtering
            // on the single column alone would report a park officer's row as a KINGDOM
            // assignment (its kingdom_id records the park's kingdom, not the grant's
            // reach) -- the same escalation ScopeMatchClause() exists to prevent.
            $scope_clause = $this->ScopeMatchClause($scope_type, $scope_id);
            if ($scope_clause === null) {
                // A scope WAS requested and could not be resolved (unknown scope_type, or a
                // non-positive/non-numeric id). Returning the unfiltered list here would
                // answer a narrow question with every role the user holds anywhere -- the
                // three sibling readers all fail closed on the same input, and so must this
                // one. Omitting the clause instead is how GetUserRoles briefly fail-OPENED.
                return [];
            }
            $where .= " AND " . $scope_clause;
        }

        $DB->Clear();
        $sql = "SELECT ur.user_role_id, ur.role_id, r.name, r.display_name, r.is_system,
				ur.kingdom_id, ur.park_id, ur.event_id, ur.unit_id,
				ur.granted_by, ur.created_at, ur.expires_at
			FROM " . DB_PREFIX . "user_role ur
			JOIN " . DB_PREFIX . "role r ON r.role_id = ur.role_id
			WHERE " . $where . "
			ORDER BY r.display_name";

        $result = $DB->DataSet($sql);
        if ($result !== false && $result->size() > 0) {
            while ($result->Next()) {
                $roles[] = [
                    'UserRoleId' => $result->user_role_id,
                    'RoleId' => $result->role_id,
                    'Name' => $result->name,
                    'DisplayName' => $result->display_name,
                    'IsSystem' => $result->is_system,
                    'KingdomId' => $result->kingdom_id,
                    'ParkId' => $result->park_id,
                    'EventId' => $result->event_id,
                    'UnitId' => $result->unit_id,
                    'GrantedBy' => $result->granted_by,
                    'CreatedAt' => $result->created_at,
                    'ExpiresAt' => $result->expires_at,
                ];
            }
        }

        return $roles;
    }

    /**
     * Get all permissions in a role.
     *
     * @param int $role_id
     * @return array  Array of permission records
     */
    public function GetRolePermissions($role_id)
    {
        global $DB;
        $role_id = (int) $role_id;
        $perms = [];

        $DB->Clear();
        $sql = "SELECT p.permission_id, p.`key`, p.display_name, p.description, p.scope_type, p.category
			FROM " . DB_PREFIX . "role_permission rp
			JOIN " . DB_PREFIX . "permission p ON p.permission_id = rp.permission_id
			WHERE rp.role_id = " . $role_id . "
			ORDER BY p.scope_type, p.category, p.`key`";

        $result = $DB->DataSet($sql);
        if ($result !== false && $result->size() > 0) {
            while ($result->Next()) {
                $perms[] = [
                    'PermissionId' => $result->permission_id,
                    'Key' => $result->key,
                    'DisplayName' => $result->display_name,
                    'Description' => $result->description,
                    'ScopeType' => $result->scope_type,
                    'Category' => $result->category,
                ];
            }
        }

        return $perms;
    }

    /**
     * Get the effective permissions for a user at a scope.
     * Aggregates all permissions from all roles assigned at that scope.
     *
     * @param int    $mundane_id
     * @param string $scope_type
     * @param int    $scope_id
     * @return array  Array of permission key strings
     */
    public function GetEffectivePermissions($mundane_id, $scope_type, $scope_id)
    {
        global $DB;
        $mundane_id = (int) $mundane_id;
        $scope_id = (int) $scope_id;
        $permissions = [];

        // Same narrowest-column rule the checker uses: this is what the consoles show a
        // user holds AT a scope, so reading it more loosely than HasPermission() would
        // display a park officer's grant as kingdom-wide authority they do not have.
        $scope_clause = $this->ScopeMatchClause($scope_type, $scope_id);
        if ($scope_clause === null) {
            return $permissions;
        }

        $DB->Clear();
        $sql = "SELECT DISTINCT p.`key`
			FROM " . DB_PREFIX . "user_role ur
			JOIN " . DB_PREFIX . "role_permission rp ON rp.role_id = ur.role_id
			JOIN " . DB_PREFIX . "permission p ON p.permission_id = rp.permission_id
			WHERE ur.mundane_id = " . $mundane_id . "
			  AND " . $scope_clause . "
			  AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
			ORDER BY p.`key`";

        $result = $DB->DataSet($sql);
        if ($result !== false && $result->size() > 0) {
            while ($result->Next()) {
                $permissions[] = $result->key;
            }
        }

        return $permissions;
    }

    /**
     * The role x permission matrix for one kingdom: every role it can use, every
     * permission in the registry, and which cells are filled.
     *
     * Backs the Permissions Grid, which until now rendered a hand-written mock-up --
     * an auth-gated page showing a kingdom fabricated role definitions as if they were
     * its own. Built here rather than in the controller so the "which roles apply to
     * this kingdom" rule (system roles plus the kingdom's own customs, exactly as
     * GetAvailableRoles defines it) has one definition.
     *
     * Two queries regardless of role count: one for the roles, one for every mapping.
     *
     * @param int $kingdom_id
     * @return array{Roles: array, Granted: array<string, array<int, true>>}
     *         Granted is permission_key => [role_id => true].
     */
    public function GetPermissionMatrix($kingdom_id)
    {
        global $DB;

        $kingdom_id = (int) $kingdom_id;
        $roles = $this->GetAvailableRoles($kingdom_id);
        if (count($roles) === 0) {
            return [ 'Roles' => [], 'Granted' => [] ];
        }

        $role_ids = [];
        foreach ($roles as $role) {
            $role_ids[] = (int) $role['RoleId'];
        }

        $DB->Clear();
        $sql = "SELECT rp.role_id, p.`key`
			FROM " . DB_PREFIX . "role_permission rp
			JOIN " . DB_PREFIX . "permission p ON p.permission_id = rp.permission_id
			WHERE rp.role_id IN (" . implode(',', $role_ids) . ")";
        $result = $DB->DataSet($sql);

        $granted = [];
        if ($result !== false && $result->size() > 0) {
            while ($result->Next()) {
                // Registry-unknown keys are skipped: a stale ork_permission row left by a
                // reverted migration must not appear as a capability the kingdom holds.
                if (PermissionRegistry::Exists($result->key)) {
                    $granted[ $result->key ][ (int) $result->role_id ] = true;
                }
            }
        }

        return [ 'Roles' => $roles, 'Granted' => $granted ];
    }

    /**
     * Get all available roles (system + custom for a kingdom).
     *
     * @param int $kingdom_id  Kingdom ID (0 for system roles only)
     * @return array
     */
    public function GetAvailableRoles($kingdom_id = 0)
    {
        global $DB;
        $kingdom_id = (int) $kingdom_id;
        $roles = [];

        $DB->Clear();
        $sql = "SELECT role_id, `name`, display_name, description, scope_type, is_system, kingdom_id
			FROM " . DB_PREFIX . "role
			WHERE kingdom_id = 0 OR kingdom_id = " . $kingdom_id . "
			ORDER BY is_system DESC, display_name";

        $result = $DB->DataSet($sql);
        if ($result !== false && $result->size() > 0) {
            while ($result->Next()) {
                $roles[] = [
                    'RoleId' => $result->role_id,
                    'Name' => $result->name,
                    'DisplayName' => $result->display_name,
                    'Description' => $result->description,
                    'ScopeType' => $result->scope_type,
                    'IsSystem' => $result->is_system,
                    'KingdomId' => $result->kingdom_id,
                ];
            }
        }

        return $roles;
    }

    /**
     * Custom (non-system) roles owned by a kingdom, each with its permission
     * records plus permission and assignment counts.
     *
     * The "custom role" rule (kingdom-owned, is_system = 0) and the per-role
     * aggregation live here so every consumer -- UI, API -- derives them the
     * same way, and so the counts cost a fixed number of queries instead of
     * two per role.
     *
     * @param int $kingdom_id
     * @return array  Array of role records with Permissions / PermCount / UserCount
     */
    public function GetCustomRolesWithCounts($kingdom_id)
    {
        return $this->RolesWithCounts(
            "kingdom_id = " . (int) $kingdom_id . " AND is_system = 0",
            (int) $kingdom_id
        );
    }

    /**
     * The built-in starter roles seeded by migrations/rbac-seed.sql -- the five crown
     * offices plus the five delegable utility roles (Award Manager, Event Coordinator,
     * Attendance Clerk, Treasurer, Heraldry Manager).
     *
     * These live at kingdom_id = 0 / is_system = 1 so every kingdom shares them, which
     * is exactly why GetCustomRolesWithCounts() cannot return them -- it filters to
     * is_system = 0. Without this method the Roles & Permissions page could only ever
     * show them inside a <select>, so a kingdom that had not yet created a custom role
     * or made an assignment saw three empty cards and no sign the starter set existed.
     *
     * Assignment counts are scoped to $kingdom_id: a system role is shared, so an
     * unscoped count would report every kingdom's assignments on one kingdom's page.
     *
     * @param int $kingdom_id  Kingdom whose assignment counts to report.
     * @return array
     */
    public function GetSystemRolesWithCounts($kingdom_id = 0)
    {
        return $this->RolesWithCounts("kingdom_id = 0 AND is_system = 1", (int) $kingdom_id);
    }

    /**
     * SQL predicate matching every ork_user_role row a kingdom owns, across all four
     * scope columns. kingdom.role.grant covers kingdom, park, event AND unit scope, so a
     * predicate that only tests kingdom_id/park_id silently drops live event- and
     * unit-scoped grants -- undercounting the "N users" badge and hiding those rows from
     * the assignment console. Mirrors ResolveOwningKingdomId()'s ownership rules.
     *
     * @param int    $kingdom_id
     * @param string $prefix  Column prefix including the dot, e.g. 'ur.' (or '' for none).
     * @return string
     */
    private function KingdomOwnedScopeClause($kingdom_id, $prefix = '')
    {
        $kingdom_id = (int) $kingdom_id;
        $parks = "SELECT park_id FROM " . DB_PREFIX . "park WHERE kingdom_id = " . $kingdom_id;

        return "( " . $prefix . "kingdom_id = " . $kingdom_id
            . " OR " . $prefix . "park_id IN (" . $parks . ")"
            . " OR " . $prefix . "event_id IN (SELECT event_id FROM " . DB_PREFIX . "event
					WHERE kingdom_id = " . $kingdom_id . " OR park_id IN (" . $parks . "))"
            // Units resolve through their ROSTER, not a park_id column -- ork_unit has none.
            // The first version of this clause joined ork_park ON u.park_id, which is a
            // column that does not exist; DataSet() cannot report the resulting SQL error,
            // so the whole predicate died and BOTH callers returned zero rows -- blanking
            // the Roles & Permissions list and zeroing every "N users" badge. That is
            // strictly worse than the bug this clause was added to fix, so the join is
            // correct or it is not here at all.
            . " OR " . $prefix . "unit_id IN (SELECT DISTINCT um.unit_id
					FROM " . DB_PREFIX . "unit_mundane um
					JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = um.mundane_id
					WHERE m.kingdom_id = " . $kingdom_id . ") )";
    }

    /**
     * Shared body for the two role listings above: roles matching $where_sql, each with
     * its permission list, a permission count, and a count of live assignments within
     * $count_kingdom_id.
     *
     * @param string $where_sql          Already-safe WHERE fragment on ork_role (ints cast by callers).
     * @param int    $count_kingdom_id   Kingdom to scope the assignment count to; 0 counts all.
     * @return array
     */
    private function RolesWithCounts($where_sql, $count_kingdom_id)
    {
        global $DB;
        $count_kingdom_id = (int) $count_kingdom_id;
        $roles = [];
        $order = [];

        $DB->Clear();
        $sql = "SELECT role_id, `name`, display_name, description, scope_type, is_system
			FROM " . DB_PREFIX . "role
			WHERE " . $where_sql . "
			ORDER BY scope_type DESC, display_name";

        $result = $DB->DataSet($sql);
        if ($result !== false && $result->size() > 0) {
            while ($result->Next()) {
                $rid = (int) $result->role_id;
                $roles[ $rid ] = [
                    'RoleId' => $result->role_id,
                    'Name' => $result->name,
                    'DisplayName' => $result->display_name,
                    'Description' => $result->description,
                    'ScopeType' => $result->scope_type,
                    'IsSystem' => (int) $result->is_system,
                    'Permissions' => [],
                    'PermCount' => 0,
                    'UserCount' => 0,
                ];
                $order[] = $rid;
            }
        }

        if (empty($roles)) {
            return [];
        }

        $id_list = implode(',', $order);

        $DB->Clear();
        $sql = "SELECT rp.role_id, p.permission_id, p.`key`, p.display_name, p.description, p.scope_type, p.category
			FROM " . DB_PREFIX . "role_permission rp
			JOIN " . DB_PREFIX . "permission p ON p.permission_id = rp.permission_id
			WHERE rp.role_id IN (" . $id_list . ")
			ORDER BY rp.role_id, p.scope_type, p.category, p.`key`";

        $result = $DB->DataSet($sql);
        if ($result !== false && $result->size() > 0) {
            while ($result->Next()) {
                $rid = (int) $result->role_id;
                if (! isset($roles[ $rid ])) {
                    continue;
                }
                $roles[ $rid ]['Permissions'][] = [
                    'PermissionId' => $result->permission_id,
                    'Key' => $result->key,
                    'DisplayName' => $result->display_name,
                    'Description' => $result->description,
                    'ScopeType' => $result->scope_type,
                    'Category' => $result->category,
                ];
                $roles[ $rid ]['PermCount']++;
            }
        }

        $DB->Clear();
        // A park-scoped grant sets park_id = <park>, and its kingdom_id may be either the
        // park's kingdom (officer-synced rows) or 0 (a hand-written scoped grant), so
        // scoping the count to kingdom_id alone under-reported park-scoped assignments.
        // Count the kingdom's whole tree: its own rows plus rows on any of its parks,
        // events or units -- the same ownership rule GetKingdomRoleAssignments() lists by,
        // so the badge and the assignment list can never disagree.
        $scope = $count_kingdom_id > 0
            ? " AND " . $this->KingdomOwnedScopeClause($count_kingdom_id)
            : "";
        $sql = "SELECT role_id, COUNT(*) AS cnt
			FROM " . DB_PREFIX . "user_role
			WHERE role_id IN (" . $id_list . ")" . $scope . "
			  AND ( expires_at IS NULL OR expires_at > NOW() )
			GROUP BY role_id";

        $result = $DB->DataSet($sql);
        if ($result !== false && $result->size() > 0) {
            while ($result->Next()) {
                $rid = (int) $result->role_id;
                if (isset($roles[ $rid ])) {
                    $roles[ $rid ]['UserCount'] = (int) $result->cnt;
                }
            }
        }

        return array_values($roles);
    }

    /**
     * Get every active role assignment scoped to a kingdom, joined to the role
     * and to the assignee / granter personas.
     *
     * Rows whose expires_at has passed are excluded.
     *
     * Every branch of the scope predicate stays on ork_user_role so idx_ur_kingdom and
     * idx_ur_park remain usable. Testing the park side as `pk.kingdom_id = N` against
     * the LEFT-JOINed ork_park instead reads the same but forces a full scan of
     * ork_user_role -- which holds one row per occupied office across every kingdom.
     *
     * @param int  $kingdom_id
     * @param bool $with_park_names  Defaults to true: each row also carries a
     *                               'ParkName' key resolved from ork_park (empty
     *                               string when the assignment is not park-scoped).
     *                               Pass false to skip that lookup.
     * @return array  Array of assignment records
     */
    public function GetKingdomRoleAssignments($kingdom_id, $with_park_names = true)
    {
        global $DB;
        $kingdom_id = (int) $kingdom_id;
        $assignments = [];

        $DB->Clear();
        $sql = "SELECT ur.user_role_id, ur.mundane_id, ur.role_id, ur.kingdom_id, ur.park_id,
				ur.event_id, ur.unit_id,
				ur.granted_by, ur.created_at, ur.expires_at,
				r.name AS role_name, r.display_name AS role_display_name, r.is_system,
				m.persona, m.username,
				g.persona AS granter_persona,
				pk.name AS park_name
			FROM " . DB_PREFIX . "user_role ur
			JOIN " . DB_PREFIX . "role r ON r.role_id = ur.role_id
			JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = ur.mundane_id
			LEFT JOIN " . DB_PREFIX . "mundane g ON g.mundane_id = ur.granted_by
			LEFT JOIN " . DB_PREFIX . "park pk ON pk.park_id = ur.park_id
			WHERE " . $this->KingdomOwnedScopeClause($kingdom_id, 'ur.') . "
			  AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
			ORDER BY r.display_name, m.persona";

        $result = $DB->DataSet($sql);
        if ($result !== false && $result->size() > 0) {
            while ($result->Next()) {
                $a = [
                    'UserRoleId' => $result->user_role_id,
                    'MundaneId' => $result->mundane_id,
                    'RoleId' => $result->role_id,
                    'KingdomId' => $result->kingdom_id,
                    'ParkId' => $result->park_id,
                    'EventId' => $result->event_id,
                    'UnitId' => $result->unit_id,
                    // Narrowest non-zero column wins, the same rule RevokeRole() and
                    // ScopeMatchClause() read by. Without it a caller that only tests
                    // ParkId (Admin_roles.tpl does exactly that) files an event- or
                    // unit-scoped grant under "kingdom-wide", which reads to the operator
                    // as far more authority than the row actually carries.
                    'ScopeType' => ((int) $result->unit_id > 0) ? 'unit'
                        : (((int) $result->event_id > 0) ? 'event'
                        : (((int) $result->park_id > 0) ? 'park'
                        : (((int) $result->kingdom_id > 0) ? 'kingdom' : 'global'))),
                    'GrantedBy' => $result->granted_by,
                    'CreatedAt' => $result->created_at,
                    'ExpiresAt' => $result->expires_at,
                    'RoleName' => $result->role_name,
                    'RoleDisplayName' => $result->role_display_name,
                    'IsSystem' => $result->is_system,
                    'Persona' => $result->persona,
                    'Username' => $result->username,
                    'GranterPersona' => $result->granter_persona,
                ];

                // Park name comes from the LEFT JOIN above. It used to be a per-row
                // SELECT inside this loop -- an N+1 on every assignment, and worse, it
                // called $DB->Clear() and re-issued a query on the same connection object
                // while the OUTER result set was still being iterated.
                if ($with_park_names) {
                    $a['ParkName'] = ($result->park_name !== null) ? $result->park_name : '';
                }

                $assignments[] = $a;
            }
        }

        return $assignments;
    }

    /**
     * Count how many user-role assignments reference a role (all scopes,
     * including expired ones).
     *
     * @param int $role_id
     * @return int
     */
    public function GetRoleUserCount($role_id)
    {
        global $DB;
        $role_id = (int) $role_id;

        $DB->Clear();
        $sql = "SELECT COUNT(*) AS cnt FROM " . DB_PREFIX . "user_role WHERE role_id = " . $role_id;
        $result = $DB->DataSet($sql);
        if ($result && $result->Next()) {
            return (int) $result->cnt;
        }

        return 0;
    }

    /**
     * All registry permission definitions, keyed by permission key.
     *
     * @return array  key => [display_name, description, scope_type, category]
     */
    public function GetAllPermissions()
    {
        return PermissionRegistry::GetAll();
    }

    /**
     * Registry permission definitions whose DECLARED scope type is exactly $scope_type.
     *
     * CAVEAT -- NOT "the permissions grantable at this scope", despite the name. A key's
     * scope_type is only its narrowest direct-check scope, and the cascade lets a grant
     * at a broader scope satisfy every check beneath it, so filtering a role-permission
     * builder through this drops every key declared lower down.
     * OfficerAdminAjax::actionPermissions() did that and offered 23 of 79 keys; it now
     * builds from GetAllPermissions() and groups by scope for display.
     *
     * @deprecated No caller should use this to decide what may be granted.
     * @see PermissionRegistry::GetByScope()
     *
     * @param string $scope_type  One of: 'global', 'kingdom', 'park', 'event', 'unit'
     * @return array  key => [display_name, description, scope_type, category]
     */
    public function GetPermissionsByScope($scope_type)
    {
        $out = [];
        foreach (PermissionRegistry::GetByScope($scope_type) as $key) {
            $out[ $key ] = PermissionRegistry::Get($key);
        }

        return $out;
    }

    // ================================================================
    // AUDIT LOGGING
    // ================================================================

    /**
     * Write an audit record to ork_rbac_audit.
     */
    private function AuditLog($actor_id, $action, $target_id, $role_id, $permission_id, $kingdom_id, $park_id, $event_id, $unit_id, $detail)
    {
        global $DB;
        $DB->Clear();
        $DB->audit_action = $action;
        $DB->audit_detail = $detail;
        $sql = "INSERT INTO " . DB_PREFIX . "rbac_audit
			(actor_mundane_id, `action`, target_mundane_id, role_id, permission_id,
			 scope_kingdom_id, scope_park_id, scope_event_id, scope_unit_id, detail)
			VALUES (" .
            (int) $actor_id . ", :audit_action, " .
            ($target_id !== null ? (int) $target_id : 'NULL') . ", " .
            ($role_id !== null ? (int) $role_id : 'NULL') . ", " .
            ($permission_id !== null ? (int) $permission_id : 'NULL') . ", " .
            (int) $kingdom_id . ", " .
            (int) $park_id . ", " .
            (int) $event_id . ", " .
            (int) $unit_id . ", :audit_detail)";
        $DB->Execute($sql);
    }

    // ================================================================
    // CACHE HELPERS
    // ================================================================

    /**
     * Invalidate the cache for all users who have a given role.
     * Used when a role's permissions are changed.
     */
    private function InvalidateCacheForRole($role_id)
    {
        global $DB;
        $DB->Clear();
        $sql = "SELECT DISTINCT mundane_id FROM " . DB_PREFIX . "user_role WHERE role_id = " . (int) $role_id;
        $result = $DB->DataSet($sql);
        if ($result !== false && $result->size() > 0) {
            while ($result->Next()) {
                $this->IncrementGenerationCounter($result->mundane_id);
            }
        }
    }
}
