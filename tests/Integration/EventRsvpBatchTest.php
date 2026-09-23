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

    private Event $eventDomain;

    private EventPlanning $planning;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = EventPlanningFixture::create();
        $this->model = new Model_Event();
        $this->eventDomain = new Event();
        $this->planning = new EventPlanning();
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

        $indexCounts = $this->indexBatchRsvpCounts($detailIds);
        $this->assertSame(1, $indexCounts[$ctx['detail_id']]['going'] ?? 0);
        $this->assertSame(1, $indexCounts[$ctx['detail_id']]['interested'] ?? 0);
        $this->assertSame(2, $indexCounts[$ctx['detail_id']]['total'] ?? 0);
        $this->assertSame(1, $indexCounts[$detailB]['going'] ?? 0);

        $templateCounts = $this->templateRsvpCounts($detailIds);
        $this->assertSame(2, $templateCounts[$ctx['detail_id']] ?? 0);
        $this->assertSame(1, $templateCounts[$detailB] ?? 0);
    }

    public function testRsvpToggleOwnershipCheck(): void
    {
        $ctxA = $this->fixture->createPublishedEvent('toggle-own-a');
        $ctxB = $this->fixture->createPublishedEvent('toggle-own-b');
        $player = $this->fixture->createGrantorWithoutAuth('toggle-player');
        unset($_SESSION['is_authorized_mundane_id']);

        $allowed = $this->detailBelongsToEvent($ctxA['event_id'], $ctxA['detail_id']);
        $this->assertTrue($allowed);
        $this->model->toggle_rsvp($ctxA['detail_id'], $player['mundane_id'], $player['token']);
        $this->assertSame('going', $this->model->get_rsvp($ctxA['detail_id'], $player['mundane_id']));

        $foreign = $this->detailBelongsToEvent($ctxA['event_id'], $ctxB['detail_id']);
        $this->assertFalse($foreign);
        $this->assertFalse($this->model->get_rsvp($ctxB['detail_id'], $player['mundane_id']));
    }

    public function testRsvpSummaryBatchIncludesUserStatus(): void
    {
        $ctx = $this->fixture->createPublishedEvent('summary-user');
        $player = $this->fixture->createPlayer('summary-player');
        $this->fixture->insertRsvp($ctx['detail_id'], $player, 'interested');

        $r = $this->eventDomain->GetRsvpSummaryBatch([
            'EventCalendarDetailIds' => [$ctx['detail_id']],
            'MundaneId' => $player,
        ]);
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertSame('interested', $r['Items'][0]['RsvpStatus'] ?? '');
    }

    /**
     * @param list<int> $detailIds
     *
     * @return array<int, array{going: int, interested: int, total: int}>
     */
    private function indexBatchRsvpCounts(array $detailIds): array
    {
        $r = $this->eventDomain->GetRsvpCountsBatch(['EventCalendarDetailIds' => $detailIds]);
        $this->assertSame(0, $r['Status']['Status']);

        $rsvpCounts = [];
        foreach ($r['Items'] ?? [] as $item) {
            $did = (int) $item['EventCalendarDetailId'];
            $rsvpCounts[$did] = [
                'going' => (int) ($item['Going'] ?? 0),
                'interested' => (int) ($item['Interested'] ?? 0),
                'total' => (int) ($item['Total'] ?? 0),
            ];
        }

        return $rsvpCounts;
    }

    /**
     * @param list<int> $detailIds
     *
     * @return array<int, int>
     */
    private function templateRsvpCounts(array $detailIds): array
    {
        $r = $this->eventDomain->GetRsvpCountsBatch(['EventCalendarDetailIds' => $detailIds]);
        $this->assertSame(0, $r['Status']['Status']);

        $rsvpCounts = [];
        foreach ($r['Items'] ?? [] as $item) {
            $rsvpCounts[(int) $item['EventCalendarDetailId']] = (int) ($item['Total'] ?? 0);
        }

        return $rsvpCounts;
    }

    private function detailBelongsToEvent(int $eventId, int $detailId): bool
    {
        $r = $this->planning->AssertDetailBelongsToEvent([
            'EventId' => $eventId,
            'EventCalendarDetailId' => $detailId,
        ]);
        $this->assertSame(0, $r['Status']['Status']);

        return (bool) ($r['Belongs'] ?? false);
    }
}
