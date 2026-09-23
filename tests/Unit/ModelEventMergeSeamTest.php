<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once DIR_UI . 'model/model.Event.php';

/**
 * Merge-seam characterization tests for Model_Event (PR #492 x PR #493).
 *
 * The union merge of PR #492 (domain-hop helpers `_planning()` / `_embed()`)
 * and PR #493 (`get_event_summary_for_redirect()` / `_event_domain()`) put all
 * four factory helpers side by side in orkui/model/model.Event.php. These
 * tests pin the behavior reachable through each helper so a bad resolution
 * (dropped helper, wrong class, swapped delegate) fails loudly.
 *
 * Style follows EventEmbedTest: characterization against the TEST sandbox
 * via EventPlanningFixture; skips when the test DB is unavailable.
 */
final class ModelEventMergeSeamTest extends TestCase
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

    // ── PR #493 side: get_event_summary_for_redirect() via _event_domain() ──

    public function testEventSummaryForRedirectReturnsNameAndKingdom(): void
    {
        $ctx = $this->fixture->createPublishedEvent('seam-summary');

        $summary = $this->model->get_event_summary_for_redirect($ctx['event_id']);

        $this->assertSame($ctx['kingdom_id'], $summary['KingdomId']);
        $this->assertNotSame('', $summary['Name']);
        $this->assertStringContainsString('seam-summary', $summary['Name']);
    }

    public function testEventSummaryForRedirectUnknownEventIsEmpty(): void
    {
        $summary = $this->model->get_event_summary_for_redirect(999999999);

        $this->assertSame(0, $summary['KingdomId']);
        $this->assertSame('', $summary['Name']);
    }

    public function testEventSummaryForRedirectInvalidIdIsEmpty(): void
    {
        $summary = $this->model->get_event_summary_for_redirect(0);

        $this->assertSame(0, $summary['KingdomId']);
        $this->assertSame('', $summary['Name']);
    }

    // ── PR #492 side: _planning() delegates ──

    public function testDetailBelongsToEventTrueForOwnDetail(): void
    {
        $ctx = $this->fixture->createPublishedEvent('seam-belongs');

        $this->assertTrue(
            $this->model->detail_belongs_to_event($ctx['event_id'], $ctx['detail_id'])
        );
    }

    public function testDetailBelongsToEventFalseForForeignDetail(): void
    {
        $a = $this->fixture->createPublishedEvent('seam-belongs-a');
        $b = $this->fixture->createPublishedEvent('seam-belongs-b');

        $this->assertFalse(
            $this->model->detail_belongs_to_event($a['event_id'], $b['detail_id'])
        );
    }

    public function testDefaultOccurrenceIdResolvesToFixtureDetail(): void
    {
        $ctx = $this->fixture->createPublishedEvent('seam-occurrence');

        $this->assertSame(
            $ctx['detail_id'],
            $this->model->get_default_occurrence_id($ctx['event_id'])
        );
    }

    public function testDefaultOccurrenceIdUnknownEventIsZero(): void
    {
        $this->assertSame(0, $this->model->get_default_occurrence_id(999999999));
    }

    // ── PR #492 side: _embed() delegate ──

    public function testPublishedScheduleEmbedHappyPath(): void
    {
        $ctx = $this->fixture->createPublishedEvent('seam-embed');
        $scheduleId = $this->fixture->insertSchedule($ctx['detail_id'], 'Seam Court');

        $embed = $this->model->get_published_schedule_embed($ctx['event_id'], $ctx['detail_id']);

        $this->assertTrue($embed['ok']);
        $this->assertSame($ctx['event_id'], $embed['event_id']);
        $this->assertSame($ctx['detail_id'], $embed['detail_id']);
        $this->assertSame('Seam Court', $embed['schedule'][0]['Title'] ?? null);
    }

    public function testPublishedScheduleEmbedUnknownEventFails(): void
    {
        $embed = $this->model->get_published_schedule_embed(999999999, 0);

        $this->assertFalse($embed['ok']);
        $this->assertSame(404, $embed['http']);
        $this->assertSame('Event not found', $embed['error']);
    }

    public function testPublishedScheduleEmbedDraftEventKeepsDomainError(): void
    {
        // Draft events yield the distinct 'Event not available' detail; a bad
        // merge of the error-mapping branch would collapse it to 'Event not found'.
        $ctx = $this->fixture->createPublishedEvent('seam-draft', 'draft');

        $embed = $this->model->get_published_schedule_embed($ctx['event_id'], $ctx['detail_id']);

        $this->assertFalse($embed['ok']);
        $this->assertSame(404, $embed['http']);
        $this->assertSame('Event not available', $embed['error']);
    }

    public function testPublishedScheduleEmbedOmittedDetailResolvesDefaultOccurrence(): void
    {
        // Omitting the second arg must resolve the event's default occurrence
        // (pins the `$detail_id = 0` default in the merged signature).
        $ctx = $this->fixture->createPublishedEvent('seam-defarg');

        $embed = $this->model->get_published_schedule_embed($ctx['event_id']);

        $this->assertTrue($embed['ok']);
        $this->assertSame($ctx['detail_id'], $embed['detail_id']);
    }

    public function testPublishedScheduleEmbedInvalidIdIs400(): void
    {
        $embed = $this->model->get_published_schedule_embed(0, 0);

        $this->assertFalse($embed['ok']);
        $this->assertSame(400, $embed['http']);
    }

    // ── Seam integrity: all four helpers exist and type correctly ──

    public function testMergeSeamHelpersAllPresent(): void
    {
        $rc = new ReflectionClass(Model_Event::class);

        foreach (['_planning', '_embed', '_event_domain'] as $helper) {
            $this->assertTrue($rc->hasMethod($helper), "helper $helper missing after merge");
        }
        $this->assertSame(
            'EventPlanning',
            (string) $rc->getMethod('_planning')->getReturnType()
        );
        $this->assertSame(
            'EventEmbed',
            (string) $rc->getMethod('_embed')->getReturnType()
        );
        $this->assertSame(
            'Event',
            (string) $rc->getMethod('_event_domain')->getReturnType()
        );
    }
}
