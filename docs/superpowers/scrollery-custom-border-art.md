# Scrollery custom border art: format and verdicts

Task 1 of `docs/superpowers/plans/2026-10-27-scrollery-customize-design.md`. Everything here was
exported from the Arcanyst Affinity sources with the local tool `tools/affinity-extract/`. Its
`recolor.py noborder <preset>` reproduces each ground, and `knot_clean.py` rebuilds `knot.svg`. Nothing
was retouched by hand.

## The knot: vector

**File:** `assets/scrolls/common/knot/knot.svg` (365 KB, 636 paths, no `<image>`).

**Source:** `Award Templates Trim.af`, master 1005 "Overlay (Jovious)", band groups `/3/8`
(Primary) and `/3/9` (Secondary). Everything else was hidden, each band's Colour Overlay was removed
with `createRemoveColourOverlayLayerEffect(sel, 0)`, and the result was exported with the
`SVG (for export)` preset. Every edit was undone and nothing was saved.

**Spike result:** the vector route passed.

| Check | Limit | Result |
|---|---|---|
| `<image>` elements | 0 | 0 |
| Mean alpha error vs `out/recolor/jovious/border-original.png` (rsvg at 5100×6600) | < 0.01 | 0.00025 |
| File size | ≤ 1.5 MB | 419 KB raw, 365 KB cleaned |
| Mean RGB error on opaque pixels, composited with `#6AC1FF` / `#E84C92` (rsvg, 2×) | < 0.02 | 0.0020 |
| The same check in headless Chrome through the real `var()` path (1×) | — | 0.0060 |

Corner and centre crops beside the Affinity render are identical by eye.

**Why vector:**
- it prints at any resolution;
- it is one 365 KB definition per page, against two 5100×6600 rasters;
- the browser recolours it exactly, so the preview and the print show the same colours.

**Channel contract:**
- Two `<symbol>`s, each `viewBox="0 0 2550 3300"`, 1:1 with the page.
  - `Knot-Band-1` = **Color 1**, the Jovious blue band (Primary `/3/8`).
  - `Knot-Band-2` = **Color 2**, the pink band (Secondary `/3/9`).
  - Draw Band 1 first, then Band 2 (the source's paint order).
- The white fills are `style="fill:var(--knot-c1)"` / `var(--knot-c2)`. The outlines carry an
  explicit `fill="#000"`, so a `fill` inherited from a `<use>` cannot repaint them.
- `fill-rule="evenodd"` is set on each symbol's group. The exporter had put it on the `<svg>`
  root, where it would not have travelled with the symbols.
- Element ids and `affinity:id`s were stripped, leaving only the two symbol ids, so an inlined copy
  creates no duplicate ids.
- **The file paints nothing by itself.** Embed the two `<symbol>`s in the page's zero-size sprite
  `<svg>` (as `_border_defs.tpl` does) and draw them with `<use href="#Knot-Band-1"/>` inside an
  element that sets `--knot-c1` / `--knot-c2`. Custom properties inherit into the `<use>` shadow
  tree; this was verified in Chrome.
- **Geometry at 1×:**
  - ink box x 58–2492, y 51–3256;
  - top gap (the Jovious crest) x 1048–1503;
  - bottom gap x 976–1574.
- **Not included:** the Jovious master also has `/3/10`, a 50 % multiply diamond texture clipped to
  the knot's shape. It is band texture, not band colour, so the knot leaves it out. `/3/10` is
  hidden in the border-free ground, see below.

**Pipeline note:** `bin/build-scroll-ink-extents.php` now records an SVG that draws nothing
outside `<symbol>`/`<defs>` under `blank` without rasterising it. ImageMagick's SVG reader rejects
`var()` fills, and a symbol library has no ink of its own.

## Border-free grounds: verdicts

The pass criteria, per order:
- no remnant, checked by eye on the full plate, 4 corner crops and contrast-stretched crops of the
  former border;
- the rest of the plate matches the shipped ground: mean RGB error < 0.08 outside the dilated
  border mask.

| Order / plate | Verdict | Source layers (Award Templates Trim.af) | Error off border |
|---|---|---|---|
| jovious `bg.jpg` | **compatible** → `jovious/bg-noborder.jpg` | master 1005, hide `/3/8`, `/3/9`, `/3/10` | 0.074 |
| warrior rank 10 `bg_10.jpg` | **compatible** → `warrior/bg_10-noborder.jpg` | master 1010 "Overlay (Flame)", hide `/1/2/0/0/2`, `/2/0/0/0/2` | 0.0175 |
| flame `bg.jpg` | **incompatible** | master 1010, also hide the phoenix `/1/2/0/4`, `/1/2/0/6`, `/2/0/0/4`, `/2/0/0/6` | 0.072, but a different colourway |
| garber `linen.jpg` (+ `overlay.png`) | **incompatible** | master 1003, show `/0/0`; hide `/0/3`, `/0/4`, `/0/5`, `/0/6` | 0.091 |
| zodiac `bg_generic.jpg` / `bg_rank.jpg` | **incompatible** | master 1002, hide `/0/0`, `/0/1` | n/a |

### jovious: compatible

- **What is hidden:** the two band groups, plus `/3/10`. With only the bands hidden, `/3/10`
  left a full ghost knot of finer diamonds. All of its 622 k ink pixels lie inside the band mask,
  so hiding it touches nothing else (684 edge pixels change outside the band).
- **No remnant:** checked on the full plate, 4 corners and contrast-stretched band crops.
- **Exported:** at 1×, 2550×3300, JPEG q85 4:4:4.
- **Texture:** the linen weave is softer than the shipped `bg.jpg`'s, as the owner already
  accepted (2026-10-27).

### warrior rank 10: compatible

- **Source:** the Trim "Overlay (Flame)" master **is** `warrior/bg_10.jpg`, the phoenix plate.
  The full render differs from the shipped file by mean error 0.0187.
- **What is hidden:** the knot is drawn twice, an FX copy and a BG copy, each under
  `Page > Group (Primary, Secondary)`. Hiding both removes it completely. The "Knockout" erase
  rasters leave no knot-shaped holes.
- **Exported at 2×, then LANCZOS to 1×:** Affinity's 1× render of this master's Ripple and
  Gaussian Blur filters comes out visibly blocky (16 px cells); the 2× render does not.
- **Texture:** the paper grain and the flame edges are softer than in the shipped file. It is the
  same kind of difference as Jovious's linen, and the owner may want to confirm it.

### flame: incompatible

- **No source for this colourway.** The only candidate is the same master 1010 with the phoenix
  hidden. The shipped `flame/bg.jpg` is a different colourway of that plate:
  - grey, mottled, speckled paper instead of warm crumpled paper;
  - orange/red knot bands instead of red/yellow;
  - flame medallions on both sides.
- **The number is misleading.** Mean error off the knot and medallions is 0.072, under the
  threshold only because the fire and rocks match. The paper tone visibly changes across the
  whole page, with worst 50 px tiles at 0.21.
- **Not shipped.** Ticking Custom Border would silently swap the plate's paper. The README
  already records "no source for the shipped Flame design".
- **Candidate kept locally** for the owner to judge:
  `~/Desktop/Arcanyst Extract/out/knot/f-noknot-nophoenix.png`.

### garber: incompatible

- **The linen source is wrong.** The master's linen (`/0/0`, hidden by default, a 10192×13184
  `Linen.jpg`) renders as a soft blur: luminance std 0.011, against 0.115 in the shipped
  `linen.jpg`'s sharp weave. A 2× export is no sharper.
- **The numbers fail:** mean RGB error is 0.091 off the border, and 0.089 even for the full plate,
  both over the 0.08 limit.
- **The plate's subject would be lost.** The linen weave is the Garber (sewing) plate's subject.
- **No remnant.** Hiding Symbol-Right, Symbol-Left, White Border and the knot group does remove the
  border cleanly.
- **Note for Task 2/3** if this is ever revisited: the needle tips in `garber/overlay.png` belong
  to the border too.

### zodiac: incompatible

- **The "border" is the composition itself:** a cyanotype night sky with constellations, and a
  torn-edged parchment over it carrying the zodiac glyphs.
- **Nothing behind it.** Hiding the sky (`/0/0`) and constellations (`/0/1`) leaves the torn
  parchment over transparency. There is no ground behind the frame to export, and the knot
  (x 58–2492) would cut across the torn edge and the glyphs.

## Files

| File | Bytes |
|---|---|
| `assets/scrolls/common/knot/knot.svg` | 364,992 |
| `assets/scrolls/jovious/bg-noborder.jpg` (2550×3300) | 932,564 |
| `assets/scrolls/jovious/bg-noborder.webp` / `_display.webp` | 554,704 / 122,636 |
| `assets/scrolls/warrior/bg_10-noborder.jpg` (2550×3300) | 399,636 |
| `assets/scrolls/warrior/bg_10-noborder.webp` / `_display.webp` | 210,160 / 33,328 |

Neither ground is a 2× master, so `hires-masters.txt` is unchanged.
