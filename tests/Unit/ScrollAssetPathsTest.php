<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The regression net.
 *
 * Expands every transliterated order across its real rank and variant range,
 * extracts every src/href, and asserts the file exists. It is the only thing that
 * catches a certificate which renders a broken image at exactly one order and one
 * rank — invisible until someone prints it. Arcanyst shipped 2.1.3 with flame and
 * zodiac both missing their rank-0 file.
 *
 * It walks whichever partials exist, so it grows by itself as orders land.
 */
final class ScrollAssetPathsTest extends TestCase
{
    /** @return iterable<string, array{string, int, string}> */
    public static function scrollMatrix(): iterable
    {
        foreach (Scroll::Orders() as $id => $order) {
            if (!is_file(Scroll::PartialPath($id))) {
                continue;   // not transliterated yet
            }
            $variants = $order['variants'] === [] ? [''] : $order['variants'];
            foreach ($variants as $variant) {
                for ($rank = $order['ranks']['from']; $rank <= $order['ranks']['to']; $rank++) {
                    $label = $id . ($variant !== '' ? "/{$variant}" : '') . "/rank{$rank}";
                    yield $label => [$id, $rank, $variant];
                }
            }
        }
    }

    #[DataProvider('scrollMatrix')]
    public function testEveryReferencedAssetExists(string $orderId, int $rank, string $variant): void
    {
        $html = Scroll::Render($this->scrollFor($orderId, $rank, $variant));

        preg_match_all('/(?:src|href)="([^"]+)"/', $html, $m);
        $checked = 0;

        foreach ($m[1] as $url) {
            if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, '#')) {
                continue;
            }
            $path = $this->localPath($url);
            if ($path === null) {
                continue;   // not an asset we ship
            }
            $this->assertFileExists($path, "{$orderId} rank {$rank} references missing {$url}");
            $checked++;
        }

        $this->assertGreaterThan(0, $checked, "{$orderId} rank {$rank} referenced no assets at all");
    }

    public function testTheMatrixIsNotEmpty(): void
    {
        $this->assertNotEmpty(iterator_to_array(self::scrollMatrix()));
    }

    public function testNoPartialEmitsADuplicateAttribute(): void
    {
        // class="a" class="b" keeps only the first — the HTML parser drops the rest
        // silently. Two of Arcanyst's elements carry both a css= binding and a
        // style=, which is exactly how this happens on transliteration.
        foreach (self::scrollMatrix() as [$orderId, $rank, $variant]) {
            $html = Scroll::Render($this->scrollFor($orderId, $rank, $variant));
            preg_match_all('/<[a-zA-Z][^>]*>/', $html, $tags);
            foreach ($tags[0] as $tag) {
                preg_match_all('/\s([a-zA-Z-]+)=/', $tag, $attrs);
                $names = array_map('strtolower', $attrs[1]);
                $this->assertSame(
                    count($names),
                    count(array_unique($names)),
                    "{$orderId} rank {$rank}: duplicate attribute in {$tag}"
                );
            }
        }
    }

    private function scrollFor(string $orderId, int $rank, string $variant): array
    {
        $order = Scroll::Orders()[$orderId];
        return [
            'order' => $orderId, 'rank' => $rank, 'variant' => $variant,
            'title' => 'Order of the Test', 'player' => 'Testerly', 'pronoun' => 'their',
            'reason' => 'testing', 'date' => '13th May 2024', 'givenBy' => 'Sir Someone',
            'issuerTitle' => 'Barony', 'issuerName' => 'Somewhere',
            'useThe' => true, 'useOf' => true,
            'heraldry' => rtrim(HTTP_ASSETS, '/') . '/heraldry/park/00435.png',
            'flavour' => (string) ($order['flavour'][(string) $rank] ?? ''),
            'primary' => $order['primary'] ?? '#000000',
            'secondary' => $order['secondary'] ?? '#ffffff',
            'resources' => $order['resources'],
            // Only custom.tpl reads this; every other partial ignores it. Present on
            // every row so the order matrix can render custom at all.
            'recipe' => Scroll::DEFAULT_RECIPE,
        ];
    }

    /**
     * The custom scroll's art is chosen at print time, so the order matrix alone does
     * not reach it. This walks the whole vocabulary instead: every ground, every
     * border and every rank set, each rendered once.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function recipeMatrix(): iterable
    {
        $v = Scroll::Recipes();

        foreach (array_keys($v['grounds']) as $ground) {
            yield "ground/{$ground}" => [$ground, 'knot', 'common'];
        }
        foreach (array_keys($v['borders']) as $border) {
            yield "border/{$border}" => ['parchment', $border, 'common'];
        }
        foreach (array_keys($v['rankSets']) as $set) {
            yield "rankset/{$set}" => ['parchment', 'knot', $set];
        }
    }

    #[DataProvider('recipeMatrix')]
    public function testEveryRecipeRendersWithNoMissingArt(string $ground, string $border, string $rankSet): void
    {
        // Rank 5 deliberately: every rank set ships rank_5, and it is inside the
        // guarded rank > 0 branch, so the numeral layer is actually exercised.
        $scroll = $this->scrollFor('custom', 5, '');
        $scroll['recipe'] = Scroll::NormaliseRecipe([
            'ground'    => $ground,
            'border'    => $border,
            'rankSet'   => $rankSet,
            'primary'   => Scroll::DEFAULT_RECIPE['primary'],
            'secondary' => Scroll::DEFAULT_RECIPE['secondary'],
        ]);

        // NormaliseRecipe silently falls back to the default for anything it does not
        // recognise, so a typo in the provider would test parchment/knot/common
        // eleven times over and pass. Assert the recipe under test survived.
        $this->assertSame($ground, $scroll['recipe']['ground']);
        $this->assertSame($border, $scroll['recipe']['border']);
        $this->assertSame($rankSet, $scroll['recipe']['rankSet']);

        $html    = Scroll::Render($scroll);
        $checked = 0;

        preg_match_all('/(?:src|href)="([^"]+)"/', $html, $m);
        foreach ($m[1] as $url) {
            if ($url === '' || str_starts_with($url, 'data:') || str_starts_with($url, '#')) {
                continue;
            }
            $path = $this->localPath($url);
            if ($path === null) {
                continue;
            }
            $this->assertFileExists($path, "recipe {$ground}/{$border}/{$rankSet} references missing {$url}");
            $checked++;
        }

        $this->assertGreaterThan(0, $checked);
    }

    /** Map an emitted URL back to a file on disk, or null if it is not ours. */
    private function localPath(string $url): ?string
    {
        $assets = rtrim(HTTP_ASSETS, '/') . '/';
        if (!str_starts_with($url, $assets)) {
            return null;
        }
        $rel = substr($url, strlen($assets));
        if (str_starts_with($rel, 'heraldry/')) {
            return null;   // player data, not shipped art
        }
        return DIR_ASSETS . $rel;
    }
}
