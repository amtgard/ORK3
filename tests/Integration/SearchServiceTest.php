<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for SearchService and frontend search SQL (T-SRC-01, T-SRC-02, T-ADM-10, T-KNA-06, T-PRA-01, T-EVA-06).
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
        $budgets = $this->mirrorUniversalBudgets('');
        $this->assertSame([4, 3, 2, 3], array_values($budgets));

        $focusPlayer = $this->mirrorUniversalBudgets('player');
        $this->assertSame(10, $focusPlayer['player']);
        $this->assertSame(0, $focusPlayer['park']);
    }

    public function testPunctFolding(): void
    {
        $folded = $this->mirrorPunctFold("Wolf\u{2019}s Run");
        $this->assertSame("Wolf's Run", $folded);

        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $persona = 'T11SRC Wolf' . "\u{2019}" . 's ' . bin2hex(random_bytes(3));
        $player = $this->fixture->createPlayer($parkId, 'punct', $persona);

        $mirrorHits = $this->mirrorUniversalPlayerSearch("Wolf's", $kid, $parkId);
        $ids = array_column($mirrorHits, 'id');
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

        $results = $this->mirrorKingdomPlayerSearch($kid, $player['persona'], 'own', 0);
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
        $results = $this->mirrorParkPlayerSearch($parkA, $outside['persona'], 'exclude');

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

        $results = $this->mirrorEventPlayerSearch($event['event_id'], $term);
        $this->assertGreaterThanOrEqual(2, count($results));
        $this->assertSame($local['mundane_id'], $results[0]['MundaneId']);
    }

    public function testAdminGlobalSearch(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $player = $this->fixture->createPlayer($parkId, 'global');

        $results = $this->mirrorAdminGlobalSearch(substr($player['persona'], -8));
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

        $counts = $this->mirrorUnitActivityCounts([$unit['unit_id']]);
        $this->assertArrayHasKey($unit['unit_id'], $counts);
        $this->assertGreaterThanOrEqual(1, $counts[$unit['unit_id']]);
    }

    /**
     * @return array{player: int, park: int, kingdom: int, unit: int}
     */
    private function mirrorUniversalBudgets(string $focus): array
    {
        return [
            'player' => $focus === 'player' ? 10 : ($focus ? 0 : 4),
            'park' => $focus === 'park' ? 10 : ($focus ? 0 : 3),
            'kingdom' => $focus === 'kingdom' ? 10 : ($focus ? 0 : 2),
            'unit' => $focus === 'unit' ? 10 : ($focus ? 0 : 3),
        ];
    }

    private function mirrorPunctFold(string $text): string
    {
        $punctFolds = [
            "\u{2019}" => "'", "\u{2018}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2014}" => '-', "\u{2013}" => '-',
            "\u{00A0}" => ' ', "\u{02DC}" => '~',
        ];

        return strtr($text, $punctFolds);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function mirrorUniversalPlayerSearch(string $term, int $kid, int $pid): array
    {
        global $DB;
        $term = str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $this->mirrorPunctFold($term));
        $foldText = function (string $col) use ($term): string {
            $sqlLit = fn (string $s): string => "'" . str_replace("'", "''", $s) . "'";
            $punctFolds = [
                "\u{2019}" => "'", "\u{2018}" => "'",
                "\u{201C}" => '"', "\u{201D}" => '"',
                "\u{2014}" => '-', "\u{2013}" => '-',
                "\u{00A0}" => ' ', "\u{02DC}" => '~',
            ];
            foreach ($punctFolds as $from => $to) {
                $col = 'REPLACE(' . $col . ', ' . $sqlLit($from) . ', ' . $sqlLit($to) . ')';
            }

            return $col;
        };

        $playerWhere = 'm.active = 1 AND m.suspended = 0 AND LENGTH(m.persona) > 0
            AND (' . $foldText('m.persona') . " LIKE '%{$term}%')";
        if ($kid > 0) {
            $playerWhere .= " AND m.kingdom_id = {$kid}";
        }
        if ($pid > 0) {
            $playerWhere .= " AND m.park_id = {$pid}";
        }

        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT m.mundane_id, m.persona FROM ' . DB_PREFIX . "mundane m WHERE {$playerWhere} LIMIT 10"
        );
        $rows = [];
        while ($rs && $rs->Next()) {
            $rows[] = ['id' => (int) $rs->mundane_id, 'name' => $rs->persona];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mirrorKingdomPlayerSearch(int $kid, string $persona, string $scope, int $parkId): array
    {
        global $DB;
        $term = str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $persona);
        if ($scope === 'exclude') {
            $kingdomClause = "AND m.kingdom_id != {$kid}";
            $parkClause = $parkId > 0 ? "AND m.park_id = {$parkId}" : '';
        } else {
            $familyIds = implode(',', array_map('intval', Ork3::$Lib->kingdom->GetFamilyKingdomIds($kid)));
            $kingdomClause = "AND m.kingdom_id IN ({$familyIds})";
            $parkClause = $parkId > 0 ? "AND m.park_id = {$parkId}" : '';
        }

        $sql = "
            SELECT m.mundane_id, m.persona, m.park_id AS m_park_id, m.kingdom_id AS m_kingdom_id
            FROM " . DB_PREFIX . "mundane m
            WHERE LENGTH(m.persona) > 0 AND m.suspended = 0 AND m.active = 1
              {$kingdomClause} {$parkClause}
              AND (m.persona LIKE '%{$term}%')
            ORDER BY m.persona LIMIT 15";
        $DB->Clear();
        $rs = $DB->DataSet($sql);
        $results = [];
        while ($rs && $rs->Next()) {
            $results[] = [
                'MundaneId' => (int) $rs->mundane_id,
                'Persona' => $rs->persona,
                'ParkId' => (int) $rs->m_park_id,
                'KingdomId' => (int) $rs->m_kingdom_id,
            ];
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mirrorParkPlayerSearch(int $parkId, string $persona, string $scope): array
    {
        global $DB;
        $term = str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $persona);
        $parkClause = $scope === 'exclude' ? "AND m.park_id != {$parkId}" : "AND m.park_id = {$parkId}";

        $sql = "
            SELECT m.mundane_id, m.persona, m.park_id AS m_park_id
            FROM " . DB_PREFIX . "mundane m
            WHERE LENGTH(m.persona) > 0 AND m.suspended = 0 AND m.active = 1
              {$parkClause}
              AND (m.persona LIKE '%{$term}%')
            ORDER BY m.persona LIMIT 15";
        $DB->Clear();
        $rs = $DB->DataSet($sql);
        $results = [];
        while ($rs && $rs->Next()) {
            $results[] = [
                'MundaneId' => (int) $rs->mundane_id,
                'Persona' => $rs->persona,
                'ParkId' => (int) $rs->m_park_id,
            ];
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mirrorEventPlayerSearch(int $eventId, string $term): array
    {
        global $DB;
        $term = str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $term);
        $DB->Clear();
        $evRow = $DB->DataSet('SELECT park_id, kingdom_id FROM ' . DB_PREFIX . "event WHERE event_id = {$eventId} LIMIT 1");
        $evParkId = ($evRow && $evRow->Next()) ? (int) $evRow->park_id : 0;
        $evKingdomId = ($evRow && isset($evRow->kingdom_id)) ? (int) $evRow->kingdom_id : 0;

        $DB->Clear();
        $rs = $DB->DataSet(
            "SELECT m.mundane_id, m.persona, m.park_id AS m_park_id, m.kingdom_id AS m_kingdom_id
             FROM " . DB_PREFIX . "mundane m
             WHERE m.suspended = 0 AND m.active = 1 AND LENGTH(m.persona) > 0
               AND (m.persona LIKE '%{$term}%')
             ORDER BY CASE
               WHEN m.park_id = {$evParkId} AND {$evParkId} > 0 THEN 0
               WHEN m.kingdom_id = {$evKingdomId} AND {$evKingdomId} > 0 THEN 1
               ELSE 2 END, m.persona
             LIMIT 15"
        );
        $results = [];
        while ($rs && $rs->Next()) {
            $results[] = [
                'MundaneId' => (int) $rs->mundane_id,
                'Persona' => $rs->persona,
                'ParkId' => (int) $rs->m_park_id,
                'KingdomId' => (int) $rs->m_kingdom_id,
            ];
        }

        return $results;
    }

    /**
     * @return list<array{MundaneId: int, Persona: string}>
     */
    private function mirrorAdminGlobalSearch(string $term): array
    {
        global $DB;
        $term = str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $term);
        $DB->Clear();
        $rs = $DB->DataSet(
            "SELECT m.mundane_id, m.persona
             FROM " . DB_PREFIX . "mundane m
             WHERE m.suspended = 0 AND m.active = 1 AND LENGTH(m.persona) > 0
               AND (m.persona LIKE '%{$term}%'
                 OR m.given_name LIKE '%{$term}%'
                 OR m.surname LIKE '%{$term}%'
                 OR m.username LIKE '%{$term}%')
             ORDER BY m.persona LIMIT 20"
        );
        $results = [];
        while ($rs && $rs->Next()) {
            $results[] = ['MundaneId' => (int) $rs->mundane_id, 'Persona' => $rs->persona];
        }

        return $results;
    }

    /**
     * @param list<int> $unitIds
     * @return array<int, int>
     */
    private function mirrorUnitActivityCounts(array $unitIds): array
    {
        global $DB;
        if ($unitIds === []) {
            return [];
        }
        $in = implode(',', array_map('intval', $unitIds));
        $sql = 'SELECT um.unit_id, COUNT(DISTINCT um.mundane_id) AS active_count
                FROM ' . DB_PREFIX . 'unit_mundane um
                JOIN ' . DB_PREFIX . 'attendance a ON a.mundane_id = um.mundane_id
                  AND a.date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
                WHERE um.unit_id IN (' . $in . ')
                GROUP BY um.unit_id';
        $DB->Clear();
        $rs = $DB->DataSet($sql);
        $out = [];
        while ($rs && $rs->Next()) {
            $out[(int) $rs->unit_id] = (int) $rs->active_count;
        }

        return $out;
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
