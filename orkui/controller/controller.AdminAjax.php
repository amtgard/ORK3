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


	public function stateofamtgard($section = null) {
		header('Content-Type: application/json');
		if (!isset($this->session->user_id)) {
			echo json_encode(['error' => 'Not logged in.']); exit;
		}
		// stateofamtgard endpoints are open to all logged-in users

		// Strict YYYY-MM-DD validation: regex strips junk, then DateTime parses
		// and round-trips to reject impossible dates (e.g. 2026-02-30, 99999-99-99).
		$validate_date = function($val, $fallback) {
			$val = preg_replace('/[^0-9\-]/', '', $val ?? '');
			if ($val === '') return $fallback;
			$dt = DateTime::createFromFormat('Y-m-d', $val);
			if ($dt === false || $dt->format('Y-m-d') !== $val) return false;
			return $val;
		};
		$start = $validate_date($_GET['start'] ?? null, date('Y') . '-01-01');
		$end   = $validate_date($_GET['end']   ?? null, date('Y') . '-12-31');
		if ($start === false || $end === false) {
			http_response_code(400);
			echo json_encode(['error' => 'Invalid date format. Expected YYYY-MM-DD.']);
			exit;
		}
		if ($start > $end) {
			http_response_code(400);
			echo json_encode(['error' => 'Start date must be on or before end date.']);
			exit;
		}

		// Cap the reporting window at 12 months in production to protect the server
		// from multi-year scans that can exhaust the DB. Local/dev (ENVIRONMENT=DEV)
		// is unrestricted, matching the env gate used for the Server Health load test.
		$limit_months = (getenv('ENVIRONMENT') === 'DEV') ? 0 : 12;
		if ($limit_months > 0) {
			$max_end = (new DateTime($start))->modify('+' . $limit_months . ' months')->format('Y-m-d');
			if ($end > $max_end) {
				http_response_code(400);
				echo json_encode(['error' => 'The reporting window cannot exceed ' . $limit_months . ' months. Please narrow the date range.']);
				exit;
			}
		}

		$raw_kingdoms = isset($_GET['kingdoms']) && is_array($_GET['kingdoms']) ? $_GET['kingdoms'] : [];
		// Reject 0/negative and absurd IDs (real kingdom_ids fit well under 100000).
		$kingdom_ids = array_values(array_filter(
			array_map('intval', $raw_kingdoms),
			fn($id) => $id > 0 && $id < 100000
		));

		$sor = Ork3::$Lib->stateofamtgard;
		switch (trim($section ?? '')) {
			case 'kingdoms':
				echo json_encode(['kingdoms' => $sor->getKingdomSignIns($start, $end, $kingdom_ids)]);
				break;
			case 'classes':
				echo json_encode(['classes' => $sor->getClassSignIns($start, $end, $kingdom_ids)]);
				break;
			case 'parks':
				echo json_encode(['parks' => $sor->getParksAnalysis($start, $end, $kingdom_ids)]);
				break;
			case 'players':
				echo json_encode(['players' => $sor->getPlayerStats($start, $end, $kingdom_ids)]);
				break;
			case 'cohorts':
				echo json_encode(['cohorts' => $sor->getPlayerCohorts($start, $end, $kingdom_ids)]);
				break;
			case 'longevity':
				echo json_encode(['longevity' => $sor->getPlayerLongevity($start, $end, $kingdom_ids)]);
				break;
			case 'retention':
				// Fixed mature-cohort analysis; ignores start/end by design, respects kingdom filter.
				echo json_encode(['retention' => $sor->getNewPlayerRetention($kingdom_ids)]);
				break;
			case 'awards':
				echo json_encode(['awards' => $sor->getAwardGrants($start, $end, $kingdom_ids)]);
				break;
			default:
				echo json_encode(['error' => 'Unknown section.']);
		}
		exit;
	}


}
