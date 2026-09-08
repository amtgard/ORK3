# The Scrollery ink-collision sweep

This measures one class of defect: **ink printed on top of other ink** on a certificate.
Text over artwork, text over ornament, text over text.

Four separate one-off fixes shipped for members of this class before this tool existed
and it kept recurring, which is the evidence the class was never characterised. The
characterisation is in `docs/scrollery-ink-collision-class.md`; this is the tool that
proves a fix over the whole space, and it is committed because the class will recur.

## Run it

```sh
ORK3_E2E_USERNAME=… ORK3_E2E_PASSWORD=… npm run sweep:scrolls
```

Needs the app on `:19080` and a login with edit rights over the kingdom being swept.
Writes `tools/scroll-sweep/out/report.md`, `results.json`, and a full-page PNG for every
case over threshold — those PNGs are the point. **A fix you have only measured is not a
fix; look at the images.**

| variable | default | what it does |
| --- | --- | --- |
| `ORK3_SWEEP_SHARDS` / `ORK3_SWEEP_WORKERS` | 4 | parallelism. Set both to the same number. |
| `ORK3_SWEEP_STAGES` | `NABCD` | which stages to run (see below) |
| `ORK3_SWEEP_ONLY` | – | substring filter on the case id, for iterating on one family |
| `ORK3_SWEEP_LIMIT` | – | stop after N cases, for a smoke test |
| `ORK3_SWEEP_KINGDOM` | 1 | which kingdom's catalogue to compose against |
| `ORK3_SWEEP_OUT` | `out/` | where to write |
| `ORK3_SWEEP_D_CAP` | 150 | how many near-threshold cases stage D expands |

`npm run sweep:scrolls:exhaustive` additionally runs every custom recipe rather than the
factorised subset — that is the run that *validates the factorisation*, and it should be
done at least once per change to the composed scroll.

`npm run sweep:scrolls:unit` runs the harness's own tests. Run these first if the sweep
is behaving oddly; they are fast and they check the things everything else rests on.

## How to read `report.md`

**score** is the interfering layer's ink strength summed over its intersection with the
glyph mask, divided by the glyph area, in parts per thousand. **worst run** is the
largest connected component of that intersection — a swash drawn through a line and the
same area of scattered pepper score alike on area alone and do not look alike, so both
are reported.

Thresholds are **per layer pair** and each is calibrated against a measured population.
They are in `lib/report.ts` with the evidence; the short version:

| pair | threshold | why |
| --- | --- | --- |
| C∩A text over artwork | 1.0 | clean cases measure 0.00–0.77; confirmed defects 1.66–6.33 |
| C∩B text over ornament | 2.0 | **every** certificate scores 0.86–0.91 here — the same 26 px of `#BottomTextFlourish` sweeping around the "Amtgard" versal. That is drawn that way, so the threshold has to clear it. Real defects measure 6.83–9.05. |
| C∩C text over text | 0.3 | no designed text-on-text exists, so the floor is a true zero. Real ones measure 0.42 and 1.32 — low because a collision between two small blocks is a small fraction of the whole certificate's type. |

The report leads with the **distribution**, not the pass list, and buckets it by multiples
of each pair's own threshold so `1.0` is exactly at the line. Some C∩A overlap is by
design — type sits on owl's blueprint plate on purpose — so what is near the line matters
as much as what crossed it. A report that published a boolean would invite exactly the
"it passed" reading this tool exists to prevent.

## The matrix

| stage | what | why crossed that way |
| --- | --- | --- |
| **N** | case zero and every previously-reported instance | named individually by the acceptance criteria; a cross product need not contain any given point of itself |
| **A** | order-variant × rank × text profile | rank changes the **plate** — dragon's knotwork widens, garber's tape measure grows, griffon draws `symbol_N` twice |
| **B** | order-variant × hand × text profile | the frame's inner edge is per-order and the type's width is per-hand; the interaction *is* "does this hand's longest line reach this frame" |
| **C** | custom recipes: legal ground×border pairs, and rankSet×rank | ground and border paint the **frame band**, the numeral paints the **centre**; factorised on that separation and validated by the exhaustive tier rather than assumed |
| **D** | near-threshold cases re-run on the composer preview, `up=2`, `up=4`, `trim`, `lines=0` | sheet geometry and stage geometry differ; only a case already near the line can be pushed over by them |

Three text profiles: **max** (every field at its derived maximum, taken from the ORK's own
data), **empty** (blank is an extreme in its own right — the garber collision was invisible
with a blank signer), and **caseZero** (the reported reproduction verbatim).

## How it measures

Playwright screenshots at 1:1 frame pixels — a 2550-wide viewport and
`deviceScaleFactor: 1` put one CSS pixel on one frame pixel. Real compositor output of the
real DOM, not a reconstruction. Serialising the `<foreignObject>` and rasterising it to a
canvas was considered and rejected: it measures a rebuild of the page, and a rebuild is
exactly how this class of defect hides.

Five rasters per case, all of the same clip so they are pixel-comparable by construction:
the ground alone, ground+plates, ground+ornament, ground+glyphs, and the full render for
looking at. Each layer's mask is its **difference from the ground**.

### Two rules that are not negotiable

**1. `display:none` is forbidden in `lib/isolate.ts`.** Isolation must not reflow. Taking
an element out of flow moves the type, and two rasters of two different layouts cannot be
intersected — the intersection reports collisions present in neither render, which is a
more expensive kind of wrong than measuring nothing. Everything is `visibility` or
`color: transparent`. There is a test that asserts it per layer.

**2. Never measure against white; measure against the ground.** Cyanotype certificates set
the type to `#ffffff`. Against a white base a darkness metric does not merely miss that
type — it inverts, scoring the dark ground as ink and the white glyphs as blank paper.
There is a test for that too.

## Adding an axis

The harness never restates Scrollery's vocabulary. `bin/dump-axes.php` reads the PHP data
files and writes `axes.json`; `lib/cases.ts` enumerates from it. So:

- **a new hand or a new order partial** — regenerate `axes.json` (the sweep script does it
  for you) and it is swept. `ScrollSweepCoverageTest` fails the build if you forget.
- **a new text block in `_text.tpl`** — add it to `BLOCK_SEL` in `lib/isolate.ts`, or
  text-on-text will not see it. It will be stamped `data-sweep-orphan` and hidden rather
  than silently faking a collision, which is the safe failure, but it will not be measured.
- **an order no route can reach** — declare it in `unreachable-orders.json` with the
  reason. `hunter` is there: its `award_id` 6969 is not an ORK award id, so no
  `ork_kingdomaward` row resolves to it and `hunter.tpl` cannot be composed. Its
  `border.png` is live as a custom recipe border and stage C does sweep that.

## What it costs

`Scrollery/compose` writes a print-log row per case, so a full sweep leaves roughly 1,400
rows in the **local** database. That is the price of driving the real print surface
through the real route instead of adding a dev-only one to a shipped module. It was a
deliberate trade; if it ever stops being the right one, the alternative is a gated no-log
route and this is the note that says so.
