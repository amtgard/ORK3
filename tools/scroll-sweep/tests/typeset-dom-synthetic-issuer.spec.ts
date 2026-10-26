import { test, expect, Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

// The issuer half of the typeset engine on a synthetic certificate: compositions, the hierarchy,
// ornament placement and the budget (spec §6.4.2, §6.5, §6.6, §6.7). Like typeset-dom-synthetic
// it needs no app and no login. The page is the spec §8.1 markup with the §8.2 rules written by
// hand, and the flourishes carry the REAL ink grids from assets/scrolls/ink-extents.json, so the
// geometry under test is the geometry the sweep calibrated. The real fixtures wait for the real
// markup (typeset-dom.spec.ts, after Task 8 and the autofit wiring).

const ENGINE = process.env.ORK3_TYPESET_JS ?? path.resolve(__dirname, '../../../orkui/template/revised-frontend/script/scrollery-typeset.js');
const INK = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../../assets/scrolls/ink-extents.json'), 'utf8')).files;

function grid(file: string): string {
  return '32x32:' + INK[file].grid.rows.join('');
}
function blank(w: number, h: number): string {
  return `data:image/svg+xml,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}"/>`)}`;
}
const esc = (s: string) => s.replace(/&/g, '&amp;').replace(/</g, '&lt;');

// 2550 wide with a 475px gutter each side: a 1600px column on x = 1275. Heights are fixed so the
// budget has a page to be a share of; the signature block holds a minimum like the real one.
interface Opts {
  name?: string; award?: string; lead?: string; place?: string; art?: boolean;
  free?: string; up?: number; face?: string; heading?: string; height?: number; scale?: number;
  spacing?: string; signMin?: number; leadSpacing?: string;
}

function pageHtml(o: Opts = {}): string {
  const face = o.face ?? 'Arial, Helvetica, sans-serif';
  const art = (o.art ?? true) ? '<span class="sc-art">the</span> ' : '';
  const free = o.free ? ` data-sc-free="${o.free}"` : '';
  const up = o.up ? ` data-scroll-up="${o.up}"` : '';
  const heading = o.heading ? ` data-sc-heading="${o.heading}"` : '';
  const wide = Math.round(2550 * (o.scale ?? 1));
  return `<!doctype html><html><head><meta charset="utf-8"><style>
    html, body { margin: 0; }
    .Award { position: relative; box-sizing: border-box; width: 2550px; height: ${o.height ?? 3300}px; padding: 200px 475px 150px;
      font: 100px/1.2 ${face}; text-align: center; display: flex; flex-direction: column; }
    .Award p, .Award section, .Award div, .Award span { margin: 0; text-align: center; }
    /* Mirrors scrollery.css as landed in Task 8: the selectors and values of the typeset contract. */
    .Award [data-sc-role="name"], .Award [data-sc-role="award"], .Award [data-sc-role="name"] .sc-line--minor { letter-spacing: -0.01em; }
    .IssuingTextSection { position: relative; flex: 0 0 auto; }
    section.IssuingTextSection > p:first-child { font-size: 1.3em; line-height: 1.1em; text-align: left; position: relative; }
    .ScVersal { font-size: 1.15em; margin-right: -10px; position: relative; }
    #IssuerTitle { font-size: 0.8em; }
    section.IssuingTextSection > p:nth-child(2) { text-align: right; position: relative; margin-top: -0.1em;
      margin-left: var(--sc-place-gutter, 4.4em); padding-right: var(--sc-place-reserve, 0px); }
    .Award .sc-line { display: block; white-space: nowrap; width: max-content; margin-left: auto; margin-right: auto; line-height: 1.1; }
    .Award [data-sc-role="name"] .sc-line { line-height: 1.12; }
    .Award .ScPlace > [data-sc-role="place"]:has(> .sc-line) { display: inline-block; }
    .Award .ScPlace .sc-line { margin-left: auto; margin-right: 0; }
    section.IssuingTextSection:not([data-sc-comp="centered"]) #IssuerTitle > .sc-line { display: inline-block; width: auto; margin: 0; }
    ${o.leadSpacing ? `.Award [data-sc-role="lead"] .sc-line { word-spacing: ${o.leadSpacing}; }` : ''}
    .Award .sc-art { font-size: var(--sc-art-size, 0.8em); }
    .Award .Flourish { position: absolute; pointer-events: none; height: auto; }
    #TopTextFlourish { width: 3.8em; left: -0.4em; bottom: 0.9em; }
    #BottomTextFlourish { width: 3.3em; left: -0.6em; top: 0.95em; }
    #RightTextFlourish { width: 4.5em; right: -0.9em; top: 0; }
    section.IssuingTextSection[data-sc-comp="centered"] > p.ScLead, section.IssuingTextSection[data-sc-comp="centered"] > p.ScPlace { text-align: center; }
    section.IssuingTextSection[data-sc-comp="centered"] > p.ScPlace { margin-left: 0; padding-right: 0; }
    section.IssuingTextSection[data-sc-comp="centered"] #IssuerTitle { display: block; text-align: center; }
    section.IssuingTextSection[data-sc-comp="centered"] .ScPlace .sc-line { margin-right: auto; }
    section.IssuingTextSection[data-sc-comp="centered"] #BottomTextFlourish, section.IssuingTextSection[data-sc-comp="centered"] #RightTextFlourish { display: none; }
    .Award .ScRule { display: none; width: 3.3em; }
    .Award .ScRule--r { transform: scaleX(-1); }
    section.IssuingTextSection[data-sc-comp="centered"] .ScRule { display: block; }
    .Award .Body.IssuingVerb { font-size: 0.8em; margin-block-start: calc(0.25em / 0.8); margin-block-end: 0; }
    .AwardeeName { font-size: 1.7em; }
    .Award > section.ScName { margin-top: 0.45em; margin-bottom: 0.2em; flex-grow: 0; }
    .Award > section.Order { flex: 0 0 auto; margin-block-start: 0; }
    .Award > .ScSpacer { flex-grow: 1; flex-shrink: 0; min-height: 0.3em; max-height: 1.2em; }
    .For { font-size: 0.6em; margin-top: 0.3em; }
    .Award_Reason { font-size: 0.8em; flex: 0 0 auto; }
    .Award_Reason p { display: inline-block; }
    .SignAndDate { flex: 0 0 auto; min-height: ${o.signMin ?? 300}px; }
  </style></head><body${up}${heading}>
  <svg viewBox="0 0 2550 3300" style="aspect-ratio:8.5/11;width:${wide}px;display:block;position:relative;overflow:hidden" data-scroll-frame>
  <foreignObject x="0" y="0" width="2550" height="3300" style="overflow:visible;">
  <div class="award-template">
  <div class="Award"${free}>
    <section class="IssuingTextSection" data-sc-comp="classic">
      <p class="ScLead"><span class="ScVersal">Amtgard
        <img id="TopTextFlourish" class="Flourish" data-sc-ink="${grid('common/text/squiggly-top.svg')}" src="${blank(556, 158)}" width="556" height="158" alt="">
        <img id="BottomTextFlourish" class="Flourish" data-sc-ink="${grid('common/text/squiggly-bottom.svg')}" src="${blank(424, 161)}" width="424" height="161" alt="">
      </span> <span id="IssuerTitle" data-scroll-text="issuerTitle" data-sc-role="lead">${esc(o.lead ?? 'and the Barony of')}</span></p>
      <p class="ScPlace"><span data-scroll-text="issuerName" data-sc-role="place" style="font-size:1.1em;--sc-art-size:calc(1.04em / 1.1)">${art}${esc(o.place ?? 'Wetlands')}</span>
        <img id="RightTextFlourish" class="Flourish" data-sc-ink="${grid('common/text/squiggly-right.svg')}" src="${blank(414, 354)}" width="414" height="354" alt=""></p>
      <img class="Flourish ScRule ScRule--l" data-sc-ink="${grid('common/text/squiggly-bottom.svg')}" src="${blank(424, 161)}" width="424" height="161" alt="">
      <img class="Flourish ScRule ScRule--r" data-sc-ink="${grid('common/text/squiggly-bottom.svg')}" src="${blank(424, 161)}" width="424" height="161" alt="">
      <p class="IssuingVerb Body"><span>presents</span></p>
    </section>
    <section class="ScName"><p class="AwardeeName"><span data-scroll-text="player" data-sc-role="name">${esc(o.name ?? 'Halo')}</span></p></section>
    <section class="Order" data-sc-role="award">${esc(o.award ?? 'Order of the Rose')}</section>
    <div class="ScSpacer" aria-hidden="true"></div>
    <section class="For">for</section>
    <section class="Award_Reason"><p data-scroll-text="reason">Valour in the lists and kindness in the tents.</p></section>
    <section class="SignAndDate"></section>
  </div></div></foreignObject></svg></body></html>`;
}

// A profile that leaves [lo, hi] free at every height.
const band = (lo: number, hi: number) => '0,100|' + Array(40).fill(lo).join(',') + '|' + Array(40).fill(hi).join(',');

async function load(page: Page, o: Opts = {}) {
  await page.setContent(pageHtml(o));
  await page.addScriptTag({ path: ENGINE });
}

const lay = (page: Page, opts?: object) =>
  page.evaluate((x) => (window as any).ScrolleryTypeset.layoutTop(document.querySelector('.Award'), x), opts);

interface State {
  comp: string | null; sectionComp: string | null; budget: string | null;
  name: number; award: number; place: number; lead: number; versal: number; art: number | null; leadPx: number; artPx: number | null;
  leadLines: string | null; placeLines: string[]; placeText: string; placeOverflow: boolean; leadOverflow: boolean;
  placeMinFloor: number;
}

// Everything the assertions read, from the DOM and not from the report, so a report that lied
// would not pass.
const state = (page: Page): Promise<State> =>
  page.evaluate(() => {
    const q = (s: string) => document.querySelector<HTMLElement>(s)!;
    const award = q('.Award'), root = parseFloat(getComputedStyle(award).fontSize);
    const em = (el: Element) => parseFloat(getComputedStyle(el).fontSize) / root;
    const art = document.querySelector('.sc-art');
    return {
      comp: award.getAttribute('data-sc-comp'), sectionComp: q('.IssuingTextSection').getAttribute('data-sc-comp'),
      budget: award.getAttribute('data-sc-budget'),
      name: em(q('[data-sc-role="name"] .sc-line')), award: em(q('[data-sc-role="award"] .sc-line')),
      place: em(q('[data-sc-role="place"]')), lead: em(q('#IssuerTitle')), versal: em(q('.ScVersal')),
      art: art ? em(art) : null, leadPx: parseFloat(getComputedStyle(q('#IssuerTitle')).fontSize),
      artPx: art ? parseFloat(getComputedStyle(art).fontSize) : null,
      leadLines: q('#IssuerTitle').getAttribute('data-sc-lines'),
      placeLines: [...document.querySelectorAll('[data-sc-role="place"] .sc-line')].map((l) => l.textContent || ''),
      placeText: q('[data-sc-role="place"]').textContent || '',
      placeOverflow: q('[data-sc-role="place"]').hasAttribute('data-sc-overflow'),
      leadOverflow: q('#IssuerTitle').hasAttribute('data-sc-overflow'),
      placeMinFloor: 0.62,
    };
  });

// The independent check of §6.6: decode the grid in the page, map its cells through the image's
// own box (getBoundingClientRect / frameScale, never offsetLeft), and test every inked cell
// against every place line's text rectangles grown by ORN_GAP.
async function inkVsText(page: Page, imgSel: string, textSel: string): Promise<{ inked: number; hits: number; hidden: boolean }> {
  return page.evaluate(({ imgSel, textSel }) => {
    const award = document.querySelector<HTMLElement>('.Award')!;
    const a = award.getBoundingClientRect(), k = a.width / award.offsetWidth;
    const img = document.querySelector<HTMLElement>(imgSel)!;
    if (getComputedStyle(img).display === 'none') return { inked: 0, hits: 0, hidden: true };
    const r = img.getBoundingClientRect(), g = img.getAttribute('data-sc-ink')!.slice(6);
    const gap = 0.12 * parseFloat(getComputedStyle(award).fontSize);
    const m = /^matrix\(([^)]+)\)$/.exec(getComputedStyle(img).transform), flipped = !!m && parseFloat(m[1].split(',')[0]) < 0;
    const boxes: number[][] = [];
    document.querySelectorAll(textSel).forEach((l) => {
      const rg = document.createRange(); rg.selectNodeContents(l);
      [...rg.getClientRects()].forEach((q) => boxes.push([(q.left - a.left) / k, (q.top - a.top) / k, (q.right - a.left) / k, (q.bottom - a.top) / k]));
    });
    let inked = 0, hits = 0;
    for (let row = 0; row < 32; row++) {
      const v = parseInt(g.substr(row * 8, 8), 16);
      for (let c = 0; c < 32; c++) {
        if (!((v >>> (31 - c)) & 1)) continue;
        inked++;
        const cc = flipped ? 31 - c : c;
        const x0 = (r.left - a.left + (r.width * cc) / 32) / k, x1 = (r.left - a.left + (r.width * (cc + 1)) / 32) / k;
        const y0 = (r.top - a.top + (r.height * row) / 32) / k, y1 = (r.top - a.top + (r.height * (row + 1)) / 32) / k;
        if (boxes.some((b) => x0 < b[2] + gap - 0.5 && x1 > b[0] - gap + 0.5 && y0 < b[3] + gap - 0.5 && y1 > b[1] - gap + 0.5)) hits++;
      }
    }
    return { inked, hits, hidden: false };
  }, { imgSel, textSel });
}

const NAMES = ['Halo', 'Stormcrow Giggleshadows, the Relentless', 'Sir Hitorimono Wanderingblade, the Scapegoat of the Eastern Marches'];
const AWARDS = ['Order of the Rose', 'Guildmaster of the Twilight Peak Anti-Paladins', 'Dragon'];

test('a short park name takes Classic, and the lead is one line', async ({ page }) => {
  await load(page, { lead: 'and the Barony of', place: 'Wetlands' });
  const report = await lay(page);
  const s = await state(page);
  expect(report.comp).toBe('classic');
  expect(s.comp).toBe('classic');
  expect(s.sectionComp).toBe('classic');
  expect(s.leadLines).toBe('1');
  expect(s.placeLines.length).toBe(1);
  expect(report.roles.lead.lines).toBe(1);
  expect(report.roles.place.lines).toBe(1);
  expect(report.ornaments.rule).toBe('hidden');
  // The no-JS fallback gutter is gone: the engine wrote its own, in frame px.
  const vars = await page.evaluate(() => {
    const s = document.querySelector<HTMLElement>('.IssuingTextSection')!;
    return [s.style.getPropertyValue('--sc-place-gutter'), s.style.getPropertyValue('--sc-place-reserve')];
  });
  expect(vars[0]).toMatch(/^\d+(\.\d+)?px$/);
  expect(vars[1]).toMatch(/^\d+(\.\d+)?px$/);
});

test('a long lead in a wide face goes Centered, with the place centred and the rule pair under it', async ({ page }) => {
  await load(page, {
    lead: 'and the Principality of the Grand Duchy of', place: 'Painted Skies and Shadows',
    face: '"Courier New", Courier, monospace',
  });
  const report = await lay(page);
  const s = await state(page);
  expect(report.comp).toBe('centered');
  expect(s.sectionComp).toBe('centered');
  expect(s.leadLines).toBe('1');
  expect(s.placeLines.length).toBeLessThanOrEqual(2);
  expect(s.lead).toBeLessThanOrEqual(0.9 + 0.001);
  // The line-two flourishes are gone and the rule pair is what remains.
  const d = await page.evaluate(() => ({
    right: getComputedStyle(document.querySelector('#RightTextFlourish')!).display,
    bottom: getComputedStyle(document.querySelector('#BottomTextFlourish')!).display,
    rules: [...document.querySelectorAll('.ScRule')].map((r) => getComputedStyle(r).display),
  }));
  expect(d.right).toBe('none');
  expect(d.bottom).toBe('none');
  expect(report.ornaments.right).toBe('hidden');
  expect(report.ornaments.bottom).toBe('hidden');
  expect(['placed', 'scaled']).toContain(report.ornaments.rule);
  expect(d.rules).toEqual(['block', 'block']);
  // The rule's ink stays clear of the place text in both halves.
  for (const sel of ['.ScRule--l', '.ScRule--r']) {
    const hit = await inkVsText(page, sel, '[data-sc-role] .sc-line');
    expect(hit.inked).toBeGreaterThan(0);
    expect(hit.hits, sel).toBe(0);
  }
});

test('the right flourish clears the place text, and so does every other ornament', async ({ page }) => {
  for (const place of ['Wetlands', 'Everglade Sentinels', 'Crystal Groves']) {
    await load(page, { place });
    const report = await lay(page);
    expect(report.comp, place).toBe('classic');
    expect(report.ornaments.right, place).not.toBe('hidden');
    const right = await inkVsText(page, '#RightTextFlourish', '[data-sc-role] .sc-line, #IssuerTitle');
    expect(right.inked).toBeGreaterThan(0);
    expect(right.hits, `${place}: right flourish ink inside a text box`).toBe(0);
    const bottom = await inkVsText(page, '#BottomTextFlourish', '[data-sc-role] .sc-line, #IssuerTitle');
    expect(bottom.hits, `${place}: bottom flourish ink inside a text box`).toBe(0);
  }
});

test('the right flourish sits ORN_GAP under the place, and the place stays inside gutter and reserve', async ({ page }) => {
  await load(page, { place: 'Everglade Sentinels' });
  await lay(page);
  const g = await page.evaluate(() => {
    const award = document.querySelector<HTMLElement>('.Award')!;
    const line = document.querySelector('[data-sc-role="place"] .sc-line')!;
    const rg = document.createRange(); rg.selectNodeContents(line);
    const t = rg.getBoundingClientRect(), img = document.querySelector('#RightTextFlourish')!.getBoundingClientRect();
    const rows = document.querySelector('#RightTextFlourish')!.getAttribute('data-sc-ink')!.slice(6);
    let curl = 0;
    for (let r = 0; r < 32; r++) { if ((parseInt(rows.substr(r * 8, 8), 16) >>> 11) !== 0) { curl = r; break; } }
    const sec = document.querySelector<HTMLElement>('.IssuingTextSection')!;
    return {
      curlTop: img.top + (img.height * curl) / 32, textBottom: t.bottom, textLeft: t.left, textRight: t.right,
      gutter: parseFloat(sec.style.getPropertyValue('--sc-place-gutter')), reserve: parseFloat(sec.style.getPropertyValue('--sc-place-reserve')),
      colL: 475, colR: award.clientWidth - 475,
    };
  });
  expect(g.curlTop - g.textBottom).toBeGreaterThanOrEqual(12 - 0.6);
  expect(g.curlTop - g.textBottom).toBeLessThanOrEqual(12 + 2);
  expect(g.textLeft).toBeGreaterThanOrEqual(g.colL + g.gutter - 1);
  expect(g.textRight).toBeLessThanOrEqual(g.colR - g.reserve + 1);
});

test('the place obeys the hierarchy unless it is at its floor, and the lead is bounded by the place', async ({ page }) => {
  for (const up of [0, 2]) {
    for (const name of NAMES) {
      for (const award of AWARDS) {
        for (const place of ['Wetlands', 'Mountain of the Sixth Dawn']) {
          await load(page, { name, award, place, up });
          await lay(page);
          const s = await state(page);
          const pmin = 0.62 * (up ? 1.5 : 1);
          const tag = `${name} / ${award} / ${place} up=${up}`;
          expect(s.place <= Math.min(0.75 * s.name, 0.9 * s.award) + 0.001 || Math.abs(s.place - pmin) < 0.002, `place ${s.place} (${tag}) name ${s.name} award ${s.award}`).toBe(true);
          expect(s.lead, `lead (${tag})`).toBeLessThanOrEqual(Math.max(0.7 * (up ? 1.5 : 1), s.place) + 0.001);
          expect(s.lead, `lead max (${tag})`).toBeLessThanOrEqual(1.04 + 0.001);
        }
      }
    }
  }
});

test('the versal is clamp(name, 1.20, 1.495)', async ({ page }) => {
  for (const name of NAMES.concat(['Sir Hitorimono Wanderingblade the Scapegoat of the Eastern Marches and the Western Reaches of Nowhere'])) {
    await load(page, { name });
    await lay(page);
    const s = await state(page);
    expect(s.versal, name).toBeCloseTo(Math.min(Math.max(s.name, 1.2), 1.495), 2);
  }
  // Both ends of the clamp occur: the short name sits above it, the long one below.
  await load(page, { name: 'Halo' });
  await lay(page);
  expect((await state(page)).versal).toBeCloseTo(1.495, 3);
  await load(page, { name: 'Sir Hitorimono Wanderingblade the Scapegoat of the Eastern Marches and the Western Reaches of Nowhere' });
  await lay(page);
  expect((await state(page)).versal).toBeCloseTo(1.2, 3);
});

test('the article is set at the lead size, on the first place line, and survives a second run', async ({ page }) => {
  await load(page, { place: 'Wetlands', art: true });
  await lay(page);
  const s = await state(page);
  expect(s.artPx).not.toBeNull();
  expect(Math.abs(s.artPx! - s.leadPx)).toBeLessThanOrEqual(0.5);
  const first = await page.evaluate(() => document.querySelector('[data-sc-role="place"] .sc-line')!.firstElementChild!.className);
  expect(first).toBe('sc-art');
  await lay(page);
  const s2 = await state(page);
  expect(Math.abs(s2.artPx! - s2.leadPx)).toBeLessThanOrEqual(0.5);
  expect(s2.placeText).toContain('the');

  // Centered too, where the article may sit on the first of two lines.
  await load(page, { lead: 'and the Principality of the Grand Duchy of', place: 'Painted Skies and Shadows', art: true, face: '"Courier New", Courier, monospace' });
  await lay(page);
  const c = await state(page);
  expect(c.comp).toBe('centered');
  expect(Math.abs(c.artPx! - c.leadPx)).toBeLessThanOrEqual(0.5);

  // No article in the markup, none written.
  await load(page, { place: 'Crystal Groves', art: false });
  await lay(page);
  expect(await page.evaluate(() => document.querySelectorAll('.sc-art').length)).toBe(0);
});

test('a forced heading wins, in both directions', async ({ page }) => {
  await load(page, { heading: 'centered', place: 'Wetlands' });
  let report = await lay(page);
  expect(report.comp).toBe('centered');
  expect((await state(page)).sectionComp).toBe('centered');

  await load(page, { heading: 'classic', lead: 'and the Principality of the Grand Duchy of', place: 'Painted Skies and Shadows', face: '"Courier New", Courier, monospace' });
  report = await lay(page);
  const s = await state(page);
  // The same words that go Centered on their own are forced to Classic, at the floors, flagged.
  expect(report.comp).toBe('classic');
  expect(s.sectionComp).toBe('classic');
  expect(s.placeOverflow || s.leadOverflow).toBe(true);
  expect(s.lead).toBeCloseTo(0.7, 2);
  expect(s.leadLines).toBe('1');

  // The unforced version of the same page is Centered, so the force is what changed it.
  await load(page, { lead: 'and the Principality of the Grand Duchy of', place: 'Painted Skies and Shadows', face: '"Courier New", Courier, monospace' });
  expect((await lay(page)).comp).toBe('centered');
});

test('the budget is ok on a roomy page and floor on an over-tall stack', async ({ page }) => {
  await load(page, { name: 'Stormcrow Giggleshadows, the Relentless' });
  let report = await lay(page);
  expect(report.budget).toBe('ok');
  expect((await state(page)).budget).toBe('ok');

  // 1500px of page, 250px of it padding, 300 held for the signature block: the top stack cannot
  // be that short even with the name at its floor.
  await load(page, { name: 'Stormcrow Giggleshadows, the Relentless', height: 1100 });
  report = await lay(page);
  expect(report.budget).toBe('floor');
  expect((await state(page)).budget).toBe('floor');
});

test('the budget loop lowers the name, by 8% a round and three rounds at most', async ({ page }) => {
  // The same short name on three pages: roomy, one the stack fits only after the loop, and one
  // it never fits. 1700px leaves the top stack about 1000px at the name's maximum, which is over
  // its share, so the name comes down and the budget then reads ok.
  await load(page, { name: 'Halo' });
  await lay(page);
  const roomy = await state(page);
  await load(page, { name: 'Halo', height: 1700 });
  const report = await lay(page);
  const tight = await state(page);
  expect(roomy.name).toBeCloseTo(2.3, 2);
  expect(tight.name).toBeLessThan(roomy.name - 0.1);
  expect(tight.name).toBeCloseTo(2.3 * 0.92 ** 3, 1);
  expect(report.budget).toBe('ok');
  // The hierarchy and the versal follow the name down.
  expect(tight.versal).toBeCloseTo(Math.min(Math.max(tight.name, 1.2), 1.495), 2);
  expect(tight.award).toBeLessThanOrEqual(0.85 * tight.name + 0.001);
  // The loop is bounded: a page that cannot be satisfied stops after three rounds, at floor.
  await load(page, { name: 'Halo', height: 1300 });
  const stuck = await lay(page);
  expect(stuck.budget).toBe('floor');
  expect((await state(page)).name).toBeGreaterThan(2.3 * 0.92 ** 3 - 0.01);
});

test('running twice changes nothing, in either composition', async ({ page }) => {
  const cases: Opts[] = [
    { place: 'Everglade Sentinels' },
    { lead: 'and the Principality of the Grand Duchy of', place: 'Painted Skies and Shadows', face: '"Courier New", Courier, monospace' },
    { place: 'Wetlands', free: band(300, 2210) },
    { place: 'Wetlands', free: band(300, 2090) },
  ];
  for (const o of cases) {
    await load(page, o);
    await lay(page);
    const first = await page.evaluate(() => document.querySelector('.Award')!.innerHTML);
    const attrs = await page.evaluate(() => ['data-sc-comp', 'data-sc-budget'].map((a) => document.querySelector('.Award')!.getAttribute(a)));
    await lay(page);
    const second = await page.evaluate(() => document.querySelector('.Award')!.innerHTML);
    expect(second).toBe(first);
    expect(await page.evaluate(() => ['data-sc-comp', 'data-sc-budget'].map((a) => document.querySelector('.Award')!.getAttribute(a)))).toEqual(attrs);
  }
});

test('a textContent write on the place re-lays out from the new text', async ({ page }) => {
  await load(page, { place: 'Wetlands' });
  await lay(page);
  await page.evaluate(() => { document.querySelector('[data-sc-role="place"]')!.textContent = 'Crystal Groves'; });
  await lay(page);
  const s = await state(page);
  expect(s.placeText).toBe('Crystal Groves');
  expect(await page.evaluate(() => document.querySelectorAll('.sc-art').length)).toBe(0);
});

test('persona text is set as text, never markup: < and & survive', async ({ page }) => {
  await load(page, { name: 'Tom & Jerry <script>x</script> Esq', award: 'Order of <b>the</b> Rose & Thorn', place: 'Ash & <i>Ember</i>' });
  await lay(page);
  const r = await page.evaluate(() => ({
    name: document.querySelector('[data-sc-role="name"]')!.textContent,
    award: document.querySelector('[data-sc-role="award"]')!.textContent,
    place: document.querySelector('[data-sc-role="place"]')!.textContent,
    tags: document.querySelectorAll('.Award script, .Award b, .Award i').length,
  }));
  expect(r.name?.replace(/ /g, ' ')).toContain('Tom & Jerry <script>x</script>');
  expect(r.award).toContain('<b>the</b>');
  expect(r.place).toContain('Ash & <i>Ember</i>');
  expect(r.tags).toBe(0);
});

test('a minor line is kept as minor through the issuer pipeline, and a second run keeps it', async ({ page }) => {
  // An honorific run over a long name, in a band that wants two lines: the honorific line may
  // be set small (spec §6.3.4). Whether the solver takes it is the solver's call; what this test
  // holds is that the pipeline writes it faithfully and that planned equals printed.
  const name = 'Lady Seraphina Wanderingblade Featherstone of the Eastern Marches';
  await load(page, { name, free: band(700, 1850) });
  await lay(page);
  const lines = await page.evaluate(() => [...document.querySelectorAll('[data-sc-role="name"] .sc-line')].map((l) => ({
    minor: l.classList.contains('sc-line--minor'), size: (l as HTMLElement).style.fontSize,
  })));
  expect(lines.length).toBeGreaterThanOrEqual(2);
  lines.filter((l) => l.minor).forEach((l) => expect(l.size).toBe('0.72em'));
  const planned = await page.evaluate(() => [
    document.querySelector('[data-sc-role="name"]')!.getAttribute('data-sc-planned'),
    document.querySelector('[data-sc-role="name"]')!.getAttribute('data-sc-lines'),
  ]);
  expect(planned[0]).toBe(planned[1]);
  const before = await page.evaluate(() => document.querySelector('.Award')!.innerHTML);
  await lay(page);
  expect(await page.evaluate(() => document.querySelector('.Award')!.innerHTML)).toBe(before);
});

test('a role with no font size to write against is left alone', async ({ page }) => {
  await load(page, { name: 'Halo' });
  await page.addStyleTag({ content: '.AwardeeName { font-size: 0 !important; }' });
  const report = await lay(page);
  const r = await page.evaluate(() => {
    const el = document.querySelector<HTMLElement>('[data-sc-role="name"]')!;
    return { size: el.style.fontSize, hasSize: el.hasAttribute('data-sc-size') };
  });
  expect(r.size).not.toMatch(/NaN|Infinity/);
  expect(r.hasSize).toBe(false);
  expect(report.roles.name).toBeUndefined();
  // The rest of the page is still laid out.
  expect(report.roles.award).toBeTruthy();
});

test('a frame drawn at half scale lays out the same as one at full scale', async ({ page }) => {
  await load(page, { place: 'Everglade Sentinels' });
  const full = await lay(page);
  const fullDom = await page.evaluate(() => document.querySelector('.Award')!.innerHTML);
  await load(page, { place: 'Everglade Sentinels', scale: 0.5 });
  const half = await lay(page);
  const halfDom = await page.evaluate(() => document.querySelector('.Award')!.innerHTML);
  expect(half.comp).toBe(full.comp);
  expect(half.ornaments).toEqual(full.ornaments);
  // Ink boxes come from getBoundingClientRect / frameScale, so the written geometry is identical.
  expect(halfDom).toBe(fullDom);
});

test('an artwork edge across the right flourish hides it and gives the type its room back', async ({ page }) => {
  await load(page, { place: 'Wetlands', free: band(300, 2210) });
  const open = await lay(page);
  expect(open.ornaments.right).not.toBe('hidden');
  const openPlace = (await state(page)).place;

  // The column ends at 2075 and the flourish hangs to 2165: an edge at 2090 cuts it at every scale.
  await load(page, { place: 'Wetlands', free: band(300, 2090) });
  const cut = await lay(page);
  expect(cut.comp).toBe('classic');
  expect(cut.ornaments.right).toBe('hidden');
  const hidden = await page.evaluate(() => getComputedStyle(document.querySelector('#RightTextFlourish')!).display);
  expect(hidden).toBe('none');
  // Nothing reserves room for a flourish that is not drawn.
  const reserve = await page.evaluate(() => parseFloat(document.querySelector<HTMLElement>('.IssuingTextSection')!.style.getPropertyValue('--sc-place-reserve')));
  expect(reserve).toBeLessThanOrEqual(2090 - 2075 + 1);
  expect((await state(page)).place).toBeGreaterThanOrEqual(openPlace - 0.001);
});

test('the bottom and top flourishes are exempt from the versal: placed, not hidden, on every Classic case', async ({ page }) => {
  for (const name of NAMES) {
    await load(page, { name, place: 'Everglade Sentinels' });
    const report = await lay(page);
    expect(report.comp).toBe('classic');
    expect(report.ornaments.bottom, name).toBe('placed');
    expect(report.ornaments.top, name).toBe('placed');
    for (const sel of ['#TopTextFlourish', '#BottomTextFlourish']) {
      expect(await page.evaluate((s) => getComputedStyle(document.querySelector(s)!).display, sel)).not.toBe('none');
    }
  }
});

test('a page with no issuer still lays out the name and the award, and reports no composition', async ({ page }) => {
  await page.setContent(`<!doctype html><html><head><meta charset="utf-8"><style>
    .Award { box-sizing: border-box; width: 2550px; padding: 200px 475px 0; font: 100px/1.2 Arial, sans-serif; text-align: center; }
    .AwardeeName { margin: 0; font-size: 1.7em; }
    .sc-line { display: block; white-space: nowrap; width: max-content; margin: 0 auto; }
  </style></head><body><div class="Award"><p class="AwardeeName"><span data-sc-role="name">Halo</span></p>
  <section data-sc-role="award">Order of the Rose</section></div></body></html>`);
  await page.addScriptTag({ path: ENGINE });
  const report = await lay(page);
  expect(report.comp).toBeNull();
  expect(report.roles.name.size).toBeCloseTo(2.3, 2);
  expect(report.roles.lead).toBeUndefined();
  expect(report.budget).toBe('ok');
});

test('the lead is written as one line in its role, and the article size goes on the place as --sc-art-size', async ({ page }) => {
  await load(page, { place: 'Wetlands' });
  await lay(page);
  const r = await page.evaluate(() => {
    const lead = document.querySelector<HTMLElement>('#IssuerTitle')!, place = document.querySelector<HTMLElement>('[data-sc-role="place"]')!;
    const line = lead.querySelector<HTMLElement>(':scope > .sc-line');
    return {
      lines: lead.querySelectorAll('.sc-line').length, text: line?.textContent, display: line ? getComputedStyle(line).display : '',
      w: line?.getAttribute('data-sc-w'), artVar: place.style.getPropertyValue('--sc-art-size'), stored: lead.getAttribute('data-sc-text'),
    };
  });
  expect(r.lines).toBe(1);
  expect(r.text).toBe('and the Barony of');
  expect(r.stored).toBe('and the Barony of');
  expect(r.display).toBe('inline-block');
  expect(Number(r.w)).toBeGreaterThan(0);
  expect(r.artVar).toMatch(/^\d+(\.\d+)?em$/);
  // Written in em of the place span, so that the stylesheet's calc lands "the" at the lead's size.
  const s = await state(page);
  expect(Number(r.artVar.replace('em', '')) * s.place).toBeCloseTo(s.lead, 2);

  // A composer's textContent write on the lead re-lays out from the new text.
  await page.evaluate(() => { document.querySelector('#IssuerTitle')!.textContent = 'and'; });
  await lay(page);
  expect(await page.evaluate(() => document.querySelector('#IssuerTitle')!.textContent)).toBe('and');
});
// The four defects Task 10b found on real certificates. The synthetic page is wrapped in the real
// <svg viewBox><foreignObject> frame (above), which is what defect 1 needed to show at all.

const leadState = (page: Page) => page.evaluate(() => {
  const lead = document.querySelector<HTMLElement>('#IssuerTitle')!, line = lead.querySelector<HTMLElement>('.sc-line')!;
  const rg = document.createRange(); rg.selectNodeContents(line);
  const t = rg.getBoundingClientRect(), v = document.querySelector('.ScVersal')!.getBoundingClientRect();
  return {
    stamped: Number(line.dataset.scW), printed: t.width, onVersalRow: t.top < v.bottom - 20,
    size: Number(lead.dataset.scSize), overflow: lead.hasAttribute('data-sc-overflow'),
  };
});

test('defect 1: inside the svg frame no box is NaN, so the profile is read and the budget is not floor', async ({ page }) => {
  await load(page, { place: 'Wetlands', free: band(300, 2210) });
  const report = await lay(page);
  expect(report.budget).toBe('ok');
  expect(report.comp).toBe('classic');
  const nan = await page.evaluate(() => {
    const bad: string[] = [];
    document.querySelectorAll('.Award [data-sc-role]').forEach((el) => {
      for (const a of ['data-sc-size', 'data-sc-w']) { const v = el.getAttribute(a) ?? el.querySelector('.sc-line')?.getAttribute(a); if (v !== null && v !== undefined && !isFinite(Number(v))) bad.push(`${el.getAttribute('data-sc-role')} ${a}=${v}`); }
    });
    const sec = document.querySelector<HTMLElement>('.IssuingTextSection')!;
    for (const v of ['--sc-place-gutter', '--sc-place-reserve']) if (/NaN/.test(sec.style.getPropertyValue(v))) bad.push(v);
    document.querySelectorAll<HTMLElement>('.Flourish').forEach((f) => { if (/NaN/.test(f.getAttribute('style') ?? '')) bad.push('flourish ' + f.id); });
    return bad;
  });
  expect(nan).toEqual([]);
  // The profile is honoured: a band that narrows the column beside the lead and the place is what
  // moves the type, which a NaN band lookup could not do.
  await load(page, { place: 'Wetlands', free: band(900, 1650) });
  await lay(page);
  const w = await page.evaluate(() => Number(document.querySelector<HTMLElement>('[data-sc-role="name"] .sc-line')!.dataset.scW));
  expect(w).toBeLessThanOrEqual(750);
});

test('defect 2: a Classic lead too wide for the versal row is shrunk onto the row, not wrapped under it', async ({ page }) => {
  await load(page, { lead: 'and the Principality of i i i', place: 'Wetlands' });
  const report = await lay(page);
  const s = await leadState(page);
  expect(report.comp).toBe('classic');
  expect(s.onVersalRow).toBe(true);
  expect(s.size).toBeLessThan(1.04 - 0.05);
  expect(s.size).toBeGreaterThanOrEqual(0.7 - 0.001);
  // The width it was held to is the room beside the versal, not the whole column.
  expect(s.stamped).toBeLessThan(1000);
  expect(s.printed).toBeLessThanOrEqual(s.stamped + 0.5);
  expect(await page.evaluate(() => document.querySelector('#IssuerTitle')!.getAttribute('data-sc-lines'))).toBe('1');
});

// word-spacing stands in for what the measurer cannot see on real faces (kerning across a space):
// the printed lead comes out a few px wider than the sum of its measured words.
const WIDE_LEAD = 'and the Principality of i i i i i i';

test('defect 4: the lead is checked against the width it is stamped with', async ({ page }) => {
  await load(page, { lead: WIDE_LEAD, place: 'Wetlands', leadSpacing: '4px' });
  const report = await lay(page);
  const s = await leadState(page);
  expect(report.comp).toBe('classic');
  expect(s.onVersalRow).toBe(true);
  expect(s.printed).toBeLessThanOrEqual(s.stamped + 0.5);
  expect(s.overflow).toBe(false);
});

test('defect 3: a lead that cannot fit at its floor makes Classic infeasible, and a forced Classic flags it', async ({ page }) => {
  const lead = WIDE_LEAD + ' l';
  await load(page, { lead, place: 'Wetlands', leadSpacing: '4px' });
  expect((await lay(page)).comp).toBe('centered');

  await load(page, { lead, place: 'Wetlands', leadSpacing: '4px', heading: 'classic' });
  const forced = await lay(page);
  const s = await leadState(page);
  expect(forced.comp).toBe('classic');
  expect(s.overflow).toBe(true);
  expect(s.size).toBeCloseTo(0.7, 2);
});

test('the top and bottom flourishes are not judged against the artwork; the right one still is', async ({ page }) => {
  // Artwork ink across the top swash's rows only (bands 0 and 1, y < 200): the lead and place
  // bands are clear, so Classic stays and the swash alone is on the wrong side of the artwork.
  const free = '0,100|' + [560, 560, ...Array(38).fill(300)].join(',') + '|' + Array(40).fill(2210).join(',');
  await load(page, { place: 'Wetlands', free });
  const report = await lay(page);
  expect(report.comp).toBe('classic');
  expect(report.ornaments.top).toBe('placed');
  expect(await page.evaluate(() => getComputedStyle(document.querySelector('#TopTextFlourish')!).display)).not.toBe('none');

  // The same in Centered, with the artwork across the bottom swash's rows as well.
  await load(page, { place: 'Wetlands', heading: 'centered', free: band(560, 2210) });
  const c = await lay(page);
  expect(c.ornaments.top).toBe('placed');
  // ...while the right flourish gives way to artwork (the last test above and the edge test).
  await load(page, { place: 'Wetlands', free: band(300, 2090) });
  expect((await lay(page)).ornaments.right).toBe('hidden');
});

// T14b: the article is resized to the lead's size after the place was verified, so a larger article
// than the one verified could leave line 0 over its band (the fresh-corpus case s02 printed 444px in
// 440.7px). converge() now measures the place again after that resize. Both cases below were found
// by sweeping places, leads, faces and band widths against the engine without the re-check: each
// printed a place line one pixel over its data-sc-w (586 in 585), past the half-pixel tolerance.
test('the place line still fits its band after the article is resized to the lead\'s size', async ({ page }) => {
  const cases: Array<{ face: string; place: string; w: number }> = [
    { face: 'Arial, Helvetica, sans-serif', place: 'Emerald Hills', w: 600 },
    { face: 'Georgia, serif', place: 'Barony of the Iron Mountains', w: 600 },
  ];
  for (const c of cases) {
    await load(page, { place: c.place, lead: 'of the Shire of', heading: 'centered', face: c.face, art: true, free: band(1275 - c.w / 2, 1275 + c.w / 2) });
    await lay(page);
    const r = await page.evaluate(() => {
      const el = document.querySelector<HTMLElement>('[data-sc-role="place"]')!;
      const lines = [...el.querySelectorAll<HTMLElement>('.sc-line')];
      lines.forEach((l) => { l.style.width = 'max-content'; });
      return {
        overflow: el.hasAttribute('data-sc-overflow'),
        lines: lines.map((l) => ({ w: Math.max(l.scrollWidth, l.offsetWidth), allowed: Number(l.dataset.scW) })),
        art: !!el.querySelector('.sc-art'),
      };
    });
    expect(r.art, `${c.place}: the case must carry an article`).toBe(true);
    expect(r.lines.length).toBeGreaterThan(0);
    for (const l of r.lines) {
      expect(r.overflow || l.w <= l.allowed + 0.5, `${c.face} / ${c.place} / ${c.w}: place line ${l.w}px in ${l.allowed}px`).toBe(true);
    }
  }
});
