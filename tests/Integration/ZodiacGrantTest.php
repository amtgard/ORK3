<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Task 3: Order of the Zodiac (award_id 30) is granted once per calendar month,
 * so its twelve ladder positions are months, not levels. A new zodiac_month
 * column records the month; rank must never be written for Zodiac by any of
 * these three write paths again, and a legacy rank must survive an edit.
 *
 * Player::AddAward()/UpdateAward() are Token-gated (IsAuthorized(), then
 * checkPermissionOrAuthority('player.award.manage', 'park', ..., AUTH_CREATE));
 * Player::AddAwardRecommendation() only requires IsAuthorized(). Both use the
 * shared AuthorizedOfficerFixture: createAuthorizedOfficer() for a session/
 * authorization row, plus grantParkAuthority() so AddAward's park-scoped check
 * (the legacy HasAuthority() walk needs a real ork_park row this branch's
 * ork_test lacks) succeeds directly instead.
 *
 * Every grant/recommend helper below seeds its OWN kingdomaward row (via
 * seedKingdomAward()) and passes KingdomAwardId, never a bare AwardId, to
 * AddAward/AddAwardRecommendation -- exactly the pattern LadderGrantRuleTest
 * already uses for the same award_id 30. Resolving via a bare AwardId
 * (Award::LookupAward) depends on kingdom 1's catalog already having exactly
 * one kingdomaward row for that award_id, which this shared, per-branch-drifted
 * local DB does not guarantee (see reference_local_db_state_gotchas.md) --
 * and if a stray pre-existing row happened to match, the write would land on
 * a real production-catalog kingdomaward_id this test's tearDown() never
 * tracks. KingdomAwardId sidesteps that lookup entirely.
 *
 * The brief's illustrative test bodies omit Token (and use RecipientId => 1,
 * AwardId => 30 directly); per the token-gating trap, an untokened call would
 * be refused at the authorization gate before ever reaching the code under
 * test. Every call site below supplies a real Token and a real seeded
 * recipient/kingdomaward instead.
 */
final class ZodiacGrantTest extends TestCase
{
    private const MARKER = 'ZODGRANT';
    private const KINGDOM_ID = 1;
    private const PARK_ID = 999103;
    private const ZODIAC_AWARD_ID = 30;
    private const ROSE_AWARD_ID = 21; // Order of the Rose -- an official ladder, not monthly.

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
            $this->pdo->exec('DELETE FROM ork_recommendations WHERE mundane_id = ' . $this->recipientId);
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

    /**
     * Grants via Player::AddAward(), asserting success, and returns the created
     * awards_id (AddAward's Success('') never returns one, so it is read back).
     * $overrides['AwardId'] selects which award to seed/grant (default: Zodiac);
     * every other key is forwarded into the AddAward request as-is.
     */
    private function grant(array $overrides): int
    {
        $awardId = (int) ($overrides['AwardId'] ?? self::ZODIAC_AWARD_ID);
        unset($overrides['AwardId']);
        $kaId = $this->seedKingdomAward($awardId);

        $request = array_merge([
            'Token' => $this->token,
            'RecipientId' => $this->recipientId,
            'KingdomAwardId' => $kaId,
            'Rank' => 0,
            'Date' => '2026-01-01',
        ], $overrides);

        $result = $this->player->AddAward($request);
        $this->assertSame(0, (int) $result['Status'], 'grant() setup call failed: ' . json_encode($result));

        $stmt = $this->pdo->prepare(
            'SELECT awards_id FROM ork_awards WHERE mundane_id = :mid AND kingdomaward_id = :kaid ORDER BY awards_id DESC LIMIT 1'
        );
        $stmt->execute([':mid' => $this->recipientId, ':kaid' => $kaId]);
        $id = $stmt->fetchColumn();
        $this->assertNotFalse($id, 'grant() could not find the created ork_awards row');

        return (int) $id;
    }

    /**
     * Inserts an ork_awards row directly, bypassing all business logic --
     * exactly a legacy row's shape (a rank-only grant with zodiac_month = 0).
     * Seeds its own kingdomaward row too, same as grant().
     */
    private function grantRaw(array $columns): int
    {
        $awardId = (int) ($columns['award_id'] ?? self::ZODIAC_AWARD_ID);
        $kaId = $this->seedKingdomAward($awardId);
        $rank = (int) ($columns['rank'] ?? 0);
        $zodiacMonth = (int) ($columns['zodiac_month'] ?? 0);

        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_awards (mundane_id, kingdomaward_id, award_id, `rank`, zodiac_month, date)
             VALUES (:mid, :kaid, :award_id, :rank, :zodiac_month, :date)'
        );
        $stmt->execute([
            ':mid' => $this->recipientId,
            ':kaid' => $kaId,
            ':award_id' => $awardId,
            ':rank' => $rank,
            ':zodiac_month' => $zodiacMonth,
            ':date' => '2020-01-01',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Recommends via Player::AddAwardRecommendation(), asserting success, and
     * returns the created recommendations_id.
     */
    private function recommend(array $overrides): int
    {
        $awardId = (int) ($overrides['AwardId'] ?? self::ZODIAC_AWARD_ID);
        unset($overrides['AwardId']);
        $kaId = $this->seedKingdomAward($awardId);

        $request = array_merge([
            'Token' => $this->token,
            'MundaneId' => $this->recipientId,
            'KingdomAwardId' => $kaId,
            'Reason' => self::MARKER,
        ], $overrides);

        $result = $this->player->AddAwardRecommendation($request);
        $this->assertSame(0, (int) $result['Status'], 'recommend() setup call failed: ' . json_encode($result));

        $stmt = $this->pdo->prepare(
            'SELECT recommendations_id FROM ork_recommendations WHERE mundane_id = :mid AND kingdomaward_id = :kaid ORDER BY recommendations_id DESC LIMIT 1'
        );
        $stmt->execute([':mid' => $this->recipientId, ':kaid' => $kaId]);
        $id = $stmt->fetchColumn();
        $this->assertNotFalse($id, 'recommend() could not find the created ork_recommendations row');

        return (int) $id;
    }

    private function columnOf(int $awardsId, string $column): int
    {
        $stmt = $this->pdo->prepare("SELECT `{$column}` FROM ork_awards WHERE awards_id = :id");
        $stmt->execute([':id' => $awardsId]);

        return (int) $stmt->fetchColumn();
    }

    private function recColumnOf(int $recommendationsId, string $column): int
    {
        $stmt = $this->pdo->prepare("SELECT `{$column}` FROM ork_recommendations WHERE recommendations_id = :id");
        $stmt->execute([':id' => $recommendationsId]);

        return (int) $stmt->fetchColumn();
    }

    private function countGrantsFor(int $awardId, int $zodiacMonth): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ork_awards WHERE mundane_id = :mid AND award_id = :award_id AND zodiac_month = :month'
        );
        $stmt->execute([':mid' => $this->recipientId, ':award_id' => $awardId, ':month' => $zodiacMonth]);

        return (int) $stmt->fetchColumn();
    }

    public function testGrantingAZodiacWritesTheMonthAndLeavesRankAtZero(): void
    {
        $id = $this->grant(['AwardId' => self::ZODIAC_AWARD_ID, 'ZodiacMonth' => 3]);

        $this->assertSame(3, $this->columnOf($id, 'zodiac_month'));
        $this->assertSame(0, $this->columnOf($id, 'rank'), 'rank is never written for Zodiac');
    }

    public function testGrantingANonZodiacLadderNeverWritesTheMonth(): void
    {
        // Order of the Rose. Even if a caller passes ZodiacMonth.
        $id = $this->grant(['AwardId' => self::ROSE_AWARD_ID, 'Rank' => 4, 'ZodiacMonth' => 3]);

        $this->assertSame(0, $this->columnOf($id, 'zodiac_month'));
        $this->assertSame(4, $this->columnOf($id, 'rank'));
    }

    public function testAMonthlessZodiacIsAccepted(): void
    {
        // 2,024 already exist. Rule 1 does not apply to Zodiac.
        $id = $this->grant(['AwardId' => self::ZODIAC_AWARD_ID, 'ZodiacMonth' => 0]);

        $this->assertSame(0, $this->columnOf($id, 'zodiac_month'));
    }

    public function testARepeatMonthIsAcceptedAndBothGrantsSurvive(): void
    {
        // Typically a player earns one December, but a second is legitimate.
        $this->grant(['AwardId' => self::ZODIAC_AWARD_ID, 'ZodiacMonth' => 12]);
        $this->grant(['AwardId' => self::ZODIAC_AWARD_ID, 'ZodiacMonth' => 12]);

        $this->assertSame(2, $this->countGrantsFor(self::ZODIAC_AWARD_ID, 12));
    }

    public function testAMonthOutsideOneToTwelveIsRejected(): void
    {
        $kaId = $this->seedKingdomAward(self::ZODIAC_AWARD_ID);
        foreach ([13, 99, -1] as $month) {
            $result = $this->player->AddAward([
                'Token' => $this->token,
                'RecipientId' => $this->recipientId,
                'KingdomAwardId' => $kaId,
                'ZodiacMonth' => $month,
                'Date' => '2026-01-01',
            ]);
            $this->assertNotSame(0, (int) $result['Status'], "month {$month} must be rejected");
            $this->assertStringContainsString('month', (string) $result['Detail'], "month {$month} rejection must come from month validation, not some other refusal");
        }
    }

    public function testALegacyRankIsUntouchedByEditingAZodiac(): void
    {
        $id = $this->grantRaw(['award_id' => self::ZODIAC_AWARD_ID, 'rank' => 5, 'zodiac_month' => 0]);
        $result = $this->player->UpdateAward(['Token' => $this->token, 'AwardsId' => $id, 'ZodiacMonth' => 7]);

        $this->assertSame(0, (int) $result['Status'], 'UpdateAward call failed: ' . json_encode($result));
        $this->assertSame(7, $this->columnOf($id, 'zodiac_month'));
        $this->assertSame(5, $this->columnOf($id, 'rank'), 'legacy rank stays legible as the level it was');
    }

    public function testRecommendingAZodiacCarriesTheMonth(): void
    {
        $recId = $this->recommend(['AwardId' => self::ZODIAC_AWARD_ID, 'ZodiacMonth' => 9]);

        $this->assertSame(9, $this->recColumnOf($recId, 'zodiac_month'));
        $this->assertSame(0, $this->recColumnOf($recId, 'rank'));
    }
}
