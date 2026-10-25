import type { Page } from '@playwright/test';
import { loadAxes } from './axes';
import { fixtureDriver, fixtureCatalogue } from './drive';

export type Choice = { awardId: number; title: string; minRank: number; maxRank: number };

/**
 * The kingdom's own printable choices, read off the composer rather than invented.
 *
 * Scrollery/compose matches on the PAIR (awardId, catalogue name), case-insensitively
 * — Model_Scrollery::matchingChoiceForTest(). So the harness cannot make up an award
 * id: it has to read what this kingdom actually carries. The composer's #sc-award
 * stamps data-order, data-name, data-min and data-max on every option (see the
 * $awardOption closure in Scrollery_create.tpl), which is everything needed.
 *
 * data-min matters and is easy to miss: warrior carries data-min="1", so rank 0 is
 * not a warrior grant. Enumerating it anyway sweeps a state the app cannot produce.
 *
 * UNREACHABLE ORDERS ARE A RESULT, NOT AN ERROR. `hunter` points at award_id 6969,
 * which scroll-orders.php says in as many words is "Arcanyst's invention, not an ORK
 * award id" — no ork_kingdomaward row resolves to it, so hunter.tpl cannot be reached
 * as an ORDER by any route. Its border.png is still live as a custom-recipe border
 * and stage C sweeps that. Reporting it is the honest answer; ScrollSweepCoverageTest
 * is what stops the list quietly growing.
 */
export async function loadCatalogue(
  page: Page,
  kingdomId: number,
): Promise<{ byOrder: Record<string, Choice>; unreachable: string[] }> {
  /* The fixture driver has no composer to read. Its answer is the same table with every
     order in it, hunter included, because a fixture reaches hunter by name. */
  if (fixtureDriver()) return fixtureCatalogue();
  await page.goto(`./index.php?Route=Scrollery/create/kingdom/${kingdomId}`);
  await page.waitForSelector('#sc-award option', { state: 'attached' });

  const options = await page.$$eval('#sc-award option', els =>
    els.map(e => {
      const o = e as HTMLOptionElement;
      return {
        value: o.value,
        order: o.dataset.order ?? '',
        name: o.dataset.name ?? (o.textContent ?? '').trim(),
        min: o.dataset.min ?? '0',
        max: o.dataset.max ?? '10',
      };
    }),
  );

  const byOrder: Record<string, Choice> = {};
  for (const o of options) {
    const awardId = Number(o.value);
    if (!Number.isFinite(awardId) || awardId <= 0 || !o.order) continue;
    /* First option per order wins. For `custom` that is deliberate: kingdom 1 carries
       seven award-94 rows with names of their own, and any one of them composes the
       same custom scroll — the recipe, not the row, is what stage C varies. */
    if (!(o.order in byOrder)) {
      byOrder[o.order] = {
        awardId, title: o.name, minRank: Number(o.min) || 0, maxRank: Number(o.max) || 10,
      };
    }
  }

  const all = new Set(loadAxes().orderVariants.map(o => o.order));
  const unreachable = [...all].filter(o => !(o in byOrder));
  return { byOrder, unreachable };
}
