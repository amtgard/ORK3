const test = require('node:test');
const assert = require('node:assert/strict');
const C = require('../../orkui/template/revised-frontend/script/event-board/evb-core.js');

const it = (start, end, more) => Object.assign({ Title: 'T', Category: 'Battlegame', StartTime: start, EndTime: end, Leads: [] }, more);

test('snap and boardPos', () => {
  assert.deepEqual([C.snap(607, 15), C.snap(608, 15), C.snap(607, 5)], [600, 615, 605]);
  assert.deepEqual(C.boardPos('2026-11-21 09:30:00'), { day: '2026-11-21', min: 570 });
  assert.deepEqual(C.boardPos('2026-11-22 00:30'), { day: '2026-11-21', min: 1470 });
  assert.deepEqual(C.boardPos('2026-11-22 05:00'), { day: '2026-11-22', min: 300 });
});
test('moving keeps the length and stores real calendar dates', () => {
  const a = it('2026-11-21 09:30:00', '2026-11-21 10:15:00');
  assert.deepEqual(C.moveTimes(a, '2026-11-21', 600), { StartTime: '2026-11-21 10:00', EndTime: '2026-11-21 10:45' });
  assert.deepEqual(C.moveTimes(a, '2026-11-20', 570), { StartTime: '2026-11-20 09:30', EndTime: '2026-11-20 10:15' });
  assert.deepEqual(C.moveTimes(a, '2026-11-21', 1470), { StartTime: '2026-11-22 00:30', EndTime: '2026-11-22 01:15' });
  assert.deepEqual(C.shiftTimes(a, 15, 0), { StartTime: '2026-11-21 09:45', EndTime: '2026-11-21 10:30' });
  assert.deepEqual(C.shiftTimes(a, -15, 1), { StartTime: '2026-11-22 09:15', EndTime: '2026-11-22 10:00' });
  const late = it('2026-11-21 23:30:00', '2026-11-22 00:30:00');
  assert.deepEqual(C.shiftTimes(late, 60, 0), { StartTime: '2026-11-22 00:30', EndTime: '2026-11-22 01:30' });
});
test('resizing clamps at five minutes and never inverts', () => {
  const a = it('2026-11-21 09:30:00', '2026-11-21 10:15:00');
  assert.deepEqual(C.resizeTimes(a, 'end', 660), { StartTime: '2026-11-21 09:30', EndTime: '2026-11-21 11:00' });
  assert.deepEqual(C.resizeTimes(a, 'end', 540), { StartTime: '2026-11-21 09:30', EndTime: '2026-11-21 09:35' });
  assert.deepEqual(C.resizeTimes(a, 'start', 540), { StartTime: '2026-11-21 09:00', EndTime: '2026-11-21 10:15' });
  assert.deepEqual(C.resizeTimes(a, 'start', 700), { StartTime: '2026-11-21 10:10', EndTime: '2026-11-21 10:15' });
  assert.deepEqual(C.resizeTimes(a, 'end', 1470), { StartTime: '2026-11-21 09:30', EndTime: '2026-11-22 00:30' });
});
test('repeat follows the rhythm of the previous item of the same category', () => {
  const first = it('2026-11-21 09:30:00', '2026-11-21 10:15:00', { Title: 'A' });
  const second = it('2026-11-21 10:45:00', '2026-11-21 11:30:00', { Title: 'B' });
  const other = it('2026-11-21 10:15:00', '2026-11-21 10:45:00', { Title: 'Quest', Category: 'Other' });
  assert.deepEqual(C.repeatSlots(second, [first, second, other], 870), [
    { StartTime: '2026-11-21 12:00', EndTime: '2026-11-21 12:45' },
    { StartTime: '2026-11-21 13:15', EndTime: '2026-11-21 14:00' },
  ]);
  assert.deepEqual(C.repeatSlots(first, [first], 720), [{ StartTime: '2026-11-21 10:45', EndTime: '2026-11-21 11:30' }]);
  assert.deepEqual(C.repeatSlots(first, [first], 630), []);
  const overlapping = it('2026-11-21 09:00:00', '2026-11-21 10:00:00', { Title: 'Z' });
  assert.equal(C.repeatSlots(first, [overlapping, first], 720)[0].StartTime, '2026-11-21 10:45');
});
test('duplicate, paste and clipboard text', () => {
  const a = it('2026-11-21 09:30:00', '2026-11-21 10:15:00', { Title: 'A', EventScheduleId: 5, cid: 'i5', Location: 'Field', Leads: [{ MundaneId: 2, Persona: 'Luna' }], Menu: 'Soup' });
  const b = it('2026-11-21 11:00:00', '2026-11-21 11:30:00', { Title: 'B', EventScheduleId: 6, cid: 'i6' });
  assert.deepEqual(C.duplicateTimes(a), { StartTime: '2026-11-21 10:15', EndTime: '2026-11-21 11:00' });
  const pasted = C.pastePlan([b, a], '2026-11-22', 600);
  assert.deepEqual(pasted.map(p => [p.Title, p.StartTime, p.EndTime]), [
    ['A', '2026-11-22 10:00', '2026-11-22 10:45'], ['B', '2026-11-22 11:30', '2026-11-22 12:00'],
  ]);
  assert.deepEqual([pasted[0].EventScheduleId, pasted[0].cid, pasted[0].Location, pasted[0].Menu, pasted[0].Leads.length], [undefined, undefined, 'Field', 'Soup', 1]);
  pasted[0].Leads.push({});
  assert.equal(a.Leads.length, 1);
  assert.equal(C.clipboardText([b, a]).split('\n').length, 2);
  assert.ok(C.clipboardText([b, a]).startsWith('sat 9:30'));
});
test('paste keeps Auto flags on copied leads and shares no objects', () => {
  const a = it('2026-11-21 09:30', '2026-11-21 10:15', { cid: 'i1', Leads: [{ MundaneId: 2, Persona: 'Luna', Auto: true }] });
  const p = C.pastePlan([a], '2026-11-21', 600);
  assert.equal(p[0].Leads[0].Auto, true);
  p[0].Leads[0].Persona = 'X';
  assert.equal(a.Leads[0].Persona, 'Luna');
  assert.deepEqual(C.pastePlan([], '2026-11-21', 600), []);
});
test('early-morning items keep their board day through resize and repeat', () => {
  const n = it('2026-11-22 00:30', '2026-11-22 01:00');
  assert.deepEqual(C.resizeTimes(n, 'end', 1560), { StartTime: '2026-11-22 00:30', EndTime: '2026-11-22 02:00' });
  assert.deepEqual(C.repeatSlots(n, [n], 1560), [{ StartTime: '2026-11-22 01:30', EndTime: '2026-11-22 02:00' }]);
});
test('a drag moves an item by the columns crossed, from its own start day', () => {
  // 4-6am belongs to board day 2026-11-20; its second segment shows in the 2026-11-21 column.
  const x = it('2026-11-21 04:00', '2026-11-21 06:00');
  assert.deepEqual(C.dragTarget(x, '2026-11-21', '2026-11-21', 0, 15), { StartTime: '2026-11-21 04:00', EndTime: '2026-11-21 06:00' });
  assert.deepEqual(C.dragTarget(x, '2026-11-21', '2026-11-21', 6, 15), { StartTime: '2026-11-21 04:00', EndTime: '2026-11-21 06:00' });
  assert.deepEqual(C.dragTarget(x, '2026-11-21', '2026-11-22', 0, 15), { StartTime: '2026-11-22 04:00', EndTime: '2026-11-22 06:00' });
  const a = it('2026-11-20 09:00', '2026-11-20 10:00');
  assert.deepEqual(C.dragTarget(a, '2026-11-20', '2026-11-21', 30, 15), { StartTime: '2026-11-21 09:30', EndTime: '2026-11-21 10:30' });
  // Snapped to the step outside the half-step dead zone; clamped to the bounds.
  const odd = it('2026-11-20 09:07', '2026-11-20 10:07');
  assert.deepEqual(C.dragTarget(odd, '2026-11-20', '2026-11-20', 7, 15), { StartTime: '2026-11-20 09:07', EndTime: '2026-11-20 10:07' });
  assert.deepEqual(C.dragTarget(odd, '2026-11-20', '2026-11-20', 8, 15), { StartTime: '2026-11-20 09:15', EndTime: '2026-11-20 10:15' });
  assert.deepEqual(C.dragTarget(a, '2026-11-20', '2026-11-20', -300, 15, { min: 420, max: 1425 }),
    { StartTime: '2026-11-20 07:00', EndTime: '2026-11-20 08:00' });
});
test('a group moves only as far as its first and last members allow (the board range)', () => {
  const range = C.boardRange(['2026-11-20', '2026-11-21', '2026-11-22']);
  const a = it('2026-11-20 09:00:00', '2026-11-20 10:00:00');
  const b = it('2026-11-22 22:00:00', '2026-11-23 04:00:00');
  // b may go only 60 minutes later (to 5am after the last day); a only 240 earlier (to 5am on the first).
  assert.deepEqual(C.groupShiftLimit([a, b], 360, 0, { range }), { dMin: 60, dDays: 0 });
  assert.deepEqual(C.groupShiftLimit([a, b], -700, 0, { range }), { dMin: -240, dDays: 0 });
  assert.deepEqual(C.groupShiftLimit([a, b], 30, 0, { range }), { dMin: 30, dDays: 0 });
  const fri = it('2026-11-20 09:00:00', '2026-11-20 10:00:00');
  const sun = it('2026-11-22 09:00:00', '2026-11-22 10:00:00');
  assert.deepEqual(C.groupShiftLimit([fri, sun], 0, 2, { range }), { dMin: 0, dDays: 0 });
  assert.deepEqual(C.groupShiftLimit([fri, sun], 0, -1, { range }), { dMin: 0, dDays: 0 });
  // A single item is limited the same way.
  assert.deepEqual(C.groupShiftLimit([fri], 0, 2, { range }), { dMin: 0, dDays: 2 });
  assert.deepEqual(C.groupShiftLimit([fri], 0, 3, { range }), { dMin: 0, dDays: 2 });
  // Without a range the move is as asked.
  assert.deepEqual(C.groupShiftLimit([fri], 45, 3), { dMin: 45, dDays: 3 });
});
