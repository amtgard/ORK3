<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Task 7: Zodiac reconciliation is month-based, not rank-based.
 *
 * Order of the Zodiac (award_id 30, Award::IsMonthlyLadder()) is granted once
 * per calendar month, so its twelve positions are months, not ranks. Its
 * reconcilability is decided by ZodiacMonth alone: a grant that already
 * carries a month is not reconcilable, whatever its legacy rank; a monthless
 * one is, whatever its legacy rank. There is no "past max" for a monthly
 * award, so the bonus-grant exclusion that applies to every other ladder does
 * not apply here.
 *
 * Player::GetReconcileSuggestions() (exercised here through the public
 * Player::GetReconcilePageData() DTO assembler) resolves the listing plus a
 * per-row SuggestedMonth pre-fill from Award::ZodiacMonthFromDate() -- a
 * suggestion only, never an automatic write. Player::ReconcileAward() writes
 * the officer-confirmed month to zodiac_month and leaves the legacy rank
 * exactly as it was.
 *
 * GetReconcilePageData() is a read-only DTO assembler and is not Token-gated,
 * but ReconcileAward() is -- AuthorizedOfficerFixture + grantParkAuthority()
 * supply a usable Token for the write tests, mirroring LadderGrantRuleTest
 * (Player::AddAward's player.award.manage check is scoped to 'park', and the
 * legacy HasAuthority() walk needs a real ork_park row that ork_test does not
 * ship -- a direct park-scoped authorization row sidesteps that walk).
 */
final class ZodiacReconcileTest extends TestCase
{
    private const MARKER = 'ZODRECON';
    private const KINGDOM_ID = 1;
    private const PARK_ID = 999102;
    private const ZODIAC_AWARD_ID = 30;

    private PDO $pdo;
    private Player $player;
    private AuthorizedOfficerFixture $officer;
    private string $token;
    private int $recipientId;
    private int $kingdomAwardId;

    /** @var list<int> */
    private array $awardsIdsToClean = [];

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
        $this->kingdomAwardId = $this->seedZodiacKingdomAward();
    }

    protected function tearDown(): void
    {
        foreach ($this->awardsIdsToClean as $id) {
            $this->pdo->exec('DELETE FROM ork_awards WHERE awards_id = ' . (int) $id);
        }
        $this->awardsIdsToClean = [];

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

    private function seedZodiacKingdomAward(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_kingdomaward (kingdom_id, award_id, name, is_ladder, max_level)
             VALUES (:kingdom_id, :award_id, :name, 1, 12)'
        );
        $stmt->execute([
            ':kingdom_id' => self::KINGDOM_ID,
            ':award_id' => self::ZODIAC_AWARD_ID,
            ':name' => self::MARKER . '-' . uniqid(),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Inserts a raw historical ork_awards row -- given_by_id AND by_whom_id both
     * 0, the "unmatched historical record" shape GetReconcileSuggestions()
     * partitions on (Player::AwardsForPlayer resolves EnteredById from
     * by_whom_id via a join, so leaving it 0 keeps the row historical; the
     * comment on LadderGrantRuleTest::seedAwardRow() notes the same thing also
     * keeps ReconcileAward's no-op short-circuit from firing).
     *
     * @param array{rank?: int, zodiac_month?: int, date?: string} $overrides
     */
    private function grantRaw(array $overrides = []): int
    {
        $rank = $overrides['rank'] ?? 0;
        $zodiacMonth = $overrides['zodiac_month'] ?? 0;
        $date = $overrides['date'] ?? '2026-01-01';

        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_awards
                (mundane_id, kingdomaward_id, award_id, `rank`, zodiac_month, date, given_by_id, by_whom_id)
             VALUES
                (:mundane_id, :kingdomaward_id, :award_id, :rank, :zodiac_month, :date, 0, 0)'
        );
        $stmt->execute([
            ':mundane_id' => $this->recipientId,
            ':kingdomaward_id' => $this->kingdomAwardId,
            ':award_id' => self::ZODIAC_AWARD_ID,
            ':rank' => $rank,
            ':zodiac_month' => $zodiacMonth,
            ':date' => $date,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->awardsIdsToClean[] = $id;

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reconcilableFor(int $mundaneId): array
    {
        $result = $this->player->GetReconcilePageData(['MundaneId' => $mundaneId]);
        $rows = $result['Detail']['HistoricalAwards'] ?? [];

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function rowFor(array $rows, int $awardsId): array
    {
        foreach ($rows as $row) {
            if ((int) ($row['AwardsId'] ?? 0) === $awardsId) {
                return $row;
            }
        }

        return [];
    }

    private function columnOf(int $awardsId, string $column): int
    {
        $stmt = $this->pdo->prepare('SELECT `' . $column . '` FROM ork_awards WHERE awards_id = :id');
        $stmt->execute([':id' => $awardsId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    private function reconcileZodiacRequest(int $awardsId, int $month): array
    {
        return [
            'Token' => $this->token,
            'AwardsId' => $awardsId,
            'KingdomAwardId' => $this->kingdomAwardId,
            'Rank' => 0,
            'ZodiacMonth' => $month,
            'Date' => '2026-01-01',
            'GivenById' => 0,
            'Note' => '',
            'ParkId' => 0,
            'KingdomId' => 0,
            'EventId' => 0,
        ];
    }

    public function testOnlyMonthlessZodiacsAreListed(): void
    {
        $withMonth = $this->grantRaw(['zodiac_month' => 4]);
        $without   = $this->grantRaw(['zodiac_month' => 0]);

        $ids = array_column($this->reconcilableFor($this->recipientId), 'AwardsId');

        $this->assertContains($without, $ids);
        $this->assertNotContains($withMonth, $ids);
    }

    public function testALegacyRankedZodiacIsStillReconcilable(): void
    {
        // 1,774 real Zodiac grants carry a rank and no month. A rank is not a month.
        $id = $this->grantRaw(['rank' => 5, 'zodiac_month' => 0]);

        $this->assertContains($id, array_column($this->reconcilableFor($this->recipientId), 'AwardsId'));
    }

    public function testThePrefillComesFromTheGrantDate(): void
    {
        $id  = $this->grantRaw(['zodiac_month' => 0, 'date' => '2024-03-28']);
        $row = $this->rowFor($this->reconcilableFor($this->recipientId), $id);

        $this->assertSame(3, (int) ($row['SuggestedMonth'] ?? -1));
    }

    public function testAZeroDateGrantPrefillsNoMonth(): void
    {
        // Award::ZodiacMonthFromDate() guards the '0000-00-00' sentinel -- 0,
        // never a spurious January.
        $id  = $this->grantRaw(['zodiac_month' => 0, 'date' => '0000-00-00']);
        $row = $this->rowFor($this->reconcilableFor($this->recipientId), $id);

        $this->assertSame(0, (int) ($row['SuggestedMonth'] ?? -1));
    }

    public function testReconcilingWritesTheMonthAndLeavesTheLegacyRank(): void
    {
        $id = $this->grantRaw(['rank' => 5, 'zodiac_month' => 0]);

        $result = $this->player->ReconcileAward($this->reconcileZodiacRequest($id, 3));

        $this->assertSame(0, (int) $result['Status']);
        $this->assertSame(3, $this->columnOf($id, 'zodiac_month'));
        $this->assertSame(5, $this->columnOf($id, 'rank'));
    }

    public function testBonusExclusionDoesNotApplyToZodiac(): void
    {
        // There is no "past max" for a monthly award: a Zodiac is reconcilable
        // exactly when it has no month, whatever its date.
        $id = $this->grantRaw(['zodiac_month' => 0, 'date' => '2030-01-01']);

        $this->assertContains($id, array_column($this->reconcilableFor($this->recipientId), 'AwardsId'));
    }

    public function testReconcilingRejectsAnOutOfRangeMonth(): void
    {
        $id = $this->grantRaw(['rank' => 5, 'zodiac_month' => 0]);

        $result = $this->player->ReconcileAward($this->reconcileZodiacRequest($id, 13));

        $this->assertNotSame(0, (int) $result['Status']);
        // The rejected write must not have touched either column.
        $this->assertSame(5, $this->columnOf($id, 'rank'));
        $this->assertSame(0, $this->columnOf($id, 'zodiac_month'));
    }
}
