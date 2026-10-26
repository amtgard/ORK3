import { test, expect } from '@playwright/test';
import { loadCorpus, renderFixtures, serveFixtures, openFixture, FixtureSpec } from '../lib/fixture';
import { BLOCK_SEL } from '../lib/isolate';

// The markup and CSS contract of the typeset engine's top half (spec sections 8.1, 8.2
// and 6.7), checked on rendered certificates. It needs the app (the fixture renderer runs
// in its container) and no login. Nothing here asks the engine to do anything: these are
// the hooks and rules it will rely on, and a render with no script at all.

const CORPUS = 'review-2026-10-25';
const spec = (id: string): FixtureSpec => loadCorpus(CORPUS).find(c => c.id === id)!;

test('centered rules apply when forced: the swashes go, the rule pair shows', async ({ page }) => {
  const c22 = spec('c22');
  await serveFixtures(page, renderFixtures([c22], 'markup-centered'));
  // The stylesheet's half of the contract is tested with no script on the page. With the
  // engine running, data-sc-comp is the engine's output and this test would be forcing an
  // attribute it owns (the engine may rewrite it at any fit); with no script, the attribute
  // is the one the server printed and the only thing that changes it is this test.
  await page.route(/\.js(\?|$)/, route => route.abort());
  await openFixture(page, c22);

  const section = page.locator('section.IssuingTextSection');
  await expect(section).toHaveAttribute('data-sc-comp', 'classic');
  // Classic: the two swashes show, the rules do not.
  await expect(page.locator('#RightTextFlourish')).toBeVisible();
  await expect(page.locator('#BottomTextFlourish')).toBeVisible();
  await expect(page.locator('.ScRule--l')).toBeHidden();
  await expect(page.locator('.ScRule--r')).toBeHidden();

  await section.evaluate(el => el.setAttribute('data-sc-comp', 'centered'));
  const display = (sel: string) => page.locator(sel).evaluate(el => getComputedStyle(el).display);
  expect(await display('#RightTextFlourish')).toBe('none');
  expect(await display('#BottomTextFlourish')).toBe('none');
  // The versal's own swash stays: Centered keeps "Amtgard" ornamented exactly as Classic does.
  expect(await display('#TopTextFlourish')).not.toBe('none');
  await expect(page.locator('.ScRule--l')).toBeVisible();
  await expect(page.locator('.ScRule--r')).toBeVisible();

  // The lead and the place centre; the lead becomes a block so it can.
  const css = (sel: string, prop: string) => page.locator(sel).evaluate((el, p) => getComputedStyle(el).getPropertyValue(p), prop);
  expect(await css('#IssuerTitle', 'display')).toBe('block');
  expect(await css('.ScLead', 'text-align')).toBe('center');
  expect(await css('.ScPlace', 'text-align')).toBe('center');
  expect(await css('.ScPlace', 'margin-left')).toBe('0px');
  expect(await css('.ScPlace', 'padding-right')).toBe('0px');
});

test('with no script at all the certificate still renders and every sweep block still matches', async ({ page }) => {
  const c22 = spec('c22');
  await serveFixtures(page, renderFixtures([c22], 'markup-nojs'));
  // Aborts every script, the engine and the autofit alike: what a blocked-script
  // print gets. The fixture documents are .html, so they are unaffected.
  await page.route(/\.js(\?|$)/, route => route.abort());
  await openFixture(page, c22);

  for (const sel of BLOCK_SEL.split(',')) {
    expect(await page.locator(sel).count(), sel).toBeGreaterThanOrEqual(1);
  }
  await expect(page.locator('#IssuerTitle')).toHaveText('and the Barony of');
  await expect(page.locator('[data-scroll-text="issuerName"]')).toHaveText('Wickerwood');
  // Nothing wrote a size or a line: this is the first guess the server printed.
  expect(await page.locator('.sc-line').count()).toBe(0);
  expect(await page.locator('[data-sc-size]').count()).toBe(0);
  const px = (sel: string) => page.locator(sel).first().evaluate(el => parseFloat(getComputedStyle(el).fontSize));
  expect(await px('.Order')).toBeGreaterThan(0);
  // Place: never above 1.3 root em. Award: never above 0.85 of the name's size.
  const root = await px('.Award');
  expect(await px('[data-scroll-text="issuerName"]')).toBeLessThanOrEqual(1.3 * root + 0.5);
  expect(await px('.Order')).toBeLessThanOrEqual(0.85 * (await px('.AwardeeName')) + 0.5);
});

test('the rhythm: the top stack is spaced 0.25 / 0.45 / 0.20 / 0.20 root em (spec 6.7)', async ({ page }) => {
  // issuer -> "presents", "presents" -> name, name -> details, details -> award line. Read as
  // painted gaps between boxes, in frame px.
  const gaps = (p: typeof page) => p.evaluate(() => {
    const award = document.querySelector('.Award') as HTMLElement;
    const k = award.getBoundingClientRect().width / award.offsetWidth;
    const box = (sel: string) => {
      const r = (document.querySelector(sel) as HTMLElement).getBoundingClientRect();
      return { top: r.top / k, bottom: r.bottom / k };
    };
    const place = box('.ScPlace');
    const verb = box('.IssuingVerb');
    const name = box('.AwardeeName');
    const details = box('.AwardDetails');
    const order = box('section.Order');
    return {
      root: parseFloat(getComputedStyle(award).fontSize),
      gaps: [verb.top - place.bottom, name.top - verb.bottom, details.top - name.bottom, order.top - details.bottom],
    };
  });
  const want = [0.25, 0.45, 0.20, 0.20];
  const TOL = 0.02;   // root em: two frame px at the 100px root

  // With no script the gaps are the stylesheet's alone, so all four are the spec's.
  const c10 = spec('c10');
  await serveFixtures(page, renderFixtures([c10], 'markup-rhythm'));
  await page.route(/\.js(\?|$)/, route => route.abort());
  await openFixture(page, c10);
  const bare = await gaps(page);
  bare.gaps.forEach((g, i) => {
    expect(Math.abs(g / bare.root - want[i]), `no script, gap ${i}: ${(g / bare.root).toFixed(3)} root em, wanted ${want[i]}`).toBeLessThanOrEqual(TOL);
  });

  // With the engine in charge the type inside the boxes changes size but the gaps between the
  // boxes must not move. The first gap is left out: in Centered the engine adds room above
  // "presents" for the rule pair, on purpose.
  await page.unroute(/\.js(\?|$)/);
  await openFixture(page, c10);
  const fitted = await gaps(page);
  fitted.gaps.slice(1).forEach((g, i) => {
    expect(Math.abs(g / fitted.root - want[i + 1]), `engine, gap ${i + 1}: ${(g / fitted.root).toFixed(3)} root em, wanted ${want[i + 1]}`).toBeLessThanOrEqual(TOL);
  });
});

test('the elastic spacer sits between the award and "for", within its caps', async ({ page }) => {
  const c10 = spec('c10');
  await serveFixtures(page, renderFixtures([c10], 'markup-spacer'));
  await openFixture(page, c10);

  const m = await page.evaluate(() => {
    const award = document.querySelector('.Award') as HTMLElement;
    const sp = document.querySelector('.ScSpacer') as HTMLElement;
    const order = document.querySelector('section.Order') as HTMLElement;
    return {
      root: parseFloat(getComputedStyle(award).fontSize),
      h: sp.offsetHeight,
      prev: sp.previousElementSibling === order,
      next: sp.nextElementSibling?.textContent?.trim(),
      ariaHidden: sp.getAttribute('aria-hidden'),
    };
  });
  expect(m.prev).toBe(true);
  expect(m.next).toBe('for');
  expect(m.ariaHidden).toBe('true');
  expect(m.h).toBeGreaterThanOrEqual(0.3 * m.root - 0.5);
  expect(m.h).toBeLessThanOrEqual(1.2 * m.root + 0.5);
});

test('the top stack is rigid: only the spacer and the bottom spacers grow', async ({ page }) => {
  const c22 = spec('c22');
  await serveFixtures(page, renderFixtures([c22], 'markup-rigid'));
  await openFixture(page, c22);
  for (const sel of ['section.IssuingTextSection', 'section.ScName', 'section.Order']) {
    expect(await page.locator(sel).evaluate(el => getComputedStyle(el).flexGrow), sel).toBe('0');
  }
  expect(await page.locator('.ScSpacer').evaluate(el => getComputedStyle(el).flexGrow)).toBe('1');
});

test('tracking is declared on the role element so it scales with the size the engine writes', async ({ page }) => {
  const c22 = spec('c22');
  await serveFixtures(page, renderFixtures([c22], 'markup-track'));
  await openFixture(page, c22);

  const read = (sel: string) => page.locator(sel).first().evaluate(el => {
    const cs = getComputedStyle(el);
    return { ls: parseFloat(cs.letterSpacing), size: parseFloat(cs.fontSize), own: el.getAttribute('data-sc-role') };
  });
  for (const sel of ['[data-sc-role="name"]', '[data-sc-role="award"]']) {
    const a = await read(sel);
    expect(a.own).not.toBeNull();
    // -0.01em of ITS OWN size. Declared on an ancestor this would be a fixed px amount.
    expect(a.ls / a.size, sel).toBeCloseTo(-0.01, 3);
  }
  // And the ancestors no longer carry it (the child's own declaration is what shows).
  expect(await page.locator('.AwardeeName').evaluate(el => getComputedStyle(el).letterSpacing)).toBe('normal');
  // The lead and the place had no tracking before, and have none now.
  expect(await page.locator('[data-sc-role="lead"]').evaluate(el => getComputedStyle(el).letterSpacing)).toBe('normal');
  expect(await page.locator('[data-sc-role="place"]').evaluate(el => getComputedStyle(el).letterSpacing)).toBe('normal');

  // An em size written on the role changes the tracking with it.
  const scaled = await page.locator('[data-sc-role="name"]').evaluate(el => {
    (el as HTMLElement).style.fontSize = '2em';
    const cs = getComputedStyle(el);
    return parseFloat(cs.letterSpacing) / parseFloat(cs.fontSize);
  });
  expect(scaled).toBeCloseTo(-0.01, 3);
});

test('a .sc-line is as wide as its text, centred, and right-aligned in Classic place', async ({ page }) => {
  const c22 = spec('c22');
  await serveFixtures(page, renderFixtures([c22], 'markup-line'));
  await openFixture(page, c22);

  // Stand in for what the engine writes, in the three places it writes lines.
  await page.evaluate(() => {
    const put = (sel: string, text: string) => {
      const role = document.querySelector(sel) as HTMLElement;
      role.textContent = '';
      const line = document.createElement('span');
      line.className = 'sc-line';
      line.textContent = text;
      role.appendChild(line);
    };
    put('[data-sc-role="name"]', 'Piercer');
    put('[data-sc-role="award"]', 'Order of the Warrior');
    put('[data-sc-role="place"]', 'Wickerwood');
  });
  // Geometry in screen px from bounding rects, with the frame scale divided out only where
  // a length is compared with a CSS length. The block each line sits in is the nearest
  // block ancestor: the name's span is inline, so its paragraph is the box that centres it.
  const m = await page.evaluate(() => {
    const award = document.querySelector('.Award') as HTMLElement;
    const K = award.getBoundingClientRect().width / award.offsetWidth;
    const info = (sel: string, boxSel: string) => {
      const line = document.querySelector(sel + ' > .sc-line') as HTMLElement;
      const box = document.querySelector(boxSel) as HTMLElement;
      const cs = getComputedStyle(line);
      const r = document.createRange();
      r.selectNodeContents(line);
      const lr = line.getBoundingClientRect();
      const br = box.getBoundingClientRect();
      const bcs = getComputedStyle(box);
      return {
        display: cs.display, ws: cs.whiteSpace, mr: cs.marginRight,
        w: line.offsetWidth, text: r.getBoundingClientRect().width / K, scroll: line.scrollWidth,
        centreOff: ((lr.left + lr.right) / 2 - (br.left + br.right) / 2) / K,
        rightOff: (br.right - parseFloat(bcs.paddingRight) * K - lr.right) / K,
      };
    };
    return {
      name: info('[data-sc-role="name"]', '.AwardeeName'),
      award: info('[data-sc-role="award"]', 'section.Order'),
      place: info('[data-sc-role="place"]', '.ScPlace'),
    };
  });
  for (const k of ['name', 'award', 'place'] as const) {
    const l = m[k];
    expect(l.display, k).toBe('block');
    expect(l.ws, k).toBe('nowrap');
    // The line box IS its text: this is what the engine's scrollWidth check reads.
    expect(Math.abs(l.w - l.text), k).toBeLessThanOrEqual(1);
    expect(l.scroll, k).toBeLessThanOrEqual(l.w + 1);
  }
  // Name and award centre in their block; the Classic place's line sits at its right edge.
  expect(Math.abs(m.name.centreOff)).toBeLessThanOrEqual(1);
  expect(Math.abs(m.award.centreOff)).toBeLessThanOrEqual(1);
  expect(m.place.mr).toBe('0px');
  expect(Math.abs(m.place.rightOff)).toBeLessThanOrEqual(1);
});

test('the render page stamps the heading on <body>, normalised', async ({ page }) => {
  const forced = { ...spec('c22'), id: 'h-centered', heading: 'centered' as const };
  const junk = { ...spec('c22'), id: 'h-junk', heading: 'sideways' as unknown as 'auto' };
  const plain = { ...spec('c22'), id: 'h-plain' };
  await serveFixtures(page, renderFixtures([forced, junk, plain], 'markup-heading'));
  const body = () => page.locator('body').getAttribute('data-sc-heading');
  await openFixture(page, forced);
  expect(await body()).toBe('centered');
  await openFixture(page, junk);
  expect(await body()).toBe('auto');
  await openFixture(page, plain);
  expect(await body()).toBe('auto');
});

test('the five flourishes carry their ink grids, and only the citation still carries a size formula', async ({ page }) => {
  const c22 = spec('c22');
  await serveFixtures(page, renderFixtures([c22], 'markup-ink'));
  await openFixture(page, c22);

  const imgs = page.locator('.Award img.Flourish');
  expect(await imgs.count()).toBe(5);
  for (const el of await imgs.all()) {
    expect(await el.getAttribute('data-sc-ink')).toMatch(/^32x32:/);
  }
  // The two rules draw the order's own bottom swash.
  const src = (sel: string) => page.locator(sel).getAttribute('src');
  expect(await src('.ScRule--l')).toBe(await src('#BottomTextFlourish'));
  expect(await src('.ScRule--r')).toBe(await src('#BottomTextFlourish'));
  // The name's and the place's sizes are the engine's, so the old length formulas are no
  // longer emitted as data; the citation's is, because scrollery-autofit.js still fits it.
  expect(await page.locator('.AwardeeName').getAttribute('data-af-len')).toBeNull();
  expect(await page.locator('[data-scroll-text="issuerName"]').getAttribute('data-af-inv')).toBeNull();
  expect(await page.locator('.Award_Reason').getAttribute('data-af-len')).not.toBeNull();
  expect(await page.locator('.Award').getAttribute('data-sc-free')).toMatch(/^\d+,\d+\|/);
});
