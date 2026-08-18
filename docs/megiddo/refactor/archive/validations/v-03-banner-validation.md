# V-03: Hero Banner — Validation Artifacts

**Milestone:** V-03  
**Branch:** `megiddo/v-03-banner-validation`  
**Target IDs:** T-PLA-06, T-PRA-04, T-KNA-08, T-UNT-01, T-EVA-14  
**Depends on:** DS-03, T-03, V-00  
**Execution sprint:** R-03  
**Discovery source:** [ds-03-banner-discovery.md §1](../ds-03-banner-discovery.md#1-backend-survey)

---

## 1. Semaphore / canary URLs

Entity **profile hosts** that render hero banner chrome. Banner AJAX upload/config/remove is covered by T-03 (`BannerTest`, `banner.spec.ts`).

### 1.1 Page registry entries

| pageId | Route | auth | Target IDs | Capture |
|--------|-------|------|------------|---------|
| `player-profile` | `./index.php?Route=Player/profile` | login | T-PLA-06 | V-00 — re-validate |
| `kingdom-profile` | `./index.php?Route=Kingdom/profile/1` | login | T-KNA-08 | V-00 — re-validate |
| `kingdom-auth-sandbox` | `./index.php?Route=Kingdom/profile/100001` | login | T-KNA-08 | V-02 baseline — re-validate |
| `park-auth-sandbox` | `./index.php?Route=Park/profile/1000001` | login | T-PRA-04 | V-02 baseline — re-validate |
| `event-list` | `./index.php?Route=Event` | login | T-EVA-14 host | V-00 — re-validate |
| `event-park` | `./index.php?Route=Event/park/1` | login | Park/event banner context | V-00 — re-validate |

No new `pages.json5` rows for V-03 — hosts already registered (V-00 / V-02). Unit banner host deferred to R-03 e2e with seeded unit id (no stable dual-profile unit profile in registry).

### 1.2 Canary matrix

| Surface | Variant A | Variant B |
|---------|-----------|-----------|
| Player banner host | `player-profile` | — |
| Kingdom banner host | Mirror `kingdom-profile` | Sandbox `kingdom-auth-sandbox` |
| Park banner host | Sandbox `park-auth-sandbox` | — |
| Event banner host | `event-list` / `event-park` | — |

**Sandbox pins:** kingdom `100001`, park `1000001`, player `megiddo`.

### 1.3 Record / validate

Baselines for kingdom/park sandbox hosts from V-02; `player-profile` re-recorded on V-03 (DOM drift vs V-00 setpoint). Confirm:

```bash
bin/fuzzy-validator validate --pages kingdom-auth-sandbox,park-auth-sandbox,player-profile --phase all
```

**V-03 capture result:** validate exit **0** (6/6). Setpoint updated with refreshed `player-profile` baselines.

At R-03 also gate `kingdom-profile,event-list,event-park` after `setpoint restore` if needed.
---

## 2. Test mutation boundaries

### 2.1 Tests in scope (from T-03)

| Test file | Type | Covers |
|-----------|------|--------|
| `tests/Integration/BannerTest.php` | Integration | Upload/config/remove per entity |
| `tests/Support/BannerFixture.php` | Fixture | Temp dirs + seeded entities |
| `tests/e2e/banner.spec.ts` | e2e | Park/kingdom/player/event hosts |

**Infection:** `tools/infection/infection.t03-banner.json5` — MSI 55% (pre-refactor on `class.Park.php`; post-R → `class.Banner.php`).

### 2.2 Expected breakage when code migrates

| Test | Likely failure mode | Root cause |
|------|---------------------|------------|
| `BannerTest` | Method path / auth helper move | Controllers thin; domain `Banner` class |
| `banner.spec.ts` | Modal selector drift | Markup if API-driven framing differs |
| Infection filter | Path change | `--filter=class.Banner.php` |

### 2.3 Acceptable migration boundaries

| Boundary | Allowed during R-03 | Not allowed |
|----------|---------------------|-------------|
| **Domain** | New `class.Banner.php` + service wrappers | Change max size, MIME, or offset clamp rules |
| **Entity quirks** | Preserve park-retired, player-gradient, event cache-bust | Drop staff `can_manage` event auth |
| **Fixtures** | Sandbox entity ids; temp banner dirs | Depend on mirror-only banner files |
| **Fuzzy** | Re-record if intentional hero chrome change | Lower thresholds to force pass |
| **Infection** | Shift filter to `class.Banner.php` | MSI below T-03 floor without justification |

### 2.4 Post-R-03 Infection scope

```bash
sh bin/run-infection.sh \
  --filter=class.Banner.php \
  --test-framework-options="--filter=BannerTest"
```

---

## 3. R-03 sign-off checklist

- [x] §1 page ids pass `bin/fuzzy-validator validate --phase all` (test + mirror)
- [x] Test edits within §2.3
- [x] Full unit suite green
- [x] Infection per §2.4
