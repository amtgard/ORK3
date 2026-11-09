<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for SearchService and frontend search SQL (T-SRC-01, T-SRC-02, T-ADM-10, T-KNA-06, T-PRA-01, T-EVA-06; T-LIB-12–14 residual lib paths).
 */
final class SearchServiceTest extends TestCase
{
    private SearchFixture $fixture;

    private SearchService $searchService;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = SearchFixture::create();
        $this->searchService = new SearchService();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testMagicSearchAbbrevPrefix(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $kAbbr = $this->fixture->kingdomAbbreviation($kid);
        $pAbbr = $this->fixture->parkAbbreviation($parkId);
        $player = $this->fixture->createPlayer($parkId, 'abbr');

        [$term, $resolvedKid, $resolvedPid] = $this->searchService->magic_search(
            "{$kAbbr}:{$pAbbr} {$player['persona']}",
            0,
            0,
        );

        $this->assertSame($player['persona'], $term);
        $this->assertSame($kid, $resolvedKid);
        $this->assertSame($parkId, $resolvedPid);
    }

    public function testUniversalMultiCategory(): void
    {
        $budgets = $this->searchService->UniversalBudgets('');
        $this->assertSame([4, 3, 2, 3], array_values($budgets));

        $focusPlayer = $this->searchService->UniversalBudgets('player');
        $this->assertSame(10, $focusPlayer['player']);
        $this->assertSame(0, $focusPlayer['park']);
    }

    public function testPunctFolding(): void
    {
        $folded = $this->searchService->FoldPunctText("Wolf\u{2019}s Run");
        $this->assertSame("Wolf's Run", $folded);

        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $persona = 'T11SRC Wolf' . "\u{2019}" . 's ' . bin2hex(random_bytes(3));
        $player = $this->fixture->createPlayer($parkId, 'punct', $persona);

        $results = $this->searchService->UniversalSearch([
            'Query' => "Wolf's",
            'Kid'   => $kid,
            'Pid'   => $parkId,
        ]);
        $ids = array_column($results['players'], 'id');
        $this->assertContains($player['mundane_id'], $ids);
    }

    public function testOrkAdminRestrictedBypass(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $admin = $this->fixture->createPlayer($parkId, 'orkadmin');
        $this->fixture->insertGlobalAdmin($admin['mundane_id']);

        $restricted = $this->fixture->createPlayer($parkId, 'restricted');
        $this->fixture->setRestricted($restricted['mundane_id'], true);
        $this->pdoUpdateMundaneName($restricted['mundane_id'], 'Secret', 'RestrictedName');

        $adminResults = $this->searchService->Player('MUNDANE', 'RestrictedName', 15, $kid, null, null, $admin['token']);
        $publicResults = $this->searchService->Player('MUNDANE', 'RestrictedName', 15, $kid);

        $adminIds = array_column($adminResults, 'MundaneId');
        $this->assertContains($restricted['mundane_id'], $adminIds);
        $this->assertSame([], $publicResults);
    }

    public function testKingdomFamilyScope(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $player = $this->fixture->createPlayer($parkId, 'family');
        $familyIds = array_map('intval', Ork3::$Lib->kingdom->GetFamilyKingdomIds($kid));

        $results = $this->searchService->RankedPlayers([
            'q'          => $player['persona'],
            'kingdomId'  => $kid,
            'restrictTo' => 'kingdom',
        ]);
        $this->assertContains($player['mundane_id'], array_column($results, 'MundaneId'));
        foreach ($results as $row) {
            $this->assertContains((int) $row['KingdomId'], $familyIds);
        }
    }

    public function testParkExcludeScope(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkA = $this->fixture->parkIdInKingdom($kid);
        $parkB = $this->fixture->secondParkIdInKingdom($kid, $parkA);
        if ($parkB <= 0) {
            $this->markTestSkipped('Need two parks in kingdom for exclude scope test.');
        }

        $outside = $this->fixture->createPlayer($parkB, 'outside');
        $results = $this->searchService->RankedPlayers([
            'q'             => $outside['persona'],
            'excludeParkId' => $parkA,
        ]);

        $parkIds = array_map('intval', array_column($results, 'ParkId'));
        $this->assertNotContains($parkA, $parkIds);
        $this->assertContains($outside['mundane_id'], array_column($results, 'MundaneId'));
    }

    public function testParkRingOrder(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkA = $this->fixture->parkIdInKingdom($kid);
        $parkB = $this->fixture->secondParkIdInKingdom($kid, $parkA);
        if ($parkB <= 0) {
            $this->markTestSkipped('Need two parks for park ring ordering.');
        }

        $marker = 'Ring' . bin2hex(random_bytes(3));
        // Remote persona sorts first alphabetically, so only the ring can put local first.
        $remote = $this->fixture->createPlayer($parkB, 'rgremote', 'T11SRC ' . $marker . ' A');
        $local = $this->fixture->createPlayer($parkA, 'rglocal', 'T11SRC ' . $marker . ' B');

        $results = $this->searchService->RankedPlayers([
            'q'      => $marker,
            'parkId' => $parkA,
        ]);
        $ids = array_column($results, 'MundaneId');
        $this->assertContains($remote['mundane_id'], $ids);
        $this->assertSame($local['mundane_id'], $results[0]['MundaneId']);
    }

    public function testMultiWordQueryMatchesAnyOrderAndPartialWords(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $m = bin2hex(random_bytes(3));
        $player = $this->fixture->createPlayer($parkId, 'multiword', "Tobias{$m} Malcom{$m} Ardente{$m}");

        // Every typed word must appear, in any order; a skipped middle word is fine.
        foreach (["Tobias{$m} Ard", "ardente{$m} tob", "Malcom{$m}", "ARDENTE{$m} tobias{$m} mal"] as $q) {
            $ids = array_column($this->searchService->RankedPlayers(['q' => $q, 'limit' => 100]), 'MundaneId');
            $this->assertContains($player['mundane_id'], $ids, "query '{$q}' should find the player");
        }
        // A word that is not in the name excludes it.
        $ids = array_column($this->searchService->RankedPlayers(['q' => "Tobias{$m} Zzqx", 'limit' => 100, 'fuzzy' => false]), 'MundaneId');
        $this->assertNotContains($player['mundane_id'], $ids);
    }

    public function testExactPersonaRanksAboveMidWordMatch(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $m = bin2hex(random_bytes(3));
        // "Abrin…" sorts first alphabetically, so only match quality can put the exact one first.
        $mid = $this->fixture->createPlayer($parkId, 'rankmid', "Abrin{$m}");
        $exact = $this->fixture->createPlayer($parkId, 'rankexact', "Brin{$m}");

        $results = $this->searchService->RankedPlayers(['q' => "brin{$m}", 'parkId' => $parkId]);
        $ids = array_column($results, 'MundaneId');
        $this->assertContains($mid['mundane_id'], $ids);
        $this->assertSame($exact['mundane_id'], $results[0]['MundaneId']);
    }

    public function testTypoToleranceFindsOneEditAwayAsCloseMatch(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $m = bin2hex(random_bytes(3));
        $player = $this->fixture->createPlayer($parkId, 'typo', "Witherbloom{$m}");

        // Swapped pair ("re" for "er"): no strict match, so it comes back as a close match.
        $rows = $this->searchService->RankedPlayers(['q' => "Withrebloom{$m}", 'limit' => 16]);
        $hit = array_values(array_filter($rows, fn ($r) => $r['MundaneId'] === $player['mundane_id']));
        $this->assertCount(1, $hit, 'one-edit typo should surface the player');
        $this->assertSame(1, $hit[0]['Fuzzy']);

        // Two edits away is not tolerated.
        $ids = array_column($this->searchService->RankedPlayers(['q' => "Wthrebloom{$m}", 'limit' => 16]), 'MundaneId');
        $this->assertNotContains($player['mundane_id'], $ids);

        // Fuzzy can be switched off by callers.
        $ids = array_column($this->searchService->RankedPlayers(['q' => "Withrebloom{$m}", 'limit' => 16, 'fuzzy' => false]), 'MundaneId');
        $this->assertNotContains($player['mundane_id'], $ids);
    }

    public function testRankedPlayersKdPrefixOrdersActiveBeforeInactive(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $kAbbr = $this->fixture->kingdomAbbreviation($kid);
        $pAbbr = $this->fixture->parkAbbreviation($parkId);
        $marker = 'C28Sort' . bin2hex(random_bytes(3));

        // Inactive persona sorts first alphabetically, so only the tier can put active first.
        $active = $this->fixture->createPlayer($parkId, 'c28act', 'T11SRC ' . $marker . ' Zactive');
        $inactive = $this->fixture->createPlayer($parkId, 'c28ina', 'T11SRC ' . $marker . ' Ainactive');
        $suspended = $this->fixture->createPlayer($parkId, 'c28sus', 'T11SRC ' . $marker . ' Suspended');
        $this->pdoUpdateMundaneMembership($inactive['mundane_id'], active: 0, suspended: 0);
        $this->pdoUpdateMundaneMembership($suspended['mundane_id'], active: 1, suspended: 1);

        $kdResults = $this->searchService->RankedPlayers([
            'q'     => "{$kAbbr}: {$marker}",
            'limit' => 50,
        ]);
        $this->assertRankedMembershipOrder($kdResults, $active, $inactive, $suspended);
        foreach ($kdResults as $row) {
            $this->assertSame($kid, (int) $row['KingdomId']);
        }

        $kdpkResults = $this->searchService->RankedPlayers([
            'q'     => "{$kAbbr}:{$pAbbr} {$marker}",
            'limit' => 50,
        ]);
        $this->assertRankedMembershipOrder($kdpkResults, $active, $inactive, $suspended);
        foreach ($kdpkResults as $row) {
            $this->assertSame($parkId, (int) $row['ParkId']);
        }
    }

    public function testGlobalSearchRespectsLimit(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $player = $this->fixture->createPlayer($parkId, 'global');

        $results = $this->searchService->RankedPlayers([
            'q'     => $player['persona'],
            'limit' => 20,
        ]);
        $this->assertLessThanOrEqual(20, count($results));
        $this->assertContains($player['mundane_id'], array_column($results, 'MundaneId'));
    }

    public function testUnitActivityCounts(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $unit = $this->fixture->createUnit('activity');
        $player = $this->fixture->createPlayer($parkId, 'unitmem');
        $this->fixture->addPlayerToUnit($unit['unit_id'], $player['mundane_id']);
        $this->fixture->insertUnitAttendance($player['mundane_id'], $parkId, $kid);

        $counts = $this->searchService->GetUnitActivityCounts([$unit['unit_id']]);
        $this->assertArrayHasKey($unit['unit_id'], $counts);
        $this->assertGreaterThanOrEqual(1, $counts[$unit['unit_id']]);
    }

    public function testUniversalSearchExcludesRetiredUnits(): void
    {
        $marker = 'C16Unit' . bin2hex(random_bytes(3));
        $active = $this->fixture->createUnit('c16act');
        $retired = $this->fixture->createUnit('c16ret');

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->prepare('UPDATE ' . DB_PREFIX . 'unit SET name = ? WHERE unit_id = ?')
            ->execute([$marker . ' Active', $active['unit_id']]);
        $pdo->prepare('UPDATE ' . DB_PREFIX . "unit SET name = ?, active = 'Retired' WHERE unit_id = ?")
            ->execute([$marker . ' Retired', $retired['unit_id']]);

        $results = $this->searchService->UniversalSearch(['Query' => $marker]);
        $ids = array_column($results['units'], 'id');
        $this->assertContains($active['unit_id'], $ids);
        $this->assertNotContains($retired['unit_id'], $ids);
    }

    /**
     * Active (tier 0) ranks before inactive (tier 1); a suspended player is hidden from an
     * anonymous (no-token) viewer.
     *
     * @param list<array<string, mixed>> $results
     * @param array{mundane_id: int} $active
     * @param array{mundane_id: int} $inactive
     * @param array{mundane_id: int} $suspended
     */
    private function assertRankedMembershipOrder(
        array $results,
        array $active,
        array $inactive,
        array $suspended,
    ): void {
        $indexById = [];
        foreach ($results as $i => $row) {
            $indexById[(int) $row['MundaneId']] = $i;
        }
        $this->assertArrayHasKey($active['mundane_id'], $indexById);
        $this->assertArrayHasKey($inactive['mundane_id'], $indexById);
        $this->assertArrayNotHasKey($suspended['mundane_id'], $indexById);
        $this->assertLessThan($indexById[$inactive['mundane_id']], $indexById[$active['mundane_id']]);
    }

    private function pdoUpdateMundaneMembership(int $mundaneId, int $active, int $suspended): void
    {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $stmt = $pdo->prepare('UPDATE ' . DB_PREFIX . 'mundane SET active = ?, suspended = ? WHERE mundane_id = ?');
        $stmt->execute([$active, $suspended, $mundaneId]);
    }

    private function pdoUpdateMundaneName(int $mundaneId, string $given, string $surname): void
    {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $stmt = $pdo->prepare('UPDATE ' . DB_PREFIX . 'mundane SET given_name = ?, surname = ? WHERE mundane_id = ?');
        $stmt->execute([$given, $surname, $mundaneId]);
    }
}
