<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Scroll;

/**
 * The sweep is only a proof while it covers everything.
 *
 * A new hand or a new order partial that nothing sweeps is exactly how this class of
 * defect got in the last time: the six display faces added most recently are wider
 * than the one the certificate was laid out against, nothing failed when they landed,
 * and the collision they caused was found by a person looking at a certificate months
 * later. Measured, on the same content: the issuing title line is 163px in Chancery
 * and 318px in Cinzel.
 *
 * This is what fails now. It asserts against tools/scroll-sweep/axes.json, which
 * bin/dump-axes.php generates from the PHP data files, so the failure reads "you added
 * a hand and did not regenerate the axes" — one command to fix, and impossible to miss.
 */
final class ScrollSweepCoverageTest extends TestCase
{
    private const AXES = __DIR__ . '/../../tools/scroll-sweep/axes.json';
    private const PARTIALS = __DIR__ . '/../../orkui/template/revised-frontend/scroll/';
    private const UNREACHABLE = __DIR__ . '/../../tools/scroll-sweep/unreachable-orders.json';
    private const REGENERATE = 'Run: php tools/scroll-sweep/bin/dump-axes.php > tools/scroll-sweep/axes.json';

    /** @return array<string, mixed> */
    private function axes(): array
    {
        $this->assertFileExists(self::AXES, self::REGENERATE);

        return json_decode((string) file_get_contents(self::AXES), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function orderPartials(): array
    {
        $out = [];
        foreach ((array) glob(self::PARTIALS . '*.tpl') as $file) {
            $name = basename((string) $file, '.tpl');
            // Shared layers (_text, _border, _heraldry, ...) are not orders.
            if (str_starts_with($name, '_')) {
                continue;
            }
            $out[] = $name;
        }
        sort($out);

        return $out;
    }

    public function testEveryHandIsSwept(): void
    {
        $recipes = require __DIR__ . '/../../system/lib/ork3/data/scroll-recipes.php';

        $this->assertSame(
            array_keys($recipes['hands']),
            $this->axes()['hands'],
            'A hand was added or removed without regenerating the sweep axes. ' . self::REGENERATE
        );
    }

    public function testEveryOrderPartialIsSweptOrDeclaredUnreachable(): void
    {
        $swept = array_values(array_unique(array_column($this->axes()['orderVariants'], 'order')));

        $this->assertFileExists(self::UNREACHABLE);
        $declared = json_decode((string) file_get_contents(self::UNREACHABLE), true, 512, JSON_THROW_ON_ERROR);

        $missing = array_values(array_diff($this->orderPartials(), $swept, $declared['unreachable']));

        $this->assertSame(
            [],
            $missing,
            'These order partials are neither swept nor declared unreachable: '
            . implode(', ', $missing) . '. ' . self::REGENERATE
            . ' — or, if no route can reach it, add it to tools/scroll-sweep/unreachable-orders.json WITH THE REASON.'
        );
    }

    /**
     * An unreachable order without a reason is a skipped test wearing a JSON file.
     */
    public function testEveryUnreachableOrderCarriesItsReason(): void
    {
        $declared = json_decode((string) file_get_contents(self::UNREACHABLE), true, 512, JSON_THROW_ON_ERROR);

        foreach ($declared['unreachable'] as $order) {
            $this->assertArrayHasKey($order, $declared['reasons'], "$order is declared unreachable with no reason");
            $this->assertGreaterThan(
                40,
                strlen((string) $declared['reasons'][$order]),
                "$order's reason has to say WHY no route reaches it"
            );
            $this->assertContains($order, $this->orderPartials(), "$order is declared unreachable but has no partial");
        }
    }

    public function testEveryVariantIsSwept(): void
    {
        $orders = require __DIR__ . '/../../system/lib/ork3/data/scroll-orders.php';

        $expected = 0;
        foreach ($orders as $def) {
            $expected += max(1, count($def['variants'] ?? []));
        }

        $this->assertCount(
            $expected,
            $this->axes()['orderVariants'],
            'A variant was added without regenerating the sweep axes. ' . self::REGENERATE
        );
    }

    /**
     * dump-axes.php reads this constant with a regex, because it deliberately does not
     * boot the app. Inside PHPUnit the class IS loadable, so this is where the two are
     * checked against each other.
     */
    public function testTheDumpedCustomNameCapMatchesTheConstant(): void
    {
        $this->assertSame(
            Scroll::CUSTOM_NAME_MAX_LENGTH,
            $this->axes()['caps']['customName'],
            'dump-axes.php parses this constant out of class.Scroll.php; it has drifted'
        );
    }

    /**
     * The recipe vocabulary the composed scroll is swept across.
     */
    public function testEveryGroundBorderAndRankSetIsSwept(): void
    {
        $recipes = require __DIR__ . '/../../system/lib/ork3/data/scroll-recipes.php';
        $axes = $this->axes();

        $this->assertSame(array_keys($recipes['grounds']), $axes['grounds'], self::REGENERATE);
        $this->assertSame(array_keys($recipes['borders']), $axes['borders'], self::REGENERATE);
        $this->assertSame(array_keys($recipes['rankSets']), $axes['rankSets'], self::REGENERATE);
        $this->assertSame($recipes['groundsWithOwnBorder'], $axes['groundsWithOwnBorder'], self::REGENERATE);
    }
}
