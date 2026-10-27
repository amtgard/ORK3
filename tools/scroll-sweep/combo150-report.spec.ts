import { test } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readdirSync, readFileSync, unlinkSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { summary as describe, type ComboCase } from './lib/combos';
import { COMBO_OUT, COMBO_BASE, COMBO_RERUN, COMBO_IDS, COMBO_TAG } from './lib/combo-paths';
import { THRESHOLDS } from './lib/report';
import { selectCases, diffPng, metricDeltas, type PixelDiff, type Box } from './lib/combo-rerun';

/**
 * Step 3 of the 150-combination sweep: join the shards, grade every case against the matrix's
 * expectations, and write results.json, summary.md, the contact sheets (10 per sheet, in review
 * batch order) and batches.json (with the sheets each batch covers).
 *
 * Contact sheets and JPEGs use ImageMagick (`magick`), which this machine has; without it the
 * sheets are skipped and summary.md says so.
 */

type Case = ComboCase & { url: string; summary: string };
type Rec = Record<string, any>;

/** Type under this many points is called out (the flavour line is designed small; it still counts). */
const TINY_PT = 7;
/** Type closer than this to the knot, in frame px (1/300 in), is called out. */
const KNOT_NEAR = 12;

const path = (u: string) => { try { return new URL(u, 'http://x/').pathname; } catch { return u; } };

function grade(c: Case, r: Rec): { flags: string[]; info: string[]; design: Rec } {
  const flags: string[] = [];
  const info: string[] = [];
  const design: Rec = {};
  if (r.error) { flags.push('render-error'); return { flags, info, design }; }
  if (r.orderMismatch) flags.push('order-mismatch');
  const d = r.dom;
  const px = r.ink.px;
  if (px.CA > THRESHOLDS.CA) flags.push('ink:text-over-art');
  if (px.CB > THRESHOLDS.CB) flags.push('ink:text-over-ornament');
  if (px.CC > THRESHOLDS.CC) flags.push('ink:text-over-text');
  if (d.clipped.length) flags.push('text-clipped');
  if (d.outside.length) flags.push('text-outside-certificate');
  if (d.lineOver.length) flags.push('line-over-planned-width');
  const engineOverflow = Object.entries(d.roles).filter(([, v]: [string, any]) => v.overflow).map(([k]) => k);
  if (engineOverflow.length) flags.push('engine-overflow');
  if (d.budget && d.budget !== 'ok') flags.push('engine-budget-' + d.budget);
  if (d.engineError) flags.push('engine-error');
  if (d.boxOverflow.length) info.push('box-overflow');
  const tiny = Object.entries(d.roles).filter(([, v]: [string, any]) => v.text && v.minPt < TINY_PT).map(([k]) => k);
  if (tiny.length) flags.push('tiny-type');
  if (c.heading === 'auto' && d.comp === 'centered') info.push('fell-back-centered');
  if (d.broken.length) flags.push('broken-image');
  const consoleErr = (r.console as string[]).filter(m => !/favicon/i.test(m));
  if (consoleErr.some(m => m.startsWith('error') || m.startsWith('pageerror'))) flags.push('console-error');
  else if (consoleErr.length) info.push('console-warning');
  if ((r.network as string[]).filter(m => !/favicon/i.test(m)).length) flags.push('network-failure');

  const e = c.expect!;
  design.expectedKnots = e.knots;
  design.knots = d.knots;
  if (d.knots !== e.knots) flags.push('design:knot-count');
  if (c.design.customBorder && !e.compatible) {
    design.incompatibleRequest = true;
    if (d.knots === 0 && !d.borderAssets.length && !['flame', 'garber', 'zodiac'].includes(c.order)) info.push('incompatible-no-border-asset');
  }
  if (d.knots > 0 && d.borderAssets.length) { flags.push('design:replaced-border-still-drawn'); design.borderAssets = d.borderAssets; }
  if (d.knots > 0 && e.groundSwap) {
    const stem = e.groundSwap.replace(/\.(jpe?g|png|webp)$/, '');
    if (!d.plateSrcs.some((s: string) => s.includes(stem))) flags.push('design:frameless-ground-missing');
  }
  if (c.design.customBorder && d.knots > 0) {
    const want1 = (e.design as any).color1, want2 = (e.design as any).color2;
    if (!d.knotStyles.every((s: string) => s.includes(`--knot-c1:${want1}`) && s.includes(`--knot-c2:${want2}`))) flags.push('design:knot-colours');
  }
  if (c.design.customRibbon) {
    const want = (e.design as any).ribbonColor;
    design.ribbons = d.ribbons;
    if (d.ribbons.count !== 5 || d.ribbons.filtered !== 5) flags.push('design:ribbon-not-on-all-5');
    if ((d.ribbons.flood ?? '').toUpperCase() !== String(want).toUpperCase()) flags.push('design:ribbon-colour');
  } else if (d.ribbons.count) flags.push('design:unrequested-ribbon');
  const wantH = e.heraldryUrl ? path(e.heraldryUrl) : '';
  const gotH = d.heraldry ? path(d.heraldry.src) : '';
  design.heraldry = { requested: c.design.heraldrySource || '(default)', resolved: e.heraldrySource, fellBack: e.heraldryFellBack, want: wantH, got: gotH };
  if (wantH !== gotH) flags.push('design:heraldry-source');
  if (d.heraldry && d.heraldry.naturalWidth === 0) flags.push('broken-heraldry');
  if (e.heraldryFellBack) info.push('heraldry-fell-back');
  if (r.knotGap && r.knotGap.gap < KNOT_NEAR) flags.push('text-near-knot');
  if (d.knots > 0 && r.safeMargin !== null && r.safeMargin < 0) flags.push('text-outside-safe-box');
  return { flags, info, design };
}

/**
 * Rerun mode: grade the rerun's cases, then compare each with its original in out/combo150/
 * (read only): diff.md plus a before/after sheet per case, in out/combo150-rerun/<tag>/.
 */
function rerunReport() {
  const matrix = JSON.parse(readFileSync(join(COMBO_BASE, 'matrix.json'), 'utf8')) as { seed: number; cases: Case[] };
  const wanted = selectCases(matrix.cases, COMBO_IDS);
  const byId = new Map(wanted.map(c => [c.id, c]));
  const recs: Rec[] = [];
  for (const f of readdirSync(COMBO_OUT).filter(f => /^shard-\d+\.json$/.test(f))) {
    recs.push(...(JSON.parse(readFileSync(join(COMBO_OUT, f), 'utf8')).results as Rec[]));
  }
  const rows = wanted.map(c => {
    const r = recs.find(x => x.id === c.id);
    if (!r) return { id: c.id, n: c.n, error: 'not rendered by the rerun (no record in any shard)', flags: ['render-error'], info: [], summary: c.summary ?? describe(c) } as Rec;
    const g = grade(c, r);
    return { ...r, order: c.order, variant: c.variant, rank: c.rank, summary: c.summary ?? describe(c), flags: g.flags, info: g.info, design: g.design };
  });
  const before = JSON.parse(readFileSync(join(COMBO_BASE, 'results.json'), 'utf8')) as { results: Rec[] };
  const beforeById = new Map(before.results.map(r => [r.id, r]));

  writeFileSync(join(COMBO_OUT, 'results.json'), JSON.stringify({
    seed: matrix.seed, generated: new Date().toISOString(), driver: 'compose', tag: COMBO_TAG, ids: wanted.map(c => c.id),
    scale: '1:1 frame px (2550x3300 CSS px, deviceScaleFactor 1); JPEGs 850px wide',
    thresholds: { ...THRESHOLDS, tinyPt: TINY_PT, knotNearPx: KNOT_NEAR },
    rendered: rows.filter(r => !r.error).length, failed: rows.filter(r => r.error).map(r => r.id), results: rows,
  }, null, 1));

  const sheetsDir = join(COMBO_OUT, 'sheets');
  mkdirSync(sheetsDir, { recursive: true });
  const haveMagick = spawnSync('magick', ['-version']).status === 0;
  const FONT = ['-font', '/System/Library/Fonts/Supplemental/Arial.ttf'];
  const L: string[] = [`# Rerun "${COMBO_TAG}" against out/combo150/`, '',
    `Cases: ${wanted.length}. Before = the original images and results in out/combo150/ (read only); after = this rerun, rendered from the saved matrix.json params. Pixel diff is exact (any channel).`, ''];
  const summaryRows: string[][] = [];
  const detail: string[] = [];
  for (const r of rows) {
    const id = r.id as string;
    const bPng = join(COMBO_BASE, 'images', `${id}.png`), aPng = join(COMBO_OUT, 'images', `${id}.png`);
    const b = beforeById.get(id);
    let diff: PixelDiff | null = null; let why = '';
    if (!existsSync(bPng)) why = 'no original image';
    else if (!existsSync(aPng)) why = 'no rerun image' + (r.error ? ` (${r.error})` : '');
    else diff = diffPng(readFileSync(bPng), readFileSync(aPng));
    const deltas = b && !r.error ? metricDeltas(b, r) : (r.error ? [{ name: 'render error', before: String(b?.error ?? '(none)'), after: String(r.error) }] : []);
    const status = diff ? (diff.changed ? 'changed' : 'unchanged') : 'changed';
    const bb = diff?.bbox ? `${diff.bbox.x},${diff.bbox.y} ${diff.bbox.w}x${diff.bbox.h}` : '-';
    summaryRows.push([id, status + (why ? ` (${why})` : ''), diff ? (diff.fraction * 100).toFixed(4) + '%' : '-', bb, String(deltas.length)]);
    const lines = [`### ${id} — ${status}`, '', `Params: ${r.summary}`, ''];
    if (diff?.changed) {
      lines.push(`- Pixel diff: ${diff.pixels} px (${(diff.fraction * 100).toFixed(4)}% of ${diff.width}x${diff.height}); bounding box x,y w x h = ${bb}${diff.sizeMismatch ? ' (SIZE MISMATCH)' : ''}`);
      lines.push(`- Zoom regions: ${diff.regions.map(g => `${g.x},${g.y} ${g.w}x${g.h}`).join('; ')}`);
    }
    if (why) lines.push(`- ${why}`);
    if (deltas.length) {
      lines.push('', '| metric | before | after | delta |', '| --- | --- | --- | --- |');
      for (const d of deltas) lines.push(`| ${d.name} | ${d.before} | ${d.after} | ${d.delta ?? ''} |`);
    } else if (diff) lines.push('- No metric moved (ink scores, flags, overflow, knot gap, safe margin).');
    lines.push('');
    detail.push(...lines);

    if (haveMagick && diff) {
      const label = [id, r.summary, `${status.toUpperCase()}  diff ${(diff.fraction * 100).toFixed(4)}%`].join('\n').replace(/%/g, '%%');
      const crops: string[] = [];
      const tmp: string[] = [];
      diff.regions.forEach((g: Box, k) => {
        const geo = `${g.w}x${g.h}+${g.x}+${g.y}`;
        const pair = join(sheetsDir, `.${id}-z${k}.png`);
        tmp.push(pair);
        const m = spawnSync('magick', [bPng, '-crop', geo, '+repage', '-bordercolor', '#888', '-border', '2', '(', aPng, '-crop', geo, '+repage', '-bordercolor', '#888', '-border', '2', ')', '+append',
          '-resize', '1400x700>', '-background', '#e8e8e8', ...FONT, '-pointsize', '14', '-gravity', 'north', '-splice', '0x22', '-annotate', '+0+3', `zoom ${k + 1}: ${g.x},${g.y} ${g.w}x${g.h} (before | after)`, pair]);
        if (m.status === 0) crops.push(pair);
      });
      const top = join(sheetsDir, `.${id}-top.png`);
      tmp.push(top);
      const m = spawnSync('magick', [bPng, '-resize', '700x', '(', aPng, '-resize', '700x', ')', '+append', '-background', '#e8e8e8', ...FONT, '-pointsize', '16', '-gravity', 'northwest',
        '-splice', '0x80', '-annotate', '+8+4', label.replace(/%%/g, '%'), '-gravity', 'north', top]);
      if (m.status === 0) {
        const out = join(sheetsDir, `${id}.jpg`);
        const c = spawnSync('magick', [top, ...crops, '-background', '#e8e8e8', '-gravity', 'center', '-append', '-quality', '88', out]);
        if (c.status !== 0) process.stdout.write(`sheet failed for ${id}: ${c.stderr}\n`);
      } else process.stdout.write(`sheet failed for ${id}: ${m.stderr}\n`);
      for (const t of tmp) { try { unlinkSync(t); } catch { /* best effort */ } }
    }
  }
  const unchanged = summaryRows.filter(x => x[1] === 'unchanged').length;
  L.push(`**${unchanged} unchanged, ${summaryRows.length - unchanged} changed.** Sheets: sheets/<id>.jpg (before | after, then zoomed crops of the changed regions).`, '');
  L.push('| case | status | pixel diff | changed bbox (x,y wxh) | metrics moved |', '| --- | --- | --- | --- | --- |');
  for (const x of summaryRows) L.push('| ' + x.join(' | ') + ' |');
  L.push('', ...detail);
  if (!haveMagick) L.push('**ImageMagick was not found: no sheets were written.**', '');
  writeFileSync(join(COMBO_OUT, 'diff.md'), L.join('\n') + '\n');
  process.stdout.write(`combo150 rerun "${COMBO_TAG}": ${unchanged} unchanged, ${summaryRows.length - unchanged} changed -> ${join(COMBO_OUT, 'diff.md')}\n`);
}

test('combo150 report', async () => {
  if (COMBO_RERUN) { rerunReport(); return; }
  const matrix = JSON.parse(readFileSync(join(COMBO_OUT, 'matrix.json'), 'utf8')) as { seed: number; cases: Case[] };
  const byId = new Map(matrix.cases.map(c => [c.id, c]));
  const recs: Rec[] = [];
  for (const f of readdirSync(COMBO_OUT).filter(f => /^shard-\d+\.json$/.test(f))) {
    recs.push(...(JSON.parse(readFileSync(join(COMBO_OUT, f), 'utf8')).results as Rec[]));
  }
  const seen = new Set<string>();
  const rows = recs.filter(r => byId.has(r.id) && !seen.has(r.id) && (seen.add(r.id), true))
    .sort((a, b) => a.n - b.n)
    .map(r => {
      const c = byId.get(r.id)!;
      const g = grade(c, r);
      return { ...r, order: c.order, variant: c.variant, rank: c.rank, summary: c.summary ?? describe(c), flags: g.flags, info: g.info, design: g.design };
    });
  const missing = matrix.cases.filter(c => !seen.has(c.id)).map(c => c.id);

  writeFileSync(join(COMBO_OUT, 'results.json'), JSON.stringify({
    seed: matrix.seed, generated: new Date().toISOString(), driver: 'compose',
    scale: '1:1 frame px (2550x3300 CSS px, deviceScaleFactor 1); JPEGs 850px wide',
    thresholds: { ...THRESHOLDS, tinyPt: TINY_PT, knotNearPx: KNOT_NEAR },
    rendered: rows.filter(r => !r.error).length, failed: rows.filter(r => r.error).map(r => r.id), missing,
    results: rows,
  }, null, 1));

  /* ---- contact sheets, in batch order ---- */
  const bfile = join(COMBO_OUT, 'batches.json');
  const batches = JSON.parse(readFileSync(bfile, 'utf8')) as { batch: number; count: number; ids: string[]; sheets?: string[] }[];
  const sheetsDir = join(COMBO_OUT, 'sheets');
  mkdirSync(sheetsDir, { recursive: true });
  const haveMagick = spawnSync('magick', ['-version']).status === 0;
  let sheetNo = 0;
  const sheetOf = new Map<string, string>();
  for (const b of batches) {
    b.sheets = [];
    for (let i = 0; i < b.ids.length; i += 10) {
      sheetNo++;
      const name = `sheet-${String(sheetNo).padStart(2, '0')}.jpg`;
      b.sheets.push(name);
      const ids = b.ids.slice(i, i + 10);
      ids.forEach(id => sheetOf.set(id, name));
      if (!haveMagick) continue;
      const args: string[] = ['montage', '-font', '/System/Library/Fonts/Supplemental/Arial.ttf', '-pointsize', '15', '-background', '#e8e8e8'];
      for (const id of ids) {
        const c = byId.get(id)!;
        const r = rows.find(x => x.id === id);
        const jpg = join(COMBO_OUT, 'images', `${id}.jpg`);
        const s = (c.summary ?? describe(c)).split(' · ');
        const label = [id, ...s, r?.error ? 'RENDER FAILED' : ''].filter(Boolean).join('\n').replace(/%/g, '%%');
        args.push('-label', label, existsSync(jpg) ? jpg : 'xc:#ffdddd[850x1100]');
      }
      args.push('-tile', '5x2', '-geometry', '700x906+10+10', join(sheetsDir, name));
      const m = spawnSync('magick', args, { encoding: 'utf8' });
      if (m.status !== 0) process.stdout.write(`montage failed for ${name}: ${m.stderr}\n`);
    }
  }
  writeFileSync(bfile, JSON.stringify(batches, null, 1));

  /* ---- summary.md ---- */
  const ok = rows.filter(r => !r.error);
  const L: string[] = [];
  const tbl = (head: string[], body: (string | number)[][]) => {
    L.push('| ' + head.join(' | ') + ' |', '|' + head.map(() => ' --- ').join('|') + '|');
    for (const b of body) L.push('| ' + b.map(x => String(x).replace(/\|/g, '\\|')).join(' | ') + ' |');
    L.push('');
  };
  L.push('# Scrollery 150-combination sweep', '');
  L.push(`Seed ${matrix.seed}. Driver: compose (Scrollery/compose, real heraldry, logged in as an admin). `
    + `${ok.length} rendered, ${rows.length - ok.length} failed, ${missing.length} missing. Captured at 1:1 frame pixels `
    + `(the certificate's own 2550x3300 CSS px, deviceScaleFactor 1); images/<id>.png is full size, images/<id>.jpg is 850px wide. `
    + `Contact sheets are 10 per sheet in review-batch order (batches.json lists each batch's sheets).`, '');
  if (!haveMagick) L.push('**ImageMagick was not found: no JPEGs or contact sheets were written.**', '');

  /* axis counts */
  const count = (f: (c: Case) => string) => {
    const m = new Map<string, number>();
    for (const c of matrix.cases) m.set(f(c), (m.get(f(c)) ?? 0) + 1);
    return [...m.entries()].sort((a, b) => b[1] - a[1]).map(([k, v]) => `${k} ${v}`).join(', ');
  };
  L.push('## Coverage (cases per axis level)', '');
  const axes: [string, (c: Case) => string][] = [
    ['order/variant', c => c.order + (c.variant ? '/' + c.variant : '')],
    ['rank', c => String(c.rank)],
    ['custom recipe', c => c.recipe?.label ?? '(not custom)'],
    ['scope', c => c.scope.label],
    ['issuer tier', c => c.issuer.scope + ':' + c.issuer.tier],
    ['issuer title', c => c.issuer.issuerTitle || '(none)'],
    ['recipient name', c => c.player.tier],
    ['recipient record', c => String(c.playerId)],
    ['reason', c => c.reason.tier],
    ['given by', c => c.givenBy.tier],
    ['date', c => c.date.tier],
    ['hand', c => c.hand],
    ['heading', c => c.heading],
    ['signature hand', c => c.sig],
    ['design mode', c => c.design.mode],
    ['heraldry request', c => c.design.heraldrySource || '(default)'],
    ['colour pair (border cases)', c => c.design.colourTier || '-'],
    ['ribbon colour (ribbon cases)', c => c.design.ribbonColor || '-'],
    ['Custom Border on an incompatible order', c => c.design.customBorder ? (c.expect!.compatible ? 'compatible' : 'INCOMPATIBLE') : '-'],
    ['expected knots', c => String(c.expect!.knots)],
  ];
  tbl(['axis', 'levels'], axes.map(([n, f]) => [n, count(f)]));

  /* flags */
  const flagCount = new Map<string, string[]>();
  const infoCount = new Map<string, string[]>();
  for (const r of rows) {
    for (const f of r.flags) flagCount.set(f, [...(flagCount.get(f) ?? []), r.id]);
    for (const f of r.info) infoCount.set(f, [...(infoCount.get(f) ?? []), r.id]);
  }
  L.push('## Automated flags', '');
  L.push('Thresholds: ink pairs as lib/report.ts THRESHOLDS (C∩A 300, C∩B 500, C∩C 95 weighted ink px); '
    + `tiny type < ${TINY_PT}pt (frame px x 72/300); text near knot < ${KNOT_NEAR} frame px (line boxes, so conservative); `
    + 'text-outside-safe-box = painted text outside x 235–2315 on a knot certificate.', '');
  if (!flagCount.size) L.push('No flags.', '');
  tbl(['flag', 'cases', 'ids'], [...flagCount.entries()].sort((a, b) => b[1].length - a[1].length).map(([f, ids]) => [f, ids.length, ids.join(', ')]));
  L.push('### Informational (not defects by themselves)', '');
  tbl(['note', 'cases', 'ids'], [...infoCount.entries()].sort((a, b) => b[1].length - a[1].length).map(([f, ids]) => [f, ids.length, ids.join(', ')]));

  /* detail of the flagged cases */
  L.push('### Flag detail', '');
  for (const r of rows.filter(x => x.flags.length)) {
    const bits: string[] = [];
    if (r.error) bits.push('error: ' + r.error);
    else {
      const d = r.dom;
      if (d.clipped.length) bits.push('clipped: ' + d.clipped.map((x: any) => `${x.block} by ${x.by}px "${x.text}"`).join('; '));
      if (d.outside.length) bits.push('outside: ' + d.outside.map((x: any) => x.block).join(', '));
      if (d.lineOver.length) bits.push('line over width: ' + d.lineOver.map((x: any) => `${x.role} ${x.w}>${x.allowed}`).join('; '));
      const ov = Object.entries(d.roles).filter(([, v]: [string, any]) => v.overflow).map(([k]) => k);
      if (ov.length) bits.push('engine overflow: ' + ov.join(', '));
      if (r.flags.includes('tiny-type')) bits.push('min pt: ' + Object.entries(d.roles).filter(([, v]: [string, any]) => v.text && v.minPt < TINY_PT).map(([k, v]: [string, any]) => `${k} ${v.minPt}`).join(', '));
      if (r.flags.some((f: string) => f.startsWith('ink:'))) bits.push(`ink px CA ${r.ink.px.CA} CB ${r.ink.px.CB} CC ${r.ink.px.CC} (pair ${r.ink.ccPair || '-'}) worst run ${Math.max(r.ink.CA.worstRun, r.ink.CB.worstRun, r.ink.CC.worstRun)}`);
      if (r.flags.includes('text-near-knot')) bits.push(`knot gap ${r.knotGap.gap}px (${r.knotGap.block})`);
      if (r.flags.includes('text-outside-safe-box')) bits.push(`safe margin ${r.safeMargin}px (${d.extents.minXBlock} / ${d.extents.maxXBlock})`);
      if (r.flags.some((f: string) => f.startsWith('design:'))) bits.push('design: ' + JSON.stringify(r.design));
      if (d.broken.length) bits.push('broken: ' + d.broken.join(', '));
      if (r.flags.includes('console-error')) bits.push('console: ' + r.console.join(' / '));
      if (r.flags.includes('network-failure')) bits.push('network: ' + r.network.join(' / '));
    }
    L.push(`- **${r.id}** [${r.flags.join(', ')}] — ${bits.join(' · ')} (sheet ${sheetOf.get(r.id) ?? '?'})`);
  }
  L.push('');

  /* worst 25 per metric */
  const top = (title: string, val: (r: Rec) => number | null, dir: 'desc' | 'asc', fmt: (r: Rec) => string) => {
    const list = ok.map(r => ({ r, v: val(r) })).filter(x => x.v !== null && Number.isFinite(x.v as number))
      .sort((a, b) => dir === 'desc' ? (b.v as number) - (a.v as number) : (a.v as number) - (b.v as number)).slice(0, 25);
    L.push(`### ${title}`, '');
    tbl(['#', 'case', 'value', 'detail', 'sheet'], list.map((x, i) => [i + 1, x.r.id, x.v as number, fmt(x.r), sheetOf.get(x.r.id) ?? '']));
  };
  L.push('## The 25 worst cases by each metric', '');
  top('Text over artwork (C∩A weighted ink px; threshold 300)', r => r.ink.px.CA, 'desc', r => `worst run ${r.ink.CA.worstRun}, top-half ${r.ink.px.CAtop}`);
  top('Text over ornament (C∩B; threshold 500)', r => r.ink.px.CB, 'desc', r => `worst run ${r.ink.CB.worstRun}, top-half ${r.ink.px.CBtop}`);
  top('Text over text (C∩C; threshold 95)', r => r.ink.px.CC, 'desc', r => `blocks ${r.ink.ccPair || '-'}, worst run ${r.ink.CC.worstRun}`);
  top('Distance from type to the knot (frame px, smallest)', r => r.knotGap?.gap ?? null, 'asc', r => `${r.knotGap?.block ?? ''}, ${r.dom.knots} knot(s)`);
  top('Margin to the 235–2315 safe box (frame px, smallest; all cases)', r => r.safeMargin, 'asc', r => `${r.dom.extents?.minXBlock} / ${r.dom.extents?.maxXBlock}; knots ${r.dom.knots}`);
  top('Smallest type anywhere (pt)', r => {
    const v = Object.values(r.dom.roles).filter((x: any) => x.text).map((x: any) => x.minPt as number);
    return v.length ? Math.min(...v) : null;
  }, 'asc', r => Object.entries(r.dom.roles).filter(([, v]: [string, any]) => v.text).sort((a: any, b: any) => a[1].minPt - b[1].minPt).slice(0, 2).map(([k, v]: [string, any]) => `${k} ${v.minPt}pt`).join(', '));
  top('Smallest recipient name (pt)', r => r.dom.roles.name?.text ? r.dom.roles.name.minPt : null, 'asc', r => `"${r.dom.roles.name?.text?.slice(0, 40)}" lines ${r.dom.roles.name?.lines ?? '?'}`);
  top('Smallest issuer place (pt)', r => r.dom.roles.place?.text ? r.dom.roles.place.minPt : null, 'asc', r => `"${r.dom.roles.place?.text?.slice(0, 40)}" comp ${r.dom.comp}`);
  top('Smallest citation (pt)', r => r.dom.roles.reason?.text ? r.dom.roles.reason.minPt : null, 'asc', r => `${r.dom.roles.reason?.text?.length ?? 0} chars`);
  top('Render time to settled (ms, largest)', r => r.ms.settled, 'desc', r => `load ${r.ms.load}, engine ${r.ms.engine}`);
  top('Typeset engine time (data-sc-ms, largest)', r => r.ms.engine, 'desc', r => `comp ${r.dom.comp}, budget ${r.dom.budget}`);

  /* engine + timing roll-up */
  const comps = new Map<string, number>();
  for (const r of ok) comps.set(`${r.dom.heading}->${r.dom.comp}`, (comps.get(`${r.dom.heading}->${r.dom.comp}`) ?? 0) + 1);
  L.push('## Typeset engine', '');
  L.push('Heading requested -> composition used: ' + [...comps.entries()].map(([k, v]) => `${k} ${v}`).join(', ') + '.', '');
  const pct = (xs: number[], p: number) => { const s = xs.slice().sort((a, b) => a - b); return s[Math.min(s.length - 1, Math.floor(p * s.length))]; };
  const settled = ok.map(r => r.ms.settled as number);
  const eng = ok.map(r => r.ms.engine as number).filter(x => Number.isFinite(x));
  if (settled.length) L.push(`Render to settled: p50 ${pct(settled, 0.5)} ms, p95 ${pct(settled, 0.95)} ms, max ${Math.max(...settled)} ms. Engine (data-sc-ms): p50 ${pct(eng, 0.5)}, p95 ${pct(eng, 0.95)}, max ${Math.max(...eng)}.`, '');
  if (missing.length) L.push('## Missing', '', missing.join(', '), '');
  writeFileSync(join(COMBO_OUT, 'summary.md'), L.join('\n') + '\n');
});
