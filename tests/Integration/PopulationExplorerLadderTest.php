<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/PopulationExplorerProbe.php';

/**
 * Population Explorer "Ladder Award Ranks" criteria (spec §4): the per-scope ladder
 * registry, the rank rule GREATEST(MAX(rank), COUNT(*)) over held awards, rank 0 for
 * players with none, and parity with Report::GetLadderAwardGrid.
 */
final class PopulationExplorerLadderTest extends TestCase
{
    private const ROSE = 21;
    private const LION = 23;
    private const WALKER = 31;

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
        $this->admin = $this->fixture->createPlayer($this->parkId, 'pe-lad-admin');
        $this->fixture->insertGlobalAdmin($this->admin['mundane_id']);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    private function req(string $token, string $type, int $id, array $tree = [], array $cols = ['persona']): array
    {
        return ['Token' => $token, 'ScopeType' => $type, 'ScopeId' => $id, 'Tree' => $tree, 'Columns' => $cols];
    }

    private function leaf(string $c, string $o, $v): array
    {
        return ['c' => $c, 'o' => $o, 'v' => $v];
    }

    private function tree(array ...$leaves): array
    {
        return ['op' => 'AND', 'children' => $leaves];
    }

    private function exec(PopulationExplorer $pe, array $request): array
    {
        unset($_SESSION['is_authorized_mundane_id']);

        return $pe->Run($request);
    }

    /** @return list<int> */
    private function ids(array $result): array
    {
        $ids = array_map(static fn (array $r): int => (int) $r['MundaneId'], $result['Rows'] ?? []);
        sort($ids);

        return $ids;
    }

    /** @param list<int> $ids @return list<int> */
    private function sorted(array $ids): array
    {
        sort($ids);

        return array_values($ids);
    }

    /** Ids among $mine the tree returns in the park scope (as admin). */
    private function matchAmong(array $tree, array $mine, ?PopulationExplorer $pe = null): array
    {
        $r = $this->exec($pe ?? $this->pe, $this->req($this->admin['token'], 'Park', $this->parkId, $tree));
        $this->assertSame(0, $r['Status']['Status'], json_encode($r['Status']));

        return $this->sorted(array_values(array_intersect($this->ids($r), $mine)));
    }

    private function sql(string $sql, array $args = []): void
    {
        $this->fixture->pdo()->prepare($sql)->execute($args);
    }

    private function player(string $suffix): array
    {
        return $this->fixture->createPlayer($this->parkId, $suffix);
    }

    /** @param list<int> $ranks */
    private function grant(int $mundaneId, int $kaId, int $awardId, array $ranks): array
    {
        $ids = [];
        foreach ($ranks as $rank) {
            $ids[] = $this->fixture->insertLadderAward($mundaneId, $this->parkId, $this->kid, $kaId, $awardId, $rank);
        }

        return $ids;
    }

    /** The test kingdom's own kingdomaward row for a global award (inserted when missing). */
    private function kaFor(int $awardId): int
    {
        $st = $this->fixture->pdo()->prepare(
            'SELECT kingdomaward_id FROM ' . DB_PREFIX . 'kingdomaward WHERE kingdom_id = ? AND award_id = ? ORDER BY kingdomaward_id LIMIT 1'
        );
        $st->execute([$this->kid, $awardId]);
        $id = (int) $st->fetchColumn();

        return $id > 0 ? $id : $this->fixture->insertKingdomAward($this->kid, $awardId, $this->fixture->awardGlobalName($awardId));
    }

    private function otherKingdomId(): int
    {
        $st = $this->fixture->pdo()->prepare(
            'SELECT kingdom_id FROM ' . DB_PREFIX . "kingdom WHERE kingdom_id <> ? AND parent_kingdom_id <> ? AND active = 'Active' ORDER BY kingdom_id LIMIT 1"
        );
        $st->execute([$this->kid, $this->kid]);
        $k = (int) $st->fetchColumn();
        if ($k <= 0) {
            $this->markTestSkipped('Needs a second kingdom.');
        }

        return $k;
    }

    public function testScopeLaddersAreTheFifteenGlobalLaddersWithKingdomNames(): void
    {
        $globals = array_map('intval', $this->fixture->pdo()->query(
            'SELECT award_id FROM ' . DB_PREFIX . 'award WHERE is_ladder = 1 AND award_id <> ' . self::WALKER . ' ORDER BY award_id'
        )->fetchAll(PDO::FETCH_COLUMN));
        $this->assertCount(15, $globals);

        // Rename the kingdom's Smith for this test only (the row is shared seed data).
        $smithKa = $this->kaFor(22);
        $smithName = (string) $this->fixture->pdo()->query('SELECT name FROM ' . DB_PREFIX . 'kingdomaward WHERE kingdomaward_id = ' . $smithKa)->fetchColumn();
        $this->fixture->renameKingdomAward($smithKa, 'Order of the Test Smiths');
        $lionKa = $this->kaFor(self::LION);
        $lionName = (string) $this->fixture->pdo()->query('SELECT name FROM ' . DB_PREFIX . 'kingdomaward WHERE kingdomaward_id = ' . $lionKa)->fetchColumn();
        $this->fixture->renameKingdomAward($lionKa, $this->fixture->awardGlobalName(self::LION));
        try {
            $this->assertScopeLadders($globals);
        } finally {
            $this->fixture->renameKingdomAward($smithKa, $smithName);
            $this->fixture->renameKingdomAward($lionKa, $lionName);
        }
    }

    /** @param list<int> $globals */
    private function assertScopeLadders(array $globals): void
    {
        foreach ([['Kingdom', $this->kid], ['Park', $this->parkId]] as [$type, $id]) {
            $l = $this->pe->LoadLadders($type, $id);
            $aIds = [];
            foreach ($l as $cid => $def) {
                if (preg_match('/^ladder_a(\d+)$/', $cid, $m)) {
                    $aIds[] = (int) $m[1];
                    $this->assertSame('award', $def['kind']);
                    $this->assertSame((int) $m[1], $def['id']);
                }
            }
            sort($aIds);
            $this->assertSame($globals, $aIds, "$type $id: the Ladder Award Grid's set, Walker excluded");
            $this->assertArrayNotHasKey('ladder_a' . self::WALKER, $l);
            $this->assertSame('Order of the Test Smiths', $l['ladder_a22']['label'], "$type $id uses the kingdom's own name");
            $this->assertSame($this->fixture->awardGlobalName(self::LION), $l['ladder_a' . self::LION]['label'], 'not renamed: the global name');
            $labels = array_column($l, 'label');
            $sorted = $labels;
            usort($sorted, 'strnatcasecmp');
            $this->assertSame($sorted, $labels, 'sorted by label');
        }

        $other = $this->otherKingdomId();
        $this->assertSame($this->fixture->awardGlobalName(22), $this->pe->LoadLadders('Kingdom', $other)['ladder_a22']['label'], 'another kingdom keeps the global name');

        // The page registry carries them as number criteria in their own group.
        $reg = $this->pe->PublicRegistry('Park', $this->parkId);
        $this->assertSame('Order of the Test Smiths', $reg['criteria']['ladder_a22']['label']);
        $this->assertSame('Ladder Award Ranks', $reg['criteria']['ladder_a22']['group']);
        $this->assertSame('number', $reg['criteria']['ladder_a22']['type']);
    }

    public function testLadderRankIsMaxRankOrHeldCountAndZeroWithoutAwards(): void
    {
        $ka = $this->kaFor(self::ROSE);
        $ranked = $this->player('pe-lad-ranked');     // ranks 1, 2, 5 -> 5
        $unranked = $this->player('pe-lad-unranked'); // three rank-0 rows -> 3
        $revoked = $this->player('pe-lad-revoked');   // ranks 1, 2 + a revoked 6 -> 2
        $aliased = $this->player('pe-lad-alias');     // one aliased row of rank 4 -> 4
        $stripped = $this->player('pe-lad-strip');    // only a stripped row -> 0
        $none = $this->player('pe-lad-none');         // nothing -> 0
        $mine = [$ranked['mundane_id'], $unranked['mundane_id'], $revoked['mundane_id'], $aliased['mundane_id'], $stripped['mundane_id'], $none['mundane_id']];

        $this->grant($ranked['mundane_id'], $ka, self::ROSE, [1, 2, 5]);
        $this->grant($unranked['mundane_id'], $ka, self::ROSE, [0, 0, 0]);
        [, , $rv] = $this->grant($revoked['mundane_id'], $ka, self::ROSE, [1, 2, 6]);
        $this->sql('UPDATE ' . DB_PREFIX . 'awards SET revoked = 1 WHERE awards_id = ?', [$rv]);
        // Aliased: the row itself names a non-ladder award; alias_award_id makes it a Rose.
        $plainAward = (int) $this->fixture->pdo()->query('SELECT award_id FROM ' . DB_PREFIX . 'award WHERE is_ladder = 0 ORDER BY award_id LIMIT 1')->fetchColumn();
        [$al] = $this->grant($aliased['mundane_id'], 0, $plainAward, [4]);
        $this->sql('UPDATE ' . DB_PREFIX . 'awards SET alias_award_id = ? WHERE awards_id = ?', [self::ROSE, $al]);
        [$st] = $this->grant($stripped['mundane_id'], $ka, self::ROSE, [7]);
        $this->sql('UPDATE ' . DB_PREFIX . 'awards SET stripped_from = ? WHERE awards_id = ?', [$st, $st]);

        $c = 'ladder_a' . self::ROSE;
        [$r5, $r3, $r2, $r4, $s0, $n0] = $mine;
        $this->assertSame([$r5], $this->matchAmong($this->tree($this->leaf($c, 'eq', 5)), $mine), 'MAX(rank)');
        $this->assertSame([$r3], $this->matchAmong($this->tree($this->leaf($c, 'eq', 3)), $mine), 'COUNT(*) of unranked rows');
        $this->assertSame([$r2], $this->matchAmong($this->tree($this->leaf($c, 'eq', 2)), $mine), 'revoked row excluded');
        $this->assertSame([$r4], $this->matchAmong($this->tree($this->leaf($c, 'eq', 4)), $mine), 'aliased row counts');
        $this->assertSame($this->sorted([$s0, $n0]), $this->matchAmong($this->tree($this->leaf($c, 'eq', 0)), $mine), 'stripped / none = rank 0');
        $this->assertSame($this->sorted([$r3, $r2, $r4, $s0, $n0]), $this->matchAmong($this->tree($this->leaf($c, 'ne', 5)), $mine), 'ne includes rank 0');
        $this->assertSame($this->sorted([$r2, $s0, $n0]), $this->matchAmong($this->tree($this->leaf($c, 'lt', 3)), $mine), 'lt includes rank 0');
        $this->assertSame($this->sorted([$r3, $r2, $r4]), $this->matchAmong($this->tree($this->leaf($c, 'between', [2, 4])), $mine));
        $this->assertSame($this->sorted([$r5, $r3, $r2, $r4]), $this->matchAmong($this->tree($this->leaf($c, 'gte', 1)), $mine));
        $this->assertSame([$r5], $this->matchAmong($this->tree($this->leaf($c, 'gt', 4)), $mine));
        $this->assertSame($this->sorted([$r2, $s0, $n0]), $this->matchAmong($this->tree($this->leaf($c, 'lte', 2)), $mine));

        // Another ladder is independent: everyone here is rank 0 in the Lion.
        $this->assertSame($this->sorted($mine), $this->matchAmong($this->tree($this->leaf('ladder_a' . self::LION, 'eq', 0)), $mine));
        // Three ladder rules ANDed.
        $this->assertSame([$r5], $this->matchAmong($this->tree(
            $this->leaf($c, 'gte', 5),
            $this->leaf('ladder_a' . self::LION, 'eq', 0),
            $this->leaf('ladder_a22', 'lt', 1)
        ), $mine));
    }

    public function testKingdomOnlyLadderMatchesOnItsKingdomAwardAndIsScoped(): void
    {
        $other = $this->otherKingdomId();
        $mineKa = $this->fixture->insertKingdomOnlyAward($this->kid, 'Order of the Test Ladder');
        $otherKa = $this->fixture->insertKingdomOnlyAward($other, 'Order of the Far Ladder');
        $probe = new PopulationExplorerProbe();
        $probe->kingdomLadderIds = [$mineKa, $otherKa];

        $l = $probe->LoadLadders('Park', $this->parkId);
        $this->assertArrayHasKey('ladder_k' . $mineKa, $l);
        $this->assertSame(['label' => 'T10RPT Order of the Test Ladder', 'kind' => 'kingdomaward', 'id' => $mineKa], $l['ladder_k' . $mineKa]);
        $this->assertArrayNotHasKey('ladder_k' . $otherKa, $l, "another kingdom's ladder is out of scope");
        $this->assertArrayHasKey('ladder_k' . $otherKa, $probe->LoadLadders('Kingdom', $other));
        $this->assertArrayHasKey('ladder_k' . $mineKa, $probe->PublicRegistry('Kingdom', $this->kid)['criteria']);

        $two = $this->player('pe-lad-ko-two');   // two unranked rows -> 2
        $five = $this->player('pe-lad-ko-five'); // ranks 0 and 5 -> 5
        $none = $this->player('pe-lad-ko-none');
        $this->grant($two['mundane_id'], $mineKa, 0, [0, 0]);
        $this->grant($five['mundane_id'], $mineKa, 0, [0, 5]);
        $mine = [$two['mundane_id'], $five['mundane_id'], $none['mundane_id']];
        $c = 'ladder_k' . $mineKa;

        $this->assertSame([$two['mundane_id']], $this->matchAmong($this->tree($this->leaf($c, 'eq', 2)), $mine, $probe));
        $this->assertSame([$five['mundane_id']], $this->matchAmong($this->tree($this->leaf($c, 'eq', 5)), $mine, $probe));
        $this->assertSame([$none['mundane_id']], $this->matchAmong($this->tree($this->leaf($c, 'eq', 0)), $mine, $probe));
        $this->assertSame($this->sorted([$two['mundane_id'], $none['mundane_id']]), $this->matchAmong($this->tree($this->leaf($c, 'ne', 5)), $mine, $probe));

        // Out of scope: rejected on that rule.
        $r = $this->exec($probe, $this->req($this->admin['token'], 'Park', $this->parkId, $this->tree($this->leaf('ladder_k' . $otherKa, 'gte', 1))));
        $this->assertSame(ServiceErrorIds::InvalidParameter, $r['Status']['Status']);
        $this->assertSame(PopulationExplorer::LADDER_UNAVAILABLE_MESSAGE, $r['Status']['Detail']);
        $this->assertSame([0], $r['RulePath']);
        $this->assertSame(0, $this->exec($probe, $this->req($this->admin['token'], 'Kingdom', $other, $this->tree($this->leaf('ladder_k' . $otherKa, 'gte', 1))))['Status']['Status']);
    }

    public function testDuplicateKingdomOnlyLadderNamesAreDisambiguated(): void
    {
        $child = (int) $this->fixture->pdo()->query(
            'SELECT kingdom_id FROM ' . DB_PREFIX . 'kingdom WHERE parent_kingdom_id = ' . $this->kid . " AND active = 'Active' ORDER BY kingdom_id LIMIT 1"
        )->fetchColumn();
        if ($child <= 0) {
            $this->markTestSkipped('Needs a principality of the test kingdom.');
        }
        $kingdomLib = Ork3::$Lib->kingdom;
        $cache = new ReflectionProperty($kingdomLib, 'statsIncludesPrincipalityCache');
        $cache->setAccessible(true);
        $saved = $cache->getValue($kingdomLib);
        $cache->setValue($kingdomLib, [$this->kid => true] + $saved);
        try {
            $this->assertContains($child, array_map('intval', $kingdomLib->GetStatsKingdomIds($this->kid)));
            $a = $this->fixture->insertKingdomOnlyAward($this->kid, 'Order of the Twin');
            $b = $this->fixture->insertKingdomOnlyAward($child, 'Order of the Twin');
            $u = $this->fixture->insertKingdomOnlyAward($child, 'Order of the Single');
            $probe = new PopulationExplorerProbe();
            $probe->kingdomLadderIds = [$a, $b, $u];

            $l = $probe->LoadLadders('Kingdom', $this->kid);
            $abbr = function (int $k): string {
                return (string) $this->fixture->pdo()->query('SELECT abbreviation FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = ' . $k)->fetchColumn();
            };
            $this->assertSame('T10RPT Order of the Twin (' . $abbr($this->kid) . ')', $l['ladder_k' . $a]['label']);
            $this->assertSame('T10RPT Order of the Twin (' . $abbr($child) . ')', $l['ladder_k' . $b]['label']);
            $this->assertSame('T10RPT Order of the Single', $l['ladder_k' . $u]['label'], 'unique names are left alone');
            // A park scope sees only its own kingdom's ladder, so no suffix.
            $this->assertSame('T10RPT Order of the Twin', $probe->LoadLadders('Park', $this->parkId)['ladder_k' . $a]['label']);
        } finally {
            $cache->setValue($kingdomLib, $saved);
        }
    }

    public function testLadderRulesThroughShareLinkAndExport(): void
    {
        $ka = $this->kaFor(self::ROSE);
        $p = $this->player('pe-lad-export');
        $this->grant($p['mundane_id'], $ka, self::ROSE, [3]);
        $plain = $this->player('pe-lad-plain');
        $tree = $this->tree($this->leaf('ladder_a' . self::ROSE, 'gte', 3));

        $model = new Model_Reports();
        unset($_SESSION['is_authorized_mundane_id']);
        $d = $model->population_decode_link(PopulationExplorer::EncodeLink(['tree' => $tree, 'columns' => ['persona']]), $plain['token'], 'Park', $this->parkId);
        $this->assertTrue($d['ok'], (string) $d['error']);
        $this->assertSame(3, $d['state']['tree']['children'][0]['v']);
        unset($_SESSION['is_authorized_mundane_id']);
        $bad = $model->population_decode_link(PopulationExplorer::EncodeLink(['tree' => $this->tree($this->leaf('ladder_a' . self::WALKER, 'gte', 1)), 'columns' => []]), $plain['token'], 'Park', $this->parkId);
        $this->assertFalse($bad['ok']);
        $this->assertStringContainsString(PopulationExplorer::LADDER_UNAVAILABLE_MESSAGE, (string) $bad['error']);

        unset($_SESSION['is_authorized_mundane_id']);
        $x = $this->pe->BuildExport($this->req($plain['token'], 'Park', $this->parkId, $tree));
        $this->assertSame(0, $x['Status']['Status'], json_encode($x['Status']));
        @unlink($x['Path']);
        unset($_SESSION['is_authorized_mundane_id']);
        $x = $this->pe->BuildExport($this->req($plain['token'], 'Park', $this->parkId, $this->tree($this->leaf('ladder_a' . self::ROSE, 'gte', -1))));
        $this->assertSame(ServiceErrorIds::InvalidParameter, $x['Status']['Status']);
        $this->assertSame([0], $x['RulePath']);
        $this->assertArrayNotHasKey('Path', $x);

        $r = $this->exec($this->pe, $this->req($plain['token'], 'Park', $this->parkId, $tree));
        $this->assertContains($p['mundane_id'], $this->ids($r), 'a non-officer may use ladder criteria');
    }

    /**
     * Parity with the Ladder Award Grid on the global ladders. Like for like: the Grid
     * lists active players whose home kingdom is the kingdom (m.kingdom_id = K) and
     * reads ladder rows through ka.award_id; the fixture rows here have no alias and
     * are not stripped, the two cases where the rules legitimately differ.
     */
    public function testLadderRanksMatchTheLadderAwardGrid(): void
    {
        $roseKa = $this->kaFor(self::ROSE);
        $lionKa = $this->kaFor(self::LION);
        $players = [];
        foreach ([[[1, 2], []], [[0, 0, 0], [6]], [[], [1]], [[9], [0, 0]]] as $i => [$rose, $lion]) {
            $pl = $this->player('pe-lad-grid-' . $i);
            $this->sql('UPDATE ' . DB_PREFIX . 'mundane SET active = 1 WHERE mundane_id = ?', [$pl['mundane_id']]);
            $this->grant($pl['mundane_id'], $roseKa, self::ROSE, $rose);
            $this->grant($pl['mundane_id'], $lionKa, self::LION, $lion);
            $players[] = $pl['mundane_id'];
        }
        $inactive = $this->player('pe-lad-grid-inactive');
        $this->sql('UPDATE ' . DB_PREFIX . 'mundane SET active = 0 WHERE mundane_id = ?', [$inactive['mundane_id']]);
        $this->grant($inactive['mundane_id'], $roseKa, self::ROSE, [4]);

        unset($_SESSION['is_authorized_mundane_id']);
        $grid = (new Report())->GetLadderAwardGrid(['KingdomId' => $this->kid, 'Token' => $this->admin['token']]);
        $this->assertArrayHasKey(self::ROSE, $grid['LadderAwards']);
        $this->assertArrayHasKey(self::LION, $grid['LadderAwards']);

        $compared = 0;
        foreach ([self::ROSE, self::LION] as $aid) {
            $byRank = [];
            foreach ($grid['GridRows'] as $row) {
                $rank = $row['Awards'][$aid]['Rank'] ?? null;
                if ($rank !== null) {
                    $byRank[(int) $rank][] = (int) $row['MundaneId'];
                }
            }
            $this->assertNotEmpty($byRank);
            $like = [$this->leaf('active', 'is', 'yes'), $this->leaf('home_kingdom', 'is', $this->kid)];
            $all = [];
            foreach ($byRank as $rank => $ids) {
                $r = $this->exec($this->pe, $this->req($this->admin['token'], 'Kingdom', $this->kid, $this->tree($this->leaf('ladder_a' . $aid, 'eq', $rank), ...$like)));
                $this->assertSame($this->sorted($ids), $this->ids($r), "ladder $aid rank $rank");
                $all = array_merge($all, $ids);
                $compared++;
            }
            $r = $this->exec($this->pe, $this->req($this->admin['token'], 'Kingdom', $this->kid, $this->tree($this->leaf('ladder_a' . $aid, 'gte', 1), ...$like)));
            $this->assertSame($this->sorted($all), $this->ids($r), "ladder $aid: everyone the Grid lists");
            $this->assertNotContains($inactive['mundane_id'], $all, 'the Grid lists active players only');
        }
        $this->assertGreaterThanOrEqual(5, $compared);
        // The fixture ranks, as the Grid states them.
        $rose = [];
        foreach ($grid['GridRows'] as $row) {
            if (in_array((int) $row['MundaneId'], $players, true)) {
                $rose[(int) $row['MundaneId']] = $row['Awards'][self::ROSE]['Rank'] ?? null;
            }
        }
        $this->assertSame([$players[0] => 2, $players[1] => 3, $players[2] => null, $players[3] => 9], $rose);
    }
}
