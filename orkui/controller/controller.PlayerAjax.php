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
			$restricted = (int)($_POST['Restricted'] ?? 0);
			$waivered   = (int)($_POST['Waivered']   ?? 0);

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
				'Token'      => $this->session->token,
				'ParkId'     => $park_id,
				'GivenName'  => $givenName,
				'Surname'    => $surname,
				'OtherName'  => '',
				'UserName'   => $userName,
				'Persona'    => $persona,
				'Email'      => $email,
				'Password'   => $password,
				'Restricted' => $restricted,
				'Waivered'   => $waivered,
				'HasImage'   => 0,
				'Image'      => '',
				'IsActive'   => 1,
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

		} else {
			echo json_encode(['status' => 1, 'error' => 'Unknown action']);
		}
		exit;
	}
}
