<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * ork_sitemap_xml() / ork_sitemap_index_xml() render the sitemap documents
 * Googlebot consumes. Escaping matters: ORK URLs are query strings.
 */
final class SitemapXmlTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../system/lib/ork3/common.php';
    }

    public function testUrlsetRendersLocAndDateOnlyLastmod(): void
    {
        $xml = ork_sitemap_xml(array(
            array('loc' => 'https://ork.amtgard.com/orkui/index.php?Route=Park/profile/277', 'lastmod' => '2026-08-21 02:28:54'),
            array('loc' => 'https://ork.amtgard.com/orkui/index.php?Route=Recap/trends'),
        ));
        $this->assertStringContainsString('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">', $xml);
        $this->assertStringContainsString('Route=Park/profile/277</loc><lastmod>2026-08-21</lastmod>', $xml);
        $this->assertStringContainsString('Route=Recap/trends</loc></url>', $xml);
        // Parseable XML end to end.
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function testAmpersandsAreEscaped(): void
    {
        $xml = ork_sitemap_xml(array(
            array('loc' => 'https://x.test/index.php?Route=Event/detail/1&foo=2'),
        ));
        $this->assertStringContainsString('&amp;foo=2', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function testBlankLocAndBadLastmodAreDropped(): void
    {
        $xml = ork_sitemap_xml(array(
            array('loc' => ''),
            array('loc' => 'https://x.test/a', 'lastmod' => 'not-a-date'),
        ));
        $this->assertSame(1, substr_count($xml, '<url>'));
        $this->assertStringNotContainsString('lastmod', $xml);
    }

    public function testIndexDocument(): void
    {
        $xml = ork_sitemap_index_xml(array('https://x.test/Sitemap/core', 'https://x.test/Sitemap/players', ''));
        $this->assertSame(2, substr_count($xml, '<sitemap>'));
        $this->assertNotFalse(simplexml_load_string($xml));
    }
}
