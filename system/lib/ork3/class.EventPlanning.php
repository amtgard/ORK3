<?php

class EventPlanning extends Ork3
{
    private const SCHEDULE_CATEGORIES = [
        'Administrative', 'Tournament', 'Battlegame', 'Arts and Sciences', 'Class',
        'Feast and Food', 'Court', 'Meeting', 'Other',
    ];

    /** @var array<int, bool> */
    private array $mundaneEligibleCache = [];

    /**
     * Centralized staff delegation check (T-EVA-02, 04, 07–11).
     *
     * @param string $capability manage|attendance|schedule|feast|edit|create
     */
    public function CanManageEventDetail(int $mundaneId, int $eventId, int $detailId, string $capability): bool
    {
        if ($mundaneId <= 0 || !valid_id($eventId)) {
            return false;
        }

        switch ($capability) {
            case 'edit':
                if (Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_EVENT, $eventId, AUTH_EDIT)) {
                    return true;
                }
                return $this->staffHasFlag($mundaneId, $eventId, $detailId, 'can_manage');
            case 'create':
                if (Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_EVENT, $eventId, AUTH_CREATE)) {
                    return true;
                }
                return $this->staffHasFlag($mundaneId, $eventId, $detailId, 'can_manage');
            case 'manage':
                if (Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_EVENT, $eventId, AUTH_EDIT)) {
                    return true;
                }
                return $this->staffHasFlag($mundaneId, $eventId, $detailId, 'can_manage');
            case 'attendance':
                if (Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_EVENT, $eventId, AUTH_CREATE)) {
                    return true;
                }
                if ($detailId > 0) {
                    $this->db->Clear();
                    $row = $this->db->DataSet(
                        'SELECT 1 FROM ' . DB_PREFIX . 'event_staff es
                         JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = es.event_calendardetail_id
                         WHERE es.event_calendardetail_id = ' . $detailId
                        . ' AND cd.event_id = ' . $eventId
                        . ' AND es.mundane_id = ' . $mundaneId
                        . ' AND es.can_attendance = 1 LIMIT 1'
                    );
                    return (bool) ($row && $row->Next());
                }
                return false;
            case 'schedule':
                return $this->staffScheduleCaps($mundaneId, $eventId, $detailId)['can_schedule'];
            case 'feast':
                $caps = $this->staffScheduleCaps($mundaneId, $eventId, $detailId);
                return $caps['can_schedule'] || $caps['can_feast'];
            default:
                return false;
        }
    }

    public function SetEventStatus($request)
    {
        $mundaneId = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $eventId = (int) ($request['EventId'] ?? 0);
        $status = (string) ($request['Status'] ?? '');

        if ($mundaneId <= 0) {
            return BadToken();
        }
        if (!valid_id($eventId) || !in_array($status, ['published', 'draft'], true)) {
            return InvalidParameter('Invalid parameters');
        }
        if (!$this->CanManageEventDetail($mundaneId, $eventId, 0, 'manage')
            && !Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_EVENT, $eventId, AUTH_EDIT)) {
            return NoAuthorization();
        }

        $this->db->Clear();
        $this->db->Execute(
            "UPDATE " . DB_PREFIX . "event SET status = '" . $status . "' WHERE event_id = " . $eventId
        );
        $this->bustEventScopeCaches($eventId);

        return Success($status);
    }

    public function GetEventPreview($request)
    {
        $eventId = (int) ($request['EventId'] ?? 0);
        $detailId = (int) ($request['EventCalendarDetailId'] ?? 0);
        $uid = (int) ($request['MundaneId'] ?? 0);

        if (!valid_id($eventId)) {
            return InvalidParameter('Invalid event id');
        }

        $isAdmin = $uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_CREATE);

        $this->db->Clear();
        $ev = $this->db->DataSet(
            "SELECT e.event_id, e.name, e.kingdom_id, e.park_id, e.has_heraldry, e.status, e.mundane_id AS creator,
                    p.name AS park_name
             FROM " . DB_PREFIX . "event e
             LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = e.park_id
             WHERE e.event_id = {$eventId} LIMIT 1"
        );
        if (!$ev || !$ev->Next()) {
            return InvalidParameter('Event not found');
        }

        $eventStatus = (string) ($ev->status ?? 'published');
        $canEdit = $uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $eventId, AUTH_EDIT);
        if ($eventStatus !== 'published' && !$canEdit && !$isAdmin && (int) $ev->creator !== $uid) {
            return NoAuthorization();
        }

        $cd = $this->resolveDetailRow($eventId, $detailId);
        if ($cd) {
            $detailId = (int) $cd->event_calendardetail_id;
        }

        $going = 0;
        $interested = 0;
        $myRsvp = '';
        if ($detailId > 0) {
            $eventApi = new Event();
            $counts = $eventApi->GetRsvpCounts(['EventCalendarDetailId' => $detailId]);
            $countStatus = is_array($counts['Status'] ?? null)
                ? (int)($counts['Status']['Status'] ?? 1)
                : (int)($counts['Status'] ?? 1);
            if ($countStatus == 0) {
                $going = (int) ($counts['Going'] ?? 0);
                $interested = (int) ($counts['Interested'] ?? 0);
            }
            if ($uid > 0) {
                $rs = $eventApi->GetRsvpStatus([
                    'EventCalendarDetailId' => $detailId,
                    'MundaneId' => $uid,
                ]);
                $rsStatus = is_array($rs['Status'] ?? null)
                    ? (int)($rs['Status']['Status'] ?? 1)
                    : (int)($rs['Status'] ?? 1);
                if ($rsStatus == 0) {
                    $statusVal = (string) ($rs['RsvpStatus'] ?? '');
                    if ($statusVal !== '') {
                        $myRsvp = $statusVal;
                    }
                }
            }
        }

        $desc = $cd ? (string) ($cd->description ?? '') : '';
        $excerpt = $this->buildDescriptionExcerpt($desc);

        $startTs = $cd ? strtotime((string) $cd->event_start) : 0;
        $endTs = $cd ? strtotime((string) $cd->event_end) : 0;
        $dateLabel = $startTs ? date('l, F j, Y', $startTs) : '';
        $timeLabel = '';
        if ($startTs) {
            $timeLabel = date('g:i A', $startTs);
            if ($endTs && $endTs > $startTs) {
                $timeLabel .= ' – ' . date('g:i A', $endTs);
            }
        }

        return [
            'Status' => Success(),
            'Preview' => [
                'event_id' => $eventId,
                'event_calendardetail_id' => $detailId,
                'name' => (string) $ev->name,
                'park_id' => (int) $ev->park_id,
                'park_name' => (string) ($ev->park_name ?? ''),
                'is_park_event' => (int) $ev->park_id > 0,
                'is_draft' => $eventStatus === 'draft',
                'has_heraldry' => (int) $ev->has_heraldry === 1,
                'date_label' => $dateLabel,
                'time_label' => $timeLabel,
                'price' => $cd ? (float) $cd->price : 0,
                'description_excerpt' => $excerpt,
                'going_count' => $going,
                'interested_count' => $interested,
                'my_rsvp' => $myRsvp,
                'can_edit' => $canEdit,
            ],
        ];
    }

    /** @var list<string> */
    private const CALENDAR_DETAIL_EVENT_TYPES = [
        'Coronation', 'Midreign', 'Endreign', 'Crown Qualifications', 'Day Event', 'Park Raid',
        'Meeting', 'Althing', 'Interkingdom Event', 'Weaponmaster', 'Warmaster', 'Dragonmaster', 'Other',
    ];

    /** @var list<string> */
    private const ALLOWED_LINK_ICONS = [
        'fab fa-facebook', 'fab fa-discord', 'fas fa-globe', 'far fa-clipboard', 'fas fa-link', 'fas fa-ticket-alt',
    ];

    public function GetDefaultOccurrenceId($request)
    {
        $eventId = (int) ($request['EventId'] ?? 0);
        if (!valid_id($eventId)) {
            return InvalidParameter('Invalid event id');
        }

        return [
            'Status' => Success(),
            'EventCalendarDetailId' => $this->resolveDefaultOccurrenceId($eventId),
        ];
    }

    public function AssertDetailBelongsToEvent($request)
    {
        $eventId = (int) ($request['EventId'] ?? 0);
        $detailId = (int) ($request['EventCalendarDetailId'] ?? 0);
        if (!valid_id($eventId) || !valid_id($detailId)) {
            return InvalidParameter('Invalid event or detail id');
        }

        return [
            'Status' => Success(),
            'Belongs' => $this->detailBelongsToEvent($detailId, $eventId),
        ];
    }

    public function GetOccurrencePageData($request)
    {
        $eventId = (int) ($request['EventId'] ?? 0);
        $detailId = (int) ($request['EventCalendarDetailId'] ?? 0);
        $mundaneId = (int) ($request['MundaneId'] ?? 0);
        $includeDietary = !empty($request['IncludeDietary']);

        if (!valid_id($eventId) || !valid_id($detailId)) {
            return InvalidParameter('Invalid event or detail id');
        }
        if (!$this->detailBelongsToEvent($detailId, $eventId)) {
            return InvalidParameter('Detail does not belong to event');
        }

        $this->db->Clear();
        $evtStatusRow = $this->db->DataSet(
            'SELECT status, mundane_id, kingdom_id, park_id FROM ' . DB_PREFIX . 'event
             WHERE event_id = ' . $eventId . ' LIMIT 1'
        );
        $eventStatus = 'published';
        $creatorId = 0;
        $kingdomId = 0;
        $parkId = 0;
        if ($evtStatusRow && $evtStatusRow->Next()) {
            $eventStatus = (string) ($evtStatusRow->status ?? 'published');
            $creatorId = (int) ($evtStatusRow->mundane_id ?? 0);
            $kingdomId = (int) ($evtStatusRow->kingdom_id ?? 0);
            $parkId = (int) ($evtStatusRow->park_id ?? 0);
        }

        $staffCaps = $this->fetchSelfStaffCaps($detailId, $mundaneId);
        $this->db->Clear();
        $cdCountRow = $this->db->DataSet(
            'SELECT COUNT(*) AS cnt FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_id = ' . $eventId . ' LIMIT 1'
        );
        $calendarDetailCount = ($cdCountRow && $cdCountRow->Next()) ? (int) $cdCountRow->cnt : 1;

        $atParkId = (int) ($request['AtParkId'] ?? 0);
        $fallbackParkId = (int) ($request['FallbackParkId'] ?? $parkId);
        $parkLookupId = $atParkId > 0 ? $atParkId : $fallbackParkId;
        $atPark = $this->fetchAtParkDisplay($parkLookupId);

        return [
            'Status' => Success(),
            'EventStatus' => $eventStatus,
            'CreatorId' => $creatorId,
            'KingdomId' => $kingdomId,
            'ParkId' => $parkId,
            'CalendarDetailCount' => $calendarDetailCount,
            'StaffCaps' => $staffCaps,
            'StaffList' => $this->fetchStaffList($detailId),
            'ScheduleList' => $this->fetchScheduleList($detailId),
            'EventFees' => $this->fetchFeeList($detailId),
            'ExternalLinks' => $this->fetchLinkList($detailId),
            'AtPark' => $atPark,
            'DietarySummary' => $includeDietary ? $this->fetchDietarySummary($detailId) : [],
        ];
    }

    public function GetSchedule($request)
    {
        $detailId = (int) ($request['EventCalendarDetailId'] ?? 0);
        if (!valid_id($detailId)) {
            return InvalidParameter('Invalid event occurrence id');
        }

        return [
            'Status' => Success(),
            'ScheduleList' => $this->fetchScheduleList($detailId),
        ];
    }

    public function SetCalendarDetailFeesAndLinks($request)
    {
        $mundaneId = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $eventId = (int) ($request['EventId'] ?? 0);
        $detailId = (int) ($request['EventCalendarDetailId'] ?? 0);
        if ($mundaneId <= 0) {
            return BadToken();
        }
        if (!valid_id($detailId) || !valid_id($eventId)) {
            return InvalidParameter('Invalid detail id');
        }
        if (!$this->detailBelongsToEvent($detailId, $eventId)) {
            return InvalidParameter('Detail does not belong to event');
        }
        if (!$this->CanManageEventDetail($mundaneId, $eventId, $detailId, 'manage')
            && !Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_EVENT, $eventId, AUTH_EDIT)) {
            return NoAuthorization();
        }

        $feesIn = is_array($request['Fees'] ?? null) ? $request['Fees'] : [];
        $linksIn = is_array($request['Links'] ?? null) ? $request['Links'] : [];
        $sync = $this->syncCalendarDetailFeesAndLinks($detailId, $feesIn, $linksIn);

        if ($sync['feesOk'] && $sync['linksOk']) {
            $this->bustCalendarDetailCaches($eventId, $detailId);
        }

        return [
            'Status' => Success(),
            'FeesOk' => $sync['feesOk'],
            'LinksOk' => $sync['linksOk'],
        ];
    }

    public function SetCalendarDetailEventType($request)
    {
        $mundaneId = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $eventId = (int) ($request['EventId'] ?? 0);
        $detailId = (int) ($request['EventCalendarDetailId'] ?? 0);
        $eventType = trim((string) ($request['EventType'] ?? ''));
        if ($mundaneId <= 0) {
            return BadToken();
        }
        if (!valid_id($detailId) || !valid_id($eventId)) {
            return InvalidParameter('Invalid detail id');
        }
        if (!$this->detailBelongsToEvent($detailId, $eventId)) {
            return InvalidParameter('Detail does not belong to event');
        }
        if (!$this->CanManageEventDetail($mundaneId, $eventId, $detailId, 'manage')
            && !Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_EVENT, $eventId, AUTH_EDIT)) {
            return NoAuthorization();
        }
        if ($eventType !== '' && !in_array($eventType, self::CALENDAR_DETAIL_EVENT_TYPES, true)) {
            return InvalidParameter('Invalid event type');
        }

        $typeSql = ($eventType === '') ? 'NULL' : "'" . $this->sq($eventType) . "'";
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'event_calendardetail SET event_type = ' . $typeSql
            . ' WHERE event_calendardetail_id = ' . $detailId
        );

        Ork3::$Lib->ghettocache->bust('SearchService.CalendarDetail', Ork3::$Lib->ghettocache->key([$detailId]));

        return ['Status' => Success()];
    }

    public function ReconcilePastAttendance($request)
    {
        $token = (string) ($request['Token'] ?? '');
        $eventId = (int) ($request['EventId'] ?? 0);
        $detailId = (int) ($request['EventCalendarDetailId'] ?? 0);
        $mundaneId = Ork3::$Lib->authorization->IsAuthorized($token);

        if ($mundaneId <= 0) {
            return BadToken();
        }
        if (!valid_id($eventId) || !valid_id($detailId)) {
            return InvalidParameter('Invalid event or detail id');
        }
        if (!$this->detailBelongsToEvent($detailId, $eventId)) {
            return InvalidParameter('Detail does not belong to event');
        }
        if (!Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_EVENT, $eventId, AUTH_CREATE)) {
            return NoAuthorization();
        }

        $today = date('Y-m-d');
        $this->db->Clear();
        $pastCountRow = $this->db->DataSet(
            'SELECT COUNT(*) AS cnt FROM ' . DB_PREFIX . 'attendance
             WHERE event_calendardetail_id = ' . $detailId . " AND date < '" . $today . "' LIMIT 1"
        );
        $pastCount = ($pastCountRow && $pastCountRow->Next()) ? (int) $pastCountRow->cnt : 0;
        if ($pastCount <= 0) {
            return [
                'Status' => InvalidParameter('No past-dated attendance found to reconcile'),
                'Detail' => '',
            ];
        }

        $this->db->Clear();
        $cdRow = $this->db->DataSet(
            'SELECT at_park_id, price, description, url, url_name, address, province, postal_code, city, country, map_url, map_url_name
             FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_calendardetail_id = ' . $detailId . ' LIMIT 1'
        );
        if (!$cdRow || !$cdRow->Next()) {
            return InvalidParameter('Occurrence not found');
        }

        $this->db->Clear();
        $dateRows = $this->db->DataSet(
            'SELECT date FROM ' . DB_PREFIX . 'attendance
             WHERE event_calendardetail_id = ' . $detailId . " AND date < '" . $today . "'"
        );
        $dates = [];
        while ($dateRows && $dateRows->Next()) {
            $dates[] = strtotime((string) $dateRows->date);
        }
        if ($dates === []) {
            return [
                'Status' => InvalidParameter('No past-dated attendance found to reconcile'),
                'Detail' => '',
            ];
        }
        $minDate = date('Y-m-d', min($dates)) . ' 12:00:00';
        $maxDate = date('Y-m-d', max($dates)) . ' 18:00:00';
        $atParkId = (int) ($cdRow->at_park_id ?? 0);

        $eventApi = new Event();
        $r = $eventApi->CreateEventDetails([
            'Token' => $token,
            'EventId' => $eventId,
            'AtParkId' => valid_id($atParkId) ? $atParkId : null,
            'Current' => 0,
            'Price' => $cdRow->price ?? '',
            'EventStart' => $minDate,
            'EventEnd' => $maxDate,
            'Description' => $cdRow->description ?? '',
            'Url' => $cdRow->url ?? '',
            'UrlName' => $cdRow->url_name ?? '',
            'Address' => $cdRow->address ?? '',
            'Province' => $cdRow->province ?? '',
            'PostalCode' => $cdRow->postal_code ?? '',
            'City' => $cdRow->city ?? '',
            'Country' => $cdRow->country ?? '',
            'MapUrl' => $cdRow->map_url ?? '',
            'MapUrlName' => $cdRow->map_url_name ?? '',
        ]);
        if (($r['Status'] ?? 1) != 0) {
            return $r;
        }

        $newDetailId = (int) ($r['Detail'] ?? 0);
        if (!$newDetailId) {
            $fresh = $eventApi->GetEventDetails(['EventId' => $eventId]);
            $all = $fresh['CalendarEventDetails'] ?? [];
            if ($all) {
                $newDetailId = max(array_map('intval', array_column($all, 'EventCalendarDetailId')));
            }
        }
        if (!$newDetailId) {
            return InvalidParameter('Reconciliation failed: could not determine the new occurrence ID.');
        }

        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'attendance SET event_calendardetail_id = ' . $newDetailId
            . ' WHERE event_calendardetail_id = ' . $detailId . " AND date < '" . $today . "'"
        );
        $this->db->Clear();
        $this->db->Execute(
            'UPDATE ' . DB_PREFIX . 'attendance_myisam SET event_calendardetail_id = ' . $newDetailId
            . ' WHERE event_calendardetail_id = ' . $detailId . " AND date < '" . $today . "'"
        );

        $oldKey = Ork3::$Lib->ghettocache->key([$detailId]);
        $newKey = Ork3::$Lib->ghettocache->key([$newDetailId]);
        Ork3::$Lib->ghettocache->bust_event_search($eventId);
        Ork3::$Lib->ghettocache->bust('SearchService.CalendarDetail', $oldKey);
        Ork3::$Lib->ghettocache->bust('SearchService.CalendarDetail', $newKey);

        return [
            'Status' => Success(),
            'NewEventCalendarDetailId' => $newDetailId,
        ];
    }

    public function GetDietarySummaryForOccurrence($request)
    {
        $detailId = (int) ($request['EventCalendarDetailId'] ?? 0);
        if ($detailId <= 0) {
            return [
                'Status' => Success(),
                'Items' => [],
            ];
        }

        return [
            'Status' => Success(),
            'Items' => $this->fetchDietarySummary($detailId),
        ];
    }

    public function GetDetailDependencyCounts($request)
    {
        $detailId = (int) ($request['EventCalendarDetailId'] ?? 0);
        if (!valid_id($detailId)) {
            return InvalidParameter('Invalid detail id');
        }

        $this->db->Clear();
        $checkAtt = $this->db->DataSet(
            'SELECT COUNT(*) AS cnt FROM ' . DB_PREFIX . 'attendance WHERE event_calendardetail_id = '
            . $detailId . ' LIMIT 1'
        );
        $attCnt = ($checkAtt && $checkAtt->Next()) ? (int) $checkAtt->cnt : 0;
        $this->db->Clear();
        $checkRsvp = $this->db->DataSet(
            'SELECT COUNT(*) AS cnt FROM ' . DB_PREFIX . 'event_rsvp WHERE event_calendardetail_id = '
            . $detailId . ' LIMIT 1'
        );
        $rsvpCnt = ($checkRsvp && $checkRsvp->Next()) ? (int) $checkRsvp->cnt : 0;

        return [
            'Status' => Success(),
            'AttendanceCount' => $attCnt,
            'RsvpCount' => $rsvpCnt,
        ];
    }

    public function GetEventRedirectScope($request)
    {
        $eventId = (int) ($request['EventId'] ?? 0);
        if (!valid_id($eventId)) {
            return InvalidParameter('Invalid event id');
        }

        $this->db->Clear();
        $evRow = $this->db->DataSet(
            'SELECT kingdom_id, park_id FROM ' . DB_PREFIX . 'event WHERE event_id = ' . $eventId . ' LIMIT 1'
        );
        $kingdomId = 0;
        $parkId = 0;
        if ($evRow && $evRow->Next()) {
            $kingdomId = (int) $evRow->kingdom_id;
            $parkId = (int) $evRow->park_id;
        }

        return [
            'Status' => Success(),
            'KingdomId' => $kingdomId,
            'ParkId' => $parkId,
        ];
    }

    public function GetParkName($request)
    {
        $parkId = (int) ($request['ParkId'] ?? 0);
        if (!valid_id($parkId)) {
            return [
                'Status' => Success(),
                'Name' => '',
            ];
        }

        $this->db->Clear();
        $row = $this->db->DataSet(
            'SELECT name FROM ' . DB_PREFIX . 'park WHERE park_id = ' . $parkId . ' LIMIT 1'
        );

        return [
            'Status' => Success(),
            'Name' => ($row && $row->Next()) ? (string) $row->name : '',
        ];
    }

    public function IsDraftBlockedForViewer($request)
    {
        $eventStatus = (string) ($request['EventStatus'] ?? 'published');
        $creatorId = (int) ($request['CreatorId'] ?? 0);
        $mundaneId = (int) ($request['MundaneId'] ?? 0);
        $canManage = !empty($request['CanManageEvent']);
        $staffCaps = is_array($request['StaffCaps'] ?? null) ? $request['StaffCaps'] : [];

        if ($eventStatus === 'published' || $canManage) {
            return ['Status' => Success(), 'Blocked' => false];
        }
        if ($mundaneId === $creatorId) {
            return ['Status' => Success(), 'Blocked' => false];
        }
        if ($mundaneId > 0 && Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_ADMIN, 0, AUTH_CREATE)) {
            return ['Status' => Success(), 'Blocked' => false];
        }
        foreach (['CanManage', 'CanAttendance', 'CanSchedule', 'CanFeast'] as $cap) {
            if (!empty($staffCaps[$cap])) {
                return ['Status' => Success(), 'Blocked' => false];
            }
        }

        return ['Status' => Success(), 'Blocked' => true];
    }

    private function resolveDefaultOccurrenceId(int $eventId): int
    {
        $this->db->Clear();
        $cdRow = $this->db->DataSet(
            'SELECT event_calendardetail_id FROM ' . DB_PREFIX . 'event_calendardetail
             WHERE event_id = ' . $eventId . ' AND event_start >= NOW()
             ORDER BY event_start ASC LIMIT 1'
        );
        if (!$cdRow || !$cdRow->Next()) {
            $this->db->Clear();
            $cdRow = $this->db->DataSet(
                'SELECT event_calendardetail_id FROM ' . DB_PREFIX . 'event_calendardetail
                 WHERE event_id = ' . $eventId . ' ORDER BY event_start DESC LIMIT 1'
            );
            if ($cdRow) {
                $cdRow->Next();
            }
        }

        return ($cdRow && isset($cdRow->event_calendardetail_id))
            ? (int) $cdRow->event_calendardetail_id
            : 0;
    }

    private function detailBelongsToEvent(int $detailId, int $eventId): bool
    {
        $this->db->Clear();
        $ownRow = $this->db->DataSet(
            'SELECT event_id FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_calendardetail_id = '
            . $detailId . ' LIMIT 1'
        );

        return (bool) ($ownRow && $ownRow->Next() && (int) $ownRow->event_id === $eventId);
    }

    /** @return array{CanManage: bool, CanAttendance: bool, CanSchedule: bool, CanFeast: bool} */
    private function fetchSelfStaffCaps(int $detailId, int $mundaneId): array
    {
        $caps = [
            'CanManage' => false,
            'CanAttendance' => false,
            'CanSchedule' => false,
            'CanFeast' => false,
        ];
        if ($mundaneId <= 0 || !valid_id($detailId)) {
            return $caps;
        }

        $this->db->Clear();
        $selfStaff = $this->db->DataSet(
            'SELECT can_manage, can_attendance, can_schedule, can_feast FROM ' . DB_PREFIX . 'event_staff
             WHERE event_calendardetail_id = ' . $detailId . ' AND mundane_id = ' . $mundaneId . ' LIMIT 1'
        );
        if ($selfStaff && $selfStaff->Next()) {
            $caps['CanManage'] = (bool) (int) $selfStaff->can_manage;
            $caps['CanAttendance'] = (bool) (int) $selfStaff->can_attendance;
            $caps['CanSchedule'] = (bool) (int) $selfStaff->can_schedule;
            $caps['CanFeast'] = (bool) (int) $selfStaff->can_feast;
        }

        return $caps;
    }

    /** @return array{Name: string, Address: string, City: string, Province: string, PostalCode: string, Location: string} */
    private function fetchAtParkDisplay(int $parkId): array
    {
        $empty = [
            'Name' => '', 'Address' => '', 'City' => '', 'Province' => '', 'PostalCode' => '', 'Location' => '',
        ];
        if ($parkId <= 0) {
            return $empty;
        }

        $this->db->Clear();
        $row = $this->db->DataSet(
            'SELECT name, address, city, province, postal_code, location FROM ' . DB_PREFIX . 'park
             WHERE park_id = ' . $parkId . ' LIMIT 1'
        );
        if (!$row || !$row->Next()) {
            return $empty;
        }

        return [
            'Name' => (string) $row->name,
            'Address' => (string) $row->address,
            'City' => (string) $row->city,
            'Province' => (string) $row->province,
            'PostalCode' => (string) $row->postal_code,
            'Location' => (string) $row->location,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function fetchStaffList(int $detailId): array
    {
        $this->db->Clear();
        $staffRows = $this->db->DataSet(
            'SELECT s.event_staff_id AS EventStaffId, s.mundane_id AS MundaneId, m.persona AS Persona,
                    s.role_name AS RoleName, s.can_manage AS CanManage, s.can_attendance AS CanAttendance,
                    s.can_schedule AS CanSchedule, s.can_feast AS CanFeast
             FROM ' . DB_PREFIX . 'event_staff s
             LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = s.mundane_id
             WHERE s.event_calendardetail_id = ' . $detailId . '
             ORDER BY s.role_name, m.persona'
        );
        $staffList = [];
        while ($staffRows && $staffRows->Next()) {
            $staffList[] = [
                'EventStaffId' => (int) $staffRows->EventStaffId,
                'MundaneId' => (int) $staffRows->MundaneId,
                'Persona' => $staffRows->Persona,
                'RoleName' => $staffRows->RoleName,
                'CanManage' => (int) $staffRows->CanManage,
                'CanAttendance' => (int) $staffRows->CanAttendance,
                'CanSchedule' => (int) $staffRows->CanSchedule,
                'CanFeast' => (int) $staffRows->CanFeast,
            ];
        }

        return $staffList;
    }

    /** @return list<array<string, mixed>> */
    private function fetchScheduleList(int $detailId): array
    {
        $this->db->Clear();
        $scheduleRows = $this->db->DataSet(
            'SELECT event_schedule_id AS EventScheduleId, title AS Title,
                    start_time AS StartTime, end_time AS EndTime,
                    location AS Location, description AS Description, category AS Category,
                    secondary_category AS SecondaryCategory,
                    menu AS Menu, cost AS Cost, dietary AS Dietary, allergens AS Allergens
             FROM ' . DB_PREFIX . 'event_schedule
             WHERE event_calendardetail_id = ' . $detailId . '
             ORDER BY start_time'
        );
        $scheduleList = [];
        while ($scheduleRows && $scheduleRows->Next()) {
            $scheduleList[] = [
                'EventScheduleId' => (int) $scheduleRows->EventScheduleId,
                'Title' => $scheduleRows->Title,
                'StartTime' => $scheduleRows->StartTime,
                'EndTime' => $scheduleRows->EndTime,
                'Location' => $scheduleRows->Location,
                'Description' => $scheduleRows->Description,
                'Category' => $scheduleRows->Category,
                'SecondaryCategory' => $scheduleRows->SecondaryCategory ?? '',
                'Menu' => $scheduleRows->Menu,
                'Cost' => $scheduleRows->Cost !== null ? (float) $scheduleRows->Cost : null,
                'Dietary' => $scheduleRows->Dietary,
                'Allergens' => $scheduleRows->Allergens,
            ];
        }

        if ($scheduleList !== []) {
            $slIds = implode(',', array_map('intval', array_column($scheduleList, 'EventScheduleId')));
            $this->db->Clear();
            $leadRows = $this->db->DataSet(
                'SELECT sl.event_schedule_id AS EventScheduleId, m.mundane_id AS MundaneId, m.persona AS Persona
                 FROM ' . DB_PREFIX . 'event_schedule_lead sl
                 JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = sl.mundane_id
                 WHERE sl.event_schedule_id IN (' . $slIds . ')
                 ORDER BY m.persona'
            );
            $leadsMap = [];
            while ($leadRows && $leadRows->Next()) {
                $leadsMap[(int) $leadRows->EventScheduleId][] = [
                    'MundaneId' => (int) $leadRows->MundaneId,
                    'Persona' => $leadRows->Persona,
                ];
            }
            foreach ($scheduleList as &$schItem) {
                $schItem['Leads'] = $leadsMap[(int) $schItem['EventScheduleId']] ?? [];
            }
            unset($schItem);
        }

        return $scheduleList;
    }

    /** @return list<array<string, mixed>> */
    private function fetchFeeList(int $detailId): array
    {
        $this->db->Clear();
        $feeRows = $this->db->DataSet(
            'SELECT event_fees_id AS EventFeesId, admission_type AS AdmissionType, cost AS Cost, sort_order AS SortOrder
             FROM ' . DB_PREFIX . 'event_fees
             WHERE event_calendardetail_id = ' . $detailId . '
             ORDER BY sort_order, event_fees_id'
        );
        $feeList = [];
        while ($feeRows && $feeRows->Next()) {
            $feeList[] = [
                'EventFeesId' => (int) $feeRows->EventFeesId,
                'AdmissionType' => $feeRows->AdmissionType,
                'Cost' => (float) $feeRows->Cost,
                'SortOrder' => (int) $feeRows->SortOrder,
            ];
        }

        return $feeList;
    }

    /** @return list<array<string, mixed>> */
    private function fetchLinkList(int $detailId): array
    {
        $this->db->Clear();
        $linkRows = $this->db->DataSet(
            'SELECT event_links_id AS EventLinkId, title AS Title, url AS Url, icon AS Icon, sort_order AS SortOrder
             FROM ' . DB_PREFIX . 'event_links
             WHERE event_calendardetail_id = ' . $detailId . '
             ORDER BY sort_order, event_links_id'
        );
        $linkList = [];
        while ($linkRows && $linkRows->Next()) {
            $linkList[] = [
                'EventLinkId' => (int) $linkRows->EventLinkId,
                'Title' => $linkRows->Title,
                'Url' => $linkRows->Url,
                'Icon' => $linkRows->Icon,
                'SortOrder' => (int) $linkRows->SortOrder,
            ];
        }

        return $linkList;
    }

    /** @return list<array<string, mixed>> */
    private function fetchDietarySummary(int $detailId): array
    {
        if ($detailId <= 0) {
            return [];
        }

        $this->db->Clear();
        $dsRows = $this->db->DataSet(
            'SELECT m.mundane_id AS MundaneId, m.persona AS Persona,
                    IF(a.mundane_id IS NOT NULL, 1, 0) AS CheckedIn,
                    d.is_anonymous, d.no_restrictions, d.diet_vegetarian, d.diet_vegan, d.diet_halal, d.diet_kosher,
                    d.diet_keto, d.diet_paleo, d.restrict_dairy, d.restrict_eggs, d.restrict_fish, d.restrict_honey,
                    d.restrict_poultry, d.restrict_beef, d.restrict_pork, d.restrict_shellfish,
                    d.allergen_milk, d.allergen_eggs, d.allergen_fish, d.allergen_shellfish, d.allergen_treenuts,
                    d.allergen_peanuts, d.allergen_wheat, d.allergen_soy, d.allergen_sesame, d.allergen_garlic,
                    d.allergen_gluten, d.allergen_onion, d.allergen_mushroom, d.allergen_corn, d.allergen_coconut,
                    d.allergen_cocoa
             FROM (
                 SELECT mundane_id FROM ' . DB_PREFIX . "event_rsvp
                 WHERE event_calendardetail_id = {$detailId} AND status = 'going'
                 UNION
                 SELECT mundane_id FROM " . DB_PREFIX . "attendance
                 WHERE event_calendardetail_id = {$detailId}
             ) src
             JOIN " . DB_PREFIX . 'mundane m ON m.mundane_id = src.mundane_id
             LEFT JOIN ' . DB_PREFIX . 'mundane_dietary d ON d.mundane_id = src.mundane_id
             LEFT JOIN (SELECT DISTINCT mundane_id FROM ' . DB_PREFIX . "attendance WHERE event_calendardetail_id = {$detailId}) a
                 ON a.mundane_id = src.mundane_id
             ORDER BY m.persona"
        );
        $dsList = [];
        while ($dsRows && $dsRows->Next()) {
            $dsList[] = [
                'MundaneId' => (int) $dsRows->MundaneId,
                'Persona' => (string) $dsRows->Persona,
                'CheckedIn' => (bool) (int) $dsRows->CheckedIn,
                'HasPrefs' => $dsRows->is_anonymous !== null,
                'NoRestrictions' => (bool) (int) ($dsRows->no_restrictions ?? 0),
                'IsAnonymous' => (int) ($dsRows->is_anonymous ?? 1),
                'DietVegetarian' => (int) ($dsRows->diet_vegetarian ?? 0),
                'DietVegan' => (int) ($dsRows->diet_vegan ?? 0),
                'DietHalal' => (int) ($dsRows->diet_halal ?? 0),
                'DietKosher' => (int) ($dsRows->diet_kosher ?? 0),
                'DietKeto' => (int) ($dsRows->diet_keto ?? 0),
                'DietPaleo' => (int) ($dsRows->diet_paleo ?? 0),
                'RestrictDairy' => (int) ($dsRows->restrict_dairy ?? 0),
                'RestrictEggs' => (int) ($dsRows->restrict_eggs ?? 0),
                'RestrictFish' => (int) ($dsRows->restrict_fish ?? 0),
                'RestrictHoney' => (int) ($dsRows->restrict_honey ?? 0),
                'RestrictPoultry' => (int) ($dsRows->restrict_poultry ?? 0),
                'RestrictBeef' => (int) ($dsRows->restrict_beef ?? 0),
                'RestrictPork' => (int) ($dsRows->restrict_pork ?? 0),
                'RestrictShellfish' => (int) ($dsRows->restrict_shellfish ?? 0),
                'AllergenMilk' => (int) ($dsRows->allergen_milk ?? 0),
                'AllergenEggs' => (int) ($dsRows->allergen_eggs ?? 0),
                'AllergenFish' => (int) ($dsRows->allergen_fish ?? 0),
                'AllergenShellfish' => (int) ($dsRows->allergen_shellfish ?? 0),
                'AllergenTreenuts' => (int) ($dsRows->allergen_treenuts ?? 0),
                'AllergenPeanuts' => (int) ($dsRows->allergen_peanuts ?? 0),
                'AllergenWheat' => (int) ($dsRows->allergen_wheat ?? 0),
                'AllergenSoy' => (int) ($dsRows->allergen_soy ?? 0),
                'AllergenSesame' => (int) ($dsRows->allergen_sesame ?? 0),
                'AllergenGarlic' => (int) ($dsRows->allergen_garlic ?? 0),
                'AllergenGluten' => (int) ($dsRows->allergen_gluten ?? 0),
                'AllergenOnion' => (int) ($dsRows->allergen_onion ?? 0),
                'AllergenMushroom' => (int) ($dsRows->allergen_mushroom ?? 0),
                'AllergenCorn' => (int) ($dsRows->allergen_corn ?? 0),
                'AllergenCoconut' => (int) ($dsRows->allergen_coconut ?? 0),
                'AllergenCocoa' => (int) ($dsRows->allergen_cocoa ?? 0),
            ];
        }

        return $dsList;
    }

    /**
     * @param list<array{AdmissionType?: string, Cost?: float|int}> $feesIn
     * @param list<array{Title?: string, Url?: string, Icon?: string}> $linksIn
     *
     * @return array{feesOk: bool, linksOk: bool}
     */
    private function syncCalendarDetailFeesAndLinks(int $detailId, array $feesIn, array $linksIn): array
    {
        $this->db->Clear();
        $this->db->Execute('START TRANSACTION');
        $feesOk = ($this->db->Execute(
            'DELETE FROM ' . DB_PREFIX . 'event_fees WHERE event_calendardetail_id = ' . $detailId
        ) !== false);
        if ($feesOk) {
            foreach ($feesIn as $fi => $fee) {
                $at = trim((string) ($fee['AdmissionType'] ?? ''));
                $cost = round((float) ($fee['Cost'] ?? 0), 2);
                $this->db->Clear();
                if ($this->db->Execute(
                    'INSERT INTO ' . DB_PREFIX . 'event_fees (event_calendardetail_id, admission_type, cost, sort_order) VALUES ('
                    . $detailId . ", '" . $this->sq($at) . "', " . $cost . ', ' . $fi . ')'
                ) === false) {
                    $feesOk = false;
                    break;
                }
            }
        }
        $this->db->Execute($feesOk ? 'COMMIT' : 'ROLLBACK');

        $this->db->Clear();
        $this->db->Execute('START TRANSACTION');
        $linksOk = ($this->db->Execute(
            'DELETE FROM ' . DB_PREFIX . 'event_links WHERE event_calendardetail_id = ' . $detailId
        ) !== false);
        if ($linksOk) {
            foreach ($linksIn as $li => $link) {
                $lt = trim((string) ($link['Title'] ?? ''));
                $luRaw = trim((string) ($link['Url'] ?? ''));
                if ($luRaw !== '') {
                    $scheme = strtolower((string) parse_url($luRaw, PHP_URL_SCHEME));
                    if (!in_array($scheme, ['http', 'https', 'mailto'], true)) {
                        $luRaw = '';
                    }
                }
                $icRaw = trim((string) ($link['Icon'] ?? ''));
                if (!in_array($icRaw, self::ALLOWED_LINK_ICONS, true)) {
                    $icRaw = 'fas fa-link';
                }
                $this->db->Clear();
                if ($this->db->Execute(
                    'INSERT INTO ' . DB_PREFIX . 'event_links (event_calendardetail_id, title, url, icon, sort_order) VALUES ('
                    . $detailId . ", '" . $this->sq($lt) . "', '" . $this->sq($luRaw) . "', '"
                    . $this->sq($icRaw) . "', " . $li . ')'
                ) === false) {
                    $linksOk = false;
                    break;
                }
            }
        }
        $this->db->Execute($linksOk ? 'COMMIT' : 'ROLLBACK');

        return ['feesOk' => $feesOk, 'linksOk' => $linksOk];
    }

    private function bustCalendarDetailCaches(int $eventId, int $detailId): void
    {
        Ork3::$Lib->ghettocache->bust('SearchService.CalendarDetail', Ork3::$Lib->ghettocache->key([$detailId]));
        Ork3::$Lib->ghettocache->bust_event_search($eventId);
    }

    public function CanAddAttendance(int $mundaneId, int $eventId, int $detailId): bool
    {
        return $this->CanManageEventDetail($mundaneId, $eventId, $detailId, 'attendance');
    }

    public function CanRemoveRsvp(int $mundaneId, int $eventId, int $detailId): bool
    {
        if (Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_EVENT, $eventId, AUTH_EDIT)) {
            return true;
        }
        if ($detailId <= 0) {
            return false;
        }
        $this->db->Clear();
        $row = $this->db->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . 'event_staff
             WHERE event_calendardetail_id = ' . $detailId
            . ' AND mundane_id = ' . $mundaneId . ' AND can_attendance = 1 LIMIT 1'
        );

        return (bool) ($row && $row->Next());
    }

    public function GetAttendanceDisplayRow(int $attendanceId): ?array
    {
        if (!valid_id($attendanceId)) {
            return null;
        }
        $this->db->Clear();
        $row = $this->db->DataSet(
            "SELECT a.attendance_id AS AttendanceId, a.mundane_id AS MundaneId, m.persona AS Persona,
                    m.kingdom_id AS KingdomId, k.name AS KingdomName, k.abbreviation AS KAbbr,
                    m.park_id AS ParkId, p.name AS ParkName, p.abbreviation AS PAbbr,
                    c.name AS ClassName, a.credits AS Credits
             FROM " . DB_PREFIX . "attendance a
             LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = a.mundane_id
             LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = m.park_id
             LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = m.kingdom_id
             LEFT JOIN " . DB_PREFIX . "class c ON c.class_id = a.class_id
             WHERE a.attendance_id = " . $attendanceId
        );
        if (!$row || !$row->Next()) {
            return null;
        }

        return [
            'AttendanceId' => $row->AttendanceId,
            'MundaneId' => $row->MundaneId,
            'Persona' => $row->Persona,
            'KingdomId' => $row->KingdomId,
            'KingdomName' => $row->KingdomName,
            'KAbbr' => $row->KAbbr,
            'ParkId' => $row->ParkId,
            'ParkName' => $row->ParkName,
            'PAbbr' => $row->PAbbr,
            'ClassName' => $row->ClassName,
            'Credits' => $row->Credits,
        ];
    }

    public function AddEventStaff($request)
    {
        $mundaneId = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $eventId = (int) ($request['EventId'] ?? 0);
        $detailId = (int) ($request['EventCalendarDetailId'] ?? 0);

        if ($mundaneId <= 0) {
            return BadToken();
        }
        if (!valid_id($eventId) || !valid_id($detailId)) {
            return InvalidParameter('Invalid Event ID.');
        }
        if (!$this->CanManageEventDetail($mundaneId, $eventId, $detailId, 'create')) {
            return NoAuthorization();
        }

        $targetMundane = (int) ($request['MundaneId'] ?? 0);
        $roleName = trim((string) ($request['RoleName'] ?? ''));
        $canManage = (int) (bool) ($request['CanManage'] ?? 0);
        $canAttendance = (int) (bool) ($request['CanAttendance'] ?? 0);
        $canSchedule = (int) (bool) ($request['CanSchedule'] ?? 0);
        $canFeast = (int) (bool) ($request['CanFeast'] ?? 0);
        $staffIdIn = (int) ($request['StaffId'] ?? 0);

        if (!valid_id($targetMundane)) {
            return InvalidParameter('A player must be selected.');
        }
        if ($roleName === '') {
            return InvalidParameter('A role is required.');
        }

        $priorState = $this->fetchStaffPriorState($detailId, $staffIdIn, $targetMundane);
        $roleSafe = $this->sq($roleName);

        $this->db->Clear();
        if ($staffIdIn > 0 && $priorState) {
            $this->db->Execute(
                'UPDATE ' . DB_PREFIX . 'event_staff
                 SET role_name = \'' . $roleSafe . '\',
                     can_manage = ' . $canManage . ',
                     can_attendance = ' . $canAttendance . ',
                     can_schedule = ' . $canSchedule . ',
                     can_feast = ' . $canFeast . '
                 WHERE event_staff_id = ' . $staffIdIn . '
                   AND event_calendardetail_id = ' . $detailId
            );
        } else {
            $this->db->Execute(
                'INSERT INTO ' . DB_PREFIX . 'event_staff
                 (event_calendardetail_id, mundane_id, role_name, can_manage, can_attendance, can_schedule, can_feast)
                 VALUES (' . $detailId . ', ' . $targetMundane . ', \'' . $roleSafe . '\', '
                . $canManage . ', ' . $canAttendance . ', ' . $canSchedule . ', ' . $canFeast . ')
                 ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), can_manage = VALUES(can_manage),
                 can_attendance = VALUES(can_attendance), can_schedule = VALUES(can_schedule),
                 can_feast = VALUES(can_feast)'
            );
        }

        $this->db->Clear();
        if ($staffIdIn > 0) {
            $idrow = $this->db->DataSet(
                'SELECT s.event_staff_id, m.persona FROM ' . DB_PREFIX . 'event_staff s
                 LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = s.mundane_id
                 WHERE s.event_staff_id = ' . $staffIdIn . ' LIMIT 1'
            );
        } else {
            $idrow = $this->db->DataSet(
                'SELECT s.event_staff_id, m.persona FROM ' . DB_PREFIX . 'event_staff s
                 LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = s.mundane_id
                 WHERE s.event_calendardetail_id = ' . $detailId . ' AND s.mundane_id = ' . $targetMundane
                . ' ORDER BY s.event_staff_id DESC LIMIT 1'
            );
        }

        $staffId = ($idrow && $idrow->Next()) ? (int) $idrow->event_staff_id : 0;
        $persona = ($idrow && isset($idrow->persona)) ? (string) $idrow->persona : '';

        return [
            'Status' => Success(),
            'PriorState' => $priorState,
            'Staff' => [
                'EventStaffId' => $staffId,
                'MundaneId' => $targetMundane,
                'Persona' => $persona,
                'RoleName' => $roleName,
                'CanManage' => $canManage,
                'CanAttendance' => $canAttendance,
                'CanSchedule' => $canSchedule,
                'CanFeast' => $canFeast,
            ],
        ];
    }

    public function RemoveEventStaff($request)
    {
        $mundaneId = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $eventId = (int) ($request['EventId'] ?? 0);
        $detailId = (int) ($request['EventCalendarDetailId'] ?? 0);
        $staffId = (int) ($request['StaffId'] ?? 0);

        if ($mundaneId <= 0) {
            return BadToken();
        }
        if (!valid_id($eventId) || !valid_id($detailId) || !valid_id($staffId)) {
            return InvalidParameter('Invalid parameters.');
        }
        if (!$this->CanManageEventDetail($mundaneId, $eventId, $detailId, 'create')) {
            return NoAuthorization();
        }

        $priorState = $this->fetchStaffPriorStateById($staffId, $detailId);

        $this->db->Clear();
        $this->db->Execute(
            'DELETE FROM ' . DB_PREFIX . 'event_staff
             WHERE event_staff_id = ' . $staffId . ' AND event_calendardetail_id = ' . $detailId
        );

        return [
            'Status' => Success(),
            'PriorState' => $priorState,
        ];
    }

    public function AddEventSchedule($request)
    {
        return $this->saveEventSchedule($request, false);
    }

    public function UpdateEventSchedule($request)
    {
        return $this->saveEventSchedule($request, true);
    }

    public function RemoveEventSchedule($request)
    {
        $mundaneId = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $eventId = (int) ($request['EventId'] ?? 0);
        $detailId = (int) ($request['EventCalendarDetailId'] ?? 0);
        $scheduleId = (int) ($request['ScheduleId'] ?? 0);

        if ($mundaneId <= 0) {
            return BadToken();
        }
        if (!valid_id($eventId) || !valid_id($detailId) || !valid_id($scheduleId)) {
            return InvalidParameter('Invalid parameters.');
        }

        if (!Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_EVENT, $eventId, AUTH_EDIT)) {
            $this->db->Clear();
            $catRow = $this->db->DataSet(
                'SELECT category, secondary_category FROM ' . DB_PREFIX . 'event_schedule
                 WHERE event_schedule_id = ' . $scheduleId . ' AND event_calendardetail_id = ' . $detailId . ' LIMIT 1'
            );
            $isFeast = false;
            if ($catRow && $catRow->Next()) {
                $cat = (string) ($catRow->category ?? '');
                $scat = (string) ($catRow->secondary_category ?? '');
                $isFeast = ($cat === 'Feast and Food' || $scat === 'Feast and Food');
            }
            $cap = $isFeast ? 'feast' : 'schedule';
            if (!$this->CanManageEventDetail($mundaneId, $eventId, $detailId, $cap)) {
                return NoAuthorization();
            }
        }

        $this->db->Clear();
        $this->db->Execute(
            'DELETE FROM ' . DB_PREFIX . 'event_schedule
             WHERE event_schedule_id = ' . $scheduleId . ' AND event_calendardetail_id = ' . $detailId
        );

        return Success();
    }

    public function ListCopySourceEvents($request)
    {
        $kingdomId = (int) ($request['KingdomId'] ?? 0);
        $parkId = (int) ($request['ParkId'] ?? 0);
        $query = trim((string) ($request['Query'] ?? ''));
        $exclude = (int) ($request['ExcludeEventId'] ?? 0);

        if (!valid_id($kingdomId) && !valid_id($parkId)) {
            return InvalidParameter('A kingdom or park is required.');
        }

        if (valid_id($parkId)) {
            $scopeWhere = 'e.park_id = ' . $parkId;
        } else {
            $scopeWhere = 'e.kingdom_id = ' . $kingdomId . ' AND (e.park_id IS NULL OR e.park_id = 0)';
        }

        $nameWhere = '';
        if ($query !== '') {
            $safe = str_replace(['\\', '%', '_', "'"], ['\\\\', '\\%', '\\_', "''"], $query);
            $nameWhere = " AND e.name LIKE '%" . $safe . "%'";
        }
        $excludeWhere = $exclude > 0 ? ' AND e.event_id != ' . $exclude : '';

        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT e.event_id, e.name,
                    MAX(cd.event_start) AS last_start,
                    MAX(cd.event_end) AS last_end,
                    COUNT(cd.event_calendardetail_id) AS occ_count
             FROM ' . DB_PREFIX . 'event e
             JOIN ' . DB_PREFIX . 'event_calendardetail cd
               ON cd.event_id = e.event_id AND cd.event_start < NOW()
             WHERE ' . $scopeWhere . $excludeWhere . "
               AND e.status = 'published'" . $nameWhere . '
             GROUP BY e.event_id
             HAVING last_start IS NOT NULL
             ORDER BY last_start DESC
             LIMIT 25'
        );

        $results = [];
        while ($rs && $rs->Next()) {
            $results[] = [
                'eventId' => (int) $rs->event_id,
                'name' => (string) $rs->name,
                'lastStart' => (string) $rs->last_start,
                'lastEnd' => (string) $rs->last_end,
                'occurrenceCount' => (int) $rs->occ_count,
            ];
        }

        return [
            'Status' => Success(),
            'Results' => $results,
        ];
    }

    public function CreateEventWithCopy($request)
    {
        $token = (string) ($request['Token'] ?? '');
        $name = trim((string) ($request['Name'] ?? ''));
        $kingdomId = (int) ($request['KingdomId'] ?? 0);
        $parkId = (int) ($request['ParkId'] ?? 0);
        $srcEvtId = (int) ($request['SourceEventId'] ?? 0);
        $newStart = trim((string) ($request['NewStart'] ?? ''));
        $newEnd = trim((string) ($request['NewEnd'] ?? ''));
        $modules = is_array($request['Modules'] ?? null) ? $request['Modules'] : [];
        $statusIn = (string) ($request['Status'] ?? 'published');

        $mod = [
            'details' => !empty($modules['details']),
            'schedule' => !empty($modules['schedule']),
            'staff' => !empty($modules['staff']),
            'feast' => !empty($modules['feast']),
            'banner' => !empty($modules['banner']),
        ];

        if ($name === '') {
            return InvalidParameter('Event name is required.');
        }
        if (!valid_id($kingdomId) && !valid_id($parkId)) {
            return InvalidParameter('A kingdom or park is required.');
        }
        if (!valid_id($srcEvtId)) {
            return InvalidParameter('A source event is required.');
        }

        $nsTs = strtotime($newStart);
        $neTs = strtotime($newEnd);
        if (!$nsTs || !$neTs) {
            return InvalidParameter('Valid start and end times are required.');
        }
        if ($neTs < $nsTs) {
            return InvalidParameter('End time cannot be before start time.');
        }

        $this->db->Clear();
        $srcRow = $this->db->DataSet(
            'SELECT event_id, name, kingdom_id, park_id FROM ' . DB_PREFIX . 'event
             WHERE event_id = ' . $srcEvtId . ' LIMIT 1'
        );
        if (!$srcRow || !$srcRow->Next()) {
            return InvalidParameter('Source event not found.');
        }
        $src = $srcRow;

        if (valid_id($parkId)) {
            if ((int) $src->park_id !== $parkId) {
                return NoAuthorization('Source event is not available in this scope.');
            }
        } elseif ((int) $src->kingdom_id !== $kingdomId || ((int) $src->park_id !== 0 && $src->park_id !== null)) {
            return NoAuthorization('Source event is not available in this scope.');
        }

        $this->db->Clear();
        $srcDetail = $this->db->DataSet(
            'SELECT * FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_id = ' . $srcEvtId
            . ' ORDER BY event_start DESC LIMIT 1'
        );
        if (!$srcDetail || !$srcDetail->Next()) {
            return InvalidParameter('Selected event has no occurrence data to copy.');
        }
        $sd = $srcDetail;
        $srcDetailId = (int) $sd->event_calendardetail_id;
        $srcStartTs = strtotime((string) $sd->event_start);
        if (!$srcStartTs) {
            return InvalidParameter('Source occurrence has an invalid start time.');
        }
        $deltaSeconds = $nsTs - $srcStartTs;

        $eventDomain = new Event();
        $createReq = [
            'Token' => $token,
            'KingdomId' => $kingdomId,
            'ParkId' => $parkId,
            'MundaneId' => 0,
            'UnitId' => 0,
            'Name' => $name,
            'Status' => $statusIn,
        ];
        $r = $eventDomain->CreateEvent($createReq);
        if (($r['Status'] ?? 1) != 0) {
            return $r;
        }

        $newEventId = (int) ($r['Detail'] ?? 0);
        if ($newEventId <= 0) {
            return ProcessingError('Failed to create event row.');
        }

        $newStartFmt = date('Y-m-d H:i:s', $nsTs);
        $newEndFmt = date('Y-m-d H:i:s', $neTs);
        $atParkSql = valid_id($parkId) ? (string) $parkId : 'NULL';

        $dsc = $mod['details'] ? (string) $sd->description : '';
        $prc = $mod['details'] ? (float) $sd->price : 0;
        $url = $mod['details'] ? (string) $sd->url : '';
        $urln = $mod['details'] ? (string) $sd->url_name : '';
        $adr = $mod['details'] ? (string) $sd->address : '';
        $prv = $mod['details'] ? (string) $sd->province : '';
        $pst = $mod['details'] ? (string) $sd->postal_code : '';
        $cty = $mod['details'] ? (string) $sd->city : '';
        $cnt = $mod['details'] ? (string) $sd->country : '';
        $mur = $mod['details'] ? (string) $sd->map_url : '';
        $murn = $mod['details'] ? (string) $sd->map_url_name : '';
        $etp = $mod['details'] ? (string) $sd->event_type : '';

        foreach (['url' => &$url, 'mur' => &$mur] as $_v) {
            if ($_v !== '') {
                $_sc = strtolower((string) parse_url($_v, PHP_URL_SCHEME));
                if (!in_array($_sc, ['http', 'https', 'mailto'], true)) {
                    $_v = '';
                }
            }
        }
        unset($_v);

        $this->db->Clear();
        $this->db->Execute('UPDATE ' . DB_PREFIX . 'event_calendardetail SET current = 0 WHERE event_id = ' . $newEventId);
        $this->db->Clear();
        $this->db->Execute(
            'INSERT INTO ' . DB_PREFIX . "event_calendardetail
             (event_id, at_park_id, current, price, event_start, event_end, description, url, url_name,
              address, province, postal_code, city, country, map_url, map_url_name, event_type)
             VALUES (" . $newEventId . ', ' . $atParkSql . ', 1, ' . (float) $prc . ", '"
            . $newStartFmt . "', '" . $newEndFmt . "', '" . $this->sq($dsc) . "', '" . $this->sq($url) . "', '"
            . $this->sq($urln) . "', '" . $this->sq($adr) . "', '" . $this->sq($prv) . "', '" . $this->sq($pst)
            . "', '" . $this->sq($cty) . "', '" . $this->sq($cnt) . "', '" . $this->sq($mur) . "', '"
            . $this->sq($murn) . "', '" . $this->sq($etp) . "')"
        );

        $this->db->Clear();
        $ndRow = $this->db->DataSet(
            'SELECT event_calendardetail_id FROM ' . DB_PREFIX . 'event_calendardetail
             WHERE event_id = ' . $newEventId . ' ORDER BY event_calendardetail_id DESC LIMIT 1'
        );
        $newDetailId = ($ndRow && $ndRow->Next()) ? (int) $ndRow->event_calendardetail_id : 0;
        if ($newDetailId <= 0) {
            $this->rollbackCopiedEvent($newEventId);
            return ProcessingError('Failed to create event occurrence.');
        }

        if ($mod['details']) {
            $this->copyDetailFeesAndLinks($srcDetailId, $newDetailId);
        }

        if ($mod['schedule'] || $mod['feast']) {
            $this->copyScheduleModules($srcDetailId, $newDetailId, $mod, $deltaSeconds);
        }

        if ($mod['staff']) {
            $this->copyStaffModule($srcDetailId, $newDetailId);
        }

        $warnings = [];
        if ($mod['banner']) {
            $banner = new Banner();
            $br = $banner->CopyBanner([
                'Token' => $token,
                'Type' => 'Event',
                'SourceId' => $srcEvtId,
                'TargetId' => $newEventId,
            ]);
            if (($br['Status'] ?? 1) != 0) {
                $warnings[] = 'Banner could not be copied.';
            }
        }

        $this->bustEventScopeCaches($newEventId);

        return [
            'Status' => Success(),
            'EventId' => $newEventId,
            'DetailId' => $newDetailId,
            'Warnings' => $warnings,
        ];
    }

    public function ScheduleFeastAllowed(int $mundaneId, int $eventId, int $detailId, string $category): bool
    {
        $isFeast = ($category === 'Feast and Food');
        if (!$isFeast) {
            return $this->CanManageEventDetail($mundaneId, $eventId, $detailId, 'schedule');
        }
        return $this->CanManageEventDetail($mundaneId, $eventId, $detailId, 'feast');
    }

    private function saveEventSchedule($request, bool $isUpdate)
    {
        $mundaneId = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $eventId = (int) ($request['EventId'] ?? 0);
        $detailId = (int) ($request['EventCalendarDetailId'] ?? 0);
        $scheduleId = (int) ($request['ScheduleId'] ?? 0);

        if ($mundaneId <= 0) {
            return BadToken();
        }
        if (!valid_id($eventId) || !valid_id($detailId)) {
            return InvalidParameter('Invalid Event ID.');
        }
        if ($isUpdate && !valid_id($scheduleId)) {
            return InvalidParameter('Invalid parameters.');
        }

        $caps = $this->staffScheduleCaps($mundaneId, $eventId, $detailId);
        if (Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_EVENT, $eventId, AUTH_EDIT)) {
            $caps = ['can_schedule' => true, 'can_feast' => true];
        }

        $title = trim((string) ($request['Title'] ?? ''));
        $startTime = trim((string) ($request['StartTime'] ?? ''));
        $endTime = trim((string) ($request['EndTime'] ?? ''));
        $location = trim((string) ($request['Location'] ?? ''));
        $description = trim((string) ($request['Description'] ?? ''));
        $category = trim((string) ($request['Category'] ?? 'Other'));
        $secondaryCategory = trim((string) ($request['SecondaryCategory'] ?? ''));

        if (!in_array($category, self::SCHEDULE_CATEGORIES, true)) {
            $category = 'Other';
        }
        if ($secondaryCategory !== '' && !in_array($secondaryCategory, self::SCHEDULE_CATEGORIES, true)) {
            $secondaryCategory = '';
        }

        $isFeast = ($category === 'Feast and Food' || $secondaryCategory === 'Feast and Food');
        if ($isFeast) {
            if (!$caps['can_schedule'] && !$caps['can_feast']) {
                return NoAuthorization();
            }
        } elseif (!$caps['can_schedule']) {
            return NoAuthorization();
        }

        if ($title === '') {
            return InvalidParameter('A title is required.');
        }

        $menu = null;
        $cost = null;
        $dietary = null;
        $allergens = null;
        $startFmt = '';
        $endFmt = '';

        if (!$isUpdate || $caps['can_schedule']) {
            if ($startTime === '' || $endTime === '') {
                return InvalidParameter('Start and end times are required.');
            }
            $startTs = strtotime($startTime);
            $endTs = strtotime($endTime);
            if (!$startTs || !$endTs) {
                return InvalidParameter('Invalid time format.');
            }
            if ($endTs < $startTs) {
                return InvalidParameter('End time cannot be before start time.');
            }
            $startFmt = date('Y-m-d H:i:s', $startTs);
            $endFmt = date('Y-m-d H:i:s', $endTs);
        }

        if ($caps['can_feast']) {
            $rawMenu = trim((string) ($request['Menu'] ?? ''));
            $rawCost = trim((string) ($request['Cost'] ?? ''));
            $rawDietary = trim((string) ($request['Dietary'] ?? ''));
            $rawAllergens = trim((string) ($request['Allergens'] ?? ''));
            $menu = ($rawMenu !== '') ? $rawMenu : null;
            $cost = ($rawCost !== '' && is_numeric($rawCost)) ? round((float) $rawCost, 2) : null;
            $dietary = ($rawDietary !== '') ? $rawDietary : null;
            $allergens = ($rawAllergens !== '') ? $rawAllergens : null;
        } elseif ($isUpdate) {
            $this->db->Clear();
            $existRow = $this->db->DataSet(
                'SELECT menu, cost, dietary, allergens FROM ' . DB_PREFIX . 'event_schedule
                 WHERE event_schedule_id = ' . $scheduleId . ' LIMIT 1'
            );
            if (!$existRow || !$existRow->Next()) {
                return InvalidParameter('Schedule item not found.');
            }
            $menu = $existRow->menu;
            $cost = $existRow->cost !== null ? (float) $existRow->cost : null;
            $dietary = $existRow->dietary;
            $allergens = $existRow->allergens;
        }

        if ($isUpdate) {
            $setParts = ['title = \'' . $this->sq($title) . '\''];
            if ($caps['can_schedule']) {
                $setParts[] = 'start_time = \'' . $startFmt . '\'';
                $setParts[] = 'end_time = \'' . $endFmt . '\'';
                $setParts[] = 'location = \'' . $this->sq($location) . '\'';
                $setParts[] = 'description = \'' . $this->sq($description) . '\'';
                $setParts[] = 'category = \'' . $this->sq($category) . '\'';
                $setParts[] = 'secondary_category = \'' . $this->sq($secondaryCategory) . '\'';
            }
            if ($caps['can_feast']) {
                $setParts[] = 'menu = ' . ($menu !== null ? "'" . $this->sq($menu) . "'" : 'NULL');
                $setParts[] = 'cost = ' . ($cost !== null ? (string) $cost : 'NULL');
                $setParts[] = 'dietary = ' . ($dietary !== null ? "'" . $this->sq($dietary) . "'" : 'NULL');
                $setParts[] = 'allergens = ' . ($allergens !== null ? "'" . $this->sq($allergens) . "'" : 'NULL');
            }
            $this->db->Clear();
            $this->db->Execute(
                'UPDATE ' . DB_PREFIX . 'event_schedule SET ' . implode(', ', $setParts)
                . ' WHERE event_schedule_id = ' . $scheduleId . ' AND event_calendardetail_id = ' . $detailId
            );
        } else {
            $menuSql = $menu !== null ? "'" . $this->sq($menu) . "'" : 'NULL';
            $costSql = $cost !== null ? (string) $cost : 'NULL';
            $dietarySql = $dietary !== null ? "'" . $this->sq($dietary) . "'" : 'NULL';
            $allergensSql = $allergens !== null ? "'" . $this->sq($allergens) . "'" : 'NULL';
            $this->db->Clear();
            $this->db->Execute(
                'INSERT INTO ' . DB_PREFIX . 'event_schedule
                 (event_calendardetail_id, title, start_time, end_time, location, description,
                  category, secondary_category, menu, cost, dietary, allergens)
                 VALUES (' . $detailId . ', \'' . $this->sq($title) . '\', \'' . $startFmt . '\', \''
                . $endFmt . '\', \'' . $this->sq($location) . '\', \'' . $this->sq($description) . '\', \''
                . $this->sq($category) . '\', \'' . $this->sq($secondaryCategory) . '\', '
                . $menuSql . ', ' . $costSql . ', ' . $dietarySql . ', ' . $allergensSql . ')'
            );
            $this->db->Clear();
            $idrow = $this->db->DataSet(
                'SELECT event_schedule_id FROM ' . DB_PREFIX . 'event_schedule
                 WHERE event_calendardetail_id = ' . $detailId . ' ORDER BY event_schedule_id DESC LIMIT 1'
            );
            $scheduleId = ($idrow && $idrow->Next()) ? (int) $idrow->event_schedule_id : 0;
        }

        $leadsOut = [];
        $leadsIn = is_array($request['Leads'] ?? null) ? $request['Leads'] : [];
        if (($isUpdate ? $caps['can_schedule'] : true) && is_array($leadsIn)) {
            if ($isUpdate) {
                $this->db->Clear();
                $this->db->Execute(
                    'DELETE FROM ' . DB_PREFIX . 'event_schedule_lead WHERE event_schedule_id = ' . $scheduleId
                );
            }
            foreach ($leadsIn as $lead) {
                $lmid = (int) ($lead['MundaneId'] ?? 0);
                if (!valid_id($lmid)) {
                    continue;
                }
                $this->db->Clear();
                $this->db->Execute(
                    'INSERT IGNORE INTO ' . DB_PREFIX . 'event_schedule_lead (event_schedule_id, mundane_id)
                     VALUES (' . $scheduleId . ', ' . $lmid . ')'
                );
                $leadsOut[] = ['MundaneId' => $lmid, 'Persona' => $lead['Persona'] ?? ''];
            }
        }

        return [
            'Status' => Success(),
            'Schedule' => [
                'EventScheduleId' => $scheduleId,
                'Title' => $title,
                'StartTime' => $startFmt,
                'EndTime' => $endFmt,
                'Location' => $location,
                'Description' => $description,
                'Category' => $category,
                'SecondaryCategory' => $secondaryCategory,
                'Menu' => $menu,
                'Cost' => $cost,
                'Dietary' => $dietary,
                'Allergens' => $allergens,
                'Leads' => $leadsOut,
            ],
        ];
    }

    private function staffHasFlag(int $mundaneId, int $eventId, int $detailId, string $flag): bool
    {
        $allowed = ['can_manage', 'can_attendance', 'can_schedule', 'can_feast'];
        if (!in_array($flag, $allowed, true)) {
            return false;
        }

        if ($detailId > 0) {
            $this->db->Clear();
            $row = $this->db->DataSet(
                'SELECT ' . $flag . ' FROM ' . DB_PREFIX . 'event_staff
                 WHERE event_calendardetail_id = ' . $detailId . ' AND mundane_id = ' . $mundaneId . ' LIMIT 1'
            );
            if ($row && $row->Next()) {
                return (bool) (int) $row->$flag || ($flag !== 'can_manage' && (bool) (int) ($row->can_manage ?? 0));
            }
            return false;
        }

        $this->db->Clear();
        $row = $this->db->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . 'event_staff es
             JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = es.event_calendardetail_id
             WHERE cd.event_id = ' . $eventId . ' AND es.mundane_id = ' . $mundaneId
            . ' AND es.' . $flag . ' = 1 LIMIT 1'
        );

        return (bool) ($row && $row->Next());
    }

    /** @return array{can_schedule: bool, can_feast: bool} */
    private function staffScheduleCaps(int $mundaneId, int $eventId, int $detailId): array
    {
        if (Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_EVENT, $eventId, AUTH_EDIT)) {
            return ['can_schedule' => true, 'can_feast' => true];
        }

        $this->db->Clear();
        $staffRow = $this->db->DataSet(
            'SELECT can_manage, can_schedule, can_feast FROM ' . DB_PREFIX . 'event_staff
             WHERE event_calendardetail_id = ' . $detailId . ' AND mundane_id = ' . $mundaneId . ' LIMIT 1'
        );
        if (!$staffRow || !$staffRow->Next()) {
            return ['can_schedule' => false, 'can_feast' => false];
        }

        return [
            'can_schedule' => (bool) (int) $staffRow->can_schedule || (bool) (int) $staffRow->can_manage,
            'can_feast' => (bool) (int) $staffRow->can_feast || (bool) (int) $staffRow->can_manage,
        ];
    }

    private function resolveDetailRow(int $eventId, int $detailId)
    {
        if ($detailId > 0) {
            $this->db->Clear();
            $cdRs = $this->db->DataSet(
                "SELECT cd.event_calendardetail_id, cd.event_start, cd.event_end, cd.description,
                        cd.price, cd.address, cd.city, cd.province, cd.location, cd.at_park_id
                 FROM " . DB_PREFIX . "event_calendardetail cd
                 WHERE cd.event_calendardetail_id = {$detailId} AND cd.event_id = {$eventId} LIMIT 1"
            );
            if ($cdRs && $cdRs->Next()) {
                return $cdRs;
            }
        }

        $this->db->Clear();
        $cdRs = $this->db->DataSet(
            "SELECT cd.event_calendardetail_id, cd.event_start, cd.event_end, cd.description,
                    cd.price, cd.address, cd.city, cd.province, cd.location, cd.at_park_id
             FROM " . DB_PREFIX . "event_calendardetail cd
             WHERE cd.event_id = {$eventId} AND cd.event_start >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY cd.event_start LIMIT 1"
        );

        return ($cdRs && $cdRs->Next()) ? $cdRs : null;
    }

    private function buildDescriptionExcerpt(string $desc): string
    {
        $plain = trim(preg_replace('/\s+/', ' ', preg_replace('/[#*_\[\]\(\)`>~-]+/', ' ', $desc)));
        if ($plain === '') {
            return '';
        }
        if (strlen($plain) <= 220) {
            return $plain;
        }
        $cut = substr($plain, 0, 220);
        $cutAt = max(strrpos($cut, '. '), strrpos($cut, ' '));

        return ($cutAt > 120 ? substr($cut, 0, $cutAt) : $cut) . '…';
    }

    private function fetchStaffPriorState(int $detailId, int $staffIdIn, int $mundaneId): ?array
    {
        $this->db->Clear();
        if ($staffIdIn > 0) {
            $priorRs = $this->db->DataSet(
                'SELECT event_staff_id, role_name, can_manage, can_attendance, can_schedule, can_feast
                 FROM ' . DB_PREFIX . 'event_staff
                 WHERE event_staff_id = ' . $staffIdIn . ' AND event_calendardetail_id = ' . $detailId . ' LIMIT 1'
            );
        } else {
            $priorRs = $this->db->DataSet(
                'SELECT event_staff_id, role_name, can_manage, can_attendance, can_schedule, can_feast
                 FROM ' . DB_PREFIX . 'event_staff
                 WHERE event_calendardetail_id = ' . $detailId . ' AND mundane_id = ' . $mundaneId . ' LIMIT 1'
            );
        }
        if (!$priorRs || !$priorRs->Next()) {
            return null;
        }

        return [
            'event_staff_id' => (int) $priorRs->event_staff_id,
            'role_name' => (string) $priorRs->role_name,
            'can_manage' => (int) $priorRs->can_manage,
            'can_attendance' => (int) $priorRs->can_attendance,
            'can_schedule' => (int) $priorRs->can_schedule,
            'can_feast' => (int) $priorRs->can_feast,
        ];
    }

    private function fetchStaffPriorStateById(int $staffId, int $detailId): ?array
    {
        $this->db->Clear();
        $priorRs = $this->db->DataSet(
            'SELECT event_staff_id, mundane_id, role_name, can_manage, can_attendance, can_schedule, can_feast
             FROM ' . DB_PREFIX . 'event_staff
             WHERE event_staff_id = ' . $staffId . ' AND event_calendardetail_id = ' . $detailId . ' LIMIT 1'
        );
        if (!$priorRs || !$priorRs->Next()) {
            return null;
        }

        return [
            'event_staff_id' => (int) $priorRs->event_staff_id,
            'mundane_id' => (int) $priorRs->mundane_id,
            'role_name' => (string) $priorRs->role_name,
            'can_manage' => (int) $priorRs->can_manage,
            'can_attendance' => (int) $priorRs->can_attendance,
            'can_schedule' => (int) $priorRs->can_schedule,
            'can_feast' => (int) $priorRs->can_feast,
        ];
    }

    private function copyDetailFeesAndLinks(int $srcDetailId, int $newDetailId): void
    {
        $this->db->Clear();
        $feesRs = $this->db->DataSet(
            'SELECT admission_type, cost, sort_order FROM ' . DB_PREFIX . 'event_fees
             WHERE event_calendardetail_id = ' . $srcDetailId . ' ORDER BY sort_order ASC'
        );
        if ($feesRs) {
            while ($feesRs->Next()) {
                $this->db->Clear();
                $this->db->Execute(
                    'INSERT INTO ' . DB_PREFIX . 'event_fees (event_calendardetail_id, admission_type, cost, sort_order)
                     VALUES (' . $newDetailId . ", '" . $this->sq((string) $feesRs->admission_type) . "', "
                    . round((float) $feesRs->cost, 2) . ', ' . (int) $feesRs->sort_order . ')'
                );
            }
        }

        $allowedIcons = ['fab fa-facebook', 'fab fa-discord', 'fas fa-globe', 'far fa-clipboard', 'fas fa-link', 'fas fa-ticket-alt'];
        $this->db->Clear();
        $linksRs = $this->db->DataSet(
            'SELECT title, url, icon, sort_order FROM ' . DB_PREFIX . 'event_links
             WHERE event_calendardetail_id = ' . $srcDetailId . ' ORDER BY sort_order ASC'
        );
        if ($linksRs) {
            while ($linksRs->Next()) {
                $luRaw = trim((string) $linksRs->url);
                if ($luRaw !== '') {
                    $sc = strtolower((string) parse_url($luRaw, PHP_URL_SCHEME));
                    if (!in_array($sc, ['http', 'https', 'mailto'], true)) {
                        $luRaw = '';
                    }
                }
                $icRaw = trim((string) $linksRs->icon);
                if (!in_array($icRaw, $allowedIcons, true)) {
                    $icRaw = 'fas fa-link';
                }
                $this->db->Clear();
                $this->db->Execute(
                    'INSERT INTO ' . DB_PREFIX . 'event_links (event_calendardetail_id, title, url, icon, sort_order)
                     VALUES (' . $newDetailId . ", '" . $this->sq((string) $linksRs->title) . "', '"
                    . $this->sq($luRaw) . "', '" . $this->sq($icRaw) . "', " . (int) $linksRs->sort_order . ')'
                );
            }
        }
    }

    private function copyScheduleModules(int $srcDetailId, int $newDetailId, array $mod, int $deltaSeconds): void
    {
        $this->db->Clear();
        $schedRs = $this->db->DataSet(
            'SELECT * FROM ' . DB_PREFIX . 'event_schedule
             WHERE event_calendardetail_id = ' . $srcDetailId . ' ORDER BY start_time ASC'
        );
        $srcSchedIds = [];
        if ($schedRs) {
            while ($schedRs->Next()) {
                $cat = (string) $schedRs->category;
                $secCat = (string) $schedRs->secondary_category;
                $isFeast = ($cat === 'Feast and Food' || $secCat === 'Feast and Food');
                $want = $isFeast ? $mod['feast'] : $mod['schedule'];
                if (!$want) {
                    continue;
                }

                $st = strtotime((string) $schedRs->start_time);
                $et = strtotime((string) $schedRs->end_time);
                if (!$st || !$et) {
                    continue;
                }

                $menuSql = ($schedRs->menu !== null) ? "'" . $this->sq((string) $schedRs->menu) . "'" : 'NULL';
                $costSql = ($schedRs->cost !== null && is_numeric($schedRs->cost))
                    ? (string) round((float) $schedRs->cost, 2) : 'NULL';
                $dietSql = ($schedRs->dietary !== null) ? "'" . $this->sq((string) $schedRs->dietary) . "'" : 'NULL';
                $alleSql = ($schedRs->allergens !== null) ? "'" . $this->sq((string) $schedRs->allergens) . "'" : 'NULL';

                $srcSchedId = (int) $schedRs->event_schedule_id;
                $this->db->Clear();
                $this->db->Execute(
                    'INSERT INTO ' . DB_PREFIX . "event_schedule
                     (event_calendardetail_id, title, start_time, end_time, location, description,
                      category, secondary_category, menu, cost, dietary, allergens)
                     VALUES (" . $newDetailId . ", '" . $this->sq((string) $schedRs->title) . "', '"
                    . date('Y-m-d H:i:s', $st + $deltaSeconds) . "', '" . date('Y-m-d H:i:s', $et + $deltaSeconds)
                    . "', '" . $this->sq((string) $schedRs->location) . "', '" . $this->sq((string) $schedRs->description)
                    . "', '" . $this->sq($cat) . "', '" . $this->sq($secCat) . "', "
                    . $menuSql . ', ' . $costSql . ', ' . $dietSql . ', ' . $alleSql . ')'
                );
                $this->db->Clear();
                $nsRow = $this->db->DataSet(
                    'SELECT event_schedule_id FROM ' . DB_PREFIX . 'event_schedule
                     WHERE event_calendardetail_id = ' . $newDetailId . ' ORDER BY event_schedule_id DESC LIMIT 1'
                );
                $newSchedId = ($nsRow && $nsRow->Next()) ? (int) $nsRow->event_schedule_id : 0;
                if ($newSchedId > 0) {
                    $srcSchedIds[$srcSchedId] = $newSchedId;
                }
            }
        }

        if (!empty($srcSchedIds)) {
            $srcKeys = implode(',', array_map('intval', array_keys($srcSchedIds)));
            $this->db->Clear();
            $leadsRs = $this->db->DataSet(
                'SELECT event_schedule_id, mundane_id FROM ' . DB_PREFIX . 'event_schedule_lead
                 WHERE event_schedule_id IN (' . $srcKeys . ')'
            );
            if ($leadsRs) {
                while ($leadsRs->Next()) {
                    $newSid = $srcSchedIds[(int) $leadsRs->event_schedule_id] ?? 0;
                    $mid = (int) $leadsRs->mundane_id;
                    if ($newSid <= 0 || !$this->isMundaneEligible($mid)) {
                        continue;
                    }
                    $this->db->Clear();
                    $this->db->Execute(
                        'INSERT IGNORE INTO ' . DB_PREFIX . 'event_schedule_lead (event_schedule_id, mundane_id)
                         VALUES (' . $newSid . ', ' . $mid . ')'
                    );
                }
            }
        }
    }

    private function copyStaffModule(int $srcDetailId, int $newDetailId): void
    {
        $this->db->Clear();
        $staffRs = $this->db->DataSet(
            'SELECT mundane_id, role_name, can_manage, can_attendance, can_schedule, can_feast
             FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . $srcDetailId
        );
        if ($staffRs) {
            while ($staffRs->Next()) {
                $mid = (int) $staffRs->mundane_id;
                if (!$this->isMundaneEligible($mid)) {
                    continue;
                }
                $this->db->Clear();
                $this->db->Execute(
                    'INSERT INTO ' . DB_PREFIX . 'event_staff
                     (event_calendardetail_id, mundane_id, role_name, can_manage, can_attendance, can_schedule, can_feast)
                     VALUES (' . $newDetailId . ', ' . $mid . ", '" . $this->sq((string) $staffRs->role_name) . "', "
                    . (int) $staffRs->can_manage . ', ' . (int) $staffRs->can_attendance . ', '
                    . (int) $staffRs->can_schedule . ', ' . (int) $staffRs->can_feast . ')
                     ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), can_manage = VALUES(can_manage),
                     can_attendance = VALUES(can_attendance), can_schedule = VALUES(can_schedule),
                     can_feast = VALUES(can_feast)'
                );
            }
        }
    }

    private function rollbackCopiedEvent(int $eventId): void
    {
        $this->db->Clear();
        $this->db->Execute(
            'DELETE FROM ' . DB_PREFIX . 'event_schedule_lead WHERE event_schedule_id IN (
                SELECT s.event_schedule_id FROM ' . DB_PREFIX . 'event_schedule s
                JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = s.event_calendardetail_id
                WHERE cd.event_id = ' . $eventId . ')'
        );
        $this->db->Clear();
        $this->db->Execute(
            'DELETE s FROM ' . DB_PREFIX . 'event_schedule s
             JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = s.event_calendardetail_id
             WHERE cd.event_id = ' . $eventId
        );
        $this->db->Clear();
        $this->db->Execute(
            'DELETE st FROM ' . DB_PREFIX . 'event_staff st
             JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = st.event_calendardetail_id
             WHERE cd.event_id = ' . $eventId
        );
        $this->db->Clear();
        $this->db->Execute(
            'DELETE fe FROM ' . DB_PREFIX . 'event_fees fe
             JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = fe.event_calendardetail_id
             WHERE cd.event_id = ' . $eventId
        );
        $this->db->Clear();
        $this->db->Execute(
            'DELETE lk FROM ' . DB_PREFIX . 'event_links lk
             JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = lk.event_calendardetail_id
             WHERE cd.event_id = ' . $eventId
        );
        $this->db->Clear();
        $this->db->Execute('DELETE FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_id = ' . $eventId);
        $this->db->Clear();
        $this->db->Execute('DELETE FROM ' . DB_PREFIX . 'event WHERE event_id = ' . $eventId);

        $base = DIR_EVENT_BANNER . sprintf('%05d', $eventId);
        if (file_exists($base . '.jpg')) {
            @unlink($base . '.jpg');
        }
        if (file_exists($base . '.png')) {
            @unlink($base . '.png');
        }
    }

    private function isMundaneEligible(int $mundaneId): bool
    {
        if ($mundaneId <= 0) {
            return false;
        }
        if (array_key_exists($mundaneId, $this->mundaneEligibleCache)) {
            return $this->mundaneEligibleCache[$mundaneId];
        }

        $this->db->Clear();
        $row = $this->db->DataSet(
            'SELECT active, suspended, suspended_until FROM ' . DB_PREFIX . 'mundane
             WHERE mundane_id = ' . $mundaneId . ' LIMIT 1'
        );
        $ok = false;
        if ($row && $row->Next()) {
            if ((int) $row->active === 1) {
                if ((int) $row->suspended !== 1) {
                    $ok = true;
                } else {
                    $until = $row->suspended_until;
                    if ($until && strtotime((string) $until) !== false && strtotime((string) $until) < strtotime(date('Y-m-d'))) {
                        $ok = true;
                    }
                }
            }
        }
        $this->mundaneEligibleCache[$mundaneId] = $ok;

        return $ok;
    }

    private function bustEventScopeCaches(int $eventId): void
    {
        Ork3::$Lib->ghettocache->bust_event_search($eventId);

        $this->db->Clear();
        $evRow = $this->db->DataSet(
            'SELECT park_id, kingdom_id FROM ' . DB_PREFIX . 'event WHERE event_id = ' . $eventId . ' LIMIT 1'
        );
        if (!$evRow || !$evRow->Next()) {
            return;
        }

        $parkId = (int) $evRow->park_id;
        $kingdomId = (int) $evRow->kingdom_id;
        $this->db->Clear();
        $dates = $this->db->DataSet(
            'SELECT DISTINCT DATE(event_start) AS d FROM ' . DB_PREFIX . 'event_calendardetail
             WHERE event_id = ' . $eventId . ' AND event_end >= NOW()'
        );
        while ($dates && $dates->Next()) {
            $d = (string) $dates->d;
            if ($parkId > 0) {
                $k = Ork3::$Lib->ghettocache->key(['Scope' => 'park', 'ScopeId' => $parkId, 'Date' => $d]);
                Ork3::$Lib->ghettocache->bust('Event.GetActiveEventsAtScope', $k);
            }
            if ($kingdomId > 0) {
                $k = Ork3::$Lib->ghettocache->key(['Scope' => 'kingdom', 'ScopeId' => $kingdomId, 'Date' => $d]);
                Ork3::$Lib->ghettocache->bust('Event.GetActiveEventsAtScope', $k);
            }
        }
    }

    private function sq(string $s): string
    {
        return str_replace(["'", '\\'], ["''", '\\\\'], $s);
    }
}
