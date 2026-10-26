import { spawnSync } from 'node:child_process';
import type { FixtureSpec } from './fixture';

/*
 * A random sample of certificates drawn from the real ORK data, the way the 2026-10-25
 * review drew its fifty (the frozen corpus's c01 to c50), except that the sampling rules
 * are written down here and the whole draw is a function of one integer.
 *
 * WHAT THE SEED CONTROLS
 *
 * Every SQL `ORDER BY RAND(n)` takes its n from the seed (seed + 1, seed + 2, ... one per
 * query), and everything the script chooses itself comes from a small PRNG started at the
 * seed. So the same seed over the same database gives the same specs. It does not give the
 * same specs over a database that has changed underneath it (a nightly reload moves
 * which row RAND() lands on), which is why the corpus spec writes the drawn specs to
 * out/<tag>/specs.json: that file, not the seed, is what replays a failing run.
 *
 * THE RULES
 *
 *   personas   half uniform over active mundanes, a quarter 24 to 40 characters, a quarter
 *              over 40 (the long tail is what actually breaks a layout)
 *   issuers    half kingdoms (ork_kingdom.active = 'Active'), half parks (joined to
 *              ork_parktitle for the rank word); a quarter of the kingdom cases are in the
 *              split form the composer prefills ("and the Kingdom of" + "the Wetlands")
 *   custom     award 94 or 246, a real ork_awards.custom_name, 40% of them over 28 characters
 *   notes      real ork_awards.note of 10 to 400 characters
 *   hands      each of the ten, an equal number of times
 *
 * The queries are read-only, through `docker exec ork3-php8-db mariadb`. Nothing here writes.
 */

const DB_CONTAINER = process.env.ORK3_DB_CONTAINER ?? 'ork3-php8-db';

/** `mariadb -B` writes tab-separated rows and escapes tab, newline, NUL and backslash. */
function unescapeBatch(s: string): string {
  return s.replace(/\\([tn0\\])/g, (_m, c: string) => (c === 't' ? '\t' : c === 'n' ? '\n' : c === '0' ? '\0' : '\\'));
}

function query(sql: string): string[][] {
  const r = spawnSync(
    'docker',
    ['exec', '-i', DB_CONTAINER, 'mariadb', '-u', 'ork', '-psecret', 'ork', '-N', '-B', '-e', sql],
    { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 },
  );
  if (r.error) throw new Error(`could not run docker: ${r.error.message}`);
  if (r.status !== 0) throw new Error(`sampling query failed (${r.status}): ${r.stderr}\n${sql}`);
  return r.stdout
    .split('\n')
    .filter(l => l.trim() !== '')
    .map(l => l.split('\t').map(unescapeBatch));
}

/** mulberry32: small, fast, and good enough to choose among ten hands. */
export function prng(seed: number): () => number {
  let a = seed >>> 0;
  return () => {
    a = (a + 0x6d2b79f5) >>> 0;
    let t = a;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

type Rng = () => number;
const pick = <T>(rng: Rng, xs: readonly T[]): T => xs[Math.floor(rng() * xs.length)];
const int = (rng: Rng, lo: number, hi: number): number => lo + Math.floor(rng() * (hi - lo + 1));

function shuffle<T>(rng: Rng, xs: T[]): T[] {
  for (let i = xs.length - 1; i > 0; i--) {
    const j = Math.floor(rng() * (i + 1));
    [xs[i], xs[j]] = [xs[j], xs[i]];
  }
  return xs;
}

/** Take `n` from `pool`, starting over when it runs out so a count above the pool still works. */
function take<T>(pool: T[], n: number): T[] {
  if (!pool.length) throw new Error('sampling pool is empty');
  return Array.from({ length: n }, (_v, i) => pool[i % pool.length]);
}

const ORDERS: Record<string, { names: string[]; variants: string[]; minRank: number; awardId: number }> = {
  battle: { names: ['Order of Battle'], variants: ['arcane', 'martial'], minRank: 0, awardId: 243 },
  crown: { names: ['Order of the Crown'], variants: [''], minRank: 0, awardId: 239 },
  dragon: { names: ['Order of the Dragon'], variants: [''], minRank: 0, awardId: 25 },
  flame: { names: ['Order of the Flame'], variants: [''], minRank: 0, awardId: 34 },
  garber: { names: ['Order of the Garber'], variants: [''], minRank: 0, awardId: 26 },
  griffon: { names: ['Order of the Griffon', 'Order of the Griffin'], variants: [''], minRank: 0, awardId: 33 },
  jovious: { names: ['Order of the Jovious', 'Order of the Jovius'], variants: [''], minRank: 0, awardId: 28 },
  lion: { names: ['Order of the Lion'], variants: [''], minRank: 0, awardId: 23 },
  hunter: { names: ['Order of the Hunter', 'Order of the Sharpshooter', 'Order of the Marksman'], variants: [''], minRank: 0, awardId: 0 },
  hydra: { names: ['Order of the Hydra'], variants: [''], minRank: 0, awardId: 32 },
  mask: { names: ['Order of the Mask'], variants: [''], minRank: 0, awardId: 29 },
  owl: { names: ['Order of the Owl'], variants: ['parchment', 'cyanotype', 'transparency'], minRank: 0, awardId: 24 },
  rose: { names: ['Order of the Rose'], variants: [''], minRank: 0, awardId: 21 },
  smith: { names: ['Order of the Smith'], variants: [''], minRank: 0, awardId: 22 },
  warrior: { names: ['Order of the Warrior'], variants: [''], minRank: 1, awardId: 27 },
  zodiac: { names: ['Order of the Zodiac'], variants: [''], minRank: 0, awardId: 30 },
};
const HANDS = ['chancery', 'sharp', 'pirata', 'cinzel', 'gotisch', 'uncial', 'fondamento', 'garamond', 'unicase', 'metamorphous'];
const GROUNDS = ['parchment', 'linen', 'jovious', 'flame', 'starfield', 'starfield2', 'cyanotype', 'sunburst', 'map', 'forge', 'rosewash', 'arcane'];
const BORDERS = ['none', 'knot', 'crown', 'hunter', 'hydra', 'lion', 'mask', 'owl', 'rose', 'smith', 'battle'];
const RANK_SETS = ['common', 'zodiac', 'garber', 'flame'];
const PRONOUNS = ['their', 'their', 'their', 'his', 'her', 'her', 'his'];

/** The composer's split, on the rank words PHP's ScrollText knows (spec 5.2 rule 3). */
const KINGDOM_SPLIT = /^(?:The|Þe)\s+(Kingdom|Empire|Principality|Grand Duchy|Duchy|Freeholds|Confederacy)\s+of\s+(.+)$/iu;
function splitKingdom(name: string): { title: string; rest: string } | null {
  const m = KINGDOM_SPLIT.exec(name.trim());
  return m ? { title: m[1], rest: m[2] } : null;
}

/** A persona is at most 100 characters in the ORK; the sample never invents longer ones. */
const PERSONA_MAX = 100;

export type SampleSpec = FixtureSpec & { issuerForm: string };

export function drawSample(seed: number, count: number): SampleSpec[] {
  if (!Number.isInteger(seed) || seed < 0 || seed > 2 ** 31) throw new Error(`seed must be a non-negative integer: ${seed}`);
  if (!Number.isInteger(count) || count < 1 || count > 1000) throw new Error(`count must be an integer from 1 to 1000: ${count}`);
  const rng = prng(seed);
  let n = 0;
  const rand = () => seed + ++n; // the n of the next ORDER BY RAND(n)
  const col = (sql: string): string[] => query(sql).map(r => r[0]);

  // Quotas first, so the proportions hold exactly and do not depend on luck.
  const nUniform = Math.ceil(count / 2);
  const nMid = Math.floor((count - nUniform + 1) / 2);
  const nLong = count - nUniform - nMid;
  const nKingdom = Math.ceil(count / 2);
  const nPark = count - nKingdom;
  const nSplit = Math.round(nKingdom / 4);

  const personas = (where: string, k: number): string[] =>
    k < 1 ? [] : col(`select persona from ork_mundane where active=1 and persona<>'' and char_length(persona)<=${PERSONA_MAX} and ${where} order by rand(${rand()}) limit ${k}`);
  const uniform = personas('1=1', nUniform);
  const mid = personas('char_length(persona) between 24 and 40', nMid);
  const long = personas('char_length(persona) > 40', nLong);
  const signers = col(`select persona from ork_mundane where active=1 and persona<>'' and char_length(persona)<=${PERSONA_MAX} order by rand(${rand()}) limit 20`);

  const kingdoms = col(`select name from ork_kingdom where active='Active' and name<>'' order by rand(${rand()})`);
  const parks = query(
    `select p.name, pt.title from ork_park p join ork_parktitle pt on pt.parktitle_id=p.parktitle_id where p.active='Active' and p.name<>'' order by rand(${rand()}) limit ${Math.max(nPark, 1)}`,
  ).map(r => ({ name: r[0], title: r[1] }));

  const customAll = col(`select distinct custom_name from ork_awards where award_id in (94,246) and custom_name<>'' order by rand(${rand()}) limit 60`);
  const customLong = col(`select distinct custom_name from ork_awards where award_id in (94,246) and char_length(custom_name)>28 order by rand(${rand()}) limit 30`);
  const notes = col(`select note from ork_awards where note<>'' and char_length(note) between 10 and 400 order by rand(${rand()}) limit ${count + 10}`);

  // Kingdom cases: the split ones come from kingdoms the split rule actually matches.
  const splittable = kingdoms.filter(k => splitKingdom(k));
  const kingdomPool = [...take(splittable.length ? splittable : kingdoms, nSplit).map(k => ({ k, split: true })), ...take(kingdoms, nKingdom - nSplit).map(k => ({ k, split: false }))];
  const issuers: Array<{ scope: 'kingdom' | 'park'; name: string; title: string; split: boolean }> = [
    ...kingdomPool.map(x => ({ scope: 'kingdom' as const, name: x.k, title: '', split: x.split })),
    ...take(parks, nPark).map(p => ({ scope: 'park' as const, name: p.name, title: p.title, split: false })),
  ];
  shuffle(rng, issuers);

  // Persona strata, shuffled across the cases.
  const personaOrder = shuffle(rng, [...uniform, ...mid, ...long]);

  // Each hand the same number of times (exactly, when count is a multiple of ten).
  const hands = shuffle(rng, Array.from({ length: Math.ceil(count / HANDS.length) * HANDS.length }, (_v, i) => HANDS[i % HANDS.length])).slice(0, count);

  const orderPool = [...Object.keys(ORDERS), ...Array(6).fill('custom')] as string[];
  const out: SampleSpec[] = [];
  for (let i = 0; i < count; i++) {
    const order = pick(rng, orderPool);
    const base: Partial<SampleSpec> = { id: `s${String(i + 1).padStart(2, '0')}`, hand: hands[i], order };
    if (order === 'custom') {
      Object.assign(base, {
        awardId: 94,
        title: customLong.length && rng() < 0.4 ? pick(rng, customLong) : pick(rng, customAll),
        variant: '',
        rank: pick(rng, [0, 0, 1, 2, 3, 5, 7, 10]),
        recipe: { ground: pick(rng, GROUNDS), border: pick(rng, BORDERS), rankSet: pick(rng, RANK_SETS) },
      });
    } else {
      const o = ORDERS[order];
      Object.assign(base, { awardId: o.awardId, title: pick(rng, o.names), variant: pick(rng, o.variants), rank: int(rng, o.minRank, 10) });
    }

    const iss = issuers[i];
    const sp = iss.scope === 'kingdom' && iss.split ? splitKingdom(iss.name) : null;
    if (iss.scope === 'kingdom' && sp) {
      // The composer's edited form, as in the reported case zero.
      Object.assign(base, { scope: 'kingdom', issuerName: sp.rest, issuerTitle: sp.title, useThe: true, useOf: true, issuerForm: 'kingdom-split' });
    } else if (iss.scope === 'kingdom') {
      Object.assign(base, { scope: 'kingdom', issuerName: iss.name, issuerTitle: '', useThe: false, useOf: false, issuerForm: 'kingdom' });
    } else {
      Object.assign(base, { scope: 'park', issuerName: iss.name, issuerTitle: iss.title, useThe: true, useOf: true, issuerForm: 'park' });
    }

    Object.assign(base, {
      player: personaOrder[i],
      pronoun: pick(rng, PRONOUNS),
      reason: notes[i % notes.length],
      givenBy: pick(rng, signers),
      date: `${int(rng, 2012, 2026)}-${String(int(rng, 1, 12)).padStart(2, '0')}-${String(int(rng, 1, 28)).padStart(2, '0')}`,
    });
    out.push(base as SampleSpec);
  }
  return out;
}
