# Mask Release (3.5.2) — Player Profile Customization + Quality of Life

**Branch:** `feature/player-profile-enhancements`
**Theme:** Make the player profile feel like *yours*. Plus a long list
of quality-of-life polish, performance work, and one new admin tool
(audit log viewer).

This is a multi-feature release. The headline is the "Design My
Profile" customizer; the long tail is recommendation seconds, custom
title aliases, milestones, audit log, lazy loading, and dozens of
smaller fixes.

---

## Headline features

### 1. Design My Profile

A new modal-driven customizer on the player's own profile. Tabs:

- **Welcome** — onboarding intro to the customizer.
- **About** — markdown bio (`about_persona`, `about_story`),
  rendered with `marked.js` + `DOMPurify`. Quick-Add snippet drops
  pre-built sections in one click; edit-pencil on the rendered
  About card jumps straight to this tab.
- **Colors** — primary / accent / secondary colors with curated
  preset palettes; gradient hero overlay strength
  (`hero_overlay` ∈ {none, low, med, high, full}); applied
  as `--pn-accent` etc. CSS vars on the profile shell.
- **Hero** — gradient + heraldry overlay strength controls;
  combine sub-elements into a single bullet-separated line.
- **Name Builder** — composes display name from prefix
  (`name_prefix`), core name, suffix (`name_suffix`), with optional
  comma between core and suffix (`suffix_comma`). Suffix dropdown
  includes noble titles + Master / Paragon. Yield-triangle warns
  when picking a title the player may not be entitled to.
- **Photo** — 3-handle focus point and crop size
  (`photo_focus_x/y/size`) for hero portraits.
- **Privacy** — `pronunciation_guide`, `show_mundane_first`,
  `show_mundane_last`, `show_email`. Defaults preserve existing
  visibility (always visible to monarchy + admins).
- **Beltline** — `show_beltline` toggle.
- **Icons** — knights only: `belt_display` ∈ {`white` (default
  generic belt), `own` (earned knighthood belts in award-date
  order), `none`}.
- **Milestones** (see below).
- **Fonts** (viewer-side, not stored in design table) — Basic /
  Dyslexia (Lexend) / Default site-wide preferences. Plus `name_font`
  preference for the persona heading; preview rendering bypasses
  viewer accessibility-font overrides so previews stay accurate.

### 2. My Milestones Timeline

Player-curated "My Story" timeline (knightings, first wins, etc.)
on the About tab. Stored in `ork_player_milestones`; per-player
display config in `ork_mundane.milestone_config` (JSON of which
auto-detected events to show alongside the user-added ones).

Accuracy reminder banner above the custom milestone list — the user
owns this content and is responsible for not contradicting the
official record.

### 3. Recommendation Seconds + Originator Reason Edit

The "+1 a recommendation" feature. Documented separately in
[`agent-instructions/award-recommendations.md`](award-recommendations.md)
§8. New table `ork_recommendation_seconds`, eligibility rules,
cascade behavior, frontend modals on Player + Park + Kingdom
recommendation lists.

### 4. Custom Title Aliases

Custom Awards / Titles can now be tagged with `alias_award_id`
pointing at a real `ork_award` row, so they count toward
beltlines, peerage queries, and roster reports as the aliased
award type — without losing the custom display name.

- `ALTER ork_awards ADD COLUMN alias_award_id INT NULL`
- New sentinel `ork_award` row "Custom Title" (`is_title=1`,
  `peerage='None'`); unaliased custom titles flow into the Titles
  tab via this row.
- Read-side: `BeltlineData` and the legacy Player controller use
  `COALESCE(alias_award_id, award_id)` so peerage matching works.
- Edit-award modal can reclassify a Custom Award → Custom Title in
  one step (also rewrites `kingdomaward_id` to keep the link sane).
- Bug fix: missing `aka` subtitle spans on Awards/Titles rows now
  render the alias display text.

### 5. Lazy-loaded profile sections

Player profile page now paints fast and pulls heavy sections on
their tab clicks instead of all upfront:

- attendance
- notes
- dues history
- recommendations
- voting eligibility badge

### 6. Audit Log Viewer

New admin tool surfacing `ork_danger_audit` records.

- **Migration 2026-04-21-danger-audit-schema-and-backfill.sql**:
  - Expands `parameters` / `prior_state` / `post_state` from
    `VARCHAR(1000)` to `TEXT` (was silently truncating large player
    payloads).
  - Drops 4 useless indexes (FULLTEXT on JSON, plus a cardinality-1
    `entity` index).
  - Adds `idx_entity_id` to support player-centric lookups.
  - Backfills `entity_id = 0` rows by extracting recipient from JSON
    (different fallback per `method_call` family).
- **`Admin_auditlog.tpl`** + filter form with structured detail
  panels, action badges, ID→name link resolution, persona display,
  truncated-prior-state diff handling, dark-mode coverage.
- **Service-side**: `class.DangerAudit.php` extended with new actions
  (Waivered changes flow through `UpdatePlayer` summary;
  `update_player` diff now uses `post_state` instead of request
  params for accuracy).

### 7. Inactive Kingdoms / Inactive Parks reports

New admin reports (`Admin_inactivekingdoms.tpl`, `Admin_inactiveparks.tpl`)
showing `active != 'Active'` rows that admins might want to clean up
or archive. Also blocks `Park/profile/0` and `Kingdom/profile/0`
404-style URLs.

### 8. Restricted Name Visibility — self-service

Restricted-account label renamed to "Restrict Mundane Name
Visibility" with helper text on every create/edit player form. The
player themselves can now toggle this (previously admin-only).

### 9. Mundane Design Table refactor

Spec: `docs/superpowers/specs/2026-04-23-mundane-design-table-design.md`.
Migration 2026-04-23: 19 design columns moved out of `ork_mundane`
into a dedicated 1:1 `ork_mundane_design` table with `ON DELETE
CASCADE` FK back to `ork_mundane`. Viewer-side font flags stayed on
`ork_mundane`. Reduces the width of the hot `ork_mundane` row and
isolates design churn.

---

## Data model changes

Migrations are listed in date/dependency order. Run via the standard
`docker exec -i ork3-php8-db mariadb -u root -proot ork < <file>`.

| Migration | What it does |
|---|---|
| `2026-04-04-player-profile-customization.sql` | `ork_mundane` += `about_persona`, `about_story`, `color_primary`, `color_accent`, `name_prefix`, `name_suffix`, `photo_focus_x/y/size`. |
| `2026-04-04-show-beltline.sql` | `ork_mundane.show_beltline` (default 1). |
| `2026-04-04-suffix-comma.sql` | `ork_mundane.suffix_comma` (default 0). |
| `2026-04-04-pronunciation-and-display-controls.sql` | `pronunciation_guide` + 3 `show_*` toggles. |
| `2026-04-04-hero-gradient-overlay.sql` | `color_secondary` + `hero_overlay` ('med'). |
| `2026-04-08-player-milestones.sql` | New `ork_player_milestones` (MyISAM); `ork_mundane.milestone_config` (JSON text). |
| `2026-04-11-performance-indexes.sql` | Idempotent index add/drop on `ork_attendance`, `ork_event*`, `ork_officer`, etc. Drops some prefixes-of-supersets and computed-column GROUP BY indexes. |
| `2026-04-14-custom-titles.sql` + `-seed-kingdomawards.sql` | `ork_awards.alias_award_id` + index; sentinel `ork_award` "Custom Title" row; per-kingdom `kingdomaward` rows. |
| `2026-04-21-danger-audit-schema-and-backfill.sql` | Audit columns to TEXT; useless index drops; `entity_id` backfill. |
| `2026-04-22-recommendation-seconds.sql` | `ork_recommendation_seconds` (see award-recommendations.md §8). |
| `2026-04-23-belt-display.sql` | `ork_mundane_design.belt_display` ∈ {white, own, none}. |
| `2026-04-23-mundane-design-table.sql` | `ork_mundane_design` 1:1 split, with backfill from `ork_mundane`. |

There are also three throwaway helper SQLs at repo root
(`migration_basic_fonts.sql`, `migration_dyslexia_fonts.sql`,
`migration_name_font.sql`) — these add the font preference columns;
worth folding into the dated migrations directory before merge.

### Schema diagram (high level)

```
ork_mundane ──1:1──► ork_mundane_design   (design fields, post-refactor)
              │
              └─1:N─► ork_player_milestones (user-curated timeline)

ork_recommendations ──1:N──► ork_recommendation_seconds   (+1s)

ork_awards.alias_award_id ──FK──► ork_award (sentinel + real awards)
```

---

## Code map

### Service layer
- `class.Player.php` — adds milestones CRUD, design field
  read/write, `AddSecondToRecommendation` / `EditSecondNotes` /
  `WithdrawSecond` / `EditAwardRecommendationReason` /
  `GetSecondsForRecommendations` (see award-rec doc), Custom Title
  alias resolution helpers.
- `class.Award.php` — Custom Title sentinel handling,
  `UpdateAward({AliasAwardId})` accepts the new field with
  Custom→Title reclassification.
- `class.DangerAudit.php` — new actions, name resolution helpers,
  diff scaffolding for the viewer.
- `class.Attendance.php` — perf-related changes for indexes.
- `class.SearchService.php` — mundane-name scope.
- `class.Park.php` / `class.Kingdom.php` — inactive-list service helpers.
- `class.Report.php` — recommendation read additions
  (Seconds/SecondsCount/ViewerCanSecond/ViewerCanEditReason).

### Controllers
- `controller.Player.php` + `controller.PlayerAjax.php` — endpoints
  for design save, milestones CRUD, second/withdraw/edit-reason,
  privacy toggles. Notes-tab self-service close-out.
- `controller.Admin.php` + `model.Admin.php` — auditlog,
  inactivekingdoms, inactiveparks pages.
- `controller.Login.php` — preserve destination URL through
  stale-session redirect; trim username/email; subtle bugfix on
  password reset.
- `controller.SearchAjax.php` — mundane-name search wiring.

### Templates / frontend
- `Playernew_index.tpl` — the bulk of the work: Design My Profile
  modal (multiple tabs), milestones timeline, lazy tab loaders,
  rec-second badges/buttons/lists, alias display, belt picker.
- `_recommendation_seconds_assets.tpl` — shared partial for the
  Player + Park + Kingdom rec lists (modals, JS, styles) so the
  feature lives in one place.
- `Admin_auditlog.tpl`, `Admin_inactivekingdoms.tpl`,
  `Admin_inactiveparks.tpl` — new admin tools.
- `revised.css` / `revised.js` — Design My Profile styles + JS,
  rec-seconds UX, dark-mode polish on Log Out item, audit-log
  filter card, action badges.
- `whats_new_content.php` — Mask release entry (3.5.2 Mask,
  2026-04-22).

---

## Workflows

### Player customizing their profile
1. Visit own profile → click **Design My Profile** in hero.
2. Cycle through tabs (Welcome → About → Colors → Hero → Name
   → Photo → Privacy → Beltline → Icons → Milestones → Fonts).
3. Each tab saves to its own endpoint; the modal can be closed at
   any tab without losing prior edits.
4. Add milestones → they appear on the About tab timeline mixed
   with auto-detected events (knightings, level-ups, etc.) per
   `milestone_config`.

### Anyone seconding a rec
See [award-recommendations.md](award-recommendations.md) §8.

### Officer reclassifying a Custom Award as a Title
1. Open the player's Awards tab → edit the Custom Award.
2. Toggle **Custom Title**; optionally choose an `alias_award_id`
   pointing at the peerage equivalent (Knight, Squire, Page, etc.).
3. Save → row is reclassified, `kingdomaward_id` rewritten,
   beltlines/rosters now treat it as the aliased type.

### ORK Admin reviewing audit log
1. Admin dashboard → Audit Log.
2. Filter by entity, action, date range, actor; submit preserves
   the `Route` param (was being dropped).
3. Click a row to expand → structured panel with prior/post diff,
   resolved IDs as links.

---

## Things to know before changing this branch

- **Design fields live in `ork_mundane_design`, not `ork_mundane`**
  (post-refactor). All design read/write must go through the
  design table; the original columns on `ork_mundane` were dropped
  by the refactor migration. The Player service handles the join.
- **Belt display lives in `ork_mundane_design`**, not on
  `ork_mundane`. The 2026-04-23-belt-display migration assumes
  the mundane-design refactor has already run.
- **Milestone display is JSON in `ork_mundane.milestone_config`**,
  not a separate table — it's per-player display preference, not
  shared structural data. Don't normalize it without a story for
  default values.
- **`AwardRecsPublic` config gates rec visibility**, including
  who can see seconds. The seconds list inherits the same gating.
- **Lazy-load tabs use a known tab-id → endpoint contract.** New
  tabs that need lazy data must register on both sides; the JS
  fetcher is generic.
- **Audit log expects TEXT-sized JSON** post-2026-04-21 migration.
  Older `VARCHAR(1000)` rows that were truncated will produce
  harmless "Unexpected end of JSON" warnings during backfill.
- **Performance indexes were curated against MariaDB 10.x**. Some
  pre-existing indexes were strict prefixes of new supersets and
  were dropped — re-adding them naively (e.g. via a schema
  comparison tool) will undo the optimization.
- **Three loose root-level migrations** (`migration_basic_fonts.sql`,
  `migration_dyslexia_fonts.sql`, `migration_name_font.sql`) need
  to be folded into `db-migrations/` with proper dates before
  merge — currently easy to miss when applying migrations.
- **`class.Authorization.php` modifications on this branch**: the
  `true ||` login bypass hack is local-only per project memory.
  Confirm before staging.

## What's not done in this branch

The "What's New" modal is shipped; the full feature surface above
is implemented. Notable gaps observed in the code:

- **Three root-level migration files** (`migration_basic_fonts.sql`
  etc.) need to move to `db-migrations/` before merge.
- **Custom Title alias editor** is functional but heavy on UX
  pinches — no in-app preview of how the alias will display
  across rosters/beltlines.
- **Audit log diff view** handles truncated prior-state but does
  not visually flag rows that were affected by the truncation.
- **Milestones timeline** has no privacy controls of its own — it
  inherits the about-tab visibility but cannot mark individual
  milestones as private.
- **Belt display** does not yet honor revoked/reactivated belts —
  it will display all earned belts in date order regardless.
