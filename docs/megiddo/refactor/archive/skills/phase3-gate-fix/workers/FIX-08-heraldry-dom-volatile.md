# Worker — FIX-08 (heraldry DOM volatility + setpoint repro)

```
You are executing **Megiddo FIX-08** only — fix reproducible V20-C fuzzy failures after canonical VALIDATE preflight.

Read: docs/megiddo/refactor/skills/phase3-gate-fix/workers/_shared-procedure.md, docs/megiddo/refactor/phase3-audit-report.md (latest V20-C), docs/megiddo/refactor/validations/v-00-fuzzy-setpoint.md

| Field | Value |
|-------|-------|
| Branch | `megiddo/p3-fix-08-heraldry-dom-volatile` |
| Stack base | `megiddo/p3-fix-07-fuzzy-baselines` @ checklist |
| Prerequisite | VALIDATE-20-rerun (2nd) failed V20-C |
| Root cause | `deploy-sandbox` bumps heraldry `?v=` cache-bust in DOM `src`/`style`; DOM gate fails across sandbox auth pages. Spurious `home-authenticated` dimension failures from stale on-disk baselines vs bundle. |

## Tasks

1. Canonical preflight (same order as VALIDATE-20):
   ```bash
   docker compose -f docker-compose.php8.yml up -d
   bin/ork-db deploy-sandbox --yes
   bin/fuzzy-validator setpoint restore
   bin/fuzzy-validator validate --all --phase all   # capture failures
   ```
2. **Validator fix** — in `tools/fuzzy-validator/python/lib/tree_diff.py`, normalize heraldry `/assets/heraldry/` URL query params in DOM attribute compare (`src`, `style` background-image). Add unit tests in `test_tree_diff.py`.
3. Re-run failing pages; if residual visual drift (e.g. `park-auth-sandbox` 0.999), `record` that page only.
4. **Setpoint refresh** (post-deploy):
   ```bash
   bin/ork-db deploy-sandbox --yes
   bin/fuzzy-validator setpoint capture --profiles test,mirror
   bin/fuzzy-validator setpoint publish --bundle tools/fuzzy-validator/setpoints/out/<newest>.zip
   cp tools/fuzzy-validator/setpoints/out/<newest>.zip tools/fuzzy-validator/setpoints/bootstrap/
   bin/fuzzy-validator setpoint restore
   ```
5. **Repro gate** — run validate twice:
   - `validate --all` exit 0 immediately after restore
   - `deploy-sandbox --yes` → `setpoint restore` → `validate --all` exit 0 (simulates VALIDATE-20 preflight)

## Gates

```bash
rg 'Ork3::\$Lib' orkui/          # exit 1
rg '\$DB->' orkui/               # exit 1
sh bin/run-unit-tests.sh         # exit 0
cd tools/fuzzy-validator/python && python3 -m pytest tests/unit/test_tree_diff.py -q   # exit 0
bin/ork-db deploy-sandbox --yes
bin/fuzzy-validator setpoint restore
bin/fuzzy-validator validate --all --phase all   # exit 0, 42/42
```

## Out of scope

- Idiom enforcement; merge to integration

Commit: `FIX-08: Stabilize fuzzy DOM gate against heraldry cache-bust drift.`  
Update `skills/phase3-gate-fix/milestone-checklist.md`; return report with new `latestBundle`.
```
