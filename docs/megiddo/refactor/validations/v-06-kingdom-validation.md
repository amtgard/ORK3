# V-06: Kingdom Profile & AJAX — Validation Artifacts

**Milestone:** V-06  
**Branch:** `megiddo/v-06-kingdom-validation`  
**Target IDs:** T-KNG-01 through T-KNG-11, T-KNA-01, T-KNA-02, T-KNA-04 through T-KNA-07  
**Depends on:** DS-06, T-06, V-00  
**Execution sprint:** R-06  
**Discovery source:** [ds-06-kingdom-discovery.md §1](../ds-06-kingdom-discovery.md#1-backend-survey)

---

## 1. Semaphore / canary URLs

Kingdom **profile hosts** (mirror id `1` + sandbox Ashkara). AJAX feeds (`park_averages_json`, `players_json`, `calendar`) and ICS download are covered by T-06 — ICS stays `skip: true` (non-visual).

### 1.1 Page registry entries

| pageId | Route | auth | Target IDs | Capture |
|--------|-------|------|------------|---------|
| `kingdom-profile` | `./index.php?Route=Kingdom/profile/1` | login | T-KNG-05–09 | V-00 — re-validate |
| `kingdom-auth-sandbox` | `./index.php?Route=Kingdom/profile/100001` | login | T-KNG-* sandbox | V-02 — re-validate |
| `kingdom-ics` | `./index.php?Route=Kingdom/ics/1` | login | T-KNG-10 | skip (download) |

No new `pages.json5` rows for V-06.

**Domain capture set:** none (reuse).  
**R-06 fuzzy gate:** `kingdom-profile,kingdom-auth-sandbox`

### 1.2 Canary matrix

| Surface | Variant A | Variant B |
|---------|-----------|-----------|
| Kingdom profile | Mirror id `1` | Sandbox Ashkara `100001` |
| ICS export | `kingdom-ics` skip | `KingdomProfileTest` / e2e |
| Calendar / roster AJAX | T-06 integration + e2e | — |

**Sandbox pins:** kingdom `100001`, login `megiddo`.  
**Mirror:** kingdom `1` for `kingdom-profile`; sandbox id → home chrome on mirror.

### 1.3 Record / validate

```bash
bin/fuzzy-validator validate --pages kingdom-profile,kingdom-auth-sandbox --phase all
```

**V-06 capture result:** validate exit **0** (4/4). Re-recorded `kingdom-profile` (mirror DOM drift: averages + Era Phoenice date).

---

## 2. Test mutation boundaries

### 2.1 Tests in scope (from T-06)

| Test file | Type | Covers |
|-----------|------|--------|
| `tests/Integration/KingdomProfileTest.php` | Integration | Event summary, park days, roster, averages, ICS |
| `tests/Integration/KingdomAjaxTest.php` | Integration | Move player, AwardRecsPublic, abbr, calendar, suspend |
| `tests/e2e/kingdom-profile.spec.ts` | e2e | Profile + ICS smoke |

**Infection:** `infection.t06-kingdom.json5` — MSI 43% on `class.Kingdom.php` + `class.Report.php`.

### 2.2 Expected breakage when code migrates

| Test | Likely failure mode | Root cause |
|------|---------------------|------------|
| `KingdomProfileTest` | Methods move to `KingdomProfile` | Event/roster/averages leave controller |
| `KingdomAjaxTest` | Config / calendar path | AwardRecsPublic + FullCalendar DTO |
| `kingdom-profile.spec.ts` | Selector / lazy tab | Profile markup after DTO refactor |
| Cross-sprint auth/banner/search | Out of R-06 | T-KNA-03/06/08 owned elsewhere |

### 2.3 Acceptable migration boundaries

| Boundary | Allowed during R-06 | Not allowed |
|----------|---------------------|-------------|
| **Domain** | `KingdomProfile` + Report extended averages | Change draft visibility or RSVP aggregate semantics |
| **Shared event query** | Parametrize for R-07 park scope | Duplicate kingdom event SQL in Park |
| **Fixtures** | Sandbox kingdom `100001` | Mirror-only kingdom ids without doc |
| **Fuzzy** | Re-record on intentional kingdom UI change | Widen thresholds to force pass |
| **Infection** | Add `--filter=class.KingdomProfile.php` | MSI below T-06 floor without justification |

### 2.4 Post-R-06 Infection scope

```bash
sh bin/run-infection.sh \
  --filter=class.Kingdom.php \
  --filter=class.KingdomProfile.php \
  --filter=class.Report.php \
  --test-framework-options="--filter=KingdomProfileTest|KingdomAjaxTest"
```

---

## 3. R-06 sign-off checklist

- [x] §1 page ids pass `bin/fuzzy-validator validate --phase all` (test + mirror)
- [x] Test edits within §2.3
- [x] Full unit suite green
- [x] Infection per §2.4
- [x] No new `$DB` in `Controller_Kingdom` / `Controller_KingdomAjax` for migrated targets
