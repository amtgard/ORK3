# Fuzzy Validator — Implementation Plan

**Status:** Plan (not implemented)  
**Estimated effort:** Phase 1 — 2–3 sessions (3 pilot pages); Phase 2 — 2–3 additional sessions

**Agent execution:** [07-agent-milestone-prompt.md](./07-agent-milestone-prompt.md) · **Progress:** [08-milestone-checklist.md](./08-milestone-checklist.md) · **Tool tests:** [09-test-framework.md](./09-test-framework.md) (≥ 90% Python coverage from FU-2)

---

## 1. Milestones (FU-*)

Independent of Megiddo DS/T/R numbering. Execute in order.

### Phase 1 — pixel gate

| ID | Branch (suggested) | Deliverable | Exit criteria |
|----|-------------------|-------------|---------------|
| **FU-0** | `megiddo/fu-0-scaffold` | `tools/fuzzy-validator/` layout, `bin/fuzzy-validator`, CLI stub | `bin/fuzzy-validator --help` shows record/validate |
| **FU-1** | `megiddo/fu-1-capture` | Playwright capture spec + stabilization lib | 5 PNGs per pilot page on stable commit |
| **FU-2** | `megiddo/fu-2-discover` | Python `discover_fuzz.py` + overlay | Auto-generated fuzz JSON + overlay for pilot pages |
| **FU-3** | `megiddo/fu-3-gate` | Python `gate.py` + shell wrappers | Gate passes on same commit; fails on intentional CSS tweak |
| **FU-4** | `megiddo/fu-4-page-registry` | Expand `pages.json5` to match e2e coverage | All Megiddo e2e routes registered |
| **FU-5** | `megiddo/fu-5-ci` | Linux baseline job + doc updates | CI artifact: gate report on PR optional check |

### Phase 2 — asset hard gate + fuzzy DOM tree

| ID | Branch (suggested) | Deliverable | Exit criteria |
|----|-------------------|-------------|---------------|
| **FU-6** | `megiddo/fu-6-asset-capture` | Playwright CSS/JS capture + raw byte store | Identical asset manifests across 5 calibration runs |
| **FU-7** | `megiddo/fu-7-asset-gate` | Python `gate_assets.py` | Fails on single-byte CSS/JS change; passes on same commit |
| **FU-8** | `megiddo/fu-8-dom-fuzz` | `canonical_dom.py`, `discover_dom_fuzz.py`, DOM baselines | Auto `dom-fuzz.json`; debug path report |
| **FU-9** | `megiddo/fu-9-unified-gate` | `gate_dom.py`, `gate.sh --phase all`, `gate_run.py` v1 | All layers pass/fail; visual report with green/red boxes |
| **FU-10** | `megiddo/fu-10-report` | Full JaCoCo-style dashboard + `summary.json` | `index.html` drill-down; CI artifact upload |
| **FU-11** | `megiddo/fu-11-dual-db` | Dual profile `record`/`validate`, `profiles.json5`, tiered thresholds | Pass/fail both test (strict) + mirror (lenient) |

Megiddo R-* teams **consume** FU-3 for pixel-only pilots; **FU-11** is the target for full refactor sign-off (dual DB + report).

---

## 2. Repository layout (target)

```
tools/fuzzy-validator/
  README.md
  bin/
    fuzzy-validator          # CLI: record | validate
  manifests/
    pages.json5
    defaults.json5
    {pageId}.fuzz.json         # Phase 1 pixel bbox
    {pageId}.dom-fuzz.json     # Phase 2 DOM fuzz nodes
  baselines/
    {pageId}.png
    {pageId}.dom.json          # Phase 2
    {pageId}.assets.json       # Phase 2
    assets/{pageId}/           # Phase 2 raw CSS/JS bytes (committed)
  playwright/
    capture.spec.ts
    lib/stabilize.ts
    lib/auth.ts
    lib/captureAssets.ts       # Phase 2
    lib/captureDom.ts          # Phase 2
  python/
    requirements.txt
    discover_fuzz.py           # Phase 1
    discover_dom_fuzz.py       # Phase 2
    gate.py                    # Phase 1
    gate_assets.py             # Phase 2
    gate_dom.py                # Phase 2
    gate_run.py                # scores, exit code, HTML report bundle
    lib/scoring.py
    lib/report_html.py
    lib/diff_regions.py
    lib/manifest.py
    lib/overlay.py
    lib/canonical_dom.py       # Phase 2
    lib/tree_diff.py           # Phase 2
    lib/asset_manifest.py      # Phase 2
    lib/asset_store.py         # Phase 2
  calibrations/           # .gitignore
  reports/                # .gitignore

docs/megiddo/fuzzy-validator/   # this plan (committed)
```

Add to root `.gitignore`:

```
tools/fuzzy-validator/calibrations/
tools/fuzzy-validator/reports/
```

**Repo entry point:** `bin/fuzzy-validator` → exec `tools/fuzzy-validator/bin/fuzzy-validator`. CLI spec: [10-cli-reference.md](./10-cli-reference.md).

---

## 3. Phase FU-0 — Scaffold

### Tasks

1. Create directory tree above + **`bin/fuzzy-validator`** wrapper at repo root.
2. Implement `tools/fuzzy-validator/bin/fuzzy-validator` with `record` / `validate` subcommand stubs.
3. Add `tools/fuzzy-validator/README.md` linking to docs.
4. Add `python/requirements.txt` and stub `python/fuzzy_validator/cli.py` with `--help`.
5. Add `.gitignore` entries for `calibrations/` and `reports/`.

Optional npm aliases in root `package.json`:

```json
"fuzz:record": "bin/fuzzy-validator record",
"fuzz:validate": "bin/fuzzy-validator validate"
```

### Acceptance

- `bin/fuzzy-validator --help` lists `record` and `validate`
- `bin/fuzzy-validator record --help` and `validate --help` (stubs OK in FU-0)

---

## 4. Phase FU-1 — Playwright capture

### Tasks

1. Implement `lib/stabilize.ts` (clock, CSS injection, scroll, fonts.ready).
2. Implement `lib/auth.ts` — copy pattern from `tests/e2e/infrastructure.spec.ts`.
3. Implement `capture.spec.ts`:
   - Read `manifests/pages.json5` at runtime (or code-generated import).
   - For each requested page id: login if needed, stabilize, loop `repeat` screenshots.
4. Write PNGs to `calibrations/{pageId}/run-{NNN}.png`.
5. Pilot pages (3):

| pageId | Route | auth |
|--------|-------|------|
| `home-anonymous` | `./index.php?Route=` | none |
| `home-authenticated` | `./index.php?Route=` | login |
| `player-profile` | `./index.php?Route=Player/profile` | login |

### Acceptance

- `npm run fuzz:capture -- --pages home-anonymous` produces 5 PNGs
- Same command twice on same commit: calibration diffs mostly inside expected dynamic areas (manual eyeball)

---

## 5. Phase FU-2 — Python fuzz discovery

### Tasks

1. Implement `lib/diff_regions.py`:
   - `pairwise_diff_mask(img_a, img_b, threshold) -> ndarray[bool]`
   - `intersect_consecutive_masks(masks) -> ndarray[bool]`
   - `masks_to_boxes(mask, min_area, pad) -> list[Rect]`
2. Implement `discover_fuzz.py`:
   - Args: `--page-id`, `--calibration-dir`, `--out-manifest`, `--overlay-out`
   - Load all `run-*.png`, run algorithm, write JSON + overlay
3. Wire `bin/calibrate.sh`:
   ```bash
   npm run fuzz:capture -- --pages "$PAGE"
   python3 tools/fuzzy-validator/python/discover_fuzz.py --page-id "$PAGE" ...
   ```
4. Pick median run (`run-003.png`) as initial baseline candidate; copy to `baselines/{pageId}.png` after review.

### Acceptance

- Overlay shows boxes on timestamps / session chrome if present
- Re-run calibrate on same commit: manifest boxes stable (±few px)

---

## 6. Phase FU-3 — Regression gate

### Tasks

1. Implement `gate.py`:
   - Args: `--page-id`, `--baseline`, `--candidate`, `--manifest`, `--max-outside-diff`
   - Exit code 0/1
2. Implement `bin/gate.sh`:
   - Single stabilized capture → `calibrations/{pageId}/candidate.png`
   - Invoke `gate.py` per page
3. **Negative test:** change a pilot page CSS padding in a throwaway branch; gate fails with diff PNG outside fuzz zones.
4. **Positive test:** same commit as baseline; gate passes.

### Acceptance

- `npm run fuzz:gate -- --pages home-anonymous` exits 0 on baseline commit
- Intentional 20px layout shift outside fuzz zones exits 1

---

## 7. Phase FU-4 — Page registry expansion

Map existing `tests/e2e/*.spec.ts` routes into `pages.json5`:

| Spec file | Suggested page ids |
|-----------|-------------------|
| `rsvp.spec.ts` | `home-anonymous`, `player-profile` |
| `attendance.spec.ts` | `event-attendance`, … |
| `infrastructure.spec.ts` | `home-authenticated`, `health` (health: body text snapshot optional, skip visual if plain text) |
| `lib-service.spec.ts` | pages hitting live weather / era widgets |
| … | one row per visually distinct route |

Auth-gated pages: capture skipped with warning when env creds missing (same as e2e).

Priority for Megiddo R-* order: pages touched by upcoming execution sprints in `04-milestone-checklist.md`.

### Acceptance

- `pages.json5` documents ≥ 20 entries
- `bin/calibrate.sh --all` completes overnight or batched (document runtime)

---

## 8. Phase FU-5 — CI (optional)

1. GitHub Actions job (or extend existing workflow):
   - Linux runner
   - Docker compose up
   - Install Python deps + Playwright chromium
   - Run `gate.sh --phase all` on PRs touching `orkui/` or `system/lib/system/class.Controller.php`
2. Store baselines in repo; do not regenerate in CI unless `--update-baselines` maintainer workflow.
3. Upload `reports/*` diff artifacts on failure (PNG, DOM txt, assets txt).

---

## 9. Phase FU-6 — Asset capture (CSS/JS)

### Tasks

1. Implement `lib/captureAssets.ts`:
   - Listen to `page.on('response')` for CSS/JS MIME types
   - Extract inline `<style>` / `<script>` bodies via `page.evaluate`
   - Write `run-NNN.assets.json` + files under `calibrations/{pageId}/assets/run-NNN/`
2. Extend `capture.spec.ts` to invoke asset capture on every run (alongside PNG).
3. Pilot pages: same three as FU-1.

### Acceptance

- Five calibration runs on same commit → identical `sha256` per asset id in all five manifests
- Manifest lists every stylesheet and script the page loads (including inline)

See [05-phase2-asset-dom-gate.md](./05-phase2-asset-dom-gate.md) §3.

---

## 10. Phase FU-7 — Asset hard gate

### Tasks

1. Implement `lib/asset_manifest.py` — load, compare, diff report
2. Implement `lib/asset_store.py` — read/write baseline bytes
3. Implement `gate_assets.py` — zero-tolerance compare
4. Calibration step asserts N-run stability; copies run-003 manifest + bytes to `baselines/`
5. **Negative test:** change one byte in a loaded CSS file → gate fails with diff path

### Acceptance

- `gate_assets.py` exits 1 on any added/removed/changed asset
- Passes on same commit as baseline

---

## 11. Phase FU-8 — DOM tree calibration

### Tasks

1. Implement `lib/captureDom.ts` — write `run-NNN.dom.html` via `page.content()`
2. Implement `lib/canonical_dom.py` — HTML → indexed path tree JSON
3. Implement `lib/tree_diff.py` — subtree hash, consecutive intersection, mode inference
4. Implement `discover_dom_fuzz.py` — write `manifests/{pageId}.dom-fuzz.json` + debug report
5. Write `baselines/{pageId}.dom.json` from run-003 after review

### Acceptance

- Session tokens / volatile text → `text` or `attributes` fuzz nodes, not full-page `subtree`
- Re-calibrate on same commit → stable fuzz node path set
- `<div>` → `<section>` outside fuzz → detected by `tree_diff` unit test

See [05-phase2-asset-dom-gate.md](./05-phase2-asset-dom-gate.md) §4.

---

## 12. Phase FU-9 — Unified gate

### Tasks

1. Implement `gate_dom.py` — baseline tree vs candidate with fuzz manifest
2. Extend `bin/gate.sh`:
   - `--phase visual|assets|dom|all` (default `all` after FU-9)
   - Single Playwright capture produces PNG + DOM + assets
   - Run gates in order: assets → dom → pixels (fail fast on cheapest first)
3. Extend `bin/calibrate.sh --phase all` to run pixel + DOM fuzz + asset stability check
4. Update [04-operating-guide.md](./04-operating-guide.md)

### Acceptance

| Scenario | `--phase all` |
|----------|---------------|
| Same commit as baselines | Pass |
| 1-byte JS change | Fail (assets) |
| Template tag rename outside fuzz | Fail (dom) |
| 20px padding outside pixel fuzz | Fail (visual) |
| Token in fuzzed input attr | Pass |

---

## 13. Phase FU-10 — HTML report + normalized scoring

### Tasks

1. Implement `lib/scoring.py` — per-layer and per-page stability scores; pass logic vs `*MinScore`
2. Implement `lib/report_html.py` — generate `reports/run-{id}/` static site (Jinja2)
3. Extend `lib/overlay.py` — **green** fuzz boxes, **red** failure boxes on annotated PNG
4. Implement `gate_run.py` — orchestrate layer gates, aggregate scores, write report, set exit code
5. Asset failures → unified diff in `diffs/{pageId}/{assetId}.diff` + collapsible section in HTML
6. DOM failures → structured diff in `data/{pageId}-dom-diff.json` + long-form path list in HTML
7. Write `summary.json` for automation; stdout `FUZZ_GATE …` line
8. CI: upload `reports/run-{id}/` as artifact on every gate run

### Acceptance

- Failed gate → open `index.html` locally; red boxes match failing regions
- Passed gate → exit 0; report still generated with green fuzz overlay on screenshots
- `--visual-min-score 0.98` passes a run with ≤2% comparable pixel drift; `1.0` fails same run
- Asset byte change → score 0.0, diff visible in report

See [06-gate-output-and-report.md](./06-gate-output-and-report.md).

---

## 14. Phase FU-11 — Dual database profiles

### Tasks

1. Add `manifests/profiles.json5` (from [profiles.json5.example](./manifests/profiles.json5.example))
2. Restructure `baselines/{profile}/` and `manifests/{profile}/`
3. Before each profile pass: `bin/ork-db use dev|prod`; wait for app container
4. `--profiles test,mirror` default on `record` and `validate`; `--profile` for single
5. `--ensure-sandbox` → `bin/ork-db deploy-sandbox` before `test` pass
6. Apply profile-specific thresholds in `scoring.py`
7. HTML report: top-level summary + subsection per profile
8. Stdout: `[test]` / `[mirror]` lines per page

### Acceptance

- `validate` on same commit passes both profiles with default thresholds
- `test` fails at visual 0.99; `mirror` passes at 0.983 with default 0.98 floor
- Missing `baselines/mirror/` fails with clear error when mirror in profile list

See [11-dual-database-profiles.md](./11-dual-database-profiles.md).

---

## 15. Integration with Megiddo R-* sign-off

Optional checklist item for execution sprints:

```bash
# After full PHPUnit (DS-4), before commit (DS-5):
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox
export ORK3_E2E_USERNAME=... ORK3_E2E_PASSWORD=...   # mirror profile

bin/fuzzy-validator validate --pages <pages-for-this-milestone> --phase all
# Default: test (strict 1.0) + mirror (lenient visual 0.98)
```

If gate fails, open **`reports/run-{id}/index.html`** (CI artifact). Layer-specific sections:

| Layer | Report section |
|-------|----------------|
| Assets | Unified diff per failed CSS/JS file |
| DOM | Path table + long-form tree diff |
| Pixels | Annotated screenshot (green fuzz / red failure) |

Do **not** replace PHPUnit or Infection sign-off.

---

## 16. Implementation notes

### Playwright config extension

Either extend root `playwright.config.ts`:

```typescript
{
  name: 'fuzzy-capture',
  testDir: './tools/fuzzy-validator/playwright',
  use: { viewport: { width: 1280, height: 720 } },
}
```

Or standalone `tools/fuzzy-validator/playwright.config.ts` invoked via `-c`.

### pages.json5 loader

Use `json5` npm package or precompile to JSON in `bin/capture.sh` to avoid TS json5 dependency.

### Python CLI interface

```bash
python3 discover_fuzz.py \
  --page-id home-authenticated \
  --calibration-dir tools/fuzzy-validator/calibrations/home-authenticated \
  --defaults tools/fuzzy-validator/manifests/defaults.json5 \
  --out tools/fuzzy-validator/manifests/home-authenticated.fuzz.json \
  --overlay tools/fuzzy-validator/reports/home-authenticated-calibration-overlay.png

python3 gate.py \
  --page-id home-authenticated \
  --baseline tools/fuzzy-validator/baselines/home-authenticated.png \
  --candidate tools/fuzzy-validator/calibrations/home-authenticated/candidate.png \
  --manifest tools/fuzzy-validator/manifests/home-authenticated.fuzz.json \
  --max-outside-diff 500
```

---

## 17. Success metrics

### Phase 1

| Metric | Target |
|--------|--------|
| False pass on 20px layout break | 0 on pilot pages |
| False fail on same-commit re-run | ≤ 1 page flaky per 10 gates |
| Calibrate runtime per page | < 60s including 5 captures |

### Phase 2

| Metric | Target |
|--------|--------|
| Asset false pass on 1-byte change | 0 |
| DOM false pass on tag rename outside fuzz | 0 |
| DOM false fail on CSRF token in fuzzed input | 0 |
| Full `--phase all` runtime per page | < 90s |

---

## 18. Future work

- Cross-link pixel bbox centers to DOM paths (`elementFromPoint`)
- Optional query-string stripping for asset URLs if cache-busters are noisy
- `page.route()` stubs for live weather in TEST
- Parallel capture workers
- Shrinking fuzz regions when calibration variance drops
