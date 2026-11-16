# Event Schedule Board, Phase 2: Edit Mode and Typed Entry: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let someone who can manage an event's schedule switch the Board into an Edit mode and add, retime, recategorise and delete items by typing, with every change saved as it is made and undoable.

**Architecture:** A pure client store (`evb-store.js`) owns the items, the undo history and a serial save queue that talks to one new batch endpoint. A pure parser in `evb-core.js` turns typed text into an item. `evb-entry.js` is the text widget (input, chips, dropdowns) used both as the quick line and inside a block; `evb-edit.js` is the mode itself (mode bar, pointer and keyboard handling). The server gains `ApplyScheduleOps`, a transactional batch with true partial updates; the existing modal endpoints keep their behaviour.

**Tech Stack:** PHP 8, MariaDB, vanilla JavaScript (no bundler), PHPUnit 11, `node --test`, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-15-event-schedule-board-design.md` (sections 4, 7.3-7.5, 8.2, 8.3, 8.5, 9). Phase 1 (`docs/superpowers/plans/2026-11-15-event-schedule-board-phase-1-board-view.md`) is already built.

## Global Constraints

- SQL only in `system/lib/ork3/`. `orkui/model/` is the only way from `orkui/` to the domain. Templates are plain PHP. Nothing under `orkui/template/default/` changes.
- Database writes in new code use `ExecuteChecked` and `BeginTrans` / `CommitTrans` / `RollbackTrans` (`system/lib/Yapo2/class.YapoMysql.php`). `Execute()` returns nothing and `query()` never returns false, so neither can detect a failure. `DataSet()` needs `Clear()` before it and `->Next()` after it.
- Schedule times are naive local datetimes. JavaScript never constructs a `Date` from a schedule time and reads hours, minutes or the date back. Use `EvBoardCore` arithmetic.
- All user text is escaped before it enters HTML.
- No native `alert`, `confirm` or `prompt` (the browser's own `beforeunload` prompt is the one exception). Tooltips use `data-tip`, never `title`.
- Dark mode is `html[data-theme="dark"] <selector>`; every new colour rule needs its dark counterpart or `--ork-*` variables. New CSS classes are prefixed `evb-`.
- A board day runs 5:00am to 5:00am. Minutes are counted from the board day's 00:00, so 12:30am the following morning is minute 1470.
- Nothing typed is silently dropped: a rejected add returns its text to the quick line.
- A batch update never changes a field that was not sent, and never changes menu, cost, dietary or allergens for a caller who cannot manage feast.
- Board editing requires "manage schedule" (`EvConfig.canManageSchedule`). Feast-only staff do not get Edit mode.
- Git: stage explicit paths only, run `git diff --cached --stat` before every commit, never stage `system/lib/ork3/class.Authorization.php`, plain single git commands from the worktree root. Commit messages start `Schedule board: ` and end with `Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>`.
- Never log in or out as `heraldsbridge` or `admin`; never use `localhost:19080` in a browser or cookie jar; use `127.0.0.1:19081` only.
- Do not run `bin/run-ork-db-checks.sh`, `bin/run-unit-tests.sh` or `bin/ork-db deploy-sandbox`. No destructive SQL.

## Dev Runbook

From the worktree root `/Users/averykrouse/GitHub/ORK-tobias/ORK3-tobias/.worktrees/event-schedule-entry`:

- Serve: `python3 .superpowers/wtproxy.py event-schedule-entry 19081 &` (check `curl -s -o /dev/null -w '%{http_code}' 'http://127.0.0.1:19081/orkui/index.php?Route=Health'` first).
- PHP tests: `ENVIRONMENT=TEST php ../../vendor/bin/phpunit -c phpunit.xml.dist --no-coverage --cache-directory=/private/tmp/claude-501/-Users-averykrouse-GitHub-ORK-tobias-ORK3-tobias/e05f9fe0-6324-41e9-8249-74fc79e03ee5/scratchpad/phpcache tests/Integration/EventPlanningTest.php`
- JS tests: `npm run test:js`
- Playwright: `ORK3_E2E_BASE_URL=http://127.0.0.1:19081/orkui/ npx --no-install playwright test tests/e2e/event-schedule-board.spec.ts --project=chromium --output=/private/tmp/claude-501/-Users-averykrouse-GitHub-ORK-tobias-ORK3-tobias/e05f9fe0-6324-41e9-8249-74fc79e03ee5/scratchpad/pw-out` plus the environment variables each task names.
- Read-only SQL: `docker exec ork3-php8-db mariadb -u ork -psecret ork -N -e "<query>"`.

## Review Focus

1. **A second edit made while the first is still saving** (drag, then drag again within a second) must end with the server holding the last position, not the first. Pinned in Task 2.
2. **The connection drops mid-edit.** Changes stay on screen, the mode bar offers Retry, and Retry sends them once, in order. Pinned in Task 2.
3. **Text that looks like grammar but is not** ("Level 1-3 Tournament", "Sunday Brunch" typed inside a block, "Sir Adler: Train the Trainer", "#1 Draft Pick") keeps its words. Pinned in Task 3.
4. **A schedule-only editor moves a feast item.** Its menu, cost, dietary and allergens are unchanged afterwards. Pinned in Task 1.
5. **Two browser tabs editing the same schedule.** A stale tab's save of one item must not disturb other items, and returning to a tab picks up the other's changes. Pinned in Task 1 (partial update) and Task 4 (refresh on focus).

---

### Task 1: Batch save on the server

**Files:**
- Modify: `system/lib/ork3/class.EventPlanning.php` (`saveEventSchedule` at about 1508-1690, `RemoveEventSchedule` at about 1252, `fetchScheduleList` at about 800; new methods)
- Modify: `orkui/model/model.EventPlanning.php`
- Modify: `orkui/controller/controller.EventAjax.php` (new actions beside `update_schedule`)
- Modify: `tests/Support/EventPlanningFixture.php` (two read helpers)
- Test: `tests/Integration/EventPlanningTest.php`

**Interfaces:**
- Produces:
  - `EventPlanning::ApplyScheduleOps(array $request): array`. Request: `Token`, `EventId`, `EventCalendarDetailId`, `Ops` (list). Success: `['Status' => Success(), 'Results' => list<['op' => string, 'cid' => string, 'id' => int, 'schedule' => array|null]>]`, one result per op in order (`schedule` is null for a remove). Failure: `['Status' => <error>, 'FailedIndex' => int]` with nothing applied; `FailedIndex` is -1 when the failure is not about one op.
  - Op shapes: `['op' => 'add', 'cid' => string, 'item' => array]`, `['op' => 'update', 'id' => int, 'fields' => array]`, `['op' => 'remove', 'id' => int]`. Item and field keys: `Title`, `StartTime`, `EndTime`, `Location`, `Description`, `Category`, `SecondaryCategory`, `Leads` (`list<['MundaneId' => int]>`), `Menu`, `Cost`, `Dietary`, `Allergens`.
  - `EventPlanning::GetScheduleForManage(array $request): array` returning `['Status' => Success(), 'ScheduleList' => …, 'StaffList' => …]`; requires the schedule capability.
  - `Model_EventPlanning::schedule_batch(array $request): array` and `schedule_list_for_manage(string $token, int $eventId, int $detailId): array`.
  - `POST EventAjax/schedule_batch/{eventId}/{detailId}` with form field `Ops` (JSON). Responses: `{"status":0,"results":[{"op","cid","id","schedule"}]}` or `{"status":N,"error":"…","failedIndex":i}` using the existing status numbers (1 validation, 3 no authorisation, 5 not logged in).
  - `GET EventAjax/schedule_list/{eventId}/{detailId}` returning `{"status":0,"schedule":[…],"staff":[…]}`.
  - A `schedule` row has exactly the keys `fetchScheduleList` returns.

Rules the code must follow:
- The caller needs `CanManageEventDetail(…, 'schedule')` or `AUTH_EVENT`/`AUTH_EDIT`; otherwise `NoAuthorization()` with `FailedIndex` -1. More than 200 ops: `InvalidParameter('Too many changes in one request.')`. An empty list succeeds with no results.
- Everything runs between `BeginTrans()` and `CommitTrans()`; the first failing op calls `RollbackTrans()` and returns with its index. Every write uses `ExecuteChecked`; a false return is a failure (`ProcessingError('Could not save the schedule.')`).
- **add:** title required (`'A title is required.'`); start and end required and parseable (`'Start and end times are required.'`, `'Invalid time format.'`); end not before start (`'End time cannot be before start time.'`); unknown `Category` becomes `Other`, unknown `SecondaryCategory` becomes empty.
- **update:** the row must exist on this occurrence (`'Schedule item not found.'`). Only keys present in `fields` change. A present `Title` must be non-empty. When only one of start/end is present the other comes from the stored row for the order check. `Leads`, when present, replaces the item's leads.
- **remove:** the row must exist on this occurrence; its leads go with it.
- Meal fields (`Menu`, `Cost`, `Dietary`, `Allergens`) are written only when the caller has the feast capability (`staffScheduleCaps(...)['can_feast']`, or event edit authority); otherwise they are ignored on add and left untouched on update.
- Ownership gaps in the existing paths: in `saveEventSchedule`'s update branch, confirm the schedule id belongs to `$detailId` before the `UPDATE`, the meal-field read and the lead delete/insert, returning `InvalidParameter('Schedule item not found.')` when it does not.
- The existing `AddEventSchedule`, `UpdateEventSchedule` and `RemoveEventSchedule` keep their request and response shapes and their full-replace behaviour.

- [ ] **Step 1: Add fixture helpers** to `tests/Support/EventPlanningFixture.php`: `fetchScheduleRow(int $scheduleId): ?array` (assoc row with keys `title`, `start_time`, `end_time`, `location`, `description`, `category`, `secondary_category`, `menu`, `cost`, `dietary`, `allergens`, `event_calendardetail_id`) and `countSchedule(int $detailId): int`.

- [ ] **Step 2: Write the failing tests** in `tests/Integration/EventPlanningTest.php`. Shared helpers first:

```php
/** @return array{0: array, 1: string} event context and a token with full event authority */
private function batchContext(string $tag): array
{
    $ctx = $this->fixture->createPublishedEvent($tag);
    $grantor = $this->fixture->createGrantorWithAuth(AUTH_PARK, $ctx['park_id'], AUTH_CREATE, $tag . '-admin');
    return [$ctx, $grantor['token']];
}

private function batch(array $ctx, string $token, array $ops): array
{
    unset($_SESSION['is_authorized_mundane_id']);
    return $this->planning->ApplyScheduleOps([
        'Token' => $token, 'EventId' => $ctx['event_id'],
        'EventCalendarDetailId' => $ctx['detail_id'], 'Ops' => $ops,
    ]);
}

private function batchDay(): string
{
    return date('Y-m-d', strtotime('+7 days'));
}
```

```php
public function testBatchAddUpdateRemove(): void
{
    [$ctx, $token] = $this->batchContext('batch-crud');
    $d = $this->batchDay();
    $r = $this->batch($ctx, $token, [
        ['op' => 'add', 'cid' => 'c1', 'item' => ['Title' => 'Mothman', 'StartTime' => "$d 12:00", 'EndTime' => "$d 12:45", 'Category' => 'Battlegame']],
        ['op' => 'add', 'cid' => 'c2', 'item' => ['Title' => 'Site Tour', 'StartTime' => "$d 14:00", 'EndTime' => "$d 14:30", 'Category' => 'Jousting']],
    ]);
    $this->assertSame(0, $r['Status']['Status']);
    $this->assertCount(2, $r['Results']);
    $this->assertSame(['add', 'c1'], [$r['Results'][0]['op'], $r['Results'][0]['cid']]);
    $id1 = (int) $r['Results'][0]['id'];
    $id2 = (int) $r['Results'][1]['id'];
    $this->assertGreaterThan(0, $id1);
    $this->assertNotSame($id1, $id2);
    $this->assertSame('Mothman', $r['Results'][0]['schedule']['Title']);
    $this->assertSame("$d 12:00:00", $r['Results'][0]['schedule']['StartTime']);
    $this->assertSame('Other', $r['Results'][1]['schedule']['Category']);

    $r2 = $this->batch($ctx, $token, [
        ['op' => 'update', 'id' => $id1, 'fields' => ['StartTime' => "$d 13:00", 'EndTime' => "$d 13:45"]],
        ['op' => 'remove', 'id' => $id2],
    ]);
    $this->assertSame(0, $r2['Status']['Status']);
    $this->assertSame('Mothman', $r2['Results'][0]['schedule']['Title']);
    $this->assertSame("$d 13:00:00", $r2['Results'][0]['schedule']['StartTime']);
    $this->assertNull($r2['Results'][1]['schedule']);
    $this->assertFalse($this->fixture->scheduleExists($id2, $ctx['detail_id']));
}

public function testBatchRollsBackOnAFailingOp(): void
{
    [$ctx, $token] = $this->batchContext('batch-rollback');
    $d = $this->batchDay();
    $r = $this->batch($ctx, $token, [
        ['op' => 'add', 'cid' => 'c1', 'item' => ['Title' => 'Should not persist', 'StartTime' => "$d 09:00", 'EndTime' => "$d 10:00"]],
        ['op' => 'update', 'id' => 999999999, 'fields' => ['Title' => 'Ghost']],
    ]);
    $this->assertNotSame(0, $r['Status']['Status']);
    $this->assertSame(1, $r['FailedIndex']);
    $this->assertSame(0, $this->fixture->countSchedule($ctx['detail_id']));
}

public function testBatchPartialUpdateLeavesOtherFieldsMealFieldsAndLeads(): void
{
    [$ctx, $token] = $this->batchContext('batch-partial');
    $d = $this->batchDay();
    $leadId = $this->fixture->createPlayer('batch-lead');
    $r = $this->batch($ctx, $token, [['op' => 'add', 'cid' => 'c1', 'item' => [
        'Title' => 'Feast', 'StartTime' => "$d 17:00", 'EndTime' => "$d 18:00", 'Category' => 'Feast and Food',
        'Location' => 'Hall', 'Description' => 'Bring a bowl', 'Menu' => 'Potato bar', 'Cost' => '8',
        'Dietary' => 'Vegan Option', 'Allergens' => 'Dairy', 'Leads' => [['MundaneId' => $leadId]],
    ]]]);
    $id = (int) $r['Results'][0]['id'];
    $r2 = $this->batch($ctx, $token, [['op' => 'update', 'id' => $id, 'fields' => ['StartTime' => "$d 17:30", 'EndTime' => "$d 18:30"]]]);
    $this->assertSame(0, $r2['Status']['Status']);
    $row = $this->fixture->fetchScheduleRow($id);
    $this->assertSame(['Feast', 'Hall', 'Bring a bowl', 'Potato bar', 'Vegan Option', 'Dairy'],
        [$row['title'], $row['location'], $row['description'], $row['menu'], $row['dietary'], $row['allergens']]);
    $this->assertEqualsWithDelta(8.0, (float) $row['cost'], 0.001);
    $this->assertSame("$d 17:30:00", $row['start_time']);
    $this->assertSame(1, $this->fixture->countScheduleLeads($id));
    $this->assertCount(1, $r2['Results'][0]['schedule']['Leads']);
}

public function testBatchUpdateChecksTimesAgainstTheStoredRow(): void
{
    [$ctx, $token] = $this->batchContext('batch-times');
    $d = $this->batchDay();
    $id = (int) $this->batch($ctx, $token, [['op' => 'add', 'cid' => 'c1', 'item' => ['Title' => 'T', 'StartTime' => "$d 12:00", 'EndTime' => "$d 12:45"]]])['Results'][0]['id'];
    $r = $this->batch($ctx, $token, [['op' => 'update', 'id' => $id, 'fields' => ['EndTime' => "$d 11:00"]]]);
    $this->assertNotSame(0, $r['Status']['Status']);
    $this->assertSame(0, $r['FailedIndex']);
    $this->assertSame("$d 12:45:00", $this->fixture->fetchScheduleRow($id)['end_time']);
    $empty = $this->batch($ctx, $token, [['op' => 'update', 'id' => $id, 'fields' => ['Title' => '  ']]]);
    $this->assertNotSame(0, $empty['Status']['Status']);
}

public function testBatchRejectsAnIdFromAnotherOccurrence(): void
{
    [$ctxA, $tokenA] = $this->batchContext('batch-own-a');
    [$ctxB, $tokenB] = $this->batchContext('batch-own-b');
    $d = $this->batchDay();
    $idB = (int) $this->batch($ctxB, $tokenB, [['op' => 'add', 'cid' => 'c1', 'item' => ['Title' => 'Theirs', 'StartTime' => "$d 09:00", 'EndTime' => "$d 10:00"]]])['Results'][0]['id'];
    foreach ([['op' => 'update', 'id' => $idB, 'fields' => ['Title' => 'Mine now']], ['op' => 'remove', 'id' => $idB]] as $op) {
        $r = $this->batch($ctxA, $tokenA, [$op]);
        $this->assertNotSame(0, $r['Status']['Status']);
    }
    $this->assertSame('Theirs', $this->fixture->fetchScheduleRow($idB)['title']);
}

public function testBatchNeedsTheScheduleCapabilityAndCapsItsSize(): void
{
    [$ctx, $token] = $this->batchContext('batch-auth');
    $d = $this->batchDay();
    $op = ['op' => 'add', 'cid' => 'c1', 'item' => ['Title' => 'T', 'StartTime' => "$d 09:00", 'EndTime' => "$d 10:00"]];
    $attOnly = $this->fixture->createGrantorWithoutAuth('batch-att');
    $this->fixture->insertStaff($ctx['detail_id'], $attOnly['mundane_id'], 'Att', canAttendance: true);
    $r = $this->batch($ctx, $attOnly['token'], [$op]);
    $this->assertNotSame(0, $r['Status']['Status']);
    $this->assertSame(-1, $r['FailedIndex']);
    $this->assertSame(0, $this->fixture->countSchedule($ctx['detail_id']));
    $tooMany = $this->batch($ctx, $token, array_fill(0, 201, $op));
    $this->assertNotSame(0, $tooMany['Status']['Status']);
    $this->assertSame(0, $this->fixture->countSchedule($ctx['detail_id']));
    $this->assertSame(0, $this->batch($ctx, $token, [])['Status']['Status']);
}

public function testBatchScheduleOnlyStaffCannotWriteMealFields(): void
{
    [$ctx, $token] = $this->batchContext('batch-meal');
    $d = $this->batchDay();
    $id = (int) $this->batch($ctx, $token, [['op' => 'add', 'cid' => 'c1', 'item' => [
        'Title' => 'Feast', 'StartTime' => "$d 17:00", 'EndTime' => "$d 18:00", 'Category' => 'Feast and Food', 'Menu' => 'Potato bar',
    ]]])['Results'][0]['id'];
    $sched = $this->fixture->createGrantorWithoutAuth('batch-sched');
    $this->fixture->insertStaff($ctx['detail_id'], $sched['mundane_id'], 'Sched', canSchedule: true);
    $r = $this->batch($ctx, $sched['token'], [
        ['op' => 'update', 'id' => $id, 'fields' => ['StartTime' => "$d 17:15", 'EndTime' => "$d 18:15", 'Menu' => 'Overwritten']],
        ['op' => 'add', 'cid' => 'c2', 'item' => ['Title' => 'Lunch', 'StartTime' => "$d 12:00", 'EndTime' => "$d 13:00", 'Category' => 'Feast and Food', 'Menu' => 'Soup']],
    ]);
    $this->assertSame(0, $r['Status']['Status']);
    $this->assertSame('Potato bar', $this->fixture->fetchScheduleRow($id)['menu']);
    $this->assertSame("$d 17:15:00", $this->fixture->fetchScheduleRow($id)['start_time']);
    $this->assertNull($this->fixture->fetchScheduleRow((int) $r['Results'][1]['id'])['menu']);
}

public function testUpdateScheduleCannotTouchLeadsOfAnotherOccurrence(): void
{
    [$ctxA, $tokenA] = $this->batchContext('own-old-a');
    $ctxB = $this->fixture->createPublishedEvent('own-old-b');
    $idB = $this->fixture->insertSchedule($ctxB['detail_id'], 'Theirs');
    $this->fixture->insertScheduleLead($idB, $this->fixture->createPlayer('own-old-lead'));
    unset($_SESSION['is_authorized_mundane_id']);
    $r = $this->planning->UpdateEventSchedule([
        'Token' => $tokenA, 'EventId' => $ctxA['event_id'], 'EventCalendarDetailId' => $ctxA['detail_id'], 'ScheduleId' => $idB,
        'Title' => 'Hijack', 'StartTime' => date('Y-m-d H:i:s', strtotime('+7 days 10:00')),
        'EndTime' => date('Y-m-d H:i:s', strtotime('+7 days 11:00')), 'Category' => 'Other', 'Leads' => [],
    ]);
    $this->assertNotSame(0, $r['Status']['Status']);
    $this->assertSame(1, $this->fixture->countScheduleLeads($idB));
    $this->assertSame('Theirs', $this->fixture->fetchScheduleRow($idB)['title']);
}

public function testGetScheduleForManageNeedsTheCapability(): void
{
    [$ctx, $token] = $this->batchContext('manage-list');
    $this->fixture->insertSchedule($ctx['detail_id'], 'Listed');
    $nobody = $this->fixture->createGrantorWithoutAuth('manage-list-none');
    unset($_SESSION['is_authorized_mundane_id']);
    $denied = $this->planning->GetScheduleForManage(['Token' => $nobody['token'], 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id']]);
    $this->assertNotSame(0, $denied['Status']['Status']);
    $this->assertArrayNotHasKey('StaffList', $denied);
    unset($_SESSION['is_authorized_mundane_id']);
    $ok = $this->planning->GetScheduleForManage(['Token' => $token, 'EventId' => $ctx['event_id'], 'EventCalendarDetailId' => $ctx['detail_id']]);
    $this->assertSame(0, $ok['Status']['Status']);
    $this->assertSame('Listed', $ok['ScheduleList'][0]['Title']);
    $this->assertIsArray($ok['StaffList']);
}
```

If `insertStaff` has no `canSchedule` named parameter, add one in the same style as `canAttendance`.

- [ ] **Step 3: Run the PHP tests.** Expected: the nine new tests fail (undefined method); the existing ones pass.
- [ ] **Step 4: Implement the domain methods and the ownership fixes.** Share the validation and category coercion between `saveEventSchedule` and the batch through small private helpers rather than copying the block. Re-read rows for results with one private `fetchScheduleRowForResult(int $scheduleId, int $detailId): ?array` that returns the `fetchScheduleList` shape.
- [ ] **Step 5: Run the PHP tests.** Expected: all pass.
- [ ] **Step 6: Add the model methods and the two controller actions**, following `update_schedule`'s structure (login check, id parsing, `emit_json` for errors). `schedule_batch` decodes `$_POST['Ops']` with `json_decode(..., true)`; a value that is not a list is `{"status":1,"error":"Invalid request."}`. On failure include `failedIndex`.
- [ ] **Step 7: Verify the endpoints refuse a signed-out caller:** `curl -s -X POST 'http://127.0.0.1:19081/orkui/index.php?Route=EventAjax/schedule_batch/1/1' -d 'Ops=[]'` and the `schedule_list` GET both return `{"status":5,…}`. `php -l` each changed PHP file.
- [ ] **Step 8: Commit:** `Schedule board: transactional batch save with partial updates`.

---

### Task 2: The client store

**Files:**
- Create: `orkui/template/revised-frontend/script/event-board/evb-store.js`
- Create: `tests/js/evb-store.test.js`

**Interfaces:**
- Consumes: the wire format of Task 1.
- Produces `EvBoardStore` (`module.exports` in node, `window.EvBoardStore` in the browser; no DOM):
  - `create({items, send, saveMeal}) -> store`. `items` are server rows. `send(wireOps) -> Promise<{status, results, error, failedIndex}>`; a rejected promise means the server could not be reached. `saveMeal` (boolean) says whether meal fields are sent.
  - `store.items() -> Array` (each a plain object with the server keys plus `cid`; `EventScheduleId` is 0 until saved). Loaded items get `cid` `'i' + EventScheduleId`; new ones `'n1'`, `'n2'`, ….
  - `store.get(cid)`, `store.add(item, opts) -> cid`, `store.update(cid, fields, opts)`, `store.remove(cid, opts)`. Each is one undo step unless `opts.undoable === false`.
  - `store.batch(fn)`: every add/update/remove made inside `fn` is one undo step.
  - `store.undo() -> boolean`, `store.redo() -> boolean`, `store.canUndo()`, `store.canRedo()`.
  - `store.status() -> {state: 'saved' | 'saving' | 'offline', pending: number}`; `store.retry()`; `store.idle() -> Promise<void>` (resolves when nothing is queued or in flight, and immediately while offline).
  - `store.reset(serverItems) -> boolean` (replaces everything and clears undo; refuses and returns false unless the state is `saved`).
  - `store.on(type, fn)` for `'change'`, `'status'` and `'rejected'` (`{kind, cid, item, message}`, where `item` is the item as the user last had it).
- Wire fields: `Title`, `StartTime`, `EndTime`, `Location`, `Description`, `Category`, `SecondaryCategory`, `Leads` (as `[{MundaneId}]`), and, only when `saveMeal`, `Menu`, `Cost`, `Dietary`, `Allergens`.

Queue rules:
- One request in flight at a time; when it settles, everything queued (at most 200 ops) goes in the next request.
- A change to an item whose add is still queued and unsent is folded into that add. A second update to an item with an unsent update merges fields. Removing an item whose add is unsent drops both. Removing an item with an unsent update drops the update and queues the remove.
- An update or remove is given its server id when the request is built, so a change made while that item's add is in flight goes in the next request with the real id.
- Success: adds take their ids; an item with nothing further queued takes the server's returned row (the server may have changed a category or trimmed text).
- `send` rejects, or resolves with status 5, or with a non-zero status and no usable `failedIndex`: state becomes `offline`, the in-flight ops return to the front of the queue, nothing is reverted. `retry()` sends again.
- `send` resolves with status 1 or 3 and a `failedIndex`: that op is rejected. The item returns to its last saved state (a rejected add disappears; a rejected remove reappears), queued ops for the same item are dropped, undo and redo steps that touch it are dropped, `'rejected'` fires, and the other in-flight ops are sent again with the rest of the queue.

- [ ] **Step 1: Write the failing tests** in `tests/js/evb-store.test.js`. Use this fake server:

```js
const S = require('../../orkui/template/revised-frontend/script/event-board/evb-store.js');
function fake() {
  const calls = []; let nextId = 100; let gate = null; let failNext = null; let badTitle = null;
  const send = ops => {
    calls.push(JSON.parse(JSON.stringify(ops)));
    const run = () => {
      if (failNext) { const f = failNext; failNext = null; if (f === 'network') return Promise.reject(new Error('down')); return Promise.resolve(f); }
      const bad = badTitle ? ops.findIndex(o => o.op === 'add' && o.item.Title === badTitle.title) : -1;
      if (bad >= 0) return Promise.resolve({ status: 1, error: badTitle.message, failedIndex: bad });
      return Promise.resolve({ status: 0, results: ops.map(o => ({ op: o.op, cid: o.cid || '', id: o.op === 'add' ? nextId++ : o.id,
        schedule: o.op === 'remove' ? null : Object.assign({ EventScheduleId: o.op === 'add' ? nextId - 1 : o.id }, o.item || o.fields) })) });
    };
    if (gate) { const g = gate; return new Promise(res => g.push(res)).then(run); }
    return run();
  };
  return { send, calls, hold() { gate = []; }, release() { const g = gate; gate = null; g.forEach(r => r()); }, failWith(f) { failNext = f; },
    rejectTitle(title, message) { badTitle = { title, message }; } };
}
const item = (title, more) => Object.assign({ Title: title, StartTime: '2026-11-21 09:00', EndTime: '2026-11-21 10:00', Category: 'Other', Leads: [] }, more);
const tick = () => new Promise(r => setImmediate(r));
```

```js
test('loaded and new items get client ids; an add is saved and takes its server id', async () => {
  const f = fake();
  const s = S.create({ items: [item('Old', { EventScheduleId: 7 })], send: f.send, saveMeal: true });
  assert.equal(s.items()[0].cid, 'i7');
  const cid = s.add(item('Mothman'));
  assert.equal(s.get(cid).EventScheduleId, 0);
  assert.deepEqual(s.status(), { state: 'saving', pending: 1 });
  await s.idle();
  assert.equal(s.get(cid).EventScheduleId, 100);
  assert.deepEqual(s.status(), { state: 'saved', pending: 0 });
  assert.deepEqual(f.calls[0].map(o => [o.op, o.cid, o.item.Title]), [['add', cid, 'Mothman']]);
  assert.equal(f.calls[0][0].item.cid, undefined);
});
test('edits made while a save is in flight are merged and sent once with the real id', async () => {
  const f = fake(); f.hold();
  const s = S.create({ items: [], send: f.send, saveMeal: true });
  const cid = s.add(item('Mothman'));
  await tick();
  s.update(cid, { StartTime: '2026-11-21 10:00' });
  s.update(cid, { StartTime: '2026-11-21 11:00', EndTime: '2026-11-21 12:00' });
  f.release(); await s.idle();
  assert.equal(f.calls.length, 2);
  assert.deepEqual(f.calls[1], [{ op: 'update', id: 100, fields: { StartTime: '2026-11-21 11:00', EndTime: '2026-11-21 12:00' } }]);
  assert.equal(s.get(cid).StartTime, '2026-11-21 11:00');
});
test('changes to an unsent add fold into it; removing an unsent add sends nothing', async () => {
  const f = fake(); f.hold();
  const s = S.create({ items: [item('Old', { EventScheduleId: 7 })], send: f.send, saveMeal: true });
  s.update('i7', { Title: 'Older' });
  await tick();
  const a = s.add(item('A')); s.update(a, { Title: 'A2' });
  const b = s.add(item('B')); s.remove(b);
  s.update('i7', { Title: 'Oldest' }); s.remove('i7');
  f.release(); await s.idle();
  assert.deepEqual(f.calls[1].map(o => [o.op, o.id || o.item.Title]), [['add', 'A2'], ['remove', 7]]);
  assert.deepEqual(s.items().map(i => i.Title), ['A2']);
});
test('undo and redo', async () => {
  const f = fake();
  const s = S.create({ items: [item('Old', { EventScheduleId: 7, Location: 'Hall' })], send: f.send, saveMeal: true });
  s.update('i7', { Location: 'Field' });
  assert.ok(s.canUndo()); assert.ok(!s.canRedo());
  assert.ok(s.undo()); assert.equal(s.get('i7').Location, 'Hall');
  assert.ok(s.redo()); assert.equal(s.get('i7').Location, 'Field');
  s.remove('i7'); assert.equal(s.get('i7'), null);
  s.undo(); await s.idle();
  assert.equal(s.get('i7').Title, 'Old');
  assert.notEqual(s.get('i7').EventScheduleId, 7);
  assert.ok(f.calls.flat().some(o => o.op === 'remove' && o.id === 7));
  assert.ok(f.calls.flat().some(o => o.op === 'add' && o.item.Title === 'Old'));
  const cid = s.add(item('New')); s.undo();
  assert.equal(s.get(cid), null);
  while (s.canUndo()) s.undo();
  assert.equal(s.undo(), false);
  await s.idle();
});
test('batch is one undo step; a new change clears redo; undoable:false records nothing', async () => {
  const f = fake();
  const s = S.create({ items: [item('Old', { EventScheduleId: 7 })], send: f.send, saveMeal: true });
  s.batch(() => { s.add(item('A')); s.add(item('B')); s.update('i7', { Title: 'Renamed' }); });
  assert.equal(s.items().length, 3);
  s.undo();
  assert.deepEqual(s.items().map(i => i.Title), ['Old']);
  s.redo(); s.undo(); s.add(item('C'));
  assert.ok(!s.canRedo());
  s.undo();
  s.remove('i7', { undoable: false });
  assert.ok(!s.canUndo());
  await s.idle();
});
test('when the server cannot be reached nothing is lost and retry sends once, in order', async () => {
  const f = fake(); f.failWith('network');
  const s = S.create({ items: [], send: f.send, saveMeal: true });
  const a = s.add(item('A'));
  await tick(); await tick();
  assert.equal(s.status().state, 'offline');
  const b = s.add(item('B'));
  assert.equal(s.items().length, 2);
  s.retry(); await s.idle();
  assert.deepEqual(f.calls[f.calls.length - 1].map(o => o.item.Title), ['A', 'B']);
  assert.ok(s.get(a).EventScheduleId > 0 && s.get(b).EventScheduleId > 0);
  assert.equal(s.status().state, 'saved');
});
test('a change the server rejects is reverted, reported, and does not block the rest', async () => {
  const f = fake();
  const s = S.create({ items: [item('Old', { EventScheduleId: 7 })], send: f.send, saveMeal: true });
  const rejected = []; s.on('rejected', r => rejected.push(r));
  f.rejectTitle('Bad', 'A title is required.');
  s.update('i7', { Title: 'First' });
  const bad = s.add(item('Bad')); const good = s.add(item('Good'));
  await s.idle();
  assert.equal(s.get('i7').Title, 'First');
  assert.equal(s.get(bad), null);
  assert.ok(s.get(good).EventScheduleId > 0);
  assert.deepEqual(rejected.map(r => [r.kind, r.item.Title, r.message]), [['add', 'Bad', 'A title is required.']]);
  assert.equal(s.status().state, 'saved');
  s.undo();
  assert.equal(s.get(good), null);
});
test('a rejected update restores the saved values; meal fields are sent only when allowed; reset refuses while busy', async () => {
  const f = fake();
  const s = S.create({ items: [item('Old', { EventScheduleId: 7, Menu: 'Soup' })], send: f.send, saveMeal: false });
  f.failWith({ status: 3, error: 'No authorization.', failedIndex: 0 });
  s.update('i7', { Title: 'Changed', Menu: 'Stew' });
  assert.equal(s.reset([]), false);
  await s.idle();
  assert.equal(s.get('i7').Title, 'Old');
  assert.equal(f.calls[0][0].fields.Menu, undefined);
  assert.equal(s.reset([item('Fresh', { EventScheduleId: 9 })]), true);
  assert.deepEqual(s.items().map(i => i.cid), ['i9']);
  assert.ok(!s.canUndo());
});
```

- [ ] **Step 2: Run `npm run test:js`.** Expected: the new file fails.
- [ ] **Step 3: Implement `evb-store.js`** with the same UMD wrapper as `evb-core.js`. Keep a `saved` map (cid to the last server-confirmed copy, absent for an unsaved add) for reverts.
- [ ] **Step 4: Run `npm run test:js`.** Expected: all pass. If the fake server's timing makes a test depend on microtask order, fix the store to be order-independent rather than adding ticks to the test.
- [ ] **Step 5: Commit:** `Schedule board: client store with undo and a save queue`.

---

### Task 3: The entry grammar

**Files:**
- Modify: `orkui/template/revised-frontend/script/event-board/evb-core.js`
- Create: `tests/js/evb-core.parse.test.js`

**Interfaces:**
- Consumes: `parseNaive`, `toNaive`, `addDays`, `fmtRange`, `BOARD_DAY_START` (Phase 1).
- Produces:
  - `CATEGORY_NAMES`: the ten names in order (Administrative, Tournament, Battlegame, Arts and Sciences, Class, Feast and Food, Court, Meeting, Roleplay and Quests, Other).
  - `resolveCategoryTag(tag) -> string | null`.
  - `guessCategory(title) -> {category: string | null, title: string}`. `title` comes back with a recognised category prefix removed and is otherwise unchanged.
  - `parseEntry(text, ctx) -> result`.
  - `formatEntry(item, opts) -> string` (`opts.withDay` prefixes the three-letter weekday).
- `ctx`: `{days: [boardDay…], day, lastEnd: {day, min} | null, lastDuration, fixedDay, fixedStart, fixedEnd, canFeast, locations: [string…], ignore: {day, time, category, location, description, cost}}`. Defaults: `day = days[0]`, `lastDuration = 60`, everything else null, false or empty.
- `result`: `{ok, setDay, day, startMin, endMin, start, end, title, category, secondaryCategory, location, description, cost, given: {day, time, category, location, description, cost}}`. `start`/`end` are naive `'YYYY-MM-DD HH:MM'`. `given.x` is true only when the user typed that part. `ok` is false when there is no title. `setDay` is a board day when the whole line is a day name (with an optional colon), otherwise null. `category` is never null in a result (falls back to `'Other'`); `secondaryCategory`, `location` and `description` are `''` when absent; `cost` is a number or null.

Rules (spec 7.4 and 8.3), all case-insensitive:
- **Day:** only the first token: a weekday name or its three-letter form, or `M/D`. It resolves to the first of `ctx.days` with that weekday, else the first such date after `ctx.days[0]`. Skipped when `ctx.fixedDay` is set or `ctx.ignore.day`.
- **Time:** the first of these found. Ranges `A-B`, `A–B`, `A to B` where each side is `H`, `H:MM`, with optional `a`/`am`/`p`/`pm`, or `noon`/`midnight`. A single time needs a colon or a marker (or `noon`/`midnight`). Lengths: `45m`, `1h`, `1.5h`, `1h30m`, `+45`. `then`. A bare-number range (no colon, no marker on either side) counts only as the first token after the day or as the last token.
- **am/pm:** written markers win. With none: 7 to 11 is morning, 12 and 1 to 6 are afternoon. A side without a marker beside a side with one takes the same marker unless that would put the start at or after the end, in which case the other. An end still not after the start moves 12 hours later if it has no marker, then to the next day. Hours 0:00 to 4:59 belong to the night of the board day (add 1440).
- **Defaults:** no time at all → `fixedStart`/`fixedEnd` when set, otherwise start at `lastEnd` (on its day) or 9:00 on `ctx.day`, for `lastDuration`. A start with no end → start plus the block's current length when `fixedStart`/`fixedEnd` are set, otherwise `lastDuration`. A length alone, or `then` → start at `lastEnd`.
- **`#tag`:** the first resolvable tag is the category, the second the secondary category; an unresolvable `#word` stays in the title.
- **Location:** the last standalone word `at` that is not followed by a time, plus the one to six words after it, up to the end of the line or the next `#`, `"` or `$` token. If it equals an entry of `ctx.locations` ignoring case, that entry's spelling is used.
- **Description:** the last double-quoted span that has title text before it (straight or curly quotes); an opening quote with no close takes the rest of the line.
- **Cost:** `$8` or `$8.50`, only when `ctx.canFeast` and the resulting category or secondary category is Feast and Food.
- **Title:** what is left, trimmed, inner whitespace collapsed. Then `guessCategory` runs on it unless a `#tag` set the category.
- **Prefixes** (`guessCategory`): a leading category name or tag alias followed by `:` sets the category and is removed. Aliases per category: Administrative (`admin`), Tournament (`tourney`), Battlegame (`bg`, `battle game`), Arts and Sciences (`as`, `a&s`), Class, Feast and Food (`feast`, `food`), Court, Meeting, Roleplay and Quests (`rp`, `roleplay`, `quest`), Other. A tag also resolves by any unique prefix of a category name.
- **Keywords**, first match wins, whole words with an optional trailing `s`: Arts and Sciences (a&s, arts and sciences, judging, dragonmaster); Feast and Food (feast, dinner, lunch, breakfast, brunch, potluck, tavern); Court (court, coronation, knighting, ceremony, oath); Tournament (tournament, tourney, warmaster, weaponmaster, bracket); Class (class, workshop, seminar); Roleplay and Quests (quest, rp, roleplay, role play); Battlegame (battlegame, battle game, bg, militia, ditch); Meeting (meeting, althing, allthing); Administrative (troll, gate, setup, set up, clean, cleanup, teardown, tear down, check-in, checkin, offsite, crat).
- **`formatEntry`:** `<fmtRange of the item's board-day minutes> [#tag ]<Title>[ at <Location>][ "<Description>"]`, with the tag only when `guessCategory(Title).category` differs from the item's category. Canonical tags: `#admin`, `#tourney`, `#bg`, `#as`, `#class`, `#feast`, `#court`, `#meeting`, `#rp`, `#other`.

- [ ] **Step 1: Write the failing tests** in `tests/js/evb-core.parse.test.js`:

```js
const DAYS = ['2026-11-20', '2026-11-21', '2026-11-22'];
const ctx = more => Object.assign({ days: DAYS, day: '2026-11-21', lastEnd: null, lastDuration: 60 }, more);
const p = (text, more) => C.parseEntry(text, ctx(more));
const pick = (r, keys) => keys.map(k => r[k]);

test('the examples from the design', () => {
  let r = p('sat 9a tavern opens');
  assert.deepEqual(pick(r, ['ok', 'day', 'startMin', 'endMin', 'title', 'category']), [true, '2026-11-21', 540, 600, 'tavern opens', 'Feast and Food']);
  assert.deepEqual([r.given.day, r.given.time, r.given.category], [true, true, false]);
  assert.deepEqual([r.start, r.end], ['2026-11-21 09:00', '2026-11-21 10:00']);
  r = p('9:30-10:15 battlegame: squatch ball at main field', { locations: ['Main Field'] });
  assert.deepEqual(pick(r, ['startMin', 'endMin', 'title', 'category', 'location']), [570, 615, 'squatch ball', 'Battlegame', 'Main Field']);
  assert.equal(p('9:30-10:15 bg: squatch ball at main field').location, 'main field');
  r = p('then 30m rp quest starts', { lastEnd: { day: '2026-11-21', min: 615 } });
  assert.deepEqual(pick(r, ['day', 'startMin', 'endMin', 'title', 'category']), ['2026-11-21', 615, 645, 'rp quest starts', 'Roleplay and Quests']);
  r = p('Friday Battlegame 3:00-4:00 at Main Field');
  assert.deepEqual(pick(r, ['day', 'startMin', 'endMin', 'title', 'category', 'location']), ['2026-11-20', 900, 960, 'Battlegame', 'Battlegame', 'Main Field']);
  r = p('3-4 Naalbinding Class "Come learn the Viking art of Naalbinding."');
  assert.deepEqual(pick(r, ['startMin', 'endMin', 'title', 'category', 'description']), [900, 960, 'Naalbinding Class', 'Class', 'Come learn the Viking art of Naalbinding.']);
});
test('am and pm', () => {
  const t = s => pick(p(s + ' x'), ['startMin', 'endMin']);
  assert.deepEqual(t('9-12'), [540, 720]);
  assert.deepEqual(t('10-1'), [600, 780]);
  assert.deepEqual(t('3-4'), [900, 960]);
  assert.deepEqual(t('10-1p'), [600, 780]);
  assert.deepEqual(t('8-9p'), [1200, 1260]);
  assert.deepEqual(t('11-12p'), [660, 720]);
  assert.deepEqual(t('7p-9'), [1140, 1260]);
  assert.deepEqual(t('9a-12p'), [540, 720]);
  assert.deepEqual(t('3p-4:30p'), [900, 990]);
  assert.deepEqual(t('10 to 1'), [600, 780]);
  assert.deepEqual(t('10p-1a'), [1320, 1500]);
  assert.deepEqual(t('15:00'), [900, 960]);
  assert.deepEqual(t('noon'), [720, 780]);
  assert.deepEqual(t('3p'), [900, 960]);
});
test('after midnight belongs to the named day’s night', () => {
  const r = p('sat 12:30a night quest');
  assert.deepEqual(pick(r, ['day', 'startMin', 'endMin', 'start', 'end', 'category']),
    ['2026-11-21', 1470, 1530, '2026-11-22 00:30', '2026-11-22 01:30', 'Roleplay and Quests']);
});
test('lengths, then, and defaults', () => {
  const last = { lastEnd: { day: '2026-11-20', min: 870 }, lastDuration: 45 };
  assert.deepEqual(pick(p('45m mystery', last), ['day', 'startMin', 'endMin']), ['2026-11-20', 870, 915]);
  assert.deepEqual(pick(p('1h30m mystery', last), ['startMin', 'endMin']), [870, 960]);
  assert.deepEqual(pick(p('1.5h mystery', last), ['startMin', 'endMin']), [870, 960]);
  assert.deepEqual(pick(p('+45 mystery', last), ['startMin', 'endMin']), [870, 915]);
  assert.deepEqual(pick(p('mystery', last), ['day', 'startMin', 'endMin']), ['2026-11-20', 870, 915]);
  assert.deepEqual(pick(p('2p mystery', last), ['day', 'startMin', 'endMin']), ['2026-11-21', 840, 885]);
  assert.deepEqual(pick(p('opening'), ['day', 'startMin', 'endMin']), ['2026-11-21', 540, 600]);
  assert.equal(p('mystery', last).given.time, false);
});
test('days', () => {
  assert.equal(p('sun 9a clean').day, '2026-11-22');
  assert.equal(p('11/22 9a clean').day, '2026-11-22');
  assert.equal(p('mon 9a clean').day, '2026-11-23');
  assert.deepEqual(pick(p('Saturday:'), ['ok', 'setDay']), [false, '2026-11-21']);
  assert.deepEqual(pick(p('fri'), ['ok', 'setDay']), [false, '2026-11-20']);
  assert.deepEqual(pick(p('sat 9a'), ['ok', 'setDay']), [false, null]);
});
test('inside a block the day is fixed and the block keeps its length', () => {
  const blk = { fixedDay: '2026-11-21', fixedStart: 720, fixedEnd: 765 };
  assert.deepEqual(pick(p('sunday brunch', blk), ['day', 'startMin', 'endMin', 'title', 'category']), ['2026-11-21', 720, 765, 'sunday brunch', 'Feast and Food']);
  assert.deepEqual(pick(p('1p Mothman', blk), ['startMin', 'endMin', 'title']), [780, 825, 'Mothman']);
  assert.deepEqual(pick(p('1-3 Mothman', blk), ['startMin', 'endMin']), [780, 900]);
});
test('words that only look like grammar stay in the title', () => {
  let r = p('Level 1-3 Tournament');
  assert.deepEqual([r.title, r.given.time, r.category], ['Level 1-3 Tournament', false, 'Tournament']);
  r = p('Sir Adler: Train the Trainer Pt3');
  assert.deepEqual([r.title, r.category, r.given.category], ['Sir Adler: Train the Trainer Pt3', 'Other', false]);
  assert.equal(p('KL Tournament: Single Short').title, 'KL Tournament: Single Short');
  assert.equal(p('#1 Draft Pick').title, '#1 Draft Pick');
  assert.equal(p('5 pumpkins').title, '5 pumpkins');
  r = p('Court at 6pm');
  assert.deepEqual([r.title, r.location, r.startMin], ['Court', '', 1080]);
  r = p('feast $8');
  assert.deepEqual([r.title, r.cost], ['feast $8', null]);
});
test('tags, prefixes, location, description and cost', () => {
  let r = p('#bg Mothman');
  assert.deepEqual([r.title, r.category, r.given.category], ['Mothman', 'Battlegame', true]);
  r = p('#class #feast Cooking');
  assert.deepEqual([r.title, r.category, r.secondaryCategory], ['Cooking', 'Class', 'Feast and Food']);
  assert.equal(p('#tourn Finals').category, 'Tournament');
  r = p('CLASS: Sir Adler - Train the Trainer Pt3');
  assert.deepEqual([r.title, r.category], ['Sir Adler - Train the Trainer Pt3', 'Class']);
  r = p('Meet at the Gate 5p');
  assert.deepEqual([r.title, r.location, r.startMin], ['Meet', 'the Gate', 1020]);
  r = p('Meet at the Gate 5p', { ignore: { location: true } });
  assert.deepEqual([r.title, r.location, r.category], ['Meet at the Gate', '', 'Administrative']);
  r = p('Naalbinding Class “Come learn');
  assert.deepEqual([r.title, r.description, r.given.description], ['Naalbinding Class', 'Come learn', true]);
  r = p('5-6 feast $8.50 at Hall', { canFeast: true });
  assert.deepEqual([r.title, r.cost, r.location, r.category], ['feast', 8.5, 'Hall', 'Feast and Food']);
  assert.equal(p('5-6 mothman $8', { canFeast: true }).cost, null);
});
test('guessCategory follows the owner’s rulings', () => {
  const g = t => C.guessCategory(t).category;
  assert.equal(g('Ditch'), 'Battlegame');
  assert.equal(g('Tavern Opens'), 'Feast and Food');
  assert.equal(g('Troll Opens'), 'Administrative');
  assert.equal(g('RP Quest Starts'), 'Roleplay and Quests');
  assert.equal(g('Night Quest!'), 'Roleplay and Quests');
  assert.equal(g('A&S Judging Begins'), 'Arts and Sciences');
  assert.equal(g('Court and the Knighting of Nip'), 'Court');
  assert.equal(g('Ceremony of Oaths'), 'Court');
  assert.equal(g('Crats on site ONLY'), 'Administrative');
  assert.equal(g("Cadellia's Vigil"), null);
  assert.equal(g('Classic Car Show'), null);
  assert.deepEqual(C.guessCategory('BATTLEGAME: Mothman'), { category: 'Battlegame', title: 'Mothman' });
  assert.equal(C.resolveCategoryTag('rp'), 'Roleplay and Quests');
  assert.equal(C.resolveCategoryTag('nope'), null);
});
test('formatEntry reads back through parseEntry', () => {
  const items = [
    { Title: 'Squatch Ball', Category: 'Battlegame', StartTime: '2026-11-21 09:30:00', EndTime: '2026-11-21 10:15:00', Location: 'Main Field', Description: 'Bring water' },
    { Title: 'Night Quest!', Category: 'Roleplay and Quests', StartTime: '2026-11-22 00:30:00', EndTime: '2026-11-22 01:30:00', Location: '', Description: '' },
    { Title: 'Lunch', Category: 'Feast and Food', StartTime: '2026-11-21 12:00:00', EndTime: '2026-11-21 13:00:00', Location: '', Description: '' },
  ];
  assert.equal(C.formatEntry(items[0]), '9:30–10:15a #bg Squatch Ball at Main Field "Bring water"');
  assert.equal(C.formatEntry(items[2]), '12–1p Lunch');
  assert.equal(C.formatEntry(items[0], { withDay: true }).slice(0, 4), 'sat ');
  items.forEach(it => {
    const r = C.parseEntry(C.formatEntry(it, { withDay: true }), { days: DAYS });
    assert.deepEqual([r.title, r.category, r.location, r.description, r.start + ':00', r.end + ':00'],
      [it.Title, it.Category, it.Location, it.Description, it.StartTime, it.EndTime]);
  });
});
```

- [ ] **Step 2: Run `npm run test:js`.** Expected: the new file fails.
- [ ] **Step 3: Implement.** Tokenise once, mark the spans each rule claims, and build the title from the unclaimed text; do not chain regular-expression replacements on the raw string, because later rules must not see text an earlier rule already claimed.
- [ ] **Step 4: Run `npm run test:js`.** Expected: all pass.
- [ ] **Step 5: Commit:** `Schedule board: entry grammar`.

---

### Task 4: Edit mode and the quick line

**Files:**
- Create: `orkui/template/revised-frontend/script/event-board/evb-entry.js`
- Create: `orkui/template/revised-frontend/script/event-board/evb-edit.js`
- Modify: `orkui/template/revised-frontend/script/event-board/evb-view.js` (blocks carry `data-cid`; an editing flag widens the axis; ghost blocks)
- Modify: `orkui/template/revised-frontend/style/event-board.css`
- Modify: `orkui/template/revised-frontend/Eventnew_index.tpl` (the "Edit schedule" button in the schedule toolbar, shown only when `$canManageSchedule`; script includes; the empty-state button)
- Modify: `tests/js/evb-view.html.test.js`, `tests/e2e/event-schedule-board.spec.ts`

**Interfaces:**
- Consumes: `EvBoardStore.create` (Task 2), `EvBoardCore.parseEntry`/`formatEntry` (Task 3), the batch and list endpoints (Task 1), `EvBoardView` (Phase 1).
- Produces:
  - `EvBoardView.setEditing(boolean)` (re-renders with `buildBoard`'s `editing` flag and adds class `evb-editing` to `#evb-root`), `EvBoardView.setGhosts(items)` (draws dashed `.evb-ghost` blocks laid out with the real ones; an empty array clears them), and `blockHtml` emitting `data-cid` when the item has a `cid`.
  - `EvBoardEntry.create(hostEl, opts) -> entry` with `opts = {getContext: () => ctx, onParse(result), onCommit(result) -> boolean, onCancel(), placeholder, compact}`; `entry.focus()`, `entry.setText(text)`, `entry.getText()`, `entry.clear()`, `entry.destroy()`. It renders `.evb-entry` containing `input.evb-entry-input`, `.evb-entry-chips` (one `.evb-chip[data-part]` per understood part; `.evb-chip-guess` when not typed; each with a `button.evb-chip-x`) and `.evb-entry-menu` (the `#` and ` at ` suggestion list, items `.evb-entry-option`).
  - `EvBoardEdit.enter()`, `EvBoardEdit.exit() -> Promise`, `EvBoardEdit.isEditing()`, `EvBoardEdit.store` (null outside Edit mode), `EvBoardEdit.entryContext(overrides) -> ctx` (the parse context built from the store: days, last end, last length, locations, `canFeast`).
  - DOM: `.evb-modebar` with `.evb-save-state`, `button.evb-undo`, `button.evb-redo`, `button.evb-help`, `button.evb-done`; `.evb-quickline`; `.evb-notice` (rejections, with a dismiss button).

Behaviour:
- **Entering:** `enter()` fetches `schedule_list`, creates the store from it, calls `setEditing(true)`, shows the mode bar and quick line, and focuses the quick line. The store's `change` event calls `EvBoardView.setItems(store.items())`.
- **Save state text:** `Saving…` while `saving`; `All changes saved` when `saved`; `Couldn't reach the server` with a Retry button when `offline`.
- **Rejections:** `.evb-notice` reads `Couldn't save "<title>": <message>. It was undone.` A rejected add also puts `formatEntry(item, {withDay: true})` into the quick line.
- **Quick line:** every keystroke parses with `entryContext()`; a valid parse shows one ghost and the chips; Enter with a valid parse calls `store.add` with the parsed fields and clears the input; Enter on a `setDay` line switches the default day and clears the input; Esc clears. The next parse context takes its day, last end and last length from the item just added. Dismissing a chip sets the matching `ignore` flag for this entry and re-parses. `/` focuses the quick line when no field has focus.
- **Suggestions:** typing `#` opens the category list filtered by what follows; typing ` at ` opens the list of locations already in the schedule filtered by what follows (nothing when there are none). Up/Down move, Enter or Tab picks and rewrites that token in the input, Esc closes. While the list is open, Enter does not add the item.
- **Undo/redo:** the buttons and Cmd/Ctrl+Z, Shift+Cmd/Ctrl+Z (when no text field has focus) call the store.
- **Help:** `button.evb-help` toggles `.evb-help-pop`, a short cheat sheet listing the grammar rows of spec 7.4 that exist so far (day, time, length, `then`, `#`, `at`, quotes, `$`) and the keys available in this phase. It closes on outside click and Esc.
- **Refresh:** when the window regains focus and the store is `saved`, re-fetch `schedule_list` and `store.reset`.
- **Leaving:** Done awaits `store.idle()`; if the state is `offline` it stays in Edit mode and says so. Otherwise it re-fetches the page, swaps `#ev-schedule-container`, `#ev-meal-list` and the Schedule and Feast tab counts from the response, hands the board the fresh list, calls `setEditing(false)` and removes the mode bar and quick line. While `pending > 0` or the state is `offline`, a `beforeunload` handler asks before leaving.
- **Empty schedule:** when there are no items and the viewer can manage the schedule, the empty state shows a "Build the schedule" button that switches to Board view and enters Edit mode.
- **Phones:** Edit mode works at 390px: the mode bar wraps, the quick line is full width.

- [ ] **Step 1: Wire the editor sign-in for browser tests.** A dedicated local test account and event already exist in this machine's `ork` database: account `e2e_schedule` (its only authority is a staff row with manage, schedule and feast on the event), event `E2E Schedule Board`, occurrence Friday 2026-11-20 09:00 to Sunday 2026-11-22 16:00. The git-ignored file `.superpowers/e2e.env` holds `ORK3_E2E_USERNAME`, `ORK3_E2E_PASSWORD` and `ORK3_E2E_EDIT_EVENT` (`19190/9680`); load it into the environment for Playwright runs (`set -a; . .superpowers/e2e.env; set +a`) and never print or commit its contents. Sign-in is `POST index.php?Route=Login/login` with form fields `username` and `password` (no CSRF token); success redirects to `Player/profile/{id}`. In the spec, add `signIn(page)` (reuse the helper in the existing specs under `tests/e2e/` if one fits; otherwise fill the login form at `index.php?Route=Login` and wait for the redirect) and `openBoard(page, event)`. Editor tests read the three variables and `test.skip` when any is missing. Do not sign in as any other account.

- [ ] **Step 2: Write the failing unit test** (append to `tests/js/evb-view.html.test.js`):

```js
test('a block carries its client id when it has one', () => {
  const h = V.blockHtml(seg({ EventScheduleId: 0, cid: 'n3', Title: 'New', Category: 'Other', Leads: [] }), cats);
  assert.match(h, /data-cid="n3"/);
});
```

- [ ] **Step 3: Write the failing browser tests** in a `test.describe('editing')` block that signs in first. Every title the tests create starts with a unique `E2E <Date.now()>` prefix, and an `afterAll` signs in and removes any block whose title has that prefix through the batch endpoint.

```ts
test('Edit schedule is offered to an editor and not to a signed-out viewer', async ({ page, browser }) => {
  await openBoard(page, EDIT_EVENT);
  await expect(page.locator('.evb-edit-btn')).toBeVisible();
  const anon = await (await browser.newContext()).newPage();
  await anon.goto(`index.php?Route=Event/detail/${EDIT_EVENT}#ev-tab-schedule?view=board`);
  await expect(anon.locator('.evb-edit-btn')).toHaveCount(0);
});
test('the quick line previews, adds and persists an item', async ({ page }) => {
  await openBoard(page, EDIT_EVENT);
  await page.locator('.evb-edit-btn').click();
  const line = page.locator('.evb-quickline .evb-entry-input');
  await expect(line).toBeFocused();
  await line.fill(`sat 9:30-10:15 bg: ${PREFIX} squatch at main field "Bring water"`);
  await expect(page.locator('.evb-ghost')).toHaveCount(1);
  await expect(page.locator('.evb-chip[data-part="category"]')).toContainText('Battlegame');
  await expect(page.locator('.evb-chip[data-part="location"]')).toContainText('main field');
  await line.press('Enter');
  await expect(line).toHaveValue('');
  await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
  const block = page.locator('.evb-block', { hasText: `${PREFIX} squatch` });
  await expect(block).toHaveAttribute('data-category', 'Battlegame');
  await line.fill(`then 30m ${PREFIX} quest`);
  await line.press('Enter');
  await expect(page.locator('.evb-block', { hasText: `${PREFIX} quest` })).toContainText('10:15–10:45a');
  await page.locator('.evb-done').click();
  await expect(page.locator('.evb-modebar')).toHaveCount(0);
  await page.reload();
  await expect(page.locator('.evb-block', { hasText: `${PREFIX} squatch` })).toHaveCount(1);
  await page.locator('[data-ev-view="list"]').click();
  await expect(page.locator('#ev-schedule-container')).toContainText(`${PREFIX} squatch`);
});
test('undo removes the last add and a category tag is suggested', async ({ page }) => {
  await openBoard(page, EDIT_EVENT);
  await page.locator('.evb-edit-btn').click();
  const line = page.locator('.evb-quickline .evb-entry-input');
  await line.fill(`sat 2p ${PREFIX} undo me`);
  await line.press('Enter');
  await expect(page.locator('.evb-block', { hasText: `${PREFIX} undo me` })).toHaveCount(1);
  await page.locator('.evb-undo').click();
  await expect(page.locator('.evb-block', { hasText: `${PREFIX} undo me` })).toHaveCount(0);
  await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
  await line.fill('sat 3p #cl');
  await expect(page.locator('.evb-entry-option').first()).toContainText('Class');
  await line.press('Enter');
  await expect(line).toHaveValue(/#class\s*$/);
  await expect(page.locator('.evb-block', { hasText: '#cl' })).toHaveCount(0);
});
test('returning to the tab picks up changes made elsewhere', async ({ page, context }) => {
  await openBoard(page, EDIT_EVENT);
  await page.locator('.evb-edit-btn').click();
  const other = await context.newPage();
  await openBoard(other, EDIT_EVENT);
  await other.locator('.evb-edit-btn').click();
  const line = other.locator('.evb-quickline .evb-entry-input');
  await line.fill(`sun 4p ${PREFIX} elsewhere`);
  await line.press('Enter');
  await expect(other.locator('.evb-save-state')).toHaveText('All changes saved');
  await page.evaluate(() => window.dispatchEvent(new Event('focus')));
  await expect(page.locator('.evb-block', { hasText: `${PREFIX} elsewhere` })).toHaveCount(1);
});
```

`openBoard(page, event)` signs in if needed and opens `…#ev-tab-schedule?view=board`.

- [ ] **Step 4: Run both test suites.** Expected: the new unit test and the three browser tests fail.
- [ ] **Step 5: Implement** the view changes, `evb-entry.js`, `evb-edit.js`, the CSS and the template changes. The transport posts a `FormData` with `Ops` to `EvConfig.uir + 'EventAjax/schedule_batch/' + EvConfig.eventId + '/' + EvConfig.detailId` with the `X-Requested-With: XMLHttpRequest` header and `credentials: 'same-origin'`; a non-2xx response or a body that is not JSON rejects.
- [ ] **Step 6: Run both suites.** Expected: all pass (unit, the Phase 1 browser tests with `ORK3_E2E_SCHEDULE_EVENT`, and the three new ones).
- [ ] **Step 7: Commit:** `Schedule board: edit mode and the quick line`.

---

### Task 5: Typing on the board

**Files:**
- Modify: `orkui/template/revised-frontend/script/event-board/evb-edit.js`
- Modify: `orkui/template/revised-frontend/script/event-board/evb-view.js` (a pure position helper)
- Modify: `orkui/template/revised-frontend/style/event-board.css`
- Modify: `tests/js/evb-view.html.test.js`, `tests/e2e/event-schedule-board.spec.ts`

**Interfaces:**
- Consumes: Task 4's `EvBoardEntry` (with `compact: true`), `EvBoardEdit.entryContext`, the store.
- Produces: `EvBoardView.slotAt(offsetY, axisStart, step) -> number` (pure: the minute at a vertical offset inside a day body, floored to `step` minutes), and in the DOM `.evb-slot-hover` (the hover highlight) and `.evb-block.evb-block-editing[data-category]` holding the compact entry (`data-category` is the parsed category and updates as the text changes).

Behaviour (Edit mode only, pointer type mouse or pen):
- Moving over empty space in a day body shows `.evb-slot-hover` on the 15-minute slot under the pointer, as tall as the last length used.
- A click on empty space opens an editing block there with the last length used (60 minutes at first). Press and drag vertically instead sets the length (minimum 15 minutes); the drag starts after 4px of movement.
- The editing block contains the compact entry, parsed with `entryContext({fixedDay, fixedStart, fixedEnd})`. Its colour follows the parsed category, and typing a time or length moves or resizes it live.
- Enter with a valid parse adds the item and opens a new editing block starting where it ended, same day, same length. Enter with an empty title, or Esc, closes the editing block and ends the run. Tab adds the item and opens a new editing block at the same start time. Clicking elsewhere adds a valid entry and discards an empty one.
- The editing block takes part in lane layout like a real item, so neighbours make room while it is open.
- On touch, tapping empty space puts `<day> <time> ` into the quick line and focuses it.

- [ ] **Step 1: Write the failing unit test:**

```js
test('slotAt floors a pixel offset to the step', () => {
  assert.equal(V.slotAt(0, 420, 15), 420);
  assert.equal(V.slotAt(17.9, 420, 15), 420);
  assert.equal(V.slotAt(18, 420, 15), 435);
  assert.equal(V.slotAt(100, 540, 15), 615);
  assert.equal(V.slotAt(-5, 540, 15), 540);
});
```

- [ ] **Step 2: Write the failing browser tests** (inside the `editing` block):

```ts
async function clickSlot(page: Page, day: string, hhmm: string) {
  const body = page.locator(`.evb-day-body[data-day="${day}"]`);
  const [h, m] = hhmm.split(':').map(Number);
  const y = await page.evaluate(([hh, mm]) => {
    const w = window as any;
    return ((hh * 60 + mm) - w.EvBoardView.board.axisStart) * w.EvBoardView.PX_PER_MIN + 4;
  }, [h, m]);
  await body.click({ position: { x: 20, y } });
}
test('click, type, Enter, type, Enter makes a run of items', async ({ page }) => {
  await openBoard(page, EDIT_EVENT);
  await page.locator('.evb-edit-btn').click();
  await clickSlot(page, SUNDAY, '10:00');
  const editor = page.locator('.evb-block-editing .evb-entry-input');
  await expect(editor).toBeFocused();
  await editor.type(`class: ${PREFIX} first`);
  await expect(page.locator('.evb-block-editing')).toHaveAttribute('data-category', 'Class');
  await editor.press('Enter');
  await expect(editor).toBeFocused();
  await editor.type(`${PREFIX} second`);
  await editor.press('Enter');
  await editor.press('Escape');
  await expect(page.locator('.evb-block-editing')).toHaveCount(0);
  await expect(page.locator('.evb-block', { hasText: `${PREFIX} first` })).toContainText('10–11a');
  await expect(page.locator('.evb-block', { hasText: `${PREFIX} second` })).toContainText('11a–12p');
  await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
});
test('typing a time inside a block retimes it; Tab starts one alongside', async ({ page }) => {
  await openBoard(page, EDIT_EVENT);
  await page.locator('.evb-edit-btn').click();
  await clickSlot(page, SUNDAY, '13:00');
  const editor = page.locator('.evb-block-editing .evb-entry-input');
  await editor.type(`2-2:30 ${PREFIX} retimed`);
  await editor.press('Tab');
  await editor.type(`${PREFIX} beside`);
  await editor.press('Enter');
  await editor.press('Escape');
  await expect(page.locator('.evb-block', { hasText: `${PREFIX} retimed` })).toContainText('2–2:30p');
  await expect(page.locator('.evb-block', { hasText: `${PREFIX} beside` })).toContainText('2–2:30p');
});
test('Escape on a fresh block adds nothing', async ({ page }) => {
  await openBoard(page, EDIT_EVENT);
  await page.locator('.evb-edit-btn').click();
  const before = await page.locator('.evb-block').count();
  await clickSlot(page, SUNDAY, '15:00');
  await page.locator('.evb-block-editing .evb-entry-input').press('Escape');
  expect(await page.locator('.evb-block').count()).toBe(before);
});
```

`SUNDAY` is the occurrence's last day as `YYYY-MM-DD`, read from `EvConfig.eventEnd` in the page.

- [ ] **Step 3: Run both suites.** Expected: the new tests fail.
- [ ] **Step 4: Implement.** Use pointer events with `setPointerCapture` (the reorder code in `Court_detail.tpl` around 5196 is the house precedent). Handle clicks on `.evb-day-body` only when the target is the body itself, so clicks on blocks are left for Task 6.
- [ ] **Step 5: Run both suites.** Expected: all pass.
- [ ] **Step 6: Commit:** `Schedule board: type items straight onto the board`.

---

### Task 6: Selecting, changing and deleting blocks

**Files:**
- Modify: `orkui/template/revised-frontend/script/event-board/evb-edit.js`
- Modify: `orkui/template/revised-frontend/style/event-board.css`
- Modify: `orkui/template/revised-frontend/Eventnew_index.tpl` (expose a way to open the schedule modal from an item object)
- Modify: `orkui/template/revised-frontend/script/revised.js` (`evRemoveSchedule` at about 10448: themed confirm; modal save notifies the board)
- Modify: `tests/e2e/event-schedule-board.spec.ts`

**Interfaces:**
- Consumes: the store, `EvBoardEntry`, `EvBoardCore.formatEntry`, `window.orkConfirm(message, onConfirm, {title, okLabel, danger})`.
- Produces:
  - `window.evOpenScheduleModalForItem(item)`: opens the existing schedule modal in edit mode filled from a plain item object (all fields, including leads and the meal fields), the way `evOpenFeastEditModal` fills it from a meal object at about 3630, without needing a list row.
  - DOM: `.evb-block.evb-selected`; `.evb-block-toolbar` with `button[data-evb-act="category"]`, `"details"`, `"delete"`; `.evb-cat-menu` with one `button[data-category]` per category; legend chips show their number key in Edit mode.

Behaviour (Edit mode):
- Clicking a block selects it (and only it), shows the toolbar above it, and does not open the view-mode popover. Clicking empty space or pressing Esc clears the selection.
- With a block selected and no text field focused: Delete or Backspace removes it; `1`-`9` and `0` set its category by the definition's key; Enter or a double-click swaps the block for the compact entry prefilled with `<Title>[ at <Location>][ "<Description>"]`, and committing updates only the parts that changed (a typed time retimes it).
- The toolbar's category button opens `.evb-cat-menu`; Details opens the modal through `evOpenScheduleModalForItem`; Delete removes.
- Removing an item that has any of `Menu`, `Cost`, `Dietary`, `Allergens` when `EvConfig.canManageFeast` is false first asks with `orkConfirm('This item has menu and dietary details that you will not be able to restore. Remove it?', …, {title: 'Remove item', okLabel: 'Remove', danger: true})` and then removes with `undoable: false`. Every other removal is immediate and undoable.
- When the modal saves or deletes while Edit mode is on, the board re-fetches `schedule_list` once the store is idle and resets the store (the modal writes through the old endpoints). Outside Edit mode the Phase 1 refresh still runs.
- The List view's delete (`evRemoveSchedule`) uses `orkConfirm` with the same title and labels and the message `Remove this schedule item?`, and shows a failure in the row instead of `alert`.

- [ ] **Step 1: Write the failing browser tests:**

```ts
test('select, recategorise with a key, rename in place, delete and undo', async ({ page }) => {
  await openBoard(page, EDIT_EVENT);
  await page.locator('.evb-edit-btn').click();
  const line = page.locator('.evb-quickline .evb-entry-input');
  await line.fill(`sun 9a ${PREFIX} keys`);
  await line.press('Enter');
  const block = page.locator('.evb-block', { hasText: `${PREFIX} keys` });
  await block.click();
  await expect(block).toHaveClass(/evb-selected/);
  await expect(page.locator('.evb-popover')).toHaveCount(0);
  await page.keyboard.press('5');
  await expect(block).toHaveAttribute('data-category', 'Class');
  await page.keyboard.press('Enter');
  const editor = page.locator('.evb-block-editing .evb-entry-input');
  await expect(editor).toHaveValue(`${PREFIX} keys`);
  await editor.fill(`${PREFIX} renamed at Hall`);
  await editor.press('Enter');
  const renamed = page.locator('.evb-block', { hasText: `${PREFIX} renamed` });
  await expect(renamed).toHaveAttribute('data-category', 'Class');
  await renamed.click();
  await page.keyboard.press('Delete');
  await expect(renamed).toHaveCount(0);
  await page.locator('.evb-undo').click();
  await expect(renamed).toHaveCount(1);
  await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
});
test('Details opens the full form for the selected item', async ({ page }) => {
  await openBoard(page, EDIT_EVENT);
  await page.locator('.evb-edit-btn').click();
  const line = page.locator('.evb-quickline .evb-entry-input');
  await line.fill(`sun 11a ${PREFIX} details at Hall`);
  await line.press('Enter');
  await expect(page.locator('.evb-save-state')).toHaveText('All changes saved');
  await page.locator('.evb-block', { hasText: `${PREFIX} details` }).click();
  await page.locator('.evb-block-toolbar [data-evb-act="details"]').click();
  await expect(page.locator('#ev-schedule-modal')).toBeVisible();
  await expect(page.locator('#ev-sched-title')).toHaveValue(`${PREFIX} details`);
  await expect(page.locator('#ev-sched-location')).toHaveValue('Hall');
});
test('deleting from the list asks with the themed dialog', async ({ page }) => {
  let nativeDialog = false;
  page.on('dialog', d => { nativeDialog = true; d.dismiss(); });
  await openBoard(page, EDIT_EVENT);
  await page.locator('[data-ev-view="list"]').click();
  await page.locator('#ev-schedule-container .ev-del-link').first().click();
  await expect(page.locator('text=Remove this schedule item?')).toBeVisible();
  expect(nativeDialog).toBe(false);
});
```

- [ ] **Step 2: Run the browser suite.** Expected: the three new tests fail.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run every suite for the phase:** `npm run test:js`, the PHP test file, and the whole Playwright spec with all four environment variables. Expected: all pass.
- [ ] **Step 5: Commit:** `Schedule board: select, recategorise, rename and delete on the board`.
