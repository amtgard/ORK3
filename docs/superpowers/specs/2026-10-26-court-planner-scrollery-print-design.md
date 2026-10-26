# Court Planner × Scrollery: status chips and bulk scroll print

Date: 2026-10-26 · Branch: `scrollery` · Status: design, awaiting approval

## Intent

Scrollery can now print certificates. The Court Planner is where an officer
already decides, line by line, which awards need a scroll before court. Today
that is a three-state printer icon (not tracked → "in progress, needs printing"
→ done) that says nothing about *how* the scroll is being made, and printing
still happens somewhere else, one award at a time.

This change lets the planner say how each scroll is being made: printed by
Scrollery, or made by hand by an artisan. Every Scrollery line then prints in
one pass from the planner, the night before court, with one page per scroll.

## Goals

1. Replace the scroll and regalia icon columns with **two status chips stacked
   vertically in one column** (scroll on top, regalia below), to save width.
2. A chip shows its status in words. Clicking it opens a **dropdown to pick the
   status directly**, so the officer no longer cycles through every state.
3. Scroll statuses: **Not Tracked, Scrollery Print, Custom Design, Complete.**
   Regalia statuses: **Not Tracked, Needed, Complete**, the existing three
   states with clearer names.
4. A toolbar button **"Print Scrollery scrolls (N)"** opens one printable
   document with a page for each line set to Scrollery Print.
5. After that print is launched, the planner offers **"Set these scrolls to
   complete?"**. Yes sets exactly the printed lines to Complete. "Not yet"
   dismisses the bar.
6. A line set to Scrollery Print **has no Scroll Maker**. That field is for
   artisans.

## Non-goals

- No per-line Scrollery customisation: no recipe, variant, hand, pronoun
  override or custom name. Each scroll prints with Scrollery's defaults for its
  order. (If an officer wants a bespoke Scrollery scroll, the existing
  Scrollery composer does that. A link to it is a possible follow-up, not part
  of this work.)
- No change to the Court Planner's grant, stage or finalize flow, or to what is
  written to the permanent record.
- No "Print Scrollery scrolls" button anywhere other than the Court Planner.
- No change to how regalia is tracked beyond the chip, the dropdown and the
  renamed labels.

## Anti-goals (failure even if it "works")

- The bulk print silently printing fewer scrolls than were marked. Every
  marked line either prints or is named in the document with the reason it
  didn't.
- A Monarchy Only recommendation reason printed on a certificate as the
  citation. The citation must pass the same gate every other published court
  citation passes (`RecommendationVisibility::publishable_citation` through
  `Court::publishable_citation`).
- "Set these scrolls to complete" touching any line that was not in the run
  that was just printed, including lines another officer re-marked in the
  meantime.
- Dark mode or mobile left behind: the chips and dropdown must work in both
  themes and in the planner's stacked mobile-card layout.

## Decisions (made with the user)

| Topic | Decision |
|---|---|
| Control | Chips stacked vertically in one column; a click opens a dropdown picker |
| Scroll states | Not Tracked / Scrollery Print / Custom Design / Complete |
| Bulk print | Toolbar action renders every Scrollery Print line into one document, one page per scroll |
| After printing | A "Set these scrolls to complete?" prompt, with "Not yet" to dismiss |
| Scroll Maker | Left blank for Scrollery Print lines |
| Giver on the scroll | The court's monarch (user agreed to the proposed default) |
| Who can bulk-print | Anyone who can manage the court, for that court's lines only. Scrollery's AUTH_EDIT check is not added on top. |

## Data model

No schema change. `ork_court_award.scroll_status` and `regalia_status` are
already `tinyint`.

| value | scroll | regalia |
|---|---|---|
| 0 | Not Tracked | Not Tracked |
| 1 | Custom Design | Needed |
| 2 | Complete | Complete |
| 3 | Scrollery Print | (not used; rejected) |

**User's decision:** Custom Design is 1, because that is what today's "in
progress (needs printing)" state represents. Every existing tracked line keeps
its meaning as Custom Design, and every done line stays Complete. So there is
**no migration and no backfill**. Scrollery Print is the only new value. A
line enters the bulk print only when an officer explicitly picks Scrollery
Print. This also keeps the Release Feature Utilization report
(`class.Report.php` ~6690: `> 0` tracked, `= 2` done) correct without edits.
Its integration test (`ReleaseUtilizationCrownTest`) should still pass
unchanged.

## Behaviour

### Chips and dropdown (Court_detail.tpl)

- One column, headed "Scroll / Regalia", replaces the two 30px icon columns in
  `.cp-row-grid` and the header. Both the published and the unpublished grid
  templates change.
- Each chip has a small icon (scroll, medal), the status label and a caret.
  Colours: Not Tracked is neutral grey, Scrollery Print blue, Custom Design
  purple, Needed red, Complete green. There are dark-theme equivalents.
  Status is never carried by colour alone; the label is the text.
- Clicking a chip must not toggle the row's expand. (The current icon already
  stops propagation; keep that.) The dropdown lists the states for that chip's
  type, with the current one marked, and has a short hint under Scrollery
  Print ("Goes in the bulk print") and Custom Design ("An artisan is making
  it"). Escape and an outside click close it. It is keyboard operable: the
  chip is a button, the arrow keys move and Enter picks.
- **Scrollery Print is disabled** in the dropdown for a line Scrollery cannot
  print, with a one-line reason. "Cannot print" means the award fails
  `Model_Scrollery::award_order_for_compose()` for the court's kingdom: an
  officer position, an excluded class, or not in the catalogue. The planner
  learns this from a per-line flag computed server-side when the court loads,
  and from the same flag in `court_state`'s `awards_full`.
- Picking a state saves it immediately through a new explicit-value endpoint
  (below). The chip updates on success and reverts with a message on failure.
  The 15-second heartbeat reconcile (`court_state` → `awards_full`) must render
  the new chips for rows changed by another officer.
- Setting a scroll to **Scrollery Print clears `scroll_maker_id`** for that
  line, and the expand panel's Scroll Maker field is hidden while the line is
  Scrollery Print. Moving to any other state shows the field again, empty.
- The planner's other readers of scroll status follow the new vocabulary:
  - the stats bar (`$_scroll_counts`): Scrollery Print, Custom Design,
    Complete and Not Tracked counts
  - the "Printing List" view (lines 2876–2920), which shows tracked rows with
    work outstanding first (Scrollery Print and Custom Design), then Complete
  - the Prep Sheet (sheet 3, grouped by maker), where Scrollery Print lines
    group under "Scrollery" instead of "no maker"
  - the About panel legend (around line 1484)
  - `cp_track_label()` in PHP and `cpTrackLabel()` in JS, which stay the single
    source of label text and must agree
- `Court_record.tpl` reads `ScrollStatus`. It must display or tolerate value 3.

### Bulk print

- Toolbar button: **"Print Scrollery scrolls (N)"**. N is the count of lines
  with scroll status Scrollery Print, cancelled and skipped lines excluded. It
  is disabled when N is 0, with a `data-tip` saying how to mark lines. N stays
  current as chips change.
- It opens, in a new tab, a new bare print route in the Scrollery controller,
  `Scrollery/court/{courtId}`, with the chosen court-award ids in the query
  string. The page reuses `Scrollery_render.tpl` (one page per scroll, the
  existing print CSS and auto-print behaviour) and `render_bare()`.
- **Authorisation (user's decision):** the viewer must be signed in and pass
  `Court::canManage` for the court's kingdom or park. That **alone** is
  enough. Scrollery's stricter `may_issue_for` (AUTH_EDIT) is *not* required
  on this route, because officers running court (Monarch, Regent or Prime
  Minister seats) are the people who need these scrolls. The grant is narrow:
  it covers certificates for lines on a court the viewer manages, and nothing
  else in Scrollery. The court's issuing scope is its park when
  `park_id > 0`, otherwise its kingdom. Every id must belong to that court;
  foreign ids are dropped. The route recomputes the set server-side from the
  ids given, still filtered to lines that are currently Scrollery Print.
- **What each scroll says.** Each scroll is built with `Scroll::BuildScroll`
  the way `compose()` does, from the court line:

  | Field | Source |
  |---|---|
  | Recipient | The line's player persona; possessive pronoun via `possessive_pronoun_for_player` |
  | Award, title | `award_order_for_compose` on the court's kingdom, with the line's `kingdomaward_id` resolved to its award |
  | Rank | The line's `rank` |
  | Date | `court_date`. An undated court leaves the date line blank. |
  | Reason | The line's citation after the publishable-citation gate. Blank if withheld or empty. |
  | Given by | The line's `given_by_mundane_id` when it is set (staged or given), otherwise the default from `Court::getCourtGiverOptions($court_id)`. That uses the court's own giver snapshot for a past court, so a reign change does not re-attribute it. |
  | Issuer | The court's park or kingdom, with heraldry from `scope_context` |
  | Provenance | `mundaneId`, `scopeType`, `scopeId` |

- Lines that cannot print are listed on a notice page at the front of the
  document, each with its reason. Reasons include an award Scrollery can't
  print, a title that resolves blank (an unnamed Custom Award or Custom
  Title), and a player who no longer resolves. Every other line still prints.
  If nothing prints, the module's existing `deny()` page explains why.
- Every printed scroll is logged through the existing `record_print()` with
  `awards_id` NULL, as composed scrolls are.
- The run is capped at `Scroll::MAX_PRINT_RUN`. The overflow is reported the
  way `render()` reports `Dropped`.
- All SQL lives in `system/lib/ork3/` (`Court` or `Scroll` lib). The
  controller reaches it only through `orkui/model`. The court-line-to-scroll
  assembly belongs in `Model_Scrollery`, as `scrolls_for_court_awards(...)`,
  next to `scrolls_for_awards_ids`.

### "Set these scrolls to complete?"

- **The planner learns exactly what will print before it opens the tab** (my
  call). Clicking the button first calls a CourtAjax preflight,
  `scrollery_print_plan` (`CourtId`). It returns:
  - `Printable`: `{CourtAwardId, RowVersion}` for each line, already capped at
    `MAX_PRINT_RUN`;
  - `Skipped`: `{CourtAwardId, Reason}` for each line that won't print.

  The preflight uses the same `Model_Scrollery::scrolls_for_court_awards`
  resolution as the print route, so the two cannot disagree. The tab then
  opens with the `Printable` ids only. The bar and "complete" act on that list
  and nothing else. Lines that only appear on the notice page, or were cut by
  the cap, are never completed.
- The planner records the `Printable` list, with each line's `row_version`.
  It then shows a bar
  above the award list: "Printed N Scrollery scrolls", **Set these scrolls to
  complete?** and **Not yet**. *My call:* the bar appears on the click, not on
  detecting the print, so it is waiting when the officer returns from the
  print tab. A browser cannot report whether a print actually happened.
- Yes calls a bulk endpoint that sets scroll status to Complete only for those
  ids and only where the line is still Scrollery Print. The ids and row
  versions are checked with the planner's existing optimistic-lock convention
  (`row_version`, a `{status:9, stale:true}` response). Lines that changed are
  reported ("2 lines changed since printing and were left as they were") and
  are not touched.
- Not yet dismisses the bar. Nothing else changes.

### Endpoints (CourtAjax)

- `set_award_tracking_status`: `CourtAwardId`, `Type` (scroll|regalia),
  `Status` (int, validated against that type's allowed set), and `RowVersion`.
  It bumps `row_version`. It clears `scroll_maker_id` when the new status is
  Scrollery Print. It is gated by `requireCourtAuth`. It refuses a line on a
  court that is already complete only if the existing tracking update refuses
  one (match the current behaviour; don't add a new restriction).
- `scrollery_print_plan`: `CourtId` → `{Printable, Skipped}` as described
  above, gated by `requireCourtAuth`. It is read-only and writes nothing to
  the print log.
- `complete_printed_scrolls`: `CourtId` plus a list of
  `{CourtAwardId, RowVersion}`, all-or-report as described above.
- The old cycling `update_award_tracking_status` and its lib
  `updateAwardTrackingStatus` (`% 3`) are removed once nothing calls them. A
  `% 3` cycle would never reach value 3 (Scrollery Print).

## Constraints and conventions

- `Court_detail.tpl` lives in `template/default/`. It is the existing Court
  Planner, so it is edited in place and no new entry points are added there.
  The print route and its template work in `revised-frontend` and the
  Scrollery controller.
- Tooltips use `data-tip`, never `title`. No native `confirm`, `alert` or
  `prompt`; the complete prompt is an in-page bar.
- Dark mode via `html[data-theme="dark"]`. Watch for `orkui.css` global
  control rules overriding the chip-as-button styles, and verify computed
  styles in both themes.
- Mobile: the stacked-card layout (`@media (max-width:640px)`, around lines
  1180–1215) currently gives scroll/regalia a full-width row with the label
  showing. Chips should sit side by side there; there is room for that.
- The dropdown must not be clipped by `.cp-award-list { overflow-x:auto }`.
  Position it fixed, measured from the chip, as the maker autocomplete does.
- Memcache: court reads are not cached, but verify the planner reflects a
  change without a flush.

## Left to the builder

- Exact chip sizing per density (cozy, comfortable, compact) and icon choice
  within FontAwesome 7.0.1.
- Whether the per-line printable flag is computed in `Court` lib (joining the
  catalogue) or by calling the Scrollery model from the Court controller, as
  long as SQL stays in the lib layer and one definition of "printable" is
  shared with the print route.
- The notice-page styling for unprintable lines.
- Test shape. At minimum: unit tests for the status vocabulary and transitions
  (including the maker clear and the value-3 rejection for regalia), the
  complete-only-what-was-printed rule with a stale row, and the citation gate
  on the print path. Then a browser pass over chips, dropdown, print and
  complete in both themes and at phone width.
