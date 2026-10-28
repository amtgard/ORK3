<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The print page's run-level heraldry choice (&heraldry=): the allowlist, and the
 * per-certificate resolution — the chosen source, else park -> kingdom -> persona
 * without it, else none — with 'auto' walking each surface's own legacy order.
 */
final class ScrollRunHeraldryTest extends TestCase
{
    private const ALL  = ['kingdom' => true, 'park' => true, 'persona' => true];
    private const NONE = ['kingdom' => false, 'park' => false, 'persona' => false];

    /** A reprint has always drawn its park's arms, then its kingdom's. */
    private const REPRINT_AUTO = ['park', 'kingdom'];

    public function testNormaliserAcceptsTheThreeSourcesAndAuto(): void
    {
        $this->assertSame('kingdom', Scroll::NormaliseRunHeraldry('kingdom'));
        $this->assertSame('park', Scroll::NormaliseRunHeraldry('park'));
        $this->assertSame('persona', Scroll::NormaliseRunHeraldry('persona'));
        $this->assertSame('auto', Scroll::NormaliseRunHeraldry('auto'));
    }

    public function testNormaliserFoldsCaseAndWhitespace(): void
    {
        $this->assertSame('persona', Scroll::NormaliseRunHeraldry(' Persona '));
    }

    public function testNormaliserTurnsAbsentAndStrangersIntoAuto(): void
    {
        $this->assertSame('auto', Scroll::NormaliseRunHeraldry(null));
        $this->assertSame('auto', Scroll::NormaliseRunHeraldry(''));
        $this->assertSame('auto', Scroll::NormaliseRunHeraldry('../x'));
        $this->assertSame('auto', Scroll::NormaliseRunHeraldry('player'));
        $this->assertSame(Scroll::HERALDRY_AUTO, Scroll::NormaliseRunHeraldry('"><script>'));
    }

    public function testAChosenSourceWithArmsIsDrawn(): void
    {
        foreach (['kingdom', 'park', 'persona'] as $source) {
            $this->assertSame($source, Scroll::ResolveRunHeraldrySource($source, self::ALL, self::REPRINT_AUTO));
        }
    }

    public function testPersonaWithoutArmsFallsBackToParkThenKingdom(): void
    {
        $this->assertSame('park', Scroll::ResolveRunHeraldrySource(
            'persona',
            ['kingdom' => true, 'park' => true, 'persona' => false],
            self::REPRINT_AUTO
        ));
        $this->assertSame('kingdom', Scroll::ResolveRunHeraldrySource(
            'persona',
            ['kingdom' => true, 'park' => false, 'persona' => false],
            self::REPRINT_AUTO
        ));
    }

    public function testKingdomWithoutArmsFallsBackToParkThenPersona(): void
    {
        $this->assertSame('park', Scroll::ResolveRunHeraldrySource(
            'kingdom',
            ['kingdom' => false, 'park' => true, 'persona' => true],
            self::REPRINT_AUTO
        ));
        $this->assertSame('persona', Scroll::ResolveRunHeraldrySource(
            'kingdom',
            ['kingdom' => false, 'park' => false, 'persona' => true],
            self::REPRINT_AUTO
        ));
    }

    public function testParkWithoutArmsFallsBackToKingdomThenPersona(): void
    {
        $this->assertSame('kingdom', Scroll::ResolveRunHeraldrySource(
            'park',
            ['kingdom' => true, 'park' => false, 'persona' => true],
            self::REPRINT_AUTO
        ));
        $this->assertSame('persona', Scroll::ResolveRunHeraldrySource(
            'park',
            ['kingdom' => false, 'park' => false, 'persona' => true],
            self::REPRINT_AUTO
        ));
    }

    public function testNoArmsAnywhereDrawsNone(): void
    {
        foreach (['auto', 'kingdom', 'park', 'persona'] as $choice) {
            $this->assertSame('', Scroll::ResolveRunHeraldrySource($choice, self::NONE, self::REPRINT_AUTO), $choice);
        }
        $this->assertSame('', Scroll::ResolveRunHeraldrySource('persona', [], self::REPRINT_AUTO));
    }

    /** Automatic on a reprint is heraldry_url(): the park's arms, then the kingdom's. */
    public function testAutoOnAReprintIsTheLegacyParkThenKingdomOrder(): void
    {
        $this->assertSame('park', Scroll::ResolveRunHeraldrySource('auto', self::ALL, self::REPRINT_AUTO));
        $this->assertSame('kingdom', Scroll::ResolveRunHeraldrySource(
            'auto',
            ['kingdom' => true, 'park' => false, 'persona' => true],
            self::REPRINT_AUTO
        ));
    }

    /** Automatic never reaches for the persona's arms — the legacy order never did. */
    public function testAutoNeverFallsBackToPersona(): void
    {
        $this->assertSame('', Scroll::ResolveRunHeraldrySource(
            'auto',
            ['kingdom' => false, 'park' => false, 'persona' => true],
            self::REPRINT_AUTO
        ));
    }

    /** A court run's Automatic is its court's own arms and nothing else. */
    public function testAutoOnACourtRunIsTheCourtsOwnArmsOnly(): void
    {
        $this->assertSame('park', Scroll::ResolveRunHeraldrySource('auto', self::ALL, ['park']));
        $this->assertSame('', Scroll::ResolveRunHeraldrySource(
            'auto',
            ['kingdom' => true, 'park' => false, 'persona' => true],
            ['park']
        ));
        $this->assertSame('kingdom', Scroll::ResolveRunHeraldrySource('auto', self::ALL, ['kingdom']));
    }

    /** An unknown choice is Automatic, and an unknown key in an order never draws. */
    public function testUnknownChoiceIsAutoAndUnknownOrderKeysAreIgnored(): void
    {
        $this->assertSame('park', Scroll::ResolveRunHeraldrySource('../x', self::ALL, self::REPRINT_AUTO));
        $this->assertSame('kingdom', Scroll::ResolveRunHeraldrySource(
            'auto',
            ['evil' => true, 'kingdom' => true],
            ['evil', 'kingdom']
        ));
    }
}
