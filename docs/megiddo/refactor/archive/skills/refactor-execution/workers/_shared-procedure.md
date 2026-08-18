# R-* Worker — Shared Procedure

Every **R-01 … R-18** sub-agent follows these steps in order after reading its milestone-specific worker file (`workers/R-{nn}.md`).

---

## 1. Stack base hygiene

**R-01:** Checkout integration line from checklist metadata (e.g. `megiddo/rebase-20260709`). `git status` clean.

**R-02+:** Checkout prior R-* branch at commit hash from checklist stack chain. Verify clean tree + prior branch has its single squashed commit. **Do not merge** to integration.

If base missing/dirty → `status=blocked`.

---

## 2. Environment preflight

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox
bin/fuzzy-validator setpoint restore   # if baselines missing
export ORK3_E2E_BASE_URL=http://127.0.0.1:19080/orkui/
bin/ork-db use prod
export ORK3_E2E_USERNAME=admin ORK3_E2E_PASSWORD=password
export ORK3_E2E_TEST_PASSWORD=test-db-player
```

See [06-test-framework.md § E2E login credentials](../../../06-test-framework.md#e2e-login-credentials-preflight). Auth Playwright must **run**, not skip.

---

## 3. Create stacked branch

**R-01:** `git checkout <integration-line> && git checkout -b <branch-from-worker-file>`

**R-02+:** `git checkout <prior-branch> && git checkout -b <branch-from-worker-file>`

One milestone per branch (DS-3). Record branch name on checklist.

---

## 4. Refactor

Implement `03-implementation-plan.md` targets + `ds-{nn}-*-discovery.md` §3 only. Move logic to `system/lib/ork3/` / `orkservice/*`. No `$DB` in touched `orkui/`. Test edits only within `validations/v-{nn}-*.md` §2.3 — **preserve semantic intent**.

---

## 5. PHPUnit (full suite)

```bash
sh bin/run-unit-tests.sh
```

Exit 0 required (DS-4/DS-5). No partial suite for sign-off.

---

## 6. Infection

Run `validations/v-{nn}-*.md` §2.4. Meet documented MSI floors — do not lower without user approval.

---

## 7. Fuzzy

```bash
bin/fuzzy-validator validate --pages <gate-from-worker-file> --phase all
```

Test + mirror must pass. Re-record only for intentional UI change.

---

## 8. Playwright

Run specs listed in worker file + auth smoke:

```bash
npx playwright test tests/e2e/infrastructure.spec.ts -g "home route loads after login"
```

---

## 9. Docs

Check off `validations/v-{nn}-*.md` §3 (or V-00 for R-18), `04-milestone-checklist.md`, `03-implementation-plan.md` targets, `milestone-checklist.md` § R-{nn} + stack chain metadata, and [10-phase-2-continuation.md](../../../10-phase-2-continuation.md) when applicable.

---

## 10. Stage, commit (mandatory)

```bash
git add -A   # all code + docs for this milestone
# squash to exactly one commit if needed
git commit -m "R-{nn}: …"
git status   # must be clean
```

Update checklist with branch + full commit hash. **Do not push/merge to integration.**

---

## Return report

```
status: ok|blocked|failed
milestone: R-{nn}
branch: …
base_branch: …
base_commit: …
commit: …
phpunit: …
infection: …
fuzzy: …
playwright: …
checklist: …
blockers: …
```
