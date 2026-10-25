import { test, expect, type Page } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { loadCorpus, renderFixtures, serveFixtures, openFixture, type FixtureSpec } from './lib/fixture';
import { markGround } from './lib/isolate';

/*
 * The underlay review sheet: every plate each certificate design draws, one at a time,
 * labelled with what system/lib/ork3/data/scroll-layout-underlays.php says about it.
 *
 * WHY A SHEET AND NOT A METRIC. The free-space profile treats every plate as an obstacle
 * the type must stay clear of, except the ones the design means the type to sit on (a
 * soft-light townscape, a wash, a texture). Nothing in the pixels separates those two
 * cases: smith's townscape changes only 21% of the page and reads as artwork to any
 * threshold (docs/scrollery-ink-collision-results.md section 4). It is a judgement about
 * what the artist intended, so it is written down as data and the owner signs the sheet.
 *
 * WHAT IS SHOWN. For each design and variant at rank 5, and again at ranks 9 and 10 for
 * the layers those ranks add (warrior's rank-10 plate, the martial and dragon `over_`
 * plates), the page is rendered with no text and no heraldry and then photographed:
 *
 *   ground      the certificate's own opaque ground, per markGround(), alone
 *   each plate  the ground plus that one layer. Over the ground and not over white,
 *               because a plate composited multiply or soft-light means nothing without
 *               the thing it blends into.
 *   all plates  ground plus every layer, the certificate with no type on it
 *   obstacles   ground plus every layer the policy does NOT call an underlay: what the
 *               profile builder will treat as artwork
 *
 * `custom` composes from parts, so it is rendered as one fixture per part (each ground,
 * each border on the plain parchment, each rank set) and a layer already shown for an
 * earlier part is not shown again.
 *
 * ISOLATION MUST NOT REFLOW, so every rule below is `visibility: hidden`, never
 * `display: none` (see lib/isolate.ts). The text is hidden the same way, which is why
 * the sheet does not depend on any text fitting.
 *
 * The verdict printed under each plate is computed by PHP's own fnmatch() against the
 * policy file, not by a JavaScript port of it, so the sheet cannot disagree with what the
 * policy means. Run: npx playwright test --project=scroll-underlays
 */

const OUT = join(__dirname, 'out', 'underlays');
const POLICY_FILE = join(__dirname, '..', '..', 'system', 'lib', 'ork3', 'data', 'scroll-layout-underlays.php');

/* Thumbnails are 900 frame-px wide: enough to judge a plate, small enough that the whole
   sheet is tens of megabytes and not gigabytes. Layout inside the foreignObject is in the
   2550x3300 frame whatever the SVG is displayed at, so shrinking the SVG moves nothing. */
const THUMB_W = 900;
const THUMB_CSS = `svg[data-scroll-frame] { width: ${THUMB_W}px !important; height: auto !important;
    max-width: none !important; max-height: none !important; }
  .scroll-page, .sc-stage, .sc-preview, .scroll-flow, body { max-width: none !important; overflow: visible !important; }
  .sc-toolbar, .sc-advisory, .sc-clip-notice { display: none !important; }`;

const RANKS = [5, 9, 10];
const STYLE_ID = 'underlay-isolate';

type Axes = {
  orderVariants: { order: string; variant: string }[];
  grounds: string[];
  borders: string[];
  rankSets: string[];
  groundsWithOwnBorder: string[];
};
type Policy = Record<string, string[]>;

type Layer = {
  index: number;
  /** Paths below assets/scrolls/, one per <img> that is a plate. Empty for inline SVG and the heraldry slot. */
  files: string[];
  kind: 'plate' | 'svg';
  /** Blend, opacity and filter on the img and its wrappers, as the page computes them. */
  paint: string;
  /** Painted box of the first plate, in frame px. */
  box: string;
};

const BASE: Omit<FixtureSpec, 'id' | 'order' | 'awardId' | 'title' | 'variant' | 'rank'> = {
  hand: 'cinzel',
  scope: 'kingdom',
  issuerName: 'The Kingdom of the Wetlands',
  issuerTitle: '',
  useThe: false,
  useOf: false,
  player: 'Plates Only',
  pronoun: 'their',
  reason: 'The text is hidden on this sheet',
  givenBy: 'Sample',
  date: '2026-01-01',
};

const esc = (s: string) => s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c] as string));

/** The policy file, plus which of `patterns` it says match `queries`. One php call; fnmatch is PHP's own. */
function askPolicy(queries: { order: string; file: string }[]): { policy: Policy; matched: string[][] } {
  const code = `$policy = require $argv[1];
$in = json_decode(stream_get_contents(STDIN), true);
$out = [];
foreach ($in as $q) {
  $out[] = array_values(array_filter($policy[$q['order']] ?? [], fn ($p) => fnmatch($p, $q['file'])));
}
echo json_encode(['policy' => $policy, 'matched' => $out]);`;
  const r = spawnSync('php', ['-r', code, POLICY_FILE], { input: JSON.stringify(queries), encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 });
  if (r.error) throw new Error(`could not run php: ${r.error.message}`);
  if (r.status !== 0) throw new Error(`reading ${POLICY_FILE} failed\n${r.stderr}${r.stdout}`);
  return JSON.parse(r.stdout);
}

/**
 * The open calls the policy file records as `REVIEW <order>: ...` comment notes, each
 * continuing over following comment lines indented three spaces past the asterisk. Read
 * from the file rather than restated here, so the sheet and the policy cannot disagree.
 */
function reviewNotes(): { order: string; text: string }[] {
  const notes: { order: string; text: string }[] = [];
  let cur: { order: string; text: string } | null = null;
  for (const line of readFileSync(POLICY_FILE, 'utf8').split('\n')) {
    const head = line.match(/^\s*\* REVIEW ([a-z]+): (.*)$/);
    const more = line.match(/^\s*\*   (\S.*)$/);
    if (head) { cur = { order: head[1], text: head[2] }; notes.push(cur); }
    else if (cur && more) cur.text += ' ' + more[1];
    else cur = null;
  }
  return notes;
}

/** A file's identity apart from its rank: `over_10.webp` and `over_6.webp` are the same plate design. */
const stemOf = (file: string) => file.replace(/\.[a-z0-9]+$/i, '').replace(/_\d+$/, '_N');

/* ---- what each fixture is ---------------------------------------------------------- */

type Fixture = { spec: FixtureSpec; group: string; label: string };

function buildFixtures(): Fixture[] {
  const axes = JSON.parse(readFileSync(join(__dirname, 'axes.json'), 'utf8')) as Axes;
  const corpus = loadCorpus('review-2026-10-25');
  const idFor = (order: string) => {
    // Titles and award ids come from the frozen corpus, which already proved each one renders
    // (hunter is award id 0 with its own title; it is not an ORK award id).
    const c = corpus.find(x => x.order === order);
    if (!c) throw new Error(`the review corpus has no ${order} case to take an award id and title from`);
    return { awardId: c.awardId, title: c.title };
  };
  const out: Fixture[] = [];
  const slug = (s: string) => s.replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '');

  for (const { order, variant } of axes.orderVariants) {
    if (order === 'custom') continue;
    const { awardId, title } = idFor(order);
    const group = variant ? `${order}/${variant}` : order;
    for (const rank of RANKS) {
      // Warrior has no rank 0 and every design reaches 10, so 5, 9 and 10 exist everywhere.
      out.push({
        spec: { ...BASE, id: `${slug(group)}-r${rank}`, order, awardId, title, variant, rank },
        group,
        label: `${group} rank ${rank}`,
      });
    }
  }

  // custom: one fixture per part. Grounds that carry their own frame take no border.
  const { awardId, title } = idFor('custom');
  const custom = (id: string, label: string, recipe: { ground: string; border: string; rankSet: string }) =>
    out.push({ spec: { ...BASE, id: `custom-${id}`, order: 'custom', awardId, title, variant: '', rank: 5, recipe }, group: 'custom', label });
  for (const ground of axes.grounds) custom(`ground-${ground}`, `ground ${ground}`, { ground, border: 'none', rankSet: 'common' });
  for (const border of axes.borders) {
    if (border === 'none') continue;
    custom(`border-${border}`, `border ${border} on parchment`, { ground: 'parchment', border, rankSet: 'common' });
  }
  for (const rankSet of axes.rankSets) {
    if (rankSet === 'common') continue;
    custom(`rankset-${rankSet}`, `rank set ${rankSet} on parchment`, { ground: 'parchment', border: 'none', rankSet });
  }
  return out;
}

/* ---- in the page ------------------------------------------------------------------- */

/** Runs in the page. Tags every layer with data-sweep-layer and describes it. */
function enumerate(): { layers: Layer[]; ground: string[] } {
  const tpl = document.querySelector('.award-template') as HTMLElement | null;
  if (!tpl) throw new Error('no .award-template on the page');
  const frame = document.querySelector('svg[data-scroll-frame]') as SVGElement;
  const k = frame.getBoundingClientRect().width / 2550;
  const rel = (src: string): string | null => {
    const m = new URL(src, location.href).pathname.match(/\/scrolls\/(.+)$/);
    return m ? decodeURIComponent(m[1]) : null;
  };
  const describe = (img: Element, top: Element): string => {
    const bits: string[] = [];
    for (let el: Element | null = img; el; el = el === top ? null : el.parentElement) {
      const cs = getComputedStyle(el);
      if (cs.mixBlendMode !== 'normal') bits.push(cs.mixBlendMode);
      if (parseFloat(cs.opacity) < 0.999) bits.push(`opacity ${Math.round(parseFloat(cs.opacity) * 100) / 100}`);
      if (cs.filter !== 'none') bits.push(`filter ${cs.filter.replace(/\s+/g, ' ')}`);
    }
    return Array.from(new Set(bits)).join(' · ');
  };

  const layers: Layer[] = [];
  const ground: string[] = [];
  let n = 0;
  Array.from(tpl.children).forEach(child => {
    child.removeAttribute('data-sweep-layer');
    if (child.classList.contains('Award')) return;
    const imgs = child.tagName === 'IMG' ? [child as HTMLImageElement] : Array.from(child.querySelectorAll('img'));
    const files = imgs.map(i => rel(i.currentSrc || i.src)).filter((f): f is string => f !== null);
    if (child.hasAttribute('data-sweep-ground')) {
      ground.push(...files);
      return;
    }
    // The awardee's device is an <img> but not a plate; with no heraldry given the slot is empty.
    const isPlate = files.length > 0;
    const isSvg = !isPlate && !!child.querySelector('svg');
    if (!isPlate && !isSvg) return;
    child.setAttribute('data-sweep-layer', String(n));
    const r = (imgs[0] ?? child).getBoundingClientRect();
    layers.push({
      index: n,
      files,
      kind: isPlate ? 'plate' : 'svg',
      paint: describe(imgs[0] ?? child.querySelector('svg')!, child),
      box: `${Math.round(r.width / k)}x${Math.round(r.height / k)}`,
    });
    n++;
  });
  return { layers, ground };
}

async function css(page: Page, rules: string): Promise<void> {
  await page.evaluate(({ id, rules }) => {
    let st = document.getElementById(id) as HTMLStyleElement | null;
    if (!st) { st = document.createElement('style'); st.id = id; document.head.appendChild(st); }
    st.textContent = rules;
  }, { id: STYLE_ID, rules });
}

const ONLY_GROUND = `.award-template > *:not([data-sweep-ground]) { visibility: hidden !important; }`;
const ALL_PLATES = `.Award { visibility: hidden !important; }`;
const ONLY_OBSTACLES = `.Award, [data-sweep-underlay] { visibility: hidden !important; }`;
const only = (i: number) =>
  `.award-template > *:not([data-sweep-layer="${i}"]):not([data-sweep-ground]) { visibility: hidden !important; }`;

/* ---- the sheet --------------------------------------------------------------------- */

type Shot = { file: string; caption: string; verdict?: 'UNDERLAY' | 'OBSTACLE'; note?: string; matched?: string[] };
type Block = { fixture: Fixture; ground: string[]; summary: Shot[]; layers: Shot[]; fixed: string[]; skipped: string };

function writeSheet(blocks: Block[], policy: Policy, dead: Record<string, string[]>): void {
  const groups = new Map<string, Block[]>();
  for (const b of blocks) groups.set(b.fixture.group, [...(groups.get(b.fixture.group) ?? []), b]);

  const fig = (s: Shot) => `<figure${s.verdict ? ` class="${s.verdict.toLowerCase()}"` : ''} data-find="${esc((s.caption + ' ' + (s.verdict ?? '')).toLowerCase())}">
<img loading="lazy" src="${esc(s.file)}" alt="${esc(s.caption)}">
<figcaption>${s.verdict ? `<span class="badge ${s.verdict.toLowerCase()}">${s.verdict}</span> ` : ''}<code>${esc(s.caption)}</code>${s.note ? `<br><small>${esc(s.note)}</small>` : ''}${s.matched?.length ? `<br><small>policy: <code>${esc(s.matched.join(', '))}</code></small>` : ''}</figcaption>
</figure>`;

  const policyRows = Object.entries(policy).map(([order, pats]) =>
    `<tr><th scope="row">${esc(order)}</th><td>${pats.length ? pats.map(p => `<code>${esc(p)}</code>`).join(' ') : '<em>none: every plate is an obstacle</em>'}</td></tr>`).join('\n');
  const deadRows = Object.entries(dead).filter(([, p]) => p.length)
    .map(([o, p]) => `<li><strong>${esc(o)}</strong>: ${p.map(x => `<code>${esc(x)}</code>`).join(', ')}</li>`).join('');

  const sections = [...groups].map(([group, bs]) => {
    const body = bs.filter(b => !b.skipped).map(b => `
<h3>${esc(b.fixture.label)}</h3>
<p class="meta">ground (excluded, opaque and normal blend): ${b.ground.length ? b.ground.map(g => `<code>${esc(g)}</code>`).join(', ') : '<em>none; the page itself</em>'}${b.fixed.length ? ` · always obstacles, not files: ${esc(b.fixed.join(', '))}` : ''}</p>
<div class="grid">${[...b.summary, ...b.layers].map(fig).join('\n')}</div>`).join('\n');
    const same = bs.filter(b => b.skipped).map(b => b.fixture.label);
    const tail = same.length ? `<p class="meta">Draws no plate that the renders above do not already show: ${esc(same.join(', '))}.</p>` : '';
    return `<section id="${esc(group.replace('/', '-'))}"><h2>${esc(group)}</h2>${body}${tail}</section>`;
  }).join('\n');
  const notes = reviewNotes();
  const noteBox = notes.length
    ? `<div class="calls"><strong>Open calls, where a plate is an obstacle because it was not clear-cut (from the policy file):</strong><ul>${notes.map(n => `<li><strong>${esc(n.order)}</strong>: ${esc(n.text)}</li>`).join('')}</ul></div>`
    : '';

  writeFileSync(join(OUT, 'index.html'), `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Scroll underlays review</title>
<style>
:root { color-scheme: light dark; --under: #1a7f37; --obst: #b42318; }
body { font: 14px/1.45 system-ui, sans-serif; margin: 16px; max-width: 1900px; }
header { position: sticky; top: 0; z-index: 2; padding: 8px 0; background: Canvas; display: flex; gap: 12px; align-items: baseline; flex-wrap: wrap; border-bottom: 1px solid #8884; }
header input { font: inherit; padding: 4px 8px; min-width: 18em; }
h1 { margin: 0; font-size: 18px; } h2 { margin: 32px 0 0; font-size: 20px; border-bottom: 2px solid #8886; } h3 { margin: 18px 0 2px; font-size: 15px; }
.meta { margin: 0 0 8px; font-size: 12px; opacity: .85; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 14px; }
figure { margin: 0; } figure img { width: 100%; height: auto; display: block; border: 3px solid #8884; background: #fff; }
figure.underlay img { border-color: var(--under); } figure.obstacle img { border-color: var(--obst); }
figcaption { font-size: 12px; padding-top: 4px; overflow-wrap: anywhere; }
.badge { font-weight: 700; font-size: 11px; padding: 1px 6px; border-radius: 3px; color: #fff; }
.badge.underlay { background: var(--under); } .badge.obstacle { background: var(--obst); }
table { border-collapse: collapse; } th, td { text-align: left; padding: 2px 10px 2px 0; vertical-align: top; }
code { font-size: 12px; } .warn { border: 2px solid var(--obst); padding: 6px 12px; }
.calls { border: 2px solid #b54708; padding: 6px 12px; margin: 12px 0; } .calls li { margin: 4px 0; }
</style></head><body>
<header><h1>Scroll underlays: review sheet</h1><span>UNDERLAY = the type is designed to sit on this plate. OBSTACLE = the type keeps clear of it.</span>
<input id="find" type="search" placeholder="Filter by file, verdict or blend" aria-label="Filter plates"></header>
<p>Each thumbnail is the certificate's own ground plus <strong>one</strong> plate, no text. Green border and badge: UNDERLAY. Red: OBSTACLE.
Where unsure, a plate is an obstacle and says why in the policy file. Heraldry is left out of every render (it is always an obstacle).</p>
${noteBox}
<h2>The policy as written</h2>
<table>${policyRows}</table>
${deadRows ? `<div class="warn"><strong>Patterns that matched no plate in any render here:</strong><ul>${deadRows}</ul></div>` : ''}
${sections}
<script>
document.getElementById('find').addEventListener('input', function (e) {
  var q = e.target.value.toLowerCase();
  document.querySelectorAll('figure').forEach(function (f) { f.hidden = q !== '' && f.dataset.find.indexOf(q) < 0; });
});
</script>
</body></html>
`);
}

test('render every plate of every order and write the review sheet', async ({ page }) => {
  test.setTimeout(0);
  const fixtures = buildFixtures();
  mkdirSync(OUT, { recursive: true });
  for (const f of readdirSync(OUT)) {
    if (/\.(jpg|html)$/.test(f)) rmSync(join(OUT, f), { force: true });
  }
  const dir = renderFixtures(fixtures.map(f => f.spec), 'underlays');
  await serveFixtures(page, dir);

  const seen = new Map<string, Set<string>>();          // group -> plate keys already shown
  const matchedEver = new Map<string, Set<string>>();   // order -> policy patterns that matched a plate
  const blocks: Block[] = [];
  const failures: string[] = [];
  let policy: Policy = askPolicy([]).policy;

  for (const fx of fixtures) {
    try {
      await openFixture(page, fx.spec);
      await page.addStyleTag({ content: THUMB_CSS });
      await markGround(page);
      const { layers, ground } = await page.evaluate(enumerate);
      const order = fx.spec.order;

      const asked = layers.flatMap(l => l.files.map(file => ({ order, file })));
      const answer = askPolicy(asked);
      policy = answer.policy;
      let q = 0;
      const verdict = new Map<number, { under: boolean; matched: string[] }>();
      for (const l of layers) {
        const m = l.files.map(() => answer.matched[q++]);
        for (const pats of m) for (const p of pats) matchedEver.set(order, (matchedEver.get(order) ?? new Set()).add(p));
        // A layer is an underlay only if every plate in it is. A mixed layer stays an obstacle.
        verdict.set(l.index, { under: l.files.length > 0 && m.every(x => x.length > 0), matched: Array.from(new Set(m.flat())) });
      }
      await page.evaluate((under) => {
        document.querySelectorAll('[data-sweep-underlay]').forEach(e => e.removeAttribute('data-sweep-underlay'));
        under.forEach(i => document.querySelector(`[data-sweep-layer="${i}"]`)?.setAttribute('data-sweep-underlay', '1'));
      }, layers.filter(l => verdict.get(l.index)!.under).map(l => l.index));

      const svg = page.locator('svg[data-scroll-frame]').first();
      const shoot = async (name: string, rules: string): Promise<string> => {
        await css(page, rules);
        const file = `${fx.spec.id}-${name}.jpg`;
        await svg.screenshot({ path: join(OUT, file), type: 'jpeg', quality: 82 });
        return file;
      };

      // The group's first fixture shows every layer; later ones (other ranks, other custom parts)
      // show only plates not yet shown, keyed by design (rank digits folded) and paint.
      const shown = seen.get(fx.group) ?? new Set<string>();
      const first = !seen.has(fx.group);
      seen.set(fx.group, shown);
      const fresh = layers.filter(l => {
        if (l.kind === 'svg') return first && !shown.has('svg');
        return first || l.files.some(f => !shown.has(`${stemOf(f)}|${l.paint}`));
      });
      if (!first && fresh.length === 0) {
        blocks.push({ fixture: fx, ground, summary: [], layers: [], fixed: [], skipped: 'no plate here that an earlier render did not already show' });
        continue;
      }

      const summary: Shot[] = [
        { file: await shoot('ground', ONLY_GROUND), caption: 'ground alone' },
        { file: await shoot('plates', ALL_PLATES), caption: 'ground and every plate, no text' },
        { file: await shoot('obstacles', ONLY_OBSTACLES), caption: 'obstacles only (underlays removed)' },
      ];
      const shots: Shot[] = [];
      const fixed: string[] = [];
      for (const l of fresh) {
        if (l.kind === 'svg') { fixed.push('knotwork border (inline SVG)'); shown.add('svg'); continue; }
        const v = verdict.get(l.index)!;
        for (const f of l.files) shown.add(`${stemOf(f)}|${l.paint}`);
        shots.push({
          file: await shoot(`layer${l.index}`, only(l.index)),
          caption: l.files.join(' + '),
          verdict: v.under ? 'UNDERLAY' : 'OBSTACLE',
          note: `layer ${l.index + 1} of ${layers.length} · ${l.box}${l.paint ? ' · ' + l.paint : ' · normal blend'}`,
          matched: v.matched,
        });
      }
      blocks.push({ fixture: fx, ground, summary, layers: shots, fixed, skipped: '' });
    } catch (e) {
      failures.push(`${fx.spec.id}: ${String((e as Error).message ?? e).split('\n')[0]}`);
    }
  }

  // A pattern no render drew is either a typo or a plate nothing here reaches; both want a person.
  const dead: Record<string, string[]> = {};
  for (const [order, pats] of Object.entries(policy)) {
    const hit = matchedEver.get(order) ?? new Set<string>();
    dead[order] = pats.filter(p => !hit.has(p));
  }
  writeSheet(blocks, policy, dead);
  console.log(`underlay sheet: ${join(OUT, 'index.html')}`);

  expect(failures, 'fixtures that could not be rendered or photographed').toEqual([]);
  expect(Object.entries(dead).filter(([, p]) => p.length), 'policy patterns that matched no plate in any render').toEqual([]);
});
