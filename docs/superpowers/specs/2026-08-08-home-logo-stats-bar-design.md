# Home Page Logo + Four-Widget Stats Bar

**Date:** 2026-08-08
**Surface:** `orkui/template/default/default.tpl` (home / landing page)

## Goal

Introduce the new ORK logo on the home page in the top-left position, occupying the
left end of the existing stats bar. Reduce the stats bar from five widgets to four by
merging the separate "Kingdoms" and "Principalities" counts into a single
"Kingdoms & Principalities" figure.

## Current State

The page opens with a centered `.hm-welcome-banner` containing an `<h1>` reading
"Welcome to the Amtgard Online Record Keeper", followed by a five-cell
`.hm-stats-bar` flex row:

| # | Value | Label | Notes |
|---|-------|-------|-------|
| 1 | `count($hmKingdoms)` | Kingdoms | |
| 2 | `count($hmPrinz)` | Principalities | wrapped in `if (count($hmPrinz) > 0)` |
| 3 | `$hmTotalParks` | Parks | |
| 4 | `~number_format($hmWeeklyAvg)` | Players / Week | |
| 5 | "Weekly Recap →" | Week of _date_ | link; `if ($LoggedIn && $week_recap)` |

Because cells 2 and 5 are conditional, the bar already renders at 3, 4, or 5 cells
depending on data and auth state.

## Design

### 1. Assets

Both SVGs are pure vector — no embedded raster images — so they scale losslessly.

| Source (`~/Downloads`) | Destination | viewBox | Aspect |
|---|---|---|---|
| `Logo-ORC-Wide.svg` | `assets/images/logo-ork-wide.svg` | `0 0 1165.08 581.6` | 2.00 : 1 |
| `Logo-ORC-Wide-OnDark.svg` | `assets/images/logo-ork-wide-ondark.svg` | `0 0 1173.09 620.45` | 1.89 : 1 |

Renamed `ORC` → `ORK` and lowercased to match the existing convention in that
directory (`belt.svg`, `banner-template.png`). `/assets/images/` is referenced
root-relative throughout the codebase.

**Constraint — the two files must not be inlined.** Both internally declare
`id="Layer_2"` and an identical `.cls-1` … `.cls-14` class set inside a `<style>`
block. Inlined into the same document, the second file's rules would overwrite the
first's fills and corrupt both renderings. Referenced via `<img src>` each SVG is an
isolated document, so the collision cannot occur. This is the deciding reason for
`<img>` over inline SVG.

### 2. Markup structure

`.hm-stats-bar` becomes a two-part flex row:

```
.hm-stats-bar
├── h1.hm-logo-cell            fixed width, right border
│   ├── img.hm-logo-light
│   └── img.hm-logo-dark
└── div.hm-stats-cells         flex: 1
    ├── .hm-stat-item          27       Kingdoms & Principalities
    ├── .hm-stat-item          156      Parks
    ├── .hm-stat-item          ~1,240   Players / Week
    └── a.hm-stat-item         Weekly Recap →   (conditional, unchanged)
```

Two structural decisions carry weight:

**The logo cell is the page `<h1>`.** The welcome heading is removed (see §3), which
would otherwise leave the page with no top-level heading — an accessibility
regression. Wrapping the logo in `<h1>` with `alt="Amtgard Online Record Keeper"`
preserves the document outline at no visual cost, provided the global heading
pill-box is reset (see §5).

**The `.hm-stats-cells` wrapper is load-bearing, not cosmetic.** The existing mobile
rules position 2-column borders using `:nth-child(odd)`, `:nth-last-child(2)`, and
`:last-child` on `.hm-stat-item`. Introducing the logo as a *sibling* of those items
would offset every index by one and silently corrupt the border grid. Nesting the
widgets inside a wrapper keeps all existing nth-child math valid and untouched.

### 3. Welcome heading

`.hm-welcome-banner` and its `<h1 class="hm-welcome-title">` are removed. The logo
reads "O.R.K. — online record keeper", which duplicates the heading text almost
verbatim within ~80px of vertical space. The stats bar becomes the first element on
the page.

The now-unused `.hm-welcome-banner` / `.hm-welcome-title` rules — including the
`@media (max-width: 600px)` override and the `html[data-theme="dark"]` block — are
deleted rather than left as dead CSS.

### 4. Combined stat

```php
<span class="hm-stat-value"><?= count($hmKingdoms) + count($hmPrinz) ?></span>
<span class="hm-stat-label">Kingdoms &amp; Principalities</span>
```

`$hmKingdoms` and `$hmPrinz` are populated by a mutually exclusive branch on
`(int)$r['ParentKingdomId'] === 0`, so the two arrays are disjoint and the sum is
exact — no double-counting.

The `if (count($hmPrinz) > 0)` conditional is dropped along with the separate cell.
The count is computed live, so the figure tracks the data rather than being hardcoded
to the current value of 27.

### 5. Styling

Logo cell:

- `flex: 0 0 auto`, padding `8px 18px`, right border matching the item separators
- Logo height **110px**, `width: auto` — sizing on height means each variant renders
  at its own aspect ratio, so the light↔dark swap causes no reflow despite the
  differing viewBoxes
- Heading pill-box reset: `background: transparent; border: none; padding: 0;
  border-radius: 0; margin: 0` (the existing `.hm-welcome-title` carries the same
  reset, confirming the global `h1` style applies on this page)

Bar height grows from ~60px to 127px; widgets remain vertically centered.

Sizing history: the logo started at 64px, where the "online record keeper" microtext
beneath the wordmark was illegible, and was enlarged in three 20% steps
(64 → 77 → 92 → **110px**, +72% overall) until that line read cleanly. The logo cell
occupies ~16% of the bar width at desktop widths.

### 6. Dark mode

Both images are present in the DOM; CSS displays exactly one:

```css
.hm-logo-cell .hm-logo-dark { display: none; }
html[data-theme="dark"] .hm-logo-cell .hm-logo-light { display: none; }
html[data-theme="dark"] .hm-logo-cell .hm-logo-dark  { display: block; }
```

All three selectors are scoped to `.hm-logo-cell` deliberately. The sizing rule
`.hm-logo-cell img { display: block }` has specificity (0,1,1); an unscoped
`.hm-logo-dark { display: none }` at (0,1,0) loses to it and both logos render side
by side in light mode. Dark mode masks the fault, because
`html[data-theme="dark"] .hm-logo-dark` at (0,2,1) wins either way — so the bug
appears only in the light theme. Verified in-browser at both themes after the fix.

The project toggles theme via the `html[data-theme="dark"]` attribute rather than
`prefers-color-scheme`, so an attribute-driven CSS swap is correct here; `<picture>`
with media queries would not respond to the app's toggle.

### 7. Responsive

At `≤600px` the bar stacks vertically:

- `.hm-stats-bar` → `flex-direction: column`
- `.hm-logo-cell` → full width, bottom border replacing the right border. The logo
  keeps its full 110px height — there is no mobile size override, so desktop and
  mobile share one value. At 110px the wider (light) variant is 220px, needing
  ~252px of viewport including padding; `max-width: 100%` plus
  `object-fit: contain` scales it down proportionally below that rather than
  distorting it.
- `.hm-stats-cells` → becomes the `display: grid; grid-template-columns: 1fr 1fr`
  container (moved off `.hm-stats-bar`), preserving the existing 2-column item rules

With four widgets this yields a clean 2×2. Logged out (no Weekly Recap cell) yields
three, and the existing `:last-child:nth-child(odd) { grid-column: span 2 }` rule
handles the odd trailing item as it does today.

## Out of Scope

- Any other page or template; the logo is not added to the global header/nav
- SVG optimization or minification (~50KB each is acceptable)
- Changes to how the underlying stats are computed
