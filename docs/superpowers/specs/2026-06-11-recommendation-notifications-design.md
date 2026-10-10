# In-App Recommendation Notifications — Design

**Date:** 2026-06-11
**Branch:** `feature/court-planner`
**Status:** Approved design, pending spec review

## Summary

Close the award-recommendation **advocacy feedback loop** with in-app notifications on
the My Amtgard dashboard. When a recommendation results in an award being granted, the people
who advocated for it — the **recommender** and any **seconders** — get an in-app notification.
Delivery is **in-app only** (no email); the surface is a Notifications card on the user's own
dashboard.

This is Thread B of the recommendations/court work. It is intentionally narrow: only the
"a recommendation was granted" event, only the advocates, dashboard-card delivery.

## Goals

- Tell a recommender when their recommendation was honored.
- Tell seconders when a recommendation they +1'd was granted.
- A generic, reusable notification store so future features can write to the same table.
- Auto-read-on-view with per-item dismiss, surfaced on the existing My Amtgard dashboard.

## Non-Goals (YAGNI for v1)

- **Email** (or any out-of-band channel). In-app only.
- Global header bell / site-wide unread badge (dashboard card only; bell is a clean future
  expansion).
- Notifying the **recipient** ("you received an award") — they see it on their profile.
- "You were recommended" notifications (often already visible via the public recs tab; can feel
  spoilery).
- Notifications for non-grant rec events (added to a court, snoozed, etc.).
- User notification preferences / opt-out (there is nothing to opt out of an in-app card; revisit
  if a bell or email is added later).

## Data Model — `ork_notification`

```sql
CREATE TABLE ork_notification (
    notification_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    mundane_id       INT UNSIGNED NOT NULL,            -- who sees this notification
    type             VARCHAR(40)  NOT NULL,            -- 'rec_granted' | 'second_granted'
    message          VARCHAR(400) NOT NULL,            -- rendered sentence (denormalized)
    link             VARCHAR(255) NULL,                -- where clicking navigates
    read_at          TIMESTAMP NULL DEFAULT NULL,
    dismissed_at     TIMESTAMP NULL DEFAULT NULL,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (notification_id),
    KEY idx_user_active (mundane_id, dismissed_at, read_at, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
```

Rationale:
- **`message` is a rendered, denormalized sentence.** A notification is a historical statement
  about a past event; it intentionally does not re-derive from live data (so a later persona
  rename does not rewrite history). This also keeps the dashboard fully generic — it never needs
  to know about recommendations.
- **`type`** is kept for future filtering/analytics, not for rendering.
- **`link`** lets the writer control navigation (here: the recipient's player profile).
- Migration file: `db-migrations/2026-06-11-add-notification-table.sql`, applied via the
  documented MariaDB container command.

## Writer — `system/lib/ork3/class.Notification.php` (new)

DB work lives in `system/lib/ork3/` per the project's layering. Methods (each `$DB->Clear()`s
before raw execute):

- `Add($mundaneId, $type, $message, $link = null)` — insert one notification. Returns the id.
- `GetForUser($mundaneId, $limit = 20)` — non-dismissed rows for the user, newest first,
  including both read and unread (auto-read keeps them in the list). Each row carries
  `notification_id`, `type`, `message`, `link`, `read_at`, `created_at`.
- `CountUnread($mundaneId)` — count of `read_at IS NULL AND dismissed_at IS NULL`.
- `MarkAllRead($mundaneId)` — set `read_at = NOW()` where currently NULL and not dismissed.
- `Dismiss($notificationId, $mundaneId)` — set `dismissed_at = NOW()`, **scoped to the owner**
  (`WHERE notification_id = ? AND mundane_id = ?`) so a user can only dismiss their own.
- `DismissAll($mundaneId)` — set `dismissed_at = NOW()` for all the user's non-dismissed rows.

### Domain helper — `notifyRecommendationGranted($recId, $grantedById)`

Called **before** the recommendation (and its seconds) are soft-deleted, so the seconder list is
still live (`deleted_at IS NULL`). Steps:

1. Load the recommendation by `$recId`: `recommended_by_id`, `mundane_id` (recipient),
   `kingdomaward_id`/award name, `rank`, `mask_giver`. If not found, no-op.
2. Build display strings: recipient persona, award label (name + ` (Rank N)` only when the
   award is a ladder award), and the link to the recipient's profile
   (`UIR . 'Playernew/index/' . recipientMundaneId`).
3. **Recommender notification** — if `recommended_by_id` is set and is **not** the granter
   (`$grantedById`) and **not** the recipient: `Add(recommended_by_id, 'rec_granted',
   "Your recommendation for {persona} ({award}) was granted.", link)`. (Anonymous recommenders
   still receive their own notification — `mask_giver` only hides them from others.)
4. **Seconder notifications** — query the live seconds:
   `SELECT supporter_mundane_id FROM ork_recommendation_seconds
    WHERE recommendations_id = $recId AND deleted_at IS NULL`.
   For each `supporter_mundane_id` that is **not** the recommender (avoids a duplicate), **not**
   the recipient, and **not** the granter:
   `Add(supporter, 'second_granted',
    "{persona} received {award} — a recommendation you seconded.", link)`.

One notification per person maximum per grant. The helper performs only reads + inserts; it does
not soft-delete anything (the callers own that).

> Confirmed during exploration: the live seconds table is `ork_recommendation_seconds`
> (`supporter_mundane_id`, `deleted_at`); `ork_recommendation_support` is abandoned and must not
> be used.

## Triggers (two call sites)

Both fire `notifyRecommendationGranted($recId, $grantedById)` **before** the rec/seconds
soft-delete so the seconder query still returns rows.

1. **Court grant** — `controller.CourtAjax::grant_award`. The court award carries
   `recommendations_id`; today the method marks the award `given` and soft-deletes the linked
   rec. Insert the notify call **before** that soft-delete `UPDATE`, guarded by
   `(int)$ca->recommendations_id > 0`. `$grantedById` is the acting `$uid`.

2. **Manager Grant Now** — `class.Player::DeleteAwardRecommendation(...)` gains an optional
   `$granted = false` parameter. When `$granted` is true, it calls
   `notifyRecommendationGranted($recId, $byUid)` **before** the existing rec + seconds cascade
   soft-delete (`class.Player.php:2474`). The `dismissrecommendation` endpoints on
   `controller.KingdomAjax` and `controller.ParkAjax` read a `Granted` POST flag and pass it
   through. The Recommendations Manager's grant flow (`rmDoGrant` in
   `Recommendations_manage.tpl`) appends `Granted=1` to its `dismissrecommendation` POST. The
   plain **Dismiss** button (and bulk dismiss) does **not** send `Granted`, so dismissals never
   notify. This covers all three Manager grant variants (Grant Now, Grant & Remove from Court,
   Grant & Leave on Court — they all route through `rmDoGrant`).

No double-notify: a given recommendation is granted through exactly one path.

## Dashboard Surface

On the My Amtgard dashboard (the user's own `Player/profile/{id}` →
`revised-frontend/Playernew_index.tpl`, `pna-feed` column, rendered only when `$isOwnProfile`):

- **Controller** (`controller.Player::profile`): for the own-profile case, load
  `$this->data['Notifications'] = Ork3::$Lib->notification->GetForUser($uid, 20)` and
  `$this->data['NotificationUnread'] = ...->CountUnread($uid)`, then call `MarkAllRead($uid)`.
  Because PHP arrays are by-value, the snapshot already in `$this->data` keeps each row's
  `read_at` for this render (so unread rows highlight this once); the DB is now marked read, so
  the next visit shows them as read. The mark-read write happens only on the user's own profile
  load.
- **Template:** a new **Notifications card** (`pna-` prefixed, dark-mode compatible) at the top
  of `pna-feed`, shown only when `count($Notifications) > 0`. Header shows "Notifications" and the
  unread count when `> 0`. Each row: a type icon, the `message`, a relative timestamp
  ("2 days ago"), the whole row links to `link` when present; unread rows (`read_at` null) get a
  highlight (left accent / subtle background). A per-row **dismiss (✕)** button and a card-level
  **Clear all** control.
- **AJAX** (`controller.PlayerAjax`): `dismiss_notification` (reads `NotificationId`, calls
  `Dismiss(id, $uid)`) and `dismiss_all_notifications` (calls `DismissAll($uid)`). Both require
  login and act only on the current user's rows. The card removes dismissed rows in place; when
  the last row is dismissed, the card hides.

## Data Flow

1. An officer grants a recommendation (court or Manager).
2. The trigger calls `notifyRecommendationGranted` **before** soft-delete → one row per advocate
   inserted into `ork_notification`.
3. The advocate later opens their own dashboard → controller loads + renders the card (unread
   highlighted) → `MarkAllRead` clears the highlight for next time.
4. The advocate clicks a notification (→ recipient profile) or dismisses it (→ AJAX, row removed).

## Error Handling

- Notification writes are **best-effort and non-blocking**: a failure to insert a notification
  must never fail or roll back the underlying grant. Wrap the notify call so an exception is
  swallowed/logged, not propagated to the grant response.
- `notifyRecommendationGranted` no-ops cleanly if the rec is missing, has no recommender, or has
  no seconds.
- Dismiss endpoints return `{status:0}`/`{status:1,error}` JSON; the card reverts the row on
  failure.
- Empty state: the card does not render at all when the user has no non-dismissed notifications
  (no empty card).

## Testing

- **Schema:** migration applies; `ork_notification` exists with the listed columns + index.
- **Recommender path:** granting a rec (court grant_award) inserts a `rec_granted` notification
  for `recommended_by_id`; not for the granter or recipient; verified via curl-auth + DB
  read-back where the court schema exists.
- **Seconder path:** a rec with 2 active seconds, granted, inserts 2 `second_granted`
  notifications (excluding recommender/recipient/granter); a rec whose seconds were withdrawn
  (`deleted_at` set) inserts none for them. Critically: notifications are captured **before** the
  cascade soft-delete, so seconders are not lost.
- **Manager Grant Now:** `Granted=1` on `dismissrecommendation` notifies; plain Dismiss does not.
  All three Manager grant variants notify exactly once.
- **No double-notify:** a single grant produces at most one notification per person.
- **Dashboard:** own profile shows the card with unread highlight + count; revisiting shows them
  read; dismiss removes a row; Clear all empties + hides the card; another user's profile never
  shows these.
- **Non-blocking:** a forced notification-insert failure does not break the grant.
- **Conventions:** `$DB->Clear()` before raw execute; `.tpl` is plain PHP; dark-mode walk of the
  card; human-readable relative time; no native `title` tooltips; no native dialogs.

## Build Sequence

1. Migration: `ork_notification` table.
2. `class.Notification.php`: CRUD methods + `notifyRecommendationGranted`.
3. Trigger 1: `CourtAjax::grant_award` notify-before-soft-delete.
4. Trigger 2: `class.Player::DeleteAwardRecommendation($granted)` + `KingdomAjax`/`ParkAjax`
   `dismissrecommendation` `Granted` plumbing + `Recommendations_manage.tpl` `rmDoGrant`
   `Granted=1`.
5. Dashboard: `controller.Player::profile` load + mark-read; Notifications card in
   `Playernew_index.tpl`; `PlayerAjax` dismiss endpoints + card JS.
6. Verification (curl-auth + DB read-back where the court/recs schema exists; dashboard +
   dark-mode walk).

## Notes / Risks

- **Local dev limits:** the local `ork` DB lacks the court tables, so the court-grant trigger
  can't be exercised locally; the Manager Grant Now path and the dashboard card can be exercised
  against the recommendations schema that does exist. Verify court-path notifications where the
  court schema exists.
- **Ordering is load-bearing:** capturing recommender + seconders must precede the soft-delete /
  cascade in both triggers, or seconders silently vanish.
- **Reusability:** the table + `Add()` are generic; a future header bell would add only a
  `CountUnread`-backed endpoint + header markup, no schema change.
