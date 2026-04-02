<?php

class Controller_AdminAjax extends Controller {

	/**
	 * Global ORK-level AJAX handler.
	 * Route: AdminAjax/global/{action}
	 * Actions: playersearch, addauth, removeauth
	 */
	public function global($p = null) {
		header('Content-Type: application/json');

		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in.']); exit;
		}

		$uid = (int)$this->session->user_id;
		if (!Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN)) {
			echo json_encode(['status' => 5, 'error' => 'Not authorized.']); exit;
		}

		$action = trim($p ?? '');
		global $DB;

		if ($action === 'playersearch') {
			$q = trim($_GET['q'] ?? '');
			if (strlen($q) < 2) { echo json_encode([]); exit; }
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
			if (!$mid) { echo json_encode(['status' => 1, 'error' => 'Invalid player.']); exit; }
			$DB->Clear();
			$DB->Execute(
				"INSERT INTO " . DB_PREFIX . "authorization (mundane_id, park_id, kingdom_id, event_id, unit_id, role, modified)
				 VALUES ({$mid}, 0, 0, 0, 0, 'admin', NOW())"
			);
			$DB->Clear();
			$rs = $DB->DataSet(
				"SELECT a.authorization_id, m.persona FROM " . DB_PREFIX . "authorization a
				 LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = a.mundane_id
				 WHERE a.mundane_id = {$mid} AND a.role = 'admin'
				 ORDER BY a.authorization_id DESC LIMIT 1"
			);
			$authId = 0; $persona = '';
			if ($rs && $rs->Next()) { $authId = (int)$rs->authorization_id; $persona = $rs->persona; }
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

}
