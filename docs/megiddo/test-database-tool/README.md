# ORK3 Test Database Tool — Plan Index

Explicit, isolated test database for ORK3 development and PHPUnit integration tests. Replaces the current practice of sharing the dev `ork` database on `localhost:19306` with fake-but-realistic data generated from compositional SQL templates.

**Goal:** Safe, repeatable test data that mirrors production schema and reference catalogs (awards, classes) while using entirely synthetic kingdoms, parks, and most players — plus a small set of real operator accounts.

**Code:** `tools/ork-db/` · **CLI:** `bin/ork-db` · **Docs:** `docs/megiddo/test-database-tool/`

**Implementation status:** TD-1–TD-10 shipped. **TD-11** (heraldry + ID namespace) planned. See [12-heraldry-and-assets.md](./12-heraldry-and-assets.md) and [11-post-implementation-tasks.md](./11-post-implementation-tasks.md).

---

## Documents

| Doc | Purpose |
|-----|---------|
| [01-architecture.md](./01-architecture.md) | Two-container model, config routing, pipeline overview |
| [02-data-model.md](./02-data-model.md) | Table tiers, fake vs real data, volume rules, major events |
| [03-template-system.md](./03-template-system.md) | Compositional SQL rendering, stable vs shifting templates |
| [04-safety-validations.md](./04-safety-validations.md) | Canary tables, port lock, kingdom/park fingerprints |
| [05-tools-and-operations.md](./05-tools-and-operations.md) | CLI commands: switch, render, wipe/replay, validate |
| [06-migration-classification.md](./06-migration-classification.md) | Which `db-migrations/` files apply to test DB |
| [07-implementation-plan.md](./07-implementation-plan.md) | Phases, repo layout, milestones, acceptance criteria |
| [08-agent-milestone-prompt.md](./08-agent-milestone-prompt.md) | Copy-paste agent prompt per TD milestone |
| [09-milestone-checklist.md](./09-milestone-checklist.md) | TD-* completion checkboxes |
| [10-cli-reference.md](./10-cli-reference.md) | **`bin/ork-db` command palette** |
| [11-post-implementation-tasks.md](./11-post-implementation-tasks.md) | **Post TD-6/7 backlog, schema-drift explanation, sign-off path** |
| [12-heraldry-and-assets.md](./12-heraldry-and-assets.md) | **TD-11: heraldry generation, asset deploy, ID namespace (`100001` / `1M` parks / `100M` players)** |

---

## Problem statement (today)

| Issue | Current state |
|-------|---------------|
| No isolation | `config.test.php` and `config.dev.php` both target database `ork` on the same MariaDB container (`ork3-php8-db`, port `19306`) |
| Shared mutable data | Integration tests and manual dev share one persistent volume (`data-db`) |
| Skip-by-default | Tests call `ork3_test_db_available()` and skip when DB is down — but when up, they mutate dev data |
| No reproducible seed | PHPUnit fixtures insert ephemeral rows; no canonical "reset to known state" |

---

## Target state (summary)

```
┌─────────────────────┐     ┌─────────────────────┐
│  ork3-php8-db       │     │  ork3-php8-test-db  │
│  profile: prod      │     │  profile: dev         │
│  port 19306         │     │  port 19307           │
│  DB: ork            │     │  DB: ork_test         │
│  volume: data-db    │     │  volume: data-test-db │
│  canary: _ork_canary_prod │  canary: _ork_canary_test │
└─────────┬───────────┘     └─────────┬───────────┘
          │                           │
          └───────────┬───────────────┘
                      │
              ork3-php8-app
              (bin/ork-db use prod|dev)
```

| Profile | App config | PHPUnit config |
|---------|------------|----------------|
| **prod** | `config.dev.php` → `ork3-php8-db:3306` / `ork` | N/A |
| **dev** | `use dev` override | `config.test.php` → `127.0.0.1:19307` / `ork_test` |

---

## Quick operations

```bash
# First-run (see 05-tools-and-operations.md for full bootstrap)
docker compose -f docker-compose.php8.yml up -d
bin/ork-db init && bin/ork-db extract && bin/ork-db apply --yes

# Switch which DB the local website uses (TD-6 — planned)
bin/ork-db use prod      # mirror — real local data
bin/ork-db use dev       # sandbox — fake data

# Sandbox lifecycle (no database argument — targets are hardcoded)
bin/ork-db extract       # read mirror
bin/ork-db render        # build SQL
bin/ork-db apply         # wipe + reload sandbox
```

On **production servers**, `extract` / `render` / `apply` refuse to run. See [10-cli-reference.md](./10-cli-reference.md).

---

## Relationship to Megiddo refactor

| Artifact | Test database tool |
|----------|-------------------|
| `docs/megiddo/refactor/06-test-framework.md` | PHPUnit defaults → `19307` / `ork_test`; `deploy-sandbox` before sign-off |
| `tests/Integration/*` | Stable fake kingdoms/parks instead of dev seed lottery |
| `tests/Support/*Fixture.php` | May simplify as canonical sandbox data exists |
| `bin/fuzzy-validator` | **`test`** profile uses same sandbox via `ork-db use dev` ([fuzzy-validator](../fuzzy-validator/reference/11-dual-database-profiles.md)) |
| `db-migrations/` | Schema applied to both DBs; content migrations filtered per [06-migration-classification.md](./06-migration-classification.md) |

---

## Prerequisites

| Requirement | Notes |
|-------------|-------|
| Docker php8 stack | `docker compose -f docker-compose.php8.yml up -d` — includes `ork3db` (mirror, `data-db`) and `ork3testdb` (sandbox, `data-test-db`) |
| Mirror database | Import dev dump into `ork` @ `19306`; apply prod canary migration before first `extract` |
| Sandbox bootstrap | `bin/ork-db init` + `extract` + `apply` (or `bin/ork-db bootstrap` when TD-7 ships) |
| PHP 8.2+ CLI | Renderer in `tools/ork-db/` — see [07-implementation-plan.md](./07-implementation-plan.md) |
