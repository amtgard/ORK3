# Scrollery "Customize Design" Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a collapsed "Customize Design" card to the Scrollery builder, holding two controls:
a Kingdom/Park/Persona heraldry switch, and a Custom Border. The Custom Border replaces any
compatible award's border with the Jovious two-band knot in two user-picked colours, with an
optional "Match scroll style?", plus a Custom Ribbon that swaps the text flourishes for the
Jovious ribbons in one chosen colour.

**Architecture:**
- **Server owns the decisions.** One normaliser (`Scroll::NormaliseDesign`) validates the new
  choices. One data file plus `Scroll::CustomBorderRule()` decides compatibility, blending and
  ground swaps.
- **Templates.** Each compatible order template asks two helpers where to draw the knot.
- **The knot artwork** is exported once from the Affinity source and emitted as one shared
  definition per page.
- **The page.** The builder reads the same rule data, so the checkbox reacts live. Colours
  repaint in place via CSS custom properties; everything else round-trips through the existing
  preview POST.

**Tech Stack:** PHP 8.4 (host PHPUnit), plain-PHP `.tpl` partials, vanilla JS
(`script/scrollery.js`), CSS (`scrollery.css` / builder styles). Artwork comes from the local,
git-ignored Affinity tool `tools/affinity-extract/` (see its README).

**Spec:** `docs/superpowers/specs/2026-10-27-scrollery-customize-design-design.md`

## Global Constraints

- **Card.** Title "Customize Design", collapsed by default, placed between the Award card and the
  Wording card in `Scrollery_create.tpl`. Use the existing `rp-filter-card` + `data-sc-collapse`
  pattern.
- **Copy.**
  - Heraldry buttons: "Kingdom", "Park", "Persona".
  - Checkboxes: "Custom Border", "Match scroll style?" and "Custom Ribbon".
  - Ribbon colour label: "Ribbon Color".
  - Colour labels: "Color 1" and "Color 2".
  - Incompatible tooltip, exact: `This award style is not compatible with custom borders.`
  - Persona-disabled tip: `Choose a recipient below first`.
- **Default colours.** Color 1 `#6AC1FF` (the Jovious "Primary" band), Color 2 `#E84C92` (the
  Jovious "Secondary" band). "Match scroll style?" is unchecked by default. Hex values are
  normalised to uppercase `#RRGGBB`.
- **Tooltips** use `data-tip`, never `title`. No native `confirm` / `alert` / `prompt`.
- **Themes and phones.** Dark mode via `html[data-theme="dark"]`; check computed styles in both
  themes, and at phone widths (16px gutter, no horizontal scroll).
- **Layering.** SQL only in `system/lib/ork3/`; `orkui/model` is the only membrane. `.tpl` files
  are plain PHP, not Smarty.
- **Commits.**
  - Stage by pathspec only, never `git add -A`, and run `git diff --cached` before each commit.
  - **Never stage `system/lib/ork3/class.Authorization.php`.**
  - Commit message prefix `Enhancement:` (or `Fix:`). End each message with
    `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Tests.** Run `php vendor/bin/phpunit --no-coverage --filter Scroll` on the **host** (the
  container's PHP is too old); it must stay green. The current baseline is 789 passing on
  `scrollery`.
- **Bulk / Court Planner / reprint paths** pass no design, so they must render exactly as today.
- **Never present unverified art.** Every exported asset is eyeballed against its source before
  it is committed.

## Review Focus

1. **Hex input in every shape:**
   - accepted: `6ac1ff`, `#6AC1FF`;
   - rejected: `#abc`, `red`, `''`, `javascript:alert(1)`, `#6AC1FF;background:url(x)`.
   - Only uppercase `#RRGGBB` may reach a style attribute; anything else falls back to the
     default. → Task 2 test `testHexColoursAreNormalisedOrFallBack`.
2. **Award/rank/ground changes while Custom Border is ticked.** Switching to an incompatible award,
   to warrior rank 10 with no clean ground, or to a framed custom ground: the server draws the
   original border, and the page hides the checkbox while keeping its state, so switching back
   restores it. → Task 2 `testIncompatibleOrdersIgnoreTheRequest`, Task 5 browser check.
3. **Persona heraldry that disappears.** Persona chosen, then the recipient cleared, or changed to
   someone with no arms: the effective source falls back to the scope default and the button
   disables. → Task 4 `testPersonaWithoutArmsFallsBackToTheScopeDefault`.
4. **Hand-edited URLs:**
   - `heraldrySource=../x`;
   - `customBorder=yes` on an incompatible order;
   - `matchStyle=1` with no custom border.
   All are normalised with no error and no effect. → Task 2 `testDesignNormalisesHostileInput`.
5. **Regression.** A scroll built with no `design` in the context (bulk, court, reprint) has
   `customBorder === null` and renders byte-identical markup to before. → Task 3
   `testNoDesignRendersExactlyAsBefore`.

---

### Task 1: Knot artwork + border-free grounds (spike, export, verify)

**Files:**
- Create the knot artwork. The format is decided in Step 1:
  - vector: `assets/scrolls/common/knot/knot.svg`, the cleaned source of the shared defs;
  - raster: `assets/scrolls/common/knot/band-1.png` and `band-2.png`, each 5100×6600 and listed
    in `assets/scrolls/hires-masters.txt` at scale 2.
- Create: `assets/scrolls/jovious/bg-noborder.jpg` (2550×3300, matching `jovious/bg.jpg`'s size).
- Create, only if clean: `assets/scrolls/warrior/bg_10-noborder.jpg`, and
  `flame/` / `garber/` / `zodiac/` border-free grounds.
- Create: `docs/superpowers/scrollery-custom-border-art.md`, the verdict record: format chosen
  and why, each investigated order's verdict with its source layer paths, and file sizes.
- Local only, never committed: a preset per investigated order in
  `tools/affinity-extract/recolor.py`.

**Interfaces:**
- **Produces:** the file paths above, plus the knot's channel contract.
  - **Band 1 = Color 1**: the Jovious "Primary" band group, master 1005 `/3/8` of
    `Award Templates Trim.af`.
  - **Band 2 = Color 2**: `/3/9`.
  - Each band is white fill + black outline, transparent elsewhere, in register with the
    2550×3300 page.
  - The verdict list feeds Task 2's data file.

- [ ] **Step 1: Spike the vector export.**
  - Using `tools/affinity-extract` (`afmcp.py run`, patterned on `js/recolor.js`), on master 1005
    of `Award Templates Trim.af`:
    - hide everything but `/3/8` and `/3/9`;
    - remove each band group's Colour Overlay with
      `DocumentCommand.createRemoveColourOverlayLayerEffect(sel, 0)`;
    - export with preset `SVG (for export)` for the current spread;
    - undo; never save.
  - **Pass condition:** the SVG contains no `<image>`, the knot is fully present (render it and
    diff its alpha against `border-original.png` from `recolor.py jovious`: mean alpha error
    < 0.01), and the file is ≤ 1.5 MB.
- [ ] **Step 2: Decide the format and export.**
  - **If Step 1 passed:** clean the SVG into two `<symbol>` groups whose white fills read
    `var(--knot-c1)` / `var(--knot-c2)`.
  - **Otherwise:** export each band at scale 2 with its overlay set to `#FFFFFF`, via
    `recolor.py`-style `createSetColourOverlayLayerEffectColour`.
  - **Check:** composited with Color 1/2 = `#6AC1FF` / `#E84C92`, the result matches
    `border-original.png` within mean RGB error 0.02 on opaque pixels.
- [ ] **Step 3: Export `jovious/bg-noborder.jpg`.** Master 1005, with `/3/8` and `/3/9` hidden,
  at 2550×3300.
  - **Pass:** eyeball the full plate plus 4 corner crops; no band remnants. The softer linen
    texture is accepted by the owner.
- [ ] **Step 4: Investigate flame, garber, zodiac and warrior rank 10.**
  - Find each plate's border layers in the inventories (`~/Desktop/Arcanyst Extract/out/inventory/`):
    - flame in `Flame.afpub` / Trim master 1010;
    - garber in Trim pages 40–50 / master 1003;
    - zodiac in Trim page 92 / master 1002;
    - warrior bg_10 in `warrior.afpub`.
  - **If** the border is a separable layer group, export the plate without it.
  - **Pass**, per order: the full plate plus 4 corner crops show no remnant, and the rest of the
    plate matches the shipped ground (mean RGB error < 0.08).
  - Record each verdict (compatible + path, or incompatible + reason) in the verdict doc.
- [ ] **Step 5: Run the art pipeline checks.**
  - Run `sh bin/build-scroll-renditions.sh`, then `php bin/build-scroll-ink-extents.php`.
  - Expected: `sh bin/build-scroll-renditions.sh --check` reports all plates up to date.
  - Then run `php vendor/bin/phpunit --no-coverage --filter Scroll`; expected: all pass.
- [ ] **Step 6: Commit.** Stage the new asset files, their renditions, `renditions.json`,
  `ink-extents.json`, `hires-masters.txt` (only if it changed) and the verdict doc. Message:
  `Enhancement: Scrollery — Jovious knot artwork and border-free grounds for custom borders`.

### Task 1R: Ribbon artwork (neutral, tintable)

**Files:**
- Create: `assets/scrolls/common/ribbon/ribbon-top.png`, `ribbon-right.png`, `ribbon-bottom.png`.
  - Each has the SAME pixel dimensions and alpha as `assets/scrolls/jovious/ribbon-<name>.png`
    (1242×432, 812×726, 892×432).
  - Their RGB is a neutral tint base (see Interfaces).
- Modify: `docs/superpowers/scrollery-custom-border-art.md`. Add a Ribbon section: source used,
  `RIBBON_DEFAULT` hex, and the acceptance numbers.
- Regenerate whatever the art pipeline regenerates for new files: renditions, `renditions.json`,
  `ink-extents.json`.

**Interfaces:**
- **Produces:**
  - The three neutral ribbon files.
  - `RIBBON_DEFAULT` (uppercase `#RRGGBB`): the ribbon base colour. Task 2 copies it into
    `Scroll::DEFAULT_DESIGN['ribbonColor']`.
  - The **tint contract**: `shipped ≈ neutral × RIBBON_DEFAULT` per channel (multiply). Tinting
    = an SVG filter that floods the colour, multiplies it with the image, and clips to the
    image's alpha.

- [ ] **Step 1: Investigate the source.** In `Award Templates Trim.af`, master 1005
  "Overlay (Jovious)", hidden group `/3/5` (three PolyCurve "Curves", fill 225,81,136, box
  ≈ 337,376 1866×502):
  - read its layer effects (pattern: the probe in `tools/affinity-extract`, effects via
    `node.layerEffectsInterface.effects`);
  - export each curve with its group made visible, alone, at scale 2 then at 1×; never save;
    undo everything;
  - compare shapes with the shipped ribbons.
- [ ] **Step 2: Choose the source and build the neutral files.**
  - **If** the source matches the shipped ribbons by eye (outline, stitch line, shading), build
    the neutral art from it. The base colour set to white, via the overlay/fill, gives the
    neutral directly.
  - **Otherwise**, build it from the shipped PNGs:
    - `RIBBON_DEFAULT` = the dominant body colour of the shipped ribbons;
    - neutral RGB = `clip(shipped / RIBBON_DEFAULT, 0, 1)` per channel;
    - alpha copied unchanged.
  - Either way, keep the exact alpha of the shipped files.
- [ ] **Step 3: Verify.**
  - **Numerically:** `neutral × RIBBON_DEFAULT` vs shipped, mean RGB error ≤ 0.03 on pixels
    with alpha > 200, and identical alpha.
  - **By eye:** a contact sheet with the shipped ribbon, plus the neutral tinted
    `RIBBON_DEFAULT`, `#1E4FA3`, `#F6B323` and `#2E7D32`. Stitching and shading must survive
    every colour.
- [ ] **Step 4: Run the art pipeline checks.** Run
  `sh bin/build-scroll-renditions.sh && php bin/build-scroll-ink-extents.php`.
  - `sh bin/build-scroll-renditions.sh --check` must be OK.
  - The ink grid for each `common/ribbon/*` file must equal its `jovious/ribbon-*` counterpart's.
  - Run `php vendor/bin/phpunit --no-coverage --filter Scroll`; expected: all pass.
- [ ] **Step 5: Commit** by pathspec.
  `Enhancement: Scrollery — tintable Jovious ribbon artwork for custom ribbons`.

### Task 2: Design normalisation + compatibility rules (lib)

**Files:**
- Create: `system/lib/ork3/data/scroll-custom-border.php`
- Modify: `system/lib/ork3/class.Scroll.php`, next to `NormaliseRecipe()` (~L664) and in
  `BuildScroll()`'s return array (~L1950).
- Test: `tests/Unit/ScrollDesignTest.php`

**Interfaces:**
- **Consumes:** Task 1's verdict list and asset paths.
- **Produces:**
  - `Scroll::DEFAULT_DESIGN = ['heraldrySource' => '', 'customBorder' => false, 'color1' => '#6AC1FF', 'color2' => '#E84C92', 'matchStyle' => false, 'customRibbon' => false, 'ribbonColor' => RIBBON_DEFAULT /* from Task 1R */]`
    - `heraldrySource` `''` means the scope default.
  - `Scroll::HERALDRY_SOURCES = ['kingdom', 'park', 'persona']`
  - `Scroll::NormaliseHexColour(string $raw, string $fallback): string` returns uppercase
    `#RRGGBB`.
  - `Scroll::NormaliseDesign(array $requested): array`
    - It has the same keys as `DEFAULT_DESIGN`.
    - Bools accept `'1'`, `1`, `true`, `'true'`, `'on'`; anything else is false.
  - `Scroll::CustomBorderRules(): array` returns the raw data file, cached like `Recipes()`.
  - `Scroll::CustomBorderRule(string $orderId, int $rank, string $variant, array $recipe): ?array`
    - Returns `null` when the combination is incompatible.
    - Otherwise returns `['passes' => string[], 'ground' => ?string]`:
      - `passes` = the style attribute of each replaced border pass, used in match-style mode;
      - `ground` = the replacement ground path relative to `assets/scrolls/`, or `null`.
  - New `BuildScroll()` keys:
    - `'design' => NormaliseDesign($context['design'] ?? [])`
    - `'customRibbon' => ?string`: the normalised `ribbonColor` when `design.customRibbon` is
      true, else `null`. Every order is eligible.
    - `'customBorder' => ?array`, which is `null` unless `design.customBorder` is true AND
      `CustomBorderRule(...)` is non-null. When set, it is
      `['color1' => …, 'color2' => …, 'matchStyle' => bool, 'passes' => [...], 'ground' => ?string]`.
- **Data file schema** (an absent order = incompatible):

```php
return [
  // passes: one style string per border pass the order draws, copied VERBATIM from the
  // order's template, in order. owl's border is inverted line art, so its passes are
  // Scroll::BORDER_STYLE_DEFAULT['light'] (parchment, transparency) / ['dark'] (cyanotype),
  // keyed by variant.
  'crown'   => ['passes' => ['mix-blend-mode:multiply;', 'opacity:0.5;']],
  'owl'     => ['passes' => ['parchment' => [...], 'cyanotype' => [...], 'transparency' => [...]]],
  'jovious' => ['passes' => ['mix-blend-mode:multiply;'], 'ground' => 'jovious/bg-noborder.jpg'],
  'warrior' => ['passes' => [/* copied */], 'ranks' => [10 => 'warrior/bg_10-noborder.jpg' /* or false */]],
  'custom'  => ['passes' => ['mix-blend-mode:multiply;'],
                'recipeGrounds' => ['jovious' => 'jovious/bg-noborder.jpg', 'sunburst' => /* path|false */]],
  // …one entry per compatible order: crown, hunter, hydra, lion, mask, owl, rose, smith,
  // warrior, battle, custom, dragon, griffon, jovious, plus flame/garber/zodiac per
  // Task 1's verdict.
];
```

- **How `CustomBorderRule` reads the schema:**
  - `passes` may be keyed by variant.
  - `ranks[n] === false` means incompatible at that rank; a string means a ground swap at that rank.
  - For `custom`, any ground in `groundsWithOwnBorder` that is not mapped to a string in
    `recipeGrounds` is incompatible.

- [ ] **Step 1: Write the failing tests** in `tests/Unit/ScrollDesignTest.php`:
  - `testDefaultsWhenNothingRequested`: `NormaliseDesign([])` equals `Scroll::DEFAULT_DESIGN`.
  - `testHexColoursAreNormalisedOrFallBack`:
    - `'6ac1ff'` → `'#6AC1FF'`; `'#e84c92'` → `'#E84C92'`;
    - `'#abc'`, `'red'`, `''`, `'javascript:alert(1)'`, `'#6AC1FF;background:url(x)'` → the
      fallback.
  - `testDesignNormalisesHostileInput`:
    - `['heraldrySource' => '../x', 'customBorder' => 'yes', 'matchStyle' => '1', 'color1' => '<b>']`
      → `heraldrySource ''`, `customBorder false`, `matchStyle true`, `color1 '#6AC1FF'`;
    - `'persona'` survives.
  - `testCompatibleOrdersReturnTheirPasses`:
    - `CustomBorderRule('crown', 3, '', [])['passes'] === ['mix-blend-mode:multiply;', 'opacity:0.5;']`;
    - `CustomBorderRule('jovious', 1, '', [])['ground'] === 'jovious/bg-noborder.jpg'`.
  - `testIncompatibleOrdersIgnoreTheRequest`:
    - `BuildScroll` for an order with no rule (pick one Task 1 marked incompatible, or a fixture
      order id absent from the file) with `design.customBorder = '1'` → `customBorder === null`;
    - same for warrior rank 10 if Task 1 marked it `false`.
  - `testCustomOrderFramedGrounds`:
    - `recipe.ground 'jovious'` → ground swap;
    - `recipe.ground 'linen'` (absent from `recipeGrounds`) → `null`.
  - `testEveryRuleNamesARealOrderAndRealFiles`: every key is in `Scroll::Orders()`, and every
    `ground` / `ranks` / `recipeGrounds` string exists under `DIR_ASSETS . 'scrolls/'`.
  - `testRibbonColourNormalisedAndOnlyWhenTicked`:
    - `{customRibbon: '1', ribbonColor: '2e7d32'}` → `$s['customRibbon'] === '#2E7D32'`;
    - `{ribbonColor: '#2E7D32'}` alone → `null`;
    - a hostile `ribbonColor` → `RIBBON_DEFAULT`.
  - `testBuildScrollCarriesDesignAndResolvedBorder`:
    - crown with design `{customBorder: '1', color1: '#1e4fa3'}` → `$s['customBorder']['color1'] === '#1E4FA3'`;
    - `$s['customBorder']['passes']` is crown's.
- [ ] **Step 2: Run, verify they fail.**
  `php vendor/bin/phpunit --no-coverage --filter ScrollDesignTest` → FAIL (undefined
  constant/method).
- [ ] **Step 3: Implement** the constants, the four methods, the data file and the two
  `BuildScroll()` keys.
  - `BuildScroll` uses the clamped `$rank`, the resolved `$variant`, and the normalised recipe
    (call `NormaliseRecipe` for `custom` as today; pass `[]` otherwise).
- [ ] **Step 4: Run, verify pass.** `--filter ScrollDesignTest` → PASS, then `--filter Scroll` →
  all pass.
- [ ] **Step 5: Commit.** Stage the data file, `class.Scroll.php` and the test.
  `Enhancement: Scrollery — design normaliser and custom-border compatibility rules`.

### Task 3: Knot partial, template helpers, wire every compatible order

**Files:**
- Create: `orkui/template/revised-frontend/scroll/_knot.tpl` (per certificate) and
  `scroll/_knot_defs.tpl` (once per page, if vector).
- Modify:
  - `scroll/_filters.tpl`, which already emits page-level defs once: emit the knot defs there,
    unconditionally, the same way and for the same cache reason documented in `_border.tpl`.
  - `system/lib/ork3/class.Scroll.php`, the helper functions near `scroll_art_attrs()` (~L2610).
  - Each compatible order's `scroll/<order>.tpl`.
  - `scrollery.css`: the `.sc-knot` rules.
- Modify: `scroll/_text.tpl`, the flourish `<img>`s at ~L157, L158, L168, L174, L175.
- Test: `tests/Unit/ScrollCustomBorderRenderTest.php`

**Interfaces:**
- **Consumes:** Task 2's `$s['customBorder']`; Task 1's asset paths.
- **Produces:**
  - `_knot.tpl` takes `$color1`, `$color2` and `$style`. It emits ONE absolutely-positioned
    full-page (2550×3300) element with class `sc-knot`, carrying inline
    `--knot-c1:<color1>;--knot-c2:<color2>;` plus `$style`. The page JS (Task 5) repaints by
    setting those two properties on every `.sc-knot`.
  - `scroll_custom_border_slot(array $s, string $mode): void`, with `$mode` ∈ `'match'|'exact'`:
    - it echoes nothing unless `$s['customBorder']` is set AND its `matchStyle` equals
      (`$mode === 'match'`);
    - in `'match'` it emits `_knot.tpl` once per entry in `passes`, with that style;
    - in `'exact'` it emits it once, with `mix-blend-mode:normal;`.
  - `scroll_custom_border_active(array $s): bool`
  - **Custom ribbon** (Task 1R's tint contract):
    - When `$s['customRibbon']` is set, `_text.tpl` uses `scrolls/common/ribbon/ribbon-<name>.png`
      for the three flourish resources, and the two `ScRule`s use the bottom one.
    - The same ink grids apply, because the alpha is identical.
    - Each flourish `<img>` gets `class="Flourish sc-ribbon"` and inline
      `filter:url(#sc-ribbon-tint)`.
    - `_text.tpl` emits, once per certificate, a zero-size inline
      `<svg aria-hidden="true" width="0" height="0"><filter id="sc-ribbon-tint" color-interpolation-filters="sRGB">`.
      The filter is: an `feFlood` carrying `flood-color` = the colour and class
      `sc-ribbon-flood`, then `feBlend mode="multiply"` with `SourceGraphic`, then
      `feComposite operator="in"` with `SourceAlpha`.
    - Page JS repaints by setting `flood-color` on every `.sc-ribbon-flood`.
    - Without `customRibbon`, `_text.tpl` output is unchanged.
  - `scroll_custom_ground(array $s, string $default): string` returns `customBorder['ground']`
    when active and non-null, else `$default`.
- **Wiring rule for each compatible template:**
  - wrap the order's border pass(es) in `<?php if (!scroll_custom_border_active($s)): ?>…<?php endif; ?>`;
  - call `scroll_custom_border_slot($s, 'match')` where those passes were;
  - call `scroll_custom_border_slot($s, 'exact')` immediately before the order's symbol/rank
    layer. That is above the ground and any whole-page wash, below the symbol and text; the knot
    never overlaps the top-centre heraldry.
  - For the `_border.tpl` orders (battle, custom, dragon incl. its glow pass, griffon incl. the
    griffon plate), the `scroll_partial('border', …)` calls are the passes.
  - Baked-ground orders change their ground `<img>` to
    `scroll_art_attrs(scroll_custom_ground($s, '<order>/bg.jpg'))`.

- [ ] **Step 1: Write the failing tests** in `ScrollCustomBorderRenderTest.php`. Pattern on
  `ScrollPartialsTest::scroll()` to build `$s`, then render with `Scroll::Render()`.
  - `testNoDesignRendersExactlyAsBefore`: for every order id in `Scroll::Orders()`, `Render()`
    of a design-less scroll contains no `sc-knot`, and still contains its own border asset name
    where it has one (e.g. `crown/border` for crown).
  - `testCrownExactModeReplacesBothPasses`: `customBorder` set, `matchStyle false` → output
    contains `sc-knot` exactly once, with `--knot-c1:#1E4FA3`, and does not contain
    `crown/border`.
  - `testCrownMatchModeDrawsOneKnotPerPass`: `matchStyle true` → `sc-knot` twice; the styles
    contain `mix-blend-mode:multiply` and `opacity:0.5`.
  - `testJoviousSwapsItsGround`: output contains `jovious/bg-noborder` and not `jovious/bg.jpg`
    (match the `ArtUrl` form used in markup).
  - `testEveryCompatibleOrderDrawsTheKnotAndDropsItsBorder`: loop over `Scroll::CustomBorderRules()`
    keys (using a rank/variant each rule accepts); each renders `sc-knot`, and none of the
    asset names listed in a per-order expectation map (`crown/border`, `rose/border`,
    `owl/border`, `warrior/border_`, …, and `<svg viewBox="0 0 2550 3300"` with
    `--primary-color` for the `_border.tpl` orders).
  - `testCustomRibbonSwapsAllFiveFlourishes`:
    - crown with `customRibbon` `'#2E7D32'` → output contains `common/ribbon/ribbon-top`, `-right`
      and `-bottom`, `sc-ribbon-tint`, and `flood-color="#2E7D32"`;
    - it no longer contains `squiggly-top`.
  - `testNoRibbonLeavesFlourishesAlone`: design-less crown contains `squiggly-top` and no
    `sc-ribbon`.
  - `testColoursOnlyReachMarkupNormalised`: a hostile `color1` in the design never appears in
    output; `#6AC1FF` does.
- [ ] **Step 2: Run, verify they fail.** `--filter ScrollCustomBorderRenderTest` → FAIL.
- [ ] **Step 3: Implement** `_knot.tpl`, the defs (if vector), the three helpers, the CSS, and
  the template wiring for every order in the data file.
- [ ] **Step 4: Run, verify pass.** `--filter ScrollCustomBorderRenderTest`, then `--filter Scroll`
  (watch `ScrollPartialsTest`, `ScrollRenderCacheTest`, `ScrollSweepCoverageTest`) → all pass.
- [ ] **Step 5: Commit.** Stage the partials, the templates, `class.Scroll.php`, the CSS and the
  test. `Enhancement: Scrollery — draw the custom knot border in place of any compatible order's border`.

### Task 4: Heraldry source + design params through preview, print and the print log

**Files:**
- Modify:
  - `system/lib/ork3/class.Scroll.php`: one pure resolver.
  - `orkui/model/model.Scrollery.php`: the heraldry lookups, beside `scope_context()` /
    `heraldry_url()`.
  - `orkui/controller/controller.ScrolleryAjax.php`: `preview()` (~L18–180).
  - `orkui/controller/controller.Scrollery.php`: `compose()` (~L664) and `record_print()` (~L877).
- Test: `tests/Unit/ScrollHeraldrySourceTest.php`

**Interfaces:**
- **Consumes:** Task 2's `NormaliseDesign()` and `$s['design']`.
- **Produces:**
  - `Scroll::ResolveHeraldrySource(string $requested, string $scopeType, array $available): string`
    - `$available` = `['kingdom' => bool, 'park' => bool, 'persona' => bool]`.
    - It returns `$requested` if that source is available.
    - Otherwise it returns the scope default (`'kingdom'` for a kingdom scope, `'park'` for a
      park scope).
    - It never returns an unavailable source except the scope default; a missing default
      simply draws no arms, as today.
  - `model.Scrollery::heraldry_sources(string $scopeType, int $scopeId, int $playerId): array`
    - It returns `['kingdom' => string, 'park' => string, 'persona' => string]` of master URLs,
      `''` when there are none.
    - **Kingdom:** for a park scope, the park's `KingdomId`.
    - **Park:** the scope's park; `''` on a kingdom scope (spec allowance, kept minimal).
    - **Persona:** `Ork3::$Lib->player->GetPlayer(...)` `HasHeraldry`, then
      `GetHeraldryUrl(['Type' => 'Player', 'Id' => $playerId, 'Size' => 'master'])`; `''` when
      `$playerId <= 0` or there are no arms.
  - **Request params**, on both the preview POST and the compose GET:
    `heraldrySource`, `customBorder`, `color1`, `color2`, `matchStyle`, `customRibbon`,
    `ribbonColor`.
    - Pass them to `BuildScroll()` as `$context['design']`.
    - `$context['heraldry']` becomes the URL for the resolved source.
  - **Preview JSON response** gains:
    - `HeraldrySources` → `{kingdom: bool, park: bool, persona: bool}`;
    - `HeraldrySource` → the resolved source string.
  - **`record_print`:** `'Recipe' => array_merge($scroll['recipe'] ?? [], ['design' => $scroll['design']])`.
    - The print log's `recipe_json` now carries the design, and the custom recipe keys are
      unchanged.

- [ ] **Step 1: Write the failing tests** in `ScrollHeraldrySourceTest.php`:
  - `testDefaultsToTheScope`: `('', 'kingdom', all true)` → `'kingdom'`; `('', 'park', all true)`
    → `'park'`.
  - `testHonoursAnAvailableChoice`: `('persona', 'park', persona true)` → `'persona'`.
  - `testPersonaWithoutArmsFallsBackToTheScopeDefault`: `('persona', 'park', persona false)` →
    `'park'`; `('persona', 'kingdom', persona false)` → `'kingdom'`.
  - `testParkOnAKingdomScopeFallsBack`: `('park', 'kingdom', park false)` → `'kingdom'`.
  - `testUnknownSourceIsTheDefault`: `('../x', 'park', …)` → `'park'`.
- [ ] **Step 2: Run, verify they fail.** `--filter ScrollHeraldrySourceTest` → FAIL.
- [ ] **Step 3: Implement** the resolver, `heraldry_sources()`, the param plumbing in `preview()`
  and `compose()`, the response fields, and the `record_print` merge.
- [ ] **Step 4: Verify.**
  - Run `--filter Scroll` → all pass.
  - Then, logged in locally (see the memory note on local curl auth):
    - POST `ScrolleryAjax/preview` with `heraldrySource=persona&playerId=<a player with heraldry>`
      → the response JSON has `HeraldrySources.persona: true`, and the markup's heraldry `<img>`
      src contains `player_heraldry`;
    - repeat with a player without arms → `persona: false` and the scope's arms.
- [ ] **Step 5: Commit.** Stage the four files and the test.
  `Enhancement: Scrollery — heraldry source and custom border travel through preview, print and the print log`.

### Task 5: The "Customize Design" card and its live behaviour

**Files:**
- Modify:
  - `orkui/template/revised-frontend/Scrollery_create.tpl`: the new card between Award (~L316)
    and Wording (~L378), plus cfg data.
  - `orkui/controller/controller.Scrollery.php` `create()`: pass
    `$this->data['CustomBorderRules'] = Scroll::CustomBorderRules()`,
    `$this->data['DesignDefaults'] = Scroll::DEFAULT_DESIGN`, and the scope's kingdom/park
    heraldry availability.
  - `orkui/template/revised-frontend/script/scrollery.js`
  - the builder's CSS (wherever the `sc-*` builder rules live).

**Interfaces:**
- **Consumes:**
  - Task 4's request params and preview response fields;
  - Task 2's rule data;
  - Task 3's `.sc-knot` / `--knot-c1` / `--knot-c2` contract.
- **Produces:** element ids:
  - `sc-design` (card), `sc-design-body`;
  - heraldry buttons `[data-heraldry-source="kingdom|park|persona"]`;
  - `sc-custom-border`, `sc-custom-border-na` (the muted label with the tooltip);
  - `sc-color1`, `sc-color1-hex`, `sc-color2`, `sc-color2-hex`;
  - `sc-match-style`;
  - `sc-custom-ribbon`, `sc-ribbon-color`, `sc-ribbon-color-hex`.
- **JS behaviour:**
  - **Request params.** `addDesign(params)` sets the five params on every preview POST and
    compose GET (call it wherever `addRecipe` is called).
  - **Compatibility** is evaluated in JS from `cfg.customBorderRules`, with the same semantics as
    `Scroll::CustomBorderRule`: order from the award option, clamped rank, variant, and for
    `custom` the ground select.
    - Re-evaluate on award, rank-pill, variant and ground changes.
    - When incompatible, hide the checkbox and colour block and show `sc-custom-border-na`,
      keeping the checked state.
  - **Colours.** The picker and the hex field sync both ways. The hex field accepts the Review
    Focus #1 shapes and flags invalid input inline without applying it.
    - A valid change sets `--knot-c1/--knot-c2` on every `.sc-knot` in the preview mount, with
      no round trip.
    - Toggling `sc-custom-border` or `sc-match-style`, or clicking a heraldry button, calls
      `reload()`.
  - **Heraldry bar.**
    - The initial active button is the scope default.
    - Kingdom/Park are enabled from page data.
    - Persona is enabled from the last preview response's `HeraldrySources.persona`; when
      disabled, it carries `data-tip="Choose a recipient below first"` if no recipient is
      chosen, or a no-arms tip otherwise.
    - The active button follows the response's `HeraldrySource`.
  - **Custom Ribbon.**
    - The checkbox shows the colour row.
    - A valid colour sets `flood-color` on every `.sc-ribbon-flood` in the preview, with no
      round trip.
    - Toggling the checkbox calls `reload()`.
    - It is always available, with no compatibility gate.
  - **Remembered colours.** The last valid colours are kept in `localStorage` key
    `scrollery.customBorder.colors`, as JSON `{c1, c2, ribbon}`. Every access is wrapped in try/catch,
    and the page works without it.
  - **The custom order.** While Custom Border is on and the order is `custom`, disable the
    "Scroll design" border select and palette with a one-line `sc-hint`: "Custom Border is
    drawing this scroll's border."

- [ ] **Step 1: Add the card markup and the cfg data.**
  - Run `php -l` on the template and the controller; expected: no syntax errors.
- [ ] **Step 2: Implement the JS behaviour above.**
  - Run `node --check orkui/template/revised-frontend/script/scrollery.js`; expected: no output.
- [ ] **Step 3: Style the card** in both themes and at phone width (heraldry bar wraps cleanly;
  the colour rows stack).
- [ ] **Step 4: Browser-verify in Chrome** (memory: automation tabs are `document.hidden`; real-click
  inputs before typing). On `Scrollery/create/park/<id>`:
  - the card is collapsed between Award and Wording;
  - **Crown:**
    - tick Custom Border → the preview shows the knot in the default colours with no crown
      border;
    - type `1e4fa3` → the band repaints with no network request;
    - tick Match scroll style → a request fires and the knot blends;
  - **an incompatible style** (from Task 1's verdicts) → the checkbox is hidden and the tooltip
    text appears on hover;
  - **switch back** → still ticked;
  - **Persona** is disabled until a recipient with arms is chosen, then enabled; choosing it
    switches the arms;
  - **Custom Ribbon** on Crown → the three ribbons replace the squiggles, the text does not
    move, and typing `2e7d32` repaints them live;
  - **Print** opens compose with the same knot, ribbons and colours;
  - **dark mode and a 390px-wide frame** (iframe harness): no overflow, readable controls.
- [ ] **Step 5: Commit.** Stage the template, controller, JS and CSS.
  `Enhancement: Scrollery — Customize Design card with heraldry source and custom border`.

### Task 6: Whole-feature verification

**Files:**
- Modify only as fixes require.

- [ ] **Step 1: Contact sheet of every compatible order** with Custom Border on, in exact and
  match modes.
  - Use the login-free render path documented in memory: PHP CLI in `ork3-php8-app` calling
    `Scroll::BuildScroll` + `Scrollery_render.tpl`, with `?face=`.
  - Eyeball each plate for:
    - no remnant of the original border;
    - knot colours exact in exact mode;
    - no text colliding with the knot (the knot spans ≈ x 56–2494, y 52–3256 at 1×);
    - heraldry and symbol untouched.
  - Fix any order that fails, adding a regression assertion to Task 3's test.
- [ ] **Step 2: Regression.** A Court Planner bulk print and a `Scrollery/render` reprint of
  existing awards look unchanged (spot-check 3 orders).
- [ ] **Step 3: Full suite.** Run `php vendor/bin/phpunit --no-coverage --filter Scroll` and
  `sh bin/build-scroll-renditions.sh --check`; expected: all pass.
- [ ] **Step 4: Commit any fixes** by pathspec, `Fix: Scrollery custom border — <what>`.
