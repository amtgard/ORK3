<?php

class Controller_Directory extends Controller
{
    // The Kingdoms Directory — formerly the home page. Reuses the base
    // Controller::index() data loads (kingdom summary, events, recap, home-kingdom
    // pinning), then renders Directory_index.tpl.
    public function index($action = null)
    {
        parent::index($action);
        $this->data[ 'page_title' ] = 'Kingdoms Directory';
        // We do not need the front-door payload here.
        $this->data[ 'IsFrontDoor' ] = false;
        // The Directory is NOT the CMS home page — drop the home-edit FAB flag
        // that the base index() set so the editor FAB doesn't appear here.
        unset($this->data[ 'cmsEditUrl' ], $this->data[ 'cmsEditTip' ]);

        // #124: parent::index() also set $PageMeta to the HOME canonical/og (site
        // root). The Directory is a distinct surface, so overwrite it with its own
        // canonical — otherwise this page would falsely canonicalize to the home
        // root (duplicate-content ambiguity).
        $this->data[ 'PageMeta' ] = array(
            'canonical'   => UIR . 'Directory',
            'og_type'     => 'website',
            'og_title'    => 'Kingdoms Directory — Amtgard Online Record Keeper',
            'og_desc'     => 'Browse Amtgard kingdoms and their parks in the Online Record Keeper.',
            'og_image'    => '',
            'og_sitename' => 'ORK 3 - Amtgard Online Record Keeper',
        );

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
        } catch (Exception $e) {
            $siteSlugs = array();
        }
        $this->data[ 'KingdomSiteSlugs' ] = $siteSlugs;
    }
}
