<?php

class Controller_Directory extends Controller
{
    // The Kingdoms Directory — formerly the home page. Reuses the base
    // Controller::index() data loads (kingdom summary, events, recap, home-kingdom
    // pinning), then renders Directory_index.tpl.
    public function index($action = null)
    {
        // Half 1 of the base index() ONLY — the shared summaries. The front-door
        // half is deliberately not called: the Directory is an in-app ORK surface,
        // so it wants none of the front door's payload, its "Amtgard - {Page}" tab
        // identity, its home-page edit FAB or its home canonical. Skipping the call
        // is what makes those absent; there is nothing left here to unset.
        $this->_indexCommonData();
        $this->data[ 'page_title' ] = 'Kingdoms Directory';

        // The Directory is a distinct surface, so it publishes its OWN
        // canonical — otherwise it would falsely canonicalize to the site root
        // (duplicate-content ambiguity).
        $this->data[ 'PageMeta' ] = CmsMeta::Build(array(
            'canonical'   => UIR . 'Directory',
            'og_type'     => 'website',
            'og_title'    => 'Kingdoms Directory — Amtgard Online Record Keeper',
            'og_desc'     => 'Browse Amtgard kingdoms and their parks in the Online Record Keeper.',
            'og_sitename' => self::APP_BRAND,
        ));

        // Discovery: prefetch published kingdom-site slugs in ONE query (avoids an
        // N+1 per-card GetSiteForScope). The template renders a "Visit site" link
        // for any kingdom present in this [kingdom_id => slug] map.
        $siteSlugs = array();
        try {
            $this->load_model('CmsSite');
            $map = $this->CmsSite->published_slug_map_by_scope('kingdom');
            if (is_array($map)) {
                $siteSlugs = $map;
            }
        } catch (\Throwable $e) {
            $siteSlugs = array();
        }
        $this->data[ 'KingdomSiteSlugs' ] = $siteSlugs;
    }
}
