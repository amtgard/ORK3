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

    public function testLiveServiceRequiresToken(): void
    {
        $service = new LiveService();
        $fixture = AdminDashboardFixture::create();
        $parkId = $fixture->firstParkId();
        $player = $fixture->createPlayer($parkId, 'c08-live');

        unset($_SESSION['is_authorized_mundane_id']);
        $denied = $service->GetStats('');
        $this->assertSame(ServiceErrorIds::SecureTokenFailure, $denied['Status'] ?? null);

        unset($_SESSION['is_authorized_mundane_id']);
        $ok = $service->GetStats($player['token']);
        $this->assertArrayNotHasKey('Status', $ok);
        $this->assertArrayHasKey('active_3h', $ok);
        $this->assertIsInt($ok['active_3h']);

        unset($_SESSION['is_authorized_mundane_id']);
        $deniedRecent = $service->GetRecent('bad-token');
        $this->assertSame(ServiceErrorIds::SecureTokenFailure, $deniedRecent['Status'] ?? null);

        unset($_SESSION['is_authorized_mundane_id']);
        $okRecent = $service->GetRecent($player['token']);
        $this->assertArrayNotHasKey('Status', $okRecent);
        $this->assertArrayHasKey('signins', $okRecent);

        $fixture->cleanup();
    }
}
