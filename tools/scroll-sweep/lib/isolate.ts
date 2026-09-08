import type { Page } from '@playwright/test';

export type Layer = 'full' | 'G' | 'A' | 'B' | 'C' | 'Codd' | 'Ceven';

const STYLE_ID = 'sweep-isolate';
const GROUND_ATTR = 'data-sweep-ground';

/*
 * ISOLATION MUST NOT REFLOW.
 *
 * `display:none` is forbidden in every rule below, and tests/isolate.spec.ts asserts
 * it by measuring a text box under each layer. Taking an element out of flow moves the
 * type, and two rasters of two different layouts cannot be intersected — the
 * intersection would report collisions that exist in neither render, which is a more
 * expensive kind of wrong than measuring nothing. Everything here is `visibility` or
 * `color: transparent`: both paint differently and lay out identically.
 *
 * EVERY LAYER IS DRAWN OVER THE CERTIFICATE'S OWN GROUND, not over white, and every
 * mask is that layer's DIFFERENCE from the ground alone (lib/mask.ts diffMask). A
 * white base cannot work here: cyanotype certificates set the type to #ffffff, so
 * against white they measure as having no type at all and the sweep calls every one
 * of them clean. See the long note on diffMask.
 */

/* Everything outside .Award that is NOT ground: the plates, the border, the heraldry.
   Layer A is what these ADD to the ground. */
const HIDE_OVERLAYS = `.award-template > *:not(.Award):not([${GROUND_ATTR}]) {
  visibility: hidden !important; }`;

/* The whole text column. */
const HIDE_AWARD = `.Award { visibility: hidden !important; }`;

/* The ornament images inside .Award. .Underline counts as ornament: it is the ruled
   line the citation is written on, and type landing across a rule is a real defect. */
const HIDE_ORNAMENT = `.Award img { visibility: hidden !important; }`;

/* Glyph ink off without moving a single line box. -webkit-text-fill-color as well as
   color: several blocks set a fill of their own and it wins over color alone. */
const HIDE_GLYPHS = `.Award, .Award * {
  color: transparent !important;
  -webkit-text-fill-color: transparent !important;
  text-shadow: none !important; }`;

const parityOff = (p: '0' | '1') => `[data-sweep-parity="${p}"], [data-sweep-parity="${p}"] * {
  color: transparent !important; -webkit-text-fill-color: transparent !important; }`;

export const LAYER_CSS: Record<Layer, string> = {
  full: '',
  /* G — the ground alone. The base every other layer is differenced against. */
  G: `${HIDE_OVERLAYS} ${HIDE_AWARD}`,
  /* A — ground + plates. diff(A, G) is the overlay artwork. */
  A: HIDE_AWARD,
  /* B — ground + ornament. */
  B: `${HIDE_OVERLAYS} ${HIDE_GLYPHS}`,
  /* C — ground + glyph ink. */
  C: `${HIDE_OVERLAYS} ${HIDE_ORNAMENT}`,
  /* C by parity. Adjacent-block text-on-text is exactly odd n even, which is how two
     rasters cover all ten blocks' pairwise adjacency instead of ten. The runner also
     checks that Codd u Ceven reconstructs C, which is what catches a same-parity
     (non-adjacent) overlap the parity trick cannot see. */
  Codd: `${HIDE_OVERLAYS} ${HIDE_ORNAMENT} ${parityOff('0')}`,
  Ceven: `${HIDE_OVERLAYS} ${HIDE_ORNAMENT} ${parityOff('1')}`,
};

/**
 * The certificate's text blocks, in document order — the parity partition's domain.
 *
 * `:first-of-type` / `:nth-of-type`, not `:first-child` / `:nth-child`: the ornament
 * images are siblings of these paragraphs inside .IssuingTextSection, so a child index
 * counts them and a type index does not. That also keeps the harness correct across a
 * fix that moves an ornament into or out of that section.
 *
 * Selected on the certificate's own structure rather than on a marker attribute,
 * because the harness must not need a production change in order to measure. A block
 * added to _text.tpl and not listed here is simply never partitioned, and the
 * Codd u Ceven reconstruction check is what makes that visible rather than silent.
 */
export const BLOCK_SEL = [
  '.IssuingTextSection > p:first-of-type',
  '.IssuingTextSection > p:nth-of-type(2)',
  '.IssuingVerb',
  '.AwardeeName',
  '.AwardDetails',
  '.Order',
  '.Award_Reason',
  '.Flavour',
  '.AwardDate',
  '.AwardSignatory',
].join(',');

/**
 * Mark the ground plates, once per page.
 *
 * A GROUND is opaque, covers the page, and composites NORMALLY. All three conditions
 * are needed and each of them excludes something real:
 *
 *   full-frame        — a rank numeral or a corner ornament is not a ground.
 *   opaque pixels     — decided by SAMPLING the image, never by its file extension.
 *                       ArtUrl() serves the print rendition, so a .jpg master arrives
 *                       as .webp; an extension test called owl's opaque blueprint an
 *                       overlay and reported 100% false ink on every owl certificate.
 *   blend `normal`    — and this is the condition a size-and-alpha test misses.
 *                       owl/border.jpg is a full-frame OPAQUE image drawn at
 *                       `mix-blend-mode: screen`, so its dark field vanishes into the
 *                       ground and only the knotwork shows. It is an overlay that
 *                       happens to be stored as an opaque rectangle. battle's
 *                       arcane/wash.jpg (opacity 0.8, soft-light) is the same shape of
 *                       thing. Classifying either as ground would delete a real plate
 *                       from layer A.
 *
 * Geometry alone is useless here: `.Award` is 2550x3300 and the `centre` positioning
 * divs are 2550x3210. Requiring a painted image is what keeps those out.
 */
export async function markGround(page: Page): Promise<number> {
  return page.evaluate((attr) => {
    const opaque = new Map<string, boolean>();
    const isOpaque = (img: HTMLImageElement) => {
      const key = img.currentSrc || img.src;
      if (opaque.has(key)) return opaque.get(key)!;
      let flat = true;
      try {
        const c = document.createElement('canvas');
        c.width = 64; c.height = 64;
        const x = c.getContext('2d', { willReadFrequently: true })!;
        x.drawImage(img, 0, 0, 64, 64);
        const d = x.getImageData(0, 0, 64, 64).data;
        for (let i = 3; i < d.length; i += 4) { if (d[i] < 250) { flat = false; break; } }
      } catch (e) { flat = false; }   // a tainted canvas is not evidence of opacity
      opaque.set(key, flat);
      return flat;
    };

    let n = 0;
    document.querySelectorAll('.award-template').forEach(tpl => {
      const r0 = tpl.getBoundingClientRect();
      Array.from(tpl.children).forEach(el => {
        el.removeAttribute(attr);
        if (el.classList.contains('Award')) return;
        if (el.tagName !== 'IMG') return;
        const cs = getComputedStyle(el);
        if (cs.mixBlendMode !== 'normal') return;
        if (parseFloat(cs.opacity) < 0.999) return;
        const r = el.getBoundingClientRect();
        if (r.width < r0.width * 0.95 || r.height < r0.height * 0.95) return;
        if (!isOpaque(el as HTMLImageElement)) return;
        el.setAttribute(attr, '1');
        n++;
      });
    });
    return n;
  }, GROUND_ATTR);
}

export async function applyLayer(page: Page, layer: Layer): Promise<void> {
  await page.evaluate(({ css, id, sel }) => {
    /* Partition in DOCUMENT order, per certificate, so one certificate's parity
       cannot leak into the next on a multi-up sheet. */
    document.querySelectorAll('.Award').forEach(award => {
      Array.from(award.querySelectorAll(sel)).forEach((el, i) => {
        el.setAttribute('data-sweep-parity', String(i % 2));
      });
    });
    let s = document.getElementById(id) as HTMLStyleElement | null;
    if (!s) {
      s = document.createElement('style');
      s.id = id;
      document.head.appendChild(s);
    }
    s.textContent = css;
  }, { css: LAYER_CSS[layer], id: STYLE_ID, sel: BLOCK_SEL });
}
