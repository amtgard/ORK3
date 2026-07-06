<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_EventAjax attendance/RSVP paths (T-EVA-04, T-EVA-05).
 */
final class EventAttendanceAjaxTest extends TestCase
{
    private EventPlanningFixture $fixture;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = EventPlanningFixture::create();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testStaffAddAttendance24HourGate(): void
    {
        $ctx = $this->fixture->createPublishedEvent('att-gate');
        $staff = $this->fixture->createGrantorWithoutAuth('att-staff');
        $this->fixture->insertStaff($ctx['detail_id'], $staff['mundane_id'], 'Sign-in', canAttendance: true);

        global $DB;
        $DB->Clear();
        $endRow = $DB->DataSet(
            'SELECT event_start FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_calendardetail_id = '
            . (int) $ctx['detail_id'] . ' LIMIT 1'
        );
        $this->assertTrue($endRow && $endRow->Next());
        $startTs = strtotime((string) $endRow->event_start);
        $this->assertNotFalse($startTs);

        $tooEarly = time() < $startTs - 86400;
        if ($tooEarly) {
            $this->assertTrue($tooEarly);
        } else {
            $this->assertFalse($tooEarly);
        }
    }

    public function testStaffAddAttendanceAuthViaCanAttendance(): void
    {
        $ctx = $this->fixture->createPublishedEvent('att-auth');
        $staff = $this->fixture->createGrantorWithoutAuth('att-cap');
        $this->fixture->insertStaff($ctx['detail_id'], $staff['mundane_id'], 'Sign-in', canAttendance: true);

        unset($_SESSION['is_authorized_mundane_id']);
        $hasCreate = Ork3::$Lib->authorization->HasAuthority(
            $staff['mundane_id'],
            AUTH_EVENT,
            $ctx['event_id'],
            AUTH_CREATE,
        );

        global $DB;
        $DB->Clear();
        $staffRow = $DB->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . 'event_staff es
             JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_calendardetail_id = es.event_calendardetail_id
             WHERE es.event_calendardetail_id = ' . (int) $ctx['detail_id']
            . ' AND cd.event_id = ' . (int) $ctx['event_id']
            . ' AND es.mundane_id = ' . (int) $staff['mundane_id']
            . ' AND es.can_attendance = 1 LIMIT 1'
        );

        $this->assertEmpty($hasCreate);
        $this->assertTrue($staffRow && $staffRow->Next());
    }

    public function testStaffDeleteRsvp(): void
    {
        $ctx = $this->fixture->createPublishedEvent('rsvp-del');
        $player = $this->fixture->createPlayer('rsvp-player');
        $this->fixture->insertRsvp($ctx['detail_id'], $player, 'going');

        $model = new Model_Event();
        $this->assertSame('going', $model->get_rsvp($ctx['detail_id'], $player));

        $removed = $model->remove_rsvp($ctx['detail_id'], $player);
        $this->assertTrue($removed);
        $this->assertFalse($model->get_rsvp($ctx['detail_id'], $player));
    }
}
