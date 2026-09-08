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
