# Worker — FIX-03 (Playwright heraldry profile)

```
You are executing **Megiddo FIX-03** only — heraldry E2E profile alignment.

Read: docs/megiddo/refactor/skills/phase3-remediation/workers/_shared-procedure.md, docs/megiddo/refactor/phase3-audit-report.md § Playwright, docs/megiddo/refactor/06-test-framework.md, tests/e2e/heraldry.spec.ts

| Field | Value |
|-------|-------|
| Branch | `megiddo/p3-fix-03-playwright-heraldry` |
| Stack base | `megiddo/p3-fix-02-assets` @ checklist |
| Prerequisite | FIX-02 complete — `deploy-sandbox` green |
| Problem | heraldry.spec.ts uses sandbox test IDs (100001, 1000001, fake players ≥100000000) but Phase 3 ran on `use prod` mirror |
| Scope | `tests/e2e/heraldry.spec.ts`, `docs/megiddo/refactor/06-test-framework.md`, optionally `playwright.config.ts` |

## Tasks

1. Make heraldry specs run against **sandbox/dev** profile with correct credentials (`megiddo` / `test-db-player` or documented override).
2. Options (pick cleanest):
   - `test.describe` with `bin/ork-db use dev` preflight documented in spec header + framework doc, or
   - Separate project in playwright.config for sandbox-only heraldry, or
   - `test.beforeAll` profile check that skips with clear message when wrong profile (avoid silent pass on mirror).
3. Update `06-test-framework.md` — which specs require sandbox vs mirror; update phase3-closeout orchestrator if heraldry should be excluded from prod-profile full run.
4. Do **not** weaken assertions — kingdom/park `.heraldry-img` and roster avatar must still be verified.

## Gates

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox
bin/ork-db use dev
export ORK3_E2E_BASE_URL=http://127.0.0.1:19080/orkui/
export ORK3_E2E_USERNAME=megiddo ORK3_E2E_PASSWORD=test-db-player
npx playwright test tests/e2e/heraldry.spec.ts   # exit 0
```

Also verify prod-profile suite still passes for non-heraldry specs if you changed shared config:

```bash
bin/ork-db use prod
export ORK3_E2E_USERNAME=admin ORK3_E2E_PASSWORD=password
npx playwright test tests/e2e/ --grep-invert heraldry   # exit 0
```

## Out of scope

- orkui/ refactors; fuzzy baselines (FIX-04)

Commit: `FIX-03: Align heraldry Playwright specs with sandbox profile.`  
Update milestone-checklist.md; return report.
```
