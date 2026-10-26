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
 * The fit is one synchronous loop, so it is one task: its time is the longest long task the page
 * reports (a PerformanceObserver for `longtask`), which covers everything the fit does (every
 * pass, the layout, the verify) and takes nothing from the engine's own report. A second fit,
 * timed by hand on the same page, is the warm figure. The MutationObserver only counts how many
 * `data-sc-ms` writes the page made for itself, so a certificate fitted twice is not mistaken
 * for two fitted.
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
    w.__bulk = { tasks: [] as number[], writes: 0 };
    new MutationObserver(ms => { for (const m of ms) if (m.attributeName === 'data-sc-ms') w.__bulk.writes++; })
      .observe(document, { attributes: true, subtree: true, attributeFilter: ['data-sc-ms'] });
    new PerformanceObserver(l => { for (const e of l.getEntries()) w.__bulk.tasks.push(e.duration); })
      .observe({ type: 'longtask', buffered: true });
  });
  const t0 = Date.now();
  await page.goto('./__fixture/bulk.html?face=chancery', { waitUntil: 'load' });
  // Not settle(): that fits again by hand, and this test is about the fit the page does for itself.
  await page.waitForFunction(
    () => Array.from(document.querySelectorAll('.Award')).every(a => a.hasAttribute('data-sc-ms')),
    undefined, { timeout: 30000 },
  ).catch(() => undefined);
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
  console.log(`bulk: ${r.awards} awards, unfitted ${r.unfitted}, first fit (longest long task) ${longest.toFixed(0)} ms, `
    + `second fit ${refit.toFixed(0)} ms, sum of layout ms ${r.sumMs.toFixed(0)}, p95 ${r.p95} ms, max ${r.max} ms, `
    + `overflow ${r.overflow}, wall ${Date.now() - t0} ms`);
  expect(r.awards).toBe(COUNT);
  expect(r.unfitted, 'certificates left on the first guess').toBe(0);
  expect(r.bulk.writes).toBeGreaterThanOrEqual(COUNT);
  expect(longest).toBeLessThan(BUDGET_MS);
  expect(refit).toBeLessThan(BUDGET_MS);
  expect(r.p95).toBeLessThanOrEqual(20);
});
