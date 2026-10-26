import { test, expect } from '@playwright/test';
import { PNG } from 'pngjs';
import { GRID, THRESHOLDS, bandEdges, obstacleMask, type Thresholds } from '../lib/profile';

/*
 * The free-space builder's arithmetic, on synthetic rasters. Nothing here opens a browser:
 * the measurement (what the page paints) is profile.spec.ts's, and this file is what turns
 * two pictures into two numbers per band, which is the part a wrong off-by-one or a wrong
 * sign would corrupt without any render ever looking odd.
 */

type Paint = (x: number, y: number) => [number, number, number] | null;

/** A w x h opaque PNG, `base` everywhere `paint` returns null. */
function synth(w: number, h: number, base: [number, number, number], paint: Paint = () => null): Buffer {
  const png = new PNG({ width: w, height: h });
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      const i = (y * w + x) << 2;
      const c = paint(x, y) ?? base;
      png.data[i] = c[0]; png.data[i + 1] = c[1]; png.data[i + 2] = c[2]; png.data[i + 3] = 255;
    }
  }
  return PNG.sync.write(png);
}

const PAPER: [number, number, number] = [235, 220, 190];
const BLACK: [number, number, number] = [0, 0, 0];

/** A mask of the whole band raster, with `fill` setting the bits it wants. */
function maskOf(fill: (x: number, y: number) => boolean): Uint8Array {
  const m = new Uint8Array(GRID.frameW * GRID.bands * GRID.step);
  for (let y = 0; y < GRID.bands * GRID.step; y++) {
    for (let x = 0; x < GRID.frameW; x++) if (fill(x, y)) m[y * GRID.frameW + x] = 1;
  }
  return m;
}
const H = GRID.bands * GRID.step;
const edges = (m: Uint8Array) => bandEdges(m, GRID.frameW, H, THRESHOLDS);

/* ---- the grid is the spec's ---------------------------------------------------------- */

test('the grid and thresholds are spec 7.1 verbatim', () => {
  expect(GRID).toMatchObject({ v: 1, y0: 400, step: 25, bands: 80, frameW: 2550 });
  expect(THRESHOLDS).toMatchObject({
    clearance: 30, centerExclusion: 250, inkThreshold: 0.2, groundDark: 0.3, groundLight: 0.55,
  });
  expect(THRESHOLDS.groundBlur).toBeGreaterThanOrEqual(2);
});

/* ---- bandEdges ----------------------------------------------------------------------- */

test('an empty raster is the whole frame in every band', () => {
  const e = edges(maskOf(() => false));
  expect(e.L).toHaveLength(80);
  expect(e.R).toHaveLength(80);
  expect(e.L.every(v => v === 0)).toBe(true);
  expect(e.R.every(v => v === 2550)).toBe(true);
  expect(e.centre.every(c => c.count === 0)).toBe(true);
});

test('a left obstacle puts L past its right edge plus the clearance', () => {
  // Pixels 100..199 inclusive: the ink ends at x=200, so the free interval starts at 230.
  const e = edges(maskOf((x, y) => x >= 100 && x < 200 && y >= 3 * 25 + 5 && y < 3 * 25 + 10));
  expect(e.L[3]).toBe(200 + 30);
  expect(e.R[3]).toBe(2550);
});

test('the widest left obstacle in a band is the one that counts', () => {
  const e = edges(maskOf((x, y) => y >= 50 && y < 75 && ((x >= 40 && x < 60) || (x >= 400 && x < 420) || (x >= 100 && x < 110))));
  expect(e.L[2]).toBe(420 + 30);
});

test('a right obstacle puts R at its left edge minus the clearance, and the nearest one counts', () => {
  const e = edges(maskOf((x, y) => y >= 0 && y < 25 && ((x >= 2300 && x < 2400) || (x >= 1900 && x < 1950))));
  expect(e.R[0]).toBe(1900 - 30);
  expect(e.L[0]).toBe(0);
});

test('vertical clearance: a band takes the worst of itself and the two bands beside it', () => {
  // The obstacle is in band 10 only. Bands 9 and 11 see it as their neighbour; 8 and 12 do not.
  const e = edges(maskOf((x, y) => x >= 100 && x < 200 && y >= 250 && y < 275));
  expect(e.L.slice(8, 13)).toEqual([0, 230, 230, 230, 0]);
});

test('vertical clearance works for the right edge and at the first and last band', () => {
  const e = edges(maskOf((x, y) => (x >= 2400 && x < 2500 && y < 25) || (x >= 2200 && x < 2300 && y >= 79 * 25)));
  expect(e.R[0]).toBe(2400 - 30);
  expect(e.R[1]).toBe(2400 - 30);
  expect(e.R[2]).toBe(2550);
  expect(e.R[78]).toBe(2200 - 30);
  expect(e.R[79]).toBe(2200 - 30);
  expect(e.R[77]).toBe(2550);
});

test('ink inside the centre exclusion is counted, not profiled', () => {
  // cx is 1275 and the exclusion 250, so 1025..1524 is the centre.
  const e = edges(maskOf((x, y) => x >= 1100 && x < 1450 && y >= 125 && y < 150));
  expect(e.L[5]).toBe(0);
  expect(e.R[5]).toBe(2550);
  expect(e.centre[5].count).toBe(350 * 25);
  expect(e.centre[5].x0).toBe(1100);
  expect(e.centre[5].x1).toBe(1449);
  expect(e.centre[4].count).toBe(0);
});

test('the centre exclusion is exactly cx +/- 250: pixel 1024 profiles, 1025 does not', () => {
  const left = edges(maskOf((x, y) => x === 1024 && y < 25));
  expect(left.L[0]).toBe(1025 + 30);
  const inside = edges(maskOf((x, y) => x === 1025 && y < 25));
  expect(inside.L[0]).toBe(0);
  expect(inside.centre[0].count).toBe(25);

  const rightInside = edges(maskOf((x, y) => x === 1524 && y < 25));
  expect(rightInside.R[0]).toBe(2550);
  const right = edges(maskOf((x, y) => x === 1525 && y < 25));
  expect(right.R[0]).toBe(1525 - 30);
});

test('an obstacle that crosses into the centre profiles by the part outside it', () => {
  // A figure from 900 to 1300 blocks the left of the page up to the centre edge.
  const e = edges(maskOf((x, y) => x >= 900 && x < 1300 && y < 25));
  expect(e.L[0]).toBe(1025 + 30);
  expect(e.centre[0].count).toBeGreaterThan(0);
});

test('the free interval always has positive width, whatever the obstacles', () => {
  const e = edges(maskOf((x, y) => y < 25 && (x === 1024 || x === 1525)));
  expect(e.L[0]).toBeLessThan(e.R[0]);
  expect(e.R[0] - e.L[0]).toBe(1495 - 1055);
});

test('the raster size must agree with the grid', () => {
  expect(() => bandEdges(new Uint8Array(10), 2550, 4, THRESHOLDS)).toThrow(/mask/i);
});

/* ---- obstacleMask -------------------------------------------------------------------- */

const W = 200;
const Hh = 60;
/* The ground tests read the ground through a box mean (groundBlur), which moves every edge by a few
   pixels. The tests that count pixels exactly turn it off; the ones about the blur say so. */
const opts = (over: Partial<{ lightInk: boolean; ownBorder: boolean; t: Partial<Thresholds> }> = {}) =>
  ({ lightInk: false, ownBorder: false, ...over, t: { groundBlur: 0, ...over.t } });
const at = (m: { mask: Uint8Array }, x: number, y: number) => m.mask[y * W + x];

test('art that darkens the ground enough is an obstacle; a faint wash is not', () => {
  const G = synth(W, Hh, PAPER);
  const A = synth(W, Hh, PAPER, (x) => (x < 50 ? BLACK : x < 100 ? [PAPER[0] - 20, PAPER[1] - 20, PAPER[2] - 20] : null));
  const m = obstacleMask(A, G, opts());
  expect(at(m, 10, 10)).toBe(1);
  expect(at(m, 75, 10)).toBe(0);
  expect(at(m, 150, 10)).toBe(0);
});

test('art that LIGHTENS the ground is an obstacle too (the cyanotype case)', () => {
  const DARK: [number, number, number] = [30, 50, 90];
  const G = synth(W, Hh, DARK);
  const A = synth(W, Hh, DARK, (x) => (x < 50 ? [250, 250, 250] : null));
  const m = obstacleMask(A, G, opts({ lightInk: true }));
  expect(at(m, 10, 10)).toBe(1);
  expect(at(m, 150, 10)).toBe(0);
});

test('with dark ink, a dark ground pixel is an obstacle even with no art on it', () => {
  const G = synth(W, Hh, PAPER, (x) => (x < 40 ? [40, 40, 60] : null));
  const m = obstacleMask(G, G, opts());
  expect(at(m, 10, 10)).toBe(1);
  expect(at(m, 100, 10)).toBe(0);
  expect(m.by.contrast).toBe(40 * Hh);
});

test('with dark ink, a ground pixel just lighter than groundDark is clear', () => {
  // Rec.709 luma of a grey g is g/255, so 0.30 is 76.5.
  const G = synth(W, Hh, PAPER, (x) => (x < 20 ? [70, 70, 70] : x < 40 ? [90, 90, 90] : null));
  const m = obstacleMask(G, G, opts());
  expect(at(m, 10, 10)).toBe(1);
  expect(at(m, 30, 10)).toBe(0);
});

test('with light ink, a bright ground pixel is an obstacle and a dark one is not', () => {
  const G = synth(W, Hh, [30, 50, 90], (x) => (x < 20 ? [190, 190, 190] : x < 40 ? [120, 120, 120] : null));
  const m = obstacleMask(G, G, opts({ lightInk: true }));
  expect(at(m, 10, 10)).toBe(1);   // 0.745 > 0.55
  expect(at(m, 30, 10)).toBe(0);   // 0.47 < 0.55
  expect(at(m, 100, 10)).toBe(0);  // the dark field itself, which light type is made for
});

test('a dark ground is not an obstacle to light type, and a light one is not to dark type', () => {
  const dark = synth(W, Hh, [20, 20, 20]);
  expect(obstacleMask(dark, dark, opts({ lightInk: true })).by.contrast).toBe(0);
  const light = synth(W, Hh, [240, 240, 240]);
  expect(obstacleMask(light, light, opts({ lightInk: false })).by.contrast).toBe(0);
});

test('a ground that carries its own border: pixels far from the paper colour are obstacles', () => {
  // The middle 40% (x 60..139) is paper, and that is what the median is taken over.
  const FRAME: [number, number, number] = [60, 40, 60];
  const G = synth(W, Hh, PAPER, (x) => (x < 30 || x >= 170 ? FRAME : null));
  const m = obstacleMask(G, G, opts({ ownBorder: true, t: { groundDark: 0.01 } }));
  expect(at(m, 10, 10)).toBe(1);
  expect(at(m, 180, 10)).toBe(1);
  expect(at(m, 100, 10)).toBe(0);
  expect(m.by.own).toBe(60 * Hh);
});

test('own-border: mottling under the calibrated distance is not an obstacle', () => {
  const near: [number, number, number] = [PAPER[0] - 8, PAPER[1] - 8, PAPER[2] - 8];
  const G = synth(W, Hh, PAPER, (x, y) => ((x + y) % 2 ? near : null));
  const m = obstacleMask(G, G, opts({ ownBorder: true }));
  expect(m.by.own).toBe(0);
});

test('own-border is off unless asked for: the same colours on a plain ground are not artwork', () => {
  const G = synth(W, Hh, PAPER, (x) => (x < 30 ? [60, 40, 60] : null));
  const m = obstacleMask(G, G, opts({ ownBorder: false, t: { groundDark: 0.01 } }));
  expect(m.by.own).toBe(0);
  expect(at(m, 10, 10)).toBe(0);
});

test('the three sources are or-ed, and each is counted once where they overlap', () => {
  const G = synth(W, Hh, PAPER, (x) => (x < 40 ? [40, 40, 60] : null));
  const A = synth(W, Hh, PAPER, (x) => (x < 40 ? BLACK : x >= 150 ? BLACK : null));
  const m = obstacleMask(A, G, opts({ ownBorder: true }));
  expect(at(m, 10, 10)).toBe(1);
  expect(at(m, 170, 10)).toBe(1);
  expect(at(m, 100, 10)).toBe(0);
  expect(m.by.total).toBe((40 + 50) * Hh);
});

test('a raster of the wrong size is refused, not misread', () => {
  expect(() => obstacleMask(synth(10, 10, PAPER), synth(12, 10, PAPER), opts())).toThrow(/size/i);
});

/* ---- the ground is read through a box mean ------------------------------------------- */

const blurred = (over: Partial<{ lightInk: boolean; ownBorder: boolean; t: Partial<Thresholds> }> = {}) =>
  ({ lightInk: false, ownBorder: false, ...over, t: { groundBlur: THRESHOLDS.groundBlur, ...over.t } });

test('a one- or two-pixel fleck in the ground is texture, not an obstacle', () => {
  // parchment.jpg: 57 flecks darker than groundDark, the widest two pixels.
  const G = synth(W, Hh, PAPER, (x, y) => ((x === 50 && y === 20) || ((x === 100 || x === 101) && y === 30) ? [10, 5, 0] : null));
  expect(obstacleMask(G, G, opts({ t: { groundBlur: 0 } })).by.contrast).toBe(3);   // unblurred, they ARE flagged
  expect(obstacleMask(G, G, blurred()).by.contrast).toBe(0);
});

test('a dark REGION of ground is still an obstacle once blurred, to within the blur radius', () => {
  const G = synth(W, Hh, PAPER, (x) => (x < 40 ? [40, 40, 60] : null));
  const m = obstacleMask(G, G, blurred());
  expect(at(m, 10, 30)).toBe(1);
  expect(at(m, 60, 30)).toBe(0);
  // The edge is where the box mean crosses groundDark: within the radius of the true edge at x=40.
  let edge = 0;
  for (let x = 0; x < W; x++) if (at(m, x, 30)) edge = x;
  expect(Math.abs(edge - 39)).toBeLessThanOrEqual(THRESHOLDS.groundBlur);
});

test('own-border: a woven ground (two colours far apart, thread by thread) is not a border once blurred', () => {
  const A: [number, number, number] = [150, 150, 150];
  const B: [number, number, number] = [250, 250, 250];
  const G = synth(W, Hh, A, (x, y) => ((x + y) % 2 ? B : null));
  expect(obstacleMask(G, G, opts({ ownBorder: true, t: { groundDark: 0.01, ownBorderDistance: 40 } })).by.own).toBeGreaterThan(0);
  expect(obstacleMask(G, G, blurred({ ownBorder: true, t: { groundDark: 0.01, ownBorderDistance: 40 } })).by.own).toBe(0);
});

test('plates are not blurred: a one-pixel line of artwork is an obstacle', () => {
  const G = synth(W, Hh, PAPER);
  const A = synth(W, Hh, PAPER, (x) => (x === 50 ? BLACK : null));
  const m = obstacleMask(A, G, blurred());
  expect(at(m, 50, 10)).toBe(1);
  expect(m.by.art).toBe(Hh);
});

test('the blur reaches the picture edge without reading past it', () => {
  const G = synth(W, Hh, PAPER);
  expect(obstacleMask(G, G, blurred({ ownBorder: true })).by.total).toBe(0);
});
