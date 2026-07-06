<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_Event detail/create flows (T-EVT-03 through T-EVT-07).
 */
final class EventOccurrenceTest extends TestCase
{
    private EventPlanningFixture $fixture;

    private Event $eventDomain;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        unset($_SESSION['is_authorized_mundane_id']);

        $this->fixture = EventPlanningFixture::create();
        $this->eventDomain = new Event();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);

        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testGetOccurrenceDetailDto(): void
    {
        $ctx = $this->fixture->createPublishedEvent('occ-dto');
        $player = $this->fixture->createPlayer('occ-staff');
        $this->fixture->insertStaff($ctx['detail_id'], $player, 'Gate');
        $scheduleId = $this->fixture->insertSchedule($ctx['detail_id'], 'Court');
        $this->fixture->insertScheduleLead($scheduleId, $player);
        $this->fixture->insertFee($ctx['detail_id'], 'Adult', 10.0);
        $this->fixture->insertLink($ctx['detail_id'], 'Tickets', 'https://example.test/tix', 'fas fa-ticket-alt');

        $dto = $this->mirrorOccurrencePageData($ctx['detail_id']);

        $this->assertCount(1, $dto['staff']);
        $this->assertSame('Gate', $dto['staff'][0]['RoleName']);
        $this->assertCount(1, $dto['schedule']);
        $this->assertSame('Court', $dto['schedule'][0]['Title']);
        $this->assertCount(1, $dto['fees']);
        $this->assertSame('Adult', $dto['fees'][0]['AdmissionType']);
        $this->assertCount(1, $dto['links']);
        $this->assertSame('https://example.test/tix', $dto['links'][0]['Url']);
    }

    public function testResolveDefaultOccurrenceId(): void
    {
        $ctx = $this->fixture->createPublishedEvent('occ-pick');
        $futureId = $this->fixture->createDetailOnEvent(
            $ctx['event_id'],
            date('Y-m-d H:i:s', strtotime('+30 days')),
            date('Y-m-d H:i:s', strtotime('+30 days +6 hours')),
        );
        $pastId = $this->fixture->createDetailOnEvent(
            $ctx['event_id'],
            date('Y-m-d H:i:s', strtotime('-30 days')),
            date('Y-m-d H:i:s', strtotime('-30 days +6 hours')),
        );

        $picked = $this->mirrorResolveDefaultOccurrenceId($ctx['event_id']);
        $this->assertSame($ctx['detail_id'], $picked);

        global $DB;
        $DB->Clear();
        $DB->Execute(
            'DELETE FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_calendardetail_id = ' . (int) $ctx['detail_id']
        );

        $next = $this->mirrorResolveDefaultOccurrenceId($ctx['event_id']);
        $this->assertSame($futureId, $next);

        $DB->Clear();
        $DB->Execute(
            'DELETE FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_calendardetail_id = ' . (int) $futureId
        );

        $fallback = $this->mirrorResolveDefaultOccurrenceId($ctx['event_id']);
        $this->assertSame($pastId, $fallback);
    }

    public function testDetailOwnershipPreflight(): void
    {
        $ctxA = $this->fixture->createPublishedEvent('own-a');
        $ctxB = $this->fixture->createPublishedEvent('own-b');

        $this->assertTrue($this->mirrorDetailBelongsToEvent($ctxA['detail_id'], $ctxA['event_id']));
        $this->assertFalse($this->mirrorDetailBelongsToEvent($ctxB['detail_id'], $ctxA['event_id']));
    }

    public function testSetCalendarDetailFeesLinks(): void
    {
        $ctx = $this->fixture->createPublishedEvent('fees-links');
        $this->fixture->insertFee($ctx['detail_id'], 'Old', 5.0);

        $this->mirrorSetCalendarDetailFeesAndLinks(
            $ctx['detail_id'],
            [
                ['AdmissionType' => 'Member', 'Cost' => 8.5],
                ['AdmissionType' => 'Guest', 'Cost' => 12.0],
            ],
            [
                ['Title' => 'Site', 'Url' => 'https://example.test', 'Icon' => 'fas fa-globe'],
                ['Title' => 'Bad', 'Url' => 'javascript:alert(1)', 'Icon' => 'not-allowed'],
            ],
        );

        $fees = $this->fixture->fetchFees($ctx['detail_id']);
        $this->assertCount(2, $fees);
        $this->assertSame('Member', $fees[0]['AdmissionType']);
        $this->assertSame(8.5, $fees[0]['Cost']);

        $links = $this->fixture->fetchLinks($ctx['detail_id']);
        $this->assertCount(2, $links);
        $this->assertSame('https://example.test', $links[0]['Url']);
        $this->assertSame('', $links[1]['Url']);
        $this->assertSame('fas fa-link', $links[1]['Icon']);
    }

    public function testSetEventTypeDespiteAttendance(): void
    {
        $ctx = $this->fixture->createPublishedEvent('ev-type');
        $player = $this->fixture->createPlayer('ev-att');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_EVENT, $ctx['event_id'], AUTH_CREATE, 'ev-type-auth');
        unset($_SESSION['is_authorized_mundane_id']);

        $attendance = new Attendance();
        $add = $attendance->AddAttendance([
            'Token' => $grantor['token'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'MundaneId' => $player,
            'ClassId' => $this->fixture->firstClassId(),
            'Credits' => 1,
            'Date' => date('Y-m-d H:i:s', strtotime('+7 days')),
        ]);
        $this->assertSame(0, $add['Status']);
        if (!empty($add['Detail'])) {
            $this->fixture->trackAttendance((int) $add['Detail']);
        }

        $this->mirrorSetCalendarDetailEventType($ctx['detail_id'], 'Day Event');

        global $DB;
        $DB->Clear();
        $row = $DB->DataSet(
            'SELECT event_type FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_calendardetail_id = '
            . (int) $ctx['detail_id'] . ' LIMIT 1'
        );
        $this->assertTrue($row && $row->Next());
        $this->assertSame('Day Event', $row->event_type);
    }

    public function testReconcilePastAttendance(): void
    {
        $ctx = $this->fixture->createPublishedEvent('reconcile');
        $player = $this->fixture->createPlayer('reconcile-att');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_EVENT, $ctx['event_id'], AUTH_CREATE, 'reconcile-auth');
        unset($_SESSION['is_authorized_mundane_id']);

        global $DB;
        $pastStart = date('Y-m-d 12:00:00', strtotime('-14 days'));
        $pastEnd = date('Y-m-d 18:00:00', strtotime('-14 days'));
        $DB->Clear();
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'event_calendardetail SET event_start = \'' . $pastStart . '\', event_end = \''
            . $pastEnd . '\' WHERE event_calendardetail_id = ' . (int) $ctx['detail_id']
        );

        $attendance = new Attendance();
        $add = $attendance->AddAttendance([
            'Token' => $grantor['token'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'MundaneId' => $player,
            'ClassId' => $this->fixture->firstClassId(),
            'Credits' => 1,
            'Date' => $pastStart,
        ]);
        $this->assertSame(0, $add['Status']);
        if (!empty($add['Detail'])) {
            $this->fixture->trackAttendance((int) $add['Detail']);
        }

        $newDetailId = $this->mirrorReconcilePastAttendance($ctx['event_id'], $ctx['detail_id'], $grantor['token']);

        $this->assertGreaterThan(0, $newDetailId);
        $this->assertSame(0, $this->fixture->countAttendanceOnDetail($ctx['detail_id']));
        $this->assertGreaterThanOrEqual(1, $this->fixture->countAttendanceOnDetail($newDetailId));
    }

    public function testReconcileRejectsWithNoPastAttendance(): void
    {
        $ctx = $this->fixture->createPublishedEvent('reconcile-empty');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_EVENT, $ctx['event_id'], AUTH_CREATE, 'reconcile-empty-auth');

        $result = $this->mirrorReconcilePastAttendance($ctx['event_id'], $ctx['detail_id'], $grantor['token']);
        $this->assertSame(0, $result);
    }

    public function testGetDietarySummary(): void
    {
        $ctx = $this->fixture->createPublishedEvent('dietary');
        $player = $this->fixture->createPlayer('diet-player');
        $this->fixture->insertRsvp($ctx['detail_id'], $player, 'going');

        $summaryZero = $this->mirrorDietarySummary(0, true);
        $this->assertSame([], $summaryZero);

        $summary = $this->mirrorDietarySummary($ctx['detail_id'], true);
        $this->assertNotEmpty($summary);
        $this->assertSame($player, $summary[0]['MundaneId']);
    }

    public function testDraftOccurrenceHiddenFromAnonymous(): void
    {
        $ctx = $this->fixture->createPublishedEvent('draft-anon', 'draft');
        $blocked = $this->mirrorDraftBlocked(
            eventId: $ctx['event_id'],
            detailId: $ctx['detail_id'],
            uid: 0,
            eventStatus: 'draft',
            creatorId: $ctx['mundane_id'],
            canManage: false,
            staffCaps: [false, false, false, false],
        );
        $this->assertTrue($blocked);
    }

    public function testDraftVisibleToStaffDelegate(): void
    {
        $ctx = $this->fixture->createPublishedEvent('draft-staff', 'draft');
        $staff = $this->fixture->createGrantorWithoutAuth('draft-sched');
        $this->fixture->insertStaff($ctx['detail_id'], $staff['mundane_id'], 'Sched', canSchedule: true);

        $blocked = $this->mirrorDraftBlocked(
            eventId: $ctx['event_id'],
            detailId: $ctx['detail_id'],
            uid: $staff['mundane_id'],
            eventStatus: 'draft',
            creatorId: $ctx['mundane_id'],
            canManage: false,
            staffCaps: [false, false, true, false],
        );
        $this->assertFalse($blocked);
    }

    /**
     * @return array{staff: list<array<string, mixed>>, schedule: list<array<string, mixed>>, fees: list<array<string, mixed>>, links: list<array<string, mixed>>}
     */
    private function mirrorOccurrencePageData(int $detailId): array
    {
        global $DB;

        $DB->Clear();
        $staffRows = $DB->DataSet(
            'SELECT s.event_staff_id AS EventStaffId, s.mundane_id AS MundaneId, m.persona AS Persona,
                    s.role_name AS RoleName
             FROM ' . DB_PREFIX . 'event_staff s
             LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = s.mundane_id
             WHERE s.event_calendardetail_id = ' . $detailId . ' ORDER BY s.role_name, m.persona'
        );
        $staff = [];
        while ($staffRows && $staffRows->Next()) {
            $staff[] = [
                'EventStaffId' => (int) $staffRows->EventStaffId,
                'MundaneId' => (int) $staffRows->MundaneId,
                'Persona' => $staffRows->Persona,
                'RoleName' => $staffRows->RoleName,
            ];
        }

        $DB->Clear();
        $scheduleRows = $DB->DataSet(
            'SELECT event_schedule_id AS EventScheduleId, title AS Title
             FROM ' . DB_PREFIX . 'event_schedule
             WHERE event_calendardetail_id = ' . $detailId . ' ORDER BY start_time'
        );
        $schedule = [];
        while ($scheduleRows && $scheduleRows->Next()) {
            $schedule[] = [
                'EventScheduleId' => (int) $scheduleRows->EventScheduleId,
                'Title' => $scheduleRows->Title,
            ];
        }

        return [
            'staff' => $staff,
            'schedule' => $schedule,
            'fees' => $this->fixture->fetchFees($detailId),
            'links' => $this->fixture->fetchLinks($detailId),
        ];
    }

    private function mirrorResolveDefaultOccurrenceId(int $eventId): int
    {
        global $DB;
        $DB->Clear();
        $_cdRow = $DB->DataSet(
            'SELECT event_calendardetail_id FROM ' . DB_PREFIX . 'event_calendardetail
             WHERE event_id = ' . $eventId . ' AND event_start >= NOW()
             ORDER BY event_start ASC LIMIT 1'
        );
        if (!$_cdRow || !$_cdRow->Next()) {
            $DB->Clear();
            $_cdRow = $DB->DataSet(
                'SELECT event_calendardetail_id FROM ' . DB_PREFIX . 'event_calendardetail
                 WHERE event_id = ' . $eventId . ' ORDER BY event_start DESC LIMIT 1'
            );
            if ($_cdRow) {
                $_cdRow->Next();
            }
        }

        return ($_cdRow && isset($_cdRow->event_calendardetail_id))
            ? (int) $_cdRow->event_calendardetail_id
            : 0;
    }

    private function mirrorDetailBelongsToEvent(int $detailId, int $eventId): bool
    {
        global $DB;
        $DB->Clear();
        $_ownRow = $DB->DataSet(
            'SELECT event_id FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_calendardetail_id = '
            . $detailId . ' LIMIT 1'
        );

        return (bool) ($_ownRow && $_ownRow->Next() && (int) $_ownRow->event_id === $eventId);
    }

    /**
     * @param list<array{AdmissionType?: string, Cost?: float|int}> $feesIn
     * @param list<array{Title?: string, Url?: string, Icon?: string}> $linksIn
     */
    private function mirrorSetCalendarDetailFeesAndLinks(int $detailId, array $feesIn, array $linksIn): void
    {
        global $DB;
        $_allowedIcons = ['fab fa-facebook', 'fab fa-discord', 'fas fa-globe', 'far fa-clipboard', 'fas fa-link', 'fas fa-ticket-alt'];

        $DB->Clear();
        $DB->Execute('START TRANSACTION');
        $_feesOk = ($DB->Execute('DELETE FROM ' . DB_PREFIX . 'event_fees WHERE event_calendardetail_id = ' . $detailId) !== false);
        if ($_feesOk) {
            foreach ($feesIn as $_fi => $_fee) {
                $_at = trim($_fee['AdmissionType'] ?? '');
                $_atSafe = str_replace(["'", '\\'], ["''", '\\\\'], $_at);
                $_cost = round((float) ($_fee['Cost'] ?? 0), 2);
                $DB->Clear();
                if ($DB->Execute(
                    'INSERT INTO ' . DB_PREFIX . 'event_fees (event_calendardetail_id, admission_type, cost, sort_order) VALUES ('
                    . $detailId . ", '" . $_atSafe . "', " . $_cost . ', ' . $_fi . ')'
                ) === false) {
                    $_feesOk = false;
                    break;
                }
            }
        }
        $DB->Execute($_feesOk ? 'COMMIT' : 'ROLLBACK');

        $DB->Clear();
        $DB->Execute('START TRANSACTION');
        $_linksOk = ($DB->Execute('DELETE FROM ' . DB_PREFIX . 'event_links WHERE event_calendardetail_id = ' . $detailId) !== false);
        if ($_linksOk) {
            foreach ($linksIn as $_li => $_link) {
                $_lt = str_replace(["'", '\\'], ["''", '\\\\'], trim($_link['Title'] ?? ''));
                $_luRaw = trim($_link['Url'] ?? '');
                if ($_luRaw !== '') {
                    $_scheme = strtolower((string) parse_url($_luRaw, PHP_URL_SCHEME));
                    if (!in_array($_scheme, ['http', 'https', 'mailto'], true)) {
                        $_luRaw = '';
                    }
                }
                $_lu = str_replace(["'", '\\'], ["''", '\\\\'], $_luRaw);
                $_icRaw = trim($_link['Icon'] ?? '');
                if (!in_array($_icRaw, $_allowedIcons, true)) {
                    $_icRaw = 'fas fa-link';
                }
                $_lic = str_replace(["'", '\\'], ["''", '\\\\'], $_icRaw);
                $DB->Clear();
                if ($DB->Execute(
                    'INSERT INTO ' . DB_PREFIX . 'event_links (event_calendardetail_id, title, url, icon, sort_order) VALUES ('
                    . $detailId . ", '" . $_lt . "', '" . $_lu . "', '" . $_lic . "', " . $_li . ')'
                ) === false) {
                    $_linksOk = false;
                    break;
                }
            }
        }
        $DB->Execute($_linksOk ? 'COMMIT' : 'ROLLBACK');
    }

    private function mirrorSetCalendarDetailEventType(int $detailId, string $eventType): void
    {
        global $DB;
        $_etAllowed = ['Coronation', 'Midreign', 'Endreign', 'Crown Qualifications', 'Day Event', 'Park Raid', 'Meeting', 'Althing', 'Interkingdom Event', 'Weaponmaster', 'Warmaster', 'Dragonmaster', 'Other'];
        if (!in_array($eventType, $_etAllowed, true)) {
            return;
        }
        $_evTypeSql = "'" . str_replace(["'", '\\'], ["''", '\\\\'], $eventType) . "'";
        $DB->Clear();
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'event_calendardetail SET event_type = ' . $_evTypeSql
            . ' WHERE event_calendardetail_id = ' . $detailId
        );
    }

    private function mirrorReconcilePastAttendance(int $eventId, int $detailId, string $token): int
    {
        $attendanceModel = new Model_Attendance();
        $attData = $attendanceModel->get_attendance_for_event($eventId, $detailId);
        $today = date('Y-m-d');
        $pastAtt = array_filter(
            $attData['Attendance'] ?? [],
            static fn ($a) => !empty($a['Date']) && strtotime($a['Date']) < strtotime($today),
        );
        if (empty($pastAtt)) {
            return 0;
        }

        $cdInfo = $attendanceModel->get_eventdetail_info($detailId);
        $dates = array_map(static fn ($a) => strtotime($a['Date']), $pastAtt);
        $minDate = date('Y-m-d', min($dates)) . ' 12:00:00';
        $maxDate = date('Y-m-d', max($dates)) . ' 18:00:00';

        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->eventDomain->CreateEventDetails([
            'Token' => $token,
            'EventId' => $eventId,
            'AtParkId' => valid_id($cdInfo['AtParkId'] ?? 0) ? $cdInfo['AtParkId'] : null,
            'Current' => 0,
            'Price' => $cdInfo['Price'] ?? '',
            'EventStart' => $minDate,
            'EventEnd' => $maxDate,
            'Description' => $cdInfo['Description'] ?? '',
            'Url' => $cdInfo['Url'] ?? '',
            'UrlName' => $cdInfo['UrlName'] ?? '',
            'Address' => $cdInfo['Address'] ?? '',
            'Province' => $cdInfo['Province'] ?? '',
            'PostalCode' => $cdInfo['PostalCode'] ?? '',
            'City' => $cdInfo['City'] ?? '',
            'Country' => $cdInfo['Country'] ?? '',
            'MapUrl' => $cdInfo['MapUrl'] ?? '',
            'MapUrlName' => $cdInfo['MapUrlName'] ?? '',
        ]);
        if ($r['Status'] != 0) {
            return 0;
        }

        $newDetailId = (int) ($r['Detail'] ?? 0);
        if (!$newDetailId) {
            $fresh = $this->eventDomain->GetEventDetails(['EventId' => $eventId]);
            $all = $fresh['CalendarEventDetails'] ?? [];
            if ($all) {
                $newDetailId = max(array_map('intval', array_column($all, 'EventCalendarDetailId')));
            }
        }
        if (!$newDetailId) {
            return 0;
        }

        $this->fixture->trackDetail($newDetailId);

        global $DB;
        $DB->Clear();
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'attendance SET event_calendardetail_id = ' . $newDetailId
            . ' WHERE event_calendardetail_id = ' . $detailId . " AND date < '" . $today . "'"
        );
        $DB->Clear();
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'attendance_myisam SET event_calendardetail_id = ' . $newDetailId
            . ' WHERE event_calendardetail_id = ' . $detailId . " AND date < '" . $today . "'"
        );

        return $newDetailId;
    }

    /**
     * @return list<array{MundaneId: int, Persona: string, CheckedIn: bool}>
     */
    private function mirrorDietarySummary(int $detailId, bool $canManageFeast): array
    {
        if (!$canManageFeast || $detailId <= 0) {
            return [];
        }

        global $DB;
        $DB->Clear();
        $dsRows = $DB->DataSet(
            'SELECT m.mundane_id AS MundaneId, m.persona AS Persona,
                    IF(a.mundane_id IS NOT NULL, 1, 0) AS CheckedIn
             FROM (
                 SELECT mundane_id FROM ' . DB_PREFIX . "event_rsvp
                 WHERE event_calendardetail_id = {$detailId} AND status = 'going'
                 UNION
                 SELECT mundane_id FROM " . DB_PREFIX . "attendance
                 WHERE event_calendardetail_id = {$detailId}
             ) src
             JOIN " . DB_PREFIX . 'mundane m ON m.mundane_id = src.mundane_id
             LEFT JOIN (SELECT DISTINCT mundane_id FROM ' . DB_PREFIX . "attendance WHERE event_calendardetail_id = {$detailId}) a ON a.mundane_id = src.mundane_id
             ORDER BY m.persona"
        );
        $dsList = [];
        while ($dsRows && $dsRows->Next()) {
            $dsList[] = [
                'MundaneId' => (int) $dsRows->MundaneId,
                'Persona' => (string) $dsRows->Persona,
                'CheckedIn' => (bool) (int) $dsRows->CheckedIn,
            ];
        }

        return $dsList;
    }

    /**
     * @param array{0: bool, 1: bool, 2: bool, 3: bool} $staffCaps manage, attendance, schedule, feast
     */
    private function mirrorDraftBlocked(
        int $eventId,
        int $detailId,
        int $uid,
        string $eventStatus,
        int $creatorId,
        bool $canManage,
        array $staffCaps,
    ): bool {
        unset($eventId, $detailId);
        [$canManageStaff, $canAttendance, $canSchedule, $canFeast] = $staffCaps;

        if ($eventStatus === 'published') {
            return false;
        }
        if ($canManage) {
            return false;
        }
        if ($canManageStaff || $canAttendance || $canSchedule || $canFeast) {
            return false;
        }
        if ($uid === $creatorId) {
            return false;
        }
        if ($uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_CREATE)) {
            return false;
        }

        return true;
    }
}
