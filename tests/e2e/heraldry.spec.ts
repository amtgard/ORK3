/**
 * Heraldry visual sign-off (TD-11d) — sandbox kingdom/park shields and player avatars.
 *
 * Preflight (required — fake IDs 100001 / 1000001 / players ≥100000000 exist only on sandbox):
 *   bin/ork-db deploy-sandbox
 *   bin/ork-db use dev
 *   export ORK3_E2E_BASE_URL=http://127.0.0.1:19080/orkui/
 *   export ORK3_E2E_USERNAME=megiddo ORK3_E2E_PASSWORD=test-db-player
 *   npx playwright test tests/e2e/heraldry.spec.ts
 *
 * Mirror-profile full runs must exclude this file: `npx playwright test tests/e2e/ --grep-invert heraldry`
 * See docs/megiddo/refactor/06-test-framework.md § Playwright DB profiles.
 */
import { test, expect, type APIRequestContext } from '@playwright/test';

const SANDBOX_KINGDOM_ID = 100001;
const SANDBOX_PARK_ID = 1000001;

async function appReachable(baseURL: string): Promise<boolean> {
  try {
    const res = await fetch(baseURL, { method: 'HEAD' });
    return res.ok || res.status === 302 || res.status === 405;
  } catch {
    return false;
  }
}

async function isSandboxProfile(request: APIRequestContext): Promise<boolean> {
  const response = await request.get(`./index.php?Route=Kingdom/players_json/${SANDBOX_KINGDOM_ID}`);
  if (!response.ok()) {
    return false;
  }

  const payload = await response.json();
  return (payload.players ?? []).some((player: { id: number }) => player.id >= 100000000);
}

test.describe('sandbox heraldry', () => {
  test.beforeEach(async ({ baseURL, request }, testInfo) => {
    if (!baseURL || !(await appReachable(baseURL))) {
      testInfo.skip(true, 'ORK3 app not reachable — start docker compose php8 stack');
      return;
    }

    if (!(await isSandboxProfile(request))) {
      testInfo.skip(
        true,
        'Sandbox profile required — run: bin/ork-db deploy-sandbox && bin/ork-db use dev (mirror lacks kingdom 100001 fake roster)',
      );
    }
  });

  test('kingdom profile shows test kingdom heraldry', async ({ page }) => {
    await page.goto(`./index.php?Route=Kingdom/profile/${SANDBOX_KINGDOM_ID}`);
    await expect(page.locator('body')).toBeVisible();
    await expect(page.locator(`.heraldry-img[src*="heraldry/kingdom/${SANDBOX_KINGDOM_ID}."]`)).toBeVisible();
  });

  test('park profile shows test park heraldry', async ({ page }) => {
    await page.goto(`./index.php?Route=Park/profile/${SANDBOX_PARK_ID}`);
    await expect(page.locator('body')).toBeVisible();
    await expect(page.locator(`.heraldry-img[src*="heraldry/park/${SANDBOX_PARK_ID}."]`)).toBeVisible();
  });

  test('kingdom roster serves heraldry avatars for flagged fake players', async ({ request }) => {
    const response = await request.get(`./index.php?Route=Kingdom/players_json/${SANDBOX_KINGDOM_ID}`);
    expect(response.ok()).toBeTruthy();

    const payload = await response.json();
    const fakeWithAvatar = (payload.players ?? []).find(
      (player: { id: number; avatarUrl?: string | null }) =>
        player.id >= 100000000 && typeof player.avatarUrl === 'string' && player.avatarUrl.length > 0,
    );

    expect(fakeWithAvatar).toBeTruthy();
    const avatarUrl = await resolveReachableAssetUrl(
      request,
      fakeWithAvatar.avatarUrl.replace('://localhost:', '://127.0.0.1:'),
    );
    const avatarResponse = await request.get(avatarUrl);
    expect(avatarResponse.status()).toBe(200);
  });
});

async function resolveReachableAssetUrl(request: APIRequestContext, url: string): Promise<string> {
  let response = await request.head(url);
  if (response.status() === 200) {
    return url;
  }

  if (url.endsWith('.png')) {
    const alternate = url.slice(0, -4) + '.jpg';
    response = await request.head(alternate);
    if (response.status() === 200) {
      return alternate;
    }
  }

  return url;
}
