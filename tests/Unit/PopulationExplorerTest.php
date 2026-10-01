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
}
