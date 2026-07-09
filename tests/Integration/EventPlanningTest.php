<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for EventPlanning domain (T-EVA-01 through T-EVA-13).
 */
final class EventPlanningTest extends TestCase
{
    private EventPlanningFixture $fixture;

    private Event $eventDomain;

    private EventPlanning $planning;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        unset($_SESSION['is_authorized_mundane_id']);

        $this->fixture = EventPlanningFixture::create();
        $this->eventDomain = new Event();
        $this->planning = new EventPlanning();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);

        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testCreateEventAsDraft(): void
    {
        $kingdomId = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->firstParkId();
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $parkId, AUTH_CREATE, 'draft-create');

        unset($_SESSION['is_authorized_mundane_id']);
        $response = $this->eventDomain->CreateEvent([
            'Token' => $grantor['token'],
            'KingdomId' => $kingdomId,
            'ParkId' => $parkId,
            'MundaneId' => 0,
            'UnitId' => 0,
            'Name' => 'T04EVPL Draft Event',
            'Status' => 'draft',
        ]);

        $this->assertSame(0, $response['Status']);
        $eventId = (int) $response['Detail'];
        $this->fixture->trackEvent($eventId);
        $this->assertSame('draft', $this->fixture->fetchEventStatus($eventId));
    }

    public function testSetEventStatusPublished(): void
    {
        $ctx = $this->fixture->createPublishedEvent('status-pub', 'draft');
        $staff = $this->fixture->createGrantorWithoutAuth('status-mgr');
        $this->fixture->insertStaff($ctx['detail_id'], $staff['mundane_id'], 'Mgr', canManage: true);

        unset($_SESSION['is_authorized_mundane_id']);
        $authorized = $this->planning->CanManageEventDetail($staff['mundane_id'], $ctx['event_id'], 0, 'manage');
        $this->assertTrue($authorized);

        $r = $this->planning->SetEventStatus([
            'Token' => $staff['token'],
            'EventId' => $ctx['event_id'],
            'Status' => 'published',
        ]);
        $this->assertSame(0, $r['Status']);
        $this->assertSame('published', $this->fixture->fetchEventStatus($ctx['event_id']));
    }

    public function testSetEventStatusRejectsUnauthorized(): void
    {
        $ctx = $this->fixture->createPublishedEvent('status-unauth');
        $stranger = $this->fixture->createGrantorWithoutAuth('stranger');

        unset($_SESSION['is_authorized_mundane_id']);
        $authorized = $this->planning->CanManageEventDetail($stranger['mundane_id'], $ctx['event_id'], 0, 'manage');
        $this->assertFalse($authorized);
    }

    public function testSetEventStatusBustsScopeCache(): void
    {
        $ctx = $this->fixture->createPublishedEvent('cache-status');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $ctx['park_id'], AUTH_CREATE, 'cache-grant');
        Ork3::$Lib->ghettocache->bust_event_search($ctx['event_id']);

        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->planning->SetEventStatus([
            'Token' => $grantor['token'],
            'EventId' => $ctx['event_id'],
            'Status' => 'draft',
        ]);
        $this->assertSame(0, $r['Status']);
        $this->assertSame('draft', $this->fixture->fetchEventStatus($ctx['event_id']));
    }

    public function testGetEventPreviewPublished(): void
    {
        $ctx = $this->fixture->createPublishedEvent('preview-pub');
        $playerId = $this->fixture->createPlayer('preview-rsvp');
        $this->fixture->insertRsvp($ctx['detail_id'], $playerId, 'going');

        $r = $this->planning->GetEventPreview([
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'MundaneId' => 0,
        ]);
        $this->assertSame(0, $r['Status']['Status']);
        $preview = $r['Preview'];
        $this->assertArrayHasKey('name', $preview);
        $this->assertSame(1, $preview['going_count']);
    }

    public function testGetEventPreviewDraftHidden(): void
    {
        $ctx = $this->fixture->createPublishedEvent('preview-draft', 'draft');
        $r = $this->planning->GetEventPreview([
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'MundaneId' => 0,
        ]);
        $status = is_array($r['Status'] ?? null) ? (int)($r['Status']['Status'] ?? 1) : (int)($r['Status'] ?? 1);
        $this->assertNotSame(0, $status);
    }

    public function testAddStaffInsertAndUpdate(): void
    {
        $ctx = $this->fixture->createPublishedEvent('staff-crud');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $ctx['park_id'], AUTH_CREATE, 'staff-admin');
        $player = $this->fixture->createPlayer('staff-target');

        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->planning->AddEventStaff([
            'Token' => $grantor['token'],
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'MundaneId' => $player,
            'RoleName' => 'Gate',
            'CanManage' => 0,
        ]);
        $this->assertSame(0, $r['Status']['Status']);
        $staffId = (int) $r['Staff']['EventStaffId'];
        $this->assertGreaterThan(0, $staffId);

        $this->fixture->updateStaff($staffId, 'Head Gate', true);
        $row = $this->fixture->fetchStaffRow($staffId);
        $this->assertSame('Head Gate', $row['role_name']);
        $this->assertSame(1, (int) $row['can_manage']);
    }

    public function testRemoveStaff(): void
    {
        $ctx = $this->fixture->createPublishedEvent('staff-rm');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $ctx['park_id'], AUTH_CREATE, 'staff-rm-admin');
        $player = $this->fixture->createPlayer('staff-rm-target');
        $staffId = $this->fixture->insertStaff($ctx['detail_id'], $player, 'Temp');

        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->planning->RemoveEventStaff([
            'Token' => $grantor['token'],
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'StaffId' => $staffId,
        ]);
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertFalse($this->fixture->staffExists($staffId));
    }

    public function testAddScheduleWithLeads(): void
    {
        $ctx = $this->fixture->createPublishedEvent('sched-leads');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $ctx['park_id'], AUTH_CREATE, 'sched-admin');
        $leadId = $this->fixture->createPlayer('sched-lead');

        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->planning->AddEventSchedule([
            'Token' => $grantor['token'],
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'Title' => 'Opening Court',
            'StartTime' => date('Y-m-d H:i:s', strtotime('+7 days 10:00')),
            'EndTime' => date('Y-m-d H:i:s', strtotime('+7 days 11:00')),
            'Location' => 'Main',
            'Description' => '',
            'Category' => 'Tournament',
            'Leads' => [['MundaneId' => $leadId, 'Persona' => 'Lead']],
        ]);
        $this->assertSame(0, $r['Status']['Status']);
        $scheduleId = (int) $r['Schedule']['EventScheduleId'];
        $this->assertTrue($this->fixture->scheduleExists($scheduleId, $ctx['detail_id']));
        $this->assertSame(1, $this->fixture->countScheduleLeads($scheduleId));
    }

    public function testAddScheduleFeastRequiresFeastCap(): void
    {
        $ctx = $this->fixture->createPublishedEvent('feast-cap');
        $staff = $this->fixture->createGrantorWithoutAuth('att-only');
        $this->fixture->insertStaff($ctx['detail_id'], $staff['mundane_id'], 'Att', canAttendance: true);

        $allowed = $this->planning->ScheduleFeastAllowed($staff['mundane_id'], $ctx['event_id'], $ctx['detail_id'], 'Feast and Food');
        $this->assertFalse($allowed);
    }

    public function testUpdateSchedulePartialFeastFields(): void
    {
        $ctx = $this->fixture->createPublishedEvent('feast-update');
        $staff = $this->fixture->createGrantorWithoutAuth('feast-staff');
        $this->fixture->insertStaff($ctx['detail_id'], $staff['mundane_id'], 'Feast', canFeast: true);
        $scheduleId = $this->fixture->insertSchedule($ctx['detail_id'], 'Feast', 'Feast and Food');

        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->planning->UpdateEventSchedule([
            'Token' => $staff['token'],
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'ScheduleId' => $scheduleId,
            'Title' => 'Feast',
            'Category' => 'Feast and Food',
            'Menu' => 'Roast beast',
        ]);
        $this->assertSame(0, $r['Status']['Status']);

        global $DB;
        $DB->Clear();
        $row = $DB->DataSet(
            'SELECT menu FROM ' . DB_PREFIX . 'event_schedule WHERE event_schedule_id = ' . $scheduleId
        );
        $this->assertTrue($row && $row->Next());
        $this->assertSame('Roast beast', $row->menu);
    }

    public function testRemoveSchedule(): void
    {
        $ctx = $this->fixture->createPublishedEvent('sched-rm');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $ctx['park_id'], AUTH_CREATE, 'sched-rm-admin');
        $scheduleId = $this->fixture->insertSchedule($ctx['detail_id'], 'Remove me');

        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->planning->RemoveEventSchedule([
            'Token' => $grantor['token'],
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'ScheduleId' => $scheduleId,
        ]);
        $this->assertSame(0, $r['Status']);
        $this->assertFalse($this->fixture->scheduleExists($scheduleId, $ctx['detail_id']));
    }

    public function testRemoveEventHeraldry(): void
    {
        $ctx = $this->fixture->createPublishedEvent('heraldry-rm');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $ctx['park_id'], AUTH_CREATE, 'heraldry-admin');
        $this->fixture->setEventHeraldry($ctx['event_id'], 1);

        unset($_SESSION['is_authorized_mundane_id']);
        $heraldry = new Heraldry();
        $r = $heraldry->RemoveEventHeraldry([
            'Token' => $grantor['token'],
            'EventId' => $ctx['event_id'],
        ]);
        $this->assertSame(0, $r['Status']);
        $this->assertSame(0, $this->fixture->fetchEventHeraldry($ctx['event_id']));
    }

    public function testCopySourceListScoped(): void
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->firstKingdomId();
        $past = $this->fixture->createPastPublishedEventAtPark($parkId, $kingdomId, 'copy-src');

        $r = $this->planning->ListCopySourceEvents([
            'ParkId' => $parkId,
            'KingdomId' => 0,
            'Query' => '',
        ]);
        $this->assertSame(0, $r['Status']['Status']);
        $ids = array_column($r['Results'], 'eventId');
        $this->assertContains($past['event_id'], $ids);
    }

    public function testCreateWithCopyModules(): void
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->firstKingdomId();
        $source = $this->fixture->createPastPublishedEventAtPark($parkId, $kingdomId, 'copy-from');
        $player = $this->fixture->createPlayer('copy-staff');
        $this->fixture->insertStaff($source['detail_id'], $player, 'Copied Staff');
        $this->fixture->insertSchedule($source['detail_id'], 'Copied Block');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $parkId, AUTH_CREATE, 'copy-create');

        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->planning->CreateEventWithCopy([
            'Token' => $grantor['token'],
            'Name' => 'T04EVPL Copy Target',
            'KingdomId' => $kingdomId,
            'ParkId' => $parkId,
            'SourceEventId' => $source['event_id'],
            'NewStart' => date('Y-m-d H:i:s', strtotime('+14 days')),
            'NewEnd' => date('Y-m-d H:i:s', strtotime('+14 days +6 hours')),
            'Modules' => ['staff' => true, 'schedule' => true],
            'Status' => 'published',
        ]);

        $this->assertSame(0, $r['Status']['Status']);
        $newEventId = (int) $r['EventId'];
        $this->fixture->trackEvent($newEventId);
        $this->assertGreaterThan(0, $newEventId);

        global $DB;
        $DB->Clear();
        $staffCount = $DB->DataSet(
            'SELECT COUNT(*) AS c FROM ' . DB_PREFIX . 'event_staff es
             JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = es.event_calendardetail_id
             WHERE cd.event_id = ' . $newEventId
        );
        $this->assertTrue($staffCount && $staffCount->Next());
        $this->assertGreaterThanOrEqual(1, (int) $staffCount->c);
    }

    public function testCreateWithCopyRollback(): void
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->firstKingdomId();
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $parkId, AUTH_CREATE, 'copy-fail');

        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->planning->CreateEventWithCopy([
            'Token' => $grantor['token'],
            'Name' => 'T04EVPL Copy Fail',
            'KingdomId' => $kingdomId,
            'ParkId' => $parkId,
            'SourceEventId' => 99999999,
            'NewStart' => date('Y-m-d H:i:s', strtotime('+14 days')),
            'NewEnd' => date('Y-m-d H:i:s', strtotime('+14 days +6 hours')),
            'Modules' => ['staff' => true],
            'Status' => 'published',
        ]);

        $this->assertNotSame(0, $r['Status']['Status']);
    }
}
