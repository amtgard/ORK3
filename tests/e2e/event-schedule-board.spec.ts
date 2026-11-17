/**
 * Event Schedule Board: the all-days board on the event page's Schedule tab.
 *
 * Viewer tests: set ORK3_E2E_SCHEDULE_EVENT to "eventId/detailId" of a published
 * event with a schedule; they run signed out and skip without it.
 *
 * Editor tests: set ORK3_E2E_USERNAME, ORK3_E2E_PASSWORD and ORK3_E2E_EDIT_EVENT
 * ("eventId/detailId" of an event that account can manage, running Friday to
 * Sunday); they skip without them.
 *
 * ORK3_E2E_REQUIRE=1 turns those set-up skips into failures, so a run that
 * tested nothing cannot report green.
 *
 * Editor tests clean up by identity: every item they create has a title that
 * starts with this run's prefix ('E2E <run time>'), and only those are removed.
 */
import { test, expect, type Page, type TestInfo } from '@playwright/test';

const EVENT = process.env.ORK3_E2E_SCHEDULE_EVENT ?? '';
const REQUIRE = process.env.ORK3_E2E_REQUIRE === '1';

// Skips a test whose set-up is missing; with ORK3_E2E_REQUIRE=1 that is a failure.
function needSetup(testInfo: TestInfo, ok: boolean, what: string) {
  if (ok) return;
  if (REQUIRE) throw new Error(`${what}, and ORK3_E2E_REQUIRE=1 is set`);
  testInfo.skip(true, what);
}

// ---- Dates and the server's copy (shared by every group) ----

const WEEKDAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as const;
type Weekday = typeof WEEKDAYS[number];

// A calendar day (YYYY-MM-DD) moved by n days.
function addDays(day: string, n: number): string {
  const [y, m, d] = day.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d + n)).toISOString().slice(0, 10);
}

// The event's day (YYYY-MM-DD) that falls on this weekday, read from the page's
// EvConfig.eventStart and eventEnd. The typed lines say 'fri', 'sat' and 'sun', so
// the expected dates follow the same weekdays.
async function dayOf(page: Page, weekday: Weekday): Promise<string> {
  const [start, end] = await page.evaluate(() => {
    const c = (window as any).EvConfig;
    return [String(c.eventStart || '').slice(0, 10), String(c.eventEnd || '').slice(0, 10)];
  });
  for (let d = start, i = 0; d && d <= end && i < 31; d = addDays(d, 1), i++) {
    if (WEEKDAYS[new Date(`${d}T00:00:00Z`).getUTCDay()] === weekday) return d;
  }
  throw new Error(`the event (${start} to ${end}) has no ${weekday}`);
}

// Every schedule row as the server has it, read through schedule_list (never the board's store).
async function serverRows(page: Page): Promise<any[]> {
  return page.evaluate(async () => {
    const c = (window as any).EvConfig;
    const res = await fetch(c.uir + 'EventAjax/schedule_list/' + c.eventId + '/' + c.detailId, {
      credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
    const list = await res.json();
    if (list.status !== 0) throw new Error('schedule_list: ' + JSON.stringify(list));
    return list.schedule || [];
  });
}

// What these tests check on a row: times (to the minute), category and leads.
type Shape = { StartTime: string; EndTime: string; Category: string; Leads: [string, boolean][] };
function shapeOf(row: any): Shape | null {
  if (!row) return null;
  return {
    StartTime: String(row.StartTime).slice(0, 16), EndTime: String(row.EndTime).slice(0, 16),
    Category: String(row.Category || ''),
    Leads: (row.Leads || []).map((l: any) => [String(l.Persona), !!l.Auto] as [string, boolean]),
  };
}
const byStart = (a: Shape, b: Shape) => (a.StartTime + a.EndTime).localeCompare(b.StartTime + b.EndTime);

// The server rows with this title (or, with prefix, every title starting with it), shaped and in time order.
async function serverItems(page: Page, title: string, opts: { prefix?: boolean } = {}): Promise<Shape[]> {
  const match = (t: string) => opts.prefix ? t.startsWith(title) : t === title;
  return (await serverRows(page)).filter(r => match(String(r.Title))).map(r => shapeOf(r)!).sort(byStart);
}
// The one server row with this title, shaped; null when there is none.
async function serverItem(page: Page, title: string): Promise<Shape | null> {
  const rows = await serverItems(page, title);
  if (rows.length > 1) throw new Error(`${rows.length} server rows are titled "${title}"`);
  return rows[0] || null;
}
// The board's own copies, shaped the same way, to compare with the server's.
async function clientItems(page: Page, title: string, opts: { prefix?: boolean } = {}): Promise<Shape[]> {
  const rows = await page.evaluate(([t, p]) => (window as any).EvBoardEdit.store.items()
    .filter((i: any) => p ? String(i.Title).startsWith(t as string) : i.Title === t), [title, !!opts.prefix] as [string, boolean]);
  return rows.map((r: any) => shapeOf(r)!).sort(byStart);
}

// Removes exactly these schedule ids through the batch endpoint; resolves to what is left unremoved.
async function removeIds(page: Page, ids: number[]): Promise<number> {
  if (!ids.length) return 0;
  return page.evaluate(async list => {
    const c = (window as any).EvConfig;
    const fd = new FormData();
    fd.append('Ops', JSON.stringify(list.map(id => ({ op: 'remove', id }))));
    const res = await (await fetch(c.uir + 'EventAjax/schedule_batch/' + c.eventId + '/' + c.detailId, {
      method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })).json();
    return res.status === 0 ? 0 : list.length;
  }, ids);
}

// One form field of a multipart request body (the board posts FormData).
function formField(body: string, name: string): string | null {
  const m = new RegExp(`name="${name}"\\r\\n\\r\\n([\\s\\S]*?)\\r\\n--`).exec(body);
  return m ? m[1] : null;
}

test.describe('viewer', () => {
  test.beforeEach(async ({}, testInfo) => {
    needSetup(testInfo, !!EVENT, 'ORK3_E2E_SCHEDULE_EVENT is not set');
  });

  test('the board renders the schedule for a signed-out viewer', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(String(e)));
    await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=board`);
    await expect(page.locator('#evb-root')).toBeVisible();
    const listCount = await page.locator('#ev-schedule-container tr[data-schedule-id]').evaluateAll(
      rows => new Set(rows.map(r => r.getAttribute('data-schedule-id'))).size);
    const boardCount = await page.locator('.evb-block').evaluateAll(
      els => new Set(els.map(e => e.getAttribute('data-id'))).size);
    expect(boardCount).toBe(listCount);
    expect(await page.locator('.evb-day').count()).toBeGreaterThan(0);
    await page.locator('[data-ev-view="list"]').click();
    await expect(page.locator('#evb-root')).toBeHidden();
    await expect(page.locator('#ev-schedule-container')).toBeVisible();
    expect(errors).toEqual([]);
  });

  test('old grid links open the board', async ({ page }) => {
    await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=grid`);
    await expect(page.locator('#evb-root')).toBeVisible();
  });

  test('clicking a block shows its details; the legend dims other categories', async ({ page }) => {
    await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=board`);
    const first = page.locator('.evb-block').first();
    const title = (await first.locator('.evb-block-title').innerText()).trim();
    await first.click();
    await expect(page.locator('.evb-popover')).toContainText(title);
    await page.keyboard.press('Escape');
    await expect(page.locator('.evb-popover')).toHaveCount(0);
    const chip = page.locator('.evb-legend-chip').first();
    const cat = await chip.getAttribute('data-category');
    await chip.click();
    // A block stays lit when the chosen category is its primary or its secondary one.
    expect(await page.locator(
      `.evb-block:not([data-category="${cat}"]):not([data-secondary-category="${cat}"]):not(.evb-dim)`).count()).toBe(0);
  });

  test('phone width shows one day and does not scroll the page sideways', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 800 });
    await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=board`);
    await expect(page.locator('.evb-day:visible')).toHaveCount(1);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
  });

  test('dark theme keeps block text white on its fill', async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('ork_theme', 'dark'));
    await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=board`);
    const color = await page.locator('.evb-block-title').first().evaluate(e => getComputedStyle(e).color);
    expect(color).toBe('rgb(255, 255, 255)');
  });

  test('share as image downloads a PNG of the board', async ({ page }) => {
    await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=board`);
    await page.locator('.evb-share-btn').click();
    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.locator('.evb-share-menu [data-evb-share="download-all"]').click(),
    ]);
    expect(download.suggestedFilename()).toMatch(/-schedule\.png$/);
    const dims = await page.evaluate(() => {
      const c = (window as any).EvBoardImage.render((window as any).EvBoardView.board,
        { title: 'T', subtitle: 'S', url: 'u', categories: (window as any).EvConfig.scheduleCategories }, { scale: 2, day: null });
      return [c.width, c.height];
    });
    expect(dims[0]).toBeGreaterThan(800);
    expect(dims[1]).toBeGreaterThan(400);
  });

  test('a very wide board image stays inside canvas limits', async ({ page }) => {
    await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=board`);
    const dims = await page.evaluate(() => {
      const board = { axisStart: 540, axisEnd: 1080, days: [{ day: '2026-11-21', label: 'Saturday', segs: [], lanes: 300 }] };
      const c = (window as any).EvBoardImage.render(board, { title: 'T', subtitle: '', url: '', categories: [] }, { scale: 2, day: null });
      return [c.width, c.height];
    });
    expect(dims[0]).toBeLessThanOrEqual(16384);
    expect(dims[0] * dims[1]).toBeLessThanOrEqual(100000000);
    expect(dims[0]).toBeGreaterThan(16000);
  });

  test('share as image says so when the browser cannot make the image', async ({ page }) => {
    await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=board`);
    await page.evaluate(() => {
      HTMLCanvasElement.prototype.toBlob = function (cb: BlobCallback) { setTimeout(() => cb(null), 0); };
    });
    await page.locator('.evb-share-btn').click();
    await page.locator('.evb-share-menu [data-evb-share="download-all"]').click();
    await expect(page.locator('.evb-share-btn')).toContainText('Image too large');
    await expect(page.locator('.evb-share-btn')).toContainText('Share as image', { timeout: 4000 });
  });

  test('the share button is a disclosure that closes when focus moves past it', async ({ page }) => {
    await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=board`);
    const btn = page.locator('.evb-share-btn');
    await expect(btn).not.toHaveAttribute('aria-haspopup', /.*/);
    await expect(btn).toHaveAttribute('aria-controls', 'evb-share-menu');
    await expect(page.locator('#evb-share-menu')).toHaveCount(1);
    await btn.click();
    await expect(btn).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('#evb-share-menu')).toBeVisible();
    for (let i = 0; i < 6; i++) {
      await page.keyboard.press('Tab');
      if (!(await page.evaluate(() => !!document.activeElement?.closest('.evb-share')))) break;
    }
    await expect(page.locator('#evb-share-menu')).toBeHidden();
    await expect(btn).toHaveAttribute('aria-expanded', 'false');
  });

  test('Copy link uses a data-tip, not a title', async ({ page }) => {
    await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=board`);
    const link = page.locator('.ev-sched-copy-btn:not(.evb-share-btn)');
    await expect(link).toHaveAttribute('data-tip', /Copy a link/);
    await expect(link).not.toHaveAttribute('title', /.*/);
  });

  test('Tab out of a keyboard-opened popover closes it and returns to the block', async ({ page }) => {
    await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=board`);
    const block = page.locator('.evb-block').first();
    await block.focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('.evb-popover')).toHaveCount(1);
    for (let i = 0; i < 10; i++) {
      await page.keyboard.press('Tab');
      if (await page.locator('.evb-popover').count() === 0) break;
    }
    await expect(page.locator('.evb-popover')).toHaveCount(0);
    expect(await block.evaluate(b => document.activeElement === b)).toBe(true);

    // Shift+Tab from the first control does the same.
    await page.keyboard.press('Enter');
    await expect(page.locator('.evb-popover')).toHaveCount(1);
    await page.keyboard.press('Tab');
    await page.keyboard.press('Shift+Tab');
    await expect(page.locator('.evb-popover')).toHaveCount(0);
    expect(await block.evaluate(b => document.activeElement === b)).toBe(true);
  });

  test('a filter on a category that leaves the board is cleared', async ({ page }) => {
    await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=board`);
    const cat = await page.locator('.evb-legend-chip').first().getAttribute('data-category');
    await page.locator('.evb-legend-chip').first().click();
    await expect(page.locator('.evb-legend-chip[aria-pressed="true"]')).toHaveCount(1);
    await page.evaluate(c => {
      const V = (window as any).EvBoardView;
      V.setItems(V.getItems().filter((it: any) => it.Category !== c && it.SecondaryCategory !== c));
    }, cat);
    await expect(page.locator('.evb-legend-chip[aria-pressed="true"]')).toHaveCount(0);
    await expect(page.locator('.evb-block.evb-dim')).toHaveCount(0);
  });

  test('a stale board refresh does not overwrite a newer one', async ({ page }) => {
    await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=board`);
    const titles = await page.evaluate(async () => {
      const w = window as any;
      const pageFor = (title: string) => '<script type="application/json" id="evb-schedule-data">' + JSON.stringify([
        { EventScheduleId: 1, Title: title, Category: 'Other', StartTime: '2026-11-21 10:00:00', EndTime: '2026-11-21 11:00:00', Leads: [] }
      ]) + '</script>';
      const pending: Array<(v: any) => void> = [];
      w.fetch = () => new Promise(res => pending.push(res));
      w.evRefreshScheduleGrid();
      w.evRefreshScheduleGrid();
      // The newer answer arrives first, then the older one.
      pending[1]({ text: () => Promise.resolve(pageFor('Newer')) });
      await new Promise(r => setTimeout(r, 50));
      pending[0]({ text: () => Promise.resolve(pageFor('Older')) });
      await new Promise(r => setTimeout(r, 50));
      return w.EvBoardView.getItems().map((it: any) => it.Title);
    });
    expect(titles).toEqual(['Newer']);
  });

  test('the now line is redrawn when the tab becomes visible', async ({ page }) => {
    await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=board`);
    const drawn = await page.evaluate(() => {
      const w = window as any, b = w.EvBoardView.board;
      // A calendar time on the first day, half an hour into the axis (the axis starts before midnight).
      const day = b.days[0].day, min = b.axisStart + 30;
      const at = day + ' ' + String(Math.floor(min / 60)).padStart(2, '0') + ':' + String(min % 60).padStart(2, '0');
      w.EvBoardView.setClock(() => at);
      document.querySelectorAll('.evb-now').forEach(e => e.remove());
      document.dispatchEvent(new Event('visibilitychange'));
      return !!document.querySelector(`.evb-day-body[data-day="${day}"] .evb-now`);
    });
    expect(drawn).toBe(true);
  });

  test('the legend filter takes other blocks out of reach and says what it shows', async ({ page }) => {
    await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=board`);
    const chips = page.locator('.evb-legend-chip');
    test.skip(await chips.count() < 2, 'needs two legend chips');
    const cat = (await chips.first().getAttribute('data-category'))!;
    // Items, not segments: an item running past its board day draws two blocks.
    const shown = await page.evaluate(c => new Set(Array.from(document.querySelectorAll('.evb-block[role="button"]'))
      .filter(b => b.getAttribute('data-category') === c || b.getAttribute('data-secondary-category') === c)
      .map(b => b.getAttribute('data-id'))).size, cat);
    const status = page.locator('.evb-sr[role="status"]');
    const others = page.locator(`.evb-block[role="button"]:not([data-category="${cat}"]):not([data-secondary-category="${cat}"])`);
    const matching = page.locator(`.evb-block[role="button"][data-category="${cat}"], .evb-block[role="button"][data-secondary-category="${cat}"]`);
    expect(await others.count()).toBeGreaterThan(0);

    await chips.first().click();
    await expect(status).toHaveText(`Showing ${shown} ${cat} item${shown === 1 ? '' : 's'}`);
    const hidden = await others.evaluateAll(els => els.map(e => [e.getAttribute('aria-hidden'), e.getAttribute('tabindex')]));
    expect(hidden.every(([a, t]) => a === 'true' && t === '-1')).toBe(true);
    expect(await matching.evaluateAll(els => els.every(e => !e.hasAttribute('aria-hidden') && e.getAttribute('tabindex') === '0'))).toBe(true);

    // Clearing the filter gives every block back.
    await chips.first().click();
    await expect(status).toHaveText('Showing all items');
    expect(await page.locator('.evb-block[role="button"]').evaluateAll(
      els => els.every(e => !e.hasAttribute('aria-hidden') && e.getAttribute('tabindex') === '0'))).toBe(true);
  });

  test('dark theme: a pressed legend chip stands out and swatches have a ring', async ({ page }) => {
    await page.addInitScript(() => localStorage.setItem('ork_theme', 'dark'));
    await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=board`);
    const chips = page.locator('.evb-legend-chip');
    test.skip(await chips.count() < 2, 'needs two legend chips');
    await chips.first().click();
    const [on, off, ring] = await page.evaluate(() => {
      const c = document.querySelectorAll('.evb-legend-chip');
      return [getComputedStyle(c[0]).backgroundColor, getComputedStyle(c[1]).backgroundColor,
        getComputedStyle(c[1].querySelector('.evb-legend-swatch')!).boxShadow];
    });
    expect(on).not.toBe(off);
    expect(ring).not.toBe('none');
  });
});

// ---- Signed-in helpers (later phases reuse these) ----

async function signIn(page: Page) {
  await page.goto('index.php?Route=Login');
  await page.fill('input[name="username"]', process.env.ORK3_E2E_USERNAME ?? '');
  await page.fill('input[name="password"]', process.env.ORK3_E2E_PASSWORD ?? '');
  await Promise.all([
    page.waitForURL(/Player\/profile\/\d+/),
    page.click('#lg-submit-btn'),
  ]);
}

async function openBoard(page: Page, event: string) {
  await page.goto(`index.php?Route=Event/detail/${event}#ev-tab-schedule?view=board`);
  // A signed-in account sees "What's New" until it dismisses the current release.
  const whatsNew = page.locator('#wn-overlay.wn-open .wn-dismiss');
  if (await whatsNew.isVisible()) await whatsNew.click();
  await expect(page.locator('#evb-root')).toBeVisible();
}

// Removes one list item, by its schedule id, through the list's own remove button
// and its themed confirm dialog.
async function removeListItem(page: Page, id: number) {
  await page.locator('[data-ev-view="list"]').click();
  const row = page.locator(`#ev-schedule-container tr[id="ev-schedule-row-${id}"]`);
  await expect(row).toHaveCount(1);
  await row.locator('.ev-del-link').click();
  await page.locator('#ork-confirm-overlay.ork-confirm-open #ork-confirm-ok').click();
  await expect(page.locator(`#ev-schedule-container tr[data-schedule-id="${id}"]`)).toHaveCount(0);
}

test.describe('editor paths (phase 1)', () => {
  const EDIT_EVENT = process.env.ORK3_E2E_EDIT_EVENT ?? '';
  const PREFIX = 'E2E ';
  const ready = !!(process.env.ORK3_E2E_USERNAME && process.env.ORK3_E2E_PASSWORD && EDIT_EVENT);
  // Ids this group added and has not removed yet.
  const created = new Set<number>();

  test.beforeEach(async ({}, testInfo) => {
    needSetup(testInfo, ready, 'ORK3_E2E_USERNAME, ORK3_E2E_PASSWORD and ORK3_E2E_EDIT_EVENT are not all set');
  });

  test.afterAll(async ({ browser }) => {
    if (!ready || !created.size) return;
    const page = await browser.newPage();
    await signIn(page);
    await openBoard(page, EDIT_EVENT);
    const left = await removeIds(page, [...created]);
    await page.close();
    expect(left).toBe(0);
  });

  test('the board follows the edit form: add, open from the popover, delete', async ({ page }) => {
    const title = `${PREFIX}${Date.now()} modal add`;
    await signIn(page);
    await openBoard(page, EDIT_EVENT);

    await page.getByRole('button', { name: 'Add Schedule Item' }).click();
    await expect(page.locator('#ev-schedule-modal')).toBeVisible();
    await page.fill('#ev-sched-title', title);
    // The event's own first day, so the test works on any edit event.
    await page.evaluate(() => {
      const day = String((window as any).EvConfig.eventStart).slice(0, 10);
      (document.getElementById('ev-sched-start') as any)._flatpickr.setDate(`${day} 10:00`, true);
      (document.getElementById('ev-sched-end') as any)._flatpickr.setDate(`${day} 11:00`, true);
    });
    await page.click('#ev-sched-save-btn');

    const block = page.locator('.evb-block', { hasText: title });
    await expect(block).toHaveCount(1);
    const id = Number(await block.getAttribute('data-id'));
    expect(id).toBeGreaterThan(0);
    created.add(id);
    await block.click();
    await expect(page.locator('.evb-popover')).toContainText(title);
    await expect(page.locator('.evb-popover .evb-popover-edit')).toHaveCount(1);
    await page.locator('.evb-popover .evb-popover-edit').click();
    await expect(page.locator('#ev-schedule-modal')).toBeVisible();
    await expect(page.locator('#ev-sched-title')).toHaveValue(title);
    await page.locator('#ev-schedule-modal .ev-modal-close').click();
    await expect(page.locator('#ev-schedule-modal')).toBeHidden();

    await removeListItem(page, id);
    created.delete(id);
    await page.locator('[data-ev-view="board"]').click();
    await expect(page.locator('.evb-block', { hasText: title })).toHaveCount(0);
    await expect.poll(() => serverItem(page, title)).toBeNull();
  });
});

test.describe('editing', () => {
  const EDIT_EVENT = process.env.ORK3_E2E_EDIT_EVENT ?? '';
  const PREFIX = `E2E ${Date.now()}`;
  const ready = !!(process.env.ORK3_E2E_USERNAME && process.env.ORK3_E2E_PASSWORD && EDIT_EVENT);

  test.beforeEach(async ({ page }, testInfo) => {
    needSetup(testInfo, ready, 'ORK3_E2E_USERNAME, ORK3_E2E_PASSWORD and ORK3_E2E_EDIT_EVENT are not all set');
    await signIn(page);
  });

  // Adds an item from the quick line (Edit mode on) and waits for it to save.
  async function addViaQuickLine(page: Page, text: string) {
    await quickAdd(page, text);
  }
  // The board store's [StartTime, EndTime] for an item, seconds trimmed (the client's view only).
  async function timesOf(page: Page, title: string): Promise<string[]> {
    return page.evaluate(t => {
      const it = (window as any).EvBoardEdit.store.items().find((i: any) => i.Title === t);
      return it ? [it.StartTime, it.EndTime].map((v: string) => String(v).slice(0, 16)) : [];
    }, title);
  }
  // Once the board says everything is saved, the server's row has these times,
  // category and leads ("All changes saved" only means the server answered).
  async function expectOnServer(page: Page, title: string, want: Shape) {
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    await expect.poll(() => serverItem(page, title)).toEqual(want);
  }
  // Once saved, the server's rows with this title (or prefix) match the board's own copies.
  async function expectServerMatchesBoard(page: Page, title: string, opts: { prefix?: boolean; count: number }) {
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    const board = await clientItems(page, title, opts);
    expect(board).toHaveLength(opts.count);
    await expect.poll(() => serverItems(page, title, opts)).toEqual(board);
    return board;
  }

  // The auto leads a new item of this category gets, read from the page's
  // EvConfig.staffList (after Edit mode is on, it is the server's list): everyone
  // whose LeadCategories include it, as [Persona, true], by name. Nobody means no
  // leads. So a test does not depend on an earlier one having made a lead.
  async function autoLeadsFor(page: Page, category: string): Promise<[string, boolean][]> {
    return page.evaluate(cat => ((window as any).EvConfig.staffList || [])
      .filter((s: any) => (s.LeadCategories || []).includes(cat))
      .map((s: any) => [String(s.Persona), true] as [string, boolean])
      .sort((a: any, b: any) => a[0].localeCompare(b[0])), category);
  }
  const sortedLeads = (leads: [string, boolean][]) => [...leads].sort((a, b) => a[0].localeCompare(b[0]));

  // Removes the items this run created, by id, through the batch endpoint. An
  // item is this run's when its TITLE starts with this run's prefix (the run's own
  // time): other cells (the Leads cell reads 'E2E Schedule Editor') never count.
  // Items left by an earlier run go too, but only when the time in their title is
  // more than an hour old, so a run still in progress keeps its items; their
  // locations would otherwise show as suggestions in this run's lines.
  test.afterAll(async ({ browser }, testInfo) => {
    if (!ready) return;
    testInfo.setTimeout(120000);
    const page = await browser.newPage();
    let left: number | string = 'not run';
    let cleared = 'not run';
    try {
      await signIn(page);
      await openBoard(page, EDIT_EVENT);
      const own = Number(PREFIX.slice(4)), staleBefore = Date.now() - 3600 * 1000;
      const ours = (title: string) => {
        const m = /^E2E (\d{13})( |$)/.exec(title);
        return !!m && (Number(m[1]) === own || Number(m[1]) < staleBefore);
      };
      const ids = (await serverRows(page)).filter(r => ours(String(r.Title))).map(r => +r.EventScheduleId);
      left = await removeIds(page, ids);
    } catch (e) {
      left = String(e);
    } finally {
      // The test account leaves this spec leading no category, whatever happened
      // above, saved through the staff endpoint with its other fields as the
      // staff table carries them.
      cleared = await clearTestLeads(page).catch(e => String(e));
      await page.close();
    }
    expect(left).toBe(0);
    expect(cleared).toBe('ok');
  });

  async function clearTestLeads(page: Page): Promise<string> {
    if (!/Event\/detail\//.test(page.url())) {
      await signIn(page);
      await openBoard(page, EDIT_EVENT);
    }
    return page.evaluate(async () => {
      const c = (window as any).EvConfig;
      const row = document.querySelector('#ev-staff-tbody tr[data-persona="E2E Schedule Editor"]') as HTMLElement | null;
      if (!row) return 'no staff row';
      if (row.dataset.leadCategories === '[]') return 'ok';
      const fd = new FormData();
      fd.append('StaffId', row.id.replace('ev-staff-row-', ''));
      fd.append('MundaneId', row.dataset.mundaneId || '');
      fd.append('RoleName', row.dataset.role || '');
      fd.append('CanManage', row.dataset.manage || '0');
      fd.append('CanAttendance', row.dataset.attendance || '0');
      fd.append('CanSchedule', row.dataset.schedule || '0');
      fd.append('CanFeast', row.dataset.feast || '0');
      fd.append('LeadCategories', '[]');
      const res = await (await fetch(c.uir + 'EventAjax/add_staff/' + c.eventId + '/' + c.detailId, {
        method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })).json();
      return res.status === 0 && res.staff.LeadCategories.length === 0 ? 'ok' : JSON.stringify(res);
    });
  }

  test('Edit schedule is offered to an editor and not to a signed-out viewer', async ({ page, browser }) => {
    await openBoard(page, EDIT_EVENT);
    await expect(page.locator('.evb-edit-btn')).toBeVisible();
    const anonCtx = await browser.newContext();
    const anon = await anonCtx.newPage();
    await anon.goto(`index.php?Route=Event/detail/${EDIT_EVENT}#ev-tab-schedule?view=board`);
    await expect(anon.locator('.evb-edit-btn')).toHaveCount(0);
    await anonCtx.close();
  });

  test('the quick line previews, adds and persists an item', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    const line = page.locator('.evb-quickline .evb-entry-input');
    await expect(line).toBeFocused();
    await line.fill(`sat 9:30-10:15 bg: ${PREFIX} squatch at main field "Bring water"`);
    await expect(page.locator('.evb-ghost')).toHaveCount(1);
    await expect(page.locator('.evb-chip[data-part="category"]')).toContainText('Battlegame');
    // An existing place's spelling wins ('Main Field' when the event already has one).
    await expect(page.locator('.evb-chip[data-part="location"]')).toContainText(/main field/i);
    await line.press('Enter');
    await expect(line).toHaveValue('');
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    const block = page.locator('.evb-block', { hasText: `${PREFIX} squatch` });
    await expect(block).toHaveAttribute('data-category', 'Battlegame');
    await line.fill(`then 30m ${PREFIX} quest`);
    await line.press('Enter');
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} quest` })).toContainText('10:15–10:45a');
    await page.locator('.evb-done').click();
    await expect(page.locator('.evb-modebar')).toHaveCount(0);
    await page.reload();
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} squatch` })).toHaveCount(1);
    await page.locator('[data-ev-view="list"]').click();
    await expect(page.locator('#ev-schedule-container')).toContainText(`${PREFIX} squatch`);
  });

  test('undo removes the last add and a category tag is suggested', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    const line = page.locator('.evb-quickline .evb-entry-input');
    await line.fill(`sat 2p ${PREFIX} undo me`);
    await line.press('Enter');
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} undo me` })).toHaveCount(1);
    await page.locator('.evb-undo').click();
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} undo me` })).toHaveCount(0);
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    await expect.poll(() => serverItem(page, `${PREFIX} undo me`)).toBeNull();
    await line.fill('sat 3p #cl');
    await expect(page.locator('.evb-entry-option').first()).toContainText('Class');
    await line.press('Enter');
    await expect(line).toHaveValue(/#class\s*$/);
    await expect(page.locator('.evb-block', { hasText: '#cl' })).toHaveCount(0);
  });

  // ---- Typing on the board ----

  // Clicks the empty board at a time of day (HH:MM) in a day's column.
  async function clickSlot(page: Page, day: string, hhmm: string) {
    // Edit mode opens after a fetch, and widens the axis when it does.
    await expect(page.locator('.evb-modebar')).toBeVisible();
    const body = page.locator(`.evb-day-body[data-day="${day}"]`);
    const [h, m] = hhmm.split(':').map(Number);
    const y = await page.evaluate(([hh, mm]) => {
      const w = window as any;
      return ((hh * 60 + mm) - w.EvBoardView.board.axisStart) * w.EvBoardView.PX_PER_MIN + 4;
    }, [h, m]);
    await body.click({ position: { x: 20, y } });
  }

  test('click, type, Enter, type, Enter makes a run of items', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SUNDAY = await dayOf(page, 'sun');
    await page.locator('.evb-edit-btn').click();
    await clickSlot(page, SUNDAY, '10:00');
    const editor = page.locator('.evb-block-editing .evb-entry-input');
    await expect(editor).toBeFocused();
    await editor.type(`class: ${PREFIX} first`);
    await expect(page.locator('.evb-block-editing')).toHaveAttribute('data-category', 'Class');
    await editor.press('Enter');
    await expect(editor).toBeFocused();
    await editor.type(`${PREFIX} second`);
    await editor.press('Enter');
    await editor.press('Escape');
    await expect(page.locator('.evb-block-editing')).toHaveCount(0);
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} first` })).toContainText('10–11a');
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} second` })).toContainText('11a–12p');
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
  });

  test('typing a time inside a block retimes it; Tab starts one alongside', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SUNDAY = await dayOf(page, 'sun');
    await page.locator('.evb-edit-btn').click();
    await clickSlot(page, SUNDAY, '13:00');
    const editor = page.locator('.evb-block-editing .evb-entry-input');
    await editor.type(`2-2:30 ${PREFIX} retimed`);
    await editor.press('Tab');
    await editor.type(`${PREFIX} beside`);
    await editor.press('Enter');
    await editor.press('Escape');
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} retimed` })).toContainText('2–2:30p');
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} beside` })).toContainText('2–2:30p');
  });

  test('Escape on a fresh block adds nothing', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SUNDAY = await dayOf(page, 'sun');
    await page.locator('.evb-edit-btn').click();
    const before = await page.locator('.evb-block').count();
    await clickSlot(page, SUNDAY, '15:00');
    await page.locator('.evb-block-editing .evb-entry-input').press('Escape');
    expect(await page.locator('.evb-block').count()).toBe(before);
  });

  test('dragging sets the length; clicking into the quick line keeps what was typed', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SUNDAY = await dayOf(page, 'sun');
    await page.locator('.evb-edit-btn').click();
    await expect(page.locator('.evb-modebar')).toBeVisible();
    // Bring 5pm to the middle of the board's frame, then work in viewport points.
    await page.evaluate(day => {
      const w = window as any;
      const s = document.querySelector('.evb-scroller') as HTMLElement;
      s.scrollIntoView({ block: 'end' });
      s.scrollTop = (17 * 60 - w.EvBoardView.board.axisStart) * w.EvBoardView.PX_PER_MIN - s.clientHeight / 2;
    }, SUNDAY);
    const at = (hh: number, mm: number) => page.evaluate(([day, h, m]) => {
      const w = window as any;
      const r = document.querySelector(`.evb-day-body[data-day="${day}"]`)!.getBoundingClientRect();
      return { x: r.left + 20, y: r.top + ((+h * 60 + +m) - w.EvBoardView.board.axisStart) * w.EvBoardView.PX_PER_MIN };
    }, [SUNDAY, hh, mm] as [string, number, number]);
    let p = await at(17, 0);
    await page.mouse.move(p.x, p.y + 4);
    await expect(page.locator('.evb-slot-hover')).toHaveCount(1);
    await page.mouse.down();
    p = await at(18, 0);
    await page.mouse.move(p.x, p.y, { steps: 4 });
    p = await at(18, 20);
    await page.mouse.move(p.x, p.y, { steps: 4 });
    await page.mouse.up();
    const editor = page.locator('.evb-block-editing .evb-entry-input');
    await expect(editor).toBeFocused();
    await editor.type(`${PREFIX} dragged`);
    await page.locator('.evb-quickline .evb-entry-input').click();
    await expect(page.locator('.evb-block-editing')).toHaveCount(0);
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} dragged` })).toContainText('5–6:30p');
  });

  test('Done with a titleless block stays in Edit mode', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SUNDAY = await dayOf(page, 'sun');
    await page.locator('.evb-edit-btn').click();
    await clickSlot(page, SUNDAY, '08:00');
    const editor = page.locator('.evb-block-editing .evb-entry-input');
    await editor.type('2-3');
    await page.locator('.evb-done').click();
    await expect(page.locator('.evb-notice')).toContainText('no title');
    await expect(page.locator('.evb-modebar')).toBeVisible();
    await expect(editor).toHaveValue('2-3');
    await editor.press('Escape');
    await expect(page.locator('.evb-block-editing')).toHaveCount(0);
  });

  test('a run can cross midnight', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SATURDAY = await dayOf(page, 'sat');
    await page.locator('.evb-edit-btn').click();
    await clickSlot(page, SATURDAY, '23:30');
    const editor = page.locator('.evb-block-editing .evb-entry-input');
    await editor.type(`${PREFIX} late court`);
    await editor.press('Enter');
    await editor.type(`${PREFIX} wee watch`);
    await editor.press('Enter');
    await editor.press('Escape');
    const start = await page.evaluate(title => {
      const it = (window as any).EvBoardEdit.store.items().find((i: any) => i.Title === title);
      return it ? String(it.StartTime) : '';
    }, `${PREFIX} wee watch`);
    // After midnight on the calendar, still on Saturday's board day.
    expect(start.startsWith(`${addDays(SATURDAY, 1)} 00:30`)).toBe(true);
    await expect(page.locator(`.evb-block[data-day="${SATURDAY}"]`, { hasText: `${PREFIX} wee watch` })).toHaveCount(1);
  });

  test('nothing is added once Done is leaving', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SUNDAY = await dayOf(page, 'sun');
    await page.locator('.evb-edit-btn').click();
    await expect(page.locator('.evb-modebar')).toBeVisible();
    // Done re-fetches the page: hold that answer back so the leaving window stays open.
    await page.route(/Route=Event\/detail\//, async route => {
      await new Promise(r => setTimeout(r, 1500));
      await route.continue();
    });
    await page.locator('.evb-done').click();
    await clickSlot(page, SUNDAY, '09:00');
    await page.keyboard.type(`${PREFIX} too late`);
    await page.keyboard.press('Enter');
    await expect(page.locator('.evb-block-editing')).toHaveCount(0);
    await expect(page.locator('.evb-modebar')).toHaveCount(0, { timeout: 10000 });
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} too late` })).toHaveCount(0);
  });

  test('returning to the tab picks up changes made elsewhere', async ({ page, context }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    const other = await context.newPage();
    await openBoard(other, EDIT_EVENT);
    await other.locator('.evb-edit-btn').click();
    const line = other.locator('.evb-quickline .evb-entry-input');
    await line.fill(`sun 4p ${PREFIX} elsewhere`);
    await line.press('Enter');
    await expect(other.locator('.evb-save-state')).toHaveText('All changes saved');
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} elsewhere` })).toHaveCount(1);
  });

  test('Done never discards a rejected line', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    const line = page.locator('.evb-quickline .evb-entry-input');
    await page.route(/schedule_batch/, route => route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ status: 1, error: 'A title is required.', failedIndex: 0 }),
    }), { times: 1 });
    // Hold the line in place while the rejection arrives, so the restore goes to the notice.
    await line.fill(`sat 4p ${PREFIX} refused`);
    await page.evaluate(() => {
      const input = document.querySelector('.evb-quickline .evb-entry-input') as HTMLInputElement;
      input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
      input.value = 'sun 2p typed meanwhile';
      input.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await expect(page.locator('.evb-notice')).toContainText('Couldn’t save');
    await expect(page.locator('.evb-notice-restore')).toHaveCount(1);
    await line.fill(`sun 3p ${PREFIX} other line`);
    await page.locator('.evb-done').click();
    await expect(page.locator('.evb-modebar')).toBeVisible();
    const kept = (await line.inputValue()).includes(`${PREFIX} refused`) ||
      await page.locator('.evb-notice-restore').count() > 0;
    expect(kept).toBe(true);
    await line.press('Escape');
    await page.locator('.evb-done').click();
    await expect(page.locator('.evb-modebar')).toHaveCount(0);
  });

  test('Done on a line that only names a day applies nothing and leaves quietly', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    const line = page.locator('.evb-quickline .evb-entry-input');
    await line.fill('sun');
    await page.locator('.evb-done').click();
    await expect(page.locator('.evb-modebar')).toHaveCount(0);
    await expect(page.locator('.evb-notice')).toHaveCount(0);
  });

  test('board shortcuts do nothing while the schedule modal is open', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    // Add Schedule Item is hidden in Edit mode: the form opens from the toolbar's Details.
    await quickAdd(page, `sun 6:15p ${PREFIX} modal keys`);
    await page.locator('.evb-block', { hasText: `${PREFIX} modal keys` }).click();
    await page.locator('.evb-block-toolbar [data-evb-act="details"]').click();
    await expect(page.locator('#ev-schedule-modal')).toBeVisible();
    await page.locator('#ev-schedule-modal .ev-modal-close').focus();
    await page.keyboard.press('/');
    expect(await page.evaluate(() => document.activeElement?.classList.contains('evb-entry-input'))).toBe(false);
    await page.locator('#ev-schedule-modal .ev-modal-close').click();
    await expect(page.locator('#ev-schedule-modal')).toBeHidden();
  });

  // ---- Selecting, changing and deleting blocks ----

  test('select, recategorise with a key, rename in place, delete and undo', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    const line = page.locator('.evb-quickline .evb-entry-input');
    await line.fill(`sun 9a ${PREFIX} keys`);
    await line.press('Enter');
    const block = page.locator('.evb-block', { hasText: `${PREFIX} keys` });
    await block.click();
    await expect(block).toHaveClass(/evb-selected/);
    await expect(page.locator('.evb-popover')).toHaveCount(0);
    await page.keyboard.press('5');
    await expect(block).toHaveAttribute('data-category', 'Class');
    await page.keyboard.press('Enter');
    const editor = page.locator('.evb-block-editing .evb-entry-input');
    await expect(editor).toHaveValue(`${PREFIX} keys`);
    await editor.fill(`${PREFIX} renamed at Hall`);
    await editor.press('Enter');
    const renamed = page.locator('.evb-block', { hasText: `${PREFIX} renamed` });
    await expect(renamed).toHaveAttribute('data-category', 'Class');
    // A rename is the title, verbatim: ' at Hall' is not read as a location.
    const it = await page.evaluate(t => (window as any).EvBoardEdit.store.items().find((i: any) => i.Title === t), `${PREFIX} renamed at Hall`);
    expect([it && it.Title, it && it.Location]).toEqual([`${PREFIX} renamed at Hall`, '']);
    await renamed.click();
    await page.keyboard.press('Delete');
    await expect(renamed).toHaveCount(0);
    await page.locator('.evb-undo').click();
    await expect(renamed).toHaveCount(1);
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    // The undone delete is back on the server, renamed and recategorised.
    const SUNDAY = await dayOf(page, 'sun');
    await expect.poll(() => serverItem(page, `${PREFIX} renamed at Hall`))
      .toEqual({ StartTime: `${SUNDAY} 09:00`, EndTime: `${SUNDAY} 10:00`, Category: 'Class', Leads: [] });
  });

  test('Details opens the full form for the selected item', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    const line = page.locator('.evb-quickline .evb-entry-input');
    await line.fill(`sun 11a ${PREFIX} details at Hall`);
    await line.press('Enter');
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    await page.locator('.evb-block', { hasText: `${PREFIX} details` }).click();
    await page.locator('.evb-block-toolbar [data-evb-act="details"]').click();
    await expect(page.locator('#ev-schedule-modal')).toBeVisible();
    await expect(page.locator('#ev-sched-title')).toHaveValue(`${PREFIX} details`);
    await expect(page.locator('#ev-sched-location')).toHaveValue('Hall');
  });

  test('deleting from the list asks with the themed dialog', async ({ page }) => {
    let nativeDialog = false;
    page.on('dialog', d => { nativeDialog = true; d.dismiss(); });
    await openBoard(page, EDIT_EVENT);
    await page.locator('[data-ev-view="list"]').click();
    await page.locator('#ev-schedule-container .ev-del-link').first().click();
    await expect(page.locator('text=Remove this schedule item?')).toBeVisible();
    expect(nativeDialog).toBe(false);
  });

  // ---- Phase 2 review fixes ----

  // Adds items through the batch endpoint from the signed-in page; returns their ids.
  async function addViaBatch(page: Page, items: object[]): Promise<number[]> {
    return page.evaluate(async list => {
      const c = (window as any).EvConfig;
      const fd = new FormData();
      fd.append('Ops', JSON.stringify(list.map((item, i) => ({ op: 'add', cid: 'x' + i, item }))));
      const res = await (await fetch(c.uir + 'EventAjax/schedule_batch/' + c.eventId + '/' + c.detailId, {
        method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })).json();
      if (res.status !== 0) throw new Error('batch add failed: ' + JSON.stringify(res));
      return res.results.map((r: any) => +(r.id || r.schedule.EventScheduleId));
    }, items);
  }
  // Adds an item from the quick line and waits for it to save.
  async function quickAdd(page: Page, line: string) {
    const input = page.locator('.evb-quickline .evb-entry-input');
    await input.fill(line);
    await input.press('Enter');
    await expect(input).toHaveValue('');
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
  }
  // The stored item with this title (null when there is none).
  async function storeItem(page: Page, title: string) {
    return page.evaluate(t => (window as any).EvBoardEdit.store.items().find((i: any) => i.Title === t) || null, title);
  }
  // The server id the board holds for the item with this title.
  async function idOf(page: Page, title: string): Promise<number> {
    const id = +((await storeItem(page, title)) || {}).EventScheduleId;
    expect(id).toBeGreaterThan(0);
    return id;
  }
  // An empty place on the board: a day and a start (HH:MM) inside the Edit-mode
  // axis with no block from 15 minutes before to 45 after; null when every day is full.
  async function freeSlot(page: Page): Promise<{ day: string; at: string } | null> {
    return page.evaluate(() => {
      const w = window as any, b = w.EvBoardView.board;
      for (const col of b.days) {
        for (let m = Math.ceil(b.axisStart / 15) * 15 + 30; m + 60 <= Math.min(b.axisEnd, 1440); m += 15) {
          if (!col.segs.some((s: any) => s.startMin < m + 45 && Math.max(s.endMin, s.startMin + 30) > m - 15)) {
            return { day: col.day, at: String(Math.floor(m / 60)).padStart(2, '0') + ':' + String(m % 60).padStart(2, '0') };
          }
        }
      }
      return null;
    });
  }
  const isFocused = (loc: ReturnType<Page['locator']>) => loc.evaluate(el => document.activeElement === el);

  test('renaming leaves an untouched multi-line description and odd location alone', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SUNDAY = await dayOf(page, 'sun');
    const title = `${PREFIX} odd one`;
    const [id] = await addViaBatch(page, [{
      Title: title, StartTime: `${SUNDAY} 12:00`, EndTime: `${SUNDAY} 13:00`, Category: 'Other', SecondaryCategory: '',
      Location: 'Hall #2', Description: 'Line one\nLine two', Leads: [],
    }]);
    let batches = 0;
    page.on('request', r => { if (r.url().includes('schedule_batch')) batches++; });
    await page.locator('.evb-edit-btn').click();
    await expect(page.locator('.evb-modebar')).toBeVisible();
    const block = page.locator(`.evb-block[data-id="${id}"]`);
    const editor = page.locator('.evb-block-editing .evb-entry-input');
    const fields = () => page.evaluate(i => {
      const it = (window as any).EvBoardEdit.store.items().find((x: any) => +x.EventScheduleId === i);
      return it ? { Title: it.Title, Location: it.Location, Description: it.Description } : null;
    }, id);

    // Opened and left without typing: nothing is written.
    await block.dblclick();
    await expect(editor).toBeFocused();
    await page.locator('.evb-modebar-title').click();
    await expect(page.locator('.evb-block-editing')).toHaveCount(0);
    expect(await fields()).toEqual({ Title: title, Location: 'Hall #2', Description: 'Line one\nLine two' });
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    expect(batches).toBe(0);

    // One word of the title changed: only the title is written.
    await block.dblclick();
    await expect(editor).toBeFocused();
    await editor.fill((await editor.inputValue()).replace(' odd ', ' even '));
    await editor.press('Enter');
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    expect(await fields()).toEqual({ Title: `${PREFIX} even one`, Location: 'Hall #2', Description: 'Line one\nLine two' });
    expect(batches).toBe(1);

    // The box holds the title alone, and what is typed is stored exactly.
    await block.dblclick();
    await expect(editor).toHaveValue(`${PREFIX} even one`);
    await editor.fill(`${PREFIX} New name at Field #2`);
    await editor.press('Enter');
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    expect(await fields()).toEqual({ Title: `${PREFIX} New name at Field #2`, Location: 'Hall #2', Description: 'Line one\nLine two' });

    // Esc puts the title back; an empty box saves nothing.
    await block.dblclick();
    await editor.fill(`${PREFIX} not kept`);
    await editor.press('Escape');
    await block.dblclick();
    await editor.fill('');
    await page.locator('.evb-modebar-title').click();
    await expect(page.locator('.evb-block-editing')).toHaveCount(0);
    expect((await fields())!.Title).toBe(`${PREFIX} New name at Field #2`);
    expect(batches).toBe(2);
  });

  test('blocks can be selected and changed from the keyboard', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await quickAdd(page, `sun 1p ${PREFIX} kb one`);
    await quickAdd(page, `sun 2p ${PREFIX} kb two`);
    const one = page.locator('.evb-block', { hasText: `${PREFIX} kb one` });
    const two = page.locator('.evb-block', { hasText: `${PREFIX} kb two` });
    await one.focus();
    await expect(one).toHaveAttribute('aria-pressed', 'true');
    await expect(two).toHaveAttribute('aria-pressed', 'false');
    // The toolbar leads with Rename.
    await expect(page.locator('.evb-block-toolbar .evb-block-tool').first()).toHaveAttribute('data-evb-act', 'rename');
    // Tab from a selected block goes to its toolbar first, then on past the last tool.
    await page.keyboard.press('Tab');
    await expect(page.locator('.evb-block-toolbar [data-evb-act="rename"]')).toBeFocused();
    await expect(one).toHaveAttribute('aria-pressed', 'true');
    for (let i = 0; i < 90 && !(await isFocused(two)); i++) await page.keyboard.press('Tab');
    expect(await isFocused(two)).toBe(true);
    await expect(two).toHaveAttribute('aria-pressed', 'true');
    await expect(one).toHaveAttribute('aria-pressed', 'false');
    await page.keyboard.press('5');
    await expect(two).toHaveAttribute('data-category', 'Class');
    expect(await isFocused(two)).toBe(true);
    await page.keyboard.press('Delete');
    await expect(two).toHaveCount(0);
    await expect(one).toHaveCount(1);

    // Rename from the toolbar; Esc hands the keys back to the block.
    await one.click();
    await page.locator('.evb-block-toolbar [data-evb-act="rename"]').click();
    const editor = page.locator('.evb-block-editing .evb-entry-input');
    await expect(editor).toHaveValue(`${PREFIX} kb one`);
    await editor.press('Escape');
    await expect(page.locator('.evb-block-editing')).toHaveCount(0);
    expect(await isFocused(one)).toBe(true);
  });

  test('Delete on a focused toolbar button does not delete the block', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await quickAdd(page, `sun 3p ${PREFIX} keep me`);
    const block = page.locator('.evb-block', { hasText: `${PREFIX} keep me` });
    await block.click();
    await page.locator('.evb-block-toolbar [data-evb-act="details"]').focus();
    await page.keyboard.press('Delete');
    await page.keyboard.press('Backspace');
    await page.locator('.evb-undo').focus();
    await page.keyboard.press('Delete');
    await page.keyboard.press('5');
    await expect(block).toHaveCount(1);
    await expect(block).toHaveClass(/evb-selected/);
    await expect(block).not.toHaveAttribute('data-category', 'Class');
  });

  test('a signed-out save says so and Retry keeps the change', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await page.route(/schedule_batch/, route => route.fulfill({
      contentType: 'application/json', body: JSON.stringify({ status: 5, error: 'Not logged in' }),
    }), { times: 1 });
    const line = page.locator('.evb-quickline .evb-entry-input');
    await line.fill(`sun 4p ${PREFIX} signed out`);
    await line.press('Enter');
    await expect(page.locator('.evb-save-state')).toHaveText('You’re signed out. Sign in in another tab, then press Retry.');
    await page.locator('.evb-save-retry').click();
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    expect((await storeItem(page, `${PREFIX} signed out`))?.EventScheduleId).toBeGreaterThan(0);
  });

  test('board keys stand down while the Schedule tab is hidden', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await quickAdd(page, `sun 5p ${PREFIX} hidden tab`);
    const block = page.locator('.evb-block', { hasText: `${PREFIX} hidden tab` });
    await block.focus();
    await expect(block).toHaveAttribute('aria-pressed', 'true');
    await page.evaluate(() => (window as any).evShowTab(document.querySelector('[data-tab="ev-tab-details"]'), 'ev-tab-details'));
    await page.keyboard.press('Delete');
    await page.keyboard.press('5');
    await page.keyboard.press('/');
    expect(await page.evaluate(() => !!document.activeElement?.closest('.evb-quickline'))).toBe(false);
    const it = await storeItem(page, `${PREFIX} hidden tab`);
    expect(it).not.toBeNull();
    expect(it.Category).not.toBe('Class');
    await page.evaluate(() => (window as any).evShowTab(document.querySelector('[data-tab="ev-tab-schedule"]'), 'ev-tab-schedule'));
    await expect(block).toHaveCount(1);
  });

  test('Esc closes the help without clearing the selection', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await quickAdd(page, `sun 6p ${PREFIX} help esc`);
    await page.locator('.evb-help').click();
    await expect(page.locator('.evb-help-pop')).toBeVisible();
    const block = page.locator('.evb-block', { hasText: `${PREFIX} help esc` });
    await block.focus();
    await expect(block).toHaveAttribute('aria-pressed', 'true');
    await page.keyboard.press('Escape');
    await expect(page.locator('.evb-help-pop')).toBeHidden();
    await expect(block).toHaveAttribute('aria-pressed', 'true');
    await expect(block).toHaveClass(/evb-selected/);
  });

  test('Ctrl+Z in an emptied rename box does not undo the board', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await quickAdd(page, `sun 7p ${PREFIX} undo guard`);
    const block = page.locator('.evb-block', { hasText: `${PREFIX} undo guard` });
    await block.dblclick();
    const editor = page.locator('.evb-block-editing .evb-entry-input');
    await expect(editor).toBeFocused();
    await editor.fill('');
    await page.keyboard.press('Control+z');
    await page.keyboard.press('Meta+z');
    expect(await storeItem(page, `${PREFIX} undo guard`)).not.toBeNull();
    await page.locator('.evb-block-editing .evb-entry-input').press('Escape');
    await expect(block).toHaveCount(1);
  });

  test('Details waits for a save, and says so when it cannot', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await expect(page.locator('.evb-modebar')).toBeVisible();
    await expect(page.locator('.evb-notice')).toHaveAttribute('role', 'alert');
    // A slow save: the selection moves on before it lands, so the form never opens.
    await page.route(/schedule_batch/, async route => {
      await new Promise(r => setTimeout(r, 1500));
      await route.continue();
    }, { times: 1 });
    const line = page.locator('.evb-quickline .evb-entry-input');
    await line.fill(`sun 8p ${PREFIX} slow details`);
    await line.press('Enter');
    await page.locator('.evb-block', { hasText: `${PREFIX} slow details` }).click();
    await page.locator('.evb-block-toolbar [data-evb-act="details"]').click();
    await page.locator('.evb-modebar-title').click();
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    await page.waitForTimeout(300);
    await expect(page.locator('#ev-schedule-modal')).toBeHidden();

    // Offline: a notice instead of nothing.
    await page.route(/schedule_batch/, route => route.abort(), { times: 1 });
    await line.fill(`sun 9p ${PREFIX} offline details`);
    await line.press('Enter');
    await expect(page.locator('.evb-save-state')).toHaveText('Couldn’t reach the server');
    await page.locator('.evb-block', { hasText: `${PREFIX} offline details` }).click();
    await page.locator('.evb-block-toolbar [data-evb-act="details"]').click();
    await expect(page.locator('.evb-notice')).toContainText('Still saving. Try Details again in a moment.');
    await expect(page.locator('#ev-schedule-modal')).toBeHidden();
    await page.locator('.evb-save-retry').click();
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
  });

  test('a chip x is reachable by keyboard and finger-sized on a phone', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 800 });
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    const line = page.locator('.evb-quickline .evb-entry-input');
    await line.fill(`${PREFIX} chipx at Zqx Yard`);
    await expect(page.locator('.evb-quickline .evb-chip[data-part="location"]')).toContainText('Zqx Yard');
    await line.press('Tab');
    const x = page.locator('.evb-quickline .evb-chip-x:focus');
    await expect(x).toHaveAttribute('aria-label', 'Not a location');
    const box = await x.boundingBox();
    expect(box!.width).toBeGreaterThanOrEqual(28);
    expect(box!.height).toBeGreaterThanOrEqual(28);
    await page.keyboard.press('Enter');
    await expect(page.locator('.evb-quickline .evb-chip[data-part="location"]')).toHaveCount(0);
    await expect(line).toBeFocused();
    await line.press('Escape');
  });

  test('the category menu is a group of pressed buttons', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await quickAdd(page, `sun 10p #class ${PREFIX} cat menu`);
    const block = page.locator('.evb-block', { hasText: `${PREFIX} cat menu` });
    await block.click();
    const catBtn = page.locator('.evb-block-toolbar [data-evb-act="category"]');
    await catBtn.click();
    const menu = page.locator('.evb-cat-menu');
    await expect(menu).toHaveAttribute('role', 'group');
    await expect(menu).toHaveAttribute('aria-label', 'Category');
    expect(await page.locator('.evb-select-layer [role="menu"], .evb-select-layer [role="menuitemradio"]').count()).toBe(0);
    const current = menu.locator('button[data-category="Class"]');
    await expect(current).toHaveAttribute('aria-pressed', 'true');
    await expect(current).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(menu).toBeHidden();
    await expect(catBtn).toBeFocused();
    await expect(block).toHaveClass(/evb-selected/);
  });

  test('$0 typed on a feast item is saved as free; a rename keeps $ as title text', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    test.skip(!(await page.evaluate(() => !!(window as any).EvConfig.canManageFeast)), 'the test account cannot manage feast');
    await page.locator('.evb-edit-btn').click();
    await quickAdd(page, `sun 12p #feast ${PREFIX} lunch $0`);
    const cost = (await storeItem(page, `${PREFIX} lunch`)).Cost;
    expect(cost === null || cost === undefined || cost === '').toBe(false);
    expect(+cost).toBe(0);
    await page.locator('.evb-block', { hasText: `${PREFIX} lunch` }).dblclick();
    const editor = page.locator('.evb-block-editing .evb-entry-input');
    await expect(editor).toBeFocused();
    await editor.fill(`${await editor.inputValue()} $5`);
    await editor.press('Enter');
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    expect(+(await storeItem(page, `${PREFIX} lunch $5`)).Cost).toBe(0);
  });

  test('clicking inside the rename box keeps the selection', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await quickAdd(page, `sun 11a ${PREFIX} click inside`);
    const block = page.locator('.evb-block', { hasText: `${PREFIX} click inside` });
    await block.dblclick();
    const editor = page.locator('.evb-block-editing .evb-entry-input');
    await expect(editor).toBeFocused();
    await editor.click();
    await editor.press('Escape');
    await expect(block).toHaveClass(/evb-selected/);
  });

  test('a refresh during a rename does not draw the item under its editor', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await quickAdd(page, `sun 10a ${PREFIX} refreshed`);
    const block = page.locator('.evb-block', { hasText: `${PREFIX} refreshed` });
    await block.dblclick();
    await expect(page.locator('.evb-block-editing .evb-entry-input')).toBeFocused();
    // A refresh takes the server's copy, which renames every cid.
    expect(await page.evaluate(() => {
      const st = (window as any).EvBoardEdit.store;
      return st.reset(st.items().map((it: any) => { const c = Object.assign({}, it); delete c.cid; return c; }));
    })).toBe(true);
    await expect(block).toHaveCount(0);
    await page.locator('.evb-block-editing .evb-entry-input').press('Escape');
    await expect(block).toHaveCount(1);
  });

  test('in single-day view the board follows the block being typed', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 800 });
    await openBoard(page, EDIT_EVENT);
    const SATURDAY = await dayOf(page, 'sat');
    const SUNDAY = await dayOf(page, 'sun');
    await page.locator('.evb-edit-btn').click();
    await expect(page.locator('.evb-modebar')).toBeVisible();
    await page.locator(`.evb-day-pill[data-day="${SATURDAY}"]`).click();
    await expect(page.locator('.evb-day:visible')).toHaveAttribute('data-day', SATURDAY);
    await clickSlot(page, SATURDAY, '22:00');
    const editor = page.locator('.evb-block-editing .evb-entry-input');
    await expect(editor).toBeFocused();
    await editor.type(`11p-6a ${PREFIX} vigil`);
    await editor.press('Enter');
    // The next block starts at 6am, on the following board day.
    await expect(page.locator('.evb-day:visible')).toHaveAttribute('data-day', SUNDAY);
    await expect(editor).toBeFocused();
    await expect(page.locator('.evb-type-block')).toBeVisible();
    await editor.press('Escape');
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
  });

  test('Undo and Redo stay disabled while Done is leaving', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await expect(page.locator('.evb-modebar')).toBeVisible();
    await page.route(/schedule_batch/, async route => {
      await new Promise(r => setTimeout(r, 800));
      await route.continue();
    }, { times: 1 });
    await page.route(/Route=Event\/detail\//, async route => {
      await new Promise(r => setTimeout(r, 2500));
      await route.continue();
    });
    const line = page.locator('.evb-quickline .evb-entry-input');
    await line.fill(`sun 9a ${PREFIX} leaving`);
    await line.press('Enter');
    await page.locator('.evb-done').click();
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    await expect(page.locator('.evb-modebar')).toBeVisible();
    await expect(page.locator('.evb-undo')).toBeDisabled();
    await expect(page.locator('.evb-redo')).toBeDisabled();
    await expect(page.locator('.evb-modebar')).toHaveCount(0, { timeout: 10000 });
  });

  test('deleting an item with meal details asks first when you cannot manage feast', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    test.skip(!(await page.evaluate(() => !!(window as any).EvConfig.canManageFeast)), 'the test account cannot save a menu');
    const SUNDAY = await dayOf(page, 'sun');
    const [id] = await addViaBatch(page, [{
      Title: `${PREFIX} stew supper`, StartTime: `${SUNDAY} 13:00`, EndTime: `${SUNDAY} 14:00`,
      Category: 'Feast and Food', SecondaryCategory: '', Location: '', Description: '', Leads: [], Menu: 'Stew',
    }]);
    await page.evaluate(() => { (window as any).EvConfig.canManageFeast = false; });
    await page.locator('.evb-edit-btn').click();
    await expect(page.locator('.evb-modebar')).toBeVisible();
    const block = page.locator(`.evb-block[data-id="${id}"]`);
    const confirm = page.locator('#ork-confirm-overlay.ork-confirm-open');
    await block.click();
    await page.keyboard.press('Delete');
    await expect(confirm.locator('#ork-confirm-msg'))
      .toHaveText('This item has menu and dietary details that you will not be able to restore. Remove it?');
    await confirm.locator('#ork-confirm-cancel').click();
    await expect(confirm).toHaveCount(0);
    await expect(block).toHaveCount(1);
    await block.click();
    await page.keyboard.press('Delete');
    await confirm.locator('#ork-confirm-ok').click();
    await expect(block).toHaveCount(0);
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    expect(await page.evaluate(() => (window as any).EvBoardEdit.store.canUndo())).toBe(false);
    await expect(page.locator('.evb-undo')).toBeDisabled();
  });

  // Leaves the test account leading Battlegame; the next tests rely on that and
  // the block's afterAll clears it.
  test('a staff member can be made a category lead', async ({ page }) => {
    await page.goto(`index.php?Route=Event/detail/${EDIT_EVENT}#ev-tab-staff`);
    // A Battlegame item with no leads, so the save has at least one item to fill.
    const SUNDAY = await dayOf(page, 'sun');
    await addViaBatch(page, [{
      Title: `${PREFIX} lead fill`, StartTime: `${SUNDAY} 11:00`, EndTime: `${SUNDAY} 12:00`,
      Category: 'Battlegame', SecondaryCategory: '', Location: '', Description: '', Leads: [],
    }]);
    const changed: any[] = [];
    await page.exposeFunction('e2eStaffChanged', (list: any) => { changed.push(list); });
    await page.evaluate(() => (window as any).evOnStaffChanged((list: any) => (window as any).e2eStaffChanged(list)));

    const row = page.locator('#ev-staff-tbody tr', { hasText: 'E2E Schedule Editor' });
    await expect(row.locator('.evb-cat-chip')).toHaveCount(0);
    await row.locator('[data-tip="Edit"]').click();
    const toggle = page.locator('#ev-staff-lead-cats .evb-cat-toggle[data-category="Battlegame"]');
    await expect(toggle).toHaveAttribute('aria-pressed', 'false');
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');
    await page.locator('#ev-staff-save-btn').click();
    await expect(row.locator('.evb-cat-chip[data-category="Battlegame"]')).toBeVisible();
    await expect(page.locator('#ev-staff-lead-note')).toHaveText(/^Added as lead on \d+ items?\.$/);
    // The List's server-rendered rows are re-read: the item now shows its lead.
    await expect(page.locator('#ev-schedule-container tr', { hasText: `${PREFIX} lead fill` })).toContainText('E2E Schedule Editor');
    await expect.poll(() => changed.length).toBe(1);
    expect(changed[0].find((s: any) => s.Persona === 'E2E Schedule Editor').LeadCategories).toEqual(['Battlegame']);
    expect(await page.evaluate(() => (window as any).EvConfig.staffList)).toEqual(changed[0]);

    // Opening the modal again clears the note and pre-presses what the row leads.
    await row.locator('[data-tip="Edit"]').click();
    await expect(page.locator('#ev-staff-lead-note')).toHaveText('');
    await expect(toggle).toHaveAttribute('aria-pressed', 'true');
    await page.locator('#ev-staff-modal .ev-modal-close').click();

    await page.reload();
    await expect(page.locator('#ev-staff-tbody tr', { hasText: 'E2E Schedule Editor' }).locator('.evb-cat-chip[data-category="Battlegame"]')).toBeVisible();
    expect(await page.evaluate(() => (window as any).EvConfig.staffList.find((s: any) => s.Persona === 'E2E Schedule Editor').LeadCategories)).toEqual(['Battlegame']);
  });

  // ---- Leads while typing (the test account leads Battlegame from here on) ----

  test('a category lead is filled in, can be removed before adding, and @ adds one', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    const line = page.locator('.evb-quickline .evb-entry-input');
    await line.fill(`sat 1p bg: ${PREFIX} auto lead`);
    await expect(page.locator('.evb-chip-auto[data-part="lead"]')).toContainText('E2E Schedule Editor');
    await line.press('Enter');
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    await line.fill(`sat 2p bg: ${PREFIX} no lead`);
    await page.locator('.evb-chip-auto[data-part="lead"] .evb-chip-x').click();
    await expect(page.locator('.evb-chip[data-part="lead"]')).toHaveCount(0);
    // Removed stays removed when the same category is read again.
    await line.press('End');
    await line.pressSequentially(' x');
    await expect(page.locator('.evb-chip[data-part="lead"]')).toHaveCount(0);
    await line.press('Backspace');
    await line.press('Backspace');
    await line.press('Enter');
    await line.fill(`sat 3p class: ${PREFIX} tagged @E2E`);
    await expect(page.locator('.evb-entry-option').first()).toContainText('E2E Schedule Editor');
    await line.press('Enter');
    await expect(line).toHaveValue(new RegExp(`${PREFIX} tagged\\s*$`));
    await expect(page.locator('.evb-chip[data-part="lead"]:not(.evb-chip-auto)')).toContainText('E2E Schedule Editor');
    await line.press('Enter');
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    const leads = await page.evaluate((prefix) => {
      const items = (window as any).EvBoardEdit.store.items();
      const of = (t: string) => items.find((i: any) => i.Title === `${prefix} ${t}`).Leads.map((l: any) => [l.Persona, !!l.Auto]);
      return [of('auto lead'), of('no lead'), of('tagged')];
    }, PREFIX);
    expect(leads).toEqual([[['E2E Schedule Editor', true]], [], [['E2E Schedule Editor', false]]]);
    await page.locator('.evb-done').click();
    await page.reload();
    await page.locator('.evb-block', { hasText: `${PREFIX} no lead` }).click();
    await expect(page.locator('.evb-popover')).not.toContainText('E2E Schedule Editor');
  });

  test('an @ that matches no one stays in the title; picking an auto lead makes it hand-picked', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    const line = page.locator('.evb-quickline .evb-entry-input');
    await line.fill(`sat 4p ${PREFIX} mail @zzqqxx`);
    const menu = page.locator('.evb-quickline .evb-entry-menu');
    await expect(menu).toContainText('No one found');
    await line.press('Escape');
    await expect(menu).toBeHidden();
    await expect(page.locator('.evb-chip[data-part="title"]')).toContainText('@zzqqxx');
    await line.press('Enter');
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    expect(await storeItem(page, `${PREFIX} mail @zzqqxx`)).not.toBeNull();

    await line.fill(`sat 5p bg: ${PREFIX} once @E2E`);
    await expect(page.locator('.evb-entry-option').first()).toContainText('E2E Schedule Editor');
    await line.press('Enter');
    await expect(page.locator('.evb-chip[data-part="lead"]')).toHaveCount(1);
    await expect(page.locator('.evb-chip[data-part="lead"]')).not.toHaveClass(/evb-chip-auto/);
    await line.press('Enter');
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    expect((await storeItem(page, `${PREFIX} once`)).Leads.map((l: any) => [l.Persona, !!l.Auto])).toEqual([['E2E Schedule Editor', false]]);
  });

  test('changing a category swaps auto-filled leads and keeps hand-picked ones', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    const line = page.locator('.evb-quickline .evb-entry-input');
    await line.fill(`sun 1p ${PREFIX} swap`);
    await line.press('Enter');
    const block = page.locator('.evb-block', { hasText: `${PREFIX} swap` });
    await block.click();
    await page.keyboard.press('3');
    await expect(block).toHaveAttribute('data-category', 'Battlegame');
    const after3 = await page.evaluate((t) => (window as any).EvBoardEdit.store.items().find((i: any) => i.Title === t).Leads.map((l: any) => !!l.Auto), `${PREFIX} swap`);
    expect(after3).toEqual((await autoLeadsFor(page, 'Battlegame')).map(() => true));
    await page.keyboard.press('7');
    const after7 = await page.evaluate((t) => (window as any).EvBoardEdit.store.items().find((i: any) => i.Title === t).Leads.length, `${PREFIX} swap`);
    expect(after7).toBe(0);
  });

  test('a rename shows the leads as chips; @ adds one and the query never joins the title', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await quickAdd(page, `sun 2p bg: ${PREFIX} renamed lead`);
    const block = page.locator('.evb-block', { hasText: `${PREFIX} renamed lead` });
    const editor = page.locator('.evb-block-editing .evb-entry-input');
    const chips = page.locator('.evb-type-pop .evb-chip[data-part="lead"]');

    // Esc drops a removed chip along with the rest of the change.
    await block.dblclick();
    await expect(editor).toHaveValue(`${PREFIX} renamed lead`);
    await expect(chips).toHaveCount(1);
    await expect(chips).toHaveClass(/evb-chip-auto/);
    await chips.locator('.evb-chip-x').click();
    await expect(chips).toHaveCount(0);
    await editor.press('Escape');
    expect((await storeItem(page, `${PREFIX} renamed lead`)).Leads.length).toBe(1);

    // '@' while renaming: picking makes the auto lead hand-picked, once.
    await block.dblclick();
    await editor.press('End');
    await editor.pressSequentially(' two @E2E');
    await expect(page.locator('.evb-entry-option').first()).toContainText('E2E Schedule Editor');
    await editor.press('Enter');
    await expect(editor).toHaveValue(`${PREFIX} renamed lead two `);
    await expect(chips).toHaveCount(1);
    await expect(chips).not.toHaveClass(/evb-chip-auto/);
    // A half-typed '@query' left in the box when it is left is kept as text.
    await editor.pressSequentially('@E2E Sch');
    await page.locator('.evb-modebar-title').click();
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    const it = await storeItem(page, `${PREFIX} renamed lead two @E2E Sch`);
    expect(it.Leads.map((l: any) => [l.Persona, !!l.Auto])).toEqual([['E2E Schedule Editor', false]]);
    expect(it.Category).toBe('Battlegame');
  });

  test('the schedule modal fills a new item\'s leads from its category and posts Auto', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    // The category's leads as the page's staff list has them (the test account
    // leads Battlegame only after the test above), so this also runs alone.
    const want = await autoLeadsFor(page, 'Battlegame');
    await page.evaluate(() => (window as any).evOpenScheduleModal());
    const leads = page.locator('#ev-sched-leads-list');
    await expect(leads).toContainText('None assigned');
    await page.locator('#ev-sched-category').selectOption('Battlegame');
    if (want.length) {
      for (const [persona] of want) await expect(leads).toContainText(persona);
      await expect(leads).toContainText('auto');
    } else {
      // Nobody leads Battlegame on this page, so there are no auto leads to see
      // here; the stored leads below are still checked against the empty list.
      await expect(leads).toContainText('None assigned');
    }
    // Another category without leads empties the untouched list again. It is
    // picked from the category list, as one no staff member leads (not assumed).
    const leadless = await page.evaluate(() => {
      const staff = (window as any).EvConfig.staffList || [];
      const led = new Set<string>();
      staff.forEach((s: any) => (s.LeadCategories || []).forEach((c: string) => led.add(c)));
      return Array.from((document.querySelector('#ev-sched-category') as HTMLSelectElement).options)
        .map(o => o.value).find(v => v && v !== 'Battlegame' && !led.has(v)) || '';
    });
    expect(leadless, 'a category nobody leads exists to switch to').not.toBe('');
    await page.locator('#ev-sched-category').selectOption(leadless);
    await expect(leads).toContainText('None assigned');
    await page.locator('#ev-sched-category').selectOption('Battlegame');
    await page.locator('#ev-sched-title').fill(`${PREFIX} modal lead`);
    await page.locator('#ev-sched-save-btn').click();
    await expect(page.locator('#ev-schedule-modal')).toBeHidden();
    const stored = await page.evaluate(async title => {
      const c = (window as any).EvConfig;
      const list = await (await fetch(c.uir + 'EventAjax/schedule_list/' + c.eventId + '/' + c.detailId, {
        credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })).json();
      const it = (list.schedule || []).find((x: any) => x.Title === title);
      return it ? it.Leads.map((l: any) => [l.Persona, !!l.Auto]) : null;
    }, `${PREFIX} modal lead`);
    expect(stored && sortedLeads(stored)).toEqual(want);
  });

  test('an unresolved @word is kept as typed when you click away', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SATURDAY = await dayOf(page, 'sat');
    await page.locator('.evb-edit-btn').click();
    await expect(page.locator('.evb-modebar')).toBeVisible();
    const away = page.locator('.evb-modebar-title');

    // A new block on the board.
    await clickSlot(page, SATURDAY, '18:00');
    const editor = page.locator('.evb-block-editing .evb-entry-input');
    await expect(editor).toBeFocused();
    await editor.pressSequentially(`${PREFIX} talk @zzqqx`);
    await expect(page.locator('.evb-type-pop .evb-entry-menu')).toBeVisible();
    await away.click();
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    expect(await storeItem(page, `${PREFIX} talk @zzqqx`)).not.toBeNull();

    // The quick line: away, back, Enter.
    const line = page.locator('.evb-quickline .evb-entry-input');
    await line.click();
    await line.pressSequentially(`sat 7p ${PREFIX} line @zzqqx`);
    await away.click();
    await line.click();
    await line.press('End');
    await line.press('Enter');
    await expect(line).toHaveValue('');
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    expect(await storeItem(page, `${PREFIX} line @zzqqx`)).not.toBeNull();

    // A rename.
    await quickAdd(page, `sun 3p ${PREFIX} rename keep`);
    await page.locator('.evb-block', { hasText: `${PREFIX} rename keep` }).dblclick();
    await expect(editor).toBeFocused();
    await editor.press('End');
    await editor.pressSequentially(' @zzqqx');
    await away.click();
    await expect(page.locator('.evb-block-editing')).toHaveCount(0);
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    expect(await storeItem(page, `${PREFIX} rename keep @zzqqx`)).not.toBeNull();
  });

  test('changing the category in the full form swaps auto leads', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await quickAdd(page, `sat 4p bg: ${PREFIX} modal swap`);
    const title = `${PREFIX} modal swap`;
    const autos = () => page.evaluate(t => {
      const it = (window as any).EvBoardEdit.store.items().find((i: any) => i.Title === t);
      return it ? it.Leads.map((l: any) => !!l.Auto) : null;
    }, title);
    // Each category's auto leads from the staff list Edit mode read, so this also runs alone.
    const bg = (await autoLeadsFor(page, 'Battlegame')).map(() => true);
    const court = (await autoLeadsFor(page, 'Court')).map(() => true);
    expect(await autos()).toEqual(bg);
    const block = page.locator('.evb-block', { hasText: title });
    const modal = page.locator('#ev-schedule-modal');
    for (const [cat, want] of [['Court', court], ['Battlegame', bg]] as const) {
      await block.click();
      await page.locator('.evb-block-toolbar [data-evb-act="details"]').click();
      await expect(modal).toBeVisible();
      await page.locator('#ev-sched-category').selectOption(cat);
      await page.locator('#ev-sched-save-btn').click();
      await expect(modal).toBeHidden();
      await expect(block).toHaveAttribute('data-category', cat);
      await expect.poll(autos).toEqual(want);
    }
  });

  // One rule for a new item's leads: the hand-picked ones plus the category's as auto
  // leads. A second, made-up staff member is added to the page only (nothing with
  // that lead is saved: the batch is refused here).
  const FAKE_LEAD = { MundaneId: 999999991, Persona: 'Zed Fakelead', LeadCategories: [] };
  async function withFakeStaff(page: Page): Promise<number> {
    return page.evaluate(fake => {
      const c = (window as any).EvConfig;
      c.staffList = c.staffList.concat([fake]);
      return +c.staffList.find((s: any) => s.Persona === 'E2E Schedule Editor').MundaneId;
    }, FAKE_LEAD);
  }
  test('bulk Add all gives hand-picked leads plus the category\'s', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await expect(page.locator('.evb-quickline .evb-entry-input')).toBeFocused();
    // After Edit mode opens: it takes the staff list from the server's read.
    await withFakeStaff(page);
    // The Battlegame leads, in staff-list order, from that list (so this also runs alone).
    const autoIds = await page.evaluate(() => ((window as any).EvConfig.staffList || [])
      .filter((s: any) => (s.LeadCategories || []).includes('Battlegame')).map((s: any) => [+s.MundaneId, true]));
    await page.route(/schedule_batch/, route => route.abort());
    await pasteText(page, ['Sunday', `6a bg: ${PREFIX} bulk both @Zed Fakelead`, `7a bg: ${PREFIX} bulk auto`].join('\n'));
    await expect(page.locator('.evb-bulkbar')).toContainText('2 items ready');
    await page.locator('.evb-bulk-add').click();
    const leads = await page.evaluate(prefix => {
      const items = (window as any).EvBoardEdit.store.items();
      const of = (t: string) => items.find((i: any) => i.Title === `${prefix} ${t}`).Leads.map((l: any) => [+l.MundaneId, !!l.Auto]);
      return [of('bulk both'), of('bulk auto')];
    }, PREFIX);
    expect(leads).toEqual([[[FAKE_LEAD.MundaneId, false], ...autoIds], autoIds]);
  });
  test('the schedule modal adds the category\'s leads to hand-picked ones', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await withFakeStaff(page);
    // A second made-up staff member, leading Battlegame on this page only (nothing
    // is saved here), so the category always has an auto lead to fill and to remove
    // by hand, whether or not the test account leads it yet.
    const CAT_LEAD = { MundaneId: 999999992, Persona: 'Yan Fakelead', LeadCategories: ['Battlegame'] };
    await page.evaluate(f => { const c = (window as any).EvConfig; c.staffList = c.staffList.concat([f]); }, CAT_LEAD);
    const want = await autoLeadsFor(page, 'Battlegame');
    expect(want).toContainEqual([CAT_LEAD.Persona, true]);
    await page.evaluate(() => (window as any).evOpenScheduleModal());
    const leads = page.locator('#ev-sched-leads-list');
    await page.evaluate(f => (window as any).evStaffQuickAddLead(f.MundaneId, encodeURIComponent(f.Persona)), FAKE_LEAD);
    await expect(leads).toContainText('Zed Fakelead');
    await page.locator('#ev-sched-category').selectOption('Battlegame');
    await expect(leads).toContainText('Zed Fakelead');
    for (const [persona] of want) await expect(leads).toContainText(persona);
    await expect(leads).toContainText('auto');
    // An auto lead removed by hand is not filled in again.
    await page.evaluate(id => (window as any).evRemoveSchedLead(id), CAT_LEAD.MundaneId);
    await page.locator('#ev-sched-category').selectOption('Class');
    await page.locator('#ev-sched-category').selectOption('Battlegame');
    await expect(leads).toContainText('Zed Fakelead');
    await expect(leads).not.toContainText(CAT_LEAD.Persona);
  });

  // ---- Phase 4: drag, resize and nudge ----

  test('dragging a block moves it and saves once', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await addViaQuickLine(page, `fri 9-10 ${PREFIX} drag me`);
    const block = page.locator('.evb-block', { hasText: `${PREFIX} drag me` });
    // The mouse does not scroll: bring the block on screen first.
    await block.scrollIntoViewIfNeeded();
    const box = (await block.boundingBox())!;
    const pxPerMin = await page.evaluate(() => (window as any).EvBoardView.PX_PER_MIN);
    await page.mouse.move(box.x + box.width / 2, box.y + 6);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width / 2, box.y + 6 + 90 * pxPerMin, { steps: 8 });
    await expect(page.locator('.evb-drag-ghost')).toBeVisible();
    await page.mouse.up();
    const FRIDAY = await dayOf(page, 'fri');
    await expect.poll(() => timesOf(page, `${PREFIX} drag me`)).toEqual([`${FRIDAY} 10:30`, `${FRIDAY} 11:30`]);
    await expectOnServer(page, `${PREFIX} drag me`, { StartTime: `${FRIDAY} 10:30`, EndTime: `${FRIDAY} 11:30`, Category: 'Other', Leads: [] });
    await page.locator('.evb-undo').click();
    await expect.poll(() => timesOf(page, `${PREFIX} drag me`)).toEqual([`${FRIDAY} 09:00`, `${FRIDAY} 10:00`]);
    await expectOnServer(page, `${PREFIX} drag me`, { StartTime: `${FRIDAY} 09:00`, EndTime: `${FRIDAY} 10:00`, Category: 'Other', Leads: [] });
  });
  test('Escape cancels a drag; the bottom grip resizes', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const FRIDAY = await dayOf(page, 'fri');
    await page.locator('.evb-edit-btn').click();
    await addViaQuickLine(page, `fri 1-2p ${PREFIX} resize me`);
    const block = page.locator('.evb-block', { hasText: `${PREFIX} resize me` });
    // The mouse does not scroll: bring the block on screen first.
    await block.scrollIntoViewIfNeeded();
    const box = (await block.boundingBox())!;
    const pxPerMin = await page.evaluate(() => (window as any).EvBoardView.PX_PER_MIN);
    await page.mouse.move(box.x + box.width / 2, box.y + 6);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width / 2, box.y + 80, { steps: 5 });
    await page.keyboard.press('Escape');
    await page.mouse.up();
    expect(await timesOf(page, `${PREFIX} resize me`)).toEqual([`${FRIDAY} 13:00`, `${FRIDAY} 14:00`]);
    await block.click();
    const grip = (await block.locator('.evb-resize-bottom').boundingBox())!;
    await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2);
    await page.mouse.down();
    await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2 + 30 * pxPerMin, { steps: 5 });
    await page.mouse.up();
    await expect.poll(() => timesOf(page, `${PREFIX} resize me`)).toEqual([`${FRIDAY} 13:00`, `${FRIDAY} 14:30`]);
    await expectOnServer(page, `${PREFIX} resize me`, { StartTime: `${FRIDAY} 13:00`, EndTime: `${FRIDAY} 14:30`, Category: 'Other', Leads: [] });
  });
  test('arrow keys nudge the selected block', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SATURDAY = await dayOf(page, 'sat');
    await page.locator('.evb-edit-btn').click();
    await addViaQuickLine(page, `fri 3-4p ${PREFIX} nudge me`);
    await page.locator('.evb-block', { hasText: `${PREFIX} nudge me` }).click();
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Shift+ArrowDown');
    await page.keyboard.press('Alt+ArrowUp');
    await page.keyboard.press('ArrowRight');
    await expect.poll(() => timesOf(page, `${PREFIX} nudge me`)).toEqual([`${SATURDAY} 15:10`, `${SATURDAY} 16:25`]);
    await expectOnServer(page, `${PREFIX} nudge me`, { StartTime: `${SATURDAY} 15:10`, EndTime: `${SATURDAY} 16:25`, Category: 'Other', Leads: [] });
    // Shift+Up shortens it, never under 5 minutes.
    for (let i = 0; i < 8; i++) await page.keyboard.press('Shift+ArrowUp');
    await expect.poll(() => timesOf(page, `${PREFIX} nudge me`)).toEqual([`${SATURDAY} 15:10`, `${SATURDAY} 15:15`]);
    await expectOnServer(page, `${PREFIX} nudge me`, { StartTime: `${SATURDAY} 15:10`, EndTime: `${SATURDAY} 15:15`, Category: 'Other', Leads: [] });
  });
  // Presses the middle of a block and moves the pointer by (dx, dy) in steps; returns the block's box.
  async function pressAndMove(page: Page, title: string, dx: number, dy: number) {
    const block = page.locator('.evb-block', { hasText: title });
    await block.scrollIntoViewIfNeeded();
    const box = (await block.boundingBox())!;
    const x = box.x + box.width / 2, y = box.y + 10;
    await page.mouse.move(x, y);
    await page.mouse.down();
    await page.mouse.move(x + dx, y + dy, { steps: 6 });
    return { x, y };
  }
  test('releasing a drag beside the board cancels it', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const FRIDAY = await dayOf(page, 'fri');
    await page.locator('.evb-edit-btn').click();
    // Not '… beside': the Tab test above already has an item by that name.
    const title = `${PREFIX} dropped off board`;
    await addViaQuickLine(page, `fri 10-11 ${title}`);
    const p = await pressAndMove(page, title, 0, 40);
    await expect(page.locator('.evb-drag-ghost')).toBeVisible();
    // Over the hour gutter, left of every day column.
    const frame = (await page.locator('.evb-scroller').boundingBox())!;
    await page.mouse.move(frame.x + 20, p.y + 40, { steps: 4 });
    await page.mouse.up();
    await expect(page.locator('.evb-drag-ghost')).toHaveCount(0);
    expect(await timesOf(page, title)).toEqual([`${FRIDAY} 10:00`, `${FRIDAY} 11:00`]);
  });
  test('dragging a block back to where it started saves nothing', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const FRIDAY = await dayOf(page, 'fri');
    await page.locator('.evb-edit-btn').click();
    const title = `${PREFIX} round trip`;
    await addViaQuickLine(page, `fri 12-1p ${title}`);
    const pxPerMin = await page.evaluate(() => (window as any).EvBoardView.PX_PER_MIN);
    let batches = 0;
    page.on('request', r => { if (r.url().includes('schedule_batch')) batches++; });
    const p = await pressAndMove(page, title, 0, 60 * pxPerMin);
    await expect(page.locator('.evb-drag-ghost')).toBeVisible();
    await page.mouse.move(p.x, p.y, { steps: 6 });
    await page.mouse.up();
    await page.waitForTimeout(1000);
    expect(batches).toBe(0);
    expect(await timesOf(page, title)).toEqual([`${FRIDAY} 12:00`, `${FRIDAY} 13:00`]);
  });
  test('a Shift-press on a block does not start a drag', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const FRIDAY = await dayOf(page, 'fri');
    await page.locator('.evb-edit-btn').click();
    const title = `${PREFIX} shift press`;
    await addViaQuickLine(page, `fri 4-5p ${title}`);
    await page.keyboard.down('Shift');
    await pressAndMove(page, title, 0, 40);
    await expect(page.locator('.evb-drag-ghost')).toHaveCount(0);
    await page.mouse.up();
    await page.keyboard.up('Shift');
    expect(await timesOf(page, title)).toEqual([`${FRIDAY} 16:00`, `${FRIDAY} 17:00`]);
  });

  // ---- Phase 4: duplicate, multi-select, copy and paste, repeat, slot cursor ----

  test('duplicate, shift-select and group delete are each one undo step', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await addViaQuickLine(page, `sun 9-9:30 bg: ${PREFIX} dup at Quarry`);
    const dup = page.locator('.evb-block', { hasText: `${PREFIX} dup` });
    await dup.click();
    await page.keyboard.press('ControlOrMeta+d');
    await expect(dup).toHaveCount(2);
    const copies = await page.evaluate((t) => (window as any).EvBoardEdit.store.items().filter((i: any) => i.Title === t)
      .map((i: any) => [i.StartTime.slice(11, 16), i.Location, i.Category]).sort(), `${PREFIX} dup`);
    expect(copies).toEqual([['09:00', 'Quarry', 'Battlegame'], ['09:30', 'Quarry', 'Battlegame']]);
    await dup.first().click();
    await dup.nth(1).click({ modifiers: ['Shift'] });
    await expect(page.locator('.evb-selected')).toHaveCount(2);
    await page.keyboard.press('Delete');
    await expect(dup).toHaveCount(0);
    await page.locator('.evb-undo').click();
    await expect(dup).toHaveCount(2);
    // Both are back on the server, as the board shows them.
    const back = await expectServerMatchesBoard(page, `${PREFIX} dup`, { count: 2 });
    expect(back.map(r => r.Category)).toEqual(['Battlegame', 'Battlegame']);
  });
  test('copy and paste at the slot cursor keeps spacing', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await addViaQuickLine(page, `fri 6-6:30p ${PREFIX} copy a`);
    await addViaQuickLine(page, `fri 7-7:30p ${PREFIX} copy b`);
    await page.locator('.evb-block', { hasText: `${PREFIX} copy a` }).click();
    await page.locator('.evb-block', { hasText: `${PREFIX} copy b` }).click({ modifiers: ['Shift'] });
    await page.keyboard.press('ControlOrMeta+c');
    await page.keyboard.press('Escape');
    await page.keyboard.press('ArrowRight');
    await expect(page.locator('.evb-slot-cursor')).toBeVisible();
    const cursor = await page.evaluate(() => {
      const el = document.querySelector('.evb-slot-cursor') as HTMLElement;
      return [el.getAttribute('data-day'), Number(el.getAttribute('data-min'))];
    });
    await page.keyboard.press('ControlOrMeta+v');
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} copy a` })).toHaveCount(2);
    const starts = await page.evaluate(([t, day]) => (window as any).EvBoardEdit.store.items()
      .filter((i: any) => i.Title.startsWith(t) && i.StartTime.startsWith(day)).map((i: any) => (window as any).EvBoardCore.boardPos(i.StartTime).min).sort((x: number, y: number) => x - y),
      [`${PREFIX} copy`, cursor[0] as string]);
    expect(starts).toEqual([cursor[1], (cursor[1] as number) + 60]);
    // The pasted copies are on the server as the board shows them.
    await expectServerMatchesBoard(page, `${PREFIX} copy a`, { count: 2 });
    await expectServerMatchesBoard(page, `${PREFIX} copy b`, { count: 2 });
  });
  test('typing at the slot cursor starts an item; shortcuts leave text alone', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await addViaQuickLine(page, `sun 2-3p ${PREFIX} keep while typing`);
    await page.locator('.evb-block', { hasText: `${PREFIX} keep while typing` }).click();
    const line = page.locator('.evb-quickline .evb-entry-input');
    await line.click();
    await line.type('sun 5p 3d printing');
    await line.press('Backspace');
    await expect(line).toHaveValue('sun 5p 3d printin');
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} keep while typing` })).toHaveCount(1);
    await line.fill('');
    await line.press('Escape');
    await page.locator('.evb-board').click({ position: { x: 4, y: 4 } });
    await page.keyboard.press('Escape');
    await page.keyboard.press('ArrowDown');
    await page.keyboard.type(`${PREFIX} from cursor`);
    await expect(page.locator('.evb-block-editing .evb-entry-input')).toHaveValue(`${PREFIX} from cursor`);
    await page.keyboard.press('Enter');
    await page.keyboard.press('Escape');
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} from cursor` })).toHaveCount(1);
  });
  test('the fill handle repeats an item down the day', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    // An earlier Battlegame of this run on Saturday would change the rhythm: clear them first.
    const SATURDAY = await dayOf(page, 'sat');
    await expect(page.locator('.evb-modebar')).toBeVisible();
    await page.evaluate(([prefix, day]) => {
      const w = window as any, store = w.EvBoardEdit.store;
      store.batch(() => store.items()
        .filter((i: any) => String(i.Title).startsWith(prefix) && w.EvBoardCore.boardPos(i.StartTime).day === day)
        .forEach((i: any) => store.remove(i.cid)));
    }, [PREFIX, SATURDAY]);
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    await addViaQuickLine(page, `sat 5-5:30p bg: ${PREFIX} repeat`);
    const block = page.locator('.evb-block', { hasText: `${PREFIX} repeat` });
    // The mouse does not scroll, and the frame scrolls by itself near its bottom: start mid-window.
    await block.evaluate(el => el.scrollIntoView({ block: 'center' }));
    await block.click();
    const h = (await page.locator('.evb-fill-handle').boundingBox())!;
    const pxPerMin = await page.evaluate(() => (window as any).EvBoardView.PX_PER_MIN);
    await page.mouse.move(h.x + h.width / 2, h.y + h.height / 2);
    await page.mouse.down();
    await page.mouse.move(h.x + h.width / 2, h.y + h.height / 2 + 125 * pxPerMin, { steps: 8 });
    await expect(page.locator('.evb-fill-ghost')).toHaveCount(2);
    await page.mouse.up();
    await expect(block).toHaveCount(3);
    // Three on the server, each a Battlegame led automatically by the event's
    // Battlegame leads (from the page's staff list; none means no leads).
    const wantLeads = await autoLeadsFor(page, 'Battlegame');
    const rows = await expectServerMatchesBoard(page, `${PREFIX} repeat`, { count: 3 });
    expect({ ...rows[0], Leads: sortedLeads(rows[0].Leads) }).toEqual({ StartTime: `${SATURDAY} 17:00`, EndTime: `${SATURDAY} 17:30`,
      Category: 'Battlegame', Leads: wantLeads });
    expect(rows.every(r => r.Category === 'Battlegame' && JSON.stringify(sortedLeads(r.Leads)) === JSON.stringify(wantLeads))).toBe(true);
    await page.locator('.evb-undo').click();
    await expect(block).toHaveCount(1);
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    await expect.poll(async () => (await serverItems(page, `${PREFIX} repeat`)).length).toBe(1);
  });
  test('Esc cancels a repeat; a group gets a count badge, a category key and Cmd+A', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await addViaQuickLine(page, `fri 2-2:30p ${PREFIX} grp one`);
    await addViaQuickLine(page, `fri 3-3:30p ${PREFIX} grp two`);
    const one = page.locator('.evb-block', { hasText: `${PREFIX} grp one` });
    const two = page.locator('.evb-block', { hasText: `${PREFIX} grp two` });
    await one.evaluate(el => el.scrollIntoView({ block: 'center' }));
    await one.click();
    const h = (await page.locator('.evb-fill-handle').boundingBox())!;
    await page.mouse.move(h.x + h.width / 2, h.y + h.height / 2);
    await page.mouse.down();
    // Far enough for one copy whatever gap an earlier item of this run sets (up to 2h).
    await page.mouse.move(h.x + h.width / 2, h.y + 200, { steps: 6 });
    await expect(page.locator('.evb-fill-ghost').first()).toBeVisible();
    await page.keyboard.press('Escape');
    await page.mouse.up();
    await expect(page.locator('.evb-fill-ghost')).toHaveCount(0);
    await expect(one).toHaveCount(1);

    await two.click({ modifiers: ['Shift'] });
    await expect(page.locator('.evb-sel-badge')).toHaveText(/2 selected/);
    await expect(page.locator('.evb-block-toolbar')).toBeHidden();
    await expect(page.locator('.evb-fill-handle')).toHaveCount(0);
    await page.keyboard.press('5');
    await expect(one).toHaveAttribute('data-category', 'Class');
    await expect(two).toHaveAttribute('data-category', 'Class');
    await page.locator('.evb-undo').click();
    await expect(one).not.toHaveAttribute('data-category', 'Class');
    await expect(two).not.toHaveAttribute('data-category', 'Class');
    // Shift-click takes one out again.
    await two.click({ modifiers: ['Shift'] });
    await expect(page.locator('.evb-selected')).toHaveCount(1);
    await expect(page.locator('.evb-block-toolbar [data-evb-act="duplicate"]')).toBeVisible();
    await expect(page.locator('.evb-slot-cursor')).toHaveCount(0);

    await page.keyboard.press('ControlOrMeta+a');
    const all = await page.evaluate(() => (window as any).EvBoardEdit.store.items().length);
    await expect(page.locator('.evb-sel-badge .evb-sel-count')).toHaveText(`${all} selected`);
    await page.keyboard.press('Escape');
    await expect(page.locator('.evb-selected')).toHaveCount(0);
    await expect(page.locator('.evb-slot-cursor')).toHaveCount(1);
  });
  test('a group delete asks once for meal items and undoes only the others', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    test.skip(!(await page.evaluate(() => !!(window as any).EvConfig.canManageFeast)), 'the test account cannot save a menu');
    const SUNDAY = await dayOf(page, 'sun');
    const [stew, games] = await addViaBatch(page, [
      { Title: `${PREFIX} group stew`, StartTime: `${SUNDAY} 11:00`, EndTime: `${SUNDAY} 12:00`,
        Category: 'Feast and Food', SecondaryCategory: '', Location: '', Description: '', Leads: [], Menu: 'Stew' },
      { Title: `${PREFIX} group games`, StartTime: `${SUNDAY} 12:00`, EndTime: `${SUNDAY} 13:00`,
        Category: 'Other', SecondaryCategory: '', Location: '', Description: '', Leads: [] },
    ]);
    await page.evaluate(() => { (window as any).EvConfig.canManageFeast = false; });
    await page.locator('.evb-edit-btn').click();
    await expect(page.locator('.evb-modebar')).toBeVisible();
    const a = page.locator(`.evb-block[data-id="${stew}"]`), b = page.locator(`.evb-block[data-id="${games}"]`);
    await a.click();
    await b.click({ modifiers: ['Shift'] });
    await page.keyboard.press('Delete');
    const confirm = page.locator('#ork-confirm-overlay.ork-confirm-open');
    await expect(confirm.locator('#ork-confirm-msg'))
      .toHaveText('One of these items has menu and dietary details that you will not be able to restore. Remove all 2?');
    await confirm.locator('#ork-confirm-ok').click();
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} group ` })).toHaveCount(0);
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    await page.locator('.evb-undo').click();
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} group games` })).toHaveCount(1);
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} group stew` })).toHaveCount(0);
  });

  // ---- Phase 4 review fixes ----

  test('a two-block group dragged against the bottom of the axis keeps its spacing', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    // Sunday is the last shown day: gestures keep to the board's days
    // (EvBoardCore.boardRange), so its range ends Monday 05:00 (1740). An item
    // ending at 5am first makes the axis reach that end.
    await addViaQuickLine(page, `sun 11:30p-5a ${PREFIX} late anchor`);
    await addViaQuickLine(page, `sun 9-10p ${PREFIX} late pair a`);
    await addViaQuickLine(page, `sun 10:30-11:30p ${PREFIX} late pair b`);
    const a = page.locator('.evb-block', { hasText: `${PREFIX} late pair a` });
    const b = page.locator('.evb-block', { hasText: `${PREFIX} late pair b` });
    await a.evaluate(el => el.scrollIntoView({ block: 'center' }));
    await a.click();
    await b.click({ modifiers: ['Shift'] });
    const [axisEnd, dayEnd] = await page.evaluate(() => [(window as any).EvBoardView.board.axisEnd, (window as any).EvBoardCore.BOARD_DAY_END]);
    expect(axisEnd).toBe(dayEnd);
    const box = (await a.boundingBox())!;
    const pxPerMin = await page.evaluate(() => (window as any).EvBoardView.PX_PER_MIN);
    await page.mouse.move(box.x + box.width / 2, box.y + 10);
    await page.mouse.down();
    // Well below the column's bottom: the pointer minute is clamped to the axis.
    await page.mouse.move(box.x + box.width / 2, box.y + 10 + 400 * pxPerMin, { steps: 12 });
    await page.mouse.up();
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    const [pa, pb] = await page.evaluate(t => {
      const w = window as any, items = w.EvBoardEdit.store.items();
      const pos = (title: string) => {
        const sp = w.EvBoardCore.span(items.find((i: any) => i.Title === title));
        return [sp.day, sp.start, sp.end];
      };
      return [pos(t + 'a'), pos(t + 'b')];
    }, `${PREFIX} late pair `);
    const SUNDAY = await dayOf(page, 'sun');
    expect([pa[0], pb[0]]).toEqual([SUNDAY, SUNDAY]);
    expect(pb[2]).toBe(dayEnd);
    expect((pb[1] as number) - (pa[1] as number)).toBe(90);
    expect(pa[1]).toBe(dayEnd - 150);
    await expectServerMatchesBoard(page, `${PREFIX} late `, { prefix: true, count: 3 });
  });
  test('a copy of an item with no length has no length, five minutes on', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await expect(page.locator('.evb-modebar')).toBeVisible();
    const title = `${PREFIX} instant`;
    // Out of the quick line, which keeps its own keys.
    await page.locator('.evb-board').click({ position: { x: 4, y: 4 } });
    const SUNDAY = await dayOf(page, 'sun');
    await page.evaluate(([t, day]) => {
      const w = window as any;
      const cid = w.EvBoardEdit.store.add({ Title: t, StartTime: `${day} 08:00`, EndTime: `${day} 08:00`, Category: 'Other',
        SecondaryCategory: '', Location: '', Description: '', Leads: [] });
      w.EvBoardSelect.select([cid]);
    }, [title, SUNDAY]);
    await page.keyboard.press('ControlOrMeta+d');
    await expect(page.locator('.evb-block', { hasText: title })).toHaveCount(2);
    const times = await page.evaluate(t => (window as any).EvBoardEdit.store.items().filter((i: any) => i.Title === t)
      .map((i: any) => [String(i.StartTime).slice(11, 16), String(i.EndTime).slice(11, 16)]).sort(), title);
    expect(times).toEqual([['08:00', '08:00'], ['08:05', '08:05']]);
  });
  test('pasting with the copied blocks still selected puts the copies right after them', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await addViaQuickLine(page, `sat 7-7:30a ${PREFIX} early one`);
    await addViaQuickLine(page, `sat 8-8:30a ${PREFIX} early two`);
    await page.locator('.evb-block', { hasText: `${PREFIX} early one` }).click();
    await page.locator('.evb-block', { hasText: `${PREFIX} early two` }).click({ modifiers: ['Shift'] });
    await page.keyboard.press('ControlOrMeta+c');
    await page.keyboard.press('ControlOrMeta+v');
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} early one` })).toHaveCount(2);
    const starts = await page.evaluate(t => (window as any).EvBoardEdit.store.items()
      .filter((i: any) => i.Title.startsWith(t)).map((i: any) => String(i.StartTime).slice(0, 16)).sort(), `${PREFIX} early `);
    const SATURDAY = await dayOf(page, 'sat');
    const want = ['07:00', '08:00', '08:30', '09:30'].map(t => `${SATURDAY} ${t}`);
    expect(starts).toEqual(want);
    // And on the server, as the board shows them.
    const rows = await expectServerMatchesBoard(page, `${PREFIX} early `, { prefix: true, count: 4 });
    expect(rows.map(r => r.StartTime)).toEqual(want);
  });
  // Dispatches a paste of text on the page; returns whether something default-prevented it.
  async function pasteText(page: Page, text: string): Promise<boolean> {
    return page.evaluate(t => {
      const dt = new DataTransfer();
      dt.setData('text/plain', t);
      const e = new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true });
      document.body.dispatchEvent(e);
      return e.defaultPrevented;
    }, text);
  }
  test('pasting other text on the board adds nothing', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await addViaQuickLine(page, `fri 8-8:30a ${PREFIX} paste guard`);
    const count = () => page.evaluate(() => (window as any).EvBoardEdit.store.items().length);
    const before = await count();
    await page.locator('.evb-board').click({ position: { x: 4, y: 4 } });
    expect(await pasteText(page, 'hello world')).toBe(false);
    await page.locator('.evb-block', { hasText: `${PREFIX} paste guard` }).click();
    await page.keyboard.press('ControlOrMeta+c');
    expect(await pasteText(page, 'hello world')).toBe(false);
    expect(await count()).toBe(before);
  });
  test('copying with text selected on the page leaves the browser\'s copy alone', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await addViaQuickLine(page, `fri 8:30-9a ${PREFIX} copy guard`);
    await page.locator('.evb-block', { hasText: `${PREFIX} copy guard` }).click();
    await page.evaluate(() => {
      const w = window as any;
      w.__copy = 'none';
      document.addEventListener('copy', (e: Event) => { w.__copy = e.defaultPrevented ? 'prevented' : 'browser'; });
      const r = document.createRange();
      r.selectNodeContents(document.querySelector('.evb-modebar-title')!);
      const sel = window.getSelection()!;
      sel.removeAllRanges();
      sel.addRange(r);
    });
    await page.keyboard.press('ControlOrMeta+c');
    await expect.poll(() => page.evaluate(() => (window as any).__copy)).toBe('browser');
  });
  test('select-all inside the quick line selects its text, not every block', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await addViaQuickLine(page, `fri 7-7:30a ${PREFIX} select all guard`);
    const line = page.locator('.evb-quickline .evb-entry-input');
    await line.fill('sat 9a something');
    await line.press('ControlOrMeta+a');
    await expect(page.locator('.evb-selected')).toHaveCount(0);
    expect(await line.evaluate((el: HTMLInputElement) => [el.selectionStart, el.selectionEnd])).toEqual([0, 'sat 9a something'.length]);
    await line.fill('');
  });

  // ---- Phase 5: bulk paste, skeleton, start from a past event, conflicts ----
  test('pasted lines preview as ghosts and are added in one undoable step', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    const text = ['Sunday', `1p ${PREFIX} pasted one`, `then 30m ${PREFIX} pasted two`, '???', `3-4 class: ${PREFIX} pasted three @Nobody`].join('\n');
    // Edit mode opens after the schedule is read.
    await expect(page.locator('.evb-quickline .evb-entry-input')).toBeFocused();
    await page.evaluate((t) => {
      const dt = new DataTransfer(); dt.setData('text/plain', t);
      document.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
    }, text);
    await expect(page.locator('.evb-ghost')).toHaveCount(3);
    await expect(page.locator('.evb-bulkbar')).toContainText('3 items ready');
    await expect(page.locator('.evb-bulk-unread')).toContainText('Line 4: ???');
    await expect(page.locator('.evb-bulk-leads')).toContainText('Nobody');
    // Ghosts are dashed .evb-block elements too; nothing real is added yet. ('pasted', not
    // 'paste': an earlier test leaves a 'paste guard' block.)
    expect(await page.locator('.evb-block:not(.evb-ghost)', { hasText: `${PREFIX} pasted` }).count()).toBe(0);
    await page.locator('.evb-bulk-add').click();
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} pasted` })).toHaveCount(3);
    await expect(page.locator('.evb-ghost')).toHaveCount(0);
    // All three on the server as the board shows them: the first at 1pm, the last a Class.
    const SUNDAY = await dayOf(page, 'sun');
    const rows = await expectServerMatchesBoard(page, `${PREFIX} pasted `, { prefix: true, count: 3 });
    expect(rows[0].StartTime).toBe(`${SUNDAY} 13:00`);
    expect(rows.map(r => r.Category)).toEqual(['Other', 'Other', 'Class']);
    await page.locator('.evb-undo').click();
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} pasted` })).toHaveCount(0);
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    await expect.poll(async () => (await serverItems(page, `${PREFIX} pasted `, { prefix: true })).length).toBe(0);
  });
  test('the skeleton can be discarded; double-booked places are flagged', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await page.locator('.evb-more-btn').click();
    await page.locator('[data-evb-more="skeleton"]').click();
    expect(await page.locator('.evb-ghost').count()).toBeGreaterThan(5);
    await page.locator('.evb-bulk-discard').click();
    await expect(page.locator('.evb-ghost')).toHaveCount(0);
    // A place no other test uses: 'Hall' would also suggest an earlier test's 'Hall #2'.
    await addViaQuickLine(page, `sun 5-6p ${PREFIX} clash a at Smithy`);
    await addViaQuickLine(page, `sun 5:30-6:30p ${PREFIX} clash b at smithy`);
    const badge = page.locator('.evb-block', { hasText: `${PREFIX} clash a` }).locator('.evb-conflict');
    await expect(badge).toHaveAttribute('data-tip', new RegExp(`Smithy is also booked for ${PREFIX} clash b`));
  });
  test('a past schedule can be copied in and undone', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await page.locator('.evb-more-btn').click();
    await page.locator('[data-evb-more="past-event"]').click();
    await expect(page.locator('.evb-copy-dialog')).toBeVisible();
    const sources = page.locator('.evb-copy-source');
    // The list has loaded when it shows sources, or says there are none.
    await expect(page.locator('.evb-copy-source, .evb-copy-none').first()).toBeVisible();
    test.skip((await sources.count()) === 0, 'no past schedule in scope for the test event');
    // Copied rows keep their source titles, so the prefix clean-up would miss them.
    // The copy's reply ({ status: 0, schedule: [row, ...], skippedFeast }) lists
    // exactly the rows it made, each with its EventScheduleId: those ids, and only
    // those, are checked after Undo and removed here if any are left.
    const replyP = page.waitForResponse(/schedule_copy_into/);
    replyP.catch(() => { /* a click that never copied: awaited below */ });
    let copied: number[] = [];
    try {
      const before = await page.locator('.evb-block').count();
      await sources.first().click();
      const reply = await (await replyP).json();
      expect(reply.status).toBe(0);
      copied = (reply.schedule || []).map((r: any) => +r.EventScheduleId).filter((id: number) => id > 0);
      expect(copied.length).toBeGreaterThan(0);
      // The notice only: '.evb-notice, .evb-modebar' would match two elements.
      await expect(page.locator('.evb-edit-head .evb-notice')).toContainText(/Added \d+ items? from/);
      expect(await page.locator('.evb-block').count()).toBeGreaterThan(before);
      await page.locator('.evb-undo').click();
      await expect(page.locator('.evb-block')).toHaveCount(before);
      await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
      // Undo took the copied rows off the server too.
      await expect.poll(async () => (await serverRows(page))
        .filter(r => copied.includes(+r.EventScheduleId)).length).toBe(0);
    } finally {
      if (copied.length) {
        const left = (await serverRows(page))
          .map(r => +r.EventScheduleId)
          .filter(id => copied.includes(id));
        await removeIds(page, left);
      }
    }
  });
  // The dialog's list and a refused copy, whatever past schedules the local
  // database holds: both endpoints are answered here, so nothing is written.
  test('the past-event dialog groups its sources and shows a refused copy', async ({ page }) => {
    await page.route(/EventAjax\/schedule_copy_sources\//, route => route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ status: 0, sources: [
        { EventId: 1, EventCalendarDetailId: 11, Name: 'Other <b>Fest</b>', StartDate: '2025-05-02', ItemCount: 4, SameEvent: false },
        { EventId: 2, EventCalendarDetailId: 22, Name: 'This Event', StartDate: '2025-11-07', ItemCount: 1, SameEvent: true },
      ] }),
    }));
    await page.route(/EventAjax\/schedule_copy_into\//, route => route.fulfill({
      contentType: 'application/json', body: JSON.stringify({ status: 3, error: 'You may not copy from that event.' }),
    }));
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await page.locator('.evb-more-btn').click();
    await expect(page.locator('.evb-more-btn')).toHaveAttribute('aria-expanded', 'true');
    await page.locator('[data-evb-more="past-event"]').click();
    const dialog = page.locator('.evb-copy-dialog');
    await expect(dialog).toBeVisible();
    const sources = dialog.locator('.evb-copy-source');
    await expect(sources).toHaveCount(2);
    // This event's own occurrences first; names are text, never markup.
    await expect(sources.nth(0)).toContainText('This Event');
    await expect(sources.nth(0)).toContainText('Nov 7, 2025 · 1 item');
    await expect(sources.nth(1)).toContainText('Other <b>Fest</b>');
    await expect(dialog.locator('.evb-copy-group').nth(0)).toHaveText('This event');
    await expect(dialog.locator('.evb-copy-group').nth(1)).toHaveText('Other events here');
    const before = await page.evaluate(() => (window as any).EvBoardEdit.store.items().length);
    await sources.nth(1).click();
    await expect(dialog.locator('.evb-copy-error')).toHaveText('You may not copy from that event.');
    await expect(dialog).toBeVisible();
    expect(await page.evaluate(() => (window as any).EvBoardEdit.store.items().length)).toBe(before);
    await page.keyboard.press('Escape');
    await expect(dialog).toHaveCount(0);
    await expect(page.locator('.evb-more-btn')).toBeFocused();
  });

  // ---- Final review fixes ----

  // Answered here: nothing is written. The second row repeats the first's id, so
  // two of the three rows are adopted and the notice counts two.
  test('the past-event dialog stays open while copying and its rows always arrive', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SUNDAY = await dayOf(page, 'sun');
    const row = (id: number, title: string, hh: string) => ({ EventScheduleId: id, Title: title, StartTime: `${SUNDAY} ${hh}:00:00`,
      EndTime: `${SUNDAY} ${hh}:30:00`, Location: '', Description: '', Category: 'Other', SecondaryCategory: '', Leads: [] });
    await page.route(/EventAjax\/schedule_copy_sources\//, route => route.fulfill({
      contentType: 'application/json',
      body: JSON.stringify({ status: 0, sources: [
        { EventId: 2, EventCalendarDetailId: 22, Name: 'Last Year', StartDate: '2025-11-07', ItemCount: 2, SameEvent: true },
      ] }),
    }));
    await page.route(/EventAjax\/schedule_copy_into\//, async route => {
      await new Promise(r => setTimeout(r, 800));
      await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ status: 0, skippedFeast: 0, schedule: [
        row(1999999001, `${PREFIX} mock copy one`, '06'), row(1999999001, `${PREFIX} mock copy one`, '06'), row(1999999002, `${PREFIX} mock copy two`, '07'),
      ] }) });
    });
    await page.locator('.evb-edit-btn').click();
    await expect(page.locator('.evb-modebar')).toBeVisible();
    expect(await page.evaluate(() => (window as any).EvBoardEdit.store.canUndo())).toBe(false);
    await page.locator('.evb-more-btn').click();
    await page.locator('[data-evb-more="past-event"]').click();
    const dialog = page.locator('.evb-copy-dialog');
    const source = dialog.locator('.evb-copy-source');
    await expect(source).toHaveCount(1);
    // Dark theme: the hovered source's border has its own colour.
    await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
    await source.hover();
    await expect.poll(() => source.evaluate(el => getComputedStyle(el).borderTopColor)).toBe('rgb(99, 179, 237)');
    await page.evaluate(() => document.documentElement.removeAttribute('data-theme'));
    await source.click();
    await expect(source).toContainText('Copying…');
    await page.keyboard.press('Escape');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('.evb-dialog-close')).toBeDisabled();
    await page.mouse.click(5, 5); // the backdrop
    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveCount(0, { timeout: 5000 });
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} mock copy` })).toHaveCount(2);
    await expect(page.locator('.evb-edit-head .evb-notice')).toContainText('Added 2 items from Last Year');
    expect(await page.evaluate(() => (window as any).EvBoardEdit.store.canUndo())).toBe(true);
    await expect(page.locator('.evb-undo')).toBeEnabled();
  });

  test('Done keeps Edit mode on while a bulk preview is open', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await expect(page.locator('.evb-quickline .evb-entry-input')).toBeFocused();
    await pasteText(page, ['Sunday', `6a ${PREFIX} done one`, `7a ${PREFIX} done two`].join('\n'));
    await expect(page.locator('.evb-bulkbar')).toContainText('2 items ready');
    await page.locator('.evb-done').click();
    await expect(page.locator('.evb-edit-head .evb-notice')).toContainText('Add or discard the preview first.');
    await expect(page.locator('.evb-modebar')).toBeVisible();
    await expect(page.locator('.evb-bulkbar')).toContainText('2 items ready');
    await expect(page.locator('.evb-ghost')).toHaveCount(2);
    await expect(page.locator('.evb-bulk-add')).toBeFocused();
    await page.locator('.evb-bulk-discard').click();
    await page.locator('.evb-done').click();
    await expect(page.locator('.evb-modebar')).toHaveCount(0, { timeout: 10000 });
    await expect(page.locator('.evb-block', { hasText: `${PREFIX} done ` })).toHaveCount(0);
  });

  test('a second bulk preview is refused while one is open', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await expect(page.locator('.evb-quickline .evb-entry-input')).toBeFocused();
    await pasteText(page, ['Sunday', `6a ${PREFIX} first one`, `7a ${PREFIX} first two`].join('\n'));
    await expect(page.locator('.evb-bulkbar')).toContainText('2 items ready');
    const notice = page.locator('.evb-edit-head .evb-notice');
    expect(await pasteText(page, ['Sunday', `8a ${PREFIX} second one`, `9a ${PREFIX} second two`, '10a x'].join('\n'))).toBe(true);
    await expect(notice).toContainText('Add or discard the preview first.');
    await expect(page.locator('.evb-bulkbar')).toContainText('2 items ready');
    for (const key of ['skeleton', 'paste-text']) {
      await page.locator('.evb-more-btn').click();
      await page.locator(`[data-evb-more="${key}"]`).click();
      await expect(page.locator('.evb-bulkbar')).toContainText('2 items ready');
      await expect(page.locator('.evb-paste-dialog')).toHaveCount(0);
    }
    // Said once, not once per refusal.
    await expect(notice.locator('.evb-notice-row', { hasText: 'Add or discard the preview first.' })).toHaveCount(1);
    await expect(page.locator('.evb-ghost')).toHaveCount(2);
    await page.locator('.evb-bulk-discard').click();
  });

  test('the image is drawn from the saved items only, on the view axis', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    const line = page.locator('.evb-quickline .evb-entry-input');
    await line.fill('sat 9a GHOSTONLY');
    await expect(page.locator('.evb-ghost')).toHaveCount(1);
    const r = await page.evaluate(() => {
      const w = window as any, view = w.EvBoardView, c = w.EvConfig;
      const titles = (b: any) => b.days.flatMap((d: any) => d.segs.map((s: any) => s.item.Title));
      const m = w.EvBoardImage.modelFor(view);
      const plain = w.EvBoardCore.buildBoard(view.getItems(), { eventStart: c.eventStart || '', eventEnd: c.eventEnd || '' });
      return { onBoard: titles(view.board).includes('GHOSTONLY'), inImage: titles(m).includes('GHOSTONLY'),
        axis: [m.axisStart, m.axisEnd], plainAxis: [plain.axisStart, plain.axisEnd], count: titles(m).length, items: view.getItems().length };
    });
    expect(r.onBoard).toBe(true);
    expect(r.inImage).toBe(false);
    expect(r.axis).toEqual(r.plainAxis);
    expect(r.count).toBeGreaterThanOrEqual(r.items);
    await line.fill('');
  });

  // The platform is set before the board's scripts read it, so this holds on any machine.
  test('Option with a character types at the cursor on a Mac only; Alt+letter elsewhere is the browser\'s', async ({ page, context }) => {
    // Opens Edit mode with the slot cursor showing; alt(key) dispatches an Alt keydown
    // and returns whether something default-prevented it.
    const ready = async (p: Page, platform: string) => {
      await p.addInitScript(pf => { Object.defineProperty(Navigator.prototype, 'platform', { get: () => pf, configurable: true }); }, platform);
      await openBoard(p, EDIT_EVENT);
      expect(await p.evaluate(() => navigator.platform)).toBe(platform);
      await p.locator('.evb-edit-btn').click();
      await expect(p.locator('.evb-modebar')).toBeVisible();
      await p.locator('.evb-board').click({ position: { x: 4, y: 4 } });
      await p.keyboard.press('Escape');
      await expect(p.locator('.evb-slot-cursor')).toHaveCount(1);
      return (k: string) => p.evaluate(kk => {
        const e = new KeyboardEvent('keydown', { key: kk, altKey: true, bubbles: true, cancelable: true });
        (document.activeElement || document.body).dispatchEvent(e);
        return e.defaultPrevented;
      }, k);
    };

    const altWin = await ready(page, 'Win32');
    expect(await altWin('d')).toBe(false);
    await expect(page.locator('.evb-block-editing')).toHaveCount(0);
    const min = () => page.locator('.evb-slot-cursor').getAttribute('data-min');
    const at = await min();
    expect(await altWin('ArrowDown')).toBe(false);
    expect(await min()).toBe(at);

    const mac = await context.newPage();
    const altMac = await ready(mac, 'MacIntel');
    expect(await altMac('å')).toBe(true);
    await expect(mac.locator('.evb-block-editing .evb-entry-input')).toHaveValue('å');
    await mac.keyboard.press('Escape');
    await expect(mac.locator('.evb-block-editing')).toHaveCount(0);
    await mac.close();
  });

  test('a server error says so, not that the server could not be reached', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await page.route(/schedule_batch/, route => route.fulfill({ status: 500, contentType: 'text/html', body: 'oops' }), { times: 1 });
    const line = page.locator('.evb-quickline .evb-entry-input');
    await line.fill(`sun 4:30p ${PREFIX} server error`);
    await line.press('Enter');
    await expect(page.locator('.evb-save-state')).toHaveText('The server had a problem. Press Retry in a moment.');
    await page.locator('.evb-save-retry').click();
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
  });

  test('a request the server refuses as a whole shows the server\'s reason, and Retry sends it again', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    const title = `${PREFIX} refused whole`;
    await page.route(/schedule_batch/, route => route.fulfill({
      contentType: 'application/json', body: JSON.stringify({ status: 1, error: 'Could not save the schedule.', failedIndex: -1 }),
    }), { times: 1 });
    const line = page.locator('.evb-quickline .evb-entry-input');
    await line.fill(`sun 5:15p ${title}`);
    await line.press('Enter');
    await expect(page.locator('.evb-save-state'))
      .toHaveText('The server had a problem: Could not save the schedule. Press Retry in a moment.');
    expect(await storeItem(page, title)).not.toBeNull();
    await page.locator('.evb-save-retry').click();
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    await expect.poll(async () => (await serverItems(page, title)).length).toBe(1);
  });

  test('conflict badges: below the top grip, tip hidden on render, read only when items change', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await addViaQuickLine(page, `sun 7-8p ${PREFIX} badge a at Barn`);
    await addViaQuickLine(page, `sun 7:30-8:30p ${PREFIX} badge b at barn`);
    const block = page.locator('.evb-block', { hasText: `${PREFIX} badge a` });
    const badge = block.locator('.evb-conflict');
    await expect(badge).toHaveCount(1);
    await block.evaluate(el => el.scrollIntoView({ block: 'center' }));
    await block.click();
    // The top grip's right end is the grip, not the badge.
    const hit = await block.evaluate(el => {
      const g = el.querySelector('.evb-resize-top')!.getBoundingClientRect();
      const t = document.elementFromPoint(g.right - 8, g.top + 3);
      return t ? t.className : '';
    });
    expect(hit).toContain('evb-resize-top');
    // The tip goes with a render. (Shown by an event, with the pointer off the
    // board, so a pointer resting on the new badge cannot show it again.)
    await page.locator('.evb-modebar-title').hover();
    await badge.dispatchEvent('mouseover');
    await expect(page.locator('.evb-conflict-tip')).toBeVisible();
    await page.evaluate(() => {
      const w = window as any, real = w.EvBoardCore.conflicts;
      w.__conflictCalls = 0;
      w.EvBoardCore.conflicts = function (items: any) { w.__conflictCalls++; return real(items); };
    });
    const line = page.locator('.evb-quickline .evb-entry-input');
    await line.fill('sun 9p something');
    await expect(page.locator('.evb-ghost')).toHaveCount(1);
    await expect(page.locator('.evb-conflict-tip')).toBeHidden();
    await line.pressSequentially(' more');
    expect(await page.evaluate(() => (window as any).__conflictCalls)).toBe(0);
    await line.fill('');
  });

  // ---- Fix wave: two organisers, a lost reply, keyboard reach, labels, clock, share ----

  test('a save whose reply is lost is resent as it was, with the same token, and applies once', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await expect(page.locator('.evb-modebar')).toBeVisible();
    const title = `${PREFIX} lost reply`;
    const bodies: string[] = [];
    page.on('request', r => { if (r.url().includes('schedule_batch') && r.method() === 'POST') bodies.push(r.postData() || ''); });
    // The first save reaches the server and is applied there; its reply never arrives.
    let reached = 0;
    await page.route(/schedule_batch/, async route => {
      reached = (await route.fetch()).status();
      await route.abort('failed');
    }, { times: 1 });
    const line = page.locator('.evb-quickline .evb-entry-input');
    await line.fill(`sun 6:45p ${title}`);
    await line.press('Enter');
    await expect(page.locator('.evb-save-state')).toHaveText('Couldn’t reach the server');
    expect(reached).toBe(200);
    await expect.poll(async () => (await serverItems(page, title)).length).toBe(1);

    await page.locator('.evb-save-retry').click();
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    // The retry is the held request again, alone: the same ops and the same token.
    expect(bodies).toHaveLength(2);
    const [first, second] = bodies.map(b => ({ ops: formField(b, 'Ops'), token: formField(b, 'BatchToken') }));
    expect(first.token).toMatch(/^[A-Za-z0-9_-]{16,64}$/);
    expect(second).toEqual(first);
    const ops = JSON.parse(first.ops!);
    expect(ops).toHaveLength(1);
    expect([ops[0].op, ops[0].item.Title]).toEqual(['add', title]);
    // One row on the server, and it is the one the board holds.
    await expect.poll(async () => (await serverItems(page, title)).length).toBe(1);
    const row = (await serverRows(page)).find(r => r.Title === title);
    expect(await idOf(page, title)).toBe(+row.EventScheduleId);
  });

  test('a change someone else beat or removed shows their version, with a notice', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    const beaten = `${PREFIX} beaten`, removed = `${PREFIX} removed elsewhere`;
    await quickAdd(page, `sat 10:15a ${beaten}`);
    await quickAdd(page, `sat 11:15a ${removed}`);
    const a = await idOf(page, beaten), b = await idOf(page, removed);
    const notice = page.locator('.evb-edit-head .evb-notice');
    // The answers are mocked: nothing here reaches the server.
    const theirs = Object.assign({}, (await serverRows(page)).find(r => +r.EventScheduleId === a),
      { Category: 'Court', Location: 'Their Hall', Modified: '2099-01-01 00:00:00' });
    const sent: any[] = [];
    const answer = (result: (op: any) => object) => (route: any) => {
      const ops = JSON.parse(formField(route.request().postData() || '', 'Ops') || '[]');
      sent.push(...ops);
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ status: 0, results: ops.map(result) }) });
    };

    await page.route(/schedule_batch/, answer(op => ({ op: op.op, cid: op.cid, id: op.id, conflict: true, schedule: theirs })), { times: 1 });
    const blockA = page.locator(`.evb-block[data-id="${a}"]`);
    await blockA.click();
    await page.keyboard.press('5');
    await expect(notice).toContainText(`"${beaten}" was changed by someone else. Their version is shown; your change was not applied.`);
    await expect(blockA).toHaveAttribute('data-category', 'Court');
    expect(await storeItem(page, beaten)).toMatchObject({ Category: 'Court', Location: 'Their Hall' });
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');

    await page.route(/schedule_batch/, answer(op => ({ op: op.op, cid: op.cid, id: op.id, gone: true, schedule: null })), { times: 1 });
    await page.locator(`.evb-block[data-id="${b}"]`).click();
    await page.keyboard.press('5');
    await expect(notice).toContainText(`"${removed}" was removed by someone else.`);
    await expect(page.locator(`.evb-block[data-id="${b}"]`)).toHaveCount(0);
    expect(await storeItem(page, removed)).toBeNull();
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    expect(sent.map(o => [o.op, o.id])).toEqual([['update', a], ['update', b]]);
  });

  test('two writers: the first change wins on the server and the board says so', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    const title = `${PREFIX} two writers`;
    await quickAdd(page, `sat 12:15p ${title} at Our Field`);
    const id = await idOf(page, title);
    // Someone else moves it, straight through the endpoint; this board is not refreshed.
    const url = await page.evaluate(() => {
      const c = (window as any).EvConfig;
      return new URL(c.uir + 'EventAjax/schedule_batch/' + c.eventId + '/' + c.detailId, location.href).href;
    });
    const res = await page.request.post(url, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      multipart: { Ops: JSON.stringify([{ op: 'update', id, fields: { Location: 'Their Barn' } }]) },
    });
    expect((await res.json()).status).toBe(0);
    // This person recategorises the copy they still have.
    const block = page.locator(`.evb-block[data-id="${id}"]`);
    await block.click();
    await page.keyboard.press('5');
    await expect(page.locator('.evb-edit-head .evb-notice'))
      .toContainText(`"${title}" was changed by someone else. Their version is shown; your change was not applied.`);
    await expect(block).toHaveAttribute('data-category', 'Other');
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    // The server kept the first change and did not apply the second.
    const row = (await serverRows(page)).find(r => +r.EventScheduleId === id);
    expect([row.Location, row.Category]).toEqual(['Their Barn', 'Other']);
    expect((await storeItem(page, title)).Location).toBe('Their Barn');
  });

  test('saving through Details keeps Undo for another item, tells of no one else, and returns to the block', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    const first = `${PREFIX} undo kept`, second = `${PREFIX} details saved`;
    await quickAdd(page, `fri 5:15p ${first}`);
    await quickAdd(page, `fri 8:15p ${second}`);
    const firstId = await idOf(page, first), secondId = await idOf(page, second);
    // An earlier change, to the first item.
    const firstBlock = page.locator(`.evb-block[data-id="${firstId}"]`);
    await firstBlock.click();
    await page.keyboard.press('5');
    await expect(firstBlock).toHaveAttribute('data-category', 'Class');
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');

    // Details on the second, saved.
    await page.locator(`.evb-block[data-id="${secondId}"]`).click();
    await page.keyboard.press('Shift+Enter');
    const modal = page.locator('#ev-schedule-modal');
    await expect(modal).toBeVisible();
    await page.locator('#ev-sched-location').fill('Details Hall');
    await page.locator('#ev-sched-save-btn').click();
    await expect(modal).toBeHidden();
    await expect.poll(async () => (await storeItem(page, second))?.Location).toBe('Details Hall');
    await expect.poll(() => page.evaluate(() => {
      const a = document.activeElement as HTMLElement | null;
      return a && a.classList.contains('evb-block') ? a.getAttribute('data-id') : null;
    })).toBe(String(secondId));
    await expect(page.locator(`.evb-block[data-id="${secondId}"]`)).toHaveClass(/evb-selected/);
    await expect(page.locator('.evb-notice-row', { hasText: 'someone else' })).toHaveCount(0);

    // Undo still holds the first item's change.
    await expect(page.locator('.evb-undo')).toBeEnabled();
    await page.locator('.evb-undo').click();
    await expect(firstBlock).toHaveAttribute('data-category', 'Other');
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    await expect.poll(async () => (await serverItem(page, first))?.Category).toBe('Other');
    expect((await serverRows(page)).find(r => +r.EventScheduleId === secondId).Location).toBe('Details Hall');
  });

  // The schedule_batch URL of the fixture event, for a second writer's request.
  async function batchUrl(page: Page): Promise<string> {
    return page.evaluate(() => {
      const c = (window as any).EvConfig;
      return new URL(c.uir + 'EventAjax/schedule_batch/' + c.eventId + '/' + c.detailId, location.href).href;
    });
  }

  test('Details will not save over a change someone else made since: it says so and the server keeps theirs', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    const title = `${PREFIX} details stale`;
    await quickAdd(page, `sat 1:15p ${title}`);
    const id = await idOf(page, title);
    const seen = (await storeItem(page, title)).Modified;
    expect(seen).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
    // Someone else changes it, straight through the endpoint; this board is not re-read.
    const res = await page.request.post(await batchUrl(page), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      multipart: { Ops: JSON.stringify([{ op: 'update', id, fields: { Location: 'Their Barn' } }]) },
    });
    expect((await res.json()).status).toBe(0);

    // Details from the copy the board still has, then save.
    const posted: string[] = [];
    page.on('request', r => { if (r.url().includes('update_schedule')) posted.push(r.postData() || ''); });
    await page.locator(`.evb-block[data-id="${id}"]`).click();
    await page.keyboard.press('Shift+Enter');
    const modal = page.locator('#ev-schedule-modal');
    await expect(modal).toBeVisible();
    await page.locator('#ev-sched-description').fill('Mine');
    await page.locator('#ev-sched-save-btn').click();
    await expect(page.locator('#ev-sched-error'))
      .toHaveText('Someone else changed this item. Close this form and open it again to see their version.');
    await expect(modal).toBeVisible();
    expect(posted).toHaveLength(1);
    expect(formField(posted[0], 'Seen')).toBe(seen);
    // The server kept their change and took none of this one.
    const row = (await serverRows(page)).find(r => +r.EventScheduleId === id);
    expect([row.Location, row.Description]).toEqual(['Their Barn', '']);
    // The refusal re-read the schedule in the background: the board has their version.
    await expect.poll(async () => (await storeItem(page, title))?.Location).toBe('Their Barn');
    await page.evaluate(() => (window as any).evCloseScheduleModal(true));
    await expect(modal).toBeHidden();

    // Reopened, the form shows their version and saves over it.
    await page.locator(`.evb-block[data-id="${id}"]`).click();
    await page.keyboard.press('Shift+Enter');
    await expect(modal).toBeVisible();
    await expect(page.locator('#ev-sched-location')).toHaveValue('Their Barn');
    await page.locator('#ev-sched-description').fill('Mine');
    const saved = page.waitForResponse(r => r.url().includes('update_schedule'));
    await page.locator('#ev-sched-save-btn').click();
    expect((await (await saved).json()).status).toBe(0);
    await expect(modal).toBeHidden();
    expect(posted).toHaveLength(2);
    expect(formField(posted[1], 'Seen')).not.toBe(seen);
    const after = (await serverRows(page)).find(r => +r.EventScheduleId === id);
    expect([after.Location, after.Description]).toEqual(['Their Barn', 'Mine']);
  });

  test('outside Edit mode, the list form refused over someone else\'s change shows their version on reopening and saves', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SATURDAY = await dayOf(page, 'sat');
    const title = `${PREFIX} list stale`;
    const [id] = await addViaBatch(page, [{ Title: title, StartTime: `${SATURDAY} 16:15`, EndTime: `${SATURDAY} 17:00`, Category: 'Other', Leads: [] }]);
    await page.reload();
    await expect(page.locator('#evb-root')).toBeVisible();
    await page.locator('[data-ev-view="list"]').click();
    const row = page.locator(`#ev-schedule-container tr[id="ev-schedule-row-${id}"]`);
    await expect(row).toHaveCount(1);
    const seen = await row.getAttribute('data-modified');
    expect(seen).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
    // Someone else changes it, straight through the endpoint; this page is not re-read.
    const res = await page.request.post(await batchUrl(page), {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      multipart: { Ops: JSON.stringify([{ op: 'update', id, fields: { Location: 'Their Barn' } }]) },
    });
    expect((await res.json()).status).toBe(0);

    const posted: string[] = [];
    page.on('request', r => { if (r.url().includes('update_schedule')) posted.push(r.postData() || ''); });
    const modal = page.locator('#ev-schedule-modal');
    await row.locator('.ev-edit-link').click();
    await expect(modal).toBeVisible();
    await expect(page.locator('#ev-sched-location')).toHaveValue('');
    await page.locator('#ev-sched-description').fill('Mine');
    await page.locator('#ev-sched-save-btn').click();
    await expect(page.locator('#ev-sched-error'))
      .toHaveText('Someone else changed this item. Close this form and open it again to see their version.');
    await expect(modal).toBeVisible();
    expect(formField(posted[0], 'Seen')).toBe(seen);
    // The refusal re-read the page in the background: the row, and the board's copy, are theirs.
    await expect(row).toHaveAttribute('data-location', 'Their Barn');
    expect(await row.getAttribute('data-modified')).not.toBe(seen);
    expect(await page.evaluate(i => {
      const it = (window as any).EvConfig.schedule.find((s: any) => +s.EventScheduleId === i);
      return it && it.Location;
    }, id)).toBe('Their Barn');
    await page.evaluate(() => (window as any).evCloseScheduleModal(true));
    await expect(modal).toBeHidden();

    // Reopened, the form shows their version and saves over it.
    await row.locator('.ev-edit-link').click();
    await expect(modal).toBeVisible();
    await expect(page.locator('#ev-sched-location')).toHaveValue('Their Barn');
    await page.locator('#ev-sched-description').fill('Mine');
    const saved = page.waitForResponse(r => r.url().includes('update_schedule'));
    await page.locator('#ev-sched-save-btn').click();
    expect((await (await saved).json()).status).toBe(0);
    await expect(modal).toBeHidden();
    expect(posted).toHaveLength(2);
    const after = (await serverRows(page)).find(r => +r.EventScheduleId === id);
    expect([after.Location, after.Description]).toEqual(['Their Barn', 'Mine']);
  });

  test('Details pressed while the item\'s own nudge is saving opens once it is saved, and its save is not refused', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    const title = `${PREFIX} nudge then details`;
    await quickAdd(page, `sat 3:15p ${title}`);
    const id = await idOf(page, title);
    const block = page.locator(`.evb-block[data-id="${id}"]`);
    await block.click();
    // A slow save, so the nudge is still in flight when Details is pressed.
    await page.route(/schedule_batch/, async route => {
      await new Promise(r => setTimeout(r, 1200));
      await route.continue();
    }, { times: 1 });
    const modal = page.locator('#ev-schedule-modal');
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Shift+Enter');
    await expect(page.locator('.evb-save-state')).not.toHaveText('All changes saved');
    await expect(modal).toBeHidden();
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    await expect(modal).toBeVisible();
    const [start, end] = await timesOf(page, title);
    expect(start).not.toBe(`${await dayOf(page, 'sat')} 15:15`);
    await page.locator('#ev-sched-location').fill('Nudged Hall');
    const saved = page.waitForResponse(r => r.url().includes('update_schedule'));
    await page.locator('#ev-sched-save-btn').click();
    const reply = await (await saved).json();
    expect(reply).toMatchObject({ status: 0 });
    await expect(modal).toBeHidden();
    await expect(page.locator('#ev-sched-error')).toBeHidden();
    await expect(page.locator('.evb-notice-row', { hasText: 'someone else' })).toHaveCount(0);
    // The server has the nudge's times and the form's place.
    const row = (await serverRows(page)).find(r => +r.EventScheduleId === id);
    expect([row.Location, String(row.StartTime).slice(0, 16), String(row.EndTime).slice(0, 16)]).toEqual(['Nudged Hall', start, end]);
  });

  test('a nudge straight after a Details save is not taken for someone else\'s change; both reach the server', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    const title = `${PREFIX} details then nudge`;
    await quickAdd(page, `sat 2:15p ${title}`);
    const id = await idOf(page, title);
    const block = page.locator(`.evb-block[data-id="${id}"]`);
    // No re-read gets through until the nudge is saved: the store can know of the
    // Details save only from the form's own reply.
    let blockReads = true;
    await page.route(/schedule_list/, route => blockReads ? route.abort('failed') : route.continue());
    const batches: string[] = [];
    page.on('request', r => { if (r.url().includes('schedule_batch') && r.method() === 'POST') batches.push(r.postData() || ''); });

    await block.click();
    await page.keyboard.press('Shift+Enter');
    const modal = page.locator('#ev-schedule-modal');
    await expect(modal).toBeVisible();
    await page.locator('#ev-sched-location').fill('Details Barn');
    const saved = page.waitForResponse(r => r.url().includes('update_schedule'));
    await page.locator('#ev-sched-save-btn').click();
    const reply = await (await saved).json();
    expect(reply.status).toBe(0);
    await expect(modal).toBeHidden();
    await expect.poll(() => page.evaluate(() => {
      const a = document.activeElement as HTMLElement | null;
      return a && a.classList.contains('evb-block') ? a.getAttribute('data-id') : null;
    })).toBe(String(id));
    expect((await storeItem(page, title)).Modified).toBe(reply.schedule.Modified);

    await page.keyboard.press('ArrowDown');
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    blockReads = false;
    await page.unroute(/schedule_list/);
    await expect(page.locator('.evb-notice-row', { hasText: 'someone else' })).toHaveCount(0);
    const ops = JSON.parse(formField(batches[batches.length - 1], 'Ops')!);
    expect([ops[0].op, ops[0].id, ops[0].seen]).toEqual(['update', id, reply.schedule.Modified]);
    // Both changes are on the server: the form's place and the nudge's times.
    const [start, end] = await timesOf(page, title);
    expect(start).not.toBe(String(reply.schedule.StartTime).slice(0, 16));
    const row = (await serverRows(page)).find(r => +r.EventScheduleId === id);
    expect([row.Location, String(row.StartTime).slice(0, 16), String(row.EndTime).slice(0, 16)]).toEqual(['Details Barn', start, end]);
  });

  test('keyboard: Tab reaches the toolbar and back, Shift+Enter opens Details, Tab leaves an empty block for the quick line', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    const title = `${PREFIX} tab tools`;
    await quickAdd(page, `fri 7:45p ${title}`);
    const block = page.locator('.evb-block', { hasText: title });
    await block.focus();
    await expect(block).toHaveAttribute('aria-pressed', 'true');
    const tool = (name: string) => page.locator(`.evb-block-toolbar [data-evb-act="${name}"]`);
    await page.keyboard.press('Tab');
    await expect(tool('rename')).toBeFocused();
    await expect(block).toHaveClass(/evb-selected/);
    await page.keyboard.press('Tab');
    await expect(tool('duplicate')).toBeFocused();
    await page.keyboard.press('Shift+Tab');
    await expect(tool('rename')).toBeFocused();
    await page.keyboard.press('Shift+Tab');
    await expect.poll(() => isFocused(block)).toBe(true);
    await expect(block).toHaveClass(/evb-selected/);

    // Shift+Enter opens Details, not a rename; closing it gives the block back.
    await page.keyboard.press('Shift+Enter');
    await expect(page.locator('#ev-schedule-modal')).toBeVisible();
    await expect(page.locator('#ev-sched-title')).toHaveValue(title);
    await expect(page.locator('.evb-block-editing')).toHaveCount(0);
    await page.locator('#ev-schedule-modal .ev-modal-close').click();
    await expect(page.locator('#ev-schedule-modal')).toBeHidden();
    await expect.poll(() => isFocused(block)).toBe(true);

    // An empty new block: Tab, and Shift+Tab, hand the keys to the quick line.
    await page.keyboard.press('Escape');
    await expect(page.locator('.evb-selected')).toHaveCount(0);
    for (const key of ['Tab', 'Shift+Tab']) {
      const slot = await freeSlot(page);
      expect(slot).not.toBeNull();
      const { day, at } = slot!;
      // Bring that time to the middle of the board's frame, clear of the sticky Edit head.
      await page.evaluate(hhmm => {
        const w = window as any, [h, m] = hhmm.split(':').map(Number);
        const s = document.querySelector('.evb-scroller') as HTMLElement;
        s.scrollIntoView({ block: 'end' });
        s.scrollTop = (h * 60 + m - w.EvBoardView.board.axisStart) * w.EvBoardView.PX_PER_MIN - s.clientHeight / 2;
      }, at);
      await clickSlot(page, day, at);
      await expect(page.locator('.evb-block-editing .evb-entry-input')).toBeFocused();
      await page.keyboard.press(key);
      await expect(page.locator('.evb-type-block')).toBeHidden();
      await expect(page.locator('.evb-quickline .evb-entry-input')).toBeFocused();
    }
  });

  test('an item after midnight names its calendar day, with the night in brackets', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SATURDAY = await dayOf(page, 'sat'), SUNDAY = await dayOf(page, 'sun');
    const title = `${PREFIX} wee hours`;
    const [id] = await addViaBatch(page, [{
      Title: title, StartTime: `${SUNDAY} 01:00`, EndTime: `${SUNDAY} 02:00`, Category: 'Other',
      SecondaryCategory: '', Location: '', Description: '', Leads: [],
    }]);
    await page.reload();
    await expect(page.locator('#evb-root')).toBeVisible();
    const block = page.locator(`.evb-block[data-id="${id}"]`);
    // Drawn on Saturday's board day, named for the Sunday it falls on.
    await expect(block).toHaveAttribute('data-day', SATURDAY);
    await expect(block).toHaveAttribute('aria-label', `${title}, Other, Sunday 1–2a (Saturday night)`);
    await block.click();
    await expect(page.locator('.evb-popover')).toContainText('Sunday · 1–2a (Saturday night)');
  });

  test('the now line follows the clock it is given, through the small hours, and not outside the event', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const FRIDAY = await dayOf(page, 'fri'), SATURDAY = await dayOf(page, 'sat'), SUNDAY = await dayOf(page, 'sun');
    // A long Saturday night, so the board's axis reaches 4:30am.
    await addViaBatch(page, [{
      Title: `${PREFIX} night watch`, StartTime: `${SATURDAY} 23:00`, EndTime: `${SUNDAY} 04:45`, Category: 'Other',
      SecondaryCategory: '', Location: '', Description: '', Leads: [],
    }]);
    await page.reload();
    await expect(page.locator('#evb-root')).toBeVisible();
    const setClock = (at: string) => page.evaluate(a => (window as any).EvBoardView.setClock(() => a), at);
    const topAt = (min: number) => page.evaluate(m => {
      const V = (window as any).EvBoardView;
      return (m - V.board.axisStart) * V.PX_PER_MIN;
    }, min);
    const lineOn = (day: string) => page.locator(`.evb-day-body[data-day="${day}"] .evb-now`);
    const topOf = (day: string) => lineOn(day).evaluate(e => parseFloat((e as HTMLElement).style.top));

    await setClock(`${FRIDAY} 12:00`);
    await expect(page.locator('.evb-now')).toHaveCount(1);
    await expect(lineOn(FRIDAY)).toHaveCount(1);
    expect(await topOf(FRIDAY)).toBeCloseTo(await topAt(720), 1);

    // 4:30am on Sunday is still Saturday night on the board.
    await setClock(`${SUNDAY} 04:30`);
    expect(await page.evaluate(() => (window as any).EvBoardView.currentNow())).toEqual({ day: SATURDAY, min: 1710 });
    await expect(page.locator('.evb-now')).toHaveCount(1);
    await expect(lineOn(SATURDAY)).toHaveCount(1);
    expect(await topOf(SATURDAY)).toBeCloseTo(await topAt(1710), 1);

    // A month after the event: no line at all.
    await setClock(`${addDays(SUNDAY, 30)} 12:00`);
    await expect(page.locator('.evb-now')).toHaveCount(0);
  });

  test('share: the day choice is a radio group, and Download and Copy draw the chosen day only', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const FRIDAY = await dayOf(page, 'fri'), SATURDAY = await dayOf(page, 'sat'), SUNDAY = await dayOf(page, 'sun');
    // Every picture the page encodes, by size (the hook the image tests use), and a
    // clipboard that takes an image without asking.
    await page.evaluate(() => {
      const w = window as any;
      w.__drawn = [];
      const real = HTMLCanvasElement.prototype.toBlob;
      HTMLCanvasElement.prototype.toBlob = function (this: HTMLCanvasElement, cb: BlobCallback, type?: string, q?: any) {
        w.__drawn.push([this.width, this.height]);
        return real.call(this, cb, type, q);
      };
      Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { write: () => Promise.resolve() } });
    });
    // The size of the picture of one day (or all days, null), drawn as the menu draws it.
    const sizeFor = (day: string | null) => page.evaluate(d => {
      const w = window as any, c = w.EvConfig, I = w.EvBoardImage;
      const when = d ? w.EvBoardCore.dayLabelLong(d) : (c.eventDateLabel || '');
      const meta = {
        title: c.eventName || 'Event', subtitle: [when, c.eventPlace || ''].filter(Boolean).join(' · '),
        url: location.origin + new URL(c.uir || '', location.href).pathname + '?Route=Event/detail/' +
          (parseInt(c.eventId, 10) || 0) + '/' + (parseInt(c.detailId, 10) || 0),
        categories: c.scheduleCategories || [],
      };
      const canvas = I.render(I.modelFor(w.EvBoardView), meta, { scale: 2, day: d });
      return [canvas.width, canvas.height];
    }, day);
    const drawn = () => page.evaluate(() => (window as any).__drawn.slice());

    await page.locator('.evb-share-btn').click();
    const group = page.locator('.evb-share-menu [role="radiogroup"][aria-label="Which days"]');
    await expect(group).toBeVisible();
    const radio = (day: string | null) => group.locator(day ? `[role="radio"][data-evb-day="${day}"]` : '[role="radio"]:not([data-evb-day])');
    await expect(group.locator('[role="radio"]')).toHaveCount(4);
    await expect(radio(SATURDAY)).toHaveText('Saturday');
    await expect(radio(null)).toHaveAttribute('aria-checked', 'true');
    // One tab stop; the arrow keys, Home and End move the choice and wrap.
    const checked = () => group.locator('[role="radio"][aria-checked="true"]');
    await radio(null).focus();
    await page.keyboard.press('ArrowRight');
    await expect(radio(FRIDAY)).toBeFocused();
    await expect(radio(FRIDAY)).toHaveAttribute('aria-checked', 'true');
    await page.keyboard.press('End');
    await expect(radio(SUNDAY)).toBeFocused();
    await page.keyboard.press('ArrowRight');
    await expect(radio(null)).toBeFocused();
    await page.keyboard.press('ArrowLeft');
    await page.keyboard.press('ArrowLeft');
    await expect(radio(SATURDAY)).toBeFocused();
    await expect(checked()).toHaveCount(1);
    await expect(radio(SATURDAY)).toHaveAttribute('aria-checked', 'true');
    await expect(group.locator('[role="radio"][tabindex="0"]')).toHaveCount(1);
    await expect(radio(SATURDAY)).toHaveAttribute('tabindex', '0');

    // Download: the picture is Saturday's alone, and the file is named for it.
    const day = await sizeFor(SATURDAY), all = await sizeFor(null);
    expect(day).not.toEqual(all);
    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.locator('.evb-share-menu [data-evb-share="download-all"]').click(),
    ]);
    expect(download.suggestedFilename()).toMatch(new RegExp(`-${SATURDAY}-schedule\\.png$`));
    expect((await drawn()).slice(-1)).toEqual([day]);

    // Copy: choose Saturday again (the menu opens on the board's day, here All days).
    await page.locator('.evb-share-btn').click();
    await expect(radio(null)).toHaveAttribute('aria-checked', 'true');
    await radio(SATURDAY).click();
    await expect(page.locator('#evb-share-menu')).toBeVisible();
    const before = (await drawn()).length;
    await page.locator('.evb-share-menu [data-evb-share="copy"]').click();
    await expect(page.locator('.evb-share-btn')).toContainText('Copied');
    const after = await drawn();
    expect(after.length).toBe(before + 1);
    expect(after.slice(-1)).toEqual([day]);
  });

  test('unmarked evening times preview in the evening, typed or pasted', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SATURDAY = await dayOf(page, 'sat');
    await page.locator('.evb-edit-btn').click();
    const line = page.locator('.evb-quickline .evb-entry-input');
    await expect(line).toBeFocused();
    const ghosts = page.locator('.evb-ghost');
    const previews = () => ghosts.evaluateAll(els => els.map(e => [
      (e.querySelector('.evb-block-title')!.textContent || '').trim(), e.getAttribute('data-day'),
      (e.querySelector('.evb-block-meta')!.textContent || '').trim()]));

    await line.fill('sat feast 7-9');
    await expect(ghosts).toHaveCount(1);
    await expect(ghosts).toHaveAttribute('data-day', SATURDAY);
    await expect(ghosts.locator('.evb-block-meta').first()).toHaveText('7–9p');
    await line.fill('');
    await expect(ghosts).toHaveCount(0);

    // Four lines with no am or pm, under a Saturday heading.
    await pasteText(page, ['Saturday', '5-6 Plate', '7-9 Court', '9-11 Night Quest', '12:30-2 Midnight Ditch'].join('\n'));
    await expect(page.locator('.evb-bulkbar')).toContainText('4 items ready');
    expect((await previews()).sort()).toEqual([
      ['Court', SATURDAY, '7–9p'],
      ['Midnight Ditch', SATURDAY, '12:30–2a'],
      ['Night Quest', SATURDAY, '9–11p'],
      ['Plate', SATURDAY, '5–6p'],
    ]);
    await page.locator('.evb-bulk-discard').click();
    await expect(ghosts).toHaveCount(0);
  });

  test('Add Schedule Item is hidden in Edit mode and back after Done', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const add = page.locator('#ev-tab-schedule .evb-edit-btn ~ .ev-submit-btn', { hasText: 'Add Schedule Item' });
    await expect(add).toBeVisible();
    await page.locator('.evb-edit-btn').click();
    await expect(page.locator('.evb-modebar')).toBeVisible();
    await expect(add).toBeHidden();
    await page.locator('.evb-done').click();
    await expect(page.locator('.evb-modebar')).toHaveCount(0, { timeout: 10000 });
    await expect(add).toBeVisible();
  });

  // ---- Enhancement round: bounds, Edit from the List, rails, now and next, ceiling, form rules ----

  // Counts the schedule_batch requests the page sends from now on.
  function countBatches(page: Page): () => number {
    let n = 0;
    page.on('request', r => { if (r.url().includes('schedule_batch') && r.method() === 'POST') n++; });
    return () => n;
  }
  // The server row's [start, end, category] for this title, to the minute; null when there is none.
  async function serverTimes(page: Page, title: string): Promise<string[] | null> {
    const r = await serverItem(page, title);
    return r && [r.StartTime, r.EndTime, r.Category];
  }
  // 'Thursday 19 November' for a calendar day, as the board's notices name it.
  function longDate(day: string): string {
    const d = new Date(`${day}T00:00:00Z`);
    const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    return `${days[d.getUTCDay()]} ${d.getUTCDate()} ${months[d.getUTCMonth()]}`;
  }
  const boardDays = (page: Page) => page.evaluate(() => (window as any).EvBoardView.board.days.map((d: any) => d.day));

  test('Edit schedule in the List view opens the board in Edit mode, and the board stays after Done', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('[data-ev-view="list"]').click();
    await expect(page.locator('#ev-schedule-container')).toBeVisible();
    await expect(page.locator('#evb-root')).toBeHidden();
    const edit = page.locator('.evb-edit-btn');
    await expect(edit).toBeVisible();
    await edit.click();
    await expect(page.locator('.evb-modebar')).toBeVisible();
    expect(await page.evaluate(() => [(window as any).EvBoardView.getView(), (window as any).EvBoardEdit.isEditing()]))
      .toEqual(['board', true]);
    await expect(page.locator('#evb-root')).toBeVisible();
    await expect(page.locator('#ev-schedule-container')).toBeHidden();
    await expect(edit).toBeHidden();
    await page.locator('.evb-done').click();
    await expect(page.locator('.evb-modebar')).toHaveCount(0, { timeout: 10000 });
    expect(await page.evaluate(() => (window as any).EvBoardView.getView())).toBe('board');
    await expect(page.locator('#evb-root')).toBeVisible();
    await expect(page.locator('#ev-schedule-container')).toBeHidden();
    await expect(edit).toBeVisible();
  });

  test('a weekday outside the event is added on its nearest date, with a notice and its own column', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const THURSDAY = addDays(await dayOf(page, 'fri'), -1);
    await page.locator('.evb-edit-btn').click();
    await expect(page.locator('.evb-modebar')).toBeVisible();
    expect(await boardDays(page)).not.toContain(THURSDAY);
    const title = `${PREFIX} arrival`;
    let id = 0;
    try {
      await quickAdd(page, `thu 9a ${title}`);
      id = await idOf(page, title);
      expect((await timesOf(page, title))[0]).toBe(`${THURSDAY} 09:00`);
      await expect(page.locator('.evb-edit-head .evb-notice .evb-notice-row'))
        .toHaveText(`"${title}" is on ${longDate(THURSDAY)}, outside the event's dates.`);
      await expect(page.locator(`.evb-day[data-day="${THURSDAY}"]`)).toHaveCount(1);
      expect(await boardDays(page)).toContain(THURSDAY);
      await expect.poll(async () => (await serverItem(page, title))?.StartTime).toBe(`${THURSDAY} 09:00`);
    } finally {
      // A Thursday item would add a column to every later test's board.
      if (id) expect(await removeIds(page, [id])).toBe(0);
    }
    await expect.poll(() => serverItem(page, title)).toBeNull();
  });

  test('a held arrow key stops the item at the board\'s end and then sends nothing', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SUNDAY = await dayOf(page, 'sun'), MONDAY = addDays(SUNDAY, 1);
    await page.locator('.evb-edit-btn').click();
    const title = `${PREFIX} late edge`;
    await quickAdd(page, `sun 11:30p-4:30a ${title}`);
    expect(await timesOf(page, title)).toEqual([`${SUNDAY} 23:30`, `${MONDAY} 04:30`]);
    const block = page.locator('.evb-block', { hasText: title });
    await block.evaluate(el => el.scrollIntoView({ block: 'center' }));
    await block.click();
    await expect(block).toHaveClass(/evb-selected/);
    const days = await boardDays(page);
    for (let i = 0; i < 12; i++) await page.keyboard.press('ArrowDown');
    await expect.poll(() => timesOf(page, title)).toEqual([`${MONDAY} 00:00`, `${MONDAY} 05:00`]);
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    await expect.poll(() => serverTimes(page, title)).toEqual([`${MONDAY} 00:00`, `${MONDAY} 05:00`, 'Other']);
    expect(await boardDays(page)).toEqual(days);

    // At the end: more presses change nothing and send nothing.
    const batches = countBatches(page);
    for (let i = 0; i < 4; i++) await page.keyboard.press('ArrowDown');
    await page.keyboard.press('ArrowRight');
    await page.waitForTimeout(1000);
    expect(batches()).toBe(0);
    expect(await timesOf(page, title)).toEqual([`${MONDAY} 00:00`, `${MONDAY} 05:00`]);
    expect(await boardDays(page)).toEqual(days);
  });

  test('a long Administrative item is a rail you can select, nudge, drag to another day, shorten and delete', async ({ page }) => {
    await page.setViewportSize({ width: 1400, height: 1000 });
    await openBoard(page, EDIT_EVENT);
    const SATURDAY = await dayOf(page, 'sat'), SUNDAY = await dayOf(page, 'sun');
    await page.locator('.evb-edit-btn').click();
    const title = `${PREFIX} troll`;
    await quickAdd(page, `sat 9a-5p ${title} #admin`);
    const rail = page.locator('.evb-block.evb-block-rail', { hasText: title });
    await expect(rail).toHaveCount(1);
    expect(Math.round((await rail.boundingBox())!.width)).toBe(22);

    await rail.click();
    await expect(rail).toHaveClass(/evb-selected/);
    for (const grip of ['.evb-resize-top', '.evb-resize-bottom', '.evb-fill-handle']) await expect(rail.locator(grip)).toHaveCount(1);
    await page.keyboard.press('ArrowDown');
    await expect.poll(() => timesOf(page, title)).toEqual([`${SATURDAY} 09:15`, `${SATURDAY} 17:15`]);

    // Drag it sideways to Sunday: the preview is a rail in Sunday with the live time ahead of its title.
    const visibleMiddle = () => page.evaluate(t => {
      const r = Array.from(document.querySelectorAll('.evb-block-rail')).find(e => (e.textContent || '').includes(t))!.getBoundingClientRect();
      const s = document.querySelector('.evb-scroller')!.getBoundingClientRect();
      const top = Math.max(r.top, s.top + 60), bottom = Math.min(r.bottom, s.bottom, window.innerHeight);
      return { x: r.left + r.width / 2, y: (top + bottom) / 2 };
    }, title);
    await rail.evaluate(el => el.scrollIntoView({ block: 'center' }));
    const p = await visibleMiddle();
    const sunday = (await page.locator(`.evb-day-body[data-day="${SUNDAY}"]`).boundingBox())!;
    await page.mouse.move(p.x, p.y);
    await page.mouse.down();
    await page.mouse.move(p.x + 20, p.y, { steps: 3 });
    await page.mouse.move(sunday.x + sunday.width / 2, p.y, { steps: 8 });
    const ghost = page.locator('.evb-drag-ghost');
    await expect(ghost).toBeVisible();
    const preview = await ghost.evaluate(g => ({
      rail: g.classList.contains('evb-block-rail'), day: g.closest('.evb-day-body')?.getAttribute('data-day'),
      width: Math.round(g.getBoundingClientRect().width), title: (g.querySelector('.evb-block-title')!.textContent || '').trim(),
    }));
    expect(preview).toEqual({ rail: true, day: SUNDAY, width: 22, title: `9:15a–5:15p ${title}` });
    await page.mouse.up();
    await expect.poll(() => timesOf(page, title)).toEqual([`${SUNDAY} 09:15`, `${SUNDAY} 17:15`]);
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    await expect.poll(() => serverTimes(page, title)).toEqual([`${SUNDAY} 09:15`, `${SUNDAY} 17:15`, 'Administrative']);

    // The bottom grip up 300 minutes: under four hours the preview leaves the rails.
    const moved = page.locator(`.evb-block.evb-block-rail[data-day="${SUNDAY}"]`, { hasText: title });
    await moved.evaluate(el => el.scrollIntoView({ block: 'end' }));
    await moved.click();
    const grip = (await moved.locator('.evb-resize-bottom').boundingBox())!;
    const pxPerMin = await page.evaluate(() => (window as any).EvBoardView.PX_PER_MIN);
    await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2);
    await page.mouse.down();
    await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2 - 20, { steps: 3 });
    await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2 - 300 * pxPerMin, { steps: 8 });
    await expect(ghost).toBeVisible();
    const shortened = await ghost.evaluate(g => ({ rail: g.classList.contains('evb-block-rail'), width: g.getBoundingClientRect().width }));
    expect(shortened.rail).toBe(false);
    expect(shortened.width).toBeGreaterThan(44);
    await page.mouse.up();
    await expect.poll(() => timesOf(page, title)).toEqual([`${SUNDAY} 09:15`, `${SUNDAY} 12:15`]);
    await expect(page.locator('.evb-block.evb-block-rail', { hasText: title })).toHaveCount(0);
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    await expect.poll(() => serverTimes(page, title)).toEqual([`${SUNDAY} 09:15`, `${SUNDAY} 12:15`, 'Administrative']);

    // Delete removes it, on the server too.
    await page.locator('.evb-block', { hasText: title }).click();
    await page.keyboard.press('Delete');
    await expect(page.locator('.evb-block', { hasText: title })).toHaveCount(0);
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    await expect.poll(() => serverItem(page, title)).toBeNull();
  });

  test('a change outside the event\'s window is refused before it is sent, with a notice', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SUNDAY = await dayOf(page, 'sun'), FAR = addDays(SUNDAY, 30);
    await page.locator('.evb-edit-btn').click();
    await expect(page.locator('.evb-modebar')).toBeVisible();
    const batches = countBatches(page);
    const before = await page.evaluate(() => (window as any).EvBoardEdit.store.items().length);
    const cid = await page.evaluate(([t, day]) => (window as any).EvBoardEdit.store.add({ Title: t, StartTime: `${day} 09:00`,
      EndTime: `${day} 10:00`, Category: 'Other', SecondaryCategory: '', Location: '', Description: '', Leads: [] }), [`${PREFIX} far away`, FAR]);
    expect(cid).toBeNull();
    await expect(page.locator('.evb-edit-head .evb-notice')).toContainText('That time is outside the event.');
    expect(await page.evaluate(() => (window as any).EvBoardEdit.store.items().length)).toBe(before);
    await page.waitForTimeout(500);
    expect(batches()).toBe(0);
    expect(await serverItem(page, `${PREFIX} far away`)).toBeNull();
  });

  test('rails: four hours or more of Administrative is a 22px rail labelled like any block, and lanes ignore rails', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SUNDAY = await dayOf(page, 'sun');
    const t = (s: string) => `${PREFIX} ${s}`;
    const item = (title: string, cat: string, start: string, end: string) => ({ Title: title, StartTime: `${SUNDAY} ${start}`,
      EndTime: `${SUNDAY} ${end}`, Category: cat, SecondaryCategory: '', Location: '', Description: '', Leads: [] });
    const lanes = ['lane a', 'lane b', 'lane c', 'lane d'];
    const ids = await addViaBatch(page, [
      item(t('rail gate'), 'Administrative', '06:00', '10:00'),
      item(t('rail troll'), 'Administrative', '06:00', '11:00'),
      item(t('almost rail'), 'Administrative', '12:00', '15:59'),
      item(t(lanes[0]), 'Other', '06:15', '07:15'),
      item(t(lanes[1]), 'Other', '06:30', '07:30'),
      item(t(lanes[2]), 'Other', '06:45', '07:45'),
      item(t(lanes[3]), 'Other', '07:00', '08:00'),
    ]);
    try {
      await page.reload();
      await expect(page.locator('#evb-root')).toBeVisible();
      const [gate, troll, almost, laneA] = ids;
      const gateEl = page.locator(`.evb-block[data-id="${gate}"]`);
      await expect(gateEl).toHaveClass(/evb-block-rail/);
      await expect(page.locator(`.evb-block[data-id="${troll}"]`)).toHaveClass(/evb-block-rail/);
      expect(Math.round((await gateEl.boundingBox())!.width)).toBe(22);
      // The same label form as an ordinary block: title, category, day and time.
      await expect(gateEl).toHaveAttribute('aria-label', `${t('rail gate')}, Administrative, Sunday 6–10a`);
      await expect(gateEl).toHaveAttribute('role', 'button');
      await expect(page.locator(`.evb-block[data-id="${laneA}"]`)).toHaveAttribute('aria-label', `${t(lanes[0])}, Other, Sunday 6:15–7:15a`);
      // 3h59m is not a rail.
      const almostEl = page.locator(`.evb-block[data-id="${almost}"]`);
      await expect(almostEl).not.toHaveClass(/evb-block-rail/);
      expect((await almostEl.boundingBox())!.width).toBeGreaterThan(22);

      // The board's model: two rails, and the four overlapping items in four lanes (not six).
      const model = await page.evaluate(([day, laneTitles, almostTitle]) => {
        const d = (window as any).EvBoardView.board.days.find((x: any) => x.day === day);
        return {
          rails: d.rails.map((s: any) => [s.item.Title, s.rail]),
          railCount: d.railCount,
          lanes: d.segs.filter((s: any) => (laneTitles as string[]).includes(s.item.Title)).map((s: any) => s.lane).sort(),
          almostInSegs: d.segs.some((s: any) => s.item.Title === almostTitle),
        };
      }, [SUNDAY, lanes.map(t), t('almost rail')] as [string, string[], string]);
      expect(model.rails).toEqual(expect.arrayContaining([[t('rail gate'), expect.any(Number)], [t('rail troll'), expect.any(Number)]]));
      expect(model.railCount).toBeGreaterThanOrEqual(2);
      expect(model.lanes).toEqual([0, 1, 2, 3]);
      expect(model.almostInSegs).toBe(true);

      // Scrolled past the rail's top, its title stays in the visible part of the board's frame.
      const seen = async () => page.evaluate(id => {
        const sc = document.querySelector('.evb-scroller') as HTMLElement;
        const rail = document.querySelector(`.evb-block[data-id="${id}"]`) as HTMLElement;
        const title = rail.querySelector('.evb-block-title')!.getBoundingClientRect();
        const s = sc.getBoundingClientRect(), r = rail.getBoundingClientRect();
        const head = (rail.closest('.evb-day')!.querySelector('.evb-head') as HTMLElement | null)?.getBoundingClientRect();
        return {
          railAbove: r.top < (head ? head.bottom : s.top),
          titleVisible: title.height > 0 && title.bottom > s.top && title.top < s.bottom && title.right > s.left && title.left < s.right,
          belowHead: !head || title.top >= head.bottom - 1,
        };
      }, troll);
      await page.evaluate(id => {
        const sc = document.querySelector('.evb-scroller') as HTMLElement;
        const rail = document.querySelector(`.evb-block[data-id="${id}"]`) as HTMLElement;
        sc.scrollTop = parseFloat(rail.style.top) + 150;
      }, troll);
      await expect.poll(seen).toEqual({ railAbove: true, titleVisible: true, belowHead: true });
    } finally {
      expect(await removeIds(page, ids)).toBe(0);
    }
  });

  test('now and next: shown above the board and the list during the event, opens the popover, hidden outside it and in Edit mode', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const FRIDAY = await dayOf(page, 'fri'), SUNDAY = await dayOf(page, 'sun');
    const t = (s: string) => `${PREFIX} ${s}`;
    const item = (title: string, start: string, end: string) => ({ Title: title, StartTime: `${FRIDAY} ${start}`,
      EndTime: `${FRIDAY} ${end}`, Category: 'Other', SecondaryCategory: '', Location: '', Description: '', Leads: [] });
    const ids = await addViaBatch(page, [
      item(t('nn now'), '05:15', '06:30'), item(t('nn next a'), '05:45', '06:00'), item(t('nn next b'), '06:00', '06:45'),
    ]);
    try {
      await page.reload();
      await expect(page.locator('#evb-root')).toBeVisible();
      const at = `${FRIDAY} 05:30`, dayEnd = `${addDays(FRIDAY, 1)} 05:00`;
      await page.evaluate(a => (window as any).EvBoardView.setClock(() => a), at);
      const strip = page.locator('.evb-nownext');
      await expect(strip).toBeVisible();

      // What the server holds at that minute: on now (started, not ended), and the next three to start that board day.
      const rows = (await serverRows(page)).map(r => ({ title: String(r.Title), s: String(r.StartTime).slice(0, 16), e: String(r.EndTime).slice(0, 16) }));
      const wantNow = rows.filter(r => r.s <= at && at < r.e).map(r => r.title).sort();
      const wantNext = rows.filter(r => r.s > at && r.s < dayEnd)
        .sort((a, b) => a.s.localeCompare(b.s) || (a.title < b.title ? -1 : a.title > b.title ? 1 : 0)).slice(0, 3).map(r => r.title);
      expect(wantNow).toContain(t('nn now'));
      expect(wantNext).toEqual(expect.arrayContaining([t('nn next a'), t('nn next b')]));
      const titles = (group: string) => strip.locator(`.evb-nownext-${group} .evb-nownext-title`).allInnerTexts();
      expect((await titles('now')).sort()).toEqual(wantNow);
      expect(await titles('next')).toEqual(wantNext);
      const nowEntry = strip.locator(`.evb-nownext-item[data-id="${ids[0]}"]`);
      await expect(nowEntry.locator('.evb-nownext-when')).toHaveText('until 6:30a');
      await expect(strip.locator(`.evb-nownext-item[data-id="${ids[1]}"] .evb-nownext-when`)).toHaveText('at 5:45a');

      // Above the board, and above the list.
      const above = (other: string) => page.evaluate(sel => {
        const s = document.querySelector('.evb-nownext')!, o = document.querySelector(sel)!;
        return !!(s.compareDocumentPosition(o) & Node.DOCUMENT_POSITION_FOLLOWING) &&
          s.getBoundingClientRect().bottom <= o.getBoundingClientRect().top + 0.5;
      }, other);
      expect(await above('#evb-root')).toBe(true);
      await page.locator('[data-ev-view="list"]').click();
      await expect(page.locator('#ev-schedule-container')).toBeVisible();
      await expect(strip).toBeVisible();
      expect(await above('#ev-schedule-container')).toBe(true);
      await page.locator('[data-ev-view="board"]').click();
      await expect(page.locator('#evb-root')).toBeVisible();

      // An entry opens its item's popover next to it; Escape gives the entry focus back.
      await nowEntry.click();
      const pop = page.locator('.evb-popover');
      await expect(pop).toContainText(t('nn now'));
      const [e, p] = [(await nowEntry.boundingBox())!, (await pop.boundingBox())!];
      const besideIt = Math.abs(p.y - (e.y + e.height + 6)) <= 1.5 || Math.abs(e.y - (p.y + p.height + 6)) <= 1.5;
      expect(besideIt).toBe(true);
      expect(p.x < e.x + e.width && p.x + p.width > e.x).toBe(true);
      await page.keyboard.press('Escape');
      await expect(pop).toHaveCount(0);
      await expect(nowEntry).toBeFocused();

      // Outside the event: no strip.
      await page.evaluate(a => (window as any).EvBoardView.setClock(() => a), `${addDays(SUNDAY, 30)} 12:00`);
      await expect(strip).toBeHidden();
      // In Edit mode: no strip, even with the clock inside the event.
      await page.evaluate(a => (window as any).EvBoardView.setClock(() => a), at);
      await expect(strip).toBeVisible();
      await page.locator('.evb-edit-btn').click();
      await expect(page.locator('.evb-modebar')).toBeVisible();
      await expect(strip).toBeHidden();
      await page.evaluate(a => (window as any).EvBoardView.setClock(() => a), at);
      await page.evaluate(() => new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r))));
      expect(await strip.isVisible()).toBe(false);
    } finally {
      expect(await removeIds(page, ids)).toBe(0);
    }
  });

  test('an add the server refuses at the item ceiling is undone alone, with the server\'s message', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await expect(page.locator('.evb-quickline .evb-entry-input')).toBeFocused();
    const one = `${PREFIX} cap one`, two = `${PREFIX} cap two`;
    const MESSAGE = 'A schedule can hold at most 500 items.';
    // One reply, mocked: the server refuses the second add at its own index. Every
    // other request goes through.
    let refused: any[] | null = null;
    await page.route(/schedule_batch/, async route => {
      const ops = JSON.parse(formField(route.request().postData() || '', 'Ops') || '[]');
      const at = ops.findIndex((o: any) => o.op === 'add' && o.item && o.item.Title === two);
      if (refused || at < 0) return route.continue();
      refused = ops;
      return route.fulfill({ contentType: 'application/json', body: JSON.stringify({ status: 1, error: MESSAGE, failedIndex: at }) });
    });
    await pasteText(page, ['Saturday', `6a ${one}`, `6:30a ${two}`].join('\n'));
    await expect(page.locator('.evb-bulkbar')).toContainText('2 items ready');
    await page.locator('.evb-bulk-add').click();
    await expect(page.locator('.evb-edit-head .evb-notice')).toContainText(`Couldn’t save “${two}”: ${MESSAGE.replace(/\.$/, '')}. It was undone.`);
    expect(refused).not.toBeNull();
    expect(refused!.map((o: any) => [o.op, o.item.Title])).toEqual([['add', one], ['add', two]]);
    await expect(page.locator('.evb-block:not(.evb-ghost)', { hasText: two })).toHaveCount(0);
    await expect(page.locator('.evb-block:not(.evb-ghost)', { hasText: one })).toHaveCount(1);
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    expect(await storeItem(page, two)).toBeNull();
    // The other add was sent again and saved; the refused one never reached the server.
    await expect.poll(async () => (await serverItems(page, one)).length).toBe(1);
    expect(await serverItem(page, two)).toBeNull();
    await page.unroute(/schedule_batch/);
  });

  test('the schedule form keeps the board\'s rules: a refused save shows why and saves nothing, a valid one saves', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SATURDAY = await dayOf(page, 'sat'), SUNDAY = await dayOf(page, 'sun');
    const title = `${PREFIX} form rules`;
    const [id] = await addViaBatch(page, [{ Title: title, StartTime: `${SATURDAY} 16:45`, EndTime: `${SATURDAY} 17:15`,
      Category: 'Other', SecondaryCategory: '', Location: '', Description: '', Leads: [] }]);
    await page.reload();
    await expect(page.locator('#evb-root')).toBeVisible();
    const original = { StartTime: `${SATURDAY} 16:45`, EndTime: `${SATURDAY} 17:15`, Category: 'Other', Leads: [] };
    expect(await serverItem(page, title)).toEqual(original);
    const posted: string[] = [];
    page.on('request', r => { if (r.url().includes('update_schedule')) posted.push(r.postData() || ''); });

    // Opened from the item's popover.
    await page.locator(`.evb-block[data-id="${id}"]`).click();
    await page.locator('.evb-popover .evb-popover-edit').click();
    const modal = page.locator('#ev-schedule-modal');
    await expect(modal).toBeVisible();
    await expect(page.locator('#ev-sched-title')).toHaveValue(title);
    const error = page.locator('#ev-sched-error');
    const pick = (sel: string, at: string) => page.evaluate(([s, a]) => (document.querySelector(s) as any)._flatpickr.setDate(a, true), [sel, at]);

    // An end before the start: refused with the rule's message (the form says it before
    // sending, in the server's own words), and nothing is saved.
    await pick('#ev-sched-start', `${SATURDAY} 17:00`);
    await pick('#ev-sched-end', `${SATURDAY} 16:00`);
    await page.locator('#ev-sched-save-btn').click();
    await expect(error).toBeVisible();
    await expect(error).toHaveText('End time cannot be before start time.');
    await expect(modal).toBeVisible();
    expect(posted).toHaveLength(0);
    // The server gives the form's path the same message for the same times, and saves nothing.
    const direct = await page.evaluate(async ([sid, t, s, e]) => {
      const c = (window as any).EvConfig, fd = new FormData();
      Object.entries({ ScheduleId: sid, Title: t, StartTime: s, EndTime: e, Category: 'Other', SecondaryCategory: '',
        Location: '', Description: '', Leads: '[]' }).forEach(([k, v]) => fd.append(k, String(v)));
      return (await fetch(c.uir + 'EventAjax/update_schedule/' + c.eventId + '/' + c.detailId, {
        method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' },
      })).json();
    }, [String(id), title, `${SATURDAY} 17:00`, `${SATURDAY} 16:00`]);
    expect([direct.status === 0, direct.error]).toEqual([false, 'End time cannot be before start time.']);
    expect(await serverItem(page, title)).toEqual(original);
    posted.length = 0;

    // A start outside the event's window (typed past the picker's days, as the form
    // takes it without Flatpickr): the server refuses, the form shows its message.
    await page.evaluate(([s, e]) => {
      (document.getElementById('ev-sched-start') as HTMLInputElement).value = s;
      (document.getElementById('ev-sched-end') as HTMLInputElement).value = e;
    }, [`${addDays(SUNDAY, 8)}T10:00`, `${addDays(SUNDAY, 8)}T11:00`]);
    const refused = page.waitForResponse(r => r.url().includes('update_schedule'));
    await page.locator('#ev-sched-save-btn').click();
    expect((await (await refused).json()).status).not.toBe(0);
    await expect(error).toHaveText('That time is outside the event.');
    await expect(modal).toBeVisible();
    expect(posted).toHaveLength(1);
    expect(await serverItem(page, title)).toEqual(original);

    // A valid save goes through.
    await pick('#ev-sched-start', `${SATURDAY} 18:00`);
    await pick('#ev-sched-end', `${SATURDAY} 19:00`);
    await page.locator('#ev-sched-location').fill('Rules Hall');
    const saved = page.waitForResponse(r => r.url().includes('update_schedule'));
    await page.locator('#ev-sched-save-btn').click();
    expect((await (await saved).json()).status).toBe(0);
    await expect(modal).toBeHidden();
    expect(await serverItem(page, title)).toEqual({ ...original, StartTime: `${SATURDAY} 18:00`, EndTime: `${SATURDAY} 19:00` });
    expect((await serverRows(page)).find(r => +r.EventScheduleId === id).Location).toBe('Rules Hall');
  });

  test('a schedule manager\'s popover on a feast item is unchanged: one Edit, to the full form', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    expect(await page.evaluate(() => !!(window as any).EvConfig.canManageSchedule)).toBe(true);
    const SUNDAY = await dayOf(page, 'sun');
    const feast = `${PREFIX} manager feast`, other = `${PREFIX} manager other`;
    const [feastId, otherId] = await addViaBatch(page, [
      { Title: feast, StartTime: `${SUNDAY} 17:30`, EndTime: `${SUNDAY} 18:00`, Category: 'Feast and Food', SecondaryCategory: '',
        Location: '', Description: '', Leads: [] },
      { Title: other, StartTime: `${SUNDAY} 18:00`, EndTime: `${SUNDAY} 18:30`, Category: 'Other', SecondaryCategory: '',
        Location: '', Description: '', Leads: [] },
    ]);
    await page.reload();
    await expect(page.locator('#evb-root')).toBeVisible();
    const pop = page.locator('.evb-popover');
    // The popover's controls, by class, for each item.
    const controls = async (id: number) => {
      await page.locator(`.evb-block[data-id="${id}"]`).click();
      await expect(pop).toHaveCount(1);
      const list = await pop.locator('button, a[href]').evaluateAll(els => els.map(e => e.className || e.tagName));
      await page.keyboard.press('Escape');
      await expect(pop).toHaveCount(0);
      return list;
    };
    const onFeast = await controls(feastId);
    expect(onFeast.filter(c => c.includes('evb-popover-edit'))).toHaveLength(1);
    expect(onFeast).toEqual(await controls(otherId));

    // Its Edit opens the full form on that item, categories free.
    await page.locator(`.evb-block[data-id="${feastId}"]`).click();
    await pop.locator('.evb-popover-edit').click();
    await expect(page.locator('#ev-schedule-modal')).toBeVisible();
    await expect(page.locator('#ev-sched-title')).toHaveValue(feast);
    await expect(page.locator('#ev-sched-mode')).toHaveValue('edit');
    await expect(page.locator('#ev-sched-category')).toHaveValue('Feast and Food');
    await expect(page.locator('#ev-sched-category')).toBeEnabled();
    await expect(page.locator('#ev-sched-secondary-category')).toBeEnabled();
    await expect(page.locator('#ev-sched-cat-locked')).toHaveCount(0);
    await page.locator('#ev-schedule-modal .ev-modal-close').click();
    await expect(page.locator('#ev-schedule-modal')).toBeHidden();
  });
});
