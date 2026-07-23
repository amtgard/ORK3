<?php

class Model_Attendance extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Attendance = new APIModel('Attendance');
        $this->Report = new APIModel('Report');
        $this->Search = new JSONModel('Search');
        $this->Event = new APIModel('Event');
        $this->Player = new APIModel('Player');
        $this->Weather = new APIModel('Weather');
        $this->Log = $LOG;
    }

    public function get_classes()
    {
        return $this->Attendance->GetClasses(array());
    }

    public function add_attendance($token, $date, $park_id, $detail_id, $mundane_id, $class_id, $credits)
    {
        logtrace("Model_Attendance->add_attendance()", array($token, $date, $park_id, $detail_id, $mundane_id, $class_id, $credits));
        return $this->Attendance->AddAttendance(array('Token' => $token, 'Date' => $date, 'ParkId' => $park_id, 'EventCalendarDetailId' => $detail_id, 'MundaneId' => $mundane_id, 'ClassId' => $class_id, 'Credits' => $credits));
    }

    public function update_attendance($token, $attendance_id, $date, $credits, $class_id, $mundane_id)
    {
        return $this->Attendance->SetAttendance(array(
            'Token'        => $token,
            'AttendanceId' => $attendance_id,
            'MundaneId'    => $mundane_id,
            'Date'         => $date,
            'Credits'      => $credits,
            'ClassId'      => $class_id,
        ));
    }

    public function lookup_by_faces($request)
    {
        return $this->Player->LookupByFaces($request);
    }

    public function delete_attendance($token, $attendance_id, $mundane_id = null)
    {
        return $this->Attendance->RemoveAttendance(array('Token' => $token, 'AttendanceId' => $attendance_id ));
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
        $r = $this->Attendance->GetAdjacentParkDates(['ParkId' => (int)$park_id, 'Date' => $date]);
        if (($r['Status'] ?? 1) == 0 && is_array($r['Detail'] ?? null)) {
            return $r['Detail'];
        }
        return ['prev' => null, 'next' => null];
    }

    public function get_active_events_at_scope($scope, $scope_id, $date)
    {
        return $this->Event->GetActiveEventsAtScope([
            'Scope'   => $scope,
            'ScopeId' => (int)$scope_id,
            'Date'    => $date,
        ]);
    }

    public function get_weather_archive_for_park($park_id, $date)
    {
        $r = $this->Weather->GetArchiveForPark(['ParkId' => (int)$park_id, 'Date' => $date]);
        if (($r['Status'] ?? 1) == 0) {
            return $r['Detail']['Weather'] ?? null;
        }
        return null;
    }

    public function get_weather_archive_for_coords($lat, $lng, $date)
    {
        $r = $this->Weather->GetArchiveForCoords(['Lat' => $lat, 'Lng' => $lng, 'Date' => $date]);
        if (($r['Status'] ?? 1) == 0) {
            return $r['Detail']['Weather'] ?? null;
        }
        return null;
    }

    /**
     * Merge active class list with per-player progression from domain credits.
     *
     * @param list<array<string, mixed>> $classes
     * @return list<array<string, mixed>>
     */
    public function enrich_classes_with_progress(int $mundane_id, array $classes): array
    {
        $progress = $this->Player->ComputeClassProgress(['MundaneId' => $mundane_id]);
        if (($progress['Status'] ?? 1) != 0) {
            return array_values($classes);
        }

        $byClass = [];
        foreach ($progress['Detail'] ?? [] as $row) {
            $byClass[(int)($row['ClassId'] ?? 0)] = $row;
        }

        $enriched = [];
        foreach (array_values($classes) as $c) {
            $cid = (int)($c['ClassId'] ?? 0);
            if (isset($byClass[$cid])) {
                $c['Credits'] = $byClass[$cid]['Credits'];
                $c['Level'] = $byClass[$cid]['Level'];
                $c['ToNext'] = $byClass[$cid]['ToNext'];
            } else {
                $levelInfo = ClassLevel::computeClassLevel(0.0);
                $c['Credits'] = 0.0;
                $c['Level'] = $levelInfo['Level'];
                $c['ToNext'] = $levelInfo['ToNext'];
            }
            $enriched[] = $c;
        }

        return $enriched;
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
