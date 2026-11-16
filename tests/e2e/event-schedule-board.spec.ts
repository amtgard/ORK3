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
// remove button (it still asks with a native confirm, accepted here).
async function removeItemsTitled(page: Page, prefix: string) {
  await page.locator('[data-ev-view="list"]').click();
  for (;;) {
    const row = page.locator('#ev-schedule-container tr[id^="ev-schedule-row-"]')
      .filter({ has: page.locator(`td:has-text("${prefix}")`) }).first();
    if (await row.count() === 0) break;
    const id = await row.getAttribute('data-schedule-id');
    page.once('dialog', d => d.accept());
    await row.locator('.ev-del-link').click();
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

  // Removes every item this run created, through the batch endpoint.
  test.afterAll(async ({ browser }) => {
    if (!ready) return;
    const page = await browser.newPage();
    await signIn(page);
    await openBoard(page, EDIT_EVENT);
    const left = await page.evaluate(async prefix => {
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
    expect(left).toBe(0);
    await page.close();
  });

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
});
