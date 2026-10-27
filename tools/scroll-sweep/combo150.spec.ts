import { test, type Page } from '@playwright/test';
import { spawn } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { PNG } from 'pngjs';
import { composeUrl, type ComboCase } from './lib/combos';
import { COMBO_OUT, COMBO_BASE, COMBO_RERUN, COMBO_IDS } from './lib/combo-paths';
import { selectCases } from './lib/combo-rerun';
import { settle } from './lib/drive';
import { captureLayers, typeBand, scoreInk } from './lib/raster';
import { blockBoxes, showOnlyBlock, applyLayer, forceFullScale, BLOCK_SEL } from './lib/isolate';
import { diffMask, severity } from './lib/mask';
import { ZERO, ink, type Score } from './lib/report';

/**
 * Step 2 of the 150-combination sweep: render every case of matrix.json through
 * Scrollery/compose (the real print page, real heraldry, the shared login) and measure it.
 *
 * Per case: a full certificate PNG at 1:1 frame pixels (2550x3300) and an 850px JPEG; the
 * ink-collision scores (lib/raster + lib/isolate, unchanged: the Custom Border's .sc-knot is a
 * child of .award-template and so is in layer A, the artwork; the Custom Ribbon's .sc-ribbon
 * flourishes are .Award imgs and so are in layer B, the ornament; neither is ever in C, the
 * type); text clipping, box overflow and margins; the type sizes and the engine's own
 * attributes; broken images and console output; the Customize Design assertions; and timing.
 *
 * Shards by stride over the matrix, like sweep.spec.ts, and writes shard-N.json; a render that
 * fails is recorded with its error rather than failing the shard, so the report step still runs.
 */

/* Rerun mode (ORK3_COMBO_TAG + ORK3_COMBO_IDS): the named cases are rendered with their SAVED
   params from the original matrix.json (COMBO_BASE) into out/combo150-rerun/<tag>/ (COMBO_OUT);
   the original out/combo150/ is only ever read. */
const SHARDS = Math.max(1, Number(process.env.ORK3_SWEEP_SHARDS ?? 4));
const ONLY = process.env.ORK3_COMBO_ONLY;
const LIMIT = Number(process.env.ORK3_COMBO_LIMIT ?? 0);
const IMG = join(COMBO_OUT, 'images');
const JPEG_W = 850;

/** Frame px are 1/300 inch on a letter certificate. */
const PT_PER_PX = 72 / 300;

/** The safe box the Custom Border's knot leaves for type (spec: side bands end at 235 / 2315). */
const SAFE = { x0: 235, x1: 2315 };

type Rect = [number, number, number, number, number];   // x, y, w, h, block index (frame px)

type DomMeasure = {
  order: string; variant: string; rank: string; ground: string;
  comp: string | null; budget: string | null; ms: number | null; engineError: string | null;
  heading: string | null; sig: string | null; face: string | null;
  roles: Record<string, { px: number; pt: number; minPx: number; minPt: number; lines?: number; planned?: number; overflow?: boolean; text: string }>;
  lineOver: { role: string; w: number; allowed: number | null }[];
  clipped: { block: string; by: number; text: string }[];
  outside: { block: string; text: string }[];
  boxOverflow: { block: string; axis: string; scroll: number; client: number }[];
  extents: { minX: number; maxX: number; minY: number; maxY: number; minXBlock: string; maxXBlock: string } | null;
  rects: Rect[];
  blockNames: string[];
  knots: number;
  knotStyles: string[];
  borderAssets: string[];
  plateSrcs: string[];
  ribbons: { count: number; filtered: number; flood: string | null; flourishes: number };
  heraldry: { src: string; naturalWidth: number } | null;
  broken: string[];
  frame: { x: number; y: number; w: number; h: number };
};

/** Runs in the page. Nothing here may close over anything outside it. */
function measureDom(args: { blockSel: string; ptPerPx: number }): DomMeasure {
  const r1 = (n: number) => Math.round(n * 10) / 10;
  const tpl = document.querySelector('.award-template') as HTMLElement;
  const award = document.querySelector('.Award') as HTMLElement;
  const frame = document.querySelector('svg[data-scroll-frame]') as SVGElement;
  const fr = frame.getBoundingClientRect();
  const k = fr.width / 2550;
  const fx = (x: number) => (x - fr.left) / k;
  const fy = (y: number) => (y - fr.top) / k;

  const ROLES: Record<string, string> = {
    lead: '#IssuerTitle', place: '[data-scroll-text="issuerName"]', versal: '.ScVersal',
    verb: '.IssuingVerb', name: '[data-scroll-text="player"]', details: '.AwardDetails', award: '.Order',
    reason: '.Award_Reason', flavour: '.Flavour', date: '[data-scroll-text="date"]', givenBy: '[data-scroll-text="givenBy"]',
  };
  const textOf = (el: Element) => (el.textContent ?? '').replace(/\s+/g, ' ').trim();
  const roles: DomMeasure['roles'] = {};
  for (const [role, sel] of Object.entries(ROLES)) {
    const el = award.querySelector(sel);
    if (!el) continue;
    const px = parseFloat(getComputedStyle(el).fontSize);
    let minPx = Infinity;
    const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
      if (!/\S/.test((n as Text).data) || !n.parentElement) continue;
      minPx = Math.min(minPx, parseFloat(getComputedStyle(n.parentElement).fontSize));
    }
    if (!Number.isFinite(minPx)) minPx = px;
    const rep: DomMeasure['roles'][string] = {
      px: r1(px), pt: Math.round(px * args.ptPerPx * 100) / 100, minPx: r1(minPx), minPt: Math.round(minPx * args.ptPerPx * 100) / 100,
      text: textOf(el).slice(0, 80),
    };
    const planned = el.getAttribute('data-sc-planned');
    if (planned !== null) rep.planned = Number(planned);
    const lines = el.getAttribute('data-sc-lines');
    if (lines !== null) rep.lines = Number(lines);
    if (el.hasAttribute('data-sc-overflow')) rep.overflow = true;
    roles[role] = rep;
  }

  const lineOver: DomMeasure['lineOver'] = [];
  for (const ln of Array.from(award.querySelectorAll<HTMLElement>('.sc-line'))) {
    const w = Math.max(ln.scrollWidth, ln.offsetWidth) || ln.getBoundingClientRect().width / k;
    const raw = ln.getAttribute('data-sc-w');
    const allowed = raw !== null && Number.isFinite(Number(raw)) ? Number(raw) : null;
    if (allowed !== null && w > allowed + 0.5) {
      lineOver.push({ role: ln.closest('[data-sc-role]')?.getAttribute('data-sc-role') ?? '?', w: r1(w), allowed });
    }
  }

  /* Painted text geometry, per block: every text node's line fragments, clamped by every
     overflow-clipping ancestor (as blockBoxes() does). A fragment the clamp cuts is text the
     reader cannot see. */
  const blockSels = args.blockSel.split(',');
  const blocks = Array.from(award.querySelectorAll(args.blockSel));
  const blockNames = blocks.map(b => blockSels.find(s => b.matches(s)) ?? b.className);
  const rects: Rect[] = [];
  const clipped: DomMeasure['clipped'] = [];
  const outside: DomMeasure['outside'] = [];
  let ext: DomMeasure['extents'] = null;
  blocks.forEach((block, bi) => {
    const walker = document.createTreeWalker(block, NodeFilter.SHOW_TEXT);
    let worstClip = 0; let clipText = '';
    let out = false;
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
      if (!/\S/.test((n as Text).data) || !n.parentElement) continue;
      const cs = getComputedStyle(n.parentElement);
      if (cs.visibility === 'hidden' || cs.display === 'none') continue;
      const range = document.createRange();
      range.selectNodeContents(n);
      for (const q of Array.from(range.getClientRects())) {
        if (q.width < 0.5 || q.height < 0.5) continue;
        let t = q.top, b = q.bottom, l = q.left, rt = q.right;
        let p: HTMLElement | null = n.parentElement;
        while (p && p !== award.parentElement) {
          const ps = getComputedStyle(p);
          if (ps.overflow !== 'visible' || ps.overflowX !== 'visible' || ps.overflowY !== 'visible') {
            const pr = p.getBoundingClientRect();
            t = Math.max(t, pr.top); b = Math.min(b, pr.bottom);
            l = Math.max(l, pr.left); rt = Math.min(rt, pr.right);
          }
          p = p.parentElement;
        }
        const cut = Math.max(0, t - q.top, q.bottom - b, l - q.left, q.right - rt) / k;
        /* More than a third of the line's height or a few px of width cut off: hidden text,
           not the font's ascender padding meeting a container edge. */
        const sig = (Math.max(0, t - q.top, q.bottom - b) / k > (q.height / k) * 0.34) || (Math.max(0, l - q.left, q.right - rt) / k > 3);
        if (sig && cut > worstClip) { worstClip = cut; clipText = (n as Text).data.trim().slice(0, 50); }
        if (b <= t || rt <= l) continue;
        const X = fx(l), Y = fy(t), W = (rt - l) / k, H = (b - t) / k;
        if (X < 0 || Y < 0 || X + W > 2550 || Y + H > 3300) out = true;
        rects.push([Math.round(X), Math.round(Y), Math.round(W), Math.round(H), bi]);
        if (!ext) ext = { minX: X, maxX: X + W, minY: Y, maxY: Y + H, minXBlock: blockNames[bi], maxXBlock: blockNames[bi] };
        else {
          if (X < ext.minX) { ext.minX = X; ext.minXBlock = blockNames[bi]; }
          if (X + W > ext.maxX) { ext.maxX = X + W; ext.maxXBlock = blockNames[bi]; }
          ext.minY = Math.min(ext.minY, Y); ext.maxY = Math.max(ext.maxY, Y + H);
        }
      }
    }
    if (worstClip > 0) clipped.push({ block: blockNames[bi], by: r1(worstClip), text: clipText });
    if (out) outside.push({ block: blockNames[bi], text: textOf(block).slice(0, 50) });
  });
  if (ext) { const e = ext as NonNullable<DomMeasure['extents']>; e.minX = r1(e.minX); e.maxX = r1(e.maxX); e.minY = r1(e.minY); e.maxY = r1(e.maxY); }

  /* Box overflow: a block-level text element whose content is bigger than its box. */
  const boxOverflow: DomMeasure['boxOverflow'] = [];
  const boxEls = new Set<Element>([...blocks, ...Object.values(ROLES).map(s => award.querySelector(s)).filter(Boolean) as Element[]]);
  boxEls.forEach(el => {
    const h = el as HTMLElement;
    if (getComputedStyle(h).display === 'inline' || !textOf(h)) return;
    const name = blockSels.find(s => h.matches(s)) ?? (Object.entries(ROLES).find(([, s]) => h.matches(s))?.[0] ?? h.className);
    if (h.clientWidth > 0 && h.scrollWidth > h.clientWidth + 1) boxOverflow.push({ block: name, axis: 'x', scroll: h.scrollWidth, client: h.clientWidth });
    if (h.clientHeight > 0 && h.scrollHeight > h.clientHeight + 1) boxOverflow.push({ block: name, axis: 'y', scroll: h.scrollHeight, client: h.clientHeight });
  });

  /* The design. */
  const knotEls = Array.from(tpl.querySelectorAll('.sc-knot'));
  const outsideAward = (el: Element) => !award.contains(el) && !el.closest('.sc-knot');
  const imgs = Array.from(tpl.querySelectorAll('img')).filter(outsideAward);
  const reBorder = /\/border(_\d+|_symbols)?\.(png|jpe?g|webp)(\?|$)/i;
  const reFramed = /\/(jovious\/bg|warrior\/bg_10)\.(png|jpe?g|webp)(\?|$)/i;
  const borderAssets = imgs.filter(i => !i.classList.contains('sc-rank-keep'))
    .map(i => i.getAttribute('src') ?? '').filter(s => reBorder.test(s) || reFramed.test(s))
    .map(s => new URL(s, location.href).pathname.replace(/^.*\/scrolls\//, ''));
  const partial = Array.from(tpl.querySelectorAll('use')).filter(u => (u.getAttribute('href') ?? '').startsWith('#Border-') && !u.closest('.sc-knot'));
  if (partial.length) borderAssets.push(`knotwork-partial(${partial.length} use)`);
  const plateSrcs = imgs.map(i => new URL(i.getAttribute('src') ?? '', location.href).pathname.replace(/^.*\/(scrolls|heraldry)\//, '$1/'));

  const flourishes = Array.from(award.querySelectorAll('img.Flourish'));
  const ribbonEls = flourishes.filter(f => f.classList.contains('sc-ribbon'));
  const filtered = ribbonEls.filter(f => /sc-ribbon-tint/.test(getComputedStyle(f).filter)).length;
  const flood = document.querySelector('#sc-ribbon-tint feFlood')?.getAttribute('flood-color') ?? null;

  const hImg = tpl.querySelector('.centre-top img') as HTMLImageElement | null;
  const broken = Array.from(document.images)
    .filter(i => i.complete && i.naturalWidth === 0 && (i.getAttribute('src') ?? '') !== '')
    .map(i => i.getAttribute('src') ?? '');

  const body = document.body;
  return {
    order: tpl.dataset.order ?? '', variant: tpl.dataset.variant ?? '', rank: tpl.dataset.rank ?? '', ground: tpl.dataset.ground ?? '',
    comp: award.getAttribute('data-sc-comp'), budget: award.getAttribute('data-sc-budget'),
    ms: award.getAttribute('data-sc-ms') !== null ? Number(award.getAttribute('data-sc-ms')) : null,
    engineError: award.getAttribute('data-sc-error'),
    heading: body.getAttribute('data-sc-heading'), sig: body.getAttribute('data-scroll-sig'), face: body.getAttribute('data-scroll-face'),
    roles, lineOver, clipped, outside, boxOverflow, extents: ext, rects, blockNames,
    knots: knotEls.length, knotStyles: knotEls.map(e => e.getAttribute('style') ?? ''),
    borderAssets, plateSrcs,
    ribbons: { count: ribbonEls.length, filtered, flood, flourishes: flourishes.length },
    heraldry: hImg ? { src: hImg.getAttribute('src') ?? '', naturalWidth: hImg.naturalWidth } : null,
    broken,
    frame: { x: fr.left, y: fr.top, w: fr.width, h: fr.height },
  };
}

const KNOT_ONLY_ID = 'combo-knot-only';
/*
 * Page chrome off, and the certificate moved to the left edge. forceFullScale() widens the SVG
 * to 2550px but leaves .scroll-page an 816px box centred with auto margins, so the frame starts
 * at x≈892 and its right third hangs past a 2600px viewport, where screenshot({ clip }) cannot
 * reach. Pinning .scroll-page to the edge only moves the frame; the certificate is laid out in
 * the viewBox's own coordinates and does not reflow.
 */
const CHROME_CSS = '.sc-toolbar,.sc-advisory,.sc-clip-notice,.sc-back{display:none!important}'
  + '.scroll-page{margin:0!important;width:2550px!important;max-width:none!important}'
  + 'body.scroll-surface{margin:0!important}';

/**
 * Distance from the type to the knot, in frame px: the knot drawn ALONE on a transparent page
 * (every other layer hidden, its blend and opacity forced to normal so a faint match pass still
 * counts), its alpha as a mask, a chamfer distance transform over the frame, and the minimum of
 * that field over every painted text fragment. Line boxes, not glyph ink, so it errs close.
 */
async function knotGap(page: Page, rects: Rect[], blockNames: string[]): Promise<{ gap: number; block: string } | null> {
  await page.evaluate((id) => {
    let st = document.getElementById(id) as HTMLStyleElement | null;
    if (!st) { st = document.createElement('style'); st.id = id; document.head.appendChild(st); }
    st.textContent = `.award-template > *:not(.sc-knot) { visibility: hidden !important; }
      .sc-knot { visibility: visible !important; mix-blend-mode: normal !important; opacity: 1 !important; filter: none !important; }
      html, body, body * { background: transparent !important; background-image: none !important; box-shadow: none !important; }`;
  }, KNOT_ONLY_ID);
  const box = await page.evaluate(() => {
    const r = document.querySelector('svg[data-scroll-frame]')!.getBoundingClientRect();
    return { x: r.left, y: r.top, width: r.width, height: r.height };
  });
  const shot = await page.screenshot({ clip: box, type: 'png', omitBackground: true, animations: 'disabled' });
  await page.evaluate((id) => document.getElementById(id)?.remove(), KNOT_ONLY_ID);
  const img = PNG.sync.read(shot);
  const W = img.width, H = img.height;
  const kx = W / 2550, ky = H / 3300;
  const INF = 1e9;
  const d = new Float32Array(W * H);
  let any = false;
  for (let p = 0; p < W * H; p++) {
    if (img.data[p * 4 + 3] > 40) { d[p] = 0; any = true; } else d[p] = INF;
  }
  if (!any) return null;
  const D1 = 1, D2 = Math.SQRT2;
  for (let y = 0; y < H; y++) for (let x = 0; x < W; x++) {
    const p = y * W + x; let v = d[p];
    if (v === 0) continue;
    if (x > 0) v = Math.min(v, d[p - 1] + D1);
    if (y > 0) { v = Math.min(v, d[p - W] + D1); if (x > 0) v = Math.min(v, d[p - W - 1] + D2); if (x < W - 1) v = Math.min(v, d[p - W + 1] + D2); }
    d[p] = v;
  }
  for (let y = H - 1; y >= 0; y--) for (let x = W - 1; x >= 0; x--) {
    const p = y * W + x; let v = d[p];
    if (v === 0) continue;
    if (x < W - 1) v = Math.min(v, d[p + 1] + D1);
    if (y < H - 1) { v = Math.min(v, d[p + W] + D1); if (x < W - 1) v = Math.min(v, d[p + W + 1] + D2); if (x > 0) v = Math.min(v, d[p + W - 1] + D2); }
    d[p] = v;
  }
  let best = INF, bestBlock = '';
  for (const [x, y, w, h, bi] of rects) {
    const x0 = Math.max(0, Math.floor(x * kx)), x1 = Math.min(W - 1, Math.ceil((x + w) * kx));
    const y0 = Math.max(0, Math.floor(y * ky)), y1 = Math.min(H - 1, Math.ceil((y + h) * ky));
    for (let yy = y0; yy <= y1; yy++) {
      const row = yy * W;
      for (let xx = x0; xx <= x1; xx++) {
        const v = d[row + xx];
        if (v < best) { best = v; bestBlock = blockNames[bi]; }
      }
    }
  }
  return { gap: Math.round((best / kx) * 10) / 10, block: bestBlock };
}

/** The text-on-text pass, as sweep.spec.ts runs it: screen on painted boxes, settle on pixels. */
async function textOnText(page: Page, band: { x: number; y: number; width: number; height: number }, G: Buffer, glyphPx: number) {
  let CC: Score = ZERO;
  let ccPair = '';
  if (!glyphPx) return { CC, ccPair };
  const boxes = await blockBoxes(page);
  const cand: [number, number][] = [];
  for (let i = 0; i < boxes.length; i++) {
    for (let j = i + 1; j < boxes.length; j++) {
      const a = boxes[i], b = boxes[j];
      if (a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom) cand.push([a.i, b.i]);
    }
  }
  const cache = new Map<number, ReturnType<typeof diffMask>>();
  const blockMask = async (i: number) => {
    if (!cache.has(i)) {
      await showOnlyBlock(page, i);
      cache.set(i, diffMask(await page.screenshot({ clip: band, type: 'png', animations: 'disabled' }), G));
    }
    return cache.get(i)!;
  };
  for (const [i, j] of cand) {
    const sv = severity(await blockMask(i), await blockMask(j), glyphPx);
    if (sv.score > CC.score) { CC = sv; ccPair = `${i}+${j}`; }
  }
  await applyLayer(page, 'full');
  return { CC, ccPair };
}

function jpeg(png: string, jpg: string): Promise<void> {
  return new Promise(resolve => {
    const p = spawn('magick', [png, '-resize', `${JPEG_W}x`, '-quality', '85', jpg], { stdio: 'ignore' });
    p.on('exit', () => resolve());
    p.on('error', () => resolve());
  });
}

for (let shard = 0; shard < SHARDS; shard++) {
  test(`combo150 ${shard + 1}/${SHARDS}`, async ({ page }) => {
    test.setTimeout(0);
    if (COMBO_IDS && !COMBO_RERUN) throw new Error('ORK3_COMBO_IDS needs ORK3_COMBO_TAG: refusing to write into the original out/combo150/');
    mkdirSync(IMG, { recursive: true });
    const matrix = JSON.parse(readFileSync(join(COMBO_BASE, 'matrix.json'), 'utf8')) as { cases: (ComboCase & { url: string })[] };
    let cases = matrix.cases;
    if (COMBO_RERUN) cases = selectCases(cases, COMBO_IDS);
    if (ONLY) cases = cases.filter(c => c.id.includes(ONLY));
    if (LIMIT) cases = cases.slice(0, LIMIT);
    if (SHARDS > 1) cases = cases.filter((_, i) => i % SHARDS === shard);

    let consoleMsgs: string[] = [];
    let netFails: string[] = [];
    page.on('console', m => { if (m.type() === 'error' || m.type() === 'warning') consoleMsgs.push(`${m.type()}: ${m.text()}`.slice(0, 300)); });
    page.on('pageerror', e => consoleMsgs.push(`pageerror: ${String(e)}`.slice(0, 300)));
    page.on('requestfailed', r => netFails.push(`${r.failure()?.errorText ?? 'failed'} ${r.url()}`.slice(0, 300)));
    page.on('response', r => { if (r.status() >= 400) netFails.push(`${r.status()} ${r.url()}`.slice(0, 300)); });

    const results: unknown[] = [];
    const jpegs: Promise<void>[] = [];
    let n = 0;
    for (const c of cases) {
      consoleMsgs = []; netFails = [];
      const rec: Record<string, unknown> = { id: c.id, n: c.n, driver: 'compose', url: c.url ?? composeUrl(c) };
      try {
        const t0 = Date.now();
        await page.goto(c.url ?? composeUrl(c), { waitUntil: 'load' });
        const tLoad = Date.now() - t0;
        const has = await page.$('.award-template');
        if (!has) {
          const txt = (await page.textContent('body').catch(() => '')) ?? '';
          throw new Error('no certificate on the page: ' + txt.replace(/\s+/g, ' ').trim().slice(0, 200));
        }
        await settle(page);
        const tSettle = Date.now() - t0;
        await page.addStyleTag({ content: CHROME_CSS });
        await forceFullScale(page);
        await page.waitForTimeout(60);

        const dom = await page.evaluate(measureDom, { blockSel: BLOCK_SEL, ptPerPx: PT_PER_PX });
        if (dom.order !== c.order || (c.variant && dom.variant && dom.variant !== c.variant)) {
          rec.orderMismatch = `${dom.order}/${dom.variant}`;
        }
        const vp = page.viewportSize()!;
        if (dom.frame.x < 0 || dom.frame.y < 0 || dom.frame.x + dom.frame.w > vp.width + 0.5 || dom.frame.y + dom.frame.h > vp.height + 0.5) {
          throw new Error(`certificate frame ${JSON.stringify(dom.frame)} is not inside the ${vp.width}x${vp.height} viewport`);
        }

        /* The certificate at 1:1. */
        const clip = { x: dom.frame.x, y: dom.frame.y, width: dom.frame.w, height: dom.frame.h };
        const png = join(IMG, `${c.id}.png`);
        writeFileSync(png, await page.screenshot({ clip, type: 'png', animations: 'disabled' }));
        jpegs.push(jpeg(png, join(IMG, `${c.id}.jpg`)));

        const gap = dom.knots > 0 ? await knotGap(page, dom.rects, dom.blockNames) : null;

        const tInk0 = Date.now();
        const band = await typeBand(page);
        const shots = await captureLayers(page, band);
        const { glyphPx, CA, CB, CAtop, CBtop } = scoreInk(shots);
        if (process.env.ORK3_COMBO_DEBUG === '1') {
          mkdirSync(join(COMBO_OUT, 'debug'), { recursive: true });
          for (const [k, b] of Object.entries(shots)) writeFileSync(join(COMBO_OUT, 'debug', `${c.id}-${k}.png`), b);
        }
        const { CC, ccPair } = await textOnText(page, band, shots.G, glyphPx);
        const tInk = Date.now() - tInk0;

        const { rects: _r, frame: _f, ...domRest } = dom;
        Object.assign(rec, {
          ms: { load: tLoad, settled: tSettle, engine: dom.ms, ink: tInk },
          dom: domRest,
          knotGap: gap,
          safeMargin: dom.extents ? Math.round(Math.min(dom.extents.minX - SAFE.x0, SAFE.x1 - dom.extents.maxX) * 10) / 10 : null,
          ink: {
            glyphPx, CA, CB, CC, CAtop, CBtop, ccPair,
            px: { CA: Math.round(ink(CA, glyphPx)), CB: Math.round(ink(CB, glyphPx)), CC: Math.round(ink(CC, glyphPx)),
                  CAtop: Math.round(ink(CAtop, glyphPx)), CBtop: Math.round(ink(CBtop, glyphPx)) },
          },
          console: consoleMsgs.slice(),
          network: netFails.slice(),
        });
      } catch (e) {
        rec.error = String(e).split('\n').slice(0, 3).join(' | ');
        rec.console = consoleMsgs.slice();
        rec.network = netFails.slice();
      }
      results.push(rec);
      if (++n % 5 === 0) process.stdout.write(`  shard ${shard + 1}: ${n}/${cases.length}\n`);
    }
    await Promise.all(jpegs);
    writeFileSync(join(COMBO_OUT, `shard-${shard}.json`), JSON.stringify({ shard, results }, null, 1));
    process.stdout.write(`combo150 shard ${shard + 1}/${SHARDS}: ${results.length} cases, `
      + `${results.filter(r => (r as { error?: string }).error).length} failed\n`);
    if (!existsSync(IMG)) throw new Error('no images directory');
  });
}
