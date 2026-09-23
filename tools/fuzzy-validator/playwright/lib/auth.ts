import type { Page } from '@playwright/test';

export function hasAuthCredentials(): boolean {
  return Boolean(process.env.ORK3_E2E_USERNAME && process.env.ORK3_E2E_PASSWORD);
}

/** Mirrors tests/e2e login flow (infrastructure.spec.ts). */
export async function login(page: Page): Promise<void> {
  const user = process.env.ORK3_E2E_USERNAME;
  const pass = process.env.ORK3_E2E_PASSWORD;
  if (!user || !pass) {
    throw new Error('ORK3_E2E_USERNAME and ORK3_E2E_PASSWORD are required for login capture');
  }

  await page.goto('./index.php?Route=Login');
  await page.fill('input[name="username"]', user);
  await page.fill('input[name="password"]', pass);
  await page.click('button[type="submit"], input[type="submit"]');
  await page.waitForLoadState('networkidle');
}
