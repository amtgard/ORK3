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

    public function add_auth($request)
    {
        return $this->Authorization->AddAuthorization($request);
    }

    public function del_auth($request)
    {
        return $this->Authorization->RemoveAuthorization($request);
    }

    public function has_authority(int $uid, string $type, $id, ?string $role): bool
    {
        return $this->_authorization_gate()->check($uid, $type, $id, $role);
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
