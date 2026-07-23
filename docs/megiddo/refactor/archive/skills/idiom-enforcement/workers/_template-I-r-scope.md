# Worker template — I-{NN} (Idiom — R-{NN} scope)

Orchestrator: substitute `{NN}` with `01` … `18` (zero-padded). Paste result into Task `prompt`.

```
You are executing **Megiddo I-{NN}** only — idiom alignment for R-{NN} file scope.

Read: docs/megiddo/refactor/skills/idiom-enforcement/workers/_shared-procedure.md, docs/megiddo/refactor/idioms-00-charter.md § I-{NN}, docs/megiddo/refactor/04-milestone-checklist.md § R-{NN} complete

| Field | Value |
|-------|-------|
| Branch | `megiddo/i-{NN}-idiom-r{NN}` |
| Stack base | Prior I-* tip from milestone-checklist.md |
| Maps to | R-{NN} execution sprint |
| Files | Per idioms-00-charter.md § I-{NN} (from `megiddo/r-{NN}-*` branch diff) |

## Scope

**Style only** on charter-listed files. Match reference file cited in charter for this hop. No semantic/API changes.

## Gates

```bash
rg '\$DB->' orkui/
rg 'Ork3::\$Lib' orkui/
sh bin/run-unit-tests.sh
```

Plus fuzzy/Playwright from idioms-00-charter.md §5 for I-{NN} if listed.

Commit: `I-{NN}: Idiom alignment for R-{NN} scope.`  
Update milestone-checklist.md; return report.
```
