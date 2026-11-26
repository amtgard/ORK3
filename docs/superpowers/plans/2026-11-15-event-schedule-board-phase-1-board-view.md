# Event Schedule Board, Phase 1: Board View: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the event Schedule tab's Grid view with a read-only all-days Board, add the tenth schedule category from one shared definition, and add "Share as image".

**Architecture:** The board is rendered in the browser from the schedule list the page already loads, by new focused scripts under `orkui/template/revised-frontend/script/event-board/`. All time, board-day and lane maths is pure code in `evb-core.js`, tested with node's built-in runner; DOM code is in `evb-view.js`; the PNG is drawn on a canvas by `evb-image.js`. Category definitions move to one PHP source that feeds the template, the modal, `revised.js` and the board.

**Tech Stack:** PHP 8 (domain in `system/lib/ork3/`, plain-PHP `.tpl` templates), MariaDB, vanilla ES5-style JavaScript (no bundler, no framework), PHPUnit 11, `node --test`, Playwright.

**Spec:** `docs/superpowers/specs/2026-11-15-event-schedule-board-design.md` (sections 7.1, 7.2, 8.1-8.4, 9). Later phases have their own plans.

## Global Constraints

- SQL only in `system/lib/ork3/`. `orkui/model/` is the only way from `orkui/` to the domain. Templates are plain PHP. Never add or edit anything under `orkui/template/default/` for this feature.
- Schedule times are naive local datetimes. JavaScript must never construct a `Date` from a schedule time and read hours, minutes or the date back from it. Use `EvBoardCore.parseNaive` and integer arithmetic. `Date.UTC` may be used only for whole-day arithmetic and weekday lookup.
- All user text (titles, locations, descriptions, persona names) is escaped before it enters HTML, and drawn as text (never parsed) on the canvas.
- No native `alert`, `confirm` or `prompt`. Tooltips use `data-tip`, never `title`.
- Dark mode is `html[data-theme="dark"] <selector>`; every new rule that sets a colour needs its dark counterpart or must use the `--ork-*` variables. Check computed styles in both themes: global `orkui.css` heading and control rules win over weak selectors.
- New CSS classes are prefixed `evb-`. New files are included with `?v=<?= filemtime(...) ?>` as `rank-pill.css` is (`Kingdomnew_index.tpl:109`).
- Bold block fills keep white text at a contrast of 4.5:1 or better.
- Git: stage explicit paths only (never `git add -A` or `git add .`), run `git diff --cached --stat` before every commit, and never stage `system/lib/ork3/class.Authorization.php`. Run git as plain single commands from the worktree root. Commit messages start `Schedule board: ` and end with the line `Co-Authored-By: Claude Fable 5.2 <noreply@anthropic.com>`.
- Never log in or out as `heraldsbridge` or `admin`, and never use `localhost:19080` in a browser or cookie jar; use `127.0.0.1:19081` (the worktree proxy) only.
- Do not run `bin/run-ork-db-checks.sh`, `bin/run-unit-tests.sh` or `bin/ork-db deploy-sandbox`.

## Dev Runbook (verified on this machine)

Worktree root: `/Users/averykrouse/GitHub/ORK-tobias/ORK3-tobias/.worktrees/event-schedule-entry`. All commands run from there.

- **Serve the worktree:** `python3 .superpowers/wtproxy.py event-schedule-entry 19081 &` then browse `http://127.0.0.1:19081/orkui/index.php?Route=Event/detail/{eventId}/{detailId}`. The plain `:19080` site serves a different checkout. Check it is already running first: `curl -s -o /dev/null -w '%{http_code}' 'http://127.0.0.1:19081/orkui/index.php?Route=Health'`.
- **PHP tests:** `ENVIRONMENT=TEST php ../../vendor/bin/phpunit -c phpunit.xml.dist --no-coverage --cache-directory=/private/tmp/claude-501/-Users-averykrouse-GitHub-ORK-tobias-ORK3-tobias/e05f9fe0-6324-41e9-8249-74fc79e03ee5/scratchpad/phpcache tests/Integration/EventPlanningTest.php` (baseline: `OK (16 tests, 38 assertions)`; warnings about `ork_idp_mailbox_challenge` are pre-existing).
- **PHP lint:** `php -l <file>`.
- **JS tests:** `npm run test:js` (added in Task 1). Node is v26; the `--no-maglev` flag in the script is required.
- **Playwright:** `ORK3_E2E_BASE_URL=http://127.0.0.1:19081/orkui/ npx --no-install playwright test tests/e2e/event-schedule-board.spec.ts --project=chromium --output=/private/tmp/claude-501/-Users-averykrouse-GitHub-ORK-tobias-ORK3-tobias/e05f9fe0-6324-41e9-8249-74fc79e03ee5/scratchpad/pw-out`.
- **Read-only SQL:** `docker exec ork3-php8-db mariadb -u ork -psecret ork -N -e "<query>"`.

## Review Focus

1. **An item that runs past midnight** (10pm to 1am) appears once, in the evening column it started in, not split at midnight. Pinned in Task 1.
2. **A title containing HTML** (`<img src=x onerror=alert(1)>`) renders as text on the board, in the popover and in the image. Pinned in Task 4 (`blockHtml`) and Task 5 (`popoverHtml`).
3. **A category the board does not know** (legacy or null) renders as Other and does not break layout or the legend. Pinned in Task 2.
4. **Items dated outside the event's own days**, and a one-day event, still get a column each and a sane axis. Pinned in Task 2.
5. **A zero-length or five-minute item** is still visible and clickable (minimum drawn height of 15 minutes) and does not create a phantom lane. Pinned in Tasks 1 and 2.

---

### Task 1: JS test harness and naive time maths

**Files:**
- Create: `orkui/template/revised-frontend/script/event-board/evb-core.js`
- Create: `tests/js/evb-core.time.test.js`
- Modify: `package.json` (add to `scripts`: `"test:js": "node --no-maglev --test tests/js/"`)

**Interfaces:**
- Produces (all on one object, exported as `module.exports` in node and `window.EvBoardCore` in the browser; no DOM access anywhere in this file):
  - `BOARD_DAY_START = 300` (minutes; 5:00am)
  - `parseNaive(str) -> {day: 'YYYY-MM-DD', min: number} | null`. Accepts `'YYYY-MM-DD HH:MM'`, an optional `:SS`, and `T` as the separator.
  - `toNaive(day, min) -> 'YYYY-MM-DD HH:MM'`. `min` may be negative or 1440 and over; the day rolls accordingly.
  - `addDays(day, n) -> 'YYYY-MM-DD'`, `diffDays(a, b) -> number` (b minus a).
  - `dayLabel(day) -> 'Friday'`, `dayLabelLong(day) -> 'Friday, November 20'`.
  - `fmtTime(min) -> '9:30a'` and `fmtRange(startMin, endMin) -> string` (see tests for the exact forms; `min` values of 1440 and over wrap).
  - `segments(item) -> Array<{item, day, startMin, endMin, isFirst, isLast}>`. `item` has `StartTime` and `EndTime` naive strings. `day` is the board day; `startMin`/`endMin` are minutes from that day's 00:00, so they lie in `[300, 1740]`. An end before the start is treated as equal to the start.

- [ ] **Step 1: Write the failing tests** in `tests/js/evb-core.time.test.js` using `node:test` and `node:assert/strict`, requiring `../../orkui/template/revised-frontend/script/event-board/evb-core.js` as `C`:

```js
test('parseNaive', () => {
  assert.deepEqual(C.parseNaive('2026-11-20 09:00:00'), { day: '2026-11-20', min: 540 });
  assert.deepEqual(C.parseNaive('2026-11-20T18:30'), { day: '2026-11-20', min: 1110 });
  assert.equal(C.parseNaive(''), null);
  assert.equal(C.parseNaive('nonsense'), null);
});
test('toNaive rolls days both ways', () => {
  assert.equal(C.toNaive('2026-11-20', 540), '2026-11-20 09:00');
  assert.equal(C.toNaive('2026-11-20', 1500), '2026-11-21 01:00');
  assert.equal(C.toNaive('2026-11-20', -30), '2026-11-19 23:30');
});
test('day arithmetic ignores time zones and DST', () => {
  assert.equal(C.addDays('2026-11-30', 1), '2026-12-01');
  assert.equal(C.addDays('2026-03-08', 1), '2026-03-09');
  assert.equal(C.addDays('2026-11-01', -1), '2026-10-31');
  assert.equal(C.diffDays('2026-11-20', '2026-11-22'), 2);
  assert.equal(C.dayLabel('2026-11-20'), 'Friday');
  assert.equal(C.dayLabelLong('2026-11-20'), 'Friday, November 20');
});
test('time labels', () => {
  assert.equal(C.fmtTime(570), '9:30a');
  assert.equal(C.fmtTime(720), '12p');
  assert.equal(C.fmtTime(0), '12a');
  assert.equal(C.fmtTime(1500), '1a');
  assert.equal(C.fmtRange(570, 615), '9:30–10:15a');
  assert.equal(C.fmtRange(690, 750), '11:30a–12:30p');
  assert.equal(C.fmtRange(720, 780), '12–1p');
  assert.equal(C.fmtRange(1380, 1500), '11p–1a');
});
test('an evening item past midnight stays in its evening column', () => {
  const s = C.segments({ StartTime: '2026-11-20 22:00:00', EndTime: '2026-11-21 01:00:00' });
  assert.equal(s.length, 1);
  assert.deepEqual([s[0].day, s[0].startMin, s[0].endMin, s[0].isFirst, s[0].isLast],
    ['2026-11-20', 1320, 1500, true, true]);
});
test('an item starting after midnight belongs to the previous board day', () => {
  const s = C.segments({ StartTime: '2026-11-21 00:30:00', EndTime: '2026-11-21 01:30:00' });
  assert.deepEqual([s.length, s[0].day, s[0].startMin, s[0].endMin], [1, '2026-11-20', 1470, 1530]);
});
test('an item crossing 5am splits into one segment per board day', () => {
  const s = C.segments({ StartTime: '2026-11-20 22:00:00', EndTime: '2026-11-21 09:00:00' });
  assert.equal(s.length, 2);
  assert.deepEqual([s[0].day, s[0].startMin, s[0].endMin, s[0].isFirst, s[0].isLast], ['2026-11-20', 1320, 1740, true, false]);
  assert.deepEqual([s[1].day, s[1].startMin, s[1].endMin, s[1].isFirst, s[1].isLast], ['2026-11-21', 300, 540, false, true]);
});
test('ending exactly at 5am leaves no empty segment; zero-length items survive', () => {
  assert.equal(C.segments({ StartTime: '2026-11-20 22:00:00', EndTime: '2026-11-21 05:00:00' }).length, 1);
  const z = C.segments({ StartTime: '2026-11-20 09:00:00', EndTime: '2026-11-20 09:00:00' });
  assert.deepEqual([z.length, z[0].startMin, z[0].endMin], [1, 540, 540]);
  const bad = C.segments({ StartTime: '2026-11-20 10:00:00', EndTime: '2026-11-20 09:00:00' });
  assert.deepEqual([bad.length, bad[0].startMin, bad[0].endMin], [1, 600, 600]);
});
```

- [ ] **Step 2: Run `npm run test:js`.** Expected: FAIL (cannot find module, or functions undefined).
- [ ] **Step 3: Implement the functions above in `evb-core.js`.** Wrap the file as `(function (root, factory) { if (typeof module === 'object' && module.exports) module.exports = factory(); else root.EvBoardCore = factory(); })(this, function () { … })`. Weekday and month names come from fixed English arrays indexed by `new Date(Date.UTC(y, m - 1, d)).getUTCDay()`.
- [ ] **Step 4: Run `npm run test:js`.** Expected: all tests pass.
- [ ] **Step 5: Commit** `package.json`, the new script and the new test: `Schedule board: naive time maths and JS test harness`.

---

### Task 2: Lane layout and board model

**Files:**
- Modify: `orkui/template/revised-frontend/script/event-board/evb-core.js`
- Create: `tests/js/fixtures/sheet-schedule.js`
- Create: `tests/js/evb-core.layout.test.js`

**Interfaces:**
- Consumes: `segments`, `parseNaive`, `addDays`, `dayLabel` from Task 1.
- Produces:
  - `layoutDay(segs) -> number` (the day's lane count). Mutates each segment, adding `lane` (0-based), `left` and `width` (percent of the day's width, 0-100).
  - `buildBoard(items, opts) -> {days: Array<{day, label, segs, lanes}>, axisStart, axisEnd}`. `opts = {eventStart, eventEnd, editing}` where the two dates are naive strings or empty and `editing` is a boolean. `days` is sorted, one entry for every calendar day from `eventStart`'s day to `eventEnd`'s day, plus every other day that has a segment. `label` is `dayLabel(day)`. A segment's category for layout is `item.Category`, or `'Other'` when missing.
  - Axis: with items, `axisStart` is the earliest `startMin` floored to the hour and `axisEnd` the latest `endMin` rounded up to the hour; with none, 540 and 1080. When `opts.editing`, `axisStart = min(axisStart, 420)` and `axisEnd = max(axisEnd, 1440)`.

- [ ] **Step 1: Build the fixture.** `tests/js/fixtures/sheet-schedule.js` exports an array of items (`Title`, `Category`, `StartTime`, `EndTime`) ported from the `DAYS` array in `.superpowers/brainstorm/34729-1794795327/content/board-look.html`: Friday is `2026-11-20`, Saturday `2026-11-21`, Sunday `2026-11-22`; map `admin`→`Administrative`, `tourn`→`Tournament`, `battle`→`Battlegame`, `ans`→`Arts and Sciences`, `klass`→`Class`, `feast`→`Feast and Food`, `court`→`Court`, `other`→`Other`. It must contain 42 items.

- [ ] **Step 2: Write the failing tests** in `tests/js/evb-core.layout.test.js`:

```js
const items = require('./fixtures/sheet-schedule.js');
const opts = { eventStart: '2026-11-20T09:00', eventEnd: '2026-11-22T16:00', editing: false };
const find = (b, day, title) => b.days.find(d => d.day === day).segs.find(s => s.item.Title === title);

test('the published sheet lays out as 2, 4 and 1 lanes', () => {
  const b = C.buildBoard(items, opts);
  assert.deepEqual(b.days.map(d => [d.day, d.label, d.lanes]),
    [['2026-11-20', 'Friday', 2], ['2026-11-21', 'Saturday', 4], ['2026-11-22', 'Sunday', 1]]);
  assert.deepEqual([b.axisStart, b.axisEnd], [540, 1200]);
});
test('an item with nothing beside it spans the day', () => {
  const b = C.buildBoard(items, opts);
  const crat = find(b, '2026-11-20', 'Crat Setup');
  assert.deepEqual([crat.left, crat.width], [0, 100]);
});
test('items keep to the lane their category last used', () => {
  const b = C.buildBoard(items, opts);
  assert.equal(find(b, '2026-11-21', 'A&S Sign Ins Close').lane, find(b, '2026-11-21', 'A&S Setup Begins').lane);
  assert.equal(find(b, '2026-11-21', 'Good King Tobias: Protocol and Precedence').lane, find(b, '2026-11-21', 'Sir Adler: Train the Trainer Pt3').lane);
  assert.equal(find(b, '2026-11-20', 'RP Quest Starts').lane, find(b, '2026-11-20', 'Squatch Ball').lane);
});
test('overlapping items never share horizontal space', () => {
  const b = C.buildBoard(items, opts);
  b.days.forEach(d => d.segs.forEach(a => d.segs.forEach(o => {
    if (a === o || a.startMin >= o.endMin || o.startMin >= a.endMin) return;
    assert.ok(a.left + a.width <= o.left + 0.001 || o.left + o.width <= a.left + 0.001, a.item.Title + ' overlaps ' + o.item.Title);
  })));
});
test('empty schedule, edit axis, out-of-range days, unknown category, zero-length items', () => {
  const e = C.buildBoard([], opts);
  assert.deepEqual([e.days.length, e.axisStart, e.axisEnd], [3, 540, 1080]);
  const ed = C.buildBoard(items, { eventStart: opts.eventStart, eventEnd: opts.eventEnd, editing: true });
  assert.deepEqual([ed.axisStart, ed.axisEnd], [420, 1440]);
  const one = C.buildBoard([{ Title: 'Late add', StartTime: '2026-11-25 10:00:00', EndTime: '2026-11-25 11:00:00' }],
    { eventStart: '2026-11-21T09:00', eventEnd: '2026-11-21T18:00', editing: false });
  assert.deepEqual(one.days.map(d => d.day), ['2026-11-21', '2026-11-25']);
  assert.equal(one.days[1].segs[0].lane, 0);
  const z = C.buildBoard([
    { Title: 'A', Category: null, StartTime: '2026-11-21 09:00:00', EndTime: '2026-11-21 09:00:00' },
    { Title: 'B', Category: 'Nope', StartTime: '2026-11-21 09:00:00', EndTime: '2026-11-21 10:00:00' }
  ], { eventStart: '', eventEnd: '', editing: false });
  assert.deepEqual([z.days.length, z.days[0].lanes], [1, 2]);
});
```

- [ ] **Step 3: Run `npm run test:js`.** Expected: the new file fails.
- [ ] **Step 4: Implement `layoutDay` and `buildBoard`.** Port the `layout` function in `board-look.html` (the block starting `function layout(items)`), which already produces 2/4/1 on this data. Its rules: sort by start then end; a cluster is a run of segments where each starts before the running latest end; within a cluster pick, in order, (a) a free lane whose last occupant had the same category, (b) the first free lane that no other-category item of the lane's category will need before this segment ends, (c) a new lane; then widen each segment rightwards across lanes that hold nothing overlapping it; `left = lane / clusterLanes * 100`, `width = span / clusterLanes * 100`. For layout only, treat a segment shorter than 15 minutes as 15 minutes long, so zero-length items occupy a lane.
- [ ] **Step 5: Run `npm run test:js`.** Expected: all pass.
- [ ] **Step 6: Commit** the three files: `Schedule board: lane layout and board model`.

---

### Task 3: One category source and the tenth category

**Files:**
- Modify: `system/lib/ork3/class.EventPlanning.php:5-8` (the `SCHEDULE_CATEGORIES` constant) and its uses at about 1538 and 1541
- Modify: `orkui/model/model.Event.php` (add `schedule_categories()`)
- Modify: `orkui/controller/controller.Event.php` (in `detail()`, next to `$this->data['ScheduleList']` at about 613)
- Modify: `orkui/template/revised-frontend/Eventnew_index.tpl` (palette at 1245-1255; list row at 1346; modal selects at 2894-2919; `EvConfig` at 2556-2587; per-category dark rules at about 492-562)
- Modify: `orkui/template/revised-frontend/script/revised.js` (`EV_CATEGORIES` at 9999-10009; the order array at 10371; the row builder at about 10254-10293)
- Modify: `orkui/template/embed/ork-schedule.js:27-37` (`CAT_COLORS`)
- Test: `tests/Integration/EventPlanningTest.php`

**Interfaces:**
- Produces:
  - PHP: `EventPlanning::ScheduleCategories(): array`, public static. Ordered map of name to `['key', 'icon', 'color', 'bg', 'colorDark', 'bgDark', 'bold']`.
  - PHP: `EventPlanning::GetScheduleCategories($request = []): array` returning `['Status' => Success(), 'Categories' => self::ScheduleCategories()]`, and `Model_Event::schedule_categories(): array` returning that `Categories` map (empty array on failure).
  - Template variable `$ScheduleCategories` (the map).
  - JS: `EvConfig.scheduleCategories`, an ordered array of `{name, key, icon, color, bg, colorDark, bgDark, bold}`.

The definitions, in this order (`color`, `bg` and `icon` are today's values; take each existing category's `colorDark` and `bgDark` from its current dark rule in the template, e.g. Feast and Food is `#ffb74d` and `#3a2410`):

| name | key | icon | color | bg | bold |
|---|---|---|---|---|---|
| Administrative | 1 | fa-clipboard-list | #546e7a | #eceff1 | #546e7a |
| Tournament | 2 | fa-trophy | #b8860b | #fffde7 | #8a6500 |
| Battlegame | 3 | fa-shield-alt | #c0392b | #fdecea | #c0392b |
| Arts and Sciences | 4 | fa-palette | #7b1fa2 | #f3e5f5 | #7b1fa2 |
| Class | 5 | fa-graduation-cap | #1565c0 | #e3f2fd | #1565c0 |
| Feast and Food | 6 | fa-utensils | #e65100 | #fff3e0 | #bf4d00 |
| Court | 7 | fa-crown | #4e342e | #efebe9 | #4e342e |
| Meeting | 8 | fa-users | #276749 | #f0fff4 | #276749 |
| Roleplay and Quests | 9 | fa-dragon | #c2185b | #fce4ec | #c2185b |
| Other | 0 | fa-star | #757575 | #fafafa | #616161 |

Roleplay and Quests dark values: `colorDark` `#f48fb1`, `bgDark` `#3a1626`.

- [ ] **Step 1: Write the failing tests** in `tests/Integration/EventPlanningTest.php`, following the style of `testAddScheduleWithLeads` (about line 180) for setup and the add call:

```php
public function testScheduleCategoriesListsTenInOrder(): void
{
    $cats = EventPlanning::ScheduleCategories();
    $this->assertSame([
        'Administrative', 'Tournament', 'Battlegame', 'Arts and Sciences', 'Class',
        'Feast and Food', 'Court', 'Meeting', 'Roleplay and Quests', 'Other',
    ], array_keys($cats));
    $this->assertSame(['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'], array_column($cats, 'key'));
    foreach ($cats as $def) {
        foreach (['icon', 'color', 'bg', 'colorDark', 'bgDark', 'bold'] as $k) {
            $this->assertNotSame('', (string) ($def[$k] ?? ''), $k);
        }
    }
    $this->assertSame('#c2185b', $cats['Roleplay and Quests']['bold']);
}
```

Plus `testAddScheduleAcceptsRoleplayAndQuests` (add an item with `Category => 'Roleplay and Quests'`; assert the returned `Schedule.Category` is that string) and `testAddScheduleUnknownCategoryBecomesOther` (add with `Category => 'Jousting'`; assert `'Other'`).

- [ ] **Step 2: Run the PHP tests (runbook).** Expected: the three new tests fail; the 16 existing pass.
- [ ] **Step 3: Implement `ScheduleCategories()`, `GetScheduleCategories()` and derive the allow-list** as `array_keys(self::ScheduleCategories())` at both validation sites.
- [ ] **Step 4: Run the PHP tests.** Expected: 19 pass.
- [ ] **Step 5: Wire it to the page.** Add `Model_Event::schedule_categories()` beside `get_schedule()` (about line 309), following that method's shape. In `controller.Event.php` set `$this->data['ScheduleCategories']`. In the template: build `$evSchedCategories` from `$ScheduleCategories` (keep the variable name; the list and feast code read `icon`, `color`, `bg` from it); render both modal selects by looping it (secondary keeps its leading "None" option); add `scheduleCategories` to `EvConfig`.
- [ ] **Step 6: Make list rows theme from variables.** On each list `<tr>` replace `style="background:…"` with `style="--evb-soft:<bg>;--evb-soft-dark:<bgDark>"`, add `.ev-sched-table tbody tr { background: var(--evb-soft); }` and `html[data-theme="dark"] .ev-sched-table tbody tr { background: var(--evb-soft-dark); }`, and delete the per-category `html[data-theme="dark"] .ev-sched-table tr[data-category="…"]` rules. Leave the `.ev-grid-*` category rules alone; Task 4 removes the grid.
- [ ] **Step 7: Make `revised.js` read the shared list.** Replace the `EV_CATEGORIES` literal with a map built from `EvConfig.scheduleCategories` (same `icon`/`color`/`bg` keys, so readers are unchanged), build the filter order array from it, and make the JS row builder set the same two CSS variables instead of a background.
- [ ] **Step 8: Add `'Roleplay and Quests': '#c2185b'` to `CAT_COLORS`** in the embed widget.
- [ ] **Step 9: Verify in the page.** Run `php -l` on the three PHP files. Start the proxy and fetch an event detail page (find one with `SELECT cd.event_id, s.event_calendardetail_id, COUNT(*) FROM ork_event_schedule s JOIN ork_event_calendardetail cd USING (event_calendardetail_id) JOIN ork_event e ON e.event_id = cd.event_id WHERE e.status = 'published' GROUP BY 1, 2 ORDER BY 3 DESC LIMIT 5`). Confirm with `curl … | grep -c` that the HTML contains `scheduleCategories`, an `<option value="Roleplay and Quests">` in both selects, and `--evb-soft:` on list rows.
- [ ] **Step 10: Commit** the listed files: `Schedule board: one category source, add Roleplay and Quests`.

---

### Task 4: The board renders in place of the grid

**Files:**
- Create: `orkui/template/revised-frontend/script/event-board/evb-view.js`
- Create: `orkui/template/revised-frontend/style/event-board.css`
- Create: `tests/js/evb-view.html.test.js`
- Modify: `orkui/template/revised-frontend/Eventnew_index.tpl` (toolbar at 1258-1271; grid block at 1383-1556; `EvConfig`; includes near 246 and 3053; grid CSS and JS at about 3961-4590; grid dark rules at about 459-562; mobile rules at 4159-4169)
- Modify: `orkui/template/revised-frontend/script/revised.js` (the calls to `evRefreshScheduleGrid` at about 10339 and 10492, and `evApplyGridFilter` at about 10426)

**Interfaces:**
- Consumes: `EvBoardCore.buildBoard`, `fmtRange` (Tasks 1-2); `EvConfig.scheduleCategories` (Task 3).
- Produces:
  - `EvConfig.schedule`: the page's schedule list as emitted by PHP, each item with keys `EventScheduleId`, `Title`, `StartTime`, `EndTime`, `Location`, `Description`, `Category`, `SecondaryCategory`, `Leads` (`[{MundaneId, Persona}]`), `Menu`, `Cost`, `Dietary`, `Allergens`. `EvConfig.staffList` entries gain `RoleName`.
  - `window.EvBoardView` (also `module.exports` in node, with DOM use guarded) with:
    - `PX_PER_MIN = 1.2` (18px per 15 minutes)
    - `esc(str) -> string`
    - `blockHtml(seg, catsByName) -> string` (pure)
    - `init()`, `render()`, `setItems(items)`, `getItems()`, `setView('board' | 'list', {persist: boolean})`, `getView()`, `setFilter(category | null)` (adds `.evb-dim` to blocks of other categories)
    - `board` (the last `buildBoard` result), and `onRender(fn)` to register a callback run after every render
  - DOM contract later phases rely on:
    ```
    #evb-root.evb
      .evb-legend > button.evb-legend-chip[data-category]
      .evb-scroller > .evb-board
        .evb-gutter > .evb-head, .evb-gutter-body > .evb-tick
        .evb-day[data-day] > .evb-head, .evb-day-body[data-day]
          .evb-block[data-id][data-day][data-category] > .evb-block-title, .evb-block-meta
    ```
    `.evb-day-body` is `position: relative` with height `(axisEnd - axisStart) * PX_PER_MIN`; a block's `top` is `(startMin - axisStart) * PX_PER_MIN` and its height `max(endMin - startMin, 15) * PX_PER_MIN`, with `left`/`width` from the segment's percentages.
  - `window.evRefreshScheduleGrid()` keeps existing callers working: it re-fetches the page as today, reads the fresh `EvConfig.schedule` out of the returned HTML (emit it in its own `<script type="application/json" id="evb-schedule-data">` so it can be parsed without evaluating script), and calls `EvBoardView.setItems`.

- [ ] **Step 1: Write the failing test** `tests/js/evb-view.html.test.js`:

```js
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
```

"Tall" means 90 minutes or longer.

- [ ] **Step 2: Run `npm run test:js`.** Expected: the new file fails.
- [ ] **Step 3: Implement `evb-view.js`.** `blockHtml` builds one `.evb-block` (a `<div role="button" tabindex="0">` with an `aria-label` of title and time); an item whose primary or secondary category is Feast and Food and whose `Menu` is non-empty gets an `<i class="fas fa-scroll evb-block-menu" data-tip="Has menu">` after its title. `render()` builds the legend (categories present, in definition order, each chip showing the bold swatch and name), the gutter with one `.evb-tick` label per hour, and one `.evb-day` per board day whose flex weight is `max(lanes, 1.4)`. `init()` reads `EvConfig`, renders into `#evb-root`, and wires the view toggle.
- [ ] **Step 4: Run `npm run test:js`.** Expected: pass.
- [ ] **Step 5: Change the template.**
  - Toolbar: the two toggle buttons become Board (`data-ev-view="board"`, icon `fa-th`) and List; add `<div id="evb-root" class="evb" style="display:none"></div>` where `#ev-schedule-grid-container` was.
  - Delete the server-rendered grid block and the grid-only CSS, dark rules and JS (`setupGridDays`, `selectGridDay`, the grid popover, `evGridBlockClick`, the now-line timer, `evReinitScheduleGrid`). Keep the list, the day sections, the filter pills, `evCopyScheduleLink`, and the hash plumbing (`evWriteHash`, `evScheduleHashSuffix`). The `<style>` block at about 3960-4273 mixes grid rules (`.ev-grid-*`) with the phone List-card rules at about 4170-4272 and the toolbar and copy-link button rules: delete only selectors that target `.ev-grid-day`, `.ev-grid-scroller`, `.ev-grid-inner`, `.ev-grid-header-row`, `.ev-grid-cat-*`, `.ev-grid-body-row`, `.ev-grid-time-*`, `.ev-grid-col`, `.ev-grid-block*`, `.ev-grid-now-*`, `.ev-grid-popover*`, `.ev-grid-lead-chip` and `#ev-schedule-grid-container`. `.ev-grid-view-toolbar`, `.ev-grid-view-toggle`, `.ev-grid-view-btn` and `.ev-sched-*` stay.
  - Emit `EvConfig.schedule` and the JSON data script, and add `serverNow: <?= json_encode(date('Y-m-d H:i')) ?>` to `EvConfig`; include `event-board.css` near line 246 and the two scripts after `revised.js` near line 3053, core before view.
  - `evRemoveFeastCard` (about 3723-3758) does not refresh the other views today; add a call to `window.evRefreshScheduleGrid()` on its success path.
  - Move the view state machine (`applyMode`, `ev-sched-view-mode` in `localStorage`, hash parsing) into `evb-view.js` as `setView`. A stored or linked `grid` means `board`. With no stored choice the default is `board` when there are items and the viewport is wider than 700px, otherwise `list`. `evScheduleHashSuffix` returns `?view=board` (plus `&day=YYYYMMDD` when a single day is in view, Task 5) or an empty string for list.
- [ ] **Step 6: Write `event-board.css`.** Sticky `.evb-head` row and sticky left `.evb-gutter` inside `.evb-scroller` (`overflow: auto`; `max-height: 80vh`); hour lines on `.evb-day-body` as a repeating gradient every 72px using `--ork-border`; blocks with white text on `var(--evb-fill)`, 5px radius, 3px 6px padding, title 12px/650 clamped to the lines that fit, meta 10px; a focus ring on `.evb-block:focus-visible`; minimum lane width 110px (day `min-width` is `lanes * 110px`). Dark theme: chrome from `--ork-*` variables; fills unchanged.
- [ ] **Step 7: Point `revised.js` at the board.** `evApplyGridFilter` becomes a call to `EvBoardView.setFilter(category | null)` (add it: dims non-matching blocks with `.evb-dim`); the two `evRefreshScheduleGrid` call sites stay as they are.
- [ ] **Step 8: Verify.** `php -l` the template is not possible (it is a `.tpl`), so fetch the page through the proxy and confirm HTTP 200 and no PHP warning text (`grep -ci 'warning\|fatal\|parse error'` returns 0). Then run the Playwright check below.
- [ ] **Step 9: Create `tests/e2e/event-schedule-board.spec.ts`** with the first signed-out test. The event under test comes from `ORK3_E2E_SCHEDULE_EVENT` (`"eventId/detailId"`); `test.skip` when unset. Use the event found in Task 3 Step 9.

```ts
test('the board renders the schedule for a signed-out viewer', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', e => errors.push(String(e)));
  await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=board`);
  await expect(page.locator('#evb-root')).toBeVisible();
  const listCount = await page.locator('#ev-schedule-container tr[data-schedule-id]').evaluateAll(
    rows => new Set(rows.map(r => r.getAttribute('data-schedule-id'))).size);
  const boardCount = await page.locator('.evb-block').evaluateAll(
    els => new Set(els.map(e => e.getAttribute('data-id'))).size);
  expect(boardCount).toBe(listCount);
  expect(await page.locator('.evb-day').count()).toBeGreaterThan(0);
  await page.locator('[data-ev-view="list"]').click();
  await expect(page.locator('#evb-root')).toBeHidden();
  await expect(page.locator('#ev-schedule-container')).toBeVisible();
  expect(errors).toEqual([]);
});
test('old grid links open the board', async ({ page }) => {
  await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=grid`);
  await expect(page.locator('#evb-root')).toBeVisible();
});
```

- [ ] **Step 10: Run the spec (runbook) with `ORK3_E2E_SCHEDULE_EVENT` set.** Expected: 2 passed.
- [ ] **Step 11: Commit** the listed files: `Schedule board: board view replaces the grid`.

---

### Task 5: Popover, legend filter, now line, phones, dark theme

**Files:**
- Modify: `orkui/template/revised-frontend/script/event-board/evb-view.js`
- Modify: `orkui/template/revised-frontend/style/event-board.css`
- Modify: `orkui/template/revised-frontend/Eventnew_index.tpl` (the mobile rules at about 4159-4169 that hid the grid and the toggle)
- Modify: `tests/js/evb-view.html.test.js`, `tests/e2e/event-schedule-board.spec.ts`

**Interfaces:**
- Consumes: Task 4's `EvBoardView` and DOM contract.
- Produces: `EvBoardView.popoverHtml(item, catsByName, uir, opts) -> string` (pure; `opts.canEdit` adds an Edit button), `EvBoardView.nowMinute(serverNow, elapsedMs) -> {day, min}` (pure; the board day and minute for the "now" line), `EvBoardView.setDay(day | null)` (null shows all days), `EvBoardView.getDay()`, `EvBoardView.autolink(text) -> string` (escapes, then links `http(s)://` URLs with `rel="noopener noreferrer" target="_blank"`).

- [ ] **Step 1: Write the failing unit tests** (append to `tests/js/evb-view.html.test.js`):

```js
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
test('the now line follows the server clock and the 5am board day', () => {
  assert.deepEqual(V.nowMinute('2026-11-21 14:05', 0), { day: '2026-11-21', min: 845 });
  assert.deepEqual(V.nowMinute('2026-11-21 14:05', 10 * 60000), { day: '2026-11-21', min: 855 });
  assert.deepEqual(V.nowMinute('2026-11-22 01:30', 0), { day: '2026-11-21', min: 1530 });
  assert.deepEqual(V.nowMinute('2026-11-21 23:50', 20 * 60000), { day: '2026-11-21', min: 1450 });
});
```

- [ ] **Step 2: Run `npm run test:js`.** Expected: the two new tests fail.
- [ ] **Step 3: Implement.**
  - **Popover:** click, Enter or Space on a block opens one `.evb-popover` (position fixed, placed below the block and flipped above when it would leave the viewport, closed by outside click, Esc or scroll). A multi-day item's time reads with weekday names on both ends. When `EvConfig.canManageSchedule` is true, pass `{canEdit: true}`; clicking `.evb-popover-edit` closes the popover and clicks that item's `.ev-edit-link` in the list (`#ev-schedule-row-{id} .ev-edit-link`), which is how the old grid opened the edit modal (the modal reads its values from the list row).
  - **Legend filter:** clicking a chip calls `setFilter` with its category; clicking the active chip clears it. Chips are buttons with `aria-pressed`.
  - **Now line:** `nowMinute(EvConfig.serverNow, performance.now() - <value at init>)` gives the board day and minute; when that day is on the board and the minute is inside the axis, draw `.evb-now` across that day, and update it every minute while the board is visible. The browser's own clock and zone are never read.
  - **Phones (700px and under):** the toggle stays visible; Board shows one day at a time using the existing `.ev-sched-pill` bar for day choice, defaulting to today's board day when it is one of the days, else the first. `setDay` drives it and the hash carries `&day=YYYYMMDD`. A `day` in the hash also selects that day on wider screens (others hidden) until "All days" is chosen from the pill bar.
  - **Dark theme and narrow widths:** no horizontal page scroll at 390px; the scroller scrolls inside itself.
- [ ] **Step 4: Run `npm run test:js`.** Expected: pass.
- [ ] **Step 5: Add browser tests** to the spec and run them:

```ts
test('clicking a block shows its details; the legend dims other categories', async ({ page }) => {
  await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=board`);
  const first = page.locator('.evb-block').first();
  const title = (await first.locator('.evb-block-title').innerText()).trim();
  await first.click();
  await expect(page.locator('.evb-popover')).toContainText(title);
  await page.keyboard.press('Escape');
  await expect(page.locator('.evb-popover')).toHaveCount(0);
  const chip = page.locator('.evb-legend-chip').first();
  const cat = await chip.getAttribute('data-category');
  await chip.click();
  expect(await page.locator(`.evb-block:not([data-category="${cat}"]):not(.evb-dim)`).count()).toBe(0);
});
test('phone width shows one day and does not scroll the page sideways', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 800 });
  await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=board`);
  await expect(page.locator('.evb-day:visible')).toHaveCount(1);
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});
test('dark theme keeps block text white on its fill', async ({ page }) => {
  await page.addInitScript(() => localStorage.setItem('ork_theme', 'dark'));
  await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=board`);
  const color = await page.locator('.evb-block-title').first().evaluate(e => getComputedStyle(e).color);
  expect(color).toBe('rgb(255, 255, 255)');
});
```

Expected: 5 passed in the file.
- [ ] **Step 6: Commit:** `Schedule board: popover, legend filter, now line, phone and dark layouts`.

---

### Task 6: Share as image

**Files:**
- Create: `orkui/template/revised-frontend/script/event-board/evb-image.js`
- Modify: `orkui/template/revised-frontend/Eventnew_index.tpl` (toolbar button and menu; script include after `evb-view.js`)
- Modify: `orkui/template/revised-frontend/style/event-board.css`
- Modify: `tests/e2e/event-schedule-board.spec.ts`

**Interfaces:**
- Consumes: `EvBoardView.board`, `EvBoardView.getDay()`, `EvConfig.eventName`, `EvConfig.eventDate`, `EvConfig.scheduleCategories`.
- Produces: `window.EvBoardImage.render(board, meta, opts) -> HTMLCanvasElement` with `meta = {title, subtitle, url, categories}` and `opts = {scale: 2, day: null | 'YYYY-MM-DD'}`; `EvBoardImage.fileName(title) -> string`; `EvBoardImage.download(canvas, name)`; `EvBoardImage.copy(canvas) -> Promise<boolean>`.

- [ ] **Step 1: Add the failing browser test:**

```ts
test('share as image downloads a PNG of the board', async ({ page }) => {
  await page.goto(`index.php?Route=Event/detail/${EVENT}#ev-tab-schedule?view=board`);
  await page.locator('.evb-share-btn').click();
  const [download] = await Promise.all([
    page.waitForEvent('download'),
    page.locator('.evb-share-menu [data-evb-share="download-all"]').click(),
  ]);
  expect(download.suggestedFilename()).toMatch(/-schedule\.png$/);
  const dims = await page.evaluate(() => {
    const c = (window as any).EvBoardImage.render((window as any).EvBoardView.board,
      { title: 'T', subtitle: 'S', url: 'u', categories: (window as any).EvConfig.scheduleCategories }, { scale: 2, day: null });
    return [c.width, c.height];
  });
  expect(dims[0]).toBeGreaterThan(800);
  expect(dims[1]).toBeGreaterThan(400);
});
```

- [ ] **Step 2: Run the spec.** Expected: this test fails.
- [ ] **Step 3: Implement `evb-image.js`.** Draw with the Canvas 2D API from the board model, never from the DOM: white background; header with the event name (22px, bold) and the date line; day headers; hour labels and hour lines; each block as a rounded rectangle in its bold fill with the title (bold, wrapped with `measureText`, clipped to the block) and the `fmtRange` time in white; the legend for categories present; a footer with the event's ORK address in grey. Lane width 190px, 1.2px per minute, everything multiplied by `opts.scale`. `fileName` lower-cases the title, replaces runs of non-alphanumerics with `-`, trims dashes, and appends `-schedule.png` (`'schedule.png'` when nothing is left). `download` uses `canvas.toBlob` and a temporary `<a download>`; `copy` uses `navigator.clipboard.write` with a `ClipboardItem` and resolves false when either is missing or it throws.
- [ ] **Step 4: Add the toolbar control.** A "Share as image" button (`.evb-share-btn`, shown only in Board view) opens `.evb-share-menu` with "Download PNG" (`data-evb-share="download-all"`), "Download this day" (`download-day`, only when a single day is in view) and "Copy image" (`copy`, only when `ClipboardItem` exists). The menu closes on outside click and Esc. After a copy, the button text reads "Copied" for two seconds.
- [ ] **Step 5: Run the spec.** Expected: 6 passed.
- [ ] **Step 6: Run everything for the phase:** `npm run test:js` (all pass) and the PHP test file (19 pass).
- [ ] **Step 7: Commit:** `Schedule board: share as image`.
