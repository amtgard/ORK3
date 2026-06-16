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
            $park_id    = $id;
            $kingdom_id = (int)Ork3::$Lib->park->GetParkKingdomId($park_id);
        } else {
            $kingdom_id = $id;
        }

        if (!valid_id($kingdom_id)) { $this->data['Error'] = 'Invalid location.'; return; }

        if (!Ork3::$Lib->court->canManage($uid, $kingdom_id, $park_id)) {
            $this->data['Error'] = 'You do not have permission to manage recommendations.';
            return;
        }

        // Location name (DB lives in the lib short-info getters).
        $locationName = '';
        if ($park_id > 0) {
            $pi = Ork3::$Lib->park->GetParkShortInfo(['ParkId' => $park_id]);
            $locationName = $pi['ParkInfo']['ParkName'] ?? '';
        } else {
            $ki = Ork3::$Lib->kingdom->GetKingdomShortInfo(['KingdomId' => $kingdom_id]);
            $locationName = $ki['KingdomInfo']['KingdomName'] ?? '';
        }

        // First 500-row batch for the scope (server-side filtered/sorted/paged).
        // Defaults mirror the recs-tab pills: eligibility 'open', sort by date desc.
        $this->load_model('Reports');
        $page = $this->Reports->recommended_awards_page([
            'RequestedBy' => $uid,
            'KingdomId'   => $park_id > 0 ? 0 : $kingdom_id,
            'ParkId'      => $park_id,
            'Eligibility' => 'open',
            'SortKey'     => 'date',
            'SortDir'     => 'desc',
            'Limit'       => 500,
            'Offset'      => 0,
        ]);
        $this->data['Groups']     = $page['Groups'];
        $this->data['Total']      = (int)$page['Total'];
        $this->data['HasMore']    = (bool)$page['HasMore'];
        $this->data['NextOffset'] = (int)$page['NextOffset'];

        // Court membership per rec (badges + court filter).
        $courtMap = Ork3::$Lib->court->getRecommendationCourtMap($kingdom_id, $park_id);

        // Courts in scope (Add-to-Court existing-court picker + specific-court filter).
        $courts = Ork3::$Lib->court->getCourtList($kingdom_id, $park_id);

        $this->data['CourtMap'] = $courtMap;
        $this->data['Courts']   = $courts;
        // Parks in the kingdom (kingdom-scope park filter + abbrev lookup); DB in lib.
        $this->data['Parks']    = $this->rmParkMap($kingdom_id);

        $this->data['KingdomId']    = $kingdom_id;
        $this->data['ParkId']       = $park_id;
        $this->data['Context']      = $context;
        $this->data['LocationName'] = $locationName;
        $this->data['Uid']          = $uid;
    }

    // Route: ?Route=Recommendations/rows/kingdom/{id} or /rows/park/{id}  (GET: filters/sort/offset)
    // Returns one 500-row JSON batch of rendered <tr class="rm-row"> partials.
    public function rows($context = null, $id = null) {
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
            $park_id    = $id;
            $kingdom_id = (int)Ork3::$Lib->park->GetParkKingdomId($park_id);
        } else {
            $kingdom_id = $id;
        }

        header('Content-Type: application/json');
        if (!valid_id($kingdom_id) || !Ork3::$Lib->court->canManage($uid, $kingdom_id, $park_id)) {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            exit;
        }

        $req = [
            'RequestedBy' => $uid,
            'KingdomId'   => $park_id > 0 ? 0 : $kingdom_id,
            'ParkId'      => $park_id,
            'Search'      => (string)($_GET['search'] ?? ''),
            'Eligibility' => (string)($_GET['elig'] ?? 'open'),
            'Court'       => (string)($_GET['court'] ?? 'all'),
            'Park'        => (string)($_GET['park'] ?? 'all'),
            'PassLocal'   => !empty($_GET['passlocal']),
            'SortKey'     => (string)($_GET['sort'] ?? 'date'),
            'SortDir'     => (string)($_GET['dir'] ?? 'desc'),
            'Limit'       => 500,
            'Offset'      => max(0, (int)($_GET['offset'] ?? 0)),
        ];

        $this->load_model('Reports');
        $page = $this->Reports->recommended_awards_page($req);

        $CourtMap = Ork3::$Lib->court->getRecommendationCourtMap($kingdom_id, $park_id);
        $Parks    = $this->rmParkMap($kingdom_id);
        $Context  = $context;

        $html = '';
        foreach ($page['Groups'] as $group) {
            ob_start();
            include DIR_TEMPLATE . 'revised-frontend/_rm_row.tpl';
            $html .= ob_get_clean();
        }
        echo json_encode([
            'html'    => $html,
            'total'   => (int)$page['Total'],
            'hasMore' => (bool)$page['HasMore'],
            'offset'  => (int)$page['NextOffset'],
        ]);
        exit;
    }

    // Park map for the kingdom-scope filter + row abbrev. DB lives in the lib
    // (Kingdom::GetParks); this only reshapes the result.
    private function rmParkMap($kingdom_id) {
        $map = [];
        $res = Ork3::$Lib->kingdom->GetParks(['KingdomId' => (int)$kingdom_id]);
        $rows = (isset($res['Parks']) && is_array($res['Parks'])) ? $res['Parks'] : [];
        foreach ($rows as $p) {
            $pid = (int)($p['ParkId'] ?? $p['park_id'] ?? 0);
            if ($pid) {
                $map[$pid] = [
                    'Name'   => $p['Name'] ?? $p['name'] ?? '',
                    'Abbrev' => $p['Abbreviation'] ?? $p['abbreviation'] ?? $p['Abbrev'] ?? '',
                ];
            }
        }
        return $map;
    }
}
