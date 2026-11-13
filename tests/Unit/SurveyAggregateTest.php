<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for SurveyReport's pure aggregation maths (spec §4 "Aggregate shape").
 *
 * SurveyReport::aggregateType() and ::displayAnswer() are deliberately free of SQL so
 * the numbers behind every chart on the results page can be pinned without a database.
 * Answer rows are shaped exactly as ork_survey_answer returns them
 * (response_id, option_id, row_option_id, value_text, value_num).
 */
final class SurveyAggregateTest extends TestCase
{
    /** @return array{option_id:int,role:string,sort_order:int,label:string,value_num:?float,is_other:int} */
    private function opt(int $id, string $label, string $role = 'choice', ?float $valueNum = null, int $isOther = 0): array
    {
        return [
            'option_id'  => $id,
            'role'       => $role,
            'sort_order' => $id,
            'label'      => $label,
            'value_num'  => $valueNum,
            'is_other'   => $isOther,
        ];
    }

    /** @return array{response_id:int,option_id:?int,row_option_id:?int,value_text:?string,value_num:?float} */
    private function row(int $responseId, ?int $optionId = null, ?float $valueNum = null, ?string $valueText = null, ?int $rowOptionId = null): array
    {
        return [
            'response_id'   => $responseId,
            'option_id'     => $optionId,
            'row_option_id' => $rowOptionId,
            'value_text'    => $valueText,
            'value_num'     => $valueNum,
        ];
    }

    // ---------------------------------------------------------------- filters

    public function testNormalizeFiltersDefaults(): void
    {
        $this->assertSame(SurveyReport::DEFAULT_FILTERS, SurveyReport::normalizeFilters(null));
        $this->assertSame(SurveyReport::DEFAULT_FILTERS, SurveyReport::normalizeFilters('not json'));
    }

    public function testNormalizeFiltersCoercesJson(): void
    {
        $f = SurveyReport::normalizeFilters('{"kingdom_ids":["17","17","bad",4],"consent":"partial","date_from":"2026-01-02","date_to":"nope","crosstab_question_id":"9","include_test":1}');
        $this->assertSame([17, 4], $f['kingdom_ids']);
        $this->assertSame('partial', $f['consent']);
        $this->assertSame('2026-01-02', $f['date_from']);
        $this->assertNull($f['date_to']);
        $this->assertSame(9, $f['crosstab_question_id']);
        $this->assertTrue($f['include_test']);
    }

    public function testNormalizeFiltersRejectsUnknownConsent(): void
    {
        $this->assertSame('any', SurveyReport::normalizeFilters(['consent' => 'everything'])['consent']);
    }

    // ----------------------------------------------------------------- single

    public function testAggregateSingleCountsAndPercents(): void
    {
        $options = [$this->opt(10, 'Alpha'), $this->opt(11, 'Other', 'choice', null, 1)];
        $rows = [
            $this->row(1, 10),
            $this->row(2, 10),
            $this->row(3, 10),
            $this->row(4, 11, null, 'Bardic'),
        ];
        $a = SurveyReport::aggregateType('single', $rows, $options, []);

        $this->assertSame(4, $a['n']);
        $this->assertSame(10, $a['counts'][0]['option_id']);
        $this->assertSame(3, $a['counts'][0]['count']);
        $this->assertSame(75.0, $a['counts'][0]['pct']);
        $this->assertSame(1, $a['counts'][1]['count']);
        $this->assertSame(25.0, $a['counts'][1]['pct']);
        $this->assertSame(['Bardic'], $a['other_texts']);
    }

    public function testAggregateSingleEmpty(): void
    {
        $a = SurveyReport::aggregateType('single', [], [$this->opt(10, 'Alpha')], []);
        $this->assertSame(0, $a['n']);
        $this->assertSame(0, $a['counts'][0]['count']);
        // Nobody answered: no percentage at all, never a plotted 0% (review #29).
        $this->assertNull($a['counts'][0]['pct']);
    }

    public function testAggregateEmptyGroupsReportNullNotZero(): void
    {
        $multi = SurveyReport::aggregateType('multi', [], [$this->opt(10, 'Alpha')], []);
        $this->assertSame(0, $multi['n']);
        $this->assertNull($multi['mean_selected']);

        $nps = SurveyReport::aggregateType('nps', [], [], []);
        $this->assertSame(0, $nps['n']);
        $this->assertNull($nps['score']);
        $this->assertNull($nps['mean']);

        $rating = SurveyReport::aggregateType('rating', [], [], ['min' => 1, 'max' => 5]);
        $this->assertSame(0, $rating['n']);
        $this->assertNull($rating['mean']);
        $this->assertNull($rating['median']);
    }

    public function testAggregateYesNoUsesSingleShape(): void
    {
        $options = [$this->opt(20, 'Yes'), $this->opt(21, 'No')];
        $a = SurveyReport::aggregateType('yesno', [$this->row(1, 20), $this->row(2, 21)], $options, []);
        $this->assertSame(2, $a['n']);
        $this->assertSame(50.0, $a['counts'][0]['pct']);
    }

    // ------------------------------------------------------------------ multi

    public function testAggregateMultiPercentOfRespondents(): void
    {
        $options = [$this->opt(10, 'Alpha'), $this->opt(11, 'Beta')];
        $rows = [
            $this->row(1, 10),
            $this->row(2, 10),
            $this->row(3, 10),
            $this->row(1, 11),
        ];
        $a = SurveyReport::aggregateType('multi', $rows, $options, []);

        $this->assertSame(3, $a['n']);
        $this->assertSame(3, $a['counts'][0]['count']);
        $this->assertSame(100.0, $a['counts'][0]['pct']);
        $this->assertSame(1, $a['counts'][1]['count']);
        $this->assertSame(33.3, $a['counts'][1]['pct']);
        $this->assertSame(1.333, $a['mean_selected']);
    }

    // ----------------------------------------------------------------- rating

    public function testAggregateRatingMeanMedianDistribution(): void
    {
        $rows = [$this->row(1, null, 5.0), $this->row(2, null, 4.0), $this->row(3, null, 4.0), $this->row(4, null, 2.0)];
        $a = SurveyReport::aggregateType('rating', $rows, [], ['min' => 1, 'max' => 5]);

        $this->assertSame(4, $a['n']);
        $this->assertSame(3.75, $a['mean']);
        $this->assertSame(4.0, $a['median']);
        $this->assertSame([1, 2, 3, 4, 5], array_column($a['distribution'], 'value'));
        $this->assertSame([0, 1, 0, 2, 1], array_column($a['distribution'], 'count'));
    }

    public function testAggregateRatingIgnoresOutOfRangeValues(): void
    {
        // A 9 stored before the scale was narrowed to 1..5 must not reach n or
        // the mean when the histogram cannot show it (review #32).
        $rows = [$this->row(1, null, 5.0), $this->row(2, null, 3.0), $this->row(3, null, 9.0), $this->row(4, null, 0.0)];
        $a = SurveyReport::aggregateType('rating', $rows, [], ['min' => 1, 'max' => 5]);

        $this->assertSame(2, $a['n']);
        $this->assertSame(4.0, $a['mean']);
        $this->assertSame(4.0, $a['median']);
        $this->assertSame($a['n'], array_sum(array_column($a['distribution'], 'count')));
    }

    // -------------------------------------------------------------------- nps

    public function testAggregateNpsScore(): void
    {
        $rows = [
            $this->row(1, null, 10.0),
            $this->row(2, null, 9.0),
            $this->row(3, null, 7.0),
            $this->row(4, null, 3.0),
            $this->row(5, null, 0.0),
        ];
        $a = SurveyReport::aggregateType('nps', $rows, [], []);

        $this->assertSame(5, $a['n']);
        $this->assertSame(2, $a['promoters']);
        $this->assertSame(1, $a['passives']);
        $this->assertSame(2, $a['detractors']);
        $this->assertSame(0.0, $a['score']);
        $this->assertCount(11, $a['distribution']);
        $this->assertSame(0, $a['distribution'][0]['value']);
        $this->assertSame(10, $a['distribution'][10]['value']);
    }

    // ----------------------------------------------------------------- matrix

    public function testAggregateMatrixWeightedMean(): void
    {
        $options = [
            $this->opt(100, 'Fighting', 'row'),
            $this->opt(101, 'Arts', 'row'),
            $this->opt(200, 'Never', 'column', 1.0),
            $this->opt(201, 'Sometimes', 'column', 2.0),
            $this->opt(202, 'Always', 'column', 3.0),
        ];
        $rows = [
            $this->row(1, 202, null, null, 100), $this->row(1, 200, null, null, 101),
            $this->row(2, 202, null, null, 100), $this->row(2, 201, null, null, 101),
            $this->row(3, 201, null, null, 100), $this->row(3, 200, null, null, 101),
        ];
        $a = SurveyReport::aggregateType('matrix', $rows, $options, []);

        $this->assertSame(3, $a['n']);
        $this->assertSame([200, 201, 202], array_column($a['columns'], 'option_id'));

        $this->assertSame(100, $a['rows'][0]['row_option_id']);
        $this->assertSame(3, $a['rows'][0]['n']);
        $this->assertSame([0, 1, 2], array_column($a['rows'][0]['counts'], 'count'));
        $this->assertSame(2.667, $a['rows'][0]['weighted_mean']);

        $this->assertSame([2, 1, 0], array_column($a['rows'][1]['counts'], 'count'));
        $this->assertSame(1.333, $a['rows'][1]['weighted_mean']);
    }

    public function testAggregateMatrixWeightedMeanNullWithoutWeights(): void
    {
        $options = [$this->opt(100, 'Fighting', 'row'), $this->opt(200, 'Never', 'column'), $this->opt(201, 'Always', 'column')];
        $a = SurveyReport::aggregateType('matrix', [$this->row(1, 201, null, null, 100)], $options, []);
        $this->assertNull($a['rows'][0]['weighted_mean']);
    }

    /** Review #34: an N/A column is left out of the mean instead of voiding it. */
    public function testAggregateMatrixMeanExcludesUnvaluedColumns(): void
    {
        $options = [
            $this->opt(100, 'Fighting', 'row'),
            $this->opt(200, 'Poor', 'column', 1.0),
            $this->opt(201, 'Good', 'column', 3.0),
            $this->opt(202, 'N/A', 'column'),
        ];
        $rows = [
            $this->row(1, 200, null, null, 100),
            $this->row(2, 201, null, null, 100),
            $this->row(3, 201, null, null, 100),
            $this->row(4, 202, null, null, 100),
        ];
        $a = SurveyReport::aggregateType('matrix', $rows, $options, []);
        $row = $a['rows'][0];
        $this->assertSame(4, $row['n'], 'N/A still counts toward the row and its percentages');
        $this->assertSame([25.0, 50.0, 25.0], array_column($row['counts'], 'pct'));
        $this->assertSame(2.333, $row['weighted_mean'], '(1 + 3 + 3) / 3, N/A excluded');
        $this->assertSame(3, $row['mean_n']);

        // One valued column is not a scale: no mean.
        $one = [$this->opt(100, 'Fighting', 'row'), $this->opt(200, 'Yes', 'column', 1.0), $this->opt(202, 'N/A', 'column')];
        $b = SurveyReport::aggregateType('matrix', [$this->row(1, 200, null, null, 100)], $one, []);
        $this->assertNull($b['rows'][0]['weighted_mean']);
        $this->assertNull($b['rows'][0]['mean_n']);

        // Only N/A answers: a scale, but nothing to average.
        $c = SurveyReport::aggregateType('matrix', [$this->row(1, 202, null, null, 100)], $options, []);
        $this->assertNull($c['rows'][0]['weighted_mean']);
        $this->assertSame(0, $c['rows'][0]['mean_n']);
    }

    public function testAggregateMatrixUnansweredRowHasNullPct(): void
    {
        $options = [
            $this->opt(100, 'Fighting', 'row'),
            $this->opt(101, 'Arts', 'row'),
            $this->opt(200, 'Never', 'column'),
            $this->opt(201, 'Always', 'column'),
        ];
        $a = SurveyReport::aggregateType('matrix', [$this->row(1, 201, null, null, 100)], $options, []);

        $this->assertSame([0.0, 100.0], array_column($a['rows'][0]['counts'], 'pct'));
        $this->assertSame(0, $a['rows'][1]['n']);
        $this->assertSame([0, 0], array_column($a['rows'][1]['counts'], 'count'));
        $this->assertSame([null, null], array_column($a['rows'][1]['counts'], 'pct'));
    }

    // ---------------------------------------------------------------- ranking

    public function testAggregateRankingBordaAndMeanRank(): void
    {
        $options = [$this->opt(10, 'A'), $this->opt(11, 'B'), $this->opt(12, 'C')];
        $rows = [
            $this->row(1, 10, 1.0), $this->row(1, 11, 2.0), $this->row(1, 12, 3.0),
            $this->row(2, 11, 1.0), $this->row(2, 10, 2.0), $this->row(2, 12, 3.0),
        ];
        $a = SurveyReport::aggregateType('ranking', $rows, $options, []);

        $this->assertSame(2, $a['n']);
        $this->assertSame(1.5, $a['options'][0]['mean_rank']);
        $this->assertSame(1.5, $a['options'][1]['mean_rank']);
        $this->assertSame(3.0, $a['options'][2]['mean_rank']);
        $this->assertSame(1, $a['options'][0]['first_count']);
        $this->assertSame(1, $a['options'][1]['first_count']);
        $this->assertSame(0, $a['options'][2]['first_count']);
        $this->assertSame(5, $a['options'][0]['score']);
        $this->assertSame(5, $a['options'][1]['score']);
        $this->assertSame(2, $a['options'][2]['score']);
    }

    // ----------------------------------------------------------------- number

    public function testAggregateNumberFewDistinctIntegersListsEachValue(): void
    {
        $rows = [
            $this->row(1, null, 1.0), $this->row(2, null, 2.0), $this->row(3, null, 3.0),
            $this->row(4, null, 3.0), $this->row(5, null, 100.0),
        ];
        $a = SurveyReport::aggregateType('number', $rows, [], []);

        $this->assertSame(5, $a['n']);
        $this->assertSame(21.8, $a['mean']);
        $this->assertSame(3.0, $a['median']);
        $this->assertSame(1.0, $a['min']);
        $this->assertSame(100.0, $a['max']);
        $this->assertSame('values', $a['mode']);
        $this->assertSame(
            [['value' => 1, 'count' => 1], ['value' => 2, 'count' => 1], ['value' => 3, 'count' => 2], ['value' => 100, 'count' => 1]],
            $a['values']
        );
        // Bins are still supplied, with whole-number edges.
        $this->assertSame(5, array_sum(array_column($a['bins'], 'count')));
        foreach ($a['bins'] as $b) {
            $this->assertIsInt($b['from']);
            $this->assertIsInt($b['to']);
        }
    }

    public function testAggregateNumberManyIntegersUseIntegerAlignedBins(): void
    {
        // 0..24 years played: 25 distinct whole numbers => binned, never '3.1–6.2'.
        $rows = [];
        for ($i = 0; $i <= 24; $i++) {
            $rows[] = $this->row($i + 1, null, (float)$i);
        }
        $a = SurveyReport::aggregateType('number', $rows, [], []);

        $this->assertSame('bins', $a['mode']);
        $this->assertSame([], $a['values']);
        $this->assertLessThanOrEqual(SurveyReport::NUMBER_MAX_BINS, count($a['bins']));
        $this->assertSame(0, $a['bins'][0]['from']);
        $this->assertSame(2, $a['bins'][0]['to']);
        $this->assertSame("0\u{2013}2", $a['bins'][0]['label']);
        $this->assertSame(3, $a['bins'][1]['from']);
        $this->assertSame(3, $a['bins'][0]['count']);
        $this->assertSame(25, array_sum(array_column($a['bins'], 'count')));
        // Contiguous, equal-width, integer edges.
        for ($i = 1, $c = count($a['bins']); $i < $c; $i++) {
            $this->assertSame($a['bins'][$i - 1]['to'] + 1, $a['bins'][$i]['from']);
        }
    }

    public function testAggregateNumberFractionalValuesUseEqualBins(): void
    {
        $rows = [$this->row(1, null, 0.5), $this->row(2, null, 1.25), $this->row(3, null, 5.5)];
        $a = SurveyReport::aggregateType('number', $rows, [], []);

        $this->assertSame('bins', $a['mode']);
        $this->assertSame([], $a['values']);
        $this->assertCount(SurveyReport::NUMBER_MAX_BINS, $a['bins']);
        $this->assertSame(0.5, $a['bins'][0]['from']);
        $this->assertSame("0.5\u{2013}1", $a['bins'][0]['label']);
        $this->assertSame(1, $a['bins'][9]['count']);   // the maximum lands in the last bin
        $this->assertSame(3, array_sum(array_column($a['bins'], 'count')));
    }

    public function testAggregateNumberSingleValueDoesNotDivideByZero(): void
    {
        $a = SurveyReport::aggregateType('number', [$this->row(1, null, 7.5), $this->row(2, null, 7.5)], [], []);
        $this->assertSame(7.5, $a['mean']);
        $this->assertCount(1, $a['bins']);
        $this->assertSame(2, $a['bins'][0]['count']);

        $int = SurveyReport::aggregateType('number', [$this->row(1, null, 7.0), $this->row(2, null, 7.0)], [], []);
        $this->assertSame('values', $int['mode']);
        $this->assertSame([['value' => 7, 'count' => 2]], $int['values']);
    }

    public function testAggregateNumberEmpty(): void
    {
        $a = SurveyReport::aggregateType('number', [], [], []);
        $this->assertSame(0, $a['n']);
        $this->assertNull($a['mean']);
        $this->assertSame([], $a['values']);
        $this->assertSame([], $a['bins']);
    }

    // ------------------------------------------------------------------- date

    public function testAggregateDateFillsEmptyMonths(): void
    {
        $rows = [
            $this->row(1, null, null, '2026-01-05'),
            $this->row(2, null, null, '2026-01-20'),
            $this->row(3, null, null, '2026-04-11'),
        ];
        $a = SurveyReport::aggregateType('date', $rows, [], []);

        $this->assertSame(3, $a['n']);
        $this->assertSame('2026-01-05', $a['min']);
        $this->assertSame('2026-04-11', $a['max']);
        $this->assertSame('month', $a['granularity']);
        $this->assertSame(['2026-01', '2026-02', '2026-03', '2026-04'], array_column($a['periods'], 'period'));
        $this->assertSame(['Jan 2026', 'Feb 2026', 'Mar 2026', 'Apr 2026'], array_column($a['periods'], 'label'));
        $this->assertSame([2, 0, 0, 1], array_column($a['periods'], 'count'));
    }

    public function testAggregateDateMonthFillCrossesYearBoundary(): void
    {
        $rows = [$this->row(1, null, null, '2025-11-30'), $this->row(2, null, null, '2026-02-01')];
        $a = SurveyReport::aggregateType('date', $rows, [], []);
        $this->assertSame(['2025-11', '2025-12', '2026-01', '2026-02'], array_column($a['periods'], 'period'));
        $this->assertSame([1, 0, 0, 1], array_column($a['periods'], 'count'));
    }

    public function testAggregateDateLongSpanGroupsByYear(): void
    {
        // First-event dates across years: > 36 months => one bar per year,
        // empty years included.
        $rows = [
            $this->row(1, null, null, '2019-03-01'),
            $this->row(2, null, null, '2019-09-12'),
            $this->row(3, null, null, '2022-06-30'),
        ];
        $a = SurveyReport::aggregateType('date', $rows, [], []);

        $this->assertSame('year', $a['granularity']);
        $this->assertSame(['2019', '2020', '2021', '2022'], array_column($a['periods'], 'period'));
        $this->assertSame(['2019', '2020', '2021', '2022'], array_column($a['periods'], 'label'));
        $this->assertSame([2, 0, 0, 1], array_column($a['periods'], 'count'));
    }

    public function testAggregateDateExactlyThirtySixMonthsStaysMonthly(): void
    {
        $a = SurveyReport::aggregateType('date', [$this->row(1, null, null, '2023-01-01'), $this->row(2, null, null, '2026-01-31')], [], []);
        $this->assertSame('month', $a['granularity']);
        $this->assertCount(37, $a['periods']);
    }

    public function testAggregateDateAbsurdSpanDoesNotExplode(): void
    {
        $a = SurveyReport::aggregateType('date', [$this->row(1, null, null, '0001-01-01'), $this->row(2, null, null, '2026-01-01')], [], []);
        $this->assertSame('year', $a['granularity']);
        $this->assertCount(2, $a['periods']);
    }

    // ------------------------------------------------------------------- text

    public function testAggregateTextCountsNonEmpty(): void
    {
        $rows = [$this->row(1, null, null, 'Fun'), $this->row(2, null, null, ''), $this->row(3, null, null, 'More fun')];
        $a = SurveyReport::aggregateType('paragraph', $rows, [], []);
        $this->assertSame(2, $a['n']);
        $this->assertSame(['Fun', 'More fun'], $a['texts']);
    }

    public function testAggregateNonAnswerableTypes(): void
    {
        $this->assertSame(0, SurveyReport::aggregateType('section', [], [], [])['n']);
        $this->assertSame(0, SurveyReport::aggregateType('image', [], [], [])['n']);
    }

    // ---------------------------------------------------------- displayAnswer

    public function testDisplayAnswerChoiceJoinsLabels(): void
    {
        $byId = [10 => $this->opt(10, 'Alpha'), 11 => $this->opt(11, 'Beta')];
        $this->assertSame('Alpha; Beta', SurveyReport::displayAnswer('multi', [$this->row(1, 10), $this->row(1, 11)], $byId));
    }

    public function testDisplayAnswerOtherWriteIn(): void
    {
        $byId = [11 => $this->opt(11, 'Other', 'choice', null, 1)];
        $this->assertSame('Other: Bardic', SurveyReport::displayAnswer('single', [$this->row(1, 11, null, 'Bardic')], $byId));
    }

    public function testDisplayAnswerNumeric(): void
    {
        $this->assertSame('4', SurveyReport::displayAnswer('rating', [$this->row(1, null, 4.0)], []));
        $this->assertSame('2.5', SurveyReport::displayAnswer('number', [$this->row(1, null, 2.5)], []));
    }

    public function testDisplayAnswerMatrix(): void
    {
        $byId = [
            100 => $this->opt(100, 'Fighting', 'row'),
            101 => $this->opt(101, 'Arts', 'row'),
            200 => $this->opt(200, 'Never', 'column'),
            201 => $this->opt(201, 'Always', 'column'),
        ];
        $rows = [$this->row(1, 201, null, null, 100), $this->row(1, 200, null, null, 101)];
        $this->assertSame('Fighting: Always | Arts: Never', SurveyReport::displayAnswer('matrix', $rows, $byId));
    }

    public function testDisplayAnswerRankingOrdersByRank(): void
    {
        $byId = [10 => $this->opt(10, 'A'), 11 => $this->opt(11, 'B')];
        $rows = [$this->row(1, 11, 2.0), $this->row(1, 10, 1.0)];
        $this->assertSame('1. A 2. B', SurveyReport::displayAnswer('ranking', $rows, $byId));
    }

    public function testDisplayAnswerTextAndDate(): void
    {
        $this->assertSame('Well met', SurveyReport::displayAnswer('short_text', [$this->row(1, null, null, 'Well met')], []));
        $this->assertSame('2026-02-28', SurveyReport::displayAnswer('date', [$this->row(1, null, null, '2026-02-28')], []));
        $this->assertSame('', SurveyReport::displayAnswer('single', [], []));
    }

    // ------------------------------------------------- min cell / narrowing (#4)

    public function testIsNarrowing(): void
    {
        $this->assertFalse(SurveyReport::isNarrowing([]));
        $this->assertFalse(SurveyReport::isNarrowing(['include_test' => true, 'crosstab_question_id' => 9]));
        $this->assertTrue(SurveyReport::isNarrowing(['kingdom_ids' => [17]]));
        $this->assertTrue(SurveyReport::isNarrowing(['consent' => 'anonymous']));
        $this->assertTrue(SurveyReport::isNarrowing(['date_from' => '2026-01-01']));
        $this->assertTrue(SurveyReport::isNarrowing(['date_to' => '2026-01-01']));
        $this->assertFalse(SurveyReport::isNarrowing(['consent' => 'any', 'kingdom_ids' => []]));
    }

    public function testIsSuppressedOnlyForNarrowedSmallTotals(): void
    {
        $this->assertTrue(SurveyReport::isSuppressed(true, SurveyReport::MIN_CELL - 1));
        $this->assertTrue(SurveyReport::isSuppressed(true, 0));
        $this->assertFalse(SurveyReport::isSuppressed(true, SurveyReport::MIN_CELL));
        // The whole survey is never suppressed, however small.
        $this->assertFalse(SurveyReport::isSuppressed(false, 1));
    }

    public function testCrosstabGroupSuppressesSmallCellsButKeepsEmptyAndLarge(): void
    {
        $small = SurveyReport::aggregateType('rating', [$this->row(1, null, 5.0), $this->row(2, null, 1.0)], [], ['min' => 1, 'max' => 5]);
        $g = SurveyReport::crosstabGroup(10, 'Druid', $small);
        $this->assertSame(['option_id' => 10, 'label' => 'Druid', 'n' => null, 'suppressed' => true], $g);
        $this->assertArrayNotHasKey('agg', $g);

        $empty = SurveyReport::aggregateType('nps', [], [], []);
        $g = SurveyReport::crosstabGroup(11, 'Paladin', $empty);
        $this->assertSame(0, $g['n']);
        $this->assertNull($g['agg']['score']);
        $this->assertArrayNotHasKey('suppressed', $g);

        $rows = [];
        for ($i = 1; $i <= SurveyReport::MIN_CELL; $i++) {
            $rows[] = $this->row($i, null, 4.0);
        }
        $big = SurveyReport::aggregateType('rating', $rows, [], ['min' => 1, 'max' => 5]);
        $g = SurveyReport::crosstabGroup(12, 'Bard', $big);
        $this->assertSame(SurveyReport::MIN_CELL, $g['n']);
        $this->assertSame(4.0, $g['agg']['mean']);
    }

    public function testCrosstabSourcesAreSingleBucketTypesOnly(): void
    {
        $this->assertSame(['single', 'dropdown', 'yesno'], SurveyReport::CROSSTAB_SOURCES);
        $this->assertNotContains('multi', SurveyReport::CROSSTAB_SOURCES);
        $this->assertNotContains('ranking', SurveyReport::CROSSTAB_SOURCES);
    }

    public function testPartialVisibilityNeedsMinCellPeers(): void
    {
        $cells = [
            'k'  => ['17' => 7, '4' => 2],
            'kb' => ['17|36' => 5, '17|72' => 2, '4|0' => 2],
        ];
        // Kingdom shown (7 share it); band 3–5 shown (5 share it). 40 months folds into the 36 band.
        $this->assertSame(['kingdom' => true, 'tenure' => true], SurveyReport::partialVisibility(17, 40, $cells));
        // Kingdom shown but band 6–10 has only 2 peers.
        $this->assertSame(['kingdom' => true, 'tenure' => false], SurveyReport::partialVisibility(17, 80, $cells));
        // A 2-person kingdom hides both.
        $this->assertSame(['kingdom' => false, 'tenure' => false], SurveyReport::partialVisibility(4, 3, $cells));
        // Nothing stored, nothing to show.
        $this->assertSame(['kingdom' => false, 'tenure' => false], SurveyReport::partialVisibility(null, null, $cells));
    }

    public function testComplementaryCellsStopABandBeingRecoveredByElimination(): void
    {
        // Kingdom 17 shows 4 of the 5 bands; one row sits alone in 3–5 years.
        // With only one band unshown the hidden row's band is certain, so the
        // smallest shown band (0, 5 rows; tie with 72 broken by key) goes too.
        $cells = [
            'k'  => ['17' => 26],
            'kb' => ['17|0' => 5, '17|12' => 7, '17|36' => 1, '17|72' => 5, '17|132' => 8],
        ];
        $forced = SurveyReport::complementaryCells($cells, 1);
        $this->assertSame([], $forced['k']);
        $this->assertSame(['17|0' => true], $forced['kb']);

        $cells['forced_k'] = $forced['k'];
        $cells['forced_kb'] = $forced['kb'];
        $this->assertSame(['kingdom' => true, 'tenure' => false], SurveyReport::partialVisibility(17, 3, $cells));
        $this->assertSame(['kingdom' => true, 'tenure' => true], SurveyReport::partialVisibility(17, 20, $cells));

        // Three bands shown leaves two candidate bands, but the lone masked row
        // is still fewer than MIN_CELL people: the smallest shown band (72, 5
        // rows) is masked with it, so the masked group is 6 rows.
        $three = ['k' => ['17' => 19], 'kb' => ['17|12' => 7, '17|36' => 1, '17|72' => 5, '17|132' => 6]];
        $this->assertSame(['k' => [], 'kb' => ['17|72' => true]], SurveyReport::complementaryCells($three, 1));

        // Two candidate bands AND a masked group of MIN_CELL: nothing extra withheld.
        $enough = ['k' => ['17' => 23], 'kb' => ['17|12' => 7, '17|36' => 3, '17|72' => 2, '17|132' => 11]];
        $this->assertSame(['k' => [], 'kb' => []], SurveyReport::complementaryCells($enough, 1));

        // No withheld band, nothing to protect, even with all five shown.
        $all = ['k' => ['17' => 25], 'kb' => ['17|0' => 5, '17|12' => 5, '17|36' => 5, '17|72' => 5, '17|132' => 5]];
        $this->assertSame(['k' => [], 'kb' => []], SurveyReport::complementaryCells($all, 1));
    }

    public function testComplementaryCellsMaskUntilTheMaskedRowsReachMinCell(): void
    {
        // Kingdom 17: two lone rows (0 and 36) hidden, three bands shown. Two
        // masked rows are "not in 12, 72 or 132" — a group of 2. The smallest
        // shown band (72, 5) goes first, making 7 masked rows; that is enough.
        $cells = [
            'k'  => ['17' => 26, '9' => 12],
            'kb' => [
                '17|0' => 1, '17|12' => 9, '17|36' => 1, '17|72' => 5, '17|132' => 10,
                // Kingdom 9 has no small band at all: never touched.
                '9|12' => 6, '9|72' => 6,
            ],
        ];
        $forced = SurveyReport::complementaryCells($cells, 40);
        $this->assertSame([], $forced['k']);
        $this->assertSame(['17|72' => true], $forced['kb']);

        $cells['forced_k'] = $forced['k'];
        $cells['forced_kb'] = $forced['kb'];
        $this->assertSame(['kingdom' => true, 'tenure' => false], SurveyReport::partialVisibility(17, 80, $cells));
        $this->assertSame(['kingdom' => true, 'tenure' => true], SurveyReport::partialVisibility(17, 20, $cells));
        $this->assertSame(['kingdom' => true, 'tenure' => true], SurveyReport::partialVisibility(9, 80, $cells));

        // One masked row beside two 5-row bands: the first 5-row band (key
        // order breaks the tie) brings the masked group to 6 — enough.
        $tie = ['k' => ['17' => 11], 'kb' => ['17|12' => 5, '17|36' => 1, '17|72' => 5]];
        $this->assertSame(['17|12' => true], SurveyReport::complementaryCells($tie, 1)['kb']);

        // A kingdom with a single shown band and one lone row: the shown band
        // goes too, so every band in the kingdom is masked.
        $one = ['k' => ['17' => 6], 'kb' => ['17|12' => 1, '17|36' => 5]];
        $this->assertSame(['17|36' => true], SurveyReport::complementaryCells($one, 1)['kb']);
    }

    public function testViewForcedCellsReapplyTheRuleToAFilteredView(): void
    {
        // Survey-wide, kingdom 17 is two healthy bands: nothing is withheld.
        $surveyCells = ['k' => ['17' => 30], 'kb' => ['17|12' => 20, '17|36' => 10]];
        $surveyForced = SurveyReport::complementaryCells($surveyCells, 1);
        $this->assertSame(['k' => [], 'kb' => []], $surveyForced);

        // A date filter leaves 6 rows in 1–2 years and 2 in 3–5 years. The
        // survey-wide set alone would show the 6 and mask only the 2 — two
        // people "in kingdom 17, not 1–2 years". The view's own rule masks
        // the 6 as well, so the masked group is 8.
        $viewCells = ['k' => ['17' => 8], 'kb' => ['17|12' => 6, '17|36' => 2]];
        $forced = SurveyReport::viewForcedCells($surveyForced, $viewCells, 1);
        $this->assertSame([], $forced['k']);
        $this->assertSame(['17|12' => true], $forced['kb']);

        $viewCells['forced_k'] = $forced['k'];
        $viewCells['forced_kb'] = $forced['kb'];
        $this->assertSame(['kingdom' => true, 'tenure' => false], SurveyReport::partialVisibility(17, 20, $viewCells));
        $this->assertSame(['kingdom' => true, 'tenure' => false], SurveyReport::partialVisibility(17, 40, $viewCells));

        // The survey-wide set always survives into the view, even where the
        // view's own counts would not force it (never less masking than before).
        $wide = ['k' => [], 'kb' => ['17|72' => true]];
        $healthy = ['k' => ['17' => 15], 'kb' => ['17|72' => 5, '17|132' => 10]];
        $this->assertSame(['k' => [], 'kb' => ['17|72' => true]], SurveyReport::viewForcedCells($wide, $healthy, 1));

        // An unfiltered view (same cells as the survey) adds nothing new.
        $this->assertSame($surveyForced, SurveyReport::viewForcedCells($surveyForced, $surveyCells, 1));
    }

    public function testCrosstabGroupsWithholdAComplementSoSubtractionCannotRecoverASmallGroup(): void
    {
        $rating = function (int $n, float $v): array {
            $rows = [];
            for ($i = 1; $i <= $n; $i++) {
                $rows[] = $this->row($i, null, $v);
            }
            return SurveyReport::aggregateType('rating', $rows, [], ['min' => 1, 'max' => 5]);
        };

        // Druid 2 (withheld), Paladin 0 (empty), Bard 6, Healer 9. Overall minus
        // Bard minus Healer would give Druid exactly, so Bard (the smallest
        // visible non-empty group) goes too: 8 withheld answers.
        $groups = SurveyReport::crosstabGroups([
            ['option_id' => 10, 'label' => 'Druid',   'sub' => $rating(2, 1.0)],
            ['option_id' => 11, 'label' => 'Paladin', 'sub' => $rating(0, 1.0)],
            ['option_id' => 12, 'label' => 'Bard',    'sub' => $rating(6, 4.0)],
            ['option_id' => 13, 'label' => 'Healer',  'sub' => $rating(9, 5.0)],
        ]);
        $this->assertSame([10, 11, 12, 13], array_column($groups, 'option_id'));
        $this->assertTrue($groups[0]['suppressed']);
        $this->assertSame(0, $groups[1]['n']);
        $this->assertArrayNotHasKey('suppressed', $groups[1]);
        $this->assertSame(['option_id' => 12, 'label' => 'Bard', 'n' => null, 'suppressed' => true], $groups[2]);
        $this->assertSame(9, $groups[3]['n']);
        $this->assertSame(5.0, $groups[3]['agg']['mean']);

        // Two small groups already total MIN_CELL: nothing else is withheld.
        $groups = SurveyReport::crosstabGroups([
            ['option_id' => 1, 'label' => 'A', 'sub' => $rating(3, 2.0)],
            ['option_id' => 2, 'label' => 'B', 'sub' => $rating(2, 2.0)],
            ['option_id' => 3, 'label' => 'C', 'sub' => $rating(7, 3.0)],
        ]);
        $this->assertTrue($groups[0]['suppressed']);
        $this->assertTrue($groups[1]['suppressed']);
        $this->assertSame(7, $groups[2]['n']);

        // One tiny group beside one visible group: both go (nothing may stay
        // visible whose complement is a group of 1).
        $groups = SurveyReport::crosstabGroups([
            ['option_id' => 1, 'label' => 'Yes', 'sub' => $rating(1, 2.0)],
            ['option_id' => 2, 'label' => 'No',  'sub' => $rating(20, 3.0)],
        ]);
        $this->assertTrue($groups[0]['suppressed']);
        $this->assertTrue($groups[1]['suppressed']);

        // Ties go to the earlier option.
        $groups = SurveyReport::crosstabGroups([
            ['option_id' => 1, 'label' => 'A', 'sub' => $rating(1, 2.0)],
            ['option_id' => 2, 'label' => 'B', 'sub' => $rating(5, 2.0)],
            ['option_id' => 3, 'label' => 'C', 'sub' => $rating(5, 2.0)],
        ]);
        $this->assertTrue($groups[1]['suppressed']);
        $this->assertSame(5, $groups[2]['n']);

        // No withheld group: nothing changes.
        $groups = SurveyReport::crosstabGroups([
            ['option_id' => 1, 'label' => 'A', 'sub' => $rating(5, 2.0)],
            ['option_id' => 2, 'label' => 'B', 'sub' => $rating(0, 2.0)],
        ]);
        $this->assertSame(5, $groups[0]['n']);
        $this->assertSame(0, $groups[1]['n']);
    }

    public function testCrosstabGroupsCountTheSkippedSourceResidualAsWithheld(): void
    {
        $rating = function (int $n, float $v): array {
            $rows = [];
            for ($i = 1; $i <= $n; $i++) {
                $rows[] = $this->row($i, null, $v);
            }
            return SurveyReport::aggregateType('rating', $rows, [], ['min' => 1, 'max' => 5]);
        };

        // 22 answered the target; Yes 11, No 10, so 1 person skipped the
        // source question. Overall minus Yes minus No would isolate that one
        // person, so No (the smallest visible group) is withheld: 11 hidden.
        $groups = SurveyReport::crosstabGroups([
            ['option_id' => 1, 'label' => 'Yes', 'sub' => $rating(11, 4.0)],
            ['option_id' => 2, 'label' => 'No',  'sub' => $rating(10, 2.0)],
        ], 1);
        $this->assertSame(11, $groups[0]['n']);
        $this->assertArrayNotHasKey('suppressed', $groups[0]);
        $this->assertSame(['option_id' => 2, 'label' => 'No', 'n' => null, 'suppressed' => true], $groups[1]);

        // A residual of MIN_CELL with no small group: nothing is withheld.
        $groups = SurveyReport::crosstabGroups([
            ['option_id' => 1, 'label' => 'Yes', 'sub' => $rating(11, 4.0)],
            ['option_id' => 2, 'label' => 'No',  'sub' => $rating(10, 2.0)],
        ], SurveyReport::MIN_CELL);
        $this->assertSame(11, $groups[0]['n']);
        $this->assertSame(10, $groups[1]['n']);

        // A residual of 2 beside a withheld group of 1: 3 hidden is still
        // under MIN_CELL, so the smallest visible group goes too.
        $groups = SurveyReport::crosstabGroups([
            ['option_id' => 1, 'label' => 'A', 'sub' => $rating(1, 2.0)],
            ['option_id' => 2, 'label' => 'B', 'sub' => $rating(6, 3.0)],
            ['option_id' => 3, 'label' => 'C', 'sub' => $rating(9, 4.0)],
        ], 2);
        $this->assertTrue($groups[0]['suppressed']);
        $this->assertTrue($groups[1]['suppressed']);
        $this->assertSame(9, $groups[2]['n']);

        // A negative residual is treated as 0 (the default behaviour).
        $groups = SurveyReport::crosstabGroups([
            ['option_id' => 1, 'label' => 'Yes', 'sub' => $rating(11, 4.0)],
            ['option_id' => 2, 'label' => 'No',  'sub' => $rating(10, 2.0)],
        ], -3);
        $this->assertSame(11, $groups[0]['n']);
        $this->assertSame(10, $groups[1]['n']);
    }

    public function testComplementaryCellsWithholdAKingdomWhenTheScopeLeavesOneCandidate(): void
    {
        // A kingdom survey whose kingdom has one principality: the kingdom
        // shows, the principality's lone partial row is hidden — and by scope
        // it can only be the principality. The kingdom is withheld with it.
        $cells = [
            'k'  => ['17' => 9, '40' => 1],
            'kb' => ['17|12' => 5, '17|72' => 4, '40|0' => 1],
        ];
        $forced = SurveyReport::complementaryCells($cells, 2);
        $this->assertSame(['17' => true], $forced['k']);
        $this->assertSame([], $forced['kb']);

        $cells['forced_k'] = $forced['k'];
        $cells['forced_kb'] = $forced['kb'];
        // The withheld kingdom takes its (otherwise visible) band with it.
        $this->assertSame(['kingdom' => false, 'tenure' => false], SurveyReport::partialVisibility(17, 20, $cells));

        // Two principalities leave two candidates: nothing extra withheld.
        $this->assertSame([], SurveyReport::complementaryCells(
            ['k' => ['17' => 9, '40' => 1], 'kb' => []],
            3
        )['k']);
    }

    public function testSmallPartialKingdomsAreThoseWithOneToFourPartialRows(): void
    {
        // 17 has 7 (kept), 4 has 2 and 9 has 4 (left out), 12 has exactly 5 (kept),
        // 3 has none (nothing to leave out), 0 is not a kingdom.
        $this->assertSame(
            [4, 9],
            SurveyReport::smallPartialKingdoms([17 => 7, 9 => 4, 4 => 2, 12 => 5, 3 => 0, 0 => 1])
        );
        $this->assertSame([], SurveyReport::smallPartialKingdoms([]));
    }

    public function testKingdomFilterCountDropsSmallPartialCells(): void
    {
        // A principality with 2 partial and no full rows shows nothing at all.
        $this->assertSame(0, SurveyReport::kingdomFilterCount(0, 2));
        // Its full rows still count; its 4 partial rows do not.
        $this->assertSame(3, SurveyReport::kingdomFilterCount(3, 4));
        // At MIN_CELL the partial rows count too.
        $this->assertSame(8, SurveyReport::kingdomFilterCount(3, SurveyReport::MIN_CELL));
        $this->assertSame(10, SurveyReport::kingdomFilterCount(10, 0));
    }

    public function testTenureLabelExactForFullBandForPartial(): void
    {
        $this->assertSame('14 years', SurveyReport::tenureLabel('full', 14 * 12 + 5));
        $this->assertSame('1 year', SurveyReport::tenureLabel('full', 12));
        $this->assertSame('0 years', SurveyReport::tenureLabel('full', 7));
        $this->assertSame("3\u{2013}5 years", SurveyReport::tenureLabel('partial', 40));
        $this->assertSame('Over 10 years', SurveyReport::tenureLabel('partial', 168));
        $this->assertSame('Under 1 year', SurveyReport::tenureLabel('partial', 0));
    }

    // ------------------------------------------------------ completion (#28)

    public function testCompletionRate(): void
    {
        $this->assertSame(0.75, SurveyReport::completionRate(30, 40, false));
        $this->assertNull(SurveyReport::completionRate(30, 0, false));     // no starts recorded
        $this->assertNull(SurveyReport::completionRate(30, 20, false));    // responses predate start tracking
        $this->assertNull(SurveyReport::completionRate(30, 40, true));     // starts cannot be filtered
        $this->assertSame(1.0, SurveyReport::completionRate(40, 40, false));
    }

    // ------------------------------------------------------- reached (#35)

    public function testReachedCountHonoursQuestionAndPageShowIf(): void
    {
        // Q5 (yes/no): r1 and r2 chose Yes (option 50), r3 chose No (51).
        $answers = [
            5 => [$this->row(1, 50), $this->row(2, 50), $this->row(3, 51)],
            6 => [$this->row(1, 60), $this->row(3, 61)],
        ];
        $sel = SurveyReport::selectionsByResponse($answers);
        $this->assertSame([5 => [50], 6 => [60]], $sel[1]);

        $plain = ['show_if_question_id' => 0, 'show_if_option_id' => 0, 'page_show_if_question_id' => 0, 'page_show_if_option_id' => 0];
        $this->assertSame(10, SurveyReport::reachedCount($plain, 10, $sel));

        $ifYes = ['show_if_question_id' => 5, 'show_if_option_id' => 50] + $plain;
        $this->assertSame(2, SurveyReport::reachedCount($ifYes, 10, $sel));

        // The page's rule AND the question's rule must both hold: only r1.
        $both = ['page_show_if_question_id' => 6, 'page_show_if_option_id' => 60] + $ifYes;
        $this->assertSame(1, SurveyReport::reachedCount($both, 10, $sel));

        // A half-set rule is no rule (SurveyTypes::isShown semantics).
        $half = ['show_if_question_id' => 5, 'show_if_option_id' => 0] + $plain;
        $this->assertSame(10, SurveyReport::reachedCount($half, 10, $sel));
    }

    // ------------------------------------------------- display order (#1)

    public function testDisplayOrderIsKeyedNotSubmissionOrder(): void
    {
        $key = 'test-order-key';
        $rows = [];
        for ($rid = 1; $rid <= 12; $rid++) {
            $rows[] = ['response_id' => $rid, 'day' => '2026-09-10', 'value_text' => 'r' . $rid];
        }
        $out = SurveyReport::displayOrder($rows, $key);

        $ids = array_column($out, 'response_id');
        $this->assertNotSame(range(1, 12), $ids, 'text lists must not come back in answer_id order');
        $this->assertEqualsCanonicalizing(range(1, 12), $ids);

        // Exactly the permutation responsePage's SQL applies: MD5(CONCAT(response_id, key)).
        $expected = range(1, 12);
        usort($expected, static function ($a, $b) use ($key) {
            return strcmp(md5($a . $key), md5($b . $key));
        });
        $this->assertSame($expected, $ids);

        // Deterministic for a key, different for another.
        $this->assertSame($ids, array_column(SurveyReport::displayOrder(array_reverse($rows), $key), 'response_id'));
        $this->assertNotSame($ids, array_column(SurveyReport::displayOrder($rows, 'other-key'), 'response_id'));
    }

    public function testDisplayOrderSortsByDayFirstAndKeepsAResponsesRowsTogether(): void
    {
        $rows = [
            ['response_id' => 1, 'day' => '2026-09-11', 'option_id' => 1],
            ['response_id' => 2, 'day' => '2026-09-10', 'option_id' => 2],
            ['response_id' => 1, 'day' => '2026-09-11', 'option_id' => 3],
        ];
        $out = SurveyReport::displayOrder($rows, 'k');
        $this->assertSame([2, 1, 3], array_column($out, 'option_id'));
    }

    public function testAggregateTextKeepsTheOrderItIsGiven(): void
    {
        $rows = [$this->row(3, null, null, 'third'), $this->row(1, null, null, 'first'), $this->row(2, null, null, 'second')];
        $this->assertSame(['third', 'first', 'second'], SurveyReport::aggregateType('paragraph', $rows, [], [])['texts']);
    }

    public function testApplyLensFoldsAKingdomLensIntoAnEmptyPick(): void
    {
        $f = SurveyReport::applyLens([], ['shared' => true, 'kingdom_ids' => [17, 44]]);
        $this->assertSame([17, 44], $f['kingdom_ids']);
        $this->assertFalse($f['impossible']);
        $this->assertFalse($f['include_test']);
    }

    public function testApplyLensIntersectsTheViewersKingdomPick(): void
    {
        $f = SurveyReport::applyLens(['kingdom_ids' => [44, 99]], ['shared' => true, 'kingdom_ids' => [17, 44]]);
        $this->assertSame([44], $f['kingdom_ids']);
        $this->assertFalse($f['impossible']);
    }

    public function testApplyLensMakesADisjointPickImpossibleRatherThanWidening(): void
    {
        $f = SurveyReport::applyLens(['kingdom_ids' => [99]], ['shared' => true, 'kingdom_ids' => [17]]);
        $this->assertSame([17], $f['kingdom_ids']);
        $this->assertTrue($f['impossible']);
    }

    public function testParkLensForcesFullConsentAndRefusesAnotherTier(): void
    {
        $f = SurveyReport::applyLens([], ['shared' => true, 'park_id' => 1049]);
        $this->assertSame(1049, $f['park_id']);
        $this->assertSame('full', $f['consent']);
        $this->assertFalse($f['impossible']);

        $g = SurveyReport::applyLens(['consent' => 'partial'], ['shared' => true, 'park_id' => 1049]);
        $this->assertTrue($g['impossible']);
    }

    public function testSharedLensForcesTestRowsOffEvenWithNoFilterLens(): void
    {
        $f = SurveyReport::applyLens(['include_test' => true], ['shared' => true]);
        $this->assertFalse($f['include_test']);
        $this->assertNull($f['park_id']);
        $this->assertSame([], $f['kingdom_ids']);
    }

    public function testLensKeysSurviveRenormalizingAndCountAsNarrowing(): void
    {
        $f = SurveyReport::normalizeFilters(SurveyReport::applyLens([], ['shared' => true, 'park_id' => 9]));
        $this->assertSame(9, $f['park_id']);
        $this->assertTrue(SurveyReport::isNarrowing(['park_id' => 9]));
        $this->assertTrue(SurveyReport::isNarrowing(['impossible' => true]));
        $this->assertFalse(SurveyReport::isNarrowing([]));
    }

    public function testRedactForLensHidesSurveyWideCountsOnlyUnderALens(): void
    {
        $summary = ['responses' => 12, 'starts' => 80, 'audience' => 400, 'excluded_anonymous' => 9, 'response_rate' => 0.2, 'completion' => 0.5];
        $lensed = SurveyReport::redactForLens($summary, ['shared' => true, 'kingdom_ids' => [17]]);
        $this->assertSame(12, $lensed['responses']);
        foreach (['starts', 'audience', 'excluded_anonymous', 'response_rate', 'completion'] as $k) {
            $this->assertNull($lensed[$k], $k);
        }
        $this->assertSame($summary, SurveyReport::redactForLens($summary, ['shared' => true]));
    }

    /**
     * Home-park credits are public and dated the day taken, so two date windows
     * ([.., D] and [.., D-1], each over MIN_CELL) would hand a shared viewer the
     * answers of the one respondent a credit names on day D. No shared viewer
     * gets date bounds, whatever the lens.
     */
    public function testApplyLensDropsDateBoundsForEverySharedViewer(): void
    {
        $dated = ['date_from' => '2026-09-01', 'date_to' => '2026-09-09'];
        foreach ([['shared' => true], ['shared' => true, 'kingdom_ids' => [17]], ['shared' => true, 'park_id' => 917]] as $lens) {
            $f = SurveyReport::applyLens($dated, $lens);
            $this->assertNull($f['date_from'], json_encode($lens));
            $this->assertNull($f['date_to'], json_encode($lens));
        }
    }

    /** park_id and impossible are lens-only: a client that sends them is ignored. */
    public function testClientCannotSetTheLensOnlyKeys(): void
    {
        $f = SurveyReport::applyLens(['park_id' => 917], ['shared' => true, 'kingdom_ids' => [17]]);
        $this->assertNull($f['park_id'], 'a kingdom lens cannot be narrowed to one park');
        $this->assertSame([17], $f['kingdom_ids']);

        $this->assertNull(SurveyReport::applyLens(['park_id' => 1049], ['shared' => true])['park_id'], "nor an 'all' share to any park anywhere");
        $this->assertFalse(SurveyReport::applyLens(['impossible' => true], ['shared' => true])['impossible']);
        $this->assertSame(917, SurveyReport::applyLens(['park_id' => 5], ['shared' => true, 'park_id' => 917])['park_id'], 'the lens still sets it');

        $c = SurveyReport::clientFilters(['park_id' => 5, 'impossible' => true, 'consent' => 'full']);
        $this->assertNull($c['park_id']);
        $this->assertFalse($c['impossible']);
        $this->assertSame('full', $c['consent']);
    }

    /** An unfiltered 'all' share is still a shared view: MIN_CELL applies to it. */
    public function testEverySharedLensIsNarrowingAndClientsCannotSetTheMarkers(): void
    {
        $f = SurveyReport::applyLens([], ['shared' => true]);
        $this->assertTrue($f['shared']);
        $this->assertTrue(SurveyReport::isNarrowing($f));
        $this->assertTrue(SurveyReport::isSuppressed(SurveyReport::isNarrowing($f), 1));
        $this->assertTrue(SurveyReport::normalizeFilters($f)['shared'], 'survives renormalizing');

        $c = SurveyReport::clientFilters(['shared' => true, 'max_response_id' => 3]);
        $this->assertFalse($c['shared']);
        $this->assertNull($c['max_response_id']);
        $this->assertFalse(SurveyReport::isNarrowing(SurveyReport::clientFilters([])), 'a manager view is not');
    }

    /** [A,B] minus [A] isolates B: a shared pick is the whole lens or one kingdom. */
    public function testSharedKingdomPickIsTheWholeLensOrOneKingdom(): void
    {
        $lens = ['shared' => true, 'kingdom_ids' => [17, 44, 45]];
        $this->assertTrue(SurveyReport::applyLens(['kingdom_ids' => [17, 44]], $lens)['impossible'], 'a multi-kingdom subset');
        $this->assertFalse(SurveyReport::applyLens(['kingdom_ids' => [45, 17, 44]], $lens)['impossible'], 'the whole lens, any order');
        $this->assertFalse(SurveyReport::applyLens(['kingdom_ids' => [44]], $lens)['impossible'], 'one kingdom (its count is checked server-side)');
        $this->assertTrue(SurveyReport::applyLens(['kingdom_ids' => [17, 44]], ['shared' => true])['impossible'], "an 'all' share too");
        $this->assertFalse(SurveyReport::applyLens(['kingdom_ids' => [17]], ['shared' => true])['impossible']);
        $this->assertFalse(SurveyReport::applyLens(['kingdom_ids' => [17, 44]], [])['impossible'], 'managers keep multi-kingdom filters');
    }

    /** Complementary suppression over one-kingdom picks (whole minus the allowed picks is 0 or 5+). */
    public function testAllowedKingdomPicksWithholdsUntilTheRemainderIsSafe(): void
    {
        $this->assertSame([44], SurveyReport::allowedKingdomPicks([17 => 10, 44 => 10, 45 => 1], 21), '10/10/1: one big kingdom goes too (ties to the lower id)');
        $this->assertSame([17], SurveyReport::allowedKingdomPicks([17 => 6, 44 => 5], 12), 'a lone anonymous row: the smaller goes');
        $this->assertSame([17, 44], SurveyReport::allowedKingdomPicks([17 => 6, 44 => 5], 11), 'remainder 0');
        $this->assertSame([17, 44], SurveyReport::allowedKingdomPicks([17 => 6, 44 => 5, 45 => 2, 46 => 3], 16), 'remainder 5');
        $this->assertSame([], SurveyReport::allowedKingdomPicks([17 => 6, 44 => 2], 8), 'whole minus the one pick is 2');
        $this->assertSame([], SurveyReport::allowedKingdomPicks([17 => 4], 4));
    }

    public function testSharedLensIgnoresTheConsentPickExceptAParkLensForcesFull(): void
    {
        foreach (['full', 'partial', 'anonymous'] as $c) {
            $this->assertSame('any', SurveyReport::applyLens(['consent' => $c], ['shared' => true])['consent']);
            $this->assertSame('any', SurveyReport::applyLens(['consent' => $c], ['shared' => true, 'kingdom_ids' => [17]])['consent']);
        }
        $this->assertSame('full', SurveyReport::applyLens(['consent' => 'full'], [])['consent'], 'managers keep it');
        $this->assertSame('full', SurveyReport::applyLens([], ['shared' => true, 'park_id' => 9])['consent']);
    }

    public function testSharedLensDropsTheCrosstab(): void
    {
        $this->assertNull(SurveyReport::applyLens(['crosstab_question_id' => 5], ['shared' => true])['crosstab_question_id']);
        $this->assertNull(SurveyReport::applyLens(['crosstab_question_id' => 5], ['shared' => true, 'kingdom_ids' => [17]])['crosstab_question_id']);
        $this->assertSame(5, SurveyReport::applyLens(['crosstab_question_id' => 5], [])['crosstab_question_id'], 'managers keep it');
    }

    public function testStripVerbatimKeepsCountsAndDropsEveryText(): void
    {
        $payload = ['questions' => [
            ['question_id' => 1, 'type' => 'paragraph', 'n' => 6, 'agg' => ['n' => 6, 'texts' => ['a', 'b']]],
            ['question_id' => 2, 'type' => 'single', 'n' => 6, 'agg' => ['n' => 6, 'counts' => [], 'other_texts' => ['x', 'y', 'z']],
             'crosstab' => ['groups' => [['n' => 5, 'agg' => ['n' => 5, 'other_texts' => ['x']]], ['n' => null, 'suppressed' => true]]]],
            ['question_id' => 3, 'type' => 'rating', 'n' => null, 'agg' => ['suppressed' => true]],
        ]];
        $out = SurveyReport::stripVerbatim($payload);
        $json = (string) json_encode($out);
        $this->assertStringNotContainsString('"texts"', $json);
        $this->assertStringNotContainsString('"other_texts"', $json);
        $this->assertSame(6, $out['questions'][0]['agg']['n']);
        $this->assertSame(3, $out['questions'][1]['agg']['other_count']);
        $this->assertSame(1, $out['questions'][1]['crosstab']['groups'][0]['agg']['other_count']);
        $this->assertSame(['suppressed' => true], $out['questions'][2]['agg']);
    }

    public function testRedactForLensDropsPerDayCountsForEverySharedViewer(): void
    {
        $summary = ['responses' => 12, 'by_day' => [['day' => '2026-09-10', 'count' => 12]], 'median_duration' => 300];
        $this->assertNull(SurveyReport::redactForLens($summary, ['shared' => true])['by_day'], "an 'all' share too");
        $this->assertNull(SurveyReport::redactForLens($summary, ['shared' => true, 'park_id' => 9])['by_day']);
        $this->assertSame(12, SurveyReport::redactForLens($summary, ['shared' => true])['responses']);
        $this->assertSame($summary, SurveyReport::redactForLens($summary, []), 'a manager keeps them');
    }

    public function testLensLabelMatchesTheAccessLabels(): void
    {
        $this->assertSame('park', SurveyReport::lensLabel(['shared' => true, 'park_id' => 1049]));
        $this->assertSame('kingdom', SurveyReport::lensLabel(['shared' => true, 'kingdom_ids' => [17, 44]]));
        $this->assertSame('all', SurveyReport::lensLabel(['shared' => true]));
        $this->assertSame('all', SurveyReport::lensLabel(['shared' => true, 'kingdom_ids' => [], 'park_id' => 0]));
    }

    // --------------------------------------------------------------- pairwise

    public function testAggregatePairwiseWinPctTiesAndRanks(): void
    {
        // A, B, C, D (4 options = 6 possible matchups).
        $options = [$this->opt(10, 'A'), $this->opt(11, 'B'), $this->opt(12, 'C'), $this->opt(13, 'D')];
        $rows = [
            // response 1: A beats B, A ties C, C beats B (right side wins)
            $this->row(1, 10, 1.0, null, 11),
            $this->row(1, 10, 0.5, null, 12),
            $this->row(1, 11, 0.0, null, 12),
            // response 2: B beats A, C beats A
            $this->row(2, 11, 1.0, null, 10),
            $this->row(2, 12, 1.0, null, 10),
        ];
        $a = SurveyReport::aggregateType('pairwise', $rows, $options, []);

        $this->assertSame(2, $a['n']);
        $this->assertSame(6, $a['possible']);
        $this->assertSame(5, $a['judged']);
        $this->assertSame(2.5, $a['avg_count']);
        $this->assertSame(41.7, $a['avg_pct']);   // (3/6 + 2/6) / 2

        $byLabel = [];
        foreach ($a['options'] as $o) {
            $byLabel[$o['label']] = $o;
        }
        // C: beat B, beat A, tied A = 2.5 / 3
        // A small set (every matchup offered to everyone) ranks every option
        // it has seen, however few matchups: the minimum is for sliced sets.
        $this->assertSame(
            ['appearances' => 3, 'wins' => 2, 'ties' => 1, 'losses' => 0, 'win_pct' => 83.3, 'rank' => 1, 'too_few' => false],
            array_intersect_key($byLabel['C'], array_flip(['appearances', 'wins', 'ties', 'losses', 'win_pct', 'rank', 'too_few']))
        );
        // A: beat B, tied C, lost to B, lost to C = 1.5 / 4
        $this->assertSame(37.5, $byLabel['A']['win_pct']);
        // B: lost to A, lost to C, beat A = 1 / 3
        $this->assertSame(33.3, $byLabel['B']['win_pct']);
        // D never came up: unranked, last, and not "too few" (it had none).
        $this->assertNull($byLabel['D']['win_pct']);
        $this->assertNull($byLabel['D']['rank']);
        $this->assertFalse($byLabel['D']['too_few']);
        $this->assertSame(['C', 'A', 'B', 'D'], array_column($a['options'], 'label'));
        $this->assertSame([1, 2, 3, null], array_column($a['options'], 'rank'));
        $this->assertSame([false, false, false, false], array_column($a['options'], 'too_few'));
    }

    public function testAggregatePairwiseSharedRankAndTieBreaks(): void
    {
        $options = [$this->opt(10, 'Zed'), $this->opt(11, 'Amy'), $this->opt(12, 'Bo')];
        // Zed beats Bo, Amy beats Bo, Zed ties Amy, from five respondents: Zed
        // and Amy both 7.5 / 10 = 75.0 over 10 appearances each, Bo 0 / 10.
        $rows = [];
        for ($r = 1; $r <= 5; $r++) {
            $rows[] = $this->row($r, 10, 1.0, null, 12);
            $rows[] = $this->row($r, 11, 1.0, null, 12);
            $rows[] = $this->row($r, 10, 0.5, null, 11);
        }
        $a = SurveyReport::aggregateType('pairwise', $rows, $options, []);
        $this->assertSame(['Amy', 'Zed', 'Bo'], array_column($a['options'], 'label'), 'equal strength sorts by label');
        $this->assertSame([1, 1, 3], array_column($a['options'], 'rank'), 'competition ranking: 1, 1, 3');
    }

    /**
     * Review #35: ranks come from a Bradley-Terry fit, so beating a strong
     * opponent counts for more than beating a weak one, and an option seen
     * in too few matchups (2/2) is listed unranked instead of on top. The
     * minimum applies to a sliced set: nine options are 36 matchups, over
     * PAIRWISE_SMALL_MAX (Z1-Z4 never come up).
     */
    public function testAggregatePairwiseBradleyTerryRanksAndMinimumSample(): void
    {
        $options = [$this->opt(10, 'A'), $this->opt(11, 'C'), $this->opt(12, 'Strong'), $this->opt(13, 'Weak'), $this->opt(14, 'Lucky'),
            $this->opt(15, 'Z1'), $this->opt(16, 'Z2'), $this->opt(17, 'Z3'), $this->opt(18, 'Z4')];
        $rows = [];
        $rid = 1;
        $add = function (int $left, int $right, int $wins, int $n) use (&$rows, &$rid): void {
            for ($i = 0; $i < $n; $i++) {
                $rows[] = $this->row($rid++, $left, $i < $wins ? 1.0 : 0.0, null, $right);
            }
        };
        $add(10, 12, 6, 12);   // A splits with Strong: 50%
        $add(11, 13, 8, 12);   // C beats Weak 8 of 12: 66.7%
        $add(12, 13, 11, 12);  // Strong dominates Weak
        $add(14, 13, 2, 2);    // Lucky: 2 of 2 against Weak
        $a = SurveyReport::aggregateType('pairwise', $rows, $options, []);

        $byLabel = array_column($a['options'], null, 'label');
        $this->assertSame(50.0, $byLabel['A']['win_pct']);
        $this->assertSame(66.7, $byLabel['C']['win_pct']);
        $this->assertGreaterThan($byLabel['C']['strength'], $byLabel['A']['strength'], 'A beat a stronger field than C');
        $this->assertSame(36, $a['possible']);
        $this->assertSame(['Strong', 'A', 'C', 'Weak', 'Lucky', 'Z1', 'Z2', 'Z3', 'Z4'], array_column($a['options'], 'label'));
        $this->assertSame([1, 2, 3, 4, null, null, null, null, null], array_column($a['options'], 'rank'));
        $this->assertTrue($byLabel['Lucky']['too_few']);
        $this->assertSame(100.0, $byLabel['Lucky']['win_pct'], 'win % stays as a column');
        $this->assertFalse($byLabel['A']['too_few']);
        $this->assertFalse($byLabel['Z1']['too_few'], 'never matched is not "too few"');

        // The same matchups in a small set (5 options, 10 matchups, all offered
        // to everyone) rank Lucky with the rest: no minimum there.
        $small = SurveyReport::aggregateType('pairwise', $rows, array_slice($options, 0, 5), []);
        $smallBy = array_column($small['options'], null, 'label');
        $this->assertFalse($smallBy['Lucky']['too_few']);
        $this->assertNotNull($smallBy['Lucky']['rank']);
        $this->assertSame([], array_filter(array_column($small['options'], 'rank'), 'is_null'), 'every option ranks');
    }

    public function testBradleyTerryIsFiniteForUndefeatedAndCentredOnATypicalOption(): void
    {
        // X beat Y ten times out of ten: the prior keeps both finite.
        $p = SurveyReport::bradleyTerry([1 => [2 => 10], 2 => [1 => 10]], [1 => [2 => 10.0], 2 => [1 => 0.0]]);
        $this->assertTrue(is_finite($p[1]) && is_finite($p[2]));
        $this->assertGreaterThan($p[2], $p[1]);
        $this->assertEqualsWithDelta(1.0, $p[1] * $p[2], 1e-9, 'geometric mean strength is 1');

        // All draws: equal strengths.
        $d = SurveyReport::bradleyTerry([1 => [2 => 4], 2 => [1 => 4]], [1 => [2 => 2.0], 2 => [1 => 2.0]]);
        $this->assertEqualsWithDelta($d[1], $d[2], 1e-9);
        $this->assertSame([], SurveyReport::bradleyTerry([], []));
    }

    /** Review #33: other_texts share the free-text cap and carry the full count; group aggregates drop text lists. */
    public function testOtherTextsCappedAndDroppedFromCrosstabGroups(): void
    {
        $options = [$this->opt(10, 'Fighter'), $this->opt(11, 'Other', 'choice', null, 1)];
        $rows = [];
        $total = SurveyReport::TEXT_SAMPLE_LIMIT + 3;
        for ($i = 1; $i <= $total; $i++) {
            $rows[] = $this->row($i, 11, null, 'write-in ' . $i);
        }
        $a = SurveyReport::aggregateType('single', $rows, $options, []);
        $this->assertCount(SurveyReport::TEXT_SAMPLE_LIMIT, $a['other_texts']);
        $this->assertSame($total, $a['other_texts_n']);

        $g = SurveyReport::crosstabGroup(10, 'Group', $a);
        $this->assertArrayNotHasKey('other_texts', $g['agg']);
        $this->assertArrayNotHasKey('other_texts_n', $g['agg']);
        $this->assertSame($total, $g['agg']['n']);
    }

    public function testAggregatePairwiseEmpty(): void
    {
        $a = SurveyReport::aggregateType('pairwise', [], [$this->opt(10, 'A'), $this->opt(11, 'B'), $this->opt(12, 'C')], []);
        $this->assertSame(0, $a['n']);
        $this->assertSame(3, $a['possible']);
        $this->assertNull($a['avg_pct']);
        $this->assertNull($a['avg_count']);
        $this->assertSame([null, null, null], array_column($a['options'], 'rank'));
    }

    public function testDisplayAnswerPairwiseWinnerFirst(): void
    {
        $byId = [10 => $this->opt(10, 'Hawk'), 11 => $this->opt(11, 'Owl'), 12 => $this->opt(12, 'Wolf')];
        $rows = [
            $this->row(1, 10, 1.0, null, 11),   // Hawk > Owl
            $this->row(1, 12, 0.5, null, 10),   // Wolf = Hawk
            $this->row(1, 11, 0.0, null, 12),   // Wolf > Owl
        ];
        $this->assertSame(
            '3 of 3: Hawk > Owl; Wolf = Hawk; Wolf > Owl',
            SurveyReport::displayAnswer('pairwise', $rows, $byId, 3)
        );
    }

    /** @return array<int,array> questions keyed by id, in analysis-test shape */
    private function analysisQuestions(): array
    {
        $q = static function (int $id, string $type, int $sq = 0, int $so = 0): array {
            return ['question_id' => $id, 'type' => $type, 'prompt' => 'P' . $id, 'settings' => [], 'page_id' => 1,
                'show_if_question_id' => $sq, 'show_if_option_id' => $so,
                'page_show_if_question_id' => 0, 'page_show_if_option_id' => 0];
        };
        return [
            1 => $q(1, 'single'), 2 => $q(2, 'multi', 1, 11), 3 => $q(3, 'matrix'), 4 => $q(4, 'ranking'),
            5 => $q(5, 'pairwise'), 6 => $q(6, 'section'), 7 => $q(7, 'number'),
        ];
    }

    private function analysisOptions(): array
    {
        return [
            1 => [$this->opt(10, 'Yes'), $this->opt(11, 'No'), $this->opt(12, 'Other', 'choice', null, 1)],
            2 => [$this->opt(20, 'A'), $this->opt(21, 'B')],
            3 => [$this->opt(30, 'R1', 'row'), $this->opt(31, 'R2', 'row'), $this->opt(32, 'Low', 'column', 1.0), $this->opt(33, 'High', 'column', 5.0)],
            4 => [$this->opt(40, 'X'), $this->opt(41, 'Y')],
            5 => [$this->opt(50, 'Hawk'), $this->opt(51, 'Owl'), $this->opt(52, 'Wolf')],
        ];
    }

    public function testAnalysisColumnCodes(): void
    {
        $cols = SurveyReport::analysisColumns($this->analysisQuestions(), $this->analysisOptions());
        $this->assertSame([
            'Q1', 'Q1_other', 'Q2_o20', 'Q2_o21', 'Q3_r30', 'Q3_r31', 'Q4_rank_o40', 'Q4_rank_o41',
            'Q5_wins_o50', 'Q5_wins_o51', 'Q5_wins_o52', 'Q7',
        ], array_column($cols, 'code'));
    }

    public function testOptionCodesArePerRolePositions(): void
    {
        $codes = SurveyReport::optionCodes($this->analysisOptions());
        $this->assertSame(3, $codes[12]);
        $this->assertSame(2, $codes[31]);
        $this->assertSame(1, $codes[32]);
    }

    public function testAnalysisNotShownIsMinus99AndSkippedIsBlank(): void
    {
        $q = $this->analysisQuestions()[2];   // shown only when Q1 = option 11
        $this->assertFalse(SurveyReport::analysisShown($q, [1 => [10]]));
        $this->assertTrue(SurveyReport::analysisShown($q, [1 => [11]]));
        $this->assertTrue(SurveyReport::analysisShown($q, [], true));   // answered => shown
        $cols = array_values(array_filter(SurveyReport::analysisColumns($this->analysisQuestions(), $this->analysisOptions()), static function ($c) {
            return $c['question_id'] === 2;
        }));
        $this->assertSame(['-99', '-99'], SurveyReport::analysisCells('multi', $cols, [], false, [], []));
        $this->assertSame(['', ''], SurveyReport::analysisCells('multi', $cols, [], true, [], []));
        $this->assertSame(['0', '1'], SurveyReport::analysisCells('multi', $cols, [$this->row(1, 21)], true, [], []));
    }

    public function testAnalysisCellsCodeEachType(): void
    {
        $qs = $this->analysisQuestions();
        $opts = $this->analysisOptions();
        $byId = [];
        foreach ($opts as $list) {
            foreach ($list as $o) {
                $byId[$o['option_id']] = $o;
            }
        }
        $codes = SurveyReport::optionCodes($opts);
        $slice = [];
        foreach (SurveyReport::analysisColumns($qs, $opts) as $c) {
            $slice[$c['question_id']][] = $c;
        }
        $this->assertSame(['3', 'Bard'], SurveyReport::analysisCells('single', $slice[1], [$this->row(1, 12, null, 'Bard')], true, $byId, $codes));
        $this->assertSame(['5', ''], SurveyReport::analysisCells('matrix', $slice[3], [$this->row(1, 33, null, null, 30)], true, $byId, $codes));
        $this->assertSame(['2', '1'], SurveyReport::analysisCells('ranking', $slice[4], [$this->row(1, 40, 2.0), $this->row(1, 41, 1.0)], true, $byId, $codes));
        $this->assertSame(['1.5', '0', '0.5'], SurveyReport::analysisCells('pairwise', $slice[5], [
            $this->row(1, 50, 1.0, null, 51),
            $this->row(1, 52, 0.5, null, 50),
        ], true, $byId, $codes));
        $this->assertSame(['4.5'], SurveyReport::analysisCells('number', $slice[7], [$this->row(1, null, 4.5)], true, $byId, $codes));
    }

    public function testCodebookListsCodesValuesAndShowIf(): void
    {
        $rows = SurveyReport::codebookRows($this->analysisQuestions(), $this->analysisOptions());
        $this->assertSame('variable', $rows[0][0]);
        $q2 = array_values(array_filter($rows, static function ($r) {
            return $r[0] === 'Q2_o20';
        }));
        $this->assertSame('Q1 = 2 (No)', $q2[0][9]);
        $this->assertSame('-99', $q2[1][6]);
        $matrixValues = array_values(array_filter($rows, static function ($r) {
            return $r[0] === 'Q3_r30' && $r[6] !== '';
        }));
        $this->assertSame([['1', 'Low'], ['5', 'High']], array_map(static function ($r) {
            return [$r[6], $r[7]];
        }, $matrixValues));
    }
}
