<?php

/**
 * Committable HasAuthority / RBAC facade (class.Authorization.php is local-only
 * per pre-commit hook, so any auth logic that needs to be maintainable lives here).
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
     * @param string     $scopeType     One of: 'kingdom', 'park', 'event', 'unit'
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
     * @param string      $scopeType     Scope type for the RBAC check
     * @param int|string  $scopeId       Scope entity ID
     * @param string|null $legacyRole    Legacy auth role for HasAuthority (AUTH_CREATE or AUTH_EDIT)
     * @return bool
     */
    public function checkPermissionOrAuthority($mundaneId, $permissionKey, $scopeType, $scopeId, $legacyRole): bool
    {
        // Map RBAC scope_type to legacy AUTH_* type constant (park is the default).
        $legacyTypes = [
            'kingdom' => AUTH_KINGDOM,
            'park' => AUTH_PARK,
            'event' => AUTH_EVENT,
            'unit' => AUTH_UNIT,
        ];
        $legacyType = $legacyTypes[$scopeType] ?? AUTH_PARK;

        return $this->checkPermission($mundaneId, $permissionKey, $scopeType, $scopeId)
            || (bool) Ork3::$Lib->authorization->HasAuthority($mundaneId, $legacyType, $scopeId, $legacyRole);
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
