import { test, expect, type Page } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { PNG } from 'pngjs';
import { loadAxes } from './lib/axes';
import { caseId, type SweepCase } from './lib/cases';
import { FIXTURE_ORDERS, fixtureSpecFor } from './lib/drive';
import { openFixture, renderFixtures, serveFixtures, type FixtureSpec } from './lib/fixture';
import { applyLayer, forceFullScale, markGround } from './lib/isolate';
import { plan, setUnderlayCss, tagPlates, POLICY_FILE } from './lib/underlays';
import { GRID, THRESHOLDS, bandEdges, obstacleMask, type Edges, type ObstacleMask, type Thresholds } from './lib/profile';

/*
 * FREE-SPACE PROFILES: how much width is clear of the artwork at each height of the text band,
 * for every certificate design, written to assets/scrolls/free-space.json (spec 7.1).
 *
 *     npm run profile:scrolls
 *
 * The typeset engine runs in the browser and cannot see the plate it is typing on. It reads this
 * file instead, so that a name is never set across an archer, a needle, a zodiac glyph or a lion.
 * The arithmetic (two rasters in, two numbers per band out) is lib/profile.ts, pinned by
 * tests/profile-edges.spec.ts; this file is what puts a certificate on a page and takes the two rasters.
 *
 * WHAT IS MEASURED. Each certificate is rendered through the fixture renderer with the `empty`
 * text profile and the stand-in heraldry (a solid 420px square: the worst case for type over a
 * device, and a device is the one thing the engine always has to keep clear of), then rasterised
 * at 1:1 twice:
 *
 *   G   the ground alone: the certificate's own opaque, full-frame, normally blended plate,
 *       found by markGround() exactly as the sweep finds it.
 *   A   the ground plus every plate, EXCEPT the plates the underlay policy says the type is
 *       designed to sit on (system/lib/ork3/data/scroll-layout-underlays.php), which are hidden.
 *       A plate the policy gives a WINDOW is hidden only inside the window's rows.
 *
 * The obstacles are what A adds to G, plus what G itself makes unreadable. Isolation never
 * reflows: every rule is `visibility: hidden`, never `display: none` (lib/isolate.ts says why).
 * The policy is resolved by lib/underlays.ts (PHP's own fnmatch(), as the review sheet does), the
 * same module the sweep's layer A uses, so the two cannot disagree with each other or with what the
 * policy file means. It is a DRAFT pending the owner's sign-off, so the manifest
 * records its hash and a test fails when the two part: regeneration is this one command.
 *
 * CUSTOM scrolls are composed from parts, so each part is measured alone and Scroll::FreeSpace()
 * intersects them: every ground with no border, every border on plain parchment, and every
 * numeral set at every rank on parchment with no border. Rank 0 draws no numeral (custom.tpl),
 * so `rankset/<set>/0` is rendered faithfully and comes out as the whole frame; it is written
 * and not omitted so the coverage test stays one rule.
 *
 * THE KNOWN LIMITS, so nobody reads a clean profile as more than it is:
 *   - Warrior rank 10: bg_10.jpg carries the frame and the ash band and is an underlay, so no
 *     obstacle ink is seen there. The .Award padding (433px a side) is what keeps type off it.
 *   - The knotwork border (_border.tpl) is inline SVG with no src, so it is always an obstacle;
 *     it is drawn outside the .Award column and rarely constrains anything.
 *   - Only ink OUTSIDE cx +/- 250 sets an edge. Ink inside it is reported, not profiled.
 *
 * Environment (for calibration and debugging; a normal run sets none):
 *   ORK3_PROFILE_ONLY   comma-separated keys, or prefixes ending in "/" ("hunter//1,zodiac//"): a
 *                       partial run, which writes out/profiles/partial.json and never touches the manifest
 *   ORK3_PROFILE_PAGES  pages measured at once (default 2)
 *   ORK3_PROFILE_OWN    override ownBorderDistance (the calibrated value is in lib/profile.ts)
 *   ORK3_PROFILE_DUMP   also write the two full-size rasters of each configuration to out/profiles/raw/
 *
 * Output: tools/scroll-sweep/out/profiles/ (git-ignored): one debug PNG per configuration with the
 * profile drawn over the composite, and report.md.
 */

const OUT = join(__dirname, 'out', 'profiles');
const FIXTURE_TAG = 'profiles';
const MANIFEST = join(__dirname, '..', '..', 'assets', 'scrolls', 'free-space.json');
const RECIPES_FILE = join(__dirname, '..', '..', 'system', 'lib', 'ork3', 'data', 'scroll-recipes.php');
const PAGES = Math.max(1, Number(process.env.ORK3_PROFILE_PAGES ?? 2));
const ONLY = (process.env.ORK3_PROFILE_ONLY ?? '').split(',').map(s => s.trim()).filter(Boolean);
const T: Thresholds = {
  ...THRESHOLDS,
  ...(process.env.ORK3_PROFILE_OWN ? { ownBorderDistance: Number(process.env.ORK3_PROFILE_OWN) } : {}),
};

/** The centre column of the frame, which the engine centres its roles on. */
const CX = GRID.frameW / 2;
/** Bands 0-60 are the ones the spec's report is about: y 400 to 1925, the heading and the award. */
const REPORT_BANDS = 61;
/** Debug images are one third size: the full 2550x2000 band at 274 configurations would be gigabytes. */
const DEBUG_SCALE = 3;

type Group = 'configs' | 'components';
type Config = { group: Group; key: string; kase: SweepCase };

/* ---- what to measure ----------------------------------------------------------------- */

const caseFor = (order: string, variant: string, rank: number, recipe?: SweepCase['recipe']): SweepCase => {
  const c: SweepCase = { id: '', stage: 'N', order, variant, rank, hand: 'chancery', text: 'empty', surface: 'print', ...(recipe ? { recipe } : {}) };
  c.id = caseId(c);
  return c;
};

/**
 * Every fixed order x variant x rank (hunter included, warrior from rank 1: FIXTURE_ORDERS carries
 * each order's range and topscore.spec.ts holds it to scroll-orders.php), and every custom part.
 * The vocabulary comes from axes.json, as the sweep's does, never from a list restated here.
 */
function buildConfigs(): Config[] {
  const axes = loadAxes();
  const out: Config[] = [];
  for (const { order, variant } of axes.orderVariants) {
    if (order === 'custom') continue;
    const f = FIXTURE_ORDERS[order];
    if (!f) throw new Error(`no fixture identity for order "${order}": add it to FIXTURE_ORDERS (lib/drive.ts)`);
    for (let rank = f.minRank; rank <= f.maxRank; rank++) {
      out.push({ group: 'configs', key: `${order}/${variant}/${rank}`, kase: caseFor(order, variant, rank) });
    }
  }
  const custom = (key: string, rank: number, recipe: { ground: string; border: string; rankSet: string }) =>
    out.push({ group: 'components', key, kase: caseFor('custom', '', rank, recipe) });
  for (const ground of axes.grounds) custom(`ground/${ground}`, 0, { ground, border: 'none', rankSet: 'common' });
  for (const border of axes.borders) {
    if (border !== 'none') custom(`border/${border}`, 0, { ground: 'parchment', border, rankSet: 'common' });
  }
  for (const rankSet of axes.rankSets) {
    for (const rank of axes.ranks) custom(`rankset/${rankSet}/${rank}`, rank, { ground: 'parchment', border: 'none', rankSet });
  }
  return out;
}

/* ---- the policy and the recipes, read from PHP --------------------------------------- */

type Recipes = { grounds: Record<string, string>; groundsWithOwnBorder: string[] };

/** The recipe vocabulary's grounds, and which of them carry their own frame, read from the PHP data file. */
function readRecipes(): Recipes {
  const code = `$r = require $argv[1];
echo json_encode(['grounds' => $r['grounds'], 'groundsWithOwnBorder' => $r['groundsWithOwnBorder']]);`;
  const r = spawnSync('php', ['-r', code, RECIPES_FILE], { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 });
  if (r.error) throw new Error(`could not run php: ${r.error.message}`);
  if (r.status !== 0) throw new Error(`reading the recipes failed\n${r.stderr}${r.stdout}`);
  return JSON.parse(r.stdout);
}

/* ---- in the page --------------------------------------------------------------------- */

type PageFacts = {
  /** Whether the type is light on this certificate (cyanotype). Read from the type's own computed colour. */
  lightInk: boolean;
  frame: { x: number; y: number };
  /** The stand-in heraldry's painted box, frame px relative to the frame's own corner. */
  heraldry: { x: number; y: number; w: number; h: number } | null;
};

/** Runs in the page: the facts that are not about plates (those are lib/underlays.ts tagPlates). */
function inspect(): PageFacts {
  const frame = document.querySelector('svg[data-scroll-frame]') as SVGElement | null;
  const tpl = document.querySelector('.award-template') as HTMLElement | null;
  const award = document.querySelector('.Award') as HTMLElement | null;
  if (!frame || !tpl || !award) throw new Error('no certificate on the page');
  const fr = frame.getBoundingClientRect();
  const k = fr.width / 2550;

  const rgb = getComputedStyle(award).color.match(/[\d.]+/g)!.map(Number);
  const luma = (0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2]) / 255;

  const hi = tpl.querySelector('img[src*="heraldry-standin"]') as HTMLElement | null;
  let heraldry: PageFacts['heraldry'] = null;
  if (hi) {
    const r = hi.getBoundingClientRect();
    heraldry = { x: (r.left - fr.left) / k, y: (r.top - fr.top) / k, w: r.width / k, h: r.height / k };
  }
  return { lightInk: luma > 0.5, frame: { x: fr.left, y: fr.top }, heraldry };
}

/**
 * The frame at the page's own origin. Left in the flow it sits wherever the page's chrome puts it
 * (here 277.58px down), and a clip at a fractional offset resamples every plate. Taking it out of
 * the flow moves nothing inside it: the foreignObject lays out in its own 2550x3300 coordinates
 * whatever the SVG is positioned at.
 */
const pinFrame = (page: Page) =>
  page.evaluate(() => {
    let st = document.getElementById('profile-origin') as HTMLStyleElement | null;
    if (!st) { st = document.createElement('style'); st.id = 'profile-origin'; document.head.appendChild(st); }
    st.textContent = 'svg[data-scroll-frame] { position: absolute !important; left: 0 !important; top: 0 !important; margin: 0 !important; }';
  });

/* ---- one configuration --------------------------------------------------------------- */

type Measured = {
  edges: Edges;
  by: ObstacleMask['by'];
  lightInk: boolean;
  ownBorder: boolean;
  ground: string[];
  /** Plate files hidden as underlays, and the ones left as obstacles. */
  underlays: string[];
  obstacles: string[];
  heraldry: PageFacts['heraldry'];
  /** Obstacle pixels in the centre exclusion that are NOT the heraldry stand-in, per band. */
  centreOther: { count: number; x0: number; x1: number }[];
  debugFile: string;
};

/**
 * The ground plates that carry their own frame, from the recipe vocabulary, as paths without the
 * extension: the page serves the print rendition (smith/bg.webp for smith/bg.jpg), which is a
 * different extension of the same plate. See Scroll::ArtUrl().
 */
const stem = (file: string) => file.replace(/\.[a-z0-9]+$/i, '');
function ownBorderFiles(r: Recipes): Set<string> {
  return new Set(r.groundsWithOwnBorder.map(g => stem(r.grounds[g])));
}

async function measure(page: Page, spec: FixtureSpec, own: Set<string>, debugName: string): Promise<Measured> {
  await openFixture(page, spec);
  await page.evaluate(() => window.scrollTo(0, 0));
  await forceFullScale(page);
  await pinFrame(page);
  await markGround(page);
  const facts = await page.evaluate(inspect);
  const plates = await tagPlates(page);
  const applied = plan(plates, spec.order);

  // The same clip for every raster, taken from the unmodified page: pixel-comparable by construction.
  const clip = { x: Math.round(facts.frame.x), y: Math.round(facts.frame.y) + GRID.y0, width: GRID.frameW, height: GRID.bands * GRID.step };
  if (Math.abs(clip.x - facts.frame.x) > 0.01 || Math.abs(facts.frame.y - Math.round(facts.frame.y)) > 0.01) {
    throw new Error(`the frame sits at a fractional offset (${facts.frame.x}, ${facts.frame.y}), so 1:1 rasters would resample`);
  }
  const shoot = () => page.screenshot({ clip, type: 'png', animations: 'disabled' });

  await setUnderlayCss(page, '');
  await applyLayer(page, 'G');
  const G = await shoot();
  await applyLayer(page, 'A');
  const composite = await shoot();             // ground + every plate: what the debug image is drawn over
  await setUnderlayCss(page, applied.css);
  const A = await shoot();                     // ground + the plates that are obstacles
  await setUnderlayCss(page, '');
  await applyLayer(page, 'full');

  if (process.env.ORK3_PROFILE_DUMP) {
    mkdirSync(join(OUT, 'raw'), { recursive: true });
    const base = debugName.replace(/\.png$/, '');
    writeFileSync(join(OUT, 'raw', `${base}-G.png`), G);
    writeFileSync(join(OUT, 'raw', `${base}-A.png`), A);
  }

  const ownBorder = plates.ground.some(f => own.has(stem(f)));
  const obs = obstacleMask(A, G, { lightInk: facts.lightInk, ownBorder, t: T });
  const edges = bandEdges(obs.mask, obs.w, obs.h, T);

  // The report's question is about ARTWORK under the heading, so the stand-in device (which every
  // certificate has, in the same place) is taken out of it and reported once, not per certificate.
  const hb = facts.heraldry;
  const pad = 6;
  const centreOther = Array.from({ length: GRID.bands }, () => ({ count: 0, x0: -1, x1: -1 }));
  for (let b = 0; b < GRID.bands; b++) {
    const c = centreOther[b];
    for (let y = b * GRID.step; y < (b + 1) * GRID.step; y++) {
      const gy = y + GRID.y0;
      const inY = hb && gy >= hb.y - pad && gy < hb.y + hb.h + pad;
      for (let x = CX - T.centerExclusion; x < CX + T.centerExclusion; x++) {
        if (!obs.mask[y * obs.w + x]) continue;
        if (inY && hb && x >= hb.x - pad && x < hb.x + hb.w + pad) continue;
        c.count++;
        if (c.x0 < 0 || x < c.x0) c.x0 = x;
        if (x > c.x1) c.x1 = x;
      }
    }
  }

  writeFileSync(join(OUT, debugName), debugComposite(composite, obs, edges));
  return {
    edges,
    by: obs.by,
    lightInk: facts.lightInk,
    ownBorder,
    ground: plates.ground,
    underlays: Array.from(new Set([...applied.whole, ...applied.window.map(f => `${f} (window)`)])),
    obstacles: Array.from(new Set([...applied.obstacles, ...applied.window.map(f => `${f} (outside its window)`)])),
    heraldry: hb,
    centreOther,
    debugFile: debugName,
  };
}

/* ---- the debug image ----------------------------------------------------------------- */

/**
 * The composite (ground + every plate, no text) at 1/3 size, with the profile drawn over it:
 * obstacle pixels tinted by what flagged them is not recoverable from the mask alone, so they are
 * red; the free interval is a green line down each band's L and R; the centre exclusion is blue
 * at the top and bottom of the band. The point is for a person to look at one picture and say
 * whether the line is where the artwork is.
 */
function debugComposite(composite: Buffer, obs: ObstacleMask, edges: Edges): Buffer {
  const src = PNG.sync.read(composite);
  const s = DEBUG_SCALE;
  const w = Math.floor(src.width / s);
  const h = Math.floor(src.height / s);
  const out = new PNG({ width: w, height: h });
  for (let y = 0; y < h; y++) {
    for (let x = 0; x < w; x++) {
      let r = 0, g = 0, b = 0, ob = 0;
      for (let dy = 0; dy < s; dy++) {
        for (let dx = 0; dx < s; dx++) {
          const p = (y * s + dy) * src.width + (x * s + dx);
          const i = p << 2;
          r += src.data[i]; g += src.data[i + 1]; b += src.data[i + 2];
          ob += obs.mask[p];
        }
      }
      const n = s * s;
      const o = (y * w + x) << 2;
      const t = Math.min(1, (ob / n) * 1.5) * 0.55;       // obstacle tint, strongest where the mask is solid
      out.data[o] = (r / n) * (1 - t) + 255 * t;
      out.data[o + 1] = (g / n) * (1 - t);
      out.data[o + 2] = (b / n) * (1 - t);
      out.data[o + 3] = 255;
    }
  }
  const put = (x: number, y: number, c: [number, number, number]) => {
    if (x < 0 || x >= w || y < 0 || y >= h) return;
    const o = (y * w + x) << 2;
    out.data[o] = c[0]; out.data[o + 1] = c[1]; out.data[o + 2] = c[2]; out.data[o + 3] = 255;
  };
  const GREEN: [number, number, number] = [0, 200, 60];
  const BLUE: [number, number, number] = [30, 90, 255];
  for (let b = 0; b < GRID.bands; b++) {
    const y0 = Math.floor((b * GRID.step) / s);
    const y1 = Math.floor(((b + 1) * GRID.step) / s) - 1;
    for (let y = y0; y <= y1; y++) {
      for (const edge of [edges.L[b], edges.R[b]]) {
        if (edge <= 0 || edge >= GRID.frameW) continue;       // an unconstrained side draws nothing
        const x = Math.floor(edge / s);
        put(x, y, GREEN); put(x + 1, y, GREEN);
      }
    }
    if (b % 10 === 0) for (let x = 0; x < 12; x++) put(x, y0, BLUE);   // a tick every 250 frame px
  }
  for (const cxEdge of [CX - T.centerExclusion, CX + T.centerExclusion]) {
    for (let y = 0; y < h; y += 4) put(Math.floor(cxEdge / s), y, BLUE);
  }
  return PNG.sync.write(out);
}

/* ---- the manifest and the report ----------------------------------------------------- */

const entryJson = (e: { L: number[]; R: number[] }) => `{"L":[${e.L.join(',')}],"R":[${e.R.join(',')}]}`;

function manifestJson(header: Record<string, unknown>, configs: Map<string, Edges>, components: Map<string, Edges>): string {
  const lines = (m: Map<string, Edges>) => [...m].map(([k, e]) => `    ${JSON.stringify(k)}: ${entryJson(e)}`).join(',\n');
  const head = Object.entries(header).map(([k, v]) => `  ${JSON.stringify(k)}: ${JSON.stringify(v)}`).join(',\n');
  return `{\n${head},\n  "configs": {\n${lines(configs)}\n  },\n  "components": {\n${lines(components)}\n  }\n}\n`;
}

const bandRange = (bands: number[]) => {
  const runs: string[] = [];
  for (let i = 0; i < bands.length; ) {
    let j = i;
    while (j + 1 < bands.length && bands[j + 1] === bands[j] + 1) j++;
    runs.push(i === j ? `${bands[i]}` : `${bands[i]}-${bands[j]}`);
    i = j + 1;
  }
  return runs.join(', ');
};

/** Narrowest free width over the report bands, and which band. The calibration cases are read off this. */
function narrowest(e: Edges): { width: number; band: number } {
  let best = { width: GRID.frameW, band: -1 };
  for (let b = 0; b < REPORT_BANDS; b++) {
    const wdt = 2 * Math.min(CX - e.L[b], e.R[b] - CX);
    if (wdt < best.width) best = { width: wdt, band: b };
  }
  return best;
}

function writeReport(cfgs: Config[], results: Map<string, Measured>, errors: string[], partial: boolean): void {
  const rows = cfgs.filter(c => results.has(c.key)).map(c => ({ c, m: results.get(c.key)! }));
  const hBox = rows.find(r => r.m.heraldry)?.m.heraldry;
  const withCentre = rows.map(({ c, m }) => {
    const bands = m.centreOther.map((x, b) => ({ x, b })).filter(({ x, b }) => b < REPORT_BANDS && x.count > 0);
    return { c, m, bands };
  }).filter(r => r.bands.length);

  const lines: string[] = [
    `# Free-space profiles: build report${partial ? ' (PARTIAL RUN: the manifest was not written)' : ''}`,
    '',
    `Configurations measured ${rows.length} of ${cfgs.length} · errors ${errors.length}`,
    '',
    `Thresholds: clearance ${T.clearance}, centerExclusion ${T.centerExclusion}, inkThreshold ${T.inkThreshold}, groundDark ${T.groundDark}, groundLight ${T.groundLight}, ownBorderDistance ${T.ownBorderDistance}, groundBlur ${T.groundBlur}`,
    '',
    ...(errors.length ? ['## Errors', '', ...errors.map(e => `- ${e}`), ''] : []),
    `## Obstacle ink inside the centre exclusion (cx ${CX} +/- ${T.centerExclusion}), bands 0-${REPORT_BANDS - 1}`,
    '',
    `Bands 0-${REPORT_BANDS - 1} are y ${GRID.y0}-${GRID.y0 + REPORT_BANDS * GRID.step}. The profile ignores ink here (the heading is centred on it), so this is where a`,
    'certificate has artwork UNDER its own title. The stand-in heraldry' + (hBox ? ` (x ${Math.round(hBox.x)}-${Math.round(hBox.x + hBox.w)}, y ${Math.round(hBox.y)}-${Math.round(hBox.y + hBox.h)} on the first certificate)` : '') +
      ' is on every certificate and is left out of the counts below; it is the engine\'s to avoid by its own box.',
    '',
    withCentre.length ? '| configuration | bands | obstacle px | x range | what is drawn there |\n|---|---|---|---|---|' : '_None: no certificate has artwork under its title other than the device._',
    ...withCentre.map(({ c, m, bands }) => {
      const px = bands.reduce((a, { x }) => a + x.count, 0);
      const x0 = Math.min(...bands.map(({ x }) => x.x0));
      const x1 = Math.max(...bands.map(({ x }) => x.x1));
      return `| ${c.key} | ${bandRange(bands.map(({ b }) => b))} | ${px} | ${x0}-${x1} | ${m.obstacles.join(', ') || (m.by.contrast || m.by.own ? 'the ground itself' : 'inline SVG border')} |`;
    }),
    '',
    '## Narrowest centred width over bands 0-60 (frame px; the column itself is 1684 at the default padding)',
    '',
    '| configuration | narrowest | at band | y | ink | ground | underlays hidden | obstacle plates | art px | ground px | own px |',
    '|---|---|---|---|---|---|---|---|---|---|---|',
    ...rows.map(({ c, m }) => {
      const n = narrowest(m.edges);
      return `| ${c.key} | ${n.width} | ${n.band} | ${GRID.y0 + n.band * GRID.step} | ${m.lightInk ? 'light' : 'dark'} | ${m.ground.join(', ') || 'none'}${m.ownBorder ? ' (own border)' : ''} | ${m.underlays.join(', ') || '-'} | ${m.obstacles.join(', ') || '-'} | ${m.by.art} | ${m.by.contrast} | ${m.by.own} |`;
    }),
    '',
  ];
  writeFileSync(join(OUT, 'report.md'), lines.join('\n'));
}

/* ---- the run ------------------------------------------------------------------------- */

test('measure the free space of every certificate design', async ({ context }) => {
  test.setTimeout(0);
  const all = buildConfigs();
  // A trailing slash makes an entry a prefix ("zodiac//"); anything else is one exact key, so
  // "hunter//1" does not also catch rank 10.
  const cfgs = ONLY.length ? all.filter(c => ONLY.some(o => c.key === o || (o.endsWith('/') && c.key.startsWith(o)))) : all;
  expect(cfgs.length, `ORK3_PROFILE_ONLY=${ONLY.join(',')} matches no configuration`).toBeGreaterThan(0);

  mkdirSync(OUT, { recursive: true });
  // A full run owns the directory; a partial one only rewrites the images it takes, so a calibration
  // run does not delete the pictures the last one was compared against.
  if (ONLY.length === 0) {
    for (const f of readdirSync(OUT)) {
      if (/\.png$/.test(f)) rmSync(join(OUT, f), { force: true });
    }
  }

  const own = ownBorderFiles(readRecipes());
  const specs = new Map<string, FixtureSpec>();
  for (const c of cfgs) specs.set(c.key, fixtureSpecFor(c.kase));
  const dir = renderFixtures([...specs.values()], FIXTURE_TAG);

  const results = new Map<string, Measured>();
  const errors: string[] = [];
  const byFixture = new Map<string, Measured>();   // two keys that are the same render are measured once
  const queue = cfgs.slice();
  const worker = async () => {
    const page = await context.newPage();
    await serveFixtures(page, dir);
    for (let cfg = queue.shift(); cfg; cfg = queue.shift()) {
      const spec = specs.get(cfg.key)!;
      try {
        let m = byFixture.get(spec.id);
        if (!m) {
          m = await measure(page, spec, own, `${cfg.key.replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '')}.png`);
          byFixture.set(spec.id, m);
        }
        results.set(cfg.key, m);
        if (results.size % 25 === 0) console.log(`profiles: ${results.size}/${cfgs.length}`);
      } catch (e) {
        errors.push(`${cfg.key}: ${String((e as Error).message ?? e).split('\n')[0]}`);
      }
    }
    await page.close();
  };
  await Promise.all(Array.from({ length: Math.min(PAGES, cfgs.length) }, worker));

  const partial = ONLY.length > 0;
  writeReport(cfgs, results, errors, partial);

  const pick = (g: Group) => new Map(cfgs.filter(c => c.group === g && results.has(c.key)).map(c => [c.key, results.get(c.key)!.edges]));
  if (partial) {
    writeFileSync(join(OUT, 'partial.json'), JSON.stringify(Object.fromEntries([...results].map(([k, m]) => [k, { L: m.edges.L, R: m.edges.R }]))));
  } else if (errors.length === 0) {
    const header = {
      _comment: 'Generated by tools/scroll-sweep/profile.spec.ts. Do not edit by hand.',
      v: GRID.v, y0: GRID.y0, step: GRID.step, bands: GRID.bands,
      frameWidth: GRID.frameW,
      clearance: T.clearance, centerExclusion: T.centerExclusion,
      inkThreshold: T.inkThreshold, groundDark: T.groundDark, groundLight: T.groundLight,
      ownBorderDistance: T.ownBorderDistance, groundBlur: T.groundBlur,
      underlayPolicySha1: createHash('sha1').update(readFileSync(POLICY_FILE)).digest('hex'),
    };
    writeFileSync(MANIFEST, manifestJson(header, pick('configs'), pick('components')));
  }
  console.log(`profiles: ${results.size}/${cfgs.length} measured, report ${join(OUT, 'report.md')}${partial ? '' : errors.length ? ' (manifest NOT written: errors)' : `, manifest ${MANIFEST}`}`);

  // Capture integrity only: that every configuration produced a profile. Whether the numbers are
  // good is for whoever reads the report and the debug images.
  expect(errors, 'configurations that could not be measured').toEqual([]);
});
