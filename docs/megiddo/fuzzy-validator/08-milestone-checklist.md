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

- [x] Branch `megiddo/fu-2-discover`
- [x] `python/lib/diff_regions.py`, `discover_fuzz.py`, `overlay.py`
- [x] Auto `manifests/{pageId}.fuzz.json` + calibration overlay
- [x] pytest coverage ≥ 90% on new Python modules
- [x] Single squashed commit

### FU-3: Pixel gate

- [x] Branch `megiddo/fu-3-gate`
- [x] `python/gate.py` + `bin/gate.sh` wired
- [x] Pass on same commit; fail on intentional layout change
- [x] pytest coverage ≥ 90% cumulative on `python/`
- [x] Single squashed commit

### FU-4: Page registry expansion

- [x] Branch `megiddo/fu-4-page-registry`
- [x] `pages.json5` ≥ 20 entries mapped from `tests/e2e/`
- [x] Documented runtime for `--all` calibrate
- [x] Tests for registry loader / validation
- [x] Single squashed commit

### FU-5: CI (optional)

- [x] Branch `megiddo/fu-5-ci`
- [x] Linux workflow job for gate (optional PR check)
- [x] Report artifact upload documented
- [x] Single squashed commit

---

## Phase 2 — Assets + DOM + report

### FU-6: Asset capture

- [x] Branch `megiddo/fu-6-asset-capture`
- [x] `playwright/lib/captureAssets.ts` + capture spec extension
- [x] Identical asset sha256 across 5 calibration runs (pilot pages)
- [x] Tests for asset manifest serialization
- [x] Single squashed commit

### FU-7: Asset hard gate

- [x] Branch `megiddo/fu-7-asset-gate`
- [x] `gate_assets.py`, `lib/asset_manifest.py`, `lib/asset_store.py`
- [x] Fail on 1-byte change; pass on same commit
- [x] pytest coverage ≥ 90% on asset modules
- [x] Single squashed commit

### FU-8: DOM fuzz calibration

- [x] Branch `megiddo/fu-8-dom-fuzz`
- [x] `canonical_dom.py`, `tree_diff.py`, `discover_dom_fuzz.py`
- [x] `manifests/{pageId}.dom-fuzz.json` + baselines `{pageId}.dom.json`
- [x] pytest coverage ≥ 90% on DOM modules
- [x] Single squashed commit

### FU-9: Unified gate

- [x] Branch `megiddo/fu-9-unified-gate`
- [x] `gate_dom.py`, `gate.sh --phase all`, `gate_run.py` v1
- [x] Green/red annotated PNG data; layer pass/fail exit codes
- [x] End-to-end test with fixture pages (no docker required)
- [x] Single squashed commit

### FU-10: HTML report + scoring

- [x] Branch `megiddo/fu-10-report`
- [x] `scoring.py`, `report_html.py`, full `reports/run-{id}/` bundle
- [x] `summary.json`, stdout `FUZZ_GATE` line, `--visual-min-score`
- [x] pytest coverage ≥ 90% on entire `tools/fuzzy-validator/python/`
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
| FU-2 | 2026-07-07 | d8820b06 | 96% |
| FU-3 | 2026-07-07 | 742310c4 | 96% |
| FU-4 | 2026-07-07 | 195e316a | 95% |
| FU-5 | 2026-07-07 | eb281bad | 95% |
| FU-6 | 2026-07-07 | f7f306ab | n/a (11 PW unit tests) |
| FU-7 | 2026-07-07 | 3f82fd89 | 95% |
| FU-8 | 2026-07-07 | 4a004419 | 94% |
| FU-9 | 2026-07-07 | 9af10a9a | 95% |
| FU-10 | | | |
| FU-11 | | | |
