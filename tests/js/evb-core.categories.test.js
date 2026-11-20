const test = require('node:test');
const assert = require('node:assert/strict');
const C = require('../../orkui/template/revised-frontend/script/event-board/evb-core.js');

const DAYS = ['2026-11-20', '2026-11-21', '2026-11-22'];
const TEN = [
  { name: 'Administrative', tag: 'admin', aliases: ['admin'], keywords: ['troll', 'gate', 'setup', 'set up', 'clean', 'cleanup', 'teardown', 'tear down', 'check-in', 'checkin', 'offsite', 'crat'] },
  { name: 'Tournament', tag: 'tourney', aliases: ['tourney'], keywords: ['tournament', 'tourney', 'warmaster', 'weaponmaster', 'bracket'] },
  { name: 'Battlegame', tag: 'bg', aliases: ['bg', 'battle game'], keywords: ['battlegame', 'battle game', 'bg', 'militia', 'ditch'] },
  { name: 'Arts and Sciences', tag: 'as', aliases: ['as', 'a&s'], keywords: ['a&s', 'arts and sciences', 'judging', 'dragonmaster'] },
  { name: 'Class', tag: 'class', aliases: [], keywords: ['class', 'workshop', 'seminar'] },
  { name: 'Feast and Food', tag: 'feast', aliases: ['feast', 'food'], keywords: ['feast', 'dinner', 'lunch', 'breakfast', 'brunch', 'potluck', 'tavern'] },
  { name: 'Court', tag: 'court', aliases: [], keywords: ['court', 'coronation', 'knighting', 'ceremony', 'oath'] },
  { name: 'Meeting', tag: 'meeting', aliases: [], keywords: ['meeting', 'althing', 'allthing'] },
  { name: 'Roleplay and Quests', tag: 'rp', aliases: ['rp', 'roleplay', 'quest'], keywords: ['quest', 'rp', 'roleplay', 'role play'] },
  { name: 'Other', tag: 'other', aliases: [], keywords: [] },
];
const snapshot = () => JSON.stringify([C.CATEGORY_NAMES, C.CATEGORY_TAGS, C.resolveCategoryTag('rp'), C.guessCategory('A&S Setup Begins')]);

test('setCategories adds a server category everywhere and resetCategories restores the defaults', () => {
  const names = C.CATEGORY_NAMES, tags = C.CATEGORY_TAGS, before = snapshot();
  try {
    assert.equal(C.setCategories(TEN.concat([{ name: 'Ritual', tag: 'ritual', aliases: ['rite'], keywords: ['ritual'] }])), true);
    // The exported tables are the same objects, changed in place.
    assert.equal(C.CATEGORY_NAMES, names);
    assert.equal(C.CATEGORY_TAGS, tags);
    assert.equal(names.length, 11);
    assert.equal(tags.Ritual, 'ritual');
    assert.equal(C.resolveCategoryTag('ritual'), 'Ritual');
    assert.equal(C.resolveCategoryTag('#rite'), 'Ritual');
    assert.equal(C.guessCategory('Evening Ritual').category, 'Ritual');
    const it = { Title: 'Fire Circle', Category: 'Ritual', StartTime: '2026-11-21 20:00', EndTime: '2026-11-21 21:00' };
    const line = C.formatEntry(it);
    assert.equal(line, '8–9p #ritual Fire Circle');
    const r = C.parseEntry(line, { days: DAYS, day: '2026-11-21' });
    assert.deepEqual([r.title, r.category, r.start, r.end], ['Fire Circle', 'Ritual', '2026-11-21 20:00', '2026-11-21 21:00']);
    assert.equal(C.parseEntry('9a #rite Dawn', { days: DAYS }).category, 'Ritual');
    // The built-in keyword priority still holds for the known categories.
    assert.equal(C.guessCategory('A&S Setup Begins').category, 'Arts and Sciences');
    // Entries without aliases or keywords get empty lists; Name is accepted.
    assert.equal(C.setCategories([{ Name: 'Solo', tag: 'solo' }, { name: 'Other', tag: 'other' }]), true);
    assert.deepEqual(names, ['Solo', 'Other']);
    assert.equal(C.resolveCategoryTag('solo'), 'Solo');
    assert.equal(C.guessCategory('Anything').category, null);
  } finally {
    C.resetCategories();
  }
  assert.equal(snapshot(), before);
  assert.equal(C.CATEGORY_NAMES, names);
  assert.equal(C.resolveCategoryTag('ritual'), null);
});

test('setCategories ignores a bad argument', () => {
  const before = snapshot();
  for (const bad of [null, undefined, [], 'Court', {}, [{ name: 'NoTag' }], [{ tag: 'notname' }],
    [{ name: 'Two Words', tag: 'two words' }], TEN.concat([null]), [{ name: 'A', tag: 'a' }, { name: 'A', tag: 'b' }],
    // No 'Other': parseEntry and formatEntry fall back to it.
    [{ name: 'Solo', tag: 'solo' }], TEN.filter(c => c.name !== 'Other')]) {
    assert.equal(C.setCategories(bad), false, JSON.stringify(bad));
    assert.equal(snapshot(), before, JSON.stringify(bad));
  }
});

test('the page’s own config shape (name plus the server’s new keys) is accepted', () => {
  try {
    const cfg = TEN.map(c => ({ name: c.name, key: '1', icon: 'fa-star', color: '#000', tag: c.tag, aliases: c.aliases, keywords: c.keywords }));
    assert.equal(C.setCategories(cfg), true);
    assert.equal(C.guessCategory('Tavern Opens').category, 'Feast and Food');
    assert.equal(C.resolveCategoryTag('bg'), 'Battlegame');
  } finally {
    C.resetCategories();
  }
});

test('keywordRank on every entry sets the guessing order', () => {
  const RANK = { 'Arts and Sciences': 1, 'Feast and Food': 2, 'Court': 3, 'Tournament': 4, 'Class': 5,
    'Roleplay and Quests': 6, 'Battlegame': 7, 'Meeting': 8, 'Administrative': 9, 'Other': 10 };
  const ranked = TEN.map(c => Object.assign({ keywordRank: RANK[c.name] }, c));
  try {
    // The server's ranks match the built-in order: nothing changes.
    assert.equal(C.setCategories(ranked), true);
    assert.equal(C.guessCategory('A&S Setup Begins').category, 'Arts and Sciences');
    assert.equal(C.guessCategory('Court and the Knighting of Nip').category, 'Court');
    // An eleventh category ranked 0 beats Arts and Sciences on a shared word.
    assert.equal(C.setCategories(ranked.concat([{ name: 'Judges', tag: 'judges', keywords: ['judging'], keywordRank: 0 }])), true);
    assert.equal(C.guessCategory('A&S Judging Begins').category, 'Judges');
    // Without a rank on every entry, the fallback order holds and the newcomer guesses last.
    assert.equal(C.setCategories(ranked.concat([{ name: 'Judges', tag: 'judges', keywords: ['judging'] }])), true);
    assert.equal(C.guessCategory('A&S Judging Begins').category, 'Arts and Sciences');
  } finally {
    C.resetCategories();
  }
  assert.equal(C.guessCategory('A&S Judging Begins').category, 'Arts and Sciences');
});
