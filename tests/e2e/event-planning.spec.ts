/**
 * EventAjax planning frontend flows (T-04 / DS-04 §2.4).
 *
 * Requires docker stack: docker compose -f docker-compose.php8.yml up -d
 * Set ORK3_E2E_USERNAME and ORK3_E2E_PASSWORD for authenticated flows.
 */
import { test, expect } from '@playwright/test';

async function appReachable(baseURL: string): Promise<boolean> {
  try {
    const res = await fetch(baseURL, { method: 'HEAD' });
    return res.ok || res.status === 302 || res.status === 405;
  } catch {
    return false;
  }
}

test.beforeEach(async ({ baseURL }, testInfo) => {
  if (!baseURL || !(await appReachable(baseURL))) {
    testInfo.skip(true, 'ORK3 app not reachable — start docker compose php8 stack');
  }
});

test('events index route loads', async ({ page }) => {
  await page.goto('./index.php?Route=Event');
  await expect(page.locator('body')).toBeVisible();
});

test.describe('authenticated event planning flows', () => {
  test.beforeEach(async ({ page }, testInfo) => {
    const user = process.env.ORK3_E2E_USERNAME;
    const pass = process.env.ORK3_E2E_PASSWORD;
    if (!user || !pass) {
      testInfo.skip(true, 'Set ORK3_E2E_USERNAME and ORK3_E2E_PASSWORD for login flows');
    }

    await page.goto('./index.php?Route=Login');
    await page.fill('input[name="username"]', user!);
    await page.fill('input[name="password"]', pass!);
    await page.click('button[type="submit"], input[type="submit"]');
    await page.waitForLoadState('networkidle');
  });

  test('kingdom events page loads after login', async ({ page }) => {
    await page.goto('./index.php?Route=Event/kingdom/1');
    await expect(page.locator('body')).toBeVisible();
  });

  test('park events page loads after login', async ({ page }) => {
    await page.goto('./index.php?Route=Event/park/1');
    await expect(page.locator('body')).toBeVisible();
  });
});
