# Test Database Tool — Post-Implementation Tasks

Consolidated follow-up after **TD-6** and **TD-7** (branch `megiddo/td-6-7`). Use this as the single backlog for maintainer review, TD-8/TD-9, and PHPUnit sign-off.

**Related:** [09-milestone-checklist.md](./09-milestone-checklist.md) · [07-implementation-plan.md](./07-implementation-plan.md)

---

## 1. Why main-suite failures are not a “green field” bug

The sandbox **is** green field by design: isolated volume, fake kingdoms (9001–9005), synthetic parks/players, no production rows. That part works — `bootstrap` + `apply` load coherent fake data and post-apply fingerprints pass.

The PHPUnit main suite fails for a different reason: **schema and test assumptions were built against the mirror, but TD-7 pointed tests at the sandbox before TD-8 made the sandbox schema match the mirror.**

### Two databases, two schema histories

| | Mirror (`19306` / `ork`) | Sandbox (`19307` / `ork_test`) |
|--|--------------------------|--------------------------------|
| **Role** | Dev copy of real data; extract source | Green-field test target |
| **Schema source today** | `ork.sql` + **~80 `db-migrations/` files** applied over time on the workstation | `ork.sql` + **`templates/schema/supplements.sql` only** (~20 lines) |
| **Example gap** | `ork_park.has_banner` (from `2026-05-17-add-entity-banners.sql`) | Column **missing** — not in `ork.sql`, not in `supplements.sql` |
| **PHPUnit before TD-7** | `config.test.php` → `19306` / `ork` | Unused for tests |
| **PHPUnit after TD-7** | Still used for `extract` | `config.test.php` → `19307` / `ork_test` |

So “green field” describes **data**, not **DDL completeness**. The sandbox is a fresh database with **incomplete schema**, not a fresh database with **current application schema**.

### What the design already says (but TD-8 has not shipped)

[01-architecture.md](./01-architecture.md) §1:

> Identical schema to production (via `ork.sql` + filtered `db-migrations/`)

[06-migration-classification.md](./06-migration-classification.md) documents which migration files are class `S` (schema, include) vs `PB` (production backfill, exclude). **`Render.php` does not apply that manifest yet** — TD-8 deliverable.

Current render path (`Render.php`):

```
ork.sql  →  supplements.sql (manual partial patch)  →  fake data templates
```

Missing step (planned TD-8):

```
ork.sql  →  classified schema migrations (S)  →  templates / extracts
```

`supplements.sql` was a stopgap so extract/render could run (award columns, pronoun table). It was never meant to replace the full migration classifier.

### Why tests break specifically

Failures fall into **two buckets**:

#### A. Schema drift (TD-8) — most errors

Application code and test fixtures assume columns/tables that exist on a **fully migrated** dev DB:

| Symptom | Cause |
|---------|--------|
| `Unknown column 'has_banner'` | Entity banner migrations not in sandbox DDL |
| Missing `ork_attendance_link`, `ork_selfreg_link`, … | Table-creating migrations not applied to sandbox |
| Startup warnings during PHPUnit bootstrap | Yapo probes tables that do not exist on partial schema |

These are **not** fixed by re-running `apply` — `apply` replays the same incomplete schema every time.

#### B. Data model mismatch (TD-9 + fixtures) — remaining failures

Even with perfect schema parity, many integration tests were written against **mirror seed data**:

| Assumption | Sandbox reality |
|------------|-----------------|
| Real kingdom/park IDs from dev import | Fake IDs 9001–9005 only |
| Historical attendance / reports with volume | Generated 3-year window, different shape |
| Specific dev accounts and kingdom names | `admin` / Ken Walker / Avery Krouse seeded, but not Northern Lights–style prod rows |

[06-test-framework.md](../refactor/06-test-framework.md) still says integration tests “use existing dev seed data; no isolated fixtures yet.” TD-7 changed **which DB** tests hit without finishing **fixture isolation** for that DB.

### Sequencing summary

```
TD-7 shipped:  PHPUnit → ork_test          (connection routing)
TD-8 pending:  ork_test DDL → mirror DDL   (schema parity)
TD-9 pending:  tests → sandbox fixtures    (data expectations)
```

Main-suite red status is an **expected gap between TD-7 and TD-8/TD-9**, not evidence that the green-field approach is wrong.

---

## 2. Immediate verification (TD-6 / TD-7 sign-off)

These should pass on a local workstation with Docker up:

```bash
docker compose -f docker-compose.php8.yml up -d

# ork-db tool suite (dedicated config — not main phpunit.xml.dist)
php vendor/bin/phpunit -c phpunit.ork-db.xml.dist

# Profile switching
bin/ork-db use dev    # .ork3-db.local → ORK3_DB_PROFILE=dev, restarts ork3app
bin/ork-db use prod   # back to mirror

# Idempotent sandbox load
bin/ork-db bootstrap --yes
bin/ork-db validate --mode post-apply
```

**Ork-db suite:** 72 tests, ~90% line coverage on `tools/ork-db/` (excludes `cli.php`, `Init.php` from coverage config).

**Main suite:** `sh bin/run-unit-tests.sh` — expect failures until §3 tasks complete.

---

## 3. Task backlog (priority order)

### P0 — TD-8: Schema parity (unblocks most main-suite errors)

| Task | Deliverable | Acceptance |
|------|-------------|------------|
| **8.1** Migration manifest | `tools/ork-db/manifests/migration-classification.json5` | Every `db-migrations/*` file classified (`S`, `RC`, `PB`, `ES`, `RB`, `PC`) per [06-migration-classification.md](./06-migration-classification.md) |
| **8.2** Render integration | `Render.php` applies class-`S` migrations after `ork.sql` | Retire or shrink `supplements.sql` once migrations cover it |
| **8.3** `schema-diff` | `bin/ork-db schema-diff` | Reports DDL differences mirror vs sandbox; exit 0 when parity |
| **8.4** `drift-check --strict` | CLI + CI hook | Fails build on schema fingerprint mismatch, catalog hash drift, unclassified migrations |
| **8.5** Re-run main suite | After `bootstrap --yes` | Schema-related errors (`Unknown column`, missing table) eliminated |

### P1 — TD-9: Test suite alignment

| Task | Deliverable | Acceptance |
|------|-------------|------------|
| **9.1** Golden render test | PHPUnit compares deterministic `render --deterministic` output | Byte-stable `sandbox.sql` hash |
| **9.2** Tier refusal tests | Production signals → extract/apply/use dev refuse | No network; mocked `DeploymentTier` |
| **9.3** Integration round-trip | Extract → render → apply on local tier | Already partially covered; extend for bootstrap |
| **9.4** Fixture strategy | Update [06-test-framework.md](../refactor/06-test-framework.md) | Document sandbox-first fixtures; stop assuming mirror kingdom IDs |
| **9.5** Fix or skip data-dependent tests | Banner, KingdomProfile, Weather, etc. | Each test either uses sandbox fixtures or documents mirror-only skip |

### P1b — TD-10: `deploy-sandbox` (daily dev entry)

| Task | Deliverable | Acceptance |
|------|-------------|------------|
| **10.1** | `DeploySandbox.php` + CLI wiring | `bin/ork-db deploy-sandbox` runs full pipeline |
| **10.2** | State detection | Uninitialized → `init`; no fingerprints → bootstrap; else skip |
| **10.3** | Validate-and-halt | Each failure prints remediation (docker up, prod canary migration, etc.) |
| **10.4** | Auto `use dev` | App container points at sandbox after success |
| **10.5** | Daily refresh gate | `extract` → `render` → `apply` only when `.last-render.json` anchor_date &lt; today |
| **10.6** | `.last-render.json` | Written by `render`/`apply`; gitignored |
| **10.7** | Unit + integration tests | Mock state paths; live test on Docker |

**Depends on:** TD-8 recommended before sign-off (validate reflects real schema).

Spec: [07-implementation-plan.md](./07-implementation-plan.md) §9 · [10-cli-reference.md](./10-cli-reference.md) § deploy-sandbox.

### P1c — TD-11: Heraldry, assets, and ID namespace

| Task | Deliverable | Acceptance |
|------|-------------|------------|
| **11a** | ID migration | Kingdoms `100001`–`100005`; parks `@ 1M`; fake mundanes `@ 100M`; validate + golden hash |
| **11b** | `generate-assets` | SVG → PNG; kingdom/park shields + phoenix player placeholder; render flags |
| **11c** | `deploy-assets` | Copy to `assets/`; hook `deploy-sandbox`; post-apply file validation |
| **11d** | Visual sign-off | Kingdom/park/player pages show real art, not defaults |

Spec: [12-heraldry-and-assets.md](./12-heraldry-and-assets.md).

### P2 — Documentation and ops

| Task | Notes |
|------|-------|
| **Update 06-test-framework.md** | Prerequisites: sandbox on `19307`, run `deploy-sandbox` before sign-off; ork-db tests via `phpunit.ork-db.xml.dist` |
| **`.ork3-db.local.example`** | Optional committed template `ORK3_DB_PROFILE=prod` for first-time Docker users |
| **Maintainer review (TD-0)** | Confirm port 19307, kingdom IDs `100001`–`100005` (TD-11), Ken/Avery mundane IDs |

### P3 — Project sign-off (checklist §Project)

| Item | Status |
|------|--------|
| `apply` impossible on production host | Tier guard implemented (TD-2) — add explicit sign-off test in TD-9 |
| `apply` impossible against mirror | Port/DB/canary locks (TD-2) — sign-off |
| Operator cannot mistype database on data commands | No CLI args (TD-0–5) — sign-off |
| Full PHPUnit suite green after `deploy-sandbox` | **Blocked on P0 + P1** |

---

## 4. Main-suite failure snapshot (2026-07-07, post TD-7)

Recorded after `config.test.php` → `19307` / `ork_test` and `bin/ork-db bootstrap --yes`:

| Metric | Value |
|--------|-------|
| Tests run | 204 (OrkDb tests excluded from `phpunit.xml.dist`; use `phpunit.ork-db.xml.dist`) |
| Errors | ~120 |
| Failures | ~6 |
| Skipped | 2 |

**Representative errors (schema — TD-8):**

- `Unknown column 'has_banner'` — `AdminDashboardFixture`, banner tests
- Missing tables at startup — `ork_attendance_link`, `ork_selfreg_link`, …

**Representative failures (data — TD-9):**

- `KingdomProfileTest` — empty report averages (no mirror-scale history)
- `WeatherServiceTest` — park weather archive null (sandbox parks lack weather seed)
- `BannerTest` — DB update failures downstream of schema/fixture issues

Re-run this snapshot after TD-8 and attach results to the checklist.

---

## 5. Recommended workflow for developers (until TD-8 ships)

**Option A — ork-db work only**

```bash
php vendor/bin/phpunit -c phpunit.ork-db.xml.dist
```

**Option B — main Megiddo suite (temporary)**

Keep mirror available and override test DB until schema parity lands:

```bash
ORK3_TEST_DB_PORT=19306 ORK3_TEST_DB_HOST=127.0.0.1 \
  # edit config.test.php DB_DATABASE back to ork — NOT recommended long-term
```

**Option C — target state (after TD-8 + TD-9 + TD-10 + TD-11)**

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox   # includes deploy-assets when TD-11 ships
sh bin/run-unit-tests.sh
```

---

## 6. Branch / commit map (TD-1–TD-7)

| Milestone | Branch (typical) | Key artifacts |
|-----------|------------------|---------------|
| TD-1–2 | `megiddo/td-*` | `docker-compose.php8.yml`, safety, validate |
| TD-3–5 | `megiddo/td-4-5` | extract, render, apply |
| TD-6–7 | `megiddo/td-6-7` | `Use.php`, `Bootstrap.php`, `config.test.php`, profile switching |
| TD-8–10 | `megiddo/td-*` | migration manifest, drift-check, deploy-sandbox |
| TD-11 | `megiddo/td-11` | ID namespace, heraldry, generate-assets, deploy-assets |

---

## 7. Open questions for maintainer review

1. **`supplements.sql` retirement** — delete once migration classifier covers the same DDL?
2. **CI split** — run `phpunit.ork-db.xml.dist` on every build now; gate main suite on TD-8?
3. **Mirror migrations on fresh clone** — document one-time `db-migrations/` apply to mirror before first `extract`?
4. **TD-7 checklist item** — “Full suite passes after apply”: mark done only after TD-8+9, or split into schema-green vs data-green criteria?
5. **`deploy-sandbox` vs `bootstrap`** — keep both? (`bootstrap` = sandbox-only; `deploy-sandbox` = full dev entry)
6. **TD-11 real player placeholders** — phoenix for Ken/Avery/megiddo in sandbox, or missing files OK?
7. **TD-11 event IDs** — migrate to `2_000_000` block or leave at `80000`?
