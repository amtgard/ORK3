import { test, expect, type Page } from '@playwright/test';
import { hasAuthCredentials, login } from '../lib/auth';
import { loadCorpus, type FixtureSpec } from '../lib/fixture';
import { settle } from '../lib/drive';

// The composer half of the typeset work: the issuer line splits as print splits it, and the
// heading choice is a per-print control that reaches the print URL. The composer is behind
// an officer login, so this skips without credentials and the controller runs it with them.
test.skip(!hasAuthCredentials(), 'needs ORK3_E2E_USERNAME and ORK3_E2E_PASSWORD (the composer is behind a login)');

const KINGDOM = Number(process.env.ORK3_SWEEP_KINGDOM ?? 1);

test.beforeEach(async ({ page }) => {
  await login(page);
  await page.goto(`./index.php?Route=Scrollery/create/kingdom/${KINGDOM}`, { waitUntil: 'load' });
  await page.waitForSelector('#sc-mount #IssuerTitle', { state: 'attached' });
});

test('typing a kingdom name splits it like print', async ({ page }) => {
  // The composer prefills the scope's own title and flags; clear them so the typed name is
  // the stored kingdom shape, one whole phrase with nothing ticked.
  await page.fill('#sc-issuer-title', '');
  await page.setChecked('#sc-use-the', false);
  await page.setChecked('#sc-use-of', false);
  await page.fill('#sc-issuer-name', 'The Kingdom of the Wetlands');

  await expect(page.locator('#IssuerTitle')).toHaveText('and the Kingdom of');
  const place = page.locator('[data-scroll-text="issuerName"]');
  await expect(place).toHaveText('the Wetlands');
  expect(await place.innerHTML()).toContain('<span class="sc-art">the</span> Wetlands');
});

test('the issuer name is escaped, not parsed as markup', async ({ page }) => {
  await page.fill('#sc-issuer-title', '');
  await page.setChecked('#sc-use-the', false);
  await page.setChecked('#sc-use-of', false);
  await page.fill('#sc-issuer-name', '<b>Wet</b> & lands');

  const place = page.locator('[data-scroll-text="issuerName"]');
  await expect(place).toHaveText('<b>Wet</b> & lands');
  expect(await place.locator('b').count()).toBe(0);
});

test('the heading choice stamps the stage and is not remembered', async ({ page }) => {
  await expect(page.locator('#sc-stage')).toHaveAttribute('data-sc-heading', 'auto');
  await page.selectOption('#sc-heading', 'centered');
  await expect(page.locator('#sc-stage')).toHaveAttribute('data-sc-heading', 'centered');

  // Per print: a reload opens on Automatic, and nothing was written to localStorage.
  expect(await page.evaluate(() => Object.keys(localStorage).filter(k => /head/i.test(k)))).toEqual([]);
  await page.reload({ waitUntil: 'load' });
  await expect(page.locator('#sc-heading')).toHaveValue('auto');
  await expect(page.locator('#sc-stage')).toHaveAttribute('data-sc-heading', 'auto');
});

test('the heading choice reaches the print URL', async ({ page }) => {
  // window.open is the whole hand-off to the print window; catch its URL rather than
  // following it, so this asserts what the composer built and not what print did with it.
  await page.evaluate(() => {
    (window as any).__opened = [];
    window.open = ((url: string) => { (window as any).__opened.push(String(url)); return null; }) as any;
  });
  await page.selectOption('#sc-heading', 'centered');
  await page.click('#sc-print');

  const opened: string[] = await page.evaluate(() => (window as any).__opened);
  expect(opened).toHaveLength(1);
  expect(opened[0]).toContain('Scrollery/compose');
  expect(new URLSearchParams(opened[0].split('&').slice(1).join('&')).get('heading')).toBe('centered');
});

/* ----------------------------------------------------------------------------------------------
 * Q10: the composer previews what prints.
 *
 * Ten certificates from the frozen review corpus are typed into the composer, and the print page is
 * opened on the URL the composer itself builds for them (window.open caught, not followed), so the two
 * surfaces are given the same inputs by construction and not by a second copy of the query. What is
 * compared is the engine's own record of the top of the page: the composition, each role's lines as
 * text, and each role's size. Sizes are written in frame px, so the composer's small stage and the
 * print page's full-width one agree on them; they may differ by 0.5% for rounding and no more.
 *
 * Not carried over from the corpus: the recipe (ground, border, rank set) and the heraldry. They are
 * the same on both surfaces here, the composer's own defaults, and they decide what the ornaments
 * avoid, not where the words are set.
 * ---------------------------------------------------------------------------------------------- */

const CORPUS = loadCorpus('review-2026-10-25');
const byId = (id: string): FixtureSpec => {
  const c = CORPUS.find(x => x.id === id);
  if (!c) throw new Error(`no corpus case ${id}`);
  return c;
};

type Heading = 'auto' | 'classic' | 'centered';

/* Chosen to cross every axis the brief names: kingdom and park scope; the park's title-name-of shape
   and the kingdom's split shape (a principality or a kingdom named by title, with the/of ticked);
   Classic, Centered and Automatic; and names from 5 to 100 characters. */
const PARITY: Array<{ id: string; heading: Heading; why: string }> = [
  { id: 'c06', heading: 'auto',     why: 'kingdom, whole phrase, a 5-character name' },
  { id: 'c05', heading: 'auto',     why: 'kingdom, whole phrase, a 100-character name at rank 10' },
  { id: 'c11', heading: 'classic',  why: 'kingdom, split form (Principality of the Crimson Sands), Classic forced' },
  { id: 'c19', heading: 'centered', why: 'kingdom, split form (Kingdom of Goldenvale), custom award, Centered forced' },
  { id: 'c13', heading: 'auto',     why: 'kingdom, "Kingdom of 13 Roads", custom award, rank 10' },
  { id: 'c42', heading: 'centered', why: 'kingdom, "Kingdom of the Desert Winds", Centered forced' },
  { id: 'c02', heading: 'classic',  why: 'park, Barony of Iron Keep, custom award, a 42-character name, Classic forced' },
  { id: 'c03', heading: 'auto',     why: 'park, Barony of Terran River' },
  { id: 'c10', heading: 'centered', why: 'park, Freehold of Bannerfall, a 5-character name, Centered forced' },
  { id: 'c36', heading: 'auto',     why: 'park, Shire of Watchers Keep' },
];

/* The corpus's scope is a shape; the composer's is a real record. Mordengaard (park 1) is in kingdom 1,
   so one officer can open both. Every issuer box is overwritten below, so the scope only decides how
   the issuer line is split (scopeType), which is the thing being compared. */
const PARK = Number(process.env.ORK3_SWEEP_PARK ?? 1);

async function openComposer(page: Page, scope: 'kingdom' | 'park'): Promise<void> {
  const id = scope === 'park' ? PARK : KINGDOM;
  await page.goto(`./index.php?Route=Scrollery/create/${scope}/${id}`, { waitUntil: 'load' });
  await page.waitForSelector('#sc-mount .Award', { state: 'attached' });
}

async function previewIdle(page: Page): Promise<void> {
  await expect(page.locator('#sc-stage')).toHaveAttribute('aria-busy', 'false');
}

/* Put a corpus case into the composer's boxes. The award and the rank go first and are waited on,
   because each is a round trip that replaces the stage; the text after them is written in place. */
async function fillCase(page: Page, c: FixtureSpec, heading: Heading): Promise<void> {
  const picked = await page.evaluate((order) => {
    const sel = document.getElementById('sc-award') as HTMLSelectElement;
    const opts = Array.from(sel.options);
    // A custom certificate is the placeholder entry (Custom Title here), the one the composer lets the
    // officer name; the other award-94 rows already carry a name of their own and offer no field.
    const i = opts.findIndex(o => o.dataset.order === order && (order !== 'custom' || !!o.dataset.customName));
    if (i < 0) return false;
    sel.selectedIndex = i;
    sel.dispatchEvent(new Event('change', { bubbles: true }));
    return true;
  }, c.order);
  expect(picked, `the composer carries an award for the ${c.order} order`).toBe(true);
  await previewIdle(page);

  const have = Number(await page.inputValue('#sc-rank'));
  if (have !== c.rank) {
    await page.click(`#sc-rank-pills .sc-rank-pill[data-rank="${c.rank}"]`);
    await previewIdle(page);
  }

  if (c.order === 'custom') await page.fill('#sc-customname', c.title);
  await page.evaluate((hand) => {
    const sel = document.getElementById('sc-face') as HTMLSelectElement;
    sel.value = hand;
    sel.dispatchEvent(new Event('change', { bubbles: true }));
  }, c.hand);
  await page.selectOption('#sc-heading', heading);

  await page.fill('#sc-issuer-title', c.issuerTitle);
  await page.fill('#sc-issuer-name', c.issuerName);
  await page.setChecked('#sc-use-the', c.useThe);
  await page.setChecked('#sc-use-of', c.useOf);
  await page.fill('#sc-player', c.player);
  await page.fill('#sc-reason', c.reason);
  await page.fill('#sc-givenby', c.givenBy);
  // The date is a Flatpickr over a hidden input: set it through the instance, which fires the change.
  await page.evaluate((d) => {
    const el = document.getElementById('sc-date') as any;
    if (el._flatpickr) { el._flatpickr.setDate(d, true); return; }
    el.value = d;
    el.dispatchEvent(new Event('change', { bubbles: true }));
  }, c.date);
  // The player search is a dropdown on the same input; it has nothing to do with this case.
  await page.keyboard.press('Escape');
  await previewIdle(page);
}

/* The composer fits through fitSoon, one animation frame after a write. Wait for fonts, then two
   frames, and read what the page did on its own. An explicit fit here would hide a composer that
   forgot to ask for one. */
async function composerSettled(page: Page): Promise<void> {
  await page.evaluate(() => document.fonts.ready);
  await page.evaluate(() => new Promise<void>(r => requestAnimationFrame(() => requestAnimationFrame(() => r()))));
  await page.waitForTimeout(150);
}

/* The URL the composer would open for print. window.open is caught, so this asserts what the composer
   built. `print=1` is dropped so that the page is a preview and does not open a print dialog. */
async function printUrlOf(page: Page): Promise<string> {
  await page.evaluate(() => {
    (window as any).__opened = [];
    window.open = ((url: string) => { (window as any).__opened.push(String(url)); return null; }) as any;
  });
  await page.click('#sc-print');
  const opened: string[] = await page.evaluate(() => (window as any).__opened);
  expect(opened, 'the Print button opens one window').toHaveLength(1);
  return opened[0].replace(/&print=1(?=&|$)/, '');
}

type Top = {
  comp: string | null;
  sectionComp: string | null;
  roles: Record<string, { size: number | null; lines: string[] } | null>;
};

/** Runs in the page. `root` is the element holding the one certificate under test. */
async function readTop(page: Page, root: string): Promise<Top> {
  return page.evaluate((rootSel) => {
    const award = document.querySelector<HTMLElement>(`${rootSel} .Award`)!;
    const norm = (t: string | null) => (t || '').replace(/\s+/g, ' ').trim();
    const roles: Top['roles'] = {};
    for (const role of ['lead', 'place', 'name', 'award']) {
      const el = award.querySelector<HTMLElement>(`[data-sc-role="${role}"]`);
      if (!el) { roles[role] = null; continue; }
      const raw = el.getAttribute('data-sc-size');
      roles[role] = {
        size: raw === null || raw === '' ? null : Number(raw),
        lines: Array.from(el.querySelectorAll('.sc-line')).map(l => norm(l.textContent)),
      };
    }
    const section = award.querySelector('[data-sc-comp]');
    return {
      comp: award.getAttribute('data-sc-comp'),
      sectionComp: section ? section.getAttribute('data-sc-comp') : null,
      roles,
    };
  }, root);
}

for (const pc of PARITY) {
  test(`preview matches print: ${pc.id} (${pc.why})`, async ({ page }) => {
    const c = byId(pc.id);
    await openComposer(page, c.scope);
    await fillCase(page, c, pc.heading);
    await composerSettled(page);
    const preview = await readTop(page, '#sc-mount');
    const url = await printUrlOf(page);

    await page.goto(url, { waitUntil: 'load' });
    await page.waitForSelector('.scroll-flow .Award', { state: 'attached' });
    await settle(page);
    const print = await readTop(page, '.scroll-flow');

    // A parity that holds because both sides are empty is not parity. The engine ran, on both,
    // and it set the roles it was asked to set.
    for (const [surface, top] of [['preview', preview], ['print', print]] as const) {
      expect(top.comp, `${surface}: the engine chose a composition`).toMatch(/^(classic|centered)$/);
      for (const role of ['lead', 'place', 'name', 'award']) {
        const r = top.roles[role];
        expect(r, `${surface}: has a ${role} role`).not.toBeNull();
        expect(Number.isFinite(r!.size), `${surface}: ${role} has a finite data-sc-size (${r!.size})`).toBe(true);
        expect(r!.lines.length, `${surface}: ${role} was broken into lines`).toBeGreaterThan(0);
      }
    }
    if (pc.heading !== 'auto') expect(print.comp, 'a forced heading is the composition that prints').toBe(pc.heading);

    expect(preview.comp, 'composition').toBe(print.comp);
    expect(preview.sectionComp, 'issuing section composition').toBe(print.sectionComp);
    for (const role of ['lead', 'place', 'name', 'award']) {
      expect(preview.roles[role]!.lines, `${role} lines`).toEqual(print.roles[role]!.lines);
      const a = preview.roles[role]!.size!;
      const b = print.roles[role]!.size!;
      expect(Math.abs(a - b) / b, `${role} size ${a} against ${b}`).toBeLessThanOrEqual(0.005);
    }
  });
}

/* ----------------------------------------------------------------------------------------------
 * The heading choice reaches print, and the print page's own picker re-sets it in place.
 * ---------------------------------------------------------------------------------------------- */

test('the heading choice reaches print', async ({ page }) => {
  const c = byId('c22');
  await openComposer(page, 'park');
  await fillCase(page, c, 'centered');
  await composerSettled(page);
  const url = await printUrlOf(page);
  expect(new URL(url, page.url()).searchParams.get('heading')).toBe('centered');

  await page.goto(url, { waitUntil: 'load' });
  await page.waitForSelector('.scroll-flow .Award', { state: 'attached' });
  await settle(page);
  await expect(page.locator('body')).toHaveAttribute('data-sc-heading', 'centered');
  await expect(page.locator('#sc-heading')).toHaveValue('centered');
  await expect(page.locator('.scroll-flow .Award').first()).toHaveAttribute('data-sc-comp', 'centered');
});

test('the print page heading picker re-letters in place and keeps the URL honest', async ({ page }) => {
  const c = byId('c22');
  await openComposer(page, 'park');
  await fillCase(page, c, 'auto');
  await composerSettled(page);
  const url = await printUrlOf(page);

  await page.goto(url, { waitUntil: 'load' });
  await page.waitForSelector('.scroll-flow .Award', { state: 'attached' });
  await settle(page);

  const select = page.locator('#sc-heading');
  const award = page.locator('.scroll-flow .Award').first();
  await expect(select).toHaveValue('auto');
  expect(await select.locator('option').evaluateAll(os => os.map(o => (o as HTMLOptionElement).value)))
    .toEqual(['auto', 'classic', 'centered']);

  // Not a navigation: a marker set on this document must survive both changes.
  await page.evaluate(() => { (window as any).__same_document = true; });
  const stamped = async () => page.evaluate(() => {
    const a = document.querySelector('.scroll-flow .Award')!;
    return { body: document.body.getAttribute('data-sc-heading'), comp: a.getAttribute('data-sc-comp') };
  });

  for (const heading of ['centered', 'classic'] as const) {
    await select.selectOption(heading);
    await expect(page.locator('body')).toHaveAttribute('data-sc-heading', heading);
    // The fit is synchronous inside the change handler, so the composition is already the new one.
    await expect(award).toHaveAttribute('data-sc-comp', heading);
    expect(await stamped()).toEqual({ body: heading, comp: heading });
    expect(await page.evaluate(() => (window as any).__same_document)).toBe(true);

    const search = await page.evaluate(() => window.location.search);
    expect(search.match(/[?&]heading=/g), 'one heading= parameter, replaced and not appended').toHaveLength(1);
    expect(new URLSearchParams(search).get('heading')).toBe(heading);
    expect(new URLSearchParams(search).get('Route')).toBe('Scrollery/compose');
  }
});

test('the print page heading picker is chrome, and is not on the paper', async ({ page }) => {
  await openComposer(page, 'kingdom');
  await composerSettled(page);
  const url = await printUrlOf(page);
  await page.goto(url, { waitUntil: 'load' });
  await page.waitForSelector('.scroll-flow .Award', { state: 'attached' });

  const label = page.locator('.sc-toolbar label[for="sc-heading"]');
  await expect(label).toBeVisible();
  expect(await page.locator('#sc-heading').getAttribute('title'), 'tooltips are data-tip, never title').toBeNull();
  // The same control as the hand's: the same label and select classes.
  // Plus sc-tip, which carries the heading's data-tip tooltip.
  expect(await label.getAttribute('class')).toBe((await page.locator('.sc-toolbar label[for="sc-face"]').getAttribute('class')) + ' sc-tip');
  expect(await page.locator('#sc-heading').getAttribute('class')).toBe(await page.locator('#sc-face').getAttribute('class'));

  await page.emulateMedia({ media: 'print' });
  await expect(page.locator('#sc-heading')).toBeHidden();
  await expect(page.locator('.sc-toolbar')).toBeHidden();
});

/* ----------------------------------------------------------------------------------------------
 * The Heading control says what Automatic chose.
 *
 * Automatic is Classic whenever Classic fits and Centered only when it does not, so on most
 * certificates it looked identical to Classic. The Automatic option now carries the engine's own
 * answer: "Automatic (Classic)", "Automatic (Centered)", or "Automatic (mixed)" on a page of
 * certificates that disagree.
 * ---------------------------------------------------------------------------------------------- */

const autoText = (page: Page) => page.locator('#sc-heading option[value="auto"]').textContent();
const compOf = (page: Page, root: string) =>
  page.evaluate((r) => document.querySelector(`${r} .Award`)!.getAttribute('data-sc-comp'), root);

test('the composer says what Automatic resolved to, after every fit', async ({ page }) => {
  await openComposer(page, 'kingdom');
  await page.fill('#sc-issuer-title', 'Kingdom');
  await page.fill('#sc-issuer-name', 'Wetlands');
  await composerSettled(page);
  expect(await compOf(page, '#sc-mount')).toBe('classic');
  expect(await autoText(page)).toBe('Automatic (Classic)');

  // A place that cannot sit on the Classic line flips the engine's own choice, with no change to
  // the Heading control: editing the name is enough, so the label must follow every fit.
  await page.fill('#sc-issuer-name', 'the Very Long and Winding Marches of the Seven Uncharted Windswept Highlands and Isles');
  await composerSettled(page);
  expect(await compOf(page, '#sc-mount')).toBe('centered');
  expect(await autoText(page)).toBe('Automatic (Centered)');

  await page.fill('#sc-issuer-name', 'Wetlands');
  await composerSettled(page);
  expect(await autoText(page)).toBe('Automatic (Classic)');
});

test('forcing Centered or Classic re-sets the certificate, and the labels beside Automatic stay plain', async ({ page }) => {
  await openComposer(page, 'kingdom');
  await page.fill('#sc-issuer-title', 'Kingdom');
  await page.fill('#sc-issuer-name', 'Wetlands');
  await composerSettled(page);
  expect(await compOf(page, '#sc-mount')).toBe('classic');

  await page.selectOption('#sc-heading', 'centered');
  await composerSettled(page);
  expect(await compOf(page, '#sc-mount')).toBe('centered');
  // A forced choice says nothing about what Automatic would have done: the label keeps its last answer.
  expect(await autoText(page)).toBe('Automatic (Classic)');
  expect(await page.locator('#sc-heading option[value="centered"]').textContent()).toBe('Centered');
  expect(await page.locator('#sc-heading option[value="classic"]').textContent()).toBe('Classic');

  // And the other way: a certificate that Automatic sets Centered can be forced Classic.
  await page.selectOption('#sc-heading', 'auto');
  await page.fill('#sc-issuer-name', 'the Very Long and Winding Marches of the Seven Uncharted Windswept Highlands and Isles');
  await composerSettled(page);
  expect(await compOf(page, '#sc-mount')).toBe('centered');
  await page.selectOption('#sc-heading', 'classic');
  await composerSettled(page);
  expect(await compOf(page, '#sc-mount')).toBe('classic');
});

test('the Heading and Hand fields each have usable width in the composer', async ({ page }) => {
  for (const width of [1400, 390]) {
    await page.setViewportSize({ width, height: 900 });
    await openComposer(page, 'kingdom');
    const heading = await page.locator('#sc-heading').boundingBox();
    const hand = await page.locator('.sc-hand-trigger').boundingBox();
    expect(heading!.width, `heading select at ${width}px`).toBeGreaterThan(200);
    expect(hand!.width, `hand control at ${width}px`).toBeGreaterThan(200);
    // "Automatic (Classic)" is not truncated by the control.
    expect(await page.locator('#sc-heading').evaluate((el: HTMLSelectElement) => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
  }
});

test('the print page says what Automatic chose, with a data-tip and no title', async ({ page }) => {
  await openComposer(page, 'kingdom');
  await page.fill('#sc-issuer-title', 'Kingdom');
  await page.fill('#sc-issuer-name', 'Wetlands');
  await composerSettled(page);
  const url = await printUrlOf(page);
  await page.goto(url, { waitUntil: 'load' });
  await page.waitForSelector('.scroll-flow .Award', { state: 'attached' });
  await settle(page);

  const award = page.locator('.scroll-flow .Award').first();
  await expect(award).toHaveAttribute('data-sc-comp', 'classic');
  await expect(page.locator('#sc-heading option[value="auto"]')).toHaveText('Automatic (Classic)');

  const label = page.locator('.sc-toolbar label[for="sc-heading"]');
  expect(await label.getAttribute('data-tip')).toContain('Automatic sets the classic heading');
  expect(await label.getAttribute('title')).toBeNull();

  // Forcing each composition re-sets the page, and the Automatic label keeps its last answer.
  await page.selectOption('#sc-heading', 'centered');
  await expect(award).toHaveAttribute('data-sc-comp', 'centered');
  await expect(page.locator('#sc-heading option[value="auto"]')).toHaveText('Automatic (Classic)');
  await page.selectOption('#sc-heading', 'classic');
  await expect(award).toHaveAttribute('data-sc-comp', 'classic');
});

test('a bulk page whose certificates disagree reads "Automatic (mixed)"', async ({ page }) => {
  await openComposer(page, 'kingdom');
  const url = await printUrlOf(page);
  await page.goto(url, { waitUntil: 'load' });
  await page.waitForSelector('.scroll-flow .Award', { state: 'attached' });
  await settle(page);
  const auto = page.locator('#sc-heading option[value="auto"]');
  await expect(auto).toHaveText(/^Automatic \((Classic|Centered)\)$/);

  // A second certificate, as a bulk run would hold, composed the other way. The label follows the
  // engine's own report on the page, so it is driven here by that report.
  await page.evaluate(() => {
    const first = document.querySelector('.scroll-flow .scroll-page')!;
    const copy = first.cloneNode(true) as HTMLElement;
    const a = copy.querySelector('.Award')!;
    const was = document.querySelector('.scroll-flow .Award')!.getAttribute('data-sc-comp');
    a.setAttribute('data-sc-comp', was === 'classic' ? 'centered' : 'classic');
    document.querySelector('.scroll-flow')!.appendChild(copy);
  });
  await expect(auto).toHaveText('Automatic (mixed)');
});

test('ScrolleryHeading.label: one word when the certificates agree, mixed when not, nothing without a report', async ({ page }) => {
  await openComposer(page, 'kingdom');
  const got = await page.evaluate(() => {
    const L = (window as any).ScrolleryHeading.label;
    return [L(['classic']), L(['classic', 'classic']), L(['centered']), L(['classic', 'centered']), L([]), L([null, 'bogus'])];
  });
  expect(got).toEqual(['Automatic (Classic)', 'Automatic (Classic)', 'Automatic (Centered)', 'Automatic (mixed)', null, null]);
});

/* ----------------------------------------------------------------------------------------------
 * Signature Hand: the typeface of the given-by line.
 *
 * A per-print choice built like the hand: an attribute on a certificate ancestor selects the face,
 * the choice is remembered per browser ('sc-sig'), it rides the print URL as sig=, and the default
 * ("Match the lettering") leaves the line exactly as it was.
 * ---------------------------------------------------------------------------------------------- */

const givenByFont = (page: Page, root: string) =>
  page.evaluate((r) => {
    const el = document.querySelector(`${r} [data-scroll-text="givenBy"]`)!;
    const cs = getComputedStyle(el);
    return { family: cs.fontFamily, style: cs.fontStyle, size: cs.fontSize, zoom: cs.zoom };
  }, root);

async function chooseSig(page: Page, key: string): Promise<void> {
  await page.evaluate((k) => {
    const sel = document.getElementById('sc-sig') as HTMLSelectElement;
    sel.value = k;
    sel.dispatchEvent(new Event('change', { bubbles: true }));
  }, key);
}

test('the composer: Signature Hand lists its faces, defaults to no change, and lettering follows the choice', async ({ page }) => {
  await openComposer(page, 'kingdom');
  await page.fill('#sc-givenby', 'Lady Isolde of Dunmere');
  await composerSettled(page);

  const opts = await page.locator('#sc-sig option').evaluateAll((os: HTMLOptionElement[]) =>
    os.map(o => ({ value: o.value, label: o.textContent, fam: o.getAttribute('data-face-family') })));
  expect(opts[0]).toEqual({ value: 'hand', label: 'Match the lettering', fam: null });
  expect(opts.length - 1).toBeGreaterThanOrEqual(6);
  expect(opts.length - 1).toBeLessThanOrEqual(10);
  for (const o of opts.slice(1)) expect(o.fam, `${o.value} carries its own family for the specimen`).toBeTruthy();

  // It sits in a row directly below Date / Given by, with the spec's hint.
  const below = await page.evaluate(() => {
    const given = document.getElementById('sc-givenby')!.getBoundingClientRect();
    const sig = document.getElementById('sc-sig')!.closest('.sc-field')!.getBoundingClientRect();
    return { givenBottom: given.bottom, sigTop: sig.top };
  });
  expect(below.sigTop).toBeGreaterThan(below.givenBottom);
  await expect(page.locator('.sc-field:has(#sc-sig) .sc-hint')).toHaveText('How the signed name is lettered. Remembered for your next print.');
  await expect(page.locator('label[for="sc-sig"]')).toHaveText('Signature Hand');

  const before = await givenByFont(page, '#sc-mount');
  expect(before.family).toContain('Averia Serif Libre');
  expect(await page.locator('#sc-stage').getAttribute('data-scroll-sig')).toBe('hand');

  await chooseSig(page, 'greatvibes');
  await composerSettled(page);
  const after = await givenByFont(page, '#sc-mount');
  expect(after.family).toContain('Great Vibes');
  expect(after.style).toBe('normal');
  expect(Number(after.zoom)).toBeGreaterThan(1);
  await expect(page.locator('#sc-stage')).toHaveAttribute('data-scroll-sig', 'greatvibes');
  expect(await page.evaluate(() => document.fonts.check('16px "Great Vibes"'))).toBe(true);
  expect(await page.evaluate(() => localStorage.getItem('sc-sig'))).toBe('greatvibes');
  // The closed control is drawn in the chosen face.
  expect(await page.locator('.sc-hand:has(#sc-sig) .sc-hand-label').evaluate(el => (el as HTMLElement).style.fontFamily)).toContain('Great Vibes');

  // Back to the default: the line is exactly what it was, and the closed control is not left in script.
  await chooseSig(page, 'hand');
  await composerSettled(page);
  const again = await givenByFont(page, '#sc-mount');
  expect(again).toEqual(before);
  expect(await page.locator('.sc-hand:has(#sc-sig) .sc-hand-label').evaluate(el => (el as HTMLElement).style.fontFamily)).toBe('');
});

test('the composer: the Signature Hand listbox is operable with the pointer, and it survives a reload', async ({ page }) => {
  await openComposer(page, 'kingdom');
  const wrap = page.locator('.sc-hand:has(#sc-sig)');
  await wrap.locator('.sc-hand-trigger').click();
  await expect(wrap.locator('.sc-hand-menu')).toBeVisible();
  await wrap.locator('.sc-hand-opt[data-value="allura"]').click();
  await expect(page.locator('#sc-sig')).toHaveValue('allura');
  await expect(page.locator('#sc-stage')).toHaveAttribute('data-scroll-sig', 'allura');

  await page.reload({ waitUntil: 'load' });
  await page.waitForSelector('#sc-mount .Award', { state: 'attached' });
  await expect(page.locator('#sc-sig')).toHaveValue('allura');
  await expect(page.locator('#sc-stage')).toHaveAttribute('data-scroll-sig', 'allura');
});

test('the composer fits a long signature in a script face, onto no more than two lines', async ({ page }) => {
  await openComposer(page, 'kingdom');
  await page.fill('#sc-givenby', 'Sir Reginald Fitzwilliam-Ashworth the Third, Warden of the Marches');
  for (const key of ['greatvibes', 'saintdelafield', 'doulaise']) {
    await chooseSig(page, key);
    await composerSettled(page);
    const lines = await page.evaluate(() => {
      const el = document.querySelector('#sc-mount [data-scroll-text="givenBy"]')!;
      const r = document.createRange();
      r.selectNodeContents(el);
      return new Set(Array.from(r.getClientRects()).filter(x => x.width > 0.5).map(x => Math.round(x.top))).size;
    });
    expect(lines, `${key}: the fit still holds the signer to two lines`).toBeLessThanOrEqual(2);
  }
});

test('the print page: sig= round-trips, is allowlisted, and re-letters in place', async ({ page }) => {
  await openComposer(page, 'kingdom');
  await page.fill('#sc-givenby', 'Lady Isolde of Dunmere');
  await chooseSig(page, 'pinyon');
  await composerSettled(page);
  const url = await printUrlOf(page);
  expect(new URL(url, page.url()).searchParams.get('sig')).toBe('pinyon');

  await page.goto(url, { waitUntil: 'load' });
  await page.waitForSelector('.scroll-flow .Award', { state: 'attached' });
  await settle(page);
  await expect(page.locator('body')).toHaveAttribute('data-scroll-sig', 'pinyon');
  await expect(page.locator('#sc-sig')).toHaveValue('pinyon');
  // The face is in the page's font gate: loaded by the time the fit has settled, not swapped in later.
  expect(await page.evaluate(() => document.fonts.check('16px "Pinyon Script"'))).toBe(true);
  expect(await page.evaluate(() => Array.from(document.querySelectorAll('link[rel=preload]')).map(l => l.getAttribute('href')))).toEqual(
    expect.arrayContaining([expect.stringContaining('PinyonScript-Regular.woff2')]));
  expect((await givenByFont(page, '.scroll-flow')).family).toContain('Pinyon Script');

  // Re-letter in place: the URL is kept honest and the attribute swaps with no reload.
  await page.evaluate(() => { (window as any).__same_document = true; });
  await chooseSig(page, 'alexbrush');
  await expect(page.locator('body')).toHaveAttribute('data-scroll-sig', 'alexbrush');
  await expect.poll(async () => (await givenByFont(page, '.scroll-flow')).family).toContain('Alex Brush');
  expect(await page.evaluate(() => (window as any).__same_document)).toBe(true);
  const search = await page.evaluate(() => window.location.search);
  expect(search.match(/[?&]sig=/g)).toHaveLength(1);
  expect(new URLSearchParams(search).get('sig')).toBe('alexbrush');
  expect(new URLSearchParams(search).get('Route')).toBe('Scrollery/compose');

  // The toolbar control is chrome: not on the paper.
  await page.emulateMedia({ media: 'print' });
  await expect(page.locator('#sc-sig')).toBeHidden();
});

test('the print page: the default sig leaves the given-by font unchanged, and a hostile sig= is the default', async ({ page }) => {
  await openComposer(page, 'kingdom');
  await page.fill('#sc-givenby', 'Lady Isolde of Dunmere');
  await composerSettled(page);
  const url = (await printUrlOf(page)).replace(/&sig=[^&]*/, '');

  const plain = async (u: string) => {
    await page.goto(u, { waitUntil: 'load' });
    await page.waitForSelector('.scroll-flow .Award', { state: 'attached' });
    await settle(page);
    return givenByFont(page, '.scroll-flow');
  };

  // No sig and nothing remembered: the body face, today's behaviour.
  await page.evaluate(() => localStorage.removeItem('sc-sig'));
  const none = await plain(url);
  expect(none.family).toContain('Averia Serif Libre');
  await expect(page.locator('body')).toHaveAttribute('data-scroll-sig', 'hand');
  expect(await page.locator('#sc-sig').inputValue()).toBe('hand');

  const explicitDefault = await plain(url + '&sig=hand');
  expect(explicitDefault).toEqual(none);

  for (const bad of ['comic', 'greatvibes%22onload%3Dx', '..%2F..%2Fetc%2Fpasswd', '%3Cscript%3E']) {
    const got = await plain(`${url}&sig=${bad}`);
    expect(got, `sig=${bad}`).toEqual(none);
    await expect(page.locator('body')).toHaveAttribute('data-scroll-sig', 'hand');
  }

  // A remembered choice applies in place when the URL says nothing, and an explicit sig= beats it.
  await page.evaluate(() => localStorage.setItem('sc-sig', 'allura'));
  await page.goto(url, { waitUntil: 'load' });
  await page.waitForSelector('.scroll-flow .Award', { state: 'attached' });
  await settle(page);
  await expect.poll(async () => (await givenByFont(page, '.scroll-flow')).family).toContain('Allura');
  await page.goto(url + '&sig=hand', { waitUntil: 'load' });
  await page.waitForSelector('.scroll-flow .Award', { state: 'attached' });
  await settle(page);
  expect((await givenByFont(page, '.scroll-flow')).family).toContain('Averia Serif Libre');
  await page.evaluate(() => localStorage.removeItem('sc-sig'));
});
