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

		} elseif ($action === 'setstatus') {
			if (!Ork3::$Lib->authorization->HasAuthority((int)$this->session->user_id, AUTH_ADMIN, 0, AUTH_ADMIN)) {
				echo json_encode(['status' => 5, 'error' => 'Unauthorized']); exit;
			}
			$this->load_model('Kingdom');
			$active = trim($_POST['Active'] ?? '') === 'Active' ? 'Active' : 'Retired';
			$r = $active === 'Active'
				? $this->Kingdom->RestoreKingdom(['Token' => $this->session->token, 'KingdomId' => $kingdom_id])
				: $this->Kingdom->RetireKingdom(['Token'  => $this->session->token, 'KingdomId' => $kingdom_id]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0, 'active' => $active])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

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
				'Description'  => trim($_POST['Description'] ?? ''),
				'Url'          => trim($_POST['Url'] ?? ''),
				'Timezone'     => trim($_POST['Timezone'] ?? ''),
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

		} elseif ($action === 'updateparks') {
			$this->load_model('Kingdom');
			$parks = json_decode($_POST['ParksJson'] ?? '[]', true);

			if (!is_array($parks) || empty($parks)) {
				echo json_encode(['status' => 1, 'error' => 'No park data provided.']);
				exit;
			}

			$request = [];
			foreach ($parks as $park) {
				$park_id = (int)($park['ParkId'] ?? 0);
				if (!valid_id($park_id)) continue;
				$request[] = [
					'ParkId'      => $park_id,
					'ParkName'    => trim($park['ParkName']    ?? ''),
					'ParkTitleId' => (int)($park['ParkTitle']  ?? 0),
					'Abbreviation'=> strtoupper(trim($park['Abbreviation'] ?? '')),
					'Active'      => !empty($park['Active']) ? 'Active' : 'Retired',
				];
			}

			if (empty($request)) {
				echo json_encode(['status' => 1, 'error' => 'No valid parks to update.']);
				exit;
			}

			$results = $this->Kingdom->update_parks($this->session->token, $request);
			$errors  = [];
			foreach ((array)$results as $r) {
				if (isset($r['Status']) && $r['Status'] == 5) {
					echo json_encode(['status' => 5, 'error' => 'Session expired.']);
					exit;
				}
				if (isset($r['Status']) && $r['Status'] != 0) {
					$errors[] = ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '');
				}
			}

			if ($errors) {
				echo json_encode(['status' => 1, 'error' => implode('; ', $errors)]);
			} else {
				echo json_encode(['status' => 0]);
			}

	} elseif ($action === 'resetwaivers') {
			$this->load_model('Player');
			$r = $this->Player->reset_waivers([
				'Token'     => $this->session->token,
				'KingdomId' => $kingdom_id,
			]);
			if ($r['Status'] == 5) {
				echo json_encode(['status' => 5, 'error' => 'Session expired.']);
			} elseif ($r['Status'] != 0) {
				echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
			} else {
				echo json_encode(['status' => 0, 'message' => $r['Detail'] ?? 'Waivers reset.']);
			}

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

		} elseif ($action === 'setheraldry') {
			$this->load_model('Kingdom');
			if (empty($_FILES['Heraldry']['tmp_name']) || !is_uploaded_file($_FILES['Heraldry']['tmp_name'])) {
				echo json_encode(['status' => 1, 'error' => 'No image file received.']); exit;
			}
			$allowed = ['image/png', 'image/jpeg', 'image/gif'];
			if (!in_array($_FILES['Heraldry']['type'], $allowed)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid image type. Use PNG, JPG, or GIF.']); exit;
			}
			$r = $this->Kingdom->set_kingdom_heraldry([
				'Token'            => $this->session->token,
				'KingdomId'        => $kingdom_id,
				'Heraldry'         => base64_encode(file_get_contents($_FILES['Heraldry']['tmp_name'])),
				'HeraldryMimeType' => $_FILES['Heraldry']['type'],
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

	} elseif ($action === 'removeheraldry') {
			$this->load_model('Kingdom');
			$r = $this->Kingdom->remove_kingdom_heraldry([
				'Token'     => $this->session->token,
				'KingdomId' => $kingdom_id,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

	} elseif ($action === 'moveplayer') {
			$uid = (int)$this->session->user_id;
			$this->load_model('Player');
			$mundane_id   = (int)($_POST['MundaneId']  ?? 0);
			$dest_park_id = (int)($_POST['DestParkId'] ?? 0);
			if (!valid_id($mundane_id))   { echo json_encode(['status' => 1, 'error' => 'Select a player.']);           exit; }
			if (!valid_id($dest_park_id)) { echo json_encode(['status' => 1, 'error' => 'Select a destination park.']); exit; }
			// Auth: look up player's current kingdom and require kingdom-level authority over it
			global $DB;
			$DB->Clear();
			$plrKingdom = $DB->DataSet("SELECT kingdom_id FROM " . DB_PREFIX . "mundane WHERE mundane_id = {$mundane_id} LIMIT 1");
			$player_kingdom_id = ($plrKingdom && $plrKingdom->Next()) ? (int)$plrKingdom->kingdom_id : 0;
			if (!$player_kingdom_id || !Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $player_kingdom_id, AUTH_EDIT)) {
				echo json_encode(['status' => 5, 'error' => 'Not authorized to move this player.']); exit;
			}
			$r = $this->Player->move_player(['Token' => $this->session->token, 'MundaneId' => $mundane_id, 'ParkId' => $dest_park_id]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0, 'parkId' => $dest_park_id])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'checkparkabbr') {
			$park_id = (int)($_POST['ParkId'] ?? 0);
			if (!valid_id($park_id)) { echo json_encode(['status' => 1, 'error' => 'Missing park ID.']); exit; }
			global $DB;
			$DB->Clear();
			$rs = $DB->DataSet("SELECT abbreviation FROM " . DB_PREFIX . "park WHERE park_id = {$park_id} LIMIT 1");
			if (!$rs || !$rs->Next()) { echo json_encode(['status' => 1, 'error' => 'Park not found.']); exit; }
			$abbr = strtoupper($rs->abbreviation);
			$DB->Clear();
			$abbrEsc = mysql_real_escape_string($abbr);
			$rs2 = $DB->DataSet("SELECT name FROM " . DB_PREFIX . "park WHERE kingdom_id = {$kingdom_id} AND abbreviation = '{$abbrEsc}' AND park_id != {$park_id} AND active = 'Active' LIMIT 1");
			$taken = ($rs2 && $rs2->Next());
			echo json_encode(['status' => 0, 'abbr' => $abbr, 'taken' => $taken, 'conflictName' => $taken ? $rs2->name : '']);
			exit;

		} elseif ($action === 'claimpark') {
			$this->load_model('Park');
			$park_id         = (int)($_POST['ParkId']        ?? 0);
			$dest_kingdom_id = (int)($_POST['DestKingdomId'] ?? $kingdom_id);
			if (!valid_id($park_id))         { echo json_encode(['status' => 1, 'error' => 'Select a park.']);                    exit; }
			if (!valid_id($dest_kingdom_id)) { echo json_encode(['status' => 1, 'error' => 'Destination kingdom is required.']); exit; }
			$new_abbr = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($_POST['Abbreviation'] ?? '')));
			$r = $this->Park->TransferPark(['Token' => $this->session->token, 'ParkId' => $park_id, 'KingdomId' => $dest_kingdom_id, 'Abbreviation' => $new_abbr]);
			if ($r['Status'] == 0) {
				$bustKey = Ork3::$Lib->ghettocache->key(['KingdomId' => $dest_kingdom_id]);
				Ork3::$Lib->ghettocache->bust('Report.GetKingdomParkAverages',        $bustKey);
				Ork3::$Lib->ghettocache->bust('Report.GetKingdomParkMonthlyAverages', $bustKey);
				echo json_encode(['status' => 0]);
			} else {
				echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
			}

		} elseif ($action === 'addrecommendation') {
			if (!isset($this->session->user_id)) { echo json_encode(['status' => 1, 'error' => 'You must be logged in to submit a recommendation.']); exit; }
			$this->load_model('Player');
			$mundane_id = (int)($_POST['MundaneId']       ?? 0);
			$award_id   = (int)($_POST['KingdomAwardId']  ?? 0);
			$rank       = (int)($_POST['Rank']            ?? 0);
			$reason     = trim($_POST['Reason']           ?? '');
			if (!valid_id($mundane_id)) { echo json_encode(['status' => 1, 'error' => 'Please select a player.']); exit; }
			if (!valid_id($award_id))   { echo json_encode(['status' => 1, 'error' => 'Please select an award.']); exit; }
			if (!$reason)               { echo json_encode(['status' => 1, 'error' => 'Please enter a reason.']); exit; }
			$r = $this->Player->add_player_recommendation([
				'Token'          => $this->session->token,
				'MundaneId'      => $mundane_id,
				'KingdomAwardId' => $award_id,
				'Rank'           => $rank > 0 ? $rank : null,
				'GivenById'      => $this->session->user_id,
				'Reason'         => $reason,
			]);
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

		} elseif ($action === 'geteventtemplates') {
			global $DB;
			$kid = $kingdom_id;
			$sql = "SELECT e.event_id, e.name, p.park_id, p.name AS park_name
			        FROM ork_event e
			        LEFT JOIN ork_park p ON p.park_id = e.park_id
			        WHERE e.kingdom_id = $kid ORDER BY e.name";
			$rs        = $DB->DataSet($sql);
			$templates = [];
			if ($rs && $rs->Size() > 0) {
				while ($rs->Next()) {
					$templates[] = [
						'EventId'  => (int)$rs->event_id,
						'Name'     => $rs->name,
						'ParkId'   => (int)$rs->park_id,
						'ParkName' => $rs->park_name ?? '',
					];
				}
			}
			echo json_encode(['status' => 0, 'templates' => $templates]);

		} elseif ($action === 'createtournament') {
			$this->load_model('Tournament');
			$name   = trim($_POST['Name']        ?? '');
			$when   = trim($_POST['When']        ?? '');
			$desc   = trim($_POST['Description'] ?? '');
			$url    = trim($_POST['Url']         ?? '');
			$pid    = (int)($_POST['ParkId']                ?? 0);
			$ecd_id = (int)($_POST['EventCalendarDetailId'] ?? 0);

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
				'ParkId'                => $pid,
				'EventCalendarDetailId' => $ecd_id,
			]);
			echo (!isset($r['Status']) || $r['Status'] == 0)
				? json_encode(['status' => 0, 'tournamentId' => (int)($r['Detail'] ?? 0)])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'deletetournament') {
			$this->load_model('Tournament');
			$tournament_id = (int)($_POST['TournamentId'] ?? 0);
			if (!valid_id($tournament_id)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid tournament ID.']); exit;
			}
			$r = $this->Tournament->delete_tournament([
				'Token'        => $this->session->token,
				'TournamentId' => $tournament_id,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

				} elseif ($action === 'setrecsvisibility') {
			$uid = (int)$this->session->user_id;
			if (!Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT)) {
				echo json_encode(['status' => 5, 'error' => 'Not authorized.']); exit;
			}
			$value = (int)($_POST['Value'] ?? 1) ? '1' : '0';
			global $DB;
			$kid = (int)$kingdom_id;
			$DB->Clear();
			$existing = $DB->DataSet("SELECT configuration_id FROM " . DB_PREFIX . "configuration WHERE type='Kingdom' AND id=$kid AND `key`='AwardRecsPublic' LIMIT 1");
			if ($existing && $existing->Next()) {
				$cid = (int)$existing->configuration_id;
				$DB->Clear();
				$DB->Execute("UPDATE " . DB_PREFIX . "configuration SET value='" . json_encode($value) . "', modified=NOW() WHERE configuration_id=$cid");
			} else {
				$DB->Clear();
				$DB->Execute("INSERT INTO " . DB_PREFIX . "configuration (type, var_type, id, `key`, value, user_setting, allowed_values, modified) VALUES ('Kingdom', 'fixed', $kid, 'AwardRecsPublic', '" . json_encode($value) . "', 1, 'null', NOW())");
			}
			echo json_encode(['status' => 0]);

		} elseif ($action === 'addauth') {
			$uid = (int)$this->session->user_id;
			if (!Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $kingdom_id, AUTH_CREATE)) {
				echo json_encode(['status' => 5, 'error' => 'Not authorized.']); exit;
			}
			$mid  = (int)($_POST['MundaneId'] ?? 0);
			$role = in_array($_POST['Role'] ?? '', ['create','edit','admin']) ? $_POST['Role'] : 'create';
			if (!$mid) { echo json_encode(['status' => 1, 'error' => 'Invalid player.']); exit; }
			if ($role === 'admin' && !Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN)) {
				echo json_encode(['status' => 5, 'error' => 'Only a system administrator can grant the Administrator role.']); exit;
			}
			global $DB;
			$DB->Clear();
			$DB->Execute("INSERT INTO ork_authorization (mundane_id, park_id, kingdom_id, event_id, unit_id, role, modified)
				VALUES ({$mid}, 0, {$kingdom_id}, 0, 0, '{$role}', NOW())");
			$DB->Clear();
			$rs = $DB->DataSet("SELECT a.authorization_id, m.persona FROM ork_authorization a
				LEFT JOIN ork_mundane m ON m.mundane_id = a.mundane_id
				WHERE a.mundane_id = {$mid} AND a.kingdom_id = {$kingdom_id}
				ORDER BY a.authorization_id DESC LIMIT 1");
			$authId = 0; $persona = '';
			if ($rs && $rs->Next()) { $authId = (int)$rs->authorization_id; $persona = $rs->persona; }
			echo json_encode(['status' => 0, 'authId' => $authId, 'persona' => $persona]);

		} elseif ($action === 'removeauth') {
			$uid = (int)$this->session->user_id;
			if (!Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $kingdom_id, AUTH_CREATE)) {
				echo json_encode(['status' => 5, 'error' => 'Not authorized.']); exit;
			}
			$this->load_model('Authorization');
			$r = $this->Authorization->del_auth([
				'Token'           => $this->session->token,
				'AuthorizationId' => (int)($_POST['AuthorizationId'] ?? 0),
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'getparks') {
			$this->load_model('Kingdom');
			$r = $this->Kingdom->get_park_info($kingdom_id);
			$parks = [];
			foreach ($r['Parks'] ?? [] as $park) {
				$parks[] = ['ParkId' => $park['ParkId'], 'Name' => $park['Name']];
			}
			echo json_encode(['status' => 0, 'parks' => $parks]);
		} elseif ($action === 'parktitles') {
			$result = Ork3::$Lib->kingdom->GetKingdomParkTitles(['KingdomId' => $kingdom_id]);
			$titles = [];
			foreach ($result['ParkTitles'] ?? [] as $pt) {
				$titles[] = ['ParkTitleId' => (int)$pt['ParkTitleId'], 'Title' => $pt['Title']];
			}
			echo json_encode(['status' => 0, 'titles' => $titles]);

		} elseif ($action === 'setparent') {
		$uid = (int)($this->session->user_id ?? 0);
		if (!$uid || !Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN)) {
			echo json_encode(['status' => 5, 'error' => 'Unauthorized']); exit;
		}
		$this->load_model('Kingdom');
		$parentId = (int)($_POST['ParentKingdomId'] ?? 0);
		$r = $this->Kingdom->set_kingdom_parent([
			'Token'           => $this->session->token,
			'KingdomId'       => $kingdom_id,
			'ParentKingdomId' => $parentId,
		]);
		echo ($r['Status'] == 0)
			? json_encode(['status' => 0])
			: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

	} elseif ($action === 'checkabbr') {
			$abbr      = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($_POST['Abbreviation'] ?? '')));
			$excludeId = (int)($_POST['ExcludeKingdomId'] ?? 0);
			if (!strlen($abbr)) { echo json_encode(['status' => 0, 'taken' => false]); exit; }
			global $DB;
			$DB->Clear();
			$excludeClause = $excludeId > 0 ? " AND kingdom_id != {$excludeId}" : '';
			$rs = $DB->DataSet("SELECT kingdom_id, name FROM " . DB_PREFIX . "kingdom WHERE abbreviation = '{$abbr}'{$excludeClause} LIMIT 1");
			echo ($rs && $rs->Next())
				? json_encode(['status' => 0, 'taken' => true,  'name' => $rs->name])
				: json_encode(['status' => 0, 'taken' => false]);

		} else {
			echo json_encode(['status' => 1, 'error' => 'Unknown action']);
		}
		exit;
	}

	public function calendar($p = null) {
		header('Content-Type: application/json');
		$kingdom_id = (int)preg_replace('/[^0-9]/', '', $p ?? '');

		if (!valid_id($kingdom_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid kingdom ID']);
			exit;
		}

		$start = preg_replace('/[^0-9\-]/', '', substr($_GET['start'] ?? '', 0, 10));
		$end   = preg_replace('/[^0-9\-]/', '', substr($_GET['end']   ?? '', 0, 10));

		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid date range']);
			exit;
		}

		$kid    = (int)$kingdom_id;
		global $DB;
		$events = [];

		// Events in range (all calendar-detail occurrences within window)
		// Fetch kingdom timezone for default
		$knTzSql = "SELECT timezone FROM ork_kingdom WHERE kingdom_id = {$kid}";
		$DB->Clear();
		$knTzRow = $DB->DataSet($knTzSql);
		$kingdomTz = ($knTzRow && $knTzRow->Size() > 0 && $knTzRow->Next() && !empty($knTzRow->timezone)) ? $knTzRow->timezone : '';

		$evtSql = "
			SELECT e.event_id, e.name, e.park_id, e.timezone AS event_tz,
			       p.abbreviation AS park_abbr, p.timezone AS park_tz,
			       cd.event_start, cd.event_end, cd.event_calendardetail_id AS detail_id
			FROM ork_event e
			LEFT JOIN ork_park p ON p.park_id = e.park_id
			INNER JOIN ork_event_calendardetail cd ON cd.event_id = e.event_id
			WHERE e.kingdom_id = {$kid}
			  AND cd.event_start >= '{$start}'
			  AND cd.event_start < '{$end}'
			ORDER BY cd.event_start";
		$DB->Clear();
		$evtResult = $DB->DataSet($evtSql);
		if ($evtResult && $evtResult->Size() > 0) {
			while ($evtResult->Next()) {
				$isPark = (int)$evtResult->park_id > 0;
				$abbr   = ($isPark && $evtResult->park_abbr) ? $evtResult->park_abbr . ': ' : '';
				$eid    = (int)$evtResult->event_id;
				$did    = (int)$evtResult->detail_id;

				// Resolve timezone: event -> park -> kingdom -> UTC
				$evTz = 'UTC';
				if (!empty($evtResult->event_tz))      $evTz = $evtResult->event_tz;
				elseif (!empty($evtResult->park_tz))    $evTz = $evtResult->park_tz;
				elseif (!empty($kingdomTz))             $evTz = $kingdomTz;
				$evTzAbbr = Common::get_timezone_abbr($evTz, $evtResult->event_start);

				$ev = [
					'title' => $abbr . $evtResult->name,
					'start' => $evtResult->event_start,
					'url'   => $did ? UIR . "Event/detail/{$eid}/{$did}" : '',
					'color' => $isPark ? '#6b46c1' : '#0891b2',
					'type'  => $isPark ? 'park-event' : 'kingdom-event',
					'timezone' => $evTz,
					'tzAbbr'   => $evTzAbbr,
				];
				$endRaw = $evtResult->event_end ?? '';
				if ($endRaw && substr($endRaw, 0, 10) > substr($evtResult->event_start, 0, 10)) {
					$endDt = new DateTime(substr($endRaw, 0, 10));
					$endDt->modify('+1 day');
					$ev['end'] = $endDt->format('Y-m-d');
				}
				$events[] = $ev;
			}
		}

		// Park day recurrences expanded for the requested range
		$pdSql = "
			SELECT pd.park_id, pd.recurrence, pd.week_day, pd.week_of_month,
			       pd.month_day, pd.time, pd.purpose, p.abbreviation AS park_abbr
			FROM ork_parkday pd
			JOIN ork_park p ON p.park_id = pd.park_id
			WHERE p.kingdom_id = {$kid} AND p.active = 'Active'";
		$pdResult = $DB->DataSet($pdSql);
		if ($pdResult && $pdResult->Size() > 0) {
			$dayNames   = ['Sunday'=>0,'Monday'=>1,'Tuesday'=>2,'Wednesday'=>3,'Thursday'=>4,'Friday'=>5,'Saturday'=>6];
			$rangeStart = new DateTime($start);
			$rangeEnd   = new DateTime($end);
			while ($pdResult->Next()) {
				switch ($pdResult->purpose) {
					case 'fighter-practice': $purposeLabel = 'Fighter Practice'; break;
					case 'arts-day':         $purposeLabel = 'A&S Day'; break;
					case 'park-day':         $purposeLabel = 'Park Day'; break;
					default:                 $purposeLabel = ucwords(str_replace('-', ' ', $pdResult->purpose));
				}
				$abbr    = $pdResult->park_abbr ? $pdResult->park_abbr . ': ' : '';
				$title   = $abbr . $purposeLabel;
				$url     = UIR . 'Park/profile/' . (int)$pdResult->park_id;
				$timeStr = ($pdResult->time && $pdResult->time !== '00:00:00') ? 'T' . $pdResult->time : '';
				$rec     = $pdResult->recurrence;

				if ($rec === 'weekly') {
					$targetWd = $dayNames[$pdResult->week_day] ?? -1;
					if ($targetWd < 0) continue;
					$cur = clone $rangeStart;
					while ((int)$cur->format('w') !== $targetWd) { $cur->modify('+1 day'); }
					while ($cur < $rangeEnd) {
						$events[] = ['title'=>$title,'start'=>$cur->format('Y-m-d').$timeStr,'url'=>$url,'color'=>'#b7791f','type'=>'park-day'];
						$cur->modify('+7 days');
					}
				} elseif ($rec === 'week-of-month') {
					$targetWd = $dayNames[$pdResult->week_day] ?? -1;
					$nth = (int)$pdResult->week_of_month;
					if ($targetWd < 0 || $nth < 1) continue;
					$curMonth = clone $rangeStart;
					$curMonth->modify('first day of this month');
					while ($curMonth < $rangeEnd) {
						$cnt = 0; $cur = clone $curMonth;
						$mn  = (int)$curMonth->format('n');
						while ((int)$cur->format('n') === $mn) {
							if ((int)$cur->format('w') === $targetWd && ++$cnt === $nth) {
								if ($cur >= $rangeStart && $cur < $rangeEnd) {
									$events[] = ['title'=>$title,'start'=>$cur->format('Y-m-d').$timeStr,'url'=>$url,'color'=>'#b7791f','type'=>'park-day'];
								}
								break;
							}
							$cur->modify('+1 day');
						}
						$curMonth->modify('first day of next month');
					}
				} elseif ($rec === 'monthly') {
					$dayNum = (int)$pdResult->month_day;
					if ($dayNum < 1) continue;
					$curMonth = clone $rangeStart;
					$curMonth->modify('first day of this month');
					while ($curMonth < $rangeEnd) {
						$mEnd = clone $curMonth; $mEnd->modify('last day of this month');
						$d    = min($dayNum, (int)$mEnd->format('d'));
						$cur  = new DateTime($curMonth->format('Y-m-') . sprintf('%02d', $d));
						if ($cur >= $rangeStart && $cur < $rangeEnd) {
							$events[] = ['title'=>$title,'start'=>$cur->format('Y-m-d').$timeStr,'url'=>$url,'color'=>'#b7791f','type'=>'park-day'];
						}
						$curMonth->modify('first day of next month');
					}
				}
			}
		}

		echo json_encode(['status' => 0, 'events' => $events]);
		exit;
	}

	public function playersearch($p = null) {
		header('Content-Type: application/json');

		if (!isset($this->session->user_id)) {
			echo json_encode([]);
			exit;
		}

		$kingdom_id = (int)preg_replace('/[^0-9]/', '', $p ?? '');
		if (!valid_id($kingdom_id)) {
			echo json_encode([]);
			exit;
		}

		$q       = trim($_GET['q']       ?? '');
		$scope   = trim($_GET['scope']   ?? 'own'); // 'own' | 'exclude'
		$park_id = (int)($_GET['park_id'] ?? 0);
		if (strlen($q) < 2) {
			echo json_encode([]);
			exit;
		}

		global $DB;
		$kid  = $kingdom_id;

		// Parse optional "KD:PK search term" prefix to scope results by abbreviation.
		// When matched, the prefix overrides the scope-based kingdom/park filter entirely.
		$filterKid = 0;
		$filterPid = 0;
		$searchQ   = $q;
		if (preg_match('/^([a-z0-9]{2,3}):([a-z0-9]{2,3}|\\*)?\\s+(.+)$/i', $q, $m)) {
			$kAbbr = str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $m[1]);
			$rs = $DB->DataSet("SELECT kingdom_id FROM ork_kingdom WHERE abbreviation = '{$kAbbr}' LIMIT 1");
			if ($rs->Next()) { $filterKid = (int)$rs->kingdom_id; }
			if ($filterKid > 0 && !empty($m[2]) && $m[2] !== '*') {
				$pAbbr = str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $m[2]);
				$rs = $DB->DataSet("SELECT park_id FROM ork_park WHERE abbreviation = '{$pAbbr}' AND kingdom_id = {$filterKid} LIMIT 1");
				if ($rs->Next()) { $filterPid = (int)$rs->park_id; }
			}
			$searchQ = trim($m[3]);
		}

		$term = str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $searchQ);

		if ($filterPid > 0) {
			$kingdom_clause = '';
			$park_clause    = "AND m.park_id = {$filterPid}";
		} elseif ($filterKid > 0) {
			$kingdom_clause = "AND m.kingdom_id = {$filterKid}";
			$park_clause    = '';
		} elseif ($scope === 'exclude') {
			$kingdom_clause = "AND m.kingdom_id != {$kid}";
			$park_clause    = valid_id($park_id) ? "AND m.park_id = {$park_id}" : '';
		} else {
			$kingdom_clause = "AND m.kingdom_id = {$kid}";
			$park_clause    = valid_id($park_id) ? "AND m.park_id = {$park_id}" : '';
		}

		$sql = "
			SELECT m.mundane_id, m.persona, p.park_id, k.kingdom_id,
			       k.name AS kingdom_name, p.name AS park_name,
			       p.abbreviation AS p_abbr, k.abbreviation AS k_abbr,
			       m.suspended
			FROM ork_mundane m
			LEFT JOIN ork_kingdom k ON k.kingdom_id = m.kingdom_id
			LEFT JOIN ork_park p ON p.park_id = m.park_id
			WHERE m.suspended = 0 AND m.active = 1 AND LENGTH(m.persona) > 0
			  {$kingdom_clause}
			  {$park_clause}
			  AND (m.persona LIKE '%{$term}%'
			    OR m.given_name LIKE '%{$term}%'
			    OR m.surname LIKE '%{$term}%'
			    OR m.username LIKE '%{$term}%')
			ORDER BY m.persona
			LIMIT 15";

		$DB->Clear();
		$rs      = $DB->DataSet($sql);
		$results = [];
		while ($rs->Next()) {
			$results[] = [
				'MundaneId'   => (int)$rs->mundane_id,
				'Persona'     => $rs->persona,
				'KingdomId'   => (int)$rs->kingdom_id,
				'ParkId'      => (int)$rs->park_id,
				'KingdomName' => $rs->kingdom_name,
				'ParkName'    => $rs->park_name,
				'KAbbr'       => $rs->k_abbr,
				'PAbbr'       => $rs->p_abbr,
				'Suspended'   => (int)$rs->suspended,
			];
		}

		echo json_encode($results);
		exit;
	}

	public function suspendplayer($p = null) {
		header('Content-Type: application/json');
		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']); exit;
		}
		$uid = (int)$this->session->user_id;
		$mid = (int)($_POST['MundaneId'] ?? 0);
		if (!$mid) { echo json_encode(['status' => 1, 'error' => 'Select a player.']); exit; }

		// Determine the player's kingdom so we can check auth
		global $DB;
		$rs = $DB->DataSet("SELECT kingdom_id FROM " . DB_PREFIX . "mundane WHERE mundane_id = {$mid} LIMIT 1");
		if (!$rs || !$rs->Next()) { echo json_encode(['status' => 1, 'error' => 'Player not found.']); exit; }
		$player_kingdom_id = (int)$rs->kingdom_id;

		$isAdmin = Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN);
		$isKingdomEditor = valid_id($player_kingdom_id)
			&& Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $player_kingdom_id, AUTH_EDIT);
		if (!$isAdmin && !$isKingdomEditor) {
			echo json_encode(['status' => 5, 'error' => 'Unauthorized']); exit;
		}

		$suspended  = (int)($_POST['Suspended']  ?? 1);
		$byId       = (int)($_POST['SuspendedById'] ?? 0);
		$at         = trim($_POST['SuspendedAt']    ?? '');
		$until      = trim($_POST['SuspendedUntil'] ?? '');
		$reason     = trim($_POST['Suspension']    ?? '');
		$propagates = (int)($_POST['SuspensionPropagates'] ?? 0);
		$this->load_model('Player');
		$r = $this->Player->suspend_player([
			'Token'                => $this->session->token,
			'MundaneId'            => $mid,
			'Suspended'            => (bool)$suspended,
			'SuspendedById'        => $byId ?: $uid,
			'SuspendedAt'          => $at,
			'SuspendedUntil'       => $until,
			'Suspension'           => $reason,
			'SuspensionPropagates' => $propagates,
		]);
		echo ($r === null || (isset($r['Status']) && $r['Status'] == 0))
			? json_encode(['status' => 0])
			: json_encode(['status' => $r['Status'] ?? 1, 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
		exit;
	}
}
