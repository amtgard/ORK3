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
  // A leading '#tag' does not hide the day word behind it.
  assert.deepEqual(pick(p('#bg sun clean'), ['day', 'title', 'category']), ['2026-11-22', 'clean', 'Battlegame']);
  assert.equal(p('#bg sun clean').given.day, true);
  assert.deepEqual(pick(p('#bg sun 9a clean'), ['day', 'startMin', 'title']), ['2026-11-22', 540, 'clean']);
  assert.deepEqual(pick(p('#bg Troll every day'), ['title']), ['Troll']);
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
test('a cost above the DECIMAL(8,2) column is not claimed', () => {
  assert.equal(p('5-6 feast $999999.99', { canFeast: true }).cost, 999999.99);
  const r = p('5-6 feast $1000000', { canFeast: true });
  assert.deepEqual([r.title, r.cost, r.given.cost, r.category], ['feast $1000000', null, false, 'Feast and Food']);
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
  // 'with' before a staff member is a cue for the lead, and goes with it.
  r = p('9a Class with @Luna.', { staff });
  assert.deepEqual([r.title, r.leads.map(l => l.MundaneId)], ['Class', [2]]);
  r = p('9a Talk @Luna;@Kai: bring water', { staff });
  assert.deepEqual([r.title, r.leads.map(l => l.MundaneId)], ['Talk bring water', [2, 3]]);
});
test('all day is 7a to 7p, and spans across days read from the line', () => {
  const t = (s, more) => { const r = p(s, Object.assign({ eventDays: DAYS }, more)); return [r.start, r.end, r.title, r.given.day, r.given.time]; };
  assert.deepEqual(t('Troll Open all day'), ['2026-11-21 07:00', '2026-11-21 19:00', 'Troll Open', false, true]);
  assert.deepEqual(t('All-day Troll'), ['2026-11-21 07:00', '2026-11-21 19:00', 'Troll', false, true]);
  assert.deepEqual(t('sun troll all day long'), ['2026-11-22 07:00', '2026-11-22 19:00', 'troll', true, true]);
  assert.deepEqual(t('Troll Open Friday all day'), ['2026-11-20 07:00', '2026-11-20 19:00', 'Troll Open', true, true]);
  assert.deepEqual(t('All Saturday Troll'), ['2026-11-21 07:00', '2026-11-21 19:00', 'Troll', true, true]);
  assert.deepEqual(t('Troll all weekend'), ['2026-11-20 07:00', '2026-11-22 19:00', 'Troll', true, true]);
  assert.deepEqual(t('Troll Open whole event long'), ['2026-11-20 07:00', '2026-11-22 19:00', 'Troll Open', true, true]);
  assert.deepEqual(t('Saturday through Sunday Troll'), ['2026-11-21 07:00', '2026-11-22 19:00', 'Troll', true, true]);
  assert.deepEqual(t('Sat-Sun Troll'), ['2026-11-21 07:00', '2026-11-22 19:00', 'Troll', true, true]);
  assert.deepEqual(t('all day sat thru sun troll'), ['2026-11-21 07:00', '2026-11-22 19:00', 'troll', true, true]);
  assert.deepEqual(t('9:00am Saturday to 2:00pm Sunday Troll'), ['2026-11-21 09:00', '2026-11-22 14:00', 'Troll', true, true]);
  assert.deepEqual(t('Sat 9a - Sun 2p Troll'), ['2026-11-21 09:00', '2026-11-22 14:00', 'Troll', true, true]);
  assert.deepEqual(t('9a to sun 2p troll'), ['2026-11-21 09:00', '2026-11-22 14:00', 'troll', true, true]);
  assert.deepEqual(t('fri 10p to sat 2a night watch'), ['2026-11-20 22:00', '2026-11-21 02:00', 'night watch', true, true]);
  // An end before the start is not a span; the rest of the line is title text.
  assert.equal(p('Sun 2p to Sat 9a x').end, '2026-11-22 15:00');
  // Ordinary ranges are untouched.
  assert.deepEqual(t('sat 9 - 10 rp').slice(0, 3), ['2026-11-21 09:00', '2026-11-21 10:00', 'rp']);
  assert.deepEqual(t('10 to 1 x').slice(0, 2), ['2026-11-21 10:00', '2026-11-21 13:00']);
  // A dismissed time or day; a fixed block day keeps a plain 'all day' only.
  assert.equal(p('Troll all day', { ignore: { time: true } }).title, 'Troll all day');
  assert.deepEqual(pick(p('All Saturday Troll', { ignore: { day: true } }), ['title']), ['All Saturday Troll']);
  const blk = { fixedDay: '2026-11-20', fixedStart: 720, fixedEnd: 765 };
  assert.deepEqual(pick(p('Troll all day', blk), ['day', 'startMin', 'endMin', 'title']), ['2026-11-20', 420, 1140, 'Troll']);
});
test('the time chip and formatEntry name the end day of a span', () => {
  assert.equal(C.fmtSpan('2026-11-21', 540, 1440 + 840), '9a–Sun 2p');
  assert.equal(C.fmtSpan('2026-11-20', 1320, 1440 + 120), '10p–2a');
  const it = { Title: 'Troll Open', Category: 'Administrative', StartTime: '2026-11-21 09:00', EndTime: '2026-11-22 14:00' };
  assert.equal(C.formatEntry(it, { withDay: true }), 'sat 9a to sun 2p Troll Open');
  const back = p(C.formatEntry(it));
  assert.deepEqual([back.start, back.end, back.title], [it.StartTime, it.EndTime, 'Troll Open']);
  const pl = C.parseLines(C.scheduleText([it]), ctx({ eventDays: DAYS }));
  assert.deepEqual(pl.items.map(i => [i.StartTime, i.EndTime]), [[it.StartTime, it.EndTime]]);
});
test('a pasted all-day line sets no clock for the lines after it', () => {
  const r = C.parseLines('Saturday\nTroll all day\nOpening Court 10a\nBattlegame', ctx({ eventDays: DAYS }));
  assert.deepEqual(r.items.map(i => [i.Title, i.StartTime, i.EndTime]), [
    ['Troll', '2026-11-21 07:00', '2026-11-21 19:00'],
    ['Opening Court', '2026-11-21 10:00', '2026-11-21 11:00'],
    ['Battlegame', '2026-11-21 11:00', '2026-11-21 12:00']]);
});
// Review fixes for the all-day and across-days shorthand. A Friday board day.
const fri = s => { const r = p(s, { day: '2026-11-20', eventDays: DAYS }); return [r.start, r.end, r.title, r.span]; };
test('a day leading the line is the start day of a span whose start names none', () => {
  assert.deepEqual(fri('Sat Tournament 9a to Sun 2p'), ['2026-11-21 09:00', '2026-11-22 14:00', 'Tournament', true]);
  const pl = C.parseLines('Sat Tournament 9a to Sun 2p', ctx({ day: '2026-11-20', eventDays: DAYS }));
  assert.deepEqual(pl.items.map(i => [i.Title, i.StartTime, i.EndTime]), [['Tournament', '2026-11-21 09:00', '2026-11-22 14:00']]);
});
test('a range then a day is that range on that day, not a span to it', () => {
  assert.deepEqual(fri('Tournament 9a to 5p Saturday'), ['2026-11-21 09:00', '2026-11-21 17:00', 'Tournament', false]);
  // Past midnight on Sunday's board day.
  assert.deepEqual(fri('Tavern 10a to 2a Sun'), ['2026-11-22 10:00', '2026-11-23 02:00', 'Tavern', false]);
  // The evening words still bias a bare range.
  assert.deepEqual(fri('Feast 7 to 9 Saturday'), ['2026-11-21 19:00', '2026-11-21 21:00', 'Feast', false]);
  // A day with its own time at the end, or days on both ends, is a span.
  assert.deepEqual(fri('9a Sat to 2p Sun x'), ['2026-11-21 09:00', '2026-11-22 14:00', 'x', true]);
  assert.deepEqual(fri('Sat 9a - Sun 2p x'), ['2026-11-21 09:00', '2026-11-22 14:00', 'x', true]);
  assert.deepEqual(fri('9a to Sun 2p x'), ['2026-11-20 09:00', '2026-11-22 14:00', 'x', true]);
  assert.deepEqual(fri('Saturday through Sunday x'), ['2026-11-21 07:00', '2026-11-22 19:00', 'x', true]);
  // A day word mid-title is not taken.
  assert.deepEqual(fri('9a Sun Dance').slice(0, 3), ['2026-11-20 09:00', '2026-11-20 10:00', 'Sun Dance']);
});
test('an explicit time beats all day', () => {
  assert.deepEqual(fri('Troll open all day 8a-6p'), ['2026-11-20 08:00', '2026-11-20 18:00', 'Troll open', false]);
  assert.deepEqual(fri('Troll Saturday all day 8a-6p'), ['2026-11-21 08:00', '2026-11-21 18:00', 'Troll', false]);
  // A length is not a time of day: all day stands and the length stays text.
  assert.deepEqual(fri('Troll all day 30m'), ['2026-11-20 07:00', '2026-11-20 19:00', 'Troll 30m', true]);
});
test('a pasted long span on a new day leaves no clock from the day before', () => {
  const r = C.parseLines('Fri\nOpening 6p-7p\nSat all day Merchants\nBreakfast', ctx({ day: '2026-11-20', eventDays: DAYS }));
  assert.deepEqual(r.items.map(i => [i.Title, i.StartTime, i.EndTime]), [
    ['Opening', '2026-11-20 18:00', '2026-11-20 19:00'],
    ['Merchants', '2026-11-21 07:00', '2026-11-21 19:00'],
    ['Breakfast', '2026-11-21 09:00', '2026-11-21 10:00']]);
});
test('setsClock: only a long span drops the clock, as the quick line and a paste both use', () => {
  assert.equal(C.setsClock({ span: true, startMin: 420, endMin: 1140 }), false);
  assert.equal(C.setsClock({ span: true, startMin: 1320, endMin: 1560 }), true, 'an overnight span');
  assert.equal(C.setsClock({ span: false, startMin: 480, endMin: 1260 }), true, 'a long typed range');
});
test('fmtSpan and formatEntry name a span’s end day the same way', () => {
  assert.equal(C.endDayWord('2026-11-21', 1000), null);
  assert.equal(C.endDayWord('2026-11-21', 1440 + 840), 'Sun');
  assert.equal(C.endDayWord('2026-11-21', 1440 + 840, true), 'sun');
  assert.equal(C.endDayWord('2026-11-21', 7 * 1440 + 840), '11/28');
  assert.equal(C.fmtSpan('2026-11-21', 540, 7 * 1440 + 840), '9a–11/28 2p');
  const it = { Title: 'Long', Category: 'Administrative', StartTime: '2026-11-21 09:00', EndTime: '2026-11-28 14:00' };
  assert.equal(C.formatEntry(it), '9a to 11/28 2p #admin Long');
});

// ---- Real text: punctuation, word lengths, from/until, relative days and more ----
const at = (s, more) => { const r = p(s, Object.assign({ eventDays: DAYS }, more)); return [r.start, r.end, r.title]; };

test('dashes, a.m. and punctuation that real text carries', () => {
  assert.deepEqual(at('9am—10am Court'), ['2026-11-21 09:00', '2026-11-21 10:00', 'Court']);
  assert.deepEqual(at('9am -> 10am Court'), ['2026-11-21 09:00', '2026-11-21 10:00', 'Court']);
  assert.deepEqual(at('9 a.m. Breakfast'), ['2026-11-21 09:00', '2026-11-21 10:00', 'Breakfast']);
  assert.deepEqual(at('9a.m.-10:30a.m. Breakfast'), ['2026-11-21 09:00', '2026-11-21 10:30', 'Breakfast']);
  assert.deepEqual(at('Sun. 9a Court'), ['2026-11-22 09:00', '2026-11-22 10:00', 'Court']);
  assert.deepEqual(at('Fri, 9a Court'), ['2026-11-20 09:00', '2026-11-20 10:00', 'Court']);
  assert.deepEqual(at('Saturday 9a, Court'), ['2026-11-21 09:00', '2026-11-21 10:00', 'Court']);
  // The title keeps its own punctuation.
  assert.equal(p('9a Court, then lunch.').title, 'Court, then lunch.');
});
test('lengths in words', () => {
  const last = { lastEnd: { day: '2026-11-21', min: 600 } };
  assert.deepEqual(at('Battlegame 2 hrs', last).slice(0, 2), ['2026-11-21 10:00', '2026-11-21 12:00']);
  assert.deepEqual(at('Battlegame for 2 hours', last).slice(0, 2), ['2026-11-21 10:00', '2026-11-21 12:00']);
  assert.deepEqual(at('Battlegame 90 minutes', last).slice(1), ['2026-11-21 11:30', 'Battlegame']);
  assert.deepEqual(at('Battlegame 1 hour 30 minutes', last).slice(1), ['2026-11-21 11:30', 'Battlegame']);
  assert.deepEqual(at('Break 30 min', last).slice(1), ['2026-11-21 10:30', 'Break']);
  assert.deepEqual(at('Break 30mins', last).slice(1), ['2026-11-21 10:30', 'Break']);
  assert.deepEqual(at('Talk an hour', last).slice(1), ['2026-11-21 11:00', 'Talk']);
  assert.deepEqual(at('Talk half an hour', last).slice(1), ['2026-11-21 10:30', 'Talk']);
  assert.deepEqual(at('2p Battlegame for 90 minutes'), ['2026-11-21 14:00', '2026-11-21 15:30', 'Battlegame']);
  assert.equal(p('Top 5 pumpkins').title, 'Top 5 pumpkins');
});
test('from, until and 12 noon', () => {
  assert.deepEqual(at('from 9 to 5 Troll'), ['2026-11-21 09:00', '2026-11-21 17:00', 'Troll']);
  assert.deepEqual(at('Troll from 9a to 5p'), ['2026-11-21 09:00', '2026-11-21 17:00', 'Troll']);
  assert.deepEqual(at('Troll 9a until 5p'), ['2026-11-21 09:00', '2026-11-21 17:00', 'Troll']);
  assert.deepEqual(at('Troll until 5p'), ['2026-11-21 09:00', '2026-11-21 17:00', 'Troll']);
  assert.deepEqual(at('Troll till 5', { lastEnd: { day: '2026-11-21', min: 780 } }), ['2026-11-21 13:00', '2026-11-21 17:00', 'Troll']);
  assert.deepEqual(at("Tavern 'til 2a", { lastEnd: { day: '2026-11-21', min: 1200 } }), ['2026-11-21 20:00', '2026-11-22 02:00', 'Tavern']);
  assert.deepEqual(at('12 noon lunch'), ['2026-11-21 12:00', '2026-11-21 13:00', 'lunch']);
  assert.deepEqual(at('Vigil 12 midnight'), ['2026-11-22 00:00', '2026-11-22 01:00', 'Vigil']);
});
test('dusk and sunset are never locations', () => {
  assert.deepEqual(pick(p('Feast at dusk'), ['title', 'location']), ['Feast at dusk', '']);
  assert.deepEqual(pick(p('Night battle at sunset at Main Field'), ['title', 'location']), ['Night battle at sunset', 'Main Field']);
});
test('a full day name beside the time, mid-line', () => {
  assert.deepEqual(at('Battlegame Sunday 3p'), ['2026-11-22 15:00', '2026-11-22 16:00', 'Battlegame']);
  assert.deepEqual(at('Battlegame 3p Sunday at Main Field').slice(0, 2), ['2026-11-22 15:00', '2026-11-22 16:00']);
  assert.deepEqual(at('Battlegame at 3p Friday'), ['2026-11-20 15:00', '2026-11-20 16:00', 'Battlegame']);
  // An abbreviation mid-line, or a day name with more title after it, stays text.
  assert.equal(p('Ride the Sun 9a').title, 'Ride the Sun');
  assert.equal(p('9a Sunday School').title, 'Sunday School');
});
test('misspelt day names', () => {
  assert.equal(p('Satuday 9a court').day, '2026-11-21');
  assert.equal(p('Saterday 9a court').day, '2026-11-21');
  assert.equal(p('Firday 9a court').day, '2026-11-20');
  assert.equal(p('Sundya 9a court').day, '2026-11-22');
  assert.deepEqual(pick(p('Sundae Bar 2p'), ['day', 'title']), ['2026-11-21', 'Sundae Bar']);
});
test('dates written in words lead the line', () => {
  assert.deepEqual(at('Nov 22 9a court'), ['2026-11-22 09:00', '2026-11-22 10:00', 'court']);
  assert.deepEqual(at('November 20th 9a court'), ['2026-11-20 09:00', '2026-11-20 10:00', 'court']);
  assert.deepEqual(at('Sunday, November 22: 9a court'), ['2026-11-22 09:00', '2026-11-22 10:00', 'court']);
});
test('relative days: today, tonight, tomorrow, day 2, last day', () => {
  const during = { today: '2026-11-20' };
  assert.equal(p('today 9a court', during).day, '2026-11-20');
  assert.equal(p('court tomorrow 10a', during).day, '2026-11-21');
  // Before the event, 'tomorrow' counts from the day on show.
  assert.equal(p('tomorrow 9a court', { today: '2026-10-01' }).day, '2026-11-22');
  assert.deepEqual(at('Bardic tonight at 9', during), ['2026-11-20 21:00', '2026-11-20 22:00', 'Bardic']);
  assert.deepEqual(at('Day 2 9a court'), ['2026-11-21 09:00', '2026-11-21 10:00', 'court']);
  assert.deepEqual(at('day three 9a court').slice(0, 1), ['2026-11-22 09:00']);
  assert.deepEqual(at('last day 11a Closing Court'), ['2026-11-22 11:00', '2026-11-22 12:00', 'Closing Court']);
  assert.equal(p('Day 9 9a court').title, 'Day 9 court');
});
test('several days on one line: one item each', () => {
  let r = p('Breakfast 8a every day', { eventDays: DAYS });
  assert.deepEqual([r.days, r.everyDay, r.title, r.startMin], [DAYS, true, 'Breakfast', 480]);
  r = p('Fri & Sat 8a Breakfast');
  assert.deepEqual([r.days, r.title], [['2026-11-20', '2026-11-21'], 'Breakfast']);
  assert.deepEqual(p('Fri, Sat and Sun 8a Breakfast').days, DAYS);
  assert.deepEqual(p('Fri/Sun 8a Breakfast').days, ['2026-11-20', '2026-11-22']);
  assert.deepEqual(p('Daily 8a Breakfast', { eventDays: DAYS }).days, DAYS);
  assert.deepEqual(C.entryParts(p('Fri & Sat 8a Breakfast')).map(x => [x.start, x.end]),
    [['2026-11-20 08:00', '2026-11-20 09:00'], ['2026-11-21 08:00', '2026-11-21 09:00']]);
  // With all day: 7a to 7p on each day.
  assert.deepEqual(C.entryParts(p('Troll all day every day', { eventDays: DAYS })).map(x => x.start), DAYS.map(d => d + ' 07:00'));
  // A pasted line too.
  assert.equal(C.parseLines('Fri & Sat 8a Breakfast', ctx()).items.length, 2);
});
test('after and before another item', () => {
  const items = [
    { Title: 'Opening Court', StartTime: '2026-11-21 09:00', EndTime: '2026-11-21 10:30' },
    { Title: 'Feast', StartTime: '2026-11-21 18:00', EndTime: '2026-11-21 20:00' },
    { Title: 'Closing Court', StartTime: '2026-11-22 11:00', EndTime: '2026-11-22 12:00' }
  ];
  let r = p('Battlegame after court', { items });
  assert.deepEqual([r.start, r.end, r.title, r.anchor], ['2026-11-21 10:30', '2026-11-21 11:30', 'Battlegame', { kind: 'after', title: 'Opening Court' }]);
  r = p('Feast prep 2h before the feast', { items });
  assert.deepEqual([r.start, r.end, r.title], ['2026-11-21 16:00', '2026-11-21 18:00', 'Feast prep']);
  r = p('Sun Pack up after closing court', { items });
  assert.deepEqual([r.start, r.title], ['2026-11-22 12:00', 'Pack up']);
  // The only match on another day, when no day is typed.
  assert.equal(p('Pack up after closing court', { items }).start, '2026-11-22 12:00');
  // A written time wins; no match stays title.
  assert.deepEqual(at('2p Battlegame after court', { items }), ['2026-11-21 14:00', '2026-11-21 15:00', 'Battlegame after court']);
  assert.equal(p('After Party', { items }).title, 'After Party');
  // A ';' line can hang off its own earlier part (the part starts with a day, so it is an item).
  r = p('9a Opening Ceremony; Sat Battlegame after opening ceremony');
  assert.deepEqual([r.start, r.before.length], ['2026-11-21 10:00', 1]);
});
test('leads named after a cue', () => {
  const staff = [{ MundaneId: 2, Persona: 'Luna Honeybadger' }];
  let r = p('Naalbinding taught by Luna Honeybadger', { staff });
  assert.deepEqual([r.title, r.leads.map(l => l.MundaneId)], ['Naalbinding', [2]]);
  r = p('Class with luna honeybadger', { staff });
  assert.deepEqual([r.title, r.leads.length], ['Class', 1]);
  r = p('Fun with Swords', { staff });
  assert.deepEqual([r.title, r.leads.length], ['Fun with Swords', 0]);
});
test('a known location without at', () => {
  const locations = ['Main Field', 'Pavilion', 'Tavern'];
  assert.deepEqual(pick(p('Battlegame Main Field', { locations }), ['title', 'location']), ['Battlegame', 'Main Field']);
  assert.deepEqual(pick(p('Battlegame in the Pavilion', { locations }), ['title', 'location']), ['Battlegame', 'Pavilion']);
  assert.deepEqual(pick(p('Bardic on the main field', { locations }), ['title', 'location']), ['Bardic', 'Main Field']);
  // One word at the end is too often a title, and an unknown place stays text.
  assert.deepEqual(pick(p('Open Tavern', { locations }), ['title', 'location']), ['Open Tavern', '']);
  assert.deepEqual(pick(p('Fighting in Armor', { locations }), ['title', 'location']), ['Fighting in Armor', '']);
});
test('feast details: dietary options and allergens, for a meal and a feast manager', () => {
  let r = p('6p Feast $5 vegan and GF options contains nuts, dairy', { canFeast: true });
  assert.deepEqual(pick(r, ['title', 'cost', 'dietary', 'allergens']), ['Feast', 5, 'Vegan Option, Gluten-Free Option', 'Tree Nuts, Dairy']);
  r = p('Lunch with vegetarian and gluten free', { canFeast: true });
  assert.deepEqual(pick(r, ['title', 'dietary']), ['Lunch', 'Vegetarian Option, Gluten-Free Option']);
  // A diet word naming the meal stays in the title; not a meal, or no right, stays text.
  assert.deepEqual(pick(p('Vegan Potluck 6p', { canFeast: true }), ['title', 'dietary']), ['Vegan Potluck', '']);
  assert.deepEqual(pick(p('Battlegame vegan', { canFeast: true }), ['title', 'dietary']), ['Battlegame vegan', '']);
  assert.deepEqual(pick(p('Feast vegan options'), ['title', 'dietary']), ['Feast vegan options', '']);
  const pl = C.parseLines('6p Feast vegan options', ctx({ canFeast: true }));
  assert.equal(pl.items[0].Dietary, 'Vegan Option');
});
test('several items on one line with "; "', () => {
  const r = p('9a breakfast; 10a court; then 30m break');
  assert.deepEqual(r.before.map(b => [b.title, b.start]), [['breakfast', '2026-11-21 09:00'], ['court', '2026-11-21 10:00']]);
  assert.deepEqual([r.title, r.start, r.end], ['break', '2026-11-21 11:00', '2026-11-21 11:30']);
  assert.equal(C.entryParts(r).length, 3);
  // A quoted ';', a ';' with no space after it, and a trailing ';' do not split.
  assert.equal(p('9a Talk "bring water; and snacks"').before, undefined);
  assert.equal(p('9a Talk; ').title, 'Talk');
  assert.equal(C.splitEntry('a;b').length, 1);
  // Inside a block the line is one item.
  assert.equal(p('court; feast', { fixedDay: '2026-11-21', fixedStart: 600, fixedEnd: 660 }).title, 'court; feast');
  // Pasted, each part is an item.
  assert.equal(C.parseLines('9a breakfast; 10a court', ctx()).items.length, 2);
});
test('a "; " splits only before a time or a day; any other ";" is title text or a lead list', () => {
  const staff = [{ MundaneId: 1, Persona: 'Luna' }, { MundaneId: 2, Persona: 'Kai' }];
  let r = p('Sat 9a Court; Sun 10a Feast');
  assert.deepEqual([r.before.map(b => [b.title, b.start]), r.title, r.start], [[['Court', '2026-11-21 09:00']], 'Feast', '2026-11-22 10:00']);
  // Not a time or a day after it: one item, the ';' stays in the title.
  r = p('6p Feast: roast boar; turnips; mead', { canFeast: true });
  assert.deepEqual([r.before, r.title, r.category, r.start], [undefined, 'roast boar; turnips; mead', 'Feast and Food', '2026-11-21 18:00']);
  r = p('9a Talk; bring water');
  assert.deepEqual([r.before, r.title, r.start], [undefined, 'Talk; bring water', '2026-11-21 09:00']);
  assert.equal(p('9a Talk; an hour of Q&A').before, undefined);
  // A ';' then '@' joins a lead list.
  r = p('9a Talk @Luna; @Kai', { staff });
  assert.deepEqual([r.ok, r.before, r.title, r.leads.map(l => l.Persona)], [true, undefined, 'Talk', ['Luna', 'Kai']]);
  // Every way a part can start with a time or a day splits.
  ['10a court', 'tomorrow court', '11/22 court', 'Nov 22 court', 'at 6 feast', 'noon lunch', '30m break',
    'then court', 'every day 7a breakfast', 'Fri/Sat court', 'all day merchants', '9:30-10 court', 'day 3 court']
    .forEach(part => assert.equal((p('9a x; ' + part).before || []).length, 1, part));
  // An earlier part's own leads still count when the line splits.
  r = p('9a Talk @Luna; 10a Court @Kai', { staff });
  assert.deepEqual([r.before[0].leads.map(l => l.Persona), r.leads.map(l => l.Persona)], [['Luna'], ['Kai']]);
  // A bare hour leads a part only in a pasted list, as it leads a line.
  assert.equal(p('9a x; 10 court').before, undefined);
  assert.equal(C.parseLines('9a x; 10 court', ctx()).items.length, 2);
  assert.equal(C.parseLines('6p Feast: roast boar; turnips', ctx({ canFeast: true })).items.length, 1);
});
test('a "; " line: a chip\'s ignore reaches only its own part; each result knows its part', () => {
  // ctx.ignore (the chips' x on the shown, last part) leaves the earlier parts as read.
  let r = p('9a Breakfast; 10a Court', { ignore: { time: true } });
  assert.deepEqual([r.before[0].title, r.before[0].start, r.title], ['Breakfast', '2026-11-21 09:00', '10a Court']);
  assert.deepEqual([r.before[0].part, r.part], [0, 1]);
  // ctx.partIgnore[n] reaches part n only.
  r = p('9a Breakfast; 10a Court', { partIgnore: { 0: { time: true } } });
  assert.deepEqual([r.before[0].title, r.title, r.start], ['9a Breakfast', 'Court', '2026-11-21 10:00']);
  r = p('9a Breakfast; 10a Court', { partIgnore: { 1: { time: true } } });
  assert.deepEqual([r.before[0].start, r.title], ['2026-11-21 09:00', '10a Court']);
  // A line that does not split is part 0, and partIgnore[0] applies to it.
  r = p('9a Court', { partIgnore: { 0: { time: true } } });
  assert.deepEqual([r.part, r.title], [0, '9a Court']);
  // A part that is not an item (a day alone) still counts in the numbering.
  r = p('9a Breakfast; Sun; 10a Court');
  assert.deepEqual([r.before.map(b => b.part), r.part, r.day], [[0], 2, '2026-11-22']);
});
test('a "; " line whose last part has no title adds nothing, not even its earlier parts', () => {
  const r = p('9a Court; 10a');
  assert.deepEqual([r.ok, r.before.length, C.entryParts(r).length], [false, 1, 0]);
  assert.equal(C.entryParts(p('9a Court; 10a Feast')).length, 2);
  assert.deepEqual(C.parseLines('9a Court; 10a', ctx()).unread.map(u => u.line), [1]);
});

// ---- Fixes: until after a title, leading days, early anchors, fuzzy days, word lengths, possessive leads ----
const onFri = (s, more) => p(s, Object.assign({ day: '2026-11-20', eventDays: DAYS }, more));
test('a start then "until <time>" later on the line is a range', () => {
  const at3 = s => pick(onFri(s), ['start', 'end', 'title']);
  assert.deepEqual(at3('9a Troll until 5p'), ['2026-11-20 09:00', '2026-11-20 17:00', 'Troll']);
  assert.deepEqual(at3('9a Troll until 5'), ['2026-11-20 09:00', '2026-11-20 17:00', 'Troll']);
  assert.deepEqual(at3('7p Bardic till 1a'), ['2026-11-20 19:00', '2026-11-21 01:00', 'Bardic']);
});
test('a leading day word with "every day" is redundant (still every day); with a day list it joins the list', () => {
  let r = onFri('Fri 9a-5p Troll every day');
  assert.deepEqual([r.title, r.days, r.everyDay, r.startMin, r.endMin], ['Troll', DAYS, true, 540, 1020]);
  r = onFri('Sat 9a Troll daily');
  assert.deepEqual([r.title, r.days], ['Troll', DAYS]);
  r = onFri('Fri 9a Troll Sat & Sun');
  assert.deepEqual([r.title, r.days], ['Troll', DAYS]);
  assert.equal(C.entryParts(onFri('Fri 9a-5p Troll every day')).length, 3);
});
test('"before" an item reaching back past 5am starts on the board day before, flagged outside before the event', () => {
  const items = [
    { Title: 'Opening Court', StartTime: '2026-11-21 09:00', EndTime: '2026-11-21 10:00' },
    { Title: 'Friday Court', StartTime: '2026-11-20 09:00', EndTime: '2026-11-20 10:00' }
  ];
  let r = onFri('Setup 10 hours before opening court', { items });
  assert.deepEqual(pick(r, ['day', 'startMin', 'endMin', 'start', 'end', 'outside']),
    ['2026-11-20', 1380, 1980, '2026-11-20 23:00', '2026-11-21 09:00', false]);
  r = onFri('Setup 10 hours before friday court', { items });
  assert.deepEqual(pick(r, ['day', 'start', 'outside']), ['2026-11-19', '2026-11-19 23:00', true]);
  // Not past 5am: unchanged.
  assert.deepEqual(pick(onFri('Setup 2 hours before opening court', { items }), ['day', 'startMin']), ['2026-11-21', 420]);
});
test('real words a letter off a weekday are not days; typos that keep the "day" still are', () => {
  const sundry = onFri('Sundry sale 3p');
  assert.deepEqual([sundry.day, sundry.title, sundry.given.day], ['2026-11-20', 'Sundry sale', false]);
  assert.deepEqual(pick(onFri('Sundry'), ['ok', 'setDay', 'title']), [true, null, 'Sundry']);
  for (const w of ['Sunray', 'Sunway', 'Sundar', 'Saturnal', 'Fridge', 'Sundial']) {
    assert.equal(onFri(w + ' x 3p').given.day, false, w);
  }
  assert.equal(onFri('Thrusday 9a court').day, '2026-11-19');
  assert.equal(onFri('Saturdy 9a court').day, '2026-11-21');
  assert.equal(onFri('Fridya 9a court').day, '2026-11-20');
});
test('a Title-Cased length inside a title stays title; real lengths still read', () => {
  const t = (s, more) => pick(onFri(s, more), ['startMin', 'endMin', 'title']);
  assert.deepEqual(t('2p One Hour Challenge'), [840, 900, 'One Hour Challenge']);
  assert.deepEqual(t('2p Half Hour Melee'), [840, 900, 'Half Hour Melee']);
  assert.deepEqual(t('2p One Hour Tourney', { lastDuration: 45 }), [840, 885, 'One Hour Tourney']);
  assert.deepEqual(t('2p 24 Hour Vigil'), [840, 900, '24 Hour Vigil']);
  assert.deepEqual(t('2p the half hour melee'), [840, 900, 'the half hour melee']);
  assert.deepEqual(t('One Hour Challenge').slice(2), ['One Hour Challenge']);
  assert.deepEqual(t('2p Court for an hour'), [840, 900, 'Court']);
  assert.deepEqual(t('2p Court for half an hour'), [840, 870, 'Court']);
  assert.deepEqual(t('2p Court 1 hour'), [840, 900, 'Court']);
  assert.deepEqual(t('2p Court 2 Hours'), [840, 960, 'Court']);
  assert.deepEqual(t('2p Court for 2 Hours'), [840, 960, 'Court']);
  assert.deepEqual(t('2p half an hour Court'), [840, 870, 'Court']);
});
test('"with <staff>" is not a lead when the name runs on into a possessive or another word', () => {
  const staff = [{ MundaneId: 2, Persona: 'Luna' }];
  let r = onFri("Sparring with Luna's group", { staff });
  assert.deepEqual([r.title, r.leads.length], ["Sparring with Luna's group", 0]);
  r = onFri('Sparring with Luna’s group', { staff });
  assert.deepEqual([r.title, r.leads.length], ['Sparring with Luna’s group', 0]);
  r = onFri('Court with Luna', { staff });
  assert.deepEqual([r.title, r.leads.map(l => l.MundaneId)], ['Court', [2]]);
  r = onFri('Court with Luna 2p', { staff });
  assert.deepEqual([r.title, r.leads.map(l => l.MundaneId), r.startMin], ['Court', [2], 840]);
});
