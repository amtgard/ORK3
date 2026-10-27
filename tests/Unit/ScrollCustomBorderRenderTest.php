<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The Custom Border and Custom Ribbon as MARKUP: what Scroll::Render() draws once
 * BuildScroll() has resolved a design (ScrollDesignTest covers that resolution).
 *
 * Every certificate here is built through BuildScroll() rather than by hand, because
 * the customBorder block a template reads is BuildScroll's answer to "can this order,
 * at this rank, variant and recipe, lose its frame?" — a hand-built one could ask the
 * templates a question the domain would never put to them.
 */
final class ScrollCustomBorderRenderTest extends TestCase
{
    /** The asset each order's border is drawn from, as it appears in markup. */
    private const BORDER_NEEDLES = [
        'crown'   => 'crown/border',
        'hunter'  => 'hunter/border',
        'hydra'   => 'hydra/border',
        'lion'    => 'lion/border',
        'mask'    => 'mask/border',
        'rose'    => 'rose/border',
        'smith'   => 'smith/border',
        'owl'     => 'owl/border',
        'warrior' => 'warrior/border_',
        // The _border.tpl orders: the knot partial's own colour pair.
        'battle'  => '--primary-color:',
        'dragon'  => '--primary-color:',
        'griffon' => '--primary-color:',
        'custom'  => '--primary-color:',
    ];

    protected function setUp(): void
    {
        unset($GLOBALS['scroll_border_defs'], $GLOBALS['scroll_knot_defs']);
    }

    private function row(string $orderId, int $rank): array
    {
        return [
            // custom's catalogue entry carries no award id; 94 is the Custom Award.
            'AwardId'          => $orderId === 'custom' ? 94 : (int) Scroll::Orders()[$orderId]['award_id'],
            'OfficerRole'      => 'none',
            'Rank'             => $rank,
            'Date'             => '2024-05-13',
            'Note'             => 'for valour',
            'GivenBy'          => 'Sir Someone',
            'Name'             => 'Test Award',
            'KingdomAwardName' => 'Test Award',
            'CustomAwardName'  => '',
            'AliasAwardName'   => '',
            'ParkName'         => 'Rising Sun Station',
            'KingdomName'      => 'The Kingdom of Crystal Groves',
        ];
    }

    /** A rank every order ships: 3, inside the order's own range. */
    private function rankFor(string $orderId): int
    {
        $r = Scroll::Orders()[$orderId]['ranks'] ?? ['from' => 0, 'to' => 10];

        return max((int) $r['from'], min(3, (int) $r['to']));
    }

    private function render(string $orderId, ?array $design, array $extra = []): string
    {
        $rank    = (int) ($extra['rank'] ?? $this->rankFor($orderId));
        $context = array_merge(['player' => 'Testerly'], $extra);
        unset($context['rank']);
        if ($design !== null) {
            $context['design'] = $design;
        }

        return Scroll::Render(Scroll::BuildScroll($this->row($orderId, $rank), $context));
    }

    private function border(bool $match = false, array $more = []): array
    {
        return array_merge([
            'customBorder' => '1', 'color1' => '#1E4FA3', 'color2' => '#E84C92', 'matchStyle' => $match ? '1' : '',
        ], $more);
    }

    /** The URL a plate is drawn from, without its cache-busting query. */
    private function artPath(string $relative): string
    {
        return (string) strtok(Scroll::ArtUrl($relative), '?');
    }

    public function testNoDesignRendersExactlyAsBefore(): void
    {
        foreach (array_keys(Scroll::Orders()) as $id) {
            $html = $this->render($id, null);
            $this->assertStringNotContainsString('sc-knot', $html, $id);
            $this->assertStringNotContainsString('sc-ribbon', $html, $id);
            if (isset(self::BORDER_NEEDLES[$id])) {
                $this->assertStringContainsString(self::BORDER_NEEDLES[$id], $html, $id);
            }
        }
        $this->assertStringContainsString($this->artPath('jovious/bg.jpg'), $this->render('jovious', null));
    }

    /**
     * Mask's glow (a 200px screen bloom of the symbol, drawn before the plain symbol and
     * again after the numeral) lightened the exact knot's bottom ends. The exact knot
     * now comes after the LAST glow pass; the match knot keeps the border's own place,
     * before the first.
     */
    public function testMaskExactKnotIsDrawnOverBothGlowPasses(): void
    {
        foreach ([0, 3] as $rank) {
            $exact = $this->render('mask', $this->border(false), ['rank' => $rank]);
            $this->assertSame(2, substr_count($exact, 'blur(200px)'), "rank {$rank}");
            $this->assertGreaterThan(strrpos($exact, 'blur(200px)'), strpos($exact, 'class="sc-knot"'), "rank {$rank}");

            $match = $this->render('mask', $this->border(true), ['rank' => $rank]);
            $this->assertLessThan(strpos($match, 'blur(200px)'), strrpos($match, 'class="sc-knot"'), "rank {$rank}");
        }
    }

    public function testCrownExactModeReplacesBothPasses(): void
    {
        $html = $this->render('crown', $this->border(false));

        $this->assertSame(1, substr_count($html, 'sc-knot'));
        $this->assertStringContainsString('--knot-c1:#1E4FA3', $html);
        $this->assertStringContainsString('--knot-c2:#E84C92', $html);
        $this->assertStringContainsString('mix-blend-mode:normal;', $html);
        $this->assertStringNotContainsString('crown/border', $html);
        // The phoenix and the rank symbol are order identity, not border: they stay.
        $this->assertStringContainsString('crown/phoenix', $html);
        $this->assertStringContainsString('crown/symbol_3', $html);
    }

    public function testCrownMatchModeDrawsOneKnotPerPass(): void
    {
        $html = $this->render('crown', $this->border(true));

        $this->assertSame(2, substr_count($html, 'sc-knot'));
        preg_match_all('/class="sc-knot" style="([^"]*)"/', $html, $m);
        $this->assertCount(2, $m[1]);
        $this->assertStringContainsString('mix-blend-mode:multiply', $m[1][0]);
        $this->assertStringContainsString('opacity:0.5', $m[1][1]);
        $this->assertStringNotContainsString('crown/border', $html);
    }

    public function testOwlMatchModeFollowsItsVariantPassCount(): void
    {
        foreach (['parchment' => 2, 'cyanotype' => 1, 'transparency' => 3] as $variant => $count) {
            $html = $this->render('owl', $this->border(true), ['variant' => $variant]);
            $this->assertSame($count, substr_count($html, 'sc-knot'), $variant);
            $this->assertStringNotContainsString('owl/border', $html, $variant);
            $this->assertStringContainsString('owl/rank_3', $html, $variant);
        }
    }

    public function testDragonMatchModeReplacesBothPartialPassesIncludingTheGlow(): void
    {
        $html = $this->render('dragon', $this->border(true));

        $this->assertSame(2, substr_count($html, 'sc-knot'));
        $this->assertStringContainsString('filter:blur(13px);mix-blend-mode:color-dodge;', $html);
        $this->assertStringNotContainsString('--primary-color:', $html);
    }

    public function testJoviousSwapsItsGround(): void
    {
        $html = $this->render('jovious', $this->border(false));

        $this->assertStringContainsString('jovious/bg-noborder', $html);
        $this->assertStringNotContainsString($this->artPath('jovious/bg.jpg'), $html);
        $this->assertSame(1, substr_count($html, 'sc-knot'));
    }

    public function testWarriorTenthSwapsItsGroundAndDrawsTheKnot(): void
    {
        $html = $this->render('warrior', $this->border(true), ['rank' => 10]);

        $this->assertStringContainsString('warrior/bg_10-noborder', $html);
        $this->assertStringNotContainsString($this->artPath('warrior/bg_10.jpg'), $html);
        $this->assertSame(1, substr_count($html, 'sc-knot'));
    }

    public function testCustomOnAFramedGroundDrawsTheSwappedGroundAndTheKnot(): void
    {
        foreach (['jovious' => 'jovious/bg-noborder', 'sunburst' => 'warrior/bg_10-noborder'] as $ground => $swap) {
            $html = $this->render('custom', $this->border(false), ['recipe' => ['ground' => $ground]]);
            $this->assertStringContainsString($swap, $html, $ground);
            $this->assertSame(1, substr_count($html, 'sc-knot'), $ground);
        }
    }

    public function testCustomDropsWhicheverBorderTheRecipeDrew(): void
    {
        $cases = [
            'owl/border'            => ['ground' => 'cyanotype', 'border' => 'owl'],
            'battle/border_symbols' => ['ground' => 'parchment', 'border' => 'battle'],
            'crown/border'          => ['ground' => 'parchment', 'border' => 'crown'],
        ];
        foreach ($cases as $needle => $recipe) {
            $this->assertStringContainsString($needle, $this->render('custom', null, ['recipe' => $recipe]), $needle);
            $html = $this->render('custom', $this->border(true), ['recipe' => $recipe]);
            $this->assertStringNotContainsString($needle, $html, $needle);
            $this->assertStringNotContainsString('--primary-color:', $html, $needle);
            $this->assertSame(1, substr_count($html, 'sc-knot'), $needle);
        }
    }

    public function testEveryCompatibleOrderDrawsTheKnotAndDropsItsBorder(): void
    {
        foreach (array_keys(Scroll::CustomBorderRules()) as $id) {
            $variant = Scroll::Orders()[$id]['variants'][0] ?? '';
            foreach ([false, true] as $match) {
                $html  = $this->render($id, $this->border($match), ['variant' => $variant]);
                $label = $id . ($match ? ' match' : ' exact');
                $this->assertStringContainsString('class="sc-knot"', $html, $label);
                if (isset(self::BORDER_NEEDLES[$id])) {
                    // warrior's rank animal comes back from its border file, clipped
                    // (testWarriorKeepsItsRankAnimalAboveTheKnot); the FRAME must not.
                    $frame = (string) preg_replace('/<img [^>]*class="sc-rank-keep"[^>]*>/', '', $html);
                    $this->assertStringNotContainsString(self::BORDER_NEEDLES[$id], $frame, $label);
                }
            }
        }
        $this->assertStringNotContainsString('Border-Griffons', $this->render('griffon', $this->border(false)));
    }

    /**
     * Warrior ranks 1-8 paint their rank animal into border_N.jpg. Under a Custom
     * Border that file comes back once, clipped to the frame's bottom gap and drawn
     * ABOVE the knot (the animal is rank art, which stays); rank 1's snake cannot be
     * clipped clear of its band, so rank 1 is incompatible; ranks 9 and 10 have no
     * animal in a border file and draw none.
     */
    public function testWarriorKeepsItsRankAnimalAboveTheKnot(): void
    {
        foreach ([false, true] as $match) {
            for ($rank = 2; $rank <= 8; $rank++) {
                $html  = $this->render('warrior', $this->border($match), ['rank' => $rank]);
                $label = "rank {$rank}" . ($match ? ' match' : ' exact');
                $this->assertSame(1, preg_match_all('/<img [^>]*class="sc-rank-keep"[^>]*>/', $html, $m), $label);
                $this->assertStringContainsString($this->artPath('warrior/border_' . $rank . '.jpg'), $m[0][0], $label);
                $this->assertStringContainsString('clip-path:polygon(', $m[0][0], $label);
                $this->assertStringContainsString('mix-blend-mode:multiply;', $m[0][0], $label);
                $this->assertGreaterThan(strrpos($html, 'class="sc-knot"'), strpos($html, 'sc-rank-keep'), $label);
            }
            foreach ([9, 10] as $rank) {
                $html = $this->render('warrior', $this->border($match), ['rank' => $rank]);
                $this->assertStringContainsString('class="sc-knot"', $html, "rank {$rank}");
                $this->assertStringNotContainsString('sc-rank-keep', $html, "rank {$rank}");
            }
        }
        $this->assertNull(Scroll::CustomBorderRule('warrior', 1, '', []));
        $one = $this->render('warrior', $this->border(false), ['rank' => 1]);
        $this->assertStringNotContainsString('sc-knot', $one);
        $this->assertStringNotContainsString('sc-rank-keep', $one);
        for ($rank = 1; $rank <= 10; $rank++) {
            $this->assertStringNotContainsString('sc-rank-keep', $this->render('warrior', null, ['rank' => $rank]), "no design, rank {$rank}");
        }
    }

    public function testIncompatibleOrdersIgnoreTheCustomBorder(): void
    {
        foreach (['flame', 'garber', 'zodiac'] as $id) {
            $this->assertSame($this->render($id, null), $this->render($id, $this->border(true)), $id);
        }
    }

    public function testTheKnotFragmentIsIdenticalWhetherOrNotTheDefsAreOnThePage(): void
    {
        $before = $this->render('crown', $this->border(false));

        ob_start();
        try {
            scroll_partial('filters');
        } finally {
            ob_end_clean();
        }

        $this->assertSame($before, $this->render('crown', $this->border(false)));
        $this->assertStringNotContainsString('<symbol', $before);
    }

    public function testThePageSeamCarriesTheKnotSymbolsOnce(): void
    {
        ob_start();
        try {
            scroll_partial('filters');
            scroll_partial('filters');
        } finally {
            $page = (string) ob_get_clean();
        }

        $this->assertSame(1, substr_count($page, 'id="Knot-Band-1"'));
        $this->assertSame(1, substr_count($page, 'id="Knot-Band-2"'));
        $this->assertStringContainsString('var(--knot-c1)', $page);
        $this->assertStringContainsString('var(--knot-c2)', $page);
    }

    public function testCustomRibbonSwapsAllFiveFlourishes(): void
    {
        $html = $this->render('crown', ['customRibbon' => '1', 'ribbonColor' => '#2e7d32']);

        foreach (['top', 'right', 'bottom'] as $side) {
            $this->assertStringContainsString('common/ribbon/ribbon-' . $side, $html, $side);
        }
        $this->assertSame(5, substr_count($html, 'class="Flourish sc-ribbon'));
        $this->assertSame(5, substr_count($html, 'filter:url(#sc-ribbon-tint)'));
        $this->assertSame(1, substr_count($html, 'id="sc-ribbon-tint"'));
        $this->assertStringContainsString('flood-color="#2E7D32"', $html);
        $this->assertStringContainsString('class="sc-ribbon-flood"', $html);
        $this->assertStringContainsString('<feBlend', $html);
        $this->assertStringContainsString('operator="in"', $html);
        $this->assertStringNotContainsString('squiggly-top', $html);
        // The ink grids follow the new files (the alpha is identical to jovious's).
        $this->assertStringContainsString(
            'data-sc-ink="' . Scroll::InkGrid('common/ribbon/ribbon-top.png') . '"',
            $html
        );
    }

    /**
     * The typeset engine places the type against each flourish's ink grid, so a Custom
     * Ribbon on ANY order must hand it exactly the grids the Jovious order's own ribbons
     * do — flourish for flourish, including the two Centered rules. Equal grids are what
     * make the ribbon set the type the same way on every order as on Jovious.
     */
    public function testCustomRibbonGivesEveryOrderTheJoviousFlourishInk(): void
    {
        $inkByFlourish = static function (string $html): array {
            preg_match_all('/<img (?:id="(\w+)" )?[^>]*class="Flourish[^"]*?(ScRule--[lr])?"[^>]*data-sc-ink="([^"]+)"/', $html, $m, PREG_SET_ORDER);
            $out = [];
            foreach ($m as $hit) {
                $out[$hit[1] !== '' ? $hit[1] : ($hit[2] ?? '?')] = $hit[3];
            }
            ksort($out);

            return $out;
        };
        $jovious = $inkByFlourish($this->render('jovious', null));
        $this->assertSame(
            ['BottomTextFlourish', 'RightTextFlourish', 'ScRule--l', 'ScRule--r', 'TopTextFlourish'],
            array_keys($jovious)
        );

        foreach (array_keys(Scroll::Orders()) as $id) {
            $html = $this->render($id, ['customRibbon' => '1']);
            $this->assertSame($jovious, $inkByFlourish($html), $id);
        }
    }

    public function testNoRibbonLeavesFlourishesAlone(): void
    {
        $html = $this->render('crown', null);

        $this->assertStringContainsString('squiggly-top', $html);
        $this->assertStringNotContainsString('sc-ribbon', $html);
    }

    public function testColoursOnlyReachMarkupNormalised(): void
    {
        $hostile = '#6AC1FF;background:url(//x)';
        $html    = $this->render('crown', $this->border(false, ['color1' => $hostile, 'ribbonColor' => $hostile, 'customRibbon' => '1']));

        $this->assertStringNotContainsString('background:url', $html);
        $this->assertStringContainsString('--knot-c1:#6AC1FF', $html);
        $this->assertStringContainsString('flood-color="' . Scroll::RIBBON_DEFAULT . '"', $html);

        // A scroll array that reaches Render() without passing BuildScroll() (a reprint
        // route, a test) is normalised in the template too.
        $s = Scroll::BuildScroll($this->row('crown', 3), ['player' => 'T', 'design' => $this->border(false)]);
        $s['customBorder']['color1'] = '"><script>x</script>';
        $s['customRibbon']           = 'red;x:y';
        $raw = Scroll::Render($s);
        $this->assertStringNotContainsString('<script>', $raw);
        $this->assertStringNotContainsString('red;x:y', $raw);
        $this->assertStringContainsString('--knot-c1:' . Scroll::DEFAULT_DESIGN['color1'], $raw);
    }
}
