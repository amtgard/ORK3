<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Scroll::GroundLuminance(): the paper tone behind the colour-contrast hint (A5).
 *
 * The builder cannot measure an image, so the page is handed one number per ground and
 * one per fixed order/variant. This pins that every choice the picker can offer has an
 * entry, and that the numbers are the measured ones from the data file rather than a
 * second opinion.
 */
final class ScrollGroundLuminanceTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function grounds(): iterable
    {
        foreach (array_keys(Scroll::Recipes()['grounds']) as $g) {
            yield $g => [$g];
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function orderChoices(): iterable
    {
        foreach (Scroll::Orders() as $id => $order) {
            if ($id === 'custom') {
                continue;
            }
            if ($order['variants'] === []) {
                yield $id => [$id, ''];
                continue;
            }
            foreach ($order['variants'] as $v) {
                yield "$id/$v" => [$id, $v];
            }
        }
    }

    #[DataProvider('grounds')]
    public function testEveryCustomGroundHasAnEntry(string $ground): void
    {
        $map = Scroll::GroundLuminance();
        $this->assertArrayHasKey($ground, $map['grounds']);
        $this->assertGreaterThan(0.0, $map['grounds'][$ground]);
        $this->assertLessThanOrEqual(1.0, $map['grounds'][$ground]);
    }

    #[DataProvider('orderChoices')]
    public function testEveryOrderAndVariantHasItsOwnGround(string $order, string $variant): void
    {
        $map = Scroll::GroundLuminance();
        $this->assertArrayHasKey($order, $map['orders'], "$order has no luminance entry");
        $entry = $map['orders'][$order];
        if ($variant === '') {
            $this->assertIsFloat($entry + 0.0);
            $this->assertGreaterThan(0.0, (float) $entry);
            return;
        }
        $this->assertIsArray($entry, "$order has variants, so its entry is keyed by variant");
        $this->assertArrayHasKey($variant, $entry);
        $this->assertGreaterThan(0.0, (float) $entry[$variant]);
    }

    public function testNumbersAreTheMeasuredOnes(): void
    {
        $map  = Scroll::GroundLuminance();
        $file = json_decode((string) file_get_contents(DIR_ORK3 . 'data/scroll-ground-luminance.json'), true);
        foreach ($file['grounds'] as $name => $row) {
            $this->assertEqualsWithDelta($row['luminance'], $map['grounds'][$name], 1e-9);
        }
        // A fixed order's number is its ground's number.
        $this->assertEqualsWithDelta($map['grounds']['linen'], $map['orders']['garber'], 1e-9);
        $this->assertEqualsWithDelta($map['grounds']['flame'], $map['orders']['flame'], 1e-9);
        $this->assertEqualsWithDelta($map['grounds']['cyanotype'], $map['orders']['owl']['cyanotype'], 1e-9);
        $this->assertEqualsWithDelta($map['grounds']['parchment'], $map['orders']['owl']['parchment'], 1e-9);
    }

    public function testOwlCyanotypeIsDarkAndTransparencyIsWhitePaper(): void
    {
        $map = Scroll::GroundLuminance();
        $this->assertLessThan(0.08, $map['orders']['owl']['cyanotype']);
        $this->assertEqualsWithDelta(1.0, $map['orders']['owl']['transparency'], 1e-9);
    }
}
