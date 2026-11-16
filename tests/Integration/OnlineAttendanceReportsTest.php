<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Integration tests: report surfaces split attendance into In-Person / Online / Total.
 *
 * Seeds a fixture park inside an otherwise empty calendar month so every figure
 * is exact. Seed (same ISO week, same month):
 *   day 1: player A in-person, player B in-person, player C online
 *   day 2: player A online (A has both kinds in the week/month)
 */
final class OnlineAttendanceReportsTest extends TestCase
{
    private ?ReportsFixture $fixture = null;

    private Report $report;

    private int $parkId = 0;

    private int $kingdomId = 0;

    private string $day1 = '';

    private string $day2 = '';

    private string $monthEnd = '';

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = ReportsFixture::create();
        $this->report = new Report();
        $this->parkId = $this->fixture->firstParkId();
        $this->kingdomId = (int) $this->fixture->pdo()->query(
            'SELECT kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = ' . $this->parkId
        )->fetchColumn();

        // Find a March with no attendance for this park (a Tuesday and Thursday in one ISO week).
        $stmt = $this->fixture->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'attendance WHERE park_id = ? AND date BETWEEN ? AND ?'
        );
        for ($year = 2003; $year > 1980; $year--) {
            $stmt->execute([$this->parkId, "$year-02-01", "$year-04-30"]);
            if ((int) $stmt->fetchColumn() === 0) {
                $tuesday = strtotime("second tuesday of march $year");
                $this->day1 = date('Y-m-d', $tuesday);
                $this->day2 = date('Y-m-d', strtotime('+2 days', $tuesday));
                $this->monthEnd = "$year-03-31";
                break;
            }
        }
        if ($this->day1 === '') {
            $this->fail('No empty month found for the fixture park.');
        }

        $a = $this->fixture->createPlayer($this->parkId, 'oar-a');
        $b = $this->fixture->createPlayer($this->parkId, 'oar-b');
        $c = $this->fixture->createPlayer($this->parkId, 'oar-c');
        $this->fixture->insertAttendance($a['mundane_id'], $this->parkId, $this->kingdomId, $this->day1, 0, 0);
        $this->fixture->insertAttendance($b['mundane_id'], $this->parkId, $this->kingdomId, $this->day1, 0, 0);
        $this->fixture->insertAttendance($c['mundane_id'], $this->parkId, $this->kingdomId, $this->day1, 0, 1);
        $this->fixture->insertAttendance($a['mundane_id'], $this->parkId, $this->kingdomId, $this->day2, 0, 1);
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            $this->fixture->cleanup();
            $this->fixture = null;
        }
    }

    private function request(array $extra = []): array
    {
        return $extra + [
            'ParkId' => $this->parkId,
            'ReportFromDate' => $this->monthEnd,
            'Periods' => 1,
            'PerMonths' => 1,
            'PerWeeks' => 0,
        ];
    }

    /** @return array<string, array> rows keyed by Y-m-d date */
    private function rowsByDate(array $response): array
    {
        $this->assertTrue(isset($response['Dates']) && is_array($response['Dates']), 'AttendanceSummary returned no Dates');
        $rows = [];
        foreach ($response['Dates'] as $row) {
            $rows[date('Y-m-d', strtotime($row['Date']))] = $row;
        }

        return $rows;
    }

    public function testAttendanceSummaryByDateSplitsPlayers(): void
    {
        $rows = $this->rowsByDate($this->report->AttendanceSummary($this->request()));
        $this->assertCount(2, $rows);

        $d1 = $rows[$this->day1];
        // Total is the pre-change figure, unchanged.
        $this->assertSame(3, (int) $d1['DistinctPlayers']);
        $this->assertSame(3, (int) $d1['Attendees']);
        $this->assertSame(2, (int) $d1['DistinctPlayersInPerson']);
        $this->assertSame(1, (int) $d1['DistinctPlayersOnline']);
        $this->assertSame(
            (int) $d1['DistinctPlayers'],
            (int) $d1['DistinctPlayersInPerson'] + (int) $d1['DistinctPlayersOnline'],
            'Single-day row: in-person + online must equal total'
        );

        $d2 = $rows[$this->day2];
        $this->assertSame(1, (int) $d2['DistinctPlayers']);
        $this->assertSame(0, (int) $d2['DistinctPlayersInPerson']);
        $this->assertSame(1, (int) $d2['DistinctPlayersOnline']);
    }

    public function testAttendanceSummaryByWeekCountsBothKindsPlayerOnceInEachColumn(): void
    {
        $response = $this->report->AttendanceSummary($this->request(['ByPeriod' => 'week']));
        $this->assertCount(1, $response['Dates']);
        $week = $response['Dates'][0];

        $this->assertSame(3, (int) $week['DistinctPlayers']);
        $this->assertSame(2, (int) $week['DistinctPlayersInPerson']);
        $this->assertSame(2, (int) $week['DistinctPlayersOnline']);
        $this->assertLessThanOrEqual((int) $week['DistinctPlayers'], (int) $week['DistinctPlayersInPerson']);
        $this->assertLessThanOrEqual((int) $week['DistinctPlayers'], (int) $week['DistinctPlayersOnline']);
    }

    public function testDistinctPlayerStatsSplit(): void
    {
        $stats = $this->report->GetDistinctPlayerStats($this->request());

        $this->assertSame(3, $stats['TotalDistinctPlayers']);
        $this->assertSame(2, $stats['TotalDistinctPlayersInPerson']);
        $this->assertSame(2, $stats['TotalDistinctPlayersOnline']);
        $this->assertLessThanOrEqual($stats['TotalDistinctPlayers'], $stats['TotalDistinctPlayersInPerson']);
        $this->assertLessThanOrEqual($stats['TotalDistinctPlayers'], $stats['TotalDistinctPlayersOnline']);

        // One week of data: the weekly averages equal the week's distinct counts.
        $this->assertEqualsWithDelta(3.0, $stats['AvgDistinctPerWeek'], 0.001);
        $this->assertEqualsWithDelta(2.0, $stats['AvgDistinctPerWeekInPerson'], 0.001);
        $this->assertEqualsWithDelta(2.0, $stats['AvgDistinctPerWeekOnline'], 0.001);
    }

    public function testMonthlyChartDataSplit(): void
    {
        $data = $this->report->GetMonthlyChartData($this->request());

        $this->assertCount(1, $data);
        $month = $data[0];
        $this->assertSame(3, $month['Month']);
        $this->assertSame(3, $month['Count']);
        $this->assertSame($month['Count'], $month['Total']);
        $this->assertSame(2, $month['InPerson']);
        $this->assertSame(2, $month['Online']);
        $this->assertLessThanOrEqual($month['Total'], $month['InPerson']);
        $this->assertLessThanOrEqual($month['Total'], $month['Online']);
    }
}
