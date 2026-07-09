# V-07: Park Profile & AJAX — Validation Artifacts

**Milestone:** V-07  
**Branch:** `megiddo/v-07-park-validation`  
**Target IDs:** T-PRK-01 through T-PRK-05, T-PRA-01, T-PRA-03  
**Depends on:** DS-07, T-07, V-00  
**Execution sprint:** R-07  
**Discovery source:** [ds-07-park-discovery.md §1](../ds-07-park-discovery.md#1-backend-survey)

---

## 1. Semaphore / canary URLs

Park **profile hosts**. Mirror `park-profile` (id `1`) stays `skip: true` (asset drift). Sandbox Silver Fen + park calendar host cover the surface.

### 1.1 Page registry entries

| pageId | Route | auth | Target IDs | Capture |
|--------|-------|------|------------|---------|
| `park-auth-sandbox` | `./index.php?Route=Park/profile/1000001` | login | T-PRK-01–05 | V-02 — re-validate |
| `event-park` | `./index.php?Route=Event/park/1` | login | Park calendar host | V-00 — re-validate |
| `park-profile` | `./index.php?Route=Park/profile/1` | login | T-PRK-* mirror | skip (asset drift) |

No new `pages.json5` rows for V-07.

**Domain capture set:** none (reuse).  
**R-07 fuzzy gate:** `park-auth-sandbox,event-park`

### 1.2 Canary matrix

| Surface | Variant A | Variant B |
|---------|-----------|-----------|
| Park profile | Sandbox Silver Fen `1000001` | Mirror id `1` (skip visual) |
| Park calendar host | `event-park` | — |
| Abbr check AJAX | `ParkAjaxTest` | — |

**Sandbox pins:** park `1000001`, login `megiddo`.  
**Mirror:** sandbox park id → home chrome; `event-park` uses mirror park `1`.

### 1.3 Record / validate

```bash
bin/fuzzy-validator validate --pages park-auth-sandbox,event-park --phase all
```

**V-07 capture result:** validate exit **0** (4/4).  
**RB-F (2026-07-09):** `park-auth-sandbox` in setpoint `20260709T173049Z-1591950d-6b22e991bb478256.zip` (sandbox refresh height drift).

---

## 2. Test mutation boundaries

### 2.1 Tests in scope (from T-07)

| Test file | Type | Covers |
|-----------|------|--------|
| `tests/Integration/ParkProfileTest.php` | Integration | Event summary, roster, averages, coords |
| `tests/Integration/ParkAjaxTest.php` | Integration | Park abbr uniqueness |
| `tests/e2e/park-profile.spec.ts` | e2e | Profile smoke |

**Infection:** `infection.t07-park.json5` — MSI 24% on `class.Park.php`.

### 2.2 Expected breakage when code migrates

| Test | Likely failure mode | Root cause |
|------|---------------------|------------|
| `ParkProfileTest` | Methods move to `ParkProfile` / shared event engine | Profile SQL leaves controller |
| `ParkAjaxTest` | Abbr API path | `CheckParkAbbreviationAvailable` |
| `park-profile.spec.ts` | Selector drift | Profile markup |
| Shared with R-06 | Event summary engine | Must consume R-06 API, not fork SQL |

### 2.3 Acceptable migration boundaries

| Boundary | Allowed during R-07 | Not allowed |
|----------|---------------------|-------------|
| **Domain** | `ParkProfile` + shared event summary scope=Park | Change draft/RSVP visibility rules |
| **Cross-sprint** | Call R-06 event summary; R-02/R-03 for auth/banner | Re-implement kingdom event SQL |
| **Fixtures** | Sandbox park `1000001` | Mirror-only park ids without doc |
| **Fuzzy** | Re-record on intentional park UI change | Un-skip `park-profile` without fixing asset drift |
| **Infection** | Add `--filter=class.ParkProfile.php` / KingdomProfile | MSI below T-07 floor without justification |

### 2.4 Post-R-07 Infection scope

```bash
sh bin/run-infection.sh \
  --filter=class.Park.php \
  --filter=class.ParkProfile.php \
  --filter=class.KingdomProfile.php \
  --test-framework-options="--filter=ParkProfileTest|ParkAjaxTest"
```

---

## 3. R-07 sign-off checklist

- [ ] §1 page ids pass `bin/fuzzy-validator validate --phase all` (test + mirror)
- [ ] Test edits within §2.3
- [ ] Full unit suite green
- [ ] Infection per §2.4
- [ ] No new `$DB` in `Controller_Park` / `Controller_ParkAjax` for migrated targets
