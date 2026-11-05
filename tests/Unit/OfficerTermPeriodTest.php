<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OfficerTermPeriodTest extends TestCase
{
    public function testSpanWithSharedYear(): void
    {
        self::assertSame(
            ['start' => ['season' => 'spring', 'year' => 2023], 'end' => ['season' => 'fall', 'year' => 2023], 'endUnknown' => false, 'single' => false],
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
        self::assertTrue($p['single']);
    }

    /** A single Winter is one season: its end is its start, not the next winter. */
    public function testSingleWinterIsOneSeason(): void
    {
        $p = OfficerTermPeriod::parse('Winter 24', 2026);
        self::assertTrue($p['single']);
        $r = OfficerTermPeriod::readBack('Winter 24', false, 2026 * 4 + 3);
        self::assertSame(2024 * 4, $r['StartOrdinal']);
        self::assertSame(2024 * 4, $r['EndOrdinal']);
        self::assertSame('Winter 2024', $r['Label']);
        self::assertSame('Fall 2023', $r['PreviousSpan']);
        // This year's winter is past by fall, so it is no future end.
        self::assertIsArray(OfficerTermPeriod::readBack('Winter 26', false, 2026 * 4 + 3));
        // Read back from storage (both ends the same winter) it is still one season.
        self::assertSame(
            ['start' => 2024 * 4, 'end' => 2024 * 4],
            OfficerTermPeriod::resolve(['StartSeason' => 'winter', 'StartYear' => 2024, 'EndSeason' => 'winter', 'EndYear' => 2024, 'StartDate' => null, 'EndDate' => null])
        );
    }

    public function testSingleSpringPrefillsTheSeasonBefore(): void
    {
        $r = OfficerTermPeriod::readBack('Spring 23', false, 2026 * 4 + 3);
        self::assertSame('Spring 2023', $r['Label']);
        self::assertSame(2023 * 4 + 1, $r['EndOrdinal']);
        self::assertSame('Winter 2023', $r['PreviousSpan']);
    }

    public function testEqualEndsLabelAsOneSeason(): void
    {
        self::assertSame('Spring 2023', OfficerTermPeriod::label(['StartSeason' => 'spring', 'StartYear' => 2023, 'EndSeason' => 'spring', 'EndYear' => 2023, 'StartDate' => null, 'EndDate' => null], false));
        self::assertSame('Jul 4, 2023 – Aug 1, 2023', OfficerTermPeriod::label(['StartSeason' => null, 'StartYear' => null, 'EndSeason' => null, 'EndYear' => null, 'StartDate' => '2023-07-04', 'EndDate' => '2023-08-01'], false), 'exact dates in one season keep both ends');
    }

    public function testSpanLabelCollapsesOneSeason(): void
    {
        self::assertSame('Winter 2023', OfficerTermPeriod::spanLabel(2023 * 4, 2023 * 4));
        self::assertSame('Winter 2022 – Winter 2023', OfficerTermPeriod::spanLabel(2021 * 4 + 3 + 1, 2023 * 4));
    }

    public function testReversedWinterSpanIsRefused(): void
    {
        self::assertSame('The end comes before the start.', OfficerTermPeriod::parse('Winter 24 – Winter 23', 2026));
        self::assertIsArray(OfficerTermPeriod::parse('Winter 23 – Winter 24', 2026));
    }

    public function testPreviousSpanFromAWinterOrdinal(): void
    {
        self::assertSame('Fall 2023', OfficerTermPeriod::previousSpan(2024 * 4, 2024 * 4));
        self::assertSame('Summer 2023 – Winter 2024', OfficerTermPeriod::previousSpan(2024 * 4, 2024 * 4 + 2));
        self::assertSame('Winter 2024', OfficerTermPeriod::previousSpan(2024 * 4 + 1, 2024 * 4 + 1));
    }

    /** Amtwiki-style input: apostrophe years, dotted abbreviations, months. */
    public function testAmtwikiStyleInput(): void
    {
        self::assertSame(['season' => 'fall', 'year' => 1995], OfficerTermPeriod::parse("Fall '95", 2026)['start']);
        self::assertSame(['season' => 'fall', 'year' => 1995], OfficerTermPeriod::parse('Fall ’95', 2026)['start']);
        self::assertSame(['season' => 'spring', 'year' => 2023], OfficerTermPeriod::parse('Spr. 23', 2026)['start']);
        $m = OfficerTermPeriod::parse('March 2003 – Sept 2003', 2026);
        self::assertSame(['season' => 'spring', 'year' => 2003], $m['start']);
        self::assertSame(['season' => 'fall', 'year' => 2003], $m['end']);
        self::assertSame(['season' => 'spring', 'year' => 2003], OfficerTermPeriod::parse('Mar 03', 2026)['start']);
        self::assertSame(['season' => 'summer', 'year' => 2003], OfficerTermPeriod::parse('Jul. 2003', 2026)['start']);
        // December is the early winter of the next January-February.
        self::assertSame(['season' => 'winter', 'year' => 2004], OfficerTermPeriod::parse('Dec 2003', 2026)['start']);
        self::assertSame(['season' => 'winter', 'year' => 2004], OfficerTermPeriod::parse('Feb 04', 2026)['start']);
        $d = OfficerTermPeriod::readBack('Sep 2003 – Dec 2003', false, 2026 * 4 + 3);
        self::assertSame([2003 * 4 + 3, 2004 * 4], [$d['StartOrdinal'], $d['EndOrdinal']]);
        $s = OfficerTermPeriod::parse('March – May 2003', 2026);
        self::assertSame([['season' => 'spring', 'year' => 2003], ['season' => 'spring', 'year' => 2003]], [$s['start'], $s['end']]);
    }

    public function testErrorsEchoTheTextAsTyped(): void
    {
        self::assertStringContainsString('"Sprng"', OfficerTermPeriod::parse('Sprng 23', 2026));
        self::assertStringContainsString('"Spring 23 Fall"', OfficerTermPeriod::parse('Spring 23 Fall', 2026));
    }

    public function testAStartInTheFutureIsRefused(): void
    {
        self::assertSame('A term cannot start in the future.', OfficerTermPeriod::readBack('Spring 27', false, 2026 * 4 + 3));
        self::assertSame('A term cannot start in the future.', OfficerTermPeriod::readBack('Winter 27', true, 2026 * 4 + 3));
        self::assertIsArray(OfficerTermPeriod::readBack('Fall 26', true, 2026 * 4 + 3));
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

    /** The ledger's period field reads its input back before Enter (parseperiod). */
    public function testReadBack(): void
    {
        self::assertSame([
            'Label' => 'Summer 2023 – Winter 2024',
            'StartOrdinal' => 2023 * 4 + 2,
            'EndOrdinal' => 2024 * 4,
            'PreviousSpan' => 'Winter 2023 – Summer 2023',
        ], OfficerTermPeriod::readBack('Su23-W24', false, 2026 * 4 + 3));
        $unknown = OfficerTermPeriod::readBack('Spring 95 -', false, 2026 * 4 + 3);
        self::assertSame('Spring 1995 – ?', $unknown['Label']);
        self::assertNull($unknown['EndOrdinal']);
        self::assertSame('Fall 1994 – Spring 1995', $unknown['PreviousSpan']);
    }

    public function testReadBackRejections(): void
    {
        self::assertSame('Current terms are set from Manage Officers.', OfficerTermPeriod::readBack('Fall 26 - present', false, 2026 * 4 + 3));
        self::assertSame('A term cannot end in the future.', OfficerTermPeriod::readBack('Spring 26 - Fall 27', false, 2026 * 4 + 3));
        self::assertIsString(OfficerTermPeriod::readBack('Blorp 23', false, 2026 * 4 + 3));
    }

    /** A current term takes its start only; its end belongs to Manage Officers. */
    public function testReadBackForACurrentTerm(): void
    {
        $r = OfficerTermPeriod::readBack('Spring 24', true, 2026 * 4 + 3);
        self::assertSame('Spring 2024 – present', $r['Label']);
        self::assertNull($r['EndOrdinal']);
        self::assertIsString(OfficerTermPeriod::readBack('Spring 24 - Fall 24', true, 2026 * 4 + 3));
    }
}
