<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Task 9 (2026-08-27-zodiac-monthly-awards): people drive reports and
 * spreadsheets off the SOAP and JSON APIs, so Zodiac data must stay usable
 * for a client written before this change, and never be silently
 * reinterpreted for a client written after it.
 *
 * The spec (zodiac_month, never rank, for every UI path) is amended for the
 * SOAP/JSON write path ONLY: AddAward keeps accepting an inbound Rank for
 * Order of the Zodiac and stores it exactly as before. A legacy integration
 * that grants Zodiacs must not start failing -- its grants land monthless,
 * the same state as the 2,024 monthless Zodiacs already in the corpus
 * (reconcilable through Task 7). The carve-out is keyed on an 'ApiClient'
 * request flag: grep across system/lib/ork3, orkservice and orkui turned up
 * no existing mechanism the domain uses to distinguish an API caller from a
 * UI caller, so this is not a second, competing mechanism -- it is the first.
 *
 * Player::AddAward()/UpdateAward() are Token-gated; AwardsForPlayer() is a
 * plain read with no Token. Mirrors ZodiacGrantTest's fixture pattern
 * (AuthorizedOfficerFixture + seedKingdomAward + KingdomAwardId, never a bare
 * AwardId, to sidestep Award::LookupAward's single-row assumption against
 * this branch's drifted local catalog).
 *
 * The brief's illustrative test bodies omit Token and use RecipientId => 1
 * directly; per the token-gating trap (an untokened call is refused at
 * Status = 5 before ever reaching the code under test, so the test would
 * pass without verifying anything), every write call site below supplies a
 * real Token and a real seeded recipient/kingdomaward instead.
 */
final class ZodiacApiCompatTest extends TestCase
{
    private const MARKER = 'ZODAPICOMPAT';
    private const KINGDOM_ID = 1;
    private const PARK_ID = 999104;
    private const ZODIAC_AWARD_ID = 30;
    private const ROSE_AWARD_ID = 21; // Order of the Rose -- not monthly.

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

    private function seedKingdomAward(int $awardId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_kingdomaward (kingdom_id, award_id, name, is_ladder, max_level)
             VALUES (:kingdom_id, :award_id, :name, 0, 0)'
        );
        $stmt->execute([
            ':kingdom_id' => self::KINGDOM_ID,
            ':award_id' => $awardId,
            ':name' => self::MARKER . '-' . uniqid(),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->kingdomAwardIdsToClean[] = $id;

        return $id;
    }

    /**
     * Inserts an ork_awards row directly, bypassing all business logic --
     * exactly a legacy row's shape. Same pattern as ZodiacGrantTest::grantRaw().
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
     * Grants Order of the Zodiac via Player::AddAward() with ApiClient => true
     * (the backwards-compatible SOAP/JSON write path), asserting success, and
     * returns the created awards_id.
     */
    private function grantViaApi(array $overrides): int
    {
        $awardId = (int) ($overrides['AwardId'] ?? self::ZODIAC_AWARD_ID);
        unset($overrides['AwardId']);
        $kaId = $this->seedKingdomAward($awardId);

        $request = array_merge([
            'Token' => $this->token,
            'RecipientId' => $this->recipientId,
            'KingdomAwardId' => $kaId,
            'Date' => '2026-01-01',
            'ApiClient' => true,
        ], $overrides);

        $result = $this->player->AddAward($request);
        $this->assertSame(0, (int) $result['Status'], 'grantViaApi() setup call failed: ' . json_encode($result));

        $stmt = $this->pdo->prepare(
            'SELECT awards_id FROM ork_awards WHERE mundane_id = :mid AND kingdomaward_id = :kaid ORDER BY awards_id DESC LIMIT 1'
        );
        $stmt->execute([':mid' => $this->recipientId, ':kaid' => $kaId]);
        $id = $stmt->fetchColumn();
        $this->assertNotFalse($id, 'grantViaApi() could not find the created ork_awards row');

        return (int) $id;
    }

    private function columnOf(int $awardsId, string $column): int
    {
        $stmt = $this->pdo->prepare("SELECT `{$column}` FROM ork_awards WHERE awards_id = :id");
        $stmt->execute([':id' => $awardsId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Calls Player::AwardsForPlayer() for this test's recipient and returns the
     * response row matching the given awards_id.
     */
    private function apiRowFor(int $awardsId): array
    {
        $response = $this->player->AwardsForPlayer(['MundaneId' => $this->recipientId]);
        $this->assertSame(0, (int) $response['Status']['Status'], 'AwardsForPlayer() failed: ' . json_encode($response));

        foreach ($response['Awards'] as $row) {
            if ((int) $row['AwardsId'] === $awardsId) {
                return $row;
            }
        }

        $this->fail("awards_id {$awardsId} not found in AwardsForPlayer() response");
    }

    public function testRankIsTheRawColumnAndIsNeverTheMonth(): void
    {
        // A client reading Rank must never be handed a month behind that name.
        $id = $this->grantRaw(['award_id' => self::ZODIAC_AWARD_ID, 'rank' => 5, 'zodiac_month' => 3]);
        $row = $this->apiRowFor($id);

        $this->assertSame(5, (int) $row['Rank']);
        $this->assertSame(3, (int) $row['ZodiacMonth']);
    }

    public function testANewZodiacReportsRankZero(): void
    {
        // Not a new failure mode: 2,024 of 3,798 existing Zodiac grants (53%)
        // already carry rank 0, so any consumer that cannot handle it is already
        // broken today.
        $id = $this->grantRaw(['award_id' => self::ZODIAC_AWARD_ID, 'rank' => 0, 'zodiac_month' => 7]);

        $this->assertSame(0, (int) $this->apiRowFor($id)['Rank']);
        $this->assertSame(7, (int) $this->apiRowFor($id)['ZodiacMonth']);
    }

    public function testMonthNameIsSuppliedSoConsumersNeedNoLookupTable(): void
    {
        $id = $this->grantRaw(['award_id' => self::ZODIAC_AWARD_ID, 'zodiac_month' => 7]);
        $this->assertSame('July', $this->apiRowFor($id)['ZodiacMonthName']);
    }

    public function testAMonthlessZodiacReportsZeroAndAnEmptyName(): void
    {
        $id = $this->grantRaw(['award_id' => self::ZODIAC_AWARD_ID, 'zodiac_month' => 0]);
        $row = $this->apiRowFor($id);

        $this->assertSame(0, (int) $row['ZodiacMonth']);
        $this->assertSame('', $row['ZodiacMonthName']);
    }

    public function testANonZodiacAwardReportsZeroMonth(): void
    {
        $id = $this->grantRaw(['award_id' => self::ROSE_AWARD_ID, 'rank' => 4, 'zodiac_month' => 0]);

        $this->assertSame(0, (int) $this->apiRowFor($id)['ZodiacMonth']);
        $this->assertSame(4, (int) $this->apiRowFor($id)['Rank']);
    }

    public function testALegacyClientGrantingAZodiacByRankStillSucceeds(): void
    {
        // The carve-out. An integration written before this change must not break.
        $this->grantViaApi(['Rank' => 4]);
        // grantViaApi() already asserts Status === 0; nothing further to check.
        $this->assertTrue(true);
    }

    public function testALegacyRankIsStoredAsARankAndNeverAsAMonth(): void
    {
        $id = $this->grantViaApi(['Rank' => 4]);

        $this->assertSame(4, $this->columnOf($id, 'rank'));
        $this->assertSame(
            0,
            $this->columnOf($id, 'zodiac_month'),
            'inbound Rank must never be silently reinterpreted as April'
        );
    }

    public function testEveryExistingApiKeySurvives(): void
    {
        $id = $this->grantRaw(['award_id' => self::ZODIAC_AWARD_ID, 'rank' => 4]);
        $row = $this->apiRowFor($id);
        foreach ([
            'AwardsId', 'AwardId', 'MundaneId', 'Rank', 'Date', 'GivenById', 'Note',
            'ParkId', 'KingdomId', 'EventId', 'Name', 'KingdomAwardName',
            'CustomAwardName', 'IsLadder', 'IsTitle', 'TitleClass',
            'ParkName', 'KingdomName', 'EventName', 'GivenBy',
        ] as $key) {
            $this->assertArrayHasKey($key, $row, "API key {$key} must not disappear");
        }
    }
}
