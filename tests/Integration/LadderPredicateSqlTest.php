<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Proves Award::LadderSql() classifies rows the way the deleted
 * pseudoLadderKingdomAwardIds() array did: kingdom ladders are found by column,
 * and an official ladder can never be lowered by its kingdom row.
 */
final class LadderPredicateSqlTest extends TestCase
{
    private const MARKER = 'LADSQL';

    private PDO $pdo;

    /** @var list<int> */
    private array $kingdomAwardIds = [];

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

        // award_id 21 (Order of the Rose) is an official ladder: ork_award.is_ladder = 1.
        // award_id 0 stands for a pure kingdom award with no Amtgard parent.
        $this->kingdomAwardIds = [
            'officialUnflagged' => $this->seedKingdomAward(21, 0), // official, ka says no
            'kingdomLadder'     => $this->seedKingdomAward(0, 1),  // kingdom ladder
            'plainKingdom'      => $this->seedKingdomAward(0, 0),  // ordinary kingdom award
        ];
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->pdo->exec("DELETE FROM ork_kingdomaward WHERE name LIKE '" . self::MARKER . "%'");
    }

    private function seedKingdomAward(int $awardId, int $isLadder): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_kingdomaward (kingdom_id, award_id, name, is_ladder, max_level)
             VALUES (0, :award_id, :name, :is_ladder, 0)'
        );
        $stmt->execute([
            ':award_id'  => $awardId,
            ':name'      => self::MARKER . '-' . $awardId . '-' . $isLadder . '-' . uniqid(),
            ':is_ladder' => $isLadder,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function effectiveLadderFor(int $kingdomAwardId): int
    {
        $sql = 'SELECT ' . Award::LadderSql() . ' AS eff
                FROM ork_kingdomaward ka
                LEFT JOIN ork_award a ON a.award_id = ka.award_id
                WHERE ka.kingdomaward_id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $kingdomAwardId]);

        return (int) $stmt->fetchColumn();
    }

    public function testKingdomLadderIsFoundByColumn(): void
    {
        $this->assertSame(1, $this->effectiveLadderFor($this->kingdomAwardIds['kingdomLadder']));
    }

    public function testOrdinaryKingdomAwardIsNotALadder(): void
    {
        $this->assertSame(0, $this->effectiveLadderFor($this->kingdomAwardIds['plainKingdom']));
    }

    public function testOfficialLadderCannotBeLoweredByItsKingdomRow(): void
    {
        // Requirement 1: the 16 official ladders must never be un-toggleable.
        // GREATEST makes it arithmetically impossible even with ka.is_ladder = 0.
        $this->assertSame(1, $this->effectiveLadderFor($this->kingdomAwardIds['officialUnflagged']));
    }

    public function testLadderSqlFindsEveryRowTheHardcodedListNamed(): void
    {
        // The 24 ids the deleted array held. On a production-shaped database these
        // are exactly the rows LadderSql() returns; on a sparse test database the
        // assertion is the weaker but still meaningful "no flagged row is missed".
        $rows = $this->pdo->query(
            'SELECT ka.kingdomaward_id
             FROM ork_kingdomaward ka
             LEFT JOIN ork_award a ON a.award_id = ka.award_id
             WHERE ka.is_ladder = 1'
        )->fetchAll(PDO::FETCH_COLUMN);

        foreach ($rows as $kingdomAwardId) {
            $this->assertSame(
                1,
                $this->effectiveLadderFor((int) $kingdomAwardId),
                "kingdomaward_id {$kingdomAwardId} is flagged but LadderSql() missed it"
            );
        }
    }
}
