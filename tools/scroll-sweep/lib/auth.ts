import type { Page } from '@playwright/test';

/**
 * Mirrors tools/fuzzy-validator/playwright/lib/auth.ts — one login flow for the repo.
 *
 * The sweep drives Scrollery/compose and ScrolleryAjax/preview, both of which gate on
 * an officer with edit rights over the issuing body. It logs in as a real user rather
 * than reaching past the gate, so the harness measures the page an officer is served.
 */
export function hasAuthCredentials(): boolean {
  return Boolean(process.env.ORK3_E2E_USERNAME && process.env.ORK3_E2E_PASSWORD);
}

export async function login(page: Page): Promise<void> {
  const user = process.env.ORK3_E2E_USERNAME;
  const pass = process.env.ORK3_E2E_PASSWORD;
  if (!user || !pass) {
    throw new Error('ORK3_E2E_USERNAME and ORK3_E2E_PASSWORD are required for the scroll sweep');
  }
  await page.goto('./index.php?Route=Login/login');
  await page.fill('input[name="username"]', user);
  await page.fill('input[name="password"]', pass);
  await page.click('button[type="submit"], input[type="submit"]');
  await page.waitForLoadState('networkidle');
}
