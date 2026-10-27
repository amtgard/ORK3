<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScrollHeraldrySourceTest extends TestCase
{
    private const ALL = ['kingdom' => true, 'park' => true, 'persona' => true];

    public function testDefaultsToTheScope(): void
    {
        $this->assertSame('kingdom', Scroll::ResolveHeraldrySource('', 'kingdom', self::ALL));
        $this->assertSame('park', Scroll::ResolveHeraldrySource('', 'park', self::ALL));
    }

    public function testHonoursAnAvailableChoice(): void
    {
        $this->assertSame('persona', Scroll::ResolveHeraldrySource('persona', 'park', self::ALL));
    }

    public function testPersonaWithoutArmsFallsBackToTheScopeDefault(): void
    {
        $a = ['kingdom' => true, 'park' => true, 'persona' => false];
        $this->assertSame('park', Scroll::ResolveHeraldrySource('persona', 'park', $a));
        $this->assertSame('kingdom', Scroll::ResolveHeraldrySource('persona', 'kingdom', $a));
    }

    public function testParkOnAKingdomScopeFallsBack(): void
    {
        $a = ['kingdom' => true, 'park' => false, 'persona' => true];
        $this->assertSame('kingdom', Scroll::ResolveHeraldrySource('park', 'kingdom', $a));
    }

    public function testUnknownSourceIsTheDefault(): void
    {
        $this->assertSame('park', Scroll::ResolveHeraldrySource('../x', 'park', self::ALL));
    }
}
