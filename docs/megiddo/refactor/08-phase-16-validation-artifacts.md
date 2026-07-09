# Megiddo Refactor — Phase 1.6: Validation Artifacts

**Status:** Plan  
**Tools:** [ork-db](../test-database-tool/README.md) (sandbox + mirror DB) · [fuzzy-validator](../fuzzy-validator/README.md) (render stability gate)

Phase 1.6 sits **after Phase 1.5 (T-*)** and **before Phase 2 (R-*)**. It produces the artifacts R-* agents consume for **dual-database fuzzy validation** and **documented test migration boundaries**.

---

## 1. Why Phase 1.6

| Gap before 1.6 | Phase 1.6 closes it |
|----------------|---------------------|
| T-* tests lock behavior but not full-page render stability | Canary URLs + fuzzy baselines on **test** and **mirror** |
| R-* may relocate tests when code moves to domain layer | Documented **mutation boundaries** — what may change vs regression |
| Fuzzy-validator has pilot pages only (FU-1) | Expanded registry = **global setpoint** + per-domain canaries |
| Mirror vs sandbox differ in data shape | Every validate/record runs both profiles (FU-11) |

Discovery (DS-*) and test implementation (T-*) remain complete for their phases. Phase 1.6 **does not** re-do survey or test writing — it adds **validation registries and sign-off contracts** for execution.

---

## 2. Milestone map

| ID | Branch | Deliverable | Blocks |
|----|--------|-------------|--------|
| **V-00** | `megiddo/v-00-fuzzy-setpoint` | Global major-interface URL registry + dual-profile baselines | All R-* fuzzy gates |
| **V-01 … V-14** | `megiddo/v-{nn}-{slug}` | Per-domain canary URLs + test mutation doc | Matching R-{nn} |

**Parallelism:** V-01 … V-14 are independent once **V-00** is done (and matching DS-{nn} + T-{nn} are complete).

**Naming:** `V-*` = **validation artifacts** (not refactor target IDs like `T-RSV-01`).

---

## 3. Global preflight — V-00

Two sequential steps on one milestone branch.

### Preflight step 1 — Register fuzzy setpoint URLs

Define **1–3 sample URLs per major interface class** across the app. These are the long-lived “setpoint” surfaces — not every refactor target, but the pages that must never drift silently.

| Class | Example routes | Count |
|-------|----------------|-------|
| Home / index | `Route=` anonymous + authenticated | 1–2 |
| Kingdom | profile, config, permissions | 1–3 |
| Park | profile, attendance | 1–3 |
| Player | profile, admin | 1–3 |
| Event | detail, calendar, RSVP widget host | 1–3 |
| Admin | dashboard, permissions | 1–3 |
| Reports | ladder, awards entry | 1–3 |
| Search | universal, unit | 1–2 |
| Infrastructure | health (optional text-only skip) | 0–1 |

**Output:**

- Rows in `tools/fuzzy-validator/manifests/pages.json5`
- Spec: [validations/v-00-fuzzy-setpoint.md](./validations/v-00-fuzzy-setpoint.md)

### Preflight step 2 — Capture dual-profile baselines

On a **stable commit** (integration branch before R-* work):

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox

# test profile (ork_test @ 19307)
bin/ork-db use dev
export ORK3_E2E_USERNAME=megiddo ORK3_E2E_PASSWORD=test-db-player
bin/fuzzy-validator record --all --phase all --profile test

# mirror profile (ork @ 19306) — local docker only
bin/ork-db use prod
export ORK3_E2E_USERNAME=admin ORK3_E2E_PASSWORD=password
bin/fuzzy-validator record --all --phase all --profile mirror
```

Review overlays under `tools/fuzzy-validator/reports/`; commit manifests + publish setpoint (FU-16) or commit baselines per profile.

**Tooling status:** FU-4 (page registry), FU-11 (dual profiles), FU-12–15 (sandbox evidence suite), and FU-16 (setpoint zip) are complete — see [fuzzy-validator checklist](../fuzzy-validator/08-milestone-checklist.md).

---

## 4. Per-domain milestones — V-01 … V-14

Each **V-{nn}** pairs with **DS-{nn}**, **T-{nn}**, and **R-{nn}**.

### Workflow

1. **Canary URLs (§1)** — 2–4 variants per feature surface targeted for refactor (query strings, entity ids, auth). Register in `pages.json5`; pin sandbox entity ids.
2. **Test mutation boundaries (§2)** — List tests from T-{nn}; document how they break when code migrates; define acceptable changes for R-{nn}.
3. **Record domain baselines** — `bin/fuzzy-validator record --pages …` on **test** + **mirror** (subset of §1 ids).
4. **Link** — Add row to [validations/README.md](./validations/README.md); cross-link from matching `ds-{nn}-*.md` footer.

**Output file:** `docs/megiddo/refactor/validations/v-{nn}-{slug}-validation.md`  
**Template:** [validations/_template-validation.md](./validations/_template-validation.md)

### Example domain mapping

| V-* | DS / T / R | Primary surfaces |
|-----|------------|------------------|
| V-01 | RSVP | Event detail RSVP, player upcoming, home widget |
| V-06 | Kingdom | Kingdom profile, kingdom admin ajax hosts |
| V-08 | Admin | Admin index, global permissions |
| V-14 | Lib-service | Live stats, weather widgets |

---

## 5. R-* consumption

At **R-{nn} sign-off**, the agent must:

1. Read [validations/v-{nn}-*.md](./validations/) §2 — stay within test migration boundaries.
2. Run **full** PHPUnit suite (DS-4).
3. Run milestone-scoped **Infection** on refactored paths (DS-7).
4. Run **`bin/fuzzy-validator validate --pages <§1 ids> --phase all`** — pass **test** (strict) and **mirror** (lenient).
5. Open `tools/fuzzy-validator/reports/run-*/index.html` on failure.

E2E credentials: [06-test-framework.md § E2E login preflight](./06-test-framework.md#e2e-login-credentials-preflight).

---

## 6. Agent execution order

```
M0.1 → M0.2 → DS-01…14 → T-01…14 → V-00 → V-01…14 (parallel) → R-01…14
```

- **V-00** once before domain V-* or in parallel with early V-* (domain records may depend on global setpoint registry merge).
- **R-{nn}** requires **V-00**, **V-{nn}**, and matching **DS-{nn}** + **T-{nn}**.

---

## 7. Related documents

| Doc | Role |
|-----|------|
| [04-milestone-checklist.md](./04-milestone-checklist.md) | V-* checkboxes |
| [07-agent-milestone-prompt.md](./07-agent-milestone-prompt.md) | Agent process for V-* and R-* |
| [06-test-framework.md](./06-test-framework.md) | PHPUnit, Infection, E2E preflight, fuzzy gate |
| [../fuzzy-validator/11-dual-database-profiles.md](../fuzzy-validator/11-dual-database-profiles.md) | test vs mirror thresholds |
| [../test-database-tool/README.md](../test-database-tool/README.md) | Sandbox deploy |
