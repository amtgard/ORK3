/**
 * Survey module, end to end, by role (local docker mirror only).
 *
 * Three surveys are created, built, opened and answered through the UI:
 *   Amtgard-wide  (ORK admin builds; a kingdom officer adopts the credit)
 *   Kingdom       (Nine Blades officer builds and switches credit on)
 *   Park          (Felfrost officer builds and switches credit on)
 * and six accounts play their parts:
 *   admin              ORK admin
 *   admiral_anne_cash  kingdom 31 CREATE (Nine Blades), home Linnagond
 *   loulee             park 277 CREATE (Felfrost), home Felfrost
 *   Emily_BH           player, Bellhollow / Nine Blades
 *   Dex_FF             player, Felfrost / Nine Blades
 *   Carter_EtH         player, Ethereal Hollow / Westmarch
 * All six have the local password "password" (set with dev-set-test-logins
 * style resets; never on prod).
 *
 * Run:  ORK3_E2E_SHOTS=/path/to/dir npx playwright test tests/e2e/survey-roles.spec.ts
 * The surveys it makes are titled "E2E ... <stamp>"; ids land in
 * $ORK3_E2E_SHOTS/survey-roles-state.json for cleanup.
 */
import { test, expect, Browser, BrowserContext, Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

test.describe.configure({ mode: 'serial' });
test.setTimeout(120_000);

const SHOTS = process.env.ORK3_E2E_SHOTS || path.join(__dirname, '..', '..', 'tmp-shots');
fs.mkdirSync(SHOTS, { recursive: true });

const STAMP = new Date().toISOString().slice(5, 19).replace(/[-T:]/g, '');
const TITLE = {
  ork: `E2E Amtgard ${STAMP}`,
  kingdom: `E2E Kingdom ${STAMP}`,
  park: `E2E Park ${STAMP}`,
};
const PASS = 'password';
const U = {
  admin: { u: 'admin', id: 1 },
  anne: { u: 'admiral_anne_cash', id: 17323 },
  loulee: { u: 'loulee', id: 161882 },
  emily: { u: 'Emily_BH', id: 187303 },
  dex: { u: 'Dex_FF', id: 171037 },
  carter: { u: 'Carter_EtH', id: 181702 },
};
const KINGDOM_LIST = './index.php?Route=Survey/index/Kingdom/31';
const PARK_LIST = './index.php?Route=Survey/index/Park/277';
const ALL_LIST = './index.php?Route=Survey/index';

const CREDIT_NOTE = 'This survey gives a bonus credit';
const CREDIT_MAYBE = 'This survey may later give a bonus credit';

type Scope = 'ork' | 'kingdom' | 'park';
const state: Record<Scope, { id: number; slug: string }> = {
  ork: { id: 0, slug: '' },
  kingdom: { id: 0, slug: '' },
  park: { id: 0, slug: '' },
};
const notes: string[] = [];
function note(s: string) { notes.push(s); console.log('NOTE: ' + s); }

async function shot(page: Page, name: string) {
  await page.screenshot({ path: path.join(SHOTS, name + '.png'), fullPage: false });
}

async function loginAs(browser: Browser, who: { u: string; id: number }, viewport = { width: 1280, height: 900 }) {
  const ctx = await browser.newContext({ viewport });
  const page = await ctx.newPage();
  await page.goto('./index.php?Route=Login');
  await page.fill('input[name="username"]', who.u);
  await page.fill('input[name="password"]', PASS);
  await page.click('button[type="submit"], input[type="submit"]');
  await page.waitForURL(/Route=Player\/profile\/\d+/, { timeout: 20_000 });
  await dismissWhatsNew(page);
  return { ctx, page };
}

/** The What's New modal sits over every page until the account dismisses it. */
async function dismissWhatsNew(page: Page) {
  const overlay = page.locator('#wn-overlay.wn-open');
  if (await overlay.count()) {
    const done = page.waitForResponse((r) => r.url().includes('WnAjax/dismiss'), { timeout: 10_000 }).catch(() => null);
    await page.evaluate(() => { const w = window as any; if (typeof w.wnDismiss === 'function') { w.wnDismiss(); } });
    await done;
    await expect(overlay).toHaveCount(0);
  }
}

async function createSurvey(page: Page, listRoute: string, scopeValue: string, title: string): Promise<number> {
  await page.goto(listRoute);
  await page.click('#sv-new-btn');
  await expect(page.locator('#sv-new-overlay')).toBeVisible();
  await page.fill('#sv-new-title', title);
  await page.selectOption('#sv-new-scope', scopeValue);
  await page.click('#sv-new-ok');
  await page.waitForURL(/Route=Survey\/build\/(\d+)/, { timeout: 20_000 });
  const m = page.url().match(/Survey\/build\/(\d+)/);
  return parseInt(m![1], 10);
}

async function waitSaved(page: Page) {
  await page.waitForTimeout(700);
  await expect(page.locator('#svb-savestate')).toHaveText(/Saved/, { timeout: 15_000 });
}

async function buildAndOpen(page: Page, opts: { banner?: boolean; shotPrefix: string }) {
  await expect(page.locator('#svb-canvas')).toBeVisible();
  const add = page.locator('.svb-addbtn[data-act="add-element"]').first();
  await expect(add).toBeVisible({ timeout: 15_000 });
  await add.click();
  const prompt = page.locator('.svb-prompt').first();
  await expect(prompt).toBeVisible();
  await prompt.fill('Which best describes your last park day? (E2E)');
  await prompt.press('Tab');
  await waitSaved(page);
  await shot(page, opts.shotPrefix + '-builder');

  if (opts.banner) {
    const seg = page.locator('.svb-viewseg-btn[data-view="settings"]');
    if (await seg.isVisible()) { await seg.click(); }
    // Settings are collapsed sections; the banner switch lives under Promotion.
    await page.locator('#svb-settings').getByText(/^\s*Promotion\s*$/i).first().click();
    const cb = page.locator('#svb-settings [data-sv-field="ShowBanner"]');
    await expect(cb).toBeAttached({ timeout: 10_000 });
    await cb.check({ force: true });
    await expect(cb).toBeChecked();
    await waitSaved(page);
    await shot(page, opts.shotPrefix + '-settings-banner');
    const segQ = page.locator('.svb-viewseg-btn[data-view="questions"]');
    if (await segQ.isVisible()) { await segQ.click(); }
  }

  await page.click('#svb-openclose');
  await expect(page.locator('#svb-confirm')).toBeVisible();
  await page.click('#svb-confirm-yes');
  await expect(page.locator('#svb-statuspill')).toContainText(/open/i, { timeout: 15_000 });
  await expect(page.locator('#svb-openclose')).toContainText(/Close survey/);
  await shot(page, opts.shotPrefix + '-opened');
}

async function slugFromList(page: Page, listRoute: string, sid: number): Promise<string> {
  await page.goto(listRoute);
  const row = page.locator(`tr:has(a[href$="Survey/build/${sid}"])`).first();
  await expect(row).toBeVisible();
  const link = await row.locator('.sv-copylink-btn').getAttribute('data-link');
  const m = (link || '').match(/Survey\/s\/([a-z0-9]+)/i);
  expect(m, 'share link on list row').not.toBeNull();
  return m![1];
}

async function enableCredit(page: Page, listRoute: string, sid: number, shotName: string) {
  await page.goto(listRoute);
  const btn = page.locator(`[data-sv-credit="${sid}"]`);
  await expect(btn).toBeVisible();
  await btn.click();
  await expect(page.locator('#sv-credit-overlay')).toBeVisible();
  await expect(page.locator('#sv-credit-enable')).toBeVisible({ timeout: 10_000 });
  await shot(page, shotName + '-modal');
  const bodyBefore = (await page.locator('#sv-credit-body').innerText()).replace(/\s+/g, ' ').trim();
  await page.click('#sv-credit-enable');
  await expect(page.locator('#sv-credit-disable')).toBeVisible({ timeout: 15_000 });
  const bodyAfter = (await page.locator('#sv-credit-body').innerText()).replace(/\s+/g, ' ').trim();
  await shot(page, shotName + '-modal-on');
  await page.click('#sv-credit-close');
  await page.reload();
  await expect(page.locator(`[data-sv-credit="${sid}"]`)).toHaveClass(/sv-row-btn-on/);
  return { bodyBefore, bodyAfter };
}

async function takeSurvey(page: Page, slug: string, shotPrefix: string) {
  await page.goto(`./index.php?Route=Survey/s/${slug}`);
  const choice = page.locator('.sv-choice-input').first();
  const notice = page.locator('.sv-notice-warn, .sv-notice-error').filter({ visible: true }).first();
  await Promise.race([
    choice.waitFor({ state: 'visible', timeout: 15_000 }),
    notice.waitFor({ state: 'visible', timeout: 15_000 }),
  ]);
  if (!(await choice.isVisible())) {
    // welcome screen or a refusal
    const start = page.locator('.sv-btn-primary');
    if (await start.isVisible()) {
      await start.click();
      await expect(choice).toBeVisible({ timeout: 10_000 });
    }
  }
  await expect(choice).toBeVisible();
  await choice.check();
  await shot(page, shotPrefix + '-question');
  await page.locator('.sv-btn-primary').click();
  const consent = page.locator('.sv-consent-input[value="full"]');
  await expect(consent).toBeVisible({ timeout: 10_000 });
  const consentText = (await page.locator('#sv-stage').innerText()).replace(/\s+/g, ' ');
  await shot(page, shotPrefix + '-consent');
  await consent.check();
  await page.locator('.sv-btn-primary').click();
  await expect(page.locator('.sv-thanks')).toBeVisible({ timeout: 15_000 });
  const creditNote = page.locator('.sv-credit-note');
  const creditText = (await creditNote.count()) ? (await creditNote.innerText()).trim() : '';
  await shot(page, shotPrefix + '-thanks');
  return { consentText, creditText };
}

async function refusalText(page: Page, slug: string, shotName: string) {
  await page.goto(`./index.php?Route=Survey/s/${slug}`);
  const notice = page.locator('.sv-notice-warn, .sv-notice-error').filter({ visible: true }).first();
  await expect(notice).toBeVisible({ timeout: 15_000 });
  await expect(page.locator('.sv-choice-input')).toHaveCount(0);
  await shot(page, shotName);
  return (await notice.innerText()).replace(/\s+/g, ' ').trim();
}

async function bonusRows(page: Page, uid: number, title: string) {
  await page.goto(`./index.php?Route=Player/profile/${uid}`);
  const section = page.locator('#pn-bonus-section');
  if (!(await section.count())) { return 0; }
  return section.locator('tbody tr', { hasText: title }).count();
}

async function surveyCardRow(page: Page, uid: number, title: string) {
  await page.goto(`./index.php?Route=Player/profile/${uid}`);
  return page.locator('.pna-survey-row', { hasText: title });
}

test.afterAll(async () => {
  fs.writeFileSync(path.join(SHOTS, 'survey-roles-state.json'), JSON.stringify({ titles: TITLE, state, notes }, null, 2));
});

// ---------------------------------------------------------------- Amtgard-wide

test('A1 admin creates, builds and opens the Amtgard-wide survey with a site banner', async ({ browser }) => {
  const { ctx, page } = await loginAs(browser, U.admin);
  await page.goto(ALL_LIST);
  await shot(page, 'A1-admin-list');
  state.ork.id = await createSurvey(page, ALL_LIST, 'ork:0', TITLE.ork);
  await buildAndOpen(page, { banner: true, shotPrefix: 'A1' });
  state.ork.slug = await slugFromList(page, ALL_LIST, state.ork.id);
  // No org page => no Credits action for the admin on the all-of-Amtgard list.
  const creditBtn = page.locator(`[data-sv-credit="${state.ork.id}"]`);
  note(`A1 admin all-of-Amtgard list shows a Credits button on the ork survey: ${(await creditBtn.count()) > 0}`);
  await ctx.close();
});

test('A2 Nine Blades kingdom officer adopts the Amtgard-wide credit', async ({ browser }) => {
  const { ctx, page } = await loginAs(browser, U.anne);
  await page.goto(KINGDOM_LIST);
  await expect(page.locator(`tr:has(a[href$="Survey/build/${state.ork.id}"]), tr:has-text("${TITLE.ork}")`).first()).toBeVisible();
  const r = await enableCredit(page, KINGDOM_LIST, state.ork.id, 'A2-credit');
  note(`A2 credit modal before: ${r.bodyBefore.slice(0, 300)}`);
  note(`A2 credit modal after:  ${r.bodyAfter.slice(0, 300)}`);
  await shot(page, 'A2-kingdom-list');
  await ctx.close();
});

test('A3 Nine Blades player sees the banner, takes it, gets the bonus credit', async ({ browser }) => {
  const { ctx, page } = await loginAs(browser, U.emily);
  await expect(page.locator('#ork-survey-banner')).toContainText(TITLE.ork);
  await shot(page, 'A3-emily-banner');
  const card = await surveyCardRow(page, U.emily.id, TITLE.ork);
  await expect(card).toBeVisible();
  note(`A3 profile card text: ${(await card.innerText()).replace(/\s+/g, ' ').trim()}`);
  const r = await takeSurvey(page, state.ork.slug, 'A3-emily');
  expect(r.consentText).toContain(CREDIT_NOTE);
  note(`A3 thanks credit note: "${r.creditText}"`);
  expect(r.creditText).toMatch(/bonus credit/);
  expect(await bonusRows(page, U.emily.id, TITLE.ork)).toBe(1);
  await page.click('[data-tab="classes"], li[data-tab*="class"]').catch(() => {});
  await page.locator('#pn-bonus-section').scrollIntoViewIfNeeded().catch(() => {});
  await shot(page, 'A3-emily-profile-bonus');
  // Second visit: already completed.
  const again = await refusalText(page, state.ork.slug, 'A3-emily-again');
  note(`A3 retake notice: ${again}`);
  expect(again).toMatch(/already completed/i);
  await ctx.close();
});

test('A4 player from a kingdom that has not adopted sees the banner, takes it, gets no credit', async ({ browser }) => {
  const { ctx, page } = await loginAs(browser, U.carter);
  await expect(page.locator('#ork-survey-banner')).toContainText(TITLE.ork);
  const r = await takeSurvey(page, state.ork.slug, 'A4-carter');
  note(`A4 consent (other kingdom) mentions: ${r.consentText.includes(CREDIT_NOTE) ? 'GIVES' : r.consentText.includes(CREDIT_MAYBE) ? 'MAY LATER' : 'nothing about credit'}`);
  expect(r.consentText).toContain(CREDIT_MAYBE);
  note(`A4 thanks credit note: "${r.creditText}"`);
  expect(r.creditText).toBe('');
  expect(await bonusRows(page, U.carter.id, TITLE.ork)).toBe(0);
  await ctx.close();
});

test('A5 admin results show both responses', async ({ browser }) => {
  const { ctx, page } = await loginAs(browser, U.admin);
  await page.goto(`./index.php?Route=Survey/results/${state.ork.id}`);
  await expect(page.locator('#svr-stat-responses')).toHaveText('2', { timeout: 20_000 });
  await shot(page, 'A5-admin-results');
  await ctx.close();
});

// ---------------------------------------------------------------- Kingdom

test('B1 kingdom officer creates, opens and switches credit on for a kingdom survey', async ({ browser }) => {
  const { ctx, page } = await loginAs(browser, U.anne);
  state.kingdom.id = await createSurvey(page, KINGDOM_LIST, 'kingdom:31', TITLE.kingdom);
  await buildAndOpen(page, { shotPrefix: 'B1' });
  state.kingdom.slug = await slugFromList(page, KINGDOM_LIST, state.kingdom.id);
  const r = await enableCredit(page, KINGDOM_LIST, state.kingdom.id, 'B1-credit');
  note(`B1 credit modal before: ${r.bodyBefore.slice(0, 300)}`);
  await ctx.close();
});

test('B2 park officer sees the kingdom survey without Build; a plain player has no survey admin', async ({ browser }) => {
  const { ctx, page } = await loginAs(browser, U.loulee);
  await page.goto(PARK_LIST);
  const row = page.locator('tr', { hasText: TITLE.kingdom }).first();
  await expect(row).toBeVisible();
  await expect(row.locator(`a[href$="Survey/build/${state.kingdom.id}"]`)).toHaveCount(0);
  note(`B2 park officer's row actions for the kingdom survey: ${(await row.locator('.sv-row-btn-label').allInnerTexts()).join(', ')}`);
  await shot(page, 'B2-loulee-park-list');
  await page.goto(KINGDOM_LIST);
  await expect(page.locator('body')).toContainText(/do not have permission/i);
  await shot(page, 'B2-loulee-kingdom-list-denied');
  await ctx.close();

  const e = await loginAs(browser, U.emily);
  await e.page.goto(ALL_LIST);
  await expect(e.page.locator('body')).toContainText(/do not have permission to manage any surveys/i);
  await shot(e.page, 'B2-emily-survey-admin-denied');
  await e.ctx.close();
});

test('B3 Felfrost player takes the kingdom survey and gets credit; other-kingdom player is refused', async ({ browser }) => {
  const d = await loginAs(browser, U.dex);
  await expect(await surveyCardRow(d.page, U.dex.id, TITLE.kingdom)).toBeVisible();
  note(`B3 banner for kingdom survey (show_banner off): ${await d.page.locator('#ork-survey-banner').count()}`);
  const r = await takeSurvey(d.page, state.kingdom.slug, 'B3-dex');
  expect(r.consentText).toContain(CREDIT_NOTE);
  expect(r.creditText).toMatch(/bonus credit/);
  expect(await bonusRows(d.page, U.dex.id, TITLE.kingdom)).toBe(1);
  await d.ctx.close();

  const c = await loginAs(browser, U.carter);
  await expect(await surveyCardRow(c.page, U.carter.id, TITLE.kingdom)).toHaveCount(0);
  const txt = await refusalText(c.page, state.kingdom.slug, 'B3-carter-refused');
  note(`B3 other-kingdom refusal: ${txt}`);
  expect(txt).toContain('for players of The Kingdom of the Nine Blades');
  await expect(c.page.locator('#sv-title')).toContainText(TITLE.kingdom);
  await c.ctx.close();
});

// ---------------------------------------------------------------- Park

test('C1 park officer creates, opens and switches credit on for a park survey', async ({ browser }) => {
  const { ctx, page } = await loginAs(browser, U.loulee);
  state.park.id = await createSurvey(page, PARK_LIST, 'park:277', TITLE.park);
  await buildAndOpen(page, { shotPrefix: 'C1' });
  state.park.slug = await slugFromList(page, PARK_LIST, state.park.id);
  const r = await enableCredit(page, PARK_LIST, state.park.id, 'C1-credit');
  note(`C1 credit modal before: ${r.bodyBefore.slice(0, 300)}`);
  await ctx.close();
});

test('C2 Felfrost member takes the park survey and gets credit; same-kingdom other-park player is refused', async ({ browser }) => {
  const d = await loginAs(browser, U.dex);
  await expect(await surveyCardRow(d.page, U.dex.id, TITLE.park)).toBeVisible();
  const r = await takeSurvey(d.page, state.park.slug, 'C2-dex');
  expect(r.consentText).toContain(CREDIT_NOTE);
  expect(r.creditText).toMatch(/bonus credit/);
  expect(await bonusRows(d.page, U.dex.id, TITLE.park)).toBe(1);
  await shot(d.page, 'C2-dex-profile');
  await d.ctx.close();

  const e = await loginAs(browser, U.emily);
  await expect(await surveyCardRow(e.page, U.emily.id, TITLE.park)).toHaveCount(0);
  const txt = await refusalText(e.page, state.park.slug, 'C2-emily-refused');
  note(`C2 same-kingdom other-park refusal: ${txt}`);
  expect(txt).toContain('for members of Felfrost');
  await expect(e.page.locator('#sv-title')).toContainText(TITLE.park);
  await e.ctx.close();
});

test('C3 kingdom officer sees the park survey in the kingdom list; owners see counts', async ({ browser }) => {
  const a = await loginAs(browser, U.anne);
  await a.page.goto(KINGDOM_LIST);
  const row = a.page.locator('tr', { hasText: TITLE.park }).first();
  await expect(row).toBeVisible();
  note(`C3 kingdom officer's row actions for the park survey: ${(await row.locator('.sv-row-btn-label').allInnerTexts()).join(', ')}`);
  await shot(a.page, 'C3-anne-kingdom-list');
  await a.page.goto(`./index.php?Route=Survey/results/${state.kingdom.id}`);
  await expect(a.page.locator('#svr-stat-responses')).toHaveText('1', { timeout: 20_000 });
  await a.ctx.close();

  const l = await loginAs(browser, U.loulee);
  await l.page.goto(`./index.php?Route=Survey/results/${state.park.id}`);
  await expect(l.page.locator('#svr-stat-responses')).toHaveText('1', { timeout: 20_000 });
  await shot(l.page, 'C3-loulee-park-results');
  await l.ctx.close();
});

test('D phone viewport: banner and take page', async ({ browser }) => {
  const { ctx, page } = await loginAs(browser, U.dex, { width: 390, height: 844 });
  await shot(page, 'D-phone-profile-banner');
  await page.goto(`./index.php?Route=Survey/s/${state.ork.slug}`);
  await expect(page.locator('.sv-choice-input').first()).toBeVisible({ timeout: 15_000 });
  await shot(page, 'D-phone-take');
  await ctx.close();
});
