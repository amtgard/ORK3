# Megiddo Refactor — Remaining Validation Material

The per-domain V-* validation artifacts supported completed R-* migrations and are preserved in [archive/validations/](../archive/validations/). They are historical reference, not an active execution queue.

---

## Active validation

- **P3-4 manual smoke:** [r-milestone-smoke-matrix.html](./r-milestone-smoke-matrix.html) — one human smoke per R-* milestone.
- **Close-out context:** [11-phase-3-closeout.md](../11-phase-3-closeout.md).

---

## Registry locations (implementation)

| Artifact | Location |
|----------|----------|
| Global setpoint page ids | `tools/fuzzy-validator/manifests/pages.json5` (V-00) |
| Domain canary page ids | Same registry — entries tagged in validation doc §1 |
| Fuzzy baselines | `tools/fuzzy-validator/baselines/{test\|mirror}/` |
| Playwright e2e (behavior) | `tests/e2e/*.spec.ts` (T-* sprints) |

---

## Historical sign-off commands

```bash
bin/ork-db deploy-sandbox
bin/fuzzy-validator validate --pages <ids-from-v-NN.md> --phase all
# Default: test (strict) + mirror (lenient) — see fuzzy-validator 11-dual-database-profiles.md
```

Requires [E2E login preflight](../06-test-framework.md#e2e-login-credentials-preflight). These commands remain useful when preparing the local environment for the manual smoke.
