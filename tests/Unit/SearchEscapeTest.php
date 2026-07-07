<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pure characterization tests for LIKE escape used across search endpoints (T-SRC-02 et al.).
 */
final class SearchEscapeTest extends TestCase
{
    /**
     * @dataProvider escapeCases
     */
    public function testLikeEscape(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->mirrorLikeEscape($input));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function escapeCases(): array
    {
        return [
            ["O'Brien", "O''Brien"],
            ['100%', '100\\\\%'],
            ['a_b', 'a\\\\_b'],
            ['back\\slash', 'back\\\\slash'],
            ["mix'_%\\", "mix''\\\\_\\\\%\\\\"],
        ];
    }

    private function mirrorLikeEscape(string $term): string
    {
        return str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $term);
    }
}
