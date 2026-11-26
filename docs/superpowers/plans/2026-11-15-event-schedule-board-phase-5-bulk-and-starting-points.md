# Event Schedule Board, Phase 5: Bulk Entry and Starting Points: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an editor fill a schedule in bulk: paste lines of text, drop in a skeleton, or copy a past event's schedule into this one; copy the schedule out as text; and see warnings when a lead or a location is double-booked.

**Architecture:** Pasted and skeleton lines go through the existing entry grammar one line at a time (`parseLines`, pure) and appear as ghost blocks until confirmed, then land in one `store.batch`. Copying a past schedule is done on the server (`CopyScheduleInto`), which reuses the existing copy code reworked to shift by whole days; the browser adopts the returned rows as one undoable step. Conflict detection is a pure function over the store's items.

**Tech Stack:** PHP 8, MariaDB, vanilla JavaScript, PHPUnit 11, `node --test`, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-15-event-schedule-board-design.md` (calls 4.7, 4.14, 4.15, 4.16; sections 7.7, 8.7). Phases 1 to 4 are built.

## Global Constraints

- Everything in the Phase 2 plan's Global Constraints applies unchanged (`docs/superpowers/plans/2026-11-15-event-schedule-board-phase-2-edit-and-entry.md`), and the Phase 3 plan's rules on leads (`is_auto`, primary category only).
- Bulk additions are never saved until the editor presses "Add all". Discard removes every ghost and saves nothing.
- Copying from a past event appends; it never removes or changes an existing item.
- A copied schedule keeps its clock times: the shift is a whole number of calendar days, never a number of seconds.
- Feast items are copied only for a caller who can manage feast.
- Leads who are not event staff cannot be restored from pasted text; they are listed to the editor, never silently dropped.

## Dev Runbook

As in the Phase 3 plan.

## Review Focus

1. **Pasting 60 lines where three are unreadable** adds nothing until confirmed, shows the three, and after "Add all" one Undo removes all 57. Pinned in Tasks 2 and 3.
2. **Copying a schedule across a daylight-saving change** keeps every item's clock time. Pinned in Task 1.
3. **Copying into an occurrence that already has items** leaves those items exactly as they were. Pinned in Task 1.
4. **A line that is only a day name, a blank line, or a heading with a colon** in pasted text sets the day or is skipped; it is never reported as unreadable noise unless it has other words. Pinned in Task 2.
5. **Text the board itself copied** pastes as blocks (Phase 4), not as a text preview. Pinned in Task 3.

---

### Task 1: Copy a past schedule into this occurrence (server)

**Files:**
- Modify: `system/lib/ork3/class.EventPlanning.php` (`copyScheduleModules` at about 1886, `CreateEventWithCopy` at about 1318, `ListCopySourceEvents` at about 1260; new methods)
- Modify: `orkui/model/model.EventPlanning.php`, `orkui/controller/controller.EventAjax.php`
- Modify: `tests/Support/EventPlanningFixture.php`, `tests/Integration/EventPlanningTest.php`

**Interfaces:**
- Produces:
  - `copyScheduleModules(int $srcDetailId, int $newDetailId, array $mod, int $shiftDays, ?int $deltaSeconds = null): array` returning the new schedule ids in order. With `$deltaSeconds` null, each start and end is moved by `$shiftDays` calendar days with the clock time kept (do the arithmetic on the date part; do not add seconds). `CreateEventWithCopy` passes its existing seconds delta through the fifth parameter and behaves exactly as today.
  - `EventPlanning::ListScheduleCopySources(array $request): array` (`Token`, `EventId`, `EventCalendarDetailId`) returning `['Status' => Success(), 'Sources' => list<['EventId', 'EventCalendarDetailId', 'Name', 'StartDate' (Y-m-d), 'ItemCount', 'SameEvent' (bool)]>]`: first the other occurrences of this event, newest first, then the latest occurrence of each event `ListCopySourceEvents` would offer for this event's kingdom and park; only occurrences with at least one schedule item; at most 25 in all. Requires the schedule capability on the target.
  - `EventPlanning::CopyScheduleInto(array $request): array` (`Token`, `EventId`, `EventCalendarDetailId`, `SourceDetailId`) returning `['Status' => Success(), 'Schedule' => list<row>, 'SkippedFeast' => int]`, rows in the `fetchScheduleList` shape. Requires the schedule capability on the target; the source must be one `ListScheduleCopySources` would return for this caller and target, otherwise `NoAuthorization()`. Shift = the number of days from the source occurrence's `event_start` date to the target's. Feast items (primary or secondary category Feast and Food) are copied only when the caller can manage feast; otherwise they are counted in `SkippedFeast`. Leads are copied under the existing eligibility rule, keeping `is_auto`. Runs in one transaction.
  - Model methods `schedule_copy_sources(string $token, int $eventId, int $detailId): array` and `schedule_copy_into(array $request): array`; actions `GET EventAjax/schedule_copy_sources/{eventId}/{detailId}` returning `{"status":0,"sources":[…]}` and `POST EventAjax/schedule_copy_into/{eventId}/{detailId}` with form field `SourceDetailId` returning `{"status":0,"schedule":[…],"skippedFeast":N}`.

- [ ] **Step 1: Add a fixture helper** `insertScheduleAt(int $detailId, string $title, string $category, string $start, string $end): int` (naive `Y-m-d H:i:s` strings) and, if missing, a way to create a published event whose occurrence starts on a given date (`createPublishedEventOn(string $suffix, string $startDate): array`, same return shape as `createPublishedEvent`).

- [ ] **Step 2: Write the failing tests:**

```php
public function testCopyScheduleIntoAppendsAndKeepsClockTimesAcrossDst(): void
{
    $src = $this->fixture->createPublishedEventOn('copy-src', '2026-10-30');   // before the US clock change on 2026-11-01
    $dst = $this->fixture->createPublishedEventOn('copy-dst', '2026-11-06');
    $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $dst['park_id'], AUTH_CREATE, 'copy-admin');
    $this->fixture->insertScheduleAt($src['detail_id'], 'Opening Court', 'Court', '2026-10-30 18:00:00', '2026-10-30 19:00:00');
    $this->fixture->insertScheduleAt($src['detail_id'], 'Night Quest', 'Roleplay and Quests', '2026-10-31 23:30:00', '2026-11-01 00:30:00');
    $existing = $this->fixture->insertScheduleAt($dst['detail_id'], 'Already Here', 'Other', '2026-11-06 09:00:00', '2026-11-06 10:00:00');
    unset($_SESSION['is_authorized_mundane_id']);
    $r = $this->planning->CopyScheduleInto(['Token' => $grantor['token'], 'EventId' => $dst['event_id'], 'EventCalendarDetailId' => $dst['detail_id'], 'SourceDetailId' => $src['detail_id']]);
    $this->assertSame(0, $r['Status']['Status']);
    $this->assertSame(
        [['Opening Court', '2026-11-06 18:00:00', '2026-11-06 19:00:00'], ['Night Quest', '2026-11-07 23:30:00', '2026-11-08 00:30:00']],
        array_map(fn ($s) => [$s['Title'], $s['StartTime'], $s['EndTime']], $r['Schedule'])
    );
    $this->assertSame(3, $this->fixture->countSchedule($dst['detail_id']));
    $this->assertSame('2026-11-06 09:00:00', $this->fixture->fetchScheduleRow($existing)['start_time']);
    $this->assertSame(2, $this->fixture->countSchedule($src['detail_id']));
}

public function testCopyScheduleIntoSkipsFeastWithoutTheFeastCapabilityAndNeedsScheduleRights(): void
{
    $src = $this->fixture->createPublishedEventOn('copy-feast-src', '2026-10-30');
    $dst = $this->fixture->createPublishedEventOn('copy-feast-dst', '2026-11-06');
    $this->fixture->insertScheduleAt($src['detail_id'], 'Feast', 'Feast and Food', '2026-10-30 17:00:00', '2026-10-30 18:00:00');
    $this->fixture->insertScheduleAt($src['detail_id'], 'Mothman', 'Battlegame', '2026-10-30 12:00:00', '2026-10-30 12:45:00');
    $sched = $this->fixture->createGrantorWithoutAuth('copy-sched');
    $this->fixture->insertStaff($dst['detail_id'], $sched['mundane_id'], 'Sched', canSchedule: true);
    $req = ['EventId' => $dst['event_id'], 'EventCalendarDetailId' => $dst['detail_id'], 'SourceDetailId' => $src['detail_id']];
    unset($_SESSION['is_authorized_mundane_id']);
    $r = $this->planning->CopyScheduleInto($req + ['Token' => $sched['token']]);
    $this->assertSame(0, $r['Status']['Status']);
    $this->assertSame(['Mothman'], array_column($r['Schedule'], 'Title'));
    $this->assertSame(1, $r['SkippedFeast']);
    $nobody = $this->fixture->createGrantorWithoutAuth('copy-nobody');
    unset($_SESSION['is_authorized_mundane_id']);
    $denied = $this->planning->CopyScheduleInto($req + ['Token' => $nobody['token']]);
    $this->assertNotSame(0, $denied['Status']['Status']);
    $this->assertSame(1, $this->fixture->countSchedule($dst['detail_id']));
}

public function testScheduleCopySourcesListThisEventsOtherOccurrencesFirst(): void
{
    $ctx = $this->fixture->createPublishedEventOn('copy-list', '2026-11-06');
    $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $ctx['park_id'], AUTH_CREATE, 'copy-list-admin');
    $earlier = $this->fixture->createDetailOnEvent($ctx['event_id'], '2025-11-07 09:00:00', '2025-11-09 16:00:00');
    $this->fixture->insertScheduleAt($earlier, 'Last Year', 'Court', '2025-11-07 18:00:00', '2025-11-07 19:00:00');
    $empty = $this->fixture->createDetailOnEvent($ctx['event_id'], '2024-11-08 09:00:00', '2024-11-10 16:00:00');
    unset($_SESSION['is_authorized_mundane_id']);
    $r = $this->planning->ListScheduleCopySources(['Token' => $grantor['token'], 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id']]);
    $this->assertSame(0, $r['Status']['Status']);
    $first = $r['Sources'][0];
    $this->assertSame([$earlier, true, 1, '2025-11-07'], [$first['EventCalendarDetailId'], $first['SameEvent'], $first['ItemCount'], $first['StartDate']]);
    $ids = array_column($r['Sources'], 'EventCalendarDetailId');
    $this->assertNotContains($empty, $ids);
    $this->assertNotContains($ctx['detail_id'], $ids);
}
```

The existing `testCreateWithCopyModules` and `testCreateWithCopyRollback` must still pass unchanged.

- [ ] **Step 3: Run the PHP tests.** Expected: the three new tests fail.
- [ ] **Step 4: Implement** the rework of `copyScheduleModules`, the two domain methods, the model methods and the controller actions (use `ExecuteChecked` and a transaction in `CopyScheduleInto`).
- [ ] **Step 5: Run the PHP tests**, `tests/Unit/TokenGatedCallSiteTest.php` and `tests/Unit/DataSetClearLintTest.php`. Expected: all pass. `php -l` each changed file.
- [ ] **Step 6: Commit:** `Schedule board: copy a past schedule into an occurrence`.

---

### Task 2: Lines of text, skeleton, conflicts (pure logic)

**Files:**
- Modify: `orkui/template/revised-frontend/script/event-board/evb-core.js`
- Modify: `orkui/template/revised-frontend/script/event-board/evb-store.js`
- Create: `tests/js/evb-core.bulk.test.js`; modify `tests/js/evb-store.test.js`

**Interfaces:**
- Consumes: `parseEntry`, `formatEntry`, `boardPos`, `dayLabel`.
- Produces:
  - `parseLines(text, ctx) -> {items: Array<object>, unread: Array<{line: number, text: string}>, unmatchedLeads: Array<string>}`. Lines are parsed in order; each parsed item updates the context for the next (day, last end, last length); a `setDay` line or a line that is only a day name followed by other punctuation sets the day; blank lines are skipped; a line that yields no title is reported in `unread` with its 1-based line number. Each item has `Title`, `StartTime`, `EndTime`, `Category`, `SecondaryCategory`, `Location`, `Description`, `Cost`, `Leads`. `unmatchedLeads` is the distinct list across all lines.
  - `scheduleText(items) -> string`: the items grouped by board day in order, each group headed by `dayLabelLong(day)` on its own line and followed by a blank line, each item as `formatEntry(item)`.
  - `skeletonText(days) -> string`: lines for the usual bones, readable by `parseLines`. First day: `<day>:` then `12p Troll Opens`, `6p Feast`. Each middle day: `9a Troll Opens`, `12p Lunch`, `6p Feast`, `8p Court`. Last day (when there is more than one day): `9a Troll Opens`, `10a Clean Up`, `12p Site Closes`. A one-day event gets the first-day lines plus `8p Court`. Each time is a start; lengths default to one hour.
  - `conflicts(items) -> Object<cid, Array<string>>`: for every pair of items whose times overlap, a message on both when they share a lead (`"<Persona> is also on <other title>"`) or share a non-empty location ignoring case and surrounding space (`"<Location> is also booked for <other title>"`). Items without a `cid` are keyed by `'i' + EventScheduleId`.
  - `store.adopt(serverRows) -> Array<cid>`: adds rows that already exist on the server (no add is sent) as one undo step whose undo removes them.

- [ ] **Step 1: Write the failing tests** in `tests/js/evb-core.bulk.test.js`:

```js
const DAYS = ['2026-11-20', '2026-11-21', '2026-11-22'];
const STAFF = [{ MundaneId: 2, Persona: 'Luna Honeybadger', RoleName: 'Warcrat', LeadCategories: [] }];
test('pasted lines carry the day and the clock forward', () => {
  const text = ['Friday', '12p Troll Opens', '12:30-1:15 bg: Squatch Ball @Luna Honeybadger', 'then 30m RP Quest Starts', '',
    'SATURDAY:', '9a Tavern Opens', '???', '10-11 class: Naalbinding @Nobody "Bring yarn"', '   '].join('\n');
  const r = C.parseLines(text, { days: DAYS, staff: STAFF });
  assert.deepEqual(r.items.map(i => [i.Title, i.StartTime, i.EndTime, i.Category]), [
    ['Troll Opens', '2026-11-20 12:00', '2026-11-20 13:00', 'Administrative'],
    ['Squatch Ball', '2026-11-20 12:30', '2026-11-20 13:15', 'Battlegame'],
    ['RP Quest Starts', '2026-11-20 13:15', '2026-11-20 13:45', 'Roleplay and Quests'],
    ['Tavern Opens', '2026-11-21 09:00', '2026-11-21 10:00', 'Feast and Food'],
    ['Naalbinding', '2026-11-21 10:00', '2026-11-21 11:00', 'Class'],
  ]);
  assert.deepEqual(r.items[1].Leads.map(l => l.MundaneId), [2]);
  assert.equal(r.items[4].Description, 'Bring yarn');
  assert.deepEqual(r.unread, [{ line: 8, text: '???' }]);
  assert.deepEqual(r.unmatchedLeads, ['Nobody']);
});
test('schedule text reads back through parseLines', () => {
  const items = [
    { Title: 'Squatch Ball', Category: 'Battlegame', StartTime: '2026-11-21 09:30:00', EndTime: '2026-11-21 10:15:00', Location: 'Main Field', Description: '', Leads: [{ MundaneId: 2, Persona: 'Luna Honeybadger' }] },
    { Title: 'Troll Opens', Category: 'Administrative', StartTime: '2026-11-20 12:00:00', EndTime: '2026-11-20 12:30:00', Location: '', Description: '', Leads: [] },
    { Title: 'Night Quest!', Category: 'Roleplay and Quests', StartTime: '2026-11-22 00:30:00', EndTime: '2026-11-22 01:30:00', Location: '', Description: '', Leads: [] },
  ];
  const text = C.scheduleText(items);
  assert.equal(text.split('\n')[0], 'Friday, November 20');
  assert.ok(text.includes('\n\nSaturday, November 21\n'));
  const back = C.parseLines(text, { days: DAYS, staff: STAFF });
  assert.deepEqual(back.unread, []);
  assert.deepEqual(back.items.map(i => [i.Title, i.StartTime + ':00', i.EndTime + ':00', i.Category, i.Location, i.Leads.length]), [
    ['Troll Opens', '2026-11-20 12:00:00', '2026-11-20 12:30:00', 'Administrative', '', 0],
    ['Squatch Ball', '2026-11-21 09:30:00', '2026-11-21 10:15:00', 'Battlegame', 'Main Field', 1],
    ['Night Quest!', '2026-11-22 00:30:00', '2026-11-22 01:30:00', 'Roleplay and Quests', '', 0],
  ]);
});
test('the skeleton parses on one-day and multi-day events', () => {
  const three = C.parseLines(C.skeletonText(DAYS), { days: DAYS });
  assert.deepEqual(three.unread, []);
  assert.deepEqual(three.items.map(i => i.StartTime.slice(0, 10)).filter((d, n, a) => a.indexOf(d) === n), DAYS);
  assert.ok(three.items.some(i => i.Title === 'Court' && i.Category === 'Court' && i.StartTime === '2026-11-21 20:00'));
  assert.ok(three.items.some(i => i.Title === 'Clean Up' && i.StartTime === '2026-11-22 10:00'));
  const one = C.parseLines(C.skeletonText(['2026-11-21']), { days: ['2026-11-21'] });
  assert.deepEqual(one.items.map(i => i.Title), ['Troll Opens', 'Feast', 'Court']);
});
test('conflicts name the shared lead or place on both items', () => {
  const a = { cid: 'a', Title: 'Mothman', StartTime: '2026-11-21 10:00', EndTime: '2026-11-21 11:00', Location: 'Main Field', Leads: [{ MundaneId: 2, Persona: 'Luna' }] };
  const b = { cid: 'b', Title: 'Archery', StartTime: '2026-11-21 10:30', EndTime: '2026-11-21 11:30', Location: ' main field', Leads: [{ MundaneId: 2, Persona: 'Luna' }] };
  const c = { cid: 'c', Title: 'Class', StartTime: '2026-11-21 11:00', EndTime: '2026-11-21 12:00', Location: 'Hall', Leads: [{ MundaneId: 2, Persona: 'Luna' }] };
  const d = { EventScheduleId: 9, Title: 'No place', StartTime: '2026-11-21 10:00', EndTime: '2026-11-21 12:00', Location: '', Leads: [] };
  const r = C.conflicts([a, b, c, d]);
  assert.deepEqual(r.a.sort(), ['Luna is also on Archery', 'Main Field is also booked for Archery']);
  assert.deepEqual(r.b.sort(), ['Luna is also on Class', 'Luna is also on Mothman', 'main field is also booked for Mothman']);
  assert.deepEqual(r.c, ['Luna is also on Archery']);
  assert.equal(r.i9, undefined);
});
```

A message names the item's own location, trimmed, as that item spells it.

And in `tests/js/evb-store.test.js`:

```js
test('adopt adds server rows without sending them and undoes as one step', async () => {
  const f = fake();
  const s = S.create({ items: [], send: f.send, saveMeal: true });
  const cids = s.adopt([item('A', { EventScheduleId: 41 }), item('B', { EventScheduleId: 42 })]);
  assert.deepEqual(cids, ['i41', 'i42']);
  assert.equal(f.calls.length, 0);
  assert.deepEqual(s.status(), { state: 'saved', pending: 0 });
  s.undo(); await s.idle();
  assert.equal(s.items().length, 0);
  assert.deepEqual(f.calls.flat().map(o => [o.op, o.id]).sort(), [['remove', 41], ['remove', 42]]);
});
```

- [ ] **Step 2: Run `npm run test:js`.** Expected: the new tests fail.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run `npm run test:js`.** Expected: all pass.
- [ ] **Step 5: Commit:** `Schedule board: lines of text, skeleton and conflict detection`.

---

### Task 3: Bulk bar, More menu, start from a past event, conflict badges

**Files:**
- Modify: `orkui/template/revised-frontend/script/event-board/evb-edit.js`, `evb-view.js`, `evb-entry.js`
- Modify: `orkui/template/revised-frontend/style/event-board.css`
- Modify: `orkui/template/revised-frontend/Eventnew_index.tpl` (the empty state's second button)
- Modify: `tests/e2e/event-schedule-board.spec.ts`

**Interfaces:**
- Consumes: Task 1's endpoints, Task 2's functions, `EvBoardView.setGhosts`, the store.
- Produces in the DOM: `.evb-bulkbar` (text `N items ready`, `button.evb-bulk-add`, `button.evb-bulk-discard`, a `.evb-bulk-unread` list, a `.evb-bulk-leads` note); `button.evb-more-btn` in the mode bar opening `.evb-more-menu` with `[data-evb-more="copy-text"]`, `"past-event"`, `"skeleton"`; `.evb-copy-dialog` listing sources as `button.evb-copy-source[data-detail-id]`; `.evb-conflict` badges on blocks, each with a `data-tip` holding its messages joined by `; `.

Behaviour (Edit mode):
- **Paste text:** a `paste` event carrying more than one line of text, into the quick line or with no field focused, runs `parseLines` with the current entry context and staff. (Text equal to what the board last copied still pastes blocks, as in Phase 4. A single pasted line in the quick line is ordinary typing.) The items show as ghosts and the bulk bar appears. "Add all" adds them in one `store.batch`, applying category leads to items with no leads, and clears the ghosts. "Discard", or Esc, clears them. The bar lists each unread line as `Line N: text` and, when there are unmatched leads, says `Not event staff, left off: A, B`.
- **Skeleton:** shows `parseLines(skeletonText(days))` as ghosts with the same bar.
- **Copy as text:** puts `scheduleText(store.items())` on the clipboard (`navigator.clipboard.writeText` with the textarea fallback used by the page's "Copy link"), and the menu item reads `Copied` for two seconds.
- **Start from a past event:** opens `.evb-copy-dialog` (a themed modal, not a native dialog) listing the sources as `Name · date · N items`, this event's own occurrences first. Choosing one posts to `schedule_copy_into`; on success the rows are adopted (`store.adopt`), the board scrolls to the first of them, and the mode bar notes `Added N items from <Name>` plus `; K feast items were skipped` when applicable. With no sources the dialog says so.
- **Empty schedule:** the empty state gains a "Start from a past event" button beside "Build the schedule", which enters Edit mode and opens the dialog.
- **Conflicts:** after every store change, blocks named by `conflicts(store.items())` get a `.evb-conflict` badge (a small warning icon in the block's top-right corner). Badges show only in Edit mode.

- [ ] **Step 1: Write the failing browser tests** (inside the signed-in `editing` block):

```ts
test('pasted lines preview as ghosts and are added in one undoable step', async ({ page }) => {
  await openBoard(page, EDIT_EVENT);
  await page.locator('.evb-edit-btn').click();
  const text = ['Sunday', `1p ${PREFIX} paste one`, `then 30m ${PREFIX} paste two`, '???', `3-4 class: ${PREFIX} paste three @Nobody`].join('\n');
  await page.evaluate((t) => {
    const dt = new DataTransfer(); dt.setData('text/plain', t);
    document.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true }));
  }, text);
  await expect(page.locator('.evb-ghost')).toHaveCount(3);
  await expect(page.locator('.evb-bulkbar')).toContainText('3 items ready');
  await expect(page.locator('.evb-bulk-unread')).toContainText('Line 4: ???');
  await expect(page.locator('.evb-bulk-leads')).toContainText('Nobody');
  expect(await page.locator('.evb-block', { hasText: `${PREFIX} paste` }).count()).toBe(0);
  await page.locator('.evb-bulk-add').click();
  await expect(page.locator('.evb-block', { hasText: `${PREFIX} paste` })).toHaveCount(3);
  await expect(page.locator('.evb-ghost')).toHaveCount(0);
  await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
  await page.locator('.evb-undo').click();
  await expect(page.locator('.evb-block', { hasText: `${PREFIX} paste` })).toHaveCount(0);
});
test('the skeleton can be discarded; double-booked places are flagged', async ({ page }) => {
  await openBoard(page, EDIT_EVENT);
  await page.locator('.evb-edit-btn').click();
  await page.locator('.evb-more-btn').click();
  await page.locator('[data-evb-more="skeleton"]').click();
  expect(await page.locator('.evb-ghost').count()).toBeGreaterThan(5);
  await page.locator('.evb-bulk-discard').click();
  await expect(page.locator('.evb-ghost')).toHaveCount(0);
  await addViaQuickLine(page, `sun 5-6p ${PREFIX} clash a at Hall`);
  await addViaQuickLine(page, `sun 5:30-6:30p ${PREFIX} clash b at hall`);
  const badge = page.locator('.evb-block', { hasText: `${PREFIX} clash a` }).locator('.evb-conflict');
  await expect(badge).toHaveAttribute('data-tip', new RegExp(`Hall is also booked for ${PREFIX} clash b`));
});
test('a past schedule can be copied in and undone', async ({ page }) => {
  await openBoard(page, EDIT_EVENT);
  await page.locator('.evb-edit-btn').click();
  await page.locator('.evb-more-btn').click();
  await page.locator('[data-evb-more="past-event"]').click();
  await expect(page.locator('.evb-copy-dialog')).toBeVisible();
  const sources = page.locator('.evb-copy-source');
  test.skip((await sources.count()) === 0, 'no past schedule in scope for the test event');
  const before = await page.locator('.evb-block').count();
  await sources.first().click();
  await expect(page.locator('.evb-notice, .evb-modebar')).toContainText(/Added \d+ items from/);
  expect(await page.locator('.evb-block').count()).toBeGreaterThan(before);
  await page.locator('.evb-undo').click();
  await expect(page.locator('.evb-block')).toHaveCount(before);
});
```

- [ ] **Step 2: Run the spec.** Expected: the new tests fail.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run every suite:** `npm run test:js`, the PHP test file, the whole Playwright spec. Expected: all pass (the past-event test may skip when the test event's park has no past event with a schedule; say so in the report if it does).
- [ ] **Step 5: Commit:** `Schedule board: paste lines, skeleton, start from a past event, conflict hints`.
