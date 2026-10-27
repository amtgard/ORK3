# Scrollery Sweep Resolution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix every real defect found by the 150-combination Scrollery sweep. Prove each fix by re-rendering only the sweep cases that exhibit it, plus a few controls that must not change.

**Architecture:** Each defect is fixed in the layer that owns it:
- **Rules and text model (PHP and its JS twin):** A3, A4, B3.
- **Typeset engine (JS):** A1/A2, B1, B2, B8.
- **Per-order CSS:** B4a–c, B7.
- **Shared text CSS:** B4d.
- **Builder page:** A4 hint, A5.
- **Heraldry rendition for scrolls:** B6.

A small extension to the sweep harness re-renders a named subset from the original `matrix.json` and builds before/after sheets, so verification never reruns all 150.

**Tech Stack:** PHP 8.4 (host PHPUnit), plain-PHP `.tpl`, vanilla JS (`scrollery-typeset.js`, `scrollery-autofit.js`, `scrollery-textmodel.js`, `scrollery.js`), CSS (`scrollery.css`), Playwright (`tools/scroll-sweep`), GD for image work.

**Spec:**
- The binding source is `docs/superpowers/specs/2026-10-27-scrollery-sweep-resolution-findings.md`: root causes, recommended fixes, proof and control cases per issue.
- The defect list is `tools/scroll-sweep/out/combo150/REPORT.md`.
- Feature context: `docs/superpowers/specs/2026-10-27-scrollery-customize-design-design.md`.

**Not fixed (recorded as intended art):**
- **B4e:** flame's charred edge on the rank-7 numeral is baked into `rank_7.png` and lines up with the charring in `bg.jpg`.
- **B5:** the Garber thread across the page is part of the plate, drawn under the text.

Tasks must not change either.

## Global Constraints

- **Tests:** run `php vendor/bin/phpunit --no-coverage --filter Scroll` on the host; it must stay green. Baseline on `scrollery`: 835 pass, 10 environmental skips.
- **Design-less certificates stay byte-identical** except where a task's own fix intends a change. Bulk, Court Planner and reprint must still render exactly as before for cases a task doesn't target.
- **Tooltips** use `data-tip`, never `title`. No native `confirm` / `alert` / `prompt`. Style dark mode via `html[data-theme="dark"]`.
- **Layering:** SQL only in `system/lib/ork3/`; `orkui/model` is the only membrane. `.tpl` files are plain PHP.
- **Text model parity:** any rule added to `ScrollText` (PHP) is mirrored exactly in `scrollery-textmodel.js`, with rows in the shared fixture `tests/fixtures/scroll-text-model.json`.
- **Never present unverified art or visuals:** every visual change is eyeballed in the before/after sheets.
- **Commits:**
  - stage by pathspec only, never `git add -A`, and run `git diff --cached` first;
  - never stage `system/lib/ork3/class.Authorization.php`;
  - message prefix `Fix: Scrollery …` (or `Enhancement:` / `Docs:`);
  - end each message with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`;
  - no git stash.
- **Proof cases** are ids from `tools/scroll-sweep/out/combo150/matrix.json`. The original images in `tools/scroll-sweep/out/combo150/images/` are the "before" and are never overwritten.

## Review Focus

1. **A normal certificate changing visibly** because of a fix meant for an extreme input (B1 floor, B2 lever, B4d rule metrics). Every task's control cases must come out pixel-identical, or the visible diff must be explained. Covered by Task 0's diff, run in each task.
2. **The engine reporting one heading while drawing another.** After B1 the heading control and `data-sc-fallback` must agree with what is drawn. Covered by the Task 2 Playwright assertion.
3. **The builder and the server disagreeing on compatibility** after A3: the page must hide Custom Border exactly where the server ignores it. Covered by the Task 6 parity test.
4. **Heraldry keying eating real artwork** in B6: dark shields that touch the corners, or line art on black. Covered by the Task 11 guard tests and the hand-checked list.
5. **The contrast hint in A5 flagging good colours,** or missing bad ones, when match mode or a dark ground changes the effective colour. Covered by the Task 8 Playwright cases.

---

### Task 0: Targeted re-render and before/after diff

**Files:**
- Modify: `tools/scroll-sweep/combo150.spec.ts`, `tools/scroll-sweep/combo150-report.spec.ts`, `tools/scroll-sweep/lib/combo-paths.ts`.
- Commit (on `scrollery` only, never on the PR branch) the combo150 harness that is currently uncommitted:
  - `tools/scroll-sweep/combo150*.spec.ts`, `lib/combos.ts`, `lib/combo-paths.ts`, `bin/combo150-expect.php`;
  - the `lib/raster.ts` cache-key fix;
  - the `playwright.config.ts` projects;
  - the `package.json` script.

**Interfaces:**
- **Produces** `npm run sweep:scrolls:combo150:rerun`, driven by these environment variables:
  - `ORK3_COMBO_IDS` = a comma-separated list of exact case ids. They are rendered with their original params from `out/combo150/matrix.json`, NOT a regenerated matrix.
  - `ORK3_COMBO_TAG` = an output tag. Output goes to `tools/scroll-sweep/out/combo150-rerun/<tag>/`: `images/`, `results.json`, `sheets/`, `diff.md`.
- **Comparison against `out/combo150/`:**
  - **Before/after sheets:** each tile is the before beside the after, labelled with case id + param summary, plus zoomed crops of the changed regions.
  - **`diff.md`:** per case, the pixel-diff fraction, the bounding box of the changed region, and the metric deltas (ink scores, engine flags, overflow, near-knot distance). Each case is listed as "unchanged" (identical pixels) or "changed".

- [ ] **Step 1: Write the harness unit test.** In `tools/scroll-sweep/tests/`:
  - an id-list filter selects exactly those cases from the saved matrix;
  - an unknown id fails loudly;
  - the diff of identical images reports `unchanged`.
- [ ] **Step 2: Run it and see it fail.** `npx playwright test --project=scroll-sweep-unit -g combo` → FAIL.
- [ ] **Step 3: Implement the rerun path and diff.**
- [ ] **Step 4: Verify.**
  - The unit test passes.
  - `ORK3_COMBO_IDS=c045-jovious-r5-park-plain,c003-crown-r8-park-plain ORK3_COMBO_TAG=baseline npm run sweep:scrolls:combo150:rerun` reports both cases **unchanged** against the originals. That proves the rerun is deterministic. Use the login `ORK3_E2E_USERNAME=heraldsbridge ORK3_E2E_PASSWORD=x`.
- [ ] **Step 5: Commit.** `Enhancement: Scrollery sweep — 150-combination harness with targeted rerun and before/after diff`.

### Task 1: B3 — don't print the rank word twice

**Files:**
- Modify: `system/lib/ork3/class.ScrollText.php` (`IssuerLines`) and `orkui/template/revised-frontend/script/scrollery-textmodel.js` (its twin).
- Test: `tests/fixtures/scroll-text-model.json` (shared rows), `tests/Unit/ScrollTextModelTest.php`.

**Interfaces:**
- **The rule, identical in PHP and JS:** when an explicit title T is set and the name matches `^(the |Þe )?T of (.+)$` case-insensitively, keep the title. The printed place is then capture 2, with its own leading "the" kept if present.
  - Worked example: title "Principality", name "the Principality of the Crimson Sands" → "and the Principality of / the Crimson Sands".
- Everything else is unchanged, including the existing split of a stored kingdom phrase when the title is empty.

- [ ] **Step 1: Add fixture rows and tests.**
  - The case above.
  - Title "Grand Duchy" + name "Grand Duchy of Wolven Fang".
  - A non-match: title "Barony" + name "Principality of X" stays as is.
  - The existing kingdom-phrase rows still pass.
- [ ] **Step 2: Run and see it fail.** `php vendor/bin/phpunit --no-coverage --filter ScrollTextModelTest` → FAIL on the new rows. The JS parity spec (`npx playwright test --project=scroll-sweep-unit -g textmodel`) fails as well.
- [ ] **Step 3: Implement in both twins.**
- [ ] **Step 4: Verify.**
  - Both suites pass, and `--filter Scroll` passes.
  - Rerun tag `b3`:
    - proof cases c047, c058, c065, c108, c110: the rank word appears once;
    - controls c015, c018, c013: unchanged.
- [ ] **Step 5: Commit.**

### Task 2: B1 — a forced Classic that can't fit falls back to Centered, and says so

**Files:**
- Modify:
  - `orkui/template/revised-frontend/script/scrollery-typeset.js` (`layoutIssuer` / the Classic solve, the `data-sc-overflow` stamping);
  - `orkui/template/revised-frontend/script/scrollery-hand.js` (`ScrolleryHeading` label).
- Test: `tools/scroll-sweep/tests/typeset-dom-synthetic-issuer.spec.ts` (or `typeset-core.spec.ts`).

**Interfaces:**
- **When the heading is forced Classic:**
  1. solve at the existing floor;
  2. if the PLACE line still overflows its allowed width, retry at a 0.5em floor;
  3. if it still overflows, lay out Centered, and stamp `data-sc-fallback="classic"` on the issuing section.
- **Overflow flag:** `data-sc-overflow` is written only when the place really overflows. Infeasibility caused only by the lead or the art profile no longer sets it.
- **The heading control** reads the stamp and shows `Classic (didn't fit — used Centered)` for that certificate. A bulk page that mixes results shows `Classic (some used Centered)`.
- **Automatic** behaviour is unchanged.

- [ ] **Step 1: Write the Playwright tests.**
  - Forced Classic plus a 70-character place → layout is Centered, `data-sc-fallback="classic"`, and no text box is outside the certificate.
  - Forced Classic plus a typical place → still Classic, with no stamp.
  - The control label text matches.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Verify.**
  - The tests pass, plus the existing typeset suites (`npx playwright test --project=scroll-sweep-unit`).
  - Rerun tag `b1`:
    - proof c064, c104, c106, c132: Centered, nothing off the page;
    - c131 and c091: still Classic, fitting at the lower floor;
    - controls c002, c003, c019, c036, c050, c098: unchanged.
- [ ] **Step 5: Commit.**

### Task 3: B2 — a long reason is not clipped when the name is empty

**Files:**
- Modify: `orkui/template/revised-frontend/script/scrollery-autofit.js`, the top-half budget loop (currently gated `&& fit.name`).
- Test: a Playwright typeset spec in `tools/scroll-sweep/tests/`.

**Interfaces:**
- **New budget lever:** when the name is empty, shrink the hand-written blank's reserved height from 2.55em toward a floor of 1.36em before the reason reaches its 0.34em floor.
- **Unchanged:** the reason floor and the existing clip notice stay.

- [ ] **Step 1: Write the test.** An empty name, an extreme issuer and a ~600-character reason → the reason's scrollHeight is within its box (no clip), and the blank is still ≥ 1.36em.
- [ ] **Step 2: Run and see it fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Verify.**
  - Rerun tag `b2`: proof c022 is not clipped.
  - Controls c077, c035, c040, c114, c058, c131: unchanged.
- [ ] **Step 5: Commit.**

### Task 4: B8 — signatures break at natural seams

**Files:**
- Modify: `orkui/template/revised-frontend/script/scrollery-autofit.js` (the signer fit, `fitInline`).
- Test: Playwright typeset spec.

**Interfaces:**
- **Break points:** before fitting a signer string with no spaces, insert `<wbr>` after `/`, `_`, `-` and `.`, and at lower→Upper camel seams.
- **Display only:** the text content is unchanged, and copy/paste is unaffected.
- **Names keep the engine's documented midpoint last resort.** No change there.

- [ ] **Step 1: Write the test.**
  - `Smiles/Hobi/Can'tCallHimSmileyCanWe?` breaks only at a slash or camel seam, never mid-word.
  - A spaced signer is byte-identical.
- [ ] **Step 2: Run and see it fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Verify.**
  - Rerun tag `b8`: proof c139, c122, c005, c082 break at seams.
  - Controls c001, c065: unchanged.
- [ ] **Step 5: Commit.**

### Task 5: A1 + A2 — the Custom Ribbon clears the heraldry and the drop-cap

**Files:**
- Modify:
  - `orkui/template/revised-frontend/style/scrollery.css`: the `.sc-ribbon` overrides for `#TopTextFlourish` (and the Centered composition);
  - `orkui/template/revised-frontend/script/scrollery-typeset.js`: ornament placement around `typeset.js:2121`, plus `textBoxes()`.
- Test: a Playwright geometry spec in `tools/scroll-sweep/tests/`.

**Interfaces:**
- **CSS:** the top ribbon gets its own size and offset (`.Flourish.sc-ribbon#TopTextFlourish`), tuned on the proof cases. The squiggle rules are untouched.
- **Engine:** when a flourish is `.sc-ribbon`, its ink box is kept clear of:
  - the heraldry `<img>` box;
  - the versal "A" glyph box, added to the obstacles for the top flourish only.
  It nudges or shrinks the ribbon within limits rather than overlapping. Squiggle placement is unchanged.

- [ ] **Step 1: Write the test.** With Custom Ribbon in both Centered and Auto, using the hands from c076, c034, c099 and c011, the top ribbon's ink box must not intersect the heraldry box or the versal box. Use the ink grids (`data-sc-ink`) mapped to page coordinates.
- [ ] **Step 2: Run and see it fail.**
- [ ] **Step 3: Implement, then tune the CSS on the proof cases.**
- [ ] **Step 4: Verify.**
  - Rerun tag `a12`: proof c076, c034, c107, c029, c099, c011 are clear.
  - Controls c045, c046, c001, c006, c122: unchanged, or still clear if they carry a ribbon.
- [ ] **Step 5: Commit.**

### Task 6: A3 — custom scrolls with Garber ranks 7–10 can't take Custom Border

**Files:**
- Modify:
  - `system/lib/ork3/data/scroll-custom-border.php` and `Scroll::CustomBorderRule()`: a rule over the custom recipe's `rankSet` + rank;
  - `orkui/template/revised-frontend/script/scrollery.js`: the JS compatibility mirror, which reads the same data.
- Test: `tests/Unit/ScrollCustomEligibilityTest.php` and `tests/Unit/ScrollDesignTest.php`.

**Interfaces:**
- **Data:** `custom` gains `recipeRankSets => ['garber' => ['minIncompatibleRank' => 7]]`, or an equivalent single data shape that both server and page read.
- **Behaviour:** custom with rankSet `garber` at rank ≥ 7 → `CustomBorderRule` returns `null` and the builder hides the option with the existing tooltip. Ranks 0–6 are unchanged.

- [ ] **Step 1: Write the tests.**
  - Data rows: rank 6 is compatible; ranks 7–10 are not.
  - `BuildScroll` with `customBorder` on at custom/garber/10 → `customBorder` is null.
  - A JS↔PHP parity check over a fixture list of (order, rank, variant, recipe) → compatible. This also closes the earlier follow-up.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Verify.**
  - Rerun tag `a3`:
    - proof c014: no knot; the recipe's own border is drawn, as on any incompatible scroll;
    - control c020 (garber rank 5): unchanged.
- [ ] **Step 5: Commit.**

### Task 7: A4 — near-black colours in a screen-blended match pass draw exact, with a hint

**Files:**
- Modify:
  - `system/lib/ork3/class.Scroll.php` (`BuildScroll`, the customBorder resolution);
  - `orkui/template/revised-frontend/script/scrollery.js` and `Scrollery_create.tpl` (the hint).
- Test: `tests/Unit/ScrollDesignTest.php`, `tests/Unit/ScrollCustomBorderRenderTest.php`.

**Interfaces:**
- **Server:** when `matchStyle` is on and any resolved pass uses `mix-blend-mode:screen` (owl cyanotype, custom on a dark ground), and Color 1 or Color 2 has relative luminance < 0.08:
  - the border renders in exact mode;
  - `customBorder['matchFallback'] = true`;
  - the logged design records `matchStyle: false`.
- **Builder:** the preview response carries `MatchFallback: true|false`. The card shows an `sc-hint` under "Match scroll style?": `Very dark colours can't blend on this background — printed in exact colours.`

- [ ] **Step 1: Write the tests.**
  - owl/cyanotype + match + `#000000` → exact (1 knot), `matchFallback` true.
  - owl/cyanotype + match + `#1E4FA3` → match stays on.
  - owl/parchment + match + `#000000` → match stays on.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Verify.**
  - Rerun tag `a4`: proof c134 shows a continuous knot; control c054: unchanged.
  - Chrome check of the hint on `Scrollery/create`.
- [ ] **Step 5: Commit.**

### Task 8: A5 — contrast hint for knot and ribbon colours

**Files:**
- Modify:
  - `system/lib/ork3/class.Scroll.php`: `Scroll::GroundLuminance(): array`, promoted from `tests/fixtures/scroll-ground-luminance.json` and extended for each fixed order/variant's own ground;
  - `orkui/template/revised-frontend/Scrollery_create.tpl`: `data-ground-lum` on the award options and the custom ground options;
  - `orkui/template/revised-frontend/script/scrollery.js`.
- Test: `tools/scroll-sweep/tests/composer.spec.ts` (Playwright) plus PHPUnit for the luminance map.

**Interfaces:**
- **Calculation:** for each colour control, compute the WCAG contrast of the effective colour against the current ground luminance. The effective colour is the colour itself, or colour × ground when match is on.
- **Threshold:** below 1.5:1, show an `sc-hint` under that control: `Low contrast on this background — this colour may be hard to see.`
- **Advisory only:** nothing is blocked. The hint updates live with colour, award, variant, ground and match changes.

- [ ] **Step 1: Write the tests.**
  - `#F2E6C8` ribbon on a parchment order → hint visible.
  - `#1E4FA3` → hidden.
  - owl cyanotype + `#FFFFFF` → hidden; + `#101820` → visible.
  - Every order and variant has a luminance entry (PHPUnit).
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Verify.**
  - The tests pass.
  - Chrome, in both themes: the hint appears and disappears live.
  - No re-render cases: the hint isn't on the certificate.
- [ ] **Step 5: Commit.**

### Task 9: B7 + B4a–c — bottom-half text clears the order art

**Files:**
- Modify: `orkui/template/revised-frontend/style/scrollery.css` only.

**Interfaces (CSS, per the findings):**
- **B7:** `.award-template[data-order="custom"][data-ground="starfield2"] section.SignAndDate > div { width: 6.2em }`, matching the zodiac order's own compensation for the baked notch.
- **B4a/b:** the battle `section.SignAndDate` gets a `min-height` of about 600px, beside the other per-order SignAndDate rules (~`scrollery.css:1404`). Tune it so the motto clears the rank plates of every battle rank in both variants.
- **B4c:** the crown `SignAndDate > div` boxes get `max-width: 600px`, so a wide signature can't drag the caption onto the laurel.

- [ ] **Step 1: Write a test** in `tests/Unit/` or Playwright. The motto box must not intersect the rank plate's ink (`InkGrid`) for battle arcane and martial at ranks 0, 5, 9 and 10, with an empty and a long reason. The caption box must not intersect the crown laurel's ink with a 70-character signer.
- [ ] **Step 2: Run and see it fail.**
- [ ] **Step 3: Implement and tune.**
- [ ] **Step 4: Verify.**
  - Rerun tag `bottom`:
    - proof c035, c110, c096, c094, c092, c030, c046, c060: clear;
    - controls c044, c029, c052, c065, c117, c075, c011, c127, c129, c003, c010: unchanged or still clear.
  - Every other order: no visual change, because the selectors are order-scoped.
- [ ] **Step 5: Commit.**

### Task 10: B4d — reason rules keep clear of descenders at small sizes

**Files:**
- Modify: `orkui/template/revised-frontend/style/scrollery.css` (`.Underline` metrics, ~L601–621) and/or `scroll/_text.tpl` (the reason container `padding-top`).

**Interfaces:**
- **Fix:** make the underline's height, margin-top and margin-bottom em-based, so they scale with the shrunk reason font. If that can't be done without moving full-size reasons, raise the container `padding-top` from .42em to .50em.
- **Full-size reasons must not move** (the controls).

- [ ] **Step 1: Write the test.** Playwright: at the reason font floor, the gap between each rule's top and the line's descender bottom is ≥ 0.05em. At full size, the rule positions are within 1px of today's.
- [ ] **Step 2: Run and see it fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Verify.**
  - Rerun tag `b4d`:
    - proof c150, c024: clear;
    - controls c110, c009, c089, c031, plus one clean case per order not already listed: unchanged within 1px.
- [ ] **Step 5: Commit.**

### Task 11: B6 — heraldry with a solid black background prints without the black square

**Files:**
- Create: `system/lib/ork3/class.ScrollHeraldry.php` (GD keying + cache).
- Modify:
  - `orkui/model/model.Scrollery.php` (`heraldry_sources()`, ~L1363): ask for the scroll rendition;
  - `system/lib/ork3/class.Heraldry.php`: only if the upload/store path is the right hook to invalidate the cache.
- Test: `tests/Unit/ScrollHeraldryKeyTest.php`, with fixture images under `tests/fixtures/heraldry/`.

**Interfaces:**
- **Method:** `ScrollHeraldry::KeyedUrl(string $sourcePath, string $sourceUrl): string` returns a URL to a keyed PNG when the source qualifies, else `$sourceUrl` unchanged.
- **The source qualifies only when all of these hold:**
  - all four corner patches are near-black (luminance ≤ 0.10);
  - the near-black region connected to the corners covers 5–60% of the image;
  - the image has no meaningful alpha already.
- **Keying:**
  - flood-fill from the corners over near-black pixels;
  - set those pixels transparent, with a 1–2 px feathered edge;
  - never touch interior dark art that isn't connected to a corner.
- **Cache:** keyed files live in a web-served cache directory keyed by the source sha1 + mtime, generated lazily on first request. The originals are never modified.
- **Scope:** only scroll renders use it. Profile and other heraldry surfaces are unchanged.

- [ ] **Step 1: Write the tests** with fixture images:
  - a shield on a black square → keyed, corners transparent, the shield interior intact;
  - a dark shield touching the corners (region > 60%) → untouched;
  - line art on black where the region is > 60% → untouched;
  - an already-transparent PNG → untouched;
  - a cache hit returns the same URL without regenerating.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.** Also run a read-only dry-run over `assets/heraldry/{player,park,kingdom}/` that counts qualifying vs skipped files, and hand-check 20 qualifying and 10 skipped ones in a contact sheet.
- [ ] **Step 4: Verify.**
  - Rerun tag `b6`:
    - proof c088, c019, c021, c020, c068, c093, c071, c127, c067, c094: no black square;
    - controls c053, c047, c030: unchanged.
- [ ] **Step 5: Commit.**

### Task 12: Final targeted verification

**Files:** none new. Fixes go to the owning task's files.

- [ ] **Step 1: Rerun the union** of every task's proof and control ids in one pass, tag `final`.
- [ ] **Step 2: Review.**
  - Read `diff.md`. Every case outside the union that a task touched must be "unchanged"; there are none by construction.
  - Every proof case must be "changed" in the intended region only.
  - Every control must be "unchanged", or carry an explained, accepted change.
  - Eyeball every before/after sheet.
- [ ] **Step 3: Run the full suite.** `php vendor/bin/phpunit --no-coverage --filter Scroll`, `npx playwright test --project=scroll-sweep-unit`, and `sh bin/build-scroll-renditions.sh --check`. All green.
- [ ] **Step 4: Update the report.** Append a "Resolution" section to `tools/scroll-sweep/out/combo150/REPORT.md`: each issue → fixed, with before/after sheet paths, or "intended art", for B4e and B5.
