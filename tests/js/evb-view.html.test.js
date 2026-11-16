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
test('popover escapes text, links leads and URLs, and shows meal details', () => {
  const h = V.popoverHtml({
    EventScheduleId: 9, Title: 'Feast <b>', Category: 'Feast and Food', StartTime: '2026-11-21 17:00:00', EndTime: '2026-11-21 18:00:00',
    Location: 'Hall', Description: 'Menu at https://example.org/menu <script>', Leads: [{ MundaneId: 42, Persona: 'Luna' }],
    Menu: 'Potato bar', Cost: 8, Dietary: 'Vegan Option', Allergens: 'Dairy'
  }, cats, '/orkui/');
  assert.ok(h.includes('Feast &lt;b&gt;'));
  assert.ok(!h.includes('<script>'));
  assert.ok(h.includes('href="https://example.org/menu"'));
  assert.ok(h.includes('href="/orkui/Player/profile/42"'));
  assert.ok(h.includes('5–6p'));
  assert.ok(h.includes('Potato bar') && h.includes('$8.00') && h.includes('Vegan Option') && h.includes('Dairy'));
});
test('a zero cost reads Free and an absent cost shows nothing', () => {
  const base = { EventScheduleId: 1, Title: 'T', Category: 'Feast and Food', StartTime: '2026-11-21 17:00:00', EndTime: '2026-11-21 18:00:00', Leads: [] };
  assert.ok(V.popoverHtml(Object.assign({ Cost: 0 }, base), cats, '/').includes('Free'));
  assert.ok(!V.popoverHtml(Object.assign({ Cost: null }, base), cats, '/').includes('$'));
});
test('only editors get an Edit button in the popover', () => {
  const item = { EventScheduleId: 5, Title: 'T', Category: 'Class', StartTime: '2026-11-21 10:00:00', EndTime: '2026-11-21 11:00:00', Leads: [] };
  assert.ok(!V.popoverHtml(item, cats, '/').includes('evb-popover-edit'));
  assert.match(V.popoverHtml(item, cats, '/', { canEdit: true }), /class="evb-popover-edit"[^>]*data-id="5"/);
});
test('autolink leaves trailing punctuation and an unmatched paren outside the link', () => {
  const h = V.autolink('See (https://example.org/a_(b)). Then "x"');
  assert.ok(h.includes('<a href="https://example.org/a_(b)" target="_blank" rel="noopener noreferrer">'));
  assert.ok(h.includes('</a>).'));
  assert.ok(h.includes('&quot;x&quot;'));
});
test('the block label names the day', () => {
  const h = V.blockHtml(seg({ EventScheduleId: 7, Title: 'Mothman', Category: 'Battlegame', Leads: [] }, { startMin: 720, endMin: 765 }), cats);
  assert.ok(h.includes('aria-label="Mothman, Saturday 12–12:45p"'), h);
});
test('a zero-length item shows one time on the block and in the popover', () => {
  const item = { EventScheduleId: 3, Title: 'Gate', Category: 'Other', StartTime: '2026-11-21 09:00:00', EndTime: '2026-11-21 09:00:00', Leads: [] };
  assert.ok(V.blockHtml(seg(item, { startMin: 540, endMin: 540 }), cats).includes('>9a</div>'));
  assert.ok(V.popoverHtml(item, cats, '/').includes('Saturday · 9a<'));
});
test('an overnight item past 5am names both weekdays in the popover', () => {
  const h = V.popoverHtml({ EventScheduleId: 4, Title: 'Vigil', Category: 'Other', StartTime: '2026-11-20 22:00:00', EndTime: '2026-11-21 06:00:00', Leads: [] }, cats, '/');
  assert.ok(h.includes('Friday') && h.includes('Saturday'), h);
});
test('autolink never links javascript: and keeps quotes out of the href', () => {
  const h = V.autolink('see javascript:alert(1) and https://x.org/"onmouseover=alert(1) now');
  assert.ok(!h.includes('href="javascript'));
  assert.equal((h.match(/<a /g) || []).length, 1);
  assert.ok(!h.includes('"onmouseover'));
});
test('the now line follows the server clock and the 5am board day', () => {
  assert.deepEqual(V.nowMinute('2026-11-21 14:05', 0), { day: '2026-11-21', min: 845 });
  assert.deepEqual(V.nowMinute('2026-11-21 14:05', 10 * 60000), { day: '2026-11-21', min: 855 });
  assert.deepEqual(V.nowMinute('2026-11-22 01:30', 0), { day: '2026-11-21', min: 1530 });
  assert.deepEqual(V.nowMinute('2026-11-21 23:50', 20 * 60000), { day: '2026-11-21', min: 1450 });
});
