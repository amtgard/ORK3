/**
 * SPACE INVADORKS (ORKPLE II game): loads from the ORK GAMES menu, plays in the real terminal, and leaves cleanly.
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

test.beforeEach(async ({ baseURL }, testInfo) => {
  if (!baseURL || !(await appReachable(baseURL))) {
    testInfo.skip(true, 'ORK3 app not reachable — start docker compose php8 stack');
  }
});

const log = (page: Page) => page.locator('#orkple [role=log]');
const state = (page: Page) => page.evaluate(() => {
  const g = (window as any).OrkpleGames.invadorks._dbg();
  const al = g.fm ? g.fm.al.reduce((a: number, r: boolean[]) => a + r.filter(Boolean).length, 0) : 0;
  return {
    mode: g.mode, score: g.score, wave: g.wave, lives: g.lives, arrows: g.arrows.length, shots: g.shots.length,
    live: al, alive: g.alive, dir: g.dir, ax: g.ax, safe: g.safeMs, mer: g.mer.map((m: any) => m.n), steps: g.steps, ivl: g.ivl,
  };
});
const shotPath = (name: string, shot: string) => shot.replace('.png', `-${name}.png`);
const snap = (page: Page, path: string) => page.locator('#orkple canvas').first().screenshot({ path });

async function openGame(page: Page) {
  await page.goto('./index.php?Route=Search');
  await page.locator('#orkple-floppy').click({ force: true });
  await expect(log(page)).toContainText('ORKPLE II', { timeout: 10000 });
  await page.keyboard.type('ork');
  await page.keyboard.press('Enter');
  await expect(log(page)).toContainText('ORK GAMES', { timeout: 10000 });
  await page.keyboard.type('4');
  await page.keyboard.press('Enter');
  await expect(log(page)).toContainText('8. SPACE INVADORKS', { timeout: 10000 });
  await page.keyboard.type('8');
  await page.keyboard.press('Enter');
  await expect(log(page)).toContainText('I N V A D O R K S', { timeout: 10000 });
}

async function startPlay(page: Page) {
  await expect.poll(async () => (await state(page)).mode, { timeout: 20000 }).toBe('TITLE');
  await page.keyboard.press('Space');
  await expect(log(page)).toContainText('WAVE 1  FIGHTERS 45  SPEED X1.00', { timeout: 10000 });
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('PLAY');
}

// Quiet the world so a test controls it: no random throws, no mystery knight, the block waits.
const calm = (page: Page) => page.evaluate(() => {
  const g = (window as any).OrkpleGames.invadorks._dbg();
  g.shotMs = 1e9; g.mysMs = 1e9; g.stepMs = -1e9; g.safeMs = 0;
});
// Put the archer's centre under the lowest living fighter of a column.
const alignUnder = (page: Page, col: number) => page.evaluate((c) => {
  const g = (window as any).OrkpleGames.invadorks._dbg();
  g.ax = g.fm.x + c * 21 + 6 - 5; g.dir = 0;
}, col);
// Leave only the listed [row, col] fighters alive.
const leave = (page: Page, keep: Array<[number, number]>) => page.evaluate((k) => {
  const g = (window as any).OrkpleGames.invadorks._dbg();
  g.fm.al.forEach((r: boolean[], ri: number) => r.forEach((_: boolean, ci: number) => { r[ci] = k.some(p => p[0] === ri && p[1] === ci); }));
}, keep);

async function clearWave(page: Page) {
  await calm(page);
  await leave(page, [[4, 4]]);
  await alignUnder(page, 4);
  await page.keyboard.press('Space');
}

async function play(page: Page, shot: string) {
  test.setTimeout(150000);
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openGame(page);
  await expect.poll(async () => (await state(page)).mode, { timeout: 20000 }).toBe('TITLE');
  await snap(page, shotPath('title', shot));
  await page.keyboard.press('h');
  await expect(log(page)).toContainText('HOW TO PLAY', { timeout: 5000 });
  await expect.poll(async () => (await state(page)).mode, { timeout: 20000 }).toBe('HOW');
  await snap(page, shotPath('how', shot));
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).mode, { timeout: 20000 }).toBe('TITLE');
  await startPlay(page);
  let s = await state(page);
  expect(s.live).toBe(45);
  expect(s.lives).toBe(3);

  // Walking: a tap sets a direction and the archer keeps walking; S stops.
  await calm(page);
  const x0 = s.ax;
  await page.keyboard.press('d');
  await expect.poll(async () => (await state(page)).dir).toBe(1);
  await expect.poll(async () => (await state(page)).ax, { timeout: 3000 }).toBeGreaterThan(x0 + 12);
  await page.keyboard.press('s');
  await expect.poll(async () => (await state(page)).dir).toBe(0);
  const x1 = (await state(page)).ax;
  await page.waitForTimeout(300);
  expect((await state(page)).ax).toBe(x1);
  await page.keyboard.press('a');
  await expect.poll(async () => (await state(page)).dir).toBe(-1);
  await expect.poll(async () => (await state(page)).ax, { timeout: 3000 }).toBeLessThan(x1 - 12);
  await page.keyboard.press('s');

  // The march steps and the interval is shown by the debug state.
  await page.evaluate(() => { const g = (window as any).OrkpleGames.invadorks._dbg(); g.stepMs = g.ivl; });
  await expect.poll(async () => (await state(page)).steps, { timeout: 3000 }).toBeGreaterThan(0);
  await calm(page);

  // A scene with everything on it: throws in the air, arrows, the mystery knight, a chewed merlon.
  await page.evaluate(() => {
    const g = (window as any).OrkpleGames.invadorks._dbg();
    g.shots = [{ k: 'dg', x: 40, y: 110, fy: 110 }, { k: 'sp', x: 120, y: 100, fy: 100 }, { k: 'sb', x: 200, y: 112, fy: 112 }];
    g.arrows = [{ x: 70, y: 100 }];
    g.mys = { x: 110, dir: 1, f: 0 };
  });
  await snap(page, shotPath('scene', shot));
  await page.evaluate(() => { const g = (window as any).OrkpleGames.invadorks._dbg(); g.shots = []; g.arrows = []; g.mys = null; });

  // Fire and hit: an arrow from under the lowest fighter of a column kills it.
  await alignUnder(page, 0);
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).score, { timeout: 4000 }).toBe(10);
  expect((await state(page)).live).toBe(44);
  await expect.poll(async () => (await state(page)).arrows).toBe(0);

  // The mystery knight is worth a hidden score from a small table.
  await page.evaluate(() => {
    const g = (window as any).OrkpleGames.invadorks._dbg();
    g.fm.al.forEach((r: boolean[]) => { r[8] = false; });
    g.ax = g.fm.x + 8 * 21 + 6 - 5; g.dir = 0;
    g.mys = { x: g.ax - 4, dir: 0, f: 0 };
  });
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).score, { timeout: 4000 }).toBeGreaterThanOrEqual(60);
  await snap(page, shotPath('bonus', shot));

  // A merlon is chewed by a thrown dagger, and by our own arrow.
  const before = (await state(page)).mer;
  await page.evaluate(() => {
    const g = (window as any).OrkpleGames.invadorks._dbg();
    g.shots = [{ k: 'sb', x: g.mer[0].x + 8, y: 120, fy: 120 }];
  });
  await expect.poll(async () => (await state(page)).mer[0], { timeout: 4000 }).toBeLessThan(before[0]);
  const mid = (await state(page)).mer;
  await page.evaluate(() => { const g = (window as any).OrkpleGames.invadorks._dbg(); g.ax = g.mer[1].x + 16 - 5; g.dir = 0; });
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).mer[1], { timeout: 4000 }).toBeLessThan(mid[1]);
  await page.evaluate(() => {
    const g = (window as any).OrkpleGames.invadorks._dbg();
    g.shots = [{ k: 'dg', x: g.mer[2].x + 14, y: 120, fy: 120 }, { k: 'dg', x: g.mer[2].x + 5, y: 130, fy: 130 }, { k: 'sp', x: g.mer[3].x + 17, y: 120, fy: 120 }, { k: 'sb', x: g.mer[3].x + 4, y: 120, fy: 120 }];
  });
  await expect.poll(async () => (await state(page)).shots, { timeout: 4000 }).toBe(0);
  await page.evaluate(() => { const g = (window as any).OrkpleGames.invadorks._dbg(); g.ax = 130; g.dir = 0; g.shotMs = 1e9; });
  await snap(page, shotPath('eroded', shot));

  // A life lost: a dagger straight down on the archer in a gap between merlons.
  await page.evaluate(() => {
    const g = (window as any).OrkpleGames.invadorks._dbg();
    g.ax = 134; g.dir = 0; g.safeMs = 0;
    g.shots = [{ k: 'dg', x: g.ax + 4, y: 140, fy: 140 }];
  });
  await expect.poll(async () => (await state(page)).lives, { timeout: 4000 }).toBe(2);
  await snap(page, shotPath('hit', shot));
  await expect.poll(async () => (await state(page)).alive, { timeout: 6000 }).toBe(true);

  // P holds the game; Q asks first and N keeps playing.
  await page.keyboard.press('p');
  await expect.poll(async () => (await state(page)).mode).toBe('HOLD');
  await page.keyboard.press('p');
  await expect.poll(async () => (await state(page)).mode).toBe('PLAY');
  await page.keyboard.press('q');
  await expect.poll(async () => (await state(page)).mode).toBe('ASK');
  await page.keyboard.press('n');
  await expect.poll(async () => (await state(page)).mode).toBe('PLAY');

  // A wave advance: shoot the last fighter, then the next card.
  await clearWave(page);
  await expect(log(page)).toContainText('WAVE 2  FIGHTERS 45  SPEED X1.17', { timeout: 12000 });
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('PLAY');
  s = await state(page);
  expect(s.wave).toBe(2);
  expect(s.live).toBe(45);
  expect(Math.min(...s.mer)).toBeGreaterThan(300);	// a fresh wall for each wave
  await snap(page, shotPath('wave2', shot));

  // Clear through to the ending.
  await clearWave(page);
  await expect(log(page)).toContainText('WAVE 3  FIGHTERS 45  SPEED X1.37', { timeout: 12000 });
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('PLAY');
  await clearWave(page);
  await expect(log(page)).toContainText('WAVE 4  FIGHTERS 45  SPEED X1.60', { timeout: 12000 });
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('PLAY');
  expect((await state(page)).wave).toBe(4);
  await clearWave(page);
  await expect(log(page)).toContainText('THE CASTLE HOLDS!', { timeout: 12000 });
  await expect.poll(async () => (await state(page)).mode, { timeout: 20000 }).toBe('OVER');
  await snap(page, shotPath('win', shot));
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('TITLE');

  // Leave: Q then Y returns to the menu.
  await page.keyboard.press('Space');
  await expect(log(page)).toContainText('WAVE 1  FIGHTERS 45  SPEED X1.00', { timeout: 10000 });
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).mode, { timeout: 12000 }).toBe('PLAY');
  await page.keyboard.press('q');
  await page.keyboard.press('y');
  await expect(log(page)).toContainText('1. ORKEGON TRAIL', { timeout: 10000 });
  expect(errors).toEqual([]);
}

test.describe('reduced motion', () => {
  test.use({ contextOptions: { reducedMotion: 'reduce' } });
  test('INVADORKS: walk, fire, bonus, erosion, life lost, waves, ending, and Q then Y back to the menu', async ({ page }) => {
    await play(page, process.env.INVADORKS_SHOT || 'test-results/invadorks.png');
  });
});

test.describe('full motion', () => {
  test.use({ contextOptions: { reducedMotion: 'no-preference' } });
  test('INVADORKS: same run with animation paths on', async ({ page }) => {
    await play(page, (process.env.INVADORKS_SHOT || 'test-results/invadorks.png').replace('.png', '-motion.png'));
  });
});

test('INVADORKS: fighters reaching the wall end the game and SPACE retries', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openGame(page);
  await startPlay(page);
  await calm(page);
  await page.evaluate(() => {
    const g = (window as any).OrkpleGames.invadorks._dbg();
    g.fm.y = 100; g.stepMs = 1e9;
  });
  await expect(log(page)).toContainText('THE CASTLE FALLS', { timeout: 8000 });
  await expect.poll(async () => (await state(page)).mode, { timeout: 20000 }).toBe('OVER');
  await snap(page, shotPath('fall', process.env.INVADORKS_SHOT || 'test-results/invadorks.png'));
  await page.keyboard.press('Space');
  await expect(log(page)).toContainText('I N V A D O R K S', { timeout: 8000 });
  await expect.poll(async () => (await state(page)).mode, { timeout: 20000 }).toBe('TITLE');
  expect(errors).toEqual([]);
});

test('INVADORKS: running out of archers ends on the game-over screen', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openGame(page);
  await startPlay(page);
  await calm(page);
  await page.evaluate(() => {
    const g = (window as any).OrkpleGames.invadorks._dbg();
    g.lives = 1; g.ax = 134; g.dir = 0;
    g.shots = [{ k: 'dg', x: g.ax + 4, y: 140, fy: 140 }];
  });
  await expect(log(page)).toContainText('OUT OF ARCHERS', { timeout: 8000 });
  await expect.poll(async () => (await state(page)).mode, { timeout: 20000 }).toBe('OVER');
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('TITLE');
  expect(errors).toEqual([]);
});

const dbg = <T>(page: Page, fn: (g: any) => T) => page.evaluate(`(${fn.toString()})(window.OrkpleGames.invadorks._dbg())`) as Promise<T>;

test('INVADORKS: one arrow at a time, the 23rd arrow takes the feast, the knight faces his way', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openGame(page);
  await startPlay(page);
  await calm(page);
  await alignUnder(page, 0);
  await page.keyboard.press('Space');
  await page.keyboard.press('Space');
  expect(await dbg(page, g => g.arrows.length)).toBeLessThanOrEqual(1);
  expect(await dbg(page, g => g.fired)).toBe(1);
  await expect.poll(async () => (await state(page)).arrows).toBe(0);
  // the 23rd arrow of the wave, at the knight
  const before = (await state(page)).score;
  await page.evaluate(() => {
    const g = (window as any).OrkpleGames.invadorks._dbg();
    g.volley = 22;
    g.fm.al.forEach((r: boolean[]) => { r[8] = false; });
    g.ax = g.fm.x + 8 * 21 + 6 - 5; g.dir = 0;
    g.mys = { x: g.ax - 4, dir: 0, f: 0 };
  });
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).score, { timeout: 4000 }).toBe(before + 300);
  expect(await dbg(page, g => g.msg)).toContain('FEAST');
  // mirrored knight art and the new sprites exist and are the right size
  const art = await page.evaluate(() => {
    const A = (window as any).OrkpleGames.invadorks._pure.ART;
    return { same: A.kn1.every((r: string, i: number) => A.kn1L[i] === r.split('').reverse().join('')), arcH: A.arcE.length, arcW: A.arcEW[0].length, bang: A.bang.length };
  });
  expect(art).toEqual({ same: true, arcH: 12, arcW: 11, bang: 5 });
  expect(errors).toEqual([]);
});

test('INVADORKS: the last fighter gets a call and a pause; an arrow and a hit in one tick is not a lost life', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openGame(page);
  await startPlay(page);
  await calm(page);
  await leave(page, [[4, 4], [4, 0]]);
  await alignUnder(page, 0);
  await page.keyboard.press('Space');
  await expect.poll(async () => await dbg(page, g => g.lastCalled), { timeout: 4000 }).toBe(true);
  expect(await dbg(page, g => g.msg)).toContain('LAST ONE');
  // arrow clears the block and a dagger hits the archer in the same tick: no life is lost, the archer lives
  await page.evaluate(() => {
    const g = (window as any).OrkpleGames.invadorks._dbg();
    g.lives = 1; g.safeMs = 0; g.ax = g.fm.x + 4 * 21 + 6 - 5; g.dir = 0;
    g.shots = [{ k: 'dg', x: g.ax + 4, y: g.ay - 1, fy: g.ay - 1 }];
    g.arrows = [{ x: g.ax + 5, y: g.fm.y + 4 * 13 + 12, n: 1 }];
  });
  await expect.poll(async () => (await state(page)).mode, { timeout: 4000 }).toBe('CLEAR');
  expect(await dbg(page, g => [g.lives, g.deaths, g.alive])).toEqual([1, 0, true]);
  expect(errors).toEqual([]);
});

test('INVADORKS: a death holds the boom for a beat, then says the line', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openGame(page);
  await startPlay(page);
  await calm(page);
  await page.evaluate(() => {
    const g = (window as any).OrkpleGames.invadorks._dbg();
    g.msg = ''; g.msgMs = 0;
    g.ax = 134; g.dir = 0; g.safeMs = 0;
    g.shots = [{ k: 'dg', x: g.ax + 4, y: 140, fy: 140 }];
  });
  await expect.poll(async () => (await state(page)).alive, { timeout: 4000 }).toBe(false);
  expect(await dbg(page, g => g.dieLine)).not.toBe('');
  await expect.poll(async () => await dbg(page, g => g.dieLine), { timeout: 3000 }).toBe('');
  expect(await dbg(page, g => g.msg)).not.toBe('');
  await expect.poll(async () => (await state(page)).alive, { timeout: 6000 }).toBe(true);
  expect(errors).toEqual([]);
});
