<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Rule 1: a ladder grant must carry a rank in 1..max, unless the recipient is
 * already at or past max -- the star path. Order of the Zodiac (award_id 30) is
 * exempt: it is granted once per calendar month, so a monthless grant must still
 * be accepted.
 *
 * Player::AddAward() is Token-gated (IsAuthorized(), then checkPermissionOrAuthority
 * ('player.award.manage', 'park', $recipient['ParkId'], AUTH_CREATE)); a request
 * with no Token -- or a token whose authorization doesn't cover the recipient's
 * park -- is refused (Status 5, NoAuthorization) before Rule 1 code ever runs.
 * setUp() uses the shared AuthorizedOfficerFixture (extracted from
 * EditAwardLadderTest by this same task) for the mundane/session/authorization
 * rows, plus grantParkAuthority() for a direct park-scoped grant: AddAward's
 * check is scoped to 'park', and the legacy HasAuthority() walk from a park id up
 * to its kingdom only succeeds through a real ork_park row -- and ork_test ships
 * with none. A direct park-scoped authorization row sidesteps that walk.
 * testWithoutAuthorityTheCallIsRefusedNotRuleOneRejected() below proves the
 * distinction empirically: a call with no authorization gets Status 5 and a
 * message that does NOT mention "ranked award", so the rejection tests below are
 * demonstrably reaching Rule 1 and not merely observing a token/authorization
 * refusal.
 */
final class LadderGrantRuleTest extends TestCase
{
    private const MARKER = 'LADRULE';
    private const KINGDOM_ID = 1;
    private const PARK_ID = 999101;

    private PDO $pdo;
    private Player $player;
    private AuthorizedOfficerFixture $officer;
    private string $token;
    private int $recipientId;

    /** @var list<int> kingdomaward ids to clean up (and their ork_awards grants) */
    private array $kingdomAwardIdsToClean = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $this->player = new Player();
        $this->officer = new AuthorizedOfficerFixture($this->pdo, self::MARKER, self::KINGDOM_ID);
        $this->token = $this->officer->createAuthorizedOfficer();
        $this->officer->grantParkAuthority(self::PARK_ID);
        $this->recipientId = $this->seedRecipient();
    }

    protected function tearDown(): void
    {
        foreach ($this->kingdomAwardIdsToClean as $kingdomAwardId) {
            $this->pdo->exec('DELETE FROM ork_awards WHERE kingdomaward_id = ' . (int) $kingdomAwardId);
        }
        $this->kingdomAwardIdsToClean = [];

        $this->pdo->exec("DELETE FROM ork_kingdomaward WHERE name LIKE '" . self::MARKER . "%'");
        if (isset($this->recipientId)) {
            $this->pdo->exec('DELETE FROM ork_mundane WHERE mundane_id = ' . $this->recipientId);
        }
        $this->officer->cleanup();
    }

    private function seedRecipient(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_mundane
                (given_name, surname, other_name, username, persona, email, park_id, kingdom_id,
                 token, waiver_ext, password_expires, password_salt, xtoken, reeve_qualified_until)
             VALUES
                (:given_name, :surname, :other_name, :username, :persona, :email, :park_id, :kingdom_id,
                 :token, :waiver_ext, :password_expires, :password_salt, :xtoken, :reeve_qualified_until)'
        );
        $username = strtolower(self::MARKER . '_recipient_' . bin2hex(random_bytes(4)));
        $stmt->execute([
            ':given_name' => self::MARKER,
            ':surname' => 'Recipient',
            ':other_name' => '',
            ':username' => $username,
            ':persona' => self::MARKER . ' Recipient',
            ':email' => $username . '@example.test',
            ':park_id' => self::PARK_ID,
            ':kingdom_id' => self::KINGDOM_ID,
            ':token' => md5($username),
            ':waiver_ext' => '',
            ':password_expires' => '2099-01-01 00:00:00',
            ':password_salt' => '',
            ':xtoken' => '',
            ':reeve_qualified_until' => '2000-01-01',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param int $awardId 0 for a pure kingdom award (no official base row)
     */
    private function seedKingdomAward(int $awardId, int $isLadder = 0, int $maxLevel = 0): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_kingdomaward (kingdom_id, award_id, name, is_ladder, max_level)
             VALUES (:kingdom_id, :award_id, :name, :is_ladder, :max_level)'
        );
        $stmt->execute([
            ':kingdom_id' => self::KINGDOM_ID,
            ':award_id' => $awardId,
            ':name' => self::MARKER . '-' . uniqid(),
            ':is_ladder' => $isLadder,
            ':max_level' => $maxLevel,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->kingdomAwardIdsToClean[] = $id;

        return $id;
    }

    private function grantExistingRank(int $kingdomAwardId, int $awardId, int $rank): void
    {
        $this->pdo->exec(
            "INSERT INTO ork_awards (mundane_id, kingdomaward_id, award_id, `rank`, date)
             VALUES ({$this->recipientId}, {$kingdomAwardId}, {$awardId}, {$rank}, '2020-01-01')"
        );
    }

    /**
     * Like grantExistingRank(), but returns the awards_id so UpdateAward()/
     * ReconcileAward() tests have a row to edit. by_whom_id is left at its
     * default (0) so ReconcileAward's no-op short-circuit (which requires
     * by_whom_id > 0) never fires for these rows.
     */
    private function seedAwardRow(int $kingdomAwardId, int $awardId, int $rank, string $date = '2020-01-01'): int
    {
        $this->pdo->exec(
            "INSERT INTO ork_awards (mundane_id, kingdomaward_id, award_id, `rank`, date)
             VALUES ({$this->recipientId}, {$kingdomAwardId}, {$awardId}, {$rank}, '{$date}')"
        );

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>
     */
    private function updateAwardRequest(int $awardsId, int $awardId, int $rank, string $date = '2026-01-01', string $note = ''): array
    {
        return [
            'Token' => $this->token,
            'AwardsId' => $awardsId,
            'RecipientId' => $this->recipientId,
            'AwardId' => $awardId,
            'CustomName' => '',
            'AliasAwardId' => 0,
            'Rank' => $rank,
            'Date' => $date,
            'GivenById' => 0,
            'Note' => $note,
            'ParkId' => 0,
            'KingdomId' => 0,
            'EventId' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reconcileAwardRequest(int $awardsId, int $kingdomAwardId, int $rank, string $date = '2026-01-01', string $note = ''): array
    {
        return [
            'Token' => $this->token,
            'AwardsId' => $awardsId,
            'KingdomAwardId' => $kingdomAwardId,
            'Rank' => $rank,
            'Date' => $date,
            'GivenById' => 0,
            'Note' => $note,
            'ParkId' => 0,
            'KingdomId' => 0,
            'EventId' => 0,
        ];
    }

    public function testWithoutAuthorityTheCallIsRefusedNotRuleOneRejected(): void
    {
        // award_id 21 = Order of the Rose (official ladder, ork_award.is_ladder = 1),
        // so this is otherwise the exact rejection shape below -- except for the
        // Token, which is deliberately omitted here. If Rule 1 code ran anyway,
        // this would show Status 4 with the "ranked award" message; instead it must
        // be refused earlier, at the authorization gate (Status 5), proving the
        // rejection tests below are not merely observing that same early refusal.
        $result = $this->player->AddAward([
            'RecipientId' => $this->recipientId, 'AwardId' => 21, 'Rank' => 0, 'Date' => '2026-01-01',
        ]);

        $this->assertSame(5, (int) $result['Status']);
        $this->assertStringNotContainsString('ranked award', (string) $result['Detail']);
    }

    public function testUnrankedLadderGrantIsRejectedForAPlayerBelowMax(): void
    {
        // award_id 21 = Order of the Rose, official ladder (ork_award.is_ladder = 1),
        // max 10. Seeded here rather than relying on AwardId to find a pre-existing
        // kingdomaward row -- the shared sandbox catalog is not this test's to trust.
        $kaId = $this->seedKingdomAward(21);
        $result = $this->player->AddAward([
            'Token' => $this->token, 'RecipientId' => $this->recipientId, 'KingdomAwardId' => $kaId, 'Rank' => 0, 'Date' => '2026-01-01',
        ]);

        $this->assertNotSame(0, (int) $result['Status']);
        $this->assertStringContainsString('is a ranked award', (string) $result['Detail']);
        $this->assertStringContainsString("\u{2731}", (string) $result['Detail']);
    }

    public function testTheRejectionMessageNamesTheAwardAndTheMax(): void
    {
        $kaId = $this->seedKingdomAward(21);
        $result = $this->player->AddAward([
            'Token' => $this->token, 'RecipientId' => $this->recipientId, 'KingdomAwardId' => $kaId, 'Rank' => 0, 'Date' => '2026-01-01',
        ]);

        $this->assertStringContainsString('10', (string) $result['Detail']);
        $this->assertStringContainsString('choose a rank', (string) $result['Detail']);
    }

    public function testRankedLadderGrantIsAccepted(): void
    {
        $kaId = $this->seedKingdomAward(21);
        $result = $this->player->AddAward([
            'Token' => $this->token, 'RecipientId' => $this->recipientId, 'KingdomAwardId' => $kaId, 'Rank' => 3, 'Date' => '2026-01-01',
        ]);

        $this->assertSame(0, (int) $result['Status']);
    }

    public function testNonLadderGrantIsUnaffectedByRuleOne(): void
    {
        // award_id 1 = Master Rose, a peerage, not a ladder.
        $kaId = $this->seedKingdomAward(1);
        $result = $this->player->AddAward([
            'Token' => $this->token, 'RecipientId' => $this->recipientId, 'KingdomAwardId' => $kaId, 'Rank' => 0, 'Date' => '2026-01-01',
        ]);

        $this->assertSame(0, (int) $result['Status']);
    }

    public function testUnrankedGrantIsAcceptedWhenRecipientIsAlreadyAtMax(): void
    {
        // The star path: the recipient already holds the top rank on this ladder,
        // so an unranked grant is recognition past the top, not the below-max mistake.
        $kaId = $this->seedKingdomAward(21);
        $this->grantExistingRank($kaId, 21, 10);

        $result = $this->player->AddAward([
            'Token' => $this->token, 'RecipientId' => $this->recipientId, 'KingdomAwardId' => $kaId, 'Rank' => 0, 'Date' => '2026-01-01',
        ]);

        $this->assertSame(0, (int) $result['Status']);
    }

    public function testMonthlessZodiacGrantIsAccepted(): void
    {
        // award_id 30 = Order of the Zodiac, official ladder, max 12 -- but exempt
        // from Rule 1 by Award::IsMonthlyLadder(): 2,024 monthless grants already
        // exist and reconciling officers must still be able to record them.
        $kaId = $this->seedKingdomAward(30);
        $result = $this->player->AddAward([
            'Token' => $this->token, 'RecipientId' => $this->recipientId, 'KingdomAwardId' => $kaId, 'Rank' => 0, 'Date' => '2026-01-01',
        ]);

        $this->assertSame(0, (int) $result['Status']);
    }

    public function testUnrankedKingdomLadderGrantBelowMaxIsRejected(): void
    {
        // A kingdom-raised ladder (award_id = 0, ka.is_ladder = 1) is an EFFECTIVE
        // ladder via Award::LadderSql() even though it is not one of the 16
        // official orders -- Rule 1 must cover it too.
        $kaId = $this->seedKingdomAward(0, 1, 5);

        $result = $this->player->AddAward([
            'Token' => $this->token, 'RecipientId' => $this->recipientId, 'KingdomAwardId' => $kaId, 'Rank' => 0, 'Date' => '2026-01-01',
        ]);

        $this->assertNotSame(0, (int) $result['Status']);
        $this->assertStringContainsString('is a ranked award', (string) $result['Detail']);
        $this->assertStringContainsString('5', (string) $result['Detail']);
    }

    // -----------------------------------------------------------------
    // Task 8A: Rule 1 must also cover UpdateAward() and ReconcileAward().
    // -----------------------------------------------------------------

    public function testUpdateAwardRejectsSettingRankZeroForBelowMaxPlayer(): void
    {
        $kaId = $this->seedKingdomAward(21);
        $awardsId = $this->seedAwardRow($kaId, 21, 5);

        $result = $this->player->UpdateAward($this->updateAwardRequest($awardsId, 21, 0));

        $this->assertNotSame(0, (int) $result['Status']);
        $this->assertStringContainsString('is a ranked award', (string) $result['Detail']);
        $this->assertStringContainsString("\u{2731}", (string) $result['Detail']);
    }

    public function testUpdateAwardCannotUseItsOwnAboutToBeOverwrittenRankToJustifyItself(): void
    {
        // This row is the ONLY grant establishing rank 10 -- no other row does.
        // The guard must exclude the row being written from the held-rank
        // lookup, or editing it down to unranked would see its own stale
        // pre-write rank=10 and wrongly allow the write. Without the exclusion
        // (CurrentLadderRank's $excludeAwardsId), this test fails.
        $kaId = $this->seedKingdomAward(21);
        $awardsId = $this->seedAwardRow($kaId, 21, 10);

        $result = $this->player->UpdateAward($this->updateAwardRequest($awardsId, 21, 0));

        $this->assertNotSame(0, (int) $result['Status']);
        $this->assertStringContainsString('is a ranked award', (string) $result['Detail']);
    }

    public function testUpdateAwardAcceptsUnrankedWriteWhenAnotherGrantAlreadyHoldsMax(): void
    {
        // The star path on edit: a SEPARATE row already carries rank 10, so
        // editing this row (an existing bonus/unranked record, or one being
        // converted to one) to stay/become unranked must succeed -- exactly
        // the reconciliation-flow case the plan calls out.
        $kaId = $this->seedKingdomAward(21);
        $this->grantExistingRank($kaId, 21, 10);
        $awardsId = $this->seedAwardRow($kaId, 21, 3);

        $result = $this->player->UpdateAward($this->updateAwardRequest($awardsId, 21, 0, '2026-01-01', 'bonus edit'));

        $this->assertSame(0, (int) $result['Status']);
    }

    public function testUpdateAwardZodiacEditStaysExempt(): void
    {
        $kaId = $this->seedKingdomAward(30);
        $awardsId = $this->seedAwardRow($kaId, 30, 0);

        $result = $this->player->UpdateAward($this->updateAwardRequest($awardsId, 30, 0, '2026-02-01'));

        $this->assertSame(0, (int) $result['Status']);
    }

    public function testUpdateAwardNonLadderEditIsUnaffected(): void
    {
        // award_id 1 = Master Rose, a peerage, not a ladder.
        $kaId = $this->seedKingdomAward(1);
        $awardsId = $this->seedAwardRow($kaId, 1, 0);

        $result = $this->player->UpdateAward($this->updateAwardRequest($awardsId, 1, 0, '2026-01-01', 'note edit'));

        $this->assertSame(0, (int) $result['Status']);
    }

    public function testReconcileAwardRejectsSettingRankZeroForBelowMaxPlayer(): void
    {
        $kaId = $this->seedKingdomAward(21);
        $awardsId = $this->seedAwardRow($kaId, 21, 5);

        $result = $this->player->ReconcileAward($this->reconcileAwardRequest($awardsId, $kaId, 0));

        $this->assertNotSame(0, (int) $result['Status']);
        $this->assertStringContainsString('is a ranked award', (string) $result['Detail']);
        $this->assertStringContainsString("\u{2731}", (string) $result['Detail']);
    }

    public function testReconcileAwardCannotUseItsOwnAboutToBeOverwrittenRankToJustifyItself(): void
    {
        $kaId = $this->seedKingdomAward(21);
        $awardsId = $this->seedAwardRow($kaId, 21, 10);

        $result = $this->player->ReconcileAward($this->reconcileAwardRequest($awardsId, $kaId, 0));

        $this->assertNotSame(0, (int) $result['Status']);
        $this->assertStringContainsString('is a ranked award', (string) $result['Detail']);
    }

    public function testReconcileAwardAcceptsUnrankedWriteWhenAnotherGrantAlreadyHoldsMax(): void
    {
        // The reconcile picker's star gate today reads client-side PnConfig
        // state only -- this proves the server independently reaches the same
        // answer for the picker's exact endpoint.
        $kaId = $this->seedKingdomAward(21);
        $this->grantExistingRank($kaId, 21, 10);
        $awardsId = $this->seedAwardRow($kaId, 21, 3);

        $result = $this->player->ReconcileAward($this->reconcileAwardRequest($awardsId, $kaId, 0, '2026-01-01', 'reconciled as bonus'));

        $this->assertSame(0, (int) $result['Status']);
    }

    public function testReconcileAwardZodiacStaysExempt(): void
    {
        $kaId = $this->seedKingdomAward(30);
        $awardsId = $this->seedAwardRow($kaId, 30, 0);

        $result = $this->player->ReconcileAward($this->reconcileAwardRequest($awardsId, $kaId, 0, '2026-02-01'));

        $this->assertSame(0, (int) $result['Status']);
    }

    public function testReconcileAwardNonLadderIsUnaffected(): void
    {
        // award_id 1 = Master Rose, a peerage, not a ladder.
        $kaId = $this->seedKingdomAward(1);
        $awardsId = $this->seedAwardRow($kaId, 1, 0);

        $result = $this->player->ReconcileAward($this->reconcileAwardRequest($awardsId, $kaId, 0, '2026-01-01', 'note edit'));

        $this->assertSame(0, (int) $result['Status']);
    }
}
