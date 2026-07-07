# Fuzzy Validator — Operating Guide

Day-to-day commands for maintainers and Megiddo R-* agents. Assumes **FU-10** for full gate + report; FU-3 for pixel-only.

> **New:** [USER-GUIDE.md](./USER-GUIDE.md) consolidates operator workflows. This guide retains detailed procedures and troubleshooting.

---

## Gate outputs (every run)

| # | Output | Location |
|---|--------|----------|
| 1 | **Pass / Fail** | Exit code; stdout scores + `FUZZ_GATE run=… pass=N fail=M exit=…` |
| 2 | **HTML report** | `tools/fuzzy-validator/reports/run-{id}/index.html` |

Open the report in a browser after a failed CI run (download artifact). See [06-gate-output-and-report.md](./06-gate-output-and-report.md).

**Screenshot legend:** green boxes = fuzz allowances; red boxes = regressions that caused failure.

**Scores:** each layer reports stability in `[0.0, 1.0]`. Default pass threshold is **1.0** for all layers. If pixel gate is flaky, set `--visual-min-score 0.98` — do not lower `assetsMinScore` during refactor testing.

### Phase overview

| Phase | Record | Validate | Thresholds |
|-------|--------|----------|------------|
| Both (default) | `record` → test + mirror | `validate` → test + mirror | test strict; mirror lenient |
| Sandbox only | `record --profile test` | `validate --profile test` | all 1.0 |

## 1. One-time setup

```bash
# Repo root
npm ci
npx playwright install chromium
pip install -r tools/fuzzy-validator/python/requirements.txt

docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox              # stable test dataset (required for test profile)

export ORK3_E2E_BASE_URL=http://localhost:19080/orkui/
export ORK3_E2E_USERNAME=your-mirror-user      # mirror profile
export ORK3_E2E_PASSWORD=your-mirror-password
export ORK3_E2E_TEST_PASSWORD=test-db-player   # test profile (optional override)
```

---

## 2. Register a new page

1. Edit `tools/fuzzy-validator/manifests/pages.json5` — add entry with unique `id`.
2. Run **`record`** (section 3).
3. Review overlay PNG.
4. Commit (Phase 1):
   - `manifests/{pageId}.fuzz.json`
   - `baselines/{pageId}.png`
5. After FU-8+, also commit (Phase 2):
   - `manifests/{pageId}.dom-fuzz.json`
   - `baselines/{pageId}.dom.json`
   - `baselines/{pageId}.assets.json`
   - `baselines/assets/{pageId}/` (raw CSS/JS files)

---

## 3. `record` — baselines + fuzz (stable branch)

Run on a **known-good commit** before the first R-* affecting these pages. Full option list: [10-cli-reference.md](./10-cli-reference.md).

```bash
# Registry page ids
bin/fuzzy-validator record --page home-authenticated
bin/fuzzy-validator record --pages home-anonymous,home-authenticated,player-profile
bin/fuzzy-validator record --all

# Raw URLs
bin/fuzzy-validator record 'http://localhost:19080/orkui/index.php?Route=Player/profile'
bin/fuzzy-validator record --urls path/to/urls.txt

# Full refactor baselines (Phase 2)
bin/fuzzy-validator record --pages player-profile --phase all
```

What `record` does:

1. Playwright ×N (default 5) → PNG + DOM HTML + asset bytes
2. **Assets:** assert identical sha256 across N runs (abort if not)
3. **DOM:** discover fuzz tree nodes → `manifests/{pageId}.dom-fuzz.json`
4. **Pixels:** discover bbox fuzz → `manifests/{pageId}.fuzz.json` + review overlay
5. Write `baselines/` artifacts for commit

### Phase 1 pixel review checklist

- [ ] Red boxes cover timestamps, session labels, ads, live widgets
- [ ] Red boxes do **not** cover primary content (forms, tables, nav structure)
- [ ] Re-run once; box count and positions roughly stable
- [ ] Copy chosen run to baseline: `cp calibrations/{pageId}/run-003.png baselines/{pageId}.png`

### Phase 2 DOM review checklist

- [ ] Read `reports/{pageId}-dom-fuzz.txt`
- [ ] Fuzz nodes cover tokens, timestamps, live widgets — not main forms/nav
- [ ] Prefer `attributes` / `text` over whole `subtree` when possible
- [ ] Commit `baselines/{pageId}.dom.json` from run-003

### Phase 2 asset review checklist

- [ ] Calibration did not abort (5 identical asset manifests)
- [ ] Baseline bytes committed under `baselines/assets/{pageId}/`
- [ ] No unexpected third-party scripts in manifest

Add manual zones if auto missed a corner:

```json
"manualZones": [
  { "x": 0, "y": 0, "width": 100, "height": 30, "source": "manual", "label": "clock" }
]
```

### `--all` calibration runtime

`bin/fuzzy-validator record --all` and `tools/fuzzy-validator/bin/calibrate.sh --all` iterate every non-`skip` entry in `manifests/pages.json5`. Each page runs five stabilized captures plus fuzz discovery (~45–60s per page on Linux with docker).

| Active pages | Approximate runtime |
|--------------|---------------------|
| 26 (current registry) | ~20–26 minutes sequential |
| Full registry growth | ~1 minute per additional page |

Run batched or overnight when calibrating the expanded registry; pilot pages (`home-anonymous`, `home-authenticated`, `player-profile`) are the only entries with committed baselines until maintainers `record` new routes.

---

## 4. `validate` — refactor sign-off (R-*)

```bash
git checkout megiddo/r-XX-...
docker compose -f docker-compose.php8.yml up -d

bin/fuzzy-validator validate --pages player-profile,home-authenticated --phase all

# Pixel-only pilot
bin/fuzzy-validator validate --page player-profile --phase visual

# URL file
bin/fuzzy-validator validate --urls path/to/urls.txt --visual-min-score 0.98
```

`validate --phase all` (default) runs **assets → dom → pixels** via `gate_run.py` v1. Each run writes `reports/run-{id}/summary.json`, per-page annotated PNGs under `reports/run-{id}/data/`, and a stdout `FUZZ_GATE …` line. Full JaCoCo-style `index.html` arrives in FU-10.

```bash
# Layer-specific gates (also via gate.sh)
tools/fuzzy-validator/bin/gate.sh --page home-anonymous --phase dom
tools/fuzzy-validator/bin/gate.sh --pages home-anonymous,player-profile --phase all
```

### On failure

1. Open `reports/run-{id}/index.html` (path printed in stdout)
2. Drill into `pages/{pageId}.html`
3. **Red boxes** on screenshot → layout/visual regression
4. **Assets section** → unified diff for changed CSS/JS bytes
5. **DOM section** → paths and snippets outside fuzz nodes

| Situation | Action |
|-----------|--------|
| Bug in refactor | Fix code; re-run `validate` |
| Accidental static file touch | Revert CSS/JS change |
| Template drift | Revert or re-`record` on integration branch |
| New dynamic widget | Re-`record` DOM/pixel fuzz |
| Platform/font noise | Linux CI baselines only |

---

## 5. Setpoint promotion (baseline bundles)

Heavy baseline bytes (PNG, DOM JSON, raw CSS/JS) live in **versioned zip bundles** on Google Drive. Git tracks only `setpoint.json` (filename pointer) and `manifests/` (fuzz allowances).

### Google Drive folder (one-time setup)

1. Create a shared folder named **ORK3 Fuzzy Setpoints**.
2. Set link sharing to **Anyone with the link can view** (maintainers retain edit access).
3. Upload setpoint zips with **exact filenames** from `setpoint capture` (do not rename).

Local bootstrap zips under `setpoints/bootstrap/` let developers and CI restore without Drive until auto-fetch lands.

### After intentional UI change (maintainer on `main`)

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox

# 1. Capture all pages + zip
bin/fuzzy-validator setpoint capture

# 2. Upload setpoints/out/{date}-{sha}-{hash}.zip to Google Drive (filename unchanged)

# 3. Publish pointer + commit manifests only
bin/fuzzy-validator setpoint publish --bundle tools/fuzzy-validator/setpoints/out/….zip
git add tools/fuzzy-validator/setpoint.json tools/fuzzy-validator/manifests/
git commit -m "Promote fuzzy setpoint …"
```

### Developer restore (before validate)

```bash
bin/fuzzy-validator setpoint restore
# or explicit path after Drive download:
bin/fuzzy-validator setpoint restore --bundle ~/Downloads/20260708T….zip

bin/fuzzy-validator validate --pages player-profile --phase all
```

If baselines are missing, `validate` prints a hint pointing at `setpoint restore`.

### Scope-only captures

| Trigger | Command |
|---------|---------|
| Full UI merge on `main` | `setpoint capture` (all profiles, all phases) |
| Sandbox schema only | `record --profile test --all --phase all` then manual zip if needed |
| Mirror DB refresh | `record --profile mirror --all --phase all` |
| Fuzz manifest tweak only | Edit `manifests/` in git; no new zip |

---

## 6. Legacy: update baselines in git (pre-FU-16)

**Deprecated.** Before FU-16, baselines were committed directly:

```bash
git checkout integration-branch
bin/fuzzy-validator record --pages affected-page-id --phase all
git add tools/fuzzy-validator/baselines/
git add tools/fuzzy-validator/manifests/
```

Use **§5 Setpoint promotion** instead.

---

## 7. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Validate fails same commit (pixels) | Threshold too strict | `--visual-min-score 0.98` |
| Asset record aborts | Unstable scripts across N runs | Stub network; fix env |
| DOM validate fails on token | Missing fuzz node | Re-`record` or add `manualNodes` |
| Huge fuzz boxes | Page still loading | Increase `waitAfterMs`; set `readySelector` |
| Empty fuzz manifest | Page fully deterministic | OK — gate uses zero zones |
| Dimension mismatch error | Content height changed | Expected; update baseline |
| Auth pages skipped | Missing env creds | Export `ORK3_E2E_*` |
| macOS vs Linux diff | Font rendering | CI baselines only; local gate optional |
| Missing baselines / validate exit 2 | No setpoint restore | `bin/fuzzy-validator setpoint restore` |

---

## 8. Stabilization reference

Injected before every capture (see [01-architecture.md](./01-architecture.md)):

- Fixed clock: `2026-06-15T12:00:00Z`
- Animations/transitions disabled
- Scroll to top
- `document.fonts.ready`
- `networkidle` + optional selector wait

If a page remains noisy after calibration, prefer **test env stubs** (weather API, what's new modal) over enlarging fuzz boxes.

---

## 9. Command reference

Primary interface: **`bin/fuzzy-validator`** from repo root. Full flags: [10-cli-reference.md](./10-cli-reference.md).

| Command | Purpose |
|---------|---------|
| `bin/fuzzy-validator record --page ID` | Baselines + fuzz discovery |
| `bin/fuzzy-validator record --urls FILE` | Same, URL list file |
| `bin/fuzzy-validator record --all` | All registry pages |
| `bin/fuzzy-validator validate --page ID` | Pass/fail + HTML report |
| `bin/fuzzy-validator validate --urls FILE` | Same, URL list file |
| `bin/fuzzy-validator validate --phase all` | Assets + DOM + pixels |
| `bin/fuzzy-validator setpoint capture` | Full record + zip bundle |
| `bin/fuzzy-validator setpoint publish --bundle PATH` | Update `setpoint.json` pointer |
| `bin/fuzzy-validator setpoint restore` | Extract baselines from bootstrap/Drive zip |

---

## 10. CI (GitHub Actions)

Workflow: [`.github/workflows/fuzzy-validator.yml`](../../../.github/workflows/fuzzy-validator.yml)

| Job | When | Purpose |
|-----|------|---------|
| `pyunit` | PRs touching `orkui/`, `class.Controller.php`, or `tools/fuzzy-validator/` | pytest ≥ 90% coverage (required) |
| `gate-pilot` | Same PRs + `workflow_dispatch` | Linux pixel gate on pilot baselines (optional; `continue-on-error`) |

### Optional gate secrets

Set repository secrets for full pilot gate (all three baselines):

| Secret | Purpose |
|--------|---------|
| `ORK3_E2E_USERNAME` | Mirror/test login for auth-gated pilot pages |
| `ORK3_E2E_PASSWORD` | Matching password |

Without secrets, CI validates `home-anonymous` only.

### Report artifacts

Every `gate-pilot` run uploads `tools/fuzzy-validator/reports/` and candidate PNGs as artifact **`fuzzy-validator-reports-{runId}`** (14-day retention). Download from the Actions run → **Artifacts** after a failed optional gate. Full HTML dashboard arrives in FU-10; FU-3/FU-5 artifacts include per-page diff PNGs (`{pageId}-gate-diff.png`) and calibration overlays.

---

## 11. Megiddo milestone mapping (suggested)

When an R-* milestone lists frontend routes in its DS design note, add corresponding page ids to the gate command for that sprint.

Example for R-01 (RSVP):

```bash
bin/fuzzy-validator validate --pages home-anonymous,player-profile --phase all
```

Document the page list in the R-* commit message or checklist item.
