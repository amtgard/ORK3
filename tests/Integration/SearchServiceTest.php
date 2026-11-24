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

    /**
     * Two players sharing a persona stem in parks A and B of the same kingdom.
     *
     * @return array{kid: int, parkA: int, parkB: int, stem: string, p: array{mundane_id: int}, q: array{mundane_id: int}}
     */
    private function twoParkScopeFixture(): array
    {
        $kid = $this->fixture->firstKingdomId();
        $parkA = $this->fixture->parkIdInKingdom($kid);
        $parkB = $this->fixture->secondParkIdInKingdom($kid, $parkA);
        if ($parkB <= 0) {
            $this->markTestSkipped('Need two parks in kingdom for scope filter tests.');
        }
        $stem = 'Scope' . bin2hex(random_bytes(3));
        $p = $this->fixture->createPlayer($parkA, 'scopea', 'T11SRC ' . $stem . ' Alpha');
        $q = $this->fixture->createPlayer($parkB, 'scopeb', 'T11SRC ' . $stem . ' Beta');

        return ['kid' => $kid, 'parkA' => $parkA, 'parkB' => $parkB, 'stem' => $stem, 'p' => $p, 'q' => $q];
    }

    public function testPrefixCannotEscapeRestrictToPark(): void
    {
        $f = $this->twoParkScopeFixture();
        $kAbbr = $this->fixture->kingdomAbbreviation($f['kid']);
        $pAbbrB = $this->fixture->parkAbbreviation($f['parkB']);
        if (!preg_match('/^[a-z0-9]{2,3}$/i', $kAbbr) || !preg_match('/^[a-z0-9]{2,3}$/i', $pAbbrB)) {
            $this->markTestSkipped('Fixture abbreviations are not prefix-shaped.');
        }

        $results = $this->searchService->RankedPlayers([
            'q'          => "{$kAbbr}:{$pAbbrB} {$f['stem']}",
            'parkId'     => $f['parkA'],
            'restrictTo' => 'park',
            'limit'      => 50,
        ]);
        $ids = array_column($results, 'MundaneId');
        $this->assertNotContains($f['q']['mundane_id'], $ids);
        foreach ($results as $row) {
            $this->assertSame($f['parkA'], (int) $row['ParkId']);
        }

        // Positive control: an in-scope prefix on the same park-locked field still finds P.
        $pAbbrA = $this->fixture->parkAbbreviation($f['parkA']);
        if (preg_match('/^[a-z0-9]{2,3}$/i', $pAbbrA)) {
            $inScope = $this->searchService->RankedPlayers([
                'q'          => "{$kAbbr}:{$pAbbrA} {$f['stem']}",
                'parkId'     => $f['parkA'],
                'restrictTo' => 'park',
                'limit'      => 50,
            ]);
            $this->assertContains($f['p']['mundane_id'], array_column($inScope, 'MundaneId'));
        }
    }

    public function testFilterOutsideRestrictKingdomIdsReturnsEmpty(): void
    {
        $f = $this->twoParkScopeFixture();
        $otherKid = $this->fixture->kingdomIdNotIn([$f['kid']]);
        if ($otherKid <= 0) {
            $this->markTestSkipped('Need a second kingdom.');
        }
        $this->fixture->createPlayer($this->fixture->parkIdInKingdom($otherKid), 'scoperk', 'T11SRC ' . $f['stem'] . ' Listed');

        $results = $this->searchService->RankedPlayers([
            'q'                  => $f['stem'],
            'restrictKingdomIds' => [$f['kid']],
            'filterKingdomId'    => $otherKid,
            'limit'              => 50,
        ]);
        $this->assertSame([], $results);
    }

    public function testKingdomFilterIncludesPrincipalityMembers(): void
    {
        $pair = $this->fixture->principalityPair();
        if ($pair === null) {
            $this->markTestSkipped('Need an active kingdom/principality pair with a park.');
        }
        $stem = 'Scope' . bin2hex(random_bytes(3));
        $childPark = $this->fixture->parkIdInKingdom($pair['child']);
        $member = $this->fixture->createPlayer($childPark, 'scopepr', 'T11SRC ' . $stem . ' Principal');
        $family = array_map('intval', Ork3::$Lib->kingdom->GetFamilyKingdomIds($pair['parent']));

        // Explicit filter.
        $results = $this->searchService->RankedPlayers(['q' => $stem, 'filterKingdomId' => $pair['parent'], 'limit' => 50]);
        $this->assertContains($member['mundane_id'], array_column($results, 'MundaneId'));
        foreach ($results as $row) {
            $this->assertContains((int) $row['KingdomId'], $family);
        }

        // Typed "KD:" prefix shares the path.
        $kAbbr = $this->fixture->kingdomAbbreviation($pair['parent']);
        if (preg_match('/^[a-z0-9]{2,3}$/i', $kAbbr)) {
            $typed = $this->searchService->RankedPlayers(['q' => "{$kAbbr}: {$stem}", 'limit' => 50]);
            $this->assertContains($member['mundane_id'], array_column($typed, 'MundaneId'));
        }
    }

    // Security regression: a field hard-scoped to a principality must not be widened to the parent
    // kingdom by a parent-kingdom filter (the filter only narrows; the family roll-down is ANDed).
    public function testParentKingdomFilterCannotWidenPrincipalityRestriction(): void
    {
        $pair = $this->fixture->principalityPair();
        if ($pair === null) {
            $this->markTestSkipped('Need an active kingdom/principality pair with a park.');
        }
        $parentPark = $this->fixture->parkIdInKingdom($pair['parent']);
        if ($parentPark <= 0) {
            $this->markTestSkipped('Need a park in the parent kingdom.');
        }
        $stem = 'Scope' . bin2hex(random_bytes(3));
        $childMember = $this->fixture->createPlayer($this->fixture->parkIdInKingdom($pair['child']), 'scopepc', 'T11SRC ' . $stem . ' Child');
        $parentMember = $this->fixture->createPlayer($parentPark, 'scopepk', 'T11SRC ' . $stem . ' Parent');

        $results = $this->searchService->RankedPlayers([
            'q'                  => $stem,
            'restrictKingdomIds' => [$pair['child']],
            'filterKingdomId'    => $pair['parent'],
            'limit'              => 50,
        ]);
        $ids = array_column($results, 'MundaneId');
        $this->assertContains($childMember['mundane_id'], $ids);
        $this->assertNotContains($parentMember['mundane_id'], $ids);
        foreach ($results as $row) {
            $this->assertSame($pair['child'], (int) $row['KingdomId']);
        }
    }

    public function testParkFilterInPrincipalityAcceptsParentKingdomFilter(): void
    {
        $pair = $this->fixture->principalityPair();
        if ($pair === null) {
            $this->markTestSkipped('Need an active kingdom/principality pair with a park.');
        }
        $stem = 'Scope' . bin2hex(random_bytes(3));
        $childPark = $this->fixture->parkIdInKingdom($pair['child']);
        $member = $this->fixture->createPlayer($childPark, 'scopepp', 'T11SRC ' . $stem . ' Pparked');

        $results = $this->searchService->RankedPlayers([
            'q'               => $stem,
            'filterKingdomId' => $pair['parent'],
            'filterParkId'    => $childPark,
            'limit'           => 50,
        ]);
        $this->assertContains($member['mundane_id'], array_column($results, 'MundaneId'));
        foreach ($results as $row) {
            $this->assertSame($childPark, (int) $row['ParkId']);
        }

        // The reverse does not hold: a parent-kingdom park is outside the principality's family.
        $parentPark = $this->fixture->parkIdInKingdom($pair['parent']);
        if ($parentPark > 0) {
            $this->assertSame([], $this->searchService->RankedPlayers([
                'q'               => $stem,
                'filterKingdomId' => $pair['child'],
                'filterParkId'    => $parentPark,
            ]));
        }
    }

    public function testExplicitFilterNarrowsWithinKingdomScope(): void
    {
        $f = $this->twoParkScopeFixture();

        $results = $this->searchService->RankedPlayers([
            'q'            => $f['stem'],
            'kingdomId'    => $f['kid'],
            'restrictTo'   => 'kingdom',
            'filterParkId' => $f['parkA'],
            'limit'        => 50,
        ]);
        $ids = array_column($results, 'MundaneId');
        $this->assertContains($f['p']['mundane_id'], $ids);
        $this->assertNotContains($f['q']['mundane_id'], $ids);
        foreach ($results as $row) {
            $this->assertSame($f['parkA'], (int) $row['ParkId']);
        }
    }

    public function testFilterOutsideRestrictScopeReturnsEmpty(): void
    {
        $f = $this->twoParkScopeFixture();
        $family = array_map('intval', Ork3::$Lib->kingdom->GetFamilyKingdomIds($f['kid']));
        $otherKid = $this->fixture->kingdomIdNotIn($family);
        if ($otherKid <= 0) {
            $this->markTestSkipped('Need a kingdom outside the family.');
        }
        $outsider = $this->fixture->createPlayer($this->fixture->parkIdInKingdom($otherKid), 'scopeo', 'T11SRC ' . $f['stem'] . ' Outsider');

        $results = $this->searchService->RankedPlayers([
            'q'               => $f['stem'],
            'kingdomId'       => $f['kid'],
            'restrictTo'      => 'kingdom',
            'filterKingdomId' => $otherKid,
            'limit'           => 50,
        ]);
        $this->assertSame([], $results);
        // Sanity: the outsider exists and is findable without the hard scope.
        $open = $this->searchService->RankedPlayers(['q' => $f['stem'], 'filterKingdomId' => $otherKid, 'limit' => 50]);
        $this->assertContains($outsider['mundane_id'], array_column($open, 'MundaneId'));
    }

    public function testFilterParkOutsideKingdomReturnsEmpty(): void
    {
        $f = $this->twoParkScopeFixture();
        $otherKid = $this->fixture->kingdomIdNotIn([$f['kid']]);
        if ($otherKid <= 0) {
            $this->markTestSkipped('Need a second kingdom.');
        }
        $otherPark = $this->fixture->parkIdInKingdom($otherKid);
        $this->fixture->createPlayer($otherPark, 'scopeop', 'T11SRC ' . $f['stem'] . ' Elsewhere');

        $results = $this->searchService->RankedPlayers([
            'q'               => $f['stem'],
            'filterKingdomId' => $f['kid'],
            'filterParkId'    => $otherPark,
            'limit'           => 50,
        ]);
        $this->assertSame([], $results);
    }

    public function testFilterScopeKingdomsRestrictedToFamily(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $family = array_map('intval', Ork3::$Lib->kingdom->GetFamilyKingdomIds($kid));

        $rows = $this->searchService->filter_scope_kingdoms(['kingdomId' => $kid, 'restrictTo' => 'kingdom']);
        $ids = array_map(fn ($r) => $r['id'], $rows);
        $this->assertContains($kid, $ids);
        $this->assertSame([], array_values(array_diff($ids, $family)));
        foreach ($rows as $row) {
            $this->assertSame(['id', 'name', 'abbr'], array_keys($row));
        }

        // restrictKingdomIds wins and excludeKingdomId removes.
        $otherKid = $this->fixture->kingdomIdNotIn($family);
        if ($otherKid > 0) {
            $listed = $this->searchService->filter_scope_kingdoms(['restrictKingdomIds' => [$kid, $otherKid], 'excludeKingdomId' => $otherKid]);
            $this->assertSame([$kid], array_map(fn ($r) => $r['id'], $listed));
        }

        // Unscoped: all active kingdoms, sorted by name, never the retired records kingdom (15).
        $all = $this->searchService->filter_scope_kingdoms([]);
        $allIds = array_map(fn ($r) => $r['id'], $all);
        $this->assertContains($kid, $allIds);
        $this->assertNotContains(15, $allIds);
        $names = array_map(fn ($r) => $r['name'], $all);
        $sorted = $names;
        usort($sorted, 'strcasecmp');
        $this->assertSame($sorted, $names);
    }

    public function testFilterScopeKingdomsEmptyForParkLocked(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $this->assertSame([], $this->searchService->filter_scope_kingdoms([
            'parkId' => $parkId, 'kingdomId' => $kid, 'restrictTo' => 'park',
        ]));
        $this->assertSame([], $this->searchService->filter_scope_parks($kid, [
            'parkId' => $parkId, 'kingdomId' => $kid, 'restrictTo' => 'park',
        ]));
    }

    public function testFilterScopeParksRejectsOutOfScopeKingdom(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkA = $this->fixture->parkIdInKingdom($kid);
        $family = array_map('intval', Ork3::$Lib->kingdom->GetFamilyKingdomIds($kid));
        $otherKid = $this->fixture->kingdomIdNotIn($family);
        if ($otherKid <= 0) {
            $this->markTestSkipped('Need a kingdom outside the family.');
        }
        $scope = ['kingdomId' => $kid, 'restrictTo' => 'kingdom'];

        $this->assertSame([], $this->searchService->filter_scope_parks($otherKid, $scope));
        $this->assertSame([], $this->searchService->filter_scope_parks($otherKid, ['restrictKingdomIds' => [$kid]]));

        $parks = $this->searchService->filter_scope_parks($kid, $scope);
        $this->assertContains($parkA, array_map(fn ($r) => $r['id'], $parks));
        foreach ($parks as $row) {
            $this->assertSame(['id', 'name', 'abbr', 'kingdomId', 'kingdomAbbr'], array_keys($row));
        }

        $withoutA = $this->searchService->filter_scope_parks($kid, $scope + ['excludeParkId' => $parkA]);
        $this->assertNotContains($parkA, array_map(fn ($r) => $r['id'], $withoutA));
    }

    public function testResolveFilterPrefixKnownAndUnknown(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $kAbbr = $this->fixture->kingdomAbbreviation($kid);
        $pAbbr = $this->fixture->parkAbbreviation($parkId);
        if (!preg_match('/^[a-z0-9]{2,3}$/i', $kAbbr) || !preg_match('/^[a-z0-9]{2,3}$/i', $pAbbr)) {
            $this->markTestSkipped('Fixture abbreviations are not prefix-shaped.');
        }

        $m = $this->searchService->resolve_filter_prefix(strtolower("{$kAbbr}:{$pAbbr}"), []);
        $this->assertNotNull($m);
        $this->assertSame($kid, $m['kingdomId']);
        $this->assertSame($parkId, $m['parkId']);
        $this->assertSame($this->fixture->parkName($parkId), $m['parkName']);
        $this->assertSame(['kingdomId', 'kingdomName', 'kingdomAbbr', 'parkId', 'parkName', 'parkAbbr'], array_keys($m));

        foreach (["{$kAbbr}:*", "{$kAbbr}:", $kAbbr] as $kOnly) {
            $k = $this->searchService->resolve_filter_prefix($kOnly, []);
            $this->assertNotNull($k, $kOnly);
            $this->assertSame($kid, $k['kingdomId']);
            $this->assertSame(0, $k['parkId']);
            $this->assertSame('', $k['parkName']);
        }

        $this->assertNull($this->searchService->resolve_filter_prefix('ZZZ:QQ', []));
        $this->assertNull($this->searchService->resolve_filter_prefix("{$kAbbr}:ZQ9", []));
        $this->assertNull($this->searchService->resolve_filter_prefix("{$kAbbr}:{$pAbbr}' OR 1=1", []));
        $this->assertNull($this->searchService->resolve_filter_prefix("{$kAbbr}:{$pAbbr}", ['parkId' => $parkId, 'restrictTo' => 'park']));
        $this->assertNull($this->searchService->resolve_filter_prefix("{$kAbbr}:{$pAbbr}", ['excludeParkId' => $parkId]));
        $this->assertNull($this->searchService->resolve_filter_prefix($kAbbr, ['excludeKingdomId' => $kid]));

        $family = array_map('intval', Ork3::$Lib->kingdom->GetFamilyKingdomIds($kid));
        $otherKid = $this->fixture->kingdomIdNotIn($family);
        if ($otherKid > 0) {
            $this->assertNull($this->searchService->resolve_filter_prefix("{$kAbbr}:{$pAbbr}", ['restrictKingdomIds' => [$otherKid]]));
            $this->assertNull($this->searchService->resolve_filter_prefix($kAbbr, ['kingdomId' => $otherKid, 'restrictTo' => 'kingdom']));
        }
    }

    public function testFilterScopeKingdomCentresOnParkKingdom(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $family = array_map('intval', Ork3::$Lib->kingdom->GetFamilyKingdomIds($kid));
        $otherKid = $this->fixture->kingdomIdNotIn($family);
        if ($otherKid <= 0) {
            $this->markTestSkipped('Need a kingdom outside the family.');
        }
        $otherPark = $this->fixture->parkIdInKingdom($otherKid);
        $otherFamily = array_map('intval', Ork3::$Lib->kingdom->GetFamilyKingdomIds($otherKid));
        // A park in another kingdom wins over a mismatching kingdomId, as in RankedPlayers.
        $scope = ['kingdomId' => $kid, 'parkId' => $otherPark, 'restrictTo' => 'kingdom'];

        $ids = array_map(fn ($r) => $r['id'], $this->searchService->filter_scope_kingdoms($scope));
        $this->assertContains($otherKid, $ids);
        $this->assertNotContains($kid, $ids);
        $this->assertSame([], array_values(array_diff($ids, $otherFamily)));
        $this->assertSame([], $this->searchService->filter_scope_parks($kid, $scope));
        $this->assertNotSame([], $this->searchService->filter_scope_parks($otherKid, $scope));
        $kAbbr = $this->fixture->kingdomAbbreviation($kid);
        $this->assertNull($this->searchService->resolve_filter_prefix($kAbbr, $scope));
    }

    public function testResolverAndSearchPrefixAgreeOnDuplicateAbbreviations(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $kAbbr = $this->fixture->kingdomAbbreviation($kid);
        $abbr = $this->fixture->unusedParkAbbreviation($kid);
        if (!preg_match('/^[a-z0-9]{2,3}$/i', $kAbbr) || $abbr === '') {
            $this->markTestSkipped('Fixture abbreviations are not prefix-shaped.');
        }
        // Retired park inserted first (lower id, earlier name) so an unordered LIMIT 1 would pick it.
        $retired = $this->fixture->createPark($kid, $abbr, 'A Retired ' . $abbr, false);
        $active = $this->fixture->createPark($kid, $abbr, 'B Active ' . $abbr, true);
        $this->assertGreaterThan($retired, $active);

        $match = $this->searchService->resolve_filter_prefix("{$kAbbr}:{$abbr}", []);
        $this->assertNotNull($match);
        $this->assertSame($active, $match['parkId']);
        [$searchKid, $searchPid] = $this->searchPrefix("{$kAbbr}:{$abbr} someone");
        $this->assertSame($kid, $searchKid);
        $this->assertSame($active, $searchPid);

        // Behavioural: the typed prefix in RankedPlayers filters to the same (active) park.
        $stem = 'Dup' . bin2hex(random_bytes(3));
        $inActive = $this->fixture->createPlayer($active, 'dupa', 'T11SRC ' . $stem . ' Active');
        $inRetired = $this->fixture->createPlayer($retired, 'dupr', 'T11SRC ' . $stem . ' Retired');
        $ids = array_column($this->searchService->RankedPlayers(['q' => "{$kAbbr}:{$abbr} {$stem}", 'limit' => 50]), 'MundaneId');
        $this->assertContains($inActive['mundane_id'], $ids);
        $this->assertNotContains($inRetired['mundane_id'], $ids);

        // Kingdom collisions (real data, e.g. NL active vs retired) — guarded: only when present.
        foreach ($this->fixture->duplicateKingdomAbbreviations() as $dupAbbr => $activeKid) {
            if (!preg_match('/^[a-z0-9]{2,3}$/i', $dupAbbr)) {
                continue;
            }
            $k = $this->searchService->resolve_filter_prefix($dupAbbr, []);
            $this->assertNotNull($k, $dupAbbr);
            $this->assertSame($activeKid, $k['kingdomId']);
            [$searchKid] = $this->searchPrefix("{$dupAbbr}: someone");
            $this->assertSame($activeKid, $searchKid, $dupAbbr);
        }
    }

    /**
     * [kingdomId, parkId] that RankedPlayers' typed-prefix parser resolves for $q.
     *
     * @return array{0: int, 1: int}
     */
    private function searchPrefix(string $q): array
    {
        $m = new ReflectionMethod(SearchService::class, 'resolveAbbrevPrefix');
        $m->setAccessible(true);
        [$kid, $pid] = $m->invoke($this->searchService, $q);

        return [(int) $kid, (int) $pid];
    }

    public function testPrincipalityParkInParentParkListAndPrefix(): void
    {
        $pair = $this->fixture->principalityPair();
        if ($pair === null) {
            $this->markTestSkipped('Need an active kingdom/principality pair with a park.');
        }
        $parent = $pair['parent'];
        $child = $pair['child'];
        $pAbbrK = $this->fixture->kingdomAbbreviation($parent);
        $cAbbrK = $this->fixture->kingdomAbbreviation($child);
        $abbr = $this->fixture->unusedParkAbbreviation($parent, $child);
        if (!preg_match('/^[a-z0-9]{2,3}$/i', $pAbbrK) || $abbr === '') {
            $this->markTestSkipped('Fixture abbreviations are not prefix-shaped.');
        }
        $childPark = $this->fixture->createPark($child, $abbr, 'Principality ' . $abbr);

        // Parent's park list rolls down to the principality, own parks first, each row labelled.
        $parks = $this->searchService->filter_scope_parks($parent, []);
        $byId = array_column($parks, null, 'id');
        $this->assertArrayHasKey($childPark, $byId);
        $this->assertSame($child, $byId[$childPark]['kingdomId']);
        $this->assertSame($cAbbrK, $byId[$childPark]['kingdomAbbr']);
        $seenChild = false;
        foreach ($parks as $row) {
            if ($row['kingdomId'] !== $parent) {
                $seenChild = true;
            } else {
                $this->assertFalse($seenChild, 'Own parks must precede principality parks.');
            }
        }
        // ...but only within the field's scope, and never upwards from the principality.
        $this->assertNotContains($childPark, array_column($this->searchService->filter_scope_parks($parent, ['restrictKingdomIds' => [$parent]]), 'id'));
        $this->assertNotContains($childPark, array_column($this->searchService->filter_scope_parks($parent, ['excludeKingdomId' => $child]), 'id'));
        foreach ($this->searchService->filter_scope_parks($child, []) as $row) {
            $this->assertSame($child, $row['kingdomId']);
        }

        // Parent prefix resolves the principality park; the pill keeps the typed kingdom.
        $m = $this->searchService->resolve_filter_prefix("{$pAbbrK}:{$abbr}", []);
        $this->assertNotNull($m);
        $this->assertSame($parent, $m['kingdomId']);
        $this->assertSame($pAbbrK, $m['kingdomAbbr']);
        $this->assertSame($childPark, $m['parkId']);
        $this->assertSame([$parent, $childPark], $this->searchPrefix("{$pAbbrK}:{$abbr} someone"));
        $this->assertNull($this->searchService->resolve_filter_prefix("{$pAbbrK}:{$abbr}", ['restrictKingdomIds' => [$parent]]));

        // Search path finds a member of that park under the parent prefix.
        $stem = 'Prin' . bin2hex(random_bytes(3));
        $member = $this->fixture->createPlayer($childPark, 'prinpk', 'T11SRC ' . $stem . ' Member');
        $this->assertContains($member['mundane_id'], array_column($this->searchService->RankedPlayers(['q' => "{$pAbbrK}:{$abbr} {$stem}", 'limit' => 50]), 'MundaneId'));

        // The parent's own park wins over a principality park with the same abbreviation, even when newer.
        $ownPark = $this->fixture->createPark($parent, $abbr, 'Own ' . $abbr);
        $this->assertSame($ownPark, $this->searchService->resolve_filter_prefix("{$pAbbrK}:{$abbr}", [])['parkId']);
        // Fresh query string: resolveAbbrevPrefix memoises per query.
        $this->assertSame([$parent, $ownPark], $this->searchPrefix("{$pAbbrK}:{$abbr} someone else"));
    }

    public function testUniversalSearchHonorsFilter(): void
    {
        $f = $this->twoParkScopeFixture();

        $results = $this->searchService->UniversalSearch([
            'Query'     => $f['stem'],
            'FilterPid' => $f['parkB'],
            'Focus'     => 'player',
        ]);
        $ids = array_column($results['players'], 'id');
        $this->assertContains($f['q']['mundane_id'], $ids);
        $this->assertNotContains($f['p']['mundane_id'], $ids);
        $parkBName = $this->fixture->parkName($f['parkB']);
        foreach ($results['players'] as $row) {
            $this->assertSame($parkBName, $row['park']);
        }
    }

    /**
     * One active, one inactive, one suspended and one penalty-box player sharing a persona stem.
     *
     * @return array{stem: string, active: int, inactive: int, suspended: int, penalty: int, parkId: int}
     */
    private function tierFixture(): array
    {
        $kid = $this->fixture->firstKingdomId();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $stem = 'Tier' . bin2hex(random_bytes(3));
        $ids = [];
        foreach (['active' => [1, 0, 0], 'inactive' => [0, 0, 0], 'suspended' => [1, 1, 0], 'penalty' => [1, 0, 1]] as $label => [$a, $s, $pb]) {
            $p = $this->fixture->createPlayer($parkId, 'tier' . $label, 'T11SRC ' . $stem . ' ' . ucfirst($label));
            $this->fixture->setMembership($p['mundane_id'], $a, $s, $pb);
            $ids[$label] = $p['mundane_id'];
        }

        return ['stem' => $stem, 'parkId' => $parkId] + $ids;
    }

    /** @return list<int> */
    private function rankedIds(array $opts): array
    {
        return array_map('intval', array_column($this->searchService->RankedPlayers($opts + ['limit' => 50, 'fuzzy' => false]), 'MundaneId'));
    }

    public function testTierFlagsExcludeInactive(): void
    {
        $f = $this->tierFixture();
        $this->assertContains($f['inactive'], $this->rankedIds(['q' => $f['stem']]), 'inactive shown by default');

        $ids = $this->rankedIds(['q' => $f['stem'], 'includeInactive' => false]);
        $this->assertContains($f['active'], $ids);
        $this->assertNotContains($f['inactive'], $ids);
    }

    public function testTierFlagsExcludeActive(): void
    {
        $f = $this->tierFixture();

        $ids = $this->rankedIds(['q' => $f['stem'], 'includeActive' => false]);
        $this->assertNotContains($f['active'], $ids);
        $this->assertContains($f['inactive'], $ids);
    }

    public function testIncludeBannedCannotExceedCaps(): void
    {
        $f = $this->tierFixture();

        // No token: includeBanned=true must not surface suspended or penalty-box players.
        $rows = $this->searchService->RankedPlayers(['q' => $f['stem'], 'includeBanned' => true, 'limit' => 50, 'fuzzy' => false]);
        $ids = array_map('intval', array_column($rows, 'MundaneId'));
        $this->assertNotContains($f['suspended'], $ids);
        $this->assertNotContains($f['penalty'], $ids);
        foreach ($rows as $row) {
            $this->assertSame(0, (int) $row['Suspended']);
            $this->assertSame(0, (int) $row['PenaltyBox']);
        }
        // Banned-only for an unauthorised viewer is empty, not a widening.
        $this->assertSame([], $this->rankedIds(['q' => $f['stem'], 'includeActive' => false, 'includeInactive' => false, 'includeBanned' => true]));

        // Sanity: a global admin does see them, and includeBanned=false hides them again.
        $admin = $this->fixture->createPlayer($f['parkId'], 'tieradmin');
        $this->fixture->insertGlobalAdmin($admin['mundane_id']);
        $adminIds = $this->rankedIds(['q' => $f['stem'], 'token' => $admin['token']]);
        $this->assertContains($f['suspended'], $adminIds);
        $this->assertContains($f['penalty'], $adminIds);
        $noBanned = $this->rankedIds(['q' => $f['stem'], 'token' => $admin['token'], 'includeBanned' => false]);
        $this->assertNotContains($f['suspended'], $noBanned);
        $this->assertNotContains($f['penalty'], $noBanned);
        $this->assertContains($f['active'], $noBanned);
    }

    public function testAllTierFlagsOffReturnsEmpty(): void
    {
        $f = $this->tierFixture();

        $this->assertSame([], $this->searchService->RankedPlayers([
            'q' => $f['stem'], 'includeActive' => false, 'includeInactive' => false, 'includeBanned' => false,
        ]));
        // Fuzzy fallback honours the flags too: a one-edit typo with inactive off finds no inactive player.
        $typo = substr($f['stem'], 0, -1) . (substr($f['stem'], -1) === 'x' ? 'y' : 'x');
        $fuzzyIds = array_map('intval', array_column($this->searchService->RankedPlayers(['q' => $typo, 'includeInactive' => false, 'limit' => 16]), 'MundaneId'));
        $this->assertNotContains($f['inactive'], $fuzzyIds);
    }

    public function testUniversalSearchHonorsTierFlags(): void
    {
        $f = $this->tierFixture();

        $default = array_column($this->searchService->UniversalSearch(['Query' => $f['stem'], 'Focus' => 'player'])['players'], 'id');
        $this->assertContains($f['inactive'], $default);

        $ids = array_column($this->searchService->UniversalSearch([
            'Query' => $f['stem'], 'Focus' => 'player', 'IncludeInactive' => false,
        ])['players'], 'id');
        $this->assertContains($f['active'], $ids);
        $this->assertNotContains($f['inactive'], $ids);

        $none = $this->searchService->UniversalSearch([
            'Query' => $f['stem'], 'Focus' => 'player', 'IncludeActive' => false, 'IncludeInactive' => false, 'IncludeBanned' => true,
        ]);
        $this->assertSame([], $none['players'], 'IncludeBanned without caps yields no players');
    }

    // The header pill strips its prefix, so FilterKid/FilterPid must narrow parks and hide kingdoms
    // exactly as a typed "KD:PK" prefix does.
    public function testUniversalSearchFilterNarrowsParksAndHidesKingdoms(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $otherKid = $this->fixture->kingdomIdNotIn($this->familyOf($kid));
        if ($kid <= 0 || $otherKid <= 0) {
            $this->markTestSkipped('Need two unrelated kingdoms.');
        }
        $stem = 'Scope' . bin2hex(random_bytes(3));
        $own = $this->fixture->createPark($kid, $this->fixture->unusedParkAbbreviation($kid), $stem . ' Own');
        $own2 = $this->fixture->createPark($kid, $this->fixture->unusedParkAbbreviation($kid), $stem . ' Own2');
        $far = $this->fixture->createPark($otherKid, $this->fixture->unusedParkAbbreviation($otherKid), $stem . ' Far');
        $parks = fn (array $r): array => array_column($r['parks'], 'id');

        $none = $this->searchService->UniversalSearch(['Query' => $stem, 'Focus' => 'park']);
        $this->assertEqualsCanonicalizing([$own, $own2, $far], $parks($none));

        $byKingdom = $this->searchService->UniversalSearch(['Query' => $stem, 'FilterKid' => $kid, 'Focus' => 'park']);
        $this->assertEqualsCanonicalizing([$own, $own2], $parks($byKingdom));

        $byPark = $this->searchService->UniversalSearch(['Query' => $stem, 'FilterKid' => $kid, 'FilterPid' => $own2, 'Focus' => 'park']);
        $this->assertSame([$own2], $parks($byPark));

        // A park outside the filtered kingdom's family matches no park.
        $mismatch = $this->searchService->UniversalSearch(['Query' => $stem, 'FilterKid' => $kid, 'FilterPid' => $far, 'Focus' => 'park']);
        $this->assertSame([], $mismatch['parks']);

        // Kingdoms section: shown without a filter, hidden by a kingdom or park filter.
        $kName = $this->fixture->kingdomName($kid);
        $plain = $this->searchService->UniversalSearch(['Query' => $kName, 'Focus' => 'kingdom']);
        $this->assertContains($kid, array_column($plain['kingdoms'], 'id'));
        $this->assertSame([], $this->searchService->UniversalSearch(['Query' => $kName, 'FilterKid' => $kid, 'Focus' => 'kingdom'])['kingdoms']);
        $this->assertSame([], $this->searchService->UniversalSearch(['Query' => $kName, 'FilterPid' => $own, 'Focus' => 'kingdom'])['kingdoms']);
    }

    /** @return list<int> */
    private function familyOf(int $kid): array
    {
        return array_map('intval', (array) Ork3::$Lib->kingdom->GetFamilyKingdomIds($kid));
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
