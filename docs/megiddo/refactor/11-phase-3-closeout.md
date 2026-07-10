# Phase 3 — Audit and Close-out

**Status:** In progress — automated audit run 2026-07-10 ([phase3-audit-report.md](./phase3-audit-report.md); **failed** pending lib bypass + heraldry/asset fixes)  
**Prerequisite:** R-01 … R-18 complete on stack tip  
**Phase 2 continuation:** [10-phase-2-continuation.md](./10-phase-2-continuation.md)  
**Master checklist:** [04-milestone-checklist.md](./04-milestone-checklist.md) § Phase 3

Phase 3 verifies the refactor target state. It is **not** an implementation phase — no new migrations except doc/audit artifacts listed below.

---

## Where Phase 3 is documented (cross-reference index)

| Document | What it says about Phase 3 |
|----------|---------------------------|
| **This file** | Canonical plan — deliverables, agent vs human, prompts |
| [04-milestone-checklist.md](./04-milestone-checklist.md) § Phase 3 | Checkboxes + quick-reference row |
| [10-phase-2-continuation.md](./10-phase-2-continuation.md) § Phase 3 | Short audit checklist (links here for full plan) |
| [02-requirements.md](./02-requirements.md) § Success Criteria | Target state definition (met after R-18 + Phase 3) |
| [05-development-steering.md](./05-development-steering.md) | Steering applies through Phase 2; Phase 3 = audit |
| [README.md](./README.md) | Current phase pointer |
| [skills/phase3-closeout/SKILL.md](./skills/phase3-closeout/SKILL.md) | Agent skill for automated close-out |
| [skills/phase3-closeout/orchestrator.prompt](./skills/phase3-closeout/orchestrator.prompt) | Copy-paste Phase 3 agent orchestrator |

---

## Deliverables

| ID | Owner | Artifact | Status |
|----|-------|----------|--------|
| **P3-1** | Human (+ agent may refresh baselines) | [validations/r-milestone-smoke-matrix.html](./validations/r-milestone-smoke-matrix.html) — one manual smoke per R-* (R-01 … R-18) | [x] |
| **P3-2** | Agent | Automated audit report (`rg`, PHPUnit, fuzzy, Playwright) | [x] ([phase3-audit-report.md](./phase3-audit-report.md) — partial pass) |
| **P3-3** | Agent | Checklist sign-off in `04-milestone-checklist.md` § Phase 3 | [x] (automated items only) |
| **P3-4** | Human | Manual walk-through of P3-1 matrix (mark pass/fail in browser) | [ ] |
| **P3-5** | Human (+ agent draft) | Retrospective notes (orchestrator hygiene, hash drift, commit gaps) | [ ] |
| **P3-6** | Human (optional) | Merge stack tip → `megiddo/rebase-20260709` | [ ] |

---

## P3-1 — Manual validation smoke matrix (HTML)

**File:** [validations/r-milestone-smoke-matrix.html](./validations/r-milestone-smoke-matrix.html)

- One smoke test per refactor milestone (18 total).
- Step-by-step: login → navigate → verify visible chrome (click/verify directions in each section).
- Embeds fuzzy-validator **test-profile baseline PNGs** from `tools/fuzzy-validator/baselines/test/` (pre-refactor setpoint reference).

**Preflight before opening HTML:**

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox
bin/fuzzy-validator setpoint restore   # if baselines missing locally
export ORK3_E2E_BASE_URL=http://127.0.0.1:19080/orkui/
bin/ork-db use prod
export ORK3_E2E_USERNAME=admin ORK3_E2E_PASSWORD=password
```

Open the HTML in a browser (`file://` or serve from repo). Walk R-01 … R-18 in order; check off each section.

---

## P3-2 — Automated audit (agent)

Run on stack tip after R-18:

| Check | Command | Pass criterion |
|-------|---------|----------------|
| No `$DB` in frontend | `rg '\$DB->' orkui/` | exit 1 (no matches) |
| No lib bypass | `rg 'Ork3::\$Lib' orkui/` | exit 1 (no matches) |
| PHPUnit | `sh bin/run-unit-tests.sh` | exit 0 |
| Fuzzy regression | `bin/fuzzy-validator validate --all --phase all` | exit 0 test + mirror |
| Playwright smoke | `npx playwright test tests/e2e/` | exit 0 (with credentials) |
| Plan complete | grep `03-implementation-plan.md` open targets | all T-* marked done in prose |

Agent orchestrator: [skills/phase3-closeout/orchestrator.prompt](./skills/phase3-closeout/orchestrator.prompt)

---

## P3-4 / P3-5 — Human-only

- **P3-4:** Subjective UI sanity via HTML matrix — agent cannot replace eyes-on verification of permissions chrome, banners, RSVP counts, etc.
- **P3-5:** Retrospective judgment; agent may draft bullets for human edit.

---

## Phase 3 checklist (04-milestone-checklist)

Copy of verification items — check in [04-milestone-checklist.md](./04-milestone-checklist.md):

- [ ] P3-1 HTML matrix produced and reviewed
- [ ] P3-4 manual smoke matrix walk-through complete (all 18 milestones)
- [ ] All ~119 target IDs in `03-implementation-plan.md` marked done
- [ ] `rg '\$DB->' orkui/` → zero
- [ ] `rg 'Ork3::\$Lib' orkui/` → zero
- [ ] Success criteria in `02-requirements.md` satisfied
- [ ] P3-2 automated gates green (PHPUnit, fuzzy, Playwright)
- [ ] P3-5 retrospective recorded

---

## Agent prompts (copy/paste)

| Purpose | Path |
|---------|------|
| Finish Phase 2 continuation (R-15 … R-18) | [skills/refactor-execution/orchestrator-phase2-continuation.prompt](./skills/refactor-execution/orchestrator-phase2-continuation.prompt) |
| Phase 3 automated close-out | [skills/phase3-closeout/orchestrator.prompt](./skills/phase3-closeout/orchestrator.prompt) |

Open the file → Ctrl+A → Ctrl+C → paste into a new agent chat. File contents are **agent-language only** (no wrapper markdown).
