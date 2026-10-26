import { test, expect, type Page } from '@playwright/test';
import { readFileSync, writeFileSync } from 'node:fs';
import { loadCorpus, renderFixtures, serveFixtures, openFixture, type FixtureSpec } from '../lib/fixture';
import { PROFILES } from '../lib/text';

/*
 * The typeset engine on real certificates: the 54-case review corpus, rendered by the app's own
 * renderer and fitted by the page's own scripts (the engine, then scrollery-autofit.js, which
 * calls it). It needs the app up and no login. The engine's pure core is tested in
 * typeset-core.spec.ts and its DOM layer on synthetic pages in typeset-dom-synthetic*.spec.ts;
 * this file is the one that says whether the two work together on what ships.
 *
 * Every assertion reads the DOM (computed styles, painted rects, decoded ink grids), never the
 * engine's Report, so an engine that mislabelled its own work would not pass. The data-sc-*
 * attributes are read only where the test is about the attribute itself (planned versus printed
 * is the engine's claim, checked against what is painted).
 *
 * The 54 cases are opened once, in one page, and measured into a table; the tests read that
 * table. Cases the corpus does not have (an empty profile, a runic persona, a forced heading, a
 * warrior at rank 8 under the `real` text profile) are rendered beside them under id `v-*`.
 */

const CORPUS = 'review-2026-10-25';
const TAG = 'typeset-dom';

const corpus = loadCorpus(CORPUS);
const base = (id: string): FixtureSpec => {
  const s = corpus.find(c => c.id === id);
  if (!s) throw new Error(`no corpus case ${id}`);
  return s;
};

const NOKREN = 'Nokren ᚨᛒᛟᛟᚹᚺᛟᚷᛟᛞ';

/* The text of PROFILES.real (the longest real values in the ORK) on a park-issued warrior. */
const realFields = (): Partial<FixtureSpec> => {
  const r = PROFILES.real;
  return {
    player: r.player, reason: r.reason, givenBy: r.givenBy, date: r.date,
    issuerName: r.issuerName, issuerTitle: r.issuerTitle, useThe: r.useThe, useOf: r.useOf,
  };
};

const VARIANTS: FixtureSpec[] = [
  { ...base('c22'), id: 'v-empty', player: '', issuerName: '', issuerTitle: '', title: '', reason: '', givenBy: '', date: '', useThe: false, useOf: false },
  { ...base('c02'), id: 'v-nokren', player: NOKREN, hand: 'uncial' },
  { ...base('c05'), id: 'v-up2', up: 2 },
  { ...base('c22'), id: 'v-centered', heading: 'centered' },
  { ...base('c14'), id: 'v-classic', heading: 'classic' },
  { ...base('c22'), ...realFields(), id: 'v-w8real', rank: 8, scope: 'park' },
  { ...base('c22'), id: 'v-halo', player: 'Halo' },
];

const ROLES = ['name', 'award', 'place', 'lead'] as const;
type RoleName = (typeof ROLES)[number];

type RoleRead = {
  /** Computed font size of the role element, in em of `.Award`'s own. */
  em: number;
  attrSize: number | null;
  lines: number | null;
  planned: number | null;
  painted: number;
  overflow: boolean;
  lineTexts: string[];
};

type Read = {
  id: string;
  error?: string;
  awardPx: number;
  scError: string | null;
  comp: string | null;
  sectionComp: string | null;
  budget: string | null;
  ms: number | null;
  hasIssuer: boolean;
  roles: Partial<Record<RoleName, RoleRead>>;
  versalEm: number | null;
  wide: Array<{ role: string; w: number; allowed: number | null }>;
  scLines: number;
  display: { top: string; right: string; bottom: string; ruleL: string; ruleR: string };
  /** Ink cells of the right flourish inside a place line grown by ORN_GAP; null when hidden. */
  rightInk: { inked: number; hits: number } | null;
  ruleInk: { inked: number; hits: number } | null;
  art: { count: number; px: number | null; leadPx: number | null };
  /** Classic: the connective shares the versal's row (its vertical extent overlaps the versal's by over half). */
  leadOnVersalRow: boolean | null;
  stack: number | null;
  content: number | null;
  below: number | null;
  idempotent: boolean;
  idemDiff: string | null;
};

/** Runs in the page. Nothing here may close over anything outside it. */
function collect(): Omit<Read, 'id' | 'idempotent' | 'idemDiff'> {
  const award = document.querySelector<HTMLElement>('.Award')!;
  const frame = document.querySelector('svg[data-scroll-frame]') as SVGElement | null;
  const awardPx = parseFloat(getComputedStyle(award).fontSize);
  const k = (frame ? frame.getBoundingClientRect().width : award.getBoundingClientRect().width) / 2550;
  const r3 = (n: number) => Math.round(n * 1000) / 1000;
  const num = (v: string | null) => (v === null || v === '' ? null : Number(v));

  const SEL: Record<string, string> = {
    name: '[data-sc-role="name"]', award: '[data-sc-role="award"]', place: '[data-sc-role="place"]', lead: '[data-sc-role="lead"]',
  };

  /* Painted lines: cluster the bottoms of the text's client rects, tolerance half a rect. */
  function painted(el: Element): number {
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
    let last = rects[0];
    for (const q of rects.slice(1)) {
      if (q.b - last.b > Math.min(q.h, last.h) / 2) { lines++; last = q; }
    }
    return lines;
  }

  const roles: Partial<Record<RoleName, RoleRead>> = {};
  for (const role of ['name', 'award', 'place', 'lead'] as RoleName[]) {
    const el = document.querySelector<HTMLElement>(SEL[role]);
    if (!el) continue;
    roles[role] = {
      em: r3(parseFloat(getComputedStyle(el).fontSize) / awardPx),
      attrSize: num(el.getAttribute('data-sc-size')),
      lines: num(el.getAttribute('data-sc-lines')),
      planned: num(el.getAttribute('data-sc-planned')),
      painted: painted(el),
      overflow: el.hasAttribute('data-sc-overflow'),
      lineTexts: Array.from(el.querySelectorAll('.sc-line')).map(l => l.textContent || ''),
    };
  }

  const wide: Read['wide'] = [];
  const lineEls = Array.from(award.querySelectorAll<HTMLElement>('.sc-line'));
  for (const l of lineEls) {
    const allowed = l.getAttribute('data-sc-w') === null ? null : Number(l.getAttribute('data-sc-w'));
    if (allowed === null || l.scrollWidth > allowed + 0.5) {
      const host = l.closest('[data-sc-role]');
      wide.push({ role: host ? host.getAttribute('data-sc-role')! : '?', w: r3(l.scrollWidth), allowed });
    }
  }

  const disp = (sel: string) => {
    const el = document.querySelector(sel);
    return el ? getComputedStyle(el).display : 'absent';
  };

  /* The independent check of spec 6.6: decode the grid, map its cells through the image's own
     painted box (getBoundingClientRect over the frame scale, never offsetLeft), and test every
     inked cell against every place line's text rectangles grown by ORN_GAP. */
  const a = award.getBoundingClientRect();
  const gap = 0.12 * awardPx;
  /* An inked cell may sit up to 1px inside the grown box before it counts. The engine lets a placement
     overlap by 0.5px (its own tolerance, less than a thousandth of an inch at 300dpi), and a cell's
     edge landing exactly on that line is decided by floating point, so a tolerance of exactly 0.5 here
     fails or passes on the last bit. */
  const TOL = 1;
  function boxesOf(sel: string): number[][] {
    const out: number[][] = [];
    document.querySelectorAll(sel).forEach(l => {
      const rg = document.createRange();
      rg.selectNodeContents(l);
      Array.from(rg.getClientRects()).forEach(q => {
        if (q.width > 0.5 && q.height > 0.5) out.push([(q.left - a.left) / k, (q.top - a.top) / k, (q.right - a.left) / k, (q.bottom - a.top) / k]);
      });
    });
    return out;
  }
  function inkVs(img: HTMLElement, boxes: number[][]): { inked: number; hits: number } {
    const r = img.getBoundingClientRect();
    const g = (img.getAttribute('data-sc-ink') || '').slice(6);
    const m = /^matrix\(([^)]+)\)$/.exec(getComputedStyle(img).transform);
    const flipped = !!m && parseFloat(m[1].split(',')[0]) < 0;
    let inked = 0;
    let hits = 0;
    for (let row = 0; row < 32; row++) {
      const v = parseInt(g.substr(row * 8, 8), 16);
      for (let c = 0; c < 32; c++) {
        if (!((v >>> (31 - c)) & 1)) continue;
        inked++;
        const cc = flipped ? 31 - c : c;
        const x0 = (r.left - a.left + (r.width * cc) / 32) / k;
        const x1 = (r.left - a.left + (r.width * (cc + 1)) / 32) / k;
        const y0 = (r.top - a.top + (r.height * row) / 32) / k;
        const y1 = (r.top - a.top + (r.height * (row + 1)) / 32) / k;
        if (boxes.some(b => x0 < b[2] + gap - TOL && x1 > b[0] - gap + TOL && y0 < b[3] + gap - TOL && y1 > b[1] - gap + TOL)) hits++;
      }
    }
    return { inked, hits };
  }

  const placeBoxes = boxesOf(SEL.place);
  const rightImg = document.querySelector<HTMLElement>('#RightTextFlourish');
  const rightInk = rightImg && getComputedStyle(rightImg).display !== 'none' && rightImg.getAttribute('data-sc-ink')
    ? inkVs(rightImg, placeBoxes) : null;
  let ruleInk: Read['ruleInk'] = null;
  for (const sel of ['.ScRule--l', '.ScRule--r']) {
    const img = document.querySelector<HTMLElement>(sel);
    if (img && getComputedStyle(img).display !== 'none' && img.getAttribute('data-sc-ink')) {
      const v = inkVs(img, placeBoxes);
      ruleInk = ruleInk ? { inked: ruleInk.inked + v.inked, hits: ruleInk.hits + v.hits } : v;
    }
  }

  const artEls = document.querySelectorAll('.sc-art');
  const art0 = artEls[0] as HTMLElement | undefined;
  const lead = document.querySelector<HTMLElement>(SEL.lead);

  /* The budget, measured from the finished layout. The stack runs from the top of the issuing
     section to the bottom of the award line. What the page needs beneath it is the sum of the
     outer heights of what follows, with the elastic spacer at its min-height and the bare spacer
     divs at nothing, as spec 6.7 reads. */
  const topChild = (el: Element | null): HTMLElement | null => {
    let n = el as HTMLElement | null;
    while (n && n.parentElement !== award) n = n.parentElement;
    return n;
  };
  const absTop = (el: HTMLElement): number => {
    let y = 0;
    let n: HTMLElement | null = el;
    while (n && n !== award) { y += n.offsetTop; n = n.offsetParent as HTMLElement | null; }
    return y;
  };
  const firstEl = topChild(document.querySelector(SEL.lead) || document.querySelector(SEL.name));
  const lastEl = topChild(document.querySelector(SEL.award));
  let stack: number | null = null;
  let below: number | null = null;
  if (firstEl && lastEl) {
    stack = absTop(lastEl) + lastEl.offsetHeight - absTop(firstEl);
    below = 0;
    for (let el = lastEl.nextElementSibling as HTMLElement | null; el; el = el.nextElementSibling as HTMLElement | null) {
      const cs = getComputedStyle(el);
      const mar = parseFloat(cs.marginTop) + parseFloat(cs.marginBottom);
      if (el.classList.contains('ScSpacer')) below += (parseFloat(cs.minHeight) || 0.3 * parseFloat(cs.fontSize)) + mar;
      else if (el.tagName === 'DIV' && !el.className && !el.children.length && !(el.textContent || '').trim()) continue;
      else below += el.offsetHeight + mar;
    }
  }
  const acs = getComputedStyle(award);

  /* Does the connective sit on the versal's row? The two are rects of text, so compare vertical extents. */
  let leadOnVersalRow: boolean | null = null;
  const versalEl = document.querySelector('.ScVersal');
  if (versalEl && lead && lead.textContent && lead.textContent.trim()) {
    const ext = (el: Element): [number, number] | null => {
      const rg = document.createRange();
      rg.selectNodeContents(el);
      const rs = Array.from(rg.getClientRects()).filter(q => q.width > 0.5 && q.height > 0.5);
      return rs.length ? [Math.min(...rs.map(q => q.top)), Math.max(...rs.map(q => q.bottom))] : null;
    };
    const vTxt = Array.from(versalEl.childNodes).find(n => n.nodeType === 3 && /\S/.test((n as Text).data));
    const rg = document.createRange();
    if (vTxt) rg.selectNodeContents(vTxt);
    const vr = vTxt ? Array.from(rg.getClientRects())[0] : null;
    const le = ext(lead);
    if (vr && le) {
      const overlap = Math.min(vr.bottom, le[1]) - Math.max(vr.top, le[0]);
      leadOnVersalRow = overlap > 0.5 * (le[1] - le[0]);
    }
  }

  return {
    awardPx,
    scError: award.getAttribute('data-sc-error'),
    comp: award.getAttribute('data-sc-comp'),
    sectionComp: document.querySelector('section.IssuingTextSection')?.getAttribute('data-sc-comp') ?? null,
    budget: award.getAttribute('data-sc-budget'),
    ms: num(award.getAttribute('data-sc-ms')),
    hasIssuer: !!document.querySelector(SEL.place),
    roles,
    versalEm: document.querySelector('.ScVersal') ? r3(parseFloat(getComputedStyle(document.querySelector('.ScVersal')!).fontSize) / awardPx) : null,
    wide,
    scLines: lineEls.length,
    display: {
      top: disp('#TopTextFlourish'), right: disp('#RightTextFlourish'), bottom: disp('#BottomTextFlourish'),
      ruleL: disp('.ScRule--l'), ruleR: disp('.ScRule--r'),
    },
    rightInk,
    ruleInk,
    art: {
      count: artEls.length,
      px: art0 ? parseFloat(getComputedStyle(art0).fontSize) : null,
      leadPx: lead ? parseFloat(getComputedStyle(lead).fontSize) : null,
    },
    leadOnVersalRow,
    stack,
    content: award.clientHeight - parseFloat(acs.paddingTop) - parseFloat(acs.paddingBottom),
    below,
  };
}

/** Measure the open page, then fit it again and say whether anything changed. */
async function read(page: Page, id: string): Promise<Read> {
  const first = await page.evaluate(collect);
  const idem = await page.evaluate(() => {
    const award = document.querySelector<HTMLElement>('.Award')!;
    // data-sc-ms is how long the layout took, which is the one thing a second run may change.
    const strip = (h: string) => h.replace(/ data-sc-ms="[^"]*"/g, '');
    const before = strip(award.outerHTML);
    (window as any).ScrolleryAutofit.fit(document);
    const after = strip(award.outerHTML);
    if (before === after) return null;
    let i = 0;
    while (i < before.length && before[i] === after[i]) i++;
    return `...${before.slice(Math.max(0, i - 60), i + 80)}  =>  ${after.slice(Math.max(0, i - 60), i + 80)}`;
  });
  return { id, ...first, idempotent: idem === null, idemDiff: idem };
}

let dirOnce: string | null = null;
function fixtureDir(): string {
  if (!dirOnce) dirOnce = renderFixtures([...corpus, ...VARIANTS], TAG);
  return dirOnce;
}

/* ORK3_TYPESET_JS and ORK3_AUTOFIT_JS=<file> serve that file in place of scrollery-typeset.js or
   scrollery-autofit.js, to try a change before it is made or to run the suite against the script as it
   was (the synthetic specs take the first variable too). */
async function serve(page: Page): Promise<void> {
  await serveFixtures(page, fixtureDir());
  for (const [env, re] of [['ORK3_TYPESET_JS', /scrollery-typeset\.js(\?|$)/], ['ORK3_AUTOFIT_JS', /scrollery-autofit\.js(\?|$)/]] as const) {
    const alt = process.env[env];
    if (alt) await page.route(re, route => route.fulfill({ status: 200, contentType: 'text/javascript', body: readFileSync(alt, 'utf8') }));
  }
}

async function open(page: Page, spec: FixtureSpec): Promise<void> {
  await serve(page);
  await openFixture(page, spec);
}

let table: Map<string, Read> | null = null;
async function survey(page: Page): Promise<Map<string, Read>> {
  if (table) return table;
  test.setTimeout(600_000);
  const m = new Map<string, Read>();
  await serve(page);
  /* ORK3_TYPESET_ONLY=c03,c05 measures just those, for looking at a case without waiting for all 60. */
  const only = process.env.ORK3_TYPESET_ONLY?.split(',');
  for (const spec of [...corpus, ...VARIANTS].filter(c => !only || only.includes(c.id))) {
    try {
      await openFixture(page, spec);
      m.set(spec.id, await read(page, spec.id));
    } catch (e) {
      m.set(spec.id, { id: spec.id, error: String((e as Error).message) } as Read);
    }
  }
  table = m;
  /* ORK3_TYPESET_DUMP=<file> writes the whole table, for looking at a failure without re-running it. */
  if (process.env.ORK3_TYPESET_DUMP) writeFileSync(process.env.ORK3_TYPESET_DUMP, JSON.stringify([...m.values()], null, 1));
  return m;
}

const CORPUS_IDS = corpus.map(c => c.id);
const rows = async (page: Page): Promise<Read[]> => {
  const t = await survey(page);
  return CORPUS_IDS.map(id => t.get(id)!);
};
const get = async (page: Page, id: string): Promise<Read> => (await survey(page)).get(id)!;

/* Engine constants, copied from spec 6.4.1; the floors lift at up=2|4 (FLOOR_SCALE). */
const RANGE = {
  name: { min: 0.8, max: 2.3 }, award: { min: 0.7, max: 1.5 }, place: { min: 0.62, max: 1.3 }, lead: { min: 0.7, max: 1.04 },
};
const FLOOR_SCALE = 1.5;
const minOf = (role: RoleName, up?: number) => (up && up >= 2 ? Math.min(RANGE[role].max, RANGE[role].min * FLOOR_SCALE) : RANGE[role].min);
const EPS = 0.0015;
const clamp = (x: number, lo: number, hi: number) => Math.min(hi, Math.max(lo, x));
const upOf = (id: string): number | undefined => ([...corpus, ...VARIANTS].find(c => c.id === id) as FixtureSpec).up;
const issuerCases = (rs: Read[]) => rs.filter(r => !r.error && r.hasIssuer);

test('every case rendered, was fitted by the engine, and had no engine error', async ({ page }) => {
  const rs = await rows(page);
  const bad = rs.filter(r => r.error || r.scError || r.ms === null || r.comp === null).map(r => `${r.id}: ${r.error ?? r.scError ?? 'no data-sc-ms or data-sc-comp'}`);
  expect(bad).toEqual([]);
  const v = await survey(page);
  expect([...v.values()].filter(r => r.error).map(r => `${r.id}: ${r.error}`)).toEqual([]);
});

/* ---------------------------------------------------------------------------------------- */
/* Task 10: names and awards                                                                */
/* ---------------------------------------------------------------------------------------- */

const T10_IDS = ['c03', 'c05', 'c14', 'c16', 'c22', 'c27', 'c31', 'c46'];

test('planned equals printed, and every line fits', async ({ page }) => {
  const t = await survey(page);
  const bad: string[] = [];
  // The eight named cases and every other certificate besides: nothing is exempt.
  for (const r of [...t.values()]) {
    // v-empty has no text to plan; its test below checks that it renders blanks and reports nothing.
    if (r.error || r.id === 'v-empty') continue;
    const why: string[] = [];
    for (const role of ROLES) {
      const x = r.roles[role];
      if (!x) continue;
      if (x.planned === null || x.lines === null) why.push(`${role}: no data-sc-planned/lines`);
      else if (x.planned !== x.lines || x.lines !== x.painted) why.push(`${role}: planned ${x.planned}, engine ${x.lines}, painted ${x.painted}`);
    }
    // A role the engine flagged overflow at its floor may legitimately be wide; it said so itself.
    const flagged = new Set(ROLES.filter(role => r.roles[role]?.overflow));
    const wide = r.wide.filter(w => !flagged.has(w.role as RoleName));
    if (wide.length) why.push(`wide: ${wide.map(w => `${w.role} ${w.w}>${w.allowed}`).join(', ')}`);
    if (why.length) bad.push(`${r.id}: ${why.join('; ')}`);
  }
  expect(bad).toEqual([]);
  for (const id of T10_IDS) {
    const r = t.get(id)!;
    expect(r.roles.name, id).toBeTruthy();
    expect(r.roles.award, id).toBeTruthy();
    expect(r.scLines, id).toBeGreaterThan(0);
  }
});

test('c14 prints the award on one line', async ({ page }) => {
  const r = await get(page, 'c14');
  expect(r.roles.award!.lines).toBe(1);
  expect(r.roles.award!.painted).toBe(1);
});

test('c27 never splits "Billy Bob"', async ({ page }) => {
  const r = await get(page, 'c27');
  const lines = r.roles.name!.lineTexts;
  expect(lines.length).toBeGreaterThan(0);
  for (const l of lines) {
    const opens = l.includes('"Billy');
    const closes = l.includes('Bob"');
    expect(opens === closes, `quote split across lines: ${JSON.stringify(lines)}`).toBe(true);
  }
});

test('c46 name takes at most two lines', async ({ page }) => {
  const r = await get(page, 'c46');
  expect(r.roles.name!.painted).toBeLessThanOrEqual(2);
});

test('hierarchy: the award never outranks the name', async ({ page }) => {
  const bad: string[] = [];
  for (const r of (await rows(page)).filter(x => !x.error)) {
    const n = r.roles.name?.em, a = r.roles.award?.em;
    if (n === undefined || a === undefined) { bad.push(`${r.id}: missing name or award`); continue; }
    const lim = 0.85 * n;
    if (a > lim + EPS && a > minOf('award', upOf(r.id)) + EPS) bad.push(`${r.id}: award ${a} > 0.85 x name ${n}`);
  }
  expect(bad).toEqual([]);
});

test('fitting twice changes nothing', async ({ page }) => {
  const t = await survey(page);
  const bad = [...t.values()].filter(r => !r.error && !r.idempotent).map(r => `${r.id}: ${r.idemDiff}`);
  expect(bad).toEqual([]);
  // And in the same page, from the outside: the same call twice, the whole .Award compared.
  await open(page, base('c22'));
  const same = await page.evaluate(() => {
    const A = (window as any).ScrolleryAutofit, el = document.querySelector('.Award')!;
    const strip = (h: string) => h.replace(/ data-sc-ms="[^"]*"/g, '');
    A.fit(document);
    const one = strip(el.outerHTML);
    A.fit(document);
    return one === strip(el.outerHTML);
  });
  expect(same).toBe(true);
});

test('a textContent write re-lays out from the new text', async ({ page }) => {
  // c06 has room: its name reaches name.max, so the size the write must reach is the table's own.
  await open(page, base('c06'));
  const r = await page.evaluate(() => {
    const span = document.querySelector<HTMLElement>('[data-sc-role="name"]')!;
    span.textContent = 'Halo';
    (window as any).ScrolleryAutofit.fit(document);
    const root = parseFloat(getComputedStyle(document.querySelector('.Award')!).fontSize);
    return {
      lines: Array.from(span.querySelectorAll('.sc-line')).map(l => l.textContent),
      em: parseFloat(getComputedStyle(span).fontSize) / root,
      planned: span.getAttribute('data-sc-planned'),
    };
  });
  expect(r.lines).toEqual(['Halo']);
  expect(r.em).toBeCloseTo(RANGE.name.max, 2);
  expect(r.planned).toBe('1');
});

test('a textContent write equals a fresh render of that text', async ({ page }) => {
  // The composer types into a preview the server rendered for another name; the print surface renders the
  // name server-side. The server's first-guess sizes differ between the two (they are keyed on the name's
  // length), so the markup is not byte-for-byte equal and the comparison is of what was laid out: every
  // role's painted size, lines and widths, the composition, and where each flourish ended up.
  const summary = () => {
    const award = document.querySelector<HTMLElement>('.Award')!;
    const roles = Array.from(award.querySelectorAll<HTMLElement>('[data-sc-role]')).map(el => [
      el.getAttribute('data-sc-role'), Math.round(parseFloat(getComputedStyle(el).fontSize)), el.getAttribute('data-sc-size'), el.getAttribute('data-sc-lines'),
      el.getAttribute('data-sc-planned'), el.hasAttribute('data-sc-overflow'),
      Array.from(el.querySelectorAll('.sc-line')).map(l => [l.textContent, l.getAttribute('data-sc-w')]),
    ]);
    const flourishes = Array.from(award.querySelectorAll<HTMLElement>('img.Flourish')).map(i => [i.id || i.className, i.getAttribute('style')]);
    const section = award.querySelector<HTMLElement>('section.IssuingTextSection')!;
    return {
      roles, flourishes, comp: award.getAttribute('data-sc-comp'), budget: award.getAttribute('data-sc-budget'),
      vars: [section.style.getPropertyValue('--sc-place-gutter'), section.style.getPropertyValue('--sc-place-reserve')],
      versal: Math.round(parseFloat(getComputedStyle(award.querySelector('.ScVersal')!).fontSize)),
    };
  };
  await open(page, base('c22'));
  const written = await page.evaluate(([fn]) => {
    document.querySelector<HTMLElement>('[data-sc-role="name"]')!.textContent = 'Halo';
    (window as any).ScrolleryAutofit.fit(document);
    return (0, eval)(`(${fn})`)();
  }, [summary.toString()]);
  await open(page, VARIANTS.find(v => v.id === 'v-halo')!);
  const rendered = await page.evaluate(([fn]) => (0, eval)(`(${fn})`)(), [summary.toString()]);
  expect(written).toEqual(rendered);
});

test('an empty profile renders blanks and reports no overflow', async ({ page }) => {
  const r = await get(page, 'v-empty');
  expect(r.error).toBeUndefined();
  expect(r.scError).toBeNull();
  expect(r.ms).not.toBeNull();
  await open(page, VARIANTS.find(v => v.id === 'v-empty')!);
  const m = await page.evaluate(() => ({
    overflow: document.querySelectorAll('.Award [data-sc-overflow]').length,
    nameBlank: !!document.querySelector('[data-sc-role="name"] img.Underline'),
    nameLines: document.querySelectorAll('[data-sc-role="name"] .sc-line').length,
    wide: Array.from(document.querySelectorAll<HTMLElement>('.sc-line')).filter(l => l.scrollWidth > Number(l.dataset.scW) + 0.5).length,
  }));
  expect(m.overflow).toBe(0);
  expect(m.nameBlank).toBe(true);
  expect(m.nameLines).toBe(0);
  expect(m.wide).toBe(0);
});

test('fallback glyphs are measured, not assumed', async ({ page }) => {
  const r = await get(page, 'v-nokren');
  expect(r.error).toBeUndefined();
  expect(r.roles.name!.lineTexts.join(' ')).toContain('ᚨᛒᛟᛟᚹᚺᛟᚷᛟᛞ');
  const flagged = !!r.roles.name!.overflow;
  const wide = r.wide.filter(w => w.role === 'name');
  expect(flagged ? [] : wide).toEqual([]);
  expect(r.roles.name!.planned).toBe(r.roles.name!.painted);
});

test('without the engine the old passes still run', async ({ page }) => {
  // The engine's script never arrives: what a page with a stale cache and no engine would do.
  await serve(page);
  await page.route(/scrollery-typeset\.js(\?|$)/, route => route.abort());
  await openFixture(page, base('c22'));
  const m = await page.evaluate(() => ({
    engine: typeof (window as any).ScrolleryTypeset,
    base: document.querySelector('.AwardeeName')!.hasAttribute('data-af-base'),
    ms: document.querySelector('.Award')!.hasAttribute('data-sc-ms'),
    lines: document.querySelectorAll('.sc-line').length,
  }));
  expect(m.engine).toBe('undefined');
  expect(m.base).toBe(true);
  expect(m.ms).toBe(false);
  expect(m.lines).toBe(0);
});

test('deleting ScrolleryTypeset after load falls back to the old passes', async ({ page }) => {
  await open(page, base('c22'));
  const m = await page.evaluate(() => {
    delete (window as any).ScrolleryTypeset;
    const n = (window as any).ScrolleryAutofit.fit(document);
    return { n, base: document.querySelector('.AwardeeName')!.hasAttribute('data-af-base') };
  });
  expect(m.n).toBe(1);
  expect(m.base).toBe(true);
});

test('multi-up lifts the floors', async ({ page }) => {
  const r = await get(page, 'v-up2');
  expect(r.error).toBeUndefined();
  const n = r.roles.name!;
  expect(n.overflow || n.em >= RANGE.name.min * FLOOR_SCALE - EPS, `name ${n.em} below 1.20 without overflow`).toBe(true);
  const a = r.roles.award!;
  expect(a.overflow || a.em >= RANGE.award.min * FLOOR_SCALE - EPS, `award ${a.em} below 1.05 without overflow`).toBe(true);
});

test('performance: p95 of data-sc-ms over the corpus is within 20ms', async ({ page }) => {
  const ms = (await rows(page)).map(r => r.ms).filter((x): x is number => x !== null).sort((a, b) => a - b);
  expect(ms.length).toBe(CORPUS_IDS.length);
  const p95 = ms[Math.min(ms.length - 1, Math.ceil(ms.length * 0.95) - 1)];
  expect(p95, `p95 ${p95}ms, median ${ms[Math.floor(ms.length / 2)]}ms`).toBeLessThanOrEqual(20);
  // The eight cases of the first brief, on their own.
  const t = await survey(page);
  const own = T10_IDS.map(id => t.get(id)!.ms!).sort((a, b) => a - b);
  expect(own[Math.ceil(own.length * 0.95) - 1]).toBeLessThanOrEqual(20);
});

/* ---------------------------------------------------------------------------------------- */
/* Task 11: compositions, ornaments, hierarchy loop, budget                                  */
/* ---------------------------------------------------------------------------------------- */

test('composition choice', async ({ page }) => {
  const t = await survey(page);
  const got = Object.fromEntries(['c22', 'c26', 'x2', 'c11', 'c14', 'x3'].map(id => [id, t.get(id)!.sectionComp]));
  // c11 was expected Centered by the plan, before anything was measured. Its lead ("and the
  // Principality of") fits beside the versal at 0.741em in Uncial and its one-line place clears
  // the profile, so Classic is correct (controller ruling, Task 10b); T14 revisits it.
  expect(got).toEqual({ c22: 'classic', c26: 'classic', x2: 'classic', c11: 'classic', c14: 'centered', x3: 'centered' });
});

test('lead never wraps', async ({ page }) => {
  const bad: string[] = [];
  for (const r of issuerCases(await rows(page))) {
    const l = r.roles.lead;
    if (!l) { bad.push(`${r.id}: no lead`); continue; }
    if (l.lines !== 1 || l.painted !== 1 || l.lineTexts.length !== 1) bad.push(`${r.id}: lead engine ${l.lines}, painted ${l.painted}, ${l.lineTexts.length} .sc-line`);
    // In Classic the lead is the versal's own line ("Amtgard and the Barony of"): one line that wraps under the versal is a wrapped line.
    if (r.sectionComp === 'classic' && r.leadOnVersalRow !== true) bad.push(`${r.id}: Classic lead is not on the versal's row`);
  }
  expect(bad).toEqual([]);
});

test('Classic: the right flourish clears the place text', async ({ page }) => {
  const bad: string[] = [];
  let checked = 0;
  for (const r of issuerCases(await rows(page))) {
    if (r.sectionComp !== 'classic' || !r.rightInk) continue;
    checked++;
    if (r.rightInk.inked === 0) bad.push(`${r.id}: ink grid decoded to nothing`);
    if (r.rightInk.hits > 0) bad.push(`${r.id}: ${r.rightInk.hits} of ${r.rightInk.inked} inked cells touch the place text`);
  }
  expect(checked).toBeGreaterThan(0);
  expect(bad).toEqual([]);
});

// The versal's own swash is not Centered's to hide, but the engine's ornament rule hides any flourish it
// cannot place clear of the text and the artwork, in either composition; so it is checked only where
// nothing but the composition could have changed it (c22 forced Centered, below).
test('Centered hides the line-two flourishes, and its rule pair clears the text', async ({ page }) => {
  expect((await get(page, 'v-centered')).display.top).not.toBe('none');
  const bad: string[] = [];
  let centered = 0;
  for (const r of issuerCases(await rows(page))) {
    if (r.sectionComp !== 'centered') continue;
    centered++;
    if (r.display.right !== 'none') bad.push(`${r.id}: right flourish shows`);
    if (r.display.bottom !== 'none') bad.push(`${r.id}: bottom flourish shows`);
    if (r.ruleInk && r.ruleInk.hits > 0) bad.push(`${r.id}: rule ink touches the place text (${r.ruleInk.hits}/${r.ruleInk.inked})`);
  }
  expect(centered).toBeGreaterThan(0);
  expect(bad).toEqual([]);
  // A Classic certificate shows no rule.
  for (const r of issuerCases(await rows(page))) {
    if (r.sectionComp === 'classic') expect([r.id, r.display.ruleL, r.display.ruleR]).toEqual([r.id, 'none', 'none']);
  }
});

test('place obeys the hierarchy', async ({ page }) => {
  const bad: string[] = [];
  for (const r of issuerCases(await rows(page))) {
    const p = r.roles.place!.em, n = r.roles.name!.em, a = r.roles.award!.em;
    const lim = Math.min(0.75 * n, 0.9 * a);
    if (p > lim + EPS && p > minOf('place', upOf(r.id)) + EPS) bad.push(`${r.id}: place ${p} > min(0.75 x name ${n}, 0.90 x award ${a}) = ${lim.toFixed(3)}`);
  }
  expect(bad).toEqual([]);
});

test('the connective and the versal are bounded', async ({ page }) => {
  const bad: string[] = [];
  for (const r of issuerCases(await rows(page))) {
    const l = r.roles.lead!.em, p = r.roles.place!.em, n = r.roles.name!.em;
    const lim = Math.max(0.7, p);
    if (l > lim + EPS) bad.push(`${r.id}: lead ${l} > max(0.70, place ${p})`);
    const want = clamp(n, 1.2, 1.495);
    if (r.versalEm === null || Math.abs(r.versalEm - want) > 0.0015) bad.push(`${r.id}: versal ${r.versalEm} != clamp(name ${n}) ${want.toFixed(3)}`);
  }
  expect(bad).toEqual([]);
});

test('the article is set small', async ({ page }) => {
  const t = await survey(page);
  // Every certificate that prints an article sets it at the connective's size.
  const withArt = [...t.values()].filter(r => !r.error && r.art.count > 0);
  expect(withArt.map(r => r.id)).toContain('c12');
  const bad = withArt.filter(r => Math.abs(r.art.px! - r.art.leadPx!) > 0.5).map(r => `${r.id}: article ${r.art.px}px, lead ${r.art.leadPx}px`);
  expect(bad).toEqual([]);
  const c12 = t.get('c12')!;
  expect(Math.abs(c12.art.px! - c12.art.leadPx!)).toBeLessThanOrEqual(0.5);
});

test('flourishes are not checked against the versal: the bottom swash survives on every Classic case', async ({ page }) => {
  const bad: string[] = [];
  let n = 0;
  for (const r of issuerCases(await rows(page))) {
    if (r.sectionComp !== 'classic') continue;
    n++;
    if (r.display.bottom === 'none') bad.push(`${r.id}: #BottomTextFlourish hidden`);
  }
  expect(n).toBeGreaterThan(0);
  expect(bad).toEqual([]);
});

test('kingdoms and parks read alike', async ({ page }) => {
  const rs = issuerCases(await rows(page));
  const median = (xs: number[]) => { const s = xs.slice().sort((a, b) => a - b); const m = s.length >> 1; return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2; };
  const scope = new Map(corpus.map(c => [c.id, c.scope]));
  const k = rs.filter(r => scope.get(r.id) === 'kingdom').map(r => r.roles.place!.em);
  const p = rs.filter(r => scope.get(r.id) === 'park').map(r => r.roles.place!.em);
  expect(k.length).toBeGreaterThan(5);
  expect(p.length).toBeGreaterThan(5);
  const ratio = Math.abs(median(k) - median(p)) / median(p);
  expect(ratio, `kingdom median ${median(k)} (n=${k.length}), park median ${median(p)} (n=${p.length})`).toBeLessThanOrEqual(0.2);
});

test('a forced heading wins', async ({ page }) => {
  // c22 chooses Classic on its own and c14 Centered; each is forced the other way and must obey.
  expect((await get(page, 'c22')).sectionComp).toBe('classic');
  expect((await get(page, 'v-centered')).sectionComp).toBe('centered');
  expect((await get(page, 'c14')).sectionComp).toBe('centered');
  const forced = await get(page, 'v-classic');
  expect(forced.sectionComp).toBe('classic');
  expect(forced.comp).toBe('classic');
});

test('budget holds on every case', async ({ page }) => {
  const rs = (await rows(page)).filter(r => !r.error);
  expect(rs.filter(r => r.budget !== 'ok').map(r => `${r.id}: ${r.budget}`)).toEqual([]);
  // And independently of the flag: the stack is inside its room, or it is at least 40% of the page.
  const bad: string[] = [];
  for (const r of rs) {
    if (r.stack === null || r.content === null || r.below === null) continue;
    if (!(r.stack <= r.content - r.below + 1 || r.stack >= 0.4 * r.content - 1)) bad.push(`${r.id}: stack ${r.stack}, content ${r.content}, below ${r.below}`);
  }
  expect(bad).toEqual([]);
});

test('budget under pressure: a warrior at rank 8, real text, the signature block pinned at 800px', async ({ page }) => {
  await open(page, VARIANTS.find(v => v.id === 'v-w8real')!);
  await page.addStyleTag({ content: 'section.SignAndDate { min-height: 800px !important; flex-basis: 800px !important; }' });
  await page.evaluate(() => (window as any).ScrolleryAutofit.fit(document));
  const r = await page.evaluate(collect);
  expect(r.scError).toBeNull();
  expect(['ok', 'floor']).toContain(r.budget);
  expect(r.stack).not.toBeNull();
  expect(
    r.stack! <= r.content! - r.below! + 1 || r.stack! >= 0.4 * r.content! - 1,
    `stack ${r.stack}, content ${r.content}, below ${r.below}, budget ${r.budget}`,
  ).toBe(true);
  // The unpinned render is the same certificate; it must not be worse.
  const free = await get(page, 'v-w8real');
  expect(free.budget).toBe('ok');
});

test('pass 3 does not break top-half widths (c32, c50)', async ({ page }) => {
  for (const id of ['c32', 'c50']) {
    const r = await get(page, id);
    const flagged = new Set(ROLES.filter(role => r.roles[role]?.overflow));
    expect(r.wide.filter(w => !flagged.has(w.role as RoleName)), `${id} (root scale ${(r.awardPx / 100).toFixed(3)})`).toEqual([]);
  }
});
