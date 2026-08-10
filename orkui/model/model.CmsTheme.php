<?php

// orkui/model/model.CmsTheme.php — thin pass-through to the CmsTheme lib.
// Calling convention: call the snake_case wrapper where one exists; a
// PascalCase reach-around via Model::__call is the sanctioned form for lib
// methods that have no wrapper.
class Model_CmsTheme extends Model
{
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
    public function preview_css($tokens)
    {
        return $this->CmsTheme->PreviewCss($tokens);
    }
    public function catalog()
    {
        return $this->CmsTheme->Catalog();
    }
    public function font_allowlist()
    {
        return $this->CmsTheme->FontAllowlist();
    }
    public function base_values()
    {
        return $this->CmsTheme->BaseValues();
    }
}
