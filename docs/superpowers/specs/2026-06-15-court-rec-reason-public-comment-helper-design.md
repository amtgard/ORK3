# Court Planner — Rec-Reason Helper Text in Public Comment

**Date:** 2026-06-15
**Branch:** `feature/court-planner`
**Route affected:** `Court/detail/{court_id}`
**Primary file:** `orkui/template/default/Court_detail.tpl` (+ small `controller.CourtAjax.php` change)

## Problem

On the Court detail planning page, each planned award has an expandable row with a
**Public Comment** textarea (shown on the public Court Report). When an award was added
from a recommendation, that recommendation often has a free-text **reason** the advocate
wrote. Today that reason is invisible in the planning UI, so the monarchy can't easily
reuse it as the public comment.

We want to surface the rec reason as gray helper text in the Public Comment box, with
quick actions to adopt or discard it — **without** the reason ever counting as the public
comment unless the user affirmatively chooses to use it.

## Key existing facts (verified)

- Each award row's Public Comment is `<textarea id="cp-pubcomment-{caid}">`, rendered in
  **two** places that must stay in sync:
  - PHP server-render: `Court_detail.tpl` ~line 1079–1081.
  - JS row-builder `cpAppendAwardRow` ~line 2177 (used when awards are added from the
    rec-picker modal).
- The rec reason is already loaded server-side as `$aw['RecReason']`
  (`class.Court.php` joins `recommendations.reason`, mapped at ~line 192).
- The `add_award` AJAX response (`controller.CourtAjax.php` ~line 170–189) does **not**
  yet include `RecReason`; JS-added rows need it added.
- Awards added from recs already store `public_comment = ''` (bulk add at
  `Court_detail.tpl` ~line 2041–2051 sends no `PublicComment`).
- The Court Report reads the DB column (`Reports_court.tpl:63`) — empty renders as "—".
- Save (`cpSaveAward`, ~line 1755–1766) sends `pubCommentEl.value`.
- Grant (`grant_award`) uses `notes`, **not** `public_comment`.

**Consequence:** keeping the textarea *value* empty until the user engages automatically
satisfies "treat as no text entry for Court Report, Grant, etc." — because save sends the
empty value and the report reads the (empty) DB column.

## Trigger condition

The helper UI appears only when **both**:
1. `RecReason` is non-empty (award came from a rec that had a reason), **and**
2. the saved `public_comment` is empty.

If a non-empty `public_comment` already exists, render the textarea normally with **no
overlay and no buttons** (decided: hide buttons when filled). On reload, an award the user
has engaged with will have a saved `public_comment`, so it falls into this normal path.

## UI design

When triggered, the Public Comment field shows:

- The "Public Comment" label, with two small inline text-buttons beside it:
  **(Start from Rec)** and **(Clear)**.
- The textarea with `value=""`, and a gray, italic, wrapping overlay positioned over it
  showing the rec reason as helper text. The overlay:
  - uses the existing rec-reason colors (`#718096` light / `#a0aec0` dark — same as
    `.cp-rm-reason`), is dark-mode-correct from the start;
  - has `pointer-events:none` so a click lands on the textarea beneath it;
  - wraps text (`white-space:normal`); long reasons may visually clip — acceptable for a hint.

## State model (per textarea)

Tracked with a `data-rec-engaged="0|1"` flag on the field wrapper.

| State | Trigger | textarea value | Overlay | Counts as |
|-------|---------|----------------|---------|-----------|
| **Untouched** | initial (engaged=0) | `""` | visible | empty (save sends `""`, report shows "—") |
| **Engaged-from-rec** | click into box **or** (Start from Rec) | filled with rec reason (real editable text) | hidden | whatever's in the box |
| **Cleared** | (Clear) | `""` (editable, focused) | hidden | whatever's in the box |

Behaviors:
- **Click into the box** (focus while engaged=0 and not cleared): hide overlay, set
  `value = recReason`, set engaged=1. Cursor lands where clicked (natural focus).
- **(Start from Rec)**: hide overlay, set `value = recReason`, set engaged=1, focus and
  move cursor to the **end** of the text.
- **(Clear)**: hide overlay, leave `value=""`, set engaged=1, focus the empty box (cursor
  in box). Because engaged=1, focus will not re-fill.
- Once engaged=1, the textarea behaves as an ordinary textarea; the overlay never returns
  for that row in the current page session.

## Implementation plan

1. **`controller.CourtAjax.php` `add_award`** — add `'RecReason'` to the response array.
   When `$rec_id` is set, read `recommendations.reason` (single guarded query, `$DB->Clear()`
   first) and include it; else empty string. This lets JS-added rows render the helper.

2. **Shared CSS** — add `.cp-pubcomment-wrap` (position:relative) and `.cp-rec-hint`
   (overlay) styles plus the small `.cp-rec-hint-btn` button style, with dark-mode
   variants, alongside the existing `cp-` styles in the template `<style>` block.

3. **PHP render path (~line 1079)** — replace the lone Public Comment textarea with the
   wrapper markup: label + (when triggered) the two buttons, the textarea, and (when
   triggered) the overlay div. Put the rec reason in a `data-rec-reason` attribute on the
   wrapper for the JS wirer. Gate the overlay/buttons on
   `RecReason !== '' && PublicComment === ''`.

4. **JS render path `cpAppendAwardRow` (~line 2177)** — emit the identical wrapper markup,
   reading `aw.RecReason` and `aw.PublicComment`. Factor the markup into a small helper
   (e.g. `cpPubCommentFieldHtml(caid, recReason, publicComment)`) to avoid divergence.

5. **`cpWirePubComment(caid)`** — a single wirer that reads `data-rec-reason` off the
   wrapper and attaches the focus + button handlers per the state model. Call it for every
   server-rendered row on `DOMContentLoaded`, and for each row appended by
   `cpAppendAwardRow`.

6. **No save-path change needed** — `cpSaveAward` already sends the textarea value, which
   stays empty until engagement.

## Out of scope

- The "Add ad-hoc award" modal (`cp-adhoc-pubcomment`) — ad-hoc awards aren't from recs,
  so there is no rec reason. Unchanged.
- The rec-picker modal — it bulk-adds awards and has no Public Comment field. Unchanged.
- Server-side storage shape — `public_comment` already defaults to empty for rec awards;
  no migration or model change.

## Testing

- Award from a rec **with** a reason, untouched → gray overlay shows reason; Save (after
  editing only Status) → `public_comment` stays empty; Court Report shows "—".
- Click into the box → overlay gone, box holds the rec reason, editable.
- (Start from Rec) → box holds rec reason, cursor at end.
- (Clear) → empty editable box, cursor in box; overlay does not reappear.
- Reload after engaging-and-saving → renders as normal textarea with saved text, no overlay/buttons.
- Award from a rec with **no** reason, and ad-hoc award → no overlay/buttons (unchanged behavior).
- Dark mode: overlay + buttons legible.
