# Team Competition Support — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Run team brackets where named teams compete as opaque units — designate/name teams, show teams (not members) on brackets, seed teams by summed member warrior levels, list one standings row per team, and surface team results in the kingdom/park report.

**Architecture:** Reuse the existing match engine unchanged (one team = one `participant_id`). The keystone is fixing the member fan-out in the data layer so team brackets return one row per team plus a `Members[]` roster; everything downstream (generation, standings, display, report) then sees teams correctly. No schema changes — `ork_participant_teams`, `ork_participant_team_members`, and `ork_participant.warrior_level` already exist.

**Tech Stack:** PHP 8 (`system/lib/ork3` business layer, `orkui/` MVC), MariaDB (Docker container `ork3-php8-db`), Smarty-ish `.tpl` templates with inline vanilla JS, app at `http://localhost:19080/orkui/`.

**Spec:** `docs/superpowers/specs/2026-05-24-team-competition-design.md`

**Verification model (no unit-test framework in this repo):**
- DB assertions: `docker exec -i ork3-php8-db mariadb -u root -proot ork -e "SQL"`
- Endpoint/JSON shape: load the rendered page and read `TnConfig.bracketData` via the browser, or `curl "http://localhost:19080/orkui/index.php?Route=Tournament/profile/<id>"`.
- UI: Chrome at desktop (~1280) and phone (~390/499) viewport; light + dark.
- Project rules to honor in every task: call `$DB->Clear()` before any raw `Execute`/`DataSet`; DB work lives in `system/lib/ork3/class.Tournament.php` / `class.TournamentReport.php` (model layer is thin pass-through); never stage `class.Authorization.php` or `CLAUDE.md`; PHP/`.tpl` multi-line edits via Python (tab-indentation), not the Edit tool; all new CSS must be dark-mode compatible; no native `title=` tooltips (use `data-tip`).

---

## Phase 0: Test fixtures

A reusable team bracket is needed for every later verification. Build it once.

### Task 0.1: Create a team-bracket test fixture

**Files:**
- Create: `scripts/seed_team_bracket.sql`

- [ ] **Step 1: Identify a usable tournament and confirm team tables exist**

Run:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
"SELECT tournament_id,name,kingdom_id,park_id FROM ork_tournament ORDER BY tournament_id DESC LIMIT 5;
 SHOW TABLES LIKE 'ork_participant_team%';"
```
Expected: at least one tournament row; `ork_participant_teams` and `ork_participant_team_members` listed. Note a `tournament_id` to reuse (call it `:TID`) and its `kingdom_id`/`park_id`.

- [ ] **Step 2: Write the seed script**

Create `scripts/seed_team_bracket.sql` (replace `@TID/@KID/@PID` literals with the values from Step 1 before running). It creates one team bracket and two teams of differing warrior depth so seeding is observable:

```sql
-- Team bracket (single elim, warrior-seeded)
INSERT INTO ork_bracket (tournament_id, style, style_note, method, rings, participants, seeding, status, current_round, is_locked, duration_minutes, best_of)
VALUES (@TID, 'Single Sword', '', 'single', 1, 'team', 'warrior', 'setup', 1, 0, 0, 1);
SET @BID = LAST_INSERT_ID();

-- Helper: each team = 1 participant row + 1 participant_teams row + N member rows.
-- Team A (members = mundane_ids 1,2)
INSERT INTO ork_participant (tournament_id,bracket_id,alias,unit_id,park_id,kingdom_id,participant_number,seed,eliminated,bracket_side,status,warrior_level)
VALUES (@TID,@BID,'Team Alpha',0,0,0,0,0,0,'','active',0);
SET @PA = LAST_INSERT_ID();
INSERT INTO ork_participant_teams (tournament_id,bracket_id,participant_id,name) VALUES (@TID,@BID,@PA,'Team Alpha');
SET @TA = LAST_INSERT_ID();
INSERT INTO ork_participant_team_members (team_id,mundane_id,tournament_id) VALUES (@TA,1,@TID),(@TA,2,@TID);
INSERT INTO ork_participant_mundane (participant_id,mundane_id,tournament_id,bracket_id) VALUES (@PA,1,@TID,@BID),(@PA,2,@TID,@BID);

-- Team B (members = mundane_ids 3,4,5)
INSERT INTO ork_participant (tournament_id,bracket_id,alias,unit_id,park_id,kingdom_id,participant_number,seed,eliminated,bracket_side,status,warrior_level)
VALUES (@TID,@BID,'Team Bravo',0,0,0,0,0,0,'','active',0);
SET @PB = LAST_INSERT_ID();
INSERT INTO ork_participant_teams (tournament_id,bracket_id,participant_id,name) VALUES (@TID,@BID,@PB,'Team Bravo');
SET @TB = LAST_INSERT_ID();
INSERT INTO ork_participant_team_members (team_id,mundane_id,tournament_id) VALUES (@TB,3,@TID),(@TB,4,@TID),(@TB,5,@TID);
INSERT INTO ork_participant_mundane (participant_id,mundane_id,tournament_id,bracket_id) VALUES (@PB,3,@TID,@BID),(@PB,4,@TID,@BID),(@PB,5,@TID,@BID);

SELECT @BID AS team_bracket_id, @PA AS team_alpha_pid, @PB AS team_bravo_pid;
```

- [ ] **Step 3: Run it and record the IDs**

Run: `docker exec -i ork3-php8-db mariadb -u root -proot ork < scripts/seed_team_bracket.sql`
Expected: prints `team_bracket_id`, `team_alpha_pid`, `team_bravo_pid`. Record `:BID`, `:PA`, `:PB` for later tasks. (mundane_ids 1–5 are assumed present; if not, swap for real ones from `SELECT mundane_id FROM ork_mundane LIMIT 5;`.)

- [ ] **Step 4: Commit**

```bash
git add scripts/seed_team_bracket.sql
git commit -m "Test: team-bracket seed fixture for team-competition work"
```

---

## Phase 1: Data layer — kill the member fan-out

This is the keystone. After Phase 1, generation and standings are correct for teams; everything else is display.

### Task 1.1: `GetParticipants` returns one row per team + `Members[]`

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php` — `GetParticipants` (~474–538)

- [ ] **Step 1: Read the current function**

Read `system/lib/ork3/class.Tournament.php` lines 474–582 in full (`GetParticipants` + `fetchAwardsForMundanes`). Note the exact `$DB`/`DataSet` pattern and the returned row keys (`ParticipantId, Alias, MundaneId, Persona, Seed, ...`). Confirm whether the bracket's `participants` value is available here (it loads the bracket or receives `BracketId`); if not, add a lookup: `SELECT participants FROM ork_bracket WHERE bracket_id = :bid`.

- [ ] **Step 2: Capture current (buggy) behavior on the fixture**

Load the team bracket's data and count rows for Team Alpha. In Chrome at `index.php?Route=Tournament/profile/:TID`, run via the JS console tool:
```js
JSON.stringify(TnConfig.bracketData[:BID].Participants
  .filter(p=>p.Alias==='Team Alpha').length)
```
Expected (before fix): `2` (the fan-out bug — one row per member).

- [ ] **Step 3: Implement team dedup + roster attach**

In `GetParticipants`, after the existing query builds the participant rows, branch when the bracket is a team bracket. Add `$DB->Clear();` before the raw query (project rule). Replace the member fan-out with one row per `participant_id` and attach a `Members` array:

```php
// After building $participants from the base query:
if ($bracketParticipants === 'team') {
    // Collapse to one row per participant_id (team), keep first occurrence.
    $byPid = [];
    foreach ($participants as $row) {
        $pid = (int)$row['ParticipantId'];
        if (!isset($byPid[$pid])) {
            $row['IsTeam']   = true;
            $row['MundaneId'] = 0;      // a team has no single mundane
            $row['Members']  = [];
            $byPid[$pid] = $row;
        }
    }
    // Roster: members per team participant.
    $DB->Clear();
    $DB->Add('bid', (int)$bracket_id);
    $roster = $DB->DataSet(
        "SELECT pt.participant_id AS ParticipantId, ptm.mundane_id AS MundaneId,
                mn.persona AS Persona, p.warrior_level AS WarriorLevel,
                mpark.name AS ParkName
         FROM ork_participant_teams pt
         JOIN ork_participant_team_members ptm ON ptm.team_id = pt.team_id
         JOIN ork_mundane mn ON mn.mundane_id = ptm.mundane_id
         LEFT JOIN ork_participant p ON p.participant_id = pt.participant_id
         LEFT JOIN ork_park mpark ON mpark.park_id = mn.park_id
         WHERE pt.bracket_id = :bid"
    );
    foreach ($roster as $m) {
        $pid = (int)$m['ParticipantId'];
        if (isset($byPid[$pid])) {
            $byPid[$pid]['Members'][] = [
                'MundaneId'    => (int)$m['MundaneId'],
                'Persona'      => $m['Persona'],
                'WarriorLevel' => (int)$m['WarriorLevel'],
                'ParkName'     => $m['ParkName'],
            ];
        }
    }
    $participants = array_values($byPid);
}
```
(Adapt `$DB->DataSet`/column-name conventions to match the surrounding code you read in Step 1 — e.g. if the codebase uses `mn.persona` vs a computed display name, mirror it. `ork_park`/`mn.park_id` join names must match the schema; verify with `DESCRIBE ork_mundane;` and `SHOW TABLES LIKE 'ork_park';`.)

- [ ] **Step 4: Verify one row per team + roster present**

Reload the page, then in the console:
```js
var ps = TnConfig.bracketData[:BID].Participants;
JSON.stringify({
  alpha: ps.filter(p=>p.Alias==='Team Alpha').length,        // expect 1
  alphaMembers: (ps.find(p=>p.Alias==='Team Alpha').Members||[]).length, // expect 2
  bravoMembers: (ps.find(p=>p.Alias==='Team Bravo').Members||[]).length  // expect 3
})
```
Expected: `{alpha:1, alphaMembers:2, bravoMembers:3}`. (Note: `bracketData` count depends on Task 1.2 too; if `Members` isn't present yet because the controller hasn't been updated, verify directly with the participants AJAX/DB instead — see Step 5.)

- [ ] **Step 5: Verify individual brackets unchanged**

Pick an existing individual bracket id `:IBID` and confirm its participant count is unchanged vs before (compare `SELECT COUNT(*) FROM ork_participant WHERE bracket_id=:IBID;` to the rendered participant count).

- [ ] **Step 6: Commit**

```bash
git add system/lib/ork3/class.Tournament.php
git commit -m "Enhancement: GetParticipants returns one row per team with Members roster"
```

### Task 1.2: `bracketData` exposes `Members`/`IsTeam` and counts teams as one

**Files:**
- Modify: `orkui/controller/controller.Tournament.php` — `bracketData` build (~222–249)

- [ ] **Step 1: Read the build block**

Read `controller.Tournament.php` lines 222–249. Identify (a) where each bracket's `Participants` array is attached to `bracketData`, and (b) the distinct-participant counter (~239–245) that keys by `MundaneId`.

- [ ] **Step 2: Ensure Members/IsTeam pass through and fix the count**

Since `GetParticipants` (Task 1.1) now returns `Members`/`IsTeam` on team rows, they serialize automatically. Fix the distinct counter so a team counts once: when the bracket's `Participants` is `'team'`, count distinct `ParticipantId` instead of distinct `MundaneId`. Concretely, in the counter loop, branch:

```php
$key = ($bracket['Participants'] ?? 'individual') === 'team'
     ? 'pid:' . $row['ParticipantId']
     : ($row['MundaneId'] > 0 ? 'mid:' . $row['MundaneId'] : 'alias:' . strtolower($row['Alias']));
```
(Match the exact existing counter variable/structure from Step 1.)

- [ ] **Step 3: Verify count**

Reload `Tournament/profile/:TID`. The team bracket's participant/team count badge should read **2 teams** (5 members), not 5 participants. Confirm in console:
```js
TnConfig.bracketData[:BID].Participants.length  // expect 2
```

- [ ] **Step 4: Commit**

```bash
git add orkui/controller/controller.Tournament.php
git commit -m "Enhancement: bracketData counts teams as one and exposes Members/IsTeam"
```

### Task 1.3: `GetStandings` — one row per team + roster + team warrior aggregate

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php` — `GetStandings` (~1144–1265)

- [ ] **Step 1: Read the function**

Read `GetStandings` (~1144–1265). Note the aggregate SQL, the `GROUP BY` (~1167, includes `pm.mundane_id`), the points formula (`wins*3 + ties*1`), the rank/tiebreak (`Points DESC, Losses ASC`), and the award decoration (~1202–1214).

- [ ] **Step 2: Capture buggy behavior**

For the team bracket, count standings rows. Since standings are server-rendered, load `Tournament/profile/:TID`, open the team bracket's Standings, and count rows — before the fix a multi-member team yields one row per member. (Or temporarily `die(json_encode($standings))` in the controller standings fetch and `curl` it.)

- [ ] **Step 3: Implement team dedup + aggregate**

In `GetStandings`, when the bracket is a team bracket: remove `pm.mundane_id` from the `GROUP BY` (group by `p.participant_id, p.alias` only) so each team is one row; do not LEFT JOIN per-member award data; instead attach the team's cumulative warrior level from `p.warrior_level`, and attach the same `Members[]` roster used in Task 1.1 (factor the roster query into a private helper `teamRoster($bracket_id)` returning `[participant_id => Members[]]`, and call it from both `GetParticipants` and `GetStandings` — DRY). Set `MundaneId=0`, omit `WarriorCount`/`WarriorRank`/`IsWarlord`/`IsKnightSword` (or set them absent) so the front-end suppresses individual pills, and add `TeamWarriorLevel => (int)$row['warrior_level']`, `IsTeam => true`, `Members => ...`.

- [ ] **Step 4: Verify one row per team**

Reload Standings for the team bracket. Expect exactly 2 rows (Team Alpha, Team Bravo), each with correct W/L/points after you record a result in Phase-3 testing. For now assert row count = 2 and that each row carries `Members`.

- [ ] **Step 5: Verify individual standings unchanged**

Open an individual bracket's Standings; confirm rows, points, and warrior pills are identical to before.

- [ ] **Step 6: Commit**

```bash
git add system/lib/ork3/class.Tournament.php
git commit -m "Enhancement: GetStandings returns one row per team with roster and cumulative warrior level"
```

---

## Phase 2: Seeding by cumulative warrior level

### Task 2.1: Snapshot a team's summed warrior level at registration

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php` — `AddParticipant` team branch (~441–467), reuse `fetchAwardsForMundanes` (~547–582) and the individual 0–12 mapping (~430–436)

- [ ] **Step 1: Read both branches**

Read `AddParticipant` ~419–467. Note the individual branch's level mapping (Sword Knight=12, Warlord=11, OotW rank 1–10, else 0) at ~430–436 and the `UPDATE ork_participant SET warrior_level` at ~437–440. Note the team branch inserts members at ~441–467 but never sets `warrior_level`.

- [ ] **Step 2: Extract the level mapping into a helper (DRY)**

Add a private method that maps one mundane's award row to the 0–12 level, so both branches share it:
```php
private function warriorLevelFromAwards(array $a): int {
    if (!empty($a['is_knight_sword'])) return 12;
    if (!empty($a['is_warlord']))      return 11;
    return min(10, (int)($a['warrior_rank'] ?? 0));
}
```
Refactor the individual branch (~430–436) to call it (behavior-preserving).

- [ ] **Step 3: Sum member levels in the team branch**

At the end of the team branch (after all members are inserted, with `$teamParticipantId` known), compute the sum and persist it:
```php
$memberMids = array_map(fn($m) => (int)$m['MundaneId'], $request['Members']);
$awards = $this->fetchAwardsForMundanes($memberMids); // keyed by mundane_id
$sum = 0;
foreach ($memberMids as $mid) {
    $sum += isset($awards[$mid]) ? $this->warriorLevelFromAwards($awards[$mid]) : 0;
}
$DB->Clear();
$DB->Add('lvl', $sum);
$DB->Add('pid', (int)$teamParticipantId);
$DB->Execute("UPDATE ork_participant SET warrior_level = :lvl WHERE participant_id = :pid");
```
(Confirm `fetchAwardsForMundanes` returns a map keyed by `mundane_id`; if it returns a list, index it first. Match the actual `$teamParticipantId` variable name from the surrounding code.)

- [ ] **Step 4: Verify the snapshot**

Register a team via the Add-Team UI on a team bracket whose members have known awards (or set the fixture members to known mundanes). Then:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
"SELECT p.alias, p.warrior_level FROM ork_participant p WHERE p.bracket_id=:BID AND p.alias LIKE 'Team%';"
```
Expected: `warrior_level` = sum of members' levels (not 0). For the SQL-seeded fixture (warrior_level was inserted 0), set the members to real awarded mundanes or recompute via a one-off UPDATE to validate the path.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.Tournament.php
git commit -m "Enhancement: snapshot team warrior level as sum of member levels at registration"
```

### Task 2.2: Warrior-seeding orders teams by cumulative level

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php` — `GenerateMatches` seeding dispatch (~712–724)

- [ ] **Step 1: Read the seeding dispatch**

Read `GenerateMatches` ~703–755. Note the `warrior` branch (~716–720) sorts by `warrior_seed_rank($row)`.

- [ ] **Step 2: Branch team seeding to the stored level**

In the `warrior` seeding branch, when the bracket is a team bracket, sort by the team's `warrior_level` (already on the participant row from Task 2.1), descending:
```php
if ($seeding === 'warrior') {
    if ($bracketParticipants === 'team') {
        usort($participants, fn($a,$b) => (int)$b['WarriorLevel'] <=> (int)$a['WarriorLevel']);
    } else {
        usort($participants, fn($a,$b) => $this->warrior_seed_rank($b) <=> $this->warrior_seed_rank($a));
    }
}
```
(`WarriorLevel` must be present on team participant rows — ensure `GetParticipants` includes `p.warrior_level AS WarriorLevel` for team brackets; add it in Task 1.1's select if missing. Match the existing comparator direction so higher = seed #1, consistent with individual warrior seeding.)

- [ ] **Step 3: Verify seed order**

Set Team Bravo's `warrior_level` higher than Team Alpha's (`UPDATE ork_participant SET warrior_level=10 WHERE participant_id=:PB; UPDATE ... =4 WHERE participant_id=:PA;`), then regenerate the team bracket via the UI (Run Tournament → Regenerate) and check seeds:
```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork -e \
"SELECT alias,seed FROM ork_participant WHERE bracket_id=:BID ORDER BY seed;"
```
Expected: Team Bravo seed 1, Team Alpha seed 2.

- [ ] **Step 4: Commit**

```bash
git add system/lib/ork3/class.Tournament.php
git commit -m "Enhancement: warrior seeding orders teams by cumulative member warrior level"
```

---

## Phase 3: Front-end — rosters, team rows, Ironman gate

After Phase 1–2 the data is correct; these tasks make the UI team-aware. Each must work in light + dark and on `.tn-mobile`.

### Task 3.1: Disable "Team" participant type for Ironman

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl` — Add/Edit-Bracket modals (~2635, 2735) + their method/participant `<select>` JS
- Modify: `system/lib/ork3/class.Tournament.php` — `AddBracket`/`UpdateBracket` (~276, 317)

- [ ] **Step 1: UI gate**

In the Add-Bracket and Edit-Bracket modal JS, add a change handler on the method select: when method === `'ironman'`, force the participant select to `individual` and disable the `team` `<option>` (add `disabled`); re-enable when method changes away. Read the modal markup at ~2635 and ~2735 to find the select ids (`tn-addbracket-participants`, `tn-editbracket-participants`, and the method selects).

- [ ] **Step 2: Server gate**

In `AddBracket`/`UpdateBracket`, reject the invalid combo:
```php
if (($request['Method'] ?? '') === 'ironman' && ($request['Participants'] ?? '') === 'team') {
    return ['Status' => 1, 'Error' => 'Team mode is not supported for Ironman brackets.'];
}
```
(Match the surrounding error-return shape.)

- [ ] **Step 3: Verify**

In the UI, choose Ironman → the Team option is disabled. Via direct AJAX (`curl` the `addbracket` action with `Method=ironman&Participants=team`) → returns the error, no bracket created (`SELECT COUNT(*)` unchanged).

- [ ] **Step 4: Commit**

```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl system/lib/ork3/class.Tournament.php
git commit -m "Enhancement: block Team mode for Ironman brackets (UI + server)"
```

### Task 3.2: Team rows in standings & participant lists (omit park, suppress pills, expandable roster)

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl` — per-bracket standings table (~2502–2576), placement list (~2273–2288), global participants tab (~2367–2424), and the `tnParticipantPills` usage

- [ ] **Step 1: Read the standings/list rendering**

Read ~2273–2319, ~2367–2424, ~2502–2576. Note `tnParticipantPills($row)`, the Park cell, the MundaneId-gated player-profile link, and the award-recommend button gate.

- [ ] **Step 2: Branch team rows**

For rows where `($row['IsTeam'] ?? false)` (or the bracket is a team bracket): render the team name; leave the Park cell blank; skip `tnParticipantPills`; skip the player-profile link and award-recommend (these are already gated on `MundaneId>0`, which is `0` for teams — verify they hide correctly); and render a small expand affordance (`▸ N members`) that toggles a roster sub-row listing each `Members[].Persona` (+ each member's `WarriorLevel`). Use the existing CSS-tooltip/`data-tip` pattern for any hover hint — no native `title=`. Show the team's cumulative warrior level (`TeamWarriorLevel`) as a single pill where individual pills would have been.

- [ ] **Step 3: Match-results table headers**

In the match-results table (~2334–2352), when the bracket is a team bracket, render the column headers "Team 1"/"Team 2" instead of "Participant 1/2".

- [ ] **Step 4: Verify (desktop, light + dark)**

On the team bracket: Standings shows 2 team rows, blank park, no warrior pills, a cumulative-warrior pill, an expandable roster that lists the right members; participants tab shows teams once with a Members affordance and no broken player link; match table headers say "Team 1/2". Repeat in dark mode. Screenshot each.

- [ ] **Step 5: Commit**

```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: team-aware standings/participant rows with expandable rosters"
```

### Task 3.3: Member roster on bracket-viz slots (hover tooltip)

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl` — `buildMatchBox` (~6876–7143), champion/podium (~6519–6538)

- [ ] **Step 1: Read `buildMatchBox`**

Read ~6876–6975. Note where the slot name (`info.p.Alias`), seed, avatar initials, and the existing hover tooltip (~6974) are built, and that `pMap[id]` now carries `Members` for teams.

- [ ] **Step 2: Add roster tooltip for team slots**

When `pMap[id].IsTeam` (or `Members` present), keep the team name in the slot and extend the slot's hover tooltip to list member personas (e.g. "Team Alpha — Ada, Ben"). Avatar initials from the team name already work. For the champion/podium, show the team name (already) and omit park (skip the `ParkName` line when `IsTeam`).

- [ ] **Step 3: Verify**

Generate + open the team bracket viz; hover a team slot → tooltip lists members. Complete the bracket; champion banner shows the team name, no park line.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: bracket-viz team slots show member roster on hover; podium omits park"
```

### Task 3.4: Mobile deck & bout list roster parity

**Files:**
- Modify: `orkui/template/revised-frontend/Tournametnew_index.tpl` — `quickCardHTML`/`trackCardHTML`/`deckCompactHTML` (~9626–9692), `tnBoutListName`/`rowHTML` (~9478–9591)

- [ ] **Step 1: Read the deck/bout builders**

Read ~9478–9692. Team name already renders as the participant name; seed shows the team seed.

- [ ] **Step 2: Add roster affordance**

On the mobile deck cards and bout-list rows, when the participant is a team, append a small "N" member-count chip with a `data-tip` listing members (CSS tooltip, not `title=`). Do not clutter the primary line — the team name stays the headline.

- [ ] **Step 3: Verify (phone viewport, light + dark)**

At 390/499 width, open Run Tournament on the team bracket: deck cards and bout list show team names + member-count chip; tooltip lists members. Dark mode too. Screenshot.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/revised-frontend/Tournametnew_index.tpl
git commit -m "Enhancement: mobile deck/bout-list team member-count chip with roster tip"
```

---

## Phase 4: Tournament report — correctness + Team Champions

### Task 4.1: Stop team-member fan-out in the report

**Files:**
- Modify: `system/lib/ork3/class.TournamentReport.php` — `GetTournamentList` (~454–519), `decoratePlacements`/`GetBracketPlacements` (~27–160), `GetTournamentProgramStats` participant count (~223)

- [ ] **Step 1: Read the report queries**

Read `GetTournamentList` (~454–519), `decoratePlacements` (~132–160), and the program-stats trend/unique-participant query (~205–223).

- [ ] **Step 2: Fix the fan-out**

In `GetTournamentList`'s per-fighter W/L query, exclude team brackets from the *individual fighter* tally (add `AND b.participants='individual'` to that join, mirroring `GetFighterLeaderboard`) so team members are not each credited with the team's W/L. In `decoratePlacements`, when the placement's bracket is a team bracket, do not LEFT JOIN per-member mundane (which keeps an arbitrary member) — return the team `alias` with `MundaneId=0` and a `Members[]` roster. Confirm `GetTournamentProgramStats` unique-participant count treats team members as the people they are (members *did* participate — leave counting humans, but ensure a team's match wins aren't attributed to members per Step 2's W/L fix).

- [ ] **Step 3: Verify**

With a completed team bracket, open the kingdom/park report (`Reports/tournaments&KingdomId=:KID`). The tournament card's TopParticipants must NOT list each team member crediting the team's wins; the team's placement appears once. Compare counts before/after with a DB query of distinct mundanes vs team rows.

- [ ] **Step 4: Commit**

```bash
git add system/lib/ork3/class.TournamentReport.php
git commit -m "Bugfix: tournament report no longer fans out team members as individual fighters"
```

### Task 4.2: Team Champions report section

**Files:**
- Create method: `system/lib/ork3/class.TournamentReport.php` — `GetTeamChampions($scope)`
- Modify: `orkui/model/model.Reports.php` (thin pass-through), `orkui/controller/controller.Reports.php` `tournaments()` (~128–178)
- Modify: `orkui/template/default/Reports_tournaments.tpl` (new section/tab)

- [ ] **Step 1: Define the data**

`GetTeamChampions($scope)` returns, for completed/finalized **team** brackets in scope (use `scopeWhere()` for kingdom/park on `ork_tournament`): per team bracket → `{TournamentName, Style, Champion: {TeamName, Members[]}, RunnerUp: {...}}` using the same placement logic as `GetBracketPlacements` but team-aware (Task 4.1). Filter `b.participants='team' AND b.status IN ('complete','finalized')`.

- [ ] **Step 2: Implement the method**

Write `GetTeamChampions` mirroring the structure of `GetBracketPlacements`/`GetTournamentList` (read those for the `$DB`/scope pattern). Add `$DB->Clear();` before the raw query. Return `['Status'=>0,'Detail'=>[...]]`.

- [ ] **Step 3: Wire model + controller**

Add `team_champions($scope)` pass-through in `model.Reports.php` (mirror the other tournament_* methods). In `controller.Reports.php::tournaments()`, call it and assign to `$this->data['TeamChampions']`.

- [ ] **Step 4: Template section**

In `Reports_tournaments.tpl`, add a "Team Champions" tab/section (follow the existing tab pattern, e.g. the Parks tab at ~104/220). Render each team bracket's champion (team name + roster) and runner-up. Dark-mode compatible; no `title=` tooltips.

- [ ] **Step 5: Verify**

Complete the team bracket, open `Reports/tournaments&KingdomId=:KID` → the Team Champions section lists the bracket with Team Bravo/Alpha as champion/runner-up and rosters. Confirm the individual Fighters/Awards tabs are unchanged (team data absent there).

- [ ] **Step 6: Commit**

```bash
git add system/lib/ork3/class.TournamentReport.php orkui/model/model.Reports.php orkui/controller/controller.Reports.php orkui/template/default/Reports_tournaments.tpl
git commit -m "Enhancement: Team Champions section in the kingdom/park tournament report"
```

---

## Phase 5: End-to-end validation & guards

### Task 5.1: Generation guards for team brackets

**Files:**
- Modify: `system/lib/ork3/class.Tournament.php` — `GenerateMatches` precondition (~706)

- [ ] **Step 1: Read the min-participant check**

Read `GenerateMatches` ~703–710. Note the existing minimum-count guard.

- [ ] **Step 2: Add team-aware guards**

After the Task 1.1 dedup, `count($participants)` is the team count, so the existing `>= 2` check is correct. Add a guard that each team has ≥1 member (skip/flag empty teams):
```php
if ($bracketParticipants === 'team') {
    foreach ($participants as $t) {
        if (empty($t['Members'])) {
            return ['Status'=>1,'Error'=>'Every team must have at least one member before generating.'];
        }
    }
}
```

- [ ] **Step 3: Verify**

Create a team with zero members (direct insert of a `ork_participant`+`ork_participant_teams` with no members), attempt generate → error; remove it, generate succeeds with 2 valid teams → bracket has 1 match (2 teams, single elim).

- [ ] **Step 4: Commit**

```bash
git add system/lib/ork3/class.Tournament.php
git commit -m "Enhancement: generation guard requires every team to have a member"
```

### Task 5.2: Full end-to-end run (the success criteria)

**Files:** none (verification only)

- [ ] **Step 1: Build a fresh team bracket through the UI**

On `:TID`, create a new team bracket (single elim, warrior seeding), and register 4 named teams with real member rosters via the Add-Team modal (members with varied awards).

- [ ] **Step 2: Generate and assert seed order**

Generate; assert seeds order by Σ member warrior level (DB query as in Task 2.2).

- [ ] **Step 3: Run the bracket**

Record results through the rounds (desktop + once on a phone viewport). Assert advancement/elimination is by team (DB: winner team's `eliminated=0`, loser `eliminated=1`; next match holds the winner's `participant_id`).

- [ ] **Step 4: Standings + report**

Standings: one row per team, correct W/L/points/placement. Open the kingdom report: Team Champions section shows the winner; individual Fighters tab unaffected; no member fan-out in the tournament card.

- [ ] **Step 5: Dark mode + mobile sweep**

Walk standings, bracket viz, deck, bout list, and the report section in dark mode and at phone width; confirm no broken layouts, no individual-only artifacts on team rows.

- [ ] **Step 6: No-console-errors check**

In Chrome, confirm zero console errors across the team-bracket run.

---

## Out of scope (tracked, not implemented here)
- `PoolsToBracket` (~1798–1807) team-record copy for pool→team-bracket promotion.
- Removing orphaned `ork_team` / `CreateTeam` / `get_teams` dead code.

## Self-review notes (coverage vs spec)
- Spec §1 data layer → Tasks 1.1–1.3. §2 seeding → Tasks 2.1–2.2. §3 front-end (rosters, team rows, ironman gate, mobile) → Tasks 3.1–3.4. §4 report (correctness + Team Champions) → Tasks 4.1–4.2. §5 schema → none needed (stated). §6 validation → Tasks 3.1 (ironman), 5.1 (min/empty-team), 5.2 (e2e). Success criteria → Task 5.2. Out-of-scope items carried forward verbatim.
