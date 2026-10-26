import { test, expect } from '@playwright/test';
import { mkdirSync, readFileSync, readdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { loadCorpus, renderFixtures, serveFixtures } from '../lib/fixture';

/*
 * Q11, the bulk half (spec 10.2): 200 certificates on one page are all fitted inside autofit's
 * 6-second budget, and none is left on the server's first guess.
 *
 * The page is built from the frozen corpus, rendered by the app's own renderer (renderFixtures),
 * and repeated to 200: each certificate's `.scroll-page` is lifted from its own fixture file into
 * one document, wrapped in its own `data-scroll-face` so every hand is on the page, as a
 * multi-certificate print is. Autofit's deadline is only ever checked between whole
 * certificates, so a certificate with no `data-sc-ms` is one the budget left behind.
 *
 * THE FIGURE IS THE TOTAL FIT TIME, not the longest single task: from the page's DOMContentLoaded to
 * the last `data-sc-ms` the engine writes (every certificate's write lands in a MutationObserver
 * callback, and the page's one fit pass is a single synchronous task, so the last callback's timestamp
 * is when the pass ended). DOMContentLoaded and not `load`, because the fit does not wait for `load`:
 * autofit starts once the document is parsed, the fonts are ready and the images have decoded, and on
 * this page its last write lands BEFORE the `load` event (measured: a load-anchored span was negative).
 * DOMContentLoaded is the earliest the engine may start, so the span is an upper bound that includes
 * the font and image wait. That, and the sum of every long task the page reports during it (the CPU the fit
 * took, which excludes any wait for fonts and images the pass sits behind), are both held to the
 * budget. A second fit, timed by hand on the same page, is the warm figure. The MutationObserver also
 * counts how many `data-sc-ms` writes the page made for itself, so a certificate fitted twice is
 * not mistaken for two fitted.
 *
 * `data-sc-ms` is written by the engine alone (scrollery-typeset.js): nothing in the server render
 * (orkui, system, bin) emits it, so a certificate that carries one has been fitted in the browser
 * and "unfitted" below is a real check, not one a server-rendered attribute could satisfy. A fit that
 * never finishes fails as "never finished", which is not the same failure as one that finished and
 * left a certificate on the first guess.
 */

const COUNT = Number(process.env.ORK3_BULK_COUNT ?? 200);
const BUDGET_MS = 6000;

function buildBulkPage(dir: string, ids: string[], count: number): string {
  const read = (id: string) => readFileSync(join(dir, `${id}.html`), 'utf8');
  const first = read(ids[0]);
  const open = '<div class="scroll-flow">';
  const head = first.slice(0, first.indexOf(open) + open.length);
  const tail = first.slice(first.indexOf('<script src="', first.indexOf('</foreignObject>')));
  const pages: string[] = [];
  const blocks = ids.map(id => {
    const html = read(id);
    const a = html.indexOf('<div class="scroll-page">');
    const end = html.indexOf('</foreignObject>', a);
    const b = html.indexOf('</svg>', end) + '</svg>'.length;
    const face = /data-scroll-face="([^"]*)"/.exec(html)![1];
    return `<div data-scroll-face="${face}" data-sc-heading="auto">${html.slice(a, b)}</div></div>`;
  });
  for (let i = 0; i < count; i++) pages.push(blocks[i % blocks.length]);
  // Each block closes the page div it opened (`</div>` after `</svg>`) and its own wrapper.
  return head + pages.join('\n') + '</div>' + tail;
}

test(`${COUNT} certificates on one page are fitted inside the budget, none left on its first guess`, async ({ page }) => {
  test.setTimeout(180000);
  const corpus = loadCorpus('review-2026-10-25');
  const dir = renderFixtures(corpus, 'bulk-src');
  const ids = corpus.map(c => c.id).filter(id => readdirSync(dir).includes(`${id}.html`));
  const outDir = join(dir, '..', 'bulk200');
  mkdirSync(outDir, { recursive: true });
  writeFileSync(join(outDir, 'bulk.html'), buildBulkPage(dir, ids, COUNT));

  await serveFixtures(page, outDir);
  await page.addInitScript(() => {
    const w = window as any;
    w.__bulk = { tasks: [] as number[], starts: [] as number[], writes: 0, loadAt: -1, dclAt: -1, lastWrite: -1 };
    document.addEventListener('DOMContentLoaded', () => { w.__bulk.dclAt = performance.now(); }, { once: true });
    window.addEventListener('load', () => { w.__bulk.loadAt = performance.now(); }, { once: true });
    new MutationObserver(ms => {
      for (const m of ms) if (m.attributeName === 'data-sc-ms') { w.__bulk.writes++; w.__bulk.lastWrite = performance.now(); }
    }).observe(document, { attributes: true, subtree: true, attributeFilter: ['data-sc-ms'] });
    new PerformanceObserver(l => { for (const e of l.getEntries()) { w.__bulk.tasks.push(e.duration); w.__bulk.starts.push(e.startTime); } })
      .observe({ type: 'longtask', buffered: true });
  });
  const t0 = Date.now();
  await page.goto('./__fixture/bulk.html?face=chancery', { waitUntil: 'load' });
  // Not settle(): that fits again by hand, and this test is about the fit the page does for itself.
  try {
    await page.waitForFunction(
      (n) => {
        const awards = Array.from(document.querySelectorAll('.Award'));
        return awards.length === n && awards.every(a => a.hasAttribute('data-sc-ms'));
      },
      COUNT, { timeout: 30000 },
    );
  } catch (e) {
    const left = await page.evaluate(() => ({
      awards: document.querySelectorAll('.Award').length,
      missing: Array.from(document.querySelectorAll('.Award')).filter(a => !a.hasAttribute('data-sc-ms')).length,
    }));
    throw new Error(`the fit never finished: after 30s ${left.missing} of ${left.awards} certificates (expected ${COUNT}) still carry no data-sc-ms. ${(e as Error).message.split('\n')[0]}`);
  }
  await page.waitForTimeout(500);
  const r = await page.evaluate(() => {
    const awards = Array.from(document.querySelectorAll<HTMLElement>('.Award'));
    const ms = awards.map(a => Number(a.getAttribute('data-sc-ms'))).filter(n => !Number.isNaN(n));
    const sorted = [...ms].sort((a, b) => a - b);
    return {
      awards: awards.length,
      unfitted: awards.filter(a => !a.hasAttribute('data-sc-ms')).length,
      errors: awards.filter(a => a.hasAttribute('data-sc-error')).length,
      overflow: awards.filter(a => a.querySelector('[data-sc-overflow]')).length,
      sumMs: ms.reduce((s, n) => s + n, 0),
      p95: sorted[Math.floor(sorted.length * 0.95)] ?? 0,
      max: sorted[sorted.length - 1] ?? 0,
      bulk: (window as any).__bulk,
    };
  });
  const refit = await page.evaluate(() => {
    const t = performance.now();
    (window as any).ScrolleryAutofit.fit(document);
    return performance.now() - t;
  });
  const longest = Math.max(0, ...(r.bulk.tasks as number[]));
  const taskSum = (r.bulk.tasks as number[]).reduce((a: number, b: number) => a + b, 0);
  const firstFit = r.bulk.lastWrite - r.bulk.dclAt;
  console.log(`bulk: ${r.awards} awards, unfitted ${r.unfitted}, first fit (DOMContentLoaded to last data-sc-ms) ${firstFit.toFixed(0)} ms (load event at +${(r.bulk.loadAt - r.bulk.dclAt).toFixed(0)} ms), `
    + `long tasks sum ${taskSum.toFixed(0)} ms (longest ${longest.toFixed(0)} ms), `
    + `second fit ${refit.toFixed(0)} ms, sum of layout ms ${r.sumMs.toFixed(0)}, p95 ${r.p95} ms, max ${r.max} ms, `
    + `overflow ${r.overflow}, wall ${Date.now() - t0} ms`);
  expect(r.awards).toBe(COUNT);
  expect(r.unfitted, 'certificates left on the first guess').toBe(0);
  expect(r.bulk.writes).toBeGreaterThanOrEqual(COUNT);
  expect(r.bulk.dclAt, 'DOMContentLoaded was never seen').toBeGreaterThan(0);
  expect(r.bulk.lastWrite, 'no data-sc-ms write was timed').toBeGreaterThan(0);
  expect(firstFit, 'first fit, from DOMContentLoaded to the last data-sc-ms').toBeLessThan(BUDGET_MS);
  expect(taskSum, 'CPU of the first fit (sum of the long tasks)').toBeLessThan(BUDGET_MS);
  expect(refit).toBeLessThan(BUDGET_MS);
  expect(r.p95).toBeLessThanOrEqual(20);
});
