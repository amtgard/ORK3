<?php

class Controller_Recommendations extends Controller {

    public function __construct($call = null, $id = null) {
        parent::__construct($call, $id);
    }

    // Route: ?Route=Recommendations/manage/kingdom/{kingdom_id}
    //        ?Route=Recommendations/manage/park/{park_id}
    public function manage($context = null, $id = null) {
        $this->template = '../revised-frontend/Recommendations_manage.tpl';

        // When route has 4+ segments (e.g. manage/kingdom/6), index.php joins
        // segments 2+ into a single string ('kingdom/6') passed as $context.
        // Split it out here so both calling conventions work.
        if ($id === null && $context !== null && strpos($context, '/') !== false) {
            $parts   = explode('/', $context, 2);
            $context = $parts[0];
            $id      = $parts[1] ?? '';
        }
        $id      = (int)preg_replace('/[^0-9]/', '', $id ?? '');
        $context = ($context === 'park') ? 'park' : 'kingdom';
        $uid     = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

        $kingdom_id = 0;
        $park_id    = 0;
        if ($context === 'park') {
            $park_id = $id;
            global $DB;
            $DB->Clear();
            $pr = $DB->DataSet('SELECT kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = ' . $park_id . ' LIMIT 1');
            if ($pr && $pr->Next()) $kingdom_id = (int)$pr->kingdom_id;
        } else {
            $kingdom_id = $id;
        }

        if (!valid_id($kingdom_id)) { $this->data['Error'] = 'Invalid location.'; return; }

        if (!Ork3::$Lib->court->canManage($uid, $kingdom_id, $park_id)) {
            $this->data['Error'] = 'You do not have permission to manage recommendations.';
            return;
        }

        // Location name
        $locationName = '';
        global $DB;
        if ($park_id > 0) {
            $DB->Clear();
            $lr = $DB->DataSet('SELECT name FROM ' . DB_PREFIX . 'park WHERE park_id = ' . $park_id . ' LIMIT 1');
            if ($lr && $lr->Next()) $locationName = $lr->name;
        } else {
            $DB->Clear();
            $lr = $DB->DataSet('SELECT name FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = ' . $kingdom_id . ' LIMIT 1');
            if ($lr && $lr->Next()) $locationName = $lr->name;
        }

        // Data wired in Task 3 (placeholder empties keep the page renderable now).
        $this->data['Recommendations'] = [];
        $this->data['CourtMap']        = [];
        $this->data['Courts']          = [];
        $this->data['Parks']           = [];

        $this->data['KingdomId']    = $kingdom_id;
        $this->data['ParkId']       = $park_id;
        $this->data['Context']      = $context;
        $this->data['LocationName'] = $locationName;
        $this->data['Uid']          = $uid;
    }
}
