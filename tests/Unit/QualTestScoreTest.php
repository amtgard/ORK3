<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization for QualTest scoring / thin Model_QualTest wrappers (RB-N).
 *
 * scoreTest is pure domain logic moved behind the frontend model boundary —
 * these cases lock single- and multi-answer scoring so controller thinning
 * cannot silently change pass/fail semantics.
 */
final class QualTestScoreTest extends TestCase
{
    private QualTest $qt;

    protected function setUp(): void
    {
        $this->qt = new QualTest();
    }

    public function testScoreTestSingleAllCorrect(): void
    {
        $correctMap = [
            10 => ['Mode' => 'single', 'AnswerIds' => [101]],
            11 => ['Mode' => 'single', 'AnswerIds' => [201]],
        ];
        $submitted = [
            10 => 101,
            11 => 201,
        ];

        $result = $this->qt->scoreTest($correctMap, $submitted);

        $this->assertSame(2, $result['correct']);
        $this->assertSame(2, $result['total']);
        $this->assertSame(100, $result['score_percent']);
    }

    public function testScoreTestSinglePartial(): void
    {
        $correctMap = [
            10 => ['Mode' => 'single', 'AnswerIds' => [101]],
            11 => ['Mode' => 'single', 'AnswerIds' => [201]],
        ];
        $submitted = [
            10 => 101,
            11 => 999,
        ];

        $result = $this->qt->scoreTest($correctMap, $submitted);

        $this->assertSame(1, $result['correct']);
        $this->assertSame(2, $result['total']);
        $this->assertSame(50, $result['score_percent']);
    }

    public function testScoreTestMultiExactMatchRequired(): void
    {
        $correctMap = [
            20 => ['Mode' => 'multi', 'AnswerIds' => [1, 2, 3]],
        ];

        $exact = $this->qt->scoreTest($correctMap, [20 => [3, 1, 2]]);
        $this->assertSame(1, $exact['correct']);
        $this->assertSame(100, $exact['score_percent']);

        $partial = $this->qt->scoreTest($correctMap, [20 => [1, 2]]);
        $this->assertSame(0, $partial['correct']);
        $this->assertSame(0, $partial['score_percent']);

        $extra = $this->qt->scoreTest($correctMap, [20 => [1, 2, 3, 4]]);
        $this->assertSame(0, $extra['correct']);
    }

    public function testScoreTestSingleAcceptsArrayShape(): void
    {
        $correctMap = [
            10 => ['Mode' => 'single', 'AnswerIds' => [101]],
        ];

        $result = $this->qt->scoreTest($correctMap, [10 => [101]]);

        $this->assertSame(1, $result['correct']);
        $this->assertSame(100, $result['score_percent']);
    }

    public function testScoreTestEmptyMapIsZero(): void
    {
        $result = $this->qt->scoreTest([], []);

        $this->assertSame(0, $result['correct']);
        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['score_percent']);
    }

    public function testModelQualTestScoreWrapperDelegates(): void
    {
        require_once DIR_UI . 'model/model.QualTest.php';
        $model = new Model_QualTest();
        $correctMap = [
            10 => ['Mode' => 'single', 'AnswerIds' => [5]],
            11 => ['Mode' => 'single', 'AnswerIds' => [6]],
        ];
        $submitted = [10 => 5, 11 => 7];

        $result = $model->score_test($correctMap, $submitted);

        $this->assertSame(1, $result['correct']);
        $this->assertSame(2, $result['total']);
        $this->assertSame(50, $result['score_percent']);
    }
}
