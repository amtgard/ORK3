import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

export type Score = { score: number; overlapPx: number; worstRun: number };

/**
 * Which driver put the certificates on the page (lib/drive.ts). A required argument to
 * writeReport and stamped into results.json: the two drivers do not draw the same
 * certificate (the fixture driver has no preview surface, no trim or no-lines sheets, and a
 * stand-in for the kingdom's arms), so a figure from one is not evidence about the other.
 * A results.json from before the stamp existed was a compose run; it was the only driver.
 */
export type Driver = 'fixture' | 'compose';
export type Result = {
  id: string; stage: string; surface: string;
  order: string; variant: string; rank: number; hand: string; text: string;
  glyphPx: number;
  CA: Score; CB: Score; CC: Score;
  /* The same two scores with only the top half's glyphs (layer Ctop) as the type. Like CC
     they are per-thousand of the WHOLE certificate's glyph area (`glyphPx`), so ink()
     reads all five alike. Absent from a results.json written before they existed, which
     the rescore seam still has to be able to read. */
  CAtop?: Score; CBtop?: Score;
  /* Which two text blocks produced the C n C score, as their indices in BLOCK_SEL.
     Empty when nothing collided. */
  ccPair: string;
};

export const ZERO: Score = { score: 0, overlapPx: 0, worstRun: 0 };
/**
 * DAMAGE IN INK PIXELS, NOT AS A FRACTION OF THE TYPE.
 *
 * `severity()` reports parts per thousand of the glyph area, and that ratio has a
 * confound an early baseline made obvious: **68% of certificates with EMPTY optional
 * fields came back dirty**, and an empty certificate should be the cleanest thing the
 * module can print.
 *
 * The cause is that the one DESIGNED overlap is a fixed AREA, not a fixed fraction.
 * Every certificate carries the same swash sweeping around the "Amtgard" versal. On a
 * full certificate that is a small share of ~24,000 glyph pixels; on a blank one it is
 * a large share of ~3,000, and it clears any threshold that catches real defects. The
 * ratio was measuring how much text the certificate had.
 *
 * So the verdict is on ABSOLUTE damage: glyph pixels struck through, weighted by how
 * dark the thing striking them is. It is derived from what severity() already returns,
 * so an existing results.json can be re-scored without re-running the sweep —
 * rescore.spec.ts is that seam.
 *
 * THESE NUMBERS ARE IN FRAME PIXELS AND ONLY MEAN ANYTHING AT 1:1. The harness
 * rasterises the certificate at its own 2550x3300 size (see forceFullScale); an
 * earlier version downsampled to about a third of that, and every figure here was
 * roughly twelve times smaller. If the rasterisation scale is ever changed again,
 * these have to be re-derived and the baseline re-captured, or before and after are
 * comparing resolutions rather than code.
 *
 * Calibrated at 1:1 against the named cases, each of which was rendered and LOOKED AT,
 * with the module's own layout reverted (ORK3_SWEEP_REVERT=1) to get the "before":
 *
 *   C∩A  clean 0 (griffon), 34 (dragon), 172 (issuer-on-knotwork)
 *        defects 508 (cyanotype), 927 (garber), 1368 (crown across the laurel)
 *        -> 300, which is 1.7x above the highest clean and 1.7x below the lowest defect
 *   C∩B  designed 238-262 — the versal swash, on every certificate in the module
 *        defects 974 (case zero), 975, 1877 (uncial), 3121 (issuer on the knotwork)
 *        -> 500, 1.9x above the designed contact and 1.9x below the smallest defect
 *   C∩C  no designed text-on-text exists at all, so the floor is a true zero
 *        -> 95, the 0.32x-derived 8 carried across by the measured 11.9x scale ratio
 */
export const THRESHOLDS = { CA: 300, CB: 500, CC: 95 };

/** Absolute weighted ink pixels: the ratio scaled back up by the area it was over. */
export const ink = (s: Score, glyphPx: number) => (s.score * glyphPx) / 1000;

/** Over threshold on ANY layer pair. The pairs are not comparable, so this is not a max. */
export const isDirty = (r: Result) =>
  ink(r.CA, r.glyphPx) > THRESHOLDS.CA
  || ink(r.CB, r.glyphPx) > THRESHOLDS.CB
  || ink(r.CC, r.glyphPx) > THRESHOLDS.CC;

/**
 * Over threshold in the TOP HALF alone, on either pair. The same two thresholds as isDirty
 * and for the same reason: a stroke through a letter is a stroke through a letter wherever
 * on the page the letter is. There is no C∩C twin; text-on-text is a whole-page question
 * and the top half has no designed contact to separate from it.
 */
export const isDirtyTop = (r: Result) =>
  (r.CAtop ? ink(r.CAtop, r.glyphPx) > THRESHOLDS.CA : false)
  || (r.CBtop ? ink(r.CBtop, r.glyphPx) > THRESHOLDS.CB : false);

/** For ranking and bucketing only: each pair scaled by its own threshold, so 1.0 is
    "exactly at the line" on whichever pair is worst. */
export const worst = (r: Result) => Math.max(
  ink(r.CA, r.glyphPx) / THRESHOLDS.CA,
  ink(r.CB, r.glyphPx) / THRESHOLDS.CB,
  ink(r.CC, r.glyphPx) / THRESHOLDS.CC);

export const worstRun = (r: Result) => Math.max(r.CA.worstRun, r.CB.worstRun, r.CC.worstRun);

/** The buckets the distribution is reported in, in ppt of glyph area. */
const BUCKETS = [0, 0.1, 0.25, 0.5, 0.9, 1, 2, 5, 10, Infinity];

export function writeReport(results: Result[], outDir: string, threshold: number, driver: Driver): void {
  mkdirSync(outDir, { recursive: true });
  writeFileSync(
    join(outDir, 'results.json'),
    JSON.stringify({ threshold, driver, generated: new Date().toISOString(), results }, null, 2),
  );

  const dirty = results.filter(isDirty).sort((a, b) => worst(b) - worst(a));

  /*
   * The DISTRIBUTION matters as much as the pass list. Some C n A overlap is by
   * design — type sits on owl's blueprint plate on purpose — so a reader has to be
   * able to see what is near the line, not only what crossed it. A report that
   * publishes a boolean invites exactly the "it passed" reading this whole exercise
   * exists to stop.
   */
  const hist = BUCKETS.slice(0, -1).map((lo, i) => {
    const hi = BUCKETS[i + 1];
    const n = results.filter(r => worst(r) >= lo && worst(r) < hi).length;
    const bar = '#'.repeat(Math.min(60, Math.round((n / Math.max(1, results.length)) * 120)));
    return `| ${lo} – ${hi === Infinity ? '∞' : hi} | ${n} | ${bar} |`;
  }).join('\n');

  /* A dash, not a zero, where the case predates the top-half scores: zero would read as
     "measured and clean". */
  const top = (s: Score | undefined, r: Result) => (s ? ink(s, r.glyphPx).toFixed(0) : '–');
  const rows = dirty.slice(0, 200).map(r =>
    `| \`${r.id}\` | ${ink(r.CA, r.glyphPx).toFixed(0)} | ${ink(r.CB, r.glyphPx).toFixed(0)} `
    + `| ${ink(r.CC, r.glyphPx).toFixed(0)} | ${top(r.CAtop, r)} | ${top(r.CBtop, r)} `
    + `| ${worstRun(r)} |`).join('\n');

  const bySurface = ['print', 'preview'].map(s => {
    const rs = results.filter(r => r.surface === s);
    if (!rs.length) return `| ${s} | – | – |`;
    return `| ${s} | ${rs.length} | ${rs.filter(isDirty).length} |`;
  }).join('\n');

  /* How many of those are over the line in the top half alone, and how many cases have the
     top-half scores at all. The second matters when reading a merged or re-scored file. */
  const scoredTop = results.filter(r => r.CAtop && r.CBtop).length;
  const dirtyTop = results.filter(isDirtyTop).length;

  const pairs = results.filter(r => r.ccPair && ink(r.CC, r.glyphPx) > THRESHOLDS.CC);
  const blank = results.filter(r => r.glyphPx === 0);

  writeFileSync(join(outDir, 'report.md'), `# Scrollery ink-collision sweep

Driver **${driver}** · cases **${results.length}** · over threshold **${dirty.length}** · over threshold in the top half **${dirtyTop}**${scoredTop === results.length ? '' : ` (scored on ${scoredTop} of ${results.length})`}

Thresholds are per layer pair and each is calibrated against a measured population —
see \`THRESHOLDS\` in lib/report.ts for the numbers and what was looked at:
**C∩A ${THRESHOLDS.CA}** · **C∩B ${THRESHOLDS.CB}** · **C∩C ${THRESHOLDS.CC}** weighted ink pixels.

Each figure is glyph pixels struck through by that layer, weighted by how dark the
striking ink is. Both masks are eroded one pixel first, so two antialiasing fringes
touching is not a finding. \`worst run\` is the largest connected component — a swash
drawn through a line and the same area of scattered pepper come out alike on area alone
and do not look alike.

The **top** columns are the same two scores with only the type above and including the
order line left in, which is the half the typeset engine places. They use the same
thresholds, so \`C∩A top\` over ${THRESHOLDS.CA} or \`C∩B top\` over ${THRESHOLDS.CB} is a
defect the engine's own half owns.

## By surface

| surface | cases | over threshold |
| --- | --- | --- |
${bySurface}

## Severity distribution (worst layer pair per case)

Bucketed on the worst pair scaled by its own threshold, so **1.0 is exactly at the line**.

| × threshold | cases | |
| --- | --- | --- |
${hist}

## Over threshold${dirty.length > 200 ? ' (first 200)' : ''}

| case | C∩A | C∩B | C∩C | C∩A top | C∩B top | worst run (px) |
| --- | --- | --- | --- | --- | --- | --- |
${rows || '| _none_ | | | | | | |'}

## Text on text

Which two blocks collided, as indices into BLOCK_SEL (0 = the issuing title line,
3 = the recipient, 7 = the citation, 8 = the flavour, 9 = the date, 10 = the signer).

${pairs.length ? pairs.map(r => `- \`${r.id}\`: blocks ${r.ccPair} at ${ink(r.CC, r.glyphPx).toFixed(0)} ink px`).join('\n')
               : '_none above threshold._'}

## Cases with no measurable type

${blank.length ? blank.map(r => `- \`${r.id}\``).join('\n')
               : '_none._'}
`);
}
