# Court Planner — Resource Planning tab

**Status:** approved to build (2026-10-26, "Go ahead and create this view"). Deeper statuses come in a follow-up.

## Intent

The person running a court's logistics needs one place to see which awards need a scroll, which need custom work, which need regalia, and where things stand on finding and assigning artisans. The Order of Court list answers "what happens, in what order". It is the wrong shape for "what do we still need to make, and who is making it".

The Court Planner gets two tabs:

1. **Order of Court** — today's list, unchanged.
2. **Resource Planning** — a spreadsheet-inspired grid of the same award lines, focused on scrolls, regalia and artisans.

The two tabs are two views of one set of lines and talk to each other in both directions. Assigning an artisan to a scroll on Resource Planning updates that line on the Order, and an edit on the Order shows up on Resource Planning.

## Goals

- At a glance: how many scrolls are Scrollery Print, Custom Design, Complete or Not Tracked; how much regalia is Needed or Complete; and how many custom scrolls and needed regalia still have **no artisan**.
- Set a line's scroll and regalia status and assign or clear its scroll maker and regalia maker, inline, without opening the Order's expand panel.
- See each line's contributing artisans, and add one.
- Edits made on either tab, or by another officer (heartbeat), appear on both tabs without a reload.

## Non-goals (this round)

- Deeper statuses such as "artisan asked", "in progress" or "delivered". These are the next round, so the grid should make it easy to add a status, but it adds none.
- New database columns, migrations or endpoints. Everything this needs exists: `scroll_status`, `regalia_status`, `scroll_maker_id`, `regalia_maker_id`, `court_award_artisan`, `set_award_tracking_status`, partial `update_award`, `add_artisan`/`remove_artisan`, and `court_state.awards_full`.
- Reordering, granting, skipping, citations or notes from Resource Planning. Those stay on the Order.
- A per-artisan workload view (deferred; a candidate for the enhancement round).

## Anti-goals

- Two sources of truth. Both tabs read and write the one client model (`window.courtAwards`) and the same endpoints. A maker set on one tab must never be overwritten by a stale value the other tab still holds. The concrete trap: the Order expand panel's **Save** posts its hidden `ScrollMakerId`/`RegaliaMakerId`. If Resource Planning changes a maker without updating those inputs, the next Save puts the old maker back.
- A second status picker. Resource Planning reuses the Order's chip and picker (`.cp-chip` and `cpOpenTrackMenu`), so labels, hints, the Scrollery-printable rule and the RowVersion queue behave identically.

## The design

### Tabs

- The `Order of Court (N)` heading in `.cp-section-header` becomes a tab strip, `Order of Court (N)` | `Resource Planning`, using real `role="tab"` buttons with arrow-key movement.
- The Order's header actions (Add Award / Title / Note, Court Script) stay visible on the Order tab only.
- The tab panels split the main column. The Order panel holds today's print bar, toolbar and award list. The Resource Planning panel holds the new grid. The sidebar is shared and unchanged.
- The selected tab is in the URL as `?view=resources` (via `history.replaceState`, so a reload or shared link returns there) and defaults to Order of Court. Any other value means Order.

### Resource Planning grid

**Summary strip.** Counts drawn from the model, skipped lines excluded:

- **Scrolls:** Scrollery Print N · Custom Design N (**M need an artisan**) · Complete N · Not Tracked N
- **Regalia:** Needed N (**M need an artisan**) · Complete N · Not Tracked N

**Filter chips** (one active at a time): All · Needs an artisan · Custom scrolls · Scrollery Print · Regalia needed · Not tracked · Complete. "Needs an artisan" means a Custom Design scroll with no scroll maker, or Needed regalia with no regalia maker. "Complete" means every tracked item on the line is Complete. Each filter shows its count.

**Columns**, one row per award line, in Order of Court order (notes excluded):

| Column | Content |
|---|---|
| # | The line's position on the Order (same number the Order shows) |
| Recipient | Persona, plus park in small text |
| Award | Award name and rank |
| Scroll | The shared scroll status chip (opens the shared picker) |
| Scroll Maker | Inline maker cell (below) |
| Regalia | The shared regalia status chip |
| Regalia Maker | Inline maker cell |
| Artisans | Contributing artisans' personas, comma-joined, plus a `+` that opens the existing Add Artisan modal for that line |
| Line | Planned / Staged / Given / Skipped as small muted text |

- Skipped (`cancelled`) lines are dimmed and stay in place.
- A row that needs an artisan gets a subtle left-edge accent, so the eye finds gaps without the filter.
- No DataTables: this is a form-control grid, which the DataTables rule exempts. It is a CSS grid in the planner's own visual language, with sticky column headers.

**Maker cell.**

- Shows the maker's persona with a small `×` to clear, or a muted "Assign…" when there is none.
- Clicking or pressing Enter turns it into a search input that uses the shared `cpAcSearch(input, dropId, hiddenId, onPick)`, which is scoped and uses `&q=`. Picking saves immediately. Escape cancels.
- A Scrollery Print scroll has no maker: the cell reads "Printed by Scrollery" (muted, not editable), matching the Order hiding the field.
- **Saving:** `CourtAjax/update_award` with **only** `ScrollMakerId` or `RegaliaMakerId`, plus `RowVersion`. It goes through the line's per-line write queue (`cpLineWrites`), so it serializes with chip writes. On success it advances the line's RowVersion and calls `cpPendingRunAdvance(caid, sentRv, newRv)`; status 9 calls `cpStale(caid)`.
- **Assigning a maker to an untracked item (my call):** assigning a scroll maker while the scroll is Not Tracked also moves it to Custom Design. Assigning a regalia maker while the regalia is Not Tracked also moves it to Needed. This is a second queued write through `set_award_tracking_status`, so the artisan assignment always lands in a tracked state. Clearing a maker never changes the status.

**Editability** matches the Order exactly: where the Order lets an officer change a chip or a maker field, Resource Planning does too, and nowhere else.

**Empty state:** "No awards on this court yet." when there are no award lines.

### Bi-directional sync

There is one model, and every write goes through the line-level writers:

- **A maker change from Resource Planning** updates `courtAwards[i].ScrollMakerId/Persona` (or the regalia pair) **and** the Order's expand-panel inputs (`cp-scroll-maker-text-/id-`, `cp-regalia-maker-text-/id-`), so a later Order Save posts the new maker.
- **A status change from either tab** goes through the shared picker, which ends in `cpSetLineTracking`.
- **Order-side changes refresh Resource Planning:** status (`cpSetLineTracking`), makers or other fields (expand Save, `cpApplyRowFields`), artisan add/remove, row add/remove, skip/unskip, stage/unstage/grant, reorder, and the heartbeat reconcile. Each calls one function, `cpRpInvalidate()`.
- **`cpRpInvalidate()`** re-renders Resource Planning from the model once per animation frame, and only while its tab is visible; otherwise it marks it dirty and renders on show. It never re-renders under an open maker search: that render waits until the edit ends.
- **A peer's change** arrives through the existing 15s heartbeat (`court_state.awards_full`, which already carries makers, statuses and artisans) and reaches Resource Planning through the same invalidate.

### Phone, dark mode, accessibility

- **Phones (≤640px):** each row becomes a card showing the recipient and award on top, then two compact sub-rows: Scroll (chip + maker) and Regalia (chip + maker), then artisans. The summary strip wraps and the filter chips scroll horizontally. Nothing scrolls horizontally on the page.
- **Dark mode** uses `html[data-theme="dark"]`, with every new surface, border and text color covered.
- **Accessibility:** tabs use `role="tablist"`/`tab`/`tabpanel`; maker cells are buttons with `aria-label` "Scroll maker: <persona or unassigned>"; tooltips use `data-tip`; there are no native dialogs.

## Constraints

- `Court_detail.tpl` lives under `default/`. The Court Planner exists only there and is actively maintained there, which is the same exception as the Qualification Tests. No new entry points elsewhere.
- `.tpl` is plain PHP. Server-rendered and JS-rendered markup must agree; this grid is JS-rendered from the model only, so there is no PHP twin to drift.
- No SQL outside the lib, and no new endpoints.
- Player search: the shared scoped `cpAcSearch` only.

## Left to the builder

- Exact visual styling within the planner's existing chip/grid language.
- Whether the summary counts are clickable shortcuts to their filter (nice, optional).
- Internal function names other than `cpRpInvalidate`.
