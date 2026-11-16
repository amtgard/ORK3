const test = require('node:test');
const assert = require('node:assert/strict');
const C = require('../../orkui/template/revised-frontend/script/event-board/evb-core.js');

const DAYS = ['2026-11-20', '2026-11-21', '2026-11-22'];
const ctx = more => Object.assign({ days: DAYS, day: '2026-11-21', lastEnd: null, lastDuration: 60 }, more);
const p = (text, more) => C.parseEntry(text, ctx(more));
const pick = (r, keys) => keys.map(k => r[k]);

test('the examples from the design', () => {
  let r = p('sat 9a tavern opens');
  assert.deepEqual(pick(r, ['ok', 'day', 'startMin', 'endMin', 'title', 'category']), [true, '2026-11-21', 540, 600, 'tavern opens', 'Feast and Food']);
  assert.deepEqual([r.given.day, r.given.time, r.given.category], [true, true, false]);
  assert.deepEqual([r.start, r.end], ['2026-11-21 09:00', '2026-11-21 10:00']);
  r = p('9:30-10:15 battlegame: squatch ball at main field', { locations: ['Main Field'] });
  assert.deepEqual(pick(r, ['startMin', 'endMin', 'title', 'category', 'location']), [570, 615, 'squatch ball', 'Battlegame', 'Main Field']);
  assert.equal(p('9:30-10:15 bg: squatch ball at main field').location, 'main field');
  r = p('then 30m rp quest starts', { lastEnd: { day: '2026-11-21', min: 615 } });
  assert.deepEqual(pick(r, ['day', 'startMin', 'endMin', 'title', 'category']), ['2026-11-21', 615, 645, 'rp quest starts', 'Roleplay and Quests']);
  r = p('Friday Battlegame 3:00-4:00 at Main Field');
  assert.deepEqual(pick(r, ['day', 'startMin', 'endMin', 'title', 'category', 'location']), ['2026-11-20', 900, 960, 'Battlegame', 'Battlegame', 'Main Field']);
  r = p('3-4 Naalbinding Class "Come learn the Viking art of Naalbinding."');
  assert.deepEqual(pick(r, ['startMin', 'endMin', 'title', 'category', 'description']), [900, 960, 'Naalbinding Class', 'Class', 'Come learn the Viking art of Naalbinding.']);
});
test('am and pm', () => {
  const t = s => pick(p(s + ' x'), ['startMin', 'endMin']);
  assert.deepEqual(t('9-12'), [540, 720]);
  assert.deepEqual(t('10-1'), [600, 780]);
  assert.deepEqual(t('3-4'), [900, 960]);
  assert.deepEqual(t('10-1p'), [600, 780]);
  assert.deepEqual(t('8-9p'), [1200, 1260]);
  assert.deepEqual(t('11-12p'), [660, 720]);
  assert.deepEqual(t('7p-9'), [1140, 1260]);
  assert.deepEqual(t('9a-12p'), [540, 720]);
  assert.deepEqual(t('3p-4:30p'), [900, 990]);
  assert.deepEqual(t('10 to 1'), [600, 780]);
  assert.deepEqual(t('10p-1a'), [1320, 1500]);
  assert.deepEqual(t('15:00'), [900, 960]);
  assert.deepEqual(t('noon'), [720, 780]);
  assert.deepEqual(t('3p'), [900, 960]);
});
test('after midnight belongs to the named day’s night', () => {
  const r = p('sat 12:30a night quest');
  assert.deepEqual(pick(r, ['day', 'startMin', 'endMin', 'start', 'end', 'category']),
    ['2026-11-21', 1470, 1530, '2026-11-22 00:30', '2026-11-22 01:30', 'Roleplay and Quests']);
});
test('lengths, then, and defaults', () => {
  const last = { lastEnd: { day: '2026-11-20', min: 870 }, lastDuration: 45 };
  assert.deepEqual(pick(p('45m mystery', last), ['day', 'startMin', 'endMin']), ['2026-11-20', 870, 915]);
  assert.deepEqual(pick(p('1h30m mystery', last), ['startMin', 'endMin']), [870, 960]);
  assert.deepEqual(pick(p('1.5h mystery', last), ['startMin', 'endMin']), [870, 960]);
  assert.deepEqual(pick(p('+45 mystery', last), ['startMin', 'endMin']), [870, 915]);
  assert.deepEqual(pick(p('mystery', last), ['day', 'startMin', 'endMin']), ['2026-11-20', 870, 915]);
  assert.deepEqual(pick(p('2p mystery', last), ['day', 'startMin', 'endMin']), ['2026-11-21', 840, 885]);
  assert.deepEqual(pick(p('opening'), ['day', 'startMin', 'endMin']), ['2026-11-21', 540, 600]);
  assert.equal(p('mystery', last).given.time, false);
});
test('days', () => {
  assert.equal(p('sun 9a clean').day, '2026-11-22');
  assert.equal(p('11/22 9a clean').day, '2026-11-22');
  assert.equal(p('mon 9a clean').day, '2026-11-23');
  assert.deepEqual(pick(p('Saturday:'), ['ok', 'setDay']), [false, '2026-11-21']);
  assert.deepEqual(pick(p('fri'), ['ok', 'setDay']), [false, '2026-11-20']);
  assert.deepEqual(pick(p('sat 9a'), ['ok', 'setDay']), [false, null]);
});
test('inside a block the day is fixed and the block keeps its length', () => {
  const blk = { fixedDay: '2026-11-21', fixedStart: 720, fixedEnd: 765 };
  assert.deepEqual(pick(p('sunday brunch', blk), ['day', 'startMin', 'endMin', 'title', 'category']), ['2026-11-21', 720, 765, 'sunday brunch', 'Feast and Food']);
  assert.deepEqual(pick(p('1p Mothman', blk), ['startMin', 'endMin', 'title']), [780, 825, 'Mothman']);
  assert.deepEqual(pick(p('1-3 Mothman', blk), ['startMin', 'endMin']), [780, 900]);
});
test('words that only look like grammar stay in the title', () => {
  let r = p('Level 1-3 Tournament');
  assert.deepEqual([r.title, r.given.time, r.category], ['Level 1-3 Tournament', false, 'Tournament']);
  r = p('Sir Adler: Train the Trainer Pt3');
  assert.deepEqual([r.title, r.category, r.given.category], ['Sir Adler: Train the Trainer Pt3', 'Other', false]);
  assert.equal(p('KL Tournament: Single Short').title, 'KL Tournament: Single Short');
  assert.equal(p('#1 Draft Pick').title, '#1 Draft Pick');
  assert.equal(p('5 pumpkins').title, '5 pumpkins');
  r = p('Court at 6pm');
  assert.deepEqual([r.title, r.location, r.startMin], ['Court', '', 1080]);
  r = p('feast $8');
  assert.deepEqual([r.title, r.cost], ['feast $8', null]);
});
test('tags, prefixes, location, description and cost', () => {
  let r = p('#bg Mothman');
  assert.deepEqual([r.title, r.category, r.given.category], ['Mothman', 'Battlegame', true]);
  r = p('#class #feast Cooking');
  assert.deepEqual([r.title, r.category, r.secondaryCategory], ['Cooking', 'Class', 'Feast and Food']);
  assert.equal(p('#tourn Finals').category, 'Tournament');
  r = p('CLASS: Sir Adler - Train the Trainer Pt3');
  assert.deepEqual([r.title, r.category], ['Sir Adler - Train the Trainer Pt3', 'Class']);
  r = p('Meet at the Gate 5p');
  assert.deepEqual([r.title, r.location, r.startMin], ['Meet', 'the Gate', 1020]);
  r = p('Meet at the Gate 5p', { ignore: { location: true } });
  assert.deepEqual([r.title, r.location, r.category], ['Meet at the Gate', '', 'Administrative']);
  r = p('Naalbinding Class “Come learn');
  assert.deepEqual([r.title, r.description, r.given.description], ['Naalbinding Class', 'Come learn', true]);
  r = p('5-6 feast $8.50 at Hall', { canFeast: true });
  assert.deepEqual([r.title, r.cost, r.location, r.category], ['feast', 8.5, 'Hall', 'Feast and Food']);
  assert.equal(p('5-6 mothman $8', { canFeast: true }).cost, null);
});
test('guessCategory follows the owner’s rulings', () => {
  const g = t => C.guessCategory(t).category;
  assert.equal(g('Ditch'), 'Battlegame');
  assert.equal(g('Tavern Opens'), 'Feast and Food');
  assert.equal(g('Troll Opens'), 'Administrative');
  assert.equal(g('RP Quest Starts'), 'Roleplay and Quests');
  assert.equal(g('Night Quest!'), 'Roleplay and Quests');
  assert.equal(g('A&S Judging Begins'), 'Arts and Sciences');
  assert.equal(g('Court and the Knighting of Nip'), 'Court');
  assert.equal(g('Ceremony of Oaths'), 'Court');
  assert.equal(g('Crats on site ONLY'), 'Administrative');
  assert.equal(g("Cadellia's Vigil"), null);
  assert.equal(g('Classic Car Show'), null);
  assert.deepEqual(C.guessCategory('BATTLEGAME: Mothman'), { category: 'Battlegame', title: 'Mothman' });
  assert.equal(C.resolveCategoryTag('rp'), 'Roleplay and Quests');
  assert.equal(C.resolveCategoryTag('nope'), null);
});
test('formatEntry reads back through parseEntry', () => {
  const items = [
    { Title: 'Squatch Ball', Category: 'Battlegame', StartTime: '2026-11-21 09:30:00', EndTime: '2026-11-21 10:15:00', Location: 'Main Field', Description: 'Bring water' },
    { Title: 'Night Quest!', Category: 'Roleplay and Quests', StartTime: '2026-11-22 00:30:00', EndTime: '2026-11-22 01:30:00', Location: '', Description: '' },
    { Title: 'Lunch', Category: 'Feast and Food', StartTime: '2026-11-21 12:00:00', EndTime: '2026-11-21 13:00:00', Location: '', Description: '' },
  ];
  assert.equal(C.formatEntry(items[0]), '9:30–10:15a #bg Squatch Ball at Main Field "Bring water"');
  assert.equal(C.formatEntry(items[2]), '12–1p Lunch');
  assert.equal(C.formatEntry(items[0], { withDay: true }).slice(0, 4), 'sat ');
  items.forEach(it => {
    const r = C.parseEntry(C.formatEntry(it, { withDay: true }), { days: DAYS });
    assert.deepEqual([r.title, r.category, r.location, r.description, r.start + ':00', r.end + ':00'],
      [it.Title, it.Category, it.Location, it.Description, it.StartTime, it.EndTime]);
  });
});
test('"at" before a bare hour is a time', () => {
  let r = p('Court at 6');
  assert.deepEqual([r.title, r.startMin, r.location, r.given.time], ['Court', 1080, '', true]);
  r = p('Feast at 6 in the Hall');
  assert.deepEqual([r.title, r.startMin, r.location], ['Feast in the Hall', 1080, '']);
  r = p('Class 101: Intro at 3');
  assert.deepEqual([r.title, r.startMin], ['Class 101: Intro', 900]);
  r = p('Hall at 7');
  assert.deepEqual([r.title, r.startMin], ['Hall', 420]);
  r = p('Meet at the Gate 5p');
  assert.deepEqual([r.location, r.startMin], ['the Gate', 1020]);
  r = p('Court at 6 9-10');
  assert.deepEqual([r.startMin, r.endMin], [540, 600]);
  assert.ok(r.title.includes('Court'));
});
test('a typed day resets the clock for lengths and then', () => {
  const last = { lastEnd: { day: '2026-11-20', min: 870 } };
  assert.deepEqual(pick(p('sun 30m x', last), ['day', 'startMin', 'endMin']), ['2026-11-22', 540, 570]);
  assert.deepEqual(pick(p('sun then x', last), ['day', 'startMin']), ['2026-11-22', 540]);
  assert.deepEqual(pick(p('fri 30m x', last), ['day', 'startMin', 'endMin']), ['2026-11-20', 870, 900]);
});
test('a rejected cost does not confuse the prefix', () => {
  let r = p('$8 feast: dinner');
  assert.deepEqual([r.title, r.given.category, r.category], ['$8 feast: dinner', false, 'Feast and Food']);
  r = p('feast: dinner $8');
  assert.deepEqual([r.title, r.category, r.given.category], ['dinner $8', 'Feast and Food', true]);
});
test('description whitespace collapses', () => {
  assert.equal(p('x "Come\tlearn   now"').description, 'Come learn now');
});
test('equal sides are zero length and absurd lengths are text', () => {
  assert.deepEqual(pick(p('9a-9a x'), ['startMin', 'endMin']), [540, 540]);
  assert.deepEqual(pick(p('12a-12a x'), ['startMin', 'endMin']), [1440, 1440]);
  assert.deepEqual(pick(p('0-0 x'), ['startMin', 'endMin']), [1440, 1440]);
  const r = p('99h x');
  assert.deepEqual([r.title, r.given.time], ['99h x', false]);
  const d = p('24h x');
  assert.equal(d.endMin - d.startMin, 1440);
});
test('formatEntry with no title ends cleanly', () => {
  const base = { Category: 'Battlegame', StartTime: '2026-11-21 09:00:00', EndTime: '2026-11-21 10:00:00' };
  assert.equal(C.formatEntry(Object.assign({ Title: '' }, base)), '9–10a #bg');
  assert.equal(C.formatEntry(base), '9–10a #bg');
});
