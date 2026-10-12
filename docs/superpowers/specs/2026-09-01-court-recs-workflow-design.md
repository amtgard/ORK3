# Court Planner & Recommendations Manager — Workflow & Click-Cost Design

**Date:** 2026-09-01
**Branch:** `feature/court-planner`
**Status:** Design for review
**Origin:** A click-pattern and workflow analysis of both modules, reshaped by a user
correction about how courts are actually run, then revised against a UI/UX/workflow
design review that verified every baseline claim against the code.

---

## 1. The workflow correction

The Court Planner was built with a live ceremony in mind: `mode='run'` is the schema
default, and run mode gets the prominent per-award Grant/Skip controls and a 15s
heartbeat. That is not how the tool is used.

> Nobody runs the ceremony live from inside the ORK. Officers set the court up, **print**
> the awards and the order of court, run the ceremony **offline from paper**, and **catch
> the data up afterward**.

How catch-up happens **varies by kingdom, and both patterns occur**: sometimes one officer
sits down with an annotated printout, sometimes someone hits *Record All Grants* and fixes
the exceptions. The design must serve both without forcing either.

Three consequences drive this document:

1. **The printed sheet is the operating document of the ceremony.** It deserves to be
   designed as a deliverable, not emitted as a byproduct.
2. **The catch-up pass is the module's real data-entry surface**, and it is currently
   assembled out of controls built for a different workflow.
3. **The failure mode of an offline workflow is silence.** If the recorder forgets, or
   never knew it was their job, the awards simply never land and nothing says so.

A live-ceremony keyboard "Runner" mode was designed and then **cut** on this correction.
Its one surviving element — keyboard acceleration — is repositioned as a bonus for the
catch-up typist, explicitly **not** counted in any cost model (see §9, D3).

---

## 2. Verified baseline

Every claim below was read in the code. Line numbers are `feature/court-planner` at
`99f9a533`.

### Court Planner (CP)

| Fact | Location |
|---|---|
| No selection model on award rows — no checkbox anywhere | `Court_detail.tpl:1547-1728` |
| Ad-hoc add modal closes on submit; no add-another | `:1886`, `cpSubmitAdhoc` `:3464` |
| Per-award detail behind expand → edit → explicit **Save** | `:1716`, `cpSaveAward` `:2641` |
| Each artisan requires its own modal | `cpOpenArtisanModal`, submit `:3589` |
| Reorder arrows move one position per click, one POST each | `:1558-1559` → `cpMoveAward` `:2386` → `cpSaveOrder` `:2626` |
| Drag-reorder exists but **draft only**; published kills all reordering | `:1555`; `.cp-list-published .cp-reorder-btns{visibility:hidden;pointer-events:none}` `:441` |
| Grant modal rank is `<input type="number" max="99">`; ad-hoc uses pills | `:1978`; `:1912` |
| Only keydown handler is Escape | `:4259` |
| Walk-on adds **already work while published** (QW#6) | `cp-published-add-tools` `:1468-1482` |
| `bulk_record_grants` stages every remaining planned award with defaults | `cpBulkRecord` `:3823` → `CourtAjax.php:611` |
| **No `update_court` endpoint exists** — only `create_court`, `update_court_status` | `controller.CourtAjax.php` |
| **`controller.Court.php` exposes only `list` and `detail`** | — |
| Award date is the court date, falling back to *today* | `class.Court.php:897` |
| `grant_award` accepts **no `RowVersion`**, while `skip_award` does | `CourtAjax.php:428` vs `:760` |

### Printed script

| Fact | Location |
|---|---|
| Compact prints `# ☐ Recipient(Park) Award(Rank)` — no giver column, one checkbox | `cpScriptCompact` `:4333` |
| Citation density has citation + artisans but **no checkbox at all** | `cpScriptCitation` `:4345` |
| **Cancelled (skipped) awards are filtered out of every printed sheet** | `cpScriptActiveAwards` `:4306-4308` |
| Sidebar "printing list" reorders the DOM; produces no print output | `cpTogglePrintingList` `:2485` |
| One reparented overlay, one `@page { margin: .6in }` | `:407-425` |

### Recommendations Manager (RM)

| Fact | Location |
|---|---|
| Bulk bar: Add to Court / Snooze / Pass down / Dismiss / Clear — **no bulk grant** | `Recommendations_manage.tpl:644-648` |
| `rm-selall` selects **loaded rows only**, against 500-row server batches | `:934-937` → `rmLoadedRows()` |
| **An inline member expander already exists** — every member's recommender, date, reason, and each second with its note | `.rm-expand-members` `:782-802` |
| Grant POSTs straight to a permanent write; **no dup guard, no undo in RM** | `rmDoGrant` `:1182`; template comment `:1283-1285` |
| Grant modal has a court-reconciliation choice (remove vs leave); server default is `leave` | `:1181` |
| Bulk actions are client-side sequential loops, not batch endpoints | `:1490+`; CP equivalent `:3271-3281` |
| Zero keydown handlers on the page | — |
| `Total` is a SQL `COUNT(*)`; groups are filtered **again** in PHP; `NextOffset` advances by SQL page size | `class.Report.php:1006-1085` |
| SQL `support_count` is **for the sort tiebreaker only**; displayed SupportCount is computed in PHP | `class.Report.php:974-976`, `groupRecommendations` `:621` |

### Constraints inherited from shipped specs

From `2026-07-12-court-planner-grant-safety-design.md` (landed — commits `8801ffe8`,
`14c20ce1`, `99f9a533`):

- **One idempotent grant sink.** `Court::commitStagedAward` claims `staged→given`
  atomically; a court line commits at most once. Server-side cross-path reconciliation;
  the client's `data-courts` is not trusted for correctness.
- **Duplicate handling: warn on all, block none.** "Already holds" is a non-blocking
  advisory, because some awards are legitimately repeatable.
- **Staging writes nothing permanent.** Only finalize does. **This is the property that
  makes bulk staging safe, and RM does not have it.**

---

## 3. Thread 0 — Correctness fixes (ship first)

These are defects, not enhancements.

### 0.1 The court's date, name, and event cannot be edited — Critical

`commitStagedAward` stamps `$date = $row['CourtDate'] ?: date('Y-m-d')`. A court created
without a date — the field is optional in `Court_list.tpl:231` — silently stamps **every
award in it with the day the recorder did the catch-up**, which under the corrected
workflow is days or weeks after the ceremony. There is no endpoint to fix it and the hero
renders the date read-only (`:1266`).

**Design:** add `CourtAjax/update_court` accepting `Name`, `CourtDate`,
`EventCalendarDetailId`, and `RecorderMundaneId` (0.7), authorised by `Court::canManage`,
permitted in `draft` and `published`, refused once `complete` (finalized rows already carry the date). Surface as
inline edit-in-place on the court hero. Flatpickr with `altInput` and the house
human-readable format belongs here.

### 0.2 `rm-selall` silently selects 500 of N — Critical

Header checkbox selects the loaded page only, against 500-row server batches.

**Design (must stand alone — Thread 0 ships before Thread 4).** When `hasMore` is true the
footer reads *"500 of 1,240 loaded — select-all covers loaded rows only"* and the bulk bar
label reads *"500 selected (loaded rows)"*. No new endpoint, and it is forward-compatible
with C3's banner, which later replaces the footer copy with the criteria-mode offer.

### 0.3 Skipped awards vanish from every printed sheet — Major

`cpScriptActiveAwards()` filters `Status !== 'cancelled'`. Reprint after a partial session
and previously-skipped rows disappear from the paper, so nothing prompts the recorder to
reconsider them — while the Complete modal promises they "resurface on the next court."

**Design:** print cancelled rows **struck through and marked skipped** rather than omitting
them.

Note the dependency on 0.5: resurfacing is delivered by `prepopulate_from_last_court`, which
fires only on a `draft` court and pulls from the most recent **completed** court. A court
left `published` and never completed never contributes its skipped rows to anything — which
is exactly what 0.5 exists to drive.

### 0.4 `grant_award` carries no `RowVersion` — Major

`skip_award` and `set_award_status` thread the optimistic lock; `grant_award` does not
(`cpGrantConfirm` `:2987-3010` sends none). Two officers recording from two annotated
printouts is an explicitly supported scenario. Any batch stage arm (§6) inherits this gap.

**Design:** thread `RowVersion` through `grant_award`, returning the existing status-9
stale-reload path. This reaches the lib: `model.Court::stage_award` (`:133`) has no
`$expectedRowVersion` parameter today, so the signature changes alongside the controller.

**The optimistic lock belongs on single-row marks only.** `bulkStagePlanned`
(`class.Court.php:1314-1329`) is a set-based `UPDATE … WHERE court_id = N AND status =
'planned'` returning a row count. "Mark all remaining Given" means *whatever is still
planned at this instant*, which is precisely set-based semantics; a stale-version rejection
on a blanket operation is noise the recorder cannot act on. Two recorders collide on
individual rows, and that is where the lock goes.

### 0.5 Nothing surfaces a published court that was never recorded — Major

Every failure mode of an offline workflow is silence. The staged-grants indicator
(`:1310`) only fires when something *is* staged — precisely not this case.

**Design:** one line on `Court_list` and one banner on the kingdom/park admin surface for
courts that are `published`, have **zero** staged or given rows, and whose `CourtDate` is
either in the past **or not set at all**:

- dated: *"Court of the Burning Lands — held 9 days ago, nothing recorded yet."*
- undated: *"Court of the Burning Lands — published 9 days ago, no date set and nothing
  recorded."*

The NULL-date arm is not an edge case: it is the exact court 0.1 exists to protect, and a
predicate keyed only on "in the past" would leave the document's worst case invisible. The
undated banner drives the officer straight into 0.1's new date editor, which is the right
sequence anyway. For the stated workflow this item is worth more than all of Thread 6.

### 0.6 `update_award` is a whole-record write — Critical

`Court::updateAward` (`class.Court.php:507-530`) writes `notes`, `public_comment`,
`pass_to_local`, `scroll_maker_id` and `regalia_maker_id` **unconditionally in one UPDATE**,
and the controller (`CourtAjax.php:314-337`) defaults every absent POST key to `''` / `0`.
This is safe today only because exactly one caller exists (`cpSaveAward`, `:2641`) and it
always sends all five from the DOM.

Two features in this document add *partial* callers, and both silently destroy data as
specified:

- **The Record Court citation save (§5).** That view shows the citation and nothing else. A
  citation-only POST sets `notes = ''`, `pass_to_local = 0`, `scroll_maker_id = NULL`,
  `regalia_maker_id = NULL` — erasing the internal notes, the Pass-to-Local decision, and
  the scroll and regalia maker credits set during planning. Those credits feed Sheet 3 and
  the "Artisans to thank" line on Sheet 1 (`cpScriptArtisans`, `:4322`). Nothing on screen
  shows the fields, so the loss is invisible until someone prints or reads a profile.
- **The selective autosave (§8, D3).** `public_comment` is written on every call, so an
  autosave of Internal Notes must send *either* the live half-typed citation — the exact
  fragment D3 exists to keep out of the permanent record — *or* nothing, which blanks a
  saved citation. D3's safety property cannot be delivered by this endpoint.

**Design:** make `update_award` a genuine partial update — write only the columns whose keys
are present (`array_key_exists`), and change `Court::updateAward` from six positional
parameters to a field map. `row_version` still increments; the optimistic-lock behavior is
unchanged.

This is the smallest change that makes two of this document's fixes actually work, and both
Thread 2 and Thread 5 depend on it.

### 0.7 Nobody is named as the recorder — Major

0.5 makes an unrecorded court *visible*; it does not make it **anyone's**. Three sheets are
printed by the planner, annotated by the herald, and typed by a third person, and nothing
models that handoff. A banner on an admin surface only works if someone looks at it.

**Design — name a person, then tell them.** Three parts, all on existing rails:

1. **Recorder on the court — defaults to the Prime Minister.** Recording court is the
   PM's responsibility under Corpora, so the field resolves to the **PM of the court's own
   scope**, with this fallback chain:

   - **park court:** park PM → park Monarch or Regent → kingdom PM → publisher
   - **kingdom court:** kingdom PM → publisher

   The park chain prefers the park's own Monarch/Regent over the kingdom PM deliberately:
   auto-assigning a *kingdom* officer to record a *park's* court is a cross-scope
   assignment nobody asked for, and the park's own royalty were present, already pass
   `canManage`, and are already candidates in `getCourtGiverOptions`.

   The reusable mechanism is the private helper `lookupOfficerGiver($kingdom_id, $park_id,
   $role, $label)` (`class.Court.php:1107`), which is generic over role — **not**
   `getCourtGiverOptions` itself, which resolves Monarch plus Regent/kingdom-Monarch/
   kingdom-Regent pills and never looks up a PM (`:1137-1171`). This is a new call on an
   existing helper, not free reuse.

   **Case caveat:** `lookupOfficerGiver` matches `o.role` exact-case, and officer roles are
   known to be lowercased in some environments. A mismatch makes the default silently fall
   through to the publisher, which is indistinguishable from "no PM on record" — the same
   collation caveat §11 records for `canManage`.

   The default is authoritative rather than incidental, and it lines up with the permission
   model for free: `Court::canManage` already admits `Prime Minister` explicitly
   (`class.Court.php:22-66`), so the person the Corpora makes responsible is a person the
   code already lets record.

   The field stays editable through `update_court` (0.1), with the player search
   **restricted to people who pass `canManage`** for that scope — which surfaces the "the
   scribe cannot actually record" problem (§11) at the moment it is fixable, rather than at
   11pm with paper in hand and a blocked save.
2. **A notification when the court's date passes with nothing recorded**, addressed to the
   recorder, linking straight to Record Court. `class.Notification.php` already exposes a
   generic `Add($mundaneId, $type, $message, $link)` over the existing `ork_notification`
   table, so this is one call on a shipped rail, not new infrastructure.

   **Honest about what this buys:** a notification row is seen when the recorder visits the
   ORK and checks their bell — still *if someone looks*, just at something smaller and
   better targeted. Because the trigger is a date passing rather than a user action, there
   is no moment when the recorder is already engaged, so a single fire-and-forget is
   genuinely likely to be missed by a volunteer who signs in a few times a year.

   The cheap fix is not a reminder chain: **0.5's unrecorded-court line also appears on the
   recorder's own home surface**, so the named person sees it every time they log in, with
   no escalation machinery. One repeat notification at ~7 days is a reasonable second
   option.
3. **The court's URL in the footer of every printed sheet** (§4), so whoever holds the paper
   reaches the right screen without hunting.

**Deliberately excluded:** accept/decline, reassignment workflow, escalating reminders.
These are volunteers doing this a few times a year; one named person and one nudge is the
whole job.

### 0.8 The Recommendations Manager has no responsive CSS at all — Major

Zero `@media` blocks against a ten-column dense grid with a sticky recipient column. Against
a blocking mobile requirement (§10) this is a **defect on a page that already shipped**, not
an enhancement.

It is placed in Thread 0 deliberately. An earlier draft attached the card collapse to Thread
4, on the reasoning that Thread 4 is the first thread to add controls to that grid — true,
but it would make an independent presentation fix hostage to criteria mode, the most
contentious feature in this document and the one §13 flags as changing the meaning of an
existing control. If Thread 4 slips, RM stays unusable on a phone indefinitely. The collapse
depends on nothing; Threads 3, 4 and 6 extend it rather than owning it.

Design in §10.

---

## 4. Thread 1 — The printed court packet

Replace the two-density overlay with **three sheets**. Density becomes a property of sheet
1 only.

**One page geometry across all three sheets.** Named `@page` rules with differing sizes in
one document have patchy browser support; the existing single reparented overlay with one
`@page` rule is kept, and the sheet selector swaps the body content.

Every sheet carries a **"printed &lt;date, time&gt;"** stamp (see §5, drift) and the
**court's URL** in its footer, so the person holding the paper can reach the right screen
without hunting (0.7).

### Sheet 1 — Order of Court (the herald reads from this)

Today's citation content: number, recipient (park), award and rank, citation text,
artisans to thank. Georgia serif, set larger — this is read at arm's length, standing, in
bad light. **Add the `☐` that citation density currently drops**, so the sheet a herald
actually holds is also annotatable.

### Sheet 2 — Court Record (the recorder writes on this)

Tabular sans, `tabular-nums`, ruled write-in fields, ~1.6em row height for real pen room.

```
GRAND COURT OF THE BURNING LANDS                    printed Sep 1, 2026 2:14 PM
Saturday, September 12, 2026                                       page 1 of 2

  #   ✓   ✕   RECIPIENT              AWARD                RANK   GIVEN BY      PTL
 ────────────────────────────────────────────────────────────────────────────────
  1   ☐   ☐   Wyndham (Nm)           Order of the Owl       —    Aldric        ☐
  2   ☐   ☐   Aleria (Gh)            Smith                  3    Aldric        ☐
  3   ☐   ☐   Bran the Red (Nm)      Warrior                5    Aldric        ☐
  4   ☐   ☐   ~~Corwin (Ir)~~        ~~Order of the Rose~~  —    (skipped last court)

  WALK-ONS
  5   ☐   ☐   ──────────────────     ─────────────────    ───    ──────────    ☐
      citation ────────────────────────────────────────────────────────────────
  6   ☐   ☐   ──────────────────     ─────────────────    ───    ──────────    ☐
      citation ────────────────────────────────────────────────────────────────
```

Design decisions, each answering something that must be typed back in:

- **Two boxes, not one.** `cpScriptCompact` renders `☑` for given-or-staged and `☐` for
  everything else, conflating *skipped* with *not yet processed*. On paper those must
  differ or the data is lost.
- **Given by, pre-printed faintly** with the court's default giver, so the common case is
  a tick and the officer writes only on deviation. The faintness *is* the design — it is
  what makes a tick sufficient rather than ambiguous — so specify it: **~60% gray, same
  size as body text**, never solid black like the recipient name.
- **Rank as its own narrow write-in column.** The plan may say Rank 3 and the Crown may
  give Rank 2. Both grant paths expose a rank control because rank changes; the paper must
  let it be struck and rewritten.
- **PTL box.** Pass-to-Local changes who confers the award and is a live decision at court.
  Sheet 1 prints `(pass to local)` as fixed text; sheet 2 needs a box to *set* it.
- **Skipped rows print struck through** (per §3, 0.3), so they prompt reconsideration
  rather than silently disappearing.
- **Walk-on rows carry a citation line.** A walk-on has no recommendation and no planned
  public comment, so its citation exists nowhere in the system. If it is not written at
  court it will be invented at a keyboard days later, or left blank permanently on a public
  profile. 6–8 ruled rows, numbering continuing from the plan.

**Dropped from the earlier design:** a separate short "jump code" per row. The `#` column
is already a unique per-sheet identifier; a second one is redundant, and it would drift the
moment a walk-on renumbers the list. The recorder types the `#`.

### Sheet 3 — Prep Sheet (scroll & regalia)

Pre-court production tracking, grouped **by maker** — what each scribe and each regalia
crafter owes, with status boxes to mark received. This gives `cpTogglePrintingList` an
actual print output; today it reorders the screen and stops.

### Not in scope, but noted

The public Court Report is the organisation's published output and consumes the same
citations. It has a list arm (`get_court_report_list`, `controller.Reports.php:272`,
`Reports_courts.tpl`) and a detail arm (`get_court_report_detail`, `:296`,
`Reports_court.tpl`) — the detail arm is the one that renders citations. This thread designs
three *internal* sheets and deliberately does not touch either.

---

## 5. Thread 2 — "Record Court": the catch-up pass

A view reachable from the court hero when status is `published`. **It is the Court Record
sheet, on screen, in the same order, without modals.**

### The correction that reshaped this thread

The first version of this design gave every row a three-state control defaulting to `—`,
so each of the ~26 rows that went exactly as planned cost one click. That charges full
price for the case the workflow says is dominant, and it made the view **slower than the
`Record All Grants` button that already exists.** Measured on a 30-award court where 26 go
as planned, 2 are skipped, 2 have a different giver, and 3 are walk-ons:

| Path | Interactions |
|---|---|
| Today (Record All Grants + fix exceptions + ad-hoc walk-ons + finalize) | **≈37** |
| First design (per-row three-state, no bulk) | **≈47** — worse |
| This design | **22** |

The fix is to lead with the bulk action and let the recorder mark exceptions.

Counted step by step: open the view (1) · mark 2 rows Skipped (2) · 2 giver changes, cell
plus chip (4) · 3 walk-ons, recipient + award + rank + Enter (12) · "Mark all remaining
Given" (1) · Complete Court plus option card (2) = **22**. There is no separate staging
click, for the reason in the next section.

### Design

- **Every mark is a real server write the moment it is made.** `Given` → the existing
  `stage_award`, `Skipped` → `skip_award`, `—` → `unstage_award`. Staging writes nothing
  permanent, so eager persistence costs no safety and keeps pre-finalize undo free — and it
  is what makes **partial work survive an interruption**, which §1 names as a core property
  of this workflow. A recorder who closes the laptop halfway through loses nothing.
- **"Mark all remaining Given"** at the top of the view. This *is* `bulk_record_grants`
  verbatim: `bulkStagePlanned` is a set-based `UPDATE … WHERE status = 'planned'`, so it
  stages every still-unmarked row server-side in one statement, and it stays live — click
  it again after adding walk-ons and it catches them.
  Rows are **never silently defaulted to Given** — that would trade a click for a chance of
  recording an award nobody gave. The bulk action is explicit and visible.
- **There is no "Stage all marked" button.** An earlier draft had one, which was incoherent:
  `bulkStagePlanned` already writes `staged` to the database, so the button would have had
  nothing to do for the majority of rows and would have meant something different for
  walk-ons than for planned rows — the exact mode confusion this section's consolidation
  exists to remove. Rows stage as they are marked, the staged-grants indicator (`:1310`)
  reports the running count, and **Finalize & Complete is the single gate.**
- **Per row:** `#`, recipient, award, rank, and a three-state segmented control
  **Given · Skipped · —**. After the bulk action, the recorder touches only exceptions.
- **Given by** cell, pre-filled with the court default as a chip. Changing one offers
  *"apply to the rest below"* — the real pattern is "the Regent gave the next six."
- **Rank** editable inline via rank pills (§8, D4).
- **Citation:** a one-line truncated preview per row, expanding to a textarea on click,
  saved through the existing `update_award`. **Zero clicks in the common case** (already
  filled during planning), one click on the exception. It is **mandatory-visible on walk-on
  rows**, where it is always blank. This field becomes `ork_awards.note` — public,
  permanent, and hand-editable only by an award admin (`class.Court.php:898-906`) — so it
  cannot be the one field the catch-up view omits.
- **Top strip:** court date, event, and default giver — edited through the new
  `update_court` endpoint from §3, 0.1, which is what makes this strip edit a real thing
  rather than a per-row override.
- **Walk-ons inline at the bottom:** a permanent blank row — recipient search, award
  search, rank pills — where Enter commits and opens a fresh blank row. Note this is a
  *faster path to an existing capability*, not a new one: `cp-published-add-tools` already
  permits walk-on adds while published. To avoid a third way to do one thing, the inline
  row **replaces** the Add Award / Add Title buttons *within this view* (they remain on the
  court detail page). It therefore has to carry what those modals carry: the row includes a
  **Pass-to-Local checkbox**, matching the PTL column on Sheet 2 — a walk-on the Crown
  passed to the local park has a box on the paper and must have somewhere to land on the
  screen. Titles are deliberately folded into the single award field, which already searches
  both (`cpAwardSearch`), rather than kept as a separate entry point. Internal Notes are
  dropped from the inline row by design; they are editable on the court detail page.
  Walk-ons land at the end because published courts cannot be reordered (`:441`) — the
  printed sheet's numbering matches.
- **Finalize & Complete** is the only commit. Nothing reaches the permanent record until
  it runs; pre-finalize undo stays free.

### Mode consolidation

The published-plan hero already renders **Complete Court**, **Record All Grants**, and
**Return to Planning**. Adding a fourth button named *Record Court* would put two
near-homophones side by side for different things.

**Record Court becomes the primary published action and absorbs Record All Grants** as its
"Mark all remaining Given" button. One name, one place, one concept — and the hero loses a
button rather than gaining one.

### Concurrency and drift

- Single-row marks thread `RowVersion` (§3, 0.4); a conflicted row reports stale and is
  left for reload rather than clobbered. **The bulk arm stays set-based and is not per-row
  versioned** — see 0.4 for why that is the correct semantics rather than a shortcut.
- If the plan changed after the last print, the view shows **"the plan changed since this
  was printed on &lt;date&gt;"** — because a walk-on renumbers the screen and the paper
  diverges.

---

## 6. Thread 3 — Bulk grant in the Recommendations Manager

### The safety analysis

The earlier design justified a bulk grant on the grounds that an itemized review sheet
satisfies the "never insta-grant" rule from
`2026-07-10-recs-manager-grant-modal-design.md`. That reads the letter of the rule and
misses what actually makes bulk staging safe in the Court Planner:

| Property | CP staging | RM grant |
|---|---|---|
| First write is permanent | No — staging writes nothing | **Yes** |
| Second gate before commit | Yes — Finalize | **No** |
| Idempotency key | Yes — `claimStagedForGrant` | **No** — `AddAward` has no dup guard; a retry writes a second permanent award (`Recommendations_manage.tpl:1283-1285`) |
| Undo | Free before finalize | **None in RM** — revoke lives on the player profile, so undoing 25 is 25 profile visits |

A bulk action with a 25-visit undo and a retry path that double-writes is a trap. The two
paths are **not** equivalent and the design must not treat them as such.

There is also a field the earlier review sheet omitted: the RM grant modal's
**court-reconciliation choice** (Grant & Remove from court vs Grant & Leave on court),
which appears only when a rec is on a court plan. In a 25-row batch some rows are on courts
and some are not, and the server default is `leave` — so a batch would silently mark court
lines given for rows the officer did not know were planned.

### Design: a direct batch grant, carrying all four safety properties

**Resolved: a direct grant from a recommendation does not require a court.** Awards are
legitimately given outside court, so routing bulk grant through a court plan would be wrong
domain modeling — it would invent a ceremony that did not happen in order to borrow that
path's safety. Considered and rejected.

That makes the four safety requirements **mandatory rather than optional**, because the
direct path has none of the properties that make Court Planner staging safe (§6 table).

**1 · Per-row idempotency — a new guarded-UPDATE claim method.**

An earlier draft proposed reusing `ResolveRecommendationCluster` as the claim. **That does
not work and must not be built.** `ResolveRecommendationCluster`
(`class.Player.php:4143-4177`) is a `SELECT … WHERE deleted_at IS NULL` followed by a PHP
loop of `DeleteAwardRecommendation` calls, and `DeleteAwardRecommendation` (`:4080-4137`)
finds by primary key with **no liveness predicate** and returns `Success` even for a row
that was already soft-deleted. There is no guarded write anywhere, and the liveness test
lives in a separate earlier SELECT outside any transaction. Two concurrent batches both
select the same live ids, both loop, and **both call `add_player_award` — two permanent
awards.** The sequential double-submit passes, which is why this would look correct in
casual testing.

`claimStagedForGrant` works because it is *one statement* whose WHERE predicate makes the
row lock pick exactly one winner, and whose affected-row count **is** the claim. The claim
here must be built the same way — as a **new lib method**, not a reuse:

```sql
UPDATE ork_recommendations
   SET deleted_at = NOW(), deleted_by = ?
 WHERE mundane_id = ? AND kingdomaward_id = ? AND rank = ?
   AND deleted_at IS NULL
```

Proceed only if `Size() > 0`. The method then performs the side effects
`DeleteAwardRecommendation` performs — notify advocates **before** the soft-delete is
observable, and cascade `recommendation_seconds` — stamped with the same
`deleted_at`/`deleted_by` so `RestoreAwardRecommendation`'s matched cascade still works.

**Liveness predicate must match the selection.** This path uses `deleted_at IS NULL`; the
RM paging query uses `(deleted_by IS NULL OR deleted_by = 0)` (`class.Report.php:1003`).
They agree for rows deleted through the normal path, which sets both — but since the claim
is now the safety mechanism, it must use the same predicate that defined the rows the
officer selected.

**Revert on failure — required, not optional.** The claim's side effect *is* soft-deleting
the cluster. If `add_player_award` then fails, the recommendation is gone and no award
exists; the officer retries, the claim affects zero rows, and the system reports *"already
granted."* Net result: the rec is permanently deleted, no award was written, the officer is
told it succeeded, and the row has vanished from RM so there is nothing to retry from.
`commitStagedAward` handles exactly this with `revertAwardStatus` on both failure paths
(`class.Court.php:878`, `:888`). **On any non-success from `add_player_award`, restore the
claimed cluster via `RestoreAwardRecommendation` (`class.Player.php:4180`, which cascades
seconds by matching `deleted_at`+`deleted_by` and exists for this) before reporting the row
failed.**

This gives the direct path the idempotency `Player::AddAward` lacks, without a new table and
without changing `AddAward` — but **with a new claim method.**

Note this dedupes *this grant*, not the player's award history: the "already holds this
award" advisory stays **non-blocking**, per the locked decision in the grant-safety spec,
because some awards are legitimately repeatable.

**2 · Per-row outcome, never an aggregate.** The batch arm returns a row-keyed result —
granted / skipped-already-granted / failed with reason. The review sheet renders each
outcome inline; a partial failure never reports as success.

**3 · Court reconciliation is a column in the review sheet.** The per-row grant modal asks
Grant & Remove from court vs Grant & Leave on court, but only when the rec is on a court
plan. In a 25-row batch some rows are on courts and some are not, and the server default is
`leave` — so a batch without this column silently marks court lines given for rows the
officer did not know were planned. The sheet shows a court badge on affected rows with a
per-row choice and a set-all control, defaulting to **Remove** (the modal's own default),
and reconciliation runs server-side through the existing
`Court::reconcileGrantForRecommendation`.

**4 · Batch-level undo — satisfied by a pre-commit hold.** There is no undo affordance,
because **nothing is written until the officer commits.**

The review sheet *is* the hold. Rows are assembled, reviewed, edited, and removed freely;
no claim is taken and no award is written while the sheet is open. A single explicit
**"Commit Awards to the ORK"** button performs the write.

This resolves the requirement without any of the traps the alternatives carried:

- **No new destructive path.** Nothing is deleted, because nothing was written.
- **No revocation.** `revoke_award` writes `stripped_from`, `revoked = 1`, and a mandatory
  reason onto a permanent record (`class.Player.php:3343-3355`) — correct as the domain tool
  for an award that was genuinely given and must be taken back, wrong as an "undo." It stays
  available on the player profile for post-commit correction, correctly named.
- **No batch-id storage**, so no second schema question.
- **Undo is free and obvious**: remove rows from the sheet, or close it.

It also matches vocabulary the module already teaches. Court Planner stages and then
finalizes; this stages in the sheet and then commits, in plain language, without inventing a
court that did not happen.

**Mechanics:**

- **Claim and write happen only on commit**, per row, in the order given by requirement 1.
  Requirement 1 is still load-bearing: it protects the commit against a double-click and
  against a second officer committing an overlapping selection at the same moment.
- **In-flight guard:** the commit button disables while the batch is outstanding.
- **The hold is client-side and deliberately not persisted.** Navigating away discards it,
  which is safe precisely because nothing was written — but the view warns on navigation
  while rows are uncommitted ("N awards not yet committed"), so the discard is never silent.
- **Post-commit**, per-row outcomes render inline (requirement 2) and the committed rows
  leave the grid.

**If any of the four cannot be delivered, cut the feature** rather than ship a bulk
permanent write with no undo.

### Review sheet

Select N → **Grant N awards** → a full-screen sheet (§10) listing every row with recipient,
award, rank (editable pills), giver, date, and — where applicable — the court reconciliation
choice. Defaults come from a top strip set once; rows can be edited or removed. Nothing is
written while the sheet is open.

**"Commit Awards to the ORK"** is the single write. Per-row results render inline
afterwards, and committed rows leave the grid.

---

## 7. Thread 4 — Selection and true select-all

### C1 — Selection model in the Court Planner

Checkboxes on award rows plus a bulk bar: set scroll maker, set regalia maker (one player
search applied to N), mark scrolls/regalia status, pass to local, remove, move to top or
bottom. The scribe who made eight scrolls is one person, entered eight times today.

**Column headers open a three-item menu — "Set all to: Not started / In progress / Ready"
— never a blind cycle.** Scroll and regalia status is state officers accumulate over weeks;
a single unmodified click that overwrote all thirty rows with no confirm and no undo would
be destructive, and cycling makes the second misclick advance rather than restore.

### C3 — True select-all

Checking the header box selects the loaded page and surfaces a banner offering selection of
everything matching the current filters, switching to **criteria mode** (the filter
payload, not an id list), with bulk endpoints accepting criteria.

Two constraints the earlier design missed:

- **The count must be the post-filter count.** `Total` is a SQL `COUNT(*)`, but groups are
  filtered again in PHP and `NextOffset` advances by SQL page size
  (`class.Report.php:1006-1085`), so "Select all 1,240" would name a number that is
  knowably not the set acted on. The criteria endpoint must return the post-filter count
  and the banner must use it.
- **Criteria mode ships only for additive and reversible actions** — Add to Court, Snooze,
  Pass down. **Dismiss is excluded**: it soft-deletes with no undo surfaced in RM, and
  generalizing it to criteria mode would take the blast radius from 500 loaded, visible
  rows to every row matching a possibly-misread filter, most never seen. If Dismiss must
  have it later, require the officer to type the count.

  **Excluded means disabled with a visible reason, never silently narrowed.** In criteria
  mode the Dismiss button is disabled and reads *"Dismiss can't be applied to a filter-wide
  selection — clear the selection to dismiss the rows you can see."* Silently falling back
  to the 500 loaded rows would be the original bug wearing a new hat: the officer believes
  they dismissed 1,240 and dismissed 500, with no signal.

---

## 8. Thread 5 — Data entry friction

- **D1 · "Save & add another"** on the ad-hoc modal: keeps the recipient search focused,
  clears the form, optionally holds the award — five people receiving the same order is a
  common shape.
- **D2 · Artisans inline** in the expand row (persona search + contribution + Enter),
  retiring `cpOpenArtisanModal`.
- **D3 · Selective autosave.** Autosave on blur for the **internal-only** fields —
  Internal Notes, scroll maker, regalia maker, Pass-to-Local. **Public Comment keeps an
  explicit save**, because it becomes `ork_awards.note`: public, permanent, admin-only to
  correct. Autosave-on-blur there would commit a half-typed fragment on a stray click, and
  the officer being interrupted mid-sentence is normal. A secondary reason: `update_award`
  participates in the `RowVersion` optimistic lock (`CourtAjax.php:281-292`), so
  blur-frequency writes in a two-officer court produce stale-reload churn.
- **D4 · Rank pills everywhere.** The CP grant modal is the lone `<input type="number"
  max="99">`, and it is the path that stages a permanent rank. Pills also show which ranks
  are already held. Cheapest item here; ship it first.
- **D5 · Flatpickr `altInput`** with the house human-readable format on every date surface
  — currently `type="date"` in two places and a readonly text field in a third, none
  matching the convention.

---

## 9. Thread 6 — Triage & findability (RM)

- **Fold award history into the existing expander — do not add a detail rail.** RM already
  renders every member's recommender, date, reason, and each second with its note inline
  (`.rm-expand-members` `:782-802`), opened from both the reason cell and the support chip.
  A rail would be a third affordance for the same data on a page these officers use a few
  times a year. What is genuinely missing is the recipient's award history and time since
  their last award; add **that** to the expander.
- **Saved views** as chips — *Ready for court*, *High consensus*, *Neglected*, *Passed to
  local*. Each is a filter payload; one click replaces four control changes.
- **URL state** for filters and sort. `rmState` (`:807`) is purely in-memory, so returning
  from a court detail page loses everything. This is the only item addressing interrupted
  work in RM, and it makes a filtered list shareable between officers — *"here is the list
  I want you to look at"* is a real workflow. **Ship this early**, not last.
- **Support ≥ N filter — blocked pending reconciliation.** The SQL `support_count` exists
  "for the 'supp' sort tiebreaker only"; the displayed value is computed in PHP by
  `groupRecommendations()` (`class.Report.php:974-976`, `:621`). A numeric filter would
  select rows whose visible Support column disagrees with it. Either reconcile the two
  expressions first, or ship it as a coarse post-filter on the loaded page and label it as
  such. **Do not ship a numeric filter against a column it contradicts.**
- **Age and Award filters** — unblocked, ship with the saved views.
- **Rec picker: align the vocabulary, defer the merge.** CP's picker renders every
  `pendingRecs` row server-side and filters client-side (`:1857-1872`); RM is
  server-paginated at 500 with server-side filters. Unifying the components is a
  pagination-model change on the CP side, not a component extraction. Making the filter
  vocabulary identical (Open / All / Snoozed / Already Has vs RM's `elig` values) delivers
  most of the user-visible benefit for a fraction of the work.
- **Keyboard acceleration** (`j`/`k` cursor in RM, `↑`/`↓`/`G`/`S` in Record Court) ships
  as a **bonus, not a cost model.** These users are perpetual beginners who open the tool a
  few times a year and will not discover or retain shortcuts. No interaction budget in this
  document assumes them.

---

## 10. Mobile — a requirement on every thread, not a thread of its own

**Every surface in this document must work on a phone.** This is a cross-cutting acceptance
criterion, not a follow-up pass, and it changes the cost of two threads materially.

**The Recommendations Manager has no responsive CSS whatsoever** — zero `@media` blocks
against a ten-column dense grid with a sticky recipient column. It is the single largest
mobile gap in either module, and it is load-bearing for Threads 3, 4 and 6, all of which add
controls to that grid.

- **The RM grid → cards below ~700px** (Thread 0, item 0.8). Each cluster becomes a card —
  recipient and park as the heading, award and rank beneath, support and age as chips, the
  court badge inline. Selection stays a real checkbox at ≥44px. Filters collapse into a
  single "Filters" sheet; the saved-view chips (§9) stay horizontally scrollable because
  they are the fastest path on a phone.
- **The row actions must keep their explanations.** Two of the five carry their entire
  meaning in a **hover-only** rich tooltip: `.rm-snooze-tip` explains that snoozing lasts
  until the Monarch or Regent changes, and `.rm-passlocal-tip` explains that passing down
  grants the park authority to award at that level. **There is no hover on a phone.** A bare
  label reading *Pass down* tells a perpetual beginner nothing, and this is the one place
  the collapse could make the page worse than the unusable-but-explanatory desktop grid. The
  existing tooltip copy is already written and must get a non-hover home — helper text under
  the label, or a tap-to-reveal info affordance.
- **Five labelled buttons do not fit a 390px card** — at 44px height with readable labels
  that is ~350px of buttons before gaps. Specify the shape rather than leaving it to be
  built as a horizontal scroll that then fails this section's own acceptance criterion:
  **Grant and Add-to-court inline; snooze, pass-down and dismiss behind an overflow
  control.**
- **The bulk bar → bottom sheet.** A fixed bar at the bottom of a phone viewport collides
  with browser chrome and the keyboard.
- **The Thread 3 review sheet is NOT a bottom sheet — it is a full-screen takeover.** It
  carries six fields per row (recipient, award, editable rank pills, giver, date,
  reconciliation choice) across up to 25 rows; a height-constrained sheet turns that into a
  long editable form in a small scroll viewport with the soft keyboard covering half of it
  on every giver search. The semantics are also wrong: bottom sheets dismiss on swipe-down
  and tap-outside, and a dismiss gesture on 25 reviewed permanent grants is a data-loss
  gesture on the most dangerous surface in this document. Full-screen, with an explicit
  Cancel.
- **Record Court (Thread 2) is explicitly a phone and tablet surface.** Someone will do the
  catch-up on a tablet at the site. The three-state control is already touch-friendly at
  ≥44px; the giver chip, the citation textarea, and the inline walk-on row's three searches
  are the risky parts. Below ~700px the row becomes a card in the same visual language as
  the printed sheet's row, and the inline walk-on row becomes a stacked form.
- **The Court Planner has 768px and 600px blocks to build on** (`Court_detail.tpl:345`,
  `:1092`) including deliberate touch sizing — new planner surface extends them rather than
  inventing a third breakpoint.
- **Autocomplete dropdowns** in any of these must follow the house rule: `position:fixed`
  via `tnFixedAcPosition()` before opening, which is a recurring bug when missed.
- **Printed sheets are exempt** — paper has no viewport.

Acceptance: every new and modified surface walked at 390px and 768px, in light and dark, with
no horizontal page scroll and no interactive target below 44px.

---

## 11. Open decisions

**D2 — Are the three sheets printed as one packet or separately by different people?** This
decides whether the printed-at stamp needs to be per-sheet and confirms the single-page-
geometry decision.

**D3 — How often is a court created with a blank or wrong date?** The local database holds
too few courts to tell. It does not change whether §3, 0.1 ships — the hazard is structural
— but it does affect its priority relative to the rest of Thread 0.

### Resolved during review

**Direct grants from recommendations do not require a court.** Routing bulk grant through a
court plan would invent a ceremony that did not happen in order to borrow that path's
safety, which is wrong domain modeling. The direct batch path is the design (§6), and the
four safety properties became mandatory as a result.

**Every surface must be mobile ready.** Not a follow-up pass — a blocking acceptance
criterion on every thread (§10), which promoted the RM card collapse to Thread 0 (0.8).

**The bulk grant is a pre-commit hold with an explicit "Commit Awards to the ORK" button.**
Requirement 4 is satisfied by construction rather than by an undo mechanism: nothing is
written until commit, so there is nothing to reverse. Revoke stays the correct tool for
post-commit correction, correctly named, on the player profile.

**Can the officer who does the catch-up actually record it?** Verified — yes, in the normal
case. `Court::canManage` (`class.Court.php:22-66`) admits anyone holding `AUTH_EDIT` on the
kingdom or the park, **plus** officers with role `Monarch`, `Regent`, or **`Prime Minister`**
at kingdom or park level. The PM is typically the officer who does data entry, so Thread 2's
premise holds. **Residual risk:** a pure scribe or deputy with no officer role and no
edit authority is blocked, and would need edit authority granted rather than a new
delegation mechanism. Note the role match is exact-case in SQL and therefore
collation-dependent.

**Does changing the court date mid-pass re-date already-staged rows?** Yes, and
deliberately. `commitStagedAward` reads `CourtDate` at commit time (`class.Court.php:897`),
so a date corrected in `published` applies to every row that has not yet been finalized.
That is the behavior this workflow wants — the recorder discovers the wrong date *while*
recording — but the opposite reading is equally plausible to a builder, so it is stated
here.

---

## 12. Schema changes

One migration, `db-migrations/2026-09-01-court-recorder.sql`:

```sql
ALTER TABLE ork_court
  ADD COLUMN recorder_mundane_id INT NULL DEFAULT NULL AFTER finalized_by;
```

No column exists today for 0.7's recorder (`db-migrations/2026-03-16-add-court-planner.sql`).

**The new migration file must also be classified in ork-db's
`migration-classification.json5`**, or `drift-check --strict` blocks the entire unit-test
run — an unclassified migration is a broken build for whoever picks the work up, not a
paperwork omission.

Everything else in this document reuses existing columns. The bulk grant's pre-commit hold
is client-side and writes nothing until commit, so it needs no storage for batch ids.

---

## 13. Build order

1. **Thread 0 — correctness only.** In this order: unrecorded-court surfacing (0.5, highest
   value for the stated workflow and zero dependencies), recorder assignment + notification
   (0.7, which completes 0.5 by making the court someone's), `update_court` (0.1), partial
   `update_award` (0.6), RM card collapse (0.8, independent and unblocks the mobile
   requirement on the worst page), select-all honesty (0.2), skipped rows on print (0.3),
   `RowVersion` on `grant_award` (0.4). 0.7 depends on 0.1 for the editable field and on the
   migration in §12.
   Thread 0 contains **no enhancements**, so it can ship and be reviewed as one tight
   correctness PR — that separation is the entire value of the thread. Rank pills (§8 D4)
   and RM URL state (§9) are cheap and independent, but they lead Threads 5 and 6 rather
   than riding along here.
2. **Threads 1 + 2 together — paper and the catch-up pass.** They are one design; the sheet
   is the view's specification. Depends on 0.1 (top strip), 0.3 (struck-through rows), and
   **0.6 (or the citation save destroys data)**.
3. **Thread 5 — data entry friction**, led by rank pills. Depends on 0.6 for D3.
4. **Thread 4 — selection and criteria mode.** Extends 0.8's cards with the new controls
   rather than owning the collapse.
5. **Thread 3 — RM bulk grant.** Last, and dependent rather than merely ungated: the review
   sheet needs Thread 4's selection model and criteria banner, and its mobile form needs
   0.8's cards. All four safety properties in §6 are now specified and deliverable.
6. **Thread 6 — triage & findability**, led by URL state. Support filter gated on the count
   reconciliation.

Threads 1+2 and Thread 6 should become **separate implementation plans**; they share no
code and have different reviewers' concerns.

---

## 14. Risks

- **Threads 1+2 rest on `update_court` existing.** If 0.1 slips, the Record Court top strip
  edits nothing and the date hazard remains live.
- **The batch stage arm is a new write path into a sink that was adversarially reviewed.**
  It must go through `commitStagedAward` and the existing claim, not around it.
- **Print output cannot be verified by automated tests.** Every sheet needs a physical or
  PDF check at real page size, in both a short court and one that spans pages.
- **Criteria mode changes the meaning of an existing control.** An officer who has learned
  that select-all means "the ones I can see" gets a different behavior; the banner must make
  the switch explicit rather than silent.
- **`Total` being approximate is pre-existing**, but criteria mode is the first feature that
  would make the approximation consequential.
- **Thread 3 is still the most dangerous work in this document**, though the pre-commit hold
  removes the worst of it: the commit is deliberate and nothing is written before it. What
  remains is the claim itself — it must be a single guarded UPDATE whose affected-row count
  is the claim, it must revert on failure, and its **concurrent** behavior must be tested,
  because the sequential case passes even when the mechanism is broken.
- **Mobile is now blocking on every thread**, and the page that most needs it has zero
  responsive CSS today. This is the largest schedule risk in the document; 0.8 exists to
  take it off the critical path of everything downstream.
- **The recorder handoff is only as good as the recorder's attention.** 0.7 names a person
  and tells them once; if the notification and the home-surface line are both missed, the
  court stays unrecorded and the design has relocated the silence rather than removed it.
  Accepted deliberately — escalation machinery is not proportionate for volunteers.

---

## 15. Verification

- **Court date:** create a court with no date, stage and finalize, assert `ork_awards.date`
  equals the court date after it is set via `update_court` — not the catch-up day.
- **Record Court cost:** walk the 30-award scenario and count interactions; the claim is
  22 against today's ≈37. If the count does not hold, the design is wrong, not the claim.
- **Citation round-trip:** a walk-on added in Record Court with a citation lands that text
  in `ork_awards.note` and renders on the public Court Report.
- **Concurrency:** two sessions recording the same court; a conflicted row returns stale
  and does not clobber.
- **Print:** all three sheets at real page size, multi-page, with `thead` repeating; a
  reprint after a partial session shows skipped rows struck through.
- **Criteria mode:** the banner count equals the number of rows actually acted on; Dismiss
  is not offered criteria mode.
- **Idempotency:** double-submit the batch stage arm; assert exactly one `ork_awards` row
  per court line, and zero `court_award` rows `given` with `award_id IS NULL`.
- **Partial update (0.6):** POST only `PublicComment` to `update_award` and assert `notes`,
  `pass_to_local`, `scroll_maker_id` and `regalia_maker_id` are **unchanged** in the row.
  This is the regression test for the data-loss path this design would otherwise introduce.
- **Interrupted pass:** mark several rows, reload mid-pass, assert every mark persisted and
  the staged count matches.
- **Unrecorded-court banner:** a published court with a NULL date and nothing recorded
  appears in the warning; a half-recorded court does not.
- **Recorder default:** publishing a park court names the park PM; a park with no PM on
  record falls back to the kingdom PM, then to the publisher. The notification lands for
  that person with a working Record Court link.
- **Batch grant idempotency (Thread 3) — must be CONCURRENT.** Two parallel requests against
  the same cluster, not a sequential double-submit: the sequential case passes even on a
  broken mechanism (the second SELECT simply finds no live recs), so a sequential-only test
  would certify the defect. Assert exactly one `ork_awards` row per cluster and that the
  loser reports already-granted.
- **Claim revert:** force `add_player_award` to fail after a successful claim; assert the
  recommendation is **live again** and the row is reported failed, not already-granted.
- **Pre-commit hold:** open the review sheet over 25 rows, edit and remove some, then
  navigate away — assert **zero** `ork_awards` rows written, zero recommendations claimed,
  and that the navigation warning fired. Then repeat and commit: assert the write happens
  once, and that a double-click on "Commit Awards to the ORK" still yields one award per
  cluster.
- **Mobile:** every new and modified surface at 390px and 768px, light and dark — no
  horizontal page scroll, no interactive target under 44px, autocomplete dropdowns
  positioned via `tnFixedAcPosition()`.
- **Conventions:** `.tpl` is plain PHP; `$DB->Clear()` before raw execute; `tnConfirm`, no
  native dialogs; dark-mode walk of every new surface; `data-tip` not `title`; scoped player
  search with `&q=`; `php -l` clean; normalize-first before editing.
