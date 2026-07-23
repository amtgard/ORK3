# Worker — BACKFILL (DS/T/V for R-14 … R-18)

```
You are executing **Megiddo BACKFILL** only — retroactive DS/T/V artifacts for Phase 2 continuation.

Read: docs/megiddo/refactor/skills/phase3-remediation/workers/_shared-procedure.md, docs/megiddo/refactor/10-phase-2-continuation.md, docs/megiddo/refactor/04-milestone-checklist.md § R-14 … R-18, docs/megiddo/refactor/ds-14-lib-service-discovery.md, docs/megiddo/refactor/validations/v-14-lib-service-validation.md

| Field | Value |
|-------|-------|
| Branch | `megiddo/p3-backfill-tvds-r14-r18` |
| Stack base | `megiddo/p3-fix-05-doc-hygiene` @ checklist |
| Scope | Documentation only — retroactive planning artifacts |

## Problem

R-14 … R-18 shipped via continuation orchestrator without full Phase 1 / 1.5 / 1.6 artifact parity:
- R-14 has DS-14, T-14, V-14 — verify completeness vs actual R-14 deliverables
- R-15 … R-18 lack dedicated ds-15…18, t-15…18, v-15…18 design/test/validation docs

## Tasks

1. **Gap audit** — table in milestone-checklist or new `docs/megiddo/refactor/p3-backfill-tvds-audit.md`: per R-14…R-18 what exists vs missing.
2. **Create missing artifacts** (follow naming of existing ds-*/validations/v-*):
   - `ds-15-hasauthority-discovery.md` (R-15)
   - `ds-16-ghettocache-discovery.md` (R-16)
   - `ds-17-lib-bypass-discovery.md` (R-17) — may consolidate with DS-14 §1.5 carryover
   - `ds-18-residual-db-discovery.md` (R-18)
   - Matching `validations/v-15-*.md` … `v-18-*.md` with fuzzy pages, Infection filters, Playwright specs actually used at sign-off
   - Test-design sections (T-15 … T-18) — either standalone `t-15-*.md` files or §2 in each ds-* doc per project convention
3. Content source: `04-milestone-checklist.md` R-14…R-18 complete sections, worker files `R-15.md`…`R-18.md`, git log on those branches if needed.
4. Update `04-milestone-checklist.md` Phase 1 / 1.5 / 1.6 — check off backfilled rows with links.
5. Add pointer in `10-phase-2-continuation.md` § Worker hygiene → backfill complete.

## Gates

- No production code changes.
- Each new doc must cite actual gate commands/results from milestone sign-off (not invented).

Commit: `BACKFILL: Retroactive DS/T/V artifacts for R-14 through R-18.`  
Update milestone-checklist.md; return report.
```
