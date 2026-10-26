import { test, expect, type Page } from '@playwright/test';
import { caseId, type SweepCase } from '../lib/cases';
import { fixtureSpecFor } from '../lib/drive';
import { openFixture, renderFixtures, serveFixtures } from '../lib/fixture';
import { applyLayer, forceFullScale, markGround } from '../lib/isolate';
import { count, diffMask, type Mask } from '../lib/mask';
import { captureLayers } from '../lib/raster';
import {
  assertEntry, combine, decide, entryProblem, hidingCss, isFullFrame, plan, readPolicy, resolveUnderlays, setUnderlayCss, tagPlates, underlayCssFor, windowClip,
  type Box,
} from '../lib/underlays';

/*
 * The shared underlay resolver (lib/underlays.ts) and what it is for: the sweep's layer A applies the
 * policy the way the layout does, including a policy WINDOW (a plate that is an underlay only inside
 * some frame rows). No login: the certificates come from the fixture renderer.
 *
 * Garber's rank_N.png is the window's customer. It holds two faint threads in the upper half, designed
 * to run behind the type, and a hard-edged scissors / tape / numeral cluster at the foot that is not.
 */

test.use({ viewport: { width: 2600, height: 3400 }, deviceScaleFactor: 1 });

const FRAME: Box = { x: 0, y: 0, w: 2550, h: 3300 };

/* ---- the pure parts --------------------------------------------------------------------- */

test('a plain pattern is the whole plate, and it beats a window', () => {
  expect(combine([])).toEqual({ kind: 'none' });
  expect(combine(['a/b.*'])).toEqual({ kind: 'whole', pattern: 'a/b.*' });
  expect(combine([{ pattern: 'a/*', y: [10, 20] }])).toEqual({ kind: 'window', y0: 10, y1: 20, pattern: 'a/*' });
  expect(combine([{ pattern: 'a/*', y: [10, 20] }, 'a/b.*']).kind).toBe('whole');
});

test('a malformed window is rejected at run time, naming the order and the entry', () => {
  const win = (y: unknown, extra: object = {}) => ({ pattern: 'garber/rank_?.*', y, ...extra });
  for (const bad of [
    '', { pattern: '', y: [1, 2] }, { y: [1, 2] }, win([500, 500]), win([900, 500]), win([-1, 500]), win([500, 3301]),
    win([500]), win([500, 900, 1200]), win(['500', '900']), win([500.5, 900]), win(undefined), win([500, 900], { x: [0, 1] }), 7, null, ['a'],
  ]) {
    expect(entryProblem(bad), JSON.stringify(bad)).not.toBeNull();
  }
  for (const good of ['garber/rank_?.*', win([420, 1220]), win([0, 3300])]) {
    expect(entryProblem(good), JSON.stringify(good)).toBeNull();
  }
  expect(() => assertEntry('garber', win([900, 500]))).toThrow(/order 'garber'.*"y":\[900,500\].*y0 >= y1/s);
  expect(() => assertEntry('garber', win([420, 1220]))).not.toThrow();
});

test('the policy resolves garber rank plates to the window and nothing else of garber', () => {
  const v = resolveUnderlays('garber', ['garber/rank_10.webp', 'garber/rank_3.png', 'garber/rank_3_display.webp', 'garber/overlay.webp', 'garber/linen.jpg']);
  expect(v.get('garber/rank_10.webp')).toMatchObject({ kind: 'window', y0: 420, y1: 1220 });
  expect(v.get('garber/rank_3.png')).toMatchObject({ kind: 'window', y0: 420, y1: 1220 });
  expect(v.get('garber/rank_3_display.webp')).toEqual({ kind: 'none' });
  expect(v.get('garber/overlay.webp')).toEqual({ kind: 'none' });
  expect(v.get('garber/linen.jpg')).toEqual({ kind: 'none' });
});

test('only garber has a window', () => {
  const policy = readPolicy();
  const windowed = Object.entries(policy).filter(([, es]) => es.some(e => typeof e !== 'string')).map(([o]) => o);
  expect(windowed).toEqual(['garber']);
});

test('a window on a plate that is not the frame fails closed', () => {
  const win = { kind: 'window', y0: 420, y1: 1220, pattern: 'p' } as const;
  expect(decide(win, FRAME)).toEqual({ verdict: win, failedClosed: false });
  expect(decide(win, { ...FRAME, x: 0.9, w: 2550.9 }).failedClosed, 'sub-pixel slack is within 1px').toBe(false);
  for (const box of [{ ...FRAME, w: 2540 }, { ...FRAME, h: 3290 }, { ...FRAME, x: 5 }, { ...FRAME, y: -3 }]) {
    expect(isFullFrame(box)).toBe(false);
    expect(decide(win, box)).toEqual({ verdict: { kind: 'none' }, failedClosed: true });
  }
  // `whole` and `none` do not depend on the box.
  expect(decide({ kind: 'whole', pattern: 'p' }, { ...FRAME, w: 10 }).failedClosed).toBe(false);
});

test('the hiding css hides a whole plate, clips a window out, and writes nothing for an obstacle', () => {
  const css = hidingCss([
    { tag: '0', verdict: { kind: 'whole', pattern: 'p' } },
    { tag: '1', verdict: { kind: 'window', y0: 330, y1: 1650, pattern: 'q' } },
    { tag: '2', verdict: { kind: 'none' } },
  ]);
  expect(css).toContain('img[data-sweep-plate-img="0"] { visibility: hidden !important; }');
  expect(css).toContain('img[data-sweep-plate-img="1"] { clip-path: polygon(');
  expect(css).not.toContain('"2"');
  // 330/3300 = 10%, 1650/3300 = 50%: the band between them is the one left out of the polygon.
  expect(windowClip(330, 1650)).toBe('polygon(0 0, 100% 0, 100% 10%, 0 10%, 0 50%, 100% 50%, 100% 100%, 0 100%)');
});

/* ---- in a page -------------------------------------------------------------------------- */

const caseFor = (order: string, variant: string, rank: number, text: SweepCase['text'] = 'empty'): SweepCase => {
  const c: SweepCase = { id: '', stage: 'N', order, variant, rank, hand: 'chancery', text, surface: 'print' };
  c.id = caseId(c);
  return c;
};

/** The frame at the page's own origin (as profile.spec.ts does) so a 1:1 clip of it is exact. */
const pinFrame = (page: Page) =>
  page.evaluate(() => {
    const st = document.createElement('style');
    st.textContent = 'svg[data-scroll-frame] { position: absolute !important; left: 0 !important; top: 0 !important; margin: 0 !important; }';
    document.head.appendChild(st);
  });

async function open(page: Page, c: SweepCase): Promise<void> {
  const spec = fixtureSpecFor(c);
  await serveFixtures(page, renderFixtures([spec], `underlay-policy-${spec.id}`.slice(0, 60)));
  await openFixture(page, spec);
  await page.evaluate(() => window.scrollTo(0, 0));
  await forceFullScale(page);
  await pinFrame(page);
}

const FULL = { x: 0, y: 0, width: 2550, height: 3300 };
/** Ink pixels of a mask inside frame rows [y0, y1). */
const inRows = (m: Mask, y0: number, y1: number) => {
  let n = 0;
  for (let y = y0; y < y1; y++) for (let x = 0; x < m.w; x++) n += m.bits[y * m.w + x];
  return n;
};

test('garber rank 10: layer A has no thread and keeps the foot cluster', async ({ page }) => {
  await open(page, caseFor('garber', '', 10));
  const shots = await captureLayers(page, FULL);

  // The same layer WITHOUT the policy, as the thread stands in the plate: the control that makes the
  // assertion below mean something (threads at y 528-575 and 1125-1170, the foot from y 2563).
  await applyLayer(page, 'A');
  await setUnderlayCss(page, '');
  const raw = await page.screenshot({ clip: FULL, type: 'png', animations: 'disabled' });
  await applyLayer(page, 'full');

  const FLOOR = 0.01;
  const rawA = diffMask(raw, shots.G, FLOOR);
  const polA = diffMask(shots.A, shots.G, FLOOR);
  expect(inRows(rawA, 520, 585), 'control: the upper thread is in the unpoliced layer').toBeGreaterThan(1000);
  expect(inRows(rawA, 1115, 1180), 'control: the lower thread is in the unpoliced layer').toBeGreaterThan(1000);
  expect(inRows(polA, 420, 1220), 'the policy window leaves layer A empty of thread').toBe(0);
  expect(inRows(polA, 2563, 3300), 'the foot cluster stays in layer A').toBe(inRows(rawA, 2563, 3300));
  expect(inRows(polA, 2563, 3300)).toBeGreaterThan(10000);
  expect(count(polA)).toBeLessThan(count(rawA));
});

test('garber rank 5 (threads at y 467 and 800): the same window covers them', async ({ page }) => {
  await open(page, caseFor('garber', '', 5));
  const shots = await captureLayers(page, FULL);
  const polA = diffMask(shots.A, shots.G, 0.01);
  expect(inRows(polA, 420, 1220)).toBe(0);
  expect(inRows(polA, 2600, 3300)).toBeGreaterThan(10000);
});

test('a windowed plate that is not the frame stays an obstacle and says so', async ({ page }) => {
  await open(page, caseFor('garber', '', 10));
  await markGround(page);
  await page.evaluate(() => {
    const img = Array.from(document.querySelectorAll('.award-template > img')).find(i => /garber\/rank_10/.test((i as HTMLImageElement).src)) as HTMLElement;
    img.style.setProperty('width', '1200px', 'important');
  });
  const logs: string[] = [];
  const applied = plan(await tagPlates(page), undefined, m => logs.push(m));
  expect(applied.failedClosed).toEqual([expect.stringMatching(/^garber\/rank_10\./)]);
  expect(applied.window).toEqual([]);
  expect(applied.css, 'no rule at all for the shrunken plate').not.toContain('clip-path');
  expect(logs).toHaveLength(1);
  expect(logs[0]).toMatch(/not the 2550x3300 frame/);
});

test('a plate taken for the ground is never hidden, even when the policy lists it', async ({ page }) => {
  await open(page, caseFor('smith', '', 5, 'max'));
  await markGround(page);
  // smith/burn.* IS listed for smith. Before it is a ground the policy hides it from layer A...
  const before = plan(await tagPlates(page), undefined, () => undefined);
  expect(before.whole).toContain('smith/burn.webp');
  // ...and once markGround()/classifyWashes() has taken it for the ground (the same attribute) it is not a plate at all.
  await page.evaluate(() => {
    const img = Array.from(document.querySelectorAll('.award-template > img')).find(i => /smith\/burn/.test((i as HTMLImageElement).src))!;
    img.setAttribute('data-sweep-ground', '1');
  });
  const facts = await tagPlates(page);
  const after = plan(facts, undefined, () => undefined);
  expect(facts.ground).toContain('smith/burn.webp');
  expect(after.whole).not.toContain('smith/burn.webp');
  expect(after.css).toBe('');
  expect((await underlayCssFor(page)).css).toBe('');
});
