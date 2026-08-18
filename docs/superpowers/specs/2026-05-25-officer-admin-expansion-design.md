# Officer Admin Expansion — Design Spec & Phased Implementation Plan

- **Date:** 2026-05-25
- **Branch:** `feature/officer-admin-expansion`
- **Status:** Design spec (no code). Implementation plan attached.
- **Scope:** ORK3 (PHP/MySQL, Amtgard ORK v3). Three layers: `orkui/` (MVC frontend), `orkservice/` (SOAP), `system/lib/ork3/` (shared logic).

> This document is authoritative against the locked product decisions. Where the gap analysis, backend design, and frontend design conflict with the locked decisions, the locked decisions win.

---

## 1. Overview & Goals

Today the officer system is hardcoded to five fixed roles (Monarch, Regent, Prime Minister, Champion, GMR) baked into ENUMs, ORDER-BY-FIELD lists, static `<select>`s, JS arrays, and an RBAC string map. Kingdoms cannot define their own offices, cannot rename offices for local culture, and the GMR role is only half-wired (latent data-integrity bug). This feature makes officer positions **first-class, kingdom-extensible, alias-able, and RBAC-bound data**.

The core requirements, restated crisply (these consolidate the locked product decisions; the data-model/RBAC mechanics from decisions 2 and 9 are detailed in §3–§5, and the award-taxonomy policy from decision 10 follows below):

1. **First-class position registry.** Introduce `ork_officer_position` as the canonical source of truth for officer offices. Migrate `ork_officer.role` and `ork_officer_history.role` from ENUM to VARCHAR; add a `position_id` FK; backfill; retain `role` varchar as a denormalized cache during a dual-write period.
2. **Stable canonical keys.** Every position has a `canonical_key` that is **never renamed**; all code/auth/RBAC logic keys on it. Display title can change without breaking logic.
3. **Title aliasing everywhere.** `DisplayTitle = IF(title_alias != '', title_alias, title)` (NOT `COALESCE` — the yapo `''`-clear rule means a cleared alias is the empty string, not `NULL`, and `COALESCE` treats `''` as non-null; see NIT-1 in §3.1/§7.3). Code keys on `canonical_key`; all UI renders `DisplayTitle`; read APIs return **both**.
4. **Core Five pinned to Crown.** `monarch`, `regent`, `prime_minister`, `champion`, `gmr` are pinned: cannot change classification, cannot be retired, cannot have their RBAC role changed. They CAN be aliased and have occupants/terms set. Enforced in **both** UI (disabled, not hidden) and server-side.
5. **Holder/occupancy rules.** Crown = exactly one occupant per position per scope, AND at most one Crown office per person globally across all kingdom+park scopes. Supporting = unlimited holders, no concurrency limit, may coexist with a Crown role.
6. **Retire/reinstate with auto-vacate + warning.** Retiring a position auto-vacates current occupant(s) (close term, remove synced `ork_user_role`); UI warns first. Pinned/system positions cannot be retired. Retired positions are filtered out of pickers, sidebar, and About panel server-side. Reinstate clears `retired_at` and restores prior classification.
7. **History shows title-at-time-of-service.** Store a snapshot display label on each history row; never retroactively rename.
8. **RBAC binding per position.** Each position links to an `ork_role` via `rbac_role_id`. Core Five reuse seeded system roles. Custom positions reuse an existing role OR build a custom permission set (upserts a kingdom-scoped `ork_role`). Replace the hardcoded `PermissionRegistry::$officerRoleMap` path with a DB-backed `position -> role_id` lookup; keep the legacy string path as fallback during dual-write.

Plus the cross-cutting policy: **`award.officer_role` taxonomy is independent** — title-granting awards keep their stored role string; aliasing is display-only and does not retroactively alter historical award titles (requirement 10 of the locked decisions).

---

## 2. Current-State Summary & Hard Blockers

### Current schema & wiring
- `ork_officer.role` is `enum('Monarch','Regent','Prime Minister','Champion')` (`ork.sql:594`), with `UNIQUE KEY (kingdom_id, park_id, role)` (`ork.sql:599`). `mundane_id = 0` means vacant. `authorization_id` links `ork_authorization` for Monarch/Regent/PM only; Champion/GMR bypass that path.
- `ork_officer_history` (`migrations/officer_history.sql:9`) has `role enum('Monarch','Regent','Prime Minister','Champion','GMR')` — its ENUM **already includes `'GMR'`** — plus `start_date / end_date / changed_by / notes`; written by `record_officer_history` in `common.php`.
- **GMR latent enum-coercion bug — `ork_officer.role` ONLY:** GMR is created and special-cased in `common.php`, but `'GMR'` is **not** a valid value in the `ork_officer.role` ENUM (`ork.sql:594` = `enum('Monarch','Regent','Prime Minister','Champion')`, no GMR). It only "works" in dev because `sql_mode=''` coerces the invalid ENUM write to `''`. This coercion bug is specific to `ork_officer.role`; `ork_officer_history.role` does **not** have it because its ENUM already lists GMR. **Both columns still need widening to VARCHAR** (for arbitrary kingdom-custom positions), but for different reasons: `ork_officer.role` to fix the coercion bug AND to allow custom keys; `ork_officer_history.role` only to allow custom keys.

### Hard blockers (must be removed/replaced)
- **(a)** `ork_officer.role` ENUM blocks arbitrary positions — `ork.sql:594`.
- **(b)** `ork_officer_history.role` ENUM — same problem, `migrations/officer_history.sql`.
- **(c)** Hardcoded `PermissionRegistry::$officerRoleMap` (`class.PermissionRegistry.php:318-324`) — custom positions resolve to `null`, so `SyncOfficerRole` silently grants nothing (`class.RBACService.php:696-700`, where `null` triggers an early `return` after a `logtrace`).
- **(d)** One-way RBAC sync — officer→user_role only, on `set_officer` in `common.php`; nothing reconciles the reverse direction or position→role rebinds.
- **(e)** Hardcoded `ORDER BY FIELD(o.role, 'Monarch','Regent','Prime Minister','Champion','GMR')` in `class.Kingdom.php:583` and `class.Park.php:396`.
- **(f)** `UNIQUE(kingdom_id, park_id, role)` (`ork.sql:599`) — cannot remain a blanket unique once supporting positions allow multiple holders.
- **(g)** `Reports_kingdomofficerdirectory.tpl` — fully hardcoded vacancy counters and per-role columns (`:10-16, :71-87, :102-129`). This is a **dynamic-column rewrite**, not a tweak.
- **(h)** Static role `<select>`s in `Kingdomnew_index.tpl` (711-715, 985-989, 1040-1044) and `Parknew_index.tpl` (1146-1150, 1381-1385, 1436-1440), plus JS `OFFICER_ROLES` arrays in `revised.js` (3928, 9125).
- **(i)** Parallel `award.officer_role` taxonomy in `controller.Player.php` (368, 434, 671) — independent; do not unify.

### Title consumption sites (alias must reach all of these — enumerated in §6)
Backend: `class.Kingdom.php:583,602,613`; `class.Park.php:381`; `controller.Player.php:397-419`; `common.php:480,486,558-568`; `PermissionRegistry:318-324`.
Sidebars: `Kingdom_index.tpl:17,20`; `Park_index.tpl:25,28`; `Kingdomnew_index.tpl:29-30,106,183`; `Parknew_index.tpl:20-21,250,424`.
About/lists: `Kingdomnew_index.tpl:180-193,1203,2778,2909`; `Parknew_index.tpl:907-957,1054-1055,1734`; `Playernew_index.tpl:1056-1057,1479-1482,2783,2927`.
Dropdowns: `Admin_setofficers.tpl:113-124` + the selects above.
JS matching: `revised.js:3928,9125,3938,9135,4055,9245` + officerList JSON in `Kingdomnew_index.tpl:1091`, `Parknew_index.tpl:1621-1623`; PHP hero extraction `Kingdomnew_index.tpl:28-31`, `Parknew_index.tpl:18-22`.
Reports + permissions UI: `Reports_kingdomofficerdirectory.tpl` (above), `Admin_permissions.tpl:196`, `Admin_permissions_grid.tpl:333-357,405+`.

---

## 3. Data Model

### 3.1 New table: `ork_officer_position`

| Column | Type | Notes |
|---|---|---|
| `position_id` | int PK AUTO_INCREMENT | |
| `kingdom_id` | int NOT NULL | `0` = system (the Core Five seed rows); otherwise the owning kingdom's `kingdom_id`. **A principality is a kingdom** (`ork_kingdom.parent_kingdom_id > 0`) with its own globally-unique `kingdom_id`, so principality-custom positions are stored here under the principality's own `kingdom_id` — no separate principality dimension is needed (see §8 Principalities). |
| `canonical_key` | varchar(60) NOT NULL | **stable, never renamed**; lowercase snake_case; the key all code/auth/RBAC matches on |
| `title` | varchar(80) NOT NULL | default display title |
| `title_alias` | varchar(80) NOT NULL DEFAULT '' | **kingdom-custom rows only** (`kingdom_id=N`); clear via `''` not `null` (yapo null-skip rule). System Core-Five rows (`kingdom_id=0`) do **not** use this column for per-kingdom aliases — those live in the dedicated `ork_officer_position_alias` table (§3.6). |
| `classification` | enum('crown','supporting') NOT NULL | |
| `is_pinned` | tinyint(1) NOT NULL DEFAULT 0 | Core Five = 1 |
| `is_system` | tinyint(1) NOT NULL DEFAULT 0 | seeded system rows = 1 |
| `rbac_role_id` | int NOT NULL | FK → `ork_role.role_id` |
| `has_auth_role` | tinyint(1) NOT NULL DEFAULT 0 | replaces the `'Champion'==role \|\| 'GMR'==role` auth-bypass special-case in `common.php:486` |
| `sort_order` | int NOT NULL DEFAULT 100 | replaces `ORDER BY FIELD(...)` |
| `retired_at` | datetime NULL | non-null = retired |
| `created_by` | int NOT NULL DEFAULT 0 | |
| `created_at` | datetime NOT NULL | |

Constraints:
- `UNIQUE(kingdom_id, canonical_key)` — a kingdom cannot define two positions with the same key; the system seed (`kingdom_id=0`) holds the Core Five.
- Index `(kingdom_id, classification, retired_at, sort_order)` to back the grouped, filtered, ordered read path.

#### Companion table: `ork_officer_position_alias`

Per-kingdom aliases of the **shared system Core-Five rows** (`kingdom_id=0`) are stored in a dedicated alias table rather than as override rows. This avoids `is_system=0`/`is_pinned=1` ambiguity, prevents `rbac_role_id` drift (the single system row remains the source of truth for classification/RBAC/pin state), simplifies `GetPositions` to a single LEFT JOIN, and makes P7 cleanup trivial.

| Column | Type | Notes |
|---|---|---|
| `alias_id` | int PK AUTO_INCREMENT | |
| `kingdom_id` | int NOT NULL | the aliasing kingdom (never `0`) |
| `canonical_key` | varchar(60) NOT NULL | the Core-Five key being aliased (`monarch`, `regent`, `prime_minister`, `champion`, `gmr`) |
| `title_alias` | varchar(80) NOT NULL DEFAULT '' | the kingdom's local name; clear via `''` not `null` (yapo rule) |

- `UNIQUE(kingdom_id, canonical_key)` — one alias per kingdom per system key.

**DisplayTitle resolution rule (consistent everywhere — NIT-1):**
- **System Core-Five rows** (`kingdom_id=0`): `DisplayTitle = IF(a.title_alias != '', a.title_alias, p.title)`, where `a` is the `ork_officer_position_alias` row for `(this_kingdom, p.canonical_key)` (LEFT JOIN). The system row itself carries no per-kingdom alias.
- **Kingdom-custom rows** (`kingdom_id=N`): `DisplayTitle = IF(p.title_alias != '', p.title_alias, p.title)` — resolved from the row's own `title_alias` column.

Use `IF(... != '', ...)` (NOT `COALESCE`) in both the SQL and the PHP/service layer, because a cleared alias is the empty string `''` (yapo null-skip rule), and `COALESCE` would treat `''` as a present value and never fall back to `title`.

### 3.2 Changes to `ork_officer`
- Widen `role` from ENUM to `VARCHAR(80) NOT NULL`. **Retained as a denormalized cache** of `position.canonical_key` during dual-write; code reads from `position_id` once cutover lands (P3+), drops the cache in P7.
- Add `position_id INT NOT NULL DEFAULT 0` + index. FK-by-convention to `ork_officer_position.position_id` (MyISAM does not enforce FKs; enforce in app layer).
- **Drop** `UNIQUE(kingdom_id, park_id, role)` — replaced by app-level occupancy enforcement (§3.4). Add non-unique index `(kingdom_id, park_id, position_id)`.

### 3.3 Changes to `ork_officer_history`
- Widen `role` from ENUM to `VARCHAR(80) NOT NULL` to admit kingdom-custom canonical keys. (Note: this column's ENUM already includes `'GMR'` — `migrations/officer_history.sql:9` — so the widen here is **not** a GMR-coercion fix; that coercion bug is on `ork_officer.role` only. See MINOR-1 / §2 / §8.)
- Add `position_id INT NOT NULL DEFAULT 0`.
- Add `display_label VARCHAR(80) NOT NULL DEFAULT ''` — **snapshot of `DisplayTitle` at the moment the row was written** (requirement 7). History never re-resolves the alias; it renders `display_label` verbatim.

### 3.4 Occupancy enforcement (the critical schema driver)

MySQL/MariaDB (and the MyISAM engine in use) have **no partial unique indexes**, so single-occupancy for crown positions cannot be a column constraint when supporting positions on the same table must allow many rows. Enforcement is therefore **application-level**, in the service layer (`class.OfficerPosition::SetOfficerByPosition` / `common.php set_officer`), inside a transaction-equivalent guarded sequence:

1. **Crown single-occupant per scope.** Before inserting/assigning a crown occupant for `(kingdom_id, park_id, position_id)`, vacate any existing non-vacant occupant of that exact position+scope (close their term, remove synced `ork_user_role`). Net effect: at most one live `ork_officer` row with `mundane_id > 0` per crown `position_id` per scope. (Vacant placeholder rows with `mundane_id = 0` may exist; they do not count.)
2. **Crown-per-person global check.** Before assigning person P to any crown position, run a global query: does P already hold a crown office in **any** kingdom or park scope (join `ork_officer` → `ork_officer_position` where `classification='crown'` and `mundane_id = P` and the row is live and the position is not retired)? If yes, reject with a clear error ("This person already holds a Crown office: {DisplayTitle} in {scope}. A person may hold only one Crown office."). This spans kingdom + park crown positions — and because **a principality is a kingdom row** (its own `kingdom_id`, §8), principality crown offices are included automatically by the same `kingdom_id`-scoped query, with no extra logic.
3. **Advisory lock around the crown-per-person SELECT-then-INSERT (reliable enforcement, not aspirational).** The engine is **MyISAM** (confirmed at `ork.sql` — `ork_officer` is `ENGINE=MyISAM`, and GET_LOCK/RELEASE_LOCK are available on MariaDB), which has **no transactions and no partial unique indexes**, so the rule 2 check is a non-atomic SELECT-then-INSERT that two concurrent admins could race. `SetOfficerByPosition` MUST wrap the crown-per-person check **and** the subsequent `ork_officer` write in a MySQL advisory lock keyed per person: `SELECT GET_LOCK('crown_assign_' . (int)$mundane_id, $timeout)` before the SELECT, `RELEASE_LOCK(...)` in a `finally`. Keying on the incoming `mundane_id` serializes all concurrent crown assignments targeting the same person across every scope, which is exactly the window the global check must protect. Abort the assignment with a clear error if the lock is not acquired within `$timeout`. This is a hard requirement, not an optional mitigation.
4. **Supporting positions: no limits.** Multiple `ork_officer` rows for the same supporting `position_id` are allowed; no concurrency cap; freely coexist with a crown role. No global check; no advisory lock needed.

Because the blanket unique is gone, the **vacant-row convention** (`mundane_id = 0`) is preserved only where useful (Core Five seed rows per kingdom keep a vacant placeholder so directory columns render "vacant"). Supporting positions do not need placeholder rows — absence of rows = no holders.

### 3.5 Migration sequence (P1)
Run via the project's MariaDB path: `docker exec -i ork3-php8-db mariadb -u root -proot ork < migration.sql`.

1. `ALTER TABLE ork_officer MODIFY role VARCHAR(80) NOT NULL;`
2. `ALTER TABLE ork_officer_history MODIFY role VARCHAR(80) NOT NULL;` (admits custom keys; this column already lists GMR so it is not the coercion-fix step — the coercion fix is step 1 on `ork_officer.role`.)
3. `CREATE TABLE ork_officer_position (...)` per §3.1; `CREATE TABLE ork_officer_position_alias (...)` per §3.6.
4. Seed the 5 system positions (`kingdom_id=0`, `is_system=1`, `is_pinned=1`, `classification='crown'`), resolving `rbac_role_id` via subquery on `ork_role.name` (`monarch`, `regent`, `prime_minister`, `champion`, `gmr`). `has_auth_role`: 1 for the three that use `ork_authorization` today (monarch, regent, prime_minister) — **verify against `common.php:486` semantics during P1**; `champion`/`gmr` set per their actual auth-bypass behavior (they currently bypass `ork_authorization`, so `has_auth_role=0`). `sort_order`: 10/20/30/40/50. GMR `title='Guildmaster of Reeves'`.
5. `ALTER TABLE ork_officer ADD position_id INT NOT NULL DEFAULT 0;` then backfill: `UPDATE ork_officer o JOIN ork_officer_position p ON p.kingdom_id=0 AND p.canonical_key = <normalized o.role> SET o.position_id = p.position_id;` Normalization maps the legacy display strings → canonical keys (`'Prime Minister'`→`prime_minister`, `'GMR'`→`gmr`, etc.). Also normalize `o.role` itself to the canonical key so the cache is consistent post-migration.
6. `ALTER TABLE ork_officer_history ADD position_id INT NOT NULL DEFAULT 0, ADD display_label VARCHAR(80) NOT NULL DEFAULT '';` Backfill `position_id` the same way; backfill `display_label` from the historical `role` string (best-effort — it is already the title-at-time-of-service for legacy rows).
7. Drop `UNIQUE(kingdom_id, park_id, role)`; add `(kingdom_id, park_id, position_id)` index.
8. **Patch `common.php::create_officers()` / `create_officer()` (~556–569) as a P1 hard prerequisite (BUG-1).** These functions run on **every kingdom/park creation** and currently write **display-string** roles (`'Monarch'`, `'Prime Minister'`, `'GMR'`, …) with **no `position_id`**. Left unpatched, any kingdom/park created between this P1 migration and the P3 cutover would insert `ork_officer` rows with `position_id=0` and a display-string `role`, re-introducing exactly the inconsistency the backfill (steps 5–6) just removed. The patch must: resolve the system seed `position_id` from `canonical_key` and write it on each created row, and write the canonical key (not the display string) into the `role` cache. This lands in P1, not P2, because the migration's "every row has a non-zero `position_id`" invariant must hold for newly-created rows too, immediately. (The `set_officer`/`record_officer_history` edits remain in P2; only the seed-on-create path is hoisted to P1.)

   **Both branches of `create_officers` must be patched**, including the principality `system=1` branch (`common.php:563-569`). Note that `create_officer`'s `$principality_id` parameter is **dead** (verified: `common.php:572-598` never stores it — the `system=1` rows are written against the same `kingdom_id`/`park_id` as the kingdom rows and the param is ignored). Do not build new behavior on it; simply ensure every row `create_officer` writes — `system=0` and `system=1` alike — gets the resolved system `position_id` and canonical-key `role` cache, so no creation path can emit a `position_id=0` row.
9. Code cutover (P3+) reads via `position_id`; `role` varchar kept as cache through dual-write.

### 3.6 Per-kingdom alias of system positions (alias table)
A kingdom aliasing a Core-Five title creates/updates a row in the dedicated **`ork_officer_position_alias`** table (defined in §3.1): `(kingdom_id=N, canonical_key, title_alias = local name)`, `UNIQUE(kingdom_id, canonical_key)`. The shared `kingdom_id=0` system row is **never mutated or duplicated** — it remains the single source of truth for the Core Five's `classification`, `rbac_role_id`, `is_pinned`, and `has_auth_role`. Clearing an alias writes `title_alias=''` (yapo rule) or deletes the alias row; either way `DisplayTitle` falls back to the system row's `title`.

`GetPositions(kingdom_id)` resolves Core-Five `DisplayTitle` via a single LEFT JOIN to `ork_officer_position_alias` on `(kingdom_id, canonical_key)` using the §3.1 resolution rule. **Custom (`kingdom_id=N`) positions do NOT use this table** — their alias lives on their own row's `title_alias` column. This split is intentional and unambiguous: only the shared system rows are aliased via the table; every kingdom-owned row aliases itself.

Rationale for the alias table over kingdom-scoped override rows: no `is_system=0`/`is_pinned=1` ambiguity, no `rbac_role_id` drift, simpler `GetPositions` join, easier P7 cleanup. (This supersedes the earlier override-row proposal; there are no override rows anywhere in this design.)

### 3.7 Dual-write strategy
- During P2–P6, every officer write sets **both** `position_id` and the `role` cache (= `canonical_key`).
- Every officer read prefers `position_id` (JOIN to `ork_officer_position`); `role` is a fallback only for rows where `position_id=0` (should be none post-backfill).
- RBAC sync prefers `SyncOfficerRoleByPositionId`; falls back to the legacy `OfficerRoleToRbacRole(role)` path when `position_id=0`.
- P7 drops the cache column and the legacy map once telemetry/queries confirm zero `position_id=0` live rows.

---

## 4. RBAC Integration

### 4.1 Position → role binding
- Every `ork_officer_position` row carries `rbac_role_id` → `ork_role.role_id`.
- **Core Five** reuse the seeded system roles (`monarch`, `regent`, `prime_minister`, `champion`, `gmr`). Their `rbac_role_id` is locked (cannot be changed — requirement 4).
- **Custom positions** at create/edit time choose one of:
  - **Reuse existing role:** pick any system role or any role scoped to this kingdom (`ork_role` where `is_system=1` OR `kingdom_id=N`). Sets `rbac_role_id` directly.
  - **Build custom permission set:** category-grouped permission checkboxes (from `PermissionRegistry::GetAll()` filtered to kingdom-scope-applicable permissions) → upsert a kingdom-scoped role via `RBACService::CreateRole` / `EditRole` with `is_system=0, kingdom_id=N`, named `officer:<slug>` (slug from canonical_key). The upserted `role_id` becomes `rbac_role_id`.

### 4.2 New sync entry point
`RBACService::SyncOfficerRoleByPositionId($old_officer_mundane_id, $new_officer_mundane_id, $position_id, $kingdom_id, $park_id, $changed_by)`:
- Reads `ork_officer_position.rbac_role_id` **directly** (no string map).
- Revokes the position's role from the outgoing occupant's `ork_user_role` (scoped to kingdom/park) and grants it to the incoming occupant.
- On vacate (incoming = 0/none), only revokes.
- `$DB->Clear()` before each raw `Execute` (project rule).

The legacy `SyncOfficerRole($role, ...)` (`class.RBACService.php:696-700`) and `PermissionRegistry::OfficerRoleToRbacRole` (`class.PermissionRegistry.php:318-324`) are **kept but deprecated** during dual-write; the position-id path is preferred. Both removed in P7.

### 4.3 Self-appointment guard generalization (BUG-2 — replaces the `is_system` gate)
The current guard at `class.RBACService.php:311–315` reads:
```php
if ( $role->is_system && $granter_id == $target_id ) {
    $officer_roles = [ 'monarch', 'regent', 'prime_minister', 'champion', 'gmr' ];
    if ( in_array( $role->name, $officer_roles ) ) { return NoAuthorization(...); }
}
```
The **outer condition gates on `$role->is_system`**. A kingdom-scoped **custom** officer role has `is_system=0`, so today it would receive **zero** self-appointment protection — a monarch could mint a custom officer role and self-assign it. The fix is **not** to broaden the inner hardcoded list; it is to **replace the `$role->is_system` outer condition** with a DB-backed check:

> Block self-grant (`$granter_id == $target_id`) when the target `role_id` is referenced as an `rbac_role_id` by **any non-retired `ork_officer_position`** (system or kingdom-custom). 

So the new structure is: `if ( $granter_id == $target_id && $this->RoleIsOfficerBound($role_id) ) { return NoAuthorization(...); }`, where `RoleIsOfficerBound` runs `SELECT 1 FROM ork_officer_position WHERE rbac_role_id = ? AND retired_at IS NULL LIMIT 1` (`$DB->Clear()` first). The hardcoded `['monarch','regent','prime_minister','champion','gmr']` list is retained **only as a belt-and-suspenders fallback** (e.g. if the registry query fails) — it is **not** a conditional-only path and never the sole guard.

**Sequencing:** this lands in **P2**, BEFORE the Manage Officers UI is wired in P4. The UI must never be the only thing standing between a custom officer role and self-appointment.

### 4.4 Two-way reconciliation (blocker (d))
When a position's `rbac_role_id` is changed (edit/reclassify) or a position is retired, the service must reconcile existing occupants' `ork_user_role` rows — revoke the old role, grant the new (or revoke entirely on retire). This is handled in `EditPosition` / `RetirePosition` / `ReinstatePosition`, not left to the next `set_officer`.

---

## 5. Service / API Layer

### 5.1 New DB class: `system/lib/ork3/class.OfficerPosition.php`
DB layer (project rule: `$DB->Clear()` before any raw `Execute`/`DataSet`). Proposed methods:

- `GetPositions($kingdom_id, $include_retired=false, $classification=null)` → registry rows for kingdom (the `kingdom_id=0` system Core Five + this kingdom's custom rows), `DisplayTitle` resolved via the §3.1 rule (LEFT JOIN `ork_officer_position_alias` on `(kingdom_id, canonical_key)` for the system rows; the row's own `title_alias` via `IF(... != '', ...)` for custom rows), ordered by `sort_order`. **No override rows** — there is exactly one `ork_officer_position` row per Core-Five key (the shared system row). Excludes retired unless `$include_retired`.
- `GetPosition($position_id)` → single row + resolved `DisplayTitle` + `rbac_role_id` + permission summary.
- `CreatePosition($kingdom_id, $canonical_key, $title, $classification, $rbac_choice)` where `$rbac_choice` is either `['mode'=>'existing','role_id'=>N]` or `['mode'=>'custom','permission_keys'=>[...]]`. Validates key uniqueness, slugifies, upserts role for custom mode, assigns `sort_order` at end of group.
- `EditPosition($position_id, $fields)` → title, title_alias, sort_order, and (non-pinned only) classification + rbac binding + permission set. Triggers §4.4 reconciliation when binding changes. Rejects pinned-position classification/RBAC/retire edits server-side.
- `RetirePosition($position_id, $changed_by)` → reject if pinned/system; auto-vacate all live occupants (close terms, revoke `ork_user_role`); set `retired_at=NOW()`. Records what it vacated for the warning/audit.
- `ReinstatePosition($position_id)` → clear `retired_at`. Classification is restored from the **unchanged `classification` column**: retire (`RetirePosition`) sets only `retired_at` and never touches `classification`, so the value at reinstate is exactly the value at retire. No `classification_at_retire` snapshot column is needed.
- `GetOfficersForDisplay($kingdom_id, $park_id, $include_retired=false)` → grouped `['crown'=>[...], 'supporting'=>[...]]` rows with `CanonicalKey`, `DisplayTitle`, occupant info, term line. Retired filtered out unless requested.
- `SetOfficerByPosition($kingdom_id, $park_id, $position_id, $mundane_id, $term_start, $term_end, $note, $changed_by)` → enforces §3.4 occupancy rules; writes `ork_officer` (position_id + role cache), records history with `display_label` snapshot, calls `SyncOfficerRoleByPositionId`.
- `VacateOfficerByPosition($kingdom_id, $park_id, $position_id, $changed_by)` → closes term, sets vacant (`mundane_id=0` for crown placeholder; deletes row for supporting), revokes role.

### 5.2 Thin model: `orkui/model/model.OfficerPosition.php`
`__call` passthrough + presentation transforms only (architecture-layers rule). No DB logic here.

### 5.3 Changes to `common.php`
- `set_officer()` (`~475`) gains optional `$position_id`. Auth bypass at `common.php:486` (`'Champion'==role || 'GMR'==role`) replaced by the position's `has_auth_role` flag. When `$position_id` present → `SyncOfficerRoleByPositionId`; else legacy fallback. History write includes `display_label` snapshot.
- `create_officers()` / `create_officer()` (`~556–597`): the `position_id`-seeding patch is **hoisted to P1** as a hard prerequisite (see §3.5 step 8 / BUG-1) because these run on every kingdom/park creation and would otherwise write `position_id=0` display-string rows between P1 and P3. P2 only needs to confirm the patch is in place.
- **`RBACService::SyncNewOfficerSlot($kingdom_id, $park_id, $role)` (called from `create_officer` at `common.php:594–597`) — BUG-4.** Confirmed: the method exists at `class.RBACService.php:751` and is currently a **no-op** (it only `logtrace`s; new slots have `mundane_id=0` so there is nothing to sync). Its signature must be widened to accept `$position_id` so the create path passes the resolved seed `position_id` through alongside `$role`. Even though it remains a no-op for occupant sync, the signature change is required for the dual-write contract and to keep the create path from being the one caller that drops `position_id`. Listed in the §5.5 / P2 method set.
- `record_officer_history` (`~522`) writes `position_id` + `display_label`.
- **MINOR-2 — fix `mysql_real_escape_string` while in this function.** Confirmed: `record_officer_history` at `common.php:527` calls `mysql_real_escape_string($role)`. That function was **removed in PHP 7** and is a **fatal `Error` on PHP 8**, which this project runs (Docker `php8`). Today it is masked only because `record_officer_history` is reached on a code path that may not exercise under the current dev login bypass, but any real officer change will fatal. While editing this function in P2 to add `position_id`/`display_label`, replace the `mysql_real_escape_string` call with the project's parameter-binding / `$DB->Clear()`+bound-value pattern (or an equivalent safe escape). Flag this as an in-scope bug fix, not a new feature.

### 5.4 Changes to `class.Kingdom.php` / `class.Park.php`
- `GetOfficers()` rewritten to JOIN `ork_officer_position`, `ORDER BY p.sort_order` (replaces `FIELD(...)` at `class.Kingdom.php:583`, `class.Park.php:396`), filter retired positions server-side. `DisplayTitle` resolved per §3.1 (alias-table LEFT JOIN for Core Five).
- Response gains `CanonicalKey` + `DisplayTitle` per officer (locked-decision 4). `Role`/`OfficerRole` retained = `canonical_key` for back-compat during dual-write.
- **`Kingdom::SetOfficer($request)` (`class.Kingdom.php:623`) and the Park analog `Park::SetOfficer($request)` (`class.Park.php:910`) — MAJOR-3.** Confirmed: these are the SOAP-facing officer write paths and have their **own yapo-based `ork_officer` writes** — `Kingdom::SetOfficer` builds a `yapo` on `DB_PREFIX.'officer'` keyed by `role` (`:631–636`) and then calls `$c->set_officer(...)` with the display-string `$request['Role']`. Both must be updated to resolve the `position_id` (from `GetPositions`/`canonical_key`) and pass it through to `set_officer`, and to key their prior-holder lookup on `position_id` rather than the `role` string, so these paths also write `position_id` (not just the new Ajax controller). Without this, every SOAP/legacy officer set leaves `position_id=0`.

### 5.5 Changes to `class.RBACService.php` (all P2, before any P4 UI)
- Add `SyncOfficerRoleByPositionId` (§4.2).
- Add `$position_id` to the `SyncNewOfficerSlot($kingdom_id, $park_id, $role)` signature (`:751`, currently a no-op — BUG-4 / §5.3); remains a no-op for sync but carries the position id through the create path.
- Replace the self-appointment guard's `$role->is_system` outer gate with the DB-backed `RoleIsOfficerBound($role_id)` check (`:311–315`, §4.3 / BUG-2); keep the hardcoded list as a belt-and-suspenders fallback only.
- Keep `SyncOfficerRole` + `OfficerRoleToRbacRole` deprecated.

### 5.6 New Ajax controller: `orkui/controller/controller.OfficerAdminAjax.php`
Actions: `list`, `setOccupant`, `vacate`, `createPosition`, `editPosition`, `reclassify`, `retire`, `reinstate`. **Every action re-checks the appropriate permission server-side** (never trust the client gate). Debug output → browser console / `die(json_encode(...))` (project rule), never `error_log`.

**Two distinct permission gates (BUG-3).** "Appoint an officer" and "restructure the position registry" are different authorities and must not share a gate:

- **`kingdom.officer.set`** (existing) gates **`setOccupant` and `vacate` ONLY** — appointing/removing a person from an existing office.
- **`kingdom.officer.position.manage`** (NEW — see §7.1) gates **`createPosition`, `editPosition`, `reclassify`, `retire`, `reinstate`** — managing the registry of offices itself.
- The park analog **`park.officer.position.manage`** (NEW) gates the same registry actions at park scope (park-level position management is in scope: the Manage Officers tab is reused at park scope per §8). `park.officer.set` continues to gate park `setOccupant`/`vacate`.

The new permissions are added to `class.PermissionRegistry.php` (the `$permissions` array, `officer` category) and seeded in `migrations/rbac-seed.sql` (alongside `kingdom.officer.set` at line 19 and `park.officer.set` at line 32, same `(key, display_name, description, scope_type, category, is_system)` INSERT shape). They are assigned to **Monarch / Regent / Prime Minister** at the kingdom level and **Park Monarch / Regent / Prime Minister** (or the park-admin roles) at the park level. Monarch/Regent/PM already receive **all** permissions via the `CROSS JOIN` blocks in `rbac-seed.sql:109–128`, so the kingdom-level grant is automatic; the seed must still explicitly grant `park.officer.position.manage` to the relevant park roles if those roles use a scoped permission list rather than CROSS JOIN.

### 5.7 Principality delegation (`class.Principality.php` / `model.Principality.php`)
Principality officer access is a **thin delegation to the kingdom path** today and stays that way (verified): `class.Principality.php` `GetPrincipalityOfficers`/`SetPrincipalityOfficer` copy `PrincipalityId` into `KingdomId` and call `Kingdom::GetOfficers`/`Kingdom::SetOfficer`; `model.Principality.php` `get_officers`/`set_officers` do the same. Because a principality is a kingdom row with its own `kingdom_id`, **no method-signature changes are required here** — once `Kingdom::GetOfficers`/`SetOfficer` are upgraded (§5.4) to be position-aware, the principality delegations inherit position registry, aliasing, Crown/Supporting grouping, retire, and RBAC sync automatically. The only required change is at the **display/UI layer**: the principality profile must render the same position-aware sidebar/About partial and (when the viewer has `kingdom.officer.position.manage` at the principality's `kingdom_id`) the Manage Officers tab. The registry's per-kingdom rows for a principality are simply rows with `kingdom_id = <principality's own id>`. The vestigial `system=1` principality officer slots created by `create_officers` (§3.5 step 8) carry `position_id` after the P1 patch but introduce no new scope — they remain `kingdom_id`-keyed rows.

---

## 6. Alias Propagation

**The rule:** code keys on `canonical_key`; UI renders `DisplayTitle = IF(title_alias != '', title_alias, title)` (resolved per §3.1 — alias-table for Core Five, own-row column for custom; never `COALESCE`); read APIs (`GetOfficers`, `GetOfficersForDisplay`) return **both** `CanonicalKey` and `DisplayTitle`. JS and PHP that currently match officers by role-string equality must switch to `canonical_key` matching so aliasing never breaks logic.

### 6.1 Backend read paths (return both keys)
- `class.Kingdom.php:583` (ORDER BY), `:602`, `:613` (response assembly) — emit `CanonicalKey` + `DisplayTitle`.
- `class.Park.php:381`, `:396`.
- `controller.Player.php:397-419` — confirmed: the `$officerSql` query selects `o.role` only and builds `$officerRoles[]` with a `'role'` key. It must be updated to JOIN `ork_officer_position` (and the alias table for Core Five) and return **both** `canonical_key` (for matching) and `DisplayTitle` (for the live officer display) per row (MINOR-5). This is the live-officer list on the player profile, distinct from the history list which uses the `display_label` snapshot.
- `common.php:480, 486, 558-568` — `:486` auth special-case → `has_auth_role`; `:558-568` GMR creation uses canonical_key.
- `PermissionRegistry:318-324` — superseded by position→role_id lookup (§4).

### 6.2 Sidebars (render DisplayTitle, Crown only — see §7 partial)
`Kingdom_index.tpl:17,20`; `Park_index.tpl:25,28`; `Kingdomnew_index.tpl:29-30,106,183`; `Parknew_index.tpl:20-21,250,424`.

**PHP-level hero/sidebar officer extraction (MAJOR-4) — must switch to canonical-key matching.** Confirmed: the hero extraction loops compare against **display strings**:
- `Kingdomnew_index.tpl:28–31`: `if ($o['OfficerRole'] === 'Monarch') $monarch = $o;` / `if ($o['OfficerRole'] === 'Regent') $regent = $o;`
- `Parknew_index.tpl:18–22`: the identical `'Monarch'`/`'Regent'` display-string comparison.

Once the read paths normalize `OfficerRole`/the new `CanonicalKey` to canonical keys (P3), these `=== 'Monarch'` / `=== 'Regent'` comparisons **break silently** (the hero loses its monarch/regent). They must switch to matching the canonical key (`'monarch'` / `'regent'`), reading `CanonicalKey` for the match and `DisplayTitle` for what is rendered. Track this as an explicit P3 edit, not an afterthought — it is a logic break, not a display-only change.

### 6.3 About / lists (render DisplayTitle, all groups)
`Kingdomnew_index.tpl:180-193,1203,2778,2909`; `Parknew_index.tpl:907-957,1054-1055,1734`; `Playernew_index.tpl:1056-1057,1479-1482,2783,2927`. Playernew officer history uses `display_label` snapshot (§8), not live alias.

### 6.4 Dropdowns / selects (data-driven from GetPositions)
`Admin_setofficers.tpl:113-124` and the static selects in `Kingdomnew_index.tpl` (711-715, 985-989, 1040-1044) and `Parknew_index.tpl` (1146-1150, 1381-1385, 1436-1440) become server-rendered loops over `GetPositions()`, `<option value="{canonical_key}">{DisplayTitle}</option>`.

**Officer-history role FILTER dropdowns must use `GetPositions(..., $include_retired=true)` (MAJOR-5).** The registry default excludes retired positions (§5.1). For *appointment* selects (set occupant) that default is correct — you cannot appoint into a retired office. But any dropdown that **filters historical officer records by role** must call `GetPositions` with `include_retired=true`, otherwise a position retired today becomes unselectable for filtering past terms it once held, silently hiding history. Pass `include_retired=true` specifically on the history-filter path; keep the default (retired-excluded) on appointment/set paths.

### 6.5 JS canonical-match migration
Replace the static `OFFICER_ROLES` arrays at `revised.js:3928` and `:9125`. All role-equality comparisons (`revised.js:3938, 9135, 4055, 9245`) switch from matching display strings to matching `canonical_key`. The officerList JSON embedded in `Kingdomnew_index.tpl:1091` and `Parknew_index.tpl:1621-1623` (both currently emit `['OfficerRole'=>..., 'MundaneId'=>..., 'Persona'=>...]` via `array_map`) must **add** `CanonicalKey` and `DisplayTitle` per entry while **keeping the existing `OfficerRole` key during dual-write** (so no consumer breaks mid-migration). JS keys logic on `CanonicalKey`, renders `DisplayTitle`; `OfficerRole` is dropped only in P7.

### 6.6 Permissions UI
`Admin_permissions.tpl:196` and `Admin_permissions_grid.tpl` reference officer roles; these read the DB-backed position→role binding rather than the hardcoded map.

**Hardcoded stats summary bar in `Admin_permissions_grid.tpl:326–368` (MAJOR-6) — dynamic rewrite.** Confirmed: the `.pg-stats-bar` block hardcodes both the per-role **column headers** (Monarch / Regent / Prime Minister / Champion / GMR / ORK Admin) and the **counts** ("Total Permissions 116", "Monarch 99 / 116", width `85.3%`, "Champion 13 / 116", etc.). These literal counts and fixed columns go stale the moment a kingdom adds a custom officer role or the permission set changes. This bar must be rewritten to render its columns and counts **dynamically** from the DB-backed roles + `ork_role_permission` counts (compute total = `COUNT(ork_permission)`, per-role = `COUNT(ork_role_permission)` for that role, and the bar-fill `width` from the ratio), in the **same P6 scope** as the officer directory dynamic rewrite. Treat both as full rewrites, not tweaks.

---

## 7. Frontend / UX

### 7.1 Manage Officers tab (Kingdomnew)
- New tab `kn-tab-manageofficers` on `Kingdomnew_index.tpl`. **Server-gated** on the NEW `Authorization::HasPermission(..., 'kingdom.officer.position.manage', 'kingdom', $id)` (BUG-3 — registry management, distinct from `kingdom.officer.set` which gates only set/vacate within the tab's cards). The tab is not rendered without the position-manage permission; the set/vacate controls inside it are additionally gated on `kingdom.officer.set`.
- Legacy `Admin_setofficers.tpl` kept as a fallback with a banner link to the new tab.
- **`KnConfig` config flag.** Confirmed: `KnConfig` (`Kingdomnew_index.tpl:1086`) currently exposes `canManage` (from `$CanManageKingdom`) but has **no** `canManageOfficers`. Add a new `canManageOfficers` flag populated from the new `kingdom.officer.position.manage` permission check (server-side, emitted into the config object). It is distinct from `canManage`.
- **revised.js IIFE guard MUST use a config flag** (`KnConfig.canManageOfficers`), NOT `document.getElementById` (project rule — external script loads before modal HTML is in the DOM; a `getElementById` guard would exit early and leave open-functions undefined).

### 7.2 Layout
- Cards grouped **Crown** vs **Supporting**.
- Per-card actions: **Set Occupant / Vacate / Edit / Reclassify** (dropdown toggle, not drag) / **Retire | Reinstate**.
- Pinned Core Five: Reclassify + Retire controls shown **disabled with a `data-tip` tooltip** ("Core office — classification and retirement are locked"). Disabled, not hidden (requirement 4).
- Term line: `Term: {start} → {end | (current)}`.

### 7.3 Create/Edit Position modal
Reuse `kn-modal-*` pattern. Fields:
- **Title** (text).
- **Display alias** (text; clear via empty string → maps to `''` write, not null — yapo null-skip rule). For Core-Five positions this writes to the `ork_officer_position_alias` row (§3.6); for kingdom-custom positions it writes to the row's own `title_alias`. `DisplayTitle` resolves via `IF(title_alias != '', title_alias, title)` in both SQL and the service layer (NIT-1 — never `COALESCE`, since the cleared value is `''` not `NULL`).
- **Classification** segmented control (locked to Crown for pinned positions).
- **RBAC choice** (segmented): "Use existing role" = `<select>` of system + this-kingdom roles with a **permission preview** panel; OR "Build custom permission set" = category-grouped checkboxes from `PermissionRegistry::GetAll()` filtered to kingdom scope → upserts kingdom-scoped role `officer:<slug>`.

### 7.4 Set Occupant modal/expander
- **Player search:** custom `kn-ac-results` dropdown only (never jQuery UI autocomplete — project rule). **Kingdom-scoped** search (park-level cards scope park then kingdom; kingdom-level scope kingdom — playersearch-scoping rule).
- **Modal autocomplete positioning:** call `tnFixedAcPosition(inputEl, dropdownEl)` before **every** `.classList.add('kn-ac-open')`, in **both** the no-results and the normal-results branches (project rule — absolute positioning is clipped by the modal stacking context).
- **Term Start** flatpickr, default today; **optional End**; optional **note**. Flatpickr uses `altInput: true` + `altFormat: 'F j, Y  h:i K'` — no raw ISO ever visible (project rule).

### 7.5 Retire / Reinstate
- **Retired Positions** = collapsible disclosure, top-right of the tab, with per-row **Reinstate**.
- **Retire** uses a `kn-confirm` **warning modal** that explicitly lists who will be auto-vacated ("Retiring {DisplayTitle} will end the current term for {occupant(s)} and remove their officer permissions. Continue?").

### 7.6 Reusable partials
- `orkui/template/revised-frontend/partials/_officer_panel.tpl` — params: `$officers` (grouped, alias-resolved, retired-filtered) and `$mode` (`'sidebar'` | `'about'`). Sidebar mode shows **Crown only**; About mode shows **all groups**. Replaces the inlined sidebar/About markup across Kingdom/Park/Kingdomnew/Parknew listed in §6.2–6.3.
- `orkui/template/revised-frontend/partials/_officer_position_modal.tpl` — the Create/Edit/Set-Occupant modal markup.

### 7.7 Dark-mode checklist (walk every new surface in dark mode before "done")
- Modal headers: reset the global `orkui.css` `h1-h6` gray-pill (`background:transparent; border:none; padding:0; border-radius:0;` + desired text-shadow).
- Ghost/cancel buttons: ensure sufficient text contrast.
- No inline `style="color:#xxx"`.
- Form labels, placeholders, segmented toggles, info boxes, retired muted text, toasts.
- Tooltips via `data-tip` only — no native `title` attributes.

---

## 8. Edge Cases & Policies

- **Retire-while-occupied:** auto-vacate all live occupants (close term + revoke `ork_user_role`), but UI **warns first** (§7.5). Pinned/system positions cannot be retired — enforced UI + server.
- **History snapshot titles:** `ork_officer_history.display_label` stores `DisplayTitle` at write time (requirement 7). Player profile officer history and any historical directory render `display_label` verbatim; never re-resolve the live alias. Aliasing today does not rewrite yesterday's history.
- **`award.officer_role` policy:** independent taxonomy (`controller.Player.php:368,434,671`). Title-granting awards keep their stored role string; aliasing is display-only and does NOT retroactively alter historical award titles (locked-decision 10). Do not unify these taxonomies in this feature.
- **GMR enum fix:** the ENUM→VARCHAR migration (§3.5 steps 1–2) resolves the latent coercion bug — but note (MINOR-1, §2) it exists **only on `ork_officer.role`** (which omits GMR from its ENUM, so writes coerce to `''` under `sql_mode=''`); `ork_officer_history.role` already lists GMR in its ENUM (`migrations/officer_history.sql:9`) and is widened only to admit custom keys, not to fix coercion. After migration, GMR is a real, valid value on both tables, with a seeded system position row, `has_auth_role` set per its actual auth behavior, and its title default "Guildmaster of Reeves".
- **Reports directory dynamic rewrite:** `Reports_kingdomofficerdirectory.tpl` (`:10-16, :71-87, :102-129`) is rebuilt to render columns and vacancy counters **dynamically** from `GetPositions()` / `GetOfficersForDisplay()` — variable number of columns grouped Crown then Supporting, retired filtered out. This is a full rewrite (P6), not a tweak.
- **Principalities (in scope):** A principality is **a kingdom** — `ork_kingdom.parent_kingdom_id > 0` marks it (verified: there is no separate `ork_principality` table; `class.Kingdom.php:34` derives `IsPrincipality` from `parent_kingdom_id`). It has its own globally-unique `kingdom_id`, stores officers in `ork_officer` at `kingdom_id = <its own id>, park_id = 0`, uses the identical Core-Five roles, and is reached through delegation (`class.Principality.php` → `Kingdom::GetOfficers/SetOfficer` with `KingdomId = PrincipalityId`; §5.7).

  **Design decision — reuse the `kingdom` scope keyed on the principality's own `kingdom_id`; do NOT add a `principality` RBAC scope_type or a `principality_id` column.** Rationale: `kingdom_id` is globally unique, so a position/role/`ork_user_role` row scoped to a principality's `kingdom_id` is already unambiguous; an explicit RBAC check `HasPermission(mundane, key, 'kingdom', principality_kingdom_id)` matches only roles granted at that exact id. Adding a parallel `principality` dimension to `ork_permission`/`ork_role`/`ork_user_role` (the alternative the gap-research raised) would be redundant data with no widget consuming it (data-usefulness test) and would fork every scope-resolution path. The position registry's `kingdom_id` column (§3.1) therefore holds a principality's own id for its custom positions; the shared Core-Five system rows (`kingdom_id=0`) and the `ork_officer_position_alias` table apply to principalities exactly as to top-level kingdoms.

  **What this requires concretely:** (a) nothing new in the data model or RBAC scope columns; (b) the §5.4 `Kingdom::GetOfficers/SetOfficer` upgrade flows to principalities for free via §5.7 delegation; (c) the Manage Officers tab, sidebar, and About partial must render on the **principality profile** as well, gated on `kingdom.officer.position.manage` / `kingdom.officer.set` evaluated at the principality's `kingdom_id` (added to P4's scope, §9). Hierarchical authority (a parent-kingdom officer acting on a child principality) is an existing/separate concern and is explicitly **not** introduced by this feature.
- **Park scope:** Crown single-occupancy and crown-per-person checks span **kingdom + park** scopes (§3.4). Park-level Manage Officers reuses the same partials/controller, scoped to the park's kingdom.
- **Vacant rows:** Core Five keep a vacant placeholder (`mundane_id=0`) per kingdom so directory columns render "vacant"; supporting positions use row-absence = no holders (no placeholder needed).

---

## 9. Phased Implementation Plan

Ordering rationale: schema must land before any code can read/write it (P1). Backend service + RBAC + dual-write (P2) must exist before read-paths can plumb both keys (P3) and before the admin UI can call anything (P4). Read-path aliasing (P3) is independent of the admin UI and de-risks the most consumption sites early. Retire/reinstate (P5) builds on the P4 UI + P2 reconciliation. Reports + permissions UI (P6) depend on the DB-backed registry. Cleanup (P7) only after telemetry proves zero legacy-path rows.

### P1 — Schema + migration + backfill (incl. GMR enum fix + create_officers patch)
- **Goal:** `ork_officer_position` + `ork_officer_position_alias` exist, ENUMs widened, position_ids backfilled, blanket unique dropped, **and `create_officers()`/`create_officer()` seed `position_id` so newly-created kingdoms/parks never write `position_id=0` rows between P1 and P3** (BUG-1, §3.5 step 8).
- **Tables/files:** new `migrations/officer-position.sql` (both tables); alters `ork_officer`, `ork_officer_history`; `ork.sql` schema-of-record updated; **`common.php` `create_officers()`/`create_officer()` patched (P1 prerequisite — the only `common.php` edit in P1)**.
- **Checkpoint:** **every live `ork_officer` row AND every row created post-migration by `create_officers` carries a non-zero `position_id`** (and a canonical-key `role` cache, not a display string); the 5 system positions seeded with correct `rbac_role_id`; a GMR row can be written to `ork_officer` without coercion under a real `sql_mode`; `UNIQUE(kingdom,park,role)` gone, replaced index present; `ork_officer_position_alias` present and empty.

### P2 — `class.OfficerPosition` + service/RBAC backend + dual-write
- **Goal:** full DB service layer + RBAC sync-by-position + dual-write wiring; no UI yet.
- **Files:** new `system/lib/ork3/class.OfficerPosition.php`, `orkui/model/model.OfficerPosition.php`; edits to `common.php` (`set_officer`, `record_officer_history` incl. the `mysql_real_escape_string` PHP8 fatal fix — MINOR-2; `create_officers` already patched in P1), `class.Kingdom.php` / `class.Park.php` (`SetOfficer` position_id pass-through — MAJOR-3), `class.RBACService.php` (`SyncOfficerRoleByPositionId`; `SyncNewOfficerSlot` signature += `$position_id` — BUG-4; self-appointment guard `is_system`→`RoleIsOfficerBound` — BUG-2/§4.3), `class.PermissionRegistry.php` (add `kingdom.officer.position.manage` + `park.officer.position.manage` — BUG-3; deprecate `$officerRoleMap`), `migrations/rbac-seed.sql` (seed + grant the two new permissions).
- **Checkpoint:** setting/vacating an officer via the service writes `position_id` + role cache + history snapshot, and grants/revokes the correct `ork_role`; crown single-occupant, crown-per-person (with `GET_LOCK` advisory lock — §3.4), and supporting-multi rules all enforced (unit-verify each); the self-appointment guard blocks self-grant of a **custom** (`is_system=0`) officer role; the two new position-manage permissions exist and are held by Monarch/Regent/PM; `record_officer_history` no longer calls `mysql_real_escape_string`; legacy string path still works as fallback.

### P3 — Read-path alias plumbing
- **Goal:** every read surface returns/renders `CanonicalKey` + `DisplayTitle`; JS matches by canonical_key.
- **Files:** `class.Kingdom.php`, `class.Park.php`, `controller.Player.php` (`:397-419` — return `canonical_key` + `DisplayTitle`, MINOR-5); sidebar/About edits (§6.2–6.3); **PHP hero-extraction loops `Kingdomnew_index.tpl:28-31` + `Parknew_index.tpl:18-22` switch `=== 'Monarch'`/`'Regent'` to canonical-key matching — MAJOR-4**; new `_officer_panel.tpl`; `revised.js` (3928/9125/3938/9135/4055/9245); officerList JSON in Kingdomnew/Parknew (add `CanonicalKey`+`DisplayTitle`, keep `OfficerRole` — NIT-2).
- **Checkpoint:** aliasing a position (DB edit) changes the displayed title everywhere in §6 while all JS officer logic still functions; the hero still resolves monarch/regent after `OfficerRole` normalizes to canonical keys; no raw `role` display string drives any comparison.

### P4 — Manage Officers UI (tab, cards, Create/Edit modal, Set Occupant, reclassify)
- **Goal:** kingdom admins can create/edit/classify positions and set/vacate occupants from the new tab.
- **Files:** new `controller.OfficerAdminAjax.php` (registry actions gated on `kingdom.officer.position.manage` / `park.officer.position.manage`; set/vacate gated on `kingdom.officer.set` / `park.officer.set` — BUG-3); `Kingdomnew_index.tpl` (tab + cards + new `KnConfig.canManageOfficers` flag from the position-manage permission — MINOR-3), `Parknew_index.tpl` (park scope, analogous config flag); the **principality profile template path** (the Manage Officers tab + sidebar/About partial must render on principality profiles too, gated at the principality's own `kingdom_id` — §8); `_officer_position_modal.tpl`; `revised.js` (open/handlers, IIFE config-flag guard); `Admin_setofficers.tpl` fallback banner.
- **Checkpoint:** create a custom supporting position with a custom permission set, alias a Core Five title (writes `ork_officer_position_alias` for Core Five), set/vacate occupants; pinned controls disabled-with-tooltip; server re-checks the **correct** permission per action (position.manage vs officer.set); the same flows work on a **principality profile** keyed at the principality's `kingdom_id`; dark-mode walkthrough passes.

### P5 — Retire / Reinstate
- **Goal:** retire (auto-vacate + warning) and reinstate flows.
- **Files:** `controller.OfficerAdminAjax.php` (retire/reinstate), `class.OfficerPosition.php` (already has methods from P2 — wire UI), Manage Officers tab (Retired disclosure + `kn-confirm` warning modal).
- **Checkpoint:** retiring an occupied position warns first, then closes terms + revokes roles + hides the position from pickers/sidebar/About/reports; reinstate restores it with prior classification.

### P6 — Reports directory dynamic rewrite + permissions UI
- **Goal:** officer directory and permissions UI driven by the registry.
- **Files:** `Reports_kingdomofficerdirectory.tpl` (full dynamic-column rewrite), `Admin_permissions.tpl:196`, `Admin_permissions_grid.tpl` (DB-backed bindings **AND** the hardcoded `.pg-stats-bar` at `:326-368` rewritten to dynamic per-role columns + computed counts/widths — MAJOR-6); supporting report model/controller as needed.
- **Checkpoint:** directory renders a variable number of grouped columns + correct vacancy counters for a kingdom with custom positions; retired excluded; the permissions-grid stats bar shows live per-role permission counts (no hardcoded "99 / 116") and includes any custom officer roles; permissions UI reflects DB-backed bindings.

### P7 — Cleanup (drop legacy map / role cache after cutover)
- **Goal:** remove dual-write scaffolding once safe.
- **Files:** `class.PermissionRegistry.php` (`$officerRoleMap`, `OfficerRoleToRbacRole`), `class.RBACService.php` (`SyncOfficerRole`, hardcoded self-appointment list), drop `ork_officer.role` cache column.
- **Checkpoint:** zero live `ork_officer` rows with `position_id=0`; no code references the legacy map; full regression of set/vacate/retire/display/reports passes.

---

## 10. Open Questions / Risks

> **Resolved during this revision (no longer open):**
> - **System-position aliasing storage** — DECIDED: dedicated `ork_officer_position_alias` table, no override rows (§3.1/§3.6). Reasons: no `is_system`/`is_pinned` ambiguity, no `rbac_role_id` drift, simpler `GetPositions` join, easier P7 cleanup.
> - **Reinstate "prior classification"** — DECIDED: restore from the unchanged `classification` column; `RetirePosition` sets only `retired_at`, so no `classification_at_retire` snapshot column is needed (§5.1).
> - **Crown-per-person concurrency** — DECIDED/PROMOTED: not a "low risk, document a stance" item; it is a mandated `GET_LOCK('crown_assign_{mundane_id}', timeout)` advisory lock around the crown-per-person SELECT-then-INSERT, now a numbered constraint in §3.4 (the engine is MyISAM — no transactions/partial unique indexes — confirmed at `ork.sql`).

1. **`has_auth_role` seed values (§3.5 step 4):** must verify the exact `common.php:486` auth-bypass semantics for champion/gmr vs monarch/regent/pm before seeding. Mis-seeding silently changes who needs an `ork_authorization` row.
2. **Principalities (resolved — in scope):** treated as kingdoms keyed on their own `kingdom_id`; no new RBAC scope/column (§8). Remaining verification: confirm the principality profile template path renders the position-aware partials + Manage Officers tab correctly under principality permissions (covered in P4); confirm hierarchical parent→principality authority is unchanged (not introduced here).
3. **Backfill normalization (§3.5 step 5):** the legacy `role` ENUM strings must map cleanly to canonical keys; any rows already coerced to `''` (the `ork_officer.role` GMR latent bug — see MINOR-1) need manual reconciliation — audit for `role=''` rows before backfill.
4. **`ork_role` scoping for custom roles:** confirm `RBACService::CreateRole/EditRole` accept `kingdom_id=N, is_system=0` and that `HasPermission` resolves kingdom-scoped officer roles correctly for the grant/check paths used here.
