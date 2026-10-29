<?php

declare(strict_types=1);

namespace Tests\Integration;

use CourtFixture;
use PDO;
use Player;
use PHPUnit\Framework\TestCase;

/**
 * Dismissing a recommendation is authorized by WHO THE TOKEN BELONGS TO, never by a
 * caller-supplied RequestedBy. Player::DeleteAwardRecommendation is reachable through
 * the public JSON service, so anything the caller can type is untrusted: a stranger
 * claiming to be the recommender (or the recipient) must be refused, and the
 * deleted_by / Granted-notification attribution must name the real caller.
 */
final class RecommendationDismissAuthTest extends TestCase
{
    private CourtFixture $fixture;
    private Player $player;
    private int $kingdomId = 0;
    private int $parkId = 0;
    /** @var list<int> */
    private array $people = [];
    /** @var array{kingdomaward_id: int, award_id: int}|array{} */
    private array $ka = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Sandbox database not reachable.');
        }

        $this->fixture   = CourtFixture::create();
        $this->player    = new Player();
        $this->kingdomId = $this->fixture->firstKingdomId();
        $this->parkId    = $this->fixture->firstParkId($this->kingdomId);
        $ka              = $this->fixture->firstKingdomAwardId($this->kingdomId);
        if (!$ka || $this->parkId <= 0) {
            $this->markTestSkipped('No park / kingdomaward in the sandbox kingdom.');
        }
        $this->ka = ['kingdomaward_id' => (int) $ka['kingdomaward_id'], 'award_id' => (int) $ka['award_id']];
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);
        if (!isset($this->fixture)) {
            return;
        }
        if ($this->people) {
            $this->fixture->pdo()->exec(
                'DELETE FROM ' . DB_PREFIX . 'notification WHERE mundane_id IN (' . implode(',', $this->people) . ')'
            );
        }
        $this->fixture->cleanup();
    }

    /** @return array{id: int, token: string} */
    private function person(string $tag): array
    {
        $id = $this->fixture->createPlayer($tag, $this->kingdomId, $this->parkId)['mundane_id'];
        $this->people[] = $id;

        return ['id' => $id, 'token' => $this->fixture->issueToken($id)];
    }

    /** @return array{id: int, token: string} a holder of park-create authority over the recipient's park */
    private function parkOfficer(): array
    {
        $p = $this->person('officer');
        $this->fixture->insertScopedAuth($p['id'], $this->parkId, 0, 'create');

        return $p;
    }

    private function recommendation(int $recipientId, int $recommenderId): int
    {
        return $this->fixture->createRecommendation(
            $recipientId,
            $this->ka['kingdomaward_id'],
            $this->ka['award_id'],
            ['recommended_by_id' => $recommenderId, 'rank' => 1]
        );
    }

    private function dismiss(string $token, int $recId, int $requestedBy, int $granted = 0): array
    {
        unset($_SESSION['is_authorized_mundane_id']);

        return $this->player->DeleteAwardRecommendation([
            'Token'             => $token,
            'RecommendationsId' => $recId,
            'RequestedBy'       => $requestedBy,
            'Granted'           => $granted,
        ]);
    }

    private function deletedBy(int $recId): int
    {
        return (int) ($this->fixture->fetchRecommendation($recId)['deleted_by'] ?? 0);
    }

    private function addSecond(int $recId, int $supporterId): int
    {
        $this->fixture->pdo()->prepare(
            'INSERT INTO ' . DB_PREFIX . 'recommendation_seconds (recommendations_id, supporter_mundane_id, notes)
             VALUES (?, ?, ?)'
        )->execute([$recId, $supporterId, 'Fixture second.']);

        return (int) $this->fixture->pdo()->lastInsertId();
    }

    private function secondDeletedBy(int $secondId): int
    {
        $st = $this->fixture->pdo()->prepare(
            'SELECT deleted_by FROM ' . DB_PREFIX . 'recommendation_seconds WHERE recommendation_seconds_id = ?'
        );
        $st->execute([$secondId]);

        return (int) $st->fetchColumn();
    }

    private function grantedNoticesFor(int $mundaneId): int
    {
        $st = $this->fixture->pdo()->prepare(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . "notification WHERE mundane_id = ? AND type = 'rec_granted'"
        );
        $st->execute([$mundaneId]);

        return (int) $st->fetchColumn();
    }

    // ---- refused: a forged RequestedBy confers nothing --------------------------

    public function testAStrangerClaimingToBeTheRecommenderCannotDismiss(): void
    {
        $recipient   = $this->person('recipient');
        $recommender = $this->person('recommender');
        $stranger    = $this->person('stranger');
        $rec         = $this->recommendation($recipient['id'], $recommender['id']);

        $r = $this->dismiss($stranger['token'], $rec, $recommender['id']);

        $this->assertNotSame(0, (int) $r['Status'], 'A forged RequestedBy must not authorize a dismissal.');
        $this->assertSame(0, $this->deletedBy($rec), 'The recommendation must stay live.');
    }

    public function testAStrangerClaimingToBeTheRecipientCannotDismiss(): void
    {
        $recipient   = $this->person('recipient');
        $recommender = $this->person('recommender');
        $stranger    = $this->person('stranger');
        $rec         = $this->recommendation($recipient['id'], $recommender['id']);

        $r = $this->dismiss($stranger['token'], $rec, $recipient['id']);

        $this->assertNotSame(0, (int) $r['Status']);
        $this->assertSame(0, $this->deletedBy($rec));
    }

    // ---- allowed: the real recommender, the real recipient, a park officer -------

    public function testTheRecommenderCanDismissTheirOwnRecommendation(): void
    {
        $recipient   = $this->person('recipient');
        $recommender = $this->person('recommender');
        $rec         = $this->recommendation($recipient['id'], $recommender['id']);

        $r = $this->dismiss($recommender['token'], $rec, $recommender['id']);

        $this->assertSame(0, (int) $r['Status']);
        $this->assertSame($recommender['id'], $this->deletedBy($rec));
    }

    public function testTheRecipientCanDismissARecommendationForThemselves(): void
    {
        $recipient   = $this->person('recipient');
        $recommender = $this->person('recommender');
        $rec         = $this->recommendation($recipient['id'], $recommender['id']);

        $r = $this->dismiss($recipient['token'], $rec, $recipient['id']);

        $this->assertSame(0, (int) $r['Status']);
        $this->assertSame($recipient['id'], $this->deletedBy($rec));
    }

    public function testAParkOfficerCanDismissAndIsRecordedAsTheDismisserWhateverRequestedBySays(): void
    {
        $recipient   = $this->person('recipient');
        $recommender = $this->person('recommender');
        $supporter   = $this->person('supporter');
        $officer     = $this->parkOfficer();
        $rec         = $this->recommendation($recipient['id'], $recommender['id']);
        $second      = $this->addSecond($rec, $supporter['id']);

        // RequestedBy names the recommender; the token belongs to the officer.
        $r = $this->dismiss($officer['token'], $rec, $recommender['id']);

        $this->assertSame(0, (int) $r['Status']);
        $this->assertSame($officer['id'], $this->deletedBy($rec), 'deleted_by must name the token holder.');
        $this->assertSame($officer['id'], $this->secondDeletedBy($second), 'The seconds cascade must name the token holder too.');
    }

    public function testAnHonestCallerNoLongerNeedsToSupplyRequestedBy(): void
    {
        $recipient   = $this->person('recipient');
        $recommender = $this->person('recommender');
        $rec         = $this->recommendation($recipient['id'], $recommender['id']);

        unset($_SESSION['is_authorized_mundane_id']);
        $r = $this->player->DeleteAwardRecommendation([
            'Token'             => $recommender['token'],
            'RecommendationsId' => $rec,
        ]);

        $this->assertSame(0, (int) $r['Status']);
        $this->assertSame($recommender['id'], $this->deletedBy($rec));
    }

    public function testAnInvalidTokenIsRefusedEvenWithAMatchingRequestedBy(): void
    {
        $recipient   = $this->person('recipient');
        $recommender = $this->person('recommender');
        $rec         = $this->recommendation($recipient['id'], $recommender['id']);

        $r = $this->dismiss(str_repeat('0', 32), $rec, $recommender['id']);

        $this->assertNotSame(0, (int) $r['Status']);
        $this->assertSame(0, $this->deletedBy($rec));
    }

    // ---- the Granted notification's actor is the token holder --------------------

    public function testAGrantCannotSuppressTheRecommendersNoticeByImpersonatingThem(): void
    {
        $recipient   = $this->person('recipient');
        $recommender = $this->person('recommender');
        $officer     = $this->parkOfficer();
        $rec         = $this->recommendation($recipient['id'], $recommender['id']);

        // The notifier skips the granter; a forged RequestedBy = recommender used to
        // make the recommender "the granter" and silence their notice.
        $r = $this->dismiss($officer['token'], $rec, $recommender['id'], 1);

        $this->assertSame(0, (int) $r['Status']);
        $this->assertSame(1, $this->grantedNoticesFor($recommender['id']));
    }

    // ---- ResolveRecommendationCluster ------------------------------------------

    private function resolve(string $token, int $recipientId, int $requestedBy): array
    {
        unset($_SESSION['is_authorized_mundane_id']);

        return $this->player->ResolveRecommendationCluster([
            'Token'          => $token,
            'MundaneId'      => $recipientId,
            'KingdomAwardId' => $this->ka['kingdomaward_id'],
            'Rank'           => 1,
            'RequestedBy'    => $requestedBy,
        ]);
    }

    public function testAStrangerCannotResolveAClusterByClaimingToBeTheRecommender(): void
    {
        $recipient   = $this->person('recipient');
        $recommender = $this->person('recommender');
        $stranger    = $this->person('stranger');
        $rec         = $this->recommendation($recipient['id'], $recommender['id']);

        $r = $this->resolve($stranger['token'], $recipient['id'], $recommender['id']);

        $this->assertSame(0, (int) ($r['Resolved'] ?? -1));
        $this->assertSame(0, $this->deletedBy($rec));
    }

    public function testAnOfficerResolvingAClusterIsRecordedAsTheDismisserWhateverRequestedBySays(): void
    {
        $recipient   = $this->person('recipient');
        $recommender = $this->person('recommender');
        $stranger    = $this->person('stranger');
        $officer     = $this->parkOfficer();
        $rec         = $this->recommendation($recipient['id'], $recommender['id']);

        $r = $this->resolve($officer['token'], $recipient['id'], $stranger['id']);

        $this->assertSame(1, (int) ($r['Resolved'] ?? -1));
        $this->assertSame($officer['id'], $this->deletedBy($rec));
    }
}
