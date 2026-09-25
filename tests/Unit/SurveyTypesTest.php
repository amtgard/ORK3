<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure SurveyTypes catalog (survey module, spec §4).
 *
 * SurveyTypes is the single shared table the builder, the runner and the
 * aggregator all consult, so these cases lock the settings defaults, the
 * settings validation, the answer normalisation into answer rows and the
 * one-level show-if evaluation. No DB, no globals.
 */
final class SurveyTypesTest extends TestCase
{
    /** @return list<array{option_id:int,role:string,is_other:int,label:string}> */
    private function choiceOptions(): array
    {
        return [
            ['option_id' => 10, 'role' => 'choice', 'is_other' => 0, 'label' => 'A'],
            ['option_id' => 11, 'role' => 'choice', 'is_other' => 1, 'label' => 'Other'],
        ];
    }

    // ---------------------------------------------------------------- catalog

    public function testTypesAndAnswerable(): void
    {
        $this->assertSame(
            ['single','multi','dropdown','yesno','rating','nps','matrix','ranking','pairwise',
                'short_text','paragraph','number','date','section','image'],
            SurveyTypes::TYPES
        );
        $this->assertNotContains('section', SurveyTypes::ANSWERABLE);
        $this->assertNotContains('image', SurveyTypes::ANSWERABLE);
        $this->assertContains('single', SurveyTypes::ANSWERABLE);
        $this->assertCount(13, SurveyTypes::ANSWERABLE);

        $this->assertTrue(SurveyTypes::isType('matrix'));
        $this->assertFalse(SurveyTypes::isType('bogus'));
        $this->assertTrue(SurveyTypes::isAnswerable('nps'));
        $this->assertFalse(SurveyTypes::isAnswerable('section'));
        $this->assertFalse(SurveyTypes::isAnswerable('bogus'));

        $this->assertSame(['single','dropdown','yesno','multi'], SurveyTypes::SHOW_IF_SOURCES);
        $this->assertSame(['row','column'], SurveyTypes::OPTION_ROLES['matrix']);
        $this->assertNotContains('pairwise', SurveyTypes::SHOW_IF_SOURCES);
        $this->assertSame(['choice'], SurveyTypes::OPTION_ROLES['pairwise']);
    }

    // ------------------------------------------------------- default settings

    public function testDefaultSettingsRating(): void
    {
        $this->assertSame(
            ['min' => 1, 'max' => 5, 'min_label' => '', 'max_label' => '', 'icon' => 'star'],
            SurveyTypes::defaultSettings('rating')
        );
    }

    public function testDefaultSettingsForEveryType(): void
    {
        $this->assertSame(['randomize' => false], SurveyTypes::defaultSettings('single'));
        $this->assertSame(
            ['randomize' => false, 'min_select' => 0, 'max_select' => 0],
            SurveyTypes::defaultSettings('multi')
        );
        $this->assertSame([], SurveyTypes::defaultSettings('dropdown'));
        $this->assertSame([], SurveyTypes::defaultSettings('yesno'));
        $this->assertSame(
            ['min_label' => 'Not likely', 'max_label' => 'Very likely'],
            SurveyTypes::defaultSettings('nps')
        );
        $this->assertSame(['require_all_rows' => false], SurveyTypes::defaultSettings('matrix'));
        $this->assertSame(['randomize' => true, 'rank_all' => true], SurveyTypes::defaultSettings('ranking'));
        $this->assertSame(['max_length' => 200, 'placeholder' => ''], SurveyTypes::defaultSettings('short_text'));
        $this->assertSame(['max_length' => 4000, 'placeholder' => ''], SurveyTypes::defaultSettings('paragraph'));
        $this->assertSame(
            ['min' => null, 'max' => null, 'step' => 1, 'unit' => ''],
            SurveyTypes::defaultSettings('number')
        );
        $this->assertSame(['min' => null, 'max' => null], SurveyTypes::defaultSettings('date'));
        $this->assertSame([], SurveyTypes::defaultSettings('section'));
        $this->assertSame(['caption' => ''], SurveyTypes::defaultSettings('image'));
        $this->assertSame([], SurveyTypes::defaultSettings('bogus'));
        $this->assertSame([], SurveyTypes::defaultSettings('pairwise'));
    }

    // ---------------------------------------------------- settings validation

    public function testValidateSettingsRejectsRatingMaxBelowMin(): void
    {
        $r = SurveyTypes::validateSettings('rating', ['min' => 5, 'max' => 2]);
        $this->assertFalse($r['ok']);
        $this->assertNotSame('', (string) $r['error']);
    }

    public function testValidateSettingsMergesDefaultsAndCoercesTypes(): void
    {
        $r = SurveyTypes::validateSettings('rating', ['max' => '7', 'unknown_key' => 'x']);
        $this->assertTrue($r['ok']);
        $this->assertSame(
            ['min' => 1, 'max' => 7, 'min_label' => '', 'max_label' => '', 'icon' => 'star'],
            $r['settings']
        );
        $this->assertArrayNotHasKey('unknown_key', $r['settings']);
    }

    public function testValidateSettingsAcceptsJsonStringAndNull(): void
    {
        $r = SurveyTypes::validateSettings('multi', '{"min_select":2,"max_select":3}');
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['settings']['min_select']);
        $this->assertSame(3, $r['settings']['max_select']);

        $d = SurveyTypes::validateSettings('multi', null);
        $this->assertTrue($d['ok']);
        $this->assertSame(SurveyTypes::defaultSettings('multi'), $d['settings']);
    }

    public function testRankingRandomizeDefaultsOnAndCanBeTurnedOff(): void
    {
        // Stored settings from before the key existed read back as randomized.
        $legacy = SurveyTypes::validateSettings('ranking', '{"rank_all":false}');
        $this->assertTrue($legacy['ok']);
        $this->assertTrue($legacy['settings']['randomize']);
        $this->assertFalse($legacy['settings']['rank_all']);

        $off = SurveyTypes::validateSettings('ranking', ['randomize' => '0']);
        $this->assertTrue($off['ok']);
        $this->assertFalse($off['settings']['randomize']);
    }

    public function testValidateSettingsRejectsBadValues(): void
    {
        $this->assertFalse(SurveyTypes::validateSettings('rating', ['icon' => 'banana'])['ok']);
        $this->assertFalse(SurveyTypes::validateSettings('multi', ['min_select' => 3, 'max_select' => 2])['ok']);
        $this->assertFalse(SurveyTypes::validateSettings('number', ['min' => 10, 'max' => 1])['ok']);
        $this->assertFalse(SurveyTypes::validateSettings('number', ['step' => 0])['ok']);
        $this->assertFalse(SurveyTypes::validateSettings('date', ['min' => 'not-a-date'])['ok']);
        $this->assertFalse(SurveyTypes::validateSettings('short_text', ['max_length' => 0])['ok']);
        $this->assertFalse(SurveyTypes::validateSettings('bogus', [])['ok']);
    }

    // ----------------------------------------------------------- seed options

    public function testSeedOptionsYesNo(): void
    {
        $this->assertSame(
            [['role' => 'choice', 'label' => 'Yes'], ['role' => 'choice', 'label' => 'No']],
            SurveyTypes::seedOptions('yesno')
        );
    }

    public function testSeedOptionsOtherTypes(): void
    {
        foreach (['single', 'multi', 'dropdown', 'ranking'] as $type) {
            $this->assertSame(
                [['role' => 'choice', 'label' => 'Option 1'], ['role' => 'choice', 'label' => 'Option 2']],
                SurveyTypes::seedOptions($type),
                $type
            );
        }

        $matrix = SurveyTypes::seedOptions('matrix');
        $this->assertCount(5, $matrix);
        $roles = array_column($matrix, 'role');
        $this->assertSame(2, count(array_filter($roles, static fn ($r) => $r === 'row')));
        $this->assertSame(3, count(array_filter($roles, static fn ($r) => $r === 'column')));

        $this->assertSame([], SurveyTypes::seedOptions('rating'));
        $this->assertSame([], SurveyTypes::seedOptions('section'));
    }

    public function testMinOptions(): void
    {
        $this->assertSame(['choice' => 2], SurveyTypes::minOptions('single'));
        $this->assertSame(['choice' => 2], SurveyTypes::minOptions('ranking'));
        $this->assertSame(['row' => 1, 'column' => 2], SurveyTypes::minOptions('matrix'));
        $this->assertSame([], SurveyTypes::minOptions('rating'));
        $this->assertSame([], SurveyTypes::minOptions('section'));
        $this->assertSame(['choice' => 3], SurveyTypes::minOptions('pairwise'));
    }

    // -------------------------------------------------------- answer: choices

    public function testValidateAnswerSingleAcceptsKnownOption(): void
    {
        $q = ['type' => 'single', 'required' => 1, 'settings' => []];
        $opts = $this->choiceOptions();
        $r = SurveyTypes::validateAnswer($q, $opts, 10);
        $this->assertTrue($r['ok']);
        $this->assertCount(1, $r['rows']);
        $this->assertSame(10, $r['rows'][0]['option_id']);
        $this->assertNull($r['rows'][0]['value_text']);
        $this->assertNull($r['rows'][0]['value_num']);
        $this->assertNull($r['rows'][0]['row_option_id']);
    }

    public function testValidateAnswerSingleOtherRequiresText(): void
    {
        $q = ['type' => 'single', 'required' => 1, 'settings' => []];
        $opts = $this->choiceOptions();
        $this->assertFalse(SurveyTypes::validateAnswer($q, $opts, ['option_id' => 11, 'other' => ''])['ok']);
        $ok = SurveyTypes::validateAnswer($q, $opts, ['option_id' => 11, 'other' => 'Bardic']);
        $this->assertTrue($ok['ok']);
        $this->assertSame(11, $ok['rows'][0]['option_id']);
        $this->assertSame('Bardic', $ok['rows'][0]['value_text']);
    }

    public function testValidateAnswerOtherTextIsTrimmedAndCapped(): void
    {
        $q = ['type' => 'single', 'required' => 1, 'settings' => []];
        $opts = $this->choiceOptions();
        $r = SurveyTypes::validateAnswer($q, $opts, ['option_id' => 11, 'other' => '  ' . str_repeat('x', 300) . '  ']);
        $this->assertTrue($r['ok']);
        $this->assertSame(255, strlen((string) $r['rows'][0]['value_text']));
    }

    public function testValidateAnswerSingleRejectsForeignOption(): void
    {
        $q = ['type' => 'single', 'required' => 1, 'settings' => []];
        $this->assertFalse(SurveyTypes::validateAnswer($q, $this->choiceOptions(), 99)['ok']);
    }

    public function testDropdownAndYesNoBehaveLikeSingle(): void
    {
        $opts = $this->choiceOptions();
        foreach (['dropdown', 'yesno'] as $type) {
            $q = ['type' => $type, 'required' => 1, 'settings' => []];
            $r = SurveyTypes::validateAnswer($q, $opts, 10);
            $this->assertTrue($r['ok'], $type);
            $this->assertSame(10, $r['rows'][0]['option_id'], $type);
            $this->assertFalse(SurveyTypes::validateAnswer($q, $opts, 99)['ok'], $type);
        }
    }

    public function testMultiHonoursMinMaxSelect(): void
    {
        $opts = [
            ['option_id' => 10, 'role' => 'choice', 'is_other' => 0, 'label' => 'A'],
            ['option_id' => 11, 'role' => 'choice', 'is_other' => 0, 'label' => 'B'],
            ['option_id' => 12, 'role' => 'choice', 'is_other' => 0, 'label' => 'C'],
        ];
        $q = ['type' => 'multi', 'required' => 1, 'settings' => ['min_select' => 2, 'max_select' => 0]];
        $this->assertFalse(SurveyTypes::validateAnswer($q, $opts, [10])['ok']);
        $ok = SurveyTypes::validateAnswer($q, $opts, [10, 11]);
        $this->assertTrue($ok['ok']);
        $this->assertCount(2, $ok['rows']);
        $this->assertSame([10, 11], array_column($ok['rows'], 'option_id'));

        $capped = ['type' => 'multi', 'required' => 1, 'settings' => ['min_select' => 0, 'max_select' => 2]];
        $this->assertFalse(SurveyTypes::validateAnswer($capped, $opts, [10, 11, 12])['ok']);
        $this->assertTrue(SurveyTypes::validateAnswer($capped, $opts, [10, 11])['ok']);
        $this->assertFalse(SurveyTypes::validateAnswer($capped, $opts, [10, 99])['ok']);
    }

    public function testMultiOtherWriteIn(): void
    {
        $opts = $this->choiceOptions();
        $q = ['type' => 'multi', 'required' => 1, 'settings' => []];
        $r = SurveyTypes::validateAnswer($q, $opts, [10, ['option_id' => 11, 'other' => 'Bardic']]);
        $this->assertTrue($r['ok']);
        $this->assertCount(2, $r['rows']);
        $this->assertSame('Bardic', $r['rows'][1]['value_text']);
    }

    // ------------------------------------------------------- answer: required

    public function testRequiredAnswerIsMandatoryButOptionalOneIsNot(): void
    {
        $req = ['type' => 'rating', 'required' => 1, 'settings' => []];
        $this->assertFalse(SurveyTypes::validateAnswer($req, [], null)['ok']);
        $this->assertFalse(SurveyTypes::validateAnswer($req, [], '')['ok']);

        $opt = ['type' => 'rating', 'required' => 0, 'settings' => []];
        $r = SurveyTypes::validateAnswer($opt, [], null);
        $this->assertTrue($r['ok']);
        $this->assertSame([], $r['rows']);

        $emptyMulti = ['type' => 'multi', 'required' => 0, 'settings' => []];
        $m = SurveyTypes::validateAnswer($emptyMulti, $this->choiceOptions(), []);
        $this->assertTrue($m['ok']);
        $this->assertSame([], $m['rows']);
        $this->assertFalse(
            SurveyTypes::validateAnswer(
                ['type' => 'multi', 'required' => 1, 'settings' => []],
                $this->choiceOptions(),
                []
            )['ok']
        );
    }

    public function testNonAnswerableTypesProduceNoRows(): void
    {
        foreach (['section', 'image'] as $type) {
            $r = SurveyTypes::validateAnswer(['type' => $type, 'required' => 1, 'settings' => []], [], 'x');
            $this->assertTrue($r['ok'], $type);
            $this->assertSame([], $r['rows'], $type);
        }
    }

    // ---------------------------------------------------- answer: scale types

    public function testNpsRange(): void
    {
        $q = ['type' => 'nps', 'required' => 1, 'settings' => []];
        $this->assertFalse(SurveyTypes::validateAnswer($q, [], 11)['ok']);
        $this->assertFalse(SurveyTypes::validateAnswer($q, [], -1)['ok']);
        $r = SurveyTypes::validateAnswer($q, [], 10);
        $this->assertTrue($r['ok']);
        $this->assertSame(10.0, $r['rows'][0]['value_num']);
        $this->assertTrue(SurveyTypes::validateAnswer($q, [], 0)['ok']);
    }

    public function testRatingRespectsSettingsRange(): void
    {
        $q = ['type' => 'rating', 'required' => 1, 'settings' => ['min' => 1, 'max' => 5]];
        $this->assertFalse(SurveyTypes::validateAnswer($q, [], 6)['ok']);
        $this->assertFalse(SurveyTypes::validateAnswer($q, [], 0)['ok']);
        $r = SurveyTypes::validateAnswer($q, [], 4);
        $this->assertTrue($r['ok']);
        $this->assertSame(4.0, $r['rows'][0]['value_num']);
    }

    // --------------------------------------------------- answer: matrix, rank

    public function testMatrixRowsAndColumns(): void
    {
        $opts = [
            ['option_id' => 1, 'role' => 'row', 'is_other' => 0, 'label' => 'Row 1'],
            ['option_id' => 2, 'role' => 'row', 'is_other' => 0, 'label' => 'Row 2'],
            ['option_id' => 20, 'role' => 'column', 'is_other' => 0, 'label' => 'Agree'],
            ['option_id' => 21, 'role' => 'column', 'is_other' => 0, 'label' => 'Disagree'],
        ];
        $q = ['type' => 'matrix', 'required' => 1, 'settings' => ['require_all_rows' => false]];

        $r = SurveyTypes::validateAnswer($q, $opts, [1 => 20, 2 => 21]);
        $this->assertTrue($r['ok']);
        $this->assertCount(2, $r['rows']);
        $this->assertSame(1, $r['rows'][0]['row_option_id']);
        $this->assertSame(20, $r['rows'][0]['option_id']);

        // Foreign column.
        $this->assertFalse(SurveyTypes::validateAnswer($q, $opts, [1 => 99])['ok']);
        // Foreign row.
        $this->assertFalse(SurveyTypes::validateAnswer($q, $opts, [99 => 20])['ok']);
        // Partial answer is fine when require_all_rows is off.
        $this->assertTrue(SurveyTypes::validateAnswer($q, $opts, [1 => 20])['ok']);

        $all = ['type' => 'matrix', 'required' => 1, 'settings' => ['require_all_rows' => true]];
        $this->assertFalse(SurveyTypes::validateAnswer($all, $opts, [1 => 20])['ok']);
        $this->assertTrue(SurveyTypes::validateAnswer($all, $opts, [1 => 20, 2 => 21])['ok']);
    }

    public function testRankingProducesRankRows(): void
    {
        $opts = [
            ['option_id' => 10, 'role' => 'choice', 'is_other' => 0, 'label' => 'A'],
            ['option_id' => 11, 'role' => 'choice', 'is_other' => 0, 'label' => 'B'],
            ['option_id' => 12, 'role' => 'choice', 'is_other' => 0, 'label' => 'C'],
        ];
        $q = ['type' => 'ranking', 'required' => 1, 'settings' => ['rank_all' => false]];

        $r = SurveyTypes::validateAnswer($q, $opts, [12, 10, 11]);
        $this->assertTrue($r['ok']);
        $this->assertSame([12, 10, 11], array_column($r['rows'], 'option_id'));
        $this->assertSame([1.0, 2.0, 3.0], array_column($r['rows'], 'value_num'));

        // Duplicate option.
        $this->assertFalse(SurveyTypes::validateAnswer($q, $opts, [10, 10])['ok']);
        // Foreign option.
        $this->assertFalse(SurveyTypes::validateAnswer($q, $opts, [10, 99])['ok']);
        // Partial ranking allowed when rank_all is off.
        $this->assertTrue(SurveyTypes::validateAnswer($q, $opts, [10])['ok']);

        $all = ['type' => 'ranking', 'required' => 1, 'settings' => ['rank_all' => true]];
        $this->assertFalse(SurveyTypes::validateAnswer($all, $opts, [10, 11])['ok']);
        $this->assertTrue(SurveyTypes::validateAnswer($all, $opts, [10, 11, 12])['ok']);
    }

    // ------------------------------- optional matrix / ranking (review #18)

    public function testOptionalMatrixMayBePartialOrBlank(): void
    {
        $opts = [
            ['option_id' => 1, 'role' => 'row', 'is_other' => 0, 'label' => 'Row 1'],
            ['option_id' => 2, 'role' => 'row', 'is_other' => 0, 'label' => 'Row 2'],
            ['option_id' => 20, 'role' => 'column', 'is_other' => 0, 'label' => 'Agree'],
            ['option_id' => 21, 'role' => 'column', 'is_other' => 0, 'label' => 'Disagree'],
        ];
        $q = ['type' => 'matrix', 'required' => 0, 'settings' => ['require_all_rows' => true]];

        // Partial: require_all_rows binds only a required grid.
        $partial = SurveyTypes::validateAnswer($q, $opts, [1 => 20]);
        $this->assertTrue($partial['ok']);
        $this->assertCount(1, $partial['rows']);

        // Every row blank: a non-empty payload with no usable row stores nothing.
        $blank = SurveyTypes::validateAnswer($q, $opts, [1 => '', 2 => null]);
        $this->assertTrue($blank['ok']);
        $this->assertSame([], $blank['rows']);

        // Empty payload.
        $this->assertTrue(SurveyTypes::validateAnswer($q, $opts, [])['ok']);

        // Foreign ids are still refused on an optional grid.
        $this->assertFalse(SurveyTypes::validateAnswer($q, $opts, [1 => 99])['ok']);

        // The same blank payload on a REQUIRED grid is refused.
        $req = ['type' => 'matrix', 'required' => 1, 'settings' => ['require_all_rows' => false]];
        $this->assertFalse(SurveyTypes::validateAnswer($req, $opts, [1 => '', 2 => null])['ok']);
    }

    public function testOptionalRankingMayBePartialOrBlank(): void
    {
        $opts = [
            ['option_id' => 10, 'role' => 'choice', 'is_other' => 0, 'label' => 'A'],
            ['option_id' => 11, 'role' => 'choice', 'is_other' => 0, 'label' => 'B'],
            ['option_id' => 12, 'role' => 'choice', 'is_other' => 0, 'label' => 'C'],
        ];
        $q = ['type' => 'ranking', 'required' => 0, 'settings' => ['rank_all' => true]];

        // Partial: rank_all binds only a required ranking.
        $partial = SurveyTypes::validateAnswer($q, $opts, [11]);
        $this->assertTrue($partial['ok']);
        $this->assertSame([11], array_column($partial['rows'], 'option_id'));

        // Only blank entries: stores nothing.
        $blank = SurveyTypes::validateAnswer($q, $opts, ['', null]);
        $this->assertTrue($blank['ok']);
        $this->assertSame([], $blank['rows']);

        // Skipped entirely (the runner sends nothing for an untouched ranking).
        $this->assertTrue(SurveyTypes::validateAnswer($q, $opts, null)['ok']);

        // Duplicates and foreign ids are still refused.
        $this->assertFalse(SurveyTypes::validateAnswer($q, $opts, [10, 10])['ok']);
        $this->assertFalse(SurveyTypes::validateAnswer($q, $opts, [99])['ok']);

        // Required + rank_all still demands every option.
        $req = ['type' => 'ranking', 'required' => 1, 'settings' => ['rank_all' => true]];
        $this->assertFalse(SurveyTypes::validateAnswer($req, $opts, [11])['ok']);
        $this->assertFalse(SurveyTypes::validateAnswer($req, $opts, ['', null])['ok']);
    }

    // ------------------------------------------- answer: text, number, date

    public function testTextMaxLength(): void
    {
        $q = ['type' => 'short_text', 'required' => 1, 'settings' => ['max_length' => 5]];
        $this->assertFalse(SurveyTypes::validateAnswer($q, [], 'abcdef')['ok']);
        $r = SurveyTypes::validateAnswer($q, [], '  abcde  ');
        $this->assertTrue($r['ok']);
        $this->assertSame('abcde', $r['rows'][0]['value_text']);

        $p = ['type' => 'paragraph', 'required' => 1, 'settings' => ['max_length' => 10]];
        $this->assertFalse(SurveyTypes::validateAnswer($p, [], str_repeat('a', 11))['ok']);
        $this->assertTrue(SurveyTypes::validateAnswer($p, [], 'hello')['ok']);
    }

    public function testNumberStepAndBounds(): void
    {
        $q = ['type' => 'number', 'required' => 1, 'settings' => ['min' => 0, 'max' => 10, 'step' => 1]];
        $this->assertFalse(SurveyTypes::validateAnswer($q, [], 11)['ok']);
        $this->assertFalse(SurveyTypes::validateAnswer($q, [], -1)['ok']);
        $this->assertFalse(SurveyTypes::validateAnswer($q, [], 2.5)['ok']);
        $r = SurveyTypes::validateAnswer($q, [], 7);
        $this->assertTrue($r['ok']);
        $this->assertSame(7.0, $r['rows'][0]['value_num']);
        $this->assertFalse(SurveyTypes::validateAnswer($q, [], 'abc')['ok']);

        $free = ['type' => 'number', 'required' => 1, 'settings' => ['step' => 0.5]];
        $this->assertTrue(SurveyTypes::validateAnswer($free, [], 2.5)['ok']);
    }

    /** Review #31: DECIMAL(12,3) range and scale are enforced, never clamped or written as INF. */
    public function testNumberRefusesWhatTheColumnCannotHold(): void
    {
        // No author min/max: only the column bounds apply.
        $whole = ['type' => 'number', 'required' => 1, 'settings' => ['step' => 1]];
        foreach (['1e400', '-1e400'] as $bad) {
            $r = SurveyTypes::validateAnswer($whole, [], $bad);
            $this->assertFalse($r['ok'], $bad . ' (INF) must be refused, not pass the step check');
            $this->assertSame('Please enter a number.', $r['error']);
        }
        foreach ([1e10, '1000000000', '-1000000000'] as $bad) {
            $r = SurveyTypes::validateAnswer($whole, [], $bad);
            $this->assertFalse($r['ok'], var_export($bad, true) . ' must be refused, not clamped');
            $this->assertStringContainsString('999,999,999.999', (string) $r['error']);
        }
        foreach (['999999999', '-999999999'] as $good) {
            $this->assertTrue(SurveyTypes::validateAnswer($whole, [], $good)['ok'], $good . ' fits');
        }

        $fine = ['type' => 'number', 'required' => 1, 'settings' => ['step' => 0.001]];
        $r = SurveyTypes::validateAnswer($fine, [], '1.2345');
        $this->assertFalse($r['ok']);
        $this->assertSame('Please use no more than 3 decimal places.', $r['error']);
        foreach (['0.125', 0.1 + 0.2] as $good) {
            $this->assertTrue(SurveyTypes::validateAnswer($fine, [], $good)['ok'], var_export($good, true) . ' fits');
        }

        $this->assertNull(SurveyTypes::numberStorageError(999999999.999));
        // A 4th decimal on a large number must not slip through to be rounded by MySQL.
        foreach ([1000000.0005, 12345678.1234, 999999999.0001] as $bad) {
            $this->assertNotNull(SurveyTypes::numberStorageError($bad), var_export($bad, true) . ' has a 4th decimal');
        }
        foreach ([987654321.987, 123456789.123] as $good) {
            $this->assertNull(SurveyTypes::numberStorageError($good), var_export($good, true) . ' fits');
        }
        $this->assertNotNull(SurveyTypes::numberStorageError(-1000000000.0));
        $this->assertNull(SurveyTypes::numberStorageError(12.5));
        $this->assertNotNull(SurveyTypes::numberStorageError(INF));
        $this->assertNotNull(SurveyTypes::numberStorageError(NAN));
    }

    public function testDateIsoOnly(): void
    {
        $q = ['type' => 'date', 'required' => 1, 'settings' => []];
        $this->assertFalse(SurveyTypes::validateAnswer($q, [], '2026-02-30')['ok']);
        $this->assertFalse(SurveyTypes::validateAnswer($q, [], '02/28/2026')['ok']);
        $r = SurveyTypes::validateAnswer($q, [], '2026-02-28');
        $this->assertTrue($r['ok']);
        $this->assertSame('2026-02-28', $r['rows'][0]['value_text']);

        $bounded = ['type' => 'date', 'required' => 1, 'settings' => ['min' => '2026-01-01', 'max' => '2026-01-31']];
        $this->assertFalse(SurveyTypes::validateAnswer($bounded, [], '2026-02-01')['ok']);
        $this->assertTrue(SurveyTypes::validateAnswer($bounded, [], '2026-01-15')['ok']);
    }

    // ------------------------------------------------------------- show-if

    public function testIsShownQuestionLevel(): void
    {
        $q = ['show_if_question_id' => 5, 'show_if_option_id' => 50];
        $this->assertTrue(SurveyTypes::isShown($q, [5 => 50]));
        $this->assertTrue(SurveyTypes::isShown($q, [5 => [49, 50]]));
        $this->assertFalse(SurveyTypes::isShown($q, [5 => 49]));
        $this->assertFalse(SurveyTypes::isShown($q, []));
        $this->assertTrue(SurveyTypes::isShown(['show_if_question_id' => null, 'show_if_option_id' => null], []));
    }

    public function testIsShownAcceptsOtherShapeAndStringKeys(): void
    {
        $q = ['show_if_question_id' => 5, 'show_if_option_id' => 50];
        $this->assertTrue(SurveyTypes::isShown($q, [5 => ['option_id' => 50, 'other' => 'x']]));
        $this->assertTrue(SurveyTypes::isShown($q, ['5' => '50']));
        $this->assertFalse(SurveyTypes::isShown($q, [5 => null]));
        $this->assertTrue(SurveyTypes::isShown([], [])); // no keys at all => always shown
    }

    public function testSelects(): void
    {
        $this->assertTrue(SurveyTypes::selects(10, 10));
        $this->assertTrue(SurveyTypes::selects('10', 10));
        $this->assertTrue(SurveyTypes::selects([9, 10], 10));
        $this->assertTrue(SurveyTypes::selects(['option_id' => 10], 10));
        $this->assertTrue(SurveyTypes::selects([['option_id' => 10, 'other' => 'x']], 10));
        $this->assertFalse(SurveyTypes::selects(9, 10));
        $this->assertFalse(SurveyTypes::selects([], 10));
        $this->assertFalse(SurveyTypes::selects(null, 10));
        $this->assertFalse(SurveyTypes::selects('hello', 10));
    }

    // --------------------------------------------------------------- pairwise

    /** @return list<array{option_id:int,role:string,is_other:int,label:string}> */
    private function pairwiseOptions(int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = ['option_id' => 100 + $i, 'role' => 'choice', 'is_other' => 0, 'label' => 'O' . $i];
        }
        return $out;
    }

    public function testPairwiseSeedsThreeOptionsAndDropsSettings(): void
    {
        $this->assertSame(
            [
                ['role' => 'choice', 'label' => 'Option 1'],
                ['role' => 'choice', 'label' => 'Option 2'],
                ['role' => 'choice', 'label' => 'Option 3'],
            ],
            SurveyTypes::seedOptions('pairwise')
        );
        $v = SurveyTypes::validateSettings('pairwise', ['randomize' => true]);
        $this->assertTrue($v['ok']);
        $this->assertSame([], $v['settings']);
    }

    /** Every band boundary (spec §2 worked values): 28/36, 105/120, 190/210, 300/325. */
    public function testPairwisePlanAtEveryBandBoundary(): void
    {
        $expect = [
            0  => [0, true, [], [], 0],
            1  => [0, true, [], [], 0],
            2  => [1, true, [], [], 1],
            3  => [3, true, [], [], 3],
            8  => [28, true, [], [], 28],
            9  => [36, false, [30, 40, 50, 60], [11, 15, 18, 22], 11],
            12 => [66, false, [30, 40, 50, 60], [20, 27, 33, 40], 20],
            15 => [105, false, [30, 40, 50, 60], [32, 42, 53, 63], 32],
            16 => [120, false, [20, 30, 40, 50], [24, 36, 48, 60], 24],
            20 => [190, false, [20, 30, 40, 50], [38, 57, 76, 95], 38],
            21 => [210, false, [10, 20, 30, 40], [21, 42, 63, 84], 21],
            25 => [300, false, [10, 20, 30, 40], [30, 60, 90, 120], 30],
            26 => [325, false, [10, 15, 20, 25], [33, 49, 65, 82], 33],
            30 => [435, false, [10, 15, 20, 25], [44, 66, 87, 109], 44],
        ];
        foreach ($expect as $n => [$possible, $small, $pcts, $tiers, $gate]) {
            $this->assertSame(
                ['possible' => $possible, 'small' => $small, 'band_pcts' => $pcts, 'tiers' => $tiers, 'gate' => $gate],
                SurveyTypes::pairwisePlan($n),
                'n=' . $n
            );
        }
    }

    public function testPairwiseGateMessages(): void
    {
        $this->assertSame('Please finish all 15 matchups to continue.', SurveyTypes::pairwiseGateMessage(SurveyTypes::pairwisePlan(6)));
        $this->assertSame('Please complete at least 20 matchups to continue.', SurveyTypes::pairwiseGateMessage(SurveyTypes::pairwisePlan(12)));
    }

    public function testPairwiseValidAnswerBecomesOneRowPerMatchup(): void
    {
        $q = ['type' => 'pairwise', 'required' => 0];
        $r = SurveyTypes::validateAnswer($q, $this->pairwiseOptions(3), [
            ['a' => 100, 'b' => 101, 'w' => 100],
            ['a' => 102, 'b' => 100, 'w' => 0],
            ['a' => 101, 'b' => 102, 'w' => 102],
        ]);
        $this->assertTrue($r['ok'], (string) $r['error']);
        $this->assertSame([
            ['option_id' => 100, 'row_option_id' => 101, 'value_text' => null, 'value_num' => 1.0],
            ['option_id' => 102, 'row_option_id' => 100, 'value_text' => null, 'value_num' => 0.5],
            ['option_id' => 101, 'row_option_id' => 102, 'value_text' => null, 'value_num' => 0.0],
        ], $r['rows']);
    }

    public function testPairwiseRejectsBadEntries(): void
    {
        $q = ['type' => 'pairwise', 'required' => 0];
        $opts = $this->pairwiseOptions(3);
        $cases = [
            'unknown option' => [[['a' => 100, 'b' => 999, 'w' => 100]], 'That option is not part of this question.'],
            'same option'    => [[['a' => 100, 'b' => 100, 'w' => 100]], 'Please make your picks again.'],
            'bad winner'     => [[['a' => 100, 'b' => 101, 'w' => 102]], 'Please make your picks again.'],
            'not a list'     => ['hello', 'Please make your picks again.'],
            'missing key'    => [[['a' => 100, 'b' => 101]], 'Please make your picks again.'],
            'repeat pair'    => [[['a' => 100, 'b' => 101, 'w' => 100], ['a' => 101, 'b' => 100, 'w' => 0]], 'Each matchup may be answered only once.'],
        ];
        foreach ($cases as $label => [$value, $error]) {
            $r = SurveyTypes::validateAnswer($q, $opts, $value);
            $this->assertFalse($r['ok'], $label);
            $this->assertSame($error, $r['error'], $label);
        }
    }

    public function testPairwiseRequiredGate(): void
    {
        // 9 options = 36 matchups, gate 11.
        $opts = $this->pairwiseOptions(9);
        $pairs = [];
        for ($i = 0; $i < 9; $i++) {
            for ($j = $i + 1; $j < 9; $j++) {
                $pairs[] = ['a' => 100 + $i, 'b' => 100 + $j, 'w' => 0];
            }
        }
        $req = ['type' => 'pairwise', 'required' => 1];

        $below = SurveyTypes::validateAnswer($req, $opts, array_slice($pairs, 0, 10));
        $this->assertFalse($below['ok']);
        $this->assertSame('Please complete at least 11 matchups to continue.', $below['error']);

        $at = SurveyTypes::validateAnswer($req, $opts, array_slice($pairs, 0, 11));
        $this->assertTrue($at['ok']);
        $this->assertCount(11, $at['rows']);

        $empty = SurveyTypes::validateAnswer($req, $opts, []);
        $this->assertSame('Please complete at least 11 matchups to continue.', $empty['error']);

        $optional = SurveyTypes::validateAnswer(['type' => 'pairwise', 'required' => 0], $opts, []);
        $this->assertTrue($optional['ok']);
        $this->assertSame([], $optional['rows']);
    }

    public function testPairwiseSmallRequiredSetNeedsEveryMatchup(): void
    {
        $opts = $this->pairwiseOptions(3);   // 3 matchups
        $req = ['type' => 'pairwise', 'required' => 1];
        $two = SurveyTypes::validateAnswer($req, $opts, [
            ['a' => 100, 'b' => 101, 'w' => 100],
            ['a' => 100, 'b' => 102, 'w' => 100],
        ]);
        $this->assertSame('Please finish all 3 matchups to continue.', $two['error']);
    }
}
