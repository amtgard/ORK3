# V-{NN}: {Domain} — Validation Artifacts

**Milestone:** V-{NN}  
**Branch:** `megiddo/v-{nn}-{slug}`  
**Target IDs:** *(from [03-implementation-plan.md](../03-implementation-plan.md))*  
**Depends on:** DS-{NN}, T-{NN}, V-00  
**Execution sprint:** R-{NN}  
**Discovery source:** `ds-{nn}-{slug}-discovery.md` §1

---

## 1. Semaphore / canary URLs

Fuzzy-validator and Playwright **render stability** checks for this domain. List **2–4 URL variants** per feature surface (query params, entity ids, auth context) that exercise the refactor targets without covering the entire app.

### 1.1 Page registry entries

Add rows to `tools/fuzzy-validator/manifests/pages.json5` (or extend existing T-* e2e routes):

| pageId | Route | auth | Feature / target IDs | Notes |
|--------|-------|------|----------------------|-------|
| | `./index.php?Route=…` | none \| login | T-*-… | |

### 1.2 Canary matrix

| Surface | Variant A | Variant B | Variant C | Variant D |
|---------|-----------|-----------|-----------|-----------|
| *(feature name)* | | | | |

**Sandbox stability:** Entity ids must resolve in `ork_test` after `bin/ork-db deploy-sandbox` (document pinned ids from [test-database-tool](../../test-database-tool/02-data-model.md)).

**Mirror stability:** Document which variants require mirror-only data (real kingdom/park ids).

### 1.3 Record baselines (both profiles)

After registry merge on a **stable commit**:

```bash
bin/ork-db deploy-sandbox
bin/fuzzy-validator record --pages <comma-separated-ids> --phase all --profile test
bin/ork-db use prod   # mirror
bin/fuzzy-validator record --pages <comma-separated-ids> --phase all --profile mirror
```

Commit: `baselines/test/`, `baselines/mirror/`, `manifests/{profile}/*.fuzz.json`, `*.dom-fuzz.json`, and asset manifests — or publish via `setpoint capture` (FU-16).

---

## 2. Test mutation boundaries

When logic moves from `orkui/` to `system/lib/ork3/` + `orkservice/*`, **existing tests will break or need relocation**. Document expected breakage and **acceptable migration** before R-{NN} starts.

### 2.1 Tests in scope

| Test file | Type | Covers target IDs | Pre-refactor role |
|-----------|------|-------------------|-------------------|
| | Unit / Integration / e2e | | |

### 2.2 Expected breakage when code migrates

| Test | Likely failure mode | Root cause |
|------|---------------------|------------|
| | Assert on SQL side effect / HTTP shape / mock path | Frontend test coupled to implementation location |

### 2.3 Acceptable migration boundaries

Document what R-{NN} **may** change vs **must not** change:

| Boundary | Allowed during R-{NN} | Not allowed (regression) |
|----------|----------------------|---------------------------|
| **Assertion target** | Move integration test to call `*Service` API instead of controller SQL | Change expected business outcome |
| **Fixture data** | Use sandbox `ork_test` rows; update pinned ids if sandbox template changes | Depend on mutable mirror-only rows without doc update |
| **Infection scope** | Shift `--filter` to new domain class path | Lower MSI below milestone threshold without justification |
| **e2e behavior** | Update selectors only if markup equivalent | Skip auth-gated flows without credentials |
| **Fuzzy gate** | Re-`record` baselines only for intentional UI change | Lower profile thresholds to force pass |

### 2.4 Post-R Infection scope

```bash
sh bin/run-infection.sh --filter=<new-domain-path> --test-framework-options="--filter=<TestClass>"
```

---

## 3. R-{NN} sign-off checklist (consumer)

- [ ] Canary page ids from §1 pass `bin/fuzzy-validator validate --phase all` on **test** and **mirror**
- [ ] Test changes stay within §2.3 boundaries
- [ ] Full unit suite green (DS-4)
- [ ] Milestone-scoped Infection per §2.4
