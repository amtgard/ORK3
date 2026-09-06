<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScrollRecipeTest extends TestCase
{
    public function testTheVocabularyListsEveryGroundBorderAndNumeralSet(): void
    {
        $r = Scroll::Recipes();

        $this->assertCount(12, $r['grounds'], 'expected 12 full-page grounds');
        $this->assertCount(11, $r['borders'], 'expected none, the knotwork, plus 9 rasters');
        $this->assertCount(4, $r['rankSets'], 'expected common, zodiac, garber, flame');
        $this->assertCount(16, $r['palette'], "expected the 16 orders' colour pairs");

        $this->assertArrayHasKey('knot', $r['borders'], 'the parametric border must be selectable');
    }

    /** Every ground and raster border must be a file we actually ship. */
    public function testEveryPieceInTheVocabularyExistsOnDisk(): void
    {
        $r = Scroll::Recipes();

        foreach ($r['grounds'] as $key => $path) {
            $this->assertFileExists(DIR_ASSETS . 'scrolls/' . $path, "ground {$key} missing");
        }
        foreach ($r['borders'] as $key => $path) {
            if ($key === 'knot') {
                continue;   // the parametric SVG partial, not a file under assets/
            }
            $this->assertFileExists(DIR_ASSETS . 'scrolls/' . $path, "border {$key} missing");
        }
        foreach ($r['rankSets'] as $key => $dir) {
            // Ranks 1-10 are the guaranteed span; garber also ships rank_0, which the
            // custom scroll deliberately never uses.
            for ($n = 1; $n <= 10; $n++) {
                $this->assertFileExists(
                    DIR_ASSETS . 'scrolls/' . $dir . '/rank_' . $n . '.png',
                    "rank set {$key} missing rank_{$n}"
                );
            }
        }
    }

    public function testTheDefaultRecipeIsItselfValid(): void
    {
        $r = Scroll::Recipes();
        $d = Scroll::DEFAULT_RECIPE;

        $this->assertArrayHasKey($d['ground'], $r['grounds']);
        $this->assertArrayHasKey($d['border'], $r['borders']);
        $this->assertArrayHasKey($d['rankSet'], $r['rankSets']);
        $this->assertSame(Scroll::DEFAULT_RECIPE, Scroll::NormaliseRecipe([]));
    }

    public function testNormaliseReplacesAnythingUnknownWithTheDefault(): void
    {
        $out = Scroll::NormaliseRecipe([
            'ground'  => '../../etc/passwd',
            'border'  => 'nonsense',
            'rankSet' => 'nope',
            'primary' => 'javascript:alert(1)',
        ]);

        $this->assertSame(Scroll::DEFAULT_RECIPE, $out);
    }

    public function testNormaliseKeepsValidChoices(): void
    {
        // parchment, not linen: linen is one of the five grounds that carry their own
        // border, where NormaliseRecipe() deliberately overrides the requested border.
        // That rule has its own tests below; this one is about a valid choice surviving.
        $out = Scroll::NormaliseRecipe([
            'ground'  => 'parchment',
            'border'  => 'lion',
            'rankSet' => 'zodiac',
            'primary' => 'hsl(114, 57%, 37%)',
            'secondary' => 'hsl(41, 93%, 55%)',
        ]);

        $this->assertSame('parchment', $out['ground']);
        $this->assertSame('lion', $out['border']);
        $this->assertSame('zodiac', $out['rankSet']);
        $this->assertSame('hsl(114, 57%, 37%)', $out['primary']);
    }

    /**
     * The guard on custom.tpl's exception handling.
     *
     * Six pieces of the shipped artwork do not behave the way their vocabulary slot
     * says they do — two washes filed as grounds, a black plate and a 2292x292 band
     * filed as borders, a dark ground, and three rank sets that are plates rather than
     * glyphs. Scroll names them, and custom.tpl composes each one differently.
     *
     * Every one of those constants keys on a vocabulary NAME. Rename `arcane` to
     * `arcanewash` in scroll-recipes.php and nothing else in this suite notices: the
     * file still exists, so testEveryPieceInTheVocabularyExistsOnDisk passes, and the
     * emitted src still resolves, so ScrollAssetPathsTest passes — while the
     * certificate silently starts printing a mean-luminance-120 wash as a bare plate
     * with the type sunk into it. Dropping `owl` gives a black page, dropping `battle`
     * an 11x vertical smear, and both just as quietly.
     *
     * The [data-ground="cyanotype"] selectors in scrollery.css key on the same name as
     * DARK_GROUNDS, so this covers them too.
     */
    public function testEveryRecipeExceptionStillNamesAnEntryInTheVocabulary(): void
    {
        $r = Scroll::Recipes();

        foreach (array_keys(Scroll::GROUND_WASHES) as $ground) {
            $this->assertArrayHasKey($ground, $r['grounds'], "GROUND_WASHES names a ground that no longer exists: {$ground}");
        }
        foreach (Scroll::DARK_GROUNDS as $ground) {
            $this->assertArrayHasKey($ground, $r['grounds'], "DARK_GROUNDS names a ground that no longer exists: {$ground}");
        }
        foreach (array_keys(Scroll::BORDER_STYLES) as $border) {
            $this->assertArrayHasKey($border, $r['borders'], "BORDER_STYLES names a border that no longer exists: {$border}");
        }

        $this->assertArrayHasKey(Scroll::BORDER_BAND, $r['borders'], 'BORDER_BAND names a border that no longer exists');
        $this->assertArrayHasKey(Scroll::RANK_SET_GLYPH, $r['rankSets'], 'RANK_SET_GLYPH names a rank set that no longer exists');
        $this->assertArrayHasKey(Scroll::RANK_SET_OVERSIZED, $r['rankSets'], 'RANK_SET_OVERSIZED names a rank set that no longer exists');
    }

    /** A colour outside the curated palette is refused — free entry was rejected. */
    public function testColoursMustComeFromTheCuratedPalette(): void
    {
        $out = Scroll::NormaliseRecipe(['primary' => '#ff00ff', 'secondary' => '#00ff00']);

        $this->assertSame(Scroll::DEFAULT_RECIPE['primary'], $out['primary']);
        $this->assertSame(Scroll::DEFAULT_RECIPE['secondary'], $out['secondary']);
    }
    /**
     * Five grounds have a border painted into the plate, so no border layer may be
     * drawn over them — the flame ground with a knot border rendered TWO borders, one
     * on top of the other.
     *
     * Enforced in NormaliseRecipe() rather than only in the composer because the
     * recipe travels in the query string: the UI going inert stops the honest mistake,
     * this stops the hand-edited URL.
     */
    public function testAGroundThatCarriesItsOwnBorderRefusesAnyOther(): void
    {
        foreach (['linen', 'jovious', 'flame', 'sunburst', 'map'] as $ground) {
            $this->assertTrue(Scroll::GroundDrawsOwnBorder($ground), $ground);

            foreach (['knot', 'lion', 'battle', 'owl'] as $border) {
                $out = Scroll::NormaliseRecipe(['ground' => $ground, 'border' => $border]);
                $this->assertSame($ground, $out['ground'], $ground);
                $this->assertSame(Scroll::BORDER_NONE, $out['border'], "{$ground}/{$border}");
            }
        }
    }

    /** The other seven are fields or washes with no frame, and keep the chosen border. */
    public function testAPlainGroundKeepsTheChosenBorder(): void
    {
        foreach (['parchment', 'starfield', 'starfield2', 'cyanotype', 'forge', 'rosewash', 'arcane'] as $ground) {
            $this->assertFalse(Scroll::GroundDrawsOwnBorder($ground), $ground);

            $out = Scroll::NormaliseRecipe(['ground' => $ground, 'border' => 'lion']);
            $this->assertSame('lion', $out['border'], $ground);
        }
    }

    /** 'none' is selectable in its own right, for a plain sheet. */
    public function testNoneIsAValidBorderChoiceOnAPlainGround(): void
    {
        $out = Scroll::NormaliseRecipe(['ground' => 'parchment', 'border' => Scroll::BORDER_NONE]);
        $this->assertSame(Scroll::BORDER_NONE, $out['border']);
    }

}
