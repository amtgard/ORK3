import type { Page } from '@playwright/test';
import type { SweepCase } from './cases';
import type { Choice } from './catalogue';
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
  await page.waitForTimeout(120);
}

export async function openPrint(page: Page, c: SweepCase, ch: Choice, kingdomId: number) {
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
