# Fuzzy Validator — Milestone Checklist

Track implementation progress for FU-* milestones. Check items only when exit criteria in [02-implementation-plan.md](./02-implementation-plan.md) are met (Phase 1–2). Phase 3–4 exit criteria are defined in this checklist until folded into the implementation plan.

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
- [x] Single squashed commit

### FU-11: Dual database profiles

- [x] Branch `megiddo/fu-11-dual-db`
- [x] `manifests/profiles.json5`; baselines under `baselines/test/` and `baselines/mirror/`
- [x] `record`/`validate` default `--profiles test,mirror`; calls `bin/ork-db use`
- [x] Tiered thresholds: test strict (1.0), mirror lenient (visual 0.98)
- [x] HTML report sections per profile
- [x] Single squashed commit

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
| FU-10 | 2026-07-07 | 32277160 | 94% |
| FU-11 | 2026-07-07 | f0702472 | 92% |
| FU-12 | 2026-07-07 | 26de47b4 | 92% |
| FU-13 | 2026-07-07 | 92c4a575 | 92% |
| FU-14 | 2026-07-07 | 0380ae0a | 92% |
| FU-15 | 2026-07-07 | 608f5b18 | 92% |
| FU-16 | 2026-07-07 | 603fd405 | 91% |

---

## Phase 3 — Evidence of operation (live integration proof)

**Gap after FU-11:** FU-0…FU-11 are functionally complete, but sign-off relied on a **stable** local ORK3 during development. Existing tests prove algorithms on **synthetic** fixtures (`tmp_path`, 32×32 PNGs, inline HTML) — not that live capture → discover → validate actually learns and applies fuzz allowances on real pages.

| What exists today | What it proves | What it does *not* prove |
|-------------------|----------------|--------------------------|
| `python/tests/unit/*` (≥90% coverage) | Diff math, scoring, gate exit codes on fixtures | Real Playwright stabilization, real volatility |
| `python/tests/integration/test_gate_run_fixtures.py` | Unified `gate_run` wiring on synthetic page | Fuzz discovery on multi-grab calibrations |
| FU-3/FU-9 manual exit criteria | Dev-time pass/fail on intentional CSS tweak | Reproducible, reviewable artifacts in repo |
| `tools/fuzzy-validator/reports/` | — | **Gitignored** — no committed reports to verify |

**Goal:** Reproducible integration suites with **committed evidence artifacts** (`tools/fuzzy-validator/evidence/`) that a reviewer can open without running docker.

### Evidence protocol (all layers)

Run on a **virgin stable commit** (baseline branch) with docker up and `bin/ork-db deploy-sandbox`.

| Step | Action |
|------|--------|
| **1. Capture virgin input** | `record --profile test` on evidence page(s); commit baselines + manifests under `evidence/baselines/` and `evidence/manifests/`. |
| **2. In-zone mutation** | Apply a **limited** change that affects only the layer under test (see table below). |
| **2a. Discover fuzz** | *(Pixel + DOM only)* Multi-grab `calibrate` between virgin runs and in-zone mutation → non-empty `*.fuzz.json` / `*.dom-fuzz.json` + calibration overlay. |
| **2b. Validate in-zone** | `validate --phase <layer>` (or `all`) → **pass** (change inside learned fuzz allowance). |
| **2c. Validate out-of-zone** | Apply a second mutation **outside** the fuzz region → **fail** (exit 1, score below threshold, or red boxes in report). |

**Layer-specific mutations** (one evidence page can serve multiple layers if chosen carefully):

| Layer | Fuzzy? | In-zone mutation (2 / 2a–2b) | Out-of-zone mutation (2c) |
|-------|--------|------------------------------|---------------------------|
| **Screenshot / pixel** | Yes | Swap kingdom/player **heraldry** image on an otherwise stable profile tile (`player-profile` or kingdom parks tab) — visual-only drift | Add 20px padding or rename a heading outside heraldry bbox |
| **DOM tree** | Yes | Change a `data-*` token or volatile text node inside a known fuzzy subtree (e.g. session chrome, relative date) | Rename a structural tag or stable label outside fuzz nodes |
| **CSS / JS assets** | **No (hard gate)** | Same commit as baseline → assets **pass** (no fuzz step 2a) | Append one byte to a captured `.css` or `.js` under `orkui/` → assets **fail** with diff in report |

> **Note:** CSS/JS use **zero-tolerance** byte gates (FU-7). Evidence for assets is pass-on-same / fail-on-1-byte — not fuzz discovery.

**Evidence page candidates:** `player-profile` (heraldry + DOM), `home-authenticated` (session chrome / timestamps). Pick 1–2 pages in FU-12; do not require full `pages.json5` registry.

**Committed artifacts** (not gitignored `reports/`):

```
tools/fuzzy-validator/evidence/
  README.md                 # how to re-run; links to reports
  pages.json5               # evidence subset
  baselines/test/           # virgin record output
  manifests/test/           # fuzz + dom-fuzz from 2a
  reports/
    pixel-proof/index.html  # 2a overlay + 2b pass + 2c fail screenshots
    dom-proof/index.html
    assets-proof/index.html
    unified-proof/index.html  # FU-15: --phase all
  scripts/
    run-evidence-suite.sh   # orchestrates 1 → 2c; asserts exit codes
```

---

### FU-12: Evidence harness + virgin capture

- [x] Branch `megiddo/fu-12-evidence-harness`
- [x] `tools/fuzzy-validator/evidence/` layout + `README.md`
- [x] `evidence/pages.json5` (1–2 pages) + mutation recipe doc (heraldry swap, CSS byte, DOM twig)
- [x] Virgin `record --profile test` on stable commit; baselines committed under `evidence/baselines/test/`
- [x] `evidence/scripts/run-evidence-suite.sh` stub (exits non-zero until FU-13–15 fill in)
- [x] Update [09-test-framework.md](./09-test-framework.md) § Evidence suite (cross-ref)
- [x] Single squashed commit

### FU-13: Pixel + DOM fuzz discovery evidence

- [x] Branch `megiddo/fu-13-fuzz-evidence`
- [x] **Pixel:** heraldry-only mutation → multi-grab calibrate → non-empty `fuzz.json` + overlay committed
- [x] **Pixel:** validate in-zone pass; out-of-zone layout change fail with annotated PNG
- [x] **DOM:** in-zone volatile node mutation → `dom-fuzz.json` non-empty + `dom-fuzz.txt` debug
- [x] **DOM:** validate in-zone pass; structural change outside fuzz fail
- [x] `evidence/reports/pixel-proof/` and `dom-proof/` with `index.html` + `summary.json`
- [x] `run-evidence-suite.sh` asserts discover + pass/fail exit codes for pixel + dom
- [x] Single squashed commit

### FU-14: Asset hard-gate evidence

- [x] Branch `megiddo/fu-14-asset-evidence`
- [x] Virgin capture: `validate --phase assets` pass on same commit
- [x] 1-byte CSS change → fail; diff visible in `evidence/reports/assets-proof/`
- [x] 1-byte JS change → fail (separate scenario or same report section)
- [x] `run-evidence-suite.sh` extended for asset pass/fail
- [x] Single squashed commit

### FU-15: Unified evidence + optional CI

- [x] Branch `megiddo/fu-15-unified-evidence`
- [x] Full `validate --phase all` on evidence page: in-zone composite pass, out-of-zone fail
- [x] `evidence/reports/unified-proof/index.html` — all layers in one JaCoCo-style report
- [x] Optional nightly / manual workflow: docker + `evidence/scripts/run-evidence-suite.sh` (not blocking pytest CI)
- [x] Sign-off: reviewer checklist in `evidence/README.md` (open 4 report URLs, confirm green/red boxes)
- [x] Single squashed commit

---

## Phase 4 — Setpoint registry (post-merge baseline promotion)

**Gap after FU-11:** `record` is the right primitive but too granular for the recurring maintainer task: *"PR merged — this is the new expected HTML, DOM, CSS, JS, and screenshots until the next revision."* Today that implies `record --all --phase all`, a multi-file `git add` of `baselines/`, and committing PNGs + raw asset bytes into git. That works for 3 pilot pages (~700 KB) but will not scale to 20+ pages × 2 profiles (screenshots alone become painful in git; Google Drive sync repos make it worse).

**Goal:** One maintainer command to **capture** a versioned setpoint; **committed filename pointer in git**; **heavy zip on Google Drive** (world-readable folder, write-restricted to maintainers).

### Storage split

| Artifact | In git | Off git (setpoint zip) | Why |
|----------|--------|------------------------|-----|
| `manifests/{profile}/*.fuzz.json`, `*.dom-fuzz.json` | Yes | Copy inside zip | Small; diffable; defines fuzz allowances |
| `setpoint.json` — **bundle filename** + metadata | Yes | — | PR diffs show which blob is current |
| `pages.json5`, `profiles.json5`, `defaults.json5` | Yes | — | Registry + thresholds |
| `baselines/{profile}/*.png` | **No** (after FU-16) | Yes | Large; binary |
| `baselines/{profile}/*.dom.json` | **No** | Yes | Grows with page count |
| `baselines/{profile}/*.assets.json` | Optional mirror in git | Yes (canonical) | Metadata small; zip keeps paths consistent |
| `baselines/assets/{profile}/{pageId}/*` (raw CSS/JS) | **No** | Yes | Byte stores |
| `reports/run-*`, `calibrations/` | No (gitignored) | No | Ephemeral debug only |

### Bundle filename (committed to repo)

The tool **produces** the zip locally. The **filename** is the setpoint identity and is what gets committed in `setpoint.json` (visible in PR diffs).

```
{date-time}-{git-commit}-{content-sha256}.zip
```

| Segment | Example | Source |
|---------|---------|--------|
| `date-time` | `20260708T153045Z` | UTC capture timestamp |
| `git-commit` | `f0702472` | Short SHA of HEAD at capture |
| `content-sha256` | `a3f8c1e9d2b4…` | SHA-256 of zip bytes (first 16 hex chars) |

Example: `20260708T153045Z-f0702472-a3f8c1e9d2b4f801.zip`

Content hash in the name lets anyone verify the downloaded file matches the committed pointer without trusting Drive metadata alone.

### Google Drive layout (public read, restricted write)

One shared folder — **world-readable**, writes limited to maintainers. No per-setpoint ACL gymnastics; the folder is wide open for reads.

```
ORK3 Fuzzy Setpoints/          # public view link; maintainer-only edit
  20260708T153045Z-f0702472-a3f8c1e9d2b4f801.zip
  20260715T091200Z-a1b2c3d4-9e8d7c6b5a4f3210.zip
  …
```

Maintainer uploads the tool-produced zip (manual step in FU-16). Old zips stay in the folder as history; git points at exactly one **latest** filename.

### Runtime fetch (deferred resolution)

`restore` needs the zip bytes before `validate`. Resolution order (implement incrementally):

| Phase | Mechanism | FU-16 scope |
|-------|-----------|-------------|
| **Now** | Maintainer uploads; dev passes local path: `setpoint restore --bundle path/to/file.zip` | Yes |
| **Soon** | Committed filename + public folder base URL: `setpoint restore --base-url https://…` constructs download URL | Optional in FU-16 |
| **Later** | Auto-scan public Drive folder listing for committed filename | Deferred |

Cloud URL construction / Drive API listing is **explicitly deferred** — FU-16 only requires local zip production, committed filename in `setpoint.json`, and `restore` from a local path (or manual browser download from the public folder).

### Maintainer workflow (after PR merge)

Run on **`main`** at the merged commit, docker up, sandbox deployed.

| Step | Who | Action |
|------|-----|--------|
| 1 | Maintainer | `bin/fuzzy-validator setpoint capture` — wraps `record --all --phase all --profiles test,mirror` |
| 2 | Tool | Writes `setpoints/out/{date-time}-{git-commit}-{content-sha}.zip` + inline manifest (file list inside zip or sidecar `manifest.json`) |
| 3 | Maintainer | Upload zip to public Google Drive folder (filename unchanged) |
| 4 | Maintainer | `bin/fuzzy-validator setpoint publish` — updates `setpoint.json` with **bundle filename** + metadata; commit pointer + `manifests/` only |
| 5 | Developers | `bin/fuzzy-validator setpoint restore` — local `--bundle`, or (later) fetch by committed filename from public folder |

**Developer validate prerequisite:** baselines extracted locally. `validate` unchanged once `baselines/` is populated.

### `setpoint.json` (committed pointer)

```json
{
  "schemaVersion": 1,
  "latestBundle": "20260708T153045Z-f0702472-a3f8c1e9d2b4f801.zip",
  "driveFolder": "ORK3 Fuzzy Setpoints",
  "setpoints": {
    "20260708T153045Z-f0702472-a3f8c1e9d2b4f801.zip": {
      "gitSha": "f0702472…",
      "capturedAt": "2026-07-08T15:30:45Z",
      "contentSha256": "a3f8c1e9d2b4f801…",
      "profiles": ["test", "mirror"],
      "pageCount": 22
    }
  }
}
```

PR diffs show `latestBundle` filename change — the audit trail for *which blob* is canonical. Optional `driveFolder` is a human hint until auto-fetch lands.

### When to capture a new setpoint

| Trigger | Capture scope |
|---------|---------------|
| PR merges intentional UI/template/asset change | Full `setpoint capture` on `main` |
| Sandbox schema change (`deploy-sandbox`) | `--profile test` only |
| Mirror DB refresh | `--profile mirror` only |
| Fuzz manifest tweak only (no baseline bytes change) | Edit manifests in git; no new zip |

### FU-16: Setpoint capture, zip bundle, and external store

- [x] Branch `megiddo/fu-16-setpoint`
- [x] `bin/fuzzy-validator setpoint capture` — one-shot `record --all --phase all` + zip named `{date-time}-{git-commit}-{content-sha}.zip`
- [x] `bin/fuzzy-validator setpoint publish` — write/update `setpoint.json` with committed **bundle filename** + metadata
- [x] `bin/fuzzy-validator setpoint restore --bundle PATH` — verify content sha256 vs pointer, extract to `baselines/`
- [x] `.gitignore` — stop tracking heavy `baselines/**`; keep `setpoint.json` + `manifests/` in git
- [x] Migrate existing pilot baselines into first published zip; remove PNGs from git on cutover branch
- [x] Document public Drive folder setup (world-readable, maintainer write)
- [x] [04-operating-guide.md](../reference/04-operating-guide.md) § Setpoint promotion (replaces §5 multi-file git add for baselines)
- [x] [10-cli-reference.md](../reference/10-cli-reference.md) `setpoint` subcommand docs
- [x] `validate` fails with actionable message when baselines missing → `setpoint restore --bundle …`
- [x] **Deferred:** `--base-url` download, Drive folder auto-scan for committed filename
- [x] Single squashed commit
