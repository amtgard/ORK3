<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Task 7: what the server hands the typeset engine about the plate it is typing on.
 *
 * The free-space manifest is generated offline (Task 9) and does not exist yet, so
 * every lookup test injects a manifest array; the one that reads a file points
 * Scroll::$freeSpacePathOverride at a temp copy of the same fixture. What is under
 * test is the lookup rules (the fixed-order key, the custom intersection, the
 * absent-entry skip), the compact attribute, the ink-grid helper and the cache key.
 */
final class ScrollFreeSpaceTest extends TestCase
{
    private const M = [
        'v'          => 1,
        'y0'         => 400,
        'step'       => 25,
        'configs'    => ['smith//5' => ['L' => [450, 460], 'R' => [2100, 2090]]],
        'components' => [
            'ground/linen' => ['L' => [500, 433], 'R' => [2116, 2050]],
            'border/knot'  => ['L' => [440, 470], 'R' => [2000, 2116]],
        ],
    ];

    /** @var list<string> temp files to remove */
    private array $temp = [];

    private ?string $savedOverride = null;

    protected function setUp(): void
    {
        $this->savedOverride = \Scroll::$freeSpacePathOverride;
    }

    protected function tearDown(): void
    {
        \Scroll::$freeSpacePathOverride = $this->savedOverride;
        $this->resetLayoutGeneration();
        foreach ($this->temp as $path) {
            @unlink($path);
        }
        $this->temp = [];
    }

    /** @return array<string, mixed> */
    private function scroll(array $overrides = []): array
    {
        return $overrides + ['order' => 'smith', 'variant' => '', 'rank' => 5, 'recipe' => []];
    }

    private function custom(array $recipe, int $rank = 3): array
    {
        return $this->scroll(['order' => 'custom', 'rank' => $rank, 'recipe' => $recipe]);
    }

    private function tempManifest(array $manifest): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'scfs');
        file_put_contents($path, json_encode($manifest));
        $this->temp[] = $path;

        return $path;
    }

    private function resetLayoutGeneration(): void
    {
        $p = new ReflectionProperty(\Scroll::class, 'layoutStamp');
        $p->setAccessible(true);
        $p->setValue(null, null);
    }

    private function layoutGeneration(): string
    {
        $m = new ReflectionMethod(\Scroll::class, 'layoutGeneration');
        $m->setAccessible(true);

        return (string) $m->invoke(null);
    }

    private function key(): string
    {
        $m = new ReflectionMethod(\Scroll::class, 'renderCacheKey');
        $m->setAccessible(true);

        return (string) $m->invoke(null, $this->scroll(), \Scroll::ART_MASTER);
    }

    public function testAFixedOrderIsLookedUpByOrderVariantRank(): void
    {
        $f = \Scroll::FreeSpace($this->scroll(), self::M);

        $this->assertSame([450, 460], $f['L']);
        $this->assertSame([2100, 2090], $f['R']);
        $this->assertSame(400, $f['y0']);
        $this->assertSame(25, $f['step']);
    }

    public function testAVariantIsPartOfTheFixedKey(): void
    {
        $m = self::M;
        $m['configs']['owl/parchment/0'] = ['L' => [610], 'R' => [1900]];

        $f = \Scroll::FreeSpace($this->scroll(['order' => 'owl', 'variant' => 'parchment', 'rank' => 0]), $m);
        $this->assertSame([610], $f['L']);

        // Same order and rank, no variant: a different key, so no entry.
        $this->assertNull(\Scroll::FreeSpace($this->scroll(['order' => 'owl', 'variant' => '', 'rank' => 0]), $m));
    }

    public function testACustomScrollIntersectsItsComponents(): void
    {
        $f = \Scroll::FreeSpace($this->custom(['ground' => 'linen', 'border' => 'knot', 'rankSet' => 'common']), self::M);

        $this->assertSame([500, 470], $f['L'], 'L is the max of the components, band by band');
        $this->assertSame([2000, 2050], $f['R'], 'R is the min of the components, band by band');
        $this->assertSame(400, $f['y0']);
        $this->assertSame(25, $f['step']);
    }

    public function testACustomScrollIncludesItsRankSetEntryWhenThereIsOne(): void
    {
        $m = self::M;
        $m['components']['rankset/garber/5'] = ['L' => [520, 400], 'R' => [1990, 2300]];

        $f = \Scroll::FreeSpace($this->custom(['ground' => 'linen', 'border' => 'knot', 'rankSet' => 'garber'], 5), $m);

        $this->assertSame([520, 470], $f['L']);
        $this->assertSame([1990, 2050], $f['R']);
    }

    public function testAnAbsentComponentIsSkippedNotTreatedAsUnconstrainedForTheRest(): void
    {
        // border/knot has no entry for 'none', rankset has none for 'common/3': only the
        // ground constrains, and the answer is exactly the ground's profile.
        $f = \Scroll::FreeSpace($this->custom(['ground' => 'linen', 'border' => 'none', 'rankSet' => 'common']), self::M);

        $this->assertSame([500, 433], $f['L']);
        $this->assertSame([2116, 2050], $f['R']);
    }

    public function testACustomScrollWithNoKnownComponentIsNull(): void
    {
        $s = $this->custom(['ground' => 'nowhere', 'border' => 'nothing', 'rankSet' => 'common']);

        $this->assertNull(\Scroll::FreeSpace($s, self::M));
        $this->assertSame('', \Scroll::FreeSpaceAttr($s, self::M));
        $this->assertNull(\Scroll::FreeSpace($this->custom([]), self::M), 'no recipe, nothing to look up');
    }

    public function testAShorterComponentLeavesTheRestOfTheBandsToTheOthers(): void
    {
        $m = self::M;
        $m['components']['ground/linen'] = ['L' => [500], 'R' => [2116]];

        $f = \Scroll::FreeSpace($this->custom(['ground' => 'linen', 'border' => 'knot', 'rankSet' => 'common']), $m);

        // Band 1 has no ground entry: "a missing band means unconstrained", so only the
        // border speaks there.
        $this->assertSame([500, 470], $f['L']);
        $this->assertSame([2000, 2116], $f['R']);
    }

    public function testAMissingConfigIsNullAndEmitsNothing(): void
    {
        $s = $this->scroll(['order' => 'rose', 'rank' => 1]);

        $this->assertNull(\Scroll::FreeSpace($s, self::M));
        $this->assertSame('', \Scroll::FreeSpaceAttr($s, self::M));
    }

    public function testAManifestWithoutAGridIsNull(): void
    {
        $m = self::M;
        unset($m['y0']);
        $this->assertNull(\Scroll::FreeSpace($this->scroll(), $m));

        $m = self::M;
        $m['step'] = 0;
        $this->assertNull(\Scroll::FreeSpace($this->scroll(), $m), 'a zero step cannot map y to a band');

        $m = self::M;
        $m['configs']['smith//5'] = ['L' => [450, 460], 'R' => [2100]];
        $this->assertNull(\Scroll::FreeSpace($this->scroll(), $m), 'L and R of unequal length is a broken entry');
    }

    /** @return array<string, mixed>|null */
    private function swapInkFiles(?array $files): ?array
    {
        $p = new ReflectionProperty(\Scroll::class, 'inkFiles');
        $p->setAccessible(true);
        $was = $p->getValue(null);
        $p->setValue(null, $files);

        return $was;
    }

    public function testANonNumericEdgeMakesTheEntryAbsentRatherThanZero(): void
    {
        // intval('abc') is 0, a perfectly plausible edge: the entry has to read as absent instead.
        foreach (
            [
                'a word'        => ['L' => [450, 'abc'], 'R' => [2100, 2090]],
                'null'          => ['L' => [450, null], 'R' => [2100, 2090]],
                'an array'      => ['L' => [450, [460]], 'R' => [2100, 2090]],
                'a bool'        => ['L' => [450, true], 'R' => [2100, 2090]],
                'empty string'  => ['L' => [450, 460], 'R' => [2100, '']],
                'trailing junk' => ['L' => [450, 460], 'R' => [2100, '2090px']],
            ] as $why => $entry
        ) {
            $m = self::M;
            $m['configs']['smith//5'] = $entry;
            $this->assertNull(\Scroll::FreeSpace($this->scroll(), $m), $why);
        }

        // ...and a component with such an entry is skipped, not counted as an edge at 0.
        $m = self::M;
        $m['components']['border/knot'] = ['L' => ['x', 'y'], 'R' => ['z', 'w']];
        $f = \Scroll::FreeSpace($this->custom(['ground' => 'linen', 'border' => 'knot', 'rankSet' => 'common']), $m);
        $this->assertSame([500, 433], $f['L']);
        $this->assertSame([2116, 2050], $f['R']);

        // Numeric strings and floats from a hand-edited file are still numbers.
        $m = self::M;
        $m['configs']['smith//5'] = ['L' => ['450', 460.0], 'R' => [2100, '2090']];
        $this->assertSame(['L' => [450, 460], 'R' => [2100, 2090]], array_intersect_key(\Scroll::FreeSpace($this->scroll(), $m), ['L' => 1, 'R' => 1]));
    }

    public function testTheInkGridRefusesARowWithATrailingNewlineOrAnyOtherJunk(): void
    {
        $good = array_fill(0, 32, 'deadbeef');
        $was  = $this->swapInkFiles(null);
        try {
            $this->swapInkFiles(['t/x.svg' => ['grid' => ['w' => 32, 'h' => 32, 'rows' => $good]]]);
            $this->assertSame('32x32:' . str_repeat('deadbeef', 32), \Scroll::InkGrid('t/x.svg'));

            foreach (["deadbeef\n", "deadbeef\r\n", "deadbeef ", "DEADBEEF", "deadbee", "deadbeef0", "\ndeadbeef", "deadbeeg", ''] as $bad) {
                $rows    = $good;
                $rows[7] = $bad;
                $this->swapInkFiles(['t/x.svg' => ['grid' => ['w' => 32, 'h' => 32, 'rows' => $rows]]]);
                $this->assertSame('', \Scroll::InkGrid('t/x.svg'), 'row ' . json_encode($bad));
            }
        } finally {
            $this->swapInkFiles($was);
        }
    }

    public function testTheAttributeFormat(): void
    {
        $this->assertSame('400,25|450,460|2100,2090', \Scroll::FreeSpaceAttr($this->scroll(), self::M));
        $this->assertSame(
            '400,25|500,470|2000,2050',
            \Scroll::FreeSpaceAttr($this->custom(['ground' => 'linen', 'border' => 'knot', 'rankSet' => 'common']), self::M)
        );
    }

    public function testWithNoManifestArgumentAMissingFileMeansNull(): void
    {
        \Scroll::$freeSpacePathOverride = sys_get_temp_dir() . '/scroll-free-space-does-not-exist-' . bin2hex(random_bytes(4)) . '.json';

        $this->assertNull(\Scroll::FreeSpace($this->scroll()));
        $this->assertSame('', \Scroll::FreeSpaceAttr($this->scroll()));
    }

    public function testWithNoManifestArgumentTheFileIsRead(): void
    {
        \Scroll::$freeSpacePathOverride = $this->tempManifest(self::M);

        $this->assertSame('400,25|450,460|2100,2090', \Scroll::FreeSpaceAttr($this->scroll()));
    }

    public function testAnUnparseableFileMeansNull(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'scfs');
        file_put_contents($path, '{not json');
        $this->temp[] = $path;
        \Scroll::$freeSpacePathOverride = $path;

        $this->assertNull(\Scroll::FreeSpace($this->scroll()));
    }

    public function testTheInkGridHelper(): void
    {
        $this->assertMatchesRegularExpression('/^32x32:[0-9a-f]{256}$/', scroll_ink_grid('scrolls/common/text/squiggly-right.svg'));
        $this->assertSame('', scroll_ink_grid('scrolls/does/not/exist.svg'));
    }

    public function testTheInkGridHelperTakesThePathWithOrWithoutTheScrollsPrefix(): void
    {
        // order `resources` values carry `scrolls/`; the manifest keys do not. The helper
        // strips it, as scroll_resource() does.
        $with = scroll_ink_grid('scrolls/common/text/squiggly-right.svg');
        $this->assertNotSame('', $with);
        $this->assertSame($with, scroll_ink_grid('common/text/squiggly-right.svg'));
        $this->assertSame($with, scroll_ink_grid('/scrolls/common/text/squiggly-right.svg'));
    }

    public function testTheInkGridIsTheManifestsRowsJoined(): void
    {
        $manifest = json_decode((string) file_get_contents(DIR_ASSETS . 'scrolls/ink-extents.json'), true);
        $rows     = $manifest['files']['common/text/squiggly-right.svg']['grid']['rows'];

        $this->assertCount(32, $rows);
        $this->assertSame('32x32:' . implode('', $rows), scroll_ink_grid('scrolls/common/text/squiggly-right.svg'));
    }

    public function testEveryFlourishInTheManifestHasAGridTheHelperReturns(): void
    {
        $manifest = json_decode((string) file_get_contents(DIR_ASSETS . 'scrolls/ink-extents.json'), true);
        $checked  = 0;
        foreach ($manifest['files'] as $path => $entry) {
            if (!isset($entry['grid'])) {
                continue;
            }
            $this->assertMatchesRegularExpression('/^32x32:[0-9a-f]{256}$/', scroll_ink_grid('scrolls/' . $path), $path);
            $checked++;
        }
        $this->assertGreaterThan(0, $checked, 'the manifest carries no ink grids at all');
    }

    public function testTheRenderCacheKeyMovesWithTheLayoutData(): void
    {
        $path = $this->tempManifest(self::M);
        touch($path, time() - 100);
        clearstatcache();
        \Scroll::$freeSpacePathOverride = $path;
        $this->resetLayoutGeneration();
        $before = $this->key();

        // Regenerating free-space.json is the only thing that changes: no partial, no
        // art, no input. The key has to move or a cached page keeps serving the old data-sc-free.
        touch($path, time() - 50);
        clearstatcache();
        $this->resetLayoutGeneration();
        $after = $this->key();

        $this->assertNotSame($before, $after);
    }

    public function testTheLayoutGenerationIsCachedWithinAProcess(): void
    {
        $path = $this->tempManifest(self::M);
        touch($path, time() - 100);
        clearstatcache();
        \Scroll::$freeSpacePathOverride = $path;
        $this->resetLayoutGeneration();
        $first = $this->layoutGeneration();

        touch($path, time() - 50);
        clearstatcache();

        $this->assertSame($first, $this->layoutGeneration(), 'one stat per process, like artGeneration()');
    }

    public function testTheLayoutGenerationIsBothFilesMtimesAndZeroForAMissingOne(): void
    {
        \Scroll::$freeSpacePathOverride = sys_get_temp_dir() . '/scroll-free-space-does-not-exist-' . bin2hex(random_bytes(4)) . '.json';
        $this->resetLayoutGeneration();

        $inkMtime = (int) filemtime(DIR_ASSETS . 'scrolls/ink-extents.json');
        $this->assertSame('0|' . $inkMtime, $this->layoutGeneration());

        $path = $this->tempManifest(self::M);
        touch($path, 1700000000);
        clearstatcache();
        \Scroll::$freeSpacePathOverride = $path;
        $this->resetLayoutGeneration();
        $this->assertSame('1700000000|' . $inkMtime, $this->layoutGeneration());
    }

    public function testTheLayoutGenerationFollowsTheOverridePathWithoutAReset(): void
    {
        $a = $this->tempManifest(self::M);
        $b = $this->tempManifest(self::M);
        touch($a, 1700000000);
        touch($b, 1700000500);
        clearstatcache();

        \Scroll::$freeSpacePathOverride = $a;
        $this->resetLayoutGeneration();
        $first = $this->layoutGeneration();

        \Scroll::$freeSpacePathOverride = $b;
        $this->assertNotSame($first, $this->layoutGeneration());
    }

    public function testTheKeyCarriesTheLayoutGeneration(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/system/lib/ork3/class.Scroll.php');
        $this->assertStringContainsString("'layout' => self::layoutGeneration()", $src);
    }
}
