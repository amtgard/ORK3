<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Player::GetAwardMaxRanks() -- the held-rank map behind the rank pickers'
 * "already awarded" pills and their at-or-above guard.
 *
 * Defect X2: the map was built with GROUP BY ka.award_id, and 17 of 26
 * kingdom-ladder rows carry ka.award_id = 0 while 9 more share the generic 94
 * "Custom Award" placeholder. Every kingdom ladder a player held therefore
 * collapsed into a single bucket, so opening the picker for a kingdom ladder
 * they had NEVER held painted ranks 1..N green and hard-blocked the grant with
 * "already has this award at or above the rank selected".
 *
 * The map now carries two key spaces, matching Report::GetLadderAwardGrid's
 * columns: int award_id for official awards (aggregated across kingdomawards,
 * so a kingdom transfer does not lose a rank) and 'k' . kingdomaward_id for
 * every row, which is the only usable key for a kingdom ladder.
 *
 * Read-only and not Token-gated, so no AuthorizedOfficerFixture here.
 */
final class AwardMaxRanksTest extends TestCase
{
    private const MARKER = 'MAXRANKS';
    private const KINGDOM_ID = 1;
    private const PARK_ID = 999104;
    private const PLACEHOLDER_AWARD_ID = 94; // the shared "Custom Award" row
    private const OFFICIAL_AWARD_ID = 21;    // Order of the Rose

    private PDO $pdo;
    private Player $player;
    private int $mundaneId;

    /** @var list<int> */
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
        $this->mundaneId = $this->seedPlayer();
    }

    protected function tearDown(): void
    {
        foreach ($this->kingdomAwardIdsToClean as $kaId) {
            $this->pdo->exec('DELETE FROM ork_awards WHERE kingdomaward_id = ' . (int) $kaId);
        }
        $this->kingdomAwardIdsToClean = [];
        $this->pdo->exec("DELETE FROM ork_kingdomaward WHERE name LIKE '" . self::MARKER . "%'");
        if (isset($this->mundaneId)) {
            $this->pdo->exec('DELETE FROM ork_mundane WHERE mundane_id = ' . $this->mundaneId);
        }
    }

    private function seedPlayer(): int
    {
        $username = strtolower(self::MARKER . '_' . bin2hex(random_bytes(4)));
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_mundane
                (given_name, surname, other_name, username, persona, email, park_id, kingdom_id,
                 token, waiver_ext, password_expires, password_salt, xtoken, reeve_qualified_until)
             VALUES
                (:given_name, :surname, :other_name, :username, :persona, :email, :park_id, :kingdom_id,
                 :token, :waiver_ext, :password_expires, :password_salt, :xtoken, :reeve_qualified_until)'
        );
        $stmt->execute([
            ':given_name' => self::MARKER,
            ':surname' => 'Holder',
            ':other_name' => '',
            ':username' => $username,
            ':persona' => self::MARKER . ' Holder',
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

    /** @param int $awardId 0 for a kingdom ladder with no official base row */
    private function seedKingdomAward(int $awardId, int $maxLevel = 10): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_kingdomaward (kingdom_id, award_id, name, is_ladder, max_level)
             VALUES (:kingdom_id, :award_id, :name, 1, :max_level)'
        );
        $stmt->execute([
            ':kingdom_id' => self::KINGDOM_ID,
            ':award_id' => $awardId,
            ':name' => self::MARKER . '-' . uniqid(),
            ':max_level' => $maxLevel,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->kingdomAwardIdsToClean[] = $id;

        return $id;
    }

    private function grantRank(int $kingdomAwardId, int $awardId, int $rank, int $revoked = 0): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_awards (mundane_id, kingdomaward_id, award_id, `rank`, date, revoked)
             VALUES (:mid, :kaid, :award_id, :rank, :date, :revoked)'
        );
        $stmt->execute([
            ':mid' => $this->mundaneId,
            ':kaid' => $kingdomAwardId,
            ':award_id' => $awardId,
            ':rank' => $rank,
            ':date' => '2024-01-01',
            ':revoked' => $revoked,
        ]);
    }

    public function testTwoKingdomLaddersWithAwardIdZeroDoNotShareABucket(): void
    {
        $owl = $this->seedKingdomAward(0);
        $fox = $this->seedKingdomAward(0);
        $this->grantRank($owl, 0, 4);

        $ranks = $this->player->GetAwardMaxRanks($this->mundaneId);

        $this->assertSame(4, $ranks['k' . $owl] ?? null, 'the ladder actually held reads as rank 4');
        $this->assertArrayNotHasKey('k' . $fox, $ranks, 'a ladder never held must have no held rank at all');
        // The bug in one line: award_id 0 used to be a real key holding MAX(rank)
        // across every award_id = 0 grant, and the picker read ranks[0].
        $this->assertArrayNotHasKey(0, $ranks, 'award_id 0 must never become a shared bucket');
    }

    public function testTwoKingdomLaddersSharingThePlaceholderAwardIdKeepSeparateRanks(): void
    {
        $hardcore = $this->seedKingdomAward(self::PLACEHOLDER_AWARD_ID);
        $sharpshooter = $this->seedKingdomAward(self::PLACEHOLDER_AWARD_ID);
        $this->grantRank($hardcore, self::PLACEHOLDER_AWARD_ID, 8);
        $this->grantRank($sharpshooter, self::PLACEHOLDER_AWARD_ID, 3);

        $ranks = $this->player->GetAwardMaxRanks($this->mundaneId);

        $this->assertSame(8, $ranks['k' . $hardcore] ?? null);
        $this->assertSame(3, $ranks['k' . $sharpshooter] ?? null, "Hardcore's rank 8 must not leak into Sharpshooter");
    }

    public function testAnOfficialAwardStillAggregatesAcrossKingdomawards(): void
    {
        // Unchanged contract for the official key space: a player who transferred
        // kingdoms holds one Order of the Rose at the highest rank they earned.
        $homeKingdom = $this->seedKingdomAward(self::OFFICIAL_AWARD_ID);
        $newKingdom = $this->seedKingdomAward(self::OFFICIAL_AWARD_ID);
        $this->grantRank($homeKingdom, self::OFFICIAL_AWARD_ID, 6);
        $this->grantRank($newKingdom, self::OFFICIAL_AWARD_ID, 2);

        $ranks = $this->player->GetAwardMaxRanks($this->mundaneId);

        $this->assertSame(6, $ranks[self::OFFICIAL_AWARD_ID] ?? null, 'award_id key is the MAX across kingdomawards');
        $this->assertSame(6, $ranks['k' . $homeKingdom] ?? null);
        $this->assertSame(2, $ranks['k' . $newKingdom] ?? null);
    }

    public function testARevokedGrantIsNotAHeldRank(): void
    {
        $ka = $this->seedKingdomAward(0);
        $this->grantRank($ka, 0, 3);
        $this->grantRank($ka, 0, 7, revoked: 1);

        $ranks = $this->player->GetAwardMaxRanks($this->mundaneId);

        $this->assertSame(3, $ranks['k' . $ka] ?? null, 'a revoked rank 7 must not read as a held rank');
    }
}
