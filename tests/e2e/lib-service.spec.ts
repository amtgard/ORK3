/**
 * Ork3::$Lib service migration frontend flows (T-14 / DS-14 §2.3).
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

test('era phoenice today endpoint responds', async ({ request, baseURL }) => {
  const res = await request.get(`${baseURL}/index.php?Route=EraPhoenice/today`);
  expect(res.status()).toBeLessThan(500);
});

test.describe('authenticated lib-service flows', () => {
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

  test('live stats route responds after login', async ({ page }) => {
    const response = await page.goto('./index.php?Route=Live/stats');
    expect(response?.status()).toBeLessThan(500);
  });

  test('weather route responds after login', async ({ page }) => {
    const response = await page.goto('./index.php?Route=Weather');
    expect(response?.status()).toBeLessThan(500);
  });

  test('tournament page loads for logged-in user', async ({ page }) => {
    const response = await page.goto('./index.php?Route=Tournament');
    expect(response?.status()).toBeLessThan(500);
  });
});
