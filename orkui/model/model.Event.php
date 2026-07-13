<?php

class Model_Event extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->Kingdom = new APIModel('Kingdom');
        $this->Report = new APIModel('Report');
        $this->Event = new APIModel('Event');
        $this->Heraldry = new APIModel('Heraldry');
        $this->Search = new JSONModel('Search');
        $this->Log = $LOG;
    }

    public function create_event($token, $kingdom_id, $park_id, $mundane_id, $unit_id, $name, $status = 'published')
    {
        $request = array(
            'Token' => $token,
            'KingdomId' => $kingdom_id,
            'ParkId' => $park_id,
            'MundaneId' => $mundane_id,
            'UnitId' => $unit_id,
            'Name' => $name,
            'Status' => $status,
        );
        logtrace("create_event()", $request);
        $r = $this->Event->CreateEvent($request);
        return $r;
    }

    public function get_event_details($event_id)
    {

        $r = $this->Event->GetEvent(array('EventId' => $event_id));
        if ($r['Status']['Status'] == 0) {
            $ret = $r;

            $detailsResult = $this->Event->GetEventDetails(array('EventId' => $event_id));
            if (isset($detailsResult['Status']['Status']) && $detailsResult['Status']['Status'] == 0) {
                $ret['CalendarEventDetails'] = $detailsResult['CalendarEventDetails'];
            } else {
                $ret['CalendarEventDetails'] = array();
            }

            $searchResult = $this->Search->Search_Event(null, null, null, null, null, 1, $event_id);
            if (is_array($searchResult)) {
                $ret['EventInfo'] = $searchResult;
            } else {
                $ret['EventInfo'] = array();
            }

            // Reuse the first GetEvent() result ($r) instead of calling GetEvent() again
            $ret['HeraldryUrl'] = $r['HeraldryUrl'] ?? '';
            $ret['HasHeraldry'] = $r['HasHeraldry'] ?? false;

            // Merge banner-image fields onto EventInfo[0] so the template (which
            // reads $info[0] from Search_Event) sees them alongside HasHeraldry.
            if (isset($ret['EventInfo'][0]) && is_array($ret['EventInfo'][0])) {
                $ret['EventInfo'][0]['HasBanner']      = $r['HasBanner']      ?? 0;
                $ret['EventInfo'][0]['BannerShowLogo'] = $r['BannerShowLogo'] ?? 1;
                $ret['EventInfo'][0]['BannerVignette'] = $r['BannerVignette'] ?? 1;
                $ret['EventInfo'][0]['BannerOffsetX']  = $r['BannerOffsetX']  ?? 50;
                $ret['EventInfo'][0]['BannerOffsetY']  = $r['BannerOffsetY']  ?? 50;
            }

            return $ret;
        } else {
            return $r;
        }
    }

    public function update_event_detail($request)
    {
        $r = $this->Event->SetEventDetails($request);
        logtrace("update_event_detail", array($request, $r));
        return $r;
    }

    public function add_event_detail($request)
    {
        $r = $this->Event->CreateEventDetails($request);
        logtrace("add_event_detail", array($request, $r));
        return $r;
    }

    public function delete_calendar_detail($token, $detail_id)
    {
        $r = $this->Event->DeleteEventDetail(array('Token' => $token, 'EventCalendarDetailId' => $detail_id));
        return $r;
    }

    private function _eventApiOk($r)
    {
        if (is_array($r['Status'] ?? null)) {
            return ($r['Status']['Status'] ?? 1) == 0;
        }
        return ($r['Status'] ?? 1) == 0;
    }

    public function event_api_ok($r)
    {
        return $this->_eventApiOk($r);
    }

    public function set_rsvp_dated($detail_id, $mundane_id, $status)
    {
        return $this->Event->SetRsvp([
            'EventCalendarDetailId' => (int)$detail_id,
            'MundaneId' => (int)$mundane_id,
            'Status' => $status,
            'EndDateGate' => 'datetime',
        ]);
    }

    public function withdraw_rsvp_self($detail_id, $mundane_id)
    {
        return $this->Event->WithdrawRsvp([
            'EventCalendarDetailId' => (int)$detail_id,
            'MundaneId' => (int)$mundane_id,
        ]);
    }

    public function get_rsvp($detail_id, $mundane_id)
    {
        $r = $this->Event->GetRsvpStatus([
            'EventCalendarDetailId' => (int)$detail_id,
            'MundaneId' => (int)$mundane_id,
        ]);
        if (!$this->_eventApiOk($r)) {
            return false;
        }
        $status = (string)($r['RsvpStatus'] ?? '');
        return $status !== '' ? $status : false;
    }

    // Sets RSVP to $status ('going'|'interested'). If already that status, removes it (toggle off).
    public function set_rsvp($detail_id, $mundane_id, $status)
    {
        $r = $this->Event->SetRsvp([
            'EventCalendarDetailId' => (int)$detail_id,
            'MundaneId' => (int)$mundane_id,
            'Status' => $status,
            'AllowToggleOff' => true,
            'CoerceInvalidStatus' => true,
            'EndDateGate' => 'none',
        ]);
        if (!$this->_eventApiOk($r)) {
            return false;
        }
        if (!empty($r['ToggledOff'])) {
            return false;
        }
        $myStatus = (string)($r['MyStatus'] ?? '');
        return $myStatus !== '' ? $myStatus : false;
    }

    public function toggle_rsvp($detail_id, $mundane_id)
    {
        return $this->set_rsvp($detail_id, $mundane_id, 'going');
    }

    public function remove_rsvp($detail_id, $mundane_id)
    {
        $r = $this->Event->RemoveRsvp([
            'EventCalendarDetailId' => (int)$detail_id,
            'TargetMundaneId' => (int)$mundane_id,
            'AuthorizedByController' => true,
        ]);
        return $this->_eventApiOk($r);
    }

    public function get_rsvp_summary_batch($detail_ids, $mundane_id = 0)
    {
        if (!is_array($detail_ids) || empty($detail_ids)) {
            return [];
        }
        $request = ['EventCalendarDetailIds' => array_values(array_map('intval', $detail_ids))];
        if ((int)$mundane_id > 0) {
            $request['MundaneId'] = (int)$mundane_id;
        }
        $r = $this->Event->GetRsvpSummaryBatch($request);
        if (!$this->_eventApiOk($r)) {
            return [];
        }
        $byDetail = [];
        foreach ($r['Items'] ?? [] as $item) {
            $did = (int)$item['EventCalendarDetailId'];
            $byDetail[$did] = [
                'going' => (int)($item['Going'] ?? 0),
                'interested' => (int)($item['Interested'] ?? 0),
                'total' => (int)($item['Total'] ?? 0),
                'status' => (string)($item['RsvpStatus'] ?? ''),
            ];
        }
        return $byDetail;
    }

    public function get_rsvp_total_counts_batch($detail_ids)
    {
        $summary = $this->get_rsvp_summary_batch($detail_ids, 0);
        $totals = [];
        foreach ($summary as $did => $counts) {
            $totals[$did] = (int)$counts['total'];
        }
        return $totals;
    }

    public function get_rsvp_count($detail_id)
    {
        $r = $this->Event->GetRsvpCounts(['EventCalendarDetailId' => (int)$detail_id]);
        if (!$this->_eventApiOk($r)) {
            return ['going' => 0, 'interested' => 0, 'total' => 0];
        }
        return [
            'going' => (int)($r['Going'] ?? 0),
            'interested' => (int)($r['Interested'] ?? 0),
            'total' => (int)($r['Total'] ?? 0),
        ];
    }

    public function get_rsvp_list($detail_id)
    {
        $r = $this->Event->GetRsvpList(['EventCalendarDetailId' => (int)$detail_id]);
        if (!$this->_eventApiOk($r)) {
            return [];
        }
        return $r['RsvpPlayers'] ?? [];
    }

    public function get_upcoming_rsvps($mundane_id)
    {
        $r = $this->Event->GetUpcomingRsvps(['MundaneId' => (int)$mundane_id]);
        if (!$this->_eventApiOk($r)) {
            return [];
        }
        $list = [];
        foreach ($r['UpcomingRsvps'] ?? [] as $row) {
            $list[] = [
                'EventCalendarDetailId' => $row['EventCalendarDetailId'],
                'EventId' => $row['EventId'],
                'EventName' => $row['EventName'],
                'EventStart' => $row['EventStart'],
                'EventEnd' => $row['EventEnd'],
            ];
        }
        return $list;
    }

    public function get_kingdom_upcoming_events($kingdom_id, $exclude_mundane_id)
    {
        $r = $this->Event->GetKingdomUpcomingEventsWithoutRsvp([
            'KingdomId' => (int)$kingdom_id,
            'MundaneId' => (int)$exclude_mundane_id,
            'Limit' => 6,
        ]);
        if (!$this->_eventApiOk($r)) {
            return [];
        }
        $list = [];
        foreach ($r['KingdomEvents'] ?? [] as $row) {
            $list[] = [
                'EventCalendarDetailId' => $row['EventCalendarDetailId'],
                'EventId' => $row['EventId'],
                'EventName' => $row['EventName'],
                'EventStart' => $row['EventStart'],
                'EventEnd' => $row['EventEnd'],
                'ParkAbbreviation' => $row['ParkAbbreviation'] ?? '',
            ];
        }
        return $list;
    }

    public function delete_event($token, $event_id)
    {
        $r = $this->Event->DeleteEvent(array('Token' => $token, 'EventId' => (int)$event_id));
        return $r;
    }

    public function update_event($token, $event_id, $kingdom_id, $park_id, $mundane_id, $unit_id, $name, $heraldry, $type)
    {
        $r = $this->Event->SetEvent(array('Token' => $token, 'EventId' => $event_id, 'KingdomId' => $kingdom_id, 'ParkId' => $park_id,
            'MundaneId' => $mundane_id, 'UnitId' => $unit_id,'Name' => $name, 'Heraldry' => $heraldry, 'HeraldryMimeType' => $type));
        logtrace("update_event($token, $event_id, $kingdom_id, $park_id, $mundane_id, $unit_id, $name)", array($r));
        return $r;
    }

    /**
     * Schedule items for one event occurrence. The query remains in
     * EventPlanning so the event page and public embed share a thin model API.
     */
    public function get_schedule($detail_id)
    {
        $r = $this->Event->GetSchedule([
            'EventCalendarDetailId' => (int) $detail_id,
        ]);
        return $this->_eventApiOk($r) ? ($r['ScheduleList'] ?? []) : [];
    }

    public function detail_belongs_to_event($event_id, $detail_id)
    {
        $r = $this->Event->AssertDetailBelongsToEvent([
            'EventId' => (int) $event_id,
            'EventCalendarDetailId' => (int) $detail_id,
        ]);
        return $this->_eventApiOk($r) && !empty($r['Belongs']);
    }

    public function get_default_occurrence_id($event_id)
    {
        $r = $this->Event->GetDefaultOccurrenceId(['EventId' => (int) $event_id]);
        if (!$this->_eventApiOk($r)) {
            return 0;
        }
        return (int) ($r['EventCalendarDetailId'] ?? 0);
    }

    public function get_occurrence_page_data($event_id, $detail_id, $mundane_id = 0, $at_park_id = 0, $fallback_park_id = 0, $include_dietary = false)
    {
        $r = $this->Event->GetOccurrencePageData([
            'EventId' => (int) $event_id,
            'EventCalendarDetailId' => (int) $detail_id,
            'MundaneId' => (int) $mundane_id,
            'AtParkId' => (int) $at_park_id,
            'FallbackParkId' => (int) $fallback_park_id,
            'IncludeDietary' => $include_dietary ? 1 : 0,
        ]);
        if (!$this->_eventApiOk($r)) {
            return null;
        }
        return $r;
    }

    public function set_calendar_detail_fees_and_links($event_id, $detail_id, $fees, $links)
    {
        $r = $this->Event->SetCalendarDetailFeesAndLinks([
            'EventId' => (int) $event_id,
            'EventCalendarDetailId' => (int) $detail_id,
            'Fees' => is_array($fees) ? $fees : [],
            'Links' => is_array($links) ? $links : [],
        ]);
        if (!$this->_eventApiOk($r)) {
            return ['feesOk' => false, 'linksOk' => false];
        }
        return [
            'feesOk' => !empty($r['FeesOk']),
            'linksOk' => !empty($r['LinksOk']),
        ];
    }

    public function set_calendar_detail_event_type($event_id, $detail_id, $event_type)
    {
        $r = $this->Event->SetCalendarDetailEventType([
            'EventId' => (int) $event_id,
            'EventCalendarDetailId' => (int) $detail_id,
            'EventType' => (string) $event_type,
        ]);
        return $this->_eventApiOk($r);
    }

    public function reconcile_past_attendance($token, $event_id, $detail_id)
    {
        $r = $this->Event->ReconcilePastAttendance([
            'Token' => $token,
            'EventId' => (int) $event_id,
            'EventCalendarDetailId' => (int) $detail_id,
        ]);
        if (!$this->_eventApiOk($r)) {
            return 0;
        }
        return (int) ($r['NewEventCalendarDetailId'] ?? 0);
    }

    public function get_detail_dependency_counts($detail_id)
    {
        $r = $this->Event->GetDetailDependencyCounts([
            'EventCalendarDetailId' => (int) $detail_id,
        ]);
        if (!$this->_eventApiOk($r)) {
            return ['attendance' => 0, 'rsvp' => 0];
        }
        return [
            'attendance' => (int) ($r['AttendanceCount'] ?? 0),
            'rsvp' => (int) ($r['RsvpCount'] ?? 0),
        ];
    }

    public function get_event_redirect_scope($event_id)
    {
        $r = $this->Event->GetEventRedirectScope(['EventId' => (int) $event_id]);
        if (!$this->_eventApiOk($r)) {
            return ['kingdom_id' => 0, 'park_id' => 0];
        }
        return [
            'kingdom_id' => (int) ($r['KingdomId'] ?? 0),
            'park_id' => (int) ($r['ParkId'] ?? 0),
        ];
    }

    public function get_park_name($park_id)
    {
        $r = $this->Event->GetParkName(['ParkId' => (int) $park_id]);
        if (!$this->_eventApiOk($r)) {
            return '';
        }
        return (string) ($r['Name'] ?? '');
    }

    public function is_draft_blocked_for_viewer($event_status, $creator_id, $mundane_id, $can_manage, $staff_caps)
    {
        $r = $this->Event->IsDraftBlockedForViewer([
            'EventStatus' => (string) $event_status,
            'CreatorId' => (int) $creator_id,
            'MundaneId' => (int) $mundane_id,
            'CanManageEvent' => $can_manage ? 1 : 0,
            'StaffCaps' => is_array($staff_caps) ? $staff_caps : [],
        ]);
        return $this->_eventApiOk($r) && !empty($r['Blocked']);
}

    public function get_event_templates_for_kingdom(int $kingdomId): array
    {
        return $this->Event->GetEventTemplatesForKingdom($kingdomId);
    }

}
