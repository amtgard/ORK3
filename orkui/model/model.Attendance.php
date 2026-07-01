<?php

class Model_Attendance extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Attendance = new APIModel('Attendance');
        $this->Report = new APIModel('Report');
        $this->Search = new JSONMOdel('Search');
        $this->Log = $LOG;
    }

    public function get_classes()
    {
        return $this->Attendance->GetClasses(array());
    }

    public function add_attendance($token, $date, $park_id, $detail_id, $mundane_id, $class_id, $credits)
    {
        logtrace("Model_Attendance->add_attendance()", array($token, $date, $park_id, $detail_id, $mundane_id, $class_id, $credits));
        $r = $this->Attendance->AddAttendance(array('Token' => $token, 'Date' => $date, 'ParkId' => $park_id, 'EventCalendarDetailId' => $detail_id, 'MundaneId' => $mundane_id, 'ClassId' => $class_id, 'Credits' => $credits));
        if (isset($r['Status']) && $r['Status'] == 0 && $mundane_id) {
            $this->bust_player_attendance_caches($mundane_id);
        }
        return $r;
    }

    public function update_attendance($token, $attendance_id, $date, $credits, $class_id, $mundane_id)
    {
        $r = $this->Attendance->SetAttendance(array(
            'Token'        => $token,
            'AttendanceId' => $attendance_id,
            'MundaneId'    => $mundane_id,
            'Date'         => $date,
            'Credits'      => $credits,
            'ClassId'      => $class_id,
        ));
        if ($r['Status'] == 0 && $mundane_id) {
            $this->bust_player_attendance_caches($mundane_id);
        }
        return $r;
    }

    public function lookup_by_faces($request)
    {
        $p = new APIModel("Player");
        return $p->LookupByFaces($request);
    }

    public function delete_attendance($token, $attendance_id, $mundane_id = null)
    {
        $r = $this->Attendance->RemoveAttendance(array('Token' => $token, 'AttendanceId' => $attendance_id ));
        if ($r['Status'] == 0 && $mundane_id) {
            $this->bust_player_attendance_caches($mundane_id);
        }
        return $r;
    }

    // Bust every cache that holds a player's attendance shape: the attendance
    // list, the awards/classes bundle (Class credits change when attendance is
    // reconciled), and the first/last sign-in date caches surfaced on the
    // profile header.
    private function bust_player_attendance_caches($mundane_id)
    {
        $mid = (int)$mundane_id;
        if ($mid <= 0) {
            return;
        }
        $cache = Ork3::$Lib->ghettocache;
        $assocKey = $cache->key(['MundaneId' => $mid]);
        $idKey    = $cache->key(array($mid));
        $cache->bust('Model_Player.fetch_player_details', $assocKey);
        $cache->bust('Model_Player.fetch_player_attendance', $assocKey);
        $cache->bust('Player.get_latest_attendance_date', $idKey);
        $cache->bust('Player.get_earliest_attendance_date', $idKey);
    }

    public function get_attendance_for_date($park_id, $date)
    {
        if (valid_id($park_id)) {
            return $this->Report->AttendanceForDate(array( 'ParkId' => $park_id, 'Date' => $date ));
        }
    }

    public function get_kingdom_attendance_for_date($kingdom_id, $date)
    {
        if (valid_id($kingdom_id)) {
            return $this->Report->AttendanceForDate(array( 'KingdomId' => $kingdom_id, 'Date' => $date ));
        }
    }

    public function get_attendance_for_event($event_id, $detail_id)
    {
        if (valid_id($event_id)) {
            return $this->Report->AttendanceForEvent(array( 'EventId' => $event_id, 'EventCalendarDetailId' => $detail_id ));
        }
    }

    public function get_eventdetail_info($detail_id)
    {
        $r = $this->Search->CalendarDetail($detail_id);
        return $r;
    }

    public function get_event_info($event_id, $include_drafts = false)
    {
        // $include_drafts lets the single-event detail page resolve unpublished
        // (draft) events by direct link, matching Event/index (GetEvent), which
        // does not filter by status.
        $r = $this->Search->Event(null, null, null, null, null, null, $event_id, null, null, 1, 0, $include_drafts);
        logtrace("get_event_info($event_id)", $r);
        return $r;
    }



    public function get_player_last_class($mundane_id)
    {
        return $this->Attendance->GetPlayerLastClass(['MundaneId' => (int)$mundane_id]);
    }

    public function create_attendance_link($args)
    {
        return $this->Attendance->CreateAttendanceLink($args);
    }

    public function get_attendance_link_info($link_token)
    {
        return $this->Attendance->GetAttendanceLinkInfo(['LinkToken' => $link_token]);
    }

    public function use_attendance_link($token, $link_token, $class_id)
    {
        return $this->Attendance->UseAttendanceLink(['Token' => $token, 'LinkToken' => $link_token, 'ClassId' => $class_id]);
    }

    public function get_recent_attendees($park_id)
    {
        return $this->Report->RecentParkAttendees(['ParkId' => $park_id]);
    }

    public function get_adjacent_park_dates($park_id, $date)
    {
        global $DB;
        $pid  = (int)$park_id;
        $date = date('Y-m-d', strtotime($date));
        $DB->Clear();
        $prev = null;
        $r = $DB->DataSet("SELECT DATE(date) AS att_date FROM " . DB_PREFIX . "attendance WHERE park_id = {$pid} AND date < '{$date}' ORDER BY date DESC LIMIT 1");
        if ($r && $r->Next()) {
            $prev = $r->att_date;
        }
        $DB->Clear();
        $next = null;
        $r = $DB->DataSet("SELECT DATE(date) AS att_date FROM " . DB_PREFIX . "attendance WHERE park_id = {$pid} AND date > '{$date}' ORDER BY date ASC LIMIT 1");
        if ($r && $r->Next()) {
            $next = $r->att_date;
        }
        return ['prev' => $prev, 'next' => $next];
    }

    public function get_attendance_links($token, $scope, $id, $event_calendardetail_id = 0)
    {
        $args = ['Token' => $token];
        if ($scope === 'park') {
            $args['ParkId']    = $id;
        } elseif ($scope === 'kingdom') {
            $args['KingdomId'] = $id;
        } else {
            $args['EventId']               = $id;
            $args['EventCalendarDetailId'] = (int)$event_calendardetail_id;
        }
        return $this->Attendance->GetAttendanceLinks($args);
    }

    public function get_existing_signin($mundane_id, $link_info)
    {
        return $this->Attendance->GetExistingSignin($mundane_id, $link_info);
    }

    public function update_self_signin_class($token, $attendance_id, $class_id)
    {
        return $this->Attendance->UpdateSelfSigninClass(['Token' => $token, 'AttendanceId' => $attendance_id, 'ClassId' => $class_id]);
    }

    public function delete_attendance_link($token, $link_id)
    {
        return $this->Attendance->DeleteAttendanceLink(['Token' => $token, 'LinkId' => $link_id]);
    }
}
