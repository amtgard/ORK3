/**
 * ORKPLE II easter egg: floppy launcher, CRT power-off, Apple II monitor, clean exit.
 * Reduced motion keeps the floppy still (clickable) and skips the animations; the 'full motion' block runs
 * the exits again through the full-length CRT animations. The Release Notes / What's New entry points
 * are in orkple-entry.spec.ts.
 */
import { test, expect, Page } from '@playwright/test';
import { orkpleSetup, log } from './orkple-helpers';

test.use({ contextOptions: { reducedMotion: 'reduce' } });

orkpleSetup();

async function boot(page: Page, force = false) {
  // The floppy bobs under full motion, so a pointer click there skips the stability wait.
  await page.locator('#orkple-floppy').click({ force });
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
  await expect(log(page)).toContainText('ORKDOS 3.3');
  await expect(log(page)).toContainText('COPYRIGHT AMTGARD');
});

test('the live log adds one line per entry and never rewrites the lines it already holds', async ({ page }) => {
  await boot(page);
  await expect(log(page)).toContainText('TRY TYPING ORK', { timeout: 10000 });
  // Tag the current last line; it must survive (same node) while new lines are appended after it.
  await page.evaluate(() => { const l = document.querySelector('#orkple [role=log]')!; (l.lastElementChild as HTMLElement).dataset.mark = '1'; });
  await type(page, 'hello');
  await expect(log(page)).toContainText('?SYNTAX ERROR');
  const r = await page.evaluate(() => {
    const l = document.querySelector('#orkple [role=log]')!;
    const kids = Array.from(l.children);
    const mark = l.querySelector('[data-mark]');
    return { divs: kids.every((k) => k.tagName === 'DIV'), marked: !!mark, after: mark ? kids.length - 1 - kids.indexOf(mark) : -1, rel: l.getAttribute('aria-relevant') };
  });
  expect(r).toMatchObject({ divs: true, marked: true, rel: 'additions' });
  expect(r.after).toBeGreaterThan(0);
  // Past LIVE_MAX the oldest lines go, so the log stays bounded.
  for (let i = 0; i < 20; i++) {
    await type(page, 'zz' + i);
    // Keys typed while the terminal prints are dropped: wait for each reply.
    await expect.poll(() => log(page).innerText()).toMatch(new RegExp('ZZ' + i + '\\s*\\?SYNTAX ERROR\\s*$'));
  }
  expect(await log(page).locator(':scope > div').count()).toBeLessThanOrEqual(30);
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
// innerText, not textContent: the live log is one <div> per line, so only innerText keeps the line breaks.
const endsOn = (page: Page, re: RegExp) => expect.poll(() => log(page).innerText(), { timeout: 15000 }).toMatch(re);

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

test('ORK GAMES lists all eight games; a missing game file gives ?FILE NOT FOUND; a game runs and quits', async ({ page }) => {
  await page.route(/games\/zork\.js/, (route) => route.fulfill({ status: 404, body: 'no' }));
  await page.route(/games\/trail\.js/, (route) => route.fulfill({
    contentType: 'application/javascript',
    body: "window.OrkpleGames=window.OrkpleGames||{};window.OrkpleGames.trail={start:function(h){h.print('GAME ON');h.keys(function(k){if(k==='Q'){h.quit();}else{h.print('KEY '+k);}});}};",
  }));
  await toMenu(page);
  await type(page, '4');
  await endsOn(page, /0\. MAIN MENU\s*$/);
  for (const g of ['1. ORKEGON TRAIL', '2. DUNGEORK', '3. NUMBER MUNCHORKS', '4. MORKMAZE', '5. ASTERORKS', '6. GEM CASTLORKS', '7. FROGGORK', '8. INVADORKS']) {
    await expect(log(page)).toContainText(g);
  }
  await expect(log(page)).toContainText('INDEPENDENT PARODY WORK');
  await expect(log(page)).toContainText('RESPECTIVE OWNERS.');
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

test.describe('full motion', () => {
  test.use({ contextOptions: { reducedMotion: 'no-preference' } });

  // The 940/760 ms CRT branches put transforms, inline styles and inert on html/body: every exit must undo them.
  test('ESC, POWER and EXIT leave the page pristine after the full-length animations', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    await boot(page, true);
    await page.keyboard.press('Escape');
    await pageIsPristine(page);
    await boot(page, true);
    await page.locator('#orkple button', { hasText: /power/i }).click();
    await pageIsPristine(page);
    await boot(page, true);
    await type(page, 'exit');
    await pageIsPristine(page);
    expect(errors).toEqual([]);
  });

  test('ESC pressed during the insert animation does not strand the page', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.locator('#orkple-floppy').click({ force: true });
    await page.keyboard.press('Escape');
    // Mid power-off: the page is collapsing and ORKPLE II has not printed its banner yet.
    expect(await log(page).filter({ hasText: 'ORKPLE II' }).count()).toBe(0);
    // Either the ESC is ignored and ORKPLE II finishes booting, or it backs out; both must end usable.
    await expect.poll(async () => {
      if (await page.locator('#orkple').isVisible()) { return 'open'; }
      const cls = await page.evaluate(() => document.documentElement.className);
      return /orkple/.test(cls) ? 'busy' : 'closed';
    }, { timeout: 10000 }).not.toBe('busy');
    if (await page.locator('#orkple').count()) {
      await expect(log(page)).toContainText('ORKPLE II', { timeout: 10000 });
      await page.keyboard.press('Escape');
    }
    await pageIsPristine(page);
    // And the floppy still works afterwards.
    await boot(page, true);
    await page.keyboard.press('Escape');
    await pageIsPristine(page);
    expect(errors).toEqual([]);
  });

  test('ESC on the boot screen, before the DOS banner, shuts down cleanly', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    await page.locator('#orkple-floppy').click({ force: true });
    await expect(page.locator('#orkple')).toBeVisible({ timeout: 10000 });
    expect(await log(page).filter({ hasText: 'TRY TYPING ORK' }).count()).toBe(0);
    await page.keyboard.press('Escape');
    await pageIsPristine(page);
    expect(errors).toEqual([]);
  });
});

test.describe('on a touch-only device', () => {
  test.use({ hasTouch: true, isMobile: true, viewport: { width: 390, height: 844 } });

  // Touch-only devices get no floppy; the disk still starts from Orkple.insert() (the Release Notes button).
  async function insertDisk(page: Page) {
    await page.goto('./index.php?Route=Search');
    await expect.poll(() => page.evaluate(() => typeof (window as any).Orkple?.insert)).toBe('function');
    await expect(page.locator('#orkple-floppy-dock')).toHaveCount(0);
    await page.evaluate(() => (window as any).Orkple.insert());
    const screen = page.locator('#orkple [role=log]');
    await expect(screen).toContainText('?NO KEYBOARD ERROR', { timeout: 10000 });
    return screen;
  }

  test('stops at the BIOS screen with POWER focused, and POWER brings the page back', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    const screen = await insertDisk(page);
    await expect(screen).toContainText('ORKPLE ][');
    await expect(screen).toContainText('WORKS BEST ON A DESKTOP');
    await expect(screen).toContainText('PRESS POWER OR ESC TO RETURN');
    await expect(screen).not.toContainText('ORKDOS');
    await expect(page.locator('#orkple input')).toBeDisabled();
    await expect(page.locator('#orkple button[aria-label="Power off"]')).toBeFocused();
    await page.locator('#orkple button[aria-label="Power off"]').tap();
    await expect(page.locator('#orkple')).toHaveCount(0, { timeout: 10000 });
    expect(errors).toEqual([]);
  });

  test('a real key on the BIOS screen boots DOS, but a modifier shortcut does not', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    const screen = await insertDisk(page);
    await expect(page.locator('#orkple button[aria-label="Power off"]')).toBeFocused();
    await page.keyboard.press('Control+l');
    await expect(page.locator('#orkple input')).toBeDisabled();
    await expect(screen).not.toContainText('ORKDOS');
    await page.keyboard.press('a');
    await expect(screen).toContainText('ORKDOS 3.3', { timeout: 10000 });
    await expect(page.locator('#orkple input')).toBeEnabled();
    expect(errors).toEqual([]);
  });
});
