/**
 * Heraldry visual sign-off (TD-11d) — sandbox kingdom/park shields and player avatars.
 */
import { test, expect } from '@playwright/test';

async function appReachable(baseURL: string): Promise<boolean> {
  try {
    const res = await fetch(baseURL, { method: 'HEAD' });
    return res.ok || res.status === 302 || res.status === 405;
  } catch {
    return false;
  }
}

test.beforeEach(async ({ baseURL }, testInfo) => {
  if (!baseURL || !(await appReachable(baseURL))) {
    testInfo.skip(true, 'ORK3 app not reachable — start docker compose php8 stack');
  }
});

test('kingdom profile shows test kingdom heraldry', async ({ page }) => {
  await page.goto('./index.php?Route=Kingdom/profile/100001');
  await expect(page.locator('body')).toBeVisible();
  await expect(page.locator('.heraldry-img[src*="heraldry/kingdom/100001."]')).toBeVisible();
});

test('park profile shows test park heraldry', async ({ page }) => {
  await page.goto('./index.php?Route=Park/profile/1000001');
  await expect(page.locator('body')).toBeVisible();
  await expect(page.locator('.heraldry-img[src*="heraldry/park/1000001."]')).toBeVisible();
});

async function resolveReachableAssetUrl(request: import('@playwright/test').APIRequestContext, url: string): Promise<string> {
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

test('kingdom roster serves heraldry avatars for flagged fake players', async ({ request }) => {
  const response = await request.get('./index.php?Route=Kingdom/players_json/100001');
  expect(response.ok()).toBeTruthy();

  const payload = await response.json();
  const fakeWithAvatar = (payload.players ?? []).find(
    (player: { id: number; avatarUrl?: string | null }) =>
      player.id >= 100000000 && typeof player.avatarUrl === 'string' && player.avatarUrl.length > 0,
  );

  expect(fakeWithAvatar).toBeTruthy();
  const avatarUrl = await resolveReachableAssetUrl(request, fakeWithAvatar.avatarUrl.replace('://localhost:', '://127.0.0.1:'));
  const avatarResponse = await request.get(avatarUrl);
  expect(avatarResponse.status()).toBe(200);
});
