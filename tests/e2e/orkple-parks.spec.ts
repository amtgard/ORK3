/**
 * ORKPLE II parks (Phases 0 and 1 of docs/superpowers/plans/2026-11-01-orkple-read-surfaces.md).
 * Walks every park screen on KCG (kingdom 17) and park 1049 through the #orkple [role=log] text, with the
 * allow-list in strict mode, then checks the figures against the real Park page as an anonymous visitor.
 */
import { test, expect, Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

const PARK = 1049;

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

test.beforeEach(async ({ baseURL, page }, testInfo) => {
  if (!baseURL || !(await appReachable(baseURL))) {
    testInfo.skip(true, 'ORK3 app not reachable — start docker compose php8 stack');
  }
  calls.length = 0;
  // Strict mode: an endpoint off the allow-list throws, so the screen fails (and window.OrkpleViolations records it).
  await page.addInitScript(() => { (window as any).OrkpleStrict = true; });
  page.on('request', (r) => {
    const m = /Json\/index\.php\?call=([^&]+)/.exec(r.url());
    if (m) calls.push(decodeURIComponent(m[1]));
  });
  // ORKPLE is only live in its event window (or with the preview cookie); the floppy is not on the page otherwise.
  await page.context().addCookies([{ name: 'orkple_preview', value: '1', url: new URL('/', baseURL).href }]);
  await page.goto('./index.php?Route=Search');
});

test.afterEach(async ({ page }) => {
  const allowed: string[] = await page.evaluate(() => ((window as any).OrkpleTerminal ? (window as any).OrkpleTerminal.lib.allowed : null)) || [];
  const violations = await page.evaluate(() => (window as any).OrkpleViolations || []);
  expect(violations).toEqual([]);
  // The terminal is the only thing on the Search page calling the JSON service in these tests.
  const outside = calls.filter((c) => !allowed.includes(c));
  expect(outside).toEqual([]);
});

async function type(page: Page, text: string) {
  await page.keyboard.type(text);
  await page.keyboard.press('Enter');
}

// The terminal script loads when the floppy is first clicked.
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

// The lines of the screen on top: from the last "PAGE n OF m" title line, or the whole log when there is none.
async function lastScreen(page: Page): Promise<string[]> {
  const lines = (((await log(page).innerText()) || '').split('\n'));
  let idx = -1;
  lines.forEach((l, i) => { if (/PAGE \d+ OF \d+$/.test(l)) idx = i; });
  return idx < 0 ? lines : lines.slice(idx);
}

// Done printing when the top screen ends on its 0. line.
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

// Every printed line fits 39 columns (a 40th would auto-wrap and eat a row).
async function widthOk(page: Page) {
  const lines = ((await log(page).innerText()) || '').split('\n');
  for (const l of lines) expect(l.length, `"${l}"`).toBeLessThanOrEqual(39);
}

// Pick the numbered row whose text matches, flipping pages with 9 until it turns up.
async function pickRow(page: Page, re: RegExp, first = 1): Promise<void> {
  for (let n = first; n < first + 30; n++) {
    const s = await settled(page, undefined, n);
    const hit = s.map((l) => re.exec(l)).find(Boolean) as RegExpExecArray | undefined;
    if (hit) { await type(page, hit[1]); return; }
    if (!s.some((l) => /^9\. MORE/.test(l))) break;
    await type(page, '9');
  }
  throw new Error('no row matching ' + re);
}

async function toKcgParks(page: Page) {
  await toMenu(page);
  await type(page, '1');
  await pickRow(page, /^(\d)\. KINGDOM OF CRYSTAL GROVES/);
  await endsOn(page, /0\. KINGDOM LIST\s*$/);
  await type(page, '1');
}

async function toPark(page: Page) {
  await toKcgParks(page);
  await pickRow(page, /^(\d)\. ANGLER'S RIFT/);
  await endsOn(page, /8\. SIGN-INS BY DAY\s+0\. PARK LIST\s*$/);
}

// Open menu item n from the park profile, return the settled screen, then 0 back to the profile.
async function visit(page: Page, n: number, title: RegExp) {
  await type(page, String(n));
  const s = await settled(page, n === 1 || n === 2 ? undefined : title);
  await widthOk(page);
  return s;
}
async function backToPark(page: Page) {
  await type(page, '0');
  await endsOn(page, /8\. SIGN-INS BY DAY\s+0\. PARK LIST\s*$/);
}

test('the KCG park list is selectable and a pick opens the park profile', async ({ page }) => {
  await toKcgParks(page);
  const s = await settled(page, /PARKS +PAGE 1 OF 2$/);
  expect(s.some((l) => /^1\. .+ \(\w+\)/.test(l))).toBe(true);
  await widthOk(page);
  await pickRow(page, /^(\d)\. ANGLER'S RIFT/);
  await endsOn(page, /8\. SIGN-INS BY DAY\s+0\. PARK LIST\s*$/);
  const t = (await log(page).innerText()) || '';
  expect(t).toContain("ARMS OF ANGLER'S RIFT");
  expect(t).toContain('BARONY, KCG');
  expect(t).toContain('CATONSVILLE, MARYLAND');
  expect(t).toMatch(/AVG\/WEEK \d+\.\d/);
  expect(t).toMatch(/AVG\/MONTH \d+\.\d/);
  for (const [n, name] of ['MEETING DAYS', 'WHERE TO FIND US', 'OFFICERS', 'RECENT PLAYERS', 'EVENTS', 'COMPANIES', 'ATTENDANCE', 'SIGN-INS BY DAY'].entries()) {
    expect(t).toContain(`${n + 1}. ${name}`);
  }
  await widthOk(page);
  await page.locator('#orkple canvas').screenshot({ path: test.info().outputPath('park-profile.png') });
  // 0 returns to the park list, page 2 where Angler's Rift sits or wherever it was picked.
  await type(page, '0');
  await settled(page, /PARKS +PAGE \d OF 2$/);
});

test('MEETING DAYS, WHERE TO FIND US, OFFICERS: content, persona opens the profile, back paths', async ({ page, request }) => {
  // OFFICERS is served from the real list with the second office vacated: who holds office changes with the data,
  // and the park page parity test checks the real list.
  const real = (await (await request.get(`../orkservice/Json/index.php?call=Park/GetOfficers&request[ParkId]=${PARK}`)).json()).Officers as any[];
  expect(real.length).toBeGreaterThanOrEqual(2);
  const officers = real.map((o, i) => (i === 1 ? { ...o, MundaneId: 0, Persona: '', UserName: '' } : o));
  await page.route(/call=Park\/GetOfficers(&|$)/, (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify({ Status: { Status: 0 }, Officers: officers }) }));
  await toPark(page);

  let s = await visit(page, 1, /MEETING DAYS/);
  let t = s.join('\n');
  expect(t).toContain('WEDNESDAYS 7:00 PM');
  expect(t).toContain('A&S DAY, ONLINE');
  expect(t).toContain('SATURDAYS 12:00 PM');
  expect(t).toContain('PARK DAY');
  expect(t).not.toMatch(/NEXT|TODAY|TOMORROW/);   // the API gives no next date; none is invented
  await backToPark(page);

  s = await visit(page, 2, /WHERE TO FIND US/);
  t = s.join('\n');
  expect(t).toContain('ADDRESS');
  expect(t).toContain('1300 CAMPUS DR');
  expect(t).toContain('DIRECTIONS');
  expect(t).toContain('PARKING ON THE DOWNHILL SIDE');
  expect(t).toContain('ABOUT');
  await type(page, '9');
  const p2 = (await settled(page, /WHERE TO FIND US/, 2)).join('\n');
  expect(p2).toContain('WEB');
  expect(p2).toContain('HTTPS://LINKTR.EE/ANGLERSRIFT');
  expect(t + p2).not.toMatch(/<|&[a-z]+;/i);
  await backToPark(page);

  s = await visit(page, 3, /^OFFICERS/);
  t = s.join('\n');
  const role = (o: any) => String(o.OfficerRole).toUpperCase().replace('PRIME MINISTER', 'PRIME MIN');
  const first = String(officers[0].Persona).toUpperCase();
  expect(s.find((l) => /^1\. /.test(l))!.replace(/\s+/g, ' ').startsWith(`1. ${role(officers[0])} ${first}`.slice(0, 30))).toBe(true);
  expect(s.find((l) => /^2\. /.test(l))!.replace(/\s+/g, ' ')).toBe(`2. ${role(officers[1])} (VACANT)`);
  for (const o of officers) if (o.UserName && !String(o.Persona).toUpperCase().includes(String(o.UserName).toUpperCase())) expect(t).not.toContain(String(o.UserName).toUpperCase());   // no usernames
  expect(t).not.toMatch(/\d{4,}/);   // no ids
  // a vacant office cannot be opened
  await type(page, '2');
  await expect(log(page)).toContainText('?VACANT OFFICE');
  // a persona opens the player profile and 0 comes back to OFFICERS
  await type(page, '1');
  await endsOn(page, /4\. TITLES\s+5\. ABOUT\s+6\. COMPANIES\s+7\. MILESTONES\s+0\. BACK\s*$/);
  await expect(log(page)).toContainText(first);
  await type(page, '0');
  await settled(page, /^OFFICERS/);
  await backToPark(page);
});

test('RECENT PLAYERS, EVENTS, COMPANIES: content and back paths', async ({ page }) => {
  await toPark(page);

  let s = await visit(page, 4, /RECENT PLAYERS/);
  expect(s.filter((l) => /^[1-8]\. /.test(l)).length).toBe(8);
  expect(s.some((l) => /\d\d\/\d\d\/\d\d$/.test(l))).toBe(true);
  await type(page, '1');
  await endsOn(page, /4\. TITLES\s+5\. ABOUT\s+6\. COMPANIES\s+7\. MILESTONES\s+0\. BACK\s*$/);
  await type(page, '0');
  await settled(page, /RECENT PLAYERS/);
  await type(page, '9');
  await settled(page, /RECENT PLAYERS/, 2);
  await backToPark(page);

  s = await visit(page, 5, /^EVENTS/);
  // which events are coming changes with the data (park page parity checks the names); here: dated rows, none of them over
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const dated = s.map((l) => /^\d\. (\d\d)\/(\d\d)\/(\d\d) \S/.exec(l)).filter(Boolean) as RegExpExecArray[];
  if (!dated.length) expect(s.join('\n')).toContain('NO UPCOMING EVENTS.');
  for (const m of dated) expect(new Date(2000 + +m[3], +m[1] - 1, +m[2]).getTime(), m[0]).toBeGreaterThanOrEqual(today.getTime() - 86400000 * 7);   // past events stay out (a multi-day event that started this week can still run)
  await backToPark(page);

  s = await visit(page, 6, /COMPANIES/);
  expect(s.some((l) => /^\S.* +\d+\/\d+$/.test(l))).toBe(true);   // NAME  ACTIVE/TOTAL (the names are checked against the unit list below)
  expect(s.join('\n')).toContain('ACTIVE/TOTAL MEMBERS');
  await type(page, '9');
  await settled(page, /COMPANIES/, 2);
  await backToPark(page);
});

test('ATTENDANCE bars, SIGN-INS BY DAY list and a day, back paths and screenshots', async ({ page }) => {
  await toPark(page);

  let s = await visit(page, 7, /^ATTENDANCE/);
  const bars = s.filter((l) => /^[A-Z]{3} \d{4} +\[/.test(l)).length;
  expect(bars).toBeGreaterThanOrEqual(6);   // six months back plus, from the server, the month in progress (parity checks the exact rows)
  expect(bars).toBeLessThanOrEqual(7);
  expect(s.join('\n')).toContain('DISTINCT PLAYERS PER MONTH');
  await page.locator('#orkple canvas').screenshot({ path: test.info().outputPath('park-attendance-chart.png') });
  await backToPark(page);

  s = await visit(page, 8, /SIGN-IN DAYS/);
  expect(s.filter((l) => /^\d\. \d\d\/\d\d\/\d\d [A-Z]{3}$/.test(l)).length).toBe(8);
  await type(page, '1');
  const day = await settled(page, /\(\d+\)/);
  expect(day[0]).toMatch(/^[A-Z]{3} \d\d\/\d\d\/\d\d \(\d+\)/);
  expect(day.join('\n')).not.toMatch(/SELECT|FROM ORK_|SELECT \*/i);   // Status.Detail never reaches the screen
  await widthOk(page);
  await page.locator('#orkple canvas').screenshot({ path: test.info().outputPath('park-signins-day.png') });
  await type(page, '0');
  await settled(page, /SIGN-IN DAYS/);
  await backToPark(page);
  await type(page, '0');
  await settled(page, /PARKS +PAGE \d OF 2$/);
});

test('search reaches a park: parks first, pick opens it, 0 returns to the results', async ({ page }) => {
  await toMenu(page);
  await type(page, '2');
  await endsOn(page, /0\. MAIN MENU\s*$/);
  await type(page, 'angler');
  const s = await settled(page, /MATCHES FOR ANGLER/);
  expect(s[2]).toMatch(/^1\. ANGLER'S RIFT +PARK KCG$/);
  await type(page, '1');
  await endsOn(page, /8\. SIGN-INS BY DAY\s+0\. RESULTS\s*$/);
  await type(page, '3');
  await settled(page, /^OFFICERS/);
  await type(page, '0');
  await endsOn(page, /0\. RESULTS\s*$/);
  await type(page, '0');
  await settled(page, /MATCHES FOR ANGLER/);
});

test('a digit that is not on offer and junk keep the park prompt working', async ({ page }) => {
  await toPark(page);
  await type(page, '9');
  await expect(log(page)).toContainText('?ILLEGAL QUANTITY ERROR');
  await type(page, 'zap');
  await expect(log(page)).toContainText('?SYNTAX ERROR');
  await type(page, '3');
  await settled(page, /^OFFICERS/);
});

// ---- empty and failure states ----

const EMPTY: Record<string, { item: number; title: RegExp; body: unknown; text: string }> = {
  'Park/GetParkDays': { item: 1, title: /MEETING DAYS/, body: { Status: { Status: 0 }, ParkDays: [] }, text: 'NO PARK DAYS ON RECORD.' },
  'Park/GetParkDetails': { item: 2, title: /WHERE TO FIND US/, body: { Status: { Status: 0 }, ParkId: PARK, ParkName: 'X', Address: '', Directions: '', Description: '', Url: '' }, text: 'NO LOCATION ON FILE.' },
  'Park/GetOfficers': { item: 3, title: /^OFFICERS/, body: { Status: { Status: 0 }, Officers: [] }, text: 'NO OFFICERS ON RECORD.' },
  'Report/RecentParkAttendees': { item: 4, title: /RECENT PLAYERS/, body: { Status: { Status: 0 }, Attendees: [] }, text: 'NO SIGN-INS IN THE LAST 90 DAYS.' },
  'SearchService/Event': { item: 5, title: /^EVENTS/, body: { Status: true, Result: [] }, text: 'NO UPCOMING EVENTS.' },
  'Report/UnitSummary': { item: 6, title: /COMPANIES/, body: { Status: { Status: 0 }, Units: [] }, text: 'NO COMPANIES HERE.' },
  'Report/GetMonthlyChartData': { item: 7, title: /^ATTENDANCE/, body: { Status: true, Result: [] }, text: 'NO ATTENDANCE IN THE LAST 6 MONTHS.' },
  'Report/GetAttendanceDates': { item: 8, title: /SIGN-IN DAYS/, body: { Status: { Status: 0 }, Dates: [] }, text: 'NO SIGN-INS IN THE LAST 6 MONTHS.' },
};

test('every park screen has an empty state and a working 0', async ({ page }) => {
  await toPark(page);
  for (const [call, e] of Object.entries(EMPTY)) {
    // Park/GetParkDetails is also in the header; the profile is already loaded, so only the list call is mocked.
    await page.route(new RegExp('call=' + call.replace('/', '\\/') + '(&|$)'), (route) => route.fulfill({ contentType: 'application/json', body: JSON.stringify(e.body) }));
    if (call === 'Park/GetParkDetails') {
      // the header already cached details, so WHERE TO FIND US shows the cached page; test the empty text via the lib instead
      const lines = await page.evaluate(() => (window as any).OrkpleTerminal.lib.parkWhereLines({ Address: '', Directions: '', Description: '', Url: '' }));
      expect(lines).toEqual([]);
      continue;
    }
    await type(page, String(e.item));
    const s = await settled(page, e.title);
    expect(s.join('\n')).toContain(e.text);
    await backToPark(page);
    await page.unroute(new RegExp('call=' + call.replace('/', '\\/') + '(&|$)'));
  }
});

test('an endpoint failure gives ?I/O ERROR, the menu still works, and a retry succeeds', async ({ page }) => {
  await toPark(page);
  const fail: Array<[string, number, RegExp]> = [
    ['Park/GetParkDays', 1, /MEETING DAYS/], ['Park/GetOfficers', 3, /^OFFICERS/], ['Report/RecentParkAttendees', 4, /RECENT PLAYERS/],
    ['SearchService/Event', 5, /^EVENTS/], ['Report/UnitSummary', 6, /COMPANIES/], ['Report/GetMonthlyChartData', 7, /^ATTENDANCE/],
    ['Report/GetAttendanceDates', 8, /SIGN-IN DAYS/],
  ];
  for (const [call, item, title] of fail) {
    const re = new RegExp('call=' + call.replace('/', '\\/') + '(&|$)');
    await page.route(re, (route) => route.abort());
    await type(page, String(item));
    await endsOn(page, /READING DISK\.\.\.\s+\?I\/O ERROR\s+DISK READ FAILED\. TRY AGAIN\.\s*$/);
    await page.unroute(re);
    await page.waitForTimeout(200);
    await type(page, String(item));
    await settled(page, title);
    await backToPark(page);
  }
});

test('a bare numeric Status or a failure Status object is an error, and its Detail is never shown', async ({ page }) => {
  await toPark(page);
  // a bare numeric Status above zero is a failure, not an empty list
  const bare = /call=Park\/GetOfficers(&|$)/;
  await page.route(bare, (route) => route.fulfill({ contentType: 'application/json', body: '{"Status":2}' }));
  await type(page, '3');
  await endsOn(page, /READING DISK\.\.\.\s+\?I\/O ERROR\s+DISK READ FAILED\. TRY AGAIN\.\s*$/);
  await page.unroute(bare);
  // a Status object with a failure code is a failure too, and its Detail (it can hold SQL) is never shown
  const obj = /call=Report\/RecentParkAttendees(&|$)/;
  await page.route(obj, (route) => route.fulfill({ contentType: 'application/json', body: '{"Status":{"Status":4,"Error":"bad","Detail":"SELECT secret FROM ork_x"},"Attendees":[]}' }));
  await type(page, '4');
  // two failures in a row redraw the park screen, as everywhere in the terminal
  await endsOn(page, /READING DISK\.\.\.\s+\?I\/O ERROR\s+DISK READ FAILED\. TRY AGAIN\.\s+ARMS OF[\s\S]*0\. PARK LIST\s*$/);
  expect((await log(page).innerText()) || '').not.toMatch(/SECRET/i);
  await page.unroute(obj);
  // Status:false is a failure
  const no = /call=Report\/UnitSummary(&|$)/;
  await page.route(no, (route) => route.fulfill({ contentType: 'application/json', body: '{"Status":false}' }));
  await type(page, '6');
  await endsOn(page, /READING DISK\.\.\.\s+\?I\/O ERROR\s+DISK READ FAILED\. TRY AGAIN\.\s*$/);
  await page.unroute(no);
  // and the same screens still open afterwards
  await type(page, '3');
  await settled(page, /^OFFICERS/);
});

test('a failed park header read gives ?I/O ERROR on the list and a missing field is handled', async ({ page }) => {
  // Details, field count and averages are optional. The averages are one kingdom read shared with the park list,
  // so they are failed before the list is read.
  const opt = [/call=Park\/GetParkDetails(&|$)/, /call=Report\/GetParkFieldCountToday(&|$)/, /Kingdom\/park_averages_json/];
  for (const r of opt) await page.route(r, (route) => route.abort());
  await toKcgParks(page);
  await settled(page, /PARKS +PAGE 1 OF 2$/);
  const re = /call=Park\/GetParkShortInfo(&|$)/;
  await page.route(re, (route) => route.abort());
  await type(page, '1');
  await expect(log(page)).toContainText('?I/O ERROR', { timeout: 10000 });
  await page.unroute(re);
  // with all three gone the profile still opens, with dashes
  await page.waitForTimeout(200);
  await type(page, '1');
  await endsOn(page, /8\. SIGN-INS BY DAY\s+0\. PARK LIST\s*$/);
  const t = (await log(page).innerText()) || '';
  expect(t).toContain('AVG/WEEK --');
  expect(t).toContain('AVG/MONTH --');
  // and WHERE TO FIND US retries the read, failing cleanly
  await type(page, '2');
  await expect(log(page)).toContainText('?I/O ERROR', { timeout: 10000 });
  for (const r of opt) await page.unroute(r);
});

// ---- page parity, as an anonymous visitor ----

function decode(s: string): string {
  return s.replace(/<[^>]*>/g, '').replace(/&amp;/g, '&').replace(/&#0?39;|&apos;/g, "'").replace(/&quot;/g, '"').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&middot;/g, '.').replace(/\s+/g, ' ').trim();
}
const up = (s: string) => decode(s).toUpperCase();

test('park page parity: officers, averages, schedule, events and attendance match the real page', async ({ page, request }) => {
  const html = await (await request.get(`./index.php?Route=Park/profile/${PARK}`)).text();
  const att6 = await (await request.get(`./index.php?Route=Reports/attendance/Park/${PARK}/Months/6`)).text();
  await toPark(page);

  // header figures
  const head = (await log(page).innerText()) || '';
  const stat = (label: string) => new RegExp('pk-stat-value[^>]*>([^<]*)</div>\\s*<div class="pk-stat-label">' + label).exec(html)![1].trim();
  expect(head).toContain('AVG/WEEK ' + stat('Avg / Week'));
  expect(head).toContain('AVG/MONTH ' + stat('Avg / Month'));

  // schedule
  const whens = [...html.matchAll(/pk-schedule-when">([^<]*)</g)].map((m) => decode(m[1]));
  const times = [...html.matchAll(/pk-schedule-time">([^<]*)</g)].map((m) => decode(m[1]));
  let s = await visit(page, 1, /MEETING DAYS/);
  whens.forEach((w, i) => {
    const day = w.replace(/^Every /, '').toUpperCase() + 'S';
    expect(s.join('\n'), w).toContain(`${day} ${times[i]}`);
  });
  await backToPark(page);

  // officers: every role and persona on the page, vacant where the page says Vacant
  const pairs = [...html.matchAll(/pk-officer-role">([^<]*)<\/span>\s*<span class="pk-officer-name">([\s\S]*?)<\/span>\s*<\/li>/g)];
  expect(pairs.length).toBeGreaterThan(0);
  s = await visit(page, 3, /^OFFICERS/);
  const rows = s.filter((l) => /^[1-8]\. /.test(l)).map((l) => l.replace(/^\d\. /, ''));
  expect(rows.length).toBe(pairs.length);
  pairs.forEach((m, i) => {
    const role = up(m[1]).replace('PRIME MINISTER', 'PRIME MIN');
    const who = up(m[2]) === 'VACANT' ? '(VACANT)' : up(m[2]);
    expect(rows[i].replace(/\s+/g, ' ')).toBe(`${role} ${who}`);
  });
  await backToPark(page);

  // events: the page's own rows (the table also carries park-day rows, which have no date)
  const evRows = [...html.matchAll(/<tr[^>]*>([\s\S]*?)<\/tr>/g)].map((m) => decode(m[1])).filter((t) => /\b\w{3}\. \d{1,2}, \d{4}/.test(t));
  s = await visit(page, 5, /^EVENTS/);
  const shown = s.filter((l) => /^(\d\. )?\d\d\/\d\d\/\d\d /.test(l));   // rows are numbered: each opens EVENT DETAIL
  expect(shown.length).toBe(evRows.length);
  evRows.forEach((r) => {
    const name = r.replace(/\s*\w{3}\. \d{1,2}, \d{4}.*$/, '').toUpperCase();
    expect(s.join('\n')).toContain(name);
  });
  await backToPark(page);

  // monthly attendance: the same six months and counts the 6 month report plots
  const labels: string[] = JSON.parse(/var monthlyDates\s*=\s*(\[.*?\]);/.exec(att6)![1]);
  const counts: number[] = JSON.parse(/var monthlyCounts\s*=\s*(\[.*?\]);/.exec(att6)![1]);
  s = await visit(page, 7, /^ATTENDANCE/);
  const bars = s.filter((l) => /^[A-Z]{3} \d{4} +\[/.test(l));
  expect(bars.length).toBe(labels.length);
  labels.forEach((lab, i) => {
    expect(bars[i].startsWith(lab.toUpperCase())).toBe(true);
    expect(bars[i].endsWith(' ' + counts[i])).toBe(true);
  });
  await backToPark(page);

  // sign-in days: the same dates the 6 month report links, across every page
  const want = [...new Set([...att6.matchAll(/Attendance\/park\/1049&AttendanceDate=(\d{4}-\d{2}-\d{2})/g)].map((m) => m[1]))].sort().reverse();
  await type(page, '8');
  const got: string[] = [];
  for (let n = 1; n < 40; n++) {
    const sc = await settled(page, /SIGN-IN DAYS/, n);
    sc.forEach((l) => { const m = /^\d\. (\d\d)\/(\d\d)\/(\d\d) [A-Z]{3}$/.exec(l); if (m) got.push(`20${m[3]}-${m[1]}-${m[2]}`); });
    if (!sc.some((l) => /^9\. MORE/.test(l))) break;
    await type(page, '9');
  }
  // the page's list is bounded by its own summary window; every date the page links must be in ours
  const missing = want.filter((d) => !got.includes(d));
  expect(missing).toEqual([]);
  expect(got.length).toBeGreaterThanOrEqual(want.length);
  expect(got.length - want.length).toBeLessThanOrEqual(2);   // the window edge may differ by a day or two

  // one day's sign-in count equals the rows on the real day page
  await type(page, '1');   // row 1 of the page we ended on
  const d = await settled(page, /\(\d+\)/);
  const m = /(\d\d)\/(\d\d)\/(\d\d) \((\d+)\)/.exec(d[0])!;
  const opened = `20${m[3]}-${m[1]}-${m[2]}`;
  const day = await (await request.get(`./index.php?Route=Attendance/park/${PARK}&AttendanceDate=${opened}`)).text();
  expect((day.match(/<tr data-att-id=/g) || []).length).toBe(+m[4]);
  expect(+m[4]).toBeGreaterThan(0);
});

test('companies match the Company rows of the real unit list for the park', async ({ page, request }) => {
  const list = await (await request.get(`./index.php?Route=Search/unitsearch&q=&ParkId=${PARK}`)).json();
  const names: string[] = list.filter((u: any) => u.Type === 'Company').map((u: any) => u.Name);
  expect(names.length).toBeGreaterThan(0);
  await toPark(page);
  await type(page, '6');
  const shown: string[] = [];
  for (let n = 1; n < 20; n++) {
    const sc = await settled(page, /COMPANIES/, n);
    sc.forEach((l) => { const m = /^(.*?) +\d+\/\d+$/.exec(l); if (m && !/^(COMPANIES|=)/.test(l) && !/ACTIVE/.test(l)) shown.push(m[1]); });
    if (!sc.some((l) => /^9\. MORE/.test(l))) break;
    await type(page, '9');
  }
  const clean = await page.evaluate((ns) => ns.map((x: string) => (window as any).OrkpleTerminal.lib.clean(x)), names);
  expect(shown.sort()).toEqual(clean.map((x: string) => x.slice(0, 39)).sort());
});

test('kingdom parks list matches the website Parks tab (active parks only)', async ({ page, request }) => {
  const html = await (await request.get('./index.php?Route=Kingdom/profile/17')).text();
  const count = +/data-kntab="parks"[\s\S]*?kn-tab-count">\((\d+)\)/.exec(html)![1];
  await toKcgParks(page);
  let n = 0;
  for (let k = 1; k < 10; k++) {
    const sc = await settled(page, /PARKS/, k);
    n += sc.filter((l) => /^[1-8]\. /.test(l)).length;
    if (!sc.some((l) => /^9\. MORE/.test(l))) break;
    await type(page, '9');
  }
  expect(n).toBe(count);
});

// ---- widths ----

test('nothing over 39 columns or 23 rows prints, even for very long names', async ({ page, request }) => {
  await loadTerminal(page);
  const api = async (call: string, k: string, v: number) => (await (await request.get(`../orkservice/Json/index.php?call=${call}&request[${k}]=${v}`)).json());
  const kd = await api('Kingdom/GetKingdoms', 'x', 1);
  const kids = [17, ...Object.values(kd.Kingdoms).map((k: any) => +k.KingdomId).filter((k: number) => k !== 17).slice(0, 8)];
  const real: any[] = [];
  for (const kid of kids) {
    const ps = await api('Kingdom/GetParks', 'KingdomId', kid);
    for (const p of (ps.Parks || []).slice(0, 12)) {
      const d = await api('Park/GetParkDetails', 'ParkId', p.ParkId);
      const dd = await api('Park/GetParkDays', 'ParkId', p.ParkId);
      if (d.ParkName) real.push({ id: p.ParkId, d, days: dd.ParkDays || [] });
    }
    if (real.length >= 60) break;
  }
  const res = await page.evaluate(async (real) => {
    const L = (window as any).OrkpleTerminal.lib;
    const bad: string[] = [];
    const chk = (what: string, lines: string[], maxRows = 23) => {
      lines.forEach((l) => { if (l.replace(/\x01/g, '').length > 39) bad.push(what + ' wide: ' + l); });
      if (lines.length > maxRows) bad.push(what + ' tall: ' + lines.length);
    };
    const longName = 'The Barony of the Exceedingly Exhaustively Long-Named Marches, Highlands and Fenlands of Llanfairpwllgwyngyll';
    const worst = {
      name: longName, kingdomAbbr: 'KCG', field: 12345, avgWeek: '123.4', avgMonth: '1234.5', retired: true,
      details: { ParkTitle: 'Grand Duchy of the Seven Realms', City: 'Llanfairpwllgwyngyllgogerychwyrndrobwllllantysiliogogogoch', Province: 'Northern Territories and Outlying Isles' },
    };
    chk('park profile', L.parkParts(worst, 'PARK LIST').lines);
    chk('park profile, no details', L.parkParts({ name: longName, kingdomAbbr: '', field: null, avgWeek: null, avgMonth: null, details: null }, 'MAIN MENU').lines);
    const day = L.parkDayText({ Recurrence: 'every-x-weeks', WeekInterval: 12, WeekDay: 'Wednesday', Time: '19:30:00', Purpose: 'fighter-practice', Online: 0, Address: '1234567890 Extraordinarily Long Boulevard Name, Suite 2000', City: 'Springfield-on-the-Longest-River', Province: 'Mississippi', PostalCode: '12345-6789', Description: '<p>Join us for a very long description that keeps going and going and going.</p><p>Second paragraph.</p>' });
    chk('park day', day.split('\n'), 6);
    const where = L.parkWhereLines({ Address: '1234567890 Extraordinarily Long Boulevard Name', City: 'Springfield', Province: 'Mississippi', PostalCode: '12345', Directions: '<p>Take exit 12, then <b>turn left</b> &amp; follow the road ' + 'for-a-very-long-way '.repeat(40) + '</p>', Description: 'About '.repeat(100), Url: 'https://example.org/' + 'a'.repeat(120) });
    chk('where', where, 100);
    const page16 = L.pagerLines({ title: 'WHERE TO FIND US', items: where, perPage: 16, back: 'BACK' });
    chk('where page', page16.lines.concat(['SELECT: ']));
    const days3 = L.pagerLines({ title: 'MEETING DAYS', items: [day, day, day], perPage: 3, back: 'BACK' });
    chk('days page', days3.lines.concat(['SELECT: ']));
    const sel = L.pagerLines({ title: 'OFFICERS', selectable: true, back: 'BACK', items: Array(8).fill('PRIME MIN  ' + 'Persona With An Extremely Long Name Indeed Yes'), extras: [] });
    chk('officers page', sel.lines.concat(['SELECT: ']));
    chk('chart', L.pagerLines({ title: 'ATTENDANCE', items: L.barChart([{ label: 'Sep 2026', value: 123456 }, { label: 'Oct 2026', value: 3 }]), back: 'BACK', notes: ['', 'DISTINCT PLAYERS PER MONTH'] }).lines);
    // HTML in, plain text out
    const t = L.htmlText('<p>Hello <b>World</b><script>alert(1)</script></p><img src=x onerror=alert(1)><ul><li>one</li><li>two &amp; three</li></ul>', 39);
    if (/[<>]/.test(t.join(''))) bad.push('htmlText left markup: ' + t.join('|'));
    let seen = 0;
    for (const x of real) {
      const d = x.d;
      seen++;
      chk('real park ' + x.id, L.parkParts({ name: d.ParkName, kingdomAbbr: 'KCG', field: 3, avgWeek: '12.3', avgMonth: '45.6', retired: d.Active !== 'Active', details: d }, 'PARK LIST').lines);
      chk('real where ' + x.id, L.pagerLines({ title: 'WHERE TO FIND US', items: L.parkWhereLines(d), perPage: 16, back: 'BACK' }).lines.concat(['SELECT: ']));
      for (const day of x.days) chk('real day ' + x.id, L.parkDayText(day).split('\n'), 6);
    }
    return { bad, seen };
  }, real);
  expect(res.seen).toBeGreaterThan(10);
  expect(res.bad).toEqual([]);
});

// ---- the allow-list ----

test('the plan document lists exactly the endpoints the terminal allows', async ({ page }) => {
  await loadTerminal(page);
  const plan = fs.readFileSync(path.join(__dirname, '../../docs/superpowers/plans/2026-11-01-orkple-read-surfaces.md'), 'utf8');
  const sec = /## Allow-list[\s\S]*?\n(?=## )/.exec(plan);
  expect(sec, 'plan has an Allow-list section').not.toBeNull();
  const inDoc = [...sec![0].matchAll(/^\| `([A-Za-z]+\/[A-Za-z_]+)`/gm)].map((m) => m[1]).sort();
  const allowed: string[] = await page.evaluate(() => (window as any).OrkpleTerminal.lib.allowed.slice());
  expect(inDoc).toEqual(allowed.slice().sort());
});

test('an endpoint off the list is always refused and logged, whatever the test flag says; the probe hook is tests only', async ({ page }) => {
  await loadTerminal(page);
  const r = await page.evaluate(async () => {
    const T = (window as any).OrkpleTerminal;
    const out: any = {};
    const opts = () => ({ canvas: document.createElement('canvas'), input: document.createElement('input'), live: document.createElement('div'), service: '/orkservice/', ui: '/orkui/index.php?Route=', onExit() {}, reducedMotion: true, user: 0 });
    // Without the test flag at mount there is no probe hook at all.
    (window as any).OrkpleStrict = false;
    const plain = T.mount(opts());
    out.probeWithoutFlag = typeof plain.probe;
    plain.destroy();
    // Mount with the flag (for the hook), then run the probes with it on and off: the refusal does not depend on it.
    (window as any).OrkpleStrict = true;
    const t = T.mount(opts());
    for (const strict of [true, false]) {
      (window as any).OrkpleStrict = strict;
      (window as any).OrkpleViolations = [];
      for (const [key, url] of [['secret', '/orkservice/Json/index.php?call=Kingdom/GetSecretThing&request[x]=1'], ['admin', '/orkui/index.php?Route=Admin/secret']]) {
        let how = 'no throw';
        try { await t.probe(url); } catch (e: any) { how = /allow-list/.test(String(e.message)) ? 'allow-list throw' : 'other: ' + e.message; }
        out[strict + ' ' + key] = how;
      }
      out['violations ' + strict] = (window as any).OrkpleViolations.slice();
    }
    t.destroy();
    (window as any).OrkpleViolations = [];
    (window as any).OrkpleStrict = true;
    return out;
  });
  expect(r.probeWithoutFlag).toBe('undefined');
  for (const strict of [true, false]) {
    expect(r[strict + ' secret']).toBe('allow-list throw');
    expect(r[strict + ' admin']).toBe('allow-list throw');
    expect(r['violations ' + strict]).toEqual(['Kingdom/GetSecretThing', 'Admin/secret']);
  }
  expect(calls).not.toContain('Kingdom/GetSecretThing');   // refused before any request goes out
});
