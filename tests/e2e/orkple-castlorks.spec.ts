/**
 * GEM CASTLORKS (ORKPLE II game): loads from the ORK GAMES menu, plays in the real terminal, and leaves cleanly.
 * Set CASTLORKS_SHOT to a directory to keep a canvas screenshot of every castle.
 */
import { test, expect, Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { orkpleSetup, log, bootGame, tick } from './orkple-helpers';

const MOTION = process.env.CASTLORKS_MOTION === '1';
test.use({ contextOptions: { reducedMotion: MOTION ? 'no-preference' : 'reduce' } });

orkpleSetup();

const state = (page: Page) => page.evaluate(() => {
  const g = (window as any).OrkpleGames.castlorks._dbg();
  return { mode: g.mode, score: g.score, x: g.p ? g.p.x : -1, y: g.p ? g.p.y : -1, level: g.level, left: g.left, got: g.got, lives: g.lives, air: !!(g.p && g.p.air) };
});
const shot = async (page: Page, name: string) => {
  const dir = process.env.CASTLORKS_SHOT;
  if (dir) { await page.locator('#orkple canvas').first().screenshot({ path: `${dir}/${name}.png` }); }
};

async function openGame(page: Page) {
  await bootGame(page, 'castlorks', { ready: 'C A S T L O R K S' });
}

// Park the looters and pages so a scripted test is not a fight, whatever the clock does.
const calm = (page: Page) => page.evaluate(() => {
  const g = (window as any).OrkpleGames.castlorks._dbg();
  g.loot.forEach((m: any) => { m.state = 'down'; m.t = 1e9; });
  g.swarmAt = 1e9;
  if (g.boss) { g.boss.stun = 1e9; }
});

test('CASTLORKS: all four keys walk the castle, gems score, SPACE jumps, and a stray Q does not end the run', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openGame(page);

  await expect.poll(async () => (await state(page)).mode).toBe('TITLE');
  await page.keyboard.press('Space');
  await expect(log(page)).toContainText('BRIDGE BATTLE', { timeout: 10000 });
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('PLAY');
  await calm(page);

  // UP walks north-east on the centre island: the first gem is one cell up.
  await page.keyboard.press('ArrowUp');
  await expect.poll(async () => (await state(page)).score, { timeout: 5000 }).toBeGreaterThan(0);
  await shot(page, 'level1-play');

  // Every key moves the player from the middle of the island: UP -y, RIGHT +x, DOWN +y, LEFT -x.
  const moved = async (key: string, axis: 'x' | 'y', sign: number) => {
    await page.evaluate(() => {
      const g = (window as any).OrkpleGames.castlorks._dbg();
      g.p.x = 4; g.p.y = 4; g.p.acc = 0; g.p.dir = 'UP'; g.p.want = 'UP';
    });
    await page.keyboard.press(key);
    await expect.poll(async () => {
      const s: any = await state(page);
      return Math.sign(s[axis] - 4);
    }, { timeout: 3000 }).toBe(sign);
  };
  await moved('d', 'x', 1);
  await moved('s', 'y', 1);
  await moved('a', 'x', -1);
  await moved('w', 'y', -1);
  await moved('ArrowRight', 'x', 1);
  await moved('ArrowDown', 'y', 1);
  await moved('ArrowLeft', 'x', -1);
  await moved('ArrowUp', 'y', -1);
  await page.evaluate(() => {
    const g = (window as any).OrkpleGames.castlorks._dbg();
    g.p.x = 4; g.p.y = 4; g.p.acc = 0; g.p.dir = 'UP'; g.p.want = 'UP';
  });

  // Depth: while the player glides in each of the four diagonals, every frame must draw the player after the cells it
  // stands on or is crossing (the deeper of origin and destination), so a tile never slides over the sprite.
  // Heading north (UP, LEFT) the destination cell is shallower than the one just left: that is the case that broke.
  for (const dir of ['LEFT', 'UP', 'RIGHT', 'DOWN']) {
    const res = await page.evaluate(async (d) => {
      const g = (window as any).OrkpleGames.castlorks._dbg();
      g.p.x = 4; g.p.y = 4; g.p.fx = 4; g.p.fy = 4; g.p.acc = 0; g.p.dir = d; g.p.want = d;
      const bad: string[] = []; let frames = 0, glide = 0;
      await new Promise<void>(done => {
        const t0 = performance.now();
        const id = setInterval(() => {
          const f = g.frame, p = g.p;
          if (f && !p.air && g.frameP === [p.x, p.y, p.fx, p.fy].join(',')) {
            const i = f.findIndex((e: any) => /^p(UP|DOWN|LEFT|RIGHT)\d\d$/.test(e.s) && e.k !== 99);
            const depth = Math.max(p.x + p.y, p.fx + p.fy);
            if (i >= 0) {
              frames++;
              if (p.fx !== p.x || p.fy !== p.y) { glide++; }
              if (f[i].k < depth) { bad.push('key ' + f[i].k + ' < ' + depth); }
              f.forEach((e: any, j: number) => { if (j > i && e.k !== 99 && e.k <= depth) { bad.push(e.s + ' k' + e.k + ' drawn after player depth ' + depth); } });
            }
          }
          if (performance.now() - t0 > 1400) { clearInterval(id); done(); }
        }, 16);
      });
      return { bad: bad.slice(0, 3), frames, glide };
    }, dir);
    expect(res.frames).toBeGreaterThan(20);
    expect(res.glide).toBeGreaterThan(5);
    expect(res.bad).toEqual([]);
    await page.evaluate(() => { const g = (window as any).OrkpleGames.castlorks._dbg(); g.p.x = 4; g.p.y = 4; g.p.fx = 4; g.p.fy = 4; g.p.acc = 0; });
  }

  // SPACE jumps: airborne for a moment, then lands.
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).air, { timeout: 1000 }).toBe(true);
  await shot(page, 'level1-jump');
  await expect.poll(async () => (await state(page)).air, { timeout: 2000 }).toBe(false);

  // Looters, pages and the helm power-up all draw as sprites.
  await page.evaluate(() => {
    const g = (window as any).OrkpleGames.castlorks._dbg();
    g.p.power = 9000;
    g.swarmAt = 0;
    g.loot.forEach((m: any) => { m.state = 'walk'; m.acc = 0; });
    g.p.x = 4; g.p.y = 4; g.p.fx = 4; g.p.fy = 4;
  });
  await tick(page, 1800);
  await shot(page, 'level1-power');

  // P holds.
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
});

test('CASTLORKS clears all five castles to the ending, then SPACE returns to the title', async ({ page }) => {
  test.setTimeout(120000);
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openGame(page);
  await expect.poll(async () => (await state(page)).mode).toBe('TITLE');
  await page.keyboard.press('Space');

  const names = ['BRIDGE BATTLE', 'CAPTURE THE FLAG', 'GHOST MODE', 'THE TWIN KEEPS', 'THE KING\'S KEEP'];
  for (let i = 0; i < names.length; i++) {
    await expect(log(page)).toContainText(names[i], { timeout: 10000 });
    await page.keyboard.press('Space');
    await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('PLAY');
    await page.evaluate(() => { (window as any).OrkpleGames.castlorks._dbg().lives = 9; });
    await tick(page, MOTION ? (i === 4 ? 7000 : 3500) : 600);
    await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('PLAY');   // let looters, pages and the boss come out in the real-motion run
    await shot(page, `level${i + 1}`);
    await calm(page);
    // Leave one gem next to the player, face it, and let the game clear the castle itself.
    await page.evaluate(() => {
      const g = (window as any).OrkpleGames.castlorks._dbg();
      const D: any = { UP: [0, -1], RIGHT: [1, 0], DOWN: [0, 1], LEFT: [-1, 0] };
      for (const row of g.tr) { row.fill(false); }
      for (const k of Object.keys(D)) {
        const x = g.p.x + D[k][0], y = g.p.y + D[k][1];
        if (g.z[y] && g.z[y][x] >= 0 && Math.abs(g.z[y][x] - g.z[g.p.y][g.p.x]) <= 1) {
          g.tr[y][x] = true; g.left = 1; g.p.want = k; g.p.dir = k;
          break;
        }
      }
    });
    await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('CLEAR');
    await shot(page, `level${i + 1}-clear`);
  }
  await expect(log(page)).toContainText('BOUNDARY ROCKS', { timeout: 20000 });
  await tick(page, 1500);
  await shot(page, 'win');
  await expect.poll(async () => (await state(page)).mode, { timeout: 20000 }).toBe('OVER');
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).mode).toBe('TITLE');
  expect(errors).toEqual([]);
});

test('CASTLORKS: losing every life ends in the game over screen', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openGame(page);
  await expect.poll(async () => (await state(page)).mode).toBe('TITLE');
  await page.keyboard.press('Space');
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('PLAY');
  for (let life = 0; life < 3; life++) {
    // Put a walking looter on the player.
    await page.evaluate(() => {
      const g = (window as any).OrkpleGames.castlorks._dbg();
      const m = g.loot[0], d: any = { UP: [0, -1], RIGHT: [1, 0], DOWN: [0, 1], LEFT: [-1, 0] };
      let k = 'UP';
      for (const n of Object.keys(d)) { const x = g.p.x + d[n][0], y = g.p.y + d[n][1]; if (g.z[y] && g.z[y][x] >= 0) { k = n; break; } }
      g.p.want = k; g.p.dir = k;
      m.state = 'walk'; m.acc = -1e9; m.x = g.p.x + d[k][0]; m.y = g.p.y + d[k][1];
    });
    await expect.poll(async () => (await state(page)).lives, { timeout: 3000 }).toBe(2 - life);
    if (life < 2) { await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('PLAY'); }
  }
  await expect(log(page)).toContainText('OUT OF LIVES', { timeout: 10000 });
  await shot(page, 'gameover');
  await expect.poll(async () => (await state(page)).mode).toBe('OVER');
  expect(errors).toEqual([]);
});

test('CASTLORKS: the clear bonus counts up into the score, SPACE pays it at once, and every message fits the row', async ({ page }) => {
  test.setTimeout(60000);
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openGame(page);
  await expect.poll(async () => (await state(page)).mode).toBe('TITLE');
  await page.keyboard.press('Space');
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('PLAY');
  await calm(page);
  // Leave one gem next to the player and let the game clear the castle itself.
  await page.evaluate(() => {
    const g = (window as any).OrkpleGames.castlorks._dbg();
    const D: any = { UP: [0, -1], RIGHT: [1, 0], DOWN: [0, 1], LEFT: [-1, 0] };
    for (const row of g.tr) { row.fill(false); }
    for (const k of Object.keys(D)) {
      const x = g.p.x + D[k][0], y = g.p.y + D[k][1];
      if (g.z[y] && g.z[y][x] >= 0 && Math.abs(g.z[y][x] - g.z[g.p.y][g.p.x]) <= 1) { g.tr[y][x] = true; g.left = 1; g.p.want = k; g.p.dir = k; break; }
    }
  });
  await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('CLEAR');
  const t0: any = await page.evaluate(() => { const g = (window as any).OrkpleGames.castlorks._dbg(); return { score: g.score, rem: g.tally.rem, msg: g.msg }; });
  expect(t0.rem).toBeGreaterThan(0);
  expect(t0.msg.length).toBeLessThanOrEqual(40);
  expect(t0.msg).toContain('LAST GEM!');
  // It counts: partway through the count the score has risen but not yet by the full bonus.
  await expect.poll(async () => { const s = await state(page); return s.score > t0.score && s.score < t0.score + t0.rem; }, { timeout: 5000 }).toBe(true);
  await page.keyboard.press('Space');
  const t1: any = await page.evaluate(() => { const g = (window as any).OrkpleGames.castlorks._dbg(); return { score: g.score, done: g.tally.done, mode: g.mode }; });
  expect(t1.done).toBe(true);
  expect(t1.mode).toBe('CLEAR');
  expect(t1.score).toBe(t0.score + t0.rem);
  await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('CARD');
  expect(errors).toEqual([]);
});

test('CASTLORKS: the level 1 secret sleeps until 24 gems are gone, then wakes and pays a flat 1000', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openGame(page);
  await expect.poll(async () => (await state(page)).mode).toBe('TITLE');
  await page.keyboard.press('Space');
  await page.keyboard.press('Space');
  await expect.poll(async () => (await state(page)).mode, { timeout: 8000 }).toBe('PLAY');
  await calm(page);
  const land = async (got: number) => {
    await page.evaluate((n) => {
      const g = (window as any).OrkpleGames.castlorks._dbg();
      g.got = n; g.p.x = 6; g.p.y = 1; g.p.fx = 6; g.p.fy = 1; g.p.want = 'DOWN'; g.p.dir = 'DOWN'; g.p.acc = 0;
    }, got);
    await page.keyboard.press('ArrowDown');
  };
  // Dormant: stepping onto the tile with 10 gems taken does nothing.
  await land(10);
  await expect.poll(async () => { const s = await state(page); return s.x + ',' + s.y; }, { timeout: 3000 }).toBe('6,2');
  expect((await state(page)).mode).toBe('PLAY');
  expect(await page.evaluate(() => !!(window as any).OrkpleGames.castlorks._dbg().warpOn)).toBe(false);
  // Awake: the tile hums once, and the landing warps for exactly 1000.
  await page.evaluate(() => { const g = (window as any).OrkpleGames.castlorks._dbg(); g.got = 24; });
  await expect.poll(async () => page.evaluate(() => (window as any).OrkpleGames.castlorks._dbg().warpOn), { timeout: 3000 }).toBe(true);
  const s0 = (await state(page)).score;
  await page.evaluate(() => { const g = (window as any).OrkpleGames.castlorks._dbg(); g.p.x = 6; g.p.y = 1; g.p.fx = 6; g.p.fy = 1; g.p.want = 'DOWN'; g.p.dir = 'DOWN'; g.p.acc = 0; });
  await page.keyboard.press('ArrowDown');
  await expect.poll(async () => (await state(page)).mode, { timeout: 3000 }).toBe('CLEAR');
  const s1 = await page.evaluate(() => { const g = (window as any).OrkpleGames.castlorks._dbg(); return { score: g.score, skip: g.skip, rem: g.tally.rem }; });
  expect(s1.skip).toBe(true);
  expect(s1.rem).toBe(0);
  expect(s1.score - s0).toBe(1000);
  expect(errors).toEqual([]);
});

test('CASTLORKS: the how-to-play screen is true, and no message overruns the 40 column message row', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(e.message));
  await openGame(page);
  await expect.poll(async () => (await state(page)).mode).toBe('TITLE');
  await tick(page, 700);
  await shot(page, 'title');
  await page.keyboard.press('h');
  await expect(log(page)).toContainText('SPACE JUMPS TWO CELLS', { timeout: 10000 });
  await tick(page, 500);
  await shot(page, 'help1');
  await page.keyboard.press('Enter');
  await tick(page, 700);
  await shot(page, 'help2');
  await expect(log(page)).toContainText('LOOTERS CAN TAG A LOW JUMP.');
  expect(errors).toEqual([]);
  // Every literal message the game puts on the row (flash, G.msg, the looter lines) is 40 characters or fewer.
  const src = fs.readFileSync(path.resolve(__dirname, '../../orkui/template/revised-frontend/orkple/games/castlorks.js'), 'utf8');
  const lits: string[] = [];
  for (const m of src.matchAll(/(?:flash\(|G\.msg = |tally\.text = )'((?:[^'\\]|\\.)*)'/g)) { lits.push(m[1]); }
  const hit = /var LOOT_HIT = \[(.*)\];/.exec(src);
  if (hit) { for (const m of hit[1].matchAll(/'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)"/g)) { lits.push(m[1] || m[2]); } }
  expect(lits.length).toBeGreaterThan(15);
  const long = lits.filter(l => l.replace(/\\./g, 'x').length > 40);
  expect(long).toEqual([]);
  // The compass label must stay clear of the tile at (0,4): RIGHT is drawn at column 5.
  expect(src).toContain("BT(5, 6, 'RIGHT', false)");
});
