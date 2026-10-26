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
| `ORK3_SWEEP_DRIVER` | compose | `fixture` renders without a login; see below |
| `ORK3_SWEEP_SAMPLE` | – | `1` runs the sampled matrix; see below |

`npm run sweep:scrolls:exhaustive` additionally runs every custom recipe rather than the
factorised subset — that is the run that *validates the factorisation*, and it should be
done at least once per change to the composed scroll.

`npm run sweep:scrolls:unit` runs the harness's own tests. Run these first if the sweep
is behaving oddly; they are fast and they check the things everything else rests on.

### The fixture driver

`ORK3_SWEEP_DRIVER=fixture` renders each case through `bin/scroll-render-fixtures.php`
(`Scroll::BuildScroll()` plus the render template, one `docker exec` per shard) instead of
fetching `Scrollery/compose`. It needs no login and writes no print-log rows, and it reaches
`hunter`, which no composer route can. It draws a flat dark square in the heraldry slot as a
stand-in for the kingdom's device, so a fixture figure is not a compose figure; compare
fixture runs with fixture runs. It cannot render the composer preview, `trim` or `lines=0`,
and refuses those cases rather than rendering their plain twins; the sweep drops them and
prints how many. See the long comment on `fixtureDriver` in `lib/drive.ts`.

### The sampled matrix

`ORK3_SWEEP_SAMPLE=1` thins the matrix to about a fifth of its size. It is what the baseline
and the after-runs use.

- **A**: every order-variant × ranks {0, 5, 10} × profiles {max, empty, kingdomReal}
- **B**: every order-variant × every hand × {max}
- **C**: the legal ground and border pairs as before, plus rankSet × ranks {0, 5, 10}
- **N**: unchanged

Every sampled case has the same id as in the full matrix, so a sampled file and a full one
compare on the cases they share. The exhaustive tier is never sampled.

### Stamps and the regression check

`results.json` carries a `driver` (`compose` or `fixture`) and a `sample` flag, and
`report.md` repeats them in its first line. A file without a `driver` predates the stamp and
was a compose run. `bin/regression.mjs` refuses to compare two files from different drivers,
because they do not draw the same certificate. A sampled file against a full one is
compared on the shared cases only, and the output says so.

## How to read `report.md`

**score** is an absolute weighted ink pixel count: the glyph pixels struck through by the
interfering layer, each weighted by how dark the striking ink is. (`lib/report.ts` stores the
ratio of that weight to glyph area and `ink()` scales it back up, so the figures in the
report are pixels.) Both masks are eroded one pixel first, so two antialiasing fringes
touching is not a finding. **worst run** is the largest connected component of that
intersection. A swash drawn through a line and the same area of scattered pepper score alike
on area alone and do not look alike, so both are reported.

Thresholds are **per layer pair**, in weighted ink pixels, and each is calibrated against a
measured population at 1:1. They are `THRESHOLDS` in `lib/report.ts`, which carries the
evidence; the short version:

| pair | threshold | why |
| --- | --- | --- |
| C∩A text over artwork | 300 | clean cases measure 0 to 172; confirmed defects 508 to 1368. 300 is 1.7x above the highest clean and 1.7x below the lowest defect. |
| C∩B text over ornament | 500 | **every** certificate scores 238 to 262 here, the versal swash that is drawn that way, so the threshold has to clear it. Defects measure 974 to 3121. |
| C∩C text over text | 95 | no designed text-on-text exists, so the floor is a true zero. 95 is the earlier 8-pixel figure carried across by the measured 11.9x change of scale. |

The report leads with the **distribution**, not the pass list, and buckets it by multiples
of each pair's own threshold so `1.0` is exactly at the line. Some C∩A overlap is by
design — type sits on owl's blueprint plate on purpose — so what is near the line matters
as much as what crossed it. A report that published a boolean would invite exactly the
"it passed" reading this tool exists to prevent.

### Top-half scores

Each result also carries **CAtop** and **CBtop**: the same two scores with only the type
above and including the `.Order` line left in. They come from a sixth layer, `Ctop`, which
is C with the glyphs of every block after `.Order` made transparent (`lib/isolate.ts`), so
nothing moves and it intersects with A and B exactly as C does. The top half is the part the
typeset engine places. The thresholds are the same two (300 and 500), and there is no C∩C
twin. The report counts the cases over threshold in the top half alone. A results file from
before the layer existed has no top scores; the report says how many cases it scored.

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

## Baselines

`baseline/` holds the frozen runs; `baseline/README.md` says how each was captured.

- `results-1to1-NA.json` is a compose-driver run of stages N and A, from before the layout
  changes. It is **superseded** for comparisons through the fixture driver.
- `results-1to1-head-2026-10-25.json` is the fixture-driver, sampled baseline. Compare
  fixture runs against it, passing it to `bin/regression.mjs` explicitly, since the script's
  default is still the NA file and would be refused on a driver mismatch.

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
