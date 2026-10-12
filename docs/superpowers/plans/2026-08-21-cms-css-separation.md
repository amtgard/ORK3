# CMS / Front-Door CSS Separation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the CMS ("OGRE") a CSS layer that is physically separate from the ORK CRM's, internally DRY, and mechanically prevented from re-merging.

**Architecture:** Three tiers of CMS surface need different bases. Standalone public org sites stop loading the CRM's `orkui.css` entirely and get a new minimal `cms-base.css`. In-shell surfaces (front door, CMS pages, blog, OGRE admin) keep loading `orkui.css` but their ORK-selector overrides are quarantined into one `orkshell-interop.css` — the single legal place CMS CSS may name an ORK selector. Inline `<style>` blocks are lifted into real stylesheets, duplicate declaration bodies are collapsed by selector grouping (no class renames, so templates are untouched), and a `bin/check-css-boundaries.sh` gate plus stylelint keep it that way.

**Tech Stack:** Plain CSS (no preprocessor), PHP 8.2 `.tpl` templates (PLAIN PHP, never Smarty), POSIX `sh` + `awk` for the gate, stylelint 16 via npm devDependency, git hooks under `.githooks/`.

**Spec:** `docs/superpowers/specs/2026-08-21-cms-css-separation-design.md`

## Global Constraints

- **`.tpl` files are PLAIN PHP**, not Smarty. Use `<?php ?>` / `<?= ?>`. `{$var}` and `{if}` render literally.
- **No visual change.** Rendered output must be pixel-identical before and after, except the one deliberate fix in Task 2.4. Every task's verification is a before/after comparison.
- **Dark mode selector is `html[data-theme="dark"]`** — never a bare `prefers-color-scheme` block. Every rule that moves must carry its dark-mode partner with it.
- **Tooltips are `data-tip`**, never native `title`.
- **Never stage `system/lib/ork3/class.Authorization.php`.** Stage files explicitly; never `git add -A`.
- **Never commit `CLAUDE.md` or `agent-instructions/claude.md`.**
- **Editing PHP/`.tpl`: normalize-first.** Check `awk '/^\t/{c++}END{print c+0}' <file>` — if non-zero the file is tab-indented; run php-cs-fixer on that file before editing it.
- **CSS indentation in CMS files is 4 spaces**, never tabs. All new CSS files follow this.
- **Local verification base URL:** `http://localhost:19080/orkui/index.php?Route=...`
- **Org-site test slugs:** `burning-lands` (themed kingdom), `kingdom-17` (themed kingdom), `ambient-forest` (**themed park** — needs `&_pfx=p`; park sites live in the /p namespace).
- **Commit prefix:** `Refactor:` for structural moves, `Enforcement:` for the Phase 3 gate.

---

# PHASE 1 — Delineation

## Task 1.1: `cms-base.css` — a minimal global base for standalone org sites

**Files:**
- Create: `orkui/template/default/frontdoor/css/cms-base.css`

**Interfaces:**
- Consumes: nothing.
- Produces: a stylesheet that replaces `orkui.css` + `tokens.css` for `$IsOrgSite` pages only. Wired up in Task 1.2.

**Why these rules and no others.** `orkui.css` currently gives an org site exactly eight global element rules. Four are needed, four are actively harmful:

| `orkui.css` rule | Line | Verdict |
|---|---|---|
| `html { overflow-x:hidden; max-width:100% }` | 742 | **Keep** — horizontal-overflow guard |
| `body { font-family; font-size:11pt; color:#666; background:white }` | 745 | **Replace** — must read `--fd-*`, not CRM greys |
| `body { padding-top:48px }` | 758 | **Drop** — clears `#newmenu`, which org sites never render |
| `#theme_container { width:95%; max-width:1600px; margin:10px auto 40px }` | 770 | **Replace** — org sites are full-bleed |
| `h1..h6 { background:#eee; border:1px solid #ccc; text-shadow; padding:3px }` | 775 | **Drop** — this is the gray pill, fought in 3 places |
| `p { text-align: justify }` | 647 | **Drop** — actively bad for authored body copy |
| `a { color:#333 } / a:hover { underline }` | 928 | **Drop** — competes with `--fd-*` link colours |
| `input…,select,textarea { font-size:16px !important }` | 2180 | **Keep** — iOS zoom guard |

- [ ] **Step 1: Create the file**

```css
/* ============================================================
 * cms-base.css — minimal global base for STANDALONE public CMS org sites.
 *
 * Loaded INSTEAD of the CRM's tokens.css + orkui.css when default.theme is
 * rendering with $IsOrgSite set (Controller_Site). It supplies only the global
 * element normalization a public marketing site genuinely needs; everything
 * visual comes from frontdoor.css / orgsite.css / blocks.css scoped under
 * .fd-page, and from the per-org theme tokens injected as <style id="fd-theme-tokens">.
 *
 * DO NOT add component styling here. This file is a base, not a stylesheet.
 * DO NOT reference #theme_container, #newmenu or any .ork-* class beyond the
 * single container-neutralizing rule below — bin/check-css-boundaries.sh (C1)
 * rejects it.
 *
 * Every rule here is deliberate; see the plan's Task 1.1 table for the
 * orkui.css rule each one replaces (or intentionally omits).
 * ============================================================ */

/* Horizontal-overflow guard. Clamp <html> ONLY — clamping body as well produces
   the duplicate-scrollbar bug. Body uses overflow-x:clip so a wide descendant is
   contained without creating a second scroll container. */
html {
    overflow-x: hidden;
    max-width: 100%;
}

body {
    margin: 0;
    padding: 0;
    overflow-x: clip;
    background: var(--fd-bg, #ffffff);
    color: var(--fd-text, #1a2236);
    font-family: var(--fd-font-body, system-ui, -apple-system, sans-serif);
    font-size: 16px;
    line-height: 1.5;
    -webkit-text-size-adjust: 100%;
}

/* default.theme always emits #theme_container, including on standalone org
   sites. Without orkui.css it is unstyled, which is what we want — but state
   the full-bleed intent explicitly rather than relying on the absence of a
   rule, so a future orkui.css change can never reach in and re-inset it. */
#theme_container {
    width: 100%;
    max-width: none;
    margin: 0;
    padding: 0;
    background: transparent;
}

/* Media never overflows its column. */
img,
svg,
video,
canvas {
    max-width: 100%;
    height: auto;
}

/* iOS zooms the viewport when a focused control's text is under 16px. Carried
   over from orkui.css:2180 — the one global form rule an org site still needs
   (contact/RSVP forms inside authored pages). */
input[type="text"],
input[type="number"],
input[type="email"],
input[type="password"],
input[type="search"],
input[type="url"],
input[type="date"],
select,
textarea {
    font-size: 16px;
}

/* The browser's own dark-surface hinting for form controls and scrollbars.
   orkui.css supplied this implicitly via its dark-mode block; org sites need it
   stated. */
html[data-theme="dark"] {
    color-scheme: dark;
}

html[data-theme="light"] {
    color-scheme: light;
}
```

- [ ] **Step 2: Verify it parses and is 4-space indented**

```bash
awk '/^\t/{c++}END{print "tab-indented lines:", c+0}' orkui/template/default/frontdoor/css/cms-base.css
node -e "const c=require('fs').readFileSync('orkui/template/default/frontdoor/css/cms-base.css','utf8');let d=0;for(const ch of c){if(ch==='{')d++;if(ch==='}')d--;if(d<0)throw new Error('unbalanced');}if(d!==0)throw new Error('unbalanced: '+d);console.log('braces balanced')"
```
Expected: `tab-indented lines: 0` and `braces balanced`.

- [ ] **Step 3: Commit**

```bash
git add orkui/template/default/frontdoor/css/cms-base.css
git commit -m "Refactor: add cms-base.css, a minimal global base for standalone org sites"
```

---

## Task 1.2: Gate the CRM stylesheets on `$IsOrgSite`

**Files:**
- Modify: `orkui/template/default/default.theme:95-96` (the `tokens.css` + `orkui.css` links)
- Modify: `orkui/template/default/default.theme:207-212` (inline `#theme_container` dark rules)

**Interfaces:**
- Consumes: `cms-base.css` from Task 1.1.
- Produces: on `$IsOrgSite` pages, `orkui.css` / `tokens.css` are absent from the document and `cms-base.css` is present. Tasks 1.3 and 3.x assert this.

- [ ] **Step 1: Record the before-state for the four surfaces**

```bash
for s in burning-lands kingdom-17 ambient-forest; do
  echo "--- $s"
  curl -s "http://localhost:19080/orkui/index.php?Route=Site/view/$s" \
    | grep -o 'tokens\.css\|orkui\.css\|cms-base\.css\|frontdoor\.css\|orgsite\.css' | sort | uniq -c
done
echo "--- front door"
curl -sL "http://localhost:19080/" | grep -o 'tokens\.css\|orkui\.css\|cms-base\.css\|frontdoor\.css' | sort | uniq -c
```
Expected before: each org site shows `orkui.css`, `tokens.css`, `frontdoor.css`, `orgsite.css` and **no** `cms-base.css`.

- [ ] **Step 2: Swap the stylesheet links**

Replace `default.theme:95-96`:

```php
		<link type="text/css" href="<?=HTTP_TEMPLATE;?>default/style/tokens.css?v=<?=filemtime(DIR_TEMPLATE.'default/style/tokens.css')?>" rel="stylesheet" />
		<link type="text/css" href="<?=HTTP_TEMPLATE;?>default/style/orkui.css?v=<?=filemtime(DIR_TEMPLATE.'default/style/orkui.css')?>" rel="stylesheet" />
```

with:

```php
		<?php
		// The CRM application stylesheets are for the ORK app shell. A standalone
		// public org site ($IsOrgSite, Controller_Site) renders no ORK nav, no ORK
		// footer and no ORK components — it got 91 KB of CSS it never used, whose
		// global element rules (the h1-h6 gray pill, p{text-align:justify},
		// a{color:#333}) then had to be undone in 37 places across the CMS CSS.
		// Org sites get the minimal cms-base.css instead. Every other surface —
		// the front door, CMS pages, the blog, and the OGRE admin — genuinely
		// renders inside the ORK shell and keeps the CRM stylesheets.
		if (empty($IsOrgSite)):
		?>
		<link type="text/css" href="<?=HTTP_TEMPLATE;?>default/style/tokens.css?v=<?=filemtime(DIR_TEMPLATE.'default/style/tokens.css')?>" rel="stylesheet" />
		<link type="text/css" href="<?=HTTP_TEMPLATE;?>default/style/orkui.css?v=<?=filemtime(DIR_TEMPLATE.'default/style/orkui.css')?>" rel="stylesheet" />
		<?php else: ?>
		<link type="text/css" href="<?=HTTP_TEMPLATE;?>default/frontdoor/css/cms-base.css?v=<?=filemtime(DIR_TEMPLATE.'default/frontdoor/css/cms-base.css')?>" rel="stylesheet" />
		<?php endif; ?>
```

- [ ] **Step 3: Gate the inline `#theme_container` dark rules**

`default.theme:207-212` paints `#theme_container` and its links for the ORK app's dark mode. On an org site that `#theme_container a { color:#63b3ed }` rule is the ID-specificity link-blue that `frontdoor.css` fights in nine selectors. Wrap the three rules:

Find these lines inside the `<style>` block in `<head>`:

```css
	html[data-theme="dark"] #theme_container { background-color: #1a202c; }
```
…through…
```css
	html[data-theme="dark"] #theme_container a:hover { color: #90cdf4; }
```

Close the `<style>` before them, wrap in a PHP conditional, and reopen — i.e. the three rules become:

```php
	</style>
<?php if (empty($IsOrgSite)): // ORK app dark chrome; a standalone org site paints its own surfaces from --fd-* and must not inherit the app's link blue at ID specificity. ?>
	<style>
	html[data-theme="dark"] #theme_container { background-color: #1a202c; }
	html[data-theme="dark"] #theme_container a { color: #63b3ed; }
	html[data-theme="dark"] #theme_container a:hover { color: #90cdf4; }
	</style>
<?php endif; ?>
	<style>
```

Keep the surrounding rules in their original `<style>` blocks — only these three move behind the guard. `default.theme` is tab-indented; match it.

- [ ] **Step 4: Re-run the probe and confirm the swap**

```bash
for s in burning-lands kingdom-17 ambient-forest; do
  echo "--- $s"
  curl -s "http://localhost:19080/orkui/index.php?Route=Site/view/$s" \
    | grep -o 'tokens\.css\|orkui\.css\|cms-base\.css\|frontdoor\.css\|orgsite\.css' | sort | uniq -c
  curl -s "http://localhost:19080/orkui/index.php?Route=Site/view/$s" | grep -c 'theme_container a' || true
done
echo "--- front door (must be UNCHANGED)"
curl -sL "http://localhost:19080/" | grep -o 'tokens\.css\|orkui\.css\|cms-base\.css' | sort | uniq -c
echo "--- OGRE admin (must be UNCHANGED)"
curl -s "http://localhost:19080/orkui/index.php?Route=Cms/dashboard" | grep -o 'tokens\.css\|orkui\.css\|cms-base\.css' | sort | uniq -c
```
Expected after: org sites show `cms-base.css`, `frontdoor.css`, `orgsite.css` and **no** `orkui.css` / `tokens.css`. Front door and admin still show `orkui.css` + `tokens.css` and **no** `cms-base.css`.

- [ ] **Step 5: BROWSER CHECKPOINT — this is the highest-risk step in the plan**

This is the step that can silently break layout by removing CSS something was leaning on. Check in Chrome, **do not skip**, and compare against screenshots taken before Step 2:

| Surface | Light | Dark | 390px |
|---|---|---|---|
| `…Route=Site/view/burning-lands` | ✔ | ✔ | ✔ |
| `…Route=Site/view/kingdom-17` | ✔ | ✔ | ✔ |
| `…Route=Site/view/ambient-forest` | ✔ | ✔ | ✔ |
| `/` (front door — regression check) | ✔ | ✔ | ✔ |
| `…Route=Cms/dashboard` (admin — regression check) | ✔ | ✔ | — |

Specifically look for: headings losing/gaining a gray pill; body copy switching to or from justified; link colours flipping to `#333` or `#63b3ed`; the page gaining a 48px gap at the top; a second horizontal scrollbar; nav/footer spacing shifts. Read the console for 404s on `cms-base.css`.

- [ ] **Step 6: Commit**

```bash
git add orkui/template/default/default.theme
git commit -m "Refactor: stop loading CRM stylesheets on standalone public org sites"
```

---

## Task 1.3: Quarantine the ORK-shell overrides into `orkshell-interop.css`

**Files:**
- Create: `orkui/template/default/frontdoor/css/orkshell-interop.css`
- Modify: `orkui/template/default/frontdoor/css/frontdoor.css` (remove lines 187-239 region — the `#theme_container` block)
- Modify: `orkui/template/default/_index.tpl`, `Page_view.tpl`, `Blog_index.tpl`, `Blog_post.tpl`, `Cms_preview.tpl` (add the link)
- Modify: `orkui/template/default/frontdoor/_park_strip.tpl:100-115` (move its `#theme_container` rules out)

**Interfaces:**
- Consumes: the `$IsOrgSite` gate from Task 1.2.
- Produces: `orkshell-interop.css` — the file `bin/check-css-boundaries.sh` (Task 3.2) allowlists for ORK-selector references on the **public** side. `cms-base.css` is the second allowlisted file (it must neutralize `#theme_container`), and `cms/css/cms-admin.css` is out of C1's scope entirely — the admin is in-shell by definition.

**The rule:** `Site_shell.tpl` must **never** link this file. Every other CMS surface must.

- [ ] **Step 1: Create the quarantine file with the rules moved verbatim**

```css
/* ============================================================
 * orkshell-interop.css — the ONLY place CMS CSS may name an ORK selector.
 *
 * The front door, CMS pages, the blog and the OGRE admin all render INSIDE the
 * ORK application shell, so orkui.css and default.theme's inline chrome are in
 * their cascade. A handful of CMS controls therefore lose to ORK rules that win
 * on ID specificity (`#theme_container a` is (1,0,1); `html[data-theme="dark"]
 * #theme_container a` is (1,1,1)). The overrides that fix that are collected
 * here rather than scattered through frontdoor.css, so the coupling is visible,
 * greppable and deletable.
 *
 * NOT loaded by Site_shell.tpl. A standalone org site does not load orkui.css
 * at all (see default.theme's $IsOrgSite gate), so it needs none of this — and
 * bin/check-css-boundaries.sh (C4) fails the build if the link is ever added.
 *
 * Every rule below is here because an ORK rule outranks a CMS rule. If you find
 * yourself adding a rule for any other reason, it belongs in frontdoor.css.
 * ============================================================ */

/* Gold-filled buttons are <a> tags; `#theme_container a` forces link-blue text
   in dark mode. Override at matching specificity, restating the SAME dark ink
   the base .fd-btn-gold / .fd-nav-cta rules already use (#1a1205 — no "ink on
   accent" token exists in CmsThemeTokens, and both base rules already hard-code
   this exact value, so restating it keeps one source of truth rather than
   inventing a second near-black). #1a1205 on gold measures ~10.7:1. */
#theme_container a.fd-btn-gold,
#theme_container a.fd-btn-gold:hover,
#theme_container a.fd-nav-cta,
#theme_container a.fd-nav-cta:hover {
    color: #1a1205 !important;
}

/* Ghost buttons sit on the DARK hero field (not on gold), where white is the
   correct, legible choice — kept separate from the gold-filled pair above so a
   future contrast fix to one never silently changes the other. */
#theme_container a.fd-btn-ghost,
#theme_container a.fd-btn-ghost:hover {
    color: #fff !important;
    text-shadow: 0 1px 2px rgba(0, 0, 0, .45);
}

/* Same trap for the outline login button: `#theme_container a` is (1,0,1), so a
   plain .fd-nav-login colour never lands and the button renders link-blue.
   Restate both themes at ID specificity. (`inherit` is NOT the shortcut here —
   it would pull the nav bar's colour, not the button's.) */
#theme_container a.fd-nav-login {
    color: #56607a;
}
#theme_container a.fd-nav-login:hover {
    color: var(--fd-text);
}
html[data-theme="dark"] #theme_container a.fd-nav-login {
    color: #c8d2e6;
}
html[data-theme="dark"] #theme_container a.fd-nav-login:hover {
    color: #fff;
}

/* "Ways to Play" path cards are <a class="fd-path"> sitting on a dark scrim, so
   their title/blurb are meant to be white. `#theme_container a` drags the link
   and its inherited children to link-blue in dark mode; restore white at
   matching specificity. Colour only (no text-shadow) so light mode — already
   white via .fd-path — is visually unchanged. The gold icon keeps its own
   color:var(--gold) and is deliberately not matched here. */
#theme_container a.fd-path,
#theme_container a.fd-path:hover,
#theme_container a.fd-path .fd-path-label,
#theme_container a.fd-path .fd-serif {
    color: #fff !important;
}

/* Park strip links (moved from _park_strip.tpl's inline <style>). The dark-mode
   `html[data-theme="dark"] #theme_container a` rule is (1,1,2) — one notch above
   the plain `#theme_container a.pk-strip-link` rule — so the dark pair below is
   required, not redundant. */
#theme_container a.pk-strip-link {
    color: var(--fd-accent-on-primary, var(--fd-primary-contrast));
    font-weight: 600;
}
#theme_container a.pk-strip-link:hover {
    color: var(--fd-primary-contrast);
}
html[data-theme="dark"] #theme_container a.pk-strip-link {
    color: var(--fd-accent-on-primary, var(--fd-primary-contrast));
}
html[data-theme="dark"] #theme_container a.pk-strip-link:hover {
    color: var(--fd-primary-contrast);
}
```

- [ ] **Step 1b: Move `body.fd-home` out of the CRM stylesheet**

`orkui.css:766` carries a rule this branch added: `body.fd-home { padding-top:0; margin-top:0 }`.
That is CRM CSS styling a CMS surface — the exact inversion of the boundary, and the
one thing Phase 3's C5 rule will reject. It belongs here.

Cut it and its comment out of `orkui.css` and append to `orkshell-interop.css`:

```css
/* Front door only. The front door hides #newmenu outright (display:none, height 0)
   and ships its own marketing nav, so orkui.css's 48px body clearance reserves
   space for a bar that is never painted; together with the UA's default 8px body
   margin that pushed the marketing nav down 56px of dead space. #theme_container's
   own 10px/6px inset is deliberate — it gives the rounded nav bar its gutter — so
   it stays.

   This lives here, not in orkui.css, because it is a CMS rule: the CRM must not
   style CMS surfaces (bin/check-css-boundaries.sh C5). It still wins over
   orkui.css's `body { padding-top:48px }` — `body.fd-home` is (0,1,1) against
   (0,0,1) — and this file is linked after orkui.css on every in-shell surface. */
body.fd-home {
    padding-top: 0;
    margin-top: 0;
}
```

Confirm the CRM stylesheet is clean:

```bash
grep -nE '(\.fd-|\.cms-|\.org-)' orkui/template/default/style/orkui.css \
  | grep -vE ':[[:space:]]*(/\*|\*|//)'
```
Expected: no output.

Then re-check the front door at `/` — its marketing nav must still sit flush at the
top with no 48px gap. This is the single rule whose loss is most visible.

- [ ] **Step 2: Delete the moved rules from their old homes**

From `frontdoor.css`, delete the four `#theme_container` rule groups in the 187-239 region (`.fd-btn-gold`/`.fd-nav-cta`, `.fd-btn-ghost`, `.fd-nav-login` ×4, `.fd-path`) **and their comment blocks** — they moved verbatim above. Leave the non-`#theme_container` rules (`.fd-btn-gold`, `.fd-btn-ghost`, `.fd-link`, focus rings) exactly where they are.

From `_park_strip.tpl`, delete the four `#theme_container a.pk-strip-link` rules and their comment from the inline `<style>`. Keep the rest of that block.

Confirm nothing is left behind:

```bash
grep -n '#theme_container' orkui/template/default/frontdoor/css/frontdoor.css orkui/template/default/frontdoor/_park_strip.tpl
```
Expected: no output.

- [ ] **Step 3: Create a shared asset partial so five templates don't hand-roll the link**

Create `orkui/template/default/frontdoor/_assets_inshell.tpl`:

```php
<?php
/*
 * _assets_inshell.tpl — stylesheet links for CMS surfaces that render INSIDE the
 * ORK application shell (front door, CMS pages, blog index/post, CMS preview).
 * PLAIN PHP (extract()+include), NEVER Smarty.
 *
 * Site_shell.tpl deliberately does NOT include this: a standalone org site does
 * not load orkui.css, so it needs no interop layer. bin/check-css-boundaries.sh
 * (C4) enforces that.
 *
 * Expects $fdDir (filesystem) and $fdAssetBase (URL) already in scope.
 */
?>
<link rel="stylesheet" href="<?= $fdAssetBase ?>css/orkshell-interop.css?v=<?= @filemtime($fdDir . 'css/orkshell-interop.css') ?>">
```

Then in each of `_index.tpl`, `Page_view.tpl`, `Blog_index.tpl`, `Blog_post.tpl`, `Cms_preview.tpl`, immediately after that file's existing `frontdoor.css` `<link>`, add:

```php
<?php include $fdDir . '_assets_inshell.tpl'; ?>
```

Each of those five already defines `$fdDir` and `$fdAssetBase` before its `frontdoor.css` link — confirm with `grep -n 'fdDir\s*=' <file>` before editing; if one does not, define it the same way `Site_shell.tpl:51-52` does.

- [ ] **Step 4: Verify link presence and, critically, absence**

```bash
echo "MUST contain orkshell-interop:"
for u in "/" "/orkui/index.php?Route=Blog/index"; do
  printf "  %-40s %s\n" "$u" "$(curl -sL "http://localhost:19080$u" | grep -c 'orkshell-interop')"
done
echo "MUST NOT contain orkshell-interop (all zeros):"
for s in burning-lands kingdom-17 ambient-forest; do
  printf "  %-40s %s\n" "$s" "$(curl -s "http://localhost:19080/orkui/index.php?Route=Site/view/$s" | grep -c 'orkshell-interop')"
done
```

- [ ] **Step 5: BROWSER CHECKPOINT**

Front door in dark mode is the surface these rules exist for. Verify at `/`: the gold "hero" CTA has near-black text (not blue), ghost buttons are white, the outline Login button is light grey (not link-blue), and the "Ways to Play" path card titles are white. Then the same in light mode, then a park-strip-bearing org page. Any of these turning blue means a rule was dropped rather than moved.

- [ ] **Step 6: Commit**

```bash
git add orkui/template/default/frontdoor/css/orkshell-interop.css \
        orkui/template/default/frontdoor/css/frontdoor.css \
        orkui/template/default/frontdoor/_assets_inshell.tpl \
        orkui/template/default/frontdoor/_park_strip.tpl \
        orkui/template/default/_index.tpl \
        orkui/template/default/Page_view.tpl \
        orkui/template/default/Blog_index.tpl \
        orkui/template/default/Blog_post.tpl \
        orkui/template/default/Cms_preview.tpl
git commit -m "Refactor: quarantine ORK-shell CSS overrides into orkshell-interop.css"
```

---

## Task 1.4: Move `cms-admin.css` into a CMS-owned directory and stop defining `--ork-warn`

**Files:**
- Move: `orkui/template/default/style/cms-admin.css` → `orkui/template/default/cms/css/cms-admin.css`
- Modify: `orkui/template/default/cms/_shell_top.tpl` (own the `<link>`)
- Modify: 9 templates that currently link it — `Cms_dashboard.tpl`, `Cms_index.tpl`, `Cms_posts.tpl`, `Cms_edit.tpl`, `Cms_editpost.tpl`, `Cms_media.tpl`, `Cms_nav.tpl`, `Cms_sites.tpl`, `Cms_theme.tpl`
- Modify: `orkui/template/default/Cms_media.tpl` (the one `--ork-warn` consumer outside the stylesheet)

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces: `cms-admin.css` at its new path, linked exactly once from `_shell_top.tpl`. Token `--cms-warn` replaces `--ork-warn`, scoped to `.cms-shell, .cms-wrap`.

All nine consumers were confirmed to include `cms/_shell_top.tpl`, so the shell can own the link with no coverage gap.

- [ ] **Step 1: Move the file with history**

```bash
mkdir -p orkui/template/default/cms/css
git mv orkui/template/default/style/cms-admin.css orkui/template/default/cms/css/cms-admin.css
```

- [ ] **Step 2: Rename the token it should not own**

`cms-admin.css:16-18` defines an `--ork-*` token — a CRM-namespace token defined by a CMS file. Replace:

```css
/* Warning/accent token used by Cms_media.tpl ("In use" label) and note-live
   surfaces. Defined globally so it isn't left undefined (bad contrast). */
:root { --ork-warn: #9c4221; }
html[data-theme="dark"] { --ork-warn: #fbd38d; }
```

with:

```css
/* Warning/accent token used by Cms_media.tpl ("In use" label) and note-live
   surfaces. Scoped to the admin shell rather than :root — this is a CMS token,
   and a CMS stylesheet must not define into the CRM's --ork-* namespace
   (bin/check-css-boundaries.sh C2). */
.cms-shell,
.cms-wrap {
    --cms-warn: #9c4221;
}

html[data-theme="dark"] .cms-shell,
html[data-theme="dark"] .cms-wrap {
    --cms-warn: #fbd38d;
}
```

`--ork-warn` is not the only one. `cms-admin.css:28` also defines
`--ork-header-h: 48px` inside `.cms-wrap`. Same problem, same fix — rename it and
keep the comment explaining where the 48px comes from:

```css
    /* The global #newmenu navbar is position:fixed at 48px tall (orkui.css).
       Sticky elements below must clear it or they hide behind it on scroll.
       Named --cms-* because a CMS stylesheet must not define into the CRM's
       --ork-* namespace, even for a value the CRM originates. */
    --cms-header-h: 48px;
```

Update its consumers the same way. Then update the `--ork-warn` consumers inside
`cms-admin.css` and the one in `Cms_media.tpl`:

```bash
grep -rn -- '--ork-warn\|--ork-header-h' orkui/template/default/cms/css/cms-admin.css orkui/template/default/Cms_media.tpl
# rewrite each var(--ork-warn, #b8860b)  -> var(--cms-warn, #b8860b)
# rewrite each var(--ork-header-h, 48px) -> var(--cms-header-h, 48px)
```
Verify no CMS file *defines* an `--ork-*` token any more (reads via `var()` stay legal
— the admin renders inside the ORK shell and inheriting `--ork-text` etc. is correct):

```bash
grep -rnE '(^|[;{[:space:]])--ork-[a-z0-9-]+[[:space:]]*:' \
  orkui/template/default/frontdoor/ orkui/template/default/cms/ \
  orkui/template/default/Cms_*.tpl
```
Expected: no output.

- [ ] **Step 3: Give the shell the link**

In `cms/_shell_top.tpl`, immediately before the existing `cms-admin.js` `<script>` tag, add:

```php
<?php // Shared OGRE admin stylesheet — one source of truth for every admin
      // surface. Loaded HERE rather than in each of the nine page templates,
      // which previously hand-rolled this link and its cache-buster. ?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/cms/css/cms-admin.css?v=<?= filemtime(__DIR__ . '/css/cms-admin.css') ?>">
```

- [ ] **Step 4: Delete the nine now-duplicate links**

Each of the nine templates has a line matching `default/style/cms-admin.css`. Delete that whole `<link…>` line from each. Verify:

```bash
grep -rn 'style/cms-admin.css' orkui/ ; echo "--- exit $?  (expect: no output)"
grep -rln 'cms/css/cms-admin.css' orkui/  # expect exactly: cms/_shell_top.tpl
```

- [ ] **Step 5: Verify every admin surface still loads it exactly once**

```bash
for r in Cms/dashboard Cms/index Cms/posts Cms/media Cms/nav Cms/theme Cms/sites; do
  # grep -c would count the CSS comment in Cms_nav.tpl as a second "link"; match the tag.
  n=$(curl -s "http://localhost:19080/orkui/index.php?Route=$r" | grep -oE '<link[^>]*cms-admin\.css[^>]*>' | wc -l | tr -d ' ')
  printf "  %-16s cms-admin.css links = %s\n" "$r" "$n"
done
```
Expected: `1` for every route (a `0` means that surface does not use the shell after all; a `2` means a stale link survived).

- [ ] **Step 6: BROWSER CHECKPOINT**

Load `Cms/dashboard`, `Cms/media` and `Cms/theme` in light and dark. The admin must look identical to before. On `Cms/media` specifically, confirm the "In use" label still has its warning colour in both themes — that is the `--cms-warn` rename.

- [ ] **Step 7: Commit**

```bash
git add orkui/template/default/cms/css/cms-admin.css \
        orkui/template/default/cms/_shell_top.tpl \
        orkui/template/default/Cms_dashboard.tpl orkui/template/default/Cms_index.tpl \
        orkui/template/default/Cms_posts.tpl orkui/template/default/Cms_edit.tpl \
        orkui/template/default/Cms_editpost.tpl orkui/template/default/Cms_media.tpl \
        orkui/template/default/Cms_nav.tpl orkui/template/default/Cms_sites.tpl \
        orkui/template/default/Cms_theme.tpl
git commit -m "Refactor: move cms-admin.css into cms/css/ and let the admin shell own the link"
```

---

# PHASE 2 — DRY

## Task 2.1: Split `frontdoor.css` into `frontdoor.css` + `blocks.css` + `blog.css`

**Files:**
- Modify: `orkui/template/default/frontdoor/css/frontdoor.css` (1,409 lines → ~1,096)
- Create: `orkui/template/default/frontdoor/css/blocks.css` (from lines 1097-1304)
- Create: `orkui/template/default/frontdoor/css/blog.css` (from lines 1305-1409)
- Modify: `Site_shell.tpl`, `_index.tpl`, `Page_view.tpl`, `Blog_index.tpl`, `Blog_post.tpl`, `Cms_preview.tpl` (link the new files)

**Interfaces:**
- Consumes: the `_assets_inshell.tpl` partial from Task 1.3.
- Produces: `blocks.css` — the destination for every block stylesheet lifted in Task 2.2, and the file Task 2.3 groups duplicates in.

**Cascade order is load-bearing.** These rules currently sit at the END of `frontdoor.css`. They must continue to be applied after it, so `blocks.css` and `blog.css` **must be linked after `frontdoor.css`** everywhere. Getting this backwards silently changes which rule wins in every tie.

**Why split here and not more aggressively.** Lines 754-1096 are the shared dark-mode and responsive sections, which mix marketing chrome and block rules. Cutting through them by selector would reorder rules relative to each other and change tie-breaks. Lines 1097+ are already self-contained per-block sections carrying their own dark-mode and media-query partners, so this boundary is a pure move. The earlier block-ish rules (`.fd-ev-*` at 327-354, `.fdb-heading-*` responsive at 1074-1080) deliberately stay in `frontdoor.css` for now — except the `.fdb-heading-*` pair, which Task 2.2 moves for a specific reason documented there.

- [ ] **Step 1: Capture a baseline of computed styles**

Before moving anything, record what the browser actually computes for a sample of block elements, so the move can be proven inert:

```bash
mkdir -p /tmp/cssbase
for s in burning-lands ambient-forest; do
  curl -s "http://localhost:19080/orkui/index.php?Route=Site/view/$s" > /tmp/cssbase/$s.before.html
done
curl -sL "http://localhost:19080/" > /tmp/cssbase/frontdoor.before.html
```

- [ ] **Step 2: Perform the split**

```bash
cd orkui/template/default/frontdoor/css
sed -n '1097,1304p' frontdoor.css > /tmp/blocks-body.css
sed -n '1305,1409p' frontdoor.css > /tmp/blog-body.css
sed -i '' '1097,1409d' frontdoor.css
```

Then prepend a header to each new file. `blocks.css`:

```css
/* ============================================================
 * blocks.css — CSS for CMS content blocks (frontdoor/blocks/*.tpl).
 *
 * Split out of frontdoor.css, which now owns only the front-door marketing
 * chrome. Loaded AFTER frontdoor.css on every CMS surface — the rules here were
 * at the end of that file and several rely on winning a same-specificity tie
 * against it. Do not reorder the <link> tags.
 *
 * Scoped under .fd-page like everything else on the public side. Each block's
 * dark-mode and responsive partners live next to its base rules, not in a
 * far-away theme section, so a block can be read in one place.
 * ============================================================ */
```

`blog.css`:

```css
/* ============================================================
 * blog.css — blog index (Blog_index.tpl) and single post (Blog_post.tpl).
 *
 * Split out of frontdoor.css. Loaded AFTER frontdoor.css and blocks.css.
 * .blog-* is the index, .blogp-* the single post; the shared tag pill is
 * grouped across both.
 * ============================================================ */
```

Then `cat /tmp/blocks-body.css >> blocks.css` and `cat /tmp/blog-body.css >> blog.css`.

- [ ] **Step 3: Link the new files, in order, on all six surfaces**

`blocks.css` is needed wherever blocks render: all six surfaces. `blog.css` is
strictly needed only on `Blog_index.tpl`, `Blog_post.tpl` and `Site_shell.tpl` (org
sites have a blog mode) — but it is ~3 KB, and serving it everywhere buys one asset
list with one cache key instead of two variants that drift. That over-serve is
deliberate; do not "optimize" it into a conditional without measuring first.

Five of the six surfaces already include `_assets_inshell.tpl`, but `Site_shell.tpl`
must NOT include that partial and still needs the block CSS. So add a second,
universally-safe partial `frontdoor/_assets_public.tpl`:

```php
<?php
/*
 * _assets_public.tpl — the public-side CMS stylesheet set, in cascade order.
 * PLAIN PHP (extract()+include), NEVER Smarty.
 *
 * ORDER IS LOAD-BEARING: blocks.css and blog.css were split off the end of
 * frontdoor.css and several of their rules win same-specificity ties against
 * it. Do not reorder.
 *
 * Safe on EVERY public CMS surface, standalone org sites included — nothing
 * here names an ORK selector. The ORK-shell interop layer is a separate
 * partial (_assets_inshell.tpl) that org sites deliberately do not include.
 *
 * Expects $fdDir (filesystem) and $fdAssetBase (URL) already in scope.
 */
$fdCssSet = array('frontdoor.css', 'blocks.css', 'blog.css');
foreach ($fdCssSet as $fdCssFile) :
?>
<link rel="stylesheet" href="<?= $fdAssetBase ?>css/<?= $fdCssFile ?>?v=<?= @filemtime($fdDir . 'css/' . $fdCssFile) ?>">
<?php endforeach; ?>
```

In all six templates, replace the existing hand-rolled `frontdoor.css` `<link>` with `<?php include $fdDir . '_assets_public.tpl'; ?>`. In `Site_shell.tpl` this goes immediately before the existing `orgsite.css` link, so org chrome still layers last.

- [ ] **Step 4: Verify order and presence**

```bash
for u in "/" "/orkui/index.php?Route=Site/view/burning-lands" "/orkui/index.php?Route=Blog/index"; do
  echo "--- $u"
  curl -sL "http://localhost:19080$u" | grep -o 'frontdoor\.css\|blocks\.css\|blog\.css\|orgsite\.css\|orkshell-interop\.css'
done
```
Expected order on every surface: `frontdoor.css`, `blocks.css`, `blog.css`, then `orgsite.css` (org sites) / `orkshell-interop.css` (in-shell). No duplicates.

- [ ] **Step 5: BROWSER CHECKPOINT**

Load an org-site home rich in blocks (`burning-lands`) and the park site (`ambient-forest`) in both themes at desktop and 390px. Blocks to look at specifically: staff roster cards, officers grid, park meeting panel, park events grid, kingdom events grid, blog feed cards. Then `Blog/index` and one post. Anything that loses its card border, grid columns, or dark-mode surface means a rule landed on the wrong side of the cut.

- [ ] **Step 6: Commit**

```bash
git add orkui/template/default/frontdoor/css/frontdoor.css \
        orkui/template/default/frontdoor/css/blocks.css \
        orkui/template/default/frontdoor/css/blog.css \
        orkui/template/default/frontdoor/_assets_public.tpl \
        orkui/template/default/Site_shell.tpl orkui/template/default/_index.tpl \
        orkui/template/default/Page_view.tpl orkui/template/default/Blog_index.tpl \
        orkui/template/default/Blog_post.tpl orkui/template/default/Cms_preview.tpl
git commit -m "Refactor: split frontdoor.css into frontdoor/blocks/blog stylesheets"
```

---

## Task 2.2: Lift static block CSS out of `<style>` blocks into `blocks.css`

**Files:**
- Modify: `orkui/template/default/frontdoor/css/blocks.css` (receives the lifted rules)
- Modify: `orkui/template/default/frontdoor/css/frontdoor.css` (move the `.fdb-heading-*` responsive pair — see Step 3)
- Modify: 20 block templates under `orkui/template/default/frontdoor/blocks/` and `frontdoor/_park_strip.tpl`

**Interfaces:**
- Consumes: `blocks.css` from Task 2.1.
- Produces: block templates with no static `<style>` blocks, which Task 3.2's C3 rule then enforces.

**Which blocks move.** Every `<style>` block whose contents are static CSS. Audited list, with measured style-block size:

| Template | lines | Move? |
|---|---|---|
| `gallery.tpl` | 158 | yes |
| `file_download.tpl` | 137 | yes |
| `video_embed.tpl` | 113 | yes |
| `image.tpl` | 95 | yes |
| `park_events.tpl` | 91 | yes |
| `park_hero.tpl` | 62 | yes |
| `accordion.tpl` | 54 | yes |
| `table.tpl` | 51 | yes |
| `kingdom_parks_map.tpl` | 48 | yes |
| `_shared/officers.tpl` | 47 | yes |
| `blog_feed.tpl` | 43 | yes |
| `kingdom_parks.tpl` | 35 | yes |
| `quote.tpl` | 31 | yes |
| `_park_strip.tpl` | 24 | yes (remainder after Task 1.3) |
| `heading.tpl` | 24 | yes — see Step 3 |
| `staff_roster.tpl` | 23 | yes |
| `divider.tpl` | 22 | yes |
| `columns.tpl` | 21 | yes |
| `spacer.tpl` | 5 | yes |
| `steps.tpl` | 4 | yes |

**Blocks that keep an inline `<style>`.** A block whose CSS embeds a *per-instance authored value* (a column count, a chosen accent, an aspect ratio) cannot move to a static file. Before moving any block, check:

```bash
awk '/<style>/{f=1} f{print} /<\/style>/{f=0}' <template> | grep -n '<?'
```
If the `<style>` body itself interpolates PHP, keep that block inline **and add the `$fdStyleOnce` guard** (Step 4) rather than moving it. Move only the static remainder. Record any such block in a comment in `blocks.css` saying which template still owns dynamic rules and why.

- [ ] **Step 1: Move one block at a time, largest first**

For each template in the table: cut the static rules out of its `<style>…</style>`, append them to `blocks.css` under a section header naming the source, and delete the now-empty `<style>` block **and its `$fdStyleOnce` guard** (the guard exists only to dedupe an inline emit; once the CSS is in a stylesheet it is dead code). Header format:

```css
/* ---- gallery block (was inline <style> in blocks/gallery.tpl) ---- */
```

Verify after each block that the page still renders and the class still resolves:

```bash
curl -s "http://localhost:19080/orkui/index.php?Route=Site/view/burning-lands" | grep -c '<style'
```
This count must go **down** monotonically and never to a value that suggests markup was clipped — check the byte count too (`| wc -c`) stays within a few hundred bytes of the pre-move value plus the removed CSS.

- [ ] **Step 2: Delete the now-dead `$fdStyleOnce` plumbing if every user is gone**

```bash
grep -rn 'fdStyleOnce' orkui/template/default/frontdoor/
```
If the only remaining reference is the declaration in `render_blocks.tpl:29-32`, delete that too along with its comment. If any block still needs it (a dynamic-CSS block from the audit above), leave it and update the comment to name the surviving users.

- [ ] **Step 3: Move `.fdb-heading-*` and its responsive partner together**

This one has a cascade trap. `heading.tpl`'s inline `<style>` renders in the **body**, after every stylesheet, so it currently wins ties. `frontdoor.css:1074-1080` holds its `@media (max-width:680px)` step-down. If the base rules move into `blocks.css` (loaded after `frontdoor.css`) while the media query stays behind, the base rules would then come *later* and the responsive sizes would stop applying at 680px.

So move **both**: cut `frontdoor.css`'s heading media query out and append it to `blocks.css` immediately after the lifted `.fdb-heading-*` base rules. Once they are adjacent in one file, the `!important` on the media-query rules is no longer needed to win the order tie — but leave it in place; removing it is a behaviour change and belongs in a separate pass.

Verify at exactly 680px and 679px in the browser that h1/h2 heading blocks step down.

- [ ] **Step 4: For any block that must keep dynamic inline CSS, add the missing guard**

Five templates emit `<style>` with no dedupe guard, so a page with two of that block emits the CSS twice: `blog_feed.tpl`, `park_events.tpl`, `staff_roster.tpl`, `_shared/officers.tpl`, `_park_strip.tpl`. If any of these survives Step 1 as a dynamic block, wrap its remaining `<style>` exactly as `heading.tpl` does:

```php
<?php // Emit this block's CSS at most once per request (dedupes repeats). ?>
<?php if (empty($fdStyleOnce['blog_feed'])) : $fdStyleOnce['blog_feed'] = true; ?>
<style>
…
</style>
<?php endif; ?>
```

- [ ] **Step 5: Confirm the inline-CSS volume actually dropped**

```bash
tot=0
for f in orkui/template/default/frontdoor/blocks/*.tpl \
         orkui/template/default/frontdoor/blocks/_shared/*.tpl \
         orkui/template/default/frontdoor/_park_strip.tpl; do
  [ -f "$f" ] || continue
  n=$(awk '/<style>/{f=1} f{c++} /<\/style>/{f=0} END{print c+0}' "$f")
  [ "$n" -gt 0 ] && printf "  %-40s %s\n" "$(basename $f)" "$n"
  tot=$((tot+n))
done
echo "TOTAL inline style lines remaining: $tot"
```
Expected: total drops from ~1,100 to under 100, and every survivor is on the dynamic-CSS list with a `$fdStyleOnce` guard.

- [ ] **Step 6: BROWSER CHECKPOINT**

Every block type must be visually re-checked, because each one moved independently. Use the org-site home pages and a CMS page that exercises gallery / image / video / file-download / table / accordion / quote / columns / steps / divider / spacer. Both themes, desktop and 390px. Pay attention to the gallery lightbox (its CSS and its inline `<script>` were adjacent — confirm the script survived the cut) and to `park_hero` on `ambient-forest`.

- [ ] **Step 7: Commit**

```bash
git add orkui/template/default/frontdoor/css/blocks.css \
        orkui/template/default/frontdoor/css/frontdoor.css \
        orkui/template/default/frontdoor/blocks/ \
        orkui/template/default/frontdoor/_park_strip.tpl \
        orkui/template/default/frontdoor/render_blocks.tpl
git commit -m "Refactor: lift static block CSS out of templates into blocks.css"
```

---

## Task 2.3: Collapse duplicate declaration bodies by selector grouping

**Files:**
- Modify: `orkui/template/default/frontdoor/css/blocks.css`
- Modify: `orkui/template/default/cms/css/cms-admin.css`

**Interfaces:**
- Consumes: `blocks.css` with all block CSS present (Task 2.2).
- Produces: no new selectors and no renamed classes — templates are untouched.

**Approach: group, do not rename.** The feed-block family (`.pe-*` park events, `.ke-*` kingdom events, `.bf-*` blog feed, `.kp-*` kingdom parks, `.ko-*` officers) is the same component copied five times. Renaming them to a shared `.fd-feed` would touch every block template and every authored page's expectations. Grouping the selectors onto one rule removes the duplication with zero markup change and zero risk of missing a call site.

Measured duplicates (byte-identical declaration bodies, ≥3 declarations):

| Declarations | Selectors to group | Copies |
|---|---|---|
| `color:var(--fd-text-muted); font-style:italic; padding:18px; text-align:center` | `.ko-empty .pm-empty .pe-empty .ke-empty .bf-empty` | 5 |
| `display:grid; gap:16px; grid-template-columns:repeat(3,1fr)` | `.pe-grid .ke-grid .bf-grid .kp-grid` | 4 |
| `align-items:flex-end; display:flex; gap:12px; justify-content:space-between; margin-bottom:18px` | `.pe-head .ke-head .bf-head .kp-head` | 4 |
| `background:var(--fd-bg); border:1px solid var(--pk-line); border-radius:10px; color:inherit; display:block; overflow:hidden; text-decoration:none` | `.pe-card .ke-card .bf-card` | 3 |
| `color:var(--pk-link); font-size:14px; font-weight:600; text-decoration:none; white-space:nowrap` | `.pe-more .ke-more .bf-more` | 3 |
| `color:var(--pk-link); font-size:12px; font-weight:600; margin-top:6px` | `.fd-ev-rsvp .pe-card-rsvp .ke-card-rsvp` | 3 |
| `color:var(--fd-text); font-size:15px; font-weight:700; margin:4px 0` | `.pe-card-name .ke-card-name` | 2 |
| `height:100%; object-fit:contain; width:100%` | `.kpm-crest img .kp-crest img` | 2 |
| `clip-path:inset(50%); height:1px; overflow:hidden; position:absolute; white-space:nowrap; width:1px` | `.cms-sr-only` + 2 others in `cms-admin.css` | 3 |
| `background:#111827; border-color:#2c3650; color:#c0cad8` | dark `.te-color-hex .te-select .te-number` | 3 |
| `background:#27200a; border-color:#78500a; color:#fbbf24` | dark `.te-contrast-warn .te-contrast-warn-inline` | 2 |
| `display:flex; flex-direction:column; gap:8px; padding:10px 14px` | `.te-group-body .te-advanced-body` | 2 |
| `font-size:15px; height:40px; width:40px` | `.cms-block-tools .cms-icon-btn` + nav variant | 2 |

Two pairs are **deliberately not grouped** — `.ko-role`/`.bf-card-date` (`#8a6608`) and `.pe-card-date`/`.ke-card-date` (`#7a5c00`) are the same shape at two different golds. Grouping them would invite a future "fix" that unifies the colours across unrelated components. Leave them, and add a comment saying so.

- [ ] **Step 1: Group each family, preserving position**

Place the grouped rule at the position of the **first** member in the file, and delete the others. Where a later member had extra declarations beyond the shared body, keep a separate follow-up rule for just those extras, immediately after the grouped rule. Example:

```css
/* Feed-block shells. .pe-* (park events), .ke-* (kingdom events), .bf-* (blog
   feed) and .kp-* (kingdom parks) are the same card-grid component rendered by
   four different blocks. Grouped rather than renamed to one class so no block
   template or authored page has to change. Per-family differences follow each
   grouped rule. */
.pe-grid,
.ke-grid,
.bf-grid,
.kp-grid {
    display: grid;
    gap: 16px;
    grid-template-columns: repeat(3, 1fr);
}
```

- [ ] **Step 2: Prove the grouping is inert**

Re-run the duplication analysis and confirm the group count drops:

```bash
python3 - <<'PY'
import re,collections,glob
def strip(s): return re.sub(r'/\*.*?\*/','',s,flags=re.S)
bodies=collections.defaultdict(list)
for f in glob.glob('orkui/template/default/frontdoor/css/*.css')+['orkui/template/default/cms/css/cms-admin.css']:
    for m in re.finditer(r'([^{}]+)\{([^{}]*)\}',strip(open(f).read())):
        sel=' '.join(m.group(1).split())
        if sel.startswith('@'): continue
        d=tuple(sorted(' '.join(x.split()) for x in m.group(2).split(';') if x.strip()))
        if len(d)>=3: bodies[d].append((f,sel))
dups={k:v for k,v in bodies.items() if len(v)>1}
print("duplicate declaration-body groups remaining:",len(dups))
for k,v in dups.items(): print("  ",len(v),"x",'; '.join(k)[:80])
PY
```
Expected: down from 19 groups to at most the 2 deliberately-kept gold pairs plus anything genuinely distinct.

- [ ] **Step 3: BROWSER CHECKPOINT**

Grouping is where an off-by-one deletion quietly removes a rule. Re-check every feed-bearing surface: `burning-lands` (kingdom events, blog feed, officers), `ambient-forest` (park events, park meeting, park hero), and the theme editor at `Cms/theme` for the `.te-*` groupings — including its contrast-warning state and dark mode.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/default/frontdoor/css/blocks.css \
        orkui/template/default/cms/css/cms-admin.css
git commit -m "Refactor: collapse duplicate CSS declaration bodies by selector grouping"
```

---

## Task 2.4: One source of truth for `--fd-*` token defaults

**Files:**
- Modify: `orkui/template/default/frontdoor/css/frontdoor.css:8-32` (the `.fd-page` token block)
- Test: `tests/cms-theme/tokens_test.php` (add a defaults-parity test)

**Interfaces:**
- Consumes: `CmsThemeTokens::DefaultValues()` and `CmsThemeTokens::ToCss()`, both already existing and both pure/static (no `$DB`).
- Produces: a test that fails if the two default sets ever drift again.

**This is the one task that intentionally changes rendered output.** The defaults have already drifted:

| Token | `frontdoor.css` (unthemed site) | `CmsThemeTokens` (site saved at defaults) |
|---|---|---|
| `--fd-font-body` | `system-ui, -apple-system, sans-serif` | `'Open Sans', sans-serif` |
| `--fd-font-heading` | `'Archivo','Trebuchet MS',Georgia,serif` | `'Archivo','Open Sans',sans-serif` |

An unthemed org site and a site saved at defaults render in **different fonts today**. `CmsThemeTokens` is the authority — it is what the theme editor writes and what "reset to default" restores — so the CSS follows it.

- [ ] **Step 1: Write the failing parity test**

`tests/cms-theme/tokens_test.php` is a standalone script, not a PHPUnit case: it
`require`s the class directly, uses a local `check($label, $cond)` helper, and runs
via `php tests/cms-theme/tokens_test.php`. Match that style. Append before the file's
final summary/exit lines:

```php
// --- frontdoor.css default parity -------------------------------------------
// The .fd-page defaults in frontdoor.css are the fallback for a site with NO
// ork_cms_theme row. CmsThemeTokens is the authority — it is what the theme
// editor writes and what "reset to default" restores. If the two disagree, an
// unthemed site and a default-themed site render differently, which is exactly
// the drift this test exists to catch.
$cssPath = __DIR__ . '/../../orkui/template/default/frontdoor/css/frontdoor.css';
$css     = file_get_contents($cssPath);
check('frontdoor.css is readable', $css !== false);

// The authoritative rendering of the defaults, as the theme engine emits them.
$authoritative = CmsThemeTokens::ToCss(array());
preg_match('/\.fd-page\{([^}]*)\}/', $authoritative, $m);
check('ToCss() emitted a .fd-page block', !empty($m[1]));

$want = array();
foreach (explode(';', isset($m[1]) ? $m[1] : '') as $decl) {
    if (strpos($decl, ':') === false) {
        continue;
    }
    list($k, $v) = explode(':', $decl, 2);
    $want[trim($k)] = trim($v);
}

// The static fallbacks declared on .fd-page in frontdoor.css, comments stripped.
preg_match('/\.fd-page\s*\{(.*?)\n\}/s', (string) $css, $c);
check('frontdoor.css has a .fd-page block', !empty($c[1]));
$cssBlock = preg_replace('#/\*.*?\*/#s', '', isset($c[1]) ? $c[1] : '');

$norm = function ($v) {
    return preg_replace('/\s+/', ' ', trim((string) $v));
};

foreach ($want as $token => $value) {
    if (strpos($token, '--fd-') !== 0) {
        continue;
    }
    // --fd-font-scale is emitted as calc(1rem * N) but declared as a bare length
    // fallback; it is asserted separately below.
    if ($token === '--fd-font-scale') {
        continue;
    }
    if (!preg_match('/' . preg_quote($token, '/') . '\s*:\s*([^;]+);/', $cssBlock, $dm)) {
        check("frontdoor.css declares a fallback for $token", false);
        continue;
    }
    check(
        "default for $token matches CmsThemeTokens",
        $norm($dm[1]) === $norm($value)
    );
}

// --fd-font-scale MUST be a LENGTH, not a ratio: consumers multiply it by a
// unitless number, so a fallback of `1` yields rem*rem and the whole declaration
// is dropped at computed-value time.
check(
    '--fd-font-scale fallback is 1rem, not 1',
    (bool) preg_match('/--fd-font-scale\s*:\s*1rem\s*;/', $cssBlock)
);
```

- [ ] **Step 2: Run it and watch it fail**

```bash
php tests/cms-theme/tokens_test.php
```
Expected: `FAIL` lines for `--fd-font-body` (`'Open Sans', sans-serif` vs `system-ui, -apple-system, sans-serif`) and on `--fd-font-heading`.

- [ ] **Step 3: Fix the CSS to match the authority**

In `frontdoor.css`'s `.fd-page` block, change:

```css
    --fd-font-heading: 'Archivo', 'Trebuchet MS', Georgia, serif;
    --fd-font-body: system-ui, -apple-system, sans-serif;
```

to:

```css
    /* These MUST match CmsThemeTokens::Defaults() as rendered by its FontStack()
       helper — they are the fallback for a site with no ork_cms_theme row, and
       CmsThemeTokens is what the theme editor writes and what "reset to default"
       restores. tests/cms-theme/tokens_test.php fails if they drift.
       Not a faux-medieval face: a new site seeds no theme row, so whatever is
       defaulted here is what every org actually ships. MedievalSharp remains
       selectable in the theme editor for orgs that deliberately want it. */
    --fd-font-heading: 'Archivo', 'Open Sans', sans-serif;
    --fd-font-body: 'Open Sans', sans-serif;
```

Apply the same treatment to any other token the test flags.

- [ ] **Step 4: Run the test to verify it passes**

```bash
php tests/cms-theme/tokens_test.php
```
Expected: every line `PASS`, exit 0. Then confirm nothing else regressed:
```bash
for t in tests/cms-theme/tokens_test.php tests/cms-site/site_test.php \
         tests/cms-fields/allowlist_test.php tests/cms-sanitizer/sanitizer_test.php \
         tests/cms-tenancy/tenancy_test.php tests/cms-heraldry-color/color_test.php; do
  echo "== $t"; php "$t" | grep -c '^FAIL' || true
done
```
Expected: `0` for each.

- [ ] **Step 5: BROWSER CHECKPOINT**

`ambient-forest` is the unthemed site, so it is the one whose fonts change. Confirm headings and body copy now match a themed-at-defaults site, and that nothing reflows badly at 390px. `Open Sans` is already loaded by `default.theme` for every page, so no new font request is needed — confirm in the Network panel that no font 404s.

- [ ] **Step 6: Commit**

```bash
git add orkui/template/default/frontdoor/css/frontdoor.css tests/cms-theme/tokens_test.php
git commit -m "Bugfix: align frontdoor.css --fd-* defaults with CmsThemeTokens, with a parity test"
```

---

# PHASE 3 — Keep it that way

## Task 3.1: stylelint over the CMS CSS directories only

**Files:**
- Create: `.stylelintrc.json`
- Create: `.stylelintignore`
- Modify: `package.json` (devDependency + scripts)

**Interfaces:**
- Consumes: the final CMS CSS file set from Phase 2.
- Produces: `npm run lint:css` (check) and `npm run lint:css:fix` (autofix), used by Task 3.3's hook.

**Scoped deliberately to the CMS.** The CRM's `orkui.css` and `reports.css` are mixed tabs/spaces with 78-79 `!important`s each. Linting them would mean a repo-wide reformat nobody asked for and a diff that buries the real work. Scoping the linter to the CMS directories is exactly goal #3: the CMS gets to hold a stricter standard than the CRM, and can raise it further without negotiating with CRM code.

- [ ] **Step 1: Add the dependency**

```bash
npm install --save-dev stylelint@^16 stylelint-config-standard@^36
```

- [ ] **Step 2: Write the config**

`.stylelintrc.json`:

```json
{
  "extends": ["stylelint-config-standard"],
  "rules": {
    "indentation": 4,
    "no-descending-specificity": null,
    "custom-property-pattern": "^(fd|cms|pk|kn|pn|ork|navy|gold|ink|z)([a-z0-9-]*)$",
    "selector-class-pattern": null,
    "declaration-block-no-redundant-longhand-properties": null,
    "alpha-value-notation": null,
    "color-function-notation": null,
    "media-feature-range-notation": "prefix",
    "shorthand-property-no-redundant-values": null,
    "comment-empty-line-before": null,
    "declaration-empty-line-before": null,
    "rule-empty-line-before": null,
    "value-keyword-case": null,
    "no-duplicate-selectors": true,
    "declaration-block-no-duplicate-properties": [true, { "ignore": ["consecutive-duplicates-with-different-values"] }],
    "block-no-empty": true,
    "color-no-invalid-hex": true,
    "no-invalid-double-slash-comments": true,
    "font-family-no-missing-generic-family-keyword": true
  }
}
```

Notes on the deliberate `null`s: `selector-class-pattern` is off because the CMS uses short prefixed classes (`.pe-card`, `.te-select`) that no single kebab pattern usefully describes; `alpha-value-notation` and `color-function-notation` are off because enforcing them would rewrite hundreds of existing `rgba(…, .4)` values for zero benefit; `no-descending-specificity` is off because the dark-mode-after-light-mode structure legitimately triggers it everywhere. `media-feature-range-notation: prefix` keeps the existing `max-width:` syntax rather than rewriting every query to range notation. The three rules that are ON and matter — `no-duplicate-selectors`, `declaration-block-no-duplicate-properties`, `font-family-no-missing-generic-family-keyword` — are the ones that catch the classes of bug Phase 2 just cleaned up.

`.stylelintignore`:

```
node_modules/**
vendor/**
orkui/template/default/script/**
orkui/template/revised-frontend/**
orkui/template/default/style/**
tools/fuzzy-validator/evidence/**
build/**
```

Note `orkui/template/default/style/**` is ignored on purpose: that directory is CRM-owned. The linter's scope is the CMS.

- [ ] **Step 3: Add the scripts**

In `package.json`'s `scripts`:

```json
    "lint:css": "stylelint \"orkui/template/default/frontdoor/css/*.css\" \"orkui/template/default/cms/css/*.css\"",
    "lint:css:fix": "stylelint --fix \"orkui/template/default/frontdoor/css/*.css\" \"orkui/template/default/cms/css/*.css\"",
```

- [ ] **Step 4: Run it and fix what it finds**

```bash
npm run lint:css
```
Expected on first run: a list of findings, most of them auto-fixable formatting (the `staff_roster` section lifted in Task 2.1 is single-line minified while the rest of the file is expanded). Then:

```bash
npm run lint:css:fix && npm run lint:css
```
Expected: clean exit 0. Anything the autofixer cannot resolve, fix by hand — but if a rule produces noise with no real defect behind it, turn the rule off in `.stylelintrc.json` with a one-line comment in this plan's spirit rather than churning the CSS to satisfy it.

- [ ] **Step 5: BROWSER CHECKPOINT**

`--fix` rewrites whitespace and can reorder nothing, but it does normalize values (e.g. `#FFF` → `#fff`, `0.5` → `.5` depending on rules). Re-load `burning-lands`, `ambient-forest`, `/`, and `Cms/theme` in both themes to confirm the autofix was cosmetic.

- [ ] **Step 6: Commit**

```bash
git add .stylelintrc.json .stylelintignore package.json package-lock.json \
        orkui/template/default/frontdoor/css/ orkui/template/default/cms/css/
git commit -m "Enforcement: add stylelint over the CMS CSS directories"
```

---

## Task 3.2: `bin/check-css-boundaries.sh` — the CMS/CRM CSS gate

**Files:**
- Create: `bin/check-css-boundaries.sh` (executable)

**Interfaces:**
- Consumes: the file layout established in Phases 1-2.
- Produces: a script with the same CLI contract as `bin/check-layering.sh` (`--staged`, `--range REV..REV`, `--files …`, `--all`; exit 0 clean, 1 violations, 2 bad invocation), consumed by Task 3.3's hooks.

**The five rules**, from the spec:

| | Rule | Scope |
|---|---|---|
| C1 | CMS CSS may not name ORK shell selectors (`#theme_container`, `#newmenu`, `.ork-`) | **public** CMS `.css` + `.tpl` under `frontdoor/`, except `orkshell-interop.css` and `cms-base.css` |
| C2 | CMS CSS may not *define* an `--ork-*` token (reading via `var()` is fine) | CMS `.css` + CMS `.tpl` |
| C3 | No `<style>` blocks in `frontdoor/blocks/*.tpl` | block templates |
| C4 | `Site_shell.tpl` may not link `orkui.css`, `tokens.css` or `orkshell-interop.css` | `Site_shell.tpl` |
| C5 | CRM CSS may not name CMS selectors (`.fd-`, `.cms-`, `.org-`) | `style/orkui.css`, `style/tokens.css`, `style/reports.css` |

**Two deliberate C1 exemptions, and one deliberate scope limit.**

- `orkshell-interop.css` is the designated coupling point — exempting it is the whole design.
- `cms-base.css` must name `#theme_container` once, to neutralize the container that
  `default.theme` emits on every page including standalone org sites. Stating that
  full-bleed intent explicitly is safer than relying on the absence of a rule, so the
  file is exempt too.
- **C1 does not apply to `cms/css/cms-admin.css`.** The OGRE admin is definitionally
  an ORK-hosted application surface — it renders inside the shell, under the ORK nav,
  and always will. Two of its rules (`#theme_container a.cms-btn-primary` in light and
  dark) legitimately out-specify an ORK link rule, and there is no portability claim
  to protect. It is the *public* side that must be able to stand alone, so that is
  where C1 bites. C2 still applies to the admin: reading `--ork-*` is correct there,
  defining one is not.

- [ ] **Step 1: Write the script**

```sh
#!/bin/sh
#
# ORK3 CMS/CRM CSS boundary gate.
#
# The CMS ("OGRE") is a separate product hosted inside the ORK, and its CSS is
# kept physically separate from the CRM's so the two can evolve independently:
#
#   orkui/template/default/style/          CRM-owned  (orkui.css, tokens.css, reports.css)
#   orkui/template/default/frontdoor/css/  CMS public side
#   orkui/template/default/cms/css/        CMS admin side
#
# Standalone public org sites do not load the CRM stylesheets at all (see the
# $IsOrgSite gate in default.theme). The one place the two layers are allowed to
# touch is frontdoor/css/orkshell-interop.css, which exists precisely so that
# coupling is visible in one file instead of scattered.
#
# Reference: docs/superpowers/specs/2026-08-21-cms-css-separation-design.md
#
# Usage:
#   bin/check-css-boundaries.sh --staged              # staged blob content (pre-commit)
#   bin/check-css-boundaries.sh --range master..HEAD  # files changed in a range (pre-push)
#   bin/check-css-boundaries.sh --files a.css b.tpl   # explicit paths (editor hook)
#   bin/check-css-boundaries.sh --all                 # every tracked file in scope (audit)
#
# Exit 0 = clean, 1 = violations found, 2 = bad invocation.
#
# Escape hatch (deliberate exceptions only): ORK3_ALLOW_LAYER_VIOLATION=1 is
# honoured by the git hooks that call this script, not by the script itself.

usage() {
    sed -n '3,26p' "$0" | sed 's/^# \{0,1\}//'
}

LC_ALL=C
export LC_ALL

REPO_ROOT=$(git rev-parse --show-toplevel 2>/dev/null)
if [ -z "$REPO_ROOT" ]; then
    echo "check-css-boundaries: not inside a git repository — skipping." >&2
    exit 0
fi
cd "$REPO_ROOT" || exit 0

MODE=""
RANGE=""
FILES=""

while [ $# -gt 0 ]; do
    case "$1" in
        --staged) MODE=staged ;;
        --all)    MODE=all ;;
        --range)
            MODE=range
            RANGE="$2"
            [ -z "$RANGE" ] && { echo "check-css-boundaries: --range needs a revision range." >&2; exit 2; }
            shift
            ;;
        --files)
            MODE=files
            shift
            FILES="$*"
            break
            ;;
        -h|--help) usage; exit 0 ;;
        *) echo "check-css-boundaries: unknown argument '$1'" >&2; usage >&2; exit 2 ;;
    esac
    shift
done

[ -z "$MODE" ] && MODE=all

CMS_PUBLIC="orkui/template/default/frontdoor"
CMS_ADMIN="orkui/template/default/cms"
CRM_STYLE="orkui/template/default/style"
INTEROP="$CMS_PUBLIC/css/orkshell-interop.css"
CMS_BASE="$CMS_PUBLIC/css/cms-base.css"
SITE_SHELL="orkui/template/default/Site_shell.tpl"

case "$MODE" in
    staged) CANDIDATES=$(git diff --cached --name-only --diff-filter=ACM) ;;
    range)  CANDIDATES=$(git diff --name-only --diff-filter=ACM "$RANGE") ;;
    files)  CANDIDATES=$(printf '%s\n' $FILES | sed "s|^$REPO_ROOT/||") ;;
    all)    CANDIDATES=$(git ls-files "$CMS_PUBLIC/*" "$CMS_ADMIN/*" "$CRM_STYLE/*" "$SITE_SHELL") ;;
esac

[ -z "$CANDIDATES" ] && exit 0

CONTENT=$(mktemp) || exit 2
AWKPROG=$(mktemp) || exit 2
trap 'rm -f "$CONTENT" "$AWKPROG"' EXIT INT TERM

if [ -t 1 ]; then
    C_RED=$(printf '\033[31m'); C_DIM=$(printf '\033[2m'); C_OFF=$(printf '\033[0m')
else
    C_RED=""; C_DIM=""; C_OFF=""
fi

cat > "$AWKPROG" <<'AWKEOF'
function report(rule, msg, fix) {
    printf "  %s%s%s  %s:%d\n", C_RED, rule, C_OFF, file, FNR
    printf "        %s\n", msg
    printf "        %s-> %s%s\n", C_DIM, fix, C_OFF
    hits++
}
{
    line = $0

    # Comment lines are documentation, not rules. The files in scope discuss
    # these very patterns in prose, so skip them.
    if (line ~ /^[ \t]*(\/\/|#|\*|\/\*)/) next

    if (C1 && line ~ /(#theme_container|#newmenu|\.ork-)/)
        report("C1", "CMS CSS names an ORK application-shell selector.", \
               "Standalone org sites do not load orkui.css, so this rule is dead there and couples the layers everywhere else. Move it to frontdoor/css/orkshell-interop.css.")

    # C2: DEFINING an --ork-* token. `var(--ork-x)` is a read and is fine; a
    # bare `--ork-x:` at the start of a declaration is a write.
    if (C2 && line ~ /(^|[;{ \t])--ork-[a-z0-9-]+[ \t]*:/)
        report("C2", "CMS CSS defines a token in the CRM's --ork-* namespace.", \
               "Rename it to --cms-* or --fd-* and scope it to the CMS root. Reading an --ork-* token with var() is fine; defining one is not.")

    if (C3 && line ~ /<style/)
        report("C3", "Inline <style> block in a content-block template.", \
               "Block CSS belongs in frontdoor/css/blocks.css so it is cacheable, lintable and visible to duplication analysis. Only per-instance authored values may stay inline.")

    if (C4 && line ~ /(orkui\.css|tokens\.css|orkshell-interop\.css)/)
        report("C4", "Site_shell.tpl links a stylesheet a standalone org site must not load.", \
               "Org sites load cms-base.css instead of the CRM stylesheets, and need no ORK-shell interop layer. Remove the link.")

    if (C5 && line ~ /(\.fd-|\.cms-|\.org-)/)
        report("C5", "CRM CSS names a CMS selector.", \
               "The CRM must not style CMS surfaces. Move the rule into the matching CMS stylesheet under frontdoor/css/ or cms/css/.")
}
END { exit (hits > 0) ? 1 : 0 }
AWKEOF

TOTAL=0

for f in $CANDIDATES; do
    case "$f" in
        */vendor/*|*/node_modules/*) continue ;;
    esac

    C1=0; C2=0; C3=0; C4=0; C5=0

    case "$f" in
        # The two files allowed to name an ORK selector. $INTEROP is the designated
        # coupling point; $CMS_BASE must neutralize the #theme_container that
        # default.theme emits on standalone org sites. Both still get C2.
        "$INTEROP"|"$CMS_BASE") C2=1 ;;
        # Public CMS side: must be able to stand alone -> C1 + C2.
        "$CMS_PUBLIC"/css/*.css|"$CMS_PUBLIC"/*.tpl|"$CMS_PUBLIC"/blocks/*.tpl) C1=1; C2=1 ;;
        # OGRE admin: definitionally in-shell, so C1 does not apply. C2 does.
        "$CMS_ADMIN"/css/*.css|"$CMS_ADMIN"/*.tpl) C2=1 ;;
    esac
    case "$f" in
        "$CMS_PUBLIC"/blocks/*.tpl) C3=1 ;;
    esac
    case "$f" in
        "$SITE_SHELL") C4=1 ;;
    esac
    case "$f" in
        "$CRM_STYLE"/orkui.css|"$CRM_STYLE"/tokens.css|"$CRM_STYLE"/reports.css) C5=1 ;;
    esac

    [ "$C1$C2$C3$C4$C5" = "00000" ] && continue

    if [ "$MODE" = "staged" ]; then
        git show ":$f" > "$CONTENT" 2>/dev/null || continue
    else
        [ -f "$f" ] || continue
        cat "$f" > "$CONTENT" 2>/dev/null || continue
    fi

    awk -v file="$f" -v C_RED="$C_RED" -v C_DIM="$C_DIM" -v C_OFF="$C_OFF" \
        -v C1="$C1" -v C2="$C2" -v C3="$C3" -v C4="$C4" -v C5="$C5" \
        -f "$AWKPROG" "$CONTENT"
    [ $? -ne 0 ] && TOTAL=$((TOTAL + 1))
done

if [ "$TOTAL" -gt 0 ]; then
    echo ""
    echo "  CSS boundary gate: $TOTAL file(s) cross the CMS/CRM line."
    echo "  Design: docs/superpowers/specs/2026-08-21-cms-css-separation-design.md"
    echo "  Audit with: bin/check-css-boundaries.sh --all"
    exit 1
fi

exit 0
```

- [ ] **Step 2: Make it executable and run a full audit**

```bash
chmod +x bin/check-css-boundaries.sh
bin/check-css-boundaries.sh --all; echo "exit=$?"
```
Expected: `exit=0`. **If it is not zero, that is a real finding from Phases 1-2 — fix the CSS, not the gate.** The only legitimate reason to relax a rule is a false positive on a pattern the rule was not written for; note it in the script's comments if so.

- [ ] **Step 3: Prove each rule actually fires**

A gate nobody has seen fail is a gate that does not work. Verify all five against throwaway edits, reverting each:

```bash
set -e
t() { printf "  %-4s " "$1"; shift; "$@" >/dev/null 2>&1 && echo "DID NOT FIRE (bad)" || echo "fires"; }

echo '#theme_container a.zz { color: red; }' >> orkui/template/default/frontdoor/css/blocks.css
t C1 bin/check-css-boundaries.sh --files orkui/template/default/frontdoor/css/blocks.css
git checkout -- orkui/template/default/frontdoor/css/blocks.css

echo '.zz { --ork-zz: red; }' >> orkui/template/default/cms/css/cms-admin.css
t C2 bin/check-css-boundaries.sh --files orkui/template/default/cms/css/cms-admin.css
git checkout -- orkui/template/default/cms/css/cms-admin.css

echo '<style>.zz{color:red}</style>' >> orkui/template/default/frontdoor/blocks/quote.tpl
t C3 bin/check-css-boundaries.sh --files orkui/template/default/frontdoor/blocks/quote.tpl
git checkout -- orkui/template/default/frontdoor/blocks/quote.tpl

echo '<link rel="stylesheet" href="x/orkui.css">' >> orkui/template/default/Site_shell.tpl
t C4 bin/check-css-boundaries.sh --files orkui/template/default/Site_shell.tpl
git checkout -- orkui/template/default/Site_shell.tpl

echo '.fd-zz { color: red; }' >> orkui/template/default/style/orkui.css
t C5 bin/check-css-boundaries.sh --files orkui/template/default/style/orkui.css
git checkout -- orkui/template/default/style/orkui.css

echo "--- interop + cms-base must be exempt from C1:"
bin/check-css-boundaries.sh --files orkui/template/default/frontdoor/css/orkshell-interop.css; echo "  interop  exit=$? (expect 0)"
bin/check-css-boundaries.sh --files orkui/template/default/frontdoor/css/cms-base.css; echo "  cms-base exit=$? (expect 0)"
echo "--- but they are NOT exempt from C2:"
echo '.zz { --ork-zz: red; }' >> orkui/template/default/frontdoor/css/cms-base.css
t C2ex bin/check-css-boundaries.sh --files orkui/template/default/frontdoor/css/cms-base.css
git checkout -- orkui/template/default/frontdoor/css/cms-base.css
echo "--- admin is exempt from C1 (in-shell by definition):"
bin/check-css-boundaries.sh --files orkui/template/default/cms/css/cms-admin.css; echo "  admin exit=$? (expect 0)"
echo "--- clean tree:"
bin/check-css-boundaries.sh --all; echo "exit=$? (expect 0)"
```
Expected: `fires` on all five, `exit=0` for the interop file and the clean tree.

- [ ] **Step 4: Confirm the working tree is clean after the probes**

```bash
git status --porcelain
```
Expected: only `bin/check-css-boundaries.sh` as untracked/new. If any CSS file shows modified, a `git checkout --` above did not run; restore it before committing.

- [ ] **Step 5: Commit**

```bash
git add bin/check-css-boundaries.sh
git commit -m "Enforcement: add bin/check-css-boundaries.sh, the CMS/CRM CSS gate"
```

---

## Task 3.3: Wire the gate into the hooks and document it

**Files:**
- Modify: `.githooks/pre-commit`
- Modify: `.githooks/pre-push`
- Modify: `docs/superpowers/specs/2026-08-21-cms-css-separation-design.md` (mark implemented)
- Create: `orkui/template/default/frontdoor/css/README.md`

**Interfaces:**
- Consumes: `bin/check-css-boundaries.sh` (Task 3.2) and `npm run lint:css` (Task 3.1).
- Produces: a blocking pre-commit/pre-push check with the shared `ORK3_ALLOW_LAYER_VIOLATION=1` escape hatch.

- [ ] **Step 1: Add the check to pre-commit, beside the layering gate**

In `.githooks/pre-commit`, immediately after the existing section 2 (`bin/check-layering.sh --staged`) block and before section 3 (php-cs-fixer), insert:

```sh
# ---------------------------------------------------------------------------
# 2b. CMS/CRM CSS boundary gate — BLOCKS the commit.
#     Same escape hatch as the layering gate above.
# ---------------------------------------------------------------------------
if [ -x bin/check-css-boundaries.sh ]; then
    if ! bin/check-css-boundaries.sh --staged; then
        if [ "${ORK3_ALLOW_LAYER_VIOLATION:-}" = "1" ]; then
            echo ""
            echo "⚠️  ORK3_ALLOW_LAYER_VIOLATION=1 — committing despite the CSS boundary violations above."
        else
            echo ""
            echo "❌ Commit blocked: staged changes cross the CMS/CRM CSS boundary."
            echo "   The CMS is a separate product hosted inside the ORK; its CSS lives under"
            echo "   frontdoor/css/ and cms/css/, and the ONE place the layers may touch is"
            echo "   frontdoor/css/orkshell-interop.css. Fix the sites above, or if this is a"
            echo "   deliberate exception:"
            echo ""
            echo "       ORK3_ALLOW_LAYER_VIOLATION=1 git commit …"
            echo ""
            exit 1
        fi
    fi
fi
```

Also update the hook's own header comment: it says "Does three things" — make it four and describe the new one.

- [ ] **Step 2: Add both checks to pre-push**

`.githooks/pre-push` computes `$RANGE` **inside** a `while read -r LOCAL_REF …` loop
(one iteration per pushed ref) and records failure in `FAILED=1`, which is checked
once after the loop. The CSS gate must therefore go *inside* that loop, beside the
existing `bin/check-layering.sh --range "$RANGE"` call — not after it, where `$RANGE`
holds only the last ref's value or nothing at all.

Two edits.

**(a)** The hook currently early-exits when the layering script is missing:

```sh
[ -x bin/check-layering.sh ] || exit 0
```

That would skip the CSS gate too. Replace with a check that at least one gate exists:

```sh
# At least one gate must be present; each call below is individually guarded.
if [ ! -x bin/check-layering.sh ] && [ ! -x bin/check-css-boundaries.sh ]; then
    exit 0
fi
```

**(b)** Inside the loop, immediately after the `bin/check-layering.sh --range "$RANGE" || FAILED=1` line, add:

```sh
    if [ -x bin/check-css-boundaries.sh ]; then
        echo "🎨 CSS boundary gate: $RANGE"
        bin/check-css-boundaries.sh --range "$RANGE" || FAILED=1
    fi
```

and guard the existing layering call the same way so a missing script cannot break the loop:

```sh
    if [ -x bin/check-layering.sh ]; then
        echo "🔍 Layering gate: $RANGE"
        bin/check-layering.sh --range "$RANGE" || FAILED=1
    fi
```

The existing post-loop `if [ "$FAILED" = 1 ]` block already honours
`ORK3_ALLOW_LAYER_VIOLATION=1`, so both gates share one escape hatch with no further
change. Update that block's message text to name both gates rather than only layering:

```sh
    echo "❌ Push blocked: commits on this branch break the ORK3 layer separation"
    echo "   or the CMS/CRM CSS boundary (see the gate output above)."
    echo ""
    echo "   Audit the whole tree:  bin/check-layering.sh --all"
    echo "                          bin/check-css-boundaries.sh --all"
    echo "   Deliberate exception:  ORK3_ALLOW_LAYER_VIOLATION=1 git push …"
```

**(c)** Add the advisory stylelint pass once, *after* the loop and before the
`FAILED` check. Advisory only — a fresh clone without `npm install` must still push:

```sh
# stylelint over the CMS CSS. Advisory: never blocks a push.
if [ -x node_modules/.bin/stylelint ]; then
    if ! npm run --silent lint:css; then
        echo "⚠️  stylelint reported CMS CSS problems above (not blocking the push)."
        echo "   Fix with: npm run lint:css:fix"
    fi
fi
```

- [ ] **Step 3: Write the directory README**

`orkui/template/default/frontdoor/css/README.md`:

```markdown
# CMS CSS

The CMS ("OGRE" — Online Gallery and Resource Engine) is a separate product
hosted inside the ORK. Its CSS is kept physically separate from the CRM's so the
two can evolve independently.

## Where things live

| Path | Owns |
|---|---|
| `orkui/template/default/style/` | **CRM.** `orkui.css`, `tokens.css`, `reports.css`. Not ours. |
| `frontdoor/css/cms-base.css` | Minimal global base for **standalone** org sites. Base only — no components. |
| `frontdoor/css/frontdoor.css` | Front-door marketing chrome, `.fd-*`, and the `--fd-*` token defaults. |
| `frontdoor/css/blocks.css` | Content-block CSS for `frontdoor/blocks/*.tpl`. |
| `frontdoor/css/blog.css` | Blog index + single post. |
| `frontdoor/css/orgsite.css` | Per-org standalone site chrome. |
| `frontdoor/css/orkshell-interop.css` | **The only** file that may name an ORK selector. |
| `cms/css/cms-admin.css` | OGRE admin. Renders inside the ORK shell, so it may *read* `--ork-*`. |

## Load order (it is load-bearing)

`frontdoor.css` → `blocks.css` → `blog.css` → then `orgsite.css` (standalone org
sites) or `orkshell-interop.css` (in-shell surfaces). `blocks.css` and `blog.css`
were split off the end of `frontdoor.css` and several of their rules win
same-specificity ties against it. Emitted by `frontdoor/_assets_public.tpl` and
`frontdoor/_assets_inshell.tpl` — add stylesheets there, not in page templates.

## The three surface tiers

| Tier | Flag | In ORK shell? | Loads `orkui.css`? |
|---|---|---|---|
| Standalone org site `/k/{slug}` | `$IsOrgSite` | no | **no** — gets `cms-base.css` |
| Front door, CMS page, blog | `$IsFrontDoor` / `$IsCmsPage` | yes | yes |
| OGRE admin `Cms/*` | — | yes | yes |

## Rules (enforced by `bin/check-css-boundaries.sh`)

- **C1** Don't name `#theme_container`, `#newmenu` or `.ork-*` outside `orkshell-interop.css`.
- **C2** Don't *define* an `--ork-*` token. Reading one with `var()` is fine.
- **C3** No `<style>` blocks in `frontdoor/blocks/*.tpl` — block CSS goes in `blocks.css`.
- **C4** `Site_shell.tpl` must not link `orkui.css`, `tokens.css` or `orkshell-interop.css`.
- **C5** CRM CSS must not name `.fd-*`, `.cms-*` or `.org-*`.

Audit: `bin/check-css-boundaries.sh --all` · Lint: `npm run lint:css` (`:fix` to autofix).
Deliberate exception: `ORK3_ALLOW_LAYER_VIOLATION=1 git commit …`

## Conventions

- 4-space indent, never tabs (the CRM files are mixed; ours are not).
- Dark mode is `html[data-theme="dark"]`, never a bare `prefers-color-scheme` block,
  and lives next to the rule it overrides — not in a distant theme section.
- `--fd-*` defaults in `frontdoor.css` must match `CmsThemeTokens::Defaults()`.
  `tests/cms-theme/tokens_test.php` fails if they drift.
```

- [ ] **Step 4: Test both hooks end-to-end**

```bash
# pre-commit fires and blocks
echo '.fd-zz { color: red; }' >> orkui/template/default/style/orkui.css
git add orkui/template/default/style/orkui.css
git commit -m "should be blocked" ; echo "exit=$? (expect non-zero)"

# the escape hatch works
ORK3_ALLOW_LAYER_VIOLATION=1 git commit -m "temp: escape hatch check" ; echo "exit=$? (expect 0)"

# clean up the temp commit and the probe edit
git reset --hard HEAD~1
git status --porcelain   # expect clean
```
Expected: the plain commit is refused with the C5 message, the escape-hatch commit succeeds, and `git reset --hard HEAD~1` removes it. **Confirm `git status` is clean and `git log -1` is the Task 3.2 commit before continuing** — a stray probe commit must not survive.

- [ ] **Step 5: Final full audit**

```bash
bin/check-layering.sh --all; echo "layering exit=$?"
bin/check-css-boundaries.sh --all; echo "css-boundaries exit=$?"
npm run lint:css; echo "stylelint exit=$?"
for t in tests/cms-theme/tokens_test.php tests/cms-site/site_test.php \
         tests/cms-fields/allowlist_test.php tests/cms-sanitizer/sanitizer_test.php \
         tests/cms-tenancy/tenancy_test.php tests/cms-heraldry-color/color_test.php; do
  printf "  %-46s FAILs=%s\n" "$t" "$(php "$t" | grep -c '^FAIL')"
done
```
Expected: all three exits `0`, all `FAILs=0`.

- [ ] **Step 6: Mark the spec implemented and commit**

Change the spec's `**Status:**` line to `implemented 2026-08-21` and add a one-line pointer to the README.

```bash
git add .githooks/pre-commit .githooks/pre-push \
        orkui/template/default/frontdoor/css/README.md \
        docs/superpowers/specs/2026-08-21-cms-css-separation-design.md
git commit -m "Enforcement: block CMS/CRM CSS boundary breaks in pre-commit and pre-push"
```

---

# Final verification (run after Phase 3)

Full browser sweep, every surface in **both** themes at desktop and 390px:

| Surface | URL |
|---|---|
| Front door | `http://localhost:19080/` |
| Org site — themed kingdom | `…/orkui/index.php?Route=Site/view/burning-lands` |
| Org site — themed kingdom 2 | `…?Route=Site/view/kingdom-17` |
| Org site — themed park | `…?Route=Site/view/ambient-forest&_pfx=p` |
| Blog index / post | `…?Route=Blog/index` |
| OGRE dashboard | `…?Route=Cms/dashboard` |
| OGRE theme editor | `…?Route=Cms/theme` |
| OGRE media | `…?Route=Cms/media` |
| OGRE nav | `…?Route=Cms/nav` |

Then confirm the headline number: a standalone org site no longer downloads the
CRM's 91 KB `orkui.css`.

```bash
curl -s "http://localhost:19080/orkui/index.php?Route=Site/view/ambient-forest" \
  | grep -o 'href="[^"]*\.css[^"]*"'
```
Expected: `cms-base.css`, `frontdoor.css`, `blocks.css`, `blog.css`, `orgsite.css` —
and **no** `orkui.css`, `tokens.css` or `orkshell-interop.css`.
