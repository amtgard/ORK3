<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/PopulationExplorerProbe.php';

/**
 * Population Explorer: scoped execution, authorization, parity against the
 * existing reports (GetPlayerRoster, PlayerAwards = knights/masters list).
 */
final class PopulationExplorerRunTest extends TestCase
{
    private ReportsFixture $fixture;

    private PopulationExplorer $pe;

    private int $kid;

    private int $parkId;

    /** @var array{mundane_id:int,park_id:int,kingdom_id:int,token:string} */
    private array $admin;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = ReportsFixture::create();
        $this->pe = new PopulationExplorer();
        $this->kid = $this->fixture->firstKingdomId();
        $this->parkId = $this->fixture->parkIdInKingdom($this->kid);
        $this->admin = $this->fixture->createPlayer($this->parkId, 'pe-admin');
        $this->fixture->insertGlobalAdmin($this->admin['mundane_id']);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    private function req(string $token, string $type, int $id, array $tree = [], array $cols = ['persona'], array $extra = []): array
    {
        return $extra + [
            'Token' => $token,
            'ScopeType' => $type,
            'ScopeId' => $id,
            'Tree' => $tree,
            'Columns' => $cols,
        ];
    }

    private function exec(array $request): array
    {
        unset($_SESSION['is_authorized_mundane_id']);

        return $this->pe->Run($request);
    }

    private function leaf(string $c, string $o, $v, ?int $p = null): array
    {
        $l = ['c' => $c, 'o' => $o, 'v' => $v];
        if ($p !== null) {
            $l['p'] = $p;
        }

        return $l;
    }

    private function tree(array ...$leaves): array
    {
        return ['op' => 'AND', 'children' => $leaves];
    }

    /** @return list<int> */
    private function ids(array $result): array
    {
        $ids = array_map(static fn (array $r): int => (int) $r['MundaneId'], $result['Rows'] ?? []);
        sort($ids);

        return $ids;
    }

    private function player(string $suffix, ?int $parkId = null): array
    {
        return $this->fixture->createPlayer($parkId ?? $this->parkId, $suffix);
    }

    private function sql(string $sql, array $args = []): void
    {
        $this->fixture->pdo()->prepare($sql)->execute($args);
    }

    private function roster(string $type, int $id, array $flags, string $token): array
    {
        $base = [
            'Type' => $type, 'Id' => $id, 'Token' => $token, 'Suspended' => false, 'Active' => false,
            'InActive' => false, 'Waivered' => false, 'UnWaivered' => false, 'Banned' => false,
            'DuesPaid' => false, 'IncludeRetiredUnitMembers' => false,
        ];
        unset($_SESSION['is_authorized_mundane_id']);
        $r = (new Report())->GetPlayerRoster($flags + $base);
        $this->assertSame(0, $r['Status']['Status']);

        return $r['Roster'];
    }

    public function testEmptyTreeReturnsAllPlayersInScope(): void
    {
        $this->player('pe-a');
        $this->player('pe-b');
        $expected = (int) $this->fixture->pdo()->query(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'mundane WHERE park_id = ' . $this->parkId . ' AND kingdom_id = ' . $this->kid
        )->fetchColumn();

        $r = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId));

        $this->assertSame(0, $r['Status']['Status']);
        $this->assertSame($expected, $r['Total']);
        $this->assertCount($expected, $r['Rows']);
        $this->assertFalse($r['Truncated']);
        $this->assertIsInt($r['ElapsedMs']);
        $this->assertSame('persona', $r['Columns'][0]['id']);
    }

    public function testLastSigninFilterMatchesRosterLastSignIn(): void
    {
        $a = $this->player('pe-ls-a');
        $b = $this->player('pe-ls-b');
        $this->player('pe-ls-none');
        $this->fixture->insertAttendance($a['mundane_id'], $this->parkId, $this->kid, '2025-06-01');
        $this->fixture->insertAttendance($b['mundane_id'], $this->parkId, $this->kid, '2025-01-01');
        $this->fixture->insertAttendance($b['mundane_id'], $this->parkId, $this->kid, '2024-12-01');

        $d = '2025-03-01';
        $r = $this->exec($this->req(
            $this->admin['token'],
            'Park',
            $this->parkId,
            $this->tree($this->leaf('last_signin', 'gte', $d)),
            ['persona', 'last_signin']
        ));
        $this->assertSame(0, $r['Status']['Status']);

        $expected = [];
        foreach ($this->roster('Park', $this->parkId, [], $this->admin['token']) as $row) {
            if ($row['LastSignIn'] !== null && $row['LastSignIn'] >= $d) {
                $expected[] = (int) $row['MundaneId'];
            }
        }
        sort($expected);
        $this->assertContains($a['mundane_id'], $expected);
        $this->assertSame($expected, $this->ids($r));
        // last_signin column agrees with the roster value
        foreach ($r['Rows'] as $row) {
            if ($row['MundaneId'] === $a['mundane_id']) {
                $this->assertSame('2025-06-01', $row['last_signin']);
            }
        }
    }

    /**
     * Mundane ids Report::GetDuesPaidList (the Dues report) lists for each id in $ids
     * of the given scope type, restricted to players whose home is inside $homeWhere.
     *
     * @param list<int> $ids
     * @return list<int>
     */
    private function duesReportIds(string $type, array $ids, string $homeWhere): array
    {
        require_once DIR_UI . 'model/model.Kingdom.php';
        require_once DIR_UI . 'model/model.Park.php';
        $out = [];
        foreach ($ids as $id) {
            unset($_SESSION['is_authorized_mundane_id']);
            $r = (new Report())->GetDuesPaidList(['Type' => $type, 'Id' => $id, 'Token' => $this->admin['token']]);
            foreach ($r['DuesPaidList'] ?? [] as $row) {
                $out[] = (int) $row['MundaneId'];
            }
        }
        $out = array_values(array_unique($out));
        if ($out === []) {
            return [];
        }
        $home = array_map('intval', $this->fixture->pdo()->query(
            'SELECT mundane_id FROM ' . DB_PREFIX . 'mundane WHERE mundane_id IN (' . implode(',', $out) . ") AND ($homeWhere)"
        )->fetchAll(PDO::FETCH_COLUMN));
        sort($home);

        return $home;
    }

    public function testDuesPaidParityWithDuesReport(): void
    {
        $paid = $this->player('pe-dues-paid');
        $expired = $this->player('pe-dues-expired');
        $life = $this->player('pe-dues-life');
        $revoked = $this->player('pe-dues-revoked');
        $none = $this->player('pe-dues-none');
        $future = date('Y-m-d', strtotime('+1 year'));
        $past = date('Y-m-d', strtotime('-1 year'));
        $this->fixture->insertDues($paid['mundane_id'], $this->parkId, $this->kid, $future);
        $this->fixture->insertDues($expired['mundane_id'], $this->parkId, $this->kid, $past);
        // Lifetime dues count as paid even with a past dues_until.
        $this->fixture->insertDues($life['mundane_id'], $this->parkId, $this->kid, '2001-01-01', true);
        $this->fixture->insertDues($revoked['mundane_id'], $this->parkId, $this->kid, $future, false, true);
        $mine = [$paid['mundane_id'], $expired['mundane_id'], $life['mundane_id'], $revoked['mundane_id'], $none['mundane_id']];

        // Park scope: exactly the Dues report's park list (home park players).
        $r = $this->exec($this->req(
            $this->admin['token'],
            'Park',
            $this->parkId,
            $this->tree($this->leaf('dues_paid', 'is', 'yes')),
            ['persona', 'dues_paid', 'dues_through']
        ));
        $this->assertSame(0, $r['Status']['Status']);
        $expected = $this->duesReportIds('Park', [$this->parkId], 'park_id = ' . $this->parkId);
        $want = [$paid['mundane_id'], $life['mundane_id']];
        sort($want);
        $this->assertSame($want, array_values(array_intersect($expected, $mine)), 'fixture rows reach the Dues report');
        $this->assertSame($expected, $this->ids($r));

        // Kingdom scope: the Dues report summed over the stats kingdoms.
        $stats = array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($this->kid));
        $k = $this->exec($this->req($this->admin['token'], 'Kingdom', $this->kid, $this->tree($this->leaf('dues_paid', 'is', 'yes'))));
        $this->assertSame(0, $k['Status']['Status']);
        $this->assertSame($this->duesReportIds('Kingdom', $stats, 'kingdom_id IN (' . implode(',', $stats) . ')'), $this->ids($k));

        // Columns: Dues paid flag and Dues through (MAX dues_until; "Lifetime" for lifetime dues).
        $all = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, [], ['persona', 'dues_paid', 'dues_through']));
        $byId = [];
        foreach ($all['Rows'] as $row) {
            $byId[$row['MundaneId']] = $row;
        }
        $this->assertSame([1, $future], [$byId[$paid['mundane_id']]['dues_paid'], $byId[$paid['mundane_id']]['dues_through']]);
        $this->assertSame([0, $past], [$byId[$expired['mundane_id']]['dues_paid'], $byId[$expired['mundane_id']]['dues_through']]);
        $this->assertSame([1, 'Lifetime'], [$byId[$life['mundane_id']]['dues_paid'], $byId[$life['mundane_id']]['dues_through']]);
        $this->assertSame([0, null], [$byId[$revoked['mundane_id']]['dues_paid'], $byId[$revoked['mundane_id']]['dues_through']]);
        $this->assertSame([0, null], [$byId[$none['mundane_id']]['dues_paid'], $byId[$none['mundane_id']]['dues_through']]);

        // "no" is the complement within the park.
        $no = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, $this->tree($this->leaf('dues_paid', 'is', 'no'))));
        $got = array_values(array_intersect($this->ids($no), $mine));
        $wantNo = [$expired['mundane_id'], $revoked['mundane_id'], $none['mundane_id']];
        sort($wantNo);
        $this->assertSame($wantNo, $got);

        // Dues through criterion: lifetime sorts as the far future; NULL never matches.
        $through = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, $this->tree($this->leaf('dues_through', 'gte', date('Y-m-d')))));
        $this->assertSame($want, array_values(array_intersect($this->ids($through), $mine)));
        $before = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, $this->tree($this->leaf('dues_through', 'lt', date('Y-m-d')))));
        $this->assertSame([$expired['mundane_id']], array_values(array_intersect($this->ids($before), $mine)));
    }

    public function testDuesPaidToAnotherParkCountOnlyInKingdomScope(): void
    {
        $p2 = $this->fixture->secondParkIdInKingdom($this->kid, $this->parkId);
        if ($p2 <= 0) {
            $this->markTestSkipped('Needs a second park in the kingdom.');
        }
        $pl = $this->player('pe-dues-other');
        $this->fixture->insertDues($pl['mundane_id'], $p2, $this->kid, date('Y-m-d', strtotime('+1 year')));

        $r = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, $this->tree($this->leaf('dues_paid', 'is', 'yes'))));
        $this->assertNotContains($pl['mundane_id'], $this->ids($r));

        $k = $this->exec($this->req($this->admin['token'], 'Kingdom', $this->kid, $this->tree($this->leaf('dues_paid', 'is', 'yes'))));
        $this->assertContains($pl['mundane_id'], $this->ids($k));
    }

    public function testActiveAndSuspendedFlags(): void
    {
        $on = $this->player('pe-flag-on');
        $off = $this->player('pe-flag-off');
        $sus = $this->player('pe-flag-sus');
        $this->sql('UPDATE ' . DB_PREFIX . 'mundane SET active = 1, suspended = 0 WHERE mundane_id = ?', [$on['mundane_id']]);
        $this->sql('UPDATE ' . DB_PREFIX . 'mundane SET active = 0, suspended = 0 WHERE mundane_id = ?', [$off['mundane_id']]);
        $this->sql('UPDATE ' . DB_PREFIX . 'mundane SET active = 1, suspended = 1 WHERE mundane_id = ?', [$sus['mundane_id']]);

        $act = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, $this->tree($this->leaf('active', 'is', 'yes'))));
        $this->assertContains($on['mundane_id'], $this->ids($act));
        $this->assertContains($sus['mundane_id'], $this->ids($act));
        $this->assertNotContains($off['mundane_id'], $this->ids($act));
        $expected = array_map(
            static fn (array $x): int => (int) $x['MundaneId'],
            $this->roster('Park', $this->parkId, ['Active' => true], $this->admin['token'])
        );
        sort($expected);
        $this->assertSame($expected, $this->ids($act));

        $s = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, $this->tree($this->leaf('suspended', 'is', 'yes'))));
        $this->assertSame([$sus['mundane_id']], array_values(array_intersect($this->ids($s), [$on['mundane_id'], $off['mundane_id'], $sus['mundane_id']])));
    }

    public function testNegatedComparisonsExcludeNullPlayers(): void
    {
        $has = $this->player('pe-neg-has');
        $none = $this->player('pe-neg-none');
        $this->fixture->insertAttendance($has['mundane_id'], $this->parkId, $this->kid, '2025-02-02');
        $classIds = array_map('intval', $this->fixture->pdo()->query(
            'SELECT class_id FROM ' . DB_PREFIX . 'class ORDER BY class_id LIMIT 2'
        )->fetchAll(PDO::FETCH_COLUMN));
        $this->assertCount(2, $classIds);

        $ne = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, $this->tree($this->leaf('last_signin', 'ne', '2020-01-01'))));
        $this->assertContains($has['mundane_id'], $this->ids($ne));
        $this->assertNotContains($none['mundane_id'], $this->ids($ne));

        // fixture attendance uses the first class; "is not <second>" must keep $has and drop $none
        $isNot = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, $this->tree($this->leaf('last_class', 'is_not', $classIds[1]))));
        $this->assertContains($has['mundane_id'], $this->ids($isNot));
        $this->assertNotContains($none['mundane_id'], $this->ids($isNot));
    }

    /** @return array{0:int,1:int} [kingdomaward_id, award_id] of a kingdom award of the given peerage */
    private function peerageAward(string $peerage): array
    {
        $st = $this->fixture->pdo()->prepare(
            'SELECT ka.kingdomaward_id, ka.award_id FROM ' . DB_PREFIX . 'kingdomaward ka
             JOIN ' . DB_PREFIX . 'award a ON a.award_id = ka.award_id
             WHERE ka.kingdom_id = ? AND a.peerage = ? ORDER BY ka.kingdomaward_id LIMIT 1'
        );
        $st->execute([$this->kid, $peerage]);
        $row = $st->fetch(PDO::FETCH_NUM);
        if ($row === false) {
            $this->markTestSkipped("No $peerage kingdom award in kingdom {$this->kid}.");
        }

        return [(int) $row[0], (int) $row[1]];
    }

    public function testRevokedAwardsDoNotCountAsHeld(): void
    {
        $pl = $this->player('pe-revoked');
        [$kaId, $awardId] = $this->peerageAward('Knight');
        $awardsId = $this->fixture->insertLadderAward($pl['mundane_id'], $this->parkId, $this->kid, $kaId, $awardId, 0);
        $this->sql('UPDATE ' . DB_PREFIX . 'awards SET revoked = 1 WHERE awards_id = ?', [$awardsId]);

        $tree = $this->tree($this->leaf('knighthood', 'has_any', [$awardId]));
        $revoked = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, $tree));
        $this->assertSame(0, $revoked['Status']['Status']);
        $this->assertNotContains($pl['mundane_id'], $this->ids($revoked));

        $this->sql('UPDATE ' . DB_PREFIX . 'awards SET revoked = 0 WHERE awards_id = ?', [$awardsId]);
        $held = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, $tree));
        $this->assertContains($pl['mundane_id'], $this->ids($held));

        $count = $this->exec($this->req(
            $this->admin['token'],
            'Park',
            $this->parkId,
            $this->tree($this->leaf('award_count', 'gte', 1)),
            ['persona', 'award_count']
        ));
        $this->assertContains($pl['mundane_id'], $this->ids($count));
    }

    public function testKingdomOnlyAwardsCountAndDate(): void
    {
        $pl = $this->player('pe-ko');
        $other = $this->player('pe-ko-none');
        $kaId = $this->fixture->insertKingdomOnlyAward($this->kid, 'Order of the Test Raider');
        $awardsId = $this->fixture->insertLadderAward($pl['mundane_id'], $this->parkId, $this->kid, $kaId, 0, 1);
        $this->sql('UPDATE ' . DB_PREFIX . 'awards SET date = ? WHERE awards_id = ?', ['2024-05-05', $awardsId]);
        [$knKa, $knAward] = $this->peerageAward('Knight');
        $this->fixture->insertLadderAward($pl['mundane_id'], $this->parkId, $this->kid, $knKa, $knAward, 0);

        // Hand count: both held awards, the kingdom-only one included.
        $r = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, [], ['persona', 'award_count']));
        $byId = array_column($r['Rows'], 'award_count', 'MundaneId');
        $this->assertSame(2, $byId[$pl['mundane_id']]);
        $this->assertSame(0, $byId[$other['mundane_id']]);

        $gte2 = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, $this->tree($this->leaf('award_count', 'gte', 2))));
        $this->assertContains($pl['mundane_id'], $this->ids($gte2));

        // Only the kingdom-only award was given in 2024.
        $in2024 = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, $this->tree(
            $this->leaf('award_date_any', 'between', ['2024-01-01', '2024-12-31'])
        )));
        $this->assertContains($pl['mundane_id'], $this->ids($in2024));
        $this->assertNotContains($other['mundane_id'], $this->ids($in2024));
    }

    public function testPeerageParityWithKnightsReport(): void
    {
        $knight = $this->player('pe-knight');
        $plain = $this->player('pe-plain');
        $this->sql('UPDATE ' . DB_PREFIX . 'mundane SET active = 1 WHERE mundane_id IN (?, ?)', [$knight['mundane_id'], $plain['mundane_id']]);
        [$kaId, $awardId] = $this->peerageAward('Knight');
        $this->fixture->insertLadderAward($knight['mundane_id'], $this->parkId, $this->kid, $kaId, $awardId, 0);

        // The knights_list controller path: Model_Reports::kingdom_awards -> Report::PlayerAwards
        unset($_SESSION['is_authorized_mundane_id']);
        $report = (new Report())->PlayerAwards([
            'KingdomId' => $this->kid, 'ParkId' => 0, 'IncludeKnights' => 1, 'IncludeMasters' => 0,
        ]);
        $this->assertSame(0, $report['Status']['Status']);
        $expected = array_values(array_unique(array_map(static fn (array $a): int => (int) $a['MundaneId'], $report['Awards'])));
        sort($expected);
        $this->assertContains($knight['mundane_id'], $expected);

        // The report lists active players only; match that in the tree.
        $r = $this->exec($this->req(
            $this->admin['token'],
            'Kingdom',
            $this->kid,
            $this->tree($this->leaf('knighthood', 'is', 'yes'), $this->leaf('active', 'is', 'yes'))
        ));
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertSame($expected, $this->ids($r));
        $this->assertNotContains($plain['mundane_id'], $this->ids($r));
    }

    /** A tree whose only rule is a restricted criterion, nested so the rule path is [1, 0]. */
    private function restrictedTree(string $c = 'suspended'): array
    {
        return ['op' => 'AND', 'children' => [
            $this->leaf('active', 'is', 'yes'),
            ['op' => 'OR', 'children' => [$this->leaf($c, 'is', 'no')]],
        ]];
    }

    private function assertRestrictedRejected(array $r, string $why): void
    {
        $this->assertSame(ServiceErrorIds::InvalidParameter, $r['Status']['Status'], $why . ': ' . json_encode($r['Status']));
        $this->assertSame(PopulationExplorer::RESTRICTED_MESSAGE, $r['Status']['Detail'], $why);
        $this->assertSame([1, 0], $r['RulePath'] ?? null, $why);
        $this->assertArrayNotHasKey('Rows', $r, $why);
    }

    private function assertRestrictedAllowed(array $r, string $why): void
    {
        $this->assertSame(0, $r['Status']['Status'], $why . ': ' . json_encode($r['Status']));
        $this->assertArrayHasKey('Rows', $r, $why);
    }

    public function testPlainPlayerMayRunAnyKingdomOrParkWithoutRestrictedCriteria(): void
    {
        $plain = $this->player('pe-plain-viewer');
        $p2 = $this->fixture->secondParkIdInKingdom($this->kid, $this->parkId);
        $other = $this->otherKingdomId();
        $otherPark = $this->fixture->parkIdInKingdom($other);
        $scopes = [['Park', $this->parkId], ['Kingdom', $this->kid], ['Kingdom', $other]];
        if ($p2 > 0) {
            $scopes[] = ['Park', $p2];
        }
        if ($otherPark > 0) {
            $scopes[] = ['Park', $otherPark];
        }
        foreach ($scopes as [$type, $id]) {
            $r = $this->exec($this->req($plain['token'], $type, $id));
            $this->assertSame(0, $r['Status']['Status'], "$type $id: " . json_encode($r['Status']));
            $this->assertSame($r['ScopeTotal'], $r['Total'], "$type $id");
            foreach (['suspended', 'banned'] as $c) {
                $this->assertRestrictedRejected($this->exec($this->req($plain['token'], $type, $id, $this->restrictedTree($c))), "$c in $type $id");
            }
        }
        $this->assertFalse($this->pe->IsScopeOfficer($plain['token'], 'Kingdom', $this->kid));
        $this->assertFalse($this->pe->IsScopeOfficer($plain['token'], 'Park', $this->parkId));
    }

    public function testNonExistentScopeIsInvalidNotForbidden(): void
    {
        $plain = $this->player('pe-plain-missing');
        $missingKingdom = (int) $this->fixture->pdo()->query('SELECT COALESCE(MAX(kingdom_id), 0) + 1000 FROM ' . DB_PREFIX . 'kingdom')->fetchColumn();
        $missingPark = (int) $this->fixture->pdo()->query('SELECT COALESCE(MAX(park_id), 0) + 1000 FROM ' . DB_PREFIX . 'park')->fetchColumn();
        foreach ([['Kingdom', $missingKingdom], ['Park', $missingPark], ['Kingdom', 0], ['Park', -3]] as [$type, $id]) {
            $denied = $this->pe->AuthorizeScope($plain['token'], $type, $id);
            $this->assertNotNull($denied, "$type $id");
            $this->assertSame(ServiceErrorIds::InvalidParameter, $denied['Status'], "$type $id");
            $r = $this->exec($this->req($plain['token'], $type, $id));
            $this->assertSame(ServiceErrorIds::InvalidParameter, $r['Status']['Status'], "$type $id");
            $this->assertArrayNotHasKey('Rows', $r);
        }
        $this->assertNull($this->pe->AuthorizeScope($plain['token'], 'Kingdom', $this->kid));
        $this->assertNull($this->pe->AuthorizeScope($plain['token'], 'Park', $this->parkId));
        // A bad token is reported as such before the scope is looked at.
        unset($_SESSION['is_authorized_mundane_id']);
        $this->assertSame(ServiceErrorIds::SecureTokenFailure, $this->pe->AuthorizeScope('nope', 'Kingdom', $missingKingdom)['Status']);
    }

    public function testParkOfficerIsAnOfficerOnlyForTheirOwnPark(): void
    {
        $p2 = $this->fixture->secondParkIdInKingdom($this->kid, $this->parkId);
        if ($p2 <= 0) {
            $this->markTestSkipped('Needs a second park in the kingdom.');
        }
        $officer = $this->player('pe-pk-officer');
        // Real park-officer grants carry kingdom_id = 0; a row with both set would also
        // satisfy the kingdom lookup in HasAuthority and prove nothing here.
        $this->fixture->insertScopedAuth($officer['mundane_id'], $this->parkId, 0, AUTH_CREATE);

        $own = $this->exec($this->req($officer['token'], 'Park', $this->parkId, $this->restrictedTree()));
        $this->assertRestrictedAllowed($own, 'own park');
        $this->assertTrue($this->pe->IsScopeOfficer($officer['token'], 'Park', $this->parkId));

        // Another park and the kingdom: may run, but not with restricted criteria.
        $other = $this->exec($this->req($officer['token'], 'Park', $p2));
        $this->assertSame(0, $other['Status']['Status']);
        $this->assertRestrictedRejected($this->exec($this->req($officer['token'], 'Park', $p2, $this->restrictedTree('banned'))), 'other park');
        $this->assertFalse($this->pe->IsScopeOfficer($officer['token'], 'Park', $p2));

        $kingdom = $this->exec($this->req($officer['token'], 'Kingdom', $this->kid));
        $this->assertSame(0, $kingdom['Status']['Status']);
        $this->assertRestrictedRejected($this->exec($this->req($officer['token'], 'Kingdom', $this->kid, $this->restrictedTree())), 'kingdom');
        $this->assertFalse($this->pe->IsScopeOfficer($officer['token'], 'Kingdom', $this->kid));
    }

    public function testKingdomOfficerIsAnOfficerForTheKingdomAndItsParks(): void
    {
        $officer = $this->player('pe-kd-officer');
        $this->fixture->insertScopedAuth($officer['mundane_id'], 0, $this->kid, AUTH_CREATE);

        $own = $this->exec($this->req($officer['token'], 'Kingdom', $this->kid));
        $this->assertSame(0, $own['Status']['Status']);
        $kidList = implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($this->kid)));
        $expected = (int) $this->fixture->pdo()->query(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . "mundane WHERE kingdom_id IN ($kidList)"
        )->fetchColumn();
        $this->assertSame($expected, $own['Total']);
        $this->assertRestrictedAllowed($this->exec($this->req($officer['token'], 'Kingdom', $this->kid, $this->restrictedTree())), 'own kingdom');
        $this->assertRestrictedAllowed($this->exec($this->req($officer['token'], 'Park', $this->parkId, $this->restrictedTree('banned'))), 'park in own kingdom');
        $this->assertTrue($this->pe->IsScopeOfficer($officer['token'], 'Park', $this->parkId));

        $otherKingdom = (int) $this->fixture->pdo()->query(
            'SELECT kingdom_id FROM ' . DB_PREFIX . "kingdom WHERE kingdom_id <> {$this->kid} AND parent_kingdom_id <> {$this->kid} AND active = 'Active' LIMIT 1"
        )->fetchColumn();
        $this->assertGreaterThan(0, $otherKingdom);
        $this->assertSame(0, $this->exec($this->req($officer['token'], 'Kingdom', $otherKingdom))['Status']['Status']);
        $this->assertRestrictedRejected($this->exec($this->req($officer['token'], 'Kingdom', $otherKingdom, $this->restrictedTree())), 'other kingdom');

        // every returned row belongs to the kingdom's stats ids
        $in = array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($this->kid));
        $idList = implode(',', array_map(static fn (array $r): int => (int) $r['MundaneId'], $own['Rows']));
        $bad = (int) $this->fixture->pdo()->query(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . "mundane WHERE mundane_id IN ($idList) AND kingdom_id NOT IN (" . implode(',', $in) . ')'
        )->fetchColumn();
        $this->assertSame(0, $bad);
    }

    public function testAdminMayUseRestrictedCriteriaEverywhere(): void
    {
        $other = $this->otherKingdomId();
        foreach ([['Park', $this->parkId], ['Kingdom', $this->kid], ['Kingdom', $other]] as [$type, $id]) {
            foreach (['suspended', 'banned'] as $c) {
                $this->assertRestrictedAllowed($this->exec($this->req($this->admin['token'], $type, $id, $this->restrictedTree($c))), "$c in $type $id");
            }
            $this->assertTrue($this->pe->IsScopeOfficer($this->admin['token'], $type, $id));
        }
        unset($_SESSION['is_authorized_mundane_id']);
        $this->assertFalse($this->pe->IsScopeOfficer('not-a-token', 'Kingdom', $this->kid), 'a bad token is never an officer');
    }

    public function testRestrictedCriteriaRejectedInExportAndShareLinkForNonOfficers(): void
    {
        $plain = $this->player('pe-plain-export');
        $before = glob(sys_get_temp_dir() . '/population-explorer-*') ?: [];
        $x = $this->pe->BuildExport($this->req($plain['token'], 'Park', $this->parkId, $this->restrictedTree('banned')));
        $this->assertSame(ServiceErrorIds::InvalidParameter, $x['Status']['Status']);
        $this->assertSame(PopulationExplorer::RESTRICTED_MESSAGE, $x['Status']['Detail']);
        $this->assertSame([1, 0], $x['RulePath']);
        $this->assertArrayNotHasKey('Path', $x);
        $this->assertSame(count($before), count(glob(sys_get_temp_dir() . '/population-explorer-*') ?: []));

        // Without restricted criteria a non-officer can export.
        unset($_SESSION['is_authorized_mundane_id']);
        $ok = $this->pe->BuildExport($this->req($plain['token'], 'Park', $this->parkId));
        $this->assertSame(0, $ok['Status']['Status']);
        @unlink($ok['Path']);

        $model = new Model_Reports();
        $q = PopulationExplorer::EncodeLink(['tree' => $this->restrictedTree(), 'columns' => ['persona']]);
        unset($_SESSION['is_authorized_mundane_id']);
        $d = $model->population_decode_link($q, $plain['token'], 'Park', $this->parkId);
        $this->assertFalse($d['ok']);
        $this->assertStringContainsString(PopulationExplorer::RESTRICTED_MESSAGE, (string) $d['error']);
        unset($_SESSION['is_authorized_mundane_id']);
        $this->assertTrue($model->population_decode_link($q, $this->admin['token'], 'Park', $this->parkId)['ok']);
        unset($_SESSION['is_authorized_mundane_id']);
        $this->assertFalse($model->population_decode_link($q, '', 'Park', $this->parkId)['ok'], 'no session: fail closed');
    }

    public function testPublicRegistryHidesRestrictedCriteriaFromNonOfficers(): void
    {
        $plain = $this->pe->PublicRegistry('Park', $this->parkId, false);
        $this->assertArrayNotHasKey('suspended', $plain['criteria']);
        $this->assertArrayNotHasKey('banned', $plain['criteria']);
        $this->assertFalse($plain['officer']);
        $this->assertArrayNotHasKey('suspended', $this->pe->PublicRegistry('Park', $this->parkId)['criteria'], 'defaults to non-officer');

        $officer = $this->pe->PublicRegistry('Park', $this->parkId, true);
        $this->assertTrue($officer['criteria']['suspended']['restricted']);
        $this->assertArrayHasKey('banned', $officer['criteria']);
        $this->assertTrue($officer['officer']);

        $model = new Model_Reports();
        $viewer = $this->player('pe-plain-registry');
        unset($_SESSION['is_authorized_mundane_id']);
        $reg = $model->population_registry($viewer['token'], 'Kingdom', $this->kid);
        $this->assertArrayNotHasKey('suspended', $reg['criteria']);
        $this->assertFalse($reg['officer']);
        unset($_SESSION['is_authorized_mundane_id']);
        $reg = $model->population_registry($this->admin['token'], 'Kingdom', $this->kid);
        $this->assertArrayHasKey('suspended', $reg['criteria']);
        $this->assertTrue($reg['officer']);
    }

    public function testGlobalAdminMayChooseAnyKingdom(): void
    {
        $others = $this->fixture->pdo()->query(
            'SELECT kingdom_id FROM ' . DB_PREFIX . "kingdom WHERE active = 'Active' AND parent_kingdom_id = 0 LIMIT 3"
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->assertNotEmpty($others);
        foreach ($others as $k) {
            $r = $this->exec($this->req($this->admin['token'], 'Kingdom', (int) $k));
            $this->assertSame(0, $r['Status']['Status'], "kingdom $k");
        }
    }

    public function testBadTokenRejected(): void
    {
        $r = $this->exec($this->req('not-a-real-token', 'Park', $this->parkId));
        $this->assertSame(ServiceErrorIds::SecureTokenFailure, $r['Status']['Status']);
        $this->assertArrayNotHasKey('Rows', $r);

        $r = $this->exec($this->req('', 'Park', $this->parkId));
        $this->assertSame(ServiceErrorIds::SecureTokenFailure, $r['Status']['Status']);

        $r = $this->exec($this->req($this->admin['token'], 'Event', 5));
        $this->assertSame(ServiceErrorIds::InvalidParameter, $r['Status']['Status']);

        $r = $this->exec($this->req($this->admin['token'], 'Park', 0));
        $this->assertSame(ServiceErrorIds::InvalidParameter, $r['Status']['Status']);
    }

    public function testInvalidTreeReturnsRulePath(): void
    {
        $r = $this->exec($this->req(
            $this->admin['token'],
            'Park',
            $this->parkId,
            $this->tree($this->leaf('last_signin', 'gte', 'not-a-date'))
        ));
        $this->assertSame(ServiceErrorIds::InvalidParameter, $r['Status']['Status']);
        $this->assertSame([0], $r['RulePath']);
        $this->assertArrayNotHasKey('Rows', $r);

        $r = $this->exec($this->req(
            $this->admin['token'],
            'Park',
            $this->parkId,
            $this->tree($this->leaf('active', 'is', 'yes'), ['op' => 'OR', 'children' => [$this->leaf('nonsense', 'is', 1)]])
        ));
        $this->assertSame([1, 0], $r['RulePath']);
    }

    public function testColumnsAreWhitelistedAndPersonaForced(): void
    {
        $this->player('pe-cols');
        $r = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, [], ['total_signins', 'bogus', 'given_name', 'total_signins', 'last_signin']));
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertSame(['persona', 'total_signins', 'last_signin'], array_column($r['Columns'], 'id'));
        $this->assertSame(['id', 'label', 'type'], array_keys($r['Columns'][0]));
        $this->assertNotEmpty($r['Rows']);
        foreach ($r['Rows'] as $row) {
            $this->assertSame(['MundaneId', 'persona', 'total_signins', 'last_signin'], array_keys($row));
        }
        $blob = strtolower(json_encode($r));
        foreach (['given_name', 'surname', 'givenname', 'email', 'bogus'] as $k) {
            $this->assertStringNotContainsString($k, $blob);
        }

        // no columns requested at all still yields persona
        $r2 = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, [], []));
        $this->assertSame(['persona'], array_column($r2['Columns'], 'id'));
    }

    public function testResultCapAndTotal(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->player('pe-cap-' . $i);
        }
        $expected = (int) $this->fixture->pdo()->query(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'mundane WHERE park_id = ' . $this->parkId . ' AND kingdom_id = ' . $this->kid
        )->fetchColumn();
        $this->assertGreaterThan(3, $expected);

        $r = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, [], ['persona'], ['RowCap' => 3]));
        $this->assertCount(3, $r['Rows']);
        $this->assertTrue($r['Truncated']);
        $this->assertSame($expected, $r['Total']);

        // RowCap above MAX_ROWS is ignored; small result is not truncated
        $r = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, [], ['persona'], ['RowCap' => 999999]));
        $this->assertFalse($r['Truncated']);
        $this->assertCount($expected, $r['Rows']);
    }

    public function testPublicRegistryHasNoSqlAndScopedOptions(): void
    {
        $reg = $this->pe->PublicRegistry('Park', $this->parkId);
        $json = json_encode($reg);
        $this->assertNotFalse($json);
        $this->assertStringNotContainsString('"sql"', $json);
        $this->assertArrayHasKey('last_signin', $reg['criteria']);
        $this->assertSame(['label', 'group', 'type', 'operands', 'param'], array_slice(array_keys($reg['criteria']['last_signin']), 0, 5));
        $this->assertSame('persona', array_key_first($reg['columns']));
        $this->assertTrue($reg['columns']['persona']['default']);
        foreach (['class', 'award', 'order', 'park', 'kingdom'] as $k) {
            $this->assertArrayHasKey($k, $reg['options']);
        }
        $this->assertSame([[$this->parkId, $this->fixture->parkName($this->parkId)]], $reg['options']['park']);
        $this->assertSame([[$this->kid, $this->fixture->kingdomName($this->kid)]], $reg['options']['kingdom']);

        $kreg = $this->pe->PublicRegistry('Kingdom', $this->kid);
        $parkIds = array_map(static fn (array $p): int => $p[0], $kreg['options']['park']);
        $this->assertContains($this->parkId, $parkIds);
        $stats = array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($this->kid));
        $stmt = $this->fixture->pdo()->query(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'park WHERE kingdom_id NOT IN (' . implode(',', $stats) . ') AND park_id IN (' . implode(',', $parkIds) . ')'
        );
        $this->assertSame(0, (int) $stmt->fetchColumn());
        $this->assertNotEmpty($kreg['options']['class']);
        $this->assertSame(3, count($kreg['options']['award'][0]));
        $this->assertArrayHasKey('Knight', $kreg['options']['order']);
        $this->assertNotFalse(json_encode($kreg));
    }

    public function testOrTreesCannotEscapeParkScope(): void
    {
        $p2 = $this->fixture->secondParkIdInKingdom($this->kid, $this->parkId);
        $inside = $this->player('pe-or-in');
        $this->sql('UPDATE ' . DB_PREFIX . 'mundane SET active = 1 WHERE mundane_id = ?', [$inside['mundane_id']]);
        if ($p2 > 0) {
            $outside = $this->player('pe-or-out', $p2);
            $this->sql('UPDATE ' . DB_PREFIX . 'mundane SET active = 0 WHERE mundane_id = ?', [$outside['mundane_id']]);
        }
        $inPark = array_map('intval', $this->fixture->pdo()->query(
            'SELECT mundane_id FROM ' . DB_PREFIX . 'mundane WHERE park_id = ' . $this->parkId
        )->fetchAll(PDO::FETCH_COLUMN));

        $trees = [
            ['op' => 'OR', 'children' => [$this->leaf('active', 'is', 'yes'), $this->leaf('active', 'is', 'no')]],
            ['op' => 'OR', 'children' => [$this->leaf('last_signin', 'lte', '1900-01-01'), $this->leaf('suspended', 'is', 'no')]],
            ['op' => 'OR', 'children' => [
                ['op' => 'OR', 'children' => [$this->leaf('active', 'is', 'yes')]],
                $this->leaf('suspended', 'is', 'no'),
            ]],
        ];
        foreach ($trees as $n => $tree) {
            $r = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, $tree));
            $this->assertSame(0, $r['Status']['Status'], "tree $n");
            $this->assertNotEmpty($r['Rows'], "tree $n");
            foreach ($this->ids($r) as $id) {
                $this->assertContains($id, $inPark, "tree $n returned a player outside the scope park");
            }
            $this->assertLessThanOrEqual(count($inPark), $r['Total'], "tree $n");
        }
    }

    /** @return array<string,string> zip entry name => contents (reads STORE or DEFLATE entries via ZipArchive) */
    private function readXlsx(string $path): array
    {
        if (!extension_loaded('zip')) {
            $this->markTestSkipped('ext-zip not available to read the workbook.');
        }
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path) === true, 'workbook opens as a zip');
        $out = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $out[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        return $out;
    }

    public function testExportBuildsReadableXlsxWithHeaderAndRows(): void
    {
        $p = $this->player('pe-xl');
        $r = $this->pe->BuildExport($this->req($this->admin['token'], 'Park', $this->parkId, [], ['persona', 'dues_paid', 'award_count', 'last_signin']));
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertMatchesRegularExpression('/^population-explorer-\d{4}-\d{2}-\d{2}\.xlsx$/', $r['Filename']);
        $this->assertFileExists($r['Path']);
        try {
            $parts = $this->readXlsx($r['Path']);
            $sheet = $parts['xl/worksheets/sheet1.xml'] ?? '';
            $this->assertStringContainsString('Population Explorer', $parts['xl/workbook.xml']);
            foreach (['Persona', 'Dues paid', 'Award count', 'Last sign-in'] as $label) {
                $this->assertStringContainsString('>' . $label . '<', $sheet);
            }
            $this->assertStringContainsString('state="frozen"', $sheet);
            $this->assertStringContainsString('Pe-xl', $sheet, 'known persona present');
            $this->assertStringNotContainsString('Showing first', $sheet);
            // flags are Yes/No text, not 0/1
            $this->assertMatchesRegularExpression('/>(Yes|No)</', $sheet);
        } finally {
            @unlink($r['Path']);
        }
        $this->assertGreaterThan(0, $p['mundane_id']);
    }

    public function testExportTruncatedAddsNoteRow(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->player('pe-xt-' . $i);
        }
        $total = (int) $this->fixture->pdo()->query(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'mundane WHERE park_id = ' . $this->parkId . ' AND kingdom_id = ' . $this->kid
        )->fetchColumn();
        $r = $this->pe->BuildExport($this->req($this->admin['token'], 'Park', $this->parkId, [], ['persona'], ['RowCap' => 3]));
        $this->assertSame(0, $r['Status']['Status']);
        try {
            $sheet = $this->readXlsx($r['Path'])['xl/worksheets/sheet1.xml'];
            $this->assertStringContainsString('Showing first 3 of ' . $total . ' matches', $sheet);
            // header + 3 data rows + spacer + note row
            $this->assertSame(6, substr_count($sheet, '<row '));
        } finally {
            @unlink($r['Path']);
        }
    }

    public function testExportRequiresAuth(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);
        $before = glob(sys_get_temp_dir() . '/population-explorer-*') ?: [];
        $r = $this->pe->BuildExport($this->req('not-a-real-token', 'Park', $this->parkId));
        $this->assertSame(ServiceErrorIds::SecureTokenFailure, $r['Status']['Status']);
        $this->assertArrayNotHasKey('Path', $r);
        $this->assertSame(count($before), count(glob(sys_get_temp_dir() . '/population-explorer-*') ?: []));

        $r = $this->pe->BuildExport($this->req($this->admin['token'], 'Park', $this->parkId, ['op' => 'AND', 'children' => [['c' => 'nope', 'o' => 'is', 'v' => 1]]]));
        $this->assertNotSame(0, $r['Status']['Status']);
        $this->assertArrayNotHasKey('Path', $r);
    }

    public function testExportFormulaLikePersonaIsLiteralText(): void
    {
        $p = $this->player('pe-fx');
        $formula = '=HYPERLINK("http://x","y")';
        $this->fixture->pdo()->prepare('UPDATE ' . DB_PREFIX . 'mundane SET persona = ? WHERE mundane_id = ?')
            ->execute([$formula, $p['mundane_id']]);
        $r = $this->pe->BuildExport($this->req($this->admin['token'], 'Park', $this->parkId));
        $this->assertSame(0, $r['Status']['Status']);
        try {
            $sheet = $this->readXlsx($r['Path'])['xl/worksheets/sheet1.xml'];
            $this->assertStringContainsString('=HYPERLINK(&quot;http://x&quot;,&quot;y&quot;)</t>', $sheet);
            // stored as an inline string cell, never as a formula element
            $this->assertStringNotContainsString('<f>', $sheet);
            $this->assertStringContainsString('t="inlineStr"', $sheet);
        } finally {
            @unlink($r['Path']);
        }
    }

    public function testScopeTotalIgnoresTreeAndIsAbsentOnError(): void
    {
        $this->player('pe-st');
        $expected = (int) $this->fixture->pdo()->query(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'mundane WHERE park_id = ' . $this->parkId . ' AND kingdom_id = ' . $this->kid
        )->fetchColumn();

        $none = $this->tree($this->leaf('total_signins', 'gt', 999999));
        $r = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, $none));
        $this->assertSame(0, $r['Total']);
        $this->assertSame($expected, $r['ScopeTotal']);

        $r = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId));
        $this->assertSame($expected, $r['ScopeTotal']);
        $this->assertSame($r['Total'], $r['ScopeTotal']);

        $kExpected = (int) $this->fixture->pdo()->query(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'mundane WHERE kingdom_id = ' . $this->kid
        )->fetchColumn();
        $r = $this->exec($this->req($this->admin['token'], 'Kingdom', $this->kid, $none));
        $this->assertGreaterThanOrEqual($kExpected, $r['ScopeTotal']);

        $r = $this->exec($this->req('bad', 'Park', $this->parkId));
        $this->assertArrayNotHasKey('ScopeTotal', $r);
        $r = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, ['op' => 'AND', 'children' => [['c' => 'nope', 'o' => 'is', 'v' => 1]]]));
        $this->assertArrayNotHasKey('ScopeTotal', $r);
    }

    private function probe(): PopulationExplorerProbe
    {
        unset($_SESSION['is_authorized_mundane_id']);

        return new PopulationExplorerProbe();
    }

    /** @return list<string> statements that are the tree-filtered COUNT (not the scope total) */
    private function filteredCounts(PopulationExplorerProbe $p): array
    {
        return array_values(array_filter($p->statements, static fn (string $s): bool => (bool) preg_match('/COUNT\(\*\) AS n FROM \w+ m WHERE \(.*\) AND \(/s', $s)));
    }

    public function testQueryShapeTimeoutNoGroupByAndCountOnlyWhenCapped(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->player('pe-shape-' . $i);
        }
        $p = $this->probe();
        $r = $p->Run($this->req($this->admin['token'], 'Park', $this->parkId, [], ['persona', 'last_signin']));
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertFalse($r['Truncated']);
        $this->assertSame(count($r['Rows']), $r['Total']);
        $this->assertSame([], $this->filteredCounts($p), 'no COUNT when the rows are under the cap');
        $rowIdx = array_keys(array_filter($p->statements, static fn (string $s): bool => str_contains($s, 'AS c_persona')));
        $this->assertCount(1, $rowIdx);
        $rowSql = $p->statements[$rowIdx[0]];
        $this->assertTrue($p->timed[$rowIdx[0]], 'row query runs under the statement timeout');
        $this->assertStringNotContainsString('GROUP BY', $rowSql);
        $this->assertStringContainsString('ORDER BY m.persona, m.mundane_id', $rowSql);

        $p = $this->probe();
        $r = $p->Run($this->req($this->admin['token'], 'Park', $this->parkId, [], ['persona'], ['RowCap' => 3]));
        $this->assertTrue($r['Truncated']);
        $this->assertCount(3, $r['Rows']);
        $this->assertCount(1, $this->filteredCounts($p), 'COUNT runs when the cap is hit');
        $countIdx = array_search($this->filteredCounts($p)[0], $p->statements, true);
        $this->assertTrue($p->timed[$countIdx], 'COUNT runs under the statement timeout');
        $this->assertSame(PopulationExplorer::STATEMENT_TIMEOUT_S, 10);
    }

    public function testEveryColumnYieldsOneRowPerPlayer(): void
    {
        $pl = $this->player('pe-dup');
        foreach (['2025-01-01', '2025-02-01', '2025-03-01'] as $d) {
            $this->fixture->insertAttendance($pl['mundane_id'], $this->parkId, $this->kid, $d);
        }
        $this->fixture->insertDues($pl['mundane_id'], $this->parkId, $this->kid);
        $this->fixture->insertDues($pl['mundane_id'], $this->parkId, $this->kid, date('Y-m-d', strtotime('+2 years')));
        [$kaId, $awardId] = $this->peerageAward('Knight');
        $this->fixture->insertLadderAward($pl['mundane_id'], $this->parkId, $this->kid, $kaId, $awardId, 0);
        $this->fixture->insertLadderAward($pl['mundane_id'], $this->parkId, $this->kid, $kaId, $awardId, 0);

        $cols = array_keys($this->pe->PublicRegistry('Park', $this->parkId)['columns']);
        $this->assertCount(19, $cols);
        $r = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, [], $cols));
        $this->assertSame(0, $r['Status']['Status']);
        $ids = array_map(static fn (array $x): int => $x['MundaneId'], $r['Rows']);
        $this->assertSame(count($ids), count(array_unique($ids)), 'MundaneIds are unique');
        $this->assertSame(1, count(array_keys($ids, $pl['mundane_id'], true)));
    }

    public function testDbFailureOnRowQueryIsAnErrorNotAnEmptyTable(): void
    {
        $p = $this->probe();
        $p->failPattern = '/AS c_persona/';
        $r = $p->Run($this->req($this->admin['token'], 'Park', $this->parkId));
        $this->assertSame(ServiceErrorIds::ProcessingError, $r['Status']['Status']);
        $this->assertArrayNotHasKey('Rows', $r);
        $this->assertArrayNotHasKey('Total', $r);
    }

    public function testDbFailureOnCountAndScopeTotalIsAnError(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->player('pe-cf-' . $i);
        }
        $p = $this->probe();
        $p->failPattern = '/COUNT\(\*\) AS n FROM \w+ m WHERE \(.*\) AND \(/s';
        $r = $p->Run($this->req($this->admin['token'], 'Park', $this->parkId, [], ['persona'], ['RowCap' => 2]));
        $this->assertSame(ServiceErrorIds::ProcessingError, $r['Status']['Status']);
        $this->assertArrayNotHasKey('Rows', $r);

        $p = $this->probe();
        $p->failPattern = '/^SELECT COUNT\(\*\) AS n FROM \w+ m WHERE \([^)]*\)$/';
        $r = $p->Run($this->req($this->admin['token'], 'Park', $this->parkId));
        $this->assertSame(ServiceErrorIds::ProcessingError, $r['Status']['Status']);
        $this->assertArrayNotHasKey('ScopeTotal', $r);
    }

    public function testDbFailureLoadingKnownIdsIsNotAValidationError(): void
    {
        $p = $this->probe();
        $p->failPattern = '/FROM ' . DB_PREFIX . 'class\b/';
        try {
            $p->LoadKnown();
            $this->fail('LoadKnown must throw when the class list cannot be read');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('class', $e->getMessage());
        }

        $r = $p->Run($this->req($this->admin['token'], 'Park', $this->parkId, $this->tree($this->leaf('last_class', 'in', [1]))));
        $this->assertSame(ServiceErrorIds::ProcessingError, $r['Status']['Status']);
        $this->assertArrayNotHasKey('RulePath', $r);

        $p = $this->probe();
        $p->failPattern = '/FROM ' . DB_PREFIX . 'award\b/';
        $this->expectException(RuntimeException::class);
        $p->LoadKnown();
    }

    public function testDbFailureInRegistryOptionsThrows(): void
    {
        $p = $this->probe();
        $p->failPattern = '/FROM ' . DB_PREFIX . 'park\b/';
        $this->expectException(RuntimeException::class);
        $p->PublicRegistry('Kingdom', $this->kid);
    }

    public function testStatementTimeoutIsReportedAsTooSlow(): void
    {
        $p = $this->probe();
        $p->timeout = 0.000001;
        $r = $p->Run($this->req($this->admin['token'], 'Kingdom', $this->kid, $this->tree($this->leaf('signins_last_n_months', 'gte', 0, 60)), ['persona', 'last_signin', 'award_count']));
        $this->assertSame(ServiceErrorIds::ProcessingError, $r['Status']['Status']);
        $this->assertTrue($r['TimedOut'] ?? false);
        $this->assertStringContainsString('took too long', (string) $r['Status']['Detail']);
        $this->assertArrayNotHasKey('Rows', $r);
    }

    public function testPersonaIsUnslashedInOnePlaceAndOtherTextIsNot(): void
    {
        $pl = $this->player('pe-slash');
        $this->fixture->pdo()->prepare('UPDATE ' . DB_PREFIX . 'mundane SET persona = ? WHERE mundane_id = ?')
            ->execute(["O\\'Brien \\ T10", $pl['mundane_id']]);
        $parkName = $this->fixture->parkName($this->parkId);
        $this->fixture->pdo()->prepare('UPDATE ' . DB_PREFIX . 'park SET name = ? WHERE park_id = ?')
            ->execute([$parkName . ' C:\\Dir', $this->parkId]);
        try {
            $r = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, [], ['persona', 'home_park']));
            $row = array_values(array_filter($r['Rows'], static fn (array $x): bool => $x['MundaneId'] === $pl['mundane_id']))[0];
            $this->assertSame("O'Brien  T10", $row['persona']);
            $this->assertSame($parkName . ' C:\\Dir', $row['home_park'], 'only persona is unslashed');

            $x = $this->pe->BuildExport($this->req($this->admin['token'], 'Park', $this->parkId, [], ['persona', 'home_park']));
            try {
                $sheet = $this->readXlsx($x['Path'])['xl/worksheets/sheet1.xml'];
                $this->assertStringContainsString('Brien  T10', $sheet);
                $this->assertStringNotContainsString('O\\', $sheet, 'persona unslashed in the xlsx');
                $this->assertStringContainsString('C:\\Dir', $sheet, 'park name keeps its backslash');
            } finally {
                @unlink($x['Path']);
            }
        } finally {
            $this->fixture->pdo()->prepare('UPDATE ' . DB_PREFIX . 'park SET name = ? WHERE park_id = ?')->execute([$parkName, $this->parkId]);
        }
    }

    public function testRegistryOffersRetiredAwardsSoOldLinksShowNames(): void
    {
        $retired = $this->fixture->pdo()->query('SELECT award_id, name, peerage FROM ' . DB_PREFIX . 'award WHERE deprecate = 1 ORDER BY award_id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if ($retired === false) {
            $this->markTestSkipped('No deprecated award in the test DB.');
        }
        $reg = $this->pe->PublicRegistry('Park', $this->parkId);
        $byId = [];
        foreach ($reg['options']['award'] as $o) {
            $byId[$o[0]] = $o;
        }
        $id = (int) $retired['award_id'];
        $this->assertArrayHasKey($id, $byId);
        $this->assertSame($retired['name'] . ' (retired)', $byId[$id][1]);
        // and a current award keeps its plain name
        $live = (int) $this->fixture->pdo()->query('SELECT award_id FROM ' . DB_PREFIX . 'award WHERE deprecate = 0 ORDER BY award_id LIMIT 1')->fetchColumn();
        $this->assertStringNotContainsString('(retired)', $byId[$live][1]);
    }

    /** @return list<array{0:int,1:int}> up to $n [kingdomaward_id, award_id] pairs of a peerage in the test kingdom */
    private function peerageAwards(string $peerage, int $n): array
    {
        $st = $this->fixture->pdo()->prepare(
            'SELECT ka.kingdomaward_id, ka.award_id FROM ' . DB_PREFIX . 'kingdomaward ka
             JOIN ' . DB_PREFIX . 'award a ON a.award_id = ka.award_id
             WHERE ka.kingdom_id = ? AND a.peerage = ? GROUP BY ka.award_id ORDER BY ka.award_id LIMIT ' . (int) $n
        );
        $st->execute([$this->kid, $peerage]);
        $rows = array_map(static fn (array $r): array => [(int) $r[0], (int) $r[1]], $st->fetchAll(PDO::FETCH_NUM));
        if (count($rows) < $n) {
            $this->markTestSkipped("Needs $n $peerage awards in kingdom {$this->kid}.");
        }

        return $rows;
    }

    /** Ids among $mine that the tree returns in the park scope. @param list<int> $mine @return list<int> */
    private function matchAmong(array $tree, array $mine, string $type = 'Park', ?int $id = null): array
    {
        $r = $this->exec($this->req($this->admin['token'], $type, $id ?? ($type === 'Park' ? $this->parkId : $this->kid), $tree));
        $this->assertSame(0, $r['Status']['Status'], json_encode($r['Status']));
        $got = array_values(array_intersect($this->ids($r), $mine));
        sort($got);

        return $got;
    }

    /** @param list<int> $ids @return list<int> */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }

    public function testHasAllHasAnyHasNoneOnKnighthoods(): void
    {
        [[$ka1, $aw1], [$ka2, $aw2]] = $this->peerageAwards('Knight', 2);
        $both = $this->player('pe-hasall-both');
        $one = $this->player('pe-hasall-one');
        $none = $this->player('pe-hasall-none');
        $this->fixture->insertLadderAward($both['mundane_id'], $this->parkId, $this->kid, $ka1, $aw1, 0);
        $this->fixture->insertLadderAward($both['mundane_id'], $this->parkId, $this->kid, $ka2, $aw2, 0);
        // a duplicate grant of the same order must not satisfy "has all" on its own
        $this->fixture->insertLadderAward($one['mundane_id'], $this->parkId, $this->kid, $ka1, $aw1, 0);
        $this->fixture->insertLadderAward($one['mundane_id'], $this->parkId, $this->kid, $ka1, $aw1, 0);
        $mine = [$both['mundane_id'], $one['mundane_id'], $none['mundane_id']];

        $this->assertSame([$both['mundane_id']], $this->matchAmong($this->tree($this->leaf('knighthood', 'has_all', [$aw1, $aw2])), $mine));
        $this->assertSame($this->sorted([$both['mundane_id'], $one['mundane_id']]), $this->matchAmong($this->tree($this->leaf('knighthood', 'has_any', [$aw1, $aw2])), $mine));
        $this->assertSame($this->sorted([$one['mundane_id'], $none['mundane_id']]), $this->matchAmong($this->tree($this->leaf('knighthood', 'has_none', [$aw2])), $mine));

        $r = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, [], ['persona', 'knighthoods']));
        $names = array_column($r['Rows'], 'knighthoods', 'MundaneId');
        $this->assertSame(1, substr_count((string) $names[$one['mundane_id']], ',') + 1, 'duplicate grant listed once');
        $this->assertSame(2, count(explode(', ', (string) $names[$both['mundane_id']])));
        $this->assertNull($names[$none['mundane_id']]);
    }

    public function testClassesPlayedInLastNMonths(): void
    {
        $classId = (int) $this->fixture->pdo()->query('SELECT class_id FROM ' . DB_PREFIX . 'class ORDER BY class_id LIMIT 1')->fetchColumn();
        $recent = $this->player('pe-cls-recent');
        $old = $this->player('pe-cls-old');
        $never = $this->player('pe-cls-never');
        $this->fixture->insertAttendance($recent['mundane_id'], $this->parkId, $this->kid, date('Y-m-d', strtotime('-1 month')));
        $this->fixture->insertAttendance($old['mundane_id'], $this->parkId, $this->kid, date('Y-m-d', strtotime('-15 months')));
        $mine = [$recent['mundane_id'], $old['mundane_id'], $never['mundane_id']];

        $this->assertSame([$recent['mundane_id']], $this->matchAmong($this->tree($this->leaf('classes_last_n_months', 'in', [$classId], 6)), $mine));
        $this->assertSame($this->sorted([$recent['mundane_id'], $old['mundane_id']]), $this->matchAmong($this->tree($this->leaf('classes_last_n_months', 'is', $classId, 24)), $mine));
        // set semantics: "did not play X in the window" includes players with no sign-ins
        $this->assertSame($this->sorted([$old['mundane_id'], $never['mundane_id']]), $this->matchAmong($this->tree($this->leaf('classes_last_n_months', 'not_in', [$classId], 6)), $mine));
    }

    public function testLastSigninParkUsesLatestSignInWithIdTieBreak(): void
    {
        $p2 = $this->fixture->secondParkIdInKingdom($this->kid, $this->parkId);
        if ($p2 <= 0) {
            $this->markTestSkipped('Needs a second park in the kingdom.');
        }
        $away = $this->player('pe-lsp-away');
        $home = $this->player('pe-lsp-home');
        $tie = $this->player('pe-lsp-tie');
        $this->fixture->insertAttendance($away['mundane_id'], $this->parkId, $this->kid, '2024-01-01');
        $this->fixture->insertAttendance($away['mundane_id'], $p2, $this->kid, '2024-06-01');
        $this->fixture->insertAttendance($home['mundane_id'], $p2, $this->kid, '2024-01-01');
        $this->fixture->insertAttendance($home['mundane_id'], $this->parkId, $this->kid, '2024-06-01');
        // same date: the later attendance row wins
        $this->fixture->insertAttendance($tie['mundane_id'], $this->parkId, $this->kid, '2024-07-07');
        $this->fixture->insertAttendance($tie['mundane_id'], $p2, $this->kid, '2024-07-07');
        $mine = [$away['mundane_id'], $home['mundane_id'], $tie['mundane_id']];

        $this->assertSame($this->sorted([$away['mundane_id'], $tie['mundane_id']]), $this->matchAmong($this->tree($this->leaf('last_signin_park', 'is', $p2)), $mine));
        $this->assertSame([$home['mundane_id']], $this->matchAmong($this->tree($this->leaf('last_signin_park', 'in', [$this->parkId])), $mine));

        $r = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, [], ['persona', 'last_signin_park']));
        $col = array_column($r['Rows'], 'last_signin_park', 'MundaneId');
        $this->assertSame($this->fixture->parkName($p2), $col[$away['mundane_id']]);
        $this->assertSame($this->fixture->parkName($this->parkId), $col[$home['mundane_id']]);
    }

    public function testPlayerSinceIsFirstSignIn(): void
    {
        $early = $this->player('pe-since-early');
        $late = $this->player('pe-since-late');
        $this->fixture->insertAttendance($early['mundane_id'], $this->parkId, $this->kid, '2019-03-01');
        $this->fixture->insertAttendance($early['mundane_id'], $this->parkId, $this->kid, '2024-01-01');
        $this->fixture->insertAttendance($late['mundane_id'], $this->parkId, $this->kid, '2021-05-05');
        $mine = [$early['mundane_id'], $late['mundane_id']];

        $r = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, [], ['persona', 'player_since']));
        $this->assertSame(0, $r['Status']['Status']);
        $col = array_column($r['Rows'], 'player_since', 'MundaneId');
        $this->assertSame('2019-03-01', $col[$early['mundane_id']]);
        $this->assertSame('2021-05-05', $col[$late['mundane_id']]);
        $this->assertSame([$early['mundane_id']], $this->matchAmong($this->tree($this->leaf('player_since', 'lt', '2020-01-01')), $mine));
        // No override column: the spec defines Player since as the first sign-in only.
        $this->assertStringNotContainsString('player_since_override', $this->pe->ColumnSelectSql('player_since', []));
    }

    /** Store a date the app's strict sql_mode would reject (legacy '0000-00-00' / typo rows exist in production). */
    private function forceAttendanceDate(int $attendanceId, string $date): void
    {
        $this->sql("SET STATEMENT sql_mode='' FOR UPDATE " . DB_PREFIX . 'attendance SET date = ? WHERE attendance_id = ?', [$date, $attendanceId]);
    }

    public function testPlayerSinceIgnoresZeroAndPre1988Dates(): void
    {
        $zero = $this->player('pe-since-zero');
        $pre = $this->player('pe-since-pre88');
        $only = $this->player('pe-since-onlyzero');
        $this->forceAttendanceDate($this->fixture->insertAttendance($zero['mundane_id'], $this->parkId, $this->kid, '2001-01-01'), '0000-00-00');
        $this->fixture->insertAttendance($zero['mundane_id'], $this->parkId, $this->kid, '2010-05-05');
        $this->fixture->insertAttendance($pre['mundane_id'], $this->parkId, $this->kid, '1985-03-03');
        $this->fixture->insertAttendance($pre['mundane_id'], $this->parkId, $this->kid, '1994-04-04');
        $this->forceAttendanceDate($this->fixture->insertAttendance($only['mundane_id'], $this->parkId, $this->kid, '2001-01-01'), '0000-00-00');
        $mine = [$zero['mundane_id'], $pre['mundane_id'], $only['mundane_id']];

        $r = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, [], ['persona', 'player_since', 'total_signins']));
        $this->assertSame(0, $r['Status']['Status']);
        $col = array_column($r['Rows'], 'player_since', 'MundaneId');
        $this->assertSame('2010-05-05', $col[$zero['mundane_id']], '0000-00-00 is ignored');
        $this->assertSame('1994-04-04', $col[$pre['mundane_id']], 'pre-1988 dates are ignored, as on the player profile');
        $this->assertNull($col[$only['mundane_id']], 'no valid sign-in: no Player since');
        $this->assertSame(1, array_column($r['Rows'], 'total_signins', 'MundaneId')[$only['mundane_id']], 'sign-in counts are unchanged');

        $this->assertSame([], $this->matchAmong($this->tree($this->leaf('player_since', 'lt', '1990-01-01')), $mine));
        $this->assertSame([], $this->matchAmong($this->tree($this->leaf('player_since', 'lte', '1987-12-31')), $mine));
        $this->assertSame([$pre['mundane_id']], $this->matchAmong($this->tree($this->leaf('player_since', 'between', ['1994-01-01', '1994-12-31'])), $mine));
        // nullable: a player with no valid first sign-in never matches a negated comparison
        $this->assertSame($this->sorted([$zero['mundane_id'], $pre['mundane_id']]), $this->matchAmong($this->tree($this->leaf('player_since', 'ne', '2000-01-01')), $mine));
        // The export writes what Run returns.
        $x = $this->pe->BuildExport($this->req($this->admin['token'], 'Park', $this->parkId, $this->tree($this->leaf('player_since', 'eq', '2010-05-05')), ['persona', 'player_since']));
        $this->assertSame(0, $x['Status']['Status']);
        $sheet = $this->readXlsx($x['Path'])['xl/worksheets/sheet1.xml'] ?? '';
        @unlink($x['Path']);
        $this->assertStringContainsString('2010-05-05', $sheet);
        $this->assertStringNotContainsString('0000-00-00', $sheet);
    }

    public function testLastSigninParkEventSignInWithNoParkIsNoPark(): void
    {
        $p2 = $this->fixture->secondParkIdInKingdom($this->kid, $this->parkId);
        if ($p2 <= 0) {
            $this->markTestSkipped('Needs a second park in the kingdom.');
        }
        $event = $this->player('pe-lsp-event');
        $tie = $this->player('pe-lsp-eventtie');
        $park = $this->player('pe-lsp-park');
        // latest sign-in is an event sign-in with no park (park_id 0)
        $this->fixture->insertAttendance($event['mundane_id'], $p2, $this->kid, '2024-01-01');
        $this->fixture->insertAttendance($event['mundane_id'], 0, $this->kid, '2024-06-01');
        // same day: the park-0 row was entered last, so it wins the tie
        $this->fixture->insertAttendance($tie['mundane_id'], $p2, $this->kid, '2024-07-07');
        $this->fixture->insertAttendance($tie['mundane_id'], 0, $this->kid, '2024-07-07');
        $this->fixture->insertAttendance($park['mundane_id'], $p2, $this->kid, '2024-06-01');
        $mine = [$event['mundane_id'], $tie['mundane_id'], $park['mundane_id']];

        $r = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, [], ['persona', 'last_signin_park']));
        $col = array_column($r['Rows'], 'last_signin_park', 'MundaneId');
        $this->assertNull($col[$event['mundane_id']]);
        $this->assertNull($col[$tie['mundane_id']]);
        $this->assertSame($this->fixture->parkName($p2), $col[$park['mundane_id']]);

        // No park = NULL: negated operands do not match it (spec §3.2 / §3.4), positive ones neither.
        $this->assertSame([], $this->matchAmong($this->tree($this->leaf('last_signin_park', 'not_in', [$p2])), $mine));
        $this->assertSame([$park['mundane_id']], $this->matchAmong($this->tree($this->leaf('last_signin_park', 'not_in', [$this->parkId])), $mine));
        $this->assertSame([$park['mundane_id']], $this->matchAmong($this->tree($this->leaf('last_signin_park', 'is_not', $this->parkId)), $mine));
        $this->assertSame([$park['mundane_id']], $this->matchAmong($this->tree($this->leaf('last_signin_park', 'in', [$p2, $this->parkId])), $mine));
    }

    public function testAwardDateAnyIgnoresUnknownDates(): void
    {
        [$kaId, $awardId] = $this->peerageAward('Knight');
        $zero = $this->player('pe-ad-zero');
        $typo = $this->player('pe-ad-typo');
        $early = $this->player('pe-ad-1985');
        $zw = $this->fixture->insertLadderAward($zero['mundane_id'], $this->parkId, $this->kid, $kaId, $awardId, 0);
        $tw = $this->fixture->insertLadderAward($typo['mundane_id'], $this->parkId, $this->kid, $kaId, $awardId, 0);
        $ew = $this->fixture->insertLadderAward($early['mundane_id'], $this->parkId, $this->kid, $kaId, $awardId, 0);
        $this->sql("SET STATEMENT sql_mode='' FOR UPDATE " . DB_PREFIX . 'awards SET date = ? WHERE awards_id = ?', ['0000-00-00', $zw]);
        $this->sql('UPDATE ' . DB_PREFIX . 'awards SET date = ? WHERE awards_id = ?', ['0201-01-28', $tw]);
        $this->sql('UPDATE ' . DB_PREFIX . 'awards SET date = ? WHERE awards_id = ?', ['1985-01-01', $ew]);
        $mine = [$zero['mundane_id'], $typo['mundane_id'], $early['mundane_id']];

        $this->assertSame([$early['mundane_id']], $this->matchAmong($this->tree($this->leaf('award_date_any', 'lt', '2000-01-01')), $mine));
        $this->assertSame([$early['mundane_id']], $this->matchAmong($this->tree($this->leaf('award_date_any', 'between', ['0001-01-01', '1999-12-31'])), $mine));
        // the award itself still counts
        $r = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, [], ['persona', 'award_count']));
        $this->assertSame(1, array_column($r['Rows'], 'award_count', 'MundaneId')[$zero['mundane_id']]);
    }

    /** Players whose park is the scope park but whose home kingdom is another kingdom. */
    private function otherKingdomId(): int
    {
        $st = $this->fixture->pdo()->prepare('SELECT kingdom_id FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id <> ? ORDER BY kingdom_id LIMIT 1');
        $st->execute([$this->kid]);
        $k = (int) $st->fetchColumn();
        if ($k <= 0) {
            $this->markTestSkipped('Needs a second kingdom.');
        }

        return $k;
    }

    public function testParkScopeIsPinnedToTheParksKingdom(): void
    {
        $home = $this->player('pe-pk-home');
        $stray = $this->player('pe-pk-stray');
        $this->sql('UPDATE ' . DB_PREFIX . 'mundane SET kingdom_id = ? WHERE mundane_id = ?', [$this->otherKingdomId(), $stray['mundane_id']]);
        $expected = (int) $this->fixture->pdo()->query(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'mundane WHERE park_id = ' . $this->parkId . ' AND kingdom_id = ' . $this->kid
        )->fetchColumn();

        $r = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId));
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertContains($home['mundane_id'], $this->ids($r));
        $this->assertNotContains($stray['mundane_id'], $this->ids($r), 'a player of another kingdom is outside the park scope');
        $this->assertSame($expected, $r['Total']);
        $this->assertSame($expected, $r['ScopeTotal'], 'ScopeTotal uses the same scope clause');
        $orTree = ['op' => 'OR', 'children' => [$this->leaf('active', 'is', 'yes'), $this->leaf('active', 'is', 'no')]];
        $this->assertNotContains($stray['mundane_id'], $this->ids($this->exec($this->req($this->admin['token'], 'Park', $this->parkId, $orTree))));

        // Same population as GetPlayerRoster's park scope.
        $roster = array_map(static fn (array $x): int => (int) $x['MundaneId'], $this->roster('Park', $this->parkId, [], $this->admin['token']));
        $this->assertSame($this->sorted(array_values(array_unique($roster))), $this->ids($r));
    }

    public function testParkScopeFailsClosedWhenTheParkHasNoKingdom(): void
    {
        $missing = (int) $this->fixture->pdo()->query('SELECT COALESCE(MAX(park_id), 0) + 1000 FROM ' . DB_PREFIX . 'park')->fetchColumn();
        $r = $this->exec($this->req($this->admin['token'], 'Park', $missing));
        $this->assertNotSame(0, $r['Status']['Status']);
        $this->assertArrayNotHasKey('Rows', $r);
        $this->assertArrayNotHasKey('ScopeTotal', $r);
    }

    public function testNonPositiveOrNonFiniteStatementTimeoutKeepsTheDefault(): void
    {
        foreach ([0.0, -5.0, NAN, INF] as $t) {
            $p = $this->probe();
            $p->timeout = $t;
            // 0 / negative would mean "no limit" to MariaDB, NAN / INF are not SQL at all
            $this->assertSame('SET STATEMENT max_statement_time=' . PopulationExplorer::STATEMENT_TIMEOUT_S . ' FOR ', $p->timeoutClause(), "timeout $t");
            $r = $p->Run($this->req($this->admin['token'], 'Park', $this->parkId));
            $this->assertSame(0, $r['Status']['Status'], "timeout $t: " . json_encode($r['Status']));
        }
        $p = $this->probe();
        $p->timeout = 2.5;
        $this->assertSame('SET STATEMENT max_statement_time=2.5 FOR ', $p->timeoutClause());
    }

    public function testReeveAndCorporaQualifiedParity(): void
    {
        $future = date('Y-m-d', strtotime('+6 months'));
        $past = date('Y-m-d', strtotime('-6 months'));
        $ok = $this->player('pe-q-ok');
        $lapsed = $this->player('pe-q-lapsed');
        $susp = $this->player('pe-q-susp');
        $no = $this->player('pe-q-no');
        $set = 'UPDATE ' . DB_PREFIX . 'mundane SET reeve_qualified = ?, reeve_qualified_until = ?, corpora_qualified = ?, corpora_qualified_until = ?, suspended = ? WHERE mundane_id = ?';
        $this->sql($set, [1, $future, 1, $future, 0, $ok['mundane_id']]);
        $this->sql($set, [1, $past, 1, $past, 0, $lapsed['mundane_id']]);
        $this->sql($set, [1, $future, 1, $future, 1, $susp['mundane_id']]);
        $this->sql($set, [0, null, 0, null, 0, $no['mundane_id']]);
        $mine = [$ok['mundane_id'], $lapsed['mundane_id'], $susp['mundane_id'], $no['mundane_id']];

        foreach (['reeve' => ['GetReeveQualified', 'ReeveQualified'], 'corpora' => ['GetCorporaQualified', 'CorporaQualified']] as $kind => [$fn, $key]) {
            $r = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, $this->tree($this->leaf($kind . '_qualified', 'is', 'yes'))));
            $this->assertSame([$ok['mundane_id']], array_values(array_intersect($this->ids($r), $mine)), $kind);
            unset($_SESSION['is_authorized_mundane_id']);
            $report = (new Report())->$fn(['KingdomId' => 0, 'ParkId' => $this->parkId]);
            $expected = array_map(static fn (array $x): int => (int) $x['MundaneId'], $report[$key] ?? []);
            $this->assertSame($this->sorted(array_values(array_unique($expected))), $this->ids($r), "$kind parity with $fn");

            $noRule = $this->matchAmong($this->tree($this->leaf($kind . '_qualified', 'is', 'no')), $mine);
            $this->assertSame($this->sorted([$lapsed['mundane_id'], $susp['mundane_id'], $no['mundane_id']]), $noRule, $kind);
        }
    }

    public function testBannedParityAndHomeKingdom(): void
    {
        $banned = $this->player('pe-banned');
        $clean = $this->player('pe-clean');
        $this->sql('UPDATE ' . DB_PREFIX . 'mundane SET penalty_box = 1 WHERE mundane_id = ?', [$banned['mundane_id']]);
        $this->sql('UPDATE ' . DB_PREFIX . 'mundane SET penalty_box = 0 WHERE mundane_id = ?', [$clean['mundane_id']]);
        $mine = [$banned['mundane_id'], $clean['mundane_id']];

        $r = $this->exec($this->req($this->admin['token'], 'Park', $this->parkId, $this->tree($this->leaf('banned', 'is', 'yes'))));
        $this->assertSame([$banned['mundane_id']], array_values(array_intersect($this->ids($r), $mine)));
        $expected = array_map(static fn (array $x): int => (int) $x['MundaneId'], $this->roster('Park', $this->parkId, ['Banned' => true], $this->admin['token']));
        $this->assertSame($this->sorted($expected), $this->ids($r), 'banned parity with GetPlayerRoster');
        $this->assertSame([$clean['mundane_id']], $this->matchAmong($this->tree($this->leaf('banned', 'is', 'no')), $mine));

        // Home kingdom within the kingdom scope: every scoped player whose home kingdom is the scope id.
        $k = $this->exec($this->req($this->admin['token'], 'Kingdom', $this->kid, $this->tree($this->leaf('home_kingdom', 'is', $this->kid))));
        $this->assertSame(0, $k['Status']['Status']);
        $want = (int) $this->fixture->pdo()->query('SELECT COUNT(*) FROM ' . DB_PREFIX . 'mundane WHERE kingdom_id = ' . $this->kid)->fetchColumn();
        $this->assertSame($want, $k['Total']);
        $this->assertContains($banned['mundane_id'], $this->ids($k));
        $this->assertSame([], $this->matchAmong($this->tree($this->leaf('home_kingdom', 'not_in', [$this->kid])), $mine, 'Kingdom'));
    }

    public function testMasterhoodParityWithMastersReport(): void
    {
        [[$kaId, $awardId]] = $this->peerageAwards('Master', 1);
        $master = $this->player('pe-master');
        $plain = $this->player('pe-master-plain');
        $this->sql('UPDATE ' . DB_PREFIX . 'mundane SET active = 1 WHERE mundane_id IN (?, ?)', [$master['mundane_id'], $plain['mundane_id']]);
        $this->fixture->insertLadderAward($master['mundane_id'], $this->parkId, $this->kid, $kaId, $awardId, 0);

        unset($_SESSION['is_authorized_mundane_id']);
        $report = (new Report())->PlayerAwards([
            'KingdomId' => $this->kid, 'ParkId' => 0, 'IncludeKnights' => 0, 'IncludeMasters' => 1,
        ]);
        $this->assertSame(0, $report['Status']['Status']);
        $expected = array_values(array_unique(array_map(static fn (array $a): int => (int) $a['MundaneId'], $report['Awards'])));
        sort($expected);
        $this->assertContains($master['mundane_id'], $expected);

        $r = $this->exec($this->req(
            $this->admin['token'],
            'Kingdom',
            $this->kid,
            $this->tree($this->leaf('masterhood', 'is', 'yes'), $this->leaf('active', 'is', 'yes'))
        ));
        $this->assertSame(0, $r['Status']['Status']);
        $this->assertSame($expected, $this->ids($r));
        $this->assertNotContains($plain['mundane_id'], $this->ids($r));
    }

    public function testAwardDateAnyRanges(): void
    {
        [$kaId, $awardId] = $this->peerageAward('Knight');
        $a = $this->player('pe-ad-a');
        $b = $this->player('pe-ad-b');
        $aw = $this->fixture->insertLadderAward($a['mundane_id'], $this->parkId, $this->kid, $kaId, $awardId, 0);
        $bw = $this->fixture->insertLadderAward($b['mundane_id'], $this->parkId, $this->kid, $kaId, $awardId, 0);
        $this->sql('UPDATE ' . DB_PREFIX . 'awards SET date = ? WHERE awards_id = ?', ['2020-06-15', $aw]);
        $this->sql('UPDATE ' . DB_PREFIX . 'awards SET date = ? WHERE awards_id = ?', ['2023-06-15', $bw]);
        $mine = [$a['mundane_id'], $b['mundane_id']];

        $this->assertSame([$b['mundane_id']], $this->matchAmong($this->tree($this->leaf('award_date_any', 'gt', '2020-06-15')), $mine));
        $this->assertSame($this->sorted($mine), $this->matchAmong($this->tree($this->leaf('award_date_any', 'gte', '2020-06-15')), $mine));
        $this->assertSame([$a['mundane_id']], $this->matchAmong($this->tree($this->leaf('award_date_any', 'lte', '2020-06-15')), $mine));
        $this->assertSame([$b['mundane_id']], $this->matchAmong($this->tree($this->leaf('award_date_any', 'between', ['2023-01-01', '2023-06-15'])), $mine));
    }
}
