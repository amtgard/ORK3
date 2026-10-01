<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PopulationExplorerTest extends TestCase
{
    private PopulationExplorer $pe;
    private array $known;

    protected function setUp(): void
    {
        $this->pe = new PopulationExplorer();
        $this->known = [
            'class'   => [1, 2, 7, 12],
            'award'   => [17, 18, 19, 20, 1, 2],
            'peerage' => ['Knight' => [17, 18, 19, 20], 'Master' => [1, 2], 'Paragon' => [], 'Squire' => [], 'Page' => [], 'Man-At-Arms' => []],
        ];
    }

    private function leaf(string $c, string $o, $v, ?int $p = null): array
    {
        $l = ['c' => $c, 'o' => $o, 'v' => $v];
        if ($p !== null) {
            $l['p'] = $p;
        }
        return $l;
    }

    private function norm(array $tree): array
    {
        return $this->pe->NormalizeTree($tree, $this->known);
    }

    public function testValidExampleQueryNormalizes(): void
    {
        $tree = ['op' => 'AND', 'children' => [
            $this->leaf('last_signin', 'gte', '2025-01-01'),
            $this->leaf('knighthood', 'has_any', ['17', '20', '18']),
            $this->leaf('dues_paid', 'is', 'yes'),
            ['op' => 'OR', 'children' => [
                $this->leaf('signins_last_n_months', 'gt', '5', 6),
                $this->leaf('last_class', 'is_not', '7'),
            ]],
        ]];
        $r = $this->norm($tree);
        $this->assertTrue($r['ok'], $r['error'] ?? '');
        $this->assertSame(1, $r['tree']['children'][2]['v']);               // yes -> 1
        $this->assertSame([17, 20, 18], $r['tree']['children'][1]['v']);    // ints
        $this->assertSame(5, $r['tree']['children'][3]['children'][0]['v']);
        $this->assertSame(6, $r['tree']['children'][3]['children'][0]['p']);
    }

    public function testEmptyChildGroupIsDroppedFromOr(): void
    {
        $r = $this->norm(['op' => 'OR', 'children' => [
            $this->leaf('active', 'is', 'yes'),
            ['op' => 'AND', 'children' => []],
        ]]);
        $this->assertTrue($r['ok']);
        $this->assertCount(1, $r['tree']['children']);
    }

    public function testEmptyRootIsAllowed(): void
    {
        $r = $this->norm(['op' => 'AND', 'children' => []]);
        $this->assertTrue($r['ok']);
        $this->assertSame([], $r['tree']['children']);
    }

    /** @dataProvider badRules */
    public function testRejections(array $leaf, string $needle): void
    {
        $r = $this->norm(['op' => 'AND', 'children' => [$leaf]]);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsStringIgnoringCase($needle, $r['error']);
        $this->assertSame([0], $r['path']);
    }

    public static function badRules(): array
    {
        return [
            'unknown criterion'        => [['c' => 'nope', 'o' => 'is', 'v' => 'yes'], 'criterion'],
            'operand wrong for type'   => [['c' => 'last_signin', 'o' => 'in', 'v' => [1]], 'operand'],
            'bad date format'          => [['c' => 'last_signin', 'o' => 'gte', 'v' => '01/02/2025'], 'date'],
            'impossible date'          => [['c' => 'last_signin', 'o' => 'gte', 'v' => '2025-02-30'], 'date'],
            'between wrong order'      => [['c' => 'last_signin', 'o' => 'between', 'v' => ['2025-05-01', '2025-01-01']], 'between'],
            'between not pair'         => [['c' => 'total_signins', 'o' => 'between', 'v' => [1]], 'between'],
            'non-numeric number'       => [['c' => 'total_signins', 'o' => 'gt', 'v' => 'abc'], 'number'],
            'empty IN list'            => [['c' => 'home_park', 'o' => 'in', 'v' => []], 'empty'],
            'unknown class id'         => [['c' => 'last_class', 'o' => 'is', 'v' => 9999], 'class'],
            'missing N param'          => [['c' => 'signins_last_n_months', 'o' => 'gt', 'v' => 1], 'param'],
            'N param out of range'     => [['c' => 'signins_last_n_months', 'o' => 'gt', 'v' => 1, 'p' => 61], 'param'],
            'bad bool'                 => [['c' => 'dues_paid', 'o' => 'is', 'v' => 'maybe'], 'yes'],
            'peerage id not a knight'  => [['c' => 'knighthood', 'o' => 'has_any', 'v' => [1]], 'knighthood'],
        ];
    }

    public function testInjectionAttemptsAreRejected(): void
    {
        $evil = ["1; DROP TABLE ork_mundane", "' OR '1'='1", "1 OR 1=1", str_repeat('A', 10240), "2025-01-01' --", "\0", "１２３"];
        foreach (['last_signin' => 'gte', 'total_signins' => 'gt', 'home_park' => 'is', 'last_class' => 'is', 'dues_paid' => 'is'] as $c => $o) {
            foreach ($evil as $v) {
                $r = $this->norm(['op' => 'AND', 'children' => [['c' => $c, 'o' => $o, 'v' => $v]]]);
                $this->assertFalse($r['ok'], "$c accepted: " . substr((string)$v, 0, 20));
            }
        }
    }

    public function testCaps(): void
    {
        // depth 7
        $t = $this->leaf('active', 'is', 'yes');
        for ($i = 0; $i < 7; $i++) {
            $t = ['op' => 'AND', 'children' => [$t]];
        }
        $this->assertFalse($this->norm($t)['ok']);

        // 41 leaves
        $leaves = [];
        for ($i = 0; $i < 41; $i++) {
            $leaves[] = $this->leaf('active', 'is', 'yes');
        }
        $this->assertFalse($this->norm(['op' => 'AND', 'children' => $leaves])['ok']);

        // 101-item IN list
        $this->assertFalse($this->norm(['op' => 'AND', 'children' => [
            $this->leaf('home_park', 'in', range(1, 101)),
        ]])['ok']);
    }

    public function testBadOpAndShape(): void
    {
        $this->assertFalse($this->norm(['op' => 'XOR', 'children' => []])['ok']);
        $this->assertFalse($this->norm(['children' => 'x'])['ok']);
    }

    private function sql(array $tree, array $ctx = ['duesScope' => 'd.kingdom_id = 1']): string
    {
        $n = $this->norm($tree);
        $this->assertTrue($n['ok'], $n['error'] ?? '');
        return $this->pe->CompileTree($n['tree'], $ctx);
    }

    public function testEmptyTreeCompilesToTautology(): void
    {
        $this->assertSame('1=1', $this->sql(['op' => 'AND', 'children' => []]));
    }

    public function testPrecedenceIsExplicit(): void
    {
        $s = $this->sql(['op' => 'AND', 'children' => [
            $this->leaf('active', 'is', 'yes'),
            ['op' => 'OR', 'children' => [
                $this->leaf('waivered', 'is', 'yes'),
                $this->leaf('suspended', 'is', 'no'),
            ]],
        ]]);
        $this->assertSame('((m.active = 1) AND ((m.waivered = 1) OR (m.suspended = 0)))', $s);
    }

    public function testEmptyChildGroupIsDroppedFromOrCompiled(): void
    {
        $s = $this->sql(['op' => 'OR', 'children' => [
            $this->leaf('active', 'is', 'yes'),
            ['op' => 'AND', 'children' => []],
        ]]);
        $this->assertSame('((m.active = 1))', $s);
    }

    public function testNullSemanticsForNegatedComparisons(): void
    {
        $s = $this->sql(['op' => 'AND', 'children' => [$this->leaf('last_class', 'is_not', 7)]]);
        $this->assertStringContainsString('<> 7', $s);
        $this->assertStringNotContainsString('COALESCE((SELECT a.class_id', $s); // no NULL-coalescing: NULL = no match
    }

    public function testBetweenAndInRender(): void
    {
        $s = $this->sql(['op' => 'AND', 'children' => [
            $this->leaf('last_signin', 'between', ['2025-01-01', '2025-06-30']),
            $this->leaf('home_park', 'in', [3, 4, 5]),
        ]]);
        $this->assertStringContainsString("BETWEEN '2025-01-01' AND '2025-06-30'", $s);
        $this->assertStringContainsString('m.park_id IN (3,4,5)', $s);
    }

    public function testNMonthsParamAndNotExists(): void
    {
        $s = $this->sql(['op' => 'AND', 'children' => [
            $this->leaf('signins_last_n_months', 'gt', 5, 6),
            $this->leaf('classes_last_n_months', 'not_in', [7], 3),
        ]]);
        $this->assertStringContainsString('INTERVAL 6 MONTH', $s);
        $this->assertStringContainsString('> 5', $s);
        $this->assertStringContainsString('NOT EXISTS', $s);
        $this->assertStringContainsString('INTERVAL 3 MONTH', $s);
    }

    public function testPeerageOperands(): void
    {
        $any  = $this->sql(['op' => 'AND', 'children' => [$this->leaf('knighthood', 'has_any', [17, 20])]]);
        $all  = $this->sql(['op' => 'AND', 'children' => [$this->leaf('knighthood', 'has_all', [17, 20])]]);
        $none = $this->sql(['op' => 'AND', 'children' => [$this->leaf('knighthood', 'has_none', [17])]]);
        $this->assertStringContainsString('aw.award_id IN (17,20)', $any);
        $this->assertStringContainsString('COUNT(DISTINCT aw.award_id)', $all);
        $this->assertStringContainsString(') = 2', $all);
        $this->assertStringContainsString('NOT EXISTS', $none);
        $this->assertStringContainsString('w.revoked = 0', $any);
        $this->assertStringContainsString('stripped_from', $any);
    }

    public function testDuesReadTheDuesLedgerInScope(): void
    {
        $s = $this->sql(['op' => 'AND', 'children' => [$this->leaf('dues_paid', 'is', 'yes')]], ['duesScope' => 'd.park_id = 42']);
        $this->assertStringContainsString(DB_PREFIX . 'dues d', $s);
        $this->assertStringContainsString('d.park_id = 42', $s);
        $this->assertStringContainsString('d.revoked = 0', $s);
        $this->assertStringContainsString('d.dues_for_life = 1', $s);
        $this->assertStringNotContainsString('split', $s);

        // No scope in ctx: dues never match (fail closed).
        $closed = $this->sql(['op' => 'AND', 'children' => [$this->leaf('dues_paid', 'is', 'yes')]], []);
        $this->assertStringContainsString('1=0', $closed);
    }

    public function testCompiledSqlNeverContainsUserText(): void
    {
        $n = $this->norm(['op' => 'AND', 'children' => [$this->leaf('last_signin', 'gte', '2025-01-01')]]);
        $s = $this->pe->CompileTree($n['tree'], ['duesScope' => '1=1']);
        $this->assertDoesNotMatchRegularExpression('/DROP|--|;/', $s);
    }

    public function testEveryColumnCompiles(): void
    {
        $cols = array_keys($this->pe->Registry()['columns']);
        foreach ($cols as $c) {
            $expr = $this->pe->ColumnSelectSql($c, ['duesScope' => 'd.kingdom_id = 1']);
            $this->assertNotSame('', $expr, $c);
        }
    }

    public function testEveryCriterionHasASqlBuilder(): void
    {
        foreach ($this->pe->Registry()['criteria'] as $id => $def) {
            $this->assertIsCallable($def['sql'], $id);
        }
    }

    public function testHasAwardSetOperands(): void
    {
        $cases = [
            ['is', 17, 'aw.award_id IN (17)', true],
            ['is_not', 17, 'aw.award_id IN (17)', false],
            ['in', [17, 20], 'aw.award_id IN (17,20)', true],
            ['not_in', [17, 20], 'aw.award_id IN (17,20)', false],
        ];
        foreach ($cases as [$o, $v, $needle, $exists]) {
            $s = $this->sql(['op' => 'AND', 'children' => [$this->leaf('has_award', $o, $v)]]);
            $this->assertStringContainsString($needle, $s, $o);
            $this->assertStringNotContainsString('1=1', $s, $o);
            $this->assertSame($exists, strpos($s, 'NOT EXISTS') === false, $o);
            $this->assertStringContainsString('EXISTS', $s, $o);
        }
    }

    public function testPeerageIsYesNoRegression(): void
    {
        $yes = $this->sql(['op' => 'AND', 'children' => [$this->leaf('knighthood', 'is', 'yes')]]);
        $no  = $this->sql(['op' => 'AND', 'children' => [$this->leaf('knighthood', 'is', 'no')]]);
        $this->assertStringContainsString("aw.peerage IN ('Knight')", $yes);
        $this->assertStringNotContainsString('NOT EXISTS', $yes);
        $this->assertStringContainsString('EXISTS', $yes);
        $this->assertStringContainsString("aw.peerage IN ('Knight')", $no);
        $this->assertStringContainsString('NOT EXISTS', $no);
    }

    public function testDecodeLinkRoundTrip(): void
    {
        $tree = ['op' => 'AND', 'children' => [$this->leaf('last_signin', 'gte', '2025-01-01')]];
        $q = PopulationExplorer::EncodeLink(['tree' => $tree, 'columns' => ['persona', 'home_park']]);
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $q);
        $d = $this->pe->DecodeLink($q, $this->known);
        $this->assertTrue($d['ok'], (string) $d['error']);
        $this->assertNull($d['error']);
        $this->assertSame('AND', $d['state']['tree']['op']);
        $this->assertSame('last_signin', $d['state']['tree']['children'][0]['c']);
        $this->assertContains('persona', $d['state']['columns']);
    }

    public function testDecodeLinkRejectsOversize(): void
    {
        $q = str_repeat('A', 8193);
        $d = PopulationExplorer::DecodeLink($q);
        $this->assertFalse($d['ok']);
        $this->assertNull($d['state']);
        $this->assertNotEmpty($d['error']);
    }

    public function testDecodeLinkRejectsGarbage(): void
    {
        foreach (['', '!!!not base64!!!', PopulationExplorer::EncodeLink(['x' => 1]) . '*'] as $q) {
            $d = PopulationExplorer::DecodeLink($q, $this->known);
            $this->assertFalse($d['ok'], $q);
            $this->assertNull($d['state']);
        }
        // valid base64 but not JSON
        $d = PopulationExplorer::DecodeLink(rtrim(strtr(base64_encode('not json {'), '+/', '-_'), '='), $this->known);
        $this->assertFalse($d['ok']);
        // valid JSON but not an object with a tree
        $d = PopulationExplorer::DecodeLink(rtrim(strtr(base64_encode('"str"'), '+/', '-_'), '='), $this->known);
        $this->assertFalse($d['ok']);
    }

    public function testDecodeLinkRevalidatesTree(): void
    {
        $bad = ['op' => 'AND', 'children' => [$this->leaf('no_such_criterion', 'is', 'yes')]];
        $d = PopulationExplorer::DecodeLink(PopulationExplorer::EncodeLink(['tree' => $bad, 'columns' => ['persona']]), $this->known);
        $this->assertFalse($d['ok']);
        $this->assertNull($d['state']);
        $this->assertNotEmpty($d['error']);
    }

    public function testDecodeLinkFiltersUnknownColumns(): void
    {
        $tree = ['op' => 'AND', 'children' => []];
        $d = PopulationExplorer::DecodeLink(PopulationExplorer::EncodeLink(['tree' => $tree, 'columns' => ['persona', 'drop table', 5, 'home_park']]), $this->known);
        $this->assertTrue($d['ok'], (string) $d['error']);
        $this->assertNotContains('drop table', $d['state']['columns']);
        $this->assertContains('home_park', $d['state']['columns']);
    }
}
