# Scrollery typeset engine: results

Measured 2026-10-25 on branch `scrollery`, after the engine was wired (T1 to T13) and calibrated (T14).
The design is `docs/superpowers/specs/2026-10-25-scrollery-typeset-engine-design.md`; section numbers
below refer to it. "Before" is the state captured at HEAD `20b4b539d` before any rendering change
(`tools/scroll-sweep/baseline/`). "After" is the calibrated engine.

## 1. Summary

- Every top-half defect D1 to D8 is fixed on the frozen 54-case corpus: the recipient is the largest
  name on every certificate, planned and printed line counts agree everywhere, nothing prints a
  "of The", pass 3 no longer fires, and no case overflows.
- The frozen corpus passes every corpus gate (Q1 to Q4, Q7 to Q9, Q11). The fresh seeded corpus passes
  all but two cases, both on Garber, both traced to one band of the Garber plate (section 6).
- The ink-collision sweep: no new top-half ornament contact (Q5), top-half artwork contact down from
  144 cases to 96, and every one of the 96 is text over a plate the underlay policy says it is meant to
  sit on (section 5). Four cases that were clean are over a threshold now (section 5.3).
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
2026-10-25); no pattern changed.

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
| D4 | long titles zigzag over three lines | c11, c14, x3 | c11 and c14 and x3 on two or three lines | c11: Classic, one name line, lead on the versal's row; c14 and x3: Centered, the place on its own lines (Q4: the lead is one line in all 54) |
| D5 | the fitter predicts instead of measuring | 9 boundary wraps; 3 cases printed more lines than planned | 3 cases printed more than planned | planned = reported = painted on every role of every case, both corpora (Q2: 0 mismatches); explicit lines mean breaks never fall inside a quoted nickname or after "of" (c27, c14 award are tested) |
| D6 | the column is treated as a rectangle | 5 overlaps, 6 near-misses | 144 of 481 sweep cases had top-half contact with artwork | 96 of 481, every one of them text over an underlay plate (section 5); hunter 15 to 0, hydra 4 to 0, crown 2 to 0, lion 2 to 0 |
| D7 | spacing is whatever `flex-grow` leaves; pass 3 scales the page | c32 scaled 0.94, c50 0.96 | pass 3 fired on 3 cases (c12 0.998, c32 0.936, c50 0.961) | pass 3 fires on 0 (Q9: before 3, after 0); budget `ok` on every case; large gaps were not separately measured |
| D8 | text reaches the layout unparsed | doubled articles (c37, c45); honorific stacks (c41, c18, c27); 11 of 342 active parks start with "The" | c37 and c45 printed "of The" | no rendered issuer text matches `/\bof The\b/` on either corpus (Q8); honorific stacks and epithets set as minor lines where the chooser isolates them |

Short-content anti-goal: c22 and c26 still come out as Classic with the versal, the swashes and the
right-hung place name (c22 name 2.116em, c26 2.300em, both one line).

## 4. Gates Q1 to Q12

Frozen corpus = `npm run sweep:scrolls:corpus` (54 cases). Fresh corpus =
`ORK3_CORPUS_SEED=20261026 ORK3_CORPUS_TAG=corpus-20261026 npm run sweep:scrolls:corpus` (the 54 plus
50 sampled, 104 in all).

| Gate | Frozen (54) | Fresh seed 20261026 (104) |
|---|---|---|
| Q1 hierarchy | pass, 0 violations | pass |
| Q2 planned = printed | pass, 0 mismatches | pass, 0 mismatches |
| Q3 width | pass | **FAIL**: s02 place line 444px > 440.7px |
| Q4 lead never wraps | pass | pass |
| Q5 `CBtop` <= 500 | pass: worst 302.7px on 481 sweep cases (baseline 302.7) | n/a, sweep matrix |
| Q6 `CAtop` <= 300 | see section 5: 96 of 481 over, all underlay plates; 0 of 95 re-scored over with the policy applied | n/a, sweep matrix |
| Q7 place parity | pass, ratio 0.93 (kingdom 1.115, park 1.199) | pass, ratio 0.905 on the sample |
| Q8 text model | pass, no "of The" | pass |
| Q9 budget | pass: 0 floor, 0 overflow; pass 3 on 0 of 54, before 3 | **FAIL**: s32 `data-sc-overflow` on the lead |
| Q10 composer = print | pass: 17 of 17 in `composer.spec.ts` (10 corpus cases: c06, c05, c11, c19, c13, c42, c02, c03, c10, c36) | |
| Q11 performance | pass: p95 4.6ms, median 2.2ms; 200-certificate page, section 7 | pass: p95 3.3ms, median 1.9ms |
| Q12 regression | section 5: 383 compared, 31 fixed, 4 newly dirty | |

Suites: `vendor/bin/phpunit --filter Scroll` green (705 tests, 10 skipped).
`npx playwright test --project=scroll-sweep-unit` with the officer login and one worker: 325 pass,
2 fail, both stale and neither caused by the calibration (section 9).

## 5. The ink-collision sweep (Q5, Q6, Q12)

`ORK3_SWEEP_DRIVER=fixture ORK3_SWEEP_SAMPLE=1 ORK3_SWEEP_STAGES=NAB npm run sweep:scrolls` then stage C,
the results merged, against `baseline/results-1to1-head-2026-10-25.json`. 481 cases (N 8, A 180, B 200,
C 93) are common to both. Battle, dragon, griffon and custom with border `knot` (98 cases) are left out
of the Q12 before/after judgement: the baseline was captured before a renderer fix restored their
knotwork border, so those cases are not comparable. They still face Q5 and Q6.

### 5.1 Q5 and the CAtop count

- **Q5:** the largest `CBtop` on any case is 302.7px (baseline 302.7px), against a limit of 500. No
  case is over. No `CB` or `CC` pair is dirty on any case before or after.
- **Q6, as the sweep scores it:** `CAtop` > 300 on **96** of 481 cases, against **144** in the baseline
  (131 to 90 with the 98 not-comparable cases removed). By stage: N 2 to 1, A 43 to 33, B 72 to 40,
  C 27 to 22. By order, after: owl 41, custom 22, smith 16, warrior 13, battle 4, and none in any
  other order. Hunter went 15 to 0, hydra 4 to 0, crown 2 to 0, lion 2 to 0.
- **Why the 96 are not engine collisions.** The sweep's layer A counts every plate as artwork, the
  underlays included; only the profile builder applies the underlay policy. The five families above
  are exactly the orders that have an underlay (smith `burn`, owl `border` (the blueprint grid),
  warrior rank 10 `bg_10`, battle arcane `wash`, and the custom grounds that draw them). The policy
  files were adopted as drafted by controller ruling. Re-scored with the policy's plates hidden, over
  the same 1:1 rasters, **0 of the 95 cases** that could be re-run still exceed 300 on `CAtop` or
  `CA` (the 96th is the named case `case-zero-uncial`, a battle arcane wash). A control run with
  nothing hidden reproduced the sweep's own numbers exactly (owl-transparency 865, smith 1,390,
  warrior 18,032), so the re-score measures the same thing. The warrior r10 cases (10,000 to 22,000px)
  are the whole-page `bg_10` plate, which carries the frame and the ash band.

The consequence is a scoring gap, not an engine gap: Q6 as written cannot pass on those five
families until layer A applies the same underlay policy the profiles use. That change is to
`lib/isolate.ts` / `lib/raster.ts`, which this task did not touch.

### 5.2 Q12

| | cases |
|---|---|
| compared (481 common, less 98 excluded) | 383 |
| clean before, dirty after, on any pair | **4** |
| dirty before, clean after | 31 |
| dirty both | 135 |

Run un-filtered, `bin/regression.mjs` reports 5 regressed and 36 fixed; the fifth regression is
`N_case-zero` (battle), in the excluded set.

The 36 fixed in the un-filtered run, by order: hunter 15, battle 4, hydra 4, custom 4, garber 3, owl 3,
lion 2, dragon 1. The 31 in the table are those less battle (4) and dragon (1), which are in the
excluded set.

### 5.3 The four newly dirty cases

| case | `CA` before | `CA` now | `CAtop` | what it is |
|---|---|---|---|---|
| `B_garber_r10_pirata_max_print` | 191 | 411 | 21 | the citation's last line lands on the tape-measure plate |
| `B_garber_r10_sharp_max_print` | 234 | 396 | 2 | same |
| `B_garber_r10_unicase_max_print` | 213 | 390 | 0 | same |
| `B_owl-cyanotype_r10_unicase_max_print` | 273 | 469 | 230 | text over the owl blueprint-grid underlay; 0 with the policy applied |

The garber three are caused by **this calibration**. Run with the pre-calibration engine (HEAD before
the calibration commit) the same cases score `CA` 165, 160 and 167. The `max` profile's persona is 100
characters of unbreakable tokens: with `NAME_LONG` it now takes four lines where it used to take three
and overflow, which is 90px more height above the citation, and the citation is pushed onto the plate
(screenshot: `.superpowers/sdd/2026-10-25-scrollery-typeset-engine/task-14-visual/garber/B_garber_r10_pirata_max-citation-on-plate.png`,
the citation's last line, "practice.", over the tape measure). Spec decision 10 makes the
synthetic `max` profile reporting-only, and the real-data corpora are unaffected (budget `ok` on all
158 cases), so this is reported rather than fixed: the only repair is in the bottom half (the citation's
floor or `fitReason`), which is out of scope. The owl case was already over before the calibration
(`CA` 346 with the engine as committed, `CAtop` 170); it is a text-on-underlay hit.

## 6. Garber's tape-measure plate

The free-space profile pins four consecutive bands to a 465px interval (frame x 1036 to 1501 or 1504):
bands 15, 16 and 17 (y 775 to 849) and, narrower, band 18 (y 850, x 905 to 1501). The three bands at
the 440px mark the brief asked about are 15 to 17. They sit under the **place** lines and the
Centered composition's lead, **not** under the name or the award.

For the garber corpus cases (rank in brackets), every line against its bands:

| case | place lines (y, x) | slack to the band edge |
|---|---|---|
| c14 (2) | 746 to 833 (1089 to 1461); 833 to 920 (1055 to 1495) | 53/40 and 19/6px |
| c24 (7) | 756 to 879 (1055 to 1495) | 19/6px |
| c36 (6) | 740 to 828 (1055 to 1495); 828 to 915 (1166 to 1384) | 19/6px; 130/117px |
| c47 (5) | 742 to 863 (1138 to 1412); 863 to 984 (1055 to 1495) | 102/89px; 150/6px |

The name and award bands clear: bands 36 to 38 (y 1300 to 1375) carry the needle, `L` 451, 600, 700, and
the name line there has 14 to 17px to spare on c14 and c36, 108px on c24 (the needle is an obstacle,
correctly). The award bands have 181 to 497px.

So the plate squeezes the issuer, not the recipient. On a Classic place it is invisible (c24's
"Tir Fado" fits 1055 to 1495). On a Centered kingdom certificate it is not: in the fresh corpus **s32**
("Winters Edge", Kingdom) the connective "and the Kingdom of" needs 596px at its 0.70em floor in a 446px
band and is flagged overflow, and the place is forced onto two lines; **s02** ("the Emerald Hills") fits
at 0.766em but 3.3px wide (444 against 440.7px). Both are the two fresh-corpus gate failures, and both
fail identically with the engine as it was before the calibration.

Screenshots: `task-14-visual/garber/` (c14, c24, c36, c47, s02, s32). In every one the faint yellow
stripe of the plate (a "thread") runs straight behind the place line, and the type already sits on it
without any visible collision. The profile reads that stripe as an obstacle because layer A is the
whole `garber/rank_N.png`, which also carries the scissors, the numeral and the stripe, and the policy
works per file (`'garber' => []`). **This is a policy question for the owner, not a constant:** the
stripe is a designed underlay, but it cannot be separated from the figures without splitting the plate.
The policy was not changed.

## 7. Q11 bulk

`tools/scroll-sweep/tests/typeset-bulk.spec.ts`: the frozen corpus rendered by `renderFixtures`, each
certificate's page lifted into one document (with its own hand) and repeated to 200. Autofit's fit is
one synchronous loop, so its time is the longest long task the page reports.

| | run 1 | run 2 |
|---|---|---|
| certificates | 200 | 200 |
| left on the first guess (no `data-sc-ms`) | 0 | 0 |
| first fit (longest long task) | 687ms | 517ms |
| a second fit, timed by hand | 1,202ms | 915ms |
| sum of the engine's own layout time | 586ms | 420ms |
| p95 / max layout per certificate | 5.9 / 11.2ms | 4.2 / 8.4ms |
| overflow flagged | 0 | 0 |

Autofit's budget is 6,000ms; the first fit uses about a ninth of it.

## 8. Visual review

`tools/scroll-sweep/out/corpus/index.html` (frozen) and `out/corpus-20261026/index.html` (fresh) are
the filterable contact sheets. Twelve representative top halves are saved in
`.superpowers/sdd/2026-10-25-scrollery-typeset-engine/task-14-visual/` (c22, c26, c05, c33, c08, c11,
c14, c16, c46, c12, c15, x3), for the sign-off of section 10.3.

## 9. Still open

1. **Q6 cannot pass until the sweep applies the underlay policy.** 96 cases (owl, smith, warrior,
   battle arcane, and their custom grounds) are over 300 `CAtop`, all text over a plate the policy
   says it sits on. Fix: hide the policy's plates in layer A in `lib/isolate.ts`.
2. **Fresh corpus fails Q3 and Q9 on two Garber kingdom certificates (s02, s32).** The Garber stripe
   narrows the Centered issuer to 440px. Options for the owner: list the stripe's plate as an
   underlay (the policy is per file, so this loses the scissors and numeral as obstacles on Garber),
   split the plate, or let Centered drop the connective's floor below 0.70em on that band.
3. **Four cases went from clean to dirty in Q12** (section 5.3): three Garber `max`-profile cases
   caused by the fourth name line pushing the citation onto the plate, and one owl-cyanotype case that
   is an underlay hit. The Garber three need a bottom-half fix or a ruling that the synthetic `max`
   profile is exempt, as spec decision 10 already makes it for Q3 and Q6.
4. **`s02`'s 3.3px.** The place's article is set at the connective's size after the place was
   verified, and the line comes out 0.75% wider than its band. A fix belongs in the issuer's
   `converge()`; it was not attempted.
5. **Two stale tests in `scroll-sweep-unit`:** `markup.spec.ts` "centered rules apply when forced" (it
   forces `data-sc-comp` by hand under a running engine, which hides the rule pair; fails identically
   with the engine as it was before the calibration) and `drive.spec.ts` "a certificate renders and
   the autofit pass has run" (it looks for `data-af-base`, a marker of the old name pass the engine
   replaced in T11; `data-sc-ms` is the marker now).
6. **The underlay policy's header** now says it was adopted by controller ruling; the manifest's
   `underlayPolicySha1` was restated for the new bytes by hand rather than by running
   `npm run profile:scrolls`, because no pattern changed and the profiles are identical. The coverage
   test passes.
7. **D3's swash-through-name count and D7's gap sizes** were not re-measured per case; their evidence
   here is `CBtop` and the budget, not a count.
