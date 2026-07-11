<?php

class Controller_Recommendations extends Controller
{
    public function __construct($call = null, $id = null)
    {
        parent::__construct($call, $id);
    }

    // Route: ?Route=Recommendations/manage/kingdom/{kingdom_id}
    //        ?Route=Recommendations/manage/park/{park_id}
    public function manage($context = null, $id = null)
    {
        $this->template = '../revised-frontend/Recommendations_manage.tpl';

        // Parse route + resolve/authorize scope (shared with rows()).
        [$kingdom_id, $park_id, $context, $uid, $authStatus] = $this->resolveContext($context, $id);

        if ($authStatus === 'invalid') {
            $this->data['Error'] = 'Invalid location.';
            return;
        }
        if ($authStatus === 'forbidden') {
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
        $courtMap = $this->rmCourtMap($kingdom_id, $park_id);

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
        // Granting officer's persona — the default "Given By" in the Grant Award modal.
        $me = $uid > 0 ? Ork3::$Lib->player->player_info($uid) : false;
        $this->data['UserName'] = is_array($me) ? ($me['Persona'] ?? '') : '';

        // Preloaded Monarch/Regent officers — quick-pick chips for "Given By" in the
        // Grant Award modal (park officers first, then kingdom officers).
        $token           = $this->session->token;
        $preloadOfficers = [];
        $addOfficers = function ($officers, $rolePrefix) use (&$preloadOfficers) {
            foreach ((array)($officers['Officers'] ?? []) as $o) {
                if (in_array($o['OfficerRole'] ?? '', ['Monarch', 'Regent'], true) && (int)($o['MundaneId'] ?? 0) > 0) {
                    $preloadOfficers[] = [
                        'MundaneId' => (int)$o['MundaneId'],
                        'Persona'   => $o['Persona'] ?? '',
                        'Role'      => $rolePrefix . $o['OfficerRole'],
                    ];
                }
            }
        };
        if ($park_id > 0) {
            $addOfficers(Ork3::$Lib->park->GetOfficers(['ParkId' => $park_id, 'Token' => $token]), '');
        }
        $addOfficers(Ork3::$Lib->kingdom->GetOfficers(['KingdomId' => $kingdom_id, 'Token' => $token]), $park_id > 0 ? 'Kingdom ' : '');
        $this->data['PreloadOfficers'] = $preloadOfficers;
    }

    // Route: ?Route=Recommendations/rows/kingdom/{id} or /rows/park/{id}  (GET: filters/sort/offset)
    // Returns one 500-row JSON batch of rendered <tr class="rm-row"> partials.
    public function rows($context = null, $id = null)
    {
        // Parse route + resolve/authorize scope (shared with manage()).
        [$kingdom_id, $park_id, $context, $uid, $authStatus] = $this->resolveContext($context, $id);

        header('Content-Type: application/json');
        if ($authStatus !== null) {
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

        $CourtMap = $this->rmCourtMap($kingdom_id, $park_id);
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

    // Route: ?Route=Recommendations/export/kingdom/{id} or /export/park/{id}  (GET: same filters as rows)
    // Streams the FULL current filtered/sorted set (not paged) as a CSV download.
    public function export($context = null, $id = null)
    {
        [$kingdom_id, $park_id, $context, $uid, $authStatus] = $this->resolveContext($context, $id);
        if ($authStatus !== null) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not authorized.';
            exit;
        }

        // Assembling the full set for a large kingdom hydrates thousands of recs in one
        // pass, so give it headroom over the default request time limit.
        @set_time_limit(120);

        $this->load_model('Reports');
        $page = $this->Reports->recommended_awards_page([
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
            'Limit'       => 1000000, // full set — export is never paged
            'Offset'      => 0,
        ]);
        $groups   = is_array($page['Groups'] ?? null) ? $page['Groups'] : [];
        $courtMap = $this->rmCourtMap($kingdom_id, $park_id);
        $parks    = $this->rmParkMap($kingdom_id);

        $scope = $park_id > 0 ? 'park-' . $park_id : 'kingdom-' . $kingdom_id;
        $fname = 'recommendations-' . $scope . '-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Cache-Control: no-store');

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accented personas correctly
        fputcsv($out, [
            'Recipient', 'Park', 'Award', 'Rank', 'Recommended By', 'Date', 'Age (days)',
            'Support', 'Already Has', 'Snoozed', 'Passed To Local', 'On Court', 'Reason',
        ]);

        foreach ($groups as $g) {
            $rank      = (int)($g['Rank'] ?? 0);
            $rankLabel = $rank > 0 ? (string)$rank : 'non-ladder';
            $parkName  = $parks[(int)($g['ParkId'] ?? 0)]['Name'] ?? '';

            // Recommenders across the cluster (unique, anonymous shown as "Anonymous").
            $names = [];
            foreach (($g['Members'] ?? []) as $m) {
                $n = $m['RecommendedByName'] ?? null;
                if ($n === null || $n === '') {
                    if (!empty($m['IsAnonymous'])) {
                        $n = 'Anonymous';
                    } else {
                        continue;
                    }
                }
                $names[$n] = true;
            }

            // Court plan names this cluster's recs sit on (if any).
            $courtNames = [];
            foreach (($g['MemberRecIds'] ?? []) as $rid) {
                foreach (($courtMap[$rid] ?? []) as $c) {
                    if (!empty($c['Name'])) {
                        $courtNames[$c['Name']] = true;
                    }
                }
            }

            fputcsv($out, [
                $g['Persona'] ?? '',
                $parkName,
                $g['AwardName'] ?? '',
                $rankLabel,
                implode('; ', array_keys($names)),
                $g['OldestDate'] ?? '',
                (int)($g['OldestAgeDays'] ?? 0),
                (int)($g['SupportCount'] ?? 0),
                !empty($g['AlreadyHas']) ? 'Yes' : 'No',
                !empty($g['IsSnoozed']) ? 'Yes' : 'No',
                !empty($g['PassedToLocal']) ? 'Yes' : 'No',
                implode('; ', array_keys($courtNames)),
                $g['Members'][0]['Reason'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }

    // Parse the route (`kingdom/6` joined segment) + resolve the scope ids, then
    // run the valid_id/canManage guard once. Shared by manage() and rows() so the
    // auth check can't diverge. Returns [kingdom_id, park_id, context, uid, status]
    // where $status is null (ok), 'invalid' (bad location) or 'forbidden' (no perm).
    private function resolveContext($context, $id)
    {
        // When route has 4+ segments (e.g. manage/kingdom/6), index.php joins
        // segments 2+ into a single string ('kingdom/6') passed as $context.
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

        $status = null;
        if (!valid_id($kingdom_id)) {
            $status = 'invalid';
        } elseif (!Ork3::$Lib->court->canManage($uid, $kingdom_id, $park_id)) {
            $status = 'forbidden';
        }
        return [$kingdom_id, $park_id, $context, $uid, $status];
    }

    // Court-membership map for the scope. DB lives in the lib
    // (Court::getRecommendationCourtMap); this is a thin scope-typed accessor.
    private function rmCourtMap($kingdom_id, $park_id)
    {
        return Ork3::$Lib->court->getRecommendationCourtMap($kingdom_id, $park_id);
    }

    // Park map for the kingdom-scope filter + row abbrev. DB lives in the lib
    // (Kingdom::GetParks); this only reshapes the result into pid => Name/Abbrev.
    private function rmParkMap($kingdom_id)
    {
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
