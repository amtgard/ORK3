# Fuzzy Validator — User Guide

**Audience:** Megiddo R-* developers, maintainers running record/validate/sign-off  
**Entry point:** `bin/fuzzy-validator` from repo root  
**Deep reference:** [10-cli-reference.md](./10-cli-reference.md) · [04-operating-guide.md](./04-operating-guide.md)

---

## What this tool does

The fuzzy validator checks that a refactor did not unintentionally change front-end **output**:

| Layer | What it compares | Pass means |
|-------|------------------|------------|
| **Assets** | CSS/JS byte content | Identical to baseline (strict) |
| **DOM** | Canonical HTML tree | Same structure outside learned fuzz nodes |
| **Pixels** | Full-page screenshot | Same appearance outside learned fuzz boxes |

It produces **exit code pass/fail** (for CI) and an **HTML report** (for humans) with green fuzz allowances and red regressions.

---

## One-time setup

```bash
# Repo root
npm ci
npx playwright install chromium
pip install -r tools/fuzzy-validator/python/requirements.txt

docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox              # required for test profile

export ORK3_E2E_BASE_URL=http://localhost:19080/orkui/
export ORK3_E2E_USERNAME=your-mirror-user      # mirror profile
export ORK3_E2E_PASSWORD=your-mirror-password
export ORK3_E2E_TEST_PASSWORD=test-db-player   # test profile (optional)
```

### Restore baselines (required after clone)

Heavy baseline bytes are **not** in git. Restore from the committed bootstrap zip:

```bash
bin/fuzzy-validator setpoint restore
```

If baselines are missing, `validate` exits `2` and prints a restore hint.

---

## Core workflows

### 1. Validate a refactor (most common)

Run on your **R-* branch** after restoring baselines:

```bash
bin/fuzzy-validator validate --pages home-anonymous,player-profile --phase all
echo $?   # 0 = pass on both test + mirror profiles
open tools/fuzzy-validator/reports/run-*/index.html
```

**Default behavior:**

- Runs **test** profile (strict, score ≥ 1.0) then **mirror** (lenient, visual ≥ 0.98).
- Phases: assets → dom → pixels (`--phase all`).
- Writes report under `tools/fuzzy-validator/reports/run-{timestamp}/`.

**Pilot pages** with bootstrap baselines: `home-anonymous`, `home-authenticated`, `player-profile`.

### 2. Record new baselines (maintainer)

Run on a **known-good commit** when UI intentionally changes or adding a new page:

```bash
bin/fuzzy-validator record --page new-page-id --phase all
```

Review calibration overlays in `tools/fuzzy-validator/reports/` before promoting.

For full-registry promotion after merge to `main`, use **setpoint capture** (§4).

### 3. Register a new page

1. Add entry to `tools/fuzzy-validator/manifests/pages.json5` (unique `id`, `url`, `auth`, waits).
2. `bin/fuzzy-validator record --page new-page-id --phase all`
3. Review overlays and fuzz manifests.
4. Promote via setpoint workflow (§4) or commit manifests if baselines already in zip.

See [03-manifest-schema.md](./03-manifest-schema.md) for field definitions.

---

## Reading results

### Exit codes

| Code | Meaning |
|------|---------|
| `0` | All pages and layers passed |
| `1` | At least one regression (expected on intentional break) |
| `2` | Harness error (missing baseline, bad manifest, capture failure) |

Stdout includes a line like:

```text
FUZZ_GATE run=20260707T120000Z pass=3 fail=0 exit=0
```

### HTML report

Open `tools/fuzzy-validator/reports/run-{id}/index.html`:

| UI element | Meaning |
|------------|---------|
| **Green boxes** on screenshot | Fuzz allowance (ignored diff) |
| **Red boxes** | Regression that caused failure |
| **Assets section** | Unified diff for changed CSS/JS |
| **DOM section** | Paths/snippets outside fuzz nodes |
| **Scores** | Stability in `[0.0, 1.0]` per layer |

Full report spec: [06-gate-output-and-report.md](./06-gate-output-and-report.md).

### On failure

| Situation | Action |
|-----------|--------|
| Bug in refactor | Fix code; re-run `validate` |
| Accidental CSS/JS touch | Revert static file change |
| Intentional template change | Re-record on integration branch; publish new setpoint |
| New dynamic widget | Re-record to learn new fuzz zones/nodes |
| Threshold too strict (pixels only) | `--visual-min-score 0.98` — do not lower asset threshold |

---

## Database profiles

Default: **`test,mirror`** on both `record` and `validate`.

| Profile | Database | Strictness | Auth |
|---------|----------|------------|------|
| **test** | Sandbox `ork_test` | All layers ≥ 1.0 | `megiddo` / `test-db-player` |
| **mirror** | Local `ork` | Visual ≥ 0.98, dom ≥ 0.99 | `ORK3_E2E_*` env |

```bash
# Strict sandbox only
bin/fuzzy-validator validate --profile test --page player-profile

# Deploy sandbox before test pass
bin/fuzzy-validator validate --ensure-sandbox --profile test --all
```

Details: [11-dual-database-profiles.md](./11-dual-database-profiles.md).

---

## Setpoint promotion (baseline bundles)

After FU-16, **do not commit** loose PNG/asset files. Maintainers capture a versioned zip; git tracks `setpoint.json` + manifests.

### Maintainer workflow (on `main`, docker up)

```bash
# 1. Capture all pages + create zip
bin/fuzzy-validator setpoint capture

# 2. Upload setpoints/out/{date}-{sha}-{hash}.zip to Google Drive
#    Folder: "ORK3 Fuzzy Setpoints" (world-readable, maintainer write)

# 3. Publish pointer
bin/fuzzy-validator setpoint publish --bundle tools/fuzzy-validator/setpoints/out/….zip

# 4. Commit pointer + manifests only
git add tools/fuzzy-validator/setpoint.json tools/fuzzy-validator/manifests/
git commit -m "Promote fuzzy setpoint …"
```

### Developer restore

```bash
bin/fuzzy-validator setpoint restore
# or after manual Drive download:
bin/fuzzy-validator setpoint restore --bundle ~/Downloads/20260708T….zip
```

When to capture: [04-operating-guide.md §5](./04-operating-guide.md).

---

## Command cheat sheet

| Task | Command |
|------|---------|
| Validate one page | `bin/fuzzy-validator validate --page player-profile` |
| Validate all registry pages | `bin/fuzzy-validator validate --all --phase all` |
| Record one page | `bin/fuzzy-validator record --page player-profile --phase all` |
| Pixel-only gate | `bin/fuzzy-validator validate --page X --phase visual` |
| URL list file | `bin/fuzzy-validator validate --urls urls.txt` |
| Dry run (print targets) | `bin/fuzzy-validator validate --all --dry-run` |
| Restore baselines | `bin/fuzzy-validator setpoint restore` |
| npm alias | `npm run fuzz:validate -- --page home-anonymous` |

Full flag list: [10-cli-reference.md](./10-cli-reference.md).

---

## Evidence reports (review without docker)

Committed integration proof shows fuzzy pass/fail behavior on real page snapshots.

**Open one page:**

```bash
open tools/fuzzy-validator/evidence/reports/index.html
```

The hub links to pixel, DOM, asset, and unified layer dashboards plus each pass/fail scenario.

| Report | Path (from hub) |
|--------|-----------------|
| **Hub** | `evidence/reports/index.html` |
| Pixel fuzz | `pixel-proof/` |
| DOM fuzz | `dom-proof/` |
| Asset hard gate | `assets-proof/` |
| Unified all layers | `unified-proof/` |

Reviewer checklist: [evidence/README.md](../../../tools/fuzzy-validator/evidence/README.md).

---

## CI usage

**Required on PR:** Python unit tests (≥ 90% coverage) — no docker.

**Optional:** Linux pixel gate on pilot pages — workflow restores setpoint then validates. Set repo secrets `ORK3_E2E_USERNAME` / `ORK3_E2E_PASSWORD` for auth pages.

Download failed gate artifacts from GitHub Actions → **Artifacts** → `fuzzy-validator-reports-{runId}`.

Details: [04-operating-guide.md §10](./04-operating-guide.md).

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| `missing baselines` / exit 2 | `bin/fuzzy-validator setpoint restore` |
| Record aborts (assets unstable) | Fix flaky scripts; stub network |
| Empty fuzz manifest | Page fully deterministic — OK |
| Dimension mismatch | Content height changed — update baseline |
| macOS local fail, CI pass | Use CI as canonical; local gate optional |

More: [04-operating-guide.md §7](./04-operating-guide.md).

---

## Related documentation

| Doc | Use when |
|-----|----------|
| [12-design-and-implementation.md](./12-design-and-implementation.md) | Understanding design decisions |
| [DEVELOPER-GUIDE.md](./DEVELOPER-GUIDE.md) | Writing tests or changing the tool |
| [03-manifest-schema.md](./03-manifest-schema.md) | Editing fuzz JSON or page registry |
