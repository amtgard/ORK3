# T-01 frontend functional tests (Playwright)

Playwright covers DS-01 §2.4 RSVP UI flows. Backend characterization lives in PHPUnit (`EventRsvp*` tests).

## Prerequisites

```bash
docker compose -f docker-compose.php8.yml up -d
npm install
npx playwright install chromium
```

## Run

Canonical credentials: [06-test-framework.md § E2E login credentials](../../docs/megiddo/refactor/06-test-framework.md#e2e-login-credentials-preflight) — **local docker only**.

```bash
export ORK3_E2E_BASE_URL=http://127.0.0.1:19080/orkui/
bin/ork-db use prod
export ORK3_E2E_USERNAME=admin ORK3_E2E_PASSWORD=password

# Full authenticated flows (default — do not sign off with smoke-only)
npx playwright test tests/e2e/rsvp.spec.ts

# Full e2e suite
npx playwright test tests/e2e/
```

Sandbox (`ork_test`): `bin/ork-db use dev` and `ORK3_E2E_USERNAME=megiddo ORK3_E2E_PASSWORD=test-db-player`.

## Flows mapped to DS-01 §2.4

| Flow | Spec | Notes |
|------|------|-------|
| Home widget | `home page loads event search widget` | Smoke; RSVP totals asserted in PHPUnit search test |
| Event detail AJAX RSVP | Extend spec when stable event fixture URL documented | Blocked on seeded event URL |
| Player profile upcoming | `player profile page loads after login` | Scaffold; extend for RSVP list |
| Staff RSVP list | TBD | Requires staff test account |

Extend this spec during R-01 when controllers call service APIs and stable routes exist.
