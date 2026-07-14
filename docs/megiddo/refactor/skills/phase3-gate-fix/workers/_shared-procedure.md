# FIX / VALIDATE-20 Worker — Shared Procedure

Sub-agents for **FIX-06** and **VALIDATE-20-rerun** follow these steps after reading their hop-specific worker file.

---

## 1. Stack base

Checkout stack tip from [milestone-checklist.md](../milestone-checklist.md). `git status` clean.

If base missing/dirty → `status=blocked`.

---

## 2. Environment preflight

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox
bin/seed-test-credentials --target sandbox   # also runs inside deploy-sandbox; safe to re-run
bin/fuzzy-validator setpoint restore
export ORK3_E2E_BASE_URL=http://127.0.0.1:19080/orkui/
```

Mirror Playwright: `bin/ork-db use prod` + `export ORK3_E2E_USERNAME=admin ORK3_E2E_PASSWORD=password`.

Sandbox heraldry: `bin/ork-db use dev` + `export ORK3_E2E_USERNAME=megiddo ORK3_E2E_PASSWORD=test-db-player`.

---

## 3. Create stacked branch

`git checkout <prior-tip-branch> && git checkout -b <branch-from-worker-file>`

Record branch on milestone-checklist.md.

---

## 4. Implement hop scope

Stay within worker file scope. No idiom refactors (I-* hops).

---

## 5. Gate (per worker file)

Run gates listed in the worker file.

---

## 6. Checklist + commit

- Update [milestone-checklist.md](../milestone-checklist.md).
- Update [04-milestone-checklist.md](../../04-milestone-checklist.md) § Phase 3 when specified.
- One commit; clean tree; stage `docs/megiddo/refactor/` changes.

---

## 7. Return report

```
status: ok|blocked|failed
hop: <id>
branch: …
commit: …
gates: …
blockers: …
```
