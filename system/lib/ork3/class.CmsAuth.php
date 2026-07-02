<?php

// CmsBase sorts after CmsAuth alphabetically in scandir(); force-load it first.
require_once __DIR__ . '/class.CmsBase.php';

/*************************************************************************
 * CmsAuth — RBAC layer for the CMS (Hybrid RBAC + scope bridge).
 *
 * Named CMS roles map cumulatively to capabilities; grants are stored in
 * ork_cms_grant (mundane_id, role, scope_type, scope_id). CmsCan() unions
 * a user's matching-scope grant capabilities AND bridges to the existing
 * HasAuthority() so kingdom/park officers implicitly gain rights.
 *
 * Super-admin: the canonical site-wide admin check the Admin panel uses —
 * Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN)
 * (an ork_authorization row role='admin' with all scope ids zero). See
 * controller.Admin.php::index() / ::permissions() and
 * class.Authorization.php::HasAuthority() all-zero-scope short-circuit.
 *
 * DB idiom: shared global $DB (YapoDb). Always Clear() before a raw
 * DataSet()/Execute(); bind via $DB->field = ... (=> :field placeholder).
 * Result rows are driven off Next()+CurrentFieldSet() (Size()/pre-fetch is
 * unreliable on this MariaDB) — same _firstRow()/_eachRow() idiom as
 * class.CmsPage.php.
 *************************************************************************/

class CmsAuth extends CmsBase
{
    /** Allowed roles, lowest → highest privilege. */
    private static $ROLES = array('contributor', 'author', 'editor', 'publisher', 'admin');

    /**
     * Per-role capability *increments*. The public capability set for a role
     * is the union of its own increment plus every lower role's increment
     * (cumulative). Keep these increments non-overlapping.
     */
    private static $ROLE_INCREMENTS = array(
        'contributor' => array('page.create', 'page.edit_own'),
        'author'      => array('page.edit'),
        'editor'      => array('media.manage'),
        'publisher'   => array('page.publish'),
        'admin'       => array('page.delete', 'nav.manage', 'roles.manage', 'theme.manage'),
    );

    /** Capabilities that demand AUTH_ADMIN (not merely AUTH_EDIT) on the bridge. */
    private static $ADMIN_BRIDGE_CAPS = array(
        'page.publish', 'page.delete', 'roles.manage', 'nav.manage', 'theme.manage',
    );

    /**
     * Per-request memoization caches. PHP-FPM resets static state between
     * requests, so these never leak across requests. GetUserGrants() and
     * IsSuperAdmin() are hit repeatedly per action (CmsCan() calls both),
     * so we cache to avoid redundant round-trips. Keyed to preserve the
     * scope-filter semantics of GetUserGrants().
     */
    private static $_grantCache = array();
    private static $_superAdminCache = array();

    public function __construct()
    {
        parent::__construct();
    }

    /* ------------------------------------------------------------------ *
     * Role → capability map
     * ------------------------------------------------------------------ */

    /**
     * Cumulative capability list for a single role (includes all lower roles).
     *
     * @param string $role one of the allowed enum values
     * @return array list of capability strings (empty for an invalid role)
     */
    public function CapabilitiesForRole($role)
    {
        $role = (string)$role;
        if (!in_array($role, self::$ROLES, true)) {
            return array();
        }
        $caps = array();
        foreach (self::$ROLES as $r) {
            foreach (self::$ROLE_INCREMENTS[$r] as $cap) {
                $caps[$cap] = true;
            }
            if ($r === $role) {
                break;
            }
        }
        return array_keys($caps);
    }

    /**
     * Every capability the system knows about (union over all roles).
     *
     * @return array list of capability strings
     */
    public function AllCapabilities()
    {
        $caps = array();
        foreach (self::$ROLE_INCREMENTS as $increment) {
            foreach ($increment as $cap) {
                $caps[$cap] = true;
            }
        }
        return array_keys($caps);
    }

    /* ------------------------------------------------------------------ *
     * Grant reads
     * ------------------------------------------------------------------ */

    /**
     * Raw ork_cms_grant rows for a user, optionally filtered by scope.
     *
     * @param int         $uid       mundane_id
     * @param string|null $scopeType when set, filter to this scope_type
     * @param int|null    $scopeId   when set (with $scopeType), filter to this scope_id
     * @return array list of assoc grant rows
     */
    public function GetUserGrants($uid, $scopeType = null, $scopeId = null)
    {
        global $DB;

        $uid = (int)$uid;
        if ($uid <= 0) {
            return array();
        }

        // Per-request memoization, keyed by the full scope-filter signature.
        $cacheKey = $uid . '|' . ($scopeType === null ? '*' : (string)$scopeType)
            . '|' . ($scopeId === null ? '*' : (int)$scopeId);
        if (isset(self::$_grantCache[$cacheKey])) {
            return self::$_grantCache[$cacheKey];
        }

        $sql = 'SELECT grant_id, mundane_id, role, scope_type, scope_id, granted_by, created_at'
            . ' FROM ' . DB_PREFIX . 'cms_grant'
            . ' WHERE mundane_id = :mundane_id';

        $DB->Clear();
        $DB->mundane_id = $uid;

        if ($scopeType !== null) {
            $sql .= ' AND scope_type = :scope_type';
            $DB->scope_type = $this->_normalizeScopeType($scopeType);
            if ($scopeId !== null) {
                $sql .= ' AND scope_id = :scope_id';
                $DB->scope_id = (int)$scopeId;
            }
        }
        $sql .= ' ORDER BY grant_id ASC';

        $r = $DB->DataSet($sql);

        $out = array();
        foreach ($this->_eachRow($r) as $row) {
            $out[] = $row;
        }

        self::$_grantCache[$cacheKey] = $out;
        return $out;
    }

    /**
     * Union of capabilities the user holds *for a given target scope*.
     *
     * A scope_type='global' grant applies to ALL scopes. A kingdom/park grant
     * applies only when it exactly matches the target scope (same type + id).
     *
     * @param int   $uid   mundane_id
     * @param array $scope ['type'=>'global'|'kingdom'|'park', 'id'=>int]
     * @return array list of capability strings
     */
    public function GetUserCapabilities($uid, $scope)
    {
        $targetType = $this->_normalizeScopeType(isset($scope['type']) ? $scope['type'] : 'global');
        $targetId   = isset($scope['id']) ? (int)$scope['id'] : 0;

        $caps = array();
        foreach ($this->GetUserGrants($uid) as $grant) {
            $gType = isset($grant['scope_type']) ? (string)$grant['scope_type'] : '';
            $gId   = isset($grant['scope_id']) ? (int)$grant['scope_id'] : 0;

            $applies = false;
            if ($gType === 'global') {
                // Global grants apply everywhere.
                $applies = true;
            } elseif ($gType === $targetType && $gId === $targetId) {
                // Scoped grant must match the target scope exactly.
                $applies = true;
            }

            if ($applies) {
                foreach ($this->CapabilitiesForRole($grant['role']) as $cap) {
                    $caps[$cap] = true;
                }
            }
        }
        return array_keys($caps);
    }

    /* ------------------------------------------------------------------ *
     * Capability check
     * ------------------------------------------------------------------ */

    /**
     * Can $uid perform $capability in $scope?
     *
     *  1. ORK super-admin → always true.
     *  2. true if $capability is in the user's unioned capabilities for $scope.
     *  3. Bridge: for kingdom/park scopes, defer to HasAuthority so officers
     *     implicitly gain rights — AUTH_EDIT for ordinary capabilities,
     *     AUTH_ADMIN for publish/delete/roles.manage/nav.manage.
     *
     * @param int    $uid        mundane_id
     * @param string $capability capability string
     * @param array  $scope      ['type'=>..., 'id'=>...]
     * @return bool
     */
    public function CmsCan($uid, $capability, $scope = array('type' => 'global', 'id' => 0))
    {
        $uid = (int)$uid;
        if ($uid <= 0) {
            return false;
        }
        $capability = (string)$capability;

        // (1) ORK super-admin short-circuit.
        if ($this->IsSuperAdmin($uid)) {
            return true;
        }

        // (2) Direct grant capabilities for this scope.
        $caps = $this->GetUserCapabilities($uid, $scope);
        if (in_array($capability, $caps, true)) {
            return true;
        }

        // (3) Scope bridge — only meaningful for kingdom/park scopes.
        $scopeType = $this->_normalizeScopeType(isset($scope['type']) ? $scope['type'] : 'global');
        $scopeId   = isset($scope['id']) ? (int)$scope['id'] : 0;

        if (($scopeType === 'kingdom' || $scopeType === 'park') && $scopeId > 0 && is_object(Ork3::$Lib->authorization)) {
            $authType = ($scopeType === 'kingdom') ? AUTH_KINGDOM : AUTH_PARK;
            $authRole = in_array($capability, self::$ADMIN_BRIDGE_CAPS, true) ? AUTH_ADMIN : AUTH_EDIT;
            if (Ork3::$Lib->authorization->HasAuthority($uid, $authType, $scopeId, $authRole)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this user the canonical site-wide ORK admin? Mirrors the Admin
     * panel's gate (all-zero-scope ork_authorization role='admin' row).
     *
     * @param int $uid mundane_id
     * @return bool
     */
    public function IsSuperAdmin($uid)
    {
        $uid = (int)$uid;
        if ($uid <= 0 || !is_object(Ork3::$Lib->authorization)) {
            return false;
        }
        if (isset(self::$_superAdminCache[$uid])) {
            return self::$_superAdminCache[$uid];
        }
        $isSuper = (bool)Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN);
        self::$_superAdminCache[$uid] = $isSuper;
        return $isSuper;
    }

    /* ------------------------------------------------------------------ *
     * Grant CRUD
     * ------------------------------------------------------------------ */

    /**
     * Idempotently grant a role at a scope. Returns the grant_id (existing row
     * id when the grant already exists, new id otherwise; 0 on invalid input).
     *
     * @param int    $uid       grantee mundane_id
     * @param string $role      one of the allowed enum values
     * @param string $scopeType 'global'|'kingdom'|'park'
     * @param int    $scopeId   scope owner id (0 for global)
     * @param int    $grantedBy mundane_id of the granting admin
     * @return int grant_id (0 on failure)
     */
    public function GrantRole($uid, $role, $scopeType, $scopeId, $grantedBy)
    {
        global $DB;

        $uid  = (int)$uid;
        $role = (string)$role;
        if ($uid <= 0 || !in_array($role, self::$ROLES, true)) {
            return 0;
        }
        $scopeType = $this->_normalizeScopeType($scopeType);
        $scopeId   = (int)$scopeId;
        $grantedBy = (int)$grantedBy;

        // Authorization: the actor must hold roles.manage on the target scope.
        // Without this any caller could escalate roles (the grantedBy field was
        // recorded for audit but never enforced).
        if ($grantedBy <= 0
            || !$this->CmsCan($grantedBy, 'roles.manage', array('type' => $scopeType, 'id' => $scopeId))
        ) {
            return 0;
        }

        // INSERT IGNORE makes the unique-key collision a no-op; we then read
        // the row back by the unique tuple to get the authoritative id (a
        // duplicate INSERT does not yield a reliable lastInsertId on this DB).
        $DB->Clear();
        $DB->mundane_id = $uid;
        $DB->role       = $role;
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $DB->granted_by = (int)$grantedBy;
        $DB->created_at = date('Y-m-d H:i:s');
        $DB->Execute(
            'INSERT IGNORE INTO ' . DB_PREFIX . 'cms_grant'
            . ' (mundane_id, role, scope_type, scope_id, granted_by, created_at)'
            . ' VALUES (:mundane_id, :role, :scope_type, :scope_id, NULLIF(:granted_by, 0), :created_at)'
        );

        // The grant set changed; drop the per-request memo so later reads see it.
        self::$_grantCache = array();

        // Authoritative read-back by the unique tuple.
        $DB->Clear();
        $DB->mundane_id = $uid;
        $DB->role       = $role;
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT grant_id FROM ' . DB_PREFIX . 'cms_grant'
            . ' WHERE mundane_id = :mundane_id AND role = :role'
            . ' AND scope_type = :scope_type AND scope_id = :scope_id LIMIT 1'
        ));

        return $row ? (int)$row['grant_id'] : 0;
    }

    /**
     * Revoke a specific role at a specific scope.
     *
     * @param int    $uid
     * @param string $role
     * @param string $scopeType
     * @param int    $scopeId
     * @param int    $actorUid  acting user; when > 0 the actor must hold
     *                          roles.manage on the target scope (revoke is
     *                          denied otherwise). Defaults to 0 to preserve
     *                          legacy internal callers that have already
     *                          authorized the operation upstream — new callers
     *                          should always pass the real actor.
     * @return bool true when the input was valid and the DELETE executed
     */
    public function RevokeRole($uid, $role, $scopeType, $scopeId, $actorUid = 0)
    {
        global $DB;

        $uid  = (int)$uid;
        $role = (string)$role;
        if ($uid <= 0 || !in_array($role, self::$ROLES, true)) {
            return false;
        }
        $scopeType = $this->_normalizeScopeType($scopeType);
        $scopeId   = (int)$scopeId;

        // Authorization (fail-closed, mirrors GrantRole): the actor MUST hold
        // roles.manage on the target scope. A missing/zero actor is a denial, not
        // a bypass — otherwise a caller that forgot to pass the actor could revoke
        // any grant unchecked.
        $actorUid = (int)$actorUid;
        if ($actorUid <= 0
            || !$this->CmsCan($actorUid, 'roles.manage', array('type' => $scopeType, 'id' => $scopeId))
        ) {
            return false;
        }

        $DB->Clear();
        $DB->mundane_id = $uid;
        $DB->role       = $role;
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $DB->Execute(
            'DELETE FROM ' . DB_PREFIX . 'cms_grant'
            . ' WHERE mundane_id = :mundane_id AND role = :role'
            . ' AND scope_type = :scope_type AND scope_id = :scope_id'
        );

        // The grant set changed; drop the per-request memo so later reads see it.
        self::$_grantCache = array();

        // Execute() is void; confirm the DELETE took by reading the row back
        // on the same unique tuple (row gone → success, still present → fail).
        $DB->Clear();
        $DB->mundane_id = $uid;
        $DB->role       = $role;
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT grant_id FROM ' . DB_PREFIX . 'cms_grant'
            . ' WHERE mundane_id = :mundane_id AND role = :role'
            . ' AND scope_type = :scope_type AND scope_id = :scope_id LIMIT 1'
        ));

        return $row === null;
    }

    /**
     * List grants (for the admin roles UI), joined to the grantee's name.
     * Optionally filter by scope.
     *
     * @param string|null $scopeType filter scope_type
     * @param int|null    $scopeId   filter scope_id (with $scopeType)
     * @return array list of assoc rows incl. persona/given_name/surname
     */
    public function ListGrants($scopeType = null, $scopeId = null)
    {
        global $DB;

        $sql = 'SELECT g.grant_id, g.mundane_id, g.role, g.scope_type, g.scope_id,'
            . ' g.granted_by, g.created_at,'
            . ' m.persona, m.given_name, m.surname'
            . ' FROM ' . DB_PREFIX . 'cms_grant g'
            . ' LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = g.mundane_id'
            . ' WHERE 1 = 1';

        $DB->Clear();
        if ($scopeType !== null) {
            $sql .= ' AND g.scope_type = :scope_type';
            $DB->scope_type = $this->_normalizeScopeType($scopeType);
            if ($scopeId !== null) {
                $sql .= ' AND g.scope_id = :scope_id';
                $DB->scope_id = (int)$scopeId;
            }
        }
        $sql .= ' ORDER BY g.scope_type ASC, g.scope_id ASC, m.persona ASC, g.grant_id ASC';

        $r = $DB->DataSet($sql);

        $out = array();
        foreach ($this->_eachRow($r) as $row) {
            $out[] = $row;
        }
        return $out;
    }

}
