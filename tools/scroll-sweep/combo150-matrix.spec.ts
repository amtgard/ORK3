import { test } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join, posix, relative, sep } from 'node:path';
import { generate, batches, composeUrl, summary, COMBO_SEED, type Expectation } from './lib/combos';
import { COMBO_OUT, COMBO_RERUN } from './lib/combo-paths';

/**
 * Step 1 of the 150-combination sweep: deal the matrix (lib/combos.ts), ask the app's own rules
 * what each case should draw (bin/combo150-expect.php, inside the app container), and write
 * matrix.json and batches.json BEFORE anything renders. combo150.spec.ts reads the file, so
 * every shard renders the same cases and a failed render is reproducible by id.
 */
test('combo150 matrix', async () => {
  if (COMBO_RERUN) throw new Error('ORK3_COMBO_TAG is set: a rerun reuses the saved matrix.json and never regenerates it');
  mkdirSync(COMBO_OUT, { recursive: true });
  const cases = generate(COMBO_SEED);
  const file = join(COMBO_OUT, 'matrix.json');
  writeFileSync(file, JSON.stringify({ seed: COMBO_SEED, count: cases.length, cases }, null, 1));

  const repo = join(__dirname, '..', '..');
  const root = process.env.ORK3_CONTAINER_ROOT ?? '/var/www/ork.amtgard.com';
  const inContainer = (p: string) => posix.join(root, ...relative(repo, p).split(sep));
  const r = spawnSync('docker', [
    'exec', process.env.ORK3_APP_CONTAINER ?? 'ork3-php8-app', 'php',
    inContainer(join(__dirname, 'bin', 'combo150-expect.php')), inContainer(file),
  ], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  if (r.status !== 0) throw new Error(`combo150-expect.php exited ${r.status}\n${r.stderr}${r.stdout}`);
  const expect = JSON.parse(r.stdout) as Record<string, Expectation>;
  for (const c of cases) {
    if (!expect[c.id]) throw new Error(`no expectation for ${c.id}`);
    c.expect = expect[c.id];
  }
  writeFileSync(file, JSON.stringify({
    seed: COMBO_SEED, count: cases.length, generated: new Date().toISOString(),
    cases: cases.map(c => ({ ...c, url: composeUrl(c), summary: summary(c) })),
  }, null, 1));
  const b = batches(cases);
  writeFileSync(join(COMBO_OUT, 'batches.json'), JSON.stringify(
    b.map((ids, i) => ({ batch: i + 1, count: ids.length, ids })), null, 1));
});
