<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

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
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'mundane WHERE park_id = ' . $this->parkId
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

    public function testDuesPaidParityWithRoster(): void
    {
        $paid = $this->player('pe-dues-paid');
        $expired = $this->player('pe-dues-expired');
        $this->player('pe-dues-none');
        $this->fixture->insertDuesSplit($paid['mundane_id'], $this->parkId, $this->kid, date('Y-m-d', strtotime('+1 year')));
        $this->fixture->insertDuesSplit($expired['mundane_id'], $this->parkId, $this->kid, date('Y-m-d', strtotime('-1 year')));

        $r = $this->exec($this->req(
            $this->admin['token'],
            'Park',
            $this->parkId,
            $this->tree($this->leaf('dues_paid', 'is', 'yes'))
        ));
        $this->assertSame(0, $r['Status']['Status']);

        $expected = array_map(
            static fn (array $x): int => (int) $x['MundaneId'],
            $this->roster('Park', $this->parkId, ['DuesPaid' => true], $this->admin['token'])
        );
        sort($expected);
        $this->assertSame([$paid['mundane_id']], array_values(array_intersect($expected, [$paid['mundane_id'], $expired['mundane_id']])));
        $this->assertSame($expected, $this->ids($r));

        // "no" is the complement within the park
        $no = $this->exec($this->req(
            $this->admin['token'],
            'Park',
            $this->parkId,
            $this->tree($this->leaf('dues_paid', 'is', 'no'))
        ));
        $this->assertContains($expired['mundane_id'], $this->ids($no));
        $this->assertNotContains($paid['mundane_id'], $this->ids($no));
    }

    public function testDuesFromAnotherParkAccountDoNotCount(): void
    {
        $p2 = $this->fixture->secondParkIdInKingdom($this->kid, $this->parkId);
        if ($p2 <= 0) {
            $this->markTestSkipped('Needs a second park in the kingdom.');
        }
        $pl = $this->player('pe-dues-other');
        $this->fixture->insertDuesSplit($pl['mundane_id'], $p2, $this->kid, date('Y-m-d', strtotime('+1 year')));

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

    public function testParkOfficerCannotReadOtherPark(): void
    {
        $p2 = $this->fixture->secondParkIdInKingdom($this->kid, $this->parkId);
        if ($p2 <= 0) {
            $this->markTestSkipped('Needs a second park in the kingdom.');
        }
        $officer = $this->player('pe-pk-officer');
        // Real park-officer grants carry kingdom_id = 0; a row with both set would also
        // satisfy the kingdom lookup in HasAuthority and prove nothing here.
        $this->fixture->insertScopedAuth($officer['mundane_id'], $this->parkId, 0, AUTH_CREATE);

        $own = $this->exec($this->req($officer['token'], 'Park', $this->parkId));
        $this->assertSame(0, $own['Status']['Status']);
        $this->assertNotEmpty($own['Rows']);

        $other = $this->exec($this->req($officer['token'], 'Park', $p2));
        $this->assertSame(ServiceErrorIds::NoAuthorization, $other['Status']['Status']);
        $this->assertArrayNotHasKey('Rows', $other);

        $kingdom = $this->exec($this->req($officer['token'], 'Kingdom', $this->kid));
        $this->assertSame(ServiceErrorIds::NoAuthorization, $kingdom['Status']['Status']);
        $this->assertArrayNotHasKey('Rows', $kingdom);
    }

    public function testKingdomOfficerScopedToKingdom(): void
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

        // a park inside the kingdom is allowed through kingdom EDIT? (Park needs park CREATE) -> denied, matches Report gate
        $otherKingdom = (int) $this->fixture->pdo()->query(
            'SELECT kingdom_id FROM ' . DB_PREFIX . "kingdom WHERE kingdom_id <> {$this->kid} AND parent_kingdom_id <> {$this->kid} AND active = 'Active' LIMIT 1"
        )->fetchColumn();
        $this->assertGreaterThan(0, $otherKingdom);
        $denied = $this->exec($this->req($officer['token'], 'Kingdom', $otherKingdom));
        $this->assertSame(ServiceErrorIds::NoAuthorization, $denied['Status']['Status']);
        $this->assertArrayNotHasKey('Rows', $denied);

        // every returned row belongs to the kingdom's stats ids
        $in = array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($this->kid));
        $idList = implode(',', array_map(static fn (array $r): int => (int) $r['MundaneId'], $own['Rows']));
        $bad = (int) $this->fixture->pdo()->query(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . "mundane WHERE mundane_id IN ($idList) AND kingdom_id NOT IN (" . implode(',', $in) . ')'
        )->fetchColumn();
        $this->assertSame(0, $bad);
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
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'mundane WHERE park_id = ' . $this->parkId
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
}
