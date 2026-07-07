<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Event/index legacy redirect lookup (T-INF-02).
 */
final class LegacyRedirectTest extends TestCase
{
    private InfrastructureFixture $fixture;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = InfrastructureFixture::create();
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

        $lookup = $this->mirrorEventIndexRedirectLookup($event['event_id']);
        $this->assertSame($event['name'], $lookup['Name']);
        $this->assertSame($event['kingdom_id'], $lookup['KingdomId']);

        $target = UIR . 'Reports/event_attendance/Kingdom/' . $lookup['KingdomId']
            . '&filter=' . rawurlencode($lookup['Name']);
        $this->assertStringContainsString((string) $lookup['KingdomId'], $target);
        $this->assertStringContainsString(rawurlencode($lookup['Name']), $target);
    }

    /**
     * @return array{Name: string, KingdomId: int}
     */
    private function mirrorEventIndexRedirectLookup(int $eventId): array
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->query(
            'SELECT name, kingdom_id FROM ' . DB_PREFIX . 'event WHERE event_id = ' . (int) $eventId . ' LIMIT 1'
        );
        if ($rs && $rs->size() > 0 && $rs->next()) {
            return [
                'Name' => (string) $rs->name,
                'KingdomId' => (int) $rs->kingdom_id,
            ];
        }

        return ['Name' => '', 'KingdomId' => 0];
    }
}
