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

  if (options.waitAfterMs && options.waitAfterMs > 0) {
    await page.waitForTimeout(options.waitAfterMs);
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
