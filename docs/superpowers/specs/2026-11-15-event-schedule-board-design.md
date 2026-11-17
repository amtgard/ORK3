# Event Schedule Board: design

Date: 2026-11-15
Branch: `feature/event-schedule-entry`
Status: written while the owner was away; the calls marked "my call" are for them to confirm.

## 1. Intent

A kingdom published its event schedule as a Google Sheets screenshot instead of using the ORK.
The goal is simple: **building a schedule in an event's Schedule tab should be as easy as
whipping it together in Google Sheets, and the result should be as postable as that
screenshot.**

Today every item goes through a modal (two category selects, title, two date-time pickers,
save). The schedule in question has about 40 items. The Grid view is display-only, shows one
day at a time, and has one column per category in use. Nothing on the ORK produces a
whole-weekend picture.

## 2. Goals, non-goals, anti-goals

**Goals**

1. A **Board** view: every event day side by side on one time axis, bold colour by category,
   overlapping items placed side by side automatically. It replaces today's Grid view. List stays.
2. **Share as image**: one click produces a PNG of the whole board for Facebook or Discord.
3. **Direct entry**: in an explicit Edit mode, type items straight onto the board or into a
   quick line, with a small text grammar that also captures category, location, leads,
   description and cost.
4. **Direct manipulation**: drag, resize, nudge, duplicate, repeat.
5. **Category leads**: staff can be marked lead for one or more categories and are filled in
   on new items of that category.
6. **Starting points**: copy a past event's schedule into this one, or drop in a skeleton.

**Non-goals** (agreed with the owner unless marked)

- Pasting from, or exporting to, Google Sheets or Excel. Dropped by the owner: it keeps
  people in the tool we want them to leave.
- Live multi-editor presence. Two people may edit at once; each change saves on its own and
  the last write to an item wins.
- A private schedule draft separate from the event's existing draft state.
- Claimable open slots ("Class Spot"), and linking leads automatically from a title.
- Category leads granting edit rights. They are a label and an auto-fill only.
- Versioning or history of the schedule.
- Fixing the missing unique key on `ork_event_staff (detail, mundane)` (pre-existing).

**Anti-goals** (would make this a failure even if it works)

- Recreating spreadsheet busywork: choosing columns, merging cells, picking colours by hand.
- A board that is always "live" to stray clicks for managers. Editing is a mode you enter.
- Losing work. Nothing typed may be silently dropped.
- Wiping a feast item's menu, cost, dietary or allergens because the board saved a move.

## 3. Decisions the owner made

- Entry and the picture are both in scope.
- Bold solid fills for blocks.
- One new category for roleplay and quests. Ditch is a Battlegame, tavern is Feast and Food,
  troll and gate are Administrative.
- Category leads on staff: multi-select, several people per category, auto-filled on new
  items, removable per item, no edit rights.
- Sheets paste is out. Go further on direct entry: an edit mode rather than always-on
  click-to-change, `@` to tag a lead with an inline player search, quotes for a description.
- Board replaces Grid; List stays. Phones show one day at a time. The modal stays for full
  details.

## 4. Decisions made on the owner's behalf (my calls)

1. **Edit mode saves as you go.** "Edit schedule" enters the mode and "Done" leaves it. Each
   action saves immediately with undo. There is no held draft and no Publish step, since the
   owner said "no versioning" and gave the reason as not being in click-to-change mode all the
   time. The save layer is a queue, so holding changes until a Publish press could be added
   later without reworking the editor.
2. **Board editing needs "Can manage schedule".** Feast-only staff use the schedule form,
   not the board (see call 45 for what they may change).
3. **A board day runs 5:00am to 5:00am.** A 12:30am Night Quest belongs to the previous
   evening's column, as people think of it. Typing `sat 12:30a night quest` therefore means
   Saturday night, stored as Sunday 00:30. The List and the public embed keep grouping by
   calendar day, so that item reads "12:30am" under Sunday there. Both are true; only the
   Board follows the evening.
4. **Grammar markers:** `@` is for people (owner), so location is written `at Main Field`;
   `#` sets a category; `"…"` is the description (owner); `$8` is a feast item's cost.
5. **The new category is named "Roleplay and Quests"**, matching "Arts and Sciences" and
   "Feast and Food".
6. **"Vigil" is not guessed** into any category (lands in Other). "Ceremony" and "oath" guess Court.
7. **The skeleton stays**, because it is only canned text fed to the multi-line preview.
8. **The list's delete uses the themed confirm** instead of the native `confirm()`.
9. **A category prefix typed in a title is removed** ("BATTLEGAME: Mothman" is stored as
   "Mothman" in category Battlegame), since the colour and label already say it. Only a
   category's own name or one of its `#` aliases counts as a prefix, so "Sir Adler: Train the
   Trainer" and "KL Tournament: Single" keep their full titles.
10. **Auto-filled leads are remembered as such** (a flag on the item's lead). Removing a
    category lead from staff takes them off only the items where they were auto-filled; a
    lead someone picked by hand stays. This came out of the design review: without the flag,
    un-marking Luna as Class lead would also remove her from her own class.
11. **Deleting a feast item you cannot restore asks first.** Someone with "Can manage
    schedule" but not "Can manage feast" cannot write menu, cost or dietary details, so Undo
    could not bring them back. For that person, deleting an item that has meal details asks
    for confirmation and is not undoable, and `$8` is not read as a cost.
12. **A change the server rejects is reverted in view, with a message.** A rejected add puts
    its text back in the quick line so it can be corrected. Network failures offer Retry.
13. **Text is stored as typed.** A location that matches one already used in this schedule,
    ignoring case, takes that existing spelling. Typed times are kept to the minute; only
    dragging and arrow keys snap to 15 minutes.
14. **Copy and paste on the board use one rule.** Copying blocks also puts their text lines
    on the clipboard. Pasting text the board itself copied pastes those blocks at the slot
    cursor; pasting any other text previews it as new items.
15. **Starting from a past event appends**, never replaces, and is one undoable step. The
    shift is the number of days between the two occurrences' first days. Feast items come
    across only for someone who can manage feast.
16. **"Copy as text" is for people to read.** Pasting it back restores everything except
    leads who are not event staff.
17. **A saved view choice is respected.** Board is the default only for someone who has never
    chosen.
18. **Renaming in place changes the title only.** The first build let an existing item be
    edited as a line of text (title, `at location`, `"description"`) and re-read it through
    the grammar. Three review rounds showed that cannot be made lossless: a multi-line
    description was flattened, and a location such as "Hall #2" could leak into the title.
    The grammar is for creating items. On an existing item, Enter or a double-click renames
    it, storing exactly what is typed; location, description and meal details are edited in
    Details; the time by dragging or Details; the category by its key or menu.
19. **"At" before a bare hour is a time.** "Court at 6" means 6pm, not a place called "6".
20. **A session that expired mid-edit says so.** The mode bar tells a signed-out editor to
    sign in in another tab and press Retry; nothing typed is lost.
21. **One rule for a new item's leads.** On every entry path (quick line, on-board typing,
    pasted lines, the full form) a new item's leads are the people picked by hand plus its
    category's leads, marked auto-filled; an auto-filled chip can be removed before adding.
    The back-fill that runs when staff change is deliberately narrower: it only fills items
    that have no lead at all.
22. **An unmatched `@word` is kept as typed.** If no one is picked, the text stays in the
    title on Enter and on clicking away alike.
23. **Only time ranges may be written as four digits** (`0900-1000`). A lone four-digit
    number is never a time, so "2026 Kickoff" keeps its title.
24. **Moving several blocks keeps them together.** A group drag or nudge stops when any
    member would leave the day's hours or the event's days; a single block may still be
    nudged onto a day outside the event.
25. **Done never discards a paste preview.** With a preview open, Done stays in Edit mode
    and asks for it to be added or discarded first. A second preview is refused the same way.
26. **Share as image shows saved items only**, in view-mode hours, even when pressed during
    Edit mode with previews on the board.
27. **Leads copied from another event are hand-picked here** unless that person leads the
    same category on this event, so a later staff change cannot remove them by surprise.
28. **Pasted text is read generously.** Bullets, numbering and bold or heading marks are
    stripped; headings like "SATURDAY 11/21" or "Saturday, November 21" set the day; items on
    days outside the event are added but called out; unreadable lines are listed, never
    guessed.

Calls made while fixing the review of 2026-11-16 (each replaces anything above it conflicts
with):

29. **A save that may have reached the server is resent exactly.** Every batch carries a
    token; the server remembers a token for a day and answers a repeat with the first
    result, applying nothing. The same holds for a copy from a past event. A lost reply can
    no longer duplicate items.
30. **When two organisers change the same item, the first save wins and the second person is
    told.** Each item carries the time it last changed; an update based on an older version
    is not applied, the board shows the other person's version, and a notice says so. An
    item someone else deleted disappears with a notice. The alternative, last write wins in
    silence, is what the review objected to.
31. **The board re-reads the schedule** on returning to the tab and every minute while
    nothing is unsaved, and merges what it finds; undo history survives except for items
    someone else changed.
32. **The server owns the lead rule.** An add without leads gets its category's leads; a
    category change without leads swaps the automatic ones; a lead is only stored as
    automatic when that person really leads that category on this occurrence. The board
    still sends the leads it previewed.
33. **A lead must be an eligible player**, except one already on the item (an old lead who
    has since gone inactive must not make an unrelated edit fail). At most 20 leads an item.
34. **Unmarked evening times.** With `night`, `evening`, `dinner`, `supper`, `feast`,
    `bardic`, `midnight` or `revel` in the title, an unmarked 7 to 11 is pm; with `court`,
    only 7 and 8 are (Sunday-morning court is common); `tavern` is not an evening word
    ("Tavern Opens" at 9am is normal); `breakfast`, `morning`, `brunch` and `lunch` block
    it. In a pasted list, time order decides instead: a time that would fall before the
    previous line's moves to the evening only when the previous line is in the afternoon
    (or the title has an evening word), and past midnight only when the previous line is
    6pm or later. A blank line or heading starts the order afresh. A first version of this
    rule put 27 of the reference sheet's 42 items at the wrong time; the sheet is now a test.
35. **A trailing bare number is never a time** ("Court 7", "Round 2"). In a pasted list a
    bare hour that LEADS a line is its time ("9 Gate opens"); the cost is that a pasted
    "5 pumpkins" becomes "pumpkins" at 5pm.
36. **The server refuses times more than seven days outside the event**, loosely on purpose
    (the board allows items a little outside), and refuses over-long text with a message
    naming the field. An occurrence whose end date is missing or before its start is
    measured from its start date.
37. **The "now" line follows the device's clock**, not the server's: the server is in one
    time zone and events are not.
38. **After midnight, labels name the real day**: "Sunday 1-2a (Saturday night)" in the
    popover and the screen-reader label, and a dashed "Sunday" marker in the picture. The
    item stays in Saturday's column.
39. **The share menu has a day choice** (All days, then each day) that both Download and
    Copy follow; a one-day picture uses wider lanes so it is readable on a phone.
40. **Shift+Enter opens Details** for the selected item, and Tab from a selected item goes
    to its toolbar.
41. **Schedule saves and staff saves run at READ COMMITTED** and lock the item before its
    leads, so two saves wait for each other instead of overwriting or failing.
42. **The Details form takes part in the same check.** It posts the version it opened with;
    if the item changed since, nothing is saved, the form says "Someone else changed this
    item. Close this form and open it again to see their version.", and the page re-reads
    in the background so reopening shows the other person's version. This applies in the
    list view too. Details waits for the item's own pending save before it opens.
43. **A category lead may always lead items in their category**, even if their player
    record has since gone inactive or been suspended (staff management chose them); the
    eligibility check (call 33) applies to everyone else.
44. **A failure that is not about the content of a change offers Retry** instead of undoing
    the change, and a save refused as a whole shows the server's reason.

Calls made for the enhancements the owner chose on 2026-11-16 (review items 6, 18, 20, 30,
31, 34 and 36, and the owner's instruction "if they can edit feast, they can edit feast
blocks"):

45. **Feast editors edit feast items in full.** Someone who can manage the feast but not the
    schedule may add a feast item, change every field of one (times, place, description,
    leads, meals), and remove one, through the schedule form, which they can now open from
    the Feast tab, from feast rows in the list, and from an `Edit` button in a feast item's
    popover. "Feast item" means primary or secondary category Feast and Food, judged on the
    item as stored, under its row lock; the item must stay a feast item, so the form locks
    the category for them. They do not get the board's Edit mode. This closed a hole: the
    old check trusted the category in the request, so a feast-only person could retitle
    any item by posting Feast and Food.
46. **One writer.** The schedule form's save is a thin wrapper over the board's op writer
    (review item 34): it keeps the form's own permission checks, builds one change and runs
    it through the same code, so the form now follows the board's content rules (strict
    times, the event window, end not before start, text limits). The form already posts the
    strict time format, so no real submission is newly refused, except text past the limits,
    which the form's fields now stop at.
47. **At most 500 items a schedule** (review item 30), checked on the board, the form and
    past-event copy. The limit is not locked against two saves arriving together, so it can
    be passed by a few items in a race.
48. **Long Administrative and Feast items are rails** (review item 36): an item whose primary
    category is Administrative or Feast and Food and that lasts four hours or more is drawn
    as a narrow labelled strip down the left of its day, outside the lanes, on the board and
    in the picture. There is no per-item switch; a four-hour "Feast prep" is a rail too.
49. **"Now and next"** (review item 18): during the event, above both the list and the
    board, a strip of what is on now (up to four) and what starts next that day (up to
    three). Never in Edit mode.
50. **One bounds rule** (review item 6): a gesture never leaves the days the board shows or
    creates a new day; a move with no room does nothing. The store refuses a time outside
    the server's window, or an end before a start, at once. A weekday the event does not
    have means the nearest such date (`thu` on a Friday-to-Sunday event is the Thursday
    before), and the person is told it is outside the event's dates.
51. **"Edit schedule" shows in the list view too** (review item 20) and switches to the board.
52. **Fewer queries** (review item 31): a save costs two statements per item plus a fixed
    22, down from about six per item.

Left for the owner to decide or try by hand:

- The new migration (`2026-11-16-event-schedule-batch-tokens.sql`) must run BEFORE this
  code is deployed: the board sends a token with every save and the server needs the new
  table to accept it, so until the migration runs nothing saves from the board.
- The pre-commit hook now runs the JavaScript unit tests when board scripts or `tests/js/`
  are staged (skipping with a note below Node 21). It affects every contributor.
- Feast-only behaviour is covered by server tests and by a one-off browser check, not by
  the browser suite: the suite's test account cannot safely demote itself.
- Production's binary-log setting must be checked before release: with binary logging on
  and `binlog_format=STATEMENT`, MariaDB refuses writes made at READ COMMITTED (call 41) and
  every schedule save would fail. MIXED, the default, is fine.
- A save of several items in descending id order can still deadlock against a staff lead
  change made at the same moment; the loser gets an error and their edit is reverted. The
  fix is for a batch to take its row locks in id order before its first change.
- `EventAjax` has no CSRF token on any endpoint, old or new; the new batch endpoint can
  remove up to 200 items in one request. Adding a token is a separate change to that
  controller as a whole.
- Copying and pasting blocks in Safari, and typing at the keyboard cursor with an input
  method, could not be tested automatically.
- A real "Start from a past event" copy was exercised in server tests and with mocked
  responses in the browser, but not end to end in a browser, because the local test park
  has no past event with a schedule.

## 5. Constraints

- Layers: SQL only in `system/lib/ork3/`; `orkui/model/` is the only way through; templates
  are plain PHP (`.tpl`), not Smarty. Never add entry points under `template/default/`.
- Schedule times are **naive local datetimes** (`DATETIME`, server zone fixed, no per-event
  zone). Client code must treat them as strings and do its own arithmetic. It must never
  build a `Date` from a schedule time and read it back in the browser's zone.
- `db->query()` never returns false and `Execute()` returns nothing, so the checks in
  `EventPlanning::syncCalendarDetailFeesAndLinks` cannot see a database failure and must not
  be copied. Transactions use `BeginTrans` / `CommitTrans` / `RollbackTrans` and writes use
  `ExecuteChecked` (`system/lib/Yapo2/class.YapoMysql.php`). `DataSet()` needs `Clear()`
  before and `->Next()` after.
- No native `alert` / `confirm` / `prompt`. Tooltips use `data-tip`, never `title`.
- Dark mode via `html[data-theme="dark"]`, checked in both themes. Global `orkui.css`
  heading and control rules win, so check computed styles.
- Player search uses the custom `kn-ac-results` dropdown pattern, always scoped, reusing the
  endpoint, scoping and debounce the schedule modal's lead search already uses
  (`revised.js` around 9904-9995).
- Dates shown to people are human-readable.
- New migrations need an entry in `tools/ork-db/manifests/migration-classification.json5` and
  must be applied to both `ork` and `ork_test`.
- The pre-commit hook reformats staged PHP and unstages `class.Authorization.php`. That file
  must never be staged. Never `git add -A`.
- There is no JS unit-test harness and no CSRF token on `EventAjax`; this design adds the
  first and does not change the second.

## 6. Approaches considered

| Approach | What it is | Trade-off | Outcome |
|---|---|---|---|
| Type on the board | Click a time, type, Enter; drag and resize | The spreadsheet gesture without the busywork; biggest front-end build | **Chosen**, core |
| Quick line | One text input with a small grammar and a live preview | Fast, works on phones, needs a parser | **Chosen**, shares the parser with the board |
| Paste a sheet | Read merged cells and fills from a copied Sheets range | Bridges people who draft in Sheets; keeps them in Sheets | Dropped by the owner |
| Embedded spreadsheet grid | A real cell grid with merges | Reproduces the busywork; always a worse Sheets | Dropped |
| Read a screenshot | OCR or an AI service | Fragile or paid | Dropped |
| Editable row table | Tab through rows of fields | Faster than a modal, but gaps and overlaps are invisible | Dropped |

The owner picked "all three" of the first three, then removed the sheet paste and asked for
direct entry to go deeper (edit mode, `@` leads, quoted descriptions).

## 7. What people see

### 7.1 View mode (everyone)

```
[ Board | List ]   ● Battlegame ● Class ● Feast …        [Copy link] [Share as image] [Edit schedule]

        FRIDAY                SATURDAY                              SUNDAY
 9a  ┌────────────────┐   ┌─────────┐┌─────────┐                ┌────────────┐
     │ Crat Setup     │   │ Tavern  ││ Troll   │                │ Tavern     │
10a  │ 9–12p          │   ├─────────┤├─────────┤┌──────┐┌─────┐ ├────────────┤
     │                │   │ Squatch ││ KL      ││Class ││ A&S │ │ Squatch    │
```

- Every calendar day of the occurrence is a column, plus any other day that has items.
- One shared time axis, hour labels in a sticky left gutter, sticky day headers.
- A block shows the title, the time, and when it is tall enough the location and lead names.
  Fill is the primary category's bold colour with white text. A secondary category shows as a
  stripe on the right edge. A feast item with a menu keeps its menu marker.
- Overlapping items share the day's width in lanes. An item with nothing beside it spans the
  full day. Nobody chooses a lane.
- The legend lists only categories in use. Clicking one dims everything else; clicking again
  clears it.
- Clicking a block opens a popover with the full details (time, location, leads linked to
  profiles, description with links, menu and dietary for feast items).
- "Now" line on the current day, as today.
- Deep links keep working: `#ev-tab-schedule?view=board&day=20261121`. Old `view=grid` links
  open the board.
- Desktop and tablet default to Board when there are items. Phones (700px and under) default
  to List as today; choosing Board there shows one day with the existing day pills.
- With no JavaScript, List is what renders.

### 7.2 Share as image

"Share as image" offers a choice of **All days** or one day, then **Download PNG** and,
where the browser allows, **Copy image**, both for that choice (it starts on the day in
view). A one-day picture uses wider lanes. The image is drawn directly onto a canvas from the same layout
data (not a screenshot of the page): event name, date range, location, the board, the
legend, and the event's ORK address. It always uses a white background and is drawn at 2x.

### 7.3 Edit mode (people who can manage the schedule)

"Edit schedule" switches the board into Edit mode:

```
 Editing schedule · All changes saved          [Undo] [Redo] [?]            [Done]
 ┌──────────────────────────────────────────────────────────────────────────────┐
 │ sat 9:30-10:15 bg: squatch ball at main field @Lu▌                           │
 └──────────────────────────────────────────────────────────────────────────────┘
   Saturday · 9:30–10:15a · Battlegame · "squatch ball" · Main Field · @Sir Warcrat (auto)
                                              ┌ Event staff ──────────────┐
                                              │ Luna Honeybadger · Classes│
                                              │ Lucian (CG:NH)            │
```

- A mode bar shows the save state ("Saving…", "All changes saved", "Couldn't save · Retry"),
  Undo, Redo, a cheat-sheet popover, and Done.
- The quick line appears above the board (press `/` to focus it).
- The legend shows the number key for each category.
- "Done" flushes pending saves, refreshes the List and Feast tabs, and returns to view mode.
  Leaving the page while a save is pending or failed asks first (the browser's own
  unload prompt, the one case where it is unavoidable).
- An empty schedule shows "Build the schedule" (enters Edit mode with the quick line
  focused) and, once phase 5 ships, "Start from a past event".
- In view mode, someone who can manage the schedule sees an "Edit" link in a block's popover,
  which opens the modal for that item.
- "Add Schedule Item" (the modal) stays available.

### 7.4 The entry grammar

One parser serves the quick line, typing inside a block, and pasted lines.

| You type | Meaning |
|---|---|
| `sat`, `saturday`, `11/21` (first token only) | Day. Omitted: the last day used. Not read inside a block, whose day is fixed |
| `9:30-10:15`, `9a-12p`, `3-4`, `10 to 1` | Time range |
| `3p`, `3:15`, `15:00`, `noon` | Start; length is the last length used |
| `45m`, `1h`, `1.5h`, `1h30m`, `+45` | Length; start is where the last item ended |
| `then` | Start where the last item ended, same day |
| `#class`, `#bg`, `#rp` | Category (a second `#` is the secondary category) |
| `@name` | Lead: opens the inline player search |
| `at Main Field` | Location |
| `"Come learn the Viking art of Naalbinding."` | Description |
| `$8` | Cost, only on a Feast and Food item and only for someone who can manage feast |
| anything else | Title |

- **am/pm when not written:** 7 to 11 is morning; 12 and 1 to 6 are afternoon; an end not
  after its start moves 12 hours later, then to the next day. One written marker carries to
  the other side sensibly (`10-1p` is 10am to 1pm).
- **Bare number ranges** (`9-12`, no colon or am/pm) count as a time only as the first or
  last token, so "Level 1-3 Tournament" stays a title.
- **Category without `#`** is guessed from the title (section 8.3). A leading "Class:" style
  prefix sets the category and is removed from the title.
- **Location** is the last standalone `at` not followed by a time, plus up to six words.
  Typing ` at ` also suggests locations already used in this schedule.
- **Description:** the quoted span after the title. An opening quote with no close takes the
  rest of the line, so it previews as you type.
- **Chips under the input** show what was understood: Day, Time, Category, Title, Location,
  Leads, Description, Cost. Guessed values look dimmer than written ones. Dismissing a chip
  (its ×) tells the parser to treat that text as title instead.
- **Leads:** typing `@` lists event staff at once, then player search results as you type
  (names may contain spaces). Up/Down, Enter or Tab picks; Esc leaves the text as typed.
  A picked lead leaves the text and becomes a chip. While a dropdown is open, Enter picks
  and does not add the item.
- **Category leads** appear as chips marked "auto" as soon as the category is known, and can
  be removed before the item is added.
- Enter adds the item. A line with no title cannot be added.

### 7.5 On the board

| Gesture | Result |
|---|---|
| Hover empty space | Faint 15-minute slot highlight |
| Click empty space | An editing block at that time, last length used; type and Enter |
| Press and drag on empty space | The same, with the dragged length |
| Type a time or length first inside a block | Re-times the block live |
| Enter | Adds it and opens the next block right after it, same length |
| Tab | Adds it and opens another at the same start (a parallel item) |
| Esc, or Enter on an empty title | Cancels and ends the run |
| Arrow keys with nothing selected | Move the slot cursor; typing starts an item there |
| Click a block | Select it; a small toolbar offers category, Details, Duplicate, Delete |
| Shift-click | Add to the selection |
| Up / Down | Move the selection 15 minutes |
| Shift+Up / Shift+Down | Change the end by 15 minutes |
| Left / Right | Move to the previous or next day |
| `1`–`9`, `0` | Set the category |
| Enter or double-click | Rename the item in place (title only) |
| Delete / Backspace | Remove (no confirm; Undo restores it) |
| Cmd/Ctrl+D | Duplicate directly after |
| Cmd/Ctrl+C, Cmd/Ctrl+V | Copy the selection; paste at the slot cursor |
| Cmd/Ctrl+Z, Shift+Cmd/Ctrl+Z | Undo, redo |
| Drag a block | Move within or across days, snapping to 15 minutes (Alt: 5) |
| Drag the top or bottom edge | Resize |
| Drag the corner handle down | Repeat the item at its length plus the gap to the previous item of the same category that day (30 minutes if none) |

- Renaming an item in place edits its title only, stored exactly as typed, with no grammar
  applied (call 4.18). Its leads show as chips that can be removed, or added with `@`.
- "Details" opens the existing modal for everything else on an existing item (location,
  description, menu, dietary, allergens).
- Changing an item's category replaces its auto-filled leads with the new category's leads.
  Leads picked by hand stay.
- A day or time typed between midnight and 5am belongs to the named day's night (call 4.3).
- Touch: no dragging. Tapping empty space fills the quick line with that day and time;
  tapping a block shows the toolbar.
- Keyboard shortcuts apply only while the board has focus and no text field is active.

### 7.6 Category leads (Staff tab)

- The staff modal gains "Category lead for": the categories as colour chips that toggle.
- The staff table gains a "Leads" column showing those chips.
- Saving a staff member returns how many existing items changed, shown beside the table:
  - **Lead added:** they are added, marked auto-filled, to items in this occurrence whose
    primary category is that category and that have no lead yet.
  - **Lead removed, or the staff member removed:** they are taken off items whose primary
    category is that category, but only where they were auto-filled.
- The schedule modal applies the same auto-fill when adding an item: choosing a category
  fills its leads if the lead list is untouched.
- Copying an event with the Staff module copies category leads.

### 7.7 Starting points and bulk

- **Paste lines of text.** Pasting several lines (into the quick line, or on the board with
  nothing focused) parses each line and shows them all as dashed ghost blocks with a bar:
  "12 items ready · Add all · Discard", listing lines that could not be read. A line that is
  only a day name sets the day for the lines after it. `@Name` in pasted text is matched
  against event staff by persona (the longest staff persona that follows the `@`, ignoring
  case); unmatched names are listed and left off.
- **Copy as text.** Puts the schedule on the clipboard as those same lines under day
  headings, for Discord or email. Pasting it back restores everything except leads who are
  not event staff.
- **Start from a past event.** Pick another occurrence of this event, or a past event in the
  same scope (its latest occurrence); its schedule is appended to this occurrence, shifted by
  the number of days between the two first days so clock times are kept. Feast items are
  included only for someone who can manage feast. It is one undoable step.
- **Skeleton.** Drops the usual bones (troll, tavern, feast, court, clean-up) across the
  event's days as ghosts to adjust and add.
- **Conflict hints** in Edit mode: a small warning on blocks where the same lead, or the same
  named location, is in two places at once.

## 8. How it is built

### 8.1 Files

New, all under `orkui/template/revised-frontend/`:

| File | Responsibility | Tested by |
|---|---|---|
| `script/event-board/evb-core.js` | Pure logic: naive time maths, board-day bucketing, lane layout, category guess, `parseEntry`, `formatEntry`, conflict detection. No DOM. Loads in the browser as `window.EvBoardCore` and in node via `module.exports` | `node --test` |
| `script/event-board/evb-store.js` | Items, selection, operations, undo/redo, the save queue. Transport is injected. No DOM | `node --test` with a fake transport |
| `script/event-board/evb-view.js` | Renders the board, legend, day pills, popover, now line; view switching and deep links | Playwright |
| `script/event-board/evb-entry.js` | The entry widget (input, chips, `@` / `#` / location dropdowns), used by the quick line and by blocks | Playwright |
| `script/event-board/evb-edit.js` | Edit mode: mode bar, slot cursor, pointer and keyboard interactions, block toolbar, ghosts, bulk bar | Playwright |
| `script/event-board/evb-image.js` | Canvas PNG export | Playwright smoke (a blob of plausible size) |
| `style/event-board.css` | All board CSS, light and dark, classes prefixed `evb-` | |

They are included from `Eventnew_index.tpl` after `revised.js`, each with the
`?v=<?= filemtime(...) ?>` cache-bust used by `rank-pill.css`. No bundler.

Tests: `tests/js/*.test.js` on node's built-in runner, wired as
`"test:js": "node --no-maglev --test \"tests/js/**/*.test.js\""` (the flag works around a
known V8 problem on this machine's node; a bare directory argument does not work on Node
26). One Playwright spec, `tests/e2e/event-schedule-board.spec.ts`.

Removed from `Eventnew_index.tpl`: the server-rendered grid (about 1383-1556), its
grid-only CSS and JS (within about 3961-4590) and its per-category dark rules. That range
also holds the phone List-card styles (about 4170-4272), which stay, as do the list's
category filter pills.

### 8.2 Data the page hands the board

Added to `EvConfig`:

- `schedule`: the list as today's `fetchScheduleList` returns it (`EventScheduleId`, `Title`,
  `StartTime`, `EndTime`, `Location`, `Description`, `Category`, `SecondaryCategory`, `Leads`,
  `Menu`, `Cost`, `Dietary`, `Allergens`).
- `scheduleCategories`: the ordered category definitions (8.3).
- `staffList` entries gain `RoleName` and `LeadCategories`.
- The "now" line reads the device's own wall clock (call 37); the page no longer sends the
  server's time. This is the one place the board reads a clock in the browser's zone.
- `scheduleBatchMax`: the most changes one save may carry, from the server's constant.
- Every schedule row carries `Modified`, the time it last changed (call 30).
- Each lead in an item's `Leads` gains `Auto` (true when auto-filled from a category lead).

In the browser an item's times are kept as `'YYYY-MM-DD HH:MM'` strings. `evb-core` converts
to a day key plus minutes with its own arithmetic.

### 8.3 Categories from one place

`EventPlanning::ScheduleCategories()` returns the ordered definitions; `SCHEDULE_CATEGORIES`
is derived from it. It reaches templates through `model.Event` and the controller as
`$ScheduleCategories`, and reaches JS as `EvConfig.scheduleCategories`. The template palette,
both modal selects, `EV_CATEGORIES` and the filter order in `revised.js` all read from it.
The embed widget stays standalone by design and gets the tenth colour by hand.

| # | Category | Bold fill | Soft bg (list) | Icon |
|---|---|---|---|---|
| 1 | Administrative | `#546e7a` | `#eceff1` | `fa-clipboard-list` |
| 2 | Tournament | `#8a6500` | `#fffde7` | `fa-trophy` |
| 3 | Battlegame | `#c0392b` | `#fdecea` | `fa-shield-alt` |
| 4 | Arts and Sciences | `#7b1fa2` | `#f3e5f5` | `fa-palette` |
| 5 | Class | `#1565c0` | `#e3f2fd` | `fa-graduation-cap` |
| 6 | Feast and Food | `#bf4d00` | `#fff3e0` | `fa-utensils` |
| 7 | Court | `#4e342e` | `#efebe9` | `fa-crown` |
| 8 | Meeting | `#276749` | `#f0fff4` | `fa-users` |
| 9 | Roleplay and Quests | `#c2185b` | `#fce4ec` | `fa-dragon` |
| 0 | Other | `#616161` | `#fafafa` | `fa-star` |

Each definition also carries the existing accent colour and a dark-theme soft background.
List rows take their soft backgrounds from CSS variables set on the row, replacing the
per-category `!important` dark rules. Bold fills must keep white text at 4.5:1 or better.

Guessing (client only; the server stores what it is given). A leading prefix (a category's
name or one of the `#` aliases below, followed by a colon) wins and is removed from the
title; otherwise the first match in this order, whole words, case-insensitive, optional
trailing "s". Nothing else is ever removed from a title:

1. Arts and Sciences: a&s, arts and sciences, judging, dragonmaster
2. Feast and Food: feast, dinner, lunch, breakfast, brunch, potluck, tavern
3. Court: court, coronation, knighting, ceremony, oath
4. Tournament: tournament, tourney, warmaster, weaponmaster, bracket
5. Class: class, workshop, seminar
6. Roleplay and Quests: quest, rp, roleplay, role play
7. Battlegame: battlegame, battle game, bg, militia, ditch
8. Meeting: meeting, althing, allthing
9. Administrative: troll, gate, setup, set up, clean, cleanup, teardown, tear down, check-in,
   checkin, offsite, crat

`#` aliases: admin, tourney, bg, battle game, as, a&s, class, feast, food, court, meeting,
rp, roleplay, quest, other, plus any unique prefix of a category name.

### 8.4 Board days, axis and lanes (`evb-core`)

- **Board day:** an item belongs to day D when its start falls in [D 05:00, D+1 05:00). An
  item that crosses a boundary is split into one segment per board day, marked as continuing,
  as the list does for calendar days today.
- **Axis:** one range for all days, from the earliest segment start floored to the hour to
  the latest end rounded up. In Edit mode it is at least 7:00am to midnight and grows when an
  item is placed outside it.
- **Lanes, per day:** sort by start then end; group into clusters of items that overlap
  directly or through others; within a cluster give each item the first free lane, preferring
  a lane last used by its own category and avoiding a lane whose category needs it again
  before this item ends; then widen each item rightwards over free lanes. A day's width
  weight is its widest cluster (minimum 1.4), with a minimum lane width and horizontal
  scrolling beyond that.
- **Fixture:** the published sheet (in the mockup at
  `.superpowers/brainstorm/…/content/board-look.html`) must lay out as 2 lanes Friday, 4
  Saturday, 1 Sunday.

### 8.5 Saving (`evb-store` and the server)

**Client.** Every change is an operation: add, update (a subset of fields), remove. Applying
one updates the board at once, records its inverse for undo, and queues it. The queue sends
one request at a time and merges consecutive updates to the same item while waiting. Items
carry a stable client id (`cid`) so undo and redo survive the server assigning or
reassigning ids (undoing a delete re-creates the item under a new server id). Operations
name items by `cid`; the server id is filled in when the request is sent, so an edit to an
item whose add is still queued is merged into that add, and one whose add is in flight
simply waits its turn. Undo history lasts for the Edit session. On entering Edit mode and
when the window regains focus with nothing pending, the list is re-fetched to pick up other
people's changes.

Failures come in two kinds:

- **Could not reach the server** (network error, 5xx, not logged in): the queue stops, the
  affected blocks are marked, and the mode bar offers Retry.
- **The server said no** (status 1 or 3: validation or permission): retrying cannot help.
  The operation, and anything queued after it for the same item, is undone on the board,
  and the mode bar says what was reverted and why until dismissed. A rejected add puts its
  text (`formatEntry`) into the quick line. The queue then continues.

Deleting an item with meal details as someone who cannot manage feast asks first with the
themed confirm and records no undo (call 4.11).

**Endpoint.** `POST EventAjax/schedule_batch/{eventId}/{detailId}` with form field `Ops`, a
JSON array of at most 200:

```
{"op":"add",    "cid":"c12", "item": {Title, StartTime, EndTime, Category, …, Leads:[{MundaneId}]}}
{"op":"update", "id": 881,   "fields": {StartTime, EndTime}}
{"op":"remove", "id": 881}
```

Success: `{"status":0,"results":[{"op","cid","id","schedule":{…}}]}` in order. Failure:
`{"status":N,"error":"…","failedIndex":i}` and nothing is applied.

**Domain.** `EventPlanning::ApplyScheduleOps` runs the batch in one transaction
(`BeginTrans`, `ExecuteChecked`, `RollbackTrans` on the first failure) and requires
"manage schedule" for the whole batch. Its rules:

- The existing `add_schedule`, `update_schedule` and `remove_schedule` actions and
  `saveEventSchedule` keep today's full-replace behaviour, because their controller cannot
  tell an absent field from an empty one. Only their ownership gaps are fixed (next point).
- A batch update is a true partial update: only the fields present change. Leads are
  replaced only when `Leads` is present. Menu, cost, dietary and allergens change only when
  present and the caller can manage feast; otherwise they are left exactly as stored. Start
  and end are validated together against the stored values when only one is sent.
- Every update, remove, lead write and stored-row read first confirms the schedule id
  belongs to the occurrence (today the lead writes and the meal-field read filter by id
  alone).
- Each result's `schedule` is the row re-read after the write, in the same shape as the
  page's list.
- A lead entry may carry `Auto: 1`; it is stored on the lead row.

`GET EventAjax/schedule_list/{eventId}/{detailId}` requires "manage schedule" and returns
`{status, schedule, staff}` for refreshes in Edit mode.

**Keeping the other views honest.** The modal's save and delete paths, which today call
`evRefreshScheduleGrid`, instead notify the board store. On "Done" the page is re-fetched
and `#ev-schedule-container`, `#ev-meal-list` and the tab counts are swapped in, the same
technique the grid refresh uses today. "Details" opens the modal from a plain item object
(as `evOpenFeastEditModal` does), not from a list row.

### 8.6 Category leads (server)

Migration `db-migrations/2026-11-15-event-staff-category-leads.sql` (idempotent, class `S`):

```
ork_event_staff_category (
  event_staff_id INT NOT NULL, category VARCHAR(50) NOT NULL,
  PRIMARY KEY (event_staff_id, category),
  FOREIGN KEY (event_staff_id) REFERENCES ork_event_staff (event_staff_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci

ALTER TABLE ork_event_schedule_lead ADD COLUMN IF NOT EXISTS is_auto TINYINT(1) NOT NULL DEFAULT 0
```

The collation must match `ork_event_schedule.category`, or joining on category fails.

- `AddEventStaff` accepts `LeadCategories` (validated against the category list), replaces
  the staff member's rows, applies the fill and removal rules of 7.6 in the same transaction,
  and returns `LeadsAdded` and `LeadsRemoved` counts. Fills are written with `is_auto = 1`;
  removals touch only `is_auto = 1` rows.
- `RemoveEventStaff` applies the removal rule before deleting.
- `fetchStaffList` returns `LeadCategories`. `fetchScheduleList` returns each lead's `Auto`.
  `copyStaffModule` copies category leads and `copyScheduleModules` copies `is_auto`.
- Authorisation for staff changes is unchanged.

### 8.7 Copy into an existing occurrence

`EventPlanning::CopyScheduleInto` (token, event, occurrence, source occurrence) requires
"manage schedule" on the target and view access to the source under the same scope rule as
`ListCopySourceEvents`. It appends, and returns the new rows so the client can record one
undo step. `copyScheduleModules` today adds a number of seconds to each time, which moves
everything by an hour across a daylight-saving change, and returns nothing; it is reworked
to shift by a count of days on the calendar date (keeping the clock time) and to return the
new ids. `CreateEventWithCopy` keeps its current behaviour by passing its shift through the
same function. A companion list call returns candidate sources: other occurrences of this
event first, then the latest occurrence of each event from `ListCopySourceEvents`.

### 8.8 Phases

Each phase ships on its own and leaves the page working.

1. **Board view.** Category source and the tenth category; `EvConfig` data; `evb-core` time,
   board-day and lane logic with tests; `evb-view`; CSS in both themes; phones; deep links;
   Grid removed; Share as image. Because the Grid was how an editor opened an item, the
   popover's "Edit" link opens the modal, and the board re-renders after every modal add,
   edit or delete and after a Feast-card delete.
2. **Edit mode and typed entry.** Batch endpoint and partial updates with tests; `evb-store`
   with tests; Edit mode shell; the parser with tests; the entry widget as quick line and
   in-block editor (day, time, `#`, `at`, quotes, `$`); click and drag to create; select,
   delete, category keys; undo and redo; Details bridge; list refresh on Done.
3. **Leads.** Category leads on staff end to end, including the auto-filled flag; `@` inline
   search; auto-fill everywhere, including the modal; leads following a category change.
4. **Direct manipulation.** Drag, resize, keyboard nudge, duplicate, copy and paste, fill
   handle, multi-select, slot cursor.
5. **Starting points and bulk.** Paste lines with ghosts; Copy as text; start from a past
   event; skeleton; conflict hints.

At release: a What's New entry and an in-app docs page. Not part of this build.

## 9. Testing

- **PHP integration** (`tests/Integration/EventPlanningTest.php` and its fixture): batch
  add/update/remove; rollback on a failing op; partial update leaves meal fields and leads
  alone; an id from another occurrence is rejected; the tenth category saves and an unknown
  one still becomes Other; category leads save, fill, remove and copy; copy-into shifts by
  whole days.
- **JS unit** (`tests/js`): time maths across midnight and the 5am boundary; lane layout
  against the sheet fixture; every grammar row in 7.4 and the am/pm rules; category guesses
  including the owner's rulings; `formatEntry` round-trips through `parseEntry`; store
  operations, undo/redo, queue merging and failure handling.
- **Browser** (Playwright, one spec, grown per phase): the board renders for a signed-out
  viewer; Edit mode is offered only with the permission; quick-line add persists after
  reload; type-Enter-Enter on the board; drag and resize persist; Share as image yields a
  PNG; dark theme and a 390px viewport render without overflow.
- During the build, run unit tests and the phase's own specs. The full e2e suites run near
  merge only.

## 10. Left to the builder

- Exact markup, class names and pixel sizes; how sticky headers and the scroll container are
  built; pointer-capture details; auto-scroll while dragging.
- How the new id is read back inside the batch transaction.
- Whether the chips' dismiss state is kept per chip or as flags on the entry.
- Canvas text wrapping and the image's exact proportions.
- Whether the quick line highlights recognised spans in the text as well as showing chips.
- The skeleton's exact items and times.
