<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ork_og_meta_tags() renders the head's OpenGraph block — the only thing
 * link-preview crawlers (Discord, Facebook, Slack) read. Per-page overrides
 * come from controllers via $this->data['og']; everything else falls back to
 * the site-generic card. Values are escaped here, so controllers pass raw
 * text (event names and user-authored snippets included).
 */
final class OgMetaTagsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../system/lib/ork3/common.php';
    }

    public function testDefaultsRenderGenericCard(): void
    {
        $html = ork_og_meta_tags();
        $this->assertStringContainsString('og:title" content="ORK 3 - Amtgard Online Record Keeper"', $html);
        $this->assertStringContainsString('og:type" content="website"', $html);
        $this->assertStringContainsString('clippy_large.png', $html);
    }

    public function testPageOverridesReplaceDefaultsButKeepTheRest(): void
    {
        $html = ork_og_meta_tags(array(
            'title'       => 'Battle of the Dens',
            'description' => 'Jul 31 – Aug 2 · Wolven Fang, Nine Blades',
        ));
        $this->assertStringContainsString('og:title" content="Battle of the Dens"', $html);
        $this->assertStringContainsString('Wolven Fang, Nine Blades', $html);
        // Un-overridden fields keep their defaults.
        $this->assertStringContainsString('og:site_name" content="ORK 3 - Amtgard Online Record Keeper"', $html);
        $this->assertStringContainsString('clippy_large.png', $html);
    }

    public function testValuesAreEscaped(): void
    {
        $html = ork_og_meta_tags(array(
            'title'       => 'Sera "Tonin" <script>alert(1)</script>',
            'description' => "Persona & park's finest",
        ));
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&quot;Tonin&quot;', $html);
        $this->assertStringContainsString('&amp; park&#039;s finest', $html);
    }

    public function testWhitespaceCollapsedAndEmptyValuesDropped(): void
    {
        $html = ork_og_meta_tags(array(
            'description' => "line one\n\n   line two",
            'title'       => '   ',
        ));
        $this->assertStringContainsString('content="line one line two"', $html);
        // Blank override drops the tag rather than rendering an empty one;
        // the default title is replaced by the (blank) override, so no
        // og:title at all is the honest output.
        $this->assertStringNotContainsString('og:title', $html);
    }

    public function testNonArrayInputFallsBackToDefaults(): void
    {
        $this->assertSame(ork_og_meta_tags(), ork_og_meta_tags(null));
    }
}
