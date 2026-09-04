# Scrollery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Port the Arcanyst scroll generator into the ORK as a `Scrollery` module that prints award certificates from ORK award records.

**Architecture:** The Aurelia application is discarded — its sources are lost and its bundles cannot be rebuilt. Each of Arcanyst's 19 order views is transliterated into a PHP `.tpl` partial under `orkui/template/revised-frontend/scroll/`, rendered by a `Scrollery` controller into a bare print route, and driven by ORK award data. Certificates remain HTML/CSS layer stacks composed with `mix-blend-mode` and printed with `window.print()`.

**Tech Stack:** PHP 8.2, ORK3's own MVC (`controller.X.php` → `$this->template`), `.tpl` templates, `revised.css`/`revised.js` design language, PHPUnit 11, vanilla JS.

**Spec:** `docs/superpowers/specs/2026-09-03-scrollery-design.md` — read it first. This plan argues from it.

**Source material:** `/Users/averykrouse/arcanyst-clone/arc-scroll` (Arcanyst 2.1.3). Add it as a second working directory. Paths below written as `arc-scroll/...` mean that tree; unprefixed paths mean the ORK repo.

## Global Constraints

- **Branch:** `scrollery`, based on `master`. Already created; the spec commit is on it.
- **PHP >= 8.2.** `declare(strict_types=1)` in every new `.php` file under `tests/` and `system/lib/ork3/`.
- **`system/lib/ork3/*.php` is auto-loaded AND auto-instantiated.** `startup.php:47-64` scans `DIR_ORK3`, `require_once`s every `.php` file, then does `$LIB->{strtolower($className)} = new $className()`. So `class.Scroll.php` MUST declare `class Scroll extends Ork3` with a **no-argument constructor**, or every page in the ORK breaks at startup. `scandir` is flat, so `system/lib/ork3/data/` is not scanned — data files there are safe.
- **Do NOT add new constants to `config.dist.php`.** Production `config.php` is gitignored and hand-maintained; a constant added to the dist file would be undefined in production. Derive asset paths from the existing `HTTP_ASSETS` and `DIR_ASSETS` (`config.dist.php:16,46`).
- **Preserve every duplicated layer.** Repeated identical `<img>`/heraldry layers deepen `multiply`/`screen` compositing and are deliberate. Never "deduplicate" them.
- **Per-order `<style>` blocks MUST become `[data-order]`-scoped rules in `scrollery.css`.** In Arcanyst those blocks (`section.SignAndDate { min-height:650px }`, warrior's per-rank overrides, owl's per-variant overrides) were scoped by Aurelia's shadow root. There is no shadow root here. Inlined as-is they are **global**, so on a bulk page of mixed orders the last certificate's rules would win for all of them. Every order partial therefore renders `<div class="award-template" data-order="..." data-rank="..." data-variant="...">` and its rules live in `scrollery.css` keyed on those attributes.
- **Document order is z-order.** `.award-template > *` is `position:absolute` full-bleed. Keep sibling order exactly as the source view has it.
- **Art lives at `assets/scrolls/`**, referenced as `HTTP_ASSETS . 'scrolls/'`.
- **Rank 0 is reachable for 18 of 19 orders** (only `warrior` starts at 1). Any unguarded `${rank}` path needs a rank-0 file.
- **Zodiac is clamped to rank 10** even though ORK's ladder map says `MaxRank => 12`. Month handling is out of scope.
- Commit after every task. Conventional-ish messages matching repo style (`Enhancement:`, `Fix:`, `Spec:`).

## Prerequisites

The test suite's bootstrap calls `startup.php`, which constructs a DB connection unconditionally — **so even pure unit tests need the test database running.**

```bash
docker-compose -f docker-compose.php8.yml up -d
# app  -> http://localhost:19080/orkui/
# db   -> localhost:19306 (ork)
# test -> localhost:19307 (ork_test)
```

Verify before starting:

```bash
vendor/bin/phpunit --testsuite unit --filter AwardCatalogueMaps
# Expected: OK (5 tests)
```

If that errors with `PDOException ... Connection refused`, the test DB is not up.

## File Structure

| File | Responsibility |
|---|---|
| `system/lib/ork3/data/scroll-orders.php` | The 19 orders as data: art key, ranks, variants, colours, resources, flavour. Returns an array; no side effects. |
| `system/lib/ork3/class.Scroll.php` | `Scroll extends Ork3`. Order lookup by base `award_id`, defaults, title precedence, rank guards, partial rendering. No DB access. |
| `orkui/model/model.Scrollery.php` | Award rows → scroll data arrays. Reuses `Player::AwardsForPlayer()`. Adds the bulk-by-event query. |
| `orkui/controller/controller.Scrollery.php` | Routes `index`, `mine`, `create`, `bulk`, `render`. Owns every permission check. |
| `orkui/controller/controller.ScrolleryAjax.php` | Preview re-render on order/rank/variant change. |
| `orkui/template/revised-frontend/Scrollery_*.tpl` | The four chrome screens plus the bare render surface. |
| `orkui/template/revised-frontend/scroll/_*.tpl` | Four shared partials: parchment, border, heraldry, text. |
| `orkui/template/revised-frontend/scroll/<order>.tpl` | 19 order partials. One responsibility each: stack the layers for that order. |
| `orkui/template/revised-frontend/style/scrollery.css` | Certificate CSS + print rules + chrome. |
| `orkui/template/revised-frontend/script/scrollery.js` | Live overrides, print trigger. |
| `assets/scrolls/` | ~60 MB of art. |
| `tests/Unit/Scroll*Test.php` | Order data, title precedence, rank guards, asset paths. |

---

### Task 1: Import art, fonts and attribution

No tests — this task is verified by file counts and by the browser loading an image.

**Files:**
- Create: `assets/scrolls/**` (copied)
- Create: `orkui/template/revised-frontend/style/fonts/BlackChancery.{woff2,woff,ttf}`
- Create: `orkui/template/revised-frontend/style/fonts/AveriaSerifLibre-{Regular,Italic,Bold}.woff2`
- Modify: `NOTICE`

- [x] **Step 1: Copy the order art**

```bash
mkdir -p assets/scrolls
cp -R /Users/averykrouse/arcanyst-clone/arc-scroll/resources/ash/. assets/scrolls/
cp -R /Users/averykrouse/arcanyst-clone/arc-scroll/resources/icons assets/scrolls/icons
```

- [x] **Step 2: Remove the four empty alias directories**

`bowyer/`, `marksman/` and `sharpshooter/` are empty in the source — Arcanyst's four archery order ids all rendered through `hunter.html`, which hardcodes the `hunter` art path. They are not missing assets.

```bash
rmdir assets/scrolls/bowyer assets/scrolls/marksman assets/scrolls/sharpshooter
ls assets/scrolls
# Expected: battle common crown dragon flame garber griffon hunter hydra icons
#           jovious lion mask owl rose smith warrior zodiac
```

- [x] **Step 3: Rename the three webpack content-hashed assets**

Arcanyst spliced these into views as opaque hashes. They are real art and need real names.

```bash
cp /Users/averykrouse/arcanyst-clone/arc-scroll/9acb30bd61cdcd5ae4a9.jpg assets/scrolls/common/parchment.jpg
mkdir -p assets/scrolls/battle/arcane
cp /Users/averykrouse/arcanyst-clone/arc-scroll/ad39b4816723cb7f7f02.jpg assets/scrolls/battle/arcane/wash.jpg
cp /Users/averykrouse/arcanyst-clone/arc-scroll/01b3c71bc126cfd86c49.png assets/scrolls/battle/arcane/glow.png
ls -l assets/scrolls/common/parchment.jpg assets/scrolls/battle/arcane/wash.jpg assets/scrolls/battle/arcane/glow.png
# Expected sizes: 1690225, 153313, 66391
```

`63c5ea5a789bf987508d.jpg` is the Arcanyst author's photo from the About page. Do not copy it.

- [x] **Step 4: Vendor the fonts**

```bash
mkdir -p orkui/template/revised-frontend/style/fonts
cp /Users/averykrouse/arcanyst-clone/arc-scroll/styles/fonts/BlackChancery.* orkui/template/revised-frontend/style/fonts/
```

Averia Serif Libre is not vendored in arc-scroll — it arrives through a Google Fonts `@import`. Download the four faces the text layer uses (400 normal, 400 italic, 700 normal, 700 italic) as woff2 from Google Fonts and place them alongside BlackChancery. Verify:

```bash
ls orkui/template/revised-frontend/style/fonts/
# Expected: AveriaSerifLibre-Bold.woff2 AveriaSerifLibre-BoldItalic.woff2
#           AveriaSerifLibre-Italic.woff2 AveriaSerifLibre-Regular.woff2
#           BlackChancery.ttf BlackChancery.woff BlackChancery.woff2
```

- [x] **Step 5: Record attribution in NOTICE**

Append to `NOTICE`:

```
assets/scrolls/**
orkui/template/revised-frontend/scroll/**
  Award certificate artwork, layer designs and flavour text originate in Arcanyst
  (https://github.com/ValoryFox/Arcanyst), by Valory Fox, used with the author's
  permission to continue the work. Flavour text credited in Arcanyst to Kisa
  (Shelly Alexandra).
  Modifications: art re-pathed under assets/scrolls/; three webpack
  content-hashed files renamed (parchment.jpg, battle/arcane/wash.jpg,
  battle/arcane/glow.png); Aurelia views transliterated to PHP partials.

orkui/template/revised-frontend/style/fonts/BlackChancery.*
  BlackChancery display face, as vendored by Arcanyst.

orkui/template/revised-frontend/style/fonts/AveriaSerifLibre-*.woff2
  Averia Serif Libre by Dan Sayers. License: SIL Open Font License 1.1.
  https://fonts.google.com/specimen/Averia+Serif+Libre
```

- [x] **Step 6: Verify the count and commit**

```bash
find assets/scrolls -type f | wc -l     # Expected: 200+
du -sh assets/scrolls                   # Expected: ~62M
git add assets/scrolls orkui/template/revised-frontend/style/fonts NOTICE
git commit -m "Enhancement: Scrollery — import certificate artwork, fonts and attribution"
```

---

### Task 2: Order definitions

**Files:**
- Create: `system/lib/ork3/data/scroll-orders.php`
- Create: `system/lib/ork3/class.Scroll.php`
- Test: `tests/Unit/ScrollOrdersTest.php`

**Interfaces:**
- Produces:
  - `Scroll::Orders(): array` — all orders keyed by order id, defaults already applied.
  - `Scroll::OrderForAwardId(int $awardId): ?array` — the order, or `null` when no art exists.
  - Each order array: `['id' => string, 'award_id' => int, 'ranks' => ['from' => int, 'to' => int], 'variants' => string[], 'primary' => ?string, 'secondary' => ?string, 'resources' => array, 'flavour' => array<string,string>]`.

- [x] **Step 1: Write the failing test**

Create `tests/Unit/ScrollOrdersTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScrollOrdersTest extends TestCase
{
    public function testEveryOrderHasArtDirectoryAndAwardId(): void
    {
        $orders = Scroll::Orders();
        $this->assertCount(16, $orders);

        foreach ($orders as $id => $order) {
            $this->assertSame($id, $order['id']);
            $this->assertGreaterThan(0, $order['award_id'], "{$id} has no award_id");
            $this->assertDirectoryExists(DIR_ASSETS . 'scrolls/' . $id, "{$id} art missing");
        }
    }

    public function testRanksDefaultToZeroThroughTenExceptWarrior(): void
    {
        $orders = Scroll::Orders();
        $this->assertSame(['from' => 0, 'to' => 10], $orders['griffon']['ranks']);
        $this->assertSame(['from' => 1, 'to' => 10], $orders['warrior']['ranks']);
        $this->assertSame(['from' => 0, 'to' => 10], $orders['zodiac']['ranks']);
    }

    public function testResourcesDefaultToFlourishesAndOverridesMergeOverThem(): void
    {
        $orders = Scroll::Orders();

        $this->assertSame(
            'scrolls/common/text/squiggly-top.svg',
            $orders['griffon']['resources']['flourish_top']
        );
        $this->assertSame(
            'scrolls/common/text/writing-line.svg',
            $orders['griffon']['resources']['writing_line']
        );

        // jovious and rose override the flourishes only — writing_line is inherited.
        $this->assertSame(
            'scrolls/jovious/ribbon-top.png',
            $orders['jovious']['resources']['flourish_top']
        );
        $this->assertSame(
            'scrolls/common/text/writing-line.svg',
            $orders['jovious']['resources']['writing_line']
        );
    }

    public function testVariantOrdersDeclareTheirVariants(): void
    {
        $orders = Scroll::Orders();
        $this->assertSame(['arcane', 'martial'], $orders['battle']['variants']);
        $this->assertSame(['cyanotype', 'parchment', 'transparency'], $orders['owl']['variants']);
        $this->assertSame([], $orders['griffon']['variants']);
    }

    public function testOrderLookupByAwardIdCoversTheLaddersAndReturnsNullOtherwise(): void
    {
        $this->assertSame('rose', Scroll::OrderForAwardId(21)['id']);
        $this->assertSame('griffon', Scroll::OrderForAwardId(33)['id']);
        $this->assertSame('crown', Scroll::OrderForAwardId(239)['id']);
        $this->assertSame('battle', Scroll::OrderForAwardId(243)['id']);
        $this->assertSame('flame', Scroll::OrderForAwardId(34)['id']);

        // Master Rose has no certificate art.
        $this->assertNull(Scroll::OrderForAwardId(1));
        $this->assertNull(Scroll::OrderForAwardId(999999));
    }

    public function testEveryLadderInTheAwardCatalogueHasArt(): void
    {
        foreach (array_keys(Award::GetLadderMasterMap()) as $awardId) {
            $this->assertNotNull(
                Scroll::OrderForAwardId((int) $awardId),
                "ladder award {$awardId} has no scroll order"
            );
        }
    }

    public function testFlavourTextCoversEveryRankInTheOrderRange(): void
    {
        foreach (Scroll::Orders() as $id => $order) {
            for ($r = $order['ranks']['from']; $r <= $order['ranks']['to']; $r++) {
                $this->assertArrayHasKey((string) $r, $order['flavour'], "{$id} has no rank-{$r} flavour");
            }
        }

        // Rank 0 is the generic, unranked certificate. Every order but warrior has one.
        $this->assertArrayHasKey('0', Scroll::Orders()['griffon']['flavour']);
        $this->assertArrayNotHasKey('0', Scroll::Orders()['warrior']['flavour']);
    }
}
```

- [x] **Step 2: Run it to make sure it fails**

```bash
vendor/bin/phpunit --testsuite unit --filter ScrollOrders
```

Expected: FAIL — `Error: Class "Scroll" not found`.

- [x] **Step 3: Generate the order data**

`arc-scroll/reference/award-definitions.json` is the source. Convert it with this one-off script, then delete the script — the PHP file is what ships.

```bash
php -r '
$src = json_decode(file_get_contents("/Users/averykrouse/arcanyst-clone/arc-scroll/reference/award-definitions.json"), true);
$skip = ["bowyer", "marksman", "sharpshooter"];   // aliases of hunter; ORK gets the name from kingdomaward
$out = [];
foreach ($src as $id => $d) {
    if (in_array($id, $skip, true)) { continue; }
    $out[$id] = [
        "id"        => $id,
        "award_id"  => $d["ork_id"],
        "ranks"     => $d["ranks"]    ?? null,
        "variants"  => $d["variants"] ?? [],
        "primary"   => $d["primary"]  ?? null,
        "secondary" => $d["secondary"]?? null,
        "resources" => $d["resources"]?? [],
        "flavour"   => $d["flavour"],
    ];
}
file_put_contents("/tmp/scroll-orders-body.php", "<?php\n\nreturn " . var_export($out, true) . ";\n");
' && head -5 /tmp/scroll-orders-body.php
```

Move it into place as `system/lib/ork3/data/scroll-orders.php`, then hand-edit three things:

1. `hunter`'s `award_id` is `6969` — Arcanyst's invention, not a real ORK award id. Leave it as `6969` with a comment pointing at the "Deferred" section at the end of this plan. `Scroll::OrderForAwardId()` will simply never match it until the real id is found, which is the correct failure: no button, rather than the wrong scroll.
2. Rewrite every `resources` path from `./resources/ash/...` to `scrolls/...`.
3. Confirm `warrior` is the only order with a non-null `ranks`.

- [x] **Step 4: Write `class.Scroll.php`**

```php
<?php

declare(strict_types=1);

/**
 * Award certificate ("scroll") definitions and rendering.
 *
 * Artwork and layer designs originate in Arcanyst by Valory Fox — see NOTICE.
 *
 * NOTE: startup.php scans system/lib/ork3 and instantiates every class it finds
 * as Ork3::$Lib->scroll. The constructor must therefore take no arguments.
 */
class Scroll extends Ork3
{
    public const DEFAULT_RANKS = ['from' => 0, 'to' => 10];

    public const DEFAULT_RESOURCES = [
        'flourish_top'    => 'scrolls/common/text/squiggly-top.svg',
        'flourish_right'  => 'scrolls/common/text/squiggly-right.svg',
        'flourish_bottom' => 'scrolls/common/text/squiggly-bottom.svg',
        'writing_line'    => 'scrolls/common/text/writing-line.svg',
    ];

    /** @var array<string, array>|null */
    private static ?array $orders = null;

    /** @return array<string, array> */
    public static function Orders(): array
    {
        if (self::$orders !== null) {
            return self::$orders;
        }

        $raw = require DIR_ORK3 . 'data/scroll-orders.php';
        $orders = [];
        foreach ($raw as $id => $order) {
            $order['id']        = $id;
            $order['award_id']  = (int) $order['award_id'];
            $order['ranks']     = $order['ranks'] ?? self::DEFAULT_RANKS;
            $order['variants']  = $order['variants'] ?? [];
            // Overrides are partial: merge over the defaults, never replace them.
            $order['resources'] = array_merge(self::DEFAULT_RESOURCES, $order['resources'] ?? []);
            $orders[$id] = $order;
        }

        return self::$orders = $orders;
    }

    public static function OrderForAwardId(int $awardId): ?array
    {
        foreach (self::Orders() as $order) {
            if ($order['award_id'] === $awardId) {
                return $order;
            }
        }
        return null;
    }
}
```

- [x] **Step 5: Run the tests**

```bash
vendor/bin/phpunit --testsuite unit --filter ScrollOrders
```

Expected: PASS (7 tests).

- [x] **Step 6: Confirm the ORK still boots**

`class.Scroll.php` is instantiated at startup by every page load. A constructor error here breaks the whole site, so check a real page:

```bash
curl -s -o /dev/null -w '%{http_code}\n' 'http://localhost:19080/orkui/index.php?Route=Login/login'
# Expected: 200
vendor/bin/phpunit --testsuite unit
# Expected: no new failures
```

- [x] **Step 7: Commit**

```bash
git add system/lib/ork3/class.Scroll.php system/lib/ork3/data/scroll-orders.php tests/Unit/ScrollOrdersTest.php
git commit -m "Enhancement: Scrollery — order definitions and Scroll library class"
```

---

### Task 3: Title resolution and rank guards

**Files:**
- Modify: `system/lib/ork3/class.Scroll.php`
- Test: `tests/Unit/ScrollResolutionTest.php`

**Interfaces:**
- Consumes: `Scroll::Orders()`, `Scroll::OrderForAwardId()` from Task 2.
- Produces:
  - `Scroll::ResolveTitle(array $awardRow): string`
  - `Scroll::ClampRank(array $order, int $rank): int`
  - `Scroll::HasArt(array $awardRow): bool`

- [x] **Step 1: Write the failing test**

> **Superseded during execution.** The title-precedence cases below were written for the
> original `CustomAwardName`-first order and do NOT match what shipped — see the spec's
> "Title precedence" note. The shipped file asserts that `KingdomAwardName` wins over
> `CustomAwardName`, and replaces the `HasArt(6969)` case (which contradicted Task 2's
> data, where `hunter` really is keyed 6969) with the three real archery base ids.
> `tests/Unit/ScrollResolutionTest.php` is the running code; read it, not this block.

Create `tests/Unit/ScrollResolutionTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScrollResolutionTest extends TestCase
{
    /** A row shaped like Player::AwardsForPlayer() output. */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'AwardId'          => 33,
            'Rank'             => 5,
            'Name'             => 'Order of the Griffin',
            'KingdomAwardName' => 'Order of the Griffin',
            'CustomAwardName'  => '',
            'AliasAwardName'   => '',
        ], $overrides);
    }

    public function testCustomNameWinsOverEverything(): void
    {
        $row = $this->row([
            'CustomAwardName'  => 'The Gilded Feather',
            'AliasAwardName'   => 'Order of the Gryphon',
            'KingdomAwardName' => 'Order of the Griffin',
        ]);
        $this->assertSame('The Gilded Feather', Scroll::ResolveTitle($row));
    }

    public function testAliasNameWinsOverKingdomName(): void
    {
        $row = $this->row([
            'AliasAwardName'   => 'Order of the Gryphon',
            'KingdomAwardName' => 'Order of the Griffin',
        ]);
        $this->assertSame('Order of the Gryphon', Scroll::ResolveTitle($row));
    }

    public function testKingdomNameWinsOverBaseName(): void
    {
        $row = $this->row([
            'KingdomAwardName' => 'Order of the Marksman',
            'Name'             => 'Order of the Hunter',
        ]);
        $this->assertSame('Order of the Marksman', Scroll::ResolveTitle($row));
    }

    public function testBaseNameIsTheLastResort(): void
    {
        $row = $this->row(['KingdomAwardName' => '', 'Name' => 'Order of the Griffin']);
        $this->assertSame('Order of the Griffin', Scroll::ResolveTitle($row));
    }

    public function testBlankAndWhitespaceOnlyNamesAreSkipped(): void
    {
        $row = $this->row([
            'CustomAwardName'  => '   ',
            'AliasAwardName'   => '',
            'KingdomAwardName' => 'Order of the Griffin',
        ]);
        $this->assertSame('Order of the Griffin', Scroll::ResolveTitle($row));
    }

    public function testRankClampsToTheOrderRange(): void
    {
        $griffon = Scroll::Orders()['griffon'];
        $warrior = Scroll::Orders()['warrior'];

        $this->assertSame(0, Scroll::ClampRank($griffon, 0));
        $this->assertSame(10, Scroll::ClampRank($griffon, 10));
        $this->assertSame(10, Scroll::ClampRank($griffon, 11));
        $this->assertSame(0, Scroll::ClampRank($griffon, -3));

        // warrior has no rank-0 art
        $this->assertSame(1, Scroll::ClampRank($warrior, 0));
    }

    public function testZodiacClampsAtTenDespiteTheCatalogueAllowingTwelve(): void
    {
        $this->assertSame(12, Award::GetLadderMasterMap()[30]['MaxRank']);
        $zodiac = Scroll::Orders()['zodiac'];
        $this->assertSame(10, Scroll::ClampRank($zodiac, 12));
    }

    public function testHasArtIsFalseForAwardsWithNoOrder(): void
    {
        $this->assertTrue(Scroll::HasArt($this->row(['AwardId' => 33])));
        $this->assertFalse(Scroll::HasArt($this->row(['AwardId' => 1])));    // Master Rose
        $this->assertFalse(Scroll::HasArt($this->row(['AwardId' => 6969]))); // archery, id unknown
    }
}
```

- [x] **Step 2: Run it to make sure it fails**

```bash
vendor/bin/phpunit --testsuite unit --filter ScrollResolution
```

Expected: FAIL — `Call to undefined method Scroll::ResolveTitle()`.

- [x] **Step 3: Implement the three methods**

Add to `class.Scroll.php`:

```php
    /**
     * Which of the ORK's four names for an award appears on the certificate.
     *
     * Arcanyst had no such concept — it computed "Order of the " . ucfirst($orderId),
     * which is why one archery award had to become four order ids. Here the word
     * after "Order of the" is data.
     *
     * REVISED during Task 3 against real award data: the kingdom's own name leads,
     * not custom_name. See the spec's "Title precedence" note.
     */
    public static function ResolveTitle(array $awardRow): string
    {
        foreach (['KingdomAwardName', 'Name', 'AliasAwardName', 'CustomAwardName'] as $key) {
            $name = trim((string) ($awardRow[$key] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
        return '';
    }

    public static function ClampRank(array $order, int $rank): int
    {
        return max($order['ranks']['from'], min($order['ranks']['to'], $rank));
    }

    public static function HasArt(array $awardRow): bool
    {
        return self::OrderForAwardId((int) ($awardRow['AwardId'] ?? 0)) !== null;
    }
```

- [x] **Step 4: Run the tests**

```bash
vendor/bin/phpunit --testsuite unit --filter ScrollResolution
```

Expected: PASS (8 tests).

- [x] **Step 5: Commit**

```bash
git add system/lib/ork3/class.Scroll.php tests/Unit/ScrollResolutionTest.php
git commit -m "Enhancement: Scrollery — award title precedence and rank clamping"
```

---

### Task 4: Scroll assembly and partial rendering

**Files:**
- Modify: `system/lib/ork3/class.Scroll.php`
- Test: `tests/Unit/ScrollBuildTest.php`

**Interfaces:**
- Consumes: `Scroll::OrderForAwardId()`, `Scroll::ResolveTitle()`, `Scroll::ClampRank()` from Tasks 2-3.
- Produces:
  - `Scroll::BuildScroll(array $awardRow, array $context): array` — the scroll data contract. Pure; no DB, no globals beyond path constants.
  - `Scroll::Render(array $scroll): string` — output-buffers the order partial and returns HTML. Used by the controller and by the asset-path test.
  - `Scroll::ArtBase(): string` — `HTTP_ASSETS . 'scrolls'`, no trailing slash.
  - `Scroll::PartialPath(string $orderId): string`

  The scroll array keys, relied on by every partial:
  `order, rank, variant, title, player, pronoun, reason, date, givenBy, issuerTitle, issuerName, useThe, useOf, heraldry, flavour, primary, secondary, resources`.

- [x] **Step 1: Write the failing test**

Create `tests/Unit/ScrollBuildTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScrollBuildTest extends TestCase
{
    private function row(array $overrides = []): array
    {
        return array_merge([
            'AwardId'          => 33,
            'Rank'             => 3,
            'Date'             => '2024-05-13',
            'Note'             => 'valour in the field',
            'GivenBy'          => 'Sir Someone',
            'Name'             => 'Order of the Griffin',
            'KingdomAwardName' => 'Order of the Griffin',
            'CustomAwardName'  => '',
            'AliasAwardName'   => '',
            'ParkName'         => 'Rising Sun Station',
            'KingdomName'      => 'Crystal Groves',
        ], $overrides);
    }

    private function context(array $overrides = []): array
    {
        return array_merge([
            'player'   => 'Testerly',
            'pronoun'  => 'her',
            'heraldry' => '/assets/heraldry/park/00435.png',
        ], $overrides);
    }

    public function testBuildsTheScrollContractFromAnAwardRow(): void
    {
        $s = Scroll::BuildScroll($this->row(), $this->context());

        $this->assertSame('griffon', $s['order']);
        $this->assertSame(3, $s['rank']);
        $this->assertSame('Order of the Griffin', $s['title']);
        $this->assertSame('Testerly', $s['player']);
        $this->assertSame('her', $s['pronoun']);
        $this->assertSame('valour in the field', $s['reason']);
        $this->assertSame('Sir Someone', $s['givenBy']);
        $this->assertSame('/assets/heraldry/park/00435.png', $s['heraldry']);
    }

    public function testDateIsFormattedForTheCertificate(): void
    {
        $s = Scroll::BuildScroll($this->row(['Date' => '2024-05-13']), $this->context());
        $this->assertSame('13th May 2024', $s['date']);

        $s = Scroll::BuildScroll($this->row(['Date' => '2024-05-01']), $this->context());
        $this->assertSame('1st May 2024', $s['date']);

        $s = Scroll::BuildScroll($this->row(['Date' => '2024-05-22']), $this->context());
        $this->assertSame('22nd May 2024', $s['date']);

        $s = Scroll::BuildScroll($this->row(['Date' => '0000-00-00']), $this->context());
        $this->assertSame('', $s['date']);
    }

    public function testIssuerPrefersParkAndFallsBackToKingdom(): void
    {
        $s = Scroll::BuildScroll($this->row(), $this->context());
        $this->assertSame('Rising Sun Station', $s['issuerName']);
        $this->assertSame('Barony', $s['issuerTitle']);

        $s = Scroll::BuildScroll($this->row(['ParkName' => '']), $this->context());
        $this->assertSame('Crystal Groves', $s['issuerName']);
        $this->assertSame('Kingdom', $s['issuerTitle']);
    }

    public function testFlavourIsSelectedByClampedRank(): void
    {
        $s = Scroll::BuildScroll($this->row(['Rank' => 3]), $this->context());
        $this->assertSame(Scroll::Orders()['griffon']['flavour']['3'], $s['flavour']);

        // Zodiac 12 clamps to 10 rather than reaching for absent art.
        $s = Scroll::BuildScroll($this->row(['AwardId' => 30, 'Rank' => 12]), $this->context());
        $this->assertSame(10, $s['rank']);
        $this->assertSame(Scroll::Orders()['zodiac']['flavour']['10'], $s['flavour']);
    }

    public function testVariantDefaultsToTheFirstDeclaredVariant(): void
    {
        $s = Scroll::BuildScroll($this->row(['AwardId' => 243]), $this->context());
        $this->assertSame('arcane', $s['variant']);

        $s = Scroll::BuildScroll($this->row(['AwardId' => 243]), $this->context(['variant' => 'martial']));
        $this->assertSame('martial', $s['variant']);

        // An unknown variant falls back rather than producing a 404 art path.
        $s = Scroll::BuildScroll($this->row(['AwardId' => 243]), $this->context(['variant' => 'nonsense']));
        $this->assertSame('arcane', $s['variant']);

        $s = Scroll::BuildScroll($this->row(), $this->context());
        $this->assertSame('', $s['variant']);
    }

    public function testBuildingAnAwardWithNoArtThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Scroll::BuildScroll($this->row(['AwardId' => 1]), $this->context());
    }

    public function testArtBaseHasNoTrailingSlash(): void
    {
        $this->assertStringEndsWith('scrolls', Scroll::ArtBase());
    }
}
```

- [x] **Step 2: Run it to make sure it fails**

```bash
vendor/bin/phpunit --testsuite unit --filter ScrollBuild
```

Expected: FAIL — `Call to undefined method Scroll::BuildScroll()`.

- [x] **Step 3: Implement**

Add to `class.Scroll.php`:

```php
    public static function ArtBase(): string
    {
        return rtrim(HTTP_ASSETS, '/') . '/scrolls';
    }

    public static function PartialPath(string $orderId): string
    {
        return DIR_TEMPLATE . 'revised-frontend/scroll/' . $orderId . '.tpl';
    }

    /**
     * Certificate date, e.g. "13th May 2024". Empty for a missing/zero date so the
     * text layer draws its writing-line blank instead of "30th Nov -0001".
     */
    public static function FormatDate(string $date): string
    {
        if ($date === '' || str_starts_with($date, '0000')) {
            return '';
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return '';
        }
        $day    = (int) date('j', $ts);
        $suffix = ($day % 100 >= 11 && $day % 100 <= 13)
            ? 'th'
            : ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'][$day % 10];

        return $day . $suffix . date(' F Y', $ts);
    }

    public static function BuildScroll(array $awardRow, array $context): array
    {
        $order = self::OrderForAwardId((int) ($awardRow['AwardId'] ?? 0));
        if ($order === null) {
            throw new InvalidArgumentException(
                'No certificate art for award_id ' . (int) ($awardRow['AwardId'] ?? 0)
            );
        }

        $rank = self::ClampRank($order, (int) ($awardRow['Rank'] ?? 0));

        $variant = '';
        if ($order['variants'] !== []) {
            $requested = (string) ($context['variant'] ?? '');
            $variant = in_array($requested, $order['variants'], true)
                ? $requested
                : $order['variants'][0];
        }

        $parkName = trim((string) ($awardRow['ParkName'] ?? ''));

        return [
            'order'       => $order['id'],
            'rank'        => $rank,
            'variant'     => $variant,
            'title'       => self::ResolveTitle($awardRow),
            'player'      => (string) ($context['player'] ?? ''),
            'pronoun'     => (string) ($context['pronoun'] ?? 'their'),
            'reason'      => (string) ($awardRow['Note'] ?? ''),
            'date'        => self::FormatDate((string) ($awardRow['Date'] ?? '')),
            'givenBy'     => (string) ($awardRow['GivenBy'] ?? ''),
            'issuerTitle' => $issuerTitle,   // see the note below the code block
            'issuerName'  => $parkName !== '' ? $parkName : (string) ($awardRow['KingdomName'] ?? ''),
            'useThe'      => (bool) ($context['useThe'] ?? true),
            'useOf'       => (bool) ($context['useOf'] ?? true),
            'heraldry'    => (string) ($context['heraldry'] ?? ''),
            'flavour'     => (string) ($order['flavour'][(string) $rank] ?? ''),
            'primary'     => $order['primary'],
            'secondary'   => $order['secondary'],
            'resources'   => $order['resources'],
        ];
    }

    /** Render one certificate's markup. `$s` is the name every partial binds to. */
    public static function Render(array $s): string
    {
        $path = self::PartialPath($s['order']);
        if (!is_file($path)) {
            throw new RuntimeException('No partial for order ' . $s['order']);
        }
        $art = self::ArtBase();
        ob_start();
        include $path;
        return (string) ob_get_clean();
    }
```

`issuerTitle` accepts a `$context['issuerTitle']` override and falls back to
`Barony`/`Kingdom`.

**Corrected during execution:** the claim that "ORK does not store a park's rank word" is
false. `ork_park.parktitle_id` points at `ork_parktitle.title`, defined per kingdom, and
most parks are not baronies — 349 are Freeholds, 274 Grand Duchies, ~90 Baronies, the
rest Shires/Outposts/Duchies/County/Burg. `BuildScroll` stays pure, so it takes the word
through the context; supplying the real one is the model's job (Task 15). The override
also makes good on this section's own promise of an officer override in Task 16, which
the original code block did not actually implement.

- [x] **Step 4: Run the tests**

```bash
vendor/bin/phpunit --testsuite unit --filter ScrollBuild
```

Expected: PASS (7 tests).

- [x] **Step 5: Commit**

```bash
git add system/lib/ork3/class.Scroll.php tests/Unit/ScrollBuildTest.php
git commit -m "Enhancement: Scrollery — scroll assembly from award rows"
```

---

### Task 5: Shared partials and certificate CSS

**Files:**
- Create: `orkui/template/revised-frontend/scroll/_parchment.tpl`
- Create: `orkui/template/revised-frontend/scroll/_heraldry.tpl`
- Create: `orkui/template/revised-frontend/scroll/_border.tpl`
- Create: `orkui/template/revised-frontend/scroll/_text.tpl`
- Create: `orkui/template/revised-frontend/style/scrollery.css`
- Modify: `system/lib/ork3/class.Scroll.php` (add `scroll_partial()`)
- Test: `tests/Unit/ScrollPartialsTest.php`

**Source:** `arc-scroll/src/award/templates/ash/common/{award-parchment,award-heraldry,award-border}.html` and `common/text/text.html`. `arc-scroll/src/award/award-render.html` holds the layout classes; `arc-scroll/styles/page.css` holds the print block.

**Interfaces:**
- Consumes: `Scroll::Render()`, `Scroll::ArtBase()` from Task 4.
- Produces: global function `scroll_partial(string $name, array $vars = []): void`.

- [x] **Step 1: Write the failing test**

Create `tests/Unit/ScrollPartialsTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScrollPartialsTest extends TestCase
{
    private function scroll(array $overrides = []): array
    {
        return array_merge([
            'order' => 'griffon', 'rank' => 3, 'variant' => '',
            'title' => 'Order of the Griffin', 'player' => 'Testerly',
            'pronoun' => 'her', 'reason' => 'valour', 'date' => '13th May 2024',
            'givenBy' => 'Sir Someone', 'issuerTitle' => 'Barony',
            'issuerName' => 'Rising Sun Station', 'useThe' => true, 'useOf' => true,
            'heraldry' => '/assets/heraldry/park/00435.png',
            'flavour' => 'flavour text', 'primary' => '#8d1b3d', 'secondary' => '#d4af37',
            'resources' => Scroll::DEFAULT_RESOURCES,
        ], $overrides);
    }

    private function render(string $name, array $vars): string
    {
        ob_start();
        scroll_partial($name, $vars);
        return (string) ob_get_clean();
    }

    public function testPartialsDoNotLeakVariablesBetweenCalls(): void
    {
        $first  = $this->render('border', ['primary' => '#111111', 'secondary' => '#222222', 'griffons' => true]);
        $second = $this->render('border', ['primary' => '#333333', 'secondary' => '#444444']);

        $this->assertStringContainsString('#111111', $first);
        $this->assertStringContainsString('#333333', $second);
        $this->assertStringNotContainsString('#111111', $second);
    }

    public function testBorderDrawsGriffonsOnlyWhenAsked(): void
    {
        $with    = $this->render('border', ['primary' => '#111', 'secondary' => '#222', 'griffons' => true]);
        $without = $this->render('border', ['primary' => '#111', 'secondary' => '#222']);

        $this->assertStringContainsString('id="Griffons"', $with);
        $this->assertStringNotContainsString('id="Griffons"', $without);
    }

    public function testHeraldryUsesDefaultsAndAcceptsOverrides(): void
    {
        $default = $this->render('heraldry', ['s' => $this->scroll()]);
        $this->assertStringContainsString('width="420"', $default);
        $this->assertStringContainsString('height="420"', $default);
        $this->assertStringContainsString('margin-top:50px', $default);
        $this->assertStringContainsString('mix-blend-mode: multiply', $default);

        $sized = $this->render('heraldry', ['s' => $this->scroll(), 'width' => 370, 'height' => 460, 'top' => 5]);
        $this->assertStringContainsString('width="370"', $sized);
        $this->assertStringContainsString('margin-top:5px', $sized);
    }

    public function testHeraldryTopOfZeroIsHonouredNotTreatedAsMissing(): void
    {
        $zero = $this->render('heraldry', ['s' => $this->scroll(), 'top' => 0]);
        $this->assertStringContainsString('margin-top:0px', $zero);
    }

    public function testHeraldryIsOmittedEntirelyWhenThereIsNoImage(): void
    {
        $none = $this->render('heraldry', ['s' => $this->scroll(['heraldry' => ''])]);
        $this->assertStringNotContainsString('<img', $none);
    }

    public function testTextLayerRendersTheAwardFields(): void
    {
        $html = $this->render('text', ['s' => $this->scroll()]);

        $this->assertStringContainsString('Order of the Griffin', $html);
        $this->assertStringContainsString('Testerly', $html);
        $this->assertStringContainsString('with her third', $html);
        $this->assertStringContainsString('valour', $html);
        $this->assertStringContainsString('13th May 2024', $html);
        $this->assertStringContainsString('Sir Someone', $html);
        $this->assertStringContainsString('and the Barony of', $html);
        $this->assertStringContainsString('Rising Sun Station', $html);
    }

    public function testTextLayerEscapesUserSuppliedContent(): void
    {
        $html = $this->render('text', ['s' => $this->scroll([
            'player' => '<script>alert(1)</script>',
            'reason' => 'a & b',
        ])]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('a &amp; b', $html);
    }

    public function testRankZeroDrawsABlankRatherThanAnOrdinal(): void
    {
        $html = $this->render('text', ['s' => $this->scroll(['rank' => 0])]);
        $this->assertStringNotContainsString('with her first', $html);
        $this->assertStringContainsString('writing-line.svg', $html);
    }
}
```

- [x] **Step 2: Run it to make sure it fails**

```bash
vendor/bin/phpunit --testsuite unit --filter ScrollPartials
```

Expected: FAIL — `Call to undefined function scroll_partial()`.

- [x] **Step 3: Add the partial helper**

At the **bottom of `class.Scroll.php`**, outside the class:

```php
/**
 * Include a shared scroll partial with an isolated variable scope.
 *
 * A bare `include` shares the caller's scope, which lets a partial silently
 * inherit a stray $primary from an earlier layer. extract() inside a function
 * gives partials real parameters, the way Aurelia's `bindable` did.
 */
function scroll_partial(string $name, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    $art = Scroll::ArtBase();
    include DIR_TEMPLATE . 'revised-frontend/scroll/_' . $name . '.tpl';
}
```

> **Two corrections made during execution.**
> 1. `html_encode()` is `htmlentities()` with the HTML5 tables, so it turns `#` into
>    `&num;` — a colour reaching a style attribute renders as
>    `--primary-color:&num;333333`. These partials put colours and length-driven
>    `calc()` values into style attributes constantly, so they use a local
>    `scroll_e()` (htmlspecialchars, as the rest of ORK's templates do) instead.
> 2. Aurelia's `css=` binding MERGED into an element's existing `style=`. Two of the
>    source elements carry both, so transliterating `css=` to a second `style=`
>    attribute makes the browser keep the first and drop the second: the border's
>    root `<svg>` loses `fill-rule:evenodd` (filling in every hole in the border
>    paths) and the reason section loses its margins. Both are merged into one
>    attribute, and `testNoElementCarriesTwoStyleAttributes` guards the class of bug.

- [x] **Step 4: Write the four shared partials**

`_parchment.tpl`:

```php
<img class="bleed" src="<?= $art ?>/common/parchment.jpg" width="2550" height="3300" alt="">
```

`_heraldry.tpl` — transliterated from `award-heraldry.html`. Note the explicit
`isset`/`=== ''` guards: `${x || 420}` in the original would swallow a legitimate `0`,
and `top="0"` is a real value.

```php
<?php
$hTop    = (isset($top)    && $top    !== '' && $top    !== null) ? (int) $top    : 50;
$hWidth  = (isset($width)  && $width  !== '' && $width  !== null) ? (int) $width  : 420;
$hHeight = (isset($height) && $height !== '' && $height !== null) ? (int) $height : 420;
?>
<div class="centre-top" style="margin-top:<?= $hTop ?>px;padding:1px;">
<?php if (($s['heraldry'] ?? '') !== ''): ?>
    <img src="<?= html_encode($s['heraldry']) ?>" width="<?= $hWidth ?>" height="<?= $hHeight ?>"
         style="mix-blend-mode: multiply;object-fit:contain;filter: var(--heraldry-filter);transform: var(--heraldry-transform);" alt="">
<?php endif; ?>
</div>
```

`_border.tpl` — copy the inline SVG from `arc-scroll/src/award/templates/ash/common/award-border.html`
verbatim. Two changes only:

1. `css="--primary-color:${primary};--secondary-color:${secondary}"` becomes
   `style="--primary-color:<?= html_encode($primary) ?>;--secondary-color:<?= html_encode($secondary) ?>"`.
2. The `<g id="Griffons">` block (~47 KB, half the file) is wrapped in
   `<?php if (!empty($griffons)): ?> ... <?php endif; ?>`. **Do not hide it with CSS
   instead** — that puts 47 KB of geometry in the DOM of all ten orders that compose
   this partial, when only `griffon` draws it.

`_text.tpl` — transliterate `arc-scroll/src/award/templates/ash/common/text/text.html`.
**Move its entire `<style>` block into `scrollery.css`** (see Step 5); the partial keeps
only markup. Bindings map as:

| Aurelia | PHP |
|---|---|
| `${award.player}` | `<?= html_encode($s['player']) ?>` |
| `${award.reason}` | `<?= html_encode($s['reason']) ?>` |
| `${award.template.title}` | `<?= html_encode($s['title']) ?>` |
| `${award.displayedFlavour}` | `<?= html_encode($s['flavour']) ?>` |
| `${Instance.Date}` / `${Instance.GivenBy}` | `<?= html_encode($s['date']) ?>` / `<?= html_encode($s['givenBy']) ?>` |
| `${Instance.IssuerTitle}` / `${Instance.IssuerName}` | `<?= html_encode($s['issuerTitle']) ?>` / `<?= html_encode($s['issuerName']) ?>` |
| `${Instance.UseThe ? ' the' : ''}` | `<?= $s['useThe'] ? ' the' : '' ?>` |
| `src.bind="template.resources.writing_line"` | `src="<?= rtrim(HTTP_ASSETS, '/') . '/' . $s['resources']['writing_line'] ?>"` |
| `${['', 'first', ...][award.rank]}` | same array, indexed by `$s['rank']` |
| `if.bind="award.rank == 0"` | `<?php if ($s['rank'] === 0): ?>` |

The two `css=` font-size expressions that scale with string length become inline
`style="font-size:max(...)"` with `strlen()` substituted for `.length`.

- [x] **Step 5: Write `scrollery.css`**

Assemble from three sources, in this order:

1. The layout classes from `arc-scroll/src/award/award-render.html`'s `<style>`:
   `.award-template > *`, `.centre-top`, `.centre-bottom`, `.centre`, plus `.bleed`.
2. The whole `<style>` block from `text.html`, unchanged except that `:root` becomes
   `.award-template`.
3. `@font-face` for BlackChancery and the four Averia faces, pointing at
   `style/fonts/`. **No Google Fonts `@import`** — a print path must not depend on a
   third-party CDN.

Then add the per-order rules that were inline `<style>` blocks, keyed by data attribute.
This is the shadow-root fix from Global Constraints:

```css
.award-template[data-order="griffon"] section.SignAndDate,
.award-template[data-order="dragon"]  section.SignAndDate,
.award-template[data-order="hydra"]   section.SignAndDate,
.award-template[data-order="hunter"]  section.SignAndDate,
.award-template[data-order="flame"]   section.SignAndDate { min-height: 650px !important; }

.award-template[data-order="lion"] section.SignAndDate,
.award-template[data-order="mask"] section.SignAndDate { min-height: 550px !important; }

.award-template[data-order="rose"]  section.SignAndDate,
.award-template[data-order="smith"] section.SignAndDate,
.award-template[data-order="owl"]   section.SignAndDate { min-height: 250px !important; }

.award-template[data-order="griffon"] [href="#Border-Deco-Special-Griffon"] { display: unset !important; }
.award-template[data-order="griffon"] [href="#Border-Deco-1"] { display: none; }
```

Print rules — much smaller than Arcanyst's, because the render route contains nothing
but certificates:

```css
@media print {
    @page { size: letter; margin: 0.125in; }
    html, body { margin: 0 !important; padding: 0 !important; }
    .scroll-page { page-break-inside: avoid; }
    .scroll-page + .scroll-page { page-break-before: always; }
    .award-template, .award-template * {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
}
```

- [x] **Step 6: Run the tests**

```bash
vendor/bin/phpunit --testsuite unit --filter ScrollPartials
```

Expected: PASS (8 tests).

- [x] **Step 7: Commit**

```bash
git add orkui/template/revised-frontend/scroll orkui/template/revised-frontend/style/scrollery.css \
        system/lib/ork3/class.Scroll.php tests/Unit/ScrollPartialsTest.php
git commit -m "Enhancement: Scrollery — shared certificate partials and stylesheet"
```

---

### Task 6: First certificate — griffon, controller, render route

This is the risk-retiring task. `griffon` alone exercises the border override, a per-rank
offset table and a deliberate duplicate layer, so if it prints correctly the remaining
eighteen are mechanical.

**Files:**
- Create: `orkui/template/revised-frontend/scroll/griffon.tpl`
- Create: `orkui/controller/controller.Scrollery.php`
- Create: `orkui/template/revised-frontend/Scrollery_render.tpl`
- Modify: `orkui/model/model.Scrollery.php` (create; thin)

**Source:** `arc-scroll/src/award/templates/ash/griffon.html`

**Interfaces:**
- Consumes: `Scroll::BuildScroll()`, `Scroll::Render()`, `scroll_partial()`.
- Produces: `Controller_Scrollery::render()`; `Model_Scrollery::scroll_for_awards_id(int $awardsId, int $viewerId): ?array`.

- [x] **Step 1: Write `griffon.tpl`**

```php
<div class="award-template" data-order="griffon" data-rank="<?= (int) $s['rank'] ?>">
    <?php scroll_partial('parchment'); ?>
    <?php scroll_partial('border', ['primary' => $s['primary'], 'secondary' => $s['secondary'], 'griffons' => true]); ?>
    <?php scroll_partial('heraldry', ['s' => $s]); ?>

    <?php $sym = $s['rank'] === 0 ? 5 : $s['rank']; ?>
    <div class="centre-bottom"><img src="<?= $art ?>/griffon/symbol_<?= $sym ?>.png" style="mix-blend-mode:soft-light;" alt=""></div>
    <div class="centre-bottom"><img src="<?= $art ?>/griffon/symbol_<?= $sym ?>.png" style="mix-blend-mode:soft-light;" alt=""></div>

    <?php if ($s['rank'] > 0): $offsets = [85, 85, 85, 85, 85, 440, 440, 440, 235, 185]; ?>
        <div class="centre-bottom" style="bottom:<?= $offsets[$s['rank'] - 1] ?>px;">
            <img src="<?= $art ?>/common/rank_<?= $s['rank'] ?>.png" alt="">
        </div>
    <?php endif; ?>

    <?php scroll_partial('text', ['s' => $s]); ?>
</div>
```

The doubled `soft-light` layer is **deliberate** — it deepens the blend. `griffon` has no
`symbol_0.png`; rank 0 borrows `symbol_5`, exactly as the source does.

- [x] **Step 2: Write the render surface**

`Scrollery_render.tpl` — no ORK chrome, only certificates:

```php
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/scrollery.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/scrollery.css') ?>">
<?php foreach ($this->data['Scrolls'] as $s): ?>
<div class="scroll-page">
    <svg viewBox="0 0 2550 3300"
         style="image-resolution:300dpi;aspect-ratio:8.5/11;width:100%;height:100%;position:relative;overflow:hidden;"
         data-scroll-frame>
        <foreignObject x="0" y="0" width="2550" height="3300" style="overflow:visible;">
            <?= Scroll::Render($s) ?>
        </foreignObject>
    </svg>
</div>
<?php endforeach; ?>
<?php if (!empty($this->data['AutoPrint'])): ?>
<script>window.addEventListener('load', function () { window.print(); });</script>
<?php endif; ?>
```

The `<svg>`/`<foreignObject>` frame is kept from Arcanyst: it is what makes the same
2550x3300 markup scale to both a small preview and a printed page.

- [x] **Step 3: Write the controller**

> **Corrected during execution — the render route cannot use `$this->template`.**
> `View::view()` wraps every template in the controller template and then the site
> theme (nav, GTM, footer). Setting `$this->template` would therefore put full ORK
> chrome around the certificates, which is the one thing this route must not have.
> `render()` includes the template with its data extracted into local scope and
> `exit`s, following the `Controller_EventEmbed` precedent. The template reads
> `$Scrolls`, not `$this->__data['Scrolls']`.

`controller.Scrollery.php`:

```php
<?php

class Controller_Scrollery extends Controller
{
    public function __construct($call = null, $id = null)
    {
        parent::__construct($call, $id);
        $this->load_model('Scrollery');
        $this->load_model('Player');
    }

    public function index($action = null) {}

    /**
     * The bare print surface. Never the authority — it re-runs the permission
     * check against its own parameters.
     */
    public function render($action = null)
    {
        $viewerId  = isset($this->session->user_id) ? (int) $this->session->user_id : 0;
        $awardsIds = array_filter(array_map('intval', explode(',', (string) ($_GET['awards'] ?? ''))));

        $scrolls = [];
        foreach ($awardsIds as $awardsId) {
            $scroll = $this->Scrollery->scroll_for_awards_id($awardsId, $viewerId);
            if ($scroll !== null) {
                $scrolls[] = $scroll;
            }
        }

        if ($scrolls === []) {
            header('Location: ' . UIR . 'Scrollery');
            return;
        }

        $this->data['Scrolls']   = $scrolls;
        $this->data['AutoPrint'] = isset($_GET['print']);
        $this->template          = '../revised-frontend/Scrollery_render.tpl';
    }
}
```

- [x] **Step 4: Write the model**

`model.Scrollery.php` — thin; the permission decision lives here so every route shares it.

```php
<?php

class Model_Scrollery extends Model
{
    /**
     * Returns the scroll data for one awards row, or null when the viewer may not
     * print it or the award has no certificate art.
     */
    public function scroll_for_awards_id(int $awardsId, int $viewerId): ?array
    {
        if ($awardsId <= 0 || $viewerId <= 0) {
            return null;
        }

        $row = $this->award_row($awardsId);
        if ($row === null || !Scroll::HasArt($row)) {
            return null;
        }

        if (!$this->viewer_may_print($row, $viewerId)) {
            return null;
        }

        $player = Ork3::$Lib->player->GetPlayer(['MundaneId' => (int) $row['MundaneId']]);

        return Scroll::BuildScroll($row, [
            'player'   => $player['Player']['Persona'] ?? '',
            'pronoun'  => $this->possessive_pronoun((int) $row['MundaneId']),
            'heraldry' => $this->heraldry_url($row),
            'variant'  => (string) ($_GET['variant'] ?? ''),
        ]);
    }

    /** Own award, or an officer with edit rights over the granting park/kingdom. */
    private function viewer_may_print(array $row, int $viewerId): bool
    {
        if ((int) $row['MundaneId'] === $viewerId) {
            return true;
        }

        $auth = Ork3::$Lib->authorization;
        if ((int) $row['ParkId'] > 0
            && $auth->HasAuthority($viewerId, AUTH_PARK, (int) $row['ParkId'], AUTH_EDIT)) {
            return true;
        }
        if ((int) $row['KingdomId'] > 0
            && $auth->HasAuthority($viewerId, AUTH_KINGDOM, (int) $row['KingdomId'], AUTH_EDIT)) {
            return true;
        }

        return false;
    }
}
```

Three helpers remain. Each reuses an existing API — write no new queries here.

**`award_row(int $awardsId): ?array`** — `AwardsForPlayer()` takes `AwardsId` and returns
the matching row alongside the player's others, so call it and select the row whose
`AwardsId` matches (`class.Player.php:448`). Do not write a parallel query; this one
already resolves the alias, kingdomaward, park, kingdom, event and giver joins.

**`heraldry_url(array $row): string`** — `Ork3::$Lib->heraldry->GetHeraldryUrl()` takes
`['Type' => 'Park'|'Kingdom', 'Id' => $id]` and returns `['Url' => ...]`
(`class.Heraldry.php:30`). Prefer `at_park_id`, fall back to `at_kingdom_id`, and return
`''` when neither resolves — `_heraldry.tpl` omits the layer entirely on an empty string.

**`possessive_pronoun(int $mundaneId): string`** — the text layer needs the *possessive*
("with **her** third"). `class.Pronoun.php` exposes only `GetPronounList($in)`, so:
read `PronounId` and `PronounCustom` from `GetPlayer()`, then

- if `PronounCustom` holds JSON, use `Ork3::$Lib->pronoun->fetch_custom_pronoun_display()`
  and take the first `possessive` entry;
- else look up `GetPronounList([$pronounId])['Pronouns'][$pronounId]['Possessive']`;
- else return `'their'`.

Return `'their'` for id 0 or any miss. Never leave it blank — the sentence reads
"with third" if you do.

- [x] **Step 5: Render it in a browser**

Pick a real Griffon award from the dev database:

```bash
mysql -P 19306 --protocol=tcp -h localhost -u root -proot ork -e \
  "SELECT a.awards_id, a.mundane_id, a.rank FROM ork_awards a
     JOIN ork_kingdomaward ka ON ka.kingdomaward_id = a.kingdomaward_id
    WHERE ka.award_id = 33 AND a.rank > 0 LIMIT 5;"
```

Log in as that player (or an officer over their park), then open:

`http://localhost:19080/orkui/index.php?Route=Scrollery/render&awards=<awards_id>`

- [x] **Step 6: Compare against Arcanyst — the visual gate** (signed off 2026-09-03)

> Structure verified mechanically: the layer stack, its document order, both blend
> modes, the duplicated soft-light layer and the rank-offset table all match
> `griffon.html` exactly, and all 8 asset URLs resolve. Rendered against real award
> data and screenshotted beside Arcanyst's own griffon rank 1 — every visible
> difference was data (real issuer, real pronoun, ORK's "Griffin" spelling), not
> rendering. Human sign-off given: "Example looks good".

Serve arc-scroll and render the same order and rank:

```bash
(cd /Users/averykrouse/arcanyst-clone/arc-scroll && python3 -m http.server 8000)
# then http://localhost:8000/
```

Check side by side: parchment tone, border colour and the griffon deco, heraldry
position and multiply blend, the doubled soft-light symbol's depth, rank numeral
position at several ranks, and the text block's vertical rhythm. **Do not proceed until
these match.** Anything wrong here is wrong in all nineteen.

- [x] **Step 7: Run the full suite and commit**

```bash
vendor/bin/phpunit --testsuite unit
git add orkui/controller/controller.Scrollery.php orkui/model/model.Scrollery.php \
        orkui/template/revised-frontend/Scrollery_render.tpl \
        orkui/template/revised-frontend/scroll/griffon.tpl
git commit -m "Enhancement: Scrollery — render route and the first certificate"
```

---

### Task 7: Asset-path test

The regression net. It expands every order across its real rank and variant range,
extracts every `src`, and asserts the file exists — the only thing that catches a
certificate which renders a broken image at exactly one order and rank. It walks
**whichever partials exist**, so it grows by itself as Tasks 8-13 land.

**Files:**
- Test: `tests/Unit/ScrollAssetPathsTest.php`

- [x] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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
        // silently. Several Arcanyst views shipped that way.
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
        ];
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
```

- [x] **Step 2: Run it**

```bash
vendor/bin/phpunit --testsuite unit --filter ScrollAssetPaths
```

Expected: PASS — 11 griffon cases (ranks 0-10) plus the two structural tests.

- [x] **Step 3: Prove it actually catches a missing file**

Temporarily break one path and confirm a red test, then restore it:

```bash
mv assets/scrolls/griffon/symbol_5.png /tmp/symbol_5.png
vendor/bin/phpunit --testsuite unit --filter ScrollAssetPaths   # Expected: FAIL (ranks 0 and 5)
mv /tmp/symbol_5.png assets/scrolls/griffon/symbol_5.png
vendor/bin/phpunit --testsuite unit --filter ScrollAssetPaths   # Expected: PASS
```

A test that cannot fail is not a net. Do not skip this step.

- [x] **Step 4: Commit**

```bash
git add tests/Unit/ScrollAssetPathsTest.php
git commit -m "Enhancement: Scrollery — asset-path regression test"
```

---

## Tasks 8-13: the remaining fifteen orders

Griffon landed in Task 6; these six tasks cover the other fifteen orders, and Task 14
closes the loop. Every order task follows the same cycle, so it is written once here:

1. Read the source view in `arc-scroll/src/award/templates/ash/<order>.html`.
2. Transliterate it into `orkui/template/revised-frontend/scroll/<order>.tpl`, wrapper
   first: `<div class="award-template" data-order="<order>" data-rank="<?= (int) $s['rank'] ?>"`
   plus `data-variant` where the order has variants.
3. Move any `<style>` block into `scrollery.css` keyed by `[data-order]` / `[data-rank]` /
   `[data-variant]`. **Never inline it** — see Global Constraints.
4. `vendor/bin/phpunit --testsuite unit --filter ScrollAssetPaths` — the matrix picks the
   new partial up automatically. Expect it to fail first if a path is wrong.
5. Render each rank in the browser and compare against Arcanyst side by side.
6. Commit.

**Preserve sibling order exactly** (document order is z-order) and **preserve every
repeated layer**.

---

### Task 8: lion, hydra, hunter

**Files:** Create `scroll/{lion,hydra,hunter}.tpl`; modify `style/scrollery.css`.

All three share one shape: parchment, heraldry, border twice, symbol twice, text. They
differ only in the second layer's blend and opacity.

| Order | Layers, in document order | `SignAndDate` |
|---|---|---|
| `lion` | parchment; heraldry; `lion/border.png` `multiply`; `lion/border.png` `soft-light` `opacity:.3`; `lion/symbol_${rank}.png` `multiply`; `lion/symbol_${rank}.png` `screen` `opacity:.3`; text | 550px |
| `hydra` | parchment; heraldry; `hydra/border.png` `multiply`; `hydra/border.png` `soft-light` `opacity:.5`; `hydra/symbol_${rank}.png` `multiply`; `hydra/symbol_${rank}.png` `soft-light` `opacity:.5`; text | 650px |
| `hunter` | parchment; heraldry; `hunter/border.png` `multiply`; `hunter/border.png` `normal` `opacity:.7`; `hunter/symbol_${rank}.png` `multiply`; `hunter/symbol_${rank}.png` `normal` `opacity:.7`; text | 650px |

Symbols are wrapped in `<div class="centre-bottom">`; borders are bare full-bleed `<img>`.
All three have `symbol_0.png`, so **the symbol path is unguarded** — do not add a rank
guard. None draws a `common/rank_N.png` numeral.

`hunter.tpl` serves all four archery names. Its art path is hardcoded to
`hunter/`; the kingdom's own name reaches the certificate through `$s['title']`
(`ork_kingdomaward.name`), which is why `bowyer`, `marksman` and `sharpshooter` are not
orders here.

- [x] Write the three partials
- [x] Add the two `SignAndDate` rules to `scrollery.css` (the 650px group already exists from Task 5)
- [x] `vendor/bin/phpunit --testsuite unit --filter ScrollAssetPaths` — expect 33 new cases, all passing
- [x] Compare all three against Arcanyst at ranks 0, 5 and 10
- [ ] `git commit -m "Enhancement: Scrollery — lion, hydra and hunter certificates"`

---

### Task 9: rose, smith, mask

**Files:** Create `scroll/{rose,smith,mask}.tpl`; modify `style/scrollery.css`.

| Order | Layers, in document order | Notes |
|---|---|---|
| `rose` | parchment; `rose/burn.svg` `multiply` `opacity:.2` `filter:hue-rotate(80deg)`; heraldry; `rose/border.png` `multiply`; `rose/border.png` `opacity:.85`; `rose/symbol_${rank}.png` (no blend); text | `SignAndDate` 250px. Note the burn layer sits **below** heraldry. `rose` overrides the three flourishes with `squiggly-*-rose.svg` — already handled by the resources merge in Task 2. |
| `smith` | parchment; heraldry; `smith/burn.jpg` `soft-light`; `smith/border.png` `multiply`; `smith/border.png` `opacity:.6`; `smith/symbol.png` `multiply`; `smith/symbol.png` `opacity:.3`; text | `SignAndDate` 250px. **`symbol.png` is unranked** — no `${rank}` anywhere. |
| `mask` | parchment; heraldry; `mask/border.png` `screen` `opacity:.6`; `mask/border.png` `multiply`; glow symbol; plain symbol; rank numeral; glow symbol **again**; text | `SignAndDate` 550px. **`symbol.png` is unranked.** |

`mask`'s glow layers carry
`filter: sepia(1) contrast(0.5) hue-rotate(200deg) saturate(5) blur(200px); mix-blend-mode: screen;`
on the wrapping `div`, not the `img`. There are **three** symbol layers: glow, plain,
then glow again *after* the rank numeral. That ordering is what puts the glow over the
numeral — keep it.

`mask`'s numeral is guarded (`rank > 0`) at `bottom:60px; opacity:1`.

- [x] Write the three partials
- [x] Add the `[data-order]` rules to `scrollery.css`
- [x] `vendor/bin/phpunit --testsuite unit --filter ScrollAssetPaths`
- [x] Compare against Arcanyst; check `mask`'s glow reads over the numeral, and `rose`'s hue-rotated burn
- [ ] `git commit -m "Enhancement: Scrollery — rose, smith and mask certificates"`

---

### Task 10: crown, garber, jovious, flame

**Files:** Create `scroll/{crown,garber,jovious,flame}.tpl`; modify `style/scrollery.css`.

These four each replace or skip the parchment.

| Order | Layers | Notes |
|---|---|---|
| `crown` | parchment; heraldry; `crown/border.png` `multiply`; `crown/border.png` `opacity:.5`; `crown/phoenix.png` `multiply` in `.centre`; `crown/phoenix.png` `opacity:.5` in `.centre`; `crown/symbol_${rank}.png` in `.centre-bottom`; text | Phoenix uses `.centre`, not `.centre-bottom`. Source interpolates `${award.order}` into three of these paths; write `crown` literally. |
| `garber` | `garber/linen.jpg` (class `bleed`, **replaces parchment**); heraldry `width=380 height=380 top=35`; `garber/overlay.png`; `garber/rank_${rank}.png` in `.centre-bottom`; text | No `<style>` block at all — no `SignAndDate` override. `rank_0.png` exists, so the path is unguarded. |
| `jovious` | `jovious/bg.jpg` (**replaces parchment**); heraldry; `jovious/symbol_${rank}.png`; text | No `<style>` block. Overrides the three flourishes with `ribbon-*.png`. **The source has a duplicated nested `<div class="award-template">` — a defect. Emit one wrapper only.** |
| `flame` | `flame/bg.jpg` (**replaces parchment**); heraldry; `flame/rank_${rank}.png` **guarded on `rank > 0`**; text | `SignAndDate` 650px. `flame` has no `rank_0.png`; the guard is what stops a broken image. Do not remove it. |

- [x] Write the four partials
- [x] Add the `[data-order]` rules to `scrollery.css`
- [x] `vendor/bin/phpunit --testsuite unit --filter ScrollAssetPaths`
- [x] Verify `flame` rank 0 renders with no numeral and no broken image
- [x] Compare all four against Arcanyst
- [ ] `git commit -m "Enhancement: Scrollery — crown, garber, jovious and flame certificates"`

---

### Task 11: dragon

**Files:** Create `scroll/dragon.tpl`; modify `style/scrollery.css`.

The only order that draws the shared border partial twice:

```php
<?php scroll_partial('parchment'); ?>
<?php scroll_partial('border', ['primary' => $s['primary'], 'secondary' => $s['secondary']]); ?>
<?php scroll_partial('border', ['primary' => 'black', 'secondary' => $s['secondary'],
                                'style' => 'filter:blur(13px);mix-blend-mode:color-dodge;']); ?>
<?php scroll_partial('heraldry', ['s' => $s]); ?>
```

The second pass is a **glow**: black primary, blurred 13px, `color-dodge`. `_border.tpl`
needs an optional `$style` parameter on its outer `<div>` to carry it — add that in this
task and leave it unset everywhere else.

Remaining layers, in order: `dragon/dragons.svg` `multiply`;
`dragon/under_${rank}.png` `multiply` in `.centre-bottom`;
`dragon/over_${rank}.png` in `.centre-bottom` **guarded on `rank >= 6`**;
`common/rank_${rank}.png` **guarded on `rank > 0`** at `bottom:50px`; text.
`SignAndDate` 650px. `under_0.png` exists, so `under_` is unguarded.

- [x] Add the `$style` parameter to `_border.tpl`
- [x] Write `dragon.tpl`
- [x] `vendor/bin/phpunit --testsuite unit --filter ScrollPartials ScrollAssetPaths`
- [ ] Check ranks 5 and 6 differ (the `over_` layer appears at 6) and that the glow reads
- [ ] `git commit -m "Enhancement: Scrollery — dragon certificate"`

---

### Task 12: zodiac and battle

**Files:** Create `scroll/{zodiac,battle}.tpl`; modify `style/scrollery.css`.

**`zodiac`** swaps its background on rank and has two conditional `<style>` blocks.

- Background: `zodiac/bg_generic.jpg` when `rank === 0`, else `zodiac/bg_rank.jpg`. No parchment.
- Heraldry: `top=100 width=360 height=360`.
- Numeral: `zodiac/rank_${rank}.png` in `.centre-bottom`, `style="margin:-37.5px"`,
  **guarded on `rank > 0`** (there is no `rank_0.png`).
- Text layer.

Its CSS, keyed by data attribute:

```css
.award-template[data-order="zodiac"] .Award { padding: 21% 21% 12% 21%; }
.award-template[data-order="zodiac"] section.Body { width: 100%; }
.award-template[data-order="zodiac"] section.Flavour { width: 85% !important; }

.award-template[data-order="zodiac"][data-rank="0"] section.SignAndDate {
    width: 90%; flex-basis: 350px; min-height: 350px;
}
.award-template[data-order="zodiac"]:not([data-rank="0"]) section.SignAndDate {
    width: 130%; flex-basis: 500px; min-height: 500px;
}
.award-template[data-order="zodiac"]:not([data-rank="0"]) section.SignAndDate > div:first-child {
    margin-left: 0.6em;
}
```

Zodiac's `<<MONTH>>` placeholder in its flavour text is **left as-is** in phase 1 —
month handling is a separate branch. Ranks stay clamped at 10.

**`battle`** has two variants, `arcane` and `martial`. Wrapper gets
`data-variant="<?= html_encode($s['variant']) ?>"`.

Layers, in order: parchment; border partial (`primary`/`secondary`); heraldry;
`battle/arcane/wash.jpg` class `bleed` `soft-light` `opacity:.8` **only when arcane**;
`battle/border_symbols.png` `multiply` `width="2291"` inside
`<div class="centre" style="margin-bottom:90px;">`;
`battle/arcane/glow.png` `multiply` in `.centre-bottom` **only when arcane**;
`battle/${variant}/symbol_${rank}.png` in `.centre-bottom` — `multiply` **only when
martial**, no blend when arcane; `battle/martial/over_${rank}.png` `multiply` in
`.centre-bottom` **only when martial and `rank >= 6`**;
`common/rank_${rank}.png` **guarded on `rank > 0`** at `bottom:124px` when `rank >= 9`
else `134px`; text.

`wash.jpg` and `glow.png` are the two files renamed from webpack hashes in Task 1. Both
variants have `symbol_0.png`, so the symbol path is unguarded. `battle` has no
`SignAndDate` override. The source contains a stray empty `<div class=""></div>`; drop it.

- [x] Write both partials
- [x] Add the zodiac rules to `scrollery.css`
- [x] `vendor/bin/phpunit --testsuite unit --filter ScrollAssetPaths` — expect 11 zodiac + 22 battle cases
- [x] Compare zodiac at rank 0 and rank 5 (different backgrounds), and both battle variants at ranks 0, 6 and 10
- [ ] `git commit -m "Enhancement: Scrollery — zodiac and battle certificates"`

---

### Task 13: owl and warrior

The two irregular orders. Read the Global Constraints on duplicated layers again before
starting.

**Files:** Create `scroll/{owl,warrior}.tpl`; modify `style/scrollery.css`.

**`owl`** has three variants, and **the number of times each layer is drawn varies by
variant**. This is deliberate: stacking identical `multiply`/`screen` layers deepens
them, and the transparent-ground variants do not read without the extra passes.

| Layer | `parchment` | `cyanotype` | `transparency` |
|---|---|---|---|
| `owl/cyanotype.jpg` background | — | 1x | — |
| shared parchment | 1x | — | — |
| heraldry | 1x | 2x | 4x |
| `owl/border.jpg` | 2x | 1x | 3x |
| `owl/rank_${rank}.png` | 1x | 1x | 2x |

Every one of those layers carries the same conditional blend expression:
`cyanotype` gets `mix-blend-mode: screen;`, the other two get
`filter:invert();mix-blend-mode:multiply;`. Compute it once into a variable at the top of
the partial and reuse it — that is a faithful transliteration, not a deduplication.

Heraldry is `width=370 height=460 top=5`. The rank layer sits in `.centre-bottom` with
`margin-bottom:75px`. `rank_0.png` exists, so it is unguarded.

Owl's CSS:

```css
.award-template[data-order="owl"] section.SignAndDate { min-height: 250px !important; }
.award-template[data-order="owl"] .centre-top { overflow: hidden; }
.award-template[data-order="owl"] .centre-top { filter: blur(1px) url(#OWL_edge-detect); position: absolute; bottom: 0; }
.award-template[data-order="owl"][data-variant="parchment"] .Award { opacity: 0.85; }
.award-template[data-order="owl"][data-variant="cyanotype"] .Award img { filter: invert(); }
.award-template[data-order="owl"][data-variant="cyanotype"] .Award { color: #ffffff !important; }
```

`url(#OWL_edge-detect)` is an in-document SVG filter. It lives in
`arc-scroll/src/resources/filters.html`; copy that filter definition into
`Scrollery_render.tpl` as a hidden `<svg>` so it resolves. **Define it once per page**,
not per certificate — duplicate SVG filter ids resolve to the first in the document, so
copies are dead weight and cost two indefinite SMIL animations each.

**`warrior`** is the only order with `ranks` `1-10`, a different `<style>` per rank, and a
parchment swap at rank 10.

- Background: shared parchment when `rank !== 10`; `warrior/bg_10.jpg` `soft-light` when `rank === 10`.
- Heraldry: defaults.
- Border: `warrior/border_${rank}.jpg` `multiply`, **only when `rank !== 10`** (there is no `border_10.jpg`).
- Symbol: `warrior/symbol_9.png` in `.centre-bottom`, **only at rank 9** — the "big scary dragon".
- Text layer.

Its per-rank CSS becomes:

```css
.award-template[data-order="warrior"][data-rank="2"] section.SignAndDate { min-height: 500px !important; }
.award-template[data-order="warrior"][data-rank="3"] section.SignAndDate { min-height: 600px !important; }
.award-template[data-order="warrior"][data-rank="4"] section.SignAndDate { min-height: 500px !important; }
.award-template[data-order="warrior"][data-rank="5"] section.SignAndDate { min-height: 650px !important; }
.award-template[data-order="warrior"][data-rank="6"] section.SignAndDate { min-height: 500px !important; }
.award-template[data-order="warrior"][data-rank="8"] section.SignAndDate { min-height: 800px !important; }
.award-template[data-order="warrior"][data-rank="9"] section.SignAndDate { min-height: 750px !important; }
.award-template[data-order="warrior"][data-rank="9"] .Award { padding-bottom: 18% !important; }
.award-template[data-order="warrior"][data-rank="9"] section.SignAndDate {
    flex-direction: column !important; row-gap: 1em !important; justify-content: flex-end !important;
}
```

Ranks 1, 7 and 10 have no override — that is correct, not an omission.

- [x] Write both partials
- [x] Copy the `#OWL_edge-detect` filter definition into `Scrollery_render.tpl`
- [x] Add both orders' rules to `scrollery.css`
- [x] `vendor/bin/phpunit --testsuite unit --filter ScrollAssetPaths` — expect 33 owl + 10 warrior cases
- [x] Compare all three owl variants at rank 5 — the transparency variant is the one that reveals a missing repeat
- [x] Compare warrior at ranks 1, 9 and 10
- [ ] `git commit -m "Enhancement: Scrollery — owl and warrior certificates"`

---


---

### Visual gate — signed off 2026-09-03

All 16 orders compared side by side against Arcanyst 2.1.3 in Chrome. Every one matched:
parchment/ground, border colour and deco, flanking figures, symbol blends, numeral art
and position, flourishes, and text rhythm. The only differences were data — this port
fills in a real issuer, recipient, pronoun and heraldry where Arcanyst rendered blanks,
and prints the ORK's own award name ("Order of the Griffin") where Arcanyst fabricated
one from the order id ("Order of the Griffon").

Cases checked beyond a plain rank 5: `flame` 0 (numeral guard — both render "with their
____" and no broken image), `dragon` 6 (the over_ layer), `warrior` 9 (big scary dragon,
and the column-stacked signature block from the per-rank CSS), `warrior` 10 (the bg_10
parchment swap), `zodiac` 0 and 5 (the two plates and the rank-conditional CSS), `owl`
all three variants, `battle` both variants, `crown` (the wide signature geometry).

Three integration details confirmed visually rather than only by test:
- jovious draws BLUE RIBBON flourishes and rose draws leafy rose squigglies, so the
  Task 2 partial-resources merge reaches the text layer correctly.
- zodiac's flavour reads "for whole months to remember" in BOTH implementations —
  Arcanyst collapses the month placeholder to the same else branch, so ExpandFlavour
  reproduces its behaviour rather than inventing one.
- owl cyanotype is pixel-comparable to Arcanyst's default owl, which is what made the
  variant reorder necessary: Arcanyst always had a picker in front of that default.

`hunter` could not be compared — Arcanyst gates the archery orders behind a kingdom-id
list and its kingdom-loading Cloud Function is dead, so the order never appears in its
sidebar. It renders correctly here and shares its layer shape with lion and hydra, both
of which matched.

### Task 14: Close the loop

**Files:** Modify `tests/Unit/ScrollOrdersTest.php`

- [x] **Step 1: Add the completeness assertion**

```php
    public function testEveryOrderHasAPartial(): void
    {
        foreach (Scroll::Orders() as $id => $order) {
            $this->assertFileExists(Scroll::PartialPath($id), "{$id} has no partial");
        }
    }
```

- [x] **Step 2: Run the whole suite**

```bash
vendor/bin/phpunit --testsuite unit
```

Expected: PASS. The asset-path matrix should now be ~190 cases (16 orders, expanded
across ranks and variants).

- [x] **Step 3: Commit**

```bash
git add tests/Unit/ScrollOrdersTest.php
git commit -m "Enhancement: Scrollery — assert every order has a partial"
```

---

### Task 15: "Reprint my scroll"

**Files:**
- Create: `orkui/template/revised-frontend/Scrollery_mine.tpl`
- Create: `orkui/template/revised-frontend/Scrollery_index.tpl`
- Modify: `orkui/controller/controller.Scrollery.php`
- Modify: `orkui/model/model.Scrollery.php`
- Modify: `orkui/template/revised-frontend/Playernew_index.tpl` (Awards tab action cell)
- Test: `tests/Unit/ScrolleryPermissionTest.php`

**Interfaces:**
- Consumes: `Model_Scrollery::scroll_for_awards_id()` from Task 6.
- Produces: `Model_Scrollery::printable_awards_for_player(int $mundaneId): array` — the
  player's award rows filtered to those with certificate art, each with a `PrintUrl`.

> **Decision taken during Task 4 — the model must supply the real park title.**
> The issuer line reads "and the &lt;title&gt; of &lt;name&gt;". `AwardsForPlayer()` returns
> `ParkName` but not the park's rank word, and `Barony` is wrong for most parks (349
> Freeholds, 274 Grand Duchies, ~90 Baronies). So `model.Scrollery` joins
> `ork_park -> ork_parktitle` on the award's `at_park_id` and passes the true word to
> `BuildScroll()` as `$context['issuerTitle']`. This means wrapping `AwardsForPlayer()`
> rather than using its rows untouched — the reuse the spec asks for still holds, the
> wrapper just decorates each row. `Barony`/`Kingdom` remains the fallback for rows
> whose park cannot be resolved.

- [ ] **Step 1: Write the failing permission test**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScrolleryPermissionTest extends TestCase
{
    public function testPrintableAwardsExcludeAwardsWithNoArt(): void
    {
        $rows = [
            ['AwardId' => 33,  'Rank' => 3],   // griffon — has art
            ['AwardId' => 1,   'Rank' => 0],   // Master Rose — none
            ['AwardId' => 243, 'Rank' => 7],   // battle — has art
            ['AwardId' => 999, 'Rank' => 1],   // unknown — none
        ];

        $printable = array_values(array_filter($rows, fn ($r) => Scroll::HasArt($r)));

        $this->assertCount(2, $printable);
        $this->assertSame(33, $printable[0]['AwardId']);
        $this->assertSame(243, $printable[1]['AwardId']);
    }
}
```

Run it: `vendor/bin/phpunit --testsuite unit --filter ScrolleryPermission`. It passes
immediately — `Scroll::HasArt()` already exists. It is here to pin the "no dead buttons"
rule so a later change cannot quietly start offering unprintable awards.

- [ ] **Step 2: Add `mine()` to the controller**

```php
    public function mine($action = null)
    {
        if (!isset($this->session->user_id)) {
            header('Location: ' . UIR . 'Login/login/Scrollery/mine');
            return;
        }

        $mundaneId = (int) $this->session->user_id;
        $this->data['Awards']    = $this->Scrollery->printable_awards_for_player($mundaneId);
        $this->data['MundaneId'] = $mundaneId;
        $this->template          = '../revised-frontend/Scrollery_mine.tpl';
    }
```

- [ ] **Step 3: Add the model method**

```php
    /** @return array<int, array> the player's awards that have certificate art */
    public function printable_awards_for_player(int $mundaneId): array
    {
        $response = Ork3::$Lib->player->AwardsForPlayer(['MundaneId' => $mundaneId]);
        $printable = [];

        foreach ($response['Awards'] ?? [] as $row) {
            if (!Scroll::HasArt($row)) {
                continue;
            }
            $row['Title']    = Scroll::ResolveTitle($row);
            $row['PrintUrl'] = UIR . 'Scrollery/render&awards=' . (int) $row['AwardsId'] . '&print=1';
            $printable[]     = $row;
        }

        return $printable;
    }
```

- [ ] **Step 4: Write `Scrollery_mine.tpl`**

Use the existing chrome vocabulary — `adm-wrap`, `adm-page-header`, `adm-card`,
`adm-table`, `adm-btn adm-btn-primary` — so it matches the rest of the revised frontend.
Include `revised.css` **then** `scrollery.css`, both with `filemtime()` stamps, following
`Eventnew_index.tpl:134`.

One row per printable award: title, rank, date, where it was given, and a Print button
targeting `PrintUrl` with `target="_blank"`. Above the table, one line of copy explaining
that awards without certificate artwork are not listed. Empty state: a short message and
a link to the player's profile.

- [ ] **Step 5: Write `Scrollery_index.tpl`**

The landing page: three `adm-card`s, one per door, each linking to `Scrollery/mine`,
`Scrollery/create/...` and `Scrollery/bulk/...`. Show the officer cards only when the
viewer holds `AUTH_EDIT` on at least one park or kingdom. Add the artwork credit from
`NOTICE` in a footer line.

- [ ] **Step 6: Add the entry point to the Awards tab**

In `Playernew_index.tpl`, inside the existing `pn-award-actions-cell` (line ~2185 and its
twin at ~2334, plus the JS-built cell at ~7428), add a Print button styled
`pn-award-action-btn`, rendered **only** when the row's award has art and the viewer is
the profile owner or an officer over the park/kingdom. Reuse the same visibility flag the
edit/delete buttons already compute rather than inventing a second check.

- [ ] **Step 7: Verify in the browser**

- Log in as a player with ladder awards; open `Scrollery/mine`; print one.
- Confirm a Master award and a non-ladder award are absent from the list.
- Log in as a different, non-officer player and request another player's `awards` id
  directly on the render route — expect a redirect, not a certificate.

- [ ] **Step 8: Run the suite and commit**

```bash
vendor/bin/phpunit --testsuite unit
git add orkui/ tests/Unit/ScrolleryPermissionTest.php
git commit -m "Enhancement: Scrollery — reprint my scroll"
```

---

### Task 16: "Print a new scroll"

**Files:**
- Create: `orkui/template/revised-frontend/Scrollery_create.tpl`
- Create: `orkui/controller/controller.ScrolleryAjax.php`
- Modify: `orkui/controller/controller.Scrollery.php`

**Interfaces:**
- Produces: `Controller_ScrolleryAjax::preview()` — returns `{ html: string }` for one
  scroll built from POSTed parameters, after the same permission check.

- [ ] **Step 1: Add `create()` to the controller**

Route is `Scrollery/create/<kingdom|park>/<id>`. Gate on
`HasAuthority($uid, AUTH_PARK|AUTH_KINGDOM, $id, AUTH_EDIT)`, mirroring
`Controller_Award::handle_award_route()` (`controller.Award.php:48`). On failure redirect
to `Login/login/Scrollery/create/...`.

Pass to the template: the award list for that kingdom (`Award::GetAwardList()`,
`class.Award.php:64`) filtered to awards with art; the park/kingdom name for the issuer
fields; and the resolved heraldry URL.

- [ ] **Step 2: Write `Scrollery_create.tpl`**

Two columns in an `adm-card`: controls on the left, live preview on the right.

Controls: recipient (reuse the existing player autocomplete — `pn-ac-results` and
friends), award (reuse the `aw-picker-trigger` / `aw-pick-item` dropdown, which already
lists kingdomaward rows by name and so handles the archery variants without any special
control), rank, variant when the order has them, reason, date, given-by, and the issuer
title/name with their "the"/"of" toggles.

Preview: the same `<svg>`/`<foreignObject>` frame as `Scrollery_render.tpl`, filled by
the Ajax response. A Print button opens `Scrollery/render` in a new tab with the current
parameters.

- [ ] **Step 3: Write `controller.ScrolleryAjax.php`**

Follow `controller.WnAjax.php` — `header('Content-Type: application/json')`, a login
check returning `['Status' => ['Status' => 1, 'Error' => ...]]`, then the work. Build the
scroll with `Scroll::BuildScroll()` and return `Scroll::Render()` output. Re-run the
permission check here too; an Ajax endpoint is not exempt.

Add `Controller_ScrolleryAjax` to the `$_skipTokenCheck` list in
`system/lib/system/class.Controller.php:37-48`, alongside the other `*Ajax` controllers.

- [ ] **Step 4: Verify in the browser**

Pick an award, change rank and variant, watch the preview re-render. Print. Confirm a
non-officer hitting the same route is redirected.

- [ ] **Step 5: Run the suite and commit**

```bash
vendor/bin/phpunit --testsuite unit
git add orkui/ system/lib/system/class.Controller.php
git commit -m "Enhancement: Scrollery — print a new scroll"
```

---

### Task 17: "Print bulk scrolls"

**Files:**
- Create: `orkui/template/revised-frontend/Scrollery_bulk.tpl`
- Modify: `orkui/controller/controller.Scrollery.php`
- Modify: `orkui/model/model.Scrollery.php`
- Test: `tests/Unit/ScrolleryBulkQueryTest.php`

**Interfaces:**
- Produces: `Model_Scrollery::awards_for_event(int $eventId): array` and
  `Model_Scrollery::awards_for_scope(string $type, int $id, string $from, string $to): array`.

This is the one genuinely new query in the module. Everything else reuses
`AwardsForPlayer()`.

- [ ] **Step 1: Write the failing test**

Test the SQL builder in isolation — assert the generated statement filters on
`at_event_id` (or `at_park_id`/`at_kingdom_id` plus a date range), joins
`kingdomaward`/`award`/`mundane` the same way `AwardsForPlayer()` does, and that ids are
cast to `int` before interpolation. Do **not** hit the database; extract the SQL into a
`public function bulkSqlForTest(string $type, string $id, string $from, string $to): string`
and assert on the string. (Named `...ForTest` rather than made private so the test needs no
reflection; it builds SQL and touches nothing.)

```php
    public function testEventQueryFiltersOnAtEventIdAndCastsTheId(): void
    {
        $sql = (new Model_Scrollery())->bulkSqlForTest('event', '17 OR 1=1', '', '');
        $this->assertStringContainsString('at_event_id = 17', $sql);
        $this->assertStringNotContainsString('1=1', $sql);
    }
```

- [ ] **Step 2: Run it to make sure it fails**, then implement the query and re-run to PASS.

- [ ] **Step 3: Add `bulk()` to the controller**

Same `AUTH_EDIT` gate as `create()`. Two selection modes: by event, or by park/kingdom
over a date range. Filter the result to rows with art and report the count that was
excluded, so an officer is not silently handed fewer scrolls than awards.

- [ ] **Step 4: Write `Scrollery_bulk.tpl`**

An `adm-table` of matching awards with a checkbox per row and a select-all. The Print
button opens `Scrollery/render&awards=<comma-separated ids>&print=1`. Show the excluded
count as an `adm-alert` above the table, naming the awards that have no artwork.

- [ ] **Step 5: Verify a real multi-page print**

Select at least six awards spanning **three different orders**, print to PDF, and check:
one certificate per page, no blank pages, and — critically — that each certificate keeps
its own `SignAndDate` height. If they all share one height, the per-order CSS is not
properly `[data-order]`-scoped; go back to Task 5.

- [ ] **Step 6: Run the suite and commit**

```bash
vendor/bin/phpunit --testsuite unit
git add orkui/ tests/Unit/ScrolleryBulkQueryTest.php
git commit -m "Enhancement: Scrollery — bulk scroll printing"
```

---

### Task 18: Live overrides

**Files:**
- Create: `orkui/template/revised-frontend/script/scrollery.js`
- Modify: `orkui/template/revised-frontend/Scrollery_create.tpl`
- Modify: `orkui/template/revised-frontend/style/scrollery.css`

Heraldry adjustment and the text fields are pure CSS-custom-property and text-content
changes, so they need no round trip. Only order, rank and variant re-fetch.

- [ ] **Step 1: Publish the heraldry variables on the frame**

In `Scrollery_render.tpl` and the create preview, put them on the `<svg>`:

```php
style="...;--heraldry-filter:brightness(1) contrast(1);--heraldry-transform:translateX(0%) translateY(0%) scale(1);"
```

`_heraldry.tpl` already consumes them (Task 5).

- [ ] **Step 2: Write `scrollery.js`**

```js
(function () {
    'use strict';

    var frame = document.querySelector('[data-scroll-frame]');
    if (!frame) { return; }

    var heraldry = { brightness: 1, contrast: 1, scale: 1, x: 0, y: 0 };

    function applyHeraldry() {
        frame.style.setProperty('--heraldry-filter',
            'brightness(' + heraldry.brightness + ') contrast(' + heraldry.contrast + ')');
        frame.style.setProperty('--heraldry-transform',
            'translateX(' + heraldry.x + '%) translateY(' + heraldry.y + '%) scale(' + heraldry.scale + ')');
    }

    document.querySelectorAll('[data-heraldry-control]').forEach(function (input) {
        input.addEventListener('input', function () {
            heraldry[input.dataset.heraldryControl] = parseFloat(input.value);
            applyHeraldry();
        });
    });

    document.querySelectorAll('[data-scroll-field]').forEach(function (input) {
        input.addEventListener('input', function () {
            var target = frame.querySelector('[data-scroll-text="' + input.dataset.scrollField + '"]');
            if (target) { target.textContent = input.value; }
        });
    });
})();
```

`_text.tpl` needs matching `data-scroll-text="date"`, `"givenBy"`, `"issuerName"`,
`"issuerTitle"`, `"player"` and `"reason"` attributes on the spans that hold those
values — add them in this task.

Order, rank and variant changes call the Ajax preview from Task 16 and replace the
`<foreignObject>` contents.

- [ ] **Step 3: Add the reset controls**

Each slider gets a reset button restoring 1 (brightness, contrast, scale) or 0
(translate), matching Arcanyst's `SetupPanel`.

- [ ] **Step 4: Verify**

Drag each slider and confirm the heraldry responds with no network request (check the
Network tab). Change the rank and confirm exactly one request. Print and confirm the
adjustments survive into the printed page.

- [ ] **Step 5: Run the suite and commit**

```bash
vendor/bin/phpunit --testsuite unit
git add orkui/
git commit -m "Enhancement: Scrollery — live heraldry and text overrides"
```

---

## Done when

- [ ] `vendor/bin/phpunit --testsuite unit` passes, including ~190 asset-path cases
- [ ] All 16 orders render and have been compared against Arcanyst by eye
- [ ] The three doors work, each with its permission check verified by trying to break it
- [ ] A mixed-order bulk print produces one correct certificate per page
- [ ] `NOTICE` credits Valory Fox and Kisa

## Deferred — not this plan

Recorded in the spec, deliberately out of scope here:

- Per-kingdom theming driving `--primary-color` / `--secondary-color`
- A generic certificate for the Masters, titles, non-ladder and kingdom awards that have no art
- Zodiac's `<<MONTH>>` handling and ranks 11-12
- The headless-Chrome PDF service
- Resolving `hunter`'s real base `award_id`. Confirmed during Task 3 that there is
  nothing to resolve: archery is not one base ladder award. Real archery grants hang off
  `award_id` 0 (no base award), 94 (Custom Award) and 131, under a dozen kingdomaward
  names. The eventual fix is a kingdomaward-name-keyed mapping, not an id lookup. Until
  then the order renders on request but never matches a real award — the safe failure.
- Per-kingdom award name variants on the printed title. Phase 1 prints the kingdom's own
  `ork_kingdomaward.name`, which is "Order of the X" throughout.
