<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Report::sqlRankRangePredicate — the Recs Manager's Rank header filter
 * (stops 1..10 = ladder ranks, 11 = "M" Master award, plus a non-ladder switch).
 */
final class ReportRankRangeFilterTest extends TestCase
{
    private const LAD = 'LADDER';
    private const MAS = '(MASTER)';

    private function pred(int $min, int $max, bool $nl): ?string
    {
        return Report::sqlRankRangePredicate($min, $max, $nl, self::LAD, self::MAS);
    }

    public function testDefaultSelectionFiltersNothing(): void
    {
        $this->assertNull($this->pred(1, Report::RANK_FILTER_MASTER, true));
    }

    public function testOutOfRangeInputsAreClampedToTheDefault(): void
    {
        $this->assertNull($this->pred(-5, 999, true));
    }

    public function testFullRangeWithoutNonLadderKeepsLaddersAndMastersOnly(): void
    {
        $this->assertSame(
            '((NOT (MASTER) AND LADDER = 1) OR (MASTER))',
            $this->pred(1, 11, false)
        );
    }

    public function testEightToTenIsAnOpenTopLadderBoundPlusNonLadder(): void
    {
        // 10 means "10 and above", so there is no upper bound.
        $this->assertSame(
            '((NOT (MASTER) AND LADDER = 1 AND COALESCE(recs.rank,0) >= 8) OR (NOT (MASTER) AND LADDER = 0))',
            $this->pred(8, 10, true)
        );
    }

    public function testEightToMasterAddsTheMasterBranch(): void
    {
        $this->assertSame(
            '((NOT (MASTER) AND LADDER = 1 AND COALESCE(recs.rank,0) >= 8) OR (MASTER))',
            $this->pred(8, 11, false)
        );
    }

    public function testMasterOnly(): void
    {
        $this->assertSame('((MASTER))', $this->pred(11, 11, false));
    }

    public function testLowRangeKeepsRankUnsetLaddersAndBoundsTheTop(): void
    {
        // No ">= 1" bound: a ladder rec with no rank yet (0) belongs to the bottom stop.
        $this->assertSame(
            '((NOT (MASTER) AND LADDER = 1 AND COALESCE(recs.rank,0) <= 3))',
            $this->pred(1, 3, false)
        );
    }

    public function testReversedBoundsAreSwapped(): void
    {
        $this->assertSame($this->pred(3, 7, true), $this->pred(7, 3, true));
    }
}
