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
