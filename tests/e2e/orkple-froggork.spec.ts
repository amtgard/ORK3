/**
 * FROGGORK (ORKPLE II game): loads from the ORK GAMES menu, plays in the real terminal, and leaves cleanly.
 * The field is pixel art on the host sprite layer; the tests drive the game through hops and read its state via _dbg.
 */
import { test, expect, Page } from '@playwright/test';
import { orkpleSetup, log, bootGame, tick } from './orkple-helpers';

orkpleSetup();

const state = (page: Page) => page.evaluate(() => {
  const g = (window as any).OrkpleGames.froggork._dbg();
  return { mode: g.mode, score: g.score, lives: g.lives, band: g.p ? g.p.band : -1, x: g.p ? g.p.x : -1, level: g.level, cause: g.cause, filled: g.filled, carry: g.p ? g.p.carry : false, ms: g.ms, timeMs: g.timeMs, lastSay: g.lastSay };
});

async function openGame(page: Page) {
  await bootGame(page, 'froggork', { ready: 'F R O G G O R K' });
}

// Clear the lanes of anything that could hurt, and stop the random extras, so a test controls what the field does.
const calmField = (page: Page) => page.evaluate(() => {
  const g = (window as any).OrkpleGames.froggork._dbg();
  g.world.lanes.forEach((l: any) => { if (l.band >= 7) { l.offs = []; } });
  g.ev = null; g.evNext = 1e9; g.pax = null; g.paxNext = 1e9;
});

async function shot(page: Page, name: string) {
  const base = process.env.FROGGORK_SHOT || 'test-results/froggork.png';
  await page.locator('#orkple canvas').first().screenshot({ path: base.replace(/\.png$/, '-' + name + '.png') });
}

async function toPlay(page: Page) {
  await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('PLAY');
}

for (const motion of ['reduce', 'no-preference'] as const) {
  test.describe('motion ' + motion, () => {
    test.use({ contextOptions: { reducedMotion: motion } });
    const tag = motion === 'reduce' ? '' : 'motion-';

    test('FROGGORK: hops on all four keys, a hazard costs a life, a platform carries you, a slot fills, Q then Y leaves', async ({ page }) => {
      test.setTimeout(90000);
      const errors: string[] = [];
      page.on('pageerror', e => errors.push(e.message));
      await openGame(page);
      await expect.poll(async () => (await state(page)).mode).toBe('TITLE');
      await shot(page, tag + 'title');
      await page.keyboard.press('Space');
      await expect(log(page)).toContainText('THE MUD PIT', { timeout: 10000 });
      await page.keyboard.press('Space');
      await toPlay(page);
      await calmField(page);

      // All four keys hop one step each: up and down a lane, sideways along the strip.
      const s0 = await state(page);
      expect(s0.band).toBe(12);
      await page.keyboard.press('ArrowUp');
      expect((await state(page)).band).toBe(11);
      await page.keyboard.press('ArrowDown');
      expect((await state(page)).band).toBe(12);
      await page.keyboard.press('a');
      expect((await state(page)).x).toBe(s0.x - 10);
      await page.keyboard.press('d');
      await page.keyboard.press('d');
      expect((await state(page)).x).toBe(s0.x + 10);
      await page.keyboard.press('ArrowLeft');
      expect((await state(page)).x).toBe(s0.x);
      await page.keyboard.press('ArrowRight');
      await page.keyboard.press('ArrowLeft');
      await page.keyboard.press('w');
      expect((await state(page)).band).toBe(11);
      await page.keyboard.press('s');
      expect((await state(page)).band).toBe(12);

      // A hazard in the next lane costs a life, with its own line.
      await page.evaluate(() => {
        const g = (window as any).OrkpleGames.froggork._dbg();
        const l = g.world.by[11];
        l.v = 0; l.ph = 0; l.offs = [g.p.x - 2];
      });
      await page.keyboard.press('ArrowUp');
      await expect.poll(async () => (await state(page)).mode).toBe('DYING');
      const dead = await state(page);
      expect(dead.lives).toBe(2);
      expect(dead.cause).toBe('newbie');
      await expect.poll(async () => (await state(page)).mode, { timeout: 6000 }).toBe('PLAY');
      expect((await state(page)).band).toBe(12);

      // Cross to the median, then ride a stretcher: it carries the player sideways.
      await calmField(page);
      for (let i = 0; i < 6; i++) { await page.keyboard.press('ArrowUp'); }
      expect((await state(page)).band).toBe(6);
      await page.evaluate(() => {
        const g = (window as any).OrkpleGames.froggork._dbg();
        const l = g.world.by[5];
        l.ph = 0; l.dir = 1; l.v = 40; l.offs = [g.p.x - 6, g.p.x + 150];
        // line the stretcher up under the player at this very moment
        l.ph = g.p.x - 6 - (l.offs[0] + l.dir * l.v * g.t / 1000);
        l.offs = [l.offs[0], l.offs[1]];
      });
      await page.keyboard.press('ArrowUp');
      const r0 = await state(page);
      expect(r0.band).toBe(5);
      expect(r0.mode).toBe('PLAY');
      await shot(page, tag + 'ride');
      await tick(page, 500);
      const r1 = await state(page);
      expect(r1.band).toBe(5);
      expect(r1.x).toBeGreaterThan(r0.x + 8);

      // Fill a sign-in table.
      await page.evaluate(() => {
        const g = (window as any).OrkpleGames.froggork._dbg();
        g.p.band = 1; g.p.ride = null; g.p.x = 140 - 4.5;
        const l = g.world.by[1]; l.v = 0; l.ph = 0; l.duck = null; l.offs = [g.p.x - 6];
        g.ev = null; g.evNext = 1e9;
      });
      await page.keyboard.press('ArrowUp');
      await expect.poll(async () => (await state(page)).mode).toBe('HOME');
      const home = await state(page);
      expect(home.filled[2]).toBe(true);
      expect(home.score).toBeGreaterThan(50);
      await expect.poll(async () => (await state(page)).mode, { timeout: 6000 }).toMatch(/READY|PLAY/);

      // Q asks first; a stray N goes back, Q then Y leaves for the menu.
      await page.keyboard.press('q');
      await expect.poll(async () => (await state(page)).mode).toBe('ASK');
      await page.keyboard.press('n');
      await expect.poll(async () => (await state(page)).mode).not.toBe('ASK');
      await page.keyboard.press('q');
      await page.keyboard.press('y');
      await expect(log(page)).toContainText('1. ORKEGON TRAIL', { timeout: 10000 });
      expect(errors).toEqual([]);
    });

    test('FROGGORK: three levels clear through the cards to the ending', async ({ page }) => {
      test.setTimeout(180000);
      const errors: string[] = [];
      page.on('pageerror', e => errors.push(e.message));
      await openGame(page);
      await expect.poll(async () => (await state(page)).mode).toBe('TITLE');
      await page.keyboard.press('Space');

      const names = ['THE MUD PIT', 'THE CREEK BED', 'THE BRIDGE BATTLE'];
      for (let lv = 0; lv < 3; lv++) {
        await expect(log(page)).toContainText(names[lv], { timeout: 10000 });
        await page.keyboard.press('Space');
        await toPlay(page);
        await tick(page, 700);
        await shot(page, tag + 'level' + (lv + 1));
        await calmField(page);
        // Four tables are already signed; the player hops into the last one.
        await page.evaluate(() => {
          const g = (window as any).OrkpleGames.froggork._dbg();
          g.filled = [true, true, true, true, false];
          g.p.band = 1; g.p.ride = null; g.p.x = 252 - 4.5;
          const l = g.world.by[1]; l.v = 0; l.ph = 0; l.duck = null; l.offs = [g.p.x - 6];
        });
        await page.keyboard.press('ArrowUp');
        await expect.poll(async () => (await state(page)).mode, { timeout: 4000 }).toBe('HOME');
        if (lv < 2) {
          await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('CLEAR');
        }
      }
      await expect(log(page)).toContainText('THE WRONG PARK', { timeout: 20000 });
      await expect(log(page)).toContainText('SPACE TO PLAY AGAIN', { timeout: 20000 });
      expect(errors).toEqual([]);
    });
  });
}

test.describe('polish', () => {
  test.use({ contextOptions: { reducedMotion: 'reduce' } });

  test('FROGGORK: the title signposts the extra life, and every death cause draws without error', async ({ page }) => {
    test.setTimeout(60000);
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    await openGame(page);
    await expect(log(page)).toContainText('A STRANGER LENDS A TABARD AT 3500', { timeout: 10000 });
    await page.keyboard.press('Space');
    await page.keyboard.press('Space');
    await toPlay(page);
    await calmField(page);
    for (const cause of ['mud', 'sink', 'edge', 'arrow', 'ball', 'fighters', 'newbie', 'cart', 'lurker', 'tent', 'taken', 'time']) {
      await page.evaluate((c) => {
        const g = (window as any).OrkpleGames.froggork._dbg();
        g.p.x = c === 'edge' ? -5 : 100; g.p.band = c === 'tent' || c === 'taken' ? 0 : 3;
        g.ev = c === 'lurker' ? { slot: 1, kind: 'lurker', ms: 900 } : null; g.evNext = 1e9;
        g.cause = c; g.mode = 'DYING'; g.ms = 0;
      }, cause);
      await tick(page, 260);
      if (cause === 'mud' || cause === 'ball' || cause === 'tent') { await shot(page, 'death-' + cause); }
      await page.evaluate(() => { (window as any).OrkpleGames.froggork._dbg().ms = 1000; });
      await tick(page, 120);
      await expect.poll(async () => (await state(page)).mode, { timeout: 4000 }).toMatch(/READY|PLAY/);
      await calmField(page);
    }
    expect(errors).toEqual([]);
  });

  test('FROGGORK: the death line survives the respawn, and a hop pressed late in the respawn is kept', async ({ page }) => {
    test.setTimeout(60000);
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    await openGame(page);
    await page.keyboard.press('Space');
    await page.keyboard.press('Space');
    await toPlay(page);
    await calmField(page);
    await page.evaluate(() => { (window as any).OrkpleGames.froggork._dbg().timeMs = 40; });
    await expect.poll(async () => (await state(page)).mode).toBe('DYING');
    expect((await state(page)).lastSay).toBe('THE REEVE CALLS TIME. SIGN-IN IS CLOSED.');
    await expect.poll(async () => (await state(page)).mode, { timeout: 4000 }).toBe('READY');
    expect((await state(page)).lastSay).not.toBe('');
    await expect.poll(async () => (await state(page)).ms, { timeout: 3000 }).toBeGreaterThanOrEqual(420);
    const before = await state(page);
    if (before.mode === 'READY') {
      await page.keyboard.press('ArrowUp');
      expect((await state(page)).band).toBe(12);
    }
    await expect.poll(async () => (await state(page)).mode, { timeout: 3000 }).toBe('PLAY');
    await expect.poll(async () => (await state(page)).band, { timeout: 1000 }).toBe(before.mode === 'READY' ? 11 : 12);
    expect(errors).toEqual([]);
  });

  test('FROGGORK: crossing 3500 points lends a tabard and says so; N from HOLD brings the HOLD line back', async ({ page }) => {
    test.setTimeout(60000);
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    await openGame(page);
    await page.keyboard.press('Space');
    await page.keyboard.press('Space');
    await toPlay(page);
    await calmField(page);
    await page.evaluate(() => {
      const g = (window as any).OrkpleGames.froggork._dbg();
      g.score = 3490;
      g.p.band = 1; g.p.ride = null; g.p.x = 140 - 4.5;
      const l = g.world.by[1]; l.v = 0; l.ph = 0; l.duck = null; l.offs = [g.p.x - 6];
    });
    await page.keyboard.press('ArrowUp');
    await expect.poll(async () => (await state(page)).mode).toBe('HOME');
    expect((await state(page)).lives).toBe(4);
    await expect.poll(async () => (await state(page)).lastSay, { timeout: 5000 }).toBe('A STRANGER LENDS A TABARD. EXTRA LIFE.');
    await expect.poll(async () => (await state(page)).mode, { timeout: 5000 }).toBe('PLAY');
    await calmField(page);
    await page.keyboard.press('Space');
    await expect.poll(async () => (await state(page)).mode).toBe('HOLD');
    await page.keyboard.press('q');
    await expect.poll(async () => (await state(page)).mode).toBe('ASK');
    await page.keyboard.press('n');
    await expect.poll(async () => (await state(page)).mode).toBe('HOLD');
    expect((await state(page)).lastSay).toMatch(/^HOLD!/);
    expect(errors).toEqual([]);
  });
});

test.describe('game over', () => {
  test.use({ contextOptions: { reducedMotion: 'reduce' } });
  test('FROGGORK: out of lives shows the ending, SPACE returns to the title', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', e => errors.push(e.message));
    await openGame(page);
    await page.keyboard.press('Space');
    await page.keyboard.press('Space');
    await toPlay(page);
    // run the reeve's clock out on every life
    for (let i = 0; i < 60; i++) {
      if ((await state(page)).mode === 'OVER') { break; }
      await page.evaluate(() => { const g = (window as any).OrkpleGames.froggork._dbg(); if (g.mode === 'PLAY') { g.timeMs = 40; } });
      await tick(page, 250);
    }
    await expect(log(page)).toContainText('OUT OF LIVES', { timeout: 15000 });
    await expect.poll(async () => (await state(page)).mode).toBe('OVER');
    await page.keyboard.press('Space');
    await expect.poll(async () => (await state(page)).mode).toBe('TITLE');
    expect(errors).toEqual([]);
  });
});
