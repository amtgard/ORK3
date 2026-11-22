<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The pairwise ranking table (Rank · Option · Strength · Win % · W · T · L · Matchups)
 * must fit the narrowest results card: the 420px grid track less its padding
 * and border, 386px. The number columns are one line each, so their header
 * gutters and a breakable Option column are what keep the table's minimum
 * width under that. At 16px gutters and an Option column as wide as its
 * longest word, the table needed 407px and Matchups was clipped in the
 * 389-405px cards (1170-1200px and 1630-1680px windows).
 */
final class SurveyResultsPairwiseTableCssTest extends TestCase
{
    private const HEAD = '.svr-card table.svr-pw-table.dataTable thead > tr > th';

    private static ?string $css = null;

    private function rule(string $selector): array
    {
        if (self::$css === null) {
            self::$css = (string) file_get_contents(__DIR__ . '/../../orkui/template/default/style/survey-results.css');
        }
        $this->assertSame(
            1,
            preg_match('/' . preg_quote($selector, '/') . '\s*\{([^}]*)\}/', self::$css, $m),
            'no rule for ' . $selector
        );
        $decls = [];
        foreach (explode(';', $m[1]) as $decl) {
            $parts = explode(':', $decl, 2);
            if (count($parts) === 2) {
                $decls[trim($parts[0])] = trim($parts[1]);
            }
        }
        return $decls;
    }

    private function px(string $value): int
    {
        $this->assertMatchesRegularExpression('/^\d+px$/', $value);
        return (int) $value;
    }

    public function testALongWordInAnOptionLabelDoesNotSetTheTableWidth(): void
    {
        // break-word would not lower the column's minimum width; anywhere does.
        $this->assertSame('anywhere', $this->rule('.svr-pw-table td:nth-child(2)')['overflow-wrap'] ?? null);
    }

    public function testNumberHeaderGuttersStayNarrow(): void
    {
        $right = $this->px($this->rule(self::HEAD)['padding-right'] ?? '');
        $left  = $this->px($this->rule(self::HEAD . ':nth-child(n+3)')['padding-left'] ?? '');
        $this->assertLessThanOrEqual(14, $right, 'sort-arrow gutter');
        $this->assertLessThanOrEqual(4, $left, 'left padding of the right-aligned number headers');
        $this->assertSame('nowrap', $this->rule(self::HEAD)['white-space'] ?? null);
    }

    /**
     * On a phone the card is the whole width (~330px). Five one-line number
     * columns left Option one character wide: nearly every label broke
     * mid-word ("Tourna|ment") and the table still overflowed at 360-400px.
     * Phones drop the W / T / L split (the chart tooltip keeps it), so
     * Option has room to break only between words.
     */
    public function testPhonesDropTheWinTieLossSplitSoLabelsBreakBetweenWords(): void
    {
        $this->rule(self::HEAD);   // loads the stylesheet
        $this->assertSame(
            1,
            preg_match('/@media \(max-width: 600px\)\s*\{((?:[^{}]*\{[^}]*\})+)\s*\}/', (string) self::$css, $m),
            'no phone block for the pairwise table'
        );
        $block = $m[1];
        // W / T / L sit at 5-7, after Rank · Option · Strength · Win %.
        foreach ([5, 6, 7] as $col) {
            foreach (['th', 'td'] as $cell) {
                $this->assertStringContainsString('.svr-card table.svr-pw-table ' . $cell . ':nth-child(' . $col . ')', $block);
            }
        }
        $this->assertStringNotContainsString(':nth-child(4)', $block, 'Win % must stay on phones');
        $this->assertMatchesRegularExpression('/:nth-child\(7\)\s*\{\s*display:\s*none;/', $block);
        $this->assertMatchesRegularExpression(
            '/\.svr-card table\.svr-pw-table td:nth-child\(2\)\s*\{\s*overflow-wrap:\s*break-word;/',
            $block
        );
    }

    /**
     * With the Strength column the eight columns need ~440-460px, more than
     * the 386px half-width card, so a narrow card drops W / T / L too.
     */
    public function testNarrowCardsDropTheWinTieLossSplit(): void
    {
        $this->rule(self::HEAD);   // loads the stylesheet
        $this->assertMatchesRegularExpression('/\.svr-pw-tablewrap\s*\{[^}]*container-type:\s*inline-size;/', (string) self::$css);
        $this->assertSame(
            1,
            preg_match('/@container \(max-width: (\d+)px\)\s*\{((?:[^{}]*\{[^}]*\})+)\s*\}/', (string) self::$css, $m),
            'no narrow-card block for the pairwise table'
        );
        $this->assertGreaterThanOrEqual(386, (int) $m[1]);
        foreach ([5, 6, 7] as $col) {
            foreach (['th', 'td'] as $cell) {
                $this->assertStringContainsString('.svr-card table.svr-pw-table ' . $cell . ':nth-child(' . $col . ')', $m[2]);
            }
        }
        $this->assertMatchesRegularExpression('/:nth-child\(7\)\s*\{\s*display:\s*none;/', $m[2]);
    }

    public function testSortArrowsDoNotRunIntoTheHeaderText(): void
    {
        // The arrows are about 8px wide at DataTables' .8em: they need the
        // gutter's padding-right less their inset, plus a little air.
        $right = $this->px($this->rule(self::HEAD)['padding-right'] ?? '');
        $inset = $this->px($this->rule(self::HEAD . ':after')['right'] ?? '');
        $this->assertGreaterThanOrEqual(11, $right - $inset);
    }
}
