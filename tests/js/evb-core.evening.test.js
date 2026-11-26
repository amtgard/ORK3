const test = require('node:test');
const assert = require('node:assert/strict');
const C = require('../../orkui/template/revised-frontend/script/event-board/evb-core.js');

const DAYS = ['2026-11-20', '2026-11-21', '2026-11-22'];
const ctx = more => Object.assign({ days: DAYS, day: '2026-11-21', lastEnd: null, lastDuration: 60 }, more);
const p = (text, more) => C.parseEntry(text, ctx(more));
const pick = (r, keys) => keys.map(k => r[k]);
const lines = (text, more) => C.parseLines(text, Object.assign({ days: DAYS }, more)).items;

test('evening title words read an unmarked 7 to 11 as pm', () => {
  assert.deepEqual(pick(p('sat feast 7-9'), ['day', 'start', 'end']), ['2026-11-21', '2026-11-21 19:00', '2026-11-21 21:00']);
  assert.deepEqual(pick(p('fri dinner at 7'), ['day', 'start', 'end', 'title']), ['2026-11-20', '2026-11-20 19:00', '2026-11-20 20:00', 'dinner']);
  assert.deepEqual(pick(p('sat night quest 8-10'), ['start', 'end']), ['2026-11-21 20:00', '2026-11-21 22:00']);
  // Ranges read forward: a 9pm start makes the unmarked 1 1am.
  assert.deepEqual(pick(p('fri revel 9-1'), ['day', 'startMin', 'endMin', 'start', 'end']),
    ['2026-11-20', 1260, 1500, '2026-11-20 21:00', '2026-11-21 01:00']);
  // 'tavern' is not an evening word: 'Tavern Opens' at 9am is normal.
  assert.deepEqual(pick(p('fri tavern 9-1'), ['start', 'end']), ['2026-11-20 09:00', '2026-11-20 13:00']);
  assert.equal(p('9-9:30 Tavern Opens').startMin, 540);
  assert.deepEqual(pick(p('7:30 Bardic Circle'), ['startMin']), [1170]);
  assert.deepEqual(pick(p('11-12 Revel'), ['startMin', 'endMin']), [1380, 1440]);
  // Whole words only, any case.
  assert.equal(p('8-9 NIGHT QUEST').startMin, 1200);
  assert.equal(p('8-9 Nightingale talk').startMin, 480);
});
test('court reads an unmarked 7 or 8 as pm, but 9 to 11 stay morning', () => {
  assert.deepEqual(pick(p('sat court 7-9'), ['start', 'end']), ['2026-11-21 19:00', '2026-11-21 21:00']);
  assert.deepEqual(pick(p('sat court at 8'), ['start']), ['2026-11-21 20:00']);
  assert.deepEqual(pick(p('sun court 10-11'), ['day', 'start', 'end']), ['2026-11-22', '2026-11-22 10:00', '2026-11-22 11:00']);
  const nine = p('sat court at 9');
  assert.deepEqual(pick(nine, ['title', 'startMin', 'start']), ['court', 540, '2026-11-21 09:00']);
  assert.equal(nine.given.time, true);
  assert.equal(p('11-12 Court').startMin, 660);
});
test('the bias comes from the final title, not a Name: prefix or a #tag', () => {
  assert.deepEqual(pick(p('Feast and Food: Pancakes 8-9'), ['title', 'category', 'start', 'end']),
    ['Pancakes', 'Feast and Food', '2026-11-21 08:00', '2026-11-21 09:00']);
  assert.deepEqual(pick(p('#feast Pancakes 8-9'), ['title', 'category', 'start', 'end']),
    ['Pancakes', 'Feast and Food', '2026-11-21 08:00', '2026-11-21 09:00']);
  assert.deepEqual(pick(p('Court: Awards 10-11'), ['title', 'category', 'start', 'end']),
    ['Awards', 'Court', '2026-11-21 10:00', '2026-11-21 11:00']);
  // The title's own words still count.
  assert.equal(p('Feast and Food: Night Snack 8-9').startMin, 1200);
});
test('morning words and explicit markers win', () => {
  assert.deepEqual(pick(p('sat breakfast 8-9'), ['startMin', 'endMin']), [480, 540]);
  assert.deepEqual(pick(p('8-9 Morning Court'), ['startMin', 'endMin']), [480, 540]);
  assert.deepEqual(pick(p('sat court 10a'), ['startMin', 'endMin']), [600, 660]);
  assert.deepEqual(pick(p('feast 7a-9'), ['startMin', 'endMin']), [420, 540]);
  assert.deepEqual(pick(p('feast 7-9a'), ['startMin', 'endMin']), [420, 540]);
  assert.deepEqual(pick(p('feast 0700-0900'), ['startMin', 'endMin']), [420, 540]);
  // Hours outside 7 to 11 keep their reading.
  assert.deepEqual(pick(p('feast 5-6'), ['startMin', 'endMin']), [1020, 1080]);
});
test('midnight in the title reads an unmarked 12 as 12am on the board day', () => {
  assert.deepEqual(pick(p('sat 12:30-2 Midnight Ditch'), ['day', 'startMin', 'endMin', 'start', 'end']),
    ['2026-11-21', 1470, 1560, '2026-11-22 00:30', '2026-11-22 02:00']);
  assert.equal(p('12:00 Court').startMin, 720);
  assert.equal(p('12:00 Midnight Court').startMin, 1440);
  assert.equal(lines('Sat\n12 Midnight Court')[0].StartTime, '2026-11-22 00:00');
  assert.equal(p('12p Midnight Court').startMin, 720);
});
test('a trailing bare number is never a time', () => {
  for (const s of ['Court 7', 'Round 2', 'Feast 8']) {
    const r = p(s);
    assert.deepEqual([r.title, r.given.time], [s, false], s);
  }
  assert.deepEqual(lines('Sat\nCourt 7').map(i => [i.Title, i.StartTime]), [['Court 7', '2026-11-21 09:00']]);
});
test('the quick line keeps the typed order: no time-order rule', () => {
  const r = p('sat class 8-9', { lastEnd: { day: '2026-11-21', min: 1140 } });
  assert.deepEqual(pick(r, ['startMin', 'endMin']), [480, 540]);
});
test('a pasted Saturday list in time order', () => {
  const r = lines('Saturday\n5-6 Chupacabra Plate\n7-9 Court\n9-11 Night Quest\n12:30-2 Midnight Ditch');
  assert.deepEqual(r.map(i => [i.Title, i.StartTime, i.EndTime]), [
    ['Chupacabra Plate', '2026-11-21 17:00', '2026-11-21 18:00'],
    ['Court', '2026-11-21 19:00', '2026-11-21 21:00'],
    ['Night Quest', '2026-11-21 21:00', '2026-11-21 23:00'],
    ['Midnight Ditch', '2026-11-22 00:30', '2026-11-22 02:00'],
  ]);
  assert.equal(C.boardPos(r[3].StartTime).min, 1470);
  assert.equal(C.boardPos(r[3].StartTime).day, '2026-11-21');
});
test('a pasted day of bare leading hours stays in order', () => {
  const r = lines('Sat\n9 Gate opens\n10 Class\n1 Lunch\n3 Battlegame\n7 Feast\n9 Court');
  assert.deepEqual(r.map(i => [i.Title, i.StartTime]), [
    ['Gate opens', '2026-11-21 09:00'], ['Class', '2026-11-21 10:00'], ['Lunch', '2026-11-21 13:00'],
    ['Battlegame', '2026-11-21 15:00'], ['Feast', '2026-11-21 19:00'], ['Court', '2026-11-21 21:00'],
  ]);
});
test('time order in pasted lists moves an unmarked time to the later reading', () => {
  // No evening word: the order alone moves 7 to 7pm and 12:30 to after midnight.
  const r = lines('Sat\n5 Battlegame\n7 Mothman\n9-10 Archery\n12:30-1:30 Ditch\n2 Jackalope');
  assert.deepEqual(r.map(i => [i.Title, i.StartTime, i.EndTime]), [
    ['Battlegame', '2026-11-21 17:00', '2026-11-21 18:00'],
    ['Mothman', '2026-11-21 19:00', '2026-11-21 20:00'],
    ['Archery', '2026-11-21 21:00', '2026-11-21 22:00'],
    ['Ditch', '2026-11-22 00:30', '2026-11-22 01:30'],
    ['Jackalope', '2026-11-22 02:00', '2026-11-22 03:00'],
  ]);
  // A morning word is never moved; a marked time is never moved; nor is one whose other reading is also earlier.
  assert.deepEqual(lines('Sat\n3 Battlegame\n8 Breakfast').map(i => i.StartTime), ['2026-11-21 15:00', '2026-11-21 08:00']);
  assert.deepEqual(lines('Sat\n3 Battlegame\n8a Gate').map(i => i.StartTime), ['2026-11-21 15:00', '2026-11-21 08:00']);
  assert.deepEqual(lines('Sat\n9p Battlegame\n8 Gate').map(i => i.StartTime), ['2026-11-21 21:00', '2026-11-21 08:00']);
  assert.deepEqual(lines('Sat\n3 Battlegame\n8-9a Gate').map(i => i.StartTime), ['2026-11-21 15:00', '2026-11-21 08:00']);
  assert.deepEqual(lines('Sat\n3 Battlegame\n2 Archery').map(i => i.StartTime), ['2026-11-21 15:00', '2026-11-21 14:00']);
  // A new day starts the order afresh.
  assert.deepEqual(lines('Sat\n3 Battlegame\nSun\n9 Gate').map(i => i.StartTime), ['2026-11-21 15:00', '2026-11-22 09:00']);
});
test('time order only moves a time when the previous start is plainly evening or night', () => {
  const t = text => lines(text).map(i => [i.Title, i.StartTime.slice(11)]);
  // An evening word on the first line does not drag the morning after it.
  assert.deepEqual(t('Sat\n9 Court\n9:30 Squatch Ball\n10 Archery'),
    [['Court', '09:00'], ['Squatch Ball', '09:30'], ['Archery', '10:00']]);
  // 12 after 1pm stays noon: the previous start is not 18:00 or later.
  assert.deepEqual(t('Sat\n1 Lunch\n12 Class'), [['Lunch', '13:00'], ['Class', '12:00']]);
  // With order context the word bias is not used; the order moves Bardic.
  assert.deepEqual(t('Sat\n7 Dinner\n9 Bardic'), [['Dinner', '19:00'], ['Bardic', '21:00']]);
  assert.deepEqual(t('Sat\n9 Breakfast\n10 Court\n12 Site closes'),
    [['Breakfast', '09:00'], ['Court', '10:00'], ['Site closes', '12:00']]);
  // The previous start must be noon or later for the order alone to move 7 to 11.
  assert.deepEqual(t('Sat\n10 Court\n7 Archery'), [['Court', '10:00'], ['Archery', '07:00']]);
  // With an order context the word bias does not move a line already in order.
  assert.deepEqual(t('Sat\n9 Gate opens\n10 Feast prep'), [['Gate opens', '09:00'], ['Feast prep', '10:00']]);
  // The order context counts only on the same day.
  assert.deepEqual(lines('Sat 3 Battlegame\nSun 9 Gate').map(i => i.StartTime), ['2026-11-21 15:00', '2026-11-22 09:00']);
});
test('in a sparse pasted list, an evening word moves a 7 to 11 that starts before the previous start', () => {
  const t = text => lines(text).map(i => [i.Title, i.StartTime.slice(11)]);
  assert.deepEqual(t('Sat\n10 Opening Court\n7 Feast\n9 Bardic'),
    [['Opening Court', '10:00'], ['Feast', '19:00'], ['Bardic', '21:00']]);
  assert.deepEqual(t('Sat\n9 Breakfast\n10 Court\n7 Feast'),
    [['Breakfast', '09:00'], ['Court', '10:00'], ['Feast', '19:00']]);
  // Already in order: never moved by its words.
  assert.deepEqual(t('Sat\n9 Gate opens\n10 Feast prep'), [['Gate opens', '09:00'], ['Feast prep', '10:00']]);
  // No evening word and the previous start before noon: stays.
  assert.deepEqual(t('Sat\n10 Court\n7 Archery'), [['Court', '10:00'], ['Archery', '07:00']]);
  // The court limit holds inside this clause: Sunday-morning court at 9 stays 09:00, but 8 Court is evening.
  assert.deepEqual(t('Sun\n10 Archery\n9 Court\n11 Site cleanup'),
    [['Archery', '10:00'], ['Court', '09:00'], ['Site cleanup', '11:00']]);
  assert.deepEqual(t('Sat\n10 Gate opens\n8 Court'), [['Gate opens', '10:00'], ['Court', '20:00']]);
  // Deliberately no not-before condition: the word-moved line may land before the previous start.
  assert.deepEqual(t('Sat\n10p Revel\n8 Feast'), [['Revel', '22:00'], ['Feast', '20:00']]);
});
test('a blank line or a heading starts a new track', () => {
  const want = [['Ditch', '10:00'], ['Battlegame', '14:00'], ['Night Battle', '19:00'], ['Naalbinding', '10:00'], ['Belt Favors', '11:00']];
  const t = text => lines(text).filter(i => i.Title !== 'Classes:').map(i => [i.Title, i.StartTime.slice(11)]);
  assert.deepEqual(t('Sat\n10 Ditch\n2 Battlegame\n7 Night Battle\n\n10 Naalbinding\n11 Belt Favors'), want);
  assert.deepEqual(t('Sat\n10 Ditch\n2 Battlegame\n7 Night Battle\nClasses:\n10 Naalbinding\n11 Belt Favors'), want);
});
test('the reference sheet pasted whole without am or pm', () => {
  const sheet = require('./fixtures/sheet-schedule.js');
  const clock = s => {
    const h = +s.slice(11, 13), m = s.slice(14, 16);
    return String(h % 12 || 12) + (m === '00' ? '' : ':' + m);
  };
  const heads = { '2026-11-20': 'Friday', '2026-11-21': 'Saturday', '2026-11-22': 'Sunday' };
  let day = null;
  const text = [];
  for (const it of sheet) {
    const d = it.StartTime.slice(0, 10);
    if (d !== day) { text.push(heads[d]); day = d; }
    text.push(clock(it.StartTime) + '-' + clock(it.EndTime) + ' ' + it.Title);
  }
  assert.ok(!/\d[ap]/i.test(text.join('\n')), 'no am/pm marker in the pasted text');
  const got = lines(text.join('\n'));
  assert.equal(got.length, 42);
  const wrong = got.filter((it, n) => it.StartTime !== sheet[n].StartTime.slice(0, 16) || it.EndTime !== sheet[n].EndTime.slice(0, 16))
    .map(it => [it.Title, it.StartTime, it.EndTime]);
  assert.deepEqual(wrong, []);
});
