<?php

class Controller_Court extends Controller
{
    public function __construct($call = null, $id = null)
    {
        parent::__construct($call, $id);
    }

    // -----------------------------------------------------------------------
    // Court list — standalone page
    // Route: ?Route=Court/list/kingdom/{kingdom_id}
    //        ?Route=Court/list/park/{park_id}
    // -----------------------------------------------------------------------
    public function list($context = null, $id = null)
    {
        $id = (int)preg_replace('/[^0-9]/', '', $id ?? '');
        $context = ($context === 'park') ? 'park' : 'kingdom';

        $uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

        // Resolve kingdom_id / park_id
        $kingdom_id = 0;
        $park_id    = 0;

        if ($context === 'park') {
            $park_id = $id;
            $kingdom_id = (int)Ork3::$Lib->park->GetParkKingdomId($park_id);
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
        if ($park_id > 0) {
            $pInfo = Ork3::$Lib->park->GetParkShortInfo(['ParkId' => $park_id]);
            if (isset($pInfo['ParkInfo']['ParkName'])) {
                $locationName = $pInfo['ParkInfo']['ParkName'];
            }
        } else {
            $kInfo = Ork3::$Lib->kingdom->GetKingdomShortInfo(['KingdomId' => $kingdom_id]);
            if (isset($kInfo['KingdomInfo']['KingdomName'])) {
                $locationName = $kInfo['KingdomInfo']['KingdomName'];
            }
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
    public function detail($court_id = null)
    {
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
        $pendingRecs  = Ork3::$Lib->court->getPendingRecommendations($court['KingdomId'], $court['ParkId'], $uid, $court_id);
        $awardOptions = Ork3::$Lib->court->getKingdomAwardOptions($court['KingdomId']);

        // Status labels and next-status transitions
        $statusFlow = [
            'draft'     => 'published',
            'published' => 'complete',
            'complete'  => null,
        ];

        // Resolve heraldry: prefer park heraldry if park-scoped, else kingdom.
        // Use the Heraldry lib so the ?v=filemtime cache-buster is preserved.
        $heraldryUrl = '';
        $hasHeraldry = false;
        if ($court['ParkId'] > 0) {
            $pInfo = Ork3::$Lib->park->GetParkShortInfo(['ParkId' => (int)$court['ParkId']]);
            if (!empty($pInfo['ParkInfo']['HasHeraldry'])) {
                $hasHeraldry = true;
                $h = Ork3::$Lib->heraldry->GetHeraldryUrl(['Type' => 'Park', 'Id' => (int)$court['ParkId']]);
                $heraldryUrl = $h['Url'] ?? '';
            }
        }
        if (!$hasHeraldry && $court['KingdomId'] > 0) {
            $kInfo = Ork3::$Lib->kingdom->GetKingdomShortInfo(['KingdomId' => (int)$court['KingdomId']]);
            if (!empty($kInfo['KingdomInfo']['HasHeraldry'])) {
                $hasHeraldry = true;
                $h = Ork3::$Lib->heraldry->GetHeraldryUrl(['Type' => 'Kingdom', 'Id' => (int)$court['KingdomId']]);
                $heraldryUrl = $h['Url'] ?? '';
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
