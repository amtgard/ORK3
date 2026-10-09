# Pools to Brackets — Design

**Date:** 2026-05-20
**Module:** Tournament

## Goal
From an Ironman bracket's Standings view, let an organizer spin up a playoff: pick Single or Double Elimination, take the top X of the Ironman standings, and create a new bracket of that type seeded by a chosen method.

## UX
- A **"Pools to Brackets"** button renders at the top of each **Ironman** standings section (server-rendered, gated on `$canManage && $_stIsIronman` — so it only appears for Ironman brackets, no JS toggle needed).
- It opens a modal (`tn-poolstobrackets-overlay`) following the existing modal pattern:
  - **Bracket type:** Single Elimination / Double Elimination.
  - **Top X:** integer (default `min(8, poolSize)`, min 2, max = pool size).
  - **Seed method:** By Ironman Standing (default) / Orders of the Warrior / Performance Score / Random.
- Submit → POST → on success, reload to the Brackets tab where the new bracket appears.

## Selection & seeding
- "Top X" is always taken by **Ironman standing rank** (GetStandings ranks Ironman by Wins → Max Streak).
- Seed method maps to the new bracket's `seeding` column:
  - By Ironman Standing → `manual`, with `seed = 1..X` assigned in rank order (1 vs N pairings follow standing).
  - Orders of the Warrior → `warrior`.
  - Performance Score → `glicko2`.
  - Random → `random`.
- The new bracket **inherits the Ironman's weapon style**, with `style_note = "Top X from Ironman"`.
- Note: `GenerateMatches` only truly sorts `manual` (by seed) and `warrior` (by award rank); `glicko2`/`random` shuffle. So "Performance Score" currently behaves like Random in placement — consistent with the existing Add Bracket modal, which has the same limitation.

## Server — `PoolsToBracket($request)` (class.Tournament.php)
Request: Token, TournamentId, BracketId (source Ironman), Method (`single`|`double`), TopX, SeedMethod (`standing`|`warrior`|`glicko2`|`random`).
1. `check_auth`; validate ids, method, TopX ≥ 2.
2. Load source bracket; must be `method='ironman'` in this tournament; capture its `style`.
3. `GetStandings(source)` → ranked rows; collect participant ids in rank order; `array_slice` top X (clamp to available). Single needs ≥ 2, Double ≥ 3.
4. Create the new bracket by calling the existing `AddBracket` (empty, no `CopyOfId`) with inherited style, chosen method, `seeding`, individual participants.
5. In a transaction, copy each selected participant into the new bracket (alias, unit/park/kingdom, participant_number, and `seed = i` for manual) plus their `participant_mundane` link (so warrior/award seeding works).
6. Call existing `GenerateMatches(new bracket)` (applies the seeding sort + builds single/double pairings).
7. Return new `bracket_id`.

Reuses `AddBracket` and `GenerateMatches` (both already transactional and tested) rather than re-implementing bracket creation or match generation.

## Controller / model
- `controller.TournamentAjax.php`: new bracket-route action `poolstobrackets` (`bracket/{sourceBracketId}/poolstobrackets`) reading Method/TopX/SeedMethod/TournamentId from POST; returns `{status:0, bracketId}` or modelError.
- `model.Tournament.php`: `pools_to_bracket()` pass-through.

## Client (Tournametnew_index.tpl)
- Button in each Ironman standings section.
- Modal markup + `tnOpenPoolsToBracketsModal(srcBid, poolSize)` (sets source id, defaults/max for Top X) + submit handler (POST, reload to Brackets tab on success) + close/escape/backdrop handlers, mirroring the Configure Standings modal. Dark-mode inherited from existing modal classes; the seed/type selects reuse existing form-field styles.

## Verification
- `php -l` clean; `node --check` the modal JS.
- Create a single and a double playoff from bracket 15's standings; confirm top-X participants copied with mundane links, seeds assigned by rank for "standing", matches generated, page reloads to the new bracket.

## Out of scope
- Real glicko2/performance seeding sort (pre-existing gap).
- Re-running/merging into an existing playoff bracket (always creates a new one).
