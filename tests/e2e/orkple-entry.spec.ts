/**
 * ORKPLE II entry points that reach every user, not just the floppy on Route=Search:
 * the pinned ORK 0.1 card on Release Notes and its Insert Floppy Disk button ([data-orkple-insert]),
 * the launcher in insert-only mode (data-floppy="0") outside the event window, the event window itself
 * (the orkple_preview=1 cookie), and the CRT What's New modal with wnInsertDisk's happy path and fallback.
 *
 * The CRT tests need the signed-in test player (heraldsbridge) to not have seen the current release yet. They
 * clear that one player's row in ork_whats_new_seen through the local docker DB and put it back afterwards;
 * no other player is touched. Without docker access they skip.
 */
import { test, expect, Page } from '@playwright/test';
import { execFileSync } from 'child_process';
import * as fs from 'fs';
import * as path from 'path';
import { appReachable, previewOn, log } from './orkple-helpers';

const CONTENT = fs.readFileSync(path.resolve(__dirname, '../../orkui/whats_new_content.php'), 'utf8');
const WN_VERSION = (/define\('WHATS_NEW_VERSION', '([^']+)'\)/.exec(CONTENT) || [])[1] || '';
const EVENT_START = (/'event_start' => '(\d{4}-\d{2}-\d{2})'/.exec(CONTENT) || [])[1] || '';
const EVENT_END = (/'event_end' => '(\d{4}-\d{2}-\d{2})'/.exec(CONTENT) || [])[1] || '';
const TODAY = new Date().toISOString().slice(0, 10);
const IN_WINDOW = !!EVENT_START && TODAY >= EVENT_START && TODAY <= EVENT_END;

const TEST_USER = 'heraldsbridge';
const DB_CONTAINER = process.env.ORK3_DB_CONTAINER || 'ork3-php8-db';

test.use({ contextOptions: { reducedMotion: 'reduce' } });

test.beforeEach(async ({ baseURL }, testInfo) => {
  if (!baseURL || !(await appReachable(baseURL))) {
    testInfo.skip(true, 'ORK3 app not reachable — start docker compose php8 stack');
  }
});

const releaseNotes = (page: Page) => page.goto('./index.php?Route=ReleaseNotes&wn=0');

async function booted(page: Page) {
  await expect(page.locator('#orkple')).toBeVisible({ timeout: 10000 });
  await expect(log(page)).toContainText('ORKPLE II', { timeout: 10000 });
}

test.describe('Release Notes', () => {
  test.beforeEach(async ({ context, baseURL }) => { await previewOn(context, baseURL!); });

  test('ORK 0.1 is the first card, with its own label, date and anchor; the rest run newest first', async ({ page }) => {
    await releaseNotes(page);
    const cards = page.locator('.rn-release');
    expect(await cards.count()).toBeGreaterThan(2);
    const first = cards.first();
    await expect(first).toHaveAttribute('id', 'release-0-1');
    await expect(first).toHaveClass(/rn-orkple/);
    await expect(first.locator('.rn-release-title')).toHaveText('ORK 0.1');
    await expect(first.locator('.rn-release-date')).toHaveText('Fall 1983');
    // Every other card is dated, and the dates never go up.
    const dates = await cards.locator('.rn-release-date').allTextContents();
    const times = dates.slice(1).map((d) => Date.parse(d));
    times.forEach((t) => expect(Number.isNaN(t)).toBe(false));
    for (let i = 1; i < times.length; i++) { expect(times[i]).toBeLessThanOrEqual(times[i - 1]); }
    // Anchors are unique: the theme and the page share one slug helper.
    const ids = await cards.evaluateAll((els) => els.map((e) => e.id));
    expect(new Set(ids).size).toBe(ids.length);
  });

  test('the card\'s Insert Floppy Disk button boots ORKPLE II', async ({ page }) => {
    await releaseNotes(page);
    const btn = page.locator('#release-0-1 [data-orkple-insert]');
    await expect(btn).toHaveCount(1);
    await expect.poll(() => page.evaluate(() => typeof (window as any).Orkple?.insert)).toBe('function');
    await btn.click();
    await booted(page);
    await page.keyboard.press('Escape');
    await expect(page.locator('#orkple')).toHaveCount(0);
  });
});

test.describe('event window', () => {
  test('without the preview cookie (outside the window) normal pages have no launcher; Release Notes still boots', async ({ page }) => {
    test.skip(IN_WINDOW, `today (${TODAY}) is inside the ORKPLE window ${EVENT_START}..${EVENT_END}`);
    await page.goto('./index.php?Route=Search');
    await expect(page.locator('script[src*="orkple-floppy.js"]')).toHaveCount(0);
    await expect(page.locator('#orkple-floppy')).toHaveCount(0);

    // Release Notes loads the launcher in insert-only mode: no floppy, but Orkple.insert() and the button work.
    await releaseNotes(page);
    await expect(page.locator('script[src*="orkple-floppy.js"]')).toHaveAttribute('data-floppy', '0');
    await expect.poll(() => page.evaluate(() => typeof (window as any).Orkple?.insert)).toBe('function');
    await expect(page.locator('#orkple-floppy-dock, #orkple-floppy')).toHaveCount(0);
    await page.locator('#release-0-1 [data-orkple-insert]').click();
    await booted(page);
  });

  test('data-floppy="0": no floppy, and Orkple.insert() still boots', async ({ page }) => {
    test.skip(IN_WINDOW, `today (${TODAY}) is inside the ORKPLE window`);
    await releaseNotes(page);
    await expect(page.locator('script[src*="orkple-floppy.js"]')).toHaveAttribute('data-floppy', '0');
    await expect.poll(() => page.evaluate(() => typeof (window as any).Orkple?.insert)).toBe('function');
    await expect(page.locator('#orkple-floppy')).toHaveCount(0);
    expect(await page.evaluate(() => (window as any).Orkple.insert())).toBe(true);
    await booted(page);
  });

  test('with orkple_preview=1 the floppy is on normal pages, without data-floppy', async ({ page, context, baseURL }) => {
    await previewOn(context, baseURL!);
    await page.goto('./index.php?Route=Search');
    const s = page.locator('script[src*="orkple-floppy.js"]');
    await expect(s).toHaveCount(1);
    expect(await s.getAttribute('data-floppy')).toBeNull();
    await expect(page.locator('#orkple-floppy')).toBeVisible({ timeout: 10000 });
    // The launcher links its own stylesheet in the head (no body <link>).
    await expect(page.locator('head link#orkple-floppy-css')).toHaveCount(1);
  });
});

// ---- the CRT What's New modal (ORK 0.1) ----

function sql(q: string): string {
  return execFileSync('docker', ['exec', DB_CONTAINER, 'sh', '-c', 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" -N -B ork -e "$0"', q], { encoding: 'utf8', timeout: 15000 }).trim();
}
let testUid = 0;
let hadSeenRow = false;
function seenRows(): number {
  return Number(sql(`SELECT COUNT(*) FROM ork_whats_new_seen WHERE mundane_id = ${testUid} AND version = '${WN_VERSION}'`));
}
const forgetSeen = () => sql(`DELETE FROM ork_whats_new_seen WHERE mundane_id = ${testUid} AND version = '${WN_VERSION}'`);
const restoreSeen = () => {
  if (hadSeenRow) { sql(`INSERT IGNORE INTO ork_whats_new_seen (mundane_id, version) VALUES (${testUid}, '${WN_VERSION}')`); }
  else { forgetSeen(); }
};

async function login(page: Page) {
  const r = await page.request.post('./index.php?Route=Login/login', { form: { username: TEST_USER, password: 'x' } });
  expect(r.ok()).toBe(true);
}

test.describe('CRT What\'s New', () => {
  test.describe.configure({ mode: 'serial' });

  test.beforeAll(() => {
    test.skip(!/^[\w-]+$/.test(WN_VERSION), 'no WHATS_NEW_VERSION');
    try {
      testUid = Number(sql(`SELECT mundane_id FROM ork_mundane WHERE username = '${TEST_USER}' LIMIT 1`));
    } catch {
      testUid = 0;
    }
    test.skip(!testUid, 'needs the local docker DB (' + DB_CONTAINER + ') to reset the test player\'s What\'s New dismissal');
    hadSeenRow = seenRows() > 0;
  });
  test.beforeEach(async ({ page }) => {
    forgetSeen();
    await login(page);
  });
  test.afterEach(() => { restoreSeen(); });

  // Count WnAjax/dismiss posts without letting them reach the server (the row stays cleared for the test).
  async function watchDismiss(page: Page) {
    const posts: string[] = [];
    await page.route('**/index.php?Route=WnAjax/dismiss**', (route) => {
      posts.push(route.request().postData() || '');
      return route.fulfill({ contentType: 'application/json', body: '{"Status":{"Status":0}}' });
    });
    return posts;
  }

  test('outside the window ORK 0.1 raises no modal and marks nothing seen', async ({ page }) => {
    test.skip(IN_WINDOW, 'today is inside the ORKPLE window');
    const posts = await watchDismiss(page);
    await page.goto('./index.php?Route=Search');
    await expect(page.locator('#wn-overlay')).toHaveCount(0);
    await page.waitForLoadState('load');
    expect(posts).toEqual([]);
    expect(seenRows()).toBe(0);
  });

  test('with the preview cookie the CRT modal shows (not the plain one), without notes-only items; Later dismisses', async ({ page, context, baseURL }) => {
    await previewOn(context, baseURL!);
    const posts = await watchDismiss(page);
    await page.goto('./index.php?Route=Search');
    const ov = page.locator('#wn-overlay.wn-open');
    await expect(ov.locator('.wn-crt')).toBeVisible();
    await expect(page.locator('.wn-modal')).toHaveCount(0);
    await expect(ov.locator('.wn-crt-sub')).toContainText('ORK 0.1');
    // notes_only items (Load the Disk) are on the Release Notes card only.
    await expect(ov.locator('.wn-crt-item-title', { hasText: 'Load the Disk' })).toHaveCount(0);
    expect(await ov.locator('.wn-crt-item').count()).toBeGreaterThan(0);
    // The modal's link and the Release Notes card share one anchor.
    expect(await ov.locator('.wn-crt-links a').getAttribute('href')).toMatch(/ReleaseNotes&wn=0#release-0-1$/);
    await expect(ov.locator('.wn-crt-insert')).toBeFocused();
    await ov.getByRole('button', { name: /^Later/ }).click();
    await expect(page.locator('#wn-overlay.wn-open')).toHaveCount(0);
    await expect.poll(() => posts.length).toBe(1);
    expect(posts[0]).toContain('version=' + encodeURIComponent(WN_VERSION));
  });

  test('Insert Floppy Disk boots ORKPLE II and then marks the release seen', async ({ page, context, baseURL }) => {
    await previewOn(context, baseURL!);
    const posts = await watchDismiss(page);
    await page.goto('./index.php?Route=Search');
    await expect.poll(() => page.evaluate(() => typeof (window as any).Orkple?.insert)).toBe('function');
    await page.locator('#wn-overlay .wn-crt-insert').click();
    await booted(page);
    await expect(page.locator('#wn-overlay.wn-open')).toHaveCount(0);
    expect(posts.length).toBe(1);
  });

  test('with the launcher blocked, Insert Floppy Disk goes to the ORK 0.1 card, unseen and with no modal over it', async ({ page, context, baseURL }) => {
    await previewOn(context, baseURL!);
    const posts = await watchDismiss(page);
    await page.route('**/orkple-floppy.js*', (route) => route.abort());
    await page.goto('./index.php?Route=Search');
    await page.waitForLoadState('load');
    await page.locator('#wn-overlay .wn-crt-insert').click();
    await page.waitForURL(/Route=ReleaseNotes&wn=0#release-0-1$/);
    await expect(page.locator('#release-0-1')).toBeVisible();
    await expect(page.locator('#wn-overlay')).toHaveCount(0);
    expect(posts).toEqual([]);
    expect(seenRows()).toBe(0);
  });
});
