# The frozen baseline

`results-1to1-NA.json` is the module's state **before** this branch's layout changes,
measured by the harness at **1:1 with the frame**, over stages N and A (841 cases).

## How it was captured

```sh
ORK3_SWEEP_REVERT=1 ORK3_SWEEP_STAGES=NA npm run sweep:scrolls
```

`ORK3_SWEEP_REVERT` undoes this branch's four layout changes from outside the page,
after autofit has run — see the long note on `revertToBefore()` in `lib/drive.ts`. It
exists because the "before" is not recoverable from git: these files carry unrelated
in-flight work that the before has to keep.

Verified by reproducing every named defect through it, which is the only thing that
makes a reconstruction trustworthy: case zero back to 975 ink px of ornament over type,
the issuer name back to 3121 on the knotwork.

## Why it replaced the previous one

An earlier baseline was captured while the harness still rasterised the certificate at
about a third of frame size. Every figure in it is roughly twelve times smaller than
the same measurement today, so comparing against it would compare resolutions rather
than code. It was deleted rather than kept alongside, because a baseline that cannot be
compared to anything is a trap.

## Why only stages N and A

Time, and it is stated rather than hidden. At 1:1 a sweep is about eight times slower
than it was, and the full matrix with stage D takes hours. **A regression check against
this baseline covers the order x rank x text cross and the named cases, and does NOT
cover the hand axis (stage B), the composed recipes (C) or the sheet axes (D).**

That matters for reading a result: stage A holds the hand at Chancery, which does not
wrap the issuing title line, so the ornament fixes do almost nothing here. Their effect
shows in the named cases and would show across stage B. Widen it with
`ORK3_SWEEP_STAGES=NABCD` when you can afford the wall clock.

## Refreshing it

Do it whenever the module's layout changes land, so the next piece of work has a before
that matches the code it is about to change:

```sh
ORK3_SWEEP_REVERT=1 ORK3_SWEEP_STAGES=NA ORK3_SWEEP_OUT=/tmp/before npm run sweep:scrolls
```

then re-shape it the way this file is shaped (id, the three damage figures, glyphPx) and
commit it. Keep the raster scale the same on both sides or the comparison means nothing.

# The typeset-engine baseline (2026-10-25)

Two files, captured at HEAD `20b4b539d` before any rendering change of the typeset engine
(`git diff --stat 9a2658622 HEAD` over `orkui/template/revised-frontend/scroll`,
`style/scrollery.css`, `script/scrollery-autofit.js` and `Scrollery_render.tpl` was empty):

- `results-1to1-head-2026-10-25.json` — the ink-collision sweep, **fixture driver, SAMPLED
  matrix**, 1:1 with the frame. Top level `driver: "fixture"`, `sample: true`. 481 cases:
  N 8, A 180, B 200, C 93. Every case carries `CAtop`/`CBtop` beside `CA`/`CB`/`CC`.
- `corpus-before-2026-10-25.json` — `out/corpus/report.json` for the 54 frozen review cases
  (sizes, line counts and pass-3 root scale per case).

## How it was captured

```sh
ORK3_SWEEP_DRIVER=fixture ORK3_SWEEP_SAMPLE=1 ORK3_SWEEP_STAGES=NAB npm run sweep:scrolls
# copy out/results.json here, then:
ORK3_SWEEP_DRIVER=fixture ORK3_SWEEP_SAMPLE=1 ORK3_SWEEP_STAGES=C npm run sweep:scrolls
# merge its `results` in, keeping threshold, driver, sample and generated
npm run sweep:scrolls:corpus
```

## What "sampled" means (`ORK3_SWEEP_SAMPLE=1`, `lib/cases.ts`)

- **A** every order-variant x ranks {0, 5, 10} (warrior at its floor, 1, instead of 0) x
  profiles {max, empty, kingdomReal}
- **B** every order-variant x every hand x {max}, at rank 10 (stage A's tightest rank cannot be
  known when B is enumerated, so the largest sampled rank stands in)
- **C** every legal ground/border pair, plus rankSet x ranks {0, 5, 10}
- **N** the named cases, unchanged; `case-zero-preview` is not rendered by the fixture driver
- stage D is not in this baseline

Every sampled case is a case of the full matrix with the same id.
`bin/regression.mjs` compares the ids both files hold and says how many; it refuses to compare
files from different drivers, because the fixture driver draws a stand-in for the kingdom's
arms and has no preview, trim or no-lines sheets, so its figures are not compose figures.
Compare a later run against this file with the same driver:

```sh
node tools/scroll-sweep/bin/regression.mjs tools/scroll-sweep/baseline/results-1to1-head-2026-10-25.json
```
