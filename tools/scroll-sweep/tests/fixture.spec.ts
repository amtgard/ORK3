import { test, expect } from '@playwright/test';
import { loadCorpus, renderFixtures, serveFixtures, openFixture } from '../lib/fixture';
import { BLOCK_SEL } from '../lib/isolate';

test('fixture renderer draws the requested order and hand without a login', async ({ page }) => {
  const specs = loadCorpus('review-2026-10-25').filter(c => c.id === 'c22' || c.id === 'x2');
  const dir = renderFixtures(specs, 'fixture-smoke');
  await serveFixtures(page, dir);
  await openFixture(page, specs.find(s => s.id === 'c22')!);
  await expect(page.locator('.award-template')).toHaveAttribute('data-order', 'warrior');
  expect(await page.evaluate(() => getComputedStyle(document.querySelector('.Award')!).fontFamily)).toContain('BlackChancery');
  await openFixture(page, specs.find(s => s.id === 'x2')!);
  await expect(page.locator('.award-template')).toHaveAttribute('data-order', 'rose');
  expect(await page.evaluate(() => getComputedStyle(document.querySelector('.Award')!).fontFamily)).toContain('Uncial Antiqua');
});

test('every sweep text block still matches on a rendered certificate', async ({ page }) => {
  const specs = loadCorpus('review-2026-10-25').filter(c => c.id === 'c22');
  await serveFixtures(page, renderFixtures(specs, 'fixture-blocks'));
  await openFixture(page, specs[0]);
  for (const sel of BLOCK_SEL.split(',')) {
    expect(await page.locator(sel).count(), sel).toBeGreaterThanOrEqual(1);
  }
});

test('the fixture renderer is not reachable over the web', async ({ request }) => {
  const r = await request.get('http://127.0.0.1:19080/bin/scroll-render-fixtures.php');
  expect(r.status()).toBe(404);
});
