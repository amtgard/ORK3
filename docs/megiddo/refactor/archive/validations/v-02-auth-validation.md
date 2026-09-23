# V-02: Authorization INSERT — Validation Artifacts

**Milestone:** V-02  
**Branch:** `megiddo/v-02-auth-validation`  
**Target IDs:** T-ADM-11, T-KNA-03, T-PRA-02, T-EVA-06 (addauth)  
**Depends on:** DS-02, T-02, V-00  
**Execution sprint:** R-02  
**Discovery source:** [ds-02-auth-insert-discovery.md §1](../ds-02-auth-insert-discovery.md#1-backend-survey)

---

## 1. Semaphore / canary URLs

Permissions **host pages** for fuzzy render stability. AJAX `*/addauth` JSON is covered by T-02 (`AuthorizationAddTest`, `auth-permissions.spec.ts`), not visual capture.

### 1.1 Page registry entries

| pageId | Route | auth | Target IDs | Capture |
|--------|-------|------|------------|---------|
| `admin-permissions` | `./index.php?Route=Admin/permissions` | login | T-ADM-11 | V-00 setpoint — **re-validate** |
| `kingdom-profile` | `./index.php?Route=Kingdom/profile/1` | login | T-KNA-03 | V-00 setpoint — **re-validate** |
| `kingdom-auth-sandbox` | `./index.php?Route=Kingdom/profile/100001` | login | T-KNA-03 | **V-02 record** (Ashkara) |
| `park-auth-sandbox` | `./index.php?Route=Park/profile/1000001` | login | T-PRA-02 | **V-02 record** (Silver Fen) |
| `event-list` | `./index.php?Route=Event` | login | T-EVA-06 host | V-00 — **re-validate** |

**Domain capture set:** `kingdom-auth-sandbox,park-auth-sandbox`  
**R-02 fuzzy gate:** those two + `admin-permissions,kingdom-profile,event-list`

### 1.2 Canary matrix

| Surface | Variant A | Variant B |
|---------|-----------|-----------|
| Global permissions | `admin-permissions` | — |
| Kingdom permissions host | Mirror id `1` (`kingdom-profile`) | Sandbox `100001` (`kingdom-auth-sandbox`) |
| Park permissions host | Sandbox `1000001` (`park-auth-sandbox`) | Mirror `Park/profile/1` via e2e only (`park-profile` skip) |
| Event permissions host | `event-list` → event admin UI | — |

**Sandbox pins:** kingdom `100001`, park `1000001`, login `megiddo`.  
**Mirror:** sandbox-namespace URLs render home chrome; baselines per profile.

### 1.3 Record baselines

```bash
bin/fuzzy-validator record --pages kingdom-auth-sandbox,park-auth-sandbox --phase all --profiles test,mirror
bin/fuzzy-validator validate --pages kingdom-auth-sandbox,park-auth-sandbox --phase all
```

**V-02 capture result:** validate exit **0** on domain ids (test + mirror).  
**RB-F (2026-07-09):** `park-auth-sandbox` / `kingdom-auth-sandbox` re-captured in full setpoint `20260709T173049Z-1591950d-6b22e991bb478256.zip` after sandbox refresh dimension drift.  
At R-02, also re-validate V-00 hosts after `setpoint restore` if local baselines drifted:

```bash
bin/fuzzy-validator validate --pages admin-permissions,kingdom-profile,event-list,kingdom-auth-sandbox,park-auth-sandbox --phase all
```

---

## 2. Test mutation boundaries

### 2.1 Tests in scope (from T-02)

| Test file | Type | Covers |
|-----------|------|--------|
| `tests/Integration/AuthorizationAddTest.php` | Integration | AddAuthorization all scopes |
| `tests/Support/AuthorizationAddFixture.php` | Fixture | Seeded grantors/grantees |
| `tests/e2e/auth-permissions.spec.ts` | e2e | Permissions route smoke |

**Infection:** `tools/infection/infection.t02-auth-insert.json5` — MSI 42% on `class.Authorization.php`.

### 2.2 Expected breakage when code migrates

| Test | Likely failure mode | Root cause |
|------|---------------------|------------|
| `AuthorizationAddTest` | Still green if tests call service | Controllers thin; tests already on API |
| Controller-level HTTP tests (if added) | JSON status mapping | Raw INSERT → `add_auth` Status codes |
| `auth-permissions.spec.ts` | Selector drift | Permissions tpl markup |

### 2.3 Acceptable migration boundaries

| Boundary | Allowed during R-02 | Not allowed |
|----------|---------------------|-------------|
| **Controllers** | Replace INSERT with `Model_Authorization::add_auth` | Change grant semantics / role whitelist |
| **Admin audit** | Add missing danger-audit on global grant | Drop audit on kingdom/park/event |
| **Fixtures** | Sandbox kingdom/park ids `100001` / `1000001` | Mirror-only auth rows without doc |
| **Fuzzy** | Re-record on intentional permissions UI change | Widen thresholds to force pass |
| **Infection** | Keep `--filter=class.Authorization.php` | MSI below T-02 floor without justification |

### 2.4 Post-R-02 Infection scope

```bash
sh bin/run-infection.sh \
  --filter=class.Authorization.php \
  --test-framework-options="--filter=AuthorizationAddTest"
```

---

## 3. R-02 sign-off checklist

- [x] §1 page ids pass `bin/fuzzy-validator validate --phase all` (test + mirror)
- [x] Test edits within §2.3
- [x] Full unit suite green
- [x] Infection per §2.4
- [x] `rg 'INSERT INTO.*authorization' orkui/` → zero matches
