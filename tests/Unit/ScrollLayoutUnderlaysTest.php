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
    /**
     * Masters exported above layout size (assets/scrolls/hires-masters.txt, "<path> <scale>").
     * A plate's pixels are layout pixels times this; a .webp rendition shares its master's scale.
     */
    private static function scaleOf(string $plate): int
    {
        static $scales = null;
        if ($scales === null) {
            $scales = [];
            $list = self::ART . '/hires-masters.txt';
            foreach (is_file($list) ? (array) file($list, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $line) {
                $line = trim((string) $line);
                if ($line !== '' && $line[0] !== '#') {
                    [$path, $scale] = array_pad(preg_split('/\s+/', $line), 2, '1');
                    $scales[preg_replace('/\.[a-z]+$/i', '', $path)] = max(1, (int) $scale);
                }
            }
        }

        return $scales[preg_replace('/\.[a-z]+$/i', '', $plate)] ?? 1;
    }

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

    /** The fnmatch pattern of an entry, which is either the string itself or a window's 'pattern'. */
    private static function patternOf(mixed $entry): string
    {
        return is_array($entry) ? (string) ($entry['pattern'] ?? '') : (string) $entry;
    }

    /** @return list<string> every pattern an order lists, windowed or not */
    private function patternsOf(mixed $entries): array
    {
        return array_map(static fn (mixed $e): string => self::patternOf($e), $entries);
    }

    /**
     * Why an entry is not a valid policy entry, or null when it is. An entry is a non-empty
     * fnmatch string, or ['pattern' => non-empty string, 'y' => [y0, y1]] with integers, both
     * within the frame (0..3300) and y0 < y1 (half-open: y1 is the first row outside).
     */
    private static function entryProblem(mixed $entry): ?string
    {
        if (is_string($entry)) {
            return $entry === '' ? 'an empty pattern' : null;
        }
        if (!is_array($entry)) {
            return 'neither a string nor a window array';
        }
        if (array_diff(array_keys($entry), ['pattern', 'y']) !== []) {
            return 'a window with keys other than pattern and y';
        }
        if (!isset($entry['pattern']) || !is_string($entry['pattern']) || $entry['pattern'] === '') {
            return 'a window with no pattern';
        }
        if (!isset($entry['y']) || !is_array($entry['y']) || !array_is_list($entry['y']) || count($entry['y']) !== 2) {
            return 'a window with no [y0, y1]';
        }
        [$y0, $y1] = $entry['y'];
        if (!is_int($y0) || !is_int($y1)) {
            return 'a window whose y0 and y1 are not integers';
        }
        if ($y0 < 0 || $y1 > 3300) {
            return "a window outside the frame rows 0..3300 ({$y0}, {$y1})";
        }
        if ($y0 >= $y1) {
            return "a window with y0 >= y1 ({$y0}, {$y1})";
        }

        return null;
    }

    public function testEveryOrderHasAnEntry(): void
    {
        $missing = array_diff(array_keys(Scroll::Orders()), array_keys($this->policy()));

        $this->assertSame([], array_values($missing), 'orders with no underlay entry (an empty list is a valid entry)');
        $this->assertArrayHasKey('custom', $this->policy());
    }

    public function testValuesAreListsOfPatternsOrWindows(): void
    {
        foreach ($this->policy() as $order => $entries) {
            $this->assertIsString($order);
            $this->assertIsArray($entries, "{$order} is not a list");
            $this->assertTrue(array_is_list($entries), "{$order} is not a list");
            foreach ($entries as $entry) {
                $this->assertNull(self::entryProblem($entry), "{$order} holds an invalid entry: " . (self::entryProblem($entry) ?? ''));
            }
        }
    }

    /** @return array<string, array{mixed}> */
    public static function malformedEntries(): array
    {
        return [
            'y0 equals y1' => [['pattern' => 'garber/rank_?.*', 'y' => [500, 500]]],
            'y0 above y1' => [['pattern' => 'garber/rank_?.*', 'y' => [900, 500]]],
            'y0 negative' => [['pattern' => 'garber/rank_?.*', 'y' => [-1, 500]]],
            'y1 past the frame' => [['pattern' => 'garber/rank_?.*', 'y' => [500, 3301]]],
            'pattern missing' => [['y' => [500, 900]]],
            'pattern empty' => [['pattern' => '', 'y' => [500, 900]]],
            'pattern not a string' => [['pattern' => 7, 'y' => [500, 900]]],
            'y missing' => [['pattern' => 'garber/rank_?.*']],
            'y has one number' => [['pattern' => 'garber/rank_?.*', 'y' => [500]]],
            'y has three numbers' => [['pattern' => 'garber/rank_?.*', 'y' => [500, 900, 1200]]],
            'y not integers' => [['pattern' => 'garber/rank_?.*', 'y' => ['500', '900']]],
            'unknown key' => [['pattern' => 'garber/rank_?.*', 'y' => [500, 900], 'x' => [0, 10]]],
            'empty string' => [''],
            'a number' => [42],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedEntries')]
    public function testAMalformedEntryIsRejected(mixed $entry): void
    {
        $this->assertNotNull(self::entryProblem($entry), 'the validator accepted a malformed entry');
    }

    public function testAWellFormedWindowIsAccepted(): void
    {
        $this->assertNull(self::entryProblem(['pattern' => 'garber/rank_?.*', 'y' => [420, 1220]]));
        $this->assertNull(self::entryProblem(['pattern' => 'garber/rank_?.*', 'y' => [0, 3300]]));
        $this->assertNull(self::entryProblem('garber/rank_?.*'));
    }

    /**
     * A window is in frame rows, so it means nothing on a plate that is not the whole frame:
     * the readers fail closed on one (the plate stays an obstacle). That is the right fallback
     * and the wrong thing to meet by accident, so every file a window pattern matches must be
     * the 2550x3300 frame.
     */
    public function testAWindowedPatternOnlyMatchesFullFramePlates(): void
    {
        $seen = 0;
        foreach ($this->policy() as $order => $entries) {
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $hits = array_filter($this->plates(), static fn (string $p): bool => fnmatch($entry['pattern'], $p));
                foreach ($hits as $file) {
                    $size = getimagesize(self::ART . '/' . $file);
                    $this->assertNotFalse($size, "{$order}: cannot read {$file}");
                    $k = self::scaleOf($file);
                    $this->assertSame([2550 * $k, 3300 * $k], [$size[0], $size[1]], "{$order}: window pattern '{$entry['pattern']}' matches {$file}, which is not the 2550x3300 frame (at {$k}x)");
                    $seen++;
                }
            }
        }
        $this->assertGreaterThan(0, $seen, 'no windowed entry matched any plate; this test would pass vacuously');
    }

    /**
     * A window is a claim about the ART: the rows inside it hold the thread that is meant to run
     * behind the type, and the rows outside it, down to where the foot cluster begins, are empty.
     * The policy validators above check the shape of the claim and that the file is the frame; only
     * the pixels can say the claim is still true. If the threads are redrawn above or below the
     * window, those rows become obstacles the layout cannot see (the profile hides only the window)
     * or underlays it should have avoided, and nothing else fails.
     *
     * "Visible" is GD alpha below 120 (GD runs 0 opaque to 127 transparent), which is a PNG
     * opacity above about 6%. The threads are at most 186 of 255 opaque (GD alpha about 34), so
     * they clear the bar easily; anything fainter than the bar is below what prints. A stray
     * stroke of even a few percent fails this test rather than being waved through.
     * The checked span ends at row 2400, not at the foot of the frame: the scissors, tape and
     * numeral cluster is a real obstacle that starts at 2546 and is meant to be there.
     *
     * Only the PNG masters are read; the WebP print renditions are derived from them.
     */
    public function testAWindowHoldsTheThreadsAndNothingBelowItUntilTheFootCluster(): void
    {
        if (!extension_loaded('gd') || !function_exists('imagecreatefrompng')) {
            $this->markTestSkipped('GD with PNG support is not available, so the window cannot be checked against the art.');
        }

        $alphaMax = 120;   // GD alpha runs 0 (opaque) to 127 (transparent); below this is visible
        $clearTo  = 2400;  // the foot cluster's first pixel is at 2546 on every rank
        $seen     = 0;
        $byPattern = [];
        foreach ($this->policy() as $order => $entries) {
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                [$y0, $y1] = $entry['y'];
                $masters = array_filter(
                    $this->plates(),
                    static fn (string $p): bool => fnmatch($entry['pattern'], $p) && str_ends_with($p, '.png')
                );
                foreach ($masters as $file) {
                    $im = imagecreatefrompng(self::ART . '/' . $file);
                    $this->assertNotFalse($im, "{$order}: cannot read {$file}");
                    // A palette PNG has no alpha channel in the pixel value: read it as truecolour so the
                    // check below sees the transparency rather than a palette index.
                    imagepalettetotruecolor($im);
                    // A hi-res master is checked on the layout grid: rows and columns step by its
                    // scale, so the window (in layout rows) and the cost are the same as at 1x.
                    $k = self::scaleOf($file);
                    $w = imagesx($im);
                    $h = min(imagesy($im), $clearTo * $k);
                    $stray = [];
                    for ($y = 0; $y < $h; $y += $k) {
                        if ($y >= $y0 * $k && $y < $y1 * $k) {
                            $y = $y1 * $k - $k;   // the window itself may hold anything
                            continue;
                        }
                        for ($x = 0; $x < $w; $x += $k) {
                            if (((imagecolorat($im, $x, $y) >> 24) & 0x7F) < $alphaMax) {
                                $stray[] = '(' . intdiv($x, $k) . ',' . intdiv($y, $k) . ')';
                                break 2;
                            }
                        }
                    }
                    imagedestroy($im);
                    $this->assertSame(
                        [],
                        $stray,
                        "{$order}: {$file} has a visible pixel at {$stray[0]} outside its window rows {$y0} to {$y1} (checked down to row {$clearTo}); "
                        . 'the art moved, so the window or the art needs revisiting'
                    );
                    $seen++;
                    $byPattern[$entry['pattern']] = ($byPattern[$entry['pattern']] ?? 0) + 1;
                }
            }
        }
        $this->assertGreaterThan(0, $seen, 'no windowed entry matched any PNG master; this test would pass vacuously');

        // Count per pattern, so a renamed rank file cannot quietly drop out of the check: Garber's
        // eleven rank plates are rank_0 to rank_9 (one pattern) and rank_10 (the other).
        $this->assertSame(10, $byPattern['garber/rank_?.*'] ?? 0, 'garber rank_0 to rank_9 masters checked');
        $this->assertSame(1, $byPattern['garber/rank_10.*'] ?? 0, 'garber rank_10 master checked');
        $onDisk = array_values(array_filter($this->plates(), static fn (string $p): bool => preg_match('#^garber/rank_\d+\.png$#', $p) === 1));
        $this->assertCount(11, $onDisk, 'the Garber set is eleven rank plates');
        $this->assertSame(11, ($byPattern['garber/rank_?.*'] ?? 0) + ($byPattern['garber/rank_10.*'] ?? 0), 'every Garber rank master was checked');
    }

    public function testPatternsAreRelativeToTheScrollsDirectory(): void
    {
        foreach ($this->policy() as $order => $entries) {
            foreach ($this->patternsOf($entries) as $pattern) {
                $this->assertStringStartsNotWith('/', $pattern, "{$order}: '{$pattern}' must be relative to assets/scrolls/");
                $this->assertStringNotContainsString('..', $pattern, "{$order}: '{$pattern}' must stay inside assets/scrolls/");
                $this->assertStringNotContainsString('scrolls/', $pattern, "{$order}: '{$pattern}' carries a scrolls/ prefix; the root is already assets/scrolls/");
            }
        }
    }

    public function testEveryPatternMatchesARealPlate(): void
    {
        $plates = $this->plates();
        foreach ($this->policy() as $order => $entries) {
            foreach ($this->patternsOf($entries) as $pattern) {
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
        $custom = $this->patternsOf($this->policy()['custom'] ?? []);
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
        $policy = array_map(fn (array $entries): array => $this->patternsOf($entries), $this->policy());

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

        foreach ($this->policy() as $order => $entries) {
            foreach ($this->patternsOf($entries) as $pattern) {
                foreach ($displays as $file) {
                    $this->assertFalse(fnmatch($pattern, $file), "{$order}: '{$pattern}' matches the display rendition {$file}");
                }
            }
        }
    }
}
