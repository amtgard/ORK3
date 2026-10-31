<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for ladder grid report assembly (T-RPT-01).
 */
final class LadderGridTest extends TestCase
{
    private ReportsFixture $fixture;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = ReportsFixture::create();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testLadderGridAssembly(): void
    {
        $kid = $this->fixture->kingdomWithLadderAwards();
        $report = new Report();
        $assembly = $report->GetLadderAwardGrid($this->kingdomGridRequest($kid));

        $this->assertArrayHasKey('ScopeName', $assembly);
        $this->assertArrayHasKey('LadderAwards', $assembly);
        $this->assertArrayHasKey('GridRows', $assembly);
        $this->assertNotEmpty($assembly['LadderAwards']);

        foreach ($assembly['GridRows'] as $row) {
            $this->assertArrayHasKey('MundaneId', $row);
            $this->assertArrayHasKey('Persona', $row);
            $this->assertArrayHasKey('Awards', $row);
            foreach ($row['Awards'] as $awardId => $cell) {
                $this->assertArrayHasKey('Rank', $cell);
                $this->assertArrayHasKey('IsMaster', $cell);
                $this->assertContains($awardId, array_keys($assembly['LadderAwards']));
            }
        }
    }

    public function testParkOnlyGridUsesGlobalLadderColumns(): void
    {
        $kid = $this->fixture->kingdomWithLadderAwards();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $ladder = $this->fixture->firstLadderAward($kid);
        $globalName = $this->fixture->awardGlobalName($ladder['award_id']);
        $alias = 'Park-only ladder alias ' . substr(md5((string) microtime(true)), 0, 8);
        $this->fixture->renameKingdomAward($ladder['kingdomaward_id'], $alias);

        $editor = $this->fixture->createPlayer($parkId, 'ladder-park-only');
        $this->fixture->insertScopedAuth($editor['mundane_id'], $parkId, $kid, AUTH_CREATE);
        unset($_SESSION['is_authorized_mundane_id']);

        $report = new Report();
        $assembly = $report->GetLadderAwardGrid([
            'KingdomId' => 0,
            'ParkId' => $parkId,
            'Token' => $editor['token'],
        ]);

        $this->assertArrayHasKey($ladder['award_id'], $assembly['LadderAwards']);
        $this->assertSame($globalName, $assembly['LadderAwards'][$ladder['award_id']]['Name']);
        $this->assertNotSame($alias, $assembly['LadderAwards'][$ladder['award_id']]['Name']);
    }

    public function testKnightGroupAliasing(): void
    {
        $knightGroupMap = [
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

        $kid = $this->fixture->kingdomWithLadderAwards();
        $report = new Report();
        $assembly = $report->GetLadderAwardGrid($this->kingdomGridRequest($kid));

        foreach ($assembly['LadderAwards'] as $col) {
            if (isset($knightGroupMap[$col['Name']])) {
                $this->assertSame($knightGroupMap[$col['Name']], $col['KnightGroup']);
            }
        }
    }

    public function testMasterMapUnification(): void
    {
        $controllerMap = [
            21 => [1], 22 => [2], 23 => [3], 24 => [4], 25 => [5],
            26 => [6], 27 => [12], 239 => [240], 243 => [244],
        ];
        $domainMap = Award::GetLadderMasterMap();

        foreach ($controllerMap as $ladderId => $masterIds) {
            $this->assertArrayHasKey($ladderId, $domainMap);
            $this->assertSame($masterIds, $domainMap[$ladderId]['MasterAwardIds']);
        }

        $this->assertGreaterThan(count($controllerMap), count($domainMap));
    }

    // ---- Smart Planning Grid: recency columns and New Players ----

    private function rowFor(array $assembly, int $mid): ?array
    {
        foreach ($assembly['GridRows'] as $row) {
            if ((int) $row['MundaneId'] === $mid) {
                return $row;
            }
        }

        return null;
    }

    /** @return array{0:int,1:int,2:array,3:array} kingdom, park, ladder, player */
    private function recencyPlayer(string $tag): array
    {
        $kid = $this->fixture->kingdomWithLadderAwards();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $ladder = $this->fixture->firstLadderAward($kid);
        $p = $this->fixture->createPlayer($parkId, $tag);
        $this->fixture->setPlayerState($p['mundane_id'], 1, 0);

        return [$kid, $parkId, $ladder, $p];
    }

    private function nonLadderKa(int $kid): array
    {
        $aid = (int) $this->fixture->pdo()->query(
            'SELECT award_id FROM ' . DB_PREFIX . 'award WHERE is_ladder = 0 AND award_id > 0 ORDER BY award_id LIMIT 1'
        )->fetchColumn();

        return [$this->fixture->insertKingdomAward($kid, $aid, 'T10RPT Plain Award'), $aid];
    }

    public function testGridRowsCarryLastAwardAndLastSignIn(): void
    {
        [$kid, $parkId, $ladder, $p] = $this->recencyPlayer('rec-a');
        $mid = $p['mundane_id'];
        $this->fixture->insertAwardOn($mid, $parkId, $kid, $ladder['kingdomaward_id'], $ladder['award_id'], 3, '2026-04-06');
        [$plainKa, $plainAid] = $this->nonLadderKa($kid);
        $this->fixture->insertAwardOn($mid, $parkId, $kid, $plainKa, $plainAid, 0, '2026-05-10');
        $this->fixture->insertAttendance($mid, $parkId, $kid, '2026-03-01');
        $this->fixture->insertAttendance($mid, $parkId, $kid, '2026-06-15');

        $row = $this->rowFor((new Report())->GetLadderAwardGrid($this->kingdomGridRequest($kid)), $mid);
        $this->assertNotNull($row);
        $this->assertSame('2026-05-10', $row['LastAwardDate']);
        $this->assertSame('T10RPT Plain Award', $row['LastAwardLabel']);
        $this->assertSame('2026-06-15', $row['LastSignIn']);
    }

    public function testCellCarriesMostRecentAwardDate(): void
    {
        [$kid, $parkId, $ladder, $p] = $this->recencyPlayer('cell-date-a');
        $mid = $p['mundane_id'];
        $this->fixture->insertAwardOn($mid, $parkId, $kid, $ladder['kingdomaward_id'], $ladder['award_id'], 1, '2025-01-01');
        $this->fixture->insertAwardOn($mid, $parkId, $kid, $ladder['kingdomaward_id'], $ladder['award_id'], 2, '2026-04-06');

        $row = $this->rowFor((new Report())->GetLadderAwardGrid($this->kingdomGridRequest($kid)), $mid);
        $this->assertNotNull($row);
        $this->assertSame('2026-04-06', $row['Awards'][$ladder['award_id']]['Date']);
    }

    public function testCellDateIgnoresPlaceholderAndRevokedRows(): void
    {
        [$kid, $parkId, $ladder, $p] = $this->recencyPlayer('cell-date-b');
        $mid = $p['mundane_id'];
        $this->fixture->insertAwardOn($mid, $parkId, $kid, $ladder['kingdomaward_id'], $ladder['award_id'], 2, '2026-01-02');
        $this->fixture->insertAwardOn($mid, $parkId, $kid, $ladder['kingdomaward_id'], $ladder['award_id'], 5, '2026-02-02', 1);
        $this->fixture->insertAwardOn($mid, $parkId, $kid, $ladder['kingdomaward_id'], $ladder['award_id'], 4, '0000-00-00');

        $row = $this->rowFor((new Report())->GetLadderAwardGrid($this->kingdomGridRequest($kid)), $mid);
        $this->assertNotNull($row);
        $this->assertSame('2026-01-02', $row['Awards'][$ladder['award_id']]['Date']);
    }

    public function testLastAwardIgnoresRevokedAndPlaceholderDates(): void
    {
        [$kid, $parkId, $ladder, $p] = $this->recencyPlayer('rec-b');
        $mid = $p['mundane_id'];
        $this->fixture->insertAwardOn($mid, $parkId, $kid, $ladder['kingdomaward_id'], $ladder['award_id'], 2, '2026-01-02');
        $this->fixture->insertAwardOn($mid, $parkId, $kid, $ladder['kingdomaward_id'], $ladder['award_id'], 5, '2026-02-02', 1);
        $this->fixture->insertAwardOn($mid, $parkId, $kid, $ladder['kingdomaward_id'], $ladder['award_id'], 4, '0000-00-00');

        $row = $this->rowFor((new Report())->GetLadderAwardGrid($this->kingdomGridRequest($kid)), $mid);
        $this->assertSame('2026-01-02', $row['LastAwardDate']);
        $this->assertStringEndsWith(' 2', $row['LastAwardLabel']);
    }

    public function testLastAwardLabelStripsOrderPrefixAndAppendsRank(): void
    {
        [$kid, $parkId, $ladder, $p] = $this->recencyPlayer('rec-c');
        $mid = $p['mundane_id'];
        $ka = $this->fixture->insertKingdomAward($kid, $ladder['award_id'], 'Order of the Rose');
        $this->fixture->insertAwardOn($mid, $parkId, $kid, $ka, $ladder['award_id'], 1, '2026-04-06');

        $row = $this->rowFor((new Report())->GetLadderAwardGrid($this->kingdomGridRequest($kid)), $mid);
        $this->assertSame('Rose 1', $row['LastAwardLabel']);
    }

    public function testLastAwardTieUsesHighestAwardRow(): void
    {
        [$kid, $parkId, $ladder, $p] = $this->recencyPlayer('rec-d');
        $mid = $p['mundane_id'];
        $this->fixture->insertAwardOn($mid, $parkId, $kid, $ladder['kingdomaward_id'], $ladder['award_id'], 1, '2026-04-06');
        [$plainKa, $plainAid] = $this->nonLadderKa($kid);
        $this->fixture->insertAwardOn($mid, $parkId, $kid, $plainKa, $plainAid, 0, '2026-04-06');

        $row = $this->rowFor((new Report())->GetLadderAwardGrid($this->kingdomGridRequest($kid)), $mid);
        $this->assertSame('T10RPT Plain Award', $row['LastAwardLabel']);
    }

    public function testPlayerWithNoSignInHasNullLastSignIn(): void
    {
        [$kid, $parkId, $ladder, $p] = $this->recencyPlayer('rec-e');
        $this->fixture->insertAwardOn($p['mundane_id'], $parkId, $kid, $ladder['kingdomaward_id'], $ladder['award_id'], 1, '2026-04-06');

        $row = $this->rowFor((new Report())->GetLadderAwardGrid($this->kingdomGridRequest($kid)), $p['mundane_id']);
        $this->assertNull($row['LastSignIn']);
    }

    private function newPlayers(int $kid, int $parkId = 0): array
    {
        $req = $this->kingdomGridRequest($kid);
        if ($parkId > 0) {
            $req['KingdomId'] = 0;
            $req['ParkId'] = $parkId;
        }
        $r = (new Report())->GetLadderGridNewPlayers($req);
        $this->assertSame(0, $r['Status']['Status']);

        return array_column($r['Players'], null, 'MundaneId');
    }

    private function daysAgo(int $n): string
    {
        return date('Y-m-d', strtotime("-{$n} days"));
    }

    public function testNewPlayersRequiresTwoDistinctSignInDates(): void
    {
        $kid = $this->fixture->kingdomWithLadderAwards();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $other = $this->fixture->secondParkIdInKingdom($kid, $parkId);
        $mk = function (string $t) use ($parkId) {
            $p = $this->fixture->createPlayer($parkId, $t);
            $this->fixture->setPlayerState($p['mundane_id'], 1, 0);

            return $p['mundane_id'];
        };
        $two = $mk('np-two');
        $one = $mk('np-one');
        $same = $mk('np-same');
        $old = $mk('np-old');
        $this->fixture->insertAttendance($two, $parkId, $kid, $this->daysAgo(5));
        $this->fixture->insertAttendance($two, $parkId, $kid, $this->daysAgo(12));
        $this->fixture->insertAttendance($one, $parkId, $kid, $this->daysAgo(5));
        $this->fixture->insertAttendance($same, $parkId, $kid, $this->daysAgo(5));
        if ($other > 0) {
            $this->fixture->insertAttendance($same, $other, $kid, $this->daysAgo(5));
        } else {
            $this->fixture->insertAttendance($same, $parkId, $kid, $this->daysAgo(5));
        }
        $this->fixture->insertAttendance($old, $parkId, $kid, $this->daysAgo(95));
        $this->fixture->insertAttendance($old, $parkId, $kid, $this->daysAgo(100));

        $got = $this->newPlayers($kid);
        $this->assertArrayHasKey($two, $got);
        $this->assertSame($this->daysAgo(5), $got[$two]['LastSignIn']);
        $this->assertArrayNotHasKey($one, $got);
        $this->assertArrayNotHasKey($same, $got);
        $this->assertArrayNotHasKey($old, $got);
    }

    public function testNewPlayersExcludesAnyoneOnTheMainGrid(): void
    {
        [$kid, $parkId, $ladder, $holder] = $this->recencyPlayer('np-holder');
        $plain = $this->fixture->createPlayer($parkId, 'np-plain');
        $this->fixture->setPlayerState($plain['mundane_id'], 1, 0);
        [$plainKa, $plainAid] = $this->nonLadderKa($kid);
        $this->fixture->insertLadderAward($holder['mundane_id'], $parkId, $kid, $ladder['kingdomaward_id'], $ladder['award_id'], 1);
        $this->fixture->insertAwardOn($plain['mundane_id'], $parkId, $kid, $plainKa, $plainAid, 0, '2026-01-01');
        foreach ([$holder, $plain] as $p) {
            $this->fixture->insertAttendance($p['mundane_id'], $parkId, $kid, $this->daysAgo(3));
            $this->fixture->insertAttendance($p['mundane_id'], $parkId, $kid, $this->daysAgo(9));
        }

        $got = $this->newPlayers($kid);
        $this->assertArrayNotHasKey($holder['mundane_id'], $got);
        $this->assertArrayHasKey($plain['mundane_id'], $got);
        $this->assertSame('T10RPT Plain Award', $got[$plain['mundane_id']]['LastAwardLabel']);
    }

    public function testNewPlayersRespectsScopeAndActive(): void
    {
        $kid = $this->fixture->kingdomWithLadderAwards();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $other = $this->fixture->secondParkIdInKingdom($kid, $parkId);
        if ($other <= 0) {
            $this->markTestSkipped('Needs a second park in the kingdom.');
        }
        $in = $this->fixture->createPlayer($parkId, 'np-in');
        $out = $this->fixture->createPlayer($other, 'np-out');
        $inactive = $this->fixture->createPlayer($parkId, 'np-inactive');
        $this->fixture->setPlayerState($in['mundane_id'], 1, 0);
        $this->fixture->setPlayerState($out['mundane_id'], 1, 0);
        $this->fixture->setPlayerState($inactive['mundane_id'], 0, 0);
        foreach ([[$in, $parkId], [$out, $other], [$inactive, $parkId]] as [$p, $pk]) {
            $this->fixture->insertAttendance($p['mundane_id'], $pk, $kid, $this->daysAgo(3));
            $this->fixture->insertAttendance($p['mundane_id'], $pk, $kid, $this->daysAgo(9));
        }

        $got = $this->newPlayers($kid, $parkId);
        $this->assertArrayHasKey($in['mundane_id'], $got);
        $this->assertArrayNotHasKey($out['mundane_id'], $got);
        $this->assertArrayNotHasKey($inactive['mundane_id'], $got);
        $this->assertArrayHasKey($out['mundane_id'], $this->newPlayers($kid));
    }

    public function testNewPlayersIncludesSuspendedFlag(): void
    {
        $kid = $this->fixture->kingdomWithLadderAwards();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $p = $this->fixture->createPlayer($parkId, 'np-susp');
        $this->fixture->setPlayerState($p['mundane_id'], 1, 1);
        $this->fixture->insertAttendance($p['mundane_id'], $parkId, $kid, $this->daysAgo(3));
        $this->fixture->insertAttendance($p['mundane_id'], $parkId, $kid, $this->daysAgo(9));

        $got = $this->newPlayers($kid);
        $this->assertSame(1, $got[$p['mundane_id']]['Suspended']);
        $this->assertSame($parkId, $got[$p['mundane_id']]['ParkId']);
    }

    public function testNewPlayersRequiresValidSession(): void
    {
        $kid = $this->fixture->kingdomWithLadderAwards();
        unset($_SESSION['is_authorized_mundane_id']);
        $r = (new Report())->GetLadderGridNewPlayers(['KingdomId' => $kid, 'ParkId' => 0, 'Token' => 'not-a-token']);
        $this->assertSame(BadToken()['Status'], $r['Status']['Status']);
        $this->assertSame([], $r['Players']);
    }

    public function testAssemblyReportsResolvedKingdomForParkOnlyRequest(): void
    {
        $kid = $this->fixture->kingdomWithLadderAwards();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $req = $this->kingdomGridRequest($kid);
        $req['KingdomId'] = 0;
        $req['ParkId'] = $parkId;
        $assembly = (new Report())->GetLadderAwardGrid($req);
        $this->assertSame($kid, $assembly['KingdomId']);
        $this->assertSame($kid, (new Report())->GetLadderAwardGrid($this->kingdomGridRequest($kid))['KingdomId']);
    }

    /**
     * @return array{KingdomId: int, ParkId: int, Token: string}
     */
    private function kingdomGridRequest(int $kid): array
    {
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $editor = $this->fixture->createPlayer($parkId, 'ladder-grid');
        $this->fixture->insertScopedAuth($editor['mundane_id'], 0, $kid, AUTH_CREATE);
        unset($_SESSION['is_authorized_mundane_id']);

        return [
            'KingdomId' => $kid,
            'ParkId' => 0,
            'Token' => $editor['token'],
        ];
    }
}
