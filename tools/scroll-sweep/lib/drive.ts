import type { Page } from '@playwright/test';
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import type { SweepCase } from './cases';
import type { Choice } from './catalogue';
import type { FixtureSpec } from './fixture';
import { PROFILES } from './text';

/* Scroll::DEFAULT_RECIPE's own pair. Stage C varies ground/border/rankSet, not the
   palette: the sixteen pairs are colour choices on the same geometry, and colour
   changes what a collision LOOKS like without changing whether there is one. */
const RECIPE_COLOURS = { primary: 'hsl(35, 29%, 55%)', secondary: 'hsl(34, 72%, 78%)' };

function fields(c: SweepCase, ch: Choice, kingdomId: number): Record<string, string> {
  const t = PROFILES[c.text];
  const f: Record<string, string> = {
    scopeType: 'kingdom',
    scopeId: String(kingdomId),
    awardId: String(ch.awardId),
    title: ch.title,
    rank: String(c.rank),
    variant: c.variant,
    player: t.player,
    reason: t.reason,
    givenBy: t.givenBy,
    date: t.date,
    issuerName: t.issuerName,
    issuerTitle: t.issuerTitle,
    customName: t.customName,
  };
  /* useThe/useOf are read as === '1', so an absent key and an empty one mean the same
     thing to the controller. Only set them when true. */
  if (t.useThe) f.useThe = '1';
  if (t.useOf) f.useOf = '1';
  if (c.recipe) {
    f.ground = c.recipe.ground;
    f.border = c.recipe.border;
    f.rankSet = c.recipe.rankSet;
    f.primary = RECIPE_COLOURS.primary;
    f.secondary = RECIPE_COLOURS.secondary;
  }
  return f;
}

export function printUrl(c: SweepCase, ch: Choice, kingdomId: number): string {
  const q = new URLSearchParams({ Route: 'Scrollery/compose', ...fields(c, ch, kingdomId) });
  q.set('face', c.hand);
  if (c.up) q.set('up', String(c.up));
  if (c.trim) q.set('trim', '1');
  if (c.lines === false) q.set('lines', '0');
  return './index.php?' + q.toString();
}

/**
 * Wait until what is on screen is what would print.
 *
 * Three things, in order, and all three are load-bearing:
 *
 *   fonts   — every hand is font-display:block, so before the face lands the type is
 *             NOT PAINTED AT ALL. A raster taken here measures a blank page and calls
 *             every certificate clean.
 *   images  — the plates ARE layer A. decode(), not merely `complete`: a decoded
 *             image is one the compositor can paint.
 *   autofit — scrollery-autofit.js sizes the type on document.fonts.ready. A raster
 *             taken before it finishes measures the server's first-guess sizes, which
 *             are not what prints. It is idempotent by construction (every element's
 *             baseline is recorded in data-af-base and restored before each run), so
 *             calling it again here cannot compound.
 */
/*
 * MEASURE THE STATE BEFORE THIS WORK, AT THE SAME RESOLUTION.
 *
 * A baseline is only evidence if the before and the after were measured the same way,
 * and this harness's rasterisation changed mid-flight — it was downsampling the
 * certificate to about a third of frame size until forceFullScale() landed. Comparing
 * a 1:1 run against a 0.32x baseline would compare resolutions, not code.
 *
 * Re-capturing the baseline means running the OLD layout behaviour, and the old
 * behaviour is not recoverable from git: these files carry unrelated in-flight work
 * that the "before" must keep. So the four changes are undone from OUTSIDE instead,
 * after the autofit pass has run — which needs no hook in production code and leaves
 * the working tree untouched:
 *
 *   1. fitTitle()          -> restore #IssuerTitle to the size the server wrote
 *   2. anchorOrnaments()   -> drop the measured `top` back to the CSS default
 *   3. date/signer fits    -> restore both to the size the server wrote
 *   4. overflow-wrap       -> back to `normal` on the signature block
 *
 * Set ORK3_SWEEP_REVERT=1 to capture a baseline. It is not a supported way to render a
 * certificate; it exists so that "no case that was clean became dirty" is a measured
 * claim rather than an asserted one.
 */
const REVERT = process.env.ORK3_SWEEP_REVERT === '1';

async function revertToBefore(page: Page): Promise<void> {
  await page.evaluate(() => {
    const restore = (sel: string) => {
      document.querySelectorAll<HTMLElement>(sel).forEach(el => {
        el.style.fontSize = el.getAttribute('data-af-inline') || '';
      });
    };
    restore('#IssuerTitle');
    restore('[data-scroll-text="date"]');
    restore('[data-scroll-text="givenBy"]');
    document.querySelectorAll<HTMLElement>('#BottomTextFlourish')
      .forEach(el => el.style.removeProperty('top'));

    const id = 'sweep-revert';
    let st = document.getElementById(id) as HTMLStyleElement | null;
    if (!st) { st = document.createElement('style'); st.id = id; document.head.appendChild(st); }
    st.textContent = 'section.SignAndDate > div > p.Body { overflow-wrap: normal !important; }';
  });
}

export async function settle(page: Page): Promise<void> {
  await page.evaluate(() => document.fonts.ready);
  await page.evaluate(async () => {
    await Promise.all(
      Array.from(document.images).map(i => i.decode().catch(() => undefined)),
    );
  });
  await page.evaluate(() => {
    const A = (window as unknown as { ScrolleryAutofit?: { fit?: (d: Document) => number } }).ScrolleryAutofit;
    if (A && typeof A.fit === 'function') A.fit(document);
  });
  if (REVERT) { await revertToBefore(page); }
  await page.waitForTimeout(120);
}

/*
 * THE FIXTURE DRIVER — `ORK3_SWEEP_DRIVER=fixture`.
 *
 * The compose driver needs a login, a kingdom whose catalogue carries the award, and a
 * browser session per worker. The fixture driver needs none of them: a certificate is
 * Scroll::BuildScroll() plus Scrollery_render.tpl, so a SweepCase and its text profile are
 * turned into a FixtureSpec, the specs for a whole shard are rendered in ONE docker exec
 * (lib/fixture.ts), and each case is then served to the page from disk. Compose stays the
 * default; nothing here changes what it does.
 *
 * Read when openPrint is CALLED rather than once at import, so a test can switch modes
 * and a shell value left over from another run cannot reach one that did not ask for it.
 *
 * WHAT THIS DRIVER CANNOT RENDER, said here so that nobody reads a clean run as covering it:
 *
 *   preview surface   it is ScrolleryAjax/preview inside the composer page, which is login-gated
 *   trim, no-lines    scroll-render-fixtures.php sets `face`, `heading` and `up` and no other
 *                     query parameter, so the page would render as if they were absent
 *   heraldry          compose draws the kingdom's own device (kingdom 1 has one); a fixture
 *                     draws none unless a spec carries one, and these do not. The heraldry is
 *                     a plate under the type, so layer A here lacks it. Before and after are
 *                     both measured this way, which is what makes the pair comparable; a
 *                     fixture figure is not comparable to a compose figure.
 *   custom, no name   the composer falls back to the kingdom's own award-94 row name when
 *                     the officer types none; a fixture has no row, so the `empty` and
 *                     `caseZero` profiles print a custom certificate with a blank award line
 *                     — what a reprint of a nameless historical grant prints.
 */
export const fixtureDriver = (): boolean => process.env.ORK3_SWEEP_DRIVER === 'fixture';

/**
 * What the composer's catalogue would have told the sweep, for every order, so the
 * catalogue lookup (and the login it needs) can be skipped.
 *
 *   awardId   the order's award_id in system/lib/ork3/data/scroll-orders.php, except:
 *               hunter  0  — that file's 6969 is Arcanyst's invention and matches nothing;
 *                          real archery grants carry no base award and Scroll::
 *                          OrderForAwardRow() recognises them by NAME, so this is also
 *                          what makes hunter reachable here and not under compose
 *               custom  94 — the file's 0 is a sentinel that never wins an id lookup;
 *                          Custom Award is how the composer reaches the custom scroll
 *   title     kingdom 1's own kingdomaward.name for it, which is what the composer sends
 *             and what the order line prints: mostly "Order of the <Order>", with the
 *             spellings the data really has ("Order of Battle", "Jovius", "Griffin").
 *             Custom has none: its title is the profile's customName.
 *   min/max   the order's `ranks`, null meaning 0-10. warrior starts at 1, and sweeping
 *             rank 0 on it would measure a state the app cannot produce.
 *
 * Checked against scroll-sweep-unit's topscore.spec.ts, which reads the data file itself,
 * so a changed award id or rank range fails a test rather than rendering another order.
 */
export const FIXTURE_ORDERS: Record<string, { awardId: number; title: string; minRank: number; maxRank: number }> = {
  battle:  { awardId: 243, title: 'Order of Battle',      minRank: 0, maxRank: 10 },
  crown:   { awardId: 239, title: 'Order of the Crown',   minRank: 0, maxRank: 10 },
  dragon:  { awardId: 25,  title: 'Order of the Dragon',  minRank: 0, maxRank: 10 },
  flame:   { awardId: 34,  title: 'Order of the Flame',   minRank: 0, maxRank: 10 },
  garber:  { awardId: 26,  title: 'Order of the Garber',  minRank: 0, maxRank: 10 },
  griffon: { awardId: 33,  title: 'Order of the Griffin', minRank: 0, maxRank: 10 },
  jovious: { awardId: 28,  title: 'Order of the Jovius',  minRank: 0, maxRank: 10 },
  lion:    { awardId: 23,  title: 'Order of the Lion',    minRank: 0, maxRank: 10 },
  hunter:  { awardId: 0,   title: 'Order of the Hunter',  minRank: 0, maxRank: 10 },
  hydra:   { awardId: 32,  title: 'Order of the Hydra',   minRank: 0, maxRank: 10 },
  mask:    { awardId: 29,  title: 'Order of the Mask',    minRank: 0, maxRank: 10 },
  owl:     { awardId: 24,  title: 'Order of the Owl',     minRank: 0, maxRank: 10 },
  rose:    { awardId: 21,  title: 'Order of the Rose',    minRank: 0, maxRank: 10 },
  smith:   { awardId: 22,  title: 'Order of the Smith',   minRank: 0, maxRank: 10 },
  warrior: { awardId: 27,  title: 'Order of the Warrior', minRank: 1, maxRank: 10 },
  zodiac:  { awardId: 30,  title: 'Order of the Zodiac',  minRank: 0, maxRank: 10 },
  custom:  { awardId: 94,  title: '',                     minRank: 0, maxRank: 10 },
};

/** loadCatalogue()'s answer without the composer: every order reachable, none declared unreachable. */
export function fixtureCatalogue(): { byOrder: Record<string, Choice>; unreachable: string[] } {
  const byOrder: Record<string, Choice> = {};
  for (const [order, f] of Object.entries(FIXTURE_ORDERS)) {
    byOrder[order] = { awardId: f.awardId, title: f.title, minRank: f.minRank, maxRank: f.maxRank };
  }
  return { byOrder, unreachable: [] };
}

/** Whether the fixture driver can render this case; see the list above for what it cannot. */
export const fixtureCovers = (c: SweepCase): boolean =>
  c.surface === 'print' && !c.trim && c.lines !== false;

/** FNV-1a, 32 bit. Any stable string hash will do; it only has to agree between calls. */
function fnv(text: string): string {
  let h = 2166136261;
  for (let i = 0; i < text.length; i++) {
    h ^= text.charCodeAt(i);
    h = Math.imul(h, 16777619);
  }
  return (h >>> 0).toString(16).padStart(8, '0');
}

/**
 * A SweepCase and its TextProfile as the certificate spec the fixture renderer reads.
 *
 * The file is named for the case AND a hash of what was asked for. A case id is not enough
 * on its own: the harness's own tests build cases by spreading one and overriding a hand or
 * a variant, which leaves the id of the case they started from, and two different
 * certificates must never share a file. The hash is what keeps the second from silently
 * measuring the first. It also keeps the id inside the renderer's [A-Za-z0-9_-] (a recipe
 * id carries `+`), and makes two cases that are the same certificate share one file.
 *
 * `scope` is always kingdom, as the compose driver's is, so the issuer line is the
 * profile's `issuerName` as a kingdom name. No `isLadder`: compose sends none.
 */
export function fixtureSpecFor(c: SweepCase): FixtureSpec {
  const f = FIXTURE_ORDERS[c.order];
  if (!f) throw new Error(`no fixture identity for order "${c.order}": add it to FIXTURE_ORDERS`);
  const t = PROFILES[c.text];
  const body: Omit<FixtureSpec, 'id'> = {
    hand: c.hand,
    order: c.order,
    awardId: f.awardId,
    title: c.order === 'custom' ? t.customName : f.title,
    variant: c.variant,
    rank: c.rank,
    scope: 'kingdom',
    issuerName: t.issuerName,
    issuerTitle: t.issuerTitle,
    useThe: t.useThe,
    useOf: t.useOf,
    player: t.player,
    pronoun: 'their',          // what compose sends when no player is looked up
    reason: t.reason,
    givenBy: t.givenBy,
    date: t.date,
    ...(c.recipe ? { recipe: c.recipe } : {}),
    ...(c.up ? { up: c.up } : {}),
  };
  const slug = c.id.replace(/[^A-Za-z0-9_-]/g, '-');
  return { id: `${slug}-${fnv(JSON.stringify(body))}`, ...body };
}

/* Where each rendered fixture lives, and which directory each page's route is serving. */
const renderedIn = new Map<string, string>();
const servedDir = new WeakMap<Page, string>();

/*
 * lib/fixture.ts imports settle() from this file, so this file may not import it back at
 * the top: whichever loaded first would see the other half-built. Loaded on use instead.
 */
const fixtureLib = () => import('./fixture');

/**
 * Render a whole list of cases in ONE docker exec.
 *
 * A render is a process start plus a bootstrap of the app, and a sweep is thousands of
 * cases, so the cost is paid per shard and not per case. Cases that are the same
 * certificate are rendered once. openPrint() renders on demand for a case that was not
 * prepared, which is what a unit test wants and a sweep does not.
 */
export async function prepareFixtures(page: Page, cases: SweepCase[], tag: string): Promise<void> {
  const fx = await fixtureLib();
  const specs = new Map<string, FixtureSpec>();
  for (const c of cases) {
    const s = fixtureSpecFor(c);
    specs.set(s.id, s);
  }
  const dir = fx.renderFixtures([...specs.values()], tag);
  for (const id of specs.keys()) renderedIn.set(id, dir);
  await fx.serveFixtures(page, dir);
  servedDir.set(page, dir);
}

async function openFixtureCase(page: Page, c: SweepCase): Promise<void> {
  const fx = await fixtureLib();
  const spec = fixtureSpecFor(c);
  let dir = renderedIn.get(spec.id);
  if (!dir || !existsSync(join(dir, `${spec.id}.html`))) {
    dir = fx.renderFixtures([spec], `adhoc-${spec.id}`);
    renderedIn.set(spec.id, dir);
  }
  /* renderFixtures() reports a spec that failed to render on stderr and leaves no file. Say
     which case it was here, so the sweep records a failure under its name instead of
     measuring the 404 page. */
  if (!existsSync(join(dir, `${spec.id}.html`))) {
    throw new Error(`the fixture for ${c.id} did not render (see scroll-render-fixtures above)`);
  }
  if (servedDir.get(page) !== dir) {
    await fx.serveFixtures(page, dir);
    servedDir.set(page, dir);
  }
  await fx.openFixture(page, spec);

  /* A wrong award id would render a different, perfectly good certificate and the sweep
     would score it under this case's name. */
  const got = await page.evaluate(() => {
    const t = document.querySelector('.award-template') as HTMLElement | null;
    return t ? { order: t.dataset.order ?? '', variant: t.dataset.variant ?? '' } : null;
  });
  if (!got) throw new Error(`no certificate on the page for ${c.id}`);
  if (got.order !== c.order || (c.variant && got.variant !== c.variant)) {
    throw new Error(`${c.id} rendered as ${got.order}/${got.variant}, not ${c.order}/${c.variant}`);
  }
}

/**
 * The print surface. Under `ORK3_SWEEP_DRIVER=fixture` the catalogue choice and the kingdom
 * are not consulted: the case alone says what to render.
 */
export async function openPrint(page: Page, c: SweepCase, ch: Choice, kingdomId: number) {
  if (fixtureDriver()) return openFixtureCase(page, c);
  await page.goto(printUrl(c, ch, kingdomId), { waitUntil: 'load' });
  await settle(page);
}

/**
 * The composer stage — the other surface.
 *
 * It differs from print in two ways that matter, and only one of them is what the
 * spec assumed. GEOMETRY: the stage is roughly 600px wide against a full page, so the
 * same viewBox lands on a different number of device pixels and the browser breaks
 * lines against a different rounding. RENDITIONS: ScrolleryAjax/preview asks for
 * ART_DISPLAY, but that controller's own note says it is a no-op until the partials
 * adopt scroll_art() — and only _parchment.tpl, custom.tpl and battle.tpl have. So
 * the surfaces differ in renditions on THREE orders, not on all of them.
 *
 * Driven through the page's own fetch rather than a bare request, so the response
 * lands in the composer's real DOM with the composer's real stylesheet over it.
 */
export async function openPreview(page: Page, c: SweepCase, ch: Choice, kingdomId: number) {
  if (fixtureDriver()) {
    throw new Error(`the fixture driver cannot render the composer preview (${c.id}); filter with fixtureCovers()`);
  }
  await page.goto(`./index.php?Route=Scrollery/create/kingdom/${kingdomId}`, { waitUntil: 'load' });
  await page.waitForSelector('#sc-mount', { state: 'attached' });
  const ok = await page.evaluate(async (args) => {
    const res = await fetch('index.php?Route=ScrolleryAjax/preview', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(args.fields),
    });
    const json = await res.json();
    if (!json || !json.Html) return false;
    const mount = document.getElementById('sc-mount');
    if (!mount) return false;
    mount.innerHTML = json.Html;
    /* scrollery.css switches hands on [data-scroll-face] on ANY ancestor, and the
       stage is where this page carries it. */
    document.getElementById('sc-stage')?.setAttribute('data-scroll-face', args.hand);
    return true;
  }, { hand: c.hand, fields: fields(c, ch, kingdomId) });
  if (!ok) throw new Error('preview returned no Html for ' + c.id);
  await settle(page);
}
