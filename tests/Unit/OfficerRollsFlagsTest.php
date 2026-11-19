<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * OfficerPosition::flag_rolls_terms(): gaps and overlaps on one office's terms,
 * found against coverage (the furthest end reached so far, oldest to newest),
 * not by comparing each term with its neighbour.
 */
final class OfficerRollsFlagsTest extends TestCase
{
    private const WINTER = 0;
    private const SPRING = 1;
    private const SUMMER = 2;
    private const FALL = 3;

    private static function ord(int $year, int $season): int
    {
        return $year * 4 + $season;
    }

    private static function term(string $holder, ?int $start, ?int $end, array $over = []): array
    {
        return $over + [
            'OfficerHistoryId' => 0, 'MundaneId' => 0, 'Persona' => '', 'HolderName' => $holder,
            'IsCurrent' => false, 'Synthetic' => false,
            'StartOrdinal' => $start, 'EndOrdinal' => $end, 'FlagAfter' => null,
        ];
    }

    private static function gap(int $from, int $to): array
    {
        $span = OfficerTermPeriod::spanLabel($from, $to);
        return ['Type' => 'gap', 'Label' => 'Gap: ' . $span, 'Span' => $span];
    }

    /** Terms in the rolls order (newest first), as rolls_for_org passes them. */
    private static function flags(array $newestFirst, bool $multiHolder = false): array
    {
        return OfficerPosition::flag_rolls_terms($newestFirst, $multiHolder);
    }

    /**
     * A one-season acting Champion inside a reign: the reigning officer held the
     * seat, so there is no gap after it and no overlap with it.
     */
    public function testANestedActingTermIsNeitherAGapNorAnOverlap(): void
    {
        $r = self::flags([
            self::term('Next', self::ord(2024, self::SUMMER), self::ord(2025, self::SPRING)),
            self::term('Acting', self::ord(2023, self::SUMMER), self::ord(2023, self::SUMMER)),
            self::term('Reign', self::ord(2023, self::SPRING), self::ord(2024, self::SPRING)),
        ]);
        self::assertSame([null, null, null], array_column($r['Terms'], 'FlagAfter'));
        self::assertSame(0, $r['GapCount']);
        self::assertSame(0, $r['OverlapCount']);
    }

    /** A real gap after a nested term is measured from the reign's end, not the nested term's. */
    public function testAGapAfterANestedTermIsMeasuredFromCoverage(): void
    {
        $r = self::flags([
            self::term('Later', self::ord(2022, self::SPRING), self::ord(2022, self::FALL)),
            self::term('Acting', self::ord(2020, self::SUMMER), self::ord(2020, self::SUMMER)),
            self::term('Reign', self::ord(2020, self::SPRING), self::ord(2020, self::FALL)),
        ]);
        self::assertSame(self::gap(self::ord(2020, self::FALL) + 1, self::ord(2022, self::SPRING) - 1), $r['Terms'][0]['FlagAfter'], 'the gap sits under the term that starts beyond coverage');
        self::assertNull($r['Terms'][1]['FlagAfter']);
        self::assertNull($r['Terms'][2]['FlagAfter']);
        self::assertSame(1, $r['GapCount']);
        self::assertSame(0, $r['OverlapCount']);
    }

    /** Co-holders of a supporting office overlap by design: no overlap flags, gaps against their union. */
    public function testCoHoldersOfASupportingOfficeRaiseNoOverlaps(): void
    {
        $terms = [
            self::term('Z', self::ord(2024, self::SUMMER), self::ord(2024, self::FALL)),
            self::term('Y', self::ord(2021, self::SUMMER), self::ord(2023, self::SPRING)),
            self::term('X', self::ord(2021, self::SPRING), self::ord(2022, self::FALL)),
        ];
        $multi = self::flags($terms, true);
        self::assertSame(0, $multi['OverlapCount']);
        self::assertSame(1, $multi['GapCount'], 'Z starts after the union of X and Y has ended');
        self::assertSame(self::gap(self::ord(2023, self::SUMMER), self::ord(2024, self::SPRING)), $multi['Terms'][0]['FlagAfter']);
        self::assertNull($multi['Terms'][1]['FlagAfter']);

        // The same terms on a single-holder office: Y overlaps X and runs past it.
        $single = self::flags($terms, false);
        self::assertSame(1, $single['OverlapCount']);
        self::assertSame(['Type' => 'overlap', 'Label' => 'Overlap with X'], $single['Terms'][1]['FlagAfter']);
    }

    /** One Fall ends a reign and starts the next: that season is shared, not overlapped. */
    public function testAFallToFallHandoffIsClean(): void
    {
        $r = self::flags([
            self::term('B', self::ord(2021, self::FALL), self::ord(2022, self::FALL)),
            self::term('A', self::ord(2020, self::FALL), self::ord(2021, self::FALL)),
        ]);
        self::assertSame([null, null], array_column($r['Terms'], 'FlagAfter'));
        self::assertSame(0, $r['GapCount'] + $r['OverlapCount']);
    }

    /** An overlap names the holder whose term reached furthest, by the recorded name. */
    public function testAnOverlapNamesTheCoveringHolderByTheirRecordedName(): void
    {
        $r = self::flags([
            self::term('New', self::ord(2022, self::SPRING), self::ord(2023, self::FALL)),
            self::term('Short', self::ord(2021, self::SUMMER), self::ord(2021, self::FALL)),
            self::term(
                'Bob',
                self::ord(2021, self::SPRING),
                self::ord(2022, self::FALL),
                ['MundaneId' => 7, 'Persona' => 'Sir Robert']
            ),
        ]);
        self::assertSame(['Type' => 'overlap', 'Label' => 'Overlap with Bob'], $r['Terms'][0]['FlagAfter']);
        self::assertNull($r['Terms'][1]['FlagAfter'], 'Short is nested inside Bob');
        self::assertSame(1, $r['OverlapCount']);
    }

    /** An unknown end leaves coverage unknown: the next start raises no gap. */
    public function testAnUnknownEndRaisesNoGapAfterIt(): void
    {
        $r = self::flags([
            self::term('Later', self::ord(2021, self::SPRING), self::ord(2022, self::FALL)),
            self::term('No End', self::ord(2015, self::SPRING), null),
            self::term('First', self::ord(2010, self::SPRING), self::ord(2010, self::FALL)),
        ]);
        self::assertNull($r['Terms'][0]['FlagAfter']);
        self::assertSame(
            self::gap(self::ord(2010, self::FALL) + 1, self::ord(2015, self::SPRING) - 1),
            $r['Terms'][1]['FlagAfter'],
            'the term with no end still starts beyond the coverage before it'
        );
        self::assertSame(1, $r['GapCount']);
    }

    /** An unknown start cannot be placed, but its known end still counts as coverage. */
    public function testAnUnknownStartContributesItsEnd(): void
    {
        $r = self::flags([
            self::term('Old', self::ord(2010, self::SPRING), self::ord(2010, self::FALL)),
            self::term('Ancient', null, self::ord(2005, self::FALL)),
        ]);
        self::assertSame(self::gap(self::ord(2005, self::FALL) + 1, self::ord(2010, self::SPRING) - 1), $r['Terms'][0]['FlagAfter']);
        self::assertNull($r['Terms'][1]['FlagAfter']);
    }

    /** The current monarch covers through the present: a later acting term is nested, not a gap or overlap. */
    public function testACurrentTermCoversThroughThePresent(): void
    {
        $r = self::flags([
            self::term('', self::ord(2024, self::SUMMER), null, ['IsCurrent' => true, 'MundaneId' => 3, 'Persona' => 'Queen']),
            self::term('Acting', self::ord(2025, self::SUMMER), self::ord(2025, self::SUMMER)),
            self::term('Reign', self::ord(2023, self::SPRING), self::ord(2024, self::SPRING)),
        ]);
        self::assertSame([null, null, null], array_column($r['Terms'], 'FlagAfter'));
        self::assertSame(0, $r['GapCount']);
        self::assertSame(0, $r['OverlapCount']);
    }

    /** A current co-holder covers the supporting office, so later starts raise no gap. */
    public function testACurrentCoHolderCoversASupportingOffice(): void
    {
        $r = self::flags([
            self::term('', self::ord(2020, self::SPRING), null, ['IsCurrent' => true, 'MundaneId' => 4, 'Persona' => 'Ongoing']),
            self::term('Third', self::ord(2025, self::SPRING), self::ord(2025, self::FALL)),
            self::term('Second', self::ord(2022, self::SPRING), self::ord(2023, self::FALL)),
        ], true);
        self::assertSame(0, $r['GapCount']);
        self::assertSame(0, $r['OverlapCount']);
    }

    /** A current term that starts inside a prior reign on a crown office is still an overlap. */
    public function testACurrentTermStartingInsideAPriorReignOverlaps(): void
    {
        $r = self::flags([
            self::term('', self::ord(2023, self::FALL), null, ['IsCurrent' => true, 'MundaneId' => 3, 'Persona' => 'Queen']),
            self::term('Reign', self::ord(2023, self::SPRING), self::ord(2024, self::SPRING)),
        ]);
        self::assertSame(1, $r['OverlapCount']);
        self::assertSame(0, $r['GapCount']);
        self::assertSame(['Type' => 'overlap', 'Label' => 'Overlap with Reign'], $r['Terms'][0]['FlagAfter']);
    }

    /** A current term with an unknown start (the migration's seat row) does not mask a real gap. */
    public function testACurrentTermWithAnUnknownStartDoesNotMaskAGap(): void
    {
        $r = self::flags([
            self::term('', null, null, ['IsCurrent' => true, 'MundaneId' => 5, 'Persona' => 'Seated']),
            self::term('Later', self::ord(2022, self::SPRING), self::ord(2022, self::FALL)),
            self::term('First', self::ord(2020, self::SPRING), self::ord(2020, self::FALL)),
        ]);
        self::assertSame(1, $r['GapCount']);
        self::assertSame('gap', $r['Terms'][1]['FlagAfter']['Type'] ?? null);
        self::assertSame(0, $r['OverlapCount']);
    }

    /** A crown holder seated while the previous term was still running overlaps it. */
    public function testACurrentTermStartingBeforeTheLastEndOverlaps(): void
    {
        $r = self::flags([
            self::term('', self::ord(2020, self::SPRING), null, ['IsCurrent' => true, 'MundaneId' => 3, 'Persona' => 'Queen']),
            self::term('Recent', self::ord(2019, self::FALL), self::ord(2020, self::SUMMER)),
        ]);
        self::assertSame(['Type' => 'overlap', 'Label' => 'Overlap with Recent'], $r['Terms'][0]['FlagAfter']);
        self::assertSame(1, $r['OverlapCount']);
    }
}
