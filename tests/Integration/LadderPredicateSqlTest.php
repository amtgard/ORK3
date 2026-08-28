<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Proves Award::LadderSql() classifies rows the way the deleted
 * pseudoLadderKingdomAwardIds() array did: kingdom ladders are found by column,
 * and an official ladder can never be lowered by its kingdom row.
 *
 * testProductionGroupingPutsAnOfficialLadderInTheOfficialGroup() additionally
 * calls the real Award::GetAwardOptionGroups() -- AwardOptionGroupsTest's
 * "mirror" tests reimplement the classification loop over sample rows and
 * never call production code, so this is the only test that exercises
 * requirement 1 against code that actually runs.
 */
final class LadderPredicateSqlTest extends TestCase
{
    private const MARKER = 'LADSQL';

    private PDO $pdo;

    /** @var list<int> */
    private array $kingdomAwardIds = [];

    private ?int $kingdomIdForGrouping = null;

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
        if ($this->kingdomIdForGrouping !== null) {
            $stmt = $this->pdo->prepare('DELETE FROM ork_kingdom WHERE kingdom_id = :id');
            $stmt->execute([':id' => $this->kingdomIdForGrouping]);
        }
    }

    private function seedKingdomAward(int $awardId, int $isLadder, int $kingdomId = 0): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_kingdomaward (kingdom_id, award_id, name, is_ladder, max_level)
             VALUES (:kingdom_id, :award_id, :name, :is_ladder, 0)'
        );
        $stmt->execute([
            ':kingdom_id' => $kingdomId,
            ':award_id'   => $awardId,
            ':name'       => self::MARKER . '-' . $awardId . '-' . $isLadder . '-' . uniqid(),
            ':is_ladder'  => $isLadder,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedKingdom(): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_kingdom (name, abbreviation, parent_kingdom_id) VALUES (:name, :abbr, 0)'
        );
        $stmt->execute([
            ':name' => self::MARKER . '-Kingdom-' . uniqid(),
            ':abbr' => 'LSQ',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param list<array{Label: string, Items: list<array<string, mixed>>}> $groups
     * @return list<int>
     */
    private function groupKingdomAwardIds(array $groups, string $label): array
    {
        foreach ($groups as $group) {
            if (($group['Label'] ?? null) === $label) {
                return array_map('intval', array_column($group['Items'] ?? [], 'KingdomAwardId'));
            }
        }

        return [];
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

    /**
     * Award::OfficialLadderSql() under NOT (...) over a LEFT JOIN that matched no
     * ork_award row. This is the exact shape Report::GetLadderAwardGrid's
     * kingdom-column query uses, and every pure kingdom ladder hits it: 17 of the
     * 26 live ka.is_ladder = 1 rows carry ka.award_id = 0, which joins to nothing.
     *
     * A bare `a.is_ladder = 1` is NULL there, `NOT (NULL)` is NULL, and NULL fails
     * the WHERE -- so the unsafe spelling silently drops the row it was supposed to
     * keep. This asserts against the live database, not against the string.
     */
    public function testOfficialLadderSqlIsFalseNotNullForAnUnmatchedLeftJoin(): void
    {
        $kingdomLadderId = $this->kingdomAwardIds['kingdomLadder']; // award_id = 0, joins to nothing

        $sql = 'SELECT ka.kingdomaward_id,
                       a.award_id AS joined_award_id,
                       ' . Award::OfficialLadderSql() . ' AS official,
                       NOT (' . Award::OfficialLadderSql() . ') AS not_official
                FROM ork_kingdomaward ka
                LEFT JOIN ork_award a ON a.award_id = ka.award_id
                WHERE ka.kingdomaward_id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':id' => $kingdomLadderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertIsArray($row, 'the seeded kingdom ladder row must come back');
        $this->assertNull(
            $row['joined_award_id'],
            'this row must genuinely have no matching ork_award, or the test proves nothing'
        );
        $this->assertNotNull($row['official'], 'the predicate itself must never evaluate to NULL');
        $this->assertSame(0, (int) $row['official']);
        $this->assertNotNull($row['not_official'], 'NOT (predicate) must be FALSE, never NULL');
        $this->assertSame(1, (int) $row['not_official']);

        // And the row therefore survives a WHERE built from it -- the behaviour the
        // Ladder Grid's kingdom columns depend on.
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ork_kingdomaward ka
             LEFT JOIN ork_award a ON a.award_id = ka.award_id
             WHERE ka.kingdomaward_id = :id AND NOT (' . Award::OfficialLadderSql() . ')'
        );
        $stmt->execute([':id' => $kingdomLadderId]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'the kingdom ladder must survive the NOT filter');
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

    /**
     * Calls the real Award::GetAwardOptionGroups() -- not a mirror -- so a
     * regression in requirement 1 (an official ladder must never be
     * un-toggleable by a kingdom) fails this test, not just a copy of the logic.
     */
    public function testProductionGroupingPutsAnOfficialLadderInTheOfficialGroup(): void
    {
        $this->kingdomIdForGrouping = $this->seedKingdom();

        // Tie-break case: award-backed (a.is_ladder = 1) but the kingdom row
        // itself says no. LadderSql()'s GREATEST() makes this effectively 1
        // regardless -- production must classify it as official, not kingdom.
        $officialBackedId = $this->seedKingdomAward(21, 0, $this->kingdomIdForGrouping);
        // Kingdom-only: no Amtgard parent, raised purely by the kingdom's own flag.
        $kingdomOnlyId = $this->seedKingdomAward(0, 1, $this->kingdomIdForGrouping);

        $result = (new Award())->GetAwardOptionGroups(['KingdomId' => $this->kingdomIdForGrouping]);
        $this->assertSame(0, $result['Status']['Status'] ?? null, 'GetAwardOptionGroups() must succeed against the seeded kingdom');

        $officialIds = $this->groupKingdomAwardIds($result['Groups'] ?? [], 'Official Ladder Awards');
        $kingdomIds  = $this->groupKingdomAwardIds($result['Groups'] ?? [], 'Kingdom Ladder Awards');

        $this->assertContains(
            $officialBackedId,
            $officialIds,
            'award_id=21 (a.is_ladder=1) must classify as official even with ka.is_ladder=0'
        );
        $this->assertNotContains(
            $officialBackedId,
            $kingdomIds,
            'requirement 1: an official ladder must never be presented as kingdom-only'
        );

        $this->assertContains(
            $kingdomOnlyId,
            $kingdomIds,
            'a kingdom-only ka.is_ladder=1 row must classify as a kingdom ladder'
        );
        $this->assertNotContains(
            $kingdomOnlyId,
            $officialIds,
            'a kingdom-only award must not appear as an official Amtgard order'
        );
    }

    public function testGetAwardListSelectsAndFiltersOnTheSameDefinition(): void
    {
        // The headline defect: GetAwardList filtered on ka.is_ladder but selected
        // a.is_ladder, so one method answered "is this a ladder?" two ways.
        $source = file_get_contents(__DIR__ . '/../../system/lib/ork3/class.Kingdom.php');
        $start  = strpos($source, 'public function GetAwardList');
        $this->assertNotFalse($start, 'GetAwardList not found');

        $end  = strpos($source, "\n    public function", $start + 10);
        $body = substr($source, $start, $end - $start);

        $this->assertStringContainsString(
            'Award::LadderSql(',
            $body,
            'GetAwardList must resolve ladders through the shared helper'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\ba\.is_ladder\b/',
            $body,
            'GetAwardList must not spell the predicate by hand'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\bka\.is_ladder\b(?!.*IFNULL)/',
            $body,
            'GetAwardList must not filter on the bare kingdom column'
        );
    }
}
