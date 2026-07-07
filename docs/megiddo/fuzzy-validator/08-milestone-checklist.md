# Fuzzy Validator — Milestone Checklist

Track implementation progress for FU-* milestones. Check items only when exit criteria in [02-implementation-plan.md](./02-implementation-plan.md) are met.

---

## Phase 1 — Pixel gate

### FU-0: Scaffold

- [x] Branch `megiddo/fu-0-scaffold` created
- [x] Directory layout under `tools/fuzzy-validator/`
- [x] **`bin/fuzzy-validator`** wrapper at repo root (executable)
- [x] `tools/fuzzy-validator/bin/fuzzy-validator` with `record` / `validate` stubs
- [x] Python CLI stubs with `--help`
- [x] npm scripts in root `package.json`
- [x] `.gitignore` entries for `calibrations/` and `reports/`
- [x] Milestone tests pass; coverage baseline recorded
- [x] Single squashed commit on branch

### FU-1: Playwright capture

- [x] Branch `megiddo/fu-1-capture`
- [x] `playwright/lib/stabilize.ts`, `auth.ts`, `capture.spec.ts`
- [x] `manifests/pages.json5` pilot pages (3)
- [x] Five PNGs per pilot page on stable commit
- [x] Unit/integration tests for stabilization helpers where applicable
- [x] Single squashed commit

### FU-2: Pixel fuzz discovery

- [ ] Branch `megiddo/fu-2-discover`
- [ ] `python/lib/diff_regions.py`, `discover_fuzz.py`, `overlay.py`
- [ ] Auto `manifests/{pageId}.fuzz.json` + calibration overlay
- [ ] pytest coverage ≥ 90% on new Python modules
- [ ] Single squashed commit

### FU-3: Pixel gate

- [ ] Branch `megiddo/fu-3-gate`
- [ ] `python/gate.py` + `bin/gate.sh` wired
- [ ] Pass on same commit; fail on intentional layout change
- [ ] pytest coverage ≥ 90% cumulative on `python/`
- [ ] Single squashed commit

### FU-4: Page registry expansion

- [ ] Branch `megiddo/fu-4-page-registry`
- [ ] `pages.json5` ≥ 20 entries mapped from `tests/e2e/`
- [ ] Documented runtime for `--all` calibrate
- [ ] Tests for registry loader / validation
- [ ] Single squashed commit

### FU-5: CI (optional)

- [ ] Branch `megiddo/fu-5-ci`
- [ ] Linux workflow job for gate (optional PR check)
- [ ] Report artifact upload documented
- [ ] Single squashed commit

---

## Phase 2 — Assets + DOM + report

### FU-6: Asset capture

- [ ] Branch `megiddo/fu-6-asset-capture`
- [ ] `playwright/lib/captureAssets.ts` + capture spec extension
- [ ] Identical asset sha256 across 5 calibration runs (pilot pages)
- [ ] Tests for asset manifest serialization
- [ ] Single squashed commit

### FU-7: Asset hard gate

- [ ] Branch `megiddo/fu-7-asset-gate`
- [ ] `gate_assets.py`, `lib/asset_manifest.py`, `lib/asset_store.py`
- [ ] Fail on 1-byte change; pass on same commit
- [ ] pytest coverage ≥ 90% on asset modules
- [ ] Single squashed commit

### FU-8: DOM fuzz calibration

- [ ] Branch `megiddo/fu-8-dom-fuzz`
- [ ] `canonical_dom.py`, `tree_diff.py`, `discover_dom_fuzz.py`
- [ ] `manifests/{pageId}.dom-fuzz.json` + baselines `{pageId}.dom.json`
- [ ] pytest coverage ≥ 90% on DOM modules
- [ ] Single squashed commit

### FU-9: Unified gate

- [ ] Branch `megiddo/fu-9-unified-gate`
- [ ] `gate_dom.py`, `gate.sh --phase all`, `gate_run.py` v1
- [ ] Green/red annotated PNG data; layer pass/fail exit codes
- [ ] End-to-end test with fixture pages (no docker required)
- [ ] Single squashed commit

### FU-10: HTML report + scoring

- [ ] Branch `megiddo/fu-10-report`
- [ ] `scoring.py`, `report_html.py`, full `reports/run-{id}/` bundle
- [ ] `summary.json`, stdout `FUZZ_GATE` line, `--visual-min-score`
- [ ] pytest coverage ≥ 90% on entire `tools/fuzzy-validator/python/`
- [ ] Single squashed commit

### FU-11: Dual database profiles

- [ ] Branch `megiddo/fu-11-dual-db`
- [ ] `manifests/profiles.json5`; baselines under `baselines/test/` and `baselines/mirror/`
- [ ] `record`/`validate` default `--profiles test,mirror`; calls `bin/ork-db use`
- [ ] Tiered thresholds: test strict (1.0), mirror lenient (visual 0.98)
- [ ] HTML report sections per profile
- [ ] Single squashed commit

---

## Sign-off notes

| Milestone | Date | Commit | Coverage % |
|-----------|------|--------|------------|
| FU-0 | 2026-07-07 | 02e0a306 | n/a (4 tests) |
| FU-1 | 2026-07-07 | 7eab9424 | n/a (8 tests + 3 PW unit) |
| FU-2 | | | |
| FU-3 | | | |
| FU-4 | | | |
| FU-5 | | | |
| FU-6 | | | |
| FU-7 | | | |
| FU-8 | | | |
| FU-9 | | | |
| FU-10 | | | |
| FU-11 | | | |
