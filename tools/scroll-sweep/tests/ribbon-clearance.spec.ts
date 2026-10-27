import { test, expect, type Page } from '@playwright/test';
import { PNG } from 'pngjs';
import { renderFixtures, serveFixtures, openFixture, type FixtureSpec } from '../lib/fixture';
import { FIXTURE_HERALDRY } from '../lib/drive';

/*
 * A1 + A2: the Custom Ribbon's top ribbon clears the heraldry and the drop-cap "A".
 *
 * The ribbon reused the squiggle's place over "Amtgard", and a ribbon is a solid band about
 * 22% taller than the squiggle it replaces: in Centered its top ran into the shield (c076,
 * c034, c107, c029) and its stem came down onto the versal's "A" (c099, c011). This renders
 * the four hands of c076, c034, c099 and c011, each with its own case's text, with a ribbon,
 * in Centered and in Auto, and reads what is painted: the ribbon's ink grid (data-sc-ink)
 * mapped onto its box, the heraldry as drawn, and the "A"'s own glyph box. Nothing is read
 * from the engine's report, so an engine that said it had cleared the ribbon would not pass by
 * saying so.
 *
 * Two heraldry stand-ins, both opaque, so the box IS the ink:
 *   - `device`: a solid block in the proportions of the case's own device (c076's park arms
 *     are 417x597, c034's 600x750, c099's and c011's kingdom arms 650x398), drawn by
 *     object-fit: contain as the real one is. The ribbon must be DRAWN here, and clear.
 *   - `slot`: FIXTURE_HERALDRY, a square filling the whole 420px slot, the worst a device can
 *     be. Clear is all that is asked here: where the "A" itself runs in under a square that
 *     wide there is no paper left for a ribbon, and hiding it is the honest answer.
 *
 * The "A" is located independently of the engine: its baseline from a zero-size inline-block
 * put on the line beside it (and taken out again), its ink from the browser's own glyph
 * metrics (canvas measureText) in the versal's computed font.
 */

const TAG = 'ribbon-clearance';

/* The proof cases' own fields (out/combo150/matrix.json is not committed). */
type Source = {
  order: string; variant: string; rank: number; awardId: number; title: string; hand: string;
  issuerName: string; issuerTitle: string; player: string; reason: string; givenBy: string; date: string;
  device: [number, number];
};
const SOURCES: Record<string, Source> = {
  c076: {
    order: 'warrior', variant: '', rank: 10, awardId: 27, title: 'Order of the Warrior', hand: 'fondamento',
    issuerName: 'Främling', issuerTitle: 'Barony', player: 'Bo', reason: '', givenBy: 'Bo', date: '27th September 2026',
    device: [417, 597],
  },
  c034: {
    order: 'lion', variant: '', rank: 5, awardId: 23, title: 'Order of the Lion', hand: 'chancery',
    issuerName: 'Sunset Shores of the Western Reach', issuerTitle: 'Duchy', player: 'Bo',
    reason: 'Brill was essential for the successful organization and completion of Night Quest this year at Keep, '
      + 'capstoning a tradition of becoming heavily involved in the successful organization and completion of quests '
      + 'at BFP and Midgard for half a decade, Kingdom for a few years now, and Keep twice. While her own brilliant '
      + 'performance has been recommended already, she was able to rely on a strong team and lifted them all.',
    givenBy: 'King Aldric II', date: '12th December 2025',
    device: [600, 750],
  },
  c099: {
    order: 'rose', variant: '', rank: 2, awardId: 21, title: 'Order of the Rose', hand: 'uncial',
    issuerName: 'Ash', issuerTitle: 'Shire', player: 'Bo', reason: '',
    givenBy: 'Her Royal Majesty Queen Seraphina Nightingale of the Wetlands, Monarch', date: '',
    device: [650, 398],
  },
  c011: {
    order: 'battle', variant: 'martial', rank: 0, awardId: 243, title: 'Order of Battle', hand: 'gotisch',
    issuerName: 'Iron Keep', issuerTitle: 'Barony', player: "Ka'thrin O'Malley-d'Arcy", reason: 'For valor at Midreign.',
    givenBy: 'Bo', date: '12th December 2025',
    device: [650, 398],
  },
};
const SHORTS = Object.keys(SOURCES);
const HEADINGS = ['centered', 'auto'] as const;
type Heraldry = 'device' | 'slot';

const deviceUrl = (short: string) => `/orkui/__fixture/rc-device-${short}.png`;

function spec(short: string, heading: 'centered' | 'auto', heraldry: Heraldry, ribbon: boolean): FixtureSpec {
  const c = SOURCES[short];
  return {
    id: `rc-${short}-${heading}-${heraldry}-${ribbon ? 'rb' : 'plain'}`,
    hand: c.hand, order: c.order, awardId: c.awardId, title: c.title, variant: c.variant, rank: c.rank,
    scope: 'park', issuerName: c.issuerName, issuerTitle: c.issuerTitle, useThe: true, useOf: true,
    player: c.player, pronoun: 'their', reason: c.reason, givenBy: c.givenBy, date: c.date,
    heading,
    heraldry: heraldry === 'slot' ? FIXTURE_HERALDRY : deviceUrl(short),
    ...(ribbon ? { design: { customRibbon: true, ribbonColor: '#1E4FA3' } } : {}),
  };
}

const SPECS: FixtureSpec[] = [];
for (const short of SHORTS) {
  for (const h of HEADINGS) {
    SPECS.push(spec(short, h, 'device', true), spec(short, h, 'slot', true), spec(short, h, 'slot', false));
  }
}

/* An opaque block of the given size, the device stand-in. */
function block(w: number, h: number): Buffer {
  const png = new PNG({ width: w, height: h });
  for (let i = 0; i < png.data.length; i += 4) {
    png.data[i] = 0x33; png.data[i + 1] = 0x33; png.data[i + 2] = 0x33; png.data[i + 3] = 0xff;
  }
  return PNG.sync.write(png);
}

type Box = { x0: number; y0: number; x1: number; y1: number };
type Read = {
  shown: boolean;
  comp: string | null;
  ink: Box[];
  inkBox: Box | null;
  heraldry: Box | null;
  versal: Box | null;
  inline: string;
};

/* Everything in frame px from the .Award's top-left (screen px undone by the frame's scale). */
async function measure(page: Page): Promise<Read> {
  return page.evaluate(() => {
    const award = document.querySelector('.Award') as HTMLElement;
    const a = award.getBoundingClientRect();
    const k = a.width / award.offsetWidth;
    const rel = (r: { left: number; top: number; right: number; bottom: number }) =>
      ({ x0: (r.left - a.left) / k, y0: (r.top - a.top) / k, x1: (r.right - a.left) / k, y1: (r.bottom - a.top) / k });

    const img = award.querySelector('#TopTextFlourish') as HTMLImageElement;
    const shown = getComputedStyle(img).display !== 'none' && img.getBoundingClientRect().width > 0;
    const ink: Box[] = [];
    const m = /^32x32:([0-9a-fA-F]{256})$/.exec(img.getAttribute('data-sc-ink') || '');
    if (shown && m) {
      const b = rel(img.getBoundingClientRect());
      const cw = (b.x1 - b.x0) / 32, ch = (b.y1 - b.y0) / 32;
      for (let r = 0; r < 32; r++) {
        const row = parseInt(m[1].substr(r * 8, 8), 16);
        for (let c = 0; c < 32; c++) {
          if ((row >>> (31 - c)) & 1) ink.push({ x0: b.x0 + c * cw, y0: b.y0 + r * ch, x1: b.x0 + (c + 1) * cw, y1: b.y0 + (r + 1) * ch });
        }
      }
    }
    const inkBox = ink.length
      ? ink.reduce((u, r) => ({ x0: Math.min(u.x0, r.x0), y0: Math.min(u.y0, r.y0), x1: Math.max(u.x1, r.x1), y1: Math.max(u.y1, r.y1) }))
      : null;

    /* The heraldry as drawn: the slot, narrowed by object-fit: contain to the device's own
       proportions. */
    const her = (award.parentElement || document).querySelector('.centre-top img') as HTMLImageElement | null;
    let heraldry: Box | null = null;
    if (her && her.getBoundingClientRect().width > 0) {
      const hr = her.getBoundingClientRect();
      const s = her.naturalWidth > 0 ? Math.min(hr.width / her.naturalWidth, hr.height / her.naturalHeight) : 0;
      const w = s ? her.naturalWidth * s : hr.width, h = s ? her.naturalHeight * s : hr.height;
      const left = hr.left + (hr.width - w) / 2, top = hr.top + (hr.height - h) / 2;
      heraldry = rel({ left, top, right: left + w, bottom: top + h });
    }

    /* The "A": the versal's first visible character. */
    const versalEl = award.querySelector('.ScVersal') as HTMLElement;
    let versal: Box | null = null;
    const node = document.createTreeWalker(versalEl, NodeFilter.SHOW_TEXT).nextNode() as Text | null;
    if (node) {
      const i = node.data.search(/\S/);
      const range = document.createRange();
      range.setStart(node, i); range.setEnd(node, i + 1);
      const glyph = range.getBoundingClientRect();
      /* Baseline: a zero-size inline-block sits on it. In and out before anything paints. */
      const probe = document.createElement('span');
      probe.style.cssText = 'display:inline-block;width:0;height:0;vertical-align:baseline';
      node.parentNode!.insertBefore(probe, node);
      const baseline = probe.getBoundingClientRect().top;
      probe.remove();
      const cs = getComputedStyle(versalEl);
      const ctx = document.createElement('canvas').getContext('2d')!;
      ctx.font = `${cs.fontStyle} ${cs.fontWeight} ${cs.fontSize} ${cs.fontFamily}`;
      const tm = ctx.measureText(node.data[i]);
      /* measureText is in the versal's CSS px; the page is drawn at k screen px per frame px. */
      versal = rel({
        left: glyph.left - tm.actualBoundingBoxLeft * k,
        right: glyph.left + tm.actualBoundingBoxRight * k,
        top: baseline - tm.actualBoundingBoxAscent * k,
        bottom: baseline + tm.actualBoundingBoxDescent * k,
      });
    }
    const section = award.querySelector('.IssuingTextSection');
    return {
      shown, ink, inkBox, heraldry, versal,
      comp: section ? section.getAttribute('data-sc-comp') : null,
      inline: img.getAttribute('style') || '',
    };
  });
}

/* Half a frame px: layout rounds, and a ribbon set exactly against a box is clear. */
const EPS = 0.5;
const meets = (cells: Box[], b: Box) =>
  cells.filter(c => c.x0 < b.x1 - EPS && c.x1 > b.x0 + EPS && c.y0 < b.y1 - EPS && c.y1 > b.y0 + EPS);

const reads: Record<string, Read> = {};

test.beforeAll(async ({ browser }) => {
  test.setTimeout(240_000);
  const dir = renderFixtures(SPECS, TAG);
  const page = await browser.newPage({ baseURL: process.env.ORK3_E2E_BASE_URL ?? 'http://127.0.0.1:19080/orkui/' });
  await page.setViewportSize({ width: 1280, height: 1700 });
  await serveFixtures(page, dir);
  /* After serveFixtures, so these win for their own paths. */
  for (const short of SHORTS) {
    const png = block(...SOURCES[short].device);
    await page.route(`**${deviceUrl(short)}`, route => route.fulfill({ status: 200, contentType: 'image/png', body: png }));
  }
  for (const s of SPECS) {
    await openFixture(page, s);
    reads[s.id] = await measure(page);
  }
  await page.close();
});

function clearOf(r: Read, label: string) {
  expect(r.heraldry, `${label}: the fixture carries heraldry`).not.toBeNull();
  expect(r.versal, `${label}: the versal "A" was found`).not.toBeNull();
  const onShield = meets(r.ink, r.heraldry!);
  expect(onShield.length, `${label}: ink ${JSON.stringify(r.inkBox)} meets heraldry ${JSON.stringify(r.heraldry)} (${r.comp}; ${r.inline})`).toBe(0);
  const onVersal = meets(r.ink, r.versal!);
  expect(onVersal.length, `${label}: ink ${JSON.stringify(r.inkBox)} meets the "A" ${JSON.stringify(r.versal)} (${r.comp}; ${r.inline})`).toBe(0);
}

for (const short of SHORTS) {
  for (const h of HEADINGS) {
    test(`${short} hand (${SOURCES[short].hand}), ${h}, its own device's shape: the top ribbon is drawn, clear of the heraldry and the "A"`, () => {
      const r = reads[`rc-${short}-${h}-device-rb`];
      expect(r, 'measured').toBeTruthy();
      expect(r.shown, `the ribbon is drawn, not hidden (${r.comp})`).toBe(true);
      expect(r.ink.length).toBeGreaterThan(0);
      clearOf(r, `${short} ${h} device`);
    });

    test(`${short} hand (${SOURCES[short].hand}), ${h}, a square filling the slot: the top ribbon never meets it or the "A"`, () => {
      const r = reads[`rc-${short}-${h}-slot-rb`];
      expect(r, 'measured').toBeTruthy();
      clearOf(r, `${short} ${h} slot`);
    });
  }
}

test('the squiggle is not moved: without a ribbon the top flourish carries no offset of its own', () => {
  for (const short of SHORTS) {
    for (const h of HEADINGS) {
      const r = reads[`rc-${short}-${h}-slot-plain`];
      expect(r, `measured ${short} ${h}`).toBeTruthy();
      expect(r.inline, `${short} ${h}`).not.toMatch(/\b(left|bottom)\s*:/);
    }
  }
});
