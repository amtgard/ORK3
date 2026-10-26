import { test, expect, type Page } from '@playwright/test';
import { existsSync, mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { loadCorpus, renderFixtures, serveFixtures, openFixture, type FixtureSpec } from './lib/fixture';
import { drawSample } from './lib/sample';
import {
  q1Hierarchy, q2PlannedIsPrinted, q3Width, q4LeadNeverWraps, q7Parity, q8TextModel, q9Budget, q11Performance, renderGates,
  type CaseInfo, type CaseMeasure, type GateResult,
} from './lib/gates';

/*
 * Render a corpus of certificates and write down what the layout did with each one.
 *
 *   ORK3_CORPUS      corpus file under tools/scroll-sweep/corpus/ (default review-2026-10-25)
 *   ORK3_CORPUS_TAG  output directory name under tools/scroll-sweep/out/ (default corpus)
 *   ORK3_CORPUS_PAGES  pages captured at once (default 3)
 *   ORK3_CORPUS_SEED   draw a random sample as well (see lib/sample.ts) and grade it with the corpus
 *   ORK3_CORPUS_COUNT  how many cases to draw (default 50)
 *   ORK3_CORPUS_SAMPLE_ONLY=1  grade the drawn sample alone, without the frozen corpus
 *   ORK3_CORPUS_REPLAY=<tag>   take the sample from out/<tag>/specs.json instead of the database
 *   ORK3_CORPUS_FULL=all       keep the full-page PNG of every case (default: failing cases only)
 *   ORK3_CORPUS_FULL_LIMIT=20  most full pages kept for failing cases (corpus order); a build with
 *                              no engine fails every case, and 54 of them is 800MB
 *   ORK3_CORPUS_FORCED=0       skip the forced-composition renders Q4 also reads
 *
 * It writes, per case, a crop of its top half (<id>-top.png), then report.json, report.md,
 * gates.json and index.html (a contact sheet of the crops). The whole certificate
 * (<id>.png) is kept only for a case that fails a gate: at 3x a full page is about 15MB,
 * and the corpus would otherwise be most of a gigabyte per run.
 *
 * Capturing and judging are separate tests. The capture test writes down what the layout did
 * (so the same report can be taken before a change and after it, and the two compared) and
 * the gate tests, one per gate of spec 10.2, read that report back off the disk and grade it.
 * They read the disk, not a variable, because Playwright starts a new worker after a failing
 * test and a failing gate must not take the ones after it with it.
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

/* Both become path segments (a file under corpus/, a directory under out/) and the run
   deletes PNGs in the second, so they are checked here, before anything touches the disk.
   No leading dot: `..` would otherwise name the directory above. */
const SAFE_SEGMENT = /^[A-Za-z0-9][A-Za-z0-9._-]*$/;
const safe = (name: string, value: string): string => {
  if (!SAFE_SEGMENT.test(value)) throw new Error(`${name} must match ${SAFE_SEGMENT}: ${JSON.stringify(value)}`);
  return value;
};

const CORPUS = safe('ORK3_CORPUS', process.env.ORK3_CORPUS ?? 'review-2026-10-25');
const TAG = safe('ORK3_CORPUS_TAG', process.env.ORK3_CORPUS_TAG ?? 'corpus');
const PAGES = Math.max(1, Number(process.env.ORK3_CORPUS_PAGES ?? 3));
const OUT = join(__dirname, 'out', TAG);
const FIXTURES = join(__dirname, 'out', 'fixtures');
const FROZEN = 'review-2026-10-25';
const BEFORE_FILE = join(__dirname, 'baseline', 'corpus-before-2026-10-25.json');
const SEED = process.env.ORK3_CORPUS_SEED === undefined || process.env.ORK3_CORPUS_SEED === '' ? undefined : Number(process.env.ORK3_CORPUS_SEED);
const COUNT = Number(process.env.ORK3_CORPUS_COUNT ?? 50);
const SAMPLE_ONLY = process.env.ORK3_CORPUS_SAMPLE_ONLY === '1';
const REPLAY = process.env.ORK3_CORPUS_REPLAY === undefined || process.env.ORK3_CORPUS_REPLAY === '' ? undefined : safe('ORK3_CORPUS_REPLAY', process.env.ORK3_CORPUS_REPLAY);
const FULL_ALL = process.env.ORK3_CORPUS_FULL === 'all';
const FULL_LIMIT = Math.max(0, Number(process.env.ORK3_CORPUS_FULL_LIMIT ?? 20));
const FORCED = process.env.ORK3_CORPUS_FORCED !== '0';
const FORCED_TAG = `${TAG}-forced`;
const COMPOSITIONS = ['classic', 'centered'] as const;

/** Frame px are 1/300 inch on a letter certificate, so a frame px is 72/300 pt. */
const PT_PER_PX = 72 / 300;

type Role = 'name' | 'award' | 'place' | 'lead';
const ROLES: Record<Role, string> = {
  name: '[data-scroll-text="player"]',
  award: '.Order',
  place: '[data-scroll-text="issuerName"]',
  lead: '#IssuerTitle',
};

type RoleReport = { sizeEm: number; sizePt: number; lines: number; planned?: number; engineLines?: number; overflow?: boolean };
type CaseReport = CaseMeasure & {
  roles: Partial<Record<Role, RoleReport>>;
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
    const printed = el.getAttribute('data-sc-lines');
    if (printed !== null && Number.isFinite(Number(printed))) rep.engineLines = Number(printed);
    if (el.hasAttribute('data-sc-overflow')) rep.overflow = true;
    roles[role as Role] = rep;
  }

  const out: Measured = { rootEm: r3(awardPx / 100), roles, cropBottomFrame: 0 };

  /* The versal is the "Amtgard" span. Its size is read the same way as a role's. */
  const versal = document.querySelector('.ScVersal, .IssuingTextSection > p:first-of-type > span:first-child');
  if (versal) out.versalEm = r3(parseFloat(getComputedStyle(versal).fontSize) / awardPx);

  /* Q3. Frame px. An inline span has no scrollWidth, so fall back to its painted width. */
  const lines = Array.from(document.querySelectorAll<HTMLElement>('.sc-line'));
  out.scLineCount = lines.length;
  const wide: NonNullable<Measured['wideLines']> = [];
  for (const ln of lines) {
    const w = Math.max(ln.scrollWidth, ln.offsetWidth) || ln.getBoundingClientRect().width / k;
    const raw = ln.getAttribute('data-sc-w');
    const allowed = raw !== null && Number.isFinite(Number(raw)) ? Number(raw) : null;
    if (allowed === null || w > allowed + 0.5) {
      wide.push({ role: ln.closest('[data-sc-role]')?.getAttribute('data-sc-role') ?? '?', w: r3(w), allowed });
    }
  }
  if (wide.length) out.wideLines = wide;

  /* Q8. The issuer text as it is rendered: the lead, the place, and the two read as one. */
  const textOf = (el: Element | null): string => {
    if (!el) return '';
    const parts = Array.from(el.querySelectorAll('.sc-line'));
    const raw = parts.length ? parts.map(p => p.textContent ?? '').join(' ') : el.textContent ?? '';
    return raw.replace(/\s+/g, ' ').trim();
  };
  const leadText = textOf(document.querySelector(args.roles.lead));
  const placeText = textOf(document.querySelector(args.roles.place));
  out.issuerText = [leadText, placeText, `${leadText} ${placeText}`.trim()];
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

async function capture(page: Page, spec: FixtureSpec, opts: { shots: boolean }): Promise<CaseReport> {
  await openFixture(page, spec);
  await hideChrome(page);
  const m = await page.evaluate(measure, { roles: ROLES, ptPerPx: PT_PER_PX });
  const { cropBottomFrame, ...rest } = m;
  if (opts.shots) await shoot(page, spec.id, cropBottomFrame, FULL_ALL);
  return { id: spec.id, ...rest };
}

/** The top-half crop for the contact sheet, and the whole certificate when asked for. */
async function shoot(page: Page, id: string, cropBottomFrame: number, full: boolean): Promise<void> {
  const svg = page.locator('svg[data-scroll-frame]').first();
  if (full) await svg.screenshot({ path: join(OUT, `${id}.png`) });
  const box = await svg.boundingBox();
  if (!box) throw new Error('certificate has no box');
  await page.screenshot({
    path: join(OUT, `${id}-top.png`),
    clip: { x: box.x, y: box.y, width: box.width, height: Math.min(box.height, (box.width / 2550) * cropBottomFrame) },
  });
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

function writeReport(specs: FixtureSpec[], reports: CaseReport[], gates: GateResult[]): void {
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
    '## Gates (spec 10.2)',
    '',
    '```',
    renderGates(gates),
    '```',
    '',
  ].join('\n');
  writeFileSync(join(OUT, 'report.md'), md);

  const byId = new Map(reports.map(r => [r.id, r]));
  const failing = failingGates(gates);
  const figures = specs.map(c => {
    const r = byId.get(c.id)!;
    const fails = failing.get(c.id) ?? [];
    const cap = caption(c);
    return `<figure data-find="${esc((cap + (fails.length ? ' fail ' + fails.join(' ') : '')).toLowerCase())}">
<img loading="lazy" src="${esc(c.id)}-top.png" alt="${esc(cap)}">
<figcaption><strong>${esc(cap)}</strong><br>${r.error ? `<em>ERROR ${esc(r.error)}</em>` : esc(sizes(r))}${r.comp ? ' · ' + esc(r.comp) : ''}${fails.length ? `<br><em>fails ${esc(fails.join(', '))}</em>` : ''}</figcaption>
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
<input id="find" type="search" placeholder="Filter by id, hand, order, issuer, title, or fail" aria-label="Filter cases"></header>
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

/* ---------------------------------------------------------------------------------------
 * The run, as written to disk by the capture test and read back by everything after it.
 * ------------------------------------------------------------------------------------- */

type RunCase = FixtureSpec & { group: string };
type Run = {
  corpus: string;
  tag: string;
  seed?: number;
  count?: number;
  cases: RunCase[];
  /** Pass-3 case ids of the frozen corpus before the engine, when this run includes it. */
  before: string[] | null;
};
type Forced = Record<string, CaseReport[]>;

/** The cases this run grades: the frozen corpus (unless SAMPLE_ONLY), then the drawn sample. */
function buildRun(): { run: Run; sample?: FixtureSpec[] } {
  const cases: RunCase[] = [];
  let before: string[] | null = null;
  if (!(SAMPLE_ONLY && SEED !== undefined)) {
    for (const c of loadCorpus(CORPUS)) cases.push({ ...c, group: CORPUS });
    if (CORPUS === FROZEN) {
      // Q9's pass-3 comparison is against this file; without it the check would quietly not run.
      if (!existsSync(BEFORE_FILE)) throw new Error(`the frozen corpus needs its before-report for Q9, and ${BEFORE_FILE} is missing`);
      const was = JSON.parse(readFileSync(BEFORE_FILE, 'utf8')) as Array<{ id: string; rootEm?: number }>;
      before = was.filter(r => r.rootEm !== undefined && r.rootEm < 0.999).map(r => r.id);
    }
  }
  let sample: FixtureSpec[] | undefined;
  if (SEED !== undefined || REPLAY !== undefined) {
    if (REPLAY !== undefined) {
      const file = join(__dirname, 'out', REPLAY, 'specs.json');
      sample = (JSON.parse(readFileSync(file, 'utf8')) as { cases: FixtureSpec[] }).cases;
    } else {
      sample = drawSample(SEED as number, COUNT);
    }
    for (const c of sample) cases.push({ ...c, group: REPLAY !== undefined ? `replay-${REPLAY}` : `sample-${SEED}` });
  }
  const ids = new Set<string>();
  for (const c of cases) {
    if (ids.has(c.id)) throw new Error(`two cases are called ${c.id}; a corpus and a sample cannot share ids`);
    ids.add(c.id);
  }
  return { run: { corpus: CORPUS, tag: TAG, seed: SEED, count: sample ? sample.length : undefined, cases, before }, sample };
}

const readJson = <T>(name: string): T => JSON.parse(readFileSync(join(OUT, name), 'utf8')) as T;

function loadRun(): { run: Run; reports: CaseReport[]; forced: Forced } {
  if (!existsSync(join(OUT, 'report.json'))) throw new Error(`no ${join(OUT, 'report.json')}: the capture test has not run for tag ${TAG}`);
  return {
    run: readJson<Run>('run.json'),
    reports: readJson<CaseReport[]>('report.json'),
    forced: existsSync(join(OUT, 'forced.json')) ? readJson<Forced>('forced.json') : {},
  };
}

type GateName = 'Q1' | 'Q2' | 'Q3' | 'Q4' | 'Q7' | 'Q8' | 'Q9' | 'Q11';

function grade(run: Run, reports: CaseReport[], forced: Forced): Record<GateName, GateResult> {
  const info = new Map(run.cases.map(c => [c.id, c]));
  const cases: CaseMeasure[] = reports.map(r => ({ ...r, up: info.get(r.id)?.up, hasIssuer: (info.get(r.id)?.issuerName ?? '').trim() !== '' }));
  const infos: CaseInfo[] = run.cases.map(c => ({ id: c.id, scope: c.scope, up: c.up, group: c.group }));
  const before = run.before ? { ids: run.before, scope: run.cases.filter(c => c.group === run.corpus).map(c => c.id) } : undefined;
  return {
    Q1: q1Hierarchy(cases),
    Q2: q2PlannedIsPrinted(cases),
    Q3: q3Width(cases),
    Q4: q4LeadNeverWraps(cases, forced),
    Q7: q7Parity(cases, infos),
    Q8: q8TextModel(cases),
    Q9: q9Budget(cases, before),
    Q11: q11Performance(cases),
  };
}

/** case id -> the gates it offends, read off the offender lines ("c05: ...", "c05 [classic forced]: ..."). */
function failingGates(results: GateResult[]): Map<string, string[]> {
  const out = new Map<string, string[]>();
  for (const r of results) {
    for (const o of r.offenders) {
      const id = /^([A-Za-z0-9][A-Za-z0-9._-]*)(?=[: ])/.exec(o)?.[1];
      if (!id) continue;
      const list = out.get(id) ?? [];
      if (!list.includes(r.gate)) list.push(r.gate);
      out.set(id, list);
    }
  }
  return out;
}

const gateList = (g: Record<GateName, GateResult>): GateResult[] => [g.Q1, g.Q2, g.Q3, g.Q4, g.Q7, g.Q8, g.Q9, g.Q11];

/* ---------------------------------------------------------------------------------------
 * Tests
 * ------------------------------------------------------------------------------------- */

test('capture and report the corpus', async ({ context }) => {
  const { run, sample } = buildRun();
  const specs: FixtureSpec[] = run.cases.map(({ group: _g, ...c }) => c);
  mkdirSync(OUT, { recursive: true });
  for (const f of readdirSync(OUT)) {
    if (/\.png$/.test(f)) rmSync(join(OUT, f), { force: true });
  }
  rmSync(join(OUT, 'forced.json'), { force: true });
  // An unseeded run must not leave a sample's specs beside its own report.
  if (!sample) rmSync(join(OUT, 'specs.json'), { force: true });
  // Written before anything renders, so a seed that finds a failure can be replayed from this file.
  if (sample) {
    writeFileSync(
      join(OUT, 'specs.json'),
      JSON.stringify({ _comment: `The sample drawn by lib/sample.ts for seed ${SEED}, ${sample.length} cases. Replay it with ORK3_CORPUS_REPLAY=${TAG}.`, seed: SEED, count: sample.length, cases: sample }, null, 1),
    );
  }
  writeFileSync(join(OUT, 'run.json'), JSON.stringify(run, null, 1));
  const dir = renderFixtures(specs, TAG);

  const run1 = async (specList: FixtureSpec[], fixtureDir: string, shots: boolean): Promise<Map<string, CaseReport>> => {
    const byId = new Map<string, CaseReport>();
    const queue = specList.slice();
    const worker = async () => {
      const page = await context.newPage();
      await serveFixtures(page, fixtureDir);
      for (let spec = queue.shift(); spec; spec = queue.shift()) {
        try {
          byId.set(spec.id, await capture(page, spec, { shots }));
        } catch (e) {
          byId.set(spec.id, { id: spec.id, roles: {}, error: String((e as Error).message ?? e).split('\n')[0] });
        }
      }
      await page.close();
    };
    await Promise.all(Array.from({ length: Math.min(PAGES, specList.length) }, worker));
    return byId;
  };

  const byId = await run1(specs, dir, true);
  // Corpus order, so two reports of the same corpus diff cleanly.
  const reports = specs.map(s => byId.get(s.id)!);

  // Q4 asks about the lead in both compositions, and `auto` picks only one per case, so the
  // same cases are rendered once more with each heading forced. Measured, not photographed.
  const forced: Forced = {};
  if (FORCED) {
    for (const comp of COMPOSITIONS) {
      const forcedSpecs = specs.map(c => ({ ...c, id: `${c.id}-${comp}`, heading: comp }));
      const forcedById = await run1(forcedSpecs, renderFixtures(forcedSpecs, `${FORCED_TAG}-${comp}`), false);
      forced[comp] = specs.map(c => ({ ...forcedById.get(`${c.id}-${comp}`)!, id: c.id }));
    }
    writeFileSync(join(OUT, 'forced.json'), JSON.stringify(forced));
  }

  const gates = grade(run, reports, forced);
  writeFileSync(join(OUT, 'gates.json'), JSON.stringify(gates, null, 1));
  writeReport(specs, reports, gateList(gates));

  // Capture integrity only: that every case produced a measurement. Whether the numbers
  // are good is for the gate tests.
  expect(reports.filter(r => r.error).map(r => `${r.id}: ${r.error}`), 'cases that could not be measured').toEqual([]);
  for (const [comp, list] of Object.entries(forced)) {
    expect(list.filter(r => r.error).map(r => `${r.id}: ${r.error}`), `${comp} renders that could not be measured`).toEqual([]);
  }
});

/* The gates read what `measure` read, so check that it reads the engine's attributes: put
   them on a real page by hand and see them come out the other side and trip the right gates.
   (Without an engine in the build, nothing else here would ever exercise those branches.) */
test('the capture reads the engine attributes it will be graded on', async ({ page }) => {
  const spec = loadCorpus(FROZEN).find(c => c.id === 'c22')!;
  await serveFixtures(page, renderFixtures([spec], `${TAG}-selftest`));
  await openFixture(page, spec);
  // Once the engine is wired the page has `.sc-line` elements of its own; count them so the
  // test adds to them rather than assuming there are none.
  const own = await page.locator('.sc-line').count();
  await page.evaluate(() => {
    const award = document.querySelector('.Award') as HTMLElement;
    award.setAttribute('data-sc-budget', 'floor');
    award.setAttribute('data-sc-ms', '12.5');
    const name = document.querySelector('[data-scroll-text="player"]') as HTMLElement;
    name.setAttribute('data-sc-planned', '7');
    name.setAttribute('data-sc-lines', '7');
    (document.querySelector('.Order') as HTMLElement).setAttribute('data-sc-overflow', '');
    // One line too wide for its width, one with no width at all.
    for (const w of ['10', null]) {
      const line = document.createElement('span');
      line.className = 'sc-line';
      line.style.cssText = 'display:inline-block;white-space:nowrap';
      if (w !== null) line.setAttribute('data-sc-w', w);
      line.textContent = 'a line of text well over ten pixels wide';
      name.appendChild(line);
    }
  });
  const m = await page.evaluate(measure, { roles: ROLES, ptPerPx: PT_PER_PX });
  expect(m.budget).toBe('floor');
  expect(m.ms).toBe(12.5);
  expect(m.roles.name).toMatchObject({ planned: 7, engineLines: 7 });
  expect(m.roles.award?.overflow).toBe(true);
  expect(m.scLineCount).toBe(own + 2);
  expect(m.wideLines?.map(l => l.allowed)).toEqual(expect.arrayContaining([10, null]));
  expect(m.versalEm).toBeGreaterThan(1);
  expect(m.issuerText?.[2]).toMatch(/^and /);

  const c: CaseMeasure[] = [{ id: 'c22', ...m }];
  expect(q2PlannedIsPrinted(c).offenders[0]).toMatch(/name: planned 7, engine says 7, painted \d/);
  expect(q3Width(c).ok).toBe(true); // award is flagged overflow, so Q3 stands down for the case
  const unflagged = { ...c[0], roles: { ...c[0].roles, award: { ...c[0].roles.award!, overflow: false } } };
  expect(q3Width([unflagged]).offenders[0]).toMatch(/^c22: .*name line [\d.]+px > 10px; name line [\d.]+px has no data-sc-w/);
  expect(q9Budget(c).offenders[0]).toMatch(/^c22: budget at the floor; data-sc-overflow on .*award/);
  expect(q11Performance(c).ok).toBe(true);
});

/* One test per gate. Each fails with its offenders, and every one of them reads the report
   off the disk, so one gate failing does not stop the next. The rules are in lib/gates.ts. */
const GATES: Array<[GateName, string]> = [
  ['Q1', 'Q1 hierarchy: award, place and lead stay below the name, and the versal is clamped'],
  ['Q2', 'Q2 planned = printed: the engine planned, reported and painted the same line count'],
  ['Q3', 'Q3 width: every line is within its allowed width'],
  ['Q4', 'Q4 lead never wraps, in either composition'],
  ['Q7', 'Q7 parity: kingdom and park place sizes within 20% (median)'],
  ['Q8', 'Q8 text model: no "of The" in any rendered issuer line'],
  ['Q9', 'Q9 budget: nothing at the floor or overflowing, and no more pass-3 cases than before'],
  ['Q11', 'Q11 performance: p95 layout time within 20ms'],
];
for (const [name, title] of GATES) {
  test(title, () => {
    const { run, reports, forced } = loadRun();
    const g = grade(run, reports, forced)[name];
    // The summary rides along as the message, so the number behind a pass or a fail is in the output.
    expect(g.offenders, `${g.gate} ${g.title}: ${g.summary}`).toEqual([]);
  });
}

/* The whole certificate of every case that failed a gate, and nothing else: these are the
   pictures someone has to look at, and the rest would be most of a gigabyte. The first
   ORK3_CORPUS_FULL_LIMIT of them, in corpus order: a build with no engine fails them all. */
test('keep the full page of every failing case', async ({ context }) => {
  const { run, reports, forced } = loadRun();
  if (FULL_ALL) return; // the capture test already kept every one
  const failing = failingGates(gateList(grade(run, reports, forced)));
  const failed = run.cases.filter(c => failing.has(c.id));
  const keep = failed.slice(0, FULL_LIMIT);
  if (failed.length > keep.length) console.warn(`${failed.length} cases fail; full pages kept for the first ${keep.length} (ORK3_CORPUS_FULL_LIMIT)`);
  if (!keep.length) return;
  const page = await context.newPage();
  await serveFixtures(page, join(FIXTURES, TAG));
  for (const spec of keep) {
    try {
      await openFixture(page, spec);
      await hideChrome(page);
      await page.locator('svg[data-scroll-frame]').first().screenshot({ path: join(OUT, `${spec.id}.png`) });
    } catch (e) {
      console.warn(`full page of ${spec.id}: ${String((e as Error).message ?? e).split('\n')[0]}`);
    }
  }
  await page.close();
});
