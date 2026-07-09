# V-13: Infrastructure & Misc — Validation Artifacts

**Milestone:** V-13  
**Branch:** `megiddo/v-13-infrastructure-validation`  
**Target IDs:** T-INF-01 through T-INF-06, T-WN-01  
**Depends on:** DS-13, T-13, V-00  
**Execution sprint:** R-13  
**Discovery source:** [ds-13-infrastructure-discovery.md §1](../ds-13-infrastructure-discovery.md#1-backend-survey)

---

## 1. Semaphore / canary URLs

Infrastructure targets live in **shared bootstrap** (`orkui/index.php`, `class.Controller.php`, `WnAjax`) — high fan-out, not dedicated page shells. `health-endpoint` stays `skip: true` (plain-text probe). R-13 fuzzy gate uses **authenticated home** (session token, font prefs, home kingdom, RSVP widget chrome); behavior covered by T-13.

### 1.1 Page registry entries

| pageId | Route | auth | Target IDs | Capture |
|--------|-------|------|------------|---------|
| `home-authenticated` | `./index.php?Route=` | login | T-INF-03–06, T-WN-01 host | V-00 — re-validate |
| `health-endpoint` | `./index.php?Route=Health` | none | T-INF-01 | skip (plain text) |
| `home-anonymous` | `./index.php?Route=` | none | Home shell | skip (inline JS) |

No new `pages.json5` rows for V-13.

**Domain capture set:** none (reuse).  
**R-13 fuzzy gate:** `home-authenticated`

### 1.2 Canary matrix

| Surface | Variant A | Variant B |
|---------|-----------|-----------|
| Authenticated home (Controller base) | `home-authenticated` | — |
| Health probe | T-13 `HealthTest` + e2e | skip visual |
| Legacy event redirect | T-13 `LegacyRedirectTest` | skip visual (302) |
| Session token / What’s New | T-13 integration + e2e | skip visual |
| Home RSVP widget counts | `home-authenticated` + R-01 batch | coordinate T-INF-06 with R-01 |

**Sandbox pins:** logged-in `megiddo` / sandbox player home kingdom from seed.  
**Mirror:** mirror E2E user home chrome (lenient thresholds).

### 1.3 Record / validate

```bash
bin/fuzzy-validator validate --pages home-authenticated --phase all
```

**V-13 capture result:** re-recorded `home-authenticated` (mirror DOM drift); validate exit **0** (2/2).

---

## 2. Test mutation boundaries

### 2.1 Tests in scope (from T-13)

| Test file | Type | Covers |
|-----------|------|--------|
| `tests/Unit/HealthTest.php` | Unit | T-INF-01 PingDb |
| `tests/Integration/SessionTokenTest.php` | Integration | T-INF-03 ValidateSessionToken |
| `tests/Integration/ViewerPreferencesTest.php` | Integration | T-INF-04 fonts; T-INF-05 home kingdom |
| `tests/Integration/WhatsNewTest.php` | Integration | T-WN-01 dismiss / seen |
| `tests/Integration/LegacyRedirectTest.php` | Integration | T-INF-02 event redirect lookup |
| `tests/Support/InfrastructureFixture.php` | Fixture | Sandbox infra rows |
| `tests/e2e/infrastructure.spec.ts` | e2e | Health, session, home, What’s New smoke |

**Infection:** `infection.t13-infrastructure.json5` — MSI floor 13% on `class.Player.php` (Authorization SQL mirrors deferred to R-13/T-14).

### 2.2 Expected breakage when code migrates

| Test | Likely failure mode | Root cause |
|------|---------------------|------------|
| `HealthTest` | Domain `Health.PingDb` path | Probe leaves `index.php` raw `$DB` |
| `SessionTokenTest` | `Authorization.ValidateSessionToken` | Controller SELECT → domain |
| `ViewerPreferencesTest` | Slim prefs / home kingdom APIs | Heavy GetPlayer replaced |
| `WhatsNewTest` | `DismissWhatsNew` / theme flag | INSERT + template `$DB` removed |
| `LegacyRedirectTest` | Slim event summary API | Redirect SQL → Event service |
| `infrastructure.spec.ts` | Redirect / modal timing | Controller wiring during migration |
| T-INF-06 home RSVP counts | Partial until R-01 | Prefer `GetRsvpCountsBatch` from R-01 |

### 2.3 Acceptable migration boundaries

| Boundary | Allowed during R-13 | Not allowed |
|----------|---------------------|-------------|
| **Domain** | PingDb, ValidateSessionToken, GetViewerPreferences, GetHomeKingdom, What’s New APIs, redirect summary | Change session-replaced / health 200–503 semantics without test update |
| **Cross-sprint** | Wire T-INF-06 to R-01 batch API; defer menu HasAuthority to R-14 | Re-implement HasAuthority menu gates in R-13 |
| **Fixtures** | InfrastructureFixture / sandbox player | Mirror-only session rows without doc |
| **Fuzzy** | Re-record `home-authenticated` on intentional chrome change; keep health skip | Un-skip `health-endpoint` as visual page |
| **Infection** | Add Authorization / Health / Player helpers as they land | MSI below T-13 floor without justification |

### 2.4 Post-R-13 Infection scope

```bash
sh bin/run-infection.sh \
  --configuration=infection.t13-infrastructure.json5 \
  --only-covered \
  --filter=class.Player.php \
  --filter=class.Authorization.php \
  --test-framework-options="--filter=HealthTest|SessionTokenTest|ViewerPreferencesTest|WhatsNewTest|LegacyRedirectTest"
```

---

## 3. R-13 sign-off checklist

- [ ] §1 page ids pass `bin/fuzzy-validator validate --phase all` (test + mirror)
- [ ] Test edits within §2.3
- [ ] Full unit suite green
- [ ] Infection per §2.4
- [ ] No new `$DB` in `orkui/index.php`, `class.Controller.php`, or `controller.WnAjax.php` for migrated targets
