import { PNG } from 'pngjs';

/**
 * Pure helpers for the targeted re-render of the 150-combination sweep (combo150.spec.ts and
 * combo150-report.spec.ts in rerun mode): pick cases from the SAVED matrix by exact id, and
 * compare a re-render with the original image and metrics. No browser, no filesystem.
 */

/** The cases named by a comma-separated id list, in matrix order. Unknown ids throw. */
export function selectCases<T extends { id: string }>(cases: T[], idsCsv: string): T[] {
  const ids = idsCsv.split(',').map(s => s.trim()).filter(Boolean);
  if (!ids.length) throw new Error('ORK3_COMBO_IDS holds no case ids');
  const have = new Set(cases.map(c => c.id));
  const unknown = ids.filter(id => !have.has(id));
  if (unknown.length) throw new Error(`unknown case id(s) in ORK3_COMBO_IDS (not in matrix.json): ${unknown.join(', ')}`);
  const want = new Set(ids);
  return cases.filter(c => want.has(c.id));
}

/** The output tag names a folder under out/combo150-rerun/, so it is a plain name. */
export function parseTag(tag: string | undefined): string {
  const t = (tag ?? '').trim();
  if (!/^[A-Za-z0-9][A-Za-z0-9._-]*$/.test(t) || t.includes('..')) throw new Error(`ORK3_COMBO_TAG must be a plain name ([A-Za-z0-9._-]), got "${tag ?? ''}"`);
  return t;
}

export type Box = { x: number; y: number; w: number; h: number };
export type PixelDiff = {
  changed: boolean;
  /** Differing pixels / total pixels (of the larger frame when sizes differ). */
  fraction: number;
  pixels: number;
  bbox: Box | null;
  /** Up to 3 padded regions worth a zoomed crop, largest change first. */
  regions: Box[];
  sizeMismatch: boolean;
  width: number;
  height: number;
};

const TILE = 100;

/** Pixel-compare two PNGs (any difference in any channel counts). */
export function diffPng(beforePng: Buffer, afterPng: Buffer, maxRegions = 3, pad = 40): PixelDiff {
  const a = PNG.sync.read(beforePng), b = PNG.sync.read(afterPng);
  if (a.width !== b.width || a.height !== b.height) {
    const w = Math.max(a.width, b.width), h = Math.max(a.height, b.height);
    return { changed: true, fraction: 1, pixels: w * h, bbox: { x: 0, y: 0, w, h }, regions: [{ x: 0, y: 0, w, h }], sizeMismatch: true, width: w, height: h };
  }
  const W = a.width, H = a.height;
  let pixels = 0, x0 = W, y0 = H, x1 = -1, y1 = -1;
  const tw = Math.ceil(W / TILE), th = Math.ceil(H / TILE);
  const tiles = new Int32Array(tw * th);
  for (let y = 0; y < H; y++) {
    for (let x = 0; x < W; x++) {
      const i = (y * W + x) << 2;
      if (a.data[i] !== b.data[i] || a.data[i + 1] !== b.data[i + 1] || a.data[i + 2] !== b.data[i + 2] || a.data[i + 3] !== b.data[i + 3]) {
        pixels++;
        if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y;
        tiles[Math.floor(y / TILE) * tw + Math.floor(x / TILE)]++;
      }
    }
  }
  if (!pixels) return { changed: false, fraction: 0, pixels: 0, bbox: null, regions: [], sizeMismatch: false, width: W, height: H };

  /* Group changed tiles (8-connected, bridging one empty tile) into regions. */
  const seen = new Uint8Array(tw * th);
  const groups: { box: Box; count: number }[] = [];
  for (let t = 0; t < tiles.length; t++) {
    if (!tiles[t] || seen[t]) continue;
    const stack = [t]; seen[t] = 1;
    let gx0 = tw, gy0 = th, gx1 = -1, gy1 = -1, count = 0;
    while (stack.length) {
      const c = stack.pop()!;
      const cx = c % tw, cy = Math.floor(c / tw);
      count += tiles[c];
      gx0 = Math.min(gx0, cx); gx1 = Math.max(gx1, cx); gy0 = Math.min(gy0, cy); gy1 = Math.max(gy1, cy);
      for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) {
        const nx = cx + dx, ny = cy + dy;
        if (nx < 0 || ny < 0 || nx >= tw || ny >= th) continue;
        const n = ny * tw + nx;
        if (tiles[n] && !seen[n]) { seen[n] = 1; stack.push(n); }
      }
    }
    groups.push({ box: { x: gx0 * TILE, y: gy0 * TILE, w: (gx1 - gx0 + 1) * TILE, h: (gy1 - gy0 + 1) * TILE }, count });
  }
  const regions = groups.sort((p, q) => q.count - p.count).slice(0, maxRegions).map(g => {
    const x = Math.max(0, g.box.x - pad), y = Math.max(0, g.box.y - pad);
    return { x, y, w: Math.min(W, g.box.x + g.box.w + pad) - x, h: Math.min(H, g.box.y + g.box.h + pad) - y };
  });
  return { changed: true, fraction: pixels / (W * H), pixels, bbox: { x: x0, y: y0, w: x1 - x0 + 1, h: y1 - y0 + 1 }, regions, sizeMismatch: false, width: W, height: H };
}

export type Delta = { name: string; before: number | string; after: number | string; delta?: number };

const list = (xs: string[]) => (xs.length ? xs.join(', ') : '(none)');

/** Graded results rows (results.json entries) compared; only metrics that moved are returned. */
export function metricDeltas(before: any, after: any): Delta[] {
  const out: Delta[] = [];
  const num = (name: string, b: unknown, a: unknown) => {
    const bv = typeof b === 'number' ? b : null, av = typeof a === 'number' ? a : null;
    if (bv === av) return;
    out.push({ name, before: bv ?? '-', after: av ?? '-', ...(bv !== null && av !== null ? { delta: Math.round((av - bv) * 100) / 100 } : {}) });
  };
  const str = (name: string, b: string, a: string) => { if (b !== a) out.push({ name, before: b, after: a }); };
  for (const k of ['CA', 'CB', 'CC', 'CAtop', 'CBtop']) num(`ink ${k}`, before?.ink?.px?.[k], after?.ink?.px?.[k]);
  num('knot gap', before?.knotGap?.gap, after?.knotGap?.gap);
  num('safe margin', before?.safeMargin, after?.safeMargin);
  str('flags', list(before?.flags ?? []), list(after?.flags ?? []));
  str('info', list(before?.info ?? []), list(after?.info ?? []));
  const ov = (r: any) => list(Object.entries(r?.dom?.roles ?? {}).filter(([, v]: [string, any]) => v?.overflow).map(([k]) => k));
  str('engine overflow', ov(before), ov(after));
  const bo = (r: any) => list((r?.dom?.boxOverflow ?? []).map((x: any) => `${x.block}:${x.axis}`));
  str('box overflow', bo(before), bo(after));
  str('budget', String(before?.dom?.budget ?? '-'), String(after?.dom?.budget ?? '-'));
  str('composition', String(before?.dom?.comp ?? '-'), String(after?.dom?.comp ?? '-'));
  str('render error', String(before?.error ?? '(none)'), String(after?.error ?? '(none)'));
  return out;
}
