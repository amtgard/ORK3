<?php

class Model_Authorization extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Authorization = new APIModel('Authorization');
    }

    public function index()
    {

    }

    /**
     * Grant / revoke go through AuthorizationGate, not straight to the Authorization
     * service. The gate honors the *.auth.manage permissions and falls back to the
     * legacy authority rule (KPM unit bypass included) when the actor does not hold one.
     * Calling AddAuthorization/RemoveAuthorization directly would skip the permission
     * entirely -- see the note on AuthorizationGate::GrantScopedAuthorization().
     */
    public function add_auth($request)
    {
        return $this->_authorization_gate()->GrantScopedAuthorization($request);
    }

    public function del_auth($request)
    {
        return $this->_authorization_gate()->RevokeScopedAuthorization($request);
    }

    public function has_authority(int $uid, string $type, $id, ?string $role): bool
    {
        return $this->_authorization_gate()->check($uid, $type, $id, $role);
    }

    /**
     * Officer authority over a unit, measured by the unit's ROSTER scopes -- the same
     * rule Unit's server-side add/remove-manager gates use. A UI flag must ask this,
     * not the viewer's own home kingdom/park, or the two disagree.
     */
    public function has_unit_officer_authority(int $uid, int $unit_id): bool
    {
        return $uid > 0 && $unit_id > 0
            && (bool) Ork3::$Lib->authorization->HasUnitOfficerAuthority($uid, $unit_id);
    }

    /**
     * Could $uid hold any administrative standing at all? Exact when false, so the
     * nav skips its standing checks for ordinary players; see the gate method.
     */
    public function has_administrative_footprint(int $uid): bool
    {
        return $this->_authorization_gate()->hasAdministrativeFootprint($uid);
    }

    /**
     * RBAC: permission-key check only (no legacy authority fallback).
     */
    public function has_permission(int $uid, string $permission_key, string $scope_type, $scope_id): bool
    {
        return $this->_authorization_gate()->checkPermission($uid, $permission_key, $scope_type, $scope_id);
    }

    /**
     * RBAC bridge: permission-key check falling back to the legacy authority check.
     */
    public function has_permission_or_authority(int $uid, string $permission_key, string $scope_type, $scope_id, ?string $legacy_role): bool
    {
        return $this->_authorization_gate()->checkPermissionOrAuthority($uid, $permission_key, $scope_type, $scope_id, $legacy_role);
    }

    /**
     * First-class audit write for auth / staff mutations (replaces inline new Dangeraudit()).
     */
    public function audit($call, $parameters, $entity, $entity_id, $prior_state = null, $post_state = null)
    {
        return $this->_dangeraudit()->audit($call, $parameters, $entity, $entity_id, $prior_state, $post_state);
    }

    /**
     * HasAuthority / auth ORM shares the global DB connection — clear after nav auth
     * checks so subclass actions start clean.
     */
    public function clear_db_after_auth_checks(): void
    {
        $this->_authorization_gate()->clearSharedDb();
    }

    private function _authorization_gate(): AuthorizationGate
    {
        return new AuthorizationGate();
    }

    private function _dangeraudit(): Dangeraudit
    {
        return new Dangeraudit();
    }
}
