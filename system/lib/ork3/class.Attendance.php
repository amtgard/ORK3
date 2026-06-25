<?php

class Attendance extends Ork3
{
    public function __construct()
    {
        parent::__construct();
        $this->attendance = new yapo($this->db, DB_PREFIX . 'attendance');
        $this->class = new yapo($this->db, DB_PREFIX . 'class');
        $this->attendance_link = new yapo($this->db, DB_PREFIX . 'attendance_link');
    }

    public function GetClasses($request)
    {
        $this->class->clear();
        if (is_numeric($request['Active'])) {
            $this->class->active = $request['Active'];
        }
        $response = array( 'Status' => Success(), 'Classes' => array() );
        if ($this->class->find()) {
            do {
                $response['Classes'][] = array(
                        'ClassId' => $this->class->class_id,
                        'Name' => $this->class->name,
                        'Active' => $this->class->active
                    );
            } while ($this->class->next());
        }
        return $response;
    }

    public function CreateClass($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_CREATE)) {
            $this->class->clear();
            $this->class->name = $request['Name'];
            $this->class->active = $request['Active'];
            $this->class->save();
            return Success($this->class->class_id);
        } else {
            return NoAuthorization();
        }
    }

    public function SetClass($request)
    {
        $response = array();
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT)) {
            $this->class->clear();
            $this->class->class_id = $request['ClassId'];
            if (valid_id($request['ClassId']) && $this->class->find()) {
                $this->class->name = $request['Name'];
                $this->class->active = $request['Active'];
                $this->class->save();
                return Success();
            } else {
                return InvalidParameter();
            }
        } else {
            return NoAuthorization();
        }
    }

    public function AddAttendance($request)
    {
        logtrace("Attendance->AddAttendance()", $request);

        if (($type = $this->AttendanceAuthority($request)) === false) {
            logtrace("Attendance->AddAttendance: No Authority", $request);
            return NoAuthorization('Type is not specified.');
        }

        if (valid_id($request['MundaneId']) && valid_id($request['ClassId']) and strtotime($request['Date']) && valid_id($request['Credits']))
        ;
        else {
            logtrace("Attendance->AddAttendance: Invalid Request", $request);
            return InvalidParameter();
        }

        $this->attendance->clear();
        $this->attendance->park_id = 0;
        $this->attendance->kingdom_id = 0;
        $this->attendance->mundane_id = $request['MundaneId'];
        $this->attendance->persona = $request['Persona'] ?? '';
        $this->attendance->class_id = $request['ClassId'];
        $this->attendance->date = $request['Date'];
        $this->attendance->credits = $request['Credits'];
        $this->attendance->note = $request['Note'] ?? '';
        $this->attendance->flavor = $request['Flavor'] ?? '';
        $this->attendance->by_whom_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        $this->attendance->entered_at = date("Y-m-d H:i:s");
        // Officer-keyed entry from Enter Attendance modal (Search / Recent tabs).
        // Self-signin and self-reg paths set this column to their own value below.
        $this->attendance->entry_method = 'manual';
        $this->attendance->event_id = 0;
        $this->attendance->event_calendardetail_id = 0;
        $this->attendance->date_year = 0;
        $this->attendance->date_month = 0;
        $this->attendance->date_week3 = 0;
        $this->attendance->date_week6 = 0;

        logtrace("AddAttendance: type before switch", array("type" => $type, "type_strict_false" => ($type === false), "type_null" => is_null($type)));
        switch ($type) {
            case AUTH_PARK:
                $park = Ork3::$Lib->park->GetParkShortInfo(array('ParkId' => $request['ParkId']));
                $this->attendance->kingdom_id = $park['ParkInfo']['KingdomId'];
                $this->attendance->park_id = $request['ParkId'];
                break;
            case AUTH_KINGDOM:
                $this->attendance->kingdom_id = $request['KingdomId'];
                break;
            case AUTH_EVENT:
                $detail = Ork3::$Lib->event->GetEventDetail(array('EventCalendarDetailId' => $request['EventCalendarDetailId']));
                $event = Ork3::$Lib->event->GetEvent(array('EventId' => $detail['CalendarEventDetails'][0]['EventId']));
                $kingdom_id = $event['KingdomId'];
                if ($detail['Status']['Status'] != 0) {
                    logtrace("AddAttendance: Could not fetch Event Detail", $detail);
                    return InvalidParameter();
                }

                if ($kingdom_id) {
                    $this->attendance->kingdom_id = $kingdom_id;
                }
                if (valid_id($detail['CalendarEventDetails'][0]['AtParkId'])) {
                    $this->attendance->park_id = $detail['CalendarEventDetails'][0]['AtParkId'];
                }
                $this->attendance->date = $detail['CalendarEventDetails'][0]['EventStart'];
                $this->attendance->event_id = $detail['CalendarEventDetails'][0]['EventId'];
                $this->attendance->event_calendardetail_id = $request['EventCalendarDetailId'];
                break;
            default:
                return InvalidParameter();
        }

        // Compute date partition columns and set them before the INSERT.
        $_parts = $this->_computeDatePartitions($this->attendance->date);
        $this->attendance->date_year  = $_parts['date_year'];
        $this->attendance->date_month = $_parts['date_month'];
        $this->attendance->date_week3 = $_parts['date_week3'];
        $this->attendance->date_week6 = $_parts['date_week6'];
        logtrace("AddAttendance: pre-save", array('date' => $this->attendance->date, 'parts' => $_parts));

        $attendance_id = $this->attendance->save();

        logtrace("Attendance->AddAttendance() post-save", array(
            'save_return'  => $attendance_id,
            'att_id_prop'  => $this->attendance->attendance_id,
            'last_sql'     => $this->attendance->lastSql(),
        ));

        if ($this->attendance->attendance_id) {
            $ck = Ork3::$Lib->ghettocache->key(['MundaneId' => (int)$this->attendance->mundane_id]);
            Ork3::$Lib->ghettocache->bust('Player.GetPlayerClasses', $ck);
            return Success($this->attendance->attendance_id);
        }
        logtrace("AddAttendance: falling through to InvalidParameter", array('att_id' => $this->attendance->attendance_id));
        return InvalidParameter();
    }

    public function SetAttendance($request)
    {

        logtrace("Attendance->SetAttendance()", $request);

        if ($this->AttendanceAuthority($request) === false) {
            return NoAuthorization();
        }

        $this->attendance->clear();
        $this->attendance->attendance_id = $request['AttendanceId'];
        if (!valid_id($request['AttendanceId']) || !$this->attendance->find()) {
            return InvalidParameter();
        }

        $attendance = new stdClass();
        $attendance->attendance_id = $this->attendance->attendance_id;
        $attendance->mundane_id = $this->attendance->mundane_id;
        $attendance->class_id = $this->attendance->class_id;
        $attendance->date = $this->attendance->date;
        $attendance->park_id = $this->attendance->park_id;
        $attendance->kingdom_id = $this->attendance->kingdom_id;
        $attendance->event_id = $this->attendance->event_id;
        $attendance->event_calendardetail_id = $this->attendance->event_calendardetail_id;
        $attendance->credits = $this->attendance->credits;
        $attendance->persona = $this->attendance->persona;
        $attendance->flavor = $this->attendance->flavor;
        $attendance->note = $this->attendance->note;

        Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $attendance->mundane_id, $attendance);

        $this->attendance->mundane_id = $request['MundaneId'];
        $this->attendance->class_id = $request['ClassId'];
        $this->attendance->date = $request['Date'];
        $this->attendance->credits = $request['Credits'];
        $this->attendance->by_whom_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        $this->attendance->entered_at = date("Y-m-d H:i:s");
        // Recompute date partitions when date changes.
        $_parts = $this->_computeDatePartitions($this->attendance->date);
        $this->attendance->date_year  = $_parts['date_year'];
        $this->attendance->date_month = $_parts['date_month'];
        $this->attendance->date_week3 = $_parts['date_week3'];
        $this->attendance->date_week6 = $_parts['date_week6'];

        $this->attendance->save();

        logtrace("Attendance->AddAttendance()", array($this->attendance->lastSql()));

        // Bust both old and (possibly different) new owner — admin reassigns happen.
        foreach (array_unique([(int)$attendance->mundane_id, (int)$request['MundaneId']]) as $_mid) {
            if ($_mid > 0) {
                $_ck = Ork3::$Lib->ghettocache->key(['MundaneId' => $_mid]);
                Ork3::$Lib->ghettocache->bust('Player.GetPlayerClasses', $_ck);
            }
        }

        return Success($this->attendance->attendance_id);
    }

    public function HasAttendance($request)
    {
        $sql = "select count(*) > 0 as has_attendance from " . DB_PREFIX . "attendance where ";
        switch ($request['Filter']) {
            case 'Event':
                if (!is_numeric($request['Value'])) {
                    return InvalidParameter('An event_calendardetail_id must be selected for this event test.');
                }
                $sql .= " event_calendardetail_id = " . $request['Value'];
                break;
            default:
                return InvalidParameter('No valid Filter selected.');
        }
        $r = $this->db->query($sql);
        return $r->has_attendance;
    }

    public function AttendanceAuthority($request)
    {
        logtrace("Attendance->AttendanceAuthority()", $request);
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        if (!valid_id($mundane_id)) {
            logtrace("Attendance->AttendanceAuthority() - No mundane_id", null);
            return false;
        }

        if (valid_id($request['AttendanceId'])) {
            $this->attendance->attendance_id = $request['AttendanceId'];
            if ($this->attendance->find()) {
                return $this->attendance_authority_h(array('Token' => $request['Token'], 'ParkId' => $this->attendance->park_id, 'KingdomId' => $this->attendance->kingdom_id, 'EventId' => $this->attendance->event_id ));
            } else {
                return false;
            }
        } else {
            return $this->attendance_authority_h($request);
        }
    }

    private function attendance_authority_h($request)
    {
        logtrace("Attendance->attendance_authority_h()", $request);
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        if (valid_id($request['EventCalendarDetailId'])) {
            $detail = Ork3::$Lib->event->GetEventDetail(array('EventCalendarDetailId' => $request['EventCalendarDetailId']));
            if ($detail['Status']['Status'] != 0) {
                logtrace('attendance_authority_h() - ecdid match', $detail);
                return false;
            }
            if (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_EVENT, $detail['CalendarEventDetails'][0]['EventId'], AUTH_EDIT)) {
                return AUTH_EVENT;
            }
            // Check event staff with can_attendance permission
            $this->db->Clear();
            $staffRow = $this->db->DataSet('SELECT 1 FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . (int)$request['EventCalendarDetailId'] . ' AND mundane_id = ' . (int)$mundane_id . ' AND can_attendance = 1 LIMIT 1');
            if ($staffRow && $staffRow->Next()) {
                return AUTH_EVENT;
            }
        } elseif (valid_id($request['EventId'])) {
            if (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_EVENT, $request['EventId'], AUTH_EDIT)) {
                return AUTH_EVENT;
            }
            // Check event staff with can_attendance permission (via event_id join for delete path)
            $this->db->Clear();
            $staffRow = $this->db->DataSet('SELECT 1 FROM ' . DB_PREFIX . 'event_staff s JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = s.event_calendardetail_id WHERE cd.event_id = ' . (int)$request['EventId'] . ' AND s.mundane_id = ' . (int)$mundane_id . ' AND s.can_attendance = 1 LIMIT 1');
            if ($staffRow && $staffRow->Next()) {
                return AUTH_EVENT;
            }
        } elseif (valid_id($request['ParkId'])) {
            if (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $request['ParkId'], AUTH_EDIT)) {
                return AUTH_PARK;
            }
        } elseif (valid_id($request['KingdomId'])) {
            if (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $request['KingdomId'], AUTH_EDIT)) {
                return AUTH_KINGDOM;
            }
        } else {
            logtrace('attendance_authority_h() - no matches');
            return false;
        }
        return false;
    }

    public function RemoveAttendance($request)
    {

        logtrace("Attendance->RemoveAttendance()", $request);

        if ($this->AttendanceAuthority($request) === false) {
            return NoAuthorization();
        }

        $this->attendance->clear();
        $this->attendance->attendance_id = $request['AttendanceId'];
        if (!valid_id($request['AttendanceId']) || !$this->attendance->find()) {
            return InvalidParameter();
        }

        $attendance = new stdClass();
        $attendance->attendance_id = $this->attendance->attendance_id;
        $attendance->mundane_id = $this->attendance->mundane_id;
        $attendance->class_id = $this->attendance->class_id;
        $attendance->date = $this->attendance->date;
        $attendance->park_id = $this->attendance->park_id;
        $attendance->kingdom_id = $this->attendance->kingdom_id;
        $attendance->event_id = $this->attendance->event_id;
        $attendance->event_calendardetail_id = $this->attendance->event_calendardetail_id;
        $attendance->credits = $this->attendance->credits;
        $attendance->persona = $this->attendance->persona;
        $attendance->flavor = $this->attendance->flavor;
        $attendance->note = $this->attendance->note;

        Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $attendance->mundane_id, $attendance);

        $this->attendance->delete();

        if ((int)$attendance->mundane_id > 0) {
            $_ck = Ork3::$Lib->ghettocache->key(['MundaneId' => (int)$attendance->mundane_id]);
            Ork3::$Lib->ghettocache->bust('Player.GetPlayerClasses', $_ck);
        }

        return Success($this->attendance->attendance_id);
    }

    private function _computeDatePartitions($date_str)
    {
        // Guard: empty/null date_str causes strtotime() to return false → epoch silently.
        $_ts = strtotime($date_str);
        if (!$_ts) {
            return ['date_year' => 0, 'date_month' => 0, 'date_week3' => 0, 'date_week6' => 0];
        }
        // date_week3 = ISO 8601 week (Monday-start) — PHP date('W') matches WEEK(date,3).
        // date_week6 = Sunday-start, week 1 contains Jan 1 — use MariaDB directly to ensure
        //              year-boundary correctness (last days of Dec / first days of Jan can
        //              belong to the prior or next year's week in MariaDB's mode-6 semantics).
        $_safe = date('Y-m-d', $_ts);
        $this->db->Clear();
        $_rs = $this->db->DataSet(
            "SELECT YEAR('{$_safe}') AS yr, MONTH('{$_safe}') AS mo," .
            " WEEK('{$_safe}', 3) AS wk3, WEEK('{$_safe}', 6) AS wk6"
        );
        if ($_rs && $_rs->Next() && $_rs->yr !== null) {
            return [
                'date_year'  => (int)$_rs->yr,
                'date_month' => (int)$_rs->mo,
                'date_week3' => (int)$_rs->wk3,
                'date_week6' => (int)$_rs->wk6,
            ];
        }
        // Fallback to PHP if DB call fails (PHP date('W') = ISO week = WEEK(date,3))
        return [
            'date_year'  => (int)date('Y', $_ts),
            'date_month' => (int)date('n', $_ts),
            'date_week3' => (int)date('W', $_ts),
            'date_week6' => 0,
        ];
    }



    public function GetPlayerLastClass($request)
    {
        $mundane_id = (int)($request['MundaneId'] ?? 0);
        if (!valid_id($mundane_id)) {
            return 0;
        }
        $this->db->Clear();
        $r = $this->db->query('SELECT class_id FROM ' . DB_PREFIX . 'attendance WHERE mundane_id = ' . $mundane_id . ' ORDER BY date DESC, attendance_id DESC LIMIT 1');
        return ($r && isset($r->class_id)) ? (int)$r->class_id : 0;
    }

    public function CreateAttendanceLink($request)
    {
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        if (!valid_id($mundane_id)) {
            return NoAuthorization();
        }

        $park_id                 = (int)($request['ParkId'] ?? 0);
        $kingdom_id              = (int)($request['KingdomId'] ?? 0);
        $event_id                = (int)($request['EventId'] ?? 0);
        $event_calendardetail_id = (int)($request['EventCalendarDetailId'] ?? 0);

        // Credits is required, must be > 0
        if (!isset($request['Credits']) || $request['Credits'] === '' || (float)$request['Credits'] <= 0) {
            return InvalidParameter('Credits is required.');
        }
        $credits = (float)$request['Credits'];
        if ($credits > 10) {
            $credits = 10.0;
        }

        $expires_at = null;

        if (valid_id($event_id)) {
            if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_EVENT, $event_id, AUTH_EDIT)) {
                // Allow event staff with can_attendance permission
                $ok = false;
                if (valid_id($event_calendardetail_id)) {
                    $this->db->Clear();
                    $row = $this->db->DataSet('SELECT 1 FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . $event_calendardetail_id . ' AND mundane_id = ' . $mundane_id . ' AND can_attendance = 1 LIMIT 1');
                    if ($row && $row->Next()) {
                        $ok = true;
                    }
                }
                if (!$ok) {
                    return NoAuthorization();
                }
            }
            // Resolve event end -> expires 24h after event end
            if (valid_id($event_calendardetail_id)) {
                $detail = Ork3::$Lib->event->GetEventDetail(['EventCalendarDetailId' => $event_calendardetail_id]);
                if (($detail['Status']['Status'] ?? 1) != 0) {
                    return InvalidParameter('Could not load event detail.');
                }
                $cd          = $detail['CalendarEventDetails'][0] ?? [];
                $event_end   = $cd['EventEnd']   ?? '';
                $event_start = $cd['EventStart'] ?? '';
            } else {
                $this->db->Clear();
                $row = $this->db->DataSet('SELECT MAX(event_end) AS event_end, MAX(event_start) AS event_start FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_id = ' . $event_id);
                if (!$row || !$row->Next()) {
                    return InvalidParameter('Event not found.');
                }
                $event_end   = $row->event_end;
                $event_start = $row->event_start;
            }
            if (!$event_end || $event_end === '0000-00-00 00:00:00') {
                $event_end = $event_start;
            }
            if (!$event_end) {
                return InvalidParameter('Event has no end date.');
            }
            // B9: Server-side check-in window gate. The Eventnew front-end shows the
            // "Create sign-in link" affordance only when the event starts within 24h;
            // enforce the same on the server so a hand-crafted request cannot create
            // a link weeks in advance.
            $_event_start_ts = $event_start ? strtotime($event_start) : false;
            if ($_event_start_ts !== false && $_event_start_ts > (time() + 86400)) {
                return InvalidParameter('Check-in window not yet open.');
            }
            // Store as UTC — MySQL `NOW()` returns UTC on this server, so PHP
            // must too or all `expires_at > NOW()` checks silently misfire by
            // the PHP-to-MySQL timezone offset (gave links 0 effective hours).
            $expires_at = gmdate('Y-m-d H:i:s', strtotime($event_end) + 86400);
            if (strtotime($expires_at . ' UTC') <= time()) {
                return InvalidParameter('This event has already ended.');
            }
        } elseif (valid_id($park_id)) {
            if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $park_id, AUTH_EDIT)) {
                return NoAuthorization();
            }
        } elseif (valid_id($kingdom_id)) {
            if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT)) {
                return NoAuthorization();
            }
        } else {
            return InvalidParameter('ParkId, KingdomId, or EventId required.');
        }

        if ($expires_at === null) {
            $hours      = min(120, max(1, (int)($request['Hours'] ?? 3)));
            // UTC — see comment above.
            $expires_at = gmdate('Y-m-d H:i:s', time() + $hours * 3600);
        }

        $token = bin2hex(random_bytes(24));

        $this->attendance_link->clear();
        $this->attendance_link->token                   = $token;
        $this->attendance_link->park_id                 = $park_id;
        $this->attendance_link->kingdom_id              = $kingdom_id;
        // R2 fix: After B3 migration, event_id/event_calendardetail_id are NULL-able
        // with FK CASCADE. Writing integer 0 fails the FK check (no parent row with id=0).
        // Persist NULL for park/kingdom-scoped links instead.
        $this->attendance_link->event_id                = valid_id($event_id) ? $event_id : null;
        $this->attendance_link->event_calendardetail_id = valid_id($event_calendardetail_id) ? $event_calendardetail_id : null;
        $this->attendance_link->by_whom_id              = $mundane_id;
        $this->attendance_link->credits                 = $credits;
        $this->attendance_link->expires_at              = $expires_at;
        $this->attendance_link->created_at              = gmdate('Y-m-d H:i:s');
        $this->attendance_link->save();

        if (!$this->attendance_link->link_id) {
            return InvalidParameter('Could not create link.');
        }
        return Success(['Token' => $token, 'ExpiresAt' => $expires_at, 'LinkId' => (int)$this->attendance_link->link_id]);
    }

    public function GetAttendanceLinkInfo($request)
    {
        $token = preg_replace('/[^a-f0-9]/', '', (string)($request['LinkToken'] ?? ''));
        if (strlen($token) !== 48) {
            return InvalidParameter('Invalid link token.');
        }

        $this->attendance_link->clear();
        $this->attendance_link->token = $token;
        if (!$this->attendance_link->find()) {
            return InvalidParameter('Link not found.');
        }
        // Revoked links are explicit (someone hit Revoke). Distinguish from
        // natural expiry so the UI can show a more accurate message.
        if (!empty($this->attendance_link->revoked_at)) {
            return InvalidParameter('This sign-in link has been revoked.');
        }
        // expires_at is stored as UTC (gmdate at insert time) — strtotime would
        // otherwise interpret as PHP local TZ and mis-compare.
        if (strtotime($this->attendance_link->expires_at . ' UTC') <= time()) {
            return InvalidParameter('This sign-in link has expired.');
        }

        return Success([
            'LinkId'                 => (int)$this->attendance_link->link_id,
            'ParkId'                 => (int)$this->attendance_link->park_id,
            'KingdomId'              => (int)$this->attendance_link->kingdom_id,
            'EventId'                => (int)$this->attendance_link->event_id,
            'EventCalendarDetailId'  => (int)$this->attendance_link->event_calendardetail_id,
            'Credits'                => (float)$this->attendance_link->credits,
            'ExpiresAt'              => $this->attendance_link->expires_at,
        ]);
    }

    public function UseAttendanceLink($request)
    {
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        if (!valid_id($mundane_id)) {
            return NoAuthorization('Must be logged in.');
        }

        $token = preg_replace('/[^a-f0-9]/', '', (string)($request['LinkToken'] ?? ''));
        if (strlen($token) !== 48) {
            return InvalidParameter('Invalid link token.');
        }

        $this->attendance_link->clear();
        $this->attendance_link->token = $token;
        if (!$this->attendance_link->find()) {
            return InvalidParameter('Link not found.');
        }
        // Revoked links are explicit (someone hit Revoke). Distinguish from
        // natural expiry so the UI can show a more accurate message.
        if (!empty($this->attendance_link->revoked_at)) {
            return InvalidParameter('This sign-in link has been revoked.');
        }
        // expires_at is stored as UTC (gmdate at insert time) — strtotime would
        // otherwise interpret as PHP local TZ and mis-compare.
        if (strtotime($this->attendance_link->expires_at . ' UTC') <= time()) {
            return InvalidParameter('This sign-in link has expired.');
        }

        $park_id                 = (int)$this->attendance_link->park_id;
        $kingdom_id              = (int)$this->attendance_link->kingdom_id;
        $event_id                = (int)$this->attendance_link->event_id;
        $event_calendardetail_id = (int)$this->attendance_link->event_calendardetail_id;
        $credits                 = (float)$this->attendance_link->credits;

        $class_id = (int)($request['ClassId'] ?? 0);
        if (!valid_id($class_id)) {
            return InvalidParameter('Class is required.');
        }

        // Event-scoped link: use the event's date and resolve park/kingdom from event
        $attendance_date = date('Y-m-d');
        if (valid_id($event_id)) {
            $detail = Ork3::$Lib->event->GetEventDetail(['EventCalendarDetailId' => $event_calendardetail_id]);
            if (($detail['Status']['Status'] ?? 1) != 0) {
                return InvalidParameter('Could not load event.');
            }
            $cd = $detail['CalendarEventDetails'][0] ?? [];
            $attendance_date = !empty($cd['EventStart']) ? date('Y-m-d', strtotime($cd['EventStart'])) : $attendance_date;
            if (!valid_id($park_id) && !empty($cd['AtParkId'])) {
                $park_id = (int)$cd['AtParkId'];
            }
            $ev = Ork3::$Lib->event->GetEvent(['EventId' => $event_id]);
            if (!valid_id($kingdom_id) && !empty($ev['KingdomId'])) {
                $kingdom_id = (int)$ev['KingdomId'];
            }
        }

        // If park-level link, resolve kingdom_id from park record
        if (valid_id($park_id) && !valid_id($kingdom_id)) {
            $park = Ork3::$Lib->park->GetParkShortInfo(['ParkId' => $park_id]);
            $kingdom_id = (int)($park['ParkInfo']['KingdomId'] ?? 0);
        }

        // Get player persona. YapoResultSet doesn't pre-advance — must call
        // Next() before field access or values are silently null.
        $this->db->Clear();
        $player_row = $this->db->query('SELECT persona FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . $mundane_id . ' LIMIT 1');
        $persona    = ($player_row && $player_row->Next() && isset($player_row->persona)) ? (string)$player_row->persona : '';

        // Check for duplicate sign-in: for events, check by event_calendardetail_id; for park, by date+park.
        // Same Next() requirement as above — without it the check silently
        // returned "no row", letting players grant themselves unlimited credits.
        $this->db->Clear();
        if (valid_id($event_calendardetail_id)) {
            $check = $this->db->query(
                'SELECT attendance_id FROM ' . DB_PREFIX . 'attendance WHERE mundane_id = ' . $mundane_id .
                ' AND event_calendardetail_id = ' . $event_calendardetail_id . ' LIMIT 1'
            );
            if ($check && $check->Next() && (int)$check->attendance_id > 0) {
                return InvalidParameter('You have already signed in to this event.');
            }
        } else {
            $check = $this->db->query(
                'SELECT attendance_id FROM ' . DB_PREFIX . 'attendance WHERE mundane_id = ' . $mundane_id .
                " AND date = '" . $attendance_date . "' AND park_id = " . $park_id . ' AND kingdom_id = ' . $kingdom_id .
                ' AND event_id = 0 LIMIT 1'
            );
            if ($check && $check->Next() && (int)$check->attendance_id > 0) {
                return InvalidParameter('You have already signed in today.');
            }
        }

        $this->attendance->clear();
        $this->attendance->park_id                  = $park_id;
        $this->attendance->kingdom_id               = $kingdom_id;
        $this->attendance->mundane_id               = $mundane_id;
        $this->attendance->persona                  = $persona;
        $this->attendance->class_id                 = $class_id;
        $this->attendance->date                     = $attendance_date;
        $this->attendance->credits                  = $credits;
        $this->attendance->note                     = '';
        $this->attendance->flavor                   = '';
        $this->attendance->by_whom_id               = $mundane_id;
        $this->attendance->entered_at               = date('Y-m-d H:i:s');
        // Self-signin via a PM-issued QR/link. The link creator's mundane_id
        // is preserved in ork_attendance_link.by_whom_id if reports want to
        // surface "link issued by Lacris, used by Augustus".
        $this->attendance->entry_method             = 'signin_link';
        $this->attendance->event_id                 = $event_id;
        $this->attendance->event_calendardetail_id  = $event_calendardetail_id;
        $this->attendance->date_year                = 0;
        $this->attendance->date_month               = 0;
        $this->attendance->date_week3               = 0;
        $this->attendance->date_week6               = 0;
        $this->attendance->save();

        if ($this->attendance->attendance_id) {
            $this->db->query(
                'UPDATE ' . DB_PREFIX . 'attendance SET date_year = YEAR(`date`), date_month = MONTH(`date`), date_week3 = WEEK(`date`, 3), date_week6 = WEEK(`date`, 6) WHERE attendance_id = ' . $this->attendance->attendance_id
            );
            // B6: Bust player caches so newly-recorded attendance appears immediately.
            $_ck = Ork3::$Lib->ghettocache->key(['MundaneId' => (int)$mundane_id]);
            Ork3::$Lib->ghettocache->bust('Model_Player.fetch_player_details', $_ck);
            Ork3::$Lib->ghettocache->bust('Player.GetPlayerClasses', $_ck);
            return Success($this->attendance->attendance_id);
        }
        return InvalidParameter('Could not save attendance. You may have already signed in today.');
    }

    // Used by the SignIn page to determine whether the logged-in player has
    // already signed in for the scope (date+park or event detail) of a given
    // attendance link. If they have, the page offers them a "change my class"
    // flow rather than silently rejecting their second tap.
    // Returns ['AttendanceId' => int, 'ClassId' => int, 'ClassName' => string]
    // or null if no existing row matches.
    public function GetExistingSignin($mundane_id, $link_info)
    {
        $mundane_id              = (int)$mundane_id;
        $park_id                 = (int)($link_info['ParkId'] ?? 0);
        $event_id                = (int)($link_info['EventId'] ?? 0);
        $event_calendardetail_id = (int)($link_info['EventCalendarDetailId'] ?? 0);

        // Event-scoped: match by event_calendardetail_id (same logic as UseAttendanceLink dup check)
        $this->db->Clear();
        if (valid_id($event_calendardetail_id)) {
            $rs = $this->db->query(
                'SELECT a.attendance_id, a.class_id, c.name AS class_name ' .
                'FROM ' . DB_PREFIX . 'attendance a ' .
                'LEFT JOIN ' . DB_PREFIX . 'class c ON c.class_id = a.class_id ' .
                'WHERE a.mundane_id = ' . $mundane_id .
                ' AND a.event_calendardetail_id = ' . $event_calendardetail_id . ' LIMIT 1'
            );
        } elseif (valid_id($park_id)) {
            // Don't filter on kingdom_id here: the link table stores 0 for
            // park-scoped links, but the attendance row stores the park's
            // parent kingdom_id (resolved at insert time). (mundane_id, date,
            // park_id, event_id=0) is unique enough for the "did this player
            // already sign in here today?" question.
            $today = date('Y-m-d');
            $rs = $this->db->query(
                'SELECT a.attendance_id, a.class_id, c.name AS class_name ' .
                'FROM ' . DB_PREFIX . 'attendance a ' .
                'LEFT JOIN ' . DB_PREFIX . 'class c ON c.class_id = a.class_id ' .
                "WHERE a.mundane_id = $mundane_id AND a.date = '$today' " .
                "AND a.park_id = $park_id AND a.event_id = 0 LIMIT 1"
            );
        } else {
            return null;
        }
        if ($rs && $rs->Next() && (int)$rs->attendance_id > 0) {
            return [
                'AttendanceId' => (int)$rs->attendance_id,
                'ClassId'      => (int)$rs->class_id,
                'ClassName'    => (string)$rs->class_name,
            ];
        }
        return null;
    }

    // Update the class on an existing self-signed attendance row. The row
    // must belong to the caller — we re-check mundane_id in the WHERE so a
    // stolen/leaked attendance_id can't be used to rewrite someone else's
    // row.
    //
    // This is the one path in the sign-in-link flow where the data alone
    // loses information on edit (UPDATE overwrites class_id) — so we audit
    // before/after class so a forensic question of "what class was this
    // originally?" can be answered after the fact. Successful insert paths
    // don't need audit because the row IS the record.
    public function UpdateSelfSigninClass($request)
    {
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        if (!valid_id($mundane_id)) {
            return NoAuthorization('Must be logged in.');
        }

        $attendance_id = (int)($request['AttendanceId'] ?? 0);
        $class_id      = (int)($request['ClassId'] ?? 0);
        if (!valid_id($attendance_id)) {
            return InvalidParameter('Invalid attendance id.');
        }
        if (!valid_id($class_id)) {
            return InvalidParameter('Class is required.');
        }

        // Read the prior row to capture before-state for audit AND to verify
        // ownership before we touch anything. The WHERE on the UPDATE would
        // also enforce ownership, but a SELECT first lets us distinguish
        // "not found / not yours" from "found but already that class".
        $this->db->Clear();
        $prior = $this->db->query(
            'SELECT attendance_id, mundane_id, class_id, date, park_id, kingdom_id, event_id, entry_method ' .
            'FROM ' . DB_PREFIX . 'attendance WHERE attendance_id = ' . $attendance_id . ' LIMIT 1'
        );
        if (!$prior || !$prior->Next() || (int)$prior->attendance_id !== $attendance_id) {
            return InvalidParameter('Attendance row not found.');
        }
        if ((int)$prior->mundane_id !== $mundane_id) {
            return NoAuthorization('You can only change your own attendance.');
        }
        $prior_state = [
            'AttendanceId' => (int)$prior->attendance_id,
            'MundaneId'    => (int)$prior->mundane_id,
            'ClassId'      => (int)$prior->class_id,
            'Date'         => (string)$prior->date,
            'ParkId'       => (int)$prior->park_id,
            'KingdomId'    => (int)$prior->kingdom_id,
            'EventId'      => (int)$prior->event_id,
            'EntryMethod'  => (string)$prior->entry_method,
        ];

        // No-op shortcut: same class already; don't touch DB or audit log.
        if ((int)$prior->class_id === $class_id) {
            return Success(['AttendanceId' => $attendance_id, 'ClassId' => $class_id, 'Unchanged' => true]);
        }

        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'attendance SET class_id = ' . $class_id . ', flavor = \'\' ' .
            'WHERE attendance_id = ' . $attendance_id . ' AND mundane_id = ' . $mundane_id . ' LIMIT 1'
        );

        $post_state = array_merge($prior_state, ['ClassId' => $class_id]);
        $audit_payload = [
            'AttendanceId' => $attendance_id,
            'PriorClassId' => $prior_state['ClassId'],
            'NewClassId'   => $class_id,
            'RemoteAddr'   => $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $audit_payload, 'Player', $mundane_id, $prior_state, $post_state);

        return Success(['AttendanceId' => $attendance_id, 'ClassId' => $class_id]);
    }

    public function GetAttendanceLinks($request)
    {
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        if (!valid_id($mundane_id)) {
            return NoAuthorization();
        }

        $park_id    = (int)($request['ParkId'] ?? 0);
        $kingdom_id = (int)($request['KingdomId'] ?? 0);
        $event_id   = (int)($request['EventId'] ?? 0);

        if (valid_id($event_id)) {
            if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_EVENT, $event_id, AUTH_EDIT)) {
                $ecdid = (int)($request['EventCalendarDetailId'] ?? 0);
                $ok = false;
                if (valid_id($ecdid)) {
                    $this->db->Clear();
                    $row = $this->db->DataSet('SELECT 1 FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . $ecdid . ' AND mundane_id = ' . $mundane_id . ' AND can_attendance = 1 LIMIT 1');
                    if ($row && $row->Next()) {
                        $ok = true;
                    }
                }
                if (!$ok) {
                    return NoAuthorization();
                }
            }
            $where = 'al.event_id = ' . $event_id;
        } elseif (valid_id($park_id)) {
            if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $park_id, AUTH_EDIT)) {
                return NoAuthorization();
            }
            // Columns are aliased — both ork_attendance_link and ork_park have a
            // park_id column, so bare `park_id` is ambiguous and fails the whole
            // SELECT silently (modal showed "No active links" forever).
            $where = 'al.park_id = ' . $park_id . ' AND al.event_id IS NULL';
        } elseif (valid_id($kingdom_id)) {
            if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT)) {
                return NoAuthorization();
            }
            $where = 'al.kingdom_id = ' . $kingdom_id . ' AND (al.park_id = 0 OR al.park_id IS NULL) AND al.event_id IS NULL';
        } else {
            return InvalidParameter('ParkId, KingdomId, or EventId required.');
        }

        $this->db->Clear();
        $rows = $this->db->DataSet('SELECT al.link_id, al.token, al.credits, al.expires_at, al.park_id, COALESCE(p.name, \'\') AS park_name FROM ' . DB_PREFIX . 'attendance_link al LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = al.park_id AND al.park_id > 0 WHERE ' . $where . ' AND al.revoked_at IS NULL AND al.expires_at > NOW() ORDER BY al.expires_at DESC LIMIT 20');
        $links = [];
        if ($rows) {
            while ($rows->Next()) {
                $links[] = [
                    'LinkId'    => (int)$rows->link_id,
                    'Token'     => $rows->token,
                    'Credits'   => (float)$rows->credits,
                    'ExpiresAt' => $rows->expires_at,
                    'ParkId'    => (int)$rows->park_id,
                    'ParkName'  => (string)$rows->park_name,
                ];
            }
        }
        return Success($links);
    }

    public function DeleteAttendanceLink($request)
    {
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        if (!valid_id($mundane_id)) {
            return NoAuthorization();
        }

        $link_id = (int)($request['LinkId'] ?? 0);
        if (!valid_id($link_id)) {
            return InvalidParameter('Invalid link ID.');
        }

        $this->attendance_link->clear();
        $this->attendance_link->link_id = $link_id;
        if (!$this->attendance_link->find()) {
            return InvalidParameter('Link not found.');
        }

        $park_id    = (int)$this->attendance_link->park_id;
        $kingdom_id = (int)$this->attendance_link->kingdom_id;
        $event_id   = (int)$this->attendance_link->event_id;
        $ecdid      = (int)$this->attendance_link->event_calendardetail_id;
        $authorized = false;
        if (valid_id($event_id) && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_EVENT, $event_id, AUTH_EDIT)) {
            $authorized = true;
        } elseif (valid_id($event_id) && valid_id($ecdid)) {
            $this->db->Clear();
            $row = $this->db->DataSet('SELECT 1 FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . $ecdid . ' AND mundane_id = ' . $mundane_id . ' AND can_attendance = 1 LIMIT 1');
            if ($row && $row->Next()) {
                $authorized = true;
            }
        }
        if (!$authorized && valid_id($park_id) && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $park_id, AUTH_EDIT)) {
            $authorized = true;
        }
        if (!$authorized && valid_id($kingdom_id) && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT)) {
            $authorized = true;
        }
        if (!$authorized) {
            return NoAuthorization();
        }

        // Record the actual revocation in revoked_at / revoked_by (UTC, mirroring
        // expires_at). Preserves the original expires_at so we can still tell
        // "would have expired naturally at X, was revoked at Y by Z."
        $this->attendance_link->revoked_at = gmdate('Y-m-d H:i:s');
        $this->attendance_link->revoked_by = (int)$mundane_id;
        $this->attendance_link->save();
        return Success($link_id);
    }

    public function _create_system_classes()
    {
        $classes = array(
            0 => 'Anti-Paladin',
            1 => 'Archer',
            2 => 'Assassin',
            3 => 'Barbarian',
            4 => 'Bard',
            5 => 'Color',
            6 => 'Druid',
            7 => 'Healer',
            8 => 'Monk',
            9 => 'Monster',
            10 => 'Paladin',
            11 => 'Peasant',
            12 => 'Color',
            13 => 'Reeve',
            14 => 'Scout',
            15 => 'Warrior',
            16 => 'Wizard');

        foreach ($classes as $class) {
            $this->class->clear();
            $this->class->name = $class;
            $this->class->active = 1;
            $this->class->save();
        }
    }

}
