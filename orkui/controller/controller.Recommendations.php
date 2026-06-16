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

        // Recommendation rows (full pending set for the scope, with seconds + notes).
        $this->load_model('Reports');
        $req = ['RequestedBy' => $uid, 'PlayerId' => 0];
        if ($park_id > 0) {
            $req['ParkId']    = $park_id;
            $req['KingdomId'] = 0;
        } else {
            $req['KingdomId'] = $kingdom_id;
            $req['ParkId']    = 0;
        }
        $recs = $this->Reports->recommended_awards($req);
        if (!is_array($recs)) { $recs = []; }

        // Group parallel recommendations by (recipient, kingdomaward, rank). Non-destructive:
        // the underlying rec rows are untouched; the grid renders one row per cluster.
        // Cluster-grouping is the shared Report::groupRecommendations() transform.
        $this->data['Groups'] = $this->Reports->group_recommendations($recs);

        // Court membership per rec (badges + court filter).
        $courtMap = Ork3::$Lib->court->getRecommendationCourtMap($kingdom_id, $park_id);

        // Courts in scope (Add-to-Court existing-court picker + specific-court filter).
        $courts = Ork3::$Lib->court->getCourtList($kingdom_id, $park_id);

        // Parks in the kingdom (kingdom-scope park filter + abbrev lookup).
        $parks = [];
        global $DB;
        $DB->Clear();
        $prs = $DB->DataSet('SELECT park_id, name, abbreviation FROM ' . DB_PREFIX . 'park WHERE kingdom_id = ' . (int)$kingdom_id . ' ORDER BY name ASC');
        if ($prs) { while ($prs->Next()) { $parks[(int)$prs->park_id] = ['Name' => $prs->name, 'Abbrev' => $prs->abbreviation]; } }

        $this->data['Recommendations'] = $recs;
        $this->data['CourtMap']        = $courtMap;
        $this->data['Courts']          = $courts;
        $this->data['Parks']           = $parks;

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
