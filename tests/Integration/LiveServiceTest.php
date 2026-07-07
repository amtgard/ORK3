<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_Live JSON data sources (T-LIB-01).
 */
final class LiveServiceTest extends TestCase
{
    private Live $live;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->live = new Live();
    }

    public function testGetStatsShape(): void
    {
        $stats = $this->live->stats();
        $this->assertArrayHasKey('now', $stats);
        $this->assertArrayHasKey('parks', $stats);
        $this->assertArrayHasKey('events', $stats);
        $this->assertArrayHasKey('active_3h', $stats);
        $this->assertIsInt($stats['active_3h']);
    }

    public function testGetRecentLimit(): void
    {
        $recent = $this->live->recent();
        $this->assertArrayHasKey('signins', $recent);
        $this->assertArrayHasKey('now', $recent);
        $this->assertLessThanOrEqual(50, count($recent['signins']));

        if ($recent['signins'] !== []) {
            $first = $recent['signins'][0];
            $this->assertIsArray($first);
            $this->assertCount(5, $first);
        }
    }
}
