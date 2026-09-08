# Ink-over-ink: what was measured, what was fixed, what was not

Companion to `docs/scrollery-ink-collision-class.md` (what the class is) and
`tools/scroll-sweep/README.md` (how to run the tool).

**The short version: two of the three sub-families are closed. The third is not, and on
two cases it is marginally worse.** Details below, including the numbers that say so.

All figures are **weighted ink pixels at 1:1 with the 2550x3300 frame** — glyph pixels
struck through, weighted by how dark the striking ink is. Thresholds: C∩A 300, C∩B 500,
C∩C 95, each calibrated against populations that were rendered and looked at.

---

## 1. Case zero, and the other named cases

Before and after, both measured at 1:1, both through the same harness, with the "before"
produced by `ORK3_SWEEP_REVERT=1` (which undoes this branch's layout changes from
outside the page — see `lib/drive.ts`).

| case | C∩A | C∩B | C∩C |
| --- | --- | --- | --- |
| **case zero** (battle/arcane r4, Cinzel) | 357 → 241 | **975 → 5** | 0 → 0 |
| **case zero, composer preview** | 219 → 186 | **974 → 5** | 0 → 0 |
| **case zero, Uncial** | 407 → 411 | **1877 → 0** | 0 → 0 |
| issuer name on the knotwork (rose, Uncial) | 172 → 172 | **3121 → 0** | 0 → 0 |
| crown sign-and-date | **1368 → 1421** | 258 → 258 | 0 → 0 |
| dragon sign-and-date | 34 → 34 | 258 → 258 | 0 → 0 |
| garber sign-and-date | **927 → 1035** | 262 → 262 | 0 → 0 |
| griffon sign-and-date | 0 → 0 | 258 → 258 | 0 → 0 |
| owl/cyanotype, white type | 508 → 511 | 238 → 238 | 0 → 0 |

The 238-262 that persists in the C∩B column on six of these is the **designed** contact:
`#BottomTextFlourish`'s curl sweeping around the "Amtgard" versal, present on every
certificate the module prints. It was located by rasterising the intersection and asking
the DOM what was there — frame x 588-609, y 648-664 — rather than assumed.

**Every one of these was rendered at 1:1 and looked at**, not merely measured. Case zero
before: the swash struck through "KINGDOM OF" — the G, D and O are crossed. Case zero
after: "AMTGARD AND THE KINGDOM OF" sets on one line and the swash sweeps clear. Uncial
after: the title legitimately stays on two lines and the swash sits *below* the second
one instead of through it, which is the anchor doing its job rather than the fit.

---

## 2. The regression run

Both sides at 1:1, stages N and A — 841 cases, the order x rank x text cross plus the
named cases.

```
compared      841 cases
fixed          10
still dirty   247
REGRESSED       1
```

**The one regression is real and is a trade, not a slip.** `A_garber_r9_chancery_max`:

| | before | after |
| --- | --- | --- |
| C∩C text over text | **89** | **0** |
| C∩A text over artwork | 288 | **397** |

Fitting the date and the signature makes that block shorter, which is what lifts the
flavour line out of the signature — and the shorter block then sits lower, where
garber's tape-measure plate is. One family closed at the cost of the other on that case.
It is recorded rather than tuned away.

### What this run does and does not cover

Stage A holds the hand at **Chancery**, which does not wrap the issuing title line — so
the ornament fixes do almost nothing in it, and "10 fixed" understates them badly. Their
effect is in the named cases above and would appear across stage B (the hand axis),
which this comparison does not include. `tools/scroll-sweep/baseline/README.md` says so
too, next to the baseline itself.

An earlier comparison over the **full** matrix (2,813 cases, stages N-D) showed **437
fixed and 0 regressed**, with every baseline-dirty case re-run. That run was at the
harness's older 0.32x rasterisation, on both sides, with identical code and metric — so
it is valid evidence about *change*, and less sensitive than the numbers above. Both are
reported because neither alone is the whole picture.

---

## 3. What is fixed

**C∩B — text over ornament: closed.** 975 → 5, 1877 → 0, 3121 → 0 on the named cases.
Two mechanisms, in cause-before-symptom order:

- `fitTitle()` stops the issuing title line wrapping at all in the hands where it did.
  Measured on identical content: Cinzel 318px → 174px, Metamorphous 323px → 178px.
- `anchorOrnaments()` puts the swash where the block it decorates actually ended up, for
  the case where the type genuinely needs two lines and no fit can help — Uncial, which
  still measures 322px and is *correct* at 322px.

**C∩C — text over text: closed on every case measured.** garber 89 → 0, and 0 across
the named set. Caused by the date and the signature having no fitting pass at all: a
flat `0.7em` in a 7em box, where the longest printable date takes three lines and a long
signer takes four, in a block anchored to the bottom that grows upward.

`overflow-wrap: anywhere` on the signature block is part of that fix and not cosmetic:
the fitting pass counts line boxes, and an unbreakable token overflowing its box is
still exactly one line box — so the ladder saw a name that "fitted" at full size while
it lay across the laurel.

---

## 4. What is NOT fixed

**C∩A — text over plate artwork. The acceptance criterion "zero collisions above
threshold across the whole matrix" is not met.** 247 of 841 cases remain dirty at 1:1
on stages N+A, and the sign-and-date family is unchanged or slightly worse: crown
1368 → 1421, garber 927 → 1035, owl/cyanotype 508 → 511.

Why it resisted the fixes that closed the other two:

- `liftSignature()` lifts the block clear of low artwork, but it is **clamped to the
  room the citation left** — and at the maximum citation there is none. The lift is
  correct mechanism with nothing to work with.
- The remaining collisions are concentrated in orders whose grounds are **busy full-page
  artwork**: smith's map, battle's rune wash, crown's laurel. There, "type over artwork"
  and "type on its designed ground" genuinely blur, and the threshold cannot separate
  them without a human deciding what the design intends. The harness has a wash test
  that removes layers covering essentially the whole band, but smith's soft-light
  townscape only *changes* 21% of pixels, so it is not caught by it.

The honest next step is not a bigger threshold. It is either a per-order decision about
which plates the citation band may sit on, or a citation that yields more before the
block does — and both are judgement calls that belong to whoever owns the artwork, not
to a metric.

---

## 5. Also not met, and why

- **The exhaustive custom-recipe tier was not run at 1:1.** It validates stage C's
  factorisation. At the older rasterisation the factorisation held; at 1:1 it is unrun.
- **Stages B, C and D have no 1:1 baseline**, for wall clock: at 1:1 the sweep is about
  eight times slower and the full matrix takes hours. The mechanism to capture one is
  committed and verified; it just needs the time.
- **`hunter` is not swept**, because no route can reach it: its `award_id` 6969 is not an
  ORK award id, so no `ork_kingdomaward` row resolves to it. Declared with its reason in
  `tools/scroll-sweep/unreachable-orders.json`, and `ScrollSweepCoverageTest` fails if
  that list grows without one.

---

## 6. The prior fixes, as measured

The spec predicted three of the four earlier fixes were symptom-level. That prediction
held, and the sweep sharpened one of them:

| prior fix | verdict |
| --- | --- |
| `--sc-sign-lift-want` (4 orders, 8 rows) | **symptom.** The lift is real mechanism; its NUMBERS were derived with the date and signer unfitted, so a long signer makes the block 150px taller and the clamp finds no gap. Retiring the constants was NOT attempted — the C∩A family they address is still open, so they are still load-bearing. |
| `--sc-swash-hang: 0.9em` | **symptom**, and its own comment says so. Untouched: it is in the C∩B path that is now closed by other means, and removing it was not needed to close it. |
| `margin-left: 4.4em` on `p:nth-of-type(2)` | **symptom**, and case zero's near-miss — a gutter reserved for a swash on the one line the swash was not anchored to. Now correct rather than accidental, because the swash is anchored to that line. |
| `section.IssuingTextSection { width: 100% }` | **cause.** The only one of the four that is. |
