import type { Axes } from './axes';
import { PROFILE_NAMES, type TextProfile } from './text';

export type Surface = 'print' | 'preview';
export type Stage = 'A' | 'B' | 'C' | 'D';

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

export function enumerate(a: Axes, stage: Stage, tightest?: Record<string, number>): SweepCase[] {
  const cases: SweepCase[] = [];

  if (stage === 'A') {
    /* Rank changes the PLATE — dragon's knotwork widens, garber's tape measure grows,
       griffon draws symbol_N twice. That is a proven interaction with the order, so
       the two are crossed rather than swept separately. Hand is held at the default. */
    for (const o of a.orderVariants) {
      for (const rank of a.ranks) {
        for (const text of PROFILE_NAMES) cases.push(base(o, rank, 'chancery', text, 'A'));
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
        for (const text of PROFILE_NAMES) cases.push(base(o, rank, hand, text, 'B'));
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
      for (const rank of a.ranks) {
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
