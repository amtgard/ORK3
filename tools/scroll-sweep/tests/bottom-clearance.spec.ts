import { test, expect, type Page } from '@playwright/test';
import { renderFixtures, serveFixtures, openFixture, type FixtureSpec } from '../lib/fixture';

/*
 * B7 + B4a-c: the bottom half's text clears the order art.
 *
 * Reads what is painted, not what the CSS says. Each ornament image is drawn onto a canvas
 * at its laid-out size and its pixels are the ink; the text's own line boxes (Range rects)
 * are the other side. Nothing here asks the engine whether it cleared anything.
 *
 *   - battle (arcane and martial, ranks 0/5/9/10, an empty and a long reason): the motto
 *     ("Tales of your valorous battles...") must not meet any plate ink: the glow ring, the
 *     symbol, the over_ plate or the rank numeral. (B4a, B4b)
 *   - crown with a 70-character signer: the "By my hand..." caption must not meet the laurel
 *     (crown/symbol_N.png and the border art). (B4c)
 *   - custom starfield2 + mask: the date and the signer must not meet the ground's baked
 *     dark notch (zodiac/bg_rank.jpg). (B7)
 */

const TAG = 'bottom-clearance';
const LONG = 'Brill was essential for the successful organization and completion of Night Quest this year at Keep, '
  + 'capstoning a tradition of becoming heavily involved in the successful organization and completion of quests '
  + 'at BFP and Midgard for half a decade, Kingdom for a few years now, and Keep twice. While her own brilliant '
  + 'performance has been recommended already, she was able to rely on a strong team and lifted them all.';
const WIDE_NAME = 'Dame Wilhelmina Featherstonehaugh-Montgomery de la Mancha, Warden of the Eastern Marches and Keeper of the Flame';
const SIGNER70 = 'Her Royal Majesty Queen Seraphina Nightingale of the Wetlands, Monarch';

const base = (o: Partial<FixtureSpec> & Pick<FixtureSpec, 'id' | 'order' | 'awardId' | 'title' | 'rank'>): FixtureSpec => ({
  hand: 'chancery', variant: '', scope: 'park', issuerName: 'Ash', issuerTitle: 'Shire', useThe: true, useOf: true,
  player: 'Bo', pronoun: 'their', reason: '', givenBy: 'Bo', date: '27th September 2026', heading: 'auto', ...o,
});

const SPECS: FixtureSpec[] = [];
for (const variant of ['arcane', 'martial']) {
  for (const rank of [0, 5, 9, 10]) {
    /* c094's own player: a name that wraps puts the motto at its lowest, with no reason at all. */
    for (const [tag, reason, player] of [['empty', '', 'Bo'], ['long', LONG, 'Bo'], ['empty-longname', '', WIDE_NAME]] as const) {
      SPECS.push(base({ id: `bt-battle-${variant}-${rank}-${tag}`, order: 'battle', variant, awardId: 243, title: 'Order of Battle', rank, reason, player }));
    }
  }
}
for (const rank of [0, 3, 9]) {
  SPECS.push(base({ id: `bt-crown-${rank}`, order: 'crown', awardId: 239, title: 'Order of the Crown', rank, givenBy: SIGNER70, hand: 'uncial', heading: 'centered' }));
}
/* c046 / c060's own signers: 36 characters, a script hand with nowhere to break. */
for (const [id, rank, hand] of [['c046', 0, 'cinzel'], ['c060', 9, 'sharp']] as const) {
  SPECS.push(base({
    id: `bt-crown-${id}`, order: 'crown', awardId: 239, title: 'Order of the Crown', rank, hand, heading: 'centered',
    player: 'GRIMWALD THE UNYIELDING', givenBy: "Smiles/Hobi/Can'tCallHimSmileyCanWe?", date: '1st May 2026',
    reason: 'For tireless service as Prime Minister of the park, keeping the books, the sign-in sheets and the peace through two hard winters.',
    /* c046 carries a custom border and ribbon, c060 a ribbon: the border narrows the column. */
    design: id === 'c046' ? { customBorder: true, customRibbon: true, ribbonColor: '#4473CF', color1: '#FF0000', color2: '#00FF00' } : { customRibbon: true, ribbonColor: '#FFFFFF' },
  }));
}
SPECS.push(base({
  id: 'bt-custom-starfield2', order: 'custom', awardId: 94, title: 'Custom Award', rank: 1,
  issuerName: 'Främling', issuerTitle: 'Barony', player: '', givenBy: '', reason: LONG,
  recipe: { ground: 'starfield2', border: 'mask', rankSet: 'zodiac' },
  design: { customBorder: true, matchStyle: true, color1: '#1E4FA3', color2: '#F6B323' },
}));
SPECS.push(base({
  id: 'bt-custom-starfield2-long', order: 'custom', awardId: 94, title: 'Custom Award', rank: 1,
  issuerName: 'Främling', issuerTitle: 'Barony', player: '', givenBy: SIGNER70, reason: '',
  recipe: { ground: 'starfield2', border: 'mask', rankSet: 'zodiac' },
  design: { customBorder: true, matchStyle: true, color1: '#1E4FA3', color2: '#F6B323' },
}));

type Read = { texts: Record<string, number>; err?: string; sample?: string; info?: string };

/* Frame px from the .Award's top-left. `inkOf` picks the images and what counts as ink there. */
async function measure(page: Page, kind: 'battle' | 'crown' | 'notch'): Promise<Read> {
  return page.evaluate(async (kind) => {
    const award = document.querySelector('.Award') as HTMLElement;
    const frame = award.parentElement as HTMLElement;
    const f = frame.getBoundingClientRect();
    const k = f.width / frame.offsetWidth;
    const S = 4; // frame px per sample
    type R = { x0: number; y0: number; x1: number; y1: number };
    const rel = (r: DOMRect): R => ({ x0: (r.left - f.left) / k, y0: (r.top - f.top) / k, x1: (r.right - f.left) / k, y1: (r.bottom - f.top) / k });

    const rectsOf = (el: Element): R[] => {
      const out: R[] = [];
      const w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
      for (let n = w.nextNode() as Text | null; n; n = w.nextNode() as Text | null) {
        if (!n.data.trim()) continue;
        const r = document.createRange(); r.selectNodeContents(n);
        for (const q of Array.from(r.getClientRects())) if (q.width > 0 && q.height > 0) out.push(rel(q));
      }
      return out;
    };

    let imgs: HTMLImageElement[] = [];
    let ink: (r: number, g: number, b: number, a: number) => boolean = (_r, _g, _b, a) => a >= 96;
    let texts: R[] = [];
    const all = Array.from(frame.querySelectorAll('img')).filter(i => !award.contains(i) && i.getBoundingClientRect().width > 0) as HTMLImageElement[];
    const src = (i: HTMLImageElement) => i.currentSrc || i.src;
    if (kind === 'battle') {
      imgs = all.filter(i => /\/battle\/(arcane|martial)\/(glow|symbol_|over_)|\/common\/rank_/.test(src(i)));
      texts = rectsOf(award.querySelector('.Flavour')!);
    } else if (kind === 'crown') {
      imgs = all.filter(i => /\/crown\/symbol_/.test(src(i)));
      const cap = Array.from(award.querySelectorAll('.AwardSignatory b span'))[0];
      texts = rectsOf(cap);
    } else {
      imgs = all.filter(i => /\/zodiac\/bg_rank/.test(src(i)));
      ink = (r, g, b, a) => a >= 96 && (0.3 * r + 0.59 * g + 0.11 * b) < 70;
      for (const sel of ['.AwardDate', '.AwardSignatory']) {
        const d = award.querySelector(sel)!;
        texts.push(...rectsOf(d));
        for (const u of Array.from(d.querySelectorAll('img.Underline'))) texts.push(rel(u.getBoundingClientRect()));
      }
    }
    const extra: Record<string, number> = {};
    if (kind === 'crown') {
      /* A signer that wraps grows upward: it must not climb into the motto above it. */
      const sg = rectsOf(award.querySelector('[data-scroll-text="givenBy"]')!);
      const mo = rectsOf(award.querySelector('.Flavour')!);
      extra['signer-over-motto'] = sg.filter(a => mo.some(b => a.x0 < b.x1 && a.x1 > b.x0 && a.y0 < b.y1 - 2 && a.y1 > b.y0 + 2)).length;
    }
    if (!imgs.length) return { texts: {}, err: 'no art images found: ' + all.map(src).join(' ') };
    if (!texts.length) return { texts: {}, err: 'no text rects found' };

    const EPS = 0.5;
    const hits: Record<string, number> = {};
    let sample = '';
    for (const img of imgs) {
      const b = rel(img.getBoundingClientRect());
      const cw = Math.max(1, Math.round((b.x1 - b.x0) / S)), ch = Math.max(1, Math.round((b.y1 - b.y0) / S));
      const cv = document.createElement('canvas'); cv.width = cw; cv.height = ch;
      const cx = cv.getContext('2d', { willReadFrequently: true })!;
      await img.decode().catch(() => undefined);
      cx.drawImage(img, 0, 0, cw, ch);
      const d = cx.getImageData(0, 0, cw, ch).data;
      const name = src(img).replace(/^.*\/scrolls\//, '').replace(/[?#].*$/, '');
      let n = 0;
      for (const t of texts) {
        const c0 = Math.max(0, Math.floor((t.x0 + EPS - b.x0) / S)), c1 = Math.min(cw - 1, Math.floor((t.x1 - EPS - b.x0) / S));
        const r0 = Math.max(0, Math.floor((t.y0 + EPS - b.y0) / S)), r1 = Math.min(ch - 1, Math.floor((t.y1 - EPS - b.y0) / S));
        for (let r = r0; r <= r1; r++) for (let c = c0; c <= c1; c++) {
          const i = (r * cw + c) * 4;
          if (ink(d[i], d[i + 1], d[i + 2], d[i + 3])) { n++; if (!sample) sample = `${name} at ${Math.round(b.x0 + c * S)},${Math.round(b.y0 + r * S)} text ${Math.round(t.x0)},${Math.round(t.y0)}-${Math.round(t.x1)},${Math.round(t.y1)}`; }
        }
      }
      hits[name] = (hits[name] || 0) + n;
    }
    const info = 'text y ' + Math.round(Math.min(...texts.map(t => t.y0))) + '-' + Math.round(Math.max(...texts.map(t => t.y1))) + ', art ' + imgs.map(i => { const b = rel(i.getBoundingClientRect()); return Math.round(b.y0) + '-' + Math.round(b.y1); }).join(' ');
    return { texts: { ...hits, ...extra }, sample, info };
  }, kind);
}

const reads: Record<string, Read> = {};
test.beforeAll(async ({ browser }) => {
  test.setTimeout(240_000);
  const dir = renderFixtures(SPECS, TAG);
  const page = await browser.newPage({ baseURL: process.env.ORK3_E2E_BASE_URL ?? 'http://127.0.0.1:19080/orkui/' });
  await page.setViewportSize({ width: 1280, height: 1700 });
  await serveFixtures(page, dir);
  for (const s of SPECS) {
    await openFixture(page, s);
    reads[s.id] = await measure(page, s.order === 'battle' ? 'battle' : s.order === 'crown' ? 'crown' : 'notch');
  }
  await page.close();
});

const total = (r: Read) => Object.values(r.texts).reduce((a, b) => a + b, 0);

for (const s of SPECS) {
  const what = s.order === 'battle' ? `battle ${s.variant} rank ${s.rank}, ${s.reason ? 'a long' : 'an empty'} reason${s.player.length > 20 ? ' and a wide name' : ''}: the motto clears the plate ink`
    : s.order === 'crown' ? `crown rank ${s.rank}, ${s.givenBy.length} signer characters (${s.id}): the "By my hand..." caption clears the laurel and the signer stays under the motto`
    : `custom starfield2+mask (${s.givenBy ? '70-character signer' : 'long reason'}): the date and signer clear the baked notch`;
  test(what, () => {
    const r = reads[s.id];
    expect(r, 'measured').toBeTruthy();
    expect(r.err, r.err).toBeUndefined();
    expect(total(r), `${s.id}: text meets art ink: ${JSON.stringify(r.texts)} first at ${r.sample} (${r.info})`).toBe(0);
  });
}
