<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The ink manifest is what lets the layout reason about what an ornament actually
 * PAINTS rather than about the box it occupies. A missing entry is not a cosmetic
 * gap: the keep-out pass falls back to the box, which is the exact error that made a
 * bounding-box test report the squiggles as clear while they crossed the type.
 */
final class ScrollInkExtentsTest extends TestCase
{
    private const MANIFEST = __DIR__ . '/../../assets/scrolls/ink-extents.json';

    /** @return array<string, array{box: array, ink: array, grid?: array}> */
    private function manifest(): array
    {
        $this->assertFileExists(self::MANIFEST, 'Run bin/build-scroll-ink-extents.php');

        return json_decode((string) file_get_contents(self::MANIFEST), true)['files'];
    }

    /**
     * Every file the layout can place as a flourish: the defaults every order inherits
     * plus whatever flourish_* an order overrides them with (rose, jovious). Orders()
     * merges the defaults under each order, so one pass over it sees both.
     *
     * @return list<string> paths relative to assets/scrolls/, as the manifest keys them
     */
    private function flourishFiles(): array
    {
        $paths = array_values(\Scroll::DEFAULT_RESOURCES);
        foreach (\Scroll::Orders() as $order) {
            foreach ($order['resources'] as $key => $path) {
                if (str_starts_with((string) $key, 'flourish_')) {
                    $paths[] = $path;
                }
            }
        }

        return array_values(array_unique(array_map(
            static fn (string $path): string => (string) preg_replace('#^scrolls/#', '', $path),
            $paths
        )));
    }

    public function testEveryDefaultOrnamentHasAnEntry(): void
    {
        $files = $this->manifest();
        foreach (\Scroll::DEFAULT_RESOURCES as $key => $relative) {
            $rel = preg_replace('#^scrolls/#', '', $relative);
            $this->assertArrayHasKey($rel, $files, "$key ($rel) has no ink extent");
        }
    }

    public function testInkBoxIsAFractionOfTheElementBox(): void
    {
        foreach ($this->manifest() as $rel => $entry) {
            foreach (['x0', 'y0', 'x1', 'y1'] as $k) {
                $this->assertGreaterThanOrEqual(0.0, $entry['ink'][$k], "$rel.$k");
                $this->assertLessThanOrEqual(1.0, $entry['ink'][$k], "$rel.$k");
            }
            $this->assertLessThan($entry['ink']['x1'], $entry['ink']['x0'], $rel);
            $this->assertLessThan($entry['ink']['y1'], $entry['ink']['y0'], $rel);
        }
    }

    /**
     * A stale manifest is worse than none: it asserts a number for art that has since
     * been redrawn. renditions.json's mtime is already Scroll::artGeneration()'s stamp
     * for "the art moved"; this manifest must not be older than it.
     */
    public function testManifestIsNotOlderThanTheArt(): void
    {
        $renditions = __DIR__ . '/../../assets/scrolls/renditions.json';
        $this->assertGreaterThanOrEqual(
            filemtime($renditions),
            filemtime(self::MANIFEST),
            'ink-extents.json is older than renditions.json — re-run bin/build-scroll-ink-extents.php'
        );
    }

    /**
     * The typeset engine places a swash so its INK clears the type, and it needs the ink
     * as a mask rather than as a box: the box of squiggly-bottom reserves the last 11% of
     * its width for nothing. 32 rows of 32 bits, bit 31 the left-most cell.
     */
    public function testEveryFlourishHasA32x32Grid(): void
    {
        $files = $this->flourishFiles();
        // 4 defaults + 3 jovious + 3 rose. A list that quietly shrank would pass this
        // test for the wrong reason.
        $this->assertGreaterThanOrEqual(10, count($files), 'flourishFiles() lost a theme');

        foreach ($files as $rel) {
            $g = $this->manifest()[$rel]['grid'] ?? null;
            $this->assertNotNull($g, "$rel has no grid");
            $this->assertSame(32, $g['w']);
            $this->assertSame(32, $g['h']);
            $this->assertCount(32, $g['rows']);
            foreach ($g['rows'] as $row) {
                $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $row);
            }
            $this->assertNotSame(str_repeat('00000000', 32), implode('', $g['rows']), "$rel grid is empty");
        }
    }

    public function testTheBottomSwashGridStopsWhereItsInkDoes(): void
    {
        // squiggly-bottom ink ends at x1 = 0.8924 -> cell 28; dilation allows 29; cells
        // 30-31 (the two low bits) are empty in every row.
        $grid = $this->manifest()['common/text/squiggly-bottom.svg']['grid'] ?? null;
        // Without this a missing grid would walk zero rows and pass.
        $this->assertNotNull($grid, 'squiggly-bottom.svg has no grid');
        $this->assertCount(32, $grid['rows']);
        foreach ($grid['rows'] as $row) {
            $this->assertSame(0, hexdec($row) & 0x3);
        }
    }
}
