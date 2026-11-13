<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The results card's "n = 59 of 61" tip says what the card's percentages are
 * of (tests/Unit/js/survey-results-badge-harness.js). A pairwise card's are of
 * matchups, a ranking or rating card has none, and only the choice types' are
 * of people.
 */
final class SurveyResultsBadgeScriptTest extends TestCase
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
        $raw = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/js/survey-results-badge-harness.js') . ' 2>&1');
        $res = json_decode(trim((string) $raw), true);
        $this->assertIsArray($res, 'harness output: ' . $raw);
        return self::$out = $res;
    }

    public function testPairwiseTipSaysItsPercentagesAreOfMatchups(): void
    {
        $this->assertSame(
            '59 people answered. The percentages here are of matchups, not people. 61 responses reached this question; 2 left it blank.',
            $this->harness()['pairwise']
        );
    }

    public function testRankingTipClaimsNoPercentages(): void
    {
        $this->assertSame('59 people answered. 61 responses reached this question; 2 left it blank.', $this->harness()['ranking']);
    }

    public function testRatingTipClaimsNoPercentages(): void
    {
        $this->assertSame('59 people answered. 61 responses reached this question; 2 left it blank.', $this->harness()['rating']);
    }

    public function testChoiceAndTextTipsAreUnchanged(): void
    {
        $out = $this->harness();
        $this->assertSame('Percentages are of the 59 people who answered. 61 responses reached this question; 2 left it blank.', $out['single']);
        $this->assertSame($out['single'], $out['multi']);
        $this->assertSame('59 people answered. 61 responses reached this question; 2 left it blank.', $out['short_text']);
    }
}
