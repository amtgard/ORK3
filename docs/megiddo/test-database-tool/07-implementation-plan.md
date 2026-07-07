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
| **TD-10** | Dev entry orchestrator | `bin/ork-db deploy-sandbox` — single safe daily startup command |

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
  Bootstrap.php
  DeploySandbox.php
  Use.php
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

### Phase 4 — TD-6 + TD-7 (done)

**Acceptance:**

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db bootstrap --yes
bin/ork-db use dev
bin/ork-db use prod
php vendor/bin/phpunit -c phpunit.ork-db.xml.dist
# Main suite: blocked on TD-8 schema parity — see 11-post-implementation-tasks.md
```

### Phase 5 — TD-8 + TD-9

- Full migration classifier
- `bin/ork-db drift-check --strict` in CI (every build)
- `bin/ork-db schema-diff` (post-apply sandbox parity)
- PHPUnit golden + apply round-trip
- Main Megiddo suite green against sandbox

### Phase 6 — TD-10 (`deploy-sandbox`)

**Goal:** One safe command to bring local dev from whatever state it is in to whatever state it needs to be — first clone, first run of the day, or no-op if already current.

**Acceptance:**

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox
# App on sandbox; dates anchored to today; validate passed; open http://localhost:19080/orkui/
```

See §9 for full pipeline spec.

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
tools/ork-db/rendered/.last-render.json
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
| 12 | Daily dev entry | `bin/ork-db deploy-sandbox` — init/bootstrap + validate + `use dev` + daily refresh (TD-10) |

---

## 8. Dev environment bootstrap

### What exists today

| Piece | Status | Notes |
|-------|--------|-------|
| `docker-compose.php8.yml` + `ork3testdb` | **Done (TD-1)** | Port `19307`, DB `ork_test`, volume `data-test-db` |
| Volume isolation from mirror | **Done (TD-1)** | `data-db` vs `data-test-db` — separate Docker named volumes |
| `bin/ork-db init` | **Done (TD-2)** | Schema + test canary only; no fake data |
| `bin/ork-db extract` / `apply` | **Done (TD-3–5)** | Full sandbox reload |
| `bin/ork-db bootstrap` | **Done (TD-7)** | Idempotent init + extract + apply |
| `bin/ork-db deploy-sandbox` | **Planned (TD-10)** | Single daily dev entry — see §9 |

### Target workflow (after TD-10)

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox
# Done — app on sandbox, data current for today
```

### Manual / low-level workflow (still supported)

```bash
# 1. Containers
docker compose -f docker-compose.php8.yml up -d

# 2. Mirror prerequisites (one-time per workstation)
#    — import a dev dump into ork @ 19306 if the mirror is empty
#    — apply db-migrations/2026-07-07-add-prod-canary.sql to ork

# 3. Sandbox only (no app switch, no daily-refresh logic)
bin/ork-db bootstrap --yes

# 4. Verify
bin/ork-db validate --mode post-apply
bin/ork-db status
```

### `bin/ork-db bootstrap` (TD-7 — done)

Idempotent sandbox loader only (no `use dev`, no daily-refresh gate):

```
tier check (local only)
  → init (skip if test canary already valid)
  → extract (skip if extracted/ fresh unless --force-extract)
  → apply --yes
  → validate --mode post-apply
```

Flags: `--yes`, `--skip-extract`, `--force-extract`.

Does **not** import mirror dumps or install prod canary — those remain manual one-time mirror setup.

---

## 9. `deploy-sandbox` (TD-10 — planned)

### Purpose

**Single safe call** to start local dev from wherever the workstation is to wherever it needs to be. Replaces the multi-step mental model (init? bootstrap? use dev? apply?) with one command operators run after `docker compose up`.

```bash
bin/ork-db deploy-sandbox
bin/ork-db deploy-sandbox --yes    # skip confirmation prompts only
```

**Local tier only** — same refusal rules as other data commands. Does **not** start Docker.

### State detection

The command classifies sandbox state before acting:

| State | Signals | Action |
|-------|---------|--------|
| **Uninitialized** | Sandbox reachable but `_ork_canary_test` missing | `init` |
| **First-run** | Test canary present; kingdom fingerprints (9001–9005) missing | `bootstrap` path (extract + apply) |
| **Stale render** | Post-apply valid but last render `anchor_date` &lt; today (local date) | `extract` → `render` → `apply --yes` |
| **Current** | Post-apply valid; render anchored today | Skip data pipeline |
| **Blocked** | Any validate check fails | **Halt** — print remediation steps; exit `2` |

Last-render metadata stored in gitignored `tools/ork-db/rendered/.last-render.json` (written by `render` / `apply`):

```json5
{
  "anchor_date": "2026-07-07",
  "rendered_at": "2026-07-07T17:30:00-05:00",
  "content_seed": 42
}
```

Daily refresh compares `anchor_date` to **today** in `America/Chicago` (same TZ as ORK3 config). Same-day re-runs are no-ops for extract/render/apply unless `--force-refresh`.

### Pipeline

```
tier check (local only)
  → preflight: mirror + sandbox ports reachable
  → init                    (if uninitialized)
  → bootstrap               (if first-run — extract + apply if needed)
  → validate                (strict — HALT on failure with remediation)
  → use dev                 (app container → ork_test)
  → if stale render:
      extract → render → apply --yes
  → validate --mode post-apply
  → status
```

### Validate-and-halt behavior

Every validate failure **stops the pipeline** and prints **actionable remediation** (not just "FAIL"). Examples:

| Check | Remediation hint |
|-------|------------------|
| Sandbox port unreachable | `docker compose -f docker-compose.php8.yml up -d ork3testdb` |
| Mirror port unreachable | `docker compose -f docker-compose.php8.yml up -d ork3db` |
| Mirror extract refused (no prod canary) | Apply `db-migrations/2026-07-07-add-prod-canary.sql` to `ork` @ 19306 |
| Mirror empty / extract fails | Import dev dump into mirror (one-time workstation setup) |
| Prod canary on sandbox target | **ABORT** — wrong database; do not proceed |
| Post-apply fingerprints fail | Re-run with `--force-refresh` or inspect `tools/ork-db/rendered/sandbox.sql` |

Exit code `2` on any validation or tier refusal. Exit code `0` when sandbox is current and app is on `dev`.

### Flags

| Flag | Default | Description |
|------|---------|-------------|
| `--yes` | off | Skip interactive confirmation on apply (never skips safety checks) |
| `--force-refresh` | off | Run extract → render → apply even if render is already anchored today |
| `--skip-use-dev` | off | Skip app profile switch (sandbox-only maintenance) |

### Relationship to other commands

| Command | When to use |
|---------|-------------|
| **`deploy-sandbox`** | **Default** — start of day, new clone, "make dev work" |
| `bootstrap` | Sandbox data only; no app switch; no daily-refresh logic |
| `apply --yes` | Quick reset when you know sandbox is already initialized |
| `init` | Low-level; rarely needed directly |

### Implementation notes

- Class: `tools/ork-db/DeploySandbox.php`
- Reuses: `Init`, `Bootstrap`, `Extract`, `Render`, `Apply`, `Validate`, `UseProfile`, `DeploymentTier`
- `render` and `apply` must update `.last-render.json` with `anchor_date`
- TD-8 schema parity should land **before** TD-10 sign-off so post-validate reflects real app schema

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
- [ ] `bin/ork-db deploy-sandbox` is the one-command daily dev entry (TD-10)
- [ ] Award catalog matches prod extract
