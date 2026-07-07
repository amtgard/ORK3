# Gate Output — Pass/Fail + HTML Report

**Status:** Plan (not implemented)  
**Audience:** CI lights-out runs and human post-mortem after a failed gate

Every gate invocation produces **exactly two deliverables**:

| # | Output | Consumer | Format |
|---|--------|----------|--------|
| **1** | **Pass / Fail** | CI, scripts, exit codes | stdout summary + exit code `0` / `1` |
| **2** | **Report bundle** | Humans, PR review, archived artifacts | Self-contained HTML site under `reports/run-{id}/` |

---

## 1. Pass / Fail (lights-out)

### 1.1 Exit code

| Code | Meaning |
|------|---------|
| `0` | **PASS** — all gated pages meet thresholds on all enabled layers |
| `1` | **FAIL** — at least one page/layer below threshold |
| `2` | **ERROR** — harness failure (app down, missing baseline, corrupt manifest) |

CI should treat non-zero as red; only `0` merges.

### 1.2 Normalized stability scores

Each page/layer computes a **stability score** in `[0.0, 1.0]` — fraction of comparable surface that **did not regress** after fuzz allowances.

| Layer | Comparable unit | Score formula (default) | Hard fail |
|-------|-----------------|-------------------------|-----------|
| **Assets (CSS/JS)** | Asset files | `1.0` if all sha256 match, else `0.0` | Yes — any mismatch → score `0.0` |
| **DOM tree** | Element paths outside fuzz | `1.0 - (fail_paths / comparable_paths)` | Optional threshold |
| **Pixels** | Pixels outside fuzz boxes | `1.0 - (outside_diff_px / comparable_px)` | Optional threshold |

`comparable_*` excludes fuzz allowances (green zones / fuzz nodes). Fuzzed differences do **not** reduce the score.

**Overall page score** (informational, for report sorting):

```
page_score = min(assets_score, dom_score, visual_score)
```

**Page pass** (configurable per layer):

```
page_pass =
  assets_score >= assetsMinScore      # default 1.0 (hard)
  AND dom_score >= domMinScore        # default 1.0
  AND visual_score >= visualMinScore  # default 1.0; tune to 0.98 in practice
```

**Run pass:** every exercised page passes.

### 1.3 Threshold defaults — `manifests/defaults.json5`

Start strict; relax only with evidence:

```json5
{
  "assetsMinScore": 1.0,
  "domMinScore": 1.0,
  "visualMinScore": 1.0,
  "gateMaxOutsideDiffPx": 500,
  "gateColorThreshold": 20
}
```

When anti-aliasing causes flaky pixel gates, lower **`visualMinScore`** first (e.g. `0.98`) — equivalent to allowing ~2% of comparable pixels to differ outside fuzz. Keep **`assetsMinScore`** at `1.0` for refactor testing.

CLI override:

```bash
npm run fuzz:gate:all -- --pages home-authenticated --visual-min-score 0.98
```

### 1.4 Stdout summary (lights-out)

Machine-readable last line for log parsers:

```
FUZZ_GATE run=20260707T120000Z pages=3 pass=2 fail=1 exit=1
```

Human block above it:

```
Fuzzy UI Gate — FAIL
  home-authenticated     PASS  assets=1.00 dom=1.00 visual=1.00
  player-profile         FAIL  assets=1.00 dom=1.00 visual=0.961  (threshold 1.00)
  home-anonymous         PASS  assets=1.00 dom=1.00 visual=0.998
Report: tools/fuzzy-validator/reports/run-20260707T120000Z/index.html
```

---

## 2. HTML report bundle (JaCoCo-style)

### 2.1 Directory layout

Each gate run writes a timestamped bundle (gitignored; CI uploads as artifact):

```
tools/fuzzy-validator/reports/run-{runId}/
  index.html                    # summary dashboard
  summary.json                  # machine-readable mirror of scores
  assets/
    index.html                  # all CSS/JS failures
  dom/
    index.html                  # all DOM failures
    {pageId}.html               # per-page DOM detail
  visual/
    index.html
    {pageId}.html               # per-page visual detail
  pages/
    {pageId}.html               # unified per-page report (primary drill-down)
  data/
    {pageId}-annotated.png      # screenshot with green/red boxes
    {pageId}-baseline.png       # copy or symlink for diff context
    {pageId}-candidate.png
    {pageId}-diff-heatmap.png   # optional magenta diff mask
    {pageId}-assets.json        # candidate manifest snapshot
    {pageId}-dom-diff.json      # structured DOM failures
  diffs/
    {pageId}/
      {assetId}.diff            # unified diff text CSS/JS
      dom-paths.txt             # long-form DOM path report
```

`runId` = UTC timestamp `YYYYMMDDTHHMMSSZ` or CI build id via `--run-id`.

Open **`index.html`** in a browser locally — no server required (static files only).

### 2.2 Summary dashboard (`index.html`)

JaCoCo-inspired layout:

| Section | Content |
|---------|---------|
| **Header** | Run id, git commit (if available), timestamp, phase flags, overall **PASS/FAIL** banner |
| **Summary table** | One row per page: Pass/Fail badge, `assets` / `dom` / `visual` scores, links to `pages/{pageId}.html` |
| **Totals row** | Pages passed / failed; worst score |
| **Threshold legend** | Active `*MinScore` values from defaults + CLI |
| **Footer** | Paths to raw calibration dirs, command line reproduced |

Color coding (CSS in report):

- **Green** — pass, fuzz-permitted regions on screenshots
- **Red** — fail, regression regions on screenshots
- **Amber** — page passed but score within 1% of threshold (warning)

### 2.3 Per-page report (`pages/{pageId}.html`)

Primary artifact for reviewers. Sections top to bottom:

#### A. Verdict strip

```
FAIL — visual score 0.961 < threshold 1.000
PASS — assets · PASS — dom
```

#### B. Annotated screenshot (required)

Single PNG (or inline `<img>`) with overlays:

| Box color | Meaning |
|-----------|---------|
| **Green** (semi-transparent fill + solid border) | **Fuzz allowance** — diffs here are expected/ignored |
| **Red** (semi-transparent fill + solid border) | **Regression** — diffs outside fuzz; caused failure |

Implementation: Python `overlay.py` draws:

1. All fuzz zones from `{pageId}.fuzz.json` → green
2. Connected components of outside-diff mask → red
3. Optional legend embedded in image corner

Also show side-by-side thumbnails: baseline | candidate | annotated (linked to full size in `data/`).

#### C. Visual metrics

| Metric | Value |
|--------|-------|
| Total pixels | … |
| Fuzz-covered pixels | … |
| Comparable pixels | … |
| Outside diff pixels | … |
| **Visual stability score** | 0.961 |
| Threshold | 1.000 |

#### D. Assets section (if phase includes assets)

- Table: asset id, kind, baseline sha256, candidate sha256, **PASS/FAIL**
- Failed rows link to `diffs/{pageId}/{assetId}.diff`
- Long-form: unified diff in collapsible `<pre>` (optional `--max-diff-lines`; default 500, truncate with “…”)  
- Binary assets: note “N bytes changed” + link to download both files from `data/`

#### E. DOM section (if phase includes dom)

- Summary: comparable paths, failed paths, **dom score**
- Table of failures: path, change type (tag / attr / child-count / text), expected snippet, actual snippet
- Long-form: `dom-paths.txt` inlined or linked; optional pretty tree diff in monospace
- List fuzz nodes applied (green labels in prose — “ignored `/html[0]/…/input[0]@value`”)

#### F. Manifest snapshot

Collapsible JSON: fuzz manifests used, git baseline commit from meta files.

### 2.4 Layer index pages

- **`assets/index.html`** — only pages with asset failures; sort by asset id
- **`dom/index.html`** — only DOM failures; sort by path
- **`visual/index.html`** — only visual failures; sort by score ascending

Cross-link back to summary and per-page reports.

### 2.5 `summary.json` (automation)

```json
{
  "runId": "20260707T120000Z",
  "exitCode": 1,
  "pass": false,
  "thresholds": {
    "assetsMinScore": 1.0,
    "domMinScore": 1.0,
    "visualMinScore": 1.0
  },
  "pages": [
    {
      "pageId": "player-profile",
      "pass": false,
      "scores": { "assets": 1.0, "dom": 1.0, "visual": 0.961 },
      "reportPath": "pages/player-profile.html"
    }
  ]
}
```

Enables downstream tooling without parsing HTML.

---

## 3. Implementation modules

```
tools/fuzzy-validator/python/
  lib/
    scoring.py          # stability scores + pass logic
    report_html.py      # Jinja2 or string templates → HTML bundle
    overlay.py          # green fuzz + red failure boxes (extend Phase 1)
  gate_run.py           # orchestrator: gate all layers → scores → report → exit code
```

`bin/gate.sh` ends with:

```bash
python3 tools/fuzzy-validator/python/gate_run.py \
  --pages "$PAGES" \
  --phase all \
  --report-dir "tools/fuzzy-validator/reports/run-${RUN_ID}" \
  --visual-min-score "${VISUAL_MIN:-1.0}"
echo "Report: tools/fuzzy-validator/reports/run-${RUN_ID}/index.html"
exit "$?"
```

Dependencies add:

```
jinja2>=3.1
```

---

## 4. Milestone placement

| Milestone | Reporting deliverable |
|-----------|----------------------|
| **FU-3** | Pass/fail exit code + annotated PNG + minimal `pages/{id}.html` (visual only) |
| **FU-7** | Asset diff sections in per-page report |
| **FU-8** | DOM diff sections in per-page report |
| **FU-9** | Full `index.html` dashboard + `summary.json` + layer indexes |
| **FU-10** (new) | Polish: heatmap, side-by-side slider, `--visual-min-score 0.98` CI profile |

---

## 5. CI artifact policy

On every gate run (pass or fail):

1. Upload `reports/run-{runId}/` as workflow artifact (retention 14–30 days).
2. On **fail**, print report URL path in CI log.
3. On **pass**, optional upload for audit trail on R-* branches.

Do not commit `reports/run-*` to git.

---

## 6. Review workflow

1. CI fails → download artifact → open `index.html`
2. Red boxes on screenshot → locate unintended layout drift
3. Asset diff section → accidental `revised.css` edit
4. DOM section → template output change outside fuzz
5. If change intentional → re-calibrate on integration branch; if bug → fix refactor

---

## 7. Related docs

- Threshold fields in [03-manifest-schema.md](./03-manifest-schema.md)
- Gate commands in [04-operating-guide.md](./04-operating-guide.md)
- Layer algorithms in [01-architecture.md](./01-architecture.md) and [05-phase2-asset-dom-gate.md](./05-phase2-asset-dom-gate.md)
