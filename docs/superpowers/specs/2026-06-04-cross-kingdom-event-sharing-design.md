# Cross-Kingdom Event Sharing — Design

**Date:** 2026-06-04
**Branch:** feature/event-planning-expansion
**Status:** Approved design, pending implementation plan

## Problem

An event in ORK belongs to exactly one owning kingdom via `ork_event.kingdom_id`. The
Kingdom profile Events tab lists events strictly with `WHERE e.kingdom_id = {$kid}`
(`controller.Kingdom.php`). When an interkingdom event is hosted by several kingdoms
(A, B, C, D), only the owning kingdom's members see it on their kingdom's Events tab.
The other participating kingdoms have no way to surface that same event for their members.

## Goal

Let a kingdom officer mark an externally-owned event as "Share with My Kingdom" so the
event also appears in **their** kingdom's Events tab — without changing the event's
ownership. Sharing is a **kingdom prerogative**: only kingdom-level authority can do it,
not park officers.

## Scope

**In scope — shared events surface in exactly ONE place:** the Kingdom profile **Events
tab** (initial render in `controller.Kingdom.php::profile()` and the "load more"
pagination in `events_more()`).

**Explicitly out of scope:** event reports, attendance reports, search results, the
`model.Kingdom::get_kingdom_events()` summary widget, park event lists, or any other
event listing. Shared events do NOT appear in those surfaces. Sharing affects display
only — it never affects attendance, ownership, RSVP scoping, or reporting.

## Data Model

New junction table. The owning kingdom stays in `ork_event.kingdom_id`; this table only
records *additional* kingdoms where the event surfaces.

```sql
CREATE TABLE ork_event_kingdom_share (
  event_kingdom_share_id INT AUTO_INCREMENT PRIMARY KEY,
  event_id               INT NOT NULL,        -- the event being shared
  kingdom_id             INT NOT NULL,        -- the kingdom it is shared INTO
  shared_by_mundane_id   INT NOT NULL,        -- officer who created the share
  created                DATETIME NOT NULL,
  UNIQUE KEY uq_event_kingdom (event_id, kingdom_id),
  KEY idx_kingdom (kingdom_id),
  KEY idx_event (event_id)
);
```

`UNIQUE(event_id, kingdom_id)` makes sharing idempotent (re-sharing is a no-op).
Migration run via `docker exec -i ork3-php8-db mariadb -u root -proot ork < migration.sql`.

## Permissions & Rules

- **Who can share into kingdom K:** `HasAuthority(uid, AUTH_KINGDOM, K, AUTH_EDIT)` —
  holds kingdom-level authority over K. Because the gate is `AUTH_KINGDOM` (not
  `AUTH_PARK`), **park officers cannot share**; it stays a kingdom prerogative.
- **Eligible events:** any **published** event — kingdom-level (`park_id = 0`) *or*
  park-hosted. Drafts (`status != 'published'`) are never shareable.
- **No self-share:** sharing into the event's own owning kingdom is blocked (no-op /
  rejected), since it is already in that kingdom's list.
- **Unshare:** the same authority over the target kingdom may remove the share.
- All authority is re-checked server-side on every share/unshare call. The event page's
  rendered buttons are a convenience only — never the security boundary.

## Backend

### `system/lib/ork3/class.Event.php` (DB layer)

- `ShareEventToKingdom($request)` — params: `Token`, `EventId`, `KingdomId`.
  Resolves mundane via `IsAuthorized`. Validates: event exists + published; not the
  owning kingdom; `HasAuthority(uid, AUTH_KINGDOM, KingdomId, AUTH_EDIT)`. On pass,
  `$DB->Clear()` then `INSERT IGNORE` into `ork_event_kingdom_share` with `NOW()`.
  Returns `Success()` / appropriate failure status.
- `UnshareEventFromKingdom($request)` — same auth gate; `$DB->Clear()` then `DELETE`
  the matching `(event_id, kingdom_id)` row.
- `GetSharedKingdomsForEvent($request)` — returns the kingdom IDs an event is currently
  shared into (drives toggle state on the event page).

All three follow the project rule: `$DB->Clear()` before raw `Execute`/`DataSet`.

### AJAX — `orkui/controller/controller.EventAjax.php`

Add a `share($p = null)` method following the existing param-array `action`-dispatch
pattern (see `auth()` / `heraldry()` / `banner()`): sub-actions `share`, `unshare`.
Each echoes `json_encode(['status' => ..., ...])`. Calls the model pass-throughs to the
`class.Event.php` methods above.

## Event-Page Entry Point

The "Share with My Kingdom" control lives on the canonical event view page reached from
the event breadcrumb (`Event/index/{id}`). **Implementation note:** confirm at plan time
whether the live page is `Event_index.tpl` (rendered by `index()`) or the revised
`Eventnew_index.tpl` — the button goes on whichever template users actually land on; if
both are reachable, the primary/canonical one is targeted.

**Controller** computes, for the signed-in viewer, the set of kingdoms they can share
into = their `AUTH_KINGDOM` kingdoms **minus** the event's owning kingdom, each annotated
with whether the event is already shared there. Exposed to the template as e.g.
`$this->data['ShareableKingdoms']`. Empty for anonymous users and users with no
qualifying kingdom authority (control is not rendered).

**Template behavior:**
- **Exactly one eligible kingdom** → a single button: **"Share with My Kingdom"** which
  toggles to **"Shared ✓ — Unshare"**.
- **Multiple eligible kingdoms** → a compact **"Share with my kingdom(s)"**
  dropdown/modal listing each kingdom with a toggle reflecting current shared state.

This single surface handles both share and unshare; no separate management panel. AJAX
calls hit the EventAjax `share`/`unshare` actions and update the button/toggle in place.

**Conventions:** dark-mode compatible proactively (button, toggle, dropdown, any modal
header h1–h6 reset); no native `confirm()` — use `tnConfirm()` if a confirm is wanted on
unshare; no native `title` tooltips (use `data-tip`).

## Kingdom Events-Tab Rendering

Two queries change so shared events appear inline:

1. **Initial events list** — `controller.Kingdom.php::profile()`, the `$evtSql` with
   `WHERE e.kingdom_id = {$kid}`.
2. **Load-more pagination** — `controller.Kingdom.php::events_more()`, the query around
   line 211.

In both, the kingdom predicate becomes:

```sql
WHERE (e.kingdom_id = {$kid}
       OR e.event_id IN (SELECT event_id FROM ork_event_kingdom_share WHERE kingdom_id = {$kid}))
```

Each row additionally selects:
- `is_shared` — 1 when the row is present only via the share table (i.e.
  `e.kingdom_id != {$kid}`), else 0.
- the owning kingdom's name (join `ork_kingdom` on `e.kingdom_id`) for the badge label.

Shared events **sort inline by date** alongside native events (existing
`ORDER BY cd.event_start, ...`). Each shared row renders a badge:
**"Shared · hosted by {OwningKingdomName}"**, dark-mode-safe.

Draft visibility: shared events are always published (sharing requires published), so the
existing draft clause naturally applies to native rows only; the share subquery selects
published events.

## Error Handling

- Unauthorized share/unshare → JSON failure status; UI shows an inline error, no state
  change.
- Sharing a draft / nonexistent / already-owned event → rejected with a clear status.
- Duplicate share → idempotent success (no error) via `INSERT IGNORE` + unique key.
- Deleting an event: `ork_event_kingdom_share` rows for it become orphaned harmlessly
  (subquery simply returns nothing); a follow-up FK CASCADE or cleanup is optional and
  noted but not required for v1.

## Testing

- Migration applies cleanly; unique key enforced.
- Curl-authed session (per project pattern): officer of kingdom B shares event owned by
  A → row inserted; appears in B's Events tab with badge; not in B's event reports.
- Park officer (no kingdom authority) → share rejected.
- Self-share into owning kingdom → rejected.
- Unshare removes the row and the event drops off B's tab.
- Anonymous viewer sees no share control.
- Dark-mode walk of the event-page control + the Events-tab badge.

## Out of Scope / Future

- Notifying the owning kingdom that their event was shared.
- A management screen listing everything a kingdom has shared in.
- Sharing into principalities specifically (handled by normal kingdom authority traversal
  if it arises; not specially designed here).
