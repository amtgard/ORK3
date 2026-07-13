import type { Page } from '@playwright/test';

const STABILIZE_CSS = `
*, *::before, *::after {
  animation: none !important;
  transition: none !important;
}
input, textarea {
  caret-color: transparent !important;
}
`;

export const FIXED_CLOCK_TIME = new Date('2026-06-15T12:00:00Z');

export interface StabilizeOptions {
  readySelector?: string;
  waitAfterMs?: number;
  stableHeightMs?: number;
}

/** Apply render stabilization before each screenshot (architecture §4.3). */
export async function stabilizePage(page: Page, options: StabilizeOptions = {}): Promise<void> {
  await page.waitForLoadState('load');
  await page.addStyleTag({ content: STABILIZE_CSS });

  if (options.readySelector) {
    await page.locator(options.readySelector).first().waitFor({
      state: 'visible',
      timeout: 30_000,
    });
  }

  await page.evaluate(async () => {
    await document.fonts.ready;
    window.scrollTo(0, 0);
  });

  if (options.stableHeightMs && options.stableHeightMs > 0) {
    await waitForStableScrollHeight(page, options.stableHeightMs);
  }

  if (options.waitAfterMs && options.waitAfterMs > 0) {
    await page.waitForTimeout(options.waitAfterMs);
  }
}

/** Wait until document scroll height stops changing (AJAX widgets, lists). */
export async function waitForStableScrollHeight(
  page: Page,
  stableMs: number,
  timeoutMs = 30_000,
): Promise<void> {
  const deadline = Date.now() + timeoutMs;
  let lastHeight = -1;
  let stableSince = Date.now();

  while (Date.now() < deadline) {
    const height = await page.evaluate(() => document.body.scrollHeight);
    if (height === lastHeight) {
      if (Date.now() - stableSince >= stableMs) {
        return;
      }
    } else {
      lastHeight = height;
      stableSince = Date.now();
    }
    await page.waitForTimeout(100);
  }
}

export async function captureScreenshot(page: Page, outPath: string): Promise<void> {
  await page.screenshot({
    path: outPath,
    fullPage: true,
    animations: 'disabled',
    caret: 'hide',
  });
}
