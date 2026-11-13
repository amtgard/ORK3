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
            'PersonaOnly'     => !isset($this->session->user_id),
        ]);

        echo json_encode($results);
        exit;
    }

    /**
     * Search/Player for browsers. orkui.js routes the page's service calls here,
     * because the session cookie is scoped to /orkui/ and never reaches /orkservice/.
     */
    public function player($p = null)
    {
        header('Content-Type: application/json');
        $this->load_model('Search');
        echo json_encode($this->Search->search_players(
            trim((string)($_REQUEST['type'] ?? 'all')),
            trim((string)($_REQUEST['search'] ?? '')),
            (int)($_REQUEST['limit'] ?? 15),
            valid_id($_REQUEST['kingdom_id'] ?? 0) ? (int)$_REQUEST['kingdom_id'] : null,
            valid_id($_REQUEST['park_id'] ?? 0) ? (int)$_REQUEST['park_id'] : null,
            isset($_REQUEST['waivered']) ? (int)$_REQUEST['waivered'] : null,
            (string)($this->session->token ?? '')
        ));
        exit;
    }
}
