# V-18: Residual `$DB` Elimination — Validation Artifacts

**Milestone:** V-18  
**Branch:** `megiddo/v-18-residual-db-validation` (retroactive — backfill 2026-07-10)  
**Target IDs:** All residual `$DB->` in `orkui/`  
**Depends on:** DS-18, T-18, V-00  
**Execution sprint:** R-18  
**Discovery source:** [ds-18-residual-db-discovery.md §1](../ds-18-residual-db-discovery.md#1-backend-survey)

---

## 1. Semaphore / canary URLs

R-18 is a **full regression sweep** — every V-00 active page validates that `$DB` removal did not break render stability across the app.

### 1.1 Page registry entries

Reuse **all V-00 active page ids** (17 pages, no skips). No new `pages.json5` rows for V-18.

| Scope | Source |
|-------|--------|
| Active page set | [v-00-fuzzy-setpoint.md](./v-00-fuzzy-setpoint.md) |
| Per-domain hosts | V-01 … V-14 registered pages |

**R-18 fuzzy gate:** V-00 active pages (full set)

### 1.2 Canary matrix

| Surface | Coverage |
|---------|----------|
| All major interfaces | 17 V-00 active pages × 2 profiles |
| Admin audit log | `admin-permissions` / admin hosts (template SQL removed) |
| Player detail | `player-profile` |
| Event occurrence | `event-index-rsvp` |
| Nav chrome | `home-authenticated` (`nav_view_helpers.php`) |
| Static exit | `rg '\$DB->' orkui/` → zero |

### 1.3 Record / validate

```bash
bin/fuzzy-validator validate --all --phase all
# Equivalently: all V-00 active page ids from v-00-fuzzy-setpoint.md
```

**R-18 sign-off result:** validate exit **0** — **34/34** (17 pages × 2 profiles).

---

## 2. Test mutation boundaries

### 2.1 Tests in scope (from T-18)

| Test file | Type | Covers |
|-----------|------|--------|
| Full PHPUnit suite | Integration + Unit | All prior R-* regression |
| `tests/Integration/PlayerProfileTest.php` | Integration | Player `$DB` paths removed |
| `tests/e2e/auth-permissions.spec.ts` | e2e | Admin paths — 3/3 |
| `tests/e2e/player-profile.spec.ts` | e2e | Player — 2/2 |
| `tests/e2e/event-detail.spec.ts` | e2e | Event — 3/3 |
| Auth smoke | e2e | Session preflight |

**Infection:** Spot-check on `class.Player.php`, `class.DangerAudit.php` (not full V-14 passes).

### 2.2 Expected breakage when code migrates

| Test | Likely failure mode | Root cause |
|------|---------------------|------------|
| Full PHPUnit | Domain API shape mismatch | Controller still expects raw SQL rows |
| Fuzzy any page | DOM missing blocks | Template data not precomputed |
| `auth-permissions.spec.ts` | Admin audit empty | `Admin_auditlog.tpl` SQL → controller |
| Static grep | `$DB->` match | Missed call site in Ajax or template |

### 2.3 Acceptable migration boundaries

| Boundary | Allowed during R-18 | Not allowed |
|----------|---------------------|-------------|
| **Controllers/models** | `JSONModel`/`APIModel` only | New `$DB` in `orkui/` |
| **Templates** | Precomputed arrays | Inline SQL |
| **Domain** | Extend existing services | Duplicate SQL in frontend |
| **Fuzzy** | Full V-00 sweep must pass | Partial gate at sign-off |
| **Infection** | Spot-check MSI on touched classes | Skip regression suite |
| **Residual lib** | 41 `Ork3::$Lib` sites OK for R-18 | Claim full lib-zero exit |

### 2.4 Post-R-18 Infection scope

```bash
sh bin/run-infection.sh \
  --only-covered \
  --filter=class.Player.php \
  --test-framework-options="--filter=PlayerProfileTest|ModelPlayerCacheTest"
# MSI 20%

sh bin/run-infection.sh \
  --only-covered \
  --filter=class.DangerAudit.php \
  --test-framework-options="--filter=DangerAudit"
# MSI 50%
```

---

## 3. R-18 sign-off checklist

- [x] `rg '\$DB->' orkui/` → **zero**
- [x] Full unit suite green — **215/215** (2 skipped)
- [x] Fuzzy V-00 active pages — **34/34** pass
- [x] Infection spot-check — Player MSI **20%**, DangerAudit MSI **50%**
- [x] Playwright `auth-permissions.spec.ts` **3/3**, `player-profile.spec.ts` **2/2**, `event-detail.spec.ts` **3/3** pass
- [x] Phase 2 complete — stack ready for Phase 3 audit

**Branch:** `megiddo/r-18-residual-db-refactor` @ `d3f29fc7`

---

## Related documents

| Doc | Link |
|-----|------|
| Design note | [ds-18-residual-db-discovery.md](../ds-18-residual-db-discovery.md) |
| V-00 setpoint | [v-00-fuzzy-setpoint.md](./v-00-fuzzy-setpoint.md) |
| Phase 3 close-out | [11-phase-3-closeout.md](../11-phase-3-closeout.md) |
