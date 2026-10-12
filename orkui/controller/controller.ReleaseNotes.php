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
        // `require`, not `require_once`. The base Controller already reads this
        // file for every logged-in user (to decide whether to show the What's New
        // modal), which makes require_once here a no-op -- leaving $WHATS_NEW_ITEMS
        // undefined in THIS scope and handing usort() a null. The page therefore
        // returned a 500 to every signed-in visitor while still rendering fine for
        // anonymous ones, who never trigger the earlier read. The file guards its
        // own define()s, so reading it again is safe.
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
