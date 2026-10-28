import { test, expect } from '@playwright/test';
import * as path from 'path';

// C6: the heraldry <img> in .centre-top is judged by the ribbon placement (heraldryInk).
// On a first preview of arms the browser has not cached it has no box yet, so the
// composer must refit once the arms decode, as it already does for ink-bearing flourishes.
// Synthetic page, no app and no login.
const AUTOFIT = path.resolve(__dirname, '../../../orkui/template/revised-frontend/script/scrollery-autofit.js');
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', 'base64');

test('a heraldry image still in flight triggers one more fit when it loads', async ({ page }) => {
  let release!: () => void;
  const gate = new Promise<void>((r) => { release = r; });
  await page.route('http://scroll.test/arms.png', async (route) => {
    await gate;
    await route.fulfill({ status: 200, contentType: 'image/png', body: PNG });
  });

  await page.setContent(`<!doctype html><html><head><meta charset="utf-8"></head><body>
    <div id="mount"><div class="award-template"><div class="centre-top"><img alt="" src="http://scroll.test/arms.png"></div>
    <div class="Award" style="width:2550px;font:100px/1.2 Arial;"><p class="Body">x</p></div></div></div></body></html>`,
    { waitUntil: 'domcontentloaded' });
  await page.addScriptTag({ path: AUTOFIT });

  const before = await page.evaluate(async () => {
    const w = window as any;
    w.__frames = 0;
    const raf = w.requestAnimationFrame.bind(w);
    w.requestAnimationFrame = (cb: FrameRequestCallback) => { w.__frames++; return raf(cb); };
    w.ScrolleryAutofit.fitSoon(document.getElementById('mount'));
    await new Promise((r) => setTimeout(r, 100));
    return w.__frames;
  });
  expect(before).toBe(1);

  release();
  await page.waitForFunction(() => (document.querySelector('.centre-top img') as HTMLImageElement).complete);
  await page.waitForTimeout(100);
  expect(await page.evaluate(() => (window as any).__frames)).toBe(2);
});
