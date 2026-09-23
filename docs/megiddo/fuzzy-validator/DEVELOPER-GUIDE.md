# Fuzzy Validator — Developer Guide

**Audience:** Contributors changing `tools/fuzzy-validator/`  
**Design context:** [12-design-and-implementation.md](./12-design-and-implementation.md)  
**User-facing commands:** [USER-GUIDE.md](./USER-GUIDE.md)

---

## 1. Development environment

From repo root:

```bash
npm ci
npx playwright install chromium

pip install -r tools/fuzzy-validator/python/requirements.txt \
            -r tools/fuzzy-validator/python/requirements-dev.txt
```

Optional for live capture work:

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox
export ORK3_E2E_BASE_URL=http://localhost:19080/orkui/
export ORK3_E2E_USERNAME=… ORK3_E2E_PASSWORD=…
bin/fuzzy-validator setpoint restore
```

---

## 2. Repository layout (where to edit)

```
tools/fuzzy-validator/
  bin/fuzzy-validator           # bash dispatcher — add new top-level commands here
  python/
    fuzzy_validator/cli.py      # record | validate | overlay | setpoint orchestration
    fuzzy_validator/overlay_cli.py
    discover_*.py, gate*.py     # CLI scripts invoked by cli.py or gate.sh
    lib/                        # unit-testable library code (prefer adding logic here)
      drift_overlay.py          # v2 overlay schema / merge / conflicts
      drift_classify.py         # expected vs unexpected inventory
      mirror_freshness.py       # 7-day mirror probe
      reproduce.py              # mechanical reproduce.md
      annotations.py            # display-only agent notes
    tests/
      conftest.py               # shared fixtures
      unit/                     # fast synthetic tests
      integration/              # multi-module wiring on fixtures
  overlays/                     # v2 drift overlays (natural / intentional / putative)
  playwright/
    capture.spec.ts             # capture loop
    lib/                        # stabilization, auth, DOM/asset capture
  manifests/                    # pages.json5, profiles, fuzz JSON (committed)
  evidence/                     # integration proof + run-evidence-suite.sh
```

**Convention:** Put reusable logic in `python/lib/`. Keep top-level `gate*.py` / `discover_*.py` as thin CLIs. Wire new user commands through `fuzzy_validator/cli.py` and `bin/fuzzy-validator`. Overlays never mutate setpoint zip contents.

---

## 3. Running unit tests

Unit tests validate the **tool algorithms** — not ORK3 pages. No docker or network required.

### Full sign-off command (matches CI)

```bash
pytest tools/fuzzy-validator/python/tests/ \
  --cov=tools/fuzzy-validator/python/lib \
  --cov=tools/fuzzy-validator/python \
  --cov-report=term-missing \
  --cov-fail-under=95
```

Or from the python directory:

```bash
cd tools/fuzzy-validator/python
pytest tests/ \
  --cov=lib \
  --cov=. \
  --cov-report=term-missing \
  --cov-fail-under=95
```

**Requirement:** ≥ **95% line coverage** on production sources under `tools/fuzzy-validator/python/` (excluding `tests/`). Run the pytest command above before merging tool changes.

### During development

```bash
# Single file
pytest tools/fuzzy-validator/python/tests/unit/test_diff_regions.py -v

# Single test
pytest tools/fuzzy-validator/python/tests/unit/test_setpoint.py::test_create_bundle_and_publish -v

# Show print output
pytest tools/fuzzy-validator/python/tests/unit/test_gate_run.py -v -s

# Coverage for one module
pytest tools/fuzzy-validator/python/tests/unit/test_tree_diff.py \
  --cov=tools/fuzzy-validator/python/lib/tree_diff \
  --cov-report=term-missing
```

### What is covered

| Path | In coverage scope |
|------|-------------------|
| `python/lib/*.py` | Yes — primary target |
| `python/gate*.py`, `discover*.py`, `calibrate_assets.py` | Yes |
| `python/fuzzy_validator/*.py` | Yes |
| `python/tests/` | No |

### Fixture strategy (`tests/conftest.py`)

| Fixture type | Purpose |
|--------------|---------|
| Synthetic 32×32 PNGs | Pixel diff / gate without real screenshots |
| Inline HTML strings | DOM canonicalization and tree diff |
| CSS/JS pairs differing by 1 byte | Asset hard gate |
| Sample manifests | Fuzz JSON, defaults, profiles |
| `tmp_path` tool roots | CLI and setpoint tests |

**Rule:** Unit tests must not require docker, network, or committed baselines under `baselines/`.

### Module → test mapping

| Module | Test file |
|--------|-----------|
| `lib/diff_regions.py` | `test_diff_regions.py` |
| `lib/scoring.py` | `test_scoring.py` |
| `lib/tree_diff.py`, `lib/canonical_dom.py` | `test_tree_diff.py`, `test_canonical_dom.py` |
| `lib/asset_manifest.py`, `lib/asset_store.py` | `test_asset_manifest.py`, `test_asset_store.py` |
| `gate.py` | `test_gate.py` |
| `gate_assets.py` | `test_gate_assets.py` |
| `gate_dom.py` | `test_gate_dom.py` |
| `gate_run.py` | `test_gate_run.py`, `integration/test_gate_run_fixtures.py` |
| `lib/report_html.py` | `test_report_html.py` |
| `lib/setpoint.py` | `test_setpoint.py` |
| `fuzzy_validator/cli.py` | `test_cli.py`, `test_cli_record.py`, `test_cli_profiles.py` |
| `lib/page_registry.py` | `test_page_registry.py` |
| `lib/profiles.py` | `test_profiles.py` |

When adding a module, add tests in the **same PR** — CI will fail below 90%.

---

## 4. Integration tests

Two integration tiers exist; do not confuse them.

### 4.1 Python integration fixtures (fast, CI-adjacent)

**Location:** `python/tests/integration/test_gate_run_fixtures.py`

Exercises `gate_run.py` end-to-end on synthetic page fixtures in `tmp_path` — unified layer wiring, exit codes, `summary.json`. Runs as part of normal pytest; **no docker**.

```bash
pytest tools/fuzzy-validator/python/tests/integration/ -v
```

### 4.2 Evidence suite (committed proof, docker optional)

**Location:** `tools/fuzzy-validator/evidence/`

Proves the full pipeline on **real** stabilized captures with controlled mutations:

| Step | Script / command |
|------|------------------|
| Virgin baselines | `bin/fuzzy-validator record --tool-root tools/fuzzy-validator/evidence …` |
| Full suite | `tools/fuzzy-validator/evidence/scripts/run-evidence-suite.sh` |
| Mutation recipes | `evidence/mutations.md`, `evidence/scripts/evidence_mutations.py` |

The suite:

1. Runs **discover** on multi-grab calibrations (pixel + DOM).
2. Runs **validate --skip-capture** against staged candidates in `evidence/calibrations/`.
3. Asserts exit codes (in-zone pass, out-of-zone fail).
4. Writes HTML under `evidence/reports/` (committed for reviewer access).

```bash
# Re-run locally (docker + sandbox required)
export ORK3_E2E_BASE_URL=http://localhost:19080/orkui/
bin/ork-db deploy-sandbox
tools/fuzzy-validator/evidence/scripts/run-evidence-suite.sh
echo $?   # expect 0
```

**Not required for every Python-only change.** Re-run locally when touching capture/report proof paths; see [evidence/README.md](../../../tools/fuzzy-validator/evidence/README.md).

After changing gate/report/discover logic, re-run the evidence suite and commit updated reports if behavior intentionally changed.

### 4.3 Live capture smoke (manual)

```bash
docker compose -f docker-compose.php8.yml up -d
bin/fuzzy-validator setpoint restore
bin/fuzzy-validator validate --page home-anonymous --phase visual
```

Use when touching Playwright stabilization or capture spec — not required for every Python-only change.

### 4.4 Playwright unit tests (TypeScript)

Optional tests for pure TS helpers under `playwright/` (if present). Not part of the 90% Python gate. Run via project test runner if added.

---

## 5. Local quality gates

This repo does not use GitHub Actions for the fuzzy validator. Before merging tool changes, run:

```bash
pytest tools/fuzzy-validator/python/tests/ --cov-fail-under=95
```

Optional full evidence suite (docker): see [evidence/README.md](../../../tools/fuzzy-validator/evidence/README.md).

---

## 6. Extending the tool

### Add a new page to the registry

1. Edit `manifests/pages.json5` — follow [03-manifest-schema.md](./reference/03-manifest-schema.md).
2. Add/extend `test_page_registry.py` if validation rules change.
3. Record on stable commit; publish setpoint (maintainer).

### Add a new gate layer or phase

1. Implement gate module in `python/` or `python/lib/`.
2. Wire into `gate_run.py` phase switch.
3. Add scoring in `lib/scoring.py` if new score dimension.
4. Extend `lib/report_html.py` for HTML section.
5. Unit tests for pass/fail fixtures; update `integration/test_gate_run_fixtures.py`.
6. Update [06-gate-output-and-report.md](./reference/06-gate-output-and-report.md) and CLI help.

### Add a CLI command

1. Subparser in `fuzzy_validator/cli.py`.
2. Dispatch case in `tools/fuzzy-validator/bin/fuzzy-validator`.
3. Tests in `tests/unit/test_cli*.py`.
4. Document in [10-cli-reference.md](./reference/10-cli-reference.md) and [USER-GUIDE.md](./USER-GUIDE.md).

### Use alternate tool root (evidence pattern)

Pass `--tool-root tools/fuzzy-validator/evidence` to isolate baselines/manifests under `evidence/`. Resolved by `lib/tool_paths.py`.

### `--skip-capture` (evidence only)

`validate --skip-capture` skips Playwright and uses existing `calibrations/{pageId}/candidate.*` files. Do not use in production sign-off — only for offline evidence replay.

---

## 7. Debugging

| Problem | Approach |
|---------|----------|
| Gate fails unexpectedly | Open `reports/run-*/index.html`; check `summary.json` |
| Fuzz discovery empty/wrong | Inspect `reports/{profile}-{page}-calibration-overlay.png` |
| DOM fuzz debug | `reports/{profile}-{page}-dom-fuzz.txt` after record |
| CLI subprocess failures | Re-run with `--dry-run`; check Playwright stderr |
| Coverage gap | `pytest … --cov-report=term-missing`; add tests for listed lines |

Ephemeral debug dirs (gitignored):

- `tools/fuzzy-validator/calibrations/`
- `tools/fuzzy-validator/reports/`

---

## 8. Code style and PR expectations

- Match existing patterns in neighboring modules (minimal scope, no drive-by refactors).
- Prefer `lib/` for testable pure functions.
- New behavior requires unit tests; integration behavior may update evidence suite.
- Doc updates for user-visible changes: `USER-GUIDE.md`, `10-cli-reference.md`, or `12-design-and-implementation.md` as appropriate.
- Do not commit secrets; auth via env and `profiles.json5`.

---

## 9. Quick reference

```bash
# Unit tests (required)
pytest tools/fuzzy-validator/python/tests/ --cov-fail-under=95

# Integration fixtures
pytest tools/fuzzy-validator/python/tests/integration/ -v

# Evidence suite (docker)
tools/fuzzy-validator/evidence/scripts/run-evidence-suite.sh

# Live smoke
bin/fuzzy-validator validate --page home-anonymous --phase visual

# Help
bin/fuzzy-validator --help
bin/fuzzy-validator setpoint restore --help
```

---

## 10. Related docs

| Doc | Content |
|-----|---------|
| [09-test-framework.md](./archive/09-test-framework.md) | Original test plan (superseded in detail by this guide) |
| [12-design-and-implementation.md](./12-design-and-implementation.md) | Architecture and module map |
| [evidence/README.md](../../../tools/fuzzy-validator/evidence/README.md) | Evidence reviewer checklist |
| [08-milestone-checklist.md](./archive/08-milestone-checklist.md) | FU-* completion history |
