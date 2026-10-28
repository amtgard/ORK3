#!/usr/bin/env node
/*
 * Refresh the saved 150-combination matrix's expectations in place.
 *
 * A rerun (ORK3_COMBO_TAG) reuses tools/scroll-sweep/out/combo150/matrix.json and never
 * regenerates it, so each case's `expect` (what the app's rules say it should draw) is
 * frozen at the day the matrix was dealt. When a rule changes on purpose, for example
 * keyed scroll heraldry (ScrollHeraldry) or a Custom Border rule, correct renders start
 * failing the design checks against the stale answer. This asks bin/combo150-expect.php
 * again, inside the app container, and replaces `expect` on every case. The cases, their
 * ids, urls and summaries are untouched, so before images stay comparable.
 *
 *   node tools/scroll-sweep/bin/combo150-refresh-expect.mjs [matrix.json]
 *
 * The first run keeps the original beside it as matrix.json.orig.
 * Env: ORK3_APP_CONTAINER (ork3-php8-app), ORK3_CONTAINER_ROOT (/var/www/ork.amtgard.com).
 */
import { spawnSync } from 'node:child_process';
import { copyFileSync, existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, posix, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repo = resolve(here, '..', '..', '..');
const file = resolve(process.argv[2] ?? join(repo, 'tools/scroll-sweep/out/combo150/matrix.json'));
const root = process.env.ORK3_CONTAINER_ROOT ?? '/var/www/ork.amtgard.com';
const inContainer = (p) => posix.join(root, ...relative(repo, p).split(sep));

const matrix = JSON.parse(readFileSync(file, 'utf8'));
if (!Array.isArray(matrix.cases)) { throw new Error(`${file} has no cases array`); }

const r = spawnSync('docker', [
  'exec', process.env.ORK3_APP_CONTAINER ?? 'ork3-php8-app', 'php',
  inContainer(join(here, 'combo150-expect.php')), inContainer(file),
], { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
if (r.status !== 0) { throw new Error(`combo150-expect.php exited ${r.status}\n${r.stderr}${r.stdout}`); }
const fresh = JSON.parse(r.stdout);

let changed = 0;
for (const c of matrix.cases) {
  if (!fresh[c.id]) { throw new Error(`no expectation for ${c.id}`); }
  if (JSON.stringify(c.expect) !== JSON.stringify(fresh[c.id])) { changed++; }
  c.expect = fresh[c.id];
}

if (!existsSync(file + '.orig')) { copyFileSync(file, file + '.orig'); }
matrix.expectRefreshed = new Date().toISOString();
writeFileSync(file, JSON.stringify(matrix, null, 1));
console.log(`${file}: ${changed} of ${matrix.cases.length} expectations changed`);
