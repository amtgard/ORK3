import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';

// The browser half of the Custom Border compatibility rule. The PHP half is
// Scroll::CustomBorderRule(), tested by tests/Unit/ScrollCustomBorderParityTest.php
// against this same fixture, so neither can change a rule without the other going red.
// The rules are the data file itself, rendered to JSON by PHP exactly as the page receives
// them (Scroll::CustomBorderRules()), so there is no second copy to drift. Needs the php CLI
// and the test bootstrap, but no page and no running app.
const M = require('../../../orkui/template/revised-frontend/script/scrollery-customborder.js');
const fx = JSON.parse(readFileSync('tests/fixtures/scroll-custom-border-compat.json', 'utf8'));
// PHP's output as JSON, from code run after the test bootstrap. A failure carries PHP's
// own stderr (and the start of its stdout), not just node's "Command failed".
function phpJson(code: string): any {
  const r = spawnSync('php', ['-r', 'require "tests/bootstrap.php"; ' + code], { encoding: 'utf8' });
  const say = (why: string) => new Error(`php -r failed (${why}).\nstderr:\n${r.stderr || '(empty)'}\nstdout:\n${(r.stdout || '').slice(0, 500)}`);
  if (r.error) { throw say(String(r.error)); }
  if (r.status !== 0) { throw say(`exit ${r.status}`); }
  try { return JSON.parse(r.stdout); } catch (e) { throw say(`not JSON: ${e}`); }
}
const rules = phpJson('echo json_encode(Scroll::CustomBorderRules());');

test('a PHP failure reading the rules says what PHP said', () => {
  // Assembled in PHP, so the message can only contain it if PHP's stderr is included
  // (the command line itself is always in node's error).
  expect(() => phpJson('fwrite(STDERR, "rules-marker-" . (7 * 100 + 3)); exit(3);')).toThrow(/rules-marker-703/);
});

for (const c of fx.cases) {
  test(`customBorder: ${c.name}`, () => {
    const o = fx.orders[c.order];
    const ground = c.recipe.ground || '';
    const got = M.compatible(rules, {
      order: c.order, variants: o.variants, variant: c.variant,
      min: o.from, max: o.to, rank: c.rank,
      rankSet: c.recipe.rankSet || 'common',
      ground, groundOwnBorder: fx.groundsWithOwnBorder.includes(ground),
    });
    expect(got).toBe(c.compatible);
  });
}

// JS-only: the mirror falls back to the order's first variant the way BuildScroll() picks one
// (PHP callers pass the already-resolved variant, so there is no PHP twin to share a fixture with).
const base = { min: 0, max: 10, rank: 3, rankSet: 'common', ground: '', groundOwnBorder: false };

test('customBorder: an unknown owl variant takes the first variant\'s answer', () => {
  const owl = fx.orders.owl;
  const first = M.compatible(rules, { ...base, order: 'owl', variants: owl.variants, variant: owl.variants[0] });
  expect(first).toBe(true);
  expect(M.compatible(rules, { ...base, order: 'owl', variants: owl.variants, variant: 'bogus' })).toBe(first);
});

test('customBorder: a first variant absent from passes is incompatible, and so is an unknown one', () => {
  const synthetic = { x: { passes: { b: ['mix-blend-mode:multiply;'] } } };
  const s = { ...base, order: 'x', variants: ['a', 'b'] };
  expect(M.compatible(synthetic, { ...s, variant: 'b' })).toBe(true);
  expect(M.compatible(synthetic, { ...s, variant: 'a' })).toBe(false);
  expect(M.compatible(synthetic, { ...s, variant: 'bogus' })).toBe(false);
});
