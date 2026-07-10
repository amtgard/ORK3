# Worker — VALIDATE-20 (Phase 3 re-audit — success criteria)

```
You are executing **Megiddo VALIDATE-20** only — final automated audit after remediation (no production refactors).

Read: docs/megiddo/refactor/skills/phase3-closeout/orchestrator.prompt (gate definitions), docs/megiddo/refactor/02-requirements.md § Success Criteria, docs/megiddo/refactor/06-test-framework.md, docs/megiddo/refactor/phase3-audit-report.md (prior run)

| Field | Value |
|-------|-------|
| Branch | `megiddo/p3-validate-20-audit` |
| Stack base | `megiddo/r-19d-residual-lib-refactor` @ checklist |
| Prior hop | R-19d |
| Scope | **Audit and verification only** — report failures; do not fix code unless user explicitly asks |

## Goal

Confirm Megiddo refactor success criteria:

1. **No database access in frontend** — zero `$DB->` in `orkui/`
2. **No domain lib bypass in frontend** — zero `Ork3::$Lib` in `orkui/`
3. **No direct DML in frontend** — zero raw `INSERT INTO` / `UPDATE` / `DELETE FROM` against domain tables in `orkui/` (SQL strings in PHP)
4. **Business logic in backend** — static audit pass above is necessary; note any remaining suspicious patterns in report (yapo ORM in orkui, heredoc SQL, `mysqli_`, `PDO` outside tests)
5. **Zero test regressions** — full PHPUnit exit 0
6. **Zero fuzzy regressions** — `validate --all` exit 0 (test + mirror)
7. **Zero Playwright regressions** — full e2e exit 0 per FIX-03 profile rules

## Environment

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox
bin/fuzzy-validator setpoint restore
export ORK3_E2E_BASE_URL=http://127.0.0.1:19080/orkui/
```

Credentials: mirror `admin`/`password`; heraldry specs per `06-test-framework.md` sandbox split from FIX-03.

## V20-A — Static audit (frontend isolation)

```bash
rg '\$DB->' orkui/
rg 'Ork3::\$Lib' orkui/
rg -i 'INSERT INTO|UPDATE [a-z_]+ SET|DELETE FROM' orkui/ --glob '*.php'
rg -i 'new yapo|mysqli_|PDO::' orkui/ --glob '*.php'
```

Pass = zero matches on first three commands (or only documented exemptions in 02-requirements). Record counts, file paths, and line samples for failures. Fourth command is advisory — list hits in report.

## V20-B — PHPUnit

```bash
sh bin/run-unit-tests.sh
```

Pass = exit 0.

## V20-C — Fuzzy regression

```bash
bin/fuzzy-validator validate --all --phase all
```

Pass = exit 0.

## V20-D — Playwright

```bash
bin/ork-db use prod
export ORK3_E2E_USERNAME=admin ORK3_E2E_PASSWORD=password
npx playwright test tests/e2e/ --grep-invert heraldry   # mirror suite

bin/ork-db use dev
export ORK3_E2E_USERNAME=megiddo ORK3_E2E_PASSWORD=test-db-player
npx playwright test tests/e2e/heraldry.spec.ts            # sandbox heraldry
```

Pass = both exit 0. If FIX-03 consolidated into single command, use documented approach from 06-test-framework.md.

## V20-E — Plan completeness

Confirm all T-* targets and R-19a…d have completion notes in `03-implementation-plan.md` and checklists.

## V20-F — Report + checklist

- **Overwrite** `docs/megiddo/refactor/phase3-audit-report.md` with timestamp, branch, commit, V20-A…E results, `status: ok|failed`.
- Update `04-milestone-checklist.md` § Phase 3 automated items — check off only what passed.
- Update `skills/phase3-remediation/milestone-checklist.md` VALIDATE-20 section.
- Leave P3-4 (manual smoke matrix) and P3-5 (retrospective) unchecked — human completes.

## Commit (if docs changed)

```bash
git add docs/megiddo/refactor/
git commit -m "VALIDATE-20: Phase 3 re-audit after remediation."
```

## Return report

```
status: ok|failed
hop: VALIDATE-20
branch: …
commit: …
v20a_db: pass|fail (count)
v20a_lib: pass|fail (count)
v20a_dml: pass|fail (count)
phpunit: pass|fail
fuzzy: pass|fail
playwright: pass|fail
audit_report: docs/megiddo/refactor/phase3-audit-report.md
human_next: P3-4 manual smoke matrix + P3-5 retrospective (if status=ok)
blockers: …
```

Exit criterion: **all** V20-A through V20-D pass → `status=ok`. Any failure → `status=failed`; do not claim remediation complete.
```
