# Scrollery Ink-Over-Ink — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Characterise the class of "ink printed on top of ink" defects in the ORK3 Scrollery, fix it at the binding rather than at each constant, and prove the fix over the whole enumerated space with a committed, re-runnable, pixel-level sweep harness.

**Architecture:** A Playwright harness drives the two real surfaces (`Scrollery/compose` for print, `ScrolleryAjax/preview` for the composer stage) through an enumerated matrix of orders, ranks, variants, recipes, hands and derived text extremes. For each case it takes six 1:1 rasters with CSS layer isolation that does not reflow, converts each to a contrast-weighted ink mask, erodes and intersects them, and scores the overlap. The fix then moves ornament positioning from fixed constants to measured anchors written by `scrollery-autofit.js`, backed by a new `assets/scrolls/ink-extents.json` that records real ink extents instead of bounding boxes.

**Tech Stack:** PHP 8 (plain-PHP `.tpl`), vanilla ES5-style browser JS, CSS custom properties, `@playwright/test` 1.62 + Chromium (already installed), `pngjs` 7 (new devDependency, pure JS, zero deps), ImageMagick + PHP GD for the offline ink-extent pass, PHPUnit for invariants.

**Spec:** `docs/superpowers/specs/2026-09-07-scrollery-ink-collisions-design.md`

## Global Constraints

- `.tpl` files are **plain PHP** (`<?php ?>` / `<?= ?>`), never Smarty.
- PHP is PSR-12 via `tools/php-cs-fixer`. **Normalise first:** run `awk '/^\t/{c++}END{print c+0}' <file>`; if non-zero, run php-cs-fixer on that file *before* editing it.
- Never `git stash`. Never stage `system/lib/ork3/class.Authorization.php`. Stage explicitly by path, never `git add -A`. Verify `git diff --cached` before every commit.
- `Scrollery_mine.tpl` and `Scrollery_bulk.tpl` must not load `scrollery.css`.
- Art URLs are served immutable. Any plate change must move its cache token **and** `Scroll::artGeneration()` (which reads `assets/scrolls/renditions.json`'s mtime).
- Valory Fox's artwork is licensed and frozen. Changing a plate is a last resort and must be called out explicitly — never a quiet redraw.
- Local app: Docker on `:19080`. Routes are `index.php?Route=Controller/action`. DB creds `ork`/`secret`, container `ork3db`.
- Every measured offset written by JS must be **clamped** against a CSS-declared ceiling, exactly as `liftSignature()` clamps against `--sc-sign-lift-want`.
- Layer isolation uses `visibility` / `color: transparent` only. **`display:none` is forbidden in isolation CSS** — it reflows, and the rasters would no longer be comparable.
- Measurement inside `scrollery-autofit.js` stays in layout pixels (`offsetWidth`/`scrollHeight`/…) except where the file's existing sanctioned exceptions apply; `getBoundingClientRect` values must be divided by `frameScale()` before being compared with anything declared in frame px.

**Derived text extremes** (measured from the local prod-backup DB, 2026-09-07):

| field | source | max |
| --- | --- | --- |
| award name | `ork_kingdomaward.name` | 35 |
| kingdom name | `ork_kingdom.name` | 37 |
| park name | `ork_park.name` | 36 |
| citation | `ork_awards.note` | 400 |
| persona | `ork_mundane.persona` | 100 |
| custom title | `Scroll::CUSTOM_NAME_MAX_LENGTH` | 64 |

---

## File Structure

**New — harness (`tools/scroll-sweep/`):**

| file | responsibility |
| --- | --- |
| `README.md` | what it is, how to run it, how to read the report, how to add an axis |
| `bin/dump-axes.php` | PHP CLI: dumps orders, variants, recipes, hands and sanitiser caps to `axes.json`. The single seam between PHP vocabulary and the TS harness. |
| `lib/axes.ts` | loads `axes.json`, exposes `Axes`, `loadAxes()` |
| `lib/cases.ts` | `SweepCase` type; `enumerate(axes, stage)` → `SweepCase[]`; `caseId(c)` |
| `lib/text.ts` | the three text profiles (`max`, `empty`, `caseZero`) built from the derived extremes |
| `lib/auth.ts` | login, mirroring `tools/fuzzy-validator/playwright/lib/auth.ts` |
| `lib/catalogue.ts` | scrapes `Scrollery/create` once for order → `{awardId, title}`; reports unreachable orders |
| `lib/drive.ts` | `openPrint(page, c)` / `openPreview(page, c)`; waits for fonts + autofit |
| `lib/isolate.ts` | the layer-isolation stylesheets and `applyLayer()` |
| `lib/raster.ts` | `captureLayers()` → six PNG buffers clipped to the type band |
| `lib/mask.ts` | pure: `inkMask`, `erode1`, `intersect`, `union`, `severity`, `largestRun` |
| `lib/report.ts` | `results.json`, `report.md`, `report.html` |
| `sweep.spec.ts` | the Playwright entry point |
| `tests/mask.spec.ts` | unit tests for `lib/mask.ts` against synthetic PNGs |
| `tests/cases.spec.ts` | unit tests for enumeration counts |

**New — art pipeline:**

| file | responsibility |
| --- | --- |
| `bin/build-scroll-ink-extents.php` | rasterises every ornament and plate, writes ink bbox + per-row extents |
| `assets/scrolls/ink-extents.json` | the manifest it writes (a sibling of `renditions.json`) |

**New — tests:**

| file | responsibility |
| --- | --- |
| `tests/Unit/ScrollSweepCoverageTest.php` | fails if a hand or a partial is added without being swept |
| `tests/Unit/ScrollInkExtentsTest.php` | every referenced ornament/plate has a manifest entry, manifest not older than art |

**Modified:**

| file | change |
| --- | --- |
| `package.json` | `pngjs` devDependency; `sweep:scrolls` + `sweep:scrolls:exhaustive` scripts |
| `playwright.config.ts` | a `scroll-sweep` project |
| `orkui/template/revised-frontend/scroll/_text.tpl` | flourishes move out of the `"Amtgard"` span onto `.IssuingTextSection`; ornament elements gain `data-orn` |
| `orkui/template/revised-frontend/style/scrollery.css` | ornament offsets become clamped custom properties; keep-out variables; retired constants |
| `orkui/template/revised-frontend/script/scrollery-autofit.js` | new `anchorOrnaments()` and `applyKeepOut()` passes; third issuer tier |
| `.gitignore` / `.git/info/exclude` | `tools/scroll-sweep/out/` ignored |

---

## Task 1: Axis dump and case enumeration

**Files:**
- Create: `tools/scroll-sweep/bin/dump-axes.php`
- Create: `tools/scroll-sweep/lib/axes.ts`
- Create: `tools/scroll-sweep/lib/text.ts`
- Create: `tools/scroll-sweep/lib/cases.ts`
- Create: `tools/scroll-sweep/tests/cases.spec.ts`
- Modify: `playwright.config.ts` (add the `scroll-sweep` project)

**Interfaces:**
- Produces:
  - `type Axes = { orderVariants: {order: string; variant: string}[]; ranks: number[]; hands: string[]; grounds: string[]; borders: string[]; groundsWithOwnBorder: string[]; rankSets: string[]; caps: {customName: number} }`
  - `loadAxes(path?: string): Axes`
  - `type TextProfile = 'max' | 'empty' | 'caseZero'`
  - `type SweepCase = { id: string; stage: 'A'|'B'|'C'|'D'; order: string; variant: string; rank: number; hand: string; text: TextProfile; surface: 'print'|'preview'; recipe?: {ground:string;border:string;rankSet:string}; up?: 0|2|4; trim?: boolean; lines?: boolean }`
  - `enumerate(axes: Axes, stage: 'A'|'B'|'C', tightest?: Record<string, number>): SweepCase[]`
  - `caseId(c: SweepCase): string`

- [ ] **Step 1: Write the failing enumeration test**

Create `tools/scroll-sweep/tests/cases.spec.ts`:

```ts
import { test, expect } from '@playwright/test';
import { loadAxes } from '../lib/axes';
import { enumerate } from '../lib/cases';

test('axes carry the whole published vocabulary', () => {
  const a = loadAxes();
  // 17 partials - 2 that carry variants + battle{arcane,martial} + owl{parchment,cyanotype,transparency}
  expect(a.orderVariants).toHaveLength(20);
  expect(a.ranks).toEqual([0,1,2,3,4,5,6,7,8,9,10]);
  expect(a.hands).toHaveLength(10);
  expect(a.grounds).toHaveLength(12);
  expect(a.borders).toHaveLength(11);
  expect(a.rankSets).toHaveLength(4);
  expect(a.groundsWithOwnBorder).toHaveLength(5);
});

test('stage A is order-variant x rank x text', () => {
  const cases = enumerate(loadAxes(), 'A');
  expect(cases).toHaveLength(20 * 11 * 3);          // 660
  expect(new Set(cases.map(c => c.id)).size).toBe(cases.length);
  expect(cases.every(c => c.hand === 'chancery')).toBe(true);
});

test('stage B is order-variant x hand x text at one rank each', () => {
  const tightest = Object.fromEntries(
    loadAxes().orderVariants.map(o => [o.order + '/' + o.variant, 7])
  );
  const cases = enumerate(loadAxes(), 'B', tightest);
  expect(cases).toHaveLength(20 * 10 * 3);          // 600
  expect(cases.every(c => c.rank === 7)).toBe(true);
});

test('stage C uses only legal ground/border pairs', () => {
  const a = loadAxes();
  const cases = enumerate(a, 'C');
  const pairs = cases.filter(c => c.recipe).map(c => c.recipe!.ground + '/' + c.recipe!.border);
  // 7 borderless grounds x 11 borders + 5 self-bordered grounds x 1 forced 'none'
  expect(new Set(pairs).size).toBe(82);
  for (const g of a.groundsWithOwnBorder) {
    expect(cases.filter(c => c.recipe?.ground === g).every(c => c.recipe!.border === 'none')).toBe(true);
  }
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
npx playwright test --project=scroll-sweep-unit tools/scroll-sweep/tests/cases.spec.ts
```

Expected: FAIL — the project does not exist yet and `../lib/axes` cannot be resolved.

- [ ] **Step 3: Add the Playwright projects**

In `playwright.config.ts`, inside `projects: [...]`, append:

```ts
    {
      name: 'scroll-sweep-unit',
      testDir: './tools/scroll-sweep/tests',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'scroll-sweep',
      testDir: './tools/scroll-sweep',
      testMatch: 'sweep.spec.ts',
      timeout: 0,
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 2550, height: 1400 },
        deviceScaleFactor: 1,
      },
    },
```

`deviceScaleFactor: 1` and a 2550-wide viewport are load-bearing: the certificate SVG is `width:100%` inside a `viewBox="0 0 2550 3300"`, so this puts one CSS pixel on one frame pixel and makes every measurement scale-free.

- [ ] **Step 4: Write the PHP axis dump**

Create `tools/scroll-sweep/bin/dump-axes.php`:

```php
<?php

declare(strict_types=1);

/**
 * Dump Scrollery's published vocabulary as JSON for the sweep harness.
 *
 * This is the ONE seam between the PHP vocabulary and the TypeScript harness. The
 * harness never restates a list of orders, hands or grounds: it reads this. That is
 * what makes ScrollSweepCoverageTest able to fail when a hand is added — there is
 * nowhere for the harness to disagree with the source.
 *
 * Reads the data files directly rather than booting the app: they are plain
 * `return array(...)` files with no side effects, and booting startup.php here would
 * drag in a database connection this script has no use for.
 *
 * Usage: php tools/scroll-sweep/bin/dump-axes.php > tools/scroll-sweep/axes.json
 */

$root = dirname(__DIR__, 3);

$orders  = require $root . '/system/lib/ork3/data/scroll-orders.php';
$recipes = require $root . '/system/lib/ork3/data/scroll-recipes.php';

$orderVariants = [];
foreach ($orders as $id => $def) {
    $variants = $def['variants'] ?? [];
    if ($variants === []) {
        $orderVariants[] = ['order' => (string) $id, 'variant' => ''];
        continue;
    }
    foreach ($variants as $v) {
        $orderVariants[] = ['order' => (string) $id, 'variant' => (string) $v];
    }
}

/*
 * CUSTOM_NAME_MAX_LENGTH is read out of the class source rather than by loading the
 * class, for the same reason as above: class.Scroll.php is only loadable inside a
 * booted app. A regex over a `public const int = N;` line is brittle in general and
 * safe here because ScrollSweepCoverageTest asserts the dumped value against the
 * real constant from inside PHPUnit, where the class IS loadable.
 */
$src = (string) file_get_contents($root . '/system/lib/ork3/class.Scroll.php');
preg_match('/public const CUSTOM_NAME_MAX_LENGTH\s*=\s*(\d+)/', $src, $m);

echo json_encode([
    'orderVariants'        => $orderVariants,
    'ranks'                => range(0, 10),
    'hands'                => array_keys($recipes['hands']),
    'grounds'              => array_keys($recipes['grounds']),
    'borders'              => array_keys($recipes['borders']),
    'groundsWithOwnBorder' => $recipes['groundsWithOwnBorder'],
    'rankSets'             => array_keys($recipes['rankSets']),
    'caps'                 => ['customName' => (int) ($m[1] ?? 64)],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
```

- [ ] **Step 5: Generate `axes.json` and eyeball it**

```bash
php tools/scroll-sweep/bin/dump-axes.php > tools/scroll-sweep/axes.json
jq '{ov: (.orderVariants|length), hands: (.hands|length), grounds: (.grounds|length), borders: (.borders|length)}' tools/scroll-sweep/axes.json
```

Expected: `{"ov":20,"hands":10,"grounds":12,"borders":11}`. If `ov` is not 20, stop — the variant vocabulary has changed and the plan's matrix arithmetic needs revisiting before going further.

- [ ] **Step 6: Write `lib/axes.ts`**

```ts
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

export type OrderVariant = { order: string; variant: string };

export type Axes = {
  orderVariants: OrderVariant[];
  ranks: number[];
  hands: string[];
  grounds: string[];
  borders: string[];
  groundsWithOwnBorder: string[];
  rankSets: string[];
  caps: { customName: number };
};

/**
 * The harness never restates Scrollery's vocabulary; it reads what
 * bin/dump-axes.php dumped out of the PHP data files. Regenerate with:
 *   php tools/scroll-sweep/bin/dump-axes.php > tools/scroll-sweep/axes.json
 */
export function loadAxes(path = join(__dirname, '..', 'axes.json')): Axes {
  return JSON.parse(readFileSync(path, 'utf8')) as Axes;
}
```

- [ ] **Step 7: Write `lib/text.ts`**

```ts
/**
 * The three text profiles.
 *
 * Every length here is DERIVED from the ORK's own data (see the plan's Global
 * Constraints table), not invented. Inventing them is how the crown collision hid on
 * "9th August 2019" and appeared on "27th September 2026".
 *
 * `empty` is an extreme in its own right and not merely the absence of one: the
 * garber collision was invisible with a blank signer and visible with a real name.
 */
export type TextProfile = 'max' | 'empty' | 'caseZero';

export type TextFields = {
  player: string;
  reason: string;
  givenBy: string;
  date: string;
  issuerName: string;
  issuerTitle: string;
  useThe: boolean;
  useOf: boolean;
  customName: string;
};

const rep = (n: number) => 'Wilhelmina Featherstonehaugh de la Mancha'.repeat(4).slice(0, n);

export const PROFILES: Record<TextProfile, TextFields> = {
  /* Every field at its derived maximum. 'W' and 'm'-heavy source text on purpose:
     the widest glyphs are what reach a frame first, and a max-LENGTH string of thin
     letters is not a max-WIDTH line. */
  max: {
    player: rep(100),
    reason: rep(400),
    givenBy: rep(60),
    date: '27th September 2026',
    issuerName: 'The Principality of the Crimson Sands',   // 36 chars, the real longest
    issuerTitle: 'Principality',
    useThe: true,
    useOf: true,
    customName: rep(64),
  },
  /* Nothing optional filled in. Exercises the writing-line blanks, the min-height
     reservations, and the branch where an empty reason computes its size from 200. */
  empty: {
    player: '',
    reason: '',
    givenBy: '',
    date: '',
    issuerName: '',
    issuerTitle: '',
    useThe: false,
    useOf: false,
    customName: '',
  },
  /* The reported reproduction, verbatim. */
  caseZero: {
    player: '',
    reason: '',
    givenBy: '',
    date: '',
    issuerName: 'Crystal Groves',
    issuerTitle: 'Kingdom',
    useThe: true,
    useOf: true,
    customName: '',
  },
};

export const PROFILE_NAMES: TextProfile[] = ['max', 'empty', 'caseZero'];
```

- [ ] **Step 8: Write `lib/cases.ts`**

```ts
import type { Axes } from './axes';
import { PROFILE_NAMES, type TextProfile } from './text';

export type Surface = 'print' | 'preview';
export type Stage = 'A' | 'B' | 'C' | 'D';

export type SweepCase = {
  id: string;
  stage: Stage;
  order: string;
  variant: string;
  rank: number;
  hand: string;
  text: TextProfile;
  surface: Surface;
  recipe?: { ground: string; border: string; rankSet: string };
  up?: 0 | 2 | 4;
  trim?: boolean;
  lines?: boolean;
};

export function caseId(c: SweepCase): string {
  const parts = [
    c.stage, c.order + (c.variant ? '-' + c.variant : ''), 'r' + c.rank, c.hand, c.text, c.surface,
  ];
  if (c.recipe) parts.push(c.recipe.ground + '+' + c.recipe.border + '+' + c.recipe.rankSet);
  if (c.up) parts.push('up' + c.up);
  if (c.trim) parts.push('trim');
  if (c.lines === false) parts.push('nolines');
  return parts.join('_');
}

const base = (o: { order: string; variant: string }, rank: number, hand: string,
              text: TextProfile, stage: Stage): SweepCase => {
  const c: SweepCase = {
    id: '', stage, order: o.order, variant: o.variant, rank, hand, text, surface: 'print',
  };
  c.id = caseId(c);
  return c;
};

/**
 * Legal ground/border pairs.
 *
 * NormaliseRecipe() forces border 'none' for anything in groundsWithOwnBorder — those
 * five grounds have a frame painted into the plate, and drawing a border over one
 * gives the certificate two. Enumerating the illegal pairs would sweep states the
 * app cannot reach and report collisions nobody can produce.
 */
export function legalRecipePairs(a: Axes): { ground: string; border: string }[] {
  const out: { ground: string; border: string }[] = [];
  for (const ground of a.grounds) {
    if (a.groundsWithOwnBorder.includes(ground)) {
      out.push({ ground, border: 'none' });
      continue;
    }
    for (const border of a.borders) out.push({ ground, border });
  }
  return out;
}

export function enumerate(a: Axes, stage: Stage, tightest?: Record<string, number>): SweepCase[] {
  const cases: SweepCase[] = [];

  if (stage === 'A') {
    /* Rank changes the PLATE — dragon's knotwork widens, garber's tape measure grows,
       griffon draws symbol_N twice. That is a proven interaction with the order, so
       the two are crossed rather than swept separately. Hand is held at the default. */
    for (const o of a.orderVariants) {
      for (const rank of a.ranks) {
        for (const text of PROFILE_NAMES) cases.push(base(o, rank, 'chancery', text, 'A'));
      }
    }
    return cases;
  }

  if (stage === 'B') {
    /* The frame's inner edge is per-order; the type's width is per-hand. The
       interaction IS "does this hand's longest line reach this frame", so order and
       hand are crossed — at the single rank stage A found tightest for that order,
       because rank moves the plate and not the type. */
    for (const o of a.orderVariants) {
      const key = o.order + '/' + o.variant;
      const rank = tightest?.[key] ?? 10;
      for (const hand of a.hands) {
        for (const text of PROFILE_NAMES) cases.push(base(o, rank, hand, text, 'B'));
      }
    }
    return cases;
  }

  if (stage === 'C') {
    /* Ground and border paint the FRAME band; the rank numeral paints the CENTRE.
       They do not overlap, so the cross is factorised on that separation — and the
       factorisation is validated by the --exhaustive tier rather than assumed. */
    const custom = { order: 'custom', variant: '' };
    for (const pair of legalRecipePairs(a)) {
      const c = base(custom, 10, 'chancery', 'max', 'C');
      c.recipe = { ...pair, rankSet: 'common' };
      c.id = caseId(c);
      cases.push(c);
    }
    for (const rankSet of a.rankSets) {
      for (const rank of a.ranks) {
        const c = base(custom, rank, 'chancery', 'max', 'C');
        c.recipe = { ground: 'parchment', border: 'knot', rankSet };
        c.id = caseId(c);
        cases.push(c);
      }
    }
    return cases;
  }

  return cases;
}

/** The exhaustive validation of stage C's factorisation: every pair x rankSet x rank. */
export function enumerateExhaustiveC(a: Axes): SweepCase[] {
  const cases: SweepCase[] = [];
  for (const pair of legalRecipePairs(a)) {
    for (const rankSet of a.rankSets) {
      for (const rank of a.ranks) {
        const c = base({ order: 'custom', variant: '' }, rank, 'chancery', 'max', 'C');
        c.recipe = { ...pair, rankSet };
        c.id = caseId(c);
        cases.push(c);
      }
    }
  }
  return cases;
}
```

- [ ] **Step 9: Run the tests and make them pass**

```bash
npx playwright test --project=scroll-sweep-unit tools/scroll-sweep/tests/cases.spec.ts
```

Expected: 4 passed. Stage C's own count is `82 + 44 = 126`; the test asserts the 82 distinct pairs, which is the property that matters.

- [ ] **Step 10: Commit**

```bash
git add tools/scroll-sweep/bin/dump-axes.php tools/scroll-sweep/axes.json \
        tools/scroll-sweep/lib/axes.ts tools/scroll-sweep/lib/text.ts \
        tools/scroll-sweep/lib/cases.ts tools/scroll-sweep/tests/cases.spec.ts \
        playwright.config.ts
git diff --cached --stat
git commit -m "Enhancement: Scrollery sweep — the axes, dumped from PHP rather than restated"
```

---

## Task 2: Driver — auth, catalogue, and one certificate on screen

**Files:**
- Create: `tools/scroll-sweep/lib/auth.ts`
- Create: `tools/scroll-sweep/lib/catalogue.ts`
- Create: `tools/scroll-sweep/lib/drive.ts`
- Create: `tools/scroll-sweep/tests/drive.spec.ts`

**Interfaces:**
- Consumes: `SweepCase`, `PROFILES` from Task 1.
- Produces:
  - `login(page: Page): Promise<void>`
  - `type Choice = { awardId: number; title: string }`
  - `loadCatalogue(page: Page, kingdomId: number): Promise<{ byOrder: Record<string, Choice>; unreachable: string[] }>`
  - `printUrl(c: SweepCase, ch: Choice, kingdomId: number): string`
  - `openPrint(page: Page, c: SweepCase, ch: Choice, kingdomId: number): Promise<void>`
  - `openPreview(page: Page, c: SweepCase, ch: Choice, kingdomId: number): Promise<void>`
  - `settle(page: Page): Promise<void>`

- [ ] **Step 1: Write the failing driver test**

Create `tools/scroll-sweep/tests/drive.spec.ts`:

```ts
import { test, expect } from '@playwright/test';
import { login } from '../lib/auth';
import { loadCatalogue } from '../lib/catalogue';
import { openPrint } from '../lib/drive';
import { loadAxes } from '../lib/axes';
import { enumerate } from '../lib/cases';

const KINGDOM = Number(process.env.ORK3_SWEEP_KINGDOM ?? 1);

test('every order partial is either reachable or declared unreachable', async ({ page }) => {
  await login(page);
  const { byOrder, unreachable } = await loadCatalogue(page, KINGDOM);
  const orders = new Set(loadAxes().orderVariants.map(o => o.order));
  for (const order of orders) {
    expect(order in byOrder || unreachable.includes(order),
      `${order} is neither reachable nor declared unreachable`).toBe(true);
  }
});

test('a certificate renders and the autofit pass completes', async ({ page }) => {
  await login(page);
  const { byOrder } = await loadCatalogue(page, KINGDOM);
  const c = enumerate(loadAxes(), 'A').find(x => x.order === 'battle' && x.text === 'caseZero')!;
  await openPrint(page, c, byOrder['battle'], KINGDOM);
  await expect(page.locator('.Award').first()).toBeVisible();
  // The fitted sizes are written in em on the elements the policy names.
  const fitted = await page.locator('.AwardeeName').first().getAttribute('data-af-base');
  expect(fitted).not.toBeNull();
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
npx playwright test --project=scroll-sweep-unit tools/scroll-sweep/tests/drive.spec.ts
```

Expected: FAIL — `../lib/auth` unresolved.

- [ ] **Step 3: Write `lib/auth.ts`**

```ts
import type { Page } from '@playwright/test';

/** Mirrors tools/fuzzy-validator/playwright/lib/auth.ts — one login flow for the repo. */
export async function login(page: Page): Promise<void> {
  const user = process.env.ORK3_E2E_USERNAME;
  const pass = process.env.ORK3_E2E_PASSWORD;
  if (!user || !pass) {
    throw new Error('ORK3_E2E_USERNAME and ORK3_E2E_PASSWORD are required for the scroll sweep');
  }
  await page.goto('./index.php?Route=Login');
  await page.fill('input[name="username"]', user);
  await page.fill('input[name="password"]', pass);
  await page.click('button[type="submit"], input[type="submit"]');
  await page.waitForLoadState('networkidle');
}
```

- [ ] **Step 4: Write `lib/catalogue.ts`**

```ts
import type { Page } from '@playwright/test';
import { loadAxes } from './axes';

export type Choice = { awardId: number; title: string };

/**
 * Scrollery/compose matches on the PAIR (awardId, catalogue name), case-insensitively
 * — see Model_Scrollery::matchingChoiceForTest(). So the harness cannot invent an
 * award id: it has to read the kingdom's own printable choices. It scrapes the
 * composer's dropdown once and reuses the map for the whole run.
 *
 * UNREACHABLE ORDERS ARE A REAL RESULT, NOT AN ERROR. `hunter` points at award_id
 * 6969, which scroll-orders.php says in as many words is "Arcanyst's invention, not
 * an ORK award id" — no ork_kingdomaward row can resolve to it, so hunter.tpl is not
 * reachable through any route as an ORDER (its border.png is still live as a custom
 * recipe border, and stage C sweeps that). Reporting it as unreachable is the honest
 * answer; ScrollSweepCoverageTest is what stops the list from quietly growing.
 */
export async function loadCatalogue(
  page: Page,
  kingdomId: number,
): Promise<{ byOrder: Record<string, Choice>; unreachable: string[] }> {
  await page.goto(`./index.php?Route=Scrollery/create/kingdom/${kingdomId}`);
  await page.waitForLoadState('domcontentloaded');

  const options = await page.$$eval('select[name="awardId"] option, #sc-award option', els =>
    els.map(e => ({
      value: (e as HTMLOptionElement).value,
      label: (e as HTMLOptionElement).textContent?.trim() ?? '',
      order: (e as HTMLOptionElement).dataset.order ?? '',
    })),
  );

  const byOrder: Record<string, Choice> = {};
  for (const o of options) {
    const awardId = Number(o.value);
    if (!Number.isFinite(awardId) || awardId <= 0 || !o.order) continue;
    if (!(o.order in byOrder)) byOrder[o.order] = { awardId, title: o.label };
  }
  // custom is the 0 sentinel and is reached by naming it, not by award id.
  if (!('custom' in byOrder)) {
    const custom = options.find(o => /custom award/i.test(o.label));
    if (custom) byOrder['custom'] = { awardId: Number(custom.value), title: custom.label };
  }

  const all = new Set(loadAxes().orderVariants.map(o => o.order));
  const unreachable = [...all].filter(o => !(o in byOrder));
  return { byOrder, unreachable };
}
```

> **Execution note for the implementer:** the dropdown's exact `name`/`id` and whether
> it carries `data-order` must be read off `Scrollery_create.tpl` before writing this.
> If it does not expose the order, add `data-order` to the option in that template —
> it is inert markup and the composer already needs to know the order to preview.
> Do not guess the selector; open the page and look.

- [ ] **Step 5: Write `lib/drive.ts`**

```ts
import type { Page } from '@playwright/test';
import type { SweepCase } from './cases';
import type { Choice } from './catalogue';
import { PROFILES } from './text';

const RECIPE_DEFAULT = { primary: 'hsl(35, 29%, 55%)', secondary: 'hsl(34, 72%, 78%)' };

export function printUrl(c: SweepCase, ch: Choice, kingdomId: number): string {
  const t = PROFILES[c.text];
  const q = new URLSearchParams({
    Route: 'Scrollery/compose',
    scopeType: 'kingdom',
    scopeId: String(kingdomId),
    awardId: String(ch.awardId),
    title: ch.title,
    rank: String(c.rank),
    variant: c.variant,
    face: c.hand,
    player: t.player,
    reason: t.reason,
    givenBy: t.givenBy,
    date: t.date,
    issuerName: t.issuerName,
    issuerTitle: t.issuerTitle,
    customName: t.customName,
  });
  if (t.useThe) q.set('useThe', '1');
  if (t.useOf) q.set('useOf', '1');
  if (c.recipe) {
    q.set('ground', c.recipe.ground);
    q.set('border', c.recipe.border);
    q.set('rankSet', c.recipe.rankSet);
    q.set('primary', RECIPE_DEFAULT.primary);
    q.set('secondary', RECIPE_DEFAULT.secondary);
  }
  if (c.up) q.set('up', String(c.up));
  if (c.trim) q.set('trim', '1');
  if (c.lines === false) q.set('lines', '0');
  return './index.php?' + q.toString();
}

/**
 * Wait until what is on screen is what would print.
 *
 * Three things, in order, and all three are load-bearing:
 *   fonts    — every hand is font-display:block, so before the face lands the type is
 *              NOT PAINTED AT ALL and a raster taken here measures an empty page.
 *   images   — the plates are what layer A is; decode, not just load.
 *   autofit  — scrollery-autofit.js sizes the type on document.fonts.ready. A raster
 *              taken before it finishes measures the server's first-guess sizes,
 *              which are not what prints.
 */
export async function settle(page: Page): Promise<void> {
  await page.evaluate(() => document.fonts.ready);
  await page.evaluate(async () => {
    await Promise.all(
      Array.from(document.images)
        .filter(i => !i.complete)
        .map(i => i.decode().catch(() => undefined)),
    );
  });
  await page.evaluate(() => {
    const A = (window as any).ScrolleryAutofit;
    if (A && typeof A.fit === 'function') A.fit(document);
  });
  await page.waitForTimeout(120);
}

export async function openPrint(page: Page, c: SweepCase, ch: Choice, kingdomId: number) {
  await page.goto(printUrl(c, ch, kingdomId));
  await settle(page);
}

/**
 * The composer stage. A different surface in two ways that matter: it renders through
 * ScrolleryAjax/preview at ART_DISPLAY (a third the linear plate size) and it lays out
 * in a ~600px box rather than a full page. Both change which pixels land where, which
 * is exactly why the spec sweeps it separately rather than assuming print covers it.
 */
export async function openPreview(page: Page, c: SweepCase, ch: Choice, kingdomId: number) {
  await page.goto(`./index.php?Route=Scrollery/create/kingdom/${kingdomId}`);
  await page.waitForLoadState('domcontentloaded');
  const t = PROFILES[c.text];
  await page.evaluate(async (args) => {
    const body = new URLSearchParams(args.fields as Record<string, string>);
    const res = await fetch('index.php?Route=ScrolleryAjax/preview', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });
    const json = await res.json();
    const stage = document.getElementById('sc-stage');
    const fo = stage?.querySelector('foreignObject');
    if (fo && json.Html) fo.innerHTML = json.Html;
    if (stage) stage.setAttribute('data-scroll-face', args.hand);
  }, {
    hand: c.hand,
    fields: {
      scopeType: 'kingdom', scopeId: String(kingdomId),
      awardId: String(ch.awardId), title: ch.title, rank: String(c.rank),
      variant: c.variant, player: t.player, reason: t.reason, givenBy: t.givenBy,
      date: t.date, issuerName: t.issuerName, issuerTitle: t.issuerTitle,
      customName: t.customName, useThe: t.useThe ? '1' : '', useOf: t.useOf ? '1' : '',
      ...(c.recipe ?? {}),
    },
  });
  await settle(page);
}
```

> **Execution note:** `ScrolleryAjax/preview`'s response shape must be read off
> `controller.ScrolleryAjax.php` (it returns `Html` at line ~153 via
> `Scroll::Render($scroll, Scroll::ART_DISPLAY)`), and the stage's DOM must be read off
> `Scrollery_create.tpl` around line 638. Confirm both before writing; do not guess.

- [ ] **Step 6: Run the driver test**

```bash
ORK3_E2E_USERNAME=heraldsbridge ORK3_E2E_PASSWORD=x \
  npx playwright test --project=scroll-sweep-unit tools/scroll-sweep/tests/drive.spec.ts
```

Expected: 2 passed, with `hunter` reported in `unreachable`.

- [ ] **Step 7: Commit**

```bash
git add tools/scroll-sweep/lib/auth.ts tools/scroll-sweep/lib/catalogue.ts \
        tools/scroll-sweep/lib/drive.ts tools/scroll-sweep/tests/drive.spec.ts
git diff --cached
git commit -m "Enhancement: Scrollery sweep — drive both real surfaces, and settle before measuring"
```

---

## Task 3: Layer isolation and rasterising

**Files:**
- Create: `tools/scroll-sweep/lib/isolate.ts`
- Create: `tools/scroll-sweep/lib/raster.ts`
- Create: `tools/scroll-sweep/tests/isolate.spec.ts`

**Interfaces:**
- Consumes: `settle` from Task 2.
- Produces:
  - `type Layer = 'full' | 'A' | 'B' | 'C' | 'Codd' | 'Ceven'`
  - `LAYER_CSS: Record<Layer, string>`
  - `applyLayer(page: Page, layer: Layer): Promise<void>`
  - `typeBand(page: Page): Promise<{x:number;y:number;width:number;height:number}>`
  - `captureLayers(page: Page): Promise<Record<Layer, Buffer>>`

- [ ] **Step 1: Write the failing isolation test**

Create `tools/scroll-sweep/tests/isolate.spec.ts`:

```ts
import { test, expect } from '@playwright/test';
import { login } from '../lib/auth';
import { loadCatalogue } from '../lib/catalogue';
import { openPrint } from '../lib/drive';
import { loadAxes } from '../lib/axes';
import { enumerate } from '../lib/cases';
import { applyLayer, captureLayers } from '../lib/isolate';

const KINGDOM = Number(process.env.ORK3_SWEEP_KINGDOM ?? 1);

test('isolating a layer does not move the type', async ({ page }) => {
  await login(page);
  const { byOrder } = await loadCatalogue(page, KINGDOM);
  const c = enumerate(loadAxes(), 'A').find(x => x.order === 'crown' && x.text === 'max')!;
  await openPrint(page, c, byOrder['crown'], KINGDOM);

  const boxOf = () => page.locator('.AwardeeName').first().evaluate(
    el => { const r = el.getBoundingClientRect(); return [r.x, r.y, r.width, r.height]; });

  const before = await boxOf();
  for (const layer of ['A', 'B', 'C', 'Codd', 'Ceven'] as const) {
    await applyLayer(page, layer);
    expect(await boxOf(), `layer ${layer} reflowed the page`).toEqual(before);
  }
});

test('the six rasters are all produced and differ', async ({ page }) => {
  await login(page);
  const { byOrder } = await loadCatalogue(page, KINGDOM);
  const c = enumerate(loadAxes(), 'A').find(x => x.order === 'crown' && x.text === 'max')!;
  await openPrint(page, c, byOrder['crown'], KINGDOM);
  const shots = await captureLayers(page);
  expect(Object.keys(shots).sort()).toEqual(['A','B','C','Ceven','Codd','full']);
  expect(shots.C.equals(shots.A)).toBe(false);
});
```

- [ ] **Step 2: Run it and watch it fail**

```bash
npx playwright test --project=scroll-sweep-unit tools/scroll-sweep/tests/isolate.spec.ts
```

Expected: FAIL — `../lib/isolate` unresolved.

- [ ] **Step 3: Write `lib/isolate.ts`**

```ts
import type { Page } from '@playwright/test';

export type Layer = 'full' | 'A' | 'B' | 'C' | 'Codd' | 'Ceven';

const STYLE_ID = 'sweep-isolate';

/*
 * ISOLATION MUST NOT REFLOW.
 *
 * `display:none` is forbidden in every rule below. Removing an element from flow
 * moves the type, and two rasters of two different layouts cannot be intersected —
 * the intersection would report collisions that exist in neither render. Everything
 * here is `visibility`, `color: transparent`, or `opacity`, all of which paint
 * differently and lay out identically.
 *
 * A white base under every layer, so `ink = (1 - L) * alpha` composited on white is
 * the same arithmetic for all of them.
 */
const WHITE = `.scroll-page, .sc-stage, body { background: #fff !important; }`;

/* Everything that is NOT the certificate's own text column. */
const HIDE_PLATES = `.award-template > *:not(.Award) { visibility: hidden !important; }
  .award-template { background: #fff !important; }`;

/* The ornament images inside .Award. .Underline is ornament too: it is the ruled
   line the citation is written on, and type landing on it is a real defect. */
const HIDE_ORNAMENT = `.Award img { visibility: hidden !important; }`;

/* Glyph ink off without moving a single line box. */
const HIDE_GLYPHS = `.Award, .Award * { color: transparent !important;
  -webkit-text-fill-color: transparent !important; text-shadow: none !important; }`;

export const LAYER_CSS: Record<Layer, string> = {
  full: WHITE,
  /* A — plate artwork only. .Award goes invisible whole; its box stays. */
  A: `${WHITE} .Award { visibility: hidden !important; }`,
  /* B — ornament only. */
  B: `${WHITE} ${HIDE_PLATES} ${HIDE_GLYPHS}`,
  /* C — glyph ink only. */
  C: `${WHITE} ${HIDE_PLATES} ${HIDE_ORNAMENT}`,
  /* C by parity. Adjacent-block text-on-text is exactly odd n even, which is how two
     rasters cover all ten blocks' pairwise adjacency. `[data-sweep-parity]` is
     stamped by applyLayer() below, not by the template — the harness must not need a
     production change to measure. */
  Codd: `${WHITE} ${HIDE_PLATES} ${HIDE_ORNAMENT}
    [data-sweep-parity="0"], [data-sweep-parity="0"] * {
      color: transparent !important; -webkit-text-fill-color: transparent !important; }`,
  Ceven: `${WHITE} ${HIDE_PLATES} ${HIDE_ORNAMENT}
    [data-sweep-parity="1"], [data-sweep-parity="1"] * {
      color: transparent !important; -webkit-text-fill-color: transparent !important; }`,
};

/** The certificate's text blocks, in document order — the parity partition's domain. */
const BLOCK_SEL = [
  '.IssuingTextSection > p:first-child',
  '.IssuingTextSection > p:nth-child(2)',
  '.IssuingVerb',
  '.AwardeeName',
  '.AwardDetails',
  '.Order',
  '.Award_Reason',
  '.Flavour',
  '.AwardDate',
  '.AwardSignatory',
].join(',');

export async function applyLayer(page: Page, layer: Layer): Promise<void> {
  await page.evaluate(({ css, id, sel }) => {
    document.querySelectorAll(sel).forEach((el, i) => {
      el.setAttribute('data-sweep-parity', String(i % 2));
    });
    let s = document.getElementById(id) as HTMLStyleElement | null;
    if (!s) {
      s = document.createElement('style');
      s.id = id;
      document.head.appendChild(s);
    }
    s.textContent = css;
  }, { css: LAYER_CSS[layer], id: STYLE_ID, sel: BLOCK_SEL });
}
```

- [ ] **Step 4: Write `lib/raster.ts`**

```ts
import type { Page } from '@playwright/test';
import { applyLayer, type Layer } from './isolate';

export const LAYERS: Layer[] = ['full', 'A', 'B', 'C', 'Codd', 'Ceven'];

/**
 * The band worth rasterising: the union of the type's painted extent, padded.
 *
 * Not the whole 2550x3300 page. Every question this harness asks is "does something
 * else land where the TYPE lands", so pixels no glyph could reach cost decode time
 * and answer nothing. The padding is generous (120 frame px) so an ornament that
 * overhangs a block is still inside the band.
 *
 * PAINTED extent, so a clipped element cannot inflate it: the citation's six ruled
 * underlines are laid out 145px apart whatever the citation says and the surplus ones
 * are cut off by overflow:hidden — measuring their rects said the citation reached
 * 2707px on every certificate. Same clamp as paintedBottom() in scrollery-autofit.js.
 */
export async function typeBand(page: Page) {
  return page.evaluate(() => {
    const award = document.querySelector('.Award') as HTMLElement;
    const frame = document.querySelector('svg[data-scroll-frame]') as SVGElement;
    const k = frame.getBoundingClientRect().width / 2550;
    const origin = frame.getBoundingClientRect();
    let top = Infinity, bottom = -Infinity, left = Infinity, right = -Infinity;

    const clampToClips = (el: Element, r: DOMRect) => {
      let out = { top: r.top, bottom: r.bottom, left: r.left, right: r.right };
      let p = el.parentElement;
      while (p && p !== award.parentElement) {
        if (getComputedStyle(p).overflow !== 'visible') {
          const pr = p.getBoundingClientRect();
          out.top = Math.max(out.top, pr.top);
          out.bottom = Math.min(out.bottom, pr.bottom);
          out.left = Math.max(out.left, pr.left);
          out.right = Math.min(out.right, pr.right);
        }
        p = p.parentElement;
      }
      return out;
    };

    award.querySelectorAll('*').forEach(el => {
      if (el.children.length) return;
      if (el.tagName !== 'IMG' && !(el.textContent || '').trim()) return;
      const r = el.getBoundingClientRect();
      if (r.width <= 0 || r.height <= 0) return;
      const c = clampToClips(el, r);
      if (c.bottom <= c.top || c.right <= c.left) return;   // clipped away entirely
      top = Math.min(top, c.top); bottom = Math.max(bottom, c.bottom);
      left = Math.min(left, c.left); right = Math.max(right, c.right);
    });

    const pad = 120 * k;
    const x = Math.max(origin.left, left - pad);
    const y = Math.max(origin.top, top - pad);
    return {
      x: Math.round(x),
      y: Math.round(y),
      width: Math.round(Math.min(origin.right, right + pad) - x),
      height: Math.round(Math.min(origin.bottom, bottom + pad) - y),
    };
  });
}

export async function captureLayers(page: Page): Promise<Record<Layer, Buffer>> {
  const clip = await typeBand(page);
  const out = {} as Record<Layer, Buffer>;
  for (const layer of LAYERS) {
    await applyLayer(page, layer);
    out[layer] = await page.screenshot({ clip, type: 'png', animations: 'disabled' });
  }
  await applyLayer(page, 'full');
  return out;
}
```

- [ ] **Step 5: Run the isolation tests**

```bash
ORK3_E2E_USERNAME=heraldsbridge ORK3_E2E_PASSWORD=x \
  npx playwright test --project=scroll-sweep-unit tools/scroll-sweep/tests/isolate.spec.ts
```

Expected: 2 passed. **If the reflow test fails, stop and fix the isolation CSS** — every number the harness produces afterwards depends on it.

- [ ] **Step 6: Commit**

```bash
git add tools/scroll-sweep/lib/isolate.ts tools/scroll-sweep/lib/raster.ts \
        tools/scroll-sweep/tests/isolate.spec.ts
git diff --cached
git commit -m "Enhancement: Scrollery sweep — layer isolation that paints differently and lays out identically"
```

---

## Task 4: Masks, intersection and severity

**Files:**
- Modify: `package.json` (add `pngjs` devDependency)
- Create: `tools/scroll-sweep/lib/mask.ts`
- Create: `tools/scroll-sweep/tests/mask.spec.ts`

**Interfaces:**
- Produces:
  - `type Mask = { w: number; h: number; bits: Uint8Array; ink: Float32Array }`
  - `inkMask(png: Buffer, floor?: number): Mask`
  - `erode1(m: Mask): Mask`
  - `intersect(a: Mask, b: Mask): Mask`
  - `count(m: Mask): number`
  - `largestRun(m: Mask): number`
  - `severity(glyph: Mask, interferer: Mask): { score: number; overlapPx: number; worstRun: number }`

- [ ] **Step 1: Add the decoder**

```bash
npm install --save-dev pngjs@7
```

`pngjs` is pure JavaScript with no dependencies and no native build. Chromium's
screenshots are 8-bit RGBA PNGs, which is exactly what it decodes.

- [ ] **Step 2: Write the failing mask tests**

Create `tools/scroll-sweep/tests/mask.spec.ts`:

```ts
import { test, expect } from '@playwright/test';
import { PNG } from 'pngjs';
import { inkMask, erode1, intersect, count, largestRun, severity } from '../lib/mask';

/** A w x h PNG, white, with `paint` allowed to darken pixels. */
function synth(w: number, h: number, paint: (x: number, y: number) => [number, number, number] | null): Buffer {
  const png = new PNG({ width: w, height: h });
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      const i = (y * w + x) << 2;
      const c = paint(x, y) ?? [255, 255, 255];
      png.data[i] = c[0]; png.data[i + 1] = c[1]; png.data[i + 2] = c[2]; png.data[i + 3] = 255;
    }
  }
  return PNG.sync.write(png);
}

test('white is not ink', () => {
  const m = inkMask(synth(10, 10, () => null));
  expect(count(m)).toBe(0);
});

test('a black block is ink at full strength', () => {
  const m = inkMask(synth(10, 10, (x, y) => (x < 5 && y < 5 ? [0, 0, 0] : null)));
  expect(count(m)).toBe(25);
  expect(m.ink[0]).toBeCloseTo(1, 3);
});

test('a pale wash is ink at low strength', () => {
  const m = inkMask(synth(4, 4, () => [220, 220, 220]));
  expect(m.ink[0]).toBeLessThan(0.2);
});

test('erode1 removes a one-pixel fringe', () => {
  // A 3x3 block erodes to a single pixel; a 1px line erodes to nothing.
  const block = inkMask(synth(5, 5, (x, y) => (x >= 1 && x <= 3 && y >= 1 && y <= 3 ? [0, 0, 0] : null)));
  expect(count(erode1(block))).toBe(1);
  const line = inkMask(synth(5, 5, (x, y) => (y === 2 ? [0, 0, 0] : null)));
  expect(count(erode1(line))).toBe(0);
});

test('largestRun distinguishes a stroke from pepper at equal area', () => {
  const stroke = inkMask(synth(20, 20, (x, y) => (y === 10 && x < 10 ? [0, 0, 0] : null)));
  const pepper = inkMask(synth(20, 20, (x, y) => ((x * 7 + y * 3) % 40 === 0 && x < 20 ? [0, 0, 0] : null)));
  expect(count(stroke)).toBeGreaterThan(0);
  expect(largestRun(stroke)).toBeGreaterThan(largestRun(pepper));
});

test('severity weights by the interferer\'s ink, not just its area', () => {
  const glyph = inkMask(synth(20, 20, (x, y) => (y >= 8 && y <= 12 ? [0, 0, 0] : null)));
  const dark  = inkMask(synth(20, 20, (x, y) => (y >= 8 && y <= 12 ? [0, 0, 0] : null)));
  const pale  = inkMask(synth(20, 20, (x, y) => (y >= 8 && y <= 12 ? [225, 225, 225] : null)));
  expect(severity(glyph, dark).score).toBeGreaterThan(severity(glyph, pale).score * 4);
});

test('two antialiasing fringes touching is not a collision', () => {
  // Two 1px lines, adjacent. Both erode to nothing, so the intersection is empty.
  const a = erode1(inkMask(synth(20, 20, (x, y) => (y === 10 ? [40, 40, 40] : null))));
  const b = erode1(inkMask(synth(20, 20, (x, y) => (y === 11 ? [40, 40, 40] : null))));
  expect(count(intersect(a, b))).toBe(0);
});
```

- [ ] **Step 3: Run and watch it fail**

```bash
npx playwright test --project=scroll-sweep-unit tools/scroll-sweep/tests/mask.spec.ts
```

Expected: FAIL — `../lib/mask` unresolved.

- [ ] **Step 4: Write `lib/mask.ts`**

```ts
import { PNG } from 'pngjs';

export type Mask = { w: number; h: number; bits: Uint8Array; ink: Float32Array };

/** Below this, a pixel is paper, not ink. Above it, `ink` records HOW MUCH. */
export const INK_FLOOR = 0.06;

/**
 * ink = (1 - L) * alpha, composited on white.
 *
 * A boolean would make a pale wash and black knotwork the same defect. They are not:
 * a certificate is unreadable under one and merely decorated under the other, so the
 * strength is carried through to the score rather than thresholded away here.
 *
 * Rec.709 luminance, because the question is perceptual — how much this darkens the
 * page a reader is looking at — not colorimetric.
 */
export function inkMask(png: Buffer, floor = INK_FLOOR): Mask {
  const img = PNG.sync.read(png);
  const n = img.width * img.height;
  const bits = new Uint8Array(n);
  const ink = new Float32Array(n);
  for (let p = 0, i = 0; p < n; p++, i += 4) {
    const a = img.data[i + 3] / 255;
    const L = (0.2126 * img.data[i] + 0.7152 * img.data[i + 1] + 0.0722 * img.data[i + 2]) / 255;
    const v = (1 - L) * a;
    if (v > floor) { bits[p] = 1; ink[p] = v; }
  }
  return { w: img.width, h: img.height, bits, ink };
}

/**
 * Erode by one pixel.
 *
 * This is what keeps the number honest. Every rasterised edge carries an
 * antialiasing fringe, so a glyph's fringe touching an ornament's fringe intersects
 * even when no reader would call it a collision — and at 2550px there are a great
 * many edges. Eroding both masks first means the intersection reports overlapping
 * SOLID ink and nothing else.
 */
export function erode1(m: Mask): Mask {
  const { w, h, bits, ink } = m;
  const out = new Uint8Array(w * h);
  const oink = new Float32Array(w * h);
  for (let y = 1; y < h - 1; y++) {
    for (let x = 1; x < w - 1; x++) {
      const p = y * w + x;
      if (!bits[p]) continue;
      if (bits[p - 1] && bits[p + 1] && bits[p - w] && bits[p + w]) { out[p] = 1; oink[p] = ink[p]; }
    }
  }
  return { w, h, bits: out, ink: oink };
}

export function intersect(a: Mask, b: Mask): Mask {
  if (a.w !== b.w || a.h !== b.h) throw new Error('mask size mismatch');
  const bits = new Uint8Array(a.w * a.h);
  const ink = new Float32Array(a.w * a.h);
  for (let p = 0; p < bits.length; p++) {
    if (a.bits[p] && b.bits[p]) { bits[p] = 1; ink[p] = b.ink[p]; }   // carry the INTERFERER's strength
  }
  return { w: a.w, h: a.h, bits, ink };
}

export function count(m: Mask): number {
  let n = 0;
  for (let p = 0; p < m.bits.length; p++) n += m.bits[p];
  return n;
}

/**
 * The largest 4-connected component.
 *
 * A swash drawn through a line and the same area of scattered pepper score alike on
 * area alone and are not alike to look at. This is what separates them, and it is
 * reported next to the score rather than folded into it so a reader can see which
 * kind of defect they have.
 */
export function largestRun(m: Mask): number {
  const seen = new Uint8Array(m.bits.length);
  const stack: number[] = [];
  let best = 0;
  for (let start = 0; start < m.bits.length; start++) {
    if (!m.bits[start] || seen[start]) continue;
    let size = 0;
    stack.push(start); seen[start] = 1;
    while (stack.length) {
      const p = stack.pop()!;
      size++;
      const x = p % m.w, y = (p / m.w) | 0;
      if (x > 0 && m.bits[p - 1] && !seen[p - 1]) { seen[p - 1] = 1; stack.push(p - 1); }
      if (x < m.w - 1 && m.bits[p + 1] && !seen[p + 1]) { seen[p + 1] = 1; stack.push(p + 1); }
      if (y > 0 && m.bits[p - m.w] && !seen[p - m.w]) { seen[p - m.w] = 1; stack.push(p - m.w); }
      if (y < m.h - 1 && m.bits[p + m.w] && !seen[p + m.w]) { seen[p + m.w] = 1; stack.push(p + m.w); }
    }
    if (size > best) best = size;
  }
  return best;
}

/**
 * Parts per thousand of the glyph area that another layer's ink lands on, weighted by
 * how dark that ink is. Zero when there is no type at all (an empty certificate
 * cannot have text-over-art), which is a real case and not a division by zero.
 */
export function severity(glyph: Mask, interferer: Mask) {
  const g = erode1(glyph);
  const i = erode1(interferer);
  const hit = intersect(g, i);
  const glyphPx = count(g);
  if (glyphPx === 0) return { score: 0, overlapPx: 0, worstRun: 0 };
  let sum = 0;
  for (let p = 0; p < hit.bits.length; p++) if (hit.bits[p]) sum += hit.ink[p];
  return {
    score: (sum / glyphPx) * 1000,
    overlapPx: count(hit),
    worstRun: largestRun(hit),
  };
}
```

- [ ] **Step 5: Run the tests and make them pass**

```bash
npx playwright test --project=scroll-sweep-unit tools/scroll-sweep/tests/mask.spec.ts
```

Expected: 7 passed.

- [ ] **Step 6: Commit**

```bash
git add package.json package-lock.json tools/scroll-sweep/lib/mask.ts \
        tools/scroll-sweep/tests/mask.spec.ts
git diff --cached --stat
git commit -m "Enhancement: Scrollery sweep — contrast-weighted ink masks, eroded so a fringe is not a finding"
```

---

## Task 5: The sweep entry point, reporting, and threshold calibration

**Files:**
- Create: `tools/scroll-sweep/lib/report.ts`
- Create: `tools/scroll-sweep/sweep.spec.ts`
- Create: `tools/scroll-sweep/README.md`
- Modify: `package.json` (scripts)
- Modify: `.git/info/exclude` (ignore `tools/scroll-sweep/out/`)

**Interfaces:**
- Consumes: everything from Tasks 1-4.
- Produces:
  - `type Result = { id: string; stage: string; surface: string; order: string; variant: string; rank: number; hand: string; text: string; CA: Score; CB: Score; CC: Score; parityLeak: number }` where `Score = { score: number; overlapPx: number; worstRun: number }`
  - `writeReport(results: Result[], outDir: string, threshold: number): void`

- [ ] **Step 1: Write `lib/report.ts`**

```ts
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

export type Score = { score: number; overlapPx: number; worstRun: number };
export type Result = {
  id: string; stage: string; surface: string;
  order: string; variant: string; rank: number; hand: string; text: string;
  CA: Score; CB: Score; CC: Score;
  parityLeak: number;   // |C| - |Codd u Ceven|: non-zero means a same-parity overlap
};

export const worst = (r: Result) => Math.max(r.CA.score, r.CB.score, r.CC.score);

export function writeReport(results: Result[], outDir: string, threshold: number): void {
  mkdirSync(outDir, { recursive: true });
  writeFileSync(join(outDir, 'results.json'),
    JSON.stringify({ threshold, generated: new Date().toISOString(), results }, null, 2));

  const dirty = results.filter(r => worst(r) > threshold).sort((a, b) => worst(b) - worst(a));

  const rows = dirty.map(r =>
    `| ${r.id} | ${r.CA.score.toFixed(2)} | ${r.CB.score.toFixed(2)} | ${r.CC.score.toFixed(2)} `
    + `| ${Math.max(r.CA.worstRun, r.CB.worstRun, r.CC.worstRun)} |`).join('\n');

  /* The distribution matters as much as the pass list: some C n A overlap is BY
     DESIGN (type sits on owl's blueprint plate), so a reader needs to see what is
     near the line rather than only what crossed it. */
  const buckets = [0, 0.25, 0.5, 1, 2, 5, 10, 25, Infinity];
  const hist = buckets.slice(0, -1).map((lo, i) => {
    const hi = buckets[i + 1];
    return `| ${lo} - ${hi === Infinity ? '∞' : hi} | ${results.filter(r => worst(r) >= lo && worst(r) < hi).length} |`;
  }).join('\n');

  writeFileSync(join(outDir, 'report.md'), `# Scrollery ink-collision sweep

Cases: **${results.length}**  ·  threshold: **${threshold}** ppt  ·  over threshold: **${dirty.length}**

## Severity distribution (worst layer pair per case)

| ppt of glyph area | cases |
| --- | --- |
${hist}

## Over threshold

| case | C∩A | C∩B | C∩C | worst run (px) |
| --- | --- | --- | --- | --- |
${rows || '| _none_ | | | | |'}

## Parity leaks

${results.filter(r => r.parityLeak > 0).map(r => `- ${r.id}: ${r.parityLeak}px`).join('\n') || '_none — every text-on-text overlap was between adjacent blocks._'}
`);
}
```

- [ ] **Step 2: Write `sweep.spec.ts`**

```ts
import { test } from '@playwright/test';
import { join } from 'node:path';
import { mkdirSync, writeFileSync } from 'node:fs';
import { login } from './lib/auth';
import { loadCatalogue } from './lib/catalogue';
import { openPrint, openPreview } from './lib/drive';
import { loadAxes } from './lib/axes';
import { enumerate, enumerateExhaustiveC, type SweepCase } from './lib/cases';
import { captureLayers } from './lib/raster';
import { inkMask, severity, count, erode1 } from './lib/mask';
import { writeReport, type Result } from './lib/report';

const KINGDOM = Number(process.env.ORK3_SWEEP_KINGDOM ?? 1);
const OUT = process.env.ORK3_SWEEP_OUT ?? join(__dirname, 'out');
const THRESHOLD = Number(process.env.ORK3_SWEEP_THRESHOLD ?? 0.5);
const EXHAUSTIVE = process.env.ORK3_SWEEP_EXHAUSTIVE === '1';
const ONLY = process.env.ORK3_SWEEP_ONLY;   // substring filter on case id, for iteration

test('sweep', async ({ page }) => {
  test.setTimeout(0);
  const axes = loadAxes();
  await login(page);
  const { byOrder, unreachable } = await loadCatalogue(page, KINGDOM);

  let cases: SweepCase[] = [
    ...enumerate(axes, 'A'),
    ...enumerate(axes, 'B'),
    ...(EXHAUSTIVE ? enumerateExhaustiveC(axes) : enumerate(axes, 'C')),
  ].filter(c => c.order in byOrder);
  if (ONLY) cases = cases.filter(c => c.id.includes(ONLY));

  mkdirSync(OUT, { recursive: true });
  writeFileSync(join(OUT, 'unreachable.json'), JSON.stringify({ unreachable }, null, 2));

  const results: Result[] = [];
  for (const c of cases) {
    const ch = byOrder[c.order];
    if (c.surface === 'preview') await openPreview(page, c, ch, KINGDOM);
    else await openPrint(page, c, ch, KINGDOM);

    const shots = await captureLayers(page);
    const A = inkMask(shots.A), B = inkMask(shots.B), C = inkMask(shots.C);
    const odd = inkMask(shots.Codd), even = inkMask(shots.Ceven);

    /* The parity guard. Codd u Ceven should reconstruct C exactly; a shortfall means
       two blocks of the SAME parity overlap, which the odd n even test cannot see. */
    const ce = erode1(C);
    let leak = 0;
    for (let p = 0; p < ce.bits.length; p++) if (ce.bits[p] && !odd.bits[p] && !even.bits[p]) leak++;

    const r: Result = {
      id: c.id, stage: c.stage, surface: c.surface, order: c.order, variant: c.variant,
      rank: c.rank, hand: c.hand, text: c.text,
      CA: severity(C, A), CB: severity(C, B), CC: severity(odd, even),
      parityLeak: leak,
    };
    results.push(r);

    /* Keep the full render only for cases that need looking at. Every case would be
       ~4 GB of PNG; the ones over threshold are what a person has to see. */
    if (Math.max(r.CA.score, r.CB.score, r.CC.score) > THRESHOLD) {
      writeFileSync(join(OUT, `${c.id}.png`), shots.full);
    }
  }

  writeReport(results, OUT, THRESHOLD);
});
```

- [ ] **Step 3: Add the npm scripts**

In `package.json` `"scripts"`, add:

```json
    "sweep:scrolls": "php tools/scroll-sweep/bin/dump-axes.php > tools/scroll-sweep/axes.json && playwright test --project=scroll-sweep",
    "sweep:scrolls:exhaustive": "ORK3_SWEEP_EXHAUSTIVE=1 npm run sweep:scrolls",
    "sweep:scrolls:unit": "playwright test --project=scroll-sweep-unit"
```

- [ ] **Step 4: Keep the output out of git**

```bash
echo 'tools/scroll-sweep/out/' >> .git/info/exclude
```

- [ ] **Step 5: Calibrate the threshold**

Run a small, targeted sweep over cases whose state is already known — the four
families the sign-and-date fix addressed (crown all ranks, dragon r8-r10, garber
r9-r10, griffon r0/r5-r8) are known-dirty *with the lift disabled*, and known-clean
with it on. Case zero is known-dirty.

```bash
# known-dirty: case zero
ORK3_SWEEP_ONLY=battle-arcane_r4 ORK3_SWEEP_THRESHOLD=0 npm run sweep:scrolls
# known-clean: the same order at a short issuer name
ORK3_SWEEP_ONLY=crown_r0_chancery_empty ORK3_SWEEP_THRESHOLD=0 npm run sweep:scrolls
```

Read `out/results.json`, record the two populations' scores in the README, and pin
`ORK3_SWEEP_THRESHOLD`'s default in `sweep.spec.ts` at a value that separates them
with margin. **Write the measured separation into the README** — a threshold with no
recorded justification is a magic number, and this file exists to stop those.

- [ ] **Step 6: Write the README**

Create `tools/scroll-sweep/README.md` covering, in this order: what defect class it
measures and why boxes cannot; how to run it (`npm run sweep:scrolls`, the env vars,
the exhaustive tier); how to read `report.md` (score = weighted ppt of glyph area,
worstRun = largest connected component, why both); the calibration numbers and the
threshold they justify; how to add an axis (regenerate `axes.json`; the enumeration
lives in `lib/cases.ts`); and the standing rule that **`display:none` is forbidden in
`lib/isolate.ts`**.

- [ ] **Step 7: Commit**

```bash
git add tools/scroll-sweep/lib/report.ts tools/scroll-sweep/sweep.spec.ts \
        tools/scroll-sweep/README.md package.json
git diff --cached
git commit -m "Enhancement: Scrollery sweep — the runner, the report, and a threshold with its working shown"
```

---

## Task 6: Baseline sweep and the written characterisation

**Files:**
- Create: `docs/scrollery-ink-collision-class.md`
- Create: `tools/scroll-sweep/baseline/results.json` (committed, small — the JSON only, not the PNGs)

- [ ] **Step 1: Run the full routine sweep against unmodified code**

```bash
ORK3_E2E_USERNAME=heraldsbridge ORK3_E2E_PASSWORD=x npm run sweep:scrolls
```

Record the wall-clock time. Expected ~1,400 cases (1,386 reachable of 1,386 + 126
stage C, minus hunter's 33) and under 30 minutes.

- [ ] **Step 2: Confirm the harness finds case zero**

```bash
jq '.results[] | select(.id | test("battle-arcane_r4.*caseZero")) | {id, CB}' tools/scroll-sweep/out/results.json
```

Expected: `CB.score` well above threshold. **If case zero measures clean, the harness
is wrong and everything downstream is worthless — stop and fix the harness.** This is
the single most important checkpoint in the plan.

- [ ] **Step 3: Freeze the baseline**

```bash
mkdir -p tools/scroll-sweep/baseline
cp tools/scroll-sweep/out/results.json tools/scroll-sweep/baseline/results.json
```

- [ ] **Step 4: Look at the top ten dirty cases**

Open the PNGs the sweep wrote for the ten highest scores. For each, write down in one
line *which* geometry produced it (C∩A flow-reaches-plate, C∩B ornament-pinned-to-a-
moved-line, or C∩C). This is the evidence the characterisation is built from — a
family named from a score and never looked at is a guess.

- [ ] **Step 5: Write `docs/scrollery-ink-collision-class.md`**

Sections: the three coordinate systems and why the three sub-families are exhaustive
over the pairs (from spec §1.1); the measured family census from step 4; **the
measured verdict on each of the four prior fixes** — for each, whether the sweep shows
it was correcting a constant or a binding, with the case ids that show it; and how to
use the harness when the class recurs.

The spec's §1.2 table is a *hypothesis*. This document records what was measured.
Where the measurement disagrees with the hypothesis, the measurement wins and the
disagreement is stated.

- [ ] **Step 6: Commit**

```bash
git add docs/scrollery-ink-collision-class.md tools/scroll-sweep/baseline/results.json
git diff --cached --stat
git commit -m "Docs: Scrollery — the ink-collision class as measured, and the baseline it was measured against"
```

---

## Task 7: Ink extents — the manifest that replaces bounding boxes

**Files:**
- Create: `bin/build-scroll-ink-extents.php`
- Create: `assets/scrolls/ink-extents.json`
- Create: `tests/Unit/ScrollInkExtentsTest.php`

**Interfaces:**
- Produces: `assets/scrolls/ink-extents.json`, shaped:

```json
{
  "_comment": "Generated by bin/build-scroll-ink-extents.php. Do not edit by hand.",
  "raster": 512,
  "files": {
    "common/text/squiggly-bottom.svg": {
      "box": [424, 161],
      "ink": { "x0": 0.012, "y0": 0.041, "x1": 0.890, "y1": 0.963 },
      "rows": [[0.10, 0.55], null, [0.02, 0.89]]
    }
  }
}
```

`ink` is the ink bounding box as a fraction of the element's own box; `rows` is the
per-row `[left, right]` extent (`null` where a row has no ink), sampled at `raster`
rows. Fractions rather than pixels, because the same ornament is drawn at a different
`em` size on every certificate.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ScrollInkExtentsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The ink manifest is what lets the layout reason about what an ornament actually
 * PAINTS rather than about the box it occupies. A missing entry is not a cosmetic
 * gap: the keep-out pass falls back to the box, which is the exact error that made a
 * bounding-box test report the squiggles as clear while they crossed the type.
 */
final class ScrollInkExtentsTest extends TestCase
{
    private const MANIFEST = __DIR__ . '/../../assets/scrolls/ink-extents.json';

    /** @return array<string, array{box: array, ink: array, rows: array}> */
    private function manifest(): array
    {
        $this->assertFileExists(self::MANIFEST, 'Run bin/build-scroll-ink-extents.php');

        return json_decode((string) file_get_contents(self::MANIFEST), true)['files'];
    }

    public function testEveryDefaultOrnamentHasAnEntry(): void
    {
        $files = $this->manifest();
        foreach (\Scroll::DEFAULT_RESOURCES as $key => $relative) {
            $rel = preg_replace('#^scrolls/#', '', $relative);
            $this->assertArrayHasKey($rel, $files, "$key ($rel) has no ink extent");
        }
    }

    public function testInkBoxIsAFractionOfTheElementBox(): void
    {
        foreach ($this->manifest() as $rel => $entry) {
            foreach (['x0', 'y0', 'x1', 'y1'] as $k) {
                $this->assertGreaterThanOrEqual(0.0, $entry['ink'][$k], "$rel.$k");
                $this->assertLessThanOrEqual(1.0, $entry['ink'][$k], "$rel.$k");
            }
            $this->assertLessThan($entry['ink']['x1'], $entry['ink']['x0'], $rel);
            $this->assertLessThan($entry['ink']['y1'], $entry['ink']['y0'], $rel);
        }
    }

    /**
     * A stale manifest is worse than none: it asserts a number for art that has since
     * been redrawn. renditions.json's mtime is already Scroll::artGeneration()'s stamp
     * for "the art moved"; this manifest must not be older than it.
     */
    public function testManifestIsNotOlderThanTheArt(): void
    {
        $renditions = __DIR__ . '/../../assets/scrolls/renditions.json';
        $this->assertGreaterThanOrEqual(
            filemtime($renditions),
            filemtime(self::MANIFEST),
            'ink-extents.json is older than renditions.json — re-run bin/build-scroll-ink-extents.php'
        );
    }
}
```

- [ ] **Step 2: Run it and watch it fail**

```bash
./bin/run-unit-tests.sh --filter ScrollInkExtentsTest
```

Expected: FAIL — `assets/scrolls/ink-extents.json` does not exist.

- [ ] **Step 3: Write `bin/build-scroll-ink-extents.php`**

Structure (write the full file; this is the specification of it):

- Header comment explaining **why fractions and not pixels**, and why this replaces
  the hand-measured numbers currently living in `scrollery.css` comments.
- `RASTER = 512` — the height every asset is rasterised to. Enough to resolve a
  hairline swash at the sizes these are drawn; small enough that 250 assets scan in
  seconds.
- Walk `assets/scrolls/`, taking `.svg`, `.png` and `.jpg`, skipping `*.webp` and
  `*_display.webp` (renditions, not masters).
- Rasterise via ImageMagick: `magick -background none <src> -resize x512 png32:<tmp>`.
  `-background none` is required — without it an SVG rasterises onto white and every
  pixel reads as opaque.
- Scan with GD: `imagecreatefrompng`, then per pixel
  `$a = 127 - (($rgb >> 24) & 0x7F)` for alpha and Rec.709 luminance for darkness;
  `ink = (1 - L) * (a / 127)`, above `INK_FLOOR = 0.06` — **the same floor and the
  same formula as `tools/scroll-sweep/lib/mask.ts`**, and the comment must say so, or
  the two will drift.
- Emit `box` (the master's real pixel dimensions), `ink` (fractional bbox) and `rows`.
- Write with `JSON_PRETTY_PRINT`, and `touch` the file last so its mtime is current.

- [ ] **Step 4: Generate and inspect**

```bash
php bin/build-scroll-ink-extents.php
jq '.files["common/text/squiggly-bottom.svg"].ink' assets/scrolls/ink-extents.json
```

Expected `x1` ≈ `0.890`. **This is a cross-check against a number measured by hand
during a previous fix and written into `scrollery.css`'s comment for
`p:nth-child(2)`.** If the script disagrees, one of the two is wrong and it must be
resolved before anything is built on it.

- [ ] **Step 5: Run the test**

```bash
./bin/run-unit-tests.sh --filter ScrollInkExtentsTest
```

Expected: 3 passed.

- [ ] **Step 6: Commit**

```bash
git add bin/build-scroll-ink-extents.php assets/scrolls/ink-extents.json \
        tests/Unit/ScrollInkExtentsTest.php
git diff --cached --stat
git commit -m "Enhancement: Scrollery — an ink manifest, so the layout can stop reasoning about boxes"
```

---

## Task 8: Fix 4.1 — anchor the ornaments to their block's measured box

**Files:**
- Modify: `orkui/template/revised-frontend/scroll/_text.tpl`
- Modify: `orkui/template/revised-frontend/style/scrollery.css`
- Modify: `orkui/template/revised-frontend/script/scrollery-autofit.js`

**Interfaces:**
- Produces: `ScrolleryAutofit.anchorOrnaments(award)` — called from `fitAward()` after
  the text passes and before `liftSignature()`.

- [ ] **Step 1: Normalise before editing PHP**

```bash
awk '/^\t/{c++}END{print c+0}' orkui/template/revised-frontend/scroll/_text.tpl
```

If non-zero, run `tools/php-cs-fixer fix orkui/template/revised-frontend/scroll/_text.tpl`
first and commit that separately.

- [ ] **Step 2: Reproduce case zero and write the guard test**

Before changing anything, capture the failing measurement so the fix has a number to
beat:

```bash
ORK3_SWEEP_ONLY=caseZero ORK3_SWEEP_THRESHOLD=0 npm run sweep:scrolls
jq '[.results[] | select(.text=="caseZero")] | map({id, CB: .CB.score}) | sort_by(-.CB) | .[0:5]' \
   tools/scroll-sweep/out/results.json
```

Record the top five. These are what step 7 must drive to zero.

- [ ] **Step 3: Move the flourishes out of the `"Amtgard"` span**

In `_text.tpl`, the two flourishes currently sit inside the `<span>` that carries
`position:relative`, which is what anchors them to line one. Move them to be children
of `<section class="IssuingTextSection">` and tag each with the block it decorates:

```php
    <section class="IssuingTextSection">
        <?php /*
            The flourishes are NOT inside the "Amtgard" span any more.

            That span is line one, and `position:relative` on it made every offset
            below measured from line one's box. The block those offsets are meant to
            decorate is the whole issuing section, whose height depends on how many
            lines the title and the name take — so a two-line title walked line two
            underneath #BottomTextFlourish while `margin-left:4.4em`, the gutter
            reserved for that very swash, went on protecting a line the swash was no
            longer beside.

            They are anchored to the SECTION now, and their offsets are custom
            properties that scrollery-autofit.js overwrites from the measured box of
            the element named in data-orn-anchor. The CSS defaults are exactly the
            constants that shipped, so a render with no JavaScript draws what it drew
            before.
        */ ?>
        <img id="TopTextFlourish" class="Flourish" alt=""
             data-orn-anchor=".IssuingTextSection > p:first-child" data-orn-edge="top"
             src="<?= scroll_resource($res['flourish_top']) ?>">
        <img id="BottomTextFlourish" class="Flourish" alt=""
             data-orn-anchor=".IssuingTextSection > p:first-child" data-orn-edge="bottom"
             src="<?= scroll_resource($res['flourish_bottom']) ?>">
        <p>
            <span style="font-size:1.15em;margin-right:-10px;position:relative;">Amtgard</span>
            <span id="IssuerTitle" data-scroll-text="issuerTitle">and<?= $s['useThe'] ? ' the' : '' ?> <?= scroll_e($s['issuerTitle']) ?><?= $s['useOf'] ? ' of' : '' ?></span>
            <br/>
        </p>
        <p>
            <span data-scroll-text="issuerName" data-af-inv="1,1,0.5,14,10" style="font-size:max(1em, calc(1em + 0.5em * (14 / <?= $issuerLen ?>)))"><?= scroll_e($s['issuerName']) ?></span>
        </p>
        <img id="RightTextFlourish" class="Flourish" alt=""
             data-orn-anchor=".IssuingTextSection > p:nth-child(2)" data-orn-edge="bottom-right"
             src="<?= scroll_resource($res['flourish_right']) ?>">
        <p class="IssuingVerb Body">
            <span>presents</span>
        </p>
    </section>
```

Note `p:nth-child(2)` in the anchor selectors: moving the images changes the `<p>`
elements' sibling positions. **Verify the two CSS rules
`section.IssuingTextSection > p:first-child` and `> p:nth-child(2)` still match the
intended paragraphs after the move**, and adjust the selectors in `scrollery.css` if
not. Getting this wrong silently unstyles the issuing block.

- [ ] **Step 4: Turn the ornament offsets into clamped custom properties**

In `scrollery.css`, replace the three ID rules:

```css
/* The ornament offsets are DEFAULTS, not decisions.

   Each is the constant that shipped, kept so a render with no JavaScript draws
   exactly what it drew before — the same contract --sc-sign-lift-want has with
   --sc-sign-lift. scrollery-autofit.js measures the box named in data-orn-anchor and
   overwrites the -- property; the *-max values below are the ceiling it clamps to, so
   a measured answer can never push an ornament further than the art allows. */
.award-template {
    --sc-orn-top-bottom: 0.9em;
    --sc-orn-top-left: -0.4em;
    --sc-orn-bottom-top: 0.95em;
    --sc-orn-bottom-left: -0.6em;
    --sc-orn-right-bottom: -1.2em;
    --sc-swash-hang: 0.9em;

    /* Ceilings. An ornament may travel this far from its declared offset and no
       further; past it the ornament is yielding more than the composition can carry
       and the type has to give instead (see the keep-out pass). */
    --sc-orn-travel-max: 2.2em;
}

section.IssuingTextSection { position: relative; }

#TopTextFlourish {
    width: 3.8em;
    left: var(--sc-orn-top-left);
    bottom: var(--sc-orn-top-bottom);
}

#BottomTextFlourish {
    width: 3.3em;
    left: var(--sc-orn-bottom-left);
    top: var(--sc-orn-bottom-top);
}

#RightTextFlourish {
    width: 4.5em;
    right: calc(-1 * var(--sc-swash-hang, 1em));
    bottom: var(--sc-orn-right-bottom);
}
```

- [ ] **Step 5: Add `anchorOrnaments()` to `scrollery-autofit.js`**

Insert next to `liftSignature()` and call it from `fitAward()` immediately before it:

```js
    /*
     * Put each ornament where the block it decorates actually ENDS UP.
     *
     * The offsets in scrollery.css are written against a one-line block. They are
     * right for the common case and wrong the moment the issuing line wraps, which is
     * how a swash came to be drawn through line two. This measures the anchor block's
     * painted box and re-offsets the ornament from it.
     *
     * Painted, not laid out: paintedBottom() clamps to every clipping ancestor, for
     * the same reason it does in liftSignature(). And CLAMPED: an ornament may travel
     * --sc-orn-travel-max and no further, so a pathological block cannot fling a
     * flourish across the certificate. Past the clamp the ornament stays put and the
     * keep-out pass makes the type yield instead.
     */
    var ORN = [
        { id: 'TopTextFlourish',    prop: '--sc-orn-top-bottom',   edge: 'top' },
        { id: 'BottomTextFlourish', prop: '--sc-orn-bottom-top',   edge: 'bottom' },
        { id: 'RightTextFlourish',  prop: '--sc-orn-right-bottom', edge: 'bottom' }
    ];

    function anchorOrnaments(award) {
        var tpl = templateOf(award);
        var section = award.querySelector('section.IssuingTextSection');
        if (!tpl || !section) { return; }

        var k = frameScale(award) || 1;
        var em = px(award, 'fontSize') || 1;
        var maxTravel = parseFloat(
            global.getComputedStyle(tpl).getPropertyValue('--sc-orn-travel-max')
        ) || 2.2;

        var sectionBox = section.getBoundingClientRect();

        for (var i = 0; i < ORN.length; i++) {
            var spec = ORN[i];
            var img = award.querySelector('#' + spec.id);
            if (!img) { continue; }

            var anchor = award.querySelector(img.getAttribute('data-orn-anchor') || '');
            if (!anchor) { continue; }

            /* Restore the declared value before measuring, so a second run cannot
               compound on the first. Same discipline as liftSignature(). */
            tpl.style.removeProperty(spec.prop);
            var declared = parseFloat(
                global.getComputedStyle(tpl).getPropertyValue(spec.prop)
            ) || 0;

            var box = anchor.getBoundingClientRect();
            var want;
            if (spec.edge === 'top') {
                /* `bottom:` is measured up from the section's bottom edge. */
                want = (sectionBox.bottom - box.top) / k / em;
            } else {
                /* `top:` is measured down from the section's top edge. */
                want = (box.bottom - sectionBox.top) / k / em;
            }

            var travel = want - declared;
            if (travel > maxTravel) { want = declared + maxTravel; }
            if (travel < -maxTravel) { want = declared - maxTravel; }

            tpl.style.setProperty(spec.prop, want.toFixed(4) + 'em');
        }
    }
```

> **Execution note:** the exact arithmetic for each edge must be checked against the
> live DOM before it is trusted — `bottom:` on `#TopTextFlourish` is measured from the
> containing block's bottom, and the containing block is now the section rather than
> the span. Load case zero, run `anchorOrnaments` by hand in the console, and confirm
> each flourish lands where it should *by looking at it*, before running the sweep.

- [ ] **Step 6: Look at case zero**

Load the case-zero URL in a browser at 1:1, screenshot it, and **look at the image**.
The swash must not cross a line. A fix that has only been measured is not a fix.

- [ ] **Step 7: Re-run the full sweep and diff against the baseline**

```bash
npm run sweep:scrolls
node -e "
const b=require('./tools/scroll-sweep/baseline/results.json').results;
const a=require('./tools/scroll-sweep/out/results.json').results;
const w=r=>Math.max(r.CA.score,r.CB.score,r.CC.score);
const B=Object.fromEntries(b.map(r=>[r.id,w(r)]));
const regressed=a.filter(r=>w(r)>0.5 && (B[r.id]??0)<=0.5);
const fixed=a.filter(r=>w(r)<=0.5 && (B[r.id]??0)>0.5);
console.log('fixed',fixed.length,'REGRESSED',regressed.length);
regressed.slice(0,20).forEach(r=>console.log(' !',r.id,(B[r.id]??0).toFixed(2),'->',w(r).toFixed(2)));
"
```

**`REGRESSED` must be 0.** Moving something to fix one collision creates another; this
is the check that catches it. If it is non-zero, the offending cases are the next
thing to fix — do not proceed.

- [ ] **Step 8: Commit**

```bash
git add orkui/template/revised-frontend/scroll/_text.tpl \
        orkui/template/revised-frontend/style/scrollery.css \
        orkui/template/revised-frontend/script/scrollery-autofit.js
git diff --cached
git commit -m "Bugfix: Scrollery — anchor the flourishes to the block they decorate, not to its first line"
```

---

## Task 9: Fix 4.2 — keep-out regions

**Files:**
- Modify: `orkui/template/revised-frontend/script/scrollery-autofit.js`
- Modify: `orkui/template/revised-frontend/style/scrollery.css`
- Modify: `orkui/template/revised-frontend/Scrollery_render.tpl` and
  `Scrollery_create.tpl` (emit the ink manifest for the ornaments on the page)

**Interfaces:**
- Consumes: `assets/scrolls/ink-extents.json` from Task 7.
- Produces: `window.SCROLL_INK` (the per-ornament ink fractions, emitted as JSON) and
  `ScrolleryAutofit.applyKeepOut(award)`.

- [ ] **Step 1: Emit the ink fractions to the page**

Only the entries the page can actually use — the four default ornaments — so the
manifest does not become a 250-entry payload on a print page. Add to `Scroll`:

```php
    /**
     * The ink extents of the ornaments a certificate draws, for the keep-out pass.
     *
     * Only DEFAULT_RESOURCES: the plates are layer A and the keep-out pass does not
     * move them, so shipping all 250 entries to a print page would be bytes nothing
     * reads. Returns [] when the manifest is missing, and the client then falls back
     * to the ornament's box — the pre-manifest behaviour, which is looser rather than
     * broken.
     *
     * @return array<string, array{ink: array{x0: float, y0: float, x1: float, y1: float}}>
     */
    public static function OrnamentInk(): array
```

Emit it in both surfaces next to the existing script tags:

```php
<script>window.SCROLL_INK = <?= json_encode(Scroll::OrnamentInk()) ?>;</script>
```

- [ ] **Step 2: Write the failing sweep expectation**

The keep-out pass exists to close whatever Task 8 did not. Take the dirty list from
Task 8 step 7's output — the cases still over threshold — and record them:

```bash
jq -r '.results[] | select((.CA.score>0.5) or (.CB.score>0.5) or (.CC.score>0.5)) | .id' \
   tools/scroll-sweep/out/results.json > /tmp/still-dirty.txt
wc -l /tmp/still-dirty.txt
```

This file is the acceptance list for this task: every id in it must measure clean at
the end, and nothing outside it may become dirty.

- [ ] **Step 3: Implement `applyKeepOut()`**

The pass, in `scrollery-autofit.js`, after `anchorOrnaments()`:

- For each ornament, compute its **ink box** in screen px: its element rect, inset by
  the fractions from `window.SCROLL_INK` (falling back to the full rect when the
  manifest has no entry).
- For each text block that overlaps an ink box, compute the horizontal or vertical
  inset needed to clear it plus `KEEPOUT_GAP` (declare it next to `SIGN_MIN_GAP`).
- Write the inset as a CSS custom property on the block (`--sc-keepout-left`,
  `--sc-keepout-right`), which `scrollery.css` consumes as `padding-inline`.
- **Clamp**: an inset may not exceed `--sc-keepout-max` (declare at `4em`). Past that
  the text has to shrink instead, which is `fitInline()`'s job — mark the block and
  let the existing ladder re-run.
- Idempotent: clear the properties and re-measure at the top, exactly as
  `liftSignature()` does.

Replace `p:nth-child(2)`'s hard-coded `margin-left: 4.4em` with the measured inset,
and leave a comment saying that 4.4em was the constant this pass replaces and why it
could only ever have been right for a one-line title.

- [ ] **Step 4: Re-run the sweep and check the acceptance list**

```bash
npm run sweep:scrolls
comm -12 <(sort /tmp/still-dirty.txt) \
         <(jq -r '.results[] | select((.CA.score>0.5) or (.CB.score>0.5) or (.CC.score>0.5)) | .id' \
              tools/scroll-sweep/out/results.json | sort)
```

Expected: empty output — no case from the acceptance list is still dirty. Then re-run
the regression diff from Task 8 step 7; **`REGRESSED` must be 0**.

- [ ] **Step 5: Look at five of them**

Open the PNGs for five cases that this task fixed, at 1:1. Confirm by eye that the
type cleared the ornament rather than the ornament vanishing or the type collapsing to
an unreadable size.

- [ ] **Step 6: Commit**

```bash
git add system/lib/ork3/class.Scroll.php \
        orkui/template/revised-frontend/script/scrollery-autofit.js \
        orkui/template/revised-frontend/style/scrollery.css \
        orkui/template/revised-frontend/Scrollery_render.tpl \
        orkui/template/revised-frontend/Scrollery_create.tpl
git diff --cached
git commit -m "Enhancement: Scrollery — keep-out regions measured from ink, replacing the one-line gutter"
```

---

## Task 10: Fix 4.3 — a third issuer tier

**Files:**
- Modify: `orkui/template/revised-frontend/script/scrollery-autofit.js`

- [ ] **Step 1: Identify the cases the ladder cannot serve**

From the current sweep output, list cases where the issuer name is still on three
lines or still colliding after Tasks 8-9:

```bash
jq -r '.results[] | select(.CB.score>0.5) | "\(.id) \(.CB.score)"' tools/scroll-sweep/out/results.json
```

- [ ] **Step 2: Add the tier**

In `POLICY.issuer.lines`, append a third rung and lower `minEm` to match:

```js
            lines: [
                { n: 1, floor: 0.62 },
                { n: 2, floor: 0.50 },
                /* A third rung, because two was not a ladder — it was a floor with a
                   step in front of it. The measured case: "and the Kingdom of" in
                   Cinzel puts the TITLE on two lines before the name has had a turn,
                   so a two-rung ladder that only ever shrinks the name cannot reach
                   an answer. 0.42em is 42px of a 2550px frame printed at 8.5in, about
                   10pt — small for an issuing body and legible, and it is reached only
                   by a name no shorter rung could set. */
                { n: 3, floor: 0.42 }
            ],
            minEm: 0.42
```

- [ ] **Step 3: Confirm the multi-up floor still refuses safely**

`FLOOR_SCALE` multiplies the ladder floors by 1.5 at `&up=2|4`. Check that 0.42 × 1.5
= 0.63 does not sit above the *first* rung (0.62) — it does, by 0.01em, which would
mean no issuer name ever shrinks on a multi-up sheet. Either exempt the third rung
from `FLOOR_SCALE` or lower it; the exemption is preferable and must carry a comment
explaining the arithmetic, in the style of the existing citation exemption.

- [ ] **Step 4: Sweep, diff, look**

```bash
npm run sweep:scrolls
# regression diff from Task 8 step 7 — REGRESSED must be 0
```

Then open two multi-up cases (`up=2`) at 1:1 and confirm the issuing line is legible.

- [ ] **Step 5: Commit**

```bash
git add orkui/template/revised-frontend/script/scrollery-autofit.js
git diff --cached
git commit -m "Bugfix: Scrollery — a third issuer rung, because two was a floor with a step in front of it"
```

---

## Task 11: Retire the subsumed constants

**Files:**
- Modify: `orkui/template/revised-frontend/style/scrollery.css`

For **each** of these, in this order, one commit each:
`--sc-sign-lift-want` crown · griffon (5 rows) · garber (2 rows) · dragon (3 rows) ·
`--sc-swash-hang` · `p:nth-child(2)`'s residual gutter.

- [ ] **Step 1: For one constant, delete it and sweep**

```bash
# delete the rule, then:
npm run sweep:scrolls
jq -r '.results[] | select(.order=="crown") | "\(.id) \(.CA.score)"' tools/scroll-sweep/out/results.json
```

- [ ] **Step 2: Decide from the measurement, not from taste**

- Every affected case still under threshold → the mechanism subsumes it. **Delete the
  constant** and note in the characterisation doc that this prior fix was
  symptom-level, with the case ids that prove it.
- Any case goes over → **restore the constant**, and replace its comment with what the
  sweep showed: which cases need it, what the mechanism could not express, and why.

- [ ] **Step 3: Commit that one constant, then repeat**

```bash
git add orkui/template/revised-frontend/style/scrollery.css
git diff --cached
git commit -m "Refactor: Scrollery — retire the crown sign-lift constant, subsumed by the measured pass"
```

Do not batch these. One constant per commit is what makes a later bisect able to name
the one that was wrong.

---

## Task 12: The coverage test

**Files:**
- Create: `tests/Unit/ScrollSweepCoverageTest.php`

- [ ] **Step 1: Write it**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The sweep is only a proof while it covers everything.
 *
 * A new hand or a new order partial that nothing sweeps is exactly how this class of
 * defect got in: the six display faces added most recently are wider than the one
 * everything was laid out against, and nothing failed when they landed. This test is
 * what fails now.
 *
 * It asserts against tools/scroll-sweep/axes.json, which bin/dump-axes.php generates
 * from the PHP data files — so the failure mode is "you added a hand and did not
 * regenerate the axes", which is one command to fix and impossible to miss.
 */
final class ScrollSweepCoverageTest extends TestCase
{
    private const AXES = __DIR__ . '/../../tools/scroll-sweep/axes.json';
    private const PARTIALS = __DIR__ . '/../../orkui/template/revised-frontend/scroll/';
    private const UNREACHABLE = __DIR__ . '/../../tools/scroll-sweep/unreachable-orders.json';

    private function axes(): array
    {
        $this->assertFileExists(self::AXES, 'Run: php tools/scroll-sweep/bin/dump-axes.php > tools/scroll-sweep/axes.json');

        return json_decode((string) file_get_contents(self::AXES), true);
    }

    public function testEveryHandIsSwept(): void
    {
        $recipes = require __DIR__ . '/../../system/lib/ork3/data/scroll-recipes.php';
        $this->assertSame(
            array_keys($recipes['hands']),
            $this->axes()['hands'],
            'A hand was added or removed without regenerating tools/scroll-sweep/axes.json'
        );
    }

    public function testEveryOrderPartialIsSweptOrDeclaredUnreachable(): void
    {
        $partials = [];
        foreach (glob(self::PARTIALS . '*.tpl') as $file) {
            $name = basename($file, '.tpl');
            if (str_starts_with($name, '_')) {
                continue;   // shared layers, not orders
            }
            $partials[] = $name;
        }
        sort($partials);

        $swept = array_values(array_unique(array_column($this->axes()['orderVariants'], 'order')));
        $declared = is_file(self::UNREACHABLE)
            ? json_decode((string) file_get_contents(self::UNREACHABLE), true)['unreachable']
            : [];

        $missing = array_diff($partials, $swept, $declared);
        $this->assertSame(
            [],
            array_values($missing),
            'These order partials are neither swept nor declared unreachable: '
            . implode(', ', $missing)
        );
    }

    public function testTheDumpedCustomNameCapMatchesTheConstant(): void
    {
        $this->assertSame(
            \Scroll::CUSTOM_NAME_MAX_LENGTH,
            $this->axes()['caps']['customName'],
            'dump-axes.php reads this constant with a regex; it has drifted'
        );
    }

    public function testEveryVariantIsSwept(): void
    {
        $orders = require __DIR__ . '/../../system/lib/ork3/data/scroll-orders.php';
        $expected = 0;
        foreach ($orders as $def) {
            $expected += max(1, count($def['variants'] ?? []));
        }
        $this->assertCount(
            $expected,
            $this->axes()['orderVariants'],
            'A variant was added without regenerating tools/scroll-sweep/axes.json'
        );
    }
}
```

- [ ] **Step 2: Create `tools/scroll-sweep/unreachable-orders.json`**

```json
{
  "_comment": "Order partials no route can reach, with the reason. The sweep cannot cover these; the coverage test requires each to be declared here rather than silently skipped.",
  "unreachable": ["hunter"],
  "reasons": {
    "hunter": "award_id 6969 is Arcanyst's invention and is not an ORK award id — see system/lib/ork3/data/scroll-orders.php. No ork_kingdomaward row can resolve to it, so hunter.tpl is unreachable as an ORDER. hunter/border.png is live as a custom-recipe border and IS swept by stage C."
  }
}
```

- [ ] **Step 3: Prove it fails when a hand is added**

```bash
./bin/run-unit-tests.sh --filter ScrollSweepCoverageTest      # expect: pass
# temporarily add a fake hand to scroll-recipes.php, re-run:
./bin/run-unit-tests.sh --filter ScrollSweepCoverageTest      # expect: FAIL on testEveryHandIsSwept
# revert the fake hand
```

A test that has never been seen to fail is not known to work.

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/ScrollSweepCoverageTest.php tools/scroll-sweep/unreachable-orders.json
git diff --cached
git commit -m "Enhancement: Scrollery — fail the build when a hand or a plate is added without being swept"
```

---

## Task 13: Both surfaces, the sheet axes, and the final proof

**Files:**
- Modify: `tools/scroll-sweep/sweep.spec.ts` (stage D)
- Create: `docs/scrollery-ink-collision-results.md`

- [ ] **Step 1: Add stage D to the runner**

After the main loop, take every result within 2× of threshold and re-run it on the
composer preview and on each sheet variant (`up=2`, `up=4`, `trim`, `lines=0`), plus a
fixed representative set of 20 cases so the surface is covered even when nothing is
near the line.

- [ ] **Step 2: Run everything**

```bash
npm run sweep:scrolls              # routine tier, both surfaces
npm run sweep:scrolls:exhaustive   # validates stage C's factorisation
./bin/run-unit-tests.sh --filter 'Scroll'
npm run sweep:scrolls:unit
```

- [ ] **Step 3: Confirm the factorisation held**

Compare the exhaustive tier's dirty set against the routine tier's. **If the
exhaustive run finds a dirty case the routine tier's factorisation skipped, the
factorisation was wrong** — widen stage C in `lib/cases.ts` to cross whatever axis
carried it, and say so in the README. A sample is not a sweep, and a factorisation is
only as good as its validation.

- [ ] **Step 4: Write the results document**

`docs/scrollery-ink-collision-results.md`: the acceptance table (cases, threshold,
over-threshold count, per-surface), the regression diff against
`tools/scroll-sweep/baseline/results.json`, the severity distribution, and the list of
prior constants retired versus kept with the reason for each.

- [ ] **Step 5: Render and look at the named cases**

Case zero and every previously-reported instance — the crown, dragon r8-r10, garber
r9-r10 and griffon r0/r5-r8 sign-and-date cases, and the issuer-name-on-the-knotwork
case — at 1:1 or better. **Look at every image.** Attach them. A fix that has only
been measured is not reported as a fix.

- [ ] **Step 6: Update the characterisation with the measured verdicts**

Return to `docs/scrollery-ink-collision-class.md` and replace the hypothesis table
with what Task 11 measured: for each of the four prior fixes, symptom or cause, with
the case ids.

- [ ] **Step 7: Final commit**

```bash
git add docs/scrollery-ink-collision-results.md docs/scrollery-ink-collision-class.md \
        tools/scroll-sweep/sweep.spec.ts tools/scroll-sweep/README.md
git diff --cached
git commit -m "Docs: Scrollery — the ink-collision sweep results, and which prior fixes were symptoms"
```

---

## Self-review notes

**Spec coverage:** §1 → Tasks 6, 13. §2.1-2.2 → Task 3. §2.3-2.4 → Task 4. §3.1 →
Task 1. §3.2 → Task 2. §4.1 → Task 8. §4.2 → Tasks 7, 9. §4.3 → Task 10. §4.4-4.5 →
Task 11. §5 → task ordering, and the regression diff repeated in Tasks 8-11. §6 →
Tasks 5, 7, 12, 13. §7 → Task 13.

**Known risks, flagged rather than hidden:**

1. **`hunter` is unreachable.** Confirmed against the DB: kingdom 1 carries all 16 real
   orders but nothing resolves to award_id 6969. Task 2 reports it, Task 12 requires it
   to be declared. The spec's "17 order partials" is therefore met as *16 swept + 1
   declared unreachable with a reason*, and that is stated rather than glossed.
2. **`compose` writes a print-log row per case** — roughly 1,400 rows per sweep in the
   local DB. Accepted in the spec; if it becomes a problem the fallback is a dev-gated
   no-log route, which is a change to a shipped module and was deliberately not taken.
3. **Task 8 step 3 changes sibling positions**, so `p:first-child` / `p:nth-child(2)`
   in `scrollery.css` may stop matching. The step says to verify; an implementer who
   skips it will silently unstyle the issuing block.
4. **Wall-clock is unmeasured** until Task 6 step 1. If the routine tier runs far past
   30 minutes, the lever is Playwright workers (currently 1 in `playwright.config.ts`)
   — raise it for the `scroll-sweep` project rather than shrinking the matrix.
