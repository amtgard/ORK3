import { test, expect } from '@playwright/test';
import { hasAuthCredentials, login } from '../lib/auth';

// The composer half of the typeset work: the issuer line splits as print splits it, and the
// heading choice is a per-print control that reaches the print URL. The composer is behind
// an officer login, so this skips without credentials and the controller runs it with them.
test.skip(!hasAuthCredentials(), 'needs ORK3_E2E_USERNAME and ORK3_E2E_PASSWORD (the composer is behind a login)');

const KINGDOM = Number(process.env.ORK3_SWEEP_KINGDOM ?? 1);

test.beforeEach(async ({ page }) => {
  await login(page);
  await page.goto(`./index.php?Route=Scrollery/create/kingdom/${KINGDOM}`, { waitUntil: 'load' });
  await page.waitForSelector('#sc-mount #IssuerTitle', { state: 'attached' });
});

test('typing a kingdom name splits it like print', async ({ page }) => {
  // The composer prefills the scope's own title and flags; clear them so the typed name is
  // the stored kingdom shape, one whole phrase with nothing ticked.
  await page.fill('#sc-issuer-title', '');
  await page.setChecked('#sc-use-the', false);
  await page.setChecked('#sc-use-of', false);
  await page.fill('#sc-issuer-name', 'The Kingdom of the Wetlands');

  await expect(page.locator('#IssuerTitle')).toHaveText('and the Kingdom of');
  const place = page.locator('[data-scroll-text="issuerName"]');
  await expect(place).toHaveText('the Wetlands');
  expect(await place.innerHTML()).toContain('<span class="sc-art">the</span> Wetlands');
});

test('the issuer name is escaped, not parsed as markup', async ({ page }) => {
  await page.fill('#sc-issuer-title', '');
  await page.setChecked('#sc-use-the', false);
  await page.setChecked('#sc-use-of', false);
  await page.fill('#sc-issuer-name', '<b>Wet</b> & lands');

  const place = page.locator('[data-scroll-text="issuerName"]');
  await expect(place).toHaveText('<b>Wet</b> & lands');
  expect(await place.locator('b').count()).toBe(0);
});

test('the heading choice stamps the stage and is not remembered', async ({ page }) => {
  await expect(page.locator('#sc-stage')).toHaveAttribute('data-sc-heading', 'auto');
  await page.selectOption('#sc-heading', 'centered');
  await expect(page.locator('#sc-stage')).toHaveAttribute('data-sc-heading', 'centered');

  // Per print: a reload opens on Automatic, and nothing was written to localStorage.
  expect(await page.evaluate(() => Object.keys(localStorage).filter(k => /head/i.test(k)))).toEqual([]);
  await page.reload({ waitUntil: 'load' });
  await expect(page.locator('#sc-heading')).toHaveValue('auto');
  await expect(page.locator('#sc-stage')).toHaveAttribute('data-sc-heading', 'auto');
});

test('the heading choice reaches the print URL', async ({ page }) => {
  // window.open is the whole hand-off to the print window; catch its URL rather than
  // following it, so this asserts what the composer built and not what print did with it.
  await page.evaluate(() => {
    (window as any).__opened = [];
    window.open = ((url: string) => { (window as any).__opened.push(String(url)); return null; }) as any;
  });
  await page.selectOption('#sc-heading', 'centered');
  await page.click('#sc-print');

  const opened: string[] = await page.evaluate(() => (window as any).__opened);
  expect(opened).toHaveLength(1);
  expect(opened[0]).toContain('Scrollery/compose');
  expect(new URLSearchParams(opened[0].split('&').slice(1).join('&')).get('heading')).toBe('centered');
});
