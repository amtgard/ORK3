# CMS / Front-Door CSS Separation — Design

**Date:** 2026-08-21
**Branch:** `feature/front-door`
**Status:** implemented 2026-08-21
**Working reference:** `orkui/template/default/frontdoor/css/README.md` — the
day-to-day guide to the layout, the load order and the enforced rules.

## Problem

The CMS ("OGRE") is designed as a separate product hosted inside the ORK. Its CSS
does not yet reflect that. Three defects, one per stated goal:

1. **Delineation.** `default.theme:95-96` links the CRM's `tokens.css` + `orkui.css`
   unconditionally. Every other piece of ORK chrome is gated on `$IsOrgSite`
   (GTM `:7`, nav `:370`, footer `:957`) — the stylesheets are not. A visitor to a
   standalone public org site at `/k/{slug}` therefore downloads **91 KB** of CRM
   application CSS they never use, and that CSS actively fights the CMS design:
   **37 override sites** exist for no reason other than undoing it. Separately,
   `cms-admin.css` (2,436 lines) lives in the CRM style directory next to
   `orkui.css`, and defines an `--ork-*` token it does not own.

2. **DRY.** ~2,200 lines of CSS live inside `<style>` blocks across 33 templates —
   uncacheable, unlintable, invisible to duplication analysis. A parse of all 1,283
   CMS rules found 19 groups of byte-identical declaration blocks, dominated by a
   feed-block family (`.pe-*`/`.ke-*`/`.bf-*`/`.kp-*`/`.ko-*`) copied 3-5 times.
   The `--fd-*` token defaults have two sources of truth — `frontdoor.css` and
   `CmsThemeTokens::Defaults()` — which have **already drifted**.

3. **Independent evolution.** PHP has php-cs-fixer, a blocking layering gate, a
   pre-commit and a pre-push hook. CSS has no linter, no formatter, no config and
   no hook. Nothing mechanically prevents the CMS/CRM boundary from re-eroding.

## Non-goals

- Restyling anything. This is a structural change; **rendered output must not change**
  except where a fix is explicitly called out (the font drift in Task 2.4).
- Touching CRM CSS quality (`orkui.css`, `reports.css`, `Directory_index.tpl`'s
  534 inline lines). Out of scope.
- Renaming CMS CSS classes. Templates keep their existing hooks.

## Key architectural constraint

**Not every CMS surface is standalone.** Three tiers, and they need different things:

| Tier | Flag | Renders inside ORK shell? | Loads `orkui.css`? |
|---|---|---|---|
| Standalone org site (`/k/{slug}`) | `$IsOrgSite` | No (nav+footer suppressed) | **Should not** |
| Front door `/`, CMS page, blog | `$IsFrontDoor` / `$IsCmsPage` | Yes | Yes — must |
| OGRE admin (`Cms/*`) | — | Yes | Yes — must |

So the `#theme_container a` overrides **cannot simply be deleted** — the in-shell
tiers still need them. They must instead be *quarantined*: moved out of
`frontdoor.css` into a single honestly-named `orkshell-interop.css` that is loaded
only by the in-shell surfaces and never by a standalone org site. The boundary then
becomes explicit, greppable, and enforceable.

## Target file layout

```
orkui/template/default/
  style/                          CRM-OWNED (unchanged)
    tokens.css                      --ork-* design tokens
    orkui.css                       CRM application CSS
    reports.css                     CRM tool-page shell
  frontdoor/css/                  CMS PUBLIC-SIDE
    cms-base.css        NEW         minimal global base for STANDALONE org sites
    frontdoor.css                   .fd-* marketing/shell chrome
    blocks.css          NEW         content-block CSS (was inline in 20 tpls)
    blog.css            NEW         blog index + single post
    orgsite.css                     per-org site chrome
    orkshell-interop.css NEW        the ONLY place CMS CSS may name ORK selectors
  cms/css/                        CMS ADMIN-SIDE
    cms-admin.css       MOVED       from style/cms-admin.css
```

## Enforcement contract (Phase 3) — as built

`bin/check-css-boundaries.sh`, wired into `.githooks/pre-commit` (`--staged`) and
`.githooks/pre-push` (`--range`, inside the per-ref loop) beside the existing
layering gate, sharing its `ORK3_ALLOW_LAYER_VIOLATION=1` escape hatch. Both
gates block; exit 0 = clean, 1 = violations, 2 = bad invocation. Comment text is
stripped before the rules run, because every file in scope discusses these very
patterns in prose.

**Scope is derived, not listed.** Every rule below originally named the files it
applied to. That made the gate strongest on the files that already existed and
blind to everything new: a static `<style>` could be put straight back into any
of the nine templates whose inline CSS the refactor had just lifted out, a new
partial one directory deeper defeated C4, and a new surface template or a new
stylesheet was born with no coverage at all. The scope rules now are:

- **R1** Everything under `frontdoor/` is the CMS **public** tier; everything
  under `cms/` is the CMS **admin** tier — at any depth.
- **R2** The router resolves `<Controller>/<action>` to
  `$TPL_ROOT/<Controller>_<action>.tpl`, so CMS page templates sit one directory
  up beside the CRM's. The CMS controllers are `Site`, `Page`, `Blog`, `Cms`
  (`orkui/controller/controller.{Site,Page,Blog,Cms}.php`), giving
  `_index.tpl` + `Site_*.tpl` + `Page_*.tpl` + `Blog_*.tpl` + `Cms_preview*.tpl`
  as the public tier and every other `Cms_*.tpl` as the admin tier. A new
  `Site_home.tpl` / `Blog_tag.tpl` / `Page_index.tpl` is in scope on creation.
  The one manual step the design keeps is adding a **controller** name to
  `CMS_CONTROLLERS` when a CMS controller is added — per controller, not per
  file. `Cms_preview*` is a naming contract: a `Cms_` template that renders the
  public page must be named that way to land on the public tier.
- **R3** Any `.css` under `orkui/template/` that is not CMS-owned is CRM CSS, so
  C5 guards a stylesheet dropped at `default/probe-tween.css`, in `orkremental/`
  or in `revised-frontend/style/` — not only the ones under `style/`.
- **R4** `--all` unions `git ls-files` with `git ls-files --others
  --exclude-standard` over the same pathspecs and names the untracked files it
  pulled in. An audit could otherwise report a clean tree while an unguarded,
  not-yet-added template sat in the working copy.
- **R5** A symlink in an in-scope path is rejected (C0), never scanned: in
  `--staged` mode `git show :path` on a symlink returns the **link target**, one
  short line that trivially passes every rule, so a staged symlink was a way to
  ship arbitrary CSS/markup into an in-scope path with the gate green.

- **C0** The scanner must be able to parse the file. A comment opener that is
  never closed leaves every later line unscanned, so it is reported instead of
  passing silently. See "Comment handling" below. A symlink in an in-scope path
  (R5) is reported here too.
- **C1** CMS CSS/markup may not name an ORK application-shell selector
  (`#theme_container`, `#newmenu`, `.ork-`). **Both** stylesheet selectors and
  template *markup* are checked — `id="theme_container"`, and a `class`
  attribute whose token list starts a token with `ork-` — and CSS identifier
  escapes (`#theme\_container`, `.ork\-x`, `#\74 heme_container`) are decoded
  before matching, because they are semantically identical CSS. So are the
  **attribute-selector** spellings — `[id="theme_container"]`,
  `[class~="ork-card"]`, `[class^="ork-"]` — which are different CSS syntax for
  the same coupling, not a different rule.
  *Scope:* the **public tier** — R1's `frontdoor/**` plus R2's public surface
  templates. (`Cms_preview*` is on that tier deliberately — it renders the
  *public* page inside a preview chrome, unlike the rest of the `Cms_*`
  screens.)
  *Exempt:* `orkshell-interop.css` (the designated coupling point) fully, and
  `cms-base.css` **narrowly** — it may name `#theme_container`, because it has
  to neutralize the container `default.theme` emits on standalone org sites,
  and nothing else. `#newmenu` and `.ork-` are still rejected there. That
  narrowness matters: `cms-base.css` is the one stylesheet a standalone org
  site loads globally, so an unbounded exemption would let the whole
  quarantined override layer migrate back into it with the gate green.
  *Out of scope:* the admin tier — `cms/**` and the non-preview `Cms_*`
  templates. The OGRE admin is definitionally an ORK-hosted application surface,
  renders inside the shell, and has no portability claim to protect. C2 still
  applies there.
- **C2** CMS CSS may not *define* a token in the CRM's `--ork-*` namespace.
  Reading one with `var()` is fine — `cms-admin.css` does so ~269 times. Every
  spelling of the definition counts: a declaration whose colon has been wrapped
  onto the following line (postcss parses it as one, and formatters produce it);
  the **first** declaration of an inline style attribute,
  `<div style="--ork-card-bg:#f00">`, which a quote rather than a `;` or `{`
  precedes; an `@property --ork-brand { … }` registration, which defines the
  token without the string `--ork-x:` appearing anywhere; and case variants
  (`--ork-Brand`, `--ORK-brand`). Custom properties *are* case-sensitive, so
  those last are technically distinct tokens — but they are still CMS code
  writing into the CRM's namespace, which is the property C2 protects. Scope:
  all CMS CSS and templates, admin included.
- **C3** A CMS template may not carry a **static** inline `<style>` block.
  *Scope: every CMS template* — R1's `frontdoor/**.tpl` and `cms/*.tpl`, and
  R2's surface templates, with `Cms_deny.tpl` the single structural exemption
  (`Controller_Cms::_denyPermission()` includes it directly and `exit`s; it
  bypasses the View pipeline, emits its own `<!doctype html>`/`<head>`/`<body>`
  and links no stylesheet at all, so inline is the only styling available to
  it — verified, not assumed). The earlier scope was `frontdoor/blocks/**.tpl`
  plus the `cms/*.tpl` and `Cms_*.tpl` globs, which left `_index.tpl`,
  `Site_shell.tpl`, `Page_view.tpl`, `Blog_index.tpl`, `Blog_post.tpl`,
  `frontdoor/org_header.tpl`, `frontdoor/render_blocks.tpl`,
  `frontdoor/_park_strip.tpl` and `frontdoor/org_blog_index.tpl` — exactly the
  templates whose inline CSS Phases 1–3 lifted out — free to take it back.
  A `<style>` is legal only if it passes **both** tests:
  1. it interpolates a PHP **variable** in a declaration-**value** position
     (`frontdoor/blocks/columns.tpl` interpolates `$fdbCount` into
     `grid-template-columns` and genuinely cannot become a stylesheet); and
  2. it carries no more than `C3_MAX_STATIC` = **8** static declarations,
     counted cumulatively over the whole file.

  Test 1 was all-or-nothing per element, so **one** interpolation laundered an
  arbitrarily large static block — proven with 1 interpolation + 10 static
  rules. Test 2 bounds it: `columns.tpl` declares 6 static properties
  (`display`, `gap`, `align-items`, `min-width`, and the two in its `@media`
  partner) beside its interpolated one, so 8 leaves a genuine per-instance block
  two declarations of headroom and stops far short of a lifted-out stylesheet.
  The budget is per **file**, not per element, because N elements of 8 would be
  the same hole reopened (proven: two 5-static blocks fail).
  *Residual gap, stated honestly:* up to 8 static declarations can still ride
  along in a file that has a legitimate interpolating block, and the counter
  only sees **declarations** — a `<style>` carrying `@font-face` bodies or
  selectors without declarations is under-counted. The budget makes the
  laundering small and bounded, not impossible.
  A PHP tag parked between rules, or one echoing a literal (`<?= '' ?>`), still
  does not launder a static block; PHP that echoes a `<style>` tag assembled
  from string fragments (`'<st' . 'yle>'`) is rejected too; and the tag match is
  case-insensitive — `<STYLE>` and `<Style>` are valid HTML for the same element.
- **C4** Nothing a standalone org site renders may pull in CRM CSS. Two halves:
  - **C4-link** — no file on the **public tier** may link (or `@import`)
    `orkui.css`, `tokens.css` or `orkshell-interop.css`. Scope: the whole tier,
    C1's scope with stylesheets included, minus exactly one exemption —
    `frontdoor/_assets_inshell.tpl`, the designated link point for the in-shell
    surfaces. The old scope was a two-file hardcoded list, so a **new** partial
    (`frontdoor/_assets_extra.tpl` linking `orkui.css`, included from
    `Site_shell.tpl`) was the same one-line detour C4 exists to close, one
    directory deeper.
  - **C4-path** — a file on the **org-site render path** (`Site_shell.tpl` plus
    everything under `frontdoor/`) may not include `_assets_inshell.tpl`, and
    may not `include` a `.tpl` resolving **outside `frontdoor/`**. This is what
    makes C4-link's directory scope *sufficient* rather than merely likely: the
    render path is confined to a region C4-link covers completely, so a new
    partial has nowhere to hide. Bases are resolved by following in-file
    assignments (`$fdDir = DIR_TEMPLATE . 'default/frontdoor/'`), `__DIR__` and
    `DIR_TEMPLATE`, one variable hop at a time; only PHP **code** is examined
    (prose and string contents are not statements); and it is **fail-closed** —
    an include whose destination it cannot prove is reported.

  *Why not walk `Site_shell.tpl`'s transitive include graph?* Because that graph
  has a dynamic edge — `render_blocks.tpl` does `include $partial`, one file per
  block type — that no static walk can enumerate, so a closure would have a hole
  in exactly the busiest directory while looking authoritative. Bounding the
  path to a fully-covered region needs no enumeration at all, and every step of
  it is a local, per-file test.
- **C5** CRM CSS may not name a CMS selector (`.fd-`, `.cms-`, `.org-`), in the
  class-selector spelling or the attribute-selector one (`[class*="fd-"]`,
  `[class^="cms-"]`). Scope: R3 — every `.css` under `orkui/template/` that is
  not CMS-owned. Scoping it to the `style/` directory still left a new
  stylesheet at `default/probe-tween.css`, in `orkremental/` or in
  `revised-frontend/style/` between every scope.
- **C6** `default.theme` may link a CRM stylesheet (anything under `style/`,
  plus `orkshell-interop.css`) only from a branch where `$IsOrgSite` is provably
  falsy. **This is the rule that actually decides what a standalone org site
  downloads** — the `$IsOrgSite` gate at `default.theme:104-110` is the whole
  point of the separation, and a link added to its `else:` branch, or added
  unconditionally, reintroduces the original 91 KB regression. C4 never covered
  it: `Site_shell.tpl` has never linked `orkui.css`, so guarding the shell
  guarded nothing. The check is **branch-aware** — it tracks PHP
  alternative-syntax `if`/`elseif`/`else`/`endif` nesting and what each branch
  implies about `$IsOrgSite`, so the legitimate `if (empty($IsOrgSite))` branch
  that *must* link them is not a false positive — and **fail-closed**: a
  structure it cannot follow is reported, not assumed safe. Scope:
  `orkui/template/default/*.theme`.

**Case and line endings.** Two normalisations run before any rule sees a line,
and both close holes a developer could open without trying to. **CR is
stripped**: on a CRLF file the trailing `\r` matches none of the `[ \t]*$`
anchors these rules are built on, so C2's wrapped-colon detection and C6's
branch tracker went quiet on exactly the files a Windows editor produces —
`--ork-probe` with its colon on the next line passed as CRLF and failed as LF.
**Matching is case-folded**: the scanner runs under `LC_ALL=C` against lowercase
literals, and HTML tag and attribute names are case-insensitive, so `<STYLE>`,
`<Style>`, `ID="theme_container"` and `CLASS="ork-card"` are ordinary markup
rather than obfuscation and used to walk straight past C1 and C3. The anchors
are kept — a class token must still *start* with `ork-` — so folding cannot make
`.ork-` match inside an unrelated word such as `[class*="network-item"]`.
`$IsOrgSite` is exempt from the folding, because PHP variable names are
case-sensitive.

**Comment handling.** Comment text is stripped before the rules run, because
every file in scope discusses these patterns in prose. The stripper is
**string-aware**: a quoted string that closes on its own line is copied through
verbatim and never scanned for comment openers, so `content: "/*"` or
`var s = "<!-- x"` cannot open a phantom comment that blinds the rest of the
file. A quote that does *not* close on its line is not treated as a string at
all, so an apostrophe in prose ("don't") cannot swallow a line either. A PHP
`//` comment is ended by `?>`, not merely by end of line. Anything that still
leaves a comment open at EOF is reported as **C0** rather than silently
disarming the scanner.

**Liveness.** The property that matters is that no in-scope file is *blind*.
Append a rule-appropriate violation to the end of each of the **114** in-scope
files in turn and every one is detected, by the intended rule — **0 blind
files**. (114, not the 76 of F4 or the 66 of Phase 3: scoping by rule instead of
by file list pulled in every remaining CRM stylesheet under `orkui/template/`
via R3.) Re-run the sweep after any change to the scanner — a hole closed by
tightening a pattern is worth nothing if the same edit blinds a file.

Plus stylelint (`npm run lint:css` — stylelint 16 + a tab-indent check) over the
CMS CSS directories only, so the CMS can adopt a stricter standard than the CRM
without a repo-wide reformat. It runs on pre-push as well but is **advisory**:
it never blocks, and it is skipped when `node_modules/.bin/stylelint` is absent
so a fresh clone that has not run `npm install` can still push.

### The duplication ratchet (`bin/check-css-duplication.php`)

stylelint has no rule for the defect this directory actually accumulates: a
duplicate declaration **body** — N different selectors carrying byte-identical
declarations, one component copied N times under N class prefixes. `lint:css`
therefore also runs `bin/check-css-duplication.php`, which groups rules by
**(at-rule context, normalized declaration body)** and enforces two budgets set
to the count on the day they were last measured:

| Budget | Today | Counts |
|---|---|---|
| `MAX_GROUPS_2PLUS` | 26 | duplicate bodies with ≥ 2 declarations — the real DRY signal |
| `MAX_GROUPS_ANY` | 90 | every duplicate body, single-declaration coincidences included |

The 22→26 / 78→90 step (F4) was a **coverage** re-baseline: lifting the admin
templates' inline CSS into `cms-admin.css` authored no new duplicate body, it
made pre-existing byte-identical copies visible to the analyser for the first
time. The four newly visible groups are enumerated at the constants.

Duplication may fall, never rise. The at-rule context is the part that is easy
to get wrong — two identical bodies in two *different* `@media` blocks are not
duplicates and cannot be collapsed — and the comment stripper is string-aware so
a `content: "/*"` cannot blind it. Re-baseline with
`npm run lint:css:dupes:report` and edit the two constants at the top of the
script, in the same commit as the change that moved them.

Proven live in an isolated copy of the tree: a new 2-declaration duplicate
fails both budgets, a new 1-declaration duplicate fails the any-size budget, the
same body in two different `@media` contexts correctly does **not** count, the
same body in one `@media` context does, a reflowed/re-cased copy still matches,
and duplication placed after a `content: "/*"` string is still seen.

## What changed

Measured across `67ff338d..HEAD` (Phases 1–3), before → after:

| | Before | After |
|---|---|---|
| CSS a standalone org site downloads | `orkui.css` 91,352 B + `tokens.css` 4,016 B + `frontdoor.css` 49,257 B + `orgsite.css` 6,985 B = **151,610 B** | `cms-base.css` 4,191 B + `frontdoor.css` 28,168 B + `blocks.css` 47,714 B + `blog.css` 6,219 B + `orgsite.css` 13,085 B = **99,377 B** |
| CRM CSS on an org site | 95,368 B (`orkui.css` + `tokens.css`) | **0 B** — replaced by a 4,191 B base |
| Inline `<style>` blocks in `frontdoor/blocks/*.tpl` | 20 templates | **1** (`columns.tpl`, PHP-interpolating, C3-legal) |
| Inline block CSS in templates | — | **714 lines deleted**, 37 re-inserted |
| Public-side files naming an ORK selector | 3 (`frontdoor.css`, `_park_strip.tpl`, `_index.tpl`) | **0** — 22 references now sit in `orkshell-interop.css` (exempt), 3 in `cms-base.css` (exempt), 2 remaining are prose in comments |
| CMS public stylesheets | 2 (`frontdoor.css`, `orgsite.css`) | 6, split by surface, all cacheable |
| CSS linting / hooks | none | stylelint 16 + `bin/check-css-duplication.php` + `bin/check-css-boundaries.sh` in pre-commit and pre-push |

Also fixed along the way: the `--fd-*` defaults in `frontdoor.css` had drifted
from `CmsThemeTokens::Defaults()`; they are realigned and
`tests/cms-theme/tokens_test.php` now fails if they drift again.

## Verification

Local docker on `:19080`. Org-site route is `/orkui/index.php?Route=Site/view/{slug}`
(the pretty `/k/` rewrite is nginx-only and 404s on the bare host).

| Surface | URL |
|---|---|
| Front door (in-shell) | `/orkui/index.php?Route=Controller/index` → `/` |
| Org site, themed kingdom | `…?Route=Site/view/burning-lands` |
| Org site, themed kingdom 2 | `…?Route=Site/view/kingdom-17` |
| Org site, themed **park** | `…?Route=Site/view/ambient-forest&_pfx=p` |
| Blog index / post | `…?Route=Blog/index` |
| OGRE admin | `…?Route=Cms/dashboard`, `Cms/theme`, `Cms/media`, `Cms/nav` |

**Which surface is unthemed.** Every `ork_cms_site` row in the local DB has an
`ork_cms_theme` row, so no org site exercises the CSS-default token path. The
surfaces with **no** `<style id="fd-theme-tokens">` block are the **front door and
the blog** (global scope has no theme row). They are therefore the surfaces that
render from `frontdoor.css`'s `.fd-page` defaults, and the ones where the
`--fd-font-body` drift is actually visible — on the most-visited public page in the
product. Task 2.4 verifies there, not on an org site.

`/` answers **302 → /orkui/**, so every probe of the front door must use `curl -sL`;
a bare `curl -sL "http://localhost:19080/"` returns an empty body and silently
"passes" any grep. Park sites are namespaced under `/p`, so `ambient-forest`
requires `&_pfx=p` — without it Controller_Site 404s and the probe validates a
not-found page. Every surface checked in **both** light and dark, at desktop and 390px.
