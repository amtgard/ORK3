/**
 * Shared helpers for the ORKPLE II specs (not a spec itself: Playwright only collects *.spec.ts).
 *
 * - previewOn(): ORKPLE is only live inside its event window (ORK 0.1 event_start..event_end); the
 *   orkple_preview=1 cookie turns it on outside the window, so every spec that boots ORKPLE sets it.
 * - testFlag(): the _dbg / probe hooks only exist when window.OrkpleStrict is set before the scripts load.
 * - bootGame(): boots ORKPLE straight into a game with page.clock installed and window.OrkpleSeed set,
 *   so host.rand repeats and the specs advance game time with runFor() instead of sleeping.
 */
import { test, expect, Page, BrowserContext } from '@playwright/test';

export async function appReachable(baseURL: string): Promise<boolean> {
  try {
    const res = await fetch(baseURL, { method: 'HEAD' });
    return res.ok || res.status === 302 || res.status === 405;
  } catch {
    return false;
  }
}

/** Skip every test in the file when the docker stack is down, and turn ORKPLE on with the preview cookie. */
export function orkpleSetup(opts: { preview?: boolean } = {}) {
  test.beforeEach(async ({ baseURL, context }, testInfo) => {
    if (!baseURL || !(await appReachable(baseURL))) {
      testInfo.skip(true, 'ORK3 app not reachable — start docker compose php8 stack');
      return;
    }
    if (opts.preview !== false) { await previewOn(context, baseURL); }
  });
}

export async function previewOn(context: BrowserContext, baseURL: string) {
  await context.addCookies([{ name: 'orkple_preview', value: '1', url: new URL('/', baseURL).href }]);
}

/** Set the test flag (and optionally the RNG seed) before any page script runs. */
export async function testFlag(page: Page, seed?: number) {
  await page.addInitScript((s) => {
    (window as any).OrkpleStrict = true;
    if (typeof s === 'number') { (window as any).OrkpleSeed = s; }
  }, seed ?? null);
}

export const log = (page: Page) => page.locator('#orkple [role=log]');

export async function type(page: Page, text: string) {
  await page.keyboard.type(text);
  await page.keyboard.press('Enter');
}

/** Order and titles of the ORK GAMES menu (orkple-terminal.js GAMES / GAME_TITLES). */
export const GAMES: Record<string, { n: number; title: string }> = {
  trail: { n: 1, title: 'ORKEGON TRAIL' },
  zork: { n: 2, title: 'DUNGEORK' },
  munchorks: { n: 3, title: 'NUMBER MUNCHORKS' },
  pacmork: { n: 4, title: 'MORKMAZE' },
  asterorks: { n: 5, title: 'ASTERORKS' },
  castlorks: { n: 6, title: 'GEM CASTLORKS' },
  froggork: { n: 7, title: 'FROGGORK' },
  invadorks: { n: 8, title: 'INVADORKS' },
};

/**
 * Click the floppy and wait for the DOS prompt. A DOM click, not a pointer click: it removes pointer and
 * bob-animation timing (orkple.spec.ts covers the real click).
 */
export async function bootTerminal(page: Page) {
  await page.locator('#orkple-floppy').evaluate((b: HTMLElement) => b.click());
  await expect(log(page)).toContainText('TRY TYPING ORK', { timeout: 15000 });
}

/** Advance the page clock (installed by bootGame) in place of a sleep: game time, not wall time. */
export async function tick(page: Page, ms: number) {
  await page.clock.runFor(ms);
}

/**
 * Boot ORKPLE and start a game from the ORK GAMES menu, waiting for `ready` in the log (the game's own title text).
 * The page clock is installed before the page loads and keeps running in real time, so expect.poll still sees the
 * game move; tick() jumps it forward deterministically. window.OrkpleSeed seeds host.rand for every game start.
 */
export async function bootGame(page: Page, id: keyof typeof GAMES | string, opts: { seed?: number; clock?: boolean; ready?: string | RegExp } = {}) {
  const g = GAMES[id];
  if (!g) { throw new Error('unknown game ' + id); }
  if (opts.clock !== false) { await page.clock.install(); }
  await testFlag(page, opts.seed ?? 1);
  await page.goto('./index.php?Route=Search');
  await bootTerminal(page);
  await type(page, 'ork');
  await expect(log(page)).toContainText('ORK GAMES', { timeout: 10000 });
  await type(page, '4');
  await expect(log(page)).toContainText(`${g.n}. ${g.title}`, { timeout: 10000 });
  await type(page, String(g.n));
  if (opts.ready) {
    if (opts.clock !== false) {
      // Skip the 4 s ORKEK title card in game time.
      await expect.poll(async () => {
        if (await log(page).filter({ hasText: opts.ready! }).count()) { return true; }
        await page.clock.runFor(500);
        return false;
      }, { timeout: 15000 }).toBe(true);
    } else {
      await expect(log(page)).toContainText(opts.ready, { timeout: 10000 });
    }
  }
}

/** True when the game canvas shows something other than its background colour. */
export async function canvasLit(page: Page): Promise<boolean> {
  return page.locator('#orkple canvas').first().evaluate((c: HTMLCanvasElement) => {
    const ctx = c.getContext('2d');
    if (!ctx) { return false; }
    const d = ctx.getImageData(0, 0, c.width, c.height).data;
    const r0 = d[0], g0 = d[1], b0 = d[2];
    let lit = 0;
    for (let i = 0; i < d.length; i += 4) {
      if (Math.abs(d[i] - r0) + Math.abs(d[i + 1] - g0) + Math.abs(d[i + 2] - b0) > 60) { lit++; }
    }
    return lit > c.width * c.height * 0.01;
  });
}
