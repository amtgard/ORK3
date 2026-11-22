const test = require('node:test');
const assert = require('node:assert/strict');
const V = require('../../orkui/template/revised-frontend/script/event-board/evb-view.js');

const feast = { EventScheduleId: 3, Title: 'Feast', Category: 'Feast and Food', SecondaryCategory: '' };
const feastSecond = { EventScheduleId: 4, Title: 'Court feast', Category: 'Court', SecondaryCategory: 'Feast and Food' };
const court = { EventScheduleId: 5, Title: 'Court', Category: 'Court', SecondaryCategory: '' };

test('a schedule manager gets Edit in the popover on every item', () => {
  const c = { canManageSchedule: true, canManageFeast: true };
  assert.ok(V.popoverCanEdit(feast, c) && V.popoverCanEdit(feastSecond, c) && V.popoverCanEdit(court, c));
  assert.ok(V.popoverCanEdit(court, { canManageSchedule: true, canManageFeast: false }));
});
test('a feast editor gets Edit on a feast item, by primary or secondary category, and on nothing else', () => {
  const c = { canManageSchedule: false, canManageFeast: true };
  assert.ok(V.popoverCanEdit(feast, c));
  assert.ok(V.popoverCanEdit(feastSecond, c));
  assert.ok(!V.popoverCanEdit(court, c));
});
test('someone who can manage neither gets no Edit', () => {
  assert.ok(!V.popoverCanEdit(feast, { canManageSchedule: false, canManageFeast: false }));
  assert.ok(!V.popoverCanEdit(feast, {}));
  assert.ok(!V.popoverCanEdit(feast));
});

test('a rail fully in view shows its whole height', () => {
  assert.equal(V.railVisible(100, 300, 0, 600), 300);
});
test('a rail scrolled past the day heads shows only the part below them', () => {
  // Rail 0..1200 in the body, scrolled 500px: 500..1100 of it shows in a 600px view.
  assert.equal(V.railVisible(0, 1200, 500, 600), 600);
  // Near its end only the last 80px show.
  assert.equal(V.railVisible(0, 1200, 1120, 600), 80);
});
test('a rail starting near the bottom of the view shows the part above the frame edge', () => {
  assert.equal(V.railVisible(550, 400, 0, 600), 50);
});
test('a rail out of view shows nothing', () => {
  assert.equal(V.railVisible(0, 300, 400, 600), 0);
  assert.equal(V.railVisible(900, 300, 0, 600), 0);
});
