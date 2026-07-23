# V-09: Player Profile & AJAX — Validation Artifacts

**Milestone:** V-09  
**Branch:** `megiddo/v-09-player-validation`  
**Target IDs:** T-PLR-01 through T-PLR-08, T-PLA-01 through T-PLA-05, T-PLM-01 through T-PLM-04 (excl. T-PLA-06 → V-03)  
**Depends on:** DS-09, T-09, V-00  
**Execution sprint:** R-09  
**Discovery source:** [ds-09-player-discovery.md §1](../ds-09-player-discovery.md#1-backend-survey)

---

## 1. Semaphore / canary URLs

Player **profile hosts** (logged-in default + sandbox pinned id). AJAX (username, merge, email, award ranks) is covered by T-09 unit/integration + `player-profile.spec.ts` — no visual canaries for JSON endpoints.

### 1.1 Page registry entries

| pageId | Route | auth | Target IDs | Capture |
|--------|-------|------|------------|---------|
| `player-profile` | `./index.php?Route=Player/profile` | login | T-PLR-01–08 | V-00 / V-03 — re-validate / refresh if drifted |
| `player-profile-sandbox` | `./index.php?Route=Player/profile/1` | login | T-PLR-* sandbox pin | V-00 — re-validate |

No new `pages.json5` rows for V-09.

**Domain capture set:** none (reuse); refresh if validate drifts.  
**R-09 fuzzy gate:** `player-profile,player-profile-sandbox`

### 1.2 Canary matrix

| Surface | Variant A | Variant B |
|---------|-----------|-----------|
| Player profile (self) | `player-profile` | — |
| Player profile (pinned id) | Sandbox `player-profile-sandbox` (id `1`) | Mirror id `1` (may differ persona) |
| Profile AJAX | T-09 `PlayerAjaxTest` + e2e | no visual canary |
| Reconcile / merge | T-09 integration | no visual canary |

**Sandbox pins:** player id `1`, login `megiddo` / `test-db-player`.  
**Mirror:** credentials via `profiles.json5` (FU-11); profile id `1` is mirror mundane.

### 1.3 Record / validate

```bash
bin/fuzzy-validator validate --pages player-profile,player-profile-sandbox --phase all
```

**V-09 capture result:** validate exit **0** (4/4). Re-recorded `player-profile-sandbox` (test DOM drift).

---

## 2. Test mutation boundaries

### 2.1 Tests in scope (from T-09)

| Test file | Type | Covers |
|-----------|------|--------|
| `tests/Integration/PlayerProfileTest.php` | Integration | Custom title, notes count, officers, grants, beltline, reconcile map |
| `tests/Integration/PlayerAjaxTest.php` | Integration | Username, award ranks, merge auth, save email, add second |
| `tests/Unit/ModelPlayerCacheTest.php` | Unit | Roster cache bust, EditNote path, milestones/dates |
| `tests/e2e/player-profile.spec.ts` | e2e | Profile smoke |

**Infection:** `tools/infection/infection.t09-player.json5` — batched MSI ≥15% (Player profile+cache, Player AJAX, Authorization).

### 2.2 Expected breakage when code migrates

| Test | Likely failure mode | Root cause |
|------|---------------------|------------|
| `PlayerProfileTest` | Methods move to Player / Report domain APIs | Profile SQL leaves `controller.Player` |
| `PlayerAjaxTest` | SOAP/JSON path or response shape | Username/email/merge leave Ajax `$DB` |
| `ModelPlayerCacheTest` | Cache keys / EditNote via service | Model bypasses removed |
| `player-profile.spec.ts` | Selector drift | Profile markup / tab visibility |
| Cross-sprint voting badge / banner | Out of R-09 | T-PLA-06 → R-03; voting → R-10 |

### 2.3 Acceptable migration boundaries

| Boundary | Allowed during R-09 | Not allowed |
|----------|---------------------|-------------|
| **Domain** | Player profile reads, CheckUsername, SaveOwnEmail, beltline/officers APIs | Change merge auth tier semantics without test update |
| **Cross-sprint** | Call R-03 banner; R-10 voting badge; R-14 HasAuthority helpers | Re-implement banner CRUD or voting rules |
| **Fixtures** | Sandbox player id `1`; `megiddo` login | Mirror-only mundane ids without doc |
| **Fuzzy** | Re-record on intentional profile UI change | Lower thresholds to force pass |
| **Infection** | Keep batched filters; add new domain classes | MSI below T-09 floors without justification |

### 2.4 Post-R-09 Infection scope

```bash
sh bin/run-infection.sh \
  --configuration=tools/infection/infection.t09-player.json5 \
  --only-covered \
  --filter=class.Player.php \
  --filter=class.Authorization.php \
  --filter=class.Report.php \
  --test-framework-options="--filter=PlayerProfileTest|PlayerAjaxTest|ModelPlayerCacheTest"
```

---

## 3. R-09 sign-off checklist

- [x] §1 page ids pass `bin/fuzzy-validator validate --phase all` (test + mirror)
- [x] Test edits within §2.3
- [x] Full unit suite green
- [x] Infection per §2.4
- [x] No new `$DB` in `controller.Player` / `controller.PlayerAjax` / `model.Player` for migrated T-PLR/T-PLA/T-PLM targets
