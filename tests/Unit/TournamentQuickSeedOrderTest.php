<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Quick Bracket seat rule (spec 2026-10-02): seeded entrants keep their seat, seed-0
 * entrants (added the long way) take the lowest empty seats by participant id, a duplicate
 * seed loses its claim to the lower participant id, then gaps close.
 */
final class TournamentQuickSeedOrderTest extends TestCase
{
    private function rows(array $pairs): array
    {
        return array_map(fn ($p) => ['ParticipantId' => $p[0], 'Seed' => $p[1]], $pairs);
    }

    public function testGapsClose(): void
    {
        $this->assertSame([10, 30, 20], Tournament::quick_seed_order($this->rows([[10, 1], [20, 5], [30, 3]])));
    }

    public function testUnseededFillLowestEmptySeat(): void
    {
        // Seeds 1 and 3 taken; unseeded 40 takes seat 2, unseeded 50 takes seat 4.
        $this->assertSame([10, 40, 30, 50], Tournament::quick_seed_order($this->rows([[50, 0], [30, 3], [40, 0], [10, 1]])));
    }

    public function testDuplicateSeedLowerIdKeepsSeat(): void
    {
        $this->assertSame([10, 20], Tournament::quick_seed_order($this->rows([[20, 1], [10, 1]])));
    }

    public function testEmpty(): void
    {
        $this->assertSame([], Tournament::quick_seed_order([]));
    }
}
