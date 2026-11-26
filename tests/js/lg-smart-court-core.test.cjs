'use strict';
var test = require('node:test');
var assert = require('node:assert');
var C = require('../../orkui/template/default/script/lg-smart-court-core.js');

function row(id, m, col, rank, saved, recId) {
  return { id: id, mundaneId: m, col: col, rank: rank, saved: !!saved, recId: recId || null, status: null, courtAwardId: null };
}

test('maxRank', function () {
  assert.strictEqual(C.maxRank('Order of the Zodiac'), 12);
  assert.strictEqual(C.maxRank('Order of the Rose'), 10);
});

test('repeat next-rank from 2 gives 3,4,5', function () {
  var rows = [];
  var r = C.nextRank(2, rows, 1, 'rose'); assert.strictEqual(r, 3);
  rows.push(row('a', 1, 'rose', r));
  r = C.nextRank(2, rows, 1, 'rose'); assert.strictEqual(r, 4);
  rows.push(row('b', 1, 'rose', r));
  r = C.nextRank(2, rows, 1, 'rose'); assert.strictEqual(r, 5);
  assert.strictEqual(C.cellCount(rows, 1, 'rose'), 2);
  assert.strictEqual(C.cellCount(rows, 2, 'rose'), 0);
});

test('next rank continues past saved court lines', function () {
  var rows = [row('s', 1, 'rose', 4, true)];
  assert.strictEqual(C.nextRank(2, rows, 1, 'rose'), 5);
  assert.strictEqual(C.effectiveRank(2, rows, 1, 'rose'), 4);
  assert.strictEqual(C.nextRank(2, rows, 1, 'lion'), 3);
});

test('assignRecs: 4th and 5th get own recs; first sorted wins; no cross-rank', function () {
  var recs = [
    { id: 10, mundaneId: 1, col: 'rose', rank: 4 },
    { id: 11, mundaneId: 1, col: 'rose', rank: 4 },
    { id: 12, mundaneId: 1, col: 'rose', rank: 5 }
  ];
  var out = C.assignRecs([row('a', 1, 'rose', 4), row('b', 1, 'rose', 5)], recs);
  assert.strictEqual(out[0].recId, 10);
  assert.strictEqual(out[1].recId, 12);
  out = C.assignRecs([row('a', 1, 'rose', 4)], [recs[2]]);
  assert.strictEqual(out[0].recId, null);
  out = C.assignRecs([row('a', 1, 'rose', 4), row('b', 1, 'rose', 4)], recs);
  assert.strictEqual(out[0].recId, 10);
  assert.strictEqual(out[1].recId, 11);
});

test('saved rows keep their rec and claim it', function () {
  var recs = [{ id: 10, mundaneId: 1, col: 'rose', rank: 4 }, { id: 11, mundaneId: 1, col: 'rose', rank: 4 }];
  var out = C.assignRecs([row('a', 1, 'rose', 4), row('s', 1, 'rose', 4, true, 10)], recs);
  assert.strictEqual(out[1].recId, 10);
  assert.strictEqual(out[0].recId, 11);
});

test('removeRow shifts later unsaved rows and rematches', function () {
  var recs = [{ id: 3, mundaneId: 1, col: 'rose', rank: 3 }, { id: 4, mundaneId: 1, col: 'rose', rank: 4 }, { id: 5, mundaneId: 1, col: 'rose', rank: 5 }];
  var rows = C.assignRecs([row('a', 1, 'rose', 3), row('b', 1, 'rose', 4), row('c', 1, 'rose', 5)], recs);
  var out = C.removeRow(rows, 'a');
  assert.deepStrictEqual(out.map(function (r) { return r.rank; }), [3, 4]);
  out = C.assignRecs(out, recs);
  assert.deepStrictEqual(out.map(function (r) { return r.recId; }), [3, 4]);
});

test('removing never renumbers saved rows', function () {
  var rows = [row('s', 1, 'rose', 3, true), row('b', 1, 'rose', 4), row('c', 1, 'rose', 5)];
  var out = C.removeRow(rows, 'b');
  assert.deepStrictEqual(out.map(function (r) { return r.rank; }), [3, 4]);
  assert.strictEqual(out[0].saved, true);
  assert.strictEqual(out[0].rank, 3);
  // other cell untouched
  out = C.removeRow([row('a', 1, 'rose', 3), row('x', 1, 'lion', 5), row('y', 2, 'rose', 6)], 'a');
  assert.deepStrictEqual(out.map(function (r) { return r.rank; }), [5, 6]);
});

test('recsAbove', function () {
  var recs = [
    { id: 1, mundaneId: 1, col: 'rose', rank: 5 }, { id: 2, mundaneId: 1, col: 'rose', rank: 2 },
    { id: 3, mundaneId: 1, col: 'rose', rank: 3 }, { id: 4, mundaneId: 2, col: 'rose', rank: 9 }
  ];
  assert.deepStrictEqual(C.recsAbove(recs, 1, 'rose', 2).map(function (r) { return r.id; }), [1, 3]);
});

test('limit boundaries', function () {
  assert.strictEqual(C.isAtLimit(4, 5), false);
  assert.strictEqual(C.isAtLimit(5, 5), true);
  assert.strictEqual(C.isAtLimit(6, 5), true);
  assert.strictEqual(C.isAtLimit(6, 0), false);
  assert.strictEqual(C.isOverLimit(5, 5), false);
  assert.strictEqual(C.isOverLimit(6, 5), true);
  assert.strictEqual(C.isOverLimit(6, 0), false);
});

test('isOverdue boundaries', function () {
  var t = '2026-10-30';
  assert.strictEqual(C.isOverdue('2026-08-31', '2026-01-01', t), true);  // 60
  assert.strictEqual(C.isOverdue('2026-08-30', '2026-01-01', t), false); // 61
  assert.strictEqual(C.isOverdue('2026-10-29', null, t), false);
  assert.strictEqual(C.isOverdue(null, '2020-01-01', t), false);
});

test('isOverdue award 180/181 days', function () {
  var t = '2026-10-30';
  assert.strictEqual(C.isOverdue(t, '2026-05-03', t), false); // 180 days
  assert.strictEqual(C.isOverdue(t, '2026-05-02', t), true);  // 181 days
});

test('ageFraction', function () {
  var t = '2026-10-30';
  assert.strictEqual(C.ageFraction(t, t), 0);
  assert.strictEqual(C.ageFraction('2025-10-30', t), 1);
  assert.strictEqual(C.ageFraction('2023-10-30', t), 1);
  assert.strictEqual(C.ageFraction(null, t), null);
  assert.ok(Math.abs(C.ageFraction('2026-04-30', t) - 183 / 365) < 1e-9);
});

test('findDuplicates', function () {
  var colOf = function (k) { return k === 7 ? 'rose' : null; };
  var lines = [
    { MundaneId: 1, KingdomAwardId: 7, Rank: 3 },
    { MundaneId: 1, KingdomAwardId: 99, Rank: 3 }
  ];
  var rows = [row('a', 1, 'rose', 3), row('b', 1, 'rose', 4), row('c', 2, 'rose', 3), row('d', 1, 'rose', 3, true)];
  assert.deepStrictEqual(C.findDuplicates(rows, lines, colOf), ['a']);
});

test('parseStored', function () {
  var ok = { v: 1, scope: 'k1', court: { id: 1 }, limit: 5, rows: [] };
  assert.deepStrictEqual(C.parseStored(JSON.stringify(ok), 'k1'), ok);
  assert.strictEqual(C.parseStored('{bad', 'k1'), null);
  assert.strictEqual(C.parseStored(null, 'k1'), null);
  assert.strictEqual(C.parseStored(JSON.stringify(ok), 'k2'), null);
  assert.strictEqual(C.parseStored(JSON.stringify({ v: 1, scope: 'k1', court: {}, limit: 0, rows: {} }), 'k1'), null);
  assert.strictEqual(C.parseStored(JSON.stringify({ v: 2, scope: 'k1', court: {}, limit: 0, rows: [] }), 'k1'), null);
});
