<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for server health stats and suspension inference (T-ADM-04, T-ADM-05, T-ADM-08).
 */
final class ServerHealthStatsTest extends TestCase
{
    private AdminDashboardFixture $fixture;
    private Administration $adminDomain;
    private Player $playerDomain;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = AdminDashboardFixture::create();
        $this->adminDomain = new Administration();
        $this->playerDomain = new Player();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testWeatherFreshnessBuckets(): void
    {
        $stats = $this->adminDomain->GetServerHealthWeatherSummary();

        $this->assertArrayHasKey('total_active', $stats);
        $this->assertArrayHasKey('fresh', $stats);
        $this->assertArrayHasKey('aging', $stats);
        $this->assertArrayHasKey('stale_row', $stats);
        $this->assertGreaterThanOrEqual(0, $stats['total_active']);
        $this->assertGreaterThanOrEqual(0, $stats['fresh']);
        $this->assertGreaterThanOrEqual(0, $stats['aging']);
        $this->assertGreaterThanOrEqual(0, $stats['stale_row']);
    }

    public function testSetPlayerSuspensionByIdInference(): void
    {
        $parkId = $this->fixture->firstParkId();
        $suspended = $this->fixture->createPlayer($parkId, 'suspended', suspended: true, suspendedById: 42);
        $active = $this->fixture->createPlayer($parkId, 'active-new');

        $editById = $this->playerDomain->InferSuspendedById($suspended['mundane_id'], 0, 99);
        $this->assertSame(42, $editById);

        $newById = $this->playerDomain->InferSuspendedById($active['mundane_id'], 0, 99);
        $this->assertSame(99, $newById);

        $explicit = $this->playerDomain->InferSuspendedById($active['mundane_id'], 7, 99);
        $this->assertSame(7, $explicit);
    }
}
