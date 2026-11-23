<?php

declare(strict_types=1);

namespace Tests\Integration;

use CourtFixture;
use PHPUnit\Framework\TestCase;

/**
 * ork_recommendations.granted_awards_id: every grant path records the award the rec
 * was granted as, Restore clears it, and the closed-recs reads (Recs Manager status
 * views, DeletedAwardRecommendations) carry it only while that award is still live.
 */
final class RecommendationGrantedAwardTest extends TestCase
{
    private CourtFixture $fx;
    private int $k = 0;
    private int $p = 0;
    /** @var array{kingdomaward_id:int, award_id:int}|array{} */
    private array $ka = [];
    /** @var list<int> */
    private array $made = [];
    /** @var list<int> */
    private array $recipients = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Sandbox database not reachable.');
        }
        $this->fx = CourtFixture::create();
        $hasCol = (int) $this->fx->pdo()->query(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
              AND table_name = '" . DB_PREFIX . "recommendations' AND column_name = 'granted_awards_id'"
        )->fetchColumn();
        if ($hasCol === 0) {
            $this->markTestSkipped('Sandbox lacks ork_recommendations.granted_awards_id (2026-11-23-recommendation-granted-award.sql).');
        }
        $this->k  = $this->fx->firstKingdomId();
        $this->p  = $this->fx->firstParkId($this->k);
        $ka       = $this->fx->firstKingdomAwardId($this->k);
        if (!$ka || $this->p <= 0) {
            $this->markTestSkipped('No park / kingdomaward in the sandbox kingdom.');
        }
        $this->ka = ['kingdomaward_id' => (int) $ka['kingdomaward_id'], 'award_id' => (int) $ka['award_id']];
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);
        if (isset($this->fx)) {
            if ($this->recipients) {
                $ids = implode(',', array_map('intval', $this->recipients));
                $this->fx->pdo()->exec('DELETE FROM ' . DB_PREFIX . "awards WHERE mundane_id IN ($ids) OR stripped_from IN ($ids)");
            }
            if ($this->made) {
                $this->fx->pdo()->exec('DELETE FROM ' . DB_PREFIX . 'recommendation_seconds WHERE recommendations_id IN (' . implode(',', array_map('intval', $this->made)) . ')');
            }
            $this->fx->cleanup();
        }
    }

    /** @return array{recipient:array, officer:array} */
    private function cast(): array
    {
        $recipient = $this->fx->createPlayerWithToken('gaw-recip', $this->k, $this->p);
        $officer   = $this->fx->createPlayerWithToken('gaw-off', $this->k, $this->p);
        $this->fx->insertScopedAuth($officer['mundane_id'], $this->p, 0, 'create');
        $this->recipients[] = (int) $recipient['mundane_id'];

        return ['recipient' => $recipient, 'officer' => $officer];
    }

    private function award(array $c, int $rank, int $revoked = 0): int
    {
        return $this->fx->createLedgerAward($c['recipient']['mundane_id'], $this->ka['kingdomaward_id'], $this->ka['award_id'], $rank, '', '', $revoked);
    }

    private function rec(array $c, int $rank): int
    {
        $id = $this->fx->createRecommendation(
            $c['recipient']['mundane_id'],
            $this->ka['kingdomaward_id'],
            $this->ka['award_id'],
            ['rank' => $rank, 'recommended_by_id' => $c['officer']['mundane_id']]
        );
        $this->made[] = $id;

        return $id;
    }

    private function grantedAwardsId(int $id): int
    {
        return (int) $this->fx->pdo()->query('SELECT granted_awards_id FROM ' . DB_PREFIX . 'recommendations WHERE recommendations_id = ' . $id)->fetchColumn();
    }

    private function call(string $method, array $c, array $req): array
    {
        unset($_SESSION['is_authorized_mundane_id']);

        return \Ork3::$Lib->player->$method(['Token' => $c['officer']['token']] + $req);
    }

    public function testClusterGrantRecordsTheBackingAward(): void
    {
        $c   = $this->cast();
        $id  = $this->rec($c, 4);
        $aid = $this->award($c, 4);
        $r   = $this->call('ResolveRecommendationCluster', $c, [
            'MundaneId' => $c['recipient']['mundane_id'], 'KingdomAwardId' => $this->ka['kingdomaward_id'], 'Rank' => 4,
        ]);
        $this->assertSame(0, $r['Status']);
        $this->assertSame($aid, $this->grantedAwardsId($id));
    }

    public function testCourtLineGrantPrefersTheLinesOwnAward(): void
    {
        $c     = $this->cast();
        $id    = $this->rec($c, 3);
        $line  = $this->award($c, 3);
        $this->award($c, 5);   // newer, also backs the rec; the line's award must win
        $this->assertTrue(\Ork3::$Lib->player->resolve_court_line_recommendation($id, $c['recipient']['mundane_id'], $c['officer']['mundane_id'], $line));
        $this->assertSame($line, $this->grantedAwardsId($id));
    }

    public function testRelabelOfADismissedRecRecordsTheAward(): void
    {
        $c  = $this->cast();
        $id = $this->rec($c, 6);
        $this->call('DeleteAwardRecommendation', $c, ['RecommendationsId' => $id]);
        $this->assertSame(0, $this->grantedAwardsId($id), 'a dismissal links no award');
        $aid = $this->award($c, 6);
        $this->assertTrue(\Ork3::$Lib->player->resolve_court_line_recommendation($id, $c['recipient']['mundane_id'], $c['officer']['mundane_id']));
        $this->assertSame($aid, $this->grantedAwardsId($id));
    }

    public function testClosedReadsCarryTheAwardOnlyWhileItIsLive(): void
    {
        $c   = $this->cast();
        $id  = $this->rec($c, 7);
        $aid = $this->award($c, 7);
        $this->call('ResolveRecommendationCluster', $c, [
            'MundaneId' => $c['recipient']['mundane_id'], 'KingdomAwardId' => $this->ka['kingdomaward_id'], 'Rank' => 7,
        ]);

        $row = $this->deletedRow($c, $id);
        $this->assertSame('granted', $row['Resolution'] ?? null);
        $this->assertSame($aid, $row['GrantedAwardsId'] ?? null);

        $page = \Ork3::$Lib->report->PlayerAwardRecommendationsPage([
            'KingdomId' => $this->k, 'Token' => $c['officer']['token'], 'Status' => 'granted',
            'Search' => (string) $c['recipient']['persona'], 'Limit' => 50, 'Offset' => 0,
        ]);
        $grp = null;
        foreach ($page['Groups'] as $g) {
            if ((int) $g['MundaneId'] === (int) $c['recipient']['mundane_id']) {
                $grp = $g;
            }
        }
        $this->assertNotNull($grp, 'the Granted view lists the granted cluster');
        $this->assertTrue($grp['IsGranted']);
        $this->assertSame($aid, $grp['GrantedAwardsId']);

        // Revoked: the rec stays granted, but there is nothing live to link to.
        $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . 'awards SET revoked = 1 WHERE awards_id = ' . $aid);
        $this->assertSame(0, $this->deletedRow($c, $id)['GrantedAwardsId'] ?? null);
    }

    private function deletedRow(array $c, int $id): array
    {
        $r = \Ork3::$Lib->report->DeletedAwardRecommendations([
            'PlayerId' => $c['recipient']['mundane_id'], 'Token' => $c['officer']['token'], 'Limit' => 50,
        ]);
        foreach ($r['AwardRecommendations'] as $row) {
            if ((int) $row['RecommendationsId'] === $id) {
                return $row;
            }
        }

        return [];
    }
}
