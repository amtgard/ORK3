#!/usr/bin/env node
/**
 * Did anything that was clean become dirty?
 *
 * The one question a fix to this class of defect must answer, and the reason the
 * baseline is captured BEFORE the first change: moving something to fix one collision
 * creates another, and without a frozen "before" that is unfalsifiable afterwards.
 *
 * Usage: node tools/scroll-sweep/bin/regression.mjs [baseline.json] [current results.json]
 */
import { readFileSync } from 'node:fs';

const T = { ca: 300, cb: 500, cc: 95 };      // must match lib/report.ts THRESHOLDS
const dirty = r => r.ca > T.ca || r.cb > T.cb || r.cc > T.cc;
const worst = r => Math.max(r.ca / T.ca, r.cb / T.cb, r.cc / T.cc);

const base = JSON.parse(readFileSync(process.argv[2] ?? 'tools/scroll-sweep/baseline/results-1to1-NA.json', 'utf8'));
const cur = JSON.parse(readFileSync(process.argv[3] ?? 'tools/scroll-sweep/out/results.json', 'utf8'));

/* THE TWO RUNS MUST HAVE BEEN DRAWN BY THE SAME DRIVER. The fixture driver has no composer
   preview, no trim or no-lines sheets and a stand-in for the kingdom's arms, so a figure
   from one is not evidence about the other, and "nothing got worse" across them would be a
   comparison of drivers. A file with no stamp predates it and was a compose run, the only
   driver there was. */
const driverOf = f => f.driver ?? 'compose';
if (driverOf(base) !== driverOf(cur)) {
  console.error(`refusing to compare: the baseline was measured by the ${driverOf(base)} driver `
    + `and the current run by the ${driverOf(cur)} driver. Re-run with ORK3_SWEEP_DRIVER=${driverOf(base)}, `
    + `or capture a new baseline with the driver you mean to compare.`);
  process.exit(2);
}

/* A SAMPLED run holds about a fifth of the full matrix (ORK3_SWEEP_SAMPLE, lib/cases.ts), and
   every sampled case is a case of the full one with the same id. So two files are compared on
   the case ids they BOTH hold and the output says how many that is. A baseline case the run
   does not hold is expected when the two disagree about sampling, and is reported; when they
   agree it was meant to be re-run, and a dirty one that was not is still an unverified hole.
   A file with no stamp was a full matrix. */
const sampleOf = f => f.sample ?? false;
const sameSample = sampleOf(base) === sampleOf(cur);

const norm = rs => Object.fromEntries(rs.map(r => [r.id, r.ca !== undefined ? r : {
  id: r.id, ca: r.CA.score * r.glyphPx / 1000, cb: r.CB.score * r.glyphPx / 1000,
  cc: r.CC.score * r.glyphPx / 1000,
}]));

const B = norm(base.results);
const C = norm(cur.results ?? cur);

const shared = Object.keys(C).filter(id => id in B);
const regressed = shared.filter(id => dirty(C[id]) && !dirty(B[id]));
const fixed = shared.filter(id => !dirty(C[id]) && dirty(B[id]));
const stillDirty = shared.filter(id => dirty(C[id]) && dirty(B[id]));
const missing = Object.keys(B).filter(id => !(id in C));

console.log(`compared ${shared.length} cases common to both`
  + (sameSample ? '' : ` (baseline sample=${sampleOf(base)}, this run sample=${sampleOf(cur)})`));
console.log(`fixed         ${fixed.length}`);
console.log(`still dirty   ${stillDirty.length}`);
console.log(`REGRESSED     ${regressed.length}`);
/* A baseline case that was DIRTY and was not re-run is an unverified claim, not a
   pass. Reported separately and loudly: the first comparison after the ornament fix
   silently skipped 448 of them because stage D expands from a moving target. */
const missingDirty = sameSample ? missing.filter(id => dirty(B[id])) : [];
if (missing.length && sameSample) console.log(`not re-run    ${missing.length} (${missingDirty.length} were dirty)`);
else if (missing.length) console.log(`${missing.length} baseline case(s) not in this run, which samples differently; not compared`);
for (const id of regressed.sort((a, b) => worst(C[b]) - worst(C[a])).slice(0, 25)) {
  const b = B[id], c = C[id];
  console.log(`  ! ${id}`);
  console.log(`      A ${b.ca.toFixed(0)}->${c.ca.toFixed(0)}  B ${b.cb.toFixed(0)}->${c.cb.toFixed(0)}  C ${b.cc.toFixed(0)}->${c.cc.toFixed(0)}`);
}
if (missingDirty.length) {
  console.log(`\n  ${missingDirty.length} baseline-dirty cases were not re-run — their state is UNVERIFIED:`);
  for (const id of missingDirty.slice(0, 10)) console.log(`      ? ${id}`);
}
process.exit(regressed.length || missingDirty.length ? 1 : 0);
