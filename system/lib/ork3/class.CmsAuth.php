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
     * Fail-closed sentinel for an unrecognized scope_type. _strictScopeType()
     * (this layer's own normalizer, below) returns this — NOT 'global' — for any
     * input that isn't exactly global/kingdom/park, so a garbage/forged scope can
     * never be silently promoted to the highest-privilege GLOBAL scope. It matches
     * no real scope enum value, so GrantRole/RevokeRole reject it outright and
     * CmsCan's grant/bridge checks never fire for it.
     */
    private const INVALID_SCOPE = '__invalid__';

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
    private static $_bridgeCache = array();

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * RBAC-layer scope normalization that FAILS CLOSED. The base scope
     * clamp in CmsBase collapses any unrecognized input to 'global' (fine for a
     * media/content scope filter), but for authorization that would let a
     * typo'd/forged scope_type silently target the site-wide GLOBAL scope.
     * Here an exact global/kingdom/park passes through; anything else collapses to
     * INVALID_SCOPE — a value that matches no real scope, so every grant read,
     * grant write, and capability check that flows through this method fails safe.
     *
     * This is deliberately a SEPARATE name, not an override of the base clamp:
     * an override would silently retarget the inherited helpers (_cmsAudit,
     * _softDelete, _restore) onto a return domain they were never written for.
     * Every authorization path in this class calls this method explicitly.
     *
     * @param string $scopeType
     * @return string 'global'|'kingdom'|'park'|INVALID_SCOPE
     */
    protected function _strictScopeType($scopeType)
    {
        $scopeType = (string)$scopeType;
        if ($scopeType === 'global' || $scopeType === 'kingdom' || $scopeType === 'park') {
            return $scopeType;
        }
        return self::INVALID_SCOPE;
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
            $normType = $this->_strictScopeType($scopeType);
            $sql .= ' AND scope_type = :scope_type';
            $DB->scope_type = $normType;
            if ($scopeId !== null) {
                // A global grant is always keyed at scope_id 0; never let a
                // (global, nonzero-id) filter go out and miss the real row.
                $sql .= ' AND scope_id = :scope_id';
                $DB->scope_id = ($normType === 'global') ? 0 : (int)$scopeId;
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
        $targetType = $this->_strictScopeType(isset($scope['type']) ? $scope['type'] : 'global');
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
        $scopeType = $this->_strictScopeType(isset($scope['type']) ? $scope['type'] : 'global');
        $scopeId   = isset($scope['id']) ? (int)$scope['id'] : 0;

        if (($scopeType === 'kingdom' || $scopeType === 'park') && $scopeId > 0 && is_object(Ork3::$Lib->authorization)) {
            $authType = ($scopeType === 'kingdom') ? AUTH_KINGDOM : AUTH_PARK;
            $authRole = in_array($capability, self::$ADMIN_BRIDGE_CAPS, true) ? AUTH_ADMIN : AUTH_EDIT;

            // Per-request memoization of the HasAuthority bridge — CmsCan() is hit
            // repeatedly per action and this authority probe is otherwise a repeated
            // round-trip. Keyed to the full HasAuthority signature.
            $bridgeKey = $uid . '|' . $authType . '|' . $scopeId . '|' . $authRole;
            if (!isset(self::$_bridgeCache[$bridgeKey])) {
                self::$_bridgeCache[$bridgeKey] =
                    (bool)Ork3::$Lib->authorization->HasAuthority($uid, $authType, $scopeId, $authRole);
            }
            if (self::$_bridgeCache[$bridgeKey]) {
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
     * Shared validation + authorization preamble for GrantRole/RevokeRole.
     *
     * FAIL-CLOSED CONTRACT: the ONLY success signal is a non-null array. Every
     * rejection returns literal NULL — never false, 0 or '' — and every caller
     * MUST test `=== null`. A truthiness test would fail OPEN, because a valid
     * normalized tuple is an array that could otherwise be confused with a
     * rejection sentinel by a sloppy comparison.
     *
     * Rejects, in order:
     *  - a non-positive grantee uid or a role outside the enum;
     *  - A scope_type that isn't exactly global/kingdom/park (INVALID_SCOPE
     *    is never clamped to 'global' — a forged scope can't become site-wide);
     *  - an absent (<= 0) actor, or an actor lacking roles.manage on the target
     *    scope. The actor check is mandatory: grantedBy/actorUid used to be
     *    recorded for audit but never enforced, so any caller could escalate.
     *
     * Normalization: a global grant always keys at scope_id 0.
     *
     * @param int    $uid       grantee mundane_id
     * @param string $role      one of the allowed enum values
     * @param string $scopeType 'global'|'kingdom'|'park'
     * @param int    $scopeId   scope owner id (0 for global)
     * @param int    $actorUid  acting mundane_id (grantedBy / actorUid)
     * @return array|null normalized ['uid','role','scope_type','scope_id','actor'],
     *                    or NULL when the mutation is denied
     */
    private function _authorizeGrantMutation($uid, $role, $scopeType, $scopeId, $actorUid)
    {
        $uid  = (int)$uid;
        $role = (string)$role;
        if ($uid <= 0 || !in_array($role, self::$ROLES, true)) {
            return null;
        }

        $scopeType = $this->_strictScopeType($scopeType);
        // Fail closed — an unrecognized scope_type is never a real scope.
        if ($scopeType === self::INVALID_SCOPE) {
            return null;
        }
        // A global grant always lives at scope_id 0 (no phantom global/nonzero).
        $scopeId  = ($scopeType === 'global') ? 0 : (int)$scopeId;
        $actorUid = (int)$actorUid;

        // Authorization: the actor must hold roles.manage on the target scope.
        // A missing/zero actor is a DENIAL, not a bypass.
        if ($actorUid <= 0
            || !$this->CmsCan($actorUid, 'roles.manage', array('type' => $scopeType, 'id' => $scopeId))
        ) {
            return null;
        }

        return array(
            'uid'        => $uid,
            'role'       => $role,
            'scope_type' => $scopeType,
            'scope_id'   => $scopeId,
            'actor'      => $actorUid,
        );
    }

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

        // Validation + authorization (scope checks + the roles.manage actor check).
        // MUST be an identity test against null: the helper's only success signal
        // is a non-null array, and a truthiness test here would fail OPEN.
        $auth = $this->_authorizeGrantMutation($uid, $role, $scopeType, $scopeId, $grantedBy);
        if ($auth === null) {
            return 0;
        }
        $uid       = $auth['uid'];
        $role      = $auth['role'];
        $scopeType = $auth['scope_type'];
        $scopeId   = $auth['scope_id'];
        $grantedBy = $auth['actor'];

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

        // The grant set changed; drop the per-request memos so later reads see it.
        self::$_grantCache = array();
        self::$_bridgeCache = array();

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

        $grantId = $row ? (int)$row['grant_id'] : 0;

        // Audit the grant (fire-and-forget). entity_id = the grantee uid so
        // the trail reads "actor granted <role> to <uid> at <scope>".
        if ($grantId > 0) {
            $this->_cmsAudit((int)$grantedBy, 'grant.' . $role, 'grant', $uid, $scopeType, $scopeId);
        }

        return $grantId;
    }

    /**
     * Revoke a specific role at a specific scope.
     *
     * @param int    $uid
     * @param string $role
     * @param string $scopeType
     * @param int    $scopeId
     * @param int    $actorUid  acting user; REQUIRED. Fail-closed: a missing or
     *                          zero actor is always denied (returns false), never
     *                          a bypass. When > 0 the actor must additionally hold
     *                          roles.manage on the target scope or the revoke is
     *                          denied. Every caller must pass the real actor uid.
     * @return bool true when the input was valid and the DELETE executed
     */
    public function RevokeRole($uid, $role, $scopeType, $scopeId, $actorUid = 0)
    {
        global $DB;

        // Validation + authorization (scope checks + the roles.manage actor check),
        // identical to GrantRole's. MUST be an identity test against null — the
        // helper's only success signal is a non-null array, and a truthiness test
        // here would fail OPEN and let an unauthorized revoke through.
        $auth = $this->_authorizeGrantMutation($uid, $role, $scopeType, $scopeId, $actorUid);
        if ($auth === null) {
            return false;
        }
        $uid       = $auth['uid'];
        $role      = $auth['role'];
        $scopeType = $auth['scope_type'];
        $scopeId   = $auth['scope_id'];
        $actorUid  = $auth['actor'];

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

        // The grant set changed; drop the per-request memos so later reads see it.
        self::$_grantCache = array();
        self::$_bridgeCache = array();

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

        $revoked = ($row === null);

        // Audit the revoke (fire-and-forget). entity_id = the grantee uid.
        if ($revoked) {
            $this->_cmsAudit((int)$actorUid, 'revoke.' . $role, 'grant', $uid, $scopeType, $scopeId);

            // Orphaned-authorship guard. Reassign this member's posts to
            // a neutral author (author_id NULL, so the byline falls back to the
            // scope label) ONLY when they can genuinely no longer manage content
            // here. The old test — "no raw grant left in this scope" — was too
            // eager: a member who still edits via a GLOBAL CMS grant (global grants
            // apply to every scope) or via the officer→HasAuthority bridge would be
            // wrongly de-credited. Probe REAL residual capability instead. CmsCan
            // reads the just-busted grant cache, so it reflects the post-revoke
            // state and folds in super-admin + global grant + the scope bridge.
            $scope = array('type' => $scopeType, 'id' => $scopeId);
            $stillManages = $this->CmsCan($uid, 'page.edit', $scope)
                || $this->CmsCan($uid, 'page.edit_own', $scope);
            if (!$stillManages) {
                $this->_reassignAuthoredPosts($uid, $scopeType, $scopeId, (int)$actorUid);
            }
        }

        return $revoked;
    }

    /**
     * Detach a departed member from the posts they authored in a scope: NULL out
     * author_id so the byline falls back to the neutral scope label. Bulk single
     * UPDATE; scope-bound so it never touches another org's content. Fire-and-forget
     * audit of the reassignment count.
     *
     * @param int    $uid       grantee whose grants were just fully revoked here
     * @param string $scopeType normalized scope_type
     * @param int    $scopeId   scope_id
     * @param int    $actorUid  acting admin (audit trail)
     * @return void
     */
    private function _reassignAuthoredPosts($uid, $scopeType, $scopeId, $actorUid)
    {
        global $DB;

        $uid = (int)$uid;
        if ($uid <= 0) {
            return;
        }

        // Count first so the audit records how many bylines were neutralized (and
        // so we skip the write + audit entirely when there's nothing to reassign).
        $DB->Clear();
        $DB->author_id = $uid;
        $DB->scope_type = $scopeType;
        $DB->scope_id = (int)$scopeId;
        $countRow = $this->_firstRow($DB->DataSet(
            'SELECT COUNT(*) AS c FROM ' . DB_PREFIX . 'cms_post'
            . ' WHERE author_id = :author_id AND scope_type = :scope_type AND scope_id = :scope_id'
        ));
        $affected = ($countRow !== null && isset($countRow['c'])) ? (int)$countRow['c'] : 0;
        if ($affected <= 0) {
            return;
        }

        // Literal NULL in the SQL (not a bound param) — yapo drops null bindings
        // from an UPDATE, which would silently no-op the clear.
        $DB->Clear();
        $DB->author_id = $uid;
        $DB->scope_type = $scopeType;
        $DB->scope_id = (int)$scopeId;
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_post SET author_id = NULL'
            . ' WHERE author_id = :author_id AND scope_type = :scope_type AND scope_id = :scope_id'
        );

        // Audit the bulk reassignment (fire-and-forget). entity_id = grantee.
        $this->_cmsAudit((int)$actorUid, 'reassign_author.' . $affected, 'post', $uid, $scopeType, (int)$scopeId);
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
            $DB->scope_type = $this->_strictScopeType($scopeType);
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
