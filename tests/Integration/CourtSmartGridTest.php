<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** In-memory ghettocache: the test environment's Memcached stub never returns a hit. */
final class SmartGridInMemoryGhettocache extends Ghettocache
{
    /** @var array<string, mixed> */
    private array $store = [];

    public function get($call, $key, $lifetime)
    {
        return $this->store[$call . '.' . $key] ?? false;
    }

    public function cache($call, $key, $content)
    {
        $this->store[$call . '.' . $key] = $content;

        return $content;
    }
}

/**
 * Smart Planning Grid: the court-authorized recommendation and court-line data,
 * and the column-to-award resolver.
 */
final class CourtSmartGridTest extends TestCase
{
    /** Order of the Rose: a catalog ladder, and in Award::GetLadderMasterMap(). */
    private const ROSE = 21;

    private CourtFixture $fx;
    private int $k;
    private int $p;
    private int $ka;
    private array $officer;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->fx = CourtFixture::create();
        unset($_SESSION['is_authorized_mundane_id']);

        $this->k  = $this->fx->firstKingdomId();
        $this->p  = $this->fx->firstParkId($this->k);
        $this->ka = (int)$this->fx->kingdomAwardFor($this->k, self::ROSE)['kingdomaward_id'];
        $this->officer = $this->kingdomOfficer();
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
        unset($_SESSION['is_authorized_mundane_id']);
    }

    private function kingdomOfficer(): array
    {
        $o = $this->fx->createPlayerWithToken('sg-kingdom', $this->k, $this->p);
        $this->fx->insertScopedAuth($o['mundane_id'], 0, $this->k, 'edit');

        return $o;
    }

    private function parkOfficer(): array
    {
        $o = $this->fx->createPlayerWithToken('sg-park', $this->k, $this->p);
        $this->fx->insertScopedAuth($o['mundane_id'], $this->p, 0, 'edit');

        return $o;
    }

    private function recipient(string $tag = 'recip'): int
    {
        return (int)$this->fx->createPlayer('sg-' . $tag, $this->k, $this->p)['mundane_id'];
    }

    private function rec(int $mundaneId, int $rank, array $overrides = []): int
    {
        return $this->fx->createRecommendation($mundaneId, $this->ka, self::ROSE, ['rank' => $rank] + $overrides);
    }

    /** getSmartGridState for the park grid, as $caller. */
    private function state(int $courtId = 0, ?array $caller = null, int $parkId = -1, bool $fresh = false): array
    {
        $caller ??= $this->officer;
        unset($_SESSION['is_authorized_mundane_id']);

        return (new \Court())->getSmartGridState(
            $this->k,
            $parkId < 0 ? $this->p : $parkId,
            $courtId,
            (int)$caller['mundane_id'],
            (string)$caller['token'],
            $fresh
        );
    }

    /** @return list<int> */
    private function recIds(array $state): array
    {
        return array_map(static fn ($r) => $r['RecommendationsId'], $state['Recs']);
    }

    private function addLine(int $courtId, int $mundaneId, int $rank, int $recId = 0): int
    {
        $res = (new \Court())->addAward(
            $courtId,
            $this->k,
            $mundaneId,
            $this->ka,
            $rank,
            $recId,
            0,
            '',
            '',
            true,
            (int)$this->officer['mundane_id']
        );
        $this->assertIsArray($res, 'the kingdom officer may add this line');

        return (int)$res['CourtAwardId'];
    }

    private function setLineStatus(int $courtAwardId, string $status): void
    {
        $this->fx->pdo()->exec(
            'UPDATE ' . DB_PREFIX . "court_award SET status = '" . $status . "' WHERE court_award_id = " . $courtAwardId
        );
    }

    private function sortRow(int $id, int $seconds, string $date, bool $fallback = false): array
    {
        return [
            'RecommendationsId' => $id,
            'SecondsCount'      => $seconds,
            'DateRecommended'   => $date,
            'IsFallback'        => $fallback,
        ];
    }

    // ---- Sort rule ----------------------------------------------------------

    public function testSortPrefersSecondsThenOldest(): void
    {
        $fourMonthsOld = $this->sortRow(1, 0, '2026-06-30');
        $oneMonthOld   = $this->sortRow(2, 14, '2026-09-30');
        $this->assertSame(
            [2, 1],
            array_column(\Court::sortSmartGridRecs([$fourMonthsOld, $oneMonthOld]), 'RecommendationsId'),
            'seconds outrank age'
        );

        $older = $this->sortRow(4, 3, '2026-01-01');
        $newer = $this->sortRow(3, 3, '2026-02-01');
        $this->assertSame(
            [4, 3],
            array_column(\Court::sortSmartGridRecs([$newer, $older]), 'RecommendationsId'),
            'on equal seconds the older comes first'
        );

        $lower  = $this->sortRow(5, 3, '2026-01-01');
        $higher = $this->sortRow(6, 3, '2026-01-01');
        $this->assertSame(
            [5, 6],
            array_column(\Court::sortSmartGridRecs([$higher, $lower]), 'RecommendationsId'),
            'on equal date the lower id comes first'
        );
    }

    public function testSortPutsFallbackLast(): void
    {
        $snoozed = $this->sortRow(1, 20, '2020-01-01', true);
        $plain   = $this->sortRow(2, 0, '2026-10-01');

        $this->assertSame(
            [2, 1],
            array_column(\Court::sortSmartGridRecs([$snoozed, $plain]), 'RecommendationsId')
        );
    }

    public function testStateRecsComeBackSortedOnSeconds(): void
    {
        $recip = $this->recipient();
        $older = $this->rec($recip, 3, ['date_recommended' => '2026-01-01']);
        $newer = $this->rec($recip, 3, ['date_recommended' => '2026-09-01']);
        $this->fx->addSecond($newer, (int)$this->officer['mundane_id']);

        $state = $this->state();
        $mine = array_values(array_filter($state['Recs'], static fn ($r) => $r['MundaneId'] === $recip));

        $this->assertSame([$newer, $older], array_column($mine, 'RecommendationsId'));
        $this->assertSame([1, 0], array_column($mine, 'SecondsCount'));
    }

    // ---- Which recs ---------------------------------------------------------

    public function testStateExcludesRankZeroAlreadyHasAndCoveredByMaster(): void
    {
        $rankZero = $this->rec($this->recipient('rank0'), 0);

        $holder = $this->recipient('holder');
        $this->fx->createLedgerAward($holder, $this->ka, self::ROSE, 3);
        $alreadyHas = $this->rec($holder, 2);
        $stillDue   = $this->rec($holder, 4);

        $master = $this->recipient('master');
        $this->fx->createLedgerAward($master, $this->ka, \Award::GetLadderMasterMap()[self::ROSE]['MasterAwardIds'][0], 0);
        $covered = $this->rec($master, 5);

        $ids = $this->recIds($this->state());

        $this->assertNotContains($rankZero, $ids, 'a rank-0 rec has no level to match');
        $this->assertNotContains($alreadyHas, $ids, 'the player already holds this rank');
        $this->assertNotContains($covered, $ids, 'a Master peerage covers the ladder');
        $this->assertContains($stillDue, $ids, 'a rank above what the player holds stays');
    }

    public function testStateExcludesRecFromAnotherKingdomsAward(): void
    {
        $other = $this->fx->otherKingdomId($this->k);
        $foreignKa = $this->fx->createKingdomAward($other, self::ROSE, 'foreign-rose');
        $recip = $this->recipient();
        $foreign = $this->fx->createRecommendation($recip, $foreignKa, self::ROSE, ['rank' => 2]);
        $own = $this->rec($recip, 2);

        $ids = $this->recIds($this->state());

        $this->assertNotContains($foreign, $ids);
        $this->assertContains($own, $ids);
    }

    public function testMonarchyOnlyRecHiddenFromParkOfficer(): void
    {
        $recip = $this->recipient();
        $hidden = $this->rec($recip, 2, ['monarchy_only' => 2, 'reason' => 'secret reason']);
        $parkLevel = $this->rec($recip, 3, ['monarchy_only' => 1, 'reason' => 'park secret']);
        $parkOfficer = $this->parkOfficer();
        $this->assertTrue((new \Court())->canManage($parkOfficer['mundane_id'], $this->k, $this->p));

        $seenByPark = $this->recIds($this->state(0, $parkOfficer));
        $this->assertNotContains($hidden, $seenByPark, 'a park officer must not see a kingdom-level hide');
        $this->assertContains($parkLevel, $seenByPark, 'a park officer may see a park-level hide');

        $this->assertContains($hidden, $this->recIds($this->state()), 'a kingdom officer may see it');
    }

    public function testMonarchyOnlyRecKeysOnTokenNotCallerUid(): void
    {
        $hidden = $this->rec($this->recipient(), 2, ['monarchy_only' => 2]);
        $parkOfficer = $this->parkOfficer();

        // A kingdom officer's uid with a park officer's token sees what the token sees.
        $forged = ['mundane_id' => $this->officer['mundane_id'], 'token' => $parkOfficer['token']];
        $this->assertNotContains($hidden, $this->recIds($this->state(0, $forged)));

        $noToken = ['mundane_id' => $this->officer['mundane_id'], 'token' => ''];
        $this->assertNotContains($hidden, $this->recIds($this->state(0, $noToken)), 'no token sees no hidden rec');
    }

    public function testStateNeverContainsReasonOrRecommender(): void
    {
        $author = $this->fx->createPlayer('sg-author', $this->k, $this->p);
        $reason = 'Fixture reason ' . bin2hex(random_bytes(4));
        $recip = $this->recipient();
        $rec = $this->rec($recip, 2, ['reason' => $reason, 'recommended_by_id' => $author['mundane_id']]);
        $court = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => $this->p, 'name' => 'sg-this']);
        $other = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => 0, 'name' => 'sg-other']);
        $this->addLine($court, $recip, 2, $rec);
        $this->addLine($other, $recip, 2, $rec);
        $listed = $this->rec($recip, 3, ['reason' => $reason, 'recommended_by_id' => $author['mundane_id']]);

        $state = $this->state($court);

        $this->assertContains($listed, $this->recIds($state));
        $row = array_column($state['Recs'], null, 'RecommendationsId')[$listed];
        $this->assertSame(
            ['RecommendationsId', 'MundaneId', 'KingdomAwardId', 'Rank', 'SecondsCount', 'IsFallback'],
            array_keys($row)
        );

        $json = json_encode($state);
        $this->assertStringNotContainsString('Reason', $json);
        $this->assertStringNotContainsString('RecommendedByName', $json);
        $this->assertStringNotContainsString($reason, $json);
        $this->assertStringNotContainsString($author['persona'], $json);
    }

    public function testRecOnAnotherOpenCourtIsFallback(): void
    {
        $recip = $this->recipient();
        $plain     = $this->rec($recip, 2);
        $onOpen    = $this->rec($recip, 3);
        $onDone    = $this->rec($recip, 4);
        $onDropped = $this->rec($recip, 5);
        $snoozed   = $this->rec($recip, 6);
        $onThis    = $this->rec($recip, 7);
        $this->fx->snoozeRecommendation($snoozed);

        $court = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => $this->p]);
        $open  = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => 0]);
        $done  = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => 0, 'status' => 'complete']);
        $this->addLine($open, $recip, 3, $onOpen);
        $this->addLine($open, $recip, 5, $onDropped);
        $this->addLine($court, $recip, 7, $onThis);
        // Written directly: addAward refuses a complete court.
        $doneLine = $this->fx->createAward($done, $recip, ['kingdomaward_id' => $this->ka, 'rank' => 4]);
        $this->fx->pdo()->exec(
            'UPDATE ' . DB_PREFIX . 'court_award SET recommendations_id = ' . $onDone . ' WHERE court_award_id = ' . $doneLine
        );
        $this->setLineStatus(
            (int)$this->fx->pdo()->query(
                'SELECT court_award_id FROM ' . DB_PREFIX . 'court_award WHERE recommendations_id = ' . $onDropped
            )->fetchColumn(),
            'cancelled'
        );

        $state = $this->state($court);
        $byId = array_column($state['Recs'], 'IsFallback', 'RecommendationsId');

        $this->assertFalse($byId[$plain], 'a rec on no court is first choice');
        $this->assertTrue($byId[$onOpen], 'a rec on another open court is a fallback');
        $this->assertTrue($byId[$snoozed], 'a snoozed rec is a fallback');
        $this->assertFalse($byId[$onDone], 'a complete court is not an open court');
        $this->assertFalse($byId[$onDropped], 'a cancelled line is not a live line');
        $this->assertArrayNotHasKey($onThis, $byId, 'a rec already on this court is dropped');

        $this->assertArrayHasKey($onThis, array_column($this->state()['Recs'], 'IsFallback', 'RecommendationsId'));
        $this->assertTrue(
            array_column($this->state()['Recs'], 'IsFallback', 'RecommendationsId')[$onThis],
            'with no court chosen, every open court counts as another court'
        );
    }

    public function testFreshReadsPastTheRecCache(): void
    {
        $original = \Ork3::$Lib->ghettocache;
        \Ork3::$Lib->ghettocache = new SmartGridInMemoryGhettocache();
        try {
            $recip = $this->recipient();
            $first = $this->rec($recip, 2);
            $this->assertContains($first, $this->recIds($this->state()), 'this read fills the cache');

            $later = $this->rec($recip, 3);
            $this->assertNotContains($later, $this->recIds($this->state()), 'a default read is served from the cache');

            $fresh = $this->recIds($this->state(0, null, -1, true));
            $this->assertContains($later, $fresh, 'fresh sees the rec the cache does not have yet');
            $this->assertContains($first, $fresh);
        } finally {
            \Ork3::$Lib->ghettocache = $original;
        }
    }

    public function testPendingRecommendationsStillReadTheCache(): void
    {
        $original = \Ork3::$Lib->ghettocache;
        \Ork3::$Lib->ghettocache = new SmartGridInMemoryGhettocache();
        try {
            $recip = $this->recipient();
            $this->rec($recip, 2);
            $court = new \Court();
            $mine = static fn (array $recs) => array_values(array_filter($recs, static fn ($r) => $r['MundaneId'] === $recip));
            $uid = (int)$this->officer['mundane_id'];
            $token = (string)$this->officer['token'];

            $this->assertCount(1, $mine($court->getPendingRecommendations($this->k, $this->p, $uid, 0, $token)));
            $this->rec($recip, 3);
            $this->assertCount(1, $mine($court->getPendingRecommendations($this->k, $this->p, $uid, 0, $token)));
        } finally {
            \Ork3::$Lib->ghettocache = $original;
        }
    }

    // ---- Lines --------------------------------------------------------------

    public function testOtherCourtLinesCoverKingdomAndParkCourtsOnly(): void
    {
        $recip = $this->recipient();
        $thisCourt = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => $this->p, 'name' => 'sg-this']);
        $parkC    = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => $this->p, 'name' => 'sg-park']);
        $kingdomC = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => 0, 'name' => 'sg-kingdom']);
        $doneC    = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => 0, 'name' => 'sg-done', 'status' => 'complete']);
        $otherC   = $this->fx->createCourt(['kingdom_id' => $this->fx->otherKingdomId($this->k), 'name' => 'sg-foreign']);

        $line = ['kingdomaward_id' => $this->ka, 'rank' => 2];
        $this->fx->createAward($thisCourt, $recip, $line);
        $this->fx->createAward($parkC, $recip, $line);
        $this->fx->createAward($kingdomC, $recip, $line);
        $this->fx->createAward($doneC, $recip, $line);
        $this->fx->createAward($otherC, $recip, $line);
        $this->fx->createAward($kingdomC, $recip, ['status' => 'cancelled'] + ['kingdomaward_id' => $this->ka, 'rank' => 3]);

        $lines = array_values(array_filter(
            $this->state($thisCourt)['OtherCourtLines'],
            static fn ($l) => $l['MundaneId'] === $recip
        ));

        usort($lines, static fn ($a, $b) => $a['CourtId'] <=> $b['CourtId']);
        $this->assertSame([
            ['MundaneId' => $recip, 'KingdomAwardId' => $this->ka, 'CourtId' => $parkC, 'CourtName' => 'sg-park'],
            ['MundaneId' => $recip, 'KingdomAwardId' => $this->ka, 'CourtId' => $kingdomC, 'CourtName' => 'sg-kingdom'],
        ], $lines);
    }

    public function testOtherCourtLineBackedByHiddenRecIsWithheld(): void
    {
        $recip = $this->recipient();
        $hidden = $this->rec($recip, 2, ['monarchy_only' => 2]);
        $kingdomC = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => 0, 'name' => 'sg-kingdom']);
        $this->addLine($kingdomC, $recip, 2, $hidden);
        $this->addLine($kingdomC, $recip, 3);

        $linesFor = static fn (array $state) => array_values(array_filter(
            $state['OtherCourtLines'],
            static fn ($l) => $l['MundaneId'] === $recip
        ));

        $this->assertCount(1, $linesFor($this->state(0, $this->parkOfficer())), 'only the walk-on line shows');
        $this->assertCount(2, $linesFor($this->state()), 'a kingdom officer sees both');
    }

    public function testCourtLinesExcludeCancelled(): void
    {
        $recip = $this->recipient();
        $rec = $this->rec($recip, 2);
        $court = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => $this->p]);
        $live = $this->addLine($court, $recip, 2, $rec);
        $dead = $this->addLine($court, $recip, 3);
        $this->setLineStatus($dead, 'cancelled');

        $lines = $this->state($court)['CourtLines'];

        $this->assertSame([$live], array_column($lines, 'CourtAwardId'));
        $this->assertSame(
            ['CourtAwardId', 'MundaneId', 'Persona', 'AwardName', 'KingdomAwardId', 'Rank', 'RecommendationsId', 'Status'],
            array_keys($lines[0])
        );
        $this->assertSame($rec, $lines[0]['RecommendationsId']);
        $this->assertSame('planned', $lines[0]['Status']);
        $this->assertSame(2, $lines[0]['Rank']);

        $this->assertSame([], $this->state(0)['CourtLines'], 'no court, no lines');
    }

    public function testCourtLineBackedByHiddenRecCarriesNoRecIdForACallerWhoCannotSeeIt(): void
    {
        $recip = $this->recipient();
        $hidden = $this->rec($recip, 2, ['monarchy_only' => 2]);
        $court = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => $this->p]);
        $this->addLine($court, $recip, 2, $hidden);

        $parkView = $this->state($court, $this->parkOfficer())['CourtLines'];
        $this->assertCount(1, $parkView, 'the line itself stays: this officer runs this court');
        $this->assertNull($parkView[0]['RecommendationsId']);

        $this->assertSame($hidden, $this->state($court)['CourtLines'][0]['RecommendationsId']);
    }

    // ---- CourtAjax/smart_grid_state and ladder_grid ---------------------------

    /** Runs a controller action in a child process and decodes what it printed. */
    private function endpoint(string $action, ?array $caller, array $params, bool $emptyRecCache = false): mixed
    {
        $input = json_encode([
            'action' => $action,
            'uid'    => $caller['mundane_id'] ?? 0,
            'token'  => $caller['token'] ?? '',
            'emptyRecCache' => $emptyRecCache,
            $action === 'ladder_grid' ? 'get' : 'post' => $params,
        ]);
        $driver = dirname(__DIR__) . '/Support/smart_court_endpoint_driver.php';
        $cmd = PHP_BINARY . ' -d error_reporting=0 ' . escapeshellarg($driver) . ' ' . escapeshellarg($input);
        $out = (string)shell_exec($cmd . ' 2>/dev/null');

        $decoded = json_decode($out, true);
        $this->assertTrue(json_last_error() === JSON_ERROR_NONE, 'driver printed: ' . $out);

        return $decoded;
    }

    private function gridState(?array $caller, array $post, bool $emptyRecCache = false): array
    {
        return $this->endpoint('smart_grid_state', $caller, $post, $emptyRecCache);
    }

    public function testEndpointRefusesLoggedOutAndNonOfficer(): void
    {
        $this->assertSame(5, $this->gridState(null, ['KingdomId' => $this->k])['status']);

        $player = $this->fx->createPlayerWithToken('sg-player', $this->k, $this->p);
        $this->assertSame(3, $this->gridState($player, ['KingdomId' => $this->k, 'ParkId' => $this->p])['status']);
        $this->assertSame(3, $this->gridState($player, ['KingdomId' => $this->k])['status']);
    }

    public function testEndpointServesOfficerAndCarriesTheSessionToken(): void
    {
        $hidden = $this->rec($this->recipient(), 2, ['monarchy_only' => 2]);

        $kingdom = $this->gridState($this->officer, ['KingdomId' => $this->k, 'ParkId' => $this->p]);
        $this->assertSame(0, $kingdom['status']);
        $this->assertSame(['Recs', 'OtherCourtLines', 'CourtLines'], array_keys($kingdom['state']));
        $this->assertContains($hidden, array_column($kingdom['state']['Recs'], 'RecommendationsId'));

        $park = $this->gridState($this->parkOfficer(), ['KingdomId' => $this->k, 'ParkId' => $this->p]);
        $this->assertSame(0, $park['status']);
        $this->assertNotContains($hidden, array_column($park['state']['Recs'], 'RecommendationsId'));
    }

    public function testEndpointRefusesParkOfficerOnTheKingdomGrid(): void
    {
        $this->assertSame(3, $this->gridState($this->parkOfficer(), ['KingdomId' => $this->k])['status']);
    }

    public function testEndpointRefusesCourtInAnotherScopeWhateverIsPosted(): void
    {
        $foreign = $this->fx->otherKingdomId($this->k);
        $court = $this->fx->createCourt(['kingdom_id' => $foreign]);

        $res = $this->gridState($this->officer, ['KingdomId' => $this->k, 'ParkId' => $this->p, 'CourtId' => $court]);
        $this->assertSame(3, $res['status']);
        $this->assertArrayNotHasKey('state', $res);
    }

    public function testEndpointTakesScopeFromTheCourt(): void
    {
        $recip = $this->recipient();
        $court = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => $this->p]);
        $line = $this->addLine($court, $recip, 2);

        $foreign = $this->fx->otherKingdomId($this->k);
        $res = $this->gridState($this->parkOfficer(), ['KingdomId' => $foreign, 'CourtId' => $court]);

        $this->assertSame(0, $res['status']);
        $this->assertSame([$line], array_column($res['state']['CourtLines'], 'CourtAwardId'));
    }

    public function testEndpointDerivesKingdomFromThePark(): void
    {
        $foreign = $this->fx->otherKingdomId($this->k);
        $foreignRecip = (int)$this->fx->createPlayer('sg-foreign', $foreign, 0)['mundane_id'];
        $foreignCourt = $this->fx->createCourt(['kingdom_id' => $foreign]);
        $foreignLine = ['kingdomaward_id' => $this->ka, 'rank' => 2];
        $this->fx->createAward($foreignCourt, $foreignRecip, $foreignLine);

        // Park authority alone passes canManage, so a posted kingdom must not widen scope.
        $res = $this->gridState($this->parkOfficer(), ['KingdomId' => $foreign, 'ParkId' => $this->p]);
        $this->assertSame(0, $res['status']);
        $this->assertNotContains($foreignCourt, array_column($res['state']['OtherCourtLines'], 'CourtId'));

        // And an officer of this kingdom gets nothing by naming another kingdom's park.
        $foreignPark = $this->fx->firstParkId($foreign);
        $this->assertGreaterThan(0, $foreignPark);
        $this->assertSame(3, $this->gridState($this->officer, ['KingdomId' => $this->k, 'ParkId' => $foreignPark])['status']);
    }

    public function testMismatchedCourtGetsItsParksRealKingdomNotTheForgedOne(): void
    {
        $foreign = $this->fx->otherKingdomId($this->k);
        $forged = $this->fx->createCourt(['kingdom_id' => $foreign, 'park_id' => $this->p, 'name' => 'sg-forged']);

        $foreignCourt = $this->fx->createCourt(['kingdom_id' => $foreign, 'name' => 'sg-foreign']);
        $realCourt = $this->fx->createCourt(['kingdom_id' => $this->k, 'name' => 'sg-real']);
        $line = ['kingdomaward_id' => $this->ka, 'rank' => 2];
        $this->fx->createAward($foreignCourt, $this->recipient('foreign'), $line);
        $this->fx->createAward($realCourt, $this->recipient('real'), $line);

        $res = $this->gridState($this->parkOfficer(), ['CourtId' => $forged]);

        $this->assertSame(0, $res['status']);
        $courts = array_column($res['state']['OtherCourtLines'], 'CourtId');
        $this->assertContains($realCourt, $courts, 'the park\'s real kingdom');
        $this->assertNotContains($foreignCourt, $courts, 'the kingdom the court claims');
    }

    public function testMismatchedCourtCannotOpenAnotherKingdomsParkToThisKingdomsOfficer(): void
    {
        // The reverse forgery: requireCourtAuth passes on the court's stored kingdom,
        // so the resolved scope needs its own check.
        $foreign = $this->fx->otherKingdomId($this->k);
        $foreignPark = $this->fx->firstParkId($foreign);
        $this->assertGreaterThan(0, $foreignPark);
        $forged = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => $foreignPark]);

        $res = $this->gridState($this->officer, ['CourtId' => $forged]);

        $this->assertSame(3, $res['status']);
        $this->assertArrayNotHasKey('state', $res);
    }

    public function testEndpointFreshBypassesTheRecCache(): void
    {
        $rec = $this->rec($this->recipient(), 2);
        $scope = ['KingdomId' => $this->k, 'ParkId' => $this->p];

        $cached = $this->gridState($this->officer, $scope, true);
        $this->assertNotContains($rec, array_column($cached['state']['Recs'], 'RecommendationsId'));

        $off = $this->gridState($this->officer, $scope + ['Fresh' => '0'], true);
        $this->assertNotContains($rec, array_column($off['state']['Recs'], 'RecommendationsId'), 'Fresh=0 is the default');

        $fresh = $this->gridState($this->officer, $scope + ['Fresh' => '1'], true);
        $this->assertContains($rec, array_column($fresh['state']['Recs'], 'RecommendationsId'));
    }

    public function testSmartCourtConfigOnlyReachesCourtAuthority(): void
    {
        $parkGrid = ['ParkId' => $this->p];
        $kingdomGrid = ['KingdomId' => $this->k];
        $parkOfficer = $this->parkOfficer();

        $player = $this->fx->createPlayerWithToken('sg-player', $this->k, $this->p);
        $this->assertNull($this->endpoint('ladder_grid', $player, $parkGrid));
        $this->assertNull($this->endpoint('ladder_grid', $parkOfficer, $kingdomGrid), 'a park officer has none on the kingdom grid');

        $open = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => $this->p, 'name' => 'sg-open', 'court_date' => '2026-11-01']);
        $done = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => $this->p, 'name' => 'sg-done', 'status' => 'complete']);
        $kingdomCourt = $this->fx->createCourt(['kingdom_id' => $this->k, 'park_id' => 0, 'name' => 'sg-kingdom']);

        $config = $this->endpoint('ladder_grid', $parkOfficer, $parkGrid);
        $this->assertSame(['KingdomId', 'ParkId', 'ColumnAwards', 'KaToColumn', 'Courts', 'Events'], array_keys($config));
        $this->assertSame($this->k, $config['KingdomId']);
        $this->assertSame($this->p, $config['ParkId']);
        $this->assertSame($this->ka, $config['ColumnAwards'][self::ROSE]);

        $courts = array_column($config['Courts'], null, 'CourtId');
        $this->assertArrayHasKey($open, $courts);
        $this->assertArrayNotHasKey($done, $courts, 'a complete court is not offered');
        $this->assertArrayNotHasKey($kingdomCourt, $courts, 'a park grid offers that park\'s courts');
        $this->assertSame(
            ['CourtId', 'Name', 'CourtDate', 'Status', 'AwardCount'],
            array_keys($courts[$open])
        );

        $kingdomConfig = $this->endpoint('ladder_grid', $this->officer, $kingdomGrid);
        $this->assertSame(0, $kingdomConfig['ParkId']);
        $this->assertContains($kingdomCourt, array_column($kingdomConfig['Courts'], 'CourtId'));
    }

    // ---- Columns ------------------------------------------------------------

    public function testColumnsResolveRealAndPseudoLadders(): void
    {
        $secondRose = $this->fx->createKingdomAward($this->k, self::ROSE, 'second-rose');
        $pseudoLow  = $this->fx->createKingdomAward($this->k, 0, 'hunter');
        $pseudoHigh = $this->fx->createKingdomAward($this->k, 0, 'hunter-split');
        $key = 'k' . $pseudoHigh;

        $cols = (new \Court())->getSmartGridColumns($this->k, [
            self::ROSE => ['Name' => 'Order of the Rose'],
            $key       => ['Name' => 'Order of the Hunter', 'KingdomAwardIds' => [$pseudoHigh, $pseudoLow]],
        ]);

        $this->assertSame($this->ka, $cols['ColumnAwards'][self::ROSE], 'lowest kingdomaward_id in this kingdom');
        $this->assertSame($pseudoLow, $cols['ColumnAwards'][$key]);

        $this->assertSame((string)self::ROSE, $cols['KaToColumn'][$this->ka]);
        $this->assertSame((string)self::ROSE, $cols['KaToColumn'][$secondRose]);
        $this->assertSame($key, $cols['KaToColumn'][$pseudoLow]);
        $this->assertSame($key, $cols['KaToColumn'][$pseudoHigh]);
    }

    public function testPseudoRowSharingACatalogIdStaysInItsOwnColumn(): void
    {
        // 12 of the 25 pseudo rows point at award_id 94, so a pseudo row can carry a
        // catalog id. It belongs to its pseudo column, as in the grid's own CASE.
        $pseudo = $this->fx->createKingdomAward($this->k, self::ROSE, 'pseudo-on-rose');
        $key = 'k' . $pseudo;

        $cols = (new \Court())->getSmartGridColumns($this->k, [
            self::ROSE => ['Name' => 'Order of the Rose'],
            $key       => ['Name' => 'Sharpshooter', 'KingdomAwardIds' => [$pseudo]],
        ]);

        $this->assertSame($key, $cols['KaToColumn'][$pseudo]);
        $this->assertSame($pseudo, $cols['ColumnAwards'][$key]);
        $this->assertSame($this->ka, $cols['ColumnAwards'][self::ROSE]);
    }

    public function testInheritedOnlyPseudoColumnHasNoColumnAward(): void
    {
        $parentRow = $this->fx->createKingdomAward($this->fx->otherKingdomId($this->k), 0, 'parent-hunter');
        $key = 'k' . $parentRow;

        $cols = (new \Court())->getSmartGridColumns($this->k, [
            $key => ['Name' => 'Order of the Hunter', 'KingdomAwardIds' => [$parentRow]],
        ]);

        $this->assertArrayNotHasKey($key, $cols['ColumnAwards']);
        $this->assertArrayNotHasKey($parentRow, $cols['KaToColumn'], 'no row of this kingdom maps to it');
    }

    public function testColumnWithNoRowInThisKingdomHasNoColumnAward(): void
    {
        $cols = (new \Court())->getSmartGridColumns($this->k, [999999 => ['Name' => 'Nothing here']]);

        $this->assertSame(['ColumnAwards' => [], 'KaToColumn' => []], $cols);
    }
}
