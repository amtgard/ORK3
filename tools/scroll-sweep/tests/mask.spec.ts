import { test, expect } from '@playwright/test';
import { PNG } from 'pngjs';
import { inkMask, diffMask, erode1, intersect, count, largestRun, severity } from '../lib/mask';

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

test('diffMask finds white ink on a dark ground, which inkMask cannot', () => {
  // The cyanotype case: white type on a dark blueprint plate.
  const dark  = synth(20, 20, () => [20, 30, 60]);
  const white = synth(20, 20, (x, y) => (y >= 8 && y <= 12 ? [255, 255, 255] : [20, 30, 60]));
  /* inkMask gets this exactly INVERTED: it finds the 15 rows of dark GROUND and
     scores the 5 rows of white TYPE at zero. Not merely blind — it would report the
     ground as the ink and the type as blank paper. */
  const viaInk = inkMask(white);
  expect(count(viaInk)).toBe(20 * 15);
  expect(viaInk.bits[10 * 20 + 10], 'inkMask sees no type where the type is').toBe(0);
  const viaDiff = diffMask(white, dark);
  expect(count(viaDiff)).toBe(20 * 5);            // exactly the five white rows
  expect(viaDiff.ink[10 * 20 + 10]).toBeGreaterThan(0.8);
});

test('diffMask scores a pale wash weakly and dark knotwork strongly', () => {
  const parchment = synth(20, 20, () => [222, 205, 170]);
  const wash  = synth(20, 20, (x, y) => (y === 10 ? [205, 190, 158] : [222, 205, 170]));
  const knot  = synth(20, 20, (x, y) => (y === 10 ? [20, 18, 15] : [222, 205, 170]));
  const w = diffMask(wash, parchment);
  const k = diffMask(knot, parchment);
  expect(count(k)).toBeGreaterThan(0);
  expect(k.ink[10 * 20 + 10]).toBeGreaterThan(w.ink[10 * 20 + 10] * 5);
});

test('diffMask sees nothing where a layer changed nothing', () => {
  const g = synth(20, 20, () => [222, 205, 170]);
  expect(count(diffMask(g, g))).toBe(0);
});
