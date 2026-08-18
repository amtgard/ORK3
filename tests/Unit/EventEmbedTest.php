<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for public schedule embed domain (RB-N).
 */
final class EventEmbedTest extends TestCase
{
    private EventPlanningFixture $fixture;

    private EventEmbed $embed;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = EventPlanningFixture::create();
        $this->embed = new EventEmbed();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testGetPublishedScheduleEmbedReturnsScheduleForPublishedEvent(): void
    {
        $ctx = $this->fixture->createPublishedEvent('embed-ok');
        $player = $this->fixture->createPlayer('embed-lead');
        $scheduleId = $this->fixture->insertSchedule($ctx['detail_id'], 'Morning Court');
        $this->fixture->insertScheduleLead($scheduleId, $player);

        $r = $this->embed->GetPublishedScheduleEmbed([
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'],
        ]);
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertSame($ctx['event_id'], (int) $r['EventId']);
        $this->assertSame($ctx['detail_id'], (int) $r['EventCalendarDetailId']);
        $this->assertNotSame('', (string) $r['Name']);
        $this->assertNotEmpty($r['ScheduleList']);
        $this->assertSame('Morning Court', $r['ScheduleList'][0]['Title']);
        $this->assertNotEmpty($r['ScheduleList'][0]['Leads'] ?? []);
    }

    public function testGetPublishedScheduleEmbedHidesDrafts(): void
    {
        $ctx = $this->fixture->createPublishedEvent('embed-draft', 'draft');
        $r = $this->embed->GetPublishedScheduleEmbed([
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => $ctx['detail_id'],
        ]);
        $this->assertNotSame(0, $r['Status'] ?? 1);
        $this->assertSame('Event not available', (string) ($r['Detail'] ?? ''));
    }

    public function testGetPublishedScheduleEmbedRejectsForeignDetailWithoutFallthrough(): void
    {
        $ctxA = $this->fixture->createPublishedEvent('embed-a');
        $ctxB = $this->fixture->createPublishedEvent('embed-b');
        $r = $this->embed->GetPublishedScheduleEmbed([
            'EventId' => $ctxA['event_id'],
            'EventCalendarDetailId' => $ctxB['detail_id'],
        ]);
        $this->assertNotSame(0, $r['Status'] ?? 1);
        $this->assertSame('Occurrence not found', (string) ($r['Detail'] ?? ''));
    }

    public function testGetPublishedScheduleEmbedResolvesDefaultOccurrence(): void
    {
        $ctx = $this->fixture->createPublishedEvent('embed-default');
        $r = $this->embed->GetPublishedScheduleEmbed([
            'EventId' => $ctx['event_id'],
            'EventCalendarDetailId' => 0,
        ]);
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertSame($ctx['detail_id'], (int) $r['EventCalendarDetailId']);
    }

    public function testGetPublishedScheduleEmbedInvalidEventId(): void
    {
        $r = $this->embed->GetPublishedScheduleEmbed(['EventId' => 0]);
        $this->assertNotSame(0, $r['Status'] ?? 1);
        $this->assertSame('Invalid event id', (string) ($r['Detail'] ?? ''));
    }

    public function testGetPublishedScheduleEmbedMissingEvent(): void
    {
        $r = $this->embed->GetPublishedScheduleEmbed(['EventId' => 2147483646]);
        $this->assertNotSame(0, $r['Status'] ?? 1);
        $this->assertSame('Event not found', (string) ($r['Detail'] ?? ''));
    }
}
