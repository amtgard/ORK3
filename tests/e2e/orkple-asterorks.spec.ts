/**
 * ASTERORKS (ORKPLE II game): loads from the ORK GAMES menu, plays in the real terminal, and leaves cleanly.
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
  const g = (window as any).OrkpleGames.asterorks._dbg();
  return {
    mode: g.mode, score: g.score, round: g.round, lives: g.lives, arrows: g.arrows.length,
    groups: g.zombies.length, live: g.zombies.reduce((a: number, z: any) => a + z.n, 0), alive: g.p.alive, safe: g.p.safeMs,
  };
});

async function openGame(page: Page) {
  await page.goto('./index.php?Route=Search');
  await page.locator('#orkple-floppy').click({ force: true });
  await expect(log(page)).toContainText('ORKPLE II', { timeout: 10000 });
  await page.keyboard.type('ork');
  await page.keyboard.press('Enter');
  await expect(log(page)).toContainText('ORK GAMES', { timeout: 10000 });
  await page.keyboard.type('4');
  await page.keyboard.press('Enter');
  await expect(log(page)).toContainText('5. ASTERORKS', { timeout: 10000 });
  await page.keyboard.type('5');
  await page.keyboard.press('Enter');
  await expect(log(page)).toContainText('A S T E R O R K S', { timeout: 10000 });
}

// Start round 1 and wait for live play, then replace the horde with a controlled one, east of the archer.
async function startPlay(page: Page) {
  await expect.poll(async () => (await state(page)).mode).toBe('TITLE');
  await page.keyboard.press('Space');
  await expect(log(page)).toContainText('ROUND 1  ZOMBIES 12  SPEED X1.00', { timeout: 10000 });
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('PLAY');
}
const plant = (page: Page, zs: Array<{ n: number; dx: number }>) => page.evaluate((list) => {
  const g = (window as any).OrkpleGames.asterorks._dbg();
  g.necroMs = 1e9;
  g.p.safeMs = 0;
  g.zombies = list.map(z => ({ n: z.n, x: g.p.x + z.dx, y: g.p.y, vx: 0, vy: 0 }));
}, zs);

async function play(page: Page, shot: string) {
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openGame(page);
  await startPlay(page);
  expect((await state(page)).live).toBe(12);

  // Fire and hit: a large cluster dead ahead breaks into two pairs.
  await plant(page, [{ n: 4, dx: 60 }, { n: 2, dx: 90 }, { n: 1, dx: -70 }]);
  await page.evaluate(() => {
    const g = (window as any).OrkpleGames.asterorks._dbg();
    g.zombies[1].y += 45; g.zombies[2].y -= 38;
    g.necro = { dir: 1, x: 100, y: 25, bolt: 1e9 };
    g.bolts = [{ x: 180, y: 40, vx: 0, vy: 0, ms: 5000 }];
    g.arrows = [{ x: 175, y: 88, vx: 0, vy: 0, face: 1, left: 1e9 }, { x: 150, y: 60, vx: 0, vy: 0, face: 3, left: 1e9 }];
  });
  await page.locator('#orkple canvas').first().screenshot({ path: shot.replace('.png', '-scene.png') });
  await page.evaluate(() => { const g = (window as any).OrkpleGames.asterorks._dbg(); g.arrows = []; g.necro = null; g.bolts = []; g.zombies = g.zombies.slice(0, 1); });
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).score, { timeout: 4000 }).toBe(20);
  let s = await state(page);
  expect(s.groups).toBe(2);
  expect(s.live).toBe(4);
  await page.locator('#orkple canvas').first().screenshot({ path: shot });

  // A pair breaks into singles: the number of live groups goes up with every split.
  await plant(page, [{ n: 2, dx: 60 }, { n: 4, dx: 110 }]);
  await page.evaluate(() => { (window as any).OrkpleGames.asterorks._dbg().zombies.forEach((z: any, i: number) => { z.y += i ? 40 : 0; }); });
  const before = (await state(page)).groups;
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).groups, { timeout: 4000 }).toBeGreaterThan(before);
  expect((await state(page)).live).toBe(6);	// a split never removes a zombie: 2 + 4 stays 6

  // Turning and running work from taps.
  const pose = () => page.evaluate(() => { const g = (window as any).OrkpleGames.asterorks._dbg(); return { x: g.p.x, y: g.p.y, face: g.p.face }; });
  const p0 = await pose();
  await page.keyboard.press('a');
  await expect.poll(async () => (await pose()).face).toBe((p0.face + 7) % 8);
  await page.keyboard.press('d');
  await page.keyboard.press('w');
  await expect.poll(async () => (await pose()).x, { timeout: 3000 }).not.toBe(p0.x);
  await page.keyboard.press('s');

  // A round advance: shoot the last single and the card for round 2 follows.
  await page.evaluate(() => { const g = (window as any).OrkpleGames.asterorks._dbg(); g.p.x = 140; g.p.y = 88; g.p.vx = 0; g.p.vy = 0; g.p.face = 2; });
  await plant(page, [{ n: 1, dx: 60 }]);
  await page.keyboard.press('Space');
  await expect(log(page)).toContainText('ROUND 2  ZOMBIES 13  SPEED X1.10', { timeout: 12000 });
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('PLAY');
  expect((await state(page)).live).toBe(13);

  // A death: a zombie on the archer costs a life, and the archer comes back.
  await page.evaluate(() => {
    const g = (window as any).OrkpleGames.asterorks._dbg();
    g.necroMs = 1e9; g.p.safeMs = 0;
    g.zombies = [{ n: 1, x: g.p.x, y: g.p.y, vx: 0, vy: 0 }, { n: 1, x: 20, y: 20, vx: 0, vy: 0 }];
  });
  await expect.poll(async () => (await state(page)).lives, { timeout: 3000 }).toBe(2);
  await expect.poll(async () => (await state(page)).alive, { timeout: 8000 }).toBe(true);

  // P holds the game; Q asks first and N keeps playing.
  await page.keyboard.press('p');
  await expect.poll(async () => (await state(page)).mode).toBe('HOLD');
  await page.keyboard.press('p');
  await expect.poll(async () => (await state(page)).mode).toBe('PLAY');
  await page.keyboard.press('q');
  await expect.poll(async () => (await state(page)).mode).toBe('ASK');
  await page.keyboard.press('n');
  await expect.poll(async () => (await state(page)).mode).toBe('PLAY');
  await page.keyboard.press('q');
  await page.keyboard.press('y');
  await expect(log(page)).toContainText('1. ORKEGON TRAIL', { timeout: 10000 });
  expect(errors).toEqual([]);
}

test.describe('reduced motion', () => {
  test.use({ contextOptions: { reducedMotion: 'reduce' } });
  test('ASTERORKS: fire, split, round advance, death, and Q then Y back to the menu', async ({ page }) => {
    await play(page, process.env.ASTERORKS_SHOT || 'test-results/asterorks.png');
  });
});

test.describe('full motion', () => {
  test.use({ contextOptions: { reducedMotion: 'no-preference' } });
  test('ASTERORKS: same run with animation paths on', async ({ page }) => {
    await play(page, process.env.ASTERORKS_SHOT_MOTION || 'test-results/asterorks-motion.png');
  });
});

test('ASTERORKS: running out of lives ends on the game-over screen and SPACE retries', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openGame(page);
  await startPlay(page);
  await page.evaluate(() => {
    const g = (window as any).OrkpleGames.asterorks._dbg();
    g.lives = 1; g.necroMs = 1e9; g.p.safeMs = 0;
    g.zombies = [{ n: 1, x: g.p.x, y: g.p.y, vx: 0, vy: 0 }, { n: 1, x: 20, y: 20, vx: 0, vy: 0 }];
  });
  await expect(log(page)).toContainText('OUT OF LIVES', { timeout: 8000 });
  await expect.poll(async () => (await state(page)).mode).toBe('OVER');
  await page.keyboard.press('Space');
  await expect(log(page)).toContainText('A S T E R O R K S', { timeout: 8000 });
  await expect.poll(async () => (await state(page)).mode).toBe('TITLE');
  expect(errors).toEqual([]);
});

test('ASTERORKS: the Hunger clock steers the horde without changing its speed, and pairs turn to their heading', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openGame(page);
  await startPlay(page);
  const r = await page.evaluate(() => {
    const g = (window as any).OrkpleGames.asterorks._dbg();
    g.necroMs = 1e9; g.p.safeMs = 1e9; g.p.vx = 0; g.p.vy = 0;
    // a pair flying along a diagonal, and a single flying away from the archer
    g.zombies = [{ n: 2, o: 0, x: g.p.x + 60, y: g.p.y - 60, vx: 20, vy: 20 }, { n: 1, o: 0, x: g.p.x + 60, y: g.p.y + 10, vx: 30, vy: 0 }];
    g.clock = 95000;
    return new Promise<any>(resolve => setTimeout(() => {
      const z = g.zombies;
      resolve({ stage: g.stage, o: z[0].o, sp0: Math.hypot(z[0].vx, z[0].vy), sp1: Math.hypot(z[1].vx, z[1].vy), vy1: z[1].vy });
    }, 400));
  });
  expect(r.stage).toBe(1);
  expect(r.o).toBe(1);
  expect(r.sp0).toBeCloseTo(Math.hypot(20, 20), 6);
  expect(r.sp1).toBeCloseTo(30, 6);
  expect(r.vy1).not.toBe(0);	// the single bent toward the archer
  await expect.poll(() => page.evaluate(() => (window as any).OrkpleGames.asterorks._dbg().msg), { timeout: 6000 }).toBe('THE DEAD HAVE CAUGHT YOUR SCENT.');
  expect(errors).toEqual([]);
});

test('ASTERORKS: clearing round 5 wins, pays for spare lives, and records FIELD HELD', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openGame(page);
  await startPlay(page);
  await page.evaluate(() => { const g = (window as any).OrkpleGames.asterorks._dbg(); g.round = 5; g.score = 0; g.lives = 3; });
  await plant(page, [{ n: 1, dx: 60 }]);
  await page.keyboard.press('Space');
  await expect(log(page)).toContainText('THE FIELD IS HELD', { timeout: 10000 });
  await expect.poll(async () => (await state(page)).mode).toBe('OVER');
  const s = await page.evaluate(() => { const g = (window as any).OrkpleGames.asterorks._dbg(); return { won: g.won, score: g.score, best: g.best }; });
  expect(s.won).toBe(true);
  expect(s.score).toBe(100 + 3000);
  expect(s.best.won).toBe(true);
  await page.keyboard.press('Space');
  await expect(log(page)).toContainText('FIELD HELD', { timeout: 8000 });
  expect(errors).toEqual([]);
});

test('ASTERORKS: pure helpers (round scaling, pair orientation, arrow rotation)', async ({ page }) => {
  await page.goto('./index.php?Route=Search');
  await page.locator('#orkple-floppy').click({ force: true });
  await expect(log(page)).toContainText('ORKPLE II', { timeout: 10000 });
  await page.keyboard.type('ork');
  await page.keyboard.press('Enter');
  await expect(log(page)).toContainText('ORK GAMES', { timeout: 10000 });
  await page.keyboard.type('4');
  await page.keyboard.press('Enter');
  await page.keyboard.type('5');
  await page.keyboard.press('Enter');
  await expect(log(page)).toContainText('A S T E R O R K S', { timeout: 10000 });
  const r = await page.evaluate(() => {
    const X = (window as any).OrkpleGames.asterorks._pure;
    return {
      counts: [1, 2, 3, 4, 5].map(X.countFor),
      speeds: [1, 2, 3].map((n: number) => X.speedFor(n) / X.speedFor(1)),
      orient: [[1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0], [0, -1]].map(v => X.orientOf(v[0], v[1])),
      vert: X.circlesOf({ n: 2, x: 10, y: 10, o: 2 }),
      diag: X.circlesOf({ n: 2, x: 10, y: 10, o: 3 }),
      stages: [0, 89999, 90000, 330000].map(X.stageFor),
      rot: X.rot90(['ab', 'cd', 'ef']),
    };
  });
  expect(r.counts).toEqual([12, 13, 15, 16, 18]);
  expect(r.speeds[1]).toBeCloseTo(1.1, 9);
  expect(r.speeds[2]).toBeCloseTo(1.21, 9);
  expect(r.orient).toEqual([0, 1, 2, 3, 0, 2]);
  expect(r.vert).toEqual([[10, 5.5], [10, 14.5]]);
  expect(r.diag).toEqual([[13, 7], [7, 13]]);
  expect(r.stages).toEqual([0, 0, 1, 5]);
  expect(r.rot).toEqual(['eca', 'fdb']);
});

test('ASTERORKS: all four pair headings draw, including at the edges', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openGame(page);
  await startPlay(page);
  await page.evaluate(() => {
    const g = (window as any).OrkpleGames.asterorks._dbg();
    g.necroMs = 1e9; g.p.safeMs = 1e9;
    const pts = [[40, 12, 0], [110, 8, 1], [180, 10, 2], [250, 12, 3], [40, 166, 1], [110, 170, 3], [180, 164, 2], [262, 160, 0], [8, 88, 1], [272, 88, 3]];
    g.zombies = pts.map(p => ({ n: 2, o: p[2], x: p[0], y: p[1], vx: 0, vy: 0 }));
    g.zombies.push({ n: 4, o: 0, x: 140, y: 40, vx: 0, vy: 0 }, { n: 1, o: 0, x: 90, y: 130, vx: 0, vy: 0 });
    g.arrows = [0, 1, 2, 3, 4, 5, 6, 7].map(f => ({ x: 20 + f * 30, y: 120, vx: 0, vy: 0, face: f, left: 1e9 }));
  });
  await page.waitForTimeout(300);
  await page.locator('#orkple canvas').first().screenshot({ path: process.env.ASTERORKS_SHOT_PAIRS || 'test-results/asterorks-pairs.png' });
  expect(errors).toEqual([]);
});

test('ASTERORKS: main screen screenshot', async ({ page }) => {
  await openGame(page);
  await startPlay(page);
  await page.waitForTimeout(1500);
  await page.locator('#orkple canvas').first().screenshot({ path: process.env.ASTERORKS_SHOT_MAIN || 'test-results/asterorks-main.png' });
});

test('ASTERORKS: graphics screenshots (set ASTERORKS_GFX to a directory)', async ({ page }) => {
  const dir = process.env.ASTERORKS_GFX;
  test.skip(!dir, 'set ASTERORKS_GFX to write screenshots');
  const cv = () => page.locator('#orkple canvas').first();
  await openGame(page);
  await expect.poll(async () => (await state(page)).mode).toBe('TITLE');
  await page.waitForTimeout(600);
  await cv().screenshot({ path: `${dir}/asterorks-title.png` });
  await page.keyboard.press('h');
  await expect.poll(async () => (await state(page)).mode).toBe('HOW');
  await page.waitForTimeout(400);
  await cv().screenshot({ path: `${dir}/asterorks-help1.png` });
  await page.keyboard.press('Space');
  await page.waitForTimeout(400);
  await cv().screenshot({ path: `${dir}/asterorks-help2.png` });
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).mode).toBe('TITLE');
  await page.keyboard.press('Space');
  await expect(log(page)).toContainText('ROUND 1  ZOMBIES 12', { timeout: 10000 });
  await page.waitForTimeout(500);
  await cv().screenshot({ path: `${dir}/asterorks-card.png` });
  await page.keyboard.press('Space');
  await page.waitForTimeout(300);
  await cv().screenshot({ path: `${dir}/asterorks-ready.png` });
  await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('PLAY');
  await page.waitForTimeout(1500);
  await cv().screenshot({ path: `${dir}/asterorks-play.png` });
  await plant(page, [{ n: 4, dx: 60 }, { n: 2, dx: 90 }, { n: 1, dx: -70 }]);
  await page.evaluate(() => {
    const g = (window as any).OrkpleGames.asterorks._dbg();
    g.zombies[1].y += 45; g.zombies[2].y -= 38;
    g.necro = { dir: 1, x: 100, y: 25, bolt: 1e9 };
    g.arrows = [{ x: 175, y: 88, vx: 0, vy: 0, face: 1, left: 1e9 }];
  });
  await page.waitForTimeout(300);
  await cv().screenshot({ path: `${dir}/asterorks-scene.png` });
  await page.evaluate(() => {
    const g = (window as any).OrkpleGames.asterorks._dbg();
    g.lives = 1; g.necroMs = 1e9; g.p.safeMs = 0; g.necro = null; g.arrows = [];
    g.zombies = [{ n: 1, x: g.p.x, y: g.p.y, vx: 0, vy: 0 }, { n: 1, x: 20, y: 20, vx: 0, vy: 0 }];
  });
  await expect(log(page)).toContainText('OUT OF LIVES', { timeout: 8000 });
  await page.waitForTimeout(800);
  await cv().screenshot({ path: `${dir}/asterorks-lose.png` });
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).mode).toBe('TITLE');
  await startPlay(page);
  await page.evaluate(() => { const g = (window as any).OrkpleGames.asterorks._dbg(); g.round = 5; g.score = 0; g.lives = 3; });
  await plant(page, [{ n: 1, dx: 60 }]);
  await page.keyboard.press('Space');
  await expect(log(page)).toContainText('THE FIELD IS HELD', { timeout: 10000 });
  await page.waitForTimeout(800);
  await cv().screenshot({ path: `${dir}/asterorks-win.png` });
});
