<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Item 36, domain half. Scroll::ArtBase() was a bare path, so the composer's
 * ~600px preview downloaded the 300 dpi print plates — a lion rank-1 certificate
 * pulls 3.83 MB and warrior rank 9 pulls 4.60 MB.
 *
 * THE CONTRACT, matched to what bin/build-scroll-renditions.sh actually emits: the
 * renditions are SIBLINGS of their masters, not a parallel tree —
 * `lion/border.webp` and `lion/border_display.webp` sit beside `lion/border.png`.
 * So a size is a filename concern and ArtUrl() resolves it through
 * assets/scrolls/renditions.json; ArtBase() stays deliberately size-free.
 *
 * The manifest is REQUIRED reading, not a naming convention to reproduce: a
 * rendition is only emitted where it beats its master, so 17 of the 244 masters
 * carry `"print": null` — and those are the largest files in the set.
 */
final class ScrollArtSizeTest extends TestCase
{
    /**
     * ArtUrl() appends ?v=<master sha> so a corrected plate gets past the `immutable`
     * cache header on assets/. Most of the assertions here are about WHICH FILE is
     * resolved, so they strip it; the ones that are about the token say so.
     */
    private function noBust(string $url): string
    {
        return strtok($url, '?') ?: $url;
    }

    /** @return array<string, array<string, mixed>> */
    private function manifest(): array
    {
        $path = dirname(__DIR__, 2) . '/assets/scrolls/renditions.json';
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded['files'] ?? null) ? $decoded['files'] : [];
    }

    public function testArtBaseIsBareAndSizeFree(): void
    {
        $this->assertStringEndsWith('/scrolls', Scroll::ArtBase());
        $this->assertStringNotContainsString('/display', Scroll::ArtBase());
    }

    /** A plate with no manifest entry resolves to itself, unchanged, at every size. */
    public function testAnUnknownPlateFallsBackToTheMasterUrl(): void
    {
        $expected = Scroll::ArtBase() . '/nosuchorder/nosuchplate.png';

        $this->assertSame($expected, Scroll::ArtUrl('nosuchorder/nosuchplate.png'));
        $this->assertSame($expected, Scroll::ArtUrl('nosuchorder/nosuchplate.png', Scroll::ART_DISPLAY));
        $this->assertSame($expected, Scroll::ArtUrl('/nosuchorder/nosuchplate.png'));
    }

    public function testAnUnknownSizeIsTreatedAsTheMaster(): void
    {
        $this->assertSame(
            Scroll::ArtUrl('lion/border.png', Scroll::ART_MASTER),
            Scroll::ArtUrl('lion/border.png', 'thumbnail')
        );
        $this->assertSame(
            Scroll::ArtUrl('lion/border.png', Scroll::ART_MASTER),
            Scroll::ArtUrl('lion/border.png', '')
        );
    }

    /**
     * The manifest, applied. Skipped rather than failed where the generated files
     * are not present — an installation that has not run the rendition pass has
     * only masters, and ArtUrl() must return exactly the URLs it always did.
     */
    public function testDisplayAndPrintResolveThroughTheManifest(): void
    {
        $manifest = $this->manifest();
        if ($manifest === []) {
            $this->markTestSkipped('assets/scrolls/renditions.json is absent — run bin/build-scroll-renditions.sh.');
        }

        $checkedPrint   = 0;
        $checkedDisplay = 0;
        $checkedNoPrint = 0;

        foreach ($manifest as $master => $entry) {
            $display = $entry['display']['file'] ?? null;
            $print   = $entry['print']['file'] ?? null;

            if (is_string($display)) {
                $this->assertSame(
                    Scroll::ArtBase() . '/' . $display,
                    $this->noBust(Scroll::ArtUrl($master, Scroll::ART_DISPLAY)),
                    $master . ' must resolve to its display rendition'
                );
                $checkedDisplay++;
            }

            if (is_string($print)) {
                $this->assertSame(
                    Scroll::ArtBase() . '/' . $print,
                    $this->noBust(Scroll::ArtUrl($master, Scroll::ART_MASTER)),
                    $master . ' must resolve to its print rendition'
                );
                $checkedPrint++;
            } else {
                // WebP LOSES to these masters — lossless inflates an already-lossy
                // JPEG — so the master itself is the right answer, and blindly
                // constructing "<name>.webp" would 404.
                $this->assertSame(
                    Scroll::ArtBase() . '/' . $master,
                    $this->noBust(Scroll::ArtUrl($master, Scroll::ART_MASTER)),
                    $master . ' has no print rendition and must serve its master'
                );
                $checkedNoPrint++;
            }
        }

        $this->assertGreaterThan(0, $checkedDisplay);
        $this->assertGreaterThan(0, $checkedPrint);
        $this->assertGreaterThan(0, $checkedNoPrint, 'the "WebP lost" branch is the one worth pinning');
    }

    /**
     * The display fallback chain. A plate the display pass skipped must still draw
     * — the print rendition, and failing that the master.
     */
    public function testDisplayFallsBackThroughPrintToTheMaster(): void
    {
        $manifest = $this->manifest();
        if ($manifest === []) {
            $this->markTestSkipped('assets/scrolls/renditions.json is absent.');
        }

        foreach ($manifest as $master => $entry) {
            $url = Scroll::ArtUrl($master, Scroll::ART_DISPLAY);
            $this->assertStringStartsWith(Scroll::ArtBase() . '/', $url);
            $this->assertNotSame(Scroll::ArtBase() . '/', $url, $master . ' resolved to nothing');
        }
    }

    /**
     * A caller that already appended a cache-buster keeps it, and does not get a
     * second one stapled on behind it.
     */
    public function testAQueryStringSurvivesTheRewrite(): void
    {
        $plain  = Scroll::ArtUrl('lion/border.png', Scroll::ART_DISPLAY);
        $busted = Scroll::ArtUrl('lion/border.png?v=123', Scroll::ART_DISPLAY);

        $this->assertSame($this->noBust($plain) . '?v=123', $busted);
        $this->assertSame(1, substr_count($busted, '?'));
    }

    /**
     * THE CACHE-BUSTER. assets/ is served `max-age=31536000, immutable`, so a browser
     * that already holds a plate will not revalidate it for a year — a correction to
     * the ARTWORK reaches nobody who has printed that certificate before. The
     * manifest's sha is of the master and changes whenever the art does, and both
     * renditions are rebuilt from it, so one token is right for all three URLs.
     */
    public function testEveryManifestedPlateCarriesItsMasterShaAsTheToken(): void
    {
        $manifest = $this->manifest();
        if ($manifest === []) {
            $this->markTestSkipped('assets/scrolls/renditions.json is absent.');
        }

        $checked = 0;
        foreach ($manifest as $master => $entry) {
            $sha = $entry['sha'] ?? null;
            if (!is_string($sha) || $sha === '') {
                continue;
            }
            foreach ([Scroll::ART_MASTER, Scroll::ART_DISPLAY] as $size) {
                $this->assertStringEndsWith(
                    '?v=' . $sha,
                    Scroll::ArtUrl($master, $size),
                    $master . ' must carry its master sha at ' . $size
                );
            }
            $checked++;
        }
        $this->assertGreaterThan(200, $checked, 'the whole corpus should be busted');

        // Nothing to key on -> no token, which is the pre-rendition behaviour exactly.
        $this->assertSame(
            Scroll::ArtBase() . '/nosuchorder/nosuchplate.png',
            Scroll::ArtUrl('nosuchorder/nosuchplate.png')
        );
    }

    /**
     * scroll_art() is the one-line change a certificate partial needs, and outside
     * a render the size in force is always the master.
     */
    public function testScrollArtUsesTheSizeInForceAndDefaultsToTheMaster(): void
    {
        $this->assertSame(Scroll::ART_MASTER, Scroll::CurrentArtSize());
        $this->assertSame(
            Scroll::ArtUrl('lion/border.png', Scroll::ART_MASTER),
            scroll_art('lion/border.png')
        );
    }

    public function testTheDeclaredSizesAreExactlyMasterAndDisplay(): void
    {
        $this->assertSame([Scroll::ART_MASTER, Scroll::ART_DISPLAY], Scroll::ART_SIZES);
    }

    /**
     * THE REGRESSION THIS EXISTS FOR.
     *
     * ArtUrl() swaps a smaller FILE in behind the same URL. Nothing in the markup told
     * the browser what box that file was standing in for, so an <img> with no
     * width/height drew at the rendition's own size — a third scale on the composer,
     * where display renditions are served. Order of the Flame rendered its entire
     * ground 850x1100 in the corner of a 2550x3300 page; Order of the Crown drew a
     * quarter-size border and a third-size phoenix. On the print surface the masters
     * ARE full size, so the identical markup looked right there and the bug hid.
     *
     * ArtBox() is what closes it, and it is derived from the manifest rather than
     * stored: display dimensions divided by the single displayScale they were cut at.
     */
    public function testArtBoxReportsTheMasterAndNotTheRendition(): void
    {
        $this->assertSame([2550, 3300], Scroll::ArtBox('flame/bg.jpg'));
        $this->assertSame([2550, 3300], Scroll::ArtBox('crown/border.png'));
        $this->assertSame([750, 750], Scroll::ArtBox('crown/symbol_0.png'));

        // +/-1: the display cut rounded, so the master is not exactly recoverable.
        // 292-wide glyph, 97px rendition, divides back to 291. See ArtBox().
        [$w, $h] = Scroll::ArtBox('common/rank_7.png');
        $this->assertEqualsWithDelta(292, $w, 1);
        $this->assertEqualsWithDelta(171, $h, 1);

        // Unknown plate: no attributes, which reproduces the pre-rendition behaviour.
        $this->assertNull(Scroll::ArtBox('nope/not-a-plate.png'));
    }

    /** The emitted attributes carry that box, so a rendition cannot resize a layer. */
    public function testArtAttrsDeclareTheMasterBox(): void
    {
        $attrs = scroll_art_attrs('crown/border.png');

        $this->assertStringContainsString('width="2550"', $attrs);
        $this->assertStringContainsString('height="3300"', $attrs);
        $this->assertStringContainsString('src="', $attrs);

        // No box known -> src only, never a bogus dimension.
        $bare = scroll_art_attrs('nope/not-a-plate.png');
        $this->assertStringNotContainsString('width=', $bare);
    }

    /**
     * Every plate an order partial draws must declare its box — the thing that was
     * true of 4 of 64 images and is now the rule. Scanned rather than spot-checked,
     * because the failure is invisible on the surface most people look at.
     */
    public function testEveryOrderPartialDeclaresABoxForItsArt(): void
    {
        $offenders = [];
        foreach (glob(DIR_TEMPLATE . 'revised-frontend/scroll/*.tpl') as $file) {
            $name = basename($file);
            if (str_starts_with($name, '_') && $name !== '_parchment.tpl') {
                continue;   // the text layer draws no plates
            }
            foreach (file($file) as $n => $line) {
                if (!str_contains($line, '<img')) {
                    continue;
                }
                // A plate is sized by scroll_art_attrs(), by an explicit width=, or by
                // .bleed / an SVG that fills the frame on its own.
                $sized = str_contains($line, 'scroll_art_attrs(')
                    || preg_match('/\bwidth="/', $line)
                    || str_contains($line, 'class="bleed');
                if (!$sized && str_contains($line, 'scroll_art')) {
                    $offenders[] = $name . ':' . ($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'these plates draw at whatever size the rendition happens to be');
    }

    /**
     * scroll_resource() is the same seam for a path out of an order's `resources`
     * set, which carries its own `scrolls/` prefix. _text.tpl used to concatenate
     * HTTP_ASSETS onto the raw value instead, which reaches the file but never the
     * rendition manifest.
     */
    public function testScrollResourceStripsThePrefixAndResolvesLikeScrollArt(): void
    {
        $this->assertSame(
            scroll_art('lion/border.png'),
            scroll_resource('scrolls/lion/border.png')
        );
        // Idempotent for a path that never carried the prefix, and for a stray slash.
        $this->assertSame(scroll_art('lion/border.png'), scroll_resource('lion/border.png'));
        $this->assertSame(scroll_art('lion/border.png'), scroll_resource('/scrolls/lion/border.png'));
    }

    /**
     * The case that actually regressed. jovious is the only order that overrides its
     * three text flourishes with rasters, so it is the only one with renditions to
     * miss — 309 KB of PNG masters served where the display set is about 24 KB.
     */
    public function testJoviousFlourishesResolveToRenditionsRatherThanTheirMasters(): void
    {
        $res = Scroll::Orders()['jovious']['resources'];

        foreach (['flourish_top', 'flourish_right', 'flourish_bottom'] as $key) {
            $this->assertArrayHasKey($key, $res, "jovious no longer overrides {$key}");
            $raw = $res[$key];

            $this->assertStringEndsWith('.png', $raw, 'the master is still the PNG');
            $this->assertStringEndsWith(
                '.webp',
                $this->noBust(scroll_resource($raw)),
                $raw . ' must resolve through the rendition manifest, not straight to the master'
            );
        }
    }

    /**
     * The fifteen orders that keep the shared SVG flourishes must still resolve —
     * SVGs have no renditions, so this is the pass-through half of the same seam.
     */
    public function testSharedSvgFlourishesStillResolveToThemselves(): void
    {
        foreach (Scroll::DEFAULT_RESOURCES as $key => $raw) {
            if (!str_ends_with((string) $raw, '.svg')) {
                continue;
            }
            $this->assertSame(
                Scroll::ArtBase() . '/' . preg_replace('#^scrolls/#', '', (string) $raw),
                scroll_resource((string) $raw),
                $key . ' must pass through unchanged — an SVG has no manifest entry, so'
                    . ' it has no sha to be busted with either'
            );
        }
    }
}
