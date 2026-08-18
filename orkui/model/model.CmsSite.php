<?php

// orkui/model/model.CmsSite.php — thin pass-through to the CmsSite lib.
// One snake_case wrapper per PascalCase lib method; pure forwards, no logic.
// (Model::__call would auto-forward unknown methods to the same APIModel; the
// explicit wrappers exist only to mirror signatures for clarity.)
// Calling convention: call the snake_case wrapper where one exists; a
// PascalCase reach-around via Model::__call is the sanctioned form for lib
// methods that have no wrapper.
class Model_CmsSite extends Model
{
    public function get_site_by_slug($slug)
    {
        return $this->CmsSite->GetSiteBySlug($slug);
    }
    public function get_site_for_scope($scopeType, $scopeId)
    {
        return $this->CmsSite->GetSiteForScope($scopeType, $scopeId);
    }
    public function org_unit_noun($scopeType, $scopeId)
    {
        return $this->CmsSite->OrgUnitNoun($scopeType, $scopeId);
    }
    public function org_display_name($scopeType, $scopeId)
    {
        return $this->CmsSite->OrgDisplayName($scopeType, $scopeId);
    }
    public function list_all_sites()
    {
        return $this->CmsSite->ListAllSites();
    }
    public function global_page_counts()
    {
        return $this->CmsSite->GlobalPageCounts();
    }
    public function published_slug_map_by_scope($scopeType)
    {
        return $this->CmsSite->PublishedSlugMapByScope($scopeType);
    }
    public function ensure_site($scopeType, $scopeId, $uid)
    {
        return $this->CmsSite->EnsureSite($scopeType, $scopeId, $uid);
    }
    public function set_published($siteId, $uid)
    {
        return $this->CmsSite->SetPublished($siteId, $uid);
    }
    public function set_draft($siteId, $uid)
    {
        return $this->CmsSite->SetDraft($siteId, $uid);
    }
    public function update_site($siteId, $fields, $uid)
    {
        return $this->CmsSite->UpdateSite($siteId, $fields, $uid);
    }
    /** @no-callers — mirror surface. */
    public function derive_slug($name)
    {
        return $this->CmsSite->DeriveSlug($name);
    }
    /** @no-callers — mirror surface. */
    public function validate_slug($slug, $exceptSiteId = 0)
    {
        return $this->CmsSite->ValidateSlug($slug, $exceptSiteId);
    }
}
