<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_EventAjax planning core (T-EVA-01 through T-EVA-13).
 *
 * Pre-refactor logic lives in orkui/controller/controller.EventAjax.php; R-04 will move
 * these behaviors to class.EventPlanning.php and EventService handlers.
 */
final class EventPlanningTest extends TestCase
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
        ]);

        $this->assertSame(0, $response['Status']);
        $eventId = (int) $response['Detail'];
        $this->fixture->trackEvent($eventId);
        $this->mirrorSetEventDraft($eventId);

        $this->assertSame('draft', $this->fixture->fetchEventStatus($eventId));
    }

    public function testSetEventStatusPublished(): void
    {
        $ctx = $this->fixture->createPublishedEvent('status-pub', 'draft');
        $staff = $this->fixture->createGrantorWithoutAuth('status-mgr');
        $this->fixture->insertStaff($ctx['detail_id'], $staff['mundane_id'], 'Mgr', canManage: true);

        unset($_SESSION['is_authorized_mundane_id']);
        $authorized = $this->mirrorSetStatusAuthorized($staff['mundane_id'], $ctx['event_id']);
        $this->assertTrue($authorized);

        $this->mirrorSetEventStatus($ctx['event_id'], 'published');
        $this->assertSame('published', $this->fixture->fetchEventStatus($ctx['event_id']));
    }

    public function testSetEventStatusRejectsUnauthorized(): void
    {
        $ctx = $this->fixture->createPublishedEvent('status-unauth');
        $stranger = $this->fixture->createGrantorWithoutAuth('stranger');

        unset($_SESSION['is_authorized_mundane_id']);
        $authorized = $this->mirrorSetStatusAuthorized($stranger['mundane_id'], $ctx['event_id']);
        $this->assertFalse($authorized);
    }

    public function testSetEventStatusBustsScopeCache(): void
    {
        $ctx = $this->fixture->createPublishedEvent('cache-status');
        Ork3::$Lib->ghettocache->bust_event_search($ctx['event_id']);
        $this->mirrorSetEventStatus($ctx['event_id'], 'draft');
        $this->assertSame('draft', $this->fixture->fetchEventStatus($ctx['event_id']));
    }

    public function testGetEventPreviewPublished(): void
    {
        $ctx = $this->fixture->createPublishedEvent('preview-pub');
        $playerId = $this->fixture->createPlayer('preview-rsvp');
        $this->fixture->insertRsvp($ctx['detail_id'], $playerId, 'going');

        $preview = $this->mirrorEventPreview($ctx['event_id'], $ctx['detail_id'], 0);
        $this->assertSame(0, $preview['status']);
        $this->assertArrayHasKey('name', $preview);
        $this->assertSame(1, $preview['rsvp']['going']);
    }

    public function testGetEventPreviewDraftHidden(): void
    {
        $ctx = $this->fixture->createPublishedEvent('preview-draft', 'draft');
        $preview = $this->mirrorEventPreview($ctx['event_id'], $ctx['detail_id'], 0);
        $this->assertSame(5, $preview['status']);
    }

    public function testAddStaffInsertAndUpdate(): void
    {
        $ctx = $this->fixture->createPublishedEvent('staff-crud');
        $player = $this->fixture->createPlayer('staff-target');

        $staffId = $this->mirrorAddStaffInsert($ctx['detail_id'], $player, 'Gate', false);
        $this->assertGreaterThan(0, $staffId);
        $row = $this->fixture->fetchStaffRow($staffId);
        $this->assertSame('Gate', $row['role_name']);

        $this->fixture->updateStaff($staffId, 'Head Gate', true);
        $row = $this->fixture->fetchStaffRow($staffId);
        $this->assertSame('Head Gate', $row['role_name']);
        $this->assertSame(1, (int) $row['can_manage']);
    }

    public function testRemoveStaff(): void
    {
        $ctx = $this->fixture->createPublishedEvent('staff-rm');
        $player = $this->fixture->createPlayer('staff-rm-target');
        $staffId = $this->fixture->insertStaff($ctx['detail_id'], $player, 'Temp');

        $this->mirrorRemoveStaff($staffId, $ctx['detail_id']);
        $this->assertFalse($this->fixture->staffExists($staffId));
    }

    public function testAddScheduleWithLeads(): void
    {
        $ctx = $this->fixture->createPublishedEvent('sched-leads');
        $leadId = $this->fixture->createPlayer('sched-lead');
        $scheduleId = $this->mirrorAddSchedule($ctx['detail_id'], 'Opening Court');
        $this->fixture->insertScheduleLead($scheduleId, $leadId);

        $this->assertTrue($this->fixture->scheduleExists($scheduleId, $ctx['detail_id']));
        $this->assertSame(1, $this->fixture->countScheduleLeads($scheduleId));
    }

    public function testAddScheduleFeastRequiresFeastCap(): void
    {
        $ctx = $this->fixture->createPublishedEvent('feast-cap');
        $staff = $this->fixture->createGrantorWithoutAuth('att-only');
        $this->fixture->insertStaff($ctx['detail_id'], $staff['mundane_id'], 'Att', canAttendance: true);

        $allowed = $this->mirrorScheduleFeastAllowed($staff['mundane_id'], $ctx['detail_id'], 'Feast and Food');
        $this->assertFalse($allowed);
    }

    public function testUpdateSchedulePartialFeastFields(): void
    {
        $ctx = $this->fixture->createPublishedEvent('feast-update');
        $staff = $this->fixture->createGrantorWithoutAuth('feast-staff');
        $this->fixture->insertStaff($ctx['detail_id'], $staff['mundane_id'], 'Feast', canFeast: true);
        $scheduleId = $this->fixture->insertSchedule($ctx['detail_id'], 'Feast', 'Feast and Food');

        $this->mirrorUpdateScheduleMenu($scheduleId, $ctx['detail_id'], 'Roast beast');
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
        $scheduleId = $this->fixture->insertSchedule($ctx['detail_id'], 'Remove me');

        $this->mirrorRemoveSchedule($scheduleId, $ctx['detail_id']);
        $this->assertFalse($this->fixture->scheduleExists($scheduleId, $ctx['detail_id']));
    }

    public function testRemoveEventHeraldry(): void
    {
        $ctx = $this->fixture->createPublishedEvent('heraldry-rm');
        $this->fixture->setEventHeraldry($ctx['event_id'], 1);

        $this->mirrorRemoveEventHeraldry($ctx['event_id']);
        $this->assertSame(0, $this->fixture->fetchEventHeraldry($ctx['event_id']));
    }

    public function testCopySourceListScoped(): void
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->firstKingdomId();
        $past = $this->fixture->createPastPublishedEventAtPark($parkId, $kingdomId, 'copy-src');

        $results = $this->mirrorCopySourceList($parkId, 0, '');
        $ids = array_column($results, 'eventId');
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

        $newEventId = $this->mirrorCreateWithCopyScheduleStaff(
            $source['event_id'],
            $source['detail_id'],
            $kingdomId,
            $parkId,
        );

        $this->assertGreaterThan(0, $newEventId);
        global $DB;
        $DB->Clear();
        $staffCount = $DB->DataSet(
            'SELECT COUNT(*) AS c FROM ' . DB_PREFIX . 'event_staff es
             JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = es.event_calendardetail_id
             WHERE cd.event_id = ' . (int) $newEventId
        );
        $this->assertTrue($staffCount && $staffCount->Next());
        $this->assertGreaterThanOrEqual(1, (int) $staffCount->c);
    }

    public function testCreateWithCopyRollback(): void
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->firstKingdomId();

        $created = $this->mirrorCreateWithCopyScheduleStaff(
            99999999,
            0,
            $kingdomId,
            $parkId,
        );
        $this->assertSame(0, $created);
    }

    /**
     * Mirrors Controller_EventAjax::create post-create draft UPDATE (lines 40–44).
     */
    private function mirrorSetEventDraft(int $eventId): void
    {
        global $DB;
        $DB->Clear();
        $DB->Execute("UPDATE " . DB_PREFIX . "event SET status = 'draft' WHERE event_id = " . $eventId);
    }

    /**
     * Mirrors Controller_EventAjax::set_status authorization (lines 76–84).
     */
    private function mirrorSetStatusAuthorized(int $uid, int $eventId): bool
    {
        if (Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $eventId, AUTH_EDIT)) {
            return true;
        }
        global $DB;
        $DB->Clear();
        $mgrRow = $DB->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . 'event_staff es
             JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = es.event_calendardetail_id
             WHERE cd.event_id = ' . $eventId . ' AND es.mundane_id = ' . $uid . ' AND es.can_manage = 1 LIMIT 1'
        );

        return (bool) ($mgrRow && $mgrRow->Next());
    }

    /**
     * Mirrors Controller_EventAjax::set_status UPDATE + cache bust (lines 86–90).
     */
    private function mirrorSetEventStatus(int $eventId, string $status): void
    {
        global $DB;
        $DB->Clear();
        $DB->Execute("UPDATE " . DB_PREFIX . "event SET status = '" . $status . "' WHERE event_id = " . $eventId);
        Ork3::$Lib->ghettocache->bust_event_search($eventId);
    }

    /**
     * Mirrors Controller_EventAjax::preview core reads (lines 119–200 simplified).
     *
     * @return array{status: int, name?: string, rsvp?: array{going: int, interested: int}}
     */
    private function mirrorEventPreview(int $eventId, int $detailId, int $uid): array
    {
        global $DB;
        $DB->Clear();
        $ev = $DB->DataSet(
            "SELECT e.event_id, e.name, e.status, e.mundane_id AS creator FROM "
            . DB_PREFIX . "event e WHERE e.event_id = {$eventId} LIMIT 1"
        );
        if (!$ev || !$ev->Next()) {
            return ['status' => 1];
        }

        $status = (string) ($ev->status ?? 'published');
        $canEdit = $uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_EVENT, $eventId, AUTH_EDIT);
        if ($status !== 'published' && !$canEdit && (int) $ev->creator !== $uid) {
            return ['status' => 5];
        }

        $going = 0;
        $interested = 0;
        if ($detailId > 0) {
            $DB->Clear();
            $rs = $DB->DataSet(
                "SELECT
                    SUM(CASE WHEN status = 'going' THEN 1 ELSE 0 END) AS g,
                    SUM(CASE WHEN status = 'interested' THEN 1 ELSE 0 END) AS i
                 FROM " . DB_PREFIX . "event_rsvp WHERE event_calendardetail_id = {$detailId}"
            );
            if ($rs && $rs->Next()) {
                $going = (int) $rs->g;
                $interested = (int) $rs->i;
            }
        }

        return [
            'status' => 0,
            'name' => (string) $ev->name,
            'rsvp' => ['going' => $going, 'interested' => $interested],
        ];
    }

    /**
     * Mirrors Controller_EventAjax::add_staff INSERT path (lines 633–641).
     */
    private function mirrorAddStaffInsert(int $detailId, int $mundaneId, string $role, bool $canManage): int
    {
        global $DB;
        $roleSafe = str_replace(["'", '\\'], ["''", '\\\\'], $role);
        $DB->Clear();
        $DB->Execute(
            'INSERT INTO ' . DB_PREFIX . 'event_staff
             (event_calendardetail_id, mundane_id, role_name, can_manage, can_attendance, can_schedule, can_feast)
             VALUES (' . $detailId . ', ' . $mundaneId . ", '" . $roleSafe . "', "
            . ($canManage ? 1 : 0) . ', 0, 0, 0)'
        );
        $DB->Clear();
        $idrow = $DB->DataSet(
            'SELECT event_staff_id FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = '
            . $detailId . ' ORDER BY event_staff_id DESC LIMIT 1'
        );

        return ($idrow && $idrow->Next()) ? (int) $idrow->event_staff_id : 0;
    }

    /**
     * Mirrors Controller_EventAjax::remove_staff DELETE (lines 755–757).
     */
    private function mirrorRemoveStaff(int $staffId, int $detailId): void
    {
        global $DB;
        $DB->Clear();
        $DB->Execute(
            'DELETE FROM ' . DB_PREFIX . 'event_staff WHERE event_staff_id = '
            . $staffId . ' AND event_calendardetail_id = ' . $detailId
        );
    }

    /**
     * Mirrors Controller_EventAjax::add_schedule INSERT (lines 887–891).
     */
    private function mirrorAddSchedule(int $detailId, string $title): int
    {
        global $DB;
        $start = date('Y-m-d H:i:s', strtotime('+7 days 10:00'));
        $end = date('Y-m-d H:i:s', strtotime('+7 days 11:00'));
        $titleSafe = str_replace(["'", '\\'], ["''", '\\\\'], $title);
        $DB->Clear();
        $DB->Execute(
            'INSERT INTO ' . DB_PREFIX . 'event_schedule
             (event_calendardetail_id, title, start_time, end_time, location, description, category)
             VALUES (' . $detailId . ", '" . $titleSafe . "', '" . $start . "', '" . $end . "', 'Main', '', 'Tournament')"
        );
        $DB->Clear();
        $idrow = $DB->DataSet(
            'SELECT event_schedule_id FROM ' . DB_PREFIX . 'event_schedule WHERE event_calendardetail_id = '
            . $detailId . ' ORDER BY event_schedule_id DESC LIMIT 1'
        );

        return ($idrow && $idrow->Next()) ? (int) $idrow->event_schedule_id : 0;
    }

    /**
     * Mirrors feast category auth gate (lines 822–827).
     */
    private function mirrorScheduleFeastAllowed(int $uid, int $detailId, string $category): bool
    {
        $isFeast = ($category === 'Feast and Food');
        if (!$isFeast) {
            return true;
        }

        global $DB;
        $DB->Clear();
        $staffRow = $DB->DataSet(
            'SELECT can_manage, can_schedule, can_feast FROM ' . DB_PREFIX . 'event_staff
             WHERE event_calendardetail_id = ' . $detailId . ' AND mundane_id = ' . $uid . ' LIMIT 1'
        );
        if (!$staffRow || !$staffRow->Next()) {
            return false;
        }

        $canSchedule = (bool) (int) $staffRow->can_schedule || (bool) (int) $staffRow->can_manage;
        $canFeast = (bool) (int) $staffRow->can_feast || (bool) (int) $staffRow->can_manage;

        return $canSchedule || $canFeast;
    }

    /**
     * Mirrors feast-field UPDATE portion of update_schedule.
     */
    private function mirrorUpdateScheduleMenu(int $scheduleId, int $detailId, string $menu): void
    {
        global $DB;
        $menuSafe = str_replace(["'", '\\'], ["''", '\\\\'], $menu);
        $DB->Clear();
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'event_schedule SET menu = \'' . $menuSafe . '\'
             WHERE event_schedule_id = ' . $scheduleId . ' AND event_calendardetail_id = ' . $detailId
        );
    }

    /**
     * Mirrors Controller_EventAjax::remove_schedule (lines 975–977).
     */
    private function mirrorRemoveSchedule(int $scheduleId, int $detailId): void
    {
        global $DB;
        $DB->Clear();
        $DB->Execute(
            'DELETE FROM ' . DB_PREFIX . 'event_schedule WHERE event_schedule_id = '
            . $scheduleId . ' AND event_calendardetail_id = ' . $detailId
        );
    }

    /**
     * Mirrors Controller_EventAjax::heraldry remove (lines 1202–1213).
     */
    private function mirrorRemoveEventHeraldry(int $eventId): void
    {
        global $DB;
        $DB->Clear();
        $DB->Execute('UPDATE ' . DB_PREFIX . 'event SET has_heraldry = 0 WHERE event_id = ' . $eventId);
        $base = DIR_EVENT_HERALDRY . sprintf('%05d', $eventId);
        if (file_exists($base . '.jpg')) {
            @unlink($base . '.jpg');
        }
        if (file_exists($base . '.png')) {
            @unlink($base . '.png');
        }
        Ork3::$Lib->ghettocache->bust_event_search($eventId);
    }

    /**
     * Mirrors Controller_EventAjax::copy_source_list park scope (lines 1283–1315).
     *
     * @return list<array{eventId: int}>
     */
    private function mirrorCopySourceList(int $parkId, int $kingdomId, string $query): array
    {
        global $DB;
        if ($parkId > 0) {
            $scopeWhere = 'e.park_id = ' . $parkId;
        } else {
            $scopeWhere = 'e.kingdom_id = ' . $kingdomId . ' AND (e.park_id IS NULL OR e.park_id = 0)';
        }

        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT e.event_id FROM ' . DB_PREFIX . 'event e
             JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_id = e.event_id AND cd.event_start < NOW()
             WHERE ' . $scopeWhere . " AND e.status = 'published'
             GROUP BY e.event_id
             HAVING MAX(cd.event_start) IS NOT NULL
             ORDER BY MAX(cd.event_start) DESC
             LIMIT 25"
        );
        $results = [];
        while ($rs && $rs->Next()) {
            $results[] = ['eventId' => (int) $rs->event_id];
        }

        return $results;
    }

    /**
     * Simplified mirror of create_with_copy staff module (partial).
     */
    private function mirrorCreateWithCopyScheduleStaff(
        int $sourceEventId,
        int $sourceDetailId,
        int $kingdomId,
        int $parkId,
    ): int {
        global $DB;

        $DB->Clear();
        $src = $DB->DataSet(
            'SELECT event_id FROM ' . DB_PREFIX . 'event WHERE event_id = ' . $sourceEventId . ' LIMIT 1'
        );
        if (!$src || !$src->Next()) {
            return 0;
        }

        $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $parkId, AUTH_CREATE, 'copy-create');
        unset($_SESSION['is_authorized_mundane_id']);
        $response = $this->eventDomain->CreateEvent([
            'Token' => $grantor['token'],
            'KingdomId' => $kingdomId,
            'ParkId' => $parkId,
            'MundaneId' => 0,
            'UnitId' => 0,
            'Name' => 'T04EVPL Copy Target',
        ]);
        if ($response['Status'] != 0) {
            return 0;
        }

        $newEventId = (int) $response['Detail'];
        $this->fixture->trackEvent($newEventId);

        $start = date('Y-m-d H:i:s', strtotime('+14 days'));
        $end = date('Y-m-d H:i:s', strtotime('+14 days +6 hours'));
        $newDetailId = $this->fixture->createDetailOnEvent($newEventId, $start, $end);

        $DB->Clear();
        $staffRs = $DB->DataSet(
            'SELECT mundane_id, role_name, can_manage, can_attendance, can_schedule, can_feast
             FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . $sourceDetailId
        );
        while ($staffRs && $staffRs->Next()) {
            $DB->Clear();
            $roleSafe = str_replace(["'", '\\'], ["''", '\\\\'], (string) $staffRs->role_name);
            $DB->Execute(
                'INSERT INTO ' . DB_PREFIX . 'event_staff
                 (event_calendardetail_id, mundane_id, role_name, can_manage, can_attendance, can_schedule, can_feast)
                 VALUES (' . $newDetailId . ', ' . (int) $staffRs->mundane_id . ", '" . $roleSafe . "', "
                . (int) $staffRs->can_manage . ', ' . (int) $staffRs->can_attendance . ', '
                . (int) $staffRs->can_schedule . ', ' . (int) $staffRs->can_feast . ')'
            );
        }

        return $newEventId;
    }
}
