# T-02 frontend functional tests (Playwright)

Playwright covers DS-02 §2.4 authorization permissions UI flows. Backend characterization lives in PHPUnit (`AuthorizationAddTest`).

## Prerequisites

```bash
docker compose -f docker-compose.php8.yml up -d
npm install
npx playwright install chromium
```

## Run

```bash
# Smoke only (permissions route) — no credentials required
npx playwright test tests/e2e/auth-permissions.spec.ts

# Authenticated flows — set dev ORK admin login
export ORK3_E2E_USERNAME='your-dev-admin'
export ORK3_E2E_PASSWORD='your-dev-password'
npx playwright test tests/e2e/auth-permissions.spec.ts
```

Override base URL if needed: `ORK3_E2E_BASE_URL=http://localhost:19080/orkui/`

## Flows mapped to DS-02 §2.4

| Flow | Spec | Notes |
|------|------|-------|
| Global permissions smoke | `admin permissions route loads` | Unauthenticated route reachability |
| Global permissions | `global permissions page loads after login` | Scaffold; extend for addauth/removeauth in R-02 |
| Kingdom permissions | `kingdom permissions page loads after login` | Scaffold; extend when stable kingdom fixture URL documented |
| Park / event permissions | TBD | Extend during R-02 with stable fixture IDs |
| Unauthorized grant | TBD | Requires non-admin test account |

Extend this spec during R-02 when AJAX handlers call `Model_Authorization::add_auth`.
