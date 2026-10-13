<?php

/**
 * Committable authorization facade for both reads and writes: HasAuthority /
 * RBAC permission checks, plus the Grant/RevokeScopedAuthorization writes
 * (class.Authorization.php is local-only per pre-commit hook, so any auth logic
 * that needs to be maintainable lives here).
 */
class AuthorizationGate extends Ork3
{
    public function check(int $mundaneId, string $type, $id, ?string $role): bool
    {
        $auth = new Authorization();

        return (bool) $auth->HasAuthority($mundaneId, $type, $id, $role);
    }

    /**
     * Check if a user has a specific RBAC permission at a scope.
     * Sole implementation of the RBAC permission check — delegates to
     * RBACService::HasPermission(). Lives here (and not on Authorization)
     * because class.Authorization.php is excluded from every commit by the
     * pre-commit hook, so edits made there can never ship.
     *
     * Parameters are intentionally untyped: the ~90 lib call sites pass
     * whatever their local $mundane_id / scope id happens to be.
     *
     * @param int|string $mundaneId     User ID
     * @param string     $permissionKey Permission key, e.g. 'kingdom.award.create'
     * @param string     $scopeType     One of: 'global', 'kingdom', 'park', 'event', 'unit'
     * @param int|string $scopeId       The ID of the scoped entity
     * @return bool
     */
    public function checkPermission($mundaneId, $permissionKey, $scopeType, $scopeId): bool
    {
        return (bool) Ork3::$Lib->rbacservice->HasPermission($mundaneId, $permissionKey, $scopeType, $scopeId);
    }

    /**
     * Bridge check for the RBAC migration: true when the actor holds the RBAC
     * permission OR the legacy HasAuthority check passes. Both old auth records
     * and new RBAC role assignments grant access during the transition.
     *
     * @param int|string  $mundaneId     User ID
     * @param string      $permissionKey RBAC permission key
     * @param string      $scopeType     Scope type for the RBAC check ('global' uses scope id 0)
     * @param int|string  $scopeId       Scope entity ID
     * @param string|null $legacyRole    Legacy auth role for HasAuthority (AUTH_CREATE or AUTH_EDIT)
     * @return bool
     */
    public function checkPermissionOrAuthority($mundaneId, $permissionKey, $scopeType, $scopeId, $legacyRole): bool
    {
        // Map RBAC scope_type to legacy AUTH_* type constant (park is the default).
        $descriptors = $this->scopeDescriptors();
        $legacyType = $descriptors[$scopeType]['type'] ?? AUTH_PARK;

        // The legacy counterpart of a global permission is the all-zero-scope admin row,
        // which HasAuthority only matches when it is handed id 0 -- any other id makes it
        // fall through to a scope lookup that cannot succeed. Callers pass 0 already, but
        // pinning it here means a stray scope id can never quietly downgrade the check.
        $legacyScopeId = ($scopeType === 'global') ? 0 : $scopeId;

        return $this->checkPermission($mundaneId, $permissionKey, $scopeType, $scopeId)
            || (bool) Ork3::$Lib->authorization->HasAuthority($mundaneId, $legacyType, $legacyScopeId, $legacyRole);
    }

    /**
     * The one scope table in this class: RBAC scope_type → legacy AUTH_* constant
     * and the *.auth.manage permission that governs grants at that scope. Every
     * other AUTH_* to scope translation here derives from it, so the two spellings
     * can no longer drift apart.
     *
     * AUTH_ADMIN is deliberately mapped to the 'global' scope: the legacy
     * counterpart of a global permission is the all-zero admin row.
     *
     * A method rather than a class constant: AUTH_* are runtime define()s from
     * class.Authorization.php, so keying a constant array on them would depend on load
     * order. Building the map at call time cannot.
     *
     * @return array<string, array{type: string, manage: string}>
     */
    private function scopeDescriptors(): array
    {
        return [
            'kingdom' => ['type' => AUTH_KINGDOM, 'manage' => 'kingdom.auth.manage'],
            'park'    => ['type' => AUTH_PARK,    'manage' => 'park.auth.manage'],
            'event'   => ['type' => AUTH_EVENT,   'manage' => 'event.auth.manage'],
            'unit'    => ['type' => AUTH_UNIT,    'manage' => 'unit.auth.manage'],
            'global'  => ['type' => AUTH_ADMIN,   'manage' => 'global.admin.grant'],
        ];
    }

    /**
     * Legacy AUTH_* scope constant → [RBAC permission key, RBAC scope_type].
     * Derived from scopeDescriptors(); do not re-type the pairs here.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function authTypeMap(): array
    {
        $map = [];
        foreach ($this->scopeDescriptors() as $scopeType => $descriptor) {
            $map[$descriptor['type']] = [$descriptor['manage'], $scopeType];
        }
        return $map;
    }

    /**
     * Grant an authorization row, honoring the *.auth.manage permission.
     *
     * Why this exists. Authorization::add_authorization() authorizes on HasAuthority()
     * alone, and it lives in class.Authorization.php, which the pre-commit hook keeps
     * out of every commit -- so it cannot be taught about permissions. The consoles
     * checked *.auth.manage and then called straight into it, which made those four keys
     * pre-filters rather than grants: an officer holding park.auth.manage through an RBAC
     * role and nothing else still failed the domain's own check and was refused.
     *
     * So: when the actor holds the permission, write the row through add_auth_h() (the
     * low-level helper, which performs no authority check of its own). Otherwise fall
     * through to add_authorization() unchanged, which preserves every legacy path --
     * including the KPM unit-roster bypass, which no permission replaces.
     *
     * NOTE: the SOAP/JSON `Authorization` service still exposes AddAuthorization
     * directly and remains legacy-only for the reason above. This method is the path the
     * application uses; anything reaching the raw service gets the old behavior.
     *
     * @param array $request  Token, MundaneId, Type (AUTH_* constant), Id, Role
     * @return array  Standard ORK response array (Detail = new authorization_id)
     */
    public function GrantScopedAuthorization(array $request): array
    {
        $actorId = (int) Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        if ($actorId <= 0) {
            return BadToken();
        }

        $type = $request['Type'] ?? '';
        $scopeId = (int) ($request['Id'] ?? 0);

        // Role is validated by add_authorization(); mirror it here so the permission
        // path cannot write a role the legacy path would have rejected.
        $role = $request['Role'] ?? '';
        if (!in_array($role, [AUTH_CREATE, AUTH_EDIT, AUTH_ADMIN], true)) {
            return InvalidParameter(null, 'Unrecognized Role.');
        }

        // Type and Role have to agree about admin. add_auth_h() writes an all-zero row
        // for Type=admin whatever the role is, so Type=admin/Role=create produced a row
        // that conferred nothing and that rowScope() could never classify -- i.e. one no
        // console could ever revoke. Conversely a scoped `admin` row is honored only at its
        // own scope, i.e. it is a redundant spelling of `create`, so refusing the
        // combination costs nothing and keeps role='admin' meaning exactly one thing.
        if (($type === AUTH_ADMIN) !== ($role === AUTH_ADMIN)) {
            return InvalidParameter(null, 'Role does not match Type.');
        }

        // Handing out the all-zero admin row stays a true-admin act. Without this,
        // global.admin.grant -- a key the RBAC roles hand out as "may manage grants" --
        // is a single request away from full legacy admin for its holder.
        if ($role === AUTH_ADMIN && !$this->check($actorId, AUTH_ADMIN, 0, AUTH_CREATE)) {
            return NoAuthorization();
        }

        if ($this->actorMayManageAuthorizations($actorId, $type, $scopeId)) {
            Ork3::$Lib->authorization->log->Write('Authorization', $actorId, LOG_ADD, $request);
            return Ork3::$Lib->authorization->add_auth_h($request);
        }

        return Ork3::$Lib->authorization->add_authorization($actorId, $request);
    }

    /**
     * Revoke an authorization row, honoring the *.auth.manage permission.
     *
     * Counterpart to GrantScopedAuthorization(); same reasoning. The row's own scope
     * decides which permission applies -- never a scope named by the caller -- so a
     * park officer cannot revoke a kingdom grant by id. remove_auth_h() keeps its own
     * officer-dependency guard and audit write.
     *
     * @param array $request  Token, AuthorizationId
     * @return array  Standard ORK response array
     */
    public function RevokeScopedAuthorization(array $request): array
    {
        global $DB;

        $actorId = (int) Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        if ($actorId <= 0) {
            return BadToken();
        }

        $authId = (int) ($request['AuthorizationId'] ?? 0);
        if (!valid_id($authId)) {
            return InvalidParameter(null, 'AuthorizationId is not set.');
        }

        $DB->Clear();
        $row = $DB->DataSet(
            'SELECT park_id, kingdom_id, event_id, unit_id, role FROM ' . DB_PREFIX . 'authorization'
            . ' WHERE authorization_id = ' . $authId . ' LIMIT 1'
        );
        if ($row === false || !$row->Next()) {
            return ProcessingError();
        }

        [$type, $scopeId] = $this->rowScope($row);
        $DB->Clear();

        // Taking away the all-zero admin row is a true-admin act for the same reason
        // handing one out is. remove_auth_h() performs no authority check at all, so
        // without this global.admin.grant alone could delete every real admin row --
        // where the legacy arm's HasAuthority('admin', $authId, AUTH_CREATE) refused
        // anyone who was not already an all-zero admin.
        if ($type === AUTH_ADMIN && !$this->check($actorId, AUTH_ADMIN, 0, AUTH_CREATE)) {
            return NoAuthorization();
        }

        if ($this->actorMayManageAuthorizations($actorId, $type, $scopeId)) {
            return Ork3::$Lib->authorization->remove_auth_h($request);
        }

        return Ork3::$Lib->authorization->RemoveAuthorization($request);
    }

    /**
     * Resolve which scope an ork_authorization row belongs to. Mirrors the private
     * Authorization::DetermineAuthType(): an unscoped `admin` row is global, otherwise
     * whichever scope column is nonzero wins, narrowest last.
     *
     * Returns a null type -- not a made-up 'None' string -- when no scope column
     * applies, so an unclassifiable row is distinguishable from a real scope.
     *
     * @param object $row  A DataSet positioned on the authorization row
     * @return array  [AUTH_* type constant or null when unclassifiable, scope id]
     */
    private function rowScope($row): array
    {
        if ((string) $row->role === AUTH_ADMIN) {
            return [AUTH_ADMIN, 0];
        }
        if ((int) $row->unit_id > 0) {
            return [AUTH_UNIT, (int) $row->unit_id];
        }
        if ((int) $row->event_id > 0) {
            return [AUTH_EVENT, (int) $row->event_id];
        }
        if ((int) $row->kingdom_id > 0) {
            return [AUTH_KINGDOM, (int) $row->kingdom_id];
        }
        if ((int) $row->park_id > 0) {
            return [AUTH_PARK, (int) $row->park_id];
        }
        return [null, 0];
    }

    /**
     * Does this actor hold the *.auth.manage permission for the given legacy scope?
     *
     * Permission only -- deliberately not the OrAuthority bridge. The legacy arm is
     * already the fallback branch in both callers, and running it here would make every
     * caller take the "permission path" and skip the KPM unit bypass that lives in
     * add_authorization().
     */
    private function actorMayManageAuthorizations(int $actorId, $type, int $scopeId): bool
    {
        $map = $this->authTypeMap();
        if (!is_string($type) || !isset($map[$type])) {
            return false;
        }

        [$permissionKey, $scopeType] = $map[$type];
        if ($scopeType !== 'global' && $scopeId <= 0) {
            return false;
        }

        return $this->checkPermission($actorId, $permissionKey, $scopeType, $scopeId);
    }

    /**
     * JSON/SOAP API: HasAuthority request → { Status, Authorized }.
     * Actor is resolved from Token; client MundaneId is ignored (privilege-oracle fix).
     */
    public function HasAuthority(array $request): array
    {
        $actorId = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        if ($actorId <= 0) {
            return array_merge(BadToken(), ['Authorized' => false]);
        }

        $type = $request['Type'] ?? '';
        $id = array_key_exists('Id', $request) ? $request['Id'] : null;
        if ($id !== null && $id !== '') {
            $id = (int) $id;
        }
        $role = $request['Role'] ?? null;

        return [
            'Status' => Success(),
            'Authorized' => $this->check($actorId, $type, $id, $role),
        ];
    }

    /**
     * HasAuthority / auth ORM share the global DB connection. Clear after nav
     * auth checks so subclass controller actions start with a clean DB state.
     */
    public function clearSharedDb(): void
    {
        global $DB;
        $DB->Clear();
    }
}
