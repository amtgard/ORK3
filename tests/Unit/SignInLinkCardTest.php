<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Link-preview card for attendance sign-in links. The SignIn page is
 * login-gated, so chat-app crawlers (never logged in) used to embed every
 * pasted link as the site-generic card. ork_signin_link_og() builds the card
 * the logged-out render now carries (fed to ork_og_meta_tags(), which does
 * the escaping) and ork_signin_link_time_left() the sentence about expiry
 * that both the card and the page use.
 */
final class SignInLinkCardTest extends TestCase
{
    private const NOW = '2026-11-17 20:00:00 UTC';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../system/lib/ork3/common.php';
    }

    public function testTimeLeftStaysTrueAfterTheCardIsCached(): void
    {
        $now = strtotime(self::NOW);
        // Phrased against the moment of posting, because Discord shows the
        // card it fetched at post time for days.
        $this->assertSame('Good for about 3 hours from when this was posted.', ork_signin_link_time_left('2026-11-17 22:30:00', $now));
        $this->assertSame('Good for about 1 hour from when this was posted.', ork_signin_link_time_left('2026-11-17 21:10:00', $now));
        $this->assertSame('Good for about 1 minute from when this was posted.', ork_signin_link_time_left('2026-11-17 20:00:30', $now));
        $this->assertSame('Good for about 2 days from when this was posted.', ork_signin_link_time_left('2026-11-20 06:00:00', $now));
        $this->assertSame('This link has expired.', ork_signin_link_time_left('2026-11-17 19:59:00', $now));
        $this->assertSame('', ork_signin_link_time_left('', $now));
        $this->assertSame('', ork_signin_link_time_left('not a date', $now));
    }

    public function testParkCardNamesParkKingdomAndTimeLeft(): void
    {
        $og = ork_signin_link_og(array(
            'valid'        => true,
            'url'          => 'https://ork.example/orkui/index.php?Route=SignIn/index/abc',
            'scope_type'   => 'park',
            'scope_name'   => 'Riverwatch',
            'kingdom_name' => 'Wetlands',
            'expires_at'   => '2026-11-17 22:30:00',
        ), strtotime(self::NOW));
        $this->assertSame('Sign in at Riverwatch', $og['title']);
        $this->assertSame('https://ork.example/orkui/index.php?Route=SignIn/index/abc', $og['url']);
        $this->assertStringContainsString("today's attendance at Riverwatch (Wetlands)", $og['description']);
        $this->assertStringContainsString('log in to sign yourself in', $og['description']);
        $this->assertStringContainsString('about 3 hours from when this was posted', $og['description']);
        $this->assertArrayNotHasKey('image', $og, 'no heraldry means the generic logo default applies');
    }

    public function testEventCardUsesEventWordingAndHeraldry(): void
    {
        $og = ork_signin_link_og(array(
            'valid'      => true,
            'url'        => 'u',
            'scope_type' => 'event',
            'scope_name' => 'Battle of the Dens',
            'expires_at' => '2026-11-20 06:00:00',
            'image'      => 'https://ork.example/heraldry/event/00042.png?v=1',
        ), strtotime(self::NOW));
        $this->assertSame('Sign in: Battle of the Dens', $og['title']);
        $this->assertStringContainsString('your attendance at Battle of the Dens', $og['description']);
        $this->assertStringContainsString('about 2 days', $og['description']);
        $this->assertSame('https://ork.example/heraldry/event/00042.png?v=1', $og['image']);
        // Blank dimensions so the logo's 1075x1075 defaults are dropped.
        $this->assertSame('', $og['image:width']);
        $this->assertSame('', $og['image:height']);
    }

    public function testUnusableLinkCardCarriesTheReason(): void
    {
        $og = ork_signin_link_og(array('valid' => false, 'url' => 'u', 'error' => 'This sign-in link has expired.'));
        $this->assertSame('Sign-in link unavailable', $og['title']);
        $this->assertSame('This sign-in link has expired. Ask whoever posted it for a fresh ORK sign-in link.', $og['description']);
        $og = ork_signin_link_og(array('valid' => false));
        $this->assertSame('Ask whoever posted it for a fresh ORK sign-in link.', $og['description']);
    }

    public function testMissingScopeNameFallsBack(): void
    {
        $og = ork_signin_link_og(array('valid' => true, 'scope_type' => 'kingdom', 'scope_name' => ''));
        $this->assertSame('Sign in at your group', $og['title']);
        $this->assertStringNotContainsString('(', $og['description'], 'kingdom scope never gets a parenthetical');
    }
}
