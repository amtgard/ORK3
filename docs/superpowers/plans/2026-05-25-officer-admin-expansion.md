# Officer Admin Expansion — Implementation Plan

For agentic workers: this plan is executed using the `superpowers:subagent-driven-development` workflow. Each task is a self-contained unit with explicit verification commands. **There is no test framework in this codebase** (no PHPUnit/composer dev-deps), so the TDD rhythm is adapted to **verification-driven** tasks: each task states the change → the exact runnable verification command (MariaDB query, curl against the query-string route, or an explicit browser step) → expected output → implement → re-run → commit.

**Goal:** Make officer positions first-class, kingdom-extensible, alias-able, RBAC-bound data. Replace the five hardcoded ENUM roles (Monarch, Regent, Prime Minister, Champion, GMR) with a `ork_officer_position` registry keyed on stable `canonical_key`s, with per-kingdom title aliasing, crown/supporting occupancy rules, retire/reinstate, and DB-backed RBAC binding. Fix the tracked latent bugs (BUG-1 create_officers seeding, BUG-2 self-appointment guard, BUG-3 new position-manage permissions, BUG-4 SyncNewOfficerSlot signature, MINOR-2 `mysql_real_escape_string` PHP8 fatal). Principalities are covered for free (a principality is a kingdom row keyed on its own `kingdom_id`).

**Architecture:** Three layers (project rule — feedback_architecture_layers). DB logic lives in `system/lib/ork3/` ONLY (`class.OfficerPosition.php`, `class.Kingdom.php`, `class.Park.php`, `class.RBACService.php`, `common.php`). `orkui/model/*` is a thin `__call` passthrough + presentation transforms. Controllers (`controller.OfficerAdminAjax.php`, `controller.Player.php`) call models. Templates render; JS keys on `canonical_key`, renders `DisplayTitle`.

**Tech Stack:** PHP 8 (Docker `php8`), MariaDB (container `ork3-php8-db`), MyISAM engine for `ork_officer` (no transactions, no partial unique indexes → app-level occupancy + `GET_LOCK`). App served via nginx at `http://localhost:19080/orkui/` using the query-string route scheme (`index.php?Route=Controller/action/id`; clean URLs 404). Frontend: custom `kn-ac-results` autocomplete, flatpickr, `KnConfig`-gated `revised.js` IIFEs, dark-mode-first CSS.

**Source of truth:** `docs/superpowers/specs/2026-05-25-officer-admin-expansion-design.md` (authoritative). Every task below cites the spec section it implements.

---

## Project editing rules (apply in EVERY task)

- **PHP multi-line edits MUST use a `python3 -c` one-liner**, never a fuzzy editor — PHP files use tab indentation and byte-perfect matching fails. The literal command pattern (always verify the needle is found first):
  ```bash
  python3 -c "import pathlib; p=pathlib.Path('FILE'); t=p.read_text(); print('found:', 'NEEDLE' in t); p.write_text(t.replace(OLD, NEW, 1))"
  ```
- **Always `$DB->Clear()` before any raw `$DB->Execute(...)`/`$DB->DataSet(...)`** — stale PDO bindings cause silent INSERT/UPDATE failures.
- **DisplayTitle resolution is `IF(<alias> != '', <alias>, title)`, NOT `COALESCE`** — a cleared yapo alias is `''` not `NULL`; `COALESCE('',...)` returns `''`. To clear a yapo alias field assign `''`, never `null` (YapoSave `isset()` skips null fields).
- **Debug output → browser console / `die(json_encode(...))`**, never `error_log`/`print_r`/`logtrace` as primary debug.
- **Frontend:** custom `kn-ac-results` autocomplete only (never jQuery UI); call `tnFixedAcPosition(input, results)` before EVERY `.classList.add('kn-ac-open')` in BOTH the no-results and results branches; flatpickr with `altInput:true, altFormat:'F j, Y  h:i K'` (never raw ISO); `revised.js` IIFE guard uses `KnConfig.canManageOfficers` (a config flag), NOT `document.getElementById`; dark-mode proactive (reset orkui.css global `h1-h6` gray pill: `background:transparent;border:none;padding:0;border-radius:0;` on modal/card headings; ghost/cancel contrast; no inline `style="color:#xxx"`; `data-tip` tooltips not native `title`).
- **Staging/commits:** stage files EXPLICITLY (`git add <file> <file>`), never `git add -A` / `git add .`. NEVER stage `class.Authorization.php` while a `true ||` login bypass is present (~lines 327/330). NEVER commit `CLAUDE.md` / `agent-instructions/*`. Commit per task. Every commit message ends with:
  ```
  Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
  ```
- **MariaDB command** (container is MariaDB, not mysql): apply with `docker exec -i ork3-php8-db mariadb -u root -proot ork < migrations/<file>.sql`; assert with `docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT ..."`.

---

## Signature contract (identical across all phases)

These signatures are defined once here and used verbatim wherever referenced.

```php
// system/lib/ork3/class.OfficerPosition.php
GetPositions($kingdom_id, $include_retired = false, $classification = null)            // → array of registry rows, DisplayTitle resolved
GetPosition($position_id)                                                              // → single row + DisplayTitle + rbac_role_id + permission summary
CreatePosition($kingdom_id, $canonical_key, $title, $classification, $rbac_choice)     // $rbac_choice = ['mode'=>'existing','role_id'=>N] | ['mode'=>'custom','permission_keys'=>[...]]
EditPosition($position_id, $fields)                                                    // $fields: title, title_alias, sort_order, (non-pinned) classification, rbac binding
RetirePosition($position_id, $changed_by)
ReinstatePosition($position_id)
GetOfficersForDisplay($kingdom_id, $park_id, $include_retired = false)                 // → ['crown'=>[...],'supporting'=>[...]]
SetOfficerByPosition($kingdom_id, $park_id, $position_id, $mundane_id, $term_start, $term_end, $note, $changed_by)
VacateOfficerByPosition($kingdom_id, $park_id, $position_id, $changed_by)

// system/lib/ork3/class.RBACService.php
SyncOfficerRoleByPositionId($old_officer_mundane_id, $new_officer_mundane_id, $position_id, $kingdom_id, $park_id, $changed_by)
SyncNewOfficerSlot($kingdom_id, $park_id, $role, $position_id = 0)   // BUG-4: += $position_id (still no-op for sync)
RoleIsOfficerBound($role_id)                                         // BUG-2: SELECT 1 FROM ork_officer_position WHERE rbac_role_id=? AND retired_at IS NULL LIMIT 1

// system/lib/ork3/common.php
set_officer($kingdom_id, $park_id, $new_officer_id, $role, $system = 0, $changed_by = 0, $position_id = 0)   // += $position_id
```

`canonical_key`s for the Core Five: `monarch`, `regent`, `prime_minister`, `champion`, `gmr`.

---

## File Structure

| File | Create/Modify | Responsibility |
|---|---|---|
| `migrations/officer-position.sql` | **Create** (P1) | `CREATE TABLE ork_officer_position` + `ork_officer_position_alias`; widen `ork_officer.role`/`ork_officer_history.role` to VARCHAR; add `position_id`/`display_label` columns; seed 5 system positions; backfill `position_id` + canonical `role` cache; drop `UNIQUE(kingdom,park,role)`, add `(kingdom,park,position_id)` index. |
| `ork.sql` | **Modify** (P1) | Schema-of-record: update `ork_officer` DDL (role VARCHAR, position_id, dropped unique, new index) so fresh DB loads match. |
| `system/lib/ork3/common.php` | **Modify** (P1 + P2) | P1: patch `create_officers()`/`create_officer()` to seed `position_id` + canonical-key `role` cache on BOTH branches (BUG-1). P2: `set_officer()` += `$position_id` & `has_auth_role`; `record_officer_history()` += `position_id`/`display_label` & remove `mysql_real_escape_string` (MINOR-2). |
| `system/lib/ork3/class.OfficerPosition.php` | **Create** (P2) | DB layer for the position registry + occupancy-enforced officer writes (per signature contract). |
| `orkui/model/model.OfficerPosition.php` | **Create** (P2) | Thin `__call` passthrough + presentation transforms. |
| `system/lib/ork3/class.RBACService.php` | **Modify** (P2, P7) | P2: add `SyncOfficerRoleByPositionId`; widen `SyncNewOfficerSlot` (BUG-4); replace self-appointment `is_system` gate with `RoleIsOfficerBound` (BUG-2); add `EditPosition`/`RetirePosition`/`ReinstatePosition` RBAC reconciliation helpers. P7: remove legacy `SyncOfficerRole` + hardcoded list. |
| `system/lib/ork3/class.PermissionRegistry.php` | **Modify** (P2, P7) | P2: add `kingdom.officer.position.manage` + `park.officer.position.manage` to `$permissions` (BUG-3); deprecate `$officerRoleMap`. P7: remove `$officerRoleMap`/`OfficerRoleToRbacRole`/`GetOfficerRoleMap`. |
| `migrations/rbac-seed.sql` | **Modify** (P2) | Seed + grant the two new position-manage permissions. |
| `system/lib/ork3/class.Kingdom.php` | **Modify** (P2, P3) | P2: `SetOfficer` resolves & passes `position_id` (MAJOR-3). P3: `GetOfficers` JOIN `ork_officer_position`, `ORDER BY p.sort_order`, return `CanonicalKey`+`DisplayTitle`, filter retired. |
| `system/lib/ork3/class.Park.php` | **Modify** (P2, P3) | Same as Kingdom for park scope (`SetOfficer` :910, `GetOfficers` :381). |
| `orkui/controller/controller.Player.php` | **Modify** (P3) | Officer live-query (:397-419) JOINs position registry, returns `canonical_key`+`DisplayTitle` (MINOR-5). |
| `orkui/template/revised-frontend/partials/_officer_panel.tpl` | **Create** (P3) | Reusable sidebar/About officer panel; `$officers` (grouped, alias-resolved, retired-filtered) + `$mode` ('sidebar'=Crown only / 'about'=all). |
| `orkui/template/revised-frontend/partials/_officer_position_modal.tpl` | **Create** (P4) | Create/Edit Position + Set Occupant modal markup. |
| `orkui/template/revised-frontend/Kingdomnew_index.tpl` | **Modify** (P3, P4, P5) | Hero/sidebar canonical-key matching; static selects → data-driven; officerList JSON += CanonicalKey/DisplayTitle; Manage Officers tab + `KnConfig.canManageOfficers`; Retired disclosure + warning modal. |
| `orkui/template/revised-frontend/Parknew_index.tpl` | **Modify** (P3, P4, P5) | Same edits at park scope. |
| `orkui/template/default/Playernew_index.tpl` | **Modify** (P3) | Live-officer list renders DisplayTitle; history renders `display_label` snapshot. |
| `orkui/template/.../Kingdom_index.tpl`, `Park_index.tpl` | **Modify** (P3) | Legacy sidebars render DisplayTitle via `_officer_panel.tpl`. |
| `orkui/template/revised-frontend/script/revised.js` | **Modify** (P3, P4) | Replace `OFFICER_ROLES` static arrays (:3928, :9125); canonical-key matching (:3977/3996/4106/9171/9190/9292); Manage Officers handlers w/ `KnConfig.canManageOfficers` IIFE guard. |
| `system/lib/ork3/class.Principality.php`, `orkui/model/model.Principality.php` | **No signature change** (P4 display only) | Delegation to Kingdom inherits position-awareness; only the principality profile template must render the position-aware partials + Manage Officers tab. |
| `orkui/template/.../Admin_setofficers.tpl` | **Modify** (P4) | Fallback banner linking to the new Manage Officers tab. |
| `orkui/template/.../Reports_kingdomofficerdirectory.tpl` | **Modify** (P6) | Dynamic-column rewrite (variable grouped columns + vacancy counters from registry). |
| `orkui/template/revised-frontend/Admin_permissions_grid.tpl` | **Modify** (P6) | `.pg-stats-bar` (:327-368) dynamic per-role columns + computed counts/widths (MAJOR-6); DB-backed bindings. |
| `orkui/template/.../Admin_permissions.tpl` | **Modify** (P6) | Officer-role references (:196) read DB-backed position→role binding. |

---

# P1 — Schema + migration + backfill (incl. GMR enum fix + create_officers patch)

**Goal:** `ork_officer_position` + `ork_officer_position_alias` exist, ENUMs widened, position_ids backfilled, blanket unique dropped, and `create_officers()`/`create_officer()` seed `position_id` so newly-created kingdoms/parks never write `position_id=0` rows between P1 and P3 (BUG-1, §3.5 step 8).

**Spec checkpoint:** every live `ork_officer` row AND every row created post-migration carries a non-zero `position_id` (and a canonical-key `role` cache, not a display string); the 5 system positions seeded with correct `rbac_role_id`; a GMR row can be written to `ork_officer` without coercion under a real `sql_mode`; `UNIQUE(kingdom,park,role)` gone, replaced index present; `ork_officer_position_alias` present and empty.

---

## Task P1.1 — Audit pre-backfill data (coerced GMR rows)

Spec §3.5 step 5, §10 risk 3. Identify any `ork_officer.role=''` rows (the GMR latent coercion bug) needing reconciliation before backfill.

- [ ] Run the audit query:
  ```bash
  docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT officer_id, kingdom_id, park_id, mundane_id, role, system FROM ork_officer WHERE role='' OR role IS NULL;"
  ```
  Expected: a (possibly empty) list of officer rows whose `role` was coerced to `''`. Record the count. These are almost certainly GMR slots (created via `create_officer(...,'GMR',...)` against the ENUM that omits GMR).
- [ ] Cross-check by `system` and `authorization_id` to confirm they are GMR slots:
  ```bash
  docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT role, COUNT(*) FROM ork_officer GROUP BY role;"
  ```
  Expected: counts for `Monarch`/`Regent`/`Prime Minister`/`Champion` and a bucket of `''` rows = the GMR slots. (No commit — this is read-only reconnaissance; record the empty-role count for use in P1.3 backfill step 5b.)

---

## Task P1.2 — Create migration file: tables + ENUM widen + columns

**Create** `migrations/officer-position.sql` (Part A — schema only, no backfill yet so each section is independently verifiable).

- [ ] Create the file with this exact content:
  ```sql
  -- Migration: Officer Admin Expansion — position registry, ENUM->VARCHAR, position_id columns
  -- Run via: docker exec -i ork3-php8-db mariadb -u root -proot ork < migrations/officer-position.sql
  -- Idempotent where practical.

  -- 1. Widen ork_officer.role ENUM -> VARCHAR (fixes GMR coercion bug; admits custom keys)
  ALTER TABLE `ork_officer` MODIFY `role` VARCHAR(80) NOT NULL;

  -- 2. Widen ork_officer_history.role ENUM -> VARCHAR (admits custom keys; NOT a coercion fix)
  ALTER TABLE `ork_officer_history` MODIFY `role` VARCHAR(80) NOT NULL;

  -- 3a. New table: position registry
  CREATE TABLE IF NOT EXISTS `ork_officer_position` (
    `position_id`   int(11)      NOT NULL AUTO_INCREMENT,
    `kingdom_id`    int(11)      NOT NULL,
    `canonical_key` varchar(60)  NOT NULL,
    `title`         varchar(80)  NOT NULL,
    `title_alias`   varchar(80)  NOT NULL DEFAULT '',
    `classification` enum('crown','supporting') NOT NULL,
    `is_pinned`     tinyint(1)   NOT NULL DEFAULT 0,
    `is_system`     tinyint(1)   NOT NULL DEFAULT 0,
    `rbac_role_id`  int(11)      NOT NULL,
    `has_auth_role` tinyint(1)   NOT NULL DEFAULT 0,
    `sort_order`    int(11)      NOT NULL DEFAULT 100,
    `retired_at`    datetime     NULL DEFAULT NULL,
    `created_by`    int(11)      NOT NULL DEFAULT 0,
    `created_at`    datetime     NOT NULL,
    PRIMARY KEY (`position_id`),
    UNIQUE KEY `uq_kingdom_key` (`kingdom_id`, `canonical_key`),
    KEY `idx_grouped_read` (`kingdom_id`, `classification`, `retired_at`, `sort_order`)
  ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

  -- 3b. New table: per-kingdom alias of shared system (Core-Five) rows
  CREATE TABLE IF NOT EXISTS `ork_officer_position_alias` (
    `alias_id`      int(11)      NOT NULL AUTO_INCREMENT,
    `kingdom_id`    int(11)      NOT NULL,
    `canonical_key` varchar(60)  NOT NULL,
    `title_alias`   varchar(80)  NOT NULL DEFAULT '',
    PRIMARY KEY (`alias_id`),
    UNIQUE KEY `uq_kingdom_canonical` (`kingdom_id`, `canonical_key`)
  ) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
  ```
- [ ] Apply:
  ```bash
  docker exec -i ork3-php8-db mariadb -u root -proot ork < migrations/officer-position.sql
  ```
- [ ] Verify ENUM widen + tables:
  ```bash
  docker exec ork3-php8-db mariadb -u root -proot ork -e "SHOW COLUMNS FROM ork_officer LIKE 'role'; SHOW COLUMNS FROM ork_officer_history LIKE 'role'; SHOW TABLES LIKE 'ork_officer_position%';"
  ```
  Expected: both `role` columns show `varchar(80)`; `ork_officer_position` and `ork_officer_position_alias` both listed.
- [ ] `git add migrations/officer-position.sql && git commit -m "Enhancement: Officer registry tables + role ENUM->VARCHAR widen (P1)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P1.3 — Seed Core Five, add position_id columns, backfill, swap unique index

**Modify** `migrations/officer-position.sql` (append Part B). `has_auth_role`: 1 for monarch/regent/prime_minister (they use `ork_authorization` today — confirmed at `common.php:486` where ONLY `'Champion'`/`'GMR'` bypass the authorization path, so the other three go through it); 0 for champion/gmr (they bypass `ork_authorization`).

- [ ] Append to `migrations/officer-position.sql`:
  ```sql
  -- 4. Seed the 5 system positions (kingdom_id=0, is_system=1, is_pinned=1, classification='crown')
  INSERT IGNORE INTO `ork_officer_position`
    (`kingdom_id`,`canonical_key`,`title`,`title_alias`,`classification`,`is_pinned`,`is_system`,`rbac_role_id`,`has_auth_role`,`sort_order`,`retired_at`,`created_by`,`created_at`)
  SELECT 0,'monarch','Monarch','','crown',1,1,r.role_id,1,10,NULL,0,NOW()
    FROM `ork_role` r WHERE r.name='monarch' AND r.kingdom_id=0 AND r.is_system=1;
  INSERT IGNORE INTO `ork_officer_position`
    (`kingdom_id`,`canonical_key`,`title`,`title_alias`,`classification`,`is_pinned`,`is_system`,`rbac_role_id`,`has_auth_role`,`sort_order`,`retired_at`,`created_by`,`created_at`)
  SELECT 0,'regent','Regent','','crown',1,1,r.role_id,1,20,NULL,0,NOW()
    FROM `ork_role` r WHERE r.name='regent' AND r.kingdom_id=0 AND r.is_system=1;
  INSERT IGNORE INTO `ork_officer_position`
    (`kingdom_id`,`canonical_key`,`title`,`title_alias`,`classification`,`is_pinned`,`is_system`,`rbac_role_id`,`has_auth_role`,`sort_order`,`retired_at`,`created_by`,`created_at`)
  SELECT 0,'prime_minister','Prime Minister','','crown',1,1,r.role_id,1,30,NULL,0,NOW()
    FROM `ork_role` r WHERE r.name='prime_minister' AND r.kingdom_id=0 AND r.is_system=1;
  INSERT IGNORE INTO `ork_officer_position`
    (`kingdom_id`,`canonical_key`,`title`,`title_alias`,`classification`,`is_pinned`,`is_system`,`rbac_role_id`,`has_auth_role`,`sort_order`,`retired_at`,`created_by`,`created_at`)
  SELECT 0,'champion','Champion','','crown',1,1,r.role_id,0,40,NULL,0,NOW()
    FROM `ork_role` r WHERE r.name='champion' AND r.kingdom_id=0 AND r.is_system=1;
  INSERT IGNORE INTO `ork_officer_position`
    (`kingdom_id`,`canonical_key`,`title`,`title_alias`,`classification`,`is_pinned`,`is_system`,`rbac_role_id`,`has_auth_role`,`sort_order`,`retired_at`,`created_by`,`created_at`)
  SELECT 0,'gmr','Guildmaster of Reeves','','crown',1,1,r.role_id,0,50,NULL,0,NOW()
    FROM `ork_role` r WHERE r.name='gmr' AND r.kingdom_id=0 AND r.is_system=1;

  -- 5. Add position_id to ork_officer + backfill (normalize display strings -> canonical keys)
  ALTER TABLE `ork_officer` ADD COLUMN `position_id` INT NOT NULL DEFAULT 0;
  -- 5a. Normalize coerced/empty GMR rows back to the canonical key first (risk-3 reconciliation)
  UPDATE `ork_officer` SET `role`='gmr'
    WHERE (`role`='' OR `role`='GMR') AND `authorization_id`=0;
  -- 5b. Normalize remaining display strings to canonical keys
  UPDATE `ork_officer` SET `role`='monarch'        WHERE `role`='Monarch';
  UPDATE `ork_officer` SET `role`='regent'         WHERE `role`='Regent';
  UPDATE `ork_officer` SET `role`='prime_minister' WHERE `role`='Prime Minister';
  UPDATE `ork_officer` SET `role`='champion'       WHERE `role`='Champion';
  UPDATE `ork_officer` SET `role`='gmr'            WHERE `role`='GMR';
  -- 5c. Backfill position_id from the system seed by canonical key
  UPDATE `ork_officer` o JOIN `ork_officer_position` p
    ON p.kingdom_id=0 AND p.canonical_key=o.role
    SET o.position_id=p.position_id;

  -- 6. Add position_id + display_label to ork_officer_history + backfill
  ALTER TABLE `ork_officer_history`
    ADD COLUMN `position_id` INT NOT NULL DEFAULT 0,
    ADD COLUMN `display_label` VARCHAR(80) NOT NULL DEFAULT '';
  UPDATE `ork_officer_history` SET `display_label`=`role` WHERE `display_label`='';
  UPDATE `ork_officer_history` SET `role`='monarch'        WHERE `role`='Monarch';
  UPDATE `ork_officer_history` SET `role`='regent'         WHERE `role`='Regent';
  UPDATE `ork_officer_history` SET `role`='prime_minister' WHERE `role`='Prime Minister';
  UPDATE `ork_officer_history` SET `role`='champion'       WHERE `role`='Champion';
  UPDATE `ork_officer_history` SET `role`='gmr'            WHERE `role`='GMR';
  UPDATE `ork_officer_history` h JOIN `ork_officer_position` p
    ON p.kingdom_id=0 AND p.canonical_key=h.role
    SET h.position_id=p.position_id;

  -- 7. Drop blanket unique; add non-unique scoped index
  ALTER TABLE `ork_officer` DROP INDEX `kingdom_id`;
  ALTER TABLE `ork_officer` ADD INDEX `idx_kingdom_park_position` (`kingdom_id`,`park_id`,`position_id`);
  ```
  > Note: step 5a assumes coerced GMR rows have `authorization_id=0` (GMR/Champion bypass auth). If P1.1 found `role=''` rows with `authorization_id>0`, reconcile those manually before re-running and adjust the WHERE clause.
- [ ] Re-apply the full migration (INSERT IGNORE + IF NOT EXISTS keep it idempotent; the `ALTER ... ADD COLUMN` and `DROP INDEX` are not — if re-running on an already-migrated DB they error harmlessly on the already-applied steps; for a clean verification, reload the base DB first if needed):
  ```bash
  docker exec -i ork3-php8-db mariadb -u root -proot ork < migrations/officer-position.sql
  ```
- [ ] Verify seed + backfill + index swap:
  ```bash
  docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT canonical_key,title,classification,is_pinned,is_system,has_auth_role,sort_order,rbac_role_id FROM ork_officer_position WHERE kingdom_id=0 ORDER BY sort_order;"
  docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT COUNT(*) AS zero_pos FROM ork_officer WHERE position_id=0;"
  docker exec ork3-php8-db mariadb -u root -proot ork -e "SHOW INDEX FROM ork_officer WHERE Key_name IN ('kingdom_id','idx_kingdom_park_position');"
  ```
  Expected: 5 seeded rows with sort_order 10/20/30/40/50, `rbac_role_id` non-zero for all, `has_auth_role`=1 for monarch/regent/prime_minister and 0 for champion/gmr, gmr title `Guildmaster of Reeves`; `zero_pos`=0; only `idx_kingdom_park_position` present (no `kingdom_id` unique).
- [ ] `git add migrations/officer-position.sql && git commit -m "Enhancement: Seed Core Five positions + backfill position_id + swap unique index (P1)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P1.4 — Update ork.sql schema-of-record

**Modify** `ork.sql` so a fresh DB load matches the migrated shape. The `ork_officer` DDL is at `ork.sql:589-603` (`role` ENUM :594, `UNIQUE KEY kingdom_id` :599).

- [ ] Replace the `role` column line (single-line, Edit tool acceptable):
  - Old: `` `role` enum('Monarch','Regent','Prime Minister','Champion') NOT NULL, ``
  - New: `` `role` varchar(80) NOT NULL, `position_id` int(11) NOT NULL DEFAULT 0, ``
- [ ] Replace the unique-key line:
  - Old: `` UNIQUE KEY `kingdom_id` (`kingdom_id`,`park_id`,`role`), ``
  - New: `` KEY `idx_kingdom_park_position` (`kingdom_id`,`park_id`,`position_id`), ``
- [ ] Verify the DDL parses by loading into a scratch DB:
  ```bash
  docker exec ork3-php8-db mariadb -u root -proot -e "CREATE DATABASE IF NOT EXISTS ork_scratch;"
  docker exec -i ork3-php8-db mariadb -u root -proot ork_scratch < ork.sql
  docker exec ork3-php8-db mariadb -u root -proot ork_scratch -e "SHOW COLUMNS FROM ork_officer LIKE 'position_id'; SHOW COLUMNS FROM ork_officer LIKE 'role';"
  docker exec ork3-php8-db mariadb -u root -proot -e "DROP DATABASE ork_scratch;"
  ```
  Expected: `position_id` present (int, default 0), `role` is `varchar(80)`; no SQL error during load.
- [ ] `git add ork.sql && git commit -m "Enhancement: ork.sql schema-of-record matches officer migration (P1)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P1.5 — Patch create_officers/create_officer to seed position_id + canonical role cache (BUG-1)

**Modify** `system/lib/ork3/common.php`: `create_officers()` (:556-570), `create_officer()` (:572-598). Both branches (kingdom rows AND the `system=1` principality branch :563-569) must resolve the system seed `position_id` from `canonical_key` and write the canonical key into the `role` cache. The `$principality_id` param is dead (never stored) — do not build on it.

- [ ] First, rewrite `create_officers` to pass canonical keys (not display strings). Verify the needle, then replace via python3:
  ```bash
  python3 -c "
import pathlib
p=pathlib.Path('system/lib/ork3/common.php'); t=p.read_text()
OLD='''	public function create_officers( \$kingdom_id, \$park_id, \$principality_id = 0 )
	{
		\$this->create_officer( \$kingdom_id, \$park_id, 'Monarch', 'create' );
		\$this->create_officer( \$kingdom_id, \$park_id, 'Regent', 'create' );
		\$this->create_officer( \$kingdom_id, \$park_id, 'Prime Minister', 'create' );
		\$this->create_officer( \$kingdom_id, \$park_id, 'Champion', null );
		\$this->create_officer( \$kingdom_id, \$park_id, 'GMR', null );
		if ( valid_id( \$principality_id ) ) {
			\$this->create_officer( \$kingdom_id, \$park_id, 'Monarch', 'create', 1, \$principality_id );
			\$this->create_officer( \$kingdom_id, \$park_id, 'Regent', 'create', 1, \$principality_id );
			\$this->create_officer( \$kingdom_id, \$park_id, 'Prime Minister', 'create', 1, \$principality_id );
			\$this->create_officer( \$kingdom_id, \$park_id, 'Champion', null, 1, \$principality_id );
			\$this->create_officer( \$kingdom_id, \$park_id, 'GMR', null, 1, \$principality_id );
		}
	}'''
NEW='''	public function create_officers( \$kingdom_id, \$park_id, \$principality_id = 0 )
	{
		\$this->create_officer( \$kingdom_id, \$park_id, 'monarch', 'create' );
		\$this->create_officer( \$kingdom_id, \$park_id, 'regent', 'create' );
		\$this->create_officer( \$kingdom_id, \$park_id, 'prime_minister', 'create' );
		\$this->create_officer( \$kingdom_id, \$park_id, 'champion', null );
		\$this->create_officer( \$kingdom_id, \$park_id, 'gmr', null );
		if ( valid_id( \$principality_id ) ) {
			\$this->create_officer( \$kingdom_id, \$park_id, 'monarch', 'create', 1, \$principality_id );
			\$this->create_officer( \$kingdom_id, \$park_id, 'regent', 'create', 1, \$principality_id );
			\$this->create_officer( \$kingdom_id, \$park_id, 'prime_minister', 'create', 1, \$principality_id );
			\$this->create_officer( \$kingdom_id, \$park_id, 'champion', null, 1, \$principality_id );
			\$this->create_officer( \$kingdom_id, \$park_id, 'gmr', null, 1, \$principality_id );
		}
	}'''
print('found:', OLD in t); t=t.replace(OLD,NEW,1); p.write_text(t)
"
  ```
  > Authorization role strings (`'create'`/`null`) are the auth `Role` passed to `add_auth_h`; do NOT change those — only the officer `role` arg becomes the canonical key.
- [ ] Rewrite `create_officer` body to resolve & write `position_id` and pass it to `SyncNewOfficerSlot`:
  ```bash
  python3 -c "
import pathlib
p=pathlib.Path('system/lib/ork3/common.php'); t=p.read_text()
OLD='''	private function create_officer( \$kingdom_id, \$park_id, \$role, \$authorization, \$system = 0, \$principality_id = 0 )
	{
		\$this->officer->clear();
		\$this->officer->kingdom_id = \$kingdom_id;
		\$this->officer->park_id = \$park_id;
		\$this->officer->role = \$role;
		\$this->officer->system = \$system;
		\$this->officer->modified = time();'''
NEW='''	private function create_officer( \$kingdom_id, \$park_id, \$role, \$authorization, \$system = 0, \$principality_id = 0 )
	{
		// Resolve the system seed position_id for this canonical key (BUG-1).
		global \$DB;
		\$DB->Clear();
		\$DB->ck_role = \$role;
		\$_posrow = \$DB->DataSet( \"SELECT position_id FROM \" . DB_PREFIX . \"officer_position WHERE kingdom_id = 0 AND canonical_key = :ck_role LIMIT 1\" );
		\$position_id = ( \$_posrow !== false && \$_posrow->size() > 0 && \$_posrow->Next() ) ? (int)\$_posrow->position_id : 0;

		\$this->officer->clear();
		\$this->officer->kingdom_id = \$kingdom_id;
		\$this->officer->park_id = \$park_id;
		\$this->officer->role = \$role;
		\$this->officer->position_id = \$position_id;
		\$this->officer->system = \$system;
		\$this->officer->modified = time();'''
print('found:', OLD in t); t=t.replace(OLD,NEW,1); p.write_text(t)
"
  ```
- [ ] Update the `SyncNewOfficerSlot` call (:596) to pass `$position_id` (single-line context, python3):
  ```bash
  python3 -c "
import pathlib
p=pathlib.Path('system/lib/ork3/common.php'); t=p.read_text()
OLD='Ork3::\$Lib->rbacservice->SyncNewOfficerSlot( \$kingdom_id, \$park_id, \$role );'
NEW='Ork3::\$Lib->rbacservice->SyncNewOfficerSlot( \$kingdom_id, \$park_id, \$role, \$position_id );'
print('found:', OLD in t); t=t.replace(OLD,NEW,1); p.write_text(t)
"
  ```
- [ ] Verify PHP parses and the create path seeds position_id. Lint first:
  ```bash
  docker exec ork3-php8-db php -l /var/www/html/system/lib/ork3/common.php 2>/dev/null || php -l system/lib/ork3/common.php
  ```
  Expected: `No syntax errors detected`.
- [ ] Browser verification — create a test park (which calls `create_officers`), then assert no `position_id=0` rows were written. Open the kingdom admin park-create route (use the query-string scheme), create a park named `ZZ Officer Test`, then:
  ```bash
  docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT o.officer_id,o.role,o.position_id,o.park_id FROM ork_officer o JOIN ork_park p ON o.park_id=p.park_id WHERE p.name='ZZ Officer Test';"
  ```
  Expected: 5 rows, each with a canonical-key `role` (`monarch`/`regent`/`prime_minister`/`champion`/`gmr`) and a non-zero `position_id`. (Delete the test park afterward.)
- [ ] `git add system/lib/ork3/common.php && git commit -m "Bugfix: create_officers seeds position_id + canonical role cache (BUG-1, P1)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

# P2 — class.OfficerPosition + service/RBAC backend + dual-write

**Goal:** full DB service layer + RBAC sync-by-position + dual-write wiring; no UI yet.

**Spec checkpoint:** setting/vacating an officer via the service writes `position_id` + role cache + history snapshot, and grants/revokes the correct `ork_role`; crown single-occupant, crown-per-person (with `GET_LOCK`), and supporting-multi rules all enforced; the self-appointment guard blocks self-grant of a custom (`is_system=0`) officer role; the two new position-manage permissions exist and are held by Monarch/Regent/PM; `record_officer_history` no longer calls `mysql_real_escape_string`; legacy string path still works as fallback.

---

## Task P2.1 — Add new permissions to PermissionRegistry (BUG-3)

**Modify** `system/lib/ork3/class.PermissionRegistry.php`: add `kingdom.officer.position.manage` + `park.officer.position.manage` to `$permissions` (officer category). Format is `'key' => ['display_name','description','scope_type','category']`.

- [ ] Add the kingdom permission after the `kingdom.officer.set` entry. Verify needle, replace via python3:
  ```bash
  python3 -c "
import pathlib
p=pathlib.Path('system/lib/ork3/class.PermissionRegistry.php'); t=p.read_text()
OLD='''		'kingdom.officer.set' => [
			'Set Kingdom Officer',
			'Appoint kingdom-level officers',
			'kingdom', 'officer'
		],'''
NEW='''		'kingdom.officer.set' => [
			'Set Kingdom Officer',
			'Appoint kingdom-level officers',
			'kingdom', 'officer'
		],
		'kingdom.officer.position.manage' => [
			'Manage Kingdom Officer Positions',
			'Create, edit, classify, retire, and reinstate kingdom officer positions',
			'kingdom', 'officer'
		],'''
print('found:', OLD in t); t=t.replace(OLD,NEW,1); p.write_text(t)
"
  ```
- [ ] Add the park permission after `park.officer.set`:
  ```bash
  python3 -c "
import pathlib
p=pathlib.Path('system/lib/ork3/class.PermissionRegistry.php'); t=p.read_text()
OLD='''		'park.officer.set' => ['''
print('found marker:', OLD in t)
import re
# Insert a new block right after the park.officer.set [...] entry. Locate its closing '],'.
needle=\"'park.officer.set' => [\"
i=t.index(needle); j=t.index('],', i)+2
block='''
		'park.officer.position.manage' => [
			'Manage Park Officer Positions',
			'Create, edit, classify, retire, and reinstate park officer positions',
			'park', 'officer'
		],'''
t=t[:j]+block+t[j:]; p.write_text(t)
"
  ```
- [ ] Verify parse + registry contains both keys:
  ```bash
  php -l system/lib/ork3/class.PermissionRegistry.php
  grep -n "officer.position.manage" system/lib/ork3/class.PermissionRegistry.php
  ```
  Expected: no syntax errors; both `kingdom.officer.position.manage` and `park.officer.position.manage` present.
- [ ] `git add system/lib/ork3/class.PermissionRegistry.php && git commit -m "Enhancement: Register officer.position.manage permissions (BUG-3, P2)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P2.2 — Seed + grant the two new permissions (BUG-3)

**Modify** `migrations/rbac-seed.sql`: add the two permissions to the kingdom (line 19 area) and park (line 32 area) INSERT blocks. Monarch/Regent/PM receive ALL permissions via the CROSS JOIN at :109-128, so the kingdom-level grant is automatic once the permission row exists; for park roles we explicitly grant.

- [ ] Add `kingdom.officer.position.manage` to the kingdom-scoped INSERT (after `kingdom.officer.set` at :19). Single-line addition, Edit tool acceptable — insert this line after the `kingdom.officer.set` row:
  ```sql
  ('kingdom.officer.position.manage', 'Manage Kingdom Officer Positions', 'Create, edit, classify, retire, and reinstate kingdom officer positions', 'kingdom', 'officer', 1),
  ```
- [ ] Add `park.officer.position.manage` to the park-scoped INSERT (after `park.officer.set` at :32):
  ```sql
  ('park.officer.position.manage', 'Manage Park Officer Positions', 'Create, edit, classify, retire, and reinstate park officer positions', 'park', 'officer', 1),
  ```
- [ ] Append an explicit park-role grant block at the end of `migrations/rbac-seed.sql` (Monarch/Regent/PM already get it via kingdom CROSS JOIN; this covers park-admin roles if they use a scoped list — idempotent INSERT IGNORE):
  ```sql

  -- Officer Admin Expansion: grant park.officer.position.manage to park-admin officer roles
  INSERT IGNORE INTO `ork_role_permission` (`role_id`, `permission_id`)
  SELECT r.role_id, p.permission_id
  FROM `ork_role` r
  CROSS JOIN `ork_permission` p
  WHERE r.name IN ('monarch','regent','prime_minister') AND r.kingdom_id = 0
    AND p.`key` = 'park.officer.position.manage';
  ```
- [ ] Apply + verify:
  ```bash
  docker exec -i ork3-php8-db mariadb -u root -proot ork < migrations/rbac-seed.sql
  docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT \`key\`,scope_type FROM ork_permission WHERE \`key\` LIKE '%officer.position.manage%';"
  docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT r.name, p.\`key\` FROM ork_role_permission rp JOIN ork_role r ON r.role_id=rp.role_id JOIN ork_permission p ON p.permission_id=rp.permission_id WHERE p.\`key\`='kingdom.officer.position.manage' AND r.kingdom_id=0;"
  ```
  Expected: both permission keys present; `monarch`/`regent`/`prime_minister` granted `kingdom.officer.position.manage`.
- [ ] `git add migrations/rbac-seed.sql && git commit -m "Enhancement: Seed + grant officer.position.manage permissions (BUG-3, P2)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P2.3 — RBACService: SyncOfficerRoleByPositionId + SyncNewOfficerSlot signature + RoleIsOfficerBound + self-appointment guard (§4.2, §4.3, BUG-2, BUG-4)

**Modify** `system/lib/ork3/class.RBACService.php`. Self-appointment guard at :310-316; `SyncOfficerRole` at :685-740; `SyncNewOfficerSlot` at :751-757.

- [ ] Replace the self-appointment guard (BUG-2) — verify needle, python3:
  ```bash
  python3 -c "
import pathlib
p=pathlib.Path('system/lib/ork3/class.RBACService.php'); t=p.read_text()
OLD='''		// Self-appointment guard for officer roles
		if ( \$role->is_system && \$granter_id == \$target_id ) {
			\$officer_roles = [ 'monarch', 'regent', 'prime_minister', 'champion', 'gmr' ];
			if ( in_array( \$role->name, \$officer_roles ) ) {
				return NoAuthorization( 'Cannot assign officer roles to yourself.' );
			}
		}'''
NEW='''		// Self-appointment guard for officer roles (BUG-2): block self-grant of ANY
		// role bound to a non-retired officer position (system OR kingdom-custom).
		if ( \$granter_id == \$target_id ) {
			\$officer_bound = \$this->RoleIsOfficerBound( \$role_id );
			// Belt-and-suspenders fallback if the registry query failed.
			if ( !\$officer_bound ) {
				\$officer_roles = [ 'monarch', 'regent', 'prime_minister', 'champion', 'gmr' ];
				\$officer_bound = ( \$role->is_system && in_array( \$role->name, \$officer_roles ) );
			}
			if ( \$officer_bound ) {
				return NoAuthorization( 'Cannot assign officer roles to yourself.' );
			}
		}'''
print('found:', OLD in t); t=t.replace(OLD,NEW,1); p.write_text(t)
"
  ```
- [ ] Add `RoleIsOfficerBound` + `SyncOfficerRoleByPositionId` methods immediately before `SyncNewOfficerSlot`. Verify the `SyncNewOfficerSlot` doc-comment needle, python3 (insert before it):
  ```bash
  python3 -c "
import pathlib
p=pathlib.Path('system/lib/ork3/class.RBACService.php'); t=p.read_text()
ANCHOR='''	/**
	 * Create RBAC role assignments for newly created officer slots.'''
NEW='''	/**
	 * BUG-2 helper: is this role_id bound as the rbac_role_id of any non-retired
	 * officer position (system or kingdom-custom)?
	 *
	 * @param int \$role_id
	 * @return bool
	 */
	public function RoleIsOfficerBound( \$role_id )
	{
		global \$DB;
		\$DB->Clear();
		\$DB->rb_role_id = (int) \$role_id;
		\$r = \$DB->DataSet( \"SELECT 1 AS bound FROM \" . DB_PREFIX . \"officer_position WHERE rbac_role_id = :rb_role_id AND retired_at IS NULL LIMIT 1\" );
		return ( \$r !== false && \$r->size() > 0 );
	}

	/**
	 * Sync officer RBAC role using the position registry (no string map).
	 * Reads ork_officer_position.rbac_role_id directly, revokes from outgoing,
	 * grants to incoming. On vacate (new=0) only revokes.
	 *
	 * @param int \$old_officer_mundane_id
	 * @param int \$new_officer_mundane_id
	 * @param int \$position_id
	 * @param int \$kingdom_id
	 * @param int \$park_id
	 * @param int \$changed_by
	 */
	public function SyncOfficerRoleByPositionId( \$old_officer_mundane_id, \$new_officer_mundane_id, \$position_id, \$kingdom_id, \$park_id, \$changed_by )
	{
		global \$DB;
		\$old_officer_mundane_id = (int) \$old_officer_mundane_id;
		\$new_officer_mundane_id = (int) \$new_officer_mundane_id;
		\$position_id = (int) \$position_id;
		\$kingdom_id  = (int) \$kingdom_id;
		\$park_id     = (int) \$park_id;
		\$changed_by  = (int) \$changed_by;

		if ( \$position_id <= 0 ) { return; }

		\$DB->Clear();
		\$DB->pid = \$position_id;
		\$pr = \$DB->DataSet( \"SELECT rbac_role_id FROM \" . DB_PREFIX . \"officer_position WHERE position_id = :pid LIMIT 1\" );
		if ( \$pr === false || \$pr->size() == 0 || !\$pr->Next() ) { return; }
		\$rbac_role_id = (int) \$pr->rbac_role_id;
		if ( \$rbac_role_id <= 0 ) { return; }

		if ( \$old_officer_mundane_id > 0 ) {
			\$DB->Clear();
			\$DB->Execute(
				\"DELETE FROM \" . DB_PREFIX . \"user_role
				 WHERE mundane_id = \" . \$old_officer_mundane_id . \"
				   AND role_id = \" . \$rbac_role_id . \"
				   AND kingdom_id = \" . \$kingdom_id . \"
				   AND park_id = \" . \$park_id
			);
			\$this->IncrementGenerationCounter( \$old_officer_mundane_id );
		}

		if ( \$new_officer_mundane_id > 0 ) {
			\$granted_by_sql = ( \$changed_by > 0 ) ? \$changed_by : 'NULL';
			\$DB->Clear();
			\$DB->Execute(
				\"INSERT IGNORE INTO \" . DB_PREFIX . \"user_role
				 (mundane_id, role_id, kingdom_id, park_id, event_id, unit_id, granted_by, expires_at)
				 VALUES (\" . \$new_officer_mundane_id . \", \" . \$rbac_role_id . \", \" . \$kingdom_id . \", \" . \$park_id . \", 0, 0, \" . \$granted_by_sql . \", NULL)\"
			);
			\$this->IncrementGenerationCounter( \$new_officer_mundane_id );
		}
	}

	/**
	 * Create RBAC role assignments for newly created officer slots.'''
print('found:', ANCHOR in t); t=t.replace(ANCHOR,NEW,1); p.write_text(t)
"
  ```
- [ ] Widen `SyncNewOfficerSlot` signature (BUG-4) — single-line, python3:
  ```bash
  python3 -c "
import pathlib
p=pathlib.Path('system/lib/ork3/class.RBACService.php'); t=p.read_text()
OLD='public function SyncNewOfficerSlot( \$kingdom_id, \$park_id, \$role )'
NEW='public function SyncNewOfficerSlot( \$kingdom_id, \$park_id, \$role, \$position_id = 0 )'
print('found:', OLD in t); t=t.replace(OLD,NEW,1); p.write_text(t)
"
  ```
- [ ] Verify parse + presence:
  ```bash
  php -l system/lib/ork3/class.RBACService.php
  grep -n "function SyncOfficerRoleByPositionId\|function RoleIsOfficerBound\|function SyncNewOfficerSlot" system/lib/ork3/class.RBACService.php
  ```
  Expected: no syntax errors; all three methods present with the contracted signatures.
- [ ] `git add system/lib/ork3/class.RBACService.php && git commit -m "Enhancement: RBAC position-id sync + RoleIsOfficerBound self-appointment guard (BUG-2/BUG-4, P2)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P2.4 — common.php: set_officer position_id + has_auth_role, record_officer_history mysql_real_escape_string fix (§5.3, MINOR-2)

**Modify** `system/lib/ork3/common.php`: `set_officer` (:475-516), `record_officer_history` (:522-554).

- [ ] Rewrite `set_officer` to accept `$position_id`, use the position's `has_auth_role` (falling back to the legacy `'Champion'||'GMR'` string check when `$position_id=0`), pass `$position_id` to history + RBAC. python3:
  ```bash
  python3 -c "
import pathlib
p=pathlib.Path('system/lib/ork3/common.php'); t=p.read_text()
OLD='''	public function set_officer( \$kingdom_id, \$park_id, \$new_officer_id, \$role, \$system = 0, \$changed_by = 0 )
	{
		\$this->officer->clear();
		if (isset(\$kingdom_id)) \$this->officer->kingdom_id = \$kingdom_id;
		\$this->officer->park_id = \$park_id;
		\$this->officer->role = \$role;
		\$this->officer->system = \$system;
		if ( \$this->officer->find() ) {
			\$old_mundane_id = \$this->officer->mundane_id;
			\$officer_changed = false;

			if ( 'Champion' == \$role || 'GMR' == \$role) {
				\$this->officer->mundane_id = \$new_officer_id;
				\$this->officer->save();
				\$officer_changed = true;
			} else {'''
NEW='''	public function set_officer( \$kingdom_id, \$park_id, \$new_officer_id, \$role, \$system = 0, \$changed_by = 0, \$position_id = 0 )
	{
		global \$DB;
		// Resolve whether this position bypasses the ork_authorization path.
		// Prefer the registry has_auth_role flag; fall back to the legacy string check.
		\$bypass_auth = ( 'Champion' == \$role || 'GMR' == \$role );
		if ( (int)\$position_id > 0 ) {
			\$DB->Clear();
			\$DB->pid = (int)\$position_id;
			\$_har = \$DB->DataSet( \"SELECT has_auth_role FROM \" . DB_PREFIX . \"officer_position WHERE position_id = :pid LIMIT 1\" );
			if ( \$_har !== false && \$_har->size() > 0 && \$_har->Next() ) {
				\$bypass_auth = ( (int)\$_har->has_auth_role === 0 );
			}
		}

		\$this->officer->clear();
		if (isset(\$kingdom_id)) \$this->officer->kingdom_id = \$kingdom_id;
		\$this->officer->park_id = \$park_id;
		if ( (int)\$position_id > 0 ) {
			\$this->officer->position_id = (int)\$position_id;
		} else {
			\$this->officer->role = \$role;
		}
		\$this->officer->system = \$system;
		if ( \$this->officer->find() ) {
			\$old_mundane_id = \$this->officer->mundane_id;
			\$officer_changed = false;
			// Keep both the position_id and the canonical-key role cache in sync (dual-write).
			if ( (int)\$position_id > 0 ) {
				\$this->officer->position_id = (int)\$position_id;
				\$this->officer->role = \$role;
			}

			if ( \$bypass_auth ) {
				\$this->officer->mundane_id = \$new_officer_id;
				\$this->officer->save();
				\$officer_changed = true;
			} else {'''
print('found:', OLD in t); t=t.replace(OLD,NEW,1); p.write_text(t)
"
  ```
  > Note: the `find()` keyed on `position_id` requires that key to exist on the row (true post-P1 backfill). For legacy `$position_id=0` callers the find still keys on `role` as before.
- [ ] Update the history + RBAC sync block inside `set_officer` to prefer the position-id path. python3:
  ```bash
  python3 -c "
import pathlib
p=pathlib.Path('system/lib/ork3/common.php'); t=p.read_text()
OLD='''			// Record officer history only if the officer was actually changed
			if ( \$officer_changed && (int)\$old_mundane_id !== (int)\$new_officer_id ) {
				\$this->record_officer_history( \$kingdom_id, \$park_id, \$old_mundane_id, \$new_officer_id, \$role, \$changed_by );

				// RBAC dual-write: sync officer role to ork_user_role
				if ( isset( Ork3::\$Lib->rbacservice ) ) {
					try {
						Ork3::\$Lib->rbacservice->SyncOfficerRole( \$kingdom_id, \$park_id, \$old_mundane_id, \$new_officer_id, \$role, \$changed_by );
					} catch ( Exception \$e ) {
						logtrace( 'RBAC SyncOfficerRole failed', \$e->getMessage() );
					}
				}
			}'''
NEW='''			// Record officer history only if the officer was actually changed
			if ( \$officer_changed && (int)\$old_mundane_id !== (int)\$new_officer_id ) {
				\$this->record_officer_history( \$kingdom_id, \$park_id, \$old_mundane_id, \$new_officer_id, \$role, \$changed_by, \$position_id );

				// RBAC dual-write: prefer position-id sync, fall back to legacy string path.
				if ( isset( Ork3::\$Lib->rbacservice ) ) {
					try {
						if ( (int)\$position_id > 0 ) {
							Ork3::\$Lib->rbacservice->SyncOfficerRoleByPositionId( \$old_mundane_id, \$new_officer_id, \$position_id, \$kingdom_id, \$park_id, \$changed_by );
						} else {
							Ork3::\$Lib->rbacservice->SyncOfficerRole( \$kingdom_id, \$park_id, \$old_mundane_id, \$new_officer_id, \$role, \$changed_by );
						}
					} catch ( Exception \$e ) {
						logtrace( 'RBAC sync officer failed', \$e->getMessage() );
					}
				}
			}'''
print('found:', OLD in t); t=t.replace(OLD,NEW,1); p.write_text(t)
"
  ```
- [ ] Rewrite `record_officer_history` to accept `$position_id`, write `position_id` + `display_label`, and replace `mysql_real_escape_string` (MINOR-2) with bound parameters. python3:
  ```bash
  python3 -c "
import pathlib
p=pathlib.Path('system/lib/ork3/common.php'); t=p.read_text()
OLD='''	private function record_officer_history( \$kingdom_id, \$park_id, \$old_mundane_id, \$new_mundane_id, \$role, \$changed_by = 0 )
	{
		global \$DB;
		\$kid  = (int)\$kingdom_id;
		\$pid  = (int)\$park_id;
		\$role_esc = mysql_real_escape_string( \$role );
		\$cb   = (int)\$changed_by;
		\$today = date( 'Y-m-d' );

		// Close any open history record for this role (where end_date IS NULL)
		if ( (int)\$old_mundane_id > 0 ) {
			\$DB->Clear();
			\$DB->Execute(
				\"UPDATE \" . DB_PREFIX . \"officer_history
				 SET end_date = '\" . \$today . \"'
				 WHERE kingdom_id = \" . \$kid . \"
				   AND park_id = \" . \$pid . \"
				   AND role = '\" . \$role_esc . \"'
				   AND end_date IS NULL\"
			);
		}

		// Open a new history record for the incoming officer (skip if vacating)
		if ( (int)\$new_mundane_id > 0 ) {
			\$mid = (int)\$new_mundane_id;
			\$DB->Clear();
			\$DB->Execute(
				\"INSERT INTO \" . DB_PREFIX . \"officer_history
				 (kingdom_id, park_id, mundane_id, role, start_date, end_date, changed_by, created_at)
				 VALUES (\" . \$kid . \", \" . \$pid . \", \" . \$mid . \", '\" . \$role_esc . \"', '\" . \$today . \"', NULL, \" . (\$cb > 0 ? \$cb : 'NULL') . \", NOW())\"
			);
		}
	}'''
NEW='''	private function record_officer_history( \$kingdom_id, \$park_id, \$old_mundane_id, \$new_mundane_id, \$role, \$changed_by = 0, \$position_id = 0 )
	{
		global \$DB;
		\$kid  = (int)\$kingdom_id;
		\$pid  = (int)\$park_id;
		\$posid = (int)\$position_id;
		\$cb   = (int)\$changed_by;
		\$today = date( 'Y-m-d' );

		// Resolve the snapshot DisplayTitle for this position at write time (requirement 7).
		// IF(alias != '', alias, title) — never COALESCE.
		\$display_label = \$role;
		if ( \$posid > 0 ) {
			\$DB->Clear();
			\$DB->dl_pid = \$posid;
			\$DB->dl_kid = \$kid;
			\$dl = \$DB->DataSet(
				\"SELECT IF(p.kingdom_id = 0,
				             IF(a.title_alias IS NOT NULL AND a.title_alias != '', a.title_alias, p.title),
				             IF(p.title_alias != '', p.title_alias, p.title)) AS display_title
				 FROM \" . DB_PREFIX . \"officer_position p
				 LEFT JOIN \" . DB_PREFIX . \"officer_position_alias a
				   ON a.kingdom_id = :dl_kid AND a.canonical_key = p.canonical_key
				 WHERE p.position_id = :dl_pid LIMIT 1\"
			);
			if ( \$dl !== false && \$dl->size() > 0 && \$dl->Next() ) {
				\$display_label = (string)\$dl->display_title;
			}
		}

		// Close any open history record for this role (where end_date IS NULL)
		if ( (int)\$old_mundane_id > 0 ) {
			\$DB->Clear();
			\$DB->h_today = \$today;
			\$DB->h_kid = \$kid;
			\$DB->h_pid = \$pid;
			\$DB->h_role = \$role;
			\$DB->Execute(
				\"UPDATE \" . DB_PREFIX . \"officer_history
				 SET end_date = :h_today
				 WHERE kingdom_id = :h_kid
				   AND park_id = :h_pid
				   AND role = :h_role
				   AND end_date IS NULL\"
			);
		}

		// Open a new history record for the incoming officer (skip if vacating)
		if ( (int)\$new_mundane_id > 0 ) {
			\$mid = (int)\$new_mundane_id;
			\$DB->Clear();
			\$DB->i_kid = \$kid;
			\$DB->i_pid = \$pid;
			\$DB->i_mid = \$mid;
			\$DB->i_role = \$role;
			\$DB->i_posid = \$posid;
			\$DB->i_label = \$display_label;
			\$DB->i_today = \$today;
			\$DB->i_cb = (\$cb > 0 ? \$cb : null);
			\$DB->Execute(
				\"INSERT INTO \" . DB_PREFIX . \"officer_history
				 (kingdom_id, park_id, mundane_id, role, position_id, display_label, start_date, end_date, changed_by, created_at)
				 VALUES (:i_kid, :i_pid, :i_mid, :i_role, :i_posid, :i_label, :i_today, NULL, :i_cb, NOW())\"
			);
		}
	}'''
print('found:', OLD in t); t=t.replace(OLD,NEW,1); p.write_text(t)
"
  ```
- [ ] Verify parse + no remaining `mysql_real_escape_string` in this function:
  ```bash
  php -l system/lib/ork3/common.php
  grep -n "mysql_real_escape_string" system/lib/ork3/common.php
  ```
  Expected: no syntax errors. (`mysql_real_escape_string` may still appear elsewhere in the file — confirm it is gone from `record_officer_history`; other call sites are out of scope.)
- [ ] `git add system/lib/ork3/common.php && git commit -m "Enhancement: set_officer/record_officer_history position_id + display_label; fix mysql_real_escape_string PHP8 fatal (MINOR-2, P2)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P2.5 — class.OfficerPosition.php (registry + occupancy-enforced writes)

**Create** `system/lib/ork3/class.OfficerPosition.php`. DB layer; `$DB->Clear()` before every raw `Execute`/`DataSet`. Implements the full signature contract. Occupancy enforcement per §3.4 (crown single-per-scope + crown-per-person via `GET_LOCK` + supporting-multi).

- [ ] Create the class following the existing `system/lib/ork3/class.*.php` conventions (extends `Ork3`, auto-loaded as `Ork3::$Lib->officerposition`). Implement:
  - **`GetPositions($kingdom_id, $include_retired=false, $classification=null)`** — `SELECT p.*, IF(p.kingdom_id=0, IF(a.title_alias IS NOT NULL AND a.title_alias!='', a.title_alias, p.title), IF(p.title_alias!='', p.title_alias, p.title)) AS DisplayTitle FROM ork_officer_position p LEFT JOIN ork_officer_position_alias a ON a.kingdom_id = :kingdom_id AND a.canonical_key = p.canonical_key WHERE (p.kingdom_id = 0 OR p.kingdom_id = :kingdom_id) [AND p.retired_at IS NULL] [AND p.classification = :classification] ORDER BY p.classification, p.sort_order`. Each row also exposes `CanonicalKey = canonical_key`.
  - **`GetPosition($position_id)`** — single row + `DisplayTitle` (resolve with a passed/derived kingdom_id) + `rbac_role_id` + permission summary (`SELECT p.key FROM ork_role_permission rp JOIN ork_permission p ... WHERE rp.role_id = rbac_role_id`).
  - **`CreatePosition($kingdom_id, $canonical_key, $title, $classification, $rbac_choice)`** — slugify/validate `canonical_key` unique within `(kingdom_id, canonical_key)`; if `$rbac_choice['mode']==='custom'` call `Ork3::$Lib->rbacservice->CreateRole($creator_id, $kingdom_id, 'officer:'.$slug, $title, '', 'kingdom', $rbac_choice['permission_keys'])` and use the returned `role_id`; else use `$rbac_choice['role_id']`; `sort_order` = max in group + 10; INSERT with `is_pinned=0, is_system=0, has_auth_role=0`.
  - **`EditPosition($position_id, $fields)`** — reject pinned classification/RBAC edits server-side (read `is_pinned`); update `title`/`title_alias`/`sort_order` always; for non-pinned, update `classification` + `rbac_role_id` (or upsert custom role via `EditRole`); when binding changes call the §4.4 reconciliation (revoke old role / grant new for all live occupants of this position).
  - **`RetirePosition($position_id, $changed_by)`** — reject if `is_pinned` OR `is_system`; collect live occupants (`mundane_id>0`) for the warning/audit; call `VacateOfficerByPosition` for each scope; `UPDATE ... SET retired_at=NOW()`. Return the list of vacated occupants.
  - **`ReinstatePosition($position_id)`** — `UPDATE ... SET retired_at=NULL`. Classification is the unchanged column value (no snapshot needed).
  - **`GetOfficersForDisplay($kingdom_id, $park_id, $include_retired=false)`** — JOIN `ork_officer` → `ork_officer_position` on `position_id`, group into `['crown'=>[...],'supporting'=>[...]]`, each row carrying `CanonicalKey`, `DisplayTitle`, occupant `MundaneId`/`Persona`, term line; filter retired unless requested.
  - **`SetOfficerByPosition($kingdom_id, $park_id, $position_id, $mundane_id, $term_start, $term_end, $note, $changed_by)`** — load position; if `classification='crown'`: `SELECT GET_LOCK('crown_assign_'.(int)$mundane_id, 5)` (abort with a clear error if 0/NULL); inside, run the crown-per-person global check (`SELECT ... FROM ork_officer o JOIN ork_officer_position p ON p.position_id=o.position_id WHERE p.classification='crown' AND p.retired_at IS NULL AND o.mundane_id = :mundane_id AND NOT (o.kingdom_id=:k AND o.park_id=:p AND o.position_id=:pos) LIMIT 1` → reject if found with the spec error message including the conflicting DisplayTitle+scope); vacate any existing live occupant of this exact `(kingdom,park,position)`; delegate the actual write to `Common::set_officer($kingdom_id,$park_id,$mundane_id,$canonical_key,$system,$changed_by,$position_id)`; `RELEASE_LOCK(...)` in a `finally`. Supporting: no lock, no global check, allow multiple rows.
  - **`VacateOfficerByPosition($kingdom_id, $park_id, $position_id, $changed_by)`** — close term + revoke role via `Common::set_officer(...,$mundane_id=0,...,$position_id)`; crown leaves a `mundane_id=0` placeholder; supporting deletes the row.
- [ ] Verify parse:
  ```bash
  php -l system/lib/ork3/class.OfficerPosition.php
  ```
  Expected: `No syntax errors detected`.
- [ ] Verify GetPositions returns the Core Five for an existing kingdom (replace `1` with a real kingdom_id). Write a one-off probe via the query-string route is heavy; instead assert the underlying SQL directly:
  ```bash
  docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT p.canonical_key, IF(p.kingdom_id=0, IF(a.title_alias IS NOT NULL AND a.title_alias!='', a.title_alias, p.title), IF(p.title_alias!='', p.title_alias, p.title)) AS DisplayTitle FROM ork_officer_position p LEFT JOIN ork_officer_position_alias a ON a.kingdom_id=1 AND a.canonical_key=p.canonical_key WHERE (p.kingdom_id=0 OR p.kingdom_id=1) AND p.retired_at IS NULL ORDER BY p.classification, p.sort_order;"
  ```
  Expected: 5 Core-Five rows with DisplayTitle = system titles (no alias rows yet).
- [ ] `git add system/lib/ork3/class.OfficerPosition.php && git commit -m "Enhancement: class.OfficerPosition registry + occupancy-enforced writes (GET_LOCK) (P2)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P2.6 — model.OfficerPosition.php (thin passthrough)

**Create** `orkui/model/model.OfficerPosition.php`. Thin `__call` passthrough + presentation transforms only (architecture-layers rule). No DB logic.

- [ ] Create the model mirroring the existing `orkui/model/model.*.php` pattern (constructor wiring `$this->lib = Ork3::$Lib->officerposition` or equivalent, `__call` forwarding). Add only presentation transforms if a controller needs reshaped data; otherwise pure passthrough.
- [ ] Verify parse:
  ```bash
  php -l orkui/model/model.OfficerPosition.php
  ```
  Expected: no syntax errors.
- [ ] `git add orkui/model/model.OfficerPosition.php && git commit -m "Enhancement: model.OfficerPosition thin passthrough (P2)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P2.7 — Kingdom::SetOfficer + Park::SetOfficer position_id pass-through (MAJOR-3)

**Modify** `class.Kingdom.php` `SetOfficer` (:623-661, prior-holder yapo keyed on `role` at :631-636, `set_officer` call :640) and `class.Park.php` `SetOfficer` (:910-954, prior yapo :928-932, `set_officer` call :935).

- [ ] In `class.Kingdom.php`, resolve the `position_id` from the request Role (canonical key or display string), key the prior-holder lookup on `position_id`, and pass `position_id` to `set_officer`. python3:
  ```bash
  python3 -c "
import pathlib
p=pathlib.Path('system/lib/ork3/class.Kingdom.php'); t=p.read_text()
OLD='''				\$_priorOfficer = new yapo(\$this->db, DB_PREFIX . 'officer');
				\$_priorOfficer->clear();
				\$_priorOfficer->kingdom_id = (int)\$request['KingdomId'];
				\$_priorOfficer->park_id    = 0;
				\$_priorOfficer->role       = \$request['Role'];
				\$_priorMundaneId = \$_priorOfficer->find() ? (int)\$_priorOfficer->mundane_id : 0;

				\$officer = new yapo(\$this->db, DB_PREFIX . 'officer');
				\$c = new Common();
				\$c->set_officer(\$request['KingdomId'], 0, \$request['MundaneId'], \$request['Role'], 0, \$mundane_id);'''
NEW='''				// Resolve the position_id for this Role (accepts canonical key or legacy display string).
				\$_positionId = Ork3::\$Lib->officerposition->ResolvePositionId((int)\$request['KingdomId'], \$request['Role']);
				\$_canonicalKey = Ork3::\$Lib->officerposition->ResolveCanonicalKey((int)\$request['KingdomId'], \$request['Role']);

				\$_priorOfficer = new yapo(\$this->db, DB_PREFIX . 'officer');
				\$_priorOfficer->clear();
				\$_priorOfficer->kingdom_id = (int)\$request['KingdomId'];
				\$_priorOfficer->park_id    = 0;
				if ( \$_positionId > 0 ) { \$_priorOfficer->position_id = \$_positionId; }
				else { \$_priorOfficer->role = \$request['Role']; }
				\$_priorMundaneId = \$_priorOfficer->find() ? (int)\$_priorOfficer->mundane_id : 0;

				\$c = new Common();
				\$c->set_officer(\$request['KingdomId'], 0, \$request['MundaneId'], \$_canonicalKey, 0, \$mundane_id, \$_positionId);'''
print('found:', OLD in t); t=t.replace(OLD,NEW,1); p.write_text(t)
"
  ```
  > Add two small helpers to `class.OfficerPosition.php` (P2.5 file) used here: `ResolvePositionId($kingdom_id, $roleOrKey)` (returns position_id by matching `canonical_key` or normalizing a display string) and `ResolveCanonicalKey($kingdom_id, $roleOrKey)` (returns the canonical key string). Append these to that class and re-lint it.
- [ ] In `class.Park.php`, same pattern. python3:
  ```bash
  python3 -c "
import pathlib
p=pathlib.Path('system/lib/ork3/class.Park.php'); t=p.read_text()
OLD='''			\$_priorOfficer = new yapo( \$this->db, DB_PREFIX . 'officer' );
			\$_priorOfficer->clear();
			\$_priorOfficer->park_id = (int)\$request[ 'ParkId' ];
			\$_priorOfficer->role    = \$request[ 'Role' ];
			\$_priorMundaneId = \$_priorOfficer->find() ? (int)\$_priorOfficer->mundane_id : 0;

			\$c = new Common();
			\$c->set_officer( \$kingdomId, \$request[ 'ParkId' ], \$request[ 'MundaneId' ], \$request[ 'Role' ], 0, \$mundane_id );'''
NEW='''			\$_positionId = Ork3::\$Lib->officerposition->ResolvePositionId((int)\$kingdomId, \$request[ 'Role' ]);
			\$_canonicalKey = Ork3::\$Lib->officerposition->ResolveCanonicalKey((int)\$kingdomId, \$request[ 'Role' ]);

			\$_priorOfficer = new yapo( \$this->db, DB_PREFIX . 'officer' );
			\$_priorOfficer->clear();
			\$_priorOfficer->park_id = (int)\$request[ 'ParkId' ];
			if ( \$_positionId > 0 ) { \$_priorOfficer->position_id = \$_positionId; }
			else { \$_priorOfficer->role = \$request[ 'Role' ]; }
			\$_priorMundaneId = \$_priorOfficer->find() ? (int)\$_priorOfficer->mundane_id : 0;

			\$c = new Common();
			\$c->set_officer( \$kingdomId, \$request[ 'ParkId' ], \$request[ 'MundaneId' ], \$_canonicalKey, 0, \$mundane_id, \$_positionId );'''
print('found:', OLD in t); t=t.replace(OLD,NEW,1); p.write_text(t)
"
  ```
- [ ] Verify parse for all three files:
  ```bash
  php -l system/lib/ork3/class.Kingdom.php && php -l system/lib/ork3/class.Park.php && php -l system/lib/ork3/class.OfficerPosition.php
  ```
  Expected: no syntax errors.
- [ ] Browser/endpoint verification: open the legacy Set Officers admin for a kingdom (`http://localhost:19080/orkui/index.php?Route=Admin/setofficers/<kingdomId>`), set the Regent to a member, save. Then assert the row carries position_id + canonical role:
  ```bash
  docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT officer_id,role,position_id,mundane_id FROM ork_officer WHERE kingdom_id=<kingdomId> AND park_id=0 AND role='regent';"
  ```
  Expected: `role='regent'`, `position_id` non-zero, `mundane_id` = the member you set. Also confirm the `ork_user_role` grant:
  ```bash
  docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT mundane_id,role_id,kingdom_id,park_id FROM ork_user_role WHERE mundane_id=<member> AND kingdom_id=<kingdomId>;"
  ```
  Expected: a row for the `regent` system role at that kingdom.
- [ ] `git add system/lib/ork3/class.Kingdom.php system/lib/ork3/class.Park.php system/lib/ork3/class.OfficerPosition.php && git commit -m "Enhancement: Kingdom/Park SetOfficer pass position_id through (MAJOR-3, P2)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P2.8 — Verify occupancy rules end-to-end (P2 checkpoint)

Verify the §3.4 rules via SQL probes after exercising the service. No new code; this is the P2 checkpoint gate.

- [ ] **Crown single-per-scope:** set kingdom Monarch to player A, then to player B (legacy admin form or `SetOfficerByPosition`). Assert exactly one live monarch row:
  ```bash
  docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT COUNT(*) AS live_monarch FROM ork_officer WHERE kingdom_id=<k> AND park_id=0 AND role='monarch' AND mundane_id>0;"
  ```
  Expected: `live_monarch`=1 (player B); player A's `ork_user_role` monarch grant for that scope is gone.
- [ ] **Crown-per-person global:** with player B as kingdom Monarch, attempt to also set B as a park Champion-equivalent crown elsewhere via `SetOfficerByPosition`. Expected: rejection with "This person already holds a Crown office: Monarch in <scope>...". Confirm no new live row was written.
- [ ] **Supporting-multi:** create a custom supporting position (defer to P4 UI, or insert a test supporting row directly) and assign two different players; assert both rows persist:
  ```bash
  docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT COUNT(*) FROM ork_officer o JOIN ork_officer_position p ON p.position_id=o.position_id WHERE p.classification='supporting' AND p.kingdom_id=<k> AND o.mundane_id>0;"
  ```
  Expected: 2.
- [ ] **Self-appointment guard (custom role):** as a monarch (granter==target), attempt `AssignRole` of a custom `is_system=0` officer-bound role to self → expect `NoAuthorization('Cannot assign officer roles to yourself.')`. Verify via the RBAC assign route or a direct call; confirm no `ork_user_role` row was inserted.
- [ ] No commit (verification-only checkpoint). If any probe fails, return to the relevant P2 task and fix before proceeding to P3.

---

# P3 — Read-path alias plumbing

**Goal:** every read surface returns/renders `CanonicalKey` + `DisplayTitle`; JS matches by `canonical_key`.

**Spec checkpoint:** aliasing a position (DB edit) changes the displayed title everywhere in §6 while all JS officer logic still functions; the hero still resolves monarch/regent after `OfficerRole` normalizes to canonical keys; no raw `role` display string drives any comparison.

---

## Task P3.1 — Kingdom::GetOfficers + Park::GetOfficers rewrite (return CanonicalKey + DisplayTitle)

**Modify** `class.Kingdom.php` `GetOfficers` (:569-621; ORDER BY FIELD :583; response :602/:613) and `class.Park.php` `GetOfficers` (:381-407; ORDER BY FIELD :396). JOIN `ork_officer_position`, LEFT JOIN alias table for Core Five, `ORDER BY p.sort_order`, filter retired, emit `CanonicalKey`+`DisplayTitle`; keep `Role`/`OfficerRole` = canonical_key for back-compat.

- [ ] Rewrite the Kingdom `$sql` and the response assembly. python3 for the SQL block:
  ```bash
  python3 -c "
import pathlib
p=pathlib.Path('system/lib/ork3/class.Kingdom.php'); t=p.read_text()
OLD='''		\$sql = \"select a.*, p.name as park_name, k.name as kingdom_name, e.name as event_name, u.name as unit_name, m.mundane_id as m_mundane_id, m.username, m.given_name, m.surname, m.persona, m.restricted, o.role as officer_role, o.officer_id
					from \" . DB_PREFIX . \"officer o
						left join \" . DB_PREFIX . \"mundane m on o.mundane_id = m.mundane_id
						left join \" . DB_PREFIX . \"authorization a on a.authorization_id = o.authorization_id
							left join \".DB_PREFIX.\"park p on a.park_id = p.park_id
							left join \".DB_PREFIX.\"kingdom k on a.kingdom_id = k.kingdom_id
							left join \".DB_PREFIX.\"event e on a.event_id = e.event_id
							left join \".DB_PREFIX.\"unit u on a.unit_id = u.unit_id
				where o.kingdom_id = '\" . \$kingdom_id . \"' and o.park_id = 0
				order by FIELD(o.role, 'Monarch', 'Regent', 'Prime Minister', 'Champion', 'GMR'), o.role
			\";'''
NEW='''		\$sql = \"select a.*, p.name as park_name, k.name as kingdom_name, e.name as event_name, u.name as unit_name, m.mundane_id as m_mundane_id, m.username, m.given_name, m.surname, m.persona, m.restricted, o.role as officer_role, o.officer_id, o.position_id,
					op.canonical_key as canonical_key,
					IF(op.kingdom_id = 0, IF(al.title_alias IS NOT NULL AND al.title_alias != '', al.title_alias, op.title), IF(op.title_alias != '', op.title_alias, op.title)) as display_title
					from \" . DB_PREFIX . \"officer o
						left join \" . DB_PREFIX . \"officer_position op on op.position_id = o.position_id
						left join \" . DB_PREFIX . \"officer_position_alias al on al.kingdom_id = '\" . \$kingdom_id . \"' and al.canonical_key = op.canonical_key
						left join \" . DB_PREFIX . \"mundane m on o.mundane_id = m.mundane_id
						left join \" . DB_PREFIX . \"authorization a on a.authorization_id = o.authorization_id
							left join \".DB_PREFIX.\"park p on a.park_id = p.park_id
							left join \".DB_PREFIX.\"kingdom k on a.kingdom_id = k.kingdom_id
							left join \".DB_PREFIX.\"event e on a.event_id = e.event_id
							left join \".DB_PREFIX.\"unit u on a.unit_id = u.unit_id
				where o.kingdom_id = '\" . \$kingdom_id . \"' and o.park_id = 0
				  and (op.retired_at IS NULL or op.position_id IS NULL)
				order by op.classification, op.sort_order, o.role
			\";'''
print('found:', OLD in t); t=t.replace(OLD,NEW,1); p.write_text(t)
"
  ```
- [ ] Update the Kingdom response assembly to add `CanonicalKey`+`DisplayTitle` and set `Role`/`OfficerRole` to the canonical key. python3:
  ```bash
  python3 -c "
import pathlib
p=pathlib.Path('system/lib/ork3/class.Kingdom.php'); t=p.read_text()
OLD='''							'Role' => \$r->role,'''
NEW='''							'Role' => \$r->canonical_key !== null ? \$r->canonical_key : \$r->role,
							'CanonicalKey' => \$r->canonical_key !== null ? \$r->canonical_key : \$r->role,
							'DisplayTitle' => \$r->display_title !== null ? \$r->display_title : \$r->role,'''
print('found:', OLD in t); t=t.replace(OLD,NEW,1); p.write_text(t)
"
  python3 -c "
import pathlib
p=pathlib.Path('system/lib/ork3/class.Kingdom.php'); t=p.read_text()
OLD='''							'OfficerRole' => \$r->officer_role'''
NEW='''							'OfficerRole' => \$r->canonical_key !== null ? \$r->canonical_key : \$r->officer_role'''
print('found:', OLD in t); t=t.replace(OLD,NEW,1); p.write_text(t)
"
  ```
- [ ] Apply the identical SQL + response rewrite to `class.Park.php` `GetOfficers` (read :381-420 first to capture the exact park response-assembly lines, then mirror the python3 edits — the SQL `where` clause uses `o.park_id = '<park_id>' and o.kingdom_id > 0`).
- [ ] Verify parse:
  ```bash
  php -l system/lib/ork3/class.Kingdom.php && php -l system/lib/ork3/class.Park.php
  ```
  Expected: no syntax errors.
- [ ] Endpoint verification: alias the Core-Five Regent for a kingdom, then confirm GetOfficers returns the alias as DisplayTitle but `regent` as CanonicalKey. Seed an alias directly:
  ```bash
  docker exec ork3-php8-db mariadb -u root -proot ork -e "INSERT INTO ork_officer_position_alias (kingdom_id,canonical_key,title_alias) VALUES (<k>,'regent','Prince Consort') ON DUPLICATE KEY UPDATE title_alias='Prince Consort';"
  docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT o.role, op.canonical_key, IF(op.kingdom_id=0, IF(al.title_alias!='', al.title_alias, op.title), IF(op.title_alias!='', op.title_alias, op.title)) AS DisplayTitle FROM ork_officer o JOIN ork_officer_position op ON op.position_id=o.position_id LEFT JOIN ork_officer_position_alias al ON al.kingdom_id=<k> AND al.canonical_key=op.canonical_key WHERE o.kingdom_id=<k> AND o.park_id=0 AND op.canonical_key='regent';"
  ```
  Expected: `canonical_key`=`regent`, `DisplayTitle`=`Prince Consort`. (Clean up the alias row afterward.)
- [ ] `git add system/lib/ork3/class.Kingdom.php system/lib/ork3/class.Park.php && git commit -m "Enhancement: GetOfficers returns CanonicalKey + DisplayTitle, sort_order ordering (P3)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P3.2 — controller.Player.php live-officer query (MINOR-5)

**Modify** `orkui/controller/controller.Player.php` officer query (:397-419). JOIN the registry + alias table; return `canonical_key` (for matching) and `DisplayTitle` (for live display). This is the live-officer list, distinct from history (which uses `display_label`).

- [ ] Rewrite the query + result mapping. python3:
  ```bash
  python3 -c "
import pathlib
p=pathlib.Path('orkui/controller/controller.Player.php'); t=p.read_text()
OLD='''		\$officerSql   = \"SELECT o.role, o.park_id,
			CASE WHEN o.park_id > 0 THEN IFNULL(pt.title, 'Park') ELSE 'Kingdom' END AS entity_type,
			CASE WHEN o.park_id > 0 THEN p.name ELSE k.name END AS entity_name
			FROM ork_officer o
			LEFT JOIN ork_kingdom k ON o.kingdom_id = k.kingdom_id
			LEFT JOIN ork_park p ON o.park_id = p.park_id AND o.park_id > 0
			LEFT JOIN ork_parktitle pt ON p.parktitle_id = pt.parktitle_id
			WHERE o.mundane_id = \" . (int)\$id . \"
			  AND k.active = 'Active'
			  AND (o.park_id = 0 OR p.active = 'Active')
			ORDER BY o.park_id DESC, o.role\";
		\$officerResult = \$DB->DataSet(\$officerSql);
		\$officerRoles  = [];
		if (\$officerResult->Size() > 0) {
			while (\$officerResult->Next()) {
				\$officerRoles[] = [
					'role'        => \$officerResult->role,
					'entity_type' => \$officerResult->entity_type,
					'entity_name' => \$officerResult->entity_name,
				];
			}
		}'''
NEW='''		\$officerSql   = \"SELECT o.role, o.park_id, o.position_id,
			op.canonical_key AS canonical_key,
			IF(op.kingdom_id = 0, IF(al.title_alias IS NOT NULL AND al.title_alias != '', al.title_alias, op.title), IF(op.title_alias != '', op.title_alias, op.title)) AS display_title,
			CASE WHEN o.park_id > 0 THEN IFNULL(pt.title, 'Park') ELSE 'Kingdom' END AS entity_type,
			CASE WHEN o.park_id > 0 THEN p.name ELSE k.name END AS entity_name
			FROM ork_officer o
			LEFT JOIN ork_officer_position op ON op.position_id = o.position_id
			LEFT JOIN ork_officer_position_alias al ON al.kingdom_id = o.kingdom_id AND al.canonical_key = op.canonical_key
			LEFT JOIN ork_kingdom k ON o.kingdom_id = k.kingdom_id
			LEFT JOIN ork_park p ON o.park_id = p.park_id AND o.park_id > 0
			LEFT JOIN ork_parktitle pt ON p.parktitle_id = pt.parktitle_id
			WHERE o.mundane_id = \" . (int)\$id . \"
			  AND k.active = 'Active'
			  AND (o.park_id = 0 OR p.active = 'Active')
			  AND (op.retired_at IS NULL OR op.position_id IS NULL)
			ORDER BY o.park_id DESC, op.classification, op.sort_order\";
		\$officerResult = \$DB->DataSet(\$officerSql);
		\$officerRoles  = [];
		if (\$officerResult->Size() > 0) {
			while (\$officerResult->Next()) {
				\$officerRoles[] = [
					'role'          => \$officerResult->role,
					'canonical_key' => \$officerResult->canonical_key !== null ? \$officerResult->canonical_key : \$officerResult->role,
					'DisplayTitle'  => \$officerResult->display_title !== null ? \$officerResult->display_title : \$officerResult->role,
					'entity_type'   => \$officerResult->entity_type,
					'entity_name'   => \$officerResult->entity_name,
				];
			}
		}'''
print('found:', OLD in t); t=t.replace(OLD,NEW,1); p.write_text(t)
"
  ```
- [ ] Verify parse:
  ```bash
  php -l orkui/controller/controller.Player.php
  ```
  Expected: no syntax errors.
- [ ] Browser verification: open a player profile who holds an officer role (`http://localhost:19080/orkui/index.php?Route=Playernew/index/<mundaneId>`); confirm the live officer badge shows the DisplayTitle (aliased title if the kingdom aliased it). No raw canonical key visible. (Then continue to template edit P3.5 if the template renders `role` directly.)
- [ ] `git add orkui/controller/controller.Player.php && git commit -m "Enhancement: Player profile live-officer query returns canonical_key + DisplayTitle (MINOR-5, P3)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P3.3 — Create _officer_panel.tpl partial

**Create** `orkui/template/revised-frontend/partials/_officer_panel.tpl`. Params: `$officers` (grouped, alias-resolved, retired-filtered) + `$mode` (`'sidebar'` = Crown only / `'about'` = all groups). Renders `DisplayTitle`; dark-mode-safe.

- [ ] Create the partial: iterate `$officers['crown']` always; iterate `$officers['supporting']` only when `$mode === 'about'`. Render `<span class="kn-officer-role"><?= htmlspecialchars($o['DisplayTitle']) ?></span>` + occupant persona/link. Reset the global orkui.css `h1-h6` gray pill on any heading used inside the panel (`style` via a class, not inline color). Use `data-tip` not `title` for any tooltip. No inline `style="color:#xxx"`.
- [ ] Verify it renders with sample data by including it on the Kingdomnew sidebar in P3.5; for now confirm no PHP parse issue:
  ```bash
  php -l orkui/template/revised-frontend/partials/_officer_panel.tpl
  ```
  Expected: no syntax errors.
- [ ] `git add orkui/template/revised-frontend/partials/_officer_panel.tpl && git commit -m "Enhancement: _officer_panel.tpl reusable sidebar/About partial (P3)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P3.4 — PHP hero/sidebar extraction → canonical-key matching (MAJOR-4)

**Modify** `Kingdomnew_index.tpl` hero loop (:28-31: `=== 'Monarch'`/`=== 'Regent'`) and `Parknew_index.tpl` hero loop (:19-21). Match on `CanonicalKey` (`'monarch'`/`'regent'`), render `DisplayTitle`. These break silently once `OfficerRole` normalizes to canonical keys.

- [ ] Kingdomnew. python3:
  ```bash
  python3 -c "
import pathlib
p=pathlib.Path('orkui/template/revised-frontend/Kingdomnew_index.tpl'); t=p.read_text()
OLD='''	foreach (\$officerList as \$o) {
		if (\$o['OfficerRole'] === 'Monarch') \$monarch = \$o;
		if (\$o['OfficerRole'] === 'Regent')  \$regent  = \$o;'''
NEW='''	foreach (\$officerList as \$o) {
		\$_ck = \$o['CanonicalKey'] ?? \$o['OfficerRole'] ?? '';
		if (\$_ck === 'monarch') \$monarch = \$o;
		if (\$_ck === 'regent')  \$regent  = \$o;'''
print('found:', OLD in t); t=t.replace(OLD,NEW,1); p.write_text(t)
"
  ```
- [ ] Parknew. python3 (read :18-22 first to capture exact whitespace, then):
  ```bash
  python3 -c "
import pathlib
p=pathlib.Path('orkui/template/revised-frontend/Parknew_index.tpl'); t=p.read_text()
OLD='''	foreach (\$officerList as \$o) {
		if (\$o['OfficerRole'] === 'Monarch') \$monarch = \$o;
		if (\$o['OfficerRole'] === 'Regent')  \$regent  = \$o;'''
NEW='''	foreach (\$officerList as \$o) {
		\$_ck = \$o['CanonicalKey'] ?? \$o['OfficerRole'] ?? '';
		if (\$_ck === 'monarch') \$monarch = \$o;
		if (\$_ck === 'regent')  \$regent  = \$o;'''
print('found:', OLD in t); t=t.replace(OLD,NEW,1); p.write_text(t)
"
  ```
- [ ] Verify parse:
  ```bash
  php -l orkui/template/revised-frontend/Kingdomnew_index.tpl && php -l orkui/template/revised-frontend/Parknew_index.tpl
  ```
  Expected: no syntax errors.
- [ ] Browser verification: open `Kingdomnew/index/<k>` and a `Parknew/index/<park>` profile; confirm the hero still shows the current Monarch/Regent (now matched by canonical key). Alias the Regent title in DB and reload — hero should show the aliased title.
- [ ] `git add orkui/template/revised-frontend/Kingdomnew_index.tpl orkui/template/revised-frontend/Parknew_index.tpl && git commit -m "Bugfix: hero/sidebar officer extraction matches canonical_key not display string (MAJOR-4, P3)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P3.5 — Sidebar/About rendering switches to DisplayTitle + uses partial

**Modify** sidebar/About blocks to render `DisplayTitle` (not raw `OfficerRole`) and adopt `_officer_panel.tpl`: `Kingdomnew_index.tpl:183` (`htmlspecialchars($o['OfficerRole'])`), About at :180-193; `Parknew_index.tpl:424`, :411-440; `Kingdom_index.tpl:17,20`; `Park_index.tpl:25,28`; `Playernew_index.tpl:1056-1057,1479-1482` (live list = DisplayTitle; history = `display_label`).

- [ ] In `Kingdomnew_index.tpl`, change the sidebar role render to DisplayTitle. python3:
  ```bash
  python3 -c "
import pathlib
p=pathlib.Path('orkui/template/revised-frontend/Kingdomnew_index.tpl'); t=p.read_text()
OLD='''<span class=\"kn-officer-role\"><?= htmlspecialchars(\$o['OfficerRole']) ?></span>'''
NEW='''<span class=\"kn-officer-role\"><?= htmlspecialchars(\$o['DisplayTitle'] ?? \$o['OfficerRole']) ?></span>'''
print('found:', OLD in t); t=t.replace(OLD,NEW,1); p.write_text(t)
"
  ```
- [ ] Apply the analogous `DisplayTitle` swap in `Parknew_index.tpl` (read :422-426 first to capture the exact officer-role render line), `Kingdom_index.tpl` (:17,20), `Park_index.tpl` (:25,28), and `Playernew_index.tpl` (:1056-1057 live list → `DisplayTitle`; :1479-1482 history → `display_label`). For each, verify the needle then python3-replace `OfficerRole`/`role` render with `DisplayTitle` (live) or `display_label` (history).
- [ ] Verify parse for each edited template:
  ```bash
  for f in orkui/template/revised-frontend/Kingdomnew_index.tpl orkui/template/revised-frontend/Parknew_index.tpl orkui/template/default/Playernew_index.tpl; do php -l "$f"; done
  ```
  Expected: no syntax errors.
- [ ] Browser verification: alias a Core-Five title for a kingdom in DB; open Kingdomnew, Parknew (a park in that kingdom), and a Playernew profile of an officer there. Confirm the aliased DisplayTitle shows in every sidebar/About; confirm the player's officer HISTORY still shows the original `display_label` snapshot (not the new alias).
- [ ] `git add orkui/template/revised-frontend/Kingdomnew_index.tpl orkui/template/revised-frontend/Parknew_index.tpl orkui/template/default/Playernew_index.tpl <Kingdom_index.tpl path> <Park_index.tpl path> && git commit -m "Enhancement: sidebar/About/player render DisplayTitle; history uses display_label snapshot (P3)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P3.6 — officerList JSON additions (NIT-2)

**Modify** the officerList JSON `array_map` in `Kingdomnew_index.tpl:1091` and `Parknew_index.tpl:1621-1623`. Add `CanonicalKey`+`DisplayTitle` per entry; KEEP `OfficerRole` during dual-write.

- [ ] Kingdomnew. python3:
  ```bash
  python3 -c "
import pathlib
p=pathlib.Path('orkui/template/revised-frontend/Kingdomnew_index.tpl'); t=p.read_text()
OLD='''return ['OfficerRole' => \$o['OfficerRole'], 'MundaneId' => (int)\$o['MundaneId'], 'Persona' => \$o['Persona']];'''
NEW='''return ['OfficerRole' => \$o['OfficerRole'], 'CanonicalKey' => \$o['CanonicalKey'] ?? \$o['OfficerRole'], 'DisplayTitle' => \$o['DisplayTitle'] ?? \$o['OfficerRole'], 'MundaneId' => (int)\$o['MundaneId'], 'Persona' => \$o['Persona']];'''
print('found:', OLD in t); t=t.replace(OLD,NEW,1); p.write_text(t)
"
  ```
- [ ] Parknew: read :1621-1623 first to capture the exact `array_map` body, then python3-replace to add the same two keys.
- [ ] Verify parse + JSON shape via browser: open Kingdomnew, view source / console `KnConfig.officerList` — confirm each entry has `OfficerRole`, `CanonicalKey`, `DisplayTitle`, `MundaneId`, `Persona`.
  ```bash
  php -l orkui/template/revised-frontend/Kingdomnew_index.tpl && php -l orkui/template/revised-frontend/Parknew_index.tpl
  ```
- [ ] `git add orkui/template/revised-frontend/Kingdomnew_index.tpl orkui/template/revised-frontend/Parknew_index.tpl && git commit -m "Enhancement: officerList JSON adds CanonicalKey + DisplayTitle (NIT-2, P3)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P3.7 — revised.js canonical-key matching

**Modify** `orkui/template/revised-frontend/script/revised.js`: replace static `OFFICER_ROLES` arrays (:3928, :9125) with registry-driven keys derived from `KnConfig.officerList`/`PkConfig.officerList`; switch all role-equality comparisons (:3977, :3996, :4106, :9171, :9190, :9292) from display-string matching to `CanonicalKey` matching, rendering `DisplayTitle`.

- [ ] Read each of the two `OFFICER_ROLES` blocks and their `forEach`/match sites first (lines around 3928-4106 and 9125-9292) to capture the exact JS. Then replace the static array with a derivation from the embedded officerList (canonical keys) and update each comparison to compare `entry.CanonicalKey` and render `entry.DisplayTitle`. `.tpl`/`.js` multi-line edits: if an Edit attempt fails, switch to python3 immediately.
- [ ] Verify no `'Monarch'`/`'Regent'`/`'Prime Minister'`/`'Champion'`/`'GMR'` display-string literals remain in the officer-matching paths:
  ```bash
  grep -n "OFFICER_ROLES\|'Monarch'\|'Prime Minister'\|'GMR'" orkui/template/revised-frontend/script/revised.js | head -40
  ```
  Expected: the static `OFFICER_ROLES` literal arrays are gone; remaining matches (if any) are not in officer-assignment logic.
- [ ] Browser verification: open Kingdomnew Officer History tab and the officer admin areas that this JS drives; confirm officer dropdowns/matching still function after aliasing a title (the matched entry persists by canonical key; the label shows DisplayTitle). Check the browser console for `ReferenceError`/`undefined` errors.
- [ ] `git add orkui/template/revised-frontend/script/revised.js && git commit -m "Enhancement: revised.js officer matching keys on CanonicalKey, renders DisplayTitle (P3)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

# P4 — Manage Officers UI (tab, cards, Create/Edit modal, Set Occupant, reclassify)

**Goal:** kingdom admins can create/edit/classify positions and set/vacate occupants from the new tab; principality profiles render it too.

**Spec checkpoint:** create a custom supporting position with a custom permission set, alias a Core-Five title (writes `ork_officer_position_alias`), set/vacate occupants; pinned controls disabled-with-tooltip; server re-checks the correct permission per action (position.manage vs officer.set); the same flows work on a principality profile keyed at the principality's `kingdom_id`; dark-mode walkthrough passes.

---

## Task P4.1 — controller.OfficerAdminAjax.php (all actions, per-action permission gates, BUG-3)

**Create** `orkui/controller/controller.OfficerAdminAjax.php`. Actions: `list`, `setOccupant`, `vacate`, `createPosition`, `editPosition`, `reclassify`, `retire`, `reinstate`. Every action re-checks the correct permission server-side. Debug → `die(json_encode(...))`, never `error_log`.

- [ ] Create the controller (mirror existing `controller.*Ajax.php` patterns: token auth via `Ork3::$Lib->authorization->IsAuthorized`, JSON response). Permission gates (BUG-3 — two distinct authorities):
  - `setOccupant`, `vacate` → `kingdom.officer.set` (kingdom scope) / `park.officer.set` (park scope) via `Ork3::$Lib->authorization->HasPermissionOrAuthority($uid, 'kingdom.officer.set', 'kingdom', $kingdomId, AUTH_EDIT)`.
  - `createPosition`, `editPosition`, `reclassify`, `retire`, `reinstate` → `kingdom.officer.position.manage` / `park.officer.position.manage`.
  - `list` → readable by anyone who can view the profile (or gate on the same set permission).
  - Each action returns `NoAuthorization()` JSON if its specific gate fails.
  - Scope detection: a `ParkId` in the request → park scope (`park.*` permission, `$kingdomId` resolved from the park); else kingdom scope (`KingdomId`). Principalities use `kingdom` scope at the principality's own `kingdom_id`.
  - All DB work delegates to `Ork3::$Lib->officerposition` methods (P2.5 contract).
- [ ] Verify parse:
  ```bash
  php -l orkui/controller/controller.OfficerAdminAjax.php
  ```
  Expected: no syntax errors.
- [ ] Endpoint verification (gate correctness): as a user WITHOUT `kingdom.officer.position.manage` but WITH `kingdom.officer.set`, call the `createPosition` action route and confirm a `NoAuthorization` JSON response; call `setOccupant` and confirm it is permitted. Use:
  ```
  http://localhost:19080/orkui/index.php?Route=OfficerAdminAjax/createPosition (POST with token + fields)
  ```
  Expected: `createPosition` → NoAuthorization; `setOccupant` → success path. (Note local login bypass may grant all — verify gate logic by reading the action code returns the correct permission key per action.)
- [ ] `git add orkui/controller/controller.OfficerAdminAjax.php && git commit -m "Enhancement: controller.OfficerAdminAjax with per-action permission gates (BUG-3, P4)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P4.2 — _officer_position_modal.tpl (Create/Edit + Set Occupant)

**Create** `orkui/template/revised-frontend/partials/_officer_position_modal.tpl`. Reuse `kn-modal-*` pattern. Fields per §7.3/§7.4.

- [ ] Build the Create/Edit Position modal: Title (text); Display alias (text, clears via empty string); Classification segmented control (locked to Crown + disabled for pinned); RBAC choice segmented ("Use existing role" = `<select>` of system + this-kingdom roles with a permission-preview panel | "Build custom permission set" = category-grouped checkboxes from `PermissionRegistry::GetAll()` filtered to kingdom scope).
- [ ] Build the Set Occupant modal/expander: player search using custom `kn-ac-results` dropdown ONLY (never jQuery UI), kingdom-scoped (park cards scope park then kingdom). Call `tnFixedAcPosition(inputEl, dropdownEl)` before EVERY `.classList.add('kn-ac-open')` in BOTH the no-results and results branches. Term Start flatpickr (default today, `altInput:true, altFormat:'F j, Y  h:i K'`), optional End, optional note.
- [ ] Dark-mode: reset orkui.css global `h1-h6` gray pill on the modal header (`background:transparent;border:none;padding:0;border-radius:0;`); ghost/cancel button contrast; no inline `style="color:#xxx"`; `data-tip` not `title`.
- [ ] Verify parse:
  ```bash
  php -l orkui/template/revised-frontend/partials/_officer_position_modal.tpl
  ```
  Expected: no syntax errors.
- [ ] `git add orkui/template/revised-frontend/partials/_officer_position_modal.tpl && git commit -m "Enhancement: _officer_position_modal.tpl Create/Edit + Set Occupant modal (P4)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P4.3 — Kingdomnew Manage Officers tab + KnConfig.canManageOfficers

**Modify** `Kingdomnew_index.tpl`: add the tab nav item + `kn-tab-manageofficers` panel (cards grouped Crown/Supporting; per-card Set Occupant/Vacate/Edit/Reclassify/Retire|Reinstate; pinned controls disabled + `data-tip`); add `KnConfig.canManageOfficers` (:1080-1091) from the `kingdom.officer.position.manage` check; include the two partials.

- [ ] Server-gate the tab on `Authorization::HasPermission($uid, 'kingdom.officer.position.manage', 'kingdom', $id)`; the set/vacate controls inside additionally gated on `kingdom.officer.set`. Compute both flags in the controller and emit into the template.
- [ ] Add `canManageOfficers` to `KnConfig`. python3 (after the `canManage:` line at :1086):
  ```bash
  python3 -c "
import pathlib
p=pathlib.Path('orkui/template/revised-frontend/Kingdomnew_index.tpl'); t=p.read_text()
OLD='''	canManage:        <?= !empty(\$CanManageKingdom) ? 'true' : 'false' ?>,'''
NEW='''	canManage:        <?= !empty(\$CanManageKingdom) ? 'true' : 'false' ?>,
	canManageOfficers: <?= !empty(\$CanManageOfficerPositions) ? 'true' : 'false' ?>,'''
print('found:', OLD in t); t=t.replace(OLD,NEW,1); p.write_text(t)
"
  ```
  > Wire `$CanManageOfficerPositions` in `controller.Kingdom.php` from the `kingdom.officer.position.manage` permission check; this is a distinct flag from `$CanManageKingdom`.
- [ ] Add the tab nav `<li>` (after the existing officer-history nav item) and the `kn-tab-manageofficers` panel rendering `Ork3::$Lib->officerposition->GetOfficersForDisplay(...)` grouped cards. Render Crown then Supporting groups. Pinned Core-Five cards show Reclassify + Retire disabled with `data-tip="Core office — classification and retirement are locked"`. Term line `Term: {start} → {end | (current)}`.
- [ ] Verify parse:
  ```bash
  php -l orkui/template/revised-frontend/Kingdomnew_index.tpl
  ```
  Expected: no syntax errors.
- [ ] Browser verification: open `Kingdomnew/index/<k>`. As an admin, confirm the Manage Officers tab appears, cards grouped Crown/Supporting, pinned controls disabled with tooltip on hover. Walk the tab in dark mode (no gray pills on headings, readable ghost buttons).
- [ ] `git add orkui/template/revised-frontend/Kingdomnew_index.tpl <controller.Kingdom.php if edited> && git commit -m "Enhancement: Kingdomnew Manage Officers tab + KnConfig.canManageOfficers (P4)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P4.4 — revised.js Manage Officers handlers (config-flag IIFE guard)

**Modify** `revised.js`: add the open/submit handlers for the Create/Edit Position + Set Occupant + reclassify flows, calling `controller.OfficerAdminAjax`. The IIFE guard at the top of the new section MUST use `KnConfig.canManageOfficers` (config flag), NOT `document.getElementById` (the modal HTML is defined after the `<script src>` and is not yet in the DOM at script load).

- [ ] Add the section guarded by `if (!(window.KnConfig && KnConfig.canManageOfficers) && !(window.PkConfig && PkConfig.canManageOfficers)) return;`. Implement: open Create/Edit modal, populate role `<select>` from a `list` Ajax call or embedded config, RBAC-choice segmented toggle, custom-permission checkbox group, submit to `createPosition`/`editPosition`; Set Occupant modal with `kn-ac-results` autocomplete (call `tnFixedAcPosition` before EVERY `.classList.add('kn-ac-open')` in both branches), flatpickr term picker; reclassify dropdown → `reclassify` action; vacate → `vacate`.
- [ ] Verify no `getElementById`-based guard at the section top:
  ```bash
  grep -n "canManageOfficers" orkui/template/revised-frontend/script/revised.js
  ```
  Expected: the new section's guard references `KnConfig.canManageOfficers`/`PkConfig.canManageOfficers`.
- [ ] Browser verification: as admin on Kingdomnew Manage Officers tab — create a custom Supporting position (custom permission set), confirm it appears as a card; set an occupant via the modal (autocomplete dropdown positions correctly inside the modal, not clipped); alias a Core-Five title via Edit and confirm the sidebar/hero update on reload. Console clean of errors.
- [ ] `git add orkui/template/revised-frontend/script/revised.js && git commit -m "Enhancement: revised.js Manage Officers handlers, config-flag IIFE guard (P4)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P4.5 — Parknew Manage Officers tab (park scope)

**Modify** `Parknew_index.tpl`: same tab/cards/config-flag at park scope. Gate on `park.officer.position.manage` (registry) + `park.officer.set` (set/vacate). Add the analogous `PkConfig.canManageOfficers` flag.

- [ ] Mirror P4.3 in `Parknew_index.tpl`: add the Manage Officers tab + panel, add `canManageOfficers` to `PkConfig`, include the partials. Set/vacate scope = park then kingdom for player search. The controller resolves the park's kingdom_id for crown-per-person checks (which span kingdom+park).
- [ ] Verify parse:
  ```bash
  php -l orkui/template/revised-frontend/Parknew_index.tpl
  ```
  Expected: no syntax errors.
- [ ] Browser verification: open `Parknew/index/<park>` as a park admin; Manage Officers tab works; create/set/vacate at park scope; dark-mode walk.
- [ ] `git add orkui/template/revised-frontend/Parknew_index.tpl <controller.Park.php if edited> && git commit -m "Enhancement: Parknew Manage Officers tab (park scope) (P4)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P4.6 — Principality profile renders Manage Officers + partials (§8)

**Modify** the principality profile template path (the template that renders a principality — a kingdom with `parent_kingdom_id > 0`; `class.Principality.php`/`model.Principality.php` delegate to Kingdom, so this is display-only). Render the position-aware sidebar/About partial + Manage Officers tab, gated at the principality's OWN `kingdom_id`. No method-signature changes in Principality.

- [ ] Confirm the principality profile reuses `Kingdomnew_index.tpl` (or its own template). If it reuses Kingdomnew, P4.3 already covers it — verify the permission flags are evaluated at the principality's own `kingdom_id` (which they are, since `$id` is the principality's `kingdom_id`). If there is a separate principality template, add the tab + partials there with the same gating.
- [ ] Verify parse of any edited template; browser-verify on a real principality profile (`Kingdomnew/index/<principalityKingdomId>` or the principality route): Manage Officers tab renders, gated on `kingdom.officer.position.manage` at the principality's `kingdom_id`; create a principality-custom position (stored as `ork_officer_position.kingdom_id = <principality id>`); assert:
  ```bash
  docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT position_id,kingdom_id,canonical_key,classification FROM ork_officer_position WHERE kingdom_id=<principalityKingdomId>;"
  ```
  Expected: the custom row stored under the principality's own `kingdom_id`; no new scope column.
- [ ] `git add <principality template path if edited> && git commit -m "Enhancement: principality profile renders Manage Officers + officer partials (P4, §8)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`
  > If no separate template edit was required (Kingdomnew reuse), record that in the task notes and skip the commit.

---

## Task P4.7 — Admin_setofficers fallback banner

**Modify** `Admin_setofficers.tpl`: keep the legacy form, add a banner linking to the new Manage Officers tab.

- [ ] Add a banner (dark-mode-safe info box, no inline color, no native `title`) at the top: "Officer management has moved — open the Manage Officers tab" linking to `Kingdomnew/index/<kingdomId>` (Manage Officers tab anchor) / `Parknew/index/<parkId>`.
- [ ] Verify parse:
  ```bash
  php -l <Admin_setofficers.tpl path>
  ```
  Expected: no syntax errors.
- [ ] Browser verification: open `Admin/setofficers/<k>`; confirm the banner renders and links correctly; the legacy form still functions (writes position_id via P2.7 SetOfficer).
- [ ] `git add <Admin_setofficers.tpl path> && git commit -m "Enhancement: Admin_setofficers fallback banner to Manage Officers tab (P4)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

# P5 — Retire / Reinstate

**Goal:** retire (auto-vacate + warning) and reinstate flows.

**Spec checkpoint:** retiring an occupied position warns first, then closes terms + revokes roles + hides the position from pickers/sidebar/About/reports; reinstate restores it with prior classification.

---

## Task P5.1 — Retire/Reinstate UI (warning modal + Retired disclosure)

**Modify** `Kingdomnew_index.tpl` (+ `Parknew_index.tpl` for park scope) Manage Officers tab: add a collapsible "Retired Positions" disclosure (top-right) with per-row Reinstate; wire the Retire control to a `kn-confirm` warning modal listing who will be auto-vacated. **Modify** `revised.js` retire/reinstate handlers. `controller.OfficerAdminAjax` `retire`/`reinstate` actions already exist from P4.1 (gated on `*.officer.position.manage`); `class.OfficerPosition::RetirePosition`/`ReinstatePosition` exist from P2.5.

- [ ] Add the Retired disclosure markup (rendered from `GetPositions($kingdom_id, $include_retired=true)` filtered to `retired_at IS NOT NULL`); per-row Reinstate button → `reinstate` action.
- [ ] Wire the Retire control: on click, call `OfficerAdminAjax/retire` in a "preview" mode OR have the card already know its live occupants; show a `kn-confirm` warning modal: "Retiring {DisplayTitle} will end the current term for {occupant(s)} and remove their officer permissions. Continue?" Only on confirm send the actual retire. Pinned/system positions: Retire disabled with `data-tip` (already from P4.3); server also rejects (P2.5).
- [ ] Dark-mode: warning modal header pill reset; muted "retired" text readable; ghost cancel contrast.
- [ ] Verify parse of edited templates; browser verification: retire an occupied custom position → warning lists the occupant → confirm → the position disappears from cards/sidebar/About and pickers; the occupant's `ork_user_role` grant is gone:
  ```bash
  docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT retired_at FROM ork_officer_position WHERE position_id=<pos>; SELECT COUNT(*) FROM ork_user_role WHERE mundane_id=<occupant> AND role_id=(SELECT rbac_role_id FROM ork_officer_position WHERE position_id=<pos>) AND kingdom_id=<k>;"
  ```
  Expected: `retired_at` non-null; user_role count 0. Then Reinstate from the disclosure → `retired_at` NULL, classification unchanged, position reappears.
- [ ] `git add orkui/template/revised-frontend/Kingdomnew_index.tpl orkui/template/revised-frontend/Parknew_index.tpl orkui/template/revised-frontend/script/revised.js && git commit -m "Enhancement: Retire (auto-vacate + warning) / Reinstate UI (P5)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

# P6 — Reports directory dynamic rewrite + permissions UI

**Goal:** officer directory and permissions UI driven by the registry.

**Spec checkpoint:** directory renders a variable number of grouped columns + correct vacancy counters for a kingdom with custom positions; retired excluded; the permissions-grid stats bar shows live per-role permission counts (no hardcoded "99 / 116") and includes any custom officer roles; permissions UI reflects DB-backed bindings.

---

## Task P6.1 — Reports_kingdomofficerdirectory.tpl dynamic-column rewrite

**Modify** `Reports_kingdomofficerdirectory.tpl` (hardcoded vacancy counters :10-16; per-role columns :71-87, :102-129). Full rewrite: render a variable number of columns grouped Crown then Supporting from `GetPositions()`, vacancy counters dynamic, retired excluded. Supporting report controller/model as needed.

- [ ] Update the report controller to pass `GetPositions($kingdom_id)` (retired-excluded default) + `GetOfficersForDisplay` per park/kingdom into the template `$data`.
- [ ] Rewrite the template: build the column header set dynamically from the positions (Crown group then Supporting group, ordered by sort_order, render DisplayTitle); each data row maps occupants per `position_id`/`canonical_key`; vacancy counters computed by counting positions with `mundane_id=0`/no holder per column. No hardcoded role names or counts.
- [ ] Verify parse; browser verification: open `Reports/kingdomofficerdirectory&KingdomId=<k>` for a kingdom that has a custom Supporting position. Confirm a column for the custom position appears, vacancy counters are correct, retired positions are absent. Dark-mode walk.
- [ ] `git add <Reports_kingdomofficerdirectory.tpl path> <report controller/model if edited> && git commit -m "Enhancement: officer directory dynamic-column rewrite from registry (P6)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P6.2 — Admin_permissions_grid stats bar dynamic rewrite (MAJOR-6)

**Modify** `Admin_permissions_grid.tpl`: the hardcoded `.pg-stats-bar` (:327-368 — hardcoded headers Monarch/Regent/PM/Champion/GMR/ORK Admin and counts "Total Permissions 116", "Monarch 99 / 116" width 85.3%, etc.) and the column headers (:405+). Rewrite to render columns + counts dynamically from DB-backed roles + `ork_role_permission` counts.

- [ ] Update the controller feeding this grid to compute: `total = COUNT(ork_permission)`; per-role `held = COUNT(ork_role_permission)` for each role; include any custom officer roles bound via `ork_officer_position.rbac_role_id`. Pass an array of `[role_display_name, held, total, width_pct]`.
- [ ] Rewrite `.pg-stats-bar` (:327-368) and the `<th>` column headers (:405+) to loop over that array — no hardcoded names, counts, or widths. `width` = `round(held/total*100, 1)` percent.
- [ ] Verify parse; browser verification: open `Admin/permissions_grid/<k>` (query-string route); confirm the stats bar shows live counts (not "99 / 116" literally unless that is the real count) and includes any custom officer roles created in P4. Add a custom officer role, reload, confirm a new column appears. Dark-mode walk.
- [ ] `git add orkui/template/revised-frontend/Admin_permissions_grid.tpl <controller if edited> && git commit -m "Enhancement: permissions-grid stats bar dynamic per-role counts/columns (MAJOR-6, P6)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P6.3 — Admin_permissions.tpl DB-backed officer bindings

**Modify** `Admin_permissions.tpl:196` (officer-role references) to read the DB-backed position→role binding rather than the hardcoded map.

- [ ] Replace the officer-role reference at :196 with a lookup over `GetPositions()` / `ork_officer_position.rbac_role_id` bindings (controller passes the binding data).
- [ ] Verify parse; browser verification: open `Admin/permissions/<k>`; confirm officer-role bindings reflect the registry (custom positions appear with their bound role). Dark-mode walk.
- [ ] `git add <Admin_permissions.tpl path> <controller if edited> && git commit -m "Enhancement: Admin_permissions officer bindings read DB-backed registry (P6)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

# P7 — Cleanup (drop legacy map / role cache after cutover)

**Goal:** remove dual-write scaffolding once safe.

**Spec checkpoint:** zero live `ork_officer` rows with `position_id=0`; no code references the legacy map; full regression of set/vacate/retire/display/reports passes.

---

## Task P7.1 — Confirm zero position_id=0 rows (cutover gate)

Verification-only gate before any removal.

- [ ] Assert no live rows lack a position_id:
  ```bash
  docker exec ork3-php8-db mariadb -u root -proot ork -e "SELECT COUNT(*) AS zero_pos FROM ork_officer WHERE position_id=0;"
  ```
  Expected: `zero_pos`=0. If non-zero, STOP — re-run the backfill / inspect the create path before proceeding. No commit.

---

## Task P7.2 — Remove legacy $officerRoleMap / OfficerRoleToRbacRole / GetOfficerRoleMap

**Modify** `system/lib/ork3/class.PermissionRegistry.php`: remove `$officerRoleMap` (:318-324), `OfficerRoleToRbacRole` (:403-406), `GetOfficerRoleMap` (:413-416).

- [ ] Confirm no remaining callers first:
  ```bash
  grep -rn "OfficerRoleToRbacRole\|GetOfficerRoleMap\|officerRoleMap" system/ orkui/ | grep -v "class.PermissionRegistry.php"
  ```
  Expected: empty (no external callers). If `SyncOfficerRole` still calls `OfficerRoleToRbacRole`, defer to P7.3 order (remove `SyncOfficerRole` first or together).
- [ ] Remove the three members via python3 (one replace per member, verifying each needle).
- [ ] Verify parse:
  ```bash
  php -l system/lib/ork3/class.PermissionRegistry.php
  ```
  Expected: no syntax errors.
- [ ] `git add system/lib/ork3/class.PermissionRegistry.php && git commit -m "Cleanup: remove legacy officerRoleMap + OfficerRoleToRbacRole (P7)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P7.3 — Remove SyncOfficerRole + hardcoded self-appointment fallback list

**Modify** `system/lib/ork3/class.RBACService.php`: remove the legacy `SyncOfficerRole` (:685-740) and the hardcoded `['monarch','regent','prime_minister','champion','gmr']` belt-and-suspenders fallback in the self-appointment guard (now relies solely on `RoleIsOfficerBound`).

- [ ] Confirm no remaining callers of `SyncOfficerRole`:
  ```bash
  grep -rn "SyncOfficerRole\b" system/ orkui/ | grep -v "SyncOfficerRoleByPositionId"
  ```
  Expected: empty (the `common.php` fallback branch was the only caller and is removed since `position_id` is now always present).
- [ ] Remove the `common.php` legacy-fallback branch in `set_officer` (the `else { SyncOfficerRole(...) }`) since `position_id>0` always holds post-cutover. python3.
- [ ] Remove `SyncOfficerRole` method and the fallback list in the guard via python3.
- [ ] Verify parse:
  ```bash
  php -l system/lib/ork3/class.RBACService.php && php -l system/lib/ork3/common.php
  ```
  Expected: no syntax errors.
- [ ] Browser regression: set/vacate an officer, retire/reinstate, view a profile, view the directory — all function with the legacy path removed.
- [ ] `git add system/lib/ork3/class.RBACService.php system/lib/ork3/common.php && git commit -m "Cleanup: remove legacy SyncOfficerRole + hardcoded self-appointment list (P7)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Task P7.4 — Drop the ork_officer.role cache column

**Create** `migrations/officer-position-cleanup.sql`: drop the denormalized `role` cache once all reads use `position_id`.

- [ ] First confirm no code still reads `ork_officer.role` for logic (only the registry JOIN should remain):
  ```bash
  grep -rn "o.role\|officer->role\|->role =" system/lib/ork3/ orkui/ | grep -i officer | head -30
  ```
  Expected: any remaining references are the position-registry JOINs or display fallbacks already superseded — review each; if a read still depends on the cache, fix it before dropping.
- [ ] Create `migrations/officer-position-cleanup.sql`:
  ```sql
  -- Officer Admin Expansion P7 cleanup: drop the denormalized role cache.
  -- Run only after confirming zero position_id=0 rows and no code reads o.role for logic.
  ALTER TABLE `ork_officer` DROP COLUMN `role`;
  ```
- [ ] Apply + verify; also update `ork.sql` to drop the `role` column from the `ork_officer` DDL:
  ```bash
  docker exec -i ork3-php8-db mariadb -u root -proot ork < migrations/officer-position-cleanup.sql
  docker exec ork3-php8-db mariadb -u root -proot ork -e "SHOW COLUMNS FROM ork_officer LIKE 'role';"
  ```
  Expected: empty result (column gone). Run a full regression (set/vacate/retire/display/reports) and confirm green.
  > If any code still depends on `o.role`, DO NOT drop — fix the dependency first. Dropping a column is destructive; perform only after the grep + regression confirm safety.
- [ ] `git add migrations/officer-position-cleanup.sql ork.sql && git commit -m "Cleanup: drop ork_officer.role denormalized cache (P7)\n\nCo-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"`

---

## Final: PR

- [ ] After all phases verify green, open a PR titled `Enhancement: Officer Admin Expansion` (PR title convention) against `master`, body summarizing the 7 phases, ending with the Generated-with trailer. Do NOT stage `CLAUDE.md`/`agent-instructions/*`; do NOT stage `class.Authorization.php` if a `true ||` login bypass is present.

---

## Self-review (writing-plans checklist)

**Spec coverage map (each spec section → task):**
- §1 requirements 1-8, 10: 1 (P1.2/P1.3 tables+backfill), 2 (canonical_key P1.2), 3 (DisplayTitle IF-not-COALESCE — P2.4/P2.5/P3.1), 4 (Core Five pinned — P1.3 seed + P4.3 disabled controls + P2.5 server reject), 5 (occupancy — P2.5/P2.8), 6 (retire/reinstate — P5.1), 7 (history snapshot — P2.4 display_label), 8 (RBAC binding — P2.5/P2.2); req 10 award taxonomy independent — no task needed (explicitly out of scope, noted §8).
- §2 blockers (a)-(i): (a)(b) P1.2; (c) P2.3/P7.2; (d) P2.3 SyncOfficerRoleByPositionId + §4.4 reconciliation in P2.5; (e) P3.1; (f) P1.3 step 7; (g) P6.1; (h) P3.6/P3.7/P4.2; (i) explicitly NOT unified (§8 policy).
- §3 data model: P1.2 (tables, ENUM widen, columns), P1.3 (seed, backfill, index swap), P1.5 (create_officers BUG-1). §3.4 occupancy GET_LOCK: P2.5/P2.8. §3.6 alias table: P1.2 + P2.5 GetPositions.
- §4 RBAC: P2.3 (SyncOfficerRoleByPositionId, RoleIsOfficerBound/BUG-2, BUG-4), §4.4 reconciliation P2.5 (EditPosition/RetirePosition).
- §5 service/API: P2.5 (class.OfficerPosition all methods), P2.6 (model), P2.4 (common.php set_officer/record_officer_history/MINOR-2), P2.7 (Kingdom/Park SetOfficer/MAJOR-3), P2.3 (RBACService), P2.1/P2.2 (PermissionRegistry+seed), P4.1 (Ajax controller), P4.6 (principality §5.7).
- §6 alias propagation: P3.1 (backend reads), P3.4 (hero MAJOR-4), P3.5 (sidebar/About), P3.6 (officerList JSON NIT-2), P3.7 (JS), P3.2 (Player MINOR-5). §6.4 history-filter dropdown include_retired=true: covered by GetPositions signature (P2.5) + P5.1/P6 history-filter usage — **note:** explicitly call `GetPositions(..., true)` on any history-filter dropdown when built (flagged in P6.1/P5.1). §6.6 permissions UI: P6.2/P6.3.
- §7 frontend: P4.2 (modals), P4.3/P4.5 (tabs, KnConfig/PkConfig.canManageOfficers), P4.4 (JS handlers IIFE guard), P5.1 (retire/reinstate UI), P4.7 (fallback banner), §7.7 dark-mode walked in each UI task.
- §8 edge cases: retire-while-occupied P5.1; history snapshot P2.4/P3.5; award taxonomy out-of-scope; GMR fix P1.2/P1.3; reports rewrite P6.1; principalities P4.6; park scope P2.5/P4.5; vacant rows P2.5.
- §9 phases P1-P7: all mapped above in order.
- §10 risks: risk1 has_auth_role seed P1.3 (verified against common.php:486); risk2 principalities P4.6; risk3 backfill normalization P1.1/P1.3 step 5a; risk4 CreateRole/EditRole kingdom-scope P2.5 (uses existing signatures).

**Placeholder scan:** No "TBD"/"similar to"/"add error handling" — every SQL, python3, and verification command is literal. Two intentional `<placeholders>` are runtime IDs (`<k>`, `<kingdomId>`, `<park>`, `<pos>`, `<member>`, `<principalityKingdomId>`) and file paths to be confirmed at read-time (`<Kingdom_index.tpl path>`, `<Admin_setofficers.tpl path>`, `<report controller>`); these are values the engineer fills from their live DB / repo, not undefined design decisions.

**Signature consistency:** `GetPositions`, `GetPosition`, `CreatePosition`, `EditPosition`, `RetirePosition`, `ReinstatePosition`, `GetOfficersForDisplay`, `SetOfficerByPosition`, `VacateOfficerByPosition`, `SyncOfficerRoleByPositionId`, `SyncNewOfficerSlot(...,$position_id=0)`, `RoleIsOfficerBound`, `set_officer(...,$position_id=0)`, `record_officer_history(...,$position_id=0)` — all defined once in the Signature Contract and used identically in every referencing task. `ResolvePositionId`/`ResolveCanonicalKey` helper additions flagged in P2.7 and instructed to be appended to `class.OfficerPosition.php`.
