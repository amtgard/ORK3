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

    // GetAttendanceDates backs the PUBLIC attendance report: no token or
    // authority required (C-22 over-gated it; corrected 2026-08-22).
    public function testGetAttendanceDatesIsPublic(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $player = $this->fixture->createPlayer($parkId, 'c22-dates');
        $this->fixture->insertAttendance($player['mundane_id'], $parkId, $kid, '2025-04-01');

        // Anonymous (no token at all) gets the dates the public report shows.
        unset($_SESSION['is_authorized_mundane_id']);
        $anon = $this->report->GetAttendanceDates([
            'Type' => 'Kingdom',
            'Id' => $kid,
        ]);
        $this->assertSame(0, $anon['Status']['Status']);
        $this->assertContains('2025-04-01', $anon['Dates']);

        // An ordinary logged-in player (no authority) likewise.
        $stranger = $this->fixture->createPlayer($parkId, 'c22-dates-stranger');
        unset($_SESSION['is_authorized_mundane_id']);
        $plain = $this->report->GetAttendanceDates([
            'Type' => 'Kingdom',
            'Id' => $kid,
            'Token' => $stranger['token'],
        ]);
        $this->assertSame(0, $plain['Status']['Status']);
        $this->assertContains('2025-04-01', $plain['Dates']);
    }

    // GetLadderAwardGrid is offered to every logged-in player by the
    // kingdom/park Reports tab: any valid session suffices, no officer
    // authority (C-22 over-gated it; corrected 2026-08-22).
    public function testGetLadderAwardGridRequiresOnlyValidSession(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $stranger = $this->fixture->createPlayer($parkId, 'c22-grid-stranger');

        unset($_SESSION['is_authorized_mundane_id']);
        $deniedAnon = $this->report->GetLadderAwardGrid([
            'KingdomId' => $kid,
        ]);
        $this->assertSame(ServiceErrorIds::SecureTokenFailure, $deniedAnon['Status']['Status'] ?? null);

        unset($_SESSION['is_authorized_mundane_id']);
        $grid = $this->report->GetLadderAwardGrid([
            'KingdomId' => $kid,
            'Token' => $stranger['token'],
        ]);
        $this->assertSame(0, $grid['Status']['Status'] ?? 0);
        $this->assertArrayHasKey('GridRows', $grid);
    }
}
