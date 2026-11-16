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

    public function testScheduleCategoriesListsTenInOrder(): void
    {
        $cats = EventPlanning::ScheduleCategories();
        $this->assertSame([
            'Administrative', 'Tournament', 'Battlegame', 'Arts and Sciences', 'Class',
            'Feast and Food', 'Court', 'Meeting', 'Roleplay and Quests', 'Other',
        ], array_keys($cats));
        $this->assertSame(['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'], array_column($cats, 'key'));
        foreach ($cats as $def) {
            foreach (['icon', 'color', 'bg', 'colorDark', 'bgDark', 'bold'] as $k) {
                $this->assertNotSame('', (string) ($def[$k] ?? ''), $k);
            }
        }
        $this->assertSame('#c2185b', $cats['Roleplay and Quests']['bold']);
    }

    public function testAddScheduleAcceptsRoleplayAndQuests(): void
    {
        $r = $this->addScheduleWithCategory('sched-rpq', 'Roleplay and Quests');
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertSame('Roleplay and Quests', $r['Schedule']['Category']);
    }

    public function testAddScheduleUnknownCategoryBecomesOther(): void
    {
        $r = $this->addScheduleWithCategory('sched-unknown', 'Jousting');
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertSame('Other', $r['Schedule']['Category']);
    }

    private function addScheduleWithCategory(string $tag, string $category): array
    {
        $ctx = $this->fixture->createPublishedEvent($tag);
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $ctx['park_id'], AUTH_CREATE, $tag . '-admin');

        unset($_SESSION['is_authorized_mundane_id']);
        return $this->planning->AddEventSchedule([
            'Token' => $grantor['token'],
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'],
            'Title' => 'Category check',
            'StartTime' => date('Y-m-d H:i:s', strtotime('+7 days 10:00')),
            'EndTime' => date('Y-m-d H:i:s', strtotime('+7 days 11:00')),
            'Location' => 'Main',
            'Description' => '',
            'Category' => $category,
        ]);
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
            'Modules' => ['staff' => true, 'schedule' => true, 'banner' => true],
            'Status' => 'published',
        ]);

        $this->assertSame(0, $r['Status']['Status']);
        $newEventId = (int) $r['EventId'];
        $this->fixture->trackEvent($newEventId);
        $this->assertGreaterThan(0, $newEventId);
        $this->assertSame([], $r['Warnings'] ?? []);

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

    /** @return array{0: array, 1: string} event context and a token with full event authority */
    private function batchContext(string $tag): array
    {
        $ctx = $this->fixture->createPublishedEvent($tag);
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $ctx['park_id'], AUTH_CREATE, $tag . '-admin');
        return [$ctx, $grantor['token']];
    }
    private function batch(array $ctx, string $token, array $ops): array
    {
        unset($_SESSION['is_authorized_mundane_id']);
        return $this->planning->ApplyScheduleOps([
            'Token' => $token, 'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'], 'Ops' => $ops,
        ]);
    }
    private function batchDay(): string
    {
        return date('Y-m-d', strtotime('+7 days'));
    }
    public function testBatchAddUpdateRemove(): void
    {
        [$ctx, $token] = $this->batchContext('batch-crud');
        $d = $this->batchDay();
        $r = $this->batch($ctx, $token, [
            ['op' => 'add', 'cid' => 'c1', 'item' => ['Title' => 'Mothman', 'StartTime' => "$d 12:00", 'EndTime' => "$d 12:45", 'Category' => 'Battlegame']],
            ['op' => 'add', 'cid' => 'c2', 'item' => ['Title' => 'Site Tour', 'StartTime' => "$d 14:00", 'EndTime' => "$d 14:30", 'Category' => 'Jousting']],
        ]);
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertCount(2, $r['Results']);
        $this->assertSame(['add', 'c1'], [$r['Results'][0]['op'], $r['Results'][0]['cid']]);
        $id1 = (int) $r['Results'][0]['id'];
        $id2 = (int) $r['Results'][1]['id'];
        $this->assertGreaterThan(0, $id1);
        $this->assertNotSame($id1, $id2);
        $this->assertSame('Mothman', $r['Results'][0]['schedule']['Title']);
        $this->assertSame("$d 12:00:00", $r['Results'][0]['schedule']['StartTime']);
        $this->assertSame('Other', $r['Results'][1]['schedule']['Category']);
        $r2 = $this->batch($ctx, $token, [
            ['op' => 'update', 'id' => $id1, 'fields' => ['StartTime' => "$d 13:00", 'EndTime' => "$d 13:45"]],
            ['op' => 'remove', 'id' => $id2],
        ]);
        $this->assertSame(0, $r2['Status']['Status']);
        $this->assertSame('Mothman', $r2['Results'][0]['schedule']['Title']);
        $this->assertSame("$d 13:00:00", $r2['Results'][0]['schedule']['StartTime']);
        $this->assertNull($r2['Results'][1]['schedule']);
        $this->assertFalse($this->fixture->scheduleExists($id2, $ctx['detail_id']));
    }
    public function testBatchRollsBackOnAFailingOp(): void
    {
        [$ctx, $token] = $this->batchContext('batch-rollback');
        $d = $this->batchDay();
        $r = $this->batch($ctx, $token, [
            ['op' => 'add', 'cid' => 'c1', 'item' => ['Title' => 'Should not persist', 'StartTime' => "$d 09:00", 'EndTime' => "$d 10:00"]],
            ['op' => 'update', 'id' => 999999999, 'fields' => ['Title' => 'Ghost']],
        ]);
        $this->assertNotSame(0, $r['Status']['Status']);
        $this->assertSame(1, $r['FailedIndex']);
        $this->assertSame(0, $this->fixture->countSchedule($ctx['detail_id']));
    }
    public function testBatchPartialUpdateLeavesOtherFieldsMealFieldsAndLeads(): void
    {
        [$ctx, $token] = $this->batchContext('batch-partial');
        $d = $this->batchDay();
        $leadId = $this->fixture->createPlayer('batch-lead');
        $r = $this->batch($ctx, $token, [['op' => 'add', 'cid' => 'c1', 'item' => [
            'Title' => 'Feast', 'StartTime' => "$d 17:00", 'EndTime' => "$d 18:00", 'Category' => 'Feast and Food',
            'Location' => 'Hall', 'Description' => 'Bring a bowl', 'Menu' => 'Potato bar', 'Cost' => '8',
            'Dietary' => 'Vegan Option', 'Allergens' => 'Dairy', 'Leads' => [['MundaneId' => $leadId]],
        ]]]);
        $id = (int) $r['Results'][0]['id'];
        $r2 = $this->batch($ctx, $token, [['op' => 'update', 'id' => $id, 'fields' => ['StartTime' => "$d 17:30", 'EndTime' => "$d 18:30"]]]);
        $this->assertSame(0, $r2['Status']['Status']);
        $row = $this->fixture->fetchScheduleRow($id);
        $this->assertSame(
            ['Feast', 'Hall', 'Bring a bowl', 'Potato bar', 'Vegan Option', 'Dairy'],
            [$row['title'], $row['location'], $row['description'], $row['menu'], $row['dietary'], $row['allergens']]
        );
        $this->assertEqualsWithDelta(8.0, (float) $row['cost'], 0.001);
        $this->assertSame("$d 17:30:00", $row['start_time']);
        $this->assertSame(1, $this->fixture->countScheduleLeads($id));
        $this->assertCount(1, $r2['Results'][0]['schedule']['Leads']);
    }
    public function testBatchUpdateChecksTimesAgainstTheStoredRow(): void
    {
        [$ctx, $token] = $this->batchContext('batch-times');
        $d = $this->batchDay();
        $id = (int) $this->batch($ctx, $token, [['op' => 'add', 'cid' => 'c1', 'item' => ['Title' => 'T', 'StartTime' => "$d 12:00", 'EndTime' => "$d 12:45"]]])['Results'][0]['id'];
        $r = $this->batch($ctx, $token, [['op' => 'update', 'id' => $id, 'fields' => ['EndTime' => "$d 11:00"]]]);
        $this->assertNotSame(0, $r['Status']['Status']);
        $this->assertSame(0, $r['FailedIndex']);
        $this->assertSame("$d 12:45:00", $this->fixture->fetchScheduleRow($id)['end_time']);
        $empty = $this->batch($ctx, $token, [['op' => 'update', 'id' => $id, 'fields' => ['Title' => '  ']]]);
        $this->assertNotSame(0, $empty['Status']['Status']);
    }
    public function testBatchRejectsAnIdFromAnotherOccurrence(): void
    {
        [$ctxA, $tokenA] = $this->batchContext('batch-own-a');
        [$ctxB, $tokenB] = $this->batchContext('batch-own-b');
        $d = $this->batchDay();
        $idB = (int) $this->batch($ctxB, $tokenB, [['op' => 'add', 'cid' => 'c1', 'item' => ['Title' => 'Theirs', 'StartTime' => "$d 09:00", 'EndTime' => "$d 10:00"]]])['Results'][0]['id'];
        foreach ([['op' => 'update', 'id' => $idB, 'fields' => ['Title' => 'Mine now']], ['op' => 'remove', 'id' => $idB]] as $op) {
            $r = $this->batch($ctxA, $tokenA, [$op]);
            $this->assertNotSame(0, $r['Status']['Status']);
        }
        $this->assertSame('Theirs', $this->fixture->fetchScheduleRow($idB)['title']);
    }
    public function testBatchNeedsTheScheduleCapabilityAndCapsItsSize(): void
    {
        [$ctx, $token] = $this->batchContext('batch-auth');
        $d = $this->batchDay();
        $op = ['op' => 'add', 'cid' => 'c1', 'item' => ['Title' => 'T', 'StartTime' => "$d 09:00", 'EndTime' => "$d 10:00"]];
        $attOnly = $this->fixture->createGrantorWithoutAuth('batch-att');
        $this->fixture->insertStaff($ctx['detail_id'], $attOnly['mundane_id'], 'Att', canAttendance: true);
        $r = $this->batch($ctx, $attOnly['token'], [$op]);
        $this->assertNotSame(0, $r['Status']['Status']);
        $this->assertSame(-1, $r['FailedIndex']);
        $this->assertSame(0, $this->fixture->countSchedule($ctx['detail_id']));
        $tooMany = $this->batch($ctx, $token, array_fill(0, 201, $op));
        $this->assertNotSame(0, $tooMany['Status']['Status']);
        $this->assertSame(-1, $tooMany['FailedIndex']);
        $this->assertSame(0, $this->fixture->countSchedule($ctx['detail_id']));
        $this->assertSame(0, $this->batch($ctx, $token, [])['Status']['Status']);
    }
    public function testBatchScheduleOnlyStaffCannotWriteMealFields(): void
    {
        [$ctx, $token] = $this->batchContext('batch-meal');
        $d = $this->batchDay();
        $id = (int) $this->batch($ctx, $token, [['op' => 'add', 'cid' => 'c1', 'item' => [
            'Title' => 'Feast', 'StartTime' => "$d 17:00", 'EndTime' => "$d 18:00", 'Category' => 'Feast and Food', 'Menu' => 'Potato bar',
        ]]])['Results'][0]['id'];
        $sched = $this->fixture->createGrantorWithoutAuth('batch-sched');
        $this->fixture->insertStaff($ctx['detail_id'], $sched['mundane_id'], 'Sched', canSchedule: true);
        $r = $this->batch($ctx, $sched['token'], [
            ['op' => 'update', 'id' => $id, 'fields' => ['StartTime' => "$d 17:15", 'EndTime' => "$d 18:15", 'Menu' => 'Overwritten']],
            ['op' => 'add', 'cid' => 'c2', 'item' => ['Title' => 'Lunch', 'StartTime' => "$d 12:00", 'EndTime' => "$d 13:00", 'Category' => 'Feast and Food', 'Menu' => 'Soup']],
        ]);
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertSame('Potato bar', $this->fixture->fetchScheduleRow($id)['menu']);
        $this->assertSame("$d 17:15:00", $this->fixture->fetchScheduleRow($id)['start_time']);
        $this->assertNull($this->fixture->fetchScheduleRow((int) $r['Results'][1]['id'])['menu']);
    }
    public function testUpdateScheduleCannotTouchLeadsOfAnotherOccurrence(): void
    {
        [$ctxA, $tokenA] = $this->batchContext('own-old-a');
        $ctxB = $this->fixture->createPublishedEvent('own-old-b');
        $idB = $this->fixture->insertSchedule($ctxB['detail_id'], 'Theirs');
        $this->fixture->insertScheduleLead($idB, $this->fixture->createPlayer('own-old-lead'));
        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->planning->UpdateEventSchedule([
            'Token' => $tokenA, 'EventId' => $ctxA['event_id'], 'EventCalendarDetailId' => $ctxA['detail_id'], 'ScheduleId' => $idB,
            'Title' => 'Hijack', 'StartTime' => date('Y-m-d H:i:s', strtotime('+7 days 10:00')),
            'EndTime' => date('Y-m-d H:i:s', strtotime('+7 days 11:00')), 'Category' => 'Other', 'Leads' => [],
        ]);
        $this->assertNotSame(0, $r['Status']['Status']);
        $this->assertSame(1, $this->fixture->countScheduleLeads($idB));
        $this->assertSame('Theirs', $this->fixture->fetchScheduleRow($idB)['title']);
    }
    public function testScheduleSavesRejectAnOccurrenceOfAnotherEvent(): void
    {
        [$ctxA, $tokenA] = $this->batchContext('own-detail-a');
        $ctxB = $this->fixture->createPublishedEvent('own-detail-b');
        $idB = $this->fixture->insertSchedule($ctxB['detail_id'], 'Theirs');
        $mixed = ['Token' => $tokenA, 'EventId' => $ctxA['event_id'], 'EventCalendarDetailId' => $ctxB['detail_id']];
        $times = ['StartTime' => date('Y-m-d H:i:s', strtotime('+7 days 10:00')), 'EndTime' => date('Y-m-d H:i:s', strtotime('+7 days 11:00'))];

        unset($_SESSION['is_authorized_mundane_id']);
        $add = $this->planning->AddEventSchedule($mixed + $times + ['Title' => 'Planted', 'Category' => 'Other']);
        unset($_SESSION['is_authorized_mundane_id']);
        $update = $this->planning->UpdateEventSchedule($mixed + $times + ['ScheduleId' => $idB, 'Title' => 'Hijack', 'Category' => 'Other']);
        unset($_SESSION['is_authorized_mundane_id']);
        $remove = $this->planning->RemoveEventSchedule($mixed + ['ScheduleId' => $idB]);
        unset($_SESSION['is_authorized_mundane_id']);
        $batch = $this->planning->ApplyScheduleOps($mixed + ['Ops' => [['op' => 'remove', 'id' => $idB]]]);

        $code = static fn (array $r): int => is_array($r['Status']) ? $r['Status']['Status'] : $r['Status'];
        $this->assertSame([4, 4, 4, 4], [$code($add), $code($update), $code($remove), $code($batch)]);
        $this->assertSame(1, $this->fixture->countSchedule($ctxB['detail_id']));
        $this->assertSame('Theirs', $this->fixture->fetchScheduleRow($idB)['title']);
    }

    public function testGetScheduleForManageNeedsTheCapability(): void
    {
        [$ctx, $token] = $this->batchContext('manage-list');
        $this->fixture->insertSchedule($ctx['detail_id'], 'Listed');
        $nobody = $this->fixture->createGrantorWithoutAuth('manage-list-none');
        unset($_SESSION['is_authorized_mundane_id']);
        $denied = $this->planning->GetScheduleForManage(['Token' => $nobody['token'], 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id']]);
        $this->assertNotSame(0, $denied['Status']['Status']);
        $this->assertArrayNotHasKey('StaffList', $denied);
        unset($_SESSION['is_authorized_mundane_id']);
        $ok = $this->planning->GetScheduleForManage(['Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id']]);
        $this->assertSame(0, $ok['Status']['Status']);
        $this->assertSame('Listed', $ok['ScheduleList'][0]['Title']);
        $this->assertIsArray($ok['StaffList']);
    }

    public function testBatchUpdateReplacesLeadsWhenSent(): void
    {
        [$ctx, $token] = $this->batchContext('batch-leads');
        $d = $this->batchDay();
        $first = $this->fixture->createPlayer('batch-leads-1');
        $other = $this->fixture->createPlayer('batch-leads-2');
        $id = (int) $this->batch($ctx, $token, [['op' => 'add', 'cid' => 'c1', 'item' => [
            'Title' => 'Mothman', 'StartTime' => "$d 12:00", 'EndTime' => "$d 12:45", 'Leads' => [['MundaneId' => $first]],
        ]]])['Results'][0]['id'];
        $r = $this->batch($ctx, $token, [['op' => 'update', 'id' => $id, 'fields' => ['Leads' => [['MundaneId' => $other]]]]]);
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertSame([['mundane_id' => $other, 'is_auto' => 0]], $this->fixture->fetchScheduleLeads($id));
        $r2 = $this->batch($ctx, $token, [['op' => 'update', 'id' => $id, 'fields' => ['Leads' => []]]]);
        $this->assertSame(0, $r2['Status']['Status']);
        $this->assertSame([], $this->fixture->fetchScheduleLeads($id));
    }

    public function testBatchRejectsAMalformedOp(): void
    {
        [$ctx, $token] = $this->batchContext('batch-malformed');
        $d = $this->batchDay();
        $add = static fn (string $cid): array => ['op' => 'add', 'cid' => $cid, 'item' => ['Title' => 'T ' . $cid, 'StartTime' => "$d 09:00", 'EndTime' => "$d 10:00"]];
        $existing = (int) $this->batch($ctx, $token, [$add('c0')])['Results'][0]['id'];
        $bad = [
            ['op' => 'explode'],
            ['op' => 'update', 'id' => $existing, 'fields' => 'nope'],
            ['op' => 'update', 'id' => $existing . '.9', 'fields' => ['Title' => 'Coerced']],
            ['op' => 'update', 'id' => '1e3', 'fields' => ['Title' => 'Coerced']],
            ['op' => 'remove', 'id' => $existing . '.0'],
        ];
        foreach ($bad as $op) {
            $r = $this->batch($ctx, $token, [$add('c1'), $op]);
            $this->assertNotSame(0, $r['Status']['Status'], json_encode($op));
            $this->assertSame(1, $r['FailedIndex'], json_encode($op));
            $this->assertSame(1, $this->fixture->countSchedule($ctx['detail_id']), json_encode($op));
        }
        $this->assertSame('T c0', $this->fixture->fetchScheduleRow($existing)['title']);
    }

    /** Adds or edits a staff row through the domain and returns the response. */
    private function saveStaff(array $ctx, string $token, int $mundaneId, array $leadCategories, int $staffId = 0): array
    {
        unset($_SESSION['is_authorized_mundane_id']);
        return $this->planning->AddEventStaff([
            'Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'],
            'MundaneId' => $mundaneId, 'RoleName' => 'Warcrat', 'CanManage' => 0, 'CanAttendance' => 0,
            'CanSchedule' => 0, 'CanFeast' => 0, 'StaffId' => $staffId, 'LeadCategories' => $leadCategories,
        ]);
    }

    public function testStaffLeadCategoriesAreSavedInDefinitionOrder(): void
    {
        [$ctx, $token] = $this->batchContext('lead-save');
        $warcrat = $this->fixture->createPlayer('lead-save-p');
        $r = $this->saveStaff($ctx, $token, $warcrat, ['Class', 'Jousting', 'Battlegame']);
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertSame(['Battlegame', 'Class'], $r['Staff']['LeadCategories']);
        $staffId = (int) $r['Staff']['EventStaffId'];
        $this->assertSame(['Battlegame', 'Class'], $this->fixture->fetchStaffCategories($staffId));
        $r2 = $this->saveStaff($ctx, $token, $warcrat, ['Court'], $staffId);
        $this->assertSame(['Court'], $this->fixture->fetchStaffCategories($staffId));
        $this->assertSame(['Court'], $r2['Staff']['LeadCategories']);
    }

    public function testNewCategoryLeadFillsOnlyItemsWithNoLead(): void
    {
        [$ctx, $token] = $this->batchContext('lead-fill');
        $warcrat = $this->fixture->createPlayer('lead-fill-w');
        $other = $this->fixture->createPlayer('lead-fill-o');
        $unled = $this->fixture->insertSchedule($ctx['detail_id'], 'Mothman', 'Battlegame');
        $led = $this->fixture->insertSchedule($ctx['detail_id'], 'Jersey Devil', 'Battlegame');
        $this->fixture->insertScheduleLead($led, $other);
        $class = $this->fixture->insertSchedule($ctx['detail_id'], 'Naalbinding', 'Class');
        $r = $this->saveStaff($ctx, $token, $warcrat, ['Battlegame']);
        $this->assertSame(1, $r['LeadsAdded']);
        $this->assertSame(0, $r['LeadsRemoved']);
        $this->assertSame([['mundane_id' => $warcrat, 'is_auto' => 1]], $this->fixture->fetchScheduleLeads($unled));
        $this->assertSame([['mundane_id' => $other, 'is_auto' => 0]], $this->fixture->fetchScheduleLeads($led));
        $this->assertSame([], $this->fixture->fetchScheduleLeads($class));
    }

    public function testDroppingACategoryLeadRemovesOnlyAutoFilledLeads(): void
    {
        [$ctx, $token] = $this->batchContext('lead-drop');
        $warcrat = $this->fixture->createPlayer('lead-drop-w');
        $auto = $this->fixture->insertSchedule($ctx['detail_id'], 'Mothman', 'Battlegame');
        $r = $this->saveStaff($ctx, $token, $warcrat, ['Battlegame']);
        $staffId = (int) $r['Staff']['EventStaffId'];
        $hand = $this->fixture->insertSchedule($ctx['detail_id'], 'Her Own Game', 'Battlegame');
        $this->fixture->insertScheduleLead($hand, $warcrat);
        $otherCat = $this->fixture->insertSchedule($ctx['detail_id'], 'Her Class', 'Class');
        $this->fixture->insertScheduleLead($otherCat, $warcrat, true);
        $r2 = $this->saveStaff($ctx, $token, $warcrat, [], $staffId);
        $this->assertSame(1, $r2['LeadsRemoved']);
        $this->assertSame([], $this->fixture->fetchScheduleLeads($auto));
        $this->assertCount(1, $this->fixture->fetchScheduleLeads($hand));
        $this->assertCount(1, $this->fixture->fetchScheduleLeads($otherCat));
    }

    public function testRemovingStaffAppliesTheRemovalRule(): void
    {
        [$ctx, $token] = $this->batchContext('lead-remove');
        $warcrat = $this->fixture->createPlayer('lead-remove-w');
        $auto = $this->fixture->insertSchedule($ctx['detail_id'], 'Mothman', 'Battlegame');
        $staffId = (int) $this->saveStaff($ctx, $token, $warcrat, ['Battlegame'])['Staff']['EventStaffId'];
        $hand = $this->fixture->insertSchedule($ctx['detail_id'], 'Her Own Game', 'Battlegame');
        $this->fixture->insertScheduleLead($hand, $warcrat);
        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->planning->RemoveEventStaff(['Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'], 'StaffId' => $staffId]);
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertSame(1, $r['LeadsRemoved']);
        $this->assertSame([], $this->fixture->fetchScheduleLeads($auto));
        $this->assertCount(1, $this->fixture->fetchScheduleLeads($hand));
        $this->assertSame([], $this->fixture->fetchStaffCategories($staffId));
    }

    public function testLeadAutoFlagRoundTripsThroughBatchAndLists(): void
    {
        [$ctx, $token] = $this->batchContext('lead-auto');
        $d = $this->batchDay();
        $a = $this->fixture->createPlayer('lead-auto-a');
        $b = $this->fixture->createPlayer('lead-auto-b');
        $r = $this->batch($ctx, $token, [['op' => 'add', 'cid' => 'c1', 'item' => [
            'Title' => 'Mothman', 'StartTime' => "$d 12:00", 'EndTime' => "$d 12:45", 'Category' => 'Battlegame',
            'Leads' => [['MundaneId' => $a, 'Auto' => 1], ['MundaneId' => $b]],
        ]]]);
        $id = (int) $r['Results'][0]['id'];
        $byId = array_column($r['Results'][0]['schedule']['Leads'], 'Auto', 'MundaneId');
        $this->assertSame([$a => true, $b => false], $byId);
        $this->batch($ctx, $token, [['op' => 'update', 'id' => $id, 'fields' => ['Leads' => [['MundaneId' => $a]]]]]);
        $this->assertSame([['mundane_id' => $a, 'is_auto' => 0]], $this->fixture->fetchScheduleLeads($id));
        $this->saveStaff($ctx, $token, $b, ['Class', 'Battlegame']);
        unset($_SESSION['is_authorized_mundane_id']);
        $list = $this->planning->GetScheduleForManage(['Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id']]);
        $this->assertSame(0, $list['Status']['Status']);
        $this->assertCount(1, $list['StaffList']);
        $this->assertSame(['Battlegame', 'Class'], $list['StaffList'][0]['LeadCategories']);
        $this->assertSame([false], array_column($list['ScheduleList'][0]['Leads'], 'Auto'));
    }

    public function testEditingStaffWithoutLeadCategoriesKeepsThem(): void
    {
        [$ctx, $token] = $this->batchContext('lead-keep');
        $warcrat = $this->fixture->createPlayer('lead-keep-w');
        $item = $this->fixture->insertSchedule($ctx['detail_id'], 'Mothman', 'Battlegame');
        $staffId = (int) $this->saveStaff($ctx, $token, $warcrat, ['Battlegame'])['Staff']['EventStaffId'];
        $this->assertSame([['mundane_id' => $warcrat, 'is_auto' => 1]], $this->fixture->fetchScheduleLeads($item));

        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->planning->AddEventStaff([
            'Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'],
            'MundaneId' => $warcrat, 'RoleName' => 'Head Warcrat', 'StaffId' => $staffId,
        ]);
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertSame('Head Warcrat', $this->fixture->fetchStaffRow($staffId)['role_name']);
        $this->assertSame(['Battlegame'], $this->fixture->fetchStaffCategories($staffId));
        $this->assertSame(['Battlegame'], $r['Staff']['LeadCategories']);
        $this->assertSame([['mundane_id' => $warcrat, 'is_auto' => 1]], $this->fixture->fetchScheduleLeads($item));
        $this->assertSame(0, $r['LeadsAdded']);
        $this->assertSame(0, $r['LeadsRemoved']);
    }

    public function testStaffChangesRejectAnOccurrenceOfAnotherEvent(): void
    {
        [$ctxA, $tokenA] = $this->batchContext('staff-own-a');
        $ctxB = $this->fixture->createPublishedEvent('staff-own-b');
        $theirs = $this->fixture->createPlayer('staff-own-theirs');
        $staffB = $this->fixture->insertStaff($ctxB['detail_id'], $theirs, 'Theirs');
        $planted = $this->fixture->createPlayer('staff-own-planted');
        $mixed = ['Token' => $tokenA, 'EventId' => $ctxA['event_id'], 'EventCalendarDetailId' => $ctxB['detail_id']];

        unset($_SESSION['is_authorized_mundane_id']);
        $add = $this->planning->AddEventStaff($mixed + ['MundaneId' => $planted, 'RoleName' => 'Planted', 'LeadCategories' => ['Battlegame']]);
        unset($_SESSION['is_authorized_mundane_id']);
        $edit = $this->planning->AddEventStaff($mixed + ['MundaneId' => $theirs, 'RoleName' => 'Hijacked', 'StaffId' => $staffB]);
        unset($_SESSION['is_authorized_mundane_id']);
        $remove = $this->planning->RemoveEventStaff($mixed + ['StaffId' => $staffB]);

        $code = static fn (array $r): int => is_array($r['Status']) ? $r['Status']['Status'] : $r['Status'];
        $this->assertNotSame(0, $code($add));
        $this->assertNotSame(0, $code($edit));
        $this->assertNotSame(0, $code($remove));
        $this->assertTrue($this->fixture->staffExists($staffB));
        $this->assertSame('Theirs', $this->fixture->fetchStaffRow($staffB)['role_name']);
        $this->assertSame([], $this->fixture->fetchStaffCategories($staffB));
        global $DB;
        $DB->Clear();
        $count = $DB->DataSet(
            'SELECT COUNT(*) AS c FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . (int) $ctxB['detail_id']
        );
        $this->assertTrue($count && $count->Next());
        $this->assertSame(1, (int) $count->c);
    }

    public function testCopyCarriesCategoryLeadsAndAutoFlags(): void
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->firstKingdomId();
        $source = $this->fixture->createPastPublishedEventAtPark($parkId, $kingdomId, 'copy-leads-from');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $parkId, AUTH_CREATE, 'copy-leads-create');
        $warcrat = $this->fixture->createPlayer('copy-leads-w');
        $this->fixture->insertSchedule($source['detail_id'], 'Mothman', 'Battlegame');
        $saved = $this->saveStaff($source, $grantor['token'], $warcrat, ['Battlegame']);
        $this->assertSame(1, $saved['LeadsAdded']);

        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->planning->CreateEventWithCopy([
            'Token' => $grantor['token'],
            'Name' => 'T04EVPL Copy Leads Target',
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

        global $DB;
        $DB->Clear();
        $detailRow = $DB->DataSet(
            'SELECT event_calendardetail_id FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_id = ' . $newEventId . ' LIMIT 1'
        );
        $this->assertTrue($detailRow && $detailRow->Next());
        $newDetailId = (int) $detailRow->event_calendardetail_id;
        $this->fixture->trackDetail($newDetailId);

        $DB->Clear();
        $staffRow = $DB->DataSet(
            'SELECT event_staff_id FROM ' . DB_PREFIX . 'event_staff
             WHERE event_calendardetail_id = ' . $newDetailId . ' AND mundane_id = ' . $warcrat . ' LIMIT 1'
        );
        $this->assertTrue($staffRow && $staffRow->Next());
        $this->assertSame(['Battlegame'], $this->fixture->fetchStaffCategories((int) $staffRow->event_staff_id));

        $DB->Clear();
        $schedRow = $DB->DataSet(
            'SELECT event_schedule_id FROM ' . DB_PREFIX . 'event_schedule
             WHERE event_calendardetail_id = ' . $newDetailId . " AND title = 'Mothman' LIMIT 1"
        );
        $this->assertTrue($schedRow && $schedRow->Next());
        $this->assertSame(
            [['mundane_id' => $warcrat, 'is_auto' => 1]],
            $this->fixture->fetchScheduleLeads((int) $schedRow->event_schedule_id)
        );
    }

    public function testCopyScheduleIntoAppendsAndKeepsClockTimesAcrossDst(): void
    {
        $src = $this->fixture->createPublishedEventOn('copy-src', '2026-10-30');   // before the US clock change on 2026-11-01
        $dst = $this->fixture->createPublishedEventOn('copy-dst', '2026-11-06');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $dst['park_id'], AUTH_CREATE, 'copy-admin');
        $this->fixture->insertScheduleAt($src['detail_id'], 'Opening Court', 'Court', '2026-10-30 18:00:00', '2026-10-30 19:00:00');
        $this->fixture->insertScheduleAt($src['detail_id'], 'Night Quest', 'Roleplay and Quests', '2026-10-31 23:30:00', '2026-11-01 00:30:00');
        $existing = $this->fixture->insertScheduleAt($dst['detail_id'], 'Already Here', 'Other', '2026-11-06 09:00:00', '2026-11-06 10:00:00');
        unset($_SESSION['is_authorized_mundane_id']);
        // A zone with a clock change between source and target, so a seconds-based shift would be an hour off.
        $tz = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
        try {
            $r = $this->planning->CopyScheduleInto(['Token' => $grantor['token'], 'EventId' => $dst['event_id'], 'EventCalendarDetailId' => $dst['detail_id'], 'SourceDetailId' => $src['detail_id']]);
        } finally {
            date_default_timezone_set($tz);
        }
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertSame(
            [['Opening Court', '2026-11-06 18:00:00', '2026-11-06 19:00:00'], ['Night Quest', '2026-11-07 23:30:00', '2026-11-08 00:30:00']],
            array_map(fn ($s) => [$s['Title'], $s['StartTime'], $s['EndTime']], $r['Schedule'])
        );
        $this->assertSame(3, $this->fixture->countSchedule($dst['detail_id']));
        $this->assertSame('2026-11-06 09:00:00', $this->fixture->fetchScheduleRow($existing)['start_time']);
        $this->assertSame(2, $this->fixture->countSchedule($src['detail_id']));
    }

    public function testCopyScheduleIntoSkipsFeastWithoutTheFeastCapabilityAndNeedsScheduleRights(): void
    {
        $src = $this->fixture->createPublishedEventOn('copy-feast-src', '2026-10-30');
        $dst = $this->fixture->createPublishedEventOn('copy-feast-dst', '2026-11-06');
        $this->fixture->insertScheduleAt($src['detail_id'], 'Feast', 'Feast and Food', '2026-10-30 17:00:00', '2026-10-30 18:00:00');
        $this->fixture->insertScheduleAt($src['detail_id'], 'Mothman', 'Battlegame', '2026-10-30 12:00:00', '2026-10-30 12:45:00');
        $sched = $this->fixture->createGrantorWithoutAuth('copy-sched');
        $this->fixture->insertStaff($dst['detail_id'], $sched['mundane_id'], 'Sched', canSchedule: true);
        $req = ['EventId' => $dst['event_id'], 'EventCalendarDetailId' => $dst['detail_id'], 'SourceDetailId' => $src['detail_id']];
        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->planning->CopyScheduleInto($req + ['Token' => $sched['token']]);
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertSame(['Mothman'], array_column($r['Schedule'], 'Title'));
        $this->assertSame(1, $r['SkippedFeast']);
        $nobody = $this->fixture->createGrantorWithoutAuth('copy-nobody');
        unset($_SESSION['is_authorized_mundane_id']);
        $denied = $this->planning->CopyScheduleInto($req + ['Token' => $nobody['token']]);
        $this->assertNotSame(0, $denied['Status']['Status']);
        $this->assertSame(1, $this->fixture->countSchedule($dst['detail_id']));
    }

    public function testCopyScheduleIntoRefusesASourceOutsideTheAllowedSet(): void
    {
        $dst = $this->fixture->createPublishedEventOn('copy-refuse-dst', '2026-11-06');
        $draft = $this->fixture->createPublishedEventOn('copy-refuse-draft', '2026-10-30', 'draft');
        $this->fixture->insertScheduleAt($draft['detail_id'], 'Secret Plan', 'Court', '2026-10-30 18:00:00', '2026-10-30 19:00:00');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $dst['park_id'], AUTH_CREATE, 'copy-refuse-admin');
        $req = ['Token' => $grantor['token'], 'EventId' => $dst['event_id'], 'EventCalendarDetailId' => $dst['detail_id']];
        $before = $this->fixture->countSchedule($dst['detail_id']);
        foreach ([$draft['detail_id'], 2000000000] as $sourceId) {
            unset($_SESSION['is_authorized_mundane_id']);
            $r = $this->planning->CopyScheduleInto($req + ['SourceDetailId' => $sourceId]);
            $this->assertNotSame(0, $r['Status']['Status'], 'source ' . $sourceId);
            $this->assertSame($before, $this->fixture->countSchedule($dst['detail_id']));
        }
    }

    public function testCopyScheduleIntoHandlesANegativeShiftAndCopiesMealFieldsWithFeastRights(): void
    {
        $src = $this->fixture->createPublishedEventOn('copy-neg-src', '2026-11-13');
        $dst = $this->fixture->createPublishedEventOn('copy-neg-dst', '2026-11-06');
        $feast = $this->fixture->insertScheduleAt($src['detail_id'], 'Feast', 'Feast and Food', '2026-11-13 17:00:00', '2026-11-13 18:00:00');
        $this->fixture->setScheduleMeal($feast, 'Stew and bread', 12.5);
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $dst['park_id'], AUTH_CREATE, 'copy-neg-admin');
        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->planning->CopyScheduleInto(['Token' => $grantor['token'], 'EventId' => $dst['event_id'], 'EventCalendarDetailId' => $dst['detail_id'], 'SourceDetailId' => $src['detail_id']]);
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertSame(0, $r['SkippedFeast']);
        $this->assertCount(1, $r['Schedule']);
        $row = $this->fixture->fetchScheduleRow((int) $r['Schedule'][0]['EventScheduleId']);
        $this->assertSame(
            ['2026-11-06 17:00:00', '2026-11-06 18:00:00', 'Stew and bread', 12.5],
            [$row['start_time'], $row['end_time'], $row['menu'], (float) $row['cost']]
        );
    }

    public function testScheduleCopySourcesListThisEventsOtherOccurrencesFirst(): void
    {
        $ctx = $this->fixture->createPublishedEventOn('copy-list', '2026-11-06');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $ctx['park_id'], AUTH_CREATE, 'copy-list-admin');
        $earlier = $this->fixture->createDetailOnEvent($ctx['event_id'], '2025-11-07 09:00:00', '2025-11-09 16:00:00');
        $this->fixture->insertScheduleAt($earlier, 'Last Year', 'Court', '2025-11-07 18:00:00', '2025-11-07 19:00:00');
        $empty = $this->fixture->createDetailOnEvent($ctx['event_id'], '2024-11-08 09:00:00', '2024-11-10 16:00:00');
        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->planning->ListScheduleCopySources(['Token' => $grantor['token'], 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id']]);
        $this->assertSame(0, $r['Status']['Status']);
        $first = $r['Sources'][0];
        $this->assertSame([$earlier, true, 1, '2025-11-07'], [$first['EventCalendarDetailId'], $first['SameEvent'], $first['ItemCount'], $first['StartDate']]);
        $ids = array_column($r['Sources'], 'EventCalendarDetailId');
        $this->assertNotContains($empty, $ids);
        $this->assertNotContains($ctx['detail_id'], $ids);
    }
}
