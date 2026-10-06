<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The consent "data gate" is the promise the survey module makes to respondents,
 * so the scrub that enforces it is a PURE function with its own tests.
 *
 * Every column in the table below comes straight from design spec §2. If a row
 * here ever disagrees with that table, the spec wins and the code is wrong —
 * these tests exist so a well-meaning refactor of submit() cannot quietly widen
 * what the ORK stores about someone who asked to stay anonymous.
 *
 *   column            | full  | partial            | anonymous
 *   ------------------+-------+--------------------+--------------------
 *   mundane_id        | set   | NULL               | NULL
 *   kingdom_id        | set   | set                | NULL
 *   tenure_months     | set   | band floor         | NULL
 *   started_at        | set   | NULL               | NULL
 *   submitted_at      | exact | DATE 00:00:00      | DATE 00:00:00
 *   duration_seconds  | set   | NULL               | NULL
 *
 * "band floor" = the TENURE_BANDS floor in months (0, 12, 36, 72, 132), so a
 * partial row says "6–10 years", never "7 years 3 months" (review #2).
 */
final class SurveyConsentTest extends TestCase
{
    /** @return array<string, mixed> */
    private function row(): array
    {
        return [
            'mundane_id'       => 46193,
            'kingdom_id'       => 17,
            'park_id'          => 1049,
            'tenure_months'    => 87,
            'started_at'       => '2026-09-09 14:02:11',
            'submitted_at'     => '2026-09-09 14:09:40',
            'duration_seconds' => 449,
        ];
    }

    // ------------------------------------------------------------------ scrub

    public function testFullKeepsEverything(): void
    {
        $this->assertSame($this->row(), SurveyResponse::scrubForConsent($this->row(), 'full'));
    }

    public function testPartialDropsIdentityBandsTenureAndTruncatesDay(): void
    {
        $r = SurveyResponse::scrubForConsent($this->row(), 'partial');
        $this->assertNull($r['mundane_id']);
        $this->assertSame(17, $r['kingdom_id']);
        $this->assertSame(72, $r['tenure_months'], '87 months is stored as the 6–10 years band floor');
        $this->assertNull($r['started_at']);
        $this->assertSame('2026-09-09 00:00:00', $r['submitted_at']);
        $this->assertNull($r['duration_seconds'], 'partial copy never promises to keep a duration');
    }

    /** @return list<array{int, int}> [exact months, stored band floor] */
    public static function tenureBandCases(): array
    {
        return [
            [0, 0], [11, 0],
            [12, 12], [35, 12],
            [36, 36], [71, 36],
            [72, 72], [131, 72],
            [132, 132], [480, 132],
        ];
    }

    #[DataProvider('tenureBandCases')]
    public function testPartialStoresTheBandFloorNeverTheExactMonths(int $months, int $floor): void
    {
        $in = $this->row();
        $in['tenure_months'] = $months;
        $this->assertSame($floor, SurveyResponse::scrubForConsent($in, 'partial')['tenure_months']);
        $this->assertSame($floor, SurveyResponse::tenureBandFloor($months));
        // full is the one level that keeps the exact figure.
        $this->assertSame($months, SurveyResponse::scrubForConsent($in, 'full')['tenure_months']);
    }

    public function testPartialKeepsAnUnknownTenureUnknown(): void
    {
        $in = $this->row();
        $in['tenure_months'] = null;
        $this->assertNull(SurveyResponse::scrubForConsent($in, 'partial')['tenure_months']);
    }

    public function testNegativeMonthsFallInTheFirstBand(): void
    {
        $this->assertSame(0, SurveyResponse::tenureBandFloor(-5));
    }

    public function testTenureBandLabels(): void
    {
        $this->assertSame('Under 1 year', SurveyResponse::tenureBandLabel(0));
        $this->assertSame('1–2 years', SurveyResponse::tenureBandLabel(12));
        $this->assertSame('3–5 years', SurveyResponse::tenureBandLabel(36));
        $this->assertSame('6–10 years', SurveyResponse::tenureBandLabel(72));
        $this->assertSame('Over 10 years', SurveyResponse::tenureBandLabel(132));
        // Any month count resolves to its band.
        $this->assertSame('6–10 years', SurveyResponse::tenureBandLabel(87));
    }

    public function testTenureBandsAreAscendingAndStartAtZero(): void
    {
        $floors = array_column(SurveyResponse::TENURE_BANDS, 0);
        $this->assertSame(0, $floors[0]);
        $sorted = $floors;
        sort($sorted);
        $this->assertSame($sorted, $floors);
        $this->assertSame([0, 12, 36, 72, 132], $floors);
    }

    public function testAnonymousDropsAll(): void
    {
        $r = SurveyResponse::scrubForConsent($this->row(), 'anonymous');
        foreach (['mundane_id', 'kingdom_id', 'tenure_months', 'started_at', 'duration_seconds'] as $k) {
            $this->assertNull($r[$k], $k);
        }
        $this->assertSame('2026-09-09 00:00:00', $r['submitted_at']);
    }

    public function testUnknownConsentThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SurveyResponse::scrubForConsent($this->row(), 'x');
    }

    public function testScrubIsPureAndDoesNotMutateItsArgument(): void
    {
        $in = $this->row();
        SurveyResponse::scrubForConsent($in, 'anonymous');
        $this->assertSame($this->row(), $in);
    }

    public function testScrubKeepsUnrelatedColumnsUntouched(): void
    {
        $in = $this->row();
        $in['survey_id'] = 12;
        $in['is_test']   = 0;
        $r = SurveyResponse::scrubForConsent($in, 'anonymous');
        $this->assertSame(12, $r['survey_id']);
        $this->assertSame(0, $r['is_test']);
    }

    public function testScrubFillsMissingColumnsWithNull(): void
    {
        $r = SurveyResponse::scrubForConsent(['submitted_at' => '2026-09-09 14:09:40'], 'full');
        foreach (['mundane_id', 'kingdom_id', 'tenure_months', 'started_at', 'duration_seconds'] as $k) {
            $this->assertArrayHasKey($k, $r, $k);
            $this->assertNull($r[$k], $k);
        }
    }

    public function testPartialLeavesAnAlreadyMidnightStampAlone(): void
    {
        $in = $this->row();
        $in['submitted_at'] = '2026-09-09 00:00:00';
        $r = SurveyResponse::scrubForConsent($in, 'partial');
        $this->assertSame('2026-09-09 00:00:00', $r['submitted_at']);
    }

    public function testAnonymousTruncatesAnUnparseableStampToToday(): void
    {
        $in = $this->row();
        $in['submitted_at'] = '';
        $r = SurveyResponse::scrubForConsent($in, 'anonymous');
        $this->assertSame(date('Y-m-d') . ' 00:00:00', $r['submitted_at']);
    }

    // --------------------------------------------------- respondent option order

    /** @return list<array{option_id: int, role: string, label: string, is_other: int}> */
    private function options(int $count, ?int $otherAt = null): array
    {
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $out[] = ['option_id' => 100 + $i, 'role' => 'choice', 'label' => 'O' . $i, 'is_other' => ($i === $otherAt) ? 1 : 0];
        }
        return $out;
    }

    public function testShuffleIsDeterministicForASeedAndKeepsEveryOption(): void
    {
        $opts = $this->options(8);
        $a = SurveyResponse::shuffleOptions($opts, 12345);
        $b = SurveyResponse::shuffleOptions($opts, 12345);
        $this->assertSame($a, $b, 'a resumed draft must see the same order');
        $ids = array_column($a, 'option_id');
        sort($ids);
        $this->assertSame(array_column($opts, 'option_id'), $ids);
    }

    public function testShuffleAnchorsOtherAtTheEnd(): void
    {
        // "Other (please specify)" authored in the middle still ends up last,
        // for every seed (review #21).
        $opts = $this->options(6, 3);
        foreach ([1, 7, 99, 12345, 987654321, -42] as $seed) {
            $out = SurveyResponse::shuffleOptions($opts, $seed);
            $this->assertCount(6, $out);
            $this->assertSame(103, $out[5]['option_id'], 'seed ' . $seed);
            $this->assertSame(1, $out[5]['is_other']);
        }
    }

    public function testShuffleActuallyReordersTheOrdinaryOptions(): void
    {
        $opts = $this->options(8);
        $orders = [];
        foreach ([1, 2, 3, 4, 5, 6] as $seed) {
            $orders[] = implode(',', array_column(SurveyResponse::shuffleOptions($opts, $seed), 'option_id'));
        }
        $this->assertGreaterThan(1, count(array_unique($orders)));
    }

    public function testConsentsConstantIsTheSpecList(): void
    {
        $this->assertSame(['full', 'partial', 'anonymous'], SurveyResponse::CONSENTS);
    }

    // -------------------------------------------------------- effectiveConsent

    public function testTestResponsesAreStoredAsFullWhateverWasChosen(): void
    {
        // A builder's own "Submit as test" row belongs to the builder, so it is
        // stored linked (and excluded from reporting) regardless of the choice.
        $this->assertSame('full', SurveyResponse::effectiveConsent(true, 'anonymous', true));
        $this->assertSame('full', SurveyResponse::effectiveConsent(false, 'partial', true));
    }

    public function testDisabledDataGateForcesAnonymous(): void
    {
        // There is no "always link" option: no gate means no identity, ever.
        $this->assertSame('anonymous', SurveyResponse::effectiveConsent(false, 'full', false));
        $this->assertSame('anonymous', SurveyResponse::effectiveConsent(false, 'partial', false));
    }

    public function testEnabledDataGateHonoursTheChoice(): void
    {
        $this->assertSame('full', SurveyResponse::effectiveConsent(true, 'full', false));
        $this->assertSame('partial', SurveyResponse::effectiveConsent(true, 'partial', false));
        $this->assertSame('anonymous', SurveyResponse::effectiveConsent(true, 'anonymous', false));
    }

    public function testUnknownChoiceFailsClosedToAnonymous(): void
    {
        $this->assertSame('anonymous', SurveyResponse::effectiveConsent(true, 'FULL', false));
        $this->assertSame('anonymous', SurveyResponse::effectiveConsent(true, '', false));
        $this->assertSame('anonymous', SurveyResponse::effectiveConsent(true, 'everything', false));
    }

    public function testParkSnapshotSurvivesOnlyFullConsent(): void
    {
        $row = [
            'mundane_id' => 5, 'kingdom_id' => 17, 'park_id' => 1049, 'tenure_months' => 40,
            'started_at' => '2026-09-10 10:00:00', 'submitted_at' => '2026-09-10 10:05:00', 'duration_seconds' => 300,
        ];
        $this->assertSame(1049, SurveyResponse::scrubForConsent($row, 'full')['park_id']);
        $this->assertNull(SurveyResponse::scrubForConsent($row, 'partial')['park_id']);
        $this->assertNull(SurveyResponse::scrubForConsent($row, 'anonymous')['park_id']);
    }

    public function testScrubAddsAMissingParkKeyAsNull(): void
    {
        $out = SurveyResponse::scrubForConsent(['mundane_id' => 5, 'submitted_at' => '2026-09-10 10:05:00'], 'full');
        $this->assertArrayHasKey('park_id', $out);
        $this->assertNull($out['park_id']);
    }

    public function testKingdomIdListIsPublicAndDecodesLists(): void
    {
        $this->assertNull(SurveyResponse::kingdomIdList(null));
        $this->assertSame([17, 4], SurveyResponse::kingdomIdList('[17,4]'));
    }
}
