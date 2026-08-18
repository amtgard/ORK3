<?php

class Controller_ReleaseNotes extends Controller
{
    public function __construct($call = null, $method = null)
    {
        parent::__construct($call, $method);
        unset($this->data['menu']['kingdom'], $this->data['menu']['park']);
        $this->data['menu']['releasenotes'] = array('url' => UIR . 'ReleaseNotes', 'display' => 'Release Notes');
        $this->data['no_index'] = true;
    }

    public function index($action = null)
    {
        // `require`, not `require_once`: Controller::__construct already includes this
        // file for logged-in viewers, so require_once would be a no-op here and leave
        // $WHATS_NEW_ITEMS undefined in this scope (fatal in usort below).
        require(DIR_UI . 'whats_new_content.php');

        $this->template = 'ReleaseNotes_index.tpl';
        $this->data['page_title'] = 'ORK Release Notes';
        $this->data['ork_version'] = ORK_VERSION;
        $releases = $WHATS_NEW_ITEMS;
        usort($releases, function ($a, $b) {
            return strcmp($b['date'], $a['date']);
        });
        $this->data['releases'] = $releases;
    }

}
