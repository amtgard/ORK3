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
 * The event-linked and kingdom-level tests add their own rows a week later.
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

    /** @var list<int> */
    private array $calendarDetailIds = [];

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

        // Find a March with no attendance anywhere in this park's kingdom (so kingdom
        // scoped rows are exact too), then a Tuesday and Thursday in one ISO week.
        $stmt = $this->fixture->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'attendance WHERE (park_id = ? OR kingdom_id = ?) AND date BETWEEN ? AND ?'
        );
        for ($year = 2003; $year > 1980; $year--) {
            $stmt->execute([$this->parkId, $this->kingdomId, "$year-02-01", "$year-04-30"]);
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
        if ($this->fixture !== null && $this->calendarDetailIds !== []) {
            $in = implode(',', array_map('intval', $this->calendarDetailIds));
            $this->fixture->pdo()->exec('DELETE FROM ' . DB_PREFIX . "event_calendardetail WHERE event_calendardetail_id IN ({$in})");
            $this->calendarDetailIds = [];
        }
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

    private function day3(): string
    {
        return date('Y-m-d', strtotime('+7 days', strtotime($this->day1)));
    }

    public function testEventLinkedRowSplitsPlayers(): void
    {
        $owner = $this->fixture->createPlayer($this->parkId, 'oar-owner');
        $eventId = $this->fixture->insertKingdomEvent($this->kingdomId, $owner['mundane_id'], 'oar-event');
        $day3 = $this->day3();
        $pdo = $this->fixture->pdo();
        $pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . "event_calendardetail
             (event_id, at_park_id, current, price, event_start, event_end, description, url, url_name, address,
              province, postal_code, city, country, map_url, map_url_name, google_geocode, location, latitude, longitude)
             VALUES (?, ?, 1, 0, ?, ?, '', '', '', '', '', '', '', '', '', '', '', '', 0, 0)"
        )->execute([$eventId, $this->parkId, "$day3 10:00:00", "$day3 18:00:00"]);
        $cdId = (int) $pdo->lastInsertId();
        $this->calendarDetailIds[] = $cdId;

        $d = $this->fixture->createPlayer($this->parkId, 'oar-d');
        $e = $this->fixture->createPlayer($this->parkId, 'oar-e');
        $ids = [
            $this->fixture->insertAttendance($d['mundane_id'], $this->parkId, $this->kingdomId, $day3, $eventId, 0),
            $this->fixture->insertAttendance($e['mundane_id'], $this->parkId, $this->kingdomId, $day3, $eventId, 1),
        ];
        $pdo->exec('UPDATE ' . DB_PREFIX . 'attendance SET event_calendardetail_id = ' . $cdId
            . ' WHERE attendance_id IN (' . implode(',', $ids) . ')');

        $rows = $this->rowsByDate($this->report->AttendanceSummary($this->request()));
        $this->assertArrayHasKey($day3, $rows);
        $row = $rows[$day3];
        $this->assertSame($cdId, (int) $row['EventCalendarDetailId']);
        $this->assertSame($eventId, (int) $row['EventId']);
        $this->assertSame(2, (int) $row['DistinctPlayers']);
        $this->assertSame(1, (int) $row['DistinctPlayersInPerson']);
        $this->assertSame(1, (int) $row['DistinctPlayersOnline']);
        $this->assertSame(
            (int) $row['DistinctPlayers'],
            (int) $row['DistinctPlayersInPerson'] + (int) $row['DistinctPlayersOnline']
        );
    }

    public function testKingdomLevelRowSplitsPlayers(): void
    {
        $day3 = $this->day3();
        $f = $this->fixture->createPlayer($this->parkId, 'oar-f');
        $g = $this->fixture->createPlayer($this->parkId, 'oar-g');
        // Kingdom-level attendance: no park, no event.
        $this->fixture->insertAttendance($f['mundane_id'], 0, $this->kingdomId, $day3, 0, 0);
        $this->fixture->insertAttendance($g['mundane_id'], 0, $this->kingdomId, $day3, 0, 1);

        $request = $this->request();
        unset($request['ParkId']);
        $request['KingdomId'] = $this->kingdomId;
        $rows = $this->rowsByDate($this->report->AttendanceSummary($request));

        $this->assertArrayHasKey($day3, $rows);
        $row = $rows[$day3];
        $this->assertEmpty($row['ParkId'], 'Kingdom-level row must have no park');
        $this->assertSame(2, (int) $row['DistinctPlayers']);
        $this->assertSame(1, (int) $row['DistinctPlayersInPerson']);
        $this->assertSame(1, (int) $row['DistinctPlayersOnline']);

        // The park-day rows from setUp are unchanged in kingdom scope.
        $this->assertSame(3, (int) $rows[$this->day1]['DistinctPlayers']);
        $this->assertSame(2, (int) $rows[$this->day1]['DistinctPlayersInPerson']);
        $this->assertSame(1, (int) $rows[$this->day1]['DistinctPlayersOnline']);
    }
}
