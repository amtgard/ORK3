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

        $this->load_model('EventPlanning');
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

        $r = $this->EventPlanning->create_event(
            $this->session->token,
            $kingdom_id,
            $park_id,
            0,
            0,
            $name,
            (string)($_POST['Status'] ?? 'published')
        );

        if ($r['Status'] == 0) {
            $newId = (int)($r['Detail'] ?? 0);
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
        $this->load_model('EventPlanning');
        $r = $this->EventPlanning->set_status($this->session->token, $evtId, $status);
        if (($r['Status'] ?? 1) == 0) {
            echo json_encode(['status' => 0, 'event_status' => $status]);
        } else {
            $this->EventPlanning->emit_json($r);
        }
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
        $uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
        $this->load_model('EventPlanning');
        $r = $this->EventPlanning->get_preview($eventId, $detailId, $uid);
        $statusCode = is_array($r['Status'] ?? null) ? (int)($r['Status']['Status'] ?? 1) : (int)($r['Status'] ?? 1);
        if ($statusCode !== 0 || !isset($r['Preview'])) {
            if ($statusCode === ServiceErrorIds::NoAuthorization) {
                echo json_encode(['status' => 5, 'error' => 'Not authorized']);
            } elseif ($statusCode === ServiceErrorIds::InvalidParameter) {
                $detail = is_array($r['Status'] ?? null) ? (string)($r['Status']['Detail'] ?? 'Invalid request') : 'Invalid request';
                echo json_encode(['status' => 1, 'error' => $detail]);
            } else {
                echo json_encode(['status' => 1, 'error' => 'Event not found']);
            }
            exit;
        }

        $preview = $r['Preview'];
        $resolvedDetailId = (int)($preview['event_calendardetail_id'] ?? 0);
        echo json_encode([
            'status'           => 0,
            'event_id'         => $eventId,
            'event_calendardetail_id' => $resolvedDetailId,
            'name'             => $preview['name'],
            'park_id'          => (int)$preview['park_id'],
            'park_name'        => $preview['park_name'],
            'is_park_event'    => (bool)$preview['is_park_event'],
            'is_draft'         => (bool)$preview['is_draft'],
            'has_heraldry'     => (bool)$preview['has_heraldry'],
            'date_label'       => $preview['date_label'],
            'time_label'       => $preview['time_label'],
            'price'            => (float)$preview['price'],
            'description_excerpt' => $preview['description_excerpt'],
            'going_count'      => (int)$preview['going_count'],
            'interested_count' => (int)$preview['interested_count'],
            'my_rsvp'          => $preview['my_rsvp'],
            'detail_url'       => $resolvedDetailId > 0 ? (UIR . 'Event/detail/' . $eventId . '/' . $resolvedDetailId) : (UIR . 'Event/index/' . $eventId),
            'can_edit'         => (bool)$preview['can_edit'],
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
        $this->load_model('EventPlanning');

        $params    = explode('/', $p ?? '');
        $event_id  = (int)preg_replace('/[^0-9]/', '', $params[0] ?? '');
        $detail_id = (int)preg_replace('/[^0-9]/', '', $params[1] ?? '');

        if (!valid_id($event_id) || !valid_id($detail_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid Event ID.']);
            exit;
        }

        $uid = (int)$this->session->user_id;
        if (!$this->EventPlanning->can_add_attendance($uid, $event_id, $detail_id)) {
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
            $aid = (int)$r['Detail'];
            $row = $this->EventPlanning->get_attendance_display_row($aid);
            if ($row) {
                echo json_encode(['status' => 0, 'attendance' => $row + [
                    'ClassId' => (int)($_POST['ClassId'] ?? 0),
                    'Date'    => $_POST['AttendanceDate'] ?? date('Y-m-d'),
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
        $this->load_model('EventPlanning');
        if (!(new EventPlanning())->CanRemoveRsvp($uid, $event_id, $detail_id)) {
            echo json_encode(['status' => 3, 'error' => 'Not authorized.']);
            exit;
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
        if (!$this->Authorization->has_authority($uid, AUTH_EVENT, $event_id, AUTH_CREATE)) {
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
            if (!$this->Authorization->has_authority($uid, AUTH_EVENT, $event_id, AUTH_CREATE)) {
                echo json_encode([]);
                exit;
            }
            $q = trim($_GET['q'] ?? '');
            if (strlen($q) < 2) {
                echo json_encode([]);
                exit;
            }
            $this->load_model('Search');
            $results = $this->Search->scoped_player_search([
                'Query'   => $q,
                'Scope'   => 'event_prioritized',
                'EventId' => $event_id,
                'Limit'   => 15,
                'Format'  => 'event',
            ]);
            echo json_encode($results);

        } elseif ($action === 'addauth') {
            if (!$this->Authorization->has_authority($uid, AUTH_EVENT, $event_id, AUTH_CREATE)) {
                echo json_encode(['status' => 5, 'error' => 'Not authorized.']);
                exit;
            }
            $mid  = (int)($_POST['MundaneId'] ?? 0);
            $role = in_array($_POST['Role'] ?? '', ['create', 'edit']) ? $_POST['Role'] : 'create';
            if (!$mid) {
                echo json_encode(['status' => 1, 'error' => 'Invalid player.']);
                exit;
            }
            $this->load_model('Authorization');
            $r = $this->Authorization->add_auth([
                'Token'     => $this->session->token,
                'MundaneId' => $mid,
                'Type'      => AUTH_EVENT,
                'Id'        => $event_id,
                'Role'      => $role,
            ]);
            if ($r['Status'] != 0) {
                echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . (isset($r['Detail']) && $r['Detail'] !== '' ? ': ' . $r['Detail'] : '')]);
                exit;
            }
            $authId = (int)($r['Detail'] ?? 0);
            $this->load_model('Player');
            $persona = $this->Player->get_persona($mid);
            (new Dangeraudit())->audit('Authorization::AddAuthorization', ['MundaneId' => $mid, 'Type' => AUTH_EVENT, 'Id' => $event_id, 'Role' => $role], 'Player', $mid, null, [
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
            if (!$this->Authorization->has_authority($uid, AUTH_EVENT, $event_id, AUTH_CREATE)) {
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

        $mundane_id    = (int)($_POST['MundaneId']    ?? 0);
        $role_name     = trim($_POST['RoleName']      ?? '');
        $can_manage    = (int)(bool)($_POST['CanManage']    ?? 0);
        $can_attendance = (int)(bool)($_POST['CanAttendance'] ?? 0);
        $can_schedule   = (int)(bool)($_POST['CanSchedule']   ?? 0);
        $can_feast      = (int)(bool)($_POST['CanFeast']      ?? 0);
        $staff_id_in  = (int)($_POST['StaffId'] ?? 0);

        if (!valid_id($mundane_id)) {
            echo json_encode(['status' => 1, 'error' => 'A player must be selected.']);
            exit;
        }
        if (!$role_name) {
            echo json_encode(['status' => 1, 'error' => 'A role is required.']);
            exit;
        }

        $this->load_model('EventPlanning');
        $r = $this->EventPlanning->add_staff([
            'Token' => $this->session->token,
            'EventId' => $event_id,
            'EventCalendarDetailId' => $detail_id,
            'StaffId' => $staff_id_in,
            'MundaneId' => $mundane_id,
            'RoleName' => $role_name,
            'CanManage' => $can_manage,
            'CanAttendance' => $can_attendance,
            'CanSchedule' => $can_schedule,
            'CanFeast' => $can_feast,
        ]);

        $statusCode = is_array($r['Status'] ?? null) ? (int)($r['Status']['Status'] ?? 1) : (int)($r['Status'] ?? 1);
        if ($statusCode !== 0) {
            $this->EventPlanning->emit_json($r);
        }

        $priorState = $r['PriorState'] ?? null;
        $staff = $r['Staff'] ?? [];
        (new Dangeraudit())->audit(
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
                'event_staff_id' => (int)($staff['EventStaffId'] ?? 0),
                'role_name'      => $role_name,
                'can_manage'     => $can_manage,
                'can_attendance' => $can_attendance,
                'can_schedule'   => $can_schedule,
                'can_feast'      => $can_feast,
            ]
        );
        echo json_encode(['status' => 0, 'staff' => $staff]);
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

        $this->load_model('EventPlanning');
        $r = $this->EventPlanning->remove_staff([
            'Token' => $this->session->token,
            'EventId' => $event_id,
            'EventCalendarDetailId' => $detail_id,
            'StaffId' => $staff_id,
        ]);

        $statusCode = is_array($r['Status'] ?? null) ? (int)($r['Status']['Status'] ?? 1) : (int)($r['Status'] ?? 1);
        if ($statusCode !== 0) {
            $this->EventPlanning->emit_json($r);
        }

        $priorState = $r['PriorState'] ?? null;
        if ($priorState) {
            (new Dangeraudit())->audit(
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

    private function _decodeScheduleLeads(): array
    {
        $leadsJson = trim($_POST['Leads'] ?? '');
        $leadsIn = ($leadsJson !== '') ? json_decode($leadsJson, true) : [];

        return is_array($leadsIn) ? $leadsIn : [];
    }

    private function _scheduleRequestFromPost(int $eventId, int $detailId, int $scheduleId = 0): array
    {
        return [
            'Token' => $this->session->token,
            'EventId' => $eventId,
            'EventCalendarDetailId' => $detailId,
            'ScheduleId' => $scheduleId,
            'Title' => trim($_POST['Title'] ?? ''),
            'StartTime' => trim($_POST['StartTime'] ?? ''),
            'EndTime' => trim($_POST['EndTime'] ?? ''),
            'Location' => trim($_POST['Location'] ?? ''),
            'Description' => trim($_POST['Description'] ?? ''),
            'Category' => trim($_POST['Category'] ?? 'Other'),
            'SecondaryCategory' => trim($_POST['SecondaryCategory'] ?? ''),
            'Menu' => trim($_POST['Menu'] ?? ''),
            'Cost' => trim($_POST['Cost'] ?? ''),
            'Dietary' => trim($_POST['Dietary'] ?? ''),
            'Allergens' => trim($_POST['Allergens'] ?? ''),
            'Leads' => $this->_decodeScheduleLeads(),
        ];
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

        $this->load_model('EventPlanning');
        $r = $this->EventPlanning->add_schedule($this->_scheduleRequestFromPost($event_id, $detail_id));
        $statusCode = is_array($r['Status'] ?? null) ? (int)($r['Status']['Status'] ?? 1) : (int)($r['Status'] ?? 1);
        if ($statusCode !== 0) {
            $this->EventPlanning->emit_json($r);
        }
        echo json_encode(['status' => 0, 'schedule' => $r['Schedule'] ?? []]);
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

        $this->load_model('EventPlanning');
        $r = $this->EventPlanning->remove_schedule($this->session->token, $event_id, $detail_id, $schedule_id);
        $statusCode = is_array($r['Status'] ?? null) ? (int)($r['Status']['Status'] ?? 1) : (int)($r['Status'] ?? 1);
        if ($statusCode !== 0) {
            $this->EventPlanning->emit_json($r);
        }
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

        $this->load_model('EventPlanning');
        $r = $this->EventPlanning->update_schedule($this->_scheduleRequestFromPost($event_id, $detail_id, $schedule_id));
        $statusCode = is_array($r['Status'] ?? null) ? (int)($r['Status']['Status'] ?? 1) : (int)($r['Status'] ?? 1);
        if ($statusCode !== 0) {
            $this->EventPlanning->emit_json($r);
        }
        echo json_encode(['status' => 0, 'schedule' => $r['Schedule'] ?? []]);
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
        $planning = new EventPlanning();
        if (!$planning->CanManageEventDetail($hUid, $event_id, 0, 'manage')) {
            echo json_encode(['status' => 3, 'error' => 'Not authorized.']);
            exit;
        }

        if ($action === 'remove') {
            $this->load_model('EventPlanning');
            $r = $this->EventPlanning->remove_heraldry($this->session->token, $event_id);
            if (($r['Status'] ?? 1) == 0) {
                echo json_encode(['status' => 0]);
            } else {
                $this->EventPlanning->emit_json($r);
            }
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
            $r = (new Heraldry())->SetEventHeraldry([
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

        $this->load_model('EventPlanning');
        $r = $this->EventPlanning->copy_source_list($kingdom_id, $park_id, $query, $exclude);
        $statusCode = is_array($r['Status'] ?? null) ? (int)($r['Status']['Status'] ?? 1) : (int)($r['Status'] ?? 1);
        if ($statusCode !== 0) {
            $this->EventPlanning->emit_json($r);
        }
        echo json_encode(['status' => 0, 'results' => $r['Results'] ?? []]);
        exit;
    }


    public function create_with_copy($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }

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
        if (!strtotime($new_start) || !strtotime($new_end)) {
            echo json_encode(['status' => 1, 'error' => 'Valid start and end times are required.']);
            exit;
        }
        if (strtotime($new_end) < strtotime($new_start)) {
            echo json_encode(['status' => 1, 'error' => 'End time cannot be before start time.']);
            exit;
        }

        $this->load_model('EventPlanning');
        $r = $this->EventPlanning->create_with_copy([
            'Token' => $this->session->token,
            'Name' => $name,
            'KingdomId' => $kingdom_id,
            'ParkId' => $park_id,
            'SourceEventId' => $src_evt_id,
            'NewStart' => $new_start,
            'NewEnd' => $new_end,
            'Modules' => $modules,
            'Status' => $status_in,
        ]);

        $statusCode = is_array($r['Status'] ?? null) ? (int)($r['Status']['Status'] ?? 1) : (int)($r['Status'] ?? 1);
        if ($statusCode !== 0) {
            $detail = is_array($r['Status'] ?? null) ? (string)($r['Status']['Detail'] ?? '') : (string)($r['Detail'] ?? '');
            $error = $detail !== '' ? $detail : (string)($r['Error'] ?? 'Error');
            $jsonStatus = ($statusCode === ServiceErrorIds::NoAuthorization) ? 3 : (int)$statusCode;
            echo json_encode(['status' => $jsonStatus, 'error' => $error]);
            exit;
        }

        $newEventId = (int)($r['EventId'] ?? 0);
        $newDetailId = (int)($r['DetailId'] ?? 0);
        echo json_encode([
            'status'   => 0,
            'eventId'  => $newEventId,
            'detailId' => $newDetailId,
            'url'      => UIR . 'Event/detail/' . $newEventId . '/' . $newDetailId,
            'warnings' => $r['Warnings'] ?? [],
        ]);
        exit;
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

        $this->load_model('Banner');
        $this->Banner->handle_ajax(
            'Event',
            $action,
            $event_id,
            $this->session->token,
            $_POST,
            $_FILES,
            3,
        );
    }
}
