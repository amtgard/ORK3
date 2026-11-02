/**
 * ORKPLE II events (Phase 2 of docs/superpowers/plans/2026-11-01-orkple-read-surfaces.md).
 * Walks the KINGDOM CALENDAR, EVENT DETAIL (description, RSVP counts, schedule, who came) and PAST EVENTS on KCG (kingdom 17) and
 * park 1049 through the #orkple [role=log] text, with the allow-list in strict mode, then checks them against the real
 * Event page and kingdom page as an anonymous visitor. Also covers the small extras: host.keys noRepeat, AT FIELD NOW, NEXT UP.
 */
import { test, expect, Page } from '@playwright/test';

const SCRATCH = '/private/tmp/claude-501/-Users-averykrouse-GitHub-ORK-tobias-ORK3-tobias/d4a03171-dc47-4ef6-af33-e92cb0cdbd0c/scratchpad/';
const PARK = 1049;
const KFC = { eid: 17767, did: 8535 };      // Kingdom Fall Coronation: a 4 day event, 179 sign-ins, no schedule
const TOURNEY = { eid: 18762, did: 9321 };  // Kingdom Tournament at Angler's Rift: a two item schedule, no sign-ins

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
const endsOn = (page: Page, re: RegExp) => expect(log(page)).toHaveText(re, { timeout: 20000 });
const calls: string[] = [];

test.beforeEach(async ({ baseURL, page }, testInfo) => {
  if (!baseURL || !(await appReachable(baseURL))) {
    testInfo.skip(true, 'ORK3 app not reachable — start docker compose php8 stack');
  }
  calls.length = 0;
  await page.addInitScript(() => { (window as any).OrkpleStrict = true; });
  page.on('request', (r) => {
    const m = /Json\/index\.php\?call=([^&]+)/.exec(r.url());
    if (m) calls.push(decodeURIComponent(m[1]));
  });
  await page.goto('./index.php?Route=Search');
});

test.afterEach(async ({ page }) => {
  const allowed: string[] = await page.evaluate(() => ((window as any).OrkpleTerminal ? (window as any).OrkpleTerminal.lib.allowed : null)) || [];
  const violations = await page.evaluate(() => (window as any).OrkpleViolations || []);
  expect(violations).toEqual([]);
  expect(calls.filter((c) => !allowed.includes(c))).toEqual([]);
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
  // A signed-in player who has not seen the current release gets the What's New modal first.
  const wn = page.locator('#wn-overlay.wn-open');
  if (await wn.count()) { await wn.getByRole('button', { name: /^(Later|Got it!)$/ }).click(); }
  await page.locator('#orkple-floppy').click();
  await expect(log(page)).toContainText('ORKPLE II', { timeout: 10000 });
  await type(page, 'ork');
  await expect(log(page)).toContainText('ORK GAMES', { timeout: 10000 });
}
async function lastScreen(page: Page): Promise<string[]> {
  const lines = (((await log(page).textContent()) || '').split('\n'));
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
  const lines = ((await log(page).textContent()) || '').split('\n');
  for (const l of lines) expect(l.length, `"${l}"`).toBeLessThanOrEqual(39);
}
async function pickRow(page: Page, re: RegExp, first = 1): Promise<void> {
  for (let n = first; n < first + 40; n++) {
    const s = await settled(page, undefined, n);
    const hit = s.map((l) => re.exec(l)).find(Boolean) as RegExpExecArray | undefined;
    if (hit) { await type(page, hit[1]); return; }
    if (!s.some((l) => /^9\. MORE/.test(l))) break;
    await type(page, '9');
  }
  throw new Error('no row matching ' + re);
}

const EVENT_END = /4\. WHO CAME\s+0\. [A-Z ]+\s*$/;
const CAL = /EVENTS [A-Z]{3} \d{4}/;

async function toKcgProfile(page: Page) {
  await toMenu(page);
  await type(page, '1');
  await pickRow(page, /^(\d)\. KINGDOM OF CRYSTAL GROVES/);
  await endsOn(page, /0\. KINGDOM LIST\s*$/);
}
async function toCalendar(page: Page) {
  await toKcgProfile(page);
  await type(page, '2');
  return settled(page, CAL);
}
const MON = ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'];
// Step the calendar to a month with 7 (previous) and 9 (next, on a month's last page).
async function gotoMonth(page: Page, mon: string, year: number) {
  const want = year * 12 + MON.indexOf(mon);
  for (let n = 0; n < 60; n++) {
    const s = await settled(page, CAL);
    const m = /EVENTS ([A-Z]{3}) (\d{4})/.exec(s[0])!;
    const cur = +m[2] * 12 + MON.indexOf(m[1]);
    if (cur === want) return s;
    await type(page, cur > want ? '7' : '9');
    const before = s[0];
    await expect.poll(async () => (await lastScreen(page))[0], { timeout: 20000 }).not.toBe(before);
  }
  throw new Error('month not reached');
}
async function toKfc(page: Page) {
  await toCalendar(page);
  await gotoMonth(page, 'OCT', 2026);
  await pickRow(page, /^(\d)\. 10\/08 KINGDOM FALL CORONATION/);
  await endsOn(page, EVENT_END);
}
async function toPark(page: Page) {
  await toKcgProfile(page);
  await type(page, '1');
  await pickRow(page, /^(\d)\. ANGLER'S RIFT/);
  await endsOn(page, /8\. SIGN-INS BY DAY\s+0\. PARK LIST\s*$/);
}

// ---- KINGDOM CALENDAR ----

test('the kingdom profile opens the calendar: dated rows, 9 is the next month, 7 the previous, 0 goes back', async ({ page }) => {
  await toKcgProfile(page);
  expect((await log(page).textContent()) || '').toMatch(/2\. EVENTS\s+3\. PAST EVENTS\s+4\. COMPANIES AND HOUSEHOLDS\s+5\. KNIGHTS AND MASTERS\s+0\. KINGDOM LIST/);
  await type(page, '2');
  let s = await settled(page, CAL);
  await widthOk(page);
  expect(s[0]).toMatch(/^KCG EVENTS [A-Z]{3} \d{4} +PAGE 1 OF \d+$/);
  expect(s.join('\n')).toMatch(/7\. PREVIOUS MONTH\s+8\. PARK DAYS: OFF\s+9\. (MORE|NEXT MONTH \([A-Z]{3}\))\s+0\. BACK/);
  await page.locator('#orkple canvas').screenshot({ path: SCRATCH + 'events-calendar.png' });

  s = await gotoMonth(page, 'OCT', 2026);
  const rows = s.filter((l) => /^[1-6]\. /.test(l));
  expect(rows.length).toBeGreaterThan(0);
  expect(rows.every((l) => /^\d\. \d\d\/\d\d [A-Z0-9]/.test(l))).toBe(true);   // every row is dated
  expect(s.join('\n')).toContain('10/08 KINGDOM FALL CORONATION');
  expect(s.join('\n')).toContain('NEXT MONTH (NOV)');
  await type(page, '9');
  s = await settled(page, /EVENTS NOV 2026/);
  await type(page, '7');
  await settled(page, /EVENTS OCT 2026/);
  await type(page, '0');
  await endsOn(page, /3\. PAST EVENTS\s+4\. COMPANIES AND HOUSEHOLDS\s+5\. KNIGHTS AND MASTERS\s+0\. KINGDOM LIST\s*$/);
  await type(page, '0');
  await settled(page, /KINGDOMS +PAGE \d OF/);
});

test('rows of every type: park days off by default, on with 8; a park day opens its park, a calendar item opens its own screen', async ({ page }) => {
  await toCalendar(page);
  let s = await gotoMonth(page, 'NOV', 2026);
  expect(s.join('\n')).toContain('8. PARK DAYS: OFF');
  expect(s.join('\n')).not.toMatch(/^\d\. \d\d\/\d\d .*PARK DAY/m);
  await type(page, '8');
  await expect(log(page)).toContainText('8. PARK DAYS: ON', { timeout: 10000 });
  s = await settled(page, CAL);
  const days = s.filter((l) => /^\d\. \d\d\/\d\d [A-Z0-9]+: (PARK DAY|FIGHTER PRACTICE|A&S DAY)/.test(l));
  expect(days.length).toBeGreaterThan(0);
  // a park day opens that park, and 0 comes back to the calendar
  await pickRow(page, /^(\d)\. \d\d\/\d\d AF: PARK DAY/);
  await endsOn(page, /8\. SIGN-INS BY DAY\s+0\. CALENDAR\s*$/);
  await type(page, '0');
  s = await settled(page, /EVENTS NOV 2026/);
  expect(s.join('\n')).toContain('8. PARK DAYS: ON');
  await type(page, '8');   // off again
  await expect(log(page)).toContainText('8. PARK DAYS: OFF', { timeout: 10000 });
  // a calendar item (August has the PH quest day)
  await gotoMonth(page, 'AUG', 2026);
  await pickRow(page, /^(\d)\. 08\/02 PH: QUEST DAY/);
  await endsOn(page, /CALENDAR ITEM +PAGE 1 OF 1[\s\S]*0\. CALENDAR\s*$/);
  const t = (await log(page).textContent()) || '';
  expect(t).toContain('PH: QUEST DAY');
  expect(t).toMatch(/SUN 08\/02\/26 1:00 PM\s+TO 4:00 PM/);
  expect(t).toContain('PARK-LEVEL CALENDAR ITEM');
  await widthOk(page);
  await type(page, '0');
  await settled(page, /EVENTS AUG 2026/);
});

test('calendar parity: the rows of a month are the rows the kingdom page lists for it', async ({ page, request }) => {
  const html = await (await request.get('./index.php?Route=Kingdom/profile/17')).text();
  // the page's event table: date, name (the park abbreviation is not in the name) and data-type
  const rows = [...html.matchAll(/<tr class="kn-row-link[^"]*" data-type="(kingdom-event|park-event|calendar-item)"[^>]*>([\s\S]*?)<\/tr>/g)];
  const up = (s: string) => s.replace(/<[^>]*>/g, ' ').replace(/&amp;/g, '&').replace(/&#0?39;|&apos;/g, "'").replace(/&quot;/g, '"').replace(/\s+/g, ' ').trim().toUpperCase();
  const cells = rows.map((r) => {
    const tds = [...r[2].matchAll(/<td[^>]*>([\s\S]*?)<\/td>/g)].map((m) => up(m[1]));
    return { type: r[1], date: tds[0].replace(/ PAST$/, ''), name: tds[1].replace(/^CALENDAR ITEM /, '') };
  });
  const target = cells.filter((c) => /^NOV \d+, 2026$/.test(c.date));
  expect(target.length).toBeGreaterThan(0);
  await toCalendar(page);
  let s = await gotoMonth(page, 'NOV', 2026);
  const shown: string[] = [];
  for (let n = 1; n < 10; n++) {
    s = await settled(page, CAL, n);
    s.filter((l) => /^\d\. \d\d\/\d\d /.test(l)).forEach((l) => shown.push(l));
    if (!s.some((l) => /^9\. MORE/.test(l))) break;
    await type(page, '9');
  }
  expect(shown.length).toBe(target.length);
  for (const c of target) {
    const day = c.date.replace(/^NOV (\d+), 2026$/, (_m, d) => '11/' + String(d).padStart(2, '0'));
    expect(shown.some((l) => l.includes(day + ' ') && l.includes(c.name.slice(0, 12))), `${day} ${c.name}`).toBe(true);
  }
});

test('an empty month says so, and a failed read gives ?I/O ERROR with the prompt working', async ({ page }) => {
  await toCalendar(page);
  await gotoMonth(page, 'NOV', 2026);
  const re = /KingdomAjax\/calendar/;
  await page.route(re, (route) => route.fulfill({ contentType: 'application/json', body: '{"status":0,"events":[]}' }));
  await type(page, '9');
  let s = await settled(page, /EVENTS DEC 2026/);
  expect(s.join(' ')).toContain('NOTHING THIS MONTH. PARK DAYS ARE HIDDEN.');
  await type(page, '8');
  await expect(log(page)).toContainText('8. PARK DAYS: ON', { timeout: 10000 });
  s = await settled(page, /EVENTS DEC 2026/);
  expect(s.join('\n')).toContain('NOTHING THIS MONTH.');
  expect(s.join('\n')).not.toContain('PARK DAYS ARE HIDDEN');
  await page.unroute(re);
  // a failure on the next month
  await page.route(re, (route) => route.abort());
  await type(page, '9');
  await endsOn(page, /READING DISK\.\.\.\s+\?I\/O ERROR\s*$/);
  await page.unroute(re);
  await page.waitForTimeout(200);
  await type(page, '9');
  await settled(page, /EVENTS JAN 2027/);
  await type(page, '8');   // park days off again: a short month, so 9 is the next month
  await expect(log(page)).toContainText('8. PARK DAYS: OFF', { timeout: 10000 });
  await settled(page, /EVENTS JAN 2027/);
  // a status:1 reply is a failure too
  await page.route(re, (route) => route.fulfill({ contentType: 'application/json', body: '{"status":1,"error":"Invalid date range"}' }));
  await type(page, '9');
  await endsOn(page, /READING DISK\.\.\.\s+\?I\/O ERROR\s*$/);
  await page.unroute(re);
});

// ---- EVENT DETAIL ----

test('EVENT DETAIL: name, dates, place and arms; the menu; back to the calendar month', async ({ page }) => {
  await toKfc(page);
  const t = (await log(page).textContent()) || '';
  const screen = t.slice(t.lastIndexOf('ARMS OF'));
  expect(screen).toContain('ARMS OF KINGDOM FALL CORONATION (KFC)');
  expect(screen).toContain('CORONATION');
  expect(screen).toContain('THU 10/08/26 12:00 PM');
  expect(screen).toContain('TO SUN 10/11/26 12:00 PM');
  expect(screen).toContain('CULPEPER, VIRGINIA');
  expect(screen).not.toContain('PRICE');   // Price is 0; the page's fees have no public read
  for (const [n, name] of ['DESCRIPTION', 'RSVP COUNTS', 'SCHEDULE', 'WHO CAME'].entries()) expect(screen).toContain(`${n + 1}. ${name}`);
  expect(screen).toContain('0. CALENDAR');
  await widthOk(page);
  await page.locator('#orkple canvas').screenshot({ path: SCRATCH + 'events-detail.png' });
  // junk and an unlisted digit keep the prompt working
  await type(page, '9');
  await expect(log(page)).toContainText('?ILLEGAL QUANTITY ERROR');
  await type(page, 'zap');
  await expect(log(page)).toContainText('?SYNTAX ERROR');
  await type(page, '0');
  const s = await settled(page, /EVENTS OCT 2026/);
  expect(s.join('\n')).toContain('10/08 KINGDOM FALL CORONATION');
});

test('DESCRIPTION is decoded, wrapped and link-shortened; RSVP COUNTS are numbers only', async ({ page, request }) => {
  await toKfc(page);
  await type(page, '1');
  let s = await settled(page, /DESCRIPTION/);
  let t = s.join('\n');
  expect(t).toContain('ADDRESS');
  expect(t).toContain('10753 MOUNTAIN RUN LAKE RD');
  expect(t).toContain('PRICING:');
  expect(t).toContain('MULTI-DAY: $45');
  expect(t).toContain('SINGLE DAY: $25');
  expect(t).toContain('TICKETTAILOR.COM/...');   // a long link shrinks to its host
  expect(t).not.toMatch(/%[0-9A-F]{2}|HTTPS?:\/\//);
  await widthOk(page);
  await type(page, '0');
  await endsOn(page, EVENT_END);

  await type(page, '2');
  s = await settled(page, /RSVP COUNTS/);
  t = s.join('\n');
  const html = await (await request.get(`./index.php?Route=Event/detail/${KFC.eid}/${KFC.did}`)).text();
  const going = /<strong>(\d+)<\/strong> Going/.exec(html)![1], interested = /<strong>(\d+)<\/strong> Interested/.exec(html)![1];
  expect(t).toMatch(new RegExp('GOING +' + going + '\\b'));
  expect(t).toMatch(new RegExp('INTERESTED +' + interested + '\\b'));
  expect(t).toMatch(new RegExp('TOTAL +' + (+going + +interested) + '\\b'));
  expect(t).toContain('COUNTS ONLY.');
  expect(t).not.toMatch(/Event\/GetRsvpList/);
  await type(page, '0');
  await endsOn(page, EVENT_END);
});

test('WHO CAME matches the real attendance table, a persona opens the profile, 0 comes back', async ({ page, request }) => {
  const html = await (await request.get(`./index.php?Route=Event/detail/${KFC.eid}/${KFC.did}`)).text();
  const n = (html.match(/<tr data-att-id=/g) || []).length;
  expect(n).toBeGreaterThan(100);
  await toKfc(page);
  await type(page, '4');
  let s = await settled(page, /WHO CAME/);
  expect(s[0]).toMatch(new RegExp('^WHO CAME \\(' + n + '\\) +PAGE 1 OF ' + Math.ceil(n / 8) + '$'));
  expect(s.filter((l) => /^[1-8]\. /.test(l)).length).toBe(8);
  await widthOk(page);
  await page.locator('#orkple canvas').screenshot({ path: SCRATCH + 'events-who-came.png' });
  // every sign-in persona of the page is in ours, paging through
  const want = [...html.matchAll(/<tr data-att-id=[\s\S]*?<td><a href="[^"]*Player\/profile\/\d+">([^<]*)<\/a><\/td>/g)].map((m) => m[1].replace(/&amp;/g, '&').replace(/&#0?39;/g, "'").normalize('NFD').replace(/[\u0300-\u036f]/g, '').toUpperCase());
  const got: string[] = [];
  for (let k = 1; k < 40; k++) {
    s = await settled(page, /WHO CAME/, k);
    s.forEach((l) => { const m = /^[1-8]\. (.*?)(?: {2,}[A-Z ]+)?$/.exec(l); if (m) got.push(m[1].trim()); });
    if (!s.some((l) => /^9\. MORE/.test(l))) break;
    await type(page, '9');
  }
  expect(got.length).toBe(n);
  const gotUp = got.map((g) => g.replace(/[^A-Z0-9 ]/g, ''));
  const wantUp = want.map((w) => w.replace(/[^A-Z0-9 ]/g, '').slice(0, 20));
  wantUp.forEach((w) => expect(gotUp.some((g) => g.startsWith(w.slice(0, 12))), w).toBe(true));
  // pick a row on the last page: the profile opens and 0 returns to the same page
  const lastNo = Math.ceil(n / 8);
  await type(page, '1');
  await endsOn(page, /4\. TITLES\s+5\. ABOUT\s+6\. COMPANIES\s+7\. MILESTONES\s+0\. BACK\s*$/);
  await type(page, '0');
  await settled(page, /WHO CAME/, lastNo);
  await type(page, '0');
  await endsOn(page, EVENT_END);
});

test('SCHEDULE: day pages, times, place and leads match the real event page; an event with none says so', async ({ page, request }) => {
  const html = await (await request.get(`./index.php?Route=Event/detail/${TOURNEY.eid}/${TOURNEY.did}`)).text();
  const titles = [...html.matchAll(/id="ev-schedule-row-\d+"[^>]*data-title="([^"]*)"/g)].map((m) => m[1].toUpperCase());
  expect(titles.length).toBe(2);
  // KFC has no schedule
  await toKfc(page);
  await type(page, '3');
  const none = await settled(page, /SCHEDULE/);
  expect(none.join('\n')).toContain('NO SCHEDULE POSTED.');
  await type(page, '0');
  await endsOn(page, EVENT_END);
  await type(page, '0');
  await settled(page, CAL);
  await type(page, '0');
  await endsOn(page, /3\. PAST EVENTS\s+4\. COMPANIES AND HOUSEHOLDS\s+5\. KNIGHTS AND MASTERS\s+0\. KINGDOM LIST\s*$/);

  // the tournament, through the kingdom's PAST EVENTS
  await type(page, '3');
  await pickRow(page, /^(\d)\. 09\/26\/26 KINGDOM TOURNAMENT/);
  await endsOn(page, EVENT_END);
  await type(page, '3');
  const s = await settled(page, /SCHEDULE/);
  const t = s.join('\n');
  expect(s[0]).toMatch(/^SCHEDULE +PAGE 1 OF 1$/);
  expect(t).toContain('SAT 09/26/26');
  expect(t).toContain('10:00 AM - 11:00 AM');
  expect(t).toContain('11:00 AM - 3:00 PM');
  titles.forEach((x) => expect(t).toContain(x));
  expect(t).toContain('AT MAIN FIELD');
  expect(t).toContain('LEAD HANK THE TANK');
  await widthOk(page);
  await page.locator('#orkple canvas').screenshot({ path: SCRATCH + 'events-schedule.png' });
  await type(page, '0');
  await endsOn(page, EVENT_END);
  // no sign-ins: WHO CAME is empty, not an error
  await type(page, '4');
  const who = await settled(page, /WHO CAME \(0\)/);
  expect(who.join('\n')).toContain('NO ATTENDANCE ON RECORD.');
});

test('event detail parity with the real page: dates, place, description words, sign-in and schedule counts', async ({ page, request }) => {
  const html = await (await request.get(`./index.php?Route=Event/detail/${KFC.eid}/${KFC.did}`)).text();
  expect(html).toContain('Oct 8, 2026');
  expect(html).toContain('Oct 11, 2026');
  expect(html).toMatch(/Culpeper/);
  await toKfc(page);
  const t = (await log(page).textContent()) || '';
  expect(t).toContain('THU 10/08/26');
  expect(t).toContain('SUN 10/11/26');
  expect(t).toContain('CULPEPER, VIRGINIA');
  await type(page, '1');
  const d = (await settled(page, /DESCRIPTION/)).join('\n');
  // the page's description text, word for word where it is plain prose
  const desc = /class="ev-description[^"]*">([\s\S]*?)<\/div>/.exec(html)![1].replace(/<[^>]*>/g, ' ').replace(/&amp;/g, '&');
  for (const w of ['Pricing', 'Multi-day', 'Single day', 'Kids, 13 and under: Free', 'Event Schedule']) {
    expect(desc).toContain(w);
    expect(d).toContain(w.toUpperCase());
  }
});

// ---- PAST EVENTS ----

test('kingdom PAST EVENTS: newest first, only events that are over, sign-in count, a pick opens the event, 0 returns', async ({ page, request }) => {
  const rep = await (await request.get('../orkservice/Json/index.php?call=Report/EventAttendanceReport&request[KingdomId]=17')).json();
  const now = Date.now();
  const past = rep.Events.filter((e: any) => /^\d{4}-\d\d-\d\d/.test(e.EventStart) && !e.EventStart.startsWith('0000') && Date.parse((e.EventEnd && !e.EventEnd.startsWith('0000') ? e.EventEnd : e.EventStart).slice(0, 10)) < now - 86400000 * 1.5);
  expect(past.length).toBeGreaterThan(50);
  await toKcgProfile(page);
  await type(page, '3');
  let s = await settled(page, /^PAST EVENTS/);
  await widthOk(page);
  expect(s[0]).toMatch(/^PAST EVENTS +PAGE 1 OF \d+$/);
  expect(s.join('\n')).toContain('RIGHT: PLAYERS SIGNED IN');
  const first = s.find((l) => /^1\. /.test(l))!;
  const newest = past.slice().sort((a: any, b: any) => (a.EventStart < b.EventStart ? 1 : -1))[0];
  expect(first).toMatch(/^1\. \d\d\/\d\d\/\d\d /);
  expect(first.replace(/^1\. /, '').startsWith(newest.EventStart.slice(5, 7) + '/' + newest.EventStart.slice(8, 10) + '/' + newest.EventStart.slice(2, 4))).toBe(true);
  // the count of rows over all pages equals the report's past rows
  let total = 0, pages = 1;
  for (let n = 1; n < 80; n++) {
    s = await settled(page, /^PAST EVENTS/, n);
    pages = n;
    total += s.filter((l) => /^[1-8]\. /.test(l)).length;
    if (!s.some((l) => /^9\. MORE/.test(l))) break;
    await type(page, '9');
  }
  expect(Math.abs(total - past.length)).toBeLessThanOrEqual(2);   // the day the window turns over may differ by an event
  expect(pages).toBeGreaterThan(5);
  // the KFC row (10/08/26, 179 sign-ins)
  await type(page, '9');
  await pickRow(page, /^(\d)\. 10\/08\/26 KINGDOM FALL CORON.* 179$/);
  await endsOn(page, /4\. WHO CAME\s+0\. PAST EVENTS\s*$/);
  await type(page, '0');
  await settled(page, /^PAST EVENTS/);
  await type(page, '0');
  await endsOn(page, /3\. PAST EVENTS\s+4\. COMPANIES AND HOUSEHOLDS\s+5\. KNIGHTS AND MASTERS\s+0\. KINGDOM LIST\s*$/);
});

test('park EVENTS: a row opens EVENT DETAIL, 7 is PAST EVENTS of the park, 0 paths come back', async ({ page }) => {
  await toPark(page);
  await type(page, '5');
  let s = await settled(page, /^EVENTS/);
  expect(s.join('\n')).toContain('7. PAST EVENTS');
  const row = s.find((l) => /^\d\. \d\d\/\d\d\/\d\d AR WINTER CORONATION/.test(l))!;
  expect(row, 'AR Winter Coronation is listed').toBeTruthy();
  await type(page, row[0]);
  await endsOn(page, /ARMS OF AR WINTER CORONATION[\s\S]*4\. WHO CAME\s+0\. EVENT LIST\s*$/);
  await widthOk(page);
  await type(page, '0');
  await settled(page, /^EVENTS/);
  await type(page, '7');
  s = await settled(page, /^PAST EVENTS/);
  const t = s.join('\n');
  expect(t).toContain('4TH OF JULY COOKOUT');   // past events are here, not in the upcoming list
  expect(t).toMatch(/10\/31\/26 NAMELESS NIGHT CARNIVAL/);
  await widthOk(page);
  await type(page, '1');
  await endsOn(page, /4\. WHO CAME\s+0\. PAST EVENTS\s*$/);
  await type(page, '0');
  await settled(page, /^PAST EVENTS/);
  await type(page, '0');
  await settled(page, /^EVENTS/);
  await type(page, '0');
  await endsOn(page, /8\. SIGN-INS BY DAY\s+0\. PARK LIST\s*$/);
});

// ---- empty and failure states ----

test('empty states: no description, no counts, no sign-ins, no past events', async ({ page }) => {
  await toKfc(page);
  await page.route(/call=Event\/GetRsvpCounts/, (r) => r.fulfill({ contentType: 'application/json', body: '{"Going":0,"Interested":0,"Total":0,"Status":{"Status":0}}' }));
  await type(page, '2');
  let s = await settled(page, /RSVP COUNTS/);
  expect(s.join('\n')).toMatch(/GOING +0\s+INTERESTED +0\s+TOTAL +0/);
  await type(page, '0');
  await endsOn(page, EVENT_END);
  await page.route(/call=Report\/AttendanceForEvent/, (r) => r.fulfill({ contentType: 'application/json', body: '{"Status":{"Status":4,"Error":"You have set a parameter incorrectly.","Detail":null}}' }));
  await type(page, '4');
  s = await settled(page, /WHO CAME \(0\)/);
  expect(s.join('\n')).toContain('NO ATTENDANCE ON RECORD.');
  await type(page, '0');
  await endsOn(page, EVENT_END);
  await page.route(/EventEmbed\/schedule/, (r) => r.fulfill({ contentType: 'application/json', body: '{"ok":true,"days":[]}' }));
  await type(page, '3');
  s = await settled(page, /SCHEDULE/);
  expect(s.join('\n')).toContain('NO SCHEDULE POSTED.');
  await type(page, '0');
  await endsOn(page, EVENT_END);
  // no description and no place: the library says so
  const lines = await page.evaluate(() => (window as any).OrkpleTerminal.lib.eventDescLines({ detail: { Description: '', Address: '', City: '', Province: '', Url: '' }, atPark: null }));
  expect(lines).toEqual([]);
  // past events: nothing over
  await type(page, '0');
  await settled(page, CAL);
  await type(page, '0');
  await endsOn(page, /3\. PAST EVENTS\s+4\. COMPANIES AND HOUSEHOLDS\s+5\. KNIGHTS AND MASTERS\s+0\. KINGDOM LIST\s*$/);
  await page.route(/call=Report\/EventAttendanceReport/, (r) => r.fulfill({ contentType: 'application/json', body: '{"Status":{"Status":0},"Events":[{"EventId":1,"EventName":"FUTURE","DetailId":2,"EventStart":"2099-01-01 10:00:00","EventEnd":"2099-01-02 10:00:00","AttendanceCount":0},{"EventId":3,"EventName":"UNDATED","DetailId":4,"EventStart":"0000-00-00 00:00:00","EventEnd":"0000-00-00 00:00:00","AttendanceCount":0}]}' }));
  await type(page, '3');
  s = await settled(page, /^PAST EVENTS/);
  expect(s.join('\n')).toContain('NO PAST EVENTS ON RECORD.');
  expect(s.join('\n')).not.toMatch(/FUTURE|UNDATED/);
});

test('every event read that fails gives ?I/O ERROR, the menu still works, and a retry succeeds', async ({ page }) => {
  await toKfc(page);
  // a Status:false and a bare numeric Status on the counts are failures too
  for (const body of ['{"Status":false}', '{"Status":5}']) {
    await page.route(/call=Event\/GetRsvpCounts/, (route) => route.fulfill({ contentType: 'application/json', body }));
    await type(page, '2');
    await expect(log(page)).toContainText('?I/O ERROR', { timeout: 10000 });
    await page.unroute(/call=Event\/GetRsvpCounts/);
    await type(page, '1');
    await settled(page, /DESCRIPTION/);
    await type(page, '0');
    await endsOn(page, EVENT_END);
  }
  const fail: Array<[RegExp, number, RegExp]> = [
    [/call=Event\/GetRsvpCounts/, 2, /RSVP COUNTS/],
    [/call=Report\/AttendanceForEvent/, 4, /WHO CAME/],
  ];
  for (const [re, item, title] of fail) {
    await page.route(re, (route) => route.abort());
    await type(page, String(item));
    await endsOn(page, /READING DISK\.\.\.\s+\?I\/O ERROR\s*$/);
    await page.unroute(re);
    await page.waitForTimeout(200);
    await type(page, String(item));
    await settled(page, title);
    await type(page, '0');
    await endsOn(page, EVENT_END);
  }
  // the schedule: an HTTP 404 and a not-ok body are both failures
  for (const r of [{ status: 404, body: '{"ok":false,"error":"Event not found"}' }, { status: 200, body: '{"ok":false}' }]) {
    await page.route(/EventEmbed\/schedule/, (route) => route.fulfill({ status: r.status, contentType: 'application/json', body: r.body }));
    await type(page, '3');
    await endsOn(page, /READING DISK\.\.\.\s+\?I\/O ERROR\s*$/);
    await page.unroute(/EventEmbed\/schedule/);
    await type(page, '1');   // a good read resets the error count (three in a row redraw the screen)
    await settled(page, /DESCRIPTION/);
    await type(page, '0');
    await endsOn(page, EVENT_END);
  }
});

test('an event that is gone gives ?FILE NOT FOUND, a failed header read gives ?I/O ERROR', async ({ page }) => {
  await toCalendar(page);
  await gotoMonth(page, 'OCT', 2026);
  const gone = /call=SearchService\/CalendarDetail/;
  await page.route(gone, (route) => route.fulfill({ contentType: 'application/json', body: '{"Result":null,"Status":true,"Code":0}' }));
  await pickRow(page, /^(\d)\. 10\/08 KINGDOM FALL CORONATION/);
  await expect(log(page)).toContainText('?FILE NOT FOUND', { timeout: 10000 });
  await page.unroute(gone);
  await page.route(gone, (route) => route.abort());
  await type(page, '1');
  await expect(log(page)).toContainText('?I/O ERROR', { timeout: 10000 });
  await page.unroute(gone);
  // the host park read is optional: the event still opens without it
  const opt = [/call=Park\/GetParkDetails/];
  for (const r of opt) await page.route(r, (route) => route.abort());
  await page.waitForTimeout(200);
  await pickRow(page, /^(\d)\. 10\/08 KINGDOM FALL CORONATION/);
  await endsOn(page, EVENT_END);
  expect((await log(page).textContent()) || '').toContain('ARMS OF KINGDOM FALL CORONATION');
  for (const r of opt) await page.unroute(r);
});

// SearchService/Event leaves draft events out; the occurrence record does not. The event record is the publish check.
test('an unpublished (draft) event does not open: no event record gives ?FILE NOT FOUND, a failed read ?I/O ERROR', async ({ page }) => {
  await toCalendar(page);
  await gotoMonth(page, 'OCT', 2026);
  const rec = /call=SearchService\/Event(&|$)/;
  await page.route(rec, (route) => route.fulfill({ contentType: 'application/json', body: '{"Result":[],"Status":true,"Code":0}' }));
  await pickRow(page, /^(\d)\. 10\/08 KINGDOM FALL CORONATION/);
  await expect(log(page)).toContainText('?FILE NOT FOUND', { timeout: 10000 });
  await page.unroute(rec);
  await page.route(rec, (route) => route.abort());
  await page.waitForTimeout(200);
  await type(page, '1');
  await expect(log(page)).toContainText('?I/O ERROR', { timeout: 10000 });
  await page.unroute(rec);
  await page.waitForTimeout(200);
  await pickRow(page, /^(\d)\. 10\/08 KINGDOM FALL CORONATION/);
  await endsOn(page, EVENT_END);
});

test('PAST EVENTS lists a draft the report cannot tell from the rest, but it does not open (local draft 18688, Northern Lights)', async ({ page, request }) => {
  const rep = await (await request.get('../orkservice/Json/index.php?call=Report/EventAttendanceReport&request[KingdomId]=20')).json();
  const draft = (rep.Events || []).find((e: any) => e.EventId === 18688);
  const rec = await (await request.get('../orkservice/Json/index.php?call=SearchService/Event&event_id=18688')).json();
  const occ = await (await request.get('../orkservice/Json/index.php?call=SearchService/CalendarDetail&event_calendardetail_id=' + (draft ? draft.DetailId : 0))).json();
  test.skip(!draft || rec.Result.length !== 0, 'the local draft event 18688 is not in this database');
  // the hazard: the report carries no status, and the occurrence record answers for the draft with its details
  expect(draft.Status).toBeUndefined();
  expect(occ.Result && occ.Result.EventId).toBe(18688);
  await toMenu(page);
  await type(page, '1');
  await pickRow(page, /^(\d)\. KINGDOM OF NORTHERN LIGHTS/);
  await endsOn(page, /0\. KINGDOM LIST\s*$/);
  const menu = (await log(page).textContent()) || '';
  await type(page, /4\. PAST EVENTS/.test(menu) ? '4' : '3');
  await settled(page, /^PAST EVENTS/);
  await pickRow(page, /^(\d)\. 08\/25\/26 NL ONLINE CREDIT 2026/);
  await endsOn(page, /READING DISK\.\.\.\s+\?FILE NOT FOUND\s*$/);
  expect((await log(page).textContent()) || '').not.toContain('ARMS OF NL ONLINE CREDIT');
});

// ---- widths ----

test('nothing over 39 columns or 23 rows prints, even for very long names and real events', async ({ page, request }) => {
  await loadTerminal(page);
  const rep = await (await request.get('../orkservice/Json/index.php?call=Report/EventAttendanceReport&request[KingdomId]=17')).json();
  const real: any[] = [];
  for (const e of rep.Events.slice(0, 60)) {
    const d = await (await request.get(`../orkservice/Json/index.php?call=SearchService/CalendarDetail&event_calendardetail_id=${e.DetailId}`)).json();
    const sc = await (await request.get(`./index.php?Route=EventEmbed/schedule/${e.EventId}/${e.DetailId}`)).json().catch(() => null);
    if (d.Result) real.push({ name: e.EventName, detail: d.Result, days: sc && sc.days ? sc.days : [] });
  }
  const res = await page.evaluate((real) => {
    const L = (window as any).OrkpleTerminal.lib;
    const bad: string[] = [];
    const chk = (what: string, lines: string[], maxRows = 23) => {
      lines.forEach((l) => { if (l.replace(/\x01/g, '').length > 39) bad.push(what + ' wide: ' + l); });
      if (lines.length > maxRows) bad.push(what + ' tall: ' + lines.length);
    };
    const long = 'The Grand Exceedingly Long-Named Midreign, Coronation and Tournament of the Seven Highlands and Fenlands';
    const worst = {
      name: long, atPark: null,
      info: { ParkName: "The Barony of the Exceedingly Exhaustively Long-Named Marches", KingdomName: 'The Kingdom of Crystal Groves' },
      detail: { EventType: 'Tournament and Feast and Everything', EventStart: '2026-10-08 12:00:00', EventEnd: '2026-10-11 12:00:00', City: 'Llanfairpwllgwyngyllgogerychwyrndrobwllllantysiliogogogoch', Province: 'Northern Territories and Outlying Isles', Price: 1234.5, Description: '' },
    };
    chk('event header', L.eventParts(worst, 'PAST EVENTS').lines.concat(['SELECT: ']));
    chk('event header, bare', L.eventParts({ name: 'X', detail: { EventStart: '0000-00-00 00:00:00', EventEnd: '0000-00-00 00:00:00' } }, 'MAIN MENU').lines.concat(['SELECT: ']));
    const desc = L.eventDescLines({ atPark: null, detail: { Address: '1234567890 Extraordinarily Long Boulevard Name', City: 'Springfield', Province: 'Mississippi', Url: 'https://example.org/' + 'a'.repeat(120), Description: encodeURIComponent('<p>Take exit 12, then <b>turn left</b> &amp; follow ' + 'for-a-very-long-way '.repeat(60) + ' https://www.facebook.com/events/1396653305747444?acontext=%7B%22x%22%3A1%7D</p>') } });
    chk('description', L.pagerLines({ title: 'DESCRIPTION', items: desc, perPage: 16, back: 'BACK' }).lines.concat(['SELECT: ']));
    // a very full schedule day pages into screens of at most 16 lines, none too wide
    const items = Array.from({ length: 30 }, (_v, i) => ({ title: 'Long Workshop Title Number ' + i + ' With Many Many Words To Wrap', start: '2026-10-09 10:00:00', end: '2026-10-09 11:00:00', location: 'The Far Away Field Behind The Big Tent On The Left', leads: ['A Very Long Persona Name The Third', 'Another One'] }));
    const pages = L.schedPages([{ date: '2026-10-09', day_label: 'Friday', items }, { date: '', day_label: 'Time TBD', items: [] }]);
    pages.forEach((p: string, i: number) => chk('schedule page ' + i, L.pagerLines({ title: 'SCHEDULE', items: [p], perPage: 1, back: 'BACK' }).lines.concat(['SELECT: '])));
    if (pages.length < 3) bad.push('schedule did not page: ' + pages.length);
    // calendar rows
    const row = L.calRow({ type: 'park-event', title: 'ZZZ: ' + long, start: '2026-10-08 12:00:00', extendedProps: { eventId: 1, detailId: 2 } });
    chk('calendar row', ['1. ' + L.calRowText(row)]);
    chk('calendar item', L.calItemLines({ title: long, start: '2026-10-08 00:00:00', end: '2026-10-10 00:00:00', allDay: true, parkId: 0, desc: 'About '.repeat(200) }), 60);
    let seen = 0;
    for (const x of real) {
      seen++;
      chk('real event ' + x.name, L.eventParts({ name: x.name, detail: x.detail, info: null, atPark: null }, 'CALENDAR').lines.concat(['SELECT: ']));
      chk('real description ' + x.name, L.pagerLines({ title: 'DESCRIPTION', items: L.eventDescLines({ detail: x.detail, atPark: null }), perPage: 16, back: 'BACK' }).lines.concat(['SELECT: ']));
      L.schedPages(x.days).forEach((p: string) => chk('real schedule ' + x.name, L.pagerLines({ title: 'SCHEDULE', items: [p], perPage: 1, back: 'BACK' }).lines.concat(['SELECT: '])));
    }
    return { bad, seen };
  }, real);
  expect(res.seen).toBeGreaterThan(20);
  expect(res.bad).toEqual([]);
});

// ---- extras: AT FIELD NOW, NEXT UP, host.keys noRepeat ----

test('park AT FIELD NOW shows only when today is an in-person park day, by the page\'s own rules', async ({ page, request }) => {
  const head = await request.get('./index.php?Route=Search');
  // the app runs on America/Chicago time (config.dist.php): its "today" is that date
  const ymd = new Date(head.headers()['date']).toLocaleDateString('en-CA', { timeZone: 'America/Chicago' });
  const dow = new Date(ymd + 'T00:00:00Z').getUTCDay();
  const wd = ['SUNDAY', 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY'][dow];
  const other = ['SUNDAY', 'MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY'][(dow + 3) % 7];
  const day = (name: string, extra: any = {}) => ({ ParkDayId: 1, ParkId: PARK, Recurrence: 'weekly', WeekOfMonth: 0, WeekDay: name, MonthDay: 0, StartDate: '1000-01-01', WeekInterval: 0, Time: '12:00:00', Purpose: 'park-day', Description: '', Online: 0, ...extra });
  const open = async (days: any[], field: number) => {
    await page.route(/call=Park\/GetParkDays/, (r) => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ Status: { Status: 0 }, ParkDays: days }) }));
    await page.route(/call=Report\/GetParkFieldCountToday/, (r) => r.fulfill({ contentType: 'application/json', body: JSON.stringify({ Status: { Status: 0 }, Count: field }) }));
    await page.goto('./index.php?Route=Search');
    await toPark(page);
    const t = (await log(page).textContent()) || '';
    await page.unroute(/call=Park\/GetParkDays/);
    await page.unroute(/call=Report\/GetParkFieldCountToday/);
    return t.slice(t.lastIndexOf('ARMS OF'));
  };
  expect(await open([day(wd)], 7)).toContain('AT FIELD NOW 7');
  expect(await open([day(other)], 7)).not.toContain('AT FIELD NOW');
  expect(await open([day(wd, { Online: 1 })], 7)).not.toContain('AT FIELD NOW');   // an online day does not count
  expect(await open([day(wd)], 0)).not.toContain('AT FIELD NOW');                    // nobody yet: the page hides it too
  // the rule for every recurrence, against fixed dates (2026-11-02 is a Monday)
  const r = await page.evaluate(() => {
    const m = (window as any).OrkpleTerminal.lib.parkMeetsOn;
    const d = (x: any) => Object.assign({ Online: 0, StartDate: '1000-01-01', WeekInterval: 0, WeekOfMonth: 0, MonthDay: 0 }, x);
    return [
      m([d({ Recurrence: 'weekly', WeekDay: 'Monday' })], '2026-11-02'),
      m([d({ Recurrence: 'weekly', WeekDay: 'Monday' })], '2026-11-03'),
      m([d({ Recurrence: 'week-of-month', WeekDay: 'Monday', WeekOfMonth: 1 })], '2026-11-02'),
      m([d({ Recurrence: 'week-of-month', WeekDay: 'Monday', WeekOfMonth: 2 })], '2026-11-02'),
      m([d({ Recurrence: 'week-of-month', WeekDay: 'Monday', WeekOfMonth: 2 })], '2026-11-09'),
      m([d({ Recurrence: 'every-x-weeks', WeekDay: 'Monday', WeekInterval: 2, StartDate: '2026-10-19' })], '2026-11-02'),
      m([d({ Recurrence: 'every-x-weeks', WeekDay: 'Monday', WeekInterval: 2, StartDate: '2026-10-19' })], '2026-11-09'),
      m([d({ Recurrence: 'every-x-weeks', WeekDay: 'Monday', WeekInterval: 2, StartDate: '2026-11-16' })], '2026-11-02'),
      m([d({ Recurrence: 'monthly', MonthDay: 2 })], '2026-11-02'),
      m([d({ Recurrence: 'monthly', MonthDay: 3 })], '2026-11-02'),
      m([d({ Recurrence: 'weekly', WeekDay: 'Monday', Online: 1 })], '2026-11-02'),
      m([], '2026-11-02'), m(null, '2026-11-02'),
    ];
  });
  expect(r).toEqual([true, false, true, false, true, true, false, false, true, false, false, false, false]);
});

test('AT FIELD NOW follows the page: every KCG park\'s header agrees with its own page on whether today is a park day', async ({ page, request }) => {
  await loadTerminal(page);
  const head = await request.get('./index.php?Route=Search');
  const today = new Date(head.headers()['date']).toLocaleDateString('en-CA', { timeZone: 'America/Chicago' });
  // The page uses each park's own timezone and the terminal uses server time, so for the hour between
  // midnight Eastern and midnight Central they name different days. Nobody is on the field then.
  const eastern = new Date(head.headers()['date']).toLocaleDateString('en-CA', { timeZone: 'America/New_York' });
  test.skip(today !== eastern, 'Eastern and Central are on different dates right now');
  const parks = (await (await request.get('../orkservice/Json/index.php?call=Report/GetKingdomParkAverages&request[KingdomId]=17')).json()).KingdomParkAveragesSummary;
  expect(parks.length).toBeGreaterThan(5);
  const disagree: string[] = [];
  for (const p of parks) {
    const days = (await (await request.get(`../orkservice/Json/index.php?call=Park/GetParkDays&request[ParkId]=${p.ParkId}`)).json()).ParkDays;
    const html = await (await request.get(`./index.php?Route=Park/profile/${p.ParkId}`)).text();
    const pageSays = /pk-stat-label">Park Day Today</.test(html);
    const ours = await page.evaluate(([d, t]) => (window as any).OrkpleTerminal.lib.parkMeetsOn(d, t), [days, today] as [any, string]);
    if (pageSays !== ours) disagree.push(`${p.ParkName}: page ${pageSays}, ours ${ours}`);
  }
  expect(disagree).toEqual([]);
});

test('MY AMTGARD: NEXT UP shows when the RSVPs read works and is left out, not (UNAVAILABLE), when it is refused', async ({ page }) => {
  const login = await page.request.post('./index.php?Route=Login/login', { form: { username: 'heraldsbridge', password: 'x' } });
  expect(login.ok()).toBe(true);
  const myAmtgard = async () => {
    await page.goto('./index.php?Route=Search');
    await toMenu(page);
    await type(page, '3');
    await endsOn(page, /4\. TITLES\s+5\. MY AMTGARD\s+6\. ABOUT\s+7\. COMPANIES\s+8\. MILESTONES\s+0\. MAIN MENU\s*$/);
    await type(page, '5');
    await endsOn(page, /FULL DASHBOARD: THE MODERN ORK\s+0\. BACK\s*$/);
    const t = (await log(page).textContent()) || '';
    return t.slice(t.lastIndexOf('MY AMTGARD\n'));
  };
  const re = /call=Event\/GetUpcomingRsvps/;
  // refused, as the pending API guard does for an anonymous call (status 5)
  await page.route(re, (r) => r.fulfill({ contentType: 'application/json', body: '{"Status":{"Status":5,"Error":"Not authorized","Detail":null}}' }));
  let t = await myAmtgard();
  expect(t).not.toContain('NEXT UP');
  expect(t).not.toContain('(UNAVAILABLE)');
  expect(t).toContain('RECENT CLASSES');
  expect(t).toContain('LATEST AWARD');
  await page.unroute(re);
  // empty list: the block shows NONE
  await page.route(re, (r) => r.fulfill({ contentType: 'application/json', body: '{"Status":{"Status":0},"UpcomingRsvps":[]}' }));
  t = await myAmtgard();
  expect(t).toMatch(/NEXT UP\s+NONE/);
  await page.unroute(re);
  // one RSVP
  await page.route(re, (r) => r.fulfill({ contentType: 'application/json', body: '{"Status":{"Status":0},"UpcomingRsvps":[{"EventName":"Test Fair","EventStart":"2027-03-04 10:00:00"}]}' }));
  t = await myAmtgard();
  expect(t).toMatch(/NEXT UP\s+03\/04\/27 TEST FAIR/);
  await page.unroute(re);
});

test('host.keys(fn, { noRepeat: true }) drops auto-repeat keydowns; the default keeps them', async ({ page }) => {
  // two fake games under the ids of two real ones: a registered object is used before any file is fetched
  await page.addInitScript(() => {
    const w = window as any;
    w.fakeKeys = { on: [] as string[], off: [] as string[] };
    w.OrkpleGames = {
      froggork: { start(host: any) { host.cls(); host.keys((k: string) => { if (k === 'Q') host.quit(); else w.fakeKeys.on.push(k); }, { noRepeat: true }); } },
      pacmork: { start(host: any) { host.cls(); host.keys((k: string) => { if (k === 'Q') host.quit(); else w.fakeKeys.off.push(k); }); } },
    };
  });
  await page.goto('./index.php?Route=Search');
  await toMenu(page);
  await type(page, '4');
  await endsOn(page, /7\. FROGGORK[\s\S]*0\. MAIN MENU\s*$/);
  const hold = async () => {
    await page.keyboard.down('A');
    await page.keyboard.down('A');   // Playwright sends these as auto-repeat keydowns (repeat: true)
    await page.keyboard.down('A');
    await page.keyboard.up('A');
    await page.keyboard.press('B');
  };
  await type(page, '7');   // noRepeat
  await page.waitForTimeout(4400);   // the 4 s ORKEK title card comes before every game
  await hold();
  expect(await page.evaluate(() => (window as any).fakeKeys.on)).toEqual(['A', 'B']);
  await page.keyboard.press('Q');
  await endsOn(page, /0\. MAIN MENU\s*$/);
  await type(page, '4');   // default: repeats pass through
  await page.waitForTimeout(4400);
  await hold();
  expect(await page.evaluate(() => (window as any).fakeKeys.off)).toEqual(['A', 'A', 'A', 'B']);
});
