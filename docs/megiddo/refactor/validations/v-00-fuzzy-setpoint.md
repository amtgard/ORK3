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

Add **1–3 entries per class** to `tools/fuzzy-validator/manifests/pages.json5`. The FU-4 registry (≥20 entries from e2e) is the starting point; V-00 adds or confirms major-interface coverage.

### Target setpoint matrix (draft)

| Class | pageId (proposed) | Route | auth | Priority |
|-------|-------------------|-------|------|----------|
| **Home** | `home-anonymous` | `./index.php?Route=` | none | FU-1 ✓ |
| **Home** | `home-authenticated` | `./index.php?Route=` | login | FU-1 ✓ |
| **Player** | `player-profile` | `./index.php?Route=Player/profile` | login | FU-1 ✓ |
| **Kingdom** | `kingdom-profile` | `./index.php?Route=Kingdom/profile/{id}` | login | V-00 |
| **Park** | `park-profile` | `./index.php?Route=Park/profile/{id}` | login | V-00 |
| **Admin** | `admin-index` | `./index.php?Route=Admin` | login | V-00 |
| **Event** | `event-detail` | `./index.php?Route=Event/detail/{id}` | none/login | V-00 |
| **Reports** | `reports-ladder` | `./index.php?Route=Reports/ladder_grid` | login | V-00 |
| **Search** | `search-universal` | `./index.php?Route=Search` | login | V-00 |

Pin `{id}` values that exist in **both** sandbox (after `deploy-sandbox`) and mirror (document separately if mirror-only).

### Exit criteria (step 1)

- [ ] `pages.json5` documents ≥ 15 setpoint entries covering all classes above
- [ ] Each entry has `notes` citing interface class
- [ ] Sandbox entity ids documented in this file or linked V-* docs

---

## Preflight step 2 — Dual-profile capture

Stable commit on integration branch; docker up; credentials per [06-test-framework.md § preflight](../06-test-framework.md#e2e-login-credentials-preflight).

```bash
bin/ork-db deploy-sandbox

# Preferred (FU-16): one-shot setpoint for all pages, both profiles
bin/fuzzy-validator setpoint capture
# upload zip to Drive, then:
bin/fuzzy-validator setpoint publish

# Or per-profile record (FU-11):
bin/ork-db use dev
bin/fuzzy-validator record --all --phase all --profile test

bin/ork-db use prod
bin/fuzzy-validator record --all --phase all --profile mirror
```

Credentials: `test` uses `megiddo` / `ORK3_E2E_TEST_PASSWORD` (default `test-db-player`); `mirror` uses `ORK3_E2E_USERNAME` / `ORK3_E2E_PASSWORD` per `manifests/profiles.json5`.

### Exit criteria (step 2)

- [ ] Every setpoint page id has baselines under `baselines/test/` and `baselines/mirror/`
- [ ] Pixel fuzz manifests committed under `manifests/test/` and `manifests/mirror/`
- [ ] `bin/fuzzy-validator validate --all --phase all` passes on same commit (both profiles)
- [ ] Human review of calibration overlays for unexpected full-page drift

---

## V-00 sign-off gate

- [ ] [05-development-steering.md](../05-development-steering.md) DS-1, DS-3, DS-6, DS-8
- [ ] Preflight step 1 + 2 exit criteria met (or documented FU-* dependency gap)
- [ ] Branch `megiddo/v-00-fuzzy-setpoint` — exactly one commit
- [ ] [validations/README.md](./README.md) updated; V-00 row linked

Live integration proof already exists in `tools/fuzzy-validator/evidence/` (FU-12–FU-15) against sandbox — V-00 extends that to the full setpoint registry.

---

## Notes

- Health/plain-text routes may set `skip: true` for pixel gate if documented in pages.json5.
- Heavy baselines: prefer `setpoint capture` + `setpoint publish` (FU-16) over committing PNGs to git.
