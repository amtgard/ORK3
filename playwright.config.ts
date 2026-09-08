import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.ORK3_E2E_BASE_URL ?? 'http://127.0.0.1:19080/orkui/';

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: 'list',
  use: {
    baseURL,
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'fuzzy-capture',
      testDir: './tools/fuzzy-validator/playwright',
      testMatch: 'capture.spec.ts',
      timeout: 300_000,
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1280, height: 720 },
      },
    },
    {
      name: 'fuzzy-unit',
      testDir: './tools/fuzzy-validator/playwright',
      testMatch: '*.unit.spec.ts',
    },
    {
      name: 'scroll-sweep-unit',
      testDir: './tools/scroll-sweep/tests',
      use: { ...devices['Desktop Chrome'] },
    },
    {
      // deviceScaleFactor 1 and a 2550-wide viewport are load-bearing: the certificate
      // SVG is width:100% inside viewBox="0 0 2550 3300", so this puts one CSS pixel on
      // one frame pixel and makes every measurement scale-free.
      name: 'scroll-sweep',
      testDir: './tools/scroll-sweep',
      testMatch: 'sweep.spec.ts',
      timeout: 0,
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 2550, height: 1400 },
        deviceScaleFactor: 1,
      },
    },
  ],
});
