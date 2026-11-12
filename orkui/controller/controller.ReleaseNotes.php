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
        // Pinned releases stay on top; the rest run newest first. An 'orkple' release
        // (ORK 0.1, the ORKPLE II event) is pinned only while the event is live.
        $live = !empty($this->data['OrkpleLive']);
        $pinned = function ($r) use ($live) {
            return (int) (!empty($r['pinned']) && (empty($r['orkple']) || $live));
        };
        usort($releases, function ($a, $b) use ($pinned) {
            $pin = $pinned($b) - $pinned($a);
            return $pin !== 0 ? $pin : strcmp($b['date'], $a['date']);
        });
        foreach ($releases as &$release) {
            $release['anchor'] = self::release_anchor($release['version']);
        }
        unset($release);
        $this->data['releases'] = $releases;
        // Outside the event the theme still loads the ORKPLE launcher here (without the
        // floppy), so the ORK 0.1 Insert Floppy Disk button works year-round.
        $this->data['OrkpleInsertOnly'] = true;
    }

}
