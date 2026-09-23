# Idiom Enforcement — I-VALIDATE Close-out Audit

**Status:** `ok`
**Hop:** I-VALIDATE (final idiom + regression audit — no behavior changes)
**Timestamp (UTC):** 2026-07-13T21:23Z
**Branch:** `megiddo/i-validate-idiom-audit`
**Stack base:** `megiddo/i-19d-idiom-residual-lib` @ `5e111edd`
**Commit (audit tree):** `5e111edd` (branch is byte-identical to the I-19d tip — audit only, zero code changes)
**Charter:** [idioms-00-charter.md](./idioms-00-charter.md) · **Worker:** VALIDATE-20 gate definitions ([skills/phase3-gate-fix/workers/VALIDATE-20.md](./skills/phase3-gate-fix/workers/VALIDATE-20.md))

This hop is an audit only. No production code was modified; no charter lint false positives required fixing. All §4 lint commands and VALIDATE-20 gates (V20-A … V20-D) were re-run against the final idiom stack tip.

---

## Charter §4 lint results

### §4.1 Static isolation (`orkui/`)

| Command | Expect | Result |
|---------|--------|--------|
| `rg '\$DB->' orkui/` | no matches | **PASS** (exit 1, zero) |
| `rg 'Ork3::\$Lib' orkui/` | no matches | **PASS** (exit 1, zero) |

### §4.2 Controller idiom drift (`orkui/controller/`)

| Command | Expect | Result |
|---------|--------|--------|
| `rg '\(new Model_' orkui/controller/` | zero preferred | **PASS** (exit 1, zero) |
| `rg 'new Model_' orkui/controller/` | zero preferred | **PASS** (exit 1, zero) |
| `rg '\(new (Player\|EventPlanning\|KingdomProfile\|Heraldry\|Weather\|Dangeraudit\|Report\|SearchService)\(\)' orkui/controller/` | zero preferred | **PASS with documented exception** — 7 `(new Dangeraudit())->audit(...)` sites only |

**Justified exception — inline `(new Dangeraudit())->audit(...)` (7 sites):**

| File | Count |
|------|-------|
| `controller/controller.EventAjax.php` | 3 |
| `controller/controller.KingdomAjax.php` | 1 |
| `controller/controller.ParkAjax.php` | 1 |
| `controller/controller.AdminAjax.php` | 1 |
| `controller/controller.Unit.php` | 1 |

Per charter §1.3 and §2, inline `(new Dangeraudit())->audit(...)` beside `add_auth` is the **canonical cross-file idiom**: every AJAX peer that grants authorization uses it, and `Model_Authorization` exposes **no** audit wrapper. Rerouting through a model would change audit payload/timing (charter-forbidden). These are the file-local dominant idiom, vetted through I-19a … I-19d, and are **not** drift. No fix applied.

### §4.3 Model layer sanity (`orkui/model/`)

| Command | Expect | Result |
|---------|--------|--------|
| `rg 'Ork3::\$Lib' orkui/model/` | no matches | **PASS** (exit 1, zero) |
| `rg '\$DB->' orkui/model/` | no matches | **PASS** (exit 1, zero) |

### §4.4 PHPUnit

`sh bin/run-unit-tests.sh` → **PASS** — 230 tests OK, 2 skipped, exit 0 (ork-db drift-check PASS; 3 PHPUnit deprecations, non-fatal).

---

## VALIDATE-20 gate table

| Gate | Description | Result |
|------|-------------|--------|
| **V20-A** | Static audit (frontend isolation) | **PASS** |
| **V20-B** | PHPUnit full suite | **PASS** |
| **V20-C** | Fuzzy `validate --all --phase all` | **PASS*** (see note) |
| **V20-D** | Playwright mirror + sandbox heraldry | **PASS** |

### V20-A — Static audit

| Check | Command | Result |
|-------|---------|--------|
| DB access | `rg '\$DB->' orkui/` | **PASS** — zero |
| Lib bypass | `rg 'Ork3::\$Lib' orkui/` | **PASS** — zero |
| Direct DML | `rg -i 'INSERT INTO\|UPDATE [a-z_]+ SET\|DELETE FROM' orkui/ --glob '*.php'` | **PASS** — zero |
| ORM/driver (advisory) | `rg -i 'new yapo\|mysqli_\|PDO::' orkui/ --glob '*.php'` | zero hits |

### V20-B — PHPUnit

230 tests OK, 2 skipped, exit 0.

### V20-C — Fuzzy regression (`validate --all --phase all`, 20 pages × test + mirror = 40 rows)

- **39 / 40 rows PASS** (assets 1.00 / dom 1.00 / visual 1.000).
- **1 row FAIL:** `[test] park-auth-sandbox` — this is the **pre-existing sandbox visual-baseline dimension mismatch**, not a regression:
  - Stored `test`-profile visual baseline: **1280 × 961**; candidate render: **1280 × 1976** (page renders taller with sandbox seed data).
  - assets = 1.00, dom = 1.00, visual = 1.000 on the compared region — **no content/behavioral change**.
  - `[mirror] park-auth-sandbox` **PASSES** (assets/dom/visual 1.000).
  - This branch is **byte-identical** to the I-19d tip (audit only), so the mismatch **cannot** have been introduced by any idiom hop. It was first documented in the I-19c hop and predates the idiom queue.
- Report: `tools/fuzzy-validator/reports/run-20260713T211659Z/index.html`

**\*Status rationale:** Per the I-VALIDATE orchestrator directive, this specific pre-existing, non-behavioral sandbox baseline dimension mismatch — the **sole** failure, confirmed pre-existing and with mirror + all behavioral checks green — is treated as pre-existing drift and does not flip status to `failed`. Re-recording the baseline is **not** performed here (fuzzy re-record policy requires an intentional UI change; this hop has none).

### V20-D — Playwright

| Suite | DB profile | Result |
|-------|------------|--------|
| Mirror (`--grep-invert heraldry`) | prod / `admin` | **PASS** — 50 tests; 1 transient login-redirect timeout in `attendance.spec.ts beforeEach` that **passed on isolated re-run** (4/4), all others green |
| Sandbox heraldry (`heraldry.spec.ts`) | dev / `megiddo` | **PASS** — 3/3 |

The single mirror flake was a transient 30s `waitForURL` timeout during login; re-running `attendance.spec.ts` in isolation passed all 4 tests (the previously-failed case in 954 ms). No behavioral regression.

---

## Idiom changes

**None.** I-VALIDATE is an audit-only hop; the branch is byte-identical to the I-19d stack tip. No charter lint false positives required correction. The 7 inline `(new Dangeraudit())->audit(...)` controller sites are the documented canonical audit idiom (charter §1.3 / §2), not drift.

## Blockers

**None.** All charter §4 lint commands pass; all VALIDATE-20 gates (V20-A … V20-D) pass. The sole fuzzy failure is the confirmed pre-existing, non-behavioral `[test] park-auth-sandbox` baseline dimension mismatch (mirror green, all scores 1.000).

## Human next steps (status = ok)

- **P3-4** — manual smoke matrix
- **P3-5** — retrospective
- **P3-6** (optional) — merge

**Recommended follow-up (non-blocking):** re-record the `test`-profile `park-auth-sandbox` visual baseline (1280 × 961 → 1280 × 1976) during a future intentional-UI or setpoint refresh to clear the standing sandbox dimension-mismatch flag.
