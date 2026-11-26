const test = require('node:test');
const assert = require('node:assert/strict');
const C = require('../../orkui/template/revised-frontend/script/event-board/evb-core.js');

const ES = '2026-11-20 09:00', EE = '2026-11-22 16:00';
const it = (Title, Category, StartTime, EndTime, more) => Object.assign({ Title, Category, StartTime, EndTime }, more);
const titles = list => list.map(e => e.item.Title);
const at = (items, now) => C.nowNext(items, now, ES, EE);

test('an item starting exactly now is on now; one ending exactly now is not', () => {
  const items = [
    it('Opening', 'Court', '2026-11-21 09:00', '2026-11-21 10:00'),
    it('Breakfast', 'Feast and Food', '2026-11-21 08:00', '2026-11-21 09:00'),
    it('Ditch', 'Battlegame', '2026-11-21 09:30', '2026-11-21 10:30')
  ];
  const r = at(items, '2026-11-21 09:00');
  assert.deepEqual(titles(r.now), ['Opening']);
  assert.deepEqual(titles(r.next), ['Ditch']);
  assert.equal(r.more, 0);
  assert.deepEqual([r.day, r.min], ['2026-11-21', 540]);
});

test('entries carry the item, whether it is ongoing, and its board start and end', () => {
  const r = at([it('Feast', 'Feast and Food', '2026-11-21 18:00', '2026-11-21 21:00', { Location: 'Hall' })], '2026-11-21 19:00');
  assert.deepEqual(r.now[0].start, { day: '2026-11-21', min: 1080 });
  assert.deepEqual(r.now[0].end, { day: '2026-11-21', min: 1260 });
  assert.equal(r.now[0].ongoing, false);
  assert.equal(r.now[0].item.Location, 'Hall');
});

test('after midnight belongs to the previous board day, and next stops at 5am', () => {
  const items = [
    it('Bardic', 'Class', '2026-11-21 23:00', '2026-11-22 02:00'),
    it('Night Quest', 'Roleplay and Quests', '2026-11-22 02:00', '2026-11-22 03:00'),
    it('Sunday Troll', 'Administrative', '2026-11-22 08:00', '2026-11-22 09:00')
  ];
  const r = at(items, '2026-11-22 01:00');
  assert.equal(r.day, '2026-11-21');
  assert.equal(r.min, 1500);
  assert.deepEqual(titles(r.now), ['Bardic']);
  assert.deepEqual(titles(r.next), ['Night Quest'], 'Sunday morning is another board day');
});

test('ongoing items come after ordinary ones on now', () => {
  const items = [
    it('Troll', 'Administrative', '2026-11-21 09:00', '2026-11-21 16:00'),
    it('Tavern', 'Feast and Food', '2026-11-21 08:00', '2026-11-22 01:00'),
    it('Ditch', 'Battlegame', '2026-11-21 10:00', '2026-11-21 11:00'),
    it('Arts', 'Arts and Sciences', '2026-11-21 09:30', '2026-11-21 12:00')
  ];
  const r = at(items, '2026-11-21 10:15');
  assert.deepEqual(titles(r.now), ['Arts', 'Ditch', 'Tavern', 'Troll']);
  assert.deepEqual(r.now.map(e => e.ongoing), [false, false, true, true]);
});

test('now holds at most four and counts the rest; next holds at most three', () => {
  const items = [];
  for (let i = 0; i < 6; i++) items.push(it('On ' + i, 'Battlegame', '2026-11-21 10:0' + i, '2026-11-21 12:00'));
  for (let i = 0; i < 5; i++) items.push(it('Later ' + i, 'Class', '2026-11-21 1' + (3 + i) + ':00', '2026-11-21 1' + (3 + i) + ':30'));
  const r = at(items, '2026-11-21 11:00');
  assert.deepEqual(titles(r.now), ['On 0', 'On 1', 'On 2', 'On 3']);
  assert.equal(r.more, 2);
  assert.deepEqual(titles(r.next), ['Later 0', 'Later 1', 'Later 2']);
});

test('the same start sorts by title', () => {
  const items = [it('B', 'Class', '2026-11-21 13:00', '2026-11-21 14:00'), it('A', 'Class', '2026-11-21 13:00', '2026-11-21 14:00')];
  assert.deepEqual(titles(at(items, '2026-11-21 12:00').next), ['A', 'B']);
});

test('null before the event, after it, and at 4:59am on the first day', () => {
  const items = [it('X', 'Class', '2026-11-21 10:00', '2026-11-21 11:00')];
  assert.equal(at(items, '2026-11-19 12:00'), null);
  assert.equal(at(items, '2026-11-20 04:59'), null, '4:59am is the night before the first board day');
  assert.notEqual(at(items, '2026-11-20 05:00'), null);
  assert.notEqual(at(items, '2026-11-23 04:59'), null, 'the last board day runs to 5am');
  assert.equal(at(items, '2026-11-23 05:00'), null);
  assert.equal(at(items, '2026-12-01 12:00'), null);
});

test('null without a usable clock or event dates; empty lists when nothing is on', () => {
  assert.equal(C.nowNext([], null, ES, EE), null);
  assert.equal(C.nowNext([], 'garbage', ES, EE), null);
  assert.equal(C.nowNext([], '2026-11-21 10:00', '', ''), null);
  assert.deepEqual(C.nowNext([], '2026-11-21 10:00', ES, EE), { day: '2026-11-21', min: 600, now: [], more: 0, next: [] });
});

test('zero-length and timeless items are never on now', () => {
  const items = [it('Gate', 'Other', '2026-11-21 10:00', '2026-11-21 10:00'), it('Bad', 'Other', 'nope', 'nope')];
  const r = at(items, '2026-11-21 10:00');
  assert.deepEqual([r.now.length, r.next.length], [0, 0]);
});

test('an end at or before its board day\'s 5am is told on that board day, as one at 1am is', () => {
  const r = at([
    it('Tavern', 'Feast and Food', '2026-11-21 09:00', '2026-11-22 05:00'),
    it('Bardic', 'Class', '2026-11-21 21:00', '2026-11-22 01:00'),
    it('Vigil', 'Other', '2026-11-21 21:00', '2026-11-22 06:00')
  ], '2026-11-21 22:00');
  const end = t => r.now.find(e => e.item.Title === t).end;
  assert.deepEqual(end('Tavern'), { day: '2026-11-21', min: 1740 });
  assert.deepEqual(end('Bardic'), { day: '2026-11-21', min: 1500 });
  // Past 5am it is the next board day.
  assert.deepEqual(end('Vigil'), { day: '2026-11-22', min: 360 });
});

test('an end at 5am is told on now\'s board day for an item that started on an earlier board day', () => {
  const r = at([
    it('Tavern', 'Feast and Food', '2026-11-21 09:00', '2026-11-22 05:00'),
    it('Troll', 'Administrative', '2026-11-20 18:00', '2026-11-22 05:00'),
    it('Longer', 'Other', '2026-11-20 18:00', '2026-11-22 06:00')
  ], '2026-11-21 15:00');
  const end = t => r.now.find(e => e.item.Title === t).end;
  assert.deepEqual(end('Tavern'), { day: '2026-11-21', min: 1740 });
  assert.deepEqual(end('Troll'), { day: '2026-11-21', min: 1740 }, 'both end at the same moment');
  assert.deepEqual(end('Longer'), { day: '2026-11-22', min: 360 }, 'past 5am it is the next board day');
});
