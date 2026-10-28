import { test, expect, type Page } from '@playwright/test';
import { hasAuthCredentials, login } from '../lib/auth';

// A4: match-style on a screen-blended pass cannot draw a near-black colour, so the server
// draws exact and the card says so. The composer is behind a login.
test.skip(!hasAuthCredentials(), 'needs ORK3_E2E_USERNAME and ORK3_E2E_PASSWORD (the composer is behind a login)');

const KINGDOM = Number(process.env.ORK3_SWEEP_KINGDOM ?? 1);
const HINT = "Very dark colours can't blend on this background — printed in exact colours.";
const SHOTS = 'tools/scroll-sweep/out/a4-hint';

async function idle(page: Page) {
  await expect(page.locator('#sc-stage')).toHaveAttribute('aria-busy', 'false');
}

async function owlCyanotypeCustomBorder(page: Page) {
  await login(page);
  await page.goto(`./index.php?Route=Scrollery/create/kingdom/${KINGDOM}`, { waitUntil: 'load' });
  await page.waitForSelector('#sc-mount .Award', { state: 'attached' });
  await page.evaluate(() => {
    const sel = document.getElementById('sc-award') as HTMLSelectElement;
    const i = Array.from(sel.options).findIndex(o => (o as HTMLOptionElement).dataset.order === 'owl');
    sel.selectedIndex = i;
    sel.dispatchEvent(new Event('change', { bubbles: true }));
  });
  await idle(page);
  await page.click('#sc-rank-pills .sc-rank-pill[data-rank="8"]');
  await idle(page);
  await page.selectOption('#sc-variant', 'cyanotype');
  await idle(page);
  await page.click('#sc-design > .rp-filter-card-header');
  await page.setChecked('#sc-custom-border', true);
  await idle(page);
  await page.setChecked('#sc-match-style', true);
  await idle(page);
}

test('a near-black colour on owl cyanotype shows the fallback hint; a blue one does not', async ({ page }) => {
  await owlCyanotypeCustomBorder(page);
  const hint = page.locator('#sc-match-fallback-hint');

  await page.fill('#sc-color1-hex', '#000000');
  await idle(page);
  await expect(hint).toBeVisible();
  await expect(hint).toHaveText(HINT);
  // exact mode: the one knot is drawn plain, not screened
  await expect(page.locator('#sc-mount .sc-knot')).toHaveCount(1);
  await page.locator('#sc-custom-border-colors').scrollIntoViewIfNeeded();
  await page.locator('#sc-custom-border-colors').screenshot({ path: `${SHOTS}/hint-light.png` });
  await page.evaluate(() => document.documentElement.setAttribute('data-theme', 'dark'));
  await page.locator('#sc-custom-border-colors').screenshot({ path: `${SHOTS}/hint-dark.png` });
  await page.evaluate(() => document.documentElement.removeAttribute('data-theme'));

  await page.fill('#sc-color1-hex', '#1E4FA3');
  await idle(page);
  await expect(hint).toBeHidden();

  // and it goes away with Match off
  await page.fill('#sc-color1-hex', '#000000');
  await idle(page);
  await expect(hint).toBeVisible();
  await page.setChecked('#sc-match-style', false);
  await expect(hint).toBeHidden();
});
