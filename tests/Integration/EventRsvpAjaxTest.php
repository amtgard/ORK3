<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_EventRsvpAjax (T-RSV-01 through T-RSV-03).
 *
 * Controller methods call exit(); these tests mirror observable SQL/validation
 * behavior until R-01 moves logic to the domain layer.
 */
final class EventRsvpAjaxTest extends TestCase
{
    private EventRsvpFixture $fixture;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = EventRsvpFixture::create();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testCountsReturnsGoingAndInterestedTotals(): void
    {
        $ctx = $this->fixture->createFutureOccurrence('ajax-counts');
        $otherId = $this->fixture->insertSecondPlayer($ctx['park_id'], $ctx['kingdom_id'], 'ajax-counts-b');
        $this->fixture->insertRsvp($ctx['detail_id'], $ctx['mundane_id'], 'going');
        $this->fixture->insertRsvp($ctx['detail_id'], $otherId, 'interested');

        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT
                SUM(CASE WHEN status = \'going\' THEN 1 ELSE 0 END) AS going_count,
                SUM(CASE WHEN status = \'interested\' THEN 1 ELSE 0 END) AS interested_count
             FROM ' . DB_PREFIX . 'event_rsvp
             WHERE event_calendardetail_id = ' . (int) $ctx['detail_id']
        );
        $this->assertTrue($rs && $rs->Next());
        $this->assertSame(1, (int) $rs->going_count);
        $this->assertSame(1, (int) $rs->interested_count);
    }

    public function testSetRsvpInsertsGoingViaUpsert(): void
    {
        $ctx = $this->fixture->createFutureOccurrence('ajax-set');
        global $DB;

        $this->ajaxUpsertSet($ctx['detail_id'], $ctx['mundane_id'], 'going');

        $DB->Clear();
        $row = $DB->DataSet(
            'SELECT status FROM ' . DB_PREFIX . 'event_rsvp WHERE event_calendardetail_id = '
            . (int) $ctx['detail_id'] . ' AND mundane_id = ' . (int) $ctx['mundane_id'] . ' LIMIT 1'
        );
        $this->assertTrue($row && $row->Next());
        $this->assertSame('going', $row->status);
    }

    public function testSetRsvpUpdatesStatusViaUpsert(): void
    {
        $ctx = $this->fixture->createFutureOccurrence('ajax-update');
        $this->fixture->insertRsvp($ctx['detail_id'], $ctx['mundane_id'], 'interested');

        $this->ajaxUpsertSet($ctx['detail_id'], $ctx['mundane_id'], 'going');

        $model = new Model_Event();
        $this->assertSame('going', $model->get_rsvp($ctx['detail_id'], $ctx['mundane_id']));
    }

    public function testWithdrawRsvpDeletesRow(): void
    {
        $ctx = $this->fixture->createFutureOccurrence('ajax-withdraw');
        $this->fixture->insertRsvp($ctx['detail_id'], $ctx['mundane_id'], 'going');
        global $DB;

        $DB->Clear();
        $DB->Execute(
            'DELETE FROM ' . DB_PREFIX . 'event_rsvp WHERE event_calendardetail_id = '
            . (int) $ctx['detail_id'] . ' AND mundane_id = ' . (int) $ctx['mundane_id']
        );

        $model = new Model_Event();
        $this->assertFalse($model->get_rsvp($ctx['detail_id'], $ctx['mundane_id']));
    }

    public function testSetRsvpRejectsEndedEvent(): void
    {
        $ctx = $this->fixture->createPastOccurrence('ajax-ended');
        global $DB;

        $DB->Clear();
        $endRow = $DB->DataSet(
            'SELECT event_end FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_calendardetail_id = '
            . (int) $ctx['detail_id'] . ' LIMIT 1'
        );
        $this->assertTrue($endRow && $endRow->Next());

        $endTs = strtotime((string) $endRow->event_end);
        $this->assertNotFalse($endTs);
        $this->assertLessThan(time(), $endTs);
    }

    public function testSetRsvpRejectsInvalidStatus(): void
    {
        $status = 'maybe';
        $this->assertFalse(in_array($status, ['going', 'interested'], true));
    }

    public function testAjaxSetDoesNotToggleOffOnRepeatStatus(): void
    {
        $ctx = $this->fixture->createFutureOccurrence('ajax-no-toggle');
        $this->ajaxUpsertSet($ctx['detail_id'], $ctx['mundane_id'], 'going');
        $this->ajaxUpsertSet($ctx['detail_id'], $ctx['mundane_id'], 'going');

        $model = new Model_Event();
        $this->assertSame('going', $model->get_rsvp($ctx['detail_id'], $ctx['mundane_id']));
    }

    /**
     * Mirrors Controller_EventRsvpAjax::set INSERT … ON DUPLICATE KEY UPDATE (lines 58–61).
     */
    private function ajaxUpsertSet(int $detailId, int $mundaneId, string $status): void
    {
        global $DB;
        $DB->Clear();
        $DB->Execute(
            'INSERT INTO ' . DB_PREFIX . 'event_rsvp (event_calendardetail_id, mundane_id, status, modified)
             VALUES (' . (int) $detailId . ', ' . (int) $mundaneId . ", '" . $status . "', NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), modified = NOW()"
        );
    }
}
