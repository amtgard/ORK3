# DS-03: Hero Banner CRUD — Discovery Design Note

**Milestone:** DS-03  
**Branch:** `megiddo/ds-03-banner-discovery`  
**Target IDs:** T-PLA-06, T-PRA-04, T-KNA-08, T-UNT-01, T-EVA-14  
**Depends on:** M0.1 (test framework), DS-02 (complete)  
**Execution sprint:** R-03
**Test sprint:** T-03

---

## 1. Backend survey

### 1.1 Scope summary

Hero banner **writes** (upload, config, remove) are implemented **five times** in `orkui/controller/*Ajax.php` with nearly identical logic. The backend **reads** banner metadata when hydrating entity responses (`GetPlayer`, `GetPark`, `GetKingdom`, `GetUnit`, `GetEvent`) but exposes **no write API** in `orkservice/*`.

This is the same duplication pattern as heraldry before `HeraldryService` — except banners use separate filesystem directories (`DIR_*_BANNER`) and five DB tables instead of heraldry columns + `has_heraldry`.

### 1.2 Database schema

Migration [`db-migrations/2026-05-17-add-entity-banners.sql`](../../../db-migrations/2026-05-17-add-entity-banners.sql) adds identical columns to `ork_park`, `ork_kingdom`, `ork_mundane`, `ork_unit`, and (separately) `ork_event`:

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `has_banner` | TINYINT(1) | 0 | Banner image on disk exists |
| `banner_show_logo` | TINYINT(1) | 1 | Overlay entity heraldry/logo |
| `banner_vignette` | TINYINT(1) | 1 | Left/bottom vignette effect |
| `banner_offset_x` | TINYINT UNSIGNED | 50 | CSS background-position-x % |
| `banner_offset_y` | TINYINT UNSIGNED | 50 | CSS background-position-y % |

**Player-only side effect:** upload clears `ork_mundane_design.hero_gradient` (AmtPride gradient) so the banner image takes visual precedence.

**Park-only guard:** `update` and `config` refuse changes when `ork_park.active != 'Active'`; `remove` is still allowed on retired parks.

### 1.3 Filesystem layout

Config constants in `config.dev.php` / `config.dist.php`:

| Entity | HTTP constant | DIR constant | Filename pattern |
|--------|---------------|--------------|------------------|
| Player | `HTTP_PLAYER_BANNER` | `DIR_PLAYER_BANNER` | `{mundane_id:06d}.{jpg\|png}` |
| Park | `HTTP_PARK_BANNER` | `DIR_PARK_BANNER` | `{park_id:05d}.{jpg\|png}` |
| Kingdom | `HTTP_KINGDOM_BANNER` | `DIR_KINGDOM_BANNER` | `{kingdom_id:04d}.{jpg\|png}` |
| Unit | `HTTP_UNIT_BANNER` | `DIR_UNIT_BANNER` | `{unit_id:05d}.{jpg\|png}` |
| Event | `HTTP_EVENT_BANNER` | `DIR_EVENT_BANNER` | `{event_id:05d}.{jpg\|png}` |

Physical path: `assets/heraldry/{entity}-banner/` under `DIR_HERALDRY`.

### 1.4 Frontend violations (target IDs)

All five targets expose the same three actions via path segment: `{EntityAjax}/banner/{id}/{action}` where `action` ∈ `update`, `config`, `remove`.

| ID | Controller | Method | Lines | Entity table | Auth gate |
|----|------------|--------|-------|--------------|-----------|
| T-PLA-06 | `Controller_PlayerAjax` | `banner` | 832–999 | `ork_mundane` (+ `mundane_design`) | Self, park EDIT, kingdom EDIT, or global admin |
| T-PRA-04 | `Controller_ParkAjax` | `banner` | 657–805 | `ork_park` | `AUTH_PARK` / `$park_id` / `AUTH_EDIT` |
| T-KNA-08 | `Controller_KingdomAjax` | `banner` | 1225–1364 | `ork_kingdom` | `AUTH_KINGDOM` / `$kingdom_id` / `AUTH_EDIT` |
| T-UNT-01 | `Controller_UnitAjax` | `banner` | 9–149 | `ork_unit` | `AUTH_UNIT` / `$unit_id` / `AUTH_EDIT` |
| T-EVA-14 | `Controller_EventAjax` | `banner` | 1741–1883 | `ork_event` | `AUTH_EVENT` / `AUTH_EDIT` **or** any `event_staff.can_manage` on event |

**Shared write behavior (all five):**

1. **`remove`** — UPDATE entity row: reset all banner columns to defaults; verify `has_banner = 0` (player/park/kingdom/unit); delete `.jpg`/`.png` from disk; JSON `{status:0}`.
2. **`config`** — Require `has_banner = 1`; UPDATE show_logo, vignette, offset_x/y (clamped 0–100); verify re-read (player/park/kingdom/unit); JSON `{status:0}`.
3. **`update`** — Validate upload: `is_uploaded_file`, max 1 MB, `exif_imagetype` JPEG/PNG only; delete prior files; `move_uploaded_file`; UPDATE `has_banner = 1` + config columns; verify `has_banner = 1` or rollback file (player/park/kingdom/unit).

**Event-specific (T-EVA-14):**

- Calls `_bustEventSearchCache($event_id)` after config/remove/update (SearchService memcache).
- `remove`/`config` **lack** the post-UPDATE verify pattern used on other entities (behavioral gap — R-03 should unify via domain layer).

**Related frontend code (out of DS-03 targets, same subsystem):**

| Location | Purpose |
|----------|---------|
| `Controller_EventAjax::create_with_copy` | Copies banner file + DB flags when `modules.banner` set (T-EVA-13 — R-04) |
| Template/JS (`revised.js`) | Banner modal IIFEs per entity prefix (`pk-`, `kn-`, `pn-`, `un-`, event) |

### 1.5 Backend surface (read-only today)

| Layer | Location | Role |
|-------|----------|------|
| Domain | `class.Player.php` | Hydrates `HasBanner`, `BannerShowLogo`, … in `GetPlayer` response |
| Domain | `class.Park.php` | Same in park info |
| Domain | `class.Kingdom.php` | Same in kingdom info |
| Domain | `class.Unit.php` | Same in unit info |
| Domain | `class.Event.php` | Same in `GetEvent` |
| Domain | `class.SearchService.php` | Embeds banner fields in event search rows |
| Domain | `class.Report.php` | Admin “feature usage” counts for `has_banner = 1` |
| Service | `orkservice/*` | **No banner write methods** |

**Clean reference for file+DB writes:** `class.Heraldry.php` + `HeraldryService` — token auth, `HasAuthority`, base64 image payload, `store_heraldry()`, yapo save. Event heraldry upload in `Controller_EventAjax::heraldry` already delegates to `Ork3::$Lib->heraldry->SetEventHeraldry` (T-EVA-11 partial — R-04).

### 1.6 Existing test coverage

| Asset | Status |
|-------|--------|
| PHPUnit `tests/` | **No** banner tests |
| Service test rigs | **No** banner coverage |
| Frontend | Manual only (banner modal flows) |

### 1.7 Behavioral gaps (frontend duplication)

| Topic | Current state | R-03 should |
|-------|---------------|-------------|
| Verify-after-UPDATE | Event banner lacks I4 verify on remove/config | Unify in domain |
| Cache bust | Event only | Keep in domain or controller hook |
| Player gradient clear | Player upload only | Preserve in `SetPlayerBanner` |
| Park retired guard | Park only | Preserve in `SetParkBanner` |
| Staff-delegated event auth | Event only | Preserve in domain auth helper |
| Duplicate ~120-line blocks | 5 controllers | Single domain + thin JSON adapters |

### 1.8 Gaps

- No `BannerService` or `class.Banner.php`.
- No SOAP/JSON API for multipart upload — heraldry uses base64 in SOAP; banners today use `$_FILES` in controllers. R-03 must choose: base64 in API (match heraldry) or a dedicated upload endpoint shape documented in definitions.
- Event banner **copy** during `create_with_copy` is file+SQL in controller — should call shared `CopyBanner` domain helper in R-04 when T-EVA-13 is executed.

---

## 2. Test design

### 2.1 Backend unit/integration tests (implement in T-03)

Add `tests/Integration/BannerTest.php` (DB + temp banner dirs required):

| Test case | Validates |
|-----------|-----------|
| `testSetParkBannerUpload` | Authorized park EDIT; JPEG saved; `has_banner = 1`; offsets persisted |
| `testSetParkBannerRejectsRetiredPark` | Inactive park → error; no file written |
| `testRemoveParkBannerResetsDefaults` | `has_banner = 0`; defaults restored; file deleted |
| `testUpdateParkBannerConfig` | Config without banner → error; with banner → toggles saved |
| `testSetPlayerBannerClearsHeroGradient` | Upload sets `mundane_design.hero_gradient = NULL` |
| `testSetKingdomBannerAuthRejectsNonEditor` | No EDIT → `NoAuthorization` |
| `testSetUnitBannerRejectsOversize` | > 1 MB → `InvalidParameter` |
| `testSetEventBannerBustsSearchCache` | Mock or spy `ghettocache->bust_event_search` called |
| `testSetEventBannerStaffCanManage` | Staff with `can_manage` (no event EDIT) can upload |
| `testRemoveBannerVerifyRollback` | Simulated failed UPDATE does not delete file |

Use seeded park/kingdom/unit/player/event IDs from dev DB; create files under test-scoped subdirs or clean up in `tearDown()`. Skip when `ork3_test_db_available()` is false.

### 2.2 Service-layer tests

Invoke `BannerSetBanner`, `BannerRemoveBanner`, `BannerUpdateConfig` from `BannerService.function.php` after bootstrap to verify SOAP wrapper parity with domain.

### 2.3 Infection scope (T-03, DS-7)

| Source filter | PHPUnit filter |
|---------------|----------------|
| `--filter=class.Banner.php` | `--test-framework-options="--filter=BannerTest"` |

Include `BannerService.function.php` once tests exercise the wrapper.

```bash
sh bin/run-infection.sh \
  --filter=class.Banner.php \
  --test-framework-options="--filter=BannerTest"
```

Target ≥ `minMsi` / `minCoveredMsi` (15) from `infection.json5`.

### 2.4 Frontend functional tests (implement in T-03)

Playwright/Cypress against `http://localhost:19080/orkui/` per [06-test-framework.md](./06-test-framework.md):

| Flow | Steps | Assert |
|------|-------|--------|
| Park banner upload | Login as park admin → park profile → banner modal → upload PNG → save framing | Hero shows image; reload persists offsets |
| Park banner remove | Remove banner | Default masthead; `has_banner` off |
| Player self-banner | Login as player → own profile → upload | Banner visible; gradient cleared if was set |
| Kingdom banner config | Toggle logo off / vignette off → save | UI reflects toggles after reload |
| Event banner (staff) | Event manager (staff can_manage) uploads banner | Search/grid shows banner after cache bust |
| Unauthorized upload | Non-editor POST to `{Entity}Ajax/banner/{id}/update` | JSON error; no file on disk |

---

## 3. Proposed revision

### 3.1 Principle

Introduce **`class.Banner.php`** (domain) and **`BannerService`** (API surface), mirroring `HeraldryService` dispatch by entity `Type`. Controllers become thin JSON adapters: validate session, map POST/FILES to service request, map `Status` → JSON `status`.

Consolidate the five duplicated controller methods into **one shared helper trait or base method** only if it stays thinner than calling the service — prefer direct `Model_Banner` → `APIModel('Banner')` calls per DS-01/DS-02 patterns.

### 3.2 New domain API (R-03)

| Method | Request keys | Behavior |
|--------|--------------|----------|
| `SetBanner` | `Token`, `Type`, `Id`, `Banner` (base64), `BannerMimeType`, `ShowLogo`, `Vignette`, `OffsetX`, `OffsetY` | Auth per entity; validate image; store file; UPDATE row; player gradient clear; event cache bust |
| `UpdateBannerConfig` | `Token`, `Type`, `Id`, `ShowLogo`, `Vignette`, `OffsetX`, `OffsetY` | Require `has_banner`; UPDATE + verify |
| `RemoveBanner` | `Token`, `Type`, `Id` | Reset columns; verify; unlink files; event cache bust |
| `CopyBanner` (internal) | `Type`, `SourceId`, `TargetId` | File copy + row copy — **called from R-04** `create_with_copy`, not exposed to frontend directly |

Entity `Type` values: `Player`, `Park`, `Kingdom`, `Unit`, `Event` (parallel `HeraldrySetHeraldry`).

Auth mapping:

| Type | Domain `HasAuthority` |
|------|----------------------|
| Player | Self OR park EDIT OR kingdom EDIT OR admin |
| Park | `AUTH_PARK`, `Id`, `AUTH_EDIT` + active park check on set/config |
| Kingdom | `AUTH_KINGDOM`, `Id`, `AUTH_EDIT` |
| Unit | `AUTH_UNIT`, `Id`, `AUTH_EDIT` |
| Event | `AUTH_EVENT`, `Id`, `AUTH_EDIT` OR staff `can_manage` on any detail |

### 3.3 Per-target replacement (R-03)

| ID | File | Change |
|----|------|--------|
| T-PLA-06 | `Controller_PlayerAjax::banner` | Replace `$DB` + file I/O with `Model_Banner` → `BannerService`; keep JSON shape `{status, error?}` |
| T-PRA-04 | `Controller_ParkAjax::banner` | Same |
| T-KNA-08 | `Controller_KingdomAjax::banner` | Same |
| T-UNT-01 | `Controller_UnitAjax::banner` | Same |
| T-EVA-14 | `Controller_EventAjax::banner` | Same; cache bust moves into domain |

Controller upload path: read `$_FILES['Banner']`, base64-encode for service call (same as event heraldry update today).

### 3.4 Files to add (R-03)

| Path | Purpose |
|------|---------|
| `system/lib/ork3/class.Banner.php` | Domain logic |
| `orkservice/Banner/BannerService.php` | Service entry |
| `orkservice/Banner/BannerService.function.php` | `BannerSetBanner`, etc. |
| `orkservice/Banner/BannerService.definitions.php` | WSDL types |
| `orkservice/Banner/BannerService.registration.php` | SOAP registration |
| `orkui/model/model.Banner.php` | Thin `APIModel('Banner')` wrapper |
| `tests/Integration/BannerTest.php` | Integration tests |

Register `Banner` in `orkservice/Json/index.php` for JSON API parity.

### 3.5 Out of scope for R-03

| Item | Deferred to |
|------|-------------|
| Banner file copy in `create_with_copy` | R-04 (T-EVA-13) via `CopyBanner` |
| Banner read hydration in `Get*` responses | Already backend-side — no change |
| `SearchService` banner fields | Already backend — no change |
| Frontend JS/CSS | Presentation — unchanged except endpoint still hits same routes |
| Heraldry upload (`Controller_*::heraldry`) | Separate; event heraldry partial fix in R-04 (T-EVA-11) |

### 3.6 Execution order (R-03)

1. Implement `class.Banner.php` with unit-testable static helpers (path resolution, validation).
2. Add integration tests against domain directly.
3. Wire `BannerService` + `Model_Banner`.
4. Replace one controller (e.g. `UnitAjax` — smallest auth) and verify E2E.
5. Replace remaining four controllers; delete all banner `$DB->Execute` from `orkui/`.
6. Run milestone-scoped Infection; full suite green.

---

## Related documents

| Doc | Link |
|-----|------|
| Hero banner port spec | [`docs/superpowers/specs/2026-05-17-hero-banner-port-design.md`](../../superpowers/specs/2026-05-17-hero-banner-port-design.md) |
| Implementation plan | [03-implementation-plan.md](./03-implementation-plan.md) |
| Test framework | [06-test-framework.md](./06-test-framework.md) |
