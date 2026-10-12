<?php

/**
 * Model_CmsTheme — thin pass-through to the CmsTheme lib.
 *
 * The base Model constructor auto-instantiates new APIModel('CmsTheme')
 * (because system/lib/ork3/class.CmsTheme.php exists), and Model::__call
 * forwards any unknown method to it. The explicit methods below mirror the
 * lib surface for clarity; all are pure forwards (no business logic here —
 * DB work lives in the lib).
 *
 * Calling convention: call the snake_case wrapper where one exists; a
 * PascalCase reach-around via Model::__call is the sanctioned form for lib
 * methods that have no wrapper.
 */
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

    public function get_active_root_css($scopeType = 'global', $scopeId = 0)
    {
        return $this->CmsTheme->GetActiveRootCss($scopeType, $scopeId);
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

    public function get_active_font_href($scopeType = 'global', $scopeId = 0)
    {
        return $this->CmsTheme->GetActiveFontHref($scopeType, $scopeId);
    }

    public function get_active_font_query($scopeType = 'global', $scopeId = 0)
    {
        return $this->CmsTheme->GetActiveFontQuery($scopeType, $scopeId);
    }

    public function font_catalog()
    {
        return $this->CmsTheme->FontCatalog();
    }

    public function fonts_for_role($role)
    {
        return $this->CmsTheme->FontsForRole($role);
    }

    public function base_values()
    {
        return $this->CmsTheme->BaseValues();
    }
}
