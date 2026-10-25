import type { Page } from '@playwright/test';

export type Layer = 'full' | 'G' | 'A' | 'B' | 'C' | 'Ctop';

const STYLE_ID = 'sweep-isolate';
const SCALE_ID = 'sweep-scale';

/*
 * RASTERISE AT 1:1 WITH THE FRAME.
 *
 * The certificate is `<svg viewBox="0 0 2550 3300" width="100%">`, and on the print
 * page that resolves to about 816 CSS px — so every raster was a 0.32x DOWNSAMPLE of
 * the thing being measured, and a hairline swash stroke is the first casualty of that.
 * Measuring painted pixels is the whole premise of this harness; measuring a third of
 * them is a quieter version of measuring boxes.
 *
 * Forcing the SVG to its own intrinsic size is free of side effects, which is what
 * makes this safe: the foreignObject's contents are ALWAYS laid out in the frame's
 * 2550x3300 coordinate space whatever the SVG is displayed at. Only the visual scale
 * changes, never the layout — so no line rewraps and no fitted size moves.
 */
const SCALE_CSS = `svg[data-scroll-frame] {
    width: 2550px !important; height: 3300px !important;
    max-width: none !important; max-height: none !important; }
  .scroll-page, .sc-stage, .sc-preview, .scroll-flow, body {
    max-width: none !important; overflow: visible !important; }`;

/** Force 1:1 rasterisation. Idempotent; call before measuring anything. */
export async function forceFullScale(page: Page): Promise<void> {
  await page.evaluate(({ id, css }) => {
    let st = document.getElementById(id) as HTMLStyleElement | null;
    if (!st) { st = document.createElement('style'); st.id = id; document.head.appendChild(st); }
    st.textContent = css;
  }, { id: SCALE_ID, css: SCALE_CSS });
}
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
const BLOCKS = [
  '.IssuingTextSection > p:first-of-type',
  '.IssuingTextSection > p:nth-of-type(2)',
  '.IssuingVerb',
  '.AwardeeName',
  '.AwardDetails',
  '.Order',
  /* The bare "for" between the order line and the citation. It has no hook of its own
     — it is `section.Body` with inline styles — and leaving it out of the partition is
     what made every case report a phantom 2.3 ppt of text-on-text: unpartitioned text
     is hidden by NEITHER parity rule, so it appears in both rasters and intersects
     itself. */
  '.Award > section.Body:not(.Award_Reason):not(.Flavour)',
  '.Award_Reason',
  '.Flavour',
  '.AwardDate',
  '.AwardSignatory',
];
export const BLOCK_SEL = BLOCKS.join(',');

/*
 * THE TOP HALF OF THE CERTIFICATE ENDS AT `.Order`.
 *
 * The top half is what the typeset engine composes: the issuing line, the verb, the
 * recipient, the award details and the order line, which are the blocks up to and
 * including `.Order`. The citation, flavour, date and signer below it are not the
 * engine's to place, so a collision there says nothing about it. Found by position in
 * the list rather than from a second list, so a block added before `.Order` cannot
 * leave this one stale.
 */
const BOTTOM_BLOCKS = BLOCKS.slice(BLOCKS.indexOf('.Order') + 1);

/* HIDE_GLYPHS, but only for the blocks after `.Order`. The blocks themselves and everything
   inside them: `color` is inherited, yet several descendants set a fill or a colour of
   their own. */
const HIDE_BOTTOM_GLYPHS = `${BOTTOM_BLOCKS.map(sel => `${sel}, ${sel} *`).join(',\n')} {
  color: transparent !important;
  -webkit-text-fill-color: transparent !important;
  text-shadow: none !important; }`;

/*
 * ONE BLOCK ONLY — how text-on-text is measured.
 *
 * The first version of this partitioned the ten text blocks by parity and intersected
 * the odd raster with the even one, on the reasoning that text-on-text can only happen
 * between ADJACENT blocks and two rasters therefore cover all ten. Both halves of that
 * were wrong, and a real defect proved it: on garber at rank 10 with a long signer, the
 * flavour line runs into the signature. `.Flavour` is block 8 and `.AwardSignatory` is
 * block 10 — the SAME parity — so their overlap appears in one raster and never in an
 * intersection. The union guard could not see it either: same-parity ink is still in
 * the union, so `Codd u Ceven == C` held while the collision sat there in the image.
 *
 * Blocks are not adjacent-only, and a guard that cannot fail is not a guard.
 *
 * So: screen on painted boxes, which OVER-report and therefore cannot miss a pair, then
 * confirm each candidate pair on real pixels by rendering the two blocks alone. Boxes
 * decide what to look at; only pixels decide the verdict. Typically no pairs at all, and
 * never more than a handful, so this is cheaper than the six rasters it replaces.
 */
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
  /* Ctop — C with the glyphs of every block after `.Order` made transparent, so only the
     top half's ink is left. `color: transparent`, never `display: none`: the type does not
     move and Ctop can be intersected with A and B exactly as C is. */
  Ctop: `${HIDE_OVERLAYS} ${HIDE_ORNAMENT} ${HIDE_BOTTOM_GLYPHS}`,
};

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
      award.querySelectorAll('[data-sweep-orphan]').forEach(el => el.removeAttribute('data-sweep-orphan'));
      Array.from(award.querySelectorAll(sel)).forEach((el, i) => {
        el.setAttribute('data-sweep-parity', String(i % 2));
      });
      /* Anything that paints type and sits under no partitioned block. Zero of these
         today; the stamp exists so that a block added to _text.tpl and forgotten here
         shows up as a parity LEAK in the report rather than as a phantom collision. */
      award.querySelectorAll('*').forEach(el => {
        if (el.children.length) return;
        if (!(el.textContent || '').trim()) return;
        const r = el.getBoundingClientRect();
        if (r.width <= 0 || r.height <= 0) return;
        let p: Element | null = el;
        while (p && p !== award) {
          if (p.hasAttribute('data-sweep-parity')) return;
          p = p.parentElement;
        }
        el.setAttribute('data-sweep-orphan', '1');
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

/**
 * Hide every glyph in the certificate except those inside block index `i`.
 *
 * Hides the OTHER blocks rather than hiding everything and restoring one: `color` is
 * inherited, so a blanket `transparent !important` on .Award reaches into the block
 * being isolated and there is no value to restore it to — `inherit` would resolve to
 * the transparent parent. Hiding the complement leaves the wanted block's own cascade
 * completely untouched, which is the point: it must render exactly as it does in C.
 */
export async function showOnlyBlock(page: Page, i: number): Promise<void> {
  await page.evaluate(({ id, sel, idx }) => {
    document.querySelectorAll('.Award').forEach(award => {
      award.querySelectorAll('[data-sweep-orphan]').forEach(el => el.removeAttribute('data-sweep-orphan'));
      Array.from(award.querySelectorAll(sel)).forEach((el, n) => {
        el.setAttribute('data-sweep-block', String(n));
      });
      award.querySelectorAll('*').forEach(el => {
        if (el.children.length) return;
        if (!(el.textContent || '').trim()) return;
        let p: Element | null = el;
        while (p && p !== award) {
          if (p.hasAttribute('data-sweep-block')) return;
          p = p.parentElement;
        }
        el.setAttribute('data-sweep-orphan', '1');
      });
    });
    const off = `color: transparent !important; -webkit-text-fill-color: transparent !important;`;
    const css = `.award-template > *:not(.Award):not([data-sweep-ground]) { visibility: hidden !important; }
      .Award img { visibility: hidden !important; }
      [data-sweep-block]:not([data-sweep-block="${idx}"]),
      [data-sweep-block]:not([data-sweep-block="${idx}"]) * { ${off} }
      [data-sweep-orphan], [data-sweep-orphan] * { ${off} }`;
    let st = document.getElementById(id) as HTMLStyleElement | null;
    if (!st) { st = document.createElement('style'); st.id = id; document.head.appendChild(st); }
    st.textContent = css;
  }, { id: STYLE_ID, sel: BLOCK_SEL, idx: i });
}

/**
 * The painted, clip-clamped box of each text block, in viewport px.
 *
 * PAINTED: the citation's surplus ruled lines are laid out 145px below the text and cut
 * off by its own overflow:hidden, so an unclamped rect says the citation reaches the
 * bottom of the page and every block below it looks overlapped. Same clamp as
 * paintedBottom() in scrollery-autofit.js, in all four directions.
 *
 * This is a SCREEN, not a verdict. It over-reports — a box is not ink — so it cannot
 * miss a pair, and every pair it proposes is then settled on pixels.
 */
export async function blockBoxes(page: Page) {
  return page.evaluate((sel) => {
    const award = document.querySelector('.Award') as HTMLElement;
    const out: { i: number; top: number; bottom: number; left: number; right: number }[] = [];
    Array.from(award.querySelectorAll(sel)).forEach((block, i) => {
      let top = Infinity, bottom = -Infinity, left = Infinity, right = -Infinity;
      const leaves = block.querySelectorAll('*').length ? block.querySelectorAll('*') : [block];
      Array.from(leaves).forEach(el => {
        if (el.children.length) return;
        if (el.tagName === 'IMG') return;              // ornament, not glyph ink
        if (!(el.textContent || '').trim()) return;
        const r = el.getBoundingClientRect();
        if (r.width <= 0 || r.height <= 0) return;
        let t = r.top, b = r.bottom, l = r.left, rt = r.right;
        let p = el.parentElement;
        while (p && p !== award.parentElement) {
          if (getComputedStyle(p).overflow !== 'visible') {
            const pr = p.getBoundingClientRect();
            t = Math.max(t, pr.top); b = Math.min(b, pr.bottom);
            l = Math.max(l, pr.left); rt = Math.min(rt, pr.right);
          }
          p = p.parentElement;
        }
        if (b <= t || rt <= l) return;
        top = Math.min(top, t); bottom = Math.max(bottom, b);
        left = Math.min(left, l); right = Math.max(right, rt);
      });
      if (Number.isFinite(top)) out.push({ i, top, bottom, left, right });
    });
    return out;
  }, BLOCK_SEL);
}

/**
 * The full-frame layers that are NOT already classified as ground, with their index.
 * Candidates for the wash test in raster.ts.
 */
export async function washCandidates(page: Page): Promise<number[]> {
  return page.evaluate((attr) => {
    const out: number[] = [];
    document.querySelectorAll('.award-template').forEach(tpl => {
      const r0 = tpl.getBoundingClientRect();
      Array.from(tpl.children).forEach((el, i) => {
        el.setAttribute('data-sweep-plate', String(i));
        if (el.classList.contains('Award')) return;
        if (el.hasAttribute(attr)) return;
        if (el.tagName !== 'IMG') return;
        const r = el.getBoundingClientRect();
        if (r.width < r0.width * 0.95 || r.height < r0.height * 0.95) return;
        out.push(i);
      });
    });
    return out;
  }, GROUND_ATTR);
}

/** Show the ground plus exactly one plate, so that plate's own contribution can be seen. */
export async function showOnlyPlate(page: Page, i: number): Promise<void> {
  await page.evaluate(({ id, idx, attr }) => {
    const css = `.award-template > *:not([data-sweep-plate="${idx}"]):not([${attr}]) {
        visibility: hidden !important; }
      .Award { visibility: hidden !important; }`;
    let st = document.getElementById(id) as HTMLStyleElement | null;
    if (!st) { st = document.createElement('style'); st.id = id; document.head.appendChild(st); }
    st.textContent = css;
  }, { id: STYLE_ID, idx: i, attr: GROUND_ATTR });
}

/** Promote a plate to ground for the rest of this page's measurement. */
export async function markAsGround(page: Page, i: number): Promise<void> {
  await page.evaluate(({ idx, attr }) => {
    document.querySelectorAll(`[data-sweep-plate="${idx}"]`).forEach(el => el.setAttribute(attr, '1'));
  }, { idx: i, attr: GROUND_ATTR });
}
