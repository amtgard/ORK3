import type { Page } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { basename, join, posix, relative, sep } from 'node:path';
import { settle } from './drive';

/*
 * The login-free way to put a certificate on a page.
 *
 * Every other driver here fetches Scrollery/compose, which needs a session, a real
 * award and a browser per worker. A certificate is only Scroll::BuildScroll() plus
 * Scrollery_render.tpl, so bin/scroll-render-fixtures.php runs those two in the app
 * container and writes one HTML file per spec, and serveFixtures() hands those files
 * to the page from a route on the app's own origin. Same origin matters: the page's
 * fonts, stylesheets and scripts load from the running app exactly as they do in
 * production, and only the document itself comes from disk.
 */

/** One certificate. Everything BuildScroll() and the render page read; nothing about a login. */
export type FixtureSpec = {
  id: string;
  hand: string;
  order: string;
  awardId: number;
  title: string;
  variant: string;
  rank: number;
  scope: 'park' | 'kingdom';
  issuerName: string;
  issuerTitle: string;
  useThe: boolean;
  useOf: boolean;
  player: string;
  pronoun: string;
  reason: string;
  givenBy: string;
  date: string;
  recipe?: { ground: string; border: string; rankSet: string };
  heading?: 'auto' | 'classic' | 'centered';
  heraldry?: string;
  up?: 0 | 2 | 4;
  /** Left unset to match compose, which sends no IsLadder; see bin/scroll-render-fixtures.php. */
  isLadder?: boolean;
};

const SWEEP_DIR = join(__dirname, '..');
const REPO_ROOT = join(SWEEP_DIR, '..', '..');
const FIXTURE_OUT = join(SWEEP_DIR, 'out', 'fixtures');
const FIXTURE_ASSETS = join(SWEEP_DIR, 'fixtures');

/* A name that becomes one path segment. No leading dot, so `.` and `..` never pass. */
const SAFE_NAME = /^[A-Za-z0-9][A-Za-z0-9._-]*$/;

/** Read `tools/scroll-sweep/corpus/<name>.json` and return its `cases`. */
export function loadCorpus(name: string): FixtureSpec[] {
  if (!SAFE_NAME.test(name)) throw new Error(`bad corpus name: ${name}`);
  const file = join(SWEEP_DIR, 'corpus', `${name}.json`);
  const data = JSON.parse(readFileSync(file, 'utf8')) as { cases?: FixtureSpec[] };
  if (!Array.isArray(data.cases)) throw new Error(`${file} has no "cases" array`);
  return data.cases;
}

/** The container sees the checkout at ORK3_CONTAINER_ROOT; map a host path inside the repo onto it. */
function toContainer(hostPath: string): string {
  const root = process.env.ORK3_CONTAINER_ROOT ?? '/var/www/ork.amtgard.com';
  const rel = relative(REPO_ROOT, hostPath);
  if (rel.startsWith('..')) throw new Error(`${hostPath} is outside the repo, so the container cannot see it`);
  return posix.join(root, ...rel.split(sep));
}

/**
 * Render `specs` to `tools/scroll-sweep/out/fixtures/<tag>/<id>.html` and return that
 * directory. A spec that fails to render is reported on stderr and has no file, so the
 * page that asks for it gets a 404 rather than a stale certificate from an earlier run.
 */
export function renderFixtures(specs: FixtureSpec[], tag: string): string {
  if (!SAFE_NAME.test(tag)) throw new Error(`bad fixture tag: ${tag}`);
  const dir = join(FIXTURE_OUT, tag);
  mkdirSync(dir, { recursive: true });
  for (const f of readdirSync(dir)) {
    if (f.endsWith('.html')) rmSync(join(dir, f), { force: true });
  }
  const specsFile = join(dir, 'specs.json');
  writeFileSync(specsFile, JSON.stringify(specs, null, 1));

  const container = process.env.ORK3_APP_CONTAINER ?? 'ork3-php8-app';
  const root = process.env.ORK3_CONTAINER_ROOT ?? '/var/www/ork.amtgard.com';
  const r = spawnSync(
    'docker',
    ['exec', container, 'php', `${root}/bin/scroll-render-fixtures.php`, toContainer(specsFile), toContainer(dir)],
    { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 },
  );
  if (r.error) throw new Error(`could not run docker: ${r.error.message}`);
  // 2 is "some specs failed, the rest rendered": report those and let the caller see the
  // missing files. Anything else means nothing rendered at all.
  if (r.status === 2) {
    const bad = r.stderr.split('\n').filter(l => / ERROR /.test(l));
    console.warn(`scroll-render-fixtures: ${bad.length} spec(s) failed\n${bad.join('\n')}`);
  } else if (r.status !== 0) {
    throw new Error(`scroll-render-fixtures exited ${r.status}\n${r.stderr}${r.stdout}`);
  }
  return dir;
}

/**
 * Serve the rendered files at `/orkui/__fixture/<id>.html`, plus the stand-in heraldry
 * image at `/orkui/__fixture/heraldry-standin.png` (a solid square, so a profile that
 * uses it measures the ornament's box and not somebody's device).
 */
export async function serveFixtures(page: Page, dir: string): Promise<void> {
  await page.route('**/orkui/__fixture/**', route => {
    const name = basename(new URL(route.request().url()).pathname);
    if (!SAFE_NAME.test(name)) return route.fulfill({ status: 400, body: 'bad name' });
    if (name === 'heraldry-standin.png') {
      const png = join(FIXTURE_ASSETS, name);
      return existsSync(png)
        ? route.fulfill({ status: 200, contentType: 'image/png', body: readFileSync(png) })
        : route.fulfill({ status: 404, body: `missing ${png}` });
    }
    const file = join(dir, name);
    if (!name.endsWith('.html') || !existsSync(file)) {
      return route.fulfill({ status: 404, body: `no fixture ${name}` });
    }
    return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', body: readFileSync(file, 'utf8') });
  });
}

/**
 * Open one fixture and wait until it is what would print.
 *
 * `?face=` is not optional. The render page's inline script re-applies the hand the
 * browser last remembered unless the URL names one, so without it a fixture rendered as
 * unicase would quietly come up in whatever hand the previous page left behind.
 */
export async function openFixture(page: Page, spec: FixtureSpec): Promise<void> {
  const url = `./__fixture/${spec.id}.html?face=${encodeURIComponent(spec.hand)}` + (spec.up ? `&up=${spec.up}` : '');
  await page.goto(url, { waitUntil: 'load' });
  await settle(page);
}
