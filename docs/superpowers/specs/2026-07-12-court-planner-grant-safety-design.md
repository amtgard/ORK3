# Court Planner — Grant Safety, Workflow, Mobile & Collaboration

**Date:** 2026-07-12
**Branch:** feature/court-planner
**Origin:** Six-expert usability/process review of the Court Planner module (LEAN, UX, domain, data-integrity, accessibility, collaboration). This spec implements the selected roadmap items: **S1** (idempotent grant sink) + **Quick Wins 1–9** + **S2** (mode drives workflow) + **S3** (responsive mobile) + **S5** (optimistic concurrency + presence).

## Core files
- `orkui/controller/controller.Court.php`, `orkui/controller/controller.CourtAjax.php`
- `system/lib/ork3/class.Court.php`
- `system/lib/ork3/class.Player.php` (`AddAward`)
- `orkui/controller/controller.PlayerAjax.php` (`grantaward` — Recs-Manager path)
- `orkui/template/default/Court_detail.tpl`, `Court_list.tpl`
- `orkui/template/default/Recommendations_manage.tpl`
- Embedded: `orkui/template/revised-frontend/Kingdomnew_index.tpl` / `Parknew_index.tpl`

## Locked product decisions
1. **Run-mode grant = stage + auto-finalize on Complete.** Live "Grant" stages under the hood, shows the row Given optimistically with Undo; the permanent `ork_awards` write happens on Complete Court via the throw-safe transactional finalize. Preserves Undo, offline safety, idempotency; one-verb mental model.
2. **Duplicate handling = warn on all, block none.** The only hard guarantee is the court-line idempotency key (a `court_award` commits at most once). Any "player already holds this award" is a non-blocking advisory the officer can override (respects legitimately-repeatable awards).

---

## S1 — One idempotent, server-reconciled grant sink

**Problem:** two paths write `ork_awards` (`Player::AddAward`, no dedup), coordinating only via a stale client `data-courts` attribute; finalize guesses the new award id by a date heuristic (`findRecentAwardId`).

**Design:**
- **Single commit path** `Court::commitStagedAward($court_award_id, $ctx)`. The **idempotency key is the court-line identity**: atomic claim `staged→given` (`claimStagedForGrant`, `Size()==1`) means a line commits at most once; re-runs / double-clicks are no-ops.
- **`Player::AddAward` returns the real inserted id** (`$awards->awards_id`, surfaced on the `Success` payload). Finalize links `award_id` from it. **Delete `findRecentAwardId` and its call sites.**
- **Server-side cross-path reconcile** `Court::reconcileGrantForRecommendation($recommendations_id, $awards_id, $given_by, …)`: when the Recs-Manager `grantaward` path fires, the server (not the client) finds any open (`planned`/`announced`/`staged`) court line for that recommendation and links/marks it `given` with `award_id`+`given_by` in the same request — or cancels it — so finalize cannot re-grant. Client `data-courts` no longer trusted for correctness.
- **"Already holds" advisory** surfaced (non-blocking) in the grant modal and add-from-recs, reusing the existing `AlreadyHas` data.

**Acceptance:** double-submitting a grant, or granting the same rec via both paths, yields exactly one `ork_awards` row. No `court_award` ends `given` with `award_id IS NULL`.

## QW#2 — Kill the complete-bypass
`update_court_status` rejects `'complete'` (returns an actionable error pointing at `finalize_court`). A court can only reach `complete` through finalize, so staged rows can never be orphaned. **Acceptance:** zero courts with `status='complete' AND finalized_at IS NULL`.

## QW#3 — Throw-safe finalize
Keep claim-first (concurrency safety); wrap `AddAward` in `try/catch (\Throwable)`. On throw **or** returned error → revert `given→staged`, push to `failed[]`, continue. Link the returned `award_id` on success. Court completes only when `failed` is empty (unchanged). **Acceptance:** a thrown `AddAward` leaves the row `staged` and re-runnable; zero silent lost grants.

## QW#4 — `update_award` no lifecycle downgrade
`update_award` writes field edits only (notes / public_comment / pass_to_local / makers) — **never `status`**. Status transitions go solely through stage/unstage/skip/set-status. Client: `cpSetRowStatus` also updates the row `<select>`; `cpSaveAward` stops sending `status`. **Acceptance:** a stale field-save can never move a row's lifecycle backward.

## QW#5 — Guard destructive endpoints vs `given`
- `removeAward`: refuse/soft-cancel a `given` row (never hard-DELETE committed history).
- `skip_award`, `setAwardStatus`: `AND status != 'given'`; 0 rows → return "already granted".
**Acceptance:** a finalized row's audit trace cannot be destroyed by a stale skip/remove.

## QW#6 — Lift the draft-only add gate (walk-ons)
Show "+ Add award" (ad-hoc + from-recs) in published/run mode; new rows insert `planned` at the end. Controller already permits (`add_award` checks auth, not status). **Acceptance:** an officer can add a recipient after publish without returning to Planning.

## QW#7 — Inline un-skip + tap targets
Cancelled rows get an inline **Un-skip** (→ `planned`, guarded). Interactive controls get ≥44px hit area on touch viewports; density shrinks rows, not targets.

## QW#8 — Accessibility additive pass (markup only; no rearchitecture)
`aria-live` polite+assertive regions (grant / error / staged-count / heartbeat changes); `label for=`/`aria-labelledby`; `aria-label` on icon-only buttons; contrast fixes (retire `#a0aec0`-on-white, darken `#718096`, darken Staged badge text to ≥4.5:1); glyph+color on scroll/regalia trackers; `role="dialog"`/`aria-modal`/`aria-labelledby` on modals + autofocus the grant modal; shared `:focus-visible` ring; `prefers-reduced-motion` block; promote the court name to `<h1>` (reset heading-box styles).
**Out of scope (deferred as S8, not selected):** making rows/trackers fully keyboard-focusable, ARIA combobox on player search, full modal focus-trap/return. Note as follow-ups.

## QW#9 — No native dialogs
Replace the reachable `confirm()`/`alert()` fallbacks (`Court_detail.tpl:1775, 2234, 2240, 2664`) with the app's non-blocking confirm/toast. **Acceptance:** zero reachable `window.confirm/alert/prompt`.

## S2 — Mode drives the workflow
- **Run mode:** button label **"Grant"** (not "Stage Grant"); tap stages under the hood + shows the row **Given** optimistically with **Undo**; **Complete Court auto-runs the transactional finalize**. Staged vocabulary hidden; banner reads "N to record on Complete."
- **Plan mode:** unchanged — explicit Stage → Record All → Finalize.
- Controllers read `mode` (already stored) to choose labels + the auto-finalize-on-complete branch.

## S3 — Responsive mobile + run-mode ceremony layout
- Immediate: wrap `.cp-award-list` in `overflow-x:auto` (Grant/Skip reachable now).
- `@media (max-width:640px)`: collapse the fixed-px grid into **stacked cards** — recipient/award on top, full-width **Grant** primary + **Skip** secondary beneath, all ≥44px. Doubles as the run-mode touch layout.

## S5 — Optimistic concurrency + full-field reconcile + presence
- **New column `ork_court_award.row_version INT NOT NULL DEFAULT 0`**, bumped on every mutating write (reliable token; `modified` is second-granular and collision-prone).
- Mutating endpoints accept the client's `row_version`; `UPDATE … AND row_version=?`; 0 rows affected → **409 → non-destructive "this row changed — reload" toast**. Reorder uses the existing court-level md5 version.
- Heartbeat **reconciles the full row set** (adds / removes / notes / makers), not just status/giver/sort.
- **Presence** via memcache (GhettoCache) keyed by court; `court_state` returns the roster → **"N officers viewing"** + honest **"Synced Ns ago" / "Reconnecting…"** chip. Background heartbeat goes silent (no error-toast spam).

---

## Data model change
One migration `db-migrations/2026-07-12-court-grant-safety.sql`:
```sql
ALTER TABLE ork_court_award
    ADD COLUMN row_version INT NOT NULL DEFAULT 0 AFTER modified;
```
Everything else reuses existing columns (`given_by_mundane_id`, `award_id`, `status`, `mode`, `finalized_at/by`, `modified`). Presence is memcache-only (no schema).

## Build order (subagent-driven, by dependency)
1. **Data + lib correctness core** — migration; S1 sink; `AddAward` returns id + delete heuristic; throw-safe finalize; kill complete-bypass; `update_award` no status; guards; optimistic-lock helpers; reconcile method.
2. **Controllers** — mode-aware run/plan; auto-finalize on complete; presence; `row_version` tokens + 409; server reconcile in grant path; lift draft-only add gate.
3. **Template / UI** — overflow wrapper + stacked cards; run labels + big-button; walk-on add; inline un-skip + tap targets; a11y additive pass; native-dialog replacement; sync chip + presence; full-field reconcile client.
4. **QA + verification** — Comprehensive QA Protocol (≥2 cycles).

## Verification
- **Curl-auth idempotency tests:** double-submit grant; cross-path grant (Recs-Manager + finalize on same rec); mid-finalize interruption; `update_court_status('complete')` rejected.
- **SQL assertions:** zero duplicate active `ork_awards` (same mundane+kingdomaward+rank, non-revoked, from one court); zero `court_award` `given`+`award_id IS NULL`; zero `complete`+`finalized_at IS NULL`; zero `cancelled` rows with non-null `award_id`.
- **Claude-in-Chrome:** mobile Grant reachability at 390px; a11y smoke (aria-live announced, labels associated, contrast).

## Conventions / guardrails
- `.tpl` = plain PHP. Dark mode `html[data-theme="dark"]`. FontAwesome 5.8.2 only. `$DB->Clear()` before raw Execute/DataSet. Controller session via `$this->session`. Never stage `class.Authorization.php` (bypass hack) or `CLAUDE.md`. Normalize-first before editing PHP/tpl (php-cs-fixer if dirty). Human-readable dates. No `error_log`/`print_r` debugging — browser console / `die(json_encode())`.
