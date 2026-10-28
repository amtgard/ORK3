import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';

// The browser half of the Custom Border compatibility rule. The PHP half is
// Scroll::CustomBorderRule(), tested by tests/Unit/ScrollCustomBorderParityTest.php
// against this same fixture, so neither can change a rule without the other going red.
// The rules are the data file itself, rendered to JSON by PHP exactly as the page receives
// them (Scroll::CustomBorderRules()), so there is no second copy to drift. Needs the php CLI
// and the test bootstrap, but no page and no running app.
const M = require('../../../orkui/template/revised-frontend/script/scrollery-customborder.js');
const fx = JSON.parse(readFileSync('tests/fixtures/scroll-custom-border-compat.json', 'utf8'));
const rules = JSON.parse(execFileSync('php', [
  '-r', 'require "tests/bootstrap.php"; echo json_encode(Scroll::CustomBorderRules());',
], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }));

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
