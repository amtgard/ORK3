<?php

declare(strict_types=1);

namespace Tests\Integration;

use CourtFixture;
use PHPUnit\Framework\TestCase;

/**
 * A granted recommendation and a dismissed one are both soft deletes; the
 * `resolution` column is what tells them apart. Granted recs must read as granted
 * in the closed-recs views and must never be Restored (a reopened granted rec is a
 * live Grant button on an honor the player already holds).
 */
final class RecommendationResolutionTest extends TestCase
{
    private CourtFixture $fx;
    private int $k = 0;
    private int $p = 0;
    /** @var array{kingdomaward_id:int, award_id:int}|array{} */
    private array $ka = [];
    /** @var list<int> */
    private array $made = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Sandbox database not reachable.');
        }
        $this->fx = CourtFixture::create();
        $hasCol = (int) $this->fx->pdo()->query(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
              AND table_name = '" . DB_PREFIX . "recommendations' AND column_name = 'resolution'"
        )->fetchColumn();
        if ($hasCol === 0) {
            $this->markTestSkipped('Sandbox lacks ork_recommendations.resolution (2026-11-22-recommendation-resolution.sql).');
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
            if ($this->made) {
                $this->fx->pdo()->exec('DELETE FROM ' . DB_PREFIX . 'recommendation_seconds WHERE recommendations_id IN (' . implode(',', array_map('intval', $this->made)) . ')');
            }
            $this->fx->cleanup();
        }
    }

    /** @return array{recipient:array, officer:array} */
    private function cast(): array
    {
        $recipient = $this->fx->createPlayerWithToken('res-recip', $this->k, $this->p);
        $officer   = $this->fx->createPlayerWithToken('res-off', $this->k, $this->p);
        $this->fx->insertScopedAuth($officer['mundane_id'], $this->p, 0, 'create');

        return ['recipient' => $recipient, 'officer' => $officer];
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

    private function call(string $method, string $token, array $req): array
    {
        unset($_SESSION['is_authorized_mundane_id']);

        return \Ork3::$Lib->player->$method(['Token' => $token] + $req);
    }

    private function resolution(int $id): ?string
    {
        $v = $this->fx->pdo()->query('SELECT resolution FROM ' . DB_PREFIX . 'recommendations WHERE recommendations_id = ' . $id)->fetchColumn();

        return $v === null || $v === false ? null : (string) $v;
    }

    public function testDismissRecordsDismissedAndGrantRecordsGranted(): void
    {
        $c   = $this->cast();
        $tok = $c['officer']['token'];
        $dis = $this->rec($c, 1);
        $gr  = $this->rec($c, 2);

        $this->assertSame(0, $this->call('DeleteAwardRecommendation', $tok, ['RecommendationsId' => $dis])['Status']);
        $this->assertSame(0, $this->call('DeleteAwardRecommendation', $tok, ['RecommendationsId' => $gr, 'Granted' => 1])['Status']);

        $this->assertSame('dismissed', $this->resolution($dis));
        $this->assertSame('granted', $this->resolution($gr));
    }

    public function testClusterResolveRecordsGranted(): void
    {
        $c  = $this->cast();
        $a  = $this->rec($c, 3);
        $b  = $this->rec($c, 3);
        unset($_SESSION['is_authorized_mundane_id']);
        $r = \Ork3::$Lib->player->ResolveRecommendationCluster([
            'Token' => $c['officer']['token'], 'MundaneId' => $c['recipient']['mundane_id'],
            'KingdomAwardId' => $this->ka['kingdomaward_id'], 'Rank' => 3,
        ]);
        $this->assertSame(2, $r['Resolved']);
        $this->assertSame('granted', $this->resolution($a));
        $this->assertSame('granted', $this->resolution($b));
    }

    public function testCourtLineCommitRecordsGranted(): void
    {
        $c  = $this->cast();
        $id = $this->rec($c, 4);
        $this->assertTrue(\Ork3::$Lib->player->resolve_court_line_recommendation($id, $c['recipient']['mundane_id'], $c['officer']['mundane_id']));
        $this->assertSame('granted', $this->resolution($id));
    }

    public function testAStaleDismissDoesNotRelabelAGrantedRec(): void
    {
        $c   = $this->cast();
        $tok = $c['officer']['token'];
        $id  = $this->rec($c, 5);
        $this->call('DeleteAwardRecommendation', $tok, ['RecommendationsId' => $id, 'Granted' => 1]);
        $this->assertSame(0, $this->call('DeleteAwardRecommendation', $tok, ['RecommendationsId' => $id])['Status']);
        $this->assertSame('granted', $this->resolution($id));
    }

    public function testRestoreRefusesGrantedAndClearsDismissed(): void
    {
        $c   = $this->cast();
        $tok = $c['officer']['token'];
        $gr  = $this->rec($c, 6);
        $dis = $this->rec($c, 7);
        $this->call('DeleteAwardRecommendation', $tok, ['RecommendationsId' => $gr, 'Granted' => 1]);
        $this->call('DeleteAwardRecommendation', $tok, ['RecommendationsId' => $dis]);

        $refused = $this->call('RestoreAwardRecommendation', $tok, ['RecommendationsId' => $gr]);
        $this->assertNotSame(0, $refused['Status']);
        $this->assertStringContainsString('granted', strtolower((string) ($refused['Detail'] ?? '')));
        $this->assertNotNull($this->fx->fetchRecommendation($gr)['deleted_at'], 'a granted rec stays closed');
        $this->assertSame('granted', $this->resolution($gr));

        $this->assertSame(0, $this->call('RestoreAwardRecommendation', $tok, ['RecommendationsId' => $dis])['Status']);
        $this->assertNull($this->fx->fetchRecommendation($dis)['deleted_at']);
        $this->assertNull($this->resolution($dis), 'a restored rec is live again, with no resolution');
    }

    public function testDeletedRecommendationsReportsTheResolution(): void
    {
        $c   = $this->cast();
        $tok = $c['officer']['token'];
        $gr  = $this->rec($c, 8);
        $dis = $this->rec($c, 9);
        $this->call('DeleteAwardRecommendation', $tok, ['RecommendationsId' => $gr, 'Granted' => 1]);
        $this->call('DeleteAwardRecommendation', $tok, ['RecommendationsId' => $dis]);

        $r = \Ork3::$Lib->report->DeletedAwardRecommendations([
            'PlayerId' => $c['recipient']['mundane_id'], 'Token' => $tok, 'Limit' => 50,
        ]);
        $by = [];
        foreach ($r['AwardRecommendations'] as $row) {
            $by[(int) $row['RecommendationsId']] = $row['Resolution'];
        }
        $this->assertSame('granted', $by[$gr] ?? null);
        $this->assertSame('dismissed', $by[$dis] ?? null);
    }
}
