/**
 * Attendance/sign-in frontend flows (T-12 / DS-12 §2.3).
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

test('attendance park route responds', async ({ page }) => {
  const response = await page.goto('./index.php?Route=Attendance');
  expect(response?.status()).toBeLessThan(500);
});

test.describe('authenticated attendance flows', () => {
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
    await page.waitForURL(/Player\/profile/, { timeout: 30_000 });
  });

  test('park attendance ajax getday responds', async ({ page }) => {
    const today = new Date().toISOString().slice(0, 10);
    const response = await page.goto(`./index.php?Route=AttendanceAjax/park/1/getday&date=${today}`);
    expect(response?.status()).toBeLessThan(500);
  });

  test('sign-in route responds for invalid token shape', async ({ page }) => {
    const response = await page.goto('./index.php?Route=SignIn/index/abc');
    expect(response?.status()).toBeLessThan(500);
  });

  test('qr link endpoint rejects short token', async ({ page }) => {
    const response = await page.goto('./index.php?Route=QR/link/abc');
    expect(response?.status()).toBeLessThan(500);
    const body = await response?.json();
    expect(body?.status).toBe(1);
  });
});
