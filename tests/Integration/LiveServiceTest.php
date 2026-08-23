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
            // [iso, park_id, event_id, cdid, is_first, is_self]
            $this->assertCount(6, $first);
        }
    }

    // PUBLIC since 2026-08-23 (Ken's call): the Live page no longer requires
    // login, so its feeds serve tokenless callers. The wire format stays
    // player-anonymous (mundane_id stripped in class.Live).
    public function testLiveServiceIsPublic(): void
    {
        $service = new LiveService();

        unset($_SESSION['is_authorized_mundane_id']);
        $ok = $service->GetStats('');
        $this->assertArrayNotHasKey('Status', $ok);
        $this->assertArrayHasKey('active_3h', $ok);
        $this->assertIsInt($ok['active_3h']);

        unset($_SESSION['is_authorized_mundane_id']);
        $okRecent = $service->GetRecent(null);
        $this->assertArrayNotHasKey('Status', $okRecent);
        $this->assertArrayHasKey('signins', $okRecent);
    }
}
