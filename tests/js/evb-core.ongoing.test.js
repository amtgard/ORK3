const test = require('node:test');
const assert = require('node:assert/strict');
const C = require('../../orkui/template/revised-frontend/script/event-board/evb-core.js');

const opts = { eventStart: '2026-11-20 09:00', eventEnd: '2026-11-22 16:00', editing: false };
const it = (Title, Category, StartTime, EndTime, more) => Object.assign({ Title, Category, StartTime, EndTime }, more);
const sat = (h, m) => '2026-11-21 ' + h + ':' + (m || '00');
const saturday = [
  it('Troll', 'Administrative', sat('09'), sat('16')),
  it('Tavern', 'Feast and Food', sat('09'), '2026-11-22 01:00'),
  it('Bridge', 'Battlegame', sat('10'), sat('11')),
  it('Ditch', 'Battlegame', sat('10'), sat('11')),
  it('Militia', 'Battlegame', sat('10'), sat('11')),
  it('Capture', 'Battlegame', sat('10'), sat('11'))
];
const day = (b, d) => b.days.find(x => x.day === d);

test('ONGOING_MIN is four hours', () => {
  assert.equal(C.ONGOING_MIN, 240);
});

test('Troll and Tavern become rails and no longer squeeze the battlegames', () => {
  const sa = day(C.buildBoard(saturday, opts), '2026-11-21');
  assert.equal(sa.lanes, 4, 'four lanes, not six');
  assert.equal(sa.railCount, 2);
  // By start, then title: both start at 9am.
  assert.deepEqual(sa.rails.map(s => [s.item.Title, s.rail]), [['Tavern', 0], ['Troll', 1]]);
  assert.deepEqual(sa.segs.map(s => s.item.Title).sort(), ['Bridge', 'Capture', 'Ditch', 'Militia']);
  sa.segs.forEach(s => assert.equal(s.width, 25, s.item.Title));
  // The axis still reaches the rails' times.
  const b = C.buildBoard(saturday, opts);
  assert.equal(b.axisStart, 540);
  assert.equal(b.axisEnd, 1500);
});

test('days without rails carry an empty list', () => {
  const b = C.buildBoard(saturday, opts);
  assert.deepEqual([day(b, '2026-11-20').rails, day(b, '2026-11-20').railCount], [[], 0]);
});

test('not ongoing: under four hours, the wrong primary category, a secondary category alone', () => {
  assert.equal(C.isOngoing(it('Gate', 'Administrative', sat('09'), sat('12', '59'))), false, '239 minutes');
  assert.equal(C.isOngoing(it('Gate', 'Administrative', sat('09'), sat('13'))), true, '240 minutes');
  assert.equal(C.isOngoing(it('War', 'Battlegame', sat('09'), sat('14'))), false, 'a five-hour battlegame');
  assert.equal(C.isOngoing(it('Quest', 'Roleplay and Quests', sat('09'), sat('17'), { SecondaryCategory: 'Administrative' })), false);
  assert.equal(C.isOngoing(it('Lunch', 'Feast and Food', sat('11'), sat('15'))), true);
  assert.equal(C.isOngoing(it('Bad', 'Administrative', 'nope', 'nope')), false);
  // None of the three is drawn as a rail.
  const b = C.buildBoard([
    it('Gate', 'Administrative', sat('09'), sat('12', '59')),
    it('War', 'Battlegame', sat('09'), sat('14')),
    it('Quest', 'Roleplay and Quests', sat('09'), sat('17'), { SecondaryCategory: 'Administrative' })
  ], opts);
  assert.equal(day(b, '2026-11-21').railCount, 0);
  assert.equal(day(b, '2026-11-21').lanes, 3);
});

test('an ongoing item past 5am is split into a rail on each board day', () => {
  const vigil = it('Overnight Troll', 'Administrative', '2026-11-20 20:00', '2026-11-21 10:00');
  const b = C.buildBoard([vigil, it('Ditch', 'Battlegame', sat('09'), sat('10'))], opts);
  const fri = day(b, '2026-11-20'), sa = day(b, '2026-11-21');
  assert.deepEqual(fri.rails.map(s => [s.startMin, s.endMin, s.isFirst, s.isLast, s.rail]), [[1200, 1740, true, false, 0]]);
  assert.deepEqual(sa.rails.map(s => [s.startMin, s.endMin, s.isFirst, s.isLast, s.rail]), [[300, 600, false, true, 0]]);
  assert.equal(fri.segs.length, 0);
  assert.equal(sa.lanes, 1);
});

test('opts.notRail keeps one item in a lane (the block being typed)', () => {
  const typed = it('Troll', 'Administrative', sat('09'), sat('16'));
  const b = C.buildBoard([typed, it('Ditch', 'Battlegame', sat('10'), sat('11'))], Object.assign({ notRail: typed }, opts));
  const sa = day(b, '2026-11-21');
  assert.equal(sa.railCount, 0);
  assert.equal(sa.lanes, 2);
});
