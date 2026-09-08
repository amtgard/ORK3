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
 * PER-LAYER THRESHOLDS, each calibrated against measured populations rather than
 * chosen. A single number cannot serve all three, because one of the three has a
 * DESIGNED floor and the others do not.
 *
 * C∩B — 2.0. Every certificate in the module scores 0.86-0.91 here, and it is the same
 *   26 pixels every time: #BottomTextFlourish's curl crossing the "Amtgard" versal at
 *   frame x 588-609, y 648-664. Located by rasterising the intersection and asking the
 *   DOM what is there, not by assuming. That is the swash sweeping around the initial —
 *   drawn that way — so the floor is design, not noise, and the threshold has to clear
 *   it. Real ornament collisions measure 6.83 (case zero), 8.03, 8.38 and 9.05.
 *   Separation: 2.2x above the designed contact, 3.4x below the smallest real defect.
 *
 * C∩A — 1.0. Clean cases measure 0.00 (griffon), 0.03 (cyanotype) and 0.77; confirmed
 *   defects measure 1.66 (garber's flavour over the tape measure), 3.10 (dragon's
 *   signer over the knotwork) and 6.33 (crown's signature across the laurel). Every one
 *   of those was looked at, not inferred from its score.
 *
 * C∩C — 0.3. There is no designed text-on-text at all, so the floor is a true zero: no
 *   clean case scores above 0. The two real ones measure 0.42 (garber's flavour into
 *   the signature) and 1.32. It sits low ON PURPOSE — a text-on-text collision is
 *   scored against the whole certificate's glyph area, so even a bad one between two
 *   small blocks is a small fraction of it.
 */
export const THRESHOLDS = { CA: 1.0, CB: 2.0, CC: 0.3 };

/** Over threshold on ANY layer pair. The pairs are not comparable, so this is not a max. */
export const isDirty = (r: Result) =>
  r.CA.score > THRESHOLDS.CA || r.CB.score > THRESHOLDS.CB || r.CC.score > THRESHOLDS.CC;

/** For ranking and bucketing only: each pair scaled by its own threshold, so 1.0 is
    "exactly at the line" on whichever pair is worst. */
export const worst = (r: Result) => Math.max(
  r.CA.score / THRESHOLDS.CA, r.CB.score / THRESHOLDS.CB, r.CC.score / THRESHOLDS.CC);
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
    `| \`${r.id}\` | ${r.CA.score.toFixed(2)} | ${r.CB.score.toFixed(2)} | ${r.CC.score.toFixed(2)} `
    + `| ${worstRun(r)} |`).join('\n');

  const bySurface = ['print', 'preview'].map(s => {
    const rs = results.filter(r => r.surface === s);
    if (!rs.length) return `| ${s} | – | – |`;
    return `| ${s} | ${rs.length} | ${rs.filter(isDirty).length} |`;
  }).join('\n');

  const pairs = results.filter(r => r.ccPair && r.CC.score > THRESHOLDS.CC);
  const blank = results.filter(r => r.glyphPx === 0);

  writeFileSync(join(outDir, 'report.md'), `# Scrollery ink-collision sweep

Cases **${results.length}** · over threshold **${dirty.length}**

Thresholds are per layer pair and each is calibrated against a measured population —
see \`THRESHOLDS\` in lib/report.ts for the numbers and what was looked at:
**C∩A ${THRESHOLDS.CA}** · **C∩B ${THRESHOLDS.CB}** · **C∩C ${THRESHOLDS.CC}** ppt of glyph area.

Score is the interfering layer's ink strength summed over the intersection with the
glyph mask, divided by the glyph area, in parts per thousand. Both masks are eroded
one pixel first, so two antialiasing fringes touching is not a finding. \`worst run\`
is the largest connected component — a swash drawn through a line and the same area of
scattered pepper score alike on area alone and do not look alike.

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

${pairs.length ? pairs.map(r => `- \`${r.id}\`: blocks ${r.ccPair} at ${r.CC.score.toFixed(2)} ppt`).join('\n')
               : '_none above threshold._'}

## Cases with no measurable type

${blank.length ? blank.map(r => `- \`${r.id}\``).join('\n')
               : '_none._'}
`);
}
