import { test, expect } from '@playwright/test';
import {
  q1Hierarchy, q2PlannedIsPrinted, q3Width, q4LeadNeverWraps, q7Parity, q8TextModel, q9Budget, q11Performance,
  type CaseInfo, type CaseMeasure,
} from '../lib/gates';

/*
 * The corpus gates, tried on hand-built layouts. A gate that has only ever been seen to fail
 * has not been shown to work, so each is given a certificate that is right by the spec's own
 * numbers (it must pass) and then that certificate with exactly one thing made wrong (it
 * must fail, and say why). None of this needs the app.
 */

/** A certificate that satisfies every gate: name 2.0, award 1.2, place 0.9, lead 0.8, versal 1.495. */
const good = (id = 'g1', over: Partial<CaseMeasure> = {}): CaseMeasure => ({
  id,
  rootEm: 1,
  comp: 'classic',
  budget: 'ok',
  ms: 6,
  versalEm: 1.495,
  scLineCount: 4,
  issuerText: ['and the Barony of', 'the Wetlands', 'and the Barony of the Wetlands'],
  roles: {
    name: { sizeEm: 2.0, lines: 1, planned: 1, engineLines: 1 },
    award: { sizeEm: 1.2, lines: 2, planned: 2, engineLines: 2 },
    place: { sizeEm: 0.9, lines: 1, planned: 1, engineLines: 1 },
    lead: { sizeEm: 0.8, lines: 1, planned: 1, engineLines: 1 },
  },
  ...over,
});
const withRole = (c: CaseMeasure, role: 'name' | 'award' | 'place' | 'lead', patch: object): CaseMeasure => ({
  ...c,
  roles: { ...c.roles, [role]: { ...c.roles[role]!, ...patch } },
});

test('the good certificate passes every gate', () => {
  const c = [good('a'), good('b')];
  const infos: CaseInfo[] = [
    { id: 'a', scope: 'kingdom', group: 'g' },
    { id: 'b', scope: 'park', group: 'g' },
  ];
  for (const r of [q1Hierarchy(c), q2PlannedIsPrinted(c), q3Width(c), q4LeadNeverWraps(c), q7Parity(c, infos), q8TextModel(c), q9Budget(c), q11Performance(c)]) {
    expect(r.offenders, `${r.gate} ${r.summary}`).toEqual([]);
    expect(r.ok).toBe(true);
  }
});

test('Q1 names the role that breaks the hierarchy', () => {
  expect(q1Hierarchy([withRole(good(), 'award', { sizeEm: 1.9 })]).offenders[0]).toMatch(/^g1: award 1.9 > 0.85\*name 1.7/);
  expect(q1Hierarchy([withRole(good(), 'place', { sizeEm: 1.6 })]).offenders[0]).toMatch(/place 1.6 > 0.75\*name/);
  // Below 0.75*name but above 0.90*award.
  expect(q1Hierarchy([withRole(good(), 'place', { sizeEm: 1.1 })]).offenders[0]).toMatch(/place 1.1 > 0.90\*award 1.08/);
  expect(q1Hierarchy([withRole(good(), 'lead', { sizeEm: 1.0 })]).offenders[0]).toMatch(/lead 1 > max\(lead.min, place\)/);
  expect(q1Hierarchy([good('g1', { versalEm: 1.1 })]).offenders[0]).toMatch(/versal 1.1 != clamp/);
});

test('Q1 lets a role sit at its own minimum, and clamps the versal both ways', () => {
  const small = good('s', { versalEm: 1.2 });
  small.roles.name!.sizeEm = 0.8;
  small.roles.award!.sizeEm = 0.7; // 0.85*0.8 = 0.68 < 0.7, allowed because it is award.min
  small.roles.place!.sizeEm = 0.62; // at place.min
  small.roles.lead!.sizeEm = 0.7;
  expect(q1Hierarchy([small]).offenders).toEqual([]);
  const big = good('b', { versalEm: 1.495 });
  big.roles.name!.sizeEm = 2.3;
  big.roles.award!.sizeEm = 1.5;
  expect(q1Hierarchy([big]).offenders).toEqual([]);
  // Multi-up lifts every minimum by 1.5 (award.min 0.7 -> 1.05), so an award of 0.9 under a
  // name of 1.0 is a violation at up=0 and sits at its floor at up=2.
  const tight = good('t', { versalEm: 1.2 });
  tight.roles.name!.sizeEm = 1.0;
  tight.roles.award!.sizeEm = 0.9;
  tight.roles.place!.sizeEm = 0.6;
  tight.roles.lead!.sizeEm = 0.6;
  expect(q1Hierarchy([tight]).offenders[0]).toMatch(/award 0.9 > 0.85\*name 0.85/);
  expect(q1Hierarchy([{ ...tight, up: 2 }]).offenders).toEqual([]);
});

test('Q2 needs the engine to have planned, and the three counts to agree', () => {
  const noEngine = withRole(good(), 'name', { planned: undefined });
  expect(q2PlannedIsPrinted([noEngine]).offenders[0]).toBe('g1: name: no data-sc-planned');
  expect(q2PlannedIsPrinted([withRole(good(), 'award', { planned: 2, engineLines: 2, lines: 3 })]).offenders[0]).toMatch(/award: planned 2, engine says 2, painted 3/);
  expect(q2PlannedIsPrinted([withRole(good(), 'place', { planned: 1, engineLines: 2 })]).offenders[0]).toMatch(/place: planned 1, engine says 2/);
  // A flagged case is held to it too.
  expect(q2PlannedIsPrinted([withRole(good(), 'award', { overflow: true, planned: 1, engineLines: 1, lines: 2 })]).ok).toBe(false);
});

test('Q3 fails a wide line, a missing width, and a page with no lines at all', () => {
  expect(q3Width([good('w', { wideLines: [{ role: 'name', w: 1801, allowed: 1700 }] })]).offenders[0]).toMatch(/w: name line 1801px > 1700px/);
  expect(q3Width([good('w', { wideLines: [{ role: 'name', w: 900, allowed: null }] })]).offenders[0]).toMatch(/no data-sc-w/);
  expect(q3Width([good('w', { scLineCount: 0 })]).offenders).toEqual(['w: no .sc-line elements']);
  // An overflow-flagged case is the engine's own admission and is not graded here.
  const flagged = withRole(good('f', { wideLines: [{ role: 'name', w: 1801, allowed: 1700 }] }), 'name', { overflow: true });
  expect(q3Width([flagged]).ok).toBe(true);
});

test('Q4 fails a wrapped lead in the case or in a forced composition', () => {
  expect(q4LeadNeverWraps([withRole(good(), 'lead', { lines: 2, engineLines: 2, planned: 2 })]).offenders[0]).toMatch(/lead printed 2 line/);
  const forced = { classic: [good()], centered: [withRole(good(), 'lead', { lines: 2 })] };
  expect(q4LeadNeverWraps([good()], forced).offenders).toEqual(['g1 [centered forced]: lead printed 2 line(s), engine says 1']);
});

test('Q7 compares the medians within 20% and refuses a corpus with one scope', () => {
  const k = good('k');
  const p = good('p');
  const infos: CaseInfo[] = [
    { id: 'k', scope: 'kingdom', group: 'g' },
    { id: 'p', scope: 'park', group: 'g' },
  ];
  const small = withRole(k, 'place', { sizeEm: 0.75 });
  const large = withRole(p, 'place', { sizeEm: 1.7 });
  expect(q7Parity([small, large], infos).ok).toBe(false);
  expect(q7Parity([withRole(k, 'place', { sizeEm: 1.0 }), withRole(p, 'place', { sizeEm: 1.15 })], infos).ok).toBe(true); // ratio 0.87
  expect(q7Parity([withRole(k, 'place', { sizeEm: 0.9 }), withRole(p, 'place', { sizeEm: 1.2 })], infos).ok).toBe(false); // ratio 0.75
  expect(q7Parity([k], [infos[0]]).offenders[0]).toMatch(/cannot compare/);
});

test('Q8 catches "of The" however the line is split, and not "of the"', () => {
  expect(q8TextModel([good('t', { issuerText: ['and', 'The Principality of The Painted Skies', 'and The Principality of The Painted Skies'] })]).offenders[0]).toMatch(/^t: "The Principality of The Painted Skies"/);
  expect(q8TextModel([good('t', { issuerText: ['and the Kingdom of', 'The Painted Skies', 'and the Kingdom of The Painted Skies'] })]).ok).toBe(false);
  expect(q8TextModel([good('t', { issuerText: ['and the Kingdom of', 'the Painted Skies', 'and the Kingdom of the Painted Skies'] })]).ok).toBe(true);
});

test('Q9 fails the floor, an overflow, a missing budget, and more pass-3 cases than before', () => {
  expect(q9Budget([good('f', { budget: 'floor' })]).offenders).toEqual(['f: budget at the floor']);
  expect(q9Budget([withRole(good('o'), 'name', { overflow: true })]).offenders[0]).toBe('o: data-sc-overflow on name');
  expect(q9Budget([good('n', { budget: undefined })]).offenders[0]).toBe('n: no data-sc-budget');
  const scaled = (id: string) => good(id, { rootEm: 0.9 });
  const before = { ids: ['c11'], scope: ['c11', 'c12'] };
  expect(q9Budget([scaled('c11'), good('c12')], before).ok).toBe(true);
  expect(q9Budget([scaled('c11'), scaled('c12')], before).offenders[0]).toMatch(/^c12: pass 3 fires on 2/);
  // A case outside the frozen scope never counts against the before figure.
  expect(q9Budget([scaled('c11'), scaled('s01')], before).ok).toBe(true);
});

test('Q11 reads the p95 of the reported times and fails when there are none', () => {
  const ms = (n: number) => Array.from({ length: 20 }, (_v, i) => good(`m${i}`, { ms: i < 19 ? 5 : n }));
  expect(q11Performance(ms(20)).ok).toBe(true); // 19 of 20 at 5ms: the p95 is the 19th value
  const slow = Array.from({ length: 20 }, (_v, i) => good(`m${i}`, { ms: i < 18 ? 5 : 40 }));
  expect(q11Performance(slow).offenders[0]).toMatch(/p95 40ms > 20ms/);
  expect(q11Performance([good('x', { ms: undefined })]).offenders[0]).toMatch(/no data-sc-ms on any case/);
});
