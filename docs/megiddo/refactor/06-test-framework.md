# Megiddo Refactor — Test Framework (M0.1)

Unified backend unit testing and Infection mutation testing for `system/lib/ork3/` and `orkservice/*`. This document is the sign-off reference for DS-4, DS-5, and DS-7.

---

## Prerequisites

| Requirement | Notes |
|-------------|-------|
| **PHP 8.2+** | Host CLI (DS-2). Docker app container is PHP 8.1 — run tests on the host. |
| **Composer dev deps** | `composer install` from repo root |
| **MariaDB (dev)** | `docker compose -f docker-compose.php8.yml up` — DB on `localhost:19306` |
| **Coverage driver** | **pcov** (preferred), phpdbg, or Xdebug for Infection |

Inside the app container, set `ORK3_TEST_DB_HOST=ork3-php8-db` and `ORK3_TEST_DB_PORT=3306`.

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
2. Sets `ENVIRONMENT=TEST` → `config.test.php` (DB host `127.0.0.1`, port `19306` by default)
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
| **Integration** | DB required | Use existing dev seed data; no isolated fixtures yet |
| **Future execution sprints** | Refactor targets | Add focused fixtures or transactions per DS test design |

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
| `orkservice/Authorization/AuthorizationService.testrig.php` | Authorization | — (TBD in T-02) | Deprecated test rig |

---

## Frontend functional tests (documented approach — out of M0.1 scope)

ORK3 has no automated frontend test runner today. Recommended approach for refactor execution sprints:

| Aspect | Plan |
|--------|------|
| **Tooling** | Playwright or Cypress against `http://localhost:19080/orkui/` (docker app) |
| **Scope** | One happy-path flow per touched controller (auth-gated pages use dev admin login) |
| **When** | Implemented during T-* test sprints (Phase 1.5) per DS test design |
| **Sign-off** | DS-7 Infection gate on T-*; R-* re-runs Infection on refactored code |

Discovery sprints document required frontend flows in their test design step.

---

## Local workflow before commit (DS-5)

1. Start docker stack: `docker compose -f docker-compose.php8.yml up -d`
2. Apply any pending SQL in `db-migrations/` if tests fail on missing schema
3. `sh bin/run-unit-tests.sh` — all tests green
4. For milestones with mutation gate: `sh bin/run-infection.sh --filter=…`
5. Commit on the milestone branch only

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
