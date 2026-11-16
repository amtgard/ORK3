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
    await page.evaluate(() => {
      (document.getElementById('ev-sched-start') as any)._flatpickr.setDate('2026-11-21 10:00', true);
      (document.getElementById('ev-sched-end') as any)._flatpickr.setDate('2026-11-21 11:00', true);
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
