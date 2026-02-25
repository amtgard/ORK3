<?php

class Controller_KingdomAjax extends Controller {

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

		if ($action === 'setofficers') {
			$this->load_model('Kingdom');

			// Collect officer assignments: any POST key ending in "Id" with a valid int value
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

			$results = $this->Kingdom->set_officers($this->session->token, $kingdom_id, $officers);
			$errors  = [];
			foreach ($results as $r) {
				if (isset($r['Status']) && $r['Status'] != 0) {
					$errors[] = ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '');
				}
			}

			if ($errors) {
				echo json_encode(['status' => 1, 'error' => implode('; ', $errors)]);
			} else {
				echo json_encode(['status' => 0]);
			}

		} elseif ($action === 'vacateofficer') {
			$this->load_model('Kingdom');
			$role = trim($_POST['Role'] ?? '');

			if (!strlen($role)) {
				echo json_encode(['status' => 1, 'error' => 'Role is required.']);
				exit;
			}

			$r = $this->Kingdom->vacate_officer($kingdom_id, $role, $this->session->token);
			if (!isset($r['Status']) || $r['Status'] == 0) {
				echo json_encode(['status' => 0]);
			} else {
				echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
			}

		} elseif ($action === 'setdetails') {
			$this->load_model('Kingdom');
			$name = trim($_POST['Name'] ?? '');
			$abbr = preg_replace('/[^A-Za-z0-9]/', '', trim($_POST['Abbreviation'] ?? ''));

			if (!strlen($name)) {
				echo json_encode(['status' => 1, 'error' => 'Kingdom name is required.']);
				exit;
			}
			if (!strlen($abbr)) {
				echo json_encode(['status' => 1, 'error' => 'Abbreviation is required.']);
				exit;
			}

			$request = [
				'Token'        => $this->session->token,
				'KingdomId'    => $kingdom_id,
				'Name'         => $name,
				'Abbreviation' => $abbr,
			];

			if (!empty($_FILES['Heraldry']['tmp_name']) && is_uploaded_file($_FILES['Heraldry']['tmp_name'])) {
				$allowed = ['image/png', 'image/jpeg', 'image/gif'];
				if (in_array($_FILES['Heraldry']['type'], $allowed)) {
					$request['Heraldry']         = base64_encode(file_get_contents($_FILES['Heraldry']['tmp_name']));
					$request['HeraldryMimeType'] = $_FILES['Heraldry']['type'];
				}
			}

			$r = $this->Kingdom->set_kingdom_details($request);
			echo $r['Status'] == 0
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'setconfig') {
			$this->load_model('Kingdom');
			$configs = $_POST['Config'] ?? [];

			if (!is_array($configs) || empty($configs)) {
				echo json_encode(['status' => 1, 'error' => 'No configuration data provided.']);
				exit;
			}

			$configList = [];
			foreach ($configs as $configId => $value) {
				$configList[] = [
					'Action'          => CFG_EDIT,
					'ConfigurationId' => (int)$configId,
					'Key'             => null,
					'Value'           => $value,
				];
			}

			$r = $this->Kingdom->set_kingdom_details([
				'Token'                => $this->session->token,
				'KingdomId'            => $kingdom_id,
				'KingdomConfiguration' => $configList,
			]);
			echo $r['Status'] == 0
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'setparktitles') {
			$this->load_model('Kingdom');
			$titles  = $_POST['Title']             ?? [];
			$classes = $_POST['Class']             ?? [];
			$minAtts = $_POST['MinimumAttendance'] ?? [];
			$minCuts = $_POST['MinimumCutoff']     ?? [];
			$periods = $_POST['Period']            ?? [];
			$lengths = $_POST['Length']            ?? [];

			$edits = [];
			foreach ($titles as $id => $title) {
				$title = trim($title);
				if ($id === 'New' && !strlen($title)) continue;
				$edits[] = [
					'Action'            => ($id === 'New') ? CFG_ADD : CFG_EDIT,
					'ParkTitleId'       => ($id === 'New') ? 0 : (int)$id,
					'Title'             => $title,
					'Class'             => (int)($classes[$id] ?? 0),
					'MinimumAttendance' => (int)($minAtts[$id] ?? 0),
					'MinimumCutoff'     => (int)($minCuts[$id] ?? 0),
					'Period'            => $periods[$id]         ?? 'month',
					'PeriodLength'      => (int)($lengths[$id]  ?? 1),
				];
			}

			if (empty($edits)) {
				echo json_encode(['status' => 1, 'error' => 'No park title data provided.']);
				exit;
			}

			$r = $this->Kingdom->set_kingdom_parktitles([
				'Token'      => $this->session->token,
				'KingdomId'  => $kingdom_id,
				'ParkTitles' => $edits,
			]);
			echo $r['Status'] == 0
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'deletetitle') {
			$this->load_model('Kingdom');
			$titleId = (int)($_POST['ParkTitleId'] ?? 0);

			if (!valid_id($titleId)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid park title ID.']);
				exit;
			}

			$r = $this->Kingdom->set_kingdom_parktitles([
				'Token'      => $this->session->token,
				'KingdomId'  => $kingdom_id,
				'ParkTitles' => [['Action' => CFG_REMOVE, 'ParkTitleId' => $titleId]],
			]);
			echo $r['Status'] == 0
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'setaward') {
			$this->load_model('Kingdom');
			$kawId   = (int)($_POST['KingdomAwardId']  ?? 0);
			$name    = trim($_POST['KingdomAwardName'] ?? '');
			$reign   = (int)($_POST['ReignLimit']      ?? 0);
			$month   = (int)($_POST['MonthLimit']      ?? 0);
			$isTitle = (int)($_POST['IsTitle']         ?? 0);
			$tClass  = (int)($_POST['TitleClass']      ?? 0);

			if (!strlen($name)) {
				echo json_encode(['status' => 1, 'error' => 'Award name is required.']);
				exit;
			}

			if ($kawId > 0) {
				$r = $this->Kingdom->EditAward([
					'Token'          => $this->session->token,
					'KingdomId'      => $kingdom_id,
					'KingdomAwardId' => $kawId,
					'Name'           => $name,
					'ReignLimit'     => $reign,
					'MonthLimit'     => $month,
					'IsTitle'        => $isTitle,
					'TitleClass'     => $tClass,
				]);
			} else {
				$awardId = (int)($_POST['AwardId'] ?? 0);
				if (!valid_id($awardId)) {
					echo json_encode(['status' => 1, 'error' => 'Canonical Award ID is required for new awards.']);
					exit;
				}
				$r = $this->Kingdom->CreateAward([
					'Token'      => $this->session->token,
					'KingdomId'  => $kingdom_id,
					'AwardId'    => $awardId,
					'Name'       => $name,
					'ReignLimit' => $reign,
					'MonthLimit' => $month,
					'IsTitle'    => $isTitle,
					'TitleClass' => $tClass,
				]);
			}

			echo (!isset($r['Status']) || $r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'deleteaward') {
			$this->load_model('Kingdom');
			$kawId = (int)($_POST['KingdomAwardId'] ?? 0);

			if (!valid_id($kawId)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid award ID.']);
				exit;
			}

			$this->Kingdom->RemoveAward([
				'Token'          => $this->session->token,
				'KingdomId'      => $kingdom_id,
				'KingdomAwardId' => $kawId,
			]);
			echo json_encode(['status' => 0]);

		} else {
			echo json_encode(['status' => 1, 'error' => 'Unknown action']);
		}
		exit;
	}
}
