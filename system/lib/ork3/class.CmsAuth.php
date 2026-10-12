<?php

// CmsBase sorts after CmsAuth alphabetically in scandir(); force-load it first.
require_once __DIR__ . '/class.CmsBase.php';

/*************************************************************************
 * CmsAuth — RBAC layer for the CMS.
 *
 * Named CMS roles map cumulatively to capabilities; grants are stored in
 * ork_cms_grant (mundane_id, role, scope_type, scope_id). CmsCan() answers
 * from those grant rows alone.
 *
 * WHERE ORK AUTHORIZATION MEETS OGRE. The two systems are separate, and OGRE
 * rights never flow back the other way — an OGRE Administrator gains no ORK
 * authority whatsoever. ORK office reaches into OGRE at exactly two points,
 * both of which make somebody an OGRE ADMINISTRATOR (the whole capability set,
 * never a partial one):
 *
 *   1. A site-wide ORK Admin (all-zero-scope ork_authorization role='admin') is
 *      an OGRE Administrator EVERYWHERE — IsSuperAdmin(), CmsCan() step 1.
 *   2. An org officer at the create-or-admin tier is an OGRE Administrator of
 *      THEIR OWN org's site and every site below it — IsScopeOfficer(),
 *      CmsCan() step 3. Kingdom/park scopes only.
 *
 * The GLOBAL front door takes neither of those below the ORK-Admin level: its
 * authors come from ork_cms_grant alone, so no amount of kingdom office admits
 * anyone to the Amtgard front door. An 'edit'-tier authorization row grants
 * nothing anywhere — the bridge hands over roles.manage and theme.manage, which
 * is not a clerical privilege.
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
     * neither CmsCan's grant check nor its officer bridge fires for it.
     */
    private const INVALID_SCOPE = '__invalid__';

    /**
     * Per-role capability *increments*. The public capability set for a role
     * is the union of its own increment plus every lower role's increment
     * (cumulative). Keep these increments non-overlapping.
     */
    private static $ROLE_INCREMENTS = array(
        'contributor' => array('page.create', 'page.edit_own', 'media.upload'),
        'author'      => array('page.edit'),
        'editor'      => array('media.manage', 'page.publish'),
        'publisher'   => array('page.delete', 'nav.manage'),
        'admin'       => array('roles.manage', 'theme.manage'),
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
     * Role / capability vocabulary (for the OGRE Settings UI)
     * ------------------------------------------------------------------ */

    /**
     * Display label + one-line summary per role. Deliberately DESCRIPTIVE only:
     * the authoritative capability list for each role is derived below from
     * CapabilitiesForRole(), never restated here, so a change to
     * $ROLE_INCREMENTS can not leave the Settings page describing a privilege
     * the gates no longer grant (or hiding one they do).
     */
    private static $ROLE_LABELS = array(
        'contributor' => array(
            'label' => 'OGRE Contributor',
            'blurb' => 'Can draft new pages, edit the ones they created, and upload media (removing their own uploads, but nobody else\'s). Nothing they write goes live until someone else publishes it.',
        ),
        'author' => array(
            'label' => 'OGRE Author',
            'blurb' => 'Everything a Contributor can do, plus editing pages and posts written by anyone else.',
        ),
        'editor' => array(
            'label' => 'OGRE Editor',
            'blurb' => 'Everything an Author can do, plus managing the whole media library (including other people\'s uploads) and taking pages and posts live.',
        ),
        'publisher' => array(
            'label' => 'OGRE Publisher',
            'blurb' => 'Everything an Editor can do, plus deleting pages and posts and rearranging the site menus.',
        ),
        'admin' => array(
            'label' => 'OGRE Administrator',
            'blurb' => 'Full control of this site: everything above, plus changing the Theme and managing who appears on this page.',
        ),
    );

    /**
     * Plain-English name for each capability string, for the Settings page's
     * role reference. A capability with no entry here falls back to its raw
     * key, so a newly added capability shows up (unlabelled) rather than
     * silently vanishing from the description of what a role can do.
     */
    private static $CAPABILITY_LABELS = array(
        'page.create'   => 'Create pages and blog posts',
        'page.edit_own' => 'Edit their own pages and posts',
        'page.edit'     => 'Edit anyone\'s pages and posts',
        'media.upload'  => 'Upload media, and remove their own',
        'media.manage'  => 'Manage and remove anyone\'s media',
        'page.publish'  => 'Publish and unpublish',
        'page.delete'   => 'Delete pages and posts',
        'nav.manage'    => 'Manage site navigation',
        'theme.manage'  => 'Change the site Theme',
        'roles.manage'  => 'Manage OGRE users and roles',
    );

    /**
     * The grantable roles, lowest → highest, each with its label, summary and
     * its FULL cumulative capability list (keys plus human labels).
     *
     * This is the single source the OGRE Settings page renders both its role
     * picker and its "what can each role do" reference from, so the two can
     * never disagree with each other or with CmsCan().
     *
     * @return array list of ['key','label','blurb','capabilities'=>[['key','label'],…]]
     */
    public function RoleCatalog()
    {
        $out = array();
        foreach (self::$ROLES as $role) {
            $caps = array();
            foreach ($this->CapabilitiesForRole($role) as $cap) {
                $caps[] = array(
                    'key'   => $cap,
                    'label' => isset(self::$CAPABILITY_LABELS[$cap]) ? self::$CAPABILITY_LABELS[$cap] : $cap,
                );
            }
            $meta = isset(self::$ROLE_LABELS[$role]) ? self::$ROLE_LABELS[$role] : array();
            $out[] = array(
                'key'          => $role,
                'label'        => isset($meta['label']) ? $meta['label'] : $role,
                'blurb'        => isset($meta['blurb']) ? $meta['blurb'] : '',
                'capabilities' => $caps,
            );
        }
        return $out;
    }

    /**
     * Display label for a single role key ('' when the key is not a real role —
     * callers render that as-is rather than inventing a name for it).
     *
     * @param string $role
     * @return string
     */
    public function RoleLabel($role)
    {
        $role = (string)$role;
        return isset(self::$ROLE_LABELS[$role]['label']) ? self::$ROLE_LABELS[$role]['label'] : '';
    }

    /**
     * Can this mundane_id be given an OGRE role at all?
     *
     * The grant table carries no FK, and _authorizeGrantMutation only checks
     * that the id is positive — so without this a hand-posted mundane_id writes
     * a grant for an account that does not exist. The Settings roster then shows
     * it as "Player #999999", and worse, that phantom counts as a role-manager in
     * IsLastRoleManager, which would let a real administrator remove themselves
     * behind cover that nobody can log into.
     *
     * Suspended and penalty-box accounts are refused for the obvious reason: a
     * banned member should not be handed authorship of the public site. This is
     * a grant-time check only — it deliberately does NOT revoke on suspension,
     * which would be a behaviour change in the suspension flow rather than here.
     *
     * @param int $uid
     * @return bool
     */
    public function IsGrantablePerson($uid)
    {
        global $DB;

        $uid = (int)$uid;
        if ($uid <= 0) {
            return false;
        }

        $DB->Clear();
        $DB->mundane_id = $uid;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT mundane_id, suspended, penalty_box FROM ' . DB_PREFIX . 'mundane'
            . ' WHERE mundane_id = :mundane_id LIMIT 1'
        ));

        if ($row === null) {
            return false;
        }
        return (int)$row['suspended'] === 0 && (int)$row['penalty_box'] === 0;
    }

    /**
     * Is this string one of the grantable role keys? The Settings endpoints
     * validate against this before touching the grant table; GrantRole/
     * RevokeRole re-check it themselves, so this is the early, friendly
     * rejection rather than the security boundary.
     *
     * @param string $role
     * @return bool
     */
    public function IsValidRole($role)
    {
        return in_array((string)$role, self::$ROLES, true);
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
     *  2. true if $capability is in the user's unioned grant capabilities for
     *     $scope.
     *  3. Org-site officer bridge (kingdom/park only) → OGRE Administrator of
     *     that site and everything below it. See the class docblock.
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

        // (3) ORG-SITE OFFICER BRIDGE — kingdom and park scopes ONLY.
        //
        // An officer of an org is an OGRE Administrator of that org's site: the
        // capability asked for does not matter, because the bridge confers the
        // whole set. The GLOBAL front door is deliberately excluded — its
        // authors are grant-only, so no amount of org office admits anyone to
        // the Amtgard front door.
        return $this->IsScopeOfficer($uid, $scope);
    }

    /**
     * Does ORK office make this user an OGRE Administrator of this scope?
     *
     * TRUE only for kingdom/park scopes, and only at the create-or-admin tier
     * (AUTH_CREATE; an 'edit' authorization row does not qualify, because the
     * bridge hands over the TOP OGRE rung — granting other people OGRE roles and
     * changing the site Theme included).
     *
     * "Their level and below" needs no code here: HasAuthority already walks the
     * hierarchy. A park check falls back to that park's kingdom, so a kingdom
     * officer covers every park beneath them; and a kingdom check walks up
     * parent_kingdom_id, so a principality is covered by its parent kingdom's
     * officers. See class.Authorization.php::HasAuthority.
     *
     * @param int   $uid
     * @param array $scope ['type'=>..., 'id'=>...]
     * @return bool
     */
    public function IsScopeOfficer($uid, $scope)
    {
        $uid = (int)$uid;
        if ($uid <= 0 || !is_object(Ork3::$Lib->authorization)) {
            return false;
        }

        $scopeType = $this->_strictScopeType(isset($scope['type']) ? $scope['type'] : 'global');
        $scopeId   = isset($scope['id']) ? (int)$scope['id'] : 0;

        // Global is grant-only; an unrecognized scope already failed closed.
        if (($scopeType !== 'kingdom' && $scopeType !== 'park') || $scopeId <= 0) {
            return false;
        }

        $authType = ($scopeType === 'kingdom') ? AUTH_KINGDOM : AUTH_PARK;

        // Per-request memo: CmsCan() is hit many times per action and this is
        // otherwise a repeated (recursive) authority probe.
        $key = $uid . '|' . $authType . '|' . $scopeId;
        if (!isset(self::$_bridgeCache[$key])) {
            self::$_bridgeCache[$key] =
                (bool)Ork3::$Lib->authorization->HasAuthority($uid, $authType, $scopeId, AUTH_CREATE);
        }
        return self::$_bridgeCache[$key];
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
            // apply to every scope) would be wrongly de-credited. Probe REAL residual capability instead. CmsCan
            // reads the just-busted grant cache, so it reflects the post-revoke
            // state and folds in super-admin + global grant (which applies to
            // every scope). Office confers nothing — see the class docblock.
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

    /* ------------------------------------------------------------------ *
     * Scope membership (the OGRE Settings "Manage Users" view)
     * ------------------------------------------------------------------ */

    /**
     * The people holding CMS grants in ONE scope, collapsed to a row per PERSON
     * rather than a row per grant.
     *
     * Roles are cumulative (an 'admin' grant already implies everything
     * 'publisher' allows), so somebody holding both reads to a human as one
     * member at the higher rung — not as two separate entries to be revoked
     * independently. 'role' is therefore the HIGHEST rung held and is what the
     * UI shows and edits; 'roles' keeps the raw list so a member carrying
     * redundant lower grants can still be fully cleaned up by SetUserRole /
     * RevokeAllRoles.
     *
     * Scope is matched EXACTLY: a global grant is not folded into a kingdom
     * scope's member list even though it confers rights there. The global
     * holder is a member of the global site and is managed on that site's
     * Settings page, where revoking actually removes the grant.
     *
     * @param string $scopeType 'global'|'kingdom'|'park'
     * @param int    $scopeId   0 for global
     * @return array list of ['mundane_id','persona','given_name','surname',
     *                        'roles'=>[…],'role','role_label','granted_at',
     *                        'granted_by','granted_by_persona']
     */
    public function ListScopeMembers($scopeType, $scopeId)
    {
        global $DB;

        $scopeType = $this->_strictScopeType($scopeType);
        if ($scopeType === self::INVALID_SCOPE) {
            return array();
        }
        $scopeId = ($scopeType === 'global') ? 0 : (int)$scopeId;

        $DB->Clear();
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $r = $DB->DataSet(
            'SELECT g.grant_id, g.mundane_id, g.role, g.granted_by, g.created_at,'
            . ' m.persona, m.given_name, m.surname,'
            . ' gb.persona AS granted_by_persona'
            . ' FROM ' . DB_PREFIX . 'cms_grant g'
            . ' LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = g.mundane_id'
            . ' LEFT JOIN ' . DB_PREFIX . 'mundane gb ON gb.mundane_id = g.granted_by'
            . ' WHERE g.scope_type = :scope_type AND g.scope_id = :scope_id'
            . ' ORDER BY g.grant_id ASC'
        );

        // Collapse to one entry per person, keeping the highest rung held.
        $members = array();
        foreach ($this->_eachRow($r) as $row) {
            $uid  = (int)$row['mundane_id'];
            $role = (string)$row['role'];
            if ($uid <= 0 || !in_array($role, self::$ROLES, true)) {
                continue;
            }

            if (!isset($members[$uid])) {
                $members[$uid] = array(
                    'mundane_id'         => $uid,
                    'persona'            => (string)$row['persona'],
                    'given_name'         => (string)$row['given_name'],
                    'surname'            => (string)$row['surname'],
                    'roles'              => array(),
                    'role'               => $role,
                    'role_label'         => '',
                    'granted_at'         => (string)$row['created_at'],
                    'granted_by'         => (int)$row['granted_by'],
                    'granted_by_persona' => (string)$row['granted_by_persona'],
                );
            }
            $members[$uid]['roles'][] = $role;

            // Highest rung wins. array_search on the ordered ladder IS the
            // privilege comparison — don't compare the strings themselves.
            $held = array_search($members[$uid]['role'], self::$ROLES, true);
            $rank = array_search($role, self::$ROLES, true);
            if ($rank !== false && ($held === false || $rank > $held)) {
                $members[$uid]['role']               = $role;
                // Attribute the row to whoever granted the rung actually in
                // effect, so "granted by" explains the privilege being shown.
                $members[$uid]['granted_at']         = (string)$row['created_at'];
                $members[$uid]['granted_by']         = (int)$row['granted_by'];
                $members[$uid]['granted_by_persona'] = (string)$row['granted_by_persona'];
            }
        }

        foreach ($members as $uid => $member) {
            $members[$uid]['role_label'] = $this->RoleLabel($member['role']);
        }

        // Highest rung first, then persona — the useful reading order for an
        // access-control list (who has the most power here?).
        $members = array_values($members);
        usort($members, function ($a, $b) {
            $ra = array_search($a['role'], self::$ROLES, true);
            $rb = array_search($b['role'], self::$ROLES, true);
            if ($ra !== $rb) {
                return $rb - $ra;
            }
            return strcasecmp($a['persona'], $b['persona']);
        });

        return $members;
    }

    /**
     * Set a person's role in a scope to EXACTLY $role: grant it, then drop every
     * other rung they hold there.
     *
     * The UI presents one role per person, so a plain GrantRole would silently
     * leave a previous, now-stale rung in place — demoting an Administrator to
     * Contributor would change the badge while leaving the admin grant (and
     * every admin capability) intact. Granting BEFORE revoking also means an
     * actor editing their own row never passes through a state with no grant,
     * which would make the roles.manage check on the follow-up revoke fail and
     * strand them mid-change.
     *
     * Authorization is NOT re-implemented here: GrantRole/RevokeRole each run
     * the full _authorizeGrantMutation() check, so an actor without
     * roles.manage on this scope gets nothing done by calling this.
     *
     * @param int    $uid       grantee mundane_id
     * @param string $role      target role
     * @param string $scopeType 'global'|'kingdom'|'park'
     * @param int    $scopeId   0 for global
     * @param int    $actorUid  acting mundane_id
     * @return bool true when the target grant is in place
     */
    public function SetUserRole($uid, $role, $scopeType, $scopeId, $actorUid)
    {
        $uid  = (int)$uid;
        $role = (string)$role;
        if ($uid <= 0 || !in_array($role, self::$ROLES, true)) {
            return false;
        }

        if ($this->GrantRole($uid, $role, $scopeType, $scopeId, $actorUid) <= 0) {
            return false;
        }

        // Drop the now-redundant rungs. Read the grants back rather than
        // looping the whole ladder so we only touch rows that exist.
        $scopeTypeNorm = $this->_strictScopeType($scopeType);
        $scopeIdNorm   = ($scopeTypeNorm === 'global') ? 0 : (int)$scopeId;

        $stale = array();
        foreach ($this->GetUserGrants($uid, $scopeTypeNorm, $scopeIdNorm) as $grant) {
            $held = (string)$grant['role'];
            if ($held !== $role) {
                $stale[] = $held;
            }
        }

        // ORDER MATTERS when the actor is editing their OWN row. Each RevokeRole
        // re-checks that the ACTOR still holds roles.manage, and only the top rung
        // carries it — so revoking highest-first drops the actor's own authority
        // partway through the loop and every later revoke is silently denied,
        // stranding a rung the UI then claims is gone. Revoking in ASCENDING
        // privilege order keeps the authority-bearing rung in place until its own
        // check has already passed.
        usort($stale, function ($a, $b) {
            return array_search($a, self::$ROLES, true) - array_search($b, self::$ROLES, true);
        });
        foreach ($stale as $held) {
            $this->RevokeRole($uid, $held, $scopeTypeNorm, $scopeIdNorm, $actorUid);
        }

        // Authoritative: report success only if the person now holds EXACTLY the
        // requested rung. Returning true off the grant alone would let a partial
        // revoke surface as "Saved — OGRE Contributor" while a higher rung, and
        // every capability it carries, quietly survived.
        $remaining = $this->GetUserGrants($uid, $scopeTypeNorm, $scopeIdNorm);
        return count($remaining) === 1 && (string)$remaining[0]['role'] === $role;
    }

    /**
     * Remove a person from a scope entirely — revoke every rung they hold there.
     *
     * Each RevokeRole runs its own authorization check and its own
     * orphaned-authorship guard, so a member who still edits here via a global
     * grant (which applies to every scope) keeps their bylines; only a member who
     * genuinely loses all access has their posts de-credited.
     *
     * @param int    $uid
     * @param string $scopeType
     * @param int    $scopeId
     * @param int    $actorUid
     * @return bool true when no grant for this person remains in this scope
     */
    public function RevokeAllRoles($uid, $scopeType, $scopeId, $actorUid)
    {
        $uid = (int)$uid;
        if ($uid <= 0) {
            return false;
        }

        $scopeTypeNorm = $this->_strictScopeType($scopeType);
        if ($scopeTypeNorm === self::INVALID_SCOPE) {
            return false;
        }
        $scopeIdNorm = ($scopeTypeNorm === 'global') ? 0 : (int)$scopeId;

        $held = array();
        foreach ($this->GetUserGrants($uid, $scopeTypeNorm, $scopeIdNorm) as $grant) {
            $held[] = (string)$grant['role'];
        }

        // Ascending privilege order, for the same reason as SetUserRole: an actor
        // removing their OWN access loses roles.manage the moment their top rung
        // goes, so revoking highest-first would deny every later revoke and leave
        // them with an orphaned lower grant they can no longer reach.
        usort($held, function ($a, $b) {
            return array_search($a, self::$ROLES, true) - array_search($b, self::$ROLES, true);
        });
        foreach ($held as $role) {
            $this->RevokeRole($uid, $role, $scopeTypeNorm, $scopeIdNorm, $actorUid);
        }

        // Authoritative: nothing left for this person in this scope.
        return count($this->GetUserGrants($uid, $scopeTypeNorm, $scopeIdNorm)) === 0;
    }

    /**
     * Would removing/demoting $uid leave this scope with NO ONE able to manage
     * roles here? Guards the lockout the Settings UI can otherwise walk into:
     * the last Administrator of a kingdom site removing themselves, after which
     * nobody but an ORK super-admin can hand the rights back.
     *
     * Counts OTHER people who can still manage roles here, which is NOT the same
     * as the members of this scope: a GLOBAL grant applies to every scope (see
     * GetUserCapabilities), so a global OGRE Administrator is a role-manager of
     * every kingdom and park site and must be counted, or the sole scoped
     * administrator is wrongly told they are the last one.
     *
     * Super-admins are deliberately NOT counted. They can always recover any
     * scope, so a lockout is never permanent — but an org should not have to go
     * find one, and treating them as cover would disable the guard everywhere.
     *
     * @param int    $uid       the person about to lose their rights
     * @param string $scopeType
     * @param int    $scopeId
     * @return bool true when $uid is the last role-manager in this scope
     */
    public function IsLastRoleManager($uid, $scopeType, $scopeId)
    {
        $uid = (int)$uid;
        if ($uid <= 0) {
            return false;
        }

        $scopeTypeNorm = $this->_strictScopeType($scopeType);
        if ($scopeTypeNorm === self::INVALID_SCOPE) {
            return false;
        }
        $scopeIdNorm = ($scopeTypeNorm === 'global') ? 0 : (int)$scopeId;

        // Everyone whose grants reach THIS scope: its own members, plus global
        // grant holders when the scope is an org site (a global grant already IS
        // the scope's member list when scopeType is 'global' — don't count twice).
        $reaching = $this->ListScopeMembers($scopeTypeNorm, $scopeIdNorm);
        if ($scopeTypeNorm !== 'global') {
            $reaching = array_merge($reaching, $this->ListScopeMembers('global', 0));
        }

        $selfManages = false;
        $othersManage = false;
        foreach ($reaching as $member) {
            $manages = in_array('roles.manage', $this->CapabilitiesForRole($member['role']), true);
            if (!$manages) {
                continue;
            }
            if ((int)$member['mundane_id'] === $uid) {
                $selfManages = true;
            } else {
                $othersManage = true;
            }
        }

        return $selfManages && !$othersManage;
    }

}
