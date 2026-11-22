# Event Schedule Board, Phase 4: Direct Manipulation: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In Edit mode, let an editor move, resize, nudge, duplicate, repeat, copy and paste schedule blocks with the mouse and the keyboard.

**Architecture:** Every gesture ends in one store operation (or one `store.batch`), so it saves and undoes as a single step. The arithmetic of each gesture is a pure function in `evb-core.js` with node tests; `evb-drag.js` (new) owns pointer handling and `evb-edit.js` keeps keyboard handling and selection.

**Tech Stack:** vanilla JavaScript, `node --test`, Playwright. No server changes.

**Spec:** `docs/superpowers/specs/2026-11-15-event-schedule-board-design.md` (section 7.5, call 4.14). Phases 1 to 3 are built.

## Global Constraints

- Everything in the Phase 2 plan's Global Constraints applies unchanged (`docs/superpowers/plans/2026-11-15-event-schedule-board-phase-2-edit-and-entry.md`).
- Pointer gestures snap to 15 minutes, or 5 with Alt held. Typed times are never snapped.
- An item is never shorter than 5 minutes after a gesture.
- A gesture that ends where it began changes nothing and records no undo step.
- Pointer gestures apply to mouse and pen only. Touch never drags.
- Keyboard shortcuts act only when Edit mode is on and no text field has focus.
- Moving, resizing, duplicating and pasting never change a lead, a category or any meal field except by copying them whole.

## Dev Runbook

As in the Phase 3 plan (`set -a; . .superpowers/e2e.env; set +a` before Playwright runs).

## Review Focus

1. **Dragging a block across midnight or onto another day** stores the right calendar dates (a block dropped at 12:30am in Saturday's column is Sunday 00:30). Pinned in Task 1.
2. **A drag released outside the board**, or cancelled with Esc, leaves the block where it started and saves nothing. Pinned in Task 2.
3. **Resizing the top edge past the bottom edge** (or the reverse) stops at 5 minutes; it never inverts or deletes the block. Pinned in Task 1.
4. **Shortcuts while typing** (Backspace, digits, arrows, Cmd+D inside the quick line or a block's text) edit the text and never touch the selected block. Pinned in Task 3.
5. **Pasting blocks next to existing ones** keeps their spacing and all their details, and one Undo removes the whole paste. Pinned in Task 1 and Task 3.

---

### Task 1: Gesture arithmetic

**Files:**
- Modify: `orkui/template/revised-frontend/script/event-board/evb-core.js`
- Create: `tests/js/evb-core.gesture.test.js`

**Interfaces:**
- Consumes: `parseNaive`, `toNaive`, `addDays`, `diffDays`, `segments`, `formatEntry`.
- Produces (all pure; times in and out are naive strings; "board minute" means minutes from a board day's 00:00, 300 to 1740):
  - `snap(min, step) -> number` (nearest multiple of `step`).
  - `boardPos(naive) -> {day, min}` (the board day and board minute of a naive time).
  - `moveTimes(item, toDay, toStartMin) -> {StartTime, EndTime}` (start placed at that board day and minute, length kept).
  - `shiftTimes(item, deltaMin, deltaDays) -> {StartTime, EndTime}`.
  - `resizeTimes(item, edge, toMin) -> {StartTime, EndTime}` with `edge` `'start'` or `'end'` and `toMin` a board minute on the item's own board day; the result is clamped so the length is at least 5.
  - `repeatSlots(item, dayItems, untilMin) -> Array<{StartTime, EndTime}>`: copies of the item's length, one after another with a gap equal to (the item's start minus the end of the nearest earlier item of the same category in `dayItems`), or 30 minutes when there is none or that gap is negative, for as long as a copy ends at or before `untilMin`.
  - `duplicateTimes(item) -> {StartTime, EndTime}` (starts where the item ends, same length).
  - `pastePlan(copied, toDay, toStartMin) -> Array<object>`: copies of the items with ids and `cid` removed, shifted so the earliest starts at that day and minute and the others keep their offsets from it.
  - `clipboardText(items) -> string` (one `formatEntry(item, {withDay: true})` line per item, sorted by start).

- [ ] **Step 1: Write the failing tests** in `tests/js/evb-core.gesture.test.js`:

```js
const it = (start, end, more) => Object.assign({ Title: 'T', Category: 'Battlegame', StartTime: start, EndTime: end, Leads: [] }, more);

test('snap and boardPos', () => {
  assert.deepEqual([C.snap(607, 15), C.snap(608, 15), C.snap(607, 5)], [600, 615, 605]);
  assert.deepEqual(C.boardPos('2026-11-21 09:30:00'), { day: '2026-11-21', min: 570 });
  assert.deepEqual(C.boardPos('2026-11-22 00:30'), { day: '2026-11-21', min: 1470 });
  assert.deepEqual(C.boardPos('2026-11-22 05:00'), { day: '2026-11-22', min: 300 });
});
test('moving keeps the length and stores real calendar dates', () => {
  const a = it('2026-11-21 09:30:00', '2026-11-21 10:15:00');
  assert.deepEqual(C.moveTimes(a, '2026-11-21', 600), { StartTime: '2026-11-21 10:00', EndTime: '2026-11-21 10:45' });
  assert.deepEqual(C.moveTimes(a, '2026-11-20', 570), { StartTime: '2026-11-20 09:30', EndTime: '2026-11-20 10:15' });
  assert.deepEqual(C.moveTimes(a, '2026-11-21', 1470), { StartTime: '2026-11-22 00:30', EndTime: '2026-11-22 01:15' });
  assert.deepEqual(C.shiftTimes(a, 15, 0), { StartTime: '2026-11-21 09:45', EndTime: '2026-11-21 10:30' });
  assert.deepEqual(C.shiftTimes(a, -15, 1), { StartTime: '2026-11-22 09:15', EndTime: '2026-11-22 10:00' });
  const late = it('2026-11-21 23:30:00', '2026-11-22 00:30:00');
  assert.deepEqual(C.shiftTimes(late, 60, 0), { StartTime: '2026-11-22 00:30', EndTime: '2026-11-22 01:30' });
});
test('resizing clamps at five minutes and never inverts', () => {
  const a = it('2026-11-21 09:30:00', '2026-11-21 10:15:00');
  assert.deepEqual(C.resizeTimes(a, 'end', 660), { StartTime: '2026-11-21 09:30', EndTime: '2026-11-21 11:00' });
  assert.deepEqual(C.resizeTimes(a, 'end', 540), { StartTime: '2026-11-21 09:30', EndTime: '2026-11-21 09:35' });
  assert.deepEqual(C.resizeTimes(a, 'start', 540), { StartTime: '2026-11-21 09:00', EndTime: '2026-11-21 10:15' });
  assert.deepEqual(C.resizeTimes(a, 'start', 700), { StartTime: '2026-11-21 10:10', EndTime: '2026-11-21 10:15' });
  assert.deepEqual(C.resizeTimes(a, 'end', 1470), { StartTime: '2026-11-21 09:30', EndTime: '2026-11-22 00:30' });
});
test('repeat follows the rhythm of the previous item of the same category', () => {
  const first = it('2026-11-21 09:30:00', '2026-11-21 10:15:00', { Title: 'A' });
  const second = it('2026-11-21 10:45:00', '2026-11-21 11:30:00', { Title: 'B' });
  const other = it('2026-11-21 10:15:00', '2026-11-21 10:45:00', { Title: 'Quest', Category: 'Other' });
  assert.deepEqual(C.repeatSlots(second, [first, second, other], 870), [
    { StartTime: '2026-11-21 12:00', EndTime: '2026-11-21 12:45' },
    { StartTime: '2026-11-21 13:15', EndTime: '2026-11-21 14:00' },
  ]);
  assert.deepEqual(C.repeatSlots(first, [first], 720), [{ StartTime: '2026-11-21 10:45', EndTime: '2026-11-21 11:30' }]);
  assert.deepEqual(C.repeatSlots(first, [first], 630), []);
  const overlapping = it('2026-11-21 09:00:00', '2026-11-21 10:00:00', { Title: 'Z' });
  assert.equal(C.repeatSlots(first, [overlapping, first], 720)[0].StartTime, '2026-11-21 10:45');
});
test('duplicate, paste and clipboard text', () => {
  const a = it('2026-11-21 09:30:00', '2026-11-21 10:15:00', { Title: 'A', EventScheduleId: 5, cid: 'i5', Location: 'Field', Leads: [{ MundaneId: 2, Persona: 'Luna' }], Menu: 'Soup' });
  const b = it('2026-11-21 11:00:00', '2026-11-21 11:30:00', { Title: 'B', EventScheduleId: 6, cid: 'i6' });
  assert.deepEqual(C.duplicateTimes(a), { StartTime: '2026-11-21 10:15', EndTime: '2026-11-21 11:00' });
  const pasted = C.pastePlan([b, a], '2026-11-22', 600);
  assert.deepEqual(pasted.map(p => [p.Title, p.StartTime, p.EndTime]), [
    ['A', '2026-11-22 10:00', '2026-11-22 10:45'], ['B', '2026-11-22 11:30', '2026-11-22 12:00'],
  ]);
  assert.deepEqual([pasted[0].EventScheduleId, pasted[0].cid, pasted[0].Location, pasted[0].Menu, pasted[0].Leads.length], [undefined, undefined, 'Field', 'Soup', 1]);
  pasted[0].Leads.push({});
  assert.equal(a.Leads.length, 1);
  assert.equal(C.clipboardText([b, a]).split('\n').length, 2);
  assert.ok(C.clipboardText([b, a]).startsWith('sat 9:30'));
});
```

- [ ] **Step 2: Run `npm run test:js`.** Expected: the new file fails.
- [ ] **Step 3: Implement the functions.**
- [ ] **Step 4: Run `npm run test:js`.** Expected: all pass.
- [ ] **Step 5: Commit:** `Schedule board: gesture arithmetic`.

---

### Task 2: Drag, resize and keyboard nudge

**Files:**
- Create: `orkui/template/revised-frontend/script/event-board/evb-drag.js`
- Modify: `orkui/template/revised-frontend/script/event-board/evb-edit.js`, `evb-view.js`
- Modify: `orkui/template/revised-frontend/style/event-board.css`
- Modify: `orkui/template/revised-frontend/Eventnew_index.tpl` (script include after `evb-edit.js`)
- Modify: `tests/e2e/event-schedule-board.spec.ts`

**Interfaces:**
- Consumes: Task 1; the store; `EvBoardView.slotAt`, `PX_PER_MIN`, `board`; selection in `evb-edit.js` (expose `EvBoardEdit.selection() -> [cid]` and `EvBoardEdit.select(cids)` if they are not public yet).
- Produces: `EvBoardDrag.attach(rootEl, api)` where `api = {store, getSelection, select, isEditing}`; in the DOM, `.evb-resize-top` and `.evb-resize-bottom` grips on a selected block, `.evb-drag-ghost` while a gesture is in progress, and `.evb-dragging` on the root.

Behaviour:
- Pressing on a block and moving more than 4px starts a move. A translucent ghost follows the pointer, snapped; the time on the ghost updates; the day under the pointer is the target day. Release applies `moveTimes` through `store.update`. If the pressed block is part of a multi-block selection, every selected block moves by the same offset in one `store.batch`.
- The grips on a selected block resize it (`resizeTimes`), the top grip changing the start and the bottom the end.
- Esc during a gesture, or a release outside every day column, cancels it. Near the top or bottom of the scroll container the board scrolls while a gesture is in progress.
- A press without movement is still a click (selection, as in Phase 2).
- With blocks selected: Up/Down apply `shiftTimes(±15, 0)`; Shift+Up/Down move only the end by 15 (`resizeTimes` on `'end'`); Left/Right apply `shiftTimes(0, ±1)`; Alt makes the step 5. Several selected blocks change in one `store.batch`. A block may be nudged onto a day outside the event; it gets a column.

- [ ] **Step 1: Write the failing browser tests** (inside the signed-in `editing` block; helpers `addViaQuickLine(page, text)` and `timesOf(page, title)`, which returns `[StartTime, EndTime]` from `EvBoardEdit.store.items()` with any seconds trimmed, go at the top of the block):

```ts
test('dragging a block moves it and saves once', async ({ page }) => {
  await openBoard(page, EDIT_EVENT);
  await page.locator('.evb-edit-btn').click();
  await addViaQuickLine(page, `fri 9-10 ${PREFIX} drag me`);
  const block = page.locator('.evb-block', { hasText: `${PREFIX} drag me` });
  const box = (await block.boundingBox())!;
  const pxPerMin = await page.evaluate(() => (window as any).EvBoardView.PX_PER_MIN);
  await page.mouse.move(box.x + box.width / 2, box.y + 6);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width / 2, box.y + 6 + 90 * pxPerMin, { steps: 8 });
  await expect(page.locator('.evb-drag-ghost')).toBeVisible();
  await page.mouse.up();
  expect(await timesOf(page, `${PREFIX} drag me`)).toEqual(['2026-11-20 10:30', '2026-11-20 11:30']);
  await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
  await page.locator('.evb-undo').click();
  expect(await timesOf(page, `${PREFIX} drag me`)).toEqual(['2026-11-20 09:00', '2026-11-20 10:00']);
});
test('Escape cancels a drag; the bottom grip resizes', async ({ page }) => {
  await openBoard(page, EDIT_EVENT);
  await page.locator('.evb-edit-btn').click();
  await addViaQuickLine(page, `fri 1-2p ${PREFIX} resize me`);
  const block = page.locator('.evb-block', { hasText: `${PREFIX} resize me` });
  const box = (await block.boundingBox())!;
  const pxPerMin = await page.evaluate(() => (window as any).EvBoardView.PX_PER_MIN);
  await page.mouse.move(box.x + box.width / 2, box.y + 6);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width / 2, box.y + 80, { steps: 5 });
  await page.keyboard.press('Escape');
  await page.mouse.up();
  expect(await timesOf(page, `${PREFIX} resize me`)).toEqual(['2026-11-20 13:00', '2026-11-20 14:00']);
  await block.click();
  const grip = (await block.locator('.evb-resize-bottom').boundingBox())!;
  await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2);
  await page.mouse.down();
  await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2 + 30 * pxPerMin, { steps: 5 });
  await page.mouse.up();
  expect(await timesOf(page, `${PREFIX} resize me`)).toEqual(['2026-11-20 13:00', '2026-11-20 14:30']);
});
test('arrow keys nudge the selected block', async ({ page }) => {
  await openBoard(page, EDIT_EVENT);
  await page.locator('.evb-edit-btn').click();
  await addViaQuickLine(page, `fri 3-4p ${PREFIX} nudge me`);
  await page.locator('.evb-block', { hasText: `${PREFIX} nudge me` }).click();
  await page.keyboard.press('ArrowDown');
  await page.keyboard.press('Shift+ArrowDown');
  await page.keyboard.press('Alt+ArrowUp');
  await page.keyboard.press('ArrowRight');
  expect(await timesOf(page, `${PREFIX} nudge me`)).toEqual(['2026-11-21 15:10', '2026-11-21 16:25']);
});
```

- [ ] **Step 2: Run the spec.** Expected: the three new tests fail.
- [ ] **Step 3: Implement** with pointer events and `setPointerCapture`, ignoring `pointerType === 'touch'`.
- [ ] **Step 4: Run the spec and `npm run test:js`.** Expected: all pass.
- [ ] **Step 5: Commit:** `Schedule board: drag, resize and nudge blocks`.

---

### Task 3: Duplicate, multi-select, copy and paste, repeat, slot cursor

**Files:**
- Modify: `orkui/template/revised-frontend/script/event-board/evb-edit.js`, `evb-drag.js`
- Modify: `orkui/template/revised-frontend/style/event-board.css`
- Modify: `tests/e2e/event-schedule-board.spec.ts`

**Interfaces:**
- Consumes: Task 1 (`duplicateTimes`, `pastePlan`, `clipboardText`, `repeatSlots`), Task 2, the store.
- Produces in the DOM: `.evb-slot-cursor[data-day][data-min]` (the keyboard cursor: one 15-minute slot in one day; `data-min` is its board minute), `.evb-fill-handle` on a single selected block, `.evb-fill-ghost` previews while it is dragged, and a Duplicate button `button[data-evb-act="duplicate"]` in the block toolbar.

Behaviour:
- **Multi-select:** Shift-click adds a block to the selection or removes it. Delete, a category key and the arrow keys act on every selected block in one `store.batch`. The toolbar shows only for a single selection.
- **Duplicate:** Cmd/Ctrl+D or the toolbar button adds a copy of each selected block at `duplicateTimes`, with every field copied, and selects the copies.
- **Copy:** Cmd/Ctrl+C with blocks selected puts `clipboardText(selected)` on the clipboard through the `copy` event and remembers the selected items and that text.
- **Paste:** on a `paste` event with no text field focused, if the pasted text equals the remembered text, add `pastePlan(remembered, cursorDay, cursorMin)` in one `store.batch` and select the new blocks. Any other text is ignored in this phase (Phase 5 turns it into a preview).
- **Slot cursor:** with no block selected and no field focused, the arrow keys move a visible cursor (Up/Down 15 minutes, Left/Right a day; it starts at the last clicked or last added position, else the first day at 9:00). Typing a printable character, or Enter, opens an editing block at the cursor with that character typed. Clicking empty space also moves the cursor there.
- **Repeat:** the fill handle sits at the bottom-right of a single selected block. Dragging it down previews `repeatSlots(item, itemsOnThatBoardDay, minuteUnderPointer)` as ghosts; release adds them in one `store.batch` as copies of the item (all fields) and selects them. Esc cancels.

- [ ] **Step 1: Write the failing browser tests:**

```ts
test('duplicate, shift-select and group delete are each one undo step', async ({ page }) => {
  await openBoard(page, EDIT_EVENT);
  await page.locator('.evb-edit-btn').click();
  await addViaQuickLine(page, `sun 9-9:30 bg: ${PREFIX} dup at Field`);
  const dup = page.locator('.evb-block', { hasText: `${PREFIX} dup` });
  await dup.click();
  await page.keyboard.press('ControlOrMeta+d');
  await expect(dup).toHaveCount(2);
  const copies = await page.evaluate((t) => (window as any).EvBoardEdit.store.items().filter((i: any) => i.Title === t)
    .map((i: any) => [i.StartTime.slice(11, 16), i.Location, i.Category]).sort(), `${PREFIX} dup`);
  expect(copies).toEqual([['09:00', 'Field', 'Battlegame'], ['09:30', 'Field', 'Battlegame']]);
  await dup.first().click();
  await dup.nth(1).click({ modifiers: ['Shift'] });
  await expect(page.locator('.evb-selected')).toHaveCount(2);
  await page.keyboard.press('Delete');
  await expect(dup).toHaveCount(0);
  await page.locator('.evb-undo').click();
  await expect(dup).toHaveCount(2);
});
test('copy and paste at the slot cursor keeps spacing', async ({ page }) => {
  await openBoard(page, EDIT_EVENT);
  await page.locator('.evb-edit-btn').click();
  await addViaQuickLine(page, `fri 6-6:30p ${PREFIX} copy a`);
  await addViaQuickLine(page, `fri 7-7:30p ${PREFIX} copy b`);
  await page.locator('.evb-block', { hasText: `${PREFIX} copy a` }).click();
  await page.locator('.evb-block', { hasText: `${PREFIX} copy b` }).click({ modifiers: ['Shift'] });
  await page.keyboard.press('ControlOrMeta+c');
  await page.keyboard.press('Escape');
  await page.keyboard.press('ArrowRight');
  await expect(page.locator('.evb-slot-cursor')).toBeVisible();
  const cursor = await page.evaluate(() => {
    const el = document.querySelector('.evb-slot-cursor') as HTMLElement;
    return [el.getAttribute('data-day'), Number(el.getAttribute('data-min'))];
  });
  await page.keyboard.press('ControlOrMeta+v');
  await expect(page.locator('.evb-block', { hasText: `${PREFIX} copy a` })).toHaveCount(2);
  const starts = await page.evaluate(([t, day]) => (window as any).EvBoardEdit.store.items()
    .filter((i: any) => i.Title.startsWith(t) && i.StartTime.startsWith(day)).map((i: any) => (window as any).EvBoardCore.boardPos(i.StartTime).min).sort((x: number, y: number) => x - y),
    [`${PREFIX} copy`, cursor[0] as string]);
  expect(starts).toEqual([cursor[1], (cursor[1] as number) + 60]);
});
test('typing at the slot cursor starts an item; shortcuts leave text alone', async ({ page }) => {
  await openBoard(page, EDIT_EVENT);
  await page.locator('.evb-edit-btn').click();
  await addViaQuickLine(page, `sun 2-3p ${PREFIX} keep me`);
  await page.locator('.evb-block', { hasText: `${PREFIX} keep me` }).click();
  const line = page.locator('.evb-quickline .evb-entry-input');
  await line.click();
  await line.type('sun 5p 3d printing');
  await line.press('Backspace');
  await expect(line).toHaveValue('sun 5p 3d printin');
  await expect(page.locator('.evb-block', { hasText: `${PREFIX} keep me` })).toHaveCount(1);
  await line.fill('');
  await line.press('Escape');
  await page.locator('.evb-board').click({ position: { x: 4, y: 4 } });
  await page.keyboard.press('Escape');
  await page.keyboard.press('ArrowDown');
  await page.keyboard.type(`${PREFIX} from cursor`);
  await expect(page.locator('.evb-block-editing .evb-entry-input')).toHaveValue(`${PREFIX} from cursor`);
  await page.keyboard.press('Enter');
  await page.keyboard.press('Escape');
  await expect(page.locator('.evb-block', { hasText: `${PREFIX} from cursor` })).toHaveCount(1);
});
test('the fill handle repeats an item down the day', async ({ page }) => {
  await openBoard(page, EDIT_EVENT);
  await page.locator('.evb-edit-btn').click();
  await addViaQuickLine(page, `sat 5-5:30p bg: ${PREFIX} repeat`);
  const block = page.locator('.evb-block', { hasText: `${PREFIX} repeat` });
  await block.click();
  const h = (await page.locator('.evb-fill-handle').boundingBox())!;
  const pxPerMin = await page.evaluate(() => (window as any).EvBoardView.PX_PER_MIN);
  await page.mouse.move(h.x + h.width / 2, h.y + h.height / 2);
  await page.mouse.down();
  await page.mouse.move(h.x + h.width / 2, h.y + h.height / 2 + 125 * pxPerMin, { steps: 8 });
  await expect(page.locator('.evb-fill-ghost')).toHaveCount(2);
  await page.mouse.up();
  await expect(block).toHaveCount(3);
  await page.locator('.evb-undo').click();
  await expect(block).toHaveCount(1);
});
```

In the last test the item runs 5:00 to 5:30pm with no earlier Battlegame that day in the test's own data; if earlier tests left Battlegame items on Saturday the rhythm would differ, so this test first removes every block whose title starts with the prefix on Saturday (through the store, in `page.evaluate`) before adding its own.

- [ ] **Step 2: Run the spec.** Expected: the four new tests fail.
- [ ] **Step 3: Implement.** Write to the clipboard inside the `copy` event (`event.clipboardData.setData('text/plain', …)` and `preventDefault()`), and read inside the `paste` event; do not use the asynchronous clipboard API, which needs a permission.
- [ ] **Step 4: Run every suite:** `npm run test:js`, the PHP test file, the whole Playwright spec. Expected: all pass.
- [ ] **Step 5: Commit:** `Schedule board: duplicate, multi-select, copy and paste, repeat, keyboard cursor`.
