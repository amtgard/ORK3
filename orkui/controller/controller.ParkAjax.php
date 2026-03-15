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
			$online = (($_POST['Online'] ?? '0') === '1') ? 1 : 0;
			$altLoc = (!$online && (($_POST['AlternateLocation'] ?? '0') === '1')) ? 1 : 0;
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
				'Online'            => $online,
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
			$q     = trim($_GET['q']     ?? '');
			$scope = trim($_GET['scope'] ?? 'own'); // 'own' | 'exclude' | 'all'
			if (strlen($q) < 2) {
				echo json_encode([]);
				exit;
			}

			global $DB;
			$pid  = (int)$park_id;
			$term = str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $q);

			if ($scope === 'own') {
				$park_clause = "AND m.park_id = {$pid}";
			} elseif ($scope === 'exclude') {
				$park_clause = "AND m.park_id != {$pid}";
			} else {
				$park_clause = '';
			}

			$sql = "
				SELECT m.mundane_id, m.persona, m.park_id AS m_park_id, m.kingdom_id AS m_kingdom_id,
				       k.name AS kingdom_name, p.name AS park_name,
				       p.abbreviation AS p_abbr, k.abbreviation AS k_abbr,
				       m.suspended
				FROM ork_mundane m
				LEFT JOIN ork_kingdom k ON k.kingdom_id = m.kingdom_id
				LEFT JOIN ork_park p ON p.park_id = m.park_id
				WHERE m.suspended = 0 AND m.active = 1 AND LENGTH(m.persona) > 0
				  {$park_clause}
				  AND (m.persona LIKE '%{$term}%'
				    OR m.given_name LIKE '%{$term}%'
				    OR m.surname LIKE '%{$term}%'
				    OR m.username LIKE '%{$term}%')
				ORDER BY m.persona
				LIMIT 15";
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

		} elseif ($action === 'setheraldry') {
			if (empty($_FILES['Heraldry']['tmp_name']) || !is_uploaded_file($_FILES['Heraldry']['tmp_name'])) {
				echo json_encode(['status' => 1, 'error' => 'No image file received.']); exit;
			}
			$allowed = ['image/png', 'image/jpeg', 'image/gif'];
			if (!in_array($_FILES['Heraldry']['type'], $allowed)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid image type. Use PNG, JPG, or GIF.']); exit;
			}
			$heraldryData = base64_encode(file_get_contents($_FILES['Heraldry']['tmp_name']));
			$r = $this->Park->SetParkDetails([
				'Token'            => $this->session->token,
				'ParkId'           => $park_id,
				'Heraldry'         => $heraldryData,
				'HeraldryMimeType' => $_FILES['Heraldry']['type'],
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'removeheraldry') {
			$r = $this->Park->RemoveParkHeraldry([
				'Token'  => $this->session->token,
				'ParkId' => $park_id,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'resetwaivers') {
			$this->load_model('Player');
			$r = $this->Player->reset_waivers([
				'Token'  => $this->session->token,
				'ParkId' => $park_id,
			]);
			if ($r['Status'] == 5) {
				echo json_encode(['status' => 5, 'error' => 'Session expired.']);
			} elseif ($r['Status'] != 0) {
				echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
			} else {
				echo json_encode(['status' => 0, 'message' => $r['Detail'] ?? 'Waivers reset.']);
			}

		} elseif ($action === 'moveplayer') {
			$this->load_model('Player');
			$mundane_id   = (int)($_POST['MundaneId']  ?? 0);
			$dest_park_id = (int)($_POST['DestParkId'] ?? 0);
			if (!valid_id($mundane_id))   { echo json_encode(['status' => 1, 'error' => 'Select a player.']); exit; }
			if (!valid_id($dest_park_id)) { echo json_encode(['status' => 1, 'error' => 'Select a destination park.']); exit; }
			$r = $this->Player->move_player(['Token' => $this->session->token, 'MundaneId' => $mundane_id, 'ParkId' => $dest_park_id]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'dismissrecommendation') {
			$this->load_model('Player');
			$rec_id = (int)($_POST['RecommendationsId'] ?? 0);
			if (!valid_id($rec_id)) { echo json_encode(['status' => 1, 'error' => 'Invalid recommendation.']); exit; }
			$r = $this->Player->delete_player_recommendation([
				'Token'             => $this->session->token,
				'RecommendationsId' => $rec_id,
				'RequestedBy'       => $this->session->user_id,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'createtournament') {
			$this->load_model('Tournament');
			$name       = trim($_POST['Name']        ?? '');
			$when       = trim($_POST['When']        ?? '');
			$desc       = trim($_POST['Description'] ?? '');
			$url        = trim($_POST['Url']         ?? '');
			$kingdom_id = (int)($_POST['KingdomId']  ?? 0);
			$ecd_id     = (int)($_POST['EventCalendarDetailId'] ?? 0);

			if (!strlen($name)) {
				echo json_encode(['status' => 1, 'error' => 'Tournament name is required.']); exit;
			}
			if (!strlen($when)) {
				echo json_encode(['status' => 1, 'error' => 'Tournament date is required.']); exit;
			}

			$r = $this->Tournament->create_tournament([
				'Token'                 => $this->session->token,
				'Name'                  => $name,
				'Description'           => $desc,
				'Url'                   => $url,
				'When'                  => $when,
				'KingdomId'             => $kingdom_id,
				'ParkId'                => $park_id,
				'EventCalendarDetailId' => $ecd_id,
			]);
			echo (!isset($r['Status']) || $r['Status'] == 0)
				? json_encode(['status' => 0, 'tournamentId' => (int)($r['Detail'] ?? 0)])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

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
