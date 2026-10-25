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

    /**
     * The policy is written by hand against a vocabulary that is also data. These three tests
     * hold the two together, so a rename or a new ground in scroll-recipes.php cannot leave an
     * entry here quietly wrong.
     *
     * custom lays three of its grounds over the parchment as washes (forge, rosewash, arcane),
     * and those are underlays. If one stopped being listed, the profile builder would count the
     * whole wash as artwork on every custom certificate that used it.
     */
    public function testEveryWashGroundIsAnUnderlayInCustom(): void
    {
        $grounds = Scroll::Recipes()['grounds'];
        $custom = $this->policy()['custom'] ?? [];
        $this->assertNotSame([], Scroll::GROUND_WASHES, 'Scroll::GROUND_WASHES is empty; this test would pass vacuously');

        foreach (array_keys(Scroll::GROUND_WASHES) as $key) {
            $this->assertArrayHasKey($key, $grounds, "GROUND_WASHES names '{$key}', which is not a ground in scroll-recipes.php");
            $file = $grounds[$key];
            $hit = array_filter($custom, static fn (string $pattern): bool => fnmatch($pattern, $file));
            $this->assertNotEmpty($hit, "custom lists no pattern for the '{$key}' wash ({$file}); it would be counted as artwork");
        }
    }

    /**
     * The other nine grounds are opaque and normally blended, which makes each of them the
     * ground, and a pattern that names the ground hides it from layer A while G keeps it: the
     * difference is the entire ground, reported as artwork. This is the test that would have
     * rejected the brief's own example, `smith/bg.*`, which is the `map` ground.
     *
     * One exemption, and it is about the ORDER, not the file: warrior rank 10 draws bg_10.jpg at
     * soft-light, so markGround() does not take it for the ground there, and it is the rank's
     * own page ground. Custom's `sunburst` draws the same file normally, so custom must never
     * list it, and the exemption is warrior's alone.
     */
    public function testNoPatternHidesAGround(): void
    {
        $exempt = [
            'warrior' => [
                'warrior/bg_10.*' => 'rank 10 draws it soft-light, so it is not the ground there (custom draws it normally, as sunburst)',
            ],
        ];

        $washes = array_keys(Scroll::GROUND_WASHES);
        $grounds = array_filter(
            Scroll::Recipes()['grounds'],
            static fn (string $key): bool => !in_array($key, $washes, true),
            ARRAY_FILTER_USE_KEY
        );
        $policy = $this->policy();

        foreach ($policy as $order => $patterns) {
            foreach ($patterns as $pattern) {
                foreach ($grounds as $key => $file) {
                    if (!fnmatch($pattern, $file)) {
                        continue;
                    }
                    $this->assertArrayHasKey(
                        $pattern,
                        $exempt[$order] ?? [],
                        "{$order}: '{$pattern}' matches {$file}, the '{$key}' ground, which markGround() takes for the ground; listing it hides the whole ground from layer A"
                    );
                }
            }
        }

        // An exemption that no longer matches anything is a stale excuse, not a rule.
        foreach ($exempt as $order => $byPattern) {
            foreach (array_keys($byPattern) as $pattern) {
                $this->assertContains($pattern, $policy[$order] ?? [], "the exemption for {$order} '{$pattern}' is stale: the policy no longer lists it");
                $named = array_filter($grounds, static fn (string $file): bool => fnmatch($pattern, $file));
                $this->assertNotEmpty($named, "the exemption for {$order} '{$pattern}' is stale: it matches no ground");
            }
        }
    }

    /**
     * Patterns name the print route's plate, `stem.*`. A pattern that also reached `stem_display.webp`
     * would be matching the composer's preview rendition, which the profile is never built from, and
     * would mean the pattern is wider than the file it was written for.
     */
    public function testNoPatternMatchesADisplayRendition(): void
    {
        $displays = array_values(array_filter($this->plates(), static fn (string $p): bool => fnmatch('*_display.webp', $p)));
        $this->assertNotSame([], $displays, 'no display renditions found under assets/scrolls/; this test would pass vacuously');

        foreach ($this->policy() as $order => $patterns) {
            foreach ($patterns as $pattern) {
                foreach ($displays as $file) {
                    $this->assertFalse(fnmatch($pattern, $file), "{$order}: '{$pattern}' matches the display rendition {$file}");
                }
            }
        }
    }
}
