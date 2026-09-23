# DS-18: Residual `$DB` in `orkui/` — Discovery Design Note

**Milestone:** DS-18  
**Branch:** `megiddo/ds-18-residual-db-discovery` (retroactive — backfill 2026-07-10)  
**Target IDs:** All remaining `$DB->` in `orkui/` controllers, models, templates, `index.php`  
**Depends on:** R-01 … R-17 (domain APIs for Player, Event, Weather, Park, Admin, …)  
**Execution sprint:** R-18  
**Test sprint:** T-18

---

## 1. Backend survey

### 1.1 Scope summary

Domain migrations R-01 … R-17 thinned most `$DB` from `orkui/`, but **9 files** still held direct database access at the carryover audit (`76758e2c`):

| Metric | Pre-R-18 | Post-R-18 |
|--------|----------|-----------|
| Files with `$DB->` in `orkui/` | 9 | **0** |
| Templates with inline SQL | 2+ | **0** on migrated paths |

**Exit criterion:** `rg '\$DB->' orkui/` → **zero**.

**Source:** [10-phase-2-continuation.md](./10-phase-2-continuation.md) carryover audit; [03-implementation-plan.md](./03-implementation-plan.md) residual rows.

### 1.2 Residual call sites (inventory)

| File / area | Patterns | Domain API |
|-------------|----------|------------|
| `Controller_Admin` | Audit log, admin dashboard queries | `Administration`, `Dangeraudit` |
| `Controller_Player` / `PlayerAjax` | Player detail, prefs | `Player` |
| `*Ajax` controllers | Scoped lookups | Respective domain services |
| `model.Player.php` | Residual SELECT paths | `Player` JSON |
| `Admin_auditlog.tpl` | Inline query | Controller precomputes rows |
| `default.theme` | Nav/session reads | `nav_view_helpers.php` |
| `Eventnew_index.tpl` | Event occurrence data | `Event` domain DTO |
| `index.php` | *(R-13 migrated health/redirect; R-18 confirms zero)* | `Health`, `Event` |

### 1.3 New view helpers

| Helper | Role |
|--------|------|
| `nav_view_helpers.php` | Nav chrome without template `$DB` |
| `wx_coords_for_calendar_detail` | Calendar weather coords (pairs with R-17 `wx_*`) |

### 1.4 Domain surfaces used

| Domain class | R-18 usage |
|--------------|------------|
| `Player` | Player detail, mundane lookups |
| `Dangeraudit` | Audit log reads |
| `Weather` | Archive/coords for templates |
| `ParkProfile` | Park-scoped reads |
| `Event` | Event occurrence page data |
| `Administration` | Admin dashboard aggregates |

### 1.5 Cross-milestone overlaps

| Pattern | Prior sprint | Notes |
|---------|--------------|-------|
| T-INF-* `$DB` | R-13 | Health/redirect already domain; R-18 confirms |
| Menu `HasAuthority` | R-14/R-15 | No `$DB` in auth paths |
| Template SQL | DS-13 `default.theme` | Completed in R-18 |

**Post-R-18:** Phase 2 complete — Phase 3 audit next. Residual `Ork3::$Lib` (41 sites) → R-19a…d remediation.

---

## 2. Test design

### 2.1 Backend unit/integration tests (T-18)

R-18 reuses full suite — no new PHPUnit files:

| Test file | Type | Covers |
|-----------|------|--------|
| Full `tests/` suite | Integration + Unit | Regression on all prior R-* domains |
| `tests/Integration/PlayerProfileTest.php` | Integration | Player paths touched |
| `tests/Integration/AuthorizationLibTest.php` | Integration | Admin/auth regression |

**Regression command (R-18 sign-off):**

```bash
sh bin/run-unit-tests.sh
# 215 passed, 2 skipped
```

### 2.2 Infection scope (T-18, DS-7)

Spot-check on primary touched domain classes (not full V-14 passes):

```bash
sh bin/run-infection.sh \
  --only-covered \
  --filter=class.Player.php \
  --test-framework-options="--filter=PlayerProfileTest|ModelPlayerCacheTest"
# Player MSI 20%

sh bin/run-infection.sh \
  --only-covered \
  --filter=class.DangerAudit.php \
  --test-framework-options="--filter=DangerAudit"
# DangerAudit MSI 50%
```

### 2.3 Frontend functional tests (T-18)

Full regression via V-00 active pages + touched domain specs:

| Test file | Flow | Assert |
|-----------|------|--------|
| `tests/e2e/auth-permissions.spec.ts` | Admin permissions | 3/3 |
| `tests/e2e/player-profile.spec.ts` | Player profile | 2/2 |
| `tests/e2e/event-detail.spec.ts` | Event detail | 3/3 |
| Auth smoke | Login preflight | — |

### 2.4 Static exit criterion

```bash
rg '\$DB->' orkui/
# → zero matches post-R-18
```

---

## 3. Proposed revision

### 3.1 Principle

**Zero `$DB->` in `orkui/`** — all data access via `JSONModel` / `APIModel` / domain helpers. Templates receive precomputed arrays only.

### 3.2 Migration phases (executed in R-18)

| Phase | Work |
|-------|------|
| **1** | Inventory remaining `$DB` with `rg` |
| **2** | Add domain methods or extend existing services |
| **3** | Thin controllers/models; extract `nav_view_helpers.php` |
| **4** | Remove template inline SQL |
| **5** | Full V-00 fuzzy regression sweep |

### 3.3 Non-goals

- Eliminating `Ork3::$Lib` (Phase 3 remediation R-19a…d).
- Changing domain SQL semantics.
- `orkservice/` or `system/lib/` refactors beyond thin wrappers.

---

## 4. Exit criteria checklist

- [x] Backend survey complete (§1)
- [x] Test design documented (§2)
- [x] Domain API mapping documented (§3)
- [x] View helper strategy recorded
- [x] Static grep exit criterion defined

---

## Related documents

| Doc | Link |
|-----|------|
| Implementation plan | [03-implementation-plan.md](./03-implementation-plan.md) |
| V-00 setpoint | [validations/v-00-fuzzy-setpoint.md](./validations/v-00-fuzzy-setpoint.md) |
| Validation | [validations/v-18-residual-db-validation.md](./validations/v-18-residual-db-validation.md) |
| Phase 3 close-out | [11-phase-3-closeout.md](./11-phase-3-closeout.md) |
| Gap audit | [p3-backfill-tvds-audit.md](./p3-backfill-tvds-audit.md) |
