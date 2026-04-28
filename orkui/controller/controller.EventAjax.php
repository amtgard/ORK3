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
			$newId = (int)($r['Detail'] ?? 0);
			// Honor optional Status=draft on create.
			$status = (string)($_POST['Status'] ?? 'published');
			if ($status === 'draft' && $newId > 0) {
				global $DB;
				$DB->Clear();
				$DB->Execute("UPDATE " . DB_PREFIX . "event SET status = 'draft' WHERE event_id = " . $newId);
			}
			echo json_encode(['status' => 0, 'eventId' => $newId]);
		} else {
			echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
		}
		exit;
	}

	// Publish / unpublish an event. Caller must hold AUTH_EVENT/AUTH_EDIT.
	public function set_status($p = null) {
		header('Content-Type: application/json');
		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}
		$uid    = (int)$this->session->user_id;
		$evtId  = (int)($_POST['EventId'] ?? 0);
		$status = (string)($_POST['Status'] ?? '');
		if ($evtId <= 0 || !in_array($status, ['published', 'draft'], true)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid parameters']);
			exit;
		}
		if (!Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $evtId, AUTH_EDIT)) {
			echo json_encode(['status' => 5, 'error' => 'Not authorized']);
			exit;
		}
		global $DB;
		$DB->Clear();
		$DB->Execute("UPDATE " . DB_PREFIX . "event SET status = '" . mysql_real_escape_string($status) . "' WHERE event_id = " . $evtId);
		// Bust SearchService.Event memcache for this event.
		$evKey = Ork3::$Lib->ghettocache->key(['', null, null, null, null, null, $evtId]);
		Ork3::$Lib->ghettocache->bust('SearchService.Event', $evKey);
		echo json_encode(['status' => 0, 'event_status' => $status]);
		exit;
	}

	// Lightweight event preview for the calendar grid quick-look modal.
	// Path: EventAjax/preview/{event_id}/{detail_id}
	public function preview($p = null) {
		header('Content-Type: application/json');
		$parts    = explode('/', (string)$p);
		$eventId  = (int)preg_replace('/[^0-9]/', '', $parts[0] ?? '');
		$detailId = (int)preg_replace('/[^0-9]/', '', $parts[1] ?? '');
		if ($eventId <= 0) {
			echo json_encode(['status' => 1, 'error' => 'Invalid event id']);
			exit;
		}
		global $DB;
		$uid     = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
		$isAdmin = $uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_CREATE);

		// Event row
		$DB->Clear();
		$ev = $DB->DataSet("
			SELECT e.event_id, e.name, e.kingdom_id, e.park_id, e.has_heraldry, e.status, e.mundane_id AS creator,
			       p.name AS park_name
			FROM " . DB_PREFIX . "event e
			LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = e.park_id
			WHERE e.event_id = {$eventId} LIMIT 1");
		if (!$ev || !$ev->Next()) {
			echo json_encode(['status' => 1, 'error' => 'Event not found']);
			exit;
		}
		$status      = (string)($ev->status ?? 'published');
		$canEdit     = $uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $eventId, AUTH_EDIT);
		if ($status !== 'published' && !$canEdit && !$isAdmin && (int)$ev->creator !== $uid) {
			echo json_encode(['status' => 5, 'error' => 'Not authorized']);
			exit;
		}

		// Detail row (price, description, dates, address, location JSON, at_park_id)
		$cd = null;
		if ($detailId > 0) {
			$DB->Clear();
			$cdRs = $DB->DataSet("
				SELECT cd.event_calendardetail_id, cd.event_start, cd.event_end, cd.description,
				       cd.price, cd.address, cd.city, cd.province, cd.location, cd.at_park_id
				FROM " . DB_PREFIX . "event_calendardetail cd
				WHERE cd.event_calendardetail_id = {$detailId} AND cd.event_id = {$eventId} LIMIT 1");
			if ($cdRs && $cdRs->Next()) $cd = $cdRs;
		}
		if (!$cd) {
			// Fall back to current detail
			$DB->Clear();
			$cdRs = $DB->DataSet("
				SELECT cd.event_calendardetail_id, cd.event_start, cd.event_end, cd.description,
				       cd.price, cd.address, cd.city, cd.province, cd.location, cd.at_park_id
				FROM " . DB_PREFIX . "event_calendardetail cd
				WHERE cd.event_id = {$eventId} AND cd.event_start >= DATE_SUB(NOW(), INTERVAL 7 DAY)
				ORDER BY cd.event_start LIMIT 1");
			if ($cdRs && $cdRs->Next()) {
				$cd = $cdRs;
				$detailId = (int)$cd->event_calendardetail_id;
			}
		}

		// RSVP counts + caller's status
		$going = 0; $interested = 0; $myRsvp = '';
		if ($detailId > 0) {
			$DB->Clear();
			$rs = $DB->DataSet("
				SELECT
					SUM(CASE WHEN status = 'going'      THEN 1 ELSE 0 END) AS g,
					SUM(CASE WHEN status = 'interested' THEN 1 ELSE 0 END) AS i
				FROM " . DB_PREFIX . "event_rsvp WHERE event_calendardetail_id = {$detailId}");
			if ($rs && $rs->Next()) { $going = (int)$rs->g; $interested = (int)$rs->i; }
			if ($uid > 0) {
				$DB->Clear();
				$mrs = $DB->DataSet("
					SELECT status FROM " . DB_PREFIX . "event_rsvp
					WHERE event_calendardetail_id = {$detailId} AND mundane_id = {$uid} LIMIT 1");
				if ($mrs && $mrs->Next()) $myRsvp = (string)$mrs->status;
			}
		}

		// Resolve coords for weather + sunrise (event location → at_park park lat/lng → host park lat/lng)
		$lat = null; $lng = null;
		if ($cd) {
			$rawLoc = (string)($cd->location ?? '');
			if ($rawLoc) {
				$loc = @json_decode(stripslashes($rawLoc));
				if ($loc) {
					$pt = isset($loc->location) ? $loc->location
						: (isset($loc->bounds->northeast) ? $loc->bounds->northeast : null);
					if ($pt && is_numeric($pt->lat ?? null) && is_numeric($pt->lng ?? null)) {
						$lat = (float)$pt->lat; $lng = (float)$pt->lng;
					}
				}
			}
			if ($lat === null && (int)($cd->at_park_id ?? 0) > 0) {
				$DB->Clear();
				$pkLook = $DB->DataSet("SELECT latitude, longitude FROM " . DB_PREFIX . "park WHERE park_id = " . (int)$cd->at_park_id . " LIMIT 1");
				if ($pkLook && $pkLook->Next() && is_numeric($pkLook->latitude) && (float)$pkLook->latitude != 0) {
					$lat = (float)$pkLook->latitude; $lng = (float)$pkLook->longitude;
				}
			}
		}
		if ($lat === null && (int)$ev->park_id > 0) {
			$DB->Clear();
			$pkLook = $DB->DataSet("SELECT latitude, longitude FROM " . DB_PREFIX . "park WHERE park_id = " . (int)$ev->park_id . " LIMIT 1");
			if ($pkLook && $pkLook->Next() && is_numeric($pkLook->latitude) && (float)$pkLook->latitude != 0) {
				$lat = (float)$pkLook->latitude; $lng = (float)$pkLook->longitude;
			}
		}

		$weather = null; $solar = null;
		if ($lat !== null && $cd) {
			$dayStr = date('Y-m-d', strtotime($cd->event_start));
			$weather = Ork3::$Lib->weather->GetForecast($lat, $lng, $dayStr);
			$solar   = SolarTimes::ForDate($lat, $lng, $dayStr);
		}

		// Description excerpt — strip markdown to first ~200 chars on a sentence boundary.
		$desc = (string)($cd->description ?? '');
		$plain = trim(preg_replace('/\s+/', ' ', preg_replace('/[#*_\[\]\(\)`>~-]+/', ' ', $desc)));
		$excerpt = '';
		if (strlen($plain)) {
			if (strlen($plain) <= 220) {
				$excerpt = $plain;
			} else {
				$cut = substr($plain, 0, 220);
				$cutAt = max(strrpos($cut, '. '), strrpos($cut, ' '));
				$excerpt = ($cutAt > 120 ? substr($cut, 0, $cutAt) : $cut) . '…';
			}
		}

		$startTs = $cd ? strtotime($cd->event_start) : 0;
		$endTs   = $cd ? strtotime($cd->event_end)   : 0;
		$dateLabel = $startTs ? date('l, F j, Y', $startTs) : '';
		$timeLabel = '';
		if ($startTs) {
			$timeLabel = date('g:i A', $startTs);
			if ($endTs && $endTs > $startTs) $timeLabel .= ' – ' . date('g:i A', $endTs);
		}

		echo json_encode([
			'status'           => 0,
			'event_id'         => $eventId,
			'event_calendardetail_id' => $detailId,
			'name'             => $ev->name,
			'park_id'          => (int)$ev->park_id,
			'park_name'        => $ev->park_name,
			'is_park_event'    => (int)$ev->park_id > 0,
			'is_draft'         => $status === 'draft',
			'has_heraldry'     => (int)$ev->has_heraldry === 1,
			'date_label'       => $dateLabel,
			'time_label'       => $timeLabel,
			'price'            => $cd ? (float)$cd->price : 0,
			'description_excerpt' => $excerpt,
			'going_count'      => $going,
			'interested_count' => $interested,
			'my_rsvp'          => $myRsvp,
			'weather'          => $weather,
			'solar'            => $solar,
			'detail_url'       => $detailId > 0 ? (UIR . 'Event/detail/' . $eventId . '/' . $detailId) : (UIR . 'Event/index/' . $eventId),
			'can_edit'         => $canEdit,
		]);
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

		$eventStartTs = $detail['EventStart'] ? strtotime($detail['EventStart']) : 0;
		if ($eventStartTs && time() < $eventStartTs - 86400) {
			$openLabel = date('D, M j, Y \\a\\t g:i A T', $eventStartTs - 86400);
			echo json_encode(['status' => 1, 'error' => 'Sign-ins for this event can be processed starting on ' . $openLabel . '.']);
			exit;
		}

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
			$DB->Clear();
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
					'ClassId'      => (int)($_POST['ClassId'] ?? 0),
					'Date'         => $_POST['AttendanceDate'] ?? date('Y-m-d'),
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
			Ork3::$Lib->dangeraudit->audit('Authorization::AddAuthorization', ['MundaneId' => $mid, 'Type' => AUTH_EVENT, 'Id' => $event_id, 'Role' => $role], 'Player', $mid, null, [
				'authorization_id' => $authId,
				'mundane_id'       => $mid,
				'park_id'          => 0,
				'kingdom_id'       => 0,
				'event_id'         => (int)$event_id,
				'unit_id'          => 0,
				'role'             => $role,
			]);
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
