import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { loadCorpus, renderFixtures, serveFixtures, openFixture } from '../lib/fixture';
import { openPrint, prepareFixtures, fixtureSpecFor, fixtureCatalogue, fixtureCovers, FIXTURE_ORDERS } from '../lib/drive';
import { applyLayer } from '../lib/isolate';
import { captureLayers, typeBand, scoreInk } from '../lib/raster';
import { diffMask, count } from '../lib/mask';
import { writeReport, isDirtyTop, ink, ZERO, type Result } from '../lib/report';
import { PROFILES, PROFILE_NAMES } from '../lib/text';
import { loadAxes } from '../lib/axes';
import { enumerate, namedCases, type SweepCase } from '../lib/cases';

/* The same geometry as the sweep: one CSS pixel on one frame pixel, tall enough that a
   clipped screenshot reaches the bottom of the certificate. */
test.use({ viewport: { width: 2600, height: 3400 }, deviceScaleFactor: 1 });

/* Every test here drives the fixture renderer, so none of them needs a login. The mode is
   read when openPrint is CALLED, so setting it here is enough and a stray shell value
   cannot send these to the composer. */
let prior: string | undefined;
test.beforeAll(() => { prior = process.env.ORK3_SWEEP_DRIVER; process.env.ORK3_SWEEP_DRIVER = 'fixture'; });
test.afterAll(() => {
  if (prior === undefined) delete process.env.ORK3_SWEEP_DRIVER; else process.env.ORK3_SWEEP_DRIVER = prior;
});

const c22 = () => loadCorpus('review-2026-10-25').filter(c => c.id === 'c22');

test('Ctop hides only the bottom half', async ({ page }) => {
  const specs = c22();
  await serveFixtures(page, renderFixtures(specs, 'topscore-c22'));
  await openFixture(page, specs[0]);

  const colour = (sel: string) => page.locator(sel).first().evaluate(el => getComputedStyle(el).color);
  const box = () => page.locator('.AwardeeName').first().evaluate(el => {
    const r = el.getBoundingClientRect();
    return [Math.round(r.x), Math.round(r.y), Math.round(r.width), Math.round(r.height)];
  });

  await applyLayer(page, 'full');
  const orderColour = await colour('.Order');
  const fullBox = await box();
  expect(orderColour, 'this test is pointless if the order line is already invisible').not.toBe('rgba(0, 0, 0, 0)');

  await applyLayer(page, 'Ctop');
  expect(await colour('.Order'), 'Ctop hid the order line, which is the top half').toBe(orderColour);
  expect(await colour('.Award_Reason')).toBe('rgba(0, 0, 0, 0)');
  for (const sel of ['.Flavour', '.AwardDate', '.AwardSignatory']) {
    expect(await colour(sel), sel).toBe('rgba(0, 0, 0, 0)');
  }
  expect(await box(), 'Ctop reflowed the page').toEqual(fullBox);
});

test('the Ctop raster is the top half of layer C and nothing else', async ({ page }) => {
  const specs = c22();
  await serveFixtures(page, renderFixtures(specs, 'topscore-c22'));
  await openFixture(page, specs[0]);
  const shots = await captureLayers(page, await typeBand(page));
  const G = shots.G;
  const C = diffMask(shots.C, G);
  const top = diffMask(shots.Ctop, G);

  expect(count(top), 'Ctop found no ink at all').toBeGreaterThan(1000);
  expect(count(top), 'Ctop is not smaller than C, so nothing was hidden').toBeLessThan(count(C));
  /* A subset by pixel, not by count: hiding glyphs must never ADD ink anywhere. */
  let outside = 0;
  for (let p = 0; p < top.bits.length; p++) if (top.bits[p] && !C.bits[p]) outside++;
  expect(outside, 'Ctop painted pixels that layer C does not').toBe(0);
});

test('CAtop and CBtop are scored in the same units as CA and CB, and never exceed them', async ({ page }) => {
  const specs = c22();
  await serveFixtures(page, renderFixtures(specs, 'topscore-c22'));
  await openFixture(page, specs[0]);
  const s = scoreInk(await captureLayers(page, await typeBand(page)));

  expect(s.glyphPx).toBeGreaterThan(1000);
  /* The top half's glyphs are a subset of the whole certificate's, so what they strike
     is a subset too. Compared in absolute weighted ink pixels, which is what both are. */
  expect(ink(s.CAtop, s.glyphPx)).toBeLessThanOrEqual(ink(s.CA, s.glyphPx) + 1e-6);
  expect(ink(s.CBtop, s.glyphPx)).toBeLessThanOrEqual(ink(s.CB, s.glyphPx) + 1e-6);
  /* Every certificate carries the designed swash contact (238-262 weighted px per
     lib/report.ts), and it is in the top half. A CBtop of zero means the score is not
     reading the mask. */
  expect(ink(s.CBtop, s.glyphPx), 'CBtop missed the versal swash').toBeGreaterThan(100);
});

test('the fixture driver reaches hunter', async ({ page }) => {
  const hunter: SweepCase = enumerate(loadAxes(), 'A')
    .find(c => c.order === 'hunter' && c.rank === 5 && c.text === 'real')!;
  expect(hunter, 'stage A has no hunter case').toBeTruthy();
  /* The catalogue argument is not consulted in fixture mode: there is no catalogue, and
     hunter is exactly the order the catalogue cannot supply. */
  await openPrint(page, hunter, undefined as never, 1);
  await expect(page.locator('.award-template')).toHaveAttribute('data-order', 'hunter');
});

test('every order in the vocabulary has a fixture identity, and it matches scroll-orders.php', () => {
  const root = join(__dirname, '..', '..', '..');
  const php = JSON.parse(execFileSync('php', ['-r',
    '$o = require $argv[1]; echo json_encode(array_map(fn($d) => ["id" => $d["award_id"], "ranks" => $d["ranks"]], $o));',
    join(root, 'system/lib/ork3/data/scroll-orders.php')], { encoding: 'utf8' })) as
    Record<string, { id: number; ranks: { from: number; to: number } | null }>;

  const orders = [...new Set(loadAxes().orderVariants.map(o => o.order))];
  for (const order of orders) {
    const f = FIXTURE_ORDERS[order];
    expect(f, `${order} has no entry in FIXTURE_ORDERS`).toBeTruthy();
    expect(f.minRank, `${order} min rank`).toBe(php[order].ranks?.from ?? 0);
    expect(f.maxRank, `${order} max rank`).toBe(php[order].ranks?.to ?? 10);
    /* hunter's award_id is Arcanyst's invention (6969): the real archery grant has no base
       award and is matched by NAME, so it is the one order whose id is not the data file's.
       custom is 0 in the data file and is reached through Custom Award, 94. */
    const want = order === 'hunter' ? 0 : order === 'custom' ? 94 : php[order].id;
    expect(f.awardId, `${order} award id`).toBe(want);
  }
});

test('every order renders as itself through the fixture driver', async ({ page }) => {
  const seed = enumerate(loadAxes(), 'A').filter(c => c.rank === 5 && c.text === 'real');
  expect(seed.length).toBeGreaterThanOrEqual(20);
  await prepareFixtures(page, seed, 'topscore-orders');
  for (const c of seed) {
    await openPrint(page, c, undefined as never, 1);   // throws if data-order is not c.order
  }
});

test('every case the baseline stages enumerate renders in one batch', () => {
  /* The baseline is an hour of sweep. A spec the renderer refuses would be a hole in it that
     the run reports only at the end, one case at a time. Stages N, A, B and C, filtered the
     way sweep.spec.ts filters them. */
  const axes = loadAxes();
  const { byOrder } = fixtureCatalogue();
  const cases = [...namedCases(), ...enumerate(axes, 'A'), ...enumerate(axes, 'B'), ...enumerate(axes, 'C')]
    .filter(c => !!byOrder[c.order] && c.rank >= byOrder[c.order].minRank && c.rank <= byOrder[c.order].maxRank)
    .filter(fixtureCovers);
  expect(cases.length).toBeGreaterThan(2000);
  const specs = new Map(cases.map(c => { const s = fixtureSpecFor(c); return [s.id, s] as const; }));
  expect(specs.size, 'two cases share a fixture file').toBe(cases.length);
  const dir = renderFixtures([...specs.values()], 'topscore-baseline');
  const missing = [...specs.keys()].filter(id => !existsSync(join(dir, `${id}.html`)));
  expect(missing, 'specs the renderer refused').toEqual([]);
});

test('the conversion follows the brief: award id, title, scope', () => {
  const byId = (id: string) => namedCases().find(c => c.id === id)!;
  const hunter = fixtureSpecFor({ ...byId('N_case-zero'), order: 'hunter', variant: '' });
  expect(hunter).toMatchObject({ order: 'hunter', awardId: 0, title: 'Order of the Hunter', scope: 'kingdom' });

  const custom = fixtureSpecFor({ ...byId('N_case-zero'), order: 'custom', variant: '', text: 'real' });
  expect(custom).toMatchObject({ order: 'custom', awardId: 94, title: PROFILES.real.customName, scope: 'kingdom' });

  const battle = fixtureSpecFor(byId('N_case-zero'));
  expect(battle).toMatchObject({ awardId: 243, title: 'Order of Battle', variant: 'arcane', rank: 4, hand: 'cinzel' });
  expect(battle.issuerName).toBe(PROFILES.caseZero.issuerName);
  expect(battle.useThe).toBe(true);
  /* compose sends no IsLadder; see bin/scroll-render-fixtures.php. */
  expect('isLadder' in battle).toBe(false);

  /* Two cases that differ only in what the sweep overrides after enumeration (a hand, a
     variant) must not share a file, or the second would measure the first. */
  const a = fixtureSpecFor({ ...byId('N_case-zero'), hand: 'chancery' });
  const b = fixtureSpecFor({ ...byId('N_case-zero'), hand: 'cinzel' });
  expect(a.id).not.toBe(b.id);
  expect(a.id).toMatch(/^[A-Za-z0-9_-]+$/);
});

test('kingdomReal is the real profile as a kingdom-issued certificate prints it', () => {
  expect(PROFILE_NAMES).toContain('kingdomReal');
  const k = PROFILES.kingdomReal;
  expect(k).toEqual({ ...PROFILES.real, issuerTitle: '', useThe: false, useOf: false,
    issuerName: 'The Principality of the Crimson Sands' });
});

const result = (over: Partial<Result>): Result => ({
  id: 'x', stage: 'A', surface: 'print', order: 'crown', variant: '', rank: 5, hand: 'chancery',
  text: 'real', glyphPx: 1000, CA: ZERO, CB: ZERO, CC: ZERO, ccPair: '', CAtop: ZERO, CBtop: ZERO,
  ...over,
});
/* ink() is score * glyphPx / 1000, so with glyphPx 1000 a score IS the weighted pixel count. */
const sc = (n: number) => ({ score: n, overlapPx: n, worstRun: n });

test('isDirtyTop uses the whole-certificate thresholds, 300 on artwork and 500 on ornament', () => {
  expect(isDirtyTop(result({ CAtop: sc(299) }))).toBe(false);
  expect(isDirtyTop(result({ CAtop: sc(301) }))).toBe(true);
  expect(isDirtyTop(result({ CBtop: sc(499) }))).toBe(false);
  expect(isDirtyTop(result({ CBtop: sc(501) }))).toBe(true);
  /* Not on text-on-text: the top-half scores have no C∩C twin. */
  expect(isDirtyTop(result({ CC: sc(900) }))).toBe(false);
  /* A result from before these scores existed is clean here, not a crash. */
  expect(isDirtyTop(result({ CAtop: undefined, CBtop: undefined }))).toBe(false);
});

test('the report prints the top-half columns, and an old results.json still reports', () => {
  const dir = mkdtempSync(join(tmpdir(), 'sweep-report-'));
  writeReport([
    result({ id: 'new', CA: sc(400), CAtop: sc(350), CBtop: sc(10) }),
    result({ id: 'old', CA: sc(400), CAtop: undefined, CBtop: undefined }),
  ], dir, 0);
  const md = readFileSync(join(dir, 'report.md'), 'utf8');
  expect(md).toContain('C∩A top');
  expect(md).toContain('C∩B top');
  const json = JSON.parse(readFileSync(join(dir, 'results.json'), 'utf8')) as { results: Result[] };
  expect(json.results.find(r => r.id === 'new')!.CAtop!.score).toBe(350);
});
