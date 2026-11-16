const test = require('node:test');
const assert = require('node:assert/strict');
const V = require('../../orkui/template/revised-frontend/script/event-board/evb-view.js');
const cats = { Battlegame: { bold: '#c0392b' }, Other: { bold: '#616161' }, 'Feast and Food': { bold: '#bf4d00' }, Class: { bold: '#1565c0' } };
const seg = (item, more) => Object.assign({ item, day: '2026-11-21', startMin: 570, endMin: 615, isFirst: true, isLast: true, lane: 0, left: 0, width: 50 }, more);

test('titles are escaped', () => {
  const h = V.blockHtml(seg({ EventScheduleId: 7, Title: '<img src=x onerror=alert(1)>', Category: 'Battlegame', Leads: [] }), cats);
  assert.ok(!h.includes('<img'));
  assert.ok(h.includes('&lt;img'));
});
test('a block carries its id, day, category, fill and time', () => {
  const h = V.blockHtml(seg({ EventScheduleId: 7, Title: 'Mothman', Category: 'Battlegame', Leads: [] }), cats);
  assert.match(h, /class="evb-block[^"]*"/);
  assert.match(h, /data-id="7"/);
  assert.match(h, /data-day="2026-11-21"/);
  assert.match(h, /data-category="Battlegame"/);
  assert.ok(h.includes('#c0392b'));
  assert.ok(h.includes('9:30–10:15a'));
});
test('unknown and missing categories render as Other', () => {
  const h = V.blockHtml(seg({ EventScheduleId: 8, Title: 'X', Category: 'Nope', Leads: [] }), cats);
  assert.match(h, /data-category="Other"/);
  assert.ok(h.includes('#616161'));
});
test('location and leads show only on tall blocks; a secondary category adds a stripe', () => {
  const item = { EventScheduleId: 9, Title: 'Feast', Category: 'Feast and Food', SecondaryCategory: 'Class', Location: 'Hall', Leads: [{ MundaneId: 1, Persona: 'Luna' }] };
  const short = V.blockHtml(seg(item), cats);
  const tall = V.blockHtml(seg(item, { endMin: 690 }), cats);
  assert.ok(!short.includes('Hall') && !short.includes('Luna'));
  assert.ok(tall.includes('Hall') && tall.includes('Luna'));
  assert.ok(tall.includes('#1565c0'));
  assert.ok(!tall.includes('evb-block-menu'));
  const withMenu = V.blockHtml(seg(Object.assign({ Menu: 'Potato bar' }, item)), cats);
  assert.ok(withMenu.includes('evb-block-menu'));
});
test('continuing segments are marked', () => {
  const h = V.blockHtml(seg({ EventScheduleId: 10, Title: 'Site Open', Category: 'Other', Leads: [] }, { isLast: false }), cats);
  assert.match(h, /evb-block-continues/);
});
