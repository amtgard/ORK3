<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Token and scope gates for new Report domain getters (C-22).
 */
final class ReportDomainAuthTest extends TestCase
{
    private ReportsFixture $fixture;

    private Report $report;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = ReportsFixture::create();
        $this->report = new Report();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testGetAdminDashboardStatsRequiresGlobalAdmin(): void
    {
        $parkId = $this->fixture->firstParkId();
        $admin = $this->fixture->createPlayer($parkId, 'c22-admin');
        $this->fixture->insertGlobalAdmin($admin['mundane_id']);
        $stranger = $this->fixture->createPlayer($parkId, 'c22-stranger');

        unset($_SESSION['is_authorized_mundane_id']);
        $denied = $this->report->GetAdminDashboardStats($stranger['token']);
        $this->assertSame(ServiceErrorIds::NoAuthorization, $denied['Status'] ?? null);

        unset($_SESSION['is_authorized_mundane_id']);
        $deniedEmpty = $this->report->GetAdminDashboardStats('');
        $this->assertSame(ServiceErrorIds::SecureTokenFailure, $deniedEmpty['Status'] ?? null);

        unset($_SESSION['is_authorized_mundane_id']);
        $stats = $this->report->GetAdminDashboardStats($admin['token']);
        $this->assertArrayHasKey('TrendStats', $stats);
        $this->assertArrayHasKey('PrevWeekly', $stats);
        $this->assertArrayNotHasKey('Error', $stats);
    }

    public function testGetAttendanceDatesRequiresScopedAuthority(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $player = $this->fixture->createPlayer($parkId, 'c22-dates');
        $this->fixture->insertAttendance($player['mundane_id'], $parkId, $kid, '2025-04-01');

        $stranger = $this->fixture->createPlayer($parkId, 'c22-dates-stranger');
        unset($_SESSION['is_authorized_mundane_id']);
        $denied = $this->report->GetAttendanceDates([
            'Type' => 'Kingdom',
            'Id' => $kid,
            'Token' => $stranger['token'],
        ]);
        $this->assertSame(ServiceErrorIds::NoAuthorization, $denied['Status']['Status'] ?? null);

        $editor = $this->fixture->createPlayer($parkId, 'c22-dates-editor');
        $this->fixture->insertScopedAuth($editor['mundane_id'], 0, $kid, AUTH_CREATE);
        unset($_SESSION['is_authorized_mundane_id']);
        $kingdom = $this->report->GetAttendanceDates([
            'Type' => 'Kingdom',
            'Id' => $kid,
            'Token' => $editor['token'],
        ]);
        $this->assertSame(0, $kingdom['Status']['Status']);
        $this->assertContains('2025-04-01', $kingdom['Dates']);
    }
}
