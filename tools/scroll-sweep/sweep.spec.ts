import { test, expect } from '@playwright/test';
import { join } from 'node:path';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { loadCatalogue, type Choice } from './lib/catalogue';
import { openPrint, openPreview, fixtureDriver, fixtureCovers, prepareFixtures } from './lib/drive';
import { loadAxes } from './lib/axes';
import { enumerate, enumerateExhaustiveC, namedCases, caseId, sampleMode, type SweepCase } from './lib/cases';
import { captureLayers, typeBand, scoreInk } from './lib/raster';
import { blockBoxes, showOnlyBlock, applyLayer } from './lib/isolate';
import { diffMask, severity } from './lib/mask';
import { writeReport, isDirty, worst, ZERO, type Driver, type Result, type Run } from './lib/report';

const KINGDOM = Number(process.env.ORK3_SWEEP_KINGDOM ?? 1);
const OUT = process.env.ORK3_SWEEP_OUT ?? join(__dirname, 'out');
const THRESHOLD = Number(process.env.ORK3_SWEEP_THRESHOLD ?? 0.5);
const EXHAUSTIVE = process.env.ORK3_SWEEP_EXHAUSTIVE === '1';
/* ORK3_SWEEP_SAMPLE=1: the sampled matrix, defined in lib/cases.ts. Stamped into the results. */
const SAMPLE = sampleMode();
const ONLY = process.env.ORK3_SWEEP_ONLY;      // substring filter on case id, for iteration
const LIMIT = Number(process.env.ORK3_SWEEP_LIMIT ?? 0);
/*
 * How many full renders each shard writes.
 *
 * At 1:1 a full-page PNG is 5.9 MB, so saving one per dirty case is ten gigabytes and
 * it dominates the run — the first 1:1 baseline attempt spent more time encoding
 * images than measuring. Nobody looks at seventeen hundred certificates; the worst
 * dozen and the named cases are what get looked at, and every case's NUMBERS are in
 * results.json either way. Raise it when you want a wider look.
 */
const MAX_PNG = Number(process.env.ORK3_SWEEP_MAX_PNG ?? 12);
/*
 * SHARDS. The sweep is re-run after every single fix — that is the whole discipline,
 * since moving something to fix one collision creates another — so a one-hour serial
 * run would be ten hours across this piece of work. Each shard takes every Nth case
 * and writes its own results file; bin/merge-report.mjs joins them.
 *
 * Sharding by stride rather than by block so that every shard gets a mix of orders and
 * hands: a run that dies halfway still has representative partial results.
 */
const SHARDS = Math.max(1, Number(process.env.ORK3_SWEEP_SHARDS ?? 4));

/** FNV-1a. Any stable string hash does; it only has to agree across workers. */
function idHash(id: string): number {
  let h = 2166136261;
  for (let i = 0; i < id.length; i++) {
    h ^= id.charCodeAt(i);
    h = Math.imul(h, 16777619);
  }
  return (h >>> 0);
}
const STAGES = (process.env.ORK3_SWEEP_STAGES ?? 'NABCD').toUpperCase();

/**
 * Stage D: the surface and sheet axes.
 *
 * Applied to every case within 2x of threshold plus a fixed representative set, rather
 * than to everything — the sheet axes multiply the matrix by ten and only a case
 * already near the line can be pushed over it by half-scale or by a different stage
 * geometry. The fixed set is what keeps the OTHER surface covered when nothing is near
 * the line at all, so a clean run still says something about the composer.
 */
/**
 * Every stage-D case the frozen baseline holds, as parent ids.
 *
 * Without this, stage D expands whatever is near threshold NOW — so a fix that moves
 * cases away from the line shrinks stage D, and the before/after comparison silently
 * runs on two different populations. The first run after the ornament fix compared
 * 1,531 of 2,813 cases and quietly did not re-run 448 that the baseline had recorded as
 * DIRTY. "No case that was clean became dirty" is not a claim you can make about a case
 * you did not re-run.
 *
 * So the baseline's own D population is always re-expanded, on top of whatever is near
 * the line now. The comparison population can then only grow.
 */
function baselineDParents(): Set<string> {
  const out = new Set<string>();
  try {
    const file = process.env.ORK3_SWEEP_BASELINE
      ?? join(__dirname, 'baseline', 'results-1to1-NA.json');
    const base = JSON.parse(readFileSync(file, 'utf8')) as { results: { id: string }[] };
    for (const r of base.results) {
      if (!r.id.startsWith('D_')) continue;
      /*
       * Recover the stage-A seed a D case was expanded FROM. A D id is the seed's with
       * three things changed, and all three have to be undone:
       *   D_        <- the stage prefix, which was A_
       *   _preview  <- the surface, which on the seed is always _print
       *   _up2 etc  <- the sheet suffix, appended
       * Getting only the last of those was why 166 baseline-dirty cases still went
       * unverified after the first attempt at this: every _preview case failed to
       * match any seed and was silently dropped.
       */
      out.add('A_' + r.id
        .replace(/^D_/, '')
        .replace(/(_up[24]|_trim|_nolines)$/, '')
        .replace(/_preview$/, '_print'));
    }
  } catch (e) { /* no baseline yet: the first run has nothing to preserve */ }
  return out;
}

function stageD(near: Result[], axes: ReturnType<typeof loadAxes>): SweepCase[] {
  const out: SweepCase[] = [];
  const seed = enumerate(axes, 'A', undefined, SAMPLE).filter(c => c.text !== 'empty');
  const fixed = axes.orderVariants.map(o =>
    seed.find(c => c.order === o.order && c.variant === o.variant && c.rank === 10)!).filter(Boolean);

  const variants: Partial<SweepCase>[] = [
    { surface: 'preview' }, { up: 2 }, { up: 4 }, { trim: true }, { lines: false },
  ];

  const keep = baselineDParents();
  const sources = [
    ...fixed,
    ...near.map(r => seed.find(c => c.id === r.id)).filter(Boolean) as SweepCase[],
    /* Everything the baseline expanded, so the comparison population cannot shrink. */
    ...seed.filter(c => keep.has(c.id)),
  ];
  const seen = new Set<string>();
  for (const src of sources) {
    for (const v of variants) {
      const c: SweepCase = { ...src, ...v, stage: 'D' };
      c.id = caseId(c);
      if (seen.has(c.id)) continue;
      seen.add(c.id);
      out.push(c);
    }
  }
  return out;
}

for (let shard = 0; shard < SHARDS; shard++) {
test(`sweep ${shard + 1}/${SHARDS}`, async ({ page }) => {
  test.setTimeout(0);
  const axes = loadAxes();
  /* No login here: the shared session comes from the scroll-sweep-setup project's
     storageState. See auth.setup.ts. Under ORK3_SWEEP_DRIVER=fixture there is neither: the
     "catalogue" is the fixture table and every order, hunter included, is reachable. */
  const FIXTURE = fixtureDriver();
  const DRIVER: Driver = FIXTURE ? 'fixture' : 'compose';
  const RUN: Run = { driver: DRIVER, sample: SAMPLE };
  const { byOrder, unreachable } = await loadCatalogue(page, KINGDOM);

  mkdirSync(OUT, { recursive: true });
  if (shard === 0) writeFileSync(join(OUT, 'unreachable.json'), JSON.stringify({ unreachable }, null, 2));

  /* An order's rank range comes from the kingdom's own catalogue, not from the
     vocabulary: warrior carries data-min="1", so rank 0 is not a warrior grant and
     sweeping it would measure a state the app cannot produce. */
  const reachable = (c: SweepCase) => {
    const ch: Choice | undefined = byOrder[c.order];
    return !!ch && c.rank >= ch.minRank && c.rank <= ch.maxRank;
  };

  let cases: SweepCase[] = [];
  /* Always, whatever stages are selected: the acceptance criteria name these
     individually and a cross product need not contain any given point of itself. */
  if (STAGES.includes('N')) cases.push(...namedCases());
  if (STAGES.includes('A')) cases.push(...enumerate(axes, 'A', undefined, SAMPLE));
  if (STAGES.includes('B')) cases.push(...enumerate(axes, 'B', undefined, SAMPLE));
  /* The exhaustive tier validates the factorisation and is never sampled. */
  if (STAGES.includes('C')) cases.push(...(EXHAUSTIVE ? enumerateExhaustiveC(axes) : enumerate(axes, 'C', undefined, SAMPLE)));
  cases = cases.filter(reachable);
  /* What the fixture driver cannot render is dropped, loudly, rather than rendered as
     something else: a preview case drawn as print would be scored under the wrong name. */
  const unrenderable = (list: SweepCase[]) => {
    if (!FIXTURE) return list;
    const keep = list.filter(fixtureCovers);
    if (keep.length < list.length) {
      process.stdout.write(`  fixture driver: ${list.length - keep.length} case(s) skipped `
        + `(composer preview, trim and no-lines need compose)\n`);
    }
    return keep;
  };
  cases = unrenderable(cases);
  if (ONLY) cases = cases.filter(c => c.id.includes(ONLY));
  if (LIMIT) cases = cases.slice(0, LIMIT);
  if (SHARDS > 1) cases = cases.filter((_, i) => i % SHARDS === shard);

  /* One render for this shard's whole list, not one per case. */
  if (FIXTURE) await prepareFixtures(page, cases, `sweep-${shard}`);

  const results: Result[] = [];
  const failures: string[] = [];
  let saved = 0;

  const measure = async (c: SweepCase): Promise<Result> => {
    const ch = byOrder[c.order];
    if (c.surface === 'preview') await openPreview(page, c, ch, KINGDOM);
    else await openPrint(page, c, ch, KINGDOM);

    const band = await typeBand(page);
    const shots = await captureLayers(page, band);
    const G = shots.G;
    const { glyphPx, CA, CB, CAtop, CBtop } = scoreInk(shots);

    /*
     * TEXT ON TEXT — screen on boxes, settle on pixels.
     *
     * A painted box OVER-reports (a box is not ink), so a pair it does not propose
     * cannot be a collision; every pair it does propose is then rendered block-alone
     * and intersected on real pixels. Boxes choose what to look at and never decide.
     *
     * This replaced an odd/even parity intersection that was clever and wrong: on
     * garber r10 with a long signer the flavour line runs into the signature, and
     * .Flavour and .AwardSignatory are blocks 8 and 10 — same parity, so the overlap
     * was in one raster and never in an intersection, and the union check could not
     * see it either.
     */
    let CC = ZERO;
    let ccPair = '';
    if (glyphPx) {
      const boxes = await blockBoxes(page);
      const cand: [number, number][] = [];
      for (let i = 0; i < boxes.length; i++) {
        for (let j = i + 1; j < boxes.length; j++) {
          const a = boxes[i], b = boxes[j];
          if (a.left < b.right && b.left < a.right && a.top < b.bottom && b.top < a.bottom) {
            cand.push([a.i, b.i]);
          }
        }
      }
      const cache = new Map<number, ReturnType<typeof diffMask>>();
      const blockMask = async (i: number) => {
        if (!cache.has(i)) {
          await showOnlyBlock(page, i);
          cache.set(i, diffMask(await page.screenshot({ clip: band, type: 'png', animations: 'disabled' }), G));
        }
        return cache.get(i)!;
      };
      for (const [i, j] of cand) {
        const sv = severity(await blockMask(i), await blockMask(j), glyphPx);
        if (sv.score > CC.score) { CC = sv; ccPair = `${i}+${j}`; }
      }
      await applyLayer(page, 'full');
    }

    return {
      id: c.id, stage: c.stage, surface: c.surface, order: c.order, variant: c.variant,
      rank: c.rank, hand: c.hand, text: c.text, glyphPx,
      CA, CB, CC, CAtop, CBtop,
      ccPair,
    };
  };

  const run = async (list: SweepCase[], label: string) => {
    let n = 0;
    for (const c of list) {
      try {
        const r = await measure(c);
        results.push(r);
        /* Keep the full render only where somebody has to look. Every case would be
           gigabytes; the ones over threshold are the ones a person must see. */
        /* Named cases always — they are the ones the acceptance criteria require
           someone to have looked at. Everything else up to the cap. */
        if (isDirty(r) && (c.stage === 'N' || saved < MAX_PNG)) {
          if (c.stage !== 'N') { saved++; }
          const shots = await captureLayers(page);
          writeFileSync(join(OUT, `${c.id}.png`), shots.full);
        }
      } catch (e) {
        /* Recorded AND counted. A first run wrote these to per-case files and carried
           on, and 660 of ~1,300 cases failed while the report cheerfully described the
           640 that had not — a sweep silently missing half its matrix is the exact
           failure this tool exists to prevent, so a run with failures now fails. */
        failures.push(`${c.id}: ${String(e).split('\n')[0]}`);
        writeFileSync(join(OUT, `${c.id}.error.txt`), String(e));
      }
      if (++n % 25 === 0) process.stdout.write(`  ${label} ${n}/${list.length}\n`);
    }
  };

  await run(cases, 'A-C');

  if (STAGES.includes('D')) {
    /* Within 2x of the line on any pair: only a case already near it can be pushed
       over by half-scale or by the composer's different stage geometry. */
    const near = results.filter(r => isDirty({ ...r,
      CA: { ...r.CA, score: r.CA.score * 2 },
      CB: { ...r.CB, score: r.CB.score * 2 },
      CC: { ...r.CC, score: r.CC.score * 2 } }))
      /* Capped, and capped by SEVERITY so the cap keeps the worst. Stage D multiplies
         each case it takes by five, so an unbounded near-list on a dirty baseline
         would more than double the whole run — and the hundred-and-fiftieth worst case
         is not what a sheet-geometry axis is going to teach us anything about. */
      .sort((a, b) => worst(b) - worst(a))
      .slice(0, Number(process.env.ORK3_SWEEP_D_CAP ?? 150));
    /*
     * Sharded BY ID, not by index.
     *
     * Two things have to hold at once: a case identical across shards must not run six
     * times (475 duplicates in the first full sweep), and a case present in only some
     * shards' lists must still run somewhere. Index sharding gives the first and not
     * the second — each shard's D list is a different length because half of it comes
     * from that shard's own near-threshold results, so `i % SHARDS` partitions six
     * different lists and drops whatever falls between them. That is how 180
     * baseline-dirty cases went unverified while the diff reported zero regressions.
     *
     * Hashing the id makes every shard reach the same verdict about a given case, so
     * the union is exactly the set, once each, however the lists differ.
     */
    const d = unrenderable(stageD(near, axes)
      .filter(reachable)
      .filter(c => SHARDS <= 1 || idHash(c.id) % SHARDS === shard));
    if (FIXTURE) await prepareFixtures(page, d, `sweep-${shard}-d`);
    await run(d, 'D');
  }

  if (SHARDS > 1) {
    /* The driver and the sample mode travel with the shard, so merge.spec stamps what
       actually ran and not whatever its own environment says. */
    writeFileSync(join(OUT, `shard-${shard}.json`), JSON.stringify({ ...RUN, results }));
  } else {
    writeReport(results, OUT, THRESHOLD, RUN);
  }
  process.stdout.write(`\nsweep ${shard + 1}/${SHARDS}: ${results.length} cases, `
    + `${results.filter(isDirty).length} over threshold`
    + (failures.length ? `, ${failures.length} FAILED` : '') + `\n`);
  expect(failures, `cases that did not render:\n${failures.slice(0, 20).join('\n')}`).toEqual([]);
});
}
