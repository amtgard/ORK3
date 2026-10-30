<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once DIR_UI . 'controller/controller.LegendAjax.php';

/**
 * The cross-site guard on the Legend write endpoints. The actions end in exit(),
 * so the decision is a private helper exercised directly, constructor skipped.
 * curl against the running stack covers the rest (see the final fix report).
 */
final class LegendAjaxOriginTest extends TestCase
{
    private function sameOrigin(array $server): bool
    {
        $ctl = (new ReflectionClass(Controller_LegendAjax::class))->newInstanceWithoutConstructor();

        return (new ReflectionMethod(Controller_LegendAjax::class, 'sameOrigin'))->invoke($ctl, $server);
    }

    public function test_helper_is_not_routable(): void
    {
        $this->assertTrue((new ReflectionMethod(Controller_LegendAjax::class, 'sameOrigin'))->isPrivate());
    }

    public function test_same_site_browser_post_passes(): void
    {
        $host = ['HTTP_HOST' => 'ork.example:8080'];
        $this->assertTrue($this->sameOrigin($host + ['HTTP_ORIGIN' => 'http://ork.example:8080', 'HTTP_REFERER' => 'http://ork.example:8080/orkui/index.php?Route=Player/profile/5']));
        $this->assertTrue($this->sameOrigin($host + ['HTTP_ORIGIN' => 'https://ORK.example:8080']), 'a Referrer-Policy may drop the Referer');
        $this->assertTrue($this->sameOrigin($host + ['HTTP_REFERER' => 'http://ork.example:8080/orkui/']), 'Origin alone may be missing');
    }

    public function test_no_header_at_all_passes(): void
    {
        $this->assertTrue($this->sameOrigin(['HTTP_HOST' => 'ork.example']));
    }

    public function test_foreign_value_in_either_header_is_refused(): void
    {
        $host = ['HTTP_HOST' => 'ork.example'];
        $this->assertFalse($this->sameOrigin($host + ['HTTP_ORIGIN' => 'https://evil.example']));
        $this->assertFalse($this->sameOrigin($host + ['HTTP_REFERER' => 'https://evil.example/ork.example']));
        $this->assertFalse($this->sameOrigin($host + ['HTTP_ORIGIN' => 'http://ork.example', 'HTTP_REFERER' => 'https://evil.example/']));
        $this->assertFalse($this->sameOrigin($host + ['HTTP_ORIGIN' => 'http://ork.example:9999']), 'another port is another origin');
        $this->assertFalse($this->sameOrigin($host + ['HTTP_ORIGIN' => 'http://ork.example.evil.example']));
    }

    public function test_opaque_origin_is_refused(): void
    {
        $this->assertFalse($this->sameOrigin(['HTTP_HOST' => 'ork.example', 'HTTP_ORIGIN' => 'null']));
    }
}
