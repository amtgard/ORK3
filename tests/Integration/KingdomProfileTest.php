<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_Kingdom profile reads (T-KNG-01 through T-KNG-10).
 */
final class KingdomProfileTest extends TestCase
{
    private KingdomProfileFixture $fixture;

    private Kingdom $kingdomDomain;

    private Report $reportDomain;

    private KingdomProfile $profileDomain;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        unset($_SESSION['is_authorized_mundane_id']);

        $this->fixture = KingdomProfileFixture::create();
        $this->kingdomDomain = new Kingdom();
        $this->reportDomain = new Report();
        $this->profileDomain = new KingdomProfile();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);

        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testGetKingdomEventSummary(): void
    {
        $ctx = $this->fixture->createPublishedEvent('summary');
        $summary = $this->profileDomain->GetKingdomEventSummary([
            'KingdomId' => $ctx['kingdom_id'],
            'MundaneId' => 0,
            'IsAdmin' => false,
        ]);

        $ids = array_column($summary, 'EventId');
        $this->assertContains($ctx['event_id'], $ids);
    }

    public function testEventSummaryStaffSeesDraft(): void
    {
        $ctx = $this->fixture->createPublishedEvent('draft-staff', 'draft');
        $staff = $this->fixture->createPlayer('draft-sched', $ctx['kingdom_id']);
        $this->fixture->insertStaff($ctx['detail_id'], $staff['mundane_id'], canSchedule: true);

        $summary = $this->profileDomain->GetKingdomEventSummary([
            'KingdomId' => $ctx['kingdom_id'],
            'MundaneId' => $staff['mundane_id'],
            'IsAdmin' => false,
        ]);
        $this->assertContains($ctx['event_id'], array_column($summary, 'EventId'));
    }

    public function testGetKingdomParkDaysListing(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $days = $this->profileDomain->GetKingdomParkDays($kid);
        $this->assertIsArray($days);
        foreach ($days as $day) {
            $this->assertArrayHasKey('ParkDayId', $day);
            $this->assertArrayHasKey('ParkName', $day);
        }
    }

    public function testGetKingdomPlayerCount(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('count-me', $kid);
        unset($player);

        $count = $this->profileDomain->GetKingdomPlayerCount($kid);
        $this->assertGreaterThan(0, $count);
    }

    public function testGetKingdomExtendedParkAverages(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $weekly = $this->reportDomain->GetKingdomParkAverages(['KingdomId' => $kid, 'AverageMonths' => 6]);
        $this->assertSame(0, $weekly['Status']['Status']);

        $extended = $this->reportDomain->GetKingdomExtendedParkAverages(['KingdomId' => $kid, 'IsAdmin' => false]);
        $this->assertArrayHasKey('_kingdom', $extended);
        $this->assertArrayHasKey('att', $extended['_kingdom']);
    }

    public function testPaginatedKingdomEvents(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $page = $this->profileDomain->GetPaginatedKingdomEvents($kid, 1);
        $this->assertSame(1, $page['Window']);
        $this->assertArrayHasKey('Events', $page);
    }

    public function testGetKingdomPlayersRoster(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $this->fixture->createPlayer('roster', $kid);

        $roster = $this->profileDomain->GetKingdomPlayersRoster($kid);
        $this->assertNotEmpty($roster['players']);
        $this->assertArrayHasKey('persona', $roster['players'][0]);
        $this->assertArrayHasKey('signinCount', $roster['players'][0]);
    }

    public function testExportKingdomIcs(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $ics = $this->profileDomain->ExportKingdomEventsIcs($kid);
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('END:VCALENDAR', $ics);
    }

    public function testGetRoyalOfficerIds(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $royals = $this->profileDomain->GetRoyalOfficerIds($kid);
        $this->assertGreaterThan(0, $royals['monarch']);
        $this->assertGreaterThan(0, $royals['regent']);
    }

    public function testKingdomDomainReadsUsedByProfile(): void
    {
        $kid = $this->fixture->firstKingdomId();

        $details = $this->kingdomDomain->GetKingdomDetails(['KingdomId' => $kid]);
        $this->assertSame(0, $details['Status']['Status']);
        $this->assertNotEmpty($details['KingdomInfo']['KingdomName']);

        $parks = $this->kingdomDomain->GetParks(['KingdomId' => $kid]);
        $this->assertSame(0, $parks['Status']['Status']);
        $this->assertNotEmpty($parks['Parks']);

        $officers = $this->kingdomDomain->GetOfficers(['KingdomId' => $kid]);
        $this->assertSame(0, $officers['Status']['Status']);

        $family = $this->kingdomDomain->GetFamilyKingdomIds($kid);
        $this->assertContains($kid, $family);

        $monthly = $this->reportDomain->GetKingdomParkMonthlyAverages(['KingdomId' => $kid]);
        $this->assertSame(0, $monthly['Status']['Status']);
    }

    public function testGetParksUnknownKingdomReturnsEmptyParksArray(): void
    {
        // KingdomId 1 is not a seeded sandbox kingdom; miss path used to omit Parks
        // and Kingdom/map array_filter()'d null → HTTP 500.
        $parks = $this->kingdomDomain->GetParks(['KingdomId' => 1]);
        $this->assertIsArray($parks['Parks'] ?? null);
        $this->assertSame([], $parks['Parks']);
        $this->assertNotSame(0, $parks['Status']['Status'] ?? 0);
    }

    public function testReportAveragesShapeAndValues(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('averages', $kid);
        $this->fixture->createRecentAttendance(
            $player['mundane_id'],
            $player['park_id'],
            $kid,
        );
        $weekly = $this->reportDomain->GetKingdomParkAverages([
            'KingdomId' => $kid,
            'AverageMonths' => 6,
            'ReportFromDate' => '',
            'AverageWeeks' => '',
        ]);
        $this->assertSame(0, $weekly['Status']['Status']);
        $summary = $weekly['KingdomParkAveragesSummary'];
        $this->assertIsArray($summary);
        $this->assertNotEmpty($summary);
        $first = $summary[0];
        $this->assertArrayHasKey('ParkId', $first);
        $this->assertArrayHasKey('ParkName', $first);
        $this->assertArrayHasKey('AttendanceCount', $first);
        $this->assertGreaterThan(0, (int) $first['ParkId']);
        $this->assertNotSame('', (string) $first['ParkName']);

        $byWeeks = $this->reportDomain->GetKingdomParkAverages([
            'KingdomId' => $kid,
            'AverageWeeks' => 26,
            'AverageMonths' => '',
            'ReportFromDate' => date('Y-m-d', strtotime('-6 month')),
        ]);
        $this->assertSame(0, $byWeeks['Status']['Status']);
        $this->assertIsArray($byWeeks['KingdomParkAveragesSummary']);

        $monthly = $this->reportDomain->GetKingdomParkMonthlyAverages(['KingdomId' => $kid]);
        $this->assertSame(0, $monthly['Status']['Status']);
        $monthRows = $monthly['KingdomParkMonthlySummary'] ?? [];
        $this->assertNotEmpty($monthRows);
        $this->assertArrayHasKey('MonthlyAvg', $monthRows[0]);
        $this->assertArrayHasKey('ParkId', $monthRows[0]);
    }
}
