# ork-db

ORK3 needs a **sandbox database for development** — a wipeable, reproducible `ork_test` you can break, reset, and use for PHPUnit, e2e, fuzzy-validator, and manual browsing — without risking a shared local prod **mirror** or anything that looks like production.

`bin/ork-db` builds and maintains that sandbox (schema + synthetic data + assets), patches known test logins, and switches the local app between sandbox and mirror with fail-closed tier checks.

Entry point: `bin/ork-db` → `tools/ork-db/cli.php`.

---

## Background

Local development used to treat one MySQL volume as everything: a dump of (or stand-in for) production, shared PHPUnit state, and the DB the browser hit. That leaves no safe place to experiment. Wipe/reload is terrifying, fixtures drift, test logins collide with real-ish accounts, and there is no crisp “point the app at the toy world” vs “point it at the mirror.”

ork-db’s answer is a **composed sandbox** plus a one-command **app switch**:

- **Sandbox** (`ork_test` @ `19307`, container `ork3-php8-test-db`) — production-like schema, catalogs pulled from the mirror, fake kingdoms/parks/players at a fixed seed, hybrid operator accounts, heraldry assets, known passwords. Destructive apply always hits this wiring only (hardcoded; never a CLI host/DB string).
- **Mirror** (`ork` @ `19306`, container `ork3-php8-db`) — your local prod-like dataset; source for extracts; what `use prod` browses.

`bin/ork-db use dev|prod` flips the app between them (writes `.ork3-db.local`, restarts `ork3app`). Data commands refuse to run when the host is classified as production.

Consumers: PHPUnit (`config.test.php` → sandbox), Infection, fuzzy-validator’s `test` profile, and manual UI after `use dev`.

**Problems this tool solves** (short sections below):

1. [Easy and safe to distribute](#1-easy-and-safe-to-distribute)
2. [Stable and lively content for development and validation](#2-stable-and-lively-content-for-development-and-validation)
3. [Safe to corrupt and restore](#3-safe-to-corrupt-and-restore)
4. [Easy to switch between sandbox and mirror](#4-easy-to-switch-between-sandbox-and-mirror)
5. [Easy to patch key user accounts for automatic and manual validation](#5-easy-to-patch-key-user-accounts-for-automatic-and-manual-validation)
6. [Preflight checks that keep DB switching in a non-prod dev environment](#6-preflight-checks-that-keep-db-switching-in-a-non-prod-dev-environment)

---

## Quick start

The sandbox and mirror MariaDB instances (and the `ork3app` container that `use` restarts) are defined in this repo’s Docker Compose stack. ork-db talks to those hardwired host/ports, loads SQL via `docker exec` into the sandbox container, and treats unreachable DB ports as “not a local tier.” Start the stack before any data command:

```bash
# Bring up app + mirror DB (19306) + sandbox DB (19307). Required for deploy/use/apply/etc.
docker compose -f docker-compose.php8.yml up -d

# One-time if the mirror was imported fresh: install prod canary on ork @ 19306
# docker exec -i ork3-php8-db mariadb -uroot -proot ork < db-migrations/2026-07-07-add-prod-canary.sql

bin/ork-db deploy-sandbox --yes
```

`deploy-sandbox` is the usual day-one / daily path: ensure sandbox schema+data, point the app at sandbox (`use dev`), deploy heraldry assets, and seed sandbox test credentials ([Seeded logins](#seeded-logins)).

```bash
bin/ork-db use prod    # browse the mirror
bin/ork-db use dev     # browse the sandbox
bin/ork-db status      # tier + reachability
```

### Seeded logins

By default, the sandbox database is enriched with known logins for manual and automated testing and evaluation. Parameters and tooling for enriching a production-mirror database instance with seeded accounts are also provided.

| When | What gets seeded |
|------|------------------|
| End of **`deploy-sandbox`** | Always — **sandbox only** (`megiddo` / `admin`). Runs even after a no-op refresh so extracted prod hashes do not stick. |
| **`bin/ork-db seed-test-credentials`** (or `bin/seed-test-credentials`) | On demand. Default **`--target both`** (sandbox + mirror). Pass `--target sandbox` or `--target mirror` to limit. |
| **`apply` / `bootstrap` alone** | Does **not** seed. Re-apply can restore credential hashes from extracts; run `seed-test-credentials` (or `deploy-sandbox`) afterward. |

| Target | Username | Password |
|--------|----------|----------|
| Sandbox (after `deploy-sandbox` or `--target sandbox\|both`) | `megiddo` | `test-db-player` |
| Sandbox | `admin` | `password` |
| Mirror (only with `seed-test-credentials --target mirror\|both`) | `admin` | `password` |

---

## Commands overview

| Command | Purpose |
|---------|---------|
| **`status`** | Show local/production tier and DB reachability |
| **`use`** | Point the app at sandbox (`dev`) or mirror (`prod`) |
| **`deploy-sandbox`** | Daily entry: init/bootstrap as needed, validate, `use dev`, refresh if stale, deploy assets, seed sandbox passwords |
| **`seed-test-credentials`** | Patch known passwords (default both DBs) |
| **`bootstrap`** | init (if needed) → extract → apply — does **not** switch the app or seed credentials |
| **`init`** | Load `ork.sql` into sandbox + install test canary (empty of fake data) |
| **`extract`** | Read-only dump of catalogs/players/events from mirror → `extracted/` |
| **`render`** | Compose `rendered/sandbox.sql` from schema, migrations, templates, extracts |
| **`apply`** | Wipe `ork_test`, load rendered SQL, post-validate (prompt unless `--yes`) |
| **`validate`** | Safety checks: wiring locks, canaries, kingdom fingerprints, blocklist |
| **`generate-assets` / `deploy-assets`** | Build heraldry rasters → copy into repo `assets/` |
| **`drift-check`** | Migration classification coverage + catalog fingerprints (`--strict` for CI) |
| **`schema-diff`** | Compare `SHOW CREATE TABLE` mirror vs sandbox |

**Exit codes:** `0` ok · `1` runtime · `2` validation / tier refusal · `3` user cancelled apply confirm.

Flag-by-flag detail: [Command reference](#command-reference).

### Common workflows

```bash
# Fresh machine / daily (compose required — see Quick start)
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox --yes

# Force a full rebuild today
bin/ork-db deploy-sandbox --force-refresh --yes

# Refresh catalogs after mirror changes, then reload sandbox
bin/ork-db extract
bin/ork-db apply --yes
bin/ork-db seed-test-credentials --target sandbox

# Assets only
bin/ork-db generate-assets
bin/ork-db deploy-assets

bin/run-ork-db-checks.sh
vendor/bin/phpunit -c phpunit.ork-db.xml.dist
```

---

## Command reference

Most data commands need the Compose stack from [Quick start](#quick-start) and a local deployment tier (`bin/ork-db status`).

### `status`

Prints deployment tier, mirror/sandbox reachability, and whether data commands are enabled.

```bash
bin/ork-db status
```

### `use`

Writes `.ork3-db.local` (`ORK3_DB_PROFILE`) and restarts `ork3app`. `use dev` is refused off local tier.

```bash
# Point the app at the sandbox (ork_test @ 19307).
bin/ork-db use dev

# Point the app at the mirror (ork @ 19306).
bin/ork-db use prod
```

### `deploy-sandbox`

Day-one / daily path: preflight → init/bootstrap as needed → validate → `use dev` → refresh if stale → deploy assets → always seed **sandbox** credentials ([Seeded logins](#seeded-logins)).

```bash
# Ensure sandbox is ready, switch app to dev, deploy assets, seed sandbox logins.
bin/ork-db deploy-sandbox --yes

# --yes: skip interactive confirm on apply/refresh (needed for non-interactive runs).
bin/ork-db deploy-sandbox --yes

# --force-refresh: wipe and rebuild sandbox even if today’s render looks current.
bin/ork-db deploy-sandbox --force-refresh --yes

# --skip-use-dev: leave the app profile unchanged; still init/bootstrap/seed sandbox.
bin/ork-db deploy-sandbox --yes --skip-use-dev
```

### `seed-test-credentials`

PDO rewrite of known test passwords ([Seeded logins](#seeded-logins)). Default `--target both`. `deploy-sandbox` calls the same logic for sandbox only. Alias: `bin/seed-test-credentials`.

```bash
# Seed sandbox + mirror (default when --target is omitted).
bin/ork-db seed-test-credentials

# --target sandbox: only ork_test (same accounts deploy-sandbox seeds).
bin/ork-db seed-test-credentials --target sandbox

# --target mirror: only ork (admin / password).
bin/ork-db seed-test-credentials --target mirror

# --target both: sandbox + mirror.
bin/ork-db seed-test-credentials --target both
```

### `bootstrap`

`init` (if needed) → `extract` → `apply`. Does not switch the app or seed credentials.

```bash
# Build sandbox from mirror extracts and load it.
bin/ork-db bootstrap --yes

# --yes: non-interactive apply confirm.
bin/ork-db bootstrap --yes

# --skip-extract: reuse existing tools/ork-db/extracted/ dumps.
bin/ork-db bootstrap --yes --skip-extract

# --force-extract: re-extract from mirror even if extracts look fresh.
bin/ork-db bootstrap --yes --force-extract
```

### `init`

Loads repo `ork.sql` into the sandbox and installs `_ork_canary_test`. Schema only — no synthetic kingdoms/players yet.

```bash
bin/ork-db init
```

### `extract`

Read-only dump from the mirror into `tools/ork-db/extracted/`. Requires the prod canary on the mirror.

```bash
# Extract configured catalog/player/event sources.
bin/ork-db extract

# --table NAME: one configured table/source only.
bin/ork-db extract --table award

# --players-only: player-related extract sources only.
bin/ork-db extract --players-only
```

### `render`

Composes `rendered/sandbox.sql` from schema, classified migrations, templates, and extracts. Does not touch MariaDB.

```bash
# Default anchor date and content seed.
bin/ork-db render

# --anchor-date: attendance/event window pivot (YYYY-MM-DD).
bin/ork-db render --anchor-date 2026-06-15

# --seed: reproducible fake kingdoms/players (default 42).
bin/ork-db render --seed 42

# --deterministic: stabilize non-content randomness in the render path.
bin/ork-db render --deterministic

# --persist-seed: record the chosen seed in last-render metadata.
bin/ork-db render --seed 42 --persist-seed

# --output: write SQL somewhere other than rendered/sandbox.sql.
bin/ork-db render --output /tmp/sandbox.sql
```

### `apply`

Pre-validate → DROP/CREATE `ork_test` → load SQL → post-validate. Full sandbox wipe. Does not seed credentials.

```bash
# Wipe sandbox and load the current render.
bin/ork-db apply --yes

# --yes: skip the destructive-apply confirmation prompt.
bin/ork-db apply --yes

# --sql: load this file instead of the default rendered path.
bin/ork-db apply --yes --sql /tmp/sandbox.sql
```

### `validate`

Read-only safety checks (wiring, canaries, fingerprints, blocklist).

```bash
# Default pre-apply gates.
bin/ork-db validate

# --mode init: allow missing test canary (post-schema expectations).
bin/ork-db validate --mode init

# --mode pre-apply: sandbox must look safe to wipe/load (default).
bin/ork-db validate --mode pre-apply

# --mode post-apply: after load; may include deployed-asset checks.
bin/ork-db validate --mode post-apply
```

### `generate-assets`

Renders heraldry/portraits into `tools/ork-db/generated-assets/` (optional `rsvg-convert`; GD fallback).

```bash
bin/ork-db generate-assets

# --seed: match art to this content seed (align with render --seed).
bin/ork-db generate-assets --seed 42
```

### `deploy-assets`

Copies `generated-assets/` into the app-facing `assets/heraldry/…` and `assets/players/` trees.

```bash
bin/ork-db deploy-assets
```

### `drift-check`

Migration classification coverage and catalog fingerprint checks (uses `extracted/` when present). Not the same tier gate as `apply`.

```bash
# Report drift; exit 0 on findings unless --strict.
bin/ork-db drift-check

# --strict: non-zero exit on unclassified migrations or fingerprint mismatches (CI).
bin/ork-db drift-check --strict
```

### `schema-diff`

Compares `SHOW CREATE TABLE` on mirror vs sandbox.

```bash
bin/ork-db schema-diff
```

### `help`

Prints CLI usage. Per-command help topics are not implemented yet.

```bash
bin/ork-db help
```

---

## Where outputs go

| Path | Git? | Contents |
|------|------|----------|
| `tools/ork-db/extracted/` | ignored | SQL dumps + `manifest.json` from mirror extract |
| `tools/ork-db/rendered/` | ignored | `sandbox.sql`, `.last-render.json` |
| `tools/ork-db/generated-assets/` | committed | Kingdom/park/player heraldry rasters |
| `tools/ork-db/manifests/` | committed | Wiring, fingerprints, migration classification, extract sources, asset manifest |
| `assets/heraldry/…`, `assets/players/` | deploy target | Copied by `deploy-assets` (served by the app) |
| `.ork3-db.local` | ignored | Active profile (`prod` / `dev`) for the app |
| Docker volumes `data-db` / `data-test-db` | host Docker | Mirror vs sandbox persistence |

Wiring (host/port/DB/container) is hardcoded in `manifests/wiring.json5` and is **not** overridable by CLI flags or env for data commands — by design.

---

## How it works

```text
Mirror (19306/ork) --extract--> extracted/*.sql
                                       │
  ork.sql + classified db-migrations + templates + fingerprints + extracts
                                       │
                                     render --> rendered/sandbox.sql
                                       │
                              apply: DROP/CREATE ork_test + load
                                       │
                         validate canaries + kingdom fingerprints
                                       │
              generate-assets --> generated-assets/ --> deploy-assets --> assets/
                                       │
                         seed-test-credentials (known passwords)
                                       │
                    use prod|dev --> .ork3-db.local --> app DB host
```

**Schema composition:** repo `ork.sql`, baseline gaps, then each migration classified in `manifests/migration-classification.json5` (`full` / `override` / skip), plus post-schema indexes.

**Data composition:** mirror catalogs; five fake kingdoms and parks; hybrid real players (admin, megiddo, …) plus synthetic mundanes; officers; events/attendance over a sliding window from an anchor date; test canary `_ork_canary_test`. Content seed defaults to a fixed value (42) for reproducibility.

**Safety layers:** deployment-tier gate; no DB connection string args on data commands; wiring locks (sandbox must not be ports like `3306`/`19306`); mutually exclusive prod/test canaries; kingdom fingerprint + real-name blocklist so a wrong DB fails closed.

`init` alone is **not** a ready sandbox — schema + test canary only. Use `deploy-sandbox` or `bootstrap` / `extract`+`apply`.

---

## Gotchas

- **Mirror needs the prod canary** before `extract` (`db-migrations/2026-07-07-add-prod-canary.sql` on `ork` @ 19306).
- **`apply` is a full wipe** of `ork_test` — not an incremental migration.
- Re-apply can restore credential hashes from extracts; `deploy-sandbox` reseeds sandbox afterward; after a bare `apply`, run `seed-test-credentials`.
- If both DBs are down or local env does not signal DEV, tier detection may treat the host as production and refuse data commands — start compose first.
- Optional `rsvg-convert` improves `generate-assets`; GD fallback exists.
- Per-command `help` topics are thin; this README + CLI usage text are the operator surface. Design depth: [`docs/megiddo/test-database-tool/`](../../docs/megiddo/test-database-tool/) (prefer code when those docs disagree).

---

## Problems addressed

### 1. Easy and safe to distribute

The sandbox is **generated from the repo**, not shipped as a giant shared dump of production. Templates, migration classification, fingerprints, and (committed) generated heraldry live under `tools/ork-db/`. Anyone with docker compose + a local mirror can run `deploy-sandbox` and get the same compositional recipe.

Safety for distribution: the tool ships in a repo that also goes to production hosts, so data commands are **refuse-by-default** off local tier ([§6](#6-preflight-checks-that-keep-db-switching-in-a-non-prod-dev-environment)). There is no CLI flag to aim `apply` at an arbitrary hostname.

### 2. Stable and lively content for development and validation

Sandbox data is **stable** (fixed content seed, kingdom fingerprints, deterministic attendance windows from an anchor date) so PHPUnit, fuzzy-validator, and screenshots can compare against a known world — and **lively** enough to exercise real UI (multiple kingdoms/parks, hybrid real operator accounts, events, officers, heraldry) instead of empty schema stubs.

Catalog reference rows come from the mirror via `extract`, so award/class/title vocabularies stay current without importing full prod player noise.

### 3. Safe to corrupt and restore

Sandbox is a separate Docker volume and database. `apply` / `deploy-sandbox --force-refresh` drop and rebuild `ork_test` only. Break foreign keys, trash rows, or crash mid-migration in the sandbox; restore with another apply. The mirror stays intact for prod-like browsing and as the extract source.

### 4. Easy to switch between sandbox and mirror

```bash
bin/ork-db use dev     # app → sandbox (ork_test @ 19307)
bin/ork-db use prod    # app → mirror (ork @ 19306)
bin/ork-db status
```

`use` writes `.ork3-db.local` (`ORK3_DB_PROFILE=dev|prod`) and restarts `ork3app`. Fuzzy-validator profiles call the same switch between captures. You do not edit PHP config by hand to flip worlds.

### 5. Easy to patch key user accounts for automatic and manual validation

Playwright, fuzzy-validator, and humans need passwords that are known and sticky. Extracted/mirror hashes are not those passwords.

- **`deploy-sandbox`** always finishes by seeding the **sandbox** accounts (`megiddo` / `test-db-player`, `admin` / `password`).
- **`seed-test-credentials`** patches on demand; default `--target both` (sandbox + mirror `admin`); use `--target sandbox` or `--target mirror` when you only want one side.

See [Seeded logins](#seeded-logins). This is a deliberate PDO credential rewrite, not “whatever landed in the SQL dump.”

### 6. Preflight checks that keep DB switching in a non-prod dev environment

Before destructive or data-plane work, `DeploymentTier` classifies the host. Data commands (including `use dev`, `apply`, `extract`, `seed-test-credentials`, `deploy-sandbox`) **refuse** when the tier is not local. Classification expects:

- Sandbox (and mirror) ports reachable as wired
- Config hostname not a known production host
- A local environment signal (`ENVIRONMENT=DEV`, `APP_STAGE=DEV`, or local/docker DB hostname)

Additional validate/apply gates: wiring locks (sandbox must not be `3306` / mirror port), mutually exclusive prod vs test canaries, kingdom fingerprints, and a real-name blocklist so a wrong database fails closed instead of quietly loading into prod-shaped data.

```bash
bin/ork-db status    # shows tier + whether data commands are enabled
```
