# T-02 frontend functional tests (Playwright)

Playwright covers DS-02 §2.4 authorization permissions UI flows. Backend characterization lives in PHPUnit (`AuthorizationAddTest`).

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

# Authenticated flows (required for milestone sign-off)
npx playwright test tests/e2e/auth-permissions.spec.ts
```

## Flows mapped to DS-02 §2.4

| Flow | Spec | Notes |
|------|------|-------|
| Global permissions smoke | `admin permissions route loads` | Unauthenticated route reachability |
| Global permissions | `global permissions page loads after login` | Scaffold; extend for addauth/removeauth in R-02 |
| Kingdom permissions | `kingdom permissions page loads after login` | Scaffold; extend when stable kingdom fixture URL documented |
| Park / event permissions | TBD | Extend during R-02 with stable fixture IDs |
| Unauthorized grant | TBD | Requires non-admin test account |

Extend this spec during R-02 when AJAX handlers call `Model_Authorization::add_auth`.
