<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The underlay policy is data, and data this size rots quietly.
 *
 * It names, per certificate design, the plates the type is DESIGNED to sit on, so the
 * free-space profile builder can leave them out of the obstacle ink (spec 7.3). The
 * judgement in it belongs to the artwork's owner; what is checked here is only that the
 * file stays the shape its reader expects and that every claim in it points at a file
 * that exists. A pattern that matches nothing is the failure that matters: a plate
 * renamed or deleted under it would silently turn an underlay back into an obstacle,
 * and the only symptom would be a certificate whose text column quietly narrowed.
 */
final class ScrollLayoutUnderlaysTest extends TestCase
{
    private const POLICY = __DIR__ . '/../../system/lib/ork3/data/scroll-layout-underlays.php';
    private const ART = __DIR__ . '/../../assets/scrolls';

    /** @return array<string, mixed> */
    private function policy(): array
    {
        $this->assertFileExists(self::POLICY);

        return require self::POLICY;
    }

    /** @return list<string> every file under assets/scrolls/, relative to it */
    private function plates(): array
    {
        $root = (string) realpath(self::ART);
        $this->assertNotSame('', $root, 'assets/scrolls is missing');
        $out = [];
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $file) {
            if ($file->isFile()) {
                $out[] = ltrim(substr($file->getPathname(), strlen($root)), '/');
            }
        }

        return $out;
    }

    public function testEveryOrderHasAnEntry(): void
    {
        $missing = array_diff(array_keys(Scroll::Orders()), array_keys($this->policy()));

        $this->assertSame([], array_values($missing), 'orders with no underlay entry (an empty list is a valid entry)');
        $this->assertArrayHasKey('custom', $this->policy());
    }

    public function testValuesAreListsOfStrings(): void
    {
        foreach ($this->policy() as $order => $patterns) {
            $this->assertIsString($order);
            $this->assertIsArray($patterns, "{$order} is not a list");
            $this->assertTrue(array_is_list($patterns), "{$order} is not a list");
            foreach ($patterns as $pattern) {
                $this->assertIsString($pattern, "{$order} holds a non-string pattern");
                $this->assertNotSame('', $pattern, "{$order} holds an empty pattern");
            }
        }
    }

    public function testPatternsAreRelativeToTheScrollsDirectory(): void
    {
        foreach ($this->policy() as $order => $patterns) {
            foreach ($patterns as $pattern) {
                $this->assertStringStartsNotWith('/', $pattern, "{$order}: '{$pattern}' must be relative to assets/scrolls/");
                $this->assertStringNotContainsString('..', $pattern, "{$order}: '{$pattern}' must stay inside assets/scrolls/");
                $this->assertStringNotContainsString('scrolls/', $pattern, "{$order}: '{$pattern}' carries a scrolls/ prefix; the root is already assets/scrolls/");
            }
        }
    }

    public function testEveryPatternMatchesARealPlate(): void
    {
        $plates = $this->plates();
        foreach ($this->policy() as $order => $patterns) {
            foreach ($patterns as $pattern) {
                $hits = array_filter($plates, static fn (string $p): bool => fnmatch($pattern, $p));
                $this->assertNotEmpty($hits, "{$order}: '{$pattern}' matches no file under assets/scrolls/");
            }
        }
    }
}
