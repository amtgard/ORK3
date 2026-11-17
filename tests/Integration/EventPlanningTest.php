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
            // A feast editor's update writes every field, as a schedule manager's does, so it sends the times too.
            'StartTime' => date('Y-m-d H:i:s', strtotime('+7 days 10:00')),
            'EndTime' => date('Y-m-d H:i:s', strtotime('+7 days 11:00')),
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
            ['op' => 'add', 'cid' => 'c2', 'item' => ['Title' => '', 'StartTime' => "$d 11:00", 'EndTime' => "$d 12:00"]],
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
        // Auto is kept only for someone who leads the item's category here.
        $this->saveStaff($ctx, $token, $a, ['Battlegame']);
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
        $this->assertCount(2, $list['StaffList']);
        $byPerson = array_column($list['StaffList'], 'LeadCategories', 'MundaneId');
        $this->assertSame(['Battlegame', 'Class'], $byPerson[$b]);
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

    public function testCopyScheduleIntoKeepsALeadAutoOnlyWhereThatPersonLeadsTheCategoryHere(): void
    {
        $src = $this->fixture->createPublishedEventOn('copy-auto-src', '2026-10-30');
        $dst = $this->fixture->createPublishedEventOn('copy-auto-dst', '2026-11-06');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $dst['park_id'], AUTH_CREATE, 'copy-auto-admin');
        $warcrat = $this->fixture->createPlayer('copy-auto-w');
        $item = $this->fixture->insertScheduleAt($src['detail_id'], 'Mothman', 'Battlegame', '2026-10-30 12:00:00', '2026-10-30 12:45:00');
        $this->fixture->insertScheduleLead($item, $warcrat, true);
        $req = ['Token' => $grantor['token'], 'EventId' => $dst['event_id'], 'EventCalendarDetailId' => $dst['detail_id'], 'SourceDetailId' => $src['detail_id']];

        // Nobody leads Battlegame on the target: the copied lead is hand-picked there.
        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->planning->CopyScheduleInto($req);
        $this->assertSame(0, $r['Status']['Status']);
        $first = (int) $r['Schedule'][0]['EventScheduleId'];
        $this->assertSame([['mundane_id' => $warcrat, 'is_auto' => 0]], $this->fixture->fetchScheduleLeads($first));
        $this->assertSame([false], array_column($r['Schedule'][0]['Leads'], 'Auto'));

        // Leading another category on the target is not enough.
        $staff = $this->saveStaff($dst, $grantor['token'], $warcrat, ['Class']);
        $this->assertSame(0, $staff['Status']['Status']);
        unset($_SESSION['is_authorized_mundane_id']);
        $second = (int) $this->planning->CopyScheduleInto($req)['Schedule'][0]['EventScheduleId'];
        $this->assertSame([['mundane_id' => $warcrat, 'is_auto' => 0]], $this->fixture->fetchScheduleLeads($second));

        // Leading Battlegame on the target: the copied lead stays auto.
        $this->saveStaff($dst, $grantor['token'], $warcrat, ['Battlegame'], (int) $staff['Staff']['EventStaffId']);
        unset($_SESSION['is_authorized_mundane_id']);
        $r3 = $this->planning->CopyScheduleInto($req);
        $third = (int) $r3['Schedule'][0]['EventScheduleId'];
        $this->assertSame([['mundane_id' => $warcrat, 'is_auto' => 1]], $this->fixture->fetchScheduleLeads($third));
        $this->assertSame([true], array_column($r3['Schedule'][0]['Leads'], 'Auto'));
        // The earlier copies keep the lead they were given.
        $this->assertSame([['mundane_id' => $warcrat, 'is_auto' => 0]], $this->fixture->fetchScheduleLeads($first));
        // The source is untouched.
        $this->assertSame([['mundane_id' => $warcrat, 'is_auto' => 1]], $this->fixture->fetchScheduleLeads($item));
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

    // ---- Review fixes: retries, two organisers, the lead rule, strict input ----

    private function batchWithToken(array $ctx, string $token, array $ops, string $batchToken): array
    {
        unset($_SESSION['is_authorized_mundane_id']);
        return $this->planning->ApplyScheduleOps([
            'Token' => $token, 'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'], 'Ops' => $ops, 'BatchToken' => $batchToken,
        ]);
    }

    /** @return array{0: array, 1: string} an occurrence from 09:00 on $startDate to 16:00 two days later, and a full-authority token */
    private function batchContextOn(string $tag, string $startDate): array
    {
        $ctx = $this->fixture->createPublishedEventOn($tag, $startDate);
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $ctx['park_id'], AUTH_CREATE, $tag . '-admin');
        return [$ctx, $grantor['token']];
    }

    private function addOp(string $cid, string $title, string $start, string $end, array $extra = []): array
    {
        return ['op' => 'add', 'cid' => $cid, 'item' => ['Title' => $title, 'StartTime' => $start, 'EndTime' => $end] + $extra];
    }

    public function testScheduleCategoriesCarryUniqueTagsAliasesAndKeywords(): void
    {
        $cats = EventPlanning::ScheduleCategories();
        $this->assertSame([
            'Administrative', 'Tournament', 'Battlegame', 'Arts and Sciences', 'Class',
            'Feast and Food', 'Court', 'Meeting', 'Roleplay and Quests', 'Other',
        ], array_keys($cats));
        $tags = array_column($cats, 'tag');
        $this->assertCount(10, $tags);
        foreach ($cats as $name => $def) {
            $this->assertNotSame('', (string) ($def['tag'] ?? ''), $name);
            $this->assertIsArray($def['aliases'] ?? null, $name);
            $this->assertIsArray($def['keywords'] ?? null, $name);
            $this->assertIsInt($def['keywordRank'] ?? null, $name);
        }
        $this->assertSame($tags, array_values(array_unique($tags)));
        $this->assertSame(['bg', 'battle game'], $cats['Battlegame']['aliases']);
        // The keyword guess order: first match wins.
        $ranked = array_keys($cats);
        usort($ranked, static fn (string $a, string $b): int => $cats[$a]['keywordRank'] <=> $cats[$b]['keywordRank']);
        $this->assertSame(['Arts and Sciences', 'Feast and Food', 'Court', 'Tournament', 'Class',
            'Roleplay and Quests', 'Battlegame', 'Meeting', 'Administrative', 'Other'], $ranked);
        $this->assertSame(200, EventPlanning::ScheduleBatchMaxOps());
    }

    public function testABatchSentTwiceWithOneTokenAppliesOnce(): void
    {
        [$ctx, $token] = $this->batchContext('tok-once');
        $d = $this->batchDay();
        $existing = $this->fixture->insertSchedule($ctx['detail_id'], 'Existing');
        $ops = [
            $this->addOp('c1', 'Mothman', "$d 12:00", "$d 12:45"),
            $this->addOp('c2', 'Site Tour', "$d 14:00", "$d 14:30"),
            ['op' => 'update', 'id' => $existing, 'fields' => ['Title' => 'Renamed']],
        ];
        $first = $this->batchWithToken($ctx, $token, $ops, 'retry-token-0001');
        $this->assertSame(0, $first['Status']['Status']);
        $this->assertSame(3, $this->fixture->countSchedule($ctx['detail_id']));
        $second = $this->batchWithToken($ctx, $token, $ops, 'retry-token-0001');
        $this->assertSame(0, $second['Status']['Status']);
        $this->assertSame(3, $this->fixture->countSchedule($ctx['detail_id']));
        $this->assertSame($first['Results'], $second['Results']);
        $this->assertSame(1, $this->fixture->countScheduleRequestTokens($ctx['detail_id']));
        // Tokens compare byte for byte.
        $other = $this->batchWithToken($ctx, $token, [$this->addOp('c3', 'Third', "$d 15:00", "$d 15:30")], 'RETRY-TOKEN-0001');
        $this->assertSame(0, $other['Status']['Status']);
        $this->assertSame(4, $this->fixture->countSchedule($ctx['detail_id']));
    }

    public function testAFailedTokenBatchStoresNothingSoACorrectedRetryApplies(): void
    {
        [$ctx, $token] = $this->batchContext('tok-fail');
        $d = $this->batchDay();
        $bad = $this->batchWithToken($ctx, $token, [
            $this->addOp('c1', 'Mothman', "$d 12:00", "$d 12:45"),
            $this->addOp('c2', '', "$d 14:00", "$d 14:30"),
        ], 'retry-token-0002');
        $this->assertNotSame(0, $bad['Status']['Status']);
        $this->assertSame(1, $bad['FailedIndex']);
        $this->assertSame(0, $this->fixture->countSchedule($ctx['detail_id']));
        $this->assertSame(0, $this->fixture->countScheduleRequestTokens($ctx['detail_id']));
        $good = $this->batchWithToken($ctx, $token, [
            $this->addOp('c1', 'Mothman', "$d 12:00", "$d 12:45"),
            $this->addOp('c2', 'Site Tour', "$d 14:00", "$d 14:30"),
        ], 'retry-token-0002');
        $this->assertSame(0, $good['Status']['Status']);
        $this->assertSame(2, $this->fixture->countSchedule($ctx['detail_id']));
    }

    public function testBadRequestTokensAreRefused(): void
    {
        [$ctx, $token] = $this->batchContext('tok-bad');
        $d = $this->batchDay();
        foreach (['short', str_repeat('a', 65), 'has space in it!!', 12345678901234567] as $bad) {
            unset($_SESSION['is_authorized_mundane_id']);
            $r = $this->planning->ApplyScheduleOps([
                'Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'],
                'Ops' => [$this->addOp('c1', 'T', "$d 09:00", "$d 10:00")], 'BatchToken' => $bad,
            ]);
            $this->assertSame(ServiceErrorIds::InvalidParameter, $r['Status']['Status'], var_export($bad, true));
            $this->assertSame(-1, $r['FailedIndex']);
            unset($_SESSION['is_authorized_mundane_id']);
            $c = $this->planning->CopyScheduleInto([
                'Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'],
                'SourceDetailId' => $ctx['detail_id'], 'RequestToken' => $bad,
            ]);
            $this->assertSame(ServiceErrorIds::InvalidParameter, $c['Status']['Status'], var_export($bad, true));
        }
        $this->assertSame(0, $this->fixture->countSchedule($ctx['detail_id']));
    }

    public function testACopySentTwiceWithOneTokenCopiesOnce(): void
    {
        $src = $this->fixture->createPublishedEventOn('tok-copy-src', '2026-10-30');
        $dst = $this->fixture->createPublishedEventOn('tok-copy-dst', '2026-11-06');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $dst['park_id'], AUTH_CREATE, 'tok-copy-admin');
        $this->fixture->insertScheduleAt($src['detail_id'], 'Opening Court', 'Court', '2026-10-30 18:00:00', '2026-10-30 19:00:00');
        $this->fixture->insertScheduleAt($src['detail_id'], 'Feast', 'Feast and Food', '2026-10-30 17:00:00', '2026-10-30 18:00:00');
        $req = ['Token' => $grantor['token'], 'EventId' => $dst['event_id'], 'EventCalendarDetailId' => $dst['detail_id'],
            'SourceDetailId' => $src['detail_id'], 'RequestToken' => 'copy-token-00001'];
        unset($_SESSION['is_authorized_mundane_id']);
        $first = $this->planning->CopyScheduleInto($req);
        $this->assertSame(0, $first['Status']['Status']);
        $this->assertCount(2, $first['Schedule']);
        unset($_SESSION['is_authorized_mundane_id']);
        $second = $this->planning->CopyScheduleInto($req);
        $this->assertSame(0, $second['Status']['Status']);
        $this->assertSame(2, $this->fixture->countSchedule($dst['detail_id']));
        $this->assertSame($first['Schedule'], $second['Schedule']);
        $this->assertSame($first['SkippedFeast'], $second['SkippedFeast']);
        unset($_SESSION['is_authorized_mundane_id']);
        $fresh = $this->planning->CopyScheduleInto(['RequestToken' => 'copy-token-00002'] + $req);
        $this->assertSame(0, $fresh['Status']['Status']);
        $this->assertSame(4, $this->fixture->countSchedule($dst['detail_id']));
    }

    public function testRemovingOrUpdatingAnItemThatIsGoneIsNotAnError(): void
    {
        [$ctx, $token] = $this->batchContext('gone');
        $d = $this->batchDay();
        $gone = $this->fixture->insertSchedule($ctx['detail_id'], 'Removed elsewhere');
        $kept = $this->fixture->insertSchedule($ctx['detail_id'], 'Still here');
        $this->fixture->deleteSchedule($gone);
        $r = $this->batch($ctx, $token, [
            ['op' => 'remove', 'cid' => 'r1', 'id' => $gone],
            ['op' => 'update', 'cid' => 'u1', 'id' => $gone, 'fields' => ['Title' => 'Ghost']],
            ['op' => 'update', 'cid' => 'u2', 'id' => $kept, 'fields' => ['Title' => 'Kept and renamed']],
            $this->addOp('c1', 'New', "$d 09:00", "$d 10:00"),
        ]);
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertSame(['op' => 'remove', 'cid' => 'r1', 'id' => $gone, 'schedule' => null, 'gone' => true], $r['Results'][0]);
        $this->assertSame(['op' => 'update', 'cid' => 'u1', 'id' => $gone, 'schedule' => null, 'gone' => true], $r['Results'][1]);
        $this->assertSame('Kept and renamed', $this->fixture->fetchScheduleRow($kept)['title']);
        $this->assertSame(2, $this->fixture->countSchedule($ctx['detail_id']));
    }

    public function testAnUpdateWithAStaleSeenIsNotAppliedAndAFreshOneIs(): void
    {
        [$ctx, $token] = $this->batchContext('seen');
        $d = $this->batchDay();
        $added = $this->batch($ctx, $token, [$this->addOp('c1', 'Mothman', "$d 12:00", "$d 12:45")]);
        $id = (int) $added['Results'][0]['id'];
        $modified = $added['Results'][0]['schedule']['Modified'];
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $modified);
        // Someone else saves it meanwhile.
        $this->batch($ctx, $token, [['op' => 'update', 'id' => $id, 'fields' => ['Location' => 'Field 2']]]);
        $current = $this->fixture->fetchScheduleModified($id);
        $this->assertNotSame($modified, $current);

        $stale = $this->batch($ctx, $token, [
            ['op' => 'update', 'cid' => 'c1', 'id' => $id, 'seen' => $modified, 'fields' => ['Title' => 'Overwrite']],
            $this->addOp('c2', 'Other', "$d 14:00", "$d 15:00"),
        ]);
        $this->assertSame(0, $stale['Status']['Status']);
        $this->assertTrue($stale['Results'][0]['conflict']);
        $this->assertSame('Mothman', $stale['Results'][0]['schedule']['Title']);
        $this->assertSame('Field 2', $stale['Results'][0]['schedule']['Location']);
        $this->assertSame($current, $stale['Results'][0]['schedule']['Modified']);
        $this->assertSame('Mothman', $this->fixture->fetchScheduleRow($id)['title']);
        $this->assertSame(2, $this->fixture->countSchedule($ctx['detail_id']));

        $fresh = $this->batch($ctx, $token, [['op' => 'update', 'id' => $id, 'seen' => $current, 'fields' => ['Title' => 'Mothman II']]]);
        $this->assertSame(0, $fresh['Status']['Status']);
        $this->assertArrayNotHasKey('conflict', $fresh['Results'][0]);
        $this->assertSame('Mothman II', $this->fixture->fetchScheduleRow($id)['title']);
        $this->assertNotSame($current, $fresh['Results'][0]['schedule']['Modified']);
    }

    public function testTwoUpdatesToOneItemInOneBatchBothApply(): void
    {
        [$ctx, $token] = $this->batchContext('seen-twice');
        $d = $this->batchDay();
        $added = $this->batch($ctx, $token, [$this->addOp('c1', 'Mothman', "$d 12:00", "$d 12:45")]);
        $id = (int) $added['Results'][0]['id'];
        $seen = $added['Results'][0]['schedule']['Modified'];
        $r = $this->batch($ctx, $token, [
            ['op' => 'update', 'id' => $id, 'seen' => $seen, 'fields' => ['Title' => 'First']],
            ['op' => 'update', 'id' => $id, 'seen' => $seen, 'fields' => ['Location' => 'Second']],
        ]);
        $this->assertSame(0, $r['Status']['Status']);
        $row = $this->fixture->fetchScheduleRow($id);
        $this->assertSame(['First', 'Second'], [$row['title'], $row['location']]);
        $this->assertArrayNotHasKey('conflict', $r['Results'][1]);
    }

    public function testALeadsOnlyUpdateAdvancesModified(): void
    {
        [$ctx, $token] = $this->batchContext('leads-mod');
        $d = $this->batchDay();
        $lead = $this->fixture->createPlayer('leads-mod-p');
        $added = $this->batch($ctx, $token, [$this->addOp('c1', 'Mothman', "$d 12:00", "$d 12:45")]);
        $id = (int) $added['Results'][0]['id'];
        $before = $this->fixture->fetchScheduleModified($id);
        $r = $this->batch($ctx, $token, [['op' => 'update', 'id' => $id, 'fields' => ['Leads' => [['MundaneId' => $lead]]]]]);
        $this->assertSame(0, $r['Status']['Status']);
        $after = $this->fixture->fetchScheduleModified($id);
        $this->assertGreaterThan($before, $after);
        $this->assertSame($after, $r['Results'][0]['schedule']['Modified']);

        // The legacy single-item save advances it too, when only the leads change.
        $row = $this->fixture->fetchScheduleRow($id);
        unset($_SESSION['is_authorized_mundane_id']);
        $legacy = $this->planning->UpdateEventSchedule([
            'Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'], 'ScheduleId' => $id,
            'Title' => $row['title'], 'StartTime' => $row['start_time'], 'EndTime' => $row['end_time'],
            'Location' => $row['location'], 'Description' => $row['description'], 'Category' => $row['category'], 'Leads' => [],
        ]);
        $this->assertSame(0, $legacy['Status']['Status']);
        $this->assertGreaterThan($after, $this->fixture->fetchScheduleModified($id));
        $this->assertSame([], $this->fixture->fetchScheduleLeads($id));
    }

    public function testAnAddWithoutLeadsGetsItsCategoryLeadAndAnAddWithEmptyLeadsGetsNone(): void
    {
        [$ctx, $token] = $this->batchContext('fill-add');
        $d = $this->batchDay();
        $warcrat = $this->fixture->createPlayer('fill-add-w');
        $this->saveStaff($ctx, $token, $warcrat, ['Battlegame']);
        $r = $this->batch($ctx, $token, [
            $this->addOp('c1', 'Mothman', "$d 12:00", "$d 12:45", ['Category' => 'Battlegame']),
            $this->addOp('c2', 'Jersey Devil', "$d 13:00", "$d 13:45", ['Category' => 'Battlegame', 'Leads' => []]),
            $this->addOp('c3', 'Naalbinding', "$d 14:00", "$d 14:45", ['Category' => 'Class']),
        ]);
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertSame([['mundane_id' => $warcrat, 'is_auto' => 1]], $this->fixture->fetchScheduleLeads((int) $r['Results'][0]['id']));
        $this->assertSame([true], array_column($r['Results'][0]['schedule']['Leads'], 'Auto'));
        $this->assertSame([], $this->fixture->fetchScheduleLeads((int) $r['Results'][1]['id']));
        $this->assertSame([], $this->fixture->fetchScheduleLeads((int) $r['Results'][2]['id']));

        // Legacy add without a Leads key fills the same way.
        unset($_SESSION['is_authorized_mundane_id']);
        $legacy = $this->planning->AddEventSchedule([
            'Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'],
            'Title' => 'Ditch', 'StartTime' => "$d 15:00:00", 'EndTime' => "$d 16:00:00", 'Category' => 'Battlegame',
        ]);
        $this->assertSame(0, $legacy['Status']['Status']);
        $this->assertSame([['mundane_id' => $warcrat, 'is_auto' => 1]], $this->fixture->fetchScheduleLeads((int) $legacy['Schedule']['EventScheduleId']));
    }

    public function testAnAutoFlagForSomeoneWhoDoesNotLeadTheCategoryIsStoredHandPicked(): void
    {
        [$ctx, $token] = $this->batchContext('auto-sanitise');
        $d = $this->batchDay();
        $classLead = $this->fixture->createPlayer('auto-sanitise-c');
        $this->saveStaff($ctx, $token, $classLead, ['Class']);
        $stranger = $this->fixture->createPlayer('auto-sanitise-s');
        $r = $this->batch($ctx, $token, [$this->addOp('c1', 'Mothman', "$d 12:00", "$d 12:45", [
            'Category' => 'Battlegame', 'Leads' => [['MundaneId' => $classLead, 'Auto' => 1], ['MundaneId' => $stranger, 'Auto' => true]],
        ])]);
        $this->assertSame(0, $r['Status']['Status']);
        $id = (int) $r['Results'][0]['id'];
        $expected = [['mundane_id' => $classLead, 'is_auto' => 0], ['mundane_id' => $stranger, 'is_auto' => 0]];
        usort($expected, static fn (array $a, array $b): int => $a['mundane_id'] <=> $b['mundane_id']);
        $this->assertSame($expected, $this->fixture->fetchScheduleLeads($id));

        // The legacy save sanitises the same way.
        unset($_SESSION['is_authorized_mundane_id']);
        $legacy = $this->planning->AddEventSchedule([
            'Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'],
            'Title' => 'Ditch', 'StartTime' => "$d 15:00:00", 'EndTime' => "$d 16:00:00", 'Category' => 'Battlegame',
            'Leads' => [['MundaneId' => $stranger, 'Auto' => 1]],
        ]);
        $this->assertSame([['mundane_id' => $stranger, 'is_auto' => 0]], $this->fixture->fetchScheduleLeads((int) $legacy['Schedule']['EventScheduleId']));
    }

    public function testACategoryChangeWithoutLeadsSwapsAutoLeadsAndKeepsHandPickedOnes(): void
    {
        [$ctx, $token] = $this->batchContext('swap');
        $d = $this->batchDay();
        $warcrat = $this->fixture->createPlayer('swap-w');
        $teacher = $this->fixture->createPlayer('swap-t');
        $friend = $this->fixture->createPlayer('swap-f');
        $this->saveStaff($ctx, $token, $warcrat, ['Battlegame']);
        $this->saveStaff($ctx, $token, $teacher, ['Class']);
        $r = $this->batch($ctx, $token, [$this->addOp('c1', 'Mothman', "$d 12:00", "$d 12:45", [
            'Category' => 'Battlegame', 'Leads' => [['MundaneId' => $warcrat, 'Auto' => 1], ['MundaneId' => $friend]],
        ])]);
        $id = (int) $r['Results'][0]['id'];
        $r2 = $this->batch($ctx, $token, [['op' => 'update', 'id' => $id, 'fields' => ['Category' => 'Class']]]);
        $this->assertSame(0, $r2['Status']['Status']);
        $leads = array_column($this->fixture->fetchScheduleLeads($id), 'is_auto', 'mundane_id');
        ksort($leads);
        $expected = [$friend => 0, $teacher => 1];
        ksort($expected);
        $this->assertSame($expected, $leads);

        // The legacy update follows the same rule when it is sent no Leads.
        $row = $this->fixture->fetchScheduleRow($id);
        unset($_SESSION['is_authorized_mundane_id']);
        $legacy = $this->planning->UpdateEventSchedule([
            'Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'], 'ScheduleId' => $id,
            'Title' => $row['title'], 'StartTime' => $row['start_time'], 'EndTime' => $row['end_time'], 'Category' => 'Battlegame',
        ]);
        $this->assertSame(0, $legacy['Status']['Status']);
        $leads = array_column($this->fixture->fetchScheduleLeads($id), 'is_auto', 'mundane_id');
        ksort($leads);
        $expected = [$friend => 0, $warcrat => 1];
        ksort($expected);
        $this->assertSame($expected, $leads);
    }

    public function testACopiedItemWithNoLeadGetsAllOfTheTargetsCategoryLeads(): void
    {
        $src = $this->fixture->createPublishedEventOn('copy-fill-src', '2026-10-30');
        $dst = $this->fixture->createPublishedEventOn('copy-fill-dst', '2026-11-06');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $dst['park_id'], AUTH_CREATE, 'copy-fill-admin');
        $warcrat = $this->fixture->createPlayer('copy-fill-w');
        $deputy = $this->fixture->createPlayer('copy-fill-d');
        $other = $this->fixture->createPlayer('copy-fill-o');
        $this->fixture->insertScheduleAt($src['detail_id'], 'Mothman', 'Battlegame', '2026-10-30 12:00:00', '2026-10-30 12:45:00');
        $led = $this->fixture->insertScheduleAt($src['detail_id'], 'Jersey Devil', 'Battlegame', '2026-10-30 14:00:00', '2026-10-30 14:45:00');
        $this->fixture->insertScheduleLead($led, $other);
        $this->saveStaff($dst, $grantor['token'], $warcrat, ['Battlegame']);
        $this->saveStaff($dst, $grantor['token'], $deputy, ['Battlegame']);
        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->planning->CopyScheduleInto(['Token' => $grantor['token'], 'EventId' => $dst['event_id'], 'EventCalendarDetailId' => $dst['detail_id'], 'SourceDetailId' => $src['detail_id']]);
        $this->assertSame(0, $r['Status']['Status']);
        $byTitle = array_column($r['Schedule'], 'EventScheduleId', 'Title');
        // Both Battlegame leads, as an add without Leads would get.
        $expected = [['mundane_id' => $warcrat, 'is_auto' => 1], ['mundane_id' => $deputy, 'is_auto' => 1]];
        usort($expected, static fn (array $a, array $b): int => $a['mundane_id'] <=> $b['mundane_id']);
        $this->assertSame($expected, $this->fixture->fetchScheduleLeads((int) $byTitle['Mothman']));
        $this->assertSame([['mundane_id' => $other, 'is_auto' => 0]], $this->fixture->fetchScheduleLeads((int) $byTitle['Jersey Devil']));

        // The same two leads an add without a Leads key gets on that occurrence.
        unset($_SESSION['is_authorized_mundane_id']);
        $add = $this->planning->ApplyScheduleOps(['Token' => $grantor['token'], 'EventId' => $dst['event_id'],
            'EventCalendarDetailId' => $dst['detail_id'],
            'Ops' => [$this->addOp('c1', 'Ditch', '2026-11-06 15:00', '2026-11-06 16:00', ['Category' => 'Battlegame'])]]);
        $this->assertSame($expected, $this->fixture->fetchScheduleLeads((int) $add['Results'][0]['id']));
    }

    public function testLeadsMustBeEligibleUnlessAlreadyOnTheItem(): void
    {
        [$ctx, $token] = $this->batchContext('eligible');
        $d = $this->batchDay();
        $old = $this->fixture->createPlayer('eligible-old');
        $inactive = $this->fixture->createPlayer('eligible-inactive');
        $this->fixture->setPlayerActive($inactive, false);
        $id = (int) $this->batch($ctx, $token, [$this->addOp('c1', 'Mothman', "$d 12:00", "$d 12:45", ['Leads' => [['MundaneId' => $old]]])])['Results'][0]['id'];

        $r = $this->batch($ctx, $token, [
            $this->addOp('c2', 'Other', "$d 14:00", "$d 15:00"),
            ['op' => 'update', 'id' => $id, 'fields' => ['Leads' => [['MundaneId' => $old], ['MundaneId' => $inactive]]]],
        ]);
        $this->assertSame(ServiceErrorIds::InvalidParameter, $r['Status']['Status']);
        $this->assertSame('That player cannot be a lead.', $r['Status']['Detail']);
        $this->assertSame(1, $r['FailedIndex']);
        $this->assertSame(1, $this->fixture->countSchedule($ctx['detail_id']));
        $this->assertSame([['mundane_id' => $old, 'is_auto' => 0]], $this->fixture->fetchScheduleLeads($id));

        // A lead who has since gone inactive does not make an unrelated edit fail.
        $this->fixture->setPlayerActive($old, false);
        $planning = new EventPlanning(); // a fresh request: no eligibility remembered
        unset($_SESSION['is_authorized_mundane_id']);
        $ok = $planning->ApplyScheduleOps(['Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'],
            'Ops' => [['op' => 'update', 'id' => $id, 'fields' => ['Title' => 'Mothman II', 'Leads' => [['MundaneId' => $old]]]]]]);
        $this->assertSame(0, $ok['Status']['Status']);
        $this->assertSame([['mundane_id' => $old, 'is_auto' => 0]], $this->fixture->fetchScheduleLeads($id));

        // The legacy save applies the same rule.
        $row = $this->fixture->fetchScheduleRow($id);
        $legacyReq = ['Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'], 'ScheduleId' => $id,
            'Title' => $row['title'], 'StartTime' => $row['start_time'], 'EndTime' => $row['end_time'], 'Category' => $row['category']];
        unset($_SESSION['is_authorized_mundane_id']);
        $refused = $planning->UpdateEventSchedule($legacyReq + ['Leads' => [['MundaneId' => $old], ['MundaneId' => $inactive]]]);
        $this->assertSame('That player cannot be a lead.', $refused['Detail']);
        unset($_SESSION['is_authorized_mundane_id']);
        $kept = $planning->UpdateEventSchedule($legacyReq + ['Leads' => [['MundaneId' => $old]]]);
        $this->assertSame(0, $kept['Status']['Status']);
        $this->assertSame([['mundane_id' => $old, 'is_auto' => 0]], $this->fixture->fetchScheduleLeads($id));
    }

    public function testAnIneligibleCategoryLeadOfTheItemsCategoryMayStillLeadIt(): void
    {
        [$ctx, $token] = $this->batchContext('lead-inel');
        $d = $this->batchDay();
        $warcrat = $this->fixture->createPlayer('lead-inel-w');
        $this->saveStaff($ctx, $token, $warcrat, ['Battlegame']);
        // Gone inactive after being made the Battlegame lead.
        $this->fixture->setPlayerActive($warcrat, false);
        $stranger = $this->fixture->createPlayer('lead-inel-s');
        $this->fixture->setPlayerActive($stranger, false);
        $asAuto = ['MundaneId' => $warcrat, 'Auto' => true];

        // Every client add in Battlegame lists the category's leads as auto leads.
        $r = $this->batch($ctx, $token, [$this->addOp('c1', 'Mothman', "$d 12:00", "$d 12:45", ['Category' => 'Battlegame', 'Leads' => [$asAuto]])]);
        $this->assertSame(0, $r['Status']['Status'], json_encode($r['Status']));
        $this->assertSame([['mundane_id' => $warcrat, 'is_auto' => 1]], $this->fixture->fetchScheduleLeads((int) $r['Results'][0]['id']));

        // Someone ineligible who does not lead the category is still refused...
        $refused = $this->batch($ctx, $token, [$this->addOp('c2', 'Jersey Devil', "$d 13:00", "$d 13:45", [
            'Category' => 'Battlegame', 'Leads' => [$asAuto, ['MundaneId' => $stranger]],
        ])]);
        $this->assertSame(ServiceErrorIds::InvalidParameter, $refused['Status']['Status']);
        $this->assertSame('That player cannot be a lead.', $refused['Status']['Detail']);
        $this->assertSame(0, $refused['FailedIndex']);
        // ...and so is the Battlegame lead on an item of another category.
        $otherCategory = $this->batch($ctx, $token, [$this->addOp('c3', 'Naalbinding', "$d 14:00", "$d 14:45", [
            'Category' => 'Class', 'Leads' => [['MundaneId' => $warcrat]],
        ])]);
        $this->assertSame('That player cannot be a lead.', $otherCategory['Status']['Detail']);
        $this->assertSame(1, $this->fixture->countSchedule($ctx['detail_id']));

        // A category change in the batch takes the new category's leads the same way.
        $classId = (int) $this->batch($ctx, $token, [$this->addOp('c4', 'Tablet weaving', "$d 15:00", "$d 15:45", ['Category' => 'Class', 'Leads' => []])])['Results'][0]['id'];
        $moved = $this->batch($ctx, $token, [['op' => 'update', 'id' => $classId, 'fields' => ['Category' => 'Battlegame', 'Leads' => [$asAuto]]]]);
        $this->assertSame(0, $moved['Status']['Status'], json_encode($moved['Status']));
        $this->assertSame([['mundane_id' => $warcrat, 'is_auto' => 1]], $this->fixture->fetchScheduleLeads($classId));

        // The legacy form behaves the same, for a suspended lead as for an inactive one.
        $this->fixture->setPlayerActive($warcrat, true);
        $this->fixture->setPlayerSuspended($warcrat, true);
        $planning = new EventPlanning(); // a fresh request: no eligibility remembered
        $legacy = ['Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'],
            'Title' => 'Ditch', 'StartTime' => "$d 16:00:00", 'EndTime' => "$d 17:00:00", 'Category' => 'Battlegame'];
        unset($_SESSION['is_authorized_mundane_id']);
        $added = $planning->AddEventSchedule($legacy + ['Leads' => [$asAuto]]);
        $this->assertSame(0, $added['Status']['Status'], json_encode($added));
        $this->assertSame([['mundane_id' => $warcrat, 'is_auto' => 1]], $this->fixture->fetchScheduleLeads((int) $added['Schedule']['EventScheduleId']));
        unset($_SESSION['is_authorized_mundane_id']);
        $legacyRefused = $planning->AddEventSchedule($legacy + ['Leads' => [$asAuto, ['MundaneId' => $stranger]]]);
        $this->assertSame('That player cannot be a lead.', $legacyRefused['Detail'] ?? null);
        unset($_SESSION['is_authorized_mundane_id']);
        $legacyOther = $planning->AddEventSchedule(['Category' => 'Class', 'Leads' => [['MundaneId' => $warcrat]]] + $legacy);
        $this->assertSame('That player cannot be a lead.', $legacyOther['Detail'] ?? null);
        $this->assertSame(3, $this->fixture->countSchedule($ctx['detail_id']));
    }

    public function testTheLegacyUpdateRefusesAStaleSeenAndSavesWithAFreshOneOrNone(): void
    {
        [$ctx, $token] = $this->batchContext('legacy-seen');
        $d = $this->batchDay();
        $lead = $this->fixture->createPlayer('legacy-seen-l');
        $newLead = $this->fixture->createPlayer('legacy-seen-n');
        $added = $this->batch($ctx, $token, [$this->addOp('c1', 'Mothman', "$d 12:00", "$d 12:45", ['Leads' => [['MundaneId' => $lead]]])]);
        $id = (int) $added['Results'][0]['id'];
        $seen = $added['Results'][0]['schedule']['Modified'];
        // Someone else moves it after the form was opened.
        $this->batch($ctx, $token, [['op' => 'update', 'id' => $id, 'fields' => ['Location' => 'Field 2']]]);
        $current = $this->fixture->fetchScheduleModified($id);
        $this->assertNotSame($seen, $current);

        $form = static fn (string $title, array $more = []): array => [
            'Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'], 'ScheduleId' => $id,
            'Title' => $title, 'StartTime' => "$d 13:00:00", 'EndTime' => "$d 13:45:00", 'Location' => 'Field 1',
            'Description' => '', 'Category' => 'Other', 'Leads' => [['MundaneId' => $newLead]],
        ] + $more;

        unset($_SESSION['is_authorized_mundane_id']);
        $stale = $this->planning->UpdateEventSchedule($form('Overwrite', ['Seen' => $seen]));
        $this->assertSame(ServiceErrorIds::InvalidParameter, $stale['Status'] ?? null, json_encode($stale));
        $this->assertSame('Someone else changed this item. Close this form and open it again to see their version.', $stale['Detail']);
        // Nothing was saved, the leads included.
        $row = $this->fixture->fetchScheduleRow($id);
        $this->assertSame(['Mothman', 'Field 2', "$d 12:00:00"], [$row['title'], $row['location'], $row['start_time']]);
        $this->assertSame([['mundane_id' => $lead, 'is_auto' => 0]], $this->fixture->fetchScheduleLeads($id));
        $this->assertSame($current, $this->fixture->fetchScheduleModified($id));

        unset($_SESSION['is_authorized_mundane_id']);
        $fresh = $this->planning->UpdateEventSchedule($form('Mothman II', ['Seen' => $current]));
        $this->assertSame(0, $fresh['Status']['Status'], json_encode($fresh));
        $row = $this->fixture->fetchScheduleRow($id);
        $this->assertSame(['Mothman II', 'Field 1', "$d 13:00:00"], [$row['title'], $row['location'], $row['start_time']]);
        $this->assertSame([['mundane_id' => $newLead, 'is_auto' => 0]], $this->fixture->fetchScheduleLeads($id));
        // The reply carries the new version, so the next save from the page can send it.
        $after = $this->fixture->fetchScheduleModified($id);
        $this->assertNotSame($current, $after);
        $this->assertSame($after, $fresh['Schedule']['Modified']);

        // Without Seen (or with an empty one) it saves as before.
        foreach (['Mothman III' => [], 'Mothman IV' => ['Seen' => '']] as $title => $more) {
            unset($_SESSION['is_authorized_mundane_id']);
            $none = $this->planning->UpdateEventSchedule($form($title, $more));
            $this->assertSame(0, $none['Status']['Status'], json_encode($none));
            $this->assertSame($title, $this->fixture->fetchScheduleRow($id)['title']);
        }
    }

    public function testTheLegacyUpdateWaitsForAConcurrentSaveAndRefusesTheSeenItHad(): void
    {
        [$ctx, $token] = $this->batchContext('legacy-race');
        $d = $this->batchDay();
        $added = $this->batch($ctx, $token, [$this->addOp('c1', 'Mothman', "$d 12:00", "$d 12:45")]);
        $id = (int) $added['Results'][0]['id'];
        $seen = $added['Results'][0]['schedule']['Modified'];

        // Organiser A has saved the item and not committed yet when B's form save reaches it.
        $other = $this->fixture->beginConcurrentWrite([
            'UPDATE ' . DB_PREFIX . "event_schedule SET title = 'Saved by A',
                 modified = GREATEST(NOW(), modified + INTERVAL 1 SECOND) WHERE event_schedule_id = $id",
        ]);
        try {
            unset($_SESSION['is_authorized_mundane_id']);
            $r = $this->planning->UpdateEventSchedule([
                'Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'], 'ScheduleId' => $id,
                'Title' => 'Saved by B', 'StartTime' => "$d 12:00:00", 'EndTime' => "$d 12:45:00", 'Category' => 'Other',
                'Leads' => [], 'Seen' => $seen,
            ]);
        } finally {
            $this->assertNull($this->fixture->endConcurrentWrite($other));
        }
        $this->assertSame('Someone else changed this item. Close this form and open it again to see their version.', $r['Detail'] ?? null, json_encode($r));
        $this->assertSame('Saved by A', $this->fixture->fetchScheduleRow($id)['title']);
    }

    public function testAFeastOnlyEditorCanSaveMealsWithSeenAgainAndAgain(): void
    {
        $ctx = $this->fixture->createPublishedEvent('feast-seen');
        $staff = $this->fixture->createGrantorWithoutAuth('feast-seen-staff');
        $this->fixture->insertStaff($ctx['detail_id'], $staff['mundane_id'], 'Feast', canFeast: true);
        $id = $this->fixture->insertSchedule($ctx['detail_id'], 'Feast', 'Feast and Food');
        $seen = $this->fixture->fetchScheduleModified($id);
        $save = function (string $menu, string $seen) use ($ctx, $staff, $id): array {
            unset($_SESSION['is_authorized_mundane_id']);
            return $this->planning->UpdateEventSchedule([
                'Token' => $staff['token'], 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'],
                'ScheduleId' => $id, 'Title' => 'Feast', 'Category' => 'Feast and Food', 'Menu' => $menu,
                // Every field is written for a feast editor too, so the times are sent.
                'StartTime' => date('Y-m-d H:i:s', strtotime('+7 days 10:00')),
                'EndTime' => date('Y-m-d H:i:s', strtotime('+7 days 11:00')),
                'Leads' => [], 'Seen' => $seen,
            ]);
        };

        // Each save sends the version the previous one replied with.
        $first = $save('Roast beast', $seen);
        $this->assertSame(0, $first['Status']['Status'], json_encode($first));
        $second = $save('Stew', $first['Schedule']['Modified']);
        $this->assertSame(0, $second['Status']['Status'], json_encode($second));
        $this->assertSame('Stew', $this->fixture->fetchScheduleRow($id)['menu'] ?? null);
        // Their stale version is refused like anyone's.
        $stale = $save('Gruel', $seen);
        $this->assertSame('Someone else changed this item. Close this form and open it again to see their version.', $stale['Detail'] ?? null);
        $this->assertSame('Stew', $this->fixture->fetchScheduleRow($id)['menu'] ?? null);
    }

    public function testLeadsAreDeduplicatedAndCapped(): void
    {
        [$ctx, $token] = $this->batchContext('lead-cap');
        $d = $this->batchDay();
        $people = [];
        for ($i = 0; $i < 21; $i++) {
            $people[] = $this->fixture->createPlayer('lead-cap-' . $i);
        }
        $twenty = array_map(static fn (int $id): array => ['MundaneId' => $id], array_slice($people, 0, 20));
        $ok = $this->batch($ctx, $token, [$this->addOp('c1', 'Mothman', "$d 12:00", "$d 12:45", ['Leads' => array_merge($twenty, $twenty)])]);
        $this->assertSame(0, $ok['Status']['Status']);
        $this->assertSame(20, $this->fixture->countScheduleLeads((int) $ok['Results'][0]['id']));

        $all = array_map(static fn (int $id): array => ['MundaneId' => $id], $people);
        $tooMany = $this->batch($ctx, $token, [$this->addOp('c2', 'Jersey Devil', "$d 14:00", "$d 14:45", ['Leads' => $all])]);
        $this->assertSame('Too many leads on one item.', $tooMany['Status']['Detail']);
        $this->assertSame(0, $tooMany['FailedIndex']);
        $this->assertSame(1, $this->fixture->countSchedule($ctx['detail_id']));

        unset($_SESSION['is_authorized_mundane_id']);
        $legacy = $this->planning->AddEventSchedule([
            'Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'],
            'Title' => 'Ditch', 'StartTime' => "$d 15:00:00", 'EndTime' => "$d 16:00:00", 'Category' => 'Battlegame', 'Leads' => $all,
        ]);
        $this->assertSame('Too many leads on one item.', $legacy['Detail']);
        $this->assertSame(1, $this->fixture->countSchedule($ctx['detail_id']));
    }

    public function testBatchTimesAreStrictAndWithinAWeekOfTheEvent(): void
    {
        [$ctx, $token] = $this->batchContextOn('strict-times', '2026-12-01');   // 2026-12-01 09:00 to 2026-12-03 16:00
        $ok = $this->batch($ctx, $token, [
            $this->addOp('c1', 'Short form', '2026-12-01 12:00', '2026-12-01 12:45'),
            $this->addOp('c2', 'Long form', '2026-12-02 12:00:30', '2026-12-02 13:00:00'),
            $this->addOp('c3', 'No length', '2026-12-02 15:00', '2026-12-02 15:00'),
            $this->addOp('c4', 'Week before', '2026-11-24 00:00', '2026-11-24 01:00'),
            $this->addOp('c5', 'Week after', '2026-12-10 23:59', '2026-12-11 00:30'),
        ]);
        $this->assertSame(0, $ok['Status']['Status']);
        $this->assertSame(
            ['2026-12-01 12:00:00', '2026-12-02 12:00:30', '2026-12-02 15:00:00'],
            [$ok['Results'][0]['schedule']['StartTime'], $ok['Results'][1]['schedule']['StartTime'], $ok['Results'][2]['schedule']['EndTime']]
        );

        $refusals = [
            ['2026-12-01T12:00', '2026-12-01 13:00', 'Invalid time format.'],
            ['12/01/2026 12:00', '2026-12-01 13:00', 'Invalid time format.'],
            ['2026-02-30 12:00', '2026-03-01 13:00', 'Invalid time format.'],
            ['2026-12-01 25:00', '2026-12-01 26:00', 'Invalid time format.'],
            ['2026-12-01 12:00', '2026-12-01 11:59', 'End time cannot be before start time.'],
            ['2026-11-23 23:59', '2026-11-24 01:00', 'That time is outside the event.'],
            ['2026-12-11 00:00', '2026-12-11 01:00', 'That time is outside the event.'],
        ];
        foreach ($refusals as [$start, $end, $message]) {
            $r = $this->batch($ctx, $token, [$this->addOp('x', 'Refused', $start, $end)]);
            $this->assertSame($message, $r['Status']['Detail'] ?? null, "$start - $end");
            $this->assertSame(0, $r['FailedIndex']);
        }
        // An update that moves the start out of the window is refused as well.
        $id = (int) $ok['Results'][0]['id'];
        $moved = $this->batch($ctx, $token, [['op' => 'update', 'id' => $id, 'fields' => ['StartTime' => '2026-12-20 12:00', 'EndTime' => '2026-12-20 13:00']]]);
        $this->assertSame('That time is outside the event.', $moved['Status']['Detail']);
        $this->assertSame('2026-12-01 12:00:00', $this->fixture->fetchScheduleRow($id)['start_time']);
        $this->assertSame(5, $this->fixture->countSchedule($ctx['detail_id']));
    }

    public function testBatchTextLongerThanItsColumnIsRefusedByName(): void
    {
        [$ctx, $token] = $this->batchContext('text-limits');
        $d = $this->batchDay();
        $cases = [
            ['Title', 256, 'The title is longer than 255 characters.'],
            ['Location', 256, 'The location is longer than 255 characters.'],
            ['Category', 51, 'The category is longer than 50 characters.'],
            ['SecondaryCategory', 51, 'The secondary category is longer than 50 characters.'],
            ['Description', 4001, 'The description is longer than 4000 characters.'],
            ['Menu', 4001, 'The menu is longer than 4000 characters.'],
            ['Dietary', 501, 'The dietary note is longer than 500 characters.'],
            ['Allergens', 501, 'The allergens note is longer than 500 characters.'],
        ];
        foreach ($cases as [$field, $length, $message]) {
            $item = ['Title' => 'T', 'StartTime' => "$d 09:00", 'EndTime' => "$d 10:00"];
            $item[$field] = str_repeat('é', $length);
            $r = $this->batch($ctx, $token, [['op' => 'add', 'cid' => 'c1', 'item' => $item]]);
            $this->assertSame($message, $r['Status']['Detail'] ?? null, $field);
            $atLimit = $item;
            $atLimit[$field] = str_repeat('é', $length - 1);
            if ($field === 'Category' || $field === 'SecondaryCategory') {
                continue; // an unknown category at the limit is coerced, not refused; nothing more to check
            }
            $this->assertSame(0, $this->batch($ctx, $token, [['op' => 'add', 'cid' => 'c1', 'item' => $atLimit]])['Status']['Status'], $field);
        }
        $id = (int) $this->batch($ctx, $token, [$this->addOp('c1', 'Short', "$d 09:00", "$d 10:00")])['Results'][0]['id'];
        $r = $this->batch($ctx, $token, [['op' => 'update', 'id' => $id, 'fields' => ['Location' => str_repeat('x', 256)]]]);
        $this->assertSame('The location is longer than 255 characters.', $r['Status']['Detail']);
    }

    // ---- Review fixes, round 2: bad end dates, concurrent saves, staff-side leads ----

    public function testAnOccurrenceWithAZeroEndDateTakesItsWindowFromTheStartDate(): void
    {
        [$ctx, $token] = $this->batchContextOn('zero-end', '2026-12-01');
        $this->fixture->setDetailEnd($ctx['detail_id'], '0000-00-00 00:00:00');
        $ok = $this->batch($ctx, $token, [$this->addOp('c1', 'Opening Court', '2026-12-01 18:00', '2026-12-01 19:00')]);
        $this->assertSame(0, $ok['Status']['Status'], json_encode($ok['Status']));
        $late = $this->batch($ctx, $token, [$this->addOp('c1', 'Too late', '2026-12-09 12:00', '2026-12-09 13:00')]);
        $this->assertSame('That time is outside the event.', $late['Status']['Detail']);
    }

    public function testAnOccurrenceThatEndsBeforeItStartsTakesItsWindowFromTheStartDate(): void
    {
        [$ctx, $token] = $this->batchContextOn('end-before-start', '2026-12-01');
        $this->fixture->setDetailEnd($ctx['detail_id'], '2026-10-01 16:00:00');
        $ok = $this->batch($ctx, $token, [$this->addOp('c1', 'Opening Court', '2026-12-01 18:00', '2026-12-01 19:00')]);
        $this->assertSame(0, $ok['Status']['Status'], json_encode($ok['Status']));
        $this->assertSame(1, $this->fixture->countSchedule($ctx['detail_id']));
    }

    public function testAnUpdateWaitsForAConcurrentSaveAndReportsTheCommittedRowAsAConflict(): void
    {
        [$ctx, $token] = $this->batchContext('race-seen');
        $d = $this->batchDay();
        $added = $this->batch($ctx, $token, [$this->addOp('c1', 'Mothman', "$d 12:00", "$d 12:45")]);
        $id = (int) $added['Results'][0]['id'];
        $seen = $added['Results'][0]['schedule']['Modified'];

        // Organiser A has saved the item and not committed yet when B's batch reaches it.
        $other = $this->fixture->beginConcurrentWrite([
            "UPDATE " . DB_PREFIX . "event_schedule SET title = 'Saved by A',
                 modified = GREATEST(NOW(), modified + INTERVAL 1 SECOND) WHERE event_schedule_id = $id",
        ]);
        try {
            // The add first gives B's transaction an earlier read, as a real batch has.
            $r = $this->batch($ctx, $token, [
                $this->addOp('c2', 'Other', "$d 14:00", "$d 15:00"),
                ['op' => 'update', 'cid' => 'u1', 'id' => $id, 'seen' => $seen, 'fields' => ['Title' => 'Saved by B']],
            ]);
        } finally {
            $this->fixture->endConcurrentWrite($other);
        }
        $this->assertSame(0, $r['Status']['Status'], json_encode($r['Status']));
        $this->assertTrue($r['Results'][1]['conflict'] ?? false);
        $committed = $this->fixture->fetchScheduleModified($id);
        $this->assertNotSame($seen, $committed);
        $this->assertSame('Saved by A', $r['Results'][1]['schedule']['Title']);
        $this->assertSame($committed, $r['Results'][1]['schedule']['Modified']);
        $this->assertSame('Saved by A', $this->fixture->fetchScheduleRow($id)['title']);
        $this->assertSame(2, $this->fixture->countSchedule($ctx['detail_id']));
    }

    public function testAnUpdateOrRemoveWaitsForAConcurrentRemovalAndReportsGone(): void
    {
        [$ctx, $token] = $this->batchContext('race-gone');
        $d = $this->batchDay();
        $added = $this->batch($ctx, $token, [$this->addOp('c1', 'Mothman', "$d 12:00", "$d 12:45")]);
        $id = (int) $added['Results'][0]['id'];
        $seen = $added['Results'][0]['schedule']['Modified'];

        $other = $this->fixture->beginConcurrentWrite([
            'DELETE FROM ' . DB_PREFIX . "event_schedule_lead WHERE event_schedule_id = $id",
            'DELETE FROM ' . DB_PREFIX . "event_schedule WHERE event_schedule_id = $id",
        ]);
        try {
            $r = $this->batch($ctx, $token, [
                $this->addOp('c2', 'Other', "$d 14:00", "$d 15:00"),
                ['op' => 'update', 'cid' => 'u1', 'id' => $id, 'seen' => $seen, 'fields' => ['Title' => 'Saved by B']],
                ['op' => 'remove', 'cid' => 'r1', 'id' => $id],
            ]);
        } finally {
            $this->fixture->endConcurrentWrite($other);
        }
        $this->assertSame(0, $r['Status']['Status'], json_encode($r['Status']));
        $this->assertSame(['op' => 'update', 'cid' => 'u1', 'id' => $id, 'schedule' => null, 'gone' => true], $r['Results'][1]);
        $this->assertSame(['op' => 'remove', 'cid' => 'r1', 'id' => $id, 'schedule' => null, 'gone' => true], $r['Results'][2]);
        $this->assertSame(1, $this->fixture->countSchedule($ctx['detail_id']));
    }

    public function testStaffSideLeadChangesAdvanceModifiedSoAnOldSeenConflicts(): void
    {
        [$ctx, $token] = $this->batchContext('staff-mod');
        $d = $this->batchDay();
        $warcrat = $this->fixture->createPlayer('staff-mod-w');
        $friend = $this->fixture->createPlayer('staff-mod-f');
        $added = $this->batch($ctx, $token, [$this->addOp('c1', 'Mothman', "$d 12:00", "$d 12:45", ['Category' => 'Battlegame'])]);
        $id = (int) $added['Results'][0]['id'];
        $seen = $added['Results'][0]['schedule']['Modified'];

        // The fill rule gives the item a lead: its version moves on.
        $staff = $this->saveStaff($ctx, $token, $warcrat, ['Battlegame']);
        $this->assertSame(1, $staff['LeadsAdded']);
        $filled = $this->fixture->fetchScheduleModified($id);
        $this->assertGreaterThan($seen, $filled);
        $r = $this->batch($ctx, $token, [['op' => 'update', 'id' => $id, 'seen' => $seen, 'fields' => ['Leads' => []]]]);
        $this->assertTrue($r['Results'][0]['conflict'] ?? false);
        $this->assertSame($filled, $r['Results'][0]['schedule']['Modified']);
        $this->assertSame([['mundane_id' => $warcrat, 'is_auto' => 1]], $this->fixture->fetchScheduleLeads($id));

        // The removal rule takes it away again: so does its version.
        $dropped = $this->saveStaff($ctx, $token, $warcrat, [], (int) $staff['Staff']['EventStaffId']);
        $this->assertSame(1, $dropped['LeadsRemoved']);
        $this->assertGreaterThan($filled, $this->fixture->fetchScheduleModified($id));
        $r2 = $this->batch($ctx, $token, [['op' => 'update', 'id' => $id, 'seen' => $filled, 'fields' => ['Leads' => [['MundaneId' => $friend]]]]]);
        $this->assertTrue($r2['Results'][0]['conflict'] ?? false);
        $this->assertSame([], $this->fixture->fetchScheduleLeads($id));
    }

    /** The staff side's `modified` bump, which locks the item before its leads are written. */
    private function staffBumpPattern(): string
    {
        return '%UPDATE ' . DB_PREFIX . 'event_schedule SET modified%';
    }

    public function testAStaffRemovalRacingABatchOnTheSameItemLocksTheItemFirstSoBothFinish(): void
    {
        [$ctx, $token] = $this->batchContext('race-staff-rm');
        $warcrat = $this->fixture->createPlayer('race-staff-rm-w');
        $friend = $this->fixture->createPlayer('race-staff-rm-f');
        $id = $this->fixture->insertSchedule($ctx['detail_id'], 'Mothman', 'Battlegame');
        $staffId = (int) $this->saveStaff($ctx, $token, $warcrat, ['Battlegame'])['Staff']['EventStaffId'];
        $this->assertSame([['mundane_id' => $warcrat, 'is_auto' => 1]], $this->fixture->fetchScheduleLeads($id));

        // A batch has locked the item (as its FOR UPDATE does) and, once the staff side
        // is waiting on it, rewrites the item's leads, as replaceScheduleLeads does.
        // Leads first on the staff side deadlocks here; item first just waits.
        $other = $this->fixture->beginConcurrentWrite(
            ['SELECT event_schedule_id FROM ' . DB_PREFIX . "event_schedule WHERE event_schedule_id = $id FOR UPDATE"],
            $this->staffBumpPattern(),
            [
                'DELETE FROM ' . DB_PREFIX . "event_schedule_lead WHERE event_schedule_id = $id",
                'INSERT INTO ' . DB_PREFIX . "event_schedule_lead (event_schedule_id, mundane_id, is_auto) VALUES ($id, $friend, 0)",
                'UPDATE ' . DB_PREFIX . "event_schedule SET title = 'Saved by the batch',
                     modified = GREATEST(NOW(), modified + INTERVAL 1 SECOND) WHERE event_schedule_id = $id",
            ]
        );
        try {
            unset($_SESSION['is_authorized_mundane_id']);
            $r = $this->planning->RemoveEventStaff([
                'Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'], 'StaffId' => $staffId,
            ]);
        } finally {
            $batchError = $this->fixture->endConcurrentWrite($other);
        }
        $this->assertNull($batchError);
        $this->assertSame(0, $r['Status']['Status'], json_encode($r['Status']));
        // The batch had already replaced the auto lead: nothing left to remove.
        $this->assertSame(0, $r['LeadsRemoved']);
        $this->assertFalse($this->fixture->staffExists($staffId));
        $this->assertSame([['mundane_id' => $friend, 'is_auto' => 0]], $this->fixture->fetchScheduleLeads($id));
        $this->assertSame('Saved by the batch', $this->fixture->fetchScheduleRow($id)['title']);
    }

    public function testAStaffFillRacingABatchThatGivesTheItemALeadDoesNotAddASecond(): void
    {
        [$ctx, $token] = $this->batchContext('race-staff-fill');
        $warcrat = $this->fixture->createPlayer('race-staff-fill-w');
        $friend = $this->fixture->createPlayer('race-staff-fill-f');
        $id = $this->fixture->insertSchedule($ctx['detail_id'], 'Mothman', 'Battlegame');

        $other = $this->fixture->beginConcurrentWrite(
            ['SELECT event_schedule_id FROM ' . DB_PREFIX . "event_schedule WHERE event_schedule_id = $id FOR UPDATE"],
            $this->staffBumpPattern(),
            [
                'INSERT INTO ' . DB_PREFIX . "event_schedule_lead (event_schedule_id, mundane_id, is_auto) VALUES ($id, $friend, 0)",
                'UPDATE ' . DB_PREFIX . "event_schedule SET modified = GREATEST(NOW(), modified + INTERVAL 1 SECOND)
                     WHERE event_schedule_id = $id",
            ]
        );
        try {
            $r = $this->saveStaff($ctx, $token, $warcrat, ['Battlegame']);
        } finally {
            $batchError = $this->fixture->endConcurrentWrite($other);
        }
        $this->assertNull($batchError);
        $this->assertSame(0, $r['Status']['Status'], json_encode($r['Status']));
        // The item had a lead by the time the fill could lock it: it is not filled.
        $this->assertSame(0, $r['LeadsAdded']);
        $this->assertSame([['mundane_id' => $friend, 'is_auto' => 0]], $this->fixture->fetchScheduleLeads($id));
    }

    public function testAFailedLockingReadFailsTheBatchInsteadOfReportingTheItemGone(): void
    {
        [$ctx, $token] = $this->batchContext('lock-timeout');
        $d = $this->batchDay();
        $added = $this->batch($ctx, $token, [$this->addOp('c1', 'Mothman', "$d 12:00", "$d 12:45")]);
        $id = (int) $added['Results'][0]['id'];

        // Another save holds the item and does not let go; this connection gives up after 1 second.
        $other = $this->fixture->holdRowLocks([
            'UPDATE ' . DB_PREFIX . "event_schedule SET title = 'Held' WHERE event_schedule_id = $id",
        ]);
        $db = $GLOBALS['DB'];
        $db->Clear();
        $db->ExecuteChecked('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $update = $this->batch($ctx, $token, [['op' => 'update', 'cid' => 'u1', 'id' => $id, 'fields' => ['Title' => 'Saved by B']]]);
            $remove = $this->batch($ctx, $token, [['op' => 'remove', 'cid' => 'r1', 'id' => $id]]);
            unset($_SESSION['is_authorized_mundane_id']);
            $legacy = $this->planning->UpdateEventSchedule([
                'Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'],
                'ScheduleId' => $id, 'Title' => 'Saved by C', 'StartTime' => "$d 12:00", 'EndTime' => "$d 12:45",
            ]);
        } finally {
            $db->Clear();
            $db->ExecuteChecked('SET SESSION innodb_lock_wait_timeout = DEFAULT');
            $this->fixture->releaseRowLocks($other);
        }
        foreach ([$update, $remove] as $r) {
            $this->assertNotSame(0, $r['Status']['Status']);
            $this->assertSame('Could not save the schedule.', $r['Status']['Detail'], json_encode($r));
            // A processing failure names no op: the client holds the batch for Retry
            // instead of undoing that op as refused.
            $this->assertSame(-1, $r['FailedIndex']);
            $this->assertArrayNotHasKey('Results', $r);
        }
        $this->assertSame('Could not save the schedule.', $legacy['Detail'] ?? null, json_encode($legacy));
        $this->assertTrue($this->fixture->scheduleExists($id, $ctx['detail_id']));
        $this->assertSame('Mothman', $this->fixture->fetchScheduleRow($id)['title']);
    }

    // ---- Feast editors edit feast items; a schedule ceiling; fewer statements ----

    /** @return array{0: array, 1: array{mundane_id: int, token: string}} an event and a staff member with can_feast only */
    private function feastEditorContext(string $tag): array
    {
        $ctx = $this->fixture->createPublishedEvent($tag);
        $staff = $this->fixture->createGrantorWithoutAuth($tag . '-feast');
        $this->fixture->insertStaff($ctx['detail_id'], $staff['mundane_id'], 'Feast', canFeast: true);

        return [$ctx, $staff];
    }

    /** The legacy form's update as a feast editor would post it, every field sent. */
    private function legacyUpdate(array $ctx, string $token, int $scheduleId, array $fields): array
    {
        unset($_SESSION['is_authorized_mundane_id']);
        return $this->planning->UpdateEventSchedule($fields + [
            'Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'],
            'ScheduleId' => $scheduleId, 'Title' => 'Feast', 'Location' => 'Main', 'Description' => '',
            'StartTime' => date('Y-m-d H:i:s', strtotime('+7 days 10:00')),
            'EndTime' => date('Y-m-d H:i:s', strtotime('+7 days 11:00')),
            'Category' => 'Feast and Food', 'SecondaryCategory' => '',
        ]);
    }

    public function testAFeastEditorChangesEveryFieldOfAFeastItem(): void
    {
        [$ctx, $staff] = $this->feastEditorContext('feast-all');
        $id = $this->fixture->insertSchedule($ctx['detail_id'], 'Feast', 'Feast and Food');
        $cook = $this->fixture->createPlayer('feast-all-cook');
        $seen = $this->fixture->fetchScheduleModified($id);
        $d = $this->batchDay();

        $r = $this->legacyUpdate($ctx, $staff['token'], $id, [
            'Title' => 'High Feast', 'StartTime' => "$d 18:00:00", 'EndTime' => "$d 20:00:00",
            'Location' => 'Great Hall', 'Description' => 'Bring a bowl', 'Menu' => 'Roast beast',
            'Leads' => [['MundaneId' => $cook]], 'Seen' => $seen,
        ]);
        $this->assertSame(0, $r['Status']['Status'], json_encode($r));
        $row = $this->fixture->fetchScheduleRow($id);
        $this->assertSame(
            ['High Feast', "$d 18:00:00", "$d 20:00:00", 'Great Hall', 'Bring a bowl', 'Feast and Food', 'Roast beast'],
            [$row['title'], $row['start_time'], $row['end_time'], $row['location'], $row['description'], $row['category'], $row['menu']]
        );
        $this->assertSame([['mundane_id' => $cook, 'is_auto' => 0]], $this->fixture->fetchScheduleLeads($id));
        $this->assertSame("$d 18:00:00", $r['Schedule']['StartTime']);

        // Their stale version is refused like anyone's, and nothing is written.
        $stale = $this->legacyUpdate($ctx, $staff['token'], $id, ['Title' => 'Overwrite', 'Seen' => $seen]);
        $this->assertSame('Someone else changed this item. Close this form and open it again to see their version.', $stale['Detail'] ?? null);
        $this->assertSame('High Feast', $this->fixture->fetchScheduleRow($id)['title']);
    }

    public function testAFeastEditorCannotUpdateANonFeastItemByPostingTheFeastCategory(): void
    {
        [$ctx, $staff] = $this->feastEditorContext('feast-hole');
        $id = $this->fixture->insertSchedule($ctx['detail_id'], 'Opening Court', 'Court');

        foreach ([['Category' => 'Feast and Food'], ['Category' => 'Court', 'SecondaryCategory' => 'Feast and Food']] as $posted) {
            $r = $this->legacyUpdate($ctx, $staff['token'], $id, ['Title' => 'Hijacked', 'Menu' => 'Gruel'] + $posted);
            $this->assertSame(ServiceErrorIds::NoAuthorization, $r['Status'] ?? null, json_encode($r));
        }
        $row = $this->fixture->fetchScheduleRow($id);
        $this->assertSame(['Opening Court', 'Court', null], [$row['title'], $row['category'], $row['menu']]);
    }

    public function testAFeastEditorKeepsAFeastItemInFeastAndFood(): void
    {
        [$ctx, $staff] = $this->feastEditorContext('feast-stay');
        $id = $this->fixture->insertSchedule($ctx['detail_id'], 'Feast', 'Feast and Food');

        $out = $this->legacyUpdate($ctx, $staff['token'], $id, ['Title' => 'Court now', 'Category' => 'Court']);
        $this->assertSame(ServiceErrorIds::InvalidParameter, $out['Status'] ?? null, json_encode($out));
        $this->assertSame('A feast item must stay in Feast and Food.', $out['Detail']);
        $this->assertSame(['Feast', 'Feast and Food'], [
            $this->fixture->fetchScheduleRow($id)['title'], $this->fixture->fetchScheduleRow($id)['category'],
        ]);

        // Through the secondary category it is still a feast item.
        $kept = $this->legacyUpdate($ctx, $staff['token'], $id, [
            'Title' => 'Court feast', 'Category' => 'Court', 'SecondaryCategory' => 'Feast and Food',
        ]);
        $this->assertSame(0, $kept['Status']['Status'], json_encode($kept));
        $row = $this->fixture->fetchScheduleRow($id);
        $this->assertSame(['Court feast', 'Court', 'Feast and Food'], [$row['title'], $row['category'], $row['secondary_category']]);

        // Stored only through the secondary category, it is still theirs to edit.
        $again = $this->legacyUpdate($ctx, $staff['token'], $id, [
            'Title' => 'Court feast II', 'Category' => 'Court', 'SecondaryCategory' => 'Feast and Food',
        ]);
        $this->assertSame(0, $again['Status']['Status'], json_encode($again));
    }

    public function testAFeastEditorsUpdateWaitsForACategoryChangeAndIsRefusedWhenItIsNoLongerFeast(): void
    {
        [$ctx, $staff] = $this->feastEditorContext('feast-race');
        $id = $this->fixture->insertSchedule($ctx['detail_id'], 'Feast', 'Feast and Food');

        // A schedule manager is moving it to Court and has not committed yet.
        $other = $this->fixture->beginConcurrentWrite([
            'UPDATE ' . DB_PREFIX . "event_schedule SET category = 'Court' WHERE event_schedule_id = $id",
        ]);
        try {
            $r = $this->legacyUpdate($ctx, $staff['token'], $id, ['Title' => 'Mine']);
        } finally {
            $this->assertNull($this->fixture->endConcurrentWrite($other));
        }
        $this->assertSame(ServiceErrorIds::NoAuthorization, $r['Status'] ?? null, json_encode($r));
        $this->assertSame(['Feast', 'Court'], [$this->fixture->fetchScheduleRow($id)['title'], $this->fixture->fetchScheduleRow($id)['category']]);
    }

    public function testAFeastEditorAddsOnlyFeastItemsAndRemovesOnlyFeastItems(): void
    {
        [$ctx, $staff] = $this->feastEditorContext('feast-add-rm');
        $d = $this->batchDay();
        $add = fn (array $more): array => $this->planning->AddEventSchedule($more + [
            'Token' => $staff['token'], 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'],
            'Title' => 'Lunch', 'StartTime' => "$d 12:00:00", 'EndTime' => "$d 13:00:00", 'Location' => 'Kitchen',
            'Description' => 'Soup', 'Menu' => 'Soup',
        ]);

        unset($_SESSION['is_authorized_mundane_id']);
        $refused = $add(['Category' => 'Class']);
        $this->assertSame(ServiceErrorIds::NoAuthorization, $refused['Status'] ?? null, json_encode($refused));
        unset($_SESSION['is_authorized_mundane_id']);
        $added = $add(['Category' => 'Feast and Food']);
        $this->assertSame(0, $added['Status']['Status'], json_encode($added));
        $row = $this->fixture->fetchScheduleRow((int) $added['Schedule']['EventScheduleId']);
        $this->assertSame(['Lunch', 'Kitchen', 'Soup', 'Soup'], [$row['title'], $row['location'], $row['description'], $row['menu']]);
        $this->assertSame(1, $this->fixture->countSchedule($ctx['detail_id']));

        $court = $this->fixture->insertSchedule($ctx['detail_id'], 'Opening Court', 'Court');
        $feast = $this->fixture->insertSchedule($ctx['detail_id'], 'Feast', 'Feast and Food');
        $remove = function (int $scheduleId) use ($ctx, $staff): array {
            unset($_SESSION['is_authorized_mundane_id']);
            return $this->planning->RemoveEventSchedule([
                'Token' => $staff['token'], 'EventId' => $ctx['event_id'],
                'EventCalendarDetailId' => $ctx['detail_id'], 'ScheduleId' => $scheduleId,
            ]);
        };
        $this->assertSame(ServiceErrorIds::NoAuthorization, $remove($court)['Status']);
        $this->assertTrue($this->fixture->scheduleExists($court, $ctx['detail_id']));
        $this->assertSame(0, $remove($feast)['Status']);
        $this->assertFalse($this->fixture->scheduleExists($feast, $ctx['detail_id']));
    }

    public function testAFeastEditorsRemoveRacingACategoryChangeLeavesTheItemAndIsNotReportedRemoved(): void
    {
        [$ctx, $staff] = $this->feastEditorContext('feast-rm-race');
        $id = $this->fixture->insertSchedule($ctx['detail_id'], 'Feast', 'Feast and Food');
        $remove = function () use ($ctx, $staff, $id): array {
            unset($_SESSION['is_authorized_mundane_id']);
            return $this->planning->RemoveEventSchedule([
                'Token' => $staff['token'], 'EventId' => $ctx['event_id'],
                'EventCalendarDetailId' => $ctx['detail_id'], 'ScheduleId' => $id,
            ]);
        };

        // A schedule manager moves it to Court after the remove has read it as a feast
        // item, and commits once the remove's DELETE is waiting on the row.
        $other = $this->fixture->beginConcurrentWrite(
            ['UPDATE ' . DB_PREFIX . "event_schedule SET category = 'Court' WHERE event_schedule_id = $id"],
            '%DELETE FROM%' . DB_PREFIX . 'event_schedule%'
        );
        try {
            $r = $remove();
        } finally {
            $this->assertNull($this->fixture->endConcurrentWrite($other));
        }
        $this->assertSame(ServiceErrorIds::NoAuthorization, $r['Status'] ?? null, json_encode($r));
        $this->assertTrue($this->fixture->scheduleExists($id, $ctx['detail_id']));
        $this->assertSame('Court', $this->fixture->fetchScheduleRow($id)['category']);
    }

    public function testRemovingAScheduleItemThatIsAlreadyGoneIsASuccess(): void
    {
        [$ctx, $staff] = $this->feastEditorContext('feast-rm-gone');
        $id = $this->fixture->insertSchedule($ctx['detail_id'], 'Feast', 'Feast and Food');
        unset($_SESSION['is_authorized_mundane_id']);
        // Someone else removes the item after the feast editor's remove has read it
        // (the first read does not lock), so its DELETE waits, matches nothing, and the
        // re-read finds the item gone: that is a success, not a refusal.
        $other = $this->fixture->beginConcurrentWrite(
            ['DELETE FROM ' . DB_PREFIX . "event_schedule WHERE event_schedule_id = $id"],
            '%DELETE FROM%' . DB_PREFIX . 'event_schedule%'
        );
        try {
            $first = $this->planning->RemoveEventSchedule([
                'Token' => $staff['token'], 'EventId' => $ctx['event_id'],
                'EventCalendarDetailId' => $ctx['detail_id'], 'ScheduleId' => $id,
            ]);
        } finally {
            $this->assertNull($this->fixture->endConcurrentWrite($other));
        }
        $this->assertSame(0, $first['Status'], json_encode($first));
        $this->assertFalse($this->fixture->scheduleExists($id, $ctx['detail_id']));

        [$ctx2, $token] = $this->batchContext('rm-gone');
        $id2 = $this->fixture->insertSchedule($ctx2['detail_id'], 'Mothman', 'Battlegame');
        $this->fixture->deleteSchedule($id2);
        unset($_SESSION['is_authorized_mundane_id']);
        $again = $this->planning->RemoveEventSchedule([
            'Token' => $token, 'EventId' => $ctx2['event_id'],
            'EventCalendarDetailId' => $ctx2['detail_id'], 'ScheduleId' => $id2,
        ]);
        $this->assertSame(0, $again['Status'], json_encode($again));
    }

    public function testALegacyAddWhoseLeadsCannotBeWrittenLeavesNoItem(): void
    {
        [$ctx, $token] = $this->batchContext('legacy-add-atomic');
        $d = $this->batchDay();
        $warcrat = $this->fixture->createPlayer('legacy-add-atomic-w');
        $this->assertSame(0, $this->saveStaff($ctx, $token, $warcrat, ['Battlegame'])['Status']['Status']);
        $add = function (array $more) use ($ctx, $token, $d): array {
            unset($_SESSION['is_authorized_mundane_id']);
            return (new EventPlanning())->AddEventSchedule($more + [
                'Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'],
                'Title' => 'Ditch', 'StartTime' => "$d 16:00:00", 'EndTime' => "$d 17:00:00", 'Category' => 'Battlegame',
            ]);
        };

        // Another save holds the lead's player row, so writing the lead (its foreign
        // key check) waits on it; this connection gives up after 1 second.
        $other = $this->fixture->holdRowLocks([
            'SELECT mundane_id FROM ' . DB_PREFIX . "mundane WHERE mundane_id = $warcrat FOR UPDATE",
        ]);
        $db = $GLOBALS['DB'];
        $db->Clear();
        $db->ExecuteChecked('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $posted = $add(['Leads' => [['MundaneId' => $warcrat, 'Auto' => true]]]);
            $filled = $add([]);
        } finally {
            $db->Clear();
            $db->ExecuteChecked('SET SESSION innodb_lock_wait_timeout = DEFAULT');
            $this->fixture->releaseRowLocks($other);
        }
        foreach ([$posted, $filled] as $r) {
            $this->assertSame(ServiceErrorIds::ProcessingError, $r['Status'] ?? null, json_encode($r));
            $this->assertSame('Could not save the schedule.', $r['Detail'] ?? null);
        }
        $this->assertSame(0, $this->fixture->countSchedule($ctx['detail_id']));

        // Once the row is free, the same add saves the item with its lead.
        $saved = $add([]);
        $this->assertSame(0, $saved['Status']['Status'], json_encode($saved));
        $this->assertSame(1, $this->fixture->countSchedule($ctx['detail_id']));
        $this->assertSame([['mundane_id' => $warcrat, 'is_auto' => 1]], $this->fixture->fetchScheduleLeads((int) $saved['Schedule']['EventScheduleId']));
    }

    /** An EventPlanning whose schedule ceiling is $max items instead of 500. */
    private function planningWithCeiling(int $max): EventPlanning
    {
        return new class ($max) extends EventPlanning {
            public function __construct(private int $max)
            {
                parent::__construct();
            }

            protected function scheduleMaxItems(): int
            {
                return $this->max;
            }
        };
    }

    public function testTheScheduleCeilingIsFiveHundredItems(): void
    {
        $method = new \ReflectionMethod(EventPlanning::class, 'scheduleFullError');
        $this->assertSame('A schedule can hold at most 500 items.', $method->invoke(new EventPlanning())['Detail']);
    }

    public function testABatchAddPastTheCeilingIsRefusedAtItsIndexAndRemovesFreeRoom(): void
    {
        [$ctx, $token] = $this->batchContext('ceiling-batch');
        $d = $this->batchDay();
        $this->planning = $this->planningWithCeiling(3);
        $first = $this->fixture->insertSchedule($ctx['detail_id'], 'Already there');

        $over = $this->batch($ctx, $token, [
            $this->addOp('c1', 'Second', "$d 09:00", "$d 10:00"),
            $this->addOp('c2', 'Third', "$d 10:00", "$d 11:00"),
            $this->addOp('c3', 'Fourth', "$d 11:00", "$d 12:00"),
        ]);
        $this->assertSame('A schedule can hold at most 3 items.', $over['Status']['Detail'] ?? null, json_encode($over));
        $this->assertSame(2, $over['FailedIndex']);
        $this->assertSame(1, $this->fixture->countSchedule($ctx['detail_id']));

        // The add that reaches the ceiling exactly is accepted.
        $full = $this->batch($ctx, $token, [
            $this->addOp('c1', 'Second', "$d 09:00", "$d 10:00"),
            $this->addOp('c2', 'Third', "$d 10:00", "$d 11:00"),
        ]);
        $this->assertSame(0, $full['Status']['Status'], json_encode($full['Status']));
        $this->assertSame(3, $this->fixture->countSchedule($ctx['detail_id']));

        // A remove earlier in the batch makes room for an add; an add before it does not.
        $tooEarly = $this->batch($ctx, $token, [
            $this->addOp('c1', 'Swapped in', "$d 12:00", "$d 13:00"),
            ['op' => 'remove', 'id' => $first],
        ]);
        $this->assertSame(0, $tooEarly['FailedIndex']);
        $swap = $this->batch($ctx, $token, [
            ['op' => 'remove', 'id' => $first],
            $this->addOp('c1', 'Swapped in', "$d 12:00", "$d 13:00"),
        ]);
        $this->assertSame(0, $swap['Status']['Status'], json_encode($swap['Status']));
        $this->assertSame(3, $this->fixture->countSchedule($ctx['detail_id']));
        $this->assertFalse($this->fixture->scheduleExists($first, $ctx['detail_id']));

        // The legacy form's add is refused the same way.
        unset($_SESSION['is_authorized_mundane_id']);
        $legacy = $this->planning->AddEventSchedule([
            'Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'],
            'Title' => 'One too many', 'StartTime' => "$d 15:00:00", 'EndTime' => "$d 16:00:00", 'Category' => 'Other',
        ]);
        $this->assertSame('A schedule can hold at most 3 items.', $legacy['Detail'] ?? null, json_encode($legacy));
        $this->assertSame(3, $this->fixture->countSchedule($ctx['detail_id']));
    }

    public function testABatchOverTheCeilingIsRefusedAtThatAddBeforeAnyOpIsApplied(): void
    {
        [$ctx, $token] = $this->batchContext('ceiling-prewalk');
        $d = $this->batchDay();
        $this->planning = $this->planningWithCeiling(3);
        $this->fixture->insertSchedule($ctx['detail_id'], 'Already there');
        $other = $this->fixture->createPublishedEvent('ceiling-prewalk-other');
        $elsewhere = $this->fixture->insertSchedule($other['detail_id'], 'Another occurrence');

        // Op 0 would fail on its own ("Title"), but the walk refuses the add at index 3, before op 0's own refusal.
        // A remove of an id that is not on this occurrence frees no room.
        $request = [
            'Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'],
            'BatchToken' => 'ceiling-prewalk-token-0001',
            'Ops' => [
                $this->addOp('c0', '', "$d 08:00", "$d 09:00"),
                ['op' => 'remove', 'id' => $elsewhere],
                $this->addOp('c1', 'Second', "$d 09:00", "$d 10:00"),
                $this->addOp('c2', 'Third', "$d 10:00", "$d 11:00"),
            ],
        ];
        unset($_SESSION['is_authorized_mundane_id']);
        $refused = $this->planning->ApplyScheduleOps($request);
        $this->assertSame('A schedule can hold at most 3 items.', $refused['Status']['Detail'] ?? null, json_encode($refused));
        $this->assertSame(3, $refused['FailedIndex']);
        $this->assertSame(1, $this->fixture->countSchedule($ctx['detail_id']), 'nothing was written');
        $this->assertTrue($this->fixture->scheduleExists($elsewhere, $other['detail_id']));

        // The refusal rolled the token back: it is free for a batch that fits.
        $request['Ops'] = [
            $this->addOp('c1', 'Second', "$d 09:00", "$d 10:00"),
            $this->addOp('c2', 'Third', "$d 10:00", "$d 11:00"),
        ];
        unset($_SESSION['is_authorized_mundane_id']);
        $ok = $this->planning->ApplyScheduleOps($request);
        $this->assertSame(0, $ok['Status']['Status'], json_encode($ok['Status']));
        $this->assertSame(3, $this->fixture->countSchedule($ctx['detail_id']));
    }

    public function testACopyThatWouldPassTheCeilingCopiesNothing(): void
    {
        $src = $this->fixture->createPublishedEventOn('ceiling-copy-src', '2026-10-30');
        $dst = $this->fixture->createPublishedEventOn('ceiling-copy-dst', '2026-11-06');
        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $dst['park_id'], AUTH_CREATE, 'ceiling-copy-admin');
        $this->fixture->insertScheduleAt($src['detail_id'], 'Opening Court', 'Court', '2026-10-30 18:00:00', '2026-10-30 19:00:00');
        $this->fixture->insertScheduleAt($src['detail_id'], 'Feast', 'Feast and Food', '2026-10-30 17:00:00', '2026-10-30 18:00:00');
        $this->fixture->insertScheduleAt($dst['detail_id'], 'Gate', 'Administrative', '2026-11-06 09:00:00', '2026-11-06 10:00:00');
        $this->fixture->insertScheduleAt($dst['detail_id'], 'Lunch', 'Feast and Food', '2026-11-06 12:00:00', '2026-11-06 13:00:00');
        $req = ['Token' => $grantor['token'], 'EventId' => $dst['event_id'], 'EventCalendarDetailId' => $dst['detail_id'],
            'SourceDetailId' => $src['detail_id'], 'RequestToken' => 'ceiling-copy-0001'];

        $this->planning = $this->planningWithCeiling(3);
        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->planning->CopyScheduleInto($req);
        $this->assertSame(ServiceErrorIds::InvalidParameter, $r['Status']['Status'], json_encode($r));
        $this->assertSame('That would put more than 3 items on this schedule.', $r['Status']['Detail']);
        $this->assertSame(2, $this->fixture->countSchedule($dst['detail_id']));
        $this->assertSame(0, $this->fixture->countScheduleRequestTokens($dst['detail_id']));

        // With room for both, the same token copies them.
        $this->planning = $this->planningWithCeiling(4);
        unset($_SESSION['is_authorized_mundane_id']);
        $fits = $this->planning->CopyScheduleInto($req);
        $this->assertSame(0, $fits['Status']['Status'], json_encode($fits));
        $this->assertSame(4, $this->fixture->countSchedule($dst['detail_id']));
    }

    public function testEachResultRowShowsTheItemAsItsOwnOpLeftItEvenWhenALaterOpRemovesIt(): void
    {
        [$ctx, $token] = $this->batchContext('result-rows');
        $d = $this->batchDay();
        $id = (int) $this->batch($ctx, $token, [$this->addOp('c1', 'Mothman', "$d 12:00", "$d 12:45")])['Results'][0]['id'];
        $kept = (int) $this->batch($ctx, $token, [$this->addOp('c1', 'Kept', "$d 14:00", "$d 14:45")])['Results'][0]['id'];

        $r = $this->batch($ctx, $token, [
            ['op' => 'update', 'cid' => 'u1', 'id' => $id, 'fields' => ['Title' => 'First']],
            ['op' => 'update', 'cid' => 'u2', 'id' => $kept, 'fields' => ['Title' => 'Kept and renamed']],
            ['op' => 'update', 'cid' => 'u3', 'id' => $id, 'fields' => ['Location' => 'Second']],
            ['op' => 'remove', 'cid' => 'r1', 'id' => $id],
        ]);
        $this->assertSame(0, $r['Status']['Status'], json_encode($r['Status']));
        $this->assertSame(['First', ''], [$r['Results'][0]['schedule']['Title'], $r['Results'][0]['schedule']['Location']]);
        $this->assertSame(['First', 'Second'], [$r['Results'][2]['schedule']['Title'], $r['Results'][2]['schedule']['Location']]);
        $this->assertSame('Kept and renamed', $r['Results'][1]['schedule']['Title']);
        $this->assertSame($this->fixture->fetchScheduleModified($kept), $r['Results'][1]['schedule']['Modified']);
        $this->assertSame(['op' => 'remove', 'cid' => 'r1', 'id' => $id, 'schedule' => null], $r['Results'][3]);
        $this->assertFalse($this->fixture->scheduleExists($id, $ctx['detail_id']));
    }

    /** Statements this connection has sent so far (the session's Questions counter). */
    private function statementsSent(): int
    {
        $db = $GLOBALS['DB'];
        $db->Clear();
        $row = $db->DataSet("SHOW SESSION STATUS LIKE 'Questions'");
        $this->assertTrue($row && $row->Next());

        return (int) $row->Value;
    }

    public function testABatchAddCostsAtMostThreeStatements(): void
    {
        $leadA = $this->fixture->createPlayer('stmts-a');
        $leadB = $this->fixture->createPlayer('stmts-b');
        $d = $this->batchDay();
        $count = function (int $adds) use ($leadA, $leadB, $d): int {
            [$ctx, $token] = $this->batchContext('stmts-' . $adds);
            $ops = [];
            for ($i = 0; $i < $adds; $i++) {
                $ops[] = $this->addOp('c' . $i, 'Item ' . $i, "$d 09:00", "$d 10:00", [
                    'Category' => 'Class', 'Leads' => [['MundaneId' => $leadA], ['MundaneId' => $leadB]],
                ]);
            }
            $this->planning = new EventPlanning(); // nothing remembered from the other run
            $before = $this->statementsSent();
            $r = $this->batch($ctx, $token, $ops);
            $after = $this->statementsSent();
            $this->assertSame(0, $r['Status']['Status'], json_encode($r['Status']));
            $this->assertCount(2, $r['Results'][$adds - 1]['schedule']['Leads']);

            return $after - $before - 1;
        };
        // The difference leaves out what every batch costs once (sign-in, rights, token, ceiling, results).
        $perAdd = ($count(30) - $count(10)) / 20;
        $this->assertLessThanOrEqual(3, $perAdd, "statements per add: $perAdd");
    }

    // ---- The legacy form's save is a thin wrapper over the batch's op writer ----

    /** The schedule form's post, as EventAjax builds it, for $ctx. */
    private function formPost(array $ctx, string $token, array $fields): array
    {
        return $fields + [
            'Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'], 'ScheduleId' => 0,
            'Title' => 'Mothman', 'StartTime' => $this->batchDay() . ' 12:00', 'EndTime' => $this->batchDay() . ' 12:45',
            'Location' => '', 'Description' => '', 'Category' => 'Other', 'SecondaryCategory' => '',
            'Menu' => '', 'Cost' => '', 'Dietary' => '', 'Allergens' => '',
        ];
    }

    /** $schedule with its keys sorted, so two replies compare by key and strict value. */
    private function sortedKeys(array $schedule): array
    {
        ksort($schedule);

        return $schedule;
    }

    public function testTheFormsReplyKeepsEveryKeyAndValueItHad(): void
    {
        [$ctx, $token] = $this->batchContext('form-reply');
        $d = $this->batchDay();
        $lead = $this->fixture->createPlayer('form-reply-lead');

        // A schedule manager's add, as the form posts it (times as 'Y-m-d H:i').
        unset($_SESSION['is_authorized_mundane_id']);
        $added = $this->planning->AddEventSchedule($this->formPost($ctx, $token, [
            'Title' => ' Mothman ', 'StartTime' => "$d 12:00", 'EndTime' => "$d 12:45", 'Location' => 'Field',
            'Description' => 'Bring water', 'Category' => 'Feast and Food', 'SecondaryCategory' => 'Court',
            'Menu' => ' Stew ', 'Cost' => '12.5', 'Dietary' => 'Vegan', 'Allergens' => '',
            'Leads' => [['MundaneId' => $lead, 'Persona' => 'Lead', 'Auto' => 0]],
        ]));
        $this->assertSame(0, $added['Status']['Status'], json_encode($added));
        $this->assertSame(['Status', 'Schedule'], array_keys($added));
        $id = (int) $added['Schedule']['EventScheduleId'];
        $leads = [['MundaneId' => $lead, 'Persona' => 'T04EVPL form-reply-lead', 'Auto' => false]];
        $this->assertSame($this->sortedKeys([
            'EventScheduleId' => $id, 'Title' => 'Mothman', 'StartTime' => "$d 12:00:00", 'EndTime' => "$d 12:45:00",
            'Location' => 'Field', 'Description' => 'Bring water', 'Category' => 'Feast and Food', 'SecondaryCategory' => 'Court',
            'Menu' => 'Stew', 'Cost' => 12.5, 'Dietary' => 'Vegan', 'Allergens' => null,
            'Leads' => $leads, 'Modified' => $this->fixture->fetchScheduleModified($id),
        ]), $this->sortedKeys($added['Schedule']));

        // Its update with the version it was given, leads not sent.
        unset($_SESSION['is_authorized_mundane_id']);
        $updated = $this->planning->UpdateEventSchedule($this->formPost($ctx, $token, [
            'ScheduleId' => $id, 'Seen' => $added['Schedule']['Modified'], 'Title' => 'Mothman II',
            'StartTime' => "$d 13:00", 'EndTime' => "$d 13:30", 'Category' => 'Class', 'Menu' => 'Soup',
        ]));
        $this->assertSame(0, $updated['Status']['Status'], json_encode($updated));
        $this->assertSame($this->sortedKeys([
            'EventScheduleId' => $id, 'Title' => 'Mothman II', 'StartTime' => "$d 13:00:00", 'EndTime' => "$d 13:30:00",
            'Location' => '', 'Description' => '', 'Category' => 'Class', 'SecondaryCategory' => '',
            'Menu' => 'Soup', 'Cost' => null, 'Dietary' => null, 'Allergens' => null,
            'Leads' => $leads, 'Modified' => $this->fixture->fetchScheduleModified($id),
        ]), $this->sortedKeys($updated['Schedule']));

        // Someone with schedule rights but not feast rights: the meal fields they post
        // are not written, and the reply carries the stored ones.
        $staff = $this->fixture->createGrantorWithoutAuth('form-reply-sched');
        $this->fixture->insertStaff($ctx['detail_id'], $staff['mundane_id'], 'Schedule', canSchedule: true);
        $feast = $this->fixture->insertSchedule($ctx['detail_id'], 'Feast', 'Feast and Food');
        $this->fixture->setScheduleMeal($feast, 'Roast', 7.25);
        unset($_SESSION['is_authorized_mundane_id']);
        $scheduleOnly = $this->planning->UpdateEventSchedule($this->formPost($ctx, $staff['token'], [
            'ScheduleId' => $feast, 'Title' => 'Feast', 'Category' => 'Feast and Food', 'Menu' => 'Gruel', 'Cost' => '1',
        ]));
        $this->assertSame(0, $scheduleOnly['Status']['Status'], json_encode($scheduleOnly));
        $this->assertSame($this->sortedKeys([
            'EventScheduleId' => $feast, 'Title' => 'Feast', 'StartTime' => "$d 12:00:00", 'EndTime' => "$d 12:45:00",
            'Location' => '', 'Description' => '', 'Category' => 'Feast and Food', 'SecondaryCategory' => '',
            'Menu' => 'Roast', 'Cost' => 7.25, 'Dietary' => null, 'Allergens' => null,
            'Leads' => [], 'Modified' => $this->fixture->fetchScheduleModified($feast),
        ]), $this->sortedKeys($scheduleOnly['Schedule']));
        $this->assertSame(['Roast', '7.25'], [$this->fixture->fetchScheduleRow($feast)['menu'], $this->fixture->fetchScheduleRow($feast)['cost']]);
    }

    public function testTheFormGetsTheBatchsContentRules(): void
    {
        [$ctx, $token] = $this->batchContextOn('form-rules', '2026-12-01');   // 2026-12-01 09:00 to 2026-12-03 16:00
        $id = $this->fixture->insertScheduleAt($ctx['detail_id'], 'Mothman', 'Battlegame', '2026-12-01 12:00:00', '2026-12-01 12:45:00');
        $refusals = [
            'the event window' => [['StartTime' => '2026-12-11 00:00', 'EndTime' => '2026-12-11 01:00'], 'That time is outside the event.'],
            'end before start' => [['StartTime' => '2026-12-01 12:00', 'EndTime' => '2026-12-01 11:59'], 'End time cannot be before start time.'],
            'a text limit' => [['Title' => str_repeat('x', 256)], 'The title is longer than 255 characters.'],
        ];
        foreach ($refusals as $rule => [$posted, $message]) {
            $posted += ['StartTime' => '2026-12-02 12:00', 'EndTime' => '2026-12-02 13:00'];
            unset($_SESSION['is_authorized_mundane_id']);
            $add = $this->planning->AddEventSchedule($this->formPost($ctx, $token, $posted));
            $this->assertSame(ServiceErrorIds::InvalidParameter, $add['Status'] ?? null, "add, $rule: " . json_encode($add));
            $this->assertSame($message, $add['Detail'], "add, $rule");
            unset($_SESSION['is_authorized_mundane_id']);
            $update = $this->planning->UpdateEventSchedule($this->formPost($ctx, $token, ['ScheduleId' => $id] + $posted));
            $this->assertSame(ServiceErrorIds::InvalidParameter, $update['Status'] ?? null, "update, $rule: " . json_encode($update));
            $this->assertSame($message, $update['Detail'], "update, $rule");
        }
        $this->assertSame(1, $this->fixture->countSchedule($ctx['detail_id']));
        $row = $this->fixture->fetchScheduleRow($id);
        $this->assertSame(['Mothman', '2026-12-01 12:00:00', '2026-12-01 12:45:00'], [$row['title'], $row['start_time'], $row['end_time']]);

        // What the form posts ('Y-m-d H:i', from its date picker) is still accepted, as is 'Y-m-d H:i:s'.
        foreach ([['2026-12-02 12:00', '2026-12-02 13:00'], ['2026-12-02 14:00:00', '2026-12-02 15:00:00']] as [$start, $end]) {
            unset($_SESSION['is_authorized_mundane_id']);
            $ok = $this->planning->AddEventSchedule($this->formPost($ctx, $token, ['StartTime' => $start, 'EndTime' => $end]));
            $this->assertSame(0, $ok['Status']['Status'], json_encode($ok));
            $this->assertSame(substr($start, 0, 16) . ':00', $ok['Schedule']['StartTime']);
        }
    }

    public function testAnUpdateOfAnItemThatIsGoneIsNotFound(): void
    {
        [$ctx, $token] = $this->batchContext('form-gone');
        $id = $this->fixture->insertSchedule($ctx['detail_id'], 'Mothman', 'Battlegame');
        $this->fixture->deleteSchedule($id);
        [$ctxB, $tokenB] = $this->batchContext('form-gone-b');
        $theirs = $this->fixture->insertSchedule($ctxB['detail_id'], 'Theirs', 'Battlegame');

        foreach ([$id, $theirs] as $scheduleId) {
            unset($_SESSION['is_authorized_mundane_id']);
            $r = $this->planning->UpdateEventSchedule($this->formPost($ctx, $token, ['ScheduleId' => $scheduleId]));
            $this->assertSame(ServiceErrorIds::InvalidParameter, $r['Status'] ?? null, json_encode($r));
            $this->assertSame('Schedule item not found.', $r['Detail']);
        }
        $this->assertSame('Theirs', $this->fixture->fetchScheduleRow($theirs)['title']);
    }

    public function testTheFormsSaveWritesTheScheduleOnlyThroughTheOpWriter(): void
    {
        $source = file((new \ReflectionClass(EventPlanning::class))->getFileName());
        foreach (['saveEventSchedule', 'applyFormScheduleOp'] as $name) {
            $method = new \ReflectionMethod(EventPlanning::class, $name);
            $body = implode('', array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
            // SQL keywords (upper case: the op names 'add' and 'update' are not SQL), and no table name at all.
            $this->assertDoesNotMatchRegularExpression('/\b(INSERT|UPDATE|DELETE|REPLACE)\b/', $body, $name);
            $this->assertStringNotContainsString('event_schedule', $body, $name);
        }
        // The helpers the legacy save used to write with are gone.
        foreach (['writeLegacyScheduleUpdate', 'normalizeScheduleTimes'] as $name) {
            $this->assertFalse(method_exists(EventPlanning::class, $name), $name);
        }
    }
}
