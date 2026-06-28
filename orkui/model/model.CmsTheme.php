<?php

// orkui/model/model.CmsTheme.php — thin pass-through to the CmsTheme lib.
class Model_CmsTheme extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->CmsTheme = new APIModel('CmsTheme');
    }

    public function get_active_theme($scopeType = 'global', $scopeId = 0)
    {
        return $this->CmsTheme->GetActiveTheme($scopeType, $scopeId);
    }
    public function get_active_css($scopeType = 'global', $scopeId = 0)
    {
        return $this->CmsTheme->GetActiveCss($scopeType, $scopeId);
    }
    public function save_theme($scopeType, $scopeId, $name, $tokens, $uid)
    {
        return $this->CmsTheme->SaveTheme($scopeType, $scopeId, $name, $tokens, $uid);
    }
    public function set_active($scopeType, $scopeId, $id)
    {
        return $this->CmsTheme->SetActive($scopeType, $scopeId, $id);
    }
    public function reset_active($scopeType, $scopeId)
    {
        return $this->CmsTheme->ResetActive($scopeType, $scopeId);
    }
}
