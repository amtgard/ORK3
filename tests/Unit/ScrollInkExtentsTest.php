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

    /** @return array<string, array{box: array, ink: array, rows: array}> */
    private function manifest(): array
    {
        $this->assertFileExists(self::MANIFEST, 'Run bin/build-scroll-ink-extents.php');

        return json_decode((string) file_get_contents(self::MANIFEST), true)['files'];
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
}
