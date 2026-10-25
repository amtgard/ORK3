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
