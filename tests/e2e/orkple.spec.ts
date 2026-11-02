/**
 * ORKPLE II easter egg: floppy launcher, CRT power-off, Apple II monitor, clean exit.
 * Reduced motion keeps the floppy still (clickable) and skips the animations.
 */
import { test, expect, Page } from '@playwright/test';

async function appReachable(baseURL: string): Promise<boolean> {
  try {
    const res = await fetch(baseURL, { method: 'HEAD' });
    return res.ok || res.status === 302 || res.status === 405;
  } catch {
    return false;
  }
}

test.use({ contextOptions: { reducedMotion: 'reduce' } });

test.beforeEach(async ({ baseURL }, testInfo) => {
  if (!baseURL || !(await appReachable(baseURL))) {
    testInfo.skip(true, 'ORK3 app not reachable — start docker compose php8 stack');
  }
});

const log = (page: Page) => page.locator('#orkple [role=log]');

async function boot(page: Page) {
  await page.locator('#orkple-floppy').click();
  await expect(page.locator('#orkple')).toBeVisible({ timeout: 10000 });
  await expect(log(page)).toContainText('ORKPLE II', { timeout: 10000 });
}

async function type(page: Page, text: string) {
  await page.keyboard.type(text);
  await page.keyboard.press('Enter');
}

test.beforeEach(async ({ page }) => {
  await page.goto('./index.php?Route=Search');
});

test('floppy is visible bottom-left', async ({ page }) => {
  const f = page.locator('#orkple-floppy');
  await expect(f).toBeVisible();
  const box = await f.boundingBox();
  expect(box!.x).toBeLessThan(60);
});

test('click boots the monitor with boot text', async ({ page }) => {
  await boot(page);
  await expect(page.locator('#orkple canvas')).toBeVisible();
  await expect(log(page)).toContainText('DOS VERSION 3.3');
  await expect(log(page)).toContainText('COPYRIGHT AMTGARD');
});

test('junk gives ?SYNTAX ERROR', async ({ page }) => {
  await boot(page);
  await type(page, 'hello');
  await expect(log(page)).toContainText('?SYNTAX ERROR');
});

test('ork reaches the menu; option 3 logged out gives ?NOT LOGGED IN', async ({ page }) => {
  await boot(page);
  await type(page, 'ork');
  await expect(log(page)).toContainText('KINGDOMS', { timeout: 10000 });
  await expect(log(page)).toContainText('ORK GAMES');
  await type(page, '3');
  await expect(log(page)).toContainText('?NOT LOGGED IN');
});

async function pageIsPristine(page: Page) {
  await expect(page.locator('#orkple')).toHaveCount(0);
  // The power-on class lives for the length of the return animation.
  await expect.poll(() => page.evaluate(() => document.documentElement.className)).not.toMatch(/orkple/);
  const s = await page.evaluate(() => ({
    html: document.documentElement.className,
    htmlStyle: document.documentElement.getAttribute('style'),
    bodyStyle: document.body.getAttribute('style'),
    inert: document.querySelectorAll('[inert]').length,
  }));
  expect(s.html).not.toMatch(/orkple/);
  expect(s.htmlStyle || '').not.toMatch(/ok-/);
  expect(s.bodyStyle || '').not.toMatch(/transform|ok-/);
  expect(s.inert).toBe(0);
}

test('Escape restores the page: no leftovers, same scrollY', async ({ page }) => {
  await page.evaluate(() => {
    const pad = document.createElement('div');
    pad.id = 'orkple-test-pad';
    pad.style.height = '3000px';
    document.body.appendChild(pad);
    window.scrollTo(0, 700);
  });
  const before = await page.evaluate(() => Math.round(window.scrollY));
  expect(before).toBeGreaterThan(0);
  await boot(page);
  await page.keyboard.press('Escape');
  await pageIsPristine(page);
  expect(await page.evaluate(() => Math.round(window.scrollY))).toBe(before);
  await expect(page.locator('#orkple-floppy')).toBeFocused();
});

test('POWER button and EXIT command also leave cleanly', async ({ page }) => {
  await boot(page);
  await page.locator('#orkple button', { hasText: /power/i }).click();
  await pageIsPristine(page);
  await boot(page);
  await type(page, 'exit');
  await pageIsPristine(page);
});

async function toMenu(page: Page) {
  await boot(page);
  await type(page, 'ork');
  await expect(log(page)).toContainText('ORK GAMES', { timeout: 10000 });
}

test('option 1 lists 8 kingdoms a page; 9 flips the page', async ({ page }) => {
  await toMenu(page);
  await type(page, '1');
  await expect(log(page)).toContainText('PAGE 1 OF', { timeout: 10000 });
  const text = (await log(page).textContent()) || '';
  for (let n = 1; n <= 8; n++) expect(text).toContain(`${n}. `);
  await expect(log(page)).toContainText('9. MORE');
  await expect(log(page)).toContainText('0. MAIN MENU');
  await type(page, '9');
  await expect(log(page)).toContainText('PAGE 2 OF');
});

test('picking a kingdom opens its profile; PARKS and EVENTS go and come back', async ({ page }) => {
  await toMenu(page);
  await type(page, '1');
  await expect(log(page)).toContainText('PAGE 1 OF', { timeout: 10000 });
  await type(page, '1');
  await expect(log(page)).toContainText('MONARCH', { timeout: 10000 });
  await expect(log(page)).toContainText('GMR');
  await expect(log(page)).toContainText('0. KINGDOM LIST');
  // A screen is done printing when the log ends on its last line; only then does it take input.
  await type(page, '1');
  await expect(log(page)).toHaveText(/PARKS +PAGE 1 OF[\s\S]*0\. BACK\s*$/, { timeout: 10000 });
  await type(page, '0');
  await expect(log(page)).toHaveText(/0\. KINGDOM LIST\s*$/, { timeout: 10000 });
  await type(page, '2');
  await expect(log(page)).toHaveText(/EVENTS [A-Z]{3} \d{4} +PAGE 1 OF[\s\S]*0\. BACK\s*$/, { timeout: 10000 });
  await type(page, '0');
  await expect(log(page)).toHaveText(/0\. KINGDOM LIST\s*$/, { timeout: 10000 });
  await type(page, '0');
  await expect(log(page)).toHaveText(/0\. MAIN MENU\s*$/, { timeout: 10000 });
  await expect(log(page)).toContainText('0. MAIN MENU');
  await expect(log(page)).toContainText('PAGE 1 OF');
});

test('a digit that is not on offer gives ?ILLEGAL QUANTITY ERROR', async ({ page }) => {
  await toMenu(page);
  await type(page, '1');
  await expect(log(page)).toContainText('PAGE 1 OF', { timeout: 10000 });
  await type(page, '1');
  await expect(log(page)).toContainText('MONARCH', { timeout: 10000 });
  await type(page, '7');
  await expect(log(page)).toContainText('?ILLEGAL QUANTITY ERROR');
  await type(page, 'zap');
  await expect(log(page)).toContainText('?SYNTAX ERROR');
});

test('a failed disk read gives ?I/O ERROR and a working prompt', async ({ page }) => {
  await page.route('**/Json/index.php**', (route) => route.abort());
  await toMenu(page);
  await type(page, '1');
  await expect(log(page)).toContainText('?I/O ERROR', { timeout: 10000 });
  await type(page, '4');
  await endsOn(page, /0\. MAIN MENU\s*$/);
  await page.unroute('**/Json/index.php**');
  await type(page, '0');
  await endsOn(page, /4\. ORK GAMES\s+5\. SEARCH THE ORK\s+6\. THE ORK TODAY\s*(E\.P\.[^\n]*)?\s*$/);
  await type(page, '1');
  await expect(log(page)).toContainText('PAGE 1 OF', { timeout: 10000 });
});

// ---- option 2: PLAYERS ----

// A screen is done printing when the log ends on its last line; only then does it take input.
const endsOn = (page: Page, re: RegExp) => expect(log(page)).toHaveText(re, { timeout: 15000 });

async function toSearch(page: Page) {
  await toMenu(page);
  await type(page, '2');
  await endsOn(page, /0\. MAIN MENU\s*$/);
}

test('option 2 opens the player search; a plain name returns rows', async ({ page }) => {
  await toSearch(page);
  await expect(log(page)).toContainText('PLAYER SEARCH');
  await expect(log(page)).toContainText('KCG:AR TOBIAS');
  await type(page, 'tobias');
  await endsOn(page, /0\. NEW SEARCH\s*$/);
  await expect(log(page)).toContainText('MATCHES FOR TOBIAS');
  await expect(log(page)).toContainText('1. ');
});

test('a KCG:AR shortcut, typed in capitals, finds Tobias of Heraldsbridge in that park only', async ({ page }) => {
  await toSearch(page);
  await type(page, 'kcg:ar tob');
  await endsOn(page, /0\. NEW SEARCH\s*$/);
  await expect(log(page)).toContainText('MATCHES FOR KCG:AR TOB');
  await expect(log(page)).toContainText('TOBIAS OF HERALDSBRIDGE');
  await expect(log(page)).not.toContainText('NO SUCH PLAYER');
});

test('a bare KCG:AR or a one letter name gets a hint, not a search', async ({ page }) => {
  let searches = 0;
  await page.route(/SearchAjax\/universal/, (route) => { searches++; return route.continue(); });
  await toSearch(page);
  await type(page, 'kcg:ar');
  await expect(log(page)).toContainText('?MISSING NAME');
  await expect(log(page)).toContainText('SHORTCUT NEEDS A NAME');
  await type(page, 'k');
  await expect(log(page)).toContainText('TYPE AT LEAST 2 LETTERS');
  expect(searches).toBe(0);
});

test('no match says NO SUCH PLAYER and leaves the prompt working', async ({ page }) => {
  await toSearch(page);
  await type(page, 'zzzqqqxx');
  await expect(log(page)).toContainText('NO SUCH PLAYER.');
  await page.waitForTimeout(500);
  await type(page, '');
  await type(page, '0');
  await endsOn(page, /4\. ORK GAMES\s+5\. SEARCH THE ORK\s+6\. THE ORK TODAY\s*(E\.P\.[^\n]*)?\s*$/);
});

test('picking a player shows the profile; classes, awards, attendance and back', async ({ page }) => {
  await toSearch(page);
  await type(page, 'kcg:ar tobias of');
  await endsOn(page, /0\. NEW SEARCH\s*$/);
  await type(page, '1');
  await endsOn(page, /0\. BACK\s*$/);
  const profile = (await log(page).textContent()) || '';
  expect(profile).toContain('TOBIAS OF HERALDSBRIDGE');
  expect(profile).toContain('STATUS');
  expect(profile).toContain('PLAYER SINCE');
  expect(profile).not.toMatch(/USERNAME|PASSWORD|@/);
  for (const [key, title] of [['1', 'CLASSES'], ['2', 'LADDERS'], ['3', 'ATTENDANCE']]) {
    await type(page, key);
    await endsOn(page, new RegExp(title + ' +PAGE 1 OF[\\s\\S]*0\\. BACK\\s*$'));
    await type(page, '0');
    await endsOn(page, /3\. ATTENDANCE\s+4\. TITLES\s+5\. ABOUT\s+6\. COMPANIES\s+7\. MILESTONES\s+0\. BACK\s*$/);
  }
  await type(page, '0');
  await endsOn(page, /0\. NEW SEARCH\s*$/);
});

test('a failed player search gives ?I/O ERROR and the NAME prompt still works', async ({ page }) => {
  await page.route(/SearchAjax\/universal/, (route) => route.abort());
  await toSearch(page);
  await type(page, 'tobias');
  await expect(log(page)).toContainText('?I/O ERROR', { timeout: 10000 });
  await page.unroute(/SearchAjax\/universal/);
  await type(page, 'kcg:ar tob');
  await endsOn(page, /0\. NEW SEARCH\s*$/);
});

test('option 3 logged out says to log in, with a working prompt', async ({ page }) => {
  await toMenu(page);
  await type(page, '3');
  await expect(log(page)).toContainText('?NOT LOGGED IN');
  await expect(log(page)).toContainText('LOG IN TO THE ORK FIRST.');
});

test('ORK GAMES lists three games; a missing game file gives ?FILE NOT FOUND; a game runs and quits', async ({ page }) => {
  await page.route(/games\/zork\.js/, (route) => route.fulfill({ status: 404, body: 'no' }));
  await page.route(/games\/trail\.js/, (route) => route.fulfill({
    contentType: 'application/javascript',
    body: "window.OrkpleGames=window.OrkpleGames||{};window.OrkpleGames.trail={start:function(h){h.print('GAME ON');h.keys(function(k){if(k==='Q'){h.quit();}else{h.print('KEY '+k);}});}};",
  }));
  await toMenu(page);
  await type(page, '4');
  await endsOn(page, /0\. MAIN MENU\s*$/);
  await expect(log(page)).toContainText('2. zORK');
  await type(page, '2');
  await expect(log(page)).toContainText('?FILE NOT FOUND');
  await page.waitForTimeout(300);
  await type(page, '1');
  await expect(log(page)).toContainText('GAME ON', { timeout: 10000 });
  await page.keyboard.press('ArrowUp');
  await expect(log(page)).toContainText('KEY UP');
  await page.keyboard.press('q');
  await endsOn(page, /0\. MAIN MENU\s*$/);
});

test.describe('on a touch-only device', () => {
  test.use({ hasTouch: true, isMobile: true, viewport: { width: 390, height: 844 } });

  test('stops at the BIOS error, takes no input, and POWER brings the page back', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.goto('./index.php?Route=Search');
    await page.locator('#orkple-floppy').tap();
    const screen = page.locator('#orkple [role=log]');
    await expect(screen).toContainText('SYSTEM BIOS ERROR', { timeout: 10000 });
    await expect(screen).toContainText('NO MOUSE DETECTED');
    await expect(screen).toContainText('NO KEYBOARD DETECTED');
    await expect(screen).toContainText('BOOT SEQUENCE INTERRUPTED');
    await expect(screen).toContainText('WORKS BEST ON A DESKTOP');
    await expect(screen).not.toContainText('DOS VERSION');
    await expect(page.locator('#orkple input')).toBeDisabled();
    await page.locator('#orkple button[aria-label="Power off"]').tap();
    await expect(page.locator('#orkple')).toHaveCount(0, { timeout: 10000 });
    expect(errors).toEqual([]);
  });
});
