import { test, expect, type Page } from '@playwright/test';
import { mkdirSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { loadCorpus, renderFixtures, serveFixtures, openFixture, type FixtureSpec } from './lib/fixture';

/*
 * Render a corpus of certificates and write down what the layout did with each one.
 *
 *   ORK3_CORPUS      corpus file under tools/scroll-sweep/corpus/ (default review-2026-10-25)
 *   ORK3_CORPUS_TAG  output directory name under tools/scroll-sweep/out/ (default corpus)
 *   ORK3_CORPUS_PAGES  pages captured at once (default 3)
 *
 * It writes, per case, the whole certificate and a crop of its top half (<id>.png and
 * <id>-top.png), then report.json, report.md and index.html (a contact sheet of the crops).
 * This file only captures and reports. Judging the numbers is a separate step, so that the
 * same report can be taken before a change and after it and the two compared.
 *
 * WHAT IS MEASURED, AND WHY HERE
 *
 * Every size is read off the TEXT element of its role, never an inner wrapper, because
 * that element's computed font-size is the size that prints both before the typeset
 * engine exists and after it. Sizes are em of `.Award`'s own font-size (so a pass-3
 * root scale cannot hide inside them) and rootEm records that scale on its own.
 *
 * Line counts are measured here, from where the text is actually painted, and are never
 * read back from the engine: a count the engine reports about itself can only ever agree
 * with its plan. The engine's own attributes (data-sc-*) are recorded beside them, when
 * the page has them, so the two can be compared.
 */

const CORPUS = process.env.ORK3_CORPUS ?? 'review-2026-10-25';
const TAG = process.env.ORK3_CORPUS_TAG ?? 'corpus';
const PAGES = Math.max(1, Number(process.env.ORK3_CORPUS_PAGES ?? 3));
const OUT = join(__dirname, 'out', TAG);

/** Frame px are 1/300 inch on a letter certificate, so a frame px is 72/300 pt. */
const PT_PER_PX = 72 / 300;

type Role = 'name' | 'award' | 'place' | 'lead';
const ROLES: Record<Role, string> = {
  name: '[data-scroll-text="player"]',
  award: '.Order',
  place: '[data-scroll-text="issuerName"]',
  lead: '#IssuerTitle',
};

type RoleReport = { sizeEm: number; sizePt: number; lines: number; planned?: number; overflow?: boolean };
type CaseReport = {
  id: string;
  comp?: string;
  rootEm?: number;
  roles: Partial<Record<Role, RoleReport>>;
  budget?: string;
  ms?: number;
  /** Set only when the case could not be rendered or measured; every other field is then absent. */
  error?: string;
};

type Measured = Omit<CaseReport, 'id' | 'error'> & { cropBottomFrame: number };

/** Runs in the page. Nothing here may close over anything outside it. */
function measure(args: { roles: Record<string, string>; ptPerPx: number }): Measured {
  const r3 = (n: number) => Math.round(n * 1000) / 1000;
  const award = document.querySelector('.Award') as HTMLElement | null;
  const frame = document.querySelector('svg[data-scroll-frame]') as SVGElement | null;
  if (!award || !frame) throw new Error('no certificate on the page');
  const awardPx = parseFloat(getComputedStyle(award).fontSize);
  const k = frame.getBoundingClientRect().width / 2550;

  /* Count painted lines: cluster the bottoms of the text's client rects. One rect per text
     node per line, so a span of smaller type on a line (the article) sits slightly higher
     than its neighbours and must not count as a line of its own; half a line of the rect's
     own height is the tolerance, in rect units so it scales with the type. */
  function countLines(el: Element): number {
    const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    const rects: { b: number; h: number }[] = [];
    for (let n = walker.nextNode(); n; n = walker.nextNode()) {
      if (!/\S/.test((n as Text).data)) continue;
      const range = document.createRange();
      range.selectNodeContents(n);
      for (const q of Array.from(range.getClientRects())) {
        if (q.width > 0.5 && q.height > 0.5) rects.push({ b: q.bottom, h: q.height });
      }
    }
    if (!rects.length) return 0;
    rects.sort((a, b) => a.b - b.b);
    let lines = 1;
    let last = rects[0].b;
    for (const q of rects.slice(1)) {
      if (q.b - last > 0.5 * q.h) lines++;
      last = q.b;
    }
    return lines;
  }

  const roles: Measured['roles'] = {};
  for (const [role, sel] of Object.entries(args.roles)) {
    const el = document.querySelector(sel);
    if (!el) continue;
    const px = parseFloat(getComputedStyle(el).fontSize);
    const rep: RoleReport = { sizeEm: r3(px / awardPx), sizePt: Math.round(px * args.ptPerPx * 100) / 100, lines: countLines(el) };
    const planned = el.getAttribute('data-sc-planned');
    if (planned !== null && Number.isFinite(Number(planned))) rep.planned = Number(planned);
    if (el.hasAttribute('data-sc-overflow')) rep.overflow = true;
    roles[role as Role] = rep;
  }

  const out: Measured = { rootEm: r3(awardPx / 100), roles, cropBottomFrame: 0 };
  const comp = award.getAttribute('data-sc-comp') ?? document.querySelector('.IssuingTextSection')?.getAttribute('data-sc-comp');
  if (comp) out.comp = comp;
  const budget = award.getAttribute('data-sc-budget');
  if (budget) out.budget = budget;
  const ms = award.getAttribute('data-sc-ms');
  if (ms !== null && Number.isFinite(Number(ms))) out.ms = Number(ms);

  const order = document.querySelector('.Order');
  const bottom = order ? (order.getBoundingClientRect().bottom - frame.getBoundingClientRect().top) / k : 1800;
  out.cropBottomFrame = Math.min(3300, Math.round(bottom + 80));
  return out;
}

/** The toolbar and notices are page chrome. Hide them so a screenshot is the certificate alone. */
async function hideChrome(page: Page): Promise<void> {
  await page.addStyleTag({ content: '.sc-toolbar,.sc-advisory,.sc-clip-notice{display:none!important}' });
}

async function capture(page: Page, spec: FixtureSpec): Promise<CaseReport> {
  await openFixture(page, spec);
  await hideChrome(page);
  const m = await page.evaluate(measure, { roles: ROLES, ptPerPx: PT_PER_PX });
  const { cropBottomFrame, ...rest } = m;

  const svg = page.locator('svg[data-scroll-frame]').first();
  await svg.screenshot({ path: join(OUT, `${spec.id}.png`) });
  const box = await svg.boundingBox();
  if (!box) throw new Error('certificate has no box');
  await page.screenshot({
    path: join(OUT, `${spec.id}-top.png`),
    clip: { x: box.x, y: box.y, width: box.width, height: Math.min(box.height, (box.width / 2550) * cropBottomFrame) },
  });
  return { id: spec.id, ...rest };
}

const esc = (s: string) => s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c] as string));

function caption(c: FixtureSpec): string {
  const issuer = [c.issuerTitle, c.useOf ? 'of' : '', c.useThe ? 'the' : '', c.issuerName].filter(Boolean).join(' ');
  return `${c.id} ${c.hand} | ${c.order} r${c.rank}${c.variant ? ' ' + c.variant : ''} | ${issuer} | ${c.player.length}ch | ${c.title}`;
}

function sizes(r: CaseReport): string {
  const parts = (['lead', 'place', 'name', 'award'] as Role[]).map(role => {
    const x = r.roles[role];
    return x ? `${role} ${x.sizePt.toFixed(1)}pt${x.lines > 1 ? ` x${x.lines}` : ''}` : `${role} -`;
  });
  return parts.join(' · ') + (r.rootEm !== undefined && r.rootEm < 0.999 ? ` · root ${r.rootEm}` : '');
}

function writeReport(specs: FixtureSpec[], reports: CaseReport[]): void {
  writeFileSync(join(OUT, 'report.json'), JSON.stringify(reports, null, 1));

  const ok = reports.filter(r => !r.error);
  const ids = (xs: CaseReport[]) => (xs.length ? ' ' + xs.map(r => r.id).join(', ') : '');
  const lineBuckets = (['name', 'award', 'place', 'lead'] as Role[]).map(role => {
    const n = (pred: (l: number) => boolean) => ok.filter(r => r.roles[role] && pred(r.roles[role]!.lines)).length;
    const multi = ok.filter(r => (r.roles[role]?.lines ?? 0) > 1).map(r => `${r.id} (${r.roles[role]!.lines})`);
    return `| ${role} | ${n(l => l === 1)} | ${n(l => l === 2)} | ${n(l => l >= 3)} | ${ok.filter(r => !r.roles[role]).length} | ${multi.join(', ')} |`;
  });
  const comps = new Map<string, number>();
  for (const r of ok) if (r.comp) comps.set(r.comp, (comps.get(r.comp) ?? 0) + 1);
  const scaled = ok.filter(r => r.rootEm !== undefined && r.rootEm < 0.999);
  const flagged = ok.filter(r => Object.values(r.roles).some(x => x?.overflow));
  const floored = ok.filter(r => r.budget === 'floor');
  const mismatch = ok.filter(r => Object.values(r.roles).some(x => x && x.planned !== undefined && x.planned !== x.lines));
  const ms = ok.map(r => r.ms).filter((x): x is number => x !== undefined).sort((a, b) => a - b);

  const md = [
    `# Corpus report: ${CORPUS} (${TAG})`,
    '',
    `Cases ${specs.length} · measured ${ok.length} · errors ${reports.length - ok.length}${ids(reports.filter(r => r.error))}`,
    '',
    '| role | 1 line | 2 lines | 3+ lines | no element | multi-line cases |',
    '|---|---|---|---|---|---|',
    ...lineBuckets,
    '',
    `Pass 3 fired (root scale below 1): ${scaled.length}${ids(scaled)}`,
    '',
    `Composition: ${comps.size ? [...comps].map(([k, v]) => `${k} ${v}`).join(', ') : 'not reported by this build'}`,
    `Overflow flagged: ${flagged.length}${ids(flagged)}`,
    `Budget at the floor: ${floored.length}${ids(floored)}`,
    `Planned line count differs from the measured one: ${mismatch.length}${ids(mismatch)}`,
    `Layout time (ms): ${ms.length ? `median ${ms[Math.floor(ms.length / 2)]}, p95 ${ms[Math.min(ms.length - 1, Math.ceil(ms.length * 0.95) - 1)]}` : 'not reported by this build'}`,
    '',
  ].join('\n');
  writeFileSync(join(OUT, 'report.md'), md);

  const byId = new Map(reports.map(r => [r.id, r]));
  const figures = specs.map(c => {
    const r = byId.get(c.id)!;
    const cap = caption(c);
    return `<figure data-find="${esc(cap.toLowerCase())}">
<img loading="lazy" src="${esc(c.id)}-top.png" alt="${esc(cap)}">
<figcaption><strong>${esc(cap)}</strong><br>${r.error ? `<em>ERROR ${esc(r.error)}</em>` : esc(sizes(r))}${r.comp ? ' · ' + esc(r.comp) : ''}</figcaption>
</figure>`;
  });
  writeFileSync(join(OUT, 'index.html'), `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Corpus ${esc(CORPUS)} (${esc(TAG)})</title>
<style>
:root { color-scheme: light dark; }
body { font: 14px/1.4 system-ui, sans-serif; margin: 16px; }
header { position: sticky; top: 0; padding: 8px 0; background: Canvas; display: flex; gap: 12px; align-items: baseline; flex-wrap: wrap; }
input { font: inherit; padding: 4px 8px; min-width: 18em; }
main { display: grid; grid-template-columns: repeat(auto-fill, minmax(520px, 1fr)); gap: 16px; }
figure { margin: 0; }
figure img { width: 100%; height: auto; display: block; border: 1px solid #8884; background: #fff; }
figcaption { font-size: 12px; padding-top: 4px; overflow-wrap: anywhere; }
</style></head><body>
<header><h1 style="margin:0;font-size:18px">${esc(CORPUS)} (${esc(TAG)})</h1><span>${specs.length} cases</span>
<input id="find" type="search" placeholder="Filter by id, hand, order, issuer, title" aria-label="Filter cases"></header>
<main>
${figures.join('\n')}
</main>
<script>
document.getElementById('find').addEventListener('input', function (e) {
  var q = e.target.value.toLowerCase();
  document.querySelectorAll('figure').forEach(function (f) { f.hidden = q !== '' && f.dataset.find.indexOf(q) < 0; });
});
</script>
</body></html>
`);
}

test('capture and report the corpus', async ({ context }) => {
  const specs = loadCorpus(CORPUS);
  mkdirSync(OUT, { recursive: true });
  for (const f of readdirSync(OUT)) {
    if (/\.png$/.test(f)) rmSync(join(OUT, f), { force: true });
  }
  const dir = renderFixtures(specs, TAG);

  const byId = new Map<string, CaseReport>();
  const queue = specs.slice();
  const worker = async () => {
    const page = await context.newPage();
    await serveFixtures(page, dir);
    for (let spec = queue.shift(); spec; spec = queue.shift()) {
      try {
        byId.set(spec.id, await capture(page, spec));
      } catch (e) {
        byId.set(spec.id, { id: spec.id, roles: {}, error: String((e as Error).message ?? e).split('\n')[0] });
      }
    }
    await page.close();
  };
  await Promise.all(Array.from({ length: Math.min(PAGES, specs.length) }, worker));

  // Corpus order, so two reports of the same corpus diff cleanly.
  const reports = specs.map(s => byId.get(s.id)!);
  writeReport(specs, reports);

  // Capture integrity only: that every case produced a measurement. Whether the numbers
  // are good is for whoever reads the report.
  expect(reports.filter(r => r.error).map(r => `${r.id}: ${r.error}`), 'cases that could not be measured').toEqual([]);
});
