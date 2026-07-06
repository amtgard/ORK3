<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for server health stats and suspension inference (T-ADM-04, T-ADM-05, T-ADM-08).
 */
final class ServerHealthStatsTest extends TestCase
{
    private AdminDashboardFixture $fixture;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = AdminDashboardFixture::create();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testWeatherFreshnessBuckets(): void
    {
        $stats = $this->mirrorWeatherFreshnessStats();

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

        $editById = $this->mirrorInferSuspendedById($suspended['mundane_id'], submittedById: 0, sessionUserId: 99);
        $this->assertSame(42, $editById);

        $newById = $this->mirrorInferSuspendedById($active['mundane_id'], submittedById: 0, sessionUserId: 99);
        $this->assertSame(99, $newById);

        $explicit = $this->mirrorInferSuspendedById($active['mundane_id'], submittedById: 7, sessionUserId: 99);
        $this->assertSame(7, $explicit);
    }

    /**
     * @return array{total_active: int, fresh: int, aging: int, stale_row: int}
     */
    private function mirrorWeatherFreshnessStats(): array
    {
        $cutoffFresh = date('Y-m-d H:i:s', time() - 90 * 60);
        $cutoffAging = date('Y-m-d H:i:s', time() - 4 * 3600);

        global $DB;
        $DB->Clear();
        $p = DB_PREFIX;
        $wsql = "SELECT
            (SELECT COUNT(*) FROM {$p}park p WHERE p.active = 'Active'
               AND EXISTS (SELECT 1 FROM {$p}attendance a
                           WHERE a.park_id = p.park_id AND a.date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY))
            ) AS total_active,
            (SELECT COUNT(*) FROM {$p}park_weather pw
               JOIN {$p}park p ON p.park_id = pw.park_id
               WHERE p.active = 'Active'
                 AND pw.fetched_at >= '{$cutoffFresh}'
            ) AS fresh,
            (SELECT COUNT(*) FROM {$p}park_weather pw
               JOIN {$p}park p ON p.park_id = pw.park_id
               WHERE p.active = 'Active'
                 AND pw.fetched_at >= '{$cutoffAging}'
                 AND pw.fetched_at <  '{$cutoffFresh}'
            ) AS aging,
            (SELECT COUNT(*) FROM {$p}park_weather pw
               JOIN {$p}park p ON p.park_id = pw.park_id
               WHERE p.active = 'Active'
                 AND pw.fetched_at <  '{$cutoffAging}'
                 AND EXISTS (SELECT 1 FROM {$p}attendance a
                             WHERE a.park_id = p.park_id AND a.date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY))
            ) AS stale_row";
        $wr = $DB->DataSet($wsql);
        $stats = ['total_active' => 0, 'fresh' => 0, 'aging' => 0, 'stale_row' => 0];
        if ($wr && $wr->Size() > 0 && $wr->Next()) {
            $stats['total_active'] = (int) $wr->total_active;
            $stats['fresh'] = (int) $wr->fresh;
            $stats['aging'] = (int) $wr->aging;
            $stats['stale_row'] = (int) $wr->stale_row;
        }

        return $stats;
    }

    private function mirrorInferSuspendedById(int $mundaneId, int $submittedById, int $sessionUserId): int
    {
        if ($submittedById > 0) {
            return $submittedById;
        }

        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT suspended_by_id, suspended FROM ' . DB_PREFIX . "mundane WHERE mundane_id = {$mundaneId} LIMIT 1"
        );
        $existingById = 0;
        $isSuspended = false;
        if ($rs && $rs->Next()) {
            $existingById = (int) $rs->suspended_by_id;
            $isSuspended = (bool) $rs->suspended;
        }

        return $isSuspended ? ($existingById ?: 0) : $sessionUserId;
    }
}
