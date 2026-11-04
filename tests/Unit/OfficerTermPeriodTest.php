<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OfficerTermPeriodTest extends TestCase
{
    public function testSpanWithSharedYear(): void
    {
        self::assertSame(
            ['start' => ['season' => 'spring', 'year' => 2023], 'end' => ['season' => 'fall', 'year' => 2023], 'endUnknown' => false],
            OfficerTermPeriod::parse('Spring–Fall 23', 2026)
        );
    }

    public function testAbbreviationsAndJoinedYears(): void
    {
        self::assertSame(OfficerTermPeriod::parse('Summer 2023 - Winter 2024', 2026), OfficerTermPeriod::parse('Su23-W24', 2026));
    }

    public function testTwoDigitYearsPivot(): void
    {
        self::assertSame(1995, OfficerTermPeriod::parse('Spring 95', 2026)['start']['year']);
        self::assertSame(2027, OfficerTermPeriod::parse('Spring 27', 2026)['start']['year']);
    }

    public function testSingleSeasonIsAOneSeasonTerm(): void
    {
        $p = OfficerTermPeriod::parse('Fall 22', 2026);
        self::assertSame($p['start'], $p['end']);
    }

    public function testEndUnknown(): void
    {
        self::assertTrue(OfficerTermPeriod::parse('Spring 95 – ?', 2026)['endUnknown']);
        self::assertTrue(OfficerTermPeriod::parse('Spring 95 -', 2026)['endUnknown']);
    }

    public function testRejections(): void
    {
        self::assertIsString(OfficerTermPeriod::parse('Sprng 23', 2026));
        self::assertIsString(OfficerTermPeriod::parse('Spring 23 Fall', 2026));
        self::assertIsString(OfficerTermPeriod::parse('Fall 23 – Spring 23', 2026));
        self::assertSame('Current terms are set from Manage Officers.', OfficerTermPeriod::parse('Fall 26 – present', 2026));
    }

    /** The four cases the user gave, plus both-Winter. */
    public function testWinterResolvesFromDirection(): void
    {
        $r = fn ($a, $b, $c, $d) => OfficerTermPeriod::resolve(['StartSeason' => $a, 'StartYear' => $b, 'EndSeason' => $c, 'EndYear' => $d, 'StartDate' => null, 'EndDate' => null]);
        self::assertSame(['start' => 2023 * 4 + 2, 'end' => 2024 * 4], $r('summer', 2023, 'winter', 2023));
        self::assertSame(['start' => 2023 * 4 + 2, 'end' => 2024 * 4], $r('summer', 2023, 'winter', 2024));
        self::assertSame(['start' => 2023 * 4, 'end' => 2023 * 4 + 2], $r('winter', 2023, 'summer', 2023));
        self::assertSame(['start' => 2024 * 4, 'end' => 2024 * 4 + 2], $r('winter', 2023, 'summer', 2024));
        self::assertSame(['start' => 2023 * 4, 'end' => 2024 * 4], $r('winter', 2023, 'winter', 2024));
    }

    public function testExactDatesWin(): void
    {
        self::assertSame(2023 * 4 + 2, OfficerTermPeriod::ordinal('spring', 2023, '2023-07-04', 'start', null));
    }

    public function testLabels(): void
    {
        self::assertSame('Winter 2023', OfficerTermPeriod::ordinalLabel(2023 * 4));
        self::assertSame('Spring 1995 – ?', OfficerTermPeriod::label(['StartSeason' => 'spring', 'StartYear' => 1995, 'EndSeason' => null, 'EndYear' => null, 'StartDate' => null, 'EndDate' => null], false));
        self::assertSame('Fall 2026 – present', OfficerTermPeriod::label(['StartSeason' => 'fall', 'StartYear' => 2026, 'EndSeason' => null, 'EndYear' => null, 'StartDate' => null, 'EndDate' => null], true));
        self::assertSame('Jul 4, 2023 – Fall 2023', OfficerTermPeriod::label(['StartSeason' => 'spring', 'StartYear' => 2023, 'StartDate' => '2023-07-04', 'EndSeason' => 'fall', 'EndYear' => 2023, 'EndDate' => null], false));
    }

    /** Backlog pre-fill: after Spring–Fall 23 the next row offers Fall 22–Spring 23. */
    public function testPreviousSpan(): void
    {
        self::assertSame('Fall 2022 – Spring 2023', OfficerTermPeriod::previousSpan(2023 * 4 + 1, 2023 * 4 + 3));
        self::assertSame('Fall 2022 – Spring 2023', OfficerTermPeriod::previousSpan(2023 * 4 + 1, null));
    }
}
