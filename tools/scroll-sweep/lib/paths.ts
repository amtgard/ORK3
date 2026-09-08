import { join } from 'node:path';

/** Where the sweep writes. Shared by the runner, the merge step and the setup project. */
export const OUT_DIR = process.env.ORK3_SWEEP_OUT ?? join(__dirname, '..', 'out');

/** The one shared login. See auth.setup.ts for why there is only one. */
export const AUTH_STATE = join(OUT_DIR, '.auth.json');
