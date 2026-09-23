/**
 * Admin dashboard frontend flows (T-08 / DS-08 §2.3).
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

test('admin index route loads', async ({ page }) => {
  await page.goto('./index.php?Route=Admin');
  await expect(page.locator('body')).toBeVisible();
});

test.describe('authenticated admin flows', () => {
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

  test('admin dashboard loads after login', async ({ page }) => {
    await page.goto('./index.php?Route=Admin');
    await expect(page.locator('body')).toBeVisible();
  });

  test('state of amtgard route responds after login', async ({ page }) => {
    const response = await page.goto('./index.php?Route=Admin/stateofamtgard');
    expect(response?.status()).toBeLessThan(500);
  });
});
