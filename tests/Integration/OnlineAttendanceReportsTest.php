<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/PopulationExplorerProbe.php';

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

    public function testNewPlayerTypedCountIsScopedToThePark(): void
    {
        // D is first seen in-person at park A (day 1) and signs in online at park B
        // (day 2), both in range. A filtered New Players count only players with a
        // sign-in of that type at the scope being reported, so D's online sign-in at B
        // never makes D an "Online" new player at A.
        $parkB = $this->fixture->secondParkIdInKingdom($this->kingdomId, $this->parkId);
        $this->assertGreaterThan($this->parkId, $parkB, 'Park B must sort after park A');
        $d = $this->fixture->createPlayer($this->parkId, 'oar-d');
        $playerD = (int) $d['mundane_id'];
        $this->fixture->insertAttendance($playerD, $this->parkId, $this->kingdomId, $this->day1, 0, 0);
        $this->fixture->insertAttendance($playerD, $parkB, $this->kingdomId, $this->day2, 0, 1);

        $base = [
            'KingdomId' => $this->kingdomId,
            'StartDate' => $this->monthStart(),
            'EndDate' => $this->monthEnd,
            'IncludePlayerDetails' => 1,
        ];
        $atA = $this->byType(fn ($req) => $this->report->GetNewPlayerAttendance($req), $base + ['ParkId' => $this->parkId]);
        $newAt = fn (array $resp, int $parkId): int => (int) ($this->rowFor($resp['Summary'], 'ParkId', $parkId)['NewPlayers'] ?? 0);
        $detailIds = fn (array $resp): array => array_map(fn ($row) => (int) $row['MundaneId'], $resp['PlayerDetails']);

        // Park A: D is a new player under All and In-Person; under Online only the
        // fixture's A and C (online sign-ins at A) count, and D is not in the list.
        $this->assertSame(4, $newAt($atA['all'], $this->parkId));
        $this->assertSame(3, $newAt($atA['in_person'], $this->parkId));
        $this->assertSame(2, $newAt($atA['online'], $this->parkId), 'D has no online sign-in at park A');
        $this->assertSame(2, (int) $this->rowFor($atA['online']['Summary'], 'ParkId', $this->parkId)['NewPlayerVisits']);
        $this->assertNotContains($playerD, $detailIds($atA['online']));
        $this->assertContains($playerD, $detailIds($atA['in_person']));

        // Park B: a new player is attributed to their first park, which for D is A
        // (MIN(park_id) of the in-range sign-ins; A sorts before B). So D is never a new
        // player at B, in any type, even though D's only online sign-in is there.
        $atB = $this->byType(fn ($req) => $this->report->GetNewPlayerAttendance($req), $base + ['ParkId' => $parkB]);
        foreach (['all', 'in_person', 'online'] as $type) {
            $this->assertSame(0, $newAt($atB[$type], $parkB), "D is not new at park B ($type)");
        }
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
        // Active Parks never follows the type: the ORK makes no park status determination
        // from online attendance, so it always counts all sign-ins.
        $this->assertGreaterThanOrEqual(1, (int) $after['all']['Participation']);
        $this->assertSame((int) $after['all']['Participation'], (int) $after['in_person']['Participation']);
        $this->assertSame((int) $after['all']['Participation'], (int) $after['online']['Participation']);
    }

    /* ------------------------------------------------------------------ */
    /*  Park / kingdom attendance averages (Task 13). Their windows are     */
    /*  relative to today, so these seed recent rows and compare deltas.    */
    /*  The test DB holds no online rows, so online figures are exact.      */
    /* ------------------------------------------------------------------ */

    /**
     * A Tuesday and Wednesday of one recent ISO week, both in one calendar month and
     * inside every averages window (6 months weekly, 12 months monthly).
     *
     * @return array{0: string, 1: string}
     */
    private function recentWeekPair(): array
    {
        for ($w = 1; $w <= 8; $w++) {
            $tue = strtotime("monday this week -{$w} week +1 day");
            $wed = strtotime('+1 day', $tue);
            if (date('Y-m', $tue) === date('Y-m', $wed)) {
                return [date('Y-m-d', $tue), date('Y-m-d', $wed)];
            }
        }
        $this->fail('No recent same-month week found.');
    }

    /**
     * Seeds, in one recent week and month: P1 in-person (Tue) and online (Wed),
     * P2 in-person, P3 online. Distinct players: all 3, in-person 2 (P1, P2),
     * online 2 (P1, P3) - P1 counts once under All but once in each type.
     */
    private function seedRecentMixedWeek(): void
    {
        [$tue, $wed] = $this->recentWeekPair();
        $p1 = (int) $this->fixture->createPlayer($this->parkId, 'oar-avg1')['mundane_id'];
        $p2 = (int) $this->fixture->createPlayer($this->parkId, 'oar-avg2')['mundane_id'];
        $p3 = (int) $this->fixture->createPlayer($this->parkId, 'oar-avg3')['mundane_id'];
        $this->fixture->insertAttendance($p1, $this->parkId, $this->kingdomId, $tue, 0, 0);
        $this->fixture->insertAttendance($p1, $this->parkId, $this->kingdomId, $wed, 0, 1);
        $this->fixture->insertAttendance($p2, $this->parkId, $this->kingdomId, $tue, 0, 0);
        $this->fixture->insertAttendance($p3, $this->parkId, $this->kingdomId, $tue, 0, 1);
    }

    public function testKingdomParkAveragesFilterByType(): void
    {
        $weeklyReq = ['KingdomId' => $this->kingdomId, 'AverageMonths' => 6];
        $monthlyReq = ['KingdomId' => $this->kingdomId];
        $weekly = fn (array $resp): int => (int) ($this->rowFor($resp['KingdomParkAveragesSummary'], 'ParkId', $this->parkId)['AttendanceCount'] ?? -1);
        $monthly = fn (array $resp): float => (float) ($this->rowFor($resp['KingdomParkMonthlySummary'], 'ParkId', $this->parkId)['MonthlyAvg'] ?? 0);
        $before = [];
        foreach (['all', 'in_person', 'online'] as $type) {
            $before[$type] = $weekly($this->report->GetKingdomParkAverages($weeklyReq + ['AttendanceType' => $type]));
        }

        $this->seedRecentMixedWeek();

        $w = $this->byType(fn ($req) => $this->report->GetKingdomParkAverages($req), $weeklyReq);
        $m = $this->byType(fn ($req) => $this->report->GetKingdomParkMonthlyAverages($req), $monthlyReq);
        $delta = [];
        foreach (['all', 'in_person', 'online'] as $type) {
            $delta[$type] = $weekly($w[$type]) - $before[$type];
        }
        // Player-weeks: P1 is one player-week under All, and one in each type.
        $this->assertSame(3, $delta['all']);
        $this->assertSame(2, $delta['in_person']);
        $this->assertSame(2, $delta['online'], 'online = the seeded online players (P1, P3)');
        // Not additive: in-person + online exceeds All by the players with both kinds that week.
        $this->assertSame($delta['all'] + 1, $delta['in_person'] + $delta['online']);
        $this->assertSame(0, $before['online']);
        $this->assertSame(2, $weekly($w['online']));

        // Monthly: AVG of distinct players per month. The only online month is the seeded one.
        $this->assertSame(2.0, $monthly($m['online']));
        $this->assertGreaterThan(0.0, $monthly($m['in_person']));
        $this->assertGreaterThanOrEqual($monthly($m['in_person']), $monthly($m['all']));
    }

    public function testKingdomExtendedParkAveragesFilterByType(): void
    {
        $req = ['KingdomId' => $this->kingdomId, 'IsAdmin' => true];
        $before = [];
        foreach (['all', 'in_person', 'online'] as $type) {
            $before[$type] = $this->report->GetKingdomExtendedParkAverages($req + ['AttendanceType' => $type]);
        }

        $this->seedRecentMixedWeek();

        $r = $this->byType(fn ($q) => $this->report->GetKingdomExtendedParkAverages($q), $req);
        $park = fn (array $resp, string $f): float => (float) ($resp[$this->parkId][$f] ?? 0);
        foreach (['att', 'tp', 'tm'] as $f) {
            $d = [];
            foreach (['all', 'in_person', 'online'] as $type) {
                $d[$type] = $park($r[$type], $f) - $park($before[$type], $f);
            }
            $this->assertSame([3.0, 2.0, 2.0], [$d['all'], $d['in_person'], $d['online']], "park $f deltas all / in_person / online");
        }
        $k = fn (array $resp, string $f): float => (float) ($resp['_kingdom'][$f] ?? 0);
        $this->assertSame(3.0, $k($r['all'], 'att') - $k($before['all'], 'att'));
        $this->assertSame(2.0, $k($r['in_person'], 'att') - $k($before['in_person'], 'att'));
        $this->assertSame(2.0, $k($r['online'], 'att'), 'kingdom online player-weeks = the seeded online players');
        $this->assertSame(2.0, $k($r['online'], 'mo'), 'kingdom online monthly avg = one month of 2 players');
        $this->assertSame(2.0, $park($r['online'], 'mo'));
        $this->assertSame($k($r['all'], 'wk_count'), $k($r['online'], 'wk_count'), 'same week denominator for every type');
        // Previous-period trend figures are type-filtered too: no online rows there.
        $this->assertSame(0.0, $park($r['online'], 'prev_att'));
    }

    public function testParkAttendanceAveragesFilterByType(): void
    {
        $profile = new ParkProfile();
        $before = [];
        foreach (['all', 'in_person', 'online'] as $type) {
            $before[$type] = $profile->GetParkAttendanceAverages($this->parkId, $type);
        }
        $this->assertEquals($profile->GetParkAttendanceAverages($this->parkId), $before['all'], 'All equals the default');

        $this->seedRecentMixedWeek();

        $omitted = $profile->GetParkAttendanceAverages($this->parkId);
        $after = [];
        foreach (['all', 'in_person', 'online', 'bogus'] as $type) {
            $after[$type] = $profile->GetParkAttendanceAverages($this->parkId, $type);
        }
        $this->assertEquals($omitted, $after['all']);
        $this->assertEquals($omitted, $after['bogus'], 'Unknown type falls back to All');

        // WeeklyAvg = player-weeks / weeks in 6 months (one denominator for every type),
        // so the deltas keep the 3 / 2 / 2 player-week relationship.
        $wkCount = max(1, (int) ceil((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime('-6 month')))) / (7 * 86400)));
        $this->assertSame(0.0, $before['online']['WeeklyAvg']);
        $this->assertSame(round(2 / $wkCount, 2), $after['online']['WeeklyAvg']);
        $this->assertEqualsWithDelta(3 / $wkCount, $after['all']['WeeklyAvg'] - $before['all']['WeeklyAvg'], 0.011);
        $this->assertEqualsWithDelta(2 / $wkCount, $after['in_person']['WeeklyAvg'] - $before['in_person']['WeeklyAvg'], 0.011);
        // MonthlyAvg = AVG(distinct players per active month): the only online month is the seeded one.
        $this->assertSame(2.0, $after['online']['MonthlyAvg']);
        $this->assertGreaterThan(0.0, $after['in_person']['MonthlyAvg']);
    }

    public function testParkAveragesIgnoreCountOnlineAttendanceSetting(): void
    {
        $this->seedRecentMixedWeek();
        $profile = new ParkProfile();
        $snapshot = function () use ($profile): array {
            $out = [];
            foreach (['all', 'in_person', 'online'] as $type) {
                $out[$type] = [
                    $profile->GetParkAttendanceAverages($this->parkId, $type),
                    $this->report->GetKingdomExtendedParkAverages(['KingdomId' => $this->kingdomId, 'IsAdmin' => false, 'AttendanceType' => $type]),
                ];
            }

            return $out;
        };
        $this->fixture->setKingdomConfig($this->kingdomId, 'CountOnlineAttendance', '"1"');
        $on = $snapshot();
        $this->fixture->setKingdomConfig($this->kingdomId, 'CountOnlineAttendance', '"0"');
        $this->assertEquals($on, $snapshot(), 'Park averages are informational: the kingdom setting never applies');
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
                fn ($req) => $this->report->GetKingdomParkAverages(['KingdomId' => $this->kingdomId, 'AverageMonths' => 6] + array_intersect_key($req, ['AttendanceType' => 1])),
                fn ($req) => $this->report->GetKingdomParkMonthlyAverages(['KingdomId' => $this->kingdomId] + array_intersect_key($req, ['AttendanceType' => 1])),
                fn ($req) => $this->report->GetKingdomExtendedParkAverages(['KingdomId' => $this->kingdomId, 'IsAdmin' => false] + array_intersect_key($req, ['AttendanceType' => 1])),
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

    /* ------------------------------------------------------------------ */
    /*  State of Amtgard (Task 14)                                         */
    /* ------------------------------------------------------------------ */

    /** Runs $fn with GhettoCache always missing, so every call really queries. */
    private function uncached(callable $fn)
    {
        $cache = Ork3::$Lib->ghettocache;
        $original = $cache->memcache;
        $cache->memcache = new class () {
            public function get($k)
            {
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
        try {
            return $fn();
        } finally {
            $cache->memcache = $original;
        }
    }

    /**
     * Calls a StateOfAmtgard section with the type omitted and with all / in_person /
     * online (cache bypassed). Asserts All and an unknown type equal the omitted call.
     *
     * @return array{all: mixed, in_person: mixed, online: mixed}
     */
    private function sorByType(callable $fn): array
    {
        return $this->uncached(function () use ($fn) {
            $omitted = $fn(null);
            $out = [];
            foreach (['all', 'in_person', 'online'] as $type) {
                $out[$type] = $fn($type);
            }
            $this->assertEquals($omitted, $out['all'], 'All must equal the result with the type omitted');
            $this->assertEquals($omitted, $fn('bogus'), 'Unknown type falls back to All');

            return $out;
        });
    }

    /** @return callable(?string): array a StateOfAmtgard section over the fixture month and kingdom */
    private function sorSection(string $method): callable
    {
        $sor = new StateOfAmtgard();
        $start = $this->monthStart();
        $end = $this->monthEnd;
        $ids = [$this->kingdomId];

        return fn (?string $type) => $type === null
            ? $sor->$method($start, $end, $ids)
            : $sor->$method($start, $end, $ids, $type);
    }

    public function testStateOfAmtgardKingdomSignInsFilterByType(): void
    {
        $r = $this->sorByType($this->sorSection('getKingdomSignIns'));
        $count = function (array $rows): int {
            $row = $this->rowFor($rows, 'kingdom_id', $this->kingdomId);

            return $row === null ? 0 : (int) $row['sign_in_count'];
        };
        // Seed: A, B in person + C online on day 1, A online on day 2; the month is otherwise empty.
        $this->assertSame(4, $count($r['all']));
        $this->assertSame(2, $count($r['in_person']));
        $this->assertSame(2, $count($r['online']), 'Online count equals the seeded online rows');
        $this->assertSame($count($r['all']), $count($r['in_person']) + $count($r['online']));
        // The prior period is filtered too (empty here), so YoY compares like with like.
        $this->assertSame(0, (int) $this->rowFor($r['online'], 'kingdom_id', $this->kingdomId)['prior_period_count']);
    }

    public function testStateOfAmtgardPlayerStatsFilterByType(): void
    {
        $r = $this->sorByType($this->sorSection('getPlayerStats'));
        $this->assertSame(4, $r['all']['total_sign_ins']);
        $this->assertSame(3, $r['all']['total_players']);
        $this->assertSame(2, $r['in_person']['total_sign_ins']);
        $this->assertSame(2, $r['in_person']['total_players'], 'A and B');
        $this->assertSame(2, $r['online']['total_sign_ins'], 'Online count equals the seeded online rows');
        $this->assertSame(2, $r['online']['total_players'], 'A and C');
        // Every sign-in is exactly one type, so sign-ins add up (in range and over ten years);
        // a player with both kinds is in both populations, so players do not.
        $this->assertSame($r['all']['total_sign_ins'], $r['in_person']['total_sign_ins'] + $r['online']['total_sign_ins']);
        $this->assertSame(
            $r['all']['ten_year']['total_sign_ins'],
            $r['in_person']['ten_year']['total_sign_ins'] + $r['online']['ten_year']['total_sign_ins']
        );
        $this->assertGreaterThanOrEqual($r['all']['ten_year']['total_players'], $r['in_person']['ten_year']['total_players'] + $r['online']['ten_year']['total_players']);
        $year = (int) substr($this->day1, 0, 4);
        $trend = fn (array $res): int => (int) ($this->rowFor(array_map(fn ($t) => $t + ['id' => $t['year']], $res['trend_by_year']), 'id', $year)['sign_ins'] ?? 0);
        $this->assertSame($trend($r['all']), $trend($r['in_person']) + $trend($r['online']));
    }

    public function testStateOfAmtgardNormalPlayerStatusUsesAllAttendance(): void
    {
        // Player D: 2 in-person + 2 online sign-ins (1 credit each, plus credits to reach 12).
        $d = $this->fixture->createPlayer($this->parkId, 'oar-d');
        $dates = [$this->monthStart(), date('Y-m-d', strtotime($this->monthStart() . ' +1 day')), date('Y-m-d', strtotime($this->monthStart() . ' +2 days')), date('Y-m-d', strtotime($this->monthStart() . ' +3 days'))];
        foreach ($dates as $i => $date) {
            $id = $this->fixture->insertAttendance((int) $d['mundane_id'], $this->parkId, $this->kingdomId, $date, 0, $i % 2);
            $this->fixture->pdo()->exec('UPDATE ' . DB_PREFIX . 'attendance SET credits = 3 WHERE attendance_id = ' . (int) $id);
        }
        $r = $this->sorByType($this->sorSection('getPlayerStats'));
        // D has 4 sign-ins / 12 credits in all, but only 2 of each type: still a normal player in each view.
        $this->assertSame(1, $r['all']['normal_players']['count']);
        $this->assertSame(1, $r['in_person']['normal_players']['count']);
        $this->assertSame(1, $r['online']['normal_players']['count']);
        $this->assertSame(2, $r['online']['normal_players']['total_sign_ins'], 'Counted sign-ins are the online ones');
    }

    public function testStateOfAmtgardClassesLongevityAndParksFilterByType(): void
    {
        $sum = fn (array $rows, string $k): int => array_sum(array_map(fn ($row) => (int) $row[$k], $rows));

        $classes = $this->sorByType($this->sorSection('getClassSignIns'));
        $this->assertSame(4, $sum($classes['all'], 'sign_in_count'));
        $this->assertSame(2, $sum($classes['in_person'], 'sign_in_count'));
        $this->assertSame(2, $sum($classes['online'], 'sign_in_count'));

        $longevity = $this->sorByType($this->sorSection('getPlayerLongevity'));
        $this->assertSame(3, $sum($longevity['all'], 'count'));
        $this->assertSame(2, $sum($longevity['in_person'], 'count'), 'A and B');
        $this->assertSame(2, $sum($longevity['online'], 'count'), 'A and C');

        $parks = $this->sorByType($this->sorSection('getParksAnalysis'));
        $top = fn (array $res): int => (int) ($this->rowFor($res['top_parks'], 'park_id', $this->parkId)['sign_in_count'] ?? 0);
        $this->assertSame(4, $top($parks['all']));
        $this->assertSame(2, $top($parks['in_person']));
        $this->assertSame(2, $top($parks['online']));
        // Park lifecycle (counts, new / lost, at-risk trend) is not filtered.
        foreach (['total_active', 'new_parks_count', 'lost_parks_count', 'downward_trend_parks'] as $k) {
            $this->assertEquals($parks['all'][$k], $parks['online'][$k], "$k uses all attendance");
        }
    }

    public function testStateOfAmtgardCohortsFilterByType(): void
    {
        $r = $this->sorByType($this->sorSection('getPlayerCohorts'));
        // A, B, C all have their first-ever sign-in in the month.
        $this->assertSame(3, $r['all']['new_players']);
        $this->assertSame(2, $r['in_person']['new_players'], 'A and B');
        $this->assertSame(2, $r['online']['new_players'], 'A and C');
        // One-time visitors: B (in person) and C (online); A has two sign-ins.
        $this->assertSame(2, $r['all']['one_time_visitors']);
        $this->assertSame(1, $r['in_person']['one_time_visitors']);
        $this->assertSame(1, $r['online']['one_time_visitors']);
        $this->assertSame(0, $r['online']['returning_players']);
    }

    public function testStateOfAmtgardRetentionFilterByType(): void
    {
        $sor = new StateOfAmtgard();
        $ids = [$this->kingdomId];
        $r = $this->sorByType(fn (?string $type) => $type === null ? $sor->getNewPlayerRetention($ids) : $sor->getNewPlayerRetention($ids, $type));
        // Fixture players are in the mature cohort (first sign-in years ago) of this kingdom.
        $this->assertGreaterThanOrEqual(3, $r['all']['headline']['cohort']);
        $this->assertGreaterThanOrEqual(1, $r['online']['headline']['cohort']);
        $this->assertLessThanOrEqual($r['all']['headline']['cohort'], $r['in_person']['headline']['cohort']);
        $this->assertLessThanOrEqual($r['all']['headline']['cohort'], $r['online']['headline']['cohort']);
        $this->assertGreaterThanOrEqual($r['all']['headline']['cohort'], $r['in_person']['headline']['cohort'] + $r['online']['headline']['cohort']);
    }

    public function testStateOfAmtgardDispatchPassesTypeAndCacheKeys(): void
    {
        $sor = new StateOfAmtgard();
        $ids = [$this->kingdomId];
        $count = function (?array $payload): int {
            $row = $this->rowFor($payload['kingdoms'], 'kingdom_id', $this->kingdomId);

            return $row === null ? 0 : (int) $row['sign_in_count'];
        };
        $this->uncached(function () use ($sor, $ids, $count) {
            $this->assertSame(2, $count($sor->DispatchChartSection('kingdoms', $this->monthStart(), $this->monthEnd, $ids, 'online')));
            $this->assertSame(4, $count($sor->DispatchChartSection('kingdoms', $this->monthStart(), $this->monthEnd, $ids)));
            $this->assertEquals(
                $sor->DispatchChartSection('awards', $this->monthStart(), $this->monthEnd, $ids),
                $sor->DispatchChartSection('awards', $this->monthStart(), $this->monthEnd, $ids, 'online'),
                'Award grants are never filtered'
            );
        });

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
            foreach (['kingdoms', 'classes', 'parks', 'players', 'cohorts', 'longevity', 'retention'] as $section) {
                $keys = [];
                foreach ([null, 'all', 'in_person', 'online'] as $type) {
                    $recorder->keys = [];
                    $type === null
                        ? $sor->DispatchChartSection($section, $this->monthStart(), $this->monthEnd, $ids)
                        : $sor->DispatchChartSection($section, $this->monthStart(), $this->monthEnd, $ids, $type);
                    $this->assertNotEmpty($recorder->keys, "$section did not consult the cache");
                    $keys[(string) $type] = $recorder->keys[0];
                }
                $this->assertSame($keys[''], $keys['all'], "$section: All shares the pre-change cache key");
                $this->assertCount(3, array_unique([$keys['all'], $keys['in_person'], $keys['online']]), "$section: each type has its own cache key");
            }
            // The pre-change key string is unchanged for All.
            $recorder->keys = [];
            $sor->getKingdomSignIns($this->monthStart(), $this->monthEnd, $ids);
            $this->assertStringEndsWith('.' . md5($this->monthStart() . '|' . $this->monthEnd . '|' . $this->kingdomId), $recorder->keys[0]);
        } finally {
            $cache->memcache = $original;
        }
    }

    /* ── Population Explorer: page-level Sign-ins counted ─────────────── */

    /**
     * Runs a Population Explorer query on the fixture park as a global admin, with
     * $type as AttendanceType (null = key omitted). Returns [result, statements].
     */
    private function peRun(array $tree, array $cols, ?string $type, string $token): array
    {
        $req = ['Token' => $token, 'ScopeType' => 'Park', 'ScopeId' => $this->parkId, 'Tree' => $tree, 'Columns' => $cols];
        if ($type !== null) {
            $req['AttendanceType'] = $type;
        }
        unset($_SESSION['is_authorized_mundane_id']);
        $pe = new PopulationExplorerProbe();
        $r = $pe->Run($req);
        $this->assertSame(0, $r['Status']['Status'], json_encode($r['Status']));

        // GET_LOCK / RELEASE_LOCK names carry no type; CONNECTION_ID is the probe's own.
        return [$r, array_values(array_filter($pe->statements, static fn (string $q): bool => !preg_match('/_LOCK\(|CONNECTION_ID/', $q)))];
    }

    /** @return array<int, array> fixture players' rows keyed by mundane_id */
    private function peFixtureRows(array $result): array
    {
        $out = [];
        foreach ($result['Rows'] as $row) {
            if (in_array($row['MundaneId'], [$this->playerA, $this->playerB, $this->playerC], true)) {
                $out[$row['MundaneId']] = $row;
            }
        }
        ksort($out);

        return $out;
    }

    private function peAdminToken(): string
    {
        $admin = $this->fixture->createPlayer($this->parkId, 'oar-pe-admin');
        $this->fixture->insertGlobalAdmin($admin['mundane_id']);

        return $admin['token'];
    }

    /** Last-sign-in window = the fixture's empty month: only the seeded players can match it. */
    private function peWindowTree(): array
    {
        return ['op' => 'AND', 'children' => [['c' => 'last_signin', 'o' => 'between', 'v' => [$this->day1, $this->monthEnd]]]];
    }

    public function testPopulationExplorerAllMatchesOmittedSqlAndResult(): void
    {
        $token = $this->peAdminToken();
        $cols = ['persona', 'last_signin', 'player_since', 'signins_6m', 'total_signins', 'last_signin_park', 'last_class'];
        $tree = ['op' => 'OR', 'children' => [
            ['c' => 'total_signins', 'o' => 'gte', 'v' => 1],
            ['c' => 'signins_last_n_months', 'o' => 'gte', 'v' => 1, 'p' => 6],
        ]];
        [$omitted, $omittedSql] = $this->peRun($tree, $cols, null, $token);
        $this->assertNotEmpty($omittedSql);
        foreach ($omittedSql as $q) {
            $this->assertStringNotContainsString('online', $q, 'All adds no online predicate');
        }
        unset($omitted['ElapsedMs']);
        foreach (['all', 'Online', 'nonsense'] as $same) {
            [$r, $sql] = $this->peRun($tree, $cols, $same, $token);
            unset($r['ElapsedMs']);
            $this->assertSame($omittedSql, $sql, "$same: the exact pre-change SQL");
            $this->assertSame($omitted, $r, "$same: the same result");
        }
        [, $onlineSql] = $this->peRun($tree, $cols, 'online', $token);
        $this->assertStringContainsString('AND a.online = 1', implode("\n", $onlineSql));
    }

    public function testPopulationExplorerSigninColumnsFollowType(): void
    {
        $token = $this->peAdminToken();
        $recent = date('Y-m-d', strtotime('-10 days'));
        $this->fixture->insertAttendance($this->playerB, $this->parkId, $this->kingdomId, $recent, 0, 1); // B: recent online
        $tree = ['op' => 'AND', 'children' => [['c' => 'total_signins', 'o' => 'gte', 'v' => 1]]];
        $cols = ['persona', 'last_signin', 'player_since', 'signins_6m', 'total_signins', 'last_signin_park'];
        $parkName = (string) $this->fixture->pdo()->query('SELECT name FROM ' . DB_PREFIX . 'park WHERE park_id = ' . $this->parkId)->fetchColumn();

        $view = function (?string $type) use ($tree, $cols, $token): array {
            $out = [];
            foreach ($this->peFixtureRows($this->peRun($tree, $cols, $type, $token)[0]) as $id => $row) {
                $out[$id] = [$row['total_signins'], $row['last_signin'], $row['player_since'], $row['signins_6m'], $row['last_signin_park']];
            }

            return $out;
        };
        $a = $this->playerA;
        $b = $this->playerB;
        $c = $this->playerC;
        $all = [
            $a => [2, $this->day2, $this->day1, 0, $parkName],
            $b => [2, $recent, $this->day1, 1, $parkName],
            $c => [1, $this->day1, $this->day1, 0, $parkName],
        ];
        ksort($all);
        $this->assertSame($all, $view(null));
        $this->assertSame($all, $view('all'));
        $inPerson = [$a => [1, $this->day1, $this->day1, 0, $parkName], $b => [1, $this->day1, $this->day1, 0, $parkName]];
        ksort($inPerson);
        $this->assertSame($inPerson, $view('in_person'), 'C has no in-person sign-in, so Total sign-ins >= 1 drops C');
        $online = [
            // Player since (third) is a first-seen anchor: day 1 for every type.
            $a => [1, $this->day2, $this->day1, 0, $parkName],
            $b => [1, $recent, $this->day1, 1, $parkName],
            $c => [1, $this->day1, $this->day1, 0, $parkName],
        ];
        ksort($online);
        $this->assertSame($online, $view('online'));
        // Per player, in-person + online sign-ins = all.
        foreach ($all as $id => $row) {
            $this->assertSame($row[0], ($inPerson[$id][0] ?? 0) + $online[$id][0]);
        }
    }

    public function testPopulationExplorerSigninCriteriaFollowType(): void
    {
        $token = $this->peAdminToken();
        $recent = date('Y-m-d', strtotime('-10 days'));
        $this->fixture->insertAttendance($this->playerB, $this->parkId, $this->kingdomId, $recent, 0, 1);
        $ids = function (array $tree, ?string $type) use ($token): array {
            return array_keys($this->peFixtureRows($this->peRun($tree, ['persona'], $type, $token)[0]));
        };
        $sorted = static function (array $x): array {
            sort($x);

            return $x;
        };
        // Last sign-in inside the empty month: only seeded players can match, so Total is exact.
        $window = $this->peWindowTree();
        $totals = [];
        foreach ([null, 'all', 'in_person', 'online'] as $type) {
            $totals[(string) $type] = $this->peRun($window, ['persona'], $type, $token)[0]['Total'];
        }
        $this->assertSame($totals[''], $totals['all']);
        $this->assertSame(2, $totals['all'], 'A (day 2) and C (day 1); B last signed in recently');
        $this->assertSame(2, $totals['in_person'], 'A and B last signed in in person on day 1');
        $this->assertSame(2, $totals['online'], 'A online on day 2, C online on day 1');
        $this->assertSame($sorted([$this->playerA, $this->playerC]), $ids($window, 'all'));
        $this->assertSame($sorted([$this->playerA, $this->playerB]), $ids($window, 'in_person'));
        $this->assertSame($sorted([$this->playerA, $this->playerC]), $ids($window, 'online'));

        $classId = (int) $this->fixture->pdo()->query('SELECT class_id FROM ' . DB_PREFIX . 'class ORDER BY class_id ASC LIMIT 1')->fetchColumn();
        $recentTree = ['op' => 'AND', 'children' => [['c' => 'signins_last_n_months', 'o' => 'gte', 'v' => 1, 'p' => 6]]];
        $classTree = ['op' => 'AND', 'children' => [['c' => 'classes_last_n_months', 'o' => 'in', 'v' => [$classId], 'p' => 6]]];
        foreach ([$recentTree, $classTree] as $tree) {
            $this->assertSame([$this->playerB], $ids($tree, null));
            $this->assertSame([$this->playerB], $ids($tree, 'online'));
            $this->assertSame([], $ids($tree, 'in_person'));
        }
        // Total sign-ins = 0: never signed in *of that type*.
        $none = ['op' => 'AND', 'children' => [['c' => 'total_signins', 'o' => 'eq', 'v' => 0]]];
        $this->assertSame([], $ids($none, 'all'));
        $this->assertSame([$this->playerC], $ids($none, 'in_person'));
        $this->assertSame([], $ids($none, 'online'));
        // Player since ignores the type (first seen = all attendance): A, B and C all date from day 1.
        $since = ['op' => 'AND', 'children' => [['c' => 'player_since', 'o' => 'eq', 'v' => $this->day1]]];
        foreach ([null, 'in_person', 'online'] as $type) {
            $this->assertSame($sorted([$this->playerA, $this->playerB, $this->playerC]), $ids($since, $type), (string) $type);
        }
    }

    public function testPopulationExplorerExportFollowsType(): void
    {
        $token = $this->peAdminToken();
        $req = ['Token' => $token, 'ScopeType' => 'Park', 'ScopeId' => $this->parkId, 'Tree' => $this->peWindowTree(), 'Columns' => ['persona', 'last_signin']];
        $sheet = function (array $r): string {
            $this->assertSame(0, $r['Status']['Status']);
            $out = shell_exec('unzip -p ' . escapeshellarg($r['Path']) . ' xl/worksheets/sheet1.xml');
            @unlink($r['Path']);
            $this->assertIsString($out, 'unzip reads the workbook');

            return (string) $out;
        };
        unset($_SESSION['is_authorized_mundane_id']);
        $omitted = (new PopulationExplorer())->BuildExport($req);
        $this->assertMatchesRegularExpression('/^population-explorer-\d{4}-\d{2}-\d{2}\.xlsx$/', $omitted['Filename']);
        $omittedSheet = $sheet($omitted);
        unset($_SESSION['is_authorized_mundane_id']);
        $all = (new PopulationExplorer())->BuildExport($req + ['AttendanceType' => 'all']);
        $this->assertSame($omitted['Filename'], $all['Filename']);
        $this->assertSame($omittedSheet, $sheet($all), 'All: byte-identical sheet');
        $this->assertStringNotContainsString('sign-ins only', $omittedSheet);

        unset($_SESSION['is_authorized_mundane_id']);
        $inPerson = (new PopulationExplorer())->BuildExport($req + ['AttendanceType' => 'in_person']);
        $this->assertMatchesRegularExpression('/^population-explorer-in-person-\d{4}-\d{2}-\d{2}\.xlsx$/', $inPerson['Filename']);
        $x = $sheet($inPerson);
        $this->assertStringContainsString('Oar-a', $x);
        $this->assertStringContainsString('Oar-b', $x);
        $this->assertStringNotContainsString('Oar-c', $x);
        $this->assertStringContainsString('use in-person sign-ins only.', $x);
        unset($_SESSION['is_authorized_mundane_id']);
        $online = (new PopulationExplorer())->BuildExport($req + ['AttendanceType' => 'online']);
        $x = $sheet($online);
        $this->assertStringContainsString('Oar-a', $x);
        $this->assertStringContainsString('Oar-c', $x);
        $this->assertStringNotContainsString('Oar-b', $x);
        $this->assertStringContainsString('>' . $this->day2 . '<', $x, "A's last online sign-in");
        $this->assertStringContainsString('use online sign-ins only.', $x);
    }

    public function testParkFieldCountTodayIsInPersonOnly(): void
    {
        // The count uses the DB's CURDATE(), which can differ from PHP's date().
        $today = (string) $this->fixture->pdo()->query('SELECT CURDATE()')->fetchColumn();
        $before = (int) $this->report->GetParkFieldCountToday(['ParkId' => $this->parkId])['Count'];
        $p = $this->fixture->createPlayer($this->parkId, 'oar-field');
        $this->fixture->insertAttendance($p['mundane_id'], $this->parkId, $this->kingdomId, $today, 0, 1);
        $this->assertSame($before, (int) $this->report->GetParkFieldCountToday(['ParkId' => $this->parkId])['Count'], 'online row adds nothing');
        $q = $this->fixture->createPlayer($this->parkId, 'oar-field2');
        $this->fixture->insertAttendance($q['mundane_id'], $this->parkId, $this->kingdomId, $today, 0, 0);
        $this->assertSame($before + 1, (int) $this->report->GetParkFieldCountToday(['ParkId' => $this->parkId])['Count'], 'in-person row adds one');
    }

    public function testFeatureUtilizationOnlineSignInsMatchesSeededRows(): void
    {
        $find = function (): int {
            foreach ($this->report->ReleaseFeatureUtilization()['releases'] as $release) {
                foreach ($release['features'] as $feature) {
                    foreach ($feature['kpis'] as $kpi) {
                        if ($kpi['label'] === 'Online check-ins recorded (30d)') {
                            return (int) $kpi['value'];
                        }
                    }
                }
            }
            $this->fail('Online check-ins KPI missing.');
        };
        $before = $find();
        $p = $this->fixture->createPlayer($this->parkId, 'oar-util');
        $recent = date('Y-m-d', strtotime('-3 days'));
        $this->fixture->insertAttendance($p['mundane_id'], $this->parkId, $this->kingdomId, $recent, 0, 1);
        $this->fixture->insertAttendance($p['mundane_id'], $this->parkId, $this->kingdomId, date('Y-m-d', strtotime('-4 days')), 0, 1);
        $this->fixture->insertAttendance($p['mundane_id'], $this->parkId, $this->kingdomId, date('Y-m-d', strtotime('-5 days')), 0, 0);
        $this->assertSame($before + 2, $find(), 'delta equals the seeded online rows in the window');
    }
}
