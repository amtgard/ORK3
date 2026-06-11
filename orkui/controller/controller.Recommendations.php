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
        $groups = [];
        foreach ($recs as $rec) {
            $mid = (int)($rec['MundaneId'] ?? 0);
            $kaid = (int)($rec['KingdomAwardId'] ?? 0);
            $rank = (int)($rec['Rank'] ?? 0);
            $key = $mid . ':' . $kaid . ':' . $rank;
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'MundaneId'      => $mid,
                    'KingdomAwardId' => $kaid,
                    'Rank'           => $rank,
                    'Persona'        => $rec['Persona'] ?? '',
                    'AwardName'      => $rec['AwardName'] ?? '',
                    'ParkId'         => (int)($rec['ParkId'] ?? 0),
                    'AlreadyHas'     => !empty($rec['AlreadyHas']),
                    'CurrentRank'    => isset($rec['CurrentRank']) ? (int)$rec['CurrentRank'] : null,
                    'Members'        => [],
                    'MemberRecIds'   => [],
                    'OldestAgeDays'  => 0,
                    'OldestDate'     => $rec['DateRecommended'] ?? '',
                    'RepRecId'       => (int)($rec['RecommendationsId'] ?? 0),
                    '_advocates'     => [],
                    '_allSnoozed'    => true,
                    '_allPassed'     => true,
                ];
            }
            $g = &$groups[$key];
            $g['Members'][]      = $rec;
            $g['MemberRecIds'][] = (int)($rec['RecommendationsId'] ?? 0);
            $age = (int)($rec['AgeDays'] ?? 0);
            if ($age >= $g['OldestAgeDays']) {
                $g['OldestAgeDays'] = $age;
                $g['OldestDate']    = $rec['DateRecommended'] ?? '';
                $g['RepRecId']      = (int)($rec['RecommendationsId'] ?? 0); // oldest = representative
            }
            if (!empty($rec['RecommendedById'])) { $g['_advocates'][(int)$rec['RecommendedById']] = true; }
            foreach (($rec['Seconds'] ?? []) as $s) {
                if (!empty($s['SupporterMundaneId'])) { $g['_advocates'][(int)$s['SupporterMundaneId']] = true; }
            }
            if (empty($rec['IsSnoozed'])) { $g['_allSnoozed'] = false; }
            if (empty($rec['PassedToLocal'])) { $g['_allPassed'] = false; }
            unset($g);
        }
        foreach ($groups as $k => $g) {
            unset($g['_advocates'][$g['MundaneId']]); // a self-rec advocate never counts
            $groups[$k]['SupportCount'] = count($g['_advocates']);
            $groups[$k]['IsSnoozed']    = $g['_allSnoozed']; // cluster snoozed only if every member is
            $groups[$k]['PassedToLocal'] = $g['_allPassed'];
            unset($groups[$k]['_advocates'], $groups[$k]['_allSnoozed'], $groups[$k]['_allPassed']);
        }
        $this->data['Groups'] = array_values($groups);

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
}
