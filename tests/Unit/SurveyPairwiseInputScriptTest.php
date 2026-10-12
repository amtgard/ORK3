<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * survey-render.js's pairwise input guards under node
 * (tests/Unit/js/survey-pairwise-input-harness.js): a pick is only ever
 * recorded for a matchup the respondent saw, a re-render replaces the
 * question's live state instead of leaking another entry, and a builder
 * preview keeps no live state behind it.
 */
final class SurveyPairwiseInputScriptTest extends TestCase
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
        $raw = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/js/survey-pairwise-input-harness.js') . ' 2>&1');
        $res = json_decode(trim((string) $raw), true);
        $this->assertIsArray($res, 'harness output: ' . $raw);
        return self::$out = $res;
    }

    public function testHeldArrowKeyRecordsOnlyTheFirstPress(): void
    {
        $h = $this->harness()['heldKey'];
        $this->assertSame(1, $h['picks'], 'auto-repeat keydowns never pick');
        $this->assertTrue($h['repeatPrevented'], 'a repeat inside the matchup still does not scroll the page');
        $this->assertSame(2, $h['afterFreshPress'], 'a fresh press picks again');
    }

    public function testReducedMotionStillSwallowsADoubleClick(): void
    {
        $out = $this->harness();
        $this->assertSame(1, $out['reducedDouble']);
        $this->assertSame(2, $out['reducedAfterWindow'], 'after the short window a pick counts again');
        $this->assertSame(1, $out['motionDouble']);
    }

    public function testReRenderReplacesTheQuestionsLiveState(): void
    {
        $h = $this->harness()['rerender'];
        $this->assertSame(1, $h['firstPicks']);
        $this->assertTrue($h['sameKey'], 'a re-render of the same question reuses its data-pw key');
        $this->assertSame(0, $h['oldMountReads'], 'the fresh render replaced the old entry; none is left behind');
        $this->assertTrue($h['otherQuestionKeyDiffers'], 'two questions never share a key');
    }

    public function testPreviewKeepsNoLiveState(): void
    {
        $out = $this->harness();
        $this->assertNull($out['previewRead']);
        $this->assertTrue($out['previewShowsFirstPair']);
        $this->assertSame(0, $out['previewPicks']);
    }
}
