# Warrior Release — Tournament Module

**Branch:** `feature/tournament-module`
**Module:** Bracket-driven tournament running, end to end.

The Warrior release adds a full tournament workflow: create a
tournament, configure one or more brackets in the formats Amtgard
actually runs (single elim, double elim, swiss, round-robin,
ironman), seed by hand or by Order of the Warrior rank, register
participants individually or as teams, and *run* the bracket live
on the field — pip-counting, ring assignment, Ironman timer,
tiebreakers, and standings.

---

## Why this exists

Tournaments at Amtgard events are run on whiteboards and paper
brackets. The ORK had skeleton tournament/bracket tables but no
generation engine, no live-running surface, no team support, no
seeding logic, no standings. Warrior fills in the entire missing
middle so a tournament marshal can:

- Build the bracket on a phone or laptop the morning of.
- Walk the field with the bracket as a live document — record
  results match-by-match, watch the next round materialize.
- Hand the standings (with point values) directly to the monarchy
  for award decisions or to a feast organizer for placement
  recognition.

---

## Data model

This branch substantially extends the existing
`ork_tournament` / `ork_bracket` / `ork_match` / `ork_participant`
tables, plus adds two new tables for teams and re-uses an event
external-links table.

### New columns on `ork_participant`

| column | type | meaning |
|---|---|---|
| `seed` | INT | seed within bracket (1 = top seed) |
| `eliminated` | TINYINT | knocked out flag |
| `bracket_side` | ENUM('winners','losers','') | for double-elim |
| `participant_number` | INT | per-tournament durable participant ID; same person across multiple brackets keeps the same number (backfilled by 2026-03-18 migration via temporary table) |
| `status` | VARCHAR(20) | live-ops state: `active`, `checked-in`, `dq`, `withdrawn` |

### New columns on `ork_match`

| column | type | meaning |
|---|---|---|
| `bracket_side` | ENUM('winners','losers','grand-final','tiebreaker-3rd','') | which side of double-elim or special-purpose match |
| `bouts` | TEXT | per-bout result strings (best-of-N support) |
| `ring_number` | INT | which ring (multi-ring Ironman) |
| `score` | **VARCHAR(20)** | was DOUBLE(12,4) which silently truncated "2-1" → 2; now stores actual score strings |
| `result` | ENUM(...) NULLable | now allows NULL so unplayed matches don't pre-default to '1-wins' |

### New columns on `ork_bracket`

| column | type | meaning |
|---|---|---|
| `status` | ENUM('setup','active','complete','finalized') | lifecycle |
| `current_round` | INT | live-ops bookkeeping |
| `is_locked` | TINYINT | freeze further match generation |
| `duration_minutes` | INT | for Ironman countdown |
| `best_of` | TINYINT (default 1) | best-of-N bouts per match (1, 3, 5, 7, 9) |
| `seeding` | ENUM extended with `'warrior'` | Order of the Warrior–rank seeding |

### New columns on `ork_tournament`

| column | type | meaning |
|---|---|---|
| `status` | ENUM('setup','active','complete') | lifecycle |
| `standings_points` | VARCHAR(64) | JSON array — configurable point values per placement, default `[5,4,3,2,1,0,0,0]` |

### New table: `ork_participant_teams`
One row per team per bracket.

| column | type | meaning |
|---|---|---|
| `team_id` | INT PK | |
| `tournament_id` / `bracket_id` | INT | denormalized for fast lookup |
| `participant_id` | INT FK | the bracket entry; team name mirrored on `ork_participant.alias` |
| `name` | VARCHAR(100) | team display name (canonical here) |

### New table: `ork_participant_team_members`
Roster rows — one per member.

| column | type | meaning |
|---|---|---|
| `id` | INT PK | |
| `team_id` | INT FK | |
| `mundane_id` | INT FK | the player |
| `tournament_id` | INT | denormalized |

UNIQUE `(team_id, mundane_id)` — prevents double-rostering.

### New table: `ork_event_links`
Multi-link replacement for the single `Url` / `UrlName` field on
event occurrences. Tournaments use it for live brackets, rules
docs, livestream links, etc.

| column | meaning |
|---|---|
| `event_link_id` | PK |
| `event_calendardetail_id` | parent occurrence |
| `title` / `url` / `icon` / `sort_order` | display fields |

### Migration order notes

- Several migrations use `IF NOT EXISTS` / information_schema
  guards because the underlying tournament tables already existed
  on master in skeletal form — re-running is safe.
- The `2026-04-06-bracket-side-tiebreaker-enum.sql` and
  `-bracket-status-finalized-enum.sql` migrations are **fixes for
  silent failures** that earlier code introduced: writing values
  not in the enum with sql_mode disabled → empty string stored.
  Always extend the enum *before* writing the new value.
- `2026-03-24-fix-match-score-column.sql` is a real bug fix:
  storing "2-1" into a DOUBLE column truncated to 2 with no error
  in the legacy schema.

---

## Code map

### Service layer
**`system/lib/ork3/class.Tournament.php`** — single class, ~1700
lines, 24 public methods. Notable surface:

- **Tournament/team CRUD**: `CreateTournament`, `UpdateTournament`,
  `CreateTeam`, `DeleteTournament`, `CheckAuth`.
- **Bracket CRUD**: `AddBracket`, `UpdateBracket`, `GetBrackets`,
  `DeleteBracket`, `ClearBracketMatches`.
- **Participant CRUD**: `AddParticipant`, `RemoveParticipant`,
  `GetParticipants` (returns warrior-rank metadata for seeding).
- **Match generation** — `GenerateMatches` dispatches by bracket
  format to private generators:
  - `generate_single_elim`
  - `generate_double_elim`
  - `generate_swiss`
  - `generate_round_robin`
  - `generate_ironman` (multi-ring rotation)
- **Match running**: `GetMatches`, `PostMatches`,
  `PostMatchResult` (auto-advances winners; for double-elim,
  drops losers into the losers' bracket), `ResetMatch`.
- **Live-ops extras**: `CreateConfirmationMatch`,
  `CreateTiebreakerMatch`, `RecordIronmanWin`, `CompleteBracket`.
- **Standings**: `GetStandings` consumes `standings_points` to
  weight placements.
- **Seeding**: dispatch on `seeding` column —
  - `manual` — no-op, use `seed` as set
  - `random` — shuffle
  - `glicko2` — by rating
  - `warrior` — Order of the Warrior rank descending
    (0=unranked weakest, 12=Sword Knight strongest); see
    `warrior_seed_rank`
  - `*-manual` variants — auto-seed then allow manual edit before
    matches generate

### Controllers
- **`controller.Tournament.php`** (page routes):
  - `profile($tournament_id)` — main tournament page (default
    redirects to `Run Tournament` tab once a bracket has matches).
  - `worksheet($tournament_id)` — printable worksheet view.
  - `create($post)` — create flow.
- **`controller.TournamentAjax.php`** — three big router methods
  acting as namespaces:
  - `tournament($p)` — sub-routed CRUD on tournament/teams/standings.
  - `bracket($p)` — sub-routed CRUD + Generate, ResetMatches,
    Complete, Tiebreaker.
  - `match($p)` — record result, advance, reset.
  - Plus search helpers: `parksearch`, `eventsearch`.

### Frontend
- **`Tournametnew_index.tpl`** *(filename typo intact — beware)* —
  the entire tournament page: hero, sidebar (Details, Bracket
  summary), tabbed main: About / Brackets / Participants /
  Run Tournament (bracket viz) / Standings.
- **Bracket visualization**: SVG connector lines (H-shaped
  src1/src2 → dest), centered vertical alignment for later rounds,
  collapsible bracket cards.
- **Run Tournament tab** UX:
  - **Next-Up strip** above the bracket viz with NOW + ON DECK
    cards, stacked vertically.
  - **Quick Win / Track Fights toggle** — Quick Win is a single
    button per fighter; Track Fights expands to per-bout pip
    tracking with an **End** button to commit early.
  - Auto-commit on Record Result when pips determine the winner.
  - **Re-generate matches** is gated by an arm-and-fire countdown
    that auto-commits at zero (avoids accidental wipes).
- **Bracket build & organization**: copy a bracket, status badges
  (setup/active/complete/finalized), advanced fields collapsed
  behind a disclosure in Add/Edit Bracket.
- **Paste Roster**: bulk-add participants from a pasted list of
  names — searches mundanes, handles unmatched as free text.
- **Participant linkage**: shows park badge, current persona,
  award pills, Order of the Warrior rank for seeding context.

### Other touch points
- `class.Report.php` — adds tournament-context fields to award
  context where applicable.
- `Eventnew_index.tpl` — surfaces tournaments tied to the event;
  external-links list backed by `ork_event_links`.
- `Kingdomnew_index.tpl` / `Parknew_index.tpl` /
  `Playernew_index.tpl` — Tournaments tabs / sections referencing
  the new module.

---

## Workflows

### Marshal building a bracket
1. Create a tournament (linked to event via Eventnew if available).
2. **Brackets** tab → Add Bracket: format (single / double / swiss
   / round-robin / ironman), seeding (manual / random / glicko2 /
   warrior / *-manual hybrids), best-of-N, duration (Ironman),
   ring count.
3. **Participants** tab → search-add or **Paste Roster** for bulk.
   Mundane-linked entries pull warrior rank for seeding.
4. Lock seeding → **Generate Matches**. Re-generate is allowed
   while the bracket is `active` but gated by the arm-and-fire
   countdown.

### Marshal running on the field
1. Open the tournament → defaults to **Run Tournament** tab once
   matches exist.
2. **Next-Up strip** shows NOW (current matches) and ON DECK (next
   round if visible). Toggle Quick Win or Track Fights per match.
3. **Quick Win**: one click per fighter → result recorded,
   bracket advances.
4. **Track Fights**: per-bout pip tracking. End button commits
   early. Auto-commits when pips settle the winner per
   `best_of`.
5. **Tiebreaker**: `CreateTiebreakerMatch` injects a
   `bracket_side='tiebreaker-3rd'` match.
6. **Confirmation match** for double-elim grand finals via
   `CreateConfirmationMatch`.
7. **Complete bracket** flips status → `complete`; finalize
   (status `finalized`) locks for record.

### Team tournaments
1. Add a Team participant: creates `ork_participant` (alias =
   team name) + `ork_participant_teams` row + roster rows in
   `ork_participant_team_members`.
2. Brackets treat the team as a single participant; per-fighter
   stats are not tracked by the bracket engine itself.

### Standings
- Each placement gets points from `standings_points`. Default
  array `[5,4,3,2,1,0,0,0]` weights top 5 places. Edit per
  tournament for kingdom/circuit-specific scoring.

---

## Things to know before changing this module

- **Filename typo `Tournametnew_index.tpl`** is the canonical name
  — do not "fix" it without rerouting controller `view()` calls
  and updating every reference.
- **`score` is VARCHAR(20)**, not numeric. Don't aggregate it as
  a number; it stores fight-record strings like "2-1".
- **`result` is now NULLable** — pre-Warrior code defaulted to
  `'1-wins'` for unplayed matches, which silently scored fighter
  1 as winning every unplayed slot. Always check for NULL before
  treating a match as complete.
- **Enum migrations are fixes**, not enhancements. With sql_mode
  off, MariaDB silently writes empty string for out-of-enum
  values. Whenever you add a new state, **migrate the enum
  first**. The `tiebreaker-3rd` and `finalized` migrations are
  recovery from this exact pitfall.
- **Seeding order matters for warrior**: 0 = unranked = weakest,
  12 = Sword Knight = strongest. The compare function returns
  `b - a` (higher rank → smaller index → top seed).
- **Re-generate matches is gated**, not blocked. Production code
  flips a bracket back to `active` and arms a countdown; bypassing
  the gate will wipe live results if anyone has been recording.
- **`participant_number` is per-tournament durable**. Same person
  in two brackets shares one number. The backfill SQL uses a
  COALESCE over `mundane_id` falling back to `-participant_id` for
  unlinked entries — preserve that pattern when adding new
  participant flows.
- **Multi-ring Ironman** uses `ring_number` on matches; Ironman
  generation rotates participants across rings. Don't assume
  `ring_number = 1` for all matches.
- **Team stats are bracket-level only** — there is no per-fighter
  standings within a team. If you add fighter-level scoring, model
  it as a new table; do not overload `ork_match`.

## What's not done in this branch

- **No persistent live results display** for spectators (the
  Run Tournament tab is the only view; no read-only mode).
- **No Glicko-2 rating integration writeback** — `glicko2`
  seeding consumes existing ratings; tournament results don't
  feed back into them.
- **Team format support is structural only** — no team-vs-team
  match logic (each team match is treated as a single match
  between two team participants; per-fighter results within a
  team match are not modeled).
- **No "scratch" / withdrawal mid-bracket** UX beyond the `status`
  column; the bracket engine doesn't auto-rebalance after a
  withdrawal.
- **Standings export** — points are computed, but no CSV /
  printable export beyond the `worksheet()` view.
- **Glicko-2 + warrior hybrid seeding** is not implemented (you
  can choose `glicko2-manual` or `random-manual`, not
  `warrior-manual` directly — though manual override is always
  allowed before generate).
- **Order of the Warrior rank lookup** assumes `award_id = 27`
  hardcoded; resilient to renames but not to award-id changes.
- **Best-of-N is bracket-wide**, not per-match. A single bracket
  can't mix best-of-1 prelims and best-of-3 finals.
