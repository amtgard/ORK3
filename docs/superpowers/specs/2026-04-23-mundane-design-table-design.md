# Move Profile Design Fields to `ork_mundane_design`

**Date:** 2026-04-23
**Status:** Approved for build

## Summary

Extract the 19 profile-design columns added by the 3.5.2 Mask release from `ork_mundane` into a dedicated 1:1 supplemental table `ork_mundane_design`. Public response shapes (`$Player['ColorPrimary']`, etc.) and template access patterns remain unchanged.

## Motivation

The 3.5.2 Mask release added 19 columns to `ork_mundane` for the Design My Profile feature (about, colors, name builder, photo focus, beltline, milestones, name font, persona privacy, pronunciation). `ork_mundane` is now overloaded — a table that started as core identity is carrying a wide and growing slice of presentation preferences. Future profile-customization work would continue widening it. Splitting now, while every read/write site for these columns lives in two contiguous PHP blocks, is cheap and keeps the core identity table focused.

## Scope

**Moves (19 columns):**
`about_persona`, `about_story`, `color_primary`, `color_accent`, `color_secondary`, `hero_overlay`, `name_prefix`, `name_suffix`, `suffix_comma`, `photo_focus_x`, `photo_focus_y`, `photo_focus_size`, `show_beltline`, `pronunciation_guide`, `show_mundane_first`, `show_mundane_last`, `show_email`, `milestone_config`, `name_font`.

**Does not move:**
- `basic_fonts`, `dyslexia_fonts` — viewer-side accessibility preferences (how *you* see the site, not how your profile renders), which would be misleading inside a `_design` table that future readers will assume describes the profile.
- `pronoun_id`, `pronoun_custom`, `park_member_since`, and any other pre-existing `ork_mundane` columns — out of scope for this PR.

The separate `ork_player_milestone` table (custom milestone rows) is unrelated and stays as-is.

## Design Decisions

**Always-present 1:1 row.** A row in `ork_mundane_design` exists for every `ork_mundane` row. Backfill seeds existing players during migration; `create_player` inserts the paired row at the same time it inserts the mundane. Reads can rely on the row existing and read defaults from the schema, not from PHP fallback logic.

Rejected alternatives:
- **Lazy 1:1 row** — splits defaults between schema and PHP, requires `if ($design->find())` checks on every access.
- **JSON blob column** — hides the overload behind a typeless column; loses indexability, ALTER COLUMN safety, and report queryability. Reverses the spirit of the request.

## Database

### New table

```sql
CREATE TABLE ork_mundane_design (
  mundane_id            int(11) NOT NULL PRIMARY KEY,
  about_persona         text NULL,
  about_story           text NULL,
  color_primary         varchar(7)  NULL,
  color_accent          varchar(7)  NULL,
  color_secondary       varchar(7)  NULL,
  hero_overlay          varchar(4)  NOT NULL DEFAULT 'med',
  name_prefix           varchar(100) NULL,
  name_suffix           varchar(100) NULL,
  suffix_comma          tinyint(1) unsigned NOT NULL DEFAULT 0,
  photo_focus_x         tinyint(3) unsigned NULL DEFAULT 50,
  photo_focus_y         tinyint(3) unsigned NULL DEFAULT 50,
  photo_focus_size      tinyint(3) unsigned NULL DEFAULT 100,
  show_beltline         tinyint(1) unsigned NOT NULL DEFAULT 1,
  pronunciation_guide   varchar(200) NULL,
  show_mundane_first    tinyint(1) unsigned NOT NULL DEFAULT 1,
  show_mundane_last     tinyint(1) unsigned NOT NULL DEFAULT 1,
  show_email            tinyint(1) unsigned NOT NULL DEFAULT 1,
  milestone_config      text NULL,
  name_font             varchar(100) NULL,
  CONSTRAINT fk_design_mundane FOREIGN KEY (mundane_id)
    REFERENCES ork_mundane (mundane_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Defaults and nullability mirror the current `ork_mundane` definitions exactly.

### Migration

`db-migrations/2026-04-23-mundane-design-table.sql` — three statements in order:

1. `CREATE TABLE ork_mundane_design (...)` as above.
2. `INSERT INTO ork_mundane_design (mundane_id, about_persona, about_story, color_primary, color_accent, color_secondary, hero_overlay, name_prefix, name_suffix, suffix_comma, photo_focus_x, photo_focus_y, photo_focus_size, show_beltline, pronunciation_guide, show_mundane_first, show_mundane_last, show_email, milestone_config, name_font) SELECT mundane_id, about_persona, about_story, color_primary, color_accent, color_secondary, hero_overlay, name_prefix, name_suffix, suffix_comma, photo_focus_x, photo_focus_y, photo_focus_size, show_beltline, pronunciation_guide, show_mundane_first, show_mundane_last, show_email, milestone_config, name_font FROM ork_mundane;`
3. `ALTER TABLE ork_mundane DROP COLUMN about_persona, DROP COLUMN about_story, DROP COLUMN color_primary, DROP COLUMN color_accent, DROP COLUMN color_secondary, DROP COLUMN hero_overlay, DROP COLUMN name_prefix, DROP COLUMN name_suffix, DROP COLUMN suffix_comma, DROP COLUMN photo_focus_x, DROP COLUMN photo_focus_y, DROP COLUMN photo_focus_size, DROP COLUMN show_beltline, DROP COLUMN pronunciation_guide, DROP COLUMN show_mundane_first, DROP COLUMN show_mundane_last, DROP COLUMN show_email, DROP COLUMN milestone_config, DROP COLUMN name_font;`

Run as a single migration via the project's standard `docker exec ork3-php8-db mariadb` command.

## Backend changes

### Read path — `class.Player.php` ~321-339

Inside the existing player-info loader (immediately after `$this->mundane->find()` succeeds), add:

```php
$design = new yapo($this->db, DB_PREFIX . 'mundane_design');
$design->clear();
$design->mundane_id = $this->mundane->mundane_id;
$design->find();
```

Then in the existing return-array block, change every `$this->mundane->X` to `$design->X` for the 19 moved columns. The array keys (`'AboutPersona'`, `'ColorPrimary'`, etc.) and the surrounding scaffolding stay identical.

### Write path — `class.Player.php` ~1010-1029

Same pattern: load the paired `$design` Yapo at the top of the block, redirect each `$this->mundane->X = ...` assignment to `$design->X = ...`, and call `$design->save()` after the existing `$this->mundane->save()`.

The `is_null($request['X']) ? $design->X : $request['X']` semantics carry over unchanged — only fields the caller actually sent get touched.

### `create_player`

After the existing `$mundane->save()` that produces the new `mundane_id`, insert a paired row with all schema defaults:

```php
$design = new yapo($this->db, DB_PREFIX . 'mundane_design');
$design->clear();
$design->mundane_id = $mundane->mundane_id;
$design->save();
```

One block, located adjacent to the existing INSERT so it's hard to miss in future edits.

### `merge_players`

The FK is `ON DELETE CASCADE`, but the existing merge code transfers data rather than deleting the from-mundane row, so add an explicit cleanup at the end of the existing transfer block:

```sql
DELETE FROM ork_mundane_design WHERE mundane_id = '<from_id>'
```

The to-mundane keeps its own design (matches existing merge behavior — to-mundane is canonical for everything else).

## Audit (must verify before merge)

- `grep -rn "<column>" --include='*.php' --include='*.tpl'` for each of the 19 column names across `system/`, `orkui/`, `orkservice/` returns only the two known blocks in `class.Player.php`. (Pre-spec audit confirmed this for the current branch; rerun before opening the PR.)
- Templates only consume the array keys (`$Player['ColorPrimary']`), never the column names directly.
- SOAP response shape unchanged — verified by `diff` of `player_info` / `fetch_player_details` JSON output before vs. after on at least one customized and one default player.
- GhettoCache: no new invalidation calls needed; existing flush calls in `UpdatePlayer` and `merge_players` cover the design table by virtue of being keyed on the mundane id.

## Out of scope

- Splitting the viewer-side font flags (`basic_fonts`, `dyslexia_fonts`) into a separate viewer-preferences table — different concern, can happen later if desired.
- Migrating any other `ork_mundane` columns added pre-Mask.
- Changing the array key names exposed to templates / SOAP consumers.
- Changing the `ork_player_milestone` table.

## Acceptance

- Migration runs cleanly on a populated database; row count of `ork_mundane_design` equals row count of `ork_mundane`; every previously-customized player retains the same values.
- A player with a fully customized profile (custom colors, custom name builder, milestones, beltline hidden, mundane-name privacy on) renders identically before and after the migration.
- A brand-new player created post-migration has a matching `ork_mundane_design` row with all schema defaults.
- `class.Player.php` no longer references any of the 19 column names against `$this->mundane`.
- Player merge cleans up the from-mundane's `ork_mundane_design` row.
