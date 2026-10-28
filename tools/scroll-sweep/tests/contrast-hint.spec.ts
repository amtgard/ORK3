import { test, expect, type Page } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { hasAuthCredentials, login } from '../lib/auth';

// A5: a knot or ribbon colour that is close to the paper reads only through its outline.
// The builder says so under the control (advisory; nothing is blocked, nothing is printed).
test.skip(!hasAuthCredentials(), 'needs ORK3_E2E_USERNAME and ORK3_E2E_PASSWORD (the composer is behind a login)');

const KINGDOM = Number(process.env.ORK3_SWEEP_KINGDOM ?? 1);
const HINT = 'Low contrast on this background — this colour may be hard to see.';
const SHOTS = 'tools/scroll-sweep/out/a5-hint';
const LUM = JSON.parse(readFileSync('system/lib/ork3/data/scroll-ground-luminance.json', 'utf8')).grounds;

async function idle(page: Page) {
  await expect(page.locator('#sc-stage')).toHaveAttribute('aria-busy', 'false');
}

async function open(page: Page) {
  await login(page);
  await page.goto(`./index.php?Route=Scrollery/create/kingdom/${KINGDOM}`, { waitUntil: 'load' });
  await page.waitForSelector('#sc-mount .Award', { state: 'attached' });
}

async function pickOrder(page: Page, orders: string[], variant?: string) {
  await page.evaluate((list) => {
    const sel = document.getElementById('sc-award') as HTMLSelectElement;
    const i = Array.from(sel.options).findIndex(o => list.includes((o as HTMLOptionElement).dataset.order ?? ''));
    if (i < 0) { throw new Error('no award for ' + list.join(',')); }
    sel.selectedIndex = i;
    sel.dispatchEvent(new Event('change', { bubbles: true }));
  }, orders);
  await idle(page);
  if (variant) {
    await page.selectOption('#sc-variant', variant);
    await idle(page);
  }
}

async function openDesign(page: Page) {
  await page.click('#sc-design > .rp-filter-card-header');
}

async function ribbon(page: Page) {
  await openDesign(page);
  await page.setChecked('#sc-custom-ribbon', true);
}

const ribbonHint = (page: Page) => page.locator('#sc-ribbon-color-contrast');

test('a parchment-toned ribbon on a parchment order is flagged; a blue one is not', async ({ page }) => {
  await open(page);
  await pickOrder(page, ['lion', 'rose', 'crown']);
  await ribbon(page);
  await page.fill('#sc-ribbon-color-hex', '#F2E6C8');
  await expect(ribbonHint(page)).toBeVisible();
  await expect(ribbonHint(page)).toHaveText(HINT);
  await page.fill('#sc-ribbon-color-hex', '#1E4FA3');
  await expect(ribbonHint(page)).toBeHidden();
});

test('owl cyanotype: white is fine, a navy that matches the plate is flagged', async ({ page }) => {
  await open(page);
  await pickOrder(page, ['owl'], 'cyanotype');
  await ribbon(page);
  await page.fill('#sc-ribbon-color-hex', '#FFFFFF');
  await expect(ribbonHint(page)).toBeHidden();
  // #101820 is 1.61:1 against the cyanotype's 0.0444 mean luminance (above the 1.5 line);
  // #1A2A3A is the same blue family closer to the plate: 1.31:1.
  await page.fill('#sc-ribbon-color-hex', '#1A2A3A');
  await expect(ribbonHint(page)).toBeVisible();
});

test('the knot colours are judged too, and match-on near-black reads as exact', async ({ page }) => {
  await open(page);
  await pickOrder(page, ['owl'], 'cyanotype');
  await openDesign(page);
  await page.setChecked('#sc-custom-border', true);
  await idle(page);
  await page.setChecked('#sc-match-style', true);
  await idle(page);
  await page.fill('#sc-color1-hex', '#FFFFFF');
  await idle(page);
  await expect(page.locator('#sc-color1-contrast')).toBeHidden();
  await page.fill('#sc-color1-hex', '#1A2A3A');
  await idle(page);
  await expect(page.locator('#sc-match-fallback-hint')).toBeVisible();
  await expect(page.locator('#sc-color1-contrast')).toBeVisible();
  await expect(page.locator('#sc-color2-contrast')).toBeHidden();
});

test('the hint follows colour, order, variant and match live, without a reload', async ({ page }) => {
  await open(page);
  await pickOrder(page, ['lion', 'rose', 'crown']);
  await ribbon(page);
  const marker = await page.evaluate(() => { (window as any).__a5 = 1; return 1; });
  expect(marker).toBe(1);

  await page.fill('#sc-ribbon-color-hex', '#F2E6C8');
  await expect(ribbonHint(page)).toBeVisible();
  await page.locator('#sc-design-body').scrollIntoViewIfNeeded();
  await page.locator('#sc-custom-ribbon-colors').screenshot({ path: `${SHOTS}/hint-light.png` });
  await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
  await page.locator('#sc-custom-ribbon-colors').screenshot({ path: `${SHOTS}/hint-dark.png` });
  await page.evaluate(() => document.documentElement.removeAttribute('data-theme'));

  // a different order: the owl's cyanotype plate is dark, so a parchment ribbon is plenty
  await pickOrder(page, ['owl'], 'cyanotype');
  await expect(ribbonHint(page)).toBeHidden();
  await page.selectOption('#sc-variant', 'parchment');
  await idle(page);
  await expect(ribbonHint(page)).toBeVisible();
  await page.selectOption('#sc-variant', 'transparency');
  await idle(page);
  await expect(ribbonHint(page)).toBeVisible();
  await page.fill('#sc-ribbon-color-hex', '#1E4FA3');
  await expect(ribbonHint(page)).toBeHidden();
  expect(await page.evaluate(() => (window as any).__a5)).toBe(1);
});

test('the custom order reads its own ground', async ({ page }) => {
  await open(page);
  await pickOrder(page, ['custom']);
  await ribbon(page);
  await page.fill('#sc-ribbon-color-hex', '#F2E6C8');
  await expect(ribbonHint(page)).toBeVisible();
  await page.click('#sc-recipe > .rp-filter-card-header');
  await page.selectOption('#sc-ground', 'cyanotype');
  await idle(page);
  await expect(ribbonHint(page)).toBeHidden();
});

test('every ground option carries the measured luminance, and the hint is never on the certificate', async ({ page }) => {
  await open(page);
  const got = await page.$$eval('#sc-ground option', os => os.map(o => [(o as HTMLOptionElement).value, (o as HTMLOptionElement).dataset.groundLum]));
  expect(got.length).toBeGreaterThan(5);
  for (const [name, lum] of got) {
    expect(Number(lum), name as string).toBeCloseTo(LUM[name as string].luminance, 5);
  }
  const awards = await page.$$eval('#sc-award option[data-order]:not([data-order="custom"])', os => os.map(o => (o as HTMLOptionElement).dataset.groundLum));
  expect(awards.length).toBeGreaterThan(5);
  for (const a of awards) { expect(Number(a)).toBeGreaterThan(0); }

  await pickOrder(page, ['lion', 'rose', 'crown']);
  await ribbon(page);
  await page.fill('#sc-ribbon-color-hex', '#F2E6C8');
  await expect(ribbonHint(page)).toBeVisible();
  const cert = await page.locator('#sc-mount').innerHTML();
  expect(cert).not.toContain('Low contrast');
  expect(cert).not.toContain('hard to see');
});

// Mask draws its knot through a screen@0.6 glow AND a multiply@1 band. The band is the
// multiply pass, so dark heraldic colours draw on its parchment: match stays on (no
// MatchFallback) and the hint judges the multiply result (colour x paper), not screen.
test('mask (mixed blend): match keeps dark colours and does not flag a visible blue', async ({ page }) => {
  await open(page);
  await pickOrder(page, ['mask']);
  await openDesign(page);
  await page.setChecked('#sc-custom-border', true);
  await idle(page);
  await page.setChecked('#sc-match-style', true);
  await idle(page);
  const fallback = page.locator('#sc-match-fallback-hint');
  const c1 = page.locator('#sc-color1-contrast');

  await page.fill('#sc-color1-hex', '#1E4FA3');
  await idle(page);
  await expect(c1).toBeHidden();
  await expect(fallback).toBeHidden();

  await page.fill('#sc-color1-hex', '#7A1F1F');
  await idle(page);
  await expect(fallback).toBeHidden();
  await expect(c1).toBeHidden();

  await page.fill('#sc-color1-hex', '#000000');
  await idle(page);
  await expect(fallback).toBeHidden();
  await expect(c1).toBeHidden();
  // The preview really drew match (the server kept it on): one knot per mask pass.
  await expect(page.locator('#sc-mount .sc-knot')).toHaveCount(2);
});
