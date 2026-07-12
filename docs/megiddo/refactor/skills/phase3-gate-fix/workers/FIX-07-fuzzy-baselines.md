# Worker — FIX-07 (VALIDATE-20 fuzzy baseline drift)

```
You are executing **Megiddo FIX-07** only — re-record fuzzy baselines blocking VALIDATE-20-rerun (no production refactors unless gate reveals a real layout regression).

Read: docs/megiddo/refactor/skills/phase3-gate-fix/workers/_shared-procedure.md, docs/megiddo/refactor/phase3-audit-report.md (V20-C section), docs/megiddo/refactor/validations/v-00-fuzzy-setpoint.md, docs/megiddo/refactor/06-test-framework.md

| Field | Value |
|-------|-------|
| Branch | `megiddo/p3-fix-07-fuzzy-baselines` |
| Stack base | `megiddo/p3-fix-06-gate-blockers` @ `c330d69b` |
| Prerequisite | FIX-06 complete; VALIDATE-20-rerun failed on V20-C only |
| Known blockers | (1) mirror `reports-ladder-grid` dimension drift 15643→15588; (2) test `player-profile` dimension or DOM drift per audit — verify live before re-recording |

## Tasks

1. Preflight (mandatory, in order):
   ```bash
   docker compose -f docker-compose.php8.yml up -d
   bin/ork-db deploy-sandbox --yes
   bin/fuzzy-validator setpoint restore
   ```
2. **Reproduce** — run `bin/fuzzy-validator validate --all --phase all` on clean FIX-06 tip with restored setpoint. Capture exit code and first `gate_run:` / `Fuzzy UI Gate` failure lines. Do not re-record until blockers are confirmed.
3. **Human drift sign-off** — reviewer completes [r-milestone-smoke-matrix.html § P3-4 fuzzy drift](../../validations/r-milestone-smoke-matrix.html#p3-fuzzy-drift). Stop if any callout is marked regression.
4. **Per-page fix** — for each failing page/profile:
   - If dimension mismatch only → `bin/fuzzy-validator record --pages <id> --phase all --profiles <test|mirror>` (add `--ensure-sandbox` for test profile).
   - If DOM/assets score drift with stable dimensions → same `record` command; inspect overlay reports under `tools/fuzzy-validator/reports/`.
   - If visual drift indicates a code regression → fix minimal template/CSS/AJAX diff first, then re-record.
5. **Setpoint publish** — after all targeted `record` passes per-page validate:
   ```bash
   bin/fuzzy-validator setpoint capture --profiles test,mirror
   bin/fuzzy-validator setpoint publish --bundle tools/fuzzy-validator/setpoints/out/<newest>.zip
   cp tools/fuzzy-validator/setpoints/out/<newest>.zip tools/fuzzy-validator/setpoints/bootstrap/
   bin/fuzzy-validator setpoint restore
   ```
   Prefer full `setpoint capture` over ad-hoc zip bundling.
6. **Gate** — `bin/fuzzy-validator validate --all --phase all` exit 0 (42/42 pass). PHPUnit must stay green.

## Gates

```bash
rg 'Ork3::\$Lib' orkui/          # exit 1
rg '\$DB->' orkui/               # exit 1
sh bin/run-unit-tests.sh         # exit 0
bin/fuzzy-validator setpoint restore
bin/fuzzy-validator validate --all --phase all   # exit 0
```

## Out of scope

- Idiom enforcement (I-*); P3-4 manual smoke; merge to integration

Commit: `FIX-07: Re-record fuzzy baselines for VALIDATE-20 V20-C gate.`  
Update `skills/phase3-gate-fix/milestone-checklist.md` FIX-07 section; return report with pages re-recorded and new `latestBundle`.
```
