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
			$password   = $_POST['Password']        ?? '';
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
			if (!strlen($password)) {
				echo json_encode(['status' => 1, 'error' => 'Password is required.']);
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
				'Token'      => $this->session->token,
				'AwardsId'   => $awards_id,
				'Revocation' => $revocation,
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
				'Token'    => $this->session->token,
				'AwardsId' => $awards_id,
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
}
