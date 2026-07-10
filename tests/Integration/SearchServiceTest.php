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
        $familyIds = Ork3::$Lib->kingdom->GetFamilyKingdomIds($kid);

        $results = $this->searchService->ScopedPlayerSearch([
            'Query'     => $player['persona'],
            'Scope'     => 'kingdom_own',
            'KingdomId' => $kid,
        ]);
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
        $results = $this->searchService->ScopedPlayerSearch([
            'Query'  => $outside['persona'],
            'Scope'  => 'park_exclude',
            'ParkId' => $parkA,
        ]);

        $parkIds = array_column($results, 'ParkId');
        $this->assertNotContains($parkA, $parkIds);
        $this->assertContains($outside['mundane_id'], array_column($results, 'MundaneId'));
    }

    public function testEventProximityOrder(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkA = $this->fixture->parkIdInKingdom($kid);
        $parkB = $this->fixture->secondParkIdInKingdom($kid, $parkA);
        if ($parkB <= 0) {
            $this->markTestSkipped('Need two parks for event proximity ordering.');
        }

        $event = $this->fixture->createEvent($parkA, 'prox');
        $local = $this->fixture->createPlayer($parkA, 'evlocal');
        $remote = $this->fixture->createPlayer($parkB, 'evremote');
        $term = 'T11SRC ev';

        $results = $this->searchService->ScopedPlayerSearch([
            'Query'   => $term,
            'Scope'   => 'event_prioritized',
            'EventId' => $event['event_id'],
        ]);
        $this->assertGreaterThanOrEqual(2, count($results));
        $this->assertSame($local['mundane_id'], $results[0]['MundaneId']);
    }

    public function testAdminGlobalSearch(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $player = $this->fixture->createPlayer($parkId, 'global');

        $results = $this->searchService->ScopedPlayerSearch([
            'Query'  => substr($player['persona'], -8),
            'Scope'  => 'global',
            'Limit'  => 20,
            'Format' => 'admin',
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
