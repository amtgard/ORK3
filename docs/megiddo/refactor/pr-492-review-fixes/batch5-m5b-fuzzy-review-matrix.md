# Batch5 M5b — fuzzy residual review matrix

Run: `20260802T185654Z` · Branch tip: `fix-pr-492` @ `287b9091`  
Validate: **EXIT:1** — line scores **522 PASS / 24 FAIL**; gate `unexpected=118`  
Thresholds (run `summary.json`): assets/dom/visual **≥ 1.0** (per-page HTML may show profile-local floors).

Sources: [closeout § M5b](./batch5-rev4-closeout-checklist.md) · `tools/fuzzy-validator/manifests/pages.json5`

---

## 1. How to open the run

| Artifact | Open |
|---|---|
| Run HTML report | [index.html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/index.html) |
| Validate log | [batch5-m5b-fuzzy-validate-all.log](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/build/batch5-m5b-fuzzy-validate-all.log) |
| Run dir (repo-relative) | `tools/fuzzy-validator/reports/run-20260802T185654Z/` |

```bash
open "tools/fuzzy-validator/reports/run-20260802T185654Z/index.html"
# or
open "file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/index.html"
```

Also useful: run `summary.json`, `drifts.json` (118 unexpected), per-page `pages/{profile}/{id}.html`, `data/{id}-annotated.png`, `data/{id}-dom-diff.json`.

---

## 2. Review checklist (24 FAIL only)

Grouped by theme. Failures are almost all **assets** (`inline:js:…` fingerprint) with green DOM/visual, except weather / admin-authorization / orkremental / unit.

### Event detail / template (8)

Shared across profiles — highest fan-out.

| Profile | Page | Layer | Why it matters / likely mode |
|---|---|---|---|
| test | `event-template` | assets 0.91 | Template event 80000; 2× `inline:js` hash drift |
| test | `event-template-2` | assets 0.91 | Bare `Event/template`; same asset pattern |
| mirror | `event-template` | assets 0.90 | Same page on prod mirror |
| mirror | `event-template-2` | assets 0.90 | Shares test template-2 asset ids |
| test | `event-detail-2` | assets 0.92 | Bare `Event/detail` |
| mirror | `event-detail` | assets 0.92 | Canonical detail `4/1` |
| mirror | `event-detail-2` | assets 0.92 | Dual-profile pair with test |
| mirror | `event-detail-3` | assets 0.92 (+1 DOM) | `Event/detail/1`; asset + `node_kind_mismatch` |

### Reports — dues / index / attendance (8)

| Profile | Page | Layer | Why it matters / likely mode |
|---|---|---|---|
| test | `reports-active-duespaid` | assets 0.93 | Shared pair of `inline:js` hashes with index/dues family |
| test | `reports-active-duespaid-2` | assets 0.93 | Kingdom 14 variant; same hashes |
| test | `reports-active-waivered-duespaid` | assets 0.93 | Same shared hashes |
| test | `reports-active-waivered-duespaid-2` | assets 0.93 | Same shared hashes |
| test | `reports-index` | assets 0.93 | Reports hub; same shared hashes |
| test | `reports-index-2` | assets 0.93 | `Reports/index/14`; same shared hashes |
| test | `reports-attendance-3` | assets 0.89 | Distinct asset ids (not the dues/index pair) |
| mirror | `reports-orkremental-2` | visual 0.995 (+DOM text) | Chart/text pixel drift (not assets) |

### Admin (3)

| Profile | Page | Layer | Why it matters / likely mode |
|---|---|---|---|
| mirror | `admin-authorization` | DOM 0.99 + visual 0.998 | Auth table attr/text mismatch — data drift |
| mirror | `admin-topparks` | assets 0.89 | Top-parks admin; `inline:js` |
| mirror | `admin-topparks-2` | assets 0.89 | `/1` variant; `inline:js` |

### Weather (1)

| Profile | Page | Layer | Why it matters / likely mode |
|---|---|---|---|
| test | `weather-index` | DOM + visual (~1.00 log; HTML dom≈0.997 / visual≈0.9999) | Weather text_mismatch + small pixel box — natural data drift |

### Unit (1)

| Profile | Page | Layer | Why it matters / likely mode |
|---|---|---|---|
| mirror | `unit-index` | DOM 0.97 (25 drifts) | Table cell `node_kind_mismatch` ×25 — likely DOM/product structure |

### Park (2)

| Profile | Page | Layer | Why it matters / likely mode |
|---|---|---|---|
| test | `park-auth-sandbox` | assets 0.93 | Sandbox park host chrome; `inline:js` |
| mirror | `park-index-2` | assets 0.92 | `Park/index/1`; `inline:js` |

### Attendance (1)

| Profile | Page | Layer | Why it matters / likely mode |
|---|---|---|---|
| mirror | `attendance-event` | assets 0.92 | Public `Attendance/event/1`; `inline:js` (shares hashes with event-detail-3) |

---

## 3. Step-by-step test matrix

Base for `file://` links:

`file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/`

Repo-relative backup: `tools/fuzzy-validator/reports/run-20260802T185654Z/…`

Expected for all rows: **PASS** at threshold on **assets + DOM + visual** (no unexpected drifts).

| Step | Profile | Page id | Route/URL | Expected | Actual (log) | Artifacts | Reviewer note |
|---:|---|---|---|---|---|---|---|
| 1 | test | event-template | `./index.php?Route=Event/template/80000` | PASS all | FAIL a=0.91 d=1.00 v=1.000 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/test/event-template.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/event-template-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/event-template-dom-diff.json) · [summary](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/summary.json) | inline JS asset hash drift |
| 2 | mirror | event-template | `./index.php?Route=Event/template/80000` | PASS all | FAIL a=0.90 d=1.00 v=1.000 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/mirror/event-template.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/event-template-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/event-template-dom-diff.json) | same template family (mirror) |
| 3 | test | event-template-2 | `./index.php?Route=Event/template` | PASS all | FAIL a=0.91 d=1.00 v=1.000 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/test/event-template-2.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/event-template-2-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/event-template-2-dom-diff.json) | inline JS asset hash drift |
| 4 | mirror | event-template-2 | `./index.php?Route=Event/template` | PASS all | FAIL a=0.90 d=1.00 v=1.000 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/mirror/event-template-2.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/event-template-2-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/event-template-2-dom-diff.json) | shared asset ids w/ test |
| 5 | test | event-detail-2 | `./index.php?Route=Event/detail` | PASS all | FAIL a=0.92 d=1.00 v=1.000 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/test/event-detail-2.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/event-detail-2-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/event-detail-2-dom-diff.json) | inline JS asset hash drift |
| 6 | mirror | event-detail-2 | `./index.php?Route=Event/detail` | PASS all | FAIL a=0.92 d=1.00 v=1.000 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/mirror/event-detail-2.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/event-detail-2-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/event-detail-2-dom-diff.json) | dual-profile detail-2 |
| 7 | mirror | event-detail | `./index.php?Route=Event/detail/4/1` | PASS all | FAIL a=0.92 d=1.00 v=1.000 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/mirror/event-detail.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/event-detail-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/event-detail-dom-diff.json) | canonical detail; assets only |
| 8 | mirror | event-detail-3 | `./index.php?Route=Event/detail/1` | PASS all | FAIL a=0.92 d=1.00 v=1.000 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/mirror/event-detail-3.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/event-detail-3-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/event-detail-3-dom-diff.json) | assets + minor DOM structure |
| 9 | test | reports-index | `./index.php?Route=Reports` | PASS all | FAIL a=0.93 d=1.00 v=1.000 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/test/reports-index.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/reports-index-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/reports-index-dom-diff.json) | shared reports inline-js cluster |
| 10 | test | reports-index-2 | `./index.php?Route=Reports/index/14` | PASS all | FAIL a=0.93 d=1.00 v=1.000 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/test/reports-index-2.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/reports-index-2-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/reports-index-2-dom-diff.json) | same shared hashes |
| 11 | test | reports-active-duespaid | `./index.php?Route=Reports/active_duespaid` | PASS all | FAIL a=0.93 d=1.00 v=1.000 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/test/reports-active-duespaid.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/reports-active-duespaid-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/reports-active-duespaid-dom-diff.json) | same shared hashes |
| 12 | test | reports-active-duespaid-2 | `./index.php?Route=Reports/active_duespaid/14` | PASS all | FAIL a=0.93 d=1.00 v=1.000 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/test/reports-active-duespaid-2.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/reports-active-duespaid-2-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/reports-active-duespaid-2-dom-diff.json) | same shared hashes |
| 13 | test | reports-active-waivered-duespaid | `./index.php?Route=Reports/active_waivered_duespaid` | PASS all | FAIL a=0.93 d=1.00 v=1.000 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/test/reports-active-waivered-duespaid.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/reports-active-waivered-duespaid-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/reports-active-waivered-duespaid-dom-diff.json) | same shared hashes |
| 14 | test | reports-active-waivered-duespaid-2 | `./index.php?Route=Reports/active_waivered_duespaid/14` | PASS all | FAIL a=0.93 d=1.00 v=1.000 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/test/reports-active-waivered-duespaid-2.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/reports-active-waivered-duespaid-2-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/reports-active-waivered-duespaid-2-dom-diff.json) | same shared hashes |
| 15 | test | reports-attendance-3 | `./index.php?Route=Reports/attendance/14` | PASS all | FAIL a=0.89 d=1.00 v=1.000 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/test/reports-attendance-3.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/reports-attendance-3-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/reports-attendance-3-dom-diff.json) | assets-only; distinct hashes |
| 16 | mirror | reports-orkremental-2 | `./index.php?Route=Reports/orkremental/14` | PASS all | FAIL a=1.00 d=1.00 v=0.995 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/mirror/reports-orkremental-2.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/reports-orkremental-2-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/reports-orkremental-2-dom-diff.json) | chart/data visual drift |
| 17 | mirror | admin-authorization | `./index.php?Route=Admin/authorization` | PASS all | FAIL a=1.00 d=0.99 v=0.998 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/mirror/admin-authorization.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/admin-authorization-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/admin-authorization-dom-diff.json) | auth table data drift |
| 18 | mirror | admin-topparks | `./index.php?Route=Admin/topparks` | PASS all | FAIL a=0.89 d=1.00 v=1.000 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/mirror/admin-topparks.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/admin-topparks-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/admin-topparks-dom-diff.json) | inline JS asset hash drift |
| 19 | mirror | admin-topparks-2 | `./index.php?Route=Admin/topparks/1` | PASS all | FAIL a=0.89 d=1.00 v=1.000 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/mirror/admin-topparks-2.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/admin-topparks-2-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/admin-topparks-2-dom-diff.json) | inline JS asset hash drift |
| 20 | test | weather-index | `./index.php?Route=Weather/index/1` | PASS all | FAIL a=1.00 d=1.00 v=1.000† | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/test/weather-index.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/weather-index-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/weather-index-dom-diff.json) | natural weather/text drift |
| 21 | mirror | unit-index | `./index.php?Route=Unit` | PASS all | FAIL a=1.00 d=0.97 v=1.000 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/mirror/unit-index.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/unit-index-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/unit-index-dom-diff.json) | likely product/DOM structure |
| 22 | test | park-auth-sandbox | `./index.php?Route=Park/profile/1000001` | PASS all | FAIL a=0.93 d=1.00 v=1.000 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/test/park-auth-sandbox.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/park-auth-sandbox-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/park-auth-sandbox-dom-diff.json) | sandbox chrome inline-js |
| 23 | mirror | park-index-2 | `./index.php?Route=Park/index/1` | PASS all | FAIL a=0.92 d=1.00 v=1.000 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/mirror/park-index-2.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/park-index-2-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/park-index-2-dom-diff.json) | inline JS asset hash drift |
| 24 | mirror | attendance-event | `./index.php?Route=Attendance/event/1` | PASS all | FAIL a=0.92 d=1.00 v=1.000 | [html](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/pages/mirror/attendance-event.html) · [diff PNG](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/attendance-event-annotated.png) · [dom-diff](file:///Users/inoahsmi/Library/CloudStorage/GoogleDrive-en.gannim%40gmail.com/My%20Drive/Personal/Development/ORK3/tools/fuzzy-validator/reports/run-20260802T185654Z/data/attendance-event-dom-diff.json) | assets; hashes shared w/ detail-3 |

† Log rounds weather to 1.00; page HTML shows dom≈0.997 / visual≈0.9999 vs threshold 1.0 — still FAIL.

Note: `data/{id}-*.png` / `*-dom-diff.json` are page-id keyed (not profile-prefixed); dual-profile pages share one snapshot set from the run.

---

## 4. Suggested review order

1. **Event template/detail cluster (steps 1–8)** — confirm one shared `inline:js` root cause; spot-check one visual PNG (DOM already green).
2. **Reports dues/index cluster (steps 9–14)** — same two asset hashes; one page HTML is enough if hashes match.
3. **reports-attendance-3 (15)** — separate asset pair.
4. **reports-orkremental-2 (16)** — only visual/DOM report residual; open annotated PNG.
5. **admin-authorization (17)** — table text/attr; decide natural data vs auth UI change.
6. **admin-topparks{,-2} (18–19)** — assets-only pair.
7. **weather-index (20)** — expect natural drift; accept or refuzz.
8. **unit-index (21)** — 25 DOM `node_kind_mismatch`; highest product-signal one-off.
9. **Park + attendance one-offs (22–24)** — assets-only leftovers.

Do **not** re-run full `validate --all` for this review; use existing `run-20260802T185654Z` artifacts.
