/**
 * ORKPLE II game "NUMBER MUNCHORKS": logic and drawing checks against a mock host (no browser needed),
 * covering enemy labels, the hold timing and double-bite guard, the Bard's change marker, bite scoring pops,
 * the added level 5 boards, the NEW BEST line on a loss, and a random-input smoke run.
 */
import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import * as vm from 'vm';

const SRC = path.join(__dirname, '../../orkui/template/revised-frontend/orkple/games/munchorks.js');
const FONT = '@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_ !"#$%&\'()*+,-./0123456789:;<=>?z';

function load(opts: { reduced?: boolean; seed?: number; best?: any } = {}) {
  const win: any = {};
  vm.runInNewContext(fs.readFileSync(SRC, 'utf8'), { window: win });
  const game = win.OrkpleGames.munchorks;
  let rs = opts.seed ?? 1234, tick: any = null, key: any = null;
  const screen: string[][] = Array.from({ length: 24 }, () => Array(40).fill(' '));
  const bad: string[] = [];
  const saved: any = {};
  const beeps: number[][] = [];
  let sprites: any[] = [];
  const put = (col: number, row: number, s: string) => {
    if (row < 0 || row > 23 || col < 0 || col + s.length > 40) bad.push(`OFFGRID ${col},${row} ${s}`);
    for (const ch of s) if (!FONT.includes(ch)) bad.push(`GLYPH ${ch} in ${s}`);
    for (let i = 0; i < s.length; i++) if (col + i < 40 && row < 24 && row >= 0) screen[row][col + i] = s[i];
  };
  const H: any = {
    reduced: !!opts.reduced, defSprite() {}, sprites: (l: any[]) => { sprites = l; }, cls() { for (const r of screen) r.fill(' '); sprites = []; },
    print() {}, line() {}, blank() {}, wait() {}, beep: (f: number, m: number) => { beeps.push([f, m]); },
    then: (f: any) => f(), prompt() {}, keys: (f: any) => { key = f; }, keysOff() {},
    put: (c: number, r: number, ch: string) => put(c, r, ch),
    text: (c: number, r: number, s: string) => put(c, r, s),
    fill: (c: number, r: number, w: number, h: number, ch: string) => { for (let y = 0; y < h; y++) put(c, r + y, ch.repeat(w)); },
    every: (_ms: number, f: any) => { tick = f; return 1; }, after() {}, cancel() {}, quit() {},
    save: (k: string, v: any) => { saved[k] = v; }, load: (k: string) => (k === 'best' ? opts.best ?? null : null),
    rand: (n: number) => { rs = (rs * 1103515245 + 12345) & 0x7fffffff; return (rs >> 8) % n; }, seed() {}
  };
  game.start(H);
  const G = game._dbg();
  return {
    game, G, bad, beeps, saved, X: game._t,
    key: (k: string) => key(k),
    tick: (n = 1) => { for (let i = 0; i < n; i++) tick(); },
    row: (r: number) => screen[r].join(''),
    sprites: () => sprites
  };
}

// Start a game and get through the rule card into level play.
function intoPlay(t: ReturnType<typeof load>) {
  t.key('SPACE');
  t.tick(11);
  t.key('ENTER');
  expect(t.G.mode).toBe('PLAY');
}
function cellRows(t: ReturnType<typeof load>, c: number, r: number) {
  return [0, 1, 2].map((k) => t.row(3 + 4 * r + k).slice(8 * c, 8 * c + 7));
}
function firstWrong(G: any) { return G.tiles.findIndex((x: any) => !x.ok && !x.blank); }
function firstTarget(G: any) { return G.tiles.findIndex((x: any) => x.ok && !x.blank); }

test('a live enemy tile shows its name on the third row', () => {
  const t = load();
  intoPlay(t);
  const e = t.G.enemies[0];
  e.state = 'live'; e.c = 0; e.r = 0;
  t.G.dirty = { 0: true };
  t.tick(1);
  expect(cellRows(t, 0, 0)[2]).toBe(' REEVE ');
  expect(t.G.cellSpr[0].some((s: any) => s.s === 'REEVEN')).toBe(true);
});

test('a warn tile shows the name only when it has no second line', () => {
  const t = load();
  intoPlay(t);
  const e = t.G.enemies[0];
  e.state = 'warn'; e.wc = 4; e.wr = 4; e.wms = 1500;
  const idx = 24;
  t.G.tiles[idx] = { a: 'AB', b: '', ok: false };
  t.G.dirty = { 24: true }; t.tick(1);
  expect(cellRows(t, 4, 4)[2]).toBe(' REEVE ');
  t.G.tiles[idx] = { a: 'AB', b: 'CD', ok: false };
  t.G.dirty = { 24: true }; t.tick(1);
  expect(cellRows(t, 4, 4)[2].trim()).toBe('CD');
});

test('a wrong bite holds 2.6 s; SPACE skips only after 0.8 s; the next bite is locked briefly', () => {
  const t = load();
  intoPlay(t);
  const i = firstWrong(t.G);
  t.G.pc = i % 5; t.G.pr = Math.floor(i / 5);
  const lives = t.G.lives;
  t.key('SPACE');
  expect(t.G.mode).toBe('HOLD');
  expect(t.G.lives).toBe(lives - 1);
  t.tick(10); t.key('SPACE');
  expect(t.G.mode).toBe('HOLD');
  t.tick(7); t.key('SPACE');
  expect(t.G.mode).toBe('PLAY');
  // mashing straight on: the tile under the player is still wrong, but the bite is ignored
  t.key('SPACE');
  expect(t.G.mode).toBe('PLAY');
  expect(t.G.lives).toBe(lives - 1);
  t.tick(8);
  t.key('SPACE');
  expect(t.G.mode).toBe('HOLD');
  expect(t.G.lives).toBe(lives - 2);
});

test('auto-end of a hold is 2.6 s for a wrong bite, 1.5 s for a touch', () => {
  const t = load();
  intoPlay(t);
  const i = firstWrong(t.G);
  t.G.pc = i % 5; t.G.pr = Math.floor(i / 5);
  t.key('SPACE');
  t.tick(51);
  expect(t.G.mode).toBe('HOLD');
  t.tick(2);
  expect(t.G.mode).toBe('PLAY');
  const e = t.G.enemies[0];
  e.state = 'live'; e.c = 0; e.r = 0; t.G.invMs = 0; t.G.pc = 1; t.G.pr = 0; t.G.biteLock = 0;
  t.key('LEFT');
  t.tick(0);
  t.G.moveCool = 0;
  t.key('LEFT');
  expect(t.G.mode).toBe('HOLD');
  expect(t.G.touch).toBe(true);
  t.tick(29);
  expect(t.G.mode).toBe('HOLD');
  t.tick(2);
  expect(t.G.mode).toBe('PLAY');
});

test('enemies kneel during a hold', () => {
  const t = load();
  intoPlay(t);
  const e = t.G.enemies[0];
  e.state = 'live'; e.c = 0; e.r = 0;
  const i = firstWrong(t.G);
  t.G.pc = i % 5; t.G.pr = Math.floor(i / 5);
  if (i === 0) { t.G.pc = 1; }
  t.key('SPACE');
  expect(t.G.mode).toBe('HOLD');
  expect(t.G.cellSpr[0].some((s: any) => s.s === 'REEVEKN')).toBe(true);
});

test('the rule card needs ENTER or SPACE after 0.5 s; arrows do not skip it', () => {
  const t = load();
  t.key('SPACE');
  expect(t.G.mode).toBe('CARD');
  t.key('SPACE');
  expect(t.G.mode).toBe('CARD');
  t.tick(12);
  t.key('RIGHT'); t.key('A'); t.key('DOWN');
  expect(t.G.mode).toBe('CARD');
  t.key('ENTER');
  expect(t.G.mode).toBe('PLAY');
});

test('the card advances by itself after 2.2 s', () => {
  const t = load({ reduced: false });
  t.key('SPACE');
  t.tick(43);
  expect(t.G.mode).toBe('CARD');
  t.tick(2);
  expect(t.G.mode).toBe('PLAY');
});

test('a good bite pops its score and crumbs, then clears; the bite sound climbs with the streak', () => {
  const t = load();
  intoPlay(t);
  const i = firstTarget(t.G);
  t.G.pc = i % 5; t.G.pr = Math.floor(i / 5);
  t.tick(10);
  const before = t.beeps.length;
  t.key('SPACE');
  expect(cellRows(t, t.G.pc, t.G.pr)[1]).toContain('+10');
  expect(t.G.cellSpr[i].some((s: any) => s.s === 'crumbN')).toBe(true);
  t.tick(10);
  expect(t.beeps.slice(before).some((b) => b[0] === 659)).toBe(true);
  expect(cellRows(t, t.G.pc, t.G.pr)[1].trim()).toBe('');
  expect(t.G.streak).toBe(1);
});

test('the Bard marks a rewritten tile with a note that fades', () => {
  const t = load({ seed: 7 });
  intoPlay(t);
  const e = t.G.enemies[0];
  e.kind = 'BARD'; e.state = 'live'; e.c = 0; e.r = 0; e.iv = 100; e.acc = 0; e.lc = -1; e.lr = -1;
  t.G.pc = 4; t.G.pr = 4; t.G.invMs = 0;
  t.G.tiles[0] = { a: '7', b: '', ok: false, v: 7 };
  t.tick(3);
  expect(t.G.rewrites).toBe(1);
  expect(t.G.notes[0]).toBeGreaterThan(0);
  expect(t.G.cellSpr[0].some((s: any) => s.s === 'note')).toBe(true);
  t.tick(30);
  expect(t.beeps.some((b) => b[0] === 494)).toBe(true);
  expect(t.G.notes[0]).toBeUndefined();
  expect((t.G.cellSpr[0] || []).some((s: any) => s.s === 'note')).toBe(false);
});

test('level 5 has the 34 and 53 credit boards with correct tiles', () => {
  const t = load();
  expect(t.X.VARIANTS5).toEqual(['F24', 'F30', 'E12', 'E21', 'E34', 'E53']);
  for (const v of ['E34', 'E53']) {
    const p = t.X.pools(5, v), n = +v.slice(1);
    expect(p.T.length).toBeGreaterThanOrEqual(p.nT);
    for (const x of p.T) expect(x.v).toBe(n);
    for (const x of p.W) { expect(x.v).not.toBe(n); expect(x.a.length).toBeLessThanOrEqual(6); }
    const b = t.X.banner(5, v);
    expect(b[0]).toBe('MUNCH WHAT EQUALS ' + n);
    expect(b[0].length).toBeLessThanOrEqual(40);
    expect(t.X.holdMessage(5, v, p.W[0])).toContain('NOT ' + n);
  }
});

test('first sight of each enemy is announced once', () => {
  const t = load();
  intoPlay(t);
  t.tick(65);
  expect(t.G.enemies[0].state).toBe('warn');
  expect(t.row(1)).toContain('THE REEVE WALKS THE FIELD. THOROUGHLY.');
  expect(t.G.seen.REEVE).toBe(true);
});

test('a blocked warn cell gives half a second of warning rather than an instant enemy', () => {
  const t = load();
  intoPlay(t);
  const e = t.G.enemies[0];
  e.state = 'warn'; e.wc = 0; e.wr = 0; e.wms = 50; t.G.pc = 0; t.G.pr = 0;
  t.G.dirty = {}; t.tick(1);
  expect(e.state).toBe('warn');
  expect(e.wms).toBe(500);
});

test('a loss shows NEW BEST! when the score beat the best from before the run', () => {
  const t = load({ best: { score: 0, level: 1, won: false } });
  intoPlay(t);
  t.G.score = 120;
  t.G.lives = 1;
  const i = firstWrong(t.G);
  t.G.pc = i % 5; t.G.pr = Math.floor(i / 5);
  t.key('SPACE');
  t.tick(60);
  expect(t.G.mode).toBe('END');
  expect(t.row(19)).toContain('NEW BEST!');
});

test('random input for several full runs: no exceptions, nothing off the grid or outside the font', () => {
  const keys = ['UP', 'DOWN', 'LEFT', 'RIGHT', 'W', 'A', 'S', 'D', 'SPACE', 'ENTER', 'SPACE', 'SPACE', 'H', 'M'];
  for (const reduced of [false, true]) {
    for (let seed = 1; seed <= 6; seed++) {
      const t = load({ seed, reduced });
      let rs = seed * 99991;
      const rnd = (n: number) => { rs = (rs * 1103515245 + 12345) & 0x7fffffff; return (rs >> 8) % n; };
      t.key('SPACE');
      for (let step = 0; step < 6000; step++) {
        t.tick();
        if (rnd(3) === 0) t.key(keys[rnd(keys.length)]);
        // a keener player: sometimes bite only right tiles
        if (t.G.mode === 'PLAY' && rnd(4) === 0) {
          const i = firstTarget(t.G);
          if (i >= 0) { t.G.pc = i % 5; t.G.pr = Math.floor(i / 5); t.key('SPACE'); }
        }
        if (t.G.mode === 'END') t.key('ENTER');
      }
      expect(t.bad).toEqual([]);
    }
  }
});
