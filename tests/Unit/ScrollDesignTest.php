<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScrollDesignTest extends TestCase
{
    private function row(int $awardId, int $rank = 3): array
    {
        return [
            'AwardId'          => $awardId,
            'OfficerRole'      => 'none',
            'Rank'             => $rank,
            'Date'             => '2024-05-13',
            'Note'             => '',
            'GivenBy'          => 'Sir Someone',
            'Name'             => 'Test Award',
            'KingdomAwardName' => 'Test Award',
            'CustomAwardName'  => '',
            'AliasAwardName'   => '',
            'ParkName'         => 'Rising Sun Station',
            'KingdomName'      => 'The Kingdom of Crystal Groves',
        ];
    }

    private function awardId(string $orderId): int
    {
        return (int) Scroll::Orders()[$orderId]['award_id'];
    }

    private function build(string $orderId, array $design, int $rank = 3, array $extra = []): array
    {
        return Scroll::BuildScroll(
            $this->row($this->awardId($orderId), $rank),
            array_merge(['player' => 'Testerly', 'design' => $design], $extra)
        );
    }

    public function testDefaultsWhenNothingRequested(): void
    {
        $this->assertSame(Scroll::DEFAULT_DESIGN, Scroll::NormaliseDesign([]));
    }

    public function testHexColoursAreNormalisedOrFallBack(): void
    {
        $this->assertSame('#6AC1FF', Scroll::NormaliseHexColour('6ac1ff', '#000000'));
        $this->assertSame('#E84C92', Scroll::NormaliseHexColour('#e84c92', '#000000'));
        foreach (['#abc', 'red', '', 'javascript:alert(1)', '#6AC1FF;background:url(x)'] as $bad) {
            $this->assertSame('#123456', Scroll::NormaliseHexColour($bad, '#123456'), $bad);
        }
    }

    public function testDesignNormalisesHostileInput(): void
    {
        $d = Scroll::NormaliseDesign([
            'heraldrySource' => '../x', 'customBorder' => 'yes', 'matchStyle' => '1', 'color1' => '<b>',
        ]);
        $this->assertSame('', $d['heraldrySource']);
        $this->assertFalse($d['customBorder']);
        $this->assertTrue($d['matchStyle']);
        $this->assertSame('#6AC1FF', $d['color1']);

        $this->assertSame('persona', Scroll::NormaliseDesign(['heraldrySource' => 'persona'])['heraldrySource']);
        foreach (['1', 1, true, 'true', 'on'] as $on) {
            $this->assertTrue(Scroll::NormaliseDesign(['customRibbon' => $on])['customRibbon']);
        }
    }

    public function testCompatibleOrdersReturnTheirPasses(): void
    {
        $this->assertSame(
            ['mix-blend-mode:multiply;', 'opacity:0.5;'],
            Scroll::CustomBorderRule('crown', 3, '', [])['passes']
        );
        $this->assertSame('jovious/bg-noborder.jpg', Scroll::CustomBorderRule('jovious', 1, '', [])['ground']);
        $this->assertNull(Scroll::CustomBorderRule('crown', 3, '', [])['ground']);
    }

    public function testOwlPassesFollowTheVariant(): void
    {
        $this->assertCount(2, Scroll::CustomBorderRule('owl', 3, 'parchment', [])['passes']);
        $this->assertCount(1, Scroll::CustomBorderRule('owl', 3, 'cyanotype', [])['passes']);
        $this->assertCount(3, Scroll::CustomBorderRule('owl', 3, 'transparency', [])['passes']);
    }

    /** Garber numeral plates at rank 7+ straddle the knot gap, so custom + garber drops the knot there. */
    public function testCustomGarberRankSevenUpTakesNoCustomBorder(): void
    {
        foreach ([6 => true, 7 => false, 8 => false, 9 => false, 10 => false] as $rank => $ok) {
            $recipe = ['ground' => 'parchment', 'rankSet' => 'garber'];
            $this->assertSame($ok, Scroll::CustomBorderRule('custom', $rank, '', $recipe) !== null, "rank {$rank}");
            $s = Scroll::BuildScroll($this->row(94, $rank), ['player' => 'T', 'recipe' => $recipe, 'design' => ['customBorder' => '1']]);
            $this->assertSame($ok, $s['customBorder'] !== null, "BuildScroll rank {$rank}");
        }
        $s = Scroll::BuildScroll($this->row(94, 10), ['player' => 'T', 'recipe' => ['ground' => 'parchment', 'rankSet' => 'flame'], 'design' => ['customBorder' => '1']]);
        $this->assertNotNull($s['customBorder']);
    }

    public function testIncompatibleOrdersIgnoreTheRequest(): void
    {
        $this->assertNull(Scroll::CustomBorderRule('flame', 3, '', []));
        $s = $this->build('flame', ['customBorder' => '1'], 5);
        $this->assertNull($s['customBorder']);

        // Warrior rank 10 is compatible, by ground swap rather than by a border layer.
        $r = Scroll::CustomBorderRule('warrior', 10, '', []);
        $this->assertSame('warrior/bg_10-noborder.jpg', $r['ground']);
        // The ground swap keeps the order's own passes: match-style still draws a knot.
        $this->assertSame(['mix-blend-mode:multiply;'], $r['passes']);
        $this->assertSame($r['passes'], Scroll::CustomBorderRule('warrior', 4, '', [])['passes']);
    }

    public function testCustomOrderFramedGrounds(): void
    {
        $this->assertSame(
            'jovious/bg-noborder.jpg',
            Scroll::CustomBorderRule('custom', 3, '', ['ground' => 'jovious'])['ground']
        );
        $this->assertSame(
            'warrior/bg_10-noborder.jpg',
            Scroll::CustomBorderRule('custom', 3, '', ['ground' => 'sunburst'])['ground']
        );
        $this->assertNull(Scroll::CustomBorderRule('custom', 3, '', ['ground' => 'linen']));
        $this->assertNull(Scroll::CustomBorderRule('custom', 3, '', ['ground' => 'flame']));
        $this->assertNull(Scroll::CustomBorderRule('custom', 3, '', ['ground' => 'map']));
        $this->assertNotNull(Scroll::CustomBorderRule('custom', 3, '', ['ground' => 'parchment']));
    }

    public function testCustomPassesFollowTheRecipe(): void
    {
        $knot = Scroll::KNOT_PARTIAL_STYLE;

        $dark = Scroll::CustomBorderRule('custom', 3, '', ['ground' => 'cyanotype', 'border' => 'knot']);
        $this->assertSame([$knot . 'mix-blend-mode:screen;'], $dark['passes']);

        $owl = Scroll::CustomBorderRule('custom', 3, '', ['ground' => 'parchment', 'border' => 'owl']);
        $this->assertSame([Scroll::BORDER_STYLES['owl']['light']], $owl['passes']);

        $light = Scroll::CustomBorderRule('custom', 3, '', ['ground' => 'parchment', 'border' => 'knot']);
        $this->assertSame([$knot], $light['passes']);

        $jov = Scroll::CustomBorderRule('custom', 3, '', ['ground' => 'jovious']);
        $this->assertSame('jovious/bg-noborder.jpg', $jov['ground']);
        $this->assertSame([$knot], $jov['passes']);
    }

    public function testEveryCompatibleCombinationDrawsAtLeastOnePass(): void
    {
        foreach (array_keys(Scroll::CustomBorderRules()) as $id) {
            $order = Scroll::Orders()[$id];
            $from  = $order['ranks']['from'] ?? 0;
            $to    = $order['ranks']['to'] ?? 0;
            foreach (($order['variants'] ?: ['']) as $variant) {
                for ($rank = $from; $rank <= $to; $rank++) {
                    $r = Scroll::CustomBorderRule($id, $rank, $variant, []);
                    if ((Scroll::CustomBorderRules()[$id]['ranks'][$rank] ?? null) === false) {
                        // A rank the data file marks incompatible (warrior 1) draws nothing.
                        $this->assertNull($r, "{$id} rank {$rank} {$variant}");
                        continue;
                    }
                    $this->assertNotNull($r, "{$id} rank {$rank} {$variant}");
                    $this->assertNotEmpty($r['passes'], "{$id} rank {$rank} {$variant}");
                }
            }
        }
    }

    public function testEveryRuleNamesARealOrderAndRealFiles(): void
    {
        $orders = Scroll::Orders();
        foreach (Scroll::CustomBorderRules() as $id => $rule) {
            $this->assertArrayHasKey($id, $orders);
            $files = [];
            if (isset($rule['ground'])) {
                $files[] = $rule['ground'];
            }
            foreach (($rule['ranks'] ?? []) as $f) {
                if (is_string($f)) {
                    $files[] = $f;
                }
            }
            foreach (($rule['recipeGrounds'] ?? []) as $f) {
                if (is_string($f)) {
                    $files[] = $f;
                }
            }
            foreach ($files as $f) {
                $this->assertFileExists(DIR_ASSETS . 'scrolls/' . $f, "{$id}: {$f}");
            }
        }
    }

    public function testRibbonColourNormalisedAndOnlyWhenTicked(): void
    {
        $on = $this->build('crown', ['customRibbon' => '1', 'ribbonColor' => '2e7d32']);
        $this->assertSame('#2E7D32', $on['customRibbon']);

        $this->assertNull($this->build('crown', ['ribbonColor' => '#2E7D32'])['customRibbon']);

        $bad = $this->build('crown', ['customRibbon' => '1', 'ribbonColor' => 'x;y']);
        $this->assertSame(Scroll::RIBBON_DEFAULT, $bad['customRibbon']);
        $this->assertSame('#4473CF', Scroll::DEFAULT_DESIGN['ribbonColor']);
    }

    public function testBuildScrollCarriesDesignAndResolvedBorder(): void
    {
        $s = $this->build('crown', ['customBorder' => '1', 'color1' => '#1e4fa3']);
        $this->assertSame('#1E4FA3', $s['customBorder']['color1']);
        $this->assertSame('#E84C92', $s['customBorder']['color2']);
        $this->assertFalse($s['customBorder']['matchStyle']);
        $this->assertSame(['mix-blend-mode:multiply;', 'opacity:0.5;'], $s['customBorder']['passes']);
        $this->assertNull($s['customBorder']['ground']);
        $this->assertSame('#1E4FA3', $s['design']['color1']);

        $this->assertNull($this->build('crown', [])['customBorder']);
    }

    public function testNoDesignInContextRendersAsToday(): void
    {
        $s = Scroll::BuildScroll($this->row($this->awardId('crown')), ['player' => 'T']);
        $this->assertNull($s['customBorder']);
        $this->assertNull($s['customRibbon']);
        $this->assertSame(Scroll::DEFAULT_DESIGN, $s['design']);
    }

    public function testLoggedDesignReflectsWhatWasDrawn(): void
    {
        $req = ['customBorder' => '1', 'matchStyle' => '1'];
        $flame = $this->build('flame', $req);
        $this->assertNull($flame['customBorder']);
        $this->assertFalse($flame['design']['customBorder']);
        $this->assertFalse($flame['design']['matchStyle']);

        $crown = $this->build('crown', $req);
        $this->assertTrue($crown['design']['customBorder']);
        $this->assertTrue($crown['design']['matchStyle']);
    }

    public function testNearBlackInAScreenBlendedMatchPassFallsBackToExact(): void
    {
        $req = ['customBorder' => '1', 'matchStyle' => '1', 'color1' => '#000000'];

        // owl cyanotype's only pass is mix-blend-mode:screen, which erases black.
        $dark = $this->build('owl', $req, 8, ['variant' => 'cyanotype']);
        $this->assertFalse($dark['customBorder']['matchStyle']);
        $this->assertTrue($dark['customBorder']['matchFallback']);
        $this->assertFalse($dark['design']['matchStyle']);
        $this->assertTrue($dark['design']['customBorder']);

        // Colour 2 counts too.
        $two = $this->build('owl', ['color1' => '#1E4FA3', 'color2' => '#050505'] + $req, 8, ['variant' => 'cyanotype']);
        $this->assertTrue($two['customBorder']['matchFallback']);

        // A colour screen does not erase keeps match on.
        $blue = $this->build('owl', ['color1' => '#1E4FA3'] + $req, 8, ['variant' => 'cyanotype']);
        $this->assertTrue($blue['customBorder']['matchStyle']);
        $this->assertFalse($blue['customBorder']['matchFallback']);
        $this->assertTrue($blue['design']['matchStyle']);

        // A multiply-only order keeps black.
        $parch = $this->build('owl', $req, 8, ['variant' => 'parchment']);
        $this->assertTrue($parch['customBorder']['matchStyle']);
        $this->assertFalse($parch['customBorder']['matchFallback']);

        // Match off never reports a fallback.
        $off = $this->build('owl', ['matchStyle' => ''] + $req, 8, ['variant' => 'cyanotype']);
        $this->assertFalse($off['customBorder']['matchFallback']);
    }

    public function testMaskMixedBlendKeepsMatchForBlack(): void
    {
        // mask = screen@0.6 (a glow) + multiply@1 (the band itself): the band is the
        // multiply pass, so black draws, and match stays on.
        $req  = ['customBorder' => '1', 'matchStyle' => '1', 'color1' => '#000000'];
        $mask = $this->build('mask', $req);
        $this->assertNotNull($mask['customBorder']);
        $this->assertTrue($mask['customBorder']['matchStyle']);
        $this->assertFalse($mask['customBorder']['matchFallback']);
        $this->assertTrue($mask['design']['matchStyle']);

        $red = $this->build('mask', ['color1' => '#7A1F1F'] + $req);
        $this->assertFalse($red['customBorder']['matchFallback']);
    }

    public function testOwlCyanotypeStillFallsBackForNearBlack(): void
    {
        $req = ['customBorder' => '1', 'matchStyle' => '1', 'color1' => '#0A0A0A'];
        $owl = $this->build('owl', $req, 8, ['variant' => 'cyanotype']);
        $this->assertTrue($owl['customBorder']['matchFallback']);
        $this->assertFalse($owl['customBorder']['matchStyle']);
    }

    public function testPassesScreenOnlyWhenNoPassCarriesTheBand(): void
    {
        $this->assertFalse(Scroll::PassesScreen(['mix-blend-mode:screen;opacity:0.6;', 'mix-blend-mode:multiply;opacity:1;']));
        $this->assertTrue(Scroll::PassesScreen(['mix-blend-mode:screen;']));
        // The last declaration wins, as in CSS: the knot partial on a dark custom ground.
        $this->assertTrue(Scroll::PassesScreen([Scroll::KNOT_PARTIAL_STYLE . 'mix-blend-mode:screen;']));
        $this->assertFalse(Scroll::PassesScreen([Scroll::KNOT_PARTIAL_STYLE]));
        // A faint normal/multiply pass under half opacity does not carry the band.
        $this->assertTrue(Scroll::PassesScreen(['mix-blend-mode:screen;', 'opacity:0.3;']));
        $this->assertFalse(Scroll::PassesScreen(['mix-blend-mode:screen;', 'opacity:0.5;']));
        // No screen pass at all: not screened.
        $this->assertFalse(Scroll::PassesScreen(['mix-blend-mode:soft-light;opacity:0.5;']));
        $this->assertFalse(Scroll::PassesScreen([]));
    }

    public function testMatchScreensIsTheBuildScrollPredicatePerOrderAndGround(): void
    {
        $m = Scroll::MatchScreens();
        $this->assertFalse($m['orders']['mask']);
        $this->assertFalse($m['orders']['crown']);
        $this->assertTrue($m['orders']['owl']['cyanotype']);
        $this->assertFalse($m['orders']['owl']['parchment']);
        $this->assertArrayNotHasKey('custom', $m['orders']);
        $this->assertTrue($m['grounds']['cyanotype']);
        $this->assertFalse($m['grounds']['parchment']);

        // Every fixed order/variant agrees with what BuildScroll decides for black.
        foreach (Scroll::CustomBorderRules() as $order => $rule) {
            if ($order === 'custom') {
                continue;
            }
            $passes = $rule['passes'];
            $sets   = array_is_list($passes) ? ['' => $passes] : $passes;
            foreach ($sets as $variant => $list) {
                $want = is_array($m['orders'][$order]) ? $m['orders'][$order][$variant] : $m['orders'][$order];
                $this->assertSame(Scroll::PassesScreen($list), $want, "{$order}/{$variant}");
            }
        }
    }

    public function testPartialReprintDesignStillMeansNoCustomBorder(): void
    {
        $s = Scroll::BuildScroll(
            $this->row($this->awardId('owl'), 8),
            ['player' => 'T', 'variant' => 'cyanotype', 'design' => ['heraldrySource' => 'kingdom']]
        );
        $this->assertNull($s['customBorder']);
        $this->assertFalse($s['design']['matchStyle']);
    }

    public function testPrintRecipeOmitsDefaultDesign(): void
    {
        $s = $this->build('crown', []);
        $this->assertSame([], Scroll::PrintRecipe($s));

        $flame = $this->build('flame', ['customBorder' => '1']);
        $this->assertSame([], Scroll::PrintRecipe($flame));
    }

    public function testPrintRecipeStoresDrawnDesign(): void
    {
        $r = Scroll::PrintRecipe($this->build('crown', ['customBorder' => '1']));
        $this->assertTrue($r['design']['customBorder']);
    }

    public function testPrintRecipeKeepsCustomRecipeKeysAtTopLevel(): void
    {
        $s = Scroll::BuildScroll($this->row(94), ['player' => 'T', 'recipe' => ['ground' => 'parchment'], 'design' => ['customBorder' => '1']]);
        $this->assertNotSame([], $s['recipe']);
        $r = Scroll::PrintRecipe($s);
        foreach ($s['recipe'] as $k => $v) {
            $this->assertSame($v, $r[$k]);
        }
        $this->assertArrayHasKey('design', $r);
        $d = Scroll::PrintRecipe(Scroll::BuildScroll($this->row(94), ['player' => 'T', 'recipe' => ['ground' => 'parchment']]));
        $this->assertArrayNotHasKey('design', $d);
        $this->assertSame($s['recipe'], array_diff_key($r, ['design' => 1]));
    }
}
