import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

export type Score = { score: number; overlapPx: number; worstRun: number };
export type Result = {
  id: string; stage: string; surface: string;
  order: string; variant: string; rank: number; hand: string; text: string;
  glyphPx: number;
  CA: Score; CB: Score; CC: Score;
  /* Which two text blocks produced the C n C score, as their indices in BLOCK_SEL.
     Empty when nothing collided. */
  ccPair: string;
};

export const ZERO: Score = { score: 0, overlapPx: 0, worstRun: 0 };
/**
 * DAMAGE IN INK PIXELS, NOT AS A FRACTION OF THE TYPE.
 *
 * `severity()` reports parts per thousand of the glyph area, and that ratio has a
 * confound this baseline made obvious: **68% of certificates with EMPTY optional
 * fields came back dirty**, and an empty certificate should be the cleanest thing the
 * module can print.
 *
 * The cause is that the one DESIGNED overlap is a fixed AREA, not a fixed fraction.
 * Every certificate carries the same 26 px of #BottomTextFlourish sweeping around the
 * "Amtgard" versal. On a full certificate that is 26 of ~24,000 glyph pixels and
 * scores 0.9; on a blank one it is 26 of ~3,000 and scores 8 — over any threshold that
 * catches real defects. The ratio was measuring how much text the certificate had.
 *
 * So the verdict is on ABSOLUTE damage: glyph pixels struck through, weighted by how
 * dark the thing striking them is. Derived from what severity() already returns rather
 * than measured separately, so an existing results.json can be re-scored without
 * re-running the sweep — which is how this correction was made without discarding a
 * 31-minute baseline.
 *
 * Calibrated on the named cases, every one of which was rendered and LOOKED AT:
 *
 *   C∩A  clean 0.0, 0.8, 20.6  |  defects 41 (garber's flavour over the tape measure),
 *                                 72 (dragon's signer over the knotwork),
 *                                 153 (crown's signature across the laurel)   -> 30
 *   C∩B  designed 21 (the versal, on every certificate in the module)
 *                                | defects 59 (case zero), 128, 137, 224      -> 40
 *   C∩C  clean 0 — there is no designed text-on-text at all
 *                                | defects 10.5 (garber flavour into signature), 35  -> 8
 */
export const THRESHOLDS = { CA: 30, CB: 40, CC: 8 };

/** Absolute weighted ink pixels: the ratio scaled back up by the area it was over. */
export const ink = (s: Score, glyphPx: number) => (s.score * glyphPx) / 1000;

/** Over threshold on ANY layer pair. The pairs are not comparable, so this is not a max. */
export const isDirty = (r: Result) =>
  ink(r.CA, r.glyphPx) > THRESHOLDS.CA
  || ink(r.CB, r.glyphPx) > THRESHOLDS.CB
  || ink(r.CC, r.glyphPx) > THRESHOLDS.CC;

/** For ranking and bucketing only: each pair scaled by its own threshold, so 1.0 is
    "exactly at the line" on whichever pair is worst. */
export const worst = (r: Result) => Math.max(
  ink(r.CA, r.glyphPx) / THRESHOLDS.CA,
  ink(r.CB, r.glyphPx) / THRESHOLDS.CB,
  ink(r.CC, r.glyphPx) / THRESHOLDS.CC);

export const worstRun = (r: Result) => Math.max(r.CA.worstRun, r.CB.worstRun, r.CC.worstRun);

/** The buckets the distribution is reported in, in ppt of glyph area. */
const BUCKETS = [0, 0.1, 0.25, 0.5, 0.9, 1, 2, 5, 10, Infinity];

export function writeReport(results: Result[], outDir: string, threshold: number): void {
  mkdirSync(outDir, { recursive: true });
  writeFileSync(
    join(outDir, 'results.json'),
    JSON.stringify({ threshold, generated: new Date().toISOString(), results }, null, 2),
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

  const rows = dirty.slice(0, 200).map(r =>
    `| \`${r.id}\` | ${ink(r.CA, r.glyphPx).toFixed(0)} | ${ink(r.CB, r.glyphPx).toFixed(0)} `
    + `| ${ink(r.CC, r.glyphPx).toFixed(0)} | ${worstRun(r)} |`).join('\n');

  const bySurface = ['print', 'preview'].map(s => {
    const rs = results.filter(r => r.surface === s);
    if (!rs.length) return `| ${s} | – | – |`;
    return `| ${s} | ${rs.length} | ${rs.filter(isDirty).length} |`;
  }).join('\n');

  const pairs = results.filter(r => r.ccPair && ink(r.CC, r.glyphPx) > THRESHOLDS.CC);
  const blank = results.filter(r => r.glyphPx === 0);

  writeFileSync(join(outDir, 'report.md'), `# Scrollery ink-collision sweep

Cases **${results.length}** · over threshold **${dirty.length}**

Thresholds are per layer pair and each is calibrated against a measured population —
see \`THRESHOLDS\` in lib/report.ts for the numbers and what was looked at:
**C∩A ${THRESHOLDS.CA}** · **C∩B ${THRESHOLDS.CB}** · **C∩C ${THRESHOLDS.CC}** weighted ink pixels.

Each figure is glyph pixels struck through by that layer, weighted by how dark the
striking ink is. Both masks are eroded one pixel first, so two antialiasing fringes
touching is not a finding. \`worst run\` is the largest connected component — a swash
drawn through a line and the same area of scattered pepper come out alike on area alone
and do not look alike.

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

| case | C∩A | C∩B | C∩C | worst run (px) |
| --- | --- | --- | --- | --- |
${rows || '| _none_ | | | | |'}

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
