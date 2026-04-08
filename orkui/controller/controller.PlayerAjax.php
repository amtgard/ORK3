<?php

class Controller_PlayerAjax extends Controller {

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

		if ($action === 'create') {
			$this->load_model('Player');
			$persona    = trim($_POST['Persona']    ?? '');
			$givenName  = trim($_POST['GivenName']  ?? '');
			$surname    = trim($_POST['Surname']    ?? '');
			$email      = trim($_POST['Email']      ?? '');
			$userName   = trim($_POST['UserName']   ?? '');
			$password   = $_POST['Password'] ?? '';
			$restricted    = (int)($_POST['Restricted']   ?? 0);
			$waivered      = (int)($_POST['Waivered']     ?? 0);
			$pronounId     = (int)($_POST['PronounId']    ?? 0);
			$pronounCustom = trim($_POST['PronounCustom'] ?? '');

			if (!strlen($persona)) {
				echo json_encode(['status' => 1, 'error' => 'Persona is required.']);
				exit;
			}
			if (!strlen($userName)) {
				echo json_encode(['status' => 1, 'error' => 'Username is required.']);
				exit;
			}
			if (strlen($userName) < 4) {
				echo json_encode(['status' => 1, 'error' => 'Username must be at least 4 characters.']);
				exit;
			}
			$request = [
				'Token'         => $this->session->token,
				'ParkId'        => $park_id,
				'GivenName'     => $givenName,
				'Surname'       => $surname,
				'OtherName'     => '',
				'UserName'      => $userName,
				'Persona'       => $persona,
				'Email'         => $email,
				'Password'      => $password,
				'Restricted'    => $restricted,
				'Waivered'      => $waivered,
				'HasImage'      => 0,
				'Image'         => '',
				'IsActive'      => 1,
				'PronounId'     => $pronounId > 0 ? $pronounId : null,
				'PronounCustom' => strlen($pronounCustom) ? $pronounCustom : null,
			];

			if (!empty($_FILES['Waiver']['tmp_name']) && is_uploaded_file($_FILES['Waiver']['tmp_name'])) {
				$allowed = ['image/png', 'image/jpeg', 'image/gif', 'application/pdf'];
				if (in_array($_FILES['Waiver']['type'], $allowed)) {
					$ext = pathinfo($_FILES['Waiver']['name'], PATHINFO_EXTENSION);
					$request['Waiver']    = base64_encode(file_get_contents($_FILES['Waiver']['tmp_name']));
					$request['WaiverExt'] = strtolower($ext);
				}
			}

			$r = $this->Player->create_player($request);
			if ($r['Status'] == 0) {
				echo json_encode(['status' => 0, 'mundaneId' => (int)($r['Detail'] ?? 0)]);
			} else {
				echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
			}

		} else {
			echo json_encode(['status' => 1, 'error' => 'Unknown action']);
		}
		exit;
	}

	public function player($p = null) {
		header('Content-Type: application/json');
		$parts     = explode('/', $p ?? '');
		$player_id = (int)preg_replace('/[^0-9]/', '', $parts[0] ?? '');
		$action    = $parts[1] ?? '';

		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}

		if (!valid_id($player_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid player ID']);
			exit;
		}

		$this->load_model('Player');

		if ($action === 'revokeaward') {
			$awards_id  = (int)($_POST['AwardsId']   ?? 0);
			$revocation = trim($_POST['Revocation'] ?? '');
			if (!valid_id($awards_id)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid award ID.']);
				exit;
			}
			if (!strlen($revocation)) {
				echo json_encode(['status' => 1, 'error' => 'Revocation reason is required.']);
				exit;
			}
			$r = $this->Player->revoke_player_award([
				'Token'       => $this->session->token,
				'AwardsId'    => $awards_id,
				'RecipientId' => $player_id,
				'Revocation'  => $revocation,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'reactivateaward') {
			$awards_id = (int)($_POST['AwardsId'] ?? 0);
			if (!valid_id($awards_id)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid award ID.']);
				exit;
			}
			$r = $this->Player->reactivate_player_award([
				'Token'       => $this->session->token,
				'AwardsId'    => $awards_id,
				'RecipientId' => $player_id,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'addnote') {
			$note     = trim($_POST['Note']         ?? '');
			$desc     = trim($_POST['Description']  ?? '');
			$date     = trim($_POST['Date']         ?? '');
			$dateComp = trim($_POST['DateComplete'] ?? '');
			if (!strlen($note)) {
				echo json_encode(['status' => 1, 'error' => 'Note title is required.']);
				exit;
			}
			if (!strlen($date)) {
				echo json_encode(['status' => 1, 'error' => 'Date is required.']);
				exit;
			}
			$r = $this->Player->add_note([
				'Token'        => $this->session->token,
				'MundaneId'    => $player_id,
				'Note'         => $note,
				'Description'  => $desc,
				'Date'         => $date,
				'DateComplete' => $dateComp,
				'GivenBy'      => (int)$this->session->user_id,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0, 'notesId' => (int)($r['Detail'] ?? 0)])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'deletenote') {
			$notes_id = (int)($_POST['NotesId'] ?? 0);
			if (!valid_id($notes_id)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid note ID.']);
				exit;
			}
			$r = $this->Player->remove_note([
				'Token'     => $this->session->token,
				'NotesId'   => $notes_id,
				'MundaneId' => $player_id,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'editnote') {
			$notes_id = (int)($_POST['NotesId']    ?? 0);
			$note     = trim($_POST['Note']         ?? '');
			$desc     = trim($_POST['Description']  ?? '');
			$date     = trim($_POST['Date']         ?? '');
			$dateComp = trim($_POST['DateComplete'] ?? '');
			if (!valid_id($notes_id)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid note ID.']);
				exit;
			}
			if (!strlen($note)) {
				echo json_encode(['status' => 1, 'error' => 'Note title is required.']);
				exit;
			}
			if (!strlen($date)) {
				echo json_encode(['status' => 1, 'error' => 'Date is required.']);
				exit;
			}
			$r = $this->Player->edit_note([
				'Token'        => $this->session->token,
				'NotesId'      => $notes_id,
				'MundaneId'    => $player_id,
				'Note'         => $note,
				'Description'  => $desc,
				'Date'         => $date,
				'DateComplete' => $dateComp,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'moveplayer') {
			$dest_park_id = (int)($_POST['ParkId'] ?? 0);
			if (!valid_id($dest_park_id)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid park ID.']);
				exit;
			}
			$r = $this->Player->move_player([
				'Token'     => $this->session->token,
				'MundaneId' => $player_id,
				'ParkId'    => $dest_park_id,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'deleteaward') {
			$awards_id = (int)($_POST['AwardsId'] ?? 0);
			if (!valid_id($awards_id)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid award ID.']);
				exit;
			}
			$r = $this->Player->delete_player_award([
				'Token'       => $this->session->token,
				'AwardsId'    => $awards_id,
				'RecipientId' => $player_id,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'removeimage') {
			$r = $this->Player->remove_image([
				'Token'     => $this->session->token,
				'MundaneId' => $player_id,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'removeheraldry') {
			$r = $this->Player->remove_heraldry([
				'Token'     => $this->session->token,
				'MundaneId' => $player_id,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'revokeallawards') {
			$revocation = trim($_POST['Revocation'] ?? '');
			if (!strlen($revocation)) {
				echo json_encode(['status' => 1, 'error' => 'Revocation reason is required.']);
				exit;
			}
			$r = $this->Player->revoke_all_awards([
				'Token'      => $this->session->token,
				'MundaneId'  => $player_id,
				'Revocation' => $revocation,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'reconcileaward') {
		$awards_id        = (int)($_POST['AwardsId']        ?? 0);
		$kingdom_award_id = (int)($_POST['KingdomAwardId'] ?? 0);
		$rank             = (int)($_POST['Rank']           ?? 0);
		$date             = trim($_POST['Date']            ?? '');
		$given_by_id      = (int)($_POST['GivenById']      ?? 0);
		$note             = trim($_POST['Note']            ?? '');
		$park_id          = (int)($_POST['ParkId']         ?? 0);
		$kingdom_id       = (int)($_POST['KingdomId']      ?? 0);
		$event_id         = (int)($_POST['EventId']        ?? 0);
		if (!valid_id($awards_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid award ID.']);
			exit;
		}
		if (!valid_id($kingdom_award_id)) {
			echo json_encode(['status' => 1, 'error' => 'A target award is required.']);
			exit;
		}
		$r = $this->Player->reconcile_player_award([
			'Token'          => $this->session->token,
			'AwardsId'       => $awards_id,
			'KingdomAwardId' => $kingdom_award_id,
			'Rank'           => $rank,
			'Date'           => $date,
			'GivenById'      => $given_by_id,
			'Note'           => $note,
			'ParkId'         => valid_id($park_id)    ? $park_id    : 0,
			'KingdomId'      => valid_id($kingdom_id) ? $kingdom_id : 0,
			'EventId'        => valid_id($event_id)   ? $event_id   : 0,
		]);
		echo ($r['Status'] == 0)
			? json_encode(['status' => 0])
			: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

	} elseif ($action === 'updateclasses') {
			$reconcile_raw = $_POST['Reconciled'] ?? [];
			if (!is_array($reconcile_raw)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid reconciliation data.']);
				exit;
			}
			$reconcile = [];
			foreach ($reconcile_raw as $class_id => $qty) {
				$reconcile[] = ['ClassId' => (int)$class_id, 'Quantity' => (int)$qty];
			}
			$r = $this->Player->update_class_reconciliation([
				'Token'     => $this->session->token,
				'MundaneId' => $player_id,
				'ParkId'    => (int)($_POST['ParkId'] ?? 0),
				'Reconcile' => $reconcile,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'awardranks') {
			global $DB;
			$DB->Clear();
			$pid = (int)$player_id;
			$rs  = $DB->DataSet("
				SELECT ka.award_id, MAX(aw.rank) AS max_rank
				FROM ork_awards aw
				INNER JOIN ork_kingdomaward ka ON ka.kingdomaward_id = aw.kingdomaward_id
				WHERE aw.mundane_id = {$pid} AND aw.rank > 0
				GROUP BY ka.award_id");
			$ranks = [];
			while ($rs && $rs->Next()) {
				$ranks[(int)$rs->award_id] = (int)$rs->max_rank;
			}
			echo json_encode($ranks);

		} elseif ($action === 'info') {
			global $DB;
			$DB->Clear();
			$rs = $DB->DataSet("SELECT mundane_id, persona FROM ork_mundane WHERE mundane_id = {$player_id} LIMIT 1");
			if ($rs && $rs->Next()) {
				echo json_encode(['status' => 0, 'MundaneId' => $player_id, 'Persona' => $rs->persona]);
			} else {
				echo json_encode(['status' => 1, 'error' => 'Player not found']);
			}
			exit;

		} elseif ($action === 'updateprofile') {
			// Own-profile customization: about, colors, name prefix/suffix, photo focus
			$uid = (int)$this->session->user_id;
			if ($uid !== $player_id) {
				echo json_encode(['status' => 5, 'error' => 'You can only customize your own profile.']);
				exit;
			}
			$fields = [
				'Token'         => $this->session->token,
				'MundaneId'     => $player_id,
				'AboutPersona'  => isset($_POST['AboutPersona'])  ? $_POST['AboutPersona']  : null,
				'AboutStory'    => isset($_POST['AboutStory'])    ? $_POST['AboutStory']    : null,
				'ColorPrimary'  => (isset($_POST['ColorPrimary']) && preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['ColorPrimary'])) ? $_POST['ColorPrimary'] : null,
				'ColorAccent'   => (isset($_POST['ColorAccent']) && preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['ColorAccent'])) ? $_POST['ColorAccent'] : null,
					'ColorSecondary'=> isset($_POST['ColorSecondary']) ? (preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['ColorSecondary']) ? $_POST['ColorSecondary'] : '') : null,
					'HeroOverlay'   => isset($_POST['HeroOverlay'])     ? $_POST['HeroOverlay']    : null,
				'NamePrefix'    => isset($_POST['NamePrefix'])    ? trim($_POST['NamePrefix'])  : null,
				'NameSuffix'    => isset($_POST['NameSuffix'])    ? trim($_POST['NameSuffix'])  : null,
					'SuffixComma'   => isset($_POST['SuffixComma'])   ? (int)$_POST['SuffixComma']   : null,
				'Persona'       => isset($_POST['Persona'])       ? trim($_POST['Persona'])     : null,
				'PhotoFocusX'   => isset($_POST['PhotoFocusX'])   ? (int)$_POST['PhotoFocusX']  : null,
				'PhotoFocusY'   => isset($_POST['PhotoFocusY'])   ? (int)$_POST['PhotoFocusY']  : null,
				'PhotoFocusSize'=> isset($_POST['PhotoFocusSize'])? (int)$_POST['PhotoFocusSize']: null,
				'ShowBeltline'  => isset($_POST['ShowBeltline'])  ? (int)$_POST['ShowBeltline']   : null,
				'PronunciationGuide' => isset($_POST['PronunciationGuide']) ? trim($_POST['PronunciationGuide']) : null,
				'ShowMundaneFirst' => isset($_POST['ShowMundaneFirst']) ? (int)$_POST['ShowMundaneFirst'] : null,
				'ShowMundaneLast'  => isset($_POST['ShowMundaneLast'])  ? (int)$_POST['ShowMundaneLast']  : null,
				'ShowEmail'        => isset($_POST['ShowEmail'])        ? (int)$_POST['ShowEmail']        : null,
				'MilestoneConfig'  => isset($_POST['MilestoneConfig'])  ? $_POST['MilestoneConfig']  : null,
			];
			$r = $this->Player->update_player($fields);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'addmilestone') {
			$description = trim($_POST['Description'] ?? '');
			$icon        = trim($_POST['Icon'] ?? 'fa-star');
			$msDate      = trim($_POST['MilestoneDate'] ?? '');
			if (!strlen($description)) {
				echo json_encode(['status' => 1, 'error' => 'Description is required.']);
				exit;
			}
			if (!strlen($msDate) || !strtotime($msDate)) {
				echo json_encode(['status' => 1, 'error' => 'A valid date is required.']);
				exit;
			}
			$r = $this->Player->add_custom_milestone([
				'Token'         => $this->session->token,
				'MundaneId'     => $player_id,
				'Icon'          => $icon,
				'Description'   => $description,
				'MilestoneDate' => $msDate,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0, 'milestoneId' => (int)($r['Detail'] ?? 0)])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'updatemilestone') {
			$milestone_id = (int)($_POST['MilestoneId'] ?? 0);
			$description  = trim($_POST['Description'] ?? '');
			$icon         = trim($_POST['Icon'] ?? '');
			$msDate       = trim($_POST['MilestoneDate'] ?? '');
			if (!valid_id($milestone_id)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid milestone ID.']);
				exit;
			}
			$r = $this->Player->update_custom_milestone([
				'Token'         => $this->session->token,
				'MundaneId'     => $player_id,
				'MilestoneId'   => $milestone_id,
				'Icon'          => $icon,
				'Description'   => $description,
				'MilestoneDate' => $msDate,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'deletemilestone') {
			$milestone_id = (int)($_POST['MilestoneId'] ?? 0);
			if (!valid_id($milestone_id)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid milestone ID.']);
				exit;
			}
			$r = $this->Player->delete_custom_milestone([
				'Token'         => $this->session->token,
				'MundaneId'     => $player_id,
				'MilestoneId'   => $milestone_id,
			]);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} else {
			echo json_encode(['status' => 1, 'error' => 'Unknown action']);
		}
		exit;
	}

	public function merge($p = null) {
		header('Content-Type: application/json');
		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}
		$uid     = (int)$this->session->user_id;
		$from_id = (int)($_POST['FromMundaneId'] ?? 0);
		$to_id   = (int)($_POST['ToMundaneId']   ?? 0);
		if (!valid_id($from_id) || !valid_id($to_id)) {
			echo json_encode(['status' => 1, 'error' => 'Both player IDs are required.']);
			exit;
		}
		if ($from_id === $to_id) {
			echo json_encode(['status' => 1, 'error' => 'Cannot merge a player with themselves.']);
			exit;
		}
		// Auth: caller must have kingdom-level authority over at least one of the players' kingdoms
		global $DB;
		$DB->Clear();
		$rs = $DB->DataSet("SELECT kingdom_id FROM " . DB_PREFIX . "mundane WHERE mundane_id IN ({$from_id}, {$to_id})");
		$authorized = false;
		while ($rs && $rs->Next()) {
			$kid = (int)$rs->kingdom_id;
			if ($kid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $kid, AUTH_CREATE)) {
				$authorized = true;
				break;
			}
		}
		if (!$authorized) {
			echo json_encode(['status' => 5, 'error' => 'Not authorized to merge these players.']);
			exit;
		}
		$this->load_model('Player');
		$r = $this->Player->merge_player([
			'Token'         => $this->session->token,
			'FromMundaneId' => $from_id,
			'ToMundaneId'   => $to_id,
		]);
		echo ($r['Status'] == 0)
			? json_encode(['status' => 0])
			: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
		exit;
	}
	public function voting_eligible($p = null) {
		header('Content-Type: application/json');
		$mundane_id = (int)($p ?? 0);
		if (!valid_id($mundane_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid player ID']);
			exit;
		}
		$this->load_model('Reports');
		global $DB;
		$DB->Clear();
		$rs = $DB->DataSet("SELECT kingdom_id FROM " . DB_PREFIX . "mundane WHERE mundane_id = $mundane_id LIMIT 1");
		if (!$rs || !$rs->Next()) {
			echo json_encode(['status' => 1, 'error' => 'Player not found']);
			exit;
		}
		$kingdom_id = (int)$rs->kingdom_id;
		$DB->Clear();
		$supported = [31, 17, 10, 20, 25, 6, 38, 4, 27, 36, 14, 19, 3, 24, 12];
		if (!in_array($kingdom_id, $supported)) {
			echo json_encode(['status' => 0, 'eligible' => false]);
			exit;
		}
		$vr     = $this->Reports->get_voting_eligible_for_player($mundane_id, $kingdom_id);
		$player = $vr['Players'][0] ?? [];
		echo json_encode([
			'status'           => 0,
			'eligible'         => !empty($player['VotingEligible']),
			'province_mode'    => !empty($vr['ProvinceMode']),
			'province_eligible'=> !empty($player['ProvinceEligible']),
			'active_knight'    => !empty($player['ActiveKnight']),
			'active_member'    => $player['ActiveMember'] ?? null,
		]);
		exit;
	}

	public function attendance($p = null) {
		header('Content-Type: application/json');
		$mundane_id = (int)($p ?? 0);
		if (!valid_id($mundane_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid player ID']);
			exit;
		}
		$this->load_model('Player');
		$attendance = $this->Player->fetch_player_attendance($mundane_id);
		$parkEditAuth = [];
		if (isset($this->session->user_id)) {
			$uid = (int)$this->session->user_id;
			$uniqueParkIds = array_unique(array_filter(array_column(
				array_filter($attendance, fn($a) => (int)($a['EventId'] ?? 0) === 0),
				'ParkId'
			)));
			foreach ($uniqueParkIds as $pid) {
				if (valid_id($pid))
					$parkEditAuth[(int)$pid] = (bool)Ork3::$Lib->authorization->HasAuthority($uid, AUTH_PARK, (int)$pid, AUTH_EDIT);
			}
		}
		echo json_encode([
			'status'               => 0,
			'attendance'           => $attendance,
			'parkEditAuth'         => $parkEditAuth,
			'canEditAnyAttendance' => !empty(array_filter($parkEditAuth)),
			'total'                => count($attendance),
			'lastClass'            => !empty($attendance[0]['ClassName']) ? $attendance[0]['ClassName'] : '',
		]);
		exit;
	}

	public function all_dues($p = null) {
		header('Content-Type: application/json');
		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}
		$mundane_id = (int)($p ?? 0);
		if (!valid_id($mundane_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid player ID']);
			exit;
		}
		$this->load_model('Player');
		$dues = $this->Player->get_dues($mundane_id, 0, false);
		echo json_encode(['status' => 0, 'dues' => is_array($dues) ? $dues : []]);
		exit;
	}

	public function notes($p = null) {
		header('Content-Type: application/json');
		$mundane_id = (int)($p ?? 0);
		if (!valid_id($mundane_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid player ID']);
			exit;
		}
		$this->load_model('Player');
		$notes = $this->Player->get_notes($mundane_id);
		echo json_encode(['status' => 0, 'notes' => is_array($notes) ? $notes : []]);
		exit;
	}

	public function recommendations($p = null) {
		header('Content-Type: application/json');
		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}
		$mundane_id = (int)($p ?? 0);
		if (!valid_id($mundane_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid player ID']);
			exit;
		}
		$this->load_model('Reports');
		$recs = $this->Reports->recommended_awards([
			'PlayerId' => $mundane_id, 'KingdomId' => 0, 'ParkId' => 0,
			'IncludeKnights' => 1, 'IncludeMasters' => 1, 'IncludeLadder' => 1, 'LadderMinimum' => 0,
		]);
		echo json_encode(['status' => 0, 'recs' => is_array($recs) ? $recs : []]);
		exit;
	}

	public function save_my_email() {
		header('Content-Type: application/json');
		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}
		$email = trim($_POST['email'] ?? '');
		if (!strlen($email)) {
			echo json_encode(['status' => 1, 'error' => 'Email address is required.']);
			exit;
		}
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			echo json_encode(['status' => 1, 'error' => 'Please enter a valid email address.']);
			exit;
		}
		$mundane_id = (int)$this->session->user_id;
		global $DB;
		$DB->Clear();
		$DB->email = $email;
		$DB->Execute("UPDATE ork_mundane SET email = :email WHERE mundane_id = $mundane_id");
		echo json_encode(['status' => 0]);
		exit;
	}
}