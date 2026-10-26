import { PNG } from 'pngjs';
import { diffMask } from './mask';

/*
 * FREE-SPACE PROFILES: THE ARITHMETIC.
 *
 * What the page paints is profile.spec.ts's to measure. This file is the part that turns two
 * rasters of the same certificate into two numbers per 25px band (spec 7.1), kept apart from
 * the browser so tests/profile.spec.ts can pin it on synthetic pictures. Nothing here touches
 * a page.
 *
 * THE QUESTION is "how far from the middle can a line of type run before it meets something".
 * The engine asks it per band because artwork is not a rectangle: archers stand beside the
 * name and not above it, so a single column width would either print over them or give the
 * name a third of the room it has.
 */

/** The grid, spec 7.1 verbatim. `frameW` is the foreignObject's own width, the full-frame answer. */
export const GRID = { v: 1, y0: 400, step: 25, bands: 80, frameW: 2550 } as const;

export type Thresholds = {
  /** Frame px kept clear between type and the artwork, applied to the edge of the nearest ink. */
  clearance: number;
  /** Ink within this many px of the page centre line is the heading's own business, not a wall. */
  centerExclusion: number;
  /** Luminance difference (Rec.709 luma, 0-1, as lib/mask.ts diffMask) at which a plate is artwork. */
  inkThreshold: number;
  /** With dark type, a ground pixel darker than this (luma 0-1) is an obstacle: nothing reads there. */
  groundDark: number;
  /** With light type (cyanotype), a ground pixel lighter than this is. */
  groundLight: number;
  /**
   * Own-border grounds only: how far (Euclidean RGB, 0-441) a pixel may sit from the ground's median
   * colour before it is the frame and not the paper. Calibrated against the three known cases in
   * profile.spec.ts and recorded in the manifest header.
   */
  ownBorderDistance: number;
  /**
   * Radius, in px, of the box mean the ground is read through before the two ground tests. A
   * ground is photographed paper, and paper is speckled: parchment.jpg has 57 flecks darker than
   * `groundDark`, none wider than two pixels, and linen's weave swings 200 RGB units between one
   * thread and the next. Type is not stopped by a fleck or a thread; it is stopped by a region.
   * Plates are NOT blurred: a hairline of linework in a border is artwork.
   */
  groundBlur: number;
};

/*
 * The first five are spec 7.1's, verbatim. The last two are the builder's, because the spec leaves
 * them to it, and both are MEASURED rather than chosen.
 *
 * ownBorderDistance 200. Taken over the five grounds that carry their own frame (linen, jovious,
 * flame, sunburst, map), read through the 9x9 box mean below, against each one's median colour:
 *
 *     the ground's own paper (central 40%)    linen 75   jovious 76   map 153   flame 186
 *     the frame, 90th percentile of its strip linen 233  jovious 270  map 303   flame 260
 *
 * (Euclidean RGB distance, 0-441. Flame's 186 is its painted fire, which the type is designed to sit
 * on; sunburst's central maximum of 243 is its phoenix, pale and in the centre zone.) 200 is the
 * smallest round number above every ground's own texture, and below where each frame sits. At the
 * 40 the first draft used, the weave of the linen and the stains of the flame were all "frame" and
 * the profile closed to the centre exclusion on every one of them.
 *
 * groundBlur 4: the radius that removes parchment.jpg's 57 flecks (none wider than two pixels)
 * and linen's weave and keeps every region a person would call a frame or a figure.
 */
export const THRESHOLDS: Thresholds = {
  clearance: 30,
  centerExclusion: 250,
  inkThreshold: 0.2,
  groundDark: 0.3,
  groundLight: 0.55,
  ownBorderDistance: 200,
  groundBlur: 4,
};

export type ObstacleMask = {
  w: number;
  h: number;
  /** 1 where something is in the way, w*h long. */
  mask: Uint8Array;
  /** Pixels each source flagged (a pixel two sources flag counts in both) and the union. */
  by: { art: number; contrast: number; own: number; total: number };
};

/**
 * Every pixel type must stay off, from three sources (spec 7.1 steps 2-4).
 *
 *   art       the plates: a pixel layer A (ground + plates, underlays hidden) differs from layer G
 *             (the ground alone) by at least `inkThreshold`. Colour-blind and blend-blind, as the
 *             sweep's own diffMask is: it measures what the plate changes, not what colour it is.
 *   contrast  the ground itself, where type could not be read: with dark type, a pixel darker than
 *             `groundDark`; with light type, one lighter than `groundLight`. Zodiac's dark sky
 *             outside its paper is the case this exists for.
 *   own       a ground with its frame painted in (linen, flame, jovious, ...): a pixel whose
 *             colour is further than `ownBorderDistance` from the ground's median colour, taken
 *             over the central 40% of the width, which is paper on every one of them.
 *
 * `a` and `g` are PNG buffers of the same clip. `ownBorder` is only meaningful for a ground that
 * has one: on a plain ground the same arithmetic would call parchment mottling artwork.
 */
export function obstacleMask(
  a: Buffer,
  g: Buffer,
  o: { lightInk: boolean; ownBorder: boolean; t?: Partial<Thresholds> },
): ObstacleMask {
  const t = { ...THRESHOLDS, ...o.t };
  const art = diffMask(a, g, t.inkThreshold);
  const G = PNG.sync.read(g);
  const { width: w, height: h } = G;
  const n = w * h;
  const mask = new Uint8Array(n);
  const by = { art: 0, contrast: 0, own: 0, total: 0 };

  // The ground as the two ground tests see it: a box mean, three planes (R, G, B).
  const [pr, pg, pb] = boxMean(G.data, w, h, t.groundBlur);

  let med: [number, number, number] | null = null;
  if (o.ownBorder) {
    // Per-channel median of the blurred ground over the central 40% of the columns, every row.
    // A histogram per channel is exact and cheap; sorting a million values per channel is neither.
    const x0 = Math.round(w * 0.3);
    const x1 = Math.round(w * 0.7);
    const hist = [new Uint32Array(256), new Uint32Array(256), new Uint32Array(256)];
    for (let y = 0; y < h; y++) {
      for (let x = x0; x < x1; x++) {
        const p = y * w + x;
        hist[0][Math.round(pr[p])]++; hist[1][Math.round(pg[p])]++; hist[2][Math.round(pb[p])]++;
      }
    }
    const half = ((x1 - x0) * h) / 2;
    const median = (hh: Uint32Array) => {
      let acc = 0;
      for (let v = 0; v < 256; v++) { acc += hh[v]; if (acc >= half) return v; }
      return 255;
    };
    med = [median(hist[0]), median(hist[1]), median(hist[2])];
  }
  const d2 = t.ownBorderDistance * t.ownBorderDistance;

  for (let p = 0; p < n; p++) {
    let hit = false;
    if (art.bits[p]) { by.art++; hit = true; }

    const L = (0.2126 * pr[p] + 0.7152 * pg[p] + 0.0722 * pb[p]) / 255;
    if (o.lightInk ? L > t.groundLight : L < t.groundDark) { by.contrast++; hit = true; }

    if (med) {
      const dr = pr[p] - med[0];
      const dg = pg[p] - med[1];
      const db = pb[p] - med[2];
      if (dr * dr + dg * dg + db * db > d2) { by.own++; hit = true; }
    }
    if (hit) { mask[p] = 1; by.total++; }
  }
  return { w, h, mask, by };
}

/**
 * The (2r+1)-square mean of each colour channel, edges clamped to the pixels that exist. Separable
 * running sums, so it is two passes over the raster however large r is. r = 0 is the picture itself.
 */
function boxMean(rgba: Uint8Array, w: number, h: number, r: number): Float32Array[] {
  const planes = [0, 1, 2].map(c => {
    const src = new Float32Array(w * h);
    for (let p = 0, i = c; p < w * h; p++, i += 4) src[p] = rgba[i];
    return src;
  });
  if (r <= 0) return planes;
  return planes.map(src => {
    const tmp = new Float32Array(w * h);
    for (let y = 0; y < h; y++) {
      const row = y * w;
      let sum = 0;
      for (let x = 0; x <= Math.min(r, w - 1); x++) sum += src[row + x];
      for (let x = 0; x < w; x++) {
        const lo = Math.max(0, x - r);
        const hi = Math.min(w - 1, x + r);
        tmp[row + x] = sum / (hi - lo + 1);
        if (x + r + 1 < w) sum += src[row + x + r + 1];
        if (x - r >= 0) sum -= src[row + x - r];
      }
    }
    const out = new Float32Array(w * h);
    for (let x = 0; x < w; x++) {
      let sum = 0;
      for (let y = 0; y <= Math.min(r, h - 1); y++) sum += tmp[y * w + x];
      for (let y = 0; y < h; y++) {
        const lo = Math.max(0, y - r);
        const hi = Math.min(h - 1, y + r);
        out[y * w + x] = sum / (hi - lo + 1);
        if (y + r + 1 < h) sum += tmp[(y + r + 1) * w + x];
        if (y - r >= 0) sum -= tmp[(y - r) * w + x];
      }
    }
    return out;
  });
}

export type CentreInk = { count: number; x0: number; x1: number };

export type Edges = {
  /** Free interval's left edge per band, frame px, clearance applied. 0 where nothing is in the way. */
  L: number[];
  /** Its right edge. `frameW` where nothing is in the way. */
  R: number[];
  /** Obstacle pixels inside the centre exclusion per band, which the profile ignores and the report does not. */
  centre: CentreInk[];
};

/**
 * Per band, the free interval around the page centre (spec 7.1 step 5).
 *
 * `mask` is `w` x `h`, the band raster: row 0 is the grid's `y0`, and `h` is `bands * step`.
 *
 *   L = (the right edge of the nearest-to-centre obstacle on the left) + clearance
 *   R = (the left edge of the nearest-to-centre obstacle on the right) - clearance
 *
 * "Nearest to centre" is the max x on the left and the min x on the right: type is centred, so
 * the nearest wall on each side is the only one that can touch it, and ink further out is
 * already behind it. Ink inside `cx +/- centerExclusion` is the heading's own ground and sets no
 * wall; it is counted in `centre` so the report can say where a certificate has artwork under
 * its title. A pixel x covers [x, x+1), so the left wall is x+1 and `x === cx - exclusion` is
 * already in the centre.
 *
 * VERTICAL CLEARANCE: a line of type is taller than one band and its descenders and the next
 * line's ascenders reach into the neighbours, so each band takes the worse of itself and the band
 * above and below (max of the L's, min of the R's), once, from the raw values and not cumulatively.
 * A band with nothing in it is the whole frame, 0..frameW: the identity for the lookup's own
 * max/min across a custom scroll's components, which is what makes an empty band safe to store.
 */
export function bandEdges(
  mask: Uint8Array,
  w: number,
  h: number,
  t: Pick<Thresholds, 'clearance' | 'centerExclusion'>,
): Edges {
  const { step, bands } = GRID;
  if (mask.length !== w * h || h !== bands * step) {
    throw new Error(`mask does not match the band grid: ${w}x${h} (${mask.length} cells), want ${bands} bands of ${step} rows`);
  }
  const cx = w / 2;
  const leftEnd = cx - t.centerExclusion;     // left zone: x < leftEnd
  const rightStart = cx + t.centerExclusion;  // right zone: x >= rightStart

  const rawL: number[] = new Array(bands).fill(0);
  const rawR: number[] = new Array(bands).fill(w);
  const centre: CentreInk[] = Array.from({ length: bands }, () => ({ count: 0, x0: -1, x1: -1 }));

  for (let b = 0; b < bands; b++) {
    let leftMax = -1;
    let rightMin = Infinity;
    const c = centre[b];
    for (let y = b * step; y < (b + 1) * step; y++) {
      const row = y * w;
      for (let x = 0; x < w; x++) {
        if (!mask[row + x]) continue;
        if (x < leftEnd) { if (x > leftMax) leftMax = x; }
        else if (x >= rightStart) { if (x < rightMin) rightMin = x; }
        else {
          c.count++;
          if (c.x0 < 0 || x < c.x0) c.x0 = x;
          if (x > c.x1) c.x1 = x;
        }
      }
    }
    if (leftMax >= 0) rawL[b] = leftMax + 1 + t.clearance;
    if (rightMin !== Infinity) rawR[b] = rightMin - t.clearance;
  }

  const L = rawL.map((_, b) => Math.max(rawL[Math.max(0, b - 1)], rawL[b], rawL[Math.min(bands - 1, b + 1)]));
  const R = rawR.map((_, b) => Math.min(rawR[Math.max(0, b - 1)], rawR[b], rawR[Math.min(bands - 1, b + 1)]));
  return { L, R, centre };
}
