<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_Event index/template RSVP batch reads (T-EVT-01, T-EVT-02).
 */
final class EventRsvpBatchTest extends TestCase
{
    private EventPlanningFixture $fixture;

    private Model_Event $model;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = EventPlanningFixture::create();
        $this->model = new Model_Event();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testBatchRsvpCountsForEvent(): void
    {
        $ctx = $this->fixture->createPublishedEvent('batch-index');
        $detailB = $this->fixture->createDetailOnEvent(
            $ctx['event_id'],
            date('Y-m-d H:i:s', strtotime('+14 days')),
            date('Y-m-d H:i:s', strtotime('+14 days +6 hours')),
        );
        $playerA = $this->fixture->createPlayer('batch-a');
        $playerB = $this->fixture->createPlayer('batch-b');
        $this->fixture->insertRsvp($ctx['detail_id'], $playerA, 'going');
        $this->fixture->insertRsvp($ctx['detail_id'], $playerB, 'interested');
        $this->fixture->insertRsvp($detailB, $playerA, 'going');

        $details = $this->model->get_event_details($ctx['event_id']);
        $this->assertSame(0, $details['Status']['Status']);
        $allDetails = $details['CalendarEventDetails'] ?? [];
        $detailIds = array_map(static fn ($d) => (int) $d['EventCalendarDetailId'], $allDetails);

        $indexCounts = $this->mirrorIndexBatchRsvpCounts($detailIds);
        $this->assertSame(1, $indexCounts[$ctx['detail_id']]['going'] ?? 0);
        $this->assertSame(1, $indexCounts[$ctx['detail_id']]['interested'] ?? 0);
        $this->assertSame(2, $indexCounts[$ctx['detail_id']]['total'] ?? 0);
        $this->assertSame(1, $indexCounts[$detailB]['going'] ?? 0);

        $templateCounts = $this->mirrorTemplateRsvpCounts($detailIds);
        $this->assertSame(2, $templateCounts[$ctx['detail_id']] ?? 0);
        $this->assertSame(1, $templateCounts[$detailB] ?? 0);
    }

    public function testRsvpToggleOwnershipCheck(): void
    {
        $ctxA = $this->fixture->createPublishedEvent('toggle-own-a');
        $ctxB = $this->fixture->createPublishedEvent('toggle-own-b');
        $player = $this->fixture->createPlayer('toggle-player');

        $allowed = $this->mirrorRsvpToggleAllowed($ctxA['event_id'], $ctxA['detail_id']);
        $this->assertTrue($allowed);
        $this->model->toggle_rsvp($ctxA['detail_id'], $player);
        $this->assertSame('going', $this->model->get_rsvp($ctxA['detail_id'], $player));

        $foreign = $this->mirrorRsvpToggleAllowed($ctxA['event_id'], $ctxB['detail_id']);
        $this->assertFalse($foreign);
        $this->assertFalse($this->model->get_rsvp($ctxB['detail_id'], $player));
    }

    /**
     * @param list<int> $detailIds
     *
     * @return array<int, array{going: int, interested: int, total: int}>
     */
    private function mirrorIndexBatchRsvpCounts(array $detailIds): array
    {
        if ($detailIds === []) {
            return [];
        }

        global $DB;
        $idList = implode(',', array_map('intval', $detailIds));
        $DB->Clear();
        $countResult = $DB->DataSet(
            'SELECT event_calendardetail_id, status, COUNT(*) AS cnt FROM ' . DB_PREFIX
            . "event_rsvp WHERE event_calendardetail_id IN ({$idList}) GROUP BY event_calendardetail_id, status"
        );
        $rsvpCounts = [];
        while ($countResult && $countResult->Next()) {
            $did = (int) $countResult->event_calendardetail_id;
            if (!isset($rsvpCounts[$did])) {
                $rsvpCounts[$did] = ['going' => 0, 'interested' => 0, 'total' => 0];
            }
            $rsvpCounts[$did][(string) $countResult->status] = (int) $countResult->cnt;
            $rsvpCounts[$did]['total'] += (int) $countResult->cnt;
        }

        return $rsvpCounts;
    }

    /**
     * @param list<int> $detailIds
     *
     * @return array<int, int>
     */
    private function mirrorTemplateRsvpCounts(array $detailIds): array
    {
        if ($detailIds === []) {
            return [];
        }

        global $DB;
        $idList = implode(',', array_map('intval', $detailIds));
        $DB->Clear();
        $rsvpResult = $DB->DataSet(
            'SELECT event_calendardetail_id, COUNT(*) AS cnt FROM ' . DB_PREFIX
            . "event_rsvp WHERE event_calendardetail_id IN ({$idList}) GROUP BY event_calendardetail_id"
        );
        $rsvpCounts = [];
        while ($rsvpResult && $rsvpResult->Next()) {
            $rsvpCounts[(int) $rsvpResult->event_calendardetail_id] = (int) $rsvpResult->cnt;
        }

        return $rsvpCounts;
    }

    private function mirrorRsvpToggleAllowed(int $eventId, int $detailId): bool
    {
        global $DB;
        $DB->Clear();
        $ownerCheck = $DB->DataSet(
            'SELECT event_id FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_calendardetail_id = '
            . $detailId . ' AND event_id = ' . $eventId . ' LIMIT 1'
        );

        return (bool) ($ownerCheck && $ownerCheck->Size() > 0 && $ownerCheck->Next());
    }
}
