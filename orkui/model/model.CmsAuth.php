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

    /** Is this user a site-wide ORK Admin (the one ORK->OGRE intersection)? */
    public function is_super_admin($uid)
    {
        return $this->CmsAuth->IsSuperAdmin($uid);
    }

    /** Does ORK office make this user an OGRE Admin of this (kingdom/park) scope? */
    public function is_scope_officer($uid, $scope)
    {
        return $this->CmsAuth->IsScopeOfficer($uid, $scope);
    }

    public function get_user_capabilities($uid, $scope)
    {
        return $this->CmsAuth->GetUserCapabilities($uid, $scope);
    }

    /** Callers: Controller_Cms::_capList() (super-admin branch, :1078) and ::_bridgedCaps() (officer bridge, non-super, :1119). */
    public function all_capabilities()
    {
        return $this->CmsAuth->AllCapabilities();
    }

    /* ------------------------------------------------------------------ *
     * OGRE Settings — role vocabulary + scope membership
     * ------------------------------------------------------------------ */

    /** The grantable roles with labels, summaries and cumulative capabilities. */
    public function role_catalog()
    {
        return $this->CmsAuth->RoleCatalog();
    }

    /** Cumulative capability list for one role (includes every lower rung). */
    public function capabilities_for_role($role)
    {
        return $this->CmsAuth->CapabilitiesForRole($role);
    }

    /** Display label for one role key ('' when it is not a real role). */
    public function role_label($role)
    {
        return $this->CmsAuth->RoleLabel($role);
    }

    /** Can this mundane_id be given an OGRE role (exists, not suspended/banned)? */
    public function is_grantable_person($uid)
    {
        return $this->CmsAuth->IsGrantablePerson($uid);
    }

    /** Is this string one of the grantable role keys? */
    public function is_valid_role($role)
    {
        return $this->CmsAuth->IsValidRole($role);
    }

    /** People holding grants in one scope, one row per person (highest rung). */
    public function list_scope_members($scopeType, $scopeId)
    {
        return $this->CmsAuth->ListScopeMembers($scopeType, $scopeId);
    }

    /** Set a person's role in a scope to exactly $role (drops other rungs). */
    public function set_user_role($uid, $role, $scopeType, $scopeId, $actorUid)
    {
        return $this->CmsAuth->SetUserRole($uid, $role, $scopeType, $scopeId, $actorUid);
    }

    /** Remove a person from a scope entirely. */
    public function revoke_all_roles($uid, $scopeType, $scopeId, $actorUid)
    {
        return $this->CmsAuth->RevokeAllRoles($uid, $scopeType, $scopeId, $actorUid);
    }

    /** Would removing this person leave the scope with no role-manager? */
    public function is_last_role_manager($uid, $scopeType, $scopeId)
    {
        return $this->CmsAuth->IsLastRoleManager($uid, $scopeType, $scopeId);
    }
}
