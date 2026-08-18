# Award Recommendation Seconds + Originator Reason Edit

**Date:** 2026-04-22
**Status:** Approved for build

## Summary

Add a "second this recommendation" mechanism to award recommendations: any logged-in player can support an existing recommendation, optionally with notes, without submitting their own duplicate recommendation. Also allow the originator of a recommendation to edit their own `reason` after submission.

## Motivation

Today, only the originating recommender can attach themselves and a reason to an award recommendation. Players who agree but don't want to file a separate full recommendation have no way to signal support. This loses signal that would otherwise help kingdom officers weighing awards. Additionally, an originator who learns more about the recipient after submitting cannot supplement their reason.

## Design Decisions

**Chosen approach: dedicated join table.** A separate `ork_recommendation_seconds` table keyed to a parent recommendation. Rejected alternative was reusing `ork_recommendations` and collapsing duplicates in the UI; that approach silently broke every existing `COUNT(*)` and `GROUP BY` over recommendations and tangled originator-vs-supporter permissions. The join table keeps the recommendation a single canonical record and isolates new behavior to new code.

## Database

### New table: `ork_recommendation_seconds`

| Column | Type | Notes |
|---|---|---|
| `recommendation_seconds_id` | int(11) NOT NULL AUTO_INCREMENT PK | |
| `recommendations_id` | int(11) NOT NULL | FK → `ork_recommendations.recommendations_id` |
| `supporter_mundane_id` | int(11) NOT NULL | FK → `ork_mundane.mundane_id` |
| `notes` | varchar(400) NOT NULL DEFAULT '' | optional supporting text |
| `created_at` | timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP | |
| `updated_at` | timestamp NULL DEFAULT NULL | bumped on notes edit |
| `deleted_at` | timestamp NULL DEFAULT NULL | soft-delete (matches parent table convention) |
| `deleted_by` | int(11) NULL DEFAULT NULL | |

**Indexes:**
- `UNIQUE KEY uniq_rec_supporter (recommendations_id, supporter_mundane_id)` — one second per supporter per rec; re-seconding after withdrawal resurrects the row.
- `KEY idx_supporter (supporter_mundane_id)` — for player-merge and "my seconds" lookups.

### Migration

`migrations/2026-04-22-recommendation-seconds.sql` — `CREATE TABLE` only. Run via the project's standard migration command.

## Backend (`system/lib/ork3/class.Player.php`)

All new methods follow existing conventions: `Success(...)` / `InvalidParameter(...)` returns, `RequestedBy` for the actor, Yapo for inserts/updates, `$DB->Clear()` before raw `Execute` per the project rule.

### `AddSecondToRecommendation({ RequestedBy, RecommendationsId, Notes })`

Eligibility checks (all hard rejections — return `InvalidParameter`):
1. The parent rec exists and is not soft-deleted.
2. `RequestedBy` is not the parent rec's `mundane_id` (recipient).
3. `RequestedBy` is not the parent rec's `recommended_by_id` (originator).
4. `RequestedBy` does not have a non-deleted primary recommendation for the same `(mundane_id, kingdomaward_id, award_id, rank)` tuple.

Behavior:
- If a soft-deleted second by this supporter exists for this rec → resurrect (`deleted_at = NULL`, `deleted_by = NULL`, replace `notes`, set `updated_at = NOW()`).
- Otherwise insert a new row.

### `EditSecondNotes({ RequestedBy, RecommendationSecondsId, Notes })`

- Loads the second; rejects if soft-deleted.
- Permission: only the supporter (`supporter_mundane_id == RequestedBy`).
- Updates `notes` and `updated_at`.

### `WithdrawSecond({ RequestedBy, RecommendationSecondsId })`

- Soft-delete via `deleted_at = NOW()`, `deleted_by = RequestedBy`.
- Permission: supporter, OR an admin/officer matching the same role check used in `DeleteAwardRecommendation`.

### `EditAwardRecommendationReason({ RequestedBy, RecommendationsId, Reason })`

- Loads the rec; rejects if soft-deleted.
- Permission: only the originator (`recommended_by_id == RequestedBy`). Admins use existing tools to delete and re-create; out of scope to extend admin edit here.
- Updates `reason` only. Does not touch `date_recommended` or any other field.

### Cascade in `DeleteAwardRecommendation`

When a recommendation is soft-deleted, soft-delete every active second for it with the same `deleted_at` timestamp and `deleted_by`.

### Read path

Extend the existing recommendation loader used by `Reports` / `AwardRecommendationsForPlayer` so each rec carries:

```
Seconds: [
  { RecommendationSecondsId, SupporterMundaneId, SupporterName, Notes, CreatedAt, IsMine },
  ...
]
SecondsCount: <int>
ViewerCanSecond: <bool>   // result of running eligibility checks for the current viewer
ViewerCanEditReason: <bool>  // RequestedBy == recommended_by_id
```

**Masking:** when the parent rec has `mask_giver = 1` and the viewer is not privileged to see the originator, `SupporterName` is masked the same way (e.g., empty / "Anonymous"). `Notes` remain visible.

## Player merge (`class.Player.php` ~ line 834)

Add after the existing `recommended_by_id` remap:

```sql
UPDATE ork_recommendation_seconds SET supporter_mundane_id = <to> WHERE supporter_mundane_id = <from>
```

This may collide with the `(recommendations_id, supporter_mundane_id)` unique key when the merged-from and merged-to mundane both seconded the same rec. Handle via a pre-pass: detect collisions, soft-delete the merged-from row first, then run the bulk UPDATE.

## Frontend (`orkui/template/default/Playernew_index.tpl`)

All CSS prefixed `pn-`, all JS inlined per existing Playernew conventions. Dark-mode compatible. No native `title` attributes — use `data-tip` per the no-browser-tooltips rule.

### Recommendations tab — per-row additions

Right-aligned action group on each rec row:

- **Seconds count badge** `+N` next to the date when `SecondsCount > 0`.
- **`[+]` button** — `data-tip="Second this recommendation and add your feedback."`. Visible only if `ViewerCanSecond`.
- **Edit-pencil** next to the reason — visible only if `ViewerCanEditReason`. Opens edit-reason modal.

### Seconds list (always rendered when `SecondsCount > 0`, no expand toggle for v1)

Below each rec row, a compact list:

```
Supporter Name — "notes…"  [withdraw]
```

`[withdraw]` shown only on the viewer's own second. Withdraw triggers a confirm-then-call to `WithdrawSecond`.

### Modals (custom JS modal pattern, matching the existing add-rec modal)

1. **Second this recommendation** — textarea + 400-char counter + Submit/Cancel. POSTs to `AddSecondToRecommendation`.
2. **Edit notes** (supporter) — same modal, prefilled. POSTs to `EditSecondNotes`.
3. **Edit reason** (originator) — same shape, 400-char counter, prefilled with current reason. POSTs to `EditAwardRecommendationReason`.

## Out of scope for v1

- Notifications to the originator/recipient when a second is added.
- Surfacing second counts in the Reports tab or in kingdom-level reports.
- Reordering or sorting the rec list by second count.
- Admin-edit of another user's `reason` (admins delete and re-create today; unchanged).

## Acceptance

- A logged-in player viewing another player's profile can second any eligible recommendation, with optional notes, and the `[+]` button is hidden when not eligible.
- A second is visible to all viewers below the rec, with the supporter's name (subject to masking) and notes.
- The supporter can edit their notes or withdraw their second; another viewer cannot.
- Soft-deleting a recommendation soft-deletes its seconds.
- The originator of a recommendation sees a pencil icon next to their reason and can edit it.
- A player merge correctly remaps seconds and does not violate the unique key.
- All counts and reports over recommendations are unchanged in value.
