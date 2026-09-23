# Why ~307 UI templates vs ~495 all templates?

**Date:** 2026-07-23  
**Branch:** `fix-pr-492`  
**Scripts:** `analyze_route_coverage.py` (`--ui-only`, `--explain-delta`), `non-ui-templates.txt`

## Short answer

| Set | Count | How it is defined |
|-----|------:|-------------------|
| All discovered templates | **495** | Controllers + `orkui/index.php` specials + UIR/Route literals + registry |
| `--ui-only` | **307** | Same set **minus** Ajax controllers (`*Ajax`, `WnAjax`, `EventRsvpAjax`) |
| **Delta (all − ui)** | **188** | **100% Ajax** — see below |
| Include-list (HTML UI policy) | **277** | `--ui-only` minus API / dead / alias / OAuth / JSON excludes |
| Active registry pages | **278** | Non-`skip` rows in `pages.json5` (twins can share a template) |
| Distinct active templates | **276** | Include-list minus residual `Kingdom/ics/{id}` skip |

The ~188 gap between 495 and 307 is **not** “missing HTML pages.” It is the Ajax filter. A second, smaller policy filter (~30 templates) then takes 307 → 277 for the page-registry include list.

## Re-run summaries (2026-07-23)

### All discovery

```
Registry pages (total)     : 301
Registry pages (active)    : 278
Registry pages (skip)      : 23
Distinct active templates  : 276
Discovered templates       : 495
  covered                  : 272
  skipped (registry only)  : 16
  missing                  : 201
  ambiguous                : 6
```

### `--ui-only`

```
Discovered templates       : 307 [ui-only]
  covered                  : 272
  skipped (registry only)  : 14
  missing                  : 15
  ambiguous                : 6
```

### `--explain-delta`

```
All discovered templates : 495
--ui-only templates      : 307
Delta (all − ui-only)    : 188

Non-ui-only templates by category
---------------------------------
  ajax                  188
```

Full Ajax listing: `non-ui-templates.txt` (188 lines).  
Machine dump: `template-delta-explain.txt`.

## Delta breakdown: the excluded ~188

`--ui-only` applies **one** filter: `is_ajax_template()` — controller name ends with `Ajax`, or is `WnAjax` / `EventRsvpAjax`.

| Category | Count | Examples |
|----------|------:|----------|
| `*Ajax` / `WnAjax` / `EventRsvpAjax` | **188** | `AdminAjax/global`, `AttendanceAjax/attendance`, `EventRsvpAjax/…`, `ParkAjax/…`, `WnAjax/…` |
| API / Health / Era / QR | **0** in this delta | These remain **inside** `--ui-only` (see next section) |
| Other `--ui-only` filters | **0** | There are no additional filters on this flag |

So: **495 − 188 Ajax = 307.**

## Second filter: 307 UI → 277 include-list

After Ajax removal, operators still exclude non-HTML / dead / alias routes from the **registry include list** (`exclude-list-ui.txt`):

| Exclude reason | Count | Examples |
|----------------|------:|----------|
| `admin-ajax` (Admin controller ajax actions, not `*Ajax` class) | 3 | `Admin/ajax`, `Admin/ajax/{id}`, `Admin/ajax/suspendplayer` |
| `api-era` | 9 | `EraPhoenice/date`, `…/holidays`, `…/today/{id}`, … |
| `api-health` | 1 | `Health` |
| `api-qr` | 3 | `QR/link`, `QR/link/{id}`, `QR/link/{token}` |
| `api-json` | 9 | `EventEmbed/*` (5), `Recap/json*` (4) |
| `canonicalize-alias` | 2 | `Login/login`, `Login/login/{id}` (canonical is `Login/login/{return}`) |
| `dead-ambiguous` | 2 | `Event/kingdom/{id}`, `Event/park/{id}` |
| `external-oauth` | 1 | `Login/login_oauth` |
| **Total excluded from include-list** | **30** | |

**307 − 30 = 277** include-list templates.

### Active pages / templates vs include-list

| Metric | Count | Notes |
|--------|------:|-------|
| Include-list | 277 | Target HTML UI templates |
| Distinct active templates | 276 | Include-list minus residual skip `Kingdom/ics/{id}` (ICS download, not HTML) |
| Active pages | 278 | 276 templates + dual-profile / fixture **twins** (extra page ids sharing one template) |

Twins examples: `kingdom-profile` + `kingdom-auth-sandbox`, `event-index-rsvp` + `event-index-rsvp-gok`, `player-profile` + `player-profile-sandbox`.

## Why analyzer still shows missing (~15) and ambiguous (~6)

### Missing (15) — intentional non-registry / policy excludes

These are discovered under `--ui-only` but **not** given an active registry row (and most are on the exclude list):

| Template | Why |
|----------|-----|
| `Admin/ajax`, `Admin/ajax/{id}`, `Admin/ajax/suspendplayer` | Admin ajax actions (JSON-ish), excluded |
| `EraPhoenice/*` (7 variants in the missing list) | Calendar API |
| `Login/login`, `Login/login/{id}` | Canonicalize to `Login/login/{return}` (which **is** covered) |
| `QR/link`, `QR/link/{id}` | Token API |

`Health`, `EraPhoenice/today`, `QR/link/{token}` appear as **skipped** (registry `skip: true`), not missing.

**Include-list still missing: 0.** Raw `--ui-only` missing is an analyzer/include-list mismatch, not an incomplete expansion.

### Ambiguous (6) — registry present, no controller public action

| Template | Registry | Notes |
|----------|----------|-------|
| `Admin/tournament` | active | Literal-only; HTTP **500** on sandbox |
| `Event/view` | active | Literal-only smoke |
| `Home/index/login` | active | Literal-only |
| `Playernew/index` | active | Literal-only (`Playernew`) |
| `Event/kingdom/{id}` | skip | Dead / empty `__call` |
| `Event/park/{id}` | skip | Dead / empty `__call` |

## Literal vs controller nuances

- Controllers emit `Controller/method` and often `Controller/method/{id}` from signature shape.
- Literals (UIR / `Route=`) add paths the static method scan would miss (e.g. `Home/index/login`, `Admin/tournament`).
- Bare `Controller` ≡ `Controller/index`; empty Route ≡ `(home)`.
- `Login/login/<anything>` collapses to one template `Login/login/{return}` — so controller-emitted `Login/login` and `Login/login/{id}` look “missing” while the canonical form is covered.
- Ajax controllers inflate **all** discovery (~188) but are correctly dropped by `--ui-only`.

## How to reproduce

```bash
python3 tools/fuzzy-validator/scripts/analyze_route_coverage.py \
  -o tools/fuzzy-validator/analysis/route-coverage-report.txt

python3 tools/fuzzy-validator/scripts/analyze_route_coverage.py --ui-only \
  -o tools/fuzzy-validator/analysis/route-coverage-report-ui.txt

python3 tools/fuzzy-validator/scripts/analyze_route_coverage.py --explain-delta \
  --dump-non-ui tools/fuzzy-validator/analysis/non-ui-templates.txt \
  -o tools/fuzzy-validator/analysis/template-delta-explain.txt
```
