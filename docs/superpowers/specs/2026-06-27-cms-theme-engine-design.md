# CMS Theme Engine — Design

**Date:** 2026-06-27
**Branch:** feature/front-door
**Status:** Approved design, pending implementation plan

## Summary

A token-based theme engine that lets CMS administrators restyle the public-facing
front-door site (colors, fonts, shape/density) without writing CSS. The user edits a
small curated set of **design tokens** (with an advanced panel exposing every raw token
value); the server stores them per scope and emits a `:root { --token: value }` block
into the page `<head>` at render time. Front-door stylesheets reference those tokens via
`var(--token)`, so reassigning them restyles the public site. Dark mode is **auto-derived**
from the single user palette — no second palette to maintain.

## Decisions (from brainstorming)

- **Control level:** Curated tokens for everyone, plus an advanced panel exposing every
  individual token as a raw value input. **No free-form custom CSS** — values only flow
  into known, named CSS custom properties (no injection surface).
- **Scope of styling:** Public CMS pages only — the front-door / marketing + CMS content
  pages. The internal ORK admin tooling and `orkui.css` are **out of scope** and untouched.
- **Token set (v1):** Colors, Typography, Shape & Density. (Hero/imagery deferred.)
- **Tenancy:** Global only for v1 (matches the CMS's current global-only reality), stored
  on the existing `scope_type`/`scope_id` convention so per-kingdom/park theming drops in
  later with no migration.
- **Dark mode:** User picks ONE light/brand palette; the engine deterministically derives
  the `[data-theme="dark"]` token values from it.

## Existing-system context (from codebase exploration)

- The CMS is a self-contained `Cms*` engine: `controller.Cms.php` (admin pages),
  `controller.CmsAjax.php` (JSON mutations), models `class.Cms*.php` extending `CmsBase`,
  tables `ork_cms_*` all carrying `scope_type enum('global','kingdom','park')` + `scope_id`.
- **CSRF:** `Controller::_csrfToken()` → `window.CMS_CSRF` (emitted by `cms/_shell_top.tpl`)
  → sent as `X-CSRF-Token` header → validated in `CmsAjax::_begin()` (POST-only, GET exempt).
- **RBAC:** `CmsAuth::$ROLE_CAPS` cumulative ladder
  contributor → author → editor → publisher → admin; `CmsAuth->CmsCan($uid,$cap,$scope)`
  with a super-admin short-circuit and a kingdom/park bridge to `Authorization->HasAuthority`.
- **Render path:** front-door is selected in base `Controller::index()`
  (`IsFrontDoor=true`); `default.theme` is the single `<html>` shell, links
  `orkui.css`, and contains the inline dark-mode `<head>` script that sets
  `document.documentElement.setAttribute('data-theme','dark')` from `localStorage`.
  Front-door content renders via `_index.tpl` → `frontdoor/css/frontdoor.css` +
  `frontdoor/render_blocks.tpl` → 28 `frontdoor/blocks/*.tpl` partials.
- **Critical constraint:** there are essentially **no CSS custom properties today** —
  colors/fonts/spacing are hardcoded hex/px, and dark mode is a parallel set of hardcoded
  `html[data-theme="dark"] .fd-*` overrides, NOT a token swap. A tokenization refactor of
  the front-door CSS is therefore a prerequisite (see §2).

## Architecture

### 1. Data model & backend

**New table `ork_cms_theme`:**

| column | type | notes |
|---|---|---|
| `id` | int PK | |
| `scope_type` | enum('global','kingdom','park') | v1 always 'global' |
| `scope_id` | int default 0 | v1 always 0 |
| `name` | varchar | |
| `is_active` | tinyint | one active theme per scope |
| `tokens_json` | JSON | only the user-set **light/base** token values |
| `updated_by` | int | mundane_id |
| `created_at`/`updated_at` | timestamps | |

Unique key `(scope_type, scope_id, name)`. Migration is idempotent
(`CREATE TABLE IF NOT EXISTS`), following the existing `db-migrations/` convention.
`tokens_json` stores ONLY values the user set; defaults and the entire dark set are derived,
never stored.

**New model `class.CmsTheme.php` (extends `CmsBase`):**
- `GetActiveTheme($scope)` — returns the active theme row, or null.
- `SaveTheme($scope, $name, $tokens, $uid)` / `SetActive($scope, $id)` /
  `DeleteTheme($scope, $id)`.
- `Defaults()` — pure: the canonical token catalog (names, groups, default values, input
  types). **Single source of truth for what tokens exist.**
- `Derive($tokens)` — pure: merges user tokens over defaults, then computes the resolved
  **light** map and the derived **dark** map. **Single source of truth for dark derivation.**
- Uses global `$DB` (YapoDb); calls `$DB->Clear()` before raw Execute per project rule.

**New AJAX endpoints on `controller.CmsAjax.php`** (each: `_begin()` login+CSRF →
`_require($uid, 'theme.manage', $SCOPE)` → JSON `{ok,...}` → exit):
- `savetheme` — upsert tokens (draft).
- `activatetheme` — set active.
- `previewtheme` — echo the resolved `:root` block for the live editor (no persistence).
- `resettheme` — clear active / revert to defaults.

**Auth:** add `theme.manage` capability to the `admin` rung of `CmsAuth::$ROLE_CAPS`.
The existing super-admin short-circuit and kingdom/park bridge already cover it for future
scopes.

### 2. Tokenization refactor (prerequisite)

Behavior-preserving conversion of front-door CSS from hardcoded values to token references.

**In scope:** `frontdoor/css/frontdoor.css`, the 28 `frontdoor/blocks/*.tpl` partials
(inline `style=` + hardcoded colors), and front-door-only inline styles in `default.theme`.
**Out of scope:** `orkui.css`, `cms-admin.css`, all internal/admin UI.

**Token catalog (v1):**
- **Colors:** `--fd-primary`, `--fd-accent`, `--fd-bg`, `--fd-surface`, `--fd-text`,
  `--fd-text-muted`, `--fd-border`, plus derived `--fd-primary-contrast`.
- **Typography:** `--fd-font-heading`, `--fd-font-body` (each one entry from a vetted
  webfont list), `--fd-font-scale` (base size / ratio).
- **Shape & density:** `--fd-radius`, `--fd-space`, `--fd-border-width`, `--fd-shadow`.

Every token gets a **default value declared in `frontdoor.css`'s own `:root`**, so an
unthemed site renders exactly as today and any missing token falls back gracefully. The
engine only overrides these defaults.

**Method:** introduce `:root` defaults, then convert one block partial at a time, diffing
the rendered front-door against current production at each step to prove zero visual change
before any theme is applied. The existing `html[data-theme="dark"] .fd-*` rules are folded
into the `Derive()` dark map (§3).

### 3. Resolution, dark derivation & injection

**Injection:** on every `IsFrontDoor` render, the controller calls
`CmsTheme->GetActiveTheme($scope)` → `Derive()` → passes resolved maps to `default.theme`,
which emits a single inline block in `<head>` adjacent to the dark-mode script:

```html
<style id="fd-theme-tokens">
  :root { --fd-primary:#1b4d3e; --fd-accent:#c9a227; --fd-radius:10px; … }
  html[data-theme="dark"] { --fd-primary:#3a8f74; --fd-surface:#1a1f1d; … }
</style>
```

No generated files, no extra request. When no active theme exists, the block is omitted and
`frontdoor.css`'s `:root` defaults stand (today's look).

**`Derive()` dark rules (pure, deterministic, server-side):**
- Surfaces/backgrounds (`--fd-bg`, `--fd-surface`) invert toward low-lightness neutrals.
- Text lifts to high lightness; `--fd-text-muted` derived for AA contrast on dark surface.
- Primary/accent keep hue/identity, lightness-nudged for legibility on dark.
- Shape/density/typography tokens are mode-agnostic — pass through unchanged.

**Contrast safety:** `Derive()` runs a WCAG contrast check on critical text/surface pairs;
if a pick would fail it nudges the **derived** value (never silently rewrites the stored
user choice) so the site stays legible in both modes. The editor also surfaces a soft
warning.

### 4. Editor UI & live preview

- **Surface:** new `theme` action on `controller.Cms.php` → `Cms_theme.tpl`, linked from the
  CMS dashboard rail. Loads `cms-admin.css`. Built dark-mode-compatible from the start
  (`html[data-theme="dark"]`), per project dark-mode checklist.
- **Layout:** left control rail + right live preview.
  - **Controls** in collapsible groups — **Colors** (palette pickers), **Typography**
    (heading/body pairing dropdown from the vetted list + size scale), **Shape & Density**
    (radius/density/border/shadow). A few **one-click starter presets** seed a good starting
    point. An **"Advanced — all tokens"** disclosure exposes every individual token as a raw
    value input (color picker per variable, steppers) — values only, no free CSS.
  - **Live preview:** iframe of the front-door home; on any change the candidate `:root{…}`
    block is injected for instant feedback, with a **light/dark toggle** so the user confirms
    the auto-derived dark variant. Inline **contrast warnings**.
- **Actions:** Save (draft), Activate, Reset to default — all POST through `CmsAjax` with the
  `X-CSRF-Token` header (`window.CMS_CSRF`).
- **Fonts:** vetted list (existing Open Sans / MedievalSharp / Lexend plus a small set of
  added webfonts, loaded with proper `<link>`/`@font-face`); no arbitrary font URLs.

## Security

- No free-form CSS or arbitrary URLs: token values flow only into named CSS custom
  properties. Color values validated against a hex/rgb pattern; font values validated against
  the vetted allowlist; numeric tokens range-clamped server-side in `Derive()`/save.
- All mutations gated by CSRF (`_begin()`) + `theme.manage` capability (`_require()`).
- `$DB->Clear()` before raw Execute; YapoSave null-skip rule observed (assign `''` not null
  to clear).

## Out of scope (v1)

- Per-kingdom / per-park theming (schema-ready, not wired live).
- Hero / imagery / background-image tokens.
- Free-form custom CSS escape hatch.
- Theming `orkui.css` / internal admin / member-facing app.
- User-supplied / two-palette dark mode (dark is always derived).

## Build sequence

1. DB migration `ork_cms_theme` + `class.CmsTheme.php` (`Defaults()`, `Derive()`, CRUD) with
   unit coverage of `Derive()` (palette → expected light+dark maps, contrast nudging).
2. Tokenization refactor of `frontdoor.css` + block partials with `:root` defaults
   (zero-visual-change verification).
3. Render-path injection in base controller + `default.theme` `<head>` block.
4. `CmsAjax` endpoints + `theme.manage` capability.
5. `Cms_theme.tpl` editor + live preview + dashboard rail link; dark-mode pass.
6. End-to-end verification in-browser (light + dark, themed + unthemed).
