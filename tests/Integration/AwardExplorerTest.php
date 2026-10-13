<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Award Explorer: registry, tree validation and compiled SQL.
 *
 * The filter tree is untrusted input, so the rejection cases are the point of
 * this file, not an afterthought.
 */
final class AwardExplorerTest extends TestCase
{
    private AwardExplorer $ae;
    /** @var array<string, list<int>> */
    private array $known;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->ae = new AwardExplorer();
        $this->known = $this->ae->LoadKnown();
    }

    private function tree(array $children, string $op = 'AND'): array
    {
        return ['op' => $op, 'children' => $children];
    }

    public function testRegistryHasCriteriaAndColumns(): void
    {
        $reg = $this->ae->Registry();
        $this->assertNotEmpty($reg['criteria']);
        $this->assertNotEmpty($reg['columns']);
        foreach ($reg['criteria'] as $id => $def) {
            $this->assertArrayHasKey('label', $def, "criterion $id");
            $this->assertArrayHasKey('operands', $def, "criterion $id");
            $this->assertNotEmpty($def['operands'], "criterion $id has no operands");
            $this->assertIsCallable($def['sql'], "criterion $id has no SQL builder");
        }
    }

    public function testDefaultColumnsAreUsedWhenNoneAsked(): void
    {
        $this->assertNotEmpty($this->ae->ResolveColumns([]));
        // Unknown ids are dropped, not fatal, and never reach ColumnSelectSql.
        $this->assertSame(['date'], $this->ae->ResolveColumns(['date', 'nonsense', 'date']));
    }

    /**
     * @dataProvider badTrees
     */
    public function testUntrustedInputIsRejected(string $why, array $tree): void
    {
        $r = $this->ae->NormalizeTree($tree, $this->known);
        $this->assertFalse($r['ok'], "should have been rejected: $why");
        $this->assertNotSame('', (string)$r['error']);
    }

    public static function badTrees(): array
    {
        return [
            'unknown criterion'   => ['unknown criterion', ['op' => 'AND', 'children' => [['c' => 'drop_tables', 'o' => 'is', 'v' => 1]]]],
            'bad operand'         => ['bad operand', ['op' => 'AND', 'children' => [['c' => 'date', 'o' => 'sql', 'v' => '2020-01-01']]]],
            'sql in a date'       => ['sql in a date', ['op' => 'AND', 'children' => [['c' => 'date', 'o' => 'eq', 'v' => "2020-01-01' OR 1=1--"]]]],
            'impossible date'     => ['impossible date', ['op' => 'AND', 'children' => [['c' => 'date', 'o' => 'eq', 'v' => '2020-02-31']]]],
            'unknown award id'    => ['unknown award id', ['op' => 'AND', 'children' => [['c' => 'award', 'o' => 'is', 'v' => 99999999]]]],
            'rank above max'      => ['rank above max', ['op' => 'AND', 'children' => [['c' => 'rank', 'o' => 'eq', 'v' => 500]]]],
            'bad group operator'  => ['bad group operator', ['op' => 'XOR', 'children' => []]],
            'empty id list'       => ['empty id list', ['op' => 'AND', 'children' => [['c' => 'award', 'o' => 'in', 'v' => []]]]],
            'months out of range' => ['months out of range', ['op' => 'AND', 'children' => [['c' => 'months_ago', 'o' => 'is', 'v' => 1, 'p' => 99999]]]],
        ];
    }

    public function testNestingAndLeafLimitsAreEnforced(): void
    {
        $deep = ['c' => 'rank', 'o' => 'eq', 'v' => 1];
        for ($i = 0; $i < AwardExplorer::MAX_DEPTH + 2; $i++) {
            $deep = ['op' => 'AND', 'children' => [$deep]];
        }
        $this->assertFalse($this->ae->NormalizeTree($deep, $this->known)['ok'], 'depth limit');

        $many = [];
        for ($i = 0; $i <= AwardExplorer::MAX_LEAVES; $i++) {
            $many[] = ['c' => 'rank', 'o' => 'eq', 'v' => 1];
        }
        $this->assertFalse($this->ae->NormalizeTree($this->tree($many), $this->known)['ok'], 'leaf limit');
    }

    public function testBetweenAcceptsEitherOrderAndSorts(): void
    {
        $r = $this->ae->NormalizeTree(
            $this->tree([['c' => 'date', 'o' => 'between', 'v' => ['2026-06-30', '2026-01-01']]]),
            $this->known
        );
        $this->assertTrue($r['ok']);
        $this->assertSame(['2026-01-01', '2026-06-30'], $r['tree']['children'][0]['v']);
    }

    /**
     * A date comparison must not match the 0000-00-00 placeholder. Without the
     * guard, "before 2020" silently returns every undated row (5,951 on production).
     */
    public function testDateComparisonExcludesUndatedRows(): void
    {
        $r = $this->ae->NormalizeTree($this->tree([['c' => 'date', 'o' => 'lt', 'v' => '2020-01-01']]), $this->known);
        $this->assertTrue($r['ok']);
        $sql = $this->ae->CompileTree($r['tree'], $this->known);
        $this->assertStringContainsString("a.date <> '0000-00-00'", $sql);
    }

    /**
     * Ladder classification must come from Award::sqlEffectiveIsLadder, which
     * includes the curated kingdom-original list. `rank > 0` is NOT a ladder test.
     */
    public function testLadderCriterionUsesTheSharedLadderRule(): void
    {
        $r = $this->ae->NormalizeTree($this->tree([['c' => 'is_ladder', 'o' => 'is', 'v' => 'yes']]), $this->known);
        $this->assertTrue($r['ok']);
        $sql = $this->ae->CompileTree($r['tree'], $this->known);
        $this->assertStringContainsString('ka.is_ladder', $sql);
        foreach (Award::pseudoLadderKingdomAwardIds() as $id) {
            $this->assertStringContainsString((string)(int)$id, $sql);
        }
    }

    /**
     * "All Knights / Paragons / Masters" has to work without anyone knowing an id.
     * Peerage is the criterion that answers it.
     */
    public function testPeerageFindsKnighthoodsAndParagons(): void
    {
        foreach (['Knight', 'Master', 'Paragon', 'Kingdom-Level-Award'] as $peerage) {
            $r = $this->ae->NormalizeTree($this->tree([['c' => 'peerage', 'o' => 'is', 'v' => $peerage]]), $this->known);
            $this->assertTrue($r['ok'], "peerage $peerage should be accepted");
            $sql = $this->ae->CompileTree($r['tree'], $this->known);
            $this->assertStringContainsString("w.peerage IN ('" . $peerage . "')", $sql);
        }
        $r = $this->ae->NormalizeTree(
            $this->tree([['c' => 'peerage', 'o' => 'in', 'v' => ['Knight', 'Master']]]),
            $this->known
        );
        $this->assertTrue($r['ok']);
        $this->assertStringContainsString("w.peerage IN ('Knight','Master')", $this->ae->CompileTree($r['tree'], $this->known));
    }

    /** Peerage is the only string a caller picks, so it must not be able to carry SQL. */
    public function testPeerageRejectsAnythingNotInTheConstant(): void
    {
        foreach (["Knight' OR 1=1--", 'knight', 'Knights', '', 'DROP TABLE', 1] as $evil) {
            $r = $this->ae->NormalizeTree($this->tree([['c' => 'peerage', 'o' => 'is', 'v' => $evil]]), $this->known);
            $this->assertFalse($r['ok'], 'peerage accepted something outside the constant: ' . var_export($evil, true));
        }
    }

    /** The pickers only help if the option lists actually arrive with the registry. */
    public function testRegistryShipsNamedOptionsForThePickers(): void
    {
        $reg = $this->ae->Registry($this->known);
        $this->assertArrayHasKey('options', $reg);
        foreach (['award', 'kingdom', 'park', 'peerage'] as $set) {
            $this->assertArrayHasKey($set, $reg['options'], "no option list for $set");
        }
        $this->assertSame(count(AwardExplorer::PEERAGE), count($reg['options']['peerage']));
        foreach ($reg['options']['peerage'] as $o) {
            $this->assertContains($o['id'], AwardExplorer::PEERAGE);
            $this->assertNotSame('', $o['name']);
        }
    }

    /**
     * Linkable columns must carry their id, or the Recipient / Park / Kingdom
     * cells can only be text. Recipient falls back to stripped_from so a stripped
     * grant still links to whoever held it.
     */
    public function testLinkableColumnsCarryTheirIds(): void
    {
        $r = $this->ae->NormalizeTree($this->tree([]), $this->known);
        $cols = ['recipient', 'recipient_park', 'recipient_kingdom', 'given_by', 'kingdom', 'park'];
        $out = $this->ae->RunRows($r['tree'], $cols, ['limit' => 5], $this->known);
        if (!$out['rows']) {
            $this->markTestSkipped('No awards in the fixture database to inspect.');
        }
        foreach ($out['rows'] as $row) {
            foreach (['recipient_id', 'recipient_park_id', 'recipient_kingdom_id', 'given_by_id', 'kingdom_id', 'park_id'] as $k) {
                $this->assertArrayHasKey($k, $row, "missing link id $k");
                $this->assertIsInt($row[$k]);
            }
        }
    }

    /** A column that links nowhere must not gain a stray id key. */
    public function testNonLinkableColumnsGainNoIds(): void
    {
        $r = $this->ae->NormalizeTree($this->tree([]), $this->known);
        $out = $this->ae->RunRows($r['tree'], ['date', 'award_name', 'rank'], ['limit' => 3], $this->known);
        foreach ($out['rows'] as $row) {
            $this->assertSame(['AwardsId', 'date', 'award_name', 'rank'], array_keys($row));
        }
    }

    /**
     * The Park filter matches the park recorded ON THE AWARD, while "Recipient park
     * today" is where that person is now. They disagree for anyone who transferred,
     * which reads as a broken filter unless the column the filter acts on is the one
     * on screen. So Park must be a default column, and the labels must say which is
     * which.
     */
    public function testParkFilterAndDefaultColumnDescribeTheSameThing(): void
    {
        $this->assertContains('park', $this->ae->ResolveColumns([]), 'Park must be shown by default');

        $cols = $this->ae->Registry($this->known)['columns'];
        $this->assertStringContainsString('on the award', $cols['park']['label']);
        $this->assertStringContainsString('on the award', $cols['kingdom']['label']);
        $this->assertStringContainsString('today', $cols['recipient_park']['label']);
        $this->assertStringContainsString('today', $cols['recipient_kingdom']['label']);

        // The filter reads the award's own park, never the recipient's current one.
        // Take a real id from the loaded set: the fixture database numbers its parks
        // from 1000001, so a hardcoded 1 is correctly rejected as unknown.
        $parkId = (int)reset($this->known['park']);
        $this->assertGreaterThan(0, $parkId, 'no parks in the fixture database');
        $r = $this->ae->NormalizeTree($this->tree([['c' => 'park', 'o' => 'is', 'v' => $parkId]]), $this->known);
        $this->assertTrue($r['ok']);
        $sql = $this->ae->CompileTree($r['tree'], $this->known);
        $this->assertStringContainsString('a.park_id', $sql);
        $this->assertStringNotContainsString('m.park_id', $sql);
    }

    public function testEmptyTreeCompilesToATruth(): void
    {
        $r = $this->ae->NormalizeTree($this->tree([]), $this->known);
        $this->assertTrue($r['ok']);
        $this->assertSame('1=1', $this->ae->CompileTree($r['tree'], $this->known));
    }

    /** Revoked and stripped rows are defaults applied outside the tree, so an OR cannot widen past them. */
    public function testRevokedAndStrippedAreExcludedByDefault(): void
    {
        $r = $this->ae->NormalizeTree($this->tree([]), $this->known);
        $out = $this->ae->RunRows($r['tree'], ['date', 'recipient', 'revoked'], ['limit' => 25], $this->known);
        foreach ($out['rows'] as $row) {
            $this->assertSame(0, (int)$row['revoked'], 'a revoked grant leaked into the default view');
            $this->assertNotSame('', (string)$row['recipient'], 'a stripped grant leaked into the default view');
        }
    }

    public function testRunReturnsOnlyTheRequestedColumns(): void
    {
        $r = $this->ae->NormalizeTree($this->tree([]), $this->known);
        $out = $this->ae->RunRows($r['tree'], ['date', 'award_name'], ['limit' => 3], $this->known);
        $this->assertLessThanOrEqual(3, count($out['rows']));
        $this->assertSame(['date', 'award_name'], $out['columns']);
        foreach ($out['rows'] as $row) {
            $this->assertSame(['AwardsId', 'date', 'award_name'], array_keys($row));
        }
    }

    /**
     * Paging must be stable when many grants share one date.
     *
     * `date` is a DATE, not a DATETIME, so a court that records a whole evening of
     * awards gives every one of them an identical sort key. Without awards_id as a
     * tiebreaker the order between them is undefined, and page 2 can repeat or skip
     * rows that page 1 already showed. Seeded here rather than assumed, because the
     * fixture database holds a single award.
     */
    public function testPagingIsStableAcrossGrantsSharingOneDate(): void
    {
        $ids = $this->seedSameDayAwards(5, '2024-03-09');
        try {
            $r = $this->ae->NormalizeTree(
                $this->tree([['c' => 'date', 'o' => 'eq', 'v' => '2024-03-09']]),
                $this->known
            );
            $this->assertTrue($r['ok']);

            $seen = [];
            for ($offset = 0; $offset < 6; $offset += 2) {
                $page = $this->ae->RunRows($r['tree'], ['date'], ['limit' => 2, 'offset' => $offset], $this->known);
                foreach ($page['rows'] as $row) {
                    $seen[] = (int)$row['AwardsId'];
                }
            }
            $this->assertSame($ids, $seen, 'paging repeated, skipped or reordered same-date grants');
            $this->assertSame(array_unique($seen), $seen, 'a grant appeared on two pages');
        } finally {
            $this->removeSeeded($ids);
        }
    }

    /** @return list<int> seeded awards_id values, in the order Run must return them */
    private function seedSameDayAwards(int $n, string $date): array
    {
        global $DB;
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $DB->Clear();
            $DB->Execute(
                'INSERT INTO ' . DB_PREFIX . 'awards (kingdomaward_id, mundane_id, `rank`, date, given_by_id, note, '
                . 'at_park_id, at_kingdom_id, at_event_id, custom_name, award_id, by_whom_id, entered_at, revoked, revocation, revoked_by_id) '
                . "VALUES (0, 999000, 0, '" . $date . "', 0, 'award-explorer test', 0, 0, 0, 'AE Test Award', 0, 0, NOW(), 0, '', 0)"
            );
            $DB->Clear();
            $rs = $DB->DataSet('SELECT LAST_INSERT_ID() AS id');
            $ids[] = $rs->Next() ? (int)$rs->id : 0;
        }
        // Run orders by date DESC then awards_id DESC, so the expected order is newest id first.
        rsort($ids);
        return $ids;
    }

    /** @param list<int> $ids */
    private function removeSeeded(array $ids): void
    {
        global $DB;
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return;
        }
        $DB->Clear();
        $DB->Execute('DELETE FROM ' . DB_PREFIX . 'awards WHERE awards_id IN (' . implode(',', $ids) . ')');
    }

    /** The one free-text path. Quotes and wildcards must not survive the whitelist. */
    public function testSearchTextCannotCarrySql(): void
    {
        $r = $this->ae->NormalizeTree($this->tree([]), $this->known);
        foreach (["x' OR 1=1 -- ", '%', '_', "'; DROP TABLE ork_awards; --"] as $evil) {
            $out = $this->ae->RunRows($r['tree'], ['date'], ['search' => $evil, 'limit' => 2], $this->known);
            $this->assertIsArray($out['rows'], "search survived: $evil");
        }
        // The table is still there.
        $this->assertGreaterThan(0, $this->ae->Count($r['tree'], [], $this->known));
    }

    public function testRunLockNameIsDatabaseQualifiedAndBounded(): void
    {
        $name = $this->ae->RunLockName(12345);
        $this->assertStringStartsWith(AwardExplorer::RUN_LOCK_PREFIX, $name);
        $this->assertStringContainsString('12345', $name);
        $this->assertLessThanOrEqual(AwardExplorer::LOCK_NAME_MAX, strlen($name));
    }
}
