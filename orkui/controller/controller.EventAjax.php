<?php

class Controller_EventAjax extends Controller {

	public function create($p = null) {
		header('Content-Type: application/json');

		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}

		$this->load_model('Event');
		$name       = trim($_POST['Name']       ?? '');
		$kingdom_id = (int)($_POST['KingdomId'] ?? 0);
		$park_id    = (int)($_POST['ParkId']    ?? 0);

		if (!strlen($name)) {
			echo json_encode(['status' => 1, 'error' => 'Event name is required.']);
			exit;
		}
		if (!valid_id($kingdom_id) && !valid_id($park_id)) {
			echo json_encode(['status' => 1, 'error' => 'A kingdom or park is required.']);
			exit;
		}

		$r = $this->Event->create_event(
			$this->session->token,
			$kingdom_id,
			$park_id,
			0,
			0,
			$name
		);

		if ($r['Status'] == 0) {
			echo json_encode(['status' => 0, 'eventId' => (int)($r['Detail'] ?? 0)]);
		} else {
			echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
		}
		exit;
	}

	public function add_attendance($p = null) {
		header('Content-Type: application/json');

		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}

		$this->load_model('Attendance');

		$params    = explode( '/', $p ?? '' );
		$event_id  = (int)preg_replace( '/[^0-9]/', '', $params[0] ?? '' );
		$detail_id = (int)preg_replace( '/[^0-9]/', '', $params[1] ?? '' );

		if (!valid_id($event_id) || !valid_id($detail_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid Event ID.']);
			exit;
		}

		$uid = (int)$this->session->user_id;
		if (!Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_EDIT)) {
			echo json_encode(['status' => 3, 'error' => 'Not authorized.']);
			exit;
		}

		if (!valid_id($_POST['MundaneId'] ?? 0)) {
			echo json_encode(['status' => 1, 'error' => 'A player must be selected.']);
			exit;
		}

		if (!valid_id($_POST['ClassId'] ?? 0)) {
			echo json_encode(['status' => 1, 'error' => 'A class must be selected.']);
			exit;
		}

		$detail = $this->Attendance->get_eventdetail_info($detail_id);
		$r = $this->Attendance->add_attendance(
			$this->session->token,
			$_POST['AttendanceDate'] ?? date('Y-m-d'),
			valid_id($detail['AtParkId']) ? $detail['AtParkId'] : null,
			$detail_id,
			$_POST['MundaneId'] ?? 0,
			$_POST['ClassId'] ?? 0,
			$_POST['Credits'] ?? 1
		);

		if ($r['Status'] == 0) {
			global $DB;
			$aid = (int)$r['Detail'];
			$row = $DB->DataSet("SELECT a.attendance_id AS AttendanceId, a.mundane_id AS MundaneId, m.persona AS Persona, m.kingdom_id AS KingdomId, k.name AS KingdomName, k.abbreviation AS KAbbr, m.park_id AS ParkId, p.name AS ParkName, p.abbreviation AS PAbbr, c.name AS ClassName, a.credits AS Credits FROM ork_attendance a LEFT JOIN ork_mundane m ON m.mundane_id = a.mundane_id LEFT JOIN ork_park p ON p.park_id = m.park_id LEFT JOIN ork_kingdom k ON k.kingdom_id = m.kingdom_id LEFT JOIN ork_class c ON c.class_id = a.class_id WHERE a.attendance_id = $aid");
			if ($row && $row->Size() > 0 && $row->Next()) {
				echo json_encode(['status' => 0, 'attendance' => [
					'AttendanceId' => $row->AttendanceId,
					'MundaneId'    => $row->MundaneId,
					'Persona'      => $row->Persona,
					'KingdomId'    => $row->KingdomId,
					'KingdomName'  => $row->KingdomName,
					'KAbbr'        => $row->KAbbr,
					'ParkId'       => $row->ParkId,
					'ParkName'     => $row->ParkName,
					'PAbbr'        => $row->PAbbr,
					'ClassName'    => $row->ClassName,
					'Credits'      => $row->Credits,
				]]);
			} else {
				echo json_encode(['status' => 0, 'attendance' => null]);
			}
		} else {
			echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
		}
		exit;
	}

	public function delete_rsvp($p = null) {
		header('Content-Type: application/json');

		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}

		$params     = explode('/', $p ?? '');
		$event_id   = (int)preg_replace('/[^0-9]/', '', $params[0] ?? '');
		$detail_id  = (int)preg_replace('/[^0-9]/', '', $params[1] ?? '');
		$mundane_id = (int)($_POST['MundaneId'] ?? 0);

		if (!valid_id($event_id) || !valid_id($detail_id) || !valid_id($mundane_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid parameters.']);
			exit;
		}

		$uid = (int)$this->session->user_id;
		if (!Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_EDIT)) {
			echo json_encode(['status' => 3, 'error' => 'Not authorized.']);
			exit;
		}

		$this->load_model('Event');
		$this->Event->remove_rsvp($detail_id, $mundane_id);
		echo json_encode(['status' => 0]);
		exit;
	}

	public function cancel($p = null) {
		header('Content-Type: application/json');

		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}

		$event_id = (int)($_POST['EventId'] ?? 0);

		if (!valid_id($event_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid Event ID.']);
			exit;
		}

		$uid = (int)$this->session->user_id;
		if (!Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_CREATE)) {
			echo json_encode(['status' => 3, 'error' => 'Not authorized.']);
			exit;
		}

		$this->load_model('Event');
		$r = $this->Event->delete_event($this->session->token, $event_id);

		if (isset($r['Status']) && $r['Status'] == 0) {
			echo json_encode(['status' => 0]);
		} else {
			echo json_encode(['status' => $r['Status'] ?? 1, 'error' => $r['Detail'] ?? 'Could not cancel event.']);
		}
		exit;
	}

	public function auth($p = null) {
		header('Content-Type: application/json');

		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']); exit;
		}

		$params   = explode('/', $p ?? '');
		$event_id = (int)preg_replace('/[^0-9]/', '', $params[0] ?? '');
		$action   = $params[1] ?? '';

		if (!valid_id($event_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid Event ID.']); exit;
		}

		$uid = (int)$this->session->user_id;

		if ($action === 'playersearch') {
			if (!Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_CREATE)) {
				echo json_encode([]); exit;
			}
			$q = trim($_GET['q'] ?? '');
			if (strlen($q) < 2) { echo json_encode([]); exit; }
			global $DB;
			$term = str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $q);
			// Get event's park and kingdom to prioritize local players in results
			$DB->Clear();
			$evRow = $DB->DataSet("SELECT park_id, kingdom_id FROM " . DB_PREFIX . "event WHERE event_id = {$event_id} LIMIT 1");
			$evParkId    = ($evRow && $evRow->Next()) ? (int)$evRow->park_id    : 0;
			$evKingdomId = $evParkId                  ? (int)$evRow->kingdom_id : 0;
			$DB->Clear();
			$rs = $DB->DataSet(
				"SELECT m.mundane_id, m.persona, m.park_id AS m_park_id, m.kingdom_id AS m_kingdom_id,
				        k.name AS kingdom_name, p.name AS park_name,
				        p.abbreviation AS p_abbr, k.abbreviation AS k_abbr,
				        m.suspended
				 FROM " . DB_PREFIX . "mundane m
				 LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = m.kingdom_id
				 LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = m.park_id
				 WHERE m.suspended = 0 AND m.active = 1 AND LENGTH(m.persona) > 0
				   AND (m.persona LIKE '%{$term}%'
				     OR m.given_name LIKE '%{$term}%'
				     OR m.surname LIKE '%{$term}%'
				     OR m.username LIKE '%{$term}%')
				 ORDER BY
				   CASE
				     WHEN m.park_id = {$evParkId} AND {$evParkId} > 0 THEN 0
				     WHEN m.kingdom_id = {$evKingdomId} AND {$evKingdomId} > 0 THEN 1
				     ELSE 2
				   END,
				   m.persona
				 LIMIT 15"
			);
			$results = [];
			if ($rs) {
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
			}
			echo json_encode($results);

		} elseif ($action === 'addauth') {
			if (!Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_CREATE)) {
				echo json_encode(['status' => 5, 'error' => 'Not authorized.']); exit;
			}
			$mid  = (int)($_POST['MundaneId'] ?? 0);
			$role = in_array($_POST['Role'] ?? '', ['create', 'edit']) ? $_POST['Role'] : 'create';
			if (!$mid) { echo json_encode(['status' => 1, 'error' => 'Invalid player.']); exit; }
			global $DB;
			$DB->Clear();
			$DB->Execute("INSERT INTO " . DB_PREFIX . "authorization (mundane_id, park_id, kingdom_id, event_id, unit_id, role, modified)
				VALUES ({$mid}, 0, 0, {$event_id}, 0, '{$role}', NOW())");
			$DB->Clear();
			$rs = $DB->DataSet("SELECT a.authorization_id, m.persona FROM " . DB_PREFIX . "authorization a
				LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = a.mundane_id
				WHERE a.mundane_id = {$mid} AND a.event_id = {$event_id}
				ORDER BY a.authorization_id DESC LIMIT 1");
			$authId = 0; $persona = '';
			if ($rs && $rs->Next()) { $authId = (int)$rs->authorization_id; $persona = $rs->persona; }
			echo json_encode(['status' => 0, 'authId' => $authId, 'persona' => $persona]);

		} elseif ($action === 'removeauth') {
			if (!Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_CREATE)) {
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

		} else {
			echo json_encode(['status' => 1, 'error' => 'Unknown action']);
		}
		exit;
	}

	public function heraldry($p = null) {
		header('Content-Type: application/json');

		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}

		$params   = explode('/', $p ?? '');
		$event_id = (int)preg_replace('/[^0-9]/', '', $params[0] ?? '');
		$action   = $params[1] ?? '';

		if (!valid_id($event_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid Event ID.']);
			exit;
		}

		if (!Ork3::$Lib->authorization->HasAuthority((int)$this->session->user_id, AUTH_EVENT, $event_id, AUTH_CREATE)) {
			echo json_encode(['status' => 3, 'error' => 'Not authorized.']);
			exit;
		}

		if ($action === 'remove') {
			global $DB;
			$DB->Execute('UPDATE ' . DB_PREFIX . 'event SET has_heraldry = 0 WHERE event_id = ' . $event_id);
			$base = DIR_EVENT_HERALDRY . sprintf('%05d', $event_id);
			if (file_exists($base . '.jpg')) unlink($base . '.jpg');
			if (file_exists($base . '.png')) unlink($base . '.png');
			echo json_encode(['status' => 0]);
			exit;
		}

		if ($action === 'update') {
			if (empty($_FILES['Heraldry']['tmp_name'])) {
				echo json_encode(['status' => 1, 'error' => 'No file uploaded.']);
				exit;
			}
			$tmp  = $_FILES['Heraldry']['tmp_name'];
			$mime = $_FILES['Heraldry']['type'] ?? 'image/jpeg';
			$r = Ork3::$Lib->heraldry->SetEventHeraldry([
				'Token'            => $this->session->token,
				'EventId'          => $event_id,
				'Heraldry'         => base64_encode(file_get_contents($tmp)),
				'HeraldryMimeType' => $mime,
			]);
			if (isset($r['Status']) && $r['Status'] == 0) {
				echo json_encode(['status' => 0]);
			} else {
				echo json_encode(['status' => 1, 'error' => $r['Error'] ?? 'Upload failed.']);
			}
			exit;
		}

		echo json_encode(['status' => 1, 'error' => 'Unknown action.']);
		exit;
	}
}
