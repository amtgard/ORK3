# Population Explorer Report — Design

Date: 2026-09-30
Status: Draft for review

## 1. Goal

A report, **Population Explorer**, that lets any logged-in user (officers included) pull a list of players and
details using an AND/OR filter builder.

A filter row is `Criteria | Operand | Value`. Rows are grouped with AND/OR, and
groups nest, e.g.

```
Last Sign-In Date | >= | 2025-01-01
AND Knighthood | HAS ANY | Flame, Sword, Crown
AND Dues Paid | IS | Yes
AND ( Sign-ins in last 6 months | > | 5
      OR Last Class | IS NOT | Druid )
```

The user then picks the output columns (Persona, Last Sign-in Date, Home Park,
Last Sign-in Park, Dues Paid, Knighthood(s) Held, Masterhood(s) Held, …),
runs it, views the table, exports it, and can share the exact query as a link.

## 2. Decisions agreed with the requester

| Topic | Decision |
|---|---|
| Who / scope | **Any logged-in user** may run it for **any kingdom or park** (owner decision 2026-10-01). Anonymous users are sent to Login. The **Suspended** and **Banned** criteria are *restricted*: they need officer authority over the scope being viewed (global admin, kingdom `AUTH_EDIT` for that kingdom, or for a park either park `AUTH_CREATE` for that park or kingdom `AUTH_EDIT` for the park's kingdom; see §3.3). |
| Personal data | **No real-name or email columns in v1.** `restricted` players show persona only. |
| v1 extras | **Excel (.xlsx) export** and **shareable URL** (query encoded in the link). No saved queries, no new tables, no migration. |
| Criteria / columns | The catalog in section 4. |
| Approach | **A:** compile the validated tree to one SQL query from a registry of whitelisted fragments. |

Non-goals: saved queries, drag-reordering columns, event-attendance and
officer-role criteria, real-name/email columns, ladder ranks as display columns,
anonymous (logged-out) access.

## 3. Architecture

Layers follow the ORK3 rule: SQL only in `system/lib/ork3/`; `orkui/model` is
the only membrane.

| Piece | File | Role |
|---|---|---|
| Domain | `system/lib/ork3/class.PopulationExplorer.php` (new, `extends Ork3`) | Criteria registry, tree validation, SQL compilation, query execution, export row-building. Auto-registered as `Ork3::$Lib->populationexplorer`. |
| Model | `orkui/model/model.Reports.php` (add methods) | Thin pass-throughs: `population_registry()`, `population_run($request)`, `population_export($request)`. |
| Controller | `orkui/controller/controller.Reports.php` (add) | `population_explorer` (page), `population_explorer_json` (registry + run), `population_explorer_export` (download). Login required (not in `$public_reports`). |
| Template | `orkui/template/default/Reports_populationexplorer.tpl` (new) | `.rp-*` shell page, matching sibling `Reports_*.tpl`. |
| JS / CSS | `orkui/template/default/script/` + `style/` (new `populationexplorer.js`, small `.pe-*` block) | Builder UI. Only builder-specific pieces get new CSS; everything else reuses `reports.css`. |
| Entry point | `Kingdomnew_index.tpl` and `Parknew_index.tpl` reports tabs (`revised-frontend`) | One link each. No new entry points in `template/default/`. |

### 3.1 Registry

`PopulationExplorer::Registry()` returns criteria keyed by id. Each entry:

- `label`, `group`
- `type`: `date | number | enum_set | bool | peerage_set`
- `operands`: allowed list for the type
- `param` (optional): extra input, e.g. N months
- `sql($operand, $value, $param)`: returns a fragment. This is the only place
  values become SQL, and only after being cast, regex-validated, or looked up.

A stripped copy (no `sql`) is served to the UI so the picker can never drift from
the server.

### 3.2 Request shape and compilation

```
{ tree: { op: 'AND'|'OR', children: [ leaf | group ] },
  columns: ['persona', ...],
  kingdom_id?, park_id?   // honored only if the viewer is authorized for it
}
leaf = { c: criterionId, o: operand, v: value, p?: param }
```

Validation (reject with a message naming the offending rule; build no SQL):

- criterion id exists in the registry; operand allowed for that criterion
- value matches the type: ISO `YYYY-MM-DD` regex, `(int)` casts, `between`
  needs two values (sorted ascending if reversed, see §3.4), ids checked against known parks / kingdoms /
  classes / peerage orders, bool enum
- tree depth ≤ 6, total leaves ≤ 40, `IN`-list size ≤ 100
- columns exist in the column registry; Persona is always included

Compile: recursive walk to `( a AND ( b OR c ) )`. `NOT`-style operands wrap in
`NOT (...)`. For nullable columns, `IS NOT` / `NOT IN` treat NULL as "no match"
(stated in the operand tooltip). An empty tree means "all players in scope".

`mysql_real_escape_string()` is a no-op in this codebase, so nothing relies on
string escaping: user text never reaches SQL.

### 3.3 Scope and authorization

The scope clause is built on the server from the session and AND-ed outside the
user tree, so no tree can widen it:

- Kingdom: `k.kingdom_id IN (stats ids)` via the same
  `Ork3::$Lib->kingdom->GetStatsKingdomIds()` helper `GetPlayerRoster` uses
  (includes principalities per the statistics setting).
- Park: `m.park_id = P AND m.kingdom_id = <P's kingdom>` (same as `GetPlayerRoster`'s park scope).
- **Access:** any valid session token may use any existing kingdom or park as its
  scope. A missing or non-existent scope id is rejected. The scope comes from the
  request (`KingdomId`/`ParkId`) or, failing that, from the session's park/kingdom.
- **Officer authority** over the scope is:
  - global admin;
  - kingdom `AUTH_EDIT` for that kingdom (principalities resolve through `HasAuthority`'s
    parent walk);
  - for a park: park `AUTH_CREATE` for that park, **or** kingdom `AUTH_EDIT` for the
    park's kingdom, so kingdom officers are officers for their own parks (owner intent
    "park or kingdom level officer", ruling A1, 2026-10-01). The old
    `Report::_authorizeKingdomParkReportScope` rule did not include that last case. It only unlocks **restricted criteria** (`suspended`, `banned`):
  - for non-officers they are left out of `PublicRegistry` (not in the picker);
  - `NormalizeTree` rejects them on that rule with "This filter requires officer
    access for this kingdom or park.", whether they arrive in the JSON body, a
    share link or an export.

### 3.4 Execution

Two queries on `ork_mundane m` (performance pass, 2026-10-01; results unchanged):
first the matching ids in report order, capped, with `COUNT(*) OVER ()` as the true
Total from the same single evaluation of the filter; then the selected columns for
exactly those ids, joined to `ork_kingdom k` / `ork_park p`. With:

- criteria fragments as correlated subqueries, written as per-player index probes
  (e.g. last sign-in is `(select a.date from ork_attendance a where a.mundane_id =
  m.mundane_id order by a.date desc limit 1)`, the newest `idx_sor_mundane_date`
  entry; "holds an award" is `(select 1 ... limit 1) is not null` with
  `w.award_id in (0, ids)` checked on `idx_awards_mundane_award_rank_date`, because
  MariaDB turns a correlated `EXISTS` into a full scan of `ork_awards`; aliased rows
  come from one uncorrelated lookup on `idx_alias_award_id`)
- output columns as scalar subqueries, **lazy** (only selected columns cost anything,
  and only for the listed rows)
- no outer `GROUP BY` (every join is 1:1 on a primary key and every fragment is a
  scalar subquery or a probe, so rows cannot multiply); `m.mundane_id` is the final
  ORDER BY tiebreaker; the order key is `CONCAT(m.persona)` (the same value and
  collation as `persona`), so MariaDB sorts the scope instead of walking the whole
  persona index
- SQL emission only (the validated tree and every rule path stay as the user built
  them): identical sibling rules compile once; under OR the "holds one of these
  awards" rules (has award, peerage has any / yes) merge into one probe over the
  union of ids, under AND their "holds none" forms do; each group's parts are
  emitted cheapest first (columns of `m`, then single probes, then aggregates)
- every statement of a run (the ids, the columns, and the scope count) runs under
  its own MariaDB statement timeout (10 s each, so up to three per run); a timeout is
  reported to the user as "query took too long — narrow your filter"
- one run at a time per player: `GET_LOCK('pe:<mundane_id>', 0)` around the run
  (export included); a second request while one is going is refused at once with
  "Another Population Explorer run of yours is still in progress — please wait for
  it to finish." (JSON `busy: true`, export HTTP 429)
- result cap **5,000 rows**; the response carries `truncated: true` and the true
  total (every match of the same predicate) so the UI can say "showing 5,000 of N"

Semantics fixed here:

- **Last Class** = class on the player's most recent sign-in that has a class.
- **Last sign-in days ago** = `DATEDIFF(CURDATE(), last sign-in date)`, using the
  same last-sign-in expression as "Last sign-in date". Players who have never signed in
  are **not matched** by any operator (owner decision 2026-10-01). Use Total sign-ins = 0
  to find them.
- **BETWEEN takes its two values in either order.** For every date and number
  criterion, the server sorts the pair ascending instead of rejecting it, and the
  builder swaps the two inputs on blur so the screen matches what runs. Both ends are
  inclusive.
- **Player Since** = the player's first sign-in date on or after `1988-01-01`, the same
  floor `Player::get_earliest_attendance_date` uses for the profile (earlier rows are
  `0000-00-00` or typo dates). `player_since_override` is not used: that column is not
  on master.
- **Last Sign-in Park** = the park of the most recent sign-in, with ties broken by the
  highest attendance id. An event sign-in with no park (`park_id = 0`) counts as no park,
  so the column is blank and park filters treat it as NULL (no match for IS NOT / NOT IN).
- **Award dates** (`award_date_any`) ignore held awards dated before `1980-01-01`
  (`0000-00-00` or typo years mean "unknown"), per the awards data-mining convention.
- **Classes played in last N months** with IS NOT / NOT IN means "did not play those
  classes in the window", so it also matches players with no sign-ins in the window.
  The operand note says so.
- **Peerage held** counts non-revoked, non-stripped awards (`revoked = 0` and
  `coalesce(stripped_from,0) = 0`; `stripped_from` is NULL or 0 on normal rows) whose `ork_award.peerage` is Knight / Master / Paragon /
  Squire / Page / Man-At-Arms; the knight "order" is the award itself
  (Flame, Sword, Crown, Serpent…).
- **Dues Paid / Dues Through** are computed exactly as the existing Dues report
  (`Report::GetDuesPaidList`) computes them, from the live `ork_dues` table
  (including lifetime dues), within scope. (The legacy `ork_split` ledger is not
  used: it holds only a handful of recent dues rows.)
- **Active / Waivered / Suspended / Banned** map to `m.active`, `m.waivered`,
  `m.suspended`, `m.penalty_box`.

### 3.5 Share link and export

- **Share link:** the validated `{tree, columns}` JSON, base64url-encoded into
  `&pe=` on the report URL (not `q`, which Google Analytics logs as a site
  search). On load the server decodes and **re-validates** it
  like any other request; a hand-edited link is no riskier than typing the same
  thing into the form. Oversized payloads (> 8 KB) are rejected with a message
  rather than truncated. Scope is never taken from the link beyond what the
  viewer is authorized for.
- **Export:** `Reports/population_explorer_export` re-runs the same compiled
  query server-side (POST, logged-in session required, scope re-derived server-side exactly as for Run) and streams an `.xlsx` (Excel only in v1; CSV is not offered, since Excel opens everywhere and avoids CSV quoting/encoding issues) built with `system/lib/vendor/SimpleXlsx.php`
  (pure-PHP writer; no `ZipArchive`). Same 5,000-row cap, with a note row when
  truncated. Dates are written as ISO `YYYY-MM-DD` text (the pure-PHP `SimpleXlsx` writer has no date cell style). `SimpleXlsx.php` is vendored verbatim into `system/lib/vendor/` (it is not yet on master).

## 4. Catalog (v1)

**Filter criteria**

| Group | Criteria | Operands |
|---|---|---|
| Activity | Last sign-in date; Last sign-in days ago (whole days); Sign-ins in last N months (N per row); Total sign-ins; Player Since (first sign-in) | `=  ≠  >  ≥  <  ≤  between` |
| Activity | Last Class; Classes played in last N months | `IS  IS NOT  IN  NOT IN` |
| Location | Home Kingdom; Home Park; Last Sign-in Park | `IS  IS NOT  IN  NOT IN` |
| Status | Dues Paid; Waivered; Active; Suspended; Banned | `IS Yes / No` |
| Status | Dues Through date | date operands |
| Peerage | Knighthood held (by order); Masterhood held; Paragon held; Squire / Page / Man-At-Arms held (exactly those three; Lords-Page and Apprentice are not included) | `HAS ANY / HAS ALL / HAS NONE`, plus `IS Yes/No` for "any" |
| Awards | Has award X; award count; awarded after / before date | has / has not; number operands; date operands `>  ≥  <  ≤  between` (no `=`/`≠`) |
| Qualifications | Reeve qualified; Corpora qualified | `IS Yes / No` |
| Ladder Award Ranks | One criterion per ranked ladder: the 15 global ladders the Ladder Award Grid uses (`ork_award.is_ladder = 1`, excluding Walker in the Middle, id 31), labelled with the kingdom's own name where it renames one, **plus** every kingdom-only ladder of the kingdoms in scope. A kingdom-only ladder is a kingdom award in master's existing list, `Award::pseudoLadderKingdomAwardIds()`; it may sit on a generic award such as Custom Award, id 94. `ork_kingdomaward.is_ladder` is not on master, so swap to that column when the award-management branch merges. | `=  ≠  >  ≥  <  ≤  between` (integer) |

**Ladder rank semantics:**
- A player's rank in a ladder is `GREATEST(MAX(rank), COUNT(*))` over that player's
  *held* awards in that ladder (the Ladder Award Grid's rule; many rows have no rank).
  "Held" is the report's usual rule: `revoked = 0`, not stripped, alias resolved.
- A player with no award in the ladder has rank **0**, never NULL, so `≠` and `<`
  include them. The operand note says this.
- Global ladders match on the resolved award id. Kingdom-only ladders match on
  `w.kingdomaward_id`.
- Criterion ids are generated per ladder: `ladder_a<award_id>` for global ladders and
  `ladder_k<kingdomaward_id>` for kingdom-only ones. They are added to the registry at
  run time for the current scope. A kingdom-only ladder outside the scope is rejected
  on that rule.

**Output columns:** Persona (always), Home Park, Home Kingdom, Last Sign-In Date,
Last Sign-In Park, Last Class, Sign-ins (last 6 months), Total Sign-ins, Player
Since, Dues Paid, Dues Through, Waivered, Active, Knighthood(s) Held,
Masterhood(s) Held, Paragon(s) Held, Award Count, Reeve Qualified, Corpora
Qualified. Persona links to the player profile.

## 5. UI

Shared `.rp-*` shell (`reports.css` linked per-template with `filemtime`):
header with scope chip, `.rp-context` explainer, `.rp-stats-row` (result count,
% of scope, run time), then three `.rp-main` cards: **Filters**, **Columns**,
**Results**. No side inspector; the builder edits inline.

- Group block: bordered, AND/OR segmented toggle in its header, **+ Add rule**,
  **+ Add group**, left accent bar / light indent for nesting. UI nesting cap 3
  (server cap 6); at the cap **+ Add group** is disabled with a `data-tip`.
- Rule row: `[Criteria] [Operand] [Value]` + remove. Changing the criterion
  resets operand and value. A connector label shows the group's AND/OR between
  rows.
- Value controls by type: Flatpickr with `altInput` for dates (human-readable);
  number input (two for `between`); custom chip multi-select for park / kingdom
  / class / order, **scoped to the viewer and not jQuery UI**; Yes/No toggle;
  extra `N` input for the "last N months" criteria.
- Columns card: grouped checkboxes, Persona locked, sensible defaults, Reset.
  Order follows the checkbox order.
- Run is explicit (button), not on keystroke. Run, Copy link and Export sit in one action bar OUTSIDE the collapsible Filters and Columns cards (always visible, between the cards and Results). A successful Run collapses both cards unless the builder was edited while that run was in flight; in that case the cards stay open, the results are marked "Filters changed since last run", and focus is not moved. Results: DataTables table,
  `.rp-empty-state`, a loading state, and per-rule inline validation errors.
- Filters and Columns are collapsible cards: the header toggle (`aria-expanded`) shows a
  one-line summary while folded ("3 rules (AND)", "4 selected", "changed since last run").
  A rule error re-opens Filters.
- A **Help** button in the page header opens the guide: a modal dialog (focus trapped,
  page behind inert, Esc / close button / backdrop close it, focus returns to Help) with
  static prose plus a criteria reference generated from the same registry as the picker,
  so restricted criteria appear only for officers.
- Tooltips use `data-tip`, never `title`. No native `alert/confirm/prompt`.
- Dark mode via `html[data-theme="dark"]` for the builder's own pieces (groups,
  segmented toggles, chips), verified on computed styles in both themes
  (`orkui.css` global control rules win otherwise). Phone width: rule rows stack
  (criteria on top, operand + value below), no horizontal scroll.

## 6. Errors and edge cases

- Invalid tree / value → HTTP 200 JSON `{status: 1, error, rule_path}`; UI marks the rule.
- Not logged in → redirect to Login (page) / `{status: 5}` (JSON), consistent with
  `set_player_active_json`.
- Viewer without a kingdom/park scope and not a global admin → `.rp-empty-state`
  "no scope" like `beltline_explorer`'s `no_kingdom`.
- Query timeout / DB error → generic message to the user; detail goes to the
  server log only.
- Result truncated at 5,000 → banner with the true count.
- Empty `IN` list → rule rejected, never compiled to `IN ()`.

## 7. Testing

1. **Parity tests (PHP CLI against `ork_test`)**: for each criterion, the
   compiled count equals the existing report's count in the same scope: Dues
   (`GetDuesPaidList`), Knights/Masters (`class_masters`/`knights_list`),
   Roster/Active (`GetPlayerRoster`, `GetActivePlayers`), Suspended/Banned.
2. **Compiler unit tests**: nesting and precedence (`A AND (B OR C)` vs
   `(A AND B) OR C`), empty tree, NOT operands with NULLs, depth/leaf caps,
   every rejection path (bad criterion, bad operand for type, bad date, bad id,
   empty IN, oversized link).
3. **Injection tests**: hostile strings in every value slot produce a rejection
   or a harmless literal — never changed SQL.
4. **Authorization**: any logged-in user can run any scope; anonymous cannot;
   restricted criteria (suspended, banned) are hidden from and rejected for
   non-officers of the scope, including an officer of a *different* park/kingdom,
   via JSON body, share link and export; officers of the scope and admins can use them.
5. **Performance**: run the slowest criteria (sign-ins in last N months, Last
   Class, award count) on the largest kingdom (~15.8k players); record timings
   in the PR. Target: each single criterion and the example query in
   section 1 run in under 2 s warm on the largest kingdom; if a
   criterion is slower, fix its fragment (or its index) before shipping.
6. **Browser**: build the example query from the request end to end; export;
   open the share link in a fresh session; dark mode; phone width via the iframe
   harness. Chrome for verification only.

## 8. Rollout

- No migration. No new tables.
- Add the report link to the Kingdom and Park reports tabs. No What's New entry and
  no version bump: it ships under 3.5.5 Hydra (owner decision, 2026-10-01).
- Verify every claim above (including "no migration") against the branch before
  declaring done.

## 9. Open risks

- "Sign-ins in last N months" and "Classes played" are aggregate subqueries per
  player. Measured, not assumed; see Testing 5.
- Peerage-held semantics depend on `ork_award.peerage` classification; the
  parity tests against the Knights/Masters reports are the guard.
