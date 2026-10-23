# Award Explorer Report — Design

Date: 2026-10-13
Status: Draft for review

## 1. Goal

A report, **Award Explorer**, that lists **award grants** — every award the ORK
has ever recorded — newest first, with an AND/OR filter builder, free-text
search, output columns, export and a shareable link.

It is the sibling of Population Explorer, not a mode of it. The difference is
the **grain**: Population Explorer returns one row per *player*; Award Explorer
returns one row per *grant*, so the same player appears as many times as they
have been honoured.

```
Date            | >=      | 2026-01-01
AND Award       | IS ANY  | Order of the Warrior, Order of the Hunter
AND Rank        | >=      | 5
AND ( Kingdom   | IS      | Northern Lights
      OR Kingdom| IS      | The Painted Skies )
```

Non-goals for v1: recommendations (a different object entirely — see §2),
saved queries, editing or revoking awards from the list, and **anonymous access —
the report requires login**.

## 2. Decisions agreed with the requester

| Topic | Decision |
|---|---|
| Who / scope | **Login required, then everything is visible.** Award data is public in principle, but the report itself is gated behind login so crawlers cannot walk 397k rows (owner decision 2026-10-13). Once logged in there is no per-criterion authority, no scope gate and no restricted columns: any logged-in user may list any award in any kingdom. That removes Population Explorer's whole §3.3 authorization layer and keeps only its token check. |
| Recommendations | **Out of scope as data.** They are a different object and belong to the Recommendations Manager. *Possible v2 action:* select rows in the list and recommend those players for something — see §7. |
| Grain | One row per `ork_awards` row. |
| Approach | Sibling class with its own registry, reusing Population Explorer's **patterns** and front end. Copy, do not generalise — see §3.1. |

## 3. Architecture

### 3.1 What is reused, and what is not

`class.PopulationExplorer.php` (1,893 lines) divides cleanly:

**Entity-agnostic — lift as-is.** `NormalizeTree`, `_normNode`, `_normLeaf`,
`_normValue`, `_normSet`, `_normList`, `_cmp`, `_set`, the typed-operator model,
cost-ordered emission, the run lock, per-statement timeouts, share-link
encode/decode, the .xlsx export, and essentially all of
`populationexplorer.js` / `.css` / `-help.js`.

**Player-specific — needs an award equivalent.** `_criterionSql`, `_columnSql`
and the ~30 expression helpers (`_lastSigninExpr`, `_duesPaidExists`,
`_ladderRankExpr`, …), every one written against `m` = `ork_mundane`. The
comment *"Rough evaluation cost per player"* on `_leafCost` marks the seam.

The requester chose **copy over generalise**, consistent with the existing house
pattern (`Reports_beltlineexplorer.tpl`, `Reports_parkattendanceexplorer.tpl`
are each their own template). The cost is a maintenance debt: a fix to the
normaliser must be applied twice. That is accepted, and noted again in §9.

### 3.2 Base query

```sql
FROM ork_awards a
LEFT JOIN ork_mundane       m  ON m.mundane_id       = a.mundane_id
LEFT JOIN ork_kingdomaward  ka ON ka.kingdomaward_id = a.kingdomaward_id
LEFT JOIN ork_award         w  ON w.award_id         = ka.award_id
LEFT JOIN ork_park          p  ON p.park_id          = a.park_id
LEFT JOIN ork_kingdom       k  ON k.kingdom_id       = a.kingdom_id
ORDER BY a.date DESC, a.awards_id DESC
```

`a.awards_id DESC` is a deliberate tiebreaker: `date` is a DATE, not a DATETIME,
so a court that records thirty awards on one day has thirty rows with an
identical sort key. Without the tiebreaker their order is undefined and paging
can repeat or skip rows.

**Ladder classification must come from `Award::sqlEffectiveIsLadder('ka', 'w')`,
never from `rank > 0`.** A kingdom-original ladder has no `ork_award` row, so
neither `is_ladder` column is ever 1 for it; the curated list is the only thing
that recognises those 25 awards. See `class.Award.php`.

### 3.3 The index — a prerequisite, not an optimisation

`ork_awards` carries 16 indexes and **none on `date`**. Measured on production
2026-10-13 (396,913 rows / 163 MB):

| query | before | after `idx_awards_date` |
|---|---|---|
| newest 50 overall | `rows=388,102`, **filesort**, 377 ms | `rows=50`, no filesort, 65 ms |
| one year + newest 50 | `rows=388,102`, **filesort**, 374 ms | `rows=50`, no filesort, 61 ms |

That is the floor — no filters, no joins. Every query this report issues sorts
by date, so without the index each run reads the whole table.

`db-migrations/2026-10-13-awards-date-index.sql` adds it. InnoDB online build
(`ALGORITHM=INPLACE, LOCK=NONE`), **341 ms** on a production-sized restore, no
table lock, no quiet window, roughly 5 MB of index.

### 3.4 Execution

Inherit Population Explorer's protections unchanged — they matter more here,
not less: `ork_awards` is **twice** `ork_mundane`'s row count with a worse
access pattern.

- one run at a time per player, per database
- per-statement timeout, surfaced as a clear refusal rather than a hang
- a busy refusal keeps the previous results on screen
- `COUNT(*)` only when the result is capped

**Default to a date window.** The last 12 months is 30,652 rows against 396,913
total — roughly 8%. Opening on a bounded window keeps the common case cheap and
makes "everything, ever" a deliberate act.

## 4. Data realities to decide

Counted on production 2026-10-13. These are not hypothetical; each needs a
stated answer before implementation.

| | rows | question |
|---|---|---|
| `date = '0000-00-00'` or null | **5,951** | where do these sort? Proposal: **last**, with the date cell rendered "unknown", and a "Date is unknown" filter. Never silently dropped. |
| `date > CURDATE()` | 7 | max is **2997-06-01**, plainly a typo. Proposal: **show** them; they are real rows and hiding them hides the error. An explicit "future-dated" filter makes them findable so they can be fixed. |
| `revoked = 1` | 2,860 | Proposal: **excluded by default**, with a "Include revoked" toggle, shown struck through when included. |
| `mundane_id = 0` (stripped) | 3,079 | `revoke_award` sets `mundane_id = 0` and moves the owner to `stripped_from`, so these have no recipient to join. Proposal: **excluded by default**, same toggle, recipient rendered from `stripped_from`. |

## 5. Catalog (v1)

### Criteria

| Criterion | Type | Notes |
|---|---|---|
| Date | date | `>=`, `<=`, `BETWEEN`, "in the last N months" |
| Date is unknown | bool | the 5,951 zero dates |
| Award | set | by `kingdomaward_id`, labelled per kingdom |
| Award (any kingdom) | set | by base `award_id`, so "Order of the Warrior" matches every kingdom's row |
| Award type | enum | Ladder / Title / Award — via `Award::sqlEffectiveIsLadder` |
| Rank | int | ladder awards; `0` means unranked, not "rank zero" |
| Peerage | set | `ork_award.peerage` |
| Kingdom | set | `a.kingdom_id` |
| Park | set | `a.park_id` |
| Recipient | search | persona, free text |
| Given by | search | `given_by_id` → persona |
| At event | set | `at_event_id` |
| Revoked | bool | default false |
| Note contains | search | `a.note` |

### Columns

Date · Recipient · Recipient's park · Recipient's kingdom · Award · Rank ·
Award type · Given by · At event · Kingdom · Park · Note · Revoked · Entered at

Default: Date, Recipient, Park, Award, Rank, Given by.

## 6. UI

Mirror Population Explorer: collapsible Filters and Columns cards, action bar
outside the cards, Run / Export / Share, Help dialog, breadcrumb following the
report scope. Reuse the stylesheet and the a11y work (44px phone targets, focus
rings, keyboard-reachable operand notes) rather than re-deriving them.

Two additions specific to this report:

- a **free-text search box** above the builder, matching recipient persona and
  award name, as the fast path that needs no filter rows
- a **date-window chip row** (12 months / 5 years / everything) so the default
  bound is visible and changeable in one click

## 7. Possible v2: recommend from the list

The requester raised selecting rows and recommending those players. Deliberately
out of v1 — it turns a read-only report into a write path, with its own
authority rules (who may recommend, in which kingdom) and its own duplicate
questions. Worth doing; worth doing separately.

## 8. Testing

Follow Population Explorer's shape: a `Unit` test for the registry and
tree normalisation, `Integration` tests for compiled SQL and each criterion, and
a controller test for the contract and same-origin check.

Specific cases this report must cover, because they are where the data bites:
zero dates sort last and are never dropped; revoked and stripped rows are absent
by default and present when toggled; a kingdom-original ladder is typed Ladder
(regression guard on `sqlEffectiveIsLadder`); thirty awards sharing one date page
in a stable order.

## 9. Open risks

- **Duplicated normaliser.** Copying Population Explorer's tree handling means
  two copies to fix. Accepted; revisit if a third explorer appears.
- **Grain confusion.** Users accustomed to Population Explorer will read a row
  count as a player count. The UI should say "grants" everywhere, never "results".
- **`custom_name` and `alias_award_id`.** Some rows carry their own display name
  rather than the kingdomaward's. v1 must decide whether the Award column shows
  the stored name or the catalog name; proposal is **stored name when present**,
  since that is what the recipient was given.
