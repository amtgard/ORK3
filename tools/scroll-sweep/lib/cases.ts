import type { Axes } from './axes';
import { FIXTURE_ORDERS } from './drive';
import { PROFILE_NAMES, type TextProfile } from './text';

export type Surface = 'print' | 'preview';
export type Stage = 'A' | 'B' | 'C' | 'D' | 'N';

export type SweepCase = {
  id: string;
  stage: Stage;
  order: string;
  variant: string;
  rank: number;
  hand: string;
  text: TextProfile;
  surface: Surface;
  recipe?: { ground: string; border: string; rankSet: string };
  up?: 0 | 2 | 4;
  trim?: boolean;
  lines?: boolean;
};

export function caseId(c: SweepCase): string {
  const parts = [
    c.stage, c.order + (c.variant ? '-' + c.variant : ''), 'r' + c.rank, c.hand, c.text, c.surface,
  ];
  if (c.recipe) parts.push(c.recipe.ground + '+' + c.recipe.border + '+' + c.recipe.rankSet);
  if (c.up) parts.push('up' + c.up);
  if (c.trim) parts.push('trim');
  if (c.lines === false) parts.push('nolines');
  return parts.join('_');
}

const base = (o: { order: string; variant: string }, rank: number, hand: string,
              text: TextProfile, stage: Stage): SweepCase => {
  const c: SweepCase = {
    id: '', stage, order: o.order, variant: o.variant, rank, hand, text, surface: 'print',
  };
  c.id = caseId(c);
  return c;
};

/**
 * Legal ground/border pairs.
 *
 * NormaliseRecipe() forces border 'none' for anything in groundsWithOwnBorder — those
 * five grounds have a frame painted into the plate, and drawing a border over one
 * gives the certificate two. Enumerating the illegal pairs would sweep states the
 * app cannot reach and report collisions nobody can produce.
 */
export function legalRecipePairs(a: Axes): { ground: string; border: string }[] {
  const out: { ground: string; border: string }[] = [];
  for (const ground of a.grounds) {
    if (a.groundsWithOwnBorder.includes(ground)) {
      out.push({ ground, border: 'none' });
      continue;
    }
    for (const border of a.borders) out.push({ ground, border });
  }
  return out;
}

/**
 * De-duplicate by case id.
 *
 * Stage C's two loops legitimately overlap at one point: `parchment+knot` is both a
 * legal ground/border pair AND the fixed recipe the rankSet loop varies rank against,
 * so `parchment+knot+common` at rank 10 is generated twice. Sweeping it twice is
 * harmless; keying results by id and getting 125 rows where the count says 126 is not.
 * De-duplicating here means the id is a key everywhere downstream.
 */
function dedupe(cases: SweepCase[]): SweepCase[] {
  const seen = new Set<string>();
  return cases.filter(c => (seen.has(c.id) ? false : (seen.add(c.id), true)));
}

/**
 * ORK3_SWEEP_SAMPLE=1 — the SAMPLED matrix.
 *
 * The full matrix is about 2,100 cases for stages N, A and B and an hour and a half of wall
 * clock at 1:1, which is too much to spend on every run. The sample keeps the shape of each
 * stage and thins the axes that moved least, so a run takes about a fifth as long:
 *
 *   A  every order-variant x ranks {0, 5, 10} x profiles {max, empty, kingdomReal}
 *        rank moves the PLATE, so the two ends and the middle are sampled; an order whose
 *        minimum rank is above 0 (warrior starts at 1) is sampled at its minimum instead of
 *        at a rank the app cannot produce
 *        `max` is the synthetic worst case, `empty` the blank one, `kingdomReal` the real
 *        kingdom-issued one; `real` and `caseZero` are dropped
 *   B  every order-variant x every hand x {max}, at rank 10
 *   C  every legal ground/border pair, as now; rankSet x ranks {0, 5, 10}
 *   N  unchanged
 *
 * Stage B is meant to run at the rank stage A found tightest for each order, and that is
 * not knowable here: B is enumerated before any of A has been measured, and the shards
 * enumerate in parallel. So `tightest` defaults to rank 10, the largest sampled rank and the
 * one the full matrix has always used. It is where the art that grows with rank is widest
 * (dragon's knotwork widens, garber's tape measure grows; see stage A below). A caller that
 * has measured the ranks passes them in.
 *
 * Every sampled case is also a case of the full matrix, with the same id, so a sampled
 * baseline and a later full run compare on the cases they share.
 */
export const SAMPLE_RANKS = [0, 5, 10];
export const SAMPLE_PROFILES_A: TextProfile[] = ['max', 'empty', 'kingdomReal'];
export const SAMPLE_PROFILES_B: TextProfile[] = ['max'];

/** Whether this run is sampled. Opt-in, and read here only: everything else asks enumerate(). */
export const sampleMode = (): boolean => process.env.ORK3_SWEEP_SAMPLE === '1';

/** The sampled ranks for an order: {0, 5, 10}, with anything below its floor lifted to the floor. */
function sampledRanks(order: string): number[] {
  const floor = FIXTURE_ORDERS[order]?.minRank ?? 0;
  return [...new Set(SAMPLE_RANKS.map(r => Math.max(r, floor)))];
}

/**
 * The case matrix for one stage. `sample` is explicit and defaults to OFF, so a unit test or a
 * helper that enumerates the full matrix cannot be changed by a variable left in the shell;
 * the sweep passes sampleMode().
 */
export function enumerate(a: Axes, stage: Stage, tightest?: Record<string, number>, sample = false): SweepCase[] {
  const cases: SweepCase[] = [];

  if (stage === 'A') {
    /* Rank changes the PLATE — dragon's knotwork widens, garber's tape measure grows,
       griffon draws symbol_N twice. That is a proven interaction with the order, so
       the two are crossed rather than swept separately. Hand is held at the default. */
    for (const o of a.orderVariants) {
      for (const rank of sample ? sampledRanks(o.order) : a.ranks) {
        for (const text of sample ? SAMPLE_PROFILES_A : PROFILE_NAMES) cases.push(base(o, rank, 'chancery', text, 'A'));
      }
    }
    return dedupe(cases);
  }

  if (stage === 'B') {
    /* The frame's inner edge is per-order; the type's width is per-hand. The
       interaction IS "does this hand's longest line reach this frame", so order and
       hand are crossed — at the single rank stage A found tightest for that order,
       because rank moves the plate and not the type. */
    for (const o of a.orderVariants) {
      const key = o.order + '/' + o.variant;
      const rank = tightest?.[key] ?? 10;
      for (const hand of a.hands) {
        for (const text of sample ? SAMPLE_PROFILES_B : PROFILE_NAMES) cases.push(base(o, rank, hand, text, 'B'));
      }
    }
    return dedupe(cases);
  }

  if (stage === 'C') {
    /* Ground and border paint the FRAME band; the rank numeral paints the CENTRE.
       They do not overlap, so the cross is factorised on that separation — and the
       factorisation is validated by the --exhaustive tier rather than assumed. */
    const custom = { order: 'custom', variant: '' };
    for (const pair of legalRecipePairs(a)) {
      const c = base(custom, 10, 'chancery', 'max', 'C');
      c.recipe = { ...pair, rankSet: 'common' };
      c.id = caseId(c);
      cases.push(c);
    }
    for (const rankSet of a.rankSets) {
      for (const rank of sample ? sampledRanks('custom') : a.ranks) {
        const c = base(custom, rank, 'chancery', 'max', 'C');
        c.recipe = { ground: 'parchment', border: 'knot', rankSet };
        c.id = caseId(c);
        cases.push(c);
      }
    }
    return dedupe(cases);
  }

  return dedupe(cases);
}

/** The exhaustive validation of stage C's factorisation: every pair x rankSet x rank. */
export function enumerateExhaustiveC(a: Axes): SweepCase[] {
  const cases: SweepCase[] = [];
  for (const pair of legalRecipePairs(a)) {
    for (const rankSet of a.rankSets) {
      for (const rank of a.ranks) {
        const c = base({ order: 'custom', variant: '' }, rank, 'chancery', 'max', 'C');
        c.recipe = { ...pair, rankSet };
        c.id = caseId(c);
        cases.push(c);
      }
    }
  }
  return dedupe(cases);
}

/**
 * The named cases: case zero and every previously-reported instance.
 *
 * These are swept ALWAYS, whatever stages are selected, because the acceptance
 * criteria name them individually and a cross product is not guaranteed to contain any
 * particular point of itself. Case zero is battle/arcane at rank 4 in Cinzel; stage B
 * sweeps battle/arcane in Cinzel but at whatever rank stage A found tightest, and
 * stage A sweeps rank 4 but in Chancery. Neither is the reported reproduction.
 *
 * Pinning them also means a regression in one of them fails loudly under its own name
 * rather than as one row among sixteen hundred.
 */
export const NAMED_CASES: { name: string; c: Omit<SweepCase, 'id'> }[] = [
  {
    /* THE reproduction from the report: composer, kingdom scope, Order of Battle,
       Arcane, Cinzel, "Kingdom" + "Crystal Groves" with both the and of, rank 4, no
       recipient. The issuing line wraps to three and the left flourish runs through
       the second. */
    name: 'case-zero',
    c: { stage: 'N', order: 'battle', variant: 'arcane', rank: 4, hand: 'cinzel',
         text: 'caseZero', surface: 'print' },
  },
  /* The same reproduction on the other surface. The composer is where it was seen. */
  {
    name: 'case-zero-preview',
    c: { stage: 'N', order: 'battle', variant: 'arcane', rank: 4, hand: 'cinzel',
         text: 'caseZero', surface: 'preview' },
  },
  /* Uncial wraps the issuing line on the same content as Cinzel — measured, 320px
     against Chancery's 163px — so it is the second member of the same family and not
     a duplicate of the first. */
  {
    name: 'case-zero-uncial',
    c: { stage: 'N', order: 'battle', variant: 'arcane', rank: 4, hand: 'uncial',
         text: 'caseZero', surface: 'print' },
  },
  /* The sign-and-date family, as the four prior fixes left it. Each of these is a
     case that HAS been fixed; they are here so that Task 11's constant-by-constant
     retirement has a named guard rather than a row in a table. The date and signer
     are driven long by the `max` profile, which is what made these visible at all:
     crown clears its corner ornament on "9th August 2019" and does not on
     "27th September 2026". */
  { name: 'crown-signdate',   c: { stage: 'N', order: 'crown',   variant: '', rank: 5,  hand: 'chancery', text: 'max', surface: 'print' } },
  { name: 'dragon-signdate',  c: { stage: 'N', order: 'dragon',  variant: '', rank: 10, hand: 'chancery', text: 'max', surface: 'print' } },
  { name: 'garber-signdate',  c: { stage: 'N', order: 'garber',  variant: '', rank: 10, hand: 'chancery', text: 'max', surface: 'print' } },
  { name: 'griffon-signdate', c: { stage: 'N', order: 'griffon', variant: '', rank: 7,  hand: 'chancery', text: 'max', surface: 'print' } },
  /* The issuer-name-on-the-knotwork case: the longest real issuer name in the ORK, in
     the widest hand, against the tightest frame (rose's head-band ink starts at 2243,
     the tightest of the seventeen). */
  { name: 'issuer-on-knotwork', c: { stage: 'N', order: 'rose', variant: '', rank: 5, hand: 'uncial', text: 'max', surface: 'print' } },
  /* Light type on a dark ground — the surface a darkness metric silently passed. */
  { name: 'cyanotype-white-type', c: { stage: 'N', order: 'owl', variant: 'cyanotype', rank: 9, hand: 'chancery', text: 'max', surface: 'print' } },
];

export function namedCases(): SweepCase[] {
  return NAMED_CASES.map(({ name, c }) => ({ ...c, id: 'N_' + name }));
}
