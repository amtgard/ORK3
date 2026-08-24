<?php

/**
 * Model_CmsAuth — thin pass-through to the CmsAuth lib.
 *
 * The base Model constructor auto-instantiates new APIModel('CmsAuth')
 * (because system/lib/ork3/class.CmsAuth.php exists), and Model::__call
 * forwards any unknown method to it. The explicit methods below mirror the
 * lib surface for clarity; all are pure forwards (no business logic here —
 * DB/auth work lives in the lib).
 *
 * Calling convention: call the snake_case wrapper where one exists; a
 * PascalCase reach-around via Model::__call is the sanctioned form for lib
 * methods that have no wrapper.
 */
class Model_CmsAuth extends Model
{
    public function cms_can($uid, $capability, $scope = array('type' => 'global', 'id' => 0))
    {
        return $this->CmsAuth->CmsCan($uid, $capability, $scope);
    }

    /** @no-callers — mirror surface. */
    public function grant_role($uid, $role, $scopeType, $scopeId, $grantedBy)
    {
        return $this->CmsAuth->GrantRole($uid, $role, $scopeType, $scopeId, $grantedBy);
    }

    /** @no-callers — mirror surface. */
    public function revoke_role($uid, $role, $scopeType, $scopeId, $actorUid = 0)
    {
        return $this->CmsAuth->RevokeRole($uid, $role, $scopeType, $scopeId, $actorUid);
    }

    /** @no-callers — mirror surface. */
    public function get_user_grants($uid, $scopeType = null, $scopeId = null)
    {
        return $this->CmsAuth->GetUserGrants($uid, $scopeType, $scopeId);
    }

    public function get_user_capabilities($uid, $scope)
    {
        return $this->CmsAuth->GetUserCapabilities($uid, $scope);
    }

    /** @no-callers — mirror surface. */
    public function capabilities_for_role($role)
    {
        return $this->CmsAuth->CapabilitiesForRole($role);
    }

    /** @no-callers — mirror surface. */
    public function list_grants($scopeType = null, $scopeId = null)
    {
        return $this->CmsAuth->ListGrants($scopeType, $scopeId);
    }

    /** @no-callers — mirror surface. */
    public function is_super_admin($uid)
    {
        return $this->CmsAuth->IsSuperAdmin($uid);
    }

    /** Callers: Controller_Cms::_capList() (super-admin branch, :1078) and ::_bridgedCaps() (officer bridge, non-super, :1119). */
    public function all_capabilities()
    {
        return $this->CmsAuth->AllCapabilities();
    }
}
