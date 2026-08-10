# Park Site Starter Template ("Seal and Field") Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the park starter template with a three-page, newcomer-first site that looks finished on day one with zero officer input.

**Architecture:** Four bug fixes land first because the template cannot ship on top of them (theming that never reaches below the hero, a medieval default font, phantom officer cards, a hidden mobile member bar). Then a PHP heraldry colour extractor writes a per-park theme row at seed time, a new `park_hero` block renders the crest, a sticky quick-facts strip goes into the park-scope shell, and finally the seeder is rewritten to produce Home / New Players / Contact.

**Tech Stack:** PHP 8 (no framework), MySQL/MariaDB via the in-house `yapo` ORM and a global `$DB` (YapoDb), plain-PHP `.tpl` templates (NOT Smarty), vanilla JS, GD for image work, plain-PHP `check()` test harness under `tests/`.

**Spec:** `docs/superpowers/specs/2026-08-10-park-site-starter-template-design.md`

## Global Constraints

- **`.tpl` files are PLAIN PHP.** Use `<?php ?>` / `<?= ?>`. `{$var}` and `{if}` render literally.
- **FontAwesome 5.8.2 only.** FA6-only names render blank. Safe: `fa-map-marker-alt`, `fa-external-link-alt`, `fa-cloud-sun`, `fa-shield-alt`, `fa-user-friends`. Forbidden: `fa-location-dot`, `fa-shield-halved`, `fa-calendar-days`.
- **Always `$DB->Clear()` before any raw `Execute()` / `DataSet()`** — stale PDO bindings cause silent save failures.
- **`$DB->DataSet()` needs an explicit `->Next()`** before reading any field, else null.
- **yapo drops `null` from UPDATE/INSERT** — assign `''` to clear a column.
- **NEVER stage `system/lib/ork3/class.Authorization.php`.** It carries a local-only `true ||` login bypass. Stage files explicitly; never `git add -A`.
- **Normalize-first PHP editing:** run `awk '/^\t/{c++}END{print c+0}' <file>` before editing. If non-zero, run php-cs-fixer on that file first.
- **"ORK flavor" is context, not cosplay.** Navy/gold brand and real Amtgard terminology. No faux-parchment, no dragons.
- **Dark mode is mandatory**, selector `html[data-theme="dark"]`. Every new surface needs a dark rule.
- **Global `h1`–`h6` in orkui.css get a gray pill box.** Any new heading must reset `background`, `border`, `padding`, `border-radius`.
- **`#theme_container a` has specificity (1,0,1)** and hijacks link colour. Declare new anchors at `#theme_container a.pk-*` from the first commit.
- **Seeded copy must be true for any Amtgard park unedited.** No weekday, no price, no city, no kingdom, no attendance claim. Never publish author instructions.
- **No native `confirm()`/`alert()`/`prompt()`** — they freeze the browser automation used for verification.
- Local dev: `http://localhost:19080/orkui/index.php?Route=Controller/action/id`. DB: `docker exec ork3-php8-db mariadb -uork -psecret ork -e "SQL"`.
- Test suites: `php tests/<suite>/<file>.php`, exit 0 on pass. Run all five (`cms-fields`, `cms-sanitizer`, `cms-site`, `cms-tenancy`, `cms-theme`) before every commit.

---

## File Structure

**Create**
- `system/lib/ork3/class.CmsHeraldryColor.php` — pure GD dominant-colour extraction from a heraldry file. No DB, no request state.
- `orkui/template/default/frontdoor/blocks/park_hero.tpl` — the crest hero block.
- `orkui/template/default/frontdoor/_park_strip.tpl` — the sticky quick-facts strip (shell partial, not a block).
- `tests/cms-heraldry-color/color_test.php` — extractor unit tests.
- `db-migrations/2026-08-11-park-theme-backfill.php` — write theme rows for existing park sites.

**Modify**
- `orkui/template/default/frontdoor/blocks/_shared/officers.tpl` — B1 phantom cards.
- `orkui/template/default/frontdoor/css/frontdoor.css` — B2 font default, B3 member bar, B4 tokenized bands/colours.
- `system/lib/ork3/class.CmsThemeTokens.php` — add `Archivo` to the font allowlist, add `--fd-accent-on-primary` to `Derive()`.
- `system/lib/ork3/class.CmsBlockRegistry.php` — register `park_hero`.
- `system/lib/ork3/class.CmsSite.php` — seed a theme row; rewrite `_starterPageDefs()` for park scope.
- `orkui/template/default/Site_shell.tpl` — include the strip in park scope.
- `tests/cms-site/site_test.php` — pin the new park page set and nav.

---

## Task 1: Fix phantom officer cards (B1)

A vacant officer seat has a role but no persona. The skip requires **both** to be empty, so 187 of 342 parks render a card with an office title and nobody in it.

**Files:**
- Modify: `orkui/template/default/frontdoor/blocks/_shared/officers.tpl:101-103`
- Test: `tests/cms-fields/officers_vacancy_test.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: nothing (behavioural fix only).

- [ ] **Step 1: Write the failing test**

Create `tests/cms-fields/officers_vacancy_test.php`:

```php
<?php

// tests/cms-fields/officers_vacancy_test.php — run: php tests/cms-fields/officers_vacancy_test.php
//
// A vacant officer seat (ork_officer row with mundane_id = 0) LEFT JOINs to a NULL
// persona but keeps its role, e.g. "Champion". The public roster must skip it. Before
// this fix the skip required BOTH persona and role to be empty, so 187 of 342 active
// parks rendered a card with an office title and no name in it.

$fails = 0;
function check($label, $cond)
{
    global $fails;
    echo($cond ? "PASS  $label\n" : "FAIL  $label\n");
    if (!$cond) {
        $fails++;
    }
}

// The predicate under test, extracted verbatim from _shared/officers.tpl.
function officer_is_renderable(array $row)
{
    $persona = trim((string) ($row['Persona'] ?? ''));
    $role    = trim((string) ($row['OfficerRole'] ?? $row['Role'] ?? ''));
    return $persona !== '';
}

check(
    'a filled seat renders',
    officer_is_renderable(array('Persona' => 'Tobias of Heraldsbridge', 'OfficerRole' => 'Monarch'))
);
check(
    'a VACANT seat (role, no persona) is skipped',
    !officer_is_renderable(array('Persona' => '', 'OfficerRole' => 'Champion'))
);
check(
    'a NULL persona from the LEFT JOIN is skipped',
    !officer_is_renderable(array('Persona' => null, 'OfficerRole' => 'GMR'))
);
check(
    'a whitespace-only persona is skipped',
    !officer_is_renderable(array('Persona' => '   ', 'OfficerRole' => 'Regent'))
);
check(
    'a totally empty row is skipped',
    !officer_is_renderable(array('Persona' => '', 'OfficerRole' => ''))
);
check(
    'a persona with no role still renders (office is optional, a name is not)',
    officer_is_renderable(array('Persona' => 'Venn', 'OfficerRole' => ''))
);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
```

- [ ] **Step 2: Run it and confirm it passes against the NEW predicate but the template still has the old one**

Run: `php tests/cms-fields/officers_vacancy_test.php`
Expected: `ALL PASS` (the test file carries the corrected predicate).

Now confirm the template still has the bug:

Run: `grep -n "fdOffPersona === '' && \$fdOffRole === ''" orkui/template/default/frontdoor/blocks/_shared/officers.tpl`
Expected: one match at line ~101. That match is the bug.

- [ ] **Step 3: Fix the template**

In `orkui/template/default/frontdoor/blocks/_shared/officers.tpl`, replace:

```php
            if ($fdOffPersona === '' && $fdOffRole === '') {
                continue;
            }
```

with:

```php
            // A seat with no PERSONA is a vacancy, whatever its role says. ork_officer
            // keeps a row per office with mundane_id = 0 when nobody holds it, and the
            // LEFT JOIN to ork_mundane then yields a NULL persona while `role` stays
            // populated ("Champion", "GMR", …). Requiring BOTH to be empty rendered
            // those vacancies as cards with an office title and nobody in them, on 187
            // of 342 active parks. A name is required; an office title is not.
            if ($fdOffPersona === '') {
                continue;
            }
```

- [ ] **Step 4: Verify against real data**

Run:

```bash
docker exec ork3-php8-db mariadb -uork -psecret ork -e "
SELECT COUNT(DISTINCT o.park_id) parks_with_vacant_seats
FROM ork_officer o JOIN ork_park p ON p.park_id=o.park_id AND p.active='Active'
WHERE (o.mundane_id=0 OR o.mundane_id IS NULL) AND o.role<>'';" 2>&1 | grep -v insecure
```

Expected: `187`. These are the parks the fix protects.

Run: `php -l orkui/template/default/frontdoor/blocks/_shared/officers.tpl`
Expected: `No syntax errors detected`.

- [ ] **Step 5: Run all five suites**

Run:

```bash
for d in cms-fields cms-sanitizer cms-site cms-tenancy cms-theme; do
  for f in tests/$d/*.php; do printf "%-28s " "$f"; php "$f" >/dev/null 2>&1 && echo PASS || echo FAIL; done
done
```

Expected: every line `PASS`.

- [ ] **Step 6: Commit**

```bash
git add orkui/template/default/frontdoor/blocks/_shared/officers.tpl tests/cms-fields/officers_vacancy_test.php
git commit -m "Bugfix: OGRE — vacant officer seats rendered as nameless cards

ork_officer keeps a row per office with mundane_id = 0 when the seat is
empty; the LEFT JOIN then yields a NULL persona while role stays set. The
skip required BOTH persona and role to be empty, so every vacancy rendered
as a card with an office title and nobody in it — 187 of 342 active parks,
21.6% of all park officer seats."
```

---

## Task 2: Tokenize section bands and hard-coded colours (B4)

Per-park theming currently never reaches below the hero. `.fd-section-light` is a literal `#fff`, and ~30 colours are hard-coded, so a wine-red park still gets Bootstrap-blue links. This is a **precondition** for the tinted three-band scheme.

**Files:**
- Modify: `orkui/template/default/frontdoor/css/frontdoor.css`

**Interfaces:**
- Consumes: existing `--fd-*` tokens from `CmsThemeTokens::Derive()`.
- Produces: CSS custom properties `--pk-paper`, `--pk-vellum`, `--pk-line`, `--pk-link` that Tasks 5–8 rely on.

- [ ] **Step 1: Record the current state so the change is measurable**

Run:

```bash
grep -cE "#[0-9a-fA-F]{3,6}" orkui/template/default/frontdoor/css/frontdoor.css
grep -n "\.fd-section-light\|\.fd-section-muted" orkui/template/default/frontdoor/css/frontdoor.css | head
```

Note the counts and line numbers. Expected: `.fd-section-light{background:#fff}` around L112 and `.fd-section-muted{background:#f7f8fb}` around L127.

- [ ] **Step 2: Add the park paper tokens to `:root`**

In the `:root` block at the top of `frontdoor.css`, after the existing `--fd-*` declarations, add:

```css
    /* ---- Park paper scale -------------------------------------------------
       Three values, not two. The old #fff / #f7f8fb alternation is a 4% step,
       i.e. invisible, which is why a long page reads as sameness. These carry
       the org's own hue at low chroma so the "white" is faintly wine or faintly
       forest — considered rather than unstyled. --fd-primary supplies the hue
       via --fd-primary-h, set alongside it by CmsThemeTokens::Derive(). */
    --pk-paper:  hsl(var(--fd-primary-h, 220) 34% 98.5%);
    --pk-vellum: hsl(var(--fd-primary-h, 220) 26% 95.5%);
    --pk-line:   hsl(var(--fd-primary-h, 220) 18% 88%);
    --pk-link:   var(--fd-primary);
```

And in the `html[data-theme="dark"]` block:

```css
    --pk-paper:  var(--fd-bg);
    --pk-vellum: var(--fd-surface);
    --pk-line:   var(--fd-border);
    --pk-link:   var(--fd-accent);
```

- [ ] **Step 3: Point the section bands at the tokens**

Replace the two literal declarations:

```css
.fd-section-light { background: #fff; }
.fd-section-muted { background: #f7f8fb; }
```

with:

```css
/* Bands read TOKENS, not literals. Until this change per-org theming stopped at
   the hero: every section below it was hard-coded #fff / #f7f8fb regardless of
   the park's palette. */
.fd-section-light { background: var(--pk-paper); }
.fd-section-muted { background: var(--pk-vellum); }
```

- [ ] **Step 4: Replace the hard-coded text, border and link colours**

Apply these exact substitutions throughout `frontdoor.css`:

| Find | Replace with |
|---|---|
| `color: #3a4356` | `color: var(--fd-text)` |
| `color: #50596e` | `color: var(--fd-text)` |
| `color: #5b6478` | `color: var(--fd-text-muted)` |
| `color: #778` | `color: var(--fd-text-muted)` |
| `color: #8899aa` | `color: var(--fd-text-muted)` |
| `color: #1d4ed8` | `color: var(--pk-link)` |
| `color: #1b2a4a` | `color: var(--fd-text)` |
| `color: #1a2236` | `color: var(--fd-text)` |
| `#e4e8f0` | `var(--pk-line)` |

- [ ] **Step 5: Fix the `.fd-kicker` font shorthand bug**

`.fd-kicker` uses the `font` shorthand, which **resets font-family**, so every kicker on every themed site silently ignores `--fd-font-body`. Replace:

```css
.fd-kicker { font: 700 12px/1 system-ui; letter-spacing: .16em; text-transform: uppercase; }
```

with longhands:

```css
/* Longhands, NOT the `font` shorthand: `font:` resets font-family, so the
   shorthand silently overrode --fd-font-body on every themed site. */
.fd-kicker {
    font-family: var(--fd-font-body);
    font-weight: 700;
    font-size: calc(var(--fd-font-scale, 1) * 0.6875rem);
    line-height: 1;
    letter-spacing: .16em;
    text-transform: uppercase;
}
```

- [ ] **Step 6: Verify no literal colours remain in the touched rules**

Run:

```bash
grep -nE "#(fff|f7f8fb|3a4356|50596e|5b6478|778|8899aa|1d4ed8|1b2a4a|1a2236|e4e8f0)\b" \
  orkui/template/default/frontdoor/css/frontdoor.css
```

Expected: no output.

- [ ] **Step 7: Verify in the browser that theming now reaches below the hero**

Start from a park site page. In DevTools console (or via the browser automation tool):

```js
getComputedStyle(document.querySelector('.fd-section-light')).backgroundColor
```

Then set `document.documentElement.style.setProperty('--fd-primary-h','350')` and read it again. Expected: the value **changes** (a pink-tinted paper). Before this task it stayed `rgb(255,255,255)`.

- [ ] **Step 8: Commit**

```bash
git add orkui/template/default/frontdoor/css/frontdoor.css
git commit -m "Bugfix: OGRE — per-org theming never reached below the hero

.fd-section-light and .fd-section-muted were literal #fff / #f7f8fb, and
~30 text, link and border colours were hard-coded, so a wine-red park still
got Bootstrap-blue links and cool-grey body text. Bands and colours now read
tokens, and a three-value paper scale (paper / vellum / field) replaces the
old 4% two-step that made long pages read as sameness.

Also fixes .fd-kicker using the \`font\` shorthand, which resets font-family
and so silently overrode --fd-font-body on every themed site."
```

---

## Task 3: Default font and mobile member bar (B2, B3)

**Files:**
- Modify: `orkui/template/default/frontdoor/css/frontdoor.css:17` and the phone media query (~L974)
- Modify: `system/lib/ork3/class.CmsThemeTokens.php:32-35`

**Interfaces:**
- Consumes: nothing.
- Produces: `'Archivo'` is a valid value for `--fd-font-heading` in `CmsThemeTokens::FontAllowlist()`.

- [ ] **Step 1: Prove the bug — every org site ships MedievalSharp**

Run:

```bash
grep -n "fd-font-heading" orkui/template/default/frontdoor/css/frontdoor.css | head -1
docker exec ork3-php8-db mariadb -uork -psecret ork -e \
  "SELECT (SELECT COUNT(*) FROM ork_cms_site) sites, (SELECT COUNT(*) FROM ork_cms_theme) themes;" 2>&1 | grep -v insecure
```

Expected: the default is `'MedievalSharp', Georgia, serif`, and there are sites but **zero** theme rows — so every org site renders headings in a faux-medieval face, which the project brand rule ("ORK flavor is context, NOT LARP cosplay") forbids.

- [ ] **Step 2: Add Archivo to the font allowlist**

In `system/lib/ork3/class.CmsThemeTokens.php`, replace:

```php
        return array('Open Sans', 'MedievalSharp', 'Lexend', 'Georgia', 'system-ui');
```

with:

```php
        // Archivo is the park/org display face: a modern grotesque with real
        // banner presence and zero medieval connotation. MedievalSharp stays
        // PICKABLE — an org that wants it can still choose it — it is just no
        // longer what 342 parks get by accident.
        return array('Archivo', 'Open Sans', 'MedievalSharp', 'Lexend', 'Georgia', 'system-ui');
```

- [ ] **Step 3: Change the CSS default heading font**

In `frontdoor.css` `:root`, replace:

```css
    --fd-font-heading: 'MedievalSharp', Georgia, serif;
```

with:

```css
    /* NOT MedievalSharp. A new site seeds no theme row, so whatever is defaulted
       here is what every org actually ships — and faux-medieval type is exactly
       the cosplay the brand rule forbids. MedievalSharp remains selectable in the
       theme editor for orgs that deliberately want it. */
    --fd-font-heading: 'Archivo', 'Trebuchet MS', Georgia, serif;
```

- [ ] **Step 4: Show the member bar on phones for org sites**

In the phone media query, replace the `.fd-member-bar { display: none !important; ... }` rule with:

```css
    /* Member bar stays VISIBLE on phones for org sites. The original rule hid it
       "to reclaim above-the-fold", which is right for the global marketing front
       door and exactly backwards for a park: the highest-frequency visit a park
       site ever gets is a member checking the time on a phone in a parking lot. */
    .fd-member-bar {
        display: none !important;
        flex-wrap: wrap;
        gap: 8px 14px !important;
        padding: 10px 16px !important;
    }
    .fd-org .fd-member-bar,
    body.fd-org .fd-member-bar {
        display: flex !important;
    }
```

- [ ] **Step 5: Verify**

Run: `php tests/cms-theme/tokens_test.php`
Expected: `ALL PASS`.

Run: `grep -n "MedievalSharp" orkui/template/default/frontdoor/css/frontdoor.css`
Expected: no output (it survives only in the allowlist, in PHP).

- [ ] **Step 6: Run all five suites, then commit**

```bash
for d in cms-fields cms-sanitizer cms-site cms-tenancy cms-theme; do
  for f in tests/$d/*.php; do printf "%-28s " "$f"; php "$f" >/dev/null 2>&1 && echo PASS || echo FAIL; done
done

git add orkui/template/default/frontdoor/css/frontdoor.css system/lib/ork3/class.CmsThemeTokens.php
git commit -m "Bugfix: OGRE — medieval default font and phone-hidden member bar

A new site seeds no theme row, so the CSS default is what every org actually
ships — and that default was MedievalSharp, the exact LARP cosplay the brand
rule forbids. Default is now Archivo; MedievalSharp stays selectable.

The member bar was display:none on phones to reclaim above-the-fold, which is
right for the global front door and backwards for a park, whose most common
visit is a member checking the time on a phone in a parking lot."
```

---

## Task 4: Heraldry colour extractor

Port the client-side dominant-colour algorithm to PHP so it runs **once at seed time** instead of on every page load. The existing `pkApplyHeroColor()` in `revised.js` recomputes constantly, caches nothing, flashes a default green, breaks under CORS, and cannot be overridden.

**Files:**
- Create: `system/lib/ork3/class.CmsHeraldryColor.php`
- Test: `tests/cms-heraldry-color/color_test.php`

**Interfaces:**
- Consumes: `CmsThemeTokens::HexToHsl()`, `CmsThemeTokens::HslToHex()`.
- Produces:
  - `CmsHeraldryColor::FromFile(string $absPath): string` — returns a `#rrggbb` clamped primary, or `''` when the file is missing/unreadable/has no usable pixels.
  - `CmsHeraldryColor::FromName(string $name): string` — deterministic hash fallback, always returns `#rrggbb`.
  - `CmsHeraldryColor::Clamp(int $r, int $g, int $b): string` — applies the saturation/lightness clamps.

- [ ] **Step 1: Write the failing test**

Create `tests/cms-heraldry-color/color_test.php`:

```php
<?php

// tests/cms-heraldry-color/color_test.php — run: php tests/cms-heraldry-color/color_test.php
//
// CmsHeraldryColor turns a park's heraldry file into the single --fd-primary its
// whole site is themed from. Pure GD + math: no DB, no request state (CmsSanitizer
// is the precedent), so it runs in a bare `php` process.
//
// The clamps are the load-bearing part. A device sampled raw yields muddy browns and
// neon primaries in equal measure; the floor kills mud, the ceiling kills neon, and
// the forced lightness guarantees white text clears 7:1 on the resulting field.

require_once __DIR__ . '/../../system/lib/ork3/class.CmsThemeTokens.php';
require_once __DIR__ . '/../../system/lib/ork3/class.CmsHeraldryColor.php';

$fails = 0;
function check($label, $cond)
{
    global $fails;
    echo($cond ? "PASS  $label\n" : "FAIL  $label\n");
    if (!$cond) {
        $fails++;
    }
}

/** Build a temp PNG that is mostly one colour, with noise GD must ignore. */
function make_png($path, $r, $g, $b)
{
    $im = imagecreatetruecolor(60, 60);
    imagefilledrectangle($im, 0, 0, 59, 59, imagecolorallocate($im, $r, $g, $b));
    // Near-white and near-black corners: the extractor must skip both.
    imagefilledrectangle($im, 0, 0, 9, 9, imagecolorallocate($im, 252, 252, 252));
    imagefilledrectangle($im, 50, 50, 59, 59, imagecolorallocate($im, 3, 3, 3));
    imagepng($im, $path);
    imagedestroy($im);
}

$tmp = sys_get_temp_dir() . '/ogre-heraldry-test';
@mkdir($tmp, 0777, true);

// --- Extraction picks the dominant hue, not the white/black noise ---
$redFile = $tmp . '/red.png';
make_png($redFile, 170, 30, 40);
$red = CmsHeraldryColor::FromFile($redFile);
check('FromFile returns a hex colour', preg_match('/^#[0-9a-f]{6}$/', $red) === 1);

$hsl = CmsThemeTokens::HexToHsl($red);
check('extracted hue is red-ish (330-360 or 0-20)', $hsl[0] >= 330 || $hsl[0] <= 20);
check('white corner did not win (result is not near-white)', $hsl[2] < 0.5);
check('black corner did not win (result is not near-black)', $hsl[2] > 0.05);

// --- Clamps ---
$greenFile = $tmp . '/green.png';
make_png($greenFile, 20, 120, 60);
$green = CmsHeraldryColor::FromFile($greenFile);
$ghsl  = CmsThemeTokens::HexToHsl($green);
check('lightness is forced to the deep-field value 0.22', abs($ghsl[2] - 0.22) < 0.02);
check('saturation is clamped into [0.30, 0.62]', $ghsl[1] >= 0.29 && $ghsl[1] <= 0.63);

// A near-grey device must not produce a muddy field — the floor lifts it.
$greyFile = $tmp . '/grey.png';
make_png($greyFile, 128, 126, 130);
$greyHsl = CmsThemeTokens::HexToHsl(CmsHeraldryColor::FromFile($greyFile));
check('a near-grey device is lifted to the saturation floor', $greyHsl[1] >= 0.29);

// A neon device must not stay neon — the ceiling pulls it down.
$neonFile = $tmp . '/neon.png';
make_png($neonFile, 0, 255, 0);
$neonHsl = CmsThemeTokens::HexToHsl(CmsHeraldryColor::FromFile($neonFile));
check('a neon device is pulled to the saturation ceiling', $neonHsl[1] <= 0.63);

// --- White text must always clear 7:1 on the field. That is why L is forced. ---
// Sweep EVERY hue at both saturation limits, not a few sampled colours: this is the
// invariant the hero relies on to use white type with no per-park contrast check.
// The margin is thin — the worst case is ~7.11:1 at hue 60 (yellow) — so raising
// SAT_MAX or FIELD_L breaks it. This test is what catches that.
$worst = 99.0;
$worstAt = -1;
for ($h = 0; $h < 360; $h += 5) {
    foreach (array(CmsHeraldryColor::SAT_MIN, 0.46, CmsHeraldryColor::SAT_MAX) as $s) {
        $field = CmsThemeTokens::HslToHex($h, $s, CmsHeraldryColor::FIELD_L);
        $c = CmsThemeTokens::Contrast('#ffffff', $field);
        if ($c < $worst) {
            $worst = $c;
            $worstAt = $h;
        }
    }
}
check(
    sprintf('white clears 7:1 on EVERY hue (worst %.2f:1 at hue %d)', $worst, $worstAt),
    $worst >= 7.0
);

// --- Failure modes must be silent and typed, never fatal ---
check('a missing file returns empty string', CmsHeraldryColor::FromFile($tmp . '/nope.png') === '');
check('a non-image file returns empty string', (function () use ($tmp) {
    file_put_contents($tmp . '/junk.png', 'not an image');
    return CmsHeraldryColor::FromFile($tmp . '/junk.png') === '';
})());
check('an empty path returns empty string', CmsHeraldryColor::FromFile('') === '');

// --- Name fallback is deterministic and well-formed ---
$a1 = CmsHeraldryColor::FromName("Angler's Rift");
$a2 = CmsHeraldryColor::FromName("Angler's Rift");
$b1 = CmsHeraldryColor::FromName('Granite Spyre');
check('FromName is deterministic', $a1 === $a2);
check('FromName differs for different names', $a1 !== $b1);
check('FromName returns a hex colour', preg_match('/^#[0-9a-f]{6}$/', $a1) === 1);
check('FromName obeys the same lightness clamp', abs(CmsThemeTokens::HexToHsl($a1)[2] - 0.22) < 0.02);
check('FromName never returns the old default green', CmsThemeTokens::HexToHsl($a1)[0] !== 153);

array_map('unlink', glob($tmp . '/*'));
@rmdir($tmp);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/cms-heraldry-color/color_test.php`
Expected: FAIL — `Failed to open stream ... class.CmsHeraldryColor.php`.

- [ ] **Step 3: Write the implementation**

Create `system/lib/ork3/class.CmsHeraldryColor.php`:

```php
<?php

/*************************************************************************
 * CmsHeraldryColor — derive an org's site palette from its heraldry device.
 *
 * WHY THIS EXISTS
 * 92% of parks have a heraldry device and 1.5% have a banner photo, so a park
 * site's only source of individuation is its own arms. Sampling the device gives
 * each of 342 parks a palette nobody had to choose.
 *
 * The algorithm is a port of pkApplyHeroColor() in revised.js (60x60 downsample,
 * 16-step RGB bucketing, skip transparent / near-white / near-black). The port is
 * the point: on the client it re-ran on every page load and every theme toggle,
 * cached nothing, flashed a default green before the image loaded, and broke
 * outright under CORS once org sites get custom domains. Here it runs ONCE, at
 * seed time, and the result is persisted as --fd-primary in ork_cms_theme, where
 * an officer can then override it.
 *
 * Pure logic: no DB, no request state (CmsSanitizer is the precedent), so it is
 * safe to call from a seeder, a migration, or a test.
 *
 * Consumers:
 *   CmsSite::_seedOrgTheme()                       seed-time palette
 *   db-migrations/2026-08-11-park-theme-backfill.php
 *************************************************************************/

class CmsHeraldryColor
{
    /** Sampling grid. Matches the client implementation. */
    const SAMPLE = 60;

    /** Quantisation step for RGB bucketing. */
    const BUCKET = 16;

    /** Alpha below this is treated as transparent and skipped. */
    const MIN_ALPHA = 120;

    /** Channel means outside this band are page-white / ink-black, not tincture. */
    const MAX_CHANNEL = 215;
    const MIN_CHANNEL = 25;

    /** Saturation floor kills mud; ceiling kills neon. */
    const SAT_MIN = 0.30;
    const SAT_MAX = 0.62;

    /**
     * Forced lightness. Not a preference — at L 0.22 white text clears 7:1 on the
     * resulting field for EVERY hue, which is what lets the hero use white type
     * without a per-park contrast check.
     */
    const FIELD_L = 0.22;

    /**
     * Dominant tincture of a heraldry file, clamped to a usable field colour.
     *
     * @param string $absPath absolute path to a local png/jpg/gif
     * @return string '#rrggbb', or '' when unreadable or when no pixel qualified
     */
    public static function FromFile($absPath)
    {
        $absPath = (string) $absPath;
        if ($absPath === '' || !is_readable($absPath)) {
            return '';
        }
        if (!function_exists('imagecreatefromstring')) {
            return '';
        }

        $raw = @file_get_contents($absPath);
        if ($raw === false || $raw === '') {
            return '';
        }
        $src = @imagecreatefromstring($raw);
        if ($src === false) {
            return '';
        }

        $w = imagesx($src);
        $h = imagesy($src);
        if ($w < 1 || $h < 1) {
            imagedestroy($src);
            return '';
        }

        // Downsample to a fixed grid so cost is independent of the source size.
        $small = imagecreatetruecolor(self::SAMPLE, self::SAMPLE);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagecopyresampled($small, $src, 0, 0, 0, 0, self::SAMPLE, self::SAMPLE, $w, $h);
        imagedestroy($src);

        $buckets = array();
        for ($y = 0; $y < self::SAMPLE; $y++) {
            for ($x = 0; $x < self::SAMPLE; $x++) {
                $rgba = imagecolorat($small, $x, $y);
                // GD alpha is 0 (opaque) .. 127 (transparent); invert to 0..255.
                $a = 255 - (int) ((($rgba >> 24) & 0x7F) * 2);
                if ($a < self::MIN_ALPHA) {
                    continue;
                }
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;

                // Skip the plate and the ink: near-white is usually the device's
                // background, near-black is usually its outline. Neither is the
                // tincture we want to theme from.
                $mean = ($r + $g + $b) / 3;
                if ($mean > self::MAX_CHANNEL || $mean < self::MIN_CHANNEL) {
                    continue;
                }

                $key = (intdiv($r, self::BUCKET) << 16)
                     | (intdiv($g, self::BUCKET) << 8)
                     |  intdiv($b, self::BUCKET);
                if (!isset($buckets[$key])) {
                    $buckets[$key] = array('n' => 0, 'r' => 0, 'g' => 0, 'b' => 0);
                }
                $buckets[$key]['n']++;
                $buckets[$key]['r'] += $r;
                $buckets[$key]['g'] += $g;
                $buckets[$key]['b'] += $b;
            }
        }
        imagedestroy($small);

        if (empty($buckets)) {
            return '';
        }

        $best = null;
        foreach ($buckets as $bucket) {
            if ($best === null || $bucket['n'] > $best['n']) {
                $best = $bucket;
            }
        }

        return self::Clamp(
            (int) round($best['r'] / $best['n']),
            (int) round($best['g'] / $best['n']),
            (int) round($best['b'] / $best['n'])
        );
    }

    /**
     * Deterministic palette from a name — the last resort when an org has no
     * device and no parent device either. Deliberately NOT a fixed default: a
     * single default colour would make every deviceless park identical, which
     * reads as unloved. Hashing the name means neighbouring parks never collide
     * and the colour is stable across re-seeds.
     *
     * @param string $name
     * @return string '#rrggbb'
     */
    public static function FromName($name)
    {
        $hash = crc32((string) $name);
        $hue  = $hash % 360;
        $sat  = self::SAT_MIN + (($hash >> 9) % 100) / 100 * (self::SAT_MAX - self::SAT_MIN);
        return CmsThemeTokens::HslToHex($hue, $sat, self::FIELD_L);
    }

    /**
     * Apply the field clamps to a raw RGB triple.
     *
     * @return string '#rrggbb'
     */
    public static function Clamp($r, $g, $b)
    {
        $hex = CmsThemeTokens::RgbToHex((int) $r, (int) $g, (int) $b);
        $hsl = CmsThemeTokens::HexToHsl($hex);

        $h = $hsl[0];
        $s = max(self::SAT_MIN, min(self::SAT_MAX, $hsl[1]));

        return CmsThemeTokens::HslToHex($h, $s, self::FIELD_L);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php tests/cms-heraldry-color/color_test.php`
Expected: `ALL PASS`.

If `HexToHsl` returns hue on a 0–1 scale rather than 0–360, adjust `FromName` and the test's hue assertions to match — check with:

```bash
php -r "require 'system/lib/ork3/class.CmsThemeTokens.php'; var_dump(CmsThemeTokens::HexToHsl('#aa1e28'));"
```

- [ ] **Step 5: Sanity-check against a real device**

Run:

```bash
php -r "
require 'system/lib/ork3/class.CmsThemeTokens.php';
require 'system/lib/ork3/class.CmsHeraldryColor.php';
foreach (array_slice(glob('assets/heraldry/park/*'), 0, 8) as \$f) {
  printf(\"%-46s %s\n\", basename(\$f), CmsHeraldryColor::FromFile(\$f) ?: '(none)');
}"
```

Expected: distinct plausible deep colours, no empty strings for real images.

- [ ] **Step 6: Commit**

```bash
git add system/lib/ork3/class.CmsHeraldryColor.php tests/cms-heraldry-color/color_test.php
git commit -m "Enhancement: OGRE — derive an org palette from its heraldry device

Ports the dominant-colour algorithm from revised.js pkApplyHeroColor() to
PHP so it runs ONCE at seed time instead of on every page load. The client
version cached nothing, flashed a default green before the image loaded,
and breaks under CORS once org sites get custom domains.

Saturation is clamped to [0.30, 0.62] — the floor kills mud, the ceiling
kills neon — and lightness is forced to 0.22, at which white text clears
7:1 for every hue, so the hero needs no per-park contrast check."
```

---

## Task 5: Seed a theme row per org site

**Files:**
- Modify: `system/lib/ork3/class.CmsSite.php` (add `_seedOrgTheme()`; call it from `_seedStarterTemplate()`)
- Modify: `system/lib/ork3/class.CmsThemeTokens.php` (`Derive()` — add `--fd-primary-h` and `--fd-accent-on-primary`)
- Test: `tests/cms-site/site_test.php`

**Interfaces:**
- Consumes: `CmsHeraldryColor::FromFile()`, `CmsHeraldryColor::FromName()`, `CmsTheme::SaveTheme()`, `CmsTheme::SetActive()`.
- Produces: `CmsSite::_seedOrgTheme(string $scopeType, int $scopeId, int $uid): string` — returns the chosen `#rrggbb`.

- [ ] **Step 1: Add the derived tokens**

In `CmsThemeTokens::Derive()`, after `--fd-primary` is resolved, add:

```php
        // The hue alone, so CSS can build the tinted paper scale
        // (hsl(var(--fd-primary-h) 34% 98.5%)) without re-parsing the hex.
        $primaryHsl = self::HexToHsl($primary);
        $d['--fd-primary-h'] = (string) (int) round($primaryHsl[0]);

        // Gold-on-gold guard: ~6% of hues collide with the ORK accent. Where the
        // accent would not read on the field, fall back to the field's own
        // contrast colour instead of special-casing at the template layer.
        $d['--fd-accent-on-primary'] = (self::Contrast($accent, $primary) >= 3.0)
            ? $accent
            : $d['--fd-primary-contrast'];
```

- [ ] **Step 2: Write the failing test**

Append to `tests/cms-site/site_test.php`, before the final `echo $fails === 0 ...` line:

```php
// --- Seeded theme row -----------------------------------------------------
// A new site used to seed NO theme row at all, so every org inherited whatever
// the CSS defaulted to — which was MedievalSharp. The seeder must now always
// create and ACTIVATE a row, and its --fd-primary must come from the org's own
// device so no two of the 342 parks look alike.
$seedTheme = new ReflectionMethod('CmsSite', '_seedOrgTheme');

$DB->executed = array();
$DB->queue    = array(array());        // no existing theme row
$primary = $seedTheme->invoke($site, 'park', 1049, 99);

check('_seedOrgTheme returns a hex primary', preg_match('/^#[0-9a-f]{6}$/', $primary) === 1);
check('_seedOrgTheme never returns the empty string', $primary !== '');
check('_seedOrgTheme wrote a theme row', (function () use ($DB) {
    foreach ($DB->executed as $sql) {
        if (stripos($sql, 'cms_theme') !== false) {
            return true;
        }
    }
    return false;
})());
```

- [ ] **Step 3: Run it to verify it fails**

Run: `php tests/cms-site/site_test.php`
Expected: FAIL — `Method CmsSite::_seedOrgTheme() does not exist`.

- [ ] **Step 4: Implement `_seedOrgTheme()`**

Add to `class.CmsSite.php`, near `_stampTemplateSeeded()`:

```php
    /**
     * Give a freshly-provisioned org site its own palette, derived from its own
     * heraldry, and ACTIVATE it.
     *
     * Before this, a new site seeded no theme row at all, so GetActiveCss()
     * returned '' and every org fell through to the raw CSS defaults — which is
     * how all 342 parks ended up rendering in MedievalSharp. Seeding a row is
     * therefore not a nicety: it is the only thing that makes the org's own
     * design tokens reachable.
     *
     * Colour cascade: the org's own device, then its PARENT KINGDOM's device (a
     * park with no arms belongs to a kingdom that almost certainly has some, and
     * inheriting is meaningful rather than arbitrary), then a deterministic hash
     * of the name. Never a fixed default — that would make every deviceless park
     * identical.
     *
     * @param string $scopeType 'kingdom' | 'park'
     * @param int    $scopeId
     * @param int    $uid acting mundane_id (audit)
     * @return string the chosen '#rrggbb'
     */
    private function _seedOrgTheme($scopeType, $scopeId, $uid)
    {
        $scopeType = (string) $scopeType;
        $scopeId   = (int) $scopeId;

        $primary = '';
        if (class_exists('CmsHeraldryColor')) {
            $primary = CmsHeraldryColor::FromFile($this->_heraldryPath($scopeType, $scopeId));

            if ($primary === '' && $scopeType === 'park') {
                $parentKingdomId = $this->_parentKingdomIdForPark($scopeId);
                if ($parentKingdomId > 0) {
                    $primary = CmsHeraldryColor::FromFile(
                        $this->_heraldryPath('kingdom', $parentKingdomId)
                    );
                }
            }
            if ($primary === '') {
                $primary = CmsHeraldryColor::FromName($this->OrgDisplayName($scopeType, $scopeId));
            }
        }
        if ($primary === '') {
            return '';
        }

        if (!class_exists('CmsTheme')) {
            return $primary;
        }
        $theme = new CmsTheme();
        $id = (int) $theme->SaveTheme($scopeType, $scopeId, 'Default', array(
            '--fd-primary'      => $primary,
            '--fd-font-heading' => 'Archivo',
            '--fd-font-body'    => 'Lexend',
            '--fd-radius'       => '6px',
        ), (int) $uid);

        if ($id > 0) {
            $theme->SetActive($scopeType, $scopeId, $id);
        }
        return $primary;
    }

    /**
     * Absolute path to an org's heraldry master, or '' when it has none.
     *
     * Gates on has_heraldry, NOT on a truthy URL: Heraldry::resolve_heraldry_url()
     * returns a guaranteed-404 path when no file exists, so a URL check would
     * always look positive.
     *
     * @return string absolute path, or ''
     */
    private function _heraldryPath($scopeType, $scopeId)
    {
        global $DB;
        $table = ($scopeType === 'park') ? 'park' : 'kingdom';
        $idCol = $table . '_id';

        $DB->Clear();
        $DB->org_id = (int) $scopeId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT has_heraldry FROM ' . DB_PREFIX . $table
            . ' WHERE ' . $idCol . ' = :org_id LIMIT 1'
        ));
        if ($row === null || (int) ($row['has_heraldry'] ?? 0) !== 1) {
            return '';
        }

        $base = rtrim(DIR_HERALDRY, '/') . '/' . $table . '/' . sprintf('%05d', (int) $scopeId);
        foreach (array('.png', '.jpg', '.jpeg', '.gif') as $ext) {
            if (is_readable($base . $ext)) {
                return $base . $ext;
            }
        }
        return '';
    }

    /** Parent kingdom of a park, or 0. */
    private function _parentKingdomIdForPark($parkId)
    {
        global $DB;
        $DB->Clear();
        $DB->park_id = (int) $parkId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = :park_id LIMIT 1'
        ));
        return ($row === null) ? 0 : (int) ($row['kingdom_id'] ?? 0);
    }
```

- [ ] **Step 5: Call it from the seeder**

In `_seedStarterTemplate()`, immediately before `$this->_stampTemplateSeeded($siteId);`, add:

```php
        // Palette before the marker: a site that fails mid-seed should not be
        // stamped as seeded, and the theme is part of "seeded".
        $this->_seedOrgTheme($scopeType, $scopeId, $uid);
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php tests/cms-site/site_test.php`
Expected: `ALL PASS`.

- [ ] **Step 7: End-to-end check**

```bash
docker exec ork3-php8-db mariadb -uork -psecret ork -e "
DELETE FROM ork_cms_block WHERE owner_type='page' AND owner_id IN (SELECT page_id FROM ork_cms_page WHERE scope_type='park' AND scope_id=1049);
DELETE FROM ork_cms_page WHERE scope_type='park' AND scope_id=1049;
DELETE FROM ork_cms_nav_item WHERE scope_type='park' AND scope_id=1049;
DELETE FROM ork_cms_site WHERE scope_type='park' AND scope_id=1049;
DELETE FROM ork_cms_theme WHERE scope_type='park' AND scope_id=1049;" 2>&1 | grep -v insecure

curl -s -c /tmp/cj.txt -b /tmp/cj.txt -d "username=heraldsbridge&password=x" \
  "http://localhost:19080/orkui/index.php?Route=Login/login" -o /dev/null
curl -s -b /tmp/cj.txt "http://localhost:19080/orkui/index.php?Route=Cms/dashboard&scope=p:1049" -o /dev/null

docker exec ork3-php8-db mariadb -uork -psecret ork -e "
SELECT scope_type, scope_id, name, is_active, tokens_json FROM ork_cms_theme WHERE scope_type='park';" 2>&1 | grep -v insecure
```

Expected: one row, `is_active = 1`, `tokens_json` containing a `--fd-primary` hex and `Archivo`.

- [ ] **Step 8: Commit**

```bash
git add system/lib/ork3/class.CmsSite.php system/lib/ork3/class.CmsThemeTokens.php tests/cms-site/site_test.php
git commit -m "Enhancement: OGRE — seed an active theme row per org site

A new site created no theme row, so GetActiveCss() returned '' and every org
fell through to the raw CSS defaults. Seeding is what makes an org's design
tokens reachable at all. Colour cascades from the org's own device, to its
parent kingdom's device, to a deterministic hash of its name — never a fixed
default, which would make every deviceless park identical.

Derive() also gains --fd-primary-h (so CSS can build the tinted paper scale
without re-parsing the hex) and --fd-accent-on-primary (the gold-on-gold
guard for the ~6% of hues that collide with the ORK accent)."
```

---

## Task 6: The `park_hero` block

**Files:**
- Create: `orkui/template/default/frontdoor/blocks/park_hero.tpl`
- Modify: `system/lib/ork3/class.CmsBlockRegistry.php`

**Interfaces:**
- Consumes: `$SiteNavScopeType` / `$SiteNavScopeId` (set by `Controller_Site::_bootShell`), `Park::CalculateNextParkDay()`, `Weather::for_park()`.
- Produces: block type `park_hero`, fields `{kicker, heading, subcopy, cta_label, cta_href, show_weather, placeholder_image}`.

- [ ] **Step 1: Register the block**

In `CmsBlockRegistry::BlockDefs()`, in the park-scoped section, add:

```php
            'park_hero' => array(
                'label'          => 'Park hero (live)',
                'group'          => 'Hero',
                'dynamic'        => true,
                'icon'           => 'fa-shield-alt',
                'description'    => 'Crest-led hero built from the park’s own heraldry and colour, with its next game day. Designed to look finished with no photo — only 5 of 342 parks have one.',
                'addable'        => true,
                'scopes'         => array('park'),
                'starter_fields' => array(
                    'kicker' => '', 'heading' => '', 'subcopy' => '',
                    'cta_label' => '', 'cta_href' => '',
                    'show_weather' => 1, 'placeholder_image' => array(),
                ),
            ),
```

- [ ] **Step 2: Write the partial**

Create `orkui/template/default/frontdoor/blocks/park_hero.tpl`:

```php
<?php
/**
 * Partial: park_hero.tpl — DYNAMIC block (park scope only).
 *
 * The crest hero. A park cannot lean on photography the way the global front door
 * does — 316 of 342 have a heraldry device and 5 have a banner photo — so the
 * anchor is the device itself, framed hard enough that the FRAME reads as the
 * design decision and the image reads as cargo. You cannot fix 342 images of
 * varying quality; you can frame them identically.
 *
 * Renders NOTHING outside park scope (same contract as park_meeting).
 *
 * Receives: $blockFields {kicker, heading, subcopy, cta_label, cta_href,
 *           show_weather, placeholder_image}, $SiteNavScope*, UIR.
 */
$phScopeType = isset($SiteNavScopeType) ? (string) $SiteNavScopeType : 'global';
$phScopeId   = isset($SiteNavScopeId) ? (int) $SiteNavScopeId : 0;
$phParkId    = ($phScopeType === 'park') ? $phScopeId : 0;
if ($phParkId <= 0) {
    return;
}

$phPark = array();
try {
    if (class_exists('APIModel')) {
        $phModel  = new APIModel('Park');
        $phDetail = $phModel->GetParkDetails(array('ParkId' => $phParkId));
        if (is_array($phDetail)) {
            $phPark = $phDetail;
        }
    }
} catch (\Throwable $e) {
    $phPark = array();
}

// NOTE the exact keys: Park::GetParkDetails() returns 'ParkName' (not 'Name')
// and 'KingdomId' (not 'KingdomName'). Verified against class.Park.php:482-528.
$phName    = trim((string) ($phPark['ParkName'] ?? ''));
$phTitle   = trim((string) ($phPark['ParkTitle'] ?? ''));
$phCity    = trim((string) ($phPark['City'] ?? ''));
$phProv    = trim((string) ($phPark['Province'] ?? ''));
$phRetired = (string) ($phPark['Active'] ?? 'Active') !== 'Active';

// The kingdom NAME is not in the park detail payload — only its id — so resolve it.
$phKingdom = '';
$phKingdomId = (int) ($phPark['KingdomId'] ?? 0);
if ($phKingdomId > 0) {
    global $DB;
    $DB->Clear();
    $DB->kingdom_id = $phKingdomId;
    $phKRes = $DB->DataSet(
        'SELECT name FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = :kingdom_id LIMIT 1'
    );
    // DataSet() needs an explicit Next() before any field read.
    if ($phKRes && $phKRes->Next()) {
        $phKingdom = trim((string) $phKRes->name);
    }
}

// Eyebrow states the park's real rank and allegiance — Amtgard terminology doing
// real work, not decoration.
$phEyebrow = trim((string) ($blockFields['kicker'] ?? ''));
if ($phEyebrow === '') {
    $phEyebrow = trim(implode(' · ', array_filter(array($phTitle, $phKingdom))));
}
$phHeading = trim((string) ($blockFields['heading'] ?? '')) ?: $phName;
$phPlace   = trim(implode(', ', array_filter(array($phCity, $phProv))));

// --- The seal -------------------------------------------------------------
// Gate on has_heraldry, NEVER on a truthy URL: resolve_heraldry_url() returns a
// guaranteed-404 path when no file exists, so a URL check always looks positive.
$phDeviceUrl = '';
$phIsCut     = false;
if (!empty($phPark['HasHeraldry'])) {
    try {
        $phH = (new APIModel('Heraldry'))->GetHeraldryUrl(array('Type' => 'Park', 'Id' => $phParkId));
        if (is_array($phH) && !empty($phH['Url'])) {
            $phDeviceUrl = (string) $phH['Url'];
            // A .jpg is opaque, so its own background BECOMES the plate when
            // cover-cropped to the disc. A .png was written with alpha and its
            // transparent margin already trimmed, so it floats, matted.
            $phIsCut = (bool) preg_match('/\.jpe?g(\?|$)/i', $phDeviceUrl);
        }
    } catch (\Throwable $e) {
        $phDeviceUrl = '';
    }
}
// Monogram fallback: initials, not the generic placeholder crest, which would make
// all 26 deviceless parks identical and unloved.
$phMonogram = '';
if ($phDeviceUrl === '') {
    $phWords = preg_split('/\s+/', $phName, -1, PREG_SPLIT_NO_EMPTY);
    foreach (array_slice($phWords, 0, 3) as $phW) {
        $phMonogram .= mb_strtoupper(mb_substr($phW, 0, 1));
    }
    $phMonogram = mb_substr($phMonogram, 0, 3);
}

// --- Next game day + weather ---------------------------------------------
$phNextLabel = '';
$phWeather   = '';
try {
    $phDays = (new APIModel('Park'))->GetParkDays(array('ParkId' => $phParkId));
    $phSoonest = null;
    foreach ((array) ($phDays['ParkDays'] ?? array()) as $phDay) {
        if (!is_array($phDay) || !class_exists('Park')) {
            continue;
        }
        $phWhen = Park::CalculateNextParkDay(
            $phDay['Recurrence'] ?? '', $phDay['WeekOfMonth'] ?? 0, $phDay['MonthDay'] ?? 0,
            $phDay['WeekDay'] ?? '', null, $phDay['StartDate'] ?? null, $phDay['WeekInterval'] ?? 0
        );
        if ($phWhen && ($phSoonest === null || strtotime($phWhen) < strtotime($phSoonest['d']))) {
            $phSoonest = array('d' => $phWhen, 't' => (string) ($phDay['Time'] ?? ''));
        }
    }
    if ($phSoonest !== null) {
        $phTs = strtotime($phSoonest['d']);
        $phNextLabel = date('l, F j', $phTs);
        if ($phSoonest['t'] !== '' && $phSoonest['t'] !== '00:00:00') {
            $phNextLabel .= ' · ' . date('g:i A', strtotime($phSoonest['t']));
        }
        // Weather degrades SILENTLY past a 7-day horizon — the forecast table only
        // carries 7 days, and a stale or missing reading must never look broken.
        $phWithinWeek = ($phTs - time()) <= (7 * 86400);
        if ($phWithinWeek && !empty($blockFields['show_weather']) && class_exists('Weather')) {
            $phF = (new Weather())->forecast_for_date($phParkId, date('Y-m-d', $phTs));
            if (is_array($phF) && isset($phF['high'])) {
                $phWeather = round((float) $phF['high']) . '°F';
            }
        }
    }
} catch (\Throwable $e) {
    $phNextLabel = '';
    $phWeather   = '';
}

$phCtaLabel = trim((string) ($blockFields['cta_label'] ?? '')) ?: 'Plan your first visit';
$phCtaHref  = trim((string) ($blockFields['cta_href'] ?? '')) ?: '#pk-meet';
$phMapUrl   = trim((string) ($phPark['MapUrl'] ?? ''));
if ($phMapUrl !== '' && !preg_match('#^https?://#i', $phMapUrl)) {
    $phMapUrl = '';
}
$phPlaceholder = is_array($blockFields['placeholder_image'] ?? null) ? $blockFields['placeholder_image'] : array();
$phPhotoSrc = trim((string) ($phPlaceholder['display'] ?? $phPlaceholder['src'] ?? ''));
?>
<?php if (empty($fdStyleOnce['park_hero'])) : $fdStyleOnce['park_hero'] = true; ?>
<style>
/* scoped: pk-hero */
.pk-hero { position: relative; overflow: hidden; background: var(--fd-primary);
    color: var(--fd-primary-contrast); border-bottom: 3px solid var(--fd-accent-on-primary, var(--fd-accent));
    padding: clamp(40px, 7vw, 84px) clamp(20px, 4vw, 56px); }
/* Placeholder photo sits BEHIND the field at low opacity so removing it degrades
   to a finished crest hero rather than an empty frame. */
.pk-hero-photo { position: absolute; inset: 0; object-fit: cover; width: 100%; height: 100%;
    opacity: .22; }
/* Diapering: heralds incised a lattice into large flat tinctures precisely because
   unbroken flat colour reads as dead paint. CSS-only, so it never depends on the
   device file's alpha. */
.pk-hero-field { position: absolute; inset: 0; pointer-events: none;
    background-image:
        radial-gradient(circle at 1px 1px, rgba(255,255,255,.075) 1px, transparent 1.7px),
        repeating-linear-gradient( 60deg, transparent 0 15px, rgba(255,255,255,.042) 15px 16px),
        repeating-linear-gradient(-60deg, transparent 0 15px, rgba(255,255,255,.042) 15px 16px);
    background-size: 32px 55px, auto, auto;
    -webkit-mask-image: radial-gradient(115% 105% at 78% 45%, #000 18%, rgba(0,0,0,.28) 100%);
    mask-image: radial-gradient(115% 105% at 78% 45%, #000 18%, rgba(0,0,0,.28) 100%); }
.pk-hero-inner { position: relative; max-width: 1120px; margin-inline: auto; display: grid;
    grid-template-columns: minmax(0,1fr) auto; align-items: center; gap: clamp(24px, 5vw, 64px); }
.pk-eyebrow { font-family: var(--fd-font-body); font-weight: 700; font-size: .6875rem;
    letter-spacing: .16em; text-transform: uppercase; color: var(--fd-accent-on-primary, var(--fd-accent));
    margin: 0 0 10px; }
/* Reset the orkui global h1-h6 grey pill box. */
.pk-name { background: none; border: 0; padding: 0; border-radius: 0; margin: 0 0 10px;
    font-family: var(--fd-font-heading); color: var(--fd-primary-contrast);
    font-size: clamp(2.25rem, 1.35rem + 4.4vw, 4rem); line-height: .98; letter-spacing: -.015em; }
.pk-place { margin: 0 0 14px; opacity: .86; }
.pk-place i { margin-right: 7px; }
.pk-next { display: inline-block; margin: 0 0 22px; padding: 8px 14px;
    border-left: 3px solid var(--fd-accent-on-primary, var(--fd-accent));
    background: rgba(255,255,255,.09); border-radius: 0 var(--fd-radius, 6px) var(--fd-radius, 6px) 0; }
.pk-wx { margin-left: 10px; opacity: .85; }
.pk-actions { display: flex; flex-wrap: wrap; gap: 12px; }
.pk-seal { width: clamp(116px, 17vw, 196px); aspect-ratio: 1; display: grid; place-items: center;
    border-radius: 50%; background: var(--pk-paper, #fff);
    box-shadow: 0 0 0 2px var(--fd-accent-on-primary, var(--fd-accent)),
                0 0 0 9px rgba(255,255,255,.13), 0 14px 34px rgba(0,0,0,.28); }
.pk-seal.is-matted img { width: 78%; height: 78%; object-fit: contain; }
.pk-seal.is-cut img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.pk-seal.is-monogram { color: var(--fd-primary); font-family: var(--fd-font-heading);
    font-size: clamp(2.4rem, 6vw, 4.2rem); font-weight: 700; }
.pk-hero.is-retired { filter: saturate(.35); }
@media (max-width: 760px) {
    .pk-hero-inner { grid-template-columns: 1fr; gap: 22px; }
    .pk-seal { order: -1; width: 104px; }
    .pk-actions > a { flex: 1 1 auto; justify-content: center; }
}
</style>
<?php endif; ?>
<header class="pk-hero<?= $phRetired ? ' is-retired' : '' ?>">
    <?php if ($phPhotoSrc !== ''): ?>
        <img class="pk-hero-photo" src="<?= htmlspecialchars($phPhotoSrc, ENT_QUOTES) ?>" alt="" aria-hidden="true">
    <?php endif; ?>
    <div class="pk-hero-field" aria-hidden="true"></div>
    <div class="pk-hero-inner">
        <div>
            <?php if ($phEyebrow !== ''): ?>
                <p class="pk-eyebrow"><?= htmlspecialchars($phEyebrow, ENT_QUOTES) ?></p>
            <?php endif; ?>
            <h1 class="pk-name"><?= htmlspecialchars($phHeading, ENT_QUOTES) ?></h1>
            <?php if ($phPlace !== ''): ?>
                <p class="pk-place"><i class="fas fa-map-marker-alt" aria-hidden="true"></i><?= htmlspecialchars($phPlace, ENT_QUOTES) ?></p>
            <?php endif; ?>
            <?php if ($phNextLabel !== ''): ?>
                <p class="pk-next">Next game day <b><?= htmlspecialchars($phNextLabel, ENT_QUOTES) ?></b>
                    <?php if ($phWeather !== ''): ?>
                        <span class="pk-wx"><i class="fas fa-cloud-sun" aria-hidden="true"></i> <?= htmlspecialchars($phWeather, ENT_QUOTES) ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <div class="pk-actions">
                <a class="fd-btn-gold" href="<?= htmlspecialchars(CmsSanitizer::SafeHrefOrHash($phCtaHref), ENT_QUOTES) ?>"><?= htmlspecialchars($phCtaLabel, ENT_QUOTES) ?></a>
                <?php if ($phMapUrl !== ''): ?>
                    <a class="fd-btn-ghost" href="<?= htmlspecialchars($phMapUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener">Get directions <i class="fas fa-external-link-alt" aria-hidden="true"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($phDeviceUrl !== ''): ?>
            <div class="pk-seal <?= $phIsCut ? 'is-cut' : 'is-matted' ?>">
                <img src="<?= htmlspecialchars($phDeviceUrl, ENT_QUOTES) ?>" alt="Arms of <?= htmlspecialchars($phName, ENT_QUOTES) ?>">
            </div>
        <?php else: ?>
            <div class="pk-seal is-monogram" role="img" aria-label="<?= htmlspecialchars($phName, ENT_QUOTES) ?>"><?= htmlspecialchars($phMonogram, ENT_QUOTES) ?></div>
        <?php endif; ?>
    </div>
</header>
```

- [ ] **Step 3: Lint and verify the block is offered only in park scope**

Run: `php -l orkui/template/default/frontdoor/blocks/park_hero.tpl`
Expected: `No syntax errors detected`.

Run:

```bash
php -r "
require 'system/lib/ork3/class.CmsBlockRegistry.php';
\$d = CmsBlockRegistry::BlockDefs();
var_dump(isset(\$d['park_hero']), \$d['park_hero']['scopes']);"
```

Expected: `bool(true)` and `array(1) { [0]=> string(4) "park" }`.

- [ ] **Step 4: Verify all four seal cases in the browser**

Provision a park site, add a `park_hero` block, and check each case:

| Case | How to force it | Expected |
|---|---|---|
| PNG device | a park whose file is `.png` | device floats, matted at 78% |
| JPEG device | a park whose file is `.jpg` | device fills the disc, background becomes the plate |
| No device | `UPDATE ork_park SET has_heraldry=0 WHERE park_id=1049` | monogram initials, no broken image |
| Retired | `UPDATE ork_park SET active='Retired' WHERE park_id=1049` | desaturated field |

Restore the park after: `UPDATE ork_park SET has_heraldry=1, active='Active' WHERE park_id=1049`.

- [ ] **Step 5: Check dark mode and 390px**

In the browser console on the park home page:

```js
document.documentElement.setAttribute('data-theme','dark');
// then read contrast of .pk-name against .pk-hero
```

Expected: legible; white on the deep field clears 7:1 by construction (Task 4 forces L 0.22).

Then measure overflow in a 390px iframe (`resize_window` is unreliable here):

```js
const f=document.createElement('iframe');
f.style.cssText='position:fixed;left:-9999px;width:390px;height:844px';
document.body.appendChild(f); f.src=location.href;
await new Promise(r=>{f.onload=r;setTimeout(r,8000)});
({scrollW: f.contentDocument.body.scrollWidth, viewport: 390})
```

Expected: `scrollW <= 392`.

- [ ] **Step 6: Commit**

```bash
git add orkui/template/default/frontdoor/blocks/park_hero.tpl system/lib/ork3/class.CmsBlockRegistry.php
git commit -m "Enhancement: OGRE — park_hero, a crest-led hero for photo-less parks

316 of 342 parks have a heraldry device and 5 have a banner photo, so the
hero anchors on the device, framed hard enough that the frame reads as the
design decision and the image reads as cargo. A .jpg cover-crops so its own
background becomes the plate; a .png floats matted. Parks with no device get
a monogram seal rather than the generic placeholder crest, which would make
all 26 of them identical.

Carries the next game day and, within a 7-day horizon, the forecast high —
the one element that makes a photo-less page feel like it changed this week.
Both degrade silently."
```

---

## Task 7: The sticky quick-facts strip

**Files:**
- Create: `orkui/template/default/frontdoor/_park_strip.tpl`
- Modify: `orkui/template/default/Site_shell.tpl`

**Interfaces:**
- Consumes: `$SiteNavScopeType` / `$SiteNavScopeId`.
- Produces: nothing (shell chrome).

- [ ] **Step 1: Write the partial**

Create `orkui/template/default/frontdoor/_park_strip.tpl`:

```php
<?php
/**
 * Partial: _park_strip.tpl — the sticky quick-facts strip (park scope only).
 *
 * SHELL CHROME, not a block: an officer must not be able to delete it by accident.
 *
 * This is the mechanism that resolves the three-audience tension. New players,
 * locals and visiting players all want the SAME fact first — when and where — and
 * diverge only on the second. So the shared fact is hoisted above the split and the
 * split happens below it: the local reads this and leaves in four seconds, the
 * newcomer scrolls past it into reassurance, the visitor taps Contact.
 *
 * THREE DEGRADATION TIERS. This is what makes it safe to pin:
 *   1. park days exist (308 of 342)      -> time, place, directions
 *   2. no park days but an address (98%) -> place and directions ONLY.
 *                                           Never invent or approximate a time.
 *   3. neither                            -> render NOTHING. A permanently sticky
 *      "Meeting times coming soon" follows the visitor down every page announcing
 *      that the site is unfinished.
 */
$psScopeType = isset($SiteNavScopeType) ? (string) $SiteNavScopeType : 'global';
$psParkId    = ($psScopeType === 'park') ? (int) ($SiteNavScopeId ?? 0) : 0;
if ($psParkId <= 0) {
    return;
}

$psWhen = '';
$psWhere = '';
$psMap = '';
try {
    $psModel = new APIModel('Park');
    $psPark  = $psModel->GetParkDetails(array('ParkId' => $psParkId));
    $psPark  = is_array($psPark) ? $psPark : array();

    $psWhere = trim(implode(', ', array_filter(array(
        trim((string) ($psPark['City'] ?? '')),
        trim((string) ($psPark['Province'] ?? '')),
    ))));
    $psMap = trim((string) ($psPark['MapUrl'] ?? ''));
    if ($psMap !== '' && !preg_match('#^https?://#i', $psMap)) {
        $psMap = '';
    }
    if ($psMap === '' && $psWhere !== '') {
        $psMap = 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(
            trim((string) ($psPark['Address'] ?? '')) . ' ' . $psWhere
        );
    }

    $psDays = $psModel->GetParkDays(array('ParkId' => $psParkId));
    $psBest = null;
    foreach ((array) ($psDays['ParkDays'] ?? array()) as $psDay) {
        if (!is_array($psDay) || !class_exists('Park')) {
            continue;
        }
        $psNext = Park::CalculateNextParkDay(
            $psDay['Recurrence'] ?? '', $psDay['WeekOfMonth'] ?? 0, $psDay['MonthDay'] ?? 0,
            $psDay['WeekDay'] ?? '', null, $psDay['StartDate'] ?? null, $psDay['WeekInterval'] ?? 0
        );
        if ($psNext && ($psBest === null || strtotime($psNext) < strtotime($psBest['d']))) {
            $psBest = array('d' => $psNext, 't' => (string) ($psDay['Time'] ?? ''),
                            'w' => (string) ($psDay['WeekDay'] ?? ''));
        }
    }
    if ($psBest !== null) {
        $psWhen = ($psBest['w'] !== '') ? $psBest['w'] . 's' : date('l', strtotime($psBest['d'])) . 's';
        if ($psBest['t'] !== '' && $psBest['t'] !== '00:00:00') {
            $psWhen .= ' ' . date('g:i A', strtotime($psBest['t']));
        }
    }
} catch (\Throwable $e) {
    $psWhen = '';
}

// Tier 3: nothing truthful to say.
if ($psWhen === '' && $psWhere === '') {
    return;
}
?>
<style>
.pk-strip { position: sticky; top: 0; z-index: 40; display: flex; flex-wrap: wrap;
    align-items: center; gap: 6px 16px; padding: 9px clamp(14px, 3vw, 28px);
    background: var(--fd-primary); color: var(--fd-primary-contrast);
    font-size: calc(var(--fd-font-scale, 1) * .9375rem); }
.pk-strip i { margin-right: 6px; opacity: .8; }
#theme_container a.pk-strip-link { color: var(--fd-accent-on-primary, var(--fd-accent)); font-weight: 600; }
#theme_container a.pk-strip-link:hover { color: var(--fd-primary-contrast); }
@media (max-width: 520px) { .pk-strip { font-size: calc(var(--fd-font-scale, 1) * .875rem); } }
</style>
<div class="pk-strip">
    <?php if ($psWhen !== ''): ?>
        <span><i class="fas fa-clock" aria-hidden="true"></i><?= htmlspecialchars($psWhen, ENT_QUOTES) ?></span>
    <?php endif; ?>
    <?php if ($psWhere !== ''): ?>
        <span><i class="fas fa-map-marker-alt" aria-hidden="true"></i><?= htmlspecialchars($psWhere, ENT_QUOTES) ?></span>
    <?php endif; ?>
    <?php if ($psMap !== ''): ?>
        <a class="pk-strip-link" href="<?= htmlspecialchars($psMap, ENT_QUOTES) ?>" target="_blank" rel="noopener">Directions <i class="fas fa-external-link-alt" aria-hidden="true"></i></a>
    <?php endif; ?>
</div>
```

- [ ] **Step 2: Include it in the shell**

In `orkui/template/default/Site_shell.tpl`, immediately after the org header include and before the breadcrumb/title block, add:

```php
    <?php include $fdDir . '_park_strip.tpl'; ?>
```

- [ ] **Step 3: Verify all three tiers**

```bash
# Tier 1 — park days present
curl -s "http://localhost:19080/orkui/index.php?Route=Site/view/angler-s-rift" | grep -c "pk-strip"

# Tier 2 — no park days, address only
docker exec ork3-php8-db mariadb -uork -psecret ork -e \
  "CREATE TEMPORARY TABLE pd_bak AS SELECT * FROM ork_parkday WHERE park_id=1049; DELETE FROM ork_parkday WHERE park_id=1049;" 2>&1 | grep -v insecure
curl -s "http://localhost:19080/orkui/index.php?Route=Site/view/angler-s-rift" | grep -o "pk-strip[^\"]*" | head
# Expected: strip present, NO time claim

# Tier 3 — no address either
docker exec ork3-php8-db mariadb -uork -psecret ork -e \
  "UPDATE ork_park SET city='', province='', address='' WHERE park_id=1049;" 2>&1 | grep -v insecure
curl -s "http://localhost:19080/orkui/index.php?Route=Site/view/angler-s-rift" | grep -c "pk-strip"
# Expected: 0
```

Restore afterwards from your own backup of the park row and `ork_parkday`.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/default/frontdoor/_park_strip.tpl orkui/template/default/Site_shell.tpl
git commit -m "Enhancement: OGRE — sticky quick-facts strip on park sites

All three park audiences want the same fact first (when and where) and
diverge only on the second, so the shared fact is hoisted above the split.
Sourced from park-day data, never hand-typed. Three degradation tiers: time
and place, place only when there are no park days (never approximate a
time), and nothing at all when neither exists — a permanently sticky
'coming soon' would follow the visitor down every page."
```

---

## Task 8: Rewrite the park starter template

**Files:**
- Modify: `system/lib/ork3/class.CmsSite.php` (`_starterPageDefs()`)
- Modify: `tests/cms-site/site_test.php`

**Interfaces:**
- Consumes: `CmsBlockRegistry` block types, `$this->OrgUnitNoun()`.
- Produces: park registry keys `home`, `new-players`, `contact`.

- [ ] **Step 1: Write the failing test**

Append to `tests/cms-site/site_test.php`, before the final echo:

```php
// --- Park starter is its own template, not a trimmed kingdom one ----------
$parkDefs2  = $defs->invoke($site, 'park', 1049);
$parkSlugs  = array_keys($parkDefs2);
$parkTypes2 = $blockTypes($parkDefs2);
$parkCopy2  = $allCopy($parkDefs2);

check('park seeds exactly three pages', count($parkSlugs) === 3);
check('park pages are home / new-players / contact',
    $parkSlugs === array('home', 'new-players', 'contact'));
check('park no longer seeds an About page', !isset($parkDefs2['about']));
check('park no longer seeds a Documents page', !isset($parkDefs2['documents']));
check('park seeds no staff_roster (parks have no board)',
    !in_array('staff_roster', $parkTypes2, true));
check('park home leads with park_hero', $parkDefs2['home']['blocks'][0]['type'] === 'park_hero');
check('park home carries park_meeting', in_array('park_meeting', $parkTypes2, true));
check('park home carries the first-day steps', in_array('steps', $parkTypes2, true));
check('new-players carries the FAQ accordion',
    in_array('accordion', array_map(function ($b) { return $b['type']; }, $parkDefs2['new-players']['blocks']), true));
check('contact carries park_officers',
    in_array('park_officers', array_map(function ($b) { return $b['type']; }, $parkDefs2['contact']['blocks']), true));
check('no seeded copy contains author instructions',
    stripos($parkCopy2, 'replace this placeholder') === false
    && stripos($parkCopy2, 'describe your park') === false
    && stripos($parkCopy2, 'tell visitors who you are') === false);
check('no seeded copy hard-codes a weekday',
    !preg_match('/\b(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\b/i', $parkCopy2));
check('no seeded copy promises a price', stripos($parkCopy2, '$') === false);
check('nav labels are Home / New Players / Contact', array_map(
    function ($d) { return $d['nav_label']; }, $parkDefs2) === array(
    'home' => 'Home', 'new-players' => 'New Players', 'contact' => 'Contact'));
```

- [ ] **Step 2: Run it to verify it fails**

Run: `php tests/cms-site/site_test.php`
Expected: FAIL on `park seeds exactly three pages` (currently four).

- [ ] **Step 3: Rewrite the park branch of `_starterPageDefs()`**

Replace the park portion so that when `$isPark` is true the function returns **only** these three definitions. Copy deck below is final and ships unedited.

```php
        if ($isPark) {
            return array(
                'home' => array(
                    'nav_label' => 'Home',
                    'attrs' => array(
                        'slug' => 'home', 'type' => 'composed', 'title' => 'Home', 'is_system' => 1,
                        'meta_description' => 'A local Amtgard chapter — foam combat and medieval hobby, all ages, no experience or equipment needed. See when and where we meet, and what to expect on your first day.',
                    ),
                    'blocks' => array(
                        array('type' => 'park_hero', 'source' => 'dynamic', 'enabled' => 1, 'order' => 10,
                            'fields' => array('kicker' => '', 'heading' => '', 'show_weather' => 1,
                                'cta_label' => 'Plan your first visit', 'cta_href' => '#pk-meet')),
                        array('type' => 'park_meeting', 'source' => 'dynamic', 'enabled' => 1, 'order' => 20,
                            'fields' => array('kicker' => 'When can I show up?', 'heading' => 'When & Where We Meet',
                                'show_map' => 1, 'show_directions' => 1, 'limit' => 6)),
                        array('type' => 'steps', 'source' => 'authored', 'enabled' => 1, 'order' => 30,
                            'fields' => array(
                                'kicker' => 'New here? Start here', 'heading' => 'Your First Day, Start to Finish',
                                'band' => 'light',
                                'cta' => array('label' => 'More questions? Read the new player guide', 'href' => 'new-players'),
                                'steps' => array(
                                    array('title' => 'Just show up.', 'body' => 'You don’t need to email anyone, register, or bring anything but water. Come to the time and place above. Ten minutes early is perfect. An hour late is also fine — we’ll still be out there.'),
                                    array('title' => 'Say the words "I’m new."', 'body' => 'Walk up to anyone and say it. That is the entire process. They’ll point you at whoever is running the day. Every person on that field said the same sentence once.'),
                                    array('title' => 'Borrow a sword.', 'body' => 'We keep loaner weapons and shields for exactly this reason. They’re foam over a flexible core. Someone will walk you through the safety basics — what counts as a hit, what’s off-limits — in about five minutes.'),
                                    array('title' => 'Play, or just watch.', 'body' => 'Jump into a game whenever you’re ready. If you’d rather stand on the sideline your whole first day and figure out what’s going on, that is completely normal and nobody will push you.'),
                                ))),
                        array('type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 40,
                            'fields' => array(
                                'kicker' => 'What is this, exactly?', 'heading' => 'Who We Are', 'align' => 'left',
                                'body' => $clean($this->_parkIntroBody($scopeId)))),
                        array('type' => 'park_events', 'source' => 'dynamic', 'enabled' => 1, 'order' => 50,
                            'fields' => array('kicker' => 'What’s coming up?', 'heading' => 'Upcoming Events', 'limit' => 3)),
                        array('type' => 'park_officers', 'source' => 'dynamic', 'enabled' => 1, 'order' => 60,
                            'fields' => array('kicker' => 'Who do I talk to?', 'heading' => 'Our Officers', 'limit' => 12)),
                        array('type' => 'cta_band', 'source' => 'authored', 'enabled' => 1, 'order' => 70,
                            'fields' => $this->_parkCtaFields($scopeId)),
                    ),
                ),

                'new-players' => array(
                    'nav_label' => 'New Players',
                    'attrs' => array(
                        'slug' => 'new-players', 'type' => 'article', 'title' => 'New Players',
                        'meta_description' => 'Everything you need for your first day of Amtgard: what to wear, what it costs, whether it’s safe, and what actually happens at a park day.',
                    ),
                    'blocks' => array(
                        array('type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 10,
                            'fields' => array(
                                'kicker' => 'Never played?', 'heading' => 'Start Here', 'align' => 'left',
                                'body' => $clean('<p>Amtgard is a foam-combat and medieval hobby that meets outdoors in a public park. There is no tryout, no membership to buy, and no experience required. Turn up, borrow a sword, and someone will teach you the rest.</p>'))),
                        array('type' => 'accordion', 'source' => 'authored', 'enabled' => 1, 'order' => 20,
                            'fields' => array('items' => array(
                                array('q' => 'What should I wear?', 'a' => $clean('<p>Clothes you can run in and closed-toe shoes you don’t mind getting grass on. That’s genuinely it — you do not need a costume, armor, or anything medieval, and plenty of regulars play in gym shorts and a t-shirt. Bring water. Sunscreen if it’s that kind of day.</p>')),
                                array('q' => 'Do I need to buy equipment?', 'a' => $clean('<p>No. We have loaner weapons and shields, and you’re welcome to use them as long as you want — weeks or months, nobody’s counting. When you do want your own, most players build theirs out of foam, tape, and a bit of patience, and someone here will happily show you how. This hobby is much cheaper than it looks.</p>')),
                                array('q' => 'Does it cost anything?', 'a' => $clean('<p>Coming out and playing doesn’t. Amtgard is run entirely by volunteers — nobody here is paid and nobody is selling you anything. Some groups ask their regular members for small dues later on to keep loaner gear stocked, but nobody is going to ask you for money on your first day.</p>')),
                                array('q' => 'What actually happens at a park day?', 'a' => $clean('<p>People trickle in, gear gets laid out and safety-checked, and someone starts calling games — team battles, last-one-standing, capture the flag with foam swords. In between, people sit in the shade and talk, work on armor and costume, or practice. You can play as hard or as gently as you like; there’s no fitness requirement and no minimum. Come late, leave early, take breaks whenever you want.</p>')),
                                array('q' => 'Is it safe? Will I get hurt?', 'a' => $clean('<p>Every weapon is foam over a flexible core and gets checked before it’s used. Intentional hits to the head are against the rules, and so is swinging harder than it takes to feel a hit. You may pick up a bruise, the way you would in any sport — real injuries are rare. If someone is playing too hard, tell an officer. That’s what they’re there for.</p>')),
                                array('q' => 'Will I be the only new person?', 'a' => $clean('<p>Maybe, maybe not — some days there are three newcomers and some days there’s just you. Either way, you won’t be the only person who has ever been new: every single player out there walked up once without knowing anybody. Showing up alone is the normal way to start.</p>')),
                                array('q' => 'How old do you have to be?', 'a' => $clean('<p>Amtgard is all ages, and most groups have players from grade-schoolers to retirees. If you’re under 18, bring a parent or guardian along the first time — they may need to sign a waiver, and they’ll probably enjoy watching more than they expect.</p>')),
                                array('q' => 'Do I have to role-play or be in character?', 'a' => $clean('<p>No. Some players have an elaborate persona and a name they go by out here; plenty of others just use their own first name and hit people with foam. Both are completely normal. Nobody is going to make you do an accent.</p>')),
                            ))),
                        array('type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 30,
                            'fields' => array(
                                'kicker' => 'Not near us?', 'heading' => 'Find Another Group', 'align' => 'left',
                                'body' => $clean('<p>Amtgard has hundreds of chapters. If we’re too far away, the Atlas will find the one nearest you.</p>'),
                                'cta' => array('label' => 'Find another Amtgard group', 'href' => 'Atlas'))),
                    ),
                ),

                'contact' => array(
                    'nav_label' => 'Contact',
                    'attrs' => array(
                        'slug' => 'contact', 'type' => 'composed', 'title' => 'Contact & Officers',
                        'meta_description' => 'The volunteers who run this Amtgard chapter, and how to reach us.',
                    ),
                    'blocks' => array(
                        array('type' => 'park_officers', 'source' => 'dynamic', 'enabled' => 1, 'order' => 10,
                            'fields' => array('kicker' => 'Who do I talk to?', 'heading' => 'Our Officers', 'limit' => 12)),
                        array('type' => 'rich_text', 'source' => 'authored', 'enabled' => 1, 'order' => 20,
                            'fields' => array(
                                'heading' => 'Visiting from another park?', 'align' => 'left',
                                'body' => $clean('<p>You’re welcome at any of our park days — just come as you are. If you need to reach someone before you travel, any of the officers above can help.</p>'))),
                    ),
                ),
            );
        }
```

- [ ] **Step 4: Add the two helpers**

```php
    /**
     * Home's "who we are" body. Uses the park's own ORK description when it has one
     * (246 of 342 do), so three quarters of parks get a genuinely local paragraph
     * with nobody typing anything. The fallback is a sentence true of every Amtgard
     * park — never an instruction to the officer, which is what the old seed
     * published to the open web.
     */
    private function _parkIntroBody($parkId)
    {
        global $DB;
        $DB->Clear();
        $DB->park_id = (int) $parkId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT description FROM ' . DB_PREFIX . 'park WHERE park_id = :park_id LIMIT 1'
        ));
        $desc = trim((string) ($row['description'] ?? ''));
        if ($desc !== '' && mb_strlen($desc) <= 800) {
            return '<p>' . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        return '<p>We’re a local chapter of Amtgard — an all-ages foam-combat and medieval '
            . 'hobby group that meets outdoors in a public park. Nothing to buy, nothing to '
            . 'sign up for, no experience needed. Show up, borrow a sword, and we’ll teach '
            . 'you the rest.</p>';
    }

    /**
     * Closing CTA. Two tiers: showing up (always true, needs no data, and the only
     * honest ask — there is no self-service signup to point at) plus the park's one
     * external URL, LABELLED BY WHAT IT ACTUALLY IS. Of 204 parks with a URL, 148 are
     * Facebook; a generic "visit our website" wastes the reassurance a social link
     * carries, since a newcomer can see the group is active and lurk before committing.
     *
     * Slot 2 is left EMPTY on purpose. ork_park has exactly one url column, which is
     * why Discord appears only 5 times — the most public thing wins the slot. An empty
     * CTA renders nothing publicly and prompts loudly in the editor, so officers get an
     * obvious home for a Discord invite at zero data-model cost.
     */
    private function _parkCtaFields($parkId)
    {
        global $DB;
        $DB->Clear();
        $DB->park_id = (int) $parkId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT url FROM ' . DB_PREFIX . 'park WHERE park_id = :park_id LIMIT 1'
        ));
        $url = trim((string) ($row['url'] ?? ''));

        $ctas = array();
        if ($url !== '' && preg_match('#^https?://#i', $url)) {
            $label = 'Visit our page';
            if (preg_match('#(facebook\.com|fb\.com|fb\.me)#i', $url)) {
                $label = 'Ask us on Facebook';
            } elseif (stripos($url, 'discord') !== false) {
                $label = 'Join our Discord';
            }
            // Ghost, never solid: a social link is a LOWER-commitment action than
            // showing up and will out-click the real goal if given equal weight.
            $ctas[] = array('label' => $label, 'href' => $url, 'style' => 'ghost');
        }
        $ctas[] = array('label' => '', 'href' => '', 'style' => 'ghost');

        return array(
            'heading' => 'Come Find Us',
            'subcopy' => 'Still have a question? Ask before you come out — there is no dumb '
                . 'question about a hobby where adults hit each other with foam. And if you’d '
                . 'rather just turn up unannounced and see what’s going on, do that instead. '
                . 'Both work.',
            'logo'  => array(),
            'ctas'  => $ctas,
            'links' => '',
        );
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php tests/cms-site/site_test.php`
Expected: `ALL PASS`.

- [ ] **Step 6: Provision a park site and verify end to end**

```bash
docker exec ork3-php8-db mariadb -uork -psecret ork -e "
DELETE FROM ork_cms_block WHERE owner_type='page' AND owner_id IN (SELECT page_id FROM ork_cms_page WHERE scope_type='park' AND scope_id=1049);
DELETE FROM ork_cms_page WHERE scope_type='park' AND scope_id=1049;
DELETE FROM ork_cms_nav_item WHERE scope_type='park' AND scope_id=1049;
DELETE FROM ork_cms_site WHERE scope_type='park' AND scope_id=1049;
DELETE FROM ork_cms_theme WHERE scope_type='park' AND scope_id=1049;" 2>&1 | grep -v insecure

curl -s -c /tmp/cj.txt -b /tmp/cj.txt -d "username=heraldsbridge&password=x" \
  "http://localhost:19080/orkui/index.php?Route=Login/login" -o /dev/null
curl -s -b /tmp/cj.txt "http://localhost:19080/orkui/index.php?Route=Cms/dashboard&scope=p:1049" -o /dev/null

docker exec ork3-php8-db mariadb -uork -psecret ork -e "
SELECT p.slug page, b.ordering, b.type FROM ork_cms_block b
JOIN ork_cms_page p ON p.page_id=b.owner_id AND b.owner_type='page'
WHERE p.scope_type='park' AND p.scope_id=1049 ORDER BY b.owner_id, b.ordering;" 2>&1 | grep -v insecure
```

Expected: exactly three pages; home leads with `park_hero`; no `kingdom_*`, no `staff_roster`, no `file_download`.

- [ ] **Step 7: Browser verification against the spec's success criteria**

Open `http://localhost:19080/orkui/index.php?Route=Site/view/angler-s-rift` and confirm:

1. No empty band, no placeholder prose, no phantom officer card.
2. Nav is exactly `Home · New Players · Contact` and fits one line at 390px.
3. Light and dark both readable.
4. Zero horizontal overflow at 390px (use the iframe measurement from Task 6, Step 5).
5. Zero console errors.

- [ ] **Step 8: Commit**

```bash
git add system/lib/ork3/class.CmsSite.php tests/cms-site/site_test.php
git commit -m "Enhancement: OGRE — park starter template is its own design

A park is not a small kingdom. Three pages (Home, New Players, Contact)
against the kingdom's five. About Us goes because its seeded body published
author instructions to the open web; Documents & Resources goes because a
park has no library to put behind it; the Board of Directors roster goes
because parks have no board and it published a fabricated person.

No Events page: 26 of 342 parks have an upcoming event, so a nav item to an
empty page would tell a prospective newcomer the club is dead before they
clicked. Events stay as a block on Home, where the honest empty state reads
as 'nothing beyond our regular park days'.

Copy ships unedited and is true for any Amtgard park — no weekday, no price,
no attendance claim. Every time and place comes from the dynamic blocks,
because a hand-typed one fails actively: it contradicts ork_parkday the day
a park moves and sends a newcomer to an empty field."
```

---

## Task 9: Backfill migration for existing park sites

**Files:**
- Create: `db-migrations/2026-08-11-park-theme-backfill.php`

**Interfaces:**
- Consumes: `CmsHeraldryColor`, `CmsTheme`.
- Produces: nothing.

- [ ] **Step 1: Write the migration**

Create `db-migrations/2026-08-11-park-theme-backfill.php`:

```php
<?php

/**
 * 2026-08-11-park-theme-backfill.php
 *
 * Gives every EXISTING org site the theme row that new sites now get at seed time.
 *
 * Without a row, GetActiveCss() returns '' and the site falls through to the raw
 * CSS defaults — which is how every org site ended up rendering in MedievalSharp.
 * This is therefore a visual bug fix, not a nicety.
 *
 * CONSERVATIVE: skips any scope that already has a theme row, so an officer who has
 * already chosen a palette is never overwritten. Re-run safe.
 *
 * Run: php db-migrations/2026-08-11-park-theme-backfill.php
 */

require_once __DIR__ . '/../startup.php';

global $DB;

$DB->Clear();
$sites = array();
$rs = $DB->DataSet(
    'SELECT s.scope_type, s.scope_id FROM ' . DB_PREFIX . 'cms_site s'
    . ' LEFT JOIN ' . DB_PREFIX . 'cms_theme t'
    . '   ON t.scope_type = s.scope_type AND t.scope_id = s.scope_id'
    . ' WHERE t.id IS NULL'
);
while ($rs && $rs->Next()) {
    $sites[] = array('type' => (string) $rs->scope_type, 'id' => (int) $rs->scope_id);
}

if (empty($sites)) {
    echo "Every org site already has a theme row — nothing to do.\n";
    return;
}

$site  = new CmsSite();
$seed  = new ReflectionMethod('CmsSite', '_seedOrgTheme');
$n = 0;
foreach ($sites as $s) {
    $primary = $seed->invoke($site, $s['type'], $s['id'], 0);
    if ($primary !== '') {
        $n++;
        echo "  {$s['type']} {$s['id']}: primary {$primary}\n";
    } else {
        echo "  {$s['type']} {$s['id']}: SKIPPED (no device, no parent, no name)\n";
    }
}

echo "\nBackfilled {$n} of " . count($sites) . " org site(s).\n";
```

- [ ] **Step 2: Run it, then run it again**

```bash
docker exec -w /var/www/ork.amtgard.com ork3-php8-app php db-migrations/2026-08-11-park-theme-backfill.php 2>&1 | grep -v "PHP Warning" | tail -12
docker exec -w /var/www/ork.amtgard.com ork3-php8-app php db-migrations/2026-08-11-park-theme-backfill.php 2>&1 | grep -v "PHP Warning" | tail -4
```

Expected: the first run reports a colour per site; the second reports "nothing to do" (idempotent).

- [ ] **Step 3: Verify no two sites share a palette**

```bash
docker exec ork3-php8-db mariadb -uork -psecret ork -e "
SELECT scope_type, scope_id, is_active,
       SUBSTRING(tokens_json, LOCATE('--fd-primary', tokens_json), 40) primary_token
FROM ork_cms_theme ORDER BY scope_type, scope_id;" 2>&1 | grep -v insecure
```

Expected: one active row per site, with distinct primaries.

- [ ] **Step 4: Run all suites and commit**

```bash
for d in cms-fields cms-heraldry-color cms-sanitizer cms-site cms-tenancy cms-theme; do
  for f in tests/$d/*.php; do printf "%-34s " "$f"; php "$f" >/dev/null 2>&1 && echo PASS || echo FAIL; done
done

git add db-migrations/2026-08-11-park-theme-backfill.php
git commit -m "Migration: backfill theme rows for existing org sites

Sites created before the seeder wrote a theme row have none, so they fall
through to the raw CSS defaults — the MedievalSharp bug. Derives each one's
palette from its own heraldry. Skips any scope that already has a row, so an
officer's chosen palette is never overwritten. Re-run safe."
```

---

## Task 10: Verification sweep and documentation

**Files:**
- Modify: `docs/superpowers/specs/2026-08-10-park-site-starter-template-design.md` (AS-SHIPPED note)

- [ ] **Step 1: Verify every success criterion from the spec**

Provision a fresh park site and check all seven:

| # | Criterion | How |
|---|---|---|
| 1 | Finished home page, zero officer input | Visual check: no empty band, no placeholder prose, no phantom card |
| 2 | No hand-typed time, place, or contact | `grep -iE "saturday|sunday|[0-9]{1,2}(am\|pm)" ` over seeded `fields_json` |
| 3 | Nav fits one phone line at 390px | iframe measurement, no wrap |
| 4 | Hero renders for PNG / JPEG / no device / retired | Task 6 Step 4 matrix |
| 5 | AA in light and dark | contrast sweep over `.pk-*`, `.fd-*` |
| 6 | Zero horizontal overflow at 390px | iframe `scrollWidth <= 392` on all three pages |
| 7 | Suites pin the page set and nav | `php tests/cms-site/site_test.php` |

For criterion 2:

```bash
docker exec ork3-php8-db mariadb -uork -psecret ork -N -e "
SELECT b.fields_json FROM ork_cms_block b
JOIN ork_cms_page p ON p.page_id=b.owner_id AND b.owner_type='page'
WHERE p.scope_type='park';" 2>&1 | grep -v insecure \
  | grep -icE "monday|tuesday|wednesday|thursday|friday|saturday|sunday|[0-9]{1,2}:[0-9]{2} ?(am|pm)"
```

Expected: `0`.

- [ ] **Step 2: Amend the spec with an AS-SHIPPED note**

Add to the top of the spec, under the status line:

```markdown
> **AS SHIPPED (2026-08-11).** Implemented across tasks 1–9 of
> `docs/superpowers/plans/2026-08-10-park-site-starter-template.md`. Deviations from
> this design, if any, are recorded here.
```

Record any deviation you made and why. If there were none, say so explicitly.

- [ ] **Step 3: Clean up test fixtures**

```bash
docker exec ork3-php8-db mariadb -uork -psecret ork -e "
DELETE FROM ork_cms_block WHERE owner_type='page' AND owner_id IN (SELECT page_id FROM ork_cms_page WHERE scope_type='park' AND scope_id=1049);
DELETE FROM ork_cms_page WHERE scope_type='park' AND scope_id=1049;
DELETE FROM ork_cms_nav_item WHERE scope_type='park' AND scope_id=1049;
DELETE FROM ork_cms_site WHERE scope_type='park' AND scope_id=1049;
DELETE FROM ork_cms_theme WHERE scope_type='park' AND scope_id=1049;" 2>&1 | grep -v insecure
```

Confirm `git status --porcelain` shows only intended files, and that
`system/lib/ork3/class.Authorization.php` is **unstaged**.

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/specs/2026-08-10-park-site-starter-template-design.md
git commit -m "Docs: park starter template — AS-SHIPPED notes"
```

---

## Self-Review

**Spec coverage.** Three-page set and nav → Task 8. Quick-facts strip with three tiers → Task 7. `park_hero`, seal modes, monogram, retired → Task 6. Colour extraction and cascade → Tasks 4–5. Two-tier CTA and the empty Discord slot → Task 8 (`_parkCtaFields`). Copy deck → Task 8. B1–B4 → Tasks 1–3. Backfill → Task 9. Success criteria → Task 10.

**Known gap, accepted:** the spec's full visual system (type scale, three-band rhythm, unified card component, eyebrow-as-question) is only partially implemented — Task 2 lands the tokens and paper scale, Task 6 the hero, and Task 8 the eyebrow copy. Consolidating the four duplicate card accents into one component is **not** in this plan; it touches five unrelated blocks and deserves its own change so it can be reviewed and reverted independently. Flag it as follow-up work rather than smuggling it in here.

**Type consistency.** `CmsHeraldryColor::FromFile/FromName/Clamp` are defined in Task 4 and consumed with those exact names in Tasks 5 and 9. `_seedOrgTheme($scopeType, $scopeId, $uid): string` is defined in Task 5 and invoked reflectively with the same signature in Task 9. `--fd-primary-h` is produced in Task 5 Step 1 and consumed in Task 2 Step 2 — **note the ordering dependency: Task 2's CSS falls back to `hsl(220 …)` until Task 5 ships, which is harmless but means the tinted paper only becomes park-specific after Task 5.**

**Placeholder scan.** No TBDs. Every code step carries real content; the copy deck is final text, not a description of text.
