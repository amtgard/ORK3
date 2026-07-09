# DS-02: Authorization INSERT Bypass — Discovery Design Note

**Milestone:** DS-02  
**Branch:** `megiddo/ds-02-auth-insert-discovery`  
**Target IDs:** T-ADM-11, T-KNA-03, T-PRA-02, T-EVA-06 (addauth portion only)  
**Depends on:** M0.1 (test framework), DS-01 (complete)  
**Execution sprint:** R-02
**Test sprint:** T-02

---

## 1. Backend survey

### 1.1 Scope summary

Authorization **grants** (`INSERT` into `ork_authorization`) are the violation. The backend already owns this operation via `AuthorizationService.AddAuthorization` → `class.Authorization::AddAuthorization` → `add_authorization` / `add_auth_h`. Four frontend AJAX handlers bypass the service with raw `$DB->Execute("INSERT INTO … authorization …")`.

**Removals** (`removeauth`) on the same controllers already call `Model_Authorization::del_auth` → `AuthorizationService.RemoveAuthorization`. Only the **add** paths need R-02 work.

A **clean reference** exists: `Controller_Unit::addauth` and `Controller_Admin::addauth` (unit admin) route through `Model_Unit::add_unit_auth` → `APIModel('Authorization')->AddAuthorization`, with danger-audit in the controller after success.

### 1.2 Database schema

Table `ork_authorization` (yapo model in `class.Authorization.php`):

| Column | Notes |
|--------|-------|
| `authorization_id` | PK |
| `mundane_id` | Grantee |
| `park_id` | Scoped park (0 if N/A) |
| `kingdom_id` | Scoped kingdom (0 if N/A) |
| `event_id` | Scoped event (0 if N/A) |
| `unit_id` | Scoped unit (0 if N/A) |
| `role` | `create` \| `edit` \| `admin` |
| `modified` | Timestamp |

Exactly one scope column is non-zero per row (except system admin: all scope columns 0, `role = admin`).

### 1.3 Frontend violations (target IDs)

#### T-ADM-11: `Controller_AdminAjax::global` → `addauth`

| Lines | Behavior |
|-------|----------|
| 55–72 | Gate: `HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN)`. Raw INSERT: `role = 'admin'`, all scope IDs 0. Post-INSERT SELECT for `authId` + `persona`. **No danger-audit** (unlike kingdom/park/event paths). |

Route: `AdminAjax/global/addauth` — used by `Admin_permissions_global.tpl`.

#### T-KNA-03: `Controller_KingdomAjax::kingdom` → `addauth`

| Lines | Behavior |
|-------|----------|
| 615–654 | Gate: `HasAuthority($uid, AUTH_KINGDOM, $kingdom_id, AUTH_CREATE)`. Role whitelist `create` \| `edit` (default `create`). Raw INSERT with `kingdom_id` set. Post-INSERT SELECT + `dangeraudit->audit('Authorization::AddAuthorization', …)`. |

Route: `KingdomAjax/kingdom/{id}/addauth` — used by `Admin_permissions.tpl` (kingdom tab).

#### T-PRA-02: `Controller_ParkAjax::park` → `addauth`

| Lines | Behavior |
|-------|----------|
| 411–450 | Gate: `HasAuthority($uid, AUTH_PARK, $park_id, AUTH_CREATE)`. Same role whitelist. Raw INSERT with `park_id` set. Post-INSERT SELECT + danger-audit. |

Route: `ParkAjax/park/{id}/addauth` — used by `Admin_permissions.tpl` (park tab).

#### T-EVA-06: `Controller_EventAjax::auth` → `addauth` (INSERT portion)

| Lines | Behavior |
|-------|----------|
| 493–528 | Gate: `HasAuthority($uid, AUTH_EVENT, $event_id, AUTH_CREATE)`. Role whitelist. Raw INSERT with `event_id` set. Post-INSERT SELECT + danger-audit. |

**Out of DS-02 / R-02 scope:** `auth` → `playersearch` (lines 434–491) — direct `$DB` player search; deferred to DS-11 (search targets).

Route: `EventAjax/auth/{event_id}/addauth` — event permissions UI.

### 1.4 Backend surface (existing — no new domain logic required)

| Layer | Location | Role |
|-------|----------|------|
| Domain | `system/lib/ork3/class.Authorization.php` | `AddAuthorization`, `add_authorization`, `add_auth_h`, `HasAuthority`, `RemoveAuthorization` |
| Service | `orkservice/Authorization/AuthorizationService.function.php` | Thin wrappers: `AddAuthorization`, `RemoveAuthorization`, `GetAuthorizations` |
| WSDL | `AuthorizationService.definitions.php` | `AddAuthorizationRequest`: Token, MundaneId, Type, Role, Id |
| Frontend model | `orkui/model/model.Authorization.php` | `add_auth`, `del_auth` via `APIModel('Authorization')` |
| Unit path | `orkui/model/model.Unit.php` | `add_unit_auth` → same `AddAuthorization` API |

#### `add_authorization` validation (what raw INSERT bypasses)

1. **Token validation** — `IsAuthorized($request['Token'])` (controllers already gate via `HasAuthority` on session user, but service path is canonical).
2. **Role whitelist** — `create`, `edit`, `admin` only (`InvalidParameter` otherwise).
3. **Grantor authority** — `HasAuthority($requester_id, $request['Type'], $request['Id'], AUTH_CREATE)` (mirrors controller gates).
4. **Unit KPM bypass** — kingdom/park edit can grant unit auth (not relevant to DS-02 targets).
5. **Authorization log** — `$this->log->Write('Authorization', $requester_id, LOG_ADD, $request)` on success.
6. **yapo save** — `add_auth_h` sets scope fields from `Type`/`Id` and returns `Success($authorization_id)` in `Detail`.

#### Request shape for each target

| Target | AddAuthorization request |
|--------|--------------------------|
| T-ADM-11 | `Token`, `MundaneId`, `Type` => `AUTH_ADMIN` (`'admin'`), `Id` => 0, `Role` => `AUTH_ADMIN` |
| T-KNA-03 | `Token`, `MundaneId`, `Type` => `AUTH_KINGDOM`, `Id` => `$kingdom_id`, `Role` => `create` \| `edit` |
| T-PRA-02 | `Token`, `MundaneId`, `Type` => `AUTH_PARK`, `Id` => `$park_id`, `Role` => `create` \| `edit` |
| T-EVA-06 | `Token`, `MundaneId`, `Type` => `AUTH_EVENT`, `Id` => `$event_id`, `Role` => `create` \| `edit` |

Constants: `AUTH_CREATE`/`AUTH_EDIT`/`AUTH_ADMIN` are string values `'create'`/`'edit'`/`'admin'` — compatible with current POST `Role` handling.

### 1.5 Existing test coverage

| Asset | Status |
|-------|--------|
| `AuthorizationService.testrig.php` | Comprehensive add/remove matrix (admin, kingdom, park, event, unit); **mutates DB** — deprecated per M0.1; not in PHPUnit suite |
| PHPUnit `tests/` | **No** `AuthorizationTest` integration tests yet |
| `Model_Authorization` | Thin wrapper only; no dedicated tests |

R-02 should port critical testrig scenarios into `tests/Integration/AuthorizationAddTest.php` (non-destructive or transactional).

### 1.6 Call graph (frontend addauth)

```
Admin_permissions_global.tpl ──► AdminAjax/global/addauth          (T-ADM-11)
Admin_permissions.tpl        ──► KingdomAjax/kingdom/{id}/addauth  (T-KNA-03)
Admin_permissions.tpl        ──► ParkAjax/park/{id}/addauth        (T-PRA-02)
Event permissions UI         ──► EventAjax/auth/{id}/addauth       (T-EVA-06)

Controller_Unit::addauth     ──► Model_Unit::add_unit_auth ──► Authorization API  ✓ (reference)
Controller_Admin::addauth    ──► Model_Unit::add_unit_auth ──► Authorization API  ✓ (reference)
```

### 1.7 Behavioral gaps (raw INSERT vs service path)

| Topic | Raw INSERT today | Service path |
|-------|------------------|--------------|
| Duplicate grants | Always INSERTs new row | Same — `add_auth_h` always INSERTs (no upsert) |
| Auth log table | Skipped | `$this->log->Write` on add |
| Danger audit | Kingdom/Park/Event only | Stays in controller (Unit pattern) — R-02 preserves |
| Admin global grant audit | **Missing** | R-02 should add danger-audit to match scoped paths |
| Response JSON | `{status, authId, persona, mundaneId?}` | API returns `{Status, Detail: authId}` — persona needs separate lookup |
| Error mapping | Custom `status` 5 / 1 | SOAP `Status` codes — map in controller |

### 1.8 Gaps

- No new service methods needed; `AddAuthorization` already covers all four scopes.
- No PHPUnit integration tests for `add_authorization` / `AddAuthorization`.
- `T-ADM-11` lacks danger-audit parity with other scopes.
- Persona fetch after grant uses direct `$DB` SELECT in all four controllers — **read-only**, out of FR-4 INSERT scope; optional cleanup in R-02 via `Ork3::$Lib->player->player_info` or defer to player-search refactor.

---

## 2. Test design

### 2.1 Backend unit/integration tests (implement in T-02)

Add `tests/Integration/AuthorizationAddTest.php` (DB required):

| Test case | Validates |
|-----------|-----------|
| `testAddKingdomCreateAuth` | Kingdom-scoped `create` grant; `Detail` is valid auth ID |
| `testAddKingdomEditAuth` | Kingdom-scoped `edit` grant |
| `testAddParkCreateAuth` | Park-scoped grant |
| `testAddEventCreateAuth` | Event-scoped grant |
| `testAddGlobalAdminAuth` | `Type`/`Role` admin, all scope IDs 0 |
| `testAddAuthRejectsInvalidRole` | Role outside whitelist → `InvalidParameter` |
| `testAddAuthRejectsUnauthorizedGrantor` | Token for user without CREATE on scope → `NoAuthorization` |
| `testAddAuthRejectsBadToken` | Invalid token → `BadToken` |
| `testAddAuthWritesAuthorizationLog` | Optional: verify log row if test DB supports |

Use seeded admin/kingdom/park/event IDs from dev DB; skip when `ork3_test_db_available()` is false.

Port highest-value cases from `AuthorizationService.testrig.php` matrices (admin granting kingdom, kingdom officer granting park, etc.) without the testrig's destructive `die()` pattern.

### 2.2 Service-layer tests

Same integration tests can invoke `AddAuthorization()` from `AuthorizationService.function.php` after bootstrap to verify SOAP wrapper parity.

### 2.3 Infection scope (T-02, DS-7)

| Source filter | PHPUnit filter |
|---------------|----------------|
| `--filter=class.Authorization.php` | `--test-framework-options="--filter=AuthorizationAddTest"` |

Include `AuthorizationService.function.php` in scope once tests exercise the wrapper.

```bash
sh bin/run-infection.sh \
  --filter=class.Authorization.php \
  --test-framework-options="--filter=AuthorizationAddTest"
```

Target ≥ `minMsi` / `minCoveredMsi` (15) from `infection.json5`.

### 2.4 Frontend functional tests (implement in T-02)

Playwright/Cypress against `http://localhost:19080/orkui/` per [06-test-framework.md](./06-test-framework.md):

| Flow | Steps | Assert |
|------|-------|--------|
| Global admin grant | Login as ORK admin → Global Permissions → add player | Row appears with admin role; `authId` returned |
| Global admin revoke | Remove granted row | Row removed (existing `removeauth` path) |
| Kingdom permissions | Kingdom admin → Permissions → add create/edit | New auth row; danger-audit entry on player |
| Park permissions | Park admin → Permissions → add create/edit | Same |
| Event permissions | Event admin → Permissions → add create/edit | Same |
| Unauthorized grant | Non-admin attempts kingdom addauth | JSON error; no new row |

Auth: dev admin + scoped test officers documented in T-02 commit.

---

## 3. Proposed revision

### 3.1 Principle

**No new APIs.** Replace raw INSERT blocks with `Model_Authorization::add_auth` (or inline `load_model('Authorization')`) using the same request shapes as `Controller_Unit`. Keep controllers as thin JSON adapters; delete all `INSERT INTO … authorization` from `orkui/`.

### 3.2 Per-target replacement (R-02)

| ID | File | Change |
|----|------|--------|
| T-ADM-11 | `Controller_AdminAjax::global` | Replace INSERT + SELECT with `add_auth([Token, MundaneId, Type=>AUTH_ADMIN, Id=>0, Role=>AUTH_ADMIN])`; map `Detail` → `authId`; fetch persona via `player_info` or minimal read; **add danger-audit** to match scoped paths |
| T-KNA-03 | `Controller_KingdomAjax::kingdom` | Replace INSERT + SELECT with `add_auth([Token, MundaneId, Type=>AUTH_KINGDOM, Id=>$kingdom_id, Role=>$role])`; keep danger-audit; delete `$DB` write |
| T-PRA-02 | `Controller_ParkAjax::park` | Same pattern with `AUTH_PARK` / `$park_id` |
| T-EVA-06 | `Controller_EventAjax::auth` | Same pattern with `AUTH_EVENT` / `$event_id` (addauth branch only) |

Shared helper pattern (optional, R-02 decision):

```php
$this->load_model('Authorization');
$r = $this->Authorization->add_auth([
    'Token'     => $this->session->token,
    'MundaneId' => $mid,
    'Type'      => AUTH_KINGDOM,  // scope-specific
    'Id'        => $kingdom_id,
    'Role'      => $role,
]);
if ($r['Status'] != 0) {
    echo json_encode(['status' => $r['Status'], 'error' => …]); exit;
}
$authId = (int)$r['Detail'];
// danger-audit + persona lookup + success JSON
```

### 3.3 Out of scope for R-02

| Item | Deferred to |
|------|-------------|
| `removeauth` paths | Already on service API — no change |
| `HasAuthority` calls in controllers | DS-14 (`Ork3::$Lib` migration) |
| Persona SELECT after grant | Optional cleanup; not an INSERT violation |
| `EventAjax::auth` playersearch SQL | DS-11 |
| Duplicate-grant policy (upsert vs multi-row) | Product decision; preserve current INSERT-always semantics |

### 3.4 Migration order (R-02)

1. Add `AuthorizationAddTest` integration tests against existing `AddAuthorization` (green before controller changes).
2. Replace INSERT in `AdminAjax`, `KingdomAjax`, `ParkAjax`, `EventAjax` one file at a time; full suite after each.
3. Add danger-audit to `AdminAjax` global addauth.
4. Run milestone-scoped Infection.
5. Add frontend functional tests for permissions flows.
6. Audit: `rg 'INSERT INTO.*authorization' orkui/` → zero matches.

### 3.5 Open decisions for R-02

1. **Persona after grant** — keep thin `$DB` SELECT vs `player_info` vs return persona from extended API response?
2. **Admin danger-audit** — confirm audit payload matches kingdom/park/event shape for global admin grants.
3. **Error status mapping** — preserve frontend `status: 5` for auth failures vs literal SOAP status codes?

---

## Related documents

| Doc | Purpose |
|-----|---------|
| [03-implementation-plan.md](./03-implementation-plan.md) | Target ID inventory |
| [04-milestone-checklist.md](./04-milestone-checklist.md) | DS-02 tracking |
| [06-test-framework.md](./06-test-framework.md) | Test and Infection commands |
| [ds-01-rsvp-discovery.md](./ds-01-rsvp-discovery.md) | Prior discovery sprint format reference |
| [validations/v-02-auth-validation.md](./validations/v-02-auth-validation.md) | Phase 1.6 — canary URLs + test mutation boundaries (V-02) |
