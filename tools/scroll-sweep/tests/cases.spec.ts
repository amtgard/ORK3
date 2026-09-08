import { test, expect } from '@playwright/test';
import { loadAxes } from '../lib/axes';
import { enumerate, enumerateExhaustiveC } from '../lib/cases';

test('axes carry the whole published vocabulary', () => {
  const a = loadAxes();
  // 17 partials - 2 that carry variants + battle{arcane,martial} + owl{parchment,cyanotype,transparency}
  expect(a.orderVariants).toHaveLength(20);
  expect(a.ranks).toEqual([0,1,2,3,4,5,6,7,8,9,10]);
  expect(a.hands).toHaveLength(10);
  expect(a.grounds).toHaveLength(12);
  expect(a.borders).toHaveLength(11);
  expect(a.rankSets).toHaveLength(4);
  expect(a.groundsWithOwnBorder).toHaveLength(5);
});

test('stage A is order-variant x rank x text', () => {
  const cases = enumerate(loadAxes(), 'A');
  expect(cases).toHaveLength(20 * 11 * 3);          // 660
  expect(new Set(cases.map(c => c.id)).size).toBe(cases.length);
  expect(cases.every(c => c.hand === 'chancery')).toBe(true);
});

test('stage B is order-variant x hand x text at one rank each', () => {
  const tightest = Object.fromEntries(
    loadAxes().orderVariants.map(o => [o.order + '/' + o.variant, 7])
  );
  const cases = enumerate(loadAxes(), 'B', tightest);
  expect(cases).toHaveLength(20 * 10 * 3);          // 600
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
