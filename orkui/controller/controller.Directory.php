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
    }
}
