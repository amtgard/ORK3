import type { Page } from '@playwright/test';
import { applyLayer, markGround, type Layer } from './isolate';

export const LAYERS: Layer[] = ['full', 'G', 'A', 'B', 'C'];

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
 */
export async function captureLayers(page: Page, clip?: Clip): Promise<Record<Layer, Buffer>> {
  const band = clip ?? (await typeBand(page));
  /* Classify the grounds ONCE, on the unmodified page. Doing it per layer would ask
     an already-hidden image whether it is opaque. */
  await markGround(page);
  const out = {} as Record<Layer, Buffer>;
  for (const layer of LAYERS) {
    await applyLayer(page, layer);
    out[layer] = await page.screenshot({ clip: band, type: 'png', animations: 'disabled' });
  }
  await applyLayer(page, 'full');
  return out;
}
