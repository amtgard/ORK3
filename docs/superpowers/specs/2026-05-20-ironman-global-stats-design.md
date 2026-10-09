# Ironman v2 — Global Wins & Streaks (Real-Time) — Design

**Date:** 2026-05-20
**Module:** Tournament / Ironman bracket type

## Goal
Track two per-fighter metrics that are **global across rings** and displayed identically on every ring: total **wins** and current **streak** (with **max streak** kept for ranking). Ironman is extremely fast-paced (multiple rings, constant fights for ~15 min), so recording a win must be O(1) on the server and a targeted DOM patch on the client — no full replay, no full re-render.

## Confirmed semantics
- **Wins** = total wins across all rings. Same number on every ring's card.
- **Current streak** = consecutive wins on the fighter's current hill; resets to 0 when dethroned. Shown on every ring's card via `fa-link` + number, when ≥ 2.
- **Max streak** = high-water mark; standings rank by Wins DESC, then Max Streak DESC.
- A fighter changes rings only **after being defeated** (they lost in ring A, got in line for ring B). In king-of-the-hill, every defeat is recorded as the **opponent's win**, so streaks reset correctly with no loser-recording and no cross-ring carry.

## Core rule — on recorded win of fighter **W** in ring **R**
1. `K` = previous king of ring R (last recorded winner in R).
2. If `K` exists and `K ≠ W` → `K` was defeated → `K.im_current_streak = 0`.
3. `W.im_wins += 1`; `W.im_current_streak += 1`; `W.im_max_streak = GREATEST(im_max_streak, im_current_streak+1)`.
4. `W` is now king of R (implied by being the latest winner in R).

## Data model
Add to `ork_participant` (idempotent migration):
- `im_wins INT NOT NULL DEFAULT 0`
- `im_current_streak INT NOT NULL DEFAULT 0`
- `im_max_streak INT NOT NULL DEFAULT 0`

Add index `idx_match_bracket_ring_order (bracket_id, ring_number, \`order\`)` so "previous king of ring R" is a single indexed lookup.

King-per-ring is **derived** (latest winner in the ring), not stored.

## Server (`system/lib/ork3/class.Tournament.php`)
- **`RecordIronmanWin`** (inside the existing `START TRANSACTION … FOR UPDATE` envelope):
  1. Read prevKing of ring R: `SELECT participant_1_id FROM match WHERE bracket_id=? AND ring_number=? AND result IS NOT NULL ORDER BY \`order\` DESC LIMIT 1` (before insert).
  2. Insert the match (as today).
  3. Update the winner's three counters (single UPDATE; RHS uses pre-update values so `im_max_streak = GREATEST(im_max_streak, im_current_streak+1)` is correct).
  4. If `prevKing && prevKing != W`: zero prevKing's `im_current_streak`.
  5. Lock/UPDATE participant rows in **ascending participant_id order** to avoid deadlocks between concurrent ring recorders.
  6. Return `{ FightNum, WinnerId, WinnerWins, WinnerStreak, DethronedId|0, RingNumber }`.
- **`GetStandings`** ironman branch: replace the full match replay with reads of `p.im_wins`, `p.im_current_streak`, `p.im_max_streak` (added to the SELECT). Rank by Wins DESC, MaxStreak DESC. Non-ironman paths unchanged.
- **`clear_bracket_matches`**: after deleting matches, reset `im_wins/current_streak/max_streak = 0` for all participants in the bracket.
- **`recomputeIronmanStats($bracket_id)`** (private): authoritative replay that recomputes the three columns using the core rule. Used for backfill of pre-existing data and as a reset/repair path. NOT on the hot path.

## Backfill
Ironman is in open playtesting (data is disposable), and only dev bracket 15 has data. The migration adds schema; `recomputeIronmanStats` is run once for existing ironman brackets so current data displays correctly. New data is maintained incrementally.

## Client (`orkui/template/revised-frontend/Tournametnew_index.tpl`)
- **Card render**: `wins` comes from the global standings map (not per-ring `rStats`); add a `fa-link` streak badge when the fighter's current streak ≥ 2. Same values on every ring. Each card gets `data-pid`; each ring container gets `data-ring`. King crown per ring stays (derived).
- **Win handlers** (quick-entry and card-click): replace `tnRefreshAndRender(bracketId)` with a **targeted patch** using the POST response:
  - update the winner's wins + streak badge on cards across **all** rings;
  - update the dethroned fighter's streak badge (→ removed) across all rings;
  - move the crown to the winner in ring R, demote the previous king;
  - bump the ring's "FIGHT #N" counter;
  - prepend one row to that ring's fight history.
  - No refetch, no full rebuild, no focus loss.
- Keep `tnRefreshAndRender` available as a fallback (e.g., on patch error or manual refresh).

## Concurrency
Two recorders posting to two rings usually touch disjoint participants. The `FOR UPDATE` envelope plus ascending-id lock ordering prevents duplicate fight numbers and deadlocks. `GetStandings` reads are cheap and lock-free.

## Out of scope / non-goals
- Recording losers explicitly.
- Per-ring streaks (replaced by global).
- Undo of a single ironman fight (only `clearmatches` restart exists).

## Verification
- Backfill bracket 15 → cards show global wins + correct streaks on both rings.
- Record a win via quick-entry → only affected cards/badges/counter/history update; input keeps focus.
- Dethrone a king → their streak badge clears on both rings; new king's crown moves; streak increments.
- `php -l` clean; standings tab matches card numbers; page renders 200.
