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

        $results = Ork3::$Lib->searchservice->UniversalSearch([
            'Query'           => $q,
            'Kid'             => (int)($_GET['kid'] ?? 0),
            'Pid'             => (int)($_GET['pid'] ?? 0),
            'IncludeInactive' => !empty($_GET['inactive']),
            'Focus'           => trim($_GET['focus'] ?? ''),
            'CallerUserId'    => isset($this->session->user_id) ? (int)$this->session->user_id : 0,
        ]);

        echo json_encode($results);
        exit;
    }
}
