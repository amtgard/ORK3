<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_Park profile reads (T-PRK-01 through T-PRK-05).
 */
final class ParkProfileTest extends TestCase
{
    private ParkProfileFixture $fixture;

    private Park $parkDomain;

    private ParkProfile $profileDomain;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        unset($_SESSION['is_authorized_mundane_id']);

        $this->fixture = ParkProfileFixture::create();
        $this->parkDomain = new Park();
        $this->profileDomain = new ParkProfile();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);

        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testGetParkEventSummary(): void
    {
        $parkId = $this->fixture->firstParkId();
        $ctx = $this->fixture->createPublishedEvent($parkId, 'summary');

        $summary = $this->profileDomain->GetParkEventSummary([
            'ParkId' => $parkId,
            'KingdomId' => $this->fixture->kingdomIdForPark($parkId),
            'MundaneId' => 0,
            'IsAdmin' => false,
        ]);
        $this->assertContains($ctx['event_id'], array_column($summary, 'EventId'));
    }

    public function testParkEventSummaryBatchCoords(): void
    {
        $parkId = $this->fixture->firstParkId();
        $ctx = $this->fixture->createPublishedEvent($parkId, 'coords');
        $summary = $this->profileDomain->GetParkEventSummary([
            'ParkId' => $parkId,
            'KingdomId' => $this->fixture->kingdomIdForPark($parkId),
            'MundaneId' => 0,
            'IsAdmin' => false,
        ]);
        $detailIds = array_column(array_filter($summary, static fn ($row) => !empty($row['EventId'])), 'NextDetailId');

        $coordMap = $this->profileDomain->GetBatchDetailCoords($detailIds);
        $this->assertArrayHasKey($ctx['detail_id'], $coordMap);
        $this->assertArrayHasKey('event_loc', $coordMap[$ctx['detail_id']]);
    }

    public function testGetParkPlayersRoster(): void
    {
        $parkId = $this->fixture->firstParkId();
        $this->fixture->createPlayer($parkId, 'roster');

        $roster = $this->profileDomain->GetParkPlayersRoster($parkId);
        $this->assertNotEmpty($roster);
        $this->assertArrayHasKey('Persona', $roster[0]);
        $this->assertArrayHasKey('SigninCount', $roster[0]);
    }

    public function testGetParkAttendanceAverages(): void
    {
        $parkId = $this->fixture->firstParkId();
        $averages = $this->profileDomain->GetParkAttendanceAverages($parkId);
        $this->assertArrayHasKey('MonthlyAvg', $averages);
        $this->assertArrayHasKey('WeeklyAvg', $averages);
        $this->assertGreaterThanOrEqual(0, $averages['MonthlyAvg']);
        $this->assertGreaterThanOrEqual(0, $averages['WeeklyAvg']);
    }

    public function testParkEventStaffSeesDraft(): void
    {
        $parkId = $this->fixture->firstParkId();
        $ctx = $this->fixture->createPublishedEvent($parkId, 'draft-staff', 'draft');
        $staff = $this->fixture->createPlayer($parkId, 'draft-sched');
        $this->fixture->insertStaff($ctx['detail_id'], $staff['mundane_id'], canSchedule: true);

        $summary = $this->profileDomain->GetParkEventSummary([
            'ParkId' => $parkId,
            'KingdomId' => $this->fixture->kingdomIdForPark($parkId),
            'MundaneId' => $staff['mundane_id'],
            'IsAdmin' => false,
        ]);
        $this->assertContains($ctx['event_id'], array_column($summary, 'EventId'));
    }

    public function testParkDomainGetParkDetails(): void
    {
        $parkId = $this->fixture->firstParkId();
        $response = $this->parkDomain->GetParkDetails(['ParkId' => $parkId]);
        $this->assertSame(0, $response['Status']['Status']);
        $this->assertSame($parkId, (int) $response['ParkId']);
        $this->assertNotEmpty($response['ParkName']);
    }
}
