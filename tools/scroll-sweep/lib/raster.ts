import type { Page } from '@playwright/test';
import { applyLayer, markGround, washCandidates, showOnlyPlate, markAsGround, forceFullScale, type Layer } from './isolate';
import { diffMask, count, erode1, severity } from './mask';
import { ZERO } from './report';
import { setUnderlayCss, underlayCssFor } from './underlays';

export const LAYERS: Layer[] = ['full', 'G', 'A', 'B', 'C', 'Ctop'];

export type Clip = { x: number; y: number; width: number; height: number };

/**
 * The band worth rasterising: the union of the type's PAINTED extent, padded.
 *
 * Not the whole 2550x3300 page. Every question this harness asks is "does something
 * else land where the TYPE lands", so a pixel no glyph could reach costs decode time
 * and answers nothing.
 *
 * PAINTED, so a clipped element cannot inflate the band. The citation's six ruled
 * underlines are laid out 145px apart whatever the citation says, and the surplus ones
 * are cut off by `.Award_Reason { overflow: hidden }` — reading their rects alone said
 * the citation reached 2707px on every certificate, which is the exact error
 * paintedBottom() exists to fix in scrollery-autofit.js. Same clamp here, in all four
 * directions rather than only downwards.
 *
 * The padding is 120 frame px: an ornament is positioned against a text box and hangs
 * off it, so the band has to be wider than the type or layer B would be cropped out of
 * its own measurement.
 */
export async function typeBand(page: Page): Promise<Clip> {
  await forceFullScale(page);
  return page.evaluate(() => {
    const award = document.querySelector('.Award') as HTMLElement | null;
    const frame = document.querySelector('svg[data-scroll-frame]') as SVGElement | null;
    if (!award || !frame) throw new Error('no certificate on the page');

    const o = frame.getBoundingClientRect();
    const k = o.width / 2550;
    let top = Infinity, bottom = -Infinity, left = Infinity, right = -Infinity;

    const clamped = (el: Element, r: DOMRect) => {
      const out = { top: r.top, bottom: r.bottom, left: r.left, right: r.right };
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
      if (el.children.length) return;                                   // leaves only
      if (el.tagName !== 'IMG' && !(el.textContent || '').trim()) return;
      const r = el.getBoundingClientRect();
      if (r.width <= 0 || r.height <= 0) return;
      const c = clamped(el, r);
      if (c.bottom <= c.top || c.right <= c.left) return;               // clipped away entirely
      top = Math.min(top, c.top); bottom = Math.max(bottom, c.bottom);
      left = Math.min(left, c.left); right = Math.max(right, c.right);
    });

    /* An entirely blank certificate (the `empty` profile on an unranked award) can
       leave nothing measurable. Fall back to the whole frame rather than throwing:
       "nothing to measure" is a real answer and the runner scores it as clean. */
    if (!Number.isFinite(top)) {
      return { x: Math.round(o.left), y: Math.round(o.top),
               width: Math.round(o.width), height: Math.round(o.height) };
    }

    const pad = 120 * k;
    const x = Math.max(o.left, left - pad);
    const y = Math.max(o.top, top - pad);
    return {
      x: Math.round(x),
      y: Math.round(y),
      width: Math.max(1, Math.round(Math.min(o.right, right + pad) - x)),
      height: Math.max(1, Math.round(Math.min(o.bottom, bottom + pad) - y)),
    };
  });
}

/**
 * The six rasters, all of the SAME clip, so they are pixel-comparable by construction.
 * The clip is computed once from the unmodified page and reused — recomputing it per
 * layer would give layer A a different band from layer C and every intersection would
 * be off by the difference.
 *
 * LAYER A APPLIES THE UNDERLAY POLICY, the way the layout does. The free-space profile
 * (profile.spec.ts) treats the plates system/lib/ork3/data/scroll-layout-underlays.php lists
 * as washes the type is DESIGNED to sit on, and keeps the type off the rest. Scoring the same
 * type against ALL the plates counted every glyph over a designed underlay as a collision
 * (96 of the 481 sampled cases, none of them real). So the underlay plates are hidden from A
 * here through the shared resolver (lib/underlays.ts): a `whole` plate entirely, a `window`
 * plate only inside its window's rows. G, B, C and Ctop are untouched, and so is `full`.
 * markGround()'s ground (and a wash classifyWashes() promoted to ground) is never hidden:
 * G keeps it, so hiding it from A alone would report the whole ground as artwork.
 */
export async function captureLayers(page: Page, clip?: Clip): Promise<Record<Layer, Buffer>> {
  await forceFullScale(page);
  const band = clip ?? (await typeBand(page));
  /* Classify the grounds ONCE, on the unmodified page. Doing it per layer would ask
     an already-hidden image whether it is opaque. */
  await markGround(page);
  await classifyWashes(page, band);
  const policy = await underlayCssFor(page);
  const out = {} as Record<Layer, Buffer>;
  for (const layer of LAYERS) {
    await applyLayer(page, layer);
    await setUnderlayCss(page, layer === 'A' ? policy.css : '');
    out[layer] = await page.screenshot({ clip: band, type: 'png', animations: 'disabled' });
  }
  await setUnderlayCss(page, '');
  await applyLayer(page, 'full');
  return out;
}

/**
 * The text-on-artwork and text-on-ornament scores, whole certificate and top half.
 *
 * `CA`/`CB` are layer C against layers A and B; `CAtop`/`CBtop` are the same with Ctop as
 * the glyph mask (see LAYER_CSS). All four are given the WHOLE certificate's glyph area as
 * their divisor, not Ctop's own: `ink()` in report.ts turns a score back into pixels by
 * multiplying by `Result.glyphPx`, so a score over any other area would be converted with
 * the wrong factor and the top-half figures would not be in the same units as the others.
 * Divided that way, `ink(CAtop)` is exactly the weighted ink pixels the top half's type
 * strikes on the artwork, which is also why it can never exceed `ink(CA)`.
 */
export function scoreInk(shots: Record<Layer, Buffer>) {
  const G = shots.G;
  const C = diffMask(shots.C, G);
  const Ctop = diffMask(shots.Ctop, G);
  const A = diffMask(shots.A, G);
  const B = diffMask(shots.B, G);
  const glyphPx = count(erode1(C));
  return {
    glyphPx,
    CA: glyphPx ? severity(C, A) : ZERO,
    CB: glyphPx ? severity(C, B) : ZERO,
    CAtop: glyphPx ? severity(Ctop, A, glyphPx) : ZERO,
    CBtop: glyphPx ? severity(Ctop, B, glyphPx) : ZERO,
  };
}

/**
 * A FULL-PAGE WASH IS NOT A COLLISION, and only the pixels can say which is which.
 *
 * markGround() classifies a ground as full-frame, opaque, `mix-blend-mode: normal`.
 * The blend condition is what keeps owl/border — a full-frame OPAQUE image drawn at
 * `screen`, whose dark field vanishes and whose knotwork does not — on the overlay
 * side where it belongs. But it also puts smith/burn there: a full-frame opaque
 * townscape drawn at `soft-light`, which tints the entire page and is a ground in
 * everything but the compositing operator. Every glyph on a smith certificate sits on
 * it, so smith came back 179 of 179 dirty with a "worst run" of 848 px — describing
 * the artwork the certificate is MADE of.
 *
 * No element property separates the two: both are full-frame, opaque, non-normal
 * blends. What separates them is what they actually paint. So each candidate is drawn
 * alone over the ground and measured: a layer that covers essentially the whole type
 * band changes the page uniformly and cannot be avoided by moving type anywhere, which
 * makes it a wash; a layer that covers part of it is artwork the type can collide with.
 *
 * Cached per plate signature: the plates are a function of order, variant, rank and
 * recipe, and are the same for every hand and every string, so this costs a handful of
 * rasters per certificate DESIGN rather than per case.
 */
const WASH_COVERAGE = 0.9;
const washCache = new Map<string, number[]>();

async function plateSignature(page: Page): Promise<string> {
  return page.evaluate(() => {
    const t = document.querySelector('.award-template') as HTMLElement | null;
    if (!t) return '';
    return [t.dataset.order, t.dataset.variant, t.dataset.rank, t.dataset.ground, t.dataset.border]
      .join('|');
  });
}

export async function classifyWashes(page: Page, band: Clip): Promise<void> {
  const sig = await plateSignature(page);
  const cached = washCache.get(sig);
  if (cached) {
    for (const i of cached) await markAsGround(page, i);
    await applyLayer(page, 'full');
    return;
  }

  const candidates = await washCandidates(page);
  if (!candidates.length) { washCache.set(sig, []); return; }

  await applyLayer(page, 'G');
  const ground = await page.screenshot({ clip: band, type: 'png', animations: 'disabled' });
  const area = band.width * band.height;

  const washes: number[] = [];
  for (const i of candidates) {
    await showOnlyPlate(page, i);
    const shot = await page.screenshot({ clip: band, type: 'png', animations: 'disabled' });
    if (count(diffMask(shot, ground)) / area > WASH_COVERAGE) washes.push(i);
  }

  washCache.set(sig, washes);
  for (const i of washes) await markAsGround(page, i);
  await applyLayer(page, 'full');
}
