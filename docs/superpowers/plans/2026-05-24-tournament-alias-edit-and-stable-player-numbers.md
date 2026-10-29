# Handoff: Inline Alias Edit + Tournament-Wide Stable Player Numbers

> **Purpose:** This is a reconciliation handoff. A *separate* terminal implemented the
> two features below at the same time the `2026-05-24-bracket-play-in-round.md` plan was
> being worked. Both touch the **same four files**. This doc lists every change, its
> exact anchor, and where the two efforts overlap, so you can merge/reconcile cleanly.

**Status:** Implemented and `php -l`-clean on all four files. NOT yet exercised against the
live DB or browser. No new DB migration (the `2026-03-18-participant-number.sql` backfill
already numbered existing tournaments).

**Branch:** `feature/tournament-module`

---

## Files changed (this effort)

| File | What changed |
|---|---|
| `system/lib/ork3/class.Tournament.php` | `AddParticipant` (alias-only number reuse + return value); `GetStandings` (added `ParticipantNumber`); new `UpdateAlias()` method |
| `orkui/model/model.Tournament.php` | new `update_alias()` passthrough |
| `orkui/controller/controller.TournamentAjax.php` | `addparticipant` action now returns `participantNumber`; new `updatealias` action |
| `orkui/template/revised-frontend/Tournametnew_index.tpl` | CSS, PHP helper, roster badge + inline-edit, standings shield, add-participant JS, bout-list + bracket-box shield/seedling, `tnEditAlias`/`tnPidShieldHTML` JS |

All template/PHP edits were made with Python `str.replace` against unique anchors
(per project convention — tab-vs-space safety).

---

## Feature 1 — Inline alias edit

A pencil button next to each player's name on the Participant roster opens an inline
`<input>`; **Enter or blur commits, Esc cancels**. The rename propagates to **every
bracket** the person appears in within the tournament.

**Backend (`class.Tournament.php`)** — new `public function UpdateAlias($request)` inserted
immediately after `UpdateParticipantStatus` (before `SearchParks`). Auth-gated; validates
`TournamentId`/`BracketId`/`ParticipantId`; trims + rejects empty + caps at 100 chars.
Propagation key is the **tournament-wide `participant_number`** (present on copied bracket
rows, unlike `participant_mundane`):

```sql
-- if participant_number > 0:
UPDATE ork_participant SET alias = :alias WHERE tournament_id = :tid AND participant_number = :pnum
-- else (legacy/edge, number 0): single-row by participant_id
```
Returns `Success(['ParticipantId'=>..., 'Alias'=>...])`. Calls `bustTournamentReportCache()`.

**Model** — `update_alias()` → `UpdateAlias()` (one-liner, after `update_participant_status`).

**Controller** — new `elseif ($action === 'updatealias')` block inserted **after the
`updateparticipantstatus` block and before the terminal `else`** in `bracket()`. Returns
`{status:0, participantId, alias}`.

**Template** — roster alias text wrapped in `<span class="tn-alias-text" data-alias="...">`
(team branch + both individual branches), with a `<button class="tn-alias-edit" ...
onclick="tnEditAlias(this)">` (manager-only). New `window.tnEditAlias` defined just before
`window.tnSetParticipantStatus`. CSS added for `.tn-alias-edit` / `.tn-alias-input` (+ dark
mode). Endpoint URL: `TnConfig.uir + 'TournamentAjax/bracket/' + bid + '/updatealias'`.

> Note: a cross-bracket rename updates all rows in the DB, but only the **current bracket's
> DOM** updates live; other brackets refresh on next view.

---

## Feature 2 — Tournament-wide stable player numbers (shield) + seed marker

The number assignment was **already** tournament-wide & person-stable in `AddParticipant`.
This effort (a) closed the alias-only gap and (b) made the number **display consistently**
as a distinctive **shield badge** (`.tn-pid`, CSS clip-path shield), while marking the
distinct *seed* value with a 🌱 `fa-seedling` icon where it still appears.

**Backend:**
- `AddParticipant`: when no `MundaneId`, reuse the number of an existing **alias-only** row
  with the same alias text in the tournament (`pm.mundane_id IS NULL AND p.alias = :a`).
- `AddParticipant` new-path return changed from `Success($pid)` →
  `Success(['ParticipantId'=>..., 'ParticipantNumber'=>$_pnum])`. **Copy path still returns
  an int** — the controller now handles both (`is_array($detail)`).
- `GetStandings`: added `p.participant_number` to **both** the team and individual SELECT +
  GROUP BY, and `'ParticipantNumber'` to both output arrays.

**Controller** — `addparticipant` echo rewritten to read array-or-int `Detail` and emit
`participantNumber`.

**Template display surfaces (per product decision):**
- **Roster badge** → `tnPidShield()` shield (was `$i + 1`). Falls back to the positional
  circle when number is 0.
- **Standings** → shield prepended in the Participant cell.
- **Bracket match boxes** (`buildMatchBox`) → shield added after the avatar; the seed circle
  (`.tn-bv-seed`) now shows `<i class="fas fa-seedling tn-seedling">` + seed, `data-tip="Seed"`.
- **Bout list** (`tnBoutListName`) → shield (from `members.ParticipantNumber`) + seedling-
  marked seed.
- New PHP helper `tnPidShield($n)` (after `tnParticipantPills`) and JS `tnPidShieldHTML(n)`
  (before `tnBoutListName`).
- CSS: `.tn-pid` (+ `.tn-bv-tree .tn-pid` compact + `td .tn-pid`), `.tn-seedling`, dark-mode
  variants. Strike-through rules for withdrawn/DQ now also exclude `.tn-pid`.

> The new `.tn-pid` badge is intentionally **decoupled** from `.tn-participant-seed` /
> `.tn-seed-enhanced`, so the existing positional-renumber loops (`tnRemoveParticipant`,
> the DnD `renumberList`) no longer touch it — the number stays stable across add/remove/
> reorder, which is the whole point.

---

## ⚠️ Overlap with `2026-05-24-bracket-play-in-round.md`

Both efforts edit the same four files. Regions are mostly disjoint, but **`Tournametnew_index.tpl`
has one true hot zone**: the bracket-viz `renderSection` / `buildMatchBox` area (~lines 7000–7220).

| Shared file | Play-in plan touches | This effort touches | Conflict? |
|---|---|---|---|
| `class.Tournament.php` | `GetBrackets`, `UpdateBracket` | `AddParticipant`, `GetStandings`, new `UpdateAlias` | Different functions — no overlap |
| `controller.TournamentAjax.php` | `updatebracket` action | `addparticipant` action, new `updatealias` action | Different actions — no overlap |
| `Tournametnew_index.tpl` | edit-bracket modal (~2858), `tnOpenEditBracketModal`/submit (~4502–4598), CSS before `/* Bracket visualization */` (~323), `tnShouldOfferPlayIn` + `renderSection` (~7000–7048) | CSS after `.tn-participant-seed` rule, roster (~2356), standings (~2647), add-JS (~5522/5698), `buildMatchBox` seed/avatar (~7208), `tnEditAlias` (~9159), `tnBoutListName` (~9814) | **Watch `buildMatchBox`**: this effort inserts a `.tn-pid` shield right after `slot.appendChild(av);` and rewrites the `seed.textContent = info.p.Seed` line. The play-in plan's Task 5 edits `renderSection` (round labels + bye-spacer in the `rMatches.forEach` call), which is adjacent but not the same lines. Both should apply, but verify after merge. |

**Reconciliation checklist:**
1. Confirm both CSS blocks landed (this effort's is after the `.tn-participant-seed` rule;
   play-in's `.tn-seg`/`.tn-field-hint` is before `/* Bracket visualization */`).
2. In `buildMatchBox`, confirm the slot renders: avatar → **player-# shield** → seed
   (with seedling) → name, AND that play-in mode still hides round-1 bye boxes correctly.
3. Confirm the `controller.TournamentAjax.php` `bracket()` dispatch chain has **all** new
   actions: `updatebracket` (play-in), `updateparticipantstatus`, `updatealias` (this effort).
4. `php -l` all four files. Then load a tournament page and check: roster shields, inline
   alias edit (Enter/blur), standings shield, bout-list shield+seedling, bracket-box
   shield+seedling, AND the play-in round label/byes.
5. `git diff --stat master...HEAD` — expect only the four shared files + the spec/plan docs.
   No `CLAUDE.md`, `agent-instructions/claude.md`, or `class.Authorization.php`.

---

## Quick verification queries (post-merge)

```bash
# Alias propagation: pick a person in 2+ brackets of one tournament, rename via UI, then:
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
 "SELECT bracket_id, alias, participant_number FROM ork_participant WHERE tournament_id=<TID> AND participant_number=<PNUM>;"
# Expect: same alias on every row sharing that participant_number.

# Stable number: same person across brackets shares one number.
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
 "SELECT participant_number, COUNT(DISTINCT bracket_id) brackets, GROUP_CONCAT(DISTINCT alias) FROM ork_participant WHERE tournament_id=<TID> GROUP BY participant_number;"
```
