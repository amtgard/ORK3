# Court Planner — deeper scroll & regalia statuses, and status bars

**Status:** design agreed in conversation 2026-10-26. This builds on the Resource Planning tab (`2026-10-26-court-planner-resource-planning-design.md`).

## Intent

The person running court logistics needs to know more than "tracked or not". For each scroll and each piece of regalia they need to know where it stands:

- nobody has it yet
- it is going to Scrollery
- an artisan has agreed to make it
- it is being made
- it is done
- it was never needed

They also want a single picture of how far along the whole court is. That picture is one stacked bar for scrolls and one for regalia, in the style of the example the user shared: coloured status blocks, with each item a thin slice.

## Goals

- One status list, shared by scrolls and regalia, in this order (the user's):
  1. **Pending**, which was "Not Tracked"
  2. **Scrollery Print**, scrolls only, unchanged
  3. **Assigned**, which was "Custom Design"
  4. **In Progress**, new
  5. **Complete**
  6. **Not Needed**, new
- Assigning an artisan moves a **Pending** item to **Assigned**. Clearing the artisan from an **Assigned** item moves it back to **Pending**. Both rules hold on both tabs, for both types.
- Two horizontal stacked bars at the top of Resource Planning, **Scrolls** and **Regalia**:
  - Each bar holds a block per status, left to right Pending → Scrollery Print → Assigned → In Progress → Complete, then Not Needed in muted grey at the far right.
  - Within each block, every award line is its own slice.
  - Clicking a block filters the grid to the lines in that status.
- Every status label, colour and rule comes from one shared definition, so the next status is a one-line addition.

## Non-goals

- Due dates, notes per status, or artisan workload views.
- Changing the Scrollery print flow. Scrollery Print → bulk print → "Set these scrolls to complete?" → Complete behaves exactly as now.
- New endpoints.

## Anti-goals

- **Two definitions of a status drifting apart.** Today about 12 places each hard-code the statuses:
  - PHP: `cp_track_label`, `cp_track_chip`, the status bar's `$_stat_html`, and `Court::allowedTrackingStatuses`
  - JS: `cpTrackLabel`, `CP_TRACK_STATES`, `CP_TRACK_HINTS`, `cpRefreshStatusBar` counts, and the RP helpers `cpRpCounts`, `cpRpMatches`, `cpRpSummaryHtml`, `cpRpNeeds*` and `cpRpTrackAssigned`
  - CSS: per-`data-status` rules

  After this change, all of these read one definition.
- **A chip, the bar and the grid disagreeing about the same line.**

## Data model

`ork_court_award.scroll_status` and `regalia_status` stay tinyint. Existing values keep their numbers, so most data needs no change:

| Value | Label | Scroll | Regalia | Meaning |
|---|---|---|---|---|
| 0 | Pending | ✓ | ✓ | Needed; nobody has it yet (the default) |
| 3 | Scrollery Print | ✓ | — | Goes in the bulk Scrollery print |
| 1 | Assigned | ✓ | ✓ | An artisan has agreed to make it |
| 4 | In Progress | ✓ | ✓ | Being made |
| 2 | Complete | ✓ | ✓ | Done |
| 5 | Not Needed | ✓ | ✓ | This line doesn't get one |

The table is in display order; the values are not.

- `Court` constants are renamed to match: `SCROLL_PENDING=0`, `SCROLL_ASSIGNED=1`, `SCROLL_COMPLETE=2`, `SCROLL_SCROLLERY=3`, `SCROLL_IN_PROGRESS=4`, `SCROLL_NOT_NEEDED=5`, and the same for `REGALIA_*` without Scrollery. Keep the old names as aliases only if something outside the Court Planner uses them; otherwise rename every use.
- `allowedTrackingStatuses('scroll')` is {0,1,2,3,4,5}. `allowedTrackingStatuses('regalia')` is {0,1,2,4,5}.
- **One data backfill migration** (classified `PB`/`skip` in `tools/ork-db/manifests/migration-classification.json5`):
  - Regalia currently at 1 ("Needed") **with no regalia maker** becomes 0 (Pending): "Needed" with no artisan was always "nobody has it yet". Regalia at 1 with a maker stays 1, now Assigned.
  - Scroll at 1 ("Custom Design") stays 1 (Assigned) whether or not a maker is recorded, because that label already meant "an artisan is making it". A scroll at Assigned with no maker recorded is flagged in the grid (below).
- **Starting state (the user's decision):** every existing 0 stays 0 and reads as Pending, and new lines keep starting at 0 (Pending). On an open court, every untouched item therefore counts as needing an artisan until someone marks it Not Needed or assigns it. That is intended, and completed courts get no backfill.
- **Release Feature Utilization report** (`class.Report.php` ~6690): its "tracked" counts are `status > 0` and its "done" counts are `= 2`. Both still mean what they meant ("moved off the default" / "complete"), so leave them alone.

## Behaviour

**The shared definition.** PHP builds one structure per type from the `Court` constants and emits it to the page as JSON. Each entry is `{value, label, hint, group, needsArtisan}`:

- `group` is one of `pending`, `print`, `assigned`, `inprogress`, `done` or `notneeded`. It drives colour, bar position and filtering.
- `needsArtisan` is true for Pending only.

Everything else reads this structure:

- the picker (options and hints), chip labels and `aria-label`s
- the status bar counts, the Printing List and Prep Sheet groupings, the About legend and the court script
- the RP bars, legend, filters and "needs an artisan"
- the server's allowed-status check, which reads the same constants

`Court_record.tpl` passes statuses through; check that its labels, if it shows any, come from the same place.

**Colours.** Each group has one colour, used by the chip, the picker dot, the bar block and the legend, in light and dark themes:

| Group | Colour |
|---|---|
| pending | neutral slate grey |
| print | blue (the existing Scrollery colour) |
| assigned | purple (the existing Custom Design colour) |
| inprogress | amber |
| done | green (the existing Complete colour) |
| notneeded | pale grey, visibly lighter than Pending, with a hatched or striped fill so it never reads as Pending |

This applies to both types, so regalia "Assigned" becomes purple; its old red for "Needed" goes away. Check the colours against the dataviz skill's contrast and validator guidance.

**Picker.** It lists all statuses for the type in display order, with hints:

| Status | Hint |
|---|---|
| Pending | "Needed — nobody has it yet" |
| Scrollery Print | "Goes in the bulk print" (unchanged) |
| Assigned | "An artisan has agreed to make it" |
| In Progress | "Being made" |
| Complete | none |
| Not Needed | "This line doesn't get one" |

Scrollery Print stays disabled where the award can't be printed, exactly as now.

**Maker rules, on both tabs, both types:**

- **Assign** a maker while the status is Pending → the status moves to Assigned, as a second queued write after the maker write.
  - This now also applies to the Order's expand-panel Save. When the Save sets a maker on a Pending item, queue the status write after it.
  - In every other status, assigning changes nothing.
- **Clear** the maker while the status is Assigned → the status moves back to Pending. In every other status, clearing changes nothing.
- **Scrollery Print** still has no maker; the server clears it, as today.

**"Needs an artisan"** means the item is Pending, Assigned or In Progress **and has no maker recorded**, on a line that isn't skipped. (My call, settled from the builder check.)

- A Pending item that already has a maker isn't flagged. Picking Pending in the picker never clears a maker.
- An Assigned or In Progress item with no maker *is* flagged. This covers the legacy Custom Design scroll with no maker, and a maker cleared while the item is In Progress.
- Scrollery Print, Complete and Not Needed are never flagged.
- No status choice is blocked for lack of a maker. The grid flags it, and the server does not validate it.
- A flagged item shows the existing left-edge accent, and its maker cell reads "Assign…" in the needs state.
- Assigning a maker while the item is Not Needed, In Progress or Complete changes no status.

**A line is complete** when each of its two items is Complete or Not Needed. (A Pending scroll that will go to Scrollery still needs a decision, so it counts as Pending.)

## The status bars (Resource Planning)

- **Layout:** two rows at the top of the RP panel, replacing the old summary strip. Each row has:
  - a label on the left: "Scrolls" or "Regalia", with the line count
  - a full-width bar
- **Blocks:** each bar is split into one block per status, in display order, with Not Needed last. The block widths are proportional to their counts. Inside a block, each line is a slice divided by a 1px gap in the page background colour, as in the user's example.
  - A status with zero items has no block.
  - Regalia never has a Scrollery Print block.
  - An empty court shows an empty grey track reading "No awards yet".
- **Legend:** one row under the bars, listing every status present with its colour swatch and its counts for scrolls and regalia, for example "Assigned 4 · 2". The "N need an artisan" callout stays beside it as a clickable filter.
- **Hover/focus on a slice:** a `data-tip` tooltip shows "Recipient — Award (rank)", plus "· <maker>" if one is assigned.
- **Click on a block or slice:** filters the grid to lines whose item of that type has that status, for example "Regalia · In Progress".
  - The active block gets an outline, like the selected segment in the user's example.
  - The filter shows as a removable chip above the grid.
  - Clicking the same block again, or the chip's ×, clears it.
- **Filter chips** are reduced to: All · Needs an artisan · Complete lines. The per-status filters come from the bars.
- **Keyboard:** each block is a focusable button (not each slice) labelled "Scrolls: Assigned, 4 of 23". Enter or Space applies the filter.
- **Excluded lines:** skipped lines are left out of the bars, with the existing "N skipped lines are not counted" note.
- **Live updates:** the bars re-render through the existing `cpRpInvalidate()`, so a change on either tab or from a peer moves them.
- **Phones (≤640px):** the bars stay full width at a slightly taller height so they're tappable, and the legend wraps. Tapping a slice filters to its block.
- **Rendering:** plain HTML/CSS (flex blocks), no chart library.

## Constraints

- These are the same as the Resource Planning spec:
  - The Court Planner lives in `default/` and is maintained there.
  - `.tpl` is plain PHP.
  - SQL stays in the lib.
  - No native dialogs. Tooltips are `data-tip`, and every new surface supports dark mode.
- Existing tests change with the renamed constants, and new statuses get server tests:
  - Regalia rejects 3.
  - Both types accept 4 and 5.
  - The backfill's rule is tested on fixture rows.
- The Scrollery court print (`model.Scrollery.php`, `scrollery_print_plan`, `complete_printed_scrolls`) keys only on 3 → 2 and is unaffected. Confirm it with its existing tests.

## My calls (the user can override)

- Number values: Pending 0, Assigned 1, Complete 2, Scrollery Print 3, In Progress 4, Not Needed 5. Keeping the old numbers avoids rewriting existing data.
- Regalia "Needed" with a maker becomes Assigned; legacy Custom Design scrolls with no maker stay Assigned and are flagged.
- One colour per status shared by both types; regalia loses its red "Needed".
- Not Needed is shown hatched and is last in the bar.
- The bars replace the RP summary strip; the per-status filter chips give way to bar clicks.
- The Order expand-panel Save gets the same Pending ↔ Assigned rules, for symmetry.

## Left to the builder

- Exact shades, as long as they pass the dataviz contrast checks in both themes.
- Bar height, and slice-gap styling.
- How the shared definition is emitted, for example a `<script>` JSON block or a `data-` attribute.
