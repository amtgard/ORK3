# FIX / BACKFILL / DS-19 Worker — Shared Procedure

Sub-agents for **FIX-02 … FIX-05**, **BACKFILL**, and **DS-19** follow these steps after reading their hop-specific worker file.

---

## 1. Stack base

Checkout stack tip from [milestone-checklist.md](../milestone-checklist.md). `git status` clean.

If base missing/dirty → `status=blocked`.

---

## 2. Environment preflight

```bash
docker compose -f docker-compose.php8.yml up -d
export ORK3_E2E_BASE_URL=http://127.0.0.1:19080/orkui/
```

For hops that need sandbox: `bin/ork-db deploy-sandbox` (must pass after FIX-02).

For hops that need mirror Playwright: `bin/ork-db use prod` + `export ORK3_E2E_USERNAME=admin ORK3_E2E_PASSWORD=password`.

For sandbox heraldry: `bin/ork-db use dev` + `export ORK3_E2E_USERNAME=megiddo ORK3_E2E_PASSWORD=test-db-player`.

---

## 3. Create stacked branch

`git checkout <prior-tip-branch> && git checkout -b <branch-from-worker-file>`

Record branch on milestone-checklist.md.

---

## 4. Implement hop scope

Stay within worker file scope. No R-19 production refactors in FIX/BACKFILL/DS-19 hops.

---

## 5. Gate (per worker file)

Run only the gates listed in the worker file. Full suite not required for doc-only hops.

---

## 6. Checklist + commit

- Update [milestone-checklist.md](../milestone-checklist.md) — branch, commit, gate boxes.
- Update master [04-milestone-checklist.md](../../../04-milestone-checklist.md) when worker specifies.
- `git add -A` (or scoped paths per worker); one commit; clean tree.

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
