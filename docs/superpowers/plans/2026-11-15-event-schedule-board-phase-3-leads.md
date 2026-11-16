# Event Schedule Board, Phase 3: Leads: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an event's staff be marked as lead for one or more schedule categories, fill those people in on new items of that category, and let an editor tag a lead while typing with `@`.

**Architecture:** A new table records which categories each staff row leads, and a flag on each item lead records whether it was auto-filled, so removing a category lead never removes a lead someone chose by hand. The server applies the fill and removal rules when staff change; the browser applies the auto-fill when an item is typed, using the staff list it already has. `@` in the entry widget opens the house player-search dropdown, event staff first.

**Tech Stack:** PHP 8, MariaDB, vanilla JavaScript, PHPUnit 11, `node --test`, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-15-event-schedule-board-design.md` (calls 4.10, sections 7.4, 7.5, 7.6, 8.6). Phases 1 and 2 are built.

## Global Constraints

- Everything in the Phase 2 plan's Global Constraints applies unchanged (`docs/superpowers/plans/2026-11-15-event-schedule-board-phase-2-edit-and-entry.md`): layers, `ExecuteChecked` and `BeginTrans`, naive times, escaping, no native dialogs, `data-tip`, dark mode, `evb-` prefix, git rules, account and host rules, forbidden scripts.
- Category leads grant no permission. Authorisation for staff changes and schedule changes is unchanged.
- Only the primary category of an item decides its category leads.
- A lead row with `is_auto = 0` is never removed by a staff change.
- Player search uses the `kn-ac-results` dropdown pattern, the same endpoint, scoping and debounce as the schedule modal's lead search (`revised.js`, the autocomplete near `evSchedLeads`), with `&q=` on the query string, never `?q=`.
- The migration is additive and idempotent, is applied to both local databases (`ork` and `ork_test`), and gets a manifest entry. The collation of the new table is `utf8mb4_unicode_ci`.

## Dev Runbook

As in the Phase 2 plan, plus:

- Apply the migration: `docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-11-15-event-staff-category-leads.sql` and `docker exec -i ork3-php8-test-db mariadb -u root -proot ork_test < db-migrations/2026-11-15-event-staff-category-leads.sql`.
- Editor browser tests: `set -a; . .superpowers/e2e.env; set +a` before the Playwright command. The test account is itself a staff row on the test event (role "E2E Editor").

## Review Focus

1. **Un-marking a category lead** removes that person only where they were auto-filled; an item where an editor picked them by hand keeps them. Pinned in Task 1.
2. **A staff member added after the schedule exists** is filled in only on items with no lead at all, and the count shown matches the rows changed. Pinned in Task 1.
3. **Removing an auto chip before adding** an item leaves that item without the lead, and it does not come back on the next refresh. Pinned in Task 3.
4. **A persona containing quotes, `<`, `@` or spaces** shows correctly in the dropdown and chips and never breaks the entry text. Pinned in Task 3 (unit).
5. **Changing an item's category** swaps auto-filled leads and keeps hand-picked ones. Pinned in Task 3.

---

### Task 1: Category leads on the server

**Files:**
- Create: `db-migrations/2026-11-15-event-staff-category-leads.sql`
- Modify: `tools/ork-db/manifests/migration-classification.json5` (one entry; mind the comma rule: the previous last entry gains a comma and the new last entry has none)
- Modify: `system/lib/ork3/class.EventPlanning.php` (`AddEventStaff`, `RemoveEventStaff`, `fetchStaffList`, `fetchScheduleList`, the lead writes in `saveEventSchedule` and in the batch, `copyStaffModule`, `copyScheduleModules`)
- Modify: `orkui/controller/controller.EventAjax.php` (`add_staff`, `remove_staff`, `_decodeScheduleLeads`)
- Modify: `tests/Support/EventPlanningFixture.php`, `tests/Integration/EventPlanningTest.php`

**Interfaces:**
- Produces:
  - Table `ork_event_staff_category (event_staff_id INT NOT NULL, category VARCHAR(50) NOT NULL, PRIMARY KEY (event_staff_id, category), FOREIGN KEY (event_staff_id) REFERENCES ork_event_staff (event_staff_id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`, created with `CREATE TABLE IF NOT EXISTS`; and `ALTER TABLE ork_event_schedule_lead ADD COLUMN IF NOT EXISTS is_auto TINYINT(1) NOT NULL DEFAULT 0`. Manifest entry: `"2026-11-15-event-staff-category-leads.sql": { "class": "S", "render": "full", "notes": "Category leads on event staff; auto-filled flag on schedule leads. Idempotent." }`.
  - `AddEventStaff` request gains `LeadCategories` (list of category names; names not in `ScheduleCategories()` are dropped; absent means "leave as they are" on an update and "none" on an add). Its response gains `Staff.LeadCategories` (in definition order), `LeadsAdded` and `LeadsRemoved` (ints).
  - `RemoveEventStaff` response gains `LeadsRemoved`.
  - `fetchStaffList` rows gain `LeadCategories`. `fetchScheduleList` lead entries gain `Auto` (bool).
  - Lead entries accepted by `saveEventSchedule` and by batch ops may carry `Auto` (truthy stores `is_auto = 1`).
  - `add_staff` reads `$_POST['LeadCategories']` (a JSON array string; missing means absent) and returns `staff.LeadCategories`, `leadsAdded`, `leadsRemoved`. `remove_staff` returns `leadsRemoved`.

Rules:
- Saving a staff row replaces its category rows with the given list, inside one transaction with the fill and removal below.
- **Fill** (for each category newly added to this staff row): on every schedule item of this occurrence whose primary category is that category and that has no lead rows at all, insert this person with `is_auto = 1`. `LeadsAdded` counts the rows inserted.
- **Removal** (for each category this staff row no longer leads, and for every category it led when the staff row is removed): delete this person's lead rows with `is_auto = 1` on items of this occurrence whose primary category is that category. `LeadsRemoved` counts the rows deleted.
- `copyStaffModule` copies category rows to the new staff rows; `copyScheduleModules` copies `is_auto`.
- `AddEventStaff` and `RemoveEventStaff` must confirm that the occurrence belongs to the event in the request before doing anything (Phase 2 added this check to the schedule paths and found the staff paths appear to lack it), returning `InvalidParameter('Invalid Event ID.')` otherwise. Add a test, `testStaffChangesRejectAnOccurrenceOfAnotherEvent`: a caller with authority over event A passes event A's id with event B's occurrence id to `AddEventStaff` and to `RemoveEventStaff`; both return a non-zero status and event B's staff rows are unchanged.

- [ ] **Step 1: Write the migration and the manifest entry, and apply the migration to both databases** (runbook). Verify with `SHOW CREATE TABLE ork_event_staff_category` and `SHOW COLUMNS FROM ork_event_schedule_lead LIKE 'is_auto'` on each.
- [ ] **Step 2: Add fixture helpers:** `fetchStaffCategories(int $staffId): array` (sorted names), `fetchScheduleLeads(int $scheduleId): array` (list of `['mundane_id' => int, 'is_auto' => int]` ordered by mundane id), and give `insertScheduleLead` an optional third parameter `bool $isAuto = false`.
- [ ] **Step 3: Write the failing tests.** Helper:

```php
/** Adds or edits a staff row through the domain and returns the response. */
private function saveStaff(array $ctx, string $token, int $mundaneId, array $leadCategories, int $staffId = 0): array
{
    unset($_SESSION['is_authorized_mundane_id']);
    return $this->planning->AddEventStaff([
        'Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'],
        'MundaneId' => $mundaneId, 'RoleName' => 'Warcrat', 'CanManage' => 0, 'CanAttendance' => 0,
        'CanSchedule' => 0, 'CanFeast' => 0, 'StaffId' => $staffId, 'LeadCategories' => $leadCategories,
    ]);
}
```

```php
public function testStaffLeadCategoriesAreSavedInDefinitionOrder(): void
{
    [$ctx, $token] = $this->batchContext('lead-save');
    $warcrat = $this->fixture->createPlayer('lead-save-p');
    $r = $this->saveStaff($ctx, $token, $warcrat, ['Class', 'Jousting', 'Battlegame']);
    $this->assertSame(0, $r['Status']['Status']);
    $this->assertSame(['Battlegame', 'Class'], $r['Staff']['LeadCategories']);
    $staffId = (int) $r['Staff']['EventStaffId'];
    $this->assertSame(['Battlegame', 'Class'], $this->fixture->fetchStaffCategories($staffId));
    $r2 = $this->saveStaff($ctx, $token, $warcrat, ['Court'], $staffId);
    $this->assertSame(['Court'], $this->fixture->fetchStaffCategories($staffId));
    $this->assertSame(['Court'], $r2['Staff']['LeadCategories']);
}

public function testNewCategoryLeadFillsOnlyItemsWithNoLead(): void
{
    [$ctx, $token] = $this->batchContext('lead-fill');
    $warcrat = $this->fixture->createPlayer('lead-fill-w');
    $other = $this->fixture->createPlayer('lead-fill-o');
    $unled = $this->fixture->insertSchedule($ctx['detail_id'], 'Mothman', 'Battlegame');
    $led = $this->fixture->insertSchedule($ctx['detail_id'], 'Jersey Devil', 'Battlegame');
    $this->fixture->insertScheduleLead($led, $other);
    $class = $this->fixture->insertSchedule($ctx['detail_id'], 'Naalbinding', 'Class');
    $r = $this->saveStaff($ctx, $token, $warcrat, ['Battlegame']);
    $this->assertSame(1, $r['LeadsAdded']);
    $this->assertSame(0, $r['LeadsRemoved']);
    $this->assertSame([['mundane_id' => $warcrat, 'is_auto' => 1]], $this->fixture->fetchScheduleLeads($unled));
    $this->assertSame([['mundane_id' => $other, 'is_auto' => 0]], $this->fixture->fetchScheduleLeads($led));
    $this->assertSame([], $this->fixture->fetchScheduleLeads($class));
}

public function testDroppingACategoryLeadRemovesOnlyAutoFilledLeads(): void
{
    [$ctx, $token] = $this->batchContext('lead-drop');
    $warcrat = $this->fixture->createPlayer('lead-drop-w');
    $auto = $this->fixture->insertSchedule($ctx['detail_id'], 'Mothman', 'Battlegame');
    $r = $this->saveStaff($ctx, $token, $warcrat, ['Battlegame']);
    $staffId = (int) $r['Staff']['EventStaffId'];
    $hand = $this->fixture->insertSchedule($ctx['detail_id'], 'Her Own Game', 'Battlegame');
    $this->fixture->insertScheduleLead($hand, $warcrat);
    $otherCat = $this->fixture->insertSchedule($ctx['detail_id'], 'Her Class', 'Class');
    $this->fixture->insertScheduleLead($otherCat, $warcrat, true);
    $r2 = $this->saveStaff($ctx, $token, $warcrat, [], $staffId);
    $this->assertSame(1, $r2['LeadsRemoved']);
    $this->assertSame([], $this->fixture->fetchScheduleLeads($auto));
    $this->assertCount(1, $this->fixture->fetchScheduleLeads($hand));
    $this->assertCount(1, $this->fixture->fetchScheduleLeads($otherCat));
}

public function testRemovingStaffAppliesTheRemovalRule(): void
{
    [$ctx, $token] = $this->batchContext('lead-remove');
    $warcrat = $this->fixture->createPlayer('lead-remove-w');
    $auto = $this->fixture->insertSchedule($ctx['detail_id'], 'Mothman', 'Battlegame');
    $staffId = (int) $this->saveStaff($ctx, $token, $warcrat, ['Battlegame'])['Staff']['EventStaffId'];
    $hand = $this->fixture->insertSchedule($ctx['detail_id'], 'Her Own Game', 'Battlegame');
    $this->fixture->insertScheduleLead($hand, $warcrat);
    unset($_SESSION['is_authorized_mundane_id']);
    $r = $this->planning->RemoveEventStaff(['Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id'], 'StaffId' => $staffId]);
    $this->assertSame(0, $r['Status']['Status']);
    $this->assertSame(1, $r['LeadsRemoved']);
    $this->assertSame([], $this->fixture->fetchScheduleLeads($auto));
    $this->assertCount(1, $this->fixture->fetchScheduleLeads($hand));
    $this->assertSame([], $this->fixture->fetchStaffCategories($staffId));
}

public function testLeadAutoFlagRoundTripsThroughBatchAndLists(): void
{
    [$ctx, $token] = $this->batchContext('lead-auto');
    $d = $this->batchDay();
    $a = $this->fixture->createPlayer('lead-auto-a');
    $b = $this->fixture->createPlayer('lead-auto-b');
    $r = $this->batch($ctx, $token, [['op' => 'add', 'cid' => 'c1', 'item' => [
        'Title' => 'Mothman', 'StartTime' => "$d 12:00", 'EndTime' => "$d 12:45", 'Category' => 'Battlegame',
        'Leads' => [['MundaneId' => $a, 'Auto' => 1], ['MundaneId' => $b]],
    ]]]);
    $id = (int) $r['Results'][0]['id'];
    $byId = array_column($r['Results'][0]['schedule']['Leads'], 'Auto', 'MundaneId');
    $this->assertSame([$a => true, $b => false], $byId);
    $this->batch($ctx, $token, [['op' => 'update', 'id' => $id, 'fields' => ['Leads' => [['MundaneId' => $a]]]]]);
    $this->assertSame([['mundane_id' => $a, 'is_auto' => 0]], $this->fixture->fetchScheduleLeads($id));
    unset($_SESSION['is_authorized_mundane_id']);
    $list = $this->planning->GetScheduleForManage(['Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id']]);
    $this->assertArrayHasKey('LeadCategories', $list['StaffList'][0] ?? ['LeadCategories' => []]);
}
```

Add one more, `testCopyCarriesCategoryLeadsAndAutoFlags`, modelled on `testCreateWithCopyModules` (about line 292): the source occurrence has a staff row leading Battlegame and a Battlegame item with that person auto-filled; after `CreateEventWithCopy` with the staff and schedule modules, the new occurrence's staff row has `['Battlegame']` and the copied item's lead has `is_auto = 1`.

- [ ] **Step 4: Run the PHP tests.** Expected: the six new tests fail.
- [ ] **Step 5: Implement** the domain changes and the controller fields.
- [ ] **Step 6: Run the PHP tests.** Expected: all pass. `php -l` each changed PHP file.
- [ ] **Step 7: Commit** (including the migration and manifest): `Schedule board: category leads on event staff`.

---

### Task 2: Category leads in the Staff tab

**Files:**
- Modify: `orkui/template/revised-frontend/Eventnew_index.tpl` (staff table at about 2018-2060; staff modal at about 2591-2653; `EvConfig.staffList`)
- Modify: `orkui/template/revised-frontend/script/revised.js` (`evOpenStaffModal`, `evEditStaff`, `evSubmitStaff`, `evRemoveStaff`, near 9470-9790)
- Modify: `orkui/template/revised-frontend/style/event-board.css`
- Modify: `orkui/controller/controller.Event.php` only if `StaffList` needs passing differently
- Modify: `tests/e2e/event-schedule-board.spec.ts`

**Interfaces:**
- Consumes: Task 1's `LeadCategories` on staff rows and its counts; `EvConfig.scheduleCategories`.
- Produces: `EvConfig.staffList` entries `{MundaneId, Persona, RoleName, LeadCategories}`; staff table rows with `data-lead-categories` (JSON array) and a "Leads" column of `.evb-cat-chip[data-category]` chips; in the modal a `#ev-staff-lead-cats` group of toggle buttons `.evb-cat-toggle[data-category][aria-pressed]`; a status line `#ev-staff-lead-note` beside the table; and `window.evOnStaffChanged(fn)` to register a callback that receives the fresh staff list (`[{MundaneId, Persona, RoleName, LeadCategories}]`) after any staff save or removal.

Behaviour:
- The modal shows "Category lead for" with one toggle per category in the board's bold colours; editing a staff row pre-presses the ones it leads. The toggles post as `LeadCategories` (JSON array).
- The table's "Leads" column sits after "Role". The JS row builder produces the same cell as the PHP row.
- After a save or removal, `#ev-staff-lead-note` reads, as applicable, `Added as lead on N item(s).` and/or `Removed as lead from N item(s).`, and is empty when both counts are zero. The Schedule views refresh (`evRefreshScheduleGrid()`), and callbacks registered with `evOnStaffChanged` run.
- Category chips are labelled with the category name and are legible in both themes.

- [ ] **Step 1: Write the failing browser test** (inside the signed-in `editing` block; the test account's own staff row is the one edited, and the test restores it at the end):

```ts
test('a staff member can be made a category lead', async ({ page }) => {
  await signIn(page);
  await page.goto(`index.php?Route=Event/detail/${EDIT_EVENT}#ev-tab-staff`);
  const row = page.locator('#ev-staff-tbody tr', { hasText: 'E2E Schedule Editor' });
  await row.locator('[data-tip="Edit"]').click();
  const toggle = page.locator('#ev-staff-lead-cats .evb-cat-toggle[data-category="Battlegame"]');
  await expect(toggle).toHaveAttribute('aria-pressed', 'false');
  await toggle.click();
  await page.locator('#ev-staff-save-btn').click();
  await expect(row.locator('.evb-cat-chip[data-category="Battlegame"]')).toBeVisible();
  await page.reload();
  await expect(page.locator('#ev-staff-tbody tr', { hasText: 'E2E Schedule Editor' }).locator('.evb-cat-chip[data-category="Battlegame"]')).toBeVisible();
  expect(await page.evaluate(() => (window as any).EvConfig.staffList.find((s: any) => s.Persona === 'E2E Schedule Editor').LeadCategories)).toEqual(['Battlegame']);
});
```

Leave the account as Battlegame lead at the end of this test; Task 3's tests rely on it, and the spec's `afterAll` clears it (edit the row, un-press every toggle, save).

- [ ] **Step 2: Run the spec.** Expected: the new test fails.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run the spec.** Expected: pass, with every earlier test still passing.
- [ ] **Step 5: Commit:** `Schedule board: category leads in the Staff tab`.

---

### Task 3: `@` leads and auto-fill while typing

**Files:**
- Modify: `orkui/template/revised-frontend/script/event-board/evb-core.js` (`parseEntry`, `formatEntry`; a pure lead helper)
- Modify: `orkui/template/revised-frontend/script/event-board/evb-entry.js`, `evb-edit.js`
- Modify: `orkui/template/revised-frontend/script/revised.js` (the schedule modal's category change in add mode; lead entries keep `Auto`)
- Modify: `orkui/template/revised-frontend/style/event-board.css`
- Modify: `tests/js/evb-core.parse.test.js`, `tests/e2e/event-schedule-board.spec.ts`

**Interfaces:**
- Consumes: `EvConfig.staffList` with `LeadCategories`, `evOnStaffChanged` (Task 2); the store, entry widget and edit mode (Phase 2).
- Produces:
  - `parseEntry` reads `ctx.staff` (`[{MundaneId, Persona}]`) and returns two more keys: `leads` (`[{MundaneId, Persona}]`, each `@` followed by the longest staff persona that matches ignoring case) and `unmatchedLeads` (`[string]`, the single word after an `@` that matched no one). Both kinds of `@` text are removed from the title. An `@` not at the start of a word (as in an email address) is left alone. `given.leads` is true when any `@` was read.
  - `formatEntry` appends ` @<Persona>` for each lead.
  - `EvBoardCore.leadsForCategory(staff, category) -> [{MundaneId, Persona, Auto: true}]` (staff whose `LeadCategories` include it, in staff order) and `EvBoardCore.swapAutoLeads(leads, staff, newCategory) -> leads` (hand-picked leads kept, auto ones replaced by the new category's, no duplicate people).
  - The entry widget gains `opts.searchPlayers(query) -> Promise<[{MundaneId, Persona, Detail}]>` and `opts.getStaff() -> staff`; `entry.getLeads()`; chips `.evb-chip[data-part="lead"][data-mundane-id]` with class `evb-chip-auto` for auto-filled ones.

Behaviour:
- Typing `@` at the start of a word opens the suggestion list at once with event staff (persona, role, and their lead categories as small swatches). As more is typed, staff are filtered and player-search results are appended after the debounce, shown as `Persona (K:P)` with the abbreviations muted. Names may contain spaces; the query runs from the `@` to the caret. Up/Down move, Enter or Tab picks, Esc closes and leaves the text.
- Picking removes the `@query` from the text and adds a lead chip. A chip's × removes that lead. A person appears at most once.
- As soon as the parsed category has category leads, they appear as `evb-chip-auto` chips. Removing one keeps it removed for this entry even if the category is re-parsed. Changing the category while typing swaps the auto chips.
- On commit the item's `Leads` are the chips: `{MundaneId, Persona, Auto}`.
- Setting a block's category (key, menu, or an in-place edit that changes it) applies `swapAutoLeads`.
- Renaming an item in place edits the title only, stored exactly as typed with no grammar applied (design call 4.18, made after Phase 2's reviews): the rename box holds the stored title verbatim; Enter or clicking away saves it when it differs, trimmed; an empty box saves nothing; Esc restores it. The item's current leads show as chips beside it; a chip's × removes that lead, and typing `@` in the box adds one through the same suggestion list, without the `@query` text ever becoming part of the title. Nothing else about the item is touched by a rename. This replaces the Phase 2 code that re-parsed the edited line (`editedTitle`, `changedFields` and the prefill of location and description).
- Every save keeps each lead's `Auto` flag: the store's wire format sends `Leads` as `[{MundaneId, Auto}]`, the page's embedded schedule carries `Auto`, and the schedule modal posts `Auto` back for leads it did not change. A lead the person adds by hand is `Auto: false`.
- The schedule modal, in add mode: changing the primary category sets the lead list to that category's leads (marked auto) when the list has not been touched by hand; the posted `Leads` JSON carries `Auto` for them.
- When staff change (`evOnStaffChanged`) or the store refreshes, later entries use the new staff list.

- [ ] **Step 1: Write the failing unit tests** (append to `tests/js/evb-core.parse.test.js`):

```js
const STAFF = [
  { MundaneId: 1, Persona: 'Luna', RoleName: 'A&S', LeadCategories: ['Class'] },
  { MundaneId: 2, Persona: 'Luna Honeybadger', RoleName: 'Warcrat', LeadCategories: ['Battlegame', 'Class'] },
  { MundaneId: 3, Persona: 'Sir "Quote" <b>', RoleName: 'Gate', LeadCategories: [] },
];
test('@ matches the longest staff persona and never stays in the title', () => {
  let r = p('sat 9a bg: Mothman @luna honeybadger at Field', { staff: STAFF });
  assert.deepEqual([r.title, r.location, r.leads.map(l => l.MundaneId), r.unmatchedLeads, r.given.leads], ['Mothman', 'Field', [2], [], true]);
  r = p('9a Class @Luna @nobody here', { staff: STAFF });
  assert.deepEqual([r.title, r.leads.map(l => l.MundaneId), r.unmatchedLeads], ['Class here', [1], ['nobody']]);
  r = p('9a Mail crat@example.org about gate', { staff: STAFF });
  assert.deepEqual([r.title, r.leads, r.given.leads], ['Mail crat@example.org about gate', [], false]);
  r = p('9a Gate @sir "quote" <b>', { staff: STAFF });
  assert.deepEqual([r.leads.map(l => l.MundaneId), r.title, r.description], [[3], 'Gate', '']);
  assert.deepEqual(p('9a Mothman', { staff: STAFF }).leads, []);
});
test('category leads and swapping', () => {
  assert.deepEqual(C.leadsForCategory(STAFF, 'Class').map(l => [l.MundaneId, l.Auto]), [[1, true], [2, true]]);
  assert.deepEqual(C.leadsForCategory(STAFF, 'Court'), []);
  const leads = [{ MundaneId: 2, Persona: 'Luna Honeybadger', Auto: true }, { MundaneId: 9, Persona: 'Hand Picked', Auto: false }];
  assert.deepEqual(C.swapAutoLeads(leads, STAFF, 'Class').map(l => [l.MundaneId, !!l.Auto]), [[9, false], [1, true], [2, true]]);
  assert.deepEqual(C.swapAutoLeads(leads, STAFF, 'Court').map(l => l.MundaneId), [9]);
  const handIsAlsoLead = [{ MundaneId: 1, Persona: 'Luna', Auto: false }];
  assert.deepEqual(C.swapAutoLeads(handIsAlsoLead, STAFF, 'Class').map(l => [l.MundaneId, !!l.Auto]), [[1, false], [2, true]]);
});
test('formatEntry writes leads and they read back for staff', () => {
  const it = { Title: 'Mothman', Category: 'Battlegame', StartTime: '2026-11-21 09:30:00', EndTime: '2026-11-21 10:15:00', Location: '', Description: '', Leads: [{ MundaneId: 2, Persona: 'Luna Honeybadger' }] };
  assert.equal(C.formatEntry(it), '9:30–10:15a #bg Mothman @Luna Honeybadger');
  assert.deepEqual(C.parseEntry(C.formatEntry(it), { days: DAYS, staff: STAFF }).leads.map(l => l.MundaneId), [2]);
});
```

- [ ] **Step 2: Write the failing browser tests** (the account is Battlegame lead from Task 2):

```ts
test('a category lead is filled in, can be removed before adding, and @ adds one', async ({ page }) => {
  await openBoard(page, EDIT_EVENT);
  await page.locator('.evb-edit-btn').click();
  const line = page.locator('.evb-quickline .evb-entry-input');
  await line.fill(`sat 1p bg: ${PREFIX} auto lead`);
  await expect(page.locator('.evb-chip-auto[data-part="lead"]')).toContainText('E2E Schedule Editor');
  await line.press('Enter');
  await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
  await line.fill(`sat 2p bg: ${PREFIX} no lead`);
  await page.locator('.evb-chip-auto[data-part="lead"] .evb-chip-x').click();
  await expect(page.locator('.evb-chip[data-part="lead"]')).toHaveCount(0);
  await line.press('Enter');
  await line.fill(`sat 3p class: ${PREFIX} tagged @E2E`);
  await expect(page.locator('.evb-entry-option').first()).toContainText('E2E Schedule Editor');
  await line.press('Enter');
  await expect(line).toHaveValue(new RegExp(`${PREFIX} tagged\\s*$`));
  await expect(page.locator('.evb-chip[data-part="lead"]:not(.evb-chip-auto)')).toContainText('E2E Schedule Editor');
  await line.press('Enter');
  await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
  const leads = await page.evaluate((prefix) => {
    const items = (window as any).EvBoardEdit.store.items();
    const of = (t: string) => items.find((i: any) => i.Title === `${prefix} ${t}`).Leads.map((l: any) => [l.Persona, !!l.Auto]);
    return [of('auto lead'), of('no lead'), of('tagged')];
  }, PREFIX);
  expect(leads).toEqual([[['E2E Schedule Editor', true]], [], [['E2E Schedule Editor', false]]]);
  await page.locator('.evb-done').click();
  await page.reload();
  await page.locator('.evb-block', { hasText: `${PREFIX} no lead` }).click();
  await expect(page.locator('.evb-popover')).not.toContainText('E2E Schedule Editor');
});
test('changing a category swaps auto-filled leads and keeps hand-picked ones', async ({ page }) => {
  await openBoard(page, EDIT_EVENT);
  await page.locator('.evb-edit-btn').click();
  const line = page.locator('.evb-quickline .evb-entry-input');
  await line.fill(`sun 1p ${PREFIX} swap`);
  await line.press('Enter');
  const block = page.locator('.evb-block', { hasText: `${PREFIX} swap` });
  await block.click();
  await page.keyboard.press('3');
  await expect(block).toHaveAttribute('data-category', 'Battlegame');
  const after3 = await page.evaluate((t) => (window as any).EvBoardEdit.store.items().find((i: any) => i.Title === t).Leads.map((l: any) => !!l.Auto), `${PREFIX} swap`);
  expect(after3).toEqual([true]);
  await page.keyboard.press('7');
  const after7 = await page.evaluate((t) => (window as any).EvBoardEdit.store.items().find((i: any) => i.Title === t).Leads.length, `${PREFIX} swap`);
  expect(after7).toBe(0);
});
```

- [ ] **Step 3: Run both suites.** Expected: the new tests fail.
- [ ] **Step 4: Implement.** Find the modal's lead search in `revised.js` (the autocomplete next to `evSchedLeads`) and call the same URL with the same scope parameters from `searchPlayers`; do not write a second search endpoint. Position the suggestion list with fixed positioning so the board's scroll container cannot clip it.
- [ ] **Step 5: Run every suite for the phase:** `npm run test:js`, the PHP test file, and the whole Playwright spec. Expected: all pass.
- [ ] **Step 6: Commit:** `Schedule board: tag leads with @ and auto-fill category leads`.
