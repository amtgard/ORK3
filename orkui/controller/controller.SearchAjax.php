<?php

class Controller_SearchAjax extends Controller
{
    public function universal($p = null)
    {
        header('Content-Type: application/json');

        $q = trim($_GET['q'] ?? '');
        if (strlen($q) < 2) {
            echo json_encode(['players' => [], 'parks' => [], 'kingdoms' => [], 'units' => []]);
            exit;
        }

        $this->load_model('Search');
        $results = $this->Search->universal_search([
            'Query'           => $q,
            'Kid'             => (int)($_GET['kid'] ?? 0),
            'Pid'             => (int)($_GET['pid'] ?? 0),
            'FilterKid'       => (int)($_GET['fk'] ?? 0),
            'FilterPid'       => (int)($_GET['fp'] ?? 0),
            'IncludeInactive' => !empty($_GET['inactive']),
            'Focus'           => trim($_GET['focus'] ?? ''),
            'CallerUserId'    => isset($this->session->user_id) ? (int)$this->session->user_id : 0,
            'Token'           => $this->session->token ?? null,
        ]);

        echo json_encode($results);
        exit;
    }

    public function players($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            // Envelope shape kept consistent so the component never has to special-case auth loss.
            echo json_encode(['rows' => [], 'hasMore' => false, 'offset' => 0]);
            exit;
        }

        $limit  = min(max((int)($_GET['limit'] ?? 15), 1), 100);
        $offset = min(max((int)($_GET['offset'] ?? 0), 0), 2000);

        $svc  = new SearchService();
        // Probe one extra row to know whether a "Load more…" page exists, without a second COUNT query.
        $rows = $svc->RankedPlayers([
            'q'                  => $_GET['q'] ?? '',
            'parkId'             => (int)($_GET['parkId']    ?? 0) ?: null,
            'kingdomId'          => (int)($_GET['kingdomId'] ?? 0) ?: null,
            'restrictTo'         => $_GET['restrictTo'] ?? '',
            'restrictKingdomIds' => $this->csvIds('restrictKingdomIds'),
            'excludeKingdomId'   => (int)($_GET['excludeKingdomId'] ?? 0) ?: null,
            'excludeParkId'      => (int)($_GET['excludeParkId']    ?? 0) ?: null,
            'excludeIds'         => $this->csvIds('excludeIds'),
            // Filter pill: narrows within the hard scope above, never widens it.
            'filterKingdomId'    => (int)($_GET['filterKingdomId'] ?? 0),
            'filterParkId'       => (int)($_GET['filterParkId']    ?? 0),
            // Banned scope is derived server-side from the viewer's grants; the client may only
            // narrow it ('kingdom'), never widen it.
            'bannedScope'        => (($_GET['bannedScope'] ?? '') === 'kingdom') ? 'kingdom' : '',
            'limit'              => $limit + 1,
            'offset'             => $offset,
            'token'              => $this->session->token ?? null,
        ]);

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }
        echo json_encode(['rows' => $rows, 'hasMore' => $hasMore, 'offset' => $offset]);
        exit;
    }

    // Rich, filterable player search behind the Advanced Search page. Returns
    // { rows, hasMore, offset, canSeeRealName, canSeeBanned, needFilter }.
    public function advanced($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            // needLogin lets the page show a sign-in prompt instead of "No players found".
            echo json_encode(['rows' => [], 'hasMore' => false, 'offset' => 0, 'canSeeRealName' => 0, 'canSeeBanned' => 0, 'needLogin' => 1]);
            exit;
        }
        $svc = new SearchService();
        echo json_encode($svc->AdvancedPlayers([
            'q'                  => $_GET['q'] ?? '',
            'kingdomId'          => (int)($_GET['kingdomId'] ?? 0) ?: null,
            'parkId'             => (int)($_GET['parkId'] ?? 0) ?: null,
            'includeActive'      => isset($_GET['includeActive']) ? !empty($_GET['includeActive']) : true,
            'includeInactive'    => isset($_GET['includeInactive']) ? !empty($_GET['includeInactive']) : true,
            'includeBanned'      => !empty($_GET['includeBanned']),
            'lastAttendanceFrom' => $_GET['from'] ?? '',
            'lastAttendanceTo'   => $_GET['to'] ?? '',
            'limit'              => min(max((int)($_GET['limit'] ?? 50), 1), 1000),
            'offset'             => max((int)($_GET['offset'] ?? 0), 0),
            'token'              => $this->session->token ?? null,
        ]));
        exit;
    }

    // Filter-pill dropdown data for a search field: {kingdoms:[...]} or, with forKingdom, {parks:[...]}.
    // Public (names/abbreviations only), so no login is required.
    public function filteroptions($p = null)
    {
        header('Content-Type: application/json');
        $this->load_model('Search');
        $scope = $this->filterScopeParams();
        $forKingdom = (int)($_GET['forKingdom'] ?? 0);
        if ($forKingdom > 0) {
            echo json_encode(['parks' => $this->Search->filter_scope_parks($forKingdom, $scope)]);
        } else {
            echo json_encode(['kingdoms' => $this->Search->filter_scope_kingdoms($scope)]);
        }
        exit;
    }

    // Typed "KD:PK" prefix → {match:{kingdomId,...}} when it names an in-scope kingdom/park, else {match:null}.
    public function filterresolve($p = null)
    {
        header('Content-Type: application/json');
        $this->load_model('Search');
        $prefix = substr(trim((string)($_GET['prefix'] ?? '')), 0, 16);
        echo json_encode(['match' => $this->Search->resolve_filter_prefix($prefix, $this->filterScopeParams())]);
        exit;
    }

    // The search field's hard-scope params, as sent by the player-search component.
    private function filterScopeParams(): array
    {
        return [
            'parkId'             => max((int)($_GET['parkId'] ?? 0), 0),
            'kingdomId'          => max((int)($_GET['kingdomId'] ?? 0), 0),
            'restrictTo'         => in_array($_GET['restrictTo'] ?? '', ['park', 'kingdom'], true) ? $_GET['restrictTo'] : '',
            'restrictKingdomIds' => $this->csvIds('restrictKingdomIds'),
            'excludeKingdomId'   => max((int)($_GET['excludeKingdomId'] ?? 0), 0),
            'excludeParkId'      => max((int)($_GET['excludeParkId'] ?? 0), 0),
        ];
    }

    // CSV → int[] (positive ids only) for the list-valued surface-context params.
    private function csvIds(string $key): array
    {
        $raw = trim((string)($_GET[$key] ?? ''));
        if ($raw === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $v) {
            if ((int)$v > 0) {
                $out[] = (int)$v;
            }
        }
        return $out;
    }
}
