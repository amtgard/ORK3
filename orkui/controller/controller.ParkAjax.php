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
