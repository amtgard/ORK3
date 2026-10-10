# Recs Manager — Grant Award modal

## Problem

The Recommendations Manager's ⚡ **Grant** button currently **insta-grants**: a bare
`tnConfirm` → `rmDoGrant()` writes the award with hardcoded values (today's date, no
event, giver = the current officer, note = the rec's reason). There is no chance to
review or adjust anything before the award lands.

This is a regression from `master`, where the profile **recs tab** had a per-row Grant
button that opened a **pre-populated "Add Award" modal** (`pk-award-overlay`) with a
Date field and a "Given By" player search. Branch commit `35483612` moved rec admin
actions into the Manager but re-implemented Grant as the instant path instead of the
modal.

**Goal:** restore a proper pre-populated Grant Award modal, now living in the Manager,
and **never insta-grant anything**.

## Decisions (from brainstorming)

- **Base it off `pk-award-overlay`** — but scoped to a single rec, not the whole
  multi-type award modal.
- **Given By:** full player search (defaults to the current officer, editable).
- **Event:** omitted for now (grant with `EventId = 0`).
- **Never insta-grant:** the ⚡ button *always* opens the modal.

## Design

### The modal — `rm-grant-overlay`

A Manager-native modal styled like the existing `rm-court-overlay` (Add to Court), so
it matches the Manager's look rather than importing the Park-profile styling.

Opened from the ⚡ `.rm-act-grant` click handler, pre-filled from the row's `data-rec`
payload (`MundaneId`, `Persona`, `KingdomAwardId`, `Rank`, `Reason`).

Fields:

| Field | Behavior |
|-------|----------|
| Recipient / Award / Rank | **Read-only** header — shows what is being granted (from the rec). |
| **Date** | `type=date`, defaults to today, required. |
| **Given By** | Defaults to the current officer (name shown, `GivenById` = `RmConfig.userId`); editable via player-search autocomplete. |
| **Note** | `textarea`, pre-filled with the rec's `Reason`, editable. |
| **Court reconciliation** | Only shown when the rec is already on a court plan: a choice of **Grant & Remove from court** vs **Grant & Leave on court** (folds today's separate `rm-grantcourt-overlay` into this one modal). |

Primary action: **Grant Award**. Cancel/close dismisses without granting.

### Given By player search

Follow the project's established custom-dropdown player-search pattern (the same one
`pk-award-givenby` uses), **not** jQuery UI:

- Custom results dropdown (`rm-ac-results`, mirroring `pk-ac-results` / `kn-ac-results`).
- **Global scope** — the "Given By" giver is one of the two intentional global search
  exceptions (the recipient stays scoped elsewhere; the giver is deliberately global,
  e.g. a Knight in another kingdom granting an association).
- Endpoint (same one the player-profile Given-By search uses):
  `KingdomAjax/playersearch/{KingdomId}&scope=all&include_inactive=1&include_suspended=1&q={term}`
  — `scope=all` gives the global giver search; note `&q=` (never `?q=`), curl-tested.
- Reuse the shared autocomplete helper (`acKeyNav` + a `*-ac-open` results dropdown) from
  `revised.js` rather than hand-rolling key nav.
- `tnFixedAcPosition(input, dropdown)` must be **defined on the Manager page** and called
  before opening the dropdown (recurring bug when missing).

### Submission — new JSON grant action

Today's grant POSTs to `Admin/player/{id}/addaward`, which renders HTML, so success is
inferred purely from `response.ok` (flagged in the recent polish pass). Because we are
building the modal properly, add a thin **JSON** action so the modal can surface real
validation errors ("already has this award", auth failure, etc.):

- **`PlayerAjax/player/{RecipientId}/grantaward`** — a new `grantaward` arm in the existing
  `PlayerAjax::player($p)` dispatch (alongside `revokeaward` / `deleteaward`). Parses
  `KingdomAwardId`, `Rank`, `GivenById`, `Date`, `Note`, `ParkId`, `KingdomId`; calls the
  existing `Player::add_player_award()` lib method; returns `{ status, error }` JSON. DB work
  stays in the lib (no raw `$DB`).
- Auth: same session/token gate the other `PlayerAjax::player()` award arms use
  (`revokeaward` etc.).

The lib method already exists and returns `Status`/`Error`; this is only a thin JSON
wrapper, keeping the Manager off the HTML endpoint.

### Grant flow

1. ⚡ Grant → open `rm-grant-overlay`, pre-filled. (Always — no insta-grant.)
2. Officer reviews/adjusts Date, Given By, Note; if on a court, picks a reconciliation
   option.
3. **Grant Award** →
   a. `PlayerAjax/grantaward` with the officer's values; on non-OK status show the error
      inline in the modal and stop (award did **not** land).
   b. On success, run the court reconciliation step (remove/leave) if chosen.
   c. `resolverecommendationcluster` — soft-deletes every parallel rec in the cluster +
      notifies advocates (existing behavior).
   d. Remove the row, toast "Granted.", close the modal.
4. In-flight guard: disable the modal's Grant button while the request is outstanding so
   a double-click can't double-grant.

This reuses the existing `rmDoGrant` chain (grant → court step → resolve cluster → remove
row); the modal only replaces the hardcoded inputs and swaps the HTML endpoint for the
JSON one.

## Out of scope

- Event picker (deferred; grants with `EventId = 0`).
- The multi-type award selection (award type tabs, award/custom/alias pickers) from
  `pk-award-overlay` — the award is fixed by the rec.
- Changing the profile recs tab (it stays browse-only; management lives in the Manager).

## Error handling

- Grant HTTP/JSON failure → inline modal error, button re-enabled, award not granted.
- Grant OK but court/cluster cleanup fails → keep the existing distinct message ("Granted,
  but cleanup failed — refresh before retrying"); do **not** re-enable Grant (retrying
  would double-grant).
- Player-search failure → dropdown shows a quiet "no results" / error state; Given By
  falls back to the defaulted current officer.

## Testing / verification

- Grant a below-rank rec: modal pre-fills recipient/award/rank; default date = today,
  giver = self, note = reason; Grant writes the award (verify via the player's award list),
  resolves the cluster, removes the row.
- Change Given By to another player → award records that giver.
- Grant a rec that's on a court → reconciliation choice appears; Grant & Remove clears the
  court award, Grant & Leave keeps it.
- Grant an "already has" rec → JSON action returns an error surfaced in the modal; nothing
  granted.
- Double-click Grant → single award (in-flight guard).
- Dark mode + the no-native-dialogs rule (modal, not `confirm`); `php -l` clean.
