/**
 * Fuzzy Validator — stabilized Playwright capture (FU-1).
 * Driven by manifests/pages.json5 and FUZZ_PAGES env (comma-separated ids).
 */
import fs from 'fs';
import path from 'path';
import { test } from '@playwright/test';
import { hasAuthCredentials, login } from './lib/auth';
import { loadPagesRegistry, parsePageIdList, resolveRequestedPages } from './lib/pages';
import { appReachable } from './lib/reachable';
import { startAssetCapture } from './lib/captureAssets';
import { captureDomHtml } from './lib/captureDom';
import { FIXED_CLOCK_TIME, captureScreenshot, stabilizePage } from './lib/stabilize';

const TOOL_ROOT = process.env.FUZZ_TOOL_ROOT || path.join(__dirname, '..');
const requestedIds = parsePageIdList(process.env.FUZZ_PAGES);
const registry = loadPagesRegistry();
const capturePages = resolveRequestedPages(registry, requestedIds);

function repeatCount(pageRepeat: number | undefined): number {
  if (process.env.FUZZ_REPEAT) {
    const parsed = Number.parseInt(process.env.FUZZ_REPEAT, 10);
    if (!Number.isNaN(parsed) && parsed > 0) {
      return parsed;
    }
  }
  return pageRepeat ?? registry.defaults.repeat ?? 5;
}

const BLOCKED_ASSET_HOSTS = [
  'googletagmanager.com',
  'google-analytics.com',
  'www.google-analytics.com',
];

async function blockVolatileThirdPartyAssets(page: import('@playwright/test').Page): Promise<void> {
  await page.route('**/*', (route) => {
    const hostname = new URL(route.request().url()).hostname;
    if (BLOCKED_ASSET_HOSTS.some((host) => hostname === host || hostname.endsWith(`.${host}`))) {
      return route.abort();
    }
    return route.continue();
  });
}

for (const pageEntry of capturePages) {
  test(`capture ${pageEntry.id}`, async ({ page, baseURL }, testInfo) => {
    if (!baseURL || !(await appReachable(baseURL))) {
      testInfo.skip(true, 'ORK3 app not reachable — start docker compose php8 stack');
    }

    const authMode = pageEntry.auth ?? registry.defaults.auth ?? 'none';
    if (authMode === 'login' && !hasAuthCredentials()) {
      testInfo.skip(true, 'Set ORK3_E2E_USERNAME and ORK3_E2E_PASSWORD for login capture');
    }

    const viewport = pageEntry.viewport ?? registry.defaults.viewport;
    await page.setViewportSize(viewport);
    await blockVolatileThirdPartyAssets(page);
    await page.clock.install({ time: FIXED_CLOCK_TIME });

    if (authMode === 'login') {
      await login(page);
    }

    const waitAfterMs = pageEntry.waitAfterMs ?? registry.defaults.waitAfterMs ?? 500;
    const singleCapture = process.env.FUZZ_MODE === 'candidate';
    const repeat = singleCapture ? 1 : repeatCount(pageEntry.repeat);
    const outDir = path.join(TOOL_ROOT, 'calibrations', pageEntry.id);
    fs.mkdirSync(outDir, { recursive: true });

    for (let run = 1; run <= repeat; run += 1) {
      const runLabel = singleCapture ? 'candidate' : `run-${String(run).padStart(3, '0')}`;
      const captureUrl = new URL(pageEntry.url, baseURL!).href;
      const assetSession = startAssetCapture(page, captureUrl);

      await page.goto(pageEntry.url, { waitUntil: 'load' });
      await stabilizePage(page, {
        readySelector: pageEntry.readySelector,
        waitAfterMs,
      });

      const fileName = singleCapture ? 'candidate.png' : `${runLabel}.png`;
      const outPath = path.join(outDir, fileName);
      await captureScreenshot(page, outPath);

      const domLabel = singleCapture ? 'candidate' : runLabel;
      const domPath = path.join(outDir, `${domLabel}.dom.html`);
      await captureDomHtml(page, domPath);

      await assetSession.finish({
        pageId: pageEntry.id,
        runLabel,
        outDir,
      });
    }
  });
}
