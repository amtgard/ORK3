import { test, expect } from '@playwright/test';
import { login } from '../lib/auth';
import { loadCatalogue } from '../lib/catalogue';
import { openPrint } from '../lib/drive';
import { loadAxes } from '../lib/axes';
import { enumerate } from '../lib/cases';
import { applyLayer, markGround } from '../lib/isolate';
import { captureLayers, typeBand } from '../lib/raster';
import { diffMask, count } from '../lib/mask';

/* Same geometry as the sweep itself: 2550 wide puts one CSS pixel on one frame
   pixel, and 3400 tall is what lets a clipped screenshot reach the bottom of the
   certificate — screenshot({ clip }) is clamped to the viewport. */
test.use({ viewport: { width: 2600, height: 3400 }, deviceScaleFactor: 1 });

const KINGDOM = Number(process.env.ORK3_SWEEP_KINGDOM ?? 1);
const pick = (order: string, text: string) =>
  enumerate(loadAxes(), 'A').find(x => x.order === order && x.text === text && x.rank === 5)!;

test.beforeEach(async ({ page }) => { await login(page); });

test('isolating a layer does not move the type', async ({ page }) => {
  const { byOrder } = await loadCatalogue(page, KINGDOM);
  await openPrint(page, pick('crown', 'max'), byOrder['crown'], KINGDOM);

  const boxOf = () => page.locator('.AwardeeName').first().evaluate(el => {
    const r = el.getBoundingClientRect();
    return [Math.round(r.x), Math.round(r.y), Math.round(r.width), Math.round(r.height)];
  });

  const before = await boxOf();
  for (const layer of ['G', 'A', 'B', 'C', 'full'] as const) {
    await applyLayer(page, layer);
    expect(await boxOf(), `layer ${layer} reflowed the page`).toEqual(before);
  }
});

test('the five rasters are produced, share a clip, and differ', async ({ page }) => {
  const { byOrder } = await loadCatalogue(page, KINGDOM);
  await openPrint(page, pick('crown', 'max'), byOrder['crown'], KINGDOM);
  const shots = await captureLayers(page);
  expect(Object.keys(shots).sort()).toEqual(['A', 'B', 'C', 'G', 'full']);
  expect(shots.C.equals(shots.A), 'glyph layer is identical to the plate layer').toBe(false);
  expect(shots.C.equals(shots.full)).toBe(false);
});

test('the ground is identified, and it is not the border or the wash', async ({ page }) => {
  const { byOrder } = await loadCatalogue(page, KINGDOM);
  await openPrint(page, pick('battle', 'max'), byOrder['battle'], KINGDOM);
  /* battle draws parchment (opaque, normal) UNDER arcane/wash.jpg (opacity 0.8,
     soft-light). Exactly one of the two is a ground. */
  expect(await markGround(page)).toBe(1);
  const marked = await page.$$eval('[data-sweep-ground]', els =>
    els.map(e => (e as HTMLImageElement).src.split('/').slice(-1)[0].split('?')[0]));
  expect(marked).toEqual(['parchment.jpg']);
});

test('owl\'s screen-blended border is an overlay, not a ground', async ({ page }) => {
  const { byOrder } = await loadCatalogue(page, KINGDOM);
  const c = { ...pick('owl', 'max'), variant: 'cyanotype' };
  await openPrint(page, c, byOrder['owl'], KINGDOM);
  await markGround(page);
  const marked = await page.$$eval('[data-sweep-ground]', els =>
    els.map(e => (e as HTMLImageElement).src.split('/').slice(-1)[0].split('?')[0]));
  /* owl/border is a full-frame OPAQUE image — a size-and-alpha test calls it ground
     and deletes a real plate from layer A. mix-blend-mode: screen is what tells them
     apart. */
  expect(marked.some(m => m.startsWith('cyanotype'))).toBe(true);
  expect(marked.some(m => m.startsWith('border')), 'owl/border was classified as ground').toBe(false);
});

test('glyph ink is found on a DARK ground where a white base would find none', async ({ page }) => {
  const { byOrder } = await loadCatalogue(page, KINGDOM);
  const c = { ...pick('owl', 'max'), variant: 'cyanotype' };
  await openPrint(page, c, byOrder['owl'], KINGDOM);
  const colour = await page.locator('.Award').first().evaluate(el => getComputedStyle(el).color);
  expect(colour, 'this test is pointless unless the type really is white').toBe('rgb(255, 255, 255)');

  const shots = await captureLayers(page);
  const C = diffMask(shots.C, shots.G);
  const A = diffMask(shots.A, shots.G);
  expect(count(C), 'no glyph ink found on a cyanotype certificate').toBeGreaterThan(5000);
  expect(count(A), 'no overlay artwork found on a cyanotype certificate').toBeGreaterThan(5000);
});

test('layer C is glyph ink and layer A is overlay artwork, on parchment', async ({ page }) => {
  const { byOrder } = await loadCatalogue(page, KINGDOM);
  await openPrint(page, pick('crown', 'max'), byOrder['crown'], KINGDOM);
  const shots = await captureLayers(page);
  expect(count(diffMask(shots.C, shots.G)), 'layer C found no glyph ink').toBeGreaterThan(5000);
  expect(count(diffMask(shots.A, shots.G)), 'layer A found no plate ink').toBeGreaterThan(5000);
  /* The ground differenced against itself must be empty, or every mask is offset by
     whatever the difference is. */
  expect(count(diffMask(shots.G, shots.G))).toBe(0);
});

test('the type band is inside the frame and not the whole page', async ({ page }) => {
  const { byOrder } = await loadCatalogue(page, KINGDOM);
  await openPrint(page, pick('crown', 'max'), byOrder['crown'], KINGDOM);
  const band = await typeBand(page);
  const frame = await page.locator('svg[data-scroll-frame]').first().evaluate(el => {
    const r = el.getBoundingClientRect();
    return { x: r.x, y: r.y, w: r.width, h: r.height };
  });
  expect(band.width).toBeGreaterThan(100);
  expect(band.width).toBeLessThanOrEqual(Math.ceil(frame.w));
  expect(band.x).toBeGreaterThanOrEqual(Math.floor(frame.x));
});

test('a full-page wash is excluded, and a screen-blended border is not', async ({ page }) => {
  const { byOrder } = await loadCatalogue(page, KINGDOM);

  /* smith/burn.webp is a full-frame OPAQUE townscape at mix-blend-mode: soft-light. It
     tints the whole page — a ground in everything but the compositing operator — and
     before the wash test smith measured 179 of 179 cases dirty with an 848px "worst
     run", describing the artwork the certificate is made of. */
  await openPrint(page, pick('smith', 'max'), byOrder['smith'], KINGDOM);
  const smith = await captureLayers(page);
  const smithA = diffMask(smith.A, smith.G);
  const smithBand = smithA.w * smithA.h;
  expect(count(smithA) / smithBand,
    'smith still reports its full-page wash as overlay artwork').toBeLessThan(0.5);

  /* owl/border is ALSO full-frame and opaque and non-normal-blend — `screen` — but it
     paints knotwork, not a tint, and the type really can collide with it. It must
     survive the same test that removes smith's wash, or the test is just "ignore
     anything big". */
  const owl = { ...pick('owl', 'max'), variant: 'cyanotype' };
  await openPrint(page, owl, byOrder['owl'], KINGDOM);
  const owlShots = await captureLayers(page);
  const owlA = diffMask(owlShots.A, owlShots.G);
  expect(count(owlA), 'owl lost its border to the wash test').toBeGreaterThan(5000);
  expect(count(owlA) / (owlA.w * owlA.h),
    'owl/border should be localised knotwork, not a full-band tint').toBeLessThan(0.9);
});
