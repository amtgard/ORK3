# Test Database Tool — Implementation Plan

Phased delivery for the test database infrastructure, PHP renderer, safety system, and Megiddo test framework integration.

---

## 1. Milestones

| ID | Title | Deliverable |
|----|-------|-------------|
| **TD-0** | Design sign-off | This doc set complete |
| **TD-1** | Docker sandbox container | `ork3testdb` service, volume, port 19307 |
| **TD-2** | Safety + canary foundation | Prod/test canaries, `Validate.php`, tier guard, port lock |
| **TD-3** | Extract command | `bin/ork-db extract` (mirror, hardcoded) |
| **TD-4** | Template renderer | `bin/ork-db render` |
| **TD-5** | Apply + wipe/replay | `bin/ork-db apply` (sandbox, hardcoded) |
| **TD-6** | DB profile switcher | `bin/ork-db use prod\|dev` |
| **TD-7** | PHPUnit + dev bootstrap | `config.test.php` → `19307`/`ork_test`, `bin/ork-db bootstrap`, doc updates |
| **TD-8** | Migration classifier + drift detection | `migration-classification.json5` + `drift-check` + `schema-diff` |
| **TD-9** | Renderer tests | PHPUnit golden files + integration smoke |

---

## 2. Repo layout (final)

```
docker-compose.php8.yml          # + ork3testdb service
.gitignore                       # + .ork3-db.local, tools/ork-db/extracted/, rendered/

config.dev.php                   # + ORK3_DB_PROFILE branch (prod|dev)
config.test.php                  # port 19307, database ork_test

db-migrations/
  2026-07-07-add-prod-canary.sql # dev-only prod canary

tools/ork-db/
  cli.php
  Render.php
  Validate.php
  Extract.php
  lib/
  templates/
    stable/kingdoms.json5
  manifests/
    wiring.json5
    migration-classification.json5
    fingerprints.json5
    extract-sources.json5

bin/ork-db                       # exec php tools/ork-db/cli.php

docs/megiddo/test-database-tool/
docs/megiddo/refactor/06-test-framework.md  # update prerequisites
```

---

## 3. Implementation language

**PHP 8.2+** on host CLI (`tools/ork-db/`). Rationale:

- Maintainer preference
- Same runtime as ORK3 domain code — can reuse `DB_PREFIX`, date helpers later
- PHPUnit for tool unit tests (no second test stack)

`bin/ork-db` is a one-line bash wrapper to `php tools/ork-db/cli.php`.

---

## 4. Phase breakdown

### Phase 0 — TD-0 (design)

- [x] Architecture, data model, templates, safety, operations docs
- [x] CLI name `ork-db`, profiles `prod` / `dev`
- [x] Kingdom monikers + Grand Duchy of Litavia principality
- [x] Ken Walker (`43232`) / Avery Krouse (`46193`) in `extract-sources.json5`
- [x] Type 1 vs Type 2 content source classes documented
- [x] Content seed semantics documented
- [ ] Review — port 19307, kingdom IDs 9001–9005 confirmed

### Phase 1 — TD-1 + TD-2

**TD-1 deliverable (done):** `docker-compose.php8.yml` extended with `ork3testdb` — port `19307`, database `ork_test`, isolated volume `data-test-db` (separate from mirror volume `data-db`).

**Acceptance:**

```bash
docker compose -f docker-compose.php8.yml up -d ork3testdb
bin/ork-db validate --mode init
bin/ork-db validate          # tier + wiring checks
```

### Phase 2 — TD-3 + TD-4

**Acceptance:**

```bash
bin/ork-db extract
bin/ork-db render --deterministic
# Golden file test passes in TD-9
```

### Phase 3 — TD-5

**Acceptance:**

```bash
bin/ork-db apply --yes
bin/ork-db status
# 5 kingdoms incl. Grand Duchy of Litavia; admin login on 19307
```

### Phase 4 — TD-6 + TD-7

**TD-7 deliverables:**

- `config.test.php` defaults → `127.0.0.1:19307` / `ork_test` (today still points at `19306` / `ork`)
- `bin/ork-db bootstrap` — first-run orchestrator (see §8)
- Doc updates for onboarding

**Acceptance:**

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db bootstrap --yes    # planned — init + extract + apply
bin/ork-db use dev
bin/ork-db use prod
ENVIRONMENT=TEST sh bin/run-unit-tests.sh
```

### Phase 5 — TD-8 + TD-9

- Full migration classifier
- `bin/ork-db drift-check --strict` in CI (every build)
- `bin/ork-db schema-diff` (post-apply sandbox parity)
- PHPUnit golden + apply round-trip

---

## 5. Config changes summary

### `config.dev.php`

```php
$ork3DbProfile = getenv('ORK3_DB_PROFILE') ?: 'prod';
if ($ork3DbProfile === 'dev') {
    define('DB_HOSTNAME', 'ork3-php8-test-db');
    define('DB_DATABASE', 'ork_test');
} else {
    define('DB_HOSTNAME', 'ork3-php8-db');
    define('DB_DATABASE', 'ork');
}
```

### `config.test.php`

```php
define('DB_PORT', (int) (getenv('ORK3_TEST_DB_PORT') ?: 19307));
define('DB_DATABASE', 'ork_test');
```

### `.gitignore` additions

```
.ork3-db.local
tools/ork-db/extracted/
tools/ork-db/rendered/
tools/ork-db/.last-apply.json
```

---

## 6. Resolved decisions

| # | Decision | Value |
|---|----------|-------|
| 1 | Test port | **19307** |
| 2 | Sandbox database name | **ork_test** |
| 3 | CLI name | **`bin/ork-db`** |
| 4 | Profiles | **`use prod\|dev` only** — mirror vs sandbox app switching; no profile on data commands |
| 5 | Implementation | **PHP 8.2+** |
| 6 | Production safety | **Tier guard** refuses extract/render/apply on production hosts |
| 6 | Principality | **Grand Duchy of Litavia** (9005 ⊂ Empire of Ashkara 9001) |
| 7 | Real player IDs | Ken Walker `43232`, Avery Krouse `46193` in `extract-sources.json5` |
| 8 | Content seed | Arbitrary integer for `mt_srand()` — persisted in `fingerprints.json5`; default `42` is a stable starting point, not domain-specific |
| 9 | Type 1 tables | `fixed_extract` + `fixed_embedded` in `extract-sources.json5` |
| 10 | Drift detection | `bin/ork-db drift-check` — schema + catalog + migration coverage |
| 11 | Dev bootstrap | `bin/ork-db bootstrap` — first-run sandbox setup (TD-7) |

---

## 8. Dev environment bootstrap

### What exists today

| Piece | Status | Notes |
|-------|--------|-------|
| `docker-compose.php8.yml` + `ork3testdb` | **Done (TD-1)** | Port `19307`, DB `ork_test`, volume `data-test-db` |
| Volume isolation from mirror | **Done (TD-1)** | `data-db` vs `data-test-db` — separate Docker named volumes |
| `bin/ork-db init` | **Done (TD-2)** | Schema + test canary only; no fake data |
| `bin/ork-db extract` / `apply` | **Done (TD-3–5)** | Full sandbox reload |
| `bin/ork-db bootstrap` | **Planned (TD-7)** | Single first-run command |

### First-run workflow (manual, until `bootstrap` ships)

```bash
# 1. Containers
docker compose -f docker-compose.php8.yml up -d

# 2. Mirror prerequisites (one-time per workstation)
#    — import a dev dump into ork @ 19306 if the mirror is empty
#    — apply db-migrations/2026-07-07-add-prod-canary.sql to ork

# 3. Sandbox bootstrap
bin/ork-db init              # schema + _ork_canary_test on empty ork_test
bin/ork-db extract           # catalogs from mirror (19306)
bin/ork-db apply --yes       # wipe + reload sandbox (19307)

# 4. Verify
bin/ork-db validate --mode post-apply
bin/ork-db status
```

### Planned `bin/ork-db bootstrap` (TD-7)

Idempotent orchestrator for step 3 above:

```
tier check (local only)
  → optional: warn if ork3testdb not reachable (suggest docker compose up)
  → init (skip if test canary already valid)
  → extract (skip if extracted/ fresh unless --force-extract)
  → apply --yes
  → validate --mode post-apply
  → status
```

Flags: `--yes` (skip prompts), `--skip-extract` (reuse existing extracts), `--force-extract`.

Does **not** import mirror dumps or install prod canary — those remain manual one-time mirror setup.

---

## 7. Success criteria (project complete)

- [x] Two MariaDB containers; sandbox on 19307 only (`docker-compose.php8.yml`)
- [x] Sandbox volume `data-test-db` isolated from mirror volume `data-db`
- [ ] `apply` cannot succeed on production tier or against mirror (19306 / `ork`)
- [ ] Prod canary on prod-like DB prevents mistaken wipe
- [ ] 5 kingdom rows: 4 sovereigns with varied monikers + 1 principality
- [ ] Attendance spans 3 years ending today after each `apply`
- [ ] GOK, Spring War, Olympiad events present
- [ ] admin, megiddo, Ken Walker, Avery Krouse accounts work
- [ ] `bin/ork-db use` toggles app between prod and dev profiles
- [ ] Full PHPUnit suite runs against dev profile
- [ ] `bin/ork-db bootstrap` brings a fresh clone from zero to post-apply sandbox
- [ ] Award catalog matches prod extract
