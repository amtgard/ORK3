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

    /**
     * T-RPT-01 kingdom-scoped ladder columns (spec requirement 4: kingdom ladders
     * are not comparable across kingdoms, so a kingdom-scoped grid must show them
     * as a separate, labelled group after the official columns; the global/park-only
     * grid must never show them at all).
     */
    public function testKingdomScopedGridAppendsKingdomLadderColumns(): void
    {
        $columns = $this->gridColumnsFor(['KingdomId' => 1]);
        $kingdomColumns = array_filter($columns, fn ($c) => ($c['Scope'] ?? '') === 'kingdom');

        $this->assertNotEmpty($kingdomColumns, 'kingdom ladders must appear in the kingdom grid');
        foreach ($kingdomColumns as $column) {
            $this->assertArrayHasKey('KingdomAwardId', $column);
            $this->assertGreaterThan(0, (int) $column['KingdomAwardId']);
        }
    }

    public function testKingdomColumnsComeAfterTheOfficialOnes(): void
    {
        $columns = array_values($this->gridColumnsFor(['KingdomId' => 1]));
        $scopes = array_map(fn ($c) => $c['Scope'] ?? 'official', $columns);
        $firstKingdom = array_search('kingdom', $scopes, true);

        if ($firstKingdom === false) {
            $this->markTestSkipped('No kingdom ladders in this fixture.');
        }
        $this->assertNotContains(
            'official',
            array_slice($scopes, $firstKingdom),
            'official columns must all precede the kingdom group'
        );
    }

    public function testGlobalGridStaysOfficialOnly(): void
    {
        // Kingdom ladders are not comparable across kingdoms: columns are keyed on
        // award_id, which kingdom ladders lack (0, or the shared 94 placeholder).
        // Two kingdoms' "Order of the Hunter" are different rows.
        $columns = $this->gridColumnsFor([]);
        foreach ($columns as $column) {
            $this->assertNotSame('kingdom', $column['Scope'] ?? 'official');
        }
    }

    public function testWalkerRemainsExcluded(): void
    {
        foreach ([[], ['KingdomId' => 1]] as $request) {
            $ids = array_column($this->gridColumnsFor($request), 'AwardId');
            $this->assertNotContains(31, $ids, 'Walker stays excluded from ladder reports');
        }
    }

    /**
     * GetLadderAwardGrid caches GridRows per (type, id, awards) -- proves the
     * kingdom-scoped cache entry is never read back by, or clobbered by, a
     * Park-only ("global") request against a park in the SAME kingdom, and that
     * a second (cache-warm) kingdom request still carries its kingdom columns.
     */
    public function testCacheDoesNotLeakBetweenGlobalAndKingdomScope(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $this->fixture->createKingdomAward($kid, 21, 'T10RPT Cache Rose', true);
        $this->fixture->createKingdomAward($kid, 94, 'T10RPT Cache Hunter', true);

        $report = new Report();
        $kingdomRequest = $this->kingdomGridRequest($kid);

        // Warm the kingdom-scoped cache entry first.
        $kingdomAssembly = $report->GetLadderAwardGrid($kingdomRequest);
        $kingdomScopes = array_map(fn ($c) => $c['Scope'] ?? 'official', $kingdomAssembly['LadderAwards']);
        $this->assertContains('kingdom', $kingdomScopes, 'kingdom-scoped grid must carry the kingdom group');

        // Global (Park-only) request against a park in the SAME kingdom must never
        // see those kingdom columns, cache-warm or not.
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $editor = $this->fixture->createPlayer($parkId, 'cache-global');
        $this->fixture->insertScopedAuth($editor['mundane_id'], $parkId, $kid, AUTH_CREATE);
        unset($_SESSION['is_authorized_mundane_id']);
        $globalAssembly = $report->GetLadderAwardGrid([
            'KingdomId' => 0,
            'ParkId' => $parkId,
            'Token' => $editor['token'],
        ]);
        foreach ($globalAssembly['LadderAwards'] as $column) {
            $this->assertNotSame('kingdom', $column['Scope'] ?? 'official', 'global grid must not leak kingdom columns');
        }

        // Re-request the kingdom-scoped grid (now served from cache) and confirm the
        // intervening global read did not clobber or bleed into its cache entry.
        $kingdomAssembly2 = $report->GetLadderAwardGrid($kingdomRequest);
        $scopes2 = array_map(fn ($c) => $c['Scope'] ?? 'official', $kingdomAssembly2['LadderAwards']);
        $this->assertContains('kingdom', $scopes2, 'kingdom cache entry must survive an interleaved global read');
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

    /**
     * Requests the Ladder Grid's LadderAwards column list. A truthy KingdomId
     * requests the kingdom-scoped grid over a fixture kingdom seeded with both an
     * official-linked kingdomaward row (award 21, "Order of the Rose") and a
     * kingdom-own ladder pointed at the shared "Custom Award" placeholder
     * (award_id=94) -- the exact collision case T-RPT-01 exists to keep separate.
     * An empty/falsy KingdomId requests the Park-only ("global") grid instead,
     * which never joins kingdomaward and so is unaffected by kingdom fixtures.
     *
     * @return list<array<string, mixed>>
     */
    private function gridColumnsFor(array $overrides): array
    {
        if (!empty($overrides['KingdomId'])) {
            $kid = $this->fixture->firstKingdomId();
            $this->fixture->createKingdomAward($kid, 21, 'T10RPT Order of the Rose', true);
            $this->fixture->createKingdomAward($kid, 94, 'T10RPT Order of the Hunter', true);
            $request = $this->kingdomGridRequest($kid);
        } else {
            $kid = $this->fixture->firstKingdomId();
            $parkId = $this->fixture->parkIdInKingdom($kid);
            $editor = $this->fixture->createPlayer($parkId, 'ladder-grid-global');
            $this->fixture->insertScopedAuth($editor['mundane_id'], $parkId, $kid, AUTH_CREATE);
            unset($_SESSION['is_authorized_mundane_id']);
            $request = ['KingdomId' => 0, 'ParkId' => $parkId, 'Token' => $editor['token']];
        }

        $report = new Report();
        $assembly = $report->GetLadderAwardGrid($request);

        return array_values($assembly['LadderAwards'] ?? []);
    }
}
