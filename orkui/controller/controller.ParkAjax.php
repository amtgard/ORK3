<?php

class Controller_ParkAjax extends Controller {

	public function park($p = null) {
		header('Content-Type: application/json');
		$parts   = explode('/', $p ?? '');
		$park_id = (int)preg_replace('/[^0-9]/', '', $parts[0] ?? '');
		$action  = $parts[1] ?? '';

		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}

		if (!valid_id($park_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid park ID']);
			exit;
		}

		$this->load_model('Park');

		if ($action === 'setofficers') {
			$officers = [];
			foreach ($_POST as $key => $val) {
				if (preg_match('/^(.+)Id$/', $key, $m) && valid_id((int)$val)) {
					$role = str_replace('_', ' ', $m[1]);
					$officers[$role] = ['MundaneId' => (int)$val, 'Role' => $role];
				}
			}
			if (empty($officers)) {
				echo json_encode(['status' => 1, 'error' => 'No officer assignments provided.']);
				exit;
			}
			$results = $this->Park->set_officers($this->session->token, $park_id, $officers);
			$errors  = [];
			foreach ($results as $r) {
				if (isset($r['Status']) && $r['Status'] != 0)
					$errors[] = ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '');
			}
			echo $errors
				? json_encode(['status' => 1, 'error' => implode('; ', $errors)])
				: json_encode(['status' => 0]);

		} elseif ($action === 'vacateofficer') {
			$role = trim($_POST['Role'] ?? '');
			if (!strlen($role)) {
				echo json_encode(['status' => 1, 'error' => 'Role is required.']);
				exit;
			}
			$r = $this->Park->vacate_officer($park_id, $role, $this->session->token);
			echo (!isset($r['Status']) || $r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'addparkday') {
			$recurrence = trim($_POST['Recurrence'] ?? '');
			$time       = trim($_POST['Time']       ?? '');
			if (!strlen($recurrence)) {
				echo json_encode(['status' => 1, 'error' => 'Recurrence is required.']);
				exit;
			}
			if (!strlen($time)) {
				echo json_encode(['status' => 1, 'error' => 'Time is required.']);
				exit;
			}
			$altLoc = (($_POST['AlternateLocation'] ?? '0') === '1') ? 1 : 0;
			$r = $this->Park->add_park_day([
				'Token'             => $this->session->token,
				'ParkId'            => $park_id,
				'Recurrence'        => $recurrence,
				'WeekDay'           => trim($_POST['WeekDay']     ?? ''),
				'WeekOfMonth'       => (int)($_POST['WeekOfMonth'] ?? 0),
				'MonthDay'          => (int)($_POST['MonthDay']    ?? 0),
				'Time'              => $time,
				'Purpose'           => trim($_POST['Purpose']     ?? 'other'),
				'Description'       => trim($_POST['Description'] ?? ''),
				'AlternateLocation' => $altLoc,
				'Address'           => trim($_POST['Address']     ?? ''),
				'City'              => trim($_POST['City']        ?? ''),
				'Province'          => trim($_POST['Province']    ?? ''),
				'PostalCode'        => trim($_POST['PostalCode']  ?? ''),
				'MapUrl'            => trim($_POST['MapUrl']      ?? ''),
				'LocationUrl'       => trim($_POST['LocationUrl'] ?? ''),
			]);
			echo (!isset($r['Status']) || $r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'deleteparkday') {
			$parkDayId = (int)($_POST['ParkDayId'] ?? 0);
			if (!valid_id($parkDayId)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid park day ID.']);
				exit;
			}
			$r = $this->Park->delete_park_day([
				'Token'     => $this->session->token,
				'ParkDayId' => $parkDayId,
			]);
			echo (!isset($r['Status']) || $r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'setdetails') {
			$r = $this->Park->set_park_details([
				'Token'       => $this->session->token,
				'ParkId'      => $park_id,
				'Url'         => trim($_POST['Url']         ?? ''),
				'Address'     => trim($_POST['Address']     ?? ''),
				'City'        => trim($_POST['City']        ?? ''),
				'Province'    => trim($_POST['Province']    ?? ''),
				'PostalCode'  => trim($_POST['PostalCode']  ?? ''),
				'MapUrl'      => trim($_POST['MapUrl']      ?? ''),
				'Description' => trim($_POST['Description'] ?? ''),
				'Directions'  => trim($_POST['Directions']  ?? ''),
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'playersearch') {
			$q = trim($_GET['q'] ?? '');
			if (strlen($q) < 2) {
				echo json_encode([]);
				exit;
			}

			// Load the park's kingdom_id for priority sorting
			global $DB;
			$pidInt = (int)$park_id;
			$pkRow  = $DB->DataSet("SELECT kingdom_id FROM ork_park WHERE park_id = {$pidInt} LIMIT 1");
			$kid    = ($pkRow && $pkRow->Next()) ? (int)$pkRow->kingdom_id : 0;

			// Park members first (0), kingdom members second (1), everyone else (2)
			$pid  = $pidInt;
			$term = str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $q);
			$kidClause = valid_id($kid) ? "WHEN m.kingdom_id = {$kid} THEN 1" : '';
			$sql = "
				SELECT m.mundane_id, m.persona, m.park_id AS m_park_id, m.kingdom_id AS m_kingdom_id,
				       k.name AS kingdom_name, p.name AS park_name,
				       p.abbreviation AS p_abbr, k.abbreviation AS k_abbr,
				       m.suspended,
				       CASE WHEN m.park_id = {$pid} THEN 0
				            {$kidClause}
				            ELSE 2 END AS sort_priority
				FROM ork_mundane m
				LEFT JOIN ork_kingdom k ON k.kingdom_id = m.kingdom_id
				LEFT JOIN ork_park p ON p.park_id = m.park_id
				WHERE m.suspended = 0 AND m.active = 1 AND LENGTH(m.persona) > 0
				  AND (m.persona LIKE '%{$term}%'
				    OR m.given_name LIKE '%{$term}%'
				    OR m.surname LIKE '%{$term}%'
				    OR m.username LIKE '%{$term}%')
				ORDER BY sort_priority, m.persona
				LIMIT 10";
			$rs      = $DB->DataSet($sql);
			$results = [];
			while ($rs->Next()) {
				$results[] = [
					'MundaneId'   => (int)$rs->mundane_id,
					'Persona'     => $rs->persona,
					'KingdomId'   => (int)$rs->m_kingdom_id,
					'ParkId'      => (int)$rs->m_park_id,
					'KingdomName' => $rs->kingdom_name,
					'ParkName'    => $rs->park_name,
					'KAbbr'       => $rs->k_abbr,
					'PAbbr'       => $rs->p_abbr,
					'Suspended'   => (int)$rs->suspended,
				];
			}

			echo json_encode($results);

		} else {
			echo json_encode(['status' => 1, 'error' => 'Unknown action']);
		}
		exit;
	}

	public function kingdom($p = null) {
		header('Content-Type: application/json');
		$parts      = explode('/', $p ?? '');
		$kingdom_id = (int)preg_replace('/[^0-9]/', '', $parts[0] ?? '');
		$action     = $parts[1] ?? '';

		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}

		if (!valid_id($kingdom_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid kingdom ID']);
			exit;
		}

		if ($action === 'create') {
			$this->load_model('Park');
			$name    = trim($_POST['Name'] ?? '');
			$abbr    = preg_replace('/[^A-Za-z0-9]/', '', trim($_POST['Abbreviation'] ?? ''));
			$titleId = (int)($_POST['ParkTitleId'] ?? 0);

			if (!strlen($name)) {
				echo json_encode(['status' => 1, 'error' => 'Park must have a name.']);
				exit;
			}
			if (!strlen($abbr)) {
				echo json_encode(['status' => 1, 'error' => 'Park must have an abbreviation.']);
				exit;
			}
			if (!valid_id($titleId)) {
				echo json_encode(['status' => 1, 'error' => 'Parks must have a title.']);
				exit;
			}

			$r = $this->Park->create_park([
				'Token'        => $this->session->token,
				'Name'         => $name,
				'Abbreviation' => $abbr,
				'KingdomId'    => $kingdom_id,
				'ParkTitleId'  => $titleId,
			]);

			if ($r['Status'] == 0) {
				echo json_encode(['status' => 0, 'parkId' => (int)($r['Detail'] ?? 0)]);
			} else {
				echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
			}
		} elseif ($action === 'editpark') {
			$this->load_model('Park');
			$park_id = (int)($_POST['ParkId'] ?? 0);
			$name    = trim($_POST['Name'] ?? '');
			$abbr    = preg_replace('/[^A-Za-z0-9]/', '', trim($_POST['Abbreviation'] ?? ''));
			$titleId = (int)($_POST['ParkTitleId'] ?? 0);
			$active  = ($_POST['Active'] ?? '') === 'Active' ? 'Active' : 'Retired';

			if (!valid_id($park_id)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid park ID.']);
				exit;
			}
			if (!strlen($name)) {
				echo json_encode(['status' => 1, 'error' => 'Park must have a name.']);
				exit;
			}
			if (!strlen($abbr)) {
				echo json_encode(['status' => 1, 'error' => 'Park must have an abbreviation.']);
				exit;
			}
			if (!valid_id($titleId)) {
				echo json_encode(['status' => 1, 'error' => 'Parks must have a title.']);
				exit;
			}

			$r = $this->Park->set_park_details([
				'Token'        => $this->session->token,
				'ParkId'       => $park_id,
				'Name'         => $name,
				'Abbreviation' => $abbr,
				'ParkTitleId'  => $titleId,
				'Active'       => $active,
			]);

			if ($r['Status'] == 0) {
				echo json_encode(['status' => 0]);
			} else {
				echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
			}
		} else {
			echo json_encode(['status' => 1, 'error' => 'Unknown action']);
		}
		exit;
	}
}
