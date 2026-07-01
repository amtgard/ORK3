<?php

// orkui/model/model.CmsSite.php — thin pass-through to the CmsSite lib.
// One snake_case wrapper per PascalCase lib method; pure forwards, no logic.
// (Model::__call would auto-forward unknown methods to the same APIModel; the
// explicit wrappers exist only to mirror signatures for clarity.)
class Model_CmsSite extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->CmsSite = new APIModel('CmsSite');
    }

    public function get_site_by_slug($slug)
    {
        return $this->CmsSite->GetSiteBySlug($slug);
    }
    public function get_site_for_scope($scopeType, $scopeId)
    {
        return $this->CmsSite->GetSiteForScope($scopeType, $scopeId);
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
    public function derive_slug($name)
    {
        return $this->CmsSite->DeriveSlug($name);
    }
    public function validate_slug($slug, $exceptSiteId = 0)
    {
        return $this->CmsSite->ValidateSlug($slug, $exceptSiteId);
    }
}
