import { test, expect } from '@playwright/test';
import { PNG } from 'pngjs';
import { selectCases, diffPng, metricDeltas, parseTag } from '../lib/combo-rerun';

const cases = [{ id: 'c001-a' }, { id: 'c002-b' }, { id: 'c003-c' }] as any[];

function img(w: number, h: number, paint?: (x: number, y: number) => boolean): Buffer {
  const p = new PNG({ width: w, height: h });
  for (let y = 0; y < h; y++) for (let x = 0; x < w; x++) {
    const i = (y * w + x) << 2;
    const v = paint?.(x, y) ? 0 : 255;
    p.data[i] = p.data[i + 1] = p.data[i + 2] = v; p.data[i + 3] = 255;
  }
  return PNG.sync.write(p);
}

test('combo: an id list selects exactly those cases, in matrix order', () => {
  expect(selectCases(cases, 'c003-c,c001-a').map(c => c.id)).toEqual(['c001-a', 'c003-c']);
  expect(selectCases(cases, ' c002-b ,').map(c => c.id)).toEqual(['c002-b']);
});

test('combo: an unknown id fails loudly, naming it; substrings do not match', () => {
  expect(() => selectCases(cases, 'c001-a,c999-zzz')).toThrow(/c999-zzz/);
  expect(() => selectCases(cases, 'c001')).toThrow(/c001/);
  expect(() => selectCases(cases, '')).toThrow(/no case ids/i);
});

test('combo: the output tag is a plain name', () => {
  expect(parseTag('baseline')).toBe('baseline');
  expect(() => parseTag('../combo150')).toThrow();
  expect(() => parseTag('')).toThrow();
});

test('combo: identical images are unchanged', () => {
  const a = img(40, 30, (x, y) => x > 5 && y > 5 && x < 20);
  const d = diffPng(a, Buffer.from(a));
  expect(d.changed).toBe(false);
  expect(d.fraction).toBe(0);
  expect(d.bbox).toBeNull();
  expect(d.regions).toEqual([]);
});

test('combo: a changed region reports fraction, bbox and a zoom region', () => {
  const a = img(100, 100);
  const b = img(100, 100, (x, y) => x >= 10 && x < 20 && y >= 30 && y < 50);
  const d = diffPng(a, b);
  expect(d.changed).toBe(true);
  expect(d.fraction).toBeCloseTo(200 / 10000, 6);
  expect(d.bbox).toEqual({ x: 10, y: 30, w: 10, h: 20 });
  expect(d.regions.length).toBe(1);
  expect(d.regions[0].x).toBeLessThanOrEqual(10);
});

test('combo: differing sizes are changed with a full-frame bbox', () => {
  const d = diffPng(img(10, 10), img(12, 10));
  expect(d.changed).toBe(true);
  expect(d.sizeMismatch).toBe(true);
});

test('combo: metric deltas list only what moved', () => {
  const before: any = { flags: ['ink:text-over-art'], info: [], ink: { px: { CA: 400, CB: 0, CC: 0 } }, knotGap: { gap: 10 }, safeMargin: 5, dom: { comp: 'classic', budget: 'ok', roles: { name: { overflow: true } }, boxOverflow: [] } };
  const after: any = { flags: [], info: [], ink: { px: { CA: 100, CB: 0, CC: 0 } }, knotGap: { gap: 30 }, safeMargin: 5, dom: { comp: 'classic', budget: 'ok', roles: { name: {} }, boxOverflow: [] } };
  const m = metricDeltas(before, after);
  expect(m.find(x => x.name === 'ink CA')).toMatchObject({ before: 400, after: 100, delta: -300 });
  expect(m.find(x => x.name === 'knot gap')).toMatchObject({ delta: 20 });
  expect(m.find(x => x.name === 'flags')).toMatchObject({ before: 'ink:text-over-art', after: '(none)' });
  expect(m.find(x => x.name === 'engine overflow')).toMatchObject({ before: 'name', after: '(none)' });
  expect(m.find(x => x.name === 'ink CB')).toBeUndefined();
  expect(metricDeltas(before, before)).toEqual([]);
});
