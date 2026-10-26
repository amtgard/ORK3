# Scrollery typeset engine: results

Measured 2026-10-25 and 2026-10-26 on branch `scrollery`, after the engine was wired (T1 to T13), calibrated (T14)
and given its underlay window (T14b).
The design is `docs/superpowers/specs/2026-10-25-scrollery-typeset-engine-design.md`; section numbers
below refer to it. "Before" is the state captured at HEAD `20b4b539d` before any rendering change
(`tools/scroll-sweep/baseline/`). "After" is the calibrated engine.

## 1. Summary

- Every top-half defect D1 to D8 is fixed on the frozen 54-case corpus: the recipient is the largest
  name on every certificate, planned and printed line counts agree everywhere, nothing prints a
  "of The", pass 3 no longer fires, and no case overflows.
- The frozen corpus and the fresh seeded corpus (seed 20261026, 104 cases) pass every corpus gate
  (Q1 to Q4, Q7 to Q9, Q11). The two Garber cases that failed Q3 and Q9 (s02, s32) pass since the
  Garber thread became an underlay window (section 6).
- The ink-collision sweep: no new top-half ornament contact (Q5, worst 302.7px), and **0 of 481** cases
  over 300 on top-half artwork contact (Q6), against 144 in the baseline. Q6 is now scored with the
  underlay policy applied in layer A, the way the layout treats it (section 5). One case that was clean
  is over a threshold now, a synthetic `max` persona on Garber (section 5.3, reporting only).
- The owner's visual sign-off (plan Step 6, a human gate) is **pending**; nothing here replaces it.
- What is still open is listed in section 9.

## 2. Calibration

Three changes to `scrollery-typeset.js`, all measured on the frozen corpus (sizes in em of the
`.Award` root). Nothing else in the role table moved.

| constant | spec | calibrated | why |
|---|---|---|---|
| `BREAK_CREDIT_CAP` | none (credit unbounded) | 25 | c46 |
| `NAME_LONG` | none | `{chars: 70, maxLines: 4}` | c16 |
| name `linePenalty` | `[0, 30, 60]` | `[0, 30, 60, 90]` | the fourth line needs a price |
| epithet start | first comma-word | first comma-word after the honorific run | the T4 minor ("sir, Kato") |

Everything else is as the spec gives it: role table min/max/sat, award `linePenalty [0, 22, 50]`,
`AWARD_LONG`, `SLACK 0.025`, `RAGGED 30`, `WIDOW 40`, `MINOR_SCALE 0.72`, `MINOR_PENALTY 6`,
`CLASSIC_PLACE_MIN 0.85`, `ORN_GAP 0.12`, `H_TOP_MIN 0.40`, the hierarchy ratios and the break
penalties of section 6.3.2. The underlay policy was adopted as drafted (controller ruling,
2026-10-25); the one change since is Garber's window (section 6).

### 2.1 c46: three lines at 1.846em became two at 1.284em

"Syr Grand Marquis Noodles, The Symbol of Peace" (Fondamento). A trace of every candidate the solver
scored showed what the brief suspected was only half the cause.

| candidate | s* | score |
|---|---|---|
| 3 lines `Syr Grand Marquis / Noodles, / The Symbol of Peace` | 1.846 | 86.9 (bonus 10) |
| 2 lines `Syr Grand Marquis Noodles, / The Symbol of Peace` | 1.296 | 76.1 (bonus 10) |

The three-line cut breaks at two good gaps (end of the honorific run, -25; the comma and the epithet,
-28) and so is paid 45 points of break credit against the two-line cut's 28. That 17-point difference
is what carries it; the size bonus past `sat` is only 7 of the 26 points between the sizes. So the two
levers the brief named do not work, and were measured over all 54 cases first:

| try | c46 | collateral |
|---|---|---|
| utility bonus 20 to 10 | still 3 lines | c28, c38, c48 (two lines at 1.82, 2.24, 2.30em) become one line at 1.17, 1.34 and a 1.56em two-liner |
| name `linePenalty [0, 35, 75]` | still 3 lines | c28 becomes a 1.17em one-liner; c12 drops to a 0.82em two-liner |
| name `linePenalty [0, 30, 80]` | 2 lines at 1.284 | c12, c15, c32 (3 lines at 1.16, 1.53, 1.23) become two-liners at 0.82, 1.02, 0.87em |
| `BREAK_CREDIT_CAP` 25 | 2 lines at 1.284 | c12, c32, c49 improve; nothing else moves |

`BREAK_CREDIT_CAP` bounds the total a candidate is paid for its breaks to one honorific-run gap. An
extra line has to earn its place on size, not on collecting credit twice. The solver reads it as
`score = U(s*) - LINE_PENALTY - max(sum of break penalties, -BREAK_CREDIT_CAP)`. Bad breaks still add in
full: the cap limits a credit, not a cost.

| case | before | after |
|---|---|---|
| c46 name | 3 lines, 1.846em | 2 lines, 1.284em |
| c12 name | 3 lines, 1.158em | 3 lines, 1.350em |
| c32 name | 3 lines, 1.229em | 3 lines, 1.441em |
| c49 name | 2 lines, 1.187em (award 0.950, one line) | 2 lines, 1.520em (award 1.292, two lines) |

Constraints: every case whose name sets on one line at `name.max` is unchanged (15 cases: c01, c06, c07,
c10, c13, c19, c20, c25, c26, c30, c34, c37, c39, c40, x4), so short names are untouched. No two-line
name became a one-liner. The c46 assertion in `typeset-dom.spec.ts` is unchanged; a second assertion (the two lines
set at 1.2em or more) was added beside it.

### 2.2 c16: the 100-character persona overflowed at `name.min`

Cormorant Unicase, 100 characters, three lines at `name.min` 0.8em printed 1,169px lines in a 1,121px
column and was flagged `data-sc-overflow`. `NAME_LONG {chars: 70, maxLines: 4}` mirrors `AWARD_LONG`
(a persona over 70 characters may take a fourth line); lowering `name.min` was rejected because it
would shrink every long persona to buy one case.

| case | before | after |
|---|---|---|
| c16 | 3 lines at 0.800em, overflow | 4 lines at 0.957em, no overflow |
| c31 | 3 lines at 0.838em | 4 lines at 1.051em |

Core tests pin the boundary (70 characters keep three lines and overflow, 71 take four and fit) and the
cap; DOM tests assert c16 and c31 print four lines with no overflow and no wide line. The cost is in section
5.3: a four-line name is about 90px taller than a three-line one.

### 2.3 The T4 minor

"sir, Kato" marked Kato an epithet start, so the gap after "Sir," scored -53 for a plain title and
name. The epithet's comma is now searched for after the honorific run (-45 after the fix). No frozen or
seeded corpus case changed.

## 3. Defects D1 to D8

"Review" is the spec's section 1 (50 cases). "Before" and "after" are the 54 frozen cases
(`corpus-before-2026-10-25.json` and the calibrated run), measured the same way by `corpus.spec.ts`.

| # | Defect | Review | Before (54) | After (54) |
|---|---|---|---|---|
| D1 | recipient not the largest name | award > name 31/50; place >= name 14/50; every persona >= 23 characters starts below the award | award > name 35; place >= name 14; of 36 personas >= 23 characters, 34 below the award | award > name 0; place >= name 0; 0 of 36 (Q1: 0 violations) |
| D2 | issuer name swings 16 to 41pt | kingdom median 21pt, park median 37pt | kingdom 21.4pt (16.1 to 40.8), park 36.9pt (22.3 to 40.8) | kingdom 26.8pt (15.0 to 31.2), park 28.8pt (16.4 to 31.2); median ratio 0.93 (Q7: within 20%); fresh corpus 0.905 |
| D3 | right swash crosses the name | curl crosses the kingdom name 25/25; "of" into swash head 5/25 | not separately recorded | placed from measured ink and hidden rather than allowed to touch; the sweep's `CBtop` is 302.7px at worst, the same as the baseline and inside the 238 to 262 designed contact plus fringe (Q5 holds, section 5). A per-case count of swash-through-name was not re-measured |
| D4 | long titles zigzag over three lines | c11, c14, x3 | c11 and c14 and x3 on two or three lines | c11: Classic, one name line, lead on the versal's row; x3: Centered, the place on its own lines; c14 was Centered only while Garber's thread pinned the place to a 465px band and is Classic now (section 6) (Q4: the lead is one line in all 54) |
| D5 | the fitter predicts instead of measuring | 9 boundary wraps; 3 cases printed more lines than planned | 3 cases printed more than planned | planned = reported = painted on every role of every case, both corpora (Q2: 0 mismatches); explicit lines mean breaks never fall inside a quoted nickname or after "of" (c27, c14 award are tested) |
| D6 | the column is treated as a rectangle | 5 overlaps, 6 near-misses | 144 of 481 sweep cases had top-half contact with artwork | 0 of 481 over 300 on `CAtop` with the underlay policy applied in layer A (section 5); hunter 15 to 0, hydra 4 to 0, crown 2 to 0, lion 2 to 0, owl 51 to 0, smith 19 to 0, warrior 13 to 0, custom 27 to 0, battle 11 to 0 |
| D7 | spacing is whatever `flex-grow` leaves; pass 3 scales the page | c32 scaled 0.94, c50 0.96 | pass 3 fired on 3 cases (c12 0.998, c32 0.936, c50 0.961) | pass 3 fires on 0 (Q9: before 3, after 0); budget `ok` on every case; large gaps were not separately measured |
| D8 | text reaches the layout unparsed | doubled articles (c37, c45); honorific stacks (c41, c18, c27); 11 of 342 active parks start with "The" | c37 and c45 printed "of The" | no rendered issuer text matches `/\bof The\b/` on either corpus (Q8); honorific stacks and epithets set as minor lines where the chooser isolates them |

Short-content anti-goal: c22 and c26 still come out as Classic with the versal, the swashes and the
right-hung place name (c22 name 2.116em, c26 2.300em, both one line).

## 4. Gates Q1 to Q12

Frozen corpus = `npm run sweep:scrolls:corpus` (54 cases). Fresh corpus =
`ORK3_CORPUS_SEED=20261026 ORK3_CORPUS_TAG=corpus-20261026 npm run sweep:scrolls:corpus` (the 54 plus
50 sampled, 104 in all; the sample is byte-identical to the one T14 drew).

| Gate | Frozen (54) | Fresh seed 20261026 (104) |
|---|---|---|
| Q1 hierarchy | pass, 0 violations | pass |
| Q2 planned = printed | pass, 0 mismatches | pass, 0 mismatches |
| Q3 width | pass | pass (s02's place line was 444px in 440.7px; Classic now, and `converge()` re-checks it, sections 6 and 9) |
| Q4 lead never wraps | pass | pass |
| Q5 `CBtop` <= 500 | pass: worst 302.7px on 481 sweep cases (baseline 302.7) | n/a, sweep matrix |
| Q6 `CAtop` <= 300 | **pass: 0 of 481 over**, scored with the underlay policy applied in layer A (baseline 144 of 481, all plates counted) | n/a, sweep matrix |
| Q7 place parity | pass, ratio 0.93 (kingdom 1.115, park 1.201) | pass, ratio 0.905 on the sample |
| Q8 text model | pass, no "of The" | pass |
| Q9 budget | pass: 0 floor, 0 overflow; pass 3 on 0 of 54, before 3 | pass (s32's lead no longer overflows: Classic, 1.040em) |
| Q10 composer = print | pass: 17 of 17 in `composer.spec.ts` (10 corpus cases: c06, c05, c11, c19, c13, c42, c02, c03, c10, c36) | |
| Q11 performance | pass: p95 5ms, median 2.1ms; 200-certificate page, section 7 | pass: p95 3.5ms, median 1.9ms |
| Q12 regression | section 5: 383 compared, 140 fixed, 1 newly dirty (reporting only) | |

Suites: `vendor/bin/phpunit --filter Scroll` green (721 tests, 10 skipped).
`npx playwright test --project=scroll-sweep-unit` with the officer login and one worker: 335 pass,
2 fail, both stale and neither caused by this work (section 9).

## 5. The ink-collision sweep (Q5, Q6, Q12)

`ORK3_SWEEP_DRIVER=fixture ORK3_SWEEP_SAMPLE=1 ORK3_SWEEP_STAGES=NAB npm run sweep:scrolls` then stage C,
the results merged, against `baseline/results-1to1-head-2026-10-25.json`. 481 cases (N 8, A 180, B 200,
C 93) are common to both. Battle, dragon, griffon and custom with border `knot` (98 cases) are left out
of the Q12 before/after judgement: the baseline was captured before a renderer fix restored their
knotwork border, so those cases are not comparable. They still face Q5 and Q6.

**Layer A now applies the underlay policy** (`lib/raster.ts` `captureLayers`, through the shared
resolver `lib/underlays.ts` that the profile builder also uses): a `whole` plate is hidden from A, a
`window` plate only inside its rows (a `clip-path` on the plate), and the plate `markGround()` took for
the ground is never hidden. G, B, C and Ctop are unchanged. The baseline was scored with every plate
counted as artwork, so Q6 and Q12 compare a policy-aware run against a policy-blind baseline; the
direction of every difference below is therefore partly the scoring and not only the engine.

### 5.1 Q5 and Q6

- **Q5:** the largest `CBtop` on any case is 302.7px (baseline 302.7px), against a limit of 500. No
  case is over. No `CB` or `CC` pair is dirty on any case before or after.
- **Q6:** `CAtop` > 300 on **0 of 481** cases (baseline 144: N 2, A 43, B 72, C 27; owl 51, smith 19,
  hunter 15, warrior 13, battle 11, custom 27, hydra 4, crown 2, lion 2). No case is over 300 on `CAtop`,
  so there is nothing to list with a plate beneath it. Across the whole certificate (`CA`) 61 cases are
  over 300, against 212 in the baseline; those are bottom-half hits and are Q12's business.
- Last time 96 cases were over, every one of them text over a plate the policy lists as an underlay
  (owl blueprint grid, smith townscape, warrior rank 10's `bg_10`, battle arcane's wash, and the custom
  grounds that draw them). The hand re-score then (0 of 95 over with the policy's plates hidden) and
  this run agree.

### 5.2 Q12

| | cases |
|---|---|
| compared (481 common, less 98 excluded) | 383 |
| clean before, dirty after, on any pair | **1** |
| dirty before, clean after | 140 |
| dirty both | 26 |

Run un-filtered, `bin/regression.mjs` reports 152 fixed, 60 still dirty and 1 regressed (the same case).
The 140 fixed, by order: owl 54, custom 30, smith 19, hunter 15, warrior 13, hydra 4, garber 3, lion 2.
Most of these are the scoring change (the underlay plates no longer count), not engine movement.

### 5.3 The newly dirty case

| case | `CA` before | `CA` now | `CAtop` | what it is |
|---|---|---|---|---|
| `B_garber_r10_unicase_max_print` | 213 | 393 | 0 | the citation's last line lands on the tape-measure plate |

Last run there were three such Garber cases (`pirata` 191 to 411, `sharp` 234 to 396, `unicase` 213 to
390); with the thread out of the obstacle ink and layer A scored with the policy, `pirata` is 172 and
`sharp` 139 and only `unicase` is over. The cause did not change: the synthetic `max` persona (100
characters of unbreakable tokens) now takes four lines where it took three and overflowed (`NAME_LONG`,
section 2.2), about 90px more height above the citation, and the citation's last line, "practice.", is
pushed onto the foot cluster (screenshot:
`.superpowers/sdd/2026-10-25-scrollery-typeset-engine/task-14-visual/garber/B_garber_r10_pirata_max-citation-on-plate.png`).
The owl-cyanotype case that was dirty last run is 0 now (it was the owl underlay).

**Controller ruling (T14b): the three `B_garber_r10_*_max` regressions are reporting-only.** Spec
decision 10 makes the synthetic `max` persona reporting-only for Q3 and Q6; the ruling extends it to Q12.
The real-data corpora keep their budget `ok` (158 cases). The repair, if one is wanted, is a bottom-half
follow-up: `bottomNeed` does not see art, so the citation's floor does not know the foot cluster is there.

## 6. Garber's underlay window

`garber/rank_N.png` is two things in one file. The policy's grain used to be the file, so all of it was an
obstacle, and the free-space profile pinned four consecutive bands to a 465px interval (frame x 1036 to
1501 or 1504: bands 15 to 17, y 775 to 849, and band 18, y 850) wherever a thread runs. That squeezed the
place line and the Centered lead on every Garber kingdom certificate (fresh corpus: s02 3.3px wide on Q3,
s32's lead flagged overflow on Q9).

The alpha of every `garber/rank_0..10.png` (2550x3300), measured by row:

| ranks | threads (alpha at most 186) | foot cluster (scissors, tape measure, numeral, pins; alpha 255) |
|---|---|---|
| 0 to 8 | y 467 to 512 and 800 to 845 | first pixel y 2659 to 2677 (ranks 0 to 5), 2546 (ranks 6, 7), 2563 (ranks 8 to 10) |
| 9, 10 | y 528 to 575 and 1123 to 1170 | (as above) |

Nothing at all is drawn between y 1171 and y 2545 on any rank. So the policy entry is a **window**:
`['pattern' => 'garber/rank_?.*', 'y' => [420, 1220]]` and the same for `rank_10.*` (the pattern is split
in two because `rank_*` would also match `rank_N_display.webp`, the composer's rendition). The window is
half-open, frame rows 420 to 1219: it holds every thread pixel with 47px above and 50px below, and ends
1,326px clear of the nearest foot pixel (the brief asked for 40px each side). It is the only window in the
policy. A window on a plate that is not the 2550x3300 frame (within 1px) fails closed and is logged.

`free-space.json` was rebuilt (`npm run profile:scrolls`). Only the garber entries changed, beyond
`underlayPolicySha1`: ranks 0 to 8 bands 1 to 5 and 15 to 18, ranks 9 and 10 bands 4 to 7 and 28 to 31.
Bands 15 to 18, which were pinned at 465px (x 1036 to 1501), now report the wide interval (x 277 to 2278,
the column); no band over the foot cluster changed; every non-garber profile entry is byte-identical to
the one before (verified by a diff of the two manifests: 11 entries and the sha differ, all garber).

Consequences, measured: s02 and s32 are Classic now and pass Q3 and Q9 (place line 726px in 1,191px; lead
1.040em); c14, which was Centered only because of the pin, is Classic (`typeset-dom.spec.ts` updated, x3
stays the Centered case). The place line s02 printed 3.3px over was also a real engine defect (section 9,
item 4, now fixed): `converge()` resized the article after the place had been verified, and re-checks it now.
With the old profile put back the fix alone takes s02 from 444px to 439px in its 440.7px band.

## 6.1 The converge fix

`converge()` writes the article at the lead's final size after the last solve, but `setRole` verified the
place line with the article at the size budgeted for it, so a larger article could leave line 0 over its
band. `reverifyPlace()` measures the place's lines against `data-sc-w` after that resize, shrinks the
place the way `setRole` does (never below its floor) and flags a line that still does not fit. A
synthetic test (`typeset-dom-synthetic-issuer.spec.ts`, Arial and Georgia, 600px band, a place with an
article under "of the Shire of") printed 586px in 585px without the change and fits with it; a sweep of
1,260 place, lead, face and band combinations found those two failures and none after.

## 7. Q11 bulk

`tools/scroll-sweep/tests/typeset-bulk.spec.ts`: the frozen corpus rendered by `renderFixtures`, each
certificate's page lifted into one document (with its own hand) and repeated to 200. The figure is the
total fit time: from DOMContentLoaded to the last `data-sc-ms` write (the fit is one synchronous task and
the writes arrive in one batch), and, beside it, the sum of the long tasks the page reports. DOMContentLoaded
and not `load`, because the fit runs before `load` on this page (a load-anchored span was negative). Both are
held to the budget; a wait that times out fails as "the fit never finished", distinct from a certificate left
on the first guess. The server render never emits `data-sc-ms` (only `scrollery-typeset.js` writes it;
grepped across `orkui`, `system`, `bin`), so the unfitted check is not vacuous.

| | run 1 | run 2 |
|---|---|---|
| certificates | 200 | 200 |
| left on the first guess (no `data-sc-ms`) | 0 | 0 |
| first fit (DOMContentLoaded to last `data-sc-ms`) | 751ms | 701ms |
| sum of the long tasks during the fit | 750ms | 636ms |
| a second fit, timed by hand | 1,103ms | 1,119ms |
| sum of the engine's own layout time | 543ms | 536ms |
| p95 / max layout per certificate | 6.0 / 11.9ms | 5.4 / 11.1ms |
| overflow flagged | 0 | 0 |

Autofit's budget is 6,000ms; the first fit uses about an eighth of it.

## 8. Visual review

`tools/scroll-sweep/out/corpus/index.html` (frozen) and `out/corpus-20261026/index.html` (fresh) are
the filterable contact sheets. Twelve representative top halves are saved in
`.superpowers/sdd/2026-10-25-scrollery-typeset-engine/task-14-visual/` (c22, c26, c05, c33, c08, c11,
c14, c16, c46, c12, c15, x3), for the sign-off of section 10.3.

## 9. Still open

1. **The owner's visual sign-off (plan Step 6) is pending.** It is a human gate. The contact sheets are
   `tools/scroll-sweep/out/corpus/index.html`, `out/corpus-20261026/index.html` and the underlay review
   sheet `out/underlays/index.html`, which now shows Garber's rank plates as WINDOW and the obstacles-only
   thumbnail with the window cut out.
2. **Three Garber `max`-persona cases in Q12 (now one over the threshold, `unicase`)** are reporting-only by
   controller ruling (section 5.3). A bottom-half follow-up is needed if they should pass: `bottomNeed`
   does not see art, so the citation can be pushed onto the foot cluster.
3. **The underlay policy is a ruling, not the owner's judgement.** Garber's window and every plain pattern
   were adopted by the controller; the owner may revise them (regeneration commands are in the policy's header).
4. **Two stale tests in `scroll-sweep-unit`:** `markup.spec.ts` "centered rules apply when forced" (it
   forces `data-sc-comp` by hand under a running engine, which hides the rule pair) and `drive.spec.ts`
   "a certificate renders and the autofit pass has run" (it looks for `data-af-base`, a marker of the old
   name pass the engine replaced in T11; `data-sc-ms` is the marker now). Both fail identically before and
   after this work.
5. **D3's swash-through-name count and D7's gap sizes** were not re-measured per case; their evidence
   here is `CBtop` and the budget, not a count.
6. **Q6 and Q12 are compared against a policy-blind baseline.** The 144 to 0 and the 140 fixed include the
   effect of scoring layer A with the policy; the baseline was not re-captured.
