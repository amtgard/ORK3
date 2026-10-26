import { test, expect, Page } from '@playwright/test';
import * as path from 'path';

// The DOM layer of the typeset engine on a synthetic certificate. It needs no app and no
// login: the page is built here, with the spec §8.1 markup (a .Award with column padding,
// data-sc-role elements, a data-sc-free profile) and a webfont-free system face, so a
// measured word is the same on every run. The real fixtures wait for the real markup
// (typeset-dom.spec.ts, after Task 8 and the autofit wiring).

// ORK3_TYPESET_JS points the test at another copy of the engine (to watch it fail on one).
const ENGINE = process.env.ORK3_TYPESET_JS ?? path.resolve(__dirname, '../../../orkui/template/revised-frontend/script/scrollery-typeset.js');

// 2550 wide with a 475px gutter each side: a 1600px column centred on x = 1275. The role
// element's parent (p.AwardeeName) is 1.7em, so the em-relative-to-parent write is
// exercised, not just the root em. .sc-line is shrink-to-fit and centred, which is what
// the stylesheet will have to give it (spec §8.2).
function pageHtml(o: { name?: string; award?: string; free?: string; up?: number; blank?: boolean } = {}): string {
  const name = o.name ?? 'Halo';
  const award = o.award ?? 'Order of the Rose';
  const free = o.free ? ` data-sc-free="${o.free}"` : '';
  const up = o.up ? ` data-scroll-up="${o.up}"` : '';
  const nameInner = o.blank ? '<img class="Underline" src="data:image/gif;base64,R0lGODlhAQABAAAAACw=" alt="">' : esc(name);
  return `<!doctype html><html><head><meta charset="utf-8"><style>
    html, body { margin: 0; }
    .Award { position: relative; box-sizing: border-box; width: 2550px; padding: 200px 475px 0; font: 100px/1.2 Arial, Helvetica, sans-serif; text-align: center; }
    .AwardeeName { margin: 0; font-size: 1.7em; }
    .Order { margin-top: 40px; }
    .sc-line { display: block; white-space: nowrap; width: max-content; margin: 0 auto; }
  </style></head><body${up}>
  <div class="Award"${free}>
    <section class="ScName"><p class="AwardeeName"><span data-scroll-text="player" data-sc-role="name" data-blank="x">${nameInner}</span></p></section>
    <section class="Order" data-sc-role="award">${esc(award)}</section>
  </div></body></html>`;
}

function esc(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;');
}

// A profile that leaves the same 750px (900..1650, centre 1275) free at every height.
const NARROW = '0,100|' + Array(40).fill(900).join(',') + '|' + Array(40).fill(1650).join(',');

async function load(page: Page, o: Parameters<typeof pageHtml>[0] = {}) {
  await page.setContent(pageHtml(o));
  await page.addScriptTag({ path: ENGINE });
}

const lay = (page: Page) =>
  page.evaluate(() => (window as any).ScrolleryTypeset.layoutTop(document.querySelector('.Award')));

interface Role { size: number; lines: string[]; planned: number; printed: number; overflow: boolean; text: string }
const role = (page: Page, which: 'name' | 'award'): Promise<Role> =>
  page.evaluate((w) => {
    const el = document.querySelector<HTMLElement>(`[data-sc-role="${w}"]`)!;
    return {
      size: Number(el.dataset.scSize),
      lines: [...el.querySelectorAll('.sc-line')].map((l) => l.textContent || ''),
      planned: Number(el.dataset.scPlanned),
      printed: Number(el.dataset.scLines),
      overflow: el.hasAttribute('data-sc-overflow'),
      text: el.textContent || '',
    };
  }, which);

const PERSONAS = [
  'Halo',
  'Stormcrow Giggleshadows, the Relentless',
  'William "Billy Bob" FitzRobert',
  'Sir Hitorimono Wanderingblade, the Scapegoat of the Eastern Marches',
];

test('planned equals printed, and every line fits its band', async ({ page }) => {
  for (const name of PERSONAS) {
    for (const free of [undefined, NARROW]) {
      await load(page, { name, award: 'Guildmaster of the Twilight Peak Anti-Paladins', free });
      await lay(page);
      for (const w of ['name', 'award'] as const) {
        const r = await role(page, w);
        expect(r.printed, `${w} of ${name}`).toBe(r.planned);
        expect(r.lines.length).toBe(r.planned);
      }
      // A line may exceed its band only in a role the engine flagged: the longest persona
      // in the narrow band does not fit at the minimum size, and says so.
      const bad = await page.evaluate(() =>
        [...document.querySelectorAll<HTMLElement>('.sc-line')]
          .filter((l) => !l.closest('[data-sc-overflow]'))
          .filter((l) => l.scrollWidth > Number(l.dataset.scW) + 0.5).length);
      expect(bad, `lines wider than data-sc-w for ${name}`).toBe(0);
    }
  }
});

test('a persona that cannot fit the band is flagged, at the minimum, with every word kept', async ({ page }) => {
  const name = 'Sir Hitorimono Wanderingblade, the Scapegoat of the Eastern Marches';
  await load(page, { name, free: NARROW });
  await lay(page);
  const r = await role(page, 'name');
  expect(r.overflow).toBe(true);
  expect(r.size).toBeCloseTo(0.8, 2);
  expect(r.lines.join(' ').replace(/\u00a0/g, ' ')).toBe(name);
});

test('a short persona takes one line at name.max; a long one takes at least two', async ({ page }) => {
  await load(page, { name: 'Halo' });
  await lay(page);
  const short = await role(page, 'name');
  expect(short.lines).toEqual(['Halo']);
  expect(short.size).toBeCloseTo(2.3, 3);

  await load(page, { name: 'Stormcrow Giggleshadows, the Relentless Slayer of the Eastern Marches' });
  await lay(page);
  const long = await role(page, 'name');
  expect(long.planned).toBeGreaterThanOrEqual(2);
  expect(long.lines.length).toBeGreaterThanOrEqual(2);
});

test('a quoted nickname is never split', async ({ page }) => {
  // The narrow band forces several lines, so a break inside the quotes is on offer.
  await load(page, { name: 'William "Billy Bob" FitzRobert the Younger of Nowhere', free: NARROW });
  await lay(page);
  const r = await role(page, 'name');
  expect(r.lines.length).toBeGreaterThanOrEqual(2);
  for (const l of r.lines) {
    expect(l.includes('"Billy') === l.includes('Bob"'), l).toBe(true);
  }
  expect(r.lines.join(' ').replace(/ /g, ' ')).toBe('William "Billy Bob" FitzRobert the Younger of Nowhere');
});

test('the award never outranks the name unless it is at its floor', async ({ page }) => {
  const awards = ['Order of the Rose', 'Guildmaster of the Twilight Peak Anti-Paladins', 'Dragon'];
  for (const up of [0, 2]) {
    for (const name of PERSONAS) {
      for (const award of awards) {
        await load(page, { name, award, up });
        await lay(page);
        const n = await role(page, 'name');
        const a = await role(page, 'award');
        const floor = 0.7 * (up ? 1.5 : 1);
        expect(a.size <= 0.85 * n.size + 0.001 || Math.abs(a.size - floor) < 0.001, `${name} / ${award} up=${up}: ${a.size} vs ${n.size}`).toBe(true);
      }
    }
  }
});

test('fitting twice changes nothing', async ({ page }) => {
  await load(page, { name: 'Sir Hitorimono Wanderingblade, the Scapegoat', award: 'Guildmaster of the Twilight Peak', free: NARROW });
  await lay(page);
  const first = await page.evaluate(() => document.querySelector('.Award')!.innerHTML);
  await lay(page);
  const second = await page.evaluate(() => document.querySelector('.Award')!.innerHTML);
  expect(second).toBe(first);
  expect(first).toContain('sc-line');
});

test('a textContent write re-lays out from the new text', async ({ page }) => {
  await load(page, { name: 'Stormcrow Giggleshadows, the Relentless Slayer of the Eastern Marches' });
  await lay(page);
  expect((await role(page, 'name')).lines.length).toBeGreaterThanOrEqual(2);
  await page.evaluate(() => { document.querySelector('[data-sc-role="name"]')!.textContent = 'Halo'; });
  await lay(page);
  const r = await role(page, 'name');
  expect(r.lines).toEqual(['Halo']);
  expect(r.size).toBeCloseTo(2.3, 3);
  expect(await page.evaluate(() => document.querySelector('[data-sc-role="name"]')!.getAttribute('data-sc-text'))).toBe('Halo');
});

test('an empty name is skipped, its writing line kept, and the award still set', async ({ page }) => {
  await load(page, { blank: true });
  const report = await lay(page);
  const state = await page.evaluate(() => {
    const el = document.querySelector<HTMLElement>('[data-sc-role="name"]')!;
    return { img: el.querySelectorAll('img.Underline').length, lines: el.querySelectorAll('.sc-line').length,
      size: el.hasAttribute('data-sc-size'), overflow: el.hasAttribute('data-sc-overflow'), text: el.hasAttribute('data-sc-text') };
  });
  expect(state).toEqual({ img: 1, lines: 0, size: false, overflow: false, text: false });
  expect(report.roles.name).toBeUndefined();
  expect(report.roles.award).toBeTruthy();
  expect((await role(page, 'award')).lines.length).toBeGreaterThanOrEqual(1);
});

test('a narrow data-sc-free band lowers the size', async ({ page }) => {
  await load(page, { name: 'Bartholomew', award: 'Dragon' });
  await lay(page);
  const wide = await role(page, 'name');
  await load(page, { name: 'Bartholomew', award: 'Dragon', free: NARROW });
  await lay(page);
  const narrow = await role(page, 'name');
  expect(wide.size).toBeCloseTo(2.3, 3);
  expect(narrow.size).toBeLessThan(wide.size - 0.3);
  const w = await page.evaluate(() => Number(document.querySelector<HTMLElement>('[data-sc-role="name"] .sc-line')!.dataset.scW));
  expect(w).toBeLessThanOrEqual(750);
});

test('multi-up lifts the floors', async ({ page }) => {
  const long = 'Sir Hitorimono Wanderingblade the Scapegoat of the Eastern Marches and the Western Reaches of Nowhere';
  await load(page, { name: long });
  await lay(page);
  const one = await role(page, 'name');
  expect(one.size).toBeLessThan(1.2);

  await load(page, { name: long, up: 2 });
  await lay(page);
  const two = await role(page, 'name');
  expect(two.size).toBeGreaterThanOrEqual(1.2 - 0.001);
  expect(two.overflow).toBe(true);
  // The award's floor lifts too: 0.70 x 1.5.
  expect((await role(page, 'award')).size).toBeGreaterThanOrEqual(1.05 - 0.001);
});

test('readContext, measureWords and freeWidth', async ({ page }) => {
  await load(page, { free: NARROW });
  const r = await page.evaluate(() => {
    const TS = (window as any).ScrolleryTypeset;
    const award = document.querySelector<HTMLElement>('.Award')!;
    const ctx = TS.readContext(award);
    const nameEl = document.querySelector<HTMLElement>('[data-sc-role="name"]')!;
    const m = TS.measureWords(nameEl, ['Halo', 'Halo', 'W']);
    return {
      rootPx: ctx.rootPx, floor: ctx.floorScale, col: ctx.column, heading: ctx.heading,
      halo: m.widths['Halo'], space: m.space,
      centred: TS.freeWidth(ctx, 300, 400, true), flat: TS.freeWidth(ctx, 300, 400, false),
      offBand: TS.freeWidth(TS.readContext(award), 99999, 100000, true),
    };
  });
  expect(r.rootPx).toBe(100);
  expect(r.floor).toBe(1);
  expect(r.col).toEqual({ left: 475, right: 2075, width: 1600, cx: 1275 });
  expect(r.heading).toBeNull();
  expect(r.halo).toBeGreaterThan(1.5);
  expect(r.halo).toBeLessThan(3);
  expect(r.space).toBeGreaterThan(0.2);
  expect(r.centred).toBe(750);
  expect(r.flat).toBe(750);
  expect(r.offBand).toBe(1600);
});
