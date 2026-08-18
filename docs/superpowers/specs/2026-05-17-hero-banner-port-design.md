# Hero Banner — Port to Park, Kingdom, Player, Unit

**Status:** Design — pending review
**Source pattern:** `feature/event-planning-expansion` event banner (see `docs/handoffs/` and `orkui/template/revised-frontend/Eventnew_index.tpl` lines 22–34, 617–644, 2100–2130, 2240–2400)
**Target branch:** new branch off `master`, single bundled PR

---

## Goal

Port the event hero banner feature (full-bleed 1800×240 image with framing, vignette, and host-editable upload modal) to four additional entity profile pages: **Park**, **Kingdom**, **Player**, **Unit**. The pattern is duplicated per entity with prefixed selectors (`pk-`, `kn-`, `pn-`, `un-`) rather than abstracted into a shared module — matches the project's existing per-template convention.

## Non-goals

- No new "Unitnew" template (the existing `Unit_index.tpl` is already the CRM-style redesign with `.un-hero`).
- No shared CSS/JS module — each entity gets its own copy.
- No changes to the event banner pattern itself; this is a port, not a refactor.
- No new framing tools beyond what Event ships (drag-to-position canvas, two display toggles).

## Per-entity matrix

| Entity | Table | ID padding | Edit-scope check | Hero class | Existing AJAX controller |
|---|---|---|---|---|---|
| Park | `ork_park` (keyed on `park_id`) | 5-digit | `HasAuthority(uid, AUTH_PARK, park_id, AUTH_EDIT)` | `.pk-hero` | `controller.ParkAjax.php` |
| Kingdom | `ork_kingdom` (keyed on `kingdom_id`) | 4-digit | `HasAuthority(uid, AUTH_KINGDOM, kingdom_id, AUTH_EDIT)` | `.kn-hero` | `controller.KingdomAjax.php` |
| Player | **`ork_mundane`** (keyed on `mundane_id`) | 6-digit | self OR park officer of player's park OR kingdom officer of player's kingdom OR admin | `.pn-hero` | `controller.PlayerAjax.php` |
| Unit | `ork_unit` (keyed on `unit_id`) | 5-digit | `HasAuthority(uid, AUTH_UNIT, unit_id, AUTH_EDIT)` (already in `$CanEdit`) | `.un-hero` | **create `controller.UnitAjax.php`** |

ID padding chosen to match each entity's existing heraldry constants in `config.dev.php` (`HERALDRY_PARK_DEFAULT = '00000.jpg'`, etc.).

**Player → mundane:** the player profile is indexed by `MundaneId`, and heraldry already lives on `ork_mundane` (`class.Player.php:329`). Banner columns go on the same table, keyed the same way — one banner per real person, shared across all their personae (same model as heraldry).

### Player edit-scope detail

Concretely, the check inside `PlayerAjax::banner()`:

```php
$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
$thePlayer  = /* hydrate by $id → mundane row */;
$canEdit =
       $mundane_id == $id                                                                       // self
    || Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK,    $thePlayer['ParkId'],    AUTH_EDIT)  // park officer
    || Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $thePlayer['KingdomId'], AUTH_EDIT)  // kingdom officer
    || /* admin check — match the existing convention; e.g. AUTH_GLOBAL or admin allowlist */;
```

The existing `controller.Player.php` uses park-officer-only (line 124); this design intentionally widens that to kingdom + admin per the brainstorming decision. Resolve the exact admin check during implementation by reading how a comparable elevated-write action does it (e.g. `controller.PlayerAjax::set_player_details`).

## Schema

One migration: `db-migrations/2026-05-17-add-entity-banners.sql`. Same 5 columns per table:

```sql
ALTER TABLE ork_park
    ADD COLUMN IF NOT EXISTS has_banner        TINYINT(1)       NOT NULL DEFAULT 0 AFTER has_heraldry,
    ADD COLUMN IF NOT EXISTS banner_show_logo  TINYINT(1)       NOT NULL DEFAULT 1 AFTER has_banner,
    ADD COLUMN IF NOT EXISTS banner_vignette   TINYINT(1)       NOT NULL DEFAULT 1 AFTER banner_show_logo,
    ADD COLUMN IF NOT EXISTS banner_offset_x   TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_vignette,
    ADD COLUMN IF NOT EXISTS banner_offset_y   TINYINT UNSIGNED NOT NULL DEFAULT 50 AFTER banner_offset_x;

ALTER TABLE ork_kingdom    ADD COLUMN ... (same five, AFTER has_heraldry);
ALTER TABLE ork_mundane    ADD COLUMN ... (same five, AFTER has_heraldry);   -- player banner lives here
ALTER TABLE ork_unit       ADD COLUMN ... (same five, AFTER has_heraldry);
```

All four target tables have `has_heraldry` (verified). Defaults are identical to the Event migration (`has_banner=0`, others = "good defaults" so toggles default on, offsets default centered).

Run via `docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-05-17-add-entity-banners.sql` (per memory rule).

## Storage

Add to `config.dev.php` and `config.dist.php`, alongside existing `HTTP_EVENT_BANNER` / `DIR_EVENT_BANNER`:

```php
define('HTTP_PARK_BANNER',    HTTP_HERALDRY . 'park-banner/');
define('HTTP_KINGDOM_BANNER', HTTP_HERALDRY . 'kingdom-banner/');
define('HTTP_PLAYER_BANNER',  HTTP_HERALDRY . 'player-banner/');
define('HTTP_UNIT_BANNER',    HTTP_HERALDRY . 'unit-banner/');

define('DIR_PARK_BANNER',     DIR_HERALDRY  . 'park-banner/');
define('DIR_KINGDOM_BANNER',  DIR_HERALDRY  . 'kingdom-banner/');
define('DIR_PLAYER_BANNER',   DIR_HERALDRY  . 'player-banner/');
define('DIR_UNIT_BANNER',     DIR_HERALDRY  . 'unit-banner/');
```

Filenames: zero-padded ID + `.jpg` or `.png`. Resolution via `Common::resolve_image_ext($dir, $name)`. Cache-bust via `?v=filemtime()`. On upload, delete both `.jpg` and `.png` before writing — no orphaned legacy files.

## Model hydration

In each `system/lib/ork3/class.{Park,Kingdom,Player,Unit}.php`, the existing "get details" method gains a raw-SQL hydration step for the five new fields (Yapo schema cache may not see them):

```php
$DB->Clear();   // memory rule: required before raw Execute/DataSet
$bn = $DB->DataSet("SELECT has_banner, banner_show_logo, banner_vignette,
                           banner_offset_x, banner_offset_y
                    FROM ork_<table>
                    WHERE <id_col> = " . (int)$id);
if ($bn && $bn->Next()) {
    $result[...]['HasBanner']      = (int)$bn->has_banner;
    $result[...]['BannerShowLogo'] = (int)$bn->banner_show_logo;
    $result[...]['BannerVignette'] = (int)$bn->banner_vignette;
    $result[...]['BannerOffsetX']  = (int)$bn->banner_offset_x;
    $result[...]['BannerOffsetY']  = (int)$bn->banner_offset_y;
}
```

Per entity, place `Banner*` keys next to wherever the existing `HasHeraldry` already sits:

| Entity | Table / ID column | Existing `HasHeraldry` location | Place `Banner*` keys at |
|---|---|---|---|
| Park | `ork_park` / `park_id` | `$response['ParkInfo']['HasHeraldry']` (`class.Park.php:458`) and flat `$response['HasHeraldry']` (line 483) | both spots, mirroring |
| Kingdom | `ork_kingdom` / `kingdom_id` | `$response['KingdomInfo']['HasHeraldry']` (`class.Kingdom.php:33`) | `$response['KingdomInfo']` |
| Player | `ork_mundane` / `mundane_id` | flat `'HasHeraldry' => $this->mundane->has_heraldry` (`class.Player.php:329`) | flat in the same array |
| Unit | `ork_unit` / `unit_id` | `$response['Unit']['HasHeraldry']` (`class.Unit.php:170`) | `$response['Unit']` |

## Backend AJAX

Three sub-actions dispatched via URL tail. Endpoints:

```
POST  ParkAjax/banner/{id}/update     multipart       — save image + config
POST  ParkAjax/banner/{id}/config     form-urlenc     — save config only
POST  ParkAjax/banner/{id}/remove                     — delete + reset

POST  KingdomAjax/banner/{id}/...     (same three)
POST  PlayerAjax/banner/{id}/...      (same three)
POST  UnitAjax/banner/{id}/...        (same three)   ← UnitAjax is a new file
```

Each method body is a near-copy of `EventAjax::banner()` (lines 977–1095 in `controller.EventAjax.php`), with the entity-specific table name, ID column, edit-scope check, and constants substituted. Key invariants from the source pattern, retained:

1. **Auth check first.** Reject before reading any input.
2. **File type allowlist.** Only `image/jpeg` and `image/png`. Anything else → status 1 with error.
3. **Verify-after-write rollback.** After `UPDATE`, run `$DB->Clear()` then re-`DataSet` to confirm `has_banner = 1`. If the read doesn't match, `@unlink` the saved image and return error. Saves us from `sql_mode=STRICT` silent failures (memory rule).
4. **`remove` resets framing + toggles.** Resetting offsets to 50/50, show_logo to 1, vignette to 1 means next upload starts clean.
5. **`config` requires `has_banner = 1`.** Don't let "save settings only" pass against a missing image — return a meaningful error.

For `controller.UnitAjax.php` (new file): skeleton mirrors `controller.PlayerAjax.php`'s class shell (extends `Controller`, has an `__init__`-style constructor if Player has one). Only the `banner()` method is implemented in this PR.

## Template wiring

Each of the four templates gets the same four insertions, mechanically copied with the entity prefix swapped.

### 1. Top-of-file resolution (after existing heraldry block)

```php
$hasBanner       = !empty($info['HasBanner']);
$bannerShowLogo  = !isset($info['BannerShowLogo']) || (int)$info['BannerShowLogo'] !== 0;
$bannerVignette  = !isset($info['BannerVignette']) || (int)$info['BannerVignette'] !== 0;
$bannerOffsetX   = isset($info['BannerOffsetX']) ? max(0, min(100, (int)$info['BannerOffsetX'])) : 50;
$bannerOffsetY   = isset($info['BannerOffsetY']) ? max(0, min(100, (int)$info['BannerOffsetY'])) : 50;
$bannerUrl       = '';
if ($hasBanner) {
    $bannerFile = Common::resolve_image_ext(DIR_<ENTITY>_BANNER, sprintf('%0<N>d', $entityId));
    $bannerFs   = DIR_<ENTITY>_BANNER . $bannerFile;
    if (file_exists($bannerFs)) {
        $bannerUrl = HTTP_<ENTITY>_BANNER . $bannerFile . '?v=' . filemtime($bannerFs);
    }
}
```

`<N>` is the entity's padding width (Park=5, Kingdom=4, Player=6, Unit=5).

### 2. Hero element assembly

Each template currently renders its hero like:

```html
<div class="<prefix>-hero">
    <div class="<prefix>-hero-bg" style="background-image:url(...)"></div>
    ... hero content (logo, title, actions) ...
</div>
```

Reshape to apply banner classes conditionally + add edit pill (full reference: `Eventnew_index.tpl` lines 617–644). Apply `background-position: X% Y%` only when a banner is set (heraldry doesn't need offsets).

### 3. JS config block

Add `<script>` near existing entity-config block (e.g. `PkConfig` / `KnConfig` / `PnConfig` / `UnConfig`) surfacing:

```js
<prefix>BannerConfig = {
    uir:            '<?= UIR ?>',
    canManage:      <?= $canManage ? 'true' : 'false' ?>,
    entityId:       <?= $entityId ?>,
    hasBanner:      <?= $hasBanner ? 'true' : 'false' ?>,
    bannerShowLogo: <?= $bannerShowLogo ? 'true' : 'false' ?>,
    bannerVignette: <?= $bannerVignette ? 'true' : 'false' ?>,
    bannerOffsetX:  <?= (int)$bannerOffsetX ?>,
    bannerOffsetY:  <?= (int)$bannerOffsetY ?>,
    bannerUrl:      <?= json_encode($bannerUrl) ?>,
};
```

Why a separate `*BannerConfig` instead of mutating the existing `*Config`: `revised.js` IIFEs already use existing config flags as their guards (memory rule). A separate object keeps the banner IIFE's guard explicit and avoids touching anyone else's config.

### 4. Modal markup

Bottom of each template (before `</body>`), mirroring `Eventnew_index.tpl:2240–2400`. Four step panes (`select` / `position` / `uploading` / `success`), two SVG wireframes (desktop + mobile), file picker, toggles, action row.

## CSS

Per-entity prefixed blocks added to `orkui/template/revised-frontend/style/revised.css` — all four templates load this file. Each block is a copy of the event banner CSS (lines 4014–4160 in `revised.css`) with `ev-` replaced by the entity prefix. Total: ~150 lines × 4 = ~600 lines added.

Key invariants retained verbatim per copy:
- The `inset: -10px` + `blur(6px)` + `opacity: 0.14` "atmospheric backdrop" mode when no banner is set.
- The two-pseudo-element vignette (gradient `::before`, masked backdrop-filter `::after`).
- The hover-revealed edit pill with `@media (hover: none)` fallback at `opacity: 0.85` for touch.
- Mobile breakpoint (`@media (max-width: 540px)`) collapses the pill to icon-only.

## JavaScript

Per-entity prefixed IIFEs added to `orkui/template/revised-frontend/script/revised.js`. Each IIFE is a copy of the event banner IIFE (`revised.js:15054–15424`), with config name + URL prefix swapped:

```js
(function() {
    if (typeof PkBannerConfig === 'undefined' || !PkBannerConfig.canManage) return;
    var UPLOAD_URL = PkBannerConfig.uir + 'ParkAjax/banner/' + PkBannerConfig.entityId + '/update';
    // ...etc, ~370 LOC per entity
})();
```

Functions exposed on window for inline `onclick` handlers, prefix-namespaced: `pkOpenBannerModal()`, `knOpenBannerModal()`, `pnOpenBannerModal()`, `unOpenBannerModal()`.

Total: ~370 LOC × 4 = ~1500 lines added.

Math, drag handlers, resize-before-upload (via existing `resizeImageToLimit` helper in `orkui.js:14026`), step state machine — all unchanged from the event implementation.

## Order of work

To get an end-to-end working slice before broad rollout, work the four entities **mostly in parallel** but stage each entity's vertical slice in the same order:

1. **Schema migration + storage constants + storage dirs** — single PR-internal commit. Verify with manual `UPDATE`.
2. **Model hydration per entity** — four parallel agents, one each. Confirm with `die(json_encode($info))` debug (memory rule: debug via console/json, not error_log).
3. **Read-only template render per entity** — four parallel agents. Skip modal + JS. Hard-code `has_banner=1` in DB; verify CSS renders.
4. **Backend AJAX per entity** — four parallel agents (one creates `UnitAjax`, three extend existing). Test each with `curl`.
5. **Modal + JS per entity** — four parallel agents.
6. **Dark mode polish + mobile breakpoints** — single pass across all four (memory rule: walk every new surface in dark mode before declaring done).

Per memory: dispatch parallel agents for steps 2–5 in a single message, one agent per entity.

## Acceptance criteria

For each of {Park, Kingdom, Player, Unit}:

1. Logged-in user with edit scope sees a "Add Banner Image" pill on hover (or always-faintly on touch); user without scope sees no pill.
2. Clicking the pill opens the modal with two wireframes + toggles + file picker.
3. Uploading a JPEG or PNG saves the file, sets `has_banner=1` and the row's offsets, and the page reloads showing the banner with default centered framing.
4. The "Adjust Image Framing" path round-trips: drag → percentages persist → reload renders at the new framing — without re-uploading.
5. The "Save settings only" path toggles `banner_show_logo` and `banner_vignette` and reloads.
6. The "Remove Banner" path deletes the file (both `.jpg` and `.png` extensions), resets `has_banner=0` and all four other columns to defaults, and reloads.
7. Dark mode renders correctly on the modal (no `orkui.css` h1–h6 pill leak, ghost button text legible).
8. Mobile breakpoint renders correctly: icon-only edit pill, modal scrolls within viewport.
9. The vignette + drop-shadow legibility holds on the chosen 1800×240 banner — no logo/title text gets lost.

## Files touched

**New:**
- `db-migrations/2026-05-17-add-entity-banners.sql`
- `orkui/controller/controller.UnitAjax.php`
- Storage dirs (created at deploy): `assets/heraldry/{park,kingdom,player,unit}-banner/`

**Modified:**
- `config.dev.php`, `config.dist.php` — 8 new constants
- `system/lib/ork3/class.Park.php`, `class.Kingdom.php`, `class.Player.php` (reads from `ork_mundane`), `class.Unit.php` — hydrate banner fields
- `orkui/controller/controller.ParkAjax.php`, `controller.KingdomAjax.php`, `controller.PlayerAjax.php` — add `banner()` method
- `orkui/template/revised-frontend/Parknew_index.tpl`, `Kingdomnew_index.tpl`, `Playernew_index.tpl` — top resolution, hero assembly, JS config, modal markup
- `orkui/template/default/Unit_index.tpl` — same four insertions
- `orkui/template/revised-frontend/style/revised.css` — four prefixed banner CSS blocks
- `orkui/template/revised-frontend/script/revised.js` — four prefixed banner IIFEs

**Total estimated size:** ~2700 LOC added (most of it being duplicated CSS/JS/modal markup), single bundled PR titled `Enhancement: Hero Banner — Park/Kingdom/Player/Unit` (per PR-title memory rule).

## Risks / gotchas (carried forward from Event handoff)

- `HasBanner` may be missing from Yapo's cached schema → raw `$DB->DataSet` in hydration getters.
- `sql_mode=STRICT` can silently fail writes → verify-after-write rollback.
- `$DB->Clear()` before every raw `Execute`/`DataSet` (project-wide rule).
- Delete both `.jpg` and `.png` before writing on upload.
- `?v=filemtime()` cache-bust required.
- `backdrop-filter` browser support uneven → gradient does the heavy lifting; blur is enhancement.
- `touch-action: none` on the drag container + `{ passive: false }` on touch listeners.
- Player edit-scope is non-trivial (self / park officer / kingdom officer / admin) — implement once in a helper if the duplication grows.
- Dark mode walk-through is non-optional (memory rule).
- Never use native browser tooltips (`title=""`) — use the `data-tip` pattern.
- Never use native `confirm()` — use the project's modal-confirm pattern.

## Out of scope (deliberate)

- Animations / hover effects on the banner image itself.
- Multi-banner / seasonal rotation.
- Cropping aspect ratios other than 7.5:1.
- AI-suggested framing.
- Sharing one banner across multiple records.
- Adding banners to additional entities (Event Type, Tournament, Award) — separate work if desired.
