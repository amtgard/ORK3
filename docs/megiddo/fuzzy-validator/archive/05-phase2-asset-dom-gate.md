# Phase 2 — Asset Stability (CSS/JS) + Fuzzy DOM Tree Gate

**Status:** Historical plan — implemented (FU-6 … FU-10). Prefer [../12-design-and-implementation.md](../12-design-and-implementation.md) and [../reference/01-architecture.md](../reference/01-architecture.md) for as-built behavior.  
**Depends on:** Phase 1 (FU-3+) — shared Playwright capture harness and page registry  
**Purpose:** Refactor regression — assets and markup should change **minimally or not at all**

---

## 1. Refactor stability model

Megiddo R-* work moves PHP logic behind services; the frontend should remain structurally stable:

| Layer | Expected refactor delta | Gate mode |
|-------|---------------------------|-----------|
| **CSS** | Zero | **Hard** byte-for-byte per loaded stylesheet |
| **JavaScript** | Zero | **Hard** byte-for-byte per loaded script |
| **HTML/DOM** | Minimal, localized | **Tree compare** with calibration-learned fuzz branches |
| **Pixels** (Phase 1) | Zero outside fuzz boxes | Fuzzy bbox gate |

CSS/JS changes during a refactor sprint are almost always accidental (wrong bundle, reordered includes, touched static file). DOM changes may be limited to moved templates but must not reshuffle unrelated trees.

---

## 2. Shared capture extension

Phase 2 reuses Phase 1 stabilization (`lib/stabilize.ts`, auth, page registry). Each calibration/gate run additionally writes:

```
calibrations/{pageId}/
  run-001.png              # Phase 1 (existing)
  run-001.dom.json         # Phase 2 — canonical DOM tree
  run-001.assets.json      # Phase 2 — CSS/JS asset manifest + inline bodies
  assets/                  # Phase 2 — raw bytes per run (gitignored)
    run-001/
      css-000-stylesheet.css
      js-003-revised.js
```

Single Playwright pass per run captures **screenshot + DOM + assets** together so all layers see identical page state.

---

## 3. CSS & JavaScript — hard validation

### 3.1 What to capture

| Source | Asset key | Bytes |
|--------|-----------|-------|
| Network `text/css` responses | Canonical URL (see §3.2) | Response body raw |
| `<link rel="stylesheet">` | Resolved href | Same |
| Inline `<style>` blocks | Synthetic id `inline-style-{index}` | `textContent` UTF-8 |
| Network `application/javascript` / `text/javascript` | Canonical URL | Response body raw |
| `<script src>` | Resolved src | Same |
| Inline `<script>` (non-empty) | Synthetic id `inline-script-{index}` | `textContent` UTF-8 |

Exclude empty inline scripts. Exclude known third-party analytics if stubbed via `page.route()` in TEST.

### 3.2 URL canonicalization

For stable keys across runs on the same commit:

1. Resolve relative to page URL.
2. Strip URL fragment (`#...`).
3. **Keep query string** for v1 — refactor must not change cache-busters; if CI noise appears, add optional `stripQuery: true` per asset class in `defaults.json5`.

Asset record:

```json
{
  "id": "css-002",
  "kind": "css",
  "url": "http://localhost:19080/orkui/template/revised-frontend/script/revised.css",
  "sha256": "a1b2c3…",
  "byteLength": 482901,
  "inline": false
}
```

Inline assets set `"url": null`, `"inline": true`.

### 3.3 Calibration (assets)

Assets are **deterministic on a given commit** — calibration does not learn fuzz for CSS/JS. Instead:

1. Capture N runs.
2. Assert **all N runs produce identical asset manifests** (same ids, sha256, byteLength).
3. If manifests differ across calibration runs → **page is not asset-stable**; fix stubs/env before baselining.

Output baseline: `baselines/{pageId}.assets.json` (from median run, identical to all runs when stable).

Optional: store raw files under `baselines/assets/{pageId}/` for offline diff on gate failure.

### 3.4 Gate (assets) — zero tolerance

Compare candidate `run-assets.json` to `baselines/{pageId}.assets.json`:

| Condition | Result |
|-----------|--------|
| Same set of asset ids, all sha256 match | Pass |
| Missing asset id | **Fail** |
| Extra asset id | **Fail** |
| Same id, different sha256 | **Fail** (report unified diff path to stored baseline file) |
| Same id, different byteLength | **Fail** |

No pixel-style budget. Any byte change fails.

On failure, emit structured diff data for the HTML report (`diffs/{pageId}/{assetId}.diff` + per-page assets section in `reports/run-{id}/pages/{pageId}.html`). Optional plain-text mirror: `data/{pageId}-assets-summary.txt`.

---

## 4. DOM / HTML — fuzzy tree comparison

### 4.1 Why tree comparison, not HTML bytes

Raw `page.content()` fails on attribute order, insignificant whitespace, and session tokens embedded in attributes. A **canonical tree** normalizes structure while preserving refactor-sensitive shape: tag names, nesting, stable attributes, form fields.

Volatile **text** and **dynamic attributes** (timestamps, tokens) are learned via calibration — same consecutive-intersection logic as Phase 1 pixels.

### 4.2 Canonical tree format

Playwright `page.evaluate()` walks `document.documentElement` and emits JSON:

```json
{
  "tag": "html",
  "path": "/html[0]",
  "attrs": { "lang": "en" },
  "children": [
    {
      "tag": "body",
      "path": "/html[0]/body[0]",
      "attrs": { "class": "ork-home" },
      "children": [ … ]
    }
  ]
}
```

Rules:

| Rule | Rationale |
|------|-----------|
| Path = index path from root (`/html[0]/body[0]/div[2]`) | Stable when structure stable; relocates with subtree moves |
| Attributes sorted by name in `attrs` | Order-independent compare |
| Boolean attrs as `"checked": ""` or omit if absent | Consistent serialization |
| Text nodes as `{ "text": "…", "path": "…/text[0]" }` | Separate fuzz on text |
| Skip `<script>` / `<style>` **bodies** in DOM tree | Validated in asset gate; avoid duplicate noise |
| Keep `<script src>` / `<link href>` as element nodes | Structural includes must not change |

Python may re-parse HTML with `html5lib` + `lxml` for calibration/gate if TS and Python must match — **single canonicalizer in Python** recommended: TS writes raw HTML snippet per run, Python builds tree.

**Capture file per run:** `run-NNN.dom.html` (raw) + `run-NNN.dom.json` (canonical, produced by Python during calibrate).

### 4.3 Calibration — discover fuzz tree nodes

For each tree path `P` across runs `R_1 … R_N`:

1. Serialize **subtree at P** to a hashable blob (tag, attrs, child tags/structure, text).
2. Compare blob across consecutive pairs `(R_i, R_{i+1})`.
3. Paths where blob **differs in every consecutive pair** → candidate **volatile subtree**.
4. Refine granularity (auto):
   - If only `text` differs → fuzz rule `{ "path": P, "mode": "text" }`
   - If only specific attrs differ (e.g. `data-token`, `value`) → `{ "path": P, "mode": "attributes", "attrs": ["data-token"] }`
   - If structure under P differs → `{ "path": P, "mode": "subtree" }`

Ancestor collapse: if parent path is `subtree` fuzz, omit redundant child rules.

Output: `manifests/{pageId}.dom-fuzz.json`

```json
{
  "schemaVersion": 1,
  "pageId": "home-authenticated",
  "calibratedAt": "2026-07-07T20:00:00Z",
  "calibrationRuns": 5,
  "fuzzNodes": [
    {
      "path": "/html[0]/body[0]/header[0]/span[2]",
      "mode": "text",
      "source": "auto",
      "label": "session greeting"
    },
    {
      "path": "/html[0]/body[0]/form[0]/input[0]",
      "mode": "attributes",
      "attrs": ["value", "data-csrf"],
      "source": "auto"
    }
  ],
  "manualNodes": []
}
```

Debug artifact: `reports/{pageId}-dom-fuzz.txt` — human-readable list of paths and modes.

### 4.4 Baseline DOM

`baselines/{pageId}.dom.json` — canonical tree from calibration run `run-003` (same pick as screenshot baseline).

### 4.5 Gate (DOM) — strict outside fuzz

Deep-compare candidate tree to baseline:

| Change | Outside fuzz | Result |
|--------|--------------|--------|
| Tag rename / node insert / delete | yes | **Fail** |
| Attribute value change | yes | **Fail** |
| Attribute value change | no (listed in `attributes` fuzz) | Pass |
| Text node change | no (`text` fuzz) | Pass |
| Entire subtree change | no (`subtree` fuzz) | Pass |
| Child count change | yes | **Fail** |

Report: structured diff in `data/{pageId}-dom-diff.json` rendered in HTML report; long-form path list in `diffs/{pageId}/dom-paths.txt` (collapsible in `pages/{pageId}.html`).

**Refactor expectation:** gate failures here indicate template/MVC output drift, not pixel drift alone.

---

## 5. Unified Phase 2 gate

`bin/gate.sh --phase all` (default after FU-9):

```
For each page:
  1. Playwright single capture → PNG + dom.html + assets
  2. gate_assets.py     → hard pass/fail + diffs for report
  3. gate_dom.py        → tree pass/fail + path diffs for report
  4. gate.py            → pixel pass/fail + red/green overlay data
  5. gate_run.py        → aggregate scores, exit code, HTML bundle
```

Phase flags:

| Flag | Runs |
|------|------|
| `--phase visual` | Phase 1 only |
| `--phase assets` | CSS/JS hard gate |
| `--phase dom` | DOM tree gate |
| `--phase all` | All three + report (default after FU-10) |

Megiddo R-* sign-off should use `--phase all`. Outputs: exit code + `reports/run-{id}/index.html`. See [06-gate-output-and-report.md](../reference/06-gate-output-and-report.md).

---

## 6. Python modules (Phase 2 additions)

```
tools/fuzzy-validator/python/
  discover_dom_fuzz.py
  gate_assets.py
  gate_dom.py
  lib/
    canonical_dom.py      # HTML → tree JSON
    tree_diff.py          # diff with fuzz rules
    asset_manifest.py     # load/compare sha256 manifests
    asset_store.py        # read/write raw bytes on disk
```

Dependencies add:

```
html5lib>=1.1
```

---

## 7. Playwright modules (Phase 2 additions)

```
tools/fuzzy-validator/playwright/
  lib/captureAssets.ts    # response listener + inline extraction
  lib/captureDom.ts       # page.content() or evaluate walker
```

Extend `capture.spec.ts` to write asset + DOM artifacts alongside PNGs.

---

## 8. Acceptance criteria (Phase 2)

| Test | Expected |
|------|----------|
| Same commit, calibrate assets ×5 | Identical sha256 all runs |
| Touch one byte in `revised.css` on branch | `gate_assets.py` fails |
| Same commit, calibrate DOM ×5 | Stable fuzz node set |
| Change template `<div>` → `<section>` outside fuzz | `gate_dom.py` fails |
| Session token in input value | Absorbed by `attributes` fuzz after calibrate |
| R-* refactor with zero template/asset change | `--phase all` passes |

---

## 9. Relationship to Phase 1 pixel fuzz

| Mechanism | Layer | Complementary |
|-----------|-------|---------------|
| Bbox pixel fuzz | Rendered appearance | Catches CSS that changed file hash but same selector layout edge cases |
| Asset hard gate | Source bytes | Catches change before render |
| DOM tree fuzz | Markup structure | Catches template output drift pixels might miss (hidden nodes, ARIA) |

All three should pass for R-* sign-off after FU-9.
