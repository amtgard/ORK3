# Scrollery Typeset Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the certificate's top-half sizing, breaking and ornament placement with one measured engine. The recipient must always lead, kingdoms and parks must read alike, nothing in the top half may touch ornament or artwork ink, and the planned layout must be what prints.

**Architecture:** A pure text model (PHP `ScrollText` + JS `ScrolleryText`, one shared fixture) splits issuer strings into display parts. A browser engine (`scrollery-typeset.js`) measures words in the actual hand, enumerates line breaks with phrase-aware penalties, solves sizes under hierarchy constraints against per-band free width from offline artwork profiles, chooses the Classic or Centered issuing composition, places flourishes from their ink grids, and writes explicit lines. `scrollery-autofit.js` calls it in place of its four top-half passes; the bottom half is untouched.

**Tech Stack:** PHP 8.x (ORK3 lib conventions), vanilla ES5-style browser JS in UMD modules, CSS, Playwright (`@playwright/test` 1.49+, TS) for Node unit tests and DOM tests, PHPUnit 11, ImageMagick + GD for the ink builder, Docker stack on :19080.

**Spec:** `docs/superpowers/specs/2026-10-25-scrollery-typeset-engine-design.md`. Read it before any task: section numbers below refer to it. The review evidence and the 54 frozen cases are in `tools/scroll-sweep/corpus/review-2026-10-25.json`.

## Global Constraints

- **Git:** stage files by explicit path only. Never `git add -A`, `git add .` or `git commit -a`. Never stage `system/lib/ork3/class.Authorization.php`. Run `git diff --cached --stat` before every commit and confirm only this task's files are staged. Other sessions may share the working tree.
- **Commit messages:** `Enhancement: Scrollery — <what>` (or `Fix: Scrollery — …`), ending with the line `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- **Measurement:** all geometry in frame px (layout px inside the 2550×3300 foreignObject) via `offsetWidth`/`scrollWidth`/`offsetTop`. `getBoundingClientRect()` only for ink boxes, always divided by `frameScale(award)` (spec §6.2).
- **Sizes** are written in `em` relative to the element's parent, so pass 3's root scale carries them.
- **Idempotence:** running the fit twice produces identical DOM; a `textContent` write followed by a fit equals a fresh render of that text (spec §6.2).
- **No native dialogs:** no `alert`, `confirm` or `prompt` anywhere.
- **No new entry points in `orkui/template/default/`.**
- **Every new `bin/*.php`** begins by refusing non-CLI use: `if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }`. nginx executes any `.php` under the repo root.
- **New lib classes in `system/lib/ork3/`** must construct with no arguments. `startup.php` instantiates every class file there.
- **JS modules:** UMD that works both as a browser global and under Node `require()`. The repo has no `"type": "module"`. Pattern: `(function (root, factory) { var api = factory(); if (typeof module === 'object' && module.exports) { module.exports = api; } if (root) { root.NAME = api; } }(typeof window !== 'undefined' ? window : null, function () { … }))`. Touch `window`/`document` only inside functions.
- **Script includes** use the existing `?v=<?= filemtime(...) ?>` pattern.
- **Comments:** match the surrounding files' voice. Explain *why* in plain sentences, cite measurements, and delete or rewrite any comment your change makes untrue. Do not append a contradicting note under a stale one.
- **Constants** are copied verbatim from spec §6.4.1 and §7.1 until T14 calibrates them.
- **The app** must be up at `http://127.0.0.1:19080/orkui/` (Docker `ork3-php8-app`, repo mounted at `/var/www/ork.amtgard.com`) for every Playwright DOM test. Node-only tests do not need it.
- **Sweep units:** scores are absolute weighted ink pixels (`lib/report.ts` `ink()`); thresholds are C∩A 300, C∩B 500, C∩C 95. The README's ppt figures are stale; ignore them.

## Review Focus

- **Unbreakable and very long personas** (`*Michael_Anthony_Wilder_III`, the 100-character personas). Expected: split at `_`/`-`/camelCase, never off the page, never hidden. Pinned in T5 (`fallback splits an unbreakable token`) and T10 (c05, c16 render within width).
- **Empty fields** (blank recipient, blank issuer, blank award on a reprint). Expected: the engine skips the empty role, the writing-line blank keeps its size, and nothing throws. Pinned in T10 (`empty profile renders blanks and reports no overflow`).
- **Composer re-entry.** Typing replaces a role's content while `.sc-line` children exist. Expected: the next fit lays out the new text from scratch. Pinned in T10 (`a textContent write re-lays out from the new text`).
- **Pass 3 after the engine.** `fitColumn` shrinks the root on a long bottom half. Expected: top-half lines still fit their bands afterward. Pinned in T11 (`pass 3 does not break top-half widths`).
- **Glyphs outside the display face** (runic, Hangul, Ş in personas). Expected: words are measured in whatever face actually draws them, and verify catches any miss. Pinned in T10 (`fallback glyphs are measured, not assumed`).

---

## File map

| File | Status | Responsibility | Task |
|---|---|---|---|
| `bin/scroll-render-fixtures.php` | create | CLI: render certificate specs → HTML files (no login, no print log) | T1 |
| `tools/scroll-sweep/lib/fixture.ts` | create | run the CLI via docker, serve fixtures same-origin, open + settle | T1 |
| `tools/scroll-sweep/tests/fixture.spec.ts` | create | fixture renderer smoke + BLOCK_SEL still matches | T1 |
| `tools/scroll-sweep/corpus/review-2026-10-25.json` | exists (untracked) | frozen 54-case corpus; commit in T1 | T1 |
| `tools/scroll-sweep/corpus.spec.ts` | create | corpus capture, metrics, contact sheet (T1); gates + random sample (T13) | T1, T13 |
| `.gitignore` | modify | ignore `tools/scroll-sweep/out/` | T1 |
| `tools/scroll-sweep/lib/drive.ts`, `lib/isolate.ts`, `lib/raster.ts`, `lib/report.ts`, `lib/text.ts` | modify | fixture driver, `Ctop` layer, `CAtop`/`CBtop`, `kingdomReal` profile | T1b |
| `tools/scroll-sweep/baseline/results-1to1-head-2026-10-25.json` | create | fresh regression baseline at HEAD | T1b |
| `system/lib/ork3/class.ScrollText.php` | create | `ScrollText::IssuerLines()` | T2 |
| `orkui/template/revised-frontend/script/scrollery-textmodel.js` | create | `ScrolleryText.issuerLines()` | T2 |
| `tests/fixtures/scroll-text-model.json` | create | shared PHP/JS cases | T2 |
| `tests/Unit/ScrollTextModelTest.php` | create | PHP side of the fixture + BuildScroll keys | T2 |
| `tools/scroll-sweep/tests/textmodel.spec.ts` | create | JS side of the fixture | T2 |
| `system/lib/ork3/class.Scroll.php` | modify | BuildScroll keys + Inactive (T2); FreeSpace, ink grid helper, headings, cache key (T7) | T2, T7 |
| `orkui/model/model.Scrollery.php` | modify | kingdom scope prefill in split form; Inactive title | T2 |
| `bin/build-scroll-ink-extents.php`, `assets/scrolls/ink-extents.json` | modify | 32×32 flourish ink grids | T3 |
| `tests/Unit/ScrollInkExtentsTest.php` | modify | grid assertions | T3 |
| `orkui/template/revised-frontend/script/scrollery-typeset.js` | create | engine: pure core (T4, T5), DOM layer (T10, T11) | T4, T5, T10, T11 |
| `tools/scroll-sweep/tests/typeset-core.spec.ts` | create | Node tests for the pure core | T4, T5 |
| `system/lib/ork3/data/scroll-layout-underlays.php` | create | which plates type may sit on | T6 |
| `tools/scroll-sweep/underlays.spec.ts` | create | layer-by-layer review sheet | T6 |
| `tests/Unit/ScrollLayoutUnderlaysTest.php` | create | policy file shape + no dead patterns | T6 |
| `tests/Unit/ScrollFreeSpaceTest.php` | create | lookup, intersection, attribute format, cache key | T7 |
| `tests/Unit/ScrollHeadingTest.php` | create | heading allowlist | T7 |
| `orkui/template/revised-frontend/scroll/_text.tpl` | modify | markup contract (spec §8.1) | T8 |
| `orkui/template/revised-frontend/style/scrollery.css` | modify | compositions, lines, rhythm (spec §8.2) | T8 |
| `orkui/template/revised-frontend/Scrollery_render.tpl`, `Scrollery_create.tpl` | modify | script includes, `data-sc-heading` | T8 (create.tpl select: T12) |
| `tests/Unit/ScrollPartialsTest.php` | modify | markup contract assertions | T8 |
| `tools/scroll-sweep/profile.spec.ts`, `tools/scroll-sweep/fixtures/heraldry-standin.png` | create | free-space profile builder | T9 |
| `assets/scrolls/free-space.json` | create | generated profiles | T9 |
| `tests/Unit/ScrollFreeSpaceCoverageTest.php` | create | every config has a profile | T9 |
| `playwright.config.ts`, `package.json` | modify | projects + npm scripts (`profile:scrolls`, `sweep:scrolls:corpus`) | T9, T13 |
| `orkui/template/revised-frontend/script/scrollery-autofit.js` | modify | call the engine; fall back; later delete old passes | T10, T15 |
| `tools/scroll-sweep/tests/typeset-dom.spec.ts` | create | DOM tests on fixtures | T10, T11 |
| `orkui/template/revised-frontend/script/scrollery.js` | modify | `applyText` through the text model; heading select | T12 |
| `tools/scroll-sweep/tests/composer.spec.ts` | create | preview vs print parity (needs credentials) | T12 |
| `docs/scrollery-typeset-results.md` | create | measured results of the final run | T14 |
| `docs/scrollery-asset-pipeline.md`, `docs/scrollery-deferred.md`, `tools/scroll-sweep/README.md` | modify | regeneration notes, bin exposure finding, new modes | T15 |

## Execution waves

Tasks in one wave touch disjoint files and may run in parallel, one subagent each. Each
task gets a fresh reviewer before its wave closes.

| Wave | Tasks | Gate before next wave |
|---|---|---|
| 1 | T1, T2, T3, T4 | — |
| 2 | T1b (after T1), T5 (after T4), T6 (after T1), T7 (after T2) | T1b's baseline MUST be committed before any wave-3 task starts: wave 3 is the first that changes rendering |
| 3 | T1b Step 5 (baseline capture, alone: nothing else edits the tree while it runs), then T8 (after T1b, T2, T3, T7) | — |
| 4 | T9 (after T1, T6, T7, T8; uses the provisional underlay file), T10 (after T5, T8) | — |
| 5 | T11 (after T9, T10), T12 (after T2, T10) | — |
| 6 | T13 (after T1, T11) | — |
| 7 | T14 | Owner's underlay sign-off (from T6) applied and profiles regenerated before Step 1; owner visual sign-off at the end |
| 8 | T15 | — |

---

### Task 1: Login-free fixture renderer

**Files:**
- Create: `bin/scroll-render-fixtures.php`
- Create: `tools/scroll-sweep/lib/fixture.ts`
- Create: `tools/scroll-sweep/tests/fixture.spec.ts`
- Create: `tools/scroll-sweep/corpus.spec.ts` (capture and report only; gates come in T13)
- Commit: `tools/scroll-sweep/corpus/review-2026-10-25.json` (already written; do not edit)
- Modify: `.gitignore` (add `tools/scroll-sweep/out/`)
- Modify: `playwright.config.ts` (project `scroll-corpus`: `testDir: './tools/scroll-sweep'`, `testMatch: 'corpus.spec.ts'`, `timeout: 0`, `use: { ...devices['Desktop Chrome'], viewport: { width: 2600, height: 3500 }, deviceScaleFactor: 3 }`), `package.json` (`"sweep:scrolls:corpus": "playwright test --project=scroll-corpus"`)

**Interfaces:**
- Produces: CLI `php bin/scroll-render-fixtures.php <specs.json> <outDir>`. `specs.json` is either an array of `FixtureSpec` or `{ "cases": FixtureSpec[] }`. Writes `<outDir>/<id>.html`; prints `<id> ok (<order>)` or `<id> ERROR <message>` per spec to stderr.
- Produces (TS, `lib/fixture.ts`):
  - `type FixtureSpec = { id: string; hand: string; order: string; awardId: number; title: string; variant: string; rank: number; scope: 'park'|'kingdom'; issuerName: string; issuerTitle: string; useThe: boolean; useOf: boolean; player: string; pronoun: string; reason: string; givenBy: string; date: string; recipe?: { ground: string; border: string; rankSet: string }; heading?: 'auto'|'classic'|'centered'; heraldry?: string; up?: 0|2|4 }`
  - `loadCorpus(name: string): FixtureSpec[]` reads `tools/scroll-sweep/corpus/<name>.json` (`.cases`).
  - `renderFixtures(specs: FixtureSpec[], tag: string): string` writes the specs to `tools/scroll-sweep/out/fixtures/<tag>/specs.json`, runs the CLI with `docker exec ${ORK3_APP_CONTAINER ?? 'ork3-php8-app'} php ${ORK3_CONTAINER_ROOT ?? '/var/www/ork.amtgard.com'}/bin/scroll-render-fixtures.php <container path to specs> <container path to dir>` (paths mapped from the host repo root), and returns the host directory.
  - `serveFixtures(page: Page, dir: string): Promise<void>` routes `**/orkui/__fixture/**`. It fulfils `<id>.html` from `dir` and `heraldry-standin.png` from `tools/scroll-sweep/fixtures/`.
  - `openFixture(page: Page, spec: FixtureSpec): Promise<void>` does `goto('./__fixture/<id>.html?face=<hand>' + (spec.up ? '&up=' + spec.up : ''))`, then `settle(page)` from `lib/drive.ts`.
- Produces (`corpus.spec.ts`): env `ORK3_CORPUS` (default `review-2026-10-25`) names the corpus file; env `ORK3_CORPUS_TAG` (default `corpus`) names the output directory `tools/scroll-sweep/out/<tag>/`. Per case it writes the full certificate PNG and a top-half crop (from y 0 to the bottom of `.Order` + 80 frame px). `report.json` holds `{ id, comp, rootEm, roles: { name|award|place|lead: { sizeEm, sizePt, lines, planned?, overflow? } }, budget?, ms? }`. `sizePt` = px × 72/300. Line counts are measured independently by clustering `Range.getClientRects()` bottoms (half a line-height tolerance, in rect units). Attributes the engine has not written yet are omitted. `report.md` has summary counts; `index.html` is a contact sheet of the crops with each case's caption and sizes. Measure each role on its **text element**, whose computed font-size is the effective size before and after the redesign: name `[data-scroll-text="player"]`, award `.Order`, place `[data-scroll-text="issuerName"]`, lead `#IssuerTitle`. `sizeEm` = that element's computed px ÷ `.Award` computed px. `rootEm` = `.Award` computed px ÷ 100, which records pass 3.

- [ ] **Step 1: Write the failing tests** in `tools/scroll-sweep/tests/fixture.spec.ts`

```ts
import { test, expect } from '@playwright/test';
import { loadCorpus, renderFixtures, serveFixtures, openFixture } from '../lib/fixture';
import { BLOCK_SEL } from '../lib/isolate';

test('fixture renderer draws the requested order and hand without a login', async ({ page }) => {
  const specs = loadCorpus('review-2026-10-25').filter(c => c.id === 'c22' || c.id === 'x2');
  const dir = renderFixtures(specs, 'fixture-smoke');
  await serveFixtures(page, dir);
  await openFixture(page, specs.find(s => s.id === 'c22')!);
  await expect(page.locator('.award-template')).toHaveAttribute('data-order', 'warrior');
  expect(await page.evaluate(() => getComputedStyle(document.querySelector('.Award')!).fontFamily)).toContain('BlackChancery');
  await openFixture(page, specs.find(s => s.id === 'x2')!);
  await expect(page.locator('.award-template')).toHaveAttribute('data-order', 'rose');
  expect(await page.evaluate(() => getComputedStyle(document.querySelector('.Award')!).fontFamily)).toContain('Uncial Antiqua');
});

test('every sweep text block still matches on a rendered certificate', async ({ page }) => {
  const specs = loadCorpus('review-2026-10-25').filter(c => c.id === 'c22');
  await serveFixtures(page, renderFixtures(specs, 'fixture-blocks'));
  await openFixture(page, specs[0]);
  for (const sel of BLOCK_SEL.split(',')) {
    expect(await page.locator(sel).count(), sel).toBeGreaterThanOrEqual(1);
  }
});

test('the fixture renderer is not reachable over the web', async ({ request }) => {
  const r = await request.get('http://127.0.0.1:19080/bin/scroll-render-fixtures.php');
  expect(r.status()).toBe(404);
});
```

- [ ] **Step 2: Run, expect failure**

Run: `npx playwright test --project=scroll-sweep-unit tools/scroll-sweep/tests/fixture.spec.ts`
Expected: FAIL (`Cannot find module '../lib/fixture'`).

- [ ] **Step 3: Implement `bin/scroll-render-fixtures.php`.** Use the CLI guard. Set `$_SERVER['HTTP_HOST'] = getenv('ORK3_FIXTURE_HOST') ?: '127.0.0.1:19080'`, `REQUEST_METHOD=GET`, `chdir(<repo>/orkui)`, include `../startup.php`, and define `UIR` if absent.

  For each spec, build the award row: `AwardId`, `OfficerRole => 'none'`, `Rank`, `Date`, `Note` = reason, `GivenBy`, `KingdomAwardName` = title, `ParkName`/`KingdomName` by scope, `IsLadder => 1`. Build the context: `player`, `pronoun`, `heraldry` (spec value or `''`), `customName` = title when order is `custom`, `issuerTitle`, `useThe`, `useOf`, `variant`, `recipe`.

  Then call `Scroll::BuildScroll()`, set `$_GET = ['face' => hand, 'heading' => heading ?? 'auto']` (plus `up`), and include `Scrollery_render.tpl` through a static closure with `['Scrolls' => [$s], 'PageTitle' => $id]`, exactly as `controller.Scrollery::render_bare()` does, inside `ob_start()`. Write the file. Catch `Throwable` per spec and keep going.

- [ ] **Step 4: Implement `lib/fixture.ts`** to the Interfaces above. `?face=` MUST be in the URL: the render page's inline script otherwise re-applies the remembered hand.

- [ ] **Step 5: Run, expect pass**

Run: `npx playwright test --project=scroll-sweep-unit tools/scroll-sweep/tests/fixture.spec.ts`
Expected: 3 passed.

- [ ] **Step 6: Implement `corpus.spec.ts`** (capture and report per the Interfaces), then run it on the frozen corpus at HEAD:

Run: `npm run sweep:scrolls:corpus`
Expected: `tools/scroll-sweep/out/corpus/report.json` has 54 entries. In it, c05's name is 3 lines and c14's award is 2 lines, matching the review. `index.html` opens and shows 54 crops.

- [ ] **Step 7: Commit**

```bash
git add bin/scroll-render-fixtures.php tools/scroll-sweep/lib/fixture.ts tools/scroll-sweep/tests/fixture.spec.ts tools/scroll-sweep/corpus.spec.ts tools/scroll-sweep/corpus/review-2026-10-25.json .gitignore playwright.config.ts package.json
git diff --cached --stat
git commit -m "Enhancement: Scrollery — login-free fixture renderer and the frozen review corpus

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 1b: Sweep — fixture driver, top-half scoring, the fresh baseline

**Files:**
- Modify: `tools/scroll-sweep/lib/drive.ts`, `tools/scroll-sweep/lib/isolate.ts`, `tools/scroll-sweep/lib/raster.ts`, `tools/scroll-sweep/lib/report.ts`, `tools/scroll-sweep/lib/text.ts`, `tools/scroll-sweep/sweep.spec.ts` (only where the driver mode and the new scores need threading through)
- Create: `tools/scroll-sweep/tests/topscore.spec.ts`, `tools/scroll-sweep/baseline/results-1to1-head-2026-10-25.json`, `tools/scroll-sweep/baseline/corpus-before-2026-10-25.json`

**Interfaces:**
- Consumes: T1 `FixtureSpec`, `renderFixtures`, `serveFixtures`, `openFixture`.
- Produces:
  - `ORK3_SWEEP_DRIVER=fixture`. `openPrint` converts the `SweepCase` and its `TextProfile` into a `FixtureSpec`:
    - `awardId` = the order's `award_id` from `scroll-orders.php` (`hunter`: 0, title `Order of the Hunter`);
    - `title` = `Order of the <Order>` style catalogue name, or the profile's `customName` for custom;
    - `scope` = `kingdom`.

    It then renders it through `lib/fixture.ts`. In this mode the login, the catalogue lookup and `unreachable-orders.json` are bypassed (`hunter` becomes reachable). Batch the render: one `renderFixtures` call per shard, not one per case.
  - Layer `Ctop` in `LAYER_CSS`: layer C plus `color: transparent; -webkit-text-fill-color: transparent` on every block after `.Order` (`.Award > section.Body:not(.Award_Reason):not(.Flavour)`, `.Award_Reason`, `.Flavour`, `.AwardDate`, `.AwardSignatory`).
  - `Result` gains `CAtop` and `CBtop`, scored exactly like `CA`/`CB` but with `Ctop` as the glyph mask. `report.ts` prints them in their own columns, and `isDirtyTop(r)` uses the same thresholds (300/500).
  - Text profile `kingdomReal` in `lib/text.ts`: `real` with `issuerTitle: ''`, `useThe: false`, `useOf: false`, `issuerName: 'The Principality of the Crimson Sands'`. Add it to `PROFILE_NAMES`.

- [ ] **Step 1: Write the failing test** `topscore.spec.ts` (fixture driver; no login):
  - `Ctop hides only the bottom half`: on the c22 fixture, `applyLayer(page, 'Ctop')`. `.Order`'s computed `color` is unchanged, `.Award_Reason`'s is `rgba(0, 0, 0, 0)`, and the `.AwardeeName` box is identical to layer `full` (no reflow).
  - `the fixture driver reaches hunter`: `openPrint` with a hunter `SweepCase` under `ORK3_SWEEP_DRIVER=fixture` renders `.award-template[data-order="hunter"]`.
- [ ] **Step 2: Run, expect failure:** `ORK3_SWEEP_DRIVER=fixture npx playwright test --project=scroll-sweep-unit tools/scroll-sweep/tests/topscore.spec.ts`.
- [ ] **Step 3: Implement** the Interfaces.
- [ ] **Step 4: Run, expect pass,** plus `npx playwright test --project=scroll-sweep-unit tools/scroll-sweep/tests/isolate.spec.ts tools/scroll-sweep/tests/mask.spec.ts` green (isolate.spec also under `ORK3_SWEEP_DRIVER=fixture`).
- [ ] **Step 5: Capture the baseline at HEAD.** No rendering change may be in the tree yet. The files that change rendering must be untouched since the plan began:

Run: `git diff --stat <commit before this plan's first commit> -- orkui/template/revised-frontend/scroll orkui/template/revised-frontend/style/scrollery.css orkui/template/revised-frontend/script/scrollery-autofit.js orkui/template/revised-frontend/Scrollery_render.tpl`
Expected: empty. The new `scrollery-textmodel.js` and `scrollery-typeset.js` are not yet included by any page, so they do not count.
  - Run: `ORK3_SWEEP_DRIVER=fixture ORK3_SWEEP_STAGES=NAB npm run sweep:scrolls`, then copy `tools/scroll-sweep/out/results.json` to `tools/scroll-sweep/baseline/results-1to1-head-2026-10-25.json`.
  - Then: `ORK3_SWEEP_DRIVER=fixture ORK3_SWEEP_STAGES=C npm run sweep:scrolls` and merge its results into the same baseline file.
  - Then: `npm run sweep:scrolls:corpus` and copy `out/corpus/report.json` to `tools/scroll-sweep/baseline/corpus-before-2026-10-25.json`.
  - Record the wall-clock times in the task report.
- [ ] **Step 6: Commit** the modified libs, the test and both baseline files (`Enhancement: Scrollery sweep — fixture driver, top-half scores, and a baseline at HEAD`).

---

### Task 2: Text model (PHP + JS, one fixture)

**Files:**
- Create: `system/lib/ork3/class.ScrollText.php`, `orkui/template/revised-frontend/script/scrollery-textmodel.js`, `tests/fixtures/scroll-text-model.json`, `tests/Unit/ScrollTextModelTest.php`, `tools/scroll-sweep/tests/textmodel.spec.ts`
- Modify: `system/lib/ork3/class.Scroll.php` (BuildScroll only: the issuer title normalisation near `:1460` and the return array near `:1544`), `orkui/model/model.Scrollery.php` (`scope_context()` both branches)

**Interfaces:**
- Produces: `ScrollText::IssuerLines(string $title, string $name, bool $useThe, bool $useOf, string $scopeType = ''): array{lead: string, article: string, place: string, title: string, useThe: bool, useOf: bool, name: string}`. The last four keys are the effective (possibly split) form, used for composer prefill.
- Produces: `ScrolleryText.issuerLines(title, name, useThe, useOf, scopeType)`, returning the same keys.
- Produces: BuildScroll keys `issuerLead`, `issuerArticle`, `issuerPlace` (strings). `issuerTitle`, `issuerName`, `useThe` and `useOf` are unchanged in meaning.

Rules: spec §5.2, with these exact refinements:

1. The title is trimmed and whitespace collapsed. Case-insensitive `inactive` becomes `''`.
2. Kingdom split applies only when title `''`, `useThe === false` and `useOf === false`.
3. `the`/`of` are emitted only when the effective title is non-empty. When the title is empty, the lead is `and` and the place is the whole name.
4. The article is detached only when the lead ends in ` of`.

- [ ] **Step 1: Write the fixture.** `tests/fixtures/scroll-text-model.json`:
`{"_comment": "...", "cases": [{"name": "...", "in": {"title": "", "name": "", "useThe": false, "useOf": false, "scope": "kingdom"}, "want": {"lead": "", "article": "", "place": ""}}]}`.

  Include every row below. The expected values are pinned; the agent adds the obvious remaining "The Kingdom of X" rows from the list.
  - Kingdom-unsplit form (`"" · name · false · false · kingdom`):
    - `The Kingdom of the Wetlands` → `and the Kingdom of` · `the` · `Wetlands`
    - `The Principality of The Painted Skies` → `and the Principality of` · `the` · `Painted Skies`
    - `The Principality of the Crimson Sands` → `and the Principality of` · `the` · `Crimson Sands`
    - `Kingdom of 13 Roads` → `and the Kingdom of` · `` · `13 Roads`
    - `Kingdom of the Desert Winds` → `and the Kingdom of` · `the` · `Desert Winds`
    - `The Empire of the Iron Mountains` → `and the Empire of` · `the` · `Iron Mountains`
    - `The Empire of Rivermoor` → `and the Empire of` · `` · `Rivermoor`
    - `The Freeholds of Amtgard` → `and the Freeholds of` · `` · `Amtgard`
    - `The Confederacy of Dreadmoor` → `and the Confederacy of` · `` · `Dreadmoor`
    - `The Celestial Kingdom` → `and` · `` · `The Celestial Kingdom`
    - `The Golden City` → `and` · `` · `The Golden City`
    - `The Valley of the Silver Rains` → `and` · `` · `The Valley of the Silver Rains`
    - `Uncharted Wastelands` → `and` · `` · `Uncharted Wastelands`
    - `Þe Olde Records Empire` → `and` · `` · `Þe Olde Records Empire`
    - `Northern Lights`, `Soul's Crossing`, `Souls Crossing`, `Spheniscidae Alliance` → `and` · `` · *(name)*
    - Every `The Kingdom of X` row (Blackspire, Burning Lands, Crystal Groves, Dragonspine, Goldenvale, Neverwinter, Northern Lights, Northreach, Polaris, Tal Dagore, the Emerald Hills, the Golden Plains, the Mystic Seas, the Nine Blades, the Rising Winds, Viridian Outlands, Westmarch, Winters Edge) → `and the Kingdom of` · (`the` if X starts with "the ") · X without "the ".
    - `The Principality of Western Gate` → `and the Principality of` · `` · `Western Gate`
  - Park and edited forms:
    - `Shire · The Everglade Sentinels · T · T · park` → `and the Shire of` · `the` · `Everglade Sentinels`
    - `Shire · The River's End · T · T` → `and the Shire of` · `the` · `River's End`
    - `Grand Duchy · Mountain of the Sixth Dawn · T · T` → `and the Grand Duchy of` · `` · `Mountain of the Sixth Dawn`
    - `Barony · Iron Keep · T · T` → `and the Barony of` · `` · `Iron Keep`
    - `Inactive  · Foo · T · T · park` → `and` · `` · `Foo` (the model alone; BuildScroll supplies Barony first, see Step 3)
    - `Kingdom · Crystal Groves · T · T · kingdom` → `and the Kingdom of` · `` · `Crystal Groves`
    - `"" · Crystal Groves · T · T` → `and` · `` · `Crystal Groves`
    - `"" · "" · F · F` → `and` · `` · ``
    - `"  Barony  " · "  Iron   Keep " · T · T` → `and the Barony of` · `` · `Iron Keep`

- [ ] **Step 2: Write the failing tests.**

```php
// tests/Unit/ScrollTextModelTest.php
final class ScrollTextModelTest extends TestCase
{
    public static function cases(): array { /* load fixture, key by name => [in, want] */ }

    #[DataProvider('cases')]
    public function testIssuerLinesMatchTheSharedFixture(array $in, array $want): void
    {
        $got = \ScrollText::IssuerLines($in['title'], $in['name'], $in['useThe'], $in['useOf'], $in['scope']);
        $this->assertSame($want, array_intersect_key($got, $want));
    }

    public function testBuildScrollCarriesTheSplitLinesAndKeepsTheStoredName(): void
    {
        // row/context helpers as in ScrollBuildTest; kingdom-issued, KingdomName 'The Kingdom of the Wetlands'
        $this->assertSame('and the Kingdom of', $s['issuerLead']);
        $this->assertSame('the', $s['issuerArticle']);
        $this->assertSame('Wetlands', $s['issuerPlace']);
        $this->assertSame('The Kingdom of the Wetlands', $s['issuerName']);
    }

    public function testAnInactiveParkTitleFallsBackToBarony(): void
    {
        // park-issued, context issuerTitle 'Inactive '
        $this->assertSame('Barony', $s['issuerTitle']);
        $this->assertSame('and the Barony of', $s['issuerLead']);
    }
}
```

```ts
// tools/scroll-sweep/tests/textmodel.spec.ts
import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
const T = require('../../../orkui/template/revised-frontend/script/scrollery-textmodel.js');
const fx = JSON.parse(readFileSync('tests/fixtures/scroll-text-model.json', 'utf8'));
for (const c of fx.cases) {
  test(`issuerLines: ${c.name}`, () => {
    const got = T.issuerLines(c.in.title, c.in.name, c.in.useThe, c.in.useOf, c.in.scope);
    expect({ lead: got.lead, article: got.article, place: got.place }).toEqual(c.want);
  });
}
```

- [ ] **Step 3: Run both, expect failure.**
`vendor/bin/phpunit tests/Unit/ScrollTextModelTest.php` → errors (class `ScrollText` not found).
`npx playwright test --project=scroll-sweep-unit tools/scroll-sweep/tests/textmodel.spec.ts` → fails (module not found).

- [ ] **Step 4: Implement** `ScrollText::IssuerLines()` (static, no constructor arguments, does not extend `Ork3`) and `ScrolleryText.issuerLines()` to the rules above. The kingdom regex is spec §5.2 rule 3, with `u` and `i` flags; JS uses `/…/i` with no `u` dependency.

  In BuildScroll, treat a trimmed `inactive` title as `''` before the existing `'Barony'` fallback. Add the three keys from `ScrollText::IssuerLines($issuerTitle, $issuerName, useThe, useOf, $isPark ? 'park' : 'kingdom')`.

  In `scope_context()`, have the kingdom branch return the split form (`Title`, `UseThe`, `UseOf`, `Name` from `IssuerLines`, with the article re-attached to `Name`, e.g. `the Wetlands`). Have the park branch treat an `Inactive` title as empty, which falls back to `Barony`.

- [ ] **Step 5: Run the new tests plus the existing ones they touch.**
`vendor/bin/phpunit tests/Unit/ScrollTextModelTest.php tests/Unit/ScrollBuildTest.php` → all pass.
`npx playwright test --project=scroll-sweep-unit tools/scroll-sweep/tests/textmodel.spec.ts` → all pass.

- [ ] **Step 6: Commit** the seven files by path (`git add <each path>`; `git diff --cached --stat`; commit `Enhancement: Scrollery — issuer text model, shared by PHP and the composer`).

---

### Task 3: Flourish ink grids

**Files:**
- Modify: `bin/build-scroll-ink-extents.php`, `assets/scrolls/ink-extents.json` (regenerated), `tests/Unit/ScrollInkExtentsTest.php`

**Interfaces:**
- Produces: each flourish entry in `ink-extents.json` `files` gains `"grid": {"w": 32, "h": 32, "rows": [32 × 8-hex-char strings]}`. Bit 31 (MSB) of a row is the left-most cell. A cell is inked if any pixel in it has alpha ≥ the manifest's `inkFloor`; then dilate by one cell (8-neighbour). Flourish files: every value of `Scroll::DEFAULT_RESOURCES` and every `flourish_*` resource in `scroll-orders.php` (rose, jovious).

- [ ] **Step 1: Write the failing tests** (add to `ScrollInkExtentsTest`):

```php
public function testEveryFlourishHasA32x32Grid(): void
{
    foreach ($this->flourishFiles() as $rel) {          // DEFAULT_RESOURCES + orders' flourish_* resources
        $g = $this->manifest()[$rel]['grid'] ?? null;
        $this->assertNotNull($g, "$rel has no grid");
        $this->assertSame(32, $g['w']); $this->assertSame(32, $g['h']);
        $this->assertCount(32, $g['rows']);
        foreach ($g['rows'] as $row) { $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $row); }
        $this->assertNotSame(str_repeat('00000000', 32), implode('', $g['rows']), "$rel grid is empty");
    }
}

public function testTheBottomSwashGridStopsWhereItsInkDoes(): void
{
    // squiggly-bottom ink ends at x1 = 0.8924 → cell 28; dilation allows 29; cells 30-31 empty in every row.
    $rows = $this->manifest()['common/text/squiggly-bottom.svg']['grid']['rows'];
    foreach ($rows as $row) { $this->assertSame(0, hexdec($row) & 0x3); }
}
```

- [ ] **Step 2: Run, expect failure:** `vendor/bin/phpunit tests/Unit/ScrollInkExtentsTest.php` → the two new tests fail.
- [ ] **Step 3: Implement the grid pass** in the builder, reusing its existing rasterisation of each file at `raster` resolution.
- [ ] **Step 4: Regenerate:** `php bin/build-scroll-ink-extents.php` (host has `magick` and GD). Then `git diff --stat assets/scrolls/ink-extents.json`: only `grid` fields are added; existing `ink` boxes are unchanged.
- [ ] **Step 5: Run, expect pass:** `vendor/bin/phpunit tests/Unit/ScrollInkExtentsTest.php` → all pass.
- [ ] **Step 6: Commit** the three files (`Enhancement: Scrollery — ink grids for every flourish`).

---

### Task 4: Engine core — tokenize and break penalties

**Files:**
- Create: `orkui/template/revised-frontend/script/scrollery-typeset.js` (UMD global `ScrolleryTypeset`; this task adds only `CONST`, `tokenize`, `breakPenalty`)
- Create: `tools/scroll-sweep/tests/typeset-core.spec.ts`

**Interfaces:**
- Produces: `ScrolleryTypeset.CONST`, with `ROLES` = the spec §6.4.1 table keyed `name`, `award`, `placeClassic`, `placeCentered`, `lead` (each `{min, max, sat, maxLines, lineHeight, linePenalty: number[]}`), plus `VERSAL: {min: 1.20, max: 1.495}`, `SLACK`, `RAGGED`, `WIDOW`, `MINOR_SCALE`, `MINOR_PENALTY`, `MINOR_LINES: true`, `CLASSIC_PLACE_MIN`, `ORN_GAP`, `H_TOP_MIN: 0.40`, `RATIOS: {awardToName: 0.85, placeToName: 0.75, placeToAward: 0.90}`, `HONORIFICS`, `WEAK_END`, `SUFFIXES`, and penalty values named `P_WEAK_END: 60`, `P_PUNCT: -20`, `P_HON_END: -25`, `P_HON_SPLIT: 30`, `P_EPITHET: -8`, `P_SUFFIX: 50`, `P_INITIAL: 40`, `P_AWARD_BEFORE_OF: -10`.
- Produces: `tokenize(text: string, role: 'name'|'award'|'place'): Array<{text: string, honorific: boolean, epithetStart: boolean}>`. Protected spans are one token joined by U+00A0.
- Produces: `breakPenalty(tokens, i: number, role): number`, the additive sum of spec §6.3.2 for the gap after token `i`.

- [ ] **Step 1: Write the failing tests:**

```ts
const TS = require('../../../orkui/template/revised-frontend/script/scrollery-typeset.js');
const texts = (s: string, r = 'name') => TS.tokenize(s, r).map((t: any) => t.text);
const flags = (s: string, k: string) => TS.tokenize(s, 'name').map((t: any) => t[k]);

test('quoted nickname is one token', () => expect(texts('William "Billy Bob" FitzRobert')).toEqual(['William', '"Billy Bob"', 'FitzRobert']));
test('curly quotes and parentheses are protected', () => {
  expect(texts('Şadhibek “The Gillie” Asdvadzadurian')).toEqual(['Şadhibek', '“The Gillie”', 'Asdvadzadurian']);
  expect(texts('Tarmak Silverblade (or) Kamar Silverblade')).toEqual(['Tarmak', 'Silverblade', '(or)', 'Kamar', 'Silverblade']);
});
test('whitespace collapses', () => expect(texts('Sir Hitorimono,  the Scapegoat')).toEqual(['Sir', 'Hitorimono,', 'the', 'Scapegoat']));
test('leading honorific run', () => expect(flags('Captain Sir Lord Kato Darkhaven Grimwulff', 'honorific')).toEqual([true, true, true, false, false, false]));
test('an honorific needs a name after it', () => expect(flags('Lady', 'honorific')).toEqual([false]));
test('Grand counts only before a rank', () => {
  expect(flags('Syr Grand Marquis Noodles', 'honorific')).toEqual([true, true, true, false]);
  expect(flags('Grand Hall', 'honorific')).toEqual([false, false]);
});
test('epithet after a comma', () => expect(flags('Stormcrow Giggleshadows, the Relentless', 'epithetStart')).toEqual([false, false, true, false]));
test('epithet at a lowercase the', () => expect(flags('Ravenge the Renegade', 'epithetStart')).toEqual([false, true, false]));
test('penalties', () => {
  const award = TS.tokenize('Twilight Peak Guildmaster of Anti-Paladins', 'award');
  expect(TS.breakPenalty(award, 3, 'award')).toBe(60);    // after "of"
  expect(TS.breakPenalty(award, 2, 'award')).toBe(-10);   // before "of"
  const n = TS.tokenize('Stormcrow Giggleshadows, the Relentless', 'name');
  expect(TS.breakPenalty(n, 1, 'name')).toBe(-28);        // comma −20 + epithet −8
  const h = TS.tokenize('Captain Sir Lord Kato', 'name');
  expect(TS.breakPenalty(h, 2, 'name')).toBe(-25);
  expect(TS.breakPenalty(h, 0, 'name')).toBe(30);
  expect(TS.breakPenalty(TS.tokenize('James III', 'name'), 0, 'name')).toBe(50);
  expect(TS.breakPenalty(TS.tokenize('Robin T. W. Carver', 'name'), 1, 'name')).toBe(40);
});
```

- [ ] **Step 2: Run, expect failure:** `npx playwright test --project=scroll-sweep-unit tools/scroll-sweep/tests/typeset-core.spec.ts` → module not found.
- [ ] **Step 3: Implement** `CONST`, `tokenize`, `breakPenalty`. Count characters with `Array.from()`, not `.length`.
- [ ] **Step 4: Run, expect pass** (same command).
- [ ] **Step 5: Commit** the two files (`Enhancement: Scrollery — typeset core: tokens and break penalties`).

---

### Task 5: Engine core — enumerate, solve, score

**Files:**
- Modify: `orkui/template/revised-frontend/script/scrollery-typeset.js` (pure functions only)
- Modify: `tools/scroll-sweep/tests/typeset-core.spec.ts`

**Interfaces:**
- Consumes: T4 `tokenize`, `breakPenalty`, `CONST`.
- Produces: `utility(s: number, range: {min, max, sat}): number` (spec §6.3.3).
- Produces: `solveBlock(opts): Solution`, where `opts = { text: string, role: 'name'|'award'|'place', measure: (word: string) => number /* em-at-1px */, spaceW: number, range: {min, max, sat}, maxLines: number, lineHeight: number, y0: number, rootPx: number, freeWidth: (yTop: number, yBottom: number) => number /* frame px */ }` and `Solution = { size: number, lines: string[], minor: number /* -1 or line index */, planned: number, score: number, overflow: boolean }`.
- Enumeration is exhaustive. Inputs are bounded (≤ 100 characters, ≤ 50 tokens, ≤ 1,225 three-line candidates), so there is no DP path.

- [ ] **Step 1: Write the failing tests.** Use a fixture measure of `w => Array.from(w).length * 0.5`, `spaceW = 0.25`, `rootPx = 100`, and `freeWidth = () => W` unless stated.

```ts
const solve = (text: string, role: string, W: number, over: any = {}) => TS.solveBlock({
  text, role, measure: (w: string) => Array.from(w).length * 0.5, spaceW: 0.25,
  range: TS.CONST.ROLES[role], maxLines: TS.CONST.ROLES[role].maxLines, lineHeight: TS.CONST.ROLES[role].lineHeight,
  y0: 900, rootPx: 100, freeWidth: () => W, ...over });

test('utility saturates', () => {
  const r = TS.CONST.ROLES.name;
  expect(TS.utility(1.6, r)).toBeCloseTo(100); expect(TS.utility(2.3, r)).toBeCloseTo(120); expect(TS.utility(0.8, r)).toBeCloseTo(50);
});
test('a short name stays on one line at its maximum', () => {
  const s = solve('Sir Hillary', 'name', 1683);
  expect(s.planned).toBe(1); expect(s.size).toBeCloseTo(2.3);
});
test('a long name takes two lines rather than one small one, never ending on "the"', () => {
  const s = solve('Nicodemus the Rat Landcrest', 'name', 1000);
  expect(s.planned).toBe(2); expect(s.lines[0].endsWith(' the')).toBe(false);
});
test('an award line never ends on "of"', () => {
  const s = solve('Twilight Peak Guildmaster of Anti-Paladins Winter 2014', 'award', 1100);
  expect(s.planned).toBeGreaterThanOrEqual(2); expect(s.lines.some(l => / of$/.test(l))).toBe(false);
});
test('a quoted nickname is never split', () => {
  const s = solve('William "Billy Bob" FitzRobert', 'name', 700);
  expect(s.lines.some(l => l.includes('"Billy') && !l.includes('Bob"'))).toBe(false);
});
test('an honorific may become a minor line', () => {
  const s = solve('Sir Lord Kato Darkhaven Grimwulff', 'name', 1100);
  expect(s.minor).toBe(0); expect(s.lines[0]).toBe('Sir Lord');
});
test('the line holding most of the characters is never minor', () => {
  const s = solve('Captain Sir Lord Kato Grimwulff', 'name', 1100);   // "Captain Sir Lord" outweighs "Kato Grimwulff"
  expect(s.minor).not.toBe(0);
});
test('minor lines can be switched off', () => {
  const saved = TS.CONST.MINOR_LINES; TS.CONST.MINOR_LINES = false;
  try { expect(solve('Sir Lord Kato Darkhaven Grimwulff', 'name', 1100).minor).toBe(-1); } finally { TS.CONST.MINOR_LINES = saved; }
});
test('a narrower band lowers the size', () => {
  const wide = solve('Lord Robin T. W. Carver, esquire', 'name', 1300);
  const narrow = solve('Lord Robin T. W. Carver, esquire', 'name', 1300, { freeWidth: (a: number, b: number) => (b > 1100 ? 900 : 1300) });
  expect(narrow.size).toBeLessThan(wide.size);
});
test('fallback splits an unbreakable token', () => {
  const s = solve('*Michael_Anthony_Wilder_III', 'name', 500);
  expect(s.overflow).toBe(false); expect(s.planned).toBeGreaterThan(1);
});
test('an impossible block still returns every word, flagged', () => {
  const s = solve('Wilhelmina Featherstonehaugh de la Mancha Wilhelmina Featherstonehaugh', 'name', 120);
  expect(s.overflow).toBe(true); expect(s.size).toBeCloseTo(TS.CONST.ROLES.name.min);
  expect(s.lines.join(' ').replace(/ /g, ' ')).toContain('Featherstonehaugh');
});
test('ties go to fewer lines', () => expect(solve('Halo', 'name', 1683).planned).toBe(1));
```

- [ ] **Step 2: Run, expect failure** (`solveBlock` undefined).
- [ ] **Step 3: Implement** `utility` and `solveBlock` per spec §6.3.3, §6.3.4 and §6.4.3: conservative bands, `s*`, the score, the minor variant per candidate, the fallback split chain (`_` `-` `/` `.` → camelCase → midpoint), then the flagged overflow at `min`. Fragments of one split word are joined with **no space** inside a line. When even the split pass is infeasible, the flagged result is the best **unsplit** candidate at `min`: splitting that does not make the block fit only mutilates the name. The widow penalty, like raggedness, ignores the minor line.
- [ ] **Step 4: Run, expect pass.**
- [ ] **Step 5: Commit** (`Enhancement: Scrollery — typeset core: breaks and sizes`).

---

### Task 6: Underlay policy and the review sheet (HUMAN GATE)

**Files:**
- Create: `system/lib/ork3/data/scroll-layout-underlays.php`, `tools/scroll-sweep/underlays.spec.ts`, `tests/Unit/ScrollLayoutUnderlaysTest.php`
- Modify: `playwright.config.ts` (add project `scroll-underlays`: `testDir: './tools/scroll-sweep'`, `testMatch: 'underlays.spec.ts'`, `timeout: 0`, `viewport: { width: 2600, height: 3500 }`, `deviceScaleFactor: 1`)

**Interfaces:**
- Consumes: T1 fixture helpers.
- Produces: `scroll-layout-underlays.php` returns `array<string, list<string>>`, keyed by every order id in `Scroll::Orders()` (including `custom`). Values are `fnmatch()` patterns relative to `assets/scrolls/`, naming the plates that type is designed to sit on (spec §7.3).

- [ ] **Step 1: Write the failing test** `ScrollLayoutUnderlaysTest`:
  - `testEveryOrderHasAnEntry`: keys ⊇ `array_keys(Scroll::Orders())`.
  - `testEveryPatternMatchesARealPlate`: each pattern matches ≥ 1 file under `assets/scrolls/` (`glob` with `fnmatch` semantics).
  - `testValuesAreListsOfStrings`.
- [ ] **Step 2: Run, expect failure:** `vendor/bin/phpunit tests/Unit/ScrollLayoutUnderlaysTest.php`.
- [ ] **Step 3: Draft the policy.** For each partial under `scroll/`, list the `<img>` layers it draws (from `scroll_art()`/`scroll_resource()`/`$art` uses). Classify each layer:
  - **Underlay:** full-page washes, scenes and textures composited soft-light or multiply, or at opacity < 1, that the type band overlaps by design. Known examples: `smith/bg.*`, `battle/arcane/wash.*`, `rose/burn.*`.
  - **Obstacle:** everything else (border corner pieces, figures, badges, numerals).

  When unsure, choose obstacle and say so in a comment beside the entry.
- [ ] **Step 4: Write `underlays.spec.ts`.** For every order (rank 5, or the order's middle rank, each variant), render one fixture. For each image layer of `.award-template` (excluding `.Award` and grounds per `markGround`), screenshot the ground plus that layer alone. Write `tools/scroll-sweep/out/underlays/index.html`: per order, a grid of layer thumbnails labelled with filename and UNDERLAY/OBSTACLE from the policy.
- [ ] **Step 5: Run:** `vendor/bin/phpunit tests/Unit/ScrollLayoutUnderlaysTest.php` → pass; `npx playwright test --project=scroll-underlays` → writes the sheet.
- [ ] **Step 6: HUMAN GATE.** Stop. Report the sheet's path to the coordinator. The coordinator shows it to the owner and records approval or the edits requested. Apply the edits and re-run Step 5.
- [ ] **Step 7: Commit** the four files (`Enhancement: Scrollery — which plates the type may sit on, as data`).

---

### Task 7: Free-space lookup, ink-grid helper, headings, cache key

**Files:**
- Modify: `system/lib/ork3/class.Scroll.php`
- Create: `tests/Unit/ScrollFreeSpaceTest.php`, `tests/Unit/ScrollHeadingTest.php`

**Interfaces:**
- Produces:
  - `Scroll::FreeSpace(array $s, ?array $manifest = null): ?array{y0: int, step: int, L: list<int>, R: list<int>}`. With `$manifest` null it reads `assets/scrolls/free-space.json`; a missing file means `null`. Fixed orders use the key `"{$s['order']}/{$s['variant']}/{$s['rank']}"`. Custom intersects `ground/<g>`, `border/<b>` and `rankset/<set>/<rank>` from `$s['recipe']` (L = max, R = min per band). Absent component entries are skipped; if all are absent, return `null`.
  - `Scroll::FreeSpaceAttr(array $s, ?array $manifest = null): string` returns `"<y0>,<step>|<L comma list>|<R comma list>"`, or `''`.
  - `function scroll_ink_grid(string $relative): string` (global, next to `scroll_resource`) returns `'32x32:' . implode('', rows)` from `ink-extents.json`, or `''`.
  - `Scroll::Headings(): array` → `['auto' => 'Automatic', 'classic' => 'Classic', 'centered' => 'Centered']`; `Scroll::NormaliseHeading(?string $v): string` → an allowlisted key, else `'auto'`.
  - `renderCacheKey()` gains `'layout' => self::layoutGeneration()` = `filemtime(free-space.json)|filemtime(ink-extents.json)`, with 0 for a missing file. Add `public static ?string $freeSpacePathOverride = null;` for tests.

- [ ] **Step 1: Write the failing tests.**

```php
// ScrollFreeSpaceTest
private const M = ['v' => 1, 'y0' => 400, 'step' => 25, 'configs' => ['smith//5' => ['L' => [450, 460], 'R' => [2100, 2090]]],
    'components' => ['ground/linen' => ['L' => [500, 433], 'R' => [2116, 2050]], 'border/knot' => ['L' => [440, 470], 'R' => [2000, 2116]]]];

public function testAFixedOrderIsLookedUpByOrderVariantRank(): void {
    $f = \Scroll::FreeSpace(['order' => 'smith', 'variant' => '', 'rank' => 5, 'recipe' => []], self::M);
    $this->assertSame([450, 460], $f['L']); $this->assertSame(400, $f['y0']);
}
public function testACustomScrollIntersectsItsComponents(): void {
    $f = \Scroll::FreeSpace(['order' => 'custom', 'variant' => '', 'rank' => 3, 'recipe' => ['ground' => 'linen', 'border' => 'knot', 'rankSet' => 'common']], self::M);
    $this->assertSame([500, 470], $f['L']); $this->assertSame([2000, 2050], $f['R']);
}
public function testAMissingConfigIsNullAndEmitsNothing(): void {
    $s = ['order' => 'rose', 'variant' => '', 'rank' => 1, 'recipe' => []];
    $this->assertNull(\Scroll::FreeSpace($s, self::M)); $this->assertSame('', \Scroll::FreeSpaceAttr($s, self::M));
}
public function testTheAttributeFormat(): void {
    $this->assertSame('400,25|450,460|2100,2090', \Scroll::FreeSpaceAttr(['order' => 'smith', 'variant' => '', 'rank' => 5, 'recipe' => []], self::M));
}
public function testTheInkGridHelper(): void {
    $this->assertMatchesRegularExpression('/^32x32:[0-9a-f]{256}$/', scroll_ink_grid('scrolls/common/text/squiggly-right.svg'));
    $this->assertSame('', scroll_ink_grid('scrolls/does/not/exist.svg'));
}
public function testTheRenderCacheKeyMovesWithTheLayoutData(): void {
    // copy free-space fixture to a temp file, point $freeSpacePathOverride at it, take key via ReflectionMethod,
    // touch() the temp file 2s later, reset the cached generation, take key again → assertNotSame
}
// ScrollHeadingTest
public function testHeadingsAreAllowlisted(): void {
    $this->assertSame('centered', \Scroll::NormaliseHeading('centered'));
    $this->assertSame('auto', \Scroll::NormaliseHeading('Centered; drop table'));
    $this->assertSame('auto', \Scroll::NormaliseHeading(null));
}
```

- [ ] **Step 2: Run, expect failure:** `vendor/bin/phpunit tests/Unit/ScrollFreeSpaceTest.php tests/Unit/ScrollHeadingTest.php`.
- [ ] **Step 3: Implement** the Interfaces. Cache the manifests in statics, as `artGeneration()` does.
- [ ] **Step 4: Run, expect pass,** plus `vendor/bin/phpunit tests/Unit/ScrollRenderCacheTest.php tests/Unit/ScrollPartialsTest.php` still green.
- [ ] **Step 5: Commit** the three files (`Enhancement: Scrollery — free-space lookup, ink grids and headings for the template`).

---

### Task 8: Markup and CSS contract

**Files:**
- Modify: `orkui/template/revised-frontend/scroll/_text.tpl`, `orkui/template/revised-frontend/style/scrollery.css`, `orkui/template/revised-frontend/Scrollery_render.tpl`, `orkui/template/revised-frontend/Scrollery_create.tpl` (script includes only), `tests/Unit/ScrollPartialsTest.php`
- Create: `tools/scroll-sweep/tests/markup.spec.ts`

**Interfaces:**
- Consumes: BuildScroll `issuerLead`/`issuerArticle`/`issuerPlace` (T2); `Scroll::FreeSpaceAttr`, `scroll_ink_grid`, `Scroll::NormaliseHeading` (T7).
- Produces: the markup in spec §8.1, exactly. Hooks for T10/T11: `data-sc-role` on the lead, place, name and award elements; `data-sc-free` on `.Award`; `data-sc-ink` on all five flourish `<img>`s; `data-sc-comp="classic"` on `.IssuingTextSection`; `div.ScSpacer`; `body[data-sc-heading]` on the render page.
- Produces (CSS): `.sc-line`, `.sc-line--minor`, `.sc-art`, `.ScRule`, the `[data-sc-comp="centered"]` rules, `--sc-place-gutter` / `--sc-place-reserve` with today's values as fallbacks, and the rhythm in spec §6.7.
- First guess (no JS): award `font-size` = `min(1.5, 0.85 × nameGuessEm)`, computed in PHP and printed as a literal (e.g. `1.1560em`). Place = `min(1.3, existing formula)`. `.sc-art` = the lead size relative to the place: `calc(1.04em / <placeGuess>)` printed as a literal.

- [ ] **Step 1: Write the failing PHP tests** (add to `ScrollPartialsTest`, using its existing render helper):

```php
public function testTheTypesetHooksArePresent(): void {
    foreach (['data-sc-role="lead"', 'data-sc-role="place"', 'data-sc-role="name"', 'data-sc-role="award"',
              'data-sc-comp="classic"', 'class="ScSpacer"', 'ScRule--l', 'ScRule--r'] as $needle) {
        $this->assertStringContainsString($needle, $html, $needle);
    }
    $this->assertSame(5, substr_count($html, 'data-sc-ink="32x32:'));
}
public function testAKingdomReadsLikeAPark(): void {   // kingdom-issued, 'The Kingdom of the Wetlands'
    $this->assertMatchesRegularExpression('#id="IssuerTitle"[^>]*>and the Kingdom of</span>#', $html);
    $this->assertStringContainsString('<span class="sc-art">the</span> Wetlands', $html);
}
public function testTheAwardFirstGuessNeverExceedsTheNamesShare(): void {   // 100-character persona → name guess 1.36
    $this->assertStringContainsString('font-size:1.1560em', $this->awardStyle($html));
}
```

  Keep the existing assertions on `data-scroll-text="rankPhrase"` and `class="Body AwardDetails"`.

- [ ] **Step 2: Write the failing DOM test** `markup.spec.ts` (fixtures c22 and c11):
  - Centered rules apply when forced: on c22, set `data-sc-comp="centered"` on the section. Expect `#RightTextFlourish` and `#BottomTextFlourish` computed `display` = `none`, `.ScRule` visible.
  - With no JS (`page.route` aborts every `*.js`), c22 renders and every `BLOCK_SEL` selector still matches.
  - Rhythm: on c10, the gap between `.IssuingVerb` bottom and `.AwardeeName` top (via `offsetTop`, frame px) is ≤ 0.6 × root px.
- [ ] **Step 3: Run, expect failure:** `vendor/bin/phpunit tests/Unit/ScrollPartialsTest.php`; `npx playwright test --project=scroll-sweep-unit tools/scroll-sweep/tests/markup.spec.ts`.
- [ ] **Step 4: Implement** the markup, CSS, first guess and script includes. In both pages the order is textmodel → typeset → autofit → existing. Stamp `data-sc-heading="<?= scroll_e(Scroll::NormaliseHeading($_GET['heading'] ?? null)) ?>"` on `<body>` in `Scrollery_render.tpl`. Keep the CSS the old passes read (`--sc-orn-travel-max`, `#BottomTextFlourish` `top`); T15 deletes it. Rewrite the comments on the rules you do change.
- [ ] **Step 5: Run, expect pass,** plus the full Scroll PHPUnit set: `vendor/bin/phpunit --filter 'Scroll'` → green.
- [ ] **Step 6: Commit** (`Enhancement: Scrollery — top-half markup for the typeset engine`).

---

### Task 9: Free-space profiles

**Files:**
- Create: `tools/scroll-sweep/profile.spec.ts`, `tools/scroll-sweep/fixtures/heraldry-standin.png` (420×420 solid `#333333`), `assets/scrolls/free-space.json` (generated), `tests/Unit/ScrollFreeSpaceCoverageTest.php`
- Modify: `playwright.config.ts` (project `scroll-profile`, same `use` block as `scroll-underlays`), `package.json` (`"profile:scrolls": "php tools/scroll-sweep/bin/dump-axes.php > tools/scroll-sweep/axes.json && playwright test --project=scroll-profile"`)

**Interfaces:**
- Consumes: T1 fixtures; T6 approved policy; T7 manifest shape; the existing `forceFullScale`, `markGround`, `LAYER_CSS`, `diffMask`.
- Produces: `assets/scrolls/free-space.json` per spec §7.1, with the measured thresholds recorded in its header.

- [ ] **Step 1: Write the failing coverage test:** for every `orderVariants` × rank in `tools/scroll-sweep/axes.json` (respecting minimum ranks from `Scroll::Orders()`; include `hunter`), `configs` has `"order/variant/rank"`. Every `grounds` entry has `ground/<g>`, every `borders` entry except `none` has `border/<b>`, and every `rankSets` × ranks pair has `rankset/<s>/<r>`. Every `L`/`R` has `bands` entries and `L[i] < R[i]`.
- [ ] **Step 2: Run, expect failure:** `vendor/bin/phpunit tests/Unit/ScrollFreeSpaceCoverageTest.php` (file missing).
- [ ] **Step 3: Implement `profile.spec.ts`** per spec §7.1 steps 1–6. Hide underlays with an injected `visibility: hidden` rule on matching `img[src*=…]`. `display: none` is forbidden, by the sweep's own rule. Write debug PNGs to `tools/scroll-sweep/out/profiles/` and a summary of centre-zone obstacles to `out/profiles/report.md`.
- [ ] **Step 4: Calibrate on three known cases** before the full run. Hunter rank 1 must constrain the bands at the archers' heights. Battle/arcane must not constrain the name band, since its wash is an underlay. Zodiac must constrain at the torn paper's edge. Record the thresholds in the JSON header.
- [ ] **Step 5: Generate:** `npm run profile:scrolls`. Then run `vendor/bin/phpunit tests/Unit/ScrollFreeSpaceCoverageTest.php tests/Unit/ScrollFreeSpaceTest.php` → pass.
- [ ] **Step 6: Commit** the five files (`Enhancement: Scrollery — measured free space beside the artwork`). The debug PNGs stay uncommitted.

---

### Task 10: Engine DOM layer — names, awards, verify, integration

**Files:**
- Modify: `orkui/template/revised-frontend/script/scrollery-typeset.js` (DOM layer), `orkui/template/revised-frontend/script/scrollery-autofit.js` (`fitAward` only)
- Create: `tools/scroll-sweep/tests/typeset-dom.spec.ts`

**Interfaces:**
- Consumes: T5 `solveBlock`; T8 hooks.
- Produces:
  - `layoutTop(award: HTMLElement, opts?: {}) -> Report`, with `Report = { comp, ms, budget: 'ok'|'over', roles: { name?, award?, place?, lead?: { size, lines, planned, overflow } }, ornaments: { right?: 'placed'|'scaled'|'hidden', bottom?: …, rule?: … } }`.
  - In this task `layoutTop` handles **name and award**. The issuer keeps its server first guess until T11.
  - DOM writes per spec §6.8. Each `.sc-line` carries `data-sc-w` (its allowed width, frame px).
  - Report attributes per spec §6.8.
- `fitAward` calls `layoutTop(award)` when `global.ScrolleryTypeset && typeof global.ScrolleryTypeset.layoutTop === 'function'`, skipping `fitLines(name)` and `fitLines(order)`. Otherwise it runs the old passes unchanged.

- [ ] **Step 1: Write the failing DOM tests** (fixtures from the review corpus):

```ts
const ids = ['c03', 'c05', 'c14', 'c16', 'c22', 'c27', 'c31', 'c46'];
test('planned equals printed, and every line fits', async ({ page }) => { /* per id: */
  for (const role of ['name', 'award']) {
    const el = page.locator(`[data-sc-role="${role}"]`);
    expect(await el.getAttribute('data-sc-lines')).toBe(await el.getAttribute('data-sc-planned'));
  }
  const bad = await page.evaluate(() => [...document.querySelectorAll<HTMLElement>('.sc-line')]
    .filter(l => l.scrollWidth > Number(l.dataset.scW) + 0.5).length);
  expect(bad).toBe(0);
});
test('c14 prints the award on one line', …expect data-sc-lines of award = "1");
test('c27 never splits "Billy Bob"', …no .sc-line text contains '"Billy' without 'Bob"');
test('c46 name takes at most two lines', …);
test('hierarchy: the award never outranks the name', …award size ≤ 0.85 × name size + 0.001, unless award size = award.min);
test('fitting twice changes nothing', …innerHTML of .Award identical after a second ScrolleryAutofit.fit(document));
test('a textContent write re-lays out from the new text', …set name span textContent to "Halo", fit, expect lines ["Halo"] and size = name.max);
test('empty profile renders blanks and reports no overflow', …render a spec with player/issuerName/title '' → no data-sc-overflow, the blank img present);
test('fallback glyphs are measured, not assumed', …spec player 'Nokren ᚨᛒᛟᛟᚹᚺᛟᚷᛟᛞ' in uncial → lines fit (data-sc-w check));
test('without the engine the old passes still run', …delete window.ScrolleryTypeset before fit → .AwardeeName has data-af-base);
test('multi-up lifts the floors', …render c05 with up: 2 → name size ≥ 1.20 (0.80 × 1.5) or data-sc-overflow set, never below);
test('performance', …p95 of data-sc-ms over the eight ids ≤ 20);
```

- [ ] **Step 2: Run, expect failure:** `npx playwright test --project=scroll-sweep-unit tools/scroll-sweep/tests/typeset-dom.spec.ts`.
- [ ] **Step 3: Implement the DOM layer.**
  - `readContext(award)`: column, `rootPx`, `FLOOR_SCALE` (copy autofit's reading of `[data-scroll-up]`), parsed `data-sc-free`, forced heading.
  - `measureWords(el, words)`: hidden `span.sc-measure` at 100px inheriting the role element's font, letter-spacing and style; cached per page.
  - `freeWidth(yTop, yBottom)`: from the profile; symmetric for centred roles; column bounds where there is no data.
  - Name solve at the name element's `offsetTop`, then award with the hierarchy cap.
  - `apply`, the `verify` loop, report attributes.
  - Wire into `fitAward`.
- [ ] **Step 4: Run, expect pass.** Also run `vendor/bin/phpunit --filter Scroll` and `npx playwright test --project=scroll-sweep-unit` → green.
- [ ] **Step 5: Commit** the three files (`Enhancement: Scrollery — the typeset engine sets the name and the award`).

---

### Task 11: Engine DOM layer — compositions, ornaments, hierarchy loop, budget

**Files:**
- Modify: `orkui/template/revised-frontend/script/scrollery-typeset.js`, `orkui/template/revised-frontend/script/scrollery-autofit.js` (skip `fitTitle`, `fitInline(issuer)` and `anchorOrnaments` when the engine runs; re-run `layoutTop` after `fitColumn` returns true)
- Modify: `tools/scroll-sweep/tests/typeset-dom.spec.ts`

**Interfaces:**
- Consumes: T10 `layoutTop`, `measureWords`, `freeWidth`; T3 grids via `data-sc-ink`; T9 profiles.
- Produces: the full pipeline from spec §4: issuer solve (Classic per §6.5.1, Centered per §6.5.2, choice per §6.5.3), the hierarchy iteration, ornament placement (§6.6), the budget (§6.7). Report `comp`, `ornaments.*`, `budget`.
- Ink grid decode: `"32x32:" + 256 hex` → 32 rows × 32 bits. Map a cell to frame px with the image's `offsetLeft/Top/Width/Height`, accumulated relative to `.Award`.

- [ ] **Step 1: Write the failing DOM tests:**
  - `composition choice`: c22, c26, x2 → `classic`; c11, c14, x3 → `centered`. A calibrated departure in T14 updates these expectations, with the reason in the commit.
  - `lead never wraps`: `[data-sc-role="lead"]` `data-sc-lines` = `1` on all 54 corpus cases.
  - `Classic: the right flourish clears the place text`: decode `#RightTextFlourish`'s grid. No inked cell intersects any place `.sc-line` ink box expanded by `ORN_GAP·rootPx`. Run on every corpus case where comp = classic and the flourish is not hidden.
  - `Centered hides the line-two flourishes`: as in T8, on the engine's own choice.
  - `place obeys the hierarchy`: place size ≤ min(0.75·name, 0.90·award) + 0.001, unless at `place.min`.
  - `connective and versal are bounded`: lead size ≤ max(0.70, place size) + 0.001. The versal span's size = clamp(name size, 1.20, 1.495) ± 0.001.
  - `the article is set small`: on c12 ("the Wetlands"), `.sc-art`'s computed font-size equals `#IssuerTitle`'s within 0.5px.
  - `flourishes are not checked against the versal`: on every Classic case, `#BottomTextFlourish` is placed and not hidden. Its designed contact with "Amtgard" is exempt (spec §6.6).
  - `kingdoms and parks read alike` (Q7): over the 54 cases, |median(kingdom place size) − median(park place size)| / median(park) ≤ 0.20.
  - `forced heading wins`: c22 with `heading: 'centered'` → `centered`.
  - `budget holds`: `data-sc-budget` = `ok` on all 54. On a warrior rank-8 fixture with the `real` text profile (signature block pinned at 800px), the top stack's height is ≤ `contentHeight − bottomNeed` or ≥ 0.40·contentHeight, and `budget` is `ok` or `floor`, never missing.
  - `pass 3 does not break top-half widths`: on c32 and c50, the `data-sc-w` check passes after the full fit.
- [ ] **Step 2: Run, expect failure.**
- [ ] **Step 3: Implement** to spec §6.5–§6.7 and wire the autofit changes above.
- [ ] **Step 4: Run, expect pass,** plus the T10 tests and `vendor/bin/phpunit --filter Scroll`.
- [ ] **Step 5: Commit** (`Enhancement: Scrollery — issuing compositions and ornaments that follow the type`).

---

### Task 12: Composer and the heading choice

**Files:**
- Modify: `orkui/template/revised-frontend/script/scrollery.js` (`applyText`, the preview and print parameters), `orkui/template/revised-frontend/Scrollery_create.tpl` (a Heading select next to Hand), `orkui/template/revised-frontend/Scrollery_render.tpl` (a Heading select in the toolbar beside Hand, which rewrites `data-sc-heading` in place and re-fits)
- Create: `tools/scroll-sweep/tests/composer.spec.ts`

**Interfaces:**
- Consumes: `ScrolleryText.issuerLines` (T2); `Scroll::Headings` (T7); `layoutTop` (T10/T11).
- `applyText()` writes `#IssuerTitle` = `lead`, and the place span = `(article ? '<span class="sc-art">the</span> ' : '') + esc(place)`, computed by `ScrolleryText.issuerLines(titleEl.value, nameEl.value, useThe, useOf, scopeType)`. The heading select stamps `data-sc-heading` on `#sc-stage` and adds `heading=` to the print URL. It reuses the Hand control's markup and classes, so it inherits the existing light and dark styling.

- [ ] **Step 1: Write the failing tests** in `composer.spec.ts`. Skip with a clear message if `ORK3_E2E_USERNAME` is unset; T14 runs it with credentials.
  - `typing a kingdom name splits it like print`: type "The Kingdom of the Wetlands" in the issuer name with the title cleared and both boxes unchecked → `#IssuerTitle` reads `and the Kingdom of`.
  - `preview matches print` (Q10): for 10 corpus cases, compare the composer stage's `data-sc-comp`, the `.sc-line` texts and `data-sc-size` with the print page for the same inputs. Sizes must agree within 0.5%.
  - `the heading choice reaches print`: choose Centered, open print → `body[data-sc-heading="centered"]` and `data-sc-comp="centered"`.
- [ ] **Step 2: Run, expect failure** (with credentials).
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run, expect pass.**
- [ ] **Step 5: Commit** (`Enhancement: Scrollery — the composer previews what prints, and the heading is a choice`).

---

### Task 13: Corpus gates and the random sample

**Files:**
- Modify: `tools/scroll-sweep/corpus.spec.ts`, `tools/scroll-sweep/README.md`

**Interfaces:**
- Consumes: T1 corpus capture and report; T10/T11 report attributes; the T1b before-report `baseline/corpus-before-2026-10-25.json`.
- Random sample: when `ORK3_CORPUS_SEED` is set, `corpus.spec.ts` draws `ORK3_CORPUS_COUNT` (default 50) extra cases with read-only queries via `docker exec ork3-php8-db mariadb -u ork -psecret ork -N -B -e …`. The sampling rules are the review's, with every `ORDER BY RAND(n)` seeded from the seed:
  - personas: half uniform, a quarter 24–40 characters, a quarter > 40;
  - issuers: kingdoms (`ork_kingdom.active='Active'`), and parks joined to `ork_parktitle` (half and half);
  - a quarter of kingdom cases in the split form;
  - custom names from `ork_awards.custom_name` on award ids 94/246;
  - real notes of 10–400 characters;
  - each hand an equal number of times.

  Write the drawn specs to `out/<tag>/specs.json`, so a failing seed can be replayed.
- Gates, one `test()` each, failing with the list of offending ids: Q1, Q2, Q3, Q4, Q7, Q8, Q9 (zero `floor`, zero `overflow`, and a pass-3 count ≤ the before-report's count for the frozen corpus), and Q11 (p95 `data-sc-ms`).

- [ ] **Step 1: Write the gate tests.**
- [ ] **Step 2: Run on the frozen corpus:** `npm run sweep:scrolls:corpus` → every gate passes.
- [ ] **Step 3: Run a fresh sample:** `ORK3_CORPUS_SEED=20261026 ORK3_CORPUS_TAG=corpus-20261026 npm run sweep:scrolls:corpus` → every gate passes. If one fails, fix the engine (not the gate) in this task, or report the case to the coordinator as needing a spec decision.
- [ ] **Step 4: Document** in the sweep README: the fixture driver (from T1b), the corpus runs, what each gate means, how to replay a seed, and the corrected units (weighted ink pixels, thresholds 300/500/95).
- [ ] **Step 5: Commit** both files (`Enhancement: Scrollery — a corpus that grades the layout`).

---

### Task 14: Calibrate and verify (coordinator-led)

**Files:**
- Modify: `scrollery-typeset.js` (constants only, unless a gate exposes a bug), `typeset-dom.spec.ts` expectations (only with stated reason)
- Create: `docs/scrollery-typeset-results.md`

- [ ] **Step 0:** Apply the owner's underlay decisions from T6 to `scroll-layout-underlays.php`, then run `npm run profile:scrolls` and commit the regenerated `free-space.json`.
- [ ] **Step 1:** Run `ORK3_CORPUS_SEED=20261026 ORK3_CORPUS_TAG=corpus-20261026 npm run sweep:scrolls:corpus` and the frozen corpus. Every gate passes. Record the numbers.
- [ ] **Step 2:** Run the ink-collision sweep through the fixture driver with the same stages as the baseline: `ORK3_SWEEP_DRIVER=fixture ORK3_SWEEP_STAGES=NAB npm run sweep:scrolls`, then stage C. Compare with `node tools/scroll-sweep/bin/regression.mjs` against `baseline/results-1to1-head-2026-10-25.json`.
  - Q5: `CBtop` ≤ 500 on every case.
  - Q6: `CAtop` ≤ 300 on every case not flagged overflow.
  - Q12: no case clean in the baseline is dirty now, on any pair.

  If a bottom-half case became dirty because the slack moved (spec §6.7), fix it here or stop and report it to the coordinator.
- [ ] **Step 3:** Run Q10 (`composer.spec.ts`) with credentials. If none are set, the coordinator asks the owner for them.
- [ ] **Step 4:** Q11 bulk timing: render one fixture page with 200 certificates (a `renderFixtures` spec list of the corpus repeated). Fitting finishes within autofit's 6 s budget, with no certificate left on the first guess.
- [ ] **Step 5:** Run every test suite green: `vendor/bin/phpunit --filter Scroll`, `npx playwright test --project=scroll-sweep-unit`.
- [ ] **Step 6: Visual review.** The coordinator reads `out/corpus/index.html` for both corpora, judging against spec §10.3. Tune constants where the layout is legal but poor, re-run Steps 1–2, then present the contact sheet to the owner. **HUMAN GATE.**
- [ ] **Step 7:** Write `docs/scrollery-typeset-results.md`: before/after numbers per defect D1–D8, gate results and the constants as calibrated. Commit with the constant changes (`Enhancement: Scrollery — typeset engine calibrated and measured`).

---

### Task 15: Remove the old passes, update the docs

**Files:**
- Modify: `scrollery-autofit.js` (delete `POLICY.name`, `POLICY.order`, `POLICY.title`, `POLICY.issuer`, `fitLines`, `fitTitle`, `lineRows`, `anchorOrnaments`, `ORN_BOTTOM_GAP_EM`, and the fallback branch from T10. Rewrite the header comment to describe what the file still does), `scrollery.css` (remove rules only those passes used), `_text.tpl` (drop the now-unused `data-af-*` comments for name and issuer), `docs/scrollery-asset-pipeline.md` (regenerate `free-space.json` after any plate change: `npm run profile:scrolls`; regenerate ink grids after any flourish change), `docs/scrollery-deferred.md` (pre-existing: `bin/*.php` is web-executable under the nginx config; the new scripts guard, the old ones do not), `tools/scroll-sweep/README.md` (new projects; replace the stale 1.0/2.0/0.3 ppt threshold table with the real 300/500/95 weighted-pixel values from `lib/report.ts`; mark `results-1to1-NA.json` as superseded by `results-1to1-head-2026-10-25.json`).

- [ ] **Step 1:** `grep -n "fitTitle\|anchorOrnaments\|lineRows\|POLICY.name\|POLICY.order\|data-af-inv" orkui/template/revised-frontend -r` → after deletion, no matches outside comments that explain history in one line.
- [ ] **Step 2:** Re-run `npm run sweep:scrolls:corpus`, `vendor/bin/phpunit --filter Scroll` and `npx playwright test --project=scroll-sweep-unit` → green, and the corpus report is identical to T14's.
- [ ] **Step 3: Commit** (`Enhancement: Scrollery — retire the top-half ladders the engine replaced`).
