<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_Event detail/create flows (T-EVT-03 through T-EVT-07).
 */
final class EventOccurrenceTest extends TestCase
{
    private EventPlanningFixture $fixture;

    private EventPlanning $planning;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        unset($_SESSION['is_authorized_mundane_id']);

        $this->fixture = EventPlanningFixture::create();
        $this->planning = new EventPlanning();
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

        $dto = $this->occurrencePageData($ctx['event_id'], $ctx['detail_id']);

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

        $picked = $this->defaultOccurrenceId($ctx['event_id']);
        $this->assertSame($ctx['detail_id'], $picked);

        global $DB;
        $DB->Clear();
        $DB->Execute(
            'DELETE FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_calendardetail_id = ' . (int) $ctx['detail_id']
        );

        $next = $this->defaultOccurrenceId($ctx['event_id']);
        $this->assertSame($futureId, $next);

        $DB->Clear();
        $DB->Execute(
            'DELETE FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_calendardetail_id = ' . (int) $futureId
        );

        $fallback = $this->defaultOccurrenceId($ctx['event_id']);
        $this->assertSame($pastId, $fallback);
    }

    public function testDetailOwnershipPreflight(): void
    {
        $ctxA = $this->fixture->createPublishedEvent('own-a');
        $ctxB = $this->fixture->createPublishedEvent('own-b');

        $this->assertTrue($this->detailBelongsToEvent($ctxA['detail_id'], $ctxA['event_id']));
        $this->assertFalse($this->detailBelongsToEvent($ctxB['detail_id'], $ctxA['event_id']));
    }

    public function testSetCalendarDetailFeesLinks(): void
    {
        $ctx = $this->fixture->createPublishedEvent('fees-links');
        $this->fixture->insertFee($ctx['detail_id'], 'Old', 5.0);
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_EVENT, $ctx['event_id'], AUTH_EDIT, 'fees-auth');
        unset($_SESSION['is_authorized_mundane_id']);

        $denied = $this->planning->SetCalendarDetailFeesAndLinks([
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'Fees' => [['AdmissionType' => 'Hack', 'Cost' => 1]],
            'Links' => [],
        ]);
        $this->assertSame(ServiceErrorIds::SecureTokenFailure, $denied['Status'] ?? null);
        $this->assertCount(1, $this->fixture->fetchFees($ctx['detail_id']));

        unset($_SESSION['is_authorized_mundane_id']);
        $noAuth = $this->planning->SetCalendarDetailFeesAndLinks([
            'Token' => md5('not-a-real-token-c10xxxxxxxx'),
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'Fees' => [['AdmissionType' => 'Hack', 'Cost' => 1]],
            'Links' => [],
        ]);
        $this->assertSame(ServiceErrorIds::SecureTokenFailure, $noAuth['Status'] ?? null);

        unset($_SESSION['is_authorized_mundane_id']);
        $sync = $this->planning->SetCalendarDetailFeesAndLinks([
            'Token' => $grantor['token'],
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'Fees' => [
                ['AdmissionType' => 'Member', 'Cost' => 8.5],
                ['AdmissionType' => 'Guest', 'Cost' => 12.0],
            ],
            'Links' => [
                ['Title' => 'Site', 'Url' => 'https://example.test', 'Icon' => 'fas fa-globe'],
                ['Title' => 'Bad', 'Url' => 'javascript:alert(1)', 'Icon' => 'not-allowed'],
            ],
        ]);
        $this->assertSame(0, $sync['Status']['Status']);
        $this->assertTrue($sync['FeesOk']);
        $this->assertTrue($sync['LinksOk']);

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

        $r = $this->planning->SetCalendarDetailEventType([
            'Token' => $grantor['token'],
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'EventType' => 'Day Event',
        ]);
        $this->assertSame(0, $r['Status']['Status']);

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

        $r = $this->planning->ReconcilePastAttendance([
            'Token' => $grantor['token'],
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'],
        ]);
        $newDetailId = (int) ($r['NewEventCalendarDetailId'] ?? 0);
        $this->fixture->trackDetail($newDetailId);

        $this->assertSame(0, $r['Status']['Status']);
        $this->assertGreaterThan(0, $newDetailId);
        $this->assertSame(0, $this->fixture->countAttendanceOnDetail($ctx['detail_id']));
        $this->assertGreaterThanOrEqual(1, $this->fixture->countAttendanceOnDetail($newDetailId));
    }

    public function testReconcileRejectsWithNoPastAttendance(): void
    {
        $ctx = $this->fixture->createPublishedEvent('reconcile-empty');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_EVENT, $ctx['event_id'], AUTH_CREATE, 'reconcile-empty-auth');

        $r = $this->planning->ReconcilePastAttendance([
            'Token' => $grantor['token'],
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'],
        ]);
        $this->assertNotSame(0, $r['Status']['Status']);
        $this->assertSame(0, (int) ($r['NewEventCalendarDetailId'] ?? 0));
    }

    public function testGetDietarySummary(): void
    {
        $ctx = $this->fixture->createPublishedEvent('dietary');
        $player = $this->fixture->createPlayer('diet-player');
        $this->fixture->insertRsvp($ctx['detail_id'], $player, 'going');
        $feast = $this->fixture->createGrantorWithoutAuth('c11-feast');
        $this->fixture->insertStaff(
            $ctx['detail_id'],
            $feast['mundane_id'],
            'Feast',
            false,
            false,
            false,
            true,
        );
        unset($_SESSION['is_authorized_mundane_id']);

        $anonymous = $this->planning->GetDietarySummaryForOccurrence([
            'EventCalendarDetailId' => $ctx['detail_id'],
        ]);
        $this->assertSame(ServiceErrorIds::SecureTokenFailure, $anonymous['Status']);

        $stranger = $this->fixture->createGrantorWithoutAuth('c11-stranger');
        unset($_SESSION['is_authorized_mundane_id']);
        $denied = $this->planning->GetDietarySummaryForOccurrence([
            'Token' => $stranger['token'],
            'EventCalendarDetailId' => $ctx['detail_id'],
        ]);
        $this->assertSame(ServiceErrorIds::NoAuthorization, $denied['Status']);

        unset($_SESSION['is_authorized_mundane_id']);
        $summaryZero = $this->planning->GetDietarySummaryForOccurrence([
            'Token' => $feast['token'],
            'EventCalendarDetailId' => 0,
        ]);
        $this->assertSame(0, $summaryZero['Status']['Status']);
        $this->assertSame([], $summaryZero['Items']);

        unset($_SESSION['is_authorized_mundane_id']);
        $summary = $this->planning->GetDietarySummaryForOccurrence([
            'Token' => $feast['token'],
            'EventCalendarDetailId' => $ctx['detail_id'],
        ]);
        $this->assertSame(0, $summary['Status']['Status']);
        $this->assertNotEmpty($summary['Items']);
        $this->assertSame($player, $summary['Items'][0]['MundaneId']);
    }

    public function testDraftOccurrenceHiddenFromAnonymous(): void
    {
        $ctx = $this->fixture->createPublishedEvent('draft-anon', 'draft');
        $blocked = $this->planning->IsDraftBlockedForViewer([
            'EventStatus' => 'draft',
            'CreatorId' => $ctx['mundane_id'],
            'MundaneId' => 0,
            'CanManageEvent' => 0,
            'StaffCaps' => [],
        ]);
        $this->assertTrue($blocked['Blocked']);
    }

    public function testDraftVisibleToStaffDelegate(): void
    {
        $ctx = $this->fixture->createPublishedEvent('draft-staff', 'draft');
        $staff = $this->fixture->createGrantorWithoutAuth('draft-sched');
        $this->fixture->insertStaff($ctx['detail_id'], $staff['mundane_id'], 'Sched', canSchedule: true);

        $blocked = $this->planning->IsDraftBlockedForViewer([
            'EventStatus' => 'draft',
            'CreatorId' => $ctx['mundane_id'],
            'MundaneId' => $staff['mundane_id'],
            'CanManageEvent' => 0,
            'StaffCaps' => ['CanSchedule' => true],
        ]);
        $this->assertFalse($blocked['Blocked']);
    }

    public function testGetDetailDependencyCounts(): void
    {
        $ctx = $this->fixture->createPublishedEvent('dep-counts');
        $player = $this->fixture->createPlayer('dep-rsvp');
        $this->fixture->insertRsvp($ctx['detail_id'], $player, 'going');

        $counts = $this->planning->GetDetailDependencyCounts([
            'EventCalendarDetailId' => $ctx['detail_id'],
        ]);
        $this->assertSame(0, $counts['Status']['Status']);
        $this->assertSame(0, $counts['AttendanceCount']);
        $this->assertSame(1, $counts['RsvpCount']);
    }

    public function testGetEventRedirectScope(): void
    {
        $ctx = $this->fixture->createPublishedEvent('redirect-scope');
        $scope = $this->planning->GetEventRedirectScope(['EventId' => $ctx['event_id']]);
        $this->assertSame(0, $scope['Status']['Status']);
        $this->assertGreaterThan(0, $scope['KingdomId']);
    }

    public function testGetParkName(): void
    {
        $ctx = $this->fixture->createPublishedEvent('park-name');
        $this->assertSame('', $this->planning->GetParkName(['ParkId' => 0])['Name']);

        $name = $this->planning->GetParkName(['ParkId' => $ctx['park_id']]);
        $this->assertSame(0, $name['Status']['Status']);
        $this->assertNotSame('', $name['Name']);
    }

    public function testDraftNotBlockedWhenPublished(): void
    {
        $blocked = $this->planning->IsDraftBlockedForViewer([
            'EventStatus' => 'published',
            'CreatorId' => 1,
            'MundaneId' => 0,
            'CanManageEvent' => 0,
            'StaffCaps' => [],
        ]);
        $this->assertFalse($blocked['Blocked']);
    }

    public function testGetDefaultOccurrenceIdInvalidEvent(): void
    {
        $r = $this->planning->GetDefaultOccurrenceId(['EventId' => 0]);
        $this->assertNotSame(0, $r['Status']['Status']);
    }

    public function testAssertDetailBelongsToEventInvalidIds(): void
    {
        $r = $this->planning->AssertDetailBelongsToEvent(['EventId' => 0, 'EventCalendarDetailId' => 0]);
        $this->assertNotSame(0, $r['Status']['Status']);
    }

    public function testSetCalendarDetailEventTypeRejectsInvalid(): void
    {
        $ctx = $this->fixture->createPublishedEvent('bad-type');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_EVENT, $ctx['event_id'], AUTH_EDIT, 'bad-type-auth');
        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->planning->SetCalendarDetailEventType([
            'Token' => $grantor['token'],
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'EventType' => 'Not A Real Type',
        ]);
        $this->assertNotSame(0, $r['Status']['Status'] ?? $r['Status'] ?? 0);
    }

    public function testGetOccurrencePageDataRejectsForeignDetail(): void
    {
        $ctxA = $this->fixture->createPublishedEvent('page-a');
        $ctxB = $this->fixture->createPublishedEvent('page-b');
        $r = $this->planning->GetOccurrencePageData([
            'EventId' => $ctxA['event_id'],
            'EventCalendarDetailId' => $ctxB['detail_id'],
            'MundaneId' => 0,
        ]);
        $this->assertNotSame(0, $r['Status']['Status']);
    }

    public function testGetOccurrencePageDataRejectsAnonymousDraft(): void
    {
        $ctx = $this->fixture->createPublishedEvent('c09-draft', 'draft');
        unset($_SESSION['is_authorized_mundane_id']);

        $denied = $this->planning->GetOccurrencePageData([
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'MundaneId' => 0,
        ]);
        $this->assertSame(ServiceErrorIds::SecureTokenFailure, $denied['Status']);

        $stranger = $this->fixture->createGrantorWithoutAuth('c09-draft-stranger');
        unset($_SESSION['is_authorized_mundane_id']);
        $noAuth = $this->planning->GetOccurrencePageData([
            'Token' => $stranger['token'],
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'MundaneId' => $stranger['mundane_id'],
        ]);
        $this->assertSame(ServiceErrorIds::NoAuthorization, $noAuth['Status']);
    }

    public function testOccurrencePageDataIgnoresDietaryWithoutFeastAuth(): void
    {
        $ctx = $this->fixture->createPublishedEvent('page-diet');
        $player = $this->fixture->createPlayer('page-diet-player');
        $this->fixture->insertRsvp($ctx['detail_id'], $player, 'going');
        unset($_SESSION['is_authorized_mundane_id']);

        $r = $this->planning->GetOccurrencePageData([
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'MundaneId' => 0,
            'IncludeDietary' => 1,
        ]);
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertSame([], $r['DietarySummary'] ?? null);
    }

    public function testOccurrencePageDataIncludesDietaryForFeastToken(): void
    {
        $ctx = $this->fixture->createPublishedEvent('page-diet-feast');
        $player = $this->fixture->createPlayer('page-diet-feast-player');
        $this->fixture->insertRsvp($ctx['detail_id'], $player, 'going');
        $feast = $this->fixture->createGrantorWithoutAuth('c09-feast');
        $this->fixture->insertStaff(
            $ctx['detail_id'],
            $feast['mundane_id'],
            'Feast',
            false,
            false,
            false,
            true,
        );
        unset($_SESSION['is_authorized_mundane_id']);

        $r = $this->planning->GetOccurrencePageData([
            'Token' => $feast['token'],
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'MundaneId' => $feast['mundane_id'],
            'IncludeDietary' => 1,
        ]);
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertNotEmpty($r['DietarySummary']);
    }

    /**
     * @return array{staff: list<array<string, mixed>>, schedule: list<array<string, mixed>>, fees: list<array<string, mixed>>, links: list<array<string, mixed>>}
     */
    private function occurrencePageData(int $eventId, int $detailId): array
    {
        $r = $this->planning->GetOccurrencePageData([
            'EventId' => $eventId,
            'EventCalendarDetailId' => $detailId,
            'MundaneId' => 0,
        ]);
        $this->assertSame(0, $r['Status']['Status']);

        return [
            'staff' => $r['StaffList'] ?? [],
            'schedule' => array_map(
                static fn ($row) => ['EventScheduleId' => $row['EventScheduleId'], 'Title' => $row['Title']],
                $r['ScheduleList'] ?? []
            ),
            'fees' => $r['EventFees'] ?? [],
            'links' => $r['ExternalLinks'] ?? [],
        ];
    }

    private function defaultOccurrenceId(int $eventId): int
    {
        $r = $this->planning->GetDefaultOccurrenceId(['EventId' => $eventId]);
        $this->assertSame(0, $r['Status']['Status']);

        return (int) ($r['EventCalendarDetailId'] ?? 0);
    }

    private function detailBelongsToEvent(int $detailId, int $eventId): bool
    {
        $r = $this->planning->AssertDetailBelongsToEvent([
            'EventId' => $eventId,
            'EventCalendarDetailId' => $detailId,
        ]);
        $this->assertSame(0, $r['Status']['Status']);

        return (bool) ($r['Belongs'] ?? false);
    }
}
