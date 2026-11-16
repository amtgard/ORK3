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
