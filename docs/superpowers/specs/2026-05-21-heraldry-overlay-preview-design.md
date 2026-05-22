# Live Heraldry-Overlay Preview in the Design modal

**Date:** 2026-05-21
**Surface:** Playernew "Design My Profile" modal → Colors tab
**Type:** Enhancement (frontend only)

## Problem

The Design modal's hero preview (`#pn-color-hero-preview`) live-updates for color
and gradient choices, but the **Heraldry Overlay Strength** buttons (Low / Medium /
High / Vignette) have no visible effect there. They only set the `pn-hero-overlay`
hidden field. The preview box also never renders the player's heraldry, so all four
strengths look identical. Users cannot tell how their heraldry will bleed through the
hero until they save and reload the profile.

## Goal

Make the overlay-strength buttons (and the modal's initial state) update the preview
in real time, compositing the player's heraldry over the chosen color/gradient exactly
as the saved profile hero will render it.

## Constraints

- Frontend only, confined to `orkui/template/revised-frontend/Playernew_index.tpl`.
- No DB or controller changes — `HeroOverlay` already persists and round-trips.
- Must match production hero compositing so the preview is truthful.
- Must not alter the real hero's rendering or its `--pn-overlay-opacity` variable.

## How production composites the hero (reference)

`revised.css:166-202` + `Playernew_index.tpl:951-956`:

- `.pn-hero` paints the base color/gradient (`--pn-hero-bg`).
- `.pn-hero-bg` is an absolutely-positioned heraldry layer, `background-size:cover`,
  `filter:blur(6px)`, `opacity: var(--pn-overlay-opacity)`.
- Opacity map: `low 0.06`, `med 0.12`, `high 0.22`.
- Vignette mode uses two layers — `.pn-hero-bg-vignette-base` (heavy blur, masked OFF
  at center) + `.pn-hero-bg-vignette-sharp` (light blur, 0.30 opacity, masked ON at
  center) — for a sharp center fading to a soft, low-opacity edge.
- Heraldry layers render regardless of pride-gradient mode (they sit on top of the
  pride background too).
- Heraldry URL is already available server-side as `$heraldryUrl`; players with no
  custom heraldry get the `000000.jpg` placeholder (same as production).

## Design

### 1. Preview markup
Add heraldry layers as the first children of `#pn-color-hero-preview`, mirroring the
real hero structure, using `$heraldryUrl`:
- `.pn-hero-bg pn-preview-bg pn-preview-bg-vignette-base`
- `.pn-preview-bg-vignette-sharp`

Both vignette layers stay in the DOM; a `pn-preview-vignette` class on the preview box
controls whether normal or vignette compositing is active. The preview content
(name + subline) keeps `position:relative; z-index:1` so it stays above the layers
(the `.pn-hero-preview` rule already sets `position:relative; overflow:hidden`).

### 2. Preview-local opacity variable
The preview reuses the production layer CSS (blur, masks) but is driven by its **own**
opacity variable so the real hero is untouched. The preview-scoped `.pn-preview-bg`
rule reads `var(--pn-preview-overlay-opacity, 0.12)`, set inline on the preview box by
JS. Vignette base/sharp layers use preview-scoped selectors so their opacity/blur/mask
match production (base uses the preview opacity var; sharp is fixed at 0.30 like prod).

### 3. Wire-up (existing handlers)
- **Overlay buttons** (`tpl:3941`): on click, in addition to the existing active-class
  + hidden-field update, set the preview's `--pn-preview-overlay-opacity` from the
  same map, toggle `pn-preview-vignette` for the vignette button, then call
  `applyOverlayPreview()` / `updateColorPreview()`.
- **`updateColorPreview()`**: unchanged base behavior (color or gradient as
  `background`). Heraldry layers sit on top via CSS, including under pride gradients.
- **Reset** (`tpl:3917`): already resets overlay to `med`; ensure it re-applies the
  preview overlay state.
- **Init**: call the overlay-preview applier once on modal setup so the preview opens
  reflecting the saved `HeroOverlay`.

### 4. Helper
A small `applyOverlayPreview(level)` function centralizes: set opacity var, toggle
vignette class. Called from button clicks, reset, and init. Keeps the logic in one
place rather than duplicated.

## Edge cases
- **No custom heraldry:** placeholder `000000.jpg` renders faintly — matches prod, stays
  truthful.
- **Pride flag active:** heraldry overlays the pride gradient, same as production.
- **Dark mode:** preview hero is always rendered on the light modal surface; no special
  handling needed.

## Out of scope
- Changing the real hero rendering, the opacity map, or persisted values.
- Restyling the preview box dimensions.

## Verification
Load a player **with** heraldry in Chrome, open the Design modal, and click
Low → Med → High → Vignette. Confirm the heraldry bleed-through changes live and that,
after submitting, the saved profile hero matches the previewed strength.
