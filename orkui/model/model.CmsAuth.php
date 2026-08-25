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

    public function get_user_capabilities($uid, $scope)
    {
        return $this->CmsAuth->GetUserCapabilities($uid, $scope);
    }

    /** Callers: Controller_Cms::_capList() (super-admin branch, :1078) and ::_bridgedCaps() (officer bridge, non-super, :1119). */
    public function all_capabilities()
    {
        return $this->CmsAuth->AllCapabilities();
    }
}
