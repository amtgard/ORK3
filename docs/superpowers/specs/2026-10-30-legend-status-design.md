# Legend Status Design

**Date:** 2026-10-30
**Branch:** `feature/legend-status-rip`
**Line references:** commit `971d2c9d6`. Verify them before relying on them.

## Intent

When an Amtgard player passes away, their ORK profile should become a memorial instead of a live account. Kingdom officers convert the persona to **Legend** status. The profile then honors the person, gathers memories from the community, and stops behaving like a current player.

The ORK must treat this person with care. It should never invite them to an event, ask for their waiver, list them for attendance, or send mail to their account. Their awards, titles and history must stay exactly where the community expects to find them.

## Goals

- Kingdom officers can convert a player to Legend, recording the year of passing.
- A Legend's profile shows a sepia photo, an In Memoriam card and a Memory Wall.
- Legends stay on award and title surfaces, marked as Legends.
- Legends leave every surface that assumes a current, living player.
- The rules hold on the server, not only in pickers.
- A mistaken conversion can be undone.

## Non-goals

- Family or friend access to a Legend's login. Login stays blocked for now and may be revisited later.
- A separate posthumous award type. A posthumous award is a normal award given to a Legend.
- A public directory of Legends or a kingdom memorial page.
- Bulk conversion.
- Photos or formatting in memories, or editing a memory after posting.
- Notifying family, the kingdom or anyone else about a conversion.
- Moving the feature onto the unmerged `refactor/playersearch` branch. When that branch lands, it will need a Legend tier.

## Anti-goals

The feature fails, even if it technically works, if any of these happen:

- A living player is marked as a Legend with no way back.
- Email reaches a Legend's address after conversion.
- A Legend appears in a sign-in or attendance picker.
- A Legend disappears from Knight, peerage or award lists.
- An undo destroys memories that people wrote.

## Decisions from the owner

### Converting
- Kingdom officers can convert a player and must enter a year, which is saved to the profile.

### Effects while a Legend
- They no longer appear in attendance searches.
- They can be granted posthumous awards.
- They don't appear in player searches for award recommendations at the kingdom or park level. They can still be recommended from their profile.
- Kingdom officers and ORK admins can edit the profile. Park officers can't.
- Only ORK admins can move them between parks or kingdoms, and they don't appear in move searches for anyone else.
- Login is blocked.
- RSVPs for events that haven't ended are removed.

### Profile
- **Photo:** the profile picture gets a sepia overlay.
- **Banner:** Option C from the mockups, a separate card between the hero and the stats row. It has an "In Memoriam" heading and the text: "{Name} passed beyond this realm in {year}. Their spirit lives on as a legend of Amtgard and they will be missed."
- **Pills:** a single Legend pill replaces the Active/Inactive, Waiver, Dues and Voting Eligible pills.
- **Button:** Recommend Award becomes Recommend Posthumous Award.

### Memory Wall
- A new Memory Wall tab uses Option A from the mockups: one column of memories with the newest first.
- It is the first tab and opens by default on a Legend's profile.
- Each memory shows "Remembered by {name}", the date and the text.
- The form tells posters to remain respectful and that inappropriate content is subject to removal. Approved wording: "Please remain respectful. This wall honors a member of our community and is read by friends and family. Inappropriate content is subject to removal."
- Kingdom officers and ORK admins can remove memories.

### Kept on the record
- Awards, titles, attendance history, dues history, notes and pending recommendations.
- **Unit memberships stay** and show a Legend marker.
- **Court Planner:** posthumous award entries and artisan entries stay, with a Legend pill so officers recognize the person.
- Award and title reports keep Legends, with a Legend marker.

### Cleared at conversion
- RSVPs for events that haven't ended, officer seats, event staff roles, permission grants and non-artisan Court Planner roles such as recorder.

### Undo
- Kingdom officers and ORK admins can remove Legend status.
- Login, search visibility, park-officer editing and normal moves return.
- Cleared items don't return automatically. The officer sees a list of what was cleared so they can restore it by hand.
- The Memory Wall is hidden, not deleted.

## Data model (builder's calls unless noted)

- Add to `ork_mundane`: `is_legend` (flag) and `legend_year` (year). Never reuse `active`, `suspended` or `penalty_box` to represent Legend status.
- Add an audit table of conversions and undos: who, when, year, the player's prior `active` value, and a JSON snapshot of what was cleared. Undo reads this snapshot to show the restoration list.
- Add a memories table: player, author, text, created date, and `removed_by_id` / `removed_at` for soft removal.
- Follow the `db-migrations/` date-prefix convention, classify each migration in `tools/ork-db/manifests/migration-classification.json5`, and apply to both `ork` and `ork_test`.

**Call: conversion sets `active = 0`.** This makes every existing `active = 1` list drop Legends automatically, so a new or forgotten query is safe by default. Award and title reports then add an explicit `OR m.is_legend = 1`. Undo restores the prior `active` value from the audit row.

Note that `active = 0` alone is not enough. Attendance entry can flip it back (`class.Attendance.php:399`, `model.Attendance.php:33`), and many pickers send `include_inactive=1`, so the Legend checks below are still required.

## Converting and undoing (my calls unless noted)

- **Who:** `HasAuthority(AUTH_KINGDOM, player's kingdom, AUTH_EDIT)` or a global ORK admin, following the `SetPlayerSuspension` pattern (`class.Player.php:2705`). Principality players are covered by the existing parent-kingdom traversal. Player ID 1 is refused, as it is in `SetPlayerSuspension`.
- **Year:** a whole year from 1983, when Amtgard was founded, through the current year. Editing the year later uses the same authority.
- **Flow:** "Mark as Legend" opens a custom modal, never a native dialog. It previews everything that will be cleared, including any officer seat that will become vacant and any unit that will lose its only manager, and the officer confirms.
- **Undo flow:** "Remove Legend Status" opens the same kind of modal and lists what was cleared.
- **Suspended players:** they can be converted. Their suspension data is left alone, and the Legend status takes priority in the display.
- **Banner name:** the plain persona, without prefix or suffix.
- **Transaction:** conversion runs in one transaction on `YapoMysql`, and flushes the affected memcache entries.

## Server enforcement

The JSON service exposes every public method of its whitelisted classes (`system/lib/system/class.JsonServer.php:452`), so all rules go in `system/lib/ork3/`, not in controllers or templates. New token-free helpers need `_` in their name. SQL stays in the library layer.

### Login and account
- Add the Legend check everywhere `suspended || penalty_box` is checked (`class.Authorization.php:291, 319, 374, 419, 478, 535`) and in `IsAuthorized_h` (`:1078`).
- Close the gaps: first-time IDP link `createIdpLink` (`:587`), IDP claim `ValidateClaimOrkCode` (`class.IdpIntegration.php:61`) and `ResetPassword` (`class.Authorization.php:125`), which must send nothing.
- Add a server-side purge of the player's `ork_session` rows and app tokens, because `DestroySessionsForUser` (`:1385`) only works with the user's own token.
- Refuse attendance sign-in links (`UseAttendanceLink`, `UpdateSelfSigninClass`).
- Refuse officer password changes through `UpdatePlayer`, along with username and email changes. Otherwise the block could be bypassed.

### Refused for a Legend target
- Attendance: `AddAttendance`, `SetAttendance` reassignment and face matching. This applies to everyone, including ORK admins and back-dated entries. Undo is the path for an exception.
- Bonus credits: `grant` and `update`.
- RSVPs, including on-behalf (`class.Event.php:98, 192, 283`).
- Event staff, leads and grants (`class.EventPlanning.php:1084, 1664`, `EventAjax:302`).
- Tournament participants (`class.Tournament.php:107`).
- Court Planner non-award, non-artisan roles.
- Unit member and manager adds (`class.Unit.php:241, 490, 670, 702`). Existing memberships stay, but a Legend can't be newly added or revived.
- Officer assignment (`class.Park.php:1094`, `class.Kingdom.php:831`, `common.php:805`) and new authorization grants (`class.Authorization.php:737`).
- Dues additions (`class.Player.php:3994`, `class.Treasury.php:69`).
- Qual test results and qual test manager assignment.
- Bonus credit granter assignment (`class.BonusCredit.php:369`).
- Recommendations through the park and kingdom endpoints (`ParkAjax:302`, `KingdomAjax:469`). The profile path (`controller.Player.php:172, 375`) stays open.

### Kingdom officer or ORK admin only
Keep the existing `AUTH_PARK` checks, and add one shared gate: if the target is a Legend, require `AUTH_KINGDOM EDIT` on their kingdom or global admin. Also change `canEdit` (`class.Player.php:457`), which drives `canEditAdmin`.

The gate applies to:
- `UpdatePlayer`, including quals, active and member-since.
- Images and heraldry (`class.Player.php:3093, 3301, 3330`, `class.Heraldry.php:63, 88`).
- Banner (`class.Banner.php:304`).
- Waiver, restriction and notes.
- `ClearNotes`, and dues and credit edits.
- `UpdateAward`, `ReconcileAward` and removing or revoking an existing award.
- `MergePlayer` (`class.Player.php:2401`). See the merge rule below.

**Call: merging.** `MergePlayer` hard-deletes the source and carries its grants, officer rows and RSVPs to the survivor, so it can't be undone.
- A merge of a Legend with a non-Legend is refused. To clear a duplicate of a Legend, the officer first marks the duplicate as a Legend through the normal conversion flow, which handles the clearing and the audit row.
- A merge of two Legends is allowed. The surviving record keeps its own year, and the source's memories and audit rows are re-homed to it.

**Call: park officers can still grant a new award to a Legend** through `AddAward` (`class.Player.php:3523`). Granting isn't editing, and park-level honors are theirs to give. They can't change or remove an award afterward.

### ORK admin only
- `MovePlayer` (`class.Player.php:2570`). Callers are `PlayerAjax:379`, `ParkAjax:266`, `KingdomAjax:393` and `Admin:1373/1445`.
- `MergeParks` and `TransferPark` are already ORK-admin-only and carry Legends along with the park. No change needed.

### Clearing at conversion
Use existing vacate and removal paths where they exist, and record each item in the audit snapshot:
- `ork_event_rsvp` rows for events that haven't ended. Past rows stay.
- Staff, lead and grant roles on events that haven't ended.
- Officer seats and `ork_authorization` rows.
- Registrations for tournaments that haven't started.
- Court Planner recorder seats and other non-award, non-artisan roles.
- Qual test manager and bonus credit granter roles.

Unit membership stays, but a manager's authority goes with their authorization rows. The preview flags any unit left with no manager.

### Notifications
Email has two per-player senders, `ResetPassword` and the IDP claim code. The IDP sender already skips suspended and banned players (`class.IdpIntegration.php:188`), so add Legends there. Skip in-app notifications addressed to a Legend (`class.Notification.php:124, 196`, `class.Court.php:3493`).

## Search

All three backends gain a Legend field in their output and hide Legends by default, even with `include_inactive=1`:
- `SearchService::Player` (`class.SearchService.php:368`)
- `UniversalSearch` (`:601`)
- `ScopedPlayerSearch` (`:780`)

A new explicit `include_legend=1` opts in. No shared parameter can identify a picker's purpose, so each call site opts in on its own.

| Surface | Legends shown? |
|---|---|
| Attendance, all pickers | No |
| Recommendation pickers, park and kingdom | No |
| Move, kingdom/park/player | No |
| Move, ORK admin | Yes, honored only for global admins |
| Officers, unit add, event staff, permissions, court role pickers | No |
| Award Given To | Yes, for posthumous awards |
| Award Given By | Yes, so historic awards can be recorded |
| Merge | Yes, for kingdom officers and ORK admins |
| Header search and Advanced Search | Yes, so profiles can be found |
| Reports roster filter | No |

`UniversalSearch` filters on active, so it needs `active = 1 OR is_legend = 1` when opted in. Legend rows show the Legend pill in pickers. Badges are hand-copied at nine sites in `revised.js` (2386, 3649, 3702, 7771, 7826, 8293, 8971, 11576) and `Admin_index.tpl:969`. Default-off also covers external `Search/Player` consumers.

Any search work must follow the standing rule: scoped, `&q=` not `?q=`, and curl-tested.

## Reports and lists

| Surface | Treatment |
|---|---|
| Park and kingdom rosters and counts | Excluded by `active = 0` |
| `GetPlayerRoster` | Excluded by default, with a Legend flag |
| Active Players report (`class.Report.php:3066`) | Explicitly excluded |
| Reeve and Corpora qualified (`:3173`, `:3217`) | Explicitly excluded |
| Crown Qualed (`:332`) | Explicitly excluded |
| Voting eligibility (`:4740`) and reconciliation prompts (`:4291`, `:4325`, `:4362`) | Excluded, and never prompts to reactivate |
| Dues Paid (`:3245`) | Excluded, including dues for life |
| Population Explorer | Excluded by default, plus a Legend criterion |
| Knights, peerage, Class Masters, custom awards, ladder grid (`:466`, `:301`, `:539`, `:8681`) | **Kept**, with `OR is_legend = 1` and a Legend pill |
| Award Explorer and State of Amtgard | Kept as history |
| New players and Recap | No change, they're attendance-driven |
| Officer directory (`:3962`) | No change, seats were vacated |
| Unit roster (`class.Report.php:2255`, `Unit_index.tpl`) | Kept, with a Legend marker |
| Sitemap (`class.Sitemap.php:117`) | No change, so Legends drop out of it |
| Recommendations Manager and CSV | Kept, with a Legend pill and CSV flag replacing "retired" (`_rm_row.tpl:100`, `Recommendations_manage.tpl:1246, 1525`). Non-Legend retired recipients are unchanged |
| Court Planner | Kept, with a Legend pill. Inherits from `class.Court.php:2776`, and `court-planner.js` renders it |

`orkmobile` is an uninitialized submodule here, so it could not be checked. The builder should check whether it's live and what it calls.

## Memory Wall behavior (my calls unless noted)

- **Reading:** anyone who can view the profile, including logged-out visitors, who see a prompt to log in to share.
- **Posting:** any logged-in player. The Remembered By name is their persona, linked to their profile, and it can't be changed. The date is the posting date, displayed like "October 12, 2026".
- **Limits:** plain text, up to 2,000 characters, checked with `ProfanityFilter`, and escaped on output. A player can post more than one memory.
- **Removing:** an inline in-page confirmation, no native dialog. It's a soft removal that records who removed it and when. Nothing in the UI restores a removed memory.
- **Undo:** the tab disappears and memories remain stored. If the player is converted again, they reappear.

## Front-end conventions

- Work in `revised-frontend`. Don't add entry points in `template/default/`. `Playernew_index.tpl` is plain PHP, rendered by `controller.Player.php`.
- Support dark mode with `html[data-theme="dark"]` and verify computed styles in both themes, because global `orkui.css` heading rules win over page styles.
- Support mobile widths, and use `data-tip`, not `title`, for tooltips.
- Sepia is a CSS filter on `.pn-avatar img`: `sepia(.85) saturate(.75) contrast(.95) brightness(1.02)`. The hero background, heraldry elsewhere and thumbnails are unchanged.
- The approved mockups are in `docs/superpowers/specs/2026-10-30-legend-status-mockups/`. `legend-banner.html` Option C and `memory-wall.html` Option A plus its form are the approved ones. They are fragments that use a few classes from the mockup tool's frame, so read them for layout and colors.
- The Legend pill matches the `pn-badge` family. The look is restrained, not themed. Light-mode colors from the mockups:
  - Card and form: background `#f7efe0`, border `#dcc9a4`, rule `#b08d4f`, text `#5a4220`, heading `#8a6a33`
  - Pill: background `#f3e6cf`, text `#6b4a1f`, border `#c9a86a`
  - Memory card: background `#fdfaf3`, border `#e6dcc8`, text `#3f3423`, byline `#7a6a50`
  - Memory Wall accent, including the active tab and Share a Memory button: `#8a6a33`
- Dark-mode colors weren't mocked up. Choose equivalents that keep the same warm, restrained feel.
- Hide Mark as Legend from everyone without authority. Hide edit, image and dues controls from park officers on a Legend, driven by `canEditAdmin`.
- Add a What's New entry and update the in-app docs.

## Verification

- Add PHPUnit coverage in `tests/` for the gates, the search defaults, conversion, undo and memory rules.
- Exercise the real app on the local stack: convert a player as a kingdom officer, and check as a park officer, a regular player and an ORK admin.
- Curl every search that changed, with a term known to match a Legend.
- Confirm a Legend can't log in by password or IDP, and that an open session ends at conversion.
- No real emails during testing.

## Left to the builder

- Exact column, table and helper names.
- Where the shared Legend gate lives, and how the milestones and profile edit modals are hidden.
- The order of the work and how it splits between agents.
- Whether Legend checks are added in each method or in one guard they all call.
