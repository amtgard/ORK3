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

const T = { ca: 30, cb: 40, cc: 8 };          // must match lib/report.ts THRESHOLDS
const dirty = r => r.ca > T.ca || r.cb > T.cb || r.cc > T.cc;
const worst = r => Math.max(r.ca / T.ca, r.cb / T.cb, r.cc / T.cc);

const base = JSON.parse(readFileSync(process.argv[2] ?? 'tools/scroll-sweep/baseline/results.json', 'utf8'));
const cur = JSON.parse(readFileSync(process.argv[3] ?? 'tools/scroll-sweep/out/results.json', 'utf8'));

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

console.log(`compared      ${shared.length} cases`);
console.log(`fixed         ${fixed.length}`);
console.log(`still dirty   ${stillDirty.length}`);
console.log(`REGRESSED     ${regressed.length}`);
/* A baseline case that was DIRTY and was not re-run is an unverified claim, not a
   pass. Reported separately and loudly: the first comparison after the ornament fix
   silently skipped 448 of them because stage D expands from a moving target. */
const missingDirty = missing.filter(id => dirty(B[id]));
if (missing.length) console.log(`not re-run    ${missing.length} (${missingDirty.length} were dirty)`);
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
