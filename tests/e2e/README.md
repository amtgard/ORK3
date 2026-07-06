# T-01 frontend functional tests (Playwright)

Playwright covers DS-01 §2.4 RSVP UI flows. Backend characterization lives in PHPUnit (`EventRsvp*` tests).

## Prerequisites

```bash
docker compose -f docker-compose.php8.yml up -d
npm install
npx playwright install chromium
```

## Run

```bash
# Smoke only (home page) — no credentials required
npx playwright test tests/e2e/rsvp.spec.ts

# Full authenticated flows — set dev login credentials
export ORK3_E2E_USERNAME='your-dev-user'
export ORK3_E2E_PASSWORD='your-dev-password'
npx playwright test tests/e2e/rsvp.spec.ts
```

Override base URL if needed: `ORK3_E2E_BASE_URL=http://localhost:19080/orkui/`

## Flows mapped to DS-01 §2.4

| Flow | Spec | Notes |
|------|------|-------|
| Home widget | `home page loads event search widget` | Smoke; RSVP totals asserted in PHPUnit search test |
| Event detail AJAX RSVP | Extend spec when stable event fixture URL documented | Blocked on seeded event URL |
| Player profile upcoming | `player profile page loads after login` | Scaffold; extend for RSVP list |
| Staff RSVP list | TBD | Requires staff test account |

Extend this spec during R-01 when controllers call service APIs and stable routes exist.
