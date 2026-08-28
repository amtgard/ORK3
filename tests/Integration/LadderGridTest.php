<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for ladder grid report assembly (T-RPT-01).
 */
final class LadderGridTest extends TestCase
{
    private ReportsFixture $fixture;

    /** @var array<string, mixed> */
    private array $lastAssembly = [];

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
     * Order of the Zodiac (award_id 30) is granted once per calendar month, so its
     * twelve positions are months, not levels. GetLadderAwardGrid's cell value was
     * GREATEST(MAX(ma.rank), COUNT(ma.awards_id)) -- fine for a ranked ladder, but
     * for Zodiac the legacy `rank` column predates the monthly model and is never a
     * meaningful ceiling. Seed three grants carrying legacy ranks well above the
     * true total (9, 10, 11) so a GREATEST-based cell would misreport 11 where the
     * correct answer -- the total granted -- is 3.
     */
    public function testZodiacColumnShowsTheTotalCountNotTheHighestRank(): void
    {
        $player = $this->playerWithThreeZodiacs();

        $cell = $this->gridCellFor($player, 30);

        $this->assertSame(3, (int) $cell['Rank']);
    }

    public function testWalkerRemainsExcludedFromTheGrid(): void
    {
        $this->assertNotContains(31, array_column($this->gridColumnsFor([]), 'AwardId'));
    }

    /**
     * Report::PlayerAwards() is the general awards report backing kingdom_awards
     * and knights_and_masters. Zodiac rows there carried the legacy rank column
     * with no month; this proves a recorded zodiac_month is surfaced directly.
     */
    public function testPlayerAwardsSurfacesTheRecordedZodiacMonth(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $ka = $this->fixture->createKingdomAward($kid, 30, 'T10RPT Order of the Zodiac', true);
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $player = $this->fixture->createPlayer($parkId, 'zodiac-recorded-month');
        $this->fixture->insertLadderAward($player['mundane_id'], $parkId, $kid, $ka, 30, 0, '2024-06-15', 6);

        $row = $this->playerAwardsRowFor($kid, $player['mundane_id']);

        $this->assertTrue($row['IsMonthlyLadder']);
        $this->assertSame(6, $row['ZodiacMonth']);
        $this->assertSame('June', $row['ZodiacMonthName']);
    }

    /**
     * 2,024 of 3,798 Zodiac grants carry no month at all. The reconciliation
     * pre-fill (Award::ZodiacMonthFromDate) is also the report's fallback: a
     * monthless grant still reads as the month it was recorded in.
     */
    public function testPlayerAwardsFallsBackToTheGrantDateWhenNoMonthIsRecorded(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $ka = $this->fixture->createKingdomAward($kid, 30, 'T10RPT Order of the Zodiac', true);
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $player = $this->fixture->createPlayer($parkId, 'zodiac-fallback-month');
        $this->fixture->insertLadderAward($player['mundane_id'], $parkId, $kid, $ka, 30, 5, '2024-03-28', 0);

        $row = $this->playerAwardsRowFor($kid, $player['mundane_id']);

        $this->assertSame(3, $row['ZodiacMonth']);
        $this->assertSame('March', $row['ZodiacMonthName']);
    }

    /**
     * 3,975 ladder grants carry the '0000-00-00' sentinel date. A monthless
     * Zodiac grant with no real date must yield NO month, never a spurious
     * January -- Award::ZodiacMonthFromDate() guards this; this proves
     * PlayerAwards actually calls it rather than parsing the date itself.
     */
    public function testPlayerAwardsTreatsTheZeroDateSentinelAsNoMonth(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $ka = $this->fixture->createKingdomAward($kid, 30, 'T10RPT Order of the Zodiac', true);
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $player = $this->fixture->createPlayer($parkId, 'zodiac-zero-date');
        $this->fixture->insertLadderAward($player['mundane_id'], $parkId, $kid, $ka, 30, 1, '0000-00-00', 0);

        $row = $this->playerAwardsRowFor($kid, $player['mundane_id']);

        $this->assertSame(0, $row['ZodiacMonth'], 'the zero-date sentinel must never read as January');
        $this->assertSame('', $row['ZodiacMonthName']);
    }

    public function testPlayerAwardsLeavesNonZodiacRowsUnaffected(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $ka = $this->fixture->createKingdomAward($kid, 21, 'T10RPT Order of the Rose', true);
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $player = $this->fixture->createPlayer($parkId, 'rose-unaffected');
        $this->fixture->insertLadderAward($player['mundane_id'], $parkId, $kid, $ka, 21, 4);

        $row = $this->playerAwardsRowFor($kid, $player['mundane_id']);

        $this->assertFalse($row['IsMonthlyLadder']);
        $this->assertSame(0, $row['ZodiacMonth']);
        $this->assertSame('', $row['ZodiacMonthName']);
    }

    /**
     * The regression this fix risks: PlayerAwards is a GENERAL report ordered
     * by peerage/name/persona for every award, reused by kingdom_awards and
     * knights_and_masters. Proves that adding Zodiac grants -- inserted out of
     * chronological order -- (a) sorts only the Zodiac rows among themselves by
     * grant date, and (b) leaves every other row's relative order exactly as it
     * was before any Zodiac data existed.
     */
    public function testPlayerAwardsSortsOnlyZodiacRowsChronologicallyWithoutDisturbingGeneralOrdering(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $request = ['KingdomId' => $kid, 'IncludeLadder' => 1, 'LadderMinimum' => 0];

        $roseKa = $this->fixture->createKingdomAward($kid, 21, 'T10RPT Order of the Rose', true);
        $alice = $this->fixture->createPlayer($parkId, 'aaa-order-rose');
        $bob = $this->fixture->createPlayer($parkId, 'bbb-order-rose');
        $this->fixture->insertLadderAward($alice['mundane_id'], $parkId, $kid, $roseKa, 21, 3);
        $this->fixture->insertLadderAward($bob['mundane_id'], $parkId, $kid, $roseKa, 21, 5);

        $report = new Report();
        $baseline = $report->PlayerAwards($request);
        $baselineOrder = array_map(
            static fn ($r) => $r['AwardName'] . '|' . $r['Persona'],
            $baseline['Awards'] ?? []
        );

        $zodiacKa = $this->fixture->createKingdomAward($kid, 30, 'T10RPT Order of the Zodiac', true);
        $carl = $this->fixture->createPlayer($parkId, 'ccc-order-zodiac');
        // Inserted deliberately out of chronological order.
        $this->fixture->insertLadderAward($carl['mundane_id'], $parkId, $kid, $zodiacKa, 30, 0, '2024-06-01', 6);
        $this->fixture->insertLadderAward($carl['mundane_id'], $parkId, $kid, $zodiacKa, 30, 0, '2024-01-01', 1);
        $this->fixture->insertLadderAward($carl['mundane_id'], $parkId, $kid, $zodiacKa, 30, 0, '2024-03-01', 3);

        $withZodiac = $report->PlayerAwards($request);
        $rows = $withZodiac['Awards'] ?? [];

        $nonZodiacOrder = array_values(array_map(
            static fn ($r) => $r['AwardName'] . '|' . $r['Persona'],
            array_values(array_filter($rows, static fn ($r) => !$r['IsMonthlyLadder']))
        ));
        $this->assertSame(
            $baselineOrder,
            $nonZodiacOrder,
            'non-Zodiac rows must keep the exact relative order they had before Zodiac data existed'
        );

        $zodiacDates = array_values(array_map(
            static fn ($r) => $r['Date'],
            array_values(array_filter($rows, static fn ($r) => $r['IsMonthlyLadder']))
        ));
        $sortedDates = $zodiacDates;
        sort($sortedDates);
        $this->assertSame($sortedDates, $zodiacDates, 'Zodiac rows must be chronologically ordered by grant date');
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
     * controller.Reports.php::ladder_grid() sets $kingdom_id in its KingdomId
     * branch and never resets it when a later ParkId branch overrides $type to
     * 'Park' -- so the live Park Ladder Grid route always forwards a non-zero
     * KingdomId alongside a non-zero ParkId. That is deliberately NOT the
     * "unscoped" case the spec protects (two kingdoms' same-named ladders being
     * incomparable): a park sits inside exactly one kingdom, so that kingdom's
     * ladders ARE directly comparable for every player on the grid. This test
     * mirrors the real request shape and asserts both halves of that intent:
     * the kingdom group is present, and the rows still stay park-scoped (the
     * columns are kingdom-wide, the rows are not).
     */
    public function testParkScopedGridAlsoShowsItsKingdomsLadders(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $ka = $this->fixture->createKingdomAward($kid, 94, 'T10RPT Park-Scoped Hunter', true);

        $parkId = $this->fixture->parkIdInKingdom($kid);
        $otherParkId = $this->fixture->secondParkIdInKingdom($kid, $parkId);

        $inPark = $this->fixture->createPlayer($parkId, 'park-ladder-in');
        $this->fixture->insertLadderAward($inPark['mundane_id'], $parkId, $kid, $ka, 94, 4);

        $outOfParkMundaneId = null;
        if ($otherParkId > 0) {
            $outOfPark = $this->fixture->createPlayer($otherParkId, 'park-ladder-out');
            $this->fixture->insertLadderAward($outOfPark['mundane_id'], $otherParkId, $kid, $ka, 94, 4);
            $outOfParkMundaneId = $outOfPark['mundane_id'];
        }

        $editor = $this->fixture->createPlayer($parkId, 'park-ladder-editor');
        $this->fixture->insertScopedAuth($editor['mundane_id'], $parkId, $kid, AUTH_CREATE);
        unset($_SESSION['is_authorized_mundane_id']);

        $report = new Report();
        // Both ids set, exactly as the live controller forwards them for a Park request.
        $assembly = $report->GetLadderAwardGrid([
            'KingdomId' => $kid,
            'ParkId' => $parkId,
            'Token' => $editor['token'],
        ]);

        $scopes = array_map(fn ($c) => $c['Scope'] ?? 'official', $assembly['LadderAwards']);
        $this->assertContains(
            'kingdom',
            $scopes,
            "a park sits inside exactly one kingdom, so a Park request that also carries KingdomId must still show that kingdom's ladder group"
        );

        $mundaneIds = array_column($assembly['GridRows'], 'MundaneId');
        $this->assertContains($inPark['mundane_id'], $mundaneIds, 'player in the requested park must appear');
        if ($outOfParkMundaneId !== null) {
            $this->assertNotContains(
                $outOfParkMundaneId,
                $mundaneIds,
                'kingdom ladder columns are kingdom-wide, but rows must stay scoped to the requested park'
            );
        }
    }

    /**
     * The kingdom-column query joined ork_award to resolve a fallback name and
     * title_class. It used an INNER join -- but a kingdom award that was raised
     * purely by the kingdom carries ka.award_id = 0, and there is no ork_award
     * row with award_id = 0, so the join dropped it. On the live database that
     * was 17 of 26 ka.is_ladder = 1 rows, hiding 2,247 grants and leaving ten of
     * eighteen kingdoms with an entirely empty kingdom group. The join is now a
     * LEFT join with every a.-referencing predicate NULL-proofed.
     */
    public function testKingdomLadderWithNoLinkedAwardRowStillGetsAColumn(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        // award_id = 0: a pure kingdom award, linked to no ork_award row at all.
        $kaid = $this->fixture->createKingdomAward($kid, 0, 'T10RPT Order of the Unlinked', true);

        $holder = $this->fixture->createPlayer($parkId, 'unlinked-ladder');
        $this->fixture->insertLadderAward($holder['mundane_id'], $parkId, $kid, $kaid, 0, 4);

        $report = new Report();
        $assembly = $report->GetLadderAwardGrid($this->kingdomGridRequest($kid));
        $columns = $assembly['LadderAwards'];

        $this->assertArrayHasKey(
            'k' . $kaid,
            $columns,
            'a kingdom ladder with award_id = 0 must still produce a column'
        );
        $this->assertSame('kingdom', $columns['k' . $kaid]['Scope']);
        $this->assertSame('T10RPT Order of the Unlinked', $columns['k' . $kaid]['Name']);
        $this->assertSame($kaid, $columns['k' . $kaid]['KingdomAwardId']);
        $this->assertSame(0, $columns['k' . $kaid]['AwardId']);

        // And the grants hanging off it must actually reach the grid.
        $cell = null;
        foreach ($assembly['GridRows'] as $row) {
            if ((int) $row['MundaneId'] === (int) $holder['mundane_id']) {
                $cell = $row['Awards']['k' . $kaid] ?? null;
            }
        }
        $this->assertNotNull($cell, 'grants on an award_id = 0 kingdom ladder must reach the grid');
        $this->assertSame(4, (int) $cell['Rank']);
    }

    /**
     * The worst case of the same defect: a kingdom whose ladders are ALL
     * award_id = 0 produced an empty kingdom group, so the report showed that
     * kingdom nothing whatsoever of its own. (Live: kingdoms 18 and 22, four
     * and two ladders respectively, both rendering zero kingdom columns.)
     */
    public function testKingdomWhoseLaddersAreAllUnlinkedGetsANonEmptyKingdomGroup(): void
    {
        $kid = $this->fixture->firstKingdomId();

        $before = array_filter(
            $this->kingdomColumnsFor($kid),
            static fn ($c) => ($c['Scope'] ?? '') === 'kingdom'
        );
        $this->assertSame([], $before, 'precondition: this kingdom starts with no kingdom ladders');

        // Every ladder this kingdom owns is award_id = 0 -- nothing links to ork_award.
        $first = $this->fixture->createKingdomAward($kid, 0, 'T10RPT Order of the Mantis', true);
        $second = $this->fixture->createKingdomAward($kid, 0, 'T10RPT Order of the Quill', true);

        $kingdomColumns = array_filter(
            $this->kingdomColumnsFor($kid),
            static fn ($c) => ($c['Scope'] ?? '') === 'kingdom'
        );

        $this->assertNotEmpty(
            $kingdomColumns,
            'a kingdom whose ladders are all award_id = 0 must still get a kingdom group'
        );
        $ids = array_column($kingdomColumns, 'KingdomAwardId');
        $this->assertContains($first, $ids);
        $this->assertContains($second, $ids);
    }

    /**
     * The Walker exclusion rode on `a.award_id != 31`, which is NULL -- not TRUE --
     * once `a` may be absent, so the NULL-proofing had to be IFNULL(a.award_id, 0).
     * Proves the exclusion survived that rewrite while unlinked ladders are present.
     */
    public function testWalkerStaysExcludedAlongsideUnlinkedKingdomLadders(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $unlinked = $this->fixture->createKingdomAward($kid, 0, 'T10RPT Order of the Unlinked Walker Case', true);
        $walkerKa = $this->fixture->createKingdomAward($kid, 31, 'T10RPT Walker In The Middle', true);

        $columns = $this->kingdomColumnsFor($kid);
        $keys = array_keys($this->lastAssembly['LadderAwards']);

        $this->assertContains($unlinked, array_column($columns, 'KingdomAwardId'));
        $this->assertNotContains(31, array_column($columns, 'AwardId'), 'Walker stays excluded from the kingdom grid');
        $this->assertNotContains('k' . $walkerKa, $keys, 'a kingdomaward pointing at Walker must not become a column');
        $this->assertNotContains(31, array_column($this->gridColumnsFor([]), 'AwardId'));
    }

    /**
     * The LEFT join widened what the KINGDOM query returns; it must not widen the
     * global (unscoped) grid, which stays official-only because two kingdoms'
     * same-named ladders are different rows and are not comparable.
     */
    public function testGlobalGridStaysOfficialOnlyWithUnlinkedKingdomLadders(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $this->fixture->createKingdomAward($kid, 0, 'T10RPT Global Leak Check One', true);
        $this->fixture->createKingdomAward($kid, 0, 'T10RPT Global Leak Check Two', true);

        $parkId = $this->fixture->parkIdInKingdom($kid);
        $editor = $this->fixture->createPlayer($parkId, 'unlinked-global');
        $this->fixture->insertScopedAuth($editor['mundane_id'], $parkId, $kid, AUTH_CREATE);
        unset($_SESSION['is_authorized_mundane_id']);

        $report = new Report();
        $assembly = $report->GetLadderAwardGrid([
            'KingdomId' => 0,
            'ParkId' => $parkId,
            'Token' => $editor['token'],
        ]);

        foreach ($assembly['LadderAwards'] as $key => $column) {
            $this->assertNotSame('kingdom', $column['Scope'] ?? 'official');
            $this->assertIsInt($key, 'global columns are keyed on award_id, never on k<kingdomaward_id>');
        }
    }

    /**
     * Kingdom-scoped LadderAwards for $kid, also stashed on $this->lastAssembly so
     * a caller can inspect the column KEYS (which array_values would discard).
     *
     * @return list<array<string, mixed>>
     */
    private function kingdomColumnsFor(int $kid): array
    {
        $report = new Report();
        $this->lastAssembly = $report->GetLadderAwardGrid($this->kingdomGridRequest($kid));

        return array_values($this->lastAssembly['LadderAwards'] ?? []);
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
     * @return array{mundane_id: int, park_id: int, kingdom_id: int, token: string}
     */
    private function playerWithThreeZodiacs(): array
    {
        $kid = $this->fixture->firstKingdomId();
        $ka = $this->fixture->createKingdomAward($kid, 30, 'T10RPT Order of the Zodiac', true);
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $player = $this->fixture->createPlayer($parkId, 'zodiac-three');

        // Legacy ranks well above the true grant count -- GREATEST(MAX(rank), COUNT())
        // would misreport 11 where the correct total (what this test protects) is 3.
        $this->fixture->insertLadderAward($player['mundane_id'], $parkId, $kid, $ka, 30, 9);
        $this->fixture->insertLadderAward($player['mundane_id'], $parkId, $kid, $ka, 30, 10);
        $this->fixture->insertLadderAward($player['mundane_id'], $parkId, $kid, $ka, 30, 11);

        return $player;
    }

    /**
     * @param array{mundane_id: int, kingdom_id: int} $player
     * @return array<string, mixed>
     */
    private function gridCellFor(array $player, int $awardId): array
    {
        $request = $this->kingdomGridRequest((int) $player['kingdom_id']);
        $report = new Report();
        $assembly = $report->GetLadderAwardGrid($request);

        foreach ($assembly['GridRows'] as $row) {
            if ((int) $row['MundaneId'] === (int) $player['mundane_id']) {
                return $row['Awards'][$awardId] ?? [];
            }
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function playerAwardsRowFor(int $kingdomId, int $mundaneId): array
    {
        $report = new Report();
        $response = $report->PlayerAwards([
            'KingdomId' => $kingdomId,
            'IncludeLadder' => 1,
            'LadderMinimum' => 0,
        ]);

        foreach ($response['Awards'] ?? [] as $row) {
            if ((int) $row['MundaneId'] === $mundaneId) {
                return $row;
            }
        }

        return [];
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
