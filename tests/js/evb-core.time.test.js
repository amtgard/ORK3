const test = require('node:test');
const assert = require('node:assert/strict');
const C = require('../../orkui/template/revised-frontend/script/event-board/evb-core.js');

test('parseNaive', () => {
  assert.deepEqual(C.parseNaive('2026-11-20 09:00:00'), { day: '2026-11-20', min: 540 });
  assert.deepEqual(C.parseNaive('2026-11-20T18:30'), { day: '2026-11-20', min: 1110 });
  assert.equal(C.parseNaive(''), null);
  assert.equal(C.parseNaive('nonsense'), null);
});
test('toNaive rolls days both ways', () => {
  assert.equal(C.toNaive('2026-11-20', 540), '2026-11-20 09:00');
  assert.equal(C.toNaive('2026-11-20', 1500), '2026-11-21 01:00');
  assert.equal(C.toNaive('2026-11-20', -30), '2026-11-19 23:30');
});
test('day arithmetic ignores time zones and DST', () => {
  assert.equal(C.addDays('2026-11-30', 1), '2026-12-01');
  assert.equal(C.addDays('2026-03-08', 1), '2026-03-09');
  assert.equal(C.addDays('2026-11-01', -1), '2026-10-31');
  assert.equal(C.diffDays('2026-11-20', '2026-11-22'), 2);
  assert.equal(C.dayLabel('2026-11-20'), 'Friday');
  assert.equal(C.dayLabelLong('2026-11-20'), 'Friday, November 20');
});
test('time labels', () => {
  assert.equal(C.fmtTime(570), '9:30a');
  assert.equal(C.fmtTime(720), '12p');
  assert.equal(C.fmtTime(0), '12a');
  assert.equal(C.fmtTime(1500), '1a');
  assert.equal(C.fmtRange(570, 615), '9:30–10:15a');
  assert.equal(C.fmtRange(690, 750), '11:30a–12:30p');
  assert.equal(C.fmtRange(720, 780), '12–1p');
  assert.equal(C.fmtRange(1380, 1500), '11p–1a');
});
test('a zero-length range reads as one time', () => {
  assert.equal(C.fmtRange(540, 540), '9a');
  assert.equal(C.fmtRange(720, 720), '12p');
});
test('an evening item past midnight stays in its evening column', () => {
  const s = C.segments({ StartTime: '2026-11-20 22:00:00', EndTime: '2026-11-21 01:00:00' });
  assert.equal(s.length, 1);
  assert.deepEqual([s[0].day, s[0].startMin, s[0].endMin, s[0].isFirst, s[0].isLast],
    ['2026-11-20', 1320, 1500, true, true]);
});
test('an item starting after midnight belongs to the previous board day', () => {
  const s = C.segments({ StartTime: '2026-11-21 00:30:00', EndTime: '2026-11-21 01:30:00' });
  assert.deepEqual([s.length, s[0].day, s[0].startMin, s[0].endMin], [1, '2026-11-20', 1470, 1530]);
});
test('an item crossing 5am splits into one segment per board day', () => {
  const s = C.segments({ StartTime: '2026-11-20 22:00:00', EndTime: '2026-11-21 09:00:00' });
  assert.equal(s.length, 2);
  assert.deepEqual([s[0].day, s[0].startMin, s[0].endMin, s[0].isFirst, s[0].isLast], ['2026-11-20', 1320, 1740, true, false]);
  assert.deepEqual([s[1].day, s[1].startMin, s[1].endMin, s[1].isFirst, s[1].isLast], ['2026-11-21', 300, 540, false, true]);
});
test('ending exactly at 5am leaves no empty segment; zero-length items survive', () => {
  assert.equal(C.segments({ StartTime: '2026-11-20 22:00:00', EndTime: '2026-11-21 05:00:00' }).length, 1);
  const z = C.segments({ StartTime: '2026-11-20 09:00:00', EndTime: '2026-11-20 09:00:00' });
  assert.deepEqual([z.length, z[0].startMin, z[0].endMin], [1, 540, 540]);
  const bad = C.segments({ StartTime: '2026-11-20 10:00:00', EndTime: '2026-11-20 09:00:00' });
  assert.deepEqual([bad.length, bad[0].startMin, bad[0].endMin], [1, 600, 600]);
});
test('an item with an absurd end date is capped at 31 segments', () => {
  const s = C.segments({ StartTime: '2026-11-20 09:00:00', EndTime: '9999-01-01 09:00:00' });
  assert.equal(s.length, 31);
  assert.equal(s[30].isLast, false);
});
test('lateNight names the calendar day and the night of an after-midnight segment', () => {
  assert.equal(C.lateNight('2026-11-21', 1439), null);
  assert.equal(C.lateNight('2026-11-21', 600), null);
  assert.deepEqual(C.lateNight('2026-11-21', 1440), { day: 'Sunday', dayISO: '2026-11-22', night: 'Saturday night' });
  assert.deepEqual(C.lateNight('2026-11-21', 1500), { day: 'Sunday', dayISO: '2026-11-22', night: 'Saturday night' });
  // Across a month end and a year end.
  assert.deepEqual(C.lateNight('2026-11-30', 1500), { day: 'Tuesday', dayISO: '2026-12-01', night: 'Monday night' });
  assert.deepEqual(C.lateNight('2026-12-31', 1440), { day: 'Friday', dayISO: '2027-01-01', night: 'Thursday night' });
  assert.equal(C.lateNight('2026-12-31', 1439), null);
});
