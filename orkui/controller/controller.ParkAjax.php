<?php

class Controller_ParkAjax extends Controller {

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
