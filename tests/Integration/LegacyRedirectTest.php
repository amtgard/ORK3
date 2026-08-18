<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Event/index legacy redirect lookup (T-INF-02).
 */
final class LegacyRedirectTest extends TestCase
{
    private InfrastructureFixture $fixture;

    private Event $eventDomain;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = InfrastructureFixture::create();
        $this->eventDomain = new Event();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testEventIndexRedirectLookup(): void
    {
        $parkId = $this->fixture->firstParkId();
        $event = $this->fixture->createPublishedEvent($parkId, 'redirect');

        $lookup = $this->eventDomain->GetEventSummaryForRedirect($event['event_id']);
        $this->assertSame($event['name'], $lookup['Name']);
        $this->assertSame($event['kingdom_id'], $lookup['KingdomId']);

        $target = UIR . 'Reports/event_attendance/Kingdom/' . $lookup['KingdomId']
            . '&filter=' . rawurlencode($lookup['Name']);
        $this->assertStringContainsString((string) $lookup['KingdomId'], $target);
        $this->assertStringContainsString(rawurlencode($lookup['Name']), $target);
    }
}
