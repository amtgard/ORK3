<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The last unguarded vocabulary names.
 *
 * ScrollRecipeTest::testEveryRecipeExceptionStillNamesAnEntryInTheVocabulary covers
 * the exception constants (GROUND_WASHES, DARK_GROUNDS, BORDER_STYLES, BORDER_BAND,
 * RANK_SET_GLYPH, RANK_SET_OVERSIZED). Four literals still escaped it:
 *
 *   - Scroll::DEFAULT_RECIPE names `parchment`, `knot` and `common` as bare strings,
 *     and carries a colour pair as two more;
 *   - scroll/custom.tpl compares $recipe['border'] === 'knot' as a bare string.
 *
 * Renaming any of those in scroll-recipes.php leaves every other test green while the
 * default recipe silently stops resolving — and the default recipe is what EVERY path
 * that prints without an explicit one uses: the reprint list, a bulk run, and the
 * composer's own opening state. The composer additionally preselects the option whose
 * value equals each DEFAULT_RECIPE name, so a rename would leave its selects landing
 * on whatever happened to be first instead.
 */
final class ScrollDefaultRecipeVocabularyTest extends TestCase
{
    public function testTheDefaultRecipeNamesEntriesThatStillExist(): void
    {
        $r = Scroll::Recipes();
        $d = Scroll::DEFAULT_RECIPE;

        $this->assertArrayHasKey(
            $d['ground'],
            $r['grounds'],
            "DEFAULT_RECIPE names a ground that no longer exists: {$d['ground']}"
        );
        $this->assertArrayHasKey(
            $d['border'],
            $r['borders'],
            "DEFAULT_RECIPE names a border that no longer exists: {$d['border']}"
        );
        $this->assertArrayHasKey(
            $d['rankSet'],
            $r['rankSets'],
            "DEFAULT_RECIPE names a rank set that no longer exists: {$d['rankSet']}"
        );
    }

    /**
     * NormaliseRecipe() only accepts primary and secondary as a PAIR drawn from the
     * palette, so a DEFAULT_RECIPE whose pair had drifted out of it would be rejected
     * by the very function that hands it back as the fallback.
     */
    public function testTheDefaultRecipeColoursAreStillACuratedPair(): void
    {
        $pairs = array_map(
            static fn (array $pair): string => $pair[0] . '|' . $pair[1],
            array_values(Scroll::Recipes()['palette'])
        );

        $this->assertContains(
            Scroll::DEFAULT_RECIPE['primary'] . '|' . Scroll::DEFAULT_RECIPE['secondary'],
            $pairs,
            'DEFAULT_RECIPE no longer names a pair from the curated palette'
        );
    }

    /** custom.tpl branches on this exact string to draw the parametric knotwork. */
    public function testTheKnotSentinelStillNamesTheParametricBorder(): void
    {
        $borders = Scroll::Recipes()['borders'];

        $this->assertArrayHasKey('knot', $borders, "custom.tpl compares against the bare string 'knot'");
        $this->assertSame(
            '',
            $borders['knot'],
            'knot is the parametric SVG, not a file — an empty path is what marks it as such'
        );
    }

    /** The default recipe must survive its own allowlist unchanged. */
    public function testNormalisingTheDefaultRecipeIsIdentity(): void
    {
        $this->assertSame(Scroll::DEFAULT_RECIPE, Scroll::NormaliseRecipe(Scroll::DEFAULT_RECIPE));
    }
}
