<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Task 12 (2026-08-27-kingdom-ladder-awards): Player::AwardsForPlayer()'s
 * IsLadder became the EFFECTIVE ladder (kingdom ladders included, see
 * Award::LadderSql()), widened from the pre-existing official-only meaning.
 * That is the point of the ladder feature, but a consumer filtering
 * IsLadder == 1 over the SOAP/JSON API did not ask for kingdom ladders too --
 * so the old, official-only meaning must stay reachable under its own key,
 * and the ladder's real height must be resolvable without the client
 * guessing it from the award's name.
 *
 * AwardsForPlayer() takes no Token (it is a plain read), so no authorized
 * officer is created here -- only a seeded recipient (AuthorizedOfficerFixture::
 * seedRecipient(), shared rather than re-declared) and directly-inserted
 * ork_awards rows (grantRaw()), exactly the pattern ZodiacGrantTest uses for
 * the same kind of read-path assertion.
 */
final class AwardApiCompatTest extends TestCase
{
    private const MARKER = 'AWDCOMPAT';
    private const KINGDOM_ID = 1;

    // Order of the Rose: a real row in ork_award with is_ladder = 1 -- one of
    // the 16 official Amtgard ladders (confirmed directly against ork_award).
    private const OFFICIAL_LADDER_AWARD_ID = 21;

    private PDO $pdo;
    private Player $player;
    private AuthorizedOfficerFixture $fixture;
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
        $this->fixture = new AuthorizedOfficerFixture($this->pdo, self::MARKER, self::KINGDOM_ID);
        $this->recipientId = $this->fixture->seedRecipient();
    }

    protected function tearDown(): void
    {
        foreach ($this->kingdomAwardIdsToClean as $kingdomAwardId) {
            $this->pdo->exec('DELETE FROM ork_awards WHERE kingdomaward_id = ' . (int) $kingdomAwardId);
        }
        $this->kingdomAwardIdsToClean = [];

        $this->pdo->exec("DELETE FROM ork_kingdomaward WHERE name LIKE '" . self::MARKER . "%'");
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    /**
     * @param int $awardId 0 for a pure kingdom award (no official ork_award row)
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
     * Inserts an ork_awards row directly, bypassing all business logic. Mirrors
     * ZodiacGrantTest::grantRaw() -- this test cares only about the read path
     * (AwardsForPlayer's response map), so a raw row is enough and side-steps
     * AddAward's ladder/rank validation entirely.
     */
    private function grantRaw(int $awardId, int $kingdomAwardId, int $rank = 0): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_awards (mundane_id, kingdomaward_id, award_id, `rank`, date)
             VALUES (:mid, :kaid, :award_id, :rank, :date)'
        );
        $stmt->execute([
            ':mid' => $this->recipientId,
            ':kaid' => $kingdomAwardId,
            ':award_id' => $awardId,
            ':rank' => $rank,
            ':date' => '2020-01-01',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Calls Player::AwardsForPlayer() for this test's recipient and returns the
     * response row matching the given awards_id.
     */
    private function awardRowFor(int $awardsId): array
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

    public function testOfficialIsLadderPreservesTheOldMeaning(): void
    {
        // Official ladder: a real ork_award row with is_ladder = 1 (Order of the
        // Rose). The kingdomaward row itself is NOT flagged as a ladder -- the
        // official-only meaning comes from the base award, not the kingdom row.
        $kaId = $this->seedKingdomAward(self::OFFICIAL_LADDER_AWARD_ID, 0, 0);
        $officialLadderGrantId = $this->grantRaw(self::OFFICIAL_LADDER_AWARD_ID, $kaId, 5);

        $row = $this->awardRowFor($officialLadderGrantId);
        $this->assertSame(1, (int) $row['OfficialIsLadder']);
        $this->assertSame(1, (int) $row['IsLadder'], 'an official ladder is also an effective ladder');

        // Kingdom ladder: a pure kingdom award (award_id 0, no ork_award row) that
        // a kingdom has raised to ladder status via ka.is_ladder. It is an
        // effective ladder but was never one of the 16 official orders.
        $kaId2 = $this->seedKingdomAward(0, 1, 8);
        $kingdomLadderGrantId = $this->grantRaw(0, $kaId2, 3);

        $row = $this->awardRowFor($kingdomLadderGrantId);
        $this->assertSame(0, (int) $row['OfficialIsLadder'], 'a kingdom ladder is not official');
        $this->assertSame(1, (int) $row['IsLadder'], 'but it is an effective ladder');
    }

    public function testMaxRankResolvesTheLadderHeightWithoutNameMatching(): void
    {
        // Official ladder: height comes from Award::GetLadderMasterMap(), not
        // ka.max_level (Order of the Rose's ceiling is 10, per the map).
        $kaId = $this->seedKingdomAward(self::OFFICIAL_LADDER_AWARD_ID, 0, 0);
        $officialGrantId = $this->grantRaw(self::OFFICIAL_LADDER_AWARD_ID, $kaId, 5);
        $this->assertSame(10, (int) $this->awardRowFor($officialGrantId)['MaxRank']);

        // Kingdom ladder: height comes from ka.max_level, capped at 12.
        $kaId2 = $this->seedKingdomAward(0, 1, 8);
        $kingdomGrantId = $this->grantRaw(0, $kaId2, 3);
        $this->assertSame(8, (int) $this->awardRowFor($kingdomGrantId)['MaxRank']);
    }

    public function testRankIsTheRawColumnAndIsNeverReinterpreted(): void
    {
        $kaId = $this->seedKingdomAward(self::OFFICIAL_LADDER_AWARD_ID, 0, 0);
        $rankedGrantId = $this->grantRaw(self::OFFICIAL_LADDER_AWARD_ID, $kaId, 4);

        $row = $this->awardRowFor($rankedGrantId);
        $this->assertSame(4, (int) $row['Rank']);
    }

    public function testExistingKeysAreAllStillPresent(): void
    {
        // Additive only. Any key a consumer reads today must survive.
        $kaId = $this->seedKingdomAward(self::OFFICIAL_LADDER_AWARD_ID, 0, 0);
        $rankedGrantId = $this->grantRaw(self::OFFICIAL_LADDER_AWARD_ID, $kaId, 4);

        $row = $this->awardRowFor($rankedGrantId);
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
