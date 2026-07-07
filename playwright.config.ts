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
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 1280, height: 720 },
      },
    },
    {
      name: 'fuzzy-unit',
      testDir: './tools/fuzzy-validator/playwright',
      testMatch: 'registry.unit.spec.ts',
    },
  ],
});
