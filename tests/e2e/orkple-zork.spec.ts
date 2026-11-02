/**
 * ORKPLE II game "zORK": real key input through the first rooms, the dark-room lose,
 * instant restart, and QUIT back to the ORK GAMES menu.
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

// ORKPLE_FULL_MOTION=1 runs the same specs with the title wait and note holds live.
test.use({ contextOptions: { reducedMotion: process.env.ORKPLE_FULL_MOTION ? 'no-preference' : 'reduce' } });

test.beforeEach(async ({ baseURL }, testInfo) => {
  if (!baseURL || !(await appReachable(baseURL))) {
    testInfo.skip(true, 'ORK3 app not reachable — start docker compose php8 stack');
  }
});

const log = (page: Page) => page.locator('#orkple [role=log]');

// Keys typed while the terminal is still printing are dropped, so let the log go quiet first.
async function settled(page: Page, quietMs = 400) {
  let last = (await log(page).textContent()) ?? '';
  let since = Date.now();
  while (Date.now() - since < quietMs) {
    await page.waitForTimeout(50);
    const now = (await log(page).textContent()) ?? '';
    if (now !== last) {
      last = now;
      since = Date.now();
    }
  }
}

async function type(page: Page, text: string) {
  await settled(page);
  await page.keyboard.type(text);
  await page.keyboard.press('Enter');
  await settled(page);
}

async function openZork(page: Page) {
  await page.goto('./index.php?Route=Search');
  await page.locator('#orkple-floppy').click({ force: true });
  await expect(log(page)).toContainText('ORKPLE II', { timeout: 10000 });
  await type(page, 'ork');
  await expect(log(page)).toContainText('ORK GAMES', { timeout: 10000 });
  await type(page, '4');
  await expect(log(page)).toContainText('NUMBER MUNCHORKS', { timeout: 10000 });
  await type(page, '2');
  await expect(log(page)).toContainText('THE ONLINE RECORD DUNGEON', { timeout: 15000 });
  // The title now stands alone on a hi-res screen; RETURN clears it for the first room.
  await settled(page);
  await page.keyboard.press('Enter');
  await expect(log(page)).toContainText('PARKING LOT', { timeout: 15000 });
}

test('zork: opening moves, a block, and a score notice', async ({ page }) => {
  await openZork(page);
  await type(page, 'n');
  await expect(log(page)).toContainText('DRESSED AS SOMEONE WITH A JOB');
  await type(page, 'open trunk');
  await expect(log(page)).toContainText('YOU TAKE TUNIC AND BOFFER');
  await type(page, 'wear garb');
  await expect(log(page)).toContainText('[+3 CREDITS, 3/53]');
  await type(page, 'n');
  await expect(log(page)).toContainText('A BATTLE IS ABOUT TO START');
});

test('zork: the dark tree line loses, any key restarts, QUIT returns to the menu', async ({ page }) => {
  await openZork(page);
  for (const c of ['open trunk', 'wear garb', 'n', 'open cooler', 'tape boffer', 'w', 'show boffer to reeve']) {
    await type(page, c);
  }
  await expect(log(page)).toContainText('WEAPON CHECK SURVIVOR', { timeout: 15000 });
  await type(page, 'w');
  await expect(log(page)).toContainText('IT IS VERY DARK UNDER THE TREES');
  await type(page, 'wait');
  await expect(log(page)).toContainText('LAST WARNING');
  await type(page, 'wait');
  await settled(page, 900);
  await page.keyboard.press('Enter'); // PRESS RETURN
  await expect(log(page)).toContainText('YOU HAVE BEEN ELECTED', { timeout: 15000 });
  await expect(log(page)).toContainText('RETURN TO PLAY AGAIN, OR QUIT.');
  await settled(page, 900);
  await page.keyboard.press('Enter'); // instant restart
  await expect(log(page)).toContainText('PARK DAY. YOU DROVE FAR', { timeout: 15000 });
  await type(page, 'quit');
  await expect(log(page)).toContainText('NUMBER MUNCHORKS');
  await expect(page.locator('#orkple')).toBeVisible();
});

test('zork: title banner, famous-word eggs and the mailbox gag', async ({ page }) => {
  await openZork(page);
  await expect(log(page)).toContainText('RELEASE 1 / SERIAL NO. 040101');
  await type(page, 'zork');
  await expect(log(page)).toContainText('MERGE?');
  await type(page, 'open mailbox');
  await expect(log(page)).toContainText('THE TRUNK IS THE');
  await type(page, 'hello sailor');
  await expect(log(page)).toContainText('NO SAILORS.');
  // Eggs are not parser errors, so no HINT nag after three of them.
  await expect(log(page)).not.toContainText('TRY HINT');
  // Bare SNAP with no stick in reach no longer scores.
  await type(page, 'snap');
  await expect(log(page)).toContainText('YOU SEE NO STICK HERE.');
  await expect(log(page)).not.toContainText('CREDITS, 3/53');
});

test('zork: natural phrasings, the sash, the finished form and the win', async ({ page }) => {
  test.setTimeout(120000);
  await openZork(page);
  for (const c of ['open trunk', 'wear garb', 'n', 'open bin', 'open cooler', 'snap stick', 'tape boffer', 'w', 'show boffer', 'w', 'take belt', 'e', 'give belt to reeve']) {
    await type(page, c);
  }
  await type(page, 'e'); // first revisit of the green
  await expect(log(page)).toContainText("'BACK IN 5!'");
  await type(page, 'e');
  await type(page, 'sign waiver');
  await type(page, 'sign sheet');
  await type(page, 'open door'); // used to refuse: THAT DOES NOT OPEN
  await expect(log(page)).toContainText('LOGIN GATE');
  await type(page, 'push gate');
  await expect(log(page)).toContainText('IT IS A THING THAT YOU');
  await type(page, 'type lay on');
  await expect(log(page)).toContainText('GATE LIFTS');
  await type(page, 'colour');
  await expect(log(page)).toContainText("'SAVED. 1 CREDIT ADDED.'", { timeout: 15000 });
  await expect(log(page)).toContainText('COURT IS EAST. THE GREEN IS WEST.');
  await type(page, 'e');
  await type(page, 'kneel');
  await settled(page, 900);
  await page.keyboard.press('Enter'); // PRESS RETURN
  await expect(log(page)).toContainText('YOU HAVE BEEN RECORDED', { timeout: 15000 });
  await expect(log(page)).toContainText('GAME CREDITS ARE NOT REAL CREDITS.');
});

test('zork: dropping the glow stick in the dark is refused', async ({ page }) => {
  await openZork(page);
  for (const c of ['open trunk', 'wear garb', 'n', 'open bin', 'open cooler', 'tape boffer', 'w', 'show boffer', 'w']) {
    await type(page, c);
  }
  await expect(log(page)).toContainText('IT IS VERY DARK UNDER THE TREES');
  await type(page, 'drop stick');
  await expect(log(page)).toContainText('YOU WOULD NEVER FIND IT AGAIN');
});
