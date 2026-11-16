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
}
