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

        // CSV → int[] for the list-valued surface-context params.
        $csvIds = function ($key) {
            $raw = trim($_GET[$key] ?? '');
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
        };

        $limit  = min(max((int)($_GET['limit'] ?? 15), 1), 100);
        $offset = max((int)($_GET['offset'] ?? 0), 0);

        $svc  = new SearchService();
        // Probe one extra row to know whether a "Load more…" page exists, without a second COUNT query.
        $rows = $svc->RankedPlayers([
            'q'                  => $_GET['q'] ?? '',
            'parkId'             => (int)($_GET['parkId']    ?? 0) ?: null,
            'kingdomId'          => (int)($_GET['kingdomId'] ?? 0) ?: null,
            'restrictTo'         => $_GET['restrictTo'] ?? '',
            'restrictKingdomIds' => $csvIds('restrictKingdomIds'),
            'excludeKingdomId'   => (int)($_GET['excludeKingdomId'] ?? 0) ?: null,
            'excludeParkId'      => (int)($_GET['excludeParkId']    ?? 0) ?: null,
            'excludeIds'         => $csvIds('excludeIds'),
            'bannedScope'        => $_GET['bannedScope'] ?? '',
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
            echo json_encode(['rows' => [], 'hasMore' => false, 'offset' => 0, 'canSeeRealName' => 0, 'canSeeBanned' => 0]);
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
}
