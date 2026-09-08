import { test, expect } from '@playwright/test';
import { login } from '../lib/auth';
import { loadCatalogue } from '../lib/catalogue';
import { openPrint } from '../lib/drive';
import { loadAxes } from '../lib/axes';
import { enumerate } from '../lib/cases';
import { typeBand } from '../lib/raster';

test.use({ viewport: { width: 2600, height: 3400 }, deviceScaleFactor: 1 });

test('rasters are 1:1 with the frame', async ({ page }) => {
  await login(page);
  const { byOrder } = await loadCatalogue(page, 1);
  const c = enumerate(loadAxes(), 'A').find(x => x.order === 'smith' && x.text === 'max' && x.rank === 5)!;
  await openPrint(page, c, byOrder['smith'], 1);
  const band = await typeBand(page);
  const k = await page.evaluate(() => {
    const f = document.querySelector('svg[data-scroll-frame]')!.getBoundingClientRect();
    return f.width / 2550;
  });
  console.log('scale k =', k, 'band =', JSON.stringify(band));
  expect(k, 'the certificate is not rasterising at 1:1 with its own frame').toBeCloseTo(1, 2);
});
