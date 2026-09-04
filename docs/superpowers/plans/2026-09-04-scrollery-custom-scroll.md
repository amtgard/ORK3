# Scrollery Custom Scroll Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every award the ORK can grant printable — the 81 uncovered titles, peerages and awards via a composable "custom scroll", archery via a name-keyed mapping — and let an officer correct an inferred pronoun.

**Architecture:** A `custom` pseudo-order whose art is not fixed: ground, border, border colours and rank-numeral set arrive in the scroll array and are composed by `custom.tpl` through the existing shared partials. Recipes are chosen per print and travel in the query string; a `DEFAULT_RECIPE` constant covers every path that prints without one. Archery resolves by `ork_kingdomaward.name` because no base award id exists to key on.

**Tech Stack:** PHP 8.2, ORK3's own MVC, `.tpl` templates (plain PHP), PHPUnit 11, vanilla JS.

**Spec:** `docs/superpowers/specs/2026-09-04-scrollery-custom-scroll-design.md` — read it first. This plan argues from it.

## Global Constraints

- **Branch:** `scrollery`, continuing from phase 1. All 448 existing unit tests must stay green.
- **`.tpl` files are PLAIN PHP.** `<?php ?>` / `<?= ?>`; `{$var}` renders literally.
- **Escape with `scroll_e()`**, never `html_encode()` — the latter is `htmlentities` with HTML5 tables and turns `#` into `&num;`, which breaks colours in style attributes.
- **Document order is z-order.** `.award-template > *` is absolutely positioned full-bleed. Never reorder siblings, never deduplicate repeated layers.
- **Per-order CSS is `[data-order]`-scoped in `scrollery.css`.** Never inline a `<style>` block in a partial — on a bulk page of mixed orders the last one would win for all of them.
- **`mysql_real_escape_string()` is a NO-OP SHIM.** Only `(int)` casts and regex validation protect a query.
- **`HasAuthority()` returns NULL on denial, not false.** Cast when a bool is needed.
- **Ajax controllers must `exit` after echoing JSON**, or `index.php` appends the whole themed page.
- **Rank numeral sets are not uniform:** `garber/` ships `rank_0..10`; `common/`, `zodiac/` and `flame/` ship `rank_1..10` only. The custom scroll therefore guards its numeral on `rank > 0` for every set — garber's `rank_0` goes unused rather than special-cased.
- **Officer positions are excluded** from the custom scroll: `ork_award.officer_role <> 'none'`.
- **PSR-12.** Run `php tools/php-cs-fixer/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php --path-mode=intersection <files>` before each commit.
- **NEVER stage `system/lib/ork3/class.Authorization.php`** — it carries a local login bypass. Stage explicitly; never `git add -A`.
- Commit after every task, `Enhancement:` / `Fix:` prefixes matching repo style.

## Prerequisites

```bash
docker compose -f docker-compose.php8.yml up -d
vendor/bin/phpunit --testsuite unit    # Expected: OK, 448 tests
```

## File Structure

| File | Responsibility |
|---|---|
| `system/lib/ork3/data/scroll-recipes.php` | The composable vocabulary: 12 grounds, 10 borders, 4 numeral sets, 16 colour pairs. Returns an array; no side effects. |
| `system/lib/ork3/class.Scroll.php` | Gains `DEFAULT_RECIPE`, `Recipes()`, `NormaliseRecipe()`, `IsCustomEligible()`, `ArcheryOrderForName()`; `HasArt()` and `BuildScroll()` widen. |
| `orkui/template/revised-frontend/scroll/custom.tpl` | The composed certificate. One responsibility: stack ground, border, numeral, text from the recipe. |
| `orkui/template/revised-frontend/style/scrollery.css` | `[data-order="custom"]` rules. |
| `orkui/model/model.Scrollery.php` | Award-row → order resolution now consults archery names and custom eligibility; bulk query selects `officer_role`. |
| `orkui/controller/controller.ScrolleryAjax.php` | Preview accepts a recipe and a pronoun override. |
| `orkui/controller/controller.Scrollery.php` | `compose()` accepts a recipe and a pronoun override. |
| `orkui/template/revised-frontend/Scrollery_create.tpl` | Recipe selectors + pronoun override, shown only for the custom order. |
| `orkui/template/revised-frontend/script/scrollery.js` | Recipe/pronoun controls drive the preview. |
| `tests/Unit/ScrollRecipeTest.php` | Recipe vocabulary, normalisation, defaults. |
| `tests/Unit/ScrollCustomEligibilityTest.php` | Who gets a custom scroll; archery name resolution. |
| `tests/Unit/ScrollPronounTest.php` | Possessive resolution including the `he/him → his` trap. |

---

### Task 1: The recipe vocabulary

**Files:**
- Create: `system/lib/ork3/data/scroll-recipes.php`
- Modify: `system/lib/ork3/class.Scroll.php`
- Test: `tests/Unit/ScrollRecipeTest.php`

**Interfaces:**
- Produces:
  - `Scroll::Recipes(): array` — `['grounds' => [...], 'borders' => [...], 'rankSets' => [...], 'palette' => [...]]`
  - `Scroll::DEFAULT_RECIPE` — `['ground' => 'parchment', 'border' => 'knot', 'primary' => 'hsl(35, 29%, 55%)', 'secondary' => 'hsl(34, 72%, 78%)', 'rankSet' => 'common']`.
    `ground`, `border` and `rankSet` are KEYS into the vocabulary, never paths — the
    path is looked up at render time so a recipe stays valid if a file ever moves.
  - `Scroll::NormaliseRecipe(array $requested): array` — every key validated against the vocabulary, anything unknown replaced by the default's value.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ScrollRecipeTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScrollRecipeTest extends TestCase
{
    public function testTheVocabularyListsEveryGroundBorderAndNumeralSet(): void
    {
        $r = Scroll::Recipes();

        $this->assertCount(12, $r['grounds'], 'expected 12 full-page grounds');
        $this->assertCount(10, $r['borders'], 'expected the knotwork plus 9 rasters');
        $this->assertCount(4, $r['rankSets'], 'expected common, zodiac, garber, flame');
        $this->assertCount(16, $r['palette'], "expected the 16 orders' colour pairs");

        $this->assertArrayHasKey('knot', $r['borders'], 'the parametric border must be selectable');
    }

    /** Every ground and raster border must be a file we actually ship. */
    public function testEveryPieceInTheVocabularyExistsOnDisk(): void
    {
        $r = Scroll::Recipes();

        foreach ($r['grounds'] as $key => $path) {
            $this->assertFileExists(DIR_ASSETS . 'scrolls/' . $path, "ground {$key} missing");
        }
        foreach ($r['borders'] as $key => $path) {
            if ($key === 'knot') {
                continue;   // the parametric SVG partial, not a file under assets/
            }
            $this->assertFileExists(DIR_ASSETS . 'scrolls/' . $path, "border {$key} missing");
        }
        foreach ($r['rankSets'] as $key => $dir) {
            // Ranks 1-10 are the guaranteed span; garber also ships rank_0, which the
            // custom scroll deliberately never uses.
            for ($n = 1; $n <= 10; $n++) {
                $this->assertFileExists(
                    DIR_ASSETS . 'scrolls/' . $dir . '/rank_' . $n . '.png',
                    "rank set {$key} missing rank_{$n}"
                );
            }
        }
    }

    public function testTheDefaultRecipeIsItselfValid(): void
    {
        $r = Scroll::Recipes();
        $d = Scroll::DEFAULT_RECIPE;

        $this->assertArrayHasKey($d['ground'], $r['grounds']);
        $this->assertArrayHasKey($d['border'], $r['borders']);
        $this->assertArrayHasKey($d['rankSet'], $r['rankSets']);
        $this->assertSame(Scroll::DEFAULT_RECIPE, Scroll::NormaliseRecipe([]));
    }

    public function testNormaliseReplacesAnythingUnknownWithTheDefault(): void
    {
        $out = Scroll::NormaliseRecipe([
            'ground'  => '../../etc/passwd',
            'border'  => 'nonsense',
            'rankSet' => 'nope',
            'primary' => 'javascript:alert(1)',
        ]);

        $this->assertSame(Scroll::DEFAULT_RECIPE, $out);
    }

    public function testNormaliseKeepsValidChoices(): void
    {
        $out = Scroll::NormaliseRecipe([
            'ground'  => 'linen',
            'border'  => 'lion',
            'rankSet' => 'zodiac',
            'primary' => 'hsl(114, 57%, 37%)',
            'secondary' => 'hsl(41, 93%, 55%)',
        ]);

        $this->assertSame('linen', $out['ground']);
        $this->assertSame('lion', $out['border']);
        $this->assertSame('zodiac', $out['rankSet']);
        $this->assertSame('hsl(114, 57%, 37%)', $out['primary']);
    }

    /** A colour outside the curated palette is refused — free entry was rejected. */
    public function testColoursMustComeFromTheCuratedPalette(): void
    {
        $out = Scroll::NormaliseRecipe(['primary' => '#ff00ff', 'secondary' => '#00ff00']);

        $this->assertSame(Scroll::DEFAULT_RECIPE['primary'], $out['primary']);
        $this->assertSame(Scroll::DEFAULT_RECIPE['secondary'], $out['secondary']);
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

```bash
vendor/bin/phpunit --testsuite unit --filter ScrollRecipe
```

Expected: FAIL — `Call to undefined method Scroll::Recipes()`.

- [ ] **Step 3: Write the data file**

Create `system/lib/ork3/data/scroll-recipes.php`. Keys are stable identifiers used in URLs; values are paths relative to `assets/scrolls/`.

```php
<?php

declare(strict_types=1);

/**
 * The custom scroll's composable vocabulary.
 *
 * These are the pieces of Arcanyst's artwork that are NOT order-identifying: full-page
 * grounds, borders, and rank numerals. Order symbols (the lion, the griffon, the
 * phoenix) are deliberately absent — they are order identity, and a griffon on a
 * Knighthood reads as a mistake rather than a choice.
 *
 * Every ground is exactly 2550x3300, the foreignObject's own size, which is what makes
 * any ground a drop-in swap for any other.
 *
 * Returns an array; no side effects. startup.php's scandir of system/lib/ork3 is flat,
 * so nothing in data/ is auto-instantiated.
 */

return [
    'grounds' => [
        'parchment'   => 'common/parchment.jpg',
        'linen'       => 'garber/linen.jpg',
        'jovious'     => 'jovious/bg.jpg',
        'flame'       => 'flame/bg.jpg',
        'starfield'   => 'zodiac/bg_generic.jpg',
        'starfield2'  => 'zodiac/bg_rank.jpg',
        'cyanotype'   => 'owl/cyanotype.jpg',
        'sunburst'    => 'warrior/bg_10.jpg',
        'map'         => 'smith/bg.jpg',
        'forge'       => 'smith/burn.jpg',
        'rosewash'    => 'rose/burn.jpg',
        'arcane'      => 'battle/arcane/wash.jpg',
    ],

    // 'knot' is the parametric SVG partial (_border.tpl), recoloured through
    // --primary-color / --secondary-color. The rest are raster overlays.
    'borders' => [
        'knot'   => '',
        'crown'  => 'crown/border.png',
        'hunter' => 'hunter/border.png',
        'hydra'  => 'hydra/border.png',
        'lion'   => 'lion/border.png',
        'mask'   => 'mask/border.png',
        'owl'    => 'owl/border.jpg',
        'rose'   => 'rose/border.png',
        'smith'  => 'smith/border.png',
        'battle' => 'battle/border_symbols.png',
    ],

    'rankSets' => [
        'common' => 'common',
        'zodiac' => 'zodiac',
        'garber' => 'garber',
        'flame'  => 'flame',
    ],

    // The 16 colour pairs the artist actually chose. Free colour entry was considered
    // and rejected: every pair here was drawn deliberately, and two arbitrary colours
    // can look wrong on artwork that was not designed for them.
    'palette' => [
        'Griffon tan'    => ['hsl(35, 29%, 55%)',  'hsl(34, 72%, 78%)'],
        'Dragon green'   => ['hsl(114, 57%, 37%)', 'hsl(41, 93%, 55%)'],
        'Battle red'     => ['hsl(0, 57%, 61%)',   'hsl(30, 55%, 80%)'],
        'Crown gold'     => ['hsl(43, 74%, 49%)',  'hsl(43, 40%, 82%)'],
        'Lion purple'    => ['hsl(280, 35%, 55%)', 'hsl(43, 74%, 62%)'],
        'Hydra teal'     => ['hsl(160, 45%, 42%)', 'hsl(160, 30%, 78%)'],
        'Rose crimson'   => ['hsl(340, 55%, 48%)', 'hsl(120, 25%, 60%)'],
        'Smith steel'    => ['hsl(210, 25%, 45%)', 'hsl(210, 20%, 75%)'],
        'Owl indigo'     => ['hsl(220, 45%, 35%)', 'hsl(220, 25%, 80%)'],
        'Mask ivory'     => ['hsl(30, 20%, 30%)',  'hsl(30, 30%, 88%)'],
        'Garber blue'    => ['hsl(215, 55%, 50%)', 'hsl(215, 30%, 80%)'],
        'Jovious rose'   => ['hsl(330, 45%, 60%)', 'hsl(205, 50%, 65%)'],
        'Flame ember'    => ['hsl(15, 75%, 52%)',  'hsl(43, 85%, 60%)'],
        'Warrior violet' => ['hsl(265, 40%, 48%)', 'hsl(265, 25%, 80%)'],
        'Zodiac night'   => ['hsl(230, 45%, 28%)', 'hsl(45, 60%, 75%)'],
        'Walker slate'   => ['hsl(200, 15%, 40%)', 'hsl(200, 15%, 78%)'],
    ],
];
```

- [ ] **Step 4: Add the accessors to `class.Scroll.php`**

Insert after `DEFAULT_RESOURCES`:

```php
    /**
     * The custom scroll used when an award has no design of its own.
     *
     * A recipe is chosen per print and travels in the URL, so nothing persists it.
     * This constant is what every path that prints WITHOUT an explicit recipe uses —
     * the reprint list and a bulk run both reach that state — which is what makes an
     * uncovered award printable everywhere and a reprint deterministic.
     */
    public const DEFAULT_RECIPE = [
        'ground'    => 'parchment',
        'border'    => 'knot',
        'primary'   => 'hsl(35, 29%, 55%)',
        'secondary' => 'hsl(34, 72%, 78%)',
        'rankSet'   => 'common',
    ];

    /** @var array<string, array>|null */
    private static ?array $recipes = null;

    /** @return array{grounds:array,borders:array,rankSets:array,palette:array} */
    public static function Recipes(): array
    {
        if (self::$recipes === null) {
            self::$recipes = require DIR_ORK3 . 'data/scroll-recipes.php';
        }

        return self::$recipes;
    }

    /**
     * Validate a requested recipe against the vocabulary.
     *
     * Anything unrecognised falls back to the default rather than being passed
     * through — these values reach an <img src> and a style attribute, and the
     * vocabulary is a closed set, so an allowlist is the whole defence.
     */
    public static function NormaliseRecipe(array $requested): array
    {
        $v      = self::Recipes();
        $recipe = self::DEFAULT_RECIPE;

        $ground = (string) ($requested['ground'] ?? '');
        if (isset($v['grounds'][$ground])) {
            $recipe['ground'] = $ground;
        }

        $border = (string) ($requested['border'] ?? '');
        if (isset($v['borders'][$border])) {
            $recipe['border'] = $border;
        }

        $rankSet = (string) ($requested['rankSet'] ?? '');
        if (isset($v['rankSets'][$rankSet])) {
            $recipe['rankSet'] = $rankSet;
        }

        // Colours are only accepted as a PAIR drawn from the curated palette, so a
        // caller cannot mix one order's primary with another's secondary.
        $primary   = (string) ($requested['primary'] ?? '');
        $secondary = (string) ($requested['secondary'] ?? '');
        foreach ($v['palette'] as $pair) {
            if ($pair[0] === $primary && $pair[1] === $secondary) {
                $recipe['primary']   = $primary;
                $recipe['secondary'] = $secondary;
                break;
            }
        }

        return $recipe;
    }
```

- [ ] **Step 5: Run the tests**

```bash
vendor/bin/phpunit --testsuite unit --filter ScrollRecipe
```

Expected: PASS (6 tests). If a ground or border path fails `assertFileExists`, the path in `scroll-recipes.php` is wrong — fix the data, not the test.

- [ ] **Step 6: Confirm the ORK still boots**

```bash
curl -s -o /dev/null -w '%{http_code}\n' 'http://localhost:19080/orkui/index.php?Route=Login/login'
# Expected: 200
vendor/bin/phpunit --testsuite unit
# Expected: no new failures
```

- [ ] **Step 7: Commit**

```bash
php tools/php-cs-fixer/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php --path-mode=intersection \
  system/lib/ork3/class.Scroll.php system/lib/ork3/data/scroll-recipes.php tests/Unit/ScrollRecipeTest.php
git add system/lib/ork3/class.Scroll.php system/lib/ork3/data/scroll-recipes.php tests/Unit/ScrollRecipeTest.php
git commit -m "Enhancement: Scrollery — the custom scroll's composable vocabulary"
```

---

### Task 2: Eligibility and archery name resolution

**Files:**
- Modify: `system/lib/ork3/class.Scroll.php`
- Test: `tests/Unit/ScrollCustomEligibilityTest.php`

**Interfaces:**
- Consumes: `Scroll::OrderForAwardId()`, `Scroll::Orders()` from phase 1.
- Produces:
  - `Scroll::ARCHERY_NAMES` — the curated name list.
  - `Scroll::ArcheryOrderForName(string $name): ?array` — the `hunter` order, or null.
  - `Scroll::IsCustomEligible(array $awardRow): bool`
  - `Scroll::OrderForAwardRow(array $awardRow): ?array` — the single resolution point: specific order, else archery by name, else `custom`, else null.
  - `Scroll::HasArt()` now delegates to `OrderForAwardRow()`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ScrollCustomEligibilityTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScrollCustomEligibilityTest extends TestCase
{
    private function row(array $o = []): array
    {
        return array_merge([
            'AwardId'          => 0,
            'KingdomAwardName' => '',
            'Name'             => '',
            'OfficerRole'      => 'none',
        ], $o);
    }

    /** The 16 specific orders keep their own artwork. */
    public function testASpecificOrderStillWins(): void
    {
        $this->assertSame('griffon', Scroll::OrderForAwardRow($this->row(['AwardId' => 33]))['id']);
        $this->assertSame('battle', Scroll::OrderForAwardRow($this->row(['AwardId' => 243]))['id']);
    }

    /**
     * Archery has no base award id to key on — real grants hang off award_id 0, 94
     * and 131 — so the kingdomaward NAME is the key.
     */
    public function testArcheryResolvesByName(): void
    {
        foreach ([
            'Order of the Hunter',
            'Order of the Archer',
            'Order of the Marksman',
            'Order of the Sharpshooter',
            'Hunter',
        ] as $name) {
            $order = Scroll::OrderForAwardRow($this->row(['AwardId' => 94, 'KingdomAwardName' => $name]));
            $this->assertSame('hunter', $order['id'], "{$name} should print the archery certificate");
        }
    }

    public function testArcheryMatchingIgnoresCaseAndSurroundingSpace(): void
    {
        $order = Scroll::OrderForAwardRow($this->row([
            'AwardId' => 0, 'KingdomAwardName' => '  order of the MARKSMAN  ',
        ]));
        $this->assertSame('hunter', $order['id']);
    }

    /**
     * Master Hunter and Master Archer deliberately fall through to the custom scroll:
     * no other ladder gives its Master tier the ladder's own artwork.
     */
    public function testArcheryMastersFallThroughToTheCustomScroll(): void
    {
        foreach (['Master Hunter', 'Master Archer'] as $name) {
            $order = Scroll::OrderForAwardRow($this->row(['AwardId' => 131, 'KingdomAwardName' => $name]));
            $this->assertSame('custom', $order['id'], "{$name} should get the custom scroll, not hunter art");
        }
    }

    /** An officer position is a job held, not an honour given. */
    public function testOfficerPositionsAreNotPrintable(): void
    {
        $row = $this->row(['AwardId' => 189, 'KingdomAwardName' => 'Guildmaster of Archers', 'OfficerRole' => 'other-officer']);

        $this->assertFalse(Scroll::IsCustomEligible($row));
        $this->assertNull(Scroll::OrderForAwardRow($row));
        $this->assertFalse(Scroll::HasArt($row));
    }

    /** Everything else with no specific order gets the custom scroll. */
    public function testTitlesPeeragesAndPlainAwardsGetTheCustomScroll(): void
    {
        foreach ([
            ['AwardId' => 17, 'KingdomAwardName' => 'Knight of the Flame'],
            ['AwardId' => 1,  'KingdomAwardName' => 'Master Rose'],
            ['AwardId' => 38, 'KingdomAwardName' => 'Paragon Archer'],
            ['AwardId' => 31, 'KingdomAwardName' => 'Order of the Walker in the Middle'],
        ] as $o) {
            $row = $this->row($o);
            $this->assertTrue(Scroll::IsCustomEligible($row), "{$o['KingdomAwardName']} should be eligible");
            $this->assertSame('custom', Scroll::OrderForAwardRow($row)['id']);
            $this->assertTrue(Scroll::HasArt($row));
        }
    }

    /**
     * Award 31 is a genuine RANKED ladder Arcanyst never drew, so its custom scroll
     * must still be able to carry a numeral.
     */
    public function testTheUncoveredLadderIsRankedNotFlat(): void
    {
        $custom = Scroll::Orders()['custom'];
        $this->assertSame(0, $custom['ranks']['from']);
        $this->assertSame(10, $custom['ranks']['to']);
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

```bash
vendor/bin/phpunit --testsuite unit --filter ScrollCustomEligibility
```

Expected: FAIL — `Call to undefined method Scroll::OrderForAwardRow()`.

- [ ] **Step 3: Add the `custom` order to `scroll-orders.php`**

Append a `custom` entry before the closing `);`. Its `award_id` is 0 so `OrderForAwardId()` can never reach it — it is selected by `OrderForAwardRow()` only.

```php
  'custom' =>
  array(
    'id' => 'custom',
    // 0 deliberately: no award maps to the custom scroll BY ID. It is chosen by
    // Scroll::OrderForAwardRow() when an award has no design of its own, so it must
    // never win an id lookup.
    'award_id' => 0,
    'ranks' => null,
    'variants' =>
    array(
    ),
    'primary' => 'hsl(35, 29%, 55%)',
    'secondary' => 'hsl(34, 72%, 78%)',
    'resources' =>
    array(
    ),
    'flavour' =>
    array(
      0 => '',
      1 => '',
      2 => '',
      3 => '',
      4 => '',
      5 => '',
      6 => '',
      7 => '',
      8 => '',
      9 => '',
      10 => '',
    ),
  ),
```

Flavour is empty at every rank: the custom scroll carries no order-specific sentiment, because there is none to carry. The text layer renders an empty flavour section without complaint.

- [ ] **Step 4: Add the resolution methods to `class.Scroll.php`**

```php
    /**
     * Kingdomaward names that print the archery certificate.
     *
     * Archery is the one order with no base award id to key on: real grants hang off
     * award_id 0 (no base award), 94 (Custom Award) and 131, under a dozen names. The
     * printed title still comes from ork_kingdomaward.name, so every spelling renders
     * on its own certificate.
     *
     * "Master Hunter" and "Master Archer" are deliberately absent — they take the
     * custom scroll with every other Master, because no ladder gives its Master tier
     * the ladder's own artwork.
     */
    public const ARCHERY_NAMES = [
        'order of the hunter',
        'order of the archer',
        'order of the marksman',
        'order of the sharpshooter',
        'hunter',
    ];

    public static function ArcheryOrderForName(string $name): ?array
    {
        $key = strtolower(trim($name));

        return in_array($key, self::ARCHERY_NAMES, true) ? (self::Orders()['hunter'] ?? null) : null;
    }

    /**
     * Whether an award with no design of its own may still be printed.
     *
     * Officer positions are excluded: an officer position is a job somebody held, not
     * an honour they were given.
     */
    public static function IsCustomEligible(array $awardRow): bool
    {
        $role = strtolower(trim((string) ($awardRow['OfficerRole'] ?? 'none')));

        return $role === '' || $role === 'none';
    }

    /**
     * THE single resolution point for "what does this award print as".
     *
     * Order of precedence: the award's own design, then archery by name, then the
     * custom scroll, then nothing. Every caller — the reprint list, the bulk list, the
     * Awards tab, the create tool — goes through here, so coverage cannot drift
     * between surfaces.
     */
    public static function OrderForAwardRow(array $awardRow): ?array
    {
        $specific = self::OrderForAwardId((int) ($awardRow['AwardId'] ?? 0));
        if ($specific !== null) {
            return $specific;
        }

        foreach (['KingdomAwardName', 'Name'] as $key) {
            $archery = self::ArcheryOrderForName((string) ($awardRow[$key] ?? ''));
            if ($archery !== null) {
                return $archery;
            }
        }

        return self::IsCustomEligible($awardRow) ? (self::Orders()['custom'] ?? null) : null;
    }
```

Then replace the body of `HasArt()`:

```php
    public static function HasArt(array $awardRow): bool
    {
        return self::OrderForAwardRow($awardRow) !== null;
    }
```

And point `BuildScroll()` at the same resolution, so the widened coverage and the
thing that builds a scroll never disagree. Replace the first statement of
`BuildScroll()`:

```php
        $order = self::OrderForAwardRow($awardRow);
        if ($order === null) {
            throw new InvalidArgumentException(
                'No certificate for award_id ' . (int) ($awardRow['AwardId'] ?? 0)
            );
        }
```

**On the intermediate state:** between this task and Task 3 there is no `custom.tpl`,
so a custom scroll renders the "artwork is missing from this installation" placeholder
that the polish pass added to `Scroll::Render()`. That is the correct behaviour for a
missing partial and it does not 500 — which is exactly why that placeholder exists. Do
not add a temporary guard here to paper over it.

- [ ] **Step 5: Run the tests**

```bash
vendor/bin/phpunit --testsuite unit --filter ScrollCustomEligibility
```

Expected: PASS (7 tests).

- [ ] **Step 6: Fix the phase-1 tests this deliberately changes**

`HasArt()` used to answer "has a specific order"; it now answers "prints as something". Two phase-1 assertions encoded the old meaning and must be updated, not worked around:

In `tests/Unit/ScrollResolutionTest.php`, `testHasArtIsFalseForAwardsWithNoOrder()` — award 1 (Master Rose) and 999999 are now custom-eligible. Replace that test with:

```php
    public function testHasArtIsTrueForAnythingPrintableAndFalseForOfficerPositions(): void
    {
        // A specific order.
        $this->assertTrue(Scroll::HasArt($this->row(['AwardId' => 33])));
        // No design of its own, but printable as a custom scroll.
        $this->assertTrue(Scroll::HasArt($this->row(['AwardId' => 1])));
        // An officer position is not printable at all.
        $this->assertFalse(Scroll::HasArt($this->row(['AwardId' => 189, 'OfficerRole' => 'other-officer'])));
    }
```

In `tests/Unit/ScrolleryPermissionTest.php`, `testPrintableAwardsExcludeAwardsWithNoArt()` and `testMasterAwardsAreNotPrintable()` both assert Masters are unprintable. Masters are now printable as custom scrolls — that is the point of this work. Rewrite them to assert the new rule:

```php
    public function testPrintableAwardsExcludeOnlyOfficerPositions(): void
    {
        $rows = [
            ['AwardId' => 33,  'OfficerRole' => 'none'],           // griffon — its own art
            ['AwardId' => 1,   'OfficerRole' => 'none'],           // Master Rose — custom scroll
            ['AwardId' => 189, 'OfficerRole' => 'other-officer'],  // Guildmaster — not printable
        ];

        $printable = array_values(array_filter($rows, fn ($r) => Scroll::HasArt($r)));

        $this->assertCount(2, $printable);
        $this->assertSame(33, $printable[0]['AwardId']);
        $this->assertSame(1, $printable[1]['AwardId']);
    }

    public function testEveryLadderStillHasItsOwnDesign(): void
    {
        foreach (Award::GetLadderMasterMap() as $ladderId => $meta) {
            $this->assertNotNull(
                Scroll::OrderForAwardId((int) $ladderId),
                "ladder {$ladderId} lost its specific order"
            );
        }
    }
```

Delete the old `testMasterAwardsAreNotPrintable()`.

- [ ] **Step 7: Run the whole suite**

```bash
vendor/bin/phpunit --testsuite unit
```

Expected: PASS. `custom` has no partial until Task 3, so `Scroll::Render()` returns the
missing-artwork placeholder for it rather than throwing — the asset matrix sees a
certificate with no `src` attributes and its "referenced no assets at all" assertion
fires. That failure is expected here and is fixed by Task 3; do not weaken the test to
silence it.

- [ ] **Step 8: Commit**

```bash
php tools/php-cs-fixer/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php --path-mode=intersection \
  system/lib/ork3/class.Scroll.php system/lib/ork3/data/scroll-orders.php \
  tests/Unit/ScrollCustomEligibilityTest.php tests/Unit/ScrollResolutionTest.php tests/Unit/ScrolleryPermissionTest.php
git add system/lib/ork3/class.Scroll.php system/lib/ork3/data/scroll-orders.php \
        tests/Unit/ScrollCustomEligibilityTest.php tests/Unit/ScrollResolutionTest.php tests/Unit/ScrolleryPermissionTest.php
git commit -m "Enhancement: Scrollery — custom-scroll eligibility and archery name resolution"
```

---

### Task 3: The custom certificate

**Files:**
- Create: `orkui/template/revised-frontend/scroll/custom.tpl`
- Modify: `system/lib/ork3/class.Scroll.php` (`BuildScroll` carries the recipe)
- Modify: `orkui/template/revised-frontend/style/scrollery.css`
- Test: covered by the existing `ScrollAssetPathsTest` matrix, extended here.

**Interfaces:**
- Consumes: `Scroll::NormaliseRecipe()`, `Scroll::Recipes()` from Task 1; `Scroll::OrderForAwardRow()` from Task 2.
- Produces: `$s['recipe']` on the scroll array whenever `$s['order'] === 'custom'`.

- [ ] **Step 1: Carry the recipe through `BuildScroll()`**

`BuildScroll()` already resolves through `OrderForAwardRow()` as of Task 2. Add the
recipe to the returned array, after `'resources' => $order['resources'],`:

```php
            // Only the custom scroll composes its own art; every other order's layers
            // are fixed by its partial.
            'recipe'      => $order['id'] === 'custom'
                ? self::NormaliseRecipe(is_array($context['recipe'] ?? null) ? $context['recipe'] : [])
                : [],
```

- [ ] **Step 2: Write `custom.tpl`**

Create `orkui/template/revised-frontend/scroll/custom.tpl`:

```php
<?php
/**
 * The custom scroll — for awards with no design of their own.
 *
 * Composed from the vocabulary in scroll-recipes.php rather than fixed art: a
 * full-page ground, a border (the parametric knotwork or a raster overlay), and a
 * rank numeral. Order symbols are deliberately absent; they are order identity.
 *
 * Document order is z-order, exactly as in every other order partial.
 */
$v      = Scroll::Recipes();
$recipe = $s['recipe'] ?: Scroll::DEFAULT_RECIPE;
$rank   = (int) $s['rank'];

$groundPath = $v['grounds'][$recipe['ground']] ?? $v['grounds']['parchment'];
$borderPath = $v['borders'][$recipe['border']] ?? '';
$rankDir    = $v['rankSets'][$recipe['rankSet']] ?? 'common';
?>
<div class="award-template" data-order="custom" data-rank="<?= $rank ?>" style="position:absolute;top:0;left:0;right:0;bottom:0;">
    <img class="bleed" src="<?= $art ?>/<?= $groundPath ?>" width="2550" height="3300" alt="">

    <?php if ($recipe['border'] === 'knot'): ?>
        <?php scroll_partial('border', ['primary' => $recipe['primary'], 'secondary' => $recipe['secondary']]); ?>
    <?php else: ?>
        <img class="bleed" src="<?= $art ?>/<?= $borderPath ?>" style="mix-blend-mode:multiply;" alt="">
    <?php endif; ?>

    <?php scroll_partial('heraldry', ['s' => $s]); ?>

    <?php /* Guarded on rank > 0 for EVERY set: common, zodiac and flame ship rank_1..10
             only. garber also ships a rank_0, which is deliberately never used here so
             the rule stays one rule. */ ?>
    <?php if ($rank > 0): ?>
        <div class="centre-bottom" style="bottom:85px;">
            <img src="<?= $art ?>/<?= $rankDir ?>/rank_<?= $rank ?>.png" alt="">
        </div>
    <?php endif; ?>

    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
```

- [ ] **Step 3: Add the CSS**

Append to the per-order section of `orkui/template/revised-frontend/style/scrollery.css`:

```css
/* The custom scroll carries no order-specific sentiment, so its signature block uses
   the shared default height. Stated explicitly so it is clear this is a decision and
   not an omission. */
.award-template[data-order="custom"] section.SignAndDate {
    min-height: 350px;
}
```

- [ ] **Step 4: Extend the asset matrix to cover the recipe space**

In `tests/Unit/ScrollAssetPathsTest.php`, `scrollFor()` currently builds a scroll array
by hand. Add a `recipe` key so `custom` renders, and add a dedicated provider so every
ground, border and rank set is stat-ed at least once. Insert into the class:

```php
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
        $scroll = $this->scrollFor('custom', 5, '');
        $scroll['recipe'] = Scroll::NormaliseRecipe([
            'ground'    => $ground,
            'border'    => $border,
            'rankSet'   => $rankSet,
            'primary'   => Scroll::DEFAULT_RECIPE['primary'],
            'secondary' => Scroll::DEFAULT_RECIPE['secondary'],
        ]);

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
```

Also add `'recipe' => Scroll::DEFAULT_RECIPE,` to the array `scrollFor()` returns, so the
order matrix can render `custom` too.

- [ ] **Step 5: Run the tests**

```bash
vendor/bin/phpunit --testsuite unit --filter 'ScrollAssetPaths|ScrollOrders'
```

Expected: PASS. The matrix grows by 11 order cases (custom ranks 0-10) plus 26 recipe
cases.

- [ ] **Step 6: Prove the new cases can fail**

```bash
mv assets/scrolls/smith/bg.jpg assets/scrolls/smith/bg.jpg.hidden
vendor/bin/phpunit --testsuite unit --filter ScrollAssetPaths   # Expected: FAIL on ground/map
mv assets/scrolls/smith/bg.jpg.hidden assets/scrolls/smith/bg.jpg
vendor/bin/phpunit --testsuite unit --filter ScrollAssetPaths   # Expected: PASS
```

A test that cannot fail is not a net. Do not skip this step.

- [ ] **Step 7: Render one by eye**

```bash
vendor/bin/phpunit --testsuite unit    # whole suite green first
```

Then render a custom scroll for a real Knighthood and look at it — the default recipe
and at least two others (a raster border, a non-parchment ground). Confirm the border
and ground do not fight, the text is legible on every ground, and the numeral sits
correctly for a ranked award.

- [ ] **Step 8: Commit**

```bash
php tools/php-cs-fixer/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php --path-mode=intersection \
  system/lib/ork3/class.Scroll.php tests/Unit/ScrollAssetPathsTest.php
git add system/lib/ork3/class.Scroll.php orkui/template/revised-frontend/scroll/custom.tpl \
        orkui/template/revised-frontend/style/scrollery.css tests/Unit/ScrollAssetPathsTest.php
git commit -m "Enhancement: Scrollery — the custom certificate"
```

---

### Task 4: Wire coverage through the model

**Files:**
- Modify: `orkui/model/model.Scrollery.php`
- Test: `tests/Unit/ScrolleryBulkQueryTest.php`

**Interfaces:**
- Consumes: `Scroll::OrderForAwardRow()`, `Scroll::HasArt()` from Task 2.
- Produces: reprint and bulk lists that include custom-eligible awards.

- [ ] **Step 1: Make the bulk query select `officer_role`**

`Scroll::IsCustomEligible()` reads `OfficerRole`, and the bulk query does not select it —
so without this every bulk row would look eligible. In `bulkSqlForTest()`, add
`a.officer_role,` to the select list next to `a.award_id, a.name,`, and add
`'officer_role' => $rs->officer_role,` to the row array in `db_query()`.

Then in `awards_for_bulk()`'s shaping, add:

```php
                'OfficerRole'      => (string) $row['officer_role'],
```

- [ ] **Step 2: Add the regression test**

In `tests/Unit/ScrolleryBulkQueryTest.php`:

```php
    /**
     * Custom-scroll eligibility is decided on officer_role, so the bulk query has to
     * select it — without it every row would look eligible.
     */
    public function testTheBulkQuerySelectsOfficerRole(): void
    {
        $this->assertStringContainsString('a.officer_role', $this->sql('event', '17', 'park', '198'));
    }
```

- [ ] **Step 3: Run the tests**

```bash
vendor/bin/phpunit --testsuite unit --filter ScrolleryBulkQuery
```

Expected: PASS.

- [ ] **Step 4: Verify against real data**

`printable_awards_for_player()` and `awards_for_bulk()` both filter on `Scroll::HasArt()`,
which now includes custom-eligible awards. Confirm the counts move in the right direction
for a real player — more printable rows than before, and no officer positions among them.

- [ ] **Step 5: Commit**

```bash
php tools/php-cs-fixer/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php --path-mode=intersection \
  orkui/model/model.Scrollery.php tests/Unit/ScrolleryBulkQueryTest.php
git add orkui/model/model.Scrollery.php tests/Unit/ScrolleryBulkQueryTest.php
git commit -m "Enhancement: Scrollery — custom scrolls reach the reprint and bulk lists"
```

---

### Task 5: Pronoun resolution and override

**Files:**
- Modify: `orkui/model/model.Scrollery.php`
- Modify: `orkui/controller/controller.ScrolleryAjax.php`
- Modify: `orkui/controller/controller.Scrollery.php`
- Test: `tests/Unit/ScrollPronounTest.php`

**Interfaces:**
- Produces: `Scroll::PossessiveFromFreetext(string $freetext, array $pronounRows): string`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ScrollPronounTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The certificate needs the POSSESSIVE — "with her third".
 *
 * Free text is subject/object ("he/him"), so reading it naively yields "him" and the
 * certificate says "with him third". The leading token is matched against
 * ork_pronoun.subject instead, longest match first so "e/ey" and "ze/zie" resolve
 * before the shorter subjects they start with.
 */
final class ScrollPronounTest extends TestCase
{
    /** Shaped like GetPronounList()['Pronouns']. */
    private function rows(): array
    {
        return [
            1  => ['Subject' => 'e/ey',  'Possessive' => 'eir'],
            2  => ['Subject' => 'he',    'Possessive' => 'his'],
            4  => ['Subject' => 'she',   'Possessive' => 'her'],
            5  => ['Subject' => 'they',  'Possessive' => 'their'],
            8  => ['Subject' => 'ze/zie', 'Possessive' => 'hir'],
        ];
    }

    public function testHeHimResolvesToHisNotHim(): void
    {
        $this->assertSame('his', Scroll::PossessiveFromFreetext('he/him', $this->rows()));
    }

    public function testCommonFormsResolve(): void
    {
        $this->assertSame('her',   Scroll::PossessiveFromFreetext('she/her', $this->rows()));
        $this->assertSame('their', Scroll::PossessiveFromFreetext('they/them', $this->rows()));
        $this->assertSame('her',   Scroll::PossessiveFromFreetext('she/they', $this->rows()));
    }

    /** "e/ey/em" must match the subject "e/ey", not the bare "e" prefix of it. */
    public function testMultiTokenSubjectsMatchLongestFirst(): void
    {
        $this->assertSame('eir', Scroll::PossessiveFromFreetext('e/ey/em', $this->rows()));
        $this->assertSame('hir', Scroll::PossessiveFromFreetext('ze/zie/hir', $this->rows()));
    }

    public function testUnknownOrEmptyFreetextYieldsNothing(): void
    {
        $this->assertSame('', Scroll::PossessiveFromFreetext('', $this->rows()));
        $this->assertSame('', Scroll::PossessiveFromFreetext('xyzzy/plugh', $this->rows()));
    }

    public function testMatchingIsCaseInsensitive(): void
    {
        $this->assertSame('his', Scroll::PossessiveFromFreetext('He/Him', $this->rows()));
    }
}
```

- [ ] **Step 2: Run it to make sure it fails**

```bash
vendor/bin/phpunit --testsuite unit --filter ScrollPronoun
```

Expected: FAIL — `Call to undefined method Scroll::PossessiveFromFreetext()`.

- [ ] **Step 3: Implement it in `class.Scroll.php`**

```php
    /**
     * Possessive from a free-text pronoun string.
     *
     * Free text is subject/object — "he/him", "she/her", "they/them" — and the
     * certificate needs the POSSESSIVE. Reading the second token gives "him", so the
     * certificate would say "with him third". Match the LEADING token against the
     * known subjects instead.
     *
     * Longest subject first, because "e/ey" and "ze/zie" are themselves slash-joined
     * and would otherwise lose to the bare "e" and "ze" they begin with.
     *
     * @param array<int, array{Subject:string, Possessive:string}> $pronounRows
     */
    public static function PossessiveFromFreetext(string $freetext, array $pronounRows): string
    {
        $text = strtolower(trim($freetext));
        if ($text === '') {
            return '';
        }

        $subjects = [];
        foreach ($pronounRows as $row) {
            $subject = strtolower(trim((string) ($row['Subject'] ?? '')));
            if ($subject !== '') {
                $subjects[$subject] = (string) ($row['Possessive'] ?? '');
            }
        }
        uksort($subjects, static fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($subjects as $subject => $possessive) {
            if ($text === $subject || str_starts_with($text, $subject . '/')) {
                return $possessive;
            }
        }

        return '';
    }
```

- [ ] **Step 4: Run the tests**

```bash
vendor/bin/phpunit --testsuite unit --filter ScrollPronoun
```

Expected: PASS (5 tests).

- [ ] **Step 5: Use it in the model, and accept the override**

In `Model_Scrollery::possessive_pronoun()`, after the custom-JSON branch and before the
`return 'their';`, add the free-text step:

```php
        // pronoun_freetext is populated by another branch; read it only if the key is
        // actually present, so this works either side of that merge.
        $freetext = trim((string) ($player['PronounFreetext'] ?? ''));
        if ($freetext !== '') {
            $list       = Ork3::$Lib->pronoun->GetPronounList();
            $possessive = Scroll::PossessiveFromFreetext($freetext, $list['Pronouns'] ?? []);
            if ($possessive !== '') {
                return $possessive;
            }
        }
```

In `Controller_ScrolleryAjax::requested_pronoun()`, let an explicit override win before
the player lookup:

```php
        // The officer's correction wins over anything inferred. Per-print only —
        // nothing is written back to the profile.
        $override = trim((string) ($_POST['pronounOverride'] ?? ''));
        if ($override !== '') {
            return $override;
        }
```

Add the same three lines to `Controller_Scrollery::compose()`, reading
`$_GET['pronounOverride']`.

- [ ] **Step 6: Run the whole suite and commit**

```bash
vendor/bin/phpunit --testsuite unit
php tools/php-cs-fixer/php-cs-fixer.phar fix --config=.php-cs-fixer.dist.php --path-mode=intersection \
  system/lib/ork3/class.Scroll.php orkui/model/model.Scrollery.php \
  orkui/controller/controller.ScrolleryAjax.php orkui/controller/controller.Scrollery.php \
  tests/Unit/ScrollPronounTest.php
git add system/lib/ork3/class.Scroll.php orkui/model/model.Scrollery.php \
        orkui/controller/controller.ScrolleryAjax.php orkui/controller/controller.Scrollery.php \
        tests/Unit/ScrollPronounTest.php
git commit -m "Enhancement: Scrollery — possessive pronouns from free text, and an officer override"
```

---

### Task 6: The composer UI

**Files:**
- Modify: `orkui/template/revised-frontend/Scrollery_create.tpl`
- Modify: `orkui/template/revised-frontend/script/scrollery.js`
- Modify: `orkui/model/model.Scrollery.php` (`printable_award_choices` includes custom-eligible awards)

**Interfaces:**
- Consumes: `Scroll::Recipes()`, `Scroll::DEFAULT_RECIPE`, the Ajax preview from Task 5.

- [ ] **Step 1: Offer every printable award in the picker**

`printable_award_choices()` currently keeps only rows whose `AwardId` resolves via
`OrderForAwardId()`. Change it to use `Scroll::OrderForAwardRow($row)` so titles,
peerages and archery appear too, and carry the resolved order id in each choice so the
UI knows when to show the recipe controls:

```php
            $order = Scroll::OrderForAwardRow([
                'AwardId'          => (int) ($row['AwardId'] ?? $row['award_id'] ?? 0),
                'KingdomAwardName' => (string) ($row['KingdomAwardName'] ?? $row['kingdom_awardname'] ?? ''),
                'OfficerRole'      => (string) ($row['OfficerRole'] ?? $row['officer_role'] ?? 'none'),
            ]);
            if ($order === null) {
                continue;
            }
```

- [ ] **Step 2: Add the controls to `Scrollery_create.tpl`**

Inside the controls card, after the variant field, add a block shown only when the
selected award resolves to `custom`. Populate the selects from `Scroll::Recipes()`,
which the controller must pass as `$this->data['Recipes']`:

```php
            <div id="sc-recipe" style="display:none">
                <div class="sc-row">
                    <div class="sc-field">
                        <label for="sc-ground">Ground</label>
                        <select id="sc-ground">
                            <?php foreach ($Recipes['grounds'] as $key => $path): ?>
                                <option value="<?= scroll_e($key) ?>"><?= scroll_e(ucfirst($key)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sc-field">
                        <label for="sc-border">Border</label>
                        <select id="sc-border">
                            <?php foreach ($Recipes['borders'] as $key => $path): ?>
                                <option value="<?= scroll_e($key) ?>"><?= scroll_e(ucfirst($key)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="sc-row">
                    <div class="sc-field">
                        <label for="sc-palette">Border colours</label>
                        <select id="sc-palette">
                            <?php foreach ($Recipes['palette'] as $name => $pair): ?>
                                <option value="<?= scroll_e($pair[0]) ?>|<?= scroll_e($pair[1]) ?>"><?= scroll_e($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sc-field">
                        <label for="sc-rankset">Rank numerals</label>
                        <select id="sc-rankset">
                            <?php foreach ($Recipes['rankSets'] as $key => $dir): ?>
                                <option value="<?= scroll_e($key) ?>"><?= scroll_e(ucfirst($key)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="sc-field">
                <label for="sc-pronoun">Pronoun (possessive)</label>
                <select id="sc-pronoun">
                    <option value="">From their profile</option>
                    <?php foreach ($Pronouns as $p): ?>
                        <option value="<?= scroll_e($p) ?>"><?= scroll_e($p) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
```

The controller supplies these in `create()`:

```php
        $this->data['Recipes']  = Scroll::Recipes();
        $this->data['Pronouns'] = array_values(array_unique(array_filter(array_map(
            static fn (array $p): string => (string) ($p['Possessive'] ?? ''),
            Ork3::$Lib->pronoun->GetPronounList()['Pronouns'] ?? []
        ))));
```

View extracts `$this->data` keys verbatim, so the template reads `$Recipes` and
`$Pronouns` — capitalised, matching the key names.

- [ ] **Step 3: Drive them from `scrollery.js`**

The recipe and pronoun controls change which art is drawn, so they re-fetch like order,
rank and variant. Add to the wiring block:

```js
    var recipeEl  = $('sc-recipe'),
        groundEl  = $('sc-ground'),
        borderEl  = $('sc-border'),
        paletteEl = $('sc-palette'),
        rankSetEl = $('sc-rankset'),
        pronounEl = $('sc-pronoun');

    // The recipe controls only mean anything for the custom scroll.
    function syncRecipeVisibility() {
        var opt = selected();
        recipeEl.style.display = (opt && opt.dataset.order === 'custom') ? '' : 'none';
    }

    [groundEl, borderEl, paletteEl, rankSetEl, pronounEl].forEach(function (el) {
        el.addEventListener('change', reload);
    });
```

In `reload()` and the print handler, append the recipe and override:

```js
        var pair = (paletteEl.value || '|').split('|');
        body.set('ground',          groundEl.value);
        body.set('border',          borderEl.value);
        body.set('primary',         pair[0]);
        body.set('secondary',       pair[1]);
        body.set('rankSet',         rankSetEl.value);
        body.set('pronounOverride', pronounEl.value);
```

Call `syncRecipeVisibility()` inside the award `change` handler and once at startup.

- [ ] **Step 4: Accept them in the two controllers**

In `Controller_ScrolleryAjax::preview()` and `Controller_Scrollery::compose()`, pass the
recipe into the build context:

```php
                'recipe' => [
                    'ground'    => (string) ($_POST['ground'] ?? ''),
                    'border'    => (string) ($_POST['border'] ?? ''),
                    'primary'   => (string) ($_POST['primary'] ?? ''),
                    'secondary' => (string) ($_POST['secondary'] ?? ''),
                    'rankSet'   => (string) ($_POST['rankSet'] ?? ''),
                ],
```

(`$_GET` in `compose()`.) `NormaliseRecipe()` validates every value, so nothing here
needs its own sanitising — but do NOT skip it: the values reach an `<img src>` and a
style attribute.

- [ ] **Step 5: Verify in the browser**

Log in as an officer, open `Scrollery/create/park/<id>`, and confirm:
- picking a ladder award hides the recipe controls; picking a Knighthood shows them
- changing ground, border, colours and numeral set each re-render the preview
- the pronoun select overrides what the profile inferred
- Print opens a certificate matching the preview

- [ ] **Step 6: Run the suite and commit**

```bash
vendor/bin/phpunit --testsuite unit
git add orkui/template/revised-frontend/Scrollery_create.tpl \
        orkui/template/revised-frontend/script/scrollery.js \
        orkui/model/model.Scrollery.php orkui/controller/controller.Scrollery.php \
        orkui/controller/controller.ScrolleryAjax.php
git commit -m "Enhancement: Scrollery — the custom scroll composer UI"
```

---

## Done when

- [ ] `vendor/bin/phpunit --testsuite unit` passes, including the recipe matrix
- [ ] A Knighthood, a Master, a title and Walker in the Middle all print
- [ ] An officer position offers no Print control anywhere
- [ ] All five archery spellings print the archery certificate; Master Hunter and Master Archer print custom scrolls
- [ ] A free-text pronoun of `he/him` prints "with **his** third", never "with him third"
- [ ] The composer's controls appear only for custom scrolls, and every combination renders with no missing art
- [ ] `Scrollery/mine` and a bulk run print uncovered awards using the default recipe

## Deferred — not this plan

- Zodiac's month and ranks 11-12 — permanently deferred to its own branch
- Saved recipes per kingdom/award — decided against; `DEFAULT_RECIPE` is the seam if it returns
- Per-kingdom theming, per-kingdom certificate names — closed
- Server-side PDF — parked
- The eight small deferred items (navigation, own-profile link, print-from-grant, bulk event picker, WnAjax exits, `PrintUrl` out of the model, woff2 MIME, hunter visual check) — ordinary bounded work, tracked in `docs/scrollery-deferred.md`
