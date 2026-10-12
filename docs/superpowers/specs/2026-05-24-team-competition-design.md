# Team Competition Support — Design Spec

**Date:** 2026-05-24
**Module:** Tournament (ORK3)
**Status:** Approved design, pending implementation plan

## Goal

Let tournaments run **team brackets** where named teams compete as units: designate/name teams, show teams (not individual members) on brackets, account for teams in standings, seed teams by cumulative member warrior level, and surface team results in the kingdom/park tournament report.

## Design decisions (locked)

1. **Match model — team as an opaque unit.** The organizer records which *team* won each bracket match, exactly as for individuals today. Members are roster/seeding metadata only; there is **no** member-vs-member sub-bout layer. The existing match engine (resolution, advancement, elimination, best-of bouts) is reused unchanged.
2. **Seed metric — sum of member warrior levels.** A team's seed value is the **sum** of each member's 0–12 warrior level (12 = Sword Knight, 11 = Warlord, 1–10 = Order-of-the-Warrior rank, 0 = unranked). Higher total = better seed (same direction as individual warrior seeding).
3. **Report depth — correctness fix + dedicated team section.** Fix the member fan-out so teams count once, AND add a new "Team Champions" section. Individual Fighters/Awards leaderboard stays individual-only.
4. **Team methods — all except Ironman.** Single elim, double elim, Swiss, and round-robin support teams. Ironman (last-fighter-standing king-of-the-hill) stays individual-only; the Team option is disabled when method = ironman (UI + server).
5. **Roster display — on-demand.** Bracket slots and standings/list rows show team name + seed; member rosters appear via hover tooltip (bracket viz) and an expandable row (lists). Brackets stay uncluttered.
6. **Team park — omitted.** Team rows leave the Park column blank (teams span parks). The report's team section scopes by the tournament's host kingdom/park (how tournaments are already scoped).

## Current state (review findings)

### What already works
- `ork_bracket.participants ENUM('individual','team')` exists and is set from the Add/Edit-Bracket UI (`class.Tournament.php` `AddBracket`/`UpdateBracket`).
- A team is modeled as: one `ork_participant` row (`alias` = team name) + one `ork_participant_teams` row (`team_id` PK, `participant_id` FK, `name`) + N `ork_participant_team_members` rows (`team_id`, `mundane_id`, `tournament_id`; unique on `(team_id, mundane_id)`). Schema from `db-migrations/2026-03-30-participant-teams.sql`.
- **Match engine is fully team-transparent.** `PostMatchResult`, `ResetMatch`, `RecordIronmanWin`, all `generate_*` algorithms, and advancement/elimination operate on opaque `participant_id`s — one team = one participant_id. **Zero changes needed** here.
- Team name already flows through as `Alias` to every display surface (bracket viz, deck cards, bout list, standings) and to `GetMatches` (`Participant1Alias`/`Participant2Alias`).
- Registration: the "Add Team" modal (`Tournametnew_index.tpl` ~5508–5809) collects name + members and POSTs to `bracket/{id}/addparticipant` with a `Members[]` JSON array; `controller.TournamentAjax.php` (~376–382) forwards it; `AddParticipant` team branch (`class.Tournament.php` ~441–467) writes the team + roster rows. PHP UI already branches on `participants==='team'` for meta badges and the Add-Team vs Add-Participant buttons (`Tournametnew_index.tpl` ~2168, 2197, 2224, 6298).

### The keystone bug — member fan-out
`GetParticipants` (`class.Tournament.php` ~478–489) and `GetStandings` (~1148–1168) `LEFT JOIN ork_participant_mundane`, which holds **one row per team member** (written for back-compat). Consequences:
- `GenerateMatches` (~703) gets N rows per team → a 2-team bracket with 3 members each generates a **6-slot** bracket.
- `GetStandings` GROUP BY includes `pm.mundane_id` → **one standings row per member**, each credited with the team's full W/L.
- `bracketData` distinct-participant counter (`controller.Tournament.php` ~239–245) counts a 3-member team as 3.

### Gaps to close
- **Seeding:** `warrior_level` is never written for team participants (the snapshot block in `AddParticipant` ~428–440 is inside the individual `MundaneId` branch only) → team `warrior_level` is always 0. No cumulative-warrior aggregate exists.
- **Roster visibility:** no data endpoint returns a team's member list; `GetParticipants`/`GetStandings`/`bracketData` expose no `Members`/`IsTeam` field. Individual-only decorations (warrior pills, park, player-profile link, award-recommend) render on team rows where they're meaningless.
- **Report:** `GetFighterLeaderboard` (lines ~269, 299, 316) and `GetTournamentParkComparison` (~432) hard-filter `b.participants='individual'` — teams excluded. `GetTournamentList` (~454–472) has **no** filter and fans out: each team member counts as a separate fighter crediting the team's wins; `decoratePlacements` (~132–160) keeps only one arbitrary member for a team podium.

### Dead ends (ignore / optional cleanup)
`ork_team`, `ork_game_team`, `CreateTeam()` (`class.Tournament.php` ~83), and `get_teams()` (`model.Tournament.php` ~55, returns empty) are orphaned — **not** the team mechanism. `ork_glicko2.team_id` is unused.

## Design

### 1. Data layer — `class.Tournament.php`, `controller.Tournament.php`
- **`GetParticipants`:** when `bracket.participants='team'`, return **one row per `participant_id`** (dedup) with `IsTeam=true` and a `Members[]` array — `[{MundaneId, Persona, WarriorLevel, ParkName}]` — fetched via a roster sub-query (`ork_participant_teams → ork_participant_team_members → ork_mundane`). Individual brackets unchanged.
- **`GetStandings`:** for team brackets, GROUP BY `participant_id` only (drop `pm.mundane_id`); one row per team; attach `Members[]`; replace per-member award decoration with the team's cumulative warrior level; do not emit individual `WarriorCount`/pills.
- **`bracketData` (`controller.Tournament.php`):** include `Members`/`IsTeam`; count each team as 1 in the distinct-participant tally.
- `ork_participant_mundane` rows are retained (back-compat) but are no longer the participant-list source for team brackets.

### 2. Seeding — `class.Tournament.php`
- **`AddParticipant` team branch (~441):** after inserting members, call `fetchAwardsForMundanes(memberMundaneIds)`, map each to the 0–12 level (reuse the existing individual mapping), **sum**, and `UPDATE ork_participant SET warrior_level = :sum` on the team's participant row.
- **Roster edits:** re-snapshot the team's `warrior_level` whenever a member is added/removed.
- **`GenerateMatches` warrior-seeding (~712–720):** team brackets sort by the team's stored `warrior_level` (desc, cumulative); individual path (`warrior_seed_rank`) unchanged.

### 3. Front-end — `Tournametnew_index.tpl` (desktop + `.tn-mobile`)
- Bracket viz slots (`buildMatchBox`), deck cards (`quickCardHTML`/`trackCardHTML`/`deckCompactHTML`), bout list (`tnBoutListName`/`rowHTML`): team name (already) + **member roster on hover/expand**; avatar = team initials.
- Standings table (~2502–2576), participant list (~2367–2424), placement lists (~2273–2288): team row = name + seed + **expandable roster**; **omit Park**; suppress warrior pills / player link / award-recommend on team rows.
- Match-results table headers (~2334–2352): "Team 1/2" for team brackets.
- Champion/podium banners: team name (works); omit park.
- Add/Edit-Bracket modal participant `<select>` (~2635, 2735): **disable "Team" when method = ironman**.
- Mobile parity for all of the above (`.tn-mobile` sheets/deck).

### 4. Report — `class.TournamentReport.php`, `Reports_tournaments.tpl`
- **Correctness:** `GetTournamentList` and `decoratePlacements`/`GetBracketPlacements` stop fanning out team members — a team appears once (team name + placement); members are not each credited with the team's W/L.
- Individual Fighters/Awards leaderboard and park comparison keep their `b.participants='individual'` exclusion (unchanged).
- **New "Team Champions" section/tab:** team-bracket standings & championships, scoped by the tournament's host kingdom/park. New `TournamentReport` method(s) (e.g. `GetTeamChampions`/`GetTeamTournamentResults`) + a controller wire-up in `Reports::tournaments()` + a template section/tab.

### 5. Schema / migration
- **None.** `ork_participant_teams`, `ork_participant_team_members`, and `ork_participant.warrior_level` all exist. No backfill (no team data exists yet).

### 6. Validation & scope
- Generate requires ≥2 teams and each team ≥1 member; reject Ironman+team server-side (mirror the UI gate); member dedup already enforced by `uq_team_mundane`.
- Variable team sizes allowed; Σ-of-levels favoring deeper teams is accepted.

## Out of scope (follow-ups)
- `PoolsToBracket` (~1798–1807) team-record copy for pool→team-bracket promotion.
- Removing orphaned `ork_team`/`CreateTeam`/`get_teams` dead code.

## Success criteria
- Create a team bracket, register ≥2 named teams with rosters; generate produces a bracket with **one slot per team** (not per member).
- Bracket viz, deck, bout list show team names; member rosters reachable on demand; no warrior pills/park/player-link on team rows.
- Warrior-seeded team bracket orders teams by Σ member warrior level.
- Standings list one row per team with correct W/L/points/placement.
- Recording results advances/eliminates teams correctly (existing engine).
- Kingdom/park report: team tournaments no longer N-inflate fighters; team results appear in the new Team Champions section; individual leaderboard unaffected.

## Key touchpoints (for the implementation plan)
- `system/lib/ork3/class.Tournament.php` — `GetParticipants` (~474–538), `GetStandings` (~1144–1265), `AddParticipant` team branch (~441–467) + warrior snapshot (~428–440), `GenerateMatches` seeding (~691–766), `fetchAwardsForMundanes` (~547–582), `RemoveParticipant` (~596–601).
- `orkui/controller/controller.Tournament.php` — `bracketData` build (~222–249).
- `orkui/controller/controller.TournamentAjax.php` — `addparticipant` (~352–387), `generate` (~123–135), result endpoints (~510–562).
- `orkui/template/revised-frontend/Tournametnew_index.tpl` — team UI branches (~2168, 2197, 2224, 2635, 2735, 6298), Add-Team modal (~5508–5809), bracket viz (`buildMatchBox` ~6876), standings (~2502–2576), participant list (~2367–2424), deck/bout-list builders (~9478–9692).
- `system/lib/ork3/class.TournamentReport.php` — `GetTournamentList` (~454–519), `decoratePlacements`/`GetBracketPlacements` (~27–160), `GetFighterLeaderboard` (~255–337), `GetTournamentParkComparison` (~403–445); new team-section method(s).
- `orkui/controller/controller.Reports.php` — `tournaments()` (~128–178); `orkui/template/default/Reports_tournaments.tpl` — new team section/tab.
