/**
 * Residual Ork3::$Lib bypass frontend flows (T-19 / DS-19 §2.2).
 *
 * Covers R-19a…d hop surfaces before production lib removal.
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

test('health probe returns OK body when DB is up', async ({ request, baseURL }) => {
  const res = await request.get(`${baseURL}/index.php?Route=Health`);
  expect(res.status()).toBeLessThan(500);
  const body = await res.text();
  if (res.status() === 200) {
    expect(body.trim()).toBe('OK');
  }
});

test.describe('authenticated residual-lib flows', () => {
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

  test('kingdom scoped player search ajax responds', async ({ page }) => {
    const response = await page.goto('./index.php?Route=KingdomAjax/playersearch/1&q=test');
    expect(response?.status()).toBeLessThan(500);
  });

  test('username availability ajax returns JSON', async ({ page }) => {
    const response = await page.request.get('./index.php?Route=PlayerAjax/check_username&username=t19_e2e_probe');
    expect(response.status()).toBeLessThan(500);
    const json = await response.json();
    expect(json).toHaveProperty('available');
    expect(json).toHaveProperty('status', 0);
  });

  test('whats new dismiss ajax responds', async ({ page }) => {
    const response = await page.request.post('./index.php?Route=WnAjax/dismiss', {
      form: { version: 't19-e2e-version' },
    });
    expect(response.status()).toBeLessThan(500);
  });

  test('universal search ajax responds', async ({ page }) => {
    const response = await page.goto('./index.php?Route=SearchAjax/universal&q=test');
    expect(response?.status()).toBeLessThan(500);
  });

  test('admin state of amtgard bootstrap route responds', async ({ page }) => {
    const response = await page.goto('./index.php?Route=Admin/stateofamtgard');
    expect(response?.status()).toBeLessThan(500);
  });

  test('admin weather stats ajax responds', async ({ page }) => {
    const response = await page.request.get('./index.php?Route=Admin/serverhealth_weather_stats');
    expect(response.status()).toBeLessThan(500);
    const json = await response.json();
    expect(json).toHaveProperty('status', 0);
    expect(json).toHaveProperty('stats');
  });
});
