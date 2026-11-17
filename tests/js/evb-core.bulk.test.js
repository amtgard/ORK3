const test = require('node:test');
const assert = require('node:assert/strict');
const C = require('../../orkui/template/revised-frontend/script/event-board/evb-core.js');

const DAYS = ['2026-11-20', '2026-11-21', '2026-11-22'];
const STAFF = [{ MundaneId: 2, Persona: 'Luna Honeybadger', RoleName: 'Warcrat', LeadCategories: [] }];
test('pasted lines carry the day and the clock forward', () => {
  const text = ['Friday', '12p Troll Opens', '12:30-1:15 bg: Squatch Ball @Luna Honeybadger', 'then 30m RP Quest Starts', '',
    'SATURDAY:', '9a Tavern Opens', '???', '10-11 class: Naalbinding @Nobody "Bring yarn"', '   '].join('\n');
  const r = C.parseLines(text, { days: DAYS, staff: STAFF });
  assert.deepEqual(r.items.map(i => [i.Title, i.StartTime, i.EndTime, i.Category]), [
    ['Troll Opens', '2026-11-20 12:00', '2026-11-20 13:00', 'Administrative'],
    ['Squatch Ball', '2026-11-20 12:30', '2026-11-20 13:15', 'Battlegame'],
    ['RP Quest Starts', '2026-11-20 13:15', '2026-11-20 13:45', 'Roleplay and Quests'],
    ['Tavern Opens', '2026-11-21 09:00', '2026-11-21 10:00', 'Feast and Food'],
    ['Naalbinding', '2026-11-21 10:00', '2026-11-21 11:00', 'Class'],
  ]);
  assert.deepEqual(r.items[1].Leads.map(l => l.MundaneId), [2]);
  assert.equal(r.items[4].Description, 'Bring yarn');
  assert.deepEqual(r.unread, [{ line: 8, text: '???' }]);
  assert.deepEqual(r.unmatchedLeads, ['Nobody']);
});
test('schedule text reads back through parseLines', () => {
  const items = [
    { Title: 'Squatch Ball', Category: 'Battlegame', StartTime: '2026-11-21 09:30:00', EndTime: '2026-11-21 10:15:00', Location: 'Main Field', Description: '', Leads: [{ MundaneId: 2, Persona: 'Luna Honeybadger' }] },
    { Title: 'Troll Opens', Category: 'Administrative', StartTime: '2026-11-20 12:00:00', EndTime: '2026-11-20 12:30:00', Location: '', Description: '', Leads: [] },
    { Title: 'Night Quest!', Category: 'Roleplay and Quests', StartTime: '2026-11-22 00:30:00', EndTime: '2026-11-22 01:30:00', Location: '', Description: '', Leads: [] },
  ];
  const text = C.scheduleText(items);
  assert.equal(text.split('\n')[0], 'Friday, November 20');
  assert.ok(text.includes('\n\nSaturday, November 21\n'));
  const back = C.parseLines(text, { days: DAYS, staff: STAFF });
  assert.deepEqual(back.unread, []);
  assert.deepEqual(back.items.map(i => [i.Title, i.StartTime + ':00', i.EndTime + ':00', i.Category, i.Location, i.Leads.length]), [
    ['Troll Opens', '2026-11-20 12:00:00', '2026-11-20 12:30:00', 'Administrative', '', 0],
    ['Squatch Ball', '2026-11-21 09:30:00', '2026-11-21 10:15:00', 'Battlegame', 'Main Field', 1],
    ['Night Quest!', '2026-11-22 00:30:00', '2026-11-22 01:30:00', 'Roleplay and Quests', '', 0],
  ]);
});
test('the skeleton parses on one-day and multi-day events', () => {
  const three = C.parseLines(C.skeletonText(DAYS), { days: DAYS });
  assert.deepEqual(three.unread, []);
  assert.deepEqual(three.items.map(i => i.StartTime.slice(0, 10)).filter((d, n, a) => a.indexOf(d) === n), DAYS);
  assert.ok(three.items.some(i => i.Title === 'Court' && i.Category === 'Court' && i.StartTime === '2026-11-21 20:00'));
  assert.ok(three.items.some(i => i.Title === 'Clean Up' && i.StartTime === '2026-11-22 10:00'));
  assert.ok(three.items.some(i => i.Title === 'Tavern Opens' && i.StartTime === '2026-11-20 13:00'));
  assert.ok(three.items.some(i => i.Title === 'Tavern Opens' && i.StartTime === '2026-11-21 11:00'));
  assert.ok(!three.items.some(i => i.Title === 'Lunch'));
  const one = C.parseLines(C.skeletonText(['2026-11-21']), { days: ['2026-11-21'] });
  assert.deepEqual(one.items.map(i => i.Title), ['Troll Opens', 'Tavern Opens', 'Feast', 'Court']);
});
test('conflicts name the shared lead or place on both items', () => {
  const a = { cid: 'a', Title: 'Mothman', StartTime: '2026-11-21 10:00', EndTime: '2026-11-21 11:00', Location: 'Main Field', Leads: [{ MundaneId: 2, Persona: 'Luna' }] };
  const b = { cid: 'b', Title: 'Archery', StartTime: '2026-11-21 10:30', EndTime: '2026-11-21 11:30', Location: ' main field', Leads: [{ MundaneId: 2, Persona: 'Luna' }] };
  const c = { cid: 'c', Title: 'Class', StartTime: '2026-11-21 11:00', EndTime: '2026-11-21 12:00', Location: 'Hall', Leads: [{ MundaneId: 2, Persona: 'Luna' }] };
  const d = { EventScheduleId: 9, Title: 'No place', StartTime: '2026-11-21 10:00', EndTime: '2026-11-21 12:00', Location: '', Leads: [] };
  const r = C.conflicts([a, b, c, d]);
  assert.deepEqual(r.a.sort(), ['Luna is also on Archery', 'Main Field is also booked for Archery']);
  assert.deepEqual(r.b.sort(), ['Luna is also on Class', 'Luna is also on Mothman', 'main field is also booked for Mothman']);
  assert.deepEqual(r.c, ['Luna is also on Archery']);
  assert.equal(r.i9, undefined);
});

// Designer rulings beyond the brief's examples.
test('a long day heading, an M/D line and punctuation after a day name set the day', () => {
  const r = C.parseLines(['Saturday', 'Friday, November 20', '9a Gate', '11/22:', '9a Clean', '11/21', '9a Lunch',
    'Friday --', '1p Archery'].join('\n'), { days: DAYS });
  assert.deepEqual(r.unread, []);
  assert.deepEqual(r.items.map(i => [i.Title, i.StartTime]), [
    ['Gate', '2026-11-20 09:00'], ['Clean', '2026-11-22 09:00'], ['Lunch', '2026-11-21 09:00'], ['Archery', '2026-11-20 13:00'],
  ]);
});
test('a line with no letter or digit left after the grammar is unread; ctx carries in', () => {
  const r = C.parseLines('Fri\n9a Gate\n10a #bg ---\n@Nobody\nthen Archery', { days: DAYS, lastDuration: 30 });
  assert.deepEqual(r.unread, [{ line: 3, text: '10a #bg ---' }, { line: 4, text: '@Nobody' }]);
  assert.deepEqual(r.items.map(i => [i.Title, i.StartTime, i.EndTime]), [
    ['Gate', '2026-11-20 09:00', '2026-11-20 09:30'], ['Archery', '2026-11-20 09:30', '2026-11-20 10:00'],
  ]);
  assert.deepEqual(r.unmatchedLeads, []);
});
test('items are ready for the store: leads are hand-picked, every field is present', () => {
  const r = C.parseLines('Sat 5-6 feast $8 at Hall @Luna Honeybadger @x @x', { days: DAYS, staff: STAFF, canFeast: true });
  assert.deepEqual(r.items, [{ Title: 'feast', StartTime: '2026-11-21 17:00', EndTime: '2026-11-21 18:00', Category: 'Feast and Food',
    SecondaryCategory: '', Location: 'Hall', Description: '', Cost: 8, Leads: [{ MundaneId: 2, Persona: 'Luna Honeybadger', Auto: false }] }]);
  assert.deepEqual(r.unmatchedLeads, ['x']);
});
test('the skeleton heads days with M/D when two days share a weekday', () => {
  const days = []; for (let n = 0; n < 9; n++) days.push(C.addDays('2026-11-20', n));
  const text = C.skeletonText(days);
  assert.equal(text.split('\n')[0], '11/20:');
  assert.ok(text.includes('\n\n11/27:\n'));
  const r = C.parseLines(text, { days });
  assert.deepEqual(r.unread, []);
  assert.deepEqual(r.items.map(i => i.StartTime.slice(0, 10)).filter((d, n, a) => a.indexOf(d) === n), days);
  assert.equal(r.items.length, 3 + 7 * 4 + 3);
  assert.equal(C.skeletonText(DAYS).split('\n')[0], 'Fri:');
  assert.equal(C.skeletonText([]), '');
});
test('scheduleText orders by start within a board day and skips items without a time', () => {
  const t = C.scheduleText([
    { Title: 'Late', Category: 'Other', StartTime: '2026-11-21 15:00', EndTime: '2026-11-21 16:00' },
    { Title: 'Early', Category: 'Other', StartTime: '2026-11-21 09:00', EndTime: '2026-11-21 10:00' },
    { Title: 'Broken', Category: 'Other', StartTime: '' },
  ]);
  assert.equal(t, 'Saturday, November 21\n9–10a Early\n3–4p Late');
  assert.equal(C.scheduleText([]), '');
});
test('conflicts: touching ends, zero-length items and different leads are not conflicts', () => {
  const lead = (id, p) => [{ MundaneId: id, Persona: p }];
  const r = C.conflicts([
    { cid: 'a', Title: 'A', StartTime: '2026-11-21 10:00', EndTime: '2026-11-21 11:00', Location: 'Hall', Leads: lead(2, 'Luna') },
    { cid: 'z', Title: 'Bell', StartTime: '2026-11-21 10:30', EndTime: '2026-11-21 10:30', Location: 'Hall', Leads: lead(2, 'Luna') },
    { cid: 'b', Title: 'B', StartTime: '2026-11-21 10:15', EndTime: '2026-11-21 10:45', Location: 'Field', Leads: lead(3, 'Other') },
    { cid: 'n', Title: 'Night', StartTime: '2026-11-21 23:30', EndTime: '2026-11-22 01:00', Location: 'Fire', Leads: lead('7', 'Seven') },
    { cid: 'm', Title: 'Late', StartTime: '2026-11-22 00:30', EndTime: '2026-11-22 02:00', Location: 'fire ', Leads: lead(7, 'Seven') },
  ]);
  assert.deepEqual(Object.keys(r).sort(), ['m', 'n']);
  assert.deepEqual(r.n.sort(), ['Fire is also booked for Late', 'Seven is also on Late']);
  assert.deepEqual(r.m.sort(), ['Seven is also on Night', 'fire is also booked for Night']);
});
test('a long heading with a date reads back to that date when weekdays repeat', () => {
  const days = []; for (let n = 0; n < 9; n++) days.push(C.addDays('2026-11-20', n));
  const items = [
    { Title: 'First Sat', Category: 'Other', StartTime: '2026-11-21 10:00', EndTime: '2026-11-21 11:00' },
    { Title: 'Second Sat', Category: 'Other', StartTime: '2026-11-28 10:00', EndTime: '2026-11-28 11:00' },
    { Title: 'Second Fri', Category: 'Other', StartTime: '2026-11-27 10:00', EndTime: '2026-11-27 11:00' },
  ];
  const back = C.parseLines(C.scheduleText(items), { days });
  assert.deepEqual(back.items.map(i => [i.Title, i.StartTime]), [
    ['First Sat', '2026-11-21 10:00'], ['Second Fri', '2026-11-27 10:00'], ['Second Sat', '2026-11-28 10:00'],
  ]);
  const three = C.parseLines('Friday, November 27\n9a X', { days: DAYS });
  assert.equal(three.items[0].StartTime, '2026-11-27 09:00');
});
test('pasted formatting is cleaned up, not turned into items', () => {
  const text = ['**Saturday**', '- 9a Gate', '• 10a Archery', '* 11a Lunch', '1. 1p Court', '2) 2p Class', '---',
    'SUNDAY 11/22', '9a Clean Up', 'Saturday - Nov 21', '#bg 3p Mothman', '3 on 3 Tourney 4-5'].join('\n');
  const r = C.parseLines(text, { days: DAYS });
  assert.deepEqual(r.unread, []);
  assert.deepEqual(r.items.map(i => [i.Title, i.StartTime]), [
    ['Gate', '2026-11-21 09:00'], ['Archery', '2026-11-21 10:00'], ['Lunch', '2026-11-21 11:00'],
    ['Court', '2026-11-21 13:00'], ['Class', '2026-11-21 14:00'], ['Clean Up', '2026-11-22 09:00'],
    ['Mothman', '2026-11-21 15:00'], ['3 on 3 Tourney', '2026-11-21 16:00'],
  ]);
  assert.equal(r.items[6].Category, 'Battlegame');
});
test('more heading forms set the day and make no item; separators are skipped', () => {
  const forms = ['SATURDAY 11/21', 'Saturday 21 November', 'Sat 11/21', 'Saturday, Nov 21st, 2026:', '## Saturday', '__Saturday__:'];
  for (const h of forms) {
    const r = C.parseLines(h + '\n- - -\n***\n9a X', { days: DAYS, day: '2026-11-20' });
    assert.deepEqual([r.unread, r.items.map(i => [i.Title, i.StartTime])], [[], [['X', '2026-11-21 09:00']]], h);
  }
});
test('items on a day outside the event are returned and listed', () => {
  const r = C.parseLines('Monday\n9a A', { days: DAYS });
  assert.deepEqual(r.items.map(i => i.StartTime), ['2026-11-23 09:00']);
  assert.deepEqual(r.outside, [{ line: 2, text: '9a A', day: '2026-11-23' }]);
  assert.deepEqual(C.parseLines('Sat\n9a A', { days: DAYS }).outside, []);
});
test('more pasted forms', () => {
  const one = line => {
    const r = C.parseLines(line, { days: DAYS, day: '2026-11-20' });
    assert.deepEqual(r.unread, [], line);
    return r.items.map(i => [i.Title, i.StartTime]);
  };
  // 1. A separator after a leading day word.
  for (const sep of ['-', '–', '—', ':']) assert.deepEqual(one('Saturday ' + sep + ' Lunch'), [['Lunch', '2026-11-21 09:00']], sep);
  assert.deepEqual(one('Saturday: Lunch 12p'), [['Lunch', '2026-11-21 12:00']]);
  // 2. A date after a leading day word is part of the day.
  assert.deepEqual(one('Sat 11/21 9a Gate'), [['Gate', '2026-11-21 09:00']]);
  assert.deepEqual(one('Saturday Nov 21 - 9a Gate'), [['Gate', '2026-11-21 09:00']]);
  // 3. A date alone is a heading; a date leading a line gives the day.
  for (const h of ['November 21', '11/21']) {
    const r = C.parseLines(h + '\n9a X', { days: DAYS, day: '2026-11-20' });
    assert.deepEqual([r.unread, r.items.map(i => [i.Title, i.StartTime])], [[], [['X', '2026-11-21 09:00']]], h);
  }
  assert.deepEqual(one('Nov 21 - 9a Gate'), [['Gate', '2026-11-21 09:00']]);
  assert.deepEqual(one('11/22 10a Clean Up'), [['Clean Up', '2026-11-22 10:00']]);
  // 4. Single emphasis around the whole line; '* ' is still a bullet.
  assert.deepEqual(one('*9a Gate*'), [['Gate', '2026-11-20 09:00']]);
  assert.deepEqual(one('_9a Gate_'), [['Gate', '2026-11-20 09:00']]);
  assert.deepEqual(one('* 9a Gate*'), [['Gate*', '2026-11-20 09:00']]);
  // 5. Dashes, dates and underscores inside a title stay.
  assert.deepEqual(one('Arts - Sciences Social 2p'), [['Arts - Sciences Social', '2026-11-20 14:00']]);
  assert.deepEqual(one('Sign-ups for 11/28 trip 3p'), [['Sign-ups for 11/28 trip', '2026-11-20 15:00']]);
  assert.deepEqual(one('snake_case talk 4p'), [['snake_case talk', '2026-11-20 16:00']]);
  assert.deepEqual(one('Mar 3-4 Sparring'), [['Mar 3-4 Sparring', '2026-11-20 09:00']]);
  // The quick line is untouched: parseEntry still keeps the separator.
  assert.equal(C.parseEntry('Saturday - Lunch', { days: DAYS }).title, '- Lunch');
});
test('conflicts compare locations ignoring the width of spaces', () => {
  const r = C.conflicts([
    { cid: 'a', Title: 'A', StartTime: '2026-11-21 10:00', EndTime: '2026-11-21 11:00', Location: 'Main  Field', Leads: [] },
    { cid: 'b', Title: 'B', StartTime: '2026-11-21 10:30', EndTime: '2026-11-21 11:30', Location: 'main field', Leads: [] },
  ]);
  assert.deepEqual(r, { a: ['Main  Field is also booked for B'], b: ['main field is also booked for A'] });
});
test('an after-midnight item stays in its board day’s group and round-trips', () => {
  const items = [
    { Title: 'Court', Category: 'Court', StartTime: '2026-11-21 19:00', EndTime: '2026-11-21 21:00' },
    { Title: 'Midnight Ditch', Category: 'Battlegame', StartTime: '2026-11-22 00:30', EndTime: '2026-11-22 02:00' },
    { Title: 'Tavern Closes', Category: 'Administrative', StartTime: '2026-11-22 12:15', EndTime: '2026-11-22 12:45' },
  ];
  const text = C.scheduleText(items);
  assert.equal(text, 'Saturday, November 21\n7–9p Court\n12:30–2a Midnight Ditch\n\nSunday, November 22\n12:15–12:45p #admin Tavern Closes');
  const sp = C.span(items[1]);
  assert.deepEqual(C.lateNight(sp.day, sp.start), { day: 'Sunday', dayISO: '2026-11-22', night: 'Saturday night' });
  const back = C.parseLines(text, { days: DAYS });
  assert.deepEqual(back.unread, []);
  assert.deepEqual(back.items.map(i => [i.Title, i.StartTime, i.EndTime, i.Category]),
    items.map(i => [i.Title, i.StartTime, i.EndTime, i.Category]));
});
