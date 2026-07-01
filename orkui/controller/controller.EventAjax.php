<?php

class Controller_EventAjax extends Controller
{
    public function create($p = null)
    {
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
    public function set_status($p = null)
    {
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
        // Either AUTH_EVENT/EDIT on the event *or* a can_manage staff row on
        // any of the event's occurrences. Staff rows are per-detail, so the
        // event-level status flip needs the join to cover any of them.
        // Mirrors the CanManageEvent check in controller.Event.php so the
        // "Hide as draft" / "Publish" buttons — which are gated on
        // CanManageEvent — actually work when a staff can_manage user clicks
        // them (previously the buttons rendered but the submit was rejected).
        global $DB;
        $authorized = Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $evtId, AUTH_EDIT);
        if (!$authorized) {
            $DB->Clear();
            $mgrRow = $DB->DataSet('SELECT 1 FROM ' . DB_PREFIX . 'event_staff es JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = es.event_calendardetail_id WHERE cd.event_id = ' . $evtId . ' AND es.mundane_id = ' . $uid . ' AND es.can_manage = 1 LIMIT 1');
            $authorized = ($mgrRow && $mgrRow->Next());
        }
        if (!$authorized) {
            echo json_encode(['status' => 5, 'error' => 'Not authorized']);
            exit;
        }
        $DB->Clear();
        // A2: $status is whitelisted to 'published'|'draft' above; safe to interpolate. Drop mysql_real_escape_string (fatal under PHP 8).
        $DB->Execute("UPDATE " . DB_PREFIX . "event SET status = '" . $status . "' WHERE event_id = " . $evtId);
        // Bust SearchService.Event memcache for this event.
        Ork3::$Lib->ghettocache->bust_event_search($evtId);
        // NF1: Also bust Event.GetActiveEventsAtScope for park+kingdom across each
        // calendardetail date so a publish/unpublish flip propagates immediately to
        // Attendance/park and Attendance/kingdom pages (otherwise stale up to 60s).
        $DB->Clear();
        $evRow = $DB->DataSet("SELECT park_id, kingdom_id FROM " . DB_PREFIX . "event WHERE event_id = " . $evtId . " LIMIT 1");
        if ($evRow && $evRow->Next()) {
            $_parkId = (int)$evRow->park_id;
            $_kingdomId = (int)$evRow->kingdom_id;
            $DB->Clear();
            $_dates = $DB->DataSet("SELECT DISTINCT DATE(event_start) AS d FROM " . DB_PREFIX . "event_calendardetail WHERE event_id = " . $evtId . " AND event_end >= NOW()");
            while ($_dates && $_dates->Next()) {
                $_d = (string)$_dates->d;
                if ($_parkId > 0) {
                    $_k = Ork3::$Lib->ghettocache->key(['Scope' => 'park', 'ScopeId' => $_parkId, 'Date' => $_d]);
                    Ork3::$Lib->ghettocache->bust('Event.GetActiveEventsAtScope', $_k);
                }
                if ($_kingdomId > 0) {
                    $_k = Ork3::$Lib->ghettocache->key(['Scope' => 'kingdom', 'ScopeId' => $_kingdomId, 'Date' => $_d]);
                    Ork3::$Lib->ghettocache->bust('Event.GetActiveEventsAtScope', $_k);
                }
            }
        }
        echo json_encode(['status' => 0, 'event_status' => $status]);
        exit;
    }

    // Lightweight event preview for the calendar grid quick-look modal.
    // Path: EventAjax/preview/{event_id}/{detail_id}
    public function preview($p = null)
    {
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
            if ($cdRs && $cdRs->Next()) {
                $cd = $cdRs;
            }
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
        $going = 0;
        $interested = 0;
        $myRsvp = '';
        if ($detailId > 0) {
            $DB->Clear();
            $rs = $DB->DataSet("
				SELECT
					SUM(CASE WHEN status = 'going'      THEN 1 ELSE 0 END) AS g,
					SUM(CASE WHEN status = 'interested' THEN 1 ELSE 0 END) AS i
				FROM " . DB_PREFIX . "event_rsvp WHERE event_calendardetail_id = {$detailId}");
            if ($rs && $rs->Next()) {
                $going = (int)$rs->g;
                $interested = (int)$rs->i;
            }
            if ($uid > 0) {
                $DB->Clear();
                $mrs = $DB->DataSet("
					SELECT status FROM " . DB_PREFIX . "event_rsvp
					WHERE event_calendardetail_id = {$detailId} AND mundane_id = {$uid} LIMIT 1");
                if ($mrs && $mrs->Next()) {
                    $myRsvp = (string)$mrs->status;
                }
            }
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
        $endTs   = $cd ? strtotime($cd->event_end) : 0;
        $dateLabel = $startTs ? date('l, F j, Y', $startTs) : '';
        $timeLabel = '';
        if ($startTs) {
            $timeLabel = date('g:i A', $startTs);
            if ($endTs && $endTs > $startTs) {
                $timeLabel .= ' – ' . date('g:i A', $endTs);
            }
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
            'detail_url'       => $detailId > 0 ? (UIR . 'Event/detail/' . $eventId . '/' . $detailId) : (UIR . 'Event/index/' . $eventId),
            'can_edit'         => $canEdit,
        ]);
        exit;
    }

    public function add_attendance($p = null)
    {
        header('Content-Type: application/json');

        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }

        $this->load_model('Attendance');

        $params    = explode('/', $p ?? '');
        $event_id  = (int)preg_replace('/[^0-9]/', '', $params[0] ?? '');
        $detail_id = (int)preg_replace('/[^0-9]/', '', $params[1] ?? '');

        if (!valid_id($event_id) || !valid_id($detail_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid Event ID.']);
            exit;
        }

        $uid = (int)$this->session->user_id;
        if (!Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_CREATE)) {
            global $DB;
            $DB->Clear();
            // Cross-validate $detail_id belongs to $event_id via the join on event_calendardetail.
            $staffRow = $DB->DataSet('SELECT 1 FROM ' . DB_PREFIX . 'event_staff es JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = es.event_calendardetail_id WHERE es.event_calendardetail_id = ' . $detail_id . ' AND cd.event_id = ' . $event_id . ' AND es.mundane_id = ' . $uid . ' AND es.can_attendance = 1 LIMIT 1');
            if (!($staffRow && $staffRow->Next())) {
                echo json_encode(['status' => 3, 'error' => 'Not authorized.']);
                exit;
            }
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

    public function delete_rsvp($p = null)
    {
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

    public function cancel($p = null)
    {
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

    public function auth($p = null)
    {
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

        $uid = (int)$this->session->user_id;

        if ($action === 'playersearch') {
            if (!Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_CREATE)) {
                echo json_encode([]);
                exit;
            }
            $q = trim($_GET['q'] ?? '');
            if (strlen($q) < 2) {
                echo json_encode([]);
                exit;
            }
            global $DB;
            $term = str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $q);
            // Get event's park and kingdom to prioritize local players in results
            $DB->Clear();
            $evRow = $DB->DataSet("SELECT park_id, kingdom_id FROM " . DB_PREFIX . "event WHERE event_id = {$event_id} LIMIT 1");
            $haveRow     = ($evRow && $evRow->Next());
            $evParkId    = $haveRow ? (int)$evRow->park_id : 0;
            $evKingdomId = $haveRow ? (int)$evRow->kingdom_id : 0;
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
                echo json_encode(['status' => 5, 'error' => 'Not authorized.']);
                exit;
            }
            $mid  = (int)($_POST['MundaneId'] ?? 0);
            $role = in_array($_POST['Role'] ?? '', ['create', 'edit']) ? $_POST['Role'] : 'create';
            if (!$mid) {
                echo json_encode(['status' => 1, 'error' => 'Invalid player.']);
                exit;
            }
            global $DB;
            $DB->Clear();
            $DB->Execute("INSERT INTO " . DB_PREFIX . "authorization (mundane_id, park_id, kingdom_id, event_id, unit_id, role, modified)
				VALUES ({$mid}, 0, 0, {$event_id}, 0, '{$role}', NOW())");
            $DB->Clear();
            $rs = $DB->DataSet("SELECT a.authorization_id, m.persona FROM " . DB_PREFIX . "authorization a
				LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = a.mundane_id
				WHERE a.mundane_id = {$mid} AND a.event_id = {$event_id}
				ORDER BY a.authorization_id DESC LIMIT 1");
            $authId = 0;
            $persona = '';
            if ($rs && $rs->Next()) {
                $authId = (int)$rs->authorization_id;
                $persona = $rs->persona;
            }
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
                echo json_encode(['status' => 5, 'error' => 'Not authorized.']);
                exit;
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

    public function add_staff($p = null)
    {
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

        // Anyone with AUTH_EVENT/CREATE (traditional event admin) *or* an
        // event_staff.can_manage row for this detail may add other staff.
        // The staff-manage capability was previously only honored by
        // add_schedule/etc. — leaving staff add/remove admin-only defeated
        // the point of delegating "Can Manage".
        $uid = (int)$this->session->user_id;
        $authorized = Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_CREATE);
        if (!$authorized) {
            global $DB;
            $DB->Clear();
            $mgrRow = $DB->DataSet('SELECT 1 FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . $detail_id . ' AND mundane_id = ' . $uid . ' AND can_manage = 1 LIMIT 1');
            $authorized = ($mgrRow && $mgrRow->Next());
        }
        if (!$authorized) {
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
        // Capture prior state so the audit log distinguishes Add from Update
        // (upsert emits one row either way; the diff is only meaningful with
        // prior_state populated).
        $DB->Clear();
        $priorRs = $DB->DataSet('SELECT event_staff_id, role_name, can_manage, can_attendance, can_schedule, can_feast FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . $detail_id . ' AND mundane_id = ' . $mundane_id . ' LIMIT 1');
        $priorState = null;
        if ($priorRs && $priorRs->Next()) {
            $priorState = [
                'event_staff_id' => (int)$priorRs->event_staff_id,
                'role_name'      => (string)$priorRs->role_name,
                'can_manage'     => (int)$priorRs->can_manage,
                'can_attendance' => (int)$priorRs->can_attendance,
                'can_schedule'   => (int)$priorRs->can_schedule,
                'can_feast'      => (int)$priorRs->can_feast,
            ];
        }
        $DB->Clear(); // reset stale bound params from prior ORM queries in this request
        $DB->Execute(
            'INSERT INTO ' . DB_PREFIX . 'event_staff
			(event_calendardetail_id, mundane_id, role_name, can_manage, can_attendance, can_schedule, can_feast)
			VALUES (' . $detail_id . ', ' . $mundane_id . ', \'' . $role_safe . '\', ' . $can_manage . ', ' . $can_attendance . ', ' . $can_schedule . ', ' . $can_feast . ')
			ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), can_manage = VALUES(can_manage), can_attendance = VALUES(can_attendance), can_schedule = VALUES(can_schedule), can_feast = VALUES(can_feast)'
        );
        $DB->Clear();
        $idrow = $DB->DataSet('SELECT s.event_staff_id, m.persona FROM ' . DB_PREFIX . 'event_staff s LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = s.mundane_id WHERE s.event_calendardetail_id = ' . $detail_id . ' AND s.mundane_id = ' . $mundane_id . ' ORDER BY s.event_staff_id DESC LIMIT 1');
        $fetched   = ($idrow && $idrow->Next());
        $staff_id  = $fetched ? (int)$idrow->event_staff_id : 0;
        $persona   = $fetched ? (string)$idrow->persona : '';
        Ork3::$Lib->dangeraudit->audit(
            $priorState ? 'EventStaff::Update' : 'EventStaff::Add',
            [
                'EventId'       => $event_id,
                'DetailId'      => $detail_id,
                'MundaneId'     => $mundane_id,
                'RoleName'      => $role_name,
                'CanManage'     => $can_manage,
                'CanAttendance' => $can_attendance,
                'CanSchedule'   => $can_schedule,
                'CanFeast'      => $can_feast,
            ],
            'Event',
            $event_id,
            $priorState,
            [
                'event_staff_id' => $staff_id,
                'role_name'      => $role_name,
                'can_manage'     => $can_manage,
                'can_attendance' => $can_attendance,
                'can_schedule'   => $can_schedule,
                'can_feast'      => $can_feast,
            ]
        );
        echo json_encode(['status' => 0, 'staff' => [
            'EventStaffId'  => $staff_id,
            'MundaneId'     => (int)$mundane_id,
            'Persona'       => $persona,
            'RoleName'      => $role_name,
            'CanManage'     => $can_manage,
            'CanAttendance' => $can_attendance,
            'CanSchedule'   => $can_schedule,
            'CanFeast'      => $can_feast,
        ]]);
        exit;
    }

    public function remove_staff($p = null)
    {
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

        // Mirror add_staff: an event admin *or* a staff row with can_manage
        // may remove staff. Otherwise a delegated manager could add staff
        // but not undo their own mistake.
        $uid = (int)$this->session->user_id;
        $authorized = Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_CREATE);
        global $DB;
        if (!$authorized) {
            $DB->Clear();
            $mgrRow = $DB->DataSet('SELECT 1 FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . $detail_id . ' AND mundane_id = ' . $uid . ' AND can_manage = 1 LIMIT 1');
            $authorized = ($mgrRow && $mgrRow->Next());
        }
        if (!$authorized) {
            echo json_encode(['status' => 3, 'error' => 'Not authorized.']);
            exit;
        }

        // Capture the row before delete so the audit log has prior_state.
        $DB->Clear();
        $priorRs = $DB->DataSet('SELECT event_staff_id, mundane_id, role_name, can_manage, can_attendance, can_schedule, can_feast FROM ' . DB_PREFIX . 'event_staff WHERE event_staff_id = ' . $staff_id . ' AND event_calendardetail_id = ' . $detail_id . ' LIMIT 1');
        $priorState = null;
        if ($priorRs && $priorRs->Next()) {
            $priorState = [
                'event_staff_id' => (int)$priorRs->event_staff_id,
                'mundane_id'     => (int)$priorRs->mundane_id,
                'role_name'      => (string)$priorRs->role_name,
                'can_manage'     => (int)$priorRs->can_manage,
                'can_attendance' => (int)$priorRs->can_attendance,
                'can_schedule'   => (int)$priorRs->can_schedule,
                'can_feast'      => (int)$priorRs->can_feast,
            ];
        }
        $DB->Clear();
        $DB->Execute(
            'DELETE FROM ' . DB_PREFIX . 'event_staff
			WHERE event_staff_id = ' . $staff_id . ' AND event_calendardetail_id = ' . $detail_id
        );
        if ($priorState) {
            Ork3::$Lib->dangeraudit->audit(
                'EventStaff::Remove',
                ['EventId' => $event_id, 'DetailId' => $detail_id, 'StaffId' => $staff_id],
                'Event',
                $event_id,
                $priorState,
                null
            );
        }
        echo json_encode(['status' => 0]);
        exit;
    }

    public function add_schedule($p = null)
    {
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
        if (!in_array($category, $allowed_cats)) {
            $category = 'Other';
        }
        if ($secondary_category !== '' && !in_array($secondary_category, $allowed_cats)) {
            $secondary_category = '';
        }

        // Feast-category rows require can_schedule OR can_feast; non-feast rows require can_schedule
        $is_feast = ($category === 'Feast and Food' || $secondary_category === 'Feast and Food');
        if ($is_feast) {
            if (!$can_schedule && !$can_feast) {
                echo json_encode(['status' => 3, 'error' => 'Not authorized.']);
                exit;
            }
        } else {
            if (!$can_schedule) {
                echo json_encode(['status' => 3, 'error' => 'Not authorized.']);
                exit;
            }
        }

        if (!$title) {
            echo json_encode(['status' => 1, 'error' => 'A title is required.']);
            exit;
        }
        if (!$start_time) {
            echo json_encode(['status' => 1, 'error' => 'A start time is required.']);
            exit;
        }
        if (!$end_time) {
            echo json_encode(['status' => 1, 'error' => 'An end time is required.']);
            exit;
        }

        $startTs = strtotime($start_time);
        $endTs   = strtotime($end_time);
        if (!$startTs || !$endTs) {
            echo json_encode(['status' => 1, 'error' => 'Invalid time format.']);
            exit;
        }
        if ($endTs < $startTs) {
            echo json_encode(['status' => 1, 'error' => 'End time cannot be before start time.']);
            exit;
        }

        // Meal fields — only accepted when user has can_feast
        $raw_menu      = trim($_POST['Menu']      ?? '');
        $raw_cost      = trim($_POST['Cost']      ?? '');
        $raw_dietary   = trim($_POST['Dietary']   ?? '');
        $raw_allergens = trim($_POST['Allergens'] ?? '');
        $menu      = ($can_feast && $raw_menu      !== '') ? $raw_menu : null;
        $cost      = ($can_feast && $raw_cost      !== '' && is_numeric($raw_cost)) ? round((float)$raw_cost, 2) : null;
        $dietary   = ($can_feast && $raw_dietary   !== '') ? $raw_dietary : null;
        $allergens = ($can_feast && $raw_allergens !== '') ? $raw_allergens : null;

        $title_safe       = str_replace(["'", '\\'], ["''", '\\\\'], $title);
        $location_safe    = str_replace(["'", '\\'], ["''", '\\\\'], $location);
        $description_safe = str_replace(["'", '\\'], ["''", '\\\\'], $description);
        $category_safe           = str_replace(["'", '\\'], ["''", '\\\\'], $category);
        $secondary_category_safe = str_replace(["'", '\\'], ["''", '\\\\'], $secondary_category);
        $menu_safe      = $menu      !== null ? str_replace(["'", '\\'], ["''", '\\\\'], $menu) : null;
        $dietary_safe   = $dietary   !== null ? str_replace(["'", '\\'], ["''", '\\\\'], $dietary) : null;
        $allergens_safe = $allergens !== null ? str_replace(["'", '\\'], ["''", '\\\\'], $allergens) : null;
        $menu_sql      = $menu_safe      !== null ? "'" . $menu_safe      . "'" : 'NULL';
        $cost_sql      = $cost           !== null ? (string)$cost : 'NULL';
        $dietary_sql   = $dietary_safe   !== null ? "'" . $dietary_safe   . "'" : 'NULL';
        $allergens_sql = $allergens_safe !== null ? "'" . $allergens_safe . "'" : 'NULL';
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
            // A16: $DB->Clear() per-iteration so prior PDO bindings can't silently break the INSERT.
            foreach ($leadsIn as $lead) {
                $lmid = (int)($lead['MundaneId'] ?? 0);
                if (!valid_id($lmid)) {
                    continue;
                }
                $DB->Clear();
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

    public function remove_schedule($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }

        $params      = explode('/', $p ?? '');
        $event_id    = (int)preg_replace('/[^0-9]/', '', $params[0] ?? '');
        $detail_id   = (int)preg_replace('/[^0-9]/', '', $params[1] ?? '');
        $schedule_id = (int)($_POST['ScheduleId'] ?? 0);

        if (!valid_id($event_id) || !valid_id($detail_id) || !valid_id($schedule_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid parameters.']);
            exit;
        }

        $uid = (int)$this->session->user_id;
        if (!Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_EDIT)) {
            global $DB;
            // A17: Look up the schedule row's category so feast-only staff can delete
            // feast items (parity with feast tab's permissive UI).
            $DB->Clear();
            $catRow = $DB->DataSet('SELECT category, secondary_category FROM ' . DB_PREFIX . 'event_schedule WHERE event_schedule_id = ' . (int)$schedule_id . ' AND event_calendardetail_id = ' . (int)$detail_id . ' LIMIT 1');
            $_isFeast = false;
            if ($catRow && $catRow->Next()) {
                $_cat  = (string)($catRow->category ?? '');
                $_scat = (string)($catRow->secondary_category ?? '');
                $_isFeast = ($_cat === 'Feast and Food' || $_scat === 'Feast and Food');
            }
            $DB->Clear();
            $_staffSql = $_isFeast
                ? 'SELECT 1 FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . (int)$detail_id . ' AND mundane_id = ' . (int)$uid . ' AND (can_manage = 1 OR can_schedule = 1 OR can_feast = 1) LIMIT 1'
                : 'SELECT 1 FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . (int)$detail_id . ' AND mundane_id = ' . (int)$uid . ' AND (can_manage = 1 OR can_schedule = 1) LIMIT 1';
            $staffRow = $DB->DataSet($_staffSql);
            if (!($staffRow && $staffRow->Next())) {
                echo json_encode(['status' => 3, 'error' => 'Not authorized.']);
                exit;
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

    public function update_schedule($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }

        $params      = explode('/', $p ?? '');
        $event_id    = (int)preg_replace('/[^0-9]/', '', $params[0] ?? '');
        $detail_id   = (int)preg_replace('/[^0-9]/', '', $params[1] ?? '');
        $schedule_id = (int)($_POST['ScheduleId'] ?? 0);

        if (!valid_id($event_id) || !valid_id($detail_id) || !valid_id($schedule_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid parameters.']);
            exit;
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
        if (!in_array($category, $allowed_cats)) {
            $category = 'Other';
        }
        if ($secondary_category !== '' && !in_array($secondary_category, $allowed_cats)) {
            $secondary_category = '';
        }

        // Feast-category rows require can_schedule OR can_feast; non-feast rows require can_schedule
        $is_feast = ($category === 'Feast and Food' || $secondary_category === 'Feast and Food');
        if ($is_feast) {
            if (!$can_schedule && !$can_feast) {
                echo json_encode(['status' => 3, 'error' => 'Not authorized.']);
                exit;
            }
        } else {
            if (!$can_schedule) {
                echo json_encode(['status' => 3, 'error' => 'Not authorized.']);
                exit;
            }
        }

        if (!$title) {
            echo json_encode(['status' => 1, 'error' => 'A title is required.']);
            exit;
        }

        // Build SET clauses selectively based on permissions:
        // can_schedule controls time/location/description/category; can_feast controls meal fields; title is shared
        $set_parts = [];
        $title_safe = str_replace(["'", '\\'], ["''", '\\\\'], $title);
        $set_parts[] = 'title = \'' . $title_safe . '\'';

        $start_fmt = '';
        $end_fmt   = '';
        if ($can_schedule) {
            if (!$start_time) {
                echo json_encode(['status' => 1, 'error' => 'A start time is required.']);
                exit;
            }
            if (!$end_time) {
                echo json_encode(['status' => 1, 'error' => 'An end time is required.']);
                exit;
            }
            $startTs = strtotime($start_time);
            $endTs   = strtotime($end_time);
            if (!$startTs || !$endTs) {
                echo json_encode(['status' => 1, 'error' => 'Invalid time format.']);
                exit;
            }
            if ($endTs < $startTs) {
                echo json_encode(['status' => 1, 'error' => 'End time cannot be before start time.']);
                exit;
            }
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
            $menu      = ($raw_menu      !== '') ? $raw_menu : null;
            $cost      = ($raw_cost      !== '' && is_numeric($raw_cost)) ? round((float)$raw_cost, 2) : null;
            $dietary   = ($raw_dietary   !== '') ? $raw_dietary : null;
            $allergens = ($raw_allergens !== '') ? $raw_allergens : null;
            $menu_sql      = $menu      !== null ? "'" . str_replace(["'", '\\'], ["''", '\\\\'], $menu)      . "'" : 'NULL';
            $cost_sql      = $cost      !== null ? (string)$cost : 'NULL';
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
            $_found = ($existRow && $existRow->Next());
            if (!$_found) {
                echo json_encode(['status' => 1, 'error' => 'Schedule item not found.']);
                exit;
            }
            $menu      = $existRow->menu;
            $cost      = $existRow->cost !== null ? (float)$existRow->cost : null;
            $dietary   = $existRow->dietary;
            $allergens = $existRow->allergens;
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
            // A16: $DB->Clear() per-iteration so prior PDO bindings can't silently break the INSERT.
            foreach ($leadsIn as $lead) {
                $lmid = (int)($lead['MundaneId'] ?? 0);
                if (!valid_id($lmid)) {
                    continue;
                }
                $DB->Clear();
                $DB->Execute('INSERT IGNORE INTO ' . DB_PREFIX . 'event_schedule_lead (event_schedule_id, mundane_id) VALUES (' . $schedule_id . ', ' . $lmid . ')');
                $leadsOut[] = ['MundaneId' => $lmid, 'Persona' => $lead['Persona'] ?? ''];
            }
        }

        echo json_encode(['status' => 0, 'schedule' => [
            'EventScheduleId'   => $schedule_id,
            'Title'             => $title,
            // A19: emit empty string (not null) so JS-side .replace() stays compatible when can_schedule=false.
            'StartTime'         => $start_fmt ?: '',
            'EndTime'           => $end_fmt ?: '',
            'Location'          => $can_schedule ? $location : null,
            'Description'       => $can_schedule ? $description : null,
            'Category'          => $can_schedule ? $category : null,
            'SecondaryCategory' => $can_schedule ? $secondary_category : null,
            'Menu'              => isset($menu) ? $menu : null,
            'Cost'              => isset($cost) ? $cost : null,
            'Dietary'           => isset($dietary) ? $dietary : null,
            'Allergens'         => isset($allergens) ? $allergens : null,
            'Leads'             => $leadsOut,
        ]]);
        exit;
    }


    public function heraldry($p = null)
    {
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
            $DB->Clear();
            $DB->Execute('UPDATE ' . DB_PREFIX . 'event SET has_heraldry = 0 WHERE event_id = ' . $event_id);
            $base = DIR_EVENT_HERALDRY . sprintf('%05d', $event_id);
            if (file_exists($base . '.jpg')) {
                unlink($base . '.jpg');
            }
            if (file_exists($base . '.png')) {
                unlink($base . '.png');
            }
            $this->_bustEventSearchCache($event_id);
            echo json_encode(['status' => 0]);
            exit;
        }

        if ($action === 'update') {
            if (empty($_FILES['Heraldry']['tmp_name'])) {
                echo json_encode(['status' => 1, 'error' => 'No file uploaded.']);
                exit;
            }
            // A7: validate the upload came via a real HTTP file upload (prevents spoofing).
            if (!is_uploaded_file($_FILES['Heraldry']['tmp_name'])) {
                echo json_encode(['status' => 1, 'error' => 'Invalid upload.']);
                exit;
            }
            // A7: server-side file size check (max 1 MB).
            if (($_FILES['Heraldry']['size'] ?? 0) > 1024 * 1024) {
                echo json_encode(['status' => 1, 'error' => 'File too large (max 1 MB).']);
                exit;
            }
            $tmp  = $_FILES['Heraldry']['tmp_name'];
            // A7: use exif_imagetype() (magic-byte check) instead of the
            // browser-supplied MIME type, which is trivially spoofable. Only
            // JPEG and PNG are supported downstream.
            $detectedType = exif_imagetype($tmp);
            if ($detectedType !== IMAGETYPE_JPEG && $detectedType !== IMAGETYPE_PNG) {
                echo json_encode(['status' => 1, 'error' => 'Only JPEG and PNG images are supported.']);
                exit;
            }
            $mime = ($detectedType === IMAGETYPE_PNG) ? 'image/png' : 'image/jpeg';
            $r = Ork3::$Lib->heraldry->SetEventHeraldry([
                'Token'            => $this->session->token,
                'EventId'          => $event_id,
                'Heraldry'         => base64_encode(file_get_contents($tmp)),
                'HeraldryMimeType' => $mime,
            ]);
            if (isset($r['Status']) && $r['Status'] == 0) {
                $this->_bustEventSearchCache($event_id);
                echo json_encode(['status' => 0]);
            } else {
                echo json_encode(['status' => 1, 'error' => $r['Error'] ?? 'Upload failed.']);
            }
            exit;
        }

        echo json_encode(['status' => 1, 'error' => 'Unknown action.']);
        exit;
    }

    public function copy_source_list($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }

        $kingdom_id = (int)($_GET['KingdomId'] ?? 0);
        $park_id    = (int)($_GET['ParkId']    ?? 0);
        $query      = trim((string)($_GET['Query'] ?? ''));
        $exclude    = (int)($_GET['ExcludeEventId'] ?? 0);

        if (!valid_id($kingdom_id) && !valid_id($park_id)) {
            echo json_encode(['status' => 1, 'error' => 'A kingdom or park is required.']);
            exit;
        }

        // Auth: matches EventAjax::create — session-gated. The dropdown only exposes
        // event names + dates (already public site-wide).

        if (valid_id($park_id)) {
            $scope_where = 'e.park_id = ' . $park_id;
        } else {
            $scope_where = 'e.kingdom_id = ' . $kingdom_id . ' AND (e.park_id IS NULL OR e.park_id = 0)';
        }

        $name_where = '';
        if ($query !== '') {
            $safe = str_replace(['\\', '%', '_', "'"], ['\\\\', '\\%', '\\_', "''"], $query);
            $name_where = " AND e.name LIKE '%" . $safe . "%'";
        }

        $exclude_where = $exclude > 0 ? ' AND e.event_id != ' . $exclude : '';

        global $DB;
        $DB->Clear();
        // Past-only: drop events whose occurrences are all in the future, and
        // for events with mixed history make MAX(last_start) reflect the most
        // recent *past* occurrence (so the order surfaces "recently held" first).
        $sql = 'SELECT e.event_id, e.name,
					MAX(cd.event_start) AS last_start,
					MAX(cd.event_end)   AS last_end,
					COUNT(cd.event_calendardetail_id) AS occ_count
				FROM ' . DB_PREFIX . 'event e
				JOIN ' . DB_PREFIX . 'event_calendardetail cd
				  ON cd.event_id = e.event_id
				 AND cd.event_start < NOW()
				WHERE ' . $scope_where . $exclude_where . "
				  AND e.status = 'published'" . $name_where . '
				GROUP BY e.event_id
				HAVING last_start IS NOT NULL
				ORDER BY last_start DESC
				LIMIT 25';
        $rs = $DB->DataSet($sql);
        $results = [];
        if ($rs) {
            while ($rs->Next()) {
                $results[] = [
                    'eventId'         => (int)$rs->event_id,
                    'name'            => (string)$rs->name,
                    'lastStart'       => (string)$rs->last_start,
                    'lastEnd'         => (string)$rs->last_end,
                    'occurrenceCount' => (int)$rs->occ_count,
                ];
            }
        }
        echo json_encode(['status' => 0, 'results' => $results]);
        exit;
    }


    public function create_with_copy($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }

        $uid         = (int)$this->session->user_id;
        $name        = trim($_POST['Name']          ?? '');
        $kingdom_id  = (int)($_POST['KingdomId']    ?? 0);
        $park_id     = (int)($_POST['ParkId']       ?? 0);
        $src_evt_id  = (int)($_POST['SourceEventId'] ?? 0);
        $new_start   = trim($_POST['NewStart']      ?? '');
        $new_end     = trim($_POST['NewEnd']        ?? '');
        $modules_raw = trim($_POST['Modules']       ?? '{}');
        $status_in   = (string)($_POST['Status']    ?? 'published');

        $modules = json_decode($modules_raw, true);
        if (!is_array($modules)) {
            $modules = [];
        }
        $mod = [
            'details'  => !empty($modules['details']),
            'schedule' => !empty($modules['schedule']),
            'staff'    => !empty($modules['staff']),
            'feast'    => !empty($modules['feast']),
            'banner'   => !empty($modules['banner']),
        ];

        if (!strlen($name)) {
            echo json_encode(['status' => 1, 'error' => 'Event name is required.']);
            exit;
        }
        if (!valid_id($kingdom_id) && !valid_id($park_id)) {
            echo json_encode(['status' => 1, 'error' => 'A kingdom or park is required.']);
            exit;
        }
        if (!valid_id($src_evt_id)) {
            echo json_encode(['status' => 1, 'error' => 'A source event is required.']);
            exit;
        }
        $ns_ts = strtotime($new_start);
        $ne_ts = strtotime($new_end);
        if (!$ns_ts || !$ne_ts) {
            echo json_encode(['status' => 1, 'error' => 'Valid start and end times are required.']);
            exit;
        }
        if ($ne_ts < $ns_ts) {
            echo json_encode(['status' => 1, 'error' => 'End time cannot be before start time.']);
            exit;
        }

        // Auth is delegated to Event->create_event() below, matching the existing
        // EventAjax::create() pattern. HasAuthority($uid, AUTH_EVENT, 0, ...) always
        // returns false for non-global-admins (class.Authorization.php:751).

        global $DB;
        $DB->Clear();
        $srcRow = $DB->DataSet('SELECT event_id, name, kingdom_id, park_id, has_banner, banner_show_logo, banner_vignette, banner_offset_x, banner_offset_y FROM ' . DB_PREFIX . 'event WHERE event_id = ' . $src_evt_id . ' LIMIT 1');
        if (!$srcRow || !$srcRow->Next()) {
            echo json_encode(['status' => 1, 'error' => 'Source event not found.']);
            exit;
        }
        $src = $srcRow;
        if (valid_id($park_id)) {
            if ((int)$src->park_id !== $park_id) {
                echo json_encode(['status' => 3, 'error' => 'Source event is not available in this scope.']);
                exit;
            }
        } else {
            if ((int)$src->kingdom_id !== $kingdom_id || ((int)$src->park_id !== 0 && $src->park_id !== null)) {
                echo json_encode(['status' => 3, 'error' => 'Source event is not available in this scope.']);
                exit;
            }
        }

        $DB->Clear();
        $srcDetail = $DB->DataSet('SELECT * FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_id = ' . $src_evt_id . ' ORDER BY event_start DESC LIMIT 1');
        if (!$srcDetail || !$srcDetail->Next()) {
            echo json_encode(['status' => 1, 'error' => 'Selected event has no occurrence data to copy.']);
            exit;
        }
        $sd = $srcDetail;
        $src_detail_id = (int)$sd->event_calendardetail_id;
        $src_start_ts  = strtotime((string)$sd->event_start);
        if (!$src_start_ts) {
            echo json_encode(['status' => 1, 'error' => 'Source occurrence has an invalid start time.']);
            exit;
        }
        $delta_seconds = $ns_ts - $src_start_ts;

        $this->load_model('Event');
        $r = $this->Event->create_event($this->session->token, $kingdom_id, $park_id, 0, 0, $name);
        if ((int)$r['Status'] !== 0) {
            echo json_encode(['status' => (int)$r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
            exit;
        }
        $new_event_id = (int)($r['Detail'] ?? 0);
        if ($new_event_id <= 0) {
            echo json_encode(['status' => 1, 'error' => 'Failed to create event row.']);
            exit;
        }

        if ($status_in === 'draft') {
            $DB->Clear();
            $DB->Execute('UPDATE ' . DB_PREFIX . "event SET status = 'draft' WHERE event_id = " . $new_event_id);
        }

        $rollback_event = function () use ($new_event_id) {
            global $DB;
            $DB->Clear();
            $DB->Execute('DELETE FROM ' . DB_PREFIX . 'event_schedule_lead WHERE event_schedule_id IN (SELECT s.event_schedule_id FROM ' . DB_PREFIX . 'event_schedule s JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = s.event_calendardetail_id WHERE cd.event_id = ' . $new_event_id . ')');
            $DB->Clear();
            $DB->Execute('DELETE s FROM ' . DB_PREFIX . 'event_schedule s JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = s.event_calendardetail_id WHERE cd.event_id = ' . $new_event_id);
            $DB->Clear();
            $DB->Execute('DELETE st FROM ' . DB_PREFIX . 'event_staff st JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = st.event_calendardetail_id WHERE cd.event_id = ' . $new_event_id);
            $DB->Clear();
            $DB->Execute('DELETE fe FROM ' . DB_PREFIX . 'event_fees fe JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = fe.event_calendardetail_id WHERE cd.event_id = ' . $new_event_id);
            $DB->Clear();
            $DB->Execute('DELETE lk FROM ' . DB_PREFIX . 'event_links lk JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = lk.event_calendardetail_id WHERE cd.event_id = ' . $new_event_id);
            $DB->Clear();
            $DB->Execute('DELETE FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_id = ' . $new_event_id);
            $DB->Clear();
            $DB->Execute('DELETE FROM ' . DB_PREFIX . 'event WHERE event_id = ' . $new_event_id);
            $base = DIR_EVENT_BANNER . sprintf('%05d', $new_event_id);
            if (file_exists($base . '.jpg')) {
                @unlink($base . '.jpg');
            }
            if (file_exists($base . '.png')) {
                @unlink($base . '.png');
            }
        };

        $new_start_fmt = date('Y-m-d H:i:s', $ns_ts);
        $new_end_fmt   = date('Y-m-d H:i:s', $ne_ts);
        $at_park_sql   = valid_id($park_id) ? (string)$park_id : 'NULL';

        $dsc  = $mod['details'] ? (string)$sd->description : '';
        $prc  = $mod['details'] ? (float)$sd->price : 0;
        $url  = $mod['details'] ? (string)$sd->url : '';
        $urln = $mod['details'] ? (string)$sd->url_name : '';
        $adr  = $mod['details'] ? (string)$sd->address : '';
        $prv  = $mod['details'] ? (string)$sd->province : '';
        $pst  = $mod['details'] ? (string)$sd->postal_code : '';
        $cty  = $mod['details'] ? (string)$sd->city : '';
        $cnt  = $mod['details'] ? (string)$sd->country : '';
        $mur  = $mod['details'] ? (string)$sd->map_url : '';
        $murn = $mod['details'] ? (string)$sd->map_url_name : '';
        $etp  = $mod['details'] ? (string)$sd->event_type : '';

        foreach (['url' => &$url, 'mur' => &$mur] as $_k => &$_v) {
            if ($_v !== '') {
                $_sc = strtolower((string)parse_url($_v, PHP_URL_SCHEME));
                if (!in_array($_sc, ['http', 'https', 'mailto'], true)) {
                    $_v = '';
                }
            }
        }
        unset($_v);

        $sq = function ($s) {
            return str_replace(["'", '\\'], ["''", '\\\\'], (string)$s);
        };
        $DB->Clear();
        $DB->Execute("UPDATE " . DB_PREFIX . "event_calendardetail SET current = 0 WHERE event_id = " . $new_event_id);
        $DB->Clear();
        $DB->Execute('INSERT INTO ' . DB_PREFIX . "event_calendardetail
			(event_id, at_park_id, current, price, event_start, event_end, description, url, url_name, address, province, postal_code, city, country, map_url, map_url_name, event_type)
			VALUES (" . $new_event_id . ', ' . $at_park_sql . ", 1, " . (float)$prc . ", '" . $new_start_fmt . "', '" . $new_end_fmt . "', '" . $sq($dsc) . "', '" . $sq($url) . "', '" . $sq($urln) . "', '" . $sq($adr) . "', '" . $sq($prv) . "', '" . $sq($pst) . "', '" . $sq($cty) . "', '" . $sq($cnt) . "', '" . $sq($mur) . "', '" . $sq($murn) . "', '" . $sq($etp) . "')");
        $DB->Clear();
        $ndRow = $DB->DataSet('SELECT event_calendardetail_id FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_id = ' . $new_event_id . ' ORDER BY event_calendardetail_id DESC LIMIT 1');
        $new_detail_id = ($ndRow && $ndRow->Next()) ? (int)$ndRow->event_calendardetail_id : 0;
        if ($new_detail_id <= 0) {
            $rollback_event();
            echo json_encode(['status' => 1, 'error' => 'Failed to create event occurrence.']);
            exit;
        }

        if ($mod['details']) {
            // Fees
            $DB->Clear();
            $feesRs = $DB->DataSet('SELECT admission_type, cost, sort_order FROM ' . DB_PREFIX . 'event_fees WHERE event_calendardetail_id = ' . $src_detail_id . ' ORDER BY sort_order ASC');
            if ($feesRs) {
                while ($feesRs->Next()) {
                    $_at = $sq((string)$feesRs->admission_type);
                    $_co = round((float)$feesRs->cost, 2);
                    $_so = (int)$feesRs->sort_order;
                    $DB->Clear();
                    $DB->Execute('INSERT INTO ' . DB_PREFIX . "event_fees (event_calendardetail_id, admission_type, cost, sort_order) VALUES (" . $new_detail_id . ", '" . $_at . "', " . $_co . ", " . $_so . ")");
                }
            }

            // Links — re-validate URL scheme and icon allow-list on insert.
            $allowed_icons = ['fab fa-facebook','fab fa-discord','fas fa-globe','far fa-clipboard','fas fa-link','fas fa-ticket-alt'];
            $DB->Clear();
            $linksRs = $DB->DataSet('SELECT title, url, icon, sort_order FROM ' . DB_PREFIX . 'event_links WHERE event_calendardetail_id = ' . $src_detail_id . ' ORDER BY sort_order ASC');
            if ($linksRs) {
                while ($linksRs->Next()) {
                    $_lt = $sq((string)$linksRs->title);
                    $_lu_raw = trim((string)$linksRs->url);
                    if ($_lu_raw !== '') {
                        $_sc = strtolower((string)parse_url($_lu_raw, PHP_URL_SCHEME));
                        if (!in_array($_sc, ['http', 'https', 'mailto'], true)) {
                            $_lu_raw = '';
                        }
                    }
                    $_lu = $sq($_lu_raw);
                    $_ic_raw = trim((string)$linksRs->icon);
                    if (!in_array($_ic_raw, $allowed_icons, true)) {
                        $_ic_raw = 'fas fa-link';
                    }
                    $_ic = $sq($_ic_raw);
                    $_so = (int)$linksRs->sort_order;
                    $DB->Clear();
                    $DB->Execute('INSERT INTO ' . DB_PREFIX . "event_links (event_calendardetail_id, title, url, icon, sort_order) VALUES (" . $new_detail_id . ", '" . $_lt . "', '" . $_lu . "', '" . $_ic . "', " . $_so . ")");
                }
            }
        }

        if ($mod['schedule'] || $mod['feast']) {
            $DB->Clear();
            $schedRs = $DB->DataSet('SELECT * FROM ' . DB_PREFIX . 'event_schedule WHERE event_calendardetail_id = ' . $src_detail_id . ' ORDER BY start_time ASC');
            $src_sched_ids = [];
            if ($schedRs) {
                while ($schedRs->Next()) {
                    $cat    = (string)$schedRs->category;
                    $secCat = (string)$schedRs->secondary_category;
                    $is_feast = ($cat === 'Feast and Food' || $secCat === 'Feast and Food');
                    $want = $is_feast ? $mod['feast'] : $mod['schedule'];
                    if (!$want) {
                        continue;
                    }

                    $_title    = $sq((string)$schedRs->title);
                    $_loc      = $sq((string)$schedRs->location);
                    $_desc     = $sq((string)$schedRs->description);
                    $_cat      = $sq($cat);
                    $_secCat   = $sq($secCat);
                    $_st       = strtotime((string)$schedRs->start_time);
                    $_et       = strtotime((string)$schedRs->end_time);
                    if (!$_st || !$_et) {
                        continue;
                    }
                    $_st_new = date('Y-m-d H:i:s', $_st + $delta_seconds);
                    $_et_new = date('Y-m-d H:i:s', $_et + $delta_seconds);

                    $_menuV  = $schedRs->menu;
                    $_costV  = $schedRs->cost;
                    $_dietV  = $schedRs->dietary;
                    $_alleV  = $schedRs->allergens;
                    $_menu_sql = ($_menuV !== null) ? "'" . $sq($_menuV) . "'" : 'NULL';
                    $_cost_sql = ($_costV !== null && is_numeric($_costV)) ? (string)round((float)$_costV, 2) : 'NULL';
                    $_diet_sql = ($_dietV !== null) ? "'" . $sq($_dietV) . "'" : 'NULL';
                    $_alle_sql = ($_alleV !== null) ? "'" . $sq($_alleV) . "'" : 'NULL';

                    $_src_sched_id = (int)$schedRs->event_schedule_id;
                    $DB->Clear();
                    $DB->Execute('INSERT INTO ' . DB_PREFIX . "event_schedule
						(event_calendardetail_id, title, start_time, end_time, location, description, category, secondary_category, menu, cost, dietary, allergens)
						VALUES (" . $new_detail_id . ", '" . $_title . "', '" . $_st_new . "', '" . $_et_new . "', '" . $_loc . "', '" . $_desc . "', '" . $_cat . "', '" . $_secCat . "', " . $_menu_sql . ", " . $_cost_sql . ", " . $_diet_sql . ", " . $_alle_sql . ")");
                    $DB->Clear();
                    $nsRow = $DB->DataSet('SELECT event_schedule_id FROM ' . DB_PREFIX . 'event_schedule WHERE event_calendardetail_id = ' . $new_detail_id . ' ORDER BY event_schedule_id DESC LIMIT 1');
                    $_new_sched_id = ($nsRow && $nsRow->Next()) ? (int)$nsRow->event_schedule_id : 0;
                    if ($_new_sched_id > 0) {
                        $src_sched_ids[$_src_sched_id] = $_new_sched_id;
                    }
                }
            }

            // Copy leads — batch-fetch all source leads in one query, then INSERT per row.
            if (!empty($src_sched_ids)) {
                $_srcKeys = implode(',', array_map('intval', array_keys($src_sched_ids)));
                $DB->Clear();
                $leadsRs = $DB->DataSet('SELECT event_schedule_id, mundane_id FROM ' . DB_PREFIX . 'event_schedule_lead WHERE event_schedule_id IN (' . $_srcKeys . ')');
                if ($leadsRs) {
                    while ($leadsRs->Next()) {
                        $_src_sid = (int)$leadsRs->event_schedule_id;
                        $_new_sid = $src_sched_ids[$_src_sid] ?? 0;
                        if ($_new_sid <= 0) {
                            continue;
                        }
                        $_mid = (int)$leadsRs->mundane_id;
                        if (!$this->_isMundaneEligible($_mid)) {
                            continue;
                        }
                        $DB->Clear();
                        $DB->Execute('INSERT IGNORE INTO ' . DB_PREFIX . 'event_schedule_lead (event_schedule_id, mundane_id) VALUES (' . $_new_sid . ', ' . $_mid . ')');
                    }
                }
            }
        }

        if ($mod['staff']) {
            $DB->Clear();
            $staffRs = $DB->DataSet('SELECT mundane_id, role_name, can_manage, can_attendance, can_schedule, can_feast FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . $src_detail_id);
            if ($staffRs) {
                while ($staffRs->Next()) {
                    $_mid = (int)$staffRs->mundane_id;
                    if (!$this->_isMundaneEligible($_mid)) {
                        continue;
                    }
                    $_role = $sq((string)$staffRs->role_name);
                    $_cm   = (int)$staffRs->can_manage ? 1 : 0;
                    $_ca   = (int)$staffRs->can_attendance ? 1 : 0;
                    $_cs   = (int)$staffRs->can_schedule ? 1 : 0;
                    $_cf   = (int)$staffRs->can_feast ? 1 : 0;
                    $DB->Clear();
                    $DB->Execute('INSERT INTO ' . DB_PREFIX . "event_staff
						(event_calendardetail_id, mundane_id, role_name, can_manage, can_attendance, can_schedule, can_feast)
						VALUES (" . $new_detail_id . ", " . $_mid . ", '" . $_role . "', " . $_cm . ", " . $_ca . ", " . $_cs . ", " . $_cf . ")
						ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), can_manage = VALUES(can_manage), can_attendance = VALUES(can_attendance), can_schedule = VALUES(can_schedule), can_feast = VALUES(can_feast)");
                }
            }
        }

        $warnings = [];

        if ($mod['banner'] && (int)$src->has_banner === 1) {
            $src_base = DIR_EVENT_BANNER . sprintf('%05d', $src_evt_id);
            $new_base = DIR_EVENT_BANNER . sprintf('%05d', $new_event_id);
            $copied = false;
            $ext = null;
            if (file_exists($src_base . '.jpg')) {
                $ext = 'jpg';
            } elseif (file_exists($src_base . '.png')) {
                $ext = 'png';
            }
            if ($ext) {
                if (!is_dir(DIR_EVENT_BANNER)) {
                    @mkdir(DIR_EVENT_BANNER, 0775, true);
                }
                if (@copy($src_base . '.' . $ext, $new_base . '.' . $ext)) {
                    $_sl = (int)$src->banner_show_logo ? 1 : 0;
                    $_vg = (int)$src->banner_vignette ? 1 : 0;
                    $_ox = max(0, min(100, (int)$src->banner_offset_x));
                    $_oy = max(0, min(100, (int)$src->banner_offset_y));
                    $DB->Clear();
                    $DB->Execute('UPDATE ' . DB_PREFIX . 'event SET has_banner = 1, banner_show_logo = ' . $_sl . ', banner_vignette = ' . $_vg . ', banner_offset_x = ' . $_ox . ', banner_offset_y = ' . $_oy . ' WHERE event_id = ' . $new_event_id);
                    $copied = true;
                }
            }
            if (!$copied) {
                $warnings[] = 'Banner could not be copied.';
            }
        }

        $this->_bustEventSearchCache($new_event_id);
        echo json_encode([
            'status'   => 0,
            'eventId'  => $new_event_id,
            'detailId' => $new_detail_id,
            'url'      => UIR . 'Event/detail/' . $new_event_id . '/' . $new_detail_id,
            'warnings' => $warnings,
        ]);
        exit;
    }

    // Per-request cache for mundane eligibility — copy passes reference the same
    // mundane through many schedule leads + staff rows.
    private $_mundaneEligibleCache = [];

    private function _isMundaneEligible($mundane_id)
    {
        $mid = (int)$mundane_id;
        if ($mid <= 0) {
            return false;
        }
        if (array_key_exists($mid, $this->_mundaneEligibleCache)) {
            return $this->_mundaneEligibleCache[$mid];
        }
        global $DB;
        $DB->Clear();
        $row = $DB->DataSet('SELECT active, suspended, suspended_until FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . $mid . ' LIMIT 1');
        $ok = false;
        if ($row && $row->Next()) {
            if ((int)$row->active === 1) {
                if ((int)$row->suspended !== 1) {
                    $ok = true;
                } else {
                    $until = $row->suspended_until;
                    if ($until && strtotime($until) !== false && strtotime($until) < strtotime(date('Y-m-d'))) {
                        $ok = true;
                    }
                }
            }
        }
        $this->_mundaneEligibleCache[$mid] = $ok;
        return $ok;
    }

    /**
     * Bust the SearchService::Event GhettoCache entries that could embed
     * event-level fields (HasHeraldry, HasBanner, BannerShowLogo, BannerVignette).
     * The cache key is positional on Event()'s arg list; mirror it here for
     * the canonical `get_event_info($event_id)` call signature so the next
     * page load reads fresh data.
     */
    private function _bustEventSearchCache($event_id)
    {
        // get_event_info() now calls Search->Event with the full 12-arg signature,
        // so the cache is keyed on all 12 args (draft-inclusive + draft-exclusive).
        // Delegate to the shared buster that mirrors those keys exactly.
        Ork3::$Lib->ghettocache->bust_event_search($event_id);
    }


    public function banner($p = null)
    {
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

        $bUid = (int)$this->session->user_id;
        $bCanManage = Ork3::$Lib->authorization->HasAuthority($bUid, AUTH_EVENT, $event_id, AUTH_EDIT);
        if (!$bCanManage) {
            global $DB;
            $DB->Clear();
            $staffRow = $DB->DataSet('SELECT 1 FROM ' . DB_PREFIX . 'event_staff s JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = s.event_calendardetail_id WHERE cd.event_id = ' . $event_id . ' AND s.mundane_id = ' . $bUid . ' AND s.can_manage = 1 LIMIT 1');
            $bCanManage = $staffRow && $staffRow->Next();
        }
        if (!$bCanManage) {
            echo json_encode(['status' => 3, 'error' => 'Not authorized.']);
            exit;
        }

        global $DB;

        if ($action === 'remove') {
            $DB->Clear();
            // Reset display toggles AND framing offsets to defaults so a future
            // upload starts fresh instead of inheriting the removed banner's
            // config.
            $DB->Execute('UPDATE ' . DB_PREFIX . 'event SET has_banner = 0, banner_show_logo = 1, banner_vignette = 1, banner_offset_x = 50, banner_offset_y = 50 WHERE event_id = ' . $event_id);
            $base = DIR_EVENT_BANNER . sprintf('%05d', $event_id);
            if (file_exists($base . '.jpg')) {
                unlink($base . '.jpg');
            }
            if (file_exists($base . '.png')) {
                unlink($base . '.png');
            }
            $this->_bustEventSearchCache($event_id);
            echo json_encode(['status' => 0]);
            exit;
        }

        if ($action === 'config') {
            // Refuse silent no-ops: config only meaningful with a banner present.
            $DB->Clear();
            $row = $DB->DataSet('SELECT has_banner FROM ' . DB_PREFIX . 'event WHERE event_id = ' . $event_id);
            if (!$row || !$row->Next() || (int)$row->has_banner !== 1) {
                echo json_encode(['status' => 1, 'error' => 'Upload a banner first before saving settings.']);
                exit;
            }
            $showLogo = !empty($_POST['ShowLogo']) ? 1 : 0;
            $vignette = !empty($_POST['Vignette']) ? 1 : 0;
            $offX = max(0, min(100, (int)($_POST['OffsetX'] ?? 50)));
            $offY = max(0, min(100, (int)($_POST['OffsetY'] ?? 50)));
            $DB->Clear();
            $DB->Execute('UPDATE ' . DB_PREFIX . 'event SET banner_show_logo = ' . $showLogo . ', banner_vignette = ' . $vignette . ', banner_offset_x = ' . $offX . ', banner_offset_y = ' . $offY . ' WHERE event_id = ' . $event_id);
            $this->_bustEventSearchCache($event_id);
            echo json_encode(['status' => 0]);
            exit;
        }

        if ($action === 'update') {
            if (empty($_FILES['Banner']['tmp_name'])) {
                echo json_encode(['status' => 1, 'error' => 'No file uploaded.']);
                exit;
            }
            // A1/I2 fix: validate the upload came via a real HTTP file upload (prevents spoofing).
            if (!is_uploaded_file($_FILES['Banner']['tmp_name'])) {
                echo json_encode(['status' => 1, 'error' => 'Invalid upload.']);
                exit;
            }
            // A1/I5 fix: server-side file size check (JS resize can be bypassed via curl).
            if (($_FILES['Banner']['size'] ?? 0) > 1024 * 1024) {
                echo json_encode(['status' => 1, 'error' => 'File too large (max 1 MB).']);
                exit;
            }
            $tmp  = $_FILES['Banner']['tmp_name'];
            // A1/I3 fix: use exif_imagetype() (magic-byte check) instead of the
            // browser-supplied MIME type, which is trivially spoofable. JPEG and
            // PNG only: resolve_image_ext returns .jpg or .png and the rest of the
            // pipeline (storage filename, frontend cache-bust, banner modal accept
            // attribute) only knows those two. GIFs would land as .jpg on disk
            // with corrupt bytes.
            $detectedType = exif_imagetype($tmp);
            if ($detectedType !== IMAGETYPE_JPEG && $detectedType !== IMAGETYPE_PNG) {
                echo json_encode(['status' => 1, 'error' => 'Only JPEG and PNG images are supported.']);
                exit;
            }
            $mime = ($detectedType === IMAGETYPE_PNG) ? 'image/png' : 'image/jpeg';
            if (!is_dir(DIR_EVENT_BANNER)) {
                @mkdir(DIR_EVENT_BANNER, 0775, true);
            }
            $ext  = ($mime === 'image/png') ? 'png' : 'jpg';
            $base = DIR_EVENT_BANNER . sprintf('%05d', $event_id);
            // Delete any previous banner files (both extensions) before saving
            // the new one so we never leave the old image behind when the host
            // switches images. resolve_image_ext picks whichever survives.
            if (file_exists($base . '.jpg')) {
                @unlink($base . '.jpg');
            }
            if (file_exists($base . '.png')) {
                @unlink($base . '.png');
            }
            if (!@move_uploaded_file($tmp, $base . '.' . $ext)) {
                echo json_encode(['status' => 1, 'error' => 'Could not save uploaded file.']);
                exit;
            }
            $showLogo = !empty($_POST['ShowLogo']) ? 1 : 0;
            $vignette = !empty($_POST['Vignette']) ? 1 : 0;
            $offX = max(0, min(100, (int)($_POST['OffsetX'] ?? 50)));
            $offY = max(0, min(100, (int)($_POST['OffsetY'] ?? 50)));
            $DB->Clear();
            $DB->Execute('UPDATE ' . DB_PREFIX . 'event SET has_banner = 1, banner_show_logo = ' . $showLogo . ', banner_vignette = ' . $vignette . ', banner_offset_x = ' . $offX . ', banner_offset_y = ' . $offY . ' WHERE event_id = ' . $event_id);
            // $DB->Execute() is void; the YapoMysql layer can silently swallow
            // failures (sql_mode=STRICT etc). Verify the update landed by
            // re-reading has_banner. If it didn't, roll back the file so we
            // don't leave an orphan whose flag is still 0.
            $DB->Clear();
            $verify = $DB->DataSet('SELECT has_banner FROM ' . DB_PREFIX . 'event WHERE event_id = ' . $event_id);
            if (!$verify || !$verify->Next() || (int)$verify->has_banner !== 1) {
                @unlink($base . '.' . $ext);
                echo json_encode(['status' => 1, 'error' => 'Saved file but could not update the database. Please try again.']);
                exit;
            }
            $this->_bustEventSearchCache($event_id);
            echo json_encode(['status' => 0]);
            exit;
        }

        echo json_encode(['status' => 1, 'error' => 'Unknown action.']);
        exit;
    }
}
