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
			global $DB;
			$DB->Clear();
			$staffRow = $DB->DataSet('SELECT 1 FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . $detail_id . ' AND mundane_id = ' . $uid . ' AND can_attendance = 1 LIMIT 1');
			if (!($staffRow && $staffRow->Next())) {
				echo json_encode(['status' => 3, 'error' => 'Not authorized.']);
				exit;
			}
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

	// --- Event staff and schedule management methods ---

	public function add_staff($p = null) {
		header('Content-Type: application/json');

		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}

		$params    = explode('/', $p ?? '');
		$event_id  = (int)preg_replace('/[^0-9]/', '', $params[0] ?? '');
		$detail_id = (int)preg_replace('/[^0-9]/', '', $params[1] ?? '');

		if (!valid_id($event_id) || !valid_id($detail_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid Event ID.']);
			exit;
		}

		if (!Ork3::$Lib->authorization->HasAuthority((int)$this->session->user_id, AUTH_EVENT, $event_id, AUTH_CREATE)) {
			echo json_encode(['status' => 3, 'error' => 'Not authorized.']);
			exit;
		}

		$mundane_id    = (int)($_POST['MundaneId']    ?? 0);
		$role_name     = trim($_POST['RoleName']      ?? '');
		$can_manage    = (int)(bool)($_POST['CanManage']    ?? 0);
		$can_attendance = (int)(bool)($_POST['CanAttendance'] ?? 0);
		$can_schedule   = (int)(bool)($_POST['CanSchedule']   ?? 0);
		$can_feast      = (int)(bool)($_POST['CanFeast']      ?? 0);

		if (!valid_id($mundane_id)) {
			echo json_encode(['status' => 1, 'error' => 'A player must be selected.']);
			exit;
		}
		if (!$role_name) {
			echo json_encode(['status' => 1, 'error' => 'A role is required.']);
			exit;
		}

		global $DB;
		$role_safe = str_replace(["'", '\\'], ["''", '\\\\'], $role_name);
		$DB->Clear(); // reset stale bound params from prior ORM queries in this request
		$DB->Execute(
			'INSERT INTO ' . DB_PREFIX . 'event_staff
			(event_calendardetail_id, mundane_id, role_name, can_manage, can_attendance, can_schedule, can_feast)
			VALUES (' . $detail_id . ', ' . $mundane_id . ', \'' . $role_safe . '\', ' . $can_manage . ', ' . $can_attendance . ', ' . $can_schedule . ', ' . $can_feast . ')
			ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), can_manage = VALUES(can_manage), can_attendance = VALUES(can_attendance), can_schedule = VALUES(can_schedule), can_feast = VALUES(can_feast)'
		);
		$DB->Clear();
		$idrow = $DB->DataSet('SELECT event_staff_id FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . $detail_id . ' AND mundane_id = ' . $mundane_id . ' ORDER BY event_staff_id DESC LIMIT 1');
		$staff_id = ($idrow && $idrow->Next()) ? (int)$idrow->event_staff_id : 0;
		echo json_encode(['status' => 0, 'staff' => [
			'EventStaffId'  => $staff_id,
			'MundaneId'     => (int)$mundane_id,
			'Persona'       => trim($_POST['Persona'] ?? ''),
			'RoleName'      => $role_name,
			'CanManage'     => $can_manage,
			'CanAttendance' => $can_attendance,
			'CanSchedule'   => $can_schedule,
			'CanFeast'      => $can_feast,
		]]);
		exit;
	}

	public function remove_staff($p = null) {
		header('Content-Type: application/json');

		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}

		$params    = explode('/', $p ?? '');
		$event_id  = (int)preg_replace('/[^0-9]/', '', $params[0] ?? '');
		$detail_id = (int)preg_replace('/[^0-9]/', '', $params[1] ?? '');
		$staff_id  = (int)($_POST['StaffId'] ?? 0);

		if (!valid_id($event_id) || !valid_id($detail_id) || !valid_id($staff_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid parameters.']);
			exit;
		}

		if (!Ork3::$Lib->authorization->HasAuthority((int)$this->session->user_id, AUTH_EVENT, $event_id, AUTH_CREATE)) {
			echo json_encode(['status' => 3, 'error' => 'Not authorized.']);
			exit;
		}

		global $DB;
		$DB->Clear();
		$DB->Execute(
			'DELETE FROM ' . DB_PREFIX . 'event_staff
			WHERE event_staff_id = ' . $staff_id . ' AND event_calendardetail_id = ' . $detail_id
		);
		echo json_encode(['status' => 0]);
		exit;
	}

	public function add_schedule($p = null) {
		header('Content-Type: application/json');
		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']); exit;
		}

		$params    = explode('/', $p ?? '');
		$event_id  = (int)preg_replace('/[^0-9]/', '', $params[0] ?? '');
		$detail_id = (int)preg_replace('/[^0-9]/', '', $params[1] ?? '');

		if (!valid_id($event_id) || !valid_id($detail_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid Event ID.']); exit;
		}

		$uid = (int)$this->session->user_id;
		$is_admin = Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_EDIT);
		$can_schedule = false;
		$can_feast    = false;
		if (!$is_admin) {
			global $DB;
			$DB->Clear();
			$staffRow = $DB->DataSet('SELECT can_manage, can_schedule, can_feast FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . $detail_id . ' AND mundane_id = ' . $uid . ' LIMIT 1');
			if ($staffRow && $staffRow->Next()) {
				$can_schedule = (bool)(int)$staffRow->can_schedule || (bool)(int)$staffRow->can_manage;
				$can_feast    = (bool)(int)$staffRow->can_feast    || (bool)(int)$staffRow->can_manage;
			}
		} else {
			$can_schedule = true;
			$can_feast    = true;
		}

		$title       = trim($_POST['Title']       ?? '');
		$start_time  = trim($_POST['StartTime']   ?? '');
		$end_time    = trim($_POST['EndTime']     ?? '');
		$location    = trim($_POST['Location']    ?? '');
		$description = trim($_POST['Description'] ?? '');
		$category           = trim($_POST['Category']           ?? 'Other');
		$secondary_category = trim($_POST['SecondaryCategory']  ?? '');
		$allowed_cats = ['Administrative','Tournament','Battlegame','Arts and Sciences','Class','Feast and Food','Court','Meeting','Other'];
		if (!in_array($category, $allowed_cats)) $category = 'Other';
		if ($secondary_category !== '' && !in_array($secondary_category, $allowed_cats)) $secondary_category = '';

		// Feast-category rows require can_schedule OR can_feast; non-feast rows require can_schedule
		$is_feast = ($category === 'Feast and Food' || $secondary_category === 'Feast and Food');
		if ($is_feast) {
			if (!$can_schedule && !$can_feast) {
				echo json_encode(['status' => 3, 'error' => 'Not authorized.']); exit;
			}
		} else {
			if (!$can_schedule) {
				echo json_encode(['status' => 3, 'error' => 'Not authorized.']); exit;
			}
		}

		if (!$title)      { echo json_encode(['status' => 1, 'error' => 'A title is required.']); exit; }
		if (!$start_time) { echo json_encode(['status' => 1, 'error' => 'A start time is required.']); exit; }
		if (!$end_time)   { echo json_encode(['status' => 1, 'error' => 'An end time is required.']); exit; }

		$startTs = strtotime($start_time);
		$endTs   = strtotime($end_time);
		if (!$startTs || !$endTs)  { echo json_encode(['status' => 1, 'error' => 'Invalid time format.']); exit; }
		if ($endTs < $startTs)     { echo json_encode(['status' => 1, 'error' => 'End time cannot be before start time.']); exit; }

		// Meal fields — only accepted when user has can_feast
		$raw_menu      = trim($_POST['Menu']      ?? '');
		$raw_cost      = trim($_POST['Cost']      ?? '');
		$raw_dietary   = trim($_POST['Dietary']   ?? '');
		$raw_allergens = trim($_POST['Allergens'] ?? '');
		$menu      = ($can_feast && $raw_menu      !== '') ? $raw_menu      : null;
		$cost      = ($can_feast && $raw_cost      !== '' && is_numeric($raw_cost)) ? round((float)$raw_cost, 2) : null;
		$dietary   = ($can_feast && $raw_dietary   !== '') ? $raw_dietary   : null;
		$allergens = ($can_feast && $raw_allergens !== '') ? $raw_allergens : null;

		$title_safe       = str_replace(["'", '\\'], ["''", '\\\\'], $title);
		$location_safe    = str_replace(["'", '\\'], ["''", '\\\\'], $location);
		$description_safe = str_replace(["'", '\\'], ["''", '\\\\'], $description);
		$category_safe           = str_replace(["'", '\\'], ["''", '\\\\'], $category);
		$secondary_category_safe = str_replace(["'", '\\'], ["''", '\\\\'], $secondary_category);
		$menu_safe      = $menu      !== null ? str_replace(["'", '\\'], ["''", '\\\\'], $menu)      : null;
		$dietary_safe   = $dietary   !== null ? str_replace(["'", '\\'], ["''", '\\\\'], $dietary)   : null;
		$allergens_safe = $allergens !== null ? str_replace(["'", '\\'], ["''", '\\\\'], $allergens) : null;
		$menu_sql      = $menu_safe      !== null ? "'" . $menu_safe      . "'"  : 'NULL';
		$cost_sql      = $cost           !== null ? (string)$cost               : 'NULL';
		$dietary_sql   = $dietary_safe   !== null ? "'" . $dietary_safe   . "'"  : 'NULL';
		$allergens_sql = $allergens_safe !== null ? "'" . $allergens_safe . "'"  : 'NULL';
		$start_fmt = date('Y-m-d H:i:s', $startTs);
		$end_fmt   = date('Y-m-d H:i:s', $endTs);

		global $DB;
		$DB->Clear();
		$DB->Execute(
			'INSERT INTO ' . DB_PREFIX . 'event_schedule
			(event_calendardetail_id, title, start_time, end_time, location, description, category, secondary_category, menu, cost, dietary, allergens)
			VALUES (' . $detail_id . ', \'' . $title_safe . '\', \'' . $start_fmt . '\', \'' . $end_fmt . '\', \'' . $location_safe . '\', \'' . $description_safe . '\', \'' . $category_safe . '\', \'' . $secondary_category_safe . '\', ' . $menu_sql . ', ' . $cost_sql . ', ' . $dietary_sql . ', ' . $allergens_sql . ')'
		);
		$DB->Clear();
		$idrow = $DB->DataSet('SELECT event_schedule_id FROM ' . DB_PREFIX . 'event_schedule WHERE event_calendardetail_id = ' . $detail_id . ' ORDER BY event_schedule_id DESC LIMIT 1');
		$schedule_id = ($idrow && $idrow->Next()) ? (int)$idrow->event_schedule_id : 0;

		// Sync leads
		$leadsJson = trim($_POST['Leads'] ?? '');
		$leadsIn = ($leadsJson !== '') ? json_decode($leadsJson, true) : [];
		$leadsOut = [];
		if (is_array($leadsIn)) {
			$DB->Clear();
			foreach ($leadsIn as $lead) {
				$lmid = (int)($lead['MundaneId'] ?? 0);
				if (!valid_id($lmid)) continue;
				$DB->Execute('INSERT IGNORE INTO ' . DB_PREFIX . 'event_schedule_lead (event_schedule_id, mundane_id) VALUES (' . $schedule_id . ', ' . $lmid . ')');
				$leadsOut[] = ['MundaneId' => $lmid, 'Persona' => $lead['Persona'] ?? ''];
			}
		}

		echo json_encode(['status' => 0, 'schedule' => [
			'EventScheduleId'   => $schedule_id,
			'Title'             => $title,
			'StartTime'         => $start_fmt,
			'EndTime'           => $end_fmt,
			'Location'          => $location,
			'Description'       => $description,
			'Category'          => $category,
			'SecondaryCategory' => $secondary_category,
			'Menu'              => $menu,
			'Cost'              => $cost,
			'Dietary'           => $dietary,
			'Allergens'         => $allergens,
			'Leads'             => $leadsOut,
		]]);
		exit;
	}

	public function remove_schedule($p = null) {
		header('Content-Type: application/json');
		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']); exit;
		}

		$params      = explode('/', $p ?? '');
		$event_id    = (int)preg_replace('/[^0-9]/', '', $params[0] ?? '');
		$detail_id   = (int)preg_replace('/[^0-9]/', '', $params[1] ?? '');
		$schedule_id = (int)($_POST['ScheduleId'] ?? 0);

		if (!valid_id($event_id) || !valid_id($detail_id) || !valid_id($schedule_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid parameters.']); exit;
		}

		$uid = (int)$this->session->user_id;
		if (!Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_EDIT)) {
			global $DB;
			$DB->Clear();
			$staffRow = $DB->DataSet('SELECT 1 FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . $detail_id . ' AND mundane_id = ' . $uid . ' AND (can_manage = 1 OR can_schedule = 1) LIMIT 1');
			if (!($staffRow && $staffRow->Next())) {
				echo json_encode(['status' => 3, 'error' => 'Not authorized.']); exit;
			}
		}

		global $DB;
		$DB->Clear();
		$DB->Execute(
			'DELETE FROM ' . DB_PREFIX . 'event_schedule WHERE event_schedule_id = ' . $schedule_id . ' AND event_calendardetail_id = ' . $detail_id
		);
		echo json_encode(['status' => 0]);
		exit;
	}

	public function update_schedule($p = null) {
		header('Content-Type: application/json');
		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']); exit;
		}

		$params      = explode('/', $p ?? '');
		$event_id    = (int)preg_replace('/[^0-9]/', '', $params[0] ?? '');
		$detail_id   = (int)preg_replace('/[^0-9]/', '', $params[1] ?? '');
		$schedule_id = (int)($_POST['ScheduleId'] ?? 0);

		if (!valid_id($event_id) || !valid_id($detail_id) || !valid_id($schedule_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid parameters.']); exit;
		}

		$uid = (int)$this->session->user_id;
		$is_admin = Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_EDIT);
		$can_schedule = false;
		$can_feast    = false;
		if (!$is_admin) {
			global $DB;
			$DB->Clear();
			$staffRow = $DB->DataSet('SELECT can_manage, can_schedule, can_feast FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . $detail_id . ' AND mundane_id = ' . $uid . ' LIMIT 1');
			if ($staffRow && $staffRow->Next()) {
				$can_schedule = (bool)(int)$staffRow->can_schedule || (bool)(int)$staffRow->can_manage;
				$can_feast    = (bool)(int)$staffRow->can_feast    || (bool)(int)$staffRow->can_manage;
			}
		} else {
			$can_schedule = true;
			$can_feast    = true;
		}

		$title       = trim($_POST['Title']       ?? '');
		$start_time  = trim($_POST['StartTime']   ?? '');
		$end_time    = trim($_POST['EndTime']     ?? '');
		$location    = trim($_POST['Location']    ?? '');
		$description = trim($_POST['Description'] ?? '');
		$category           = trim($_POST['Category']           ?? 'Other');
		$secondary_category = trim($_POST['SecondaryCategory']  ?? '');
		$allowed_cats = ['Administrative','Tournament','Battlegame','Arts and Sciences','Class','Feast and Food','Court','Meeting','Other'];
		if (!in_array($category, $allowed_cats)) $category = 'Other';
		if ($secondary_category !== '' && !in_array($secondary_category, $allowed_cats)) $secondary_category = '';

		// Feast-category rows require can_schedule OR can_feast; non-feast rows require can_schedule
		$is_feast = ($category === 'Feast and Food' || $secondary_category === 'Feast and Food');
		if ($is_feast) {
			if (!$can_schedule && !$can_feast) {
				echo json_encode(['status' => 3, 'error' => 'Not authorized.']); exit;
			}
		} else {
			if (!$can_schedule) {
				echo json_encode(['status' => 3, 'error' => 'Not authorized.']); exit;
			}
		}

		if (!$title) { echo json_encode(['status' => 1, 'error' => 'A title is required.']); exit; }

		// Build SET clauses selectively based on permissions:
		// can_schedule controls time/location/description/category; can_feast controls meal fields; title is shared
		$set_parts = [];
		$title_safe = str_replace(["'", '\\'], ["''", '\\\\'], $title);
		$set_parts[] = 'title = \'' . $title_safe . '\'';

		$start_fmt = '';
		$end_fmt   = '';
		if ($can_schedule) {
			if (!$start_time) { echo json_encode(['status' => 1, 'error' => 'A start time is required.']); exit; }
			if (!$end_time)   { echo json_encode(['status' => 1, 'error' => 'An end time is required.']); exit; }
			$startTs = strtotime($start_time);
			$endTs   = strtotime($end_time);
			if (!$startTs || !$endTs)  { echo json_encode(['status' => 1, 'error' => 'Invalid time format.']); exit; }
			if ($endTs < $startTs)     { echo json_encode(['status' => 1, 'error' => 'End time cannot be before start time.']); exit; }
			$start_fmt = date('Y-m-d H:i:s', $startTs);
			$end_fmt   = date('Y-m-d H:i:s', $endTs);
			$location_safe    = str_replace(["'", '\\'], ["''", '\\\\'], $location);
			$description_safe = str_replace(["'", '\\'], ["''", '\\\\'], $description);
			$category_safe           = str_replace(["'", '\\'], ["''", '\\\\'], $category);
			$secondary_category_safe = str_replace(["'", '\\'], ["''", '\\\\'], $secondary_category);
			$set_parts[] = 'start_time = \'' . $start_fmt . '\'';
			$set_parts[] = 'end_time = \'' . $end_fmt . '\'';
			$set_parts[] = 'location = \'' . $location_safe . '\'';
			$set_parts[] = 'description = \'' . $description_safe . '\'';
			$set_parts[] = 'category = \'' . $category_safe . '\'';
			$set_parts[] = 'secondary_category = \'' . $secondary_category_safe . '\'';
		}

		if ($can_feast) {
			$raw_menu      = trim($_POST['Menu']      ?? '');
			$raw_cost      = trim($_POST['Cost']      ?? '');
			$raw_dietary   = trim($_POST['Dietary']   ?? '');
			$raw_allergens = trim($_POST['Allergens'] ?? '');
			$menu      = ($raw_menu      !== '') ? $raw_menu      : null;
			$cost      = ($raw_cost      !== '' && is_numeric($raw_cost)) ? round((float)$raw_cost, 2) : null;
			$dietary   = ($raw_dietary   !== '') ? $raw_dietary   : null;
			$allergens = ($raw_allergens !== '') ? $raw_allergens : null;
			$menu_sql      = $menu      !== null ? "'" . str_replace(["'", '\\'], ["''", '\\\\'], $menu)      . "'" : 'NULL';
			$cost_sql      = $cost      !== null ? (string)$cost                                                        : 'NULL';
			$dietary_sql   = $dietary   !== null ? "'" . str_replace(["'", '\\'], ["''", '\\\\'], $dietary)   . "'" : 'NULL';
			$allergens_sql = $allergens !== null ? "'" . str_replace(["'", '\\'], ["''", '\\\\'], $allergens) . "'" : 'NULL';
			$set_parts[] = 'menu = ' . $menu_sql;
			$set_parts[] = 'cost = ' . $cost_sql;
			$set_parts[] = 'dietary = ' . $dietary_sql;
			$set_parts[] = 'allergens = ' . $allergens_sql;
		} else {
			// No feast permission: read existing meal fields to echo back unchanged
			global $DB;
			$DB->Clear();
			$existRow = $DB->DataSet('SELECT menu, cost, dietary, allergens FROM ' . DB_PREFIX . 'event_schedule WHERE event_schedule_id = ' . $schedule_id . ' LIMIT 1');
			$menu      = ($existRow && $existRow->Next()) ? $existRow->menu      : null;
			$cost      = $existRow ? ($existRow->cost !== null ? (float)$existRow->cost : null) : null;
			$dietary   = $existRow ? $existRow->dietary   : null;
			$allergens = $existRow ? $existRow->allergens : null;
		}

		global $DB;
		$DB->Clear();
		$DB->Execute(
			'UPDATE ' . DB_PREFIX . 'event_schedule SET ' . implode(', ', $set_parts) .
			' WHERE event_schedule_id = ' . $schedule_id . ' AND event_calendardetail_id = ' . $detail_id
		);

		// Sync leads (replace all) -- schedule permission required
		$leadsJson = trim($_POST['Leads'] ?? '');
		$leadsIn = ($leadsJson !== '') ? json_decode($leadsJson, true) : [];
		$leadsOut = [];
		if ($can_schedule && is_array($leadsIn)) {
			$DB->Clear();
			$DB->Execute('DELETE FROM ' . DB_PREFIX . 'event_schedule_lead WHERE event_schedule_id = ' . $schedule_id);
			foreach ($leadsIn as $lead) {
				$lmid = (int)($lead['MundaneId'] ?? 0);
				if (!valid_id($lmid)) continue;
				$DB->Execute('INSERT IGNORE INTO ' . DB_PREFIX . 'event_schedule_lead (event_schedule_id, mundane_id) VALUES (' . $schedule_id . ', ' . $lmid . ')');
				$leadsOut[] = ['MundaneId' => $lmid, 'Persona' => $lead['Persona'] ?? ''];
			}
		}

		echo json_encode(['status' => 0, 'schedule' => [
			'EventScheduleId'   => $schedule_id,
			'Title'             => $title,
			'StartTime'         => $start_fmt ?: null,
			'EndTime'           => $end_fmt   ?: null,
			'Location'          => $can_schedule ? $location    : null,
			'Description'       => $can_schedule ? $description : null,
			'Category'          => $can_schedule ? $category    : null,
			'SecondaryCategory' => $can_schedule ? $secondary_category : null,
			'Menu'              => isset($menu)      ? $menu      : null,
			'Cost'              => isset($cost)      ? $cost      : null,
			'Dietary'           => isset($dietary)   ? $dietary   : null,
			'Allergens'         => isset($allergens) ? $allergens : null,
			'Leads'             => $leadsOut,
		]]);
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

		$hUid = (int)$this->session->user_id;
		$hCanManage = Ork3::$Lib->authorization->HasAuthority($hUid, AUTH_EVENT, $event_id, AUTH_EDIT);
		if (!$hCanManage) {
			global $DB;
			$DB->Clear();
			$staffRow = $DB->DataSet('SELECT 1 FROM ' . DB_PREFIX . 'event_staff s JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = s.event_calendardetail_id WHERE cd.event_id = ' . $event_id . ' AND s.mundane_id = ' . $hUid . ' AND s.can_manage = 1 LIMIT 1');
			$hCanManage = $staffRow && $staffRow->Next();
		}
		if (!$hCanManage) {
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
