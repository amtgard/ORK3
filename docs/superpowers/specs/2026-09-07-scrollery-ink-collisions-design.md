# Scrollery — ink printed on top of ink

**Date:** 2026-09-07
**Status:** approved, ready for implementation planning

This is about a *class* of defect, not an instance. Four one-off fixes have shipped
for members of it and it keeps recurring, which is the evidence that the class was
never characterised. This document characterises it, says where it will be fixed,
and specifies the harness that proves the fix over the whole space.

---

## 1. The class

### 1.1 The geometry that makes it possible

A certificate is a single flex column (`.Award`, `display:flex; align-items:center`)
inside a fixed `2550x3300` frame. **Three independent coordinate systems paint into
that frame:**

| layer | what it is | what its position is measured against |
| --- | --- | --- |
| **A** plate artwork | absolutely-positioned plates, everything outside `.Award` | **frame** coordinates — fixed, data-blind |
| **B** ornament | `img.Flourish`, `img.Underline` and friends inside `.Award` | a **text box**, via `top/bottom/left/right` in local `em` |
| **C** glyph ink | the type, in normal flow | **content** — string length x face metrics x column width |

Every member of the class is the same failure:

> **An offset measured in one of those systems is applied to ink that lives in
> another, and the two systems move independently.**

That yields three sub-families, and they are exhaustive over the pairs:

1. **C n A — the flow reaches fixed art.**
   The column's extent is data-dependent; the plate's position is not. The
   sign-and-date family (crown, dragon r8-r10, garber r9-r10, griffon r0/r5-r8) is
   this one.

2. **C n B — the ornament is pinned to a line that has moved.**
   *This is case zero.* `#TopTextFlourish` and `#BottomTextFlourish` are positioned
   against the `"Amtgard"` span in `p:first-child` (`bottom:0.9em`, `top:0.95em`);
   `#RightTextFlourish` against `p:nth-child(2)`'s padding box. Both offsets encode
   the assumption *"the block I decorate is exactly one line tall"*. Drive the
   issuing line to three lines and the bottom swash lands on line two — while
   `margin-left: 4.4em`, the gutter reserved for that very swash, goes on protecting
   a line the swash is no longer beside.

3. **C n C — type over type.**
   Structurally possible wherever a block carries a negative margin or a reserved
   height smaller than its content (`section.SignAndDate` has `min-height`, several
   blocks carry negative `margin-left`/`margin-block-start`). Not yet reported. The
   sweep looks rather than assumes.

### 1.2 Why the previous passes missed it

Every prior fix corrected a **constant**. The defect is in the **binding** — what the
constant is measured against. A corrected constant is right for exactly the one data
point that was measured. A corrected binding is right for the axis.

Read of the four prior fixes, to be **confirmed or refuted by measurement** rather
than asserted:

| prior fix | verdict (hypothesis) |
| --- | --- |
| `--sc-sign-lift-want` (4 orders, 8 rows) | **symptom** — though `liftSignature()`'s measure-and-clamp around it is genuine mechanism |
| `--sc-swash-hang: 0.9em` + paired `padding-right` | **symptom**, and its own comment says so: one value because five frames were never measured off alpha |
| `margin-left: 4.4em` on `p:nth-child(2)` | **symptom**, and case zero's near-miss |
| `section.IssuingTextSection { width: 100% }` (killing the -10% overhang) | **cause** — it separated the ornament's box from the type's box |

The five load-bearing lessons from the brief, restated as rules this design obeys:

1. **Layout boxes are not ink.** `getBoundingClientRect` reports an SVG swash's full
   box and says nothing about its hairline stroke, and it reports boxes for elements
   an ancestor's `overflow:hidden` has clipped away entirely (the six citation rules
   do exactly this). Both directions of that error have already produced a wrong
   answer here. **Measure painted pixels.**
2. **Real award data is not the worst case.** The crown collision was invisible on
   `"9th August 2019"` and obvious on `"27th September 2026"`; the garber collision
   was invisible with a blank signer. Drive extremes, and **derive them from the
   data** rather than inventing them.
3. **A sample is not a sweep.** A seeded 20% sample found one of five families that
   full enumeration then found. Enumerate.
4. **The typeface axis was never swept.** All ten hands change every text metric, and
   the six added most recently are wider than the one everything was laid out
   against. Case zero lives on this axis.
5. **Moving something to fix one collision creates another.** Every fix is followed
   by a full re-run plus an independent overlap guard, never a spot check.

### 1.3 Case zero

Composer, kingdom scope. Award *Order of Battle*, variant *Arcane*, hand *Cinzel
Scroll Font*, issuer title `Kingdom` + name `Crystal Groves` with both **the** and
**of** checked, rank 4, no recipient.

The issuer line wraps to three lines; the left text flourish
(`text/squiggly-top.svg`) runs through the second; the right flourish closes on the
third. This must render clean when the work is done, **and so must every neighbour of
it** — which is what the sweep is for.

---

## 2. Measurement

### 2.1 Rasteriser: Playwright, as primary

The brief offered SVG serialisation (inline every plate and `@font-face` as data
URIs, `drawImage` to canvas) with browser screenshots as the fallback. **This design
inverts that ordering**, and not for reasons of speed:

> Serialising the `<foreignObject>` rasterises **a reconstruction of the page, not
> the page** — and a reconstruction is precisely how this class of defect hides.

`element.screenshot()` is the browser's own compositor output of the real DOM: real
pixels by definition, no canvas tainting, no font or image inlining to get wrong,
deterministic, headless, one command. `@playwright/test` is already a devDependency
and Chromium is already installed. The cost is minutes of wall clock, and it buys
away the whole failure mode.

### 2.2 Layer isolation

**Six rasters per case, at 1:1 frame pixels** — viewport 2550 wide and
`deviceScaleFactor: 1`, so the SVG's `width:100%` puts one CSS pixel on one frame
pixel. Each is clipped to the type band and taken on the same white base. Layers are
isolated with `visibility` and `color: transparent` **so that nothing reflows between
rasters**; `display:none` is banned here for that reason.

| raster | contents |
| --- | --- |
| `R_full` | the certificate as it prints — kept for eyeballing, never measured |
| `R_A` | plates only (`.Award { visibility: hidden }`) |
| `R_B` | ornament only (outside-`.Award` hidden, glyph ink transparent) |
| `R_C` | glyph ink only (outside-`.Award` hidden, `.Award img` hidden) |
| `R_C_odd` / `R_C_even` | the text blocks by parity |

The parity pair is how text-on-text is obtained cheaply. Adjacent-block C n C is
exactly `odd n even`, so two rasters cover all ~10 blocks' pairwise adjacency instead
of ten. Two guards make that sound:

- a non-empty `odd n even` **re-renders the two implicated blocks alone** to attribute
  the collision to a specific pair;
- `odd u even != C` is checked, and flags a same-parity (non-adjacent) overlap that
  the parity trick would otherwise hide.

### 2.3 What counts as ink

`ink = (1 - L) * alpha`, composited on white, above a floor.

- **A ground is not a collision.** A ground is opaque and covers the page by design;
  only a layer with transparency is a candidate. Decided by **sampling the pixels**,
  never by file extension — `ArtUrl()` serves the print rendition, so a `.jpg` master
  arrives as `.webp` and an extension test called owl's opaque blueprint plate an
  overlay, reporting 100% false ink on every owl certificate.
- **Both masks are eroded 1px before intersecting**, and a minimum connected-component
  size applies. Without that, two antialiasing fringes touching reads as a collision
  and the entire number stops being trustworthy.

### 2.4 Severity, not a boolean

Per case, per layer pair:

```
score      = SUM(interferer ink strength over C n X) / |C|   (parts per thousand of glyph area)
worst_run  = largest connected component of C n X            (pixels)
```

Both are reported. `worst_run` is what separates a swash drawn through a line from
scattered pepper at the same total area — the two have very different scores under a
plain area metric and identical ones under a boolean.

Weighting by contrast is what makes "pale ornament under dark type is not the same
defect as black knotwork under it" fall out of the arithmetic instead of needing a
special case.

**The threshold is derived, not chosen.** It is calibrated against known-clean and
known-dirty cases, pinned in the harness config, and the measured separation between
the two populations is reported alongside it. The full severity distribution is
published, not only pass/fail, because some C n A overlap is by design (type sits on
owl's blueprint plate) and a reader needs to see what is near the line.

---

## 3. The matrix

### 3.1 Factorisation

The full cross product is not runnable. Each sweep drives named axes to extremes
against a fixed baseline **B0** — mid-length everything, `chancery`, rank 5, lines on,
1-up, print surface — and only axes that actually interact are crossed. Each crossing
is justified below before it is run.

**Text profiles**, derived from the data (`ork_kingdomaward.name` max 35,
`ork_kingdom.name` max 37, `ork_park.name` max 36, `ork_awards.note` max 400,
`ork_mundane.persona` max 100 characters; composed-only fields — `customName`,
`titlePhrase`, free-text `issuerTitle` — take their caps from `Scroll::Sanitise*`
rather than from the DB):

- **max** — every field at its derived maximum
- **empty** — every optional field blank. *Blank is an extreme:* the garber collision
  was invisible with a blank signer.
- **case-zero** — the exact reported string set

| stage | cross | why this crossing | cases |
| --- | --- | --- | --- |
| **A** | order-variant (20) x rank (11) x text (3) | rank changes the **plate** — dragon's knotwork widens, garber's tape measure grows, griffon draws `symbol_N` twice. Proven interaction. | 660 |
| **B** | order-variant (20) x hand (10) x text (3), at each order's tightest rank from A | the frame's inner edge is per-order and the type's width is per-hand; the interaction *is* "does this hand's longest line reach this frame" | 600 |
| **C** | custom recipes: legal ground x border pairs (82) + rankSet (4) x rank (11) | ground and border paint the **frame band**; the numeral paints the **centre**. Factorised on that separation — and the factorisation is then **validated by one exhaustive 3,608-case run**, not assumed. | 126 routine |
| **D** | every stage A-C case within 2x of threshold, re-run on composer preview / `up=2` / `up=4` / `trim` / `lines=0`, plus a fixed representative set | sheet geometry and `ART_DISPLAY` renditions differ between surfaces; only near-threshold cases can flip | ~200 |

**~1,600 cases routine** (target under 30 minutes at 6 workers), **3,608 more** — the custom-recipe validation run — in the
`--exhaustive` tier.

20 order-variants = 17 partials - 2 (battle, owl) + battle{arcane, martial} +
owl{parchment, cyanotype, transparency}.

82 legal ground/border pairs = 7 borderless grounds x 11 borders + 5 grounds in
`groundsWithOwnBorder` x 1 (`NormaliseRecipe()` forces `border=none` for those).

### 3.2 Drivers

Existing routes only. **No new route is added to a shipped module.**

| surface | route | art size | geometry |
| --- | --- | --- | --- |
| print | `Scrollery/compose` (GET, all parameters on the query string) | `ART_MASTER` | `.scroll-page > svg > foreignObject`, full page |
| composer preview | `ScrolleryAjax/preview` (POST, returns rendered markup) | `ART_DISPLAY` | `#sc-stage`, ~600px |

Auth follows the existing `tools/fuzzy-validator/playwright/lib/auth.ts` convention
(`ORK3_E2E_USERNAME` / `ORK3_E2E_PASSWORD`).

**Accepted cost, flagged rather than hidden:** `compose` calls `record_print()`, so a
full sweep leaves roughly 1,600 rows in the print log of the **local** database. That
is the price of driving the real print surface through the real route instead of
adding a dev-only one.

---

## 4. Where it is fixed

Causes before symptoms, in this order. The lowest-numbered mechanism that can express
a fix is the one used.

### 4.1 Anchor ornaments to their block's measured box

The flourishes move off the `"Amtgard"` span and onto `.IssuingTextSection`,
positioned through custom properties (`--sc-orn-*`) whose **CSS defaults are today's
constants** and which `scrollery-autofit.js` overwrites from measurement.

With the script off, the current constants apply and behaviour is exactly what ships
today — no regression path. This is the `--sc-sign-lift` / `--sc-sign-lift-want`
idiom the file already established, reused rather than reinvented.

*An ornament anchored to its block's measured box cannot be crossed by that block.*

### 4.2 Keep-out regions, from measured ink

A build step rasterises every ornament and plate and writes
`assets/scrolls/ink-extents.json`: ink bounding box plus per-row extents.

This turns numbers **already sitting in CSS comments** ("squiggly-bottom ink stops at
0.890 of its box", "frame ink ends at 206..318 on the left and starts at 2243..2343 on
the right") into data. It is what retires `--sc-swash-hang`'s stated compromise — *one*
value because five of seventeen frames were only ever read off a crop rather than off
alpha — and it is the mechanism that handles the next new plate or the next new hand
without another patch.

Text is fitted to avoid a declared region.

### 4.3 Extend the measured fitting pass

The issuer ladder is `[{n:1, floor:0.62}, {n:2, floor:0.50}]` and bottoms out into a
wrap nothing can absorb. It gains a third tier and a wrap-aware floor, where the
honest answer genuinely is "this text has to be smaller or wrap differently".

### 4.4 Per-order / per-rank constants

Only where 4.1-4.3 genuinely cannot express it, and the comment says why.

### 4.5 Rules that bind all of the above

- **Every measured offset is clamped** to a declared ceiling, so it can never overrun
  a longer citation — exactly as `liftSignature()` clamps against
  `--sc-sign-lift-want`.
- **The artwork is Valory Fox's, licensed and frozen.** Changing a plate is a last
  resort, called out explicitly, never a quiet redraw — and a plate change moves its
  cache token and `Scroll::artGeneration()` with it, because art URLs are served
  immutable.
- **Prior constants are retired only where the sweep proves the mechanism subsumes
  them.** For each existing constant: prove by before/after sweep that the mechanism
  alone keeps that case clean, then delete it. Anything the mechanism cannot express
  keeps its constant with the reason written down. That is what converts the
  characterisation in section 1.2 from a hypothesis into a result.

---

## 5. Sequencing

Order is load-bearing, because the acceptance criteria depend on it.

1. **Harness first.**
2. **Baseline sweep captured before any fix.** The "no case that was clean became
   dirty" run is impossible otherwise.
3. Fix, in the 4.1-4.4 order.
4. **Full re-run plus an independent overlap guard** after *every* fix — never a spot
   check. Moving something to fix one collision creates another.
5. Case zero and every previously-reported instance rendered at 1:1 or better and
   **looked at**, images attached. A fix that has only been measured is not reported
   as a fix.

---

## 6. Deliverables

- **`tools/scroll-sweep/`** — the harness, with its own README, re-runnable by one
  command (`npm run sweep:scrolls`), following the shape of
  `tools/fuzzy-validator/playwright`. Committed, because this class will recur and
  the next person needs the tool.
- **`assets/scrolls/ink-extents.json`** and the build script that writes it.
- **`ScrollSweepCoverageTest`** — fails if `scroll-recipes.php` gains a hand, or
  `orkui/template/revised-frontend/scroll/` gains a partial, that the sweep manifest
  does not enumerate.
- **`ScrollInkExtentsTest`** — every ornament and plate a partial references has a
  manifest entry, and the manifest is not older than the art.
- Unit tests on the extracted keep-out geometry helpers.
- A short written characterisation of the class (section 1, published as its own
  document under `docs/`), including which of the four prior fixes were
  symptom-level — **as measured, not as predicted**.
- A results table: severity per case across the enumerated matrix, both surfaces.

## 7. Acceptance

- Zero collisions above threshold across the whole enumerated matrix, on both
  surfaces, reported as a table.
- A regression run showing no case that was clean became dirty.
- Case zero and every previously-reported instance rendered at 1:1 or better, looked
  at, images attached.
- Tests pinning the invariants, including one that fails if a new hand or a new plate
  is added without being swept.
- The harness committed and re-runnable by one command, with its own README.
- The written characterisation.

Any part not met is stated plainly, with the reason.

## 8. Constraints

- `.tpl` files are plain PHP, not Smarty.
- PSR-12 via `tools/php-cs-fixer` before committing PHP; normalise-first on leading
  tabs.
- Never `git stash`; never stage `system/lib/ork3/class.Authorization.php`; stage
  explicitly, never `git add -A`; verify `git diff --cached`.
- `mine` and `bulk` must not load `scrollery.css`.
- Art URLs are served immutable — a plate change needs its cache token and the render
  cache's art generation to move with it.
- Docker on `:19080`; routes are `index.php?Route=Controller/action`.
