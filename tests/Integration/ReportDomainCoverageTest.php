<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * In-memory ghettocache for Report cache-bust / ladder grid characterization.
 */
final class ReportCoverageInMemoryGhettocache extends Ghettocache
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function __construct()
    {
        parent::__construct();
    }

    public function get($call, $key, $lifetime)
    {
        $this->lifetime[$this->prefix . '.' . $call . '.' . $key] = $lifetime;
        $fullKey = $this->prefix . '.' . $call . '.' . $key;

        return $this->store[$fullKey] ?? false;
    }

    public function cache($call, $key, $content)
    {
        $fullKey = $this->prefix . '.' . $call . '.' . $key;
        $this->store[$fullKey] = $content;

        return $content;
    }

    public function bust($call, $key): void
    {
        unset($this->store[$this->prefix . '.' . $call . '.' . $key]);
    }

    public function has(string $call, string $key): bool
    {
        return array_key_exists($this->prefix . '.' . $call . '.' . $key, $this->store);
    }
}

/**
 * Mutation-killing characterization for class.Report.php (RB-H Reports Infection).
 *
 * Uses real sandbox kingdoms (voting rule kingdom IDs are absent from TEST seed)
 * with explicit rule payloads so GetVotingEligible SQL paths see fixture rows.
 */
final class ReportDomainCoverageTest extends TestCase
{
    private ReportsFixture $fixture;

    private Report $report;

    private Ghettocache $originalCache;

    private ReportCoverageInMemoryGhettocache $cache;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = ReportsFixture::create();
        $this->report = new Report();
        $this->originalCache = Ork3::$Lib->ghettocache;
        $this->cache = new ReportCoverageInMemoryGhettocache();
        Ork3::$Lib->ghettocache = $this->cache;
    }

    protected function tearDown(): void
    {
        Ork3::$Lib->ghettocache = $this->originalCache;

        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testAttendanceDatesDomainContract(): void
    {
        $invalid = $this->report->GetAttendanceDates(['Type' => 'Kingdom', 'Id' => 0]);
        $this->assertNotSame(0, $invalid['Status']['Status']);
        $this->assertSame([], $invalid['Dates']);

        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $player = $this->fixture->createPlayer($parkId, 'dates-dom');
        $this->fixture->insertAttendance($player['mundane_id'], $parkId, $kid, '2025-03-01');
        $this->fixture->insertAttendance($player['mundane_id'], $parkId, $kid, '2025-03-15');

        $kingdom = $this->report->GetAttendanceDates(['Type' => 'Kingdom', 'Id' => $kid]);
        $this->assertSame(0, $kingdom['Status']['Status']);
        $this->assertContains('2025-03-15', $kingdom['Dates']);
        $this->assertContains('2025-03-01', $kingdom['Dates']);
        $sorted = $kingdom['Dates'];
        rsort($sorted);
        $this->assertSame($sorted, $kingdom['Dates']);

        $park = $this->report->GetAttendanceDates(['Type' => 'Park', 'Id' => $parkId]);
        $this->assertSame(0, $park['Status']['Status']);
        $this->assertContains('2025-03-15', $park['Dates']);
    }

    public function testVotingRulesPayload(): void
    {
        $all = $this->report->GetVotingRules(['KingdomId' => 0]);
        $this->assertSame(0, $all['Status']['Status']);
        $this->assertSame(VotingRules::supportedKingdomIds(), $all['SupportedKingdomIds']);
        $this->assertNull($all['KingdomRules']);
        $this->assertArrayHasKey(14, $all['Rules']);

        $one = $this->report->GetVotingRules(['KingdomId' => 14]);
        $this->assertSame(VotingRules::rulesForKingdom(14), $one['KingdomRules']);
        $this->assertSame(7, $one['KingdomRules']['AttendanceRequired']);
    }

    public function testVotingEligibleEmptyKingdom(): void
    {
        $result = $this->report->GetVotingEligible([
            'KingdomId' => 0,
            'AttendanceMode' => 'count',
            'AttendanceRequired' => 3,
            'MonthsWindow' => 6,
            'MinMembershipMonths' => 0,
        ]);
        $this->assertSame([], $result['Players']);
        $this->assertSame(3, $result['AttendanceRequired']);
        $this->assertSame(6, $result['MonthsWindow']);
        $this->assertFalse($result['ProvinceMode']);
    }

    public function testVotingEligibleCountModeWithFixturePlayer(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $player = $this->fixture->createPlayer($parkId, 'vote-count');
        $this->fixture->makeVotingReady($player['mundane_id']);
        $this->fixture->insertDues($player['mundane_id'], $parkId, $kid);

        $dates = ['2025-11-01', '2025-11-08', '2025-11-15', '2025-11-22', '2025-12-01'];
        foreach ($dates as $date) {
            $this->fixture->insertAttendance($player['mundane_id'], $parkId, $kid, $date);
        }

        $result = $this->report->GetVotingEligible([
            'KingdomId' => $kid,
            'MundaneId' => $player['mundane_id'],
            'AttendanceMode' => 'count',
            'AttendanceRequired' => 4,
            'MonthsWindow' => 12,
            'MinMembershipMonths' => 0,
            'StartDate' => '2025-10-01',
            'ProvinceMode' => false,
        ]);

        $this->assertSame('count', $result['AttendanceMode']);
        $this->assertSame(4, $result['AttendanceRequired']);
        $this->assertSame(12, $result['MonthsWindow']);
        $this->assertSame('2025-10-01', $result['StartDate']);
        $this->assertCount(1, $result['Players']);

        $row = $result['Players'][0];
        $this->assertSame($player['mundane_id'], $row['MundaneId']);
        $this->assertSame(1, $row['Waivered']);
        $this->assertSame(1, $row['DuesPaid']);
        $this->assertSame(5, $row['AttCount']);
        $this->assertTrue($row['KingdomEligible']);
        $this->assertTrue($row['VotingEligible']);
        $this->assertTrue($row['MembershipOk']);
        $this->assertFalse($row['Suspended']);
        $this->assertSame($parkId, $row['ParkId']);
        $this->assertSame($kid, $row['KingdomId']);
        $this->assertNotSame('', $row['ParkName']);
        $this->assertNotSame('', $row['KingdomName']);
    }

    public function testVotingEligibleDaysAndWeeksModes(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $player = $this->fixture->createPlayer($parkId, 'vote-modes');
        $this->fixture->makeVotingReady($player['mundane_id']);
        $this->fixture->insertDues($player['mundane_id'], $parkId, $kid);

        // Two distinct calendar days in the same ISO week, plus one later week.
        $this->fixture->insertAttendance($player['mundane_id'], $parkId, $kid, '2025-10-06');
        $this->fixture->insertAttendance($player['mundane_id'], $parkId, $kid, '2025-10-07');
        $this->fixture->insertAttendance($player['mundane_id'], $parkId, $kid, '2025-10-20');

        $days = $this->report->GetVotingEligible([
            'KingdomId' => $kid,
            'MundaneId' => $player['mundane_id'],
            'AttendanceMode' => 'days',
            'AttendanceRequired' => 2,
            'MonthsWindow' => 12,
            'MinMembershipMonths' => 0,
            'StartDate' => '2025-09-01',
        ]);
        $this->assertSame('days', $days['AttendanceMode']);
        $this->assertSame(3, $days['Players'][0]['AttCount']);
        $this->assertTrue($days['Players'][0]['KingdomEligible']);

        $weeks = $this->report->GetVotingEligible([
            'KingdomId' => $kid,
            'MundaneId' => $player['mundane_id'],
            'AttendanceMode' => 'weeks',
            'AttendanceRequired' => 2,
            'MonthsWindow' => 12,
            'MinMembershipMonths' => 0,
            'StartDate' => '2025-09-01',
        ]);
        $this->assertSame('weeks', $weeks['AttendanceMode']);
        $this->assertSame(2, $weeks['Players'][0]['AttCount']);
        $this->assertTrue($weeks['Players'][0]['KingdomEligible']);
    }

    public function testVotingEligibleInvalidModeFallsBackToWeeks(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $result = $this->report->GetVotingEligible([
            'KingdomId' => $kid,
            'AttendanceMode' => 'not-a-mode',
            'AttendanceRequired' => 6,
            'MonthsWindow' => 6,
            'MinMembershipMonths' => 0,
            'MundaneId' => 1,
        ]);
        $this->assertSame('weeks', $result['AttendanceMode']);
    }

    public function testVotingEligibleHomeParkOnly(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $homePark = $this->fixture->parkIdInKingdom($kid);
        $awayPark = $this->fixture->secondParkIdInKingdom($kid, $homePark);
        if ($awayPark <= 0) {
            $this->markTestSkipped('Need two active parks in kingdom.');
        }

        $player = $this->fixture->createPlayer($homePark, 'vote-home');
        $this->fixture->makeVotingReady($player['mundane_id']);
        $this->fixture->insertDues($player['mundane_id'], $homePark, $kid);

        $this->fixture->insertAttendance($player['mundane_id'], $homePark, $kid, '2025-11-02');
        $this->fixture->insertAttendance($player['mundane_id'], $homePark, $kid, '2025-11-09');
        $this->fixture->insertAttendance($player['mundane_id'], $awayPark, $kid, '2025-11-16');
        $this->fixture->insertAttendance($player['mundane_id'], $awayPark, $kid, '2025-11-23');

        $result = $this->report->GetVotingEligible([
            'KingdomId' => $kid,
            'MundaneId' => $player['mundane_id'],
            'AttendanceMode' => 'count',
            'AttendanceRequired' => 2,
            'MonthsWindow' => 12,
            'MinMembershipMonths' => 0,
            'HomeParkOnly' => true,
            'StartDate' => '2025-10-01',
        ]);

        $this->assertTrue($result['HomeParkOnly']);
        $this->assertSame(2, $result['Players'][0]['AttCount']);
        $this->assertTrue($result['Players'][0]['KingdomEligible']);
    }

    public function testVotingEligibleProvinceModeEmitsParkAttCount(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $player = $this->fixture->createPlayer($parkId, 'vote-prov');
        $this->fixture->makeVotingReady($player['mundane_id']);
        $this->fixture->insertDues($player['mundane_id'], $parkId, $kid);
        $this->fixture->insertAttendance($player['mundane_id'], $parkId, $kid, '2025-11-05');
        $this->fixture->insertAttendance($player['mundane_id'], $parkId, $kid, '2025-11-12');
        $this->fixture->insertAttendance($player['mundane_id'], $parkId, $kid, '2025-11-19');

        $result = $this->report->GetVotingEligible([
            'KingdomId' => $kid,
            'MundaneId' => $player['mundane_id'],
            'AttendanceMode' => 'count',
            'AttendanceRequired' => 2,
            'MonthsWindow' => 12,
            'MinMembershipMonths' => 0,
            'ProvinceMode' => true,
            'StartDate' => '2025-10-01',
        ]);

        $this->assertTrue($result['ProvinceMode']);
        $row = $result['Players'][0];
        $this->assertArrayHasKey('ParkAttCount', $row);
        $this->assertArrayHasKey('ProvinceEligible', $row);
        $this->assertSame(3, $row['ParkAttCount']);
        $this->assertTrue($row['ProvinceEligible']);
        $this->assertTrue($row['KingdomEligible']);
    }

    public function testVotingEligibleFirstAttendanceMembership(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $player = $this->fixture->createPlayer($parkId, 'vote-first');
        // park_member_since is recent, but first attendance is older than MinMembershipMonths.
        $this->fixture->makeVotingReady($player['mundane_id'], date('Y-m-d'));
        $this->fixture->insertDues($player['mundane_id'], $parkId, $kid);
        $this->fixture->insertAttendance($player['mundane_id'], $parkId, $kid, '2024-01-15');
        $this->fixture->insertAttendance($player['mundane_id'], $parkId, $kid, '2025-11-01');
        $this->fixture->insertAttendance($player['mundane_id'], $parkId, $kid, '2025-11-08');
        $this->fixture->insertAttendance($player['mundane_id'], $parkId, $kid, '2025-11-15');

        $result = $this->report->GetVotingEligible([
            'KingdomId' => $kid,
            'MundaneId' => $player['mundane_id'],
            'AttendanceMode' => 'count',
            'AttendanceRequired' => 2,
            'MonthsWindow' => 12,
            'MinMembershipMonths' => 3,
            'MembershipMode' => 'first_attendance',
            'StartDate' => '2025-10-01',
        ]);

        $this->assertSame('first_attendance', $result['MembershipMode']);
        $row = $result['Players'][0];
        $this->assertSame('2024-01-15', $row['MemberSince']);
        $this->assertTrue($row['MembershipOk']);
        $this->assertSame(3, $row['AttCount']);
        $this->assertTrue($row['KingdomEligible']);
    }

    public function testVotingEligibleParkIdDerivesKingdom(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $player = $this->fixture->createPlayer($parkId, 'vote-parkid');
        $this->fixture->makeVotingReady($player['mundane_id']);
        $this->fixture->insertDues($player['mundane_id'], $parkId, $kid);
        $this->fixture->insertAttendance($player['mundane_id'], $parkId, $kid, '2025-11-03');
        $this->fixture->insertAttendance($player['mundane_id'], $parkId, $kid, '2025-11-10');

        $result = $this->report->GetVotingEligible([
            'KingdomId' => 0,
            'ParkId' => $parkId,
            'MundaneId' => $player['mundane_id'],
            'AttendanceMode' => 'count',
            'AttendanceRequired' => 2,
            'MonthsWindow' => 12,
            'MinMembershipMonths' => 0,
            'StartDate' => '2025-10-01',
        ]);

        $this->assertCount(1, $result['Players']);
        $this->assertSame($kid, $result['Players'][0]['KingdomId']);
        $this->assertSame(2, $result['Players'][0]['AttCount']);
    }

    public function testVotingEligibleForPlayerDelegates(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $player = $this->fixture->createPlayer($parkId, 'vote-for');
        $this->fixture->makeVotingReady($player['mundane_id']);
        $this->fixture->insertDues($player['mundane_id'], $parkId, $kid);
        for ($i = 0; $i < 3; $i++) {
            $this->fixture->insertAttendance(
                $player['mundane_id'],
                $parkId,
                $kid,
                date('Y-m-d', strtotime('-' . ($i * 7) . ' days')),
            );
        }

        // Auto-merge only runs when AttendanceMode is absent; sandbox kingdoms have no stored rules.
        $result = $this->report->GetVotingEligibleForPlayer([
            'MundaneId' => $player['mundane_id'],
            'KingdomId' => $kid,
            'AttendanceMode' => 'count',
            'AttendanceRequired' => 2,
            'MonthsWindow' => 6,
            'MinMembershipMonths' => 0,
        ]);
        $this->assertArrayHasKey('Players', $result);
        $this->assertLessThanOrEqual(1, count($result['Players']));
        if ($result['Players'] !== []) {
            $this->assertSame($player['mundane_id'], $result['Players'][0]['MundaneId']);
            $this->assertGreaterThanOrEqual(2, $result['Players'][0]['AttCount']);
        }
    }

    public function testLadderGridParkScopeAndAwardedPlayer(): void
    {
        $kid = $this->fixture->kingdomWithLadderAwards();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $kingdomName = $this->fixture->kingdomName($kid);
        $parkName = $this->fixture->parkName($parkId);
        $ladder = $this->fixture->firstLadderAward($kid);

        $player = $this->fixture->createPlayer($parkId, 'ladder-grid');
        $this->fixture->insertLadderAward(
            $player['mundane_id'],
            $parkId,
            $kid,
            $ladder['kingdomaward_id'],
            $ladder['award_id'],
            4,
        );
        $this->fixture->insertAttendance(
            $player['mundane_id'],
            $parkId,
            $kid,
            date('Y-m-d', strtotime('-30 days')),
        );

        $parkGrid = $this->report->GetLadderAwardGrid(['KingdomId' => 0, 'ParkId' => $parkId]);
        $this->assertSame($kingdomName . ' — ' . $parkName, $parkGrid['ScopeName']);
        $this->assertArrayHasKey($ladder['award_id'], $parkGrid['LadderAwards']);

        $col = $parkGrid['LadderAwards'][$ladder['award_id']];
        $this->assertSame(
            preg_replace('/^Order of (?:the )?/i', '', $col['Name']),
            $col['DisplayName'],
        );
        $this->assertSame($ladder['name'], $col['Name']);
        $knightMap = [
            'Order of Battle' => 'Battle',
            'Order of the Warrior' => 'Sword',
            'Order of the Crown' => 'Crown',
            'Order of the Lion' => 'Flame',
            'Order of the Rose' => 'Flame',
            'Order of the Smith' => 'Flame',
            'Order of the Dragon' => 'Serpent',
            'Order of the Garber' => 'Serpent',
            'Order of the Owl' => 'Serpent',
        ];
        if (isset($knightMap[$col['Name']])) {
            $this->assertSame($knightMap[$col['Name']], $col['KnightGroup']);
        }

        $byId = [];
        foreach ($parkGrid['GridRows'] as $row) {
            $byId[$row['MundaneId']] = $row;
        }
        $this->assertArrayHasKey($player['mundane_id'], $byId);
        $found = $byId[$player['mundane_id']];
        $this->assertSame($player['mundane_id'], $found['MundaneId']);
        $this->assertArrayHasKey($ladder['award_id'], $found['Awards']);
        $this->assertSame(4, $found['Awards'][$ladder['award_id']]['Rank']);
        $this->assertFalse($found['Awards'][$ladder['award_id']]['IsMaster']);
        $this->assertTrue($found['RecentSignIn']);
        $this->assertIsArray($found['KnightGroups']);

        $kingdomGrid = $this->report->GetLadderAwardGrid(['KingdomId' => $kid, 'ParkId' => 0]);
        $this->assertSame($kingdomName, $kingdomGrid['ScopeName']);
        $this->assertNotEmpty($kingdomGrid['LadderAwards']);
    }

    public function testOfficerDirectoryParkPivotAndMerged(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $monarch = $this->fixture->createPlayer($parkId, 'off-mon');
        $regent = $this->fixture->createPlayer($parkId, 'off-reg');
        $this->fixture->insertParkOfficer($kid, $parkId, $monarch['mundane_id'], 'Monarch');
        $this->fixture->insertParkOfficer($kid, $parkId, $regent['mundane_id'], 'Regent');

        $raw = $this->report->KingdomOfficerDirectory(['KingdomId' => $kid]);
        $this->assertSame(0, $raw['Status']['Status']);
        $this->assertSame('parks', $raw['Mode']);

        $parkRow = null;
        foreach ($raw['Kingdoms'] as $row) {
            if ((int) $row['KingdomId'] === $parkId) {
                $parkRow = $row;
                break;
            }
        }
        $this->assertNotNull($parkRow);
        $this->assertSame($monarch['mundane_id'], (int) $parkRow['MonarchId']);
        $this->assertSame($regent['mundane_id'], (int) $parkRow['RegentId']);
        $this->assertStringContainsString('Off-mon', (string) $parkRow['MonarchPersona']);
        $this->assertStringContainsString('Off-reg', (string) $parkRow['RegentPersona']);
        $this->assertNotSame('', (string) $parkRow['MonarchGiven']);
        $this->assertArrayHasKey('MonarchEmail', $parkRow);
        $this->assertArrayHasKey('GMRId', $parkRow);

        $merged = $this->report->GetKingdomOfficerDirectoryMerged(['KingdomId' => $kid]);
        $this->assertSame(0, $merged['Status']['Status']);
        $this->assertSame('parks', $merged['Mode']);
        $this->assertIsArray($merged['Principalities']);
        $this->assertNotEmpty($merged['Rows']);

        $top = $this->report->KingdomOfficerDirectory(['KingdomId' => 0]);
        $this->assertSame('kingdoms', $top['Mode']);
        $this->assertSame(0, $top['Status']['Status']);
    }

    public function testParkAverageCacheBust(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $bustKey = $this->cache->key(['KingdomId' => $kid]);
        $avgCall = 'Report.GetKingdomParkAverages';
        $monthlyCall = 'Report.GetKingdomParkMonthlyAverages';
        $extCall = 'Report.GetKingdomExtendedParkAverages';

        $this->cache->cache($avgCall, $bustKey, ['cached' => 'avg']);
        $this->cache->cache($monthlyCall, $bustKey, ['cached' => 'mo']);
        $this->cache->cache($extCall, $this->cache->key(['KingdomId' => $kid, 'IsAdmin' => 0]), ['cached' => 'ext0']);
        $this->cache->cache($extCall, $this->cache->key(['KingdomId' => $kid, 'IsAdmin' => 1]), ['cached' => 'ext1']);

        $this->report->bustKingdomParkAverageCaches(0);
        $this->assertTrue($this->cache->has($avgCall, $bustKey));

        $this->report->bustKingdomParkAverageCaches($kid);
        $this->assertFalse($this->cache->has($avgCall, $bustKey));
        $this->assertFalse($this->cache->has($monthlyCall, $bustKey));
        $this->assertFalse($this->cache->has($extCall, $this->cache->key(['KingdomId' => $kid, 'IsAdmin' => 0])));
        $this->assertFalse($this->cache->has($extCall, $this->cache->key(['KingdomId' => $kid, 'IsAdmin' => 1])));
    }
}
