import { test, expect } from '@playwright/test';
import { PNG } from 'pngjs';
import { renderFixtures, serveFixtures, openFixture, type FixtureSpec } from '../lib/fixture';

/*
 * B4d: the reason's six ruled lines keep clear of the descenders when the reason shrinks.
 *
 * Reads what is painted. The rules are hidden and the reason box is screenshotted, so the
 * lowest dark pixel of each text line is its real descender bottom (g, p, q, y); that is
 * compared with the top of the rule the line sits on (the rule is a lens: its thick centre
 * starts at the image's top edge).
 *   - at the font floor (a long reason) the rule's top must be at least 0.2em of the
 *     reason's fitted size below that descender bottom, on every line;
 *   - at full size (a short reason, nothing shrunk) every rule keeps the position it had
 *     before the rules went em-based, within 1px (FULL_SIZE_RULE_TOPS, measured on BASE
 *     251f1bc3f).
 */

/* c150's and c024's own long reason (the proofs): it shrinks the reason to the floor. */
const LONG = 'Brill was essential for the successful organization and completion of Night Quest this year at Keep, '
  + 'capstoning a tradition of becoming heavily involved in the successful organization and completion of quests '
  + 'at BFP and Midgard for half a decade, Kingdom for a few years now, and Keep twice. While her own brilliant '
  + 'performance has been recommended already, she was able to rely on a strong team and lifted them all.';
const SHORT = 'For valor at Midreign.';

const base = (o: Partial<FixtureSpec> & Pick<FixtureSpec, 'id' | 'order' | 'awardId' | 'title' | 'reason'>): FixtureSpec => ({
  hand: 'chancery', variant: '', rank: 5, scope: 'park', issuerName: 'Iron Keep', issuerTitle: 'Barony', useThe: true, useOf: true,
  player: 'Sir Aldric Thornwood the Unbowed of the Iron Vale', pronoun: 'their', givenBy: 'Bo', date: '27th September 2026', heading: 'auto', ...o,
});
/* c150: garber, chancery, classic heading, a border-match custom border. c024: rose, uncial, plain. */
const c150 = { order: 'garber', awardId: 26, title: 'Order of the Garber', heading: 'classic', design: { customBorder: true, matchStyle: true, color1: '#1E4FA3', color2: '#F6B323' } } as const;
const c024 = { order: 'rose', awardId: 21, title: 'Order of the Rose', hand: 'uncial', rank: 10, player: 'Bo', issuerName: 'The Marsh', issuerTitle: 'Duchy' } as const;

const SPECS: FixtureSpec[] = [
  base({ id: 'rr-c150-long', reason: LONG, ...c150 }),
  base({ id: 'rr-c150-short', reason: SHORT, ...c150 }),
  base({ id: 'rr-c024-long', reason: LONG, ...c024 }),
  base({ id: 'rr-c024-short', reason: SHORT, ...c024 }),
];

/* Each rule's top in layout px BELOW THE TOP OF THE REASON BOX (so layout changes above the reason
   do not matter), on BASE 251f1bc3f. The rule top is deliberately not em-based (see .Underline). */
const FULL_SIZE_RULE_TOPS: Record<string, number[]> = {
  'rr-c150-short': [142.67, 298.27, 453.86, 609.45, 765.05, 920.64],
  'rr-c024-short': [144.67, 300.27, 455.86, 611.45, 767.05, 922.64],
  'rr-c150-long': [102.95, 212.3, 321.64, 430.98, 540.33, 649.67],
  'rr-c024-long': [102.78, 211.31, 319.84, 428.38, 536.91, 645.44],
};

type Read = { fontPx: number; gaps: number[]; ruleTops: number[]; ruleH: number; info: string };
const reads: Record<string, Read> = {};

test.beforeAll(async ({ browser }) => {
  test.setTimeout(240_000);
  const dir = renderFixtures(SPECS, 'reason-rules');
  const ctx = await browser.newContext({ deviceScaleFactor: 2 });
  const page = await ctx.newPage();
  await page.setViewportSize({ width: 1280, height: 1700 });
  await serveFixtures(page, dir);
  const baseURL = process.env.ORK3_E2E_BASE_URL ?? 'http://127.0.0.1:19080/orkui/';
  for (const s of SPECS) {
    await page.goto(new URL(`./__fixture/${s.id}.html?face=${s.hand}`, baseURL).toString(), { waitUntil: 'load' });
    await (await import('../lib/drive')).settle(page);
    const g = await page.evaluate(() => {
      const award = document.querySelector('.Award') as HTMLElement;
      const frame = award.parentElement as HTMLElement;
      const k = frame.getBoundingClientRect().width / frame.offsetWidth;
      const box = award.querySelector('.Award_Reason') as HTMLElement;
      const p = box.querySelector('p[data-scroll-text="reason"]') as HTMLElement;
      const rules = Array.from(box.querySelectorAll('img.Underline')).map(u => u.getBoundingClientRect()).filter(r => r.width > 0);
      const range = document.createRange(); range.selectNodeContents(p);
      const lines = new Map<number, { top: number; bottom: number; x0: number; x1: number }>();
      for (const q of Array.from(range.getClientRects())) if (q.width >= 1 && q.height >= 1) { const l = lines.get(Math.round(q.top)); lines.set(Math.round(q.top), { top: q.top, bottom: q.bottom, x0: Math.min(l?.x0 ?? 1e9, q.left), x1: Math.max(l?.x1 ?? -1e9, q.right) }); };
      const b = box.getBoundingClientRect();
      return {
        k, fontPx: parseFloat(getComputedStyle(p).fontSize),
        clip: { x: b.left, y: b.top, width: b.width, height: b.height }, boxTop: b.top,
        rules: rules.map(r => ({ top: r.top, bottom: r.bottom })),
        lines: Array.from(lines.values()).sort((a, c) => a.top - c.top),
      };
    });
    await page.addStyleTag({ content: '.Award_Reason img.Underline{visibility:hidden !important}' });
    const png = PNG.sync.read(await page.screenshot({ clip: g.clip }));
    const dpr = png.width / g.clip.width;
    const lowestDark = (y0: number, y1: number, x0: number, x1: number): number => {
      const r0 = Math.max(0, Math.floor((y0 - g.clip.y) * dpr)), r1 = Math.min(png.height - 1, Math.ceil((y1 - g.clip.y) * dpr));
      for (let r = r1; r >= r0; r--) {
        let n = 0;
        for (let c = Math.max(0, Math.floor((x0 - g.clip.x) * dpr)); c <= Math.min(png.width - 1, Math.ceil((x1 - g.clip.x) * dpr)); c++) {
          const i = (r * png.width + c) * 4;
          if (0.3 * png.data[i] + 0.59 * png.data[i + 1] + 0.11 * png.data[i + 2] < 60) n++;
        }
        if (n >= 4) return g.clip.y + r / dpr;
      }
      return NaN;
    };
    const gaps: number[] = [];
    g.lines.forEach((ln, i) => {
      /* The rule this line sits on: the first whose top is below the line box's midpoint. */
      const rule = g.rules.find(u => u.top > ln.top + (ln.bottom - ln.top) * 0.5);
      const next = g.lines[i + 1];
      const desc = lowestDark(ln.top, next ? next.top : ln.bottom + 40, ln.x0, ln.x1);
      if (rule && !Number.isNaN(desc)) gaps.push((rule.top - desc) / g.k);
    });
    reads[s.id] = {
      fontPx: g.fontPx, gaps, ruleTops: g.rules.map(r => (r.top - g.boxTop) / g.k), ruleH: (g.rules[0].bottom - g.rules[0].top) / g.k,
      info: `font ${g.fontPx.toFixed(2)}px, ${g.lines.length} lines, gaps ${gaps.map(x => x.toFixed(1)).join(' ')}`,
    };
  }
  await ctx.close();
});

for (const id of ['rr-c150-long', 'rr-c024-long']) {
  test(`${id}: at the font floor every rule clears the line's descender bottom by 0.2em`, () => {
    const r = reads[id];
    expect(r.gaps.length, 'lines measured').toBeGreaterThan(2);
    const min = 0.2 * r.fontPx; /* base 0.22-0.28em; the rejected fully-em variant measured 0.185em and fails */
    expect(r.gaps.filter(x => x < min), `${r.info}; need >= ${min.toFixed(2)}px`).toEqual([]);
  });
}

for (const id of ['rr-c150-short', 'rr-c024-short', 'rr-c150-long', 'rr-c024-long']) {
  test(`${id}: the rules keep their positions within 1px`, () => {
    const r = reads[id];
    const want = FULL_SIZE_RULE_TOPS[id];
    expect(r.ruleTops.length).toBe(want.length);
    r.ruleTops.forEach((t, i) => expect(Math.abs(t - want[i]), `rule ${i}: ${t.toFixed(2)} vs ${want[i]}`).toBeLessThanOrEqual(1));
  });
}

/* The rule's thickness is em-based, so it is the same fraction of the type at the floor as at full size. */
for (const [long, short] of [['rr-c150-long', 'rr-c150-short'], ['rr-c024-long', 'rr-c024-short']]) {
  test(`${long}: the rule is as thick, in em, as at full size`, () => {
    const l = reads[long], f = reads[short];
    expect(l.fontPx, 'the long reason shrank').toBeLessThan(f.fontPx * 0.8);
    const lem = l.ruleH / l.fontPx, fem = f.ruleH / f.fontPx;
    expect(Math.abs(lem - fem) / fem, `${lem.toFixed(4)}em at ${l.fontPx.toFixed(1)}px vs ${fem.toFixed(4)}em at ${f.fontPx.toFixed(1)}px`).toBeLessThan(0.03);
  });
}
