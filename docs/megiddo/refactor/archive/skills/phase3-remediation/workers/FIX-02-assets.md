# Worker — FIX-02 (Asset pipeline)

```
You are executing **Megiddo FIX-02** only — ork-db heraldry asset manifest alignment.

Read: docs/megiddo/refactor/skills/phase3-remediation/workers/_shared-procedure.md, docs/megiddo/refactor/phase3-audit-report.md § deploy-sandbox, tools/ork-db/Validate.php (checkDeployedAssets), tools/ork-db/GenerateAssets.php, tools/ork-db/Render.php

| Field | Value |
|-------|-------|
| Branch | `megiddo/p3-fix-02-assets` |
| Stack base | Prior tip from milestone-checklist.md (entry: `megiddo/r-18-residual-db-refactor`) |
| Problem | `deploy-sandbox` aborts: ~110 missing `assets/heraldry/player/*` vs ~82 generated |
| Scope | `tools/ork-db/` only — align bootstrap `has_heraldry` flags with GenerateAssets ID lists, or expand generator to cover every `has_heraldry=1` mundane in sandbox |

## Tasks

1. Reproduce: `bin/ork-db deploy-sandbox` → asset FAIL; document root cause in commit message or short note in milestone-checklist.
2. Fix so `generate-assets` + `deploy-assets` satisfy `Validate::checkDeployedAssets` for sandbox DB.
3. Prefer fixing Render/GenerateAssets consistency over weakening validation.
4. Add or extend unit test in `tests/Unit/OrkDb/` if behavior is non-obvious.

## Gates

```bash
bin/ork-db generate-assets
bin/ork-db deploy-assets
bin/ork-db deploy-sandbox    # must exit 0; Assets: PASS
sh bin/run-unit-tests.sh     # exit 0
```

## Out of scope

- Playwright, fuzzy, orkui/ refactors

Commit: `FIX-02: Align sandbox heraldry asset generation with deploy validation.`  
Update milestone-checklist.md FIX-02 section; return report.
```
