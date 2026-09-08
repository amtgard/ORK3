import { test as setup } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { login } from './lib/auth';
import { AUTH_STATE } from './lib/paths';

/**
 * Log in ONCE and share the session with every shard.
 *
 * The sweep first had each worker log in for itself, and roughly half of ~1,300 cases
 * came back with no certificate on the page. The cause is in class.Authorization.php:
 * ork_session is a multi-device store, but a login also writes the "vestigial"
 * ork_mundane.token pointer, which holds ONE value — so six workers logging in as the
 * same officer knock each other out, and a worker that was fine a moment ago starts
 * being redirected to the login page mid-run.
 *
 * Logging in once and handing every worker the same cookie fixes it at the source and
 * is faster besides. It also means the sweep exercises exactly one session, which is
 * what an officer printing a court's worth of certificates actually has.
 */
setup('authenticate', async ({ page }) => {
  mkdirSync(dirname(AUTH_STATE), { recursive: true });
  await login(page);
  await page.context().storageState({ path: AUTH_STATE });
});
