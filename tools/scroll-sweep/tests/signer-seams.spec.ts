import { test, expect, Page } from '@playwright/test';
import * as path from 'path';

// B8: a signature with no space in it breaks at a natural seam (/ _ - . or a camel hump),
// never mid-word, and the text itself is untouched. Synthetic page, no app and no login;
// the autofit runs with no engine on the page (one console error, the signer is still fitted).
const AUTOFIT = path.resolve(__dirname, '../../../orkui/template/revised-frontend/script/scrollery-autofit.js');

async function load(page: Page, signer: string) {
  await page.setContent(`<!doctype html><html><head><meta charset="utf-8"><style>
    html, body { margin: 0; }
    .Award { width: 2550px; font: 100px/1.2 Arial, Helvetica, sans-serif; }
    section.SignAndDate > div { width: 7em; }
    section.SignAndDate > div > p.Body { margin: 0; overflow-wrap: anywhere; }
  </style></head><body><div class="Award"><section class="SignAndDate"><div>
    <p data-scroll-text="givenBy" class="Body" style="font-size:0.7em;"></p></div></section></div></body></html>`);
  await page.evaluate((t) => { document.querySelector('[data-scroll-text="givenBy"]')!.textContent = t; }, signer);
  await page.addScriptTag({ path: AUTOFIT });
  await page.evaluate(() => (window as any).ScrolleryAutofit.fitAward(document.querySelector('.Award')));
}

// The visual lines of the signer, from the DOM: the text of each line box.
const lines = (page: Page): Promise<string[]> =>
  page.evaluate(() => {
    const el = document.querySelector('[data-scroll-text="givenBy"]')!;
    const out: { top: number; s: string }[] = [];
    const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    for (let n = walker.nextNode() as Text | null; n; n = walker.nextNode() as Text | null) {
      for (let i = 0; i < n.length; i++) {
        const r = document.createRange(); r.setStart(n, i); r.setEnd(n, i + 1);
        const rc = r.getClientRects()[0]; if (!rc) continue;
        const top = Math.round(rc.top);
        const last = out[out.length - 1];
        if (last && Math.abs(last.top - top) < 5) last.s += n.data[i]; else out.push({ top, s: n.data[i] });
      }
    }
    return out.map((l) => l.s);
  });

const SIGNER = "Smiles/Hobi/Can'tCallHimSmileyCanWe?";

test('an unspaced signer breaks only at a slash or camel seam, never mid-word', async ({ page }) => {
  await load(page, SIGNER);
  const ls = await lines(page);
  expect(ls.length).toBeGreaterThan(1);
  expect(ls.join('')).toBe(SIGNER);
  for (const l of ls.slice(0, -1)) {
    // each line ends where a seam falls: after / _ - . or just before an upper-case letter
    expect(l).toMatch(/[\/_\-.]$|[a-z]$/);
  }
  let pos = 0;
  for (const l of ls.slice(0, -1)) {
    pos += l.length;
    const before = SIGNER[pos - 1], after = SIGNER[pos];
    expect(/[\/_\-.]/.test(before) || (/[a-z]/.test(before) && /[A-Z]/.test(after))).toBe(true);
  }
});

test('the text content and a re-fit are unchanged by the break points', async ({ page }) => {
  await load(page, SIGNER);
  await page.evaluate(() => (window as any).ScrolleryAutofit.fitAward(document.querySelector('.Award')));
  const r = await page.evaluate(() => {
    const el = document.querySelector('[data-scroll-text="givenBy"]')!;
    return { text: el.textContent, wbrs: el.querySelectorAll('wbr').length };
  });
  expect(r.text).toBe(SIGNER);
  expect(r.wbrs).toBe(7);
});

test('markup in a signature is text, never parsed', async ({ page }) => {
  await load(page, '<img src=x onerror=1>/Foo<b>Bar');
  const r = await page.evaluate(() => {
    const el = document.querySelector('[data-scroll-text="givenBy"]')!;
    return { text: el.textContent, imgs: el.querySelectorAll('img,b').length };
  });
  expect(r.imgs).toBe(0);
  expect(r.text).toBe('<img src=x onerror=1>/Foo<b>Bar');
});

test('a spaced signer is byte-identical', async ({ page }) => {
  await load(page, 'Sir Hobi the Mighty, Knight/Marshal');
  const html = await page.evaluate(() => document.querySelector('[data-scroll-text="givenBy"]')!.innerHTML);
  expect(html).toBe('Sir Hobi the Mighty, Knight/Marshal');
});
