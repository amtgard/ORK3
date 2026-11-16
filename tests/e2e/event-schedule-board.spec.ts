/**
 * Event Schedule Board: the all-days board on the event page's Schedule tab.
 *
 * Viewer tests: set ORK3_E2E_SCHEDULE_EVENT to "eventId/detailId" of a published
 * event with a schedule; they run signed out and skip without it.
 *
 * Editor tests: set ORK3_E2E_USERNAME, ORK3_E2E_PASSWORD and ORK3_E2E_EDIT_EVENT
 * ("eventId/detailId" of an event that account can manage); they skip without them.
 */
import { test, expect, type Page } from '@playwright/test';

const EVENT = process.env.ORK3_E2E_SCHEDULE_EVENT ?? '';

test.describe('viewer', () => {
  test.beforeEach(async ({}, testInfo) => {
    testInfo.skip(!EVENT, 'ORK3_E2E_SCHEDULE_EVENT is not set');
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
      const day = b.days[0].day, min = b.axisStart + 30;
      w.EvConfig.serverNow = day + ' ' + String(Math.floor(min / 60)).padStart(2, '0') + ':' + String(min % 60).padStart(2, '0');
      document.querySelectorAll('.evb-now').forEach(e => e.remove());
      document.dispatchEvent(new Event('visibilitychange'));
      return !!document.querySelector(`.evb-day-body[data-day="${day}"] .evb-now`);
    });
    expect(drawn).toBe(true);
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

// Removes every list item whose title starts with prefix, through the list's own
// remove button and its themed confirm dialog.
async function removeItemsTitled(page: Page, prefix: string) {
  await page.locator('[data-ev-view="list"]').click();
  for (;;) {
    const row = page.locator('#ev-schedule-container tr[id^="ev-schedule-row-"]')
      .filter({ has: page.locator(`td:has-text("${prefix}")`) }).first();
    if (await row.count() === 0) break;
    const id = await row.getAttribute('data-schedule-id');
    await row.locator('.ev-del-link').click();
    await page.locator('#ork-confirm-overlay.ork-confirm-open #ork-confirm-ok').click();
    await expect(page.locator(`#ev-schedule-container tr[data-schedule-id="${id}"]`)).toHaveCount(0);
  }
}

test.describe('editor paths (phase 1)', () => {
  const EDIT_EVENT = process.env.ORK3_E2E_EDIT_EVENT ?? '';
  const PREFIX = 'E2E ';
  const ready = !!(process.env.ORK3_E2E_USERNAME && process.env.ORK3_E2E_PASSWORD && EDIT_EVENT);

  test.beforeEach(async ({}, testInfo) => {
    testInfo.skip(!ready, 'ORK3_E2E_USERNAME, ORK3_E2E_PASSWORD and ORK3_E2E_EDIT_EVENT are not all set');
  });

  test.afterAll(async ({ browser }) => {
    if (!ready) return;
    const page = await browser.newPage();
    await signIn(page);
    await openBoard(page, EDIT_EVENT);
    await removeItemsTitled(page, PREFIX);
    await page.close();
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
    await block.click();
    await expect(page.locator('.evb-popover')).toContainText(title);
    await expect(page.locator('.evb-popover .evb-popover-edit')).toHaveCount(1);
    await page.locator('.evb-popover .evb-popover-edit').click();
    await expect(page.locator('#ev-schedule-modal')).toBeVisible();
    await expect(page.locator('#ev-sched-title')).toHaveValue(title);
    await page.locator('#ev-schedule-modal .ev-modal-close').click();
    await expect(page.locator('#ev-schedule-modal')).toBeHidden();

    await removeItemsTitled(page, title);
    await page.locator('[data-ev-view="board"]').click();
    await expect(page.locator('.evb-block', { hasText: title })).toHaveCount(0);
  });
});

test.describe('editing', () => {
  const EDIT_EVENT = process.env.ORK3_E2E_EDIT_EVENT ?? '';
  const PREFIX = `E2E ${Date.now()}`;
  const ready = !!(process.env.ORK3_E2E_USERNAME && process.env.ORK3_E2E_PASSWORD && EDIT_EVENT);

  test.beforeEach(async ({ page }, testInfo) => {
    testInfo.skip(!ready, 'ORK3_E2E_USERNAME, ORK3_E2E_PASSWORD and ORK3_E2E_EDIT_EVENT are not all set');
    await signIn(page);
  });

  // Adds an item from the quick line (Edit mode on) and waits for it to save.
  async function addViaQuickLine(page: Page, text: string) {
    await quickAdd(page, text);
  }
  // The stored item's [StartTime, EndTime], seconds trimmed.
  async function timesOf(page: Page, title: string): Promise<string[]> {
    return page.evaluate(t => {
      const it = (window as any).EvBoardEdit.store.items().find((i: any) => i.Title === t);
      return it ? [it.StartTime, it.EndTime].map((v: string) => String(v).slice(0, 16)) : [];
    }, title);
  }

  // Removes every item this run created, through the batch endpoint.
  test.afterAll(async ({ browser }) => {
    if (!ready) return;
    const page = await browser.newPage();
    let left: number | string = 'not run';
    let cleared = 'not run';
    try {
      await signIn(page);
      await openBoard(page, EDIT_EVENT);
      left = await page.evaluate(async prefix => {
        const c = (window as any).EvConfig;
        const base = c.uir + 'EventAjax/';
        const path = c.eventId + '/' + c.detailId;
        const opts = { credentials: 'same-origin' as RequestCredentials, headers: { 'X-Requested-With': 'XMLHttpRequest' } };
        const list = await (await fetch(base + 'schedule_list/' + path, opts)).json();
        const ops = (list.schedule || []).filter((it: any) => String(it.Title).startsWith(prefix))
          .map((it: any) => ({ op: 'remove', id: it.EventScheduleId }));
        if (!ops.length) return 0;
        const fd = new FormData();
        fd.append('Ops', JSON.stringify(ops));
        const res = await (await fetch(base + 'schedule_batch/' + path, Object.assign({ method: 'POST', body: fd }, opts))).json();
        return res.status === 0 ? 0 : ops.length;
      }, PREFIX);
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
    await expect(page.locator('.evb-chip[data-part="location"]')).toContainText('main field');
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
  // The occurrence's last day (YYYY-MM-DD).
  async function lastDay(page: Page): Promise<string> {
    return page.evaluate(() => String((window as any).EvConfig.eventEnd).slice(0, 10));
  }
  // The day before the occurrence's last day (YYYY-MM-DD).
  async function dayBeforeLast(page: Page): Promise<string> {
    return page.evaluate(() => {
      const [y, m, d] = String((window as any).EvConfig.eventEnd).slice(0, 10).split('-').map(Number);
      return new Date(Date.UTC(y, m - 1, d - 1)).toISOString().slice(0, 10);
    });
  }

  test('click, type, Enter, type, Enter makes a run of items', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SUNDAY = await lastDay(page);
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
    const SUNDAY = await lastDay(page);
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
    const SUNDAY = await lastDay(page);
    await page.locator('.evb-edit-btn').click();
    const before = await page.locator('.evb-block').count();
    await clickSlot(page, SUNDAY, '15:00');
    await page.locator('.evb-block-editing .evb-entry-input').press('Escape');
    expect(await page.locator('.evb-block').count()).toBe(before);
  });

  test('dragging sets the length; clicking into the quick line keeps what was typed', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SUNDAY = await lastDay(page);
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
    const SUNDAY = await lastDay(page);
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
    const SATURDAY = await dayBeforeLast(page);
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
    expect(start.startsWith('2026-11-22 00:30')).toBe(true);
    await expect(page.locator(`.evb-block[data-day="${SATURDAY}"]`, { hasText: `${PREFIX} wee watch` })).toHaveCount(1);
  });

  test('nothing is added once Done is leaving', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SUNDAY = await lastDay(page);
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
    await page.getByRole('button', { name: 'Add Schedule Item' }).click();
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
  const isFocused = (loc: ReturnType<Page['locator']>) => loc.evaluate(el => document.activeElement === el);

  test('renaming leaves an untouched multi-line description and odd location alone', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SUNDAY = await lastDay(page);
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
    for (let i = 0; i < 60 && !(await isFocused(two)); i++) await page.keyboard.press('Tab');
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
    const SATURDAY = await dayBeforeLast(page);
    const SUNDAY = await lastDay(page);
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
    const SUNDAY = await lastDay(page);
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
    const SUNDAY = await lastDay(page);
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
    expect(after3).toEqual([true]);
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
    await page.evaluate(() => (window as any).evOpenScheduleModal());
    const leads = page.locator('#ev-sched-leads-list');
    await expect(leads).toContainText('None assigned');
    await page.locator('#ev-sched-category').selectOption('Battlegame');
    await expect(leads).toContainText('E2E Schedule Editor');
    await expect(leads).toContainText('auto');
    // Another category without leads empties the untouched list again.
    await page.locator('#ev-sched-category').selectOption('Class');
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
    expect(stored).toEqual([['E2E Schedule Editor', true]]);
  });

  test('an unresolved @word is kept as typed when you click away', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    const SATURDAY = await dayBeforeLast(page);
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
    expect(await autos()).toEqual([true]);
    const block = page.locator('.evb-block', { hasText: title });
    const modal = page.locator('#ev-schedule-modal');
    for (const [cat, want] of [['Court', []], ['Battlegame', [true]]] as const) {
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
    expect(await timesOf(page, `${PREFIX} drag me`)).toEqual(['2026-11-20 10:30', '2026-11-20 11:30']);
    await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
    await page.locator('.evb-undo').click();
    expect(await timesOf(page, `${PREFIX} drag me`)).toEqual(['2026-11-20 09:00', '2026-11-20 10:00']);
  });
  test('Escape cancels a drag; the bottom grip resizes', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
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
    expect(await timesOf(page, `${PREFIX} resize me`)).toEqual(['2026-11-20 13:00', '2026-11-20 14:00']);
    await block.click();
    const grip = (await block.locator('.evb-resize-bottom').boundingBox())!;
    await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2);
    await page.mouse.down();
    await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2 + 30 * pxPerMin, { steps: 5 });
    await page.mouse.up();
    expect(await timesOf(page, `${PREFIX} resize me`)).toEqual(['2026-11-20 13:00', '2026-11-20 14:30']);
  });
  test('arrow keys nudge the selected block', async ({ page }) => {
    await openBoard(page, EDIT_EVENT);
    await page.locator('.evb-edit-btn').click();
    await addViaQuickLine(page, `fri 3-4p ${PREFIX} nudge me`);
    await page.locator('.evb-block', { hasText: `${PREFIX} nudge me` }).click();
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Shift+ArrowDown');
    await page.keyboard.press('Alt+ArrowUp');
    await page.keyboard.press('ArrowRight');
    expect(await timesOf(page, `${PREFIX} nudge me`)).toEqual(['2026-11-21 15:10', '2026-11-21 16:25']);
  });
});
