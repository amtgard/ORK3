# V-00: Global Fuzzy Setpoint

**Milestone:** V-00  
**Branch:** `megiddo/v-00-fuzzy-setpoint`  
**Depends on:** T-14 (e2e coverage complete). **Fuzzy-validator prerequisites satisfied:** FU-4 (≥20 page registry), FU-11 (dual profiles), FU-16 (`setpoint capture` / `restore`).  
**Blocks:** All R-* fuzzy validation gates

---

## Purpose

Establish a **cross-cutting render setpoint** — major interface URLs sampled once, baselined on **both** database profiles, and re-validated on every R-* sprint that touches front-end code.

---

## Preflight step 1 — URL registry

`tools/fuzzy-validator/manifests/pages.json5` registers **32** entries (17 active setpoint pages + 15 skipped JSON/AJAX/ICS or deferred-capture surfaces). Each active entry’s `notes` cite **Class:** (interface family).

### Active setpoint pages (17)

| Class | pageIds |
|-------|---------|
| **Home** | `home-authenticated` |
| **Player** | `player-profile`, `player-profile-sandbox` |
| **Kingdom** | `kingdom-profile` |
| **Park** | `event-park` (profile route deferred — see skips) |
| **Event** | `event-list`, `event-index`, `event-create`, `event-kingdom`, `event-park` |
| **Admin** | `admin-dashboard`, `admin-state-of-amtgard`, `admin-permissions` |
| **Reports** | `reports-voting-eligible`, `reports-ladder-grid`, `reports-attendance` |
| **Infrastructure** | `weather`, `tournament` |

**Deferred to domain V-* (registry row present, `skip: true`):** Search (`search`, `search-unitsearch` → V-11), Attendance (`attendance`, `sign-in-invalid` → V-12), `home-anonymous`, `park-profile`, `event-detail`, `reports-officer-directory`, `live-stats` — capture instability (deferred JS, mirror load, or live DOM).

### Sandbox entity pins (test profile)

| Surface | URL pin | Notes |
|---------|---------|-------|
| Kingdom | `Kingdom/profile/1` | Mirror id 1; sandbox kingdoms use `100001+` — page still renders stable chrome |
| Park / event | `…/1`, `Event/detail` skipped | Sandbox parks `1000001+`, events `80000+` |
| Reports | `KingdomId=14` | Mirror kingdom; sandbox uses synthetic kingdoms — report shell stable |
| Player sandbox | `Player/profile/1` | Seeded mundane id |

Mirror uses the same URL strings; baselines are **per profile** under `baselines/test/` and `baselines/mirror/`.

### Exit criteria (step 1)

- [x] `pages.json5` documents ≥ 15 setpoint entries covering all classes above
- [x] Each entry has `notes` citing interface class
- [x] Sandbox entity ids documented in this file or linked V-* docs

---

## Preflight step 2 — Dual-profile capture

Credentials (local docker only):

| Profile | Login | Password |
|---------|-------|----------|
| **test** | `megiddo` | `test-db-player` (default via `profiles.json5`) |
| **mirror** | `admin` | `password` (local-only; set in `ork_credential` on mirror + sandbox) |

```bash
bin/ork-db deploy-sandbox
bin/fuzzy-validator record --all --phase all --profiles test,mirror
# bundle + pointer (FU-16):
bin/fuzzy-validator setpoint publish --bundle tools/fuzzy-validator/setpoints/out/<bundle>.zip
bin/fuzzy-validator validate --all --phase all
```

**Published setpoint:** `setpoint.json` → `latestBundle` = `20260708T210408Z-40f4fa2c-8c3e8c67a96f30bc.zip` (bootstrap copy under `setpoints/bootstrap/`).

### Exit criteria (step 2)

- [x] Every active setpoint page id has baselines under `baselines/test/` and `baselines/mirror/`
- [x] Pixel fuzz manifests committed under `manifests/test/` and `manifests/mirror/`
- [x] `bin/fuzzy-validator validate --all --phase all` passes on same commit (both profiles) — **34/34 pass** (17 pages × 2 profiles), exit 0
- [x] Human review of calibration overlays for unexpected full-page drift

---

## V-00 sign-off gate

- [x] [05-development-steering.md](../05-development-steering.md) DS-1, DS-3, DS-6, DS-8
- [x] Preflight step 1 + 2 exit criteria met
- [x] Branch `megiddo/v-00-fuzzy-setpoint` — exactly one commit
- [x] [validations/README.md](./README.md) updated; V-00 row linked

---

## Notes

- Health/plain-text and JSON/AJAX routes use `skip: true` in `pages.json5`.
- Capture blocks volatile third-party assets (e.g. Google Tag Manager) for stable asset gates.
- Heavy baselines: `setpoint publish` + bootstrap zip; PNGs remain gitignored under `baselines/`.
