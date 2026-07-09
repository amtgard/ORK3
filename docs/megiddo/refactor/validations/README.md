# Megiddo Refactor — Validation Artifacts (Phase 1.6)

Per-domain **canary URLs** and **test mutation boundaries** for R-* execution sprints. Produced in Phase 1.6 (**V-*** milestones); consumed at R-* sign-off with `bin/fuzzy-validator` and Infection.

**Plan:** [08-phase-16-validation-artifacts.md](../08-phase-16-validation-artifacts.md) · **Checklist:** [04-milestone-checklist.md § Phase 1.6](../04-milestone-checklist.md#phase-16--validation-artifacts) · **Agent prompt:** [09-v-phase-agent-prompt.md](../09-v-phase-agent-prompt.md)

---

## Documents

| ID | File | Domain | R-* |
|----|------|--------|-----|
| **V-00** | [v-00-fuzzy-setpoint.md](./v-00-fuzzy-setpoint.md) | Global major-interface setpoint | All |
| V-01 | [v-01-rsvp-validation.md](./v-01-rsvp-validation.md) | RSVP / events | R-01 |
| V-02 | [v-02-auth-validation.md](./v-02-auth-validation.md) | Authorization INSERT | R-02 |
| V-03 | [v-03-banner-validation.md](./v-03-banner-validation.md) | Banners | R-03 |
| V-04 | [v-04-eventajax-validation.md](./v-04-eventajax-validation.md) | EventAjax | R-04 |
| V-05 | [v-05-event-validation.md](./v-05-event-validation.md) | Event controller | R-05 |
| V-06 | [v-06-kingdom-validation.md](./v-06-kingdom-validation.md) | Kingdom | R-06 |
| V-07 | [v-07-park-validation.md](./v-07-park-validation.md) | Park | R-07 |
| V-08 | [v-08-admin-validation.md](./v-08-admin-validation.md) | Admin | R-08 |
| V-09 | [v-09-player-validation.md](./v-09-player-validation.md) | Player | R-09 |
| V-10 | [v-10-reports-validation.md](./v-10-reports-validation.md) | Reports / awards | R-10 |
| V-11 | *(planned)* | Search | R-11 |
| V-12 | *(planned)* | Attendance / sign-in | R-12 |
| V-13 | *(planned)* | Infrastructure | R-13 |
| V-14 | *(planned)* | Ork3::$Lib / lib-service | R-14 |

**Template:** [_template-validation.md](./_template-validation.md)

---

## Registry locations (implementation)

| Artifact | Location |
|----------|----------|
| Global setpoint page ids | `tools/fuzzy-validator/manifests/pages.json5` (V-00) |
| Domain canary page ids | Same registry — entries tagged in validation doc §1 |
| Fuzzy baselines | `tools/fuzzy-validator/baselines/{test\|mirror}/` |
| Playwright e2e (behavior) | `tests/e2e/*.spec.ts` (T-* sprints) |

---

## Sign-off commands (R-*)

```bash
bin/ork-db deploy-sandbox
bin/fuzzy-validator validate --pages <ids-from-v-NN.md> --phase all
# Default: test (strict) + mirror (lenient) — see fuzzy-validator 11-dual-database-profiles.md
```

Requires [E2E login preflight](../06-test-framework.md#e2e-login-credentials-preflight). Dual-profile `validate` is implemented (FU-11); baselines via `record` or `setpoint restore` (FU-16).
