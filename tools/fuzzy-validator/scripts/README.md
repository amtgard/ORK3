# Route coverage + HTTP reachability

Static analysis of **orkui route templates** vs the fuzzy-validator page registry
(`manifests/pages.json5`). Optional lightweight HTTP check of active registry
pages. Does not run Playwright pixel/DOM fuzzy validation or touch the setpoint.

## Run

From the repo root:

```bash
python3 tools/fuzzy-validator/scripts/analyze_route_coverage.py

# Full report file (default location used in findings)
python3 tools/fuzzy-validator/scripts/analyze_route_coverage.py \
  --write tools/fuzzy-validator/analysis/route-coverage-report.txt

# Omit *Ajax endpoints
python3 tools/fuzzy-validator/scripts/analyze_route_coverage.py --ui-only -o \
  tools/fuzzy-validator/analysis/route-coverage-report-ui.txt

# Explain 495 vs 307 (Ajax delta) + dump non-ui templates
python3 tools/fuzzy-validator/scripts/analyze_route_coverage.py --explain-delta \
  --dump-non-ui tools/fuzzy-validator/analysis/non-ui-templates.txt \
  -o tools/fuzzy-validator/analysis/template-delta-explain.txt

# HTTP reachability of all active pages (sandbox / test profile)
bin/ork-db use dev
python3 tools/fuzzy-validator/scripts/validate_page_http.py --profile test \
  -o tools/fuzzy-validator/analysis/http-reachability-test.md
```

Prefer base URL `http://127.0.0.1:19080/orkui/` (script default). Plain
`localhost` needs a cookie-policy workaround for PHP’s `Domain=.localhost`.

Stdlib only. Analyzer exit `0` on success even when gaps are listed; non-zero
only on script/IO errors.

## Canonical template form

See the module docstring in `analyze_route_coverage.py`. Short version:

| Instance | Template |
|----------|----------|
| `Kingdom/index/32` | `Kingdom/index/{id}` |
| `Event/detail/4/1` | `Event/detail/{id}/{id}` |
| `Event` (bare) | `Event/index` |
| empty `Route=` | `(home)` |
| `Login/login/Admin/event/5` | `Login/login/{return}` |
| `Reports/voting_eligible&KingdomId=14` | `Reports/voting_eligible?KingdomId={id}` |

## Findings (2026-07-23)

### How the setpoint / registry chooses routes

- **Source of truth for what validate/record hits:** `tools/fuzzy-validator/manifests/pages.json5`.
- **`setpoint.json`** only points at the latest baseline **bundle** (zip of baselines + fuzz manifests). Latest bundle `pageCount` is **20** — matching **active** (non-`skip`) registry pages, not the full 39 entries.
- Pages with `"skip": true` stay in the registry for documentation / e2e parity but are **excluded** from `--all` validate/record (`active_page_ids` in `python/lib/page_registry.py`).
- URLs are `./index.php?Route=…` relative to `ORK3_E2E_BASE_URL`. Multiple page ids may share one route **template** (e.g. two RSVP event ids → one `Event/index/{id}`).

### Current coverage (2026-07-23 registry expansion + HTTP follow-up)

See `../analysis/REGISTRY-EXPANSION.md` and `../analysis/TEMPLATE-COUNT-DELTA.md`.

| Metric | Count |
|--------|------:|
| Registry pages (total) | 301 |
| Registry pages (active / setpoint-gated) | 278 |
| Registry pages (`skip`) | 23 |
| Distinct **active** route templates | 276 |
| UI include-list target | 277 |
| Include-list still missing | 0 |
| Discovered templates (all) | 495 |
| Discovered templates (`--ui-only`) | 307 |
| Delta (all − ui-only) | 188 (all Ajax) |
| **covered** (`--ui-only`) | 272 |
| **skipped** (registry only, `--ui-only`) | 14 |
| **missing** (`--ui-only`, excluded APIs/aliases) | 15 |
| **ambiguous** | 6 (dead Event kingdom/park + literal-only routes) |

Full listing: `../analysis/route-coverage-report.txt` (and `-ui.txt`).
HTTP: `../analysis/http-reachability-test.md`.

### Route template vs instance

- A **template** collapses resource ids / UUIDs / opaque tokens; keeps controller, action, and other static segments.
- An **instance** is a concrete URL the harness navigates (specific kingdom/park/event ids for sandbox vs mirror).

### Residual gaps after registry expansion

Registry include-list coverage is complete for HTML UI templates (see `REGISTRY-EXPANSION.md`). Remaining work is **setpoint capture** for new pages, not more registry rows.

**Still skipped / judgment:**

- `Kingdom/ics/{id}` — ICS download (not HTML); documented residual skip
- `EventEmbed/*`, `Recap/json*`, `Login/login_oauth` — JSON/OAuth; `skip` after HTTP check
- `Event/kingdom/{id}`, `Event/park/{id}` — dead routes (`skip`); empty `__call`
- Literal-only ambiguous actives: `Admin/tournament`, `Event/view`, `Home/index/login`, `Playernew/index`
- `Event/detail/{id}/{id}` — **enabled** with longer waits; capture may still hang at fullPage

### What these scripts do *not* do

- Change the setpoint or run full pixel/DOM fuzzy `--phase all`
- Fix product 500s (HTTP report only records them)
