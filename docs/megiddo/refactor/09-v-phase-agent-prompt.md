# Megiddo Refactor — Phase 1.6 (V-*) Agent Prompt

Copy the prompt below into a new agent session. Replace **`{{BATCH}}`** or **`{{MILESTONE}}`** to scope work. Default: execute the **next unchecked batch** from the table below.

**Scope:** Validation artifacts only — no DS-* discovery, no T-* test writing, no R-* refactor.

---

## Prompt (copy from here)

```
You are executing **Megiddo Phase 1.6 (V-*)** — validation artifacts for refactor execution. You produce:

1. **Canary / semaphore URLs** (2–4 variants per feature surface) registered in `tools/fuzzy-validator/manifests/pages.json5`
2. **Test mutation boundary docs** — how matching T-* tests may change during R-* without hiding regressions
3. **Dual-profile fuzzy baselines** — `record` or `setpoint capture` on **test** (sandbox) and **mirror** (local prod-shaped DB)

You do **not** refactor `orkui/`, re-run DS-* surveys, or implement new T-* characterization tests.

## Tools (already implemented — use them)

| Tool | Entry | Role |
|------|-------|------|
| Sandbox DB | `bin/ork-db deploy-sandbox` · `bin/ork-db use dev` | **test** profile → `ork_test` @ 19307 |
| Mirror DB | `bin/ork-db use prod` | **mirror** profile → `ork` @ 19306 |
| Fuzzy validator | `bin/fuzzy-validator record|validate|setpoint` | Capture, gate, zip setpoints (FU-4…FU-16 complete) |

## Documentation (read before work)

| Doc | Path |
|-----|------|
| Phase 1.6 plan | `docs/megiddo/refactor/08-phase-16-validation-artifacts.md` |
| Milestone checklist | `docs/megiddo/refactor/04-milestone-checklist.md` § Phase 1.6 |
| Validation index | `docs/megiddo/refactor/validations/README.md` |
| Template | `docs/megiddo/refactor/validations/_template-validation.md` |
| V-00 spec | `docs/megiddo/refactor/validations/v-00-fuzzy-setpoint.md` |
| E2E login preflight | `docs/megiddo/refactor/06-test-framework.md` § E2E login credentials |
| Fuzzy CLI | `docs/megiddo/fuzzy-validator/10-cli-reference.md` |
| Dual profiles | `docs/megiddo/fuzzy-validator/11-dual-database-profiles.md` |
| Setpoints (FU-16) | `docs/megiddo/fuzzy-validator/04-operating-guide.md` § Setpoint |
| User guide | `docs/megiddo/fuzzy-validator/USER-GUIDE.md` |

**Inputs (read-only):** matching `docs/megiddo/refactor/ds-{nn}-*-discovery.md` (§1 scope, §2 test list) and T-* test paths cited in checklist — do not rewrite those tests.

## Steering (non-negotiable)

From `docs/megiddo/refactor/05-development-steering.md` — apply what fits V-* (docs + registry + baselines, minimal PHP if any):

- **DS-3:** One branch per **V-* milestone** (`megiddo/v-00-fuzzy-setpoint`, `megiddo/v-{nn}-{slug}`).
- **DS-6:** Exactly **one squashed commit** per V-* branch.
- **DS-8:** Commit title matches milestone: `V-03: Banner validation artifacts and fuzzy canaries`.
- **Doc sign-off:** All checklist + validation doc updates in the same commit as deliverables.
- **E2E preflight:** Complete before any auth-gated capture — sandbox `megiddo` / `test-db-player`; mirror via `ORK3_E2E_*`. Never use `class.Authorization.php` bypass.
- **DS-4/DS-5:** Full PHPUnit suite must pass before each commit if you touch non-doc files (e.g. `pages.json5` loader tests). Doc-only V-* commits may skip if no code changed.

**Out of scope:** R-* refactor, new T-* tests, FU-* tool development, amending completed DS/T branches.

## Batch to execute

G-3

**Default if neither placeholder is set:** Run the **first batch with unchecked items** in order: **G0 → G1 → G2 → G3 → G4** (see batch table below). Within a batch, execute V-* milestones **sequentially** — each gets its own branch and commit before starting the next in the batch.

### Batch groups (3–4 milestones per agent session)

| Batch | Milestones | Domains | Prerequisite |
|-------|------------|---------|--------------|
| **G0** | V-00 | Global fuzzy setpoint (all major interface classes) | T-14 complete |
| **G1** | V-01, V-02, V-03, V-04 | RSVP, auth INSERT, banners, EventAjax | **V-00** |
| **G2** | V-05, V-06, V-07, V-08 | Event, kingdom, park, admin | **V-00** |
| **G3** | V-09, V-10, V-11, V-12 | Player, reports, search, attendance | **V-00** |
| **G4** | V-13, V-14 | Infrastructure, lib-service | **V-00** |

Single-milestone override: set `{{MILESTONE}}` to e.g. `V-05` to run only that id (still one branch, one commit).

State at start: batch id, list of V-* ids, branch name(s), and matching DS/T/R numbers.

---

## Process (follow in order)

### 1. Confirm prerequisites

- Open `04-milestone-checklist.md` § Phase 1.6 — verify batch milestones are unchecked.
- **G1–G4:** Confirm **V-00** is checked complete (or execute G0 first).
- **V-01+:** Confirm matching **DS-{nn}** and **T-{nn}** are complete (they are for all 14 domains today).
- Read `08-phase-16-validation-artifacts.md` and each active validation spec (or `_template-validation.md` for new docs).

### 2. Environment preflight (once per session)

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox
export ORK3_E2E_BASE_URL=http://127.0.0.1:19080/orkui/
```

Credentials are profile-specific via `tools/fuzzy-validator/manifests/profiles.json5` (FU-11). For manual Playwright/debug, export matching env vars per `06-test-framework.md`.

### 3. For each V-* in the batch (sequential loop)

#### 3a. Branch

- Base branch: previous V-* branch in sequence, or current integration line (e.g. `megiddo/fu-16-setpoint` / `megiddo/t-14-lib-service-tests`) if starting G0.
- Create `megiddo/v-{nn}-{slug}` from checklist table.

#### 3b. V-00 only — global setpoint

1. **Preflight 1:** Audit/extend `tools/fuzzy-validator/manifests/pages.json5` — 1–3 URLs per class (home, kingdom, park, player, event, admin, reports, search). FU-4 registry is the baseline; fill gaps per [v-00-fuzzy-setpoint.md](./validations/v-00-fuzzy-setpoint.md).
2. **Preflight 2:** Capture on stable commit:
   ```bash
   bin/fuzzy-validator setpoint capture    # preferred (FU-16): both profiles, all pages
   bin/fuzzy-validator setpoint publish      # updates setpoint.json pointer
   ```
   Or per-profile: `record --all --phase all --profile test` then `--profile mirror`.
3. Verify: `bin/fuzzy-validator validate --all --phase all` passes **test** + **mirror** on same commit.
4. Update [v-00-fuzzy-setpoint.md](./validations/v-00-fuzzy-setpoint.md) exit criteria checkboxes.

#### 3c. V-01 … V-14 — domain validation

For each domain `{nn}`:

1. **Read** `ds-{nn}-*-discovery.md` §1 (surfaces) and §2 (existing tests). Read T-* test paths from checklist.
2. **Write** `validations/v-{nn}-{slug}-validation.md` from [_template-validation.md](./validations/_template-validation.md):
   - **§1:** 2–4 canary URL variants per refactor feature; pin sandbox entity ids; register `pageId`s in `pages.json5`.
   - **§2:** List T-* tests; expected breakage when logic migrates; **§2.3 acceptable boundaries** for R-{nn}.
3. **Capture** domain page ids only:
   ```bash
   bin/fuzzy-validator record --pages <ids> --phase all --profiles test,mirror
   ```
   Or append to setpoint if maintainer workflow prefers one global zip after batch (document in validation doc).
4. **Validate:** `bin/fuzzy-validator validate --pages <ids> --phase all` — both profiles exit 0.
5. **Link:** Update [validations/README.md](./validations/README.md); add footer link in matching `ds-{nn}-*.md`.

#### 3d. Checklist + commit (per V-*)

- Check off validation sprint items in `04-milestone-checklist.md` for this V-* id.
- Fill sign-off row if present (date, commit hash).
- Stage: validation doc, `pages.json5` changes, manifests/setpoint pointer, checklist, any ds-* footer links.
- **One squashed commit** on the V-* branch only.
- Report: V-* id, branch, commit hash, page ids, validate exit codes (test/mirror), blockers.

Then continue to the next V-* in the batch (fresh branch from the commit you just made).

### 4. Batch completion report

After all V-* in the batch:

| Field | Value |
|-------|-------|
| Batch | G0 … G4 |
| Milestones completed | V-* list |
| Commits | one hash per V-* |
| Setpoint bundle | `setpoint.json` `latestBundle` if updated |
| Validate summary | pass/fail per profile per domain |
| Next batch | e.g. G2 if G1 done |

Do not merge or push unless explicitly asked.

---

Begin with step 1 now.
```

---

## Placeholder reference

| Placeholder | Example |
|-------------|---------|
| `{{BATCH}}` | `G0` · `G1` · `G2` · `G3` · `G4` |
| `{{MILESTONE}}` | `V-00` · `V-05` · `V-01,V-02,V-03,V-04` (explicit subset) |

**Examples:**

```
{{BATCH}} = G0
→ V-00 only; branch megiddo/v-00-fuzzy-setpoint

{{BATCH}} = G1
→ V-01, V-02, V-03, V-04 sequentially; four branches, four commits

{{MILESTONE}} = V-06
→ Kingdom validation only
```

---

## Milestone quick map

| ID | Branch | Validation doc | DS / T / R |
|----|--------|----------------|------------|
| V-00 | `megiddo/v-00-fuzzy-setpoint` | [v-00-fuzzy-setpoint.md](./validations/v-00-fuzzy-setpoint.md) | All R-* |
| V-01 | `megiddo/v-01-rsvp-validation` | [v-01-rsvp-validation.md](./validations/v-01-rsvp-validation.md) | R-01 |
| V-02 | `megiddo/v-02-auth-validation` | *(create)* | R-02 |
| V-03 | `megiddo/v-03-banner-validation` | *(create)* | R-03 |
| V-04 | `megiddo/v-04-eventajax-validation` | *(create)* | R-04 |
| V-05 | `megiddo/v-05-event-validation` | *(create)* | R-05 |
| V-06 | `megiddo/v-06-kingdom-validation` | *(create)* | R-06 |
| V-07 | `megiddo/v-07-park-validation` | *(create)* | R-07 |
| V-08 | `megiddo/v-08-admin-validation` | *(create)* | R-08 |
| V-09 | `megiddo/v-09-player-validation` | *(create)* | R-09 |
| V-10 | `megiddo/v-10-reports-validation` | *(create)* | R-10 |
| V-11 | `megiddo/v-11-search-validation` | *(create)* | R-11 |
| V-12 | `megiddo/v-12-attendance-validation` | *(create)* | R-12 |
| V-13 | `megiddo/v-13-infrastructure-validation` | *(create)* | R-13 |
| V-14 | `megiddo/v-14-lib-service-validation` | *(create)* | R-14 |

---

## Per-milestone deliverables checklist

| # | Deliverable | V-00 | V-01…14 |
|---|-------------|------|---------|
| 1 | `validations/v-{nn}-*.md` complete | setpoint spec | §1 + §2 from template |
| 2 | `pages.json5` entries for canary ids | all classes | domain ids |
| 3 | Fuzzy baselines / setpoint | `setpoint capture` or dual `record --all` | `record --pages …` both profiles |
| 4 | `validate --phase all` pass | test + mirror | test + mirror |
| 5 | `validations/README.md` + ds-* footer link | ✓ | ✓ |
| 6 | `04-milestone-checklist.md` checked | ✓ | ✓ |
| 7 | One commit on `megiddo/v-*` branch | ✓ | ✓ |

---

## Related documents

- [08-phase-16-validation-artifacts.md](./08-phase-16-validation-artifacts.md)
- [07-agent-milestone-prompt.md](./07-agent-milestone-prompt.md) — R-* and general refactor (after V-*)
- [validations/README.md](./validations/README.md)
