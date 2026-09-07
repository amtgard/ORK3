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
                    Scroll::ArtUrl($master, Scroll::ART_DISPLAY),
                    $master . ' must resolve to its display rendition'
                );
                $checkedDisplay++;
            }

            if (is_string($print)) {
                $this->assertSame(
                    Scroll::ArtBase() . '/' . $print,
                    Scroll::ArtUrl($master, Scroll::ART_MASTER),
                    $master . ' must resolve to its print rendition'
                );
                $checkedPrint++;
            } else {
                // WebP LOSES to these masters — lossless inflates an already-lossy
                // JPEG — so the master itself is the right answer, and blindly
                // constructing "<name>.webp" would 404.
                $this->assertSame(
                    Scroll::ArtBase() . '/' . $master,
                    Scroll::ArtUrl($master, Scroll::ART_MASTER),
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

    /** A caller that already appended a cache-buster must not have it dropped. */
    public function testAQueryStringSurvivesTheRewrite(): void
    {
        $plain  = Scroll::ArtUrl('lion/border.png', Scroll::ART_DISPLAY);
        $busted = Scroll::ArtUrl('lion/border.png?v=123', Scroll::ART_DISPLAY);

        $this->assertSame($plain . '?v=123', $busted);
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
}
