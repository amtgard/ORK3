# V-17: Residual Domain Lib Bypass — Validation Artifacts

**Milestone:** V-17  
**Branch:** `megiddo/v-17-lib-bypass-validation` (retroactive — backfill 2026-07-10)  
**Target IDs:** T-EVT-08 (weather templates), T-KNG-11, T-PRK-05, T-PLR-08, T-RPT-02, T-UNT-02, T-UNT-03  
**Depends on:** DS-17, T-17, V-00, V-14  
**Execution sprint:** R-17  
**Discovery source:** [ds-17-lib-bypass-discovery.md §1](../ds-17-lib-bypass-discovery.md#1-backend-survey)

---

## 1. Semaphore / canary URLs

Lib-bypass migration touches **event weather blocks**, player profile helpers, and reports voting scope. R-17 fuzzy gate uses three domain hosts with re-recorded baselines.

### 1.1 Page registry entries

| pageId | Route | auth | Target IDs | Capture |
|--------|-------|------|------------|---------|
| `event-index-rsvp` | `./index.php?Route=Event&EventId=…` | login | T-EVT-08 weather templates | V-01/V-05 — re-validate |
| `player-profile` | `./index.php?Route=Player&PlayerId=…` | login | T-PLR-08, T-KNG-11 overlap | V-09 — re-validate |
| `reports-voting-eligible` | `./index.php?Route=Reports/voting_eligible` | login | T-RPT-02 `GetParkKingdomId` | V-10 — re-validate |

No new `pages.json5` rows for V-17.

**Domain capture set:** none (reuse).  
**R-17 fuzzy gate:** `event-index-rsvp,player-profile,reports-voting-eligible`

### 1.2 Canary matrix

| Surface | Variant A | Variant B |
|---------|-----------|-----------|
| Event index + weather | `event-index-rsvp` | — |
| Player profile + awards | `player-profile` | — |
| Voting eligible report | `reports-voting-eligible` | — |
| Weather helpers | T-17 `WeatherServiceTest` | `wx_*` template smoke |
| Unit officer grant | T-UNT-02 domain tests | skip visual |

**Sandbox pins:** event/player ids from sandbox seed.  
**Mirror:** mirror event/player/report data (lenient thresholds).

### 1.3 Record / validate

```bash
bin/fuzzy-validator validate --pages event-index-rsvp,player-profile,reports-voting-eligible --phase all
```

**R-17 sign-off result:** validate exit **0** — **6/6** (3 pages × 2 profiles; **re-recorded baselines**).

---

## 2. Test mutation boundaries

### 2.1 Tests in scope (from T-17)

| Test file | Type | Covers |
|-----------|------|--------|
| `tests/Integration/WeatherServiceTest.php` | Integration | Weather service (template helper backing) |
| `tests/Integration/PlayerProfileTest.php` | Integration | Player model wrappers |
| `tests/Integration/ReportTest.php` | Integration | Report domain |
| `tests/e2e/event-detail.spec.ts` | e2e | Event weather block |
| `tests/e2e/player-profile.spec.ts` | e2e | Profile + circle awards |
| `tests/e2e/reports.spec.ts` | e2e | Voting eligible scope |
| Auth smoke | e2e | Session preflight |

**Infection:** V-14 §2.4 pass A + B.

### 2.2 Expected breakage when code migrates

| Test | Likely failure mode | Root cause |
|------|---------------------|------------|
| `event-detail.spec.ts` | Missing weather block | Template `wx_*` helper wiring |
| `player-profile.spec.ts` | Award list empty | `Model_Player` wrapper regression |
| `reports.spec.ts` | Wrong kingdom scope | `GetParkKingdomId` path |
| Fuzzy event-index | Weather DOM drift | Intentional template helper change |

### 2.3 Acceptable migration boundaries

| Boundary | Allowed during R-17 | Not allowed |
|----------|---------------------|-------------|
| **Controllers** | Model wrappers replace `Ork3::$Lib` domain calls | New lib bypass on migrated files |
| **Templates** | `wx_*` helpers (no `Ork3::$Lib`) | Template domain lib calls |
| **Domain** | Thin wrappers only | SQL semantics change |
| **Fuzzy** | Re-record gate baselines (done at sign-off) | Skip gate pages |
| **Infection** | Pass A ≥ 15%, pass B ≥ 15% | MSI drop without justification |
| **Residual lib** | 41 sites remain for R-19a…d | Claim zero-lib exit |

### 2.4 Post-R-17 Infection scope

```bash
sh bin/run-infection.sh --configuration=tools/infection/infection.t14-lib-auth-era.json5 ...
sh bin/run-infection.sh --configuration=tools/infection/infection.t14-lib-live-weather.json5 ...
```

**R-17 sign-off result:** pass A MSI **18%**, pass B MSI **27%**.

---

## 3. R-17 sign-off checklist

- [x] §1 page ids pass `bin/fuzzy-validator validate --phase all` (test + mirror) — **6/6** (re-recorded)
- [x] Test edits within §2.3
- [x] Full unit suite green — **215/215** (2 skipped)
- [x] Infection per §2.4 — pass A **18%**, pass B **27%**
- [x] Target controllers off `Ork3::$Lib` domain helpers (T-EVT-08, T-KNG-11, T-PRK-05, T-RPT-02, T-UNT-02/03)
- [x] Event/Park/Attendance weather templates use `wx_*` helpers
- [x] Playwright `event-detail.spec.ts` **3/3**, `player-profile.spec.ts` **2/2**, `reports.spec.ts` **4/4** pass

**Branch:** `megiddo/r-17-lib-bypass-refactor` @ `28a2f390`

---

## Related documents

| Doc | Link |
|-----|------|
| Design note | [ds-17-lib-bypass-discovery.md](../ds-17-lib-bypass-discovery.md) |
| Parent inventory | [ds-14-lib-service-discovery.md §1.5](../ds-14-lib-service-discovery.md#15-cross-cutting-other-ork3lib-domains) |
| Phase 3 audit | [phase3-audit-report.md](../phase3-audit-report.md) (41 residual lib sites) |
