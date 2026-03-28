<?php

class Controller_Court extends Controller {

    public function __construct($call = null, $id = null) {
        parent::__construct($call, $id);
    }

    // -----------------------------------------------------------------------
    // Court list — standalone page
    // Route: ?Route=Court/list/kingdom/{kingdom_id}
    //        ?Route=Court/list/park/{park_id}
    // -----------------------------------------------------------------------
    public function list($context = null, $id = null) {
        $id = (int)preg_replace('/[^0-9]/', '', $id ?? '');
        $context = ($context === 'park') ? 'park' : 'kingdom';

        $uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

        // Resolve kingdom_id / park_id
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

        if (!valid_id($kingdom_id)) {
            $this->data['Error'] = 'Invalid location.';
            return;
        }

        $canManage = Ork3::$Lib->court->canManage($uid, $kingdom_id, $park_id);

        if (!$canManage) {
            $this->data['Error'] = 'You do not have permission to view the Court Planner.';
            return;
        }

        $courtList     = Ork3::$Lib->court->getCourtList($kingdom_id, $park_id);
        $upcomingEvents = Ork3::$Lib->court->getUpcomingEvents($kingdom_id);

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

        $this->data['CourtList']      = $courtList;
        $this->data['UpcomingEvents'] = $upcomingEvents;
        $this->data['KingdomId']      = $kingdom_id;
        $this->data['ParkId']         = $park_id;
        $this->data['Context']        = $context;
        $this->data['LocationName']   = $locationName;
        $this->data['CanManage']      = $canManage;
        $this->data['Uid']            = $uid;
    }

    // -----------------------------------------------------------------------
    // Court detail — standalone planning page
    // Route: ?Route=Court/detail/{court_id}
    // -----------------------------------------------------------------------
    public function detail($court_id = null) {
        $court_id = (int)preg_replace('/[^0-9]/', '', $court_id ?? '');
        $uid      = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

        if (!valid_id($court_id)) {
            $this->data['Error'] = 'Invalid court.';
            return;
        }

        $court = Ork3::$Lib->court->getCourtDetail($court_id);
        if (!$court) {
            $this->data['Error'] = 'Court not found.';
            return;
        }

        $canManage = Ork3::$Lib->court->canManage($uid, $court['KingdomId'], $court['ParkId']);
        if (!$canManage) {
            $this->data['Error'] = 'You do not have permission to manage this court.';
            return;
        }

        $courtAwards  = Ork3::$Lib->court->getCourtAwards($court_id);
        $pendingRecs  = Ork3::$Lib->court->getPendingRecommendations($court['KingdomId'], $court['ParkId']);
        $awardOptions = Ork3::$Lib->court->getKingdomAwardOptions($court['KingdomId']);

        // Status labels and next-status transitions
        $statusFlow = [
            'draft'     => 'published',
            'published' => 'complete',
            'complete'  => null,
        ];

        // Resolve heraldry: prefer park heraldry if park-scoped, else kingdom
        $heraldryUrl = '';
        $hasHeraldry = false;
        if ($court['ParkId'] > 0) {
            global $DB;
            $DB->Clear();
            $hr = $DB->DataSet('SELECT has_heraldry FROM ' . DB_PREFIX . 'park WHERE park_id = ' . (int)$court['ParkId'] . ' LIMIT 1');
            if ($hr && $hr->Next() && (int)$hr->has_heraldry) {
                $hasHeraldry = true;
                $name = sprintf('%05d', (int)$court['ParkId']);
                $heraldryUrl = file_exists(DIR_PARK_HERALDRY . $name . '.png')
                    ? HTTP_PARK_HERALDRY . $name . '.png'
                    : HTTP_PARK_HERALDRY . $name . '.jpg';
            }
        }
        if (!$hasHeraldry && $court['KingdomId'] > 0) {
            global $DB;
            $DB->Clear();
            $hr = $DB->DataSet('SELECT has_heraldry FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = ' . (int)$court['KingdomId'] . ' LIMIT 1');
            if ($hr && $hr->Next() && (int)$hr->has_heraldry) {
                $hasHeraldry = true;
                $name = sprintf('%04d', (int)$court['KingdomId']);
                $heraldryUrl = file_exists(DIR_KINGDOM_HERALDRY . $name . '.png')
                    ? HTTP_KINGDOM_HERALDRY . $name . '.png'
                    : HTTP_KINGDOM_HERALDRY . $name . '.jpg';
            }
        }

        $this->data['Court']        = $court;
        $this->data['CourtAwards']  = $courtAwards;
        $this->data['PendingRecs']  = $pendingRecs;
        $this->data['AwardOptions'] = $awardOptions;
        $this->data['StatusFlow']   = $statusFlow;
        $this->data['CanManage']    = $canManage;
        $this->data['Uid']          = $uid;
        $this->data['HeraldryUrl']  = $heraldryUrl;
        $this->data['HasHeraldry']  = $hasHeraldry;

        $this->template = 'Court_detail.tpl';
    }
}
