# Worker — FIX-09 (event-index skip + attendance login flake)

```
You are executing **Megiddo FIX-09** only — fix VALIDATE-20-rerun (3rd) V20-C and V20-D blockers.

Read: docs/megiddo/refactor/skills/phase3-gate-fix/workers/_shared-procedure.md, docs/megiddo/refactor/validations/v-05-event-validation.md, docs/megiddo/refactor/phase3-audit-report.md (latest V20-C/V20-D)

| Field | Value |
|-------|-------|
| Branch | `megiddo/p3-fix-09-event-index-attendance` |
| Stack base | `megiddo/p3-fix-08-heraldry-dom-volatile` @ checklist |
| Prerequisite | VALIDATE-20-rerun (3rd) failed V20-C + V20-D |
| Root cause | V20-C: mirror `event-index` DOM 0.996 (event table rows 11–12 link text churn) — V-00 only per v-05. V20-D: `attendance.spec.ts` login `beforeEach` times out on `networkidle` (mirror long-polling). |

## Tasks

1. Canonical preflight (same order as VALIDATE-20):
   ```bash
   docker compose -f docker-compose.php8.yml up -d
   bin/ork-db deploy-sandbox --yes
   bin/fuzzy-validator setpoint restore
   bin/fuzzy-validator validate --all --phase all   # capture failures
   ```
2. **V20-C** — in `tools/fuzzy-validator/manifests/pages.json5`, set `event-index` `skip: true` with note citing mirror volatile event list and V-00 coverage via `event-index-rsvp*`. No setpoint re-publish required (page excluded from `--all`).
3. **V20-D** — in `tests/e2e/attendance.spec.ts`, replace login `waitForLoadState('networkidle')` with `waitForURL` leaving Login route (do not weaken auth assertions).
4. **Repro gate** — run validate twice:
   - `validate --all` exit 0 immediately after restore (expect 41/41)
   - `deploy-sandbox --yes` → `setpoint restore` → `validate --all` exit 0
5. Playwright: mirror 50/50 (`--grep-invert heraldry`), `attendance.spec.ts` 4/4, sandbox heraldry 3/3.

## Gates

```bash
rg 'Ork3::\$Lib' orkui/          # exit 1
rg '\$DB->' orkui/               # exit 1
sh bin/run-unit-tests.sh         # exit 0
bin/ork-db deploy-sandbox --yes
bin/fuzzy-validator setpoint restore
bin/fuzzy-validator validate --all --phase all   # exit 0, 41/41
export ORK3_E2E_BASE_URL=http://127.0.0.1:19080/orkui/
bin/ork-db use prod
export ORK3_E2E_USERNAME=admin ORK3_E2E_PASSWORD=password
npx playwright test tests/e2e/ --grep-invert heraldry   # 50/50
npx playwright test tests/e2e/attendance.spec.ts        # 4/4
bin/ork-db use dev
export ORK3_E2E_USERNAME=megiddo ORK3_E2E_PASSWORD=test-db-player
npx playwright test tests/e2e/heraldry.spec.ts   # 3/3
```

## Out of scope

- Idiom enforcement; merge to integration; shared login helper refactor across all e2e specs

Commit: `FIX-09: Stabilize V20-C/V20-D gates (event-index skip + attendance login wait).`  
Docs: `Docs: Add FIX-09 worker and update gate-fix checklist.`  
Rebase `megiddo/p3-validate-20-audit` onto FIX-09 tip.
```
