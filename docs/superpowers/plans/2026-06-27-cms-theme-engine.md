# CMS Theme Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let CMS admins restyle the public front-door site (colors, fonts, shape/density) by editing curated design tokens — no CSS — with dark mode auto-derived from one palette.

**Architecture:** A standalone, framework-free `CmsThemeTokens` class owns the token catalog, defaults, validation, and the light→dark derivation (pure color math, unit-tested with host PHP). A DB model `CmsTheme` (extends `CmsBase`) persists per-scope token sets in a new `ork_cms_theme` table and delegates all computation to `CmsThemeTokens`. The front-door CSS is refactored so colors/fonts/shape reference `--fd-*` custom properties declared on `.fd-page`; at render time the active theme is resolved to a `<style>` block emitted in `default.theme`'s `<head>` (scoped to `.fd-page` and `html[data-theme="dark"] .fd-page`). A new `Cms/theme` editor page with a live-preview iframe drives it, saving through CSRF-gated `CmsAjax` endpoints behind a new `theme.manage` capability.

**Tech Stack:** PHP 8.5 (host CLI available at `php`), MySQL (YapoDb), plain-PHP `.tpl` templates (`extract()`+`include`, NOT Smarty), vanilla JS, CSS custom properties. No PHPUnit/composer — pure logic is tested via committed standalone PHP harness scripts run with host `php`.

## Global Constraints

- **`.tpl` files are PLAIN PHP** — use `<?php ?>`/`<?= ?>`, never `{$var}`/`{if}`/`{foreach}`.
- **Theming scope is the public front door ONLY** — touch `frontdoor/css/frontdoor.css`, `frontdoor/blocks/*.tpl`, front-door-only inline styles, and the new editor. NEVER touch `orkui.css` or internal/admin UI styling.
- **Tenancy v1 = global** — every persisted row uses `scope_type='global', scope_id=0`. Store on the existing scope columns so kingdom/park drops in later.
- **Tokens live on `.fd-page`**, not `:root` — match the existing front-door convention (`.fd-page { --navy; --gold; … }`); the front-door CSS is "scoped under `.fd-page`; never bleeds into the ORK shell."
- **Dark mode selector is `html[data-theme="dark"]`** (NOT `body.dark-mode`). Dark token values are DERIVED, never user-authored in v1.
- **No free-form CSS / no arbitrary URLs** — token values flow only into named `--fd-*` properties. Colors validated against hex pattern; fonts validated against the vetted allowlist; numerics range-clamped server-side.
- **CSRF on every mutation** — `CmsAjax` POST endpoints go through `_begin()` (validates `X-CSRF-Token` vs `_csrfToken()`); editor JS sends `window.CMS_CSRF`.
- **Capability gate** — all theme mutations require `theme.manage` via `_require($uid, 'theme.manage')` → `CmsAuth->cms_can(...)`.
- **DB rule** — call `$DB->Clear()` before any raw Execute/DataSet. To clear a column via yapo assign `''`, never `null`.
- **Editor must be dark-mode compatible** from the start (`html[data-theme="dark"]`), per the project dark-mode checklist (modal headers, ghost buttons, labels, placeholders).
- **FontAwesome 5.8.2 only** — no FA6-only icon names.
- **Commit message footer** — end each commit body with: `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`.
- **Staging** — stage files explicitly; NEVER `git add -A`/`.`; NEVER stage `system/lib/ork3/class.Authorization.php`.

## File Structure

- `db-migrations/2026-06-27-cms-theme.sql` — `ork_cms_theme` table (idempotent).
- `system/lib/ork3/class.CmsThemeTokens.php` — **pure, framework-free**: token catalog, defaults, validation, color math, `Derive()`. No `extends`, no `$DB`.
- `system/lib/ork3/class.CmsTheme.php` — DB model (`extends CmsBase`): persistence; delegates computation to `CmsThemeTokens`.
- `orkui/model/model.CmsTheme.php` — thin pass-through (mirrors `model.CmsPage.php`).
- `system/lib/ork3/class.CmsAuth.php` — MODIFY: add `theme.manage` to admin increment + admin-bridge set.
- `orkui/controller/controller.CmsAjax.php` — MODIFY: add `savetheme`/`activatetheme`/`previewtheme`/`resettheme`; load `CmsTheme` model.
- `orkui/controller/controller.Cms.php` — MODIFY: add `theme` action.
- `system/lib/system/class.Controller.php` — MODIFY: `index()` sets `fdThemeCss`; add shared `_attachFrontDoorTheme()` helper.
- `orkui/controller/controller.Page.php`, `controller.Blog.php` — MODIFY: call `_attachFrontDoorTheme()` where they render front-door blocks (if applicable — verify in Task 7).
- `orkui/template/default/default.theme` — MODIFY: emit `<style id="fd-theme-tokens">` in `<head>` when `$fdThemeCss` set.
- `orkui/template/default/frontdoor/css/frontdoor.css` — MODIFY: declare `--fd-*` catalog on `.fd-page`; replace hardcoded values with `var(--fd-*)`.
- `orkui/template/default/frontdoor/blocks/*.tpl` — MODIFY: sweep inline `style=`/hardcoded colors → tokens.
- `orkui/template/default/Cms_theme.tpl` — NEW: editor page.
- `orkui/template/default/cms/_shell_top.tpl` — MODIFY: add Theme rail item.
- `orkui/template/default/style/cms-admin.css` — MODIFY: theme-editor styles (light + dark).
- `tests/cms-theme/tokens_test.php` — NEW: standalone harness for `CmsThemeTokens` (run with host `php`).

## Token Catalog (the CSS↔editor contract)

Declared on `.fd-page`. Defaults below preserve today's look.

| Token | Group | Default (light) | Input |
|---|---|---|---|
| `--fd-primary` | color | `#0b1120` | color picker |
| `--fd-accent` | color | `#f0b429` | color picker |
| `--fd-bg` | color | `#ffffff` | color picker |
| `--fd-surface` | color | `#f7f8fa` | color picker |
| `--fd-text` | color | `#1a2236` | color picker |
| `--fd-text-muted` | color | `#5b6472` | color picker |
| `--fd-border` | color | `#e2e6ec` | color picker |
| `--fd-primary-contrast` | color (derived) | `#ffffff` | (auto) |
| `--fd-font-heading` | type | `MedievalSharp` | select (allowlist) |
| `--fd-font-body` | type | `Open Sans` | select (allowlist) |
| `--fd-font-scale` | type | `1` | stepper 0.9–1.25 |
| `--fd-radius` | shape | `12px` | slider 0–24px |
| `--fd-space` | shape | `1` | slider 0.85–1.3 |
| `--fd-border-width` | shape | `1px` | slider 0–3px |
| `--fd-shadow` | shape | `0 12px 50px rgba(0,0,0,.4)` | select (preset list) |

**Font allowlist (v1):** `Open Sans`, `MedievalSharp`, `Lexend`, `Georgia`, `system-ui`. Heading + body each pick from this list. (Adding webfonts beyond those already loaded is out of scope for v1; allowlist values map to already-available families.)

---

### Task 1: DB migration + pure token catalog & defaults

**Files:**
- Create: `db-migrations/2026-06-27-cms-theme.sql`
- Create: `system/lib/ork3/class.CmsThemeTokens.php`
- Test: `tests/cms-theme/tokens_test.php`

**Interfaces:**
- Produces: `CmsThemeTokens::Defaults(): array` → `['--fd-primary'=>['group'=>'color','value'=>'#0b1120','input'=>'color'], …]` (ordered catalog map). `CmsThemeTokens::FontAllowlist(): array`. `CmsThemeTokens::DefaultValues(): array` → `['--fd-primary'=>'#0b1120', …]` (token⇒default value only).

- [ ] **Step 1: Write the migration**

```sql
-- db-migrations/2026-06-27-cms-theme.sql
-- CMS Theme Engine: per-scope design-token sets for the public front door.
CREATE TABLE IF NOT EXISTS ork_cms_theme (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  scope_type   ENUM('global','kingdom','park') NOT NULL DEFAULT 'global',
  scope_id     INT NOT NULL DEFAULT 0,
  name         VARCHAR(120) NOT NULL DEFAULT 'Default',
  is_active    TINYINT(1) NOT NULL DEFAULT 0,
  tokens_json  JSON NULL,
  updated_by   INT NOT NULL DEFAULT 0,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_scope_name (scope_type, scope_id, name),
  KEY idx_scope_active (scope_type, scope_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 2: Write the failing test for the catalog**

```php
<?php
// tests/cms-theme/tokens_test.php — run: php tests/cms-theme/tokens_test.php
require __DIR__ . '/../../system/lib/ork3/class.CmsThemeTokens.php';

$fails = 0;
function check($label, $cond) { global $fails; if ($cond) { echo "PASS  $label\n"; } else { echo "FAIL  $label\n"; $fails++; } }

// --- Catalog / defaults ---
$def = CmsThemeTokens::Defaults();
check('Defaults has --fd-primary', isset($def['--fd-primary']));
check('primary default is navy', ($def['--fd-primary']['value'] ?? null) === '#0b1120');
check('DefaultValues flattens', CmsThemeTokens::DefaultValues()['--fd-accent'] === '#f0b429');
check('font allowlist has Open Sans', in_array('Open Sans', CmsThemeTokens::FontAllowlist(), true));

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php tests/cms-theme/tokens_test.php`
Expected: FAIL — `class "CmsThemeTokens" not found` (fatal).

- [ ] **Step 4: Implement the pure catalog class**

```php
<?php
// system/lib/ork3/class.CmsThemeTokens.php
// Pure, framework-free token logic for the CMS Theme Engine.
// NO `extends`, NO $DB — safe to include in a CLI harness. All methods static.

class CmsThemeTokens
{
    /** Canonical token catalog (ordered): name => [group, value(default), input]. */
    public static function Defaults()
    {
        return array(
            '--fd-primary'          => array('group' => 'color', 'value' => '#0b1120', 'input' => 'color'),
            '--fd-accent'           => array('group' => 'color', 'value' => '#f0b429', 'input' => 'color'),
            '--fd-bg'               => array('group' => 'color', 'value' => '#ffffff', 'input' => 'color'),
            '--fd-surface'          => array('group' => 'color', 'value' => '#f7f8fa', 'input' => 'color'),
            '--fd-text'             => array('group' => 'color', 'value' => '#1a2236', 'input' => 'color'),
            '--fd-text-muted'       => array('group' => 'color', 'value' => '#5b6472', 'input' => 'color'),
            '--fd-border'           => array('group' => 'color', 'value' => '#e2e6ec', 'input' => 'color'),
            '--fd-primary-contrast' => array('group' => 'color', 'value' => '#ffffff', 'input' => 'derived'),
            '--fd-font-heading'     => array('group' => 'type',  'value' => 'MedievalSharp', 'input' => 'font'),
            '--fd-font-body'        => array('group' => 'type',  'value' => 'Open Sans',     'input' => 'font'),
            '--fd-font-scale'       => array('group' => 'type',  'value' => '1',    'input' => 'scale'),
            '--fd-radius'           => array('group' => 'shape', 'value' => '12px', 'input' => 'px'),
            '--fd-space'            => array('group' => 'shape', 'value' => '1',    'input' => 'scale'),
            '--fd-border-width'     => array('group' => 'shape', 'value' => '1px',  'input' => 'px'),
            '--fd-shadow'           => array('group' => 'shape', 'value' => '0 12px 50px rgba(0,0,0,.4)', 'input' => 'shadow'),
        );
    }

    /** Vetted font families (heading/body must be one of these). */
    public static function FontAllowlist()
    {
        return array('Open Sans', 'MedievalSharp', 'Lexend', 'Georgia', 'system-ui');
    }

    /** token => default value (flattened). */
    public static function DefaultValues()
    {
        $out = array();
        foreach (self::Defaults() as $k => $meta) {
            $out[$k] = $meta['value'];
        }
        return $out;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php tests/cms-theme/tokens_test.php`
Expected: `ALL PASS`.

- [ ] **Step 6: Commit**

```bash
git add db-migrations/2026-06-27-cms-theme.sql system/lib/ork3/class.CmsThemeTokens.php tests/cms-theme/tokens_test.php
git commit -m "Enhancement: CMS Theme Engine — token catalog + theme table migration

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Token validation (sanitize user input)

**Files:**
- Modify: `system/lib/ork3/class.CmsThemeTokens.php`
- Test: `tests/cms-theme/tokens_test.php`

**Interfaces:**
- Produces: `CmsThemeTokens::Validate(array $tokens): array` — returns only known tokens with values that pass per-group validation (colors → `#rgb`/`#rrggbb`; fonts → allowlist; scale/px → numeric range-clamped); drops unknown keys and invalid values. Pure.

- [ ] **Step 1: Add failing tests**

```php
// append before the summary line in tokens_test.php
$v = CmsThemeTokens::Validate(array(
  '--fd-primary'    => '#0B4D3E',
  '--fd-accent'     => 'red; }',          // invalid → dropped
  '--fd-font-body'  => 'Comic Sans',      // not allowlisted → dropped
  '--fd-font-heading'=> 'Lexend',         // ok
  '--fd-radius'     => '999px',           // clamped to max 24px
  '--fd-font-scale' => '1.1',             // ok
  'evil'            => 'x',               // unknown → dropped
));
check('valid hex kept (lowercased)', ($v['--fd-primary'] ?? '') === '#0b4d3e');
check('css-injection value dropped', !isset($v['--fd-accent']));
check('non-allowlist font dropped', !isset($v['--fd-font-body']));
check('allowlist font kept', ($v['--fd-font-heading'] ?? '') === 'Lexend');
check('radius clamped to 24px', ($v['--fd-radius'] ?? '') === '24px');
check('unknown key dropped', !isset($v['evil']));
```

- [ ] **Step 2: Run to verify fail**

Run: `php tests/cms-theme/tokens_test.php`
Expected: FAIL (`Call to undefined method CmsThemeTokens::Validate`).

- [ ] **Step 3: Implement Validate + helpers**

```php
// add inside class CmsThemeTokens

    /** Numeric ranges for non-color tokens: [min, max, unit]. */
    private static function Ranges()
    {
        return array(
            '--fd-font-scale'   => array(0.9, 1.25, ''),
            '--fd-radius'       => array(0, 24, 'px'),
            '--fd-space'        => array(0.85, 1.3, ''),
            '--fd-border-width' => array(0, 3, 'px'),
        );
    }

    private static $SHADOWS = array(
        'none', '0 1px 3px rgba(0,0,0,.18)', '0 6px 24px rgba(0,0,0,.28)', '0 12px 50px rgba(0,0,0,.4)',
    );

    /** Keep only known tokens whose values pass per-group validation. Pure. */
    public static function Validate($tokens)
    {
        $catalog = self::Defaults();
        $ranges  = self::Ranges();
        $out = array();
        foreach ((array)$tokens as $k => $raw) {
            if (!isset($catalog[$k]) || $catalog[$k]['input'] === 'derived') {
                continue; // unknown or auto-only
            }
            $group = $catalog[$k]['input'];
            if ($group === 'color') {
                $val = strtolower(trim((string)$raw));
                if (preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $val)) {
                    $out[$k] = $val;
                }
            } elseif ($group === 'font') {
                if (in_array((string)$raw, self::FontAllowlist(), true)) {
                    $out[$k] = (string)$raw;
                }
            } elseif ($group === 'shadow') {
                if (in_array((string)$raw, self::$SHADOWS, true)) {
                    $out[$k] = (string)$raw;
                }
            } elseif (isset($ranges[$k])) {
                list($min, $max, $unit) = $ranges[$k];
                $n = (float)preg_replace('/[^0-9.\-]/', '', (string)$raw);
                $n = max($min, min($max, $n));
                // integers for px, 2-dp for scales
                $out[$k] = ($unit === 'px') ? (((int)round($n)) . 'px') : rtrim(rtrim(sprintf('%.2f', $n), '0'), '.');
            }
        }
        return $out;
    }
```

- [ ] **Step 4: Run to verify pass**

Run: `php tests/cms-theme/tokens_test.php`
Expected: `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.CmsThemeTokens.php tests/cms-theme/tokens_test.php
git commit -m "Enhancement: CMS Theme Engine — server-side token validation

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Color math + light→dark derivation + contrast

**Files:**
- Modify: `system/lib/ork3/class.CmsThemeTokens.php`
- Test: `tests/cms-theme/tokens_test.php`

**Interfaces:**
- Produces: `CmsThemeTokens::Derive(array $userTokens): array` → `['light'=>[token=>value,...], 'dark'=>[token=>value,...]]`. Merges validated user tokens over `DefaultValues()`, computes `--fd-primary-contrast` for both modes, derives the dark color set (surfaces→dark, text→light, primary/accent lightness-nudged, hue preserved), passes shape/type tokens through unchanged, and applies a WCAG contrast nudge to derived text/surface pairs. Pure.

- [ ] **Step 1: Add failing tests**

```php
$d = CmsThemeTokens::Derive(array('--fd-primary' => '#1b4d3e', '--fd-radius' => '6px'));
check('light keeps user primary', $d['light']['--fd-primary'] === '#1b4d3e');
check('light bg stays default white', $d['light']['--fd-bg'] === '#ffffff');
check('dark bg is dark (low luminance)', CmsThemeTokens::Luminance($d['dark']['--fd-bg']) < 0.15);
check('dark text is light (high luminance)', CmsThemeTokens::Luminance($d['dark']['--fd-text']) > 0.6);
check('shape passes through to dark', $d['dark']['--fd-radius'] === '6px');
check('primary-contrast computed for light', in_array($d['light']['--fd-primary-contrast'], array('#ffffff','#1a2236'), true));
check('dark text/bg contrast >= 4.5', CmsThemeTokens::Contrast($d['dark']['--fd-text'], $d['dark']['--fd-bg']) >= 4.5);
// hue preserved: a green primary stays greener than red in dark
$h = CmsThemeTokens::HexToHsl($d['dark']['--fd-primary']);
check('primary hue preserved (green-ish)', $h[0] > 90 && $h[0] < 180);
```

- [ ] **Step 2: Run to verify fail**

Run: `php tests/cms-theme/tokens_test.php`
Expected: FAIL (undefined `Derive`/`Luminance`/`Contrast`/`HexToHsl`).

- [ ] **Step 3: Implement color math + Derive**

```php
// add inside class CmsThemeTokens

    /** '#rrggbb'|'#rgb' => [r,g,b] 0-255. */
    public static function HexToRgb($hex)
    {
        $hex = ltrim(strtolower(trim((string)$hex)), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/', $hex)) {
            return array(0, 0, 0);
        }
        return array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }

    public static function RgbToHex($r, $g, $b)
    {
        $c = function ($n) { return str_pad(dechex(max(0, min(255, (int)round($n)))), 2, '0', STR_PAD_LEFT); };
        return '#' . $c($r) . $c($g) . $c($b);
    }

    /** hex => [h(0-360), s(0-1), l(0-1)]. */
    public static function HexToHsl($hex)
    {
        list($r, $g, $b) = array_map(function ($v) { return $v / 255; }, self::HexToRgb($hex));
        $max = max($r, $g, $b); $min = min($r, $g, $b); $d = $max - $min;
        $l = ($max + $min) / 2; $h = 0; $s = 0;
        if ($d > 0) {
            $s = $d / (1 - abs(2 * $l - 1));
            if ($max === $r)      { $h = 60 * fmod((($g - $b) / $d), 6); }
            elseif ($max === $g)  { $h = 60 * ((($b - $r) / $d) + 2); }
            else                  { $h = 60 * ((($r - $g) / $d) + 4); }
        }
        if ($h < 0) { $h += 360; }
        return array($h, $s, $l);
    }

    public static function HslToHex($h, $s, $l)
    {
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        if ($h < 60)      { $rp = $c; $gp = $x; $bp = 0; }
        elseif ($h < 120) { $rp = $x; $gp = $c; $bp = 0; }
        elseif ($h < 180) { $rp = 0; $gp = $c; $bp = $x; }
        elseif ($h < 240) { $rp = 0; $gp = $x; $bp = $c; }
        elseif ($h < 300) { $rp = $x; $gp = 0; $bp = $c; }
        else              { $rp = $c; $gp = 0; $bp = $x; }
        return self::RgbToHex(($rp + $m) * 255, ($gp + $m) * 255, ($bp + $m) * 255);
    }

    /** WCAG relative luminance 0-1. */
    public static function Luminance($hex)
    {
        $lin = array_map(function ($v) {
            $v /= 255;
            return $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        }, self::HexToRgb($hex));
        return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
    }

    /** WCAG contrast ratio between two hex colors (>=1). */
    public static function Contrast($a, $b)
    {
        $la = self::Luminance($a); $lb = self::Luminance($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Black or white, whichever contrasts better with $bg. */
    private static function BestText($bg)
    {
        return self::Contrast('#ffffff', $bg) >= self::Contrast('#1a2236', $bg) ? '#ffffff' : '#1a2236';
    }

    private static function WithL($hex, $l)
    {
        list($h, $s) = self::HexToHsl($hex);
        return self::HslToHex($h, $s, max(0, min(1, $l)));
    }

    /** Nudge $fg lightness until it clears $ratio against $bg (preserving hue). */
    private static function EnsureContrast($fg, $bg, $ratio, $towardLight)
    {
        for ($i = 0; $i < 20 && self::Contrast($fg, $bg) < $ratio; $i++) {
            list($h, $s, $l) = self::HexToHsl($fg);
            $l = $towardLight ? min(1, $l + 0.04) : max(0, $l - 0.04);
            $fg = self::HslToHex($h, $s, $l);
        }
        return $fg;
    }

    /** Resolve user tokens to full light + dark token maps. Pure. */
    public static function Derive($userTokens)
    {
        $light = array_merge(self::DefaultValues(), self::Validate($userTokens));
        $light['--fd-primary-contrast'] = self::BestText($light['--fd-primary']);

        // Dark color set (color tokens only; shape/type pass through).
        $dark = $light;
        $dark['--fd-bg']         = self::WithL($light['--fd-primary'], 0.08);   // brand-tinted near-black
        $dark['--fd-surface']    = self::WithL($light['--fd-primary'], 0.13);
        $dark['--fd-border']     = self::WithL($light['--fd-primary'], 0.22);
        $dark['--fd-text']       = '#e8ecf1';
        $dark['--fd-text-muted'] = '#aab3c0';
        // Brand colors: lift lightness for legibility on dark, keep hue/sat.
        list($ph, $ps, $pl) = self::HexToHsl($light['--fd-primary']);
        $dark['--fd-primary'] = self::HslToHex($ph, $ps, max($pl, 0.55));
        list($ah, $as, $al) = self::HexToHsl($light['--fd-accent']);
        $dark['--fd-accent']  = self::HslToHex($ah, $as, max($al, 0.55));
        $dark['--fd-primary-contrast'] = self::BestText($dark['--fd-primary']);

        // Contrast safety on derived pairs (nudge derived values, not stored ones).
        $dark['--fd-text']       = self::EnsureContrast($dark['--fd-text'], $dark['--fd-bg'], 4.5, true);
        $dark['--fd-text-muted'] = self::EnsureContrast($dark['--fd-text-muted'], $dark['--fd-bg'], 3.0, true);

        return array('light' => $light, 'dark' => $dark);
    }
```

- [ ] **Step 4: Run to verify pass**

Run: `php tests/cms-theme/tokens_test.php`
Expected: `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.CmsThemeTokens.php tests/cms-theme/tokens_test.php
git commit -m "Enhancement: CMS Theme Engine — light/dark derivation + WCAG contrast

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: CSS emitter (tokens → scoped <style> string)

**Files:**
- Modify: `system/lib/ork3/class.CmsThemeTokens.php`
- Test: `tests/cms-theme/tokens_test.php`

**Interfaces:**
- Produces: `CmsThemeTokens::ToCss(array $userTokens): string` — returns the inner CSS for the `<style>` block: `.fd-page{--fd-primary:…;…} html[data-theme="dark"] .fd-page{…}`. Font tokens emitted as quoted family lists with a sensible fallback. Pure, no DB.

- [ ] **Step 1: Add failing tests**

```php
$css = CmsThemeTokens::ToCss(array('--fd-primary' => '#1b4d3e'));
check('emits .fd-page scope', strpos($css, '.fd-page{') !== false);
check('emits dark scope', strpos($css, 'html[data-theme="dark"] .fd-page{') !== false);
check('emits primary var', strpos($css, '--fd-primary:#1b4d3e') !== false);
check('font emitted with fallback', strpos($css, "--fd-font-body:'Open Sans'") !== false);
check('no raw braces injection from value', substr_count($css, '}') === 2);
```

- [ ] **Step 2: Run to verify fail**

Run: `php tests/cms-theme/tokens_test.php` → FAIL (undefined `ToCss`).

- [ ] **Step 3: Implement ToCss**

```php
// add inside class CmsThemeTokens

    private static function FontStack($family)
    {
        $fallback = ($family === 'MedievalSharp') ? 'cursive'
            : (($family === 'Georgia') ? 'serif'
            : (($family === 'system-ui') ? 'sans-serif' : "'Open Sans', sans-serif"));
        return ($family === 'system-ui') ? 'system-ui, sans-serif' : "'" . $family . "', " . $fallback;
    }

    private static function Block($selector, $tokens)
    {
        $parts = array();
        foreach ($tokens as $k => $v) {
            if ($k === '--fd-font-heading' || $k === '--fd-font-body') {
                $v = self::FontStack($v);
            } elseif ($k === '--fd-font-scale') {
                $v = 'calc(1rem * ' . $v . ')';
            }
            $parts[] = $k . ':' . $v;
        }
        return $selector . '{' . implode(';', $parts) . '}';
    }

    public static function ToCss($userTokens)
    {
        $d = self::Derive($userTokens);
        return self::Block('.fd-page', $d['light'])
            . ' ' . self::Block('html[data-theme="dark"] .fd-page', $d['dark']);
    }
```

Note: validation (Task 2) guarantees values contain no `;`/`{`/`}`, so emission is injection-safe.

- [ ] **Step 4: Run to verify pass** → `ALL PASS`.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.CmsThemeTokens.php tests/cms-theme/tokens_test.php
git commit -m "Enhancement: CMS Theme Engine — scoped CSS emitter

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 5: CmsTheme DB model + thin orkui model

**Files:**
- Create: `system/lib/ork3/class.CmsTheme.php`
- Create: `orkui/model/model.CmsTheme.php`

**Interfaces:**
- Consumes: `CmsThemeTokens::*`, `CmsBase::_firstRow/_eachRow/_normalizeScopeType`, global `$DB` (YapoDb).
- Produces (lib, CamelCase): `GetActiveTheme($scopeType,$scopeId): ?array`, `GetActiveCss($scopeType,$scopeId): string`, `SaveTheme($scopeType,$scopeId,$name,$tokens,$uid): int`, `SetActive($scopeType,$scopeId,$id): bool`, `ResetActive($scopeType,$scopeId): bool`. Thin model (snake_case) mirrors these.

**YapoDb idiom (CRITICAL — match `class.CmsPage.php` exactly):** this codebase does NOT use positional `?` placeholders. The shared global `$DB` (YapoDb) uses **named placeholders `:field` in the SQL string, bound via `$DB->field = value` after `$DB->Clear()`**. Tables are prefixed with the `DB_PREFIX` constant (so the table is `DB_PREFIX . 'cms_theme'`). `$DB->DataSet($sql)` runs SELECTs (consume via `$this->_firstRow()`/`_eachRow()`); `$DB->Execute($sql)` runs writes. `GetLastInsertId()` is unreliable under ERRMODE_WARNING — after an INSERT, **read the row back by its unique tuple** (see `CmsPage::CreatePage`). The code below already follows this idiom; reproduce it precisely.

- [ ] **Step 1: Apply the migration locally**

Run (container `ork3app`, DB `ork`, user `ork`; password in `.dev.env` as `MYSQL_PASSWORD`):
```bash
docker compose -f docker-compose.php8.yml exec -T ork3app sh -lc \
  'mysql -uork -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" < /var/www/db-migrations/2026-06-27-cms-theme.sql'
```
Expected: no error. Verify:
```bash
docker compose -f docker-compose.php8.yml exec -T ork3app sh -lc \
  'mysql -uork -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "SHOW TABLES LIKE \"ork_cms_theme\""'
```
Expected: one row (`ork_cms_theme`). NOTE: confirm `DB_PREFIX` resolves to `ork_` (grep `define('DB_PREFIX'` in the codebase); the migration hardcodes `ork_cms_theme`, so the lib must build `DB_PREFIX . 'cms_theme'` to match.

- [ ] **Step 2: Implement the lib model**

```php
<?php
// system/lib/ork3/class.CmsTheme.php
// DB persistence for CMS theme token sets. Pure computation is delegated to
// CmsThemeTokens; this class only reads/writes <prefix>cms_theme.
//
// DB idiom (matches class.CmsPage.php): shared global $DB (YapoDb); always
// Clear() before a raw DataSet()/Execute(); bind values via $DB->field = ...
// (the SQL uses :field named placeholders). lastInsertId() is unreliable on
// dup-key under ERRMODE_WARNING, so INSERTs read back by the unique tuple.

require_once __DIR__ . '/class.CmsThemeTokens.php';

class CmsTheme extends CmsBase
{
    public function __construct()
    {
        parent::__construct();
    }

    /** Active theme row for a scope, or null. tokens_json decoded to 'tokens'. */
    public function GetActiveTheme($scopeType = 'global', $scopeId = 0)
    {
        global $DB;
        $scopeType = $this->_normalizeScopeType($scopeType);
        $scopeId   = (int)$scopeId;

        $DB->Clear();
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT id, name, tokens_json, is_active FROM ' . DB_PREFIX . 'cms_theme'
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id AND is_active = 1 LIMIT 1'
        ));
        if ($row === null) {
            return null;
        }
        $row['tokens'] = json_decode((string)(isset($row['tokens_json']) ? $row['tokens_json'] : ''), true);
        if (!is_array($row['tokens'])) {
            $row['tokens'] = array();
        }
        return $row;
    }

    /** The <style> inner CSS for the active theme, or '' when none. */
    public function GetActiveCss($scopeType = 'global', $scopeId = 0)
    {
        $t = $this->GetActiveTheme($scopeType, $scopeId);
        if ($t === null) {
            return '';
        }
        return CmsThemeTokens::ToCss($t['tokens']);
    }

    /**
     * Upsert a theme by (scope,name); returns its id (>0) or 0 on failure.
     * Stores only validated tokens. Does NOT change active state.
     */
    public function SaveTheme($scopeType, $scopeId, $name, $tokens, $uid)
    {
        global $DB;
        $scopeType = $this->_normalizeScopeType($scopeType);
        $scopeId   = (int)$scopeId;
        $name      = trim((string)$name);
        if ($name === '') {
            $name = 'Default';
        }
        $json = json_encode(CmsThemeTokens::Validate($tokens));
        $uid  = (int)$uid;

        // Existing (scope,name) → UPDATE in place.
        $DB->Clear();
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $DB->name       = $name;
        $existing = $this->_firstRow($DB->DataSet(
            'SELECT id FROM ' . DB_PREFIX . 'cms_theme'
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id AND name = :name LIMIT 1'
        ));
        if ($existing !== null) {
            $id = (int)$existing['id'];
            $DB->Clear();
            $DB->tokens_json = $json;
            $DB->updated_by  = $uid;
            $DB->id          = $id;
            $DB->Execute(
                'UPDATE ' . DB_PREFIX . 'cms_theme'
                . ' SET tokens_json = :tokens_json, updated_by = :updated_by WHERE id = :id'
            );
            return $id;
        }

        // INSERT, then read back by the unique (scope,name) tuple.
        $DB->Clear();
        $DB->scope_type  = $scopeType;
        $DB->scope_id    = $scopeId;
        $DB->name        = $name;
        $DB->tokens_json = $json;
        $DB->updated_by  = $uid;
        $DB->Execute(
            'INSERT INTO ' . DB_PREFIX . 'cms_theme'
            . ' (scope_type, scope_id, name, tokens_json, updated_by, is_active)'
            . ' VALUES (:scope_type, :scope_id, :name, :tokens_json, :updated_by, 0)'
        );

        $DB->Clear();
        $DB->scope_type = $scopeType;
        $DB->scope_id   = $scopeId;
        $DB->name       = $name;
        $row = $this->_firstRow($DB->DataSet(
            'SELECT id FROM ' . DB_PREFIX . 'cms_theme'
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id AND name = :name LIMIT 1'
        ));
        return $row ? (int)$row['id'] : 0;
    }

    /** Make one theme active for its scope (deactivating siblings). */
    public function SetActive($scopeType, $scopeId, $id)
    {
        global $DB;
        $DB->Clear();
        $DB->id         = (int)$id;
        $DB->scope_type = $this->_normalizeScopeType($scopeType);
        $DB->scope_id   = (int)$scopeId;
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_theme'
            . ' SET is_active = IF(id = :id, 1, 0)'
            . ' WHERE scope_type = :scope_type AND scope_id = :scope_id'
        );
        return true;
    }

    /** Deactivate all themes for a scope (revert to CSS defaults). */
    public function ResetActive($scopeType, $scopeId)
    {
        global $DB;
        $DB->Clear();
        $DB->scope_type = $this->_normalizeScopeType($scopeType);
        $DB->scope_id   = (int)$scopeId;
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'cms_theme'
            . ' SET is_active = 0 WHERE scope_type = :scope_type AND scope_id = :scope_id'
        );
        return true;
    }
}
```

Note: this mirrors `class.CmsPage.php`'s YapoDb idiom exactly (named `:field` placeholders, `$DB->field =` binds, `DB_PREFIX`, read-back-after-insert). Before finishing, open `class.CmsPage.php` and confirm the method casing (`Clear`/`DataSet`/`Execute`) and `DB_PREFIX` usage still match; adjust if the codebase differs.

- [ ] **Step 3: Implement the thin model**

```php
<?php
// orkui/model/model.CmsTheme.php — thin pass-through to the CmsTheme lib.
class Model_CmsTheme extends Model
{
    public function __construct()
    {
        parent::__construct();
        $this->CmsTheme = new APIModel('CmsTheme');
    }

    public function get_active_theme($scopeType = 'global', $scopeId = 0)
    {
        return $this->CmsTheme->GetActiveTheme($scopeType, $scopeId);
    }
    public function get_active_css($scopeType = 'global', $scopeId = 0)
    {
        return $this->CmsTheme->GetActiveCss($scopeType, $scopeId);
    }
    public function save_theme($scopeType, $scopeId, $name, $tokens, $uid)
    {
        return $this->CmsTheme->SaveTheme($scopeType, $scopeId, $name, $tokens, $uid);
    }
    public function set_active($scopeType, $scopeId, $id)
    {
        return $this->CmsTheme->SetActive($scopeType, $scopeId, $id);
    }
    public function reset_active($scopeType, $scopeId)
    {
        return $this->CmsTheme->ResetActive($scopeType, $scopeId);
    }
}
```

- [ ] **Step 4: Smoke-test persistence**

Run (adjust DB access as in Step 1):
```bash
docker compose -f docker-compose.php8.yml exec -T ork3app php -r '
  require "/var/www/system/lib/ork3/class.CmsThemeTokens.php";
  echo CmsThemeTokens::ToCss(["--fd-primary"=>"#1b4d3e"]), "\n";
'
```
Expected: prints the `.fd-page{…}` CSS (confirms class loads inside the container). Full DB round-trip is exercised via the AJAX endpoint in Task 8.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.CmsTheme.php orkui/model/model.CmsTheme.php
git commit -m "Enhancement: CMS Theme Engine — CmsTheme persistence model

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Add `theme.manage` capability

**Files:**
- Modify: `system/lib/ork3/class.CmsAuth.php:42` (admin increment), `:48` (admin-bridge set)

**Interfaces:**
- Produces: `CmsAuth->cms_can($uid, 'theme.manage', $scope)` resolves true for admins/super-admins (and, for kingdom/park scopes, AUTH_ADMIN officers via the existing bridge).

- [ ] **Step 1: Add to admin increment**

In `class.CmsAuth.php`, change the admin increment:
```php
'admin'       => array('page.delete', 'nav.manage', 'roles.manage', 'theme.manage'),
```

- [ ] **Step 2: Add to admin-bridge caps**

Change `$ADMIN_BRIDGE_CAPS`:
```php
private static $ADMIN_BRIDGE_CAPS = array(
    'page.publish', 'page.delete', 'roles.manage', 'nav.manage', 'theme.manage',
);
```

- [ ] **Step 3: Verify**

Run (adjust DB access):
```bash
docker compose -f docker-compose.php8.yml exec -T ork3app php -r '
  // confirm the literal appears in both arrays
' ; grep -n "theme.manage" system/lib/ork3/class.CmsAuth.php
```
Expected: two matches (increment + bridge).

- [ ] **Step 4: Commit**

```bash
git add system/lib/ork3/class.CmsAuth.php
git commit -m "Enhancement: CMS Theme Engine — theme.manage capability

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Render-path injection (front door reads the active theme)

**Files:**
- Modify: `system/lib/system/class.Controller.php` (add helper `_attachFrontDoorTheme()`; call it in `index()` ~L230)
- Modify: `orkui/controller/controller.Page.php` (`view()` — renders `.fd-page` content, sets `IsFrontDoor=false`)
- Modify: `orkui/controller/controller.Blog.php` (`post()` — renders `.fd-page` content)
- Modify: `orkui/template/default/default.theme` (emit `<style id="fd-theme-tokens">` at END OF BODY)

**Interfaces:**
- Consumes: `Model_CmsTheme->get_active_css('global', 0)`.
- Produces: `$this->data['fdThemeCss']` (string CSS or unset); `default.theme` renders it when non-empty.

**CRITICAL — cascade/source-order facts (already verified):**
- `frontdoor.css` (which will declare the `.fd-page { --fd-*: default }` tokens in Task 9) is `<link>`ed in the **body** content templates (`_index.tpl:12`, `Page_view.tpl:12`, `Blog_post.tpl:22`, `Cms_preview.tpl`, `Blog_index.tpl`), NOT in `<head>`. So a theme `<style>` placed in `<head>` would load BEFORE `frontdoor.css` and be overridden by its equal-specificity `.fd-page` defaults. The theme block MUST be emitted AFTER the body content — emit it at **end of `<body>`** (just before `</body>`), so source order makes the theme win for both light and dark.
- Only base `Controller::index()` sets `IsFrontDoor=true`. `Page::view()` and `Blog::post()` render `.fd-page` content but set `IsFrontDoor=false`. The theme emission is gated on `$fdThemeCss` (NOT `IsFrontDoor`), so the helper must be called in ALL THREE controllers, or themed CMS pages/blog posts won't pick up the theme.

- [ ] **Step 1: Add the shared helper to base Controller**

```php
// in system/lib/system/class.Controller.php, near the front-door logic
/** Resolve the active front-door theme into $data['fdThemeCss'] (global scope, v1). */
protected function _attachFrontDoorTheme()
{
    $this->load_model('CmsTheme');
    $css = (string) $this->CmsTheme->get_active_css('global', 0);
    if ($css !== '') {
        $this->data['fdThemeCss'] = $css;
    }
}
```

- [ ] **Step 2: Call it from `index()`**

Immediately after `$this->data['IsFrontDoor'] = true;` in `index()` (~L230), add:
```php
$this->_attachFrontDoorTheme();
```

- [ ] **Step 3: Call it from Page::view() and Blog::post()**

In `controller.Page.php::view()`, after the line `$this->data['FrontDoor'] = $this->CmsPage->get_page_blocks(...)` (where it populates the block list), add:
```php
$this->_attachFrontDoorTheme();
```
In `controller.Blog.php::post()`, after the line `$this->data['post_blocks'] = is_array($blocks) ? $blocks : array();`, add:
```php
$this->_attachFrontDoorTheme();
```
(Both controllers extend the base `Controller`, so the protected helper is in scope. Add it on every return path that renders blocks — but NOT before an early `not-found`/redirect return.)

- [ ] **Step 4: Emit the style block at END OF BODY in default.theme**

In `orkui/template/default/default.theme`, immediately BEFORE the closing `</body>` (line ~891, after the existing `cms-fab-stack` block), add:
```php
<?php if (!empty($fdThemeCss)): ?>
<style id="fd-theme-tokens"><?= $fdThemeCss ?></style>
<?php endif; ?>
```
Rationale: end-of-body places this AFTER the body-linked `frontdoor.css`, so the theme overrides win by source order. `$fdThemeCss` is built only from validated tokens (Task 2) — no user HTML/CSS can reach it — so emitting unescaped is safe. Do NOT also emit it in `<head>` (would be overridden).

- [ ] **Step 5: Verify (no active theme → no regression; active theme → applies)**

Run: load `http://localhost:19080/orkui/` with NO active theme; confirm the front door renders normally and `#fd-theme-tokens` is ABSENT. Then activate a row directly (DB is the `ork3db` MariaDB container; password from `.dev.env`):
```bash
docker compose -f docker-compose.php8.yml exec -T ork3db sh -lc \
  'mariadb -uork -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "INSERT INTO ork_cms_theme (scope_type,scope_id,name,tokens_json,is_active,updated_by) VALUES (\"global\",0,\"Test\",\"{\\\"--fd-primary\\\":\\\"#1b4d3e\\\"}\",1,0)"'
```
Reload — confirm `<style id="fd-theme-tokens">` is present near `</body>` and contains `.fd-page{--fd-primary:#1b4d3e…}`, AND that the front-door primary color actually changed (inspect a primary-colored element's computed style → the theme value, not `#0b1120`). This proves the cascade order is correct. Also verify a CMS page (`Page/view`) and a blog post pick it up. Then delete the test row:
```bash
docker compose -f docker-compose.php8.yml exec -T ork3db sh -lc \
  'mariadb -uork -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE" -e "DELETE FROM ork_cms_theme WHERE name=\"Test\""'
```
(NOTE: the `.fd-page` token defaults don't exist until Task 9 tokenizes `frontdoor.css`. Before Task 9, you can still confirm the `<style>` block is EMITTED in the right place and contains the expected CSS; the visible color change is fully verifiable after Task 9. State which you confirmed in your report.)

- [ ] **Step 6: Commit**

```bash
git add system/lib/system/class.Controller.php orkui/template/default/default.theme orkui/controller/controller.Page.php orkui/controller/controller.Blog.php
git commit -m "Enhancement: CMS Theme Engine — inject active theme at end of front-door body

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: CmsAjax mutation endpoints

**Files:**
- Modify: `orkui/controller/controller.CmsAjax.php` (constructor `load_model('CmsTheme')`; add 4 actions)
- Modify: `system/lib/ork3/class.CmsTheme.php` (add `PreviewCss($tokens)` passthrough to the pure emitter)
- Modify: `orkui/model/model.CmsTheme.php` (add `preview_css($tokens)` thin forward)

**Interfaces:**
- Consumes: `_begin()`, `_require($uid,'theme.manage')`, `_ok()/_fail()`, `$this->CmsTheme->*`.
- Produces endpoints: `savetheme` (POST), `activatetheme` (POST), `resettheme` (POST), `previewtheme` (POST, echoes CSS, no persistence).

**Architecture-layers rule:** controllers must NOT reference `system/lib` classes (e.g. `CmsThemeTokens`) directly — go through the model. So `previewtheme` calls `$this->CmsTheme->preview_css($tokens)`, and the lib wraps the pure emitter. Add these two one-liners first.

- [ ] **Step 1a: Add `PreviewCss` to the lib model**

In `system/lib/ork3/class.CmsTheme.php`, add:
```php
    /** Resolve arbitrary tokens to the <style> inner CSS WITHOUT persisting (live preview). */
    public function PreviewCss($tokens)
    {
        return CmsThemeTokens::ToCss(is_array($tokens) ? $tokens : array());
    }
```

- [ ] **Step 1b: Add `preview_css` to the thin model**

In `orkui/model/model.CmsTheme.php`, add:
```php
    public function preview_css($tokens)
    {
        return $this->CmsTheme->PreviewCss($tokens);
    }
```

- [ ] **Step 1c: Load the model in the controller constructor**

In `Controller_CmsAjax::__construct`, after `$this->load_model('CmsNav');` add:
```php
        $this->load_model('CmsTheme');
```

- [ ] **Step 2: Add the endpoints**

```php
    /* ------------------------------------------------------------------ *
     * theme engine
     * ------------------------------------------------------------------ */

    /** POST: validate+persist tokens (draft) under the global scope. */
    public function savetheme($action = null)
    {
        $uid = $this->_begin();
        $this->_require($uid, 'theme.manage');
        $tokens = $this->_themeTokensFromPost();
        $name   = trim((string)($_POST['name'] ?? 'Default'));
        $id = (int)$this->CmsTheme->save_theme('global', 0, $name, $tokens, $uid);
        if ($id <= 0) {
            $this->_fail('Could not save the theme.');
        }
        $this->_ok(array('theme_id' => $id, 'saved_at' => date('c')));
    }

    /** POST: activate a theme id for the global scope. */
    public function activatetheme($action = null)
    {
        $uid = $this->_begin();
        $this->_require($uid, 'theme.manage');
        $id = (int)($_POST['theme_id'] ?? 0);
        if ($id <= 0) {
            $this->_fail('Missing theme id.', 4);
        }
        $this->CmsTheme->set_active('global', 0, $id);
        $this->_ok(array('active' => $id));
    }

    /** POST: deactivate all themes (revert to CSS defaults). */
    public function resettheme($action = null)
    {
        $uid = $this->_begin();
        $this->_require($uid, 'theme.manage');
        $this->CmsTheme->reset_active('global', 0);
        $this->_ok();
    }

    /** POST: echo resolved CSS for the live preview (no persistence). */
    public function previewtheme($action = null)
    {
        $uid = $this->_begin();
        $this->_require($uid, 'theme.manage');
        $tokens = $this->_themeTokensFromPost();
        $css = (string)$this->CmsTheme->preview_css($tokens);
        $this->_ok(array('css' => $css));
    }

    /** Decode posted tokens JSON into an assoc array (validation happens in the lib). */
    private function _themeTokensFromPost()
    {
        $raw = $_POST['tokens'] ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : array();
    }
```

Note: `previewtheme` goes controller → model (`preview_css`) → lib (`PreviewCss`) → pure `CmsThemeTokens::ToCss`, keeping the controller free of direct `system/lib` references (architecture-layers rule). Validation still happens inside the lib (`ToCss`→`Derive`→`Validate`), so the echoed CSS is always sanitized.

- [ ] **Step 3: Verify each endpoint with curl**

Run (logged-in admin session cookie + CSRF token required; grab `window.CMS_CSRF` from a CMS page). Example:
```bash
curl -s -X POST 'http://localhost:19080/orkui/index.php?Route=CmsAjax/previewtheme' \
  -H "X-CSRF-Token: $TOKEN" -b "$COOKIEJAR" \
  --data-urlencode 'tokens={"--fd-primary":"#1b4d3e"}'
```
Expected: `{"ok":true,"css":".fd-page{--fd-primary:#1b4d3e;…"}`. Repeat for `savetheme` (returns `theme_id`), `activatetheme`, `resettheme`. A POST without the header must return `{"ok":false,"status":9,...}`.

- [ ] **Step 4: Commit**

```bash
git add orkui/controller/controller.CmsAjax.php system/lib/ork3/class.CmsTheme.php orkui/model/model.CmsTheme.php
git commit -m "Enhancement: CMS Theme Engine — CmsAjax save/activate/preview/reset endpoints

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: Tokenize the front-door CSS (zero-visual-change)

**Files:**
- Modify: `orkui/template/default/frontdoor/css/frontdoor.css`

**Interfaces:**
- Produces: `--fd-*` token defaults declared on `.fd-page`; all front-door color/font/shape rules reference `var(--fd-*)`. The existing `--navy`/`--gold`/`--ink` vars are redefined in terms of the new tokens (kept as aliases to avoid touching every usage at once).

- [ ] **Step 1: Declare the token catalog on `.fd-page`**

In `frontdoor.css`, replace the existing `.fd-page { --navy; --navy2; --gold; --ink; … }` palette block with:
```css
.fd-page {
    /* Theme tokens (overridable by the theme engine) */
    --fd-primary: #0b1120;
    --fd-accent:  #f0b429;
    --fd-bg:      #ffffff;
    --fd-surface: #f7f8fa;
    --fd-text:    #1a2236;
    --fd-text-muted: #5b6472;
    --fd-border:  #e2e6ec;
    --fd-primary-contrast: #ffffff;
    --fd-font-heading: 'MedievalSharp', Georgia, serif;
    --fd-font-body: system-ui, -apple-system, sans-serif;
    --fd-font-scale: 1rem;
    --fd-radius: 12px;
    --fd-space: 1;
    --fd-border-width: 1px;
    --fd-shadow: 0 12px 50px rgba(0, 0, 0, .4);

    /* Legacy aliases (existing rules still reference these) */
    --navy:  var(--fd-primary);
    --navy2: #121a30;
    --gold:  var(--fd-accent);
    --ink:   var(--fd-text);

    font-family: var(--fd-font-body);
    color: var(--fd-text);
    border-radius: var(--fd-radius);
    overflow: hidden;
    box-shadow: var(--fd-shadow);
}
```

**ZERO-VISUAL-CHANGE — font defaults MUST match current literals.** The CSS token
DEFAULTS above reproduce today's front-door exactly: body `system-ui, -apple-system,
sans-serif` (current `.fd-page` line 12), headings `'MedievalSharp', Georgia, serif`
(current lines 39/54), navy `#0b1120`, gold `#f0b429`, ink `#1a2236`, radius `12px`,
shadow `0 12px 50px rgba(0,0,0,.4)`. Do NOT use `'Open Sans'`/`cursive` as the CSS
defaults — that would silently restyle the unthemed site. (Note: the editor's token
CATALOG default for body is `Open Sans` — intentionally different. That only takes
effect once a theme is ACTIVE, i.e. theming a site adopts Open Sans for the body; the
UNTHEMED baseline this task must preserve stays `system-ui`. Confirm every other
replaced value's default equals the literal it replaced.)

- [ ] **Step 2: Point headings + key surfaces at tokens**

Sweep `frontdoor.css` for hardcoded values that should track the theme and replace with `var(--fd-*)`:
- heading `font-family` → `var(--fd-font-heading)`
- panel/card backgrounds `#fff`/`#f7f8fa` → `var(--fd-surface)` / `var(--fd-bg)`
- body text colors `#1a2236`/`#5b6472` → `var(--fd-text)` / `var(--fd-text-muted)`
- borders → `var(--fd-border-width) solid var(--fd-border)`
- `border-radius` on cards/buttons → `var(--fd-radius)`
- accent/CTA backgrounds (gold) → `var(--fd-accent)` with text `var(--fd-primary-contrast)`

Leave decorative one-offs (gradients, hero imagery) as-is for v1.

- [ ] **Step 3: Fold the existing dark block into tokens**

The `html[data-theme="dark"] .fd-*` rules (frontdoor.css ~L395+) that merely set dark colors are now redundant for tokenized properties (the engine's derived dark block, or the default derivation, supplies them). For the UNTHEMED default, add a baseline dark override of the tokens so default dark still looks right:
```css
html[data-theme="dark"] .fd-page {
    --fd-bg: #0e1626;
    --fd-surface: #16203a;
    --fd-border: #243049;
    --fd-text: #e8ecf1;
    --fd-text-muted: #aab3c0;
    --fd-primary: #3b62c2;
    --fd-accent: #f0b429;
}
```
Remove now-redundant per-element dark color rules ONLY where the element already reads the token; keep any structural/non-color dark rules.

- [ ] **Step 4: Verify zero visual change (light + dark, unthemed)**

Run: load `http://localhost:19080/orkui/` with NO active theme. Compare against pre-change (git stash / screenshot) in BOTH light and dark. Expected: visually identical. Iterate until pixel-equivalent for the main hero/cards/nav.

- [ ] **Step 5: Commit**

```bash
git add orkui/template/default/frontdoor/css/frontdoor.css
git commit -m "Enhancement: CMS Theme Engine — tokenize front-door stylesheet

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: Tokenize the block partials

**Files:**
- Modify: `orkui/template/default/frontdoor/blocks/*.tpl` (only those with inline `style=` or hardcoded colors)

**Interfaces:**
- Produces: block partials free of hardcoded theme colors; visual output driven by `frontdoor.css` token classes.

- [ ] **Step 1: Find offenders**

Run:
```bash
grep -rln 'style="' orkui/template/default/frontdoor/blocks/
grep -rln '#[0-9a-fA-F]\{3,6\}' orkui/template/default/frontdoor/blocks/
```

- [ ] **Step 2: Replace ONLY exact-match colors with tokens (zero visual change)**

**THE RULE (learned from Task 9's dark-mode regressions): replace a hardcoded color with `var(--fd-x)` ONLY when the token's DEFAULT value equals that color EXACTLY.** A near-match is a regression — leave it. Never introduce new hardcoded hex. Keep all structural inline styles (widths, grid, margins, font-size, opacity) untouched.

Safe exact-match mappings (token default → replace these literals):

| Literal in partial | Replace with | Why safe |
|---|---|---|
| `#1a2236` (body text) | `var(--fd-text)` | default `#1a2236` ✓ |
| `#f0b429` (gold/accent) | `var(--fd-accent)` | default `#f0b429` ✓ |
| `#fff` / `#ffffff` as a CONTRAST text color on a dark/navy band (e.g. `color:#fff` on `background:var(--navy)`) | `var(--fd-primary-contrast)` | default `#ffffff` ✓, and it derives correctly per-theme |
| `#fff` / `#ffffff` as a panel/card BACKGROUND | `var(--fd-bg)` | default `#ffffff` ✓ |
| `#0b1120` (navy band) | `var(--fd-primary)` | default `#0b1120` ✓ |

LEAVE AS-IS (no exact token match → would change appearance): `#f7f8fb`, `#f7f8fa` ambiguity, `#eef2fb`, `#667`, `#9aa7c4`, gradients, decorative/hero one-offs, and anything whose role you're unsure of. Conservatism is correct — a missed tokenization is a future enhancement; a wrong replacement is a visible bug. Note: many partials ALREADY use `var(--navy)`/`var(--gold)` aliases — those already track the theme via Task 9; don't touch them.

DARK-MODE TRAP (from Task 9): if you change an element to use `var(--fd-text)`/`var(--fd-primary)` and that element is rendered on a fixed-color band WITHOUT its own `html[data-theme="dark"]` rule, the dark token override may change it in dark mode. After each change, check whether the element needs a dark cover rule. When in doubt, leave it.

Prefer editing the inline `style="…"` in place (e.g. `color:#1a2236` → `color:var(--fd-text)`) rather than moving to a class — these partials are plain-PHP `.tpl` (NOT Smarty); keep edits minimal and local.

- [ ] **Step 3: Verify zero visual change in the browser (light + dark, unthemed)**

There is NO active theme (table empty), so the page reflects only CSS/inline defaults. Load `http://localhost:19080/orkui/` (home uses many blocks) plus a CMS page and a blog post. In BOTH light and dark, confirm every touched block looks identical to before (git stash the partials to compare, or screenshot). Pay special attention to white-on-band text and any element you re-pointed at a token — verify it didn't shift in dark mode. List exactly which partials you changed and what you verified.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/default/frontdoor/blocks/
git commit -m "Enhancement: CMS Theme Engine — tokenize front-door block partials

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 11: Theme editor page (controller action + template + rail link)

**Files:**
- Modify: `orkui/controller/controller.Cms.php` (add `theme` action)
- Create: `orkui/template/default/Cms_theme.tpl`
- Modify: `orkui/template/default/cms/_shell_top.tpl:52` (add Theme rail item)
- Modify: `orkui/template/default/style/cms-admin.css` (editor styles, light + dark)

**Interfaces:**
- Consumes: `CmsAuth->cms_can($uid,'theme.manage',SCOPE)`, `CmsTheme->get_active_theme`, `CmsThemeTokens::Defaults/FontAllowlist/DefaultValues`, `_csrfToken()`.
- Produces: `Cms/theme` page rendering the editor with `window.CMS_CSRF`, the token catalog as JS data, and the active theme values.

- [ ] **Step 1: Add the controller action**

```php
    /** Theme engine editor (global scope, v1). */
    public function theme($action = null)
    {
        $uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
        if (!$this->CmsAuth->cms_can($uid, 'theme.manage', self::$SCOPE)) {
            return $this->_denyRedirect();
        }
        $this->load_model('CmsTheme');
        require_once DIR_LIB . 'ork3/class.CmsThemeTokens.php'; // adjust const to match codebase
        $active = $this->CmsTheme->get_active_theme('global', 0);

        $this->data['CmsCsrf']      = $this->_csrfToken();
        $this->data['ThemeCatalog'] = CmsThemeTokens::Defaults();
        $this->data['ThemeFonts']   = CmsThemeTokens::FontAllowlist();
        $this->data['ThemeValues']  = array_merge(
            CmsThemeTokens::DefaultValues(),
            ($active['tokens'] ?? array())
        );
        $this->data['ThemeActiveId'] = (int)($active['id'] ?? 0);
        $this->data['cmsActive']     = 'theme';
        $this->_render('Cms_theme');   // match how other Cms actions render (verify helper name)
    }
```
Note: confirm the render mechanism + `DIR_LIB` constant by reading another `Cms` action (e.g. `nav`/`media`); mirror it exactly.

- [ ] **Step 2: Add the rail item**

In `cms/_shell_top.tpl`, after the `nav` item (L52), add:
```php
    array('theme',     'Theme',      UIR . 'Cms/theme',     'fa-palette',    !empty($shCaps['theme'])),
```
And ensure `$shCaps['theme']` is populated where `$shCaps` is built (mirror how `media`/`nav` caps are computed — `cms_can($uid,'theme.manage',$scope)`).

- [ ] **Step 3: Build the editor template (plain PHP)**

Create `Cms_theme.tpl` including the shared shell (`cms/_shell_top.tpl` / `_shell_bottom.tpl` as other `Cms_*.tpl` do), with:
- Left control rail: iterate `$ThemeCatalog` grouped by `group` (color/type/shape); render `<input type="color">` for colors, `<select>` (from `$ThemeFonts`) for fonts, `<input type="range">`/number for scale/px, `<select>` for shadow. Each control carries `data-token="--fd-…"`. Seed values from `$ThemeValues`.
- A few preset buttons (`data-preset` with a small inline JSON of token overrides).
- An "Advanced — all tokens" `<details>` exposing every token raw (same inputs, ungrouped).
- Right: `<iframe id="fd-theme-preview" src="<?= UIR ?>">` + a light/dark toggle + a contrast-warning area.
- Footer actions: Save, Activate, Reset.
- Emit `window.CMS_CSRF` and `window.THEME_VALUES`/`window.THEME_CATALOG` as JSON via `json_encode(..., JSON_HEX_TAG)`.

Follow the dark-mode checklist (`html[data-theme="dark"]`): style labels, ghost buttons, inputs, the iframe frame, and the advanced disclosure for dark.

- [ ] **Step 4: Verify the page loads + is gated**

Run: visit `http://localhost:19080/orkui/index.php?Route=Cms/theme` as an admin → editor renders with controls seeded. As a non-admin (or logged out) → redirected to login. Confirm dark mode looks correct.

- [ ] **Step 5: Commit**

```bash
git add orkui/controller/controller.Cms.php orkui/template/default/Cms_theme.tpl orkui/template/default/cms/_shell_top.tpl orkui/template/default/style/cms-admin.css
git commit -m "Enhancement: CMS Theme Engine — theme editor page + rail entry

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 12: Live preview, contrast warnings, and wire the actions

**Files:**
- Modify: `orkui/template/default/Cms_theme.tpl` (inline `<script>` or a sibling JS), `orkui/template/default/style/cms-admin.css`

**Interfaces:**
- Consumes: `CmsAjax/previewtheme`, `CmsAjax/savetheme`, `CmsAjax/activatetheme`, `CmsAjax/resettheme`; `window.CMS_CSRF`, `window.THEME_CATALOG`.
- Produces: live-updating preview iframe + Save/Activate/Reset behavior + inline contrast warnings.

- [ ] **Step 1: Collect tokens + debounced preview**

Add JS that, on any control `input`/`change`:
1. Reads all `[data-token]` controls into a `tokens` object (skipping values equal to default — optional).
2. POSTs `tokens` (JSON) to `CmsAjax/previewtheme` with the `X-CSRF-Token` header (debounced ~150ms).
3. Injects the returned `css` into the preview iframe by writing/replacing a `<style id="fd-theme-preview-style">` in the iframe document head:
```js
function applyPreview(css) {
  var doc = document.getElementById('fd-theme-preview').contentDocument;
  if (!doc) return;
  var s = doc.getElementById('fd-theme-preview-style');
  if (!s) { s = doc.createElement('style'); s.id = 'fd-theme-preview-style'; doc.head.appendChild(s); }
  s.textContent = css;
}
```
(Preview iframe is same-origin → contentDocument is accessible.)

- [ ] **Step 2: Light/dark preview toggle**

Toggle sets `data-theme` on the iframe document's `<html>`:
```js
document.getElementById('fd-theme-preview').contentDocument.documentElement
  .setAttribute('data-theme', mode); // 'dark' | 'light'
```
So the user sees the auto-derived dark variant from the same `css` (which already contains the `html[data-theme="dark"] .fd-page{…}` block).

- [ ] **Step 3: Inline contrast warnings**

Client-side, replicate the WCAG contrast check on the key pairs (text/bg, accent/primary-contrast) and show a soft inline warning chip near the offending color control when ratio < 4.5 (text) / < 3 (large). Use the same luminance formula as the server (port the small JS). Non-blocking — server still nudges derived values.

- [ ] **Step 4: Wire Save / Activate / Reset**

- Save → POST `savetheme` (`name=Default`, `tokens=…`) → on `ok`, stash `theme_id`.
- Activate → ensure saved first, then POST `activatetheme` with `theme_id` → toast "Theme applied to your site."
- Reset → `tnConfirm({...})` (NEVER native confirm) → POST `resettheme` → reset controls to defaults + clear preview.
All use `window.CMS_CSRF` via `X-CSRF-Token`. Use the project toast/`tnConfirm` patterns.

- [ ] **Step 5: Verify end to end (browser)**

Run: in `Cms/theme`, drag the primary color → preview updates live; toggle dark → derived dark shows; Save then Activate → open the public front door in a new tab → themed in light AND dark. Reset → front door returns to default. Confirm a contrast warning appears for a deliberately low-contrast pick.

- [ ] **Step 6: Commit**

```bash
git add orkui/template/default/Cms_theme.tpl orkui/template/default/style/cms-admin.css
git commit -m "Enhancement: CMS Theme Engine — live preview, contrast warnings, save/activate

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 13: Full end-to-end verification + polish pass

**Files:** none (verification), small fixes as needed.

- [ ] **Step 1: Re-run the pure unit harness**

Run: `php tests/cms-theme/tokens_test.php` → `ALL PASS`.

- [ ] **Step 2: Matrix check in browser**

Verify the public front door in all four states, light + dark each:
1. No active theme (defaults) — identical to production look.
2. Active custom theme — palette/fonts/shape applied; dark auto-derived and legible.
3. Across page types that render `.fd-page` (home, a CMS page, a blog post).
4. The editor itself in dark mode (labels, buttons, inputs, iframe frame).

- [ ] **Step 3: Security spot-checks**

- POST `savetheme`/`activatetheme` without `X-CSRF-Token` → `status:9`.
- As a non-`theme.manage` user → `status:5`.
- Inject `--fd-primary = "#fff; } body{display:none}"` → value dropped by `Validate`; emitted CSS has no extra braces (confirm via `previewtheme` response).

- [ ] **Step 4: Dark-mode + tooltip + icon checklist**

Walk the editor against the project dark-mode checklist; confirm only FA5 icons (`fa-palette` ok); any tooltips use the `data-tip` pattern (no native `title`).

- [ ] **Step 5: Final commit (if fixes made)**

```bash
git add <changed files>
git commit -m "Bugfix: CMS Theme Engine — verification polish

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Notes for the implementer

- **Verify YapoDb API casing** (`Execute`/`DataSet`/`LastInsertId`/`Clear`) against `class.CmsPage.php` before writing Task 5 — match the codebase exactly rather than the illustrative names here.
- **Verify the Cms render helper + constants** (`_render`/`_denyRedirect`/`DIR_LIB`/`UIR`) by reading existing `Cms` actions; mirror them.
- **Normalize-first for PHP edits**: before multi-line Edits on tab-indented files, run `awk '/^\t/{c++} END{print c+0}' <file>`; if non-zero, run the php-cs-fixer on that one file first, then Edit.
- **Do not stage `class.Authorization.php`**; stage every file explicitly.
