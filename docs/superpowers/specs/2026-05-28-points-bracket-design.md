# Points Bracket — Design

**Date:** 2026-05-28
**Branch:** feature/tournament-module
**Status:** Draft for review

## Goal

Add a new bracket method, **Points**, to the tournament module. A Points bracket is a non-elimination, multi-round scoring format: every participant plays every round, and final placement is determined by total points scored across all rounds. Use cases: archery, thrown-weapons, accuracy/skill challenges where each round produces an independent score rather than a head-to-head bout outcome.

The run/score UI is a spreadsheet-style grid (players × rounds), with two scoring modes:

- **Fixed Points** — a configurable scale (e.g. `5,3,1,0`) rendered as clickable pips per cell.
- **Open Points** — a small numeric input per cell accepting any non-negative decimal.

All cell writes auto-save on blur / click-away / pip-click.

## Non-Goals

- No elimination tree, no advancement, no bouts. Points sits alongside `single`, `double`, `swiss`, `round-robin`, `ironman` as a seventh independent method.
- No per-cell concurrency lock (last-write-wins; acceptable for typical small tournaments).
- No decreasing the round count after `status=active` (would require a bracket reset).
- No mid-run change to the Fixed-Points scale once `status=active`.
- No "rings" / concurrent stations — irrelevant for a flat grid.
- No best-round / drop-worst scoring reductions. Total = sum across rounds, period.
- No tiebreaker beyond shared placement (no countback, no organizer manual break).

## Configuration

At bracket creation, when **Method = Points** is chosen, the form exposes:

| Field          | Type                              | Notes                                                                                                                                       |
| -------------- | --------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| Style          | existing enum (Single Sword, etc.) | Unchanged. Drives the tournament-type label. Archery would typically be added here as a Style if not present.                              |
| Rounds         | integer 1–32                      | Default 3. Initial round count.                                                                                                             |
| Point Mode     | radio: `fixed` / `open`           | Default `fixed`.                                                                                                                            |
| Point Scale    | CSV string, e.g. `5,3,1,0`        | Required when `fixed`; hidden when `open`. Helper text: *"Comma-separated values shown as clickable pips. First value is highest."* Live pip preview rendered beneath the input. |
| Seeding        | existing options                  | Drives row order on the grid. Manual/glicko2/random/warrior-level all valid.                                                                |
| Participants   | individual / team                 | Both supported with no special logic — a "team" is just a row.                                                                              |
| Rings          | hidden                            | Forced to 1; not exposed in UI.                                                                                                             |

### Point Scale validation (fixed mode)

- 1–16 values, comma-separated.
- Each value: non-negative decimal, 0–999.99, up to 2 decimal places.
- No duplicates.
- Order preserved (rendered left-to-right; first value is conventionally the highest but enforcement is not strict — organizer can choose `0,1,3,5` if they prefer).

### Mid-run mutation rules

| After `status=active`           | Allowed? |
| ------------------------------- | -------- |
| Add a round (R+1)               | ✅ Yes (organizer button on the grid) |
| Remove a round                  | ❌ No — requires bracket reset       |
| Change Point Scale CSV          | ❌ No — locked once any cell scored  |
| Switch Point Mode (fixed↔open)  | ❌ No — locked once active           |
| Add a participant               | ✅ Yes — new row with null cells     |
| Withdraw / DQ a participant     | ✅ Yes — existing flow; their cells stay but they're excluded from placement (matches existing `participant.status` semantics) |
| Reorder participants            | ✅ Yes — existing ReorderSeeds        |

## Data Model

### New table

```sql
CREATE TABLE ork_point_score (
  point_score_id  int(11)         NOT NULL AUTO_INCREMENT,
  bracket_id      int(11)         NOT NULL,
  participant_id  int(11)         NOT NULL,
  round           int(11)         NOT NULL,         -- 1-indexed
  points          decimal(8,2)        NULL,         -- null = not scored / cleared
  scored_at       datetime            NULL,
  scored_by       int(11)             NULL,         -- player_id who entered (audit)
  PRIMARY KEY (point_score_id),
  UNIQUE KEY uq_cell (bracket_id, participant_id, round),
  KEY idx_bracket (bracket_id),
  KEY idx_participant (participant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Notes:
- `decimal(8,2)` ceiling is 999999.99 — vastly more than archery needs; safe.
- Null `points` (vs no row) is the canonical "cleared" state. Saving null deletes or upserts a NULL row; either is fine — we choose **upsert NULL** for simpler queries.
- `scored_by` is `player_id` not user/session — matches existing PostMatchResult audit pattern.

### Extend `ork_bracket`

```sql
ALTER TABLE ork_bracket
  MODIFY method enum('single','double','swiss','round-robin','ironman','score','points')
    NOT NULL DEFAULT 'single',
  ADD COLUMN point_rounds int(11) NULL AFTER best_of,
  ADD COLUMN point_mode   enum('fixed','open') NULL AFTER point_rounds,
  ADD COLUMN point_scale  varchar(120) NULL AFTER point_mode;
```

All three new columns are NULL for non-points brackets.

### No use of `ork_match`

Points brackets create zero rows in `ork_match`. `GetStandings()` and `GetBracketPlacements()` get an explicit `points` branch that reads exclusively from `ork_point_score`.

## Backend — `system/lib/ork3/class.Tournament.php`

### New / changed methods

- **`AddBracket()` and the existing bracket-edit path** — accept `point_rounds`, `point_mode`, `point_scale` when `method='points'`. Validate per the rules above. Persist to the new columns. The edit path must enforce the mid-run lock rules: `point_mode` and `point_scale` rejected if `status='active'` and any `ork_point_score` row exists; round count may only increase post-activation.
- **`generate_points($bracketId)`** — verify ≥1 participant; set `status='active'`. No match rows are written. Called from the existing `GenerateMatches()` dispatch.
- **`SavePointScore($bracketId, $participantId, $round, $points)`** —
  - Authorization via existing `check_auth($bracketId)`.
  - Validate: bracket method is `points`, `status` not `finalized`, participant belongs to bracket, `1 ≤ round ≤ point_rounds`.
  - In `fixed` mode: `$points` must be null OR an exact match (string-equal after normalize) to one of the CSV values.
  - In `open` mode: `$points` must be null OR a non-negative decimal ≤ 999.99 with ≤2 decimal places.
  - **`$DB->Clear()` before Execute** (project convention — silent failure protection).
  - Upsert into `ork_point_score` keyed on `(bracket_id, participant_id, round)`.
  - Return updated cell + recomputed standings (totals + placements) so the client updates Total column + standings ribbon in one round trip.
- **`AddPointsRound($bracketId)`** — increment `point_rounds` by 1. Blocked if `status='finalized'`. Returns the new round count.
- **`GetPointStandings($bracketId)`** — returns array of rows:
  ```
  [
    { participant_id, alias, participant_number, status,
      round_scores: [r1, r2, ...], total, place }
    ...
  ]
  ```
  Ordered by `total DESC, alias ASC` (alias ASC is for stable display order only — not a tiebreaker for placement). Withdrawn/DQ participants excluded from `place` numbering but still returned (status flag for UI). Ties produce shared placements using rank-with-gaps (1, 2, 2, 4) — emitted as `{ place: 2, tied: true }` and the UI renders the `T-` prefix.
- **`GetStandings()`** — add a method-switch: `if ($method === 'points') return $this->GetPointStandings($bracketId);`. Existing callers (TournamentReport, etc.) work unchanged.
- **`GetBracketPlacements()`** (in `class.TournamentReport.php`) — points branch reuses `placementsFromStandings()` (existing helper for non-elimination types). No special logic needed.

### Cascade behavior

- Deleting a bracket cascades-delete `ork_point_score` rows (FK or explicit cleanup in the existing bracket-delete path — match whatever ork_match uses).
- Deleting a participant cascades-delete their `ork_point_score` rows.

## API — `orkui/controller/controller.TournamentAjax.php`

| Endpoint                                                            | Body                                              | Returns                                              |
| ------------------------------------------------------------------- | ------------------------------------------------- | ---------------------------------------------------- |
| `POST /TournamentAjax/bracket/{bid}/savepointscore`                 | `participant_id`, `round`, `points` (null clears) | `{ success, cell:{...}, standings:[...] }`           |
| `POST /TournamentAjax/bracket/{bid}/addpointsround`                 | (none)                                            | `{ success, point_rounds: N }`                       |

The existing `addbracket`, `generate`, `reorder`, `addparticipant`, `updateparticipantstatus` endpoints all accept the new method with no signature change.

## Model layer — `orkui/model/model.Tournament.php`

Thin pass-through additions via existing `__call` magic — `SavePointScore`, `AddPointsRound`, `GetPointStandings` auto-forward. No explicit method definitions required unless a transform is needed (none anticipated).

## UI — Create / Configure

In the existing AddBracket modal:

- When **Method** dropdown changes to `Points`:
  - Hide irrelevant fields: `best_of`, `rings`.
  - Show: `Rounds` number input, `Point Mode` radio, `Point Scale` text (only when `Fixed`).
- Live pip preview beneath the Point Scale input updates on input: e.g. typing `5,3,1,0` shows `[5][3][1][0]` styled with the same pip CSS used on the grid.
- Client-side validation mirrors backend rules; submit blocked with inline error message until valid.
- Dark-mode compatible per project rules (pre-flight checklist).

## UI — Run / Score (the grid)

### Layout

```
┌─ Bracket header (style, status, "Add Round" if active) ──┐
├─ Standings ribbon: 1st Alice (9) · 2nd Bob (5) · ... ─────┤
├─ Grid ────────────────────────────────────────────────────┤
│ Player                  │ R1     │ R2     │ R3 │ Total   │
│ #1 Alice Aldrich        │ [pips] │ [pips] │ [..│   9     │
│ #2 Bob Brightblade      │ [pips] │ [pips] │ [..│   5     │
└───────────────────────────────────────────────────────────┘
```

- **Player column**: sticky-left; shows `#N` participant_number + alias. Existing drag-handle (ReorderSeeds) attaches here.
- **Round columns**: one per round. Header shows `R1`, `R2`, … with no extra controls per column.
- **Total column**: sticky-right; bold; updates live on every save.
- **Add Round button**: small `+` button at the right edge of the header row, visible only when `status='active'` and user has edit auth.

### Fixed-mode cell

- Renders the scale as a row of pip buttons: `[5][3][1][0]`.
- Pip states: `unselected` (outline), `selected` (filled, primary color).
- Click on an unselected pip → POST save with that value. Click on the currently-selected pip → POST save with `null` (clear).
- Switching from one pip to another in the same cell is a single POST (with the new value) — the UI doesn't pre-clear.
- Whole row sized to fit naturally; on narrow widths the pip row wraps within the cell.
- A small saving/saved/error indicator (tick / spinner / retry icon) sits to the right of the pip row.

### Open-mode cell

- `<input type="text" inputmode="decimal" maxlength="5" size="3">`. Sized for ~3 characters via CSS width.
- Accepts characters: `0-9` and `.`. JS strips invalid input. Backend re-validates.
- Save trigger: blur, Enter, click-elsewhere, or focus-change to another cell.
- Validation: non-negative decimal, ≤999.99, ≤2 decimal places. Invalid → red border, revert to previous value after a brief flash, no save.
- Same saving indicator as fixed mode.

### Standings ribbon

- Top-of-grid one-liner: `1st Alice Aldrich (9) · 2nd Bob Brightblade (5) · ...`.
- Ties render as `T-1 Alice (9), T-1 Carol (9) · T-3 Bob (5) · ...`.
- Updates live after each save (the save endpoint returns standings, so no extra fetch).
- Withdrawn/DQ participants excluded from the ribbon.

### "Add Round" interaction

- Click `+` → POST `addpointsround` → on success, append a new `Rn+1` column with all blank cells. No page reload.

### Mobile / Tournament Mobile Organizer

- Reuse the existing `TnMobile` foundation (viewMode / swipe / sheet / deck).
- Alt "stack" view: one participant card per screen, showing their pip row(s) per round with arrow-paging to next participant. Lets a reeve walk a line of archers entering scores without scrolling a wide grid.
- Default mobile view is the grid (horizontally scrollable); the stack view is opt-in via the existing TnMobile mode toggle.

### Project conventions (dark mode pre-flight)

- Modal headers reset `background/border/padding/border-radius` from the global `h1–h6` rule.
- Pip selected/unselected colors verified in both light and dark mode.
- Cell input border, focus ring, and red-error state verified in dark mode.
- No native `title` attributes — use `data-tip` for any hover hints (e.g. on the `+` Add Round button).
- Standings ribbon legible in both themes.

## Authorization

- All write endpoints (`savepointscore`, `addpointsround`) gated by the existing `check_auth($bracketId)` — kingdom/park/event edit OR tournament reeve role (`organizer` / `bracket_runner`).
- Read paths are public (matches the existing tournament view-mode behavior).

## Concurrency

- Last-write-wins on a cell. Two reeves editing the same cell on two devices → the later write sticks. The grid does **not** poll for updates from other devices; a manual refresh shows the latest state. Acceptable for typical tournament size and reeve count.

## Standings & Placements

- **Within bracket**: total = sum of non-null `points` across rounds. Place by `total DESC`. Ties share a place (rank-with-gaps: 1, 2, 2, 4).
- **Withdrawn / DQ**: not placed; appear at the bottom of the grid with a status badge; excluded from the standings ribbon.
- **TournamentReport `GetBracketPlacements()`**: points-method branch passes the standings through `placementsFromStandings()` (existing helper). Knock-on effect: tournament-level `standings_points` (the `[5,4,3,2,1,...]` placement-to-points mapping) applies to Points brackets exactly like it does to RR/Swiss.

## Migrations

Single SQL file: `db-migrations/2026-05-28-points-bracket.sql`

```sql
ALTER TABLE ork_bracket
  MODIFY method enum('single','double','swiss','round-robin','ironman','score','points')
    NOT NULL DEFAULT 'single';

ALTER TABLE ork_bracket
  ADD COLUMN point_rounds int(11) NULL AFTER best_of,
  ADD COLUMN point_mode   enum('fixed','open') NULL AFTER point_rounds,
  ADD COLUMN point_scale  varchar(120) NULL AFTER point_mode;

CREATE TABLE ork_point_score (
  point_score_id  int(11) NOT NULL AUTO_INCREMENT,
  bracket_id      int(11) NOT NULL,
  participant_id  int(11) NOT NULL,
  round           int(11) NOT NULL,
  points          decimal(8,2) NULL,
  scored_at       datetime NULL,
  scored_by       int(11) NULL,
  PRIMARY KEY (point_score_id),
  UNIQUE KEY uq_cell (bracket_id, participant_id, round),
  KEY idx_bracket (bracket_id),
  KEY idx_participant (participant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Run via project convention:
```
docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-05-28-points-bracket.sql
```

## Test Plan

Manual (no automated test infra in this project for the tournament module):

1. **Create**: Create a tournament, add a Points bracket with method=points, rounds=3, mode=fixed, scale=`5,3,1,0`. Verify form blocks invalid CSVs (duplicates, negative, >2 decimal places).
2. **Add participants**: Add 4 participants. Verify each appears as a grid row.
3. **Generate**: Click Generate. Verify `status` flips to `active`, grid is rendered with 4 rows × 3 round columns × 4-pip cells.
4. **Fixed scoring**: Click pips across all cells. Verify each click triggers a save (network tab), Total column updates live, standings ribbon updates live.
5. **Pip clear**: Click an already-selected pip. Verify it clears (cell reverts to null, Total recomputes).
6. **Add round**: Click `+`. Verify R4 column appears blank for all participants and `point_rounds` increments in DB.
7. **Add late participant**: Add a 5th participant after some scoring. Verify their row appears with all cells blank.
8. **Open mode**: Create a second bracket with mode=open. Enter `8.5`, `9.25`, `0` in cells. Verify decimals persist; invalid input (`abc`, `-1`, `1000`, `5.555`) is rejected with red border + revert.
9. **Concurrency**: Open the same bracket in two browsers. Score the same cell from each. Verify last write wins, no error.
10. **Ties**: Score two participants to the same total. Verify ribbon shows `T-1` for both.
11. **Auth**: Log out. Verify save endpoints reject with 401-equivalent; grid renders in read-only mode (no clickable pips, inputs disabled).
12. **Dark mode**: Toggle dark mode. Verify pip colors, cell input, focus ring, modal header, and ribbon all read correctly.
13. **Mobile**: Open on a phone-width viewport. Verify grid horizontal-scrolls cleanly, stack view available via TnMobile toggle, pip-tap works.
14. **Placement integration**: Finalize the bracket. Verify TournamentReport shows placements 1–N (or T-1, T-1, T-3 for ties) and tournament-level placement points apply.
15. **Locked fields**: After scoring starts, attempt to change `point_scale` via direct API request. Verify rejection.

## Open Questions

None — all configuration choices have been resolved.

## File Inventory (anticipated changes)

| File                                                             | Change            |
| ---------------------------------------------------------------- | ----------------- |
| `db-migrations/2026-05-28-points-bracket.sql`             | new               |
| `system/lib/ork3/class.Tournament.php`                           | +methods, dispatch |
| `system/lib/ork3/class.TournamentReport.php`                     | placement branch  |
| `orkui/model/model.Tournament.php`                               | (likely no-op via __call) |
| `orkui/controller/controller.TournamentAjax.php`                 | +2 endpoints      |
| `orkui/template/default/Tournament_create.tpl`                   | method option + conditional fields |
| `orkui/template/default/Tournametnew_index.tpl` (existing typo)  | grid render + JS  |
| `orkui/template/default/Tournament_run.tpl` (or wherever brackets are run) | grid run UI |
| CSS for pip styling (inline in template per project convention)  | new pip styles    |
