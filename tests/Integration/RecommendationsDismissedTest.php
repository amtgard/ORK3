<?php

declare(strict_types=1);

namespace Tests\Integration;

use CourtFixture;
use PHPUnit\Framework\TestCase;

/**
 * "Show Dismissed Recommendations" on the Recommendations Manager.
 *
 * A recommendation is never hard-deleted — dismiss stamps deleted_at/deleted_by
 * (Player::DeleteAwardRecommendation) and every active query filters the row out.
 * These tests pin the opt-in that folds those rows back into the Manager's list.
 *
 * The Manager groups rows into (recipient, kingdomaward, rank) CLUSTERS, so the
 * rule under test is cluster-level: a cluster shows as dismissed only when every
 * member recommendation is dismissed. A cluster with even one live member is an
 * ordinary live row and must be untouched by the flag.
 */
final class RecommendationsDismissedTest extends TestCase
{
    private CourtFixture $fixture;
    private \Report $report;
    private int $kingdomId = 0;
    private int $kaId = 0;
    private int $awardId = 0;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Sandbox database not reachable.');
        }

        $this->fixture   = CourtFixture::create();
        $this->report    = new \Report();
        $this->kingdomId = $this->fixture->firstKingdomId();

        $ka = $this->fixture->firstKingdomAwardId($this->kingdomId);
        if (!$ka) {
            $this->markTestSkipped('No kingdomaward in the sandbox kingdom.');
        }
        $this->kaId    = (int) $ka['kingdomaward_id'];
        $this->awardId = (int) $ka['award_id'];
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    /**
     * @return array<int, array<string, mixed>> the page's Groups
     */
    private function page(bool $includeDismissed): array
    {
        $r = $this->report->PlayerAwardRecommendationsPage([
            'RequestedBy'      => 0,
            'KingdomId'        => $this->kingdomId,
            'ParkId'           => 0,
            'Eligibility'      => 'all',
            'IncludeDismissed' => $includeDismissed,
            'Limit'            => 500,
            'Offset'           => 0,
        ]);

        return $r['Groups'] ?? [];
    }

    private function findCluster(array $groups, int $mundaneId): ?array
    {
        foreach ($groups as $g) {
            if ((int) $g['MundaneId'] === $mundaneId) {
                return $g;
            }
        }

        return null;
    }

    /**
     * The regression guard: without the flag the Manager must look exactly as it
     * always has. If this ever fails, dismissed recommendations have leaked into
     * the default view of every officer.
     */
    public function testDismissedClusterIsHiddenByDefault(): void
    {
        $player = $this->fixture->createPlayer('dismhidden', $this->kingdomId);
        $this->fixture->createRecommendation(
            $player['mundane_id'],
            $this->kaId,
            $this->awardId,
            ['dismissed_by' => $player['mundane_id']]
        );

        $this->assertNull(
            $this->findCluster($this->page(false), $player['mundane_id']),
            'A fully dismissed cluster must not appear without IncludeDismissed.'
        );
    }

    public function testDismissedClusterAppearsWhenIncluded(): void
    {
        $player = $this->fixture->createPlayer('dismshown', $this->kingdomId);
        $this->fixture->createRecommendation(
            $player['mundane_id'],
            $this->kaId,
            $this->awardId,
            ['dismissed_by' => $player['mundane_id']]
        );

        $cluster = $this->findCluster($this->page(true), $player['mundane_id']);

        $this->assertNotNull($cluster, 'IncludeDismissed must surface the dismissed cluster.');
        $this->assertTrue((bool) $cluster['IsDismissed'], 'The cluster must be flagged dismissed.');
    }

    /**
     * The cluster rule. Two people recommend the same honor; one recommendation is
     * dismissed, the other is live. The honor is still live, so the row must render
     * as an ordinary live row — not as a dismissed one — and the dismissed member
     * must not be folded into it.
     */
    public function testClusterWithOneLiveMemberStaysLiveAndExcludesTheDismissedMember(): void
    {
        $player   = $this->fixture->createPlayer('dismmixed', $this->kingdomId);
        $advocate = $this->fixture->createPlayer('dismadvoc', $this->kingdomId);

        $liveId = $this->fixture->createRecommendation(
            $player['mundane_id'],
            $this->kaId,
            $this->awardId,
            ['recommended_by_id' => $advocate['mundane_id']]
        );
        $deadId = $this->fixture->createRecommendation(
            $player['mundane_id'],
            $this->kaId,
            $this->awardId,
            ['dismissed_by' => $advocate['mundane_id']]
        );

        $cluster = $this->findCluster($this->page(true), $player['mundane_id']);

        $this->assertNotNull($cluster);
        $this->assertFalse(
            (bool) ($cluster['IsDismissed'] ?? false),
            'A cluster with a live member is not a dismissed cluster.'
        );
        $this->assertContains($liveId, $cluster['MemberRecIds']);
        $this->assertNotContains(
            $deadId,
            $cluster['MemberRecIds'],
            'A dismissed member must not be folded into a live cluster.'
        );
    }

    /**
     * The dismissed row has to say who retired it and when, which is the whole
     * reason the existing Kingdom/Park deleted-recs panel joins deleted_by.
     */
    public function testDismissedClusterCarriesWhoDismissedItAndWhen(): void
    {
        $player  = $this->fixture->createPlayer('dismwho', $this->kingdomId);
        $officer = $this->fixture->createPlayer('dismofficer', $this->kingdomId);

        $this->fixture->createRecommendation(
            $player['mundane_id'],
            $this->kaId,
            $this->awardId,
            ['dismissed_by' => $officer['mundane_id']]
        );

        $cluster = $this->findCluster($this->page(true), $player['mundane_id']);

        $this->assertNotNull($cluster);
        $this->assertSame($officer['persona'], $cluster['DismissedByPersona']);
        $this->assertNotEmpty($cluster['DismissedAt']);
    }

    /**
     * Restore is the undelete the row's trash-can-arrow-up button drives. It must
     * clear BOTH columns: the active queries filter on deleted_by while the
     * deleted-recs report keys on deleted_at, so clearing one would leave the row
     * invisible in both views.
     */
    public function testRestoreClearsBothDeletionColumns(): void
    {
        $player = $this->fixture->createPlayer('dismrestore', $this->kingdomId);
        $recId  = $this->fixture->createRecommendation(
            $player['mundane_id'],
            $this->kaId,
            $this->awardId,
            ['dismissed_by' => $player['mundane_id']]
        );

        $before = $this->fixture->fetchRecommendation($recId);
        $this->assertNotNull($before['deleted_by']);
        $this->assertNotNull($before['deleted_at']);

        $this->fixture->pdo()->exec(
            'UPDATE ' . DB_PREFIX . 'recommendations
             SET deleted_at = NULL, deleted_by = NULL
             WHERE recommendations_id = ' . (int) $recId
        );

        $after = $this->fixture->fetchRecommendation($recId);
        $this->assertNull($after['deleted_by']);
        $this->assertNull($after['deleted_at']);

        $this->assertNotNull(
            $this->findCluster($this->page(false), $player['mundane_id']),
            'A restored recommendation returns to the default (non-dismissed) list.'
        );
    }
}
