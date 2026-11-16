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
test('a tapped slot line, with a title typed after it, parses to the tapped day and minute', () => {
  for (const [day, min] of [['2026-11-20', 825], ['2026-11-21', 600], ['2026-11-21', 1470], ['2026-11-22', 720]]) {
    const line = C.slotLine(day, min);
    const r = p(line + 'Archery practice');
    assert.equal(r.ok, true, line);
    assert.deepEqual(pick(r, ['day', 'startMin', 'title']), [day, min, 'Archery practice'], line);
  }
  assert.equal(C.slotLine('2026-11-20', 825), 'Friday 1:45p ');
});
test('a slot line with an end keeps the range', () => {
  const r = p(C.slotLine('2026-11-21', 840, 900) + '2-3');
  assert.deepEqual(pick(r, ['day', 'startMin', 'endMin']), ['2026-11-21', 840, 900]);
  const z = p(C.slotLine('2026-11-21', 840, 840) + 'Bell');
  assert.deepEqual(pick(z, ['startMin', 'endMin', 'title']), [840, 840, 'Bell']);
});
test('inside a block, noon and midnight are words unless they follow at', () => {
  const blk = { fixedDay: '2026-11-21', fixedStart: 600, fixedEnd: 660 };
  assert.deepEqual(pick(p('Midnight Madness', blk), ['startMin', 'endMin', 'title']), [600, 660, 'Midnight Madness']);
  assert.deepEqual(pick(p('Noon Court', blk), ['startMin', 'endMin', 'title']), [600, 660, 'Noon Court']);
  assert.deepEqual(pick(p('Court of Noon', blk), ['startMin', 'title']), [600, 'Court of Noon']);
  assert.deepEqual(pick(p('Feast at noon', blk), ['startMin', 'endMin', 'title']), [720, 780, 'Feast']);
  assert.deepEqual(pick(p('Revel at midnight', blk), ['startMin', 'title']), [1440, 'Revel']);
  // The quick line keeps reading a lone noon or midnight at either end as a time.
  assert.deepEqual(pick(p('noon Court'), ['startMin', 'title']), [720, 'Court']);
  assert.deepEqual(pick(p('Court noon'), ['startMin', 'title']), [720, 'Court']);
});

const STAFF = [
  { MundaneId: 1, Persona: 'Luna', RoleName: 'A&S', LeadCategories: ['Class'] },
  { MundaneId: 2, Persona: 'Luna Honeybadger', RoleName: 'Warcrat', LeadCategories: ['Battlegame', 'Class'] },
  { MundaneId: 3, Persona: 'Sir "Quote" <b>', RoleName: 'Gate', LeadCategories: [] },
];
test('@ matches the longest staff persona and never stays in the title', () => {
  let r = p('sat 9a bg: Mothman @luna honeybadger at Field', { staff: STAFF });
  assert.deepEqual([r.title, r.location, r.leads.map(l => l.MundaneId), r.unmatchedLeads, r.given.leads], ['Mothman', 'Field', [2], [], true]);
  r = p('9a Class @Luna @nobody here', { staff: STAFF });
  assert.deepEqual([r.title, r.leads.map(l => l.MundaneId), r.unmatchedLeads], ['Class here', [1], ['nobody']]);
  r = p('9a Mail crat@example.org about gate', { staff: STAFF });
  assert.deepEqual([r.title, r.leads, r.given.leads], ['Mail crat@example.org about gate', [], false]);
  r = p('9a Gate @sir "quote" <b>', { staff: STAFF });
  assert.deepEqual([r.leads.map(l => l.MundaneId), r.title, r.description], [[3], 'Gate', '']);
  assert.deepEqual(p('9a Mothman', { staff: STAFF }).leads, []);
});
test('category leads and swapping', () => {
  assert.deepEqual(C.leadsForCategory(STAFF, 'Class').map(l => [l.MundaneId, l.Auto]), [[1, true], [2, true]]);
  assert.deepEqual(C.leadsForCategory(STAFF, 'Court'), []);
  const leads = [{ MundaneId: 2, Persona: 'Luna Honeybadger', Auto: true }, { MundaneId: 9, Persona: 'Hand Picked', Auto: false }];
  assert.deepEqual(C.swapAutoLeads(leads, STAFF, 'Class').map(l => [l.MundaneId, !!l.Auto]), [[9, false], [1, true], [2, true]]);
  assert.deepEqual(C.swapAutoLeads(leads, STAFF, 'Court').map(l => l.MundaneId), [9]);
  const handIsAlsoLead = [{ MundaneId: 1, Persona: 'Luna', Auto: false }];
  assert.deepEqual(C.swapAutoLeads(handIsAlsoLead, STAFF, 'Class').map(l => [l.MundaneId, !!l.Auto]), [[1, false], [2, true]]);
});
test('formatEntry writes leads and they read back for staff', () => {
  const it = { Title: 'Mothman', Category: 'Battlegame', StartTime: '2026-11-21 09:30:00', EndTime: '2026-11-21 10:15:00', Location: '', Description: '', Leads: [{ MundaneId: 2, Persona: 'Luna Honeybadger' }] };
  assert.equal(C.formatEntry(it), '9:30–10:15a #bg Mothman @Luna Honeybadger');
  assert.deepEqual(C.parseEntry(C.formatEntry(it), { days: DAYS, staff: STAFF }).leads.map(l => l.MundaneId), [2]);
});
test('four-digit 24-hour ranges; a lone four-digit number is never a time', () => {
  assert.deepEqual(pick(p('0900-1000 Archery'), ['startMin', 'endMin', 'title']), [540, 600, 'Archery']);
  assert.deepEqual(pick(p('0900–1000 Archery'), ['startMin', 'endMin', 'title']), [540, 600, 'Archery']);
  assert.deepEqual(pick(p('0900 - 1000 Archery'), ['startMin', 'endMin', 'title']), [540, 600, 'Archery']);
  assert.deepEqual(pick(p('Archery 1330-1415'), ['startMin', 'endMin', 'title']), [810, 855, 'Archery']);
  assert.deepEqual(pick(p('0030-0130 Night Watch'), ['day', 'startMin', 'endMin']), ['2026-11-21', 1470, 1530]);
  assert.deepEqual(pick(p('2026 Kickoff 9a'), ['title', 'startMin']), ['2026 Kickoff', 540]);
  let r = p('sat 1330 Court');
  assert.deepEqual([r.day, r.title, r.given.time], ['2026-11-21', '1330 Court', false]);
  assert.deepEqual(pick(p('2026-2027 Season Kickoff 9a'), ['title', 'startMin']), ['2026-2027 Season Kickoff', 540]);
  r = p('2026-2027 Season Kickoff');  // 20:26 to 20:27 is under five minutes
  assert.deepEqual([r.title, r.given.time], ['2026-2027 Season Kickoff', false]);
  r = p('Points 1000-2000 Kickoff');   // mid-line, so not a time
  assert.deepEqual([r.title, r.given.time], ['Points 1000-2000 Kickoff', false]);
  r = p('2300-0100 Vigil');            // the end must be after the start
  assert.equal(r.given.time, false);
  assert.deepEqual(pick(p('Class of 1999 reunion 5p'), ['title', 'startMin']), ['Class of 1999 reunion', 1020]);
  assert.deepEqual(pick(p('1984 Night 8p'), ['title', 'startMin']), ['1984 Night', 1200]);
});

// Final review fixes.
test('a secondary category is written as a second #tag and reads back', () => {
  const it = { Title: 'Cooking', Category: 'Class', SecondaryCategory: 'Feast and Food',
    StartTime: '2026-11-21 10:00', EndTime: '2026-11-21 11:00', Location: '', Description: '', Leads: [] };
  const line = C.formatEntry(it);
  assert.equal(line, '10–11a #class #feast Cooking');
  const r = p(line);
  assert.deepEqual([r.title, r.category, r.secondaryCategory, r.start, r.end],
    ['Cooking', 'Class', 'Feast and Food', '2026-11-21 10:00', '2026-11-21 11:00']);
  const back = C.parseLines(C.scheduleText([it]), { days: DAYS });
  assert.deepEqual(back.items.map(i => [i.Title, i.Category, i.SecondaryCategory]), [['Cooking', 'Class', 'Feast and Food']]);
  // Without a secondary nothing changes: a title that reads as its own category has no tag.
  assert.equal(C.formatEntry(Object.assign({}, it, { Title: 'Class', SecondaryCategory: '' })), '10–11a Class');
});
test('punctuation right after a matched lead goes with it', () => {
  const staff = [{ MundaneId: 2, Persona: 'Luna' }, { MundaneId: 3, Persona: 'Kai' }];
  let r = p('9a Class @Luna, @Kai', { staff });
  assert.deepEqual([r.title, r.leads.map(l => l.MundaneId)], ['Class', [2, 3]]);
  r = p('9a Class with @Luna.', { staff });
  assert.deepEqual([r.title, r.leads.map(l => l.MundaneId)], ['Class with', [2]]);
  r = p('9a Talk @Luna;@Kai: bring water', { staff });
  assert.deepEqual([r.title, r.leads.map(l => l.MundaneId)], ['Talk bring water', [2, 3]]);
});
