const test = require('node:test');
const assert = require('node:assert/strict');
const I = require('../../orkui/template/revised-frontend/script/event-board/evb-image.js');
const measure = s => s.length * 7;

test('a long title wraps on word boundaries', () => {
  // 70px holds 10 characters.
  assert.deepEqual(I.wrapLines(measure, 'Grand Melee at the north field', 70, 5),
    ['Grand', 'Melee at', 'the north', 'field']);
});
test('a single word wider than the block is broken', () => {
  assert.deepEqual(I.wrapLines(measure, 'Supercalifragilistic', 70, 5), ['Supercalif', 'ragilistic']);
});
test('the last allowed line ends with an ellipsis when text remains', () => {
  const lines = I.wrapLines(measure, 'Grand Melee at the north field', 70, 2);
  assert.equal(lines.length, 2);
  assert.equal(lines[0], 'Grand');
  assert.ok(lines[1].endsWith('…'));
  assert.ok(measure(lines[1]) <= 70);
});
test('an empty string returns an empty array', () => {
  assert.deepEqual(I.wrapLines(measure, '', 70, 3), []);
});
test('file names are lower-case slugs ending -schedule.png', () => {
  assert.equal(I.fileName('NLCC Fall End Reign!'), 'nlcc-fall-end-reign-schedule.png');
  assert.equal(I.fileName('  ***  '), 'schedule.png');
});
test('fitScale keeps the wanted scale for a normal board', () => {
  assert.equal(I.fitScale(1200, 900, 2), 2);
});
test('fitScale holds each side to 16384 device pixels', () => {
  const s = I.fitScale(40000, 800, 2);
  assert.ok(s <= 16384 / 40000, String(s));
  assert.ok(Math.ceil(40000 * s) <= 16384);
  assert.ok(s > 0.4);
  const tall = I.fitScale(800, 20000, 2);
  assert.ok(Math.ceil(20000 * tall) <= 16384);
});
test('fitScale holds the area to 100,000,000 pixels', () => {
  const s = I.fitScale(9000, 9000, 2);
  assert.ok(Math.ceil(9000 * s) * Math.ceil(9000 * s) <= 100000000, String(s));
  assert.ok(s > 1.1);
});
test('the image is built from the real items only, on the view-mode axis', () => {
  const real = { cid: 'i1', Title: 'Real', Category: 'Other', StartTime: '2026-11-21 10:00', EndTime: '2026-11-21 11:00' };
  // What an Edit-mode view holds: its board also has a ghost and the widened axis.
  const view = { getItems: () => [real], board: { days: [], axisStart: 420, axisEnd: 1440 } };
  const m = I.modelFor(view, { eventStart: '2026-11-21 09:00', eventEnd: '2026-11-21 17:00' });
  const titles = m.days.flatMap(d => d.segs.map(s => s.item.Title));
  assert.deepEqual(titles, ['Real']);
  assert.deepEqual([m.axisStart, m.axisEnd], [600, 660]);
});
