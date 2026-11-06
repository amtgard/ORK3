<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Order of the Zodiac (award_id 30) is granted once per calendar month, so its twelve
 * positions are months, not levels. Duplicates are legitimate (35 players already hold
 * duplicate ranks, one holds nine) and a total can exceed 12 -- both the distinct-rank
 * set and the maxRank clamp that ClassifyLadderGrants uses for ranked ladders are wrong
 * here.
 */
final class ZodiacProgressTest extends TestCase
{
    /** @param list<array{Rank: int, Date: string, ZodiacMonth: int}> $grants */
    private function zodiac(array $grants): array
    {
        return (new Player())->ClassifyLadderGrants(30, 0, $grants, false);
    }

    public function testCountIsTheTotalAndIsUncapped(): void
    {
        $grants = [];
        for ($i = 0; $i < 14; $i++) {
            $grants[] = ['Rank' => 0, 'Date' => '2024-01-01', 'ZodiacMonth' => ($i % 12) + 1];
        }

        // Not distinct months, not highest rank, and never clamped to 12.
        $this->assertSame(14, $this->zodiac($grants)['Count']);
    }

    public function testDuplicateMonthsCountTwiceButFillOneCircle(): void
    {
        $grants = [
            ['Rank' => 0, 'Date' => '2023-12-20', 'ZodiacMonth' => 12],
            ['Rank' => 0, 'Date' => '2024-12-20', 'ZodiacMonth' => 12],
            ['Rank' => 0, 'Date' => '2024-03-05', 'ZodiacMonth' => 3],
        ];
        $result = $this->zodiac($grants);

        $this->assertSame(3, $result['Count']);
        $this->assertSame([3, 12], $result['MonthsHeld']);
        $this->assertSame(
            ['2023-12-20', '2024-12-20'],
            $result['MonthDates'][12],
            'both December dates ride along for the strip tooltip'
        );
    }

    public function testMarkerIsSetWhenAnyZodiacHasNoMonth(): void
    {
        $result = $this->zodiac([
            ['Rank' => 0, 'Date' => '2024-01-01', 'ZodiacMonth' => 1],
            ['Rank' => 5, 'Date' => '2019-01-01', 'ZodiacMonth' => 0],
        ]);

        $this->assertSame(1, $result['Unmonthed']);
        $this->assertTrue($result['Approx'], '~ means "month not recorded" for Zodiac');
    }

    public function testMarkerIsClearWhenEveryZodiacHasAMonth(): void
    {
        $result = $this->zodiac([
            ['Rank' => 0, 'Date' => '2024-01-01', 'ZodiacMonth' => 1],
            ['Rank' => 0, 'Date' => '2024-02-01', 'ZodiacMonth' => 2],
        ]);

        $this->assertSame(0, $result['Unmonthed']);
        $this->assertFalse($result['Approx']);
    }

    public function testZodiacNeverHasBonusGrants(): void
    {
        // There is no "past max" for a monthly award.
        $grants = [];
        for ($m = 1; $m <= 12; $m++) {
            $grants[] = ['Rank' => 0, 'Date' => '2024-01-01', 'ZodiacMonth' => $m];
        }
        $grants[] = ['Rank' => 0, 'Date' => '2025-01-01', 'ZodiacMonth' => 1];

        $this->assertSame(0, $this->zodiac($grants)['BonusCount']);
    }

    public function testLegacyRanksAreNeverReadAsMonths(): void
    {
        // The whole point: rank 1 on 1,193 grants must not become January.
        $result = $this->zodiac([
            ['Rank' => 1, 'Date' => '2015-07-01', 'ZodiacMonth' => 0],
        ]);

        $this->assertSame([], $result['MonthsHeld']);
        $this->assertSame(1, $result['Unmonthed']);
    }

    public function testMasterZodiacStillSuppressesTheMarker(): void
    {
        $result = (new Player())->ClassifyLadderGrants(
            30,
            0,
            [['Rank' => 0, 'Date' => '2019-01-01', 'ZodiacMonth' => 0]],
            true
        );

        $this->assertFalse($result['Approx'], 'GetLadderMasterMap behaviour is preserved as-is');
    }
}
