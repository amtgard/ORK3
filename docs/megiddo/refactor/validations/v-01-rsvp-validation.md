# V-01: RSVP — Validation Artifacts

**Milestone:** V-01  
**Branch:** `megiddo/v-01-rsvp-validation`  
**Target IDs:** T-RSV-01 through T-RSV-09, T-INF-06  
**Depends on:** DS-01, T-01, V-00  
**Execution sprint:** R-01  
**Discovery source:** [ds-01-rsvp-discovery.md §1](../ds-01-rsvp-discovery.md#1-backend-survey)

---

## 1. Semaphore / canary URLs

Fuzzy-validator **render stability** for RSVP host surfaces. AJAX JSON (`EventRsvpAjax/set|withdraw`) and Event/detail occurrence chrome are covered by T-01 tests — detail pages hang on fullPage capture (same class as V-00 `event-detail`).

### 1.1 Page registry entries

| pageId | Route | auth | Target IDs | Capture |
|--------|-------|------|------------|---------|
| `home-authenticated` | `./index.php?Route=` | login | T-INF-06 | V-00 setpoint (re-validate at R-01) |
| `player-profile` | `./index.php?Route=Player/profile` | login | T-RSV-08, T-RSV-09 | V-00 setpoint (re-validate at R-01) |
| `event-index-rsvp` | `./index.php?Route=Event/index/80000` | login | T-RSV batch counts | **V-01 record** |
| `event-index-rsvp-gok` | `./index.php?Route=Event/index/80003` | login | T-RSV batch counts | **V-01 record** |
| `event-detail-rsvp` | `./index.php?Route=Event/detail/80000/1` | none | T-RSV-01–07 | `skip: true` (fullPage hang) |
| `event-detail-auth-rsvp` | `./index.php?Route=Event/detail/80000/1` | login | T-RSV-02–05 | `skip: true` (fullPage hang) |

**Domain capture set:** `event-index-rsvp,event-index-rsvp-gok`  
**R-01 fuzzy gate (recommended):** those two + `home-authenticated,player-profile`

### 1.2 Canary matrix

| Surface | Variant A | Variant B | Variant C |
|---------|-----------|-----------|-----------|
| Event index RSVP counts | Spring War `80000` | Gathering of Kingdoms `80003` | — |
| Home widget totals | `home-authenticated` | — | — |
| Player upcoming / kingdom discovery | `player-profile` | — | — |
| Event occurrence (e2e only) | Sandbox `Event/detail/80000/1` past end-date | Mirror future event (manual) | — |

**Sandbox pins (`ork_test` after `bin/ork-db deploy-sandbox`):**

| Entity | Id | Notes |
|--------|-----|-------|
| Spring War event | `80000` | detail `1` ended 2026-05-26 |
| Gathering of Kingdoms | `80003` | detail `4` |
| Login player | `megiddo` / mundane `4` | test profile auth |

**Mirror:** Events `80000`/`80003` absent — same URL strings render home chrome. Baselines are per profile. Do not put mirror-only event ids in the shared registry.

### 1.3 Record baselines (both profiles)

```bash
bin/ork-db deploy-sandbox --yes
export ORK3_E2E_BASE_URL=http://127.0.0.1:19080/orkui/
bin/fuzzy-validator record --pages event-index-rsvp,event-index-rsvp-gok --phase all --profiles test,mirror
bin/fuzzy-validator validate --pages event-index-rsvp,event-index-rsvp-gok --phase all
```

**V-01 capture result (2026-07-09):** validate exit **0** — test + mirror, both page ids, assets/dom/visual all 1.00.  
**Setpoint:** `setpoint.json` `latestBundle` = `20260709T173049Z-1591950d-6b22e991bb478256.zip` (RB-F full recapture; pageCount 30).

R-01 full RSVP gate:

```bash
bin/fuzzy-validator validate --pages home-authenticated,player-profile,event-index-rsvp,event-index-rsvp-gok --phase all
```

---

## 2. Test mutation boundaries

### 2.1 Tests in scope (from T-01)

| Test file | Type | Covers |
|-----------|------|--------|
| `tests/Integration/EventRsvpTest.php` | Integration | Model_Event RSVP methods |
| `tests/Integration/EventRsvpAjaxTest.php` | Integration | EventRsvpAjax counts/set/withdraw |
| `tests/Integration/EventRsvpSearchTest.php` | Integration | Search + kingdom upcoming |
| `tests/Unit/EventRsvpValidationTest.php` | Unit | Status whitelist, date gate |
| `tests/e2e/rsvp.spec.ts` | e2e | Home + player profile smoke |

**Infection (pre-refactor):** `infection.t01-rsvp.json5` — MSI 52% / covered 63% on `model.Event.php` + `EventRsvp` filter.

### 2.2 Expected breakage when code migrates

| Test | Likely failure mode | Root cause |
|------|---------------------|------------|
| `EventRsvpAjaxTest` | 404 or wrong JSON shape | AJAX handler thinned; logic in service |
| `EventRsvpTest` | Direct model method removed | Reads move to `Event` / `EventService` |
| `EventRsvpSearchTest` | SQL assertion on frontend path | Query moved to domain |
| `rsvp.spec.ts` | Selector drift | Template changes if API-driven markup differs |

### 2.3 Acceptable migration boundaries

| Boundary | Allowed during R-01 | Not allowed |
|----------|---------------------|-------------|
| **Integration tests** | Call `EventService` / JSON endpoints instead of `Model_Event` | Change RSVP count semantics or status enum |
| **Fixtures** | Use sandbox events `80000`/`80003`; update pins if template shifts | Hardcode mirror-only event ids without doc |
| **e2e** | Update selectors for equivalent markup; exercise `Event/detail/80000/1` | Remove authenticated flow from sign-off |
| **Fuzzy** | Re-record only if intentional RSVP UI change | Pass validate by widening fuzz without review |
| **Infection** | Shift `--filter` to `class.Event.php` + new service paths | MSI below T-01 documented floor without justification |

### 2.4 Post-R-01 Infection scope

```bash
sh bin/run-infection.sh \
  --filter=class.Event.php \
  --test-framework-options="--filter=EventRsvp"
```

---

## 3. R-01 sign-off checklist

- [x] §1 domain page ids pass `bin/fuzzy-validator validate --phase all` (test + mirror)
- [x] Test edits within §2.3
- [x] Full unit suite green
- [x] Infection per §2.4

**R-01 capture notes (2026-07-09):** Post-refactor validate required test-profile re-record for `event-index-rsvp,event-index-rsvp-gok` after `deploy-sandbox` (pre-record dom 0.94 on sandbox date text). Final gate 8/8 pass test+mirror. Infection MSI 17% / covered 55% on `class.Event.php` + EventService.
