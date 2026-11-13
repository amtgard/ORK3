/**
 * ORKPLE II THE ORK TODAY and profile depth (Phase 4 of docs/superpowers/plans/2026-11-01-orkple-read-surfaces.md).
 * Walks the main menu's date line, THE ORK TODAY (KINGDOM STANDINGS, TOP PARKS, THIS WEEK, ON THE FIELD NOW, WEATHER FOR PLAY),
 * the kingdom KNIGHTS AND MASTERS list and the player ABOUT, COMPANIES and MILESTONES screens through the #orkple [role=log] text
 * and the terminal's 40x24 page, with the allow-list in strict mode, then checks each against the website as an anonymous visitor.
 *
 * Live/stats and Live/recent hold no rows in the local data, so ON THE FIELD NOW is walked with rows mocked from the shapes in
 * system/lib/ork3/class.Live.php (and the real, empty feed is checked against its own answer). WEATHER FOR PLAY is walked with the
 * real feed and with mocked rows for the formats the real day does not carry.
 */
import { test, expect, Page, APIRequestContext } from '@playwright/test';


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
  await page.addInitScript(() => {
    (window as any).OrkpleStrict = true;
    // Keep a handle on the mounted terminal so a test can read its 40x24 page.
    let T: any;
    Object.defineProperty(window, 'OrkpleTerminal', {
      configurable: true,
      get() { return T; },
      set(v) {
        T = v;
        if (v && typeof v.mount === 'function' && !v.__wrapped) {
          const m = v.mount;
          v.mount = function () { const r = m.apply(this, arguments); (window as any).__term = r; return r; };
          v.__wrapped = true;
        }
      },
    });
  });
  page.on('request', (r) => {
    const m = /Json\/index\.php\?call=([^&]+)/.exec(r.url());
    if (m) calls.push(decodeURIComponent(m[1]));
    const u = /index\.php\?Route=((?:Live|Recap|Weather|EraPhoenice|Admin|Reports|Search|SearchAjax)\/[A-Za-z_]+)/.exec(r.url());
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
  for (const l of lines.filter((x) => !/^ARMS OF /.test(x))) expect(l.length, `"${l}"`).toBeLessThanOrEqual(39);
}
// The terminal's own 24 rows. Each must fit 39 columns; the prompt (or a menu key) sits at the foot and nothing has scrolled away.
async function rows(page: Page): Promise<string[]> {
  return page.evaluate(() => (window as any).__term.screenRows());
}
// The row the prompt sits on: straight under the last line of the screen.
async function promptRow(page: Page): Promise<number> {
  return (await rows(page)).findIndex((r) => /^SELECT: /.test(r));
}
async function pickRow(page: Page, re: RegExp): Promise<void> {
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
// Every page of a pager screen, rows only (the numbered lines), by flipping with 9 until the first page is back.
async function allPages(page: Page, title: RegExp): Promise<string[][]> {
  const first = await settled(page, title);
  const total = +/PAGE \d+ OF (\d+)/.exec(first[0])![1];
  const out = [first];
  for (let n = 2; n <= total; n++) {
    await type(page, '9');
    out.push(await settled(page, title, n));
  }
  if (total > 1) { await type(page, '9'); await settled(page, title, 1); }
  return out;
}
// The text lines of a pager screen: between the rule under the title and the menu keys at the foot.
function bodyOf(s: string[]): string[] {
  const out = s.slice(2);
  while (out.length && /^[0-9]\. /.test(out[out.length - 1])) out.pop();
  while (out.length && out[out.length - 1] === '') out.pop();
  return out;
}
// A list row as the terminal builds it: left and right with the gap filled, 36 columns.
const row36 = (left: string, right: string) => left + ' '.repeat(Math.max(1, 36 - left.length - right.length)) + right;
const numbered = (pages: string[][], max = 8) => pages.flat().filter((l) => new RegExp('^[1-' + max + ']\\. ').test(l)).map((l) => l.replace(/^\d\. /, ''));
// The words of a text, upper case, nothing else: how a screen and the page are compared.
const words = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toUpperCase().replace(/[^A-Z0-9]+/g, ' ').replace(/^ | $/g, '');
const decode = (s: string) => s.replace(/&#0?39;/g, "'").replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>');
const SVC = '../orkservice/Json/index.php?call=';

const TODAY_END = /5\. WEATHER FOR PLAY\s+0\. MAIN MENU\s*$/;
async function toToday(page: Page) {
  await toMenu(page);
  await type(page, '6');
  await endsOn(page, TODAY_END);
}
const PROFILE_END = /0\. KINGDOM LIST\s*$/;
async function toKcgProfile(page: Page) {
  await toMenu(page);
  await type(page, '1');
  await pickRow(page, /^(\d)\. KINGDOM OF CRYSTAL GROVES/);
  await endsOn(page, PROFILE_END);
}
async function openPlayer(page: Page, id: number, name: string) {
  await page.route(/SearchAjax\/universal/, (r) => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ players: [{ id, name, abbr: '', park: '', active: 1 }], parks: [], kingdoms: [], units: [] }) }));
  await toMenu(page);
  await type(page, '2');
  await expect(log(page)).toContainText('PLAYER SEARCH');
  await type(page, name.slice(0, 20));
  await settled(page, /^MATCHES FOR/);
  await type(page, '1');
  await expect(log(page)).toContainText('1. CLASSES', { timeout: 20000 });
  await expect(log(page)).toHaveText(/0\. (BACK|MAIN MENU)\s*$/, { timeout: 20000, useInnerText: true });
}
const serverDay = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Chicago' }).format(new Date());

// ---- the main menu and its date line ----

test('the main menu keeps 1 to 5 where they were and adds 6. THE ORK TODAY; 7 is illegal', async ({ page }) => {
  await toMenu(page);
  const t = (await log(page).innerText()) || '';
  expect(t).toMatch(/1\. KINGDOMS\s+2\. PLAYERS\s+3\. MY PROFILE\s+4\. ORK GAMES\s+5\. SEARCH THE ORK\s+6\. THE ORK TODAY/);
  await type(page, '7');
  await expect(log(page)).toContainText('?ILLEGAL QUANTITY ERROR');
  await type(page, '4');
  await endsOn(page, /7\. FROGGORK[\s\S]*0\. MAIN MENU\s*$/);
});

test('the date line is the Era Phoenice date the website shows, on the menu page between the scroll and the options', async ({ page, request }) => {
  const ep = await (await request.get('./index.php?Route=EraPhoenice/today')).json();
  const home = await (await request.get('./index.php?Route=Home')).text();
  expect(home).toContain(ep.ep.formatted);   // an anonymous visitor sees this date on the home page
  await toMenu(page);
  const want = ep.ep.formatted.toUpperCase().replace(/\s+/g, ' ');
  await expect(log(page)).toContainText(want, { timeout: 10000 });
  await expect.poll(async () => (await rows(page)).findIndex((r) => r.trim() === want)).toBeGreaterThan(8);
  const rs = await rows(page);
  const at = rs.findIndex((r) => r.trim() === want);
  expect(rs[at + 1].trim()).toBe('');
  expect(rs[at + 2].trim()).toBe('1. KINGDOMS');
  expect(rs[at - 1]).toMatch(/\.--\.|'--'/);   // the scroll's foot is the row above
  rs.forEach((r) => expect(r.length).toBeLessThanOrEqual(40));
  await shot(page, 'today-menu');
  // back to the menu from a submenu: the date is drawn with the menu at once
  await type(page, '4');
  await endsOn(page, /0\. MAIN MENU\s*$/);
  await type(page, '0');
  await expect.poll(async () => (await rows(page)).some((r) => r.trim() === want)).toBe(true);
});

test('a slow date answer does not delay the menu or land on another screen; a failed or malformed one leaves a blank row', async ({ page }) => {
  let release: () => void = () => {};
  const gate = new Promise<void>((res) => { release = res; });
  await page.route(/Route=EraPhoenice\/today/, async (r) => { await gate; await r.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, ep: { formatted: 'E.P. 99, 1st of Test' }, holiday: null }) }); });
  await toMenu(page);
  // the menu is complete and answers keys while the date is still on its way
  await expect.poll(() => promptRow(page)).toBeGreaterThan(15);
  expect((await rows(page)).some((r) => /E\.P\./.test(r))).toBe(false);
  await type(page, '4');
  await endsOn(page, /0\. MAIN MENU\s*$/);
  release();
  await page.waitForTimeout(400);
  // the late answer found the menu gone: it is on no other screen
  expect((await rows(page)).some((r) => /E\.P\./.test(r))).toBe(false);
  expect((await log(page).innerText()) || '').not.toContain('1ST OF TEST');
  await type(page, '0');
  await expect.poll(async () => (await rows(page)).some((r) => r.trim() === 'E.P. 99, 1ST OF TEST')).toBe(true);   // cached for the next time
});

for (const [label, body, status] of [['a server error', '{"ok":false}', 500], ['a page of nonsense', 'not json', 200], ['an answer without a date', '{"ok":true,"ep":{}}', 200], ['an HTML error', '<html>Oops</html>', 200]] as Array<[string, string, number]>) {
  test(`the date line with ${label}: the menu still works and the row stays blank`, async ({ page }) => {
    await page.route(/Route=EraPhoenice\/today/, (r) => r.fulfill({ status, contentType: 'application/json', body }));
    await toMenu(page);
    await expect.poll(() => promptRow(page)).toBeGreaterThan(15);
    await page.waitForTimeout(300);
    const rs = await rows(page);
    expect(rs.some((r) => /E\.P\.|OOPS|NONSENSE/.test(r))).toBe(false);
    expect((await log(page).innerText()) || '').not.toMatch(/E\.P\./);
    await type(page, '5');
    await expect(log(page)).toContainText('SEARCH THE ORK');
  });
}

test('the date line aborted by the network leaves the menu working', async ({ page }) => {
  await page.route(/Route=EraPhoenice\/today/, (r) => r.abort());
  await toMenu(page);
  await page.waitForTimeout(300);
  await type(page, '6');
  await endsOn(page, TODAY_END);
  expect((await log(page).innerText()) || '').not.toMatch(/E\.P\./);
});

// ---- THE ORK TODAY ----

test('THE ORK TODAY lists five screens with the date and holiday, 0 returns, other keys are errors', async ({ page, request }) => {
  await toToday(page);
  await widthOk(page);
  const t = (await log(page).innerText()) || '';
  const ep = await (await request.get('./index.php?Route=EraPhoenice/today')).json();
  expect(t).toMatch(/THE ORK TODAY\n=+\n/);
  expect(t).toMatch(/1\. KINGDOM STANDINGS\n2\. TOP PARKS\n3\. THIS WEEK\n4\. ON THE FIELD NOW\n5\. WEATHER FOR PLAY\n0\. MAIN MENU\s*$/);
  const rs = await rows(page);
  expect(rs[0].trim()).toBe('THE ORK TODAY');
  expect(rs.some((r) => r.trim() === ep.ep.formatted.toUpperCase())).toBe(true);
  await shot(page, 'today-menu-board');
  await type(page, '6');
  await expect(log(page)).toContainText('?ILLEGAL QUANTITY ERROR');
  await type(page, 'x');
  await expect(log(page)).toContainText('?SYNTAX ERROR');
  await type(page, '0');
  await endsOn(page, /6\. THE ORK TODAY\s*(E\.P\.[^\n]*)?\s*$/);
});

test('a holiday is named on the board', async ({ page }) => {
  await page.route(/Route=EraPhoenice\/today/, (r) => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ ok: true, ep: { formatted: 'E.P. 44, 87th of Harvest' }, holiday: 'Guy Kasama Day' }) }));
  await toMenu(page);
  await expect(log(page)).toContainText('E.P. 44, 87TH OF HARVEST');
  await type(page, '6');
  await endsOn(page, TODAY_END);
  expect((await log(page).innerText()) || '').toMatch(/E\.P\. 44, 87TH OF HARVEST\nHOLIDAY: GUY KASAMA DAY\n1\. KINGDOM STANDINGS/);
});

// ---- KINGDOM STANDINGS ----

test('KINGDOM STANDINGS matches the home page cards: parks and average a week, busiest first; a row opens the kingdom and 0 comes back', async ({ page, request }) => {
  await toToday(page);
  await type(page, '1');
  const pages = await allPages(page, /^KINGDOM STANDINGS/);
  await widthOk(page);
  const first = pages[0];
  expect(first[0]).toMatch(/^KINGDOM STANDINGS +PAGE 1 OF [34]$/);
  expect(first.join('\n')).toContain('RIGHT: PARKS, PLAYERS A WEEK');
  const mine = numbered(pages).map((l) => /^(.+?) +(\d+)P (\d+\.\d)$/.exec(l)!).map((m) => ({ name: m[1], parks: +m[2], weekly: +m[3] }));
  const html = await (await request.get('./index.php?Route=Home')).text();
  const grid = html.slice(html.indexOf('class="hm-kingdoms-grid"'), html.indexOf('class="hm-prinz-grid"'));
  const cards = [...grid.matchAll(/hm-card-name">([^<]*)<[\s\S]*?(\d+) parks?[\s\S]*?([\d,.]+)\/wk/g)].map((m) => ({ name: words(decode(m[1])).replace(/^THE /, '').replace(/^(KINGDOM|EMPIRE) OF (THE )?/, ''), parks: +m[2], weekly: +m[3].replace(/,/g, '') }));
  expect(cards.length).toBeGreaterThan(20);
  expect(mine).toHaveLength(cards.length);
  for (const c of cards) {
    const hit = mine.find((m) => words(m.name) === c.name);
    expect(hit, c.name).toBeTruthy();
    expect(hit!.parks).toBe(c.parks);
    expect(hit!.weekly).toBe(c.weekly);
  }
  for (let i = 1; i < mine.length; i++) expect(mine[i].weekly).toBeLessThanOrEqual(mine[i - 1].weekly);
  await shot(page, 'today-standings');
  // a row opens its kingdom; 0 on the kingdom comes back to the same page of standings
  await type(page, '9');
  const s2 = await settled(page, /^KINGDOM STANDINGS/, 2);
  const pick = /^2\. (.+?) +\d+P/.exec(s2.find((l) => /^2\. /.test(l))!)![1];
  await type(page, '2');
  await expect(log(page)).toHaveText(/0\. BACK\s*$/, { timeout: 20000, useInnerText: true });
  expect((await log(page).innerText()) || '').toContain('ARMS OF');
  expect(words((await log(page).innerText()) || '')).toContain(words(pick).slice(0, 12));
  await type(page, '0');
  await settled(page, /^KINGDOM STANDINGS/, 2);
  await type(page, '0');
  await endsOn(page, TODAY_END);
});

// ---- TOP PARKS ----

test('TOP PARKS is the Top Parks page: 25 parks, six months, average a week, in rank order; a row opens the park and 0 comes back', async ({ page, request }) => {
  await toToday(page);
  await type(page, '2');
  const pages = await allPages(page, /^TOP PARKS/);
  await widthOk(page);
  expect(pages[0][0]).toMatch(/^TOP PARKS +PAGE 1 OF 4$/);
  expect(pages[0].join('\n')).toMatch(/RIGHT: RANK, PLAYERS A WEEK\n\d\d\/\d\d\/\d\d TO \d\d\/\d\d\/\d\d/);
  const mine = numbered(pages).map((l) => /^(.+?) +#(\d+) (\d+\.\d)$/.exec(l)!).map((m) => ({ name: m[1], rank: +m[2], avg: +m[3] }));
  expect(mine).toHaveLength(25);
  const html = await (await request.get('./index.php?Route=Admin/topparks')).text();
  const table = html.slice(html.indexOf('<tbody'), html.indexOf('</tbody>'));
  const pageRows = [...table.matchAll(/rp-rank-badge[^>]*>(\d+)<[\s\S]*?<strong>([\d.,]+)<\/strong><\/td>\s*<td>([^<]*)<\/td>/g)].map((m) => ({ rank: +m[1], avg: +m[2].replace(/,/g, ''), name: words(decode(m[3])) }));
  expect(pageRows).toHaveLength(25);
  pageRows.forEach((p, i) => {
    expect(mine[i].rank).toBe(p.rank);
    expect(words(mine[i].name)).toBe(p.name.slice(0, words(mine[i].name).length));
    expect(Math.abs(mine[i].avg - p.avg)).toBeLessThan(0.06);
  });
  await shot(page, 'today-topparks');
  await type(page, '1');
  await expect(log(page)).toHaveText(/0\. TOP PARKS\s*$/, { timeout: 20000, useInnerText: true });
  expect(words((await log(page).innerText()) || '')).toContain(words(mine[0].name));
  await type(page, '0');
  await settled(page, /^TOP PARKS/, 1);
  await type(page, '0');
  await endsOn(page, TODAY_END);
});

// ---- THIS WEEK ----

test('THIS WEEK carries the Recap page\'s sections; a row opens its player, park or event and 0 comes back to the same page', async ({ page, request }) => {
  const recap = await (await request.get('./index.php?Route=Recap/json')).json();
  expect(recap.WeekStart).toBeTruthy();
  await toToday(page);
  await type(page, '3');
  const pages = await allPages(page, /^THIS WEEK/);
  await widthOk(page);
  const all = pages.flat().join('\n');
  expect(pages[0].join('\n')).toContain('WEEK OF ' + recap.WeekStart.slice(5, 7) + '/' + recap.WeekStart.slice(8, 10) + '/' + recap.WeekStart.slice(2, 4) + ' TO ');
  expect(pages[0].join('\n')).toContain('ALL OF AMTGARD');
  expect(all).toContain('BIGGEST EVENTS');
  expect(all).toContain('MOST ACTIVE PARKS');
  expect(all).toContain('NEW MASTERS');
  if (recap.Knightings.length) expect(all).toContain('NEW KNIGHTS');
  const cap = Math.min(30, recap.NewPlayers.Count);
  expect(all).toContain(recap.NewPlayers.Count > 30 ? 'NEW PLAYERS (FIRST 30 OF ' + recap.NewPlayers.Count + ')' : 'NEW PLAYERS (' + recap.NewPlayers.Count + ')');
  const rowsText = numbered(pages, 6).map(words);
  const expected = [
    ...recap.TopEvents.map((e: any) => e.EventName), ...recap.TopParks.map((p: any) => p.ParkName),
    ...recap.Knightings.map((k: any) => k.Persona), ...recap.Masterhoods.map((k: any) => k.Persona), ...recap.Paragons.map((k: any) => k.Persona),
    ...recap.NewPlayers.Players.slice(0, cap).map((k: any) => k.Persona),
  ];
  expect(rowsText).toHaveLength(expected.length);
  expected.forEach((n: string) => expect(rowsText.some((r) => r.startsWith(words(n).slice(0, 14))), n).toBe(true));
  // the attendance of the top event is its right-hand column
  const topEv = recap.TopEvents[0];
  expect(numbered(pages, 6).some((l) => new RegExp(String(topEv.Attendance) + '$').test(l) && words(l).startsWith(words(topEv.EventName).slice(0, 14)))).toBe(true);
  await shot(page, 'today-week');
  // a master opens the player; 0 comes back to the same page of THIS WEEK
  const mp = words(recap.Masterhoods[0].Persona).slice(0, 14);
  const at = pages.findIndex((pg) => pg.some((l) => /^\d\. /.test(l) && words(l.replace(/^\d\. /, '')).startsWith(mp)));
  expect(at).toBeGreaterThanOrEqual(0);
  for (let k = 0; k < at; k++) { await type(page, '9'); await settled(page, /^THIS WEEK/, k + 2); }
  const key = pages[at].find((l) => /^\d\. /.test(l) && words(l.replace(/^\d\. /, '')).startsWith(mp))![0];
  await type(page, key);
  await expect(log(page)).toContainText('1. CLASSES', { timeout: 20000 });
  await expect(log(page)).toHaveText(/0\. BACK\s*$/, { useInnerText: true });
  await type(page, '0');
  await settled(page, /^THIS WEEK/, at + 1);
  await type(page, '0');
  await endsOn(page, TODAY_END);
});

test('THIS WEEK for one kingdom: A KINGDOM picks it, the page matches Recap/json_kingdom, 7 returns to all of Amtgard, 0 steps back', async ({ page, request }) => {
  const recap = await (await request.get('./index.php?Route=Recap/json_kingdom/17')).json();
  await toToday(page);
  await type(page, '3');
  const s0 = await settled(page, /^THIS WEEK/);
  expect(s0.join('\n')).toMatch(/\n8\. A KINGDOM\n/);
  expect(s0.join('\n')).not.toContain('7. ALL OF AMTGARD');
  await type(page, '8');
  await settled(page, /^WHICH KINGDOM/);
  await widthOk(page);
  await type(page, '0');
  await settled(page, /^THIS WEEK/);
  await type(page, '8');
  await settled(page, /^WHICH KINGDOM/);
  await pickRow(page, /^(\d)\. KINGDOM OF CRYSTAL GROVES/);
  const s = await settled(page, /^THIS WEEK/);
  expect(s.join('\n')).toContain('KINGDOM: CRYSTAL GROVES');
  expect(s.join('\n')).toMatch(/7\. ALL OF AMTGARD\n8\. A KINGDOM/);
  const pages = await allPages(page, /^THIS WEEK/);
  const all = pages.flat().join('\n');
  // an empty section is left out, as on the Recap page
  if (recap.NewPlayers.Players.length) expect(all).toMatch(/NEW PLAYERS \((\d+|FIRST \d+ OF \d+)\)/);
  else expect(all).not.toContain('NEW PLAYERS');
  const mine = numbered(pages, 6).map(words);
  const exp = [...recap.TopEvents, ...recap.TopParks, ...recap.Knightings, ...recap.Masterhoods, ...recap.Paragons].length + Math.min(30, recap.NewPlayers.Players.length);
  expect(mine).toHaveLength(exp);
  recap.NewPlayers.Players.slice(0, 30).forEach((p: any) => expect(mine.some((r) => r.startsWith(words(p.Persona).slice(0, 14))), p.Persona).toBe(true));
  await shot(page, 'today-week-kingdom');
  await type(page, '7');
  const back = await settled(page, /^THIS WEEK/);
  expect(back.join('\n')).toContain('ALL OF AMTGARD');
  await type(page, '0');
  await endsOn(page, TODAY_END);
});

test('THIS WEEK with no recap computed or nothing to report says so; a failed read gives ?I/O ERROR and the board still works', async ({ page }) => {
  await page.route(/Route=Recap\/json$/, (r) => r.fulfill({ contentType: 'application/json', body: '{"WeekStart":"2026-10-26","KingdomId":null,"Status":"not_computed"}' }));
  await toToday(page);
  await type(page, '3');
  await settled(page, /^THIS WEEK/);
  expect((await log(page).innerText()) || '').toMatch(/NO RECAP HAS BEEN COMPUTED YET\.\s+\n?ALL OF AMTGARD|NO RECAP HAS BEEN COMPUTED YET\./);
  expect((await log(page).innerText()) || '').not.toContain('WEEK OF');
  await type(page, '0');
  await endsOn(page, TODAY_END);
  await page.unroute(/Route=Recap\/json$/);
  await page.goto('./index.php?Route=Search');   // a read that worked is kept for the mount: start a fresh one
  await page.route(/Route=Recap\/json$/, (r) => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ WeekStart: '2026-10-19', WeekEnd: '2026-10-25', Knightings: [], Masterhoods: [], Paragons: [], TopEvents: [], TopParks: [], NewPlayers: { Count: 0, Players: [] }, ReturningPlayers: [] }) }));
  await toToday(page);
  await type(page, '3');
  await settled(page, /^THIS WEEK/);
  expect((await log(page).innerText()) || '').toMatch(/NOTHING TO REPORT THIS WEEK\.\s+WEEK OF 10\/19\/26 TO 10\/25\/26/);
  await type(page, '0');
  await endsOn(page, TODAY_END);
  await page.unroute(/Route=Recap\/json$/);
  await page.goto('./index.php?Route=Search');
  await page.route(/Route=Recap\/json$/, (r) => r.abort());
  await toToday(page);
  await type(page, '3');
  await endsOn(page, /READING DISK\.\.\.\s+\?I\/O ERROR\s+(?:DISK READ FAILED|THE ORK IS SLOW TODAY)\. TRY AGAIN\.\s*$/);
  await type(page, '1');
  await settled(page, /^KINGDOM STANDINGS/);
});

// ---- ON THE FIELD NOW ----

async function liveMocks(page: Page, eid: number, did: number) {
  const now = Date.now();
  const iso = (ms: number) => new Date(now - ms).toISOString().replace(/\.\d+Z$/, 'Z');
  const stats = {
    status: 0, now: '2026-11-01 22:41:09', active_3h: 17,
    parks: {
      1049: { name: 'Test Park Alpha', kingdom: 'The Kingdom of Crystal Groves', city: 'Austin', province: 'TX', title: 'Barony', tier: 3, lat: 1, lng: 2, day: 20, h3: 9, m30: 2 },
      68: { name: 'Pegasus Valley', kingdom: 'The Kingdom of Dragonspine', city: null, province: null, title: 'Duchy', tier: 4, lat: 1, lng: 2, day: 8, h3: 5, m30: 0 },
      70: { name: 'Quiet Park', kingdom: 'x', day: 3, h3: 0, m30: 0 },
      71: { name: 'Zed Park Also Five', kingdom: 'x', day: 6, h3: 5, m30: 1 },
    },
    events: { [eid]: { name: 'Mock Gathering', kingdom: 'The Kingdom of Crystal Groves', lat: 0, lng: 0, coord_source: 'none', day: 4, h3: 3, m30: 1, calendar_detail_id: did } },
  };
  const recent = {
    status: 0, now: '2026-11-01 22:41:09',
    signins: [
      [iso(2 * 60000 + 5000), 1049, 0, 0, 1, 0],
      [iso(5 * 60000 + 5000), 0, eid, did, 0, 1],
      [iso(3 * 3600000 + 60000), 68, 0, 0, 0, 0],
      [iso(4 * 3600000), 999999, 0, 0, 0, 0],
    ],
    parks: { 1049: { name: 'Test Park Alpha', title: 'Barony' } },
    events: { [eid]: { name: 'Mock Gathering' } },
  };
  await page.route(/Route=Live\/stats/, (r) => r.fulfill({ contentType: 'application/json', body: JSON.stringify(stats) }));
  await page.route(/Route=Live\/recent/, (r) => r.fulfill({ contentType: 'application/json', body: JSON.stringify(recent) }));
}
async function aRealEvent(request: any): Promise<{ eid: number; did: number }> {
  for (const [y, m] of [[2026, 11], [2026, 12], [2027, 1]]) {
    const lo = y + '-' + String(m).padStart(2, '0') + '-01', hi = (m === 12 ? y + 1 : y) + '-' + String(m === 12 ? 1 : m + 1).padStart(2, '0') + '-01';
    const j = await (await request.get('./index.php?Route=KingdomAjax/calendar/17&start=' + lo + '&end=' + hi)).json();
    const e = (j.events || []).find((x: any) => x.extendedProps && x.extendedProps.detailId && /event/.test(x.type));
    if (e) return { eid: e.extendedProps.eventId, did: e.extendedProps.detailId };
  }
  throw new Error('no event on the KCG calendar');
}

test('ON THE FIELD NOW (mocked rows from the Live class shapes): busiest first, events marked, quiet parks left out, the right column and count note', async ({ page, request }) => {
  const ev = await aRealEvent(request);
  await liveMocks(page, ev.eid, ev.did);
  await toToday(page);
  await type(page, '4');
  const s = await settled(page, /^ON THE FIELD NOW/);
  await widthOk(page);
  expect(s[0]).toMatch(/^ON THE FIELD NOW +PAGE 1 OF 1$/);
  expect(s.filter((l) => /^[1-7]\. /.test(l))).toEqual([
    '1. ' + row36('TEST PARK ALPHA', '9'),
    '2. ' + row36('PEGASUS VALLEY', '5'),
    '3. ' + row36('ZED PARK ALSO FIVE', '5'),
    '4. ' + row36('MOCK GATHERING (EVENT)', '3'),
  ]);
  expect(s.join('\n')).toContain('RIGHT: SIGN-INS IN THE PAST 3 HOURS');
  expect(s.join('\n')).toContain('ALL SIGN-INS PAST 3 HOURS: 17');
  expect(s.join('\n')).not.toContain('QUIET PARK');
  expect(s.join('\n')).toMatch(/8\. LATEST SIGN-INS\n0\. BACK$/);
  await shot(page, 'today-live');
  // an event row opens the event, a park row opens the park; 0 returns to the same list
  await type(page, '4');
  await expect(log(page)).toHaveText(/0\. ON THE FIELD NOW\s*$/, { timeout: 20000, useInnerText: true });
  expect((await log(page).innerText()) || '').toMatch(/DESCRIPTION\n2\. RSVP COUNTS/);
  await type(page, '0');
  await settled(page, /^ON THE FIELD NOW/);
  await type(page, '1');
  await expect(log(page)).toHaveText(/0\. ON THE FIELD NOW\s*$/, { timeout: 20000, useInnerText: true });
  expect((await log(page).innerText()) || '').toContain('1. MEETING DAYS');
  await type(page, '0');
  await settled(page, /^ON THE FIELD NOW/);
  // the ticker
  await type(page, '8');
  const r = await settled(page, /^LATEST SIGN-INS/);
  await widthOk(page);
  const rr = r.filter((l) => /^[1-8]\. /.test(l));
  expect(rr).toHaveLength(3);
  expect(rr[0]).toMatch(/^1\. TEST PARK ALPHA +[0-9]+M AGO NEW$/);
  expect(rr[1]).toMatch(/^2\. MOCK GATHERING +[0-9]+M AGO$/);
  expect(rr[2]).toMatch(/^3\. PEGASUS VALLEY +[0-9]+H AGO$/);   // named from the stats map; the unnamed park is skipped
  expect(r.join('\n')).toContain('NEW = THEIR FIRST SIGN-IN EVER');
  await shot(page, 'today-ticker');
  await type(page, '2');
  await expect(log(page)).toHaveText(/0\. LATEST SIGN-INS\s*$/, { timeout: 20000, useInnerText: true });
  await type(page, '0');
  await settled(page, /^LATEST SIGN-INS/);
  await type(page, '0');
  await settled(page, /^ON THE FIELD NOW/);
  await type(page, '0');
  await endsOn(page, TODAY_END);
});

test('ON THE FIELD NOW with the real feed: the rows are the real answer\'s parks and events with sign-ins in 3 hours (empty here says so); the empty-list shape is handled', async ({ page, request }) => {
  const real = await (await request.get('./index.php?Route=Live/stats')).json();
  const entries = (o: any) => (o && typeof o === 'object' ? Object.values(o) : []) as any[];
  const wantRows = [...entries(real.parks), ...entries(real.events)].filter((x) => x.h3 > 0).length;
  await toToday(page);
  await type(page, '4');
  const s = await settled(page, /^ON THE FIELD NOW/);
  await widthOk(page);
  const n = s.filter((l) => /^[1-7]\. /.test(l)).length;
  if (wantRows === 0) {
    expect(s.join('\n')).toMatch(/NOBODY HAS SIGNED IN DURING THE PAST 3\s+HOURS\./);
    expect(n).toBe(0);
  } else {
    expect(n).toBeGreaterThan(0);
  }
  // PHP sends [] for no parks or events, and an object otherwise; both are read, and a missing answer is an error
  await type(page, '0');
  await endsOn(page, TODAY_END);
  await page.route(/Route=Live\/stats/, (r) => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ status: 0, now: 'x', active_3h: 0, parks: [], events: [] }) }));
  await page.route(/Route=Live\/recent/, (r) => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ status: 0, now: 'x', signins: [], parks: [], events: [] }) }));
  await type(page, '4');
  await settled(page, /^ON THE FIELD NOW/);
  expect((await log(page).innerText()) || '').toMatch(/NOBODY HAS SIGNED IN DURING THE PAST 3\s+HOURS\.[\s\S]*ALL SIGN-INS PAST 3 HOURS: 0[\s\S]*8\. LATEST SIGN-INS/);
  await type(page, '8');
  await settled(page, /^LATEST SIGN-INS/);
  expect((await log(page).innerText()) || '').toMatch(/NO SIGN-INS IN THE LAST 24 HOURS\.\s+0\. BACK\s*$/);
  await type(page, '0');
  await settled(page, /^ON THE FIELD NOW/);
  await type(page, '0');
  await endsOn(page, TODAY_END);
  // the stats answer failing is an I/O error; the ticker failing alone leaves the list and no key 8
  await page.unroute(/Route=Live\/stats/);
  await page.route(/Route=Live\/stats/, (r) => r.abort());
  await type(page, '4');
  await endsOn(page, /READING DISK\.\.\.\s+\?I\/O ERROR\s+(?:DISK READ FAILED|THE ORK IS SLOW TODAY)\. TRY AGAIN\.\s*$/);
  await page.unroute(/Route=Live\/stats/);
  await page.route(/Route=Live\/stats/, (r) => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ status: 0, now: 'x', active_3h: 2, parks: { 68: { name: 'Pegasus Valley', h3: 2, day: 2, m30: 0 } }, events: [] }) }));
  await page.unroute(/Route=Live\/recent/);
  await page.route(/Route=Live\/recent/, (r) => r.abort());
  await type(page, '4');
  const t = await settled(page, /^ON THE FIELD NOW/);
  expect(t.join('\n')).toMatch(/1\. PEGASUS VALLEY +2\n/);
  expect(t.join('\n')).not.toContain('LATEST SIGN-INS');
  await type(page, '8');
  await expect(log(page)).toContainText('?ILLEGAL QUANTITY ERROR');
});

test('an event row on the field with no calendar detail says ?FILE NOT FOUND and keeps the prompt', async ({ page }) => {
  await page.route(/Route=Live\/stats/, (r) => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ status: 0, now: 'x', active_3h: 1, parks: [], events: { 5: { name: 'No Detail Fest', day: 1, h3: 1, m30: 0, calendar_detail_id: 0 } } }) }));
  await toToday(page);
  await type(page, '4');
  await settled(page, /^ON THE FIELD NOW/);
  await type(page, '1');
  await expect(log(page)).toContainText('?FILE NOT FOUND');
  await type(page, '0');
  await settled(page, /^ON THE FIELD NOW/);
});

// ---- WEATHER FOR PLAY ----

test('WEATHER FOR PLAY with the real feed: parks playing today and the flagged ones are the Weather page\'s figures; a row opens the park', async ({ page, request }) => {
  const day = serverDay();
  const wx = await (await request.get('./index.php?Route=Weather/day/' + day)).json();
  const flagged = wx.play.filter((p: any) => p.badges.length);
  await toToday(page);
  await type(page, '5');
  const first = await settled(page, /^WEATHER FOR PLAY/);
  await widthOk(page);
  const all = first.join('\n');
  expect(all).toContain('PARKS PLAYING TODAY: ' + wx.play.length);
  if (!flagged.length) {
    expect(words(all)).toContain('CONDITIONS LOOK FAVORABLE AT ALL ' + wx.play.length + ' PARKS');
    return;
  }
  expect(all).toContain('WITH A WARNING: ' + flagged.length);
  expect(all).toContain('RIGHT: WARNING, HIGH IN F');
  const pages = await allPages(page, /^WEATHER FOR PLAY/);
  const mine = numbered(pages, 6);
  expect(mine).toHaveLength(flagged.length);
  const sorted = flagged.slice().sort((a: any, b: any) => (b._score - a._score) || (words(a.park_name) < words(b.park_name) ? -1 : 1));
  sorted.forEach((p: any, i: number) => {
    expect(words(mine[i]).startsWith(words(p.park_name).slice(0, 12)), p.park_name).toBe(true);
    if (p.forecast && p.forecast.hi_f !== null) expect(mine[i]).toMatch(new RegExp(' ' + Math.round(p.forecast.hi_f) + 'F$'));
  });
  await shot(page, 'today-weather');
  await type(page, '1');
  await expect(log(page)).toHaveText(/0\. WEATHER\s*$/, { timeout: 20000, useInnerText: true });
  expect(words((await log(page).innerText()) || '')).toContain(words(sorted[0].park_name).slice(0, 12));
  await type(page, '0');
  await settled(page, /^WEATHER FOR PLAY/);
  await type(page, '0');
  await endsOn(page, TODAY_END);
});

test('WEATHER FOR PLAY row formats, order, no-forecast rows, the favorable and no-play states and a failed read', async ({ page }) => {
  const park = (id: number, name: string, score: number, badges: string[], hi: number | null) => ({
    park_id: id, park_name: name, kingdom_name: 'K', lat: 1, lng: 2, schedules: [], forecast: hi === null ? null : { hi_f: hi, lo_f: 40 }, forecast_status: hi === null ? 'dormant' : 'ok',
    badges: badges.map((b) => ({ icon: 'x', label: b, severity: 'warning' })), _score: score,
  });
  const mock = [park(1, 'Calm Park', 0, [], 70), park(2, 'Windy Peak', 60, ['Severe wind'], 61.5), park(3, 'Hot Springs Of Great Length Indeed', 90, ['Extreme heat', 'Very high UV', 'Thunderstorms'], 108), park(4, 'Cold Hollow', 60, ['Frostbite risk'], null), park(5, 'Odd Flag', 10, ['Meteor shower with a very long name'], 50)];
  await page.route(/WeatherService\/GetPlayForDate/, (r) => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ Result: mock, Status: true, Code: 0 }) }));
  await toToday(page);
  await type(page, '5');
  const s = await settled(page, /^WEATHER FOR PLAY/);
  await widthOk(page);
  expect(s.filter((l) => /^[1-6]\. /.test(l))).toEqual([
    '1. ' + row36('HOT SPRINGS OF GREAT LE', 'HEAT/UV 108F'),
    '2. ' + row36('COLD HOLLOW', 'FROST'),
    '3. ' + row36('WINDY PEAK', 'WIND 62F'),
    '4. ' + row36('ODD FLAG', 'METEOR S 50F'),
  ]);
  expect(s.join('\n')).toContain('PARKS PLAYING TODAY: 5');
  expect(s.join('\n')).toContain('WITH A WARNING: 4');
  expect(s.join('\n')).not.toContain('CALM PARK');
  await shot(page, 'today-weather-mock');
  await type(page, '0');
  await endsOn(page, TODAY_END);
  await page.goto('./index.php?Route=Search');   // a read that worked is kept for the mount: each state below is a fresh one
  await page.route(/WeatherService\/GetPlayForDate/, (r) => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ Result: [park(1, 'Calm Park', 0, [], 70), park(2, 'Fine Park', 0, [], 65)], Status: true, Code: 0 }) }));
  await toToday(page);
  await type(page, '5');
  await settled(page, /^WEATHER FOR PLAY/);
  expect((await log(page).innerText()) || '').toMatch(/CONDITIONS LOOK FAVORABLE AT ALL 2\s+PARKS PLAYING TODAY\. NO WARNINGS\.\s+PARKS PLAYING TODAY: 2\s+WITH A WARNING: 0\s+0\. BACK\s*$/);
  await type(page, '0');
  await endsOn(page, TODAY_END);
  await page.goto('./index.php?Route=Search');
  await page.route(/WeatherService\/GetPlayForDate/, (r) => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ Result: [], Status: true, Code: 0 }) }));
  await toToday(page);
  await type(page, '5');
  await settled(page, /^WEATHER FOR PLAY/);
  expect((await log(page).innerText()) || '').toMatch(/NO PARKS HAVE IN-PERSON PLAY TODAY\.\s+0\. BACK\s*$/);
  await type(page, '0');
  await endsOn(page, TODAY_END);
  await page.goto('./index.php?Route=Search');
  await page.route(/WeatherService\/GetPlayForDate/, (r) => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ Result: null, Status: false, Code: 2, ErrorMessage: 'SELECT secret' }) }));
  await toToday(page);
  await type(page, '5');
  await endsOn(page, /READING DISK\.\.\.\s+\?I\/O ERROR\s+(?:DISK READ FAILED|THE ORK IS SLOW TODAY)\. TRY AGAIN\.\s*$/);
  expect((await log(page).innerText()) || '').not.toContain('SECRET');
  await type(page, '2');   // the board's prompt is still there after the error
  await settled(page, /^TOP PARKS/);
});

test('every THE ORK TODAY read that fails gives ?I/O ERROR, the board still works and a retry succeeds', async ({ page }) => {
  const reads: Array<[string, RegExp]> = [['1', /Report\/GetActiveKingdomsSummary/], ['2', /Report\/GetTopParksByAttendance/], ['3', /Route=Recap\/json$/], ['4', /Route=Live\/stats/], ['5', /WeatherService\/GetPlayForDate/]];
  await toToday(page);
  for (const [key, re] of reads) {
    await page.route(re, (r) => r.abort());
    await type(page, key);
    await endsOn(page, /READING DISK\.\.\.\s+\?I\/O ERROR\s+(?:DISK READ FAILED|THE ORK IS SLOW TODAY)\. TRY AGAIN\.\s*$/);
    await page.unroute(re);
    await page.waitForTimeout(100);
    await type(page, key);
    await settled(page, /^(KINGDOM STANDINGS|TOP PARKS|THIS WEEK|ON THE FIELD NOW|WEATHER FOR PLAY)/);
    await type(page, '0');
    await endsOn(page, TODAY_END);
  }
});

test('a service refusal on the board is ?I/O ERROR and never shows the detail', async ({ page }) => {
  await page.route(/Report\/GetActiveKingdomsSummary/, (r) => r.fulfill({ contentType: 'application/json', body: '{"Status":{"Status":5,"Error":"x","Detail":"SELECT secret FROM t"}}' }));
  await toToday(page);
  await type(page, '1');
  await endsOn(page, /READING DISK\.\.\.\s+\?I\/O ERROR\s+(?:DISK READ FAILED|THE ORK IS SLOW TODAY)\. TRY AGAIN\.\s*$/);
  expect((await log(page).innerText()) || '').not.toContain('SECRET');
  await type(page, '2');
  await settled(page, /^TOP PARKS/);
});

// ---- kingdom KNIGHTS AND MASTERS ----

async function kamRowsOfPage(request: any, kid: number) {
  const html = await (await request.get('./index.php?Route=Reports/knights_and_masters&KingdomId=' + kid)).text();
  const body = html.slice(html.indexOf('<tbody'), html.indexOf('</tbody>'));
  return [...body.matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/g)].map((m) => {
    const cells = [...m[1].matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map((x) => x[1]);
    const at = cells.findIndex((c) => /Player\/profile\/\d+/.test(c));
    const txt = (c: string) => decode(c.replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim());
    return { persona: txt(cells[at]), award: txt(cells[at + 1]), date: txt(cells[at + 2]), park: at > 0 ? txt(cells[at - 1]) : '', id: +/Player\/profile\/(\d+)/.exec(cells[at])![1] };
  });
}
const KAM_URL = SVC + 'Report/PlayerAwards&request[KingdomId]=17&request[ParkId]=0&request[IncludeKnights]=1&request[IncludeMasters]=1';
const stripAward = (a: string) => words(a).replace(/^(KNIGHT OF THE|KNIGHT OF|PARAGON|MASTER) /, '');

test('KCG KNIGHTS AND MASTERS is the Reports tab list: every belt, grouped, with counts; a row shows award, date, park; the player opens; every 0 returns', async ({ page, request }) => {
  const web = await kamRowsOfPage(request, 17);
  expect(web.length).toBeGreaterThan(100);
  const svc = (await (await request.get(KAM_URL)).json()).Awards;
  const nK = svc.filter((a: any) => a.Peerage === 'Knight').length;
  await toKcgProfile(page);
  expect(await menuText(page)).toMatch(/1\. PARKS\s+2\. EVENTS\s+3\. PAST EVENTS\s+4\. COMPANIES AND HOUSEHOLDS\s+5\. KNIGHTS AND MASTERS\s+0\. KINGDOM LIST\s*$/);
  await type(page, '5');
  const pages = await allPages(page, /^KNIGHTS AND MASTERS/);
  await widthOk(page);
  const text = pages.flat().join('\n');
  expect(text).toContain('KNIGHTS (' + nK + ')');
  expect(text).toContain('MASTERS (' + (web.length - nK) + ')');
  const mine = numbered(pages).map(words);
  expect(mine).toHaveLength(web.length);
  // each belt on the page is a row here: same persona, same award (less the word the heading says)
  web.forEach((w) => {
    const pw = words(w.persona).slice(0, 10), aw = stripAward(w.award);
    expect(mine.some((k) => k.startsWith(pw) && k.endsWith(aw.slice(-8))), w.persona + ' / ' + w.award).toBe(true);
  });
  await shot(page, 'kam-list');
  // first row: the belt
  const firstRow = words(pages[0].find((l) => /^1\. /.test(l))!.replace(/^1\. /, ''));
  await type(page, '1');
  const d = await settled(page, /^(KNIGHT|MASTER) /);
  await widthOk(page);
  const dtext = d.join('\n');
  expect(dtext).toMatch(/GIVEN \d\d\/\d\d\/\d\d/);
  expect(dtext).toContain('1. PLAYER PROFILE');
  expect(dtext).toMatch(/0\. LIST$/);
  const dw = web.find((w) => firstRow.startsWith(words(w.persona).slice(0, 10)) && firstRow.endsWith(stripAward(w.award).slice(-8)))!;
  expect(dw).toBeTruthy();
  const [yy, mm, dd] = dw.date.split('-');
  expect(dtext).toContain('GIVEN ' + mm + '/' + dd + '/' + yy.slice(2));
  expect(words(dtext)).toContain(words(dw.park).slice(0, 20));
  expect(words(dtext)).toContain(words(dw.award).slice(0, 20));
  // private fields stay out: who entered the award is not on any screen
  const enteredBy = svc.find((a: any) => a.MundaneId === dw.id && a.AwardName === dw.award).EnteredBy;
  expect(enteredBy.length).toBeGreaterThan(3);
  expect(words((await log(page).innerText()) || '')).not.toContain(words(enteredBy));
  await shot(page, 'kam-detail');
  await type(page, '1');
  await expect(log(page)).toContainText('1. CLASSES', { timeout: 20000 });
  await expect(log(page)).toHaveText(/0\. BACK\s*$/, { useInnerText: true });
  await type(page, '0');
  await settled(page, /^(KNIGHT|MASTER) /);
  await type(page, '2');
  await expect(log(page)).toContainText('?ILLEGAL QUANTITY ERROR');
  await type(page, '0');
  await settled(page, /^KNIGHTS AND MASTERS/, 1);
  // page 3 and back: the same page returns
  await type(page, '9'); await settled(page, /^KNIGHTS AND MASTERS/, 2);
  await type(page, '9'); await settled(page, /^KNIGHTS AND MASTERS/, 3);
  await type(page, '2');
  await settled(page, /^(KNIGHT|MASTER) /);
  await type(page, '0');
  await settled(page, /^KNIGHTS AND MASTERS/, 3);
  await type(page, '0');
  await endsOn(page, PROFILE_END);
});

test('a kingdom with principalities puts KNIGHTS AND MASTERS at 6 and the profile still fits the page', async ({ page }) => {
  await toMenu(page);
  await type(page, '1');
  await pickRow(page, /^(\d)\. KINGDOM OF NORTHERN LIGHTS/);
  await endsOn(page, PROFILE_END);
  expect(await menuText(page)).toMatch(/1\. PARKS\s+2\. EVENTS\s+3\. PRINCIPALITIES\s+4\. PAST EVENTS\s+5\. COMPANIES AND HOUSEHOLDS\s+6\. KNIGHTS AND MASTERS\s+0\. KINGDOM LIST\s*$/);
  const rs = await rows(page);
  expect(rs[0]).toMatch(/KINGDOM OF NORTHERN/);    // nothing scrolled off the top
  expect(rs[22]).toMatch(/^0\. KINGDOM LIST/);    // 23 lines, the prompt on the 24th row
  expect(rs[23]).toMatch(/^SELECT: /);
  rs.forEach((r) => expect(r.length).toBeLessThanOrEqual(40));
  await shot(page, 'kam-profile-prinz');
  await type(page, '6');
  await settled(page, /^KNIGHTS AND MASTERS/);
  await type(page, '0');
  await endsOn(page, PROFILE_END);
  await type(page, '5');
  await settled(page, /^COMPANIES AND HOUSEHOLDS/);
});

test('KNIGHTS AND MASTERS: empty, only rows that are knights or masters with a player, and a failed read', async ({ page }) => {
  const re = /Report\/PlayerAwards/;
  await page.route(re, (r) => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ Status: { Status: 0 }, Awards: [] }) }));
  await toKcgProfile(page);
  await type(page, '5');
  await settled(page, /^KNIGHTS AND MASTERS/);
  expect((await log(page).innerText()) || '').toMatch(/NO KNIGHTS OR MASTERS ON RECORD\.\s+0\. BACK\s*$/);
  await type(page, '0');
  await endsOn(page, PROFILE_END);
  // a read that worked is kept for the mount, so each state below starts a fresh one
  await page.goto('./index.php?Route=Search');
  await page.unroute(re);
  const rowMock = (o: any) => Object.assign({ MundaneId: 5, Persona: 'Sir Test', Date: '2026-01-02', ParkId: 1049, ParkName: 'Some Park', AwardName: 'Knight of the Flame', Peerage: 'Knight', EnteredBy: 'Real Name', Suspended: 0 }, o);
  await page.route(re, (r) => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ Status: { Status: 0 }, Awards: [rowMock({}), rowMock({ MundaneId: 6, Persona: 'Ladder One', Peerage: '', AwardName: 'Order of the Rose' }), rowMock({ MundaneId: 0, Persona: 'Nobody' }), rowMock({ MundaneId: 7, Persona: 'Dame Sus', Peerage: 'Master', AwardName: 'Master Rose', Suspended: 1 })] }) }));
  await toKcgProfile(page);
  await type(page, '5');
  const s = await settled(page, /^KNIGHTS AND MASTERS/);
  expect(s.filter((l) => /^[1-8]\. /.test(l))).toEqual(['1. ' + row36('SIR TEST', 'FLAME'), '2. ' + row36('DAME SUS', 'ROSE')]);
  expect(s.join('\n')).toMatch(/KNIGHTS \(1\)\n1\. SIR TEST[^\n]*\nMASTERS \(1\)\n2\. DAME SUS/);
  expect((await log(page).innerText()) || '').not.toMatch(/SUSPEND/);   // the list page marks the row by style only; no text is added here
  await page.goto('./index.php?Route=Search');
  await page.unroute(re);
  await page.route(re, (r) => r.abort());
  await toKcgProfile(page);
  await type(page, '5');
  await endsOn(page, /READING DISK\.\.\.\s+\?I\/O ERROR\s+(?:DISK READ FAILED|THE ORK IS SLOW TODAY)\. TRY AGAIN\.\s*$/);
  await page.unroute(re);
  await type(page, '5');
  await settled(page, /^KNIGHTS AND MASTERS/);
});

// ---- player ABOUT, COMPANIES, MILESTONES ----

const PLAYER_END = /7\. MILESTONES\s+0\. (BACK|MAIN MENU)\s*$/;
function aboutOf(html: string, key: string): string {
  const m = new RegExp(key + ':\\s+("(?:[^"\\\\]|\\\\.)*")').exec(html);
  return m ? JSON.parse(m[1]) : '';
}
const mdWords = (s: string) => words(s.replace(/<[^>]*>/g, ' ').replace(/!\[[^\]]*\]\([^)]*\)/g, ' ').replace(/\[([^\]]*)\]\([^)]*\)/g, '$1').replace(/&amp;/g, '&').replace(/&#039;/g, "'").replace(/&quot;/g, '"'));

// The player screens are walked on a player picked at runtime (a KCG kingdom officer), with the parts of the record a
// test is about (suspension, About text, companies) set through a fixture on Player/GetPlayer: the real read is made
// and those fields are replaced. Which player is suspended or writes a long About changes with the data.
type Subject = { id: number; name: string };
async function kcgOfficers(request: APIRequestContext): Promise<Subject[]> {
  const r = await (await request.get(SVC + 'Kingdom/GetOfficers&request[KingdomId]=17')).json();
  return (r.Officers as any[]).filter((o) => +o.MundaneId > 0 && String(o.Persona || '').trim()).map((o) => ({ id: +o.MundaneId, name: String(o.Persona).trim() }));
}
async function aPlayer(request: APIRequestContext): Promise<Subject> {
  const all = await kcgOfficers(request);
  expect(all.length).toBeGreaterThan(0);
  return all[0];
}
async function patchPlayer(page: Page, id: number, patch: Record<string, unknown>) {
  await page.route(/call=Player\/GetPlayer&/, async (route) => {
    const res = await route.fetch();
    const j = await res.json();
    if (j && j.Player && +j.Player.MundaneId === id) Object.assign(j.Player, patch);
    await route.fulfill({ response: res, json: j });
  });
}

test('player menu: 5 ABOUT, 6 COMPANIES, 7 MILESTONES after TITLES; the profile fits the page and a suspended player gets no new suspension text', async ({ page, request }) => {
  const who = await aPlayer(request);
  // suspended: the service marks the record inactive; the terminal never reads Suspended
  await patchPlayer(page, who.id, { Suspended: 1, IsActive: 0, AboutPersona: 'A quiet *persona* of the [woods](http://x/y).', AboutStory: 'Once upon a time there was a story.' });
  await openPlayer(page, who.id, who.name);
  expect(await menuText(page)).toMatch(/1\. CLASSES\s+2\. AWARDS\s+3\. ATTENDANCE\s+4\. TITLES\s+5\. ABOUT\s+6\. COMPANIES\s+7\. MILESTONES\s+0\. BACK\s*$/);
  const rs = await rows(page);
  expect(words(rs[0])).toContain(words(who.name).slice(0, 20));
  expect(rs.join('\n')).toContain('INACTIVE');
  const z0 = rs.findIndex((r) => /^0\. /.test(r));
  expect(rs[z0 + 1]).toMatch(/^SELECT: /);
  await shot(page, 'player-menu');
  // the screens carry the record's words, and no suspension text of their own
  await type(page, '5');
  const a = await settled(page, /^ABOUT/);
  await widthOk(page);
  const shown = words(bodyOf(a).join(' '));
  expect(shown).toContain('A QUIET PERSONA OF THE WOODS');
  expect(shown).toContain('ONCE UPON A TIME THERE WAS A STORY');
  expect(shown).toContain(words('ABOUT ' + who.name));
  expect(shown).toContain(words(who.name + "'s story"));
  await shot(page, 'player-about');
  await type(page, '0');
  await expect(log(page)).toHaveText(PLAYER_END, { useInnerText: true });
  await type(page, '6');
  await settled(page, /^COMPANIES/);
  await type(page, '7');
  await expect(log(page)).toContainText('?ILLEGAL QUANTITY ERROR');
  await type(page, '0');
  await expect(log(page)).toHaveText(PLAYER_END, { useInnerText: true });
  await type(page, '7');
  await settled(page, /^MILESTONES/);
  await type(page, '0');
  await expect(log(page)).toHaveText(PLAYER_END, { useInnerText: true });
  await type(page, '8');
  await expect(log(page)).toContainText('?ILLEGAL QUANTITY ERROR');
  const t = (await log(page).innerText()) || '';
  expect(t).not.toMatch(/SUSPEND|RESTRICT/);
});

test('ABOUT on a long text: wrapped, paged, the record\'s words, 0 returns; a player with no About text says so', async ({ page, request }) => {
  const who = await aPlayer(request);
  const para = 'The quick brown fox of the northern woods jumps over the lazy dog by the river bend at dawn. ';
  const long = Array.from({ length: 12 }, (_, i) => 'Part ' + (i + 1) + '. ' + para.repeat(4)).join('\r\n\r\n');
  const aw = words(long);
  expect(aw.length).toBeGreaterThan(3000);
  await patchPlayer(page, who.id, { AboutPersona: long, AboutStory: '' });
  await openPlayer(page, who.id, who.name);
  await type(page, '5');
  const first = await settled(page, /^ABOUT/);
  const pages = +/PAGE 1 OF (\d+)/.exec(first[0])![1];
  expect(pages).toBeGreaterThan(5);
  let body = bodyOf(first);
  for (let n = 2; n <= pages; n++) { await type(page, '9'); const s = await settled(page, /^ABOUT/, n); body = body.concat(bodyOf(s)); }
  await widthOk(page);
  const shown = words(body.join(' ')).replace(new RegExp('^' + words('ABOUT ' + who.name) + ' '), '');
  // the 120-line cap cuts the longest texts with "..."; what is shown is the start of the text
  expect(shown.slice(0, 300)).toBe(aw.slice(0, 300));
  await shot(page, 'player-about-long');
  await type(page, '9');
  await settled(page, /^ABOUT/, 1);
  await type(page, '0');
  await expect(log(page)).toHaveText(PLAYER_END, { useInnerText: true });
  // a player with nothing
  await page.unrouteAll();
  await page.goto('./index.php?Route=Search');
  await patchPlayer(page, who.id, { AboutPersona: '', AboutStory: '' });
  await openPlayer(page, who.id, who.name);
  await type(page, '5');
  await settled(page, /^ABOUT/);
  expect((await log(page).innerText()) || '').toMatch(/NO ABOUT TEXT ON FILE\.\s+0\. BACK\s*$/);
});

test('ABOUT strips anything but words: tags, scripts, images, links and Markdown marks never reach the page', async ({ page }) => {
  await page.locator('#orkple-floppy').click();
  await page.waitForFunction(() => !!(window as any).OrkpleTerminal);
  const lines = await page.evaluate(() => (window as any).OrkpleTerminal.lib.aboutLines({
    persona: 'Tester', aboutStory: '',
    aboutPersona: 'Hello <b>world</b> & friends.<script>alert(1)</script><img src=x onerror=alert(2)>\n\n**Bold** [a link](http://example.com/a/very/long/path/that/goes/on/and/on?q=1) ![pic](http://x/y.png)\n<style>body{display:none}</style>- item one\n# Heading\nCafé ☃ snowman',
  }));
  const text = lines.join('\n');
  expect(text).not.toMatch(/[<>\[\]*]/);
  expect(text).not.toMatch(/ALERT|SCRIPT|DISPLAY|ONERROR|HTTP|PNG/);
  expect(text).toContain('HELLO WORLD & FRIENDS.');
  expect(text).toContain('BOLD A LINK');
  expect(text).toContain('CAFE ? SNOWMAN');
  lines.forEach((l: string) => expect(l.length).toBeLessThanOrEqual(39));
});

test('COMPANIES is the record\'s Companies list: names, the unit opens, 0 returns to the list; a player in none says so', async ({ page, request }) => {
  const who = await aPlayer(request);
  // real KCG companies, so a pick opens a real unit profile (Player/GetPlayer's Company list carries companies only)
  const sum = (await (await request.get(SVC + 'Report/UnitSummary&request[KingdomId]=17&request[IncludeCompanies]=1&request[IncludeHouseHolds]=1&request[TopBySize]=1&request[Limit]=100')).json()).Units as any[];
  const companies = sum.filter((u) => u.Type === 'Company').slice(0, 10).map((u) => ({ UnitId: +u.UnitId, Name: String(u.Name), Type: 'Company' }));
  expect(companies.length).toBe(10);
  await patchPlayer(page, who.id, { Company: companies });
  await openPlayer(page, who.id, who.name);
  await type(page, '6');
  const pages = await allPages(page, /^COMPANIES/);
  await widthOk(page);
  const keys = numbered(pages).map((l) => words(l));
  expect(keys).toHaveLength(companies.length);
  companies.forEach((c) => expect(keys.some((k) => k.startsWith(words(c.Name).slice(0, 20)) && k.endsWith(' CO')), c.Name).toBe(true));
  await shot(page, 'player-companies');
  await type(page, '9');
  await settled(page, /^COMPANIES/, 2);
  await type(page, '1');
  await endsOn(page, /2\. HISTORY\s+0\. COMPANIES\s*$/);
  await type(page, '0');
  await settled(page, /^COMPANIES/, 2);
  await type(page, '0');
  await expect(log(page)).toHaveText(PLAYER_END, { useInnerText: true });
  // a player who is in none
  await page.unrouteAll();
  await page.goto('./index.php?Route=Search');
  await patchPlayer(page, who.id, { Company: [] });
  await openPlayer(page, who.id, who.name);
  await type(page, '6');
  await settled(page, /^COMPANIES/);
  expect((await log(page).innerText()) || '').toMatch(/NOT IN ANY COMPANY\.\s+0\. BACK\s*$/);
});

test('MILESTONES shows what the profile\'s timeline shows; 0 returns', async ({ page, request }) => {
  // parity on a player picked at runtime: the first KCG officer whose public timeline has more than five entries
  let who: Subject | null = null;
  let tl: Array<{ date: string; desc: string }> = [];
  for (const o of await kcgOfficers(request)) {
    const html = await (await request.get('./index.php?Route=Player/profile/' + o.id)).text();
    tl = [...html.matchAll(/pn-tl-date">([A-Z][a-z]{2} \d{1,2}, \d{4})<\/div>[\s\S]*?pn-tl-desc">([^<]*)</g)].map((m) => ({ date: new Date(m[1] + ' UTC').toISOString().slice(0, 10), desc: words(decode(m[2])) }));
    if (tl.length > 5) { who = o; break; }
  }
  test.skip(!who, 'no KCG officer with a public timeline of more than five entries');
  await openPlayer(page, who!.id, who!.name);
  await type(page, '7');
  const pages = await allPages(page, /^MILESTONES/);
  await widthOk(page);
  // every shown milestone is on the page's timeline, with the same date
  const entries = pages.flat().filter((l) => /^\d\d\/\d\d\/\d\d /.test(l));
  expect(entries.length).toBeGreaterThan(5);
  entries.forEach((e) => {
    const [mm, dd, yy] = e.slice(0, 8).split('/');
    const d = '20' + yy + '-' + mm + '-' + dd;
    expect(tl.some((t) => t.date === d && t.desc.startsWith(words(e.slice(9)).slice(0, 14))), e).toBe(true);
  });
  await shot(page, 'player-milestones');
  await type(page, '0');
  await expect(log(page)).toHaveText(PLAYER_END, { useInnerText: true });
});

test('MILESTONES honours the player\'s own switches (hidden types stay out, newest first when asked) and an empty list says so', async ({ page, request }) => {
  const read = async (id: number) => {
    const player = await (await request.get(SVC + 'Player/GetPlayer&request[MundaneId]=' + id)).json();
    const all = await (await request.get(SVC + 'Player/GetPlayerMilestones&request[MundaneId]=' + id)).json();
    return { pc: JSON.parse(player.Player.MilestoneConfig), all: all.Detail as any[] };
  };
  // Bearinger Moonshadow hides officer and custom milestones, and has officer ones
  const a = await read(143361);
  const hidden = new Set(Object.keys(a.pc).filter((k) => a.pc[k] === 0 || a.pc[k] === false));
  expect(a.all.some((m) => hidden.has(m.type))).toBe(true);
  const wantN = a.all.filter((m) => !hidden.has(m.type)).length;
  await openPlayer(page, 143361, 'Bearinger Moonshadow');
  await type(page, '7');
  const pages = await allPages(page, /^MILESTONES/);
  const entries = pages.flat().filter((l) => /^\d\d\/\d\d\/\d\d /.test(l));
  expect(entries.length).toBe(wantN);
  a.all.filter((m) => hidden.has(m.type)).forEach((m) => {
    expect(entries.some((e) => words(e).includes(words(m.description).slice(0, 25))), m.description).toBe(false);
  });
  expect(entries.some((e) => /EARNED THE TITLE/.test(e))).toBe(true);
  // Fly Nycto asks for newest first
  const b = await read(91319);
  expect(b.pc.newest_first).toBe(1);
  await page.goto('./index.php?Route=Search');
  await openPlayer(page, 91319, 'Fly Nycto');
  await type(page, '7');
  const bp = await allPages(page, /^MILESTONES/);
  const dates = bp.flat().filter((l) => /^\d\d\/\d\d\/\d\d /.test(l)).map((l) => '20' + l.slice(6, 8) + l.slice(0, 2) + l.slice(3, 5));
  expect(dates.length).toBeGreaterThan(5);
  expect(dates).toEqual(dates.slice().sort().reverse());
  // nothing to show
  await page.goto('./index.php?Route=Search');
  await page.route(/Player\/GetPlayerMilestones/, (r) => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ Status: 0, Error: 'Success', Detail: [] }) }));
  const who = await aPlayer(request);
  await openPlayer(page, who.id, who.name);
  await type(page, '7');
  await settled(page, /^MILESTONES/);
  expect((await log(page).innerText()) || '').toMatch(/NO MILESTONES TO SHOW\.\s+0\. BACK\s*$/);
});

test('a milestone read that fails gives ?I/O ERROR and the profile keeps working', async ({ page, request }) => {
  const who = await aPlayer(request);
  await openPlayer(page, who.id, who.name);
  const re = /Player\/GetPlayerMilestones/;
  await page.route(re, (r) => r.abort());
  await type(page, '7');
  await endsOn(page, /READING DISK\.\.\.\s+\?I\/O ERROR\s+(?:DISK READ FAILED|THE ORK IS SLOW TODAY)\. TRY AGAIN\.\s*$/);
  await page.unroute(re);
  await type(page, '7');
  await settled(page, /^MILESTONES/);
  await type(page, '0');
  await expect(log(page)).toHaveText(PLAYER_END, { useInnerText: true });
});

test('milestones in the library: the server applies the player\'s switches (ApplyVisibility); the terminal only drops rows without a date or words, in the order given', async ({ page }) => {
  await page.locator('#orkple-floppy').click();
  await page.waitForFunction(() => !!(window as any).OrkpleTerminal);
  const r = await page.evaluate(() => {
    const L = (window as any).OrkpleTerminal.lib;
    const list = [
      { type: 'officer', date: '2024-01-01', description: 'Officer' },
      { type: 'first_signin', date: '2020-01-01', description: 'First' },
      { type: 'knight', date: '2021-01-01', description: 'Knighted' },
      { type: 'custom', date: '2022-01-01', description: 'My thing' },
      { type: 'master', date: '0000-00-00', description: 'No date' },
      { type: 'title', date: '2023-01-01', description: '' },
    ];
    return {
      rows: L.milestoneRows(list).map((m: any) => m.type),
      text: L.milestoneText({ date: '2021-01-01', description: 'a '.repeat(120) }).split('\n').length,
    };
  });
  expect(r.rows).toEqual(['officer', 'first_signin', 'knight', 'custom']);
  expect(r.text).toBe(3);
});

// ---- width and height on long names ----

test('long persona names: the player profile and the new player screens fit 39 columns and 24 rows', async ({ page }) => {
  for (const [id, name] of [[59733, 'Radiant Yamato Saphiran'], [25834, 'The Shadow of a Moonbeam'], [54806, 'Vladimir']] as Array<[number, string]>) {
    await page.goto('./index.php?Route=Search');
    await openPlayer(page, id, name);
    await widthOk(page);
    const rs = await rows(page);
    rs.forEach((r) => expect(r.length).toBeLessThanOrEqual(40));
    const z = rs.findIndex((r) => /^0\. /.test(r));
    expect(rs[z + 1]).toMatch(/^SELECT: /);
    expect(rs[0].trim()).not.toBe('');   // the name is still the first row
    for (const k of ['5', '6', '7']) {
      await type(page, k);
      await settled(page);
      await widthOk(page);
      await type(page, '0');
      await expect(log(page)).toHaveText(PLAYER_END, { useInnerText: true });
    }
    if (id === 59733) await shot(page, 'player-long-name');
  }
});

test('the player menu fits 23 lines for the worst name, pronunciation, pronouns and home, own profile included', async ({ page }) => {
  await page.locator('#orkple-floppy').click();
  await page.waitForFunction(() => !!(window as any).OrkpleTerminal);
  const r = await page.evaluate(() => {
    const L = (window as any).OrkpleTerminal.lib;
    const long = 'Wwwwwwwwwwwwwwwwwww '.repeat(8);
    const p = { name: long, pronunciation: long, pronouns: long, where: long, status: 'ACTIVE', since: '01/01/20', last: '01/01/26', dues: 'PAID', reeve: 'NO', corpora: 'NO' };
    return { own: L.playerLines(p, 'BACK', true), other: L.playerLines(p, 'BACK', false), plain: L.playerLines({ name: 'Bob', status: 'ACTIVE', since: 'a', last: 'b', dues: 'c', reeve: 'd', corpora: 'e' }, 'BACK', true) };
  });
  for (const k of ['own', 'other', 'plain']) {
    expect(r[k].length, k).toBeLessThanOrEqual(23);
    r[k].forEach((l: string) => expect(l.length).toBeLessThanOrEqual(39));
  }
  expect(r.own.join('\n')).toMatch(/4\. TITLES\n5\. MY AMTGARD\n6\. ABOUT\n7\. COMPANIES\n8\. MILESTONES\n0\. BACK$/);
  expect(r.other.join('\n')).toMatch(/4\. TITLES\n5\. ABOUT\n6\. COMPANIES\n7\. MILESTONES\n0\. BACK$/);
  expect(r.plain.join('\n')).toMatch(/\n\n1\. CLASSES/);
});

test('long kingdom, park and persona names on the new rows are cut to the 36 columns a row has', async ({ page }) => {
  await page.locator('#orkple-floppy').click();
  await page.waitForFunction(() => !!(window as any).OrkpleTerminal);
  const r = await page.evaluate(() => {
    const L = (window as any).OrkpleTerminal.lib, long = 'Wwwwwwwwwwwwwwwwwww '.repeat(6);
    const week = L.weekRows({ TopEvents: [{ EventName: long, EventId: 1, EventCalendarDetailId: 2, Attendance: 12345 }], TopParks: [{ ParkId: 1, ParkName: long, Attendance: 9 }], Knightings: [{ MundaneId: 1, Persona: long, AwardName: 'Knight of the ' + long }], Masterhoods: [], Paragons: [], NewPlayers: { Count: 1, Players: [{ MundaneId: 2, Persona: long, ParkName: long }] } });
    return [
      ...week.rows.map((x: any) => x.text),
      L.standingText({ name: long, parks: 100, weekly: 1234.5 }), L.topParkText({ name: long, avg: 123.4 }, 25), L.liveText({ name: long, kind: 'event', n: 1000 }),
      L.recentText({ name: long, t: Date.now() - 3600000 * 30, first: true }, Date.now()), L.wxText({ name: long, badges: ['HEAT', 'UV'], hi: 108 }), L.kamText({ persona: long, award: 'Knight of the ' + long }),
    ];
  });
  expect(r.length).toBe(10);
  r.forEach((t: string) => expect(t.length, t).toBeLessThanOrEqual(36));
});

// ---- the allow-list covers the new reads ----

test('the new reads are on the allow-list and an unlisted one still throws', async ({ page }) => {
  await page.locator('#orkple-floppy').click();
  await page.waitForFunction(() => !!(window as any).OrkpleTerminal);
  const allowed: string[] = await page.evaluate(() => (window as any).OrkpleTerminal.lib.allowed.slice());
  for (const n of ['Report/GetActiveKingdomsSummary', 'Report/GetTopParksByAttendance', 'Recap/json', 'Recap/json_kingdom', 'Live/stats', 'Live/recent', 'WeatherService/GetPlayForDate', 'EraPhoenice/today', 'Report/PlayerAwards', 'Player/GetPlayerMilestones']) expect(allowed).toContain(n);
  for (const n of ['WeatherService/GetDailySummary', 'Kingdom/GetAwardList', 'Event/GetRsvpList', 'Player/GetNotes', 'Report/PlayerAwardRecommendations']) expect(allowed).not.toContain(n);
  const how = await page.evaluate(async () => {
    const t = (window as any).OrkpleTerminal.mount({ canvas: document.createElement('canvas'), input: document.createElement('input'), live: document.createElement('div'), service: '/orkservice/', ui: '/orkui/index.php?Route=', onExit() {}, reducedMotion: true, user: 0 });
    let out = 'no throw';
    try { await t.probe('/orkui/index.php?Route=Live/secret'); } catch (e: any) { out = /allow-list/.test(String(e.message)) ? 'allow-list throw' : 'other'; }
    t.destroy();
    (window as any).OrkpleViolations = [];
    return out;
  });
  expect(how).toBe('allow-list throw');
  calls.length = 0;
  uiCalls.length = 0;
});
