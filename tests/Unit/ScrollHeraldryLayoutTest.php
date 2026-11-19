<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** EXPERIMENT (scrollery-heraldry): the multi-arms layouts and the signature stamp. */
final class ScrollHeraldryLayoutTest extends TestCase
{
    private const ARMS = ['kingdom' => 'k.jpg', 'park' => 'p.jpg', 'persona' => 'm.jpg'];

    private function build(array $design, array $arms = self::ARMS, string $scopeType = 'park'): array
    {
        return Scroll::BuildScroll(
            [
                'AwardId'          => (int) Scroll::Orders()['lion']['award_id'],
                'OfficerRole'      => 'none',
                'Rank'             => 3,
                'KingdomAwardName' => 'Order of the Lion',
                'ParkName'         => $scopeType === 'park' ? 'Mordengaard' : '',
                'KingdomName'      => $scopeType === 'kingdom' ? 'The Kingdom of the Wetlands' : '',
            ],
            [
                'player'       => 'T',
                'heraldry'     => 'chosen.jpg',
                'heraldryArms' => $arms,
                'scopeType'    => $scopeType,
                'scopeId'      => 1,
                'design'       => $design,
            ]
        );
    }

    public function testLayoutNeedsEveryArmsItDraws(): void
    {
        $all = ['kingdom' => true, 'park' => true, 'persona' => true];
        $this->assertSame('flank', Scroll::ResolveHeraldryLayout('flank', $all));
        $this->assertSame('single', Scroll::ResolveHeraldryLayout('flank', ['persona' => false] + $all));
        $this->assertSame('overlap', Scroll::ResolveHeraldryLayout('overlap', ['persona' => false] + $all));
        $this->assertSame('single', Scroll::ResolveHeraldryLayout('overlap', ['park' => false] + $all));
        $this->assertSame('single', Scroll::ResolveHeraldryLayout('../x', $all));
    }

    public function testUnknownLayoutNormalisesToSingle(): void
    {
        $this->assertSame('single', Scroll::NormaliseDesign(['heraldryLayout' => 'nope'])['heraldryLayout']);
    }

    public function testSingleKeepsTheChosenSourceOnTop(): void
    {
        $s = $this->build([]);
        $this->assertSame('single', $s['heraldryLayout']);
        $this->assertSame('chosen.jpg', $s['heraldry']);
        $this->assertSame([], $s['heraldryArms']);
    }

    public function testFlankAndSealsPutTheRecipientOnTop(): void
    {
        foreach (['flank', 'seals'] as $layout) {
            $s = $this->build(['heraldryLayout' => $layout]);
            $this->assertSame($layout, $s['heraldryLayout']);
            $this->assertSame('m.jpg', $s['heraldry']);
        }
    }

    public function testOverlapDrawsNothingSingleOnTop(): void
    {
        $s = $this->build(['heraldryLayout' => 'overlap']);
        $this->assertSame('', $s['heraldry']);
        $this->assertSame('k.jpg', $s['heraldryArms']['kingdom']);
    }

    public function testMissingArmsFallBackAndTheDesignSaysSo(): void
    {
        $s = $this->build(['heraldryLayout' => 'seals'], ['persona' => ''] + self::ARMS);
        $this->assertSame('single', $s['heraldryLayout']);
        $this->assertSame('single', $s['design']['heraldryLayout']);
        $this->assertSame('chosen.jpg', $s['heraldry']);
    }

    public function testStampIsTheIssuingBodysArms(): void
    {
        $this->assertSame('p.jpg', $this->build(['heraldryStamp' => '1'])['heraldryStamp']);
        $this->assertSame('k.jpg', $this->build(['heraldryStamp' => '1'], self::ARMS, 'kingdom')['heraldryStamp']);
    }

    public function testStampNeverDrawsWithSealsOrWithoutArms(): void
    {
        $s = $this->build(['heraldryStamp' => '1', 'heraldryLayout' => 'seals']);
        $this->assertSame('', $s['heraldryStamp']);
        $this->assertFalse($s['design']['heraldryStamp']);

        $s = $this->build(['heraldryStamp' => '1'], ['park' => ''] + self::ARMS);
        $this->assertSame('', $s['heraldryStamp']);
    }

    public function testDrawInReplacesTheTopCentreArms(): void
    {
        foreach (Scroll::DRAW_IN_SHAPES as $shape) {
            $s = $this->build(['drawIn' => $shape]);
            $this->assertSame($shape, $s['heraldryDrawIn']);
            $this->assertSame('', $s['heraldry']);
            $html = Scroll::Render($s);
            $this->assertStringContainsString('class="ScDrawIn"', $html);
            $this->assertStringContainsString($shape === 'heater' ? '<path' : '<circle', $html);
            $this->assertStringContainsString('stroke-width="' . Scroll::DRAW_IN_STROKE . '"', $html);
        }
        $this->assertSame('', Scroll::NormaliseDesign(['drawIn' => 'lozenge'])['drawIn']);
    }

    public function testDrawInDropsThePersonaNeedAndOverlap(): void
    {
        $noPersona = ['persona' => ''] + self::ARMS;
        $this->assertSame('flank', $this->build(['drawIn' => 'circle', 'heraldryLayout' => 'flank'], $noPersona)['heraldryLayout']);
        $this->assertSame('seals', $this->build(['drawIn' => 'heater', 'heraldryLayout' => 'seals'], $noPersona)['heraldryLayout']);
        $s = $this->build(['drawIn' => 'heater', 'heraldryLayout' => 'overlap']);
        $this->assertSame('single', $s['heraldryLayout']);
        $this->assertStringNotContainsString('ScArmsPair', Scroll::Render($s));
    }

    public function testRenderedMarkupCarriesEachLayout(): void
    {
        $this->assertStringContainsString('ScArmsPair', Scroll::Render($this->build(['heraldryLayout' => 'overlap'])));
        $this->assertStringContainsString('data-sc-arms="flank"', Scroll::Render($this->build(['heraldryLayout' => 'flank'])));
        $this->assertStringContainsString('ScArms--seal', Scroll::Render($this->build(['heraldryLayout' => 'seals'])));
        $this->assertStringContainsString('ScArms--stamp', Scroll::Render($this->build(['heraldryStamp' => '1'])));
    }
}
