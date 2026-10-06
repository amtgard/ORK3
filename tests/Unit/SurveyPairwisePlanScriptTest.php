<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * survey-render.js's pairwise core under node (tests/Unit/js/survey-pairwise-harness.js).
 * The builder recomputes the plan in the browser from a JS copy of
 * SurveyTypes::PAIRWISE_BANDS; this pins that copy to the PHP for every option
 * count 0..60, and checks the queue and the encouragement stages (spec §2, §4, §6).
 */
final class SurveyPairwisePlanScriptTest extends TestCase
{
    private static ?array $out = null;

    private function harness(): array
    {
        if (self::$out !== null) {
            return self::$out;
        }
        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }
        $raw = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/js/survey-pairwise-harness.js') . ' 2>&1');
        $res = json_decode(trim((string) $raw), true);
        $this->assertIsArray($res, 'harness output: ' . $raw);
        return self::$out = $res;
    }

    public function testJsPlanMatchesPhpForEveryOptionCountUpTo60(): void
    {
        $out = $this->harness();
        for ($n = 0; $n <= 60; $n++) {
            $this->assertSame(SurveyTypes::pairwisePlan($n), $out['plans'][$n], 'n=' . $n);
        }
    }

    public function testQueueCoversEveryPairOnceWithRandomSides(): void
    {
        $q = $this->harness()['queue'];
        $this->assertSame(28, $q['count']);
        $this->assertSame(28, $q['unique']);
        $this->assertTrue($q['flipped'], 'some matchups show the higher id on the left');
        $this->assertSame(26, $q['afterDone']);
        $this->assertFalse($q['containsDone'], 'answered pairs never come back, in either order');
    }

    public function testQueueRarelyRepeatsAnOptionBackToBack(): void
    {
        // A plain shuffle of 8 options repeats an option in ~44% of consecutive matchups.
        $this->assertLessThan(0.10, $this->harness()['queue']['repeatRate']);
    }

    public function testPreviewQueueKeepsAuthoredOrder(): void
    {
        $this->assertSame([['a' => 1, 'b' => 2], ['a' => 1, 'b' => 3], ['a' => 2, 'b' => 3]], $this->harness()['queue']['preview']);
    }

    public function testStagesAndMessages(): void
    {
        $s = $this->harness()['stage'];
        $this->assertSame(['level' => 0, 'message' => 'Finish all 15 matchups to continue.', 'complete' => false], $s['smallReq0']);
        $this->assertSame(['level' => 1, 'message' => '', 'complete' => false], $s['smallOpt5']);
        $this->assertSame(['level' => 2, 'message' => '', 'complete' => false], $s['smallOpt10']);
        $this->assertSame(['level' => 4, 'message' => 'Whoa, you ranked them all! Incredible job, we thank you!', 'complete' => true], $s['small15']);
        $this->assertSame(['level' => 0, 'message' => '1 more matchup to go before you can continue.', 'complete' => false], $s['bigReq19']);
        $this->assertSame(['level' => 0, 'message' => 'Every matchup helps. Do as many as you like.', 'complete' => false], $s['bigOpt0']);
        $this->assertSame('This is a great start. You can move on, but you can make our survey better by doing a few more matchups!', $s['big20']['message']);
        $this->assertSame(1, $s['big20']['level']);
        $this->assertSame('Even better! You can keep going for better results or continue.', $s['big27']['message']);
        $this->assertSame('Awesome! This is a great sample. Feel free to keep ranking or continue on.', $s['big33']['message']);
        $this->assertSame("Fantastic! You've given us a great sample size, so you can keep going or continue on. Your choice!", $s['big40']['message']);
        $this->assertSame(4, $s['big40']['level']);
        $this->assertTrue($s['big66']['complete']);
    }

    public function testGateMessagesMatchThePhp(): void
    {
        $this->assertSame(
            [SurveyTypes::pairwiseGateMessage(SurveyTypes::pairwisePlan(6)), SurveyTypes::pairwiseGateMessage(SurveyTypes::pairwisePlan(12))],
            $this->harness()['gate']
        );
    }
}
