# Round-Robin First-Place Tiebreaker

**Date:** 2026-05-06
**Status:** Draft — awaiting user approval
**Module:** Tournament (Round Robin bracket method)
**Related:** Existing single-elim 3rd-place tiebreaker (pattern to mirror)

## Goal

When all regular Round Robin matches in a bracket are complete and 2+ players are tied for 1st place, give the tournament organizer the choice to either run a tiebreaker round (a mini round-robin among the tied players) or accept joint winners. Tiebreakers may cascade — the tiebreaker can itself end in a tie, prompting the same choice again. Joint winners compress: three tied at 1st means the next player ranks 4th.

## Non-Goals

- No tiebreaker support for placements below 1st (3rd-place ties etc. continue to share standings rank with no resolution flow).
- No tiebreaker for non-RR bracket methods (single-elim has its own 3rd-place flow; double-elim / Swiss / score / ironman are out of scope).
- No "undo decline" — once an organizer chooses joint winners, the choice is final via the standard UI. Undoing requires manual DB intervention. (YAGNI.)
- No automatic re-seeding of the parent tournament when a bracket re-finalizes.

## User Flow

1. **Detection.** All regular RR matches in a bracket are complete (every pair has played all their bouts). The standings sort places 2+ players at rank 1 by primary key (points). The bracket transitions to status `complete`.
2. **Banner.** A confirmation banner appears above the round nav (re-using `.tn-gf-confirm-banner`):
   > 🏅 **3 players are tied for 1st place.** Add a tiebreaker round?
   > [Yes, Run Tiebreaker]   [No, Joint Winners]
3. **Choice → "Yes, Run Tiebreaker":** A new round of matches is generated — every pair of tied players plays one match using the parent bracket's Best-of-N config. Bracket status returns to `active`. Banner is replaced by the tiebreaker round in the round nav (labeled "TB1").
4. **Choice → "No, Joint Winners":** A sticky flag is persisted on the bracket. Standings are finalized with all tied players at rank 1, next player at rank N+1. Bracket transitions to `finalized`.
5. **Cascade.** When a tiebreaker round completes, the system re-evaluates ties at rank 1 *within the tied group*. If a unique winner emerged, finalize. If 2+ are still tied, banner re-appears for round 2 ("TB2"). Same Yes/No choice.
6. **Final state.** Bracket reaches `finalized` either when the organizer declines a tiebreaker (joint winners) or a tiebreaker round produces a unique winner.

## Data Model

### `match` table (existing — no schema change)

Tiebreaker matches are stored as additional rows with:

- `bracket_side = 'tiebreaker'` — new value alongside existing `winners`, `losers`, `grand-final`, `tiebreaker-3rd`.
- `round_num = max(regular_round_num) + tb_index` — tiebreaker rounds start one above the highest regular round. Round 1 of the first tiebreaker is `max+1`, round 1 of a second cascade is `max+2`, etc.
- `bracket_id` — same as parent.
- `best_of_n` — inherited from the parent bracket's configuration at creation time (snapshot, so a parent bracket config edit doesn't retroactively change tiebreaker scoring).
- All other fields (participant_a_id, participant_b_id, status, result) follow existing match conventions.

### `bracket` table (one new column)

```sql
ALTER TABLE ork_bracket ADD COLUMN tiebreaker_declined TINYINT(1) NOT NULL DEFAULT 0;
```

**Why a column instead of inferring from match state:** The decline state needs to be sticky across page loads. Without it, declining and refreshing would re-show the banner. A column is the cleanest representation of an organizer decision that has no corresponding match rows.

**Why no `tiebreaker_round_num` column:** Round number is derivable from `match.round_num` already. Adding a separate counter is duplicate state.

### Migration file

`db-migrations/2026-05-06-rr-tiebreaker.sql`:

```sql
ALTER TABLE ork_bracket
  ADD COLUMN tiebreaker_declined TINYINT(1) NOT NULL DEFAULT 0;
```

## Backend

### `system/lib/ork3/class.Tournament.php` — new methods

**`CreateRoundRobinTiebreaker($request)`**

Input: `bracket_id`. Authorization: requires `canManage` on the parent tournament (mirrors `CreateTiebreakerMatch`).

Logic:
1. Verify bracket method is `round-robin`.
2. Verify all regular RR matches in the bracket have a result. If any are unresolved, return InvalidParameter.
3. Verify `tiebreaker_declined = 0`.
4. Determine the tied-at-rank-1 player set via the standings function (see below). If fewer than 2 players are tied, return InvalidParameter.
5. Verify no in-progress tiebreaker round exists (no tiebreaker matches with unresolved status).
6. Compute next tiebreaker round_num: `MAX(round_num) + 1` across the bracket.
7. Insert one match row per pair of tied players with `bracket_side='tiebreaker'`, the computed `round_num`, parent bracket's `best_of_n`, status `pending`.
8. Set bracket status back to `active` (it was `complete`).
9. Return new round_num and match list.

**`DeclineRoundRobinTiebreaker($request)`**

Input: `bracket_id`. Authorization: same as above.

Logic:
1. Verify bracket method is `round-robin`.
2. Verify standings show a tie at rank 1 (else there's nothing to decline).
3. Set `tiebreaker_declined = 1` on the bracket.
4. Transition bracket status to `finalized`.
5. Return success.

**`GetRoundRobinTopTied($bracket_id)` (private helper)**

Returns the array of participant IDs currently tied at rank 1 in this bracket, considering both regular and any completed tiebreaker matches. Used by detection, create, and standings logic.

### Standings logic update

The function that produces RR standings (currently in `class.Tournament.php` / `class.Report.php`) must be updated to:

1. **Base computation** from regular matches only (existing behavior preserved for the matrix view).
2. **Tiebreaker overlay**: If completed tiebreaker matches exist for this bracket, re-rank *only the tied-at-top group* using mini-RR records over those tiebreaker matches. Players outside the tied group are unaffected.
3. **Cascade resolution**: If multiple tiebreaker rounds exist, apply them in `round_num` order. Each round only re-ranks players still tied after the previous round.
4. **Joint winners**: If `tiebreaker_declined = 1` and a tie remains at rank 1, all tied players get rank 1; the next player's rank is `1 + tied_count`.

The standings function returns the same shape as today plus a `Rank` field that reflects all of the above. The frontend renders ranks directly without further branching.

### `orkui/controller/controller.TournamentAjax.php` — new actions

Two new action keywords inside the existing `tournament` route handler:

- `roundrobintiebreaker` (POST, body `bracket_id={id}`) → calls `CreateRoundRobinTiebreaker`
- `roundrobintiebreakerdecline` (POST, body `bracket_id={id}`) → calls `DeclineRoundRobinTiebreaker`

Pattern mirrors the existing `tiebreakerfor3rd` action exactly.

### `orkui/model/model.Tournament.php` — pass-throughs

```php
function create_round_robin_tiebreaker($request) {
    return $this->Tournament->CreateRoundRobinTiebreaker($request);
}
function decline_round_robin_tiebreaker($request) {
    return $this->Tournament->DeclineRoundRobinTiebreaker($request);
}
```

## Frontend

All changes inside `orkui/template/revised-frontend/Tournametnew_index.tpl`. No external JS or CSS file additions.

### Banner

Re-use `.tn-gf-confirm-banner` (same DOM/CSS as the 3rd-place tiebreaker). The renderer for the bracket viz, when in RR mode, performs this check after rendering rounds:

```
if (bracketStatus === 'complete'
    && !bracket.TiebreakerDeclined
    && topTiedCount >= 2
    && !hasInProgressTiebreaker) {
    showBanner();
}
```

Banner copy:
> *N players are tied for 1st place. Add a tiebreaker round?*
> [Yes, Run Tiebreaker] [No, Joint Winners]

Yes → POST to `roundrobintiebreaker`, refresh viz.
No → POST to `roundrobintiebreakerdecline`, refresh viz.

### Tiebreaker round in the rounds nav

Tiebreaker rounds are appended to the existing `.tn-bv-round-nav` button list with label `TB1`, `TB2`, etc. (derived from order). When clicked, the round body shows:

- A section header using `.tn-bv-section-hdr` with new modifier class `.tn-bv-section-hdr.tiebreaker-rr` (amber accent — re-uses the existing `tiebreaker-3rd` color tokens for the modifier).
- Heading text: **"Tiebreaker Round N — Mini Round-Robin"** plus a subline listing the participating players.
- Match cards rendered via the same `.tn-bv-match` component as regular RR matches.

### Standings table

No structural changes. The rank cells render whatever `Rank` value the standings function returns. Joint winners naturally show as multiple medal icons at rank 1 with the next player at rank N+1.

### Cross-table matrix

**Untouched.** The matrix represents the regular RR cross-table only. Tiebreaker matches are deliberately excluded.

### Compact tiebreaker results panel

A small panel below the matrix view (visible only when tiebreaker matches exist):

```
Tiebreaker Round 1
  Conrad def. Franciy (3-1)
  Conrad def. Sir Apocalypse (3-0)
  Sir Apocalypse def. Franciy (3-2)
```

If a tiebreaker round itself ended in a tie and the organizer declined further tiebreakers, append a small italic note: *"Joint winners — declined further tiebreakers."*

## Dark Mode

The new banner and section header re-use existing classes that already have dark-mode coverage from the recent dark-mode pass. The new `.tn-bv-section-hdr.tiebreaker-rr` modifier needs the same dark-mode override added (one line, mirroring `.tiebreaker-3rd`).

## Edge Cases

| Case | Behavior |
|------|----------|
| 2-way tie | Mini-RR is just 1 match. Single decisive match resolves rank 1. |
| 3-way tie, all distinct after TB1 | Resolve to ranks 1/2/3, next player at rank 4. |
| 3-way tie, 2 still tied after TB1 | Banner re-appears asking about a TB2 between the 2 remaining tied players. |
| All 3 still tied after TB1 (mini-RR rock/paper/scissors) | Banner re-appears asking about a TB2 between all 3 — same logic, no special case. |
| Organizer declines on second cascade | `tiebreaker_declined=1` set. The 2 (or 3) still-tied players become joint winners; next player drops accordingly. |
| Already-declined bracket reload | Banner stays hidden (sticky flag). |
| Tiebreaker round in progress | Banner hidden until all matches in the round are resolved. |
| Lower-rank ties (3rd, 5th, etc.) | Out of scope. Standings show shared rank from primary sort, no tiebreaker offered. |
| Tiebreaker match deleted via existing reset UI | Bracket re-evaluates: if no tiebreaker matches remain and ties are still present, banner re-appears unless `tiebreaker_declined=1`. |
| Parent bracket Best-of-N edited after tiebreaker created | Tiebreaker matches keep their snapshot `best_of_n`. New tiebreaker matches (from later cascades) use the current parent value. |
| Bracket has only 1 match remaining | Detection waits — must be fully complete before banner appears. |

## Test Plan

Manual verification path:
1. Create RR with 4 players, force a 2-way 1st-place tie via match results, verify banner appears.
2. Click "Yes" → 1 tiebreaker match created, bracket re-active. Resolve it → bracket finalizes, standings show winner at 1st, runner-up at 2nd, next two at 3/4.
3. Create RR with 5 players, force a 3-way 1st-place tie. "Yes" → 3 tiebreaker matches. Resolve such that 2 tied → banner re-appears. "No" → joint winners (rank 1 for both), original 4th-place player now at rank 4.
4. Create RR, "No" immediately → joint winners, no matches generated, finalize, refresh — banner stays gone.
5. Verify matrix view never includes tiebreaker matches.
6. Verify dark-mode styling on the banner, section header, and tiebreaker round button.
7. Verify authorization: non-manager users cannot create/decline tiebreakers.

## Open Questions

None. All flagged ambiguities resolved during brainstorming:
- Best-of-N for tiebreaker matches: **inherit from parent bracket**.
- Joint-winner display: **rank 1 with medal, next player at rank N+1, no special label**.
- Tiebreaker visualization: **appended rounds with section header; matrix untouched**.
