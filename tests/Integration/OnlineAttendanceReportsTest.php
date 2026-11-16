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

    private int $playerA = 0;

    private int $playerB = 0;

    private int $playerC = 0;

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
        $this->playerA = (int) $a['mundane_id'];
        $this->playerB = (int) $b['mundane_id'];
        $this->playerC = (int) $c['mundane_id'];
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

    /* ------------------------------------------------------------------ */
    /*  Task 12: In-Person / Online / All toggle on explorer-style reports */
    /*  setUp seed: A in-person d1, B in-person d1, C online d1, A online d2 */
    /* ------------------------------------------------------------------ */

    private function monthStart(): string
    {
        return substr($this->day1, 0, 8) . '01';
    }

    /**
     * Calls $fn with the key omitted and with all / in_person / online.
     * Asserts All equals the key-omitted (pre-change) result.
     *
     * @return array{all: mixed, in_person: mixed, online: mixed}
     */
    private function byType(callable $fn, array $request): array
    {
        $omitted = $fn($request);
        $out = [];
        foreach (['all', 'in_person', 'online'] as $type) {
            $out[$type] = $fn($request + ['AttendanceType' => $type]);
        }
        $this->assertEquals($omitted, $out['all'], 'All must equal the result with AttendanceType omitted');
        $this->assertEquals($omitted, $fn($request + ['AttendanceType' => 'bogus']), 'Unknown type falls back to All');

        return $out;
    }

    /** @return array<string, array> rows of $list whose $idKey equals $id */
    private function rowFor(array $list, string $idKey, int $id): ?array
    {
        foreach ($list as $row) {
            if ((int) $row[$idKey] === $id) {
                return $row;
            }
        }

        return null;
    }

    public function testParkAttendanceAllParksFiltersByType(): void
    {
        $request = [
            'KingdomId' => $this->kingdomId,
            'StartDate' => $this->monthStart(),
            'EndDate' => $this->monthEnd,
            'Period' => 'Monthly',
        ];
        $r = $this->byType(fn ($req) => $this->report->ParkAttendanceAllParks($req), $request);

        $park = [];
        foreach ($r as $type => $resp) {
            $row = $this->rowFor($resp['Attendance'], 'ParkId', $this->parkId);
            $this->assertNotNull($row, "Fixture park row missing ($type)");
            $park[$type] = $row;
        }
        $this->assertSame(4, (int) $park['all']['TotalSignins']);
        $this->assertSame(2, (int) $park['online']['TotalSignins'], 'online = the seeded online rows');
        $this->assertSame(
            (int) $park['all']['TotalSignins'],
            (int) $park['in_person']['TotalSignins'] + (int) $park['online']['TotalSignins']
        );
        $this->assertSame(3, (int) $park['all']['UniquePlayers']);
        $this->assertSame(2, (int) $park['in_person']['UniquePlayers']);
        $this->assertSame(2, (int) $park['online']['UniquePlayers']);
        // A has two sign-ins at the home park in All, one of each kind otherwise.
        $this->assertSame(1, (int) $park['all']['Members2Plus']);
        $this->assertSame(0, (int) $park['in_person']['Members2Plus']);
        $this->assertSame(0, (int) $park['online']['Members2Plus']);
        // Kingdom-wide summary query is filtered too.
        $this->assertSame(3, (int) $r['all']['Summary']['UniquePlayers']);
        $this->assertSame(2, (int) $r['in_person']['Summary']['UniquePlayers']);
        $this->assertSame(2, (int) $r['online']['Summary']['UniquePlayers']);
    }

    public function testParkAttendanceSingleParkFiltersByType(): void
    {
        $request = [
            'KingdomId' => $this->kingdomId,
            'ParkId' => $this->parkId,
            'StartDate' => $this->monthStart(),
            'EndDate' => $this->monthEnd,
            'Period' => 'Monthly',
            'MinimumSignIns' => 0,
        ];
        $r = $this->byType(fn ($req) => $this->report->ParkAttendanceSinglePark($req), $request);

        // The method's do/while emits a leading all-null row (pre-existing; the
        // controller skips rows without a persona), so drop rows with no player.
        $players = fn (array $resp): array => array_values(array_filter($resp['Attendance'], fn ($row) => !empty($row['MundaneId'])));
        $sum = [];
        $byPlayer = [];
        foreach ($r as $type => $resp) {
            $sum[$type] = array_sum(array_map(fn ($row) => (int) $row['SignInCount'], $players($resp)));
            foreach ($players($resp) as $row) {
                $byPlayer[$type][(int) $row['MundaneId']] = (int) $row['SignInCount'];
            }
        }
        $this->assertSame(4, $sum['all']);
        $this->assertSame(2, $sum['online'], 'online = the seeded online rows');
        $this->assertSame($sum['all'], $sum['in_person'] + $sum['online']);
        $this->assertSame([$this->playerA => 1, $this->playerB => 1], $byPlayer['in_person']);
        $this->assertSame([$this->playerA => 1, $this->playerC => 1], $byPlayer['online']);

        // Minimum Sign-Ins counts sign-ins of the selected type.
        $min = $this->byType(fn ($req) => $this->report->ParkAttendanceSinglePark($req), ['MinimumSignIns' => 2] + $request);
        $this->assertSame([$this->playerA], array_map(fn ($row) => (int) $row['MundaneId'], $players($min['all'])));
        $this->assertSame([], $players($min['in_person']));
        $this->assertSame([], $players($min['online']));
    }

    public function testNewPlayerAttendanceFiltersCountedAttendanceOnly(): void
    {
        $request = [
            'KingdomId' => $this->kingdomId,
            'ParkId' => $this->parkId,
            'StartDate' => $this->monthStart(),
            'EndDate' => $this->monthEnd,
            'IncludePlayerDetails' => 1,
        ];
        $r = $this->byType(fn ($req) => $this->report->GetNewPlayerAttendance($req), $request);

        $s = [];
        foreach ($r as $type => $resp) {
            $s[$type] = $this->rowFor($resp['Summary'], 'ParkId', $this->parkId);
            $this->assertNotNull($s[$type], "Fixture park summary missing ($type)");
        }
        $this->assertSame(3, $s['all']['NewPlayers']);
        $this->assertSame(4, $s['all']['NewPlayerVisits']);
        $this->assertSame(1, $s['all']['ReturningPlayers']);
        // New Players under a type = new players with a sign-in of that type.
        $this->assertSame(2, $s['in_person']['NewPlayers']);
        $this->assertSame(2, $s['online']['NewPlayers']);
        $this->assertSame(2, $s['online']['NewPlayerVisits'], 'online = the seeded online rows');
        $this->assertSame($s['all']['NewPlayerVisits'], $s['in_person']['NewPlayerVisits'] + $s['online']['NewPlayerVisits']);
        $this->assertSame(0, $s['in_person']['ReturningPlayers']);
        $this->assertSame(0, $s['online']['ReturningPlayers']);

        $details = [];
        foreach ($r as $type => $resp) {
            foreach ($resp['PlayerDetails'] as $d) {
                $details[$type][(int) $d['MundaneId']] = $d;
            }
            ksort($details[$type]);
        }
        $this->assertSame([$this->playerA, $this->playerB, $this->playerC], array_keys($details['all']));
        $this->assertSame([$this->playerA, $this->playerB], array_keys($details['in_person']));
        $this->assertSame([$this->playerA, $this->playerC], array_keys($details['online']));
        // Who is new (and when) comes from ALL attendance: A's first sign-in stays day 1
        // (in-person) even in the Online view; last sign-in and visits follow the type.
        $this->assertSame($this->day1, $details['online'][$this->playerA]['FirstSignInDate']);
        $this->assertSame($this->day2, $details['online'][$this->playerA]['LastSignInDate']);
        $this->assertSame($this->day1, $details['in_person'][$this->playerA]['LastSignInDate']);
        $this->assertSame($this->day2, $details['all'][$this->playerA]['LastSignInDate']);
        $this->assertSame(2, (int) $details['all'][$this->playerA]['VisitsInPeriod']);
        $this->assertSame(1, (int) $details['online'][$this->playerA]['VisitsInPeriod']);
    }

    public function testNewPlayerOnlineFirstIsNotNewAgainInPerson(): void
    {
        // C's first-ever sign-in (day 1) was online. A later in-person month must not
        // treat C as a new player in the In-Person view.
        $next = date('Y-m-d', strtotime('+1 month', strtotime($this->day1)));
        $this->fixture->insertAttendance($this->playerC, $this->parkId, $this->kingdomId, $next, 0, 0);
        $request = [
            'KingdomId' => $this->kingdomId,
            'ParkId' => $this->parkId,
            'StartDate' => substr($next, 0, 8) . '01',
            'EndDate' => date('Y-m-t', strtotime($next)),
        ];
        foreach (['all', 'in_person', 'online'] as $type) {
            $resp = $this->report->GetNewPlayerAttendance($request + ['AttendanceType' => $type]);
            $row = $this->rowFor($resp['Summary'], 'ParkId', $this->parkId);
            $this->assertSame(0, $row['NewPlayers'] ?? 0, "C is not new in $type");
        }
    }

    public function testNewPlayerAttendanceByKingdomFiltersByType(): void
    {
        $request = ['StartDate' => $this->monthStart(), 'EndDate' => $this->monthEnd];
        $r = $this->byType(fn ($req) => $this->report->GetNewPlayerAttendanceByKingdom($req), $request);

        $k = [];
        foreach ($r as $type => $resp) {
            $k[$type] = $this->rowFor($resp['Summary'], 'KingdomId', $this->kingdomId);
            $this->assertNotNull($k[$type], "Fixture kingdom row missing ($type)");
        }
        $this->assertSame(3, $k['all']['NewPlayers']);
        $this->assertSame(4, $k['all']['NewPlayerVisits']);
        $this->assertSame(2, $k['in_person']['NewPlayers']);
        $this->assertSame(2, $k['online']['NewPlayers']);
        $this->assertSame(2, $k['online']['NewPlayerVisits'], 'online = the seeded online rows');
        $this->assertSame($k['all']['NewPlayerVisits'], $k['in_person']['NewPlayerVisits'] + $k['online']['NewPlayerVisits']);
    }

    public function testTopParksByAttendanceFiltersByType(): void
    {
        $request = [
            'Limit' => 100000,
            'StartDate' => $this->monthStart(),
            'EndDate' => $this->monthEnd,
            'NativePopulace' => false,
            'Waivered' => false,
        ];
        $r = $this->byType(fn ($req) => $this->report->GetTopParksByAttendance($req), $request);

        $c = [];
        foreach ($r as $type => $resp) {
            $row = $this->rowFor($resp['TopParksSummary'], 'ParkId', $this->parkId);
            $this->assertNotNull($row, "Fixture park missing from top parks ($type)");
            $c[$type] = (int) $row['AttendanceCount'];
        }
        // Weekly unique players: A counts once in the week under All.
        $this->assertSame(3, $c['all']);
        $this->assertSame(2, $c['in_person']);
        $this->assertSame(2, $c['online']);

        $native = $this->byType(fn ($req) => $this->report->GetTopParksByAttendance($req), ['NativePopulace' => true] + $request);
        $this->assertSame(2, (int) $this->rowFor($native['online']['TopParksSummary'], 'ParkId', $this->parkId)['AttendanceCount']);
    }

    public function testActiveKingdomsSummaryFiltersByType(): void
    {
        $kingdomRow = function (array $resp): array {
            $row = $this->rowFor($resp['ActiveKingdomsSummaryList'], 'KingdomId', $this->kingdomId);
            $this->assertNotNull($row, 'Fixture kingdom missing from Active Kingdoms');

            return $row;
        };
        $before = [];
        foreach (['all', 'in_person', 'online'] as $type) {
            $before[$type] = $kingdomRow($this->report->GetActiveKingdomsSummary(['AttendanceType' => $type]));
        }

        // Recent rows (windows are relative to today): two in-person, one online.
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        foreach ([[0, 'oar-r1'], [0, 'oar-r2'], [1, 'oar-r3']] as [$online, $suffix]) {
            $p = $this->fixture->createPlayer($this->parkId, $suffix);
            $this->fixture->insertAttendance((int) $p['mundane_id'], $this->parkId, $this->kingdomId, $yesterday, 0, $online);
        }

        $omitted = $this->report->GetActiveKingdomsSummary();
        $this->assertEquals($omitted, $this->report->GetActiveKingdomsSummary(['AttendanceType' => 'all']));
        $after = [];
        foreach (['all', 'in_person', 'online'] as $type) {
            $after[$type] = $kingdomRow($this->report->GetActiveKingdomsSummary(['AttendanceType' => $type]));
        }
        $delta = fn (string $type, string $field): int => (int) $after[$type][$field] - (int) $before[$type][$field];
        foreach (['Attendance', 'Monthly'] as $field) {
            $this->assertSame(3, $delta('all', $field), "$field all");
            $this->assertSame(2, $delta('in_person', $field), "$field in_person");
            $this->assertSame(1, $delta('online', $field), "$field online (the seeded online row)");
        }
        $this->assertGreaterThanOrEqual(1, (int) $after['online']['Participation']);
    }

    public function testCacheKeyIncludesAttendanceType(): void
    {
        $cache = Ork3::$Lib->ghettocache;
        $original = $cache->memcache;
        $recorder = new class () {
            /** @var list<string> */
            public array $keys = [];

            public function get($k)
            {
                $this->keys[] = $k;

                return false;
            }

            public function set($k, $v, $e = 0)
            {
                return true;
            }

            public function delete($k)
            {
                return true;
            }
        };
        $cache->memcache = $recorder;
        try {
            $request = ['StartDate' => $this->monthStart(), 'EndDate' => $this->monthEnd];
            $calls = [
                fn ($req) => $this->report->GetNewPlayerAttendanceByKingdom($req),
                fn ($req) => $this->report->GetTopParksByAttendance($req + ['Limit' => 5]),
                fn ($req) => $this->report->ParkAttendanceAllParks($req + ['KingdomId' => $this->kingdomId, 'Period' => 'Monthly']),
                fn ($req) => $this->report->ParkAttendanceSinglePark($req + ['KingdomId' => $this->kingdomId, 'ParkId' => $this->parkId, 'Period' => 'Monthly']),
                fn ($req) => $this->report->GetNewPlayerAttendance($req + ['KingdomId' => $this->kingdomId, 'ParkId' => $this->parkId]),
                fn ($req) => $this->report->GetActiveKingdomsSummary(['AttendanceType' => $req['AttendanceType'] ?? null] + ['KingdomAverageWeeks' => 26]),
            ];
            foreach ($calls as $i => $call) {
                $keys = [];
                foreach ([null, 'all', 'in_person', 'online'] as $type) {
                    $recorder->keys = [];
                    $call($type === null ? $request : $request + ['AttendanceType' => $type]);
                    $this->assertNotEmpty($recorder->keys, "call $i did not consult the cache");
                    $keys[(string) $type] = $recorder->keys[0];
                }
                $this->assertSame($keys[''], $keys['all'], "call $i: All shares the pre-change cache key");
                $this->assertCount(3, array_unique([$keys['all'], $keys['in_person'], $keys['online']]), "call $i: each type has its own cache key");
            }
        } finally {
            $cache->memcache = $original;
        }
    }
}
