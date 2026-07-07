# Test Database Tool — CLI Reference

**Binary:** `bin/ork-db`  
**Implementation:** `tools/ork-db/`  
**Run:** `bin/ork-db <command> [options]`

---

## Critical constraint: this ships to production

The entire ORK3 repo deploys to production servers. `bin/ork-db` **will exist on production**. A mistyped database argument there could wipe the real database.

**Design rule:** `extract`, `render`, and `apply` take **no database parameter**. Targets are **hardcoded in the tool manifest** and guarded by **deployment-tier detection**. The operator cannot aim these commands at an arbitrary database.

Only `use prod|dev` accepts a target name — and on production, `use dev` refuses (no sandbox exists).

---

## Command palette

```
bin/ork-db use <prod|dev>     # switch which DB the local app container uses
bin/ork-db status             # show tier, wiring, canaries (read-only)

bin/ork-db extract            # read-only pull — source is hardcoded
bin/ork-db render             # compose sandbox SQL — no DB connection
bin/ork-db apply              # wipe + reload sandbox — target is hardcoded

bin/ork-db validate           # safety checks on hardcoded sandbox target
bin/ork-db init               # first-time sandbox schema + canary
bin/ork-db bootstrap          # sandbox-only: init + extract + apply
bin/ork-db deploy-sandbox     # daily dev entry: bootstrap + validate + use dev + daily refresh (TD-10)
bin/ork-db schema-diff        # compare mirror vs sandbox schema (local only)

bin/ork-db help [command]
```

### What each command does (no guesswork required)

| Command | Takes DB arg? | Connects to DB? | What it always means |
|---------|---------------|-----------------|----------------------|
| `use prod` | yes (`prod`) | No (config only) | App container → mirror DB (`19306` / `ork`) |
| `use dev` | yes (`dev`) | No (config only) | App container → sandbox DB (`19307` / `ork_test`) |
| `extract` | **no** | Yes, read-only | Pull catalogs from **mirror** (see wiring below) |
| `render` | **no** | No | Build sandbox SQL file from templates + extracts |
| `apply` | **no** | Yes, destructive | Wipe + reload **sandbox only** |
| `validate` | **no** | Yes, read-only | Probe **sandbox only** |
| `status` | **no** | Maybe read-only | Print tier + wiring + health |

---

## Hardcoded wiring (not overridable)

Stored in `tools/ork-db/manifests/wiring.json5`. **Not** exposed as CLI flags in v1.

| Role | Host | Port | Database | Container |
|------|------|------|----------|-----------|
| **Mirror** (extract source) | `127.0.0.1` | `19306` | `ork` | `ork3-php8-db` |
| **Sandbox** (apply target) | `127.0.0.1` | `19307` | `ork_test` | `ork3-php8-test-db` |

`extract` always reads mirror. `apply` always writes sandbox. There is no syntax to point either command elsewhere.

---

## Deployment tier detection

Before any command runs, the tool classifies the host:

| Tier | How detected | `extract` / `render` / `apply` |
|------|--------------|----------------------------------|
| **local** | Sandbox port `19307` reachable **and** mirror port `19306` reachable **and** `config.php` hostname is local/docker **or** `ENVIRONMENT=DEV` | Allowed (with safety checks) |
| **production** | `config.php` points at production host **or** `_ork_canary_prod` on default app connection **or** sandbox port unreachable | **`extract` / `apply` / `init` refused**; `render` refused (no reason to run on prod box) |

Production refusal message (example):

```
ork-db: REFUSED — this host is classified as production.
Data commands (extract, render, apply) only run on local workstations.
```

`status` is always allowed (read-only diagnostics).

`use prod` on production: no-op or affirms default production config.  
`use dev` on production: **refused** — sandbox does not exist.

---

## `use prod|dev`

The **only** command where the operator names a target. Switches the Docker app container — does not run SQL.

```bash
bin/ork-db use prod    # app → mirror (19306 / ork)
bin/ork-db use dev     # app → sandbox (19307 / ork_test)
```

On **production**: `use dev` refuses. `use prod` is a no-op (app already uses production DB).

Writes `ORK3_DB_PROFILE` to `.ork3-db.local` (local workstations only; file gitignored).

---

## `extract`

```bash
bin/ork-db extract
bin/ork-db extract --table award
bin/ork-db extract --players-only
```

- **Always** reads mirror: `127.0.0.1:19306/ork`
- `SELECT` only — never mutates
- **Refused on production tier**
- **Refused** if mirror has `_ork_canary_prod` missing (mirror not initialized) — optional guard
- Output: `tools/ork-db/extracted/`

---

## `render`

```bash
bin/ork-db render
bin/ork-db render --anchor-date 2026-07-07 --seed 42
bin/ork-db render --deterministic
```

- **No database connection**
- Reads templates + `extracted/` → writes `tools/ork-db/rendered/sandbox.sql`
- **Refused on production tier** (no local sandbox workflow on prod box)
- Requires prior `extract` on a local workstation

| Flag | Default | Description |
|------|---------|-------------|
| `--anchor-date` | today | End of 3-year window |
| `--seed` | `42` | Park/player volume seed |
| `--output` | `tools/ork-db/rendered/sandbox.sql` | Output path (still under repo tree) |
| `--deterministic` | off | Byte-stable golden output |

---

## `apply`

```bash
bin/ork-db apply
bin/ork-db apply --sql tools/ork-db/rendered/sandbox.sql
bin/ork-db apply --yes    # skip confirmation only — never skips safety checks
```

- **Always** targets sandbox: `127.0.0.1:19307/ork_test`
- **Refused on production tier**
- **Refused** unless all checks in [04-safety-validations.md](./04-safety-validations.md) pass
- There is no `apply` code path for mirror, port 3306, or database `ork`

Pipeline:

```
tier check (local only)
  → validate (strict)
  → render (unless --sql)
  → confirmation prompt (unless --yes)
  → load into sandbox
  → validate (post-apply)
  → status
```

---

## `validate` / `init` / `status`

```bash
bin/ork-db validate
bin/ork-db validate --mode init|post-apply
bin/ork-db init
bin/ork-db status
```

All probe **sandbox only** (hardcoded `19307` / `ork_test`). Refused on production tier except `status` (read-only tier report).

### `init` vs bootstrap vs deploy-sandbox

| Command | Scope |
|---------|-------|
| `init` | Empty `ork_test` → apply `ork.sql` + install `_ork_canary_test` only |
| `bootstrap` | Sandbox only: `init` (if needed) → `extract` → `apply` → post-validate |
| `deploy-sandbox` *(TD-10)* | **Default dev entry:** detect state → init/bootstrap → validate (halt + hints) → `use dev` → daily refresh if stale |

`init` does **not** load fake kingdoms or players. Prefer `deploy-sandbox` for day-to-day work; use `bootstrap` when you only need sandbox data without switching the app.

---

## `bootstrap`

```bash
bin/ork-db bootstrap
bin/ork-db bootstrap --yes
bin/ork-db bootstrap --skip-extract
bin/ork-db bootstrap --force-extract
```

- **Local tier only** — same refusal rules as other data commands
- Does **not** switch app profile — use `use dev` separately, or run `deploy-sandbox`
- Does **not** start Docker
- Does **not** import mirror dumps or install prod canary (manual mirror one-time setup)

Pipeline:

```
tier check (local only)
  → init (if test canary missing)
  → extract (unless --skip-extract)
  → apply --yes (unless interactive)
  → validate --mode post-apply
```

---

## `deploy-sandbox` *(planned — TD-10)*

```bash
bin/ork-db deploy-sandbox
bin/ork-db deploy-sandbox --yes
bin/ork-db deploy-sandbox --force-refresh
```

**Single safe command** to start local dev from whatever state the workstation is in to whatever state it needs to be.

Automatically:

1. **Detects state** — uninitialized sandbox, first-run (no fake data), or current
2. **Inits and/or bootstraps** as needed
3. **Validates** at each gate — **halts on error** with remediation steps (not just exit codes)
4. **Switches app to dev** (`use dev` → sandbox @ 19307)
5. **Refreshes data if stale** — runs `extract` → `render` → `apply` when last render `anchor_date` is before today

Pipeline:

```
tier check (local only)
  → preflight (mirror + sandbox reachable)
  → init (if test canary missing)
  → bootstrap path (if kingdom fingerprints missing)
  → validate — HALT with remediation on failure
  → use dev
  → if last render anchor_date < today: extract → render → apply --yes
  → validate --mode post-apply
  → status
```

| Flag | Description |
|------|-------------|
| `--yes` | Skip apply confirmation only — never skips safety checks |
| `--force-refresh` | Run extract/render/apply even if render is already anchored today |
| `--skip-use-dev` | Sandbox maintenance without switching app profile |

Last-render metadata: `tools/ork-db/rendered/.last-render.json` (gitignored). See [07-implementation-plan.md](./07-implementation-plan.md) §9.

---

## Examples (local workstation only)

```bash
# Target daily workflow (TD-10)
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox
open http://localhost:19080/orkui/

# Low-level / sandbox-only (no app switch)
bin/ork-db bootstrap --yes

# Manual step-by-step
bin/ork-db init
bin/ork-db extract
bin/ork-db apply --yes
bin/ork-db use dev

# Browse mirror again
bin/ork-db use prod
```

---

## What happens on production (intentional)

An operator who SSHs to production and runs:

```bash
bin/ork-db apply        # REFUSED — production tier
bin/ork-db extract      # REFUSED — production tier
bin/ork-db render       # REFUSED — production tier
bin/ork-db use dev      # REFUSED — no sandbox
bin/ork-db status       # OK — prints "tier: production, data commands: disabled"
```

Even if someone patches the CLI or manifest, `apply` still runs [04-safety-validations.md](./04-safety-validations.md) checks: port must be `19307`, database must be `ork_test`, prod canary must be absent on target, test canary must be present.

---

## Exit codes

| Code | Meaning |
|------|---------|
| `0` | Success |
| `1` | Runtime error |
| `2` | Validation or tier refusal |
| `3` | User cancelled confirmation |

---

## Environment variables

| Variable | Purpose |
|----------|---------|
| `ORK3_DB_PROFILE` | Set by `use` → `.ork3-db.local` (local app switching) |
| `ORK3_TEST_DB_HOST` | PHPUnit override (default `127.0.0.1`) |
| `ORK3_TEST_DB_PORT` | PHPUnit override (default `19307`) |
| `ORK_DB_RENDER_SEED` | Render volume seed |

**No env var overrides wiring host/port/database for extract or apply in v1.**

---

## `tools/ork-db/` layout

```
tools/ork-db/
  cli.php
  Render.php
  Validate.php
  Extract.php
  lib/
    DeploymentTier.php    # local vs production classification
    Wiring.php            # hardcoded mirror/sandbox endpoints
  manifests/
    wiring.json5          # mirror + sandbox endpoints (committed, not CLI-overridable)
    fingerprints.json5
    extract-sources.json5
```

`bin/ork-db`:

```bash
#!/usr/bin/env bash
exec php tools/ork-db/cli.php "$@"
```
