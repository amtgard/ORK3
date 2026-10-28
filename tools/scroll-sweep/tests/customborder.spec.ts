import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';

// The browser half of the Custom Border compatibility rule. The PHP half is
// Scroll::CustomBorderRule(), tested by tests/Unit/ScrollCustomBorderParityTest.php
// against this same fixture, so neither can change a rule without the other going red.
// The rules are the data file itself, rendered to JSON by PHP as the page receives them.
// Pure Node: no page and no app needed.
const M = require('../../../orkui/template/revised-frontend/script/scrollery-customborder.js');
const fx = JSON.parse(readFileSync('tests/fixtures/scroll-custom-border-compat.json', 'utf8'));
const rules = JSON.parse(readFileSync('tests/fixtures/scroll-custom-border-rules.json', 'utf8'));

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
