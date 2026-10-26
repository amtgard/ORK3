import { test } from '@playwright/test';
import { readdirSync, readFileSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { writeReport, isDirty, type Driver, type Result, type Run } from './lib/report';

const OUT = process.env.ORK3_SWEEP_OUT ?? join(__dirname, 'out');

/**
 * Join the shards into one results.json and one report.md.
 *
 * A separate Playwright PROJECT with `dependencies: ['scroll-sweep']` rather than an
 * afterAll hook, for two reasons: the shards run in parallel workers and two workers
 * writing one file race, and a dependency is the only thing that actually orders work
 * across workers. It is a Playwright test rather than a plain node script because
 * lib/report.ts is TypeScript and the runner is the only transpiler in this repo.
 */
test('merge shards', () => {
  const shards = readdirSync(OUT).filter(f => /^shard-\d+\.json$/.test(f));
  if (!shards.length) {
    /* Not an error: a single-shard run writes the report itself and leaves nothing to
       merge. Saying so beats failing a run that succeeded. */
    console.log('no shard files — nothing to merge');
    return;
  }
  /* A shard is `{ driver, sample, results }`. A bare array is a shard from before the stamp:
     compose, full matrix, the only way there was. */
  type Shard = Run & { results: Result[] };
  const parsed = shards.map(f => {
    const j = JSON.parse(readFileSync(join(OUT, f), 'utf8')) as Result[] | (Partial<Run> & { results: Result[] });
    return Array.isArray(j)
      ? { driver: 'compose' as Driver, sample: false, results: j }
      : { driver: j.driver ?? 'compose', sample: j.sample ?? false, results: j.results } as Shard;
  });
  /* Shards of one run share one environment, so two stamps here means stale files from an
     earlier run are mixed in. A report that blends them describes neither. */
  const stamps = new Set(parsed.map(p => `${p.driver}/sample=${p.sample}`));
  if (stamps.size !== 1) {
    throw new Error(`shards were written by different runs (${[...stamps].join(', ')}); `
      + `delete ${OUT}/shard-*.json and re-run`);
  }
  const results = parsed.flatMap(p => p.results);
  writeReport(results, OUT, 0, { driver: parsed[0].driver, sample: parsed[0].sample });
  for (const f of shards) rmSync(join(OUT, f));
  console.log(`merged ${shards.length} shards, ${results.length} cases, `
    + `${results.filter(isDirty).length} over threshold -> ${join(OUT, 'report.md')}`);
});
