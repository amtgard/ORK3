# Worker — FIX-10 (Sandbox fuzzy setpoint re-baseline)

```
You are executing **Megiddo FIX-10** only — re-record **test/sandbox** fuzzy baselines after credential seed fix (no production refactors).

Read: docs/megiddo/refactor/skills/phase3-gate-fix/workers/_shared-procedure.md, docs/megiddo/fuzzy-validator/reference/04-operating-guide.md § record / setpoint / refuzz, docs/megiddo/refactor/06-test-framework.md § E2E login credentials, docs/megiddo/refactor/validations/v-00-fuzzy-setpoint.md

| Field | Value |
|-------|-------|
| Branch | `megiddo/p3-fix-10-sandbox-fuzzy-rebaseline` |
| Stack base | Prior tip from checklist / `megiddo/i-validate-idiom-audit` (or later) |
| Scope | **test profile only** — do not re-record mirror unless a test-profile validate proves mirror also broken |

## Why

`bin/ork-db deploy-sandbox` re-applies extracted production credential hashes, so sandbox auth (`megiddo` / `test-db-player`, `admin` / `password`) silently broke. Authenticated **test**-profile baselines (esp. `park-auth-sandbox`) were captured while logged in; later validates compared against unauthenticated renders → dimension mismatch. `bin/seed-test-credentials` (wired into deploy-sandbox) restores known passwords.

## Preflight (mandatory, in order)

```bash
docker compose -f docker-compose.php8.yml up -d
bin/seed-test-credentials --target sandbox
bin/ork-db deploy-sandbox --yes   # must print Seed credentials lines; re-seed is part of deploy
bin/seed-test-credentials --target sandbox   # idempotent OK
bin/ork-db use dev
export ORK3_E2E_BASE_URL=http://127.0.0.1:19080/orkui/
export ORK3_E2E_USERNAME=megiddo ORK3_E2E_PASSWORD=test-db-player
bin/fuzzy-validator setpoint restore
```

Verify auth (pick one):
- `ENVIRONMENT=TEST php -r '… Authorization::KeyExists …'` for megiddo + admin, OR
- Playwright/infrastructure after `npx playwright install` if browsers missing.

If seed fails → `status=blocked`.

## If seed-test-credentials is uncommitted on the working tree

Stage and include it in this hop's commit (or a first commit on the branch): `bin/seed-test-credentials`, `tools/ork-db/SeedTestCredentials.php`, DeploySandbox wiring, cli.php, ork-db bootstrap require, unit tests, docs (`06-test-framework.md`, smoke-matrix preflight). Message: `FIX-10: Seed local-docker test credentials for sandbox auth.` then continue with baseline work in a second commit if cleaner.

## Re-record (test profile)

```bash
bin/fuzzy-validator record --profile test --all --phase all --ensure-sandbox
```

If `--all` is too long or hangs, batch active pages (20 non-skip in `pages.json5`):

```text
home-authenticated,player-profile,player-profile-sandbox,kingdom-profile,kingdom-auth-sandbox,park-auth-sandbox,event-list,event-index-rsvp,event-index-rsvp-gok,event-create,event-kingdom,event-park,admin-dashboard,admin-state-of-amtgard,admin-permissions,reports-voting-eligible,reports-ladder-grid,reports-attendance,weather,tournament
```

Record in batches of ≤5 via `--pages a,b,c --profile test --phase all --ensure-sandbox`.

## Validate + setpoint

```bash
bin/fuzzy-validator validate --profile test --all --phase all   # expect 20/20 (or full test-row count) exit 0
bin/fuzzy-validator setpoint capture --profiles test,mirror
bin/fuzzy-validator setpoint publish --bundle tools/fuzzy-validator/setpoints/out/<newest>.zip
cp tools/fuzzy-validator/setpoints/out/<newest>.zip tools/fuzzy-validator/setpoints/bootstrap/
bin/fuzzy-validator setpoint restore
bin/fuzzy-validator validate --profile test --all --phase all   # confirm after restore
```

## Docs

- Update `validations/r-milestone-smoke-matrix.html` known-issue `#known-issue-park-auth-sandbox`: mark resolved / strike as expected FAIL once test-profile `park-auth-sandbox` passes.
- Note new setpoint bundle id in hop report / checklist.

## Gates

```bash
bin/seed-test-credentials --target sandbox   # exit 0, unchanged OK
rg '\$DB->' orkui/                           # exit 1
rg 'Ork3::\$Lib' orkui/                      # exit 1
sh bin/run-unit-tests.sh                     # exit 0
php vendor/bin/phpunit -c phpunit.ork-db.xml.dist tests/Unit/OrkDb/SeedTestCredentialsTest.php  # exit 0
bin/fuzzy-validator validate --profile test --all --phase all   # exit 0
```

Commit: `FIX-10: Re-record sandbox fuzzy baselines after credential seed.`  
Update `skills/phase3-gate-fix/milestone-checklist.md` if present; return report with pages recorded + setpoint bundle.
```
