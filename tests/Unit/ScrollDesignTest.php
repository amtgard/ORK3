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

    public function testIncompatibleOrdersIgnoreTheRequest(): void
    {
        $this->assertNull(Scroll::CustomBorderRule('flame', 3, '', []));
        $s = $this->build('flame', ['customBorder' => '1'], 5);
        $this->assertNull($s['customBorder']);

        // Warrior rank 10 is compatible, by ground swap rather than by a border layer.
        $r = Scroll::CustomBorderRule('warrior', 10, '', []);
        $this->assertSame('warrior/bg_10-noborder.jpg', $r['ground']);
        $this->assertSame([], $r['passes']);
        $this->assertSame('mix-blend-mode:multiply;', Scroll::CustomBorderRule('warrior', 4, '', [])['passes'][0]);
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
}
