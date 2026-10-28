import { test, expect } from '@playwright/test';
import { loadAxes } from '../lib/axes';
import { enumerate, enumerateExhaustiveC, namedCases, sampleMode } from '../lib/cases';

test('axes carry the whole published vocabulary', () => {
  const a = loadAxes();
  // 17 partials - 2 that carry variants + battle{arcane,martial} + owl{parchment,cyanotype,transparency}
  expect(a.orderVariants).toHaveLength(20);
  expect(a.ranks).toEqual([0,1,2,3,4,5,6,7,8,9,10]);
  expect(a.hands).toHaveLength(25);
  expect(a.grounds).toHaveLength(12);
  expect(a.borders).toHaveLength(11);
  expect(a.rankSets).toHaveLength(4);
  expect(a.groundsWithOwnBorder).toHaveLength(5);
});

test('stage A is order-variant x rank x text', () => {
  const cases = enumerate(loadAxes(), 'A', undefined, false);
  expect(cases).toHaveLength(20 * 11 * 5);          // 1100: five text profiles since kingdomReal
  expect(new Set(cases.map(c => c.id)).size).toBe(cases.length);
  expect(cases.every(c => c.hand === 'chancery')).toBe(true);
});

test('stage B is order-variant x hand x text at one rank each', () => {
  const tightest = Object.fromEntries(
    loadAxes().orderVariants.map(o => [o.order + '/' + o.variant, 7])
  );
  const cases = enumerate(loadAxes(), 'B', tightest);
  expect(cases).toHaveLength(20 * 25 * 5);          // 2500
  expect(cases.every(c => c.rank === 7)).toBe(true);
});

test('stage C uses only legal ground/border pairs', () => {
  const a = loadAxes();
  const cases = enumerate(a, 'C');
  const pairs = cases.filter(c => c.recipe).map(c => c.recipe!.ground + '/' + c.recipe!.border);
  // 7 borderless grounds x 11 borders + 5 self-bordered grounds x 1 forced 'none'
  expect(new Set(pairs).size).toBe(82);
  for (const g of a.groundsWithOwnBorder) {
    expect(cases.filter(c => c.recipe?.ground === g).every(c => c.recipe!.border === 'none')).toBe(true);
  }
});

test('case ids are unique in every stage', () => {
  /* Stage C's two loops legitimately overlap at parchment+knot+common rank 10 — it is
     both a legal ground/border pair and the fixed recipe the rankSet loop varies rank
     against. The id is a key everywhere downstream (results.json, the report table,
     the PNG filename), so a collision would silently drop a row rather than fail. */
  const a = loadAxes();
  for (const stage of ['A', 'B', 'C'] as const) {
    const ids = enumerate(a, stage).map(c => c.id);
    expect(new Set(ids).size, `${stage} has duplicate case ids`).toBe(ids.length);
  }
});

test('the exhaustive tier is a superset of stage C', () => {
  const a = loadAxes();
  const routine = new Set(enumerate(a, 'C').map(c => c.id));
  const all = new Set(enumerateExhaustiveC(a).map(c => c.id));
  // Every routine stage-C case must be re-run by the validation tier, or the tier
  // cannot tell us whether the factorisation held.
  for (const id of routine) expect(all.has(id), `${id} is not in the exhaustive tier`).toBe(true);
  expect(all.size).toBe(82 * 4 * 11);
});

/* ORK3_SWEEP_SAMPLE=1: the sampled matrix the baseline is captured over. These pin its size
   per stage, because a baseline taken over a population nobody can state is not evidence. */
const axes = loadAxes();
const key = (c: { order: string; variant: string }) => c.order + '/' + c.variant;

test('sampled stage A is every order-variant x ranks {0,5,10} x profiles {max,empty,kingdomReal}', () => {
  const cases = enumerate(axes, 'A', undefined, true);
  expect(cases).toHaveLength(20 * 3 * 3);                                   // 180
  expect(new Set(cases.map(c => c.id)).size).toBe(cases.length);
  expect([...new Set(cases.map(c => c.text))].sort()).toEqual(['empty', 'kingdomReal', 'max']);
  expect(cases.every(c => c.hand === 'chancery' && c.surface === 'print')).toBe(true);
  for (const o of axes.orderVariants) {
    const ranks = [...new Set(cases.filter(c => key(c) === key(o)).map(c => c.rank))].sort((a, b) => a - b);
    /* warrior starts at rank 1 (data-min="1"), so its floor sample is 1, not an unreachable 0. */
    expect(ranks, key(o)).toEqual(o.order === 'warrior' ? [1, 5, 10] : [0, 5, 10]);
  }
});

test('sampled stage B is every order-variant x every hand x {max}, at rank 10', () => {
  const cases = enumerate(axes, 'B', undefined, true);
  expect(cases).toHaveLength(20 * 25 * 1);                                  // 500
  expect(new Set(cases.map(c => c.id)).size).toBe(cases.length);
  expect(cases.every(c => c.text === 'max' && c.rank === 10)).toBe(true);
  expect(new Set(cases.map(c => c.hand))).toEqual(new Set(axes.hands));
  /* A caller that knows each order's tightest rank can still say so. */
  const at7 = enumerate(axes, 'B', Object.fromEntries(axes.orderVariants.map(o => [key(o), 7])), true);
  expect(at7.every(c => c.rank === 7)).toBe(true);
});

test('sampled stage C keeps every legal pair and samples rankSet x ranks {0,5,10}', () => {
  const cases = enumerate(axes, 'C', undefined, true);
  /* 82 legal ground/border pairs + 4 rankSets x 3 ranks, less the one point they share
     (parchment+knot+common at rank 10), the same overlap the full matrix has. */
  expect(cases).toHaveLength(82 + 4 * 3 - 1);                               // 93
  expect(new Set(cases.map(c => c.id)).size).toBe(cases.length);
  const pairs = cases.filter(c => c.recipe!.rankSet === 'common' && c.rank === 10 && c.recipe!.ground !== undefined);
  expect(pairs.length).toBeGreaterThanOrEqual(82);
  const ranked = cases.filter(c => c.recipe!.ground === 'parchment' && c.recipe!.border === 'knot');
  expect([...new Set(ranked.map(c => c.rank))].sort((a, b) => a - b)).toEqual([0, 5, 10]);
  expect([...new Set(ranked.map(c => c.recipe!.rankSet))].sort()).toEqual([...axes.rankSets].sort());
});

test('every sampled case is also a case of the full matrix, so a sampled baseline and a full run share ids', () => {
  for (const stage of ['A', 'B', 'C'] as const) {
    const full = new Set(enumerate(axes, stage, undefined, false).map(c => c.id));
    const missing = enumerate(axes, stage, undefined, true).filter(c => !full.has(c.id)).map(c => c.id);
    expect(missing, `stage ${stage} sampled ids absent from the full matrix`).toEqual([]);
  }
});

test('stage N and the full matrix are untouched by sampling', () => {
  expect(namedCases()).toHaveLength(9);
  expect(enumerate(axes, 'A', undefined, false)).toHaveLength(1100);
  expect(enumerate(axes, 'B', undefined, false)).toHaveLength(2500);
  expect(enumerate(axes, 'C', undefined, false)).toHaveLength(125);
});

test('ORK3_SWEEP_SAMPLE=1 turns sampling on and nothing else does', () => {
  const prior = process.env.ORK3_SWEEP_SAMPLE;
  try {
    for (const [v, want] of [['1', true], ['0', false], ['', false], ['true', false], [undefined, false]] as const) {
      if (v === undefined) delete process.env.ORK3_SWEEP_SAMPLE; else process.env.ORK3_SWEEP_SAMPLE = v;
      expect(sampleMode(), String(v)).toBe(want);
    }
  } finally {
    if (prior === undefined) delete process.env.ORK3_SWEEP_SAMPLE; else process.env.ORK3_SWEEP_SAMPLE = prior;
  }
});
