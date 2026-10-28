<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Scroll::CustomBorderRule()'s yes/no, PHP half of the JS mirror.
 *
 * The builder hides the Custom Border box with scrollery-customborder.js; the server
 * decides with Scroll::CustomBorderRule(). Both are driven from
 * tests/fixtures/scroll-custom-border-compat.json (the JS half is
 * tools/scroll-sweep/tests/customborder.spec.ts), so a rule changed on one side
 * turns the other side red.
 */
final class ScrollCustomBorderParityTest extends TestCase
{
    private static function fixture(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__ . '/../fixtures/scroll-custom-border-compat.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }

    /** @return iterable<string, array{string, int, string, array<string, string>, bool}> */
    public static function cases(): iterable
    {
        foreach (self::fixture()['cases'] as $c) {
            yield $c['name'] => [$c['order'], $c['rank'], $c['variant'], $c['recipe'], $c['compatible']];
        }
    }

    #[DataProvider('cases')]
    public function testRuleMatchesFixture(string $order, int $rank, string $variant, array $recipe, bool $want): void
    {
        $rule = Scroll::CustomBorderRule($order, $rank, $variant, $recipe);
        $this->assertSame($want, $rule !== null);
    }

    /** The page reads these from the option's data-*; the fixture must not drift from them. */
    public function testFixtureOrderMetadataMatchesTheOrders(): void
    {
        $fx = self::fixture();
        foreach ($fx['orders'] as $id => $meta) {
            $order = Scroll::Orders()[$id];
            $this->assertSame($order['ranks']['from'] ?? 0, $meta['from'], "{$id} from");
            $this->assertSame($order['ranks']['to'] ?? 0, $meta['to'], "{$id} to");
            $this->assertSame(array_values($order['variants'] ?? []), $meta['variants'], "{$id} variants");
        }
        foreach ($fx['cases'] as $c) {
            $this->assertArrayHasKey($c['order'], $fx['orders'], $c['name']);
        }
        foreach (['parchment', 'linen', 'jovious', 'flame', 'sunburst', 'map', 'cyanotype'] as $g) {
            $this->assertSame(in_array($g, $fx['groundsWithOwnBorder'], true), Scroll::GroundDrawsOwnBorder($g), $g);
        }
    }
}
