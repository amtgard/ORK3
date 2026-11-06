<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OfficerTermPeriodTest extends TestCase
{
    public function testSpanWithSharedYear(): void
    {
        self::assertSame(
            [
                'start' => ['season' => 'spring', 'year' => 2023, 'ordinal' => 2023 * 4 + 1],
                'end' => ['season' => 'fall', 'year' => 2023, 'ordinal' => 2023 * 4 + 3],
                'endUnknown' => false, 'single' => false,
            ],
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
        self::assertSame('Winter 2023–24', $r['Label'], 'a winter is named by both its years');
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
        self::assertSame('Winter 2022–23', OfficerTermPeriod::spanLabel(2023 * 4, 2023 * 4));
        self::assertSame('Winter 2021–22 – Winter 2022–23', OfficerTermPeriod::spanLabel(2021 * 4 + 3 + 1, 2023 * 4));
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
        $fall95 = ['season' => 'fall', 'year' => 1995, 'ordinal' => 1995 * 4 + 3];
        self::assertSame($fall95, OfficerTermPeriod::parse("Fall '95", 2026)['start']);
        self::assertSame($fall95, OfficerTermPeriod::parse('Fall ’95', 2026)['start']);
        self::assertSame(['season' => 'spring', 'year' => 2023, 'ordinal' => 2023 * 4 + 1], OfficerTermPeriod::parse('Spr. 23', 2026)['start']);
        $m = OfficerTermPeriod::parse('March 2003 – Sept 2003', 2026);
        self::assertSame(['season' => 'spring', 'year' => 2003, 'ordinal' => 2003 * 4 + 1], $m['start']);
        self::assertSame(['season' => 'fall', 'year' => 2003, 'ordinal' => 2003 * 4 + 3], $m['end']);
        self::assertSame(['season' => 'spring', 'year' => 2003, 'ordinal' => 2003 * 4 + 1], OfficerTermPeriod::parse('Mar 03', 2026)['start']);
        self::assertSame(['season' => 'summer', 'year' => 2003, 'ordinal' => 2003 * 4 + 2], OfficerTermPeriod::parse('Jul. 2003', 2026)['start']);
        // December is the early winter of the next January-February.
        self::assertSame(['season' => 'winter', 'year' => 2004, 'ordinal' => 2004 * 4], OfficerTermPeriod::parse('Dec 2003', 2026)['start']);
        self::assertSame(['season' => 'winter', 'year' => 2004, 'ordinal' => 2004 * 4], OfficerTermPeriod::parse('Feb 04', 2026)['start']);
        $d = OfficerTermPeriod::readBack('Sep 2003 – Dec 2003', false, 2026 * 4 + 3);
        self::assertSame([2003 * 4 + 3, 2004 * 4], [$d['StartOrdinal'], $d['EndOrdinal']]);
        $s = OfficerTermPeriod::parse('March – May 2003', 2026);
        $spring03 = ['season' => 'spring', 'year' => 2003, 'ordinal' => 2003 * 4 + 1];
        self::assertSame([$spring03, $spring03], [$s['start'], $s['end']]);
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
        self::assertSame('Winter 2022–23', OfficerTermPeriod::ordinalLabel(2023 * 4));
        self::assertSame('Winter 1999–2000', OfficerTermPeriod::ordinalLabel(2000 * 4), 'a winter across a century names both years in full');
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
            'Label' => 'Summer 2023 – Winter 2023–24',
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

    /**
     * Resolve $text as EditHistoryTerm applies a sent Period (historyTermColumns):
     * the Period's seasons replace the span and clear the unsent dates; on a
     * current row it sets the start only and the stored end stays.
     */
    private static function savedOrdinals(string $text, array $term, bool $current): array
    {
        $p = OfficerTermPeriod::parse($text);
        self::assertIsArray($p, '"' . $text . '" must parse: ' . (is_string($p) ? $p : ''));
        if ($current) {
            self::assertFalse(OfficerTermPeriod::isSpan($text), 'a current term is its start only');
            return ['start' => $p['start']['ordinal'], 'end' => OfficerTermPeriod::ordinals($term)['end']];
        }
        return ['start' => $p['start']['ordinal'], 'end' => $p['end']['ordinal'] ?? null];
    }

    private static function span(array $t): array
    {
        return $t + [
            'StartSeason' => null, 'StartYear' => null, 'StartDate' => null,
            'EndSeason' => null, 'EndYear' => null, 'EndDate' => null,
        ];
    }

    /** @return array<string, array{array, bool, ?string}> term, current, expected text (null: any round-tripping text) */
    public static function editTextTerms(): array
    {
        return [
            'date-only spring to fall' => [['StartDate' => '2019-04-02', 'EndDate' => '2019-10-20'], false, 'Spring 2019 – Fall 2019'],
            // The regression: "Winter 2021 – Spring 2022" re-parses to the winter of Dec 2021.
            'date-only from a late winter' => [['StartDate' => '2021-01-15', 'EndDate' => '2022-04-10'], false, 'Winter 2020 – Spring 2022'],
            'date-only from an early winter' => [['StartDate' => '2020-12-05', 'EndDate' => '2022-04-10'], false, 'Winter 2020 – Spring 2022'],
            'date-only within one winter' => [['StartDate' => '2020-12-05', 'EndDate' => '2021-02-10'], false, 'Winter 2021'],
            'date-only winter to winter' => [['StartDate' => '2021-01-15', 'EndDate' => '2022-01-20'], false, 'Winter 2021 – Winter 2022'],
            'date-only, end unknown' => [['StartDate' => '2020-12-10'], false, 'Winter 2021 –'],
            // A current "Winter 2020" (Jan-Feb 2020) closed in January 2021 keeps its
            // stored start: a year-long term, not a collapse into one winter.
            'closed current winter start' => [['StartSeason' => 'winter', 'StartYear' => 2020, 'StartOrdinal' => 2020 * 4, 'EndDate' => '2021-01-15', 'EndOrdinal' => 2021 * 4], false, 'Winter 2020 – Winter 2021'],
            'start date + end season' => [['StartDate' => '2019-03-30', 'EndSeason' => 'fall', 'EndYear' => 2019], false, 'Spring 2019 – Fall 2019'],
            'start season + end date' => [['StartSeason' => 'fall', 'StartYear' => 2018, 'EndDate' => '2019-06-30'], false, 'Fall 2018 – Summer 2019'],
            'winter start before a spring' => [['StartSeason' => 'winter', 'StartYear' => 2020, 'EndSeason' => 'spring', 'EndYear' => 2021], false, 'Winter 2020 – Spring 2021'],
            'winter start, end unknown' => [['StartSeason' => 'winter', 'StartYear' => 2020], false, 'Winter 2020 –'],
            'winter to winter seasons' => [['StartSeason' => 'winter', 'StartYear' => 2020, 'EndSeason' => 'winter', 'EndYear' => 2021], false, 'Winter 2020 – Winter 2021'],
            'one season' => [['StartSeason' => 'spring', 'StartYear' => 2023, 'EndSeason' => 'spring', 'EndYear' => 2023], false, 'Spring 2023'],
            'current, dated' => [['StartDate' => '2020-04-01'], true, 'Spring 2020'],
            'current, December start' => [['StartDate' => '2020-12-15'], true, 'Winter 2021'],
            'current, winter season' => [['StartSeason' => 'winter', 'StartYear' => 2019], true, 'Winter 2019'],
            'current, spring season' => [['StartSeason' => 'spring', 'StartYear' => 2024], true, 'Spring 2024'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('editTextTerms')]
    public function testEditTextRoundTrips(array $t, bool $current, ?string $expected): void
    {
        $term = self::span($t);
        $text = OfficerTermPeriod::editText($term, $current);
        if ($expected !== null) {
            self::assertSame($expected, $text);
        }
        self::assertSame(
            OfficerTermPeriod::ordinals($term),
            self::savedOrdinals($text, $term, $current),
            '"' . $text . '" saved back unchanged must keep the term\'s seasons'
        );
    }

    /** No known start: '' (the grammar has no unknown start), so an unchanged field sends no Period. */
    public function testEditTextIsBlankForAnEndOnlyTerm(): void
    {
        self::assertSame('', OfficerTermPeriod::editText(self::span(['EndSeason' => 'fall', 'EndYear' => 2005]), false));
        self::assertSame('', OfficerTermPeriod::editText(self::span(['EndDate' => '2005-10-01']), false));
        self::assertSame('', OfficerTermPeriod::editText(self::span([]), false));
        self::assertSame('', OfficerTermPeriod::editText(self::span([]), true));
    }

    /** Every season start over a few years, each end shape: the edit text always round-trips. */
    public function testEditTextRoundTripsExhaustively(): void
    {
        $sides = [null];
        foreach ([2019, 2020, 2021] as $y) {
            foreach (['winter', 'spring', 'summer', 'fall'] as $s) {
                $sides[] = ['season' => $s, 'year' => $y];
            }
            foreach (['01-10', '02-20', '04-01', '07-04', '10-31', '12-15'] as $md) {
                $sides[] = ['date' => $y . '-' . $md];
            }
        }
        $put = function (array &$t, string $prefix, ?array $side): void {
            if ($side === null) {
                return;
            }
            if (isset($side['date'])) {
                $t[$prefix . 'Date'] = $side['date'];
            } else {
                $t[$prefix . 'Season'] = $side['season'];
                $t[$prefix . 'Year'] = $side['year'];
            }
        };
        $checked = 0;
        foreach ($sides as $a) {
            if ($a === null) {
                continue;
            }
            foreach ($sides as $b) {
                $t = [];
                $put($t, 'Start', $a);
                $put($t, 'End', $b);
                $term = self::span($t);
                $want = OfficerTermPeriod::ordinals($term);
                if ($want['end'] !== null && $want['end'] < $want['start']) {
                    continue;
                }
                foreach ($b === null ? [false, true] : [false] as $current) {
                    $text = OfficerTermPeriod::editText($term, $current);
                    self::assertNotSame('', $text, json_encode($t) . ' has a start, so it has edit text');
                    self::assertSame($want, self::savedOrdinals($text, $term, $current), json_encode($t) . ' -> "' . $text . '"');
                    $checked++;
                }
            }
        }
        self::assertGreaterThan(500, $checked);
    }

    // ------------------------------------------------------------
    // Stored ordinals: a saved term's meaning is fixed (review items 1-4)
    // ------------------------------------------------------------

    /**
     * Item 1: the stored ordinals are the term's meaning. A current "Winter 24"
     * (Jan-Feb 2024) closed with an exact end keeps its start; re-resolving the
     * columns would read it as Dec 2024 and collapse it into one winter.
     */
    public function testClosingACurrentWinterStartKeepsItsStartOrdinal(): void
    {
        $open = self::span(['StartSeason' => 'winter', 'StartYear' => 2024, 'StartOrdinal' => 2024 * 4, 'EndOrdinal' => null]);
        self::assertSame(['start' => 2024 * 4, 'end' => null], OfficerTermPeriod::ordinals($open));
        $closed = ['EndDate' => '2025-01-10', 'EndOrdinal' => 2025 * 4] + $open;
        self::assertSame(['start' => 2024 * 4, 'end' => 2025 * 4], OfficerTermPeriod::ordinals($closed));
        self::assertSame('Winter 2023–24 – Jan 10, 2025', OfficerTermPeriod::label($closed, false));
        // A dated side with no stored ordinal (a row written before the stamp, or
        // by hand) reads its date: one date is one ordinal, nothing re-resolves.
        $dated = self::span(['StartDate' => '2024-03-10', 'StartOrdinal' => null, 'EndOrdinal' => null]);
        self::assertSame(['start' => 8097, 'end' => null], OfficerTermPeriod::ordinals($dated));
        self::assertNotSame('', OfficerTermPeriod::editText($dated, false));
        self::assertSame(['start' => 8097, 'end' => 2025 * 4], OfficerTermPeriod::ordinals(['EndDate' => '2025-01-10', 'EndOrdinal' => ''] + $dated));
        // A stored null is an unknown end, not "resolve the columns again".
        self::assertSame(['start' => 2024 * 4, 'end' => null], OfficerTermPeriod::ordinals(['EndSeason' => 'fall', 'EndYear' => 2024] + $open));
    }

    /** Item 2: a month start is that month's ordinal, however long the span after it. */
    public function testMonthStartsKeepTheirOrdinalAcrossSpanLengths(): void
    {
        $now = 2030 * 4;
        foreach (['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'] as $i => $mon) {
            foreach ([2020, 2023] as $y) {
                $want = OfficerTermPeriod::ordinal(null, null, sprintf('%04d-%02d-01', $y, $i + 1), 'start', null);
                for ($len = 0; $len <= 8; $len++) {
                    $text = $mon . ' ' . $y . ' – ' . OfficerTermPeriod::ordinalLabel($want + $len);
                    $r = OfficerTermPeriod::readBack($text, false, $now);
                    self::assertIsArray($r, $text . ': ' . (is_string($r) ? $r : ''));
                    self::assertSame([$want, $want + $len], [$r['StartOrdinal'], $r['EndOrdinal']], $text);
                }
            }
        }
        // The cases the review found, against short typed ends.
        self::assertSame(2023 * 4, OfficerTermPeriod::readBack('Jan 23 – Fall 24', false, $now)['StartOrdinal']);
        self::assertSame(2020 * 4, OfficerTermPeriod::readBack('Feb 2020 – Spring 2021', false, $now)['StartOrdinal']);
    }

    /** Item 3: both typed forms of one winter read back as the same two-year label. */
    public function testBothTypedFormsOfOneWinterShareALabel(): void
    {
        $now = 2026 * 4 + 3;
        $a = OfficerTermPeriod::readBack('Summer 22 – Winter 22', false, $now);
        $b = OfficerTermPeriod::readBack('Summer 22 – Winter 23', false, $now);
        self::assertSame('Summer 2022 – Winter 2022–23', $a['Label']);
        self::assertSame($a, $b);
        // The two-year name parses, alone and as either end, and is one season.
        foreach (['Winter 2022–23', 'Winter 22-23', 'W 22/23', 'winter 2022-2023'] as $text) {
            $r = OfficerTermPeriod::readBack($text, false, $now);
            self::assertIsArray($r, $text);
            self::assertSame(['Winter 2022–23', 2023 * 4, 2023 * 4], [$r['Label'], $r['StartOrdinal'], $r['EndOrdinal']], $text);
        }
        self::assertSame([2023 * 4, 2023 * 4 + 1], array_values(array_slice(OfficerTermPeriod::readBack('Winter 2022–23 – Spring 23', false, $now), 1, 2)));
        self::assertSame([2022 * 4 + 3, 2023 * 4], array_values(array_slice(OfficerTermPeriod::readBack('Fall 22 – Winter 2022–23', false, $now), 1, 2)));
        // A current term's start may be named that way: it is not a span.
        self::assertFalse(OfficerTermPeriod::isSpan('Winter 2022–23'));
        self::assertTrue(OfficerTermPeriod::isSpan('Winter 22 – Spring 23'));
        self::assertSame('Winter 2022–23 – present', OfficerTermPeriod::readBack('Winter 2022–23', true, $now)['Label']);
        // Only consecutive years name a winter.
        self::assertIsString(OfficerTermPeriod::parse('Winter 22–24', 2026));
        // Stored winters label by their ordinal, whatever year was typed.
        $typed22 = self::span(['StartSeason' => 'summer', 'StartYear' => 2022, 'EndSeason' => 'winter', 'EndYear' => 2022]);
        $typed23 = self::span(['StartSeason' => 'summer', 'StartYear' => 2022, 'EndSeason' => 'winter', 'EndYear' => 2023]);
        self::assertSame(OfficerTermPeriod::label($typed22, false), OfficerTermPeriod::label($typed23, false));
    }

    /**
     * Item 4: every pre-fill and every label reads back as the span it names.
     * previousSpan()/inputText() are typed back in; spanLabel() is display text
     * that a user may also copy into the field.
     */
    public function testPreviousSpanAndSpanLabelRoundTrip(): void
    {
        $back = function (string $text): array {
            $p = OfficerTermPeriod::parse($text, 2026);
            self::assertIsArray($p, '"' . $text . '": ' . (is_string($p) ? $p : ''));
            return [$p['start']['ordinal'], $p['end']['ordinal'] ?? null];
        };
        $checked = 0;
        for ($from = 2019 * 4; $from < 2023 * 4; $from++) {
            for ($to = $from; $to <= $from + 9; $to++) {
                self::assertSame([$from, $to], $back(OfficerTermPeriod::inputText($from, $to)), 'inputText ' . $from . '..' . $to);
                self::assertSame([$from, $to], $back(OfficerTermPeriod::spanLabel($from, $to)), 'spanLabel ' . $from . '..' . $to);
                $prev = OfficerTermPeriod::previousSpan($from, $to);
                $want = $to <= $from ? [$from - 1, $from - 1] : [$from - ($to - $from), $from];
                self::assertSame($want, $back($prev), 'previousSpan ' . $from . '..' . $to . ' -> "' . $prev . '"');
                $checked++;
            }
            $prev = OfficerTermPeriod::previousSpan($from, null);
            self::assertSame([$from - 2, $from], $back($prev), 'previousSpan ' . $from . '.. -> "' . $prev . '"');
        }
        self::assertGreaterThan(150, $checked);
        // The review's case: after "Spring 25 – Summer 26" the pre-fill saves Winter 2024 – Spring 2025.
        $r = OfficerTermPeriod::readBack('Spring 25 – Summer 26', false, 2026 * 4 + 3);
        self::assertSame([2024 * 4, 2025 * 4 + 1], $back($r['PreviousSpan']));
    }

    /** Edit text round-trips for any stored pair of ordinals, whatever the columns say. */
    public function testEditTextRoundTripsStoredOrdinals(): void
    {
        $checked = 0;
        for ($s = 2019 * 4; $s < 2022 * 4; $s++) {
            foreach (array_merge([null], range($s, $s + 9)) as $e) {
                foreach ($e === null ? [false, true] : [false] as $current) {
                    $term = self::span(['StartOrdinal' => $s, 'EndOrdinal' => $e]);
                    $text = OfficerTermPeriod::editText($term, $current);
                    self::assertNotSame('', $text);
                    self::assertSame(['start' => $s, 'end' => $e], self::savedOrdinals($text, $term, $current), $s . '..' . $e . ' -> "' . $text . '"');
                    $checked++;
                }
            }
        }
        self::assertGreaterThan(140, $checked);
    }
}
