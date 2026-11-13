# Tournament Playtest Fixes — Design

**Date:** 2026-06-01
**Status:** Approved (design); pending implementation plan
**Context:** Issues surfaced during a live playtest of the tournament module
(route `Tournament/profile/{id}`, e.g. `/orkui/index.php?Route=Tournament/profile/173`).

This spec covers four independent fixes. Issues 1, 2, 4 are contained changes; issue 3
is a real feature enhancement of an existing flow.

---

## Issue 1 — Tiered (non-exclusionary) participant search

### Problem
Adding a participant to a tournament cannot find players from outside the tournament's
kingdom. The scope is hard-locked.

### Current behavior (verified)
- Add-participant modal search builds `KingdomAjax/playersearch/{kingdomId}&q=...`
  (`orkui/template/revised-frontend/Tournametnew_index.tpl:6115`). `TnConfig.kingdomId`
  is set from the tournament's `KingdomId` (`:3559`).
- The endpoint defaults `scope='own'` (`controller.KingdomAjax.php:790`), which adds
  `AND m.kingdom_id = {$kid}` to the SQL (`:834`), excluding all outside-kingdom players.
- The endpoint already supports `scope=all` (no kingdom/park clause) and `scope=exclude`,
  and already has a kingdom-priority `ORDER BY CASE` — the tournament UI just never used them.
- The add/save path (`class.Tournament.php:AddParticipant`, `ensureRegistrant`) does **no**
  kingdom validation — it stores whatever `KingdomId` is posted. So the lock is purely in
  the search UI.

### Design
Make the tournament participant search **tiered, not gated**: it returns *all* matching
players but ranks them by proximity to the event.

- **Local (park) tournament** → same-park first, then same-kingdom, then everyone else.
- **Kingdom tournament** → same-kingdom first, then everyone else.

Implementation:
1. Add a `scope=tiered` mode to `KingdomAjax::playersearch`. In this mode the `WHERE` clause
   carries **no** kingdom/park restriction (global result set), and the `ORDER BY` becomes:
   ```
   ORDER BY
     CASE WHEN :parkId > 0 AND m.park_id = :parkId THEN 0 ELSE 1 END,   -- park tier (local events)
     CASE WHEN m.kingdom_id = :kid THEN 0 ELSE 1 END,                    -- kingdom tier
     m.suspended ASC, m.active DESC, m.persona
   ```
   Accept an optional `ParkId` query param for the park tier; when absent/0 the park tier
   is a no-op and the search degrades to kingdom-then-global (the kingdom-event case).
2. Template: expose `$tParkId` alongside the existing `$tKingdomId`; set `TnConfig.parkId`.
3. JS: build `...playersearch/{kingdomId}&q={term}&scope=tiered&ParkId={parkId}`.

### Constraints / checklist (project rules)
- URL must use `&q=` not `?q=` (UIR already ends in `?Route=`).
- The dropdown is inside a modal → `tnFixedAcPosition(input, dropdown)` must be called before
  every `kn-ac-open`, in both the results and no-results branches (confirm it is defined on
  the page).
- Curl-test the endpoint (logged-in cookie jar) and confirm tiered rows return before "done".
- No new IDOR surface: save path is unchanged and already accepts any kingdom.

---

## Issue 2 — Always-visible advanced options + Individual/Team toggle

### Problem
1. Advanced bracket options are hidden behind a disclosure and should always be shown.
2. Individual vs Team is a `<select>` and should be a left|right segmented toggle.

### Current behavior (verified)
- Both Add Bracket (`~2940`) and Edit Bracket (`~3066`) modals wrap the advanced fields
  (participants, rings, seeding, best-of, duration, style note) in a `.tn-advanced-toggle`
  disclosure (`display:none` by default), with show/hide JS (`~10269`) and an
  auto-expand-on-edit heuristic (`~4992`).
- Individual/Team is `<select id="tn-addbracket-participants">` / `tn-editbracket-participants`
  with `<option value="individual|team">`, submitted as `Participants`, stored in
  `ork_bracket.participants ENUM('individual','team')`. JS gates "Team" off when method =
  Ironman (`~4770`, `~5022`).
- A reusable segmented control already exists: `.tn-seg` / `.tn-seg-btn` / `.tn-seg-active`
  (CSS `:431`, dark mode `:1218`), already used for `tn-editbracket-firstround`
  (`:3118`) with `data-val` + click handler (`:5055`).

### Design (apply to BOTH Add and Edit modals)
1. Remove the `.tn-advanced-toggle` button and its JS (toggle handler + auto-expand block);
   render the advanced fields inline, always visible.
2. Replace each Individual/Team `<select>` with a `.tn-seg` two-button control mirroring
   `tn-editbracket-firstround`:
   ```html
   <div class="tn-seg" id="tn-addbracket-participants">
     <button type="button" class="tn-seg-btn tn-seg-active" data-val="individual">Individual</button>
     <button type="button" class="tn-seg-btn" data-val="team">Team</button>
   </div>
   ```
   - Click handler sets `tn-seg-active` (same shape as the firstround handler).
   - Form submission reads the active button's `data-val` (replacing `.value` reads at the
     submit sites `~4815` / `~5061`); still posts `Participants=individual|team`.
   - Ironman gating reimplemented against the toggle: when method = Ironman, disable the
     Team button (visual disabled state) and force-select Individual.
   - Edit modal: preselect the active button from the bracket's stored `participants` value.
3. Controller/model/DB unchanged (`Participants` contract preserved).

### Constraints
- Dark mode: `.tn-seg` already has dark-mode CSS — no new work, but verify the disabled
  Team-button state reads correctly in dark mode.

---

## Issue 4 — Split "Other / Open" into "Open Weapons" + "Other"

### Problem
The weapon-style category "Other / Open" conflates two concepts. Rename it to
"Open Weapons" and add a separate, genuine "Other" category.

### Current behavior (verified)
- `ork_bracket.style` is a DB **ENUM**:
  `('Single Sword','Florentine','Sword and Shield','Great Weapon','Missile','Other','Jugging','Battlegame','Quest')`.
- The single value `'Other'` is displayed as "Other / Open" (dropdowns) and "Open"
  (label map). Four UI touchpoints: label map (`~83`), Add dropdown (`~2899`),
  Edit dropdown (`~3025`), JS `STYLE_OPTS` (`~5233`). Also referenced in
  `scripts/simulate_tournament.php`.

### Design
1. **DB migration** (additive, safe): append a new ENUM member `'Open Weapons'` to
   `ork_bracket.style`, then migrate existing rows: `UPDATE ork_bracket SET style='Open Weapons'
   WHERE style='Other'` (today's "Other" semantically meant "open"). `'Other'` remains in the
   enum as the now-distinct bucket.
   - Append rather than splice the enum so the change is purely additive.
2. **UI** — at all four touchpoints, present two distinct options:
   - `'Open Weapons'` → label "Open Weapons"
   - `'Other'` → label "Other"
   - Remove the old `'Other' => 'Open'` / "Other / Open" mappings.
3. Migration file under the project migrations dir; run via
   `docker exec -i ork3-php8-db mariadb -u root -proot ork < migration.sql`.

### Constraints
- ENUM ordering: append `'Open Weapons'` (don't reorder existing members) to keep the ALTER
  purely additive.
- After migration, confirm no code path still maps `'Other'` → "Open".

---

## Issue 3 — Withdrawal that lets the bracket finish (organizer picks forfeit vs annul)

### Problem
During a round robin, two teams withdrew mid-event after several fights had been recorded.
The organizer had to regenerate a whole new bracket, which was painful.

### Current behavior (verified)
- A per-participant status menu already exists: Active / Withdrawn / Disqualified
  (`Tournametnew_index.tpl:2455`, `:2476`) → `tnSetParticipantStatus` →
  `TournamentAjax` → `class.Tournament.php:UpdateParticipantStatus` (`:2546`), which only
  writes `ork_participant.status` (`'active'|'withdrawn'|'disqualified'`).
- Setting "Withdrawn" has three effects today:
  - `PostMatchResult` **blocks** recording any match involving a withdrawn/disqualified
    participant (`:1137`) → the bracket can never reach completion → forced regenerate.
  - Played matches stay recorded.
  - Standings push withdrawn/disqualified to the bottom with `Place=null` (`:3040`).
- No auto-forfeit, no re-pairing.
- Forfeit scoring is directional-messy: `resolveWinnerLoser` (`:97`) treats bare
  `'forfeit'`/`'disqualified'` as "p2 wins, p1 loses" — i.e. p1 is hardcoded as the
  forfeiter. Standings count `result IN ('2-wins','forfeit','disqualified')` as a p2-side win.
  Auto-resolution must therefore write **directional** win results, not bare `'forfeit'`.

### Research (how governing bodies handle round-robin withdrawals)
- **Chess/FIDE:** *annul* (delete all their games from standings) if withdrawal occurs before
  50% of their games are played; *forfeit* (opponents get the win, remaining opponents get a
  walkover) thereafter.
- **USA Pickleball:** withdrawn player's results don't count toward round-robin standings
  (annul); a specific forfeited match scores as a max-score loss.

### Design
Keep the existing status menu. When an organizer sets a participant to **Withdrawn** *after
results have been recorded in the bracket*, show a small in-product modal (tnConfirm-style —
no native dialog) asking how to resolve their matches, with a **smart default** per FIDE:
- < 50% of their matches played → default **Annul**
- ≥ 50% → default **Forfeit**

**Forfeit (walkover):** already-fought matches stand; every *unplayed* match of theirs
auto-resolves as a win for the opponent. Bracket completes normally.

**Annul:** *all* their matches (played + unplayed) stop counting toward everyone's standings —
the round robin effectively becomes N−1 players.

### Mechanism
- New service method `WithdrawParticipant(BracketId, ParticipantId, Mode='forfeit'|'annul')`
  + `TournamentAjax/bracket/{id}/withdraw` endpoint. The status menu's "Withdrawn" path routes
  through it (carrying the chosen mode); "Active" routes through an un-withdraw that reverts.
- Persist the chosen mode on the participant: add `withdraw_mode ENUM('forfeit','annul') NULL`
  to `ork_participant` (needed for undo + display).
- **Annul** uses a recomputed match `voided` flag (new `ork_match.voided TINYINT DEFAULT 0`):
  on every withdraw/un-withdraw, recompute across the bracket so a match is `voided=1` iff
  **either** participant is currently withdrawn with `mode='annul'`. This keeps the
  two-participants-withdraw case and undo consistent without per-action bookkeeping.
  - `GetStandings` SQL and the bracket-completion check both add `AND m.voided = 0`.
- **Forfeit** writes the opponent-win result on each *unplayed* match directionally
  (`'1-wins'` or `'2-wins'` depending on which side the withdrawn participant is), tagged with
  a new `ork_match.auto_resolved TINYINT DEFAULT 0` so "set back to Active" reverts only the
  auto-written results, never real ones. Played matches untouched.
- **Un-withdraw (back to Active):** clear `withdraw_mode`; recompute `voided` (annul case);
  reset `result` to NULL on that participant's `auto_resolved=1` matches and clear the flag
  (forfeit case).
- **Generalization:** the forfeit-vs-annul choice surfaces only for round-robin and points
  formats (where annul is meaningful). Single/double-elim and Swiss always use forfeit
  (opponent walks over / advances) and skip the modal.
- **Net effect:** withdrawing resolves the bracket in place — no manual regenerate — and undo
  restores it.

### Data model changes
- `ork_participant.withdraw_mode ENUM('forfeit','annul') NULL`
- `ork_match.voided TINYINT NOT NULL DEFAULT 0`
- `ork_match.auto_resolved TINYINT NOT NULL DEFAULT 0`

### Constraints
- No native `confirm()`/`alert()` — use the existing `tnConfirm({...})` modal pattern.
- Dark-mode-compatible modal.
- All writes wrapped in transactions (consistent with existing `GenerateMatches` /
  `PostMatchResult`); `$DB->Clear()` before raw Execute where prior model calls ran.
- Recompute is idempotent and safe to run on every status change.

---

## Out of scope
- Re-seeding / re-pairing remaining round-robin matches after a withdrawal (annul simply
  drops the games; we do not regenerate a tighter schedule).
- Rating/Glicko effects of forfeits.
- Bulk withdrawal UI (one participant at a time, as today).

## Files touched (anticipated)
- `orkui/controller/controller.KingdomAjax.php` (issue 1: `scope=tiered`)
- `orkui/controller/controller.TournamentAjax.php` (issue 3: `withdraw` endpoint)
- `system/lib/ork3/class.Tournament.php` (issues 1 tiering helper if needed, 3:
  `WithdrawParticipant`, standings/completion `voided` filter)
- `orkui/template/revised-frontend/Tournametnew_index.tpl` (issues 1, 2, 3, 4 UI)
- New migration SQL (issues 3 + 4)
- `scripts/simulate_tournament.php` (issue 4: style list, if it should include new buckets)
