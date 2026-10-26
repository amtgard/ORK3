import { test, expect } from '@playwright/test';
import { login } from '../lib/auth';
import { loadCatalogue } from '../lib/catalogue';
import { openPrint, openPreview, printUrl, fixtureDriver } from '../lib/drive';
import { loadAxes } from '../lib/axes';
import { enumerate } from '../lib/cases';

test.use({ viewport: { width: 2600, height: 3400 }, deviceScaleFactor: 1 });

const KINGDOM = Number(process.env.ORK3_SWEEP_KINGDOM ?? 1);
const caseZero = () => {
  const c = enumerate(loadAxes(), 'A')
    .find(x => x.order === 'battle' && x.variant === 'arcane' && x.rank === 4 && x.text === 'caseZero')!;
  return { ...c, hand: 'cinzel' };
};

test.beforeEach(async ({ page }) => { await login(page); });

test('every order partial is either reachable or declared unreachable', async ({ page }) => {
  /* There is no composer catalogue to find hunter missing from: the fixture driver reaches
     it by name and declares nothing unreachable (see FIXTURE_ORDERS in lib/drive.ts). */
  test.skip(fixtureDriver(), 'compose driver only');
  const { byOrder, unreachable } = await loadCatalogue(page, KINGDOM);
  const orders = new Set(loadAxes().orderVariants.map(o => o.order));
  for (const order of orders) {
    expect(order in byOrder || unreachable.includes(order),
      `${order} is neither reachable nor declared unreachable`).toBe(true);
  }
  /* The composer carries all sixteen real orders plus custom; hunter's award_id 6969
     is not an ORK award id and no ork_kingdomaward row resolves to it. */
  expect(unreachable).toEqual(['hunter']);
});

test('the catalogue carries the rank floor, so impossible states are not swept', async ({ page }) => {
  const { byOrder } = await loadCatalogue(page, KINGDOM);
  // warrior has no rank 0 — data-min="1" on its option in Scrollery_create.tpl.
  expect(byOrder['warrior'].minRank).toBe(1);
  expect(byOrder['crown'].minRank).toBe(0);
});

test('a certificate renders and the typeset engine has run', async ({ page }) => {
  const { byOrder } = await loadCatalogue(page, KINGDOM);
  await openPrint(page, caseZero(), byOrder['battle'], KINGDOM);
  const award = page.locator('.Award').first();
  await expect(award).toBeVisible();
  /* data-sc-ms and data-sc-budget are written by ScrolleryTypeset.layoutTop() at the end
     of every top-half solve (the milliseconds it took, and whether the stack fit the
     vertical budget). Their presence is the proof the engine ran rather than the page
     having stopped at the server's first guess; and no error attribute means it ran to
     the end. */
  await expect(award).toHaveAttribute('data-sc-ms', /^\d/);
  await expect(award).toHaveAttribute('data-sc-budget', /^(ok|floor)$/);
  await expect(award).not.toHaveAttribute('data-sc-error', /./);
});

test('the print URL carries the hand, and the page honours it', async ({ page }) => {
  const { byOrder } = await loadCatalogue(page, KINGDOM);
  const c = caseZero();
  expect(printUrl(c, byOrder['battle'], KINGDOM)).toContain('face=cinzel');
  await openPrint(page, c, byOrder['battle'], KINGDOM);
  await expect(page.locator('body')).toHaveAttribute('data-scroll-face', 'cinzel');
});

test('the composer preview renders the same award on the other surface', async ({ page }) => {
  test.skip(fixtureDriver(), 'the preview is the composer page, which needs a login');
  const { byOrder } = await loadCatalogue(page, KINGDOM);
  await openPreview(page, caseZero(), byOrder['battle'], KINGDOM);
  await expect(page.locator('#sc-mount .Award').first()).toBeVisible();
  await expect(page.locator('#sc-stage')).toHaveAttribute('data-scroll-face', 'cinzel');
});
