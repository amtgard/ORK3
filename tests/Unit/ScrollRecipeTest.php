<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScrollRecipeTest extends TestCase
{
    public function testTheVocabularyListsEveryGroundBorderAndNumeralSet(): void
    {
        $r = Scroll::Recipes();

        $this->assertCount(12, $r['grounds'], 'expected 12 full-page grounds');
        $this->assertCount(10, $r['borders'], 'expected the knotwork plus 9 rasters');
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
        $out = Scroll::NormaliseRecipe([
            'ground'  => 'linen',
            'border'  => 'lion',
            'rankSet' => 'zodiac',
            'primary' => 'hsl(114, 57%, 37%)',
            'secondary' => 'hsl(41, 93%, 55%)',
        ]);

        $this->assertSame('linen', $out['ground']);
        $this->assertSame('lion', $out['border']);
        $this->assertSame('zodiac', $out['rankSet']);
        $this->assertSame('hsl(114, 57%, 37%)', $out['primary']);
    }

    /** A colour outside the curated palette is refused — free entry was rejected. */
    public function testColoursMustComeFromTheCuratedPalette(): void
    {
        $out = Scroll::NormaliseRecipe(['primary' => '#ff00ff', 'secondary' => '#00ff00']);

        $this->assertSame(Scroll::DEFAULT_RECIPE['primary'], $out['primary']);
        $this->assertSame(Scroll::DEFAULT_RECIPE['secondary'], $out['secondary']);
    }
}
