<?php

declare(strict_types=1);

namespace Tests\Integration;

use CourtFixture;
use PHPUnit\Framework\TestCase;

/**
 * Monarchy Only recommendations — every Report reader (and the public seconds
 * lookup) applies RecommendationVisibility for the TOKEN's viewer.
 */
final class RecommendationMonarchyOnlyReadersTest extends TestCase
{
    private CourtFixture $fx;
    private int $k = 0;
    private int $p = 0;
    private int $otherPark = 0;
    /** @var list<int> */
    private array $secondIds = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Sandbox database not reachable.');
        }
        $this->fx = CourtFixture::create();
        $this->k  = $this->fx->firstKingdomId();
        $this->p  = $this->fx->firstParkId($this->k);
        $this->otherPark = $this->fx->secondParkId($this->k, $this->p);
        if (!$this->otherPark) {
            $this->markTestSkipped('Sandbox kingdom needs two parks.');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            if ($this->secondIds) {
                $this->fx->pdo()->exec(
                    'DELETE FROM ' . DB_PREFIX . 'recommendation_seconds WHERE recommendation_seconds_id IN ('
                    . implode(',', array_map('intval', $this->secondIds)) . ')'
                );
                $this->secondIds = [];
            }
            $this->fx->cleanup();
        }
        unset($_SESSION['is_authorized_mundane_id']);
    }

    /**
     * Same cast as RecommendationVisibilityTest, but each entry keeps the full
     * createPlayerWithToken() result so tests can read ['token'] and ['persona'].
     *
     * @return array<string, array{mundane_id:int, persona:string, token:string}>
     */
    private function cast(): array
    {
        $recipient = $this->fx->createPlayerWithToken('recip', $this->k, $this->p);
        $author    = $this->fx->createPlayerWithToken('author', $this->k, $this->p);
        $parkOff   = $this->fx->createPlayerWithToken('parkoff', $this->k, $this->p);
        $this->fx->insertScopedAuth($parkOff['mundane_id'], $this->p, 0, 'edit');
        $otherOff  = $this->fx->createPlayerWithToken('otheroff', $this->k, $this->otherPark);
        $this->fx->insertScopedAuth($otherOff['mundane_id'], $this->otherPark, 0, 'edit');
        $kOff      = $this->fx->createPlayerWithToken('koff', $this->k, $this->p);
        $this->fx->insertScopedAuth($kOff['mundane_id'], 0, $this->k, 'edit');
        $seat      = $this->fx->createPlayerWithToken('seat', $this->k, $this->p);
        $this->fx->insertOfficer($seat['mundane_id'], $this->k, 0, 'Monarch');
        $plain     = $this->fx->createPlayerWithToken('plain', $this->k, $this->p);
        $admin     = $this->fx->createPlayerWithToken('admin', $this->k, $this->p);
        $this->fx->insertGlobalAdmin($admin['mundane_id']);

        return ['recipient' => $recipient, 'author' => $author, 'parkOfficer' => $parkOff,
            'otherParkOfficer' => $otherOff, 'kingdomOfficer' => $kOff, 'seatMonarch' => $seat,
            'plain' => $plain, 'admin' => $admin];
    }

    /**
     * One public (rank 1), one park-level (rank 2) and one kingdom-level (rank 3)
     * recommendation, all for the same recipient, all by `author`.
     *
     * @return array{0: array, 1: int, 2: int, 3: int}
     */
    private function seed(): array
    {
        $c  = $this->cast();
        $ka = $this->fx->firstKingdomAwardId($this->k);
        $ids = [];
        foreach ([0, 1, 2] as $lvl) {
            $ids[$lvl] = $this->fx->createRecommendation(
                $c['recipient']['mundane_id'],
                (int) $ka['kingdomaward_id'],
                (int) $ka['award_id'],
                ['monarchy_only' => $lvl, 'recommended_by_id' => $c['author']['mundane_id'], 'rank' => 1 + $lvl]
            );
        }
        unset($_SESSION['is_authorized_mundane_id']);

        return [$c, $ids[0], $ids[1], $ids[2]];
    }

    private function idsFrom(array $resp): array
    {
        $ids = array_map(fn ($r) => (int) $r['RecommendationsId'], $resp['AwardRecommendations'] ?? []);
        sort($ids);
        return $ids;
    }

    private function mainIds(\Report $report, array $q, string $token): array
    {
        unset($_SESSION['is_authorized_mundane_id']);

        return $this->idsFrom($report->PlayerAwardRecommendations($q + ['Token' => $token]));
    }

    public function testMainReaderFiltersPerTokenViewer(): void
    {
        [$c, $pub, $park, $king] = $this->seed();
        $report = new \Report();
        $q = ['KingdomId' => 0, 'ParkId' => 0, 'PlayerId' => $c['recipient']['mundane_id']];

        $this->assertSame([$pub], $this->mainIds($report, $q, $c['plain']['token']));
        $this->assertSame([$pub], $this->mainIds($report, $q, $c['recipient']['token']));
        $this->assertSame([$pub, $park], $this->mainIds($report, $q, $c['parkOfficer']['token']));
        $this->assertSame([$pub, $park, $king], $this->mainIds($report, $q, $c['kingdomOfficer']['token']));
        $this->assertSame([$pub, $park, $king], $this->mainIds($report, $q, $c['author']['token']));
        // Cached response must not leak between viewers: ask the plain player again after the officer warmed it.
        $this->assertSame([$pub], $this->mainIds($report, $q, $c['plain']['token']));
    }

    public function testForgedRequestedByWithoutTokenSeesNoHiddenRows(): void
    {
        [$c, $pub] = $this->seed();
        unset($_SESSION['is_authorized_mundane_id']);
        $resp = (new \Report())->PlayerAwardRecommendations([
            'KingdomId' => 0, 'ParkId' => 0, 'PlayerId' => $c['recipient']['mundane_id'],
            'RequestedBy' => $c['kingdomOfficer']['mundane_id'],
        ]);
        $this->assertSame([$pub], $this->idsFrom($resp));
    }

    public function testRowsCarryLevels(): void
    {
        [$c, $pub, $park, $king] = $this->seed();
        $this->fx->setPassedToLocal($king, true);
        unset($_SESSION['is_authorized_mundane_id']);
        $resp = (new \Report())->PlayerAwardRecommendations([
            'KingdomId' => 0, 'ParkId' => 0, 'PlayerId' => $c['recipient']['mundane_id'], 'SkipCache' => 1,
            'Token' => $c['kingdomOfficer']['token'],
        ]);
        $byId = [];
        foreach ($resp['AwardRecommendations'] as $r) {
            $byId[(int) $r['RecommendationsId']] = $r;
        }
        $this->assertSame(0, $byId[$pub]['MonarchyOnlyLevel']);
        $this->assertSame(1, $byId[$park]['MonarchyOnlyLevel']);
        $this->assertSame(2, $byId[$king]['MonarchyOnly']);
        $this->assertSame(1, $byId[$king]['MonarchyOnlyLevel']);
    }

    public function testGroupsCarryLevels(): void
    {
        $report = new \Report();
        $groups = $report->groupRecommendations([
            ['RecommendationsId' => 1, 'MundaneId' => 9, 'KingdomAwardId' => 5, 'Rank' => 1, 'MonarchyOnlyLevel' => 0],
            ['RecommendationsId' => 2, 'MundaneId' => 9, 'KingdomAwardId' => 5, 'Rank' => 1, 'MonarchyOnlyLevel' => 2],
            ['RecommendationsId' => 3, 'MundaneId' => 9, 'KingdomAwardId' => 5, 'Rank' => 2, 'MonarchyOnlyLevel' => 1],
            ['RecommendationsId' => 4, 'MundaneId' => 9, 'KingdomAwardId' => 5, 'Rank' => 3, 'MonarchyOnlyLevel' => 0],
        ]);
        $byRank = [];
        foreach ($groups as $g) {
            $byRank[$g['Rank']] = $g;
        }
        $this->assertSame(2, $byRank[1]['MonarchyOnlyLevel']);
        $this->assertFalse($byRank[1]['MonarchyOnlyAll']);
        $this->assertSame(1, $byRank[2]['MonarchyOnlyLevel']);
        $this->assertTrue($byRank[2]['MonarchyOnlyAll']);
        $this->assertSame(0, $byRank[3]['MonarchyOnlyLevel']);
        $this->assertFalse($byRank[3]['MonarchyOnlyAll']);
        $this->assertArrayNotHasKey('_moMax', $byRank[1]);
        $this->assertArrayNotHasKey('_moAll', $byRank[1]);
    }

    public function testPageTotalMatchesDeliveredGroupsForParkOfficer(): void
    {
        [$c] = $this->seed();
        unset($_SESSION['is_authorized_mundane_id']);
        $resp = (new \Report())->PlayerAwardRecommendationsPage([
            'ParkId' => $this->p, 'KingdomId' => 0, 'Eligibility' => 'all',
            'Search' => $c['recipient']['persona'], 'Token' => $c['parkOfficer']['token'],
        ]);
        $this->assertSame(2, $resp['Total']);
        $this->assertCount(2, $resp['Groups']);
    }

    public function testCountExcludesHiddenForRecipientOfficer(): void
    {
        [$c] = $this->seed();
        $report = new \Report();
        unset($_SESSION['is_authorized_mundane_id']);
        $base = (int) $report->PlayerAwardRecommendationsCount(['KingdomId' => $this->k, 'Token' => $c['plain']['token']]);
        unset($_SESSION['is_authorized_mundane_id']);
        $asKing = (int) $report->PlayerAwardRecommendationsCount(['KingdomId' => $this->k, 'Token' => $c['kingdomOfficer']['token']]);
        $this->assertSame($base + 2, $asKing);
    }

    public function testDismissedPanelFilters(): void
    {
        [$c, $pub, $park, $king] = $this->seed();
        $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . "recommendations SET deleted_at = NOW(), deleted_by = {$c['author']['mundane_id']} WHERE recommendations_id IN ($pub,$park,$king)");
        unset($_SESSION['is_authorized_mundane_id']);
        $resp = (new \Report())->DeletedAwardRecommendations(['PlayerId' => $c['recipient']['mundane_id'], 'KingdomId' => 0, 'ParkId' => 0, 'Token' => $c['parkOfficer']['token']]);
        $this->assertSame([$pub, $park], $this->idsFrom($resp));
    }

    public function testPublicSecondsWrapperDropsHiddenRecs(): void
    {
        [$c, $pub, $park, $king] = $this->seed();
        $ins = $this->fx->pdo()->prepare(
            'INSERT INTO ' . DB_PREFIX . 'recommendation_seconds (recommendations_id, supporter_mundane_id, notes, created_at)
             VALUES (?, ?, ?, NOW())'
        );
        foreach ([$pub, $park, $king] as $rid) {
            $ins->execute([$rid, $c['plain']['mundane_id'], 'Fixture second.']);
            $this->secondIds[] = (int) $this->fx->pdo()->lastInsertId();
        }
        unset($_SESSION['is_authorized_mundane_id']);
        $out = \Ork3::$Lib->player->GetSecondsForRecommendations([$pub, $park, $king], 0);
        $this->assertArrayHasKey($pub, $out);
        $this->assertArrayNotHasKey($park, $out);
        $this->assertArrayNotHasKey($king, $out);
    }

    /**
     * The unfiltered seconds reader must not be reachable through orkservice/Json.
     * PHP method names are case-insensitive, so a lowercase first letter does not
     * hide a method: `Player/SecondsForRecommendations` passes the validator and
     * dispatches to it. Only a `_` in the name is refused by every spelling.
     */
    public function testUnfilteredSecondsReaderIsNotJsonCallable(): void
    {
        $server   = new \JsonServer(['Player']);
        $validate = new \ReflectionMethod(\JsonServer::class, 'validate_method');
        $validate->setAccessible(true);
        foreach (['SecondsForRecommendations', 'SecondsforRecommendations', 'SECONDSFORRECOMMENDATIONS',
                  'Seconds_for_recommendations', 'Seconds_For_Recommendations'] as $spelling) {
            $reachable = $validate->invoke($server, 'Player', $spelling) && method_exists(\Player::class, $spelling);
            $this->assertFalse($reachable, "Player/$spelling reaches an unfiltered reader through the JSON service");
        }
        $this->assertTrue(method_exists(\Player::class, 'seconds_for_recommendations'));
        // The public wrapper stays callable.
        $this->assertTrue($validate->invoke($server, 'Player', 'GetSecondsForRecommendations'));
    }
}
