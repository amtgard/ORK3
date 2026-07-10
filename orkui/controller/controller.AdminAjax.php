<?php

class Controller_AdminAjax extends Controller
{
    /**
     * Global ORK-level AJAX handler.
     * Route: AdminAjax/global/{action}
     * Actions: playersearch, addauth, removeauth
     */
    public function global($p = null)
    {
        header('Content-Type: application/json');

        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in.']);
            exit;
        }

        $uid = (int)$this->session->user_id;
        if (!Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN)) {
            echo json_encode(['status' => 5, 'error' => 'Not authorized.']);
            exit;
        }

        $action = trim($p ?? '');
        global $DB;

        if ($action === 'playersearch') {
            $q = trim($_GET['q'] ?? '');
            if (strlen($q) < 2) {
                echo json_encode([]);
                exit;
            }
            $term = str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $q);
            $DB->Clear();
            $rs = $DB->DataSet(
                "SELECT m.mundane_id, m.persona, p.abbreviation AS PAbbr, k.abbreviation AS KAbbr
				 FROM " . DB_PREFIX . "mundane m
				 LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = m.kingdom_id
				 LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = m.park_id
				 WHERE m.suspended = 0 AND m.active = 1 AND LENGTH(m.persona) > 0
				   AND (m.persona LIKE '%{$term}%'
				     OR m.given_name LIKE '%{$term}%'
				     OR m.surname LIKE '%{$term}%'
				     OR m.username LIKE '%{$term}%')
				 ORDER BY m.persona LIMIT 20"
            );
            $results = [];
            if ($rs) {
                while ($rs->Next()) {
                    $results[] = [
                        'MundaneId' => (int)$rs->mundane_id,
                        'Persona'   => $rs->persona,
                        'PAbbr'     => $rs->PAbbr,
                        'KAbbr'     => $rs->KAbbr,
                    ];
                }
            }
            echo json_encode($results);

        } elseif ($action === 'addauth') {
            $mid = (int)($_POST['MundaneId'] ?? 0);
            if (!$mid) {
                echo json_encode(['status' => 1, 'error' => 'Invalid player.']);
                exit;
            }
            $this->load_model('Authorization');
            $r = $this->Authorization->add_auth([
                'Token'     => $this->session->token,
                'MundaneId' => $mid,
                'Type'      => AUTH_ADMIN,
                'Id'        => 0,
                'Role'      => AUTH_ADMIN,
            ]);
            if ($r['Status'] != 0) {
                echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . (isset($r['Detail']) && $r['Detail'] !== '' ? ': ' . $r['Detail'] : '')]);
                exit;
            }
            $authId = (int)($r['Detail'] ?? 0);
            $DB->Clear();
            $rs = $DB->DataSet(
                "SELECT m.persona FROM " . DB_PREFIX . "mundane m WHERE m.mundane_id = {$mid}"
            );
            $persona = '';
            if ($rs && $rs->Next()) {
                $persona = $rs->persona;
            }
            Ork3::$Lib->dangeraudit->audit('Authorization::AddAuthorization', ['MundaneId' => $mid, 'Type' => AUTH_ADMIN, 'Id' => 0, 'Role' => AUTH_ADMIN], 'Player', $mid, null, [
                'authorization_id' => $authId,
                'mundane_id'       => $mid,
                'park_id'          => 0,
                'kingdom_id'       => 0,
                'event_id'         => 0,
                'unit_id'          => 0,
                'role'             => AUTH_ADMIN,
            ]);
            echo json_encode(['status' => 0, 'authId' => $authId, 'persona' => $persona, 'mundaneId' => $mid]);

        } elseif ($action === 'removeauth') {
            $this->load_model('Authorization');
            $r = $this->Authorization->del_auth([
                'Token'           => $this->session->token,
                'AuthorizationId' => (int)($_POST['AuthorizationId'] ?? 0),
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        } else {
            echo json_encode(['status' => 1, 'error' => 'Unknown action']);
        }
        exit;
    }


    public function stateofamtgard($section = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['error' => 'Not logged in.']);
            exit;
        }
        // stateofamtgard endpoints are open to all logged-in users

        $sor = Ork3::$Lib->stateofamtgard;
        $validated = $sor->ValidateDateRange($_GET['start'] ?? null, $_GET['end'] ?? null);
        if (!$validated['ok']) {
            http_response_code($validated['httpCode'] ?? 400);
            echo json_encode(['error' => $validated['error'] ?? 'Invalid date range.']);
            exit;
        }
        $start = $validated['start'];
        $end = $validated['end'];

        $raw_kingdoms = isset($_GET['kingdoms']) && is_array($_GET['kingdoms']) ? $_GET['kingdoms'] : [];
        // Reject 0/negative and absurd IDs (real kingdom_ids fit well under 100000).
        $kingdom_ids = array_values(array_filter(
            array_map('intval', $raw_kingdoms),
            fn ($id) => $id > 0 && $id < 100000
        ));

        $payload = $sor->DispatchChartSection(trim($section ?? ''), $start, $end, $kingdom_ids);
        if ($payload === null) {
            echo json_encode(['error' => 'Unknown section.']);
        } else {
            echo json_encode($payload);
        }
        exit;
    }


}
