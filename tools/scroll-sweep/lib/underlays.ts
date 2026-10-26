import type { Page } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { join } from 'node:path';

/*
 * THE UNDERLAY POLICY, RESOLVED ONCE FOR EVERY READER.
 *
 * system/lib/ork3/data/scroll-layout-underlays.php says, per certificate design, which plates the
 * type is DESIGNED to sit on. Two things in this harness need that answer and they must not
 * disagree: the free-space profile builder (profile.spec.ts), which leaves underlays out of the
 * obstacle ink, and the sweep's layer A (lib/raster.ts captureLayers), which is what Q6 scores
 * text-over-artwork against. Both go through this file.
 *
 * A plate resolves to one of three things (see the policy file's header for the data):
 *   none            an obstacle, as everything is unless the policy says otherwise
 *   whole           the whole plate is an underlay: hidden from the artwork layer
 *   window(y0, y1)  an underlay ONLY in frame rows y0 <= y < y1 (frame px, half-open), an
 *                   obstacle everywhere else: the rows of the window are hidden and the rest of
 *                   the plate stays
 *
 * Matching is PHP's own fnmatch(), as the review sheet does, so nothing here can disagree with what
 * the policy file means. Verdicts are cached: one plate has one answer per order.
 *
 * FAIL CLOSED. A window is in the frame's own 2550x3300 coordinates, so it only means something on
 * a plate that IS the frame. A windowed plate whose box is not the frame (within 1px) is an obstacle
 * and is logged, never clipped by a guess. `decide()` is that rule, as a pure function.
 */

export const POLICY_FILE = join(__dirname, '..', '..', '..', 'system', 'lib', 'ork3', 'data', 'scroll-layout-underlays.php');

export const FRAME_W = 2550;
export const FRAME_H = 3300;

/** A policy entry: a plain fnmatch pattern, or a pattern restricted to frame rows [y0, y1). */
export type Entry = string | { pattern: string; y: [number, number] };
export type Policy = Record<string, Entry[]>;

export type Verdict =
  | { kind: 'none' }
  | { kind: 'whole'; pattern: string }
  | { kind: 'window'; y0: number; y1: number; pattern: string };

/** A plate's painted box in frame px, relative to the frame's own corner. */
export type Box = { x: number; y: number; w: number; h: number };

/** The policy file as data, read by PHP so a window's array shape arrives as written. */
export function readPolicy(): Policy {
  const r = spawnSync('php', ['-r', 'echo json_encode(require $argv[1]);', POLICY_FILE], { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 });
  if (r.error) throw new Error(`could not run php: ${r.error.message}`);
  if (r.status !== 0) throw new Error(`reading the underlay policy failed\n${r.stderr}${r.stdout}`);
  return JSON.parse(r.stdout);
}

/** Combine the entries that matched one plate: a plain pattern (whole) beats a window. Pure. */
export function combine(entries: Entry[]): Verdict {
  const plain = entries.find(e => typeof e === 'string');
  if (plain !== undefined) return { kind: 'whole', pattern: plain as string };
  const win = entries.find(e => typeof e !== 'string') as { pattern: string; y: [number, number] } | undefined;
  if (win) return { kind: 'window', y0: win.y[0], y1: win.y[1], pattern: win.pattern };
  return { kind: 'none' };
}

const cache = new Map<string, Verdict>();

/** The verdict for each plate file (path below assets/scrolls/) of one order. */
export function resolveUnderlays(order: string, files: string[]): Map<string, Verdict> {
  const ask = Array.from(new Set(files)).filter(f => !cache.has(`${order}|${f}`));
  if (ask.length) {
    const code = `$policy = require $argv[1];
$in = json_decode(stream_get_contents(STDIN), true);
echo json_encode(array_map(function ($q) use ($policy) {
  return array_values(array_filter($policy[$q['order']] ?? [], function ($e) use ($q) {
    return fnmatch(is_array($e) ? $e['pattern'] : $e, $q['file']);
  }));
}, $in));`;
    const r = spawnSync('php', ['-r', code, POLICY_FILE], {
      input: JSON.stringify(ask.map(file => ({ order, file }))),
      encoding: 'utf8',
      maxBuffer: 16 * 1024 * 1024,
    });
    if (r.error) throw new Error(`could not run php: ${r.error.message}`);
    if (r.status !== 0) throw new Error(`asking the underlay policy failed\n${r.stderr}${r.stdout}`);
    (JSON.parse(r.stdout) as Entry[][]).forEach((m, i) => cache.set(`${order}|${ask[i]}`, combine(m)));
  }
  return new Map(files.map(f => [f, cache.get(`${order}|${f}`)!]));
}

/** Whether a box is the whole 2550x3300 frame, within a pixel. */
export const isFullFrame = (b: Box): boolean =>
  Math.abs(b.x) <= 1 && Math.abs(b.y) <= 1 && Math.abs(b.w - FRAME_W) <= 1 && Math.abs(b.h - FRAME_H) <= 1;

/**
 * What to do with one plate: its verdict, unless it is a window on a plate that is not the frame, in
 * which case it stays an obstacle (`none`) and `failedClosed` says so. Pure.
 */
export function decide(v: Verdict, box: Box): { verdict: Verdict; failedClosed: boolean } {
  if (v.kind === 'window' && !isFullFrame(box)) return { verdict: { kind: 'none' }, failedClosed: true };
  return { verdict: v, failedClosed: false };
}

/* ---- in the page --------------------------------------------------------------------- */

export type Plate = { tag: string; file: string; box: Box };
export type PlateFacts = { plates: Plate[]; ground: string[]; order: string };

const PLATE_ATTR = 'data-sweep-plate-img';
const POLICY_STYLE_ID = 'sweep-underlay-policy';

/**
 * Tag every plate `data-sweep-plate-img="<n>"` so a rule can name it, and report them. A plate is an
 * <img> inside `.award-template` and outside `.Award` whose src is under /scrolls/; the heraldry
 * (not under /scrolls/) is not one. A ground is a direct child that markGround() (or a wash
 * promoted by classifyWashes) marked `data-sweep-ground`: it is NEVER a plate here, so it can never
 * be hidden (the policy header's rule: hiding it from layer A while G keeps it would report the
 * whole ground as artwork). Call after markGround().
 */
export function tagPlates(page: Page): Promise<PlateFacts> {
  return page.evaluate(({ attr }) => {
    const frame = document.querySelector('svg[data-scroll-frame]') as SVGElement | null;
    const tpl = document.querySelector('.award-template') as HTMLElement | null;
    const award = document.querySelector('.Award') as HTMLElement | null;
    if (!frame || !tpl || !award) throw new Error('no certificate on the page');
    const fr = frame.getBoundingClientRect();
    const k = fr.width / 2550;
    const rel = (src: string): string | null => {
      const m = new URL(src, location.href).pathname.match(/\/scrolls\/(.+)$/);
      return m ? decodeURIComponent(m[1]) : null;
    };
    const plates: { tag: string; file: string; box: { x: number; y: number; w: number; h: number } }[] = [];
    const ground: string[] = [];
    let n = 0;
    tpl.querySelectorAll('img').forEach(img => {
      img.removeAttribute(attr);
      if (award.contains(img)) return;
      const file = rel(img.currentSrc || img.src);
      if (file === null) return;                               // the heraldry, which is not a plate
      if (img.parentElement === tpl && img.hasAttribute('data-sweep-ground')) { ground.push(file); return; }
      const tag = String(n++);
      img.setAttribute(attr, tag);
      const r = img.getBoundingClientRect();
      plates.push({ tag, file, box: { x: (r.left - fr.left) / k, y: (r.top - fr.top) / k, w: r.width / k, h: r.height / k } });
    });
    return { plates, ground, order: tpl.dataset.order ?? '' };
  }, { attr: PLATE_ATTR });
}

/** The CSS that removes frame rows [y0, y1) of a plate and keeps the rest (percentages of its own box). Pure. */
export function windowClip(y0: number, y1: number): string {
  const p0 = (y0 / FRAME_H) * 100;
  const p1 = (y1 / FRAME_H) * 100;
  // One polygon over the rows above and the rows below: the band between p0 and p1 is not in it.
  return `polygon(0 0, 100% 0, 100% ${p0}%, 0 ${p0}%, 0 ${p1}%, 100% ${p1}%, 100% 100%, 0 100%)`;
}

/** The stylesheet text that hides what the verdicts say to hide. Pure: no page needed to test it. */
export function hidingCss(items: { tag: string; verdict: Verdict }[]): string {
  const rules: string[] = [];
  for (const { tag, verdict } of items) {
    const sel = `img[${PLATE_ATTR}="${tag}"]`;
    if (verdict.kind === 'whole') rules.push(`${sel} { visibility: hidden !important; }`);
    if (verdict.kind === 'window') rules.push(`${sel} { clip-path: ${windowClip(verdict.y0, verdict.y1)} !important; }`);
  }
  return rules.join('\n');
}

/** Install (or, with empty css, clear) the policy's hiding rules. Separate from the layer stylesheet. */
export const setUnderlayCss = (page: Page, css: string): Promise<void> =>
  page.evaluate(({ id, css }) => {
    let st = document.getElementById(id) as HTMLStyleElement | null;
    if (!st) { st = document.createElement('style'); st.id = id; document.head.appendChild(st); }
    st.textContent = css;
  }, { id: POLICY_STYLE_ID, css });

export type Applied = { css: string; whole: string[]; window: string[]; failedClosed: string[]; obstacles: string[] };

/**
 * Resolve the policy for the plates on this page, applying `decide()` to each, and return what to
 * hide. Nothing is installed: the caller turns it on for the layer that wants it (`setUnderlayCss`)
 * and off again. A windowed plate that is not the frame is logged here, once per call.
 */
export function plan(facts: PlateFacts, order = facts.order, log: (m: string) => void = m => console.warn(m)): Applied {
  const verdicts = resolveUnderlays(order, facts.plates.map(p => p.file));
  const items: { tag: string; verdict: Verdict }[] = [];
  const out: Applied = { css: '', whole: [], window: [], failedClosed: [], obstacles: [] };
  for (const p of facts.plates) {
    const d = decide(verdicts.get(p.file)!, p.box);
    if (d.failedClosed) {
      out.failedClosed.push(p.file);
      log(`underlay window on ${p.file} ignored: its box ${Math.round(p.box.w)}x${Math.round(p.box.h)} at ${Math.round(p.box.x)},${Math.round(p.box.y)} is not the ${FRAME_W}x${FRAME_H} frame, so the whole plate stays an obstacle`);
    }
    items.push({ tag: p.tag, verdict: d.verdict });
    if (d.verdict.kind === 'whole') out.whole.push(p.file);
    else if (d.verdict.kind === 'window') out.window.push(p.file);
    else out.obstacles.push(p.file);
  }
  out.css = hidingCss(items);
  return out;
}

/** Everything the policy says about this page's plates, as a style text; the one call a reader needs. */
export async function underlayCssFor(page: Page): Promise<Applied> {
  return plan(await tagPlates(page));
}
