/**
 * Event Schedule Board: the all-days board on the event page's Schedule tab.
 *
 * Set ORK3_E2E_SCHEDULE_EVENT to "eventId/detailId" of a published event with a
 * schedule; the tests skip without it. Signed-out viewer unless noted.
 */
import { test, expect } from '@playwright/test';

const EVENT = process.env.ORK3_E2E_SCHEDULE_EVENT ?? '';

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
