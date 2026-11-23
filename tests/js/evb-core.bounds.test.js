const test = require('node:test');
const assert = require('node:assert/strict');
const C = require('../../orkui/template/revised-frontend/script/event-board/evb-core.js');

// A Friday-to-Sunday event: 20 to 22 November 2026.
const DAYS = ['2026-11-20', '2026-11-21', '2026-11-22'];
const RANGE = C.boardRange(DAYS);
const it = (start, end, more) => Object.assign({ Title: 'T', Category: 'Battlegame', StartTime: start, EndTime: end, Leads: [] }, more);
const moved = (item, lim) => C.shiftTimes(item, lim.dMin, lim.dDays);

// ---- the server's window (the store's safety net) ----

test('hardWindow: 00:00 seven days before the start date to 23:59 seven days after the end date', () => {
  assert.deepEqual(C.hardWindow('2026-11-20 09:00:00', '2026-11-22 16:00:00'), { start: '2026-11-13 00:00', end: '2026-11-29 23:59' });
  assert.deepEqual(C.hardWindow('2026-11-20', '2026-11-22'), { start: '2026-11-13 00:00', end: '2026-11-29 23:59' });
});
test('hardWindow: a missing, unreadable or earlier end date counts as the start date', () => {
  const one = { start: '2026-11-13 00:00', end: '2026-11-27 23:59' };
  assert.deepEqual(C.hardWindow('2026-11-20 09:00:00', ''), one);
  assert.deepEqual(C.hardWindow('2026-11-20 09:00:00', null), one);
  assert.deepEqual(C.hardWindow('2026-11-20 09:00:00', '0000-00-00 00:00:00'), one);
  assert.deepEqual(C.hardWindow('2026-11-20 09:00:00', 'soon'), one);
  assert.deepEqual(C.hardWindow('2026-11-20 09:00:00', '2026-11-19 09:00:00'), one);
  // An impossible date rolls over as the server reads it.
  assert.deepEqual(C.hardWindow('2026-11-20 09:00:00', '2026-11-31 09:00:00'), { start: '2026-11-13 00:00', end: '2026-12-08 23:59' });
});
test('hardWindow: no window when the start date cannot be read', () => {
  assert.equal(C.hardWindow('', '2026-11-22 16:00:00'), null);
  assert.equal(C.hardWindow('0000-00-00 00:00:00', '2026-11-22 16:00:00'), null);
});
test('timesRefusal: the server\'s two messages, and only for times that changed', () => {
  const w = C.hardWindow('2026-11-20 09:00:00', '2026-11-22 16:00:00');
  const OUT = 'That time is outside the event.', INV = 'An item cannot end before it starts.';
  assert.equal(C.timesRefusal(it('2026-11-13 00:00', '2026-11-13 01:00'), null, w), '');
  assert.equal(C.timesRefusal(it('2026-11-12 23:59', '2026-11-13 01:00'), null, w), OUT);
  assert.equal(C.timesRefusal(it('2026-11-29 23:59', '2026-11-30 03:00'), null, w), '');
  assert.equal(C.timesRefusal(it('2026-11-30 00:00', '2026-11-30 03:00'), null, w), OUT);
  assert.equal(C.timesRefusal(it('2026-11-21 10:00', '2026-11-21 09:59'), null, w), INV);
  assert.equal(C.timesRefusal(it('2026-11-21 10:00', '2026-11-21 10:00'), null, w), '', 'no length is allowed');
  assert.equal(C.timesRefusal(it('2026-12-25 10:00', '2026-12-25 11:00'), null, null), '', 'no window, no window refusal');
  // An update judges only what it changes: an old item outside the window may still be renamed; a change to either time is judged (line 44).
  const old = it('2026-12-25 10:00', '2026-12-25 11:00');
  assert.equal(C.timesRefusal(Object.assign({}, old, { Title: 'New' }), old, w), '');
  // A change to either time sends StartTime too, and the server judges the window then.
  assert.equal(C.timesRefusal(Object.assign({}, old, { EndTime: '2026-12-25 12:00' }), old, w), OUT);
  assert.equal(C.timesRefusal(Object.assign({}, old, { EndTime: '2026-12-25 09:00' }), old, w), INV, 'the order is judged first');
  assert.equal(C.timesRefusal(Object.assign({}, old, { StartTime: '2026-12-25 10:15' }), old, w), OUT);
});

// ---- the board's range ----

test('boardRange: 5am on the first board day to 5am after the last, in runs of shown days', () => {
  assert.deepEqual(RANGE, { firstDay: '2026-11-20', lastDay: '2026-11-22', start: '2026-11-20 05:00', end: '2026-11-23 05:00',
    runs: [{ start: '2026-11-20 05:00', end: '2026-11-23 05:00' }] });
  // A day shown only for an item, with days between it and the event: two runs.
  const gap = C.boardRange(['2026-11-25', '2026-11-20', '2026-11-21', '2026-11-22', 'bad']);
  assert.deepEqual([gap.start, gap.end], ['2026-11-20 05:00', '2026-11-26 05:00']);
  assert.deepEqual(gap.runs, [{ start: '2026-11-20 05:00', end: '2026-11-23 05:00' }, { start: '2026-11-25 05:00', end: '2026-11-26 05:00' }]);
  assert.equal(C.boardRange([]), null);
  assert.equal(C.boardRange(null), null);
});

// ---- one rule, for one item and for many ----

test('one item: a nudge past 5am after the last day stops there; with no room it does nothing', () => {
  const late = it('2026-11-23 04:00', '2026-11-23 04:50');
  assert.deepEqual(C.groupShiftLimit([late], 15, 0, { range: RANGE }), { dMin: 10, dDays: 0 });
  const atEdge = it('2026-11-23 04:00', '2026-11-23 05:00');
  assert.deepEqual(C.groupShiftLimit([atEdge], 15, 0, { range: RANGE }), { dMin: 0, dDays: 0 });
  assert.deepEqual(C.groupShiftLimit([atEdge], -15, 0, { range: RANGE }), { dMin: -15, dDays: 0 });
});
test('one item crossing midnight: it keeps its length and stops at the board\'s end', () => {
  const tavern = it('2026-11-22 23:30', '2026-11-23 04:45');
  const lim = C.groupShiftLimit([tavern], 30, 0, { range: RANGE });
  assert.deepEqual(lim, { dMin: 15, dDays: 0 });
  assert.deepEqual(moved(tavern, lim), { StartTime: '2026-11-22 23:45', EndTime: '2026-11-23 05:00' });
  // Saturday's crosses midnight and can still go a day on.
  const sat = it('2026-11-21 23:30', '2026-11-22 01:00');
  assert.deepEqual(C.groupShiftLimit([sat], 0, 1, { range: RANGE }), { dMin: 0, dDays: 1 });
  assert.deepEqual(C.groupShiftLimit([tavern], 0, 1, { range: RANGE }), { dMin: 0, dDays: 0 });
});
test('one item: at 5am on the first day it goes no earlier, and no day before the first or after the last', () => {
  const early = it('2026-11-20 05:10', '2026-11-20 06:00');
  assert.deepEqual(C.groupShiftLimit([early], -15, 0, { range: RANGE }), { dMin: -10, dDays: 0 });
  assert.deepEqual(C.groupShiftLimit([it('2026-11-20 05:00', '2026-11-20 06:00')], -15, 0, { range: RANGE }), { dMin: 0, dDays: 0 });
  assert.deepEqual(C.groupShiftLimit([early], 0, -1, { range: RANGE }), { dMin: 0, dDays: 0 });
  assert.deepEqual(C.groupShiftLimit([it('2026-11-22 09:00', '2026-11-22 10:00')], 0, 1, { range: RANGE }), { dMin: 0, dDays: 0 });
  assert.deepEqual(C.groupShiftLimit([it('2026-11-21 09:00', '2026-11-21 10:00')], 0, 1, { range: RANGE }), { dMin: 0, dDays: 1 });
});
test('a drag past the last column goes to the last day it can, never a new day', () => {
  const fri = it('2026-11-20 09:00', '2026-11-20 10:00');
  assert.deepEqual(C.groupShiftLimit([fri], 60, 5, { range: RANGE }), { dMin: 60, dDays: 2 });
  // A day the board does not show (Monday, between Sunday and an item's Wednesday) is never landed on.
  const gap = C.boardRange(['2026-11-20', '2026-11-21', '2026-11-22', '2026-11-25']);
  const sun = it('2026-11-22 09:00', '2026-11-22 10:00');
  assert.deepEqual(C.groupShiftLimit([sun], 0, 1, { range: gap }), { dMin: 0, dDays: 0 });
  assert.deepEqual(C.groupShiftLimit([sun], 0, 3, { range: gap }), { dMin: 0, dDays: 3 });
});
test('a group: the same rule, as far as all of it fits', () => {
  const a = it('2026-11-20 05:30', '2026-11-20 06:00');
  const b = it('2026-11-22 23:30', '2026-11-23 04:45');
  assert.deepEqual(C.groupShiftLimit([a, b], 30, 0, { range: RANGE }), { dMin: 15, dDays: 0 });
  assert.deepEqual(C.groupShiftLimit([a, b], -60, 0, { range: RANGE }), { dMin: -30, dDays: 0 });
  assert.deepEqual(C.groupShiftLimit([a, b], 0, 1, { range: RANGE }), { dMin: 0, dDays: 0 });
  const c = it('2026-11-20 10:00', '2026-11-20 11:00'), d = it('2026-11-21 23:00', '2026-11-22 01:00');
  assert.deepEqual(C.groupShiftLimit([c, d], 0, 1, { range: RANGE }), { dMin: 0, dDays: 1 });
  assert.deepEqual(C.groupShiftLimit([c, d], 0, 2, { range: RANGE }), { dMin: 0, dDays: 1 });
});

test('resize: an edge stops at 5am on the board\'s days', () => {
  const sun = it('2026-11-22 22:00', '2026-11-22 23:00');
  assert.deepEqual(C.resizeTimes(sun, 'end', 1800, RANGE), { StartTime: '2026-11-22 22:00', EndTime: '2026-11-23 05:00' });
  const fri = it('2026-11-20 06:00', '2026-11-20 07:00');
  assert.deepEqual(C.resizeTimes(fri, 'start', 240, RANGE), { StartTime: '2026-11-20 05:00', EndTime: '2026-11-20 07:00' });
  // Within the range it is as before.
  assert.deepEqual(C.resizeTimes(fri, 'end', 600, RANGE), { StartTime: '2026-11-20 06:00', EndTime: '2026-11-20 10:00' });
});

test('duplicate: the copy goes only as far as fits; with no room there is no copy', () => {
  const late = it('2026-11-23 03:00', '2026-11-23 04:30');
  assert.deepEqual(C.duplicateTimes(late, RANGE), { StartTime: '2026-11-23 03:30', EndTime: '2026-11-23 05:00' });
  assert.equal(C.duplicateTimes(it('2026-11-23 03:00', '2026-11-23 05:00'), RANGE), null);
  assert.deepEqual(C.duplicateTimes(it('2026-11-21 09:00', '2026-11-21 10:00'), RANGE), { StartTime: '2026-11-21 10:00', EndTime: '2026-11-21 11:00' });
  assert.deepEqual(C.duplicateTimes(late), { StartTime: '2026-11-23 04:30', EndTime: '2026-11-23 06:00' }, 'without a range: straight after');
});

test('block paste: placed only as far as all the copies fit, keeping their spacing; with no room, nothing', () => {
  const a = it('2026-11-21 09:00', '2026-11-21 10:00', { Title: 'A' });
  const b = it('2026-11-21 10:30', '2026-11-21 11:00', { Title: 'B' });
  const at = (day, min) => C.pastePlan([a, b], day, min, RANGE).map(p => [p.Title, p.StartTime, p.EndTime]);
  assert.deepEqual(at('2026-11-22', 600), [['A', '2026-11-22 10:00', '2026-11-22 11:00'], ['B', '2026-11-22 11:30', '2026-11-22 12:00']]);
  // At 3am Sunday night the last copy would end past 5am: they move back to end there.
  assert.deepEqual(at('2026-11-22', 1620), [['A', '2026-11-23 03:00', '2026-11-23 04:00'], ['B', '2026-11-23 04:30', '2026-11-23 05:00']]);
  // A day after the last: they go on the last day.
  assert.deepEqual(at('2026-11-23', 600), [['A', '2026-11-22 10:00', '2026-11-22 11:00'], ['B', '2026-11-22 11:30', '2026-11-22 12:00']]);
  // Longer than the board: no room.
  const huge = it('2026-11-20 05:00', '2026-11-23 06:00');
  assert.deepEqual(C.pastePlan([huge], '2026-11-21', 600, RANGE), []);
});

// ---- a weekday the event does not include ----

const parse = (text, eventDays, more) => C.parseEntry(text, Object.assign({ days: eventDays.slice(), eventDays, day: eventDays[0] }, more));

test('on a Friday-to-Sunday event a weekday outside it is the nearest such date, and outside', () => {
  const cases = { thu: '2026-11-19', mon: '2026-11-23', tue: '2026-11-24', wed: '2026-11-18' };
  Object.keys(cases).forEach(w => {
    const r = parse(w + ' 9a Arrival', DAYS);
    assert.deepEqual([r.ok, r.day, r.outside, r.start], [true, cases[w], true, cases[w] + ' 09:00'], w);
  });
  ['fri', 'sat', 'sun'].forEach((w, i) => {
    const r = parse(w + ' 9a Court', DAYS);
    assert.deepEqual([r.day, r.outside], [DAYS[i], false], w);
  });
  assert.equal(parse('9a Court', DAYS).outside, false);
});
test('on a one-day (Saturday) event every other weekday is the nearest such date', () => {
  const sat = ['2026-11-21'];
  const cases = { thu: '2026-11-19', fri: '2026-11-20', sun: '2026-11-22', mon: '2026-11-23', tue: '2026-11-24', wed: '2026-11-18' };
  Object.keys(cases).forEach(w => {
    const r = parse(w + ' 9a X', sat);
    assert.deepEqual([r.day, r.outside], [cases[w], true], w);
  });
  assert.deepEqual([parse('sat 9a X', sat).day, parse('sat 9a X', sat).outside], ['2026-11-21', false]);
});
test('the weekday is the event\'s, even when the board shows a later day with it', () => {
  // An item already sits on Thursday 26th; "thu" still means the 19th, the nearer.
  const r = parse('thu 9a Arrival', DAYS, { days: DAYS.concat(['2026-11-26']) });
  assert.deepEqual([r.day, r.outside], ['2026-11-19', true]);
});
test('a day word alone switches to the nearest date; an explicit date is unchanged', () => {
  assert.equal(parse('thu', DAYS).setDay, '2026-11-19');
  const d = parse('11/25 9a Pack up', DAYS);
  assert.deepEqual([d.day, d.outside], ['2026-11-25', true]);
  assert.deepEqual([parse('11/21 9a X', DAYS).day, parse('11/21 9a X', DAYS).outside], ['2026-11-21', false]);
});
test('without event days a weekday off the board is the nearest to the board\'s days, not a week on', () => {
  const r = C.parseEntry('thu 9a Arrival', { days: DAYS, day: DAYS[0] });
  assert.deepEqual([r.day, r.outside], ['2026-11-19', true]);
});
test('pasted lines and parseEntry agree on what is outside', () => {
  const r = C.parseLines('Thursday\n6p Arrival\nSat\n9a Court\n11/25 9a Pack', { days: DAYS, eventDays: DAYS });
  assert.deepEqual(r.items.map(i => i.StartTime), ['2026-11-19 18:00', '2026-11-21 09:00', '2026-11-25 09:00']);
  assert.deepEqual(r.outside.map(o => [o.line, o.day]), [[2, '2026-11-19'], [5, '2026-11-25']]);
  // A board day shown for an item is still outside the event.
  const shown = C.parseLines('11/25 10a Feast', { days: DAYS.concat(['2026-11-25']), eventDays: DAYS });
  assert.deepEqual(shown.outside.map(o => o.day), ['2026-11-25']);
  assert.equal(C.parseEntry('11/25 10a Feast', { days: DAYS.concat(['2026-11-25']), eventDays: DAYS }).outside, true);
});
test('eventDays and the notice\'s date label', () => {
  assert.deepEqual(C.eventDays('2026-11-20 09:00:00', '2026-11-22 16:00:00'), DAYS);
  assert.deepEqual(C.eventDays('2026-11-20 09:00:00', '2026-11-19 16:00:00'), ['2026-11-20']);
  assert.deepEqual(C.eventDays('', ''), []);
  assert.equal(C.dayDateLabel('2026-11-19'), 'Thursday 19 November');
});
