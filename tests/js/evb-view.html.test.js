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
  // (The label names them on every block; the visible lines are for tall blocks only.)
  assert.ok(!short.includes('evb-block-loc') && !short.includes('evb-block-leads'));
  assert.ok(tall.includes('Hall</div>') && tall.includes('Luna</div>'));
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
  assert.ok(h.includes('aria-label="Mothman, Battlegame, Saturday 12–12:45p"'), h);
  assert.ok(h.includes('data-evb-label="Mothman, Battlegame, Saturday 12–12:45p"'), h);
});
test('the block label adds the secondary category, location and leads only when present', () => {
  const label = item => (V.blockHtml(seg(item), cats).match(/aria-label="([^"]*)"/) || [])[1];
  const base = { EventScheduleId: 7, Title: 'Feast', Category: 'Feast and Food', Leads: [] };
  assert.equal(label(base), 'Feast, Feast and Food, Saturday 9:30–10:15a');
  assert.equal(label(Object.assign({}, base, { SecondaryCategory: 'Class' })), 'Feast, Feast and Food and Class, Saturday 9:30–10:15a');
  assert.equal(label(Object.assign({}, base, { Location: 'Hall' })), 'Feast, Feast and Food, Saturday 9:30–10:15a, at Hall');
  assert.equal(label(Object.assign({}, base, { Leads: [{ MundaneId: 1, Persona: 'Luna' }] })), 'Feast, Feast and Food, Saturday 9:30–10:15a, led by Luna');
  assert.equal(label(Object.assign({}, base, { Leads: [{ Persona: 'Luna' }, { Persona: 'Sol' }] })), 'Feast, Feast and Food, Saturday 9:30–10:15a, led by Luna and Sol');
  assert.equal(label(Object.assign({}, base, { SecondaryCategory: 'Class', Location: 'Hall', Leads: [{ Persona: 'A' }, { Persona: 'B' }, { Persona: 'C' }] })),
    'Feast, Feast and Food and Class, Saturday 9:30–10:15a, at Hall, led by A, B and C');
  // An unknown secondary category is not named; the label is escaped.
  assert.equal(label(Object.assign({}, base, { SecondaryCategory: 'Nope', Location: '<b>' })), 'Feast, Feast and Food, Saturday 9:30–10:15a, at &lt;b&gt;');
});
test('a ghost block carries no label base for the conflict badges', () => {
  const h = V.blockHtml(seg({ EventScheduleId: 0, Title: 'Preview', Category: 'Battlegame', Leads: [] }), cats, 0, { ghost: true });
  assert.ok(!/data-evb-label=/.test(h));
});
test('an item after midnight is named for the real day and its night', () => {
  const h = V.blockHtml(seg({ EventScheduleId: 7, Title: 'Bardic', Category: 'Class', Leads: [] }, { startMin: 1500, endMin: 1560 }), cats);
  assert.ok(h.includes('aria-label="Bardic, Class, Sunday 1–2a (Saturday night)"'), h);
  const p = V.popoverHtml({ EventScheduleId: 7, Title: 'Bardic', Category: 'Class', StartTime: '2026-11-22 01:00:00', EndTime: '2026-11-22 02:00:00', Leads: [] }, cats, '/');
  assert.ok(p.includes('Sunday · 1–2a (Saturday night)<'), p);
  const before = V.popoverHtml({ EventScheduleId: 7, Title: 'Late', Category: 'Class', StartTime: '2026-11-21 23:00:00', EndTime: '2026-11-22 00:30:00', Leads: [] }, cats, '/');
  assert.ok(before.includes('Saturday · 11p–12:30a<'), before);
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
test('a block carries its client id when it has one', () => {
  const h = V.blockHtml(seg({ EventScheduleId: 0, cid: 'n3', Title: 'New', Category: 'Other', Leads: [] }), cats);
  assert.match(h, /data-cid="n3"/);
});
test('a ghost block is marked, dashed and kept out of the tab order', () => {
  const h = V.blockHtml(seg({ EventScheduleId: 0, Title: 'Preview', Category: 'Battlegame', Leads: [] }), cats, 0, { ghost: true });
  assert.match(h, /class="evb-block[^"]*\bevb-ghost\b/);
  assert.match(h, /aria-hidden="true"/);
  assert.ok(!/tabindex="0"/.test(h));
  assert.ok(!/data-cid=/.test(h));
});
test('slotAt floors a pixel offset to the step', () => {
  assert.equal(V.slotAt(0, 420, 15), 420);
  assert.equal(V.slotAt(17.9, 420, 15), 420);
  assert.equal(V.slotAt(18, 420, 15), 435);
  assert.equal(V.slotAt(100, 540, 15), 615);
  assert.equal(V.slotAt(-5, 540, 15), 540);
});
test('a placeholder block holds a place in the layout but is not a block anyone can use', () => {
  const h = V.blockHtml(seg({ EventScheduleId: 0, cid: 'x', Title: '', Category: 'Class', Leads: [] }), cats, 0, { placeholder: true });
  assert.match(h, /class="evb-block[^"]*\bevb-placeholder\b/);
  assert.ok(!/evb-block-editing/.test(h));
  assert.match(h, /aria-hidden="true"/);
  assert.ok(!/tabindex="0"/.test(h));
  assert.ok(!/data-cid=/.test(h));
});
test('now comes from the injected clock, measured from a 5am board day', () => {
  try {
    V.setClock(() => '2026-11-21 14:05');
    assert.deepEqual(V.currentNow(), { day: '2026-11-21', min: 845 });
    V.setClock(() => '2026-11-22 04:30');
    assert.deepEqual(V.currentNow(), { day: '2026-11-21', min: 1710 }, '4:30am is still the previous board day');
    V.setClock(() => '2026-11-22 05:00');
    assert.deepEqual(V.currentNow(), { day: '2026-11-22', min: 300 });
    V.setClock(() => 'garbage');
    assert.equal(V.currentNow(), null);
  } finally {
    V.setClock(null);
  }
});
test('with no injected clock now is the device clock read as a local wall time', () => {
  V.setClock(null);
  const d = new Date();
  const now = V.currentNow();
  const p = n => String(n).padStart(2, '0');
  const today = d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
  const mins = d.getHours() * 60 + d.getMinutes();
  // Within a minute of the reading taken here (the test may straddle a minute boundary).
  const expMin = mins < 300 ? mins + 1440 : mins;
  assert.ok(Math.abs(now.min - expMin) <= 1 || Math.abs(now.min - expMin) >= 1439, JSON.stringify(now));
  if (mins >= 300) assert.equal(now.day, today);
});
test('the now line sits on its board day and minute, and not at all outside the event', () => {
  const board = { days: [{ day: '2026-11-20' }, { day: '2026-11-21' }], axisStart: 420, axisEnd: 1740 };
  try {
    V.setClock(() => '2026-11-21 14:05');
    assert.deepEqual(V.nowLine(board, V.currentNow()), { day: '2026-11-21', min: 845, top: (845 - 420) * V.PX_PER_MIN });
    V.setClock(() => '2026-11-22 04:30');
    assert.equal(V.nowLine(board, V.currentNow()).day, '2026-11-21');
    V.setClock(() => '2026-11-25 12:00');
    assert.equal(V.nowLine(board, V.currentNow()), null, 'a day not on the board draws no line');
    V.setClock(() => '2026-11-21 06:00');
    assert.equal(V.nowLine(board, V.currentNow()), null, 'a minute before the axis draws no line');
  } finally {
    V.setClock(null);
  }
});
test('a phone opens on today\'s board day, else the first day', () => {
  const board = { days: [{ day: '2026-11-20' }, { day: '2026-11-21' }, { day: '2026-11-22' }], axisStart: 420, axisEnd: 1740 };
  try {
    V.setClock(() => '2026-11-22 02:00');
    assert.equal(V.openingDay(board, V.currentNow()), '2026-11-21');
    V.setClock(() => '2026-11-22 09:00');
    assert.equal(V.openingDay(board, V.currentNow()), '2026-11-22');
    V.setClock(() => '2026-12-01 09:00');
    assert.equal(V.openingDay(board, V.currentNow()), '2026-11-20');
  } finally {
    V.setClock(null);
  }
});
test('the legend filter announces how many items it shows', () => {
  const items = [
    { Category: 'Battlegame' }, { Category: 'Class', SecondaryCategory: 'Battlegame' }, { Category: 'Class' }, { Category: 'Nope' }
  ];
  assert.equal(V.filterStatus(items, cats, null), 'Showing all items');
  assert.equal(V.filterStatus(items, cats, 'Battlegame'), 'Showing 2 Battlegame items');
  assert.equal(V.filterStatus(items, cats, 'Other'), 'Showing 1 Other item');
});
test('a filter whose category has gone is dropped and the status region reset', () => {
  assert.deepEqual(V.keptFilter('Battlegame', { Other: true }), { filter: null, status: 'Showing all items' });
  assert.deepEqual(V.keptFilter('Battlegame', { Battlegame: true }), { filter: 'Battlegame', status: null });
  assert.deepEqual(V.keptFilter(null, {}), { filter: null, status: null });
});
test('a dimmed block is hidden unless it is selected or focused', () => {
  assert.equal(V.filterAccess(true, false, false), 'hide');
  assert.equal(V.filterAccess(true, true, false), null);
  // Selected or focused after the filter had hidden it: reachable again.
  assert.equal(V.filterAccess(true, true, true), 'show');
  assert.equal(V.filterAccess(false, false, true), 'show');
  assert.equal(V.filterAccess(false, false, false), null);
});

// Ongoing items (EvBoardCore.isOngoing) draw as rails at the left of their day.
test('a rail is a real block: same data, role, focus and label, narrow at the left edge', () => {
  const item = { EventScheduleId: 11, cid: 'i11', Title: 'Troll', Category: 'Feast and Food', SecondaryCategory: 'Class', Location: 'Gate', Leads: [], Menu: 'Stew' };
  const h = V.blockHtml(seg(item, { rail: 1, startMin: 540, endMin: 960, left: undefined, width: undefined }), cats, 480);
  assert.match(h, /class="evb-block evb-block-rail evb-block-striped"/);
  assert.match(h, /role="button" tabindex="0"/);
  assert.match(h, /data-id="11"/);
  assert.match(h, /data-cid="i11"/);
  assert.match(h, /data-day="2026-11-21"/);
  assert.match(h, /data-category="Feast and Food"/);
  assert.match(h, /data-secondary-category="Class"/);
  assert.ok(h.includes('aria-label="Troll, Feast and Food and Class, Saturday 9a–4p, at Gate"'), h);
  assert.ok(h.includes('data-evb-label="Troll, Feast and Food and Class, Saturday 9a–4p, at Gate"'), h);
  // Second rail: 1px inset plus one 22px rail and its 2px gap.
  assert.ok(h.includes('left:25px;width:22px;'), h);
  assert.ok(h.includes('top:73.0px;height:502.0px;'), h);
  // Only the title shows; the label carries the rest.
  assert.ok(h.includes('<div class="evb-block-title">Troll</div></div>'), h);
  assert.ok(!h.includes('evb-block-meta'));
});
test('ordinary blocks are laid out in the width left after the rails', () => {
  const h = V.blockHtml(seg({ EventScheduleId: 7, Title: 'Ditch', Category: 'Battlegame', Leads: [] }, { left: 25, width: 50 }), cats);
  assert.ok(h.includes('left:calc(var(--evb-rail-w, 0px) + (100% - var(--evb-rail-w, 0px)) * 0.25000 + 1px);'), h);
  assert.ok(h.includes('width:calc((100% - var(--evb-rail-w, 0px)) * 0.50000 - 2px);'), h);
  assert.equal(V.railWidth(0), 0);
  assert.equal(V.railWidth(2), 48);
});
test('a ghost rail stays out of the tab order', () => {
  const h = V.blockHtml(seg({ EventScheduleId: 0, Title: 'Troll', Category: 'Other', Leads: [] }, { rail: 0 }), cats, 0, { ghost: true });
  assert.match(h, /evb-block-rail/);
  assert.match(h, /aria-hidden="true"/);
  assert.ok(!/tabindex="0"/.test(h));
});

// The "now and next" strip.
const C = require('../../orkui/template/revised-frontend/script/event-board/evb-core.js');
const nn = (items, now) => C.nowNext(items, now, '2026-11-20 09:00', '2026-11-22 16:00');
test('the strip names now and next, with the time, the place and the category in the label', () => {
  const items = [
    { EventScheduleId: 3, Title: 'Ditch <b>', Category: 'Battlegame', StartTime: '2026-11-21 14:00', EndTime: '2026-11-21 15:00', Location: 'North Field' },
    { EventScheduleId: 4, Title: 'Court', Category: 'Nope', StartTime: '2026-11-21 15:30', EndTime: '2026-11-21 16:30' }
  ];
  const h = V.nowNextHtml(nn(items, '2026-11-21 14:20'), cats);
  assert.match(h, /<span class="evb-nownext-label" id="evb-nownext-now-label">Now<\/span>/);
  assert.match(h, /<span class="evb-nownext-label" id="evb-nownext-next-label">Next<\/span>/);
  assert.match(h, /role="group" aria-labelledby="evb-nownext-now-label"/);
  assert.ok(h.includes('<button type="button" class="evb-nownext-item" data-id="3" data-category="Battlegame" aria-label="Ditch &lt;b&gt;, Battlegame, until 3p, at North Field">'), h);
  assert.ok(h.includes('background:#c0392b'), 'the swatch is in the category colour');
  assert.ok(h.includes('<span class="evb-nownext-when">until 3p</span>'));
  assert.ok(h.includes('North Field</span>'));
  assert.ok(h.includes('aria-label="Court, Other, at 3:30p"'), 'an unknown category is Other');
  assert.ok(!h.includes('<b>'));
});
test('the strip is empty when nothing is on or next, or the clock is outside the event', () => {
  assert.equal(V.nowNextHtml(null, cats), '');
  assert.equal(V.nowNextHtml(nn([], '2026-11-21 14:00'), cats), '');
  const items = [{ EventScheduleId: 3, Title: 'Ditch', Category: 'Battlegame', StartTime: '2026-11-21 14:00', EndTime: '2026-11-21 15:00' }];
  const onlyNext = V.nowNextHtml(nn(items, '2026-11-21 13:00'), cats);
  assert.ok(!onlyNext.includes('evb-nownext-now') && onlyNext.includes('evb-nownext-next'), onlyNext);
});
test('the strip counts the items left off now, and names the day an item ends on another day', () => {
  const items = [];
  for (let i = 0; i < 5; i++) items.push({ EventScheduleId: 10 + i, Title: 'Bout ' + i, Category: 'Battlegame', StartTime: '2026-11-21 10:00', EndTime: '2026-11-21 11:00' });
  items.push({ EventScheduleId: 20, Title: 'Site', Category: 'Other', StartTime: '2026-11-20 09:00', EndTime: '2026-11-22 16:00' });
  const h = V.nowNextHtml(nn(items, '2026-11-21 10:30'), cats);
  assert.ok(h.includes('<li class="evb-nownext-more">+2 more</li>'), h);
  const site = V.nowNextHtml(nn([items[5]], '2026-11-21 10:30'), cats);
  assert.ok(site.includes('until Sunday 4p'), site);
  const late = V.nowNextHtml(nn([{ EventScheduleId: 21, Title: 'Bardic', Category: 'Class', StartTime: '2026-11-21 22:00', EndTime: '2026-11-22 01:00' }], '2026-11-21 23:00'), cats);
  assert.ok(late.includes('until 1a'), 'past midnight on the same board day needs no day name');
  const early = V.nowNextHtml(nn([
    { EventScheduleId: 22, Title: 'Tavern', Category: 'Feast and Food', StartTime: '2026-11-21 09:00', EndTime: '2026-11-22 05:00' },
    { EventScheduleId: 23, Title: 'Troll', Category: 'Administrative', StartTime: '2026-11-20 18:00', EndTime: '2026-11-22 05:00' }
  ], '2026-11-21 15:00'), cats);
  assert.equal(early.split('<span class="evb-nownext-when">until 5a</span>').length - 1, 2, early);
  assert.ok(!early.includes('until Sunday'), early);
});
