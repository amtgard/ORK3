/**
 * ORKPLE II companies and global search (Phase 3 of docs/superpowers/plans/2026-11-01-orkple-read-surfaces.md).
 * Walks the kingdom COMPANIES AND HOUSEHOLDS list (KCG, kingdom 17), the UNIT PROFILE (unit 2541 Asura through search, plus
 * units with a description and history) and SEARCH THE ORK through the #orkple [role=log] text, with the allow-list in strict
 * mode, then checks them against the website's unit list and unit page as an anonymous visitor.
 */
import { test, expect, Page } from '@playwright/test';

const SVC = '../orkservice/Json/index.php?call=';

async function appReachable(baseURL: string): Promise<boolean> {
  try {
    const res = await fetch(baseURL, { method: 'HEAD' });
    return res.ok || res.status === 302 || res.status === 405;
  } catch {
    return false;
  }
}

test.use({ contextOptions: { reducedMotion: 'reduce' } });

const log = (page: Page) => page.locator('#orkple [role=log]');
// The kingdom header's PARKS, EVENTS and AVG counts load behind the screen and are written in just before its last line
// as they arrive, so screen endings and menus are matched without those lines.
const STAT = /^(PARKS|EVENTS|AVG\/WEEK|AVG\/MONTH) (--|\d[\d.,]*)$/;
const menuText = async (page: Page) => ((await log(page).innerText()) || '').split('\n').filter((l) => !STAT.test(l)).join('\n');
const endsOn = (page: Page, re: RegExp) => expect.poll(() => menuText(page), { timeout: 20000 }).toMatch(re);
const calls: string[] = [];
const uiCalls: string[] = [];

test.beforeEach(async ({ baseURL, page }, testInfo) => {
  if (!baseURL || !(await appReachable(baseURL))) {
    testInfo.skip(true, 'ORK3 app not reachable — start docker compose php8 stack');
  }
  calls.length = 0;
  uiCalls.length = 0;
  await page.addInitScript(() => { (window as any).OrkpleStrict = true; });
  page.on('request', (r) => {
    const m = /Json\/index\.php\?call=([^&]+)/.exec(r.url());
    if (m) calls.push(decodeURIComponent(m[1]));
    const u = /index\.php\?Route=((?:Search|SearchAjax|Unit|UnitAjax)\/[A-Za-z_]+)/.exec(r.url());
    if (u && !/Route=Search$/.test(r.url())) uiCalls.push(u[1]);
  });
  // ORKPLE is only live in its event window (or with the preview cookie); the floppy is not on the page otherwise.
  await page.context().addCookies([{ name: 'orkple_preview', value: '1', url: new URL('/', baseURL).href }]);
  await page.goto('./index.php?Route=Search');
});

test.afterEach(async ({ page }) => {
  const allowed: string[] = await page.evaluate(() => ((window as any).OrkpleTerminal ? (window as any).OrkpleTerminal.lib.allowed : null)) || [];
  const violations = await page.evaluate(() => (window as any).OrkpleViolations || []);
  expect(violations).toEqual([]);
  expect(calls.filter((c) => !allowed.includes(c))).toEqual([]);
  expect(uiCalls.filter((c) => !allowed.includes(c))).toEqual([]);
});

async function type(page: Page, text: string) {
  await page.keyboard.type(text);
  await page.keyboard.press('Enter');
}
async function loadTerminal(page: Page) {
  await page.locator('#orkple-floppy').click();
  await page.waitForFunction(() => !!(window as any).OrkpleTerminal);
}
async function toMenu(page: Page) {
  await page.locator('#orkple-floppy').click();
  await expect(log(page)).toContainText('ORKPLE II', { timeout: 10000 });
  await type(page, 'ork');
  await expect(log(page)).toContainText('ORK GAMES', { timeout: 10000 });
}
async function lastScreen(page: Page): Promise<string[]> {
  const lines = (((await log(page).innerText()) || '').split('\n'));
  let idx = -1;
  lines.forEach((l, i) => { if (/PAGE \d+ OF \d+$/.test(l)) idx = i; });
  return idx < 0 ? lines : lines.slice(idx);
}
async function settled(page: Page, title?: RegExp, pageNo?: number): Promise<string[]> {
  let got: string[] = [];
  await expect.poll(async () => {
    got = await lastScreen(page);
    const okTitle = !title || title.test(got[0] || '');
    const okPage = !pageNo || new RegExp('PAGE ' + pageNo + ' OF').test(got[0] || '');
    return okTitle && okPage && /^0\. /.test(got[got.length - 1] || '');
  }, { timeout: 20000 }).toBe(true);
  return got;
}
async function widthOk(page: Page) {
  const lines = ((await log(page).innerText()) || '').split('\n');
  // the ARMS OF line is the screen reader's stand-in for the arms bitmap, never drawn
  for (const l of lines.filter((x) => !/^ARMS OF /.test(x))) expect(l.length, `"${l}"`).toBeLessThanOrEqual(39);
}
async function pickRow(page: Page, re: RegExp): Promise<void> {
  // starts on whatever page is showing and flips round (9 on the last page wraps to the first) until a full cycle is done
  let s = await settled(page);
  const total = +/PAGE \d+ OF (\d+)/.exec(s[0])![1];
  for (let n = 0; n < total; n++) {
    const hit = s.map((l) => re.exec(l)).find(Boolean) as RegExpExecArray | undefined;
    if (hit) { await type(page, hit[1]); return; }
    const was = s[0];
    await type(page, '9');
    await expect.poll(async () => (await lastScreen(page))[0], { timeout: 20000 }).not.toBe(was);
    s = await settled(page);
  }
  throw new Error('no row matching ' + re);
}
async function shot(page: Page, name: string) {
  await page.waitForTimeout(150);
  await page.locator('#orkple canvas').screenshot({ path: test.info().outputPath(name + '.png') });
}
// The text lines of a pager screen: between the rule under the title and the menu keys at the foot.
function bodyOf(s: string[]): string[] {
  const out = s.slice(2);
  while (out.length && /^[0-9]\. /.test(out[out.length - 1])) out.pop();
  while (out.length && out[out.length - 1] === '') out.pop();
  return out;
}
// The words of a text, upper case, nothing else: how a screen and the page are compared.
const words = (s: string) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase().replace(/[^A-Z0-9]+/g, ' ').replace(/^ | $/g, '');

const PROFILE_END = /0\. KINGDOM LIST\s*$/;
async function toKcgProfile(page: Page) {
  await toMenu(page);
  await type(page, '1');
  await pickRow(page, /^(\d)\. KINGDOM OF CRYSTAL GROVES/);
  await endsOn(page, PROFILE_END);
}
const UNITS = /^COMPANIES AND HOUSEHOLDS/;
async function toKcgUnits(page: Page) {
  await toKcgProfile(page);
  await type(page, '4');
  return settled(page, UNITS);
}
async function toSearch(page: Page) {
  await toMenu(page);
  await type(page, '5');
  await endsOn(page, /0\. MAIN MENU\s*$/);
}
async function search(page: Page, term: string) {
  await type(page, term);
  return settled(page, /^FOUND /);
}
// Fixtures for the unit profile walk: which players and companies a search finds, and a unit's leaders and texts,
// change with the data. The search is served for 'asura' (two players, then unit 2541 at row 3), and fixUnit serves
// unit 2541 itself with one leader and no ABOUT or HISTORY text. The parity tests below read the real records.
const SEARCH_ASURA = {
  players: [
    { type: 'player', id: 900001, name: 'Asura', abbr: 'OLD', park: 'Test Park', active: 1 },
    { type: 'player', id: 900002, name: 'Bakasura', abbr: 'RM', park: 'Test Park', active: 1 },
  ],
  parks: [], kingdoms: [],
  units: [{ type: 'unit', id: 2541, name: 'Asura', unitType: 'Company' }],
};
const UNIT_ASURA = { UnitId: 2541, Name: 'Asura', Type: 'Company', Active: 'Active', Description: '', History: '', Url: '', HasHeraldry: 1 };
const json = (body: unknown) => ({ contentType: 'application/json', body: JSON.stringify(body) });
// The patterns differ from the ones the failure tests route and unroute, so those leave these in place.
async function fixSearch(page: Page) {
  await page.route(/SearchAjax\/universal&q=asura$/i, (r) => r.fulfill(json(SEARCH_ASURA)));
}
async function fixUnit(page: Page) {
  await page.route(/call=Unit\/GetUnit&.*2541/, (r) => r.fulfill(json({ Status: { Status: 0 }, Unit: UNIT_ASURA })));
  await page.route(/Search\/unitsearch&q=Asura$/, (r) => r.fulfill(json([{ UnitId: 2541, LeaderNames: 'Test Leader' }])));
}

// Pick the row after a group heading (PLAYERS, PARKS, KINGDOMS, COMPANIES, HOUSEHOLDS) whose text matches.
async function pickInGroup(page: Page, group: string, re: RegExp): Promise<void> {
  const s = await settled(page);
  let inGroup = false;
  for (const l of s) {
    if (/^[A-Z]+$/.test(l)) inGroup = group === 'UNITS' ? /^(COMPANIES|HOUSEHOLDS)$/.test(l) : l === group;
    const m = inGroup ? re.exec(l) : null;
    if (m) { await type(page, m[1]); return; }
  }
  throw new Error('no ' + group + ' row matching ' + re);
}
const UNIT_END = /2\. HISTORY\s+0\. [A-Z ]+\s*$/;

// ---- the menu ----

test('the main menu keeps 1 to 4 where they were and adds 5. SEARCH THE ORK and 6. THE ORK TODAY', async ({ page }) => {
  await toMenu(page);
  const t = (await log(page).innerText()) || '';
  expect(t).toMatch(/1\. KINGDOMS\s+2\. PLAYERS\s+3\. MY PROFILE\s+4\. ORK GAMES\s+5\. SEARCH THE ORK\s+6\. THE ORK TODAY\s*(E\.P\.[^\n]*)?\s*$/);
  await type(page, '7');
  await expect(log(page)).toContainText('?ILLEGAL QUANTITY ERROR');
  await type(page, '4');
  await endsOn(page, /7\. FROGGORK[\s\S]*0\. MAIN MENU\s*$/);
});

test('the kingdom profile gets 4. COMPANIES AND HOUSEHOLDS after PAST EVENTS; a kingdom with principalities gets 5', async ({ page }) => {
  await toKcgProfile(page);
  expect(await menuText(page)).toMatch(/1\. PARKS\s+2\. EVENTS\s+3\. PAST EVENTS\s+4\. COMPANIES AND HOUSEHOLDS\s+5\. KNIGHTS AND MASTERS\s+0\. KINGDOM LIST\s*$/);
  await type(page, '0');
  await settled(page, /^KINGDOMS/);
  await pickRow(page, /^(\d)\. KINGDOM OF NORTHERN LIGHTS/);
  await endsOn(page, PROFILE_END);
  expect(await menuText(page)).toMatch(/1\. PARKS\s+2\. EVENTS\s+3\. PRINCIPALITIES\s+4\. PAST EVENTS\s+5\. COMPANIES AND HOUSEHOLDS\s+6\. KNIGHTS AND MASTERS\s+0\. KINGDOM LIST\s*$/);
});

// ---- the kingdom list ----

test('KCG COMPANIES AND HOUSEHOLDS: largest first, CO/HH tags, * for inactive, filter, back paths', async ({ page }) => {
  await toKcgProfile(page);
  await type(page, '4');
  let s = await settled(page, UNITS);
  await widthOk(page);
  expect(s[0]).toMatch(/^COMPANIES AND HOUSEHOLDS +PAGE 1 OF 15$/);
  const rows = s.filter((l) => /^[1-7]\. /.test(l));
  expect(rows).toHaveLength(7);
  expect(rows.every((l) => /^\d\. .+ (CO|HH)\*?$/.test(l))).toBe(true);
  expect(s.join('\n')).toContain('CO COMPANY HH HOUSEHOLD * INACTIVE');
  expect(s.join('\n')).toMatch(/8\. COMPANIES ONLY\s+9\. MORE\s+0\. BACK$/);
  await shot(page, 'units-list');
  // the last page says the list is the website's 100 largest
  for (let n = 1; n < 15; n++) { await type(page, '9'); await settled(page, UNITS, n + 1); }
  s = await settled(page, UNITS, 15);
  expect(s.join('\n')).toContain('LARGEST 100 ONLY. SEARCH FOR OTHERS.');
  expect(s.join('\n')).toMatch(/9\. FIRST PAGE\s+0\. BACK$/);
  // the filter: companies only, households only, all again; each restarts at page 1
  await type(page, '8');
  s = await settled(page, /^COMPANIES +PAGE 1 OF/);
  expect(s.filter((l) => /^[1-7]\. /.test(l)).every((l) => / CO\*?$/.test(l))).toBe(true);
  expect(s.join('\n')).toContain('8. HOUSEHOLDS ONLY');
  await type(page, '8');
  s = await settled(page, /^HOUSEHOLDS +PAGE 1 OF/);
  expect(s.filter((l) => /^[1-7]\. /.test(l)).every((l) => / HH\*?$/.test(l))).toBe(true);
  expect(s.join('\n')).toContain('8. ALL UNITS');
  await type(page, '8');
  await settled(page, /^COMPANIES AND HOUSEHOLDS +PAGE 1 OF 15/);
  await type(page, '0');
  await endsOn(page, /4\. COMPANIES AND HOUSEHOLDS\s+5\. KNIGHTS AND MASTERS\s+0\. KINGDOM LIST\s*$/);
  await type(page, '0');
  await settled(page, /^KINGDOMS/);
  await type(page, '9');   // keys the list does not offer are errors, not crashes
});

test('the list is the website\'s default unit list for KCG, with the website\'s inactive rule', async ({ page, request }) => {
  const s0 = await toKcgUnits(page);
  expect(s0.length).toBeGreaterThan(8);
  const seen: Array<{ name: string; code: string; star: boolean }> = [];
  for (let n = 1; n <= 15; n++) {
    const s = await settled(page, UNITS, n);
    s.filter((l) => /^[1-7]\. /.test(l)).forEach((l) => {
      const m = /^\d\. (.+?) +(CO|HH)(\*?)$/.exec(l)!;
      seen.push({ name: m[1], code: m[2], star: m[3] === '*' });
    });
    if (n < 15) await type(page, '9');
  }
  expect(seen).toHaveLength(100);
  // the website's own list
  const web: any[] = await (await request.get('./index.php?Route=Search/unitsearch&KingdomId=17&q=')).json();
  expect(web).toHaveLength(100);
  const key = (name: string, t: string) => words(name) + '|' + (t === 'Company' ? 'CO' : 'HH');
  const mine = new Map(seen.map((u) => [words(u.name) + '|' + u.code, u]));
  const overlap = web.filter((u) => mine.has(key(u.Name, u.Type)));
  expect(overlap.length).toBeGreaterThanOrEqual(95);   // ties at the 100th place may fall either way between two calls
  // the page fills its Active column from Search/unitactivity (25 ids a call): * is exactly "no member signed in"
  const active: Record<string, number> = {};
  for (let i = 0; i < overlap.length; i += 25) {
    const part = overlap.slice(i, i + 25).map((u) => u.UnitId).join(',');
    Object.assign(active, await (await request.get('./index.php?Route=Search/unitactivity&ids=' + part)).json());
  }
  const wrong = overlap.filter((u) => mine.get(key(u.Name, u.Type))!.star !== !(active[u.UnitId] > 0)).map((u) => u.Name);
  expect(wrong).toEqual([]);
  // largest first: the service's own member counts never rise down the list
  const sizes = new Map<string, number>();
  web.forEach((u) => sizes.set(key(u.Name, u.Type), +u.MemberCount));
  const order = seen.map((u) => sizes.get(words(u.name) + '|' + u.code)).filter((x) => x !== undefined) as number[];
  for (let i = 1; i < order.length; i++) expect(order[i]).toBeLessThanOrEqual(order[i - 1]);
  // no retired unit
  const retired = await (await request.get('./index.php?Route=Search/unitsearch&KingdomId=17&q=&include=1')).json();
  expect(retired.length).toBe(100);
});

test('a row opens the unit; 0 returns to the same page of the same filter', async ({ page }) => {
  await toKcgUnits(page);
  await type(page, '9');
  await settled(page, UNITS, 2);
  await type(page, '8');
  await settled(page, /^COMPANIES +PAGE 1 OF/);
  await type(page, '9');
  const s = await settled(page, /^COMPANIES +PAGE 2 OF/);
  const row = s.find((l) => /^3\. /.test(l))!;
  const name = /^3\. (.+?) +CO\*?$/.exec(row)![1];
  await type(page, '3');
  await endsOn(page, /2\. HISTORY\s+0\. UNIT LIST\s*$/);
  expect((await log(page).innerText()) || '').toContain('ARMS OF ' + name);
  await type(page, '0');
  s.length && await settled(page, /^COMPANIES +PAGE 2 OF/);
  await type(page, '0');
  await endsOn(page, /4\. COMPANIES AND HOUSEHOLDS\s+5\. KNIGHTS AND MASTERS\s+0\. KINGDOM LIST\s*$/);
});

// ---- the unit profile ----

test('unit 2541 (Asura) through SEARCH THE ORK: type, leaders, arms, empty ABOUT and HISTORY, 0 returns to the results', async ({ page }) => {
  await fixSearch(page);
  await fixUnit(page);
  await toSearch(page);
  await widthOk(page);
  expect((await log(page).innerText()) || '').toMatch(/SEARCH THE ORK\s+=+\s+TYPE A NAME\. FINDS PLAYERS, PARKS,/);
  const s = await search(page, 'asura');
  await widthOk(page);
  const t = s.join('\n');
  expect(t).toMatch(/PLAYERS\n1\. ASURA +\(OLD\)\n2\. BAKASURA +\(RM\)\nCOMPANIES\n3\. ASURA\n/);
  await shot(page, 'search-results');
  await type(page, '3');
  await endsOn(page, UNIT_END);
  const u = (await log(page).innerText()) || '';
  const prof = u.slice(u.lastIndexOf('ARMS OF'));
  expect(prof).toMatch(/^ARMS OF ASURA\nASURA\nCOMPANY\n=+\nLEADERS\nTEST LEADER\n/);
  expect(prof).toMatch(/1\. ABOUT\n2\. HISTORY\n0\. RESULTS\s*$/);
  await widthOk(page);
  await shot(page, 'unit-asura');
  await type(page, '1');
  await settled(page, /^ABOUT/);
  expect((await log(page).innerText()) || '').toMatch(/NO DESCRIPTION PROVIDED\.\s+0\. BACK\s*$/);
  await type(page, '0');
  await endsOn(page, UNIT_END);
  await type(page, '2');
  await settled(page, /^HISTORY/);
  expect((await log(page).innerText()) || '').toMatch(/NO HISTORY PROVIDED\.\s+0\. BACK\s*$/);
  await type(page, '0');
  await endsOn(page, UNIT_END);
  await type(page, '3');
  await expect(log(page)).toContainText('?ILLEGAL QUANTITY ERROR');
  await type(page, '0');
  await settled(page, /^FOUND ASURA/);
  expect((await log(page).innerText()) || '').toMatch(/COMPANIES\n3\. ASURA\n/);
});

test('unit 2541 matches the unit page for an anonymous visitor: name, type, leaders', async ({ page, request }) => {
  const html = await (await request.get('./index.php?Route=Unit/index/2541')).text();
  const name = /<h1 class="un-hero-name">([^<]*)</.exec(html)![1].trim();
  const unitType = /un-type-badge">\s*<i[^>]*><\/i>\s*([A-Za-z]+)/.exec(html)![1];
  // leaders on the page: the roster's captains and lords
  const body = /<tbody>([\s\S]*?)<\/tbody>/.exec(html.slice(html.indexOf('un-roster-table')))![1];
  const leaders = [...body.matchAll(/<tr>([\s\S]*?)<\/tr>/g)].map((m) => {
    const tds = [...m[1].matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map((x) => x[1].replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim());
    return { persona: tds[0].replace(/\(inactive\)/, '').trim(), role: tds[3] };
  }).filter((r) => /^(Captain|Lord)$/.test(r.role)).map((r) => r.persona).sort();
  await fixSearch(page);   // the search is a fixture (row 3 is unit 2541); the unit and its leaders are real
  await toSearch(page);
  await search(page, 'asura');
  await type(page, '3');
  await endsOn(page, UNIT_END);
  const u = (await log(page).innerText()) || '';
  const prof = u.slice(u.lastIndexOf('ARMS OF'));
  expect(words(prof)).toContain(words('ARMS OF ' + name + ' ' + name + ' ' + unitType));
  expect(words(prof)).toContain(words(leaders.join(' ')));
  expect(leaders.length).toBeGreaterThan(0);
  // no member list: no roster persona other than the leaders is on any screen
  const others = [...body.matchAll(/<tr>([\s\S]*?)<\/tr>/g)].map((m) => /<a [^>]*>\s*([^<]*)</.exec(m[1])![1].trim()).filter((p) => !leaders.includes(p) && p.length > 4);
  others.forEach((p) => expect(words(prof)).not.toContain(words(p)));
});

test('a unit with a description and a history: ABOUT and HISTORY carry the page\'s text, wrapped, paged, 0 returns', async ({ page, request }) => {
  // units with both texts and heraldry; Archon (23) and Cobra Kai Dojo (12) and the long Hojo Clan (27)
  for (const id of [23, 12, 27]) {
    const html = await (await request.get('./index.php?Route=Unit/index/' + id)).text();
    const name = /<h1 class="un-hero-name">([^<]*)</.exec(html)![1].trim();
    const cards = [...html.matchAll(/<div class="kn-description-body"[^>]*>([\s\S]*?)<\/div>/g)].map((m) => m[1].replace(/<[^>]*>/g, ' ').replace(/&amp;/g, '&').replace(/&#039;/g, "'").replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>'));
    expect(cards.length).toBe(2);
    await page.goto('./index.php?Route=Search');
    await toSearch(page);
    await type(page, name.slice(0, 20));
    await settled(page, /^FOUND /);
    await pickInGroup(page, 'UNITS', new RegExp('^(\\d)\\. ' + name.replace(/[^A-Za-z0-9]+/g, '.*').toUpperCase().slice(0, 30)));
    await endsOn(page, UNIT_END);
    for (const [key, i] of [['1', 0], ['2', 1]] as Array<[string, number]>) {
      await type(page, key);
      const first = await settled(page, key === '1' ? /^ABOUT/ : /^HISTORY/);
      let all = bodyOf(first);
      const pages = +/PAGE 1 OF (\d+)/.exec(first[0])![1];
      for (let n = 2; n <= pages; n++) {
        await type(page, '9');
        const s = await settled(page, key === '1' ? /^ABOUT/ : /^HISTORY/, n);
        all = all.concat(bodyOf(s));
      }
      await widthOk(page);
      const on = words(all.join(' ').replace(/\bWEB .*$/, ''));
      const want = words(cards[i]);
      expect(on.slice(0, 400), `unit ${id} ${key}`).toBe(want.slice(0, 400));
      if (pages > 1) expect(pages).toBeGreaterThan(1);
      if (pages > 1) { await type(page, '9'); await settled(page, key === '1' ? /^ABOUT/ : /^HISTORY/, 1); }
      if (id === 27 && key === '1') await shot(page, 'unit-about-long');
      await type(page, '0');
      await endsOn(page, UNIT_END);
    }
    if (id === 12) await shot(page, 'unit-cobra');
  }
});

test('a retired unit shows RETIRED and the anonymous unit page says retired too', async ({ page, request }) => {
  const html = await (await request.get('./index.php?Route=Unit/index/5')).text();
  expect(html).toContain('has been retired');
  await page.route(/SearchAjax\/universal/, (r) => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ players: [], parks: [], kingdoms: [], units: [{ type: 'unit', id: 5, name: 'DragonStorm', unitType: 'Company' }] }) }));
  await toSearch(page);
  await search(page, 'dragonstorm');
  await type(page, '1');
  await endsOn(page, UNIT_END);
  const u = (await log(page).innerText()) || '';
  expect(u.slice(u.lastIndexOf('ARMS OF'))).toMatch(/^ARMS OF DRAGONSTORM\nDRAGONSTORM\nCOMPANY\nRETIRED\n=+\n/);
});

// ---- SEARCH THE ORK ----

test('results group PLAYERS, PARKS, KINGDOMS, COMPANIES; each opens its profile and 0 returns to the same results', async ({ page, request }) => {
  await toSearch(page);
  const universal = await (await request.get('./index.php?Route=SearchAjax/universal&q=crystal')).json();
  let s = await search(page, 'crystal');
  await widthOk(page);
  expect(s[0]).toMatch(/PAGE 1 OF 2$/);
  const page1 = s.join('\n');
  expect(page1).toMatch(/^PLAYERS\n1\. /m);
  await type(page, '9');
  s = await settled(page, /^FOUND CRYSTAL +PAGE 2 OF 2/);
  await widthOk(page);
  let t = s.join('\n');
  expect(t).toMatch(/^KINGDOMS\n\d\. KINGDOM OF CRYSTAL GROVES +KCG\n/m);
  expect(t).toMatch(/\nCOMPANIES\n\d\. CRYSTAL VALKYRIES\n/);
  expect(t).toMatch(/\nHOUSEHOLDS\n\d\. CRYSTAL CORPS/);
  expect(universal.kingdoms.map((k: any) => k.id)).toContain(17);
  expect(universal.units.map((u: any) => u.name)).toEqual(expect.arrayContaining(['Crystal Corps', 'Crystal Valkyries']));
  // kingdom
  await pickRow(page, /^(\d)\. KINGDOM OF CRYSTAL GROVES/);
  await endsOn(page, /0\. BACK\s*$/);
  expect((await log(page).innerText()) || '').toContain('4. COMPANIES AND HOUSEHOLDS');
  await type(page, '0');
  s = await settled(page, /^FOUND CRYSTAL +PAGE 2 OF 2/);
  expect(s.join('\n')).toBe(t);   // back on the same page, same rows
  // a player, from page 1
  await type(page, '9');
  s = await settled(page, /^FOUND CRYSTAL +PAGE 1 OF 2/);
  expect(s.join('\n')).toBe(page1);
  await type(page, '1');
  await endsOn(page, /0\. BACK\s*$/);
  await expect(log(page)).toContainText('1. CLASSES');
  await type(page, '0');
  s = await settled(page, /^FOUND CRYSTAL +PAGE 1 OF 2/);
  expect(s.join('\n')).toBe(page1);
  // a company
  await type(page, '9');
  await settled(page, /PAGE 2 OF 2/);
  await pickInGroup(page, 'COMPANIES', /^(\d)\. CRYSTAL VALKYRIES/);
  await endsOn(page, UNIT_END);
  await type(page, '0');
  s = await settled(page, /^FOUND CRYSTAL +PAGE 2 OF 2/);
  expect(s.join('\n')).toBe(t);
  // a park
  await type(page, '0');
  await endsOn(page, /0\. MAIN MENU\s*$/);
  await type(page, 'angler');
  s = await settled(page, /^FOUND ANGLER +PAGE 1 OF 2/);
  expect(s.join('\n')).toMatch(/^PLAYERS\n1\. /m);
  await type(page, '9');   // eight players fill page 1; the park follows them
  s = await settled(page, /^FOUND ANGLER +PAGE 2 OF 2/);
  expect(s.join('\n')).toMatch(/^PARKS\n\d\. ANGLER'S RIFT +KCG\n/m);
  await shot(page, 'search-park-results');
  await pickRow(page, /^(\d)\. ANGLER'S RIFT/);
  await endsOn(page, /8\. SIGN-INS BY DAY\s+0\. RESULTS\s*$/);
  await type(page, '0');
  await settled(page, /^FOUND ANGLER +PAGE 2 OF 2/);
});

test('retired kingdoms stay out of the KINGDOMS group, as they are not on the KINGDOMS list', async ({ page, request }) => {
  const u = await (await request.get('./index.php?Route=SearchAjax/universal&q=northern')).json();
  expect(u.kingdoms.map((k: any) => k.id)).toEqual(expect.arrayContaining([20, 23]));   // 23 is retired and the endpoint still returns it
  await toSearch(page);
  let s = await search(page, 'northern');
  let t = s.join('\n');
  if (/PAGE 1 OF 2/.test(s[0])) { await type(page, '9'); s = await settled(page, /PAGE 2 OF 2/); t += '\n' + s.join('\n'); }
  expect(t).toContain('KINGDOM OF NORTHERN LIGHTS');
  expect(t).not.toMatch(/\d\. NORTHERN LIGHTS +NL\n/);
});

test('the player shortcuts KCG:AR and KCG: work in SEARCH THE ORK and in PLAYERS', async ({ page, request }) => {
  // The subject is picked at runtime: an officer of Angler's Rift (KCG:AR) with a plain persona that the
  // site's own search finds under the shortcut.
  const officers = (await (await request.get(`${SVC}Park/GetOfficers&request[ParkId]=1049`)).json()).Officers as any[];
  let who = '';
  for (const o of officers) {
    const p = String(o.Persona || '').trim();
    if (!(+o.MundaneId > 0) || !/^[A-Za-z][A-Za-z ]{2,24}$/.test(p)) continue;
    const r = await (await request.get('./index.php?Route=SearchAjax/universal&q=' + encodeURIComponent('KCG:AR ' + p))).json();
    if ((r.players || []).some((x: any) => +x.id === +o.MundaneId)) { who = p.toUpperCase(); break; }
  }
  test.skip(!who, 'no Angler\'s Rift officer the KCG:AR shortcut finds');
  const rowOf = (s: string[]) => s.map((l) => new RegExp('^(\\d)\\. ' + who + ' +\\(KCG\\)$').exec(l)).find(Boolean) as RegExpExecArray | undefined;
  await toSearch(page);
  let s = await search(page, 'KCG:AR ' + who);
  expect(s[0]).toMatch(new RegExp('^FOUND KCG:AR ' + who));
  expect(s.join('\n')).toMatch(/^PLAYERS$/m);
  const row = rowOf(s);
  expect(row, s.join('\n')).toBeTruthy();
  await type(page, row![1]);
  await endsOn(page, /0\. BACK\s*$/);
  await expect(log(page)).toContainText(who);
  await type(page, '0');
  await settled(page, /^FOUND KCG:AR/);
  await type(page, '0');
  await endsOn(page, /0\. MAIN MENU\s*$/);
  await type(page, 'kcg: ' + who.toLowerCase());
  s = await settled(page, /^FOUND KCG: /);
  expect(s.join('\n')).toContain(who);
  // a bare prefix or one letter is a hint, not a search
  await type(page, '0');
  await type(page, 'KCG:AR');
  await expect(log(page)).toContainText('?MISSING NAME');
  await expect(log(page)).toContainText('SHORTCUT NEEDS A NAME');
  await type(page, 'a');
  await expect(log(page)).toContainText('TYPE AT LEAST 2 LETTERS');
  // PLAYERS (menu 2) still works as before
  await type(page, '0');
  await type(page, '2');
  await expect(log(page)).toContainText('PLAYER SEARCH');
  await type(page, 'KCG:AR ' + who);
  s = await settled(page, /^MATCHES FOR KCG:AR /);
  expect(s.join('\n')).toContain(who);
});

test('SEARCH THE ORK: nothing found re-prompts, EXIT and 0 work, a miss on the results page is an error', async ({ page }) => {
  await fixSearch(page);   // three results, so 8 is not on offer
  await toSearch(page);
  await type(page, 'zzqqxxww');
  await expect(log(page)).toContainText('NOTHING FOUND.');
  await type(page, 'asura');
  await settled(page, /^FOUND ASURA/);
  await type(page, '8');
  await expect(log(page)).toContainText('?ILLEGAL QUANTITY ERROR');
  await type(page, 'zzz');
  await expect(log(page)).toContainText('?SYNTAX ERROR');
  await type(page, '0');
  await endsOn(page, /0\. MAIN MENU\s*$/);   // NEW SEARCH
  await type(page, '0');
  await expect(log(page)).toContainText('5. SEARCH THE ORK');
});

test('search results with every group full page and stay within 39 columns', async ({ page }) => {
  await toSearch(page);
  const s = await search(page, 'dr');
  await widthOk(page);
  const t = s.join('\n');
  expect(t).toMatch(/^PLAYERS$/m);
  expect(s.length).toBeLessThanOrEqual(23);
  await shot(page, 'search-dr');
  if (/PAGE 1 OF 2/.test(s[0])) {
    await type(page, '9');
    const s2 = await settled(page, /^FOUND DR/, 2);
    expect(s2.join('\n')).toMatch(/^(PLAYERS|PARKS|KINGDOMS|COMPANIES|HOUSEHOLDS)$/m);   // the heading repeats on the next page
  }
});

// ---- empty states and failures ----

test('failures: each read gives ?I/O ERROR, a missing record ?FILE NOT FOUND, an empty kingdom its empty state', async ({ page }) => {
  await toKcgProfile(page);
  // UnitSummary fails, then the activity read fails, then both work
  const sum = /call=Report\/UnitSummary/, act = /Search\/unitactivity/;
  // two read errors in a row redraw the screen; a screen change in between starts the count again
  const reset = async () => { await type(page, '1'); await settled(page, /PARKS/); await type(page, '0'); await endsOn(page, PROFILE_END); };
  await page.route(sum, (r) => r.abort());
  await type(page, '4');
  await endsOn(page, /READING DISK\.\.\.\s+\?I\/O ERROR\s+(?:DISK READ FAILED|THE ORK IS SLOW TODAY)\. TRY AGAIN\.\s*$/);
  await page.unroute(sum);
  await reset();
  await page.route(act, (r) => r.abort());
  await page.waitForTimeout(200);
  await type(page, '4');
  await endsOn(page, /READING DISK\.\.\.\s+\?I\/O ERROR\s+(?:DISK READ FAILED|THE ORK IS SLOW TODAY)\. TRY AGAIN\.\s*$/);
  await page.unroute(act);
  await reset();
  // a service refusal
  await page.route(sum, (r) => r.fulfill({ contentType: 'application/json', body: '{"Status":{"Status":5,"Error":"x","Detail":"SELECT secret FROM t"}}' }));
  await type(page, '4');
  await endsOn(page, /READING DISK\.\.\.\s+\?I\/O ERROR\s+(?:DISK READ FAILED|THE ORK IS SLOW TODAY)\. TRY AGAIN\.\s*$/);
  expect((await log(page).innerText()) || '').not.toContain('SELECT secret');
  await page.unroute(sum);
  await reset();
  // an empty kingdom
  // a loaded list is kept for the session, so each shape of answer gets a fresh terminal
  await page.goto('./index.php?Route=Search');
  await toKcgProfile(page);
  await page.route(sum, (r) => r.fulfill({ contentType: 'application/json', body: '{"Status":{"Status":0},"Units":[]}' }));
  await page.waitForTimeout(200);
  await type(page, '4');
  await settled(page, UNITS);
  expect((await log(page).innerText()) || '').toMatch(/NO COMPANIES OR HOUSEHOLDS HERE\.\s+0\. BACK\s*$/);   // no filter key with nothing to filter
  await type(page, '0');
  await endsOn(page, PROFILE_END);
  await page.unroute(sum);
  // only households here: the COMPANIES filter shows its own empty state
  await page.goto('./index.php?Route=Search');
  await toKcgProfile(page);
  await page.route(sum, (r) => r.fulfill({ contentType: 'application/json', body: '{"Status":{"Status":0},"Units":[{"UnitId":9,"Type":"Household","Name":"Hearth","MemberCount":3,"ActiveMemberCount":null,"LeaderNames":"","Url":"","Active":"Active"}]}' }));
  await page.route(act, (r) => r.fulfill({ contentType: 'application/json', body: '{"9":2}' }));
  await page.waitForTimeout(200);
  await type(page, '4');
  let s = await settled(page, UNITS);
  expect(s.join('\n')).toContain('1. HEARTH');
  expect(s.join('\n')).not.toContain('LARGEST');
  expect(s.join('\n')).toMatch(/1\. HEARTH +HH\n/);
  await type(page, '8');
  await settled(page, /^COMPANIES +PAGE 1 OF 1/);
  expect((await log(page).innerText()) || '').toMatch(/NONE OF THAT TYPE HERE\.\s+8\. HOUSEHOLDS ONLY\s+0\. BACK\s*$/);
  await page.unroute(sum);
  await page.unroute(act);
});

test('failures: the unit record and the search', async ({ page }) => {
  await fixSearch(page);
  await fixUnit(page);
  await toSearch(page);
  const uni = /SearchAjax\/universal/;
  await page.route(uni, (r) => r.abort());
  await type(page, 'asura');
  await endsOn(page, /READING DISK\.\.\.\s+\?I\/O ERROR\s+(?:DISK READ FAILED|THE ORK IS SLOW TODAY)\. TRY AGAIN\.\s*$/);
  await page.unroute(uni);
  await type(page, '0');   // a screen change starts the error count again
  await type(page, '5');
  await endsOn(page, /0\. MAIN MENU\s*$/);
  await page.route(uni, (r) => r.fulfill({ contentType: 'application/json', body: '{"nope":1}' }));
  await type(page, 'asura');
  await endsOn(page, /READING DISK\.\.\.\s+\?I\/O ERROR\s+(?:DISK READ FAILED|THE ORK IS SLOW TODAY)\. TRY AGAIN\.\s*$/);
  await page.unroute(uni);
  await page.waitForTimeout(200);
  const again = async () => { await type(page, '0'); await endsOn(page, /0\. MAIN MENU\s*$/); await search(page, 'asura'); };   // a new screen starts the error count again
  await search(page, 'asura');
  // the unit record is gone, then comes back empty, then fails; the leaders read fails and the unit still opens, without LEADERS
  const rec = /call=Unit\/GetUnit/;
  await page.route(rec, (r) => r.fulfill({ contentType: 'application/json', body: '{"Status":{"Status":4,"Error":"x","Detail":null}}' }));
  await type(page, '3');
  await endsOn(page, /READING DISK\.\.\.\s+\?FILE NOT FOUND\s*$/);
  await page.unroute(rec);
  await again();
  await page.route(rec, (r) => r.fulfill({ contentType: 'application/json', body: '{"Status":{"Status":0},"Unit":null}' }));
  await type(page, '3');
  await endsOn(page, /READING DISK\.\.\.\s+\?FILE NOT FOUND\s*$/);
  await page.unroute(rec);
  await again();
  await page.route(rec, (r) => r.abort());
  await type(page, '3');
  await endsOn(page, /READING DISK\.\.\.\s+\?I\/O ERROR\s+(?:DISK READ FAILED|THE ORK IS SLOW TODAY)\. TRY AGAIN\.\s*$/);
  await page.unroute(rec);
  await again();
  const ls = /Search\/unitsearch/;
  await page.route(ls, (r) => r.abort());
  await type(page, '3');
  await endsOn(page, UNIT_END);
  const t = (await log(page).innerText()) || '';
  expect(t.slice(t.lastIndexOf('ARMS OF'))).toMatch(/^ARMS OF ASURA\nASURA\nCOMPANY\n=+\n\n?1\. ABOUT/);
  expect(t.slice(t.lastIndexOf('ARMS OF'))).not.toContain('LEADERS');
  await page.unroute(ls);
});

test('the arms of a unit fall back to the shield when the image is missing', async ({ page }) => {
  await fixSearch(page);
  await fixUnit(page);
  await toSearch(page);
  await search(page, 'asura');
  await page.route(/assets\/heraldry\/unit\//, (r) => r.abort());
  await type(page, '3');
  await endsOn(page, UNIT_END);
  await shot(page, 'unit-shield-fallback');
});

// ---- widths ----

test('nothing over 39 columns or 23 rows prints, even for very long unit names, leaders and texts', async ({ page, request }) => {
  await loadTerminal(page);
  const sum = await (await request.get(SVC + 'Report/UnitSummary&request[KingdomId]=17&request[IncludeCompanies]=1&request[IncludeHouseHolds]=1&request[TopBySize]=1&request[Limit]=100')).json();
  const longest = await (await request.get(SVC + 'Unit/GetUnit&request[UnitId]=61')).json();
  const texts: any[] = [];
  for (const id of [9, 11, 12, 14, 27, 38, 61, 3633, 5103]) {
    const d = await (await request.get(SVC + 'Unit/GetUnit&request[UnitId]=' + id)).json();
    if (d.Unit) texts.push(d.Unit);
  }
  expect(texts.length).toBeGreaterThan(5);
  const res = await page.evaluate(({ units, texts, longName }) => {
    const L = (window as any).OrkpleTerminal.lib;
    const bad: string[] = [];
    const chk = (what: string, lines: string[], maxRows = 23) => {
      lines.forEach((l) => { if (l.replace(/\x01/g, '').length > 39) bad.push(what + ' wide: ' + l); });
      if (lines.length > maxRows) bad.push(what + ' tall: ' + lines.length);
    };
    const long = 'The Grand Exceedingly Long-Named Company of the Seven Highlands and Fenlands Brotherhood';
    const leaders = Array.from({ length: 30 }, (_v, i) => 'Sir Persona Number ' + i + ' the Third').join(', ');
    chk('unit header, worst', L.unitParts({ name: long, type: 'Household', retired: true, leaders, desc: '', hist: '', url: '' }, 'UNIT LIST').lines.concat(['SELECT: ']));
    chk('unit header, bare', L.unitParts({ name: 'X', type: 'Company', retired: false, leaders: null }, 'MAIN MENU').lines.concat(['SELECT: ']));
    chk('unit header, no leaders', L.unitParts({ name: 'X', type: 'Company', retired: false, leaders: '' }, 'MAIN MENU').lines.concat(['SELECT: ']));
    chk('unit row, long name', ['1. ' + L.unitRowText({ name: long, type: 'Household', active: 0 })]);
    chk('unit row, odd type', ['1. ' + L.unitRowText({ name: long, type: 'Event', active: 5 })]);
    chk('search row', ['1. ' + L.osRowText({ name: long, tag: '(KCG) (INACTIVE)' })]);
    const groups = ['PLAYERS', 'PARKS', 'KINGDOMS', 'COMPANIES', 'HOUSEHOLDS'];
    const items = Array.from({ length: 8 }, (_v, i) => '1. x');
    chk('search page with every heading', L.pagerLines({ title: 'FOUND ' + long, items, selectable: true, groups: [groups[0], groups[1], groups[2], groups[3], groups[4], groups[4], groups[4], groups[4]], back: 'NEW SEARCH', nineLast: 'x' }).lines.concat(['SELECT: ']));
    const longText = '# Heading\n\n**Bold** [a link](https://example.org/' + 'a'.repeat(100) + ') ![image](https://example.org/i.png)\n\n- item one\n- item two\n\n' + 'Supercalifragilisticexpialidocious '.repeat(10) + 'https://www.example.org/' + 'b'.repeat(120) + '\n\n' + 'word '.repeat(2000);
    const lines = L.unitTextLines(longText);
    if (lines.length !== 240) bad.push('long text not capped: ' + lines.length);
    chk('long text', L.pagerLines({ title: 'ABOUT', items: lines, perPage: 16, back: 'BACK' }).lines.concat(['SELECT: ']));
    if (lines.join('\n').indexOf('IMAGE') >= 0 || lines.join('\n').indexOf('![') >= 0) bad.push('image not removed');
    chk('about with web', L.pagerLines({ title: 'ABOUT', items: L.unitAboutLines({ desc: 'Hello', url: 'https://example.org/' + 'c'.repeat(100) }), perPage: 16, back: 'BACK' }).lines.concat(['SELECT: ']));
    let seen = 0;
    for (const u of units) {
      seen++;
      chk('real row ' + u.Name, ['1. ' + L.unitRowText({ name: u.Name, type: u.Type, active: seen % 2 })]);
      chk('real header ' + u.Name, L.unitParts({ name: u.Name, type: u.Type, retired: false, leaders: u.LeaderNames }, 'UNIT LIST').lines.concat(['SELECT: ']));
    }
    for (const t of texts) {
      seen++;
      chk('real about ' + t.Name, L.pagerLines({ title: 'ABOUT', items: L.unitAboutLines({ desc: t.Description, url: t.Url }), perPage: 16, back: 'BACK' }).lines.concat(['SELECT: ']));
      chk('real history ' + t.Name, L.pagerLines({ title: 'HISTORY', items: L.unitTextLines(t.History), perPage: 16, back: 'BACK' }).lines.concat(['SELECT: ']));
      chk('real header ' + t.Name, L.unitParts({ name: t.Name, type: t.Type, retired: t.Active === 'Retired', leaders: null }, 'RESULTS').lines.concat(['SELECT: ']));
    }
    chk('the longest real name', L.unitParts({ name: longName, type: 'Company', retired: false, leaders: null }, 'RESULTS').lines.concat(['SELECT: ']));
    return { bad, seen };
  }, { units: sum.Units, texts, longName: longest.Unit.Name });
  expect(res.seen).toBeGreaterThan(100);
  expect(res.bad).toEqual([]);
});

test('on screen: a unit with a very long name and a full leader list draws inside the 40 column page', async ({ page }) => {
  await toSearch(page);
  await search(page, 'sacred order of the righteous');
  await type(page, '1');
  await endsOn(page, UNIT_END);
  await widthOk(page);
  await shot(page, 'unit-long-name');
});
