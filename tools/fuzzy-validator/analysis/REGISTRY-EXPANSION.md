# Page registry expansion (UI templates)

**Date:** 2026-07-23  
**Branch:** `fix-pr-492`  
**Scope:** Expand `manifests/pages.json5` so each included HTML UI route template has an active (non-`skip`) registry entry. Does **not** recapture / publish setpoints.

## Include list policy

Start from `analyze_route_coverage.py --ui-only` (**307** templates), then exclude:

| Reason | Templates |
|--------|----------:|
| `Admin/ajax*` | 3 |
| API / non-HTML: `Health*`, `EraPhoenice*`, `QR/link*` | 13 |
| JSON APIs: `EventEmbed/*`, `Recap/json*` | 9 |
| External OAuth: `Login/login_oauth` | 1 |
| Dead / empty `__call`: `Event/kingdom/{id}`, `Event/park/{id}` | 2 |
| Canonicalize aliases of `Login/login/{return}`: `Login/login`, `Login/login/{id}` | 2 |

**Include target: 277** templates (307 − 30 excludes).

Lists: `include-list-ui.txt`, `exclude-list-ui.txt`.

See also `TEMPLATE-COUNT-DELTA.md` (495 vs 307 vs 277) and `http-reachability-test.md`.

## Before → after

| Metric | Before | After (post HTTP follow-up) |
|--------|-------:|----------------------------:|
| Registry pages (total) | 39 | 301 |
| Registry pages (active) | 20 | 278 |
| Registry pages (`skip`) | 19 | 23 |
| Distinct **active** templates | 18 | 276 |
| UI templates targeted (include list) | — | 277 |
| Analyzer missing (raw `--ui-only`) | 277 | 15 (all excluded APIs/aliases) |
| **Still missing (include-list)** | — | **0** |
| Documented residual skips (include-list) | — | 1 (`Kingdom/ics/{id}`) |

Dual-profile / fixture twins (extra page entries sharing one template): e.g. `kingdom-profile` + `kingdom-auth-sandbox`, `event-index-rsvp` + `event-index-rsvp-gok`, `player-profile` + `player-profile-sandbox`. These raise **active page** count above distinct templates.

## Documented skips / residuals

**Include-list residual (keep `skip`):**

- `kingdom-ics` → `Kingdom/ics/{id}` — ICS download, not HTML UI.

**JSON / OAuth marked `skip` after HTTP validation (were briefly active):**

- `eventembed-*` (5) — public JSON embed API
- `recap-json*` (4) — JSON endpoints (HTML `Recap/*` pages remain active)
- `login-login-oauth` — external IdP (`Connection refused` locally)

**Enabled with safer waits (was skip):**

- `event-detail`, `event-template`, `search`, `search-unitsearch`, `attendance`, `reports-officer-directory`, `live-stats`, `sign-in-invalid`.

**Excluded but still in registry as `skip` (smoke / e2e parity):**

- `health-endpoint`, `era-phoenice-api`, `qr-link-api`, `search-ajax-universal`, `attendance-ajax-getday`.

**Dead routes forced `skip`:**

- `event-kingdom`, `event-park`.

**Twins kept `skip` (template already active via sibling):**

- `home-anonymous`, `park-profile`, `event-index`, `event-detail-rsvp`, `event-detail-auth-rsvp`.

## Judgment calls (literal-only / ambiguous)

These UIR literals have **no** matching controller public method (analyzer **ambiguous**), but stay **active** so capture can still smoke the dispatch path:

- `Admin/tournament` — HTTP **500** on sandbox (product)
- `Event/view`
- `Home/index/login`
- `Playernew` / `Playernew/index`

## Re-run analyzer

```bash
python3 tools/fuzzy-validator/scripts/analyze_route_coverage.py --ui-only \
  -o tools/fuzzy-validator/analysis/route-coverage-report-ui.txt
```

Validate registry load:

```bash
cd tools/fuzzy-validator/python && python3 -c \
  "from lib.page_registry import load_pages_registry, validate_pages_registry, active_page_ids as a; \
   r=load_pages_registry(); assert not validate_pages_registry(r); print(len(a(r)), 'active')"
```

HTTP reachability (sandbox / test profile):

```bash
bin/ork-db use dev
python3 tools/fuzzy-validator/scripts/validate_page_http.py --profile test \
  -o tools/fuzzy-validator/analysis/http-reachability-test.md
```

## Out of scope

Full setpoint capture/publish for new pages, product 500 fixes, cleaning unrelated dirty mirror/test fuzz manifests from prior gold runs.
