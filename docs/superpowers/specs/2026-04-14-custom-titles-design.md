# Custom Titles — Design Spec

**Date:** 2026-04-14
**Branch:** `feature/player-profile-enhancements`
**Scope:** Allow award-givers to bestow a "Custom Title" with an optional alias to a core system award, treated as full equivalence (name substitution + peerage/report semantics of the alias target).

## Problem

Kingdoms have been using the existing **Custom Award** flow to hand out things that are really *titles* (e.g., "Ambassador of the Fae", "Brother-in-Battle"). These end up flagged `[Custom Award]` in the awards table, but:

- They don't appear in the Titles tab.
- They don't participate in belt relationships (My Associates, Beltline Explorer) even when the giver clearly intended a Page/Squire equivalency.
- They have no peerage semantics the reports can key off of.

Users need a way to give a named custom title **and** optionally mark it as equivalent to a core non-officer title (e.g., Brother-in-Battle *is a* Page).

## Design Decisions (from brainstorm)

1. **Structure:** Add a new **"Custom Title"** entry to the award dropdown, alongside "Custom Award". Picking it reveals the custom-name box **and** an "Alias of… (optional)" dropdown. Custom Award flow is untouched.
2. **Alias source:** Grouped dropdown — peerage ladder awards (Page, Lords-Page, Squire, Man-At-Arms, Master, Knight) first, then all other `is_title=1` non-officer awards.
3. **Substitution:** Full substitution. An aliased Custom Title behaves in every query exactly as if it were an award of the alias type, with only the *display name* differing. Limits (reign/month) are honored on the alias target.
4. **Display:** `[Custom Title]` chip pattern matching `[Custom Award]`, with an `aka Page` muted subtitle when aliased. In the Titles tab, aliased Custom Titles slot into the peerage ladder at the alias's position.

## Data Model

### New column: `ork_awards.alias_award_id`

```sql
ALTER TABLE ork_awards
  ADD COLUMN alias_award_id INT(11) NULL DEFAULT NULL AFTER custom_name,
  ADD KEY idx_alias_award_id (alias_award_id);
```

- `NULL` for all existing rows and for all non-Custom-Title entries.
- Points to `ork_award.award_id` of the core award this custom title aliases to.
- Not a hard FK (the codebase avoids FK constraints for historical reasons).

### New sentinel row: `ork_award` "Custom Title"

```sql
INSERT INTO ork_award (name, is_title, peerage, officer_role, is_ladder, ...)
VALUES ('Custom Title', 1, 'None', 'none', 0, ...);
```

- Plays the same role for Custom Titles that `award_id=94` ("Custom Award") plays today.
- `is_title=1` so unaliased custom titles still show up in the Titles tab.
- Use whatever the next free `award_id` is; capture it into the sentinel constant.

### Storage semantics (three cases)

| Case | `award_id` | `custom_name` | `alias_award_id` |
|---|---|---|---|
| Legacy Custom Award | 94 (Custom Award sentinel) | `'Ambassador of the Fae'` | `NULL` |
| Custom Title — unaliased | 95 (new Custom Title sentinel) | `'Protector of Lore'` | `NULL` |
| Custom Title — aliased to Page | 95 | `'Brother-in-Battle'` | Page's `award_id` |

Note: the alias target is NOT used as the row's own `award_id`. Keeping `award_id = Custom Title sentinel` preserves a clean marker that this is a Custom Title entry, independent of whether an alias is present. Substitution happens at query time via an additional LEFT JOIN.

## Query Substitution Pattern

Anywhere the codebase currently joins `ork_award a ON a.award_id = aw.award_id`, and reads peerage/is_title/officer_role/is_ladder/title_class from `a`, we add:

```sql
LEFT JOIN ork_award alias ON alias.award_id = aw.alias_award_id
```

and resolve the effective award columns via `COALESCE(alias.col, a.col)`:

```sql
COALESCE(alias.peerage, a.peerage)          AS effective_peerage,
COALESCE(alias.is_title, a.is_title)        AS effective_is_title,
COALESCE(alias.officer_role, a.officer_role) AS effective_officer_role,
COALESCE(alias.is_ladder, a.is_ladder)      AS effective_is_ladder,
COALESCE(alias.title_class, a.title_class)  AS effective_title_class,
COALESCE(alias.peerage_rank, a.peerage_rank) AS effective_peerage_rank
```

Display name stays custom-first:
```sql
COALESCE(NULLIF(aw.custom_name,''), ka.name, a.name) AS award_name
```

**Queries that need this treatment:**

1. `system/lib/ork3/class.Report.php` — `BeltlineData()` (lines ~2486–2584). The peerage-filtered relationship query must use `effective_peerage` in its `WHERE` and `GROUP BY`. The returned `title_name` column uses the custom-first display name.
2. `orkui/controller/controller.Player.php` — legacy Titles tab query (lines ~590–620). Filter `(effective_officer_role != 'none' OR effective_is_title = 1 OR effective_peerage NOT IN ('None',''))` to include aliased Custom Titles.
3. `orkui/controller/controller.Player.php` — My Associates query (lines ~559–587). Peerage filter becomes `effective_peerage IN (...)`.
4. `orkui/controller/controller.Playernew.php` — `Awards` and any "titles-only" filter within the details fetch, so the Titles tab on the new profile also gets them.
5. Any **limit/cap checks** in `class.Player.php` `add_player_award()` that compare `peerage` / `is_title` must use the alias target when `alias_award_id` is set. Implementation: resolve `effective_award_id = alias_award_id ?: award_id` up front and run existing cap logic against that.
6. `Reports_beltlineexplorer.tpl` requires no changes if `BeltlineData()` is updated — the template already renders whatever `title_name` / `peerage` come back.

Out of scope for this spec: other reports (e.g., kingdom award statistics) — we update them if/when the user reports a gap.

## UI Changes

### Add-Award modal in Playernew (`Playernew_index.tpl` ~line 2375)

1. Add a **"Custom Title"** entry to the award `<select>` (alongside "Custom Award"). Place it in a visible position — likely right after "Custom Award".
2. When selected, show:
   - The existing `#pn-award-custom-name` input (relabel to "Custom Title Name" dynamically when in Custom Title mode, or use a shared "Custom Name" label that fits both).
   - A new `#pn-award-alias` select with optgroups:
     ```
     — Peerage Ladder —
       Page
       Lords-Page
       Squire
       Man-At-Arms
       Master
       Knight
     — Other Titles —
       (every ork_award where is_title=1, officer_role='none', peerage IN ('','None'), name != 'Custom Title')
     — None —
     ```
     Default: "— None —" (unaliased).
3. The alias dropdown is populated server-side from a new `$CustomTitleAliasOptions` variable built in `controller.Playernew.php` during the initial profile load. No AJAX needed.
4. When submitting the form, the JS reads `pn-award-alias` and includes it as `AliasAwardId` in the POST payload. Empty/None submits as `0` or omitted.

### Save flow

- `controller.Player.php` `case 'addaward'` passes `AliasAwardId` into `add_player_award()`.
- `class.Player.php` `add_player_award()`:
  - Accepts `$request['AliasAwardId']`.
  - Stores it on the new `ork_awards.alias_award_id` column.
  - Resolves `effective_award_id` for cap/limit checks.
  - Validates: if present, alias target must exist in `ork_award`, must be `is_title=1` OR have a non-`None` peerage, and must not itself be the Custom Title sentinel (no recursion).

### Awards table display (Playernew)

- In the same PHP-rendered awards row used in `Playernew_index.tpl`, extend the existing `[Custom Award]` chip logic:
  - `custom_name != '' AND award_id == CUSTOM_TITLE_ID` → render `[Custom Title]` chip.
  - If `alias_award_id` is set, render a muted `aka <alias_award_name>` subtitle on the next line (or inline, matching chip pattern), using the resolved alias name.

### Titles tab (Playernew)

- The titles data feed (`controller.Playernew.php` Titles section) uses `effective_peerage`/`effective_is_title` so aliased Custom Titles flow in naturally.
- Rendering: identical to today's rows, but with the `[Custom Title]` chip + `aka …` subtitle. Sort order for ladder groups uses `effective_peerage_rank` — aliased Custom Titles slot into the appropriate ladder bucket.

## Migration

`db-migrations/2026-04-14-custom-titles.sql`:

```sql
-- 1. Column
ALTER TABLE ork_awards
  ADD COLUMN alias_award_id INT(11) NULL DEFAULT NULL AFTER custom_name,
  ADD KEY idx_alias_award_id (alias_award_id);

-- 2. Sentinel row (captured id used by code via SELECT or hardcoded if stable)
INSERT INTO ork_award (name, is_title, peerage, officer_role, is_ladder)
VALUES ('Custom Title', 1, 'None', 'none', 0);
```

A PHP constant `AWARD_CUSTOM_TITLE` is added wherever `AWARD_CUSTOM_AWARD` lives (or inline alongside existing `94` references) capturing the new sentinel id. The migration file prints the inserted id so the constant can be set to the real value post-migration.

## Security / Auth

- "Give a Custom Title" requires the same permission as "Give an Award" today (`AUTH_PARK` / `AUTH_CREATE` for the target player's park). No new auth surface.
- Alias validation in `class.Player.php` prevents clients from aliasing to officer-role awards or to the Custom Title sentinel itself.
- The alias dropdown is server-rendered, so clients can't inject arbitrary ids; the server also revalidates.

## Non-Goals / Explicitly Out of Scope

- **Editing existing Custom Award entries into Custom Titles** — no backfill UI in this pass. Users re-give if they want the alias.
- **Kingdom-level Custom Title catalogs** — this is an instance-level feature, not a kingdom palette. A future enhancement could add saved presets.
- **Ladder reconciliation** — Custom Titles don't appear in the historical reconcile flow.
- **Revocation behavior** — uses existing award revocation path; no special handling.
- **Changing how legacy Custom Award (award_id=94) works** — fully preserved.

## Success Criteria

1. Giving a Custom Title "Brother-in-Battle" aliased to Page:
   - Appears in the recipient's Awards table with `[Custom Title]` chip and `aka Page` subtitle.
   - Appears in the Titles tab grouped under Pages.
   - Appears in Beltline Explorer as a Page-peerage relationship between giver and recipient, with `title_name = 'Brother-in-Battle'`.
   - Appears in the giver's "My Associates" under Pages if the giver views their own profile.
   - Counts against the giver's monthly/reign Page limit.
2. Giving an unaliased Custom Title:
   - Appears in Awards table with `[Custom Title]` chip, no subtitle.
   - Appears in Titles tab (ungrouped / Other Titles section).
   - Does NOT appear in Beltline Explorer or My Associates.
3. Existing Custom Award flow continues to work unchanged.
4. No performance regression on the Beltline Explorer query (extra LEFT JOIN is on an indexed column).

## Files Touched

- `db-migrations/2026-04-14-custom-titles.sql` *(new)*
- `system/lib/ork3/class.Player.php` — `add_player_award()`, `update_player_award()`: accept/persist `AliasAwardId`, validation, effective-award cap resolution
- `system/lib/ork3/class.Report.php` — `BeltlineData()`: alias join + effective column resolution
- `orkui/controller/controller.Player.php` — `addaward`/`updateaward` cases, Titles query, My Associates query
- `orkui/controller/controller.Playernew.php` — Titles tab data, Awards data, `$CustomTitleAliasOptions` build
- `orkui/template/revised-frontend/Playernew_index.tpl` — dropdown entry, alias select, chip rendering in Awards table and Titles tab, save-flow JS
- `orkui/template/default/Award_addawards.tpl` — *optional*: parallel UI update for legacy profile if time allows, otherwise deferred
