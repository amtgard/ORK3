<?php

class EventPlanning extends Ork3
{
    /**
     * Schedule item categories, in display order. The single source for the
     * allow-list, the Schedule tab's palette, selects, filters, board and entry grammar.
     * `key` is the keyboard shortcut; `bold` is the solid fill behind white text.
     * `tag` is the `#tag` the entry grammar writes; `aliases` are the other names a
     * typed `#tag` or `Name:` prefix may use. `keywords` are the title words that
     * guess the category (whole words, optional trailing 's'); the guess tries the
     * categories in ascending `keywordRank` and the first match wins (Other has no
     * keywords and ranks last).
     */
    private const SCHEDULE_CATEGORIES = [
        'Administrative'      => ['key' => '1', 'icon' => 'fa-clipboard-list', 'color' => '#546e7a', 'bg' => '#eceff1', 'colorDark' => '#90a4ae', 'bgDark' => '#2a3136', 'bold' => '#546e7a',
            'tag' => 'admin', 'aliases' => ['admin'], 'keywordRank' => 9,
            'keywords' => ['troll', 'gate', 'setup', 'set up', 'clean', 'cleanup', 'teardown', 'tear down', 'check-in', 'checkin', 'offsite', 'crat']],
        'Tournament'          => ['key' => '2', 'icon' => 'fa-trophy',         'color' => '#b8860b', 'bg' => '#fffde7', 'colorDark' => '#ffd54f', 'bgDark' => '#3a2f15', 'bold' => '#8a6500',
            'tag' => 'tourney', 'aliases' => ['tourney'], 'keywordRank' => 4,
            'keywords' => ['tournament', 'tourney', 'warmaster', 'weaponmaster', 'bracket']],
        'Battlegame'          => ['key' => '3', 'icon' => 'fa-shield-alt',     'color' => '#c0392b', 'bg' => '#fdecea', 'colorDark' => '#ff9a93', 'bgDark' => '#3d1e1a', 'bold' => '#c0392b',
            'tag' => 'bg', 'aliases' => ['bg', 'battle game'], 'keywordRank' => 7,
            'keywords' => ['battlegame', 'battle game', 'bg', 'militia', 'ditch']],
        'Arts and Sciences'   => ['key' => '4', 'icon' => 'fa-palette',        'color' => '#7b1fa2', 'bg' => '#f3e5f5', 'colorDark' => '#ce93d8', 'bgDark' => '#2d1935', 'bold' => '#7b1fa2',
            'tag' => 'as', 'aliases' => ['as', 'a&s'], 'keywordRank' => 1,
            'keywords' => ['a&s', 'arts and sciences', 'judging', 'dragonmaster']],
        'Class'               => ['key' => '5', 'icon' => 'fa-graduation-cap', 'color' => '#1565c0', 'bg' => '#e3f2fd', 'colorDark' => '#90caf9', 'bgDark' => '#0f2540', 'bold' => '#1565c0',
            'tag' => 'class', 'aliases' => [], 'keywordRank' => 5,
            'keywords' => ['class', 'workshop', 'seminar']],
        'Feast and Food'      => ['key' => '6', 'icon' => 'fa-utensils',       'color' => '#e65100', 'bg' => '#fff3e0', 'colorDark' => '#ffb74d', 'bgDark' => '#3a2410', 'bold' => '#bf4d00',
            'tag' => 'feast', 'aliases' => ['feast', 'food'], 'keywordRank' => 2,
            'keywords' => ['feast', 'dinner', 'lunch', 'breakfast', 'brunch', 'potluck', 'tavern']],
        'Court'               => ['key' => '7', 'icon' => 'fa-crown',          'color' => '#4e342e', 'bg' => '#efebe9', 'colorDark' => '#bcaaa4', 'bgDark' => '#2a201d', 'bold' => '#4e342e',
            'tag' => 'court', 'aliases' => [], 'keywordRank' => 3,
            'keywords' => ['court', 'coronation', 'knighting', 'ceremony', 'oath']],
        'Meeting'             => ['key' => '8', 'icon' => 'fa-users',          'color' => '#276749', 'bg' => '#f0fff4', 'colorDark' => '#81c784', 'bgDark' => '#143024', 'bold' => '#276749',
            'tag' => 'meeting', 'aliases' => [], 'keywordRank' => 8,
            'keywords' => ['meeting', 'althing', 'allthing']],
        'Roleplay and Quests' => ['key' => '9', 'icon' => 'fa-dragon',         'color' => '#c2185b', 'bg' => '#fce4ec', 'colorDark' => '#f48fb1', 'bgDark' => '#3a1626', 'bold' => '#c2185b',
            'tag' => 'rp', 'aliases' => ['rp', 'roleplay', 'quest'], 'keywordRank' => 6,
            'keywords' => ['quest', 'rp', 'roleplay', 'role play']],
        'Other'               => ['key' => '0', 'icon' => 'fa-star',           'color' => '#757575', 'bg' => '#fafafa', 'colorDark' => '#bdbdbd', 'bgDark' => '#2a2a2a', 'bold' => '#616161',
            'tag' => 'other', 'aliases' => [], 'keywordRank' => 10,
            'keywords' => []],
    ];

    /** Most changes one schedule batch may carry. */
    private const SCHEDULE_BATCH_MAX_OPS = 200;

    /** Fields a feast-capable caller may write; everyone else's are ignored. */
    private const SCHEDULE_MEAL_FIELDS = ['Menu' => 'menu', 'Cost' => 'cost', 'Dietary' => 'dietary', 'Allergens' => 'allergens'];

    /** Most leads one schedule item may have. */
    private const SCHEDULE_LEADS_MAX = 20;

    /** A BatchToken / RequestToken: what makes a repeated batch or copy apply once. */
    private const SCHEDULE_REQUEST_TOKEN_RE = '/^[A-Za-z0-9_-]{16,64}$/';

    /** Longest text (characters) a batch op may write to each field, and the field's name in the refusal. */
    private const SCHEDULE_TEXT_LIMITS = [
        'Title' => [255, 'title'],
        'Location' => [255, 'location'],
        'Category' => [50, 'category'],
        'SecondaryCategory' => [50, 'secondary category'],
        'Description' => [4000, 'description'],
        'Menu' => [4000, 'menu'],
        'Dietary' => [500, 'dietary note'],
        'Allergens' => [500, 'allergens note'],
    ];

    /** How far (days) before the occurrence's start date or after its end date an item may start. */
    private const SCHEDULE_WINDOW_DAYS = 7;

    /** The legacy form's refusal when the item changed after the form read it. */
    private const SCHEDULE_CHANGED_ELSEWHERE = 'Someone else changed this item. Close this form and open it again to see their version.';

    /** Most items one occurrence's schedule may hold. */
    private const SCHEDULE_MAX_ITEMS = 500;

    /** The category whose items a feast editor (can_feast without can_schedule) may change. */
    private const FEAST_CATEGORY = 'Feast and Food';

    /**
     * Ordered map of category name to its definition
     * (`key`, `icon`, `color`, `bg`, `colorDark`, `bgDark`, `bold`, `tag`,
     * `aliases`, `keywords`, `keywordRank`).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function ScheduleCategories(): array
    {
        return self::SCHEDULE_CATEGORIES;
    }

    public function GetScheduleCategories($request = [])
    {
        return [
            'Status' => Success(),
            'Categories' => self::ScheduleCategories(),
        ];
    }

    /** Most changes one schedule batch (EventAjax/schedule_batch) may carry. */
    public static function ScheduleBatchMaxOps(): int
    {
        return self::SCHEDULE_BATCH_MAX_OPS;
    }

    public function GetScheduleBatchMax($request = [])
    {
        return [
            'Status' => Success(),
            'BatchMaxOps' => self::ScheduleBatchMaxOps(),
        ];
    }

    /**
     * Most items one occurrence's schedule may hold (SCHEDULE_MAX_ITEMS). A method so
     * a test can lower the ceiling instead of seeding hundreds of rows; the refusals
     * are built from it, so their number is always the ceiling actually applied.
     */
    protected function scheduleMaxItems(): int
    {
        return self::SCHEDULE_MAX_ITEMS;
    }

    /** The refusal of an add (batch or legacy form) that would put the schedule over its ceiling. */
    private function scheduleFullError(): array
    {
        return InvalidParameter('A schedule can hold at most ' . $this->scheduleMaxItems() . ' items.');
    }

    /** The occurrence's item count, or null when the read failed. */
    private function countScheduleItems(int $detailId): ?int
    {
        $this->db->Clear();
        $row = @$this->db->DataSet(
            'SELECT COUNT(*) AS item_count FROM ' . DB_PREFIX . 'event_schedule WHERE event_calendardetail_id = ' . $detailId
        );
        if (!$this->resultSucceeded($row) || !$row->Next()) {
            return null;
        }

        return (int) $row->item_count;
    }

    /** Whether a primary/secondary category pair makes an item a feast item. */
    private function isFeastItem(string $category, string $secondaryCategory): bool
    {
        return $category === self::FEAST_CATEGORY || $secondaryCategory === self::FEAST_CATEGORY;
    }

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
        $requestMundaneId = (int) ($request['MundaneId'] ?? 0);
        $wantDietary = !empty($request['IncludeDietary']);
        $token = (string) ($request['Token'] ?? '');
        $actorId = Ork3::$Lib->authorization->IsAuthorized($token);

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

        if ($eventStatus !== 'published') {
            if (!valid_id($actorId)) {
                return BadToken();
            }
            $isAdmin = Ork3::$Lib->authorization->HasAuthority($actorId, AUTH_ADMIN, 0, AUTH_CREATE);
            $canViewDraft = $isAdmin
                || (int) $actorId === $creatorId
                || $this->CanManageEventDetail($actorId, $eventId, $detailId, 'manage')
                || $this->CanManageEventDetail($actorId, $eventId, $detailId, 'attendance')
                || $this->CanManageEventDetail($actorId, $eventId, $detailId, 'schedule')
                || $this->CanManageEventDetail($actorId, $eventId, $detailId, 'feast');
            if (!$canViewDraft) {
                return NoAuthorization();
            }
        }

        $mundaneId = valid_id($actorId) ? (int) $actorId : $requestMundaneId;
        $includeDietary = $wantDietary
            && valid_id($actorId)
            && $this->CanManageEventDetail((int) $actorId, $eventId, $detailId, 'feast');

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
        $mundaneId = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        if (!valid_id($mundaneId)) {
            return BadToken();
        }

        $detailId = (int) ($request['EventCalendarDetailId'] ?? 0);
        if ($detailId <= 0) {
            return [
                'Status' => Success(),
                'Items' => [],
            ];
        }

        $this->db->Clear();
        $detailRow = $this->db->DataSet(
            'SELECT event_id FROM ' . DB_PREFIX . 'event_calendardetail
             WHERE event_calendardetail_id = ' . $detailId . ' LIMIT 1'
        );
        if (!$detailRow || !$detailRow->Next()) {
            return InvalidParameter('Event occurrence not found.');
        }
        $eventId = (int) $detailRow->event_id;
        if (!$this->CanManageEventDetail((int) $mundaneId, $eventId, $detailId, 'feast')) {
            return NoAuthorization();
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

        $categories = $this->fetchStaffLeadCategories(array_column($staffList, 'EventStaffId'));
        foreach ($staffList as &$staffRow) {
            $staffRow['LeadCategories'] = $categories[$staffRow['EventStaffId']] ?? [];
        }
        unset($staffRow);

        return $staffList;
    }

    /**
     * Schedule items of one occurrence with their leads; a positive $scheduleId
     * narrows it to that one item, and a list of ids to those items (still scoped
     * to the occurrence; an empty list reads nothing).
     *
     * @param int|list<int> $scheduleId
     * @return list<array<string, mixed>>
     */
    private function fetchScheduleList(int $detailId, int|array $scheduleId = 0): array
    {
        $only = is_array($scheduleId)
            ? array_values(array_unique(array_filter(array_map('intval', $scheduleId), 'valid_id')))
            : ($scheduleId > 0 ? [$scheduleId] : []);
        if (is_array($scheduleId) && $only === []) {
            return [];
        }
        $this->db->Clear();
        $scheduleRows = $this->db->DataSet(
            'SELECT event_schedule_id AS EventScheduleId, title AS Title,
                    start_time AS StartTime, end_time AS EndTime,
                    location AS Location, description AS Description, category AS Category,
                    secondary_category AS SecondaryCategory,
                    menu AS Menu, cost AS Cost, dietary AS Dietary, allergens AS Allergens,
                    DATE_FORMAT(modified, \'%Y-%m-%d %H:%i:%s\') AS Modified
             FROM ' . DB_PREFIX . 'event_schedule
             WHERE event_calendardetail_id = ' . $detailId
            . ($only !== [] ? ' AND event_schedule_id IN (' . implode(',', $only) . ')' : '') . '
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
                // The row's version: every write this class makes to the item advances it,
                // its leads included (batch ops, the form's save through them, and the staff-side
                // category-lead fill and removal).
                'Modified' => (string) ($scheduleRows->Modified ?? ''),
            ];
        }

        if ($scheduleList !== []) {
            $slIds = implode(',', array_map('intval', array_column($scheduleList, 'EventScheduleId')));
            $this->db->Clear();
            $leadRows = $this->db->DataSet(
                'SELECT sl.event_schedule_id AS EventScheduleId, m.mundane_id AS MundaneId, m.persona AS Persona,
                        sl.is_auto AS IsAuto
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
                    'Auto' => (bool) (int) $leadRows->IsAuto,
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
                    d.allergen_cocoa, d.allergen_nightshades
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
                'AllergenNightshades' => (int) ($dsRows->allergen_nightshades ?? 0),
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
        if (!valid_id($eventId) || !valid_id($detailId) || !$this->detailBelongsToEvent($detailId, $eventId)) {
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

        // Absent means "leave them as they are" on an update and "none" on an add.
        $leadCategories = array_key_exists('LeadCategories', $request)
            ? $this->normalizeLeadCategories($request['LeadCategories'])
            : null;

        $priorState = $this->fetchStaffPriorState($detailId, $staffIdIn, $targetMundane);
        $isUpdate = $staffIdIn > 0 && $priorState;

        // As a batch's: the lead rules lock schedule items, which a batch may be writing.
        $this->beginReadCommittedTrans();
        try {
            [$error, $staff, $leadsAdded, $leadsRemoved] = $this->writeStaffRow(
                $detailId,
                $isUpdate ? $staffIdIn : 0,
                $targetMundane,
                $roleName,
                [$canManage, $canAttendance, $canSchedule, $canFeast],
                $leadCategories
            );
            if ($error !== null) {
                $this->db->RollbackTrans();
                return $error;
            }
        } catch (\Throwable $e) {
            $this->db->RollbackTrans();
            throw $e;
        }
        if (!$this->db->CommitTrans()) {
            return ProcessingError('Could not save the staff member.');
        }

        return [
            'Status' => Success(),
            'PriorState' => $priorState,
            'Staff' => [
                'EventStaffId' => $staff['EventStaffId'],
                'MundaneId' => $targetMundane,
                'Persona' => $staff['Persona'],
                'RoleName' => $roleName,
                'CanManage' => $canManage,
                'CanAttendance' => $canAttendance,
                'CanSchedule' => $canSchedule,
                'CanFeast' => $canFeast,
                'LeadCategories' => $staff['LeadCategories'],
            ],
            'LeadsAdded' => $leadsAdded,
            'LeadsRemoved' => $leadsRemoved,
        ];
    }

    /**
     * Inserts or updates one staff row and, when $leadCategories is not null,
     * replaces its lead categories and applies the fill and removal rules.
     * Runs inside AddEventStaff's transaction.
     *
     * @param array{0: int, 1: int, 2: int, 3: int} $caps manage, attendance, schedule, feast
     * @param list<string>|null $leadCategories null leaves the stored categories as they are
     * @return array{0: ?array, 1: array{EventStaffId: int, Persona: string, LeadCategories: list<string>}, 2: int, 3: int}
     */
    private function writeStaffRow(
        int $detailId,
        int $staffIdIn,
        int $targetMundane,
        string $roleName,
        array $caps,
        ?array $leadCategories
    ): array {
        [$canManage, $canAttendance, $canSchedule, $canFeast] = $caps;
        $failed = [ProcessingError('Could not save the staff member.'), [], 0, 0];

        $this->db->Clear();
        $this->db->role_name = $roleName;
        $capsSql = 'can_manage = ' . $canManage . ', can_attendance = ' . $canAttendance
            . ', can_schedule = ' . $canSchedule . ', can_feast = ' . $canFeast;
        if ($staffIdIn > 0) {
            $ok = $this->db->ExecuteChecked(
                'UPDATE ' . DB_PREFIX . 'event_staff SET role_name = :role_name, ' . $capsSql . '
                 WHERE event_staff_id = ' . $staffIdIn . ' AND event_calendardetail_id = ' . $detailId
            );
        } else {
            $ok = $this->db->ExecuteChecked(
                'INSERT INTO ' . DB_PREFIX . 'event_staff
                 (event_calendardetail_id, mundane_id, role_name, can_manage, can_attendance, can_schedule, can_feast)
                 VALUES (' . $detailId . ', ' . $targetMundane . ', :role_name, '
                . $canManage . ', ' . $canAttendance . ', ' . $canSchedule . ', ' . $canFeast . ')
                 ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), can_manage = VALUES(can_manage),
                 can_attendance = VALUES(can_attendance), can_schedule = VALUES(can_schedule),
                 can_feast = VALUES(can_feast)'
            );
        }
        if (!$ok) {
            return $failed;
        }

        $this->db->Clear();
        $idrow = $this->db->DataSet(
            'SELECT s.event_staff_id, s.mundane_id, m.persona FROM ' . DB_PREFIX . 'event_staff s
             LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = s.mundane_id
             WHERE s.event_calendardetail_id = ' . $detailId
            . ($staffIdIn > 0
                ? ' AND s.event_staff_id = ' . $staffIdIn
                : ' AND s.mundane_id = ' . $targetMundane . ' ORDER BY s.event_staff_id DESC')
            . ' LIMIT 1'
        );
        if (!$idrow || !$idrow->Next()) {
            return $failed;
        }
        $staffId = (int) $idrow->event_staff_id;
        $staffMundane = (int) $idrow->mundane_id;
        $persona = (string) ($idrow->persona ?? '');

        $current = $this->fetchStaffLeadCategories([$staffId])[$staffId] ?? [];
        $leadsAdded = 0;
        $leadsRemoved = 0;
        if ($leadCategories !== null) {
            $added = array_values(array_diff($leadCategories, $current));
            $dropped = array_values(array_diff($current, $leadCategories));
            foreach ($dropped as $category) {
                $this->db->Clear();
                $this->db->category = $category;
                if (!$this->db->ExecuteChecked(
                    'DELETE FROM ' . DB_PREFIX . 'event_staff_category
                     WHERE event_staff_id = ' . $staffId . ' AND category = :category'
                )) {
                    return $failed;
                }
            }
            foreach ($added as $category) {
                $this->db->Clear();
                $this->db->category = $category;
                if (!$this->db->ExecuteChecked(
                    'INSERT IGNORE INTO ' . DB_PREFIX . 'event_staff_category (event_staff_id, category)
                     VALUES (' . $staffId . ', :category)'
                )) {
                    return $failed;
                }
            }
            $leadsRemoved = $this->removeAutoCategoryLeads($detailId, $staffMundane, $dropped, $staffId);
            $leadsAdded = $leadsRemoved === null ? null : $this->fillCategoryLeads($detailId, $staffMundane, $added);
            if ($leadsRemoved === null || $leadsAdded === null) {
                return $failed;
            }
            $current = $leadCategories;
        }

        return [
            null,
            ['EventStaffId' => $staffId, 'Persona' => $persona, 'LeadCategories' => $current],
            $leadsAdded,
            $leadsRemoved,
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
        if (!$this->detailBelongsToEvent($detailId, $eventId)) {
            return InvalidParameter('Invalid Event ID.');
        }
        if (!$this->CanManageEventDetail($mundaneId, $eventId, $detailId, 'create')) {
            return NoAuthorization();
        }

        $priorState = $this->fetchStaffPriorStateById($staffId, $detailId);
        $leadsRemoved = 0;

        // As a batch's: the removal rule locks schedule items, which a batch may be writing.
        $this->beginReadCommittedTrans();
        try {
            if ($priorState !== null) {
                $leadsRemoved = $this->removeAutoCategoryLeads(
                    $detailId,
                    $priorState['mundane_id'],
                    $priorState['lead_categories'],
                    $staffId
                );
            }
            $ok = $leadsRemoved !== null;
            if ($ok) {
                // Its category rows go with it (ON DELETE CASCADE).
                $this->db->Clear();
                $ok = $this->db->ExecuteChecked(
                    'DELETE FROM ' . DB_PREFIX . 'event_staff
                     WHERE event_staff_id = ' . $staffId . ' AND event_calendardetail_id = ' . $detailId
                );
            }
            if (!$ok) {
                $this->db->RollbackTrans();
                return ProcessingError('Could not remove the staff member.');
            }
        } catch (\Throwable $e) {
            $this->db->RollbackTrans();
            throw $e;
        }
        if (!$this->db->CommitTrans()) {
            return ProcessingError('Could not remove the staff member.');
        }

        return [
            'Status' => Success(),
            'PriorState' => $priorState,
            'LeadsRemoved' => $leadsRemoved,
        ];
    }

    /**
     * Category names from a request, keeping only known schedule categories,
     * de-duplicated and in definition order.
     *
     * @return list<string>
     */
    private function normalizeLeadCategories(mixed $raw): array
    {
        $wanted = [];
        foreach (is_array($raw) ? $raw : [] as $name) {
            if (is_string($name)) {
                $wanted[trim($name)] = true;
            }
        }

        return array_values(array_filter(
            array_keys(self::SCHEDULE_CATEGORIES),
            static fn (string $category): bool => isset($wanted[$category])
        ));
    }

    /**
     * Lead categories of the given staff rows, each list in definition order.
     *
     * @param list<int> $staffIds
     * @return array<int, list<string>>
     */
    private function fetchStaffLeadCategories(array $staffIds): array
    {
        $staffIds = array_values(array_filter(array_map('intval', $staffIds), 'valid_id'));
        if ($staffIds === []) {
            return [];
        }
        $this->db->Clear();
        $rows = $this->db->DataSet(
            'SELECT event_staff_id, category FROM ' . DB_PREFIX . 'event_staff_category
             WHERE event_staff_id IN (' . implode(',', $staffIds) . ')'
        );
        $found = [];
        while ($rows && $rows->Next()) {
            $found[(int) $rows->event_staff_id][(string) $rows->category] = true;
        }
        $order = array_keys(self::SCHEDULE_CATEGORIES);
        $out = [];
        foreach ($found as $staffId => $set) {
            $out[$staffId] = array_values(array_filter($order, static fn (string $c): bool => isset($set[$c])));
        }

        return $out;
    }

    /**
     * Fill rule: on every item of the occurrence whose primary category is one of
     * $categories and which has no lead at all, adds $mundaneId as an auto-filled lead.
     * Each item filled has its `modified` advanced, as a batch op's write would.
     *
     * Lock order, as in a batch: the item rows first (the `modified` bump), then
     * their lead rows. The opposite order deadlocks against a batch, which locks
     * the item (FOR UPDATE) before it rewrites the leads. Once the items are locked
     * they are read again, so the fill acts on them as they are now, not as they
     * were before another save that this one waited for.
     *
     * @param list<string> $categories
     * @return int|null rows inserted, or null when a write failed
     */
    private function fillCategoryLeads(int $detailId, int $mundaneId, array $categories): ?int
    {
        // All the categories at once: an item has one primary category, so the
        // sets are disjoint and one pass fills exactly what one pass per category would.
        $candidates = $this->unledItemIds($detailId, $categories, null);
        if ($candidates === []) {
            return 0;
        }
        if (!$this->bumpScheduleModified($candidates)) {
            return null;
        }
        $fill = $this->unledItemIds($detailId, $categories, $candidates);
        $rows = array_map(static fn (int $scheduleId): array => [$scheduleId, $mundaneId, 1], $fill);
        // A plain INSERT (not IGNORE): it either adds every row or fails, so the count is count($fill).
        if (!$this->insertScheduleLeadRows($rows, false)) {
            return null;
        }

        return count($fill);
    }

    /**
     * Items of the occurrence whose primary category is one of $categories and which
     * have no lead at all; with $onlyIds, only those of these items.
     *
     * @param list<string> $categories
     * @param list<int>|null $onlyIds
     * @return list<int>
     */
    private function unledItemIds(int $detailId, array $categories, ?array $onlyIds): array
    {
        $known = array_values(array_intersect(array_keys(self::SCHEDULE_CATEGORIES), $categories));
        if ($known === []) {
            return [];
        }
        $this->db->Clear();
        $placeholders = [];
        foreach ($known as $i => $category) {
            $this->db->{'category' . $i} = $category;
            $placeholders[] = ':category' . $i;
        }
        $rows = $this->db->DataSet(
            'SELECT s.event_schedule_id FROM ' . DB_PREFIX . 'event_schedule s
             WHERE s.event_calendardetail_id = ' . $detailId . ' AND s.category IN (' . implode(', ', $placeholders) . ')'
            . ($onlyIds !== null ? ' AND s.event_schedule_id IN (' . implode(',', array_map('intval', $onlyIds)) . ')' : '') . '
               AND NOT EXISTS (SELECT 1 FROM ' . DB_PREFIX . 'event_schedule_lead sl
                               WHERE sl.event_schedule_id = s.event_schedule_id)'
        );
        $scheduleIds = [];
        while ($rows && $rows->Next()) {
            $scheduleIds[] = (int) $rows->event_schedule_id;
        }

        return $scheduleIds;
    }

    /**
     * Removal rule: deletes $mundaneId's auto-filled leads on items of the occurrence
     * whose primary category is one of $categories. A category the same person still
     * leads through another staff row of this occurrence is left alone. Hand-picked
     * leads (is_auto = 0) are never touched. Each item that loses a lead has its
     * `modified` advanced, as a batch op's write would.
     *
     * Lock order, as in a batch: the item rows first (the `modified` bump), then
     * their lead rows. The opposite order deadlocks against a batch, which locks
     * the item (FOR UPDATE) before it rewrites the leads. Once the items are locked
     * their leads are read again, so only leads that still exist are deleted and counted.
     *
     * @param list<string> $categories
     * @return int|null rows deleted, or null when a write failed
     */
    private function removeAutoCategoryLeads(int $detailId, int $mundaneId, array $categories, int $staffId): ?int
    {
        if ($categories === []) {
            return 0;
        }
        $this->db->Clear();
        $others = $this->db->DataSet(
            'SELECT event_staff_id FROM ' . DB_PREFIX . 'event_staff
             WHERE event_calendardetail_id = ' . $detailId . ' AND mundane_id = ' . $mundaneId
            . ' AND event_staff_id <> ' . $staffId
        );
        $otherIds = [];
        while ($others && $others->Next()) {
            $otherIds[] = (int) $others->event_staff_id;
        }
        $stillLed = array_merge([], ...array_values($this->fetchStaffLeadCategories($otherIds)));

        $removed = 0;
        foreach (array_diff($categories, $stillLed) as $category) {
            [, $scheduleIds] = $this->autoLeadRows($detailId, $mundaneId, $category, null);
            if ($scheduleIds === []) {
                continue;
            }
            if (!$this->bumpScheduleModified($scheduleIds)) {
                return null;
            }
            [$leadIds] = $this->autoLeadRows($detailId, $mundaneId, $category, $scheduleIds);
            if ($leadIds === []) {
                continue;
            }
            $this->db->Clear();
            if (!$this->db->ExecuteChecked(
                'DELETE FROM ' . DB_PREFIX . 'event_schedule_lead
                 WHERE event_schedule_lead_id IN (' . implode(',', $leadIds) . ')'
            )) {
                return null;
            }
            $removed += count($leadIds);
        }

        return $removed;
    }

    /**
     * $mundaneId's auto-filled leads on items of the occurrence whose primary category
     * is $category; with $onlyIds, only on those items.
     *
     * @param list<int>|null $onlyIds
     * @return array{0: list<int>, 1: list<int>} [lead row ids, their item ids]
     */
    private function autoLeadRows(int $detailId, int $mundaneId, string $category, ?array $onlyIds): array
    {
        $this->db->Clear();
        $this->db->category = $category;
        $rows = $this->db->DataSet(
            'SELECT sl.event_schedule_lead_id, sl.event_schedule_id FROM ' . DB_PREFIX . 'event_schedule_lead sl
             JOIN ' . DB_PREFIX . 'event_schedule s ON s.event_schedule_id = sl.event_schedule_id
             WHERE s.event_calendardetail_id = ' . $detailId . ' AND s.category = :category
               AND sl.mundane_id = ' . $mundaneId . ' AND sl.is_auto = 1'
            . ($onlyIds !== null ? ' AND sl.event_schedule_id IN (' . implode(',', array_map('intval', $onlyIds)) . ')' : '')
        );
        $leadIds = [];
        $scheduleIds = [];
        while ($rows && $rows->Next()) {
            $leadIds[] = (int) $rows->event_schedule_lead_id;
            $scheduleIds[] = (int) $rows->event_schedule_id;
        }

        return [$leadIds, array_values(array_unique($scheduleIds))];
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
        if (!valid_id($eventId) || !valid_id($detailId) || !valid_id($scheduleId)
            || !$this->detailBelongsToEvent($detailId, $eventId)) {
            return InvalidParameter('Invalid parameters.');
        }

        // A feast editor (can_feast without can_schedule) may remove only an item whose
        // stored categories make it a feast item: checked here, and again by the delete
        // itself, so a category change made meanwhile is not removed by them.
        $feastItemsOnly = false;
        if (!$this->staffScheduleCaps($mundaneId, $eventId, $detailId)['can_schedule']) {
            $this->db->Clear();
            $catRow = $this->db->DataSet(
                'SELECT category, secondary_category FROM ' . DB_PREFIX . 'event_schedule
                 WHERE event_schedule_id = ' . $scheduleId . ' AND event_calendardetail_id = ' . $detailId . ' LIMIT 1'
            );
            $isFeast = ($catRow && $catRow->Next())
                && $this->isFeastItem((string) ($catRow->category ?? ''), (string) ($catRow->secondary_category ?? ''));
            if (!$isFeast || !$this->CanManageEventDetail($mundaneId, $eventId, $detailId, 'feast')) {
                return NoAuthorization();
            }
            $feastItemsOnly = true;
        }

        $this->db->Clear();
        if ($feastItemsOnly) {
            $this->db->feast = self::FEAST_CATEGORY;
            $this->db->feast2 = self::FEAST_CATEGORY;
        }
        if (!$this->db->ExecuteChecked(
            'DELETE FROM ' . DB_PREFIX . 'event_schedule
             WHERE event_schedule_id = ' . $scheduleId . ' AND event_calendardetail_id = ' . $detailId
            . ($feastItemsOnly ? ' AND (category = :feast OR secondary_category = :feast2)' : '')
        )) {
            return ProcessingError('Could not remove the schedule item.');
        }

        // Nothing removed: an item that is already gone is not an error, but one the
        // feast guard kept (recategorised since the read above) was not removed. An
        // unreadable count is checked the same way.
        if ($feastItemsOnly && ($this->lastStatementRowCount() ?? 0) === 0) {
            $stillThere = $this->fetchScheduleTimesOnDetail($scheduleId, $detailId);
            if ($stillThere === false) {
                return ProcessingError('Could not remove the schedule item.');
            }
            if ($stillThere !== null) {
                return NoAuthorization();
            }
        }

        return Success();
    }

    /**
     * The rows the previous statement on this connection changed (ROW_COUNT()), or
     * null when that cannot be read.
     */
    private function lastStatementRowCount(): ?int
    {
        $this->db->Clear();
        $row = @$this->db->DataSet('SELECT ROW_COUNT() AS row_count');
        if (!$this->resultSucceeded($row) || !$row->Next()) {
            return null;
        }

        return (int) $row->row_count;
    }

    /**
     * Applies a list of schedule changes to one occurrence in a single transaction:
     * either every op is applied or none is.
     *
     * Ops: ['op' => 'add', 'cid' => string, 'item' => array],
     *      ['op' => 'update', 'id' => int, 'fields' => array, 'seen' => ?string] (only the keys sent change),
     *      ['op' => 'remove', 'id' => int].
     *
     * An update or remove of an item that is no longer on the occurrence is not an
     * error: its result carries 'gone' => true and the batch goes on. An update whose
     * 'seen' (the item's Modified as the caller last had it) is not the item's current
     * Modified is not applied: its result carries the current row and 'conflict' => true.
     *
     * With a 'BatchToken', the batch applies once: a repeat of the token returns the
     * first call's Results and changes nothing. A batch that fails stores nothing.
     * 'FailedIndex' is the refused op's index when an op's content was refused
     * (InvalidParameter), and -1 for anything else, a processing failure included.
     * An add that would take the occurrence past SCHEDULE_MAX_ITEMS items (counted at
     * the start, then kept up to date by the batch's own adds and removes) is refused
     * at its index.
     *
     * Each result's 'schedule' is the item as committed by this batch, or, for a
     * conflict, as the other save committed it; an item that a later op of the same
     * batch changes or removes is shown as the op that names it left it.
     *
     * @return array{Status: array, Results?: list<array{op: string, cid: string, id: int, schedule: ?array, gone?: bool, conflict?: bool}>, FailedIndex?: int}
     */
    public function ApplyScheduleOps(array $request): array
    {
        $mundaneId = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $eventId = (int) ($request['EventId'] ?? 0);
        $detailId = (int) ($request['EventCalendarDetailId'] ?? 0);
        $ops = $request['Ops'] ?? null;
        $batchToken = $request['BatchToken'] ?? null;

        if ($mundaneId <= 0) {
            return ['Status' => BadToken(), 'FailedIndex' => -1];
        }
        if (!valid_id($eventId) || !valid_id($detailId) || !is_array($ops) || !array_is_list($ops)
            || ($batchToken !== null && !$this->isRequestToken($batchToken))) {
            return ['Status' => InvalidParameter('Invalid parameters.'), 'FailedIndex' => -1];
        }
        $caps = $this->staffScheduleCaps($mundaneId, $eventId, $detailId);
        if (!$caps['can_schedule']) {
            return ['Status' => NoAuthorization(), 'FailedIndex' => -1];
        }
        if (!$this->detailBelongsToEvent($detailId, $eventId)) {
            return ['Status' => InvalidParameter('Invalid parameters.'), 'FailedIndex' => -1];
        }
        if (count($ops) > self::SCHEDULE_BATCH_MAX_OPS) {
            return ['Status' => InvalidParameter('Too many changes in one request.'), 'FailedIndex' => -1];
        }
        if ($ops === []) {
            return ['Status' => Success(), 'Results' => []];
        }
        if ($batchToken !== null) {
            $stored = $this->fetchScheduleRequestResult($detailId, $batchToken, 'batch');
            if ($stored !== null) {
                return ['Status' => Success(), 'Results' => $stored];
            }
            $this->pruneScheduleRequestTokens();
        }

        // The last op that names each existing item by id. A result row is read once,
        // after the loop, unless a later op of this batch changes or removes that item:
        // then it is read when its op runs, so it shows the item as that op left it.
        $lastTouch = [];
        $hasAdd = false;
        foreach ($ops as $index => $op) {
            $kind = is_array($op) ? ($op['op'] ?? null) : null;
            $hasAdd = $hasAdd || $kind === 'add';
            if ($kind === 'update' || $kind === 'remove') {
                $touchedId = $this->strictId($op['id'] ?? null);
                if ($touchedId > 0) {
                    $lastTouch[$touchedId] = $index;
                }
            }
        }

        $results = [];
        $written = [];
        $pending = [];
        $this->beginReadCommittedTrans();
        try {
            if ($batchToken !== null && !$this->claimScheduleRequestToken($detailId, $batchToken, 'batch')) {
                $this->db->RollbackTrans();
                // Another request with this token got there first: answer with what it stored.
                $stored = $this->fetchScheduleRequestResult($detailId, $batchToken, 'batch');
                return $stored !== null
                    ? ['Status' => Success(), 'Results' => $stored]
                    : ['Status' => ProcessingError('Could not save the schedule.'), 'FailedIndex' => -1];
            }
            // The ceiling: the items now on the occurrence, kept up to date through the batch.
            $itemCount = $hasAdd ? $this->countScheduleItems($detailId) : 0;
            if ($itemCount === null) {
                $this->db->RollbackTrans();
                return ['Status' => ProcessingError('Could not save the schedule.'), 'FailedIndex' => -1];
            }
            if ($hasAdd) {
                // Walk the ops in order with the running count before anything is written:
                // the first add that would pass the ceiling is refused at its index.
                $removable = [];
                foreach ($ops as $op) {
                    $removeId = (is_array($op) && ($op['op'] ?? null) === 'remove') ? $this->strictId($op['id'] ?? null) : 0;
                    if ($removeId > 0) {
                        $removable[$removeId] = false;
                    }
                }
                if ($removable !== []) {
                    $this->db->Clear();
                    $found = @$this->db->DataSet(
                        'SELECT event_schedule_id FROM ' . DB_PREFIX . 'event_schedule WHERE event_calendardetail_id = '
                        . $detailId . ' AND event_schedule_id IN (' . implode(',', array_map('intval', array_keys($removable))) . ')'
                    );
                    if (!$this->resultSucceeded($found)) {
                        $this->db->RollbackTrans();
                        return ['Status' => ProcessingError('Could not save the schedule.'), 'FailedIndex' => -1];
                    }
                    while ($found->Next()) {
                        $removable[(int) $found->event_schedule_id] = true;
                    }
                }
                $running = $itemCount;
                foreach ($ops as $index => $op) {
                    $kind = is_array($op) ? ($op['op'] ?? null) : null;
                    if ($kind === 'add') {
                        if ($running >= $this->scheduleMaxItems()) {
                            $this->db->RollbackTrans();
                            return ['Status' => $this->scheduleFullError(), 'FailedIndex' => $index];
                        }
                        $running++;
                    } elseif ($kind === 'remove') {
                        $removeId = $this->strictId($op['id'] ?? null);
                        if ($removeId > 0 && !empty($removable[$removeId])) {
                            $removable[$removeId] = false;
                            $running--;
                        }
                    }
                }
            }
            foreach ($ops as $index => $op) {
                $kind = is_array($op) ? ($op['op'] ?? null) : null;
                if ($kind === 'add' && $itemCount >= $this->scheduleMaxItems()) {
                    $this->db->RollbackTrans();
                    return ['Status' => $this->scheduleFullError(), 'FailedIndex' => $index];
                }
                [$error, $result, $readLater] = $this->applyScheduleOp(
                    is_array($op) ? $op : [],
                    $detailId,
                    $caps['can_feast'],
                    $written,
                    static fn (int $scheduleId): bool => ($lastTouch[$scheduleId] ?? -1) > $index
                );
                if ($error !== null) {
                    $this->db->RollbackTrans();
                    // A refusal of the op's content names the op. A processing failure (a
                    // failed read or write, a lock-wait timeout) says nothing about the op:
                    // the request may simply be sent again, with the same token.
                    $refused = ($error['Status'] ?? null) === ServiceErrorIds::InvalidParameter;
                    return ['Status' => $error, 'FailedIndex' => $refused ? $index : -1];
                }
                if ($readLater > 0) {
                    $pending[count($results)] = $readLater;
                }
                $results[] = $result;
                if ($kind === 'add') {
                    $itemCount++;
                } elseif ($kind === 'remove' && empty($result['gone'])) {
                    $itemCount--;
                }
            }
            if ($pending !== []) {
                // One read for every result row left to the end. Nothing else can have
                // changed these items meanwhile: this transaction holds their rows.
                $rows = [];
                foreach ($this->fetchScheduleList($detailId, array_values($pending)) as $row) {
                    $rows[$row['EventScheduleId']] = $row;
                }
                foreach ($pending as $resultIndex => $scheduleId) {
                    if (!isset($rows[$scheduleId])) {
                        $this->db->RollbackTrans();
                        return ['Status' => ProcessingError('Could not save the schedule.'), 'FailedIndex' => -1];
                    }
                    $results[$resultIndex]['schedule'] = $rows[$scheduleId];
                }
            }
            if ($batchToken !== null && !$this->storeScheduleRequestResult($detailId, $batchToken, $results)) {
                $this->db->RollbackTrans();
                return ['Status' => ProcessingError('Could not save the schedule.'), 'FailedIndex' => -1];
            }
        } catch (\Throwable $e) {
            $this->db->RollbackTrans();
            throw $e;
        }
        if (!$this->db->CommitTrans()) {
            return ['Status' => ProcessingError('Could not save the schedule.'), 'FailedIndex' => -1];
        }

        return ['Status' => Success(), 'Results' => $results];
    }

    /**
     * Opens the batch's transaction at READ COMMITTED (for that transaction only, the
     * way YapoMysql::BeginReadSnapshot sets its level), so every read in it sees what
     * other saves have committed. Under the server's REPEATABLE READ the batch would
     * read its first snapshot: a 'conflict' result could carry the row as it was
     * before the other save, and with innodb_snapshot_isolation (on by default in
     * current MariaDB) the locking read of a row committed after that snapshot fails
     * the batch instead of reporting the conflict. Inside a caller's transaction the
     * level cannot change, and the caller's is kept.
     */
    private function beginReadCommittedTrans(): void
    {
        if (!$this->db->InTrans()) {
            $this->db->Clear();
            $this->db->ExecuteChecked('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        $this->db->BeginTrans();
    }

    /** Whether $value is a well-formed BatchToken / RequestToken. */
    private function isRequestToken(mixed $value): bool
    {
        return is_string($value) && preg_match(self::SCHEDULE_REQUEST_TOKEN_RE, $value) === 1;
    }

    /**
     * The stored result of an earlier request with this token on this occurrence,
     * decoded, or null when there is none (or it was made by the other kind of request).
     */
    private function fetchScheduleRequestResult(int $detailId, string $token, string $kind): ?array
    {
        $this->db->Clear();
        $this->db->token = $token;
        $row = $this->db->DataSet(
            'SELECT kind, results FROM ' . DB_PREFIX . 'event_schedule_batch
             WHERE event_calendardetail_id = ' . $detailId . ' AND token = :token LIMIT 1'
        );
        if (!$row || !$row->Next() || (string) $row->kind !== $kind) {
            return null;
        }
        $decoded = json_decode((string) $row->results, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Opportunistic cleanup: request tokens are kept for one day. */
    private function pruneScheduleRequestTokens(): void
    {
        $this->db->Clear();
        $this->db->ExecuteChecked(
            'DELETE FROM ' . DB_PREFIX . 'event_schedule_batch WHERE created < NOW() - INTERVAL 1 DAY'
        );
    }

    /**
     * Claims the token inside the caller's transaction, before its writes, so a
     * concurrent request with the same token waits on the primary key and then
     * finds the stored result. False when the claim failed (normally: the token is
     * already taken); the caller rolls back and reads the stored result back.
     */
    private function claimScheduleRequestToken(int $detailId, string $token, string $kind): bool
    {
        $this->db->Clear();
        $this->db->token = $token;
        $this->db->kind = $kind;

        return (bool) @$this->db->ExecuteChecked(
            'INSERT INTO ' . DB_PREFIX . 'event_schedule_batch (event_calendardetail_id, token, kind, results)
             VALUES (' . $detailId . ', :token, :kind, \'\')'
        );
    }

    /** Stores the result of the request that claimed the token (inside its transaction). */
    private function storeScheduleRequestResult(int $detailId, string $token, array $result): bool
    {
        $json = json_encode($result, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return false;
        }
        $this->db->Clear();
        $this->db->token = $token;
        $this->db->results = $json;

        return $this->db->ExecuteChecked(
            'UPDATE ' . DB_PREFIX . 'event_schedule_batch SET results = :results
             WHERE event_calendardetail_id = ' . $detailId . ' AND token = :token'
        );
    }

    /**
     * The occurrence's schedule and staff for a caller who can manage the schedule.
     *
     * @return array{Status: array, ScheduleList?: list<array<string, mixed>>, StaffList?: list<array<string, mixed>>}
     */
    public function GetScheduleForManage(array $request): array
    {
        $mundaneId = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $eventId = (int) ($request['EventId'] ?? 0);
        $detailId = (int) ($request['EventCalendarDetailId'] ?? 0);

        if ($mundaneId <= 0) {
            return ['Status' => BadToken()];
        }
        if (!valid_id($eventId) || !valid_id($detailId)) {
            return ['Status' => InvalidParameter('Invalid parameters.')];
        }
        if (!$this->CanManageEventDetail($mundaneId, $eventId, $detailId, 'schedule')) {
            return ['Status' => NoAuthorization()];
        }
        if (!$this->detailBelongsToEvent($detailId, $eventId)) {
            return ['Status' => InvalidParameter('Invalid parameters.')];
        }

        return [
            'Status' => Success(),
            'ScheduleList' => $this->fetchScheduleList($detailId),
            'StaffList' => $this->fetchStaffList($detailId),
        ];
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

    /**
     * Occurrences whose schedule a schedule manager may copy into this one: first the
     * event's other occurrences (newest first), then the latest past occurrence of each
     * event ListCopySourceEvents offers for this event's kingdom and park. Only
     * occurrences with at least one schedule item; at most 25.
     *
     * @return array{Status: array, Sources?: list<array{EventId: int, EventCalendarDetailId: int, Name: string, StartDate: string, ItemCount: int, SameEvent: bool}>}
     */
    public function ListScheduleCopySources(array $request): array
    {
        $mundaneId = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $eventId = (int) ($request['EventId'] ?? 0);
        $detailId = (int) ($request['EventCalendarDetailId'] ?? 0);

        if ($mundaneId <= 0) {
            return ['Status' => BadToken()];
        }
        if (!valid_id($eventId) || !valid_id($detailId)) {
            return ['Status' => InvalidParameter('Invalid parameters.')];
        }
        if (!$this->staffScheduleCaps($mundaneId, $eventId, $detailId)['can_schedule']) {
            return ['Status' => NoAuthorization()];
        }
        if (!$this->detailBelongsToEvent($detailId, $eventId)) {
            return ['Status' => InvalidParameter('Invalid parameters.')];
        }

        return ['Status' => Success(), 'Sources' => $this->scheduleCopySources($eventId, $detailId)];
    }

    /**
     * Appends a copy of another occurrence's schedule to this one, every item moved by
     * the whole days between the two occurrences' start dates (clock times kept).
     * Feast items are skipped, and counted, for a caller who cannot manage feast.
     * A copied item left with no lead gets all of this occurrence's category leads for
     * its primary category, as auto-filled leads, as an add without 'Leads' would.
     *
     * With a 'RequestToken', the copy applies once: a repeat of the token answers
     * with the first call's items (as they are now) and copies nothing.
     *
     * @return array{Status: array, Schedule?: list<array<string, mixed>>, SkippedFeast?: int}
     */
    public function CopyScheduleInto(array $request): array
    {
        $mundaneId = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $eventId = (int) ($request['EventId'] ?? 0);
        $detailId = (int) ($request['EventCalendarDetailId'] ?? 0);
        $sourceId = $this->strictId($request['SourceDetailId'] ?? null);
        $requestToken = $request['RequestToken'] ?? null;

        if ($mundaneId <= 0) {
            return ['Status' => BadToken()];
        }
        if (!valid_id($eventId) || !valid_id($detailId) || $sourceId === 0
            || ($requestToken !== null && !$this->isRequestToken($requestToken))) {
            return ['Status' => InvalidParameter('Invalid parameters.')];
        }
        $caps = $this->staffScheduleCaps($mundaneId, $eventId, $detailId);
        if (!$caps['can_schedule']) {
            return ['Status' => NoAuthorization()];
        }
        if (!$this->detailBelongsToEvent($detailId, $eventId)) {
            return ['Status' => InvalidParameter('Invalid parameters.')];
        }
        if ($requestToken !== null) {
            $stored = $this->fetchScheduleRequestResult($detailId, $requestToken, 'copy');
            if ($stored !== null) {
                return $this->copyResponse($detailId, $stored);
            }
        }
        $allowed = array_column($this->scheduleCopySources($eventId, $detailId), 'EventCalendarDetailId');
        if (!in_array($sourceId, $allowed, true)) {
            return ['Status' => NoAuthorization()];
        }

        $sourceStart = $this->fetchDetailStart($sourceId);
        $targetStart = $this->fetchDetailStart($detailId);
        $shiftDays = ($sourceStart !== null && $targetStart !== null)
            ? $this->calendarDaysBetween($sourceStart, $targetStart) : null;
        if ($shiftDays === null) {
            return ['Status' => InvalidParameter('The occurrence dates could not be read.')];
        }
        if ($requestToken !== null) {
            $this->pruneScheduleRequestTokens();
        }

        $skippedFeast = 0;
        $mod = ['schedule' => true, 'feast' => $caps['can_feast'], 'meals' => $caps['can_feast']];
        $this->db->BeginTrans();
        try {
            if ($requestToken !== null && !$this->claimScheduleRequestToken($detailId, $requestToken, 'copy')) {
                $this->db->RollbackTrans();
                // Another request with this token got there first: answer with what it stored.
                $stored = $this->fetchScheduleRequestResult($detailId, $requestToken, 'copy');
                return $stored !== null
                    ? $this->copyResponse($detailId, $stored)
                    : ['Status' => ProcessingError('Could not copy the schedule.')];
            }
            $itemCount = $this->countScheduleItems($detailId);
            if ($itemCount === null) {
                throw new \RuntimeException('Could not count the schedule.');
            }
            $roomLeft = max(0, $this->scheduleMaxItems() - $itemCount);
            $newIds = $this->copyScheduleModules($sourceId, $detailId, $mod, $shiftDays, null, $skippedFeast, $roomLeft);
            if ($newIds === null) {
                // Nothing was written; the rollback also frees the request token.
                $this->db->RollbackTrans();
                return ['Status' => InvalidParameter(
                    'That would put more than ' . $this->scheduleMaxItems() . ' items on this schedule.'
                )];
            }
            $this->demoteForeignAutoLeads($detailId, $newIds);
            $this->fillCopiedItemLeads($detailId, $newIds);
            $stored = ['Ids' => $newIds, 'SkippedFeast' => $skippedFeast];
            if ($requestToken !== null && !$this->storeScheduleRequestResult($detailId, $requestToken, $stored)) {
                throw new \RuntimeException('Could not store the copy token.');
            }
        } catch (\RuntimeException $e) {
            $this->db->RollbackTrans();
            return ['Status' => ProcessingError('Could not copy the schedule.')];
        } catch (\Throwable $e) {
            $this->db->RollbackTrans();
            throw $e;
        }
        if (!$this->db->CommitTrans()) {
            return ['Status' => ProcessingError('Could not copy the schedule.')];
        }

        return $this->copyResponse($detailId, $stored);
    }

    /**
     * CopyScheduleInto's answer from what it stores: the copied items still on the
     * occurrence, in copy order, and the count of feast items skipped.
     *
     * @param array{Ids?: list<int>, SkippedFeast?: int} $stored
     */
    private function copyResponse(int $detailId, array $stored): array
    {
        $byId = [];
        foreach ($this->fetchScheduleList($detailId) as $row) {
            $byId[$row['EventScheduleId']] = $row;
        }
        $schedule = [];
        foreach (is_array($stored['Ids'] ?? null) ? $stored['Ids'] : [] as $newId) {
            if (isset($byId[(int) $newId])) {
                $schedule[] = $byId[(int) $newId];
            }
        }

        return ['Status' => Success(), 'Schedule' => $schedule, 'SkippedFeast' => (int) ($stored['SkippedFeast'] ?? 0)];
    }

    /**
     * Copied items left with no lead at all get every one of the occurrence's category
     * leads for their primary category, as auto-filled leads: the same leads an add
     * without a 'Leads' key gets (applyCategoryLeads).
     *
     * @param list<int> $scheduleIds the copied items, all on $detailId
     * @throws \RuntimeException when a write fails
     */
    private function fillCopiedItemLeads(int $detailId, array $scheduleIds): void
    {
        $scheduleIds = array_values(array_filter(array_map('intval', $scheduleIds), 'valid_id'));
        if ($scheduleIds === []) {
            return;
        }
        $this->db->Clear();
        $rows = $this->db->DataSet(
            'SELECT s.event_schedule_id, s.category FROM ' . DB_PREFIX . 'event_schedule s
             WHERE s.event_calendardetail_id = ' . $detailId . '
               AND s.event_schedule_id IN (' . implode(',', $scheduleIds) . ')
               AND NOT EXISTS (SELECT 1 FROM ' . DB_PREFIX . 'event_schedule_lead sl
                               WHERE sl.event_schedule_id = s.event_schedule_id)'
        );
        $unled = [];
        while ($rows && $rows->Next()) {
            $unled[(int) $rows->event_schedule_id] = (string) $rows->category;
        }
        if ($unled === []) {
            return;
        }
        // The occurrence's category leads read once for every item, written in one statement.
        $leadMap = $this->categoryLeadMap($detailId);
        $leadRows = [];
        foreach ($unled as $scheduleId => $category) {
            foreach ($leadMap[$category] ?? [] as $leadId) {
                $leadRows[] = [$scheduleId, $leadId, 1];
            }
        }
        if (!$this->insertScheduleLeadRows($leadRows, true)) {
            throw new \RuntimeException('Could not copy a schedule lead.');
        }
    }

    /**
     * A lead copied in from another occurrence stays auto-filled only when that person
     * leads the item's primary category on this occurrence; otherwise it is stored as
     * hand-picked, so a later staff change here never removes it.
     *
     * @param list<int> $scheduleIds the copied items, all on $detailId
     * @throws \RuntimeException when the write fails
     */
    private function demoteForeignAutoLeads(int $detailId, array $scheduleIds): void
    {
        $scheduleIds = array_values(array_filter(array_map('intval', $scheduleIds), 'valid_id'));
        if ($scheduleIds === []) {
            return;
        }
        $this->db->Clear();
        if (!$this->db->ExecuteChecked(
            'UPDATE ' . DB_PREFIX . 'event_schedule_lead sl
             JOIN ' . DB_PREFIX . 'event_schedule s ON s.event_schedule_id = sl.event_schedule_id
             SET sl.is_auto = 0
             WHERE sl.event_schedule_id IN (' . implode(',', $scheduleIds) . ')
               AND s.event_calendardetail_id = ' . $detailId . ' AND sl.is_auto = 1
               AND NOT EXISTS (
                   SELECT 1 FROM ' . DB_PREFIX . 'event_staff st
                   JOIN ' . DB_PREFIX . 'event_staff_category sc ON sc.event_staff_id = st.event_staff_id
                   WHERE st.event_calendardetail_id = ' . $detailId . '
                     AND st.mundane_id = sl.mundane_id AND sc.category = s.category
               )'
        )) {
            throw new \RuntimeException('Could not copy a schedule lead.');
        }
    }

    /**
     * The copy sources for a target occurrence (see ListScheduleCopySources); no access check.
     *
     * @return list<array{EventId: int, EventCalendarDetailId: int, Name: string, StartDate: string, ItemCount: int, SameEvent: bool}>
     */
    private function scheduleCopySources(int $eventId, int $detailId): array
    {
        // CopyScheduleInto accepts only sources in this list, so a same-event occurrence beyond the cap cannot be copied from.
        $limit = 25;
        $this->db->Clear();
        $eventRow = $this->db->DataSet(
            'SELECT name, kingdom_id, park_id FROM ' . DB_PREFIX . 'event WHERE event_id = ' . $eventId . ' LIMIT 1'
        );
        if (!$eventRow || !$eventRow->Next()) {
            return [];
        }
        $eventName = (string) $eventRow->name;
        $kingdomId = (int) $eventRow->kingdom_id;
        $parkId = (int) $eventRow->park_id;

        $sources = [];
        $this->db->Clear();
        $sameRs = $this->db->DataSet(
            'SELECT cd.event_calendardetail_id, cd.event_start, COUNT(s.event_schedule_id) AS item_count
             FROM ' . DB_PREFIX . 'event_calendardetail cd
             JOIN ' . DB_PREFIX . 'event_schedule s ON s.event_calendardetail_id = cd.event_calendardetail_id
             WHERE cd.event_id = ' . $eventId . ' AND cd.event_calendardetail_id != ' . $detailId . '
             GROUP BY cd.event_calendardetail_id, cd.event_start
             ORDER BY cd.event_start DESC, cd.event_calendardetail_id DESC
             LIMIT ' . $limit
        );
        while ($sameRs && $sameRs->Next()) {
            $sources[] = [
                'EventId' => $eventId,
                'EventCalendarDetailId' => (int) $sameRs->event_calendardetail_id,
                'Name' => $eventName,
                'StartDate' => substr((string) $sameRs->event_start, 0, 10),
                'ItemCount' => (int) $sameRs->item_count,
                'SameEvent' => true,
            ];
        }
        if (count($sources) >= $limit) {
            return $sources;
        }

        $offered = $this->ListCopySourceEvents([
            'KingdomId' => $kingdomId,
            'ParkId' => $parkId,
            'ExcludeEventId' => $eventId,
        ]);
        $names = [];
        foreach ($offered['Results'] ?? [] as $result) {
            $names[(int) $result['eventId']] = (string) $result['name'];
        }
        if ($names === []) {
            return $sources;
        }

        $this->db->Clear();
        $latestRs = $this->db->DataSet(
            'SELECT cd.event_id, cd.event_calendardetail_id, cd.event_start,
                    (SELECT COUNT(*) FROM ' . DB_PREFIX . 'event_schedule s
                     WHERE s.event_calendardetail_id = cd.event_calendardetail_id) AS item_count
             FROM ' . DB_PREFIX . 'event_calendardetail cd
             WHERE cd.event_id IN (' . implode(',', array_map('intval', array_keys($names))) . ')
               AND cd.event_start < NOW()
             ORDER BY cd.event_start DESC, cd.event_calendardetail_id DESC'
        );
        $latest = [];
        while ($latestRs && $latestRs->Next()) {
            $otherId = (int) $latestRs->event_id;
            if (isset($latest[$otherId])) {
                continue;
            }
            $latest[$otherId] = [
                'EventId' => $otherId,
                'EventCalendarDetailId' => (int) $latestRs->event_calendardetail_id,
                'Name' => $names[$otherId],
                'StartDate' => substr((string) $latestRs->event_start, 0, 10),
                'ItemCount' => (int) $latestRs->item_count,
                'SameEvent' => false,
            ];
        }
        foreach (array_keys($names) as $otherId) {
            $candidate = $latest[$otherId] ?? null;
            if ($candidate === null || $candidate['ItemCount'] < 1) {
                continue;
            }
            $sources[] = $candidate;
            if (count($sources) >= $limit) {
                break;
            }
        }

        return $sources;
    }

    /** The occurrence's event_start as stored, or null when it does not exist. */
    private function fetchDetailStart(int $detailId): ?string
    {
        $this->db->Clear();
        $row = $this->db->DataSet(
            'SELECT event_start FROM ' . DB_PREFIX . 'event_calendardetail
             WHERE event_calendardetail_id = ' . $detailId . ' LIMIT 1'
        );

        return ($row && $row->Next()) ? (string) $row->event_start : null;
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
            try {
                $this->copyScheduleModules($srcDetailId, $newDetailId, $mod, 0, $deltaSeconds);
            } catch (\RuntimeException $e) {
                $this->rollbackCopiedEvent($newEventId);
                return ProcessingError('Failed to copy the schedule.');
            }
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

    /**
     * The schedule form's save (EventAjax add_schedule / update_schedule), as a thin
     * wrapper over the batch's op writer. It keeps only what is the form's own: the
     * caller's rights (schedule managers, event editors, and a feast editor's rules),
     * the form's fields turned into one op, and the op's result turned into the reply
     * the form reads. The content rules (strict times, the event window, end not before
     * start, text limits, the lead rules), the item ceiling, the 'Seen' check and every
     * write are the batch's, run through applyScheduleOp in one transaction opened as
     * the batch's is.
     *
     * A feast editor (can_feast without can_schedule) may add a feast item (judged by
     * the posted categories), and update an item stored as one (judged under the row
     * lock, so a concurrent change of category cannot slip past) that stays one.
     */
    private function saveEventSchedule($request, bool $isUpdate)
    {
        $mundaneId = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
        $eventId = (int) ($request['EventId'] ?? 0);
        $detailId = (int) ($request['EventCalendarDetailId'] ?? 0);
        $scheduleId = (int) ($request['ScheduleId'] ?? 0);

        if ($mundaneId <= 0) {
            return BadToken();
        }
        if (!valid_id($eventId) || !valid_id($detailId) || !$this->detailBelongsToEvent($detailId, $eventId)) {
            return InvalidParameter('Invalid Event ID.');
        }
        if ($isUpdate && !valid_id($scheduleId)) {
            return InvalidParameter('Invalid parameters.');
        }

        $caps = $this->staffScheduleCaps($mundaneId, $eventId, $detailId);
        if (Ork3::$Lib->authorization->HasAuthority($mundaneId, AUTH_EVENT, $eventId, AUTH_EDIT)) {
            $caps = ['can_schedule' => true, 'can_feast' => true];
        }
        $feastEditor = !$caps['can_schedule'];
        $postedFeast = $this->isFeastItem(
            $this->coerceScheduleCategory($this->scheduleText($request['Category'] ?? 'Other')),
            $this->coerceSecondaryCategory($this->scheduleText($request['SecondaryCategory'] ?? ''))
        );
        if ($feastEditor && (!$caps['can_feast'] || (!$isUpdate && !$postedFeast))) {
            return NoAuthorization();
        }

        // The form's fields as one op, by the batch's names. The form writes every
        // field it posts (one it leaves out is written empty, as it always was); the
        // meal fields only for a caller with feast rights. Leads: sent (even an empty
        // list) means stored as sent; not sent means the category's lead rules apply.
        $fields = [
            'Title' => $request['Title'] ?? '',
            'StartTime' => $request['StartTime'] ?? '',
            'EndTime' => $request['EndTime'] ?? '',
            'Location' => $request['Location'] ?? '',
            'Description' => $request['Description'] ?? '',
            'Category' => $request['Category'] ?? 'Other',
            'SecondaryCategory' => $request['SecondaryCategory'] ?? '',
        ];
        if ($caps['can_feast']) {
            foreach (array_keys(self::SCHEDULE_MEAL_FIELDS) as $key) {
                $fields[$key] = $request[$key] ?? '';
            }
        }
        if (array_key_exists('Leads', $request)) {
            $fields['Leads'] = is_array($request['Leads']) ? $request['Leads'] : [];
        }
        if ($isUpdate) {
            $op = ['op' => 'update', 'id' => $scheduleId, 'fields' => $fields];
            // Seen: the item's Modified when the form opened; none (or '') saves unchecked.
            $seen = $request['Seen'] ?? null;
            if ($seen !== null && $seen !== '') {
                $op['seen'] = $seen;
            }
        } else {
            $op = ['op' => 'add', 'item' => $fields];
        }

        $this->beginReadCommittedTrans();
        try {
            [$error, $row] = $this->applyFormScheduleOp($op, $detailId, $caps['can_feast'], $feastEditor, $postedFeast);
        } catch (\Throwable $e) {
            $this->db->RollbackTrans();
            throw $e;
        }
        if ($error !== null) {
            $this->db->RollbackTrans();
            return $error;
        }
        if (!$this->db->CommitTrans()) {
            return ProcessingError('Could not save the schedule.');
        }

        // The item as saved, its leads and Modified (its new version) included.
        return ['Status' => Success(), 'Schedule' => $row];
    }

    /**
     * The form's one op, inside saveEventSchedule's transaction: the feast editor's
     * check of the stored item (on the locking read, which holds the row to the end
     * of the transaction), the ceiling for an add, the op itself, and its result read
     * as the form's reply row.
     *
     * @return array{0: ?array, 1: ?array} [error status or null, the saved item in the fetchScheduleList shape]
     */
    private function applyFormScheduleOp(array $op, int $detailId, bool $canFeast, bool $feastEditor, bool $postedFeast): array
    {
        if ($op['op'] === 'update' && $feastEditor) {
            $stored = $this->fetchScheduleTimesOnDetail($op['id'], $detailId);
            if ($stored === false) {
                return [ProcessingError('Could not save the schedule.'), null];
            }
            if ($stored === null) {
                return [InvalidParameter('Schedule item not found.'), null];
            }
            if (!$this->isFeastItem($stored['category'], $stored['secondary_category'])) {
                return [NoAuthorization(), null];
            }
            if (!$postedFeast) {
                return [InvalidParameter('A feast item must stay in ' . self::FEAST_CATEGORY . '.'), null];
            }
        }
        if ($op['op'] === 'add') {
            $itemCount = $this->countScheduleItems($detailId);
            if ($itemCount === null) {
                return [ProcessingError('Could not save the schedule.'), null];
            }
            if ($itemCount >= $this->scheduleMaxItems()) {
                return [$this->scheduleFullError(), null];
            }
        }

        $written = [];
        [$error, $result] = $this->applyScheduleOp($op, $detailId, $canFeast, $written, static fn (int $id): bool => false);
        if ($error !== null) {
            return [$error, null];
        }
        if (!empty($result['conflict'])) {
            return [InvalidParameter(self::SCHEDULE_CHANGED_ELSEWHERE), null];
        }
        if (!empty($result['gone'])) {
            return [InvalidParameter('Schedule item not found.'), null];
        }
        $row = $this->fetchScheduleRowForResult((int) $result['id'], $detailId);

        return $row !== null ? [null, $row] : [ProcessingError('Could not save the schedule.'), null];
    }

    /**
     * One batch op, run inside ApplyScheduleOps's transaction. $written collects the
     * ids this batch has added or updated, so a later update to one of them skips the
     * 'seen' check.
     *
     * A result that carries the item's row ('schedule') has it read here only when
     * $readNow says a later op of the batch changes that item; otherwise 'schedule' is
     * left null and the item's id is returned for the caller to read after the loop.
     *
     * @param array<int, bool> $written
     * @param callable(int): bool $readNow
     * @return array{0: ?array, 1: ?array, 2: int} [error status or null, result or null, id whose row the caller reads, or 0]
     */
    private function applyScheduleOp(array $op, int $detailId, bool $canFeast, array &$written, callable $readNow): array
    {
        $kind = $op['op'] ?? null;
        $cid = is_scalar($op['cid'] ?? null) ? (string) $op['cid'] : '';

        if ($kind === 'add') {
            if (!is_array($op['item'] ?? null)) {
                return [InvalidParameter('Invalid parameters.'), null, 0];
            }
            [$error, $scheduleId] = $this->insertScheduleItem($detailId, $op['item'], $canFeast);
        } elseif ($kind === 'update' || $kind === 'remove') {
            $scheduleId = $this->strictId($op['id'] ?? null);
            if ($scheduleId === 0) {
                return [InvalidParameter('Invalid parameters.'), null, 0];
            }
            $stored = $this->fetchScheduleTimesOnDetail($scheduleId, $detailId);
            if ($stored === false) {
                // The read failed (a deadlock, a lock-wait timeout): not an absent item.
                return [ProcessingError('Could not save the schedule.'), null, 0];
            }
            if ($stored === null) {
                // An item of another occurrence was never this one's to change; one
                // that no longer exists was removed meanwhile, which is not an error.
                $exists = $this->scheduleIdExists($scheduleId);
                if ($exists === null) {
                    return [ProcessingError('Could not save the schedule.'), null, 0];
                }
                if ($exists) {
                    return [InvalidParameter('Schedule item not found.'), null, 0];
                }
                return [null, ['op' => $kind, 'cid' => $cid, 'id' => $scheduleId, 'schedule' => null, 'gone' => true], 0];
            }
            if ($kind === 'remove') {
                $error = $this->deleteScheduleItem($scheduleId, $detailId);
            } elseif (!is_array($op['fields'] ?? null)) {
                $error = InvalidParameter('Invalid parameters.');
            } else {
                $seen = $op['seen'] ?? null;
                if ($seen !== null && !isset($written[$scheduleId])
                    && (!is_string($seen) || $seen !== $stored['modified'])) {
                    // Changed by someone else since the caller last saw it: not applied.
                    // Its result carries the row as the other save committed it.
                    $result = ['op' => 'update', 'cid' => $cid, 'id' => $scheduleId, 'schedule' => null, 'conflict' => true];
                    return $this->withResultRow($result, $scheduleId, $detailId, $readNow);
                }
                $error = $this->updateScheduleItem($scheduleId, $detailId, $op['fields'], $stored, $canFeast);
            }
        } else {
            return [InvalidParameter('Unknown change type.'), null, 0];
        }
        if ($error !== null) {
            return [$error, null, 0];
        }

        $result = ['op' => $kind, 'cid' => $cid, 'id' => $scheduleId, 'schedule' => null];
        if ($kind === 'remove') {
            return [null, $result, 0];
        }
        $written[$scheduleId] = true;

        return $this->withResultRow($result, $scheduleId, $detailId, $readNow);
    }

    /**
     * An op's result with its item's row: read now when $readNow says a later op of
     * the batch changes the item, else left for the caller's single read after the loop.
     *
     * @param callable(int): bool $readNow
     * @return array{0: ?array, 1: ?array, 2: int} as applyScheduleOp
     */
    private function withResultRow(array $result, int $scheduleId, int $detailId, callable $readNow): array
    {
        if (!$readNow($scheduleId)) {
            return [null, $result, $scheduleId];
        }
        $result['schedule'] = $this->fetchScheduleRowForResult($scheduleId, $detailId);
        if ($result['schedule'] === null) {
            return [ProcessingError('Could not save the schedule.'), null, 0];
        }

        return [null, $result, 0];
    }

    /**
     * An add's leads: as sent (sanitised) when the item has a 'Leads' key, even an
     * empty one; otherwise the occurrence's category leads for its primary category.
     *
     * @return array{0: ?array, 1: int} [error status or null, new schedule id]
     */
    private function insertScheduleItem(int $detailId, array $item, bool $canFeast): array
    {
        $tooLong = $this->scheduleTextLimitError($item, $canFeast);
        if ($tooLong !== null) {
            return [$tooLong, 0];
        }
        $title = $this->scheduleText($item['Title'] ?? '');
        if ($title === '') {
            return [InvalidParameter('A title is required.'), 0];
        }
        [$timeError, $start, $end] = $this->normalizeBatchScheduleTimes(
            $this->scheduleText($item['StartTime'] ?? ''),
            $this->scheduleText($item['EndTime'] ?? ''),
            $detailId,
            true
        );
        if ($timeError !== null) {
            return [InvalidParameter($timeError), 0];
        }

        $values = [
            'event_calendardetail_id' => $detailId,
            'title' => $title,
            'start_time' => $start,
            'end_time' => $end,
            'location' => $this->scheduleText($item['Location'] ?? ''),
            'description' => $this->scheduleText($item['Description'] ?? ''),
            'category' => $this->coerceScheduleCategory($this->scheduleText($item['Category'] ?? 'Other')),
            'secondary_category' => $this->coerceSecondaryCategory($this->scheduleText($item['SecondaryCategory'] ?? '')),
        ];
        if ($canFeast) {
            foreach (self::SCHEDULE_MEAL_FIELDS as $key => $column) {
                $values[$column] = $this->normalizeMealField($key, $item[$key] ?? '');
            }
        }

        $this->db->Clear();
        foreach ($values as $column => $value) {
            $this->db->$column = $value;
        }
        $columns = array_keys($values);
        $ok = $this->db->ExecuteChecked(
            'INSERT INTO ' . DB_PREFIX . 'event_schedule (' . implode(', ', $columns) . ')
             VALUES (:' . implode(', :', $columns) . ')'
        );
        $scheduleId = $ok ? (int) $this->db->GetLastInsertId() : 0;
        if ($scheduleId <= 0) {
            return [ProcessingError('Could not save the schedule.'), 0];
        }

        $error = array_key_exists('Leads', $item)
            ? $this->replaceScheduleLeads($scheduleId, $item['Leads'], false, [$detailId, $values['category']])
            : $this->applyCategoryLeads($scheduleId, $detailId, $values['category'], false);
        if ($error !== null) {
            return [$error, 0];
        }

        return [null, $scheduleId];
    }

    /**
     * Writes only the fields present in $fields. Meal fields are skipped unless
     * $canFeast; when only one of start/end is sent, the order check uses the stored other.
     * Leads: as sent (sanitised) when $fields has 'Leads'; otherwise a change of
     * primary category swaps the item's auto-filled leads for the new category's
     * leads and keeps the hand-picked ones. Any change advances the row's `modified`.
     *
     * @param array{start_time: string, end_time: string, category: string, modified: string} $stored
     */
    private function updateScheduleItem(int $scheduleId, int $detailId, array $fields, array $stored, bool $canFeast): ?array
    {
        $tooLong = $this->scheduleTextLimitError($fields, $canFeast);
        if ($tooLong !== null) {
            return $tooLong;
        }
        $values = [];
        if (array_key_exists('Title', $fields)) {
            $values['title'] = $this->scheduleText($fields['Title']);
            if ($values['title'] === '') {
                return InvalidParameter('A title is required.');
            }
        }
        $hasStart = array_key_exists('StartTime', $fields);
        $hasEnd = array_key_exists('EndTime', $fields);
        if ($hasStart || $hasEnd) {
            [$timeError, $values['start_time'], $values['end_time']] = $this->normalizeBatchScheduleTimes(
                $hasStart ? $this->scheduleText($fields['StartTime']) : $stored['start_time'],
                $hasEnd ? $this->scheduleText($fields['EndTime']) : $stored['end_time'],
                $detailId,
                $hasStart
            );
            if ($timeError !== null) {
                return InvalidParameter($timeError);
            }
        }
        if (array_key_exists('Location', $fields)) {
            $values['location'] = $this->scheduleText($fields['Location']);
        }
        if (array_key_exists('Description', $fields)) {
            $values['description'] = $this->scheduleText($fields['Description']);
        }
        if (array_key_exists('Category', $fields)) {
            $values['category'] = $this->coerceScheduleCategory($this->scheduleText($fields['Category']));
        }
        if (array_key_exists('SecondaryCategory', $fields)) {
            $values['secondary_category'] = $this->coerceSecondaryCategory($this->scheduleText($fields['SecondaryCategory']));
        }
        if ($canFeast) {
            foreach (self::SCHEDULE_MEAL_FIELDS as $key => $column) {
                if (array_key_exists($key, $fields)) {
                    $values[$column] = $this->normalizeMealField($key, $fields[$key]);
                }
            }
        }
        $leadsSent = array_key_exists('Leads', $fields);
        $categoryChanged = isset($values['category']) && $values['category'] !== $stored['category'];

        if ($values !== [] || $leadsSent) {
            $this->db->Clear();
            $setParts = [];
            foreach ($values as $column => $value) {
                $this->db->$column = $value;
                $setParts[] = $column . ' = :' . $column;
            }
            $setParts[] = $this->scheduleModifiedBump();
            $ok = $this->db->ExecuteChecked(
                'UPDATE ' . DB_PREFIX . 'event_schedule SET ' . implode(', ', $setParts)
                . ' WHERE event_schedule_id = ' . $scheduleId . ' AND event_calendardetail_id = ' . $detailId
            );
            if (!$ok) {
                return ProcessingError('Could not save the schedule.');
            }
        }

        if ($leadsSent) {
            return $this->replaceScheduleLeads(
                $scheduleId,
                $fields['Leads'],
                true,
                [$detailId, $values['category'] ?? $stored['category']]
            );
        }
        if ($categoryChanged) {
            return $this->applyCategoryLeads($scheduleId, $detailId, $values['category'], true);
        }

        return null;
    }

    /**
     * The SET clause that advances an item's `modified`. The column has one-second
     * resolution and only moves by itself when a value changes, so two writes in the
     * same second, or a write that changes only the leads, would otherwise leave it as it was.
     */
    private function scheduleModifiedBump(): string
    {
        return 'modified = GREATEST(NOW(), COALESCE(modified, NOW()) + INTERVAL 1 SECOND)';
    }

    /**
     * Advances `modified` on items whose leads were changed outside a batch op (the
     * staff-side fill and removal rules). False when the write failed.
     *
     * @param list<int> $scheduleIds
     */
    private function bumpScheduleModified(array $scheduleIds): bool
    {
        $scheduleIds = array_values(array_unique(array_filter(array_map('intval', $scheduleIds), 'valid_id')));
        if ($scheduleIds === []) {
            return true;
        }
        $this->db->Clear();

        return $this->db->ExecuteChecked(
            'UPDATE ' . DB_PREFIX . 'event_schedule SET ' . $this->scheduleModifiedBump() . '
             WHERE event_schedule_id IN (' . implode(',', $scheduleIds) . ')'
        );
    }

    private function deleteScheduleItem(int $scheduleId, int $detailId): ?array
    {
        $this->db->Clear();
        $ok = $this->db->ExecuteChecked(
            'DELETE FROM ' . DB_PREFIX . 'event_schedule_lead WHERE event_schedule_id = ' . $scheduleId
        );
        if ($ok) {
            $this->db->Clear();
            $ok = $this->db->ExecuteChecked(
                'DELETE FROM ' . DB_PREFIX . 'event_schedule
                 WHERE event_schedule_id = ' . $scheduleId . ' AND event_calendardetail_id = ' . $detailId
            );
        }

        return $ok ? null : ProcessingError('Could not save the schedule.');
    }

    /**
     * Sets an item's leads to $leads (list of ['MundaneId' => int, 'Auto' => bool]),
     * sanitised by prepareScheduleLeads against the item's stored primary category;
     * $clearFirst drops the old ones. A caller that has just written the item (a batch
     * op, inside its transaction) passes its occurrence and stored primary category in
     * $known, which saves reading the item again.
     *
     * @param array{0: int, 1: string}|null $known [occurrence id, stored primary category]
     */
    private function replaceScheduleLeads(int $scheduleId, mixed $leads, bool $clearFirst, ?array $known = null): ?array
    {
        if ($known !== null) {
            [$detailId, $category] = $known;
        } else {
            $this->db->Clear();
            $itemRow = $this->db->DataSet(
                'SELECT event_calendardetail_id, category FROM ' . DB_PREFIX . 'event_schedule
                 WHERE event_schedule_id = ' . $scheduleId . ' LIMIT 1'
            );
            if (!$itemRow || !$itemRow->Next()) {
                return ProcessingError('Could not save the schedule.');
            }
            $detailId = (int) $itemRow->event_calendardetail_id;
            $category = (string) $itemRow->category;
        }

        [$error, $prepared] = $this->prepareScheduleLeads(
            $leads,
            $detailId,
            $category,
            $clearFirst ? $this->fetchScheduleLeadIds($scheduleId) : []
        );
        if ($error !== null) {
            return $error;
        }
        if ($clearFirst) {
            $this->db->Clear();
            if (!$this->db->ExecuteChecked(
                'DELETE FROM ' . DB_PREFIX . 'event_schedule_lead WHERE event_schedule_id = ' . $scheduleId
            )) {
                return ProcessingError('Could not save the schedule.');
            }
        }
        $rows = array_map(static fn (array $lead): array => [$scheduleId, $lead[0], $lead[1]], $prepared);
        if (!$this->insertScheduleLeadRows($rows, true)) {
            return ProcessingError('Could not save the schedule.');
        }

        return null;
    }

    /**
     * Leads from a request, ready to store: invalid ids dropped, each person once,
     * at most SCHEDULE_LEADS_MAX, every one an eligible player (a person who already
     * leads the item, in $existingIds, or who leads $category on the occurrence, may
     * be a lead even if not eligible), and
     * auto-filled only when asked for AND that person leads $category on the occurrence.
     *
     * @param list<int> $existingIds the item's current leads
     * @return array{0: ?array, 1: list<array{0: int, 1: int}>} [error status or null, [mundane id, is_auto] pairs]
     */
    private function prepareScheduleLeads(mixed $leads, int $detailId, string $category, array $existingIds): array
    {
        if (!is_array($leads)) {
            return [InvalidParameter('Invalid parameters.'), []];
        }
        $wanted = [];
        foreach ($leads as $lead) {
            $leadId = is_array($lead) ? $this->strictId($lead['MundaneId'] ?? null) : 0;
            if ($leadId === 0 || isset($wanted[$leadId])) {
                continue;
            }
            $wanted[$leadId] = $this->leadIsAuto($lead);
        }
        if (count($wanted) > self::SCHEDULE_LEADS_MAX) {
            return [InvalidParameter('Too many leads on one item.'), []];
        }
        $this->primeMundaneEligible(array_values(array_diff(array_keys($wanted), $existingIds)));
        // Read only when needed: for an auto flag, or for someone who is not eligible.
        $categoryLeads = null;
        $out = [];
        foreach ($wanted as $leadId => $askedAuto) {
            if (!in_array($leadId, $existingIds, true) && !$this->isMundaneEligible($leadId)) {
                // A category lead of the item's category was chosen through staff
                // management, and the server's own fills add them unchecked: the
                // client's preview of those leads must not be refused.
                $categoryLeads ??= $this->categoryLeadIds($detailId, $category);
                if (!in_array($leadId, $categoryLeads, true)) {
                    return [InvalidParameter('That player cannot be a lead.'), []];
                }
            }
            if ($askedAuto) {
                $categoryLeads ??= $this->categoryLeadIds($detailId, $category);
            }
            $out[] = [$leadId, ($askedAuto && in_array($leadId, $categoryLeads ?? [], true)) ? 1 : 0];
        }

        return [null, $out];
    }

    /** @return list<int> the item's current leads */
    private function fetchScheduleLeadIds(int $scheduleId): array
    {
        $this->db->Clear();
        $rows = $this->db->DataSet(
            'SELECT mundane_id FROM ' . DB_PREFIX . 'event_schedule_lead WHERE event_schedule_id = ' . $scheduleId
        );
        $ids = [];
        while ($rows && $rows->Next()) {
            $ids[] = (int) $rows->mundane_id;
        }

        return $ids;
    }

    /**
     * Category leads of the occurrence: category name => the people who lead it, in
     * staff order.
     *
     * @return array<string, list<int>>
     */
    private function categoryLeadMap(int $detailId): array
    {
        $this->db->Clear();
        $rows = $this->db->DataSet(
            'SELECT st.mundane_id, sc.category FROM ' . DB_PREFIX . 'event_staff st
             JOIN ' . DB_PREFIX . 'event_staff_category sc ON sc.event_staff_id = st.event_staff_id
             WHERE st.event_calendardetail_id = ' . $detailId . '
             ORDER BY st.event_staff_id'
        );
        $map = [];
        while ($rows && $rows->Next()) {
            $leadId = (int) $rows->mundane_id;
            $category = (string) $rows->category;
            if (!in_array($leadId, $map[$category] ?? [], true)) {
                $map[$category][] = $leadId;
            }
        }

        return $map;
    }

    /** @return list<int> the people who lead $category on the occurrence */
    private function categoryLeadIds(int $detailId, string $category): array
    {
        return $this->categoryLeadMap($detailId)[$category] ?? [];
    }

    /**
     * Gives the item the occurrence's category leads for $category as auto-filled
     * leads. $dropAuto first removes the item's auto-filled leads (a category change);
     * hand-picked leads are kept, and a person already on the item stays as they are.
     */
    private function applyCategoryLeads(int $scheduleId, int $detailId, string $category, bool $dropAuto): ?array
    {
        if ($dropAuto) {
            $this->db->Clear();
            if (!$this->db->ExecuteChecked(
                'DELETE FROM ' . DB_PREFIX . 'event_schedule_lead WHERE event_schedule_id = ' . $scheduleId . ' AND is_auto = 1'
            )) {
                return ProcessingError('Could not save the schedule.');
            }
        }
        $rows = array_map(
            static fn (int $leadId): array => [$scheduleId, $leadId, 1],
            $this->categoryLeadIds($detailId, $category)
        );
        if (!$this->insertScheduleLeadRows($rows, true)) {
            return ProcessingError('Could not save the schedule.');
        }

        return null;
    }

    /**
     * Writes lead rows in one multi-row INSERT (per chunk of 500 rows); INSERT IGNORE
     * when $ignore, so a person already on the item stays as they are. True when there
     * was nothing to write. False when a statement failed.
     *
     * @param list<array{0: int, 1: int, 2: int}> $rows [schedule id, mundane id, is_auto]
     */
    private function insertScheduleLeadRows(array $rows, bool $ignore): bool
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            $values = array_map(
                static fn (array $row): string => '(' . (int) $row[0] . ', ' . (int) $row[1] . ', ' . ((int) $row[2] === 1 ? 1 : 0) . ')',
                $chunk
            );
            $this->db->Clear();
            if (!$this->db->ExecuteChecked(
                'INSERT ' . ($ignore ? 'IGNORE ' : '') . 'INTO ' . DB_PREFIX . 'event_schedule_lead (event_schedule_id, mundane_id, is_auto)
                 VALUES ' . implode(', ', $values)
            )) {
                return false;
            }
        }

        return true;
    }

    /** One item in the fetchScheduleList shape, or null when it is not on this occurrence. */
    private function fetchScheduleRowForResult(int $scheduleId, int $detailId): ?array
    {
        $rows = $this->fetchScheduleList($detailId, $scheduleId);

        return $rows[0] ?? null;
    }

    /**
     * A locking read (FOR UPDATE): inside a transaction it waits for any other
     * uncommitted write to the item, then returns the row as committed (or nothing,
     * if that write removed it), and holds the row until this transaction ends, so
     * the batch's 'seen' check cannot pass on a version another save is replacing.
     * Outside a transaction (the form's remove, re-reading an item it did not remove)
     * the lock ends with the statement.
     *
     * @return array{start_time: string, end_time: string, category: string, secondary_category: string, modified: string}|null|false
     *         null when the read succeeded and the item is not on this occurrence;
     *         false when the statement failed (a deadlock, a lock-wait timeout), which
     *         says nothing about whether the item exists
     */
    private function fetchScheduleTimesOnDetail(int $scheduleId, int $detailId): array|null|false
    {
        $this->db->Clear();
        // @: a failure is read from the result's SQLSTATE below; PDO's warning would
        // only be printed into the response.
        $row = @$this->db->DataSet(
            'SELECT start_time, end_time, category, secondary_category,
                    DATE_FORMAT(modified, \'%Y-%m-%d %H:%i:%s\') AS modified_at
             FROM ' . DB_PREFIX . 'event_schedule
             WHERE event_schedule_id = ' . $scheduleId . ' AND event_calendardetail_id = ' . $detailId . ' LIMIT 1
             FOR UPDATE'
        );
        if (!$this->resultSucceeded($row)) {
            return false;
        }
        if (!$row->Next()) {
            return null;
        }

        return [
            'start_time' => (string) $row->start_time,
            'end_time' => (string) $row->end_time,
            'category' => (string) $row->category,
            'secondary_category' => (string) ($row->secondary_category ?? ''),
            'modified' => (string) ($row->modified_at ?? ''),
        ];
    }

    /**
     * Whether a schedule item with this id exists on any occurrence; null when the
     * statement failed, which says nothing either way.
     */
    private function scheduleIdExists(int $scheduleId): ?bool
    {
        $this->db->Clear();
        $row = @$this->db->DataSet(
            'SELECT 1 AS found FROM ' . DB_PREFIX . 'event_schedule WHERE event_schedule_id = ' . $scheduleId . ' LIMIT 1'
        );
        if (!$this->resultSucceeded($row)) {
            return null;
        }

        return $row->Next();
    }

    /**
     * Whether a DataSet() result came from a statement that ran. DataSet() returns a
     * result even for a failed statement (PDO runs in ERRMODE_WARNING), which then
     * reads as no rows; its SQLSTATE, as ExecuteChecked reads it, tells the two apart.
     */
    private function resultSucceeded(mixed $result): bool
    {
        return is_object($result)
            && in_array($result->__ERROR[0][1] ?? null, ['00000', null], true);
    }

    /**
     * A refusal naming the first text field of a batch item or update that is longer
     * than it may be, or null. Meal fields count only when they would be written.
     */
    private function scheduleTextLimitError(array $src, bool $canFeast): ?array
    {
        foreach (self::SCHEDULE_TEXT_LIMITS as $key => [$max, $name]) {
            if (!array_key_exists($key, $src) || (!$canFeast && isset(self::SCHEDULE_MEAL_FIELDS[$key]))) {
                continue;
            }
            if (mb_strlen($this->scheduleText($src[$key])) > $max) {
                return InvalidParameter('The ' . $name . ' is longer than ' . $max . ' characters.');
            }
        }

        return null;
    }

    /**
     * Batch path: validates a start/end pair of naive 'Y-m-d H:i:s' or 'Y-m-d H:i'
     * strings (no time-zone conversion) and formats both as 'Y-m-d H:i:s'. The end may
     * equal the start (an item with no length) but not come before it. With
     * $checkWindow, the start must lie within SCHEDULE_WINDOW_DAYS of the occurrence's dates.
     *
     * @return array{0: ?string, 1: string, 2: string} [error message or null, start, end]
     */
    private function normalizeBatchScheduleTimes(string $start, string $end, int $detailId, bool $checkWindow): array
    {
        if ($start === '' || $end === '') {
            return ['Start and end times are required.', '', ''];
        }
        $startFmt = $this->parseNaiveScheduleTime($start);
        $endFmt = $this->parseNaiveScheduleTime($end);
        if ($startFmt === null || $endFmt === null) {
            return ['Invalid time format.', '', ''];
        }
        if ($endFmt < $startFmt) {
            return ['End time cannot be before start time.', '', ''];
        }
        if ($checkWindow) {
            $window = $this->scheduleWindow($detailId);
            if ($window === null) {
                return ['The occurrence dates could not be read.', '', ''];
            }
            if ($startFmt < $window[0] || $startFmt > $window[1]) {
                return ['That time is outside the event.', '', ''];
            }
        }

        return [null, $startFmt, $endFmt];
    }

    /** A naive 'Y-m-d H:i:s' or 'Y-m-d H:i' as 'Y-m-d H:i:s', or null when it is neither (or not a real time). */
    private function parseNaiveScheduleTime(string $value): ?string
    {
        $utc = new \DateTimeZone('UTC');
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $value, $utc);
            if ($parsed !== false && $parsed->format($format) === $value) {
                return $parsed->format('Y-m-d H:i:s');
            }
        }

        return null;
    }

    /** @var array<int, array{0: string, 1: string}|null> */
    private array $scheduleWindowCache = [];

    /**
     * Earliest and latest start a batch item may have on the occurrence, as naive
     * 'Y-m-d H:i:s': from 00:00 SCHEDULE_WINDOW_DAYS before its start date to 23:59:59
     * SCHEDULE_WINDOW_DAYS after its end date. An end date that cannot be used (a
     * zero date, or one before the start date, both common in old occurrences) is
     * replaced by the start date. Null only when the start date cannot be read.
     *
     * @return array{0: string, 1: string}|null
     */
    private function scheduleWindow(int $detailId): ?array
    {
        if (array_key_exists($detailId, $this->scheduleWindowCache)) {
            return $this->scheduleWindowCache[$detailId];
        }
        $this->db->Clear();
        $row = $this->db->DataSet(
            'SELECT event_start, event_end FROM ' . DB_PREFIX . 'event_calendardetail
             WHERE event_calendardetail_id = ' . $detailId . ' LIMIT 1'
        );
        $window = null;
        if ($row && $row->Next()) {
            $first = $this->parseOccurrenceDate((string) $row->event_start);
            if ($first !== null) {
                $last = $this->parseOccurrenceDate((string) $row->event_end);
                if ($last === null || $last < $first) {
                    $last = $first;
                }
                $days = self::SCHEDULE_WINDOW_DAYS;
                $window = [
                    $first->modify('-' . $days . ' days')->format('Y-m-d') . ' 00:00:00',
                    $last->modify('+' . $days . ' days')->format('Y-m-d') . ' 23:59:59',
                ];
            }
        }

        return $this->scheduleWindowCache[$detailId] = $window;
    }

    /**
     * The date part of an occurrence's DATETIME, or null when it is not a usable date:
     * createFromFormat reads '0000-00-00' as -0001-11-30 rather than failing, so a
     * year before 1000 counts as unreadable.
     */
    private function parseOccurrenceDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10), new \DateTimeZone('UTC'));

        return ($date !== false && (int) $date->format('Y') >= 1000) ? $date : null;
    }

    /** Unknown categories become 'Other'. */
    private function coerceScheduleCategory(string $category): string
    {
        return array_key_exists($category, self::SCHEDULE_CATEGORIES) ? $category : 'Other';
    }

    /** Unknown secondary categories become empty. */
    private function coerceSecondaryCategory(string $category): string
    {
        return array_key_exists($category, self::SCHEDULE_CATEGORIES) ? $category : '';
    }

    /** A meal field as stored: trimmed text or null; Cost is a 2-place number or null. */
    private function normalizeMealField(string $key, mixed $raw): string|float|null
    {
        $text = $this->scheduleText($raw);
        if ($key === 'Cost') {
            return ($text !== '' && is_numeric($text)) ? round((float) $text, 2) : null;
        }

        return $text !== '' ? $text : null;
    }

    /**
     * A strictly positive integer id from request data, or 0. Digit strings are
     * accepted; floats, exponents ("1e3"), decimals ("5.9") and signs are not.
     */
    private function strictId(mixed $value): int
    {
        if (is_int($value)) {
            return valid_id($value) ? $value : 0;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]{0,9}$/', $value) && (int) $value <= 2147483647) {
            return (int) $value;
        }

        return 0;
    }

    /** Whether a lead entry from a request is marked auto-filled. */
    private function leadIsAuto(mixed $lead): bool
    {
        return is_array($lead) && !empty($lead['Auto']) && $lead['Auto'] !== 'false';
    }

    /** Trimmed string for a scalar request value; '' for anything else. */
    private function scheduleText(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
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
        $staffId = (int) $priorRs->event_staff_id;
        $prior = [
            'event_staff_id' => $staffId,
            'role_name' => (string) $priorRs->role_name,
            'can_manage' => (int) $priorRs->can_manage,
            'can_attendance' => (int) $priorRs->can_attendance,
            'can_schedule' => (int) $priorRs->can_schedule,
            'can_feast' => (int) $priorRs->can_feast,
        ];
        $prior['lead_categories'] = $this->fetchStaffLeadCategories([$staffId])[$staffId] ?? [];

        return $prior;
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
        $prior = [
            'event_staff_id' => (int) $priorRs->event_staff_id,
            'mundane_id' => (int) $priorRs->mundane_id,
            'role_name' => (string) $priorRs->role_name,
            'can_manage' => (int) $priorRs->can_manage,
            'can_attendance' => (int) $priorRs->can_attendance,
            'can_schedule' => (int) $priorRs->can_schedule,
            'can_feast' => (int) $priorRs->can_feast,
        ];
        $prior['lead_categories'] = $this->fetchStaffLeadCategories([$staffId])[$staffId] ?? [];

        return $prior;
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

    /**
     * Copies the source occurrence's schedule items (and their eligible leads, keeping
     * is_auto) onto $newDetailId. $mod['schedule'] / $mod['feast'] pick which items;
     * $mod['meals'] (default true) copies menu, cost, dietary and allergens.
     * With $deltaSeconds null every time moves by $shiftDays calendar days with its
     * clock time kept; otherwise by $deltaSeconds seconds (CreateEventWithCopy).
     *
     * $skippedFeast receives the number of feast items left out because $mod['feast'] is off.
     * With $roomLeft, a copy that would add more items than that writes nothing and
     * returns null.
     *
     * @return list<int>|null the new schedule ids, in source start order; null when over $roomLeft
     * @throws \RuntimeException when a write fails
     */
    private function copyScheduleModules(
        int $srcDetailId,
        int $newDetailId,
        array $mod,
        int $shiftDays,
        ?int $deltaSeconds = null,
        ?int &$skippedFeast = null,
        ?int $roomLeft = null
    ): ?array {
        $skippedFeast = 0;
        $copyMeals = $mod['meals'] ?? true;
        $this->db->Clear();
        $schedRs = $this->db->DataSet(
            'SELECT * FROM ' . DB_PREFIX . 'event_schedule
             WHERE event_calendardetail_id = ' . $srcDetailId . ' ORDER BY start_time ASC, event_schedule_id ASC'
        );
        $sourceRows = [];
        while ($schedRs && $schedRs->Next()) {
            $sourceRows[] = [
                'id' => (int) $schedRs->event_schedule_id,
                'title' => (string) $schedRs->title,
                'start_time' => (string) $schedRs->start_time,
                'end_time' => (string) $schedRs->end_time,
                'location' => (string) $schedRs->location,
                'description' => (string) $schedRs->description,
                'category' => (string) $schedRs->category,
                'secondary_category' => (string) $schedRs->secondary_category,
                'menu' => $schedRs->menu,
                'cost' => $schedRs->cost,
                'dietary' => $schedRs->dietary,
                'allergens' => $schedRs->allergens,
            ];
        }

        // Every item to copy is worked out before any is written, so a copy over the
        // ceiling writes nothing.
        $planned = [];
        foreach ($sourceRows as $row) {
            $isFeast = ($row['category'] === 'Feast and Food' || $row['secondary_category'] === 'Feast and Food');
            if (!($isFeast ? !empty($mod['feast']) : !empty($mod['schedule']))) {
                if ($isFeast) {
                    $skippedFeast++;
                }
                continue;
            }

            if ($deltaSeconds === null) {
                $start = $this->shiftNaiveDays($row['start_time'], $shiftDays);
                $end = $this->shiftNaiveDays($row['end_time'], $shiftDays);
            } else {
                $st = strtotime($row['start_time']);
                $et = strtotime($row['end_time']);
                $start = $st ? date('Y-m-d H:i:s', $st + $deltaSeconds) : null;
                $end = $et ? date('Y-m-d H:i:s', $et + $deltaSeconds) : null;
            }
            if ($start === null || $end === null) {
                continue;
            }

            $values = [
                'event_calendardetail_id' => $newDetailId,
                'title' => $row['title'],
                'start_time' => $start,
                'end_time' => $end,
                'location' => $row['location'],
                'description' => $row['description'],
                'category' => $row['category'],
                'secondary_category' => $row['secondary_category'],
            ];
            if ($copyMeals) {
                foreach (['menu', 'dietary', 'allergens'] as $column) {
                    if ($row[$column] !== null) {
                        $values[$column] = (string) $row[$column];
                    }
                }
                if ($row['cost'] !== null && is_numeric($row['cost'])) {
                    $values['cost'] = round((float) $row['cost'], 2);
                }
            }
            $planned[] = [$row['id'], $values];
        }
        if ($roomLeft !== null && count($planned) > $roomLeft) {
            return null;
        }

        $newIds = [];
        $srcSchedIds = [];
        foreach ($planned as [$srcId, $values]) {
            $this->db->Clear();
            foreach ($values as $column => $value) {
                $this->db->$column = $value;
            }
            $columns = array_keys($values);
            $ok = $this->db->ExecuteChecked(
                'INSERT INTO ' . DB_PREFIX . 'event_schedule (' . implode(', ', $columns) . ')
                 VALUES (:' . implode(', :', $columns) . ')'
            );
            $newSchedId = $ok ? (int) $this->db->GetLastInsertId() : 0;
            if ($newSchedId <= 0) {
                throw new \RuntimeException('Could not copy a schedule item.');
            }
            $newIds[] = $newSchedId;
            $srcSchedIds[$srcId] = $newSchedId;
        }

        if ($srcSchedIds !== []) {
            $srcKeys = implode(',', array_map('intval', array_keys($srcSchedIds)));
            $this->db->Clear();
            $leadsRs = $this->db->DataSet(
                'SELECT event_schedule_id, mundane_id, is_auto FROM ' . DB_PREFIX . 'event_schedule_lead
                 WHERE event_schedule_id IN (' . $srcKeys . ')'
            );
            $leads = [];
            while ($leadsRs && $leadsRs->Next()) {
                $leads[] = [
                    $srcSchedIds[(int) $leadsRs->event_schedule_id] ?? 0,
                    (int) $leadsRs->mundane_id,
                    (int) $leadsRs->is_auto === 1 ? 1 : 0,
                ];
            }
            // Eligibility read for every lead in one statement, the leads written in one.
            $this->primeMundaneEligible(array_column($leads, 1));
            $leadRows = [];
            foreach ($leads as [$newSid, $mid, $isAuto]) {
                if ($newSid > 0 && $this->isMundaneEligible($mid)) {
                    $leadRows[] = [$newSid, $mid, $isAuto];
                }
            }
            if (!$this->insertScheduleLeadRows($leadRows, true)) {
                throw new \RuntimeException('Could not copy a schedule lead.');
            }
        }

        return $newIds;
    }

    /**
     * A naive 'Y-m-d H:i:s' moved by whole calendar days with the clock time kept,
     * or null when it does not parse. Pure date arithmetic, so clock changes do not apply.
     */
    private function shiftNaiveDays(string $naive, int $days): ?string
    {
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $naive, $m)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $m[1], new \DateTimeZone('UTC'));
        if ($date === false) {
            return null;
        }

        return $date->modify(sprintf('%+d days', $days))->format('Y-m-d') . ' ' . $m[2];
    }

    /** Whole calendar days from the date part of $from to the date part of $to (naive datetimes). */
    private function calendarDaysBetween(string $from, string $to): ?int
    {
        $utc = new \DateTimeZone('UTC');
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($from, 0, 10), $utc);
        $b = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($to, 0, 10), $utc);
        if ($a === false || $b === false) {
            return null;
        }

        return (int) $a->diff($b)->format('%r%a');
    }

    private function copyStaffModule(int $srcDetailId, int $newDetailId): void
    {
        $this->db->Clear();
        $staffRs = $this->db->DataSet(
            'SELECT event_staff_id, mundane_id, role_name, can_manage, can_attendance, can_schedule, can_feast
             FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . $srcDetailId
        );
        if ($staffRs) {
            while ($staffRs->Next()) {
                $mid = (int) $staffRs->mundane_id;
                $srcStaffId = (int) $staffRs->event_staff_id;
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
                $this->db->Clear();
                $newStaff = $this->db->DataSet(
                    'SELECT event_staff_id FROM ' . DB_PREFIX . 'event_staff
                     WHERE event_calendardetail_id = ' . $newDetailId . ' AND mundane_id = ' . $mid
                    . ' ORDER BY event_staff_id DESC LIMIT 1'
                );
                $newStaffId = ($newStaff && $newStaff->Next()) ? (int) $newStaff->event_staff_id : 0;
                if ($newStaffId > 0) {
                    $this->db->Clear();
                    $this->db->Execute(
                        'INSERT IGNORE INTO ' . DB_PREFIX . 'event_staff_category (event_staff_id, category)
                         SELECT ' . $newStaffId . ', category FROM ' . DB_PREFIX . 'event_staff_category
                         WHERE event_staff_id = ' . $srcStaffId
                    );
                }
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

        $this->primeMundaneEligible([$mundaneId]);

        return $this->mundaneEligibleCache[$mundaneId] ?? false;
    }

    /**
     * Reads, in one statement, whether each of these players may be a lead or be
     * copied as staff (active, and not suspended or suspended only until a past
     * day), for those not already known; isMundaneEligible() then answers from memory.
     *
     * @param list<int> $mundaneIds
     */
    private function primeMundaneEligible(array $mundaneIds): void
    {
        $ids = [];
        foreach ($mundaneIds as $mundaneId) {
            $mundaneId = (int) $mundaneId;
            if ($mundaneId > 0 && !array_key_exists($mundaneId, $this->mundaneEligibleCache)) {
                $ids[$mundaneId] = true;
            }
        }
        if ($ids === []) {
            return;
        }
        $this->db->Clear();
        $rows = $this->db->DataSet(
            'SELECT mundane_id, active, suspended, suspended_until FROM ' . DB_PREFIX . 'mundane
             WHERE mundane_id IN (' . implode(',', array_keys($ids)) . ')'
        );
        $found = [];
        while ($rows && $rows->Next()) {
            $ok = false;
            if ((int) $rows->active === 1) {
                if ((int) $rows->suspended !== 1) {
                    $ok = true;
                } else {
                    $until = $rows->suspended_until;
                    if ($until && strtotime((string) $until) !== false && strtotime((string) $until) < strtotime(date('Y-m-d'))) {
                        $ok = true;
                    }
                }
            }
            $found[(int) $rows->mundane_id] = $ok;
        }
        foreach (array_keys($ids) as $mundaneId) {
            $this->mundaneEligibleCache[$mundaneId] = $found[$mundaneId] ?? false;
        }
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
