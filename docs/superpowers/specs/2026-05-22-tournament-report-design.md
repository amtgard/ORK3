# Tournament Report — Design Spec

**Date:** 2026-05-22
**Scope:** Kingdom- and Park-level Tournaments Report for ORK3
**Status:** Approved for planning

## Summary

A tabbed, scope-aware report that aggregates tournament data over time into three
audiences: athlete/competitor performance, an awards-recognition pipeline, and
cross-park comparison — plus a program-health overview. It reuses the existing
tournament data model and the Order of the Warrior (0–12) ranking that the
tournament module already computes from awards, and it deep-links into the
existing award-recommendation flow.

This is greenfield development; current tournament rows are test data, so no
historical backfill is required.

## Routing & Entry Points

- **Endpoints** (following the existing `Reports/{method}/{Type}&id={N}` convention
  used by `attendance`, `player_awards`, etc.):
  - `Reports/tournaments/Kingdom&id=N`
  - `Reports/tournaments/Park&id=N`
- **Entry points:** a new link in the Reports grid on both
  `orkui/template/revised-frontend/Kingdomnew_index.tpl` and
  `orkui/template/revised-frontend/Parknew_index.tpl`, under a "Tournaments" /
  "Competition" heading.
- **Scope** is fixed by the URL (Kingdom vs Park). Park scope hides the Parks tab.

## Global Controls

- **Date-range filter** — flatpickr with `altInput: true` and a human-readable
  `altFormat` (per project date-display convention). Default = **all-time**.
  Filters every aggregate on every tab. Tournaments are bounded by
  `ork_tournament.date_time`.

## Tabs

The report is a single template with four tabs.

### 1. Overview (program health)

- Tournaments run: total and by `status` (setup/active/complete).
- Unique participants and average participants per tournament.
- Completion rate: % of tournaments/brackets reaching `complete`/`finalized`.
- Breakdown by **style** (`ork_bracket.style`: Single Sword, Florentine, Sword and
  Shield, Great Weapon, Missile, Jugging, Battlegame, Quest, Other) and by
  **method** (`ork_bracket.method`: single/double/swiss/round-robin/ironman/score).
- **Average warrior level of fields** — field-strength indicator from
  warrior-at-time snapshots.
- **Trend chart** — tournaments and participation over time, rendered as an inline
  SVG/CSS bar chart. No new JS dependency (matches the no-library precedent set by
  the bracket renderer).

### 2. Fighters (performance leaderboard)

Per player, joining `ork_participant → ork_participant_mundane → mundane`.
**Individual brackets only for v1** (`ork_bracket.participants = 'individual'`);
team brackets contribute to Overview counts but not per-fighter stats yet.

Sortable columns:

- **Warrior level (0–12)** — the global-ranking / "rating" column. Current level,
  derived via existing `fetchAwardsForMundanes()` logic
  (award 27 = Order of the Warrior rank 1–10, 12 = Warlord → 11,
  20 = Knight of the Sword → 12).
- Tournaments entered
- Matches played, **W / L / win %**
- **Championships** — bracket wins (see Placement Computation)
- **Podiums** — 1st/2nd/3rd finishes
- **Longest streak** — `ork_participant.im_max_streak`
- Standings points earned
- Best style (style with the highest win rate / most championships)
- **Upset wins** — wins against an opponent whose warrior-at-time was meaningfully
  higher (threshold to be fixed in the plan, e.g. ≥3 levels higher).

**Rating extensibility:** the leaderboard reads any computed rating from a single
pluggable method (`GetFighterRating()`), which returns null today. A Glicko2/Elo
pipeline can populate it later without UI changes; warrior level remains the
day-one ranking signal.

### 3. Awards (recognition pipeline)

- Threshold-based candidate list (e.g. ≥N championships or ≥N podiums in range;
  thresholds finalized in the plan), each row showing the **tournament evidence**.
- **Headline signal:** fighters dominating fields **above their current Warrior
  rank** → **Order of the Warrior candidates**. Tournament dominance relative to
  current warrior level is the exact evidence kingdoms use to grant the next OotW.
- Each candidate has a button that opens the **existing award-recommendation
  modal**, pre-filled with the player and an auto-composed evidence note
  (e.g. "3 tournament championships in Single Sword, Jan–May 2026; multiple upset
  wins over higher-ranked fighters"). Pure reuse of the existing flow and the
  custom `kn-ac` autocomplete patterns. No new write path or auto-recommendation
  engine.

### 4. Parks (cross-park comparison) — kingdom scope only

Per park within the kingdom, sortable:

- Tournaments hosted
- Participants fielded
- Championships won by that park's fighters
- Top fighter
- Average warrior level of participants

Hidden entirely at park scope.

## Placement Computation (the critical unit)

A tournament can hold several brackets (one per weapon style), so a
**championship is a bracket win**, not a tournament win. All per-fighter placement
stats depend on one isolated, well-tested helper:

- **`GetBracketPlacements($bracket_id)`** → ordered participant placements
  (1st, 2nd, 3rd, …).
  - **Elimination** (single/double): walk the final / grand-final match for 1st &
    2nd; semifinal losers for 3rd.
  - **Round-robin / Swiss / Ironman:** top of the existing `GetStandings` ordering.

This is the riskiest unit and gets its own focused tests; everything in the
Fighters, Awards, and Parks tabs builds on it.

## Warrior-at-Time-of-Competition

To keep historical comparisons apples-to-apples, capture each participant's warrior
level at the moment they competed.

- **New column:** `ork_participant.warrior_level` (TINYINT, 0–12, default 0).
- **Write path:** populated at participant-add and/or bracket-lock, from the same
  `fetchAwardsForMundanes()` / `warrior_seed_rank()` logic the seeding code already
  uses.
- **No backfill** — greenfield; existing rows are test data.
- Used by: upset-win detection, strength-of-field averages, and historically
  consistent context for podiums/championships.

## Architecture (per project layer convention)

DB/business logic lives only in `system/lib/ork3/`; `orkui/model` is a thin
pass-through; the controller orchestrates and the template renders.

- **`system/lib/ork3/class.TournamentReport.php`** (new — keeps `class.Report.php`
  from bloating). Methods, each taking `{KingdomId | ParkId, DateFrom, DateTo}`:
  - `GetBracketPlacements($bracket_id)`
  - `GetTournamentProgramStats($request)`
  - `GetFighterLeaderboard($request)`
  - `GetTournamentParkComparison($request)` (kingdom only)
  - `GetTournamentAwardCandidates($request)`
  - `GetFighterRating(...)` — pluggable rating hook, returns null for now
- **`orkui/model/model.Reports.php`** — thin pass-throughs to the above.
- **`orkui/controller/controller.Reports.php`** — new `tournaments($params)` method
  parsing Type + Id exactly like `attendance()` / `player_awards()`.
- **`orkui/template/.../Reports_tournaments.tpl`** — revised-frontend style,
  CSS prefixed to avoid global collisions, **dark-mode compatible from the start**
  (modal headers, ghost buttons, labels, placeholders, segmented toggles, info
  boxes — per the dark-mode checklist), in-product `data-tip` tooltips (never
  native `title`), human-readable dates.

## Data Sources (reference)

- `ork_tournament` — tournament_id, kingdom_id, park_id, event_id, name, status,
  date_time, standings_points
- `ork_bracket` — bracket_id, tournament_id, style, method, participants, status
- `ork_match` — participant_1_id, participant_2_id, result, bracket_id, round,
  bracket_side, score, created, ring_number
- `ork_participant` — participant_id, tournament_id, bracket_id, alias, unit_id,
  park_id, kingdom_id, seed, eliminated, im_wins, im_current_streak, im_max_streak,
  status, **(+ new) warrior_level**
- `ork_participant_mundane` — participant_id → mundane_id (the player)
- `ork_awards` — award_id 27/12/20 drive warrior level

## Out of Scope (v1)

- Team-bracket per-fighter attribution (counted in Overview only).
- Computed Glicko2/Elo rating (column hook present, pipeline deferred).
- Auto-generating/queuing award recommendations (deep-link only).
- By-reign/year bucketing (date-range filter covers the need for now).
