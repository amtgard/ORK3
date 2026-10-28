<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B6: heraldry uploaded on an opaque black square printed as a black block, because
 * _heraldry.tpl draws it with mix-blend-mode: multiply (white vanishes, black stays).
 * ScrollHeraldry keys the corner-connected black out of a scroll-only copy, and only
 * when the image plainly is a device on a black ground.
 *
 * Fixtures are synthetic; see tests/fixtures/heraldry/make-fixtures.php.
 */
final class ScrollHeraldryKeyTest extends TestCase
{
    private const FIX = ORK3_ROOT . '/tests/fixtures/heraldry/';
    private const URL = 'http://localhost/assets/heraldry/scroll/';

    private string $cache;

    protected function setUp(): void
    {
        if (!function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD is not available.');
        }
        $this->cache = sys_get_temp_dir() . '/scroll-heraldry-test-' . bin2hex(random_bytes(4)) . '/';
    }

    protected function tearDown(): void
    {
        if (isset($this->cache) && is_dir($this->cache)) {
            foreach (glob($this->cache . '*') ?: [] as $f) {
                unlink($f);
            }
            rmdir($this->cache);
        }
    }

    private function key(string $path, string $url = 'http://localhost/src.jpg'): string
    {
        return ScrollHeraldry::KeyedUrl($path, $url, $this->cache, self::URL);
    }

    /** The file a returned cache URL names. */
    private function cached(string $url): string
    {
        $this->assertStringStartsWith(self::URL, $url);
        $path = $this->cache . substr($url, strlen(self::URL));
        $this->assertFileExists($path);

        return $path;
    }

    /** @return array{int,int,int,int} r, g, b, GD alpha (0 opaque .. 127 clear) */
    private function px($im, int $x, int $y): array
    {
        $c = imagecolorat($im, $x, $y);

        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF, ($c >> 24) & 0x7F];
    }

    public function testAShieldOnABlackSquareIsKeyed(): void
    {
        $src    = self::FIX . 'shield-on-black.jpg';
        $before = sha1_file($src);

        $url = $this->key($src);
        $im  = imagecreatefrompng($this->cached($url));
        $this->assertStringEndsWith('.png', $url);
        $this->assertSame([200, 240], [imagesx($im), imagesy($im)]);

        // Corners, and the black square beside the shield, are clear.
        foreach ([[0, 0], [199, 0], [0, 239], [199, 239], [10, 120], [100, 236]] as [$x, $y]) {
            $this->assertSame(127, $this->px($im, $x, $y)[3], "($x,$y) should be transparent");
        }
        // The shield's gold field is untouched and opaque.
        [$r, $g, $b, $a] = $this->px($im, 100, 150);
        $this->assertSame(0, $a);
        $this->assertGreaterThan(180, $r);
        $this->assertGreaterThan(140, $g);
        // Gold right at the shield's top edge keeps full opacity (the feather fades dark
        // fringe, never ink).
        $this->assertSame(0, $this->px($im, 100, 37)[3]);

        // The original is never modified.
        $this->assertSame($before, sha1_file($src));
    }

    public function testDarkArtInsideTheShieldIsNotTouched(): void
    {
        $im = imagecreatefrompng($this->cached($this->key(self::FIX . 'shield-on-black.jpg')));

        // The black roundel is not connected to a corner: it stays black and opaque.
        [$r, $g, $b, $a] = $this->px($im, 100, 72);
        $this->assertSame(0, $a);
        $this->assertLessThan(30, max($r, $g, $b));
    }

    public function testADarkFieldRunningToTheCornersIsUntouched(): void
    {
        $info = ScrollHeraldry::Analyse(self::FIX . 'dark-field.jpg');
        $this->assertFalse($info['qualifies']);
        $this->assertGreaterThan(0.60, $info['region']);
        $this->assertSame('http://localhost/src.jpg', $this->key(self::FIX . 'dark-field.jpg'));
        $this->assertSame([], glob($this->cache . '*.png') ?: []);
    }

    public function testLineArtOnBlackIsUntouched(): void
    {
        $info = ScrollHeraldry::Analyse(self::FIX . 'line-art-on-black.png');
        $this->assertFalse($info['qualifies']);
        $this->assertGreaterThan(0.60, $info['region']);
        $this->assertSame('http://localhost/src.png', $this->key(self::FIX . 'line-art-on-black.png', 'http://localhost/src.png'));
    }

    public function testAnAlreadyTransparentPngIsUntouched(): void
    {
        $info = ScrollHeraldry::Analyse(self::FIX . 'transparent-shield.png');
        $this->assertFalse($info['qualifies']);
        $this->assertSame('http://localhost/src.png', $this->key(self::FIX . 'transparent-shield.png', 'http://localhost/src.png'));
    }

    /**
     * Found by the dry run's hand-check (park 00768, player 044269): dark art that
     * touches the black ground through a gap is corner-connected and inside 5-60%, so the
     * briefed rules alone eat it. No straight row or column ray from an edge reaches it.
     */
    public function testDarkArtJoinedToTheGroundThroughAGapIsUntouched(): void
    {
        $src  = self::FIX . 'silhouette-through-gap.png';
        $info = ScrollHeraldry::Analyse($src);
        $this->assertGreaterThanOrEqual(0.05, $info['region']);
        $this->assertLessThanOrEqual(0.60, $info['region']);
        $this->assertFalse($info['qualifies']);
        $this->assertSame('hidden', $info['reason']);
        $this->assertSame('http://localhost/src.png', $this->key($src, 'http://localhost/src.png'));
    }

    public function testTheBlackSquareAroundAShieldIsAllInSightOfAnEdge(): void
    {
        $this->assertLessThanOrEqual(ScrollHeraldry::HIDDEN_MAX, ScrollHeraldry::Analyse(self::FIX . 'shield-on-black.jpg')['hidden']);
    }

    public function testTheShieldQualifiesWithinTheBriefedBounds(): void
    {
        $info = ScrollHeraldry::Analyse(self::FIX . 'shield-on-black.jpg');
        $this->assertTrue($info['qualifies']);
        $this->assertGreaterThanOrEqual(0.05, $info['region']);
        $this->assertLessThanOrEqual(0.60, $info['region']);
    }

    public function testACacheHitReturnsTheSameUrlWithoutRegenerating(): void
    {
        $src   = self::FIX . 'shield-on-black.jpg';
        $first = $this->key($src);
        $file  = $this->cached($first);
        file_put_contents($file, 'sentinel');

        $this->assertSame($first, $this->key($src));
        $this->assertSame('sentinel', file_get_contents($file));
    }

    public function testAReuploadedSourceGetsANewKeyedFile(): void
    {
        $copy = $this->cache . 'upload.jpg';
        @mkdir($this->cache, 0775, true);
        copy(self::FIX . 'shield-on-black.jpg', $copy);
        touch($copy, 1_700_000_000);
        clearstatcache();
        $first = $this->key($copy);

        touch($copy, 1_700_000_500);
        clearstatcache();
        $second = $this->key($copy);

        $this->assertNotSame($first, $second);
        $this->cached($second);
        // The superseded copy is removed when the new one is made.
        $this->assertFileDoesNotExist($this->cache . substr($first, strlen(self::URL)));
        $this->assertCount(1, glob($this->cache . '*.png') ?: []);
    }

    /**
     * A file GD cannot decode (or one over MAX_PIXELS) is judged once: it leaves a .skip
     * marker, so later renders short-circuit instead of re-reading and re-parsing it.
     */
    public function testAnUndecodableSourceLeavesASkipMarkerAndShortCircuits(): void
    {
        @mkdir($this->cache, 0775, true);
        $bad = $this->cache . 'broken.jpg';
        file_put_contents($bad, "\xFF\xD8\xFF not really a jpeg");

        $this->assertSame('http://localhost/b.jpg', $this->key($bad, 'http://localhost/b.jpg'));
        $skips = glob($this->cache . '*.skip') ?: [];
        $this->assertCount(1, $skips);

        // Short-circuit: with the marker in place the source is hashed for its key but
        // never decoded again.
        $decodes = ScrollHeraldry::DecodeCount();
        $this->assertSame('http://localhost/b.jpg', $this->key($bad, 'http://localhost/b.jpg'));
        $this->assertSame($decodes, ScrollHeraldry::DecodeCount());
        $this->assertSame($skips, glob($this->cache . '*.skip') ?: []);
    }

    /**
     * A chromatic dark field touching the black ground is ink, not fringe: the flood
     * refuses it (NEAR_BLACK_CHROMA), and so must the feather. Navy (16,13,116) has
     * luminance 0.08, under NEAR_BLACK_LUMINANCE, so a luminance-only feather cleared it.
     */
    public function testANavyFieldTouchingTheGroundKeepsFullAlpha(): void
    {
        @mkdir($this->cache, 0775, true);
        $im = imagecreatetruecolor(100, 100);
        imagefill($im, 0, 0, imagecolorallocate($im, 0, 0, 0));
        imagefilledrectangle($im, 15, 15, 84, 84, imagecolorallocate($im, 16, 13, 116));
        $src = $this->cache . 'navy-on-black.png';
        imagepng($im, $src);

        $out = imagecreatefrompng($this->cached($this->key($src)));
        $this->assertSame(127, $this->px($out, 0, 0)[3], 'the ground is keyed');
        $this->assertSame(127, $this->px($out, 14, 50)[3], 'the ground beside the field is keyed');
        foreach ([[15, 50], [16, 50], [50, 15], [84, 84], [50, 50]] as [$x, $y]) {
            $this->assertSame(0, $this->px($out, $x, $y)[3], "navy at ($x,$y) keeps full alpha");
        }
    }

    /** An image over MAX_PIXELS is not decoded at all: it is left alone and judged once. */
    public function testAnImageOverMaxPixelsIsSkippedWithoutDecoding(): void
    {
        $src = self::FIX . 'shield-on-black.jpg';     // 200 x 240 = 48,000 px; qualifies
        ScrollHeraldry::OverrideMaxPixels(40000);
        try {
            $this->assertSame('http://localhost/big.jpg', $this->key($src, 'http://localhost/big.jpg'));
            $this->assertCount(1, glob($this->cache . '*.skip') ?: []);
            $this->assertCount(0, glob($this->cache . '*.png') ?: []);
            $this->assertFalse(ScrollHeraldry::Analyse($src)['qualifies']);
            $this->assertSame('unreadable', ScrollHeraldry::Analyse($src)['reason']);
        } finally {
            ScrollHeraldry::OverrideMaxPixels(null);
        }
        $this->assertSame(16000000, ScrollHeraldry::MAX_PIXELS, 'the production limit is unchanged');
        $this->assertTrue(ScrollHeraldry::Analyse($src)['qualifies'], 'and with it restored the same image qualifies');
    }

    /** An uncreatable cache directory fails open, and says so once per process. */
    public function testAnUncreatableCacheDirectoryFailsOpenAndLogsOnce(): void
    {
        @mkdir($this->cache, 0775, true);
        $blocker = $this->cache . 'not-a-dir';
        file_put_contents($blocker, 'x');
        $log = $this->cache . 'php-error.log';
        $old = ini_set('error_log', $log);
        try {
            ScrollHeraldry::ResetForTests();
            foreach ([1, 2] as $_) {
                $this->assertSame(
                    'http://localhost/s.jpg',
                    ScrollHeraldry::KeyedUrl(self::FIX . 'shield-on-black.jpg', 'http://localhost/s.jpg', $blocker . '/scroll/', self::URL)
                );
            }
        } finally {
            ini_set('error_log', (string) $old);
        }
        $lines = array_values(array_filter(explode("\n", (string) @file_get_contents($log)), static fn ($l) => str_contains($l, 'ScrollHeraldry')));
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('not-a-dir/scroll/', $lines[0]);
    }

    public function testAMissingSourceReturnsItsUrlUnchanged(): void
    {
        $this->assertSame('http://localhost/none.jpg', $this->key(self::FIX . 'no-such-file.jpg', 'http://localhost/none.jpg'));
    }

    public function testSourcePathMapsAHeraldryUrlAndRefusesTheCacheDirectory(): void
    {
        $this->assertSame(DIR_PLAYER_HERALDRY . '000001.jpg', ScrollHeraldry::SourcePath(HTTP_PLAYER_HERALDRY . '000001.jpg?v=123'));
        $this->assertNull(ScrollHeraldry::SourcePath(HTTP_HERALDRY . 'scroll/park-00797-' . str_repeat('a', 40) . '-1-r1.png'));
        $this->assertNull(ScrollHeraldry::SourcePath(HTTP_HERALDRY . '../config.php'));
        $this->assertNull(ScrollHeraldry::SourcePath('https://example.com/x.jpg'));
        $scroll = HTTP_HERALDRY . 'scroll/x.png';
        $this->assertSame($scroll, ScrollHeraldry::ForUrl($scroll));
    }

    public function testForUrlLeavesUrlsOutsideTheHeraldryTreeAlone(): void
    {
        $this->assertSame('https://example.com/x.jpg', ScrollHeraldry::ForUrl('https://example.com/x.jpg'));
        $this->assertSame('', ScrollHeraldry::ForUrl(''));
        $this->assertSame(HTTP_HERALDRY . '../config.php', ScrollHeraldry::ForUrl(HTTP_HERALDRY . '../config.php'));
        $this->assertSame(HTTP_PLAYER_HERALDRY . '999999999.jpg', ScrollHeraldry::ForUrl(HTTP_PLAYER_HERALDRY . '999999999.jpg'));
    }

    /**
     * Every scroll-render heraldry path — court Automatic (scope_context), reprint
     * Automatic (heraldry_url), a run's chosen source (master_heraldry_url) and the
     * composer (heraldry_sources) — goes through the one keyed helper, so composer,
     * reprint and court draw the same arms.
     */
    public function testEveryScrollHeraldryPathAsksForTheKeyedUrl(): void
    {
        $src = (string) file_get_contents(DIR_UI . 'model/model.Scrollery.php');

        $this->assertSame(1, substr_count($src, '->GetHeraldryUrl('), 'only scroll_heraldry_url() may call GetHeraldryUrl()');
        $this->assertStringContainsString('ScrollHeraldry::ForUrl(', $src);
        foreach (['scope_context', 'heraldry_url', 'master_heraldry_url', 'heraldry_sources'] as $fn) {
            $this->assertMatchesRegularExpression(
                '/function ' . $fn . '\(.*?\n    }\n/s',
                $src
            );
            preg_match('/function ' . $fn . '\(.*?\n    }\n/s', $src, $m);
            $this->assertStringContainsString('scroll_heraldry_url(', $m[0], "$fn() must use scroll_heraldry_url()");
        }

        // Memoised per source for the request: reprint Automatic calls heraldry_url()
        // once per row, and a 200-row run must hash each file once, not 200 times.
        preg_match('/function scroll_heraldry_url\(.*?\n    }\n/s', $src, $m);
        $this->assertStringContainsString('static $cache', $m[0], 'scroll_heraldry_url() must memoise per source');
    }
}
