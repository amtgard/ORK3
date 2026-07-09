# Megiddo Refactor — Test Framework (M0.1)

Unified backend unit testing and Infection mutation testing for `system/lib/ork3/` and `orkservice/*`. This document is the sign-off reference for DS-4, DS-5, and DS-7.

---

## Prerequisites

| Requirement | Notes |
|-------------|-------|
| **PHP 8.2+** | Host CLI (DS-2). Docker app container is PHP 8.1 — run tests on the host. |
| **Composer dev deps** | `composer install` from repo root |
| **MariaDB (dev)** | `docker compose -f docker-compose.php8.yml up` — mirror DB on `localhost:19306` (`ork`); **sandbox** on `localhost:19307` (`ork_test`) |
| **Test sandbox** | `bin/ork-db deploy-sandbox` — canonical fake data for PHPUnit and fuzzy-validator **`test`** profile ([test-database-tool](../test-database-tool/README.md)) |
| **Coverage driver** | **pcov** (preferred), phpdbg, or Xdebug for Infection |
| **E2E login credentials** | Required before T-* / R-* milestones with auth-gated Playwright or fuzzy-validator flows — see [E2E login credentials (preflight)](#e2e-login-credentials-preflight) below |

Inside the app container, set `ORK3_TEST_DB_HOST=ork3-php8-db` and `ORK3_TEST_DB_PORT=3306`.

---

## E2E login credentials (preflight)

Playwright specs (`tests/e2e/`) and **`bin/fuzzy-validator`** auth-gated pages perform a **real login** through the standard `Login` route. They do **not** use a code-level password bypass.

**Do not** rely on the local-only `class.Authorization.php` login bypass (`true ||` hack). That file is never committed, is invalid for milestone sign-off, and must not be substituted for configured test credentials.

**Do not** sign off RB-*, T-*, R-*, or V-* milestones with auth-gated Playwright by running smoke-only specs. Export the documented credentials below and confirm authenticated specs **run** (not skip).

Before any **T-*** or **R-*** milestone that runs authenticated frontend tests (or fuzzy-validator gates on login pages), configure credentials once per shell session.

### 1. Canonical accounts (local docker only)

These passwords are **intentionally weak** and apply only to **local MariaDB** in `docker-compose.php8.yml` (`localhost:19306` mirror, `localhost:19307` sandbox). **Never** set these on remote production hosts.

| Profile | `bin/ork-db` | DB | Username | Password | Used by |
|---------|--------------|-----|----------|----------|---------|
| **Sandbox / test** | `use dev` | `ork_test` @ 19307 | `megiddo` | `test-db-player` | Fuzzy **test** profile; sandbox Playwright when app points at dev DB |
| **Mirror / prod-local** | `use prod` | `ork` @ 19306 | `admin` | `password` | Fuzzy **mirror** profile; default Playwright auth flows |

Defaults are also in `tools/fuzzy-validator/manifests/profiles.json5`. Fuzzy-validator reads those defaults on `record`/`validate`. **Playwright always requires explicit env vars** (next section).

Refresh sandbox before strict gates: `bin/ork-db deploy-sandbox`.

### 2. Applying passwords on local databases

After a **mirror import/refresh** or first sandbox setup, ensure the accounts above can log in through the normal Login route:

| Account | Mirror (`ork` @ 19306) | Sandbox (`ork_test` @ 19307) |
|---------|------------------------|--------------------------------|
| **`admin` / `password`** | Set `admin` password to `password` in local mirror (UI password change or direct update to `ork_credential` / `ork_mundane` password fields). Required for mirror fuzzy + admin Playwright specs. | Same overwrite on sandbox if admin specs run against dev DB. |
| **`megiddo` / `test-db-player`** | Optional on mirror (not the default Playwright user). | Fake players get `test-db-player` from `bin/ork-db apply`. Real extracted `megiddo` may still have mirror credentials — set to `test-db-player` if login fails after `deploy-sandbox`. |

Verify manually once per DB refresh:

```bash
export ORK3_E2E_BASE_URL=http://127.0.0.1:19080/orkui/
bin/ork-db use prod   # or use dev for sandbox
export ORK3_E2E_USERNAME=admin ORK3_E2E_PASSWORD=password
npx playwright test tests/e2e/infrastructure.spec.ts -g "home route loads after login"
```

### 3. Stack + export env vars

```bash
docker compose -f docker-compose.php8.yml up -d
export ORK3_E2E_BASE_URL=http://127.0.0.1:19080/orkui/
```

**Playwright (required every session):**

```bash
# Mirror / prod-local (default for most tests/e2e/*.spec.ts)
bin/ork-db use prod
export ORK3_E2E_USERNAME=admin ORK3_E2E_PASSWORD=password

# Sandbox-only Playwright (when explicitly testing against ork_test)
bin/ork-db use dev
export ORK3_E2E_USERNAME=megiddo ORK3_E2E_PASSWORD=test-db-player
```

**Fuzzy-validator** uses `profiles.json5` defaults when env overrides are unset:

| Profile | Login | Password env | Default |
|---------|-------|----------------|---------|
| **`test`** | `megiddo` | `ORK3_E2E_TEST_PASSWORD` | `test-db-player` |
| **`mirror`** | `admin` | `ORK3_E2E_USERNAME` / `ORK3_E2E_PASSWORD` | `admin` / `password` |

Optional explicit override (same values): `export ORK3_E2E_USERNAME=admin ORK3_E2E_PASSWORD=password ORK3_E2E_TEST_PASSWORD=test-db-player`

See [11-dual-database-profiles.md](../fuzzy-validator/11-dual-database-profiles.md).

### 4. Verify login works (sign-off gate)

```bash
# Smoke — no auth required
npx playwright test tests/e2e/infrastructure.spec.ts -g "health route"

# Auth — must not skip
npx playwright test tests/e2e/infrastructure.spec.ts -g "home route loads after login"

# Full auth-gated suite (RB-2 / R-* sign-off)
npx playwright test tests/e2e/
```

If authenticated specs report **skipped** (`Set ORK3_E2E_USERNAME and ORK3_E2E_PASSWORD`), preflight is incomplete — do not sign off frontend functional tests.

**Never** commit `.ork3-db.local` or local `class.Authorization.php` overrides.

---

## Conventions

### Location and naming

| Kind | Path pattern | Example |
|------|--------------|---------|
| Shared bootstrap | `tests/bootstrap.php` | Loads `startup.php` with `ENVIRONMENT=TEST` |
| Unit tests | `tests/Unit/*Test.php` | Pure logic or static domain helpers |
| Integration tests | `tests/Integration/*Test.php` | Requires DB; skips when unavailable |
| PHPUnit config | `phpunit.xml.dist` | Full suite entry (copy to `phpunit.xml` locally if needed) |
| Mutation config | `infection.json5` | Scoped to `system/lib/ork3/` and `orkservice/` |

Test classes use `declare(strict_types=1);` and extend `PHPUnit\Framework\TestCase`.

### Bootstrap

All PHPUnit tests load `tests/bootstrap.php`, which:

1. Sets `$_SERVER['HTTP_HOST'] = 'localhost'`
2. Sets `ENVIRONMENT=TEST` → `config.test.php` (DB host `127.0.0.1`, port **`19307`**, database **`ork_test`** when TD-7 routing is active)
3. Runs `startup.php` (full ORK3 runtime, same as `$DONOTWEBSERVICE` service includes)

Service tests that previously used:

```php
$DONOTWEBSERVICE = true;
include_once('SomeService.php');
```

should instead rely on the bootstrap (domain classes are already loaded) or require the service entry file after bootstrap when testing SOAP wrappers.

### DB fixture strategy

| Tier | When | Approach |
|------|------|----------|
| **Unit** | No DB needed | Test static methods and `common.php` helpers |
| **Integration** | DB required | Prefer **`ork_test` sandbox** after `bin/ork-db deploy-sandbox`; stable kingdoms/parks/players |
| **Future execution sprints** | Refactor targets | Focused fixtures where sandbox rows are insufficient |

Integration tests call `ork3_test_db_available()` in `setUp()` and `markTestSkipped()` when the database is down — so the full suite stays green in CI-less environments, but sign-off on a milestone machine requires the docker DB running.

**Do not** run legacy `*Service.test.php` scripts or `AuthorizationService.testrig.php` against shared dev DBs — they mutate data. Those files are deprecated and guarded with `die()` where applicable.

---

## Commands

### Full unit test suite (sign-off — DS-4, DS-5)

```bash
composer install
sh bin/run-unit-tests.sh
```

Equivalent:

```bash
ENVIRONMENT=TEST php vendor/bin/phpunit -c phpunit.xml.dist
```

**Never** use `--filter`, a single testsuite, or path-scoped PHPUnit flags for milestone sign-off.

Partial runs are allowed during development only:

```bash
php vendor/bin/phpunit -c phpunit.xml.dist --filter CommonFunctionsTest
```

### Infection — full configured scope

```bash
sh bin/run-infection.sh
```

Requires a coverage driver. With pcov installed, the script disables Xdebug automatically.

### Infection — milestone-scoped (DS-7)

Infection’s `--filter` option limits **source files to mutate**, not PHPUnit tests. Use both:

| Flag | Purpose |
|------|---------|
| `--filter=class.Calendar.php` | Limit mutated source (path substring match) |
| `--test-framework-options="--filter=CalendarServiceTest"` | Limit PHPUnit to relevant tests |

Examples:

```bash
# M0.1 pilot: Calendar domain (legacy CalendarService.test.php target)
sh bin/run-infection.sh \
  --filter=class.Calendar.php \
  --test-framework-options="--filter=CalendarServiceTest"

# Park static helper unit tests
sh bin/run-infection.sh \
  --filter=class.Park.php \
  --test-framework-options="--filter=ParkCalculateNextParkDayTest"
```

Document the `--filter` source path and PHPUnit filter used in each T-* and R-* milestone commit.

### Thresholds (`infection.json5`)

| Setting | M0.1 value | Intent |
|---------|------------|--------|
| `minMsi` | 15 | Conservative starting floor (~21% on current covered code) |
| `minCoveredMsi` | 15 | Covered-code floor |

Raise thresholds as coverage grows during T-* and R-* sprints.

### Database migrations

Integration tests and Infection assume a schema current with `db-migrations/`. After pulling new migrations:

```bash
for f in $(ls db-migrations/* | sort); do
  docker exec -i ork3-php8-db mariadb -uork -psecret ork < "$f"
done
```

Missing tables produce PDO warnings that can break Infection’s initial coverage subprocess.

---

## Legacy script inventory

| File | Service | PHPUnit replacement | Status |
|------|---------|---------------------|--------|
| `orkservice/Player/PlayerService.test.php` | Player | — (TBD in T-09) | Deprecated, `die()` |
| `orkservice/Kingdom/KingdomService.test.php` | Kingdom | — (TBD in T-06) | Deprecated, `die()` |
| `orkservice/Park/ParkService.test.php` | Park | `ParkCalculateNextParkDayTest` (partial) | Deprecated, `die()` |
| `orkservice/Report/ReportService.test.php` | Report | — (TBD in T-10) | Deprecated manual script |
| `orkservice/Calendar/CalendarService.test.php` | Calendar | `CalendarServiceTest` | Deprecated, `die()` |
| `orkservice/Event/EventService.test.php` | Event | — (TBD in T-04/T-05) | Deprecated, `die()` |
| `orkservice/Authorization/AuthorizationService.testrig.php` | Authorization | `AuthorizationAddTest` (T-02) | Deprecated test rig |

---

## Frontend functional tests (documented approach — out of M0.1 scope)

ORK3 has no automated frontend test runner today. Recommended approach for refactor execution sprints:

| Aspect | Plan |
|--------|------|
| **Tooling** | Playwright or Cypress against `http://localhost:19080/orkui/` (docker app) |
| **Scope** | One happy-path flow per touched controller (auth-gated pages use configured login — see [E2E login credentials preflight](./06-test-framework.md#e2e-login-credentials-preflight)) |
| **When** | Implemented during T-* test sprints (Phase 1.5) per DS test design |
| **Sign-off** | DS-7 Infection gate on T-*; R-* re-runs Infection on refactored code |

Discovery sprints document required frontend flows in their test design step.

---

## Fuzzy render stability gate (R-* sign-off)

Required at **R-* sign-off** after matching **V-*** milestone. Complements Playwright e2e behavior tests with **pixel + DOM + asset** stability on **sandbox and mirror** databases. Tool: **`bin/fuzzy-validator`** ([fuzzy-validator docs](../fuzzy-validator/README.md)).

| Aspect | Plan |
|--------|------|
| **When** | Every R-* sign-off after V-00 + matching V-{nn} baselines exist |
| **Databases** | **`test`** (sandbox, strict) and **`mirror`** (local `ork`, lenient) — both required |
| **Setup** | `bin/ork-db deploy-sandbox`; credentials per [E2E login preflight](#e2e-login-credentials-preflight) |
| **Page ids** | From [validations/v-{nn}-*.md](./validations/) §1 — not ad-hoc |
| **Strictness** | `test`: all scores **1.0**; `mirror`: visual **≥ 0.98**, DOM **≥ 0.99**, assets **1.0** |
| **Command** | `bin/fuzzy-validator validate --pages <ids> --phase all` |
| **Output** | Exit code + HTML report under `tools/fuzzy-validator/reports/run-{id}/` |

Record baselines in **V-00** (global setpoint) and **V-{nn}** (domain canaries): `bin/fuzzy-validator record --pages …`. See [08-phase-16-validation-artifacts.md](./08-phase-16-validation-artifacts.md) and [11-dual-database-profiles.md](../fuzzy-validator/11-dual-database-profiles.md).

---

## Local workflow before commit (DS-5)

1. Start docker stack: `docker compose -f docker-compose.php8.yml up -d`
2. Refresh sandbox when integration tests need stable data: `bin/ork-db deploy-sandbox`
3. **T-* / R-* with auth-gated Playwright or fuzzy-validator:** complete [E2E login credentials preflight](./06-test-framework.md#e2e-login-credentials-preflight) — export `ORK3_E2E_*` for the active DB profile; do not use `class.Authorization.php` bypass
4. Apply any pending SQL in `db-migrations/` if tests fail on missing schema
5. `sh bin/run-unit-tests.sh` — all tests green
6. For milestones with mutation gate: `sh bin/run-infection.sh --filter=…`
7. Commit on the milestone branch only

Optional: add `sh bin/run-unit-tests.sh` to personal pre-push hook (not enforced repo-wide in M0.1).

---

## Adding a new backend test

1. Create `tests/Unit/YourFeatureTest.php` or `tests/Integration/YourServiceTest.php`
2. Extend `TestCase` — bootstrap is automatic via `phpunit.xml.dist`
3. For DB tests, skip when `!ork3_test_db_available()`
4. Run full suite before commit
5. Add Infection filter for the milestone scope when touching production code

---

## Related documents

| Doc | Purpose |
|-----|---------|
| [04-milestone-checklist.md](./04-milestone-checklist.md) | M0.1 checklist |
| [05-development-steering.md](./05-development-steering.md) | DS-4–DS-7 gates |
| [../test-database-tool/README.md](../test-database-tool/README.md) | Sandbox DB (`bin/ork-db`) |
| [08-phase-16-validation-artifacts.md](./08-phase-16-validation-artifacts.md) | Phase 1.6 V-* validation artifacts |
| [validations/README.md](./validations/README.md) | Canary URLs + test mutation boundaries |
| [../fuzzy-validator/README.md](../fuzzy-validator/README.md) | Render gate (`bin/fuzzy-validator`) |
