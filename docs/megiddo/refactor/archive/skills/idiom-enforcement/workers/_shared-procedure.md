# I-* Worker — Shared Procedure

Sub-agents for **I-0**, **I-01 … I-19d**, and **I-VALIDATE** follow these steps after reading their hop-specific worker file.

---

## 1. Stack base

Checkout stack tip from [milestone-checklist.md](../milestone-checklist.md). `git status` clean.

If base missing/dirty → `status=blocked`.

---

## 2. Environment preflight

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox
bin/fuzzy-validator setpoint restore
export ORK3_E2E_BASE_URL=http://127.0.0.1:19080/orkui/
bin/ork-db use prod
export ORK3_E2E_USERNAME=admin ORK3_E2E_PASSWORD=password
```

---

## 3. Create stacked branch

`git checkout <prior-tip-branch> && git checkout -b <branch-from-worker-file>`

Record branch on milestone-checklist.md.

---

## 4. Read charter

**I-0:** produce [idioms-00-charter.md](../../idioms-00-charter.md).

**I-01 … I-19d:** read charter § hop scope + reference file. **Style only** — no behavior change.

---

## 5. Idiom edits (allowed)

- Replace inline `new Model_*()` / `new Domain()` in controllers with `load_model` + `$this->Model->wrapper()` when file peers use that pattern
- Add thin model wrapper methods instead of domain calls in controllers
- Normalize naming to snake_case wrappers → PascalCase domain methods per existing model
- Match whitespace and `array()` vs `[]` to **dominant style in each touched file**

## 5b. Forbidden

- New features, bug fixes unrelated to idiom, or “while I'm here” refactors
- Reintroducing `$DB`, `Ork3::$Lib`, or direct DML in `orkui/`
- Changing JSON response shapes or HTTP status semantics
- Whole-file reformatting or PSR-* modernization

---

## 6. Static isolation (every hop)

```bash
rg '\$DB->' orkui/          # exit 1
rg 'Ork3::\$Lib' orkui/    # exit 1
```

---

## 7. PHPUnit (full suite)

```bash
sh bin/run-unit-tests.sh
```

Exit 0 required. If tests fail, revert idiom change or `status=failed`.

---

## 8. Hop gates (when listed in worker / charter)

Run fuzzy/Playwright/Infection only when worker file lists them (typically same pages as mapped R-* / R-19*).

---

## 9. Checklist + commit

Update `skills/idiom-enforcement/milestone-checklist.md`. One commit per hop. Stage all `docs/megiddo/refactor/` changes.

---

## 10. Return report

```
status: ok|blocked|failed
hop: I-…
branch: …
commit: …
gates: …
idiom_changes: brief bullet list
blockers: …
```
