<?php

/**
 * Model_CmsSite — thin pass-through to the CmsSite lib.
 *
 * The base Model constructor auto-instantiates new APIModel('CmsSite')
 * (because system/lib/ork3/class.CmsSite.php exists), and Model::__call
 * forwards any unknown method to it. The explicit methods below mirror the
 * lib surface for clarity; all are pure forwards (no business logic here —
 * DB work lives in the lib).
 *
 * Calling convention: call the snake_case wrapper where one exists; a
 * PascalCase reach-around via Model::__call is the sanctioned form for lib
 * methods that have no wrapper.
 */
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

    /* ---- Site-creation policy (the three rollout toggles) ---- */

    public function kingdom_sites_enabled()
    {
        return $this->CmsSite->KingdomSitesEnabled();
    }

    public function park_sites_enabled()
    {
        return $this->CmsSite->ParkSitesEnabled();
    }

    public function kingdom_allows_park_sites($kingdomId)
    {
        return $this->CmsSite->KingdomAllowsParkSites($kingdomId);
    }

    public function set_kingdom_sites_enabled($on)
    {
        return $this->CmsSite->SetKingdomSitesEnabled($on);
    }

    public function set_park_sites_enabled($on)
    {
        return $this->CmsSite->SetParkSitesEnabled($on);
    }

    public function set_kingdom_allows_park_sites($kingdomId, $on)
    {
        return $this->CmsSite->SetKingdomAllowsParkSites($kingdomId, $on);
    }

    public function can_create_site($scopeType, $scopeId)
    {
        return $this->CmsSite->CanCreateSite($scopeType, $scopeId);
    }
}
