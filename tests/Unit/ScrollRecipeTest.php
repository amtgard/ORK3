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

        foreach (Scroll::PALE_RANK_SETS as $set) {
            $this->assertArrayHasKey($set, $r['rankSets'], "PALE_RANK_SETS names a rank set that no longer exists: {$set}");
        }
    }

    /**
     * The pale sets are the ones whose numerals are painted for a DARK plate, and
     * putting one on a pale ground is what made the rank numeral vanish — zodiac on
     * parchment measured 1.50:1 over the numeral's own ink, against 11.35:1 as the
     * worst the text ever gets.
     *
     * Asserted as a closed set rather than a spot check: the guard is that somebody
     * adding a fifth rank set has to decide which side of this line it falls on, and
     * a `count` is what makes them notice. `garber` is deliberately absent — its
     * numerals are outlined, carrying ink from L 0 to L 0.892 in one glyph, so they
     * read on anything and darkening them would flatten the outline.
     */
    public function testOnlyTheDarkPlateRankSetsAreMarkedPale(): void
    {
        $this->assertSame(['zodiac', 'flame'], Scroll::PALE_RANK_SETS);
        $this->assertNotContains('common', Scroll::PALE_RANK_SETS, 'common is dark ink; it needs no lift');
        $this->assertNotContains('garber', Scroll::PALE_RANK_SETS, 'garber is outlined; see the constant');

        $this->assertCount(
            4,
            Scroll::Recipes()['rankSets'],
            'a rank set was added or removed — decide whether it is pale and update PALE_RANK_SETS'
        );
    }

    /**
     * The hands are a closed vocabulary reached from four surfaces, so the shape is
     * asserted rather than assumed: a hand with no label renders an empty <option>,
     * and one with no fonts preloads nothing — which on font-display:block faces
     * means a certificate that prints blank where the text goes.
     */
    public function testEveryHandCarriesALabelAndItsPreloadFonts(): void
    {
        $hands = Scroll::Hands();
        $this->assertNotEmpty($hands);

        foreach ($hands as $key => $hand) {
            $this->assertMatchesRegularExpression('/^[a-z]+$/', $key, 'a hand key reaches an HTML attribute and &face=');
            $this->assertArrayHasKey('label', $hand, $key);
            $this->assertNotSame('', trim($hand['label']), $key . ' would render an empty option');
            $this->assertNotEmpty($hand['fonts'], $key . ' would preload nothing');

            foreach ($hand['fonts'] as $file) {
                $this->assertStringEndsWith('.woff2', $file, $key);
                $this->assertFileExists(
                    DIR_TEMPLATE . 'revised-frontend/style/fonts/' . $file,
                    $key . ' preloads a face that is not shipped'
                );
            }
        }
    }

    /**
     * Every hand in the data has a rule in the stylesheet that actually selects it.
     *
     * This is the drift that would not announce itself. A hand added to
     * scroll-recipes.php with no [data-scroll-face] block still appears in all four
     * pickers, still travels in &face=, still preloads its face — and renders in
     * BlackChancery, because nothing ever set --sc-display-face. Silent, and only
     * visible to somebody who knows what the hand was supposed to look like.
     *
     * The default hand is exempt: it is the bare .award-template declaration, which
     * is deliberately not written as an attribute selector so that a certificate with
     * no hand at all still has a face.
     */
    public function testEveryHandHasAStylesheetBlockThatSelectsIt(): void
    {
        $css = file_get_contents(DIR_TEMPLATE . 'revised-frontend/style/scrollery.css');
        $this->assertNotFalse($css);

        foreach (array_keys(Scroll::Hands()) as $key) {
            if ($key === Scroll::DEFAULT_HAND) {
                continue;
            }

            $this->assertStringContainsString(
                '[data-scroll-face="' . $key . '"] .award-template',
                $css,
                $key . ' would silently render in the default face'
            );
        }
    }

    /**
     * And every face those blocks name is actually declared with an @font-face.
     *
     * A --sc-display-face naming a family with no @font-face falls straight through
     * to the next entry in the font stack, which is Grenze — so the hand renders,
     * looks plausible, and is not the hand anybody chose.
     */
    public function testEveryDisplayFaceNamedByAHandIsDeclared(): void
    {
        $css = file_get_contents(DIR_TEMPLATE . 'revised-frontend/style/scrollery.css');

        preg_match_all('/--sc-display-face:\s*"([^"]+)"/', $css, $used);
        preg_match_all('/font-family:\s*\'([^\']+)\'/', $css, $declared);

        $this->assertNotEmpty($used[1]);
        foreach (array_unique($used[1]) as $family) {
            $this->assertContains(
                $family,
                $declared[1],
                $family . ' is selected by a hand but never declared with @font-face'
            );
        }
    }

    /**
     * The picker draws each option in the face it names, so `family` must be the same
     * face --sc-display-face selects for that key. Previewing one face and printing
     * another is worse than previewing nothing.
     */
    public function testEachHandsPickerFamilyMatchesTheFaceItPrintsIn(): void
    {
        $css = file_get_contents(DIR_TEMPLATE . 'revised-frontend/style/scrollery.css');

        foreach (Scroll::Hands() as $key => $hand) {
            $this->assertArrayHasKey('family', $hand, $key);
            $this->assertArrayHasKey('file', $hand, $key);
            $this->assertSame(
                $hand['fonts'][0],
                $hand['file'],
                $key . ': the specimen file must be the hand\'s own display face'
            );
            $this->assertFileExists(DIR_TEMPLATE . 'revised-frontend/style/fonts/' . $hand['file'], $key);

            /* The default hand's face is set on bare .award-template, not behind an
               attribute selector — see testEveryHandHasAStylesheetBlockThatSelectsIt. */
            $needle = $key === Scroll::DEFAULT_HAND
                ? '.award-template {'
                : '[data-scroll-face="' . $key . '"] .award-template {';
            $pos = strpos($css, $needle);
            $this->assertNotFalse($pos, $key . ' has no stylesheet block');

            $block = substr($css, $pos, strpos($css, '}', $pos) - $pos);
            $this->assertStringContainsString(
                '--sc-display-face: "' . $hand['family'] . '"',
                $block,
                $key . ': the picker would preview a different face than it prints'
            );
        }
    }

    /** Labels are specimens, not captions — the font's own name, set in that font. */
    public function testHandLabelsNameTheirFont(): void
    {
        foreach (Scroll::Hands() as $key => $hand) {
            $this->assertStringEndsWith('Scroll Font', $hand['label'], $key);
            $this->assertStringNotContainsString('—', $hand['label'], $key . ': labels are names, not commentary');
        }
    }

    /**
     * The reason-lines switch parses like the hand: an allowlist of one value that
     * turns it OFF, and everything else — absent, empty, a typo — meaning the state
     * every certificate already prints in. A flag whose typo silently produces a
     * DIFFERENT certificate is worse than one that ignores you.
     */
    public function testReasonLinesAreOnUnlessExplicitlyTurnedOff(): void
    {
        $this->assertTrue(Scroll::SHOW_LINES_DEFAULT);

        $this->assertFalse(Scroll::NormaliseShowLines('0'));
        $this->assertFalse(Scroll::NormaliseShowLines(' 0 '), 'a padded value is still a 0');

        foreach ([null, '', '1', 'on', 'banana', '00', 'false', '-0'] as $v) {
            $this->assertTrue(
                Scroll::NormaliseShowLines($v),
                var_export($v, true) . ' must leave the lines on'
            );
        }
    }

    /** The default has to be one of them, or every surface falls back to nothing. */
    public function testTheDefaultHandIsOneOfTheHands(): void
    {
        $this->assertArrayHasKey(Scroll::DEFAULT_HAND, Scroll::Hands());
    }

    /**
     * NormaliseHand() is an allowlist, not a sanitiser: the value lands in an HTML
     * attribute and selects a font, so anything unknown must become the default
     * rather than reaching either.
     */
    public function testAnUnknownHandFallsBackToTheDefault(): void
    {
        foreach (array_keys(Scroll::Hands()) as $key) {
            $this->assertSame($key, Scroll::NormaliseHand($key));
        }

        // Case and stray characters are stripped first, so a hand-typed URL still works.
        $this->assertSame('cinzel', Scroll::NormaliseHand('Cinzel'));
        $this->assertSame('cinzel', Scroll::NormaliseHand('  cinzel  '));

        foreach (['', 'comic', 'chancery"onload=x', '../../etc/passwd', '<script>'] as $bad) {
            $this->assertSame(Scroll::DEFAULT_HAND, Scroll::NormaliseHand($bad), $bad);
        }
    }

    /** HandFonts() answers through the same allowlist, so it can never 404 a preload. */
    public function testHandFontsAlwaysAnswersARealHand(): void
    {
        $this->assertSame(
            Scroll::Hands()[Scroll::DEFAULT_HAND]['fonts'],
            Scroll::HandFonts('no-such-hand')
        );
        $this->assertSame(Scroll::Hands()['sharp']['fonts'], Scroll::HandFonts('sharp'));
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

    /**
     * The signature block's lift for a COMPOSED certificate.
     *
     * The block is the last thing on the page and sits lowest, which is where a
     * border's corner ornaments and a full-page rank plate's own furniture also are.
     * Every number here came out of rendering the pairing and counting how much of
     * the date and signature boxes landed on dark ink; this pins
     * the SHAPE of the answer so a later edit cannot quietly flatten it.
     */
    public function testSignLiftIsZeroForThePairingsThatMeasuredClean(): void
    {
        // `common` is a 292x171 glyph on a bottom offset — it never reaches the block.
        foreach (array_keys(Scroll::BORDER_INSETS) as $border) {
            if ($border === 'crown') {
                continue;   // crown's own ornaments are the one border that intrudes
            }
            $this->assertSame(
                0,
                Scroll::SignLiftFor($border, 'common', 7),
                $border . ' + the glyph rank set measured clean and must not be lifted'
            );
        }

        // zodiac and flame only collide against the deepest inset in the set.
        $this->assertSame(0, Scroll::SignLiftFor('knot', 'zodiac', 7));
        $this->assertSame(0, Scroll::SignLiftFor('lion', 'zodiac', 7));
        $this->assertSame(0, Scroll::SignLiftFor('knot', 'flame', 7));
        $this->assertGreaterThan(0, Scroll::SignLiftFor('hunter', 'zodiac', 7));
        $this->assertGreaterThan(0, Scroll::SignLiftFor('hunter', 'flame', 7));
    }

    /** crown is the only border whose art reaches the block, at every rank set. */
    public function testTheCrownBorderLiftsOnItsOwn(): void
    {
        $this->assertSame(290, Scroll::SignLiftFor('crown', 'common', 0));
        $this->assertSame(290, Scroll::SIGN_LIFT_BORDER['crown']);
        $this->assertArrayNotHasKey('knot', Scroll::SIGN_LIFT_BORDER);
    }

    /**
     * garber's plates are full-page furniture drawn for garber's OWN wider block, so
     * they collide on every border, and worse as the rank (and the plate) grows.
     */
    public function testTheGarberRankSetLiftsOnEveryBorderAndGrowsWithTheRank(): void
    {
        foreach (array_keys(Scroll::BORDER_INSETS) as $border) {
            $this->assertGreaterThan(
                0,
                Scroll::SignLiftFor($border, 'garber', 10),
                $border . ' + garber rank 10 must be lifted'
            );
        }

        $shallow = Scroll::SignLiftFor('knot', 'garber', 3);
        $mid     = Scroll::SignLiftFor('knot', 'garber', 7);
        $high    = Scroll::SignLiftFor('knot', 'garber', 10);
        $this->assertLessThan($mid, $shallow, 'a bigger plate needs a bigger lift');
        $this->assertLessThan($high, $mid);

        // A deeper inset pushes the columns further under the plate, so it needs more.
        $this->assertGreaterThan(
            Scroll::SignLiftFor('knot', 'garber', 10),
            Scroll::SignLiftFor('hunter', 'garber', 10),
            'hunter puts the columns 159px further in than the flat padding does'
        );
    }

    /** The two axes combine by max(), not by sum — they are the same 595px box. */
    public function testTheBorderAndRankSetLiftsDoNotAccumulate(): void
    {
        $border  = Scroll::SignLiftFor('crown', 'common', 3);
        $rankSet = Scroll::SignLiftFor('knot', 'garber', 3);
        $both    = Scroll::SignLiftFor('crown', 'garber', 3);

        $this->assertSame(max($border, Scroll::SignLiftFor('hunter', 'garber', 3)), $both);
        $this->assertLessThan($border + $rankSet, $both);
    }

    /** An unknown border or rank set must not fatal, and must not invent a lift. */
    public function testAnUnknownRecipeFallsBackToNoLift(): void
    {
        $this->assertSame(0, Scroll::SignLiftFor('nosuchborder', 'nosuchrankset', 4));
        $this->assertSame(0, Scroll::SignLiftFor('', '', 0));
    }

    /**
     * The CSS half of the same fix, for the sixteen fixed orders. The lift is a
     * CEILING that scrollery-autofit.js clamps, so the custom property it declares
     * has to be the one the script reads — a rename on either side is silent.
     */
    public function testTheStylesheetDeclaresTheLiftAsACeilingTheScriptCanClamp(): void
    {
        $css = (string) file_get_contents(
            DIR_TEMPLATE . 'revised-frontend/style/scrollery.css'
        );
        $js = (string) file_get_contents(
            DIR_TEMPLATE . 'revised-frontend/script/scrollery-autofit.js'
        );

        $this->assertStringContainsString(
            'bottom: var(--sc-sign-lift, var(--sc-sign-lift-want, 0px));',
            $css,
            'the block must fall back to the declared ceiling when the script never runs'
        );
        $this->assertStringContainsString('--sc-sign-lift-want', $js);
        $this->assertStringContainsString("setProperty('--sc-sign-lift'", $js);

        // The four fixed orders the sweep found, and only those, declare a ceiling.
        foreach (['crown', 'griffon', 'garber', 'dragon'] as $order) {
            $this->assertMatchesRegularExpression(
                '/\[data-order="' . $order . '"\][^{]*\{[^}]*--sc-sign-lift-want/s',
                $css,
                $order . ' lost its measured lift'
            );
        }
    }

    /**
     * garber's rank plate is a whole page and must be a full-bleed layer. Held in a
     * .centre-bottom box it is bottom-anchored in an inline <img>, and the line box's
     * descender pushed it 25px off its own registration.
     */
    public function testGarberDrawsItsFullPageRankPlateAsABleedLayer(): void
    {
        $tpl = (string) file_get_contents(
            DIR_TEMPLATE . 'revised-frontend/scroll/garber.tpl'
        );

        $q = chr(39);
        $this->assertStringContainsString(
            '<img class="bleed" <?= scroll_art_attrs(' . $q . 'garber/rank_',
            $tpl,
            'the rank plate must be a full-bleed layer, as in flame.tpl'
        );
        $this->assertStringNotContainsString(
            '<div class="centre-bottom"><img <?= scroll_art_attrs(' . $q . 'garber/rank_',
            $tpl
        );
    }
}
