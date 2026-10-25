import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.ORK3_E2E_BASE_URL ?? 'http://127.0.0.1:19080/orkui/';

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  /* The scroll sweep shards across workers; everything else in this repo is serial and
     stays that way. ORK3_SWEEP_WORKERS is what the sweep npm script sets. */
  workers: Number(process.env.ORK3_SWEEP_WORKERS ?? 1),
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
      // One login, shared by every shard. See tools/scroll-sweep/auth.setup.ts: the app
      // keeps a single-valued ork_mundane.token pointer beside the multi-device
      // ork_session store, so six workers each logging in as the same officer knock
      // each other out and half the matrix silently fails to render.
      name: 'scroll-sweep-setup',
      testDir: './tools/scroll-sweep',
      testMatch: 'auth.setup.ts',
    },
    {
      // deviceScaleFactor 1 and a 2550-wide viewport are load-bearing: the certificate
      // SVG is width:100% inside viewBox="0 0 2550 3300", so this puts one CSS pixel on
      // one frame pixel and makes every measurement scale-free.
      name: 'scroll-sweep',
      testDir: './tools/scroll-sweep',
      testMatch: 'sweep.spec.ts',
      fullyParallel: true,
      dependencies: ['scroll-sweep-setup'],
      timeout: 0,
      use: {
        ...devices['Desktop Chrome'],
        // Tall enough to hold the whole 2550x3300 certificate. screenshot({ clip })
        // is clamped to the VIEWPORT, so a short viewport silently truncates the
        // lower band and every sign-and-date measurement would read clean.
        viewport: { width: 2600, height: 3400 },
        deviceScaleFactor: 1,
        storageState: (process.env.ORK3_SWEEP_OUT ?? './tools/scroll-sweep/out') + '/.auth.json',
      },
    },
    {
      // The frozen review corpus (or any corpus file) rendered through the fixture renderer,
      // so it needs no login. See corpus.spec.ts. Three times device scale because the
      // contact sheet is read by eye; line counts and sizes are layout measurements and do
      // not depend on it. The viewport is tall enough to hold the whole certificate.
      name: 'scroll-corpus',
      testDir: './tools/scroll-sweep',
      testMatch: 'corpus.spec.ts',
      timeout: 0,
      use: {
        ...devices['Desktop Chrome'],
        viewport: { width: 2600, height: 3500 },
        deviceScaleFactor: 3,
      },
    },
    {
      // Re-emits the report from an existing results.json. See rescore.spec.ts.
      name: 'scroll-sweep-rescore',
      testDir: './tools/scroll-sweep',
      testMatch: 'rescore.spec.ts',
      timeout: 0,
    },
    {
      // Joins the shards. A dependency, not an afterAll hook: two workers writing one
      // results file race, and a project dependency is the only thing that orders work
      // across workers.
      name: 'scroll-sweep-merge',
      testDir: './tools/scroll-sweep',
      testMatch: 'merge.spec.ts',
      dependencies: ['scroll-sweep'],
      timeout: 0,
    },
  ],
});
