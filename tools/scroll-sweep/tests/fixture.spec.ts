import { test, expect } from '@playwright/test';
import { loadCorpus, renderFixtures, serveFixtures, openFixture, type FixtureSpec } from '../lib/fixture';
import { BLOCK_SEL } from '../lib/isolate';

test('fixture renderer draws the requested order and hand without a login', async ({ page }) => {
  const specs = loadCorpus('review-2026-10-25').filter(c => c.id === 'c22' || c.id === 'x2');
  const dir = renderFixtures(specs, 'fixture-smoke');
  await serveFixtures(page, dir);
  await openFixture(page, specs.find(s => s.id === 'c22')!);
  await expect(page.locator('.award-template')).toHaveAttribute('data-order', 'warrior');
  expect(await page.evaluate(() => getComputedStyle(document.querySelector('.Award')!).fontFamily)).toContain('BlackChancery');
  await openFixture(page, specs.find(s => s.id === 'x2')!);
  await expect(page.locator('.award-template')).toHaveAttribute('data-order', 'rose');
  expect(await page.evaluate(() => getComputedStyle(document.querySelector('.Award')!).fontFamily)).toContain('Uncial Antiqua');
});

test('every sweep text block still matches on a rendered certificate', async ({ page }) => {
  const specs = loadCorpus('review-2026-10-25').filter(c => c.id === 'c22');
  await serveFixtures(page, renderFixtures(specs, 'fixture-blocks'));
  await openFixture(page, specs[0]);
  for (const sel of BLOCK_SEL.split(',')) {
    expect(await page.locator(sel).count(), sel).toBeGreaterThanOrEqual(1);
  }
});

/*
 * _border_defs.tpl emits the knotwork's <symbol> library once per PROCESS, and the renderer writes a
 * whole batch from one process, so before the renderer reset that flag per spec only the first file
 * of a batch carried it and every later knotwork border (battle, dragon, griffon, custom `knot`)
 * drew nothing. Three knot orders in one batch, none of them first by accident: the griffon one
 * also needs the griffon plate, which the first call used to decide for the whole run.
 */
test('every fixture in one batch carries the knotwork library its border references', async ({ page }) => {
  const base = loadCorpus('review-2026-10-25')[0];
  const mk = (id: string, order: string, awardId: number, variant: string): FixtureSpec =>
    ({ ...base, id, order, awardId, title: `Order of the ${order}`, variant, rank: 5, recipe: undefined, heraldry: '' });
  const specs = [
    mk('knot-battle', 'battle', 243, 'arcane'),
    mk('knot-dragon', 'dragon', 25, ''),
    mk('knot-griffon', 'griffon', 33, ''),
  ];
  await serveFixtures(page, renderFixtures(specs, 'fixture-knot-defs'));
  for (const spec of specs) {
    await openFixture(page, spec);
    const r = await page.evaluate(() => {
      const uses = Array.from(document.querySelectorAll('use[href^="#Border-"]'));
      return {
        uses: uses.length,
        unresolved: uses.map(u => u.getAttribute('href')!.slice(1)).filter(id => !document.getElementById(id)),
        symbols: document.querySelectorAll('symbol[id^="Border-"]').length,
        griffonUse: !!document.querySelector('use[href="#Border-Griffons"]'),
        griffonPlate: !!document.getElementById('Border-Griffons'),
        libraries: document.querySelectorAll('#Border-TopCorner').length,
      };
    });
    expect(r.uses, `${spec.id}: draws no knotwork <use>`).toBeGreaterThan(0);
    expect(r.symbols, `${spec.id}: no <symbol> library`).toBeGreaterThan(0);
    expect(r.unresolved, `${spec.id}: <use> targets that resolve to nothing`).toEqual([]);
    expect(r.libraries, `${spec.id}: library emitted once, not per certificate`).toBe(1);
    if (spec.order === 'griffon') {
      expect(r.griffonUse && r.griffonPlate, `${spec.id}: griffon plate missing`).toBe(true);
    }
  }
});

test('the fixture renderer is not reachable over the web', async ({ request }) => {
  const r = await request.get('http://127.0.0.1:19080/bin/scroll-render-fixtures.php');
  expect(r.status()).toBe(404);
});
