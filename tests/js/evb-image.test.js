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

// One-line blocks (30 minutes or less): time first, title cut to the rest.
test('a one-line block keeps its time and shortens the title with an ellipsis', () => {
  // 7px a character in both fonts; 200px leaves 159px (22 characters) for the title.
  const line = I.oneLine(measure, measure, '8:30p', 'Kingdom Warmaster Championship', 200);
  assert.equal(line.time, '8:30p');
  assert.equal(line.titleX, 5 * 7 + 6);
  assert.equal(line.title, 'Kingdom Warmaster Cha…');
  assert.ok(line.titleX + measure(line.title) <= 200);
});
test('a one-line block with room shows the whole title', () => {
  const line = I.oneLine(measure, measure, '8:30p', 'Court', 140);
  assert.deepEqual([line.time, line.title], ['8:30p', 'Court']);
});
test('a one-line block too narrow for any title still shows the time', () => {
  const line = I.oneLine(measure, measure, '8:30p', 'Court', 50);
  assert.equal(line.time, '8:30p');
  assert.equal(line.title, '');
});
test('fitText cuts by character and ends with an ellipsis', () => {
  assert.equal(I.fitText(measure, 'Kingdom Warmaster', 70), 'Kingdom W…');
  assert.equal(I.fitText(measure, 'Kingdom Warmaster', 63), 'Kingdom…');
  assert.equal(I.fitText(measure, 'Court', 70), 'Court');
  assert.equal(I.fitText(measure, 'Court', 7), '');
});

// A single-day picture: lanes about 1,080px in all, 190px floor, 360px cap.
test('a one-day picture widens its lanes toward 1,080px', () => {
  assert.equal(I.dayLaneWidth(4), 270);
  assert.equal(I.dayLaneWidth(5), 216);
});
test('one-day lanes are capped at 360px', () => {
  assert.equal(I.dayLaneWidth(1), 360);
  assert.equal(I.dayLaneWidth(3), 360);
  assert.equal(I.dayLaneWidth(0), 360);
});
test('one-day lanes are never narrower than the all-days lane', () => {
  assert.equal(I.dayLaneWidth(6), 190);
  assert.equal(I.dayLaneWidth(12), 190);
});

// Midnight markers: only in columns with something at or after midnight.
test('midnight markers go only on columns that reach past midnight', () => {
  const seg = (startMin, endMin) => ({ startMin, endMin });
  const days = [
    { day: '2026-11-20', segs: [seg(1080, 1380)] },            // ends 11pm
    { day: '2026-11-21', segs: [seg(1320, 1500)] },            // 10pm to 1am
    { day: '2026-11-22', segs: [seg(600, 660), seg(1470, 1500)] }, // 12:30am
    { day: '2026-11-23', segs: [seg(1380, 1440)] },            // ends at midnight
    { day: '2026-11-24', segs: [] }
  ];
  assert.deepEqual(I.midnightMarks(days), [
    { index: 1, day: 'Sunday' },
    { index: 2, day: 'Monday' }
  ]);
});
test('day choices use the plain day names for a week or less', () => {
  const opts = I.dayChoices(['2026-11-20', '2026-11-21', '2026-11-22']);
  assert.deepEqual(opts.map(o => o.label), ['All days', 'Friday', 'Saturday', 'Sunday']);
  assert.deepEqual(opts.map(o => o.day), [null, '2026-11-20', '2026-11-21', '2026-11-22']);
});
test('day choices for eight or more days use the long label, so no two match', () => {
  const days = ['2026-11-13', '2026-11-14', '2026-11-15', '2026-11-16',
    '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20'];
  const labels = I.dayChoices(days).map(o => o.label);
  assert.equal(labels.length, 9);
  assert.equal(new Set(labels).size, 9);
  assert.equal(labels[1], 'Friday, November 13');
  assert.equal(labels[8], 'Friday, November 20');
});
test('day choices for exactly seven days still use the plain weekday labels', () => {
  const days = ['2026-11-14', '2026-11-15', '2026-11-16', '2026-11-17',
    '2026-11-18', '2026-11-19', '2026-11-20'];
  const labels = I.dayChoices(days).map(o => o.label);
  assert.equal(labels.length, 8);
  assert.equal(new Set(labels).size, 8);
  assert.equal(labels[1], 'Saturday');
});
test('a one-day or empty board offers no day choice', () => {
  assert.deepEqual(I.dayChoices(['2026-11-21']), []);
  assert.deepEqual(I.dayChoices([]), []);
});
test('a one-day board is always drawn as its day', () => {
  assert.equal(I.pictureDay(['2026-11-21'], null), '2026-11-21');
  assert.equal(I.pictureDay(['2026-11-21'], '2026-11-30'), '2026-11-21');
});
test('a chosen day the board no longer has falls back to all days', () => {
  const days = ['2026-11-20', '2026-11-21'];
  assert.equal(I.pictureDay(days, '2026-11-21'), '2026-11-21');
  assert.equal(I.pictureDay(days, '2026-11-22'), null);
  assert.equal(I.pictureDay(days, null), null);
});

// Rails in the picture: narrow strips at the column's left, lanes in the rest.
test('a day with two rails and four lanes: rails at the left, lanes after them', () => {
  const C = require('../../orkui/template/revised-frontend/script/event-board/evb-core.js');
  const sat = h => '2026-11-21 ' + h;
  const items = [
    { Title: 'Troll', Category: 'Administrative', StartTime: sat('09:00'), EndTime: sat('16:00') },
    { Title: 'Tavern', Category: 'Feast and Food', StartTime: sat('09:00'), EndTime: '2026-11-22 01:00' }
  ];
  ['A', 'B', 'C', 'D'].forEach(t => items.push({ Title: t, Category: 'Battlegame', StartTime: sat('10:00'), EndTime: sat('11:00') }));
  const d = C.buildBoard(items, { eventStart: '', eventEnd: '' }).days[0];
  const laneW = I.dayLaneWidth(d.lanes);
  assert.equal(laneW, 270, 'the one-day lane rule counts the four lanes, not the rails');
  const col = I.columnLayout(d, laneW);
  assert.deepEqual([col.railW, col.lanesW, col.width], [48, 1080, 1128]);
  assert.deepEqual(col.rails.map(r => [r.seg.item.Title, r.x, r.w]), [['Tavern', 1, 22], ['Troll', 25, 22]]);
  assert.deepEqual(col.blocks.map(b => [b.x, b.w]).sort((a, b) => a[0] - b[0]), [[49, 268], [319, 268], [589, 268], [859, 268]]);
  // Widening shares the extra between the lanes; the rails keep their width.
  const wide = I.columnLayout(d, laneW, 1.5);
  assert.deepEqual([wide.railW, wide.lanesW], [48, 1620]);
  assert.equal(wide.rails[1].x, 25);
});
test('a day with no rails lays its lanes from the left edge', () => {
  const col = I.columnLayout({ lanes: 2, segs: [{ left: 50, width: 50 }] }, 190);
  assert.deepEqual([col.railW, col.width, col.rails.length], [0, 380, 0]);
  assert.deepEqual([col.blocks[0].x, col.blocks[0].w], [191, 188]);
});
test('a rail past midnight puts a midnight mark on its column', () => {
  const days = [{ day: '2026-11-21', segs: [], rails: [{ startMin: 540, endMin: 1500 }] }];
  assert.deepEqual(I.midnightMarks(days), [{ index: 0, day: 'Sunday' }]);
});
