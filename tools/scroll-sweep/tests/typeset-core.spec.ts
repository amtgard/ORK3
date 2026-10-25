import { test, expect } from '@playwright/test';

// Node-only: the engine's pure core touches no DOM, so none of this needs the app up.
const TS = require('../../../orkui/template/revised-frontend/script/scrollery-typeset.js');
const texts = (s: string, r = 'name') => TS.tokenize(s, r).map((t: any) => t.text);
const flags = (s: string, k: string) => TS.tokenize(s, 'name').map((t: any) => t[k]);

test('quoted nickname is one token', () => expect(texts('William "Billy Bob" FitzRobert')).toEqual(['William', '"Billy\u00a0Bob"', 'FitzRobert']));
test('curly quotes and parentheses are protected', () => {
  expect(texts('Şadhibek “The Gillie” Asdvadzadurian')).toEqual(['Şadhibek', '“The\u00a0Gillie”', 'Asdvadzadurian']);
  expect(texts('Tarmak Silverblade (or) Kamar Silverblade')).toEqual(['Tarmak', 'Silverblade', '(or)', 'Kamar', 'Silverblade']);
});
test('whitespace collapses', () => expect(texts('Sir Hitorimono,  the Scapegoat')).toEqual(['Sir', 'Hitorimono,', 'the', 'Scapegoat']));
test('leading honorific run', () => expect(flags('Captain Sir Lord Kato Darkhaven Grimwulff', 'honorific')).toEqual([true, true, true, false, false, false]));
test('an honorific needs a name after it', () => expect(flags('Lady', 'honorific')).toEqual([false]));
test('Grand counts only before a rank', () => {
  expect(flags('Syr Grand Marquis Noodles', 'honorific')).toEqual([true, true, true, false]);
  expect(flags('Grand Hall', 'honorific')).toEqual([false, false]);
});
test('epithet after a comma', () => expect(flags('Stormcrow Giggleshadows, the Relentless', 'epithetStart')).toEqual([false, false, true, false]));
test('epithet at a lowercase the', () => expect(flags('Ravenge the Renegade', 'epithetStart')).toEqual([false, true, false]));
test('penalties', () => {
  const award = TS.tokenize('Twilight Peak Guildmaster of Anti-Paladins', 'award');
  expect(TS.breakPenalty(award, 3, 'award')).toBe(60);    // after "of"
  expect(TS.breakPenalty(award, 2, 'award')).toBe(-10);   // before "of"
  const n = TS.tokenize('Stormcrow Giggleshadows, the Relentless', 'name');
  expect(TS.breakPenalty(n, 1, 'name')).toBe(-28);        // comma −20 + epithet −8
  const h = TS.tokenize('Captain Sir Lord Kato', 'name');
  expect(TS.breakPenalty(h, 2, 'name')).toBe(-25);
  expect(TS.breakPenalty(h, 0, 'name')).toBe(30);
  expect(TS.breakPenalty(TS.tokenize('James III', 'name'), 0, 'name')).toBe(50);
  expect(TS.breakPenalty(TS.tokenize('Robin T. W. Carver', 'name'), 1, 'name')).toBe(40);
});

// Beyond the brief: the edges that decide whether real personas tokenize sanely.

test('an apostrophe inside a word does not open a protected span', () => {
  expect(texts("Ma'at O'Brien 'the Bold' Smith")).toEqual(["Ma'at", "O'Brien", "'the\u00a0Bold'", 'Smith']);
  expect(texts("Dars' Fiery D'Arcy")).toEqual(["Dars'", 'Fiery', "D'Arcy"]);
});
test('an unclosed quote or bracket is ordinary text', () => {
  expect(texts('Bob "Wolf Pack Smith')).toEqual(['Bob', '"Wolf', 'Pack', 'Smith']);
  expect(texts('Bob (Wolf Pack Smith')).toEqual(['Bob', '(Wolf', 'Pack', 'Smith']);
});
test('punctuation after a protected span stays on its token', () => {
  expect(texts('Billy "Bob Joe", the Great')).toEqual(['Billy', '"Bob\u00a0Joe",', 'the', 'Great']);
});
test('brackets protect their whole span, nested or holding other brackets', () => {
  expect(texts('A (b (c d) e) F')).toEqual(['A', '(b\u00a0(c\u00a0d)\u00a0e)', 'F']);
  expect(texts('A [b (c d) e] F')).toEqual(['A', '[b\u00a0(c\u00a0d)\u00a0e]', 'F']);
});
test('astral characters are one character, not two', () => {
  expect(texts('Zoë 😀 "Ƶ 😀"')).toEqual(['Zoë', '😀', '"Ƶ\u00a0😀"']);
});
test('honorifics match through punctuation and a lowercase first letter', () => {
  expect(flags('sir, Kato', 'honorific')).toEqual([true, false]);
  expect(flags('Dame Lady', 'honorific')).toEqual([false, false]);
});
test('an honorific only leads: one mid-name stays a name', () => {
  expect(flags('Kato Lord Darkhaven', 'honorific')).toEqual([false, false, false]);
});
test('Grand before each rank it may precede', () => {
  for (const rank of ['Duke', 'Duchess', 'Marquis', 'Master']) {
    expect(flags(`Grand ${rank} Kato`, 'honorific')).toEqual([true, true, false]);
  }
  // Grand is not itself an honorific, so it ends the (empty) leading run before Prince.
  expect(flags('Grand Prince Kato', 'honorific')).toEqual([false, false, false]);
});
test('honorific and epithet flags exist only for the name role', () => {
  for (const role of ['award', 'place']) {
    const t = TS.tokenize('Sir Kato, the Bold', role);
    expect(t.map((x: any) => x.honorific)).toEqual([false, false, false, false]);
    expect(t.map((x: any) => x.epithetStart)).toEqual([false, false, false, false]);
  }
});
test('the earliest epithet start wins and only one token carries it', () => {
  expect(flags('Ravenge the Renegade, Slayer', 'epithetStart')).toEqual([false, true, false, false]);
  expect(flags('Foo, Bar the Great', 'epithetStart')).toEqual([false, true, false, false]);
});
test('a comma on the last token starts no epithet', () => expect(flags('Kato Grimwulff,', 'epithetStart')).toEqual([false, false]));
test('empty and blank input give no tokens', () => {
  expect(TS.tokenize('', 'name')).toEqual([]);
  expect(TS.tokenize('  \t\n ', 'award')).toEqual([]);
  expect(TS.tokenize(undefined, 'name')).toEqual([]);
  expect(TS.tokenize(null, 'place')).toEqual([]);
});
test('suffix forms', () => {
  for (const s of ['II', 'IV', 'VIII', 'X', 'Jr.', 'Sr.', 'Jr', 'Sr']) {
    expect(TS.breakPenalty(TS.tokenize(`James ${s}`, 'name'), 0, 'name'), s).toBe(50);
  }
  expect(TS.breakPenalty(TS.tokenize('James XI', 'name'), 0, 'name')).toBe(0);
});
test('penalties stack, and a gap that is not there costs nothing', () => {
  // "of," is weak (+60) and ends in a comma (−20).
  expect(TS.breakPenalty(TS.tokenize('Lord of, Kato', 'name'), 1, 'name')).toBe(40);
  // Comma (−20), the epithet it starts (−8) and a suffix (+50) all land in one gap.
  expect(TS.breakPenalty(TS.tokenize('Hitorimono, III', 'name'), 0, 'name')).toBe(22);
  const t = TS.tokenize('Kato Darkhaven', 'name');
  expect(TS.breakPenalty(t, 1, 'name')).toBe(0);
  expect(TS.breakPenalty(t, -1, 'name')).toBe(0);
  expect(TS.breakPenalty(t, 7, 'name')).toBe(0);
});
test('the last token of the honorific run is a −25 gap, not a +30', () => {
  const t = TS.tokenize('Sir Kato', 'name');
  expect(TS.breakPenalty(t, 0, 'name')).toBe(-25);
});
test('weak-end words are matched lowercase and without punctuation', () => {
  expect(TS.breakPenalty(TS.tokenize('Kato Of Hall', 'name'), 1, 'name')).toBe(60);
  expect(TS.breakPenalty(TS.tokenize('Kato & Hall', 'name'), 1, 'name')).toBe(60);
  expect(TS.breakPenalty(TS.tokenize('Kato von Hall', 'name'), 1, 'name')).toBe(60);
});
test('award before-of bonus is for the award role only', () => {
  const a = TS.tokenize('Master of Arms', 'award');
  expect(TS.breakPenalty(a, 0, 'award')).toBe(-10);
  expect(TS.breakPenalty(TS.tokenize('Lady of Arms', 'name'), 0, 'name')).toBe(-25);
});

test('CONST carries the spec 6.4.1 table and constants verbatim', () => {
  const C = TS.CONST;
  expect(C.ROLES.name).toEqual({ min: 0.80, max: 2.30, sat: 1.60, maxLines: 3, lineHeight: 1.12, linePenalty: [0, 30, 60] });
  expect(C.ROLES.award).toEqual({ min: 0.70, max: 1.50, sat: 1.20, maxLines: 2, lineHeight: 1.10, linePenalty: [0, 22, 50] });
  expect(C.ROLES.placeClassic).toEqual({ min: 0.62, max: 1.30, sat: 1.00, maxLines: 1, lineHeight: 1.10, linePenalty: [0] });
  expect(C.ROLES.placeCentered).toEqual({ min: 0.62, max: 1.30, sat: 1.00, maxLines: 2, lineHeight: 1.10, linePenalty: [0, 25] });
  expect(C.ROLES.lead).toMatchObject({ min: 0.70, max: 1.04, maxLines: 1, lineHeight: 1.10 });
  expect(Object.keys(C.ROLES).sort()).toEqual(['award', 'lead', 'name', 'placeCentered', 'placeClassic']);
  expect(C.AWARD_LONG).toEqual({ chars: 40, maxLines: 3 });
  expect(C.VERSAL).toEqual({ min: 1.20, max: 1.495 });
  expect(C.SLACK).toBe(0.025);
  expect(C.RAGGED).toBe(30);
  expect(C.WIDOW).toBe(40);
  expect(C.MINOR_SCALE).toBe(0.72);
  expect(C.MINOR_PENALTY).toBe(6);
  expect(C.MINOR_LINES).toBe(true);
  expect(C.CLASSIC_PLACE_MIN).toBe(0.85);
  expect(C.ORN_GAP).toBe(0.12);
  expect(C.H_TOP_MIN).toBe(0.40);
  expect(C.RATIOS).toEqual({ awardToName: 0.85, placeToName: 0.75, placeToAward: 0.90 });
  expect([C.P_WEAK_END, C.P_PUNCT, C.P_HON_END, C.P_HON_SPLIT, C.P_EPITHET, C.P_SUFFIX, C.P_INITIAL, C.P_AWARD_BEFORE_OF])
    .toEqual([60, -20, -25, 30, -8, 50, 40, -10]);
});
test('CONST word lists carry the spec 6.3 lists', () => {
  const C = TS.CONST;
  expect(C.HONORIFICS).toHaveLength(35);
  expect(C.HONORIFICS).toEqual(expect.arrayContaining(['Sir', 'Dame', 'Syr', 'Knight', 'Marchioness', 'Baronetess']));
  expect(C.HONORIFICS).not.toContain('Grand');
  expect(C.WEAK_END).toHaveLength(39);
  expect(C.WEAK_END).toEqual(expect.arrayContaining(['of', 'the', '&', 'ibn', 'bint', 'mc']));
  expect(C.SUFFIXES).toEqual(expect.arrayContaining(['III', 'IV', 'Jr.', 'Sr.', 'X']));
});

// The Array.from pin: a lone astral capital initial is one character, so it is an initial.
// Under .length it would count two code units and the +40 would silently vanish.
test('an astral capital initial is a lone initial (Array.from, not .length)', () => {
  expect(TS.breakPenalty(TS.tokenize('Kato \u{10400}. Smith', 'name'), 1, 'name')).toBe(40);
});

// ------------------------------------------------------------------ solveBlock

// Fixture measure: half an em per character, a quarter-em space, 100px root. Widths are
// em at 1px, so a line of LW em at size s is LW * s * 100 frame px.
const solve = (text: string, role: string, W: number, over: any = {}) => TS.solveBlock({
  text, role, measure: (w: string) => Array.from(w).length * 0.5, spaceW: 0.25,
  range: TS.CONST.ROLES[role], maxLines: TS.CONST.ROLES[role].maxLines, lineHeight: TS.CONST.ROLES[role].lineHeight,
  y0: 900, rootPx: 100, freeWidth: () => W, ...over });

test('utility saturates', () => {
  const r = TS.CONST.ROLES.name;
  expect(TS.utility(1.6, r)).toBeCloseTo(100); expect(TS.utility(2.3, r)).toBeCloseTo(120); expect(TS.utility(0.8, r)).toBeCloseTo(50);
});
test('a short name stays on one line at its maximum', () => {
  const s = solve('Sir Hillary', 'name', 1683);
  expect(s.planned).toBe(1); expect(s.size).toBeCloseTo(2.3);
});
// PROPOSED FIXTURE (W 1683). The brief's W is 1000, where the 3-line candidate wins under spec 6.3.3:
// ["Nicodemus", "the Rat", "Landcrest"] sets at 2.167 (U 116.2) and scores 61.9, against 48.4 for the best
// two-line cut. At 1683 the two-line cut already sets at 2.05 (score 85.1) and a third line buys nothing.
test('a long name takes two lines rather than one small one, never ending on "the"', () => {
  const s = solve('Nicodemus the Rat Landcrest', 'name', 1683);
  expect(s.planned).toBe(2); expect(s.lines[0].endsWith(' the')).toBe(false);
});
test('an award line never ends on "of"', () => {
  const s = solve('Twilight Peak Guildmaster of Anti-Paladins Winter 2014', 'award', 1100);
  expect(s.planned).toBeGreaterThanOrEqual(2); expect(s.lines.some(l => / of$/.test(l))).toBe(false);
});
test('a quoted nickname is never split', () => {
  const s = solve('William "Billy Bob" FitzRobert', 'name', 700);
  expect(s.lines.some(l => l.includes('"Billy') && !l.includes('Bob"'))).toBe(false);
});
// PROPOSED FIXTURE (W 1683). The brief's W is 1100, where a plain 3-line cut wins under spec 6.3.3:
// ["Sir Lord", "Kato Darkhaven", "Grimwulff"] scores 55.05 (minor-0 variant 54.97), against 47.29 for the
// two-line cut with a minor "Sir Lord". At 1683 the three-line cut is capped at 2.3 and the two-line minor
// cut scores 78.2 against 75.7.
test('an honorific may become a minor line', () => {
  const s = solve('Sir Lord Kato Darkhaven Grimwulff', 'name', 1683);
  expect(s.minor).toBe(0); expect(s.lines[0]).toBe('Sir Lord');
});
test('the line holding most of the characters is never minor', () => {
  const s = solve('Captain Sir Lord Kato Grimwulff', 'name', 1100);   // "Captain Sir Lord" outweighs "Kato Grimwulff"
  expect(s.minor).not.toBe(0);
});
test('minor lines can be switched off', () => {
  const saved = TS.CONST.MINOR_LINES; TS.CONST.MINOR_LINES = false;
  try { expect(solve('Sir Lord Kato Darkhaven Grimwulff', 'name', 1683).minor).toBe(-1); } finally { TS.CONST.MINOR_LINES = saved; }
});
test('a narrower band lowers the size', () => {
  const wide = solve('Lord Robin T. W. Carver, esquire', 'name', 1300);
  const narrow = solve('Lord Robin T. W. Carver, esquire', 'name', 1300, { freeWidth: (a: number, b: number) => (b > 1100 ? 900 : 1300) });
  expect(narrow.size).toBeLessThan(wide.size);
});
test('fallback splits an unbreakable token', () => {
  const s = solve('*Michael_Anthony_Wilder_III', 'name', 500);
  expect(s.overflow).toBe(false); expect(s.planned).toBeGreaterThan(1);
});
test('an impossible block still returns every word, flagged', () => {
  const s = solve('Wilhelmina Featherstonehaugh de la Mancha Wilhelmina Featherstonehaugh', 'name', 120);
  expect(s.overflow).toBe(true); expect(s.size).toBeCloseTo(TS.CONST.ROLES.name.min);
  expect(s.lines.join(' ').replace(/ /g, ' ')).toContain('Featherstonehaugh');
});
test('ties go to fewer lines', () => expect(solve('Halo', 'name', 1683).planned).toBe(1));

// Beyond the brief: the edges that decide whether the solver is right, not just plausible.

const fixture = (over: any = {}) => ({
  measure: (w: string) => Array.from(w).length * 0.5, spaceW: 0.25, y0: 900, rootPx: 100, ...over });

test('utility is a 100-point ramp to sat, then 20 more to max, and refuses a range with no sat', () => {
  const r = { min: 0.8, max: 2.3, sat: 1.6 };
  expect(TS.utility(0, r)).toBe(0);
  expect(TS.utility(1.2, r)).toBeCloseTo(75);
  expect(TS.utility(1.95, r)).toBeCloseTo(110);
  // A ceiling held at or under sat (the hierarchy can do that) never divides by zero.
  expect(TS.utility(1.0, { min: 0.7, max: 1.0, sat: 1.2 })).toBeCloseTo(100 * 1.0 / 1.2);
  expect(() => TS.utility(1, TS.CONST.ROLES.lead)).toThrow();
});
test('nothing to set returns an empty, unflagged block', () => {
  const s = solve('   ', 'name', 1000);
  expect(s.lines).toEqual([]); expect(s.planned).toBe(0); expect(s.overflow).toBe(false); expect(s.minor).toBe(-1);
});
test('the answer is the same every time', () => {
  const a = solve('Lord Robin T. W. Carver, esquire', 'name', 1300), b = solve('Lord Robin T. W. Carver, esquire', 'name', 1300);
  expect(b).toEqual(a);
});
test('every word comes back, in order, with single spaces', () => {
  const text = 'Lord Robin T. W. Carver, esquire';
  const s = solve(text, 'name', 1300);
  expect(s.lines.join(' ')).toBe(text);
  expect(s.lines.length).toBe(s.planned);
});
test('the band asked of freeWidth is the conservative one of spec 6.4.3', () => {
  const asked: number[][] = [];
  solve('Sir Hillary Montgomery Fitzwilliam', 'name', 1000, { freeWidth: (a: number, b: number) => { asked.push([a, b]); return 1000; } });
  // line 0: y0 .. y0 + lh*max; line 1: y0 + lh*min .. y0 + 2*lh*max (rootPx 100, lh 1.12, min .8, max 2.3)
  const near = (x: number[], y: number[]) => Math.abs(x[0] - y[0]) < 1e-6 && Math.abs(x[1] - y[1]) < 1e-6;
  expect(asked.some(b => near(b, [900, 900 + 1.12 * 2.3 * 100]))).toBe(true);
  expect(asked.some(b => near(b, [900 + 1.12 * 0.8 * 100, 900 + 2 * 1.12 * 2.3 * 100]))).toBe(true);
  // each band once, however many candidates use it
  expect(new Set(asked.map(b => b.join(':'))).size).toBe(asked.length);
});
test('a range of its own can carry the per-line-count prices', () => {
  // Equal width lines, a ceiling both cuts reach and no break penalty: the two cuts tie exactly.
  const tied = { min: 0.7, max: 1.5, sat: 1.2, linePenalty: [0, 0, 0] };
  expect(solve('aa bb', 'award', 3000, { range: tied }).planned).toBe(1);
  // The same block with two lines made cheaper than one flips to two: the tie was real.
  expect(solve('aa bb', 'award', 3000, { range: { ...tied, linePenalty: [5, 0, 0] } }).planned).toBe(2);
});
test('an award of 40 characters keeps its two-line cap, one of 41 may take three', () => {
  const forty = 'Guildmaster of Anti-Paladins Winter 2014', forty1 = 'Guildmaster of Anti-Paladins Winter 20145';
  expect(Array.from(forty).length).toBe(40); expect(Array.from(forty1).length).toBe(41);
  const a = solve(forty, 'award', 500), b = solve(forty1, 'award', 500);
  expect(a.planned).toBe(2); expect(a.overflow).toBe(true);   // three lines would fit, but the cap says two
  expect(b.planned).toBe(3); expect(b.overflow).toBe(false);
});
test('a ceiling below the role maximum holds the size under it', () => {
  const s = solve('Halo', 'award', 1683, { range: { ...TS.CONST.ROLES.award, max: 0.9 } });
  expect(s.size).toBeCloseTo(0.9);
});
test('an epithet of two or more words may become a minor last line', () => {
  const s = solve('Alexander Montgomery-Fitzwilliam, the Bold One', 'name', 1683);
  expect(s.lines).toEqual(['Alexander Montgomery-Fitzwilliam,', 'the Bold One']);
  expect(s.minor).toBe(1);
});
test('a one-word honorific line is not a widow once it is minor', () => {
  const s = solve('Sir Kato Darkhaven Grimwulff', 'name', 1400);
  expect(s.lines[0]).toBe('Sir'); expect(s.minor).toBe(0);
  const saved = TS.CONST.MINOR_LINES; TS.CONST.MINOR_LINES = false;
  try { expect(solve('Sir Kato Darkhaven Grimwulff', 'name', 1400).lines[0]).not.toBe('Sir'); } finally { TS.CONST.MINOR_LINES = saved; }
});
test('the heaviest line is never minor on the epithet side either', () => {
  // "the Bold Defender of Dawn" holds far more characters than "Kato,": shrinking it would shrink the name.
  const s = solve('Kato, the Bold Defender of Dawn', 'name', 1000);
  expect(s.minor).not.toBe(1); expect(s.lines).toEqual(['Kato, the Bold', 'Defender of Dawn']);
});
// A wide honorific that is lighter in characters than the rest, so it may be minor and is the binding line.
const wideHonorific = (w: string) => (w === 'Archduchess' ? 9 : Array.from(w).length * 0.5);
test('a minor line is set smaller, so the block can take a size its plain cut cannot', () => {
  // Plain, "Archduchess" needs 9 em at 0.8 = 720px of a 634px line; at 0.72 of that it fits.
  const s = solve('Archduchess Kato Grimwulff', 'name', 650, { measure: wideHonorific });
  expect(s.lines).toEqual(['Archduchess', 'Kato Grimwulff']); expect(s.minor).toBe(0); expect(s.overflow).toBe(false);
});
test('below a minor first line the next band starts higher than uniform spacing says', () => {
  const tops: number[] = [];
  solve('Archduchess Kato Grimwulff', 'name', 650, { measure: wideHonorific, freeWidth: (a: number, b: number) => { tops.push(a); return 650; } });
  // line 1 starts at y0 + (MINOR_SCALE) * lh * min * rootPx once line 0 is minor, rather than y0 + lh * min * rootPx
  expect(tops.some(a => Math.abs(a - (900 + 0.72 * 1.12 * 0.8 * 100)) < 1e-6)).toBe(true);
});
test('split fragments sit against each other with no space, on a line and between lines', () => {
  const s = solve('*Michael_Anthony_Wilder_III', 'name', 500);
  expect(s.lines).toEqual(['*Michael_', 'Anthony_', 'Wilder_III']);
  expect(s.lines.join('')).toBe('*Michael_Anthony_Wilder_III');
});
test('a protected span too wide for any line is cut between its words, not inside one', () => {
  // 31 characters is 15.5 em: 1240px at the minimum, against a 487px line. Each word fits alone.
  const span = '"Ravenhurst Stormcrow Wolfbane"';
  const s = solve(span, 'name', 500);
  expect(s.overflow).toBe(false);
  expect(s.lines).toEqual(['"Ravenhurst', 'Stormcrow', 'Wolfbane"']);
  expect(s.lines.some(l => l.includes('\u00a0'))).toBe(false);   // the no-break spaces became ordinary spaces
  expect(s.lines.join(' ')).toBe(span);
});
test('a parenthetical is cut between its words the same way', () => {
  const s = solve('(Ravenhurst Stormcrow Wolfbane)', 'name', 500);
  expect(s.overflow).toBe(false); expect(s.lines).toEqual(['(Ravenhurst', 'Stormcrow', 'Wolfbane)']);
});
test('a span is cut at its spaces before a word inside it is cut at its hyphen', () => {
  const s = solve('"Montgomery-Fitzwilliam Bold"', 'name', 1000);
  expect(s.lines).toEqual(['"Montgomery-Fitzwilliam', 'Bold"']);
  // Narrower, the hyphenated word no longer fits either, and the next pass cuts it.
  const t = solve('"Montgomery-Fitzwilliam Bold"', 'name', 600);
  expect(t.overflow).toBe(false); expect(t.lines).toEqual(['"Montgomery-', 'Fitzwilliam', 'Bold"']);
});
test('a protected span that fits on a line is left whole', () => {
  const s = solve('Kato "Billy Bob" Smith', 'name', 1683);
  expect(s.lines.some(l => l.includes('"Billy\u00a0Bob"'))).toBe(true);
});
test('camelCase seams are cut before any hard split', () => {
  const s = solve('AlexanderWilhelmFitzgerald', 'name', 500);
  expect(s.lines).toEqual(['Alexander', 'Wilhelm', 'Fitzgerald']); expect(s.overflow).toBe(false);
});
test('a seamless word is halved only when nothing gentler fits', () => {
  const s = solve('Abcdefghijklmnopqrstuvwxyz', 'name', 700);
  expect(s.lines).toEqual(['Abcdefghijklm', 'nopqrstuvwxyz']); expect(s.overflow).toBe(false);
});
test('when even a split block cannot fit, the flagged answer is the unsplit words', () => {
  // Pieces of "*Michael_Anthony_Wilder_III" still overflow 100px, so cutting would only mutilate it.
  const s = solve('*Michael_Anthony_Wilder_III', 'name', 100);
  expect(s.overflow).toBe(true); expect(s.lines).toEqual(['*Michael_Anthony_Wilder_III']);
  expect(s.size).toBeCloseTo(0.8);
  const t = solve('Abcdefghijklmnopqrstuvwxyz', 'name', 500);
  expect(t.overflow).toBe(true); expect(t.lines).toEqual(['Abcdefghijklmnopqrstuvwxyz']);
  // A span is put back whole too: its no-break spaces return with it.
  const u = solve('"Ravenhurst Stormcrow Wolfbane"', 'name', 100);
  expect(u.overflow).toBe(true); expect(u.lines).toEqual(['"Ravenhurst\u00a0Stormcrow\u00a0Wolfbane"']);
});
test('an overflowing block is flagged with the narrowest cut, not the cheapest', () => {
  // Nothing fits at 120px; one line would overflow worst, so the flagged answer spreads over three.
  const s = solve('Wilhelmina Featherstonehaugh de la Mancha Wilhelmina Featherstonehaugh', 'name', 120);
  expect(s.planned).toBe(3);
});
test('a protected span survives as one token through the solver', () => {
  const s = solve('Sir "Billy Bob" Fitzgerald-Montgomery', 'name', 900);
  expect(s.lines.some(l => l.includes('"Billy Bob"'))).toBe(true);
});
test('fifty tokens solve well inside a millisecond budget', () => {
  const words = Array.from({ length: 50 }, (_, i) => 'w' + 'x'.repeat(i % 4)).join(' ');
  const t0 = Date.now(); const s = solve(words, 'name', 1683); const ms = Date.now() - t0;
  expect(s.lines.join(' ')).toBe(words); expect(ms).toBeLessThan(250);
});
