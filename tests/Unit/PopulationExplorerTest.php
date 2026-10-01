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
        // Compilation tests are not about access: normalize as an officer of the scope.
        $n = $this->pe->NormalizeTree($tree, ['officer' => true] + $this->known);
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
        $this->assertStringContainsString('COALESCE(NULLIF(w.award_id, 0), ka.award_id) IN (17,20)', $any);
        $this->assertStringContainsString('wa.alias_award_id IN (17,20)', $any, 'alias-aware');
        $this->assertStringContainsString('ka.award_id) IN (17)', $all);
        $this->assertStringContainsString('ka.award_id) IN (20)', $all);
        $this->assertStringStartsWith('((NOT ', $none);
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

    public function testAwardDateAnyOperandsAreRangesOnly(): void
    {
        $def = $this->pe->Registry()['criteria']['award_date_any'];
        $this->assertSame(['gt', 'gte', 'lt', 'lte', 'between'], $def['operands']);
        foreach (['eq', 'ne'] as $o) {
            $r = $this->norm(['op' => 'AND', 'children' => [$this->leaf('award_date_any', $o, '2025-01-01')]]);
            $this->assertFalse($r['ok'], $o);
            $this->assertStringContainsStringIgnoringCase('operand', $r['error']);
        }
        $this->assertTrue($this->norm(['op' => 'AND', 'children' => [$this->leaf('award_date_any', 'gt', '2025-01-01')]])['ok']);
    }

    public function testNullableFlagMarksCriteriaWhoseValueCanBeMissing(): void
    {
        $nullable = [];
        foreach ($this->pe->Registry()['criteria'] as $id => $def) {
            if ($def['nullable']) {
                $nullable[] = $id;
            }
        }
        sort($nullable);
        $this->assertSame(['dues_through', 'last_class', 'last_signin', 'last_signin_days_ago', 'last_signin_park', 'player_since'], $nullable);
    }

    public function testLesserPeerageLabelNamesItsThreeOrders(): void
    {
        $crit = $this->pe->Registry()['criteria'];
        $this->assertArrayHasKey('lesser_peerage', $crit);
        $this->assertSame('Squire / Page / Man-At-Arms held', $crit['lesser_peerage']['label']);
        $this->assertSame(['Squire', 'Page', 'Man-At-Arms'], $crit['lesser_peerage']['peerage']);
    }

    public function testColumnsCarryNoSqlClosuresAndPeerageColumnsUsePeerageIn(): void
    {
        foreach ($this->pe->Registry()['columns'] as $id => $def) {
            $this->assertArrayNotHasKey('sql', $def, $id);
        }
        $this->assertStringContainsString("aw.peerage IN ('Knight')", $this->pe->ColumnSelectSql('knighthoods', []));
        $this->assertStringContainsString("aw.peerage IN ('Master')", $this->pe->ColumnSelectSql('masterhoods', []));
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
            ['is', 17, 'ka.award_id) IN (17)', true],
            ['is_not', 17, 'ka.award_id) IN (17)', false],
            ['in', [17, 20], 'ka.award_id) IN (17,20)', true],
            ['not_in', [17, 20], 'ka.award_id) IN (17,20)', false],
        ];
        foreach ($cases as [$o, $v, $needle, $holds]) {
            $s = $this->sql(['op' => 'AND', 'children' => [$this->leaf('has_award', $o, $v)]]);
            $this->assertStringContainsString($needle, $s, $o);
            $this->assertStringNotContainsString('1=1', $s, $o);
            $this->assertSame($holds, strpos($s, '((NOT ') !== 0, $o);
            $this->assertStringContainsString('LIMIT 1) IS NOT NULL', $s, $o);
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

    private function compileLeaf(string $c, string $o, $v, ?int $p = null): string
    {
        $n = $this->norm(['op' => 'AND', 'children' => [$this->leaf($c, $o, $v, $p)]]);
        $this->assertTrue($n['ok'], (string) ($n['error'] ?? ''));

        return $this->pe->CompileTree($n['tree'], ['duesScope' => '1=1']);
    }

    public function testPlayerSinceFloorsAt1988AndHasNoOverride(): void
    {
        $col = $this->pe->ColumnSelectSql('player_since', []);
        $this->assertStringContainsString("a.date >= '1988-01-01'", $col);
        $this->assertStringNotContainsString('player_since_override', $col);
        $crit = $this->compileLeaf('player_since', 'lt', '2000-01-01');
        $this->assertStringContainsString("a.date >= '1988-01-01'", $crit);
        $this->assertStringNotContainsString('player_since_override', $crit);
        $this->assertFalse(method_exists(PopulationExplorer::class, '_hasPlayerSinceOverride'), 'no schema probe');
    }

    public function testLastSigninParkTreatsParkZeroAsNoParkWithIdTieBreak(): void
    {
        foreach ([$this->compileLeaf('last_signin_park', 'not_in', [5]), $this->pe->ColumnSelectSql('last_signin_park', [])] as $sql) {
            $this->assertStringContainsString('NULLIF(a.park_id, 0)', $sql);
            $this->assertStringContainsString('ORDER BY a.date DESC, a.attendance_id DESC LIMIT 1', $sql);
        }
    }

    public function testAwardDateAnyIgnoresAwardsDatedBefore1980(): void
    {
        foreach (['lt', 'gte'] as $o) {
            $this->assertStringContainsString("w.date >= '1980-01-01'", $this->compileLeaf('award_date_any', $o, '2000-01-01'));
        }
    }

    /**
     * Every criterion that offers a negated operand says what happens to players
     * with no value: nullable ones are not matched, set-style ones are. The note
     * travels in the registry so the UI never guesses.
     */
    public function testNegatedOperandNotesAreSpecificAndAccurate(): void
    {
        $neg = ['ne', 'is_not', 'not_in', 'has_none'];
        $crit = $this->pe->Registry()['criteria'];
        foreach ($crit as $id => $def) {
            $hasNeg = array_intersect($neg, $def['operands']) !== [];
            $setStyle = $def['type'] === 'peerage_set' || in_array($id, ['has_award', 'classes_last_n_months'], true);
            if ($id === 'last_signin_days_ago') {
                continue; // its "not matched" note applies to every operand: see testLastSigninDaysAgoDefinition
            }
            if ($hasNeg && ($def['nullable'] || $setStyle)) {
                $this->assertNotSame('', (string) ($def['neg_note'] ?? ''), "$id needs a negated-operand note");
            } else {
                $this->assertArrayNotHasKey('neg_note', $def, "$id: a count or plain field has no missing value");
            }
        }
        $this->assertStringContainsString('no sign-ins in the last N months', $crit['classes_last_n_months']['neg_note']);
        $this->assertStringContainsString('also match', $crit['classes_last_n_months']['neg_note']);
        $this->assertStringContainsString('event', $crit['last_signin_park']['neg_note']);
        $this->assertStringContainsString('not matched', $crit['last_signin_park']['neg_note']);
        foreach (['knighthood', 'masterhood', 'paragon', 'lesser_peerage', 'has_award'] as $id) {
            $this->assertStringContainsString('also match', $crit[$id]['neg_note'], $id);
        }
        foreach (['last_signin', 'player_since', 'last_class', 'dues_through'] as $id) {
            $this->assertStringContainsString('not matched', $crit[$id]['neg_note'], $id);
        }
        $this->assertStringContainsString('1988', $crit['player_since']['note']);
        $this->assertStringContainsString('1980', $crit['award_date_any']['note']);
    }

    // ------------------------------------------------------------ restricted criteria (spec §3.3)

    /** @return array{0:array,1:array} [non-officer known, officer known] */
    private function viewers(): array
    {
        return [['officer' => false] + $this->known, ['officer' => true] + $this->known];
    }

    public function testSuspendedAndBannedAreTheOnlyRestrictedCriteria(): void
    {
        $restricted = [];
        foreach ($this->pe->Registry()['criteria'] as $id => $def) {
            if (!empty($def['restricted'])) {
                $restricted[] = $id;
            }
        }
        sort($restricted);
        $this->assertSame(['banned', 'suspended'], $restricted);
        $this->assertTrue($this->pe->Registry()['criteria']['suspended']['restricted']);
    }

    public function testRestrictedCriteriaAreRejectedForNonOfficersWithRulePath(): void
    {
        [$plain, $officer] = $this->viewers();
        foreach (['suspended', 'banned'] as $c) {
            $tree = ['op' => 'AND', 'children' => [
                $this->leaf('active', 'is', 'yes'),
                ['op' => 'OR', 'children' => [$this->leaf($c, 'is', 'yes')]],
            ]];
            $r = $this->pe->NormalizeTree($tree, $plain);
            $this->assertFalse($r['ok'], $c);
            $this->assertSame(PopulationExplorer::RESTRICTED_MESSAGE, $r['error']);
            $this->assertSame('This filter requires officer access for this kingdom or park.', $r['error']);
            $this->assertSame([1, 0], $r['path']);

            $ok = $this->pe->NormalizeTree($tree, $officer);
            $this->assertTrue($ok['ok'], $c . ': ' . ($ok['error'] ?? ''));
            $this->assertSame(1, $ok['tree']['children'][1]['children'][0]['v']);
        }
    }

    /**
     * Ordering guard: the officer check runs before operand and value validation, so a
     * non-officer gets the same message for any operand or value, and nothing echoes the
     * restricted criterion's label, operand or value.
     */
    public function testRestrictedCheckRunsBeforeOperandAndValueValidation(): void
    {
        [$plain, $officer] = $this->viewers();
        $bad = [
            ['suspended', 'gte', 'yes'],
            ['banned', 'between', ['x', 'y']],
            ['suspended', 'is', 'maybe'],
            ['banned', 'is', null],
            ['suspended', 42, []],
        ];
        foreach ($bad as $i => [$c, $o, $v]) {
            $tree = ['op' => 'OR', 'children' => [$this->leaf('active', 'is', 'yes'), ['c' => $c, 'o' => $o, 'v' => $v]]];
            $r = $this->pe->NormalizeTree($tree, $plain);
            $this->assertFalse($r['ok'], "case $i");
            $this->assertSame(PopulationExplorer::RESTRICTED_MESSAGE, $r['error'], "case $i");
            $this->assertSame([1], $r['path'], "case $i");

            // An officer reaches the ordinary validation, which may name the criterion.
            $o2 = $this->pe->NormalizeTree($tree, $officer);
            $this->assertFalse($o2['ok'], "officer case $i");
            $this->assertNotSame(PopulationExplorer::RESTRICTED_MESSAGE, $o2['error'], "officer case $i");
        }
    }

    public function testRestrictedCriteriaFailClosedWhenOfficerStatusIsUnknown(): void
    {
        $tree = ['op' => 'AND', 'children' => [$this->leaf('suspended', 'is', 'no')]];
        foreach ([$this->known, ['officer' => 1] + $this->known, ['officer' => 'yes'] + $this->known, ['officer' => null] + $this->known] as $i => $known) {
            $r = $this->pe->NormalizeTree($tree, $known);
            $this->assertFalse($r['ok'], "case $i");
            $this->assertSame(PopulationExplorer::RESTRICTED_MESSAGE, $r['error']);
        }
        // An unrestricted criterion is unaffected.
        $this->assertTrue($this->pe->NormalizeTree(['op' => 'AND', 'children' => [$this->leaf('active', 'is', 'no')]], $this->known)['ok']);
    }

    public function testRestrictedCriteriaInShareLinksAreRejectedForNonOfficers(): void
    {
        [$plain, $officer] = $this->viewers();
        $q = PopulationExplorer::EncodeLink(['tree' => ['op' => 'AND', 'children' => [$this->leaf('banned', 'is', 'yes')]], 'columns' => ['persona']]);
        $d = PopulationExplorer::DecodeLink($q, $plain);
        $this->assertFalse($d['ok']);
        $this->assertStringContainsString(PopulationExplorer::RESTRICTED_MESSAGE, (string) $d['error']);
        $this->assertTrue(PopulationExplorer::DecodeLink($q, $officer)['ok']);
    }

    public function testPublicCriteriaHideRestrictedFromNonOfficers(): void
    {
        [$plain, $officer] = $this->viewers();
        $hidden = $this->pe->PublicCriteria($plain);
        $this->assertArrayNotHasKey('suspended', $hidden);
        $this->assertArrayNotHasKey('banned', $hidden);
        $this->assertArrayHasKey('active', $hidden);
        $this->assertArrayNotHasKey('suspended', $this->pe->PublicCriteria($this->known), 'fail closed');

        $shown = $this->pe->PublicCriteria($officer);
        $this->assertArrayHasKey('suspended', $shown);
        $this->assertArrayHasKey('banned', $shown);
        foreach ($shown as $id => $def) {
            $this->assertArrayNotHasKey('sql', $def, $id);
        }
        $this->assertSame(array_keys($this->pe->Registry()['criteria']), array_keys($shown));
    }

    // ------------------------------------------------------------ ladder award ranks (spec §4)

    /** The scope's ladders as LoadLadders() returns them. */
    private function ladders(): array
    {
        return [
            'ladder_k7070' => ['label' => 'Order of the Archer', 'kind' => 'kingdomaward', 'id' => 7070],
            'ladder_a21'   => ['label' => 'Order of the Rose', 'kind' => 'award', 'id' => 21],
        ];
    }

    private function withLadders(bool $officer = false): array
    {
        return ['ladders' => $this->ladders(), 'officer' => $officer] + $this->known;
    }

    private function ladderNorm($v, string $o = 'gte', string $c = 'ladder_a21'): array
    {
        return $this->pe->NormalizeTree(['op' => 'AND', 'children' => [$this->leaf($c, $o, $v)]], $this->withLadders());
    }

    public function testLadderCriteriaAreGeneratedFromTheScopesLadders(): void
    {
        $this->assertSame([], preg_grep('/^ladder_/', array_keys($this->pe->Registry()['criteria'])), 'the static registry is scope-free');

        $crit = $this->pe->PublicCriteria($this->withLadders());
        $ids = array_keys($crit);
        $this->assertSame(['ladder_k7070', 'ladder_a21'], array_slice($ids, -2), 'appended in LoadLadders order (by label)');
        foreach (['ladder_a21' => 'Order of the Rose', 'ladder_k7070' => 'Order of the Archer'] as $id => $label) {
            $def = $crit[$id];
            $this->assertSame($label, $def['label']);
            $this->assertSame('Ladder Award Ranks', $def['group']);
            $this->assertSame('number', $def['type']);
            $this->assertSame(['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'between'], $def['operands']);
            $this->assertSame(0, $def['min']);
            $this->assertFalse($def['nullable'], 'rank is 0, never NULL');
            $this->assertArrayNotHasKey('neg_note', $def, 'no "not matched" note: rank-0 players do match');
            $this->assertSame('Players with no award in this ladder count as rank 0.', $def['note']);
            $this->assertArrayNotHasKey('sql', $def);
            $this->assertArrayNotHasKey('ladder', $def, 'SQL ids stay server-side');
        }
    }

    public function testUnknownOrOutOfScopeLadderIsRejected(): void
    {
        foreach (['ladder_k999', 'ladder_a31', 'ladder_a22'] as $c) {
            $r = $this->ladderNorm(1, 'gte', $c);
            $this->assertFalse($r['ok'], $c);
            $this->assertSame(PopulationExplorer::LADDER_UNAVAILABLE_MESSAGE, $r['error'], $c);
            $this->assertSame([0], $r['path']);
        }
        $this->assertSame("This ladder isn't available for this kingdom or park.", PopulationExplorer::LADDER_UNAVAILABLE_MESSAGE);
        // No ladders loaded (no scope): every ladder id is unavailable.
        $r = $this->norm(['op' => 'AND', 'children' => [$this->leaf('ladder_a21', 'gte', 1)]]);
        $this->assertSame(PopulationExplorer::LADDER_UNAVAILABLE_MESSAGE, $r['error']);
        // Not a ladder id at all.
        $this->assertSame('Unknown criterion', $this->ladderNorm(1, 'gte', 'ladder_x1')['error']);
        $this->assertTrue($this->ladderNorm(1, 'gte', 'ladder_k7070')['ok']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badLadderValues')]
    public function testLadderValuesMustBeWholeNumbersOfZeroOrMore(string $o, $v, string $needle): void
    {
        $r = $this->ladderNorm($v, $o);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsStringIgnoringCase($needle, $r['error']);
        $this->assertSame([0], $r['path']);
    }

    public static function badLadderValues(): array
    {
        return [
            'negative'            => ['gte', -1, '0 or more'],
            'negative string'     => ['eq', '-3', '0 or more'],
            'decimal'             => ['gt', '2.5', '0 or more'],
            'text'                => ['lt', 'abc', '0 or more'],
            'empty'               => ['eq', '', '0 or more'],
            'array for scalar'    => ['eq', [3], '0 or more'],
            'between negative'    => ['between', [-1, 3], '0 or more'],
            'between single'      => ['between', [3], 'two values'],
        ];
    }

    public function testValidLadderValuesNormalizeToInts(): void
    {
        $this->assertSame(0, $this->ladderNorm('0', 'eq')['tree']['children'][0]['v']);
        $this->assertSame(7, $this->ladderNorm(7, 'ne')['tree']['children'][0]['v']);
        $this->assertSame([0, 3], $this->ladderNorm(['0', 3], 'between')['tree']['children'][0]['v']);
    }

    public function testLadderSqlIsTheHeldRankExpressionWithIntIdsOnly(): void
    {
        $n = $this->pe->NormalizeTree(['op' => 'AND', 'children' => [
            $this->leaf('ladder_a21', 'gte', 2),
            $this->leaf('ladder_k7070', 'ne', 0),
        ]], $this->withLadders());
        $this->assertTrue($n['ok'], $n['error'] ?? '');
        $sql = $this->pe->CompileTree($n['tree'], ['ladders' => $this->ladders()]);

        $this->assertSame(3, substr_count($sql, 'GREATEST(COALESCE(MAX(w.rank), 0), COUNT(*))'), 'global: aliased + probe branches; kingdom-only: one');
        $this->assertStringContainsString('aw.award_id = 21', $sql, 'global ladder: resolved (alias-aware) award id');
        $this->assertStringContainsString('COALESCE(NULLIF(w.alias_award_id, 0)', $sql);
        $this->assertStringContainsString('w.kingdomaward_id = 7070', $sql, 'kingdom-only ladder');
        $this->assertSame(3, substr_count($sql, 'w.revoked = 0 AND COALESCE(w.stripped_from, 0) = 0'), 'held awards only');
        $this->assertStringContainsString('wa.revoked = 0 AND COALESCE(wa.stripped_from, 0) = 0', $sql, 'aliased rows: held only');
        $this->assertStringContainsString(') >= 2', $sql);
        $this->assertStringContainsString(') <> 0', $sql);
        // Only the registry's ints: no other digits than the ids, the values and the SQL's own 0s.
        preg_match_all('/\d+/', $sql, $m);
        $this->assertSame([], array_values(array_diff(array_unique($m[0]), ['0', '2', '21', '7070'])));
    }

    public function testLadderLeafCannotCompileWithoutTheScopesLadders(): void
    {
        $n = $this->pe->NormalizeTree(['op' => 'AND', 'children' => [$this->leaf('ladder_a21', 'gte', 2)]], $this->withLadders());
        $this->assertTrue($n['ok']);
        $this->expectException(InvalidArgumentException::class);
        $this->pe->CompileTree($n['tree'], ['ladders' => []]);
    }

    public function testMalformedLadderEntriesAreNeverOffered(): void
    {
        $known = ['ladders' => [
            'ladder_a21) OR (1=1' => ['label' => 'x', 'kind' => 'award', 'id' => 21],
            'ladder_a22'          => ['label' => 'Kind mismatch', 'kind' => 'kingdomaward', 'id' => 22],
            'ladder_a23'          => ['label' => 'Id mismatch', 'kind' => 'award', 'id' => 24],
            'ladder_a25'          => ['label' => 'Id not an int', 'kind' => 'award', 'id' => '25 OR 1=1'],
            'ladder_k0'           => ['label' => 'Zero', 'kind' => 'kingdomaward', 'id' => 0],
            'ladder_a26'          => ['label' => 'Fine', 'kind' => 'award', 'id' => 26],
        ]] + $this->known;
        $ladderIds = array_values(preg_grep('/^ladder_/', array_keys($this->pe->PublicCriteria($known))));
        $this->assertSame(['ladder_a26'], $ladderIds);
        $r = $this->pe->NormalizeTree(['op' => 'AND', 'children' => [$this->leaf('ladder_a25', 'gte', 1)]], $known);
        $this->assertSame(PopulationExplorer::LADDER_UNAVAILABLE_MESSAGE, $r['error']);
    }

    public function testLadderShareLinkRevalidatesAgainstTheScope(): void
    {
        $q = PopulationExplorer::EncodeLink(['tree' => ['op' => 'AND', 'children' => [$this->leaf('ladder_k7070', 'between', [1, 4])]], 'columns' => ['persona']]);
        $this->assertTrue(PopulationExplorer::DecodeLink($q, $this->withLadders())['ok']);
        $d = PopulationExplorer::DecodeLink($q, ['ladders' => []] + $this->known);
        $this->assertFalse($d['ok']);
        $this->assertStringContainsString(PopulationExplorer::LADDER_UNAVAILABLE_MESSAGE, (string) $d['error']);
    }
    // ------------------------------------------------------------ between in either order (spec §3.4)

    /** @return array<string, array{0:string, 1:array, 2:array, 3:string}> */
    public static function reversedBetweens(): array
    {
        return [
            'date'        => ['last_signin', ['2025-05-01', '2025-01-01'], ['2025-01-01', '2025-05-01'], "BETWEEN '2025-01-01' AND '2025-05-01'"],
            'number'      => ['total_signins', ['20', 3], [3, 20], 'BETWEEN 3 AND 20'],
            'ladder rank' => ['ladder_a21', [5, '2'], [2, 5], 'BETWEEN 2 AND 5'],
            'days ago'    => ['last_signin_days_ago', [200, 180], [180, 200], 'BETWEEN 180 AND 200'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reversedBetweens')]
    public function testReversedBetweenIsSwappedToAscending(string $c, array $in, array $sorted, string $sqlNeedle): void
    {
        $known = $this->withLadders(true);
        $n = $this->pe->NormalizeTree(['op' => 'AND', 'children' => [$this->leaf($c, 'between', $in)]], $known);
        $this->assertTrue($n['ok'], $n['error'] ?? '');
        $this->assertSame($sorted, $n['tree']['children'][0]['v']);
        $sql = $this->pe->CompileTree($n['tree'], ['ladders' => $this->ladders()]);
        $this->assertStringContainsString($sqlNeedle, $sql);

        // Equal values stay valid; an ascending pair is unchanged.
        foreach ([[$sorted[0], $sorted[0]], $sorted] as $pair) {
            $m = $this->pe->NormalizeTree(['op' => 'AND', 'children' => [$this->leaf($c, 'between', $pair)]], $known);
            $this->assertTrue($m['ok'], $m['error'] ?? '');
            $this->assertSame($pair, $m['tree']['children'][0]['v']);
        }
    }

    public function testReversedBetweenStillValidatesEachValue(): void
    {
        $bad = [
            ['last_signin', ['2025-05-01', '2025-02-30'], 'date'],
            ['total_signins', [9, 'x'], 'number'],
            ['ladder_a21', [5, -1], '0 or more'],
            ['last_signin', ['2025-05-01'], 'two values'],
            ['total_signins', [9, 3, 1], 'two values'],
        ];
        foreach ($bad as [$c, $v, $needle]) {
            $r = $this->pe->NormalizeTree(['op' => 'AND', 'children' => [$this->leaf($c, 'between', $v)]], $this->withLadders());
            $this->assertFalse($r['ok'], $c . ' ' . json_encode($v));
            $this->assertStringContainsStringIgnoringCase($needle, $r['error']);
            $this->assertSame([0], $r['path']);
        }
    }

    public function testShareLinkWithReversedBetweenDecodesAscending(): void
    {
        $tree = ['op' => 'AND', 'children' => [
            $this->leaf('player_since', 'between', ['2020-12-31', '2001-01-01']),
            $this->leaf('award_count', 'between', [10, 1]),
        ]];
        $d = PopulationExplorer::DecodeLink(PopulationExplorer::EncodeLink(['tree' => $tree, 'columns' => ['persona']]), $this->known);
        $this->assertTrue($d['ok'], (string) $d['error']);
        $this->assertSame(['2001-01-01', '2020-12-31'], $d['state']['tree']['children'][0]['v']);
        $this->assertSame([1, 10], $d['state']['tree']['children'][1]['v']);
    }

    // ------------------------------------------------------------ last sign-in date / days ago (spec §3.4, §4)

    public function testLastSigninCriterionAndColumnAreBothLabelledAsADate(): void
    {
        $reg = $this->pe->Registry();
        $this->assertSame('Last sign-in date', $reg['criteria']['last_signin']['label']);
        $this->assertSame('date', $reg['criteria']['last_signin']['type']);
        $this->assertSame('Last sign-in date', $reg['columns']['last_signin']['label']);
    }

    public function testLastSigninDaysAgoDefinition(): void
    {
        $crit = $this->pe->Registry()['criteria'];
        $ids = array_keys($crit);
        $this->assertSame('last_signin_days_ago', $ids[array_search('last_signin', $ids, true) + 1], 'right after Last sign-in date');
        $def = $crit['last_signin_days_ago'];
        $this->assertSame('Last sign-in days ago', $def['label']);
        $this->assertSame('Activity', $def['group']);
        $this->assertSame('number', $def['type']);
        $this->assertSame(['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'between'], $def['operands']);
        $this->assertFalse($def['param']);
        $this->assertSame(0, $def['min']);
        $this->assertSame(PopulationExplorer::MAX_DAYS_AGO, $def['max']);
        $this->assertSame(36500, PopulationExplorer::MAX_DAYS_AGO);
        $this->assertTrue($def['nullable'], 'never signed in = NULL');
        // The note is on every operand (not only the negated ones): no operand matches a player who never signed in.
        $this->assertSame('Players who have never signed in are not matched. Use Total sign-ins = 0 to find them.', $def['note']);
        $this->assertArrayNotHasKey('neg_note', $def, 'the note already says it; a neg_note would repeat it on ≠');
        $this->assertArrayHasKey('last_signin_days_ago', $this->pe->PublicCriteria($this->known));
    }

    /** @return array<string, array{0:string, 1:mixed}> */
    public static function badDaysAgo(): array
    {
        return [
            'negative'        => ['gte', -1],
            'negative string' => ['eq', '-3'],
            'decimal'         => ['gt', '2.5'],
            'text'            => ['lt', 'abc'],
            'empty'           => ['eq', ''],
            'too large'       => ['gt', 36501],
            'huge string'     => ['gt', '9999999999'],
            'array for scalar' => ['eq', [3]],
            'between too big' => ['between', [36501, 5]],
            'between negative' => ['between', [10, -1]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badDaysAgo')]
    public function testLastSigninDaysAgoRejectsValuesOutsideZeroTo36500($o, $v): void
    {
        $r = $this->norm(['op' => 'AND', 'children' => [$this->leaf('last_signin_days_ago', $o, $v)]]);
        $this->assertFalse($r['ok']);
        $this->assertSame('Value must be a whole number from 0 to 36500', $r['error']);
        $this->assertSame([0], $r['path']);
    }

    public function testLastSigninDaysAgoAcceptsTheEnds(): void
    {
        foreach ([['eq', '0', 0], ['lte', 36500, 36500], ['between', ['36500', 0], [0, 36500]]] as [$o, $v, $want]) {
            $r = $this->norm(['op' => 'AND', 'children' => [$this->leaf('last_signin_days_ago', $o, $v)]]);
            $this->assertTrue($r['ok'], $r['error'] ?? '');
            $this->assertSame($want, $r['tree']['children'][0]['v']);
        }
    }

    public function testLastSigninDaysAgoIsDatediffOverTheLastSigninExpression(): void
    {
        $last = $this->pe->ColumnSelectSql('last_signin', []);
        $s = $this->sql(['op' => 'AND', 'children' => [$this->leaf('last_signin_days_ago', 'gt', 180)]]);
        $this->assertSame('((DATEDIFF(CURDATE(), ' . $last . ') > 180))', $s);
        // The same expression the Last sign-in date criterion compiles.
        $d = $this->sql(['op' => 'AND', 'children' => [$this->leaf('last_signin', 'lt', '2025-01-01')]]);
        $this->assertSame('((' . $last . " < '2025-01-01'))", $d);
        foreach (['ne' => '<> 5', 'lte' => '<= 5', 'eq' => '= 5'] as $o => $tail) {
            $this->assertStringEndsWith($tail . '))', $this->sql(['op' => 'AND', 'children' => [$this->leaf('last_signin_days_ago', $o, 5)]]));
        }
    }

    // ---------------------------------------------------------------- held-award probes (performance)
    // Shape only: the integration tests and the data-verification oracles pin the results.

    private function peerageCtx(): array
    {
        return ['duesScope' => '1=1', 'peerageIds' => $this->known['peerage']];
    }

    public function testHasAwardMatchesTheResolvedIdWithoutJoiningTheAwardTable(): void
    {
        $s = $this->sql(['op' => 'AND', 'children' => [$this->leaf('has_award', 'in', [17, 20])]]);
        $this->assertStringNotContainsString(DB_PREFIX . 'award aw', $s, 'no join to ork_award');
        $this->assertStringContainsString('w.award_id IN (0,17,20)', $s, 'index pre-filter on (mundane_id, award_id)');
        $this->assertStringContainsString('COALESCE(w.alias_award_id, 0) = 0', $s);
        $this->assertStringContainsString('COALESCE(NULLIF(w.award_id, 0), ka.award_id) IN (17,20)', $s);
        $this->assertStringContainsString('m.mundane_id IN (SELECT wa.mundane_id FROM ' . DB_PREFIX . 'awards wa WHERE wa.alias_award_id IN (17,20)', $s, 'aliased rows: one uncorrelated lookup');
        $this->assertStringContainsString('LIMIT 1) IS NOT NULL', $s, 'a per-player probe, not an EXISTS MariaDB turns into a full scan');
        $this->assertStringNotContainsString('EXISTS', $s);
        $none = $this->sql(['op' => 'AND', 'children' => [$this->leaf('has_award', 'not_in', [17, 20])]]);
        $this->assertStringStartsWith('((NOT ((SELECT 1 ', $none);
    }

    public function testPeerageHasAllIsOneProbePerId(): void
    {
        $s = $this->sql(['op' => 'AND', 'children' => [$this->leaf('knighthood', 'has_all', [17, 20])]]);
        $this->assertSame(2, substr_count($s, 'LIMIT 1) IS NOT NULL'));
        $this->assertStringContainsString('w.award_id IN (0,17)', $s);
        $this->assertStringContainsString('w.award_id IN (0,20)', $s);
        $this->assertStringNotContainsString('COUNT(DISTINCT', $s);
        $this->assertStringNotContainsString(DB_PREFIX . 'award aw', $s);
    }

    public function testPeerageYesNoProbesThePeerageIdsWhenTheContextCarriesThem(): void
    {
        $yes = $this->sql(['op' => 'AND', 'children' => [$this->leaf('knighthood', 'is', 'yes')]], $this->peerageCtx());
        $this->assertStringContainsString('w.award_id IN (0,17,18,19,20)', $yes);
        $this->assertStringNotContainsString('aw.peerage', $yes);
        $no = $this->sql(['op' => 'AND', 'children' => [$this->leaf('knighthood', 'is', 'no')]], $this->peerageCtx());
        $this->assertStringStartsWith('((NOT ((SELECT 1 ', $no);
        // No award carries the peerage: nobody holds one.
        $this->assertSame('(((0=1)))', $this->sql(['op' => 'AND', 'children' => [$this->leaf('paragon', 'is', 'yes')]], $this->peerageCtx()));
        $this->assertSame('((NOT (0=1)))', $this->sql(['op' => 'AND', 'children' => [$this->leaf('lesser_peerage', 'is', 'no')]], $this->peerageCtx()));
    }

    public function testPeerageColumnsProbeTheIdsUnlessThePlayerHasAnAliasedRow(): void
    {
        $col = $this->pe->ColumnSelectSql('knighthoods', $this->peerageCtx());
        $this->assertStringStartsWith('CASE WHEN m.mundane_id IN (SELECT wa.mundane_id FROM ' . DB_PREFIX . 'awards wa WHERE wa.alias_award_id IN (17,18,19,20)', $col);
        $this->assertStringContainsString("aw.peerage IN ('Knight')", $col, 'aliased players: the exact alias-aware form');
        $this->assertStringContainsString('w.award_id IN (0,17,18,19,20)', $col, 'everyone else: the index probe');
        $this->assertSame('NULL', $this->pe->ColumnSelectSql('paragons', $this->peerageCtx()));
    }

    public function testGlobalLadderRankProbesTheIdUnlessThePlayerHasAnAliasedRow(): void
    {
        $n = $this->pe->NormalizeTree(['op' => 'AND', 'children' => [$this->leaf('ladder_a21', 'gte', 2)]], $this->withLadders());
        $sql = $this->pe->CompileTree($n['tree'], ['ladders' => $this->ladders()]);
        $this->assertStringContainsString('CASE WHEN m.mundane_id IN (SELECT wa.mundane_id FROM ' . DB_PREFIX . 'awards wa WHERE wa.alias_award_id IN (21)', $sql);
        $this->assertStringContainsString('w.award_id IN (0,21)', $sql);
        $this->assertSame(2, substr_count($sql, 'GREATEST(COALESCE(MAX(w.rank), 0), COUNT(*))'), 'both branches: the same rank rule');
    }

    public function testAwardDateAnyIsAProbeNotAnExists(): void
    {
        $s = $this->compileLeaf('award_date_any', 'gte', '2024-01-01');
        $this->assertStringContainsString('LIMIT 1) IS NOT NULL', $s);
        $this->assertStringNotContainsString('EXISTS', $s);
    }
}
