const test = require('node:test');
const assert = require('node:assert/strict');
const C = require('../../orkui/template/revised-frontend/script/event-board/evb-core.js');
const items = require('./fixtures/sheet-schedule.js');
const opts = { eventStart: '2026-11-20T09:00', eventEnd: '2026-11-22T16:00', editing: false };
const find = (b, day, title) => b.days.find(d => d.day === day).segs.find(s => s.item.Title === title);

test('the published sheet lays out as 2, 4 and 1 lanes', () => {
  const b = C.buildBoard(items, opts);
  assert.deepEqual(b.days.map(d => [d.day, d.label, d.lanes]),
    [['2026-11-20', 'Friday', 2], ['2026-11-21', 'Saturday', 4], ['2026-11-22', 'Sunday', 1]]);
  assert.deepEqual([b.axisStart, b.axisEnd], [540, 1200]);
});
test('an item with nothing beside it spans the day', () => {
  const b = C.buildBoard(items, opts);
  const crat = find(b, '2026-11-20', 'Crat Setup');
  assert.deepEqual([crat.left, crat.width], [0, 100]);
});
test('items keep to the lane their category last used', () => {
  const b = C.buildBoard(items, opts);
  assert.equal(find(b, '2026-11-21', 'A&S Sign Ins Close').lane, find(b, '2026-11-21', 'A&S Setup Begins').lane);
  assert.equal(find(b, '2026-11-21', 'Good King Tobias: Protocol and Precedence').lane, find(b, '2026-11-21', 'Sir Adler: Train the Trainer Pt3').lane);
  assert.equal(find(b, '2026-11-20', 'RP Quest Starts').lane, find(b, '2026-11-20', 'Squatch Ball').lane);
});
test('overlapping items never share horizontal space', () => {
  const b = C.buildBoard(items, opts);
  b.days.forEach(d => d.segs.forEach(a => d.segs.forEach(o => {
    if (a === o || a.startMin >= o.endMin || o.startMin >= a.endMin) return;
    assert.ok(a.left + a.width <= o.left + 0.001 || o.left + o.width <= a.left + 0.001, a.item.Title + ' overlaps ' + o.item.Title);
  })));
});
test('empty schedule, edit axis, out-of-range days, unknown category, zero-length items', () => {
  const e = C.buildBoard([], opts);
  assert.deepEqual([e.days.length, e.axisStart, e.axisEnd], [3, 540, 1080]);
  const ed = C.buildBoard(items, { eventStart: opts.eventStart, eventEnd: opts.eventEnd, editing: true });
  assert.deepEqual([ed.axisStart, ed.axisEnd], [420, 1440]);
  const one = C.buildBoard([{ Title: 'Late add', StartTime: '2026-11-25 10:00:00', EndTime: '2026-11-25 11:00:00' }],
    { eventStart: '2026-11-21T09:00', eventEnd: '2026-11-21T18:00', editing: false });
  assert.deepEqual(one.days.map(d => d.day), ['2026-11-21', '2026-11-25']);
  assert.equal(one.days[1].segs[0].lane, 0);
  const z = C.buildBoard([
    { Title: 'A', Category: null, StartTime: '2026-11-21 09:00:00', EndTime: '2026-11-21 09:00:00' },
    { Title: 'B', Category: 'Nope', StartTime: '2026-11-21 09:00:00', EndTime: '2026-11-21 10:00:00' }
  ], { eventStart: '', eventEnd: '', editing: false });
  assert.deepEqual([z.days.length, z.days[0].lanes], [1, 2]);
});
test('the axis reaches the bottom of a very short last item', () => {
  const at = (s, e) => C.buildBoard([{ Title: 'X', StartTime: s, EndTime: e }], { eventStart: '', eventEnd: '', editing: false });
  assert.equal(at('2026-11-21 17:00:00', '2026-11-21 17:00:00').axisEnd, 1080);
  assert.equal(at('2026-11-21 16:50:00', '2026-11-21 16:55:00').axisEnd, 1080);
});
test('an empty day has one lane', () => {
  const b = C.buildBoard([], opts);
  assert.ok(b.days.length > 0);
  b.days.forEach(d => assert.equal(d.lanes, 1, d.day));
});
test('an end before the start still shows the start day', () => {
  const b = C.buildBoard([], { eventStart: '2026-11-21T09:00', eventEnd: '2026-11-20T09:00', editing: false });
  assert.deepEqual(b.days.map(d => d.day), ['2026-11-21']);
});
test('an absurd event length is capped', () => {
  const b = C.buildBoard([], { eventStart: '2026-11-20T09:00', eventEnd: '2099-01-01T09:00', editing: false });
  assert.ok(b.days.length > 0 && b.days.length <= 62, String(b.days.length));
});
