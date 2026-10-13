# Kingdom-Specific Ladder Awards

**Date:** 2026-08-27
**Status:** Design approved, ready for implementation planning

## Problem

Amtgard defines 16 official ladder awards (`ork_award.is_ladder = 1`). Kingdoms have
their own multi-rank awards too — 24 of them across 18 kingdoms, carrying **3,436
granted ranks** — recorded as `ork_kingdomaward.is_ladder = 1`.

The application cannot manage that second set and mostly cannot see it:

- **`ork_kingdomaward.is_ladder` has no writer anywhere in the codebase.** All 24 rows
  can only have come from direct database access. A kingdom cannot create, edit or
  remove one through the ORK.
- **"Is this a ladder?" is answered five different ways**, so each surface sees a
  different subset:

  | Predicate | Uses | Sees |
  |---|---|---|
  | `a.is_ladder` | 8 | official only |
  | `->is_ladder` (PHP) | 7 | official only |
  | `ka.is_ladder` | 4 | kingdom only |
  | `COALESCE(alias.is_ladder, a.is_ladder)` | 3 | both |
  | `Award::pseudoLadderKingdomAwardIds()` | grouping | kingdom only, hardcoded |

- `Kingdom::GetAwardList` — the query behind Manage Awards, the award pickers and the
  recommendation lists — **filters** on `ka.is_ladder` but **selects** `a.is_ladder`.
  One method, two different definitions.
- `Award::pseudoLadderKingdomAwardIds()` (`class.Award.php:545`) hardcodes 24 primary
  keys. Verified: that array is **exactly** `SELECT kingdomaward_id FROM ork_kingdomaward
  WHERE is_ladder = 1`. The distinction already exists; it is stored as pasted row IDs
  instead of read from the column that holds it.
- `ork_kingdomaward.max_level` is set to 10 on all 24 rows and **read by nothing**.

Consequence: a kingdom ladder gets no rank picker on any surface, no progress tile, no
place in ladder reports, and is grouped as an ordinary award. Wetlands' *Tsunami* has 83
ranked grants and renders as a flat award.

This is the unfinished half of an earlier migration. `is_title` and `title_class` were
switched to read the kingdom row authoritatively; `is_ladder` was left behind.

## Requirements

1. The 16 official ladders must **never** be un-toggleable by a kingdom.
2. A kingdom may ladder-ify any of its own awards and set a maximum rank.
3. Every surface that cares about ladders must recognise kingdom ladders: award
   granting, recommendations, awards-tab widgets, reports, profiles.
4. Official and kingdom ladders must be **visibly distinguishable** to users.
5. The Walker exception (`award_id = 31`, excluded from ladder reports) continues.

## Design

### 1. The predicate

No schema change. Both columns exist and both hold live data; what changes is how they
are read.

**Effective ladder** — additive, so official can only ever be raised, never lowered:

```sql
GREATEST(IFNULL(ka.is_ladder, 0), IFNULL(a.is_ladder, 0))
```

**Official ladder** — unchanged, still what cross-kingdom comparisons key on:

```sql
a.is_ladder = 1
```

Two static helpers on `Award`, following `OfficerPosition::DisplayTitleSql()` — the same
pattern used earlier in this branch to fix the same class of drift for officer titles:

```php
Award::LadderSql($ka = 'ka', $a = 'a')   // effective
Award::OfficialLadderSql($a = 'a')       // official
```

Every existing predicate spelling becomes a call to one of these.
`pseudoLadderKingdomAwardIds()` is **deleted** — the column replaces it with no
behaviour change on day one, because the two sets are identical.

**Max rank cannot be resolved in SQL**, because the official ladders' maxes live in PHP,
not in the database. `Award::GetLadderMasterMap()` (`class.Award.php:17`) is the real
authority for them — and **Order of the Zodiac (30) is 12, not 10**. `ka.max_level` is
`0` for every official row, so a SQL-only `COALESCE(NULLIF(ka.max_level,0), 10)` would
silently demote Zodiac to 10.

A PHP helper resolves it instead:

```php
Award::MaxRankFor(int $awardId, int $kaMaxLevel = 0): int
// official (in GetLadderMasterMap)  -> that entry's MaxRank
// kingdom ladder                    -> ka.max_level
// anything else                     -> 10
```

This also retires a special case currently written out **three times**:
`GetLadderMasterMap()` has Zodiac at 12, `GetLadderProgress` falls back to
`($lpAid === 30) ? 12 : 10` (`class.Player.php:1636`), and
`Playernew_reconcile.tpl:185` repeats `($aid === 30) ? 12 : 10` again. All three become
calls to the helper.

Note `GetLadderMasterMap()` holds 14 entries, not 16: Walker (31) is deliberately absent,
and Order of the Flame (34) is missing — it falls through to 10, which is its correct
value, but by accident rather than by declaration. The helper makes that explicit.

**The Zodiac predicate.** Order of the Zodiac (30) is granted once per calendar month, so
its twelve positions are months rather than levels — nobody advances from March to April.
It is the **only** award of that nature; no other ladder has an alternate progression.

That does not warrant a kind taxonomy, which would imply a family that does not exist. It
warrants one named predicate, so the special case lives in a single place instead of
being spelled out wherever it is needed:

```php
Award::IsMonthlyLadder(int $awardId): bool   // true for 30, false for everything else
```

This is the same discipline as `DisplayTitleSql()` — not an abstraction over a set, just
a name for a fact that several call sites need. This spec is already deleting three
scattered `$awardId === 30` literals; it must not add a fourth.

Everything in this document is written for ordinary ranked ladders, which is every ladder
except Zodiac. Zodiac's behaviour is specified separately in
`2026-08-27-zodiac-monthly-awards-design.md`, which depends on this predicate.

### 2. Rank display vs. rank offering

These are separate questions and are currently conflated. Separating them is what makes
un-laddering safe:

- **Display a rank** when `ork_awards.rank > 0` — a property of the **grant**.
- **Offer a rank picker** when the award is currently an effective ladder — a property
  of the **award**.

Un-ticking Ladder therefore stops the picker but cannot touch history. Past ranks keep
rendering, no confirm dialog is needed, no data migration, and re-ticking is a no-op for
existing grants. Un-laddering is forward-only by construction.

### 3. Editing — Manage Awards modal

Two controls per row, between **Title?** and **Class**:

- **Ladder** — checkbox, writes `ka.is_ladder`
- **Max Rank** — number, enabled only when Ladder is ticked, writes `ka.max_level`.
  Default **10**, hard ceiling **12** (`max="12"` client-side, clamped in
  `Kingdom::EditAward`)

Saved through the existing per-row save and the existing `kingdom.award.edit` permission;
`Kingdom::EditAward` already authorizes against the award's own kingdom
(`class.Kingdom.php:395-401`). No new permission, no new endpoint.

**The official 16 render fully locked** — Ladder ticked and disabled, **and Max Rank
disabled too**, `data-tip`: *"Standard Amtgard ladder award — this can't be changed."*

Locking Max Rank as well as the checkbox is a deliberate reading of requirement 1. The
requirement says a kingdom must not be able to un-toggle an official ladder; it is silent
on max rank. Leaving Max Rank editable would let one kingdom run Order of the Rose to 12
ranks while every other kingdom runs it to 10 — a corpora deviation dressed up as a
display setting, and one that would make cross-kingdom ladder reports incomparable. The
official ladders' shape belongs to Amtgard, so the whole ladder configuration is locked,
not just the flag. `EditAward` rejects a `ka.max_level` write when `a.is_ladder = 1` for
the same reason it rejects the flag.

Ladder and Title? are mutually exclusive: ticking one unticks the other.

The modal's instructions box gains a **Ladder** entry: the standard orders are set by
Amtgard and locked, a kingdom may ladder-ify its own awards, and un-ticking is
forward-only.

### 4. The star pill

A new pill state — `pn-rank-star` / `kn-` / `pk-`, added to the existing grouped selector
at `revised.css:1337`:

- Rendered **only** when the player's current rank ≥ max rank. Below that, the picker
  looks exactly as it does today.
- Sits after the last numbered pill, showing **✱** instead of a number.
- Selecting it submits an **unranked** grant — `rank = 0`. It does **not** submit
  `max + 1`, and no grant ever carries a rank above the award's max.
- Selecting it reveals inline: *"The standard cap for this award is {max} — but don't let
  that stop you from recognizing someone!"*

Because it keys on the player's rank against the award's max, it appears on a 12-rank
kingdom ladder only at 12, and on an official order at 10.

**The star applies to the official 16 as well.** Their max rank is locked at 10, but a
player who has reached 10 can still be recognised again — as an unranked grant. Locking
the *shape* of the official ladders does not restrict recognising someone who has
finished one.

This is what makes max rank a real ceiling for ranked grants while keeping recognition
open-ended: ranks run 1..max, and anything beyond max is expressed as an unranked grant
rather than an out-of-range number. Ranks above max that already exist in the data — from
imports or historical records — still render; nothing rewrites them.

### 5. Bonus grants: the `~` marker and reconciliation

An unranked grant of a ladder award currently means one thing to the ORK — *a historical
record whose rank was never captured*. The star introduces a second, legitimate kind of
unranked grant: recognition beyond the top of the ladder. These must not be confused.

**Definition.** For a given ladder award and player, a **bonus grant** is an unranked
grant whose date is later than the date the player first reached max rank. If the player
has never reached max rank, there are no bonus grants and every unranked grant stays
unreconciled — which is the correct reading of the old data.

Ties on the same date count as unreconciled, not bonus. That is the conservative side of
the line: a false "needs reconciling" is a dismissible prompt, whereas a false "bonus"
silently hides a genuinely broken record.

**No new query.** `AwardsForPlayer` already returns `Rank` and `Date` on the same row
(`class.Player.php:1173-1174`), so the classification is a pass over data
`GetLadderProgress` already has. It has no date awareness today.

**`GetLadderProgress` changes** (`class.Player.php:1610-1640`). Today:

```php
$effectiveCount = count($lp['RankSet']) + $lp['UnrankedCount'];
$lp['Approx']   = ($effectiveCount > $lp['Rank']) && empty($lp['HasMaster']);
$lp['Rank']     = min($maxRank, max($lp['Rank'], $effectiveCount));
```

Every unranked grant inflates the displayed rank and trips `~`. After the change, bonus
grants are counted separately and excluded from both:

- `UnrankedCount` counts only non-bonus unranked grants — these still drive `~`.
- `BonusCount` is tracked alongside and never affects `Rank` or `Approx`.

So a player at 10/10 who is recognised twice more shows **10 / 10** with no `~`, instead
of today's `~10 / 10` implying broken data. The existing `HasMaster` suppression is
unchanged.

**Tile display.** The tile gains a quiet `✱2` beside the rank when `BonusCount > 0` —
recognition earned past the top of the ladder is worth showing, and it explains why the
player has more grants than ranks without implying an error.

**Reconciliation** (`Playernew_reconcile.tpl`). Bonus grants are excluded from the list
entirely. The page tells the player these are records "not matched to your official award
history yet" (`:124`) — which would be actively wrong about a deliberate star grant, and
would invite them to assign it a rank it should never have.

### 6. Surfaces

**Rank pickers — eight.** All built by `revised.js` off the shared pill selector, so the
star pill and effective-ladder check are implemented once:

| Wrap | Surface |
|---|---|
| `pn-rank-pills` | Grant award — player profile |
| `pn-rec-rank-pills` | Recommend award — player profile |
| `pn-edit-rank-pills` | Edit an existing award |
| `pn-edit-reconcile-rank-pills` | Reconcile a historical award |
| `kn-rank-pills` / `kn-rec-rank-pills` | Grant / recommend — kingdom profile |
| `pk-rank-pills` / `pk-rec-rank-pills` | Grant / recommend — park profile |

All eight move to effective ladder and real max rank instead of a hardcoded 10.

**Server grant paths.** All funnel through `Model_Player::add_player_award`, called from
`controller.Award.php:107` (Award/kingdom, Award/park), `controller.Player.php:132` and
`controller.Admin.php:1126`. All four must stop dropping rank for kingdom ladders, and
all four inherit Rule 1 (below) because it lives in `add_player_award` rather than in any
one caller.

**Grant-from-recommendation** — `Kingdomnew_recommendations_panel.tpl`:

- The Grant button (`:111`) passes the rec's `Rank` straight into the grant.
- Line 75 buckets rows with `(int)$rec['Rank'] > 0 ? 'below' : 'nonladder'`, using *rank
  present* as a proxy for *is a ladder*. A kingdom-ladder rec has no rank today, so it
  files under "Non-Ladder Awards & Titles" and the officer is told to Grant-or-Delete a
  ranked award as if it were flat. This bucketing moves onto effective ladder.

**Recommendation reads** — `Report::recommended_awards` (`controller.Player.php:248`,
`controller.PlayerAjax.php:641`, `controller.Kingdom.php:135/138`) and
`recommended_awards_count` (`:423`). All take `IncludeLadder`/`LadderMinimum`.

**Other consumers:**

| Surface | Change |
|---|---|
| `Award::groupAwardOptions()` | Drop the hardcoded list; group by effective ladder, official and kingdom ladders as separate labelled groups |
| `Kingdom::GetAwardList` | `a.is_ladder` → `Award::LadderSql()`, finishing the `is_title` migration |
| `Player::GetLadderProgress` | Picks up kingdom ladders; real max rank |
| Awards-tab tiles | Official and kingdom ladders in separate labelled groups |
| `Report::kingdom_awards` | `IncludeLadder`/`LadderMinimum` honour effective ladder |
| Ladder Grid | Kingdom-scoped: official columns then a separated kingdom group. Global: unchanged, official-only, Walker still excluded |

**Ladder Grid detail.** Columns are keyed on `award_id`, which kingdom ladders lack
(`0`, or the shared `94` placeholder). Two kingdoms' "Order of the Hunter" are different
rows. Kingdom columns are therefore keyed on `kingdomaward_id` and appear **only** in the
kingdom-scoped grid; the global grid stays official-only.

### 7. Error handling

Two hard rules, and otherwise the design removes failure modes rather than handling them.

**Rule 1 — a ladder grant must carry a rank in 1..max, with one exception.** An
*unranked* grant of an effective ladder award is allowed **only** when the recipient's
current rank for that award is already ≥ max. That is the star path. Granting a ladder
award with no rank to someone below max is rejected — it is the mistake that produces the
rankless ladder grants this feature exists to stop.

Enforced in `Model_Player::add_player_award` so all four grant callers inherit it, and
mirrored in the pickers (which simply do not render a star below max).

Rejection message: *"{Award} is a ranked award — choose a rank, or use ✱ if they have
already reached {max}."*

**Rule 2 — Max Rank ≤ 12**, enforced client-side (`max="12"`) and clamped in
`Kingdom::EditAward`.

Everything else:

- No grant is rejected for exceeding max, because the UI can no longer produce one — the
  star yields an unranked grant instead. Pre-existing ranks above max are left alone and
  still render.
- An official award cannot be un-laddered: `GREATEST` makes it arithmetically impossible,
  `EditAward` refuses the write, and the disabled checkbox is the third line of defence.
- An official award's max rank cannot be changed either — `EditAward` rejects a
  `ka.max_level` write when `a.is_ladder = 1`, so its shape stays comparable across
  kingdoms.
- Un-laddering needs no confirm — forward-only and reversible.
- `max_level = 0` means unspecified, resolves to 10.
- Ranks above max render fine; progress bars already clamp (`Playernew_index.tpl:2127`).

## Testing

**Refactor safety net (write first).** Assert `Award::LadderSql()` selects exactly the 24
`kingdomaward_id`s of the deleted hardcoded array. This proves the refactor is
behaviour-preserving on day one and is the single most valuable test here.

Then:

- An official award stays an effective ladder when `ka.is_ladder = 0` is forced.
- `EditAward` refuses to clear the ladder flag on an official award.
- Max Rank above 12 is clamped server-side.
- Un-ticking a kingdom ladder leaves `ork_awards.rank` untouched, and past grants still
  render their ranks.
- The star pill appears at rank ≥ max and not below.
- The star submits `rank = 0`, not `max + 1`.
- An unranked ladder grant is rejected for a player below max, and accepted for a player
  at or above max.
- The star is available on an official ladder at rank 10, even though its max rank is
  locked.
- A pre-existing grant with rank above max still renders and is not rewritten.
- `Award::MaxRankFor()` returns 12 for Order of the Zodiac (30) and 10 for the other
  official ladders — the Zodiac case that is currently duplicated in three places.
- An unranked grant dated after the player reached max rank is classified bonus: it does
  not set `~`, does not inflate the displayed rank, and is absent from the reconciliation
  list.
- An unranked grant dated before that point is still unreconciled and still sets `~`.
- An unranked grant on the same date as the max-rank grant counts as unreconciled.
- A player who never reached max has no bonus grants; every unranked grant stays
  reconcilable, matching today's behaviour exactly.
- A kingdom-ladder recommendation buckets as "below", not "nonladder".
- Walker (`award_id = 31`) remains excluded from ladder reports.

## Out of scope

- `ork_kingdomaward.max_level` for the official 16 stays `0`; no cap is invented for them.
- `Kingdomnew_recommendations_panel.tpl:83` uses a native `title=` attribute, breaking the
  `data-tip` house rule. Pre-existing and unrelated to ladders.
- Bulk rank editing or stripping.
