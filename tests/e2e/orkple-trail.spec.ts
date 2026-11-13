/**
 * ORKEGON TRAIL (ORKPLE II game): loads from the ORK GAMES menu, shows the title, and Q leaves.
 */
import { test, expect, Page } from '@playwright/test';
import { orkpleSetup, log, bootGame, tick } from './orkple-helpers';

test.use({ contextOptions: { reducedMotion: 'reduce' } });

orkpleSetup();

// The live log keeps only its last 30 lines, so count the ORK GAMES menu only when it is the tail of the log
// (the title now speaks its wording into the log, which pushes the first menu out of the window).
const menuCount = async (page: Page) => (/0\. MAIN MENU\s*$/.test((await log(page).innerText()).trimEnd()) ? 1 : 0);
const dbgState = (page: Page) => page.evaluate(() => { const d = (window as any).OrkpleGames.trail._dbg(); return { armed: d.qArmed(), live: !!d.state(), ready: d.ready() }; });

// The 4 s ORKEK title card comes first; the title and role screens are grid-drawn (not in the text log).
async function openTrail(page: Page) {
  await bootGame(page, 'trail');
  await tick(page, 5500);   // 4 s ORKEK title card, then the title screen (game time, not wall time)
}

// Title -> role (middle budget) -> four names -> the store, waiting for each prompt so no Return is dropped.
async function toStore(page: Page) {
  await page.keyboard.press('1');
  await tick(page, 800);
  await page.keyboard.press('2');
  await expect(log(page)).toContainText('WHO IS IN THE CAR', { timeout: 10000 });
  // The prompt text is grid-drawn, so pace the Returns (in game time): one dropped during output would leave a name unanswered.
  for (let i = 0; i < 4; i++) {
    await tick(page, 900);
    await page.keyboard.press('Enter');
  }
  await expect.poll(async () => (await dbgState(page)).ready, { timeout: 10000 }).toBe(true);
}

test('ORKEGON TRAIL starts from ORK GAMES, reaches the names and store, and Q returns to the menu', async ({ page }) => {
  await openTrail(page);
  await toStore(page);
  const before = await menuCount(page);
  // One Q mid-run only asks; the second Q (within 2 s) leaves.
  await page.keyboard.press('q');
  await tick(page, 300);
  expect((await dbgState(page)).armed).toBe(true);
  expect(await menuCount(page)).toBe(before);
  await page.keyboard.press('q');
  await expect.poll(() => menuCount(page), { timeout: 10000 }).toBe(before + 1);
});


test('ORKEGON TRAIL: a lone Q at the store does not end the run, and the ask times out', async ({ page }) => {
  await openTrail(page);
  await toStore(page);
  const before = await menuCount(page);
  await page.keyboard.press('q');
  await tick(page, 400);
  expect(await dbgState(page)).toEqual({ armed: true, live: true, ready: true });
  await tick(page, 2300);
  expect((await dbgState(page)).armed).toBe(false);
  expect(await menuCount(page)).toBe(before);
  // A later lone Q asks again instead of leaving.
  await page.keyboard.press('q');
  await tick(page, 300);
  expect((await dbgState(page)).armed).toBe(true);
  expect(await menuCount(page)).toBe(before);
});

test('ORKEGON TRAIL: rules and text stay inside the screen', async ({ page }) => {
  await openTrail(page);
  const out = await page.evaluate(() => {
    const T = (window as any).OrkpleGames.trail._test;
    const r: any = {};
    // The Saturday line is 40 columns at most, even at VERY POOR.
    r.satMax = Math.max(...[0, 10, 30, 50, 80].map((h: number) => T.satHead(h).length));
    // Travel cost rows fit 40 columns for every pace, ration, party size and hour.
    let wide = 0, signs = new Set<string>();
    for (let pace = 0; pace < 3; pace++) for (let rat = 0; rat < 3; rat++) for (let alive = 1; alive <= 4; alive++) for (const hours of [0, 15, 16, 17, 20]) {
      const S = T.newGame(1, null); S.pace = pace; S.rat = rat; S.alive = alive; S.hours = hours;
      const t = T.choiceRows(S, []);
      [14, 15, 16].forEach((i: number) => { wide = Math.max(wide, t[i].length); });
      signs.add(t[16]);
    }
    r.wide = wide; r.signs = Array.from(signs);
    const S0 = T.newGame(1, null); S0.pace = 2; S0.rat = 0;
    r.lead = T.choiceRows(S0, [])[14]; r.chip = T.choiceRows(S0, [])[15];
    // Budgets: the middle is exactly 200, and the rows say so.
    r.cash = T.ROLES.map((x: any) => x.cash);
    // Maybe Creek, tape-and-tarp that fails: the tarp always goes.
    const S1 = T.newGame(1, null); S1.tarps = 1; S1.tape = 1;
    const o = T.river(S1, 6, 2, () => 99);
    r.tarpOnFail = { ok: o.ok, lost: o.tarpLost, tarps: S1.tarps };
    // Reeve hits cannot take the bag count below zero.
    const D = T.ditchNew(1); D.ents.push({ l: 0, p: 2, ty: 'R', hp: 1, acc: 0 });
    T.ditchSwing(D, 0);
    r.bags = D.bags;
    // Late arrival: someone says they thought it started at noon.
    const S2 = T.newGame(1, null); S2.hours = 18; S2.cash = 50;
    r.late = T.arrival(S2, () => 0);
    return r;
  });
  expect(out.satMax).toBeLessThanOrEqual(40);
  expect(out.wide).toBeLessThanOrEqual(40);
  expect(out.signs).toEqual(expect.arrayContaining(['SIGN-IN CLOSES IN 16 HOURS', 'SIGN-IN CLOSES IN 1 HOUR', 'SIGN-IN HAS CLOSED']));
  expect(out.lead).toBe('PACE     LEAD FOOT 150 MI, 9 GAL, BRUTAL');
  expect(out.chip).toContain('HUNGRY');
  expect(out.cash).toEqual([250, 200, 175]);
  expect(out.tarpOnFail).toEqual({ ok: false, lost: true, tarps: 0 });
  expect(out.bags).toBe(0);
  expect(out.late).toContain('I THOUGHT IT STARTED AT NOON');
});
