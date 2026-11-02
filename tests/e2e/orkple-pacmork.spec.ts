/**
 * PAC-MORK (ORKPLE II game): loads from the ORK GAMES menu, plays in the real terminal, and leaves cleanly.
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
const state = (page: Page) => page.evaluate(() => {
  const g = (window as any).OrkpleGames.pacmork._dbg();
  return { mode: g.mode, score: g.score, c: g.p.c, r: g.p.r, level: g.level };
});

async function openGame(page: Page) {
  await page.goto('./index.php?Route=Search');
  await page.locator('#orkple-floppy').click();
  await expect(log(page)).toContainText('ORKPLE II', { timeout: 10000 });
  await page.keyboard.type('ork');
  await page.keyboard.press('Enter');
  await expect(log(page)).toContainText('ORK GAMES', { timeout: 10000 });
  await page.keyboard.type('4');
  await page.keyboard.press('Enter');
  await expect(log(page)).toContainText('4. PAC-MORK', { timeout: 10000 });
  await page.keyboard.type('4');
  await page.keyboard.press('Enter');
  await expect(log(page)).toContainText('P A C - M O R K', { timeout: 10000 });
}

test('PAC-MORK plays a field: spellballs score, and a stray Q does not end the run', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openGame(page);

  await expect.poll(async () => (await state(page)).mode).toBe('TITLE');
  await page.keyboard.press('Space');
  await expect(log(page)).toContainText('THE PARK', { timeout: 10000 });
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('PLAY');

  await page.keyboard.press('ArrowLeft');
  await expect.poll(async () => (await state(page)).score, { timeout: 5000 }).toBeGreaterThan(0);
  await page.locator('#orkple canvas').first().screenshot({ path: process.env.PACMORK_SHOT || 'test-results/pacmork.png' });

  // Stopped against a wall the wizard stays put: there is no last step to keep gliding through.
  await page.evaluate(() => {
    const g = (window as any).OrkpleGames.pacmork._dbg();
    g.foes.forEach((f: any) => { f.state = 'home'; f.wait = 60000; });
  });
  await expect.poll(() => page.evaluate(() => {
    const p = (window as any).OrkpleGames.pacmork._dbg().p;
    return p.pc === p.c && p.pr === p.r;
  }), { timeout: 8000 }).toBe(true);

  // W A S D work as well as the arrows.
  const before = await state(page);
  await page.keyboard.press('d');
  await expect.poll(async () => (await state(page)).c, { timeout: 3000 }).not.toBe(before.c);

  await page.keyboard.press('q');
  await expect.poll(async () => (await state(page)).mode).toBe('ASK');
  await page.keyboard.press('n');
  await expect.poll(async () => (await state(page)).mode).toBe('PLAY');
  await page.keyboard.press('q');
  await page.keyboard.press('y');
  await expect(log(page)).toContainText('1. ORKEGON TRAIL', { timeout: 10000 });
  expect(errors).toEqual([]);
});

test('PAC-MORK clears all three fields through the between-level scenes to the ending', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openGame(page);
  await expect.poll(async () => (await state(page)).mode).toBe('TITLE');
  await page.keyboard.press('Space');

  for (const name of ['THE PARK', 'THE CASTLE RUINS', 'THE TREE LINE']) {
    await expect(log(page)).toContainText(name, { timeout: 10000 });
    await page.keyboard.press('Space');
    await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('PLAY');
    // Leave one spellball in the player's path and let the game clear the field itself.
    await page.evaluate(() => {
      const g = (window as any).OrkpleGames.pacmork._dbg();
      g.grid = g.grid.map((row: string[]) => row.map(t => (t === '.' || t === '*' ? ' ' : t)));
      g.grid[g.p.r][g.p.c - 1] = '.';
      g.left = 1;
    });
    if (name === 'THE TREE LINE') break;
    await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('SCENE');
    await expect(log(page)).toContainText('SPACE TO TAKE THE FIELD');
    await page.keyboard.press('Space');
  }
  await expect(log(page)).toContainText('YOU SIGNED IN AS A BARBARIAN', { timeout: 15000 });
  expect(errors).toEqual([]);
});

test('PAC-MORK wizard sprite keeps its lit pixels inside one cell and rings them dark', async ({ page }) => {
  await openGame(page);
  const art = await page.evaluate(() => {
    const X = (window as any).OrkpleGames.pacmork._pure;
    return { right: [0, 1, 2].map(f => X.wizardRows('RIGHT', f)), left: [0, 1, 2].map(f => X.wizardRows('LEFT', f)) };
  });
  for (const rows of [...art.right, ...art.left]) {
    expect(rows.length).toBe(10);
    rows.forEach((r: string) => expect(r.length).toBe(9));
    // Lit pixels inside the 7x8 cell (the sprite is drawn at -1,-1), never on the ring's own row/column.
    rows.forEach((r: string, y: number) => r.split('').forEach((ch, x) => {
      if (ch === '#') { expect(x >= 1 && x <= 7 && y >= 1 && y <= 8).toBe(true); }
    }));
    // The hat tip has a dark pixel above and either side of it, so it never fuses with a wall line.
    const tip = rows[1].indexOf('#');
    expect(rows[0][tip]).toBe('o');
    expect(rows[1][tip - 1]).toBe('o');
    expect(rows[1][tip + 1]).toBe('o');
    // Every pixel next to a lit one is lit or dark, nothing else.
    rows.forEach((r: string, y: number) => r.split('').forEach((ch, x) => {
      if (ch !== '.') { return; }
      for (let dy = -1; dy <= 1; dy++) { for (let dx = -1; dx <= 1; dx++) { expect((rows[y + dy] || '')[x + dx] === '#').toBe(false); } }
    }));
  }
  // Left is the mirror of right.
  expect(art.left[2]).toEqual(art.right[2].map((r: string) => r.split('').reverse().join('')));
});

test('PAC-MORK tagging a dagger carrier pauses briefly and shows its points as a sprite; a death sinks the wizard', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openGame(page);
  await expect.poll(async () => (await state(page)).mode).toBe('TITLE');
  await page.keyboard.press('Space');
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('PLAY');

  // Put a frightened Rusher on the cell the wizard is about to step into; record the pop the instant it appears.
  const setup = `(function (scared, who) {
    const O = window.OrkpleGames.pacmork, X = O._pure, g = O._dbg(), p = g.p;
    const dir = X.ORDER.filter(k => { const n = X.step(p.c, p.r, k); return X.open(g.grid, n[0], n[1], false); })[0];
    const n = X.step(p.c, p.r, dir);
    g.foes.forEach((f, i) => { if (i !== who) { f.state = 'home'; f.wait = 60000; } });
    const f = g.foes[who];
    f.state = 'run'; f.scared = scared; f.c = n[0]; f.r = n[1]; f.pc = f.c; f.pr = f.r; f.acc = -100000;
    p.want = dir; p.dir = dir;
    window.__pacSeen = null;
    if (!window.__pacTimer) { window.__pacTimer = setInterval(() => { if (g.pop && !window.__pacSeen) { window.__pacSeen = { s: g.pop.s, x: g.pop.x, stop: g.stopMs }; } }, 5); }
  })`;
  await page.evaluate(`${setup}(true, 0)`);
  await expect.poll(() => page.evaluate(() => (window as any).__pacSeen), { timeout: 3000 }).not.toBeNull();
  const pop = await page.evaluate(() => (window as any).__pacSeen);
  expect(pop.s).toBe('pts0');
  expect(pop.x).toBeGreaterThanOrEqual(0);
  expect(pop.stop).toBeGreaterThan(0);

  // Now a live dagger on the wizard's next cell: the wizard dies, the sinking frames play, a life is lost.
  await page.evaluate(() => { const g = (window as any).OrkpleGames.pacmork._dbg(); g.stopMs = 0; g.pop = null; g.fireMs = 0; g.foes.forEach((f: any) => { f.scared = false; }); });
  const lives = await page.evaluate(() => (window as any).OrkpleGames.pacmork._dbg().lives);
  await page.evaluate(`${setup}(false, 1)`);
  await expect.poll(async () => (await state(page)).mode, { timeout: 3000 }).toBe('DYING');
  expect(await page.evaluate(() => (window as any).OrkpleGames.pacmork._dbg().lives)).toBe(lives - 1);
  await expect.poll(async () => (await state(page)).mode, { timeout: 5000 }).toBe('READY');
  await expect.poll(async () => (await state(page)).mode, { timeout: 5000 }).toBe('PLAY');
  expect(errors).toEqual([]);
});
