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
    /** @var list<int> recipients whose AddAward-written ork_awards rows are swept */
    private array $recipients = [];

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
            if ($this->recipients) {
                $this->fx->pdo()->exec('DELETE FROM ' . DB_PREFIX . 'awards WHERE mundane_id IN (' . implode(',', array_map('intval', $this->recipients)) . ')'
                    . ' OR stripped_from IN (' . implode(',', array_map('intval', $this->recipients)) . ')');
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
        $recipient = $this->fx->createPlayerWithToken('res-recip', $this->k, $this->p);
        $officer   = $this->fx->createPlayerWithToken('res-off', $this->k, $this->p);
        $this->fx->insertScopedAuth($officer['mundane_id'], $this->p, 0, 'create');
        $this->recipients[] = (int) $recipient['mundane_id'];

        return ['recipient' => $recipient, 'officer' => $officer];
    }

    /** A row on the recipient's permanent record. */
    private function award(array $c, int $rank, string $date = '', string $enteredAt = '', int $revoked = 0): int
    {
        return $this->fx->createLedgerAward(
            $c['recipient']['mundane_id'],
            $this->ka['kingdomaward_id'],
            $this->ka['award_id'],
            $rank,
            $date,
            $enteredAt,
            $revoked
        );
    }

    private function cluster(array $c, int $rank): array
    {
        unset($_SESSION['is_authorized_mundane_id']);

        return \Ork3::$Lib->player->ResolveRecommendationCluster([
            'Token' => $c['officer']['token'], 'MundaneId' => $c['recipient']['mundane_id'],
            'KingdomAwardId' => $this->ka['kingdomaward_id'], 'Rank' => $rank,
        ]);
    }

    private function isLive(int $id): bool
    {
        return (int) ($this->fx->fetchRecommendation($id)['deleted_by'] ?? 0) === 0;
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

    public function testDismissRecordsDismissed(): void
    {
        $c   = $this->cast();
        $dis = $this->rec($c, 1);
        $this->assertSame(0, $this->call('DeleteAwardRecommendation', $c['officer']['token'], ['RecommendationsId' => $dis])['Status']);
        $this->assertSame('dismissed', $this->resolution($dis));
    }

    public function testAForgedGrantedFlagIsIgnored(): void
    {
        $c  = $this->cast();
        $id = $this->rec($c, 2);
        // Even with a backing award on the record, the JSON-callable dismiss never grants.
        $this->award($c, 2);
        $this->assertSame(0, $this->call('DeleteAwardRecommendation', $c['officer']['token'], ['RecommendationsId' => $id, 'Granted' => 1])['Status']);
        $this->assertSame('dismissed', $this->resolution($id));
    }

    public function testClusterResolveRecordsGrantedWhenAnAwardBacksIt(): void
    {
        $c = $this->cast();
        $a = $this->rec($c, 3);
        $b = $this->rec($c, 3);
        $this->award($c, 3);
        $r = $this->cluster($c, 3);
        $this->assertSame(0, $r['Status']);
        $this->assertSame(2, $r['Resolved']);
        $this->assertSame('granted', $this->resolution($a));
        $this->assertSame('granted', $this->resolution($b));
    }

    public function testClusterGrantWithoutABackingAwardIsRefusedAndLeftOpen(): void
    {
        $c  = $this->cast();
        $id = $this->rec($c, 3);
        $r  = $this->cluster($c, 3);
        $this->assertNotSame(0, $r['Status']);
        $this->assertSame(1, $r['Unbacked']);
        $this->assertTrue($this->isLive($id), 'no award: the rec stays open');
        $this->assertNull($this->resolution($id));
    }

    public function testALowerRankRevokedOrEarlierAwardDoesNotBackARec(): void
    {
        $c  = $this->cast();
        $id = $this->rec($c, 4);
        $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . 'recommendations SET date_recommended = \'' . date('Y-m-d', strtotime('-10 days')) . '\' WHERE recommendations_id = ' . $id);
        $this->award($c, 3);                                    // lower rank
        $this->award($c, 4, '', '', 1);                         // revoked
        $old = date('Y-m-d', strtotime('-30 days'));
        $this->award($c, 4, $old, $old . ' 12:00:00');          // given and entered before the rec
        $this->assertNotSame(0, $this->cluster($c, 4)['Status']);
        $this->assertTrue($this->isLive($id));

        // Backdated before the rec, but keyed in after it: backs it.
        $this->award($c, 4, $old);
        $this->assertSame(0, $this->cluster($c, 4)['Status']);
        $this->assertSame('granted', $this->resolution($id));
    }

    public function testCourtLineCommitRecordsGrantedOnlyWithABackingAward(): void
    {
        $c  = $this->cast();
        $id = $this->rec($c, 4);
        $this->assertFalse(\Ork3::$Lib->player->resolve_court_line_recommendation($id, $c['recipient']['mundane_id'], $c['officer']['mundane_id']));
        $this->assertTrue($this->isLive($id));

        $this->award($c, 4);
        $this->assertTrue(\Ork3::$Lib->player->resolve_court_line_recommendation($id, $c['recipient']['mundane_id'], $c['officer']['mundane_id']));
        $this->assertSame('granted', $this->resolution($id));
    }

    public function testADismissedRecIsRelabelledGrantedWhenTheAwardIsGiven(): void
    {
        $c     = $this->cast();
        $tok   = $c['officer']['token'];
        $viaCl = $this->rec($c, 5);
        $viaCt = $this->rec($c, 5);
        $this->call('DeleteAwardRecommendation', $tok, ['RecommendationsId' => $viaCl]);
        $this->call('DeleteAwardRecommendation', $tok, ['RecommendationsId' => $viaCt]);
        $this->assertSame('dismissed', $this->resolution($viaCl));

        $this->award($c, 5);
        $r = $this->cluster($c, 5);
        $this->assertSame(0, $r['Status']);
        $this->assertSame(2, $r['Resolved'], 'both dismissed siblings relabel');
        $this->assertSame('granted', $this->resolution($viaCl));
        $this->assertSame((int) $c['officer']['mundane_id'], (int) $this->fx->fetchRecommendation($viaCl)['deleted_by']);

        // The court-line path relabels too (re-dismiss it first to test that path alone).
        $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . "recommendations SET resolution = 'dismissed' WHERE recommendations_id = " . $viaCt);
        $this->assertTrue(\Ork3::$Lib->player->resolve_court_line_recommendation($viaCt, $c['recipient']['mundane_id'], $c['officer']['mundane_id']));
        $this->assertSame('granted', $this->resolution($viaCt));
    }

    public function testAStaleDismissDoesNotRelabelAGrantedRec(): void
    {
        $c  = $this->cast();
        $id = $this->rec($c, 5);
        $this->award($c, 5);
        $this->cluster($c, 5);
        $this->assertSame(0, $this->call('DeleteAwardRecommendation', $c['officer']['token'], ['RecommendationsId' => $id])['Status']);
        $this->assertSame('granted', $this->resolution($id));
    }

    private function addAward(array $c, int $rank, int $recId): array
    {
        unset($_SESSION['is_authorized_mundane_id']);

        return \Ork3::$Lib->player->AddAward([
            'Token' => $c['officer']['token'], 'RecipientId' => $c['recipient']['mundane_id'],
            'KingdomAwardId' => $this->ka['kingdomaward_id'], 'AwardId' => 0, 'Rank' => $rank,
            'Date' => date('Y-m-d'), 'GivenById' => $c['officer']['mundane_id'], 'CustomName' => '',
            'Note' => '', 'ParkId' => $this->p, 'KingdomId' => 0, 'EventId' => 0,
            'RecommendationsId' => $recId,
        ]);
    }

    public function testGiveFromRecViaAddAwardGrantsTheRec(): void
    {
        $c  = $this->cast();
        $id = $this->rec($c, 6);
        $r  = $this->addAward($c, 6, $id);
        $this->assertSame(0, (int) $r['Status'], (string) ($r['Detail'] ?? ''));
        $this->assertTrue($r['RecClosed']);
        $this->assertSame('granted', $this->resolution($id));
    }

    public function testGiveFromRecAtALowerRankLeavesTheRecOpen(): void
    {
        $c  = $this->cast();
        $id = $this->rec($c, 6);
        $r  = $this->addAward($c, 5, $id);
        $this->assertSame(0, (int) $r['Status'], (string) ($r['Detail'] ?? ''));
        $this->assertFalse($r['RecClosed']);
        $this->assertNotSame('', (string) ($r['RecError'] ?? ''));
        $this->assertTrue($this->isLive($id));
    }

    public function testRestoreRefusesGrantedAndClearsDismissed(): void
    {
        $c   = $this->cast();
        $tok = $c['officer']['token'];
        $gr  = $this->rec($c, 6);
        $dis = $this->rec($c, 7);
        $this->award($c, 6);
        $this->cluster($c, 6);
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
        $this->award($c, 8);
        $this->cluster($c, 8);
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
