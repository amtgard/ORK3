# Court Planner — Stage/Finalize, Run-vs-Plan, and Ceremony Enhancements

**Date:** 2026-07-11
**Status:** Approved design → implementation
**Author:** Court Planner enhancement pass

## 1. Motivation

A usability/process review of the Court Planner surfaced two classes of friction:

- **Defect risk** — a Grant writes to the permanent player-award record on a single click
  with no undo, no confirmation, no captured giver (`GivenById = 0`), and no
  duplicate/eligibility guard. There is no way to reverse a mistaken grant.
- **Overloaded "Publish"** — the tool assumes one person live-runs the ceremony, but it is
  equally used as a *planning* tool: an officer prepares the order of court, prints it, and
  a different officer records the grants later. "Publish" conflates "I am about to run this
  live" with "the plan is locked for someone else to process."

This spec addresses both by splitting **granting** from **committing to the record**
(stage → finalize) and by making **run vs. plan** an explicit, stored intent.

Six enhancement items are in scope: drag-and-drop reordering (Q1-3), undo (Q1-4), a
two-tap grant modal (Q1-5), a live multi-manager heartbeat (Q1-7), a "prepopulate
skipped-from-last-court" banner (Q2-2), and a complete-court confirmation modal (Q2-6).

## 2. Keystone decisions (locked)

1. **Stage now, finalize later.** Granting at court captures the giver/reason/date/rank and
   marks the court row **staged** — it does *not* write to the permanent player record.
   A single **Finalize** step (folded into "Complete Court") batch-commits all staged
   grants. Undo before finalize is free (un-stage; no record surgery).
2. **Run vs. Plan is a stored court mode**, chosen when leaving draft and changeable later.
   It changes UI emphasis only — both modes share the identical stage→finalize pipeline.
3. **Grant always opens a pre-filled modal** in both modes (deliberate two-tap). Bulk
   "Record grants" in Plan mode stages many rows at once using defaults.

## 3. Current-state anchors (files this touches)

- `orkui/controller/controller.Court.php` — page controller (`list()`, `detail()`).
- `orkui/controller/controller.CourtAjax.php` — mutations: `grant_award` (:342),
  `skip_award` (:420), `set_award_status` (:214), `update_award` (:242), `create_court`,
  `add_award`, `reorder_awards`, `advance_status`.
- `system/lib/ork3/class.Court.php` — DB layer: `canManage` (:22), `addAward` (:231),
  `getCourtAwardForGrant`, `claimAwardForGrant` (:501), `revertAwardStatus` (:513),
  `getPendingRecommendations` (:619), `getUpcomingEvents`, `updateAwardTrackingStatus`,
  `getCourtReportList/Detail`, court insert (:162), court_award insert (:231).
- `orkui/template/default/Court_detail.tpl` — inline `<style>` + IIFE `<script>`; grant
  (`cpGrantAward` :2032), skip (`cpSkipAward` :2055), advance (`cpAdvanceStatus` :1526),
  reorder (`cpSaveOrder` :1843, `cpMoveAward` :1644), script (`cpOpenScript` :2691),
  ad-hoc modal (:1375), autocomplete (`cpAcSearch` :2507).
- `orkui/template/default/Court_list.tpl` — "Plan a Court" modal.
- `revised-frontend/Kingdomnew_index.tpl` / `Parknew_index.tpl` — embedded Admin-tab court
  list (create-court posts to `CourtAjax/create_court`).
- `Reports_court.tpl` / `Reports_courts.tpl` — public court report (keys on `status='given'`).
- `system/lib/ork3/class.Player.php` — `AddAward` (:2168), `resolve_player_recommendation_cluster`.
- Recs Manager: `revised-frontend/Recommendations_manage.tpl`, `controller.Recommendations.php`.

## 4. Data model changes

Migration (MariaDB; run via `docker exec -i ork3-php8-db mariadb -u root -proot ork < migration.sql`):

```sql
-- Run vs Plan intent
ALTER TABLE `court`
  ADD COLUMN `mode` ENUM('run','plan') NOT NULL DEFAULT 'run' AFTER `status`,
  ADD COLUMN `finalized_at` DATETIME NULL DEFAULT NULL,
  ADD COLUMN `finalized_by` INT NULL DEFAULT NULL;

-- Captured grant metadata + the committed award linkage
ALTER TABLE `court_award`
  ADD COLUMN `given_by_mundane_id` INT NULL DEFAULT NULL AFTER `mundane_id`,
  ADD COLUMN `award_id` INT NULL DEFAULT NULL AFTER `recommendations_id`;

-- Widen status enum to add 'staged' (keep existing values)
ALTER TABLE `court_award`
  MODIFY COLUMN `status` ENUM('planned','announced','staged','given','cancelled')
  NOT NULL DEFAULT 'planned';
```

> If `court_award.status` is currently a VARCHAR rather than ENUM, skip the third `ALTER`
> and rely on application-level validation lists (which we extend to include `staged`).
> Probe the live column type first.

**Status semantics (new):**

| status      | meaning                                                        | in player record? |
|-------------|---------------------------------------------------------------|-------------------|
| `planned`   | queued in the plan, not acted on                              | no                |
| `announced` | (legacy, retained) called but not resolved                    | no                |
| `staged`    | granted at court — giver/reason/rank captured, pending finalize| **no**            |
| `given`     | finalized/committed; `award_id` set                           | **yes**           |
| `cancelled` | skipped — explicitly not given                                | no                |

## 5. Lifecycle

### 5.1 Award lifecycle

```
planned ──grant (modal)──▶ staged ──finalize──▶ given (award_id set)
   │                          │
   └───────skip───────▶ cancelled
                              │
   staged ──undo──▶ planned  ◀┘
```

- **grant** → opens the pre-filled modal; on confirm, sets `staged`, stores
  `given_by_mundane_id`, and persists the reason/rank/date used. No `AddAward` call.
- **undo** → `staged` → `planned`. Free; leaves no player-record trace. Available until finalize.
- **skip** → `planned`/`staged` → `cancelled`.
- **finalize** (see 5.3) → every `staged` row → `AddAward`, then `given` with `award_id`.

### 5.2 Court lifecycle & mode

`draft → published → complete` is retained. Leaving draft now sets `mode`:

- **Run at Court** (`mode='run'`) — live per-award Grant/Skip prominent, herald script
  prominent, heartbeat polling on.
- **Lock as Plan** (`mode='plan'`) — printable order-of-court prominent, plus a bulk
  **Record grants** action (stages all `planned` rows with defaults) for the later processor.

Mode is shown in the hero and switchable at any time (a plan can flip to live mid-event).
Both modes stage→finalize through the same endpoints; mode only changes emphasis.

### 5.3 Finalize gate

**Finalize is the single commit to permanent records**, folded into "Complete Court":

1. For each `court_award` with `status='staged'` on this court:
   - resolve `event_id` (from linked calendar detail; date falls back to `court_date`/today),
   - call `add_player_award` with the captured `given_by_mundane_id`, rank, reason, note,
   - on success set `status='given'` and store the returned `award_id`,
   - best-effort `resolve_player_recommendation_cluster` (unchanged, try/catch).
2. Rows already `given` (e.g. committed by the Recs Manager "Grant & leave on court" flow)
   are **skipped** by finalize — no double-grant.
3. Set `court.status='complete'`, `finalized_at=now`, `finalized_by=uid`.
4. Any partial `AddAward` failure reverts that row to `staged` and surfaces which rows failed;
   finalize is re-runnable (idempotent over already-`given` rows).

**Unfinalized-staged safeguard (required, not optional):** any court with `status != 'complete'`
and ≥1 `staged` row shows a persistent "**N grants staged, not yet finalized — Finalize to
record them**" indicator in the court hero *and* in the Admin-tab court list, so staged grants
can never be silently lost by forgetting to complete.

## 6. Feature specifications

### 6.1 Grant modal (Q1-5)

Tapping **Grant** opens a pre-filled modal (base: the existing Add Award modal markup, adapted
in-planner) that **stages** on confirm.

Pre-population:
- **Recipient / award / rank / date** — from the court row + `court_date`.
- **Giver** — defaults to the **court-level Monarch** (kingdom court → kingdom Monarch; park
  court → park Monarch). Quick-pick **pills** for likely alternates:
  - kingdom court → **Regent**
  - park court → **park Regent + kingdom Monarch + kingdom Regent** (passed-down awards one tap).
  - The giver field remains a full player-search (scoped, `&q=`, `tnFixedAcPosition` in modal
    per house autocomplete-in-modal rules) so any giver can be chosen.
- **Reason** — the citation/`Note` that will persist with the award. Precedence:
  `court_award.public_comment` (the public citation) if set → else the originating
  **recommendation reason** → else blank. (The court row has no literal "reason" column;
  `public_comment` is the citation shown on the public report, `notes` stays internal.)

New lib method resolves the officer roster: `getCourtGiverOptions($court_id)` → returns
monarch (default) + ordered pill candidates with `mundane_id` + persona + role label.

### 6.2 Drag-and-drop reorder (Q1-3)

Wire real drag-and-drop onto the existing `.cp-award-drag` handle (currently vestigial CSS).
- Pointer/touch drag reorders rows within "Order of Court".
- Up/down arrows retained as an accessible fallback.
- **One** debounced `reorder_awards` POST on drop (not per-step), sending the final order.
- Draft-only (hidden once published, matching current `.cp-list-published`).

### 6.3 Undo (Q1-4)

Per-row **Undo** control on any `staged` award → `unstage_award` endpoint → `staged`→`planned`,
clears nothing destructive (captured giver/reason may be retained to speed a re-grant, but the
row is no longer counted as staged). Confirmation not required (it is itself the safety action).
No in-planner undo after finalize (that would reverse a real record; out of scope).

### 6.4 Live heartbeat (Q1-7)

New lightweight `court_state` endpoint returns `{ version, mode, court_status, awards:[{id,
status, sort_order, given_by, ...}] }` where `version` is a cheap stamp (max `updated_at` or a
row-hash). Client polls every **~15s** (configurable; longer is fine — court actions are not
rapid), compares `version`, and re-renders only changed rows / reorders when it differs. Prevents
two reeves from clobbering each other. No websocket; poll only. Pauses while a modal is open to
avoid yanking the UI mid-edit.

### 6.5 Prepopulate skipped-from-last-court banner (Q2-2)

Draft-view banner at the top of the planner:
> "**X awards** were skipped at the most recent previous court and have not been granted yet.
> **Prepopulate those?**"

Fires when the most recent **completed** court at this level (same `kingdom_id`/`park_id`) has
awards that are **ungranted and still awardable** — i.e. not `given`, and the recipient does not
already hold that award/rank (covers both `cancelled` skips and `planned` left-as-is rows). New
lib method `getUngrantedFromLastCourt($kingdom_id,$park_id)`; one click calls a bulk
`prepopulate_from_last_court` that inserts those as `planned` rows (carrying recipient, award,
rank, recommendations_id, reason) on the current court, de-duplicated against rows already present.

### 6.6 Complete-court modal (Q2-6)

Completing a court that still has unresolved (`planned`/`announced`) awards opens a three-option
modal (in-app `tnConfirm`-style, not native):

- **Go Back** — cancel; return to planning.
- **Skip Remaining Awards** — mark all still-unresolved rows `cancelled`, then finalize (5.3) + complete.
- **Leave As-Is and Close** — finalize (5.3) + complete, leaving unresolved rows untouched (they
  resurface in the next court's §6.5 banner).

If there are staged grants but no unresolved rows, completing goes straight to finalize with a
simple "Finalize N grants and complete?" confirm. Either path commits only `staged` rows.

## 7. Endpoint changes (CourtAjax)

| endpoint                        | change                                                                 |
|---------------------------------|------------------------------------------------------------------------|
| `grant_award`                   | **rewrite**: validate published; confirm *stages* (sets `staged`, stores `given_by_mundane_id`, `public_comment`/reason, rank); no `AddAward`. Adapt `claimAwardForGrant` to claim to `'staged'` (was `'given'`) so a double-submit still can't double-stage. |
| `unstage_award` (**new**)       | `staged`→`planned`; auth + published guard.                            |
| `finalize_court` (**new**)      | commits all `staged`→`given` via `AddAward`, sets `award_id`, resolves rec clusters, sets court `complete`+`finalized_at/by`. Idempotent. |
| `skip_award`                    | unchanged behavior (`→cancelled`); allow from `planned`/`staged`.       |
| `set_award_status`/`update_award` | extend allowed status list to include `staged`.                      |
| `bulk_record_grants` (**new**)  | Plan mode: stage all `planned` rows using defaults (monarch giver, court/rec reason). |
| `prepopulate_from_last_court` (**new**) | insert ungranted-from-last-court rows as `planned` (§6.5).      |
| `court_state` (**new**)         | heartbeat read (§6.4).                                                  |
| `advance_status`                | leaving draft accepts/sets `mode`; completing routes through finalize (§6.6). |
| `create_court`                  | accepts optional initial `mode` (default `run`).                       |

## 8. Cross-surface consistency

- The Recs Manager "**Grant & leave on court**" flow continues to set the court row `given`
  (award already committed there). Finalize skips `given` rows, so no double-grant.
- Public Court Report (`status='given'`) is unaffected — only *finalized* awards appear, which is
  correct (staged-but-unfinalized are not yet real).
- Herald script check-off treats `staged` **and** `given` as "done at court" so the script ticks
  as the monarch grants live.

## 9. Testing / verification

- **Migration**: apply on local; probe `status` column type first (ENUM vs VARCHAR).
- **Stage→finalize happy path**: grant 3 awards (staged, not in player record) → complete/finalize
  → all 3 appear in player records with correct giver + rank + reason + `award_id` set.
- **Undo**: stage → undo → row `planned`, player record untouched.
- **Run vs Plan**: create each mode; verify emphasis + bulk Record grants (plan) stages all.
- **Grant modal**: giver defaults to correct monarch per level; pills correct per kingdom/park;
  reason falls back court-field → rec-reason → blank.
- **Heartbeat**: two sessions; grant in A appears in B within one poll; modal open in B pauses sync.
- **Banner**: complete a court leaving/ skipping 2 awardable awards → next court shows banner →
  prepopulate inserts exactly those 2 as planned.
- **Complete modal**: three paths behave per §6.6; only staged rows commit.
- **Safeguard**: staged-but-not-finalized court shows the indicator in hero + admin list.
- **Regression**: drag reorder saves once; arrows still work; public report unchanged; Recs Manager
  grant-and-leave still prevents double-grant.

## 10. Out of scope

- Undo of a *finalized* award (would delete real history).
- Grant-time duplicate/eligibility hard-gate for ad-hoc awards (tracked separately; the banner and
  rec-picker warnings remain advisory).
- Websocket/live presence cursors (poll heartbeat only).
- Reusable court templates / "duplicate court" (separate enhancement).
