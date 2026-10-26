import { test } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { writeReport, isDirty, type Driver, type Result } from './lib/report';

const OUT = process.env.ORK3_SWEEP_OUT ?? join(__dirname, 'out');

/**
 * Re-emit report.md from an existing results.json, without re-running the sweep.
 *
 * The verdict is derived from what the sweep measures, not measured separately, so a
 * change to how damage is scored or where the line sits can be applied to a run that
 * already happened. That is what let a thirty-minute baseline survive the discovery
 * that the first metric was measuring how much text a certificate had.
 *
 * Run: npx playwright test --project=scroll-sweep-rescore
 */
test('rescore', () => {
  const { results, driver, sample } = JSON.parse(readFileSync(join(OUT, 'results.json'), 'utf8')) as
    { results: Result[]; driver?: Driver; sample?: boolean };
  /* Re-scoring does not change who measured it or over what. A file from before the stamp was
     compose, full matrix. */
  writeReport(results, OUT, 0, { driver: driver ?? 'compose', sample: sample ?? false });
  console.log(`rescored ${results.length} cases, ${results.filter(isDirty).length} over threshold`);
});
