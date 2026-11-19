# Order of the Zodiac as a Monthly Award

**Date:** 2026-08-27
**Status:** Design approved, ready for implementation planning
**Depends on:** `2026-08-27-kingdom-ladder-awards-design.md` (the `Award::IsMonthlyLadder()` predicate)

## Problem

The Order of the Zodiac is granted **once per calendar month**, to someone who earned it
through outstanding service in that month. The ORK models it as a 12-rank ladder, so its
twelve positions are stored as levels. They should be months.

The two are not interchangeable, and the data proves it.

**Rank is a level, not a month.** Grants by rank:

| rank | 1 | 2 | 3 | 4 | 5 | 6 | 7 | 8 | 9 | 10 | 11 | 12 |
|---|---|---|---|---|---|---|---|---|---|---|---|---|
| grants | 1193 | 339 | 131 | 55 | 27 | 13 | 7 | 4 | 2 | 1 | 1 | 1 |

A ladder-decay curve. Months would be roughly flat at ~148 each. Reading rank as a month
would relabel 1,193 grants as January in a single deploy.

**Grant dates, by contrast, are near-uniform** — 254 to 364 per month across all twelve,
the fingerprint of a genuinely monthly award. The date is a strong month signal; the rank
is worthless as one.

**Scale.** 3,798 Zodiac grants across 2,525 holders. 1,774 carry a rank; **2,024 are
already unranked** and lose nothing. 35 players already hold duplicate ranks (one holds
nine), which the current set-based progress logic silently under-counts.

## Requirements

1. A grantor designates **which calendar month** a Zodiac is for.
2. Rank pills read **J F M A M J J A S O N D**, tooltips give the full month name.
3. A player who already holds a month can be granted or recommended that month **again** —
   indicated, never blocked.
4. Existing Zodiacs can be edited to carry a month.
5. Reconciliation gets a Zodiac-specific flow: reconcile to month, not rank.
6. Reports showing Zodiac individually show the month; where they show a count, show the
   **total granted**, not the highest rank.
7. The awards-tab widget becomes a month strip, not a 0–12 bar.

## Design

### 1. Data model

A new column, leaving `rank` alone:

```sql
ALTER TABLE ork_awards          ADD COLUMN zodiac_month TINYINT(2) NOT NULL DEFAULT 0;
ALTER TABLE ork_recommendations ADD COLUMN zodiac_month TINYINT(2) NOT NULL DEFAULT 0;
```

`1`–`12` = January–December. `0` = no month recorded.

`rank` is **never written for Zodiac again** and is **never read as a month**. Existing
ranks stay exactly as they are — they remain legible as the legacy levels they always
were, and nothing rewrites them. A Zodiac is month-confirmed when `zodiac_month > 0`,
which is unambiguous on day one and stays unambiguous forever.

This is deliberately not "reuse `rank` plus a flag". That would give one column two
meanings gated on a second column — precisely the split-brain the companion spec exists
to remove from `is_ladder`.

Both migrations need classifying in `ork-db`'s `migration-classification.json5`, or
`drift-check --strict` blocks the unit-test run.

### 2. The Zodiac predicate

`Award::IsMonthlyLadder(30)` is true; every other award is false. Zodiac is the only
award of this nature — no other ladder has an alternate progression — so this is a named
fact, not a taxonomy. All Zodiac behaviour below hangs off it rather than on scattered
`award_id === 30` literals; the companion spec deletes three such literals and neither
document should add a fourth.

Zodiac **stays** `is_ladder = 1`. It keeps the pill machinery, the picker, and its place
in ladder surfaces; only the meaning of the twelve positions changes.

### 3. Month pills

The existing pill components (`pn-`/`kn-`/`pk-rank-pill`) render months for Zodiac:

- Label: the month's initial — **J F M A M J J A S O N D**
- `data-tip`: the full month name
- Submits `zodiac_month`, not `Rank`
- Months already held are marked held (the existing `-held` green state)

**A held month stays selectable.** Typically a player earns one December, but a second is
legitimate and must never be gated. When a held month is selected, the tip becomes:

> *"Player already has a Zodiac for {Month}. {Award|Recommend} another?"*

— "Award" on grant surfaces, "Recommend" on recommendation surfaces.

**No star pill.** The star exists to express recognition past the top of a ladder; Zodiac
has no top. Repeat-month grants serve that purpose instead, and `Award::IsMonthlyLadder()`
suppresses the star.

**Rule 1 does not apply.** The companion spec requires a ranked ladder grant to carry a
rank. Zodiac's equivalent is softer: a month is expected but a monthless Zodiac is
accepted, because 2,024 of them already exist and officers reconciling history need to be
able to record a grant they cannot date to a month.

### 4. Progress: counts, not levels

`GetLadderProgress` currently computes a set of distinct ranks and clamps to `maxRank`.
For Zodiac both are wrong: duplicates are legitimate (35 players already have
them) and a total can exceed 12.

For Zodiac:

- **Count** = total Zodiac grants, uncapped. Not distinct months, not highest rank.
- **MonthsHeld** = the set of distinct months held, for the widget.
- **Unmonthed** = grants with `zodiac_month = 0`, which drive the `~` marker.
- No `min($maxRank, …)` clamp.

The `~` marker keeps its shape but changes its words: for Zodiac it means *month not
recorded*, not *rank not recorded*.

### 5. The awards-tab widget

The 0–12 progress bar is replaced by a month strip:

```
J F (M) A M J (J) A S O N (D)        3 Zodiacs
```

Each month is its initial; months earned are drawn in a filled circle in the player's
accent colour. Repeats are not drawn twice — the circle means "has at least one" — with
the total alongside, so a player with two Decembers reads as one filled `D` and a count
that exceeds the filled circles.

Each month carries a `data-tip` with the full name and, where held, the grant date(s).

### 6. Reconciliation

The Zodiac section of `Playernew_reconcile.tpl` becomes month-based:

- Lists Zodiac grants with `zodiac_month = 0`.
- Each row offers a **month** picker instead of the `min=1 max=12` rank input.
- **Pre-filled from the grant date's month**, which the data shows is a strong signal.
  The officer confirms or changes it.
- Legacy `rank` is displayed read-only as context — "recorded as level 5" — so the
  officer can see what the old system captured without being invited to treat it as a
  month.

Because a monthly award is usually granted at or just after the end of the month it
honours, the pre-fill is a suggestion and never an automatic write. **No bulk
auto-migration**: with 1,774 ranked grants and no reliable rank→month mapping, guessing
at scale would manufacture wrong history that looks authoritative.

Bonus-grant exclusion from the companion spec does not apply to Zodiac — there is no
"past max" for a monthly award. A Zodiac is reconcilable exactly when it has no month.

### 7. Reports

- Anywhere a Zodiac appears individually, show the **month** — "Zodiac (March)" — falling
  back to the grant date when no month is recorded.
- Anywhere a count appears, show the **total granted**, not the highest rank. Highest rank
  is meaningless for a monthly award and misleading for the 35 players with duplicates.
- **Ladder Grid**: the Zodiac column shows the total count rather than a rank number.
  Walker (31) remains excluded from the grid entirely, unchanged.
- Zodiac lists sort **chronologically by grant date**, not by rank.

### 8. Master Zodiac

`GetLadderMasterMap()` maps Zodiac to Master Zodiac (award 8), and holding the master
award currently suppresses the `~` marker. That behaviour is preserved as-is.

Whether Master Zodiac should now mean "has all twelve months" is a **corpora question,
not a software one**, and is deliberately out of scope. Nothing in this design computes
or awards it automatically.

## Error handling

- A month outside 1–12 is rejected server-side in the grant and recommendation paths.
- A monthless Zodiac is accepted — see §3.
- A repeat month is accepted and never blocked; the UI indicates it and asks for
  confirmation through the tip copy only.
- Legacy `rank` values are never validated, rewritten, or interpreted.

## Testing

- `Award::IsMonthlyLadder(30)` is true; every other award, including every other ladder, is false.
- Granting a Zodiac writes `zodiac_month` and leaves `rank` at 0.
- Granting a non-Zodiac ladder never writes `zodiac_month`.
- A repeat month is accepted and the holder ends with two grants for that month.
- Progress count for Zodiac is the total, uncapped — a player with 14 Zodiacs
  reports 14, not 12.
- A player with duplicate months counts every grant but fills each circle once.
- `~` is set when any Zodiac has `zodiac_month = 0`, and clear when none does.
- The star pill never renders for Zodiac.
- Reconciliation lists only monthless Zodiacs and pre-fills from the grant date's month.
- Existing rank values are untouched by every path above.

## Out of scope

- Bulk migration of the 1,774 legacy ranks. No reliable mapping exists; reconciliation is
  the migration path.
- Whether Master Zodiac should require all twelve months.
- Enforcing one Zodiac per kingdom per month. The award is described as monthly, but
  nothing here restricts a kingdom to a single recipient in a month.
