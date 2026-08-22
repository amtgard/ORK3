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
  `Cms_preview*` is a naming contract: a `Cms_` template that renders the
  public page must be named that way to land on the public tier.

  **The controller set is derived, not listed (R9/R10/C8).** This was originally
  documented as "the one manual step the design keeps" — add a controller name
  to `CMS_CONTROLLERS` when you add a CMS controller. A manual step is a step
  someone forgets, and forgetting it was **silent**: a new `Ogre_view.tpl`
  linking `orkui.css`, carrying a static `<style>` *and* writing
  `id="theme_container" class="ork-card"` passed at **exit 0**, because no rule
  was switched on for it at all. A brand-new surface got zero coverage and the
  gate said nothing. Three mechanisms now close it, each covering the one before:

  | | Mechanism | What it catches |
  |---|---|---|
  | **R9** | `CMS_CONTROLLERS` is derived from the filesystem — the controllers using the **`CmsScopeContext`** trait (`controller.{Site,Page,Blog,Cms,CmsAjax}.php`: 5 of 44, exactly the CMS set), unioned with the historical list as a **floor** so derivation can only *add*. Same idea as `check-layering.sh` deriving `DOMAIN_CLASSES` from `system/lib/ork3/class.*.php`. | The new CMS controller nobody added to the list. Proven: with `controller.Ogre.php` using the trait, `Ogre_view.tpl` reports C1 + C3 + C4. |
  | **R10** | A `$TPL_ROOT` template that **renders CMS chrome** — includes a `frontdoor/` or `cms/` partial, links a stylesheet from either — is a CMS surface template whatever its prefix and whoever owns its controller. Direct evidence, not inference. `frontdoor/` ⇒ public tier, `cms/`-only ⇒ admin. | The new CMS controller that never used the trait. Proven: with the trait removed, `Ogre_view.tpl` including `frontdoor/render_blocks.tpl` still reports C1 + C3 + C4. Adds **no** file today — 13 of the 15 existing CMS surface templates already qualify on content alone, and **none** of the other 99 templates under `$TPL_ROOT` reference either directory. |
  | **C8** | A `$TPL_ROOT/<X>_<action>.tpl` with no `controller.<X>.php` is **reported**. "No rule matched" and "no rule ran" look identical from outside, and only one is safe. | The surface with nothing behind it. Zero today: all 23 distinct prefixes resolve to a controller that exists, so it costs nothing and fires on the first one that does not. |

  *Residual, stated honestly:* a controller with no CMS marker whose template
  renders no CMS chrome is, on every piece of available evidence, a **CRM**
  surface — and a CRM surface linking `orkui.css` and naming `#theme_container`
  is doing its job, not violating anything. There is nothing left to detect in
  that case, which makes this residual principled rather than a gap.
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
- **R6** The **CRM stylesheet set is derived from the filesystem** — every
  `.css` under `orkui/template/default/style/`, at any depth, plus
  `orkshell-interop.css`. C4-link and C6 hardcoded three names
  (`orkui.css`, `tokens.css`, `orkshell-interop.css`), which meant `reports.css`
  (55 KB) and `custom.css` — both of which had been sitting in `style/` the
  whole time — were unknown to the gate, and so would tomorrow's CRM stylesheet
  be. Everything under `style/` is CRM CSS by R3, so the shell asks the
  filesystem, the way `check-layering.sh` derives `DOMAIN_CLASSES` from
  `system/lib/ork3/`. Consequence to know: a CMS stylesheet must not reuse a CRM
  stylesheet's basename.
- **R7** **Asset-base seeds are derived too.** A partial does not assign the
  base it is handed — `_assets_public.tpl` documents *"Expects `$fdDir` and
  `$fdAssetBase` already in scope"* and its six includers assign them — so read
  on its own its href is unresolvable, and C4's fail-closed rule would fire on
  the one file whose entire job is linking the CMS stylesheets. Every in-scope
  file is therefore scanned once for `$name = HTTP_TEMPLATE|DIR_TEMPLATE . '…'`,
  and a name the tree assigns a **provable** prefix to resolves to that prefix
  in a file that does not assign it itself. In-file assignment always wins; an
  assignment the scanner cannot resolve seeds nothing. This is not a loophole —
  it works in the dangerous direction too: point one of those names at `style/`
  anywhere in the tree and every partial that consumes it starts reporting.

- **R8** **The CMS PHP and JS sources are in scope for C7.** R1–R7 scope `.css`,
  `.tpl` and `.theme` — every file that can *declare* CSS, and none of the files
  that can *inject* it. A verifier put a stylesheet `<link>` and a
  `<style>#theme_container{}</style>` onto a live org-site page from two
  directions — `frontdoor/js/frontdoor.js` via
  `document.head.insertAdjacentHTML()`, and `orkui/controller/controller.Site.php`
  echoing the markup — and **both** this gate and the layering gate returned exit
  0. The blind set was 31 files; it is now derived the same way everything else
  here is:

  | Derived from | Files |
  |---|---|
  | `CMS_CONTROLLERS`, as `controller.<C>*.php` | `controller.{Site,Page,Blog,Cms}.php` + `controller.CmsAjax.php` — the `*` means an Ajax sibling needs no second list |
  | `CMS_CONTROLLERS`, as `trait.<C>*.php` | `orkui/controller/trait.CmsScope.php` — mixed into the CMS controllers, and a trait can `echo` anything a controller can |
  | `CMS_CONTROLLERS`, as `model.<C>*.php` | `orkui/model/model.Cms*.php` today, `model.Blog*.php` / `model.Site*.php` / `model.Page*.php` on the day one lands |
  | `CMS_CONTROLLERS`, as `class.<C>*.php` | `system/lib/ork3/class.Cms*.php` today, the same widening for the rest |
  | R1, extended to scripts | `frontdoor/**.js`, `cms/**.js` |
  | **named explicitly** | `orkui/model/model.FrontDoor.php` — the front door is rendered by the **base** controller, so its membrane carries no CMS controller prefix to derive from. The second manual step in the design, beside `CMS_CONTROLLERS`. |

  **All four rows derive from the same list, and that is the point.** The model
  and domain rows used to match a literal `Cms` prefix while only the controller
  row was derived, which made the model set *narrower than the controller set* —
  and the gap was reachable, because `Blog` and `Site` are CMS controllers.
  Proven: `orkui/model/model.BlogZz.php` echoing a `<style>` and a CRM stylesheet
  `<link>`, and `orkui/model/model.SiteZz.php` defining `--ork-brand`, both
  passed at **exit 0** — CMS-owned membranes for CMS surfaces, injecting exactly
  what C7 exists to stop, invisible only because nobody had written `Cms` in the
  filename. `trait.*.php` was blind from the other direction for the same reason.
  Deriving all four from `CMS_CONTROLLERS` means the halves cannot drift; it adds
  exactly **one** file that exists today (`trait.CmsScope.php`, which passes
  clean), and the rest is coverage waiting for the file that has not been written
  yet. It is also the right rule in its own terms for the domain row: a
  `system/lib/ork3/` class emitting CSS is a layering violation whoever owns it.

  Only C7 runs on these files. C1–C6 are about how CSS is *declared and linked*
  in stylesheets and templates, and applying them to PHP source would be a
  category error.


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

  **C3-total — the tree-wide ratchet, because a per-file budget reopens one
  level up.** N *elements* of 8 was closed by making the budget per file; N
  *files* of 8 does exactly the same thing one level higher. Proven: three new
  partials under `frontdoor/`, 8 static declarations each, = 24 static
  declarations back inline, every file inside its budget, gate exit 0. R1 puts a
  new partial in scope wherever under `frontdoor/` it sits, so nothing stopped
  the third, the tenth or the fiftieth. The per-file rule bounds one file; it
  cannot bound the render path, because the render path is the **sum**.

  So the quantity actually pinned is the tree-wide one: `C3_TOTAL_STATIC` = **6**,
  the total number of static declarations riding inside *legal*
  (PHP-interpolating) `<style>` blocks across **every** CMS template, counted
  over the whole tree in **every** mode — `--staged`, `--range` and `--files`
  re-scan the tree for the census, so the sum cannot be evaded by committing one
  partial at a time. All 6 are `columns.tpl`'s; it is the only contributor.
  `cms/_shell_top.tpl`'s `<style>` is prose inside a PHP comment and
  `Cms_deny.tpl` is exempt from C3 altogether, so both contribute 0 and both keep
  passing. A static-**only** `<style>` is not in this number — that is a plain C3
  violation, reported as one. C3-total measures exactly the laundering channel:
  static CSS riding on a legitimate interpolation.

  It is a **ratchet, not a freeze**, for the same reason the duplication budgets
  are (see below): above the pin fails (`ROSE`), below it fails too
  (`FELL — re-pin`), because slack left in a budget is slack the next commit can
  spend with the gate green throughout. The failure prints the exact line of the
  script and the replacement line, so re-pinning is a one-line edit.
  `CSS_STATIC_ALLOW_SLACK=1` forgives the below-budget direction only — never
  the above — mirroring `CSS_DUP_ALLOW_SLACK=1`. Raising it is therefore a
  deliberate, reviewable act: the fourth partial is not a judgement call about
  whether 8 is small, it is a diff that raises a pinned number.

  *Residual gap, stated honestly:* within the pinned total, up to 8 static
  declarations can still ride along in a file that has a legitimate interpolating
  block, and the counter only sees **declarations** — a `<style>` carrying
  `@font-face` bodies or selectors without declarations is under-counted, in the
  per-file budget and in the total alike. The two budgets together make the
  laundering small, bounded and *unable to grow silently*; they do not make it
  impossible.
  A PHP tag parked between rules, or one echoing a literal (`<?= '' ?>`), still
  does not launder a static block; PHP that echoes a `<style>` tag assembled
  from string fragments (`'<st' . 'yle>'`) is rejected too; and the tag match is
  case-insensitive — `<STYLE>` and `<Style>` are valid HTML for the same element.
- **C4** Nothing a standalone org site renders may pull in CRM CSS. Two halves:
  - **C4-link** — no file on the **public tier** may link (or `@import`) a CRM
    stylesheet (R6's derived set, and any path landing in a `style/`
    directory — see *Which stylesheet a path names* below). Scope: the whole
    tier, C1's scope with stylesheets included, minus exactly one exemption —
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
  that *must* link them is not a false positive — and **fail-closed** twice
  over: a structure it cannot follow is reported, and so is a stylesheet href on
  an org-reachable branch whose destination it cannot prove. Scope:
  `orkui/template/default/*.theme`.

- **C7** **CMS PHP and CMS JS may not inject CSS into a page.** Scope: R8.
  One flat rule, no tiers, no exemption list. CSS enters a CMS page through
  three sanctioned channels — the two link partials, `cms/_shell_top.tpl` for
  the admin, and `default.theme`'s `$IsOrgSite` gate — and it *lives* in a
  stylesheet under `frontdoor/css/` or `cms/css/`. A controller, a model, a
  domain class and a script bundle are none of those on either tier, which is
  why C7 needs no public/admin split: there is no legitimate case anywhere in
  the set. Reported: a `<style>` element however assembled (the literal tag, a
  tag built by string concatenation `'<sty' . 'le>'` / `'<sty' + 'le>'`, and the
  DOM spellings that never write a tag — `createElement('style')`,
  `new CSSStyleSheet()` + `insertRule`, `adoptedStyleSheets`); a stylesheet
  `<link>` (one that *has* an `href` and whose `rel` is not a known
  non-stylesheet rel, plus `createElement('link')`); an `@import`; an ORK
  application-shell selector in the spellings C1 reads plus the markup ones
  (`id="theme_container"`, a `class` token starting `ork-`) and the DOM ones
  (`classList.add('ork-…')`, `getElementById('theme_container')`); a definition
  in the CRM's `--ork-*` namespace in the spellings C2 reads plus
  `style.setProperty('--ork-x', v)`; and a CRM stylesheet name or a
  `style/<…>.css` path shape, so an href assembled into a variable is caught one
  step before it reaches a tag.

  **The difficulty is false positives, and it is answered by the trigger set,
  not by an exemption list.** Three files in scope handle CSS legitimately, as
  *data*: `class.CmsThemeTokens.php` **builds** CSS text (`Block()`, `ToCss()`,
  `ToRootCss()`), `class.CmsSanitizer.php` inspects and **strips** style
  attributes and `<style>`/`<link>` tags out of authored content, and
  `class.CmsTheme.php` passes that CSS around. The distinction drawn is
  **literal emission, not mention**:

  | Trigger | What it deliberately does **not** match |
  |---|---|
  | the tag **openers** `<style` / `<link` | the tag **names**. `CmsSanitizer`'s blocklist is `array('script', 'style', … 'link', …)` — bare names — and passes on its own merits |
  | the CRM namespace `--ork-*` | the CMS namespaces `--fd-*` / `--cms-*`. `CmsThemeTokens` is a `--fd-*` factory end to end, and passes on its own merits |
  | code, after comment stripping — PHP's `#`-to-end-of-line form included, for PHP sources only, since `#` opens an id selector in CSS and a private field in JS | prose. `CmsTheme.php`'s docblock *"the `<style>` inner CSS for the active theme"* is documentation |
  | a `<link>` **with an `href`** | the RSS `<link>` **element**, whose URL is its text content. `class.CmsPost.php` writes exactly that into the feed, and passes on its own merits |

  An exemption is a standing hole that rots as the exempted file grows; a
  trigger set narrow enough that the CSS-as-data classes never touch it does
  not. **Verified: all 31 files report clean, with no file named anywhere in the
  rule.** C7 is fail-closed on an unprovable `rel` (it could be `stylesheet`)
  and deliberately does **not** resolve the `href`: for these files every
  stylesheet link is a violation whatever it points at, which is also what lets
  it survive concatenation — `'<link rel="stylesheet" href="' . $u . '">'` has
  its attribute values shredded by the PHP string quotes, so no scanner can read
  the href out of it, but the attribute is demonstrably *present*, and present
  is all C7 needs.

  **Escape-encoded tags are decoded before the nets run.** C7 reads `<style` and
  `<link`, and two ordinary JS spellings put a `<style>` element on the page
  without ever writing a `<`. Both scored **exit 0**:

  ```js
  '\x3cstyle\x3e.fd-page{color:red}\x3c/style\x3e'   // + insertAdjacentHTML()
  String.fromCharCode(60) + 'sty' + 'le' + String.fromCharCode(62)
  ```

  The first is **not merely an attack**: `\x3c` is the routine idiom for keeping
  a literal `</script>` out of an inline script, so this is a plausible
  *accident* — which is the reason to decode it rather than argue about intent.
  `esc_decode()` folds `\xHH`, `\uHHHH` and `\u{H…}`; `fcc_decode()` folds
  `String.fromCharCode` / `fromCodePoint` with a decimal argument list, emitting
  a **quoted** literal so the existing concatenation-joining pass splices
  `'<' + 'sty' + 'le' + '>'` into `'<style>'` — the two passes have to compose,
  because that payload needs both. C3's PHP-fragment net decodes the same way on
  lines carrying a PHP open tag: `<?php echo "\x3cstyle\x3e…"; ?>` in a `.tpl`
  was the identical hole one rule over. Escaped backslashes are preserved
  (`'\\x3cstyle\\x3e'` in prose stays prose), and the CSS rules deliberately do
  **not** decode these forms — in CSS a backslash escape means something else
  (`\x3c` is the letter *x* then `3c`), so decoding there would invent
  violations; `css_unescape()` already handles the CSS spelling.

  *Residual, stated honestly:* obfuscation beyond those forms — octal escapes,
  `atob()`/base64, `charCodeAt` arithmetic, a computed template literal,
  `String.raw`, a tag assembled through an array join, anything rebuilt at
  runtime from data. A line scanner cannot close that, and claiming otherwise
  would be worse than saying so. `tests/cms-css/boundary_test.php` remains the
  backstop that reads what a live surface actually serves.


**Which stylesheet a path names.** C4-link and C6 originally asked "is the
literal `default/style/…css`, or one of three basenames, on this line?". A
verifier defeated both with spellings that are **idiomatic in this codebase**,
not obfuscation: `HTTP_TEMPLATE . "default/style/" . "orkui.css"` (the literal
split at the quote), `"default/sty" . "le/orkui.css"` (split anywhere),
`default/frontdoor/../style/orkui.css` (a `..` hop), `../style/orkui.css`,
`default/style//orkui.css`, an `href` split across two lines,
`default/style/reports.css` and `custom.css` (not among the three names), and
`@import url("../../style/reports.css")` from a CMS stylesheet. All eight are
one defect — a *literal* prefix and a *name list* — so the fix is one rule:

1. **Resolve the path.** PHP is evaluated as far as string values go: adjacent
   literals are joined, `HTTP_TEMPLATE` / `DIR_TEMPLATE` / `__DIR__` are known,
   a variable is followed through in-file assignments (R7 supplies the base a
   partial is handed), an array literal and the `foreach ($fdCssSet as
   $fdCssFile)` in `_assets_public.tpl` are **enumerated** so each of its three
   values is classified separately, and a `<link>` tag is accumulated across
   lines so a split attribute is one string again.
2. **Judge it by shape.** Normalise (`..` resolved, `//` collapsed, query and
   scheme+authority stripped), then classify: any path landing in a `style/`
   directory, or naming one of R6's derived basenames, is CRM CSS however it was
   spelled.
3. **Fail closed.** A stylesheet `href` or `@import` still carrying an
   unresolved variable or call is reported: the gate cannot prove where it
   lands, which is exactly the state C4-path already refuses to accept for an
   include.

The scope is deliberately narrow, because a fail-closed rule pointed at
everything is a false-positive engine: only the two vectors that make a browser
download a stylesheet are examined — a `<link>` whose `rel` could be one (`rel`
absent, `stylesheet`, `preload`, an unknown `rel`, or one built in PHP; the
known non-stylesheet `rel`s are skipped) and an `@import`. An `<img src>`, a
`<script src>` and an `<a href>` are not stylesheets and are left alone, which is
what keeps the dynamic hrefs all over the CMS templates — and `default.theme`'s
own dynamic `rel="canonical"` / `rel="alternate"` links — from reading as
violations. A coarse name/shape net still runs over the whole (concatenation-
joined) line, for spellings that never reach a `<link>` at all.
A stylesheet injected by JavaScript, or echoed straight out of a controller,
used to be out of reach here — C4 reads templates, and `frontdoor/js/` was not in
this gate's scope at all. **R8 + C7** now cover the CMS PHP and JS sources for
exactly that, and section 9 of `tests/cms-css/boundary_test.php` is the runtime
mirror: it reads every same-origin `<script src>` and inline `<script>` an org
site is actually served and asserts none of them injects CSS. *Not covered,
stated honestly:* a stylesheet URL that never appears as text in the tree — read
out of the database, say — for which the runtime test remains the only backstop.

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
**(at-rule context, normalized declaration body)** and enforces two budgets
pinned to the count on the day they were last measured:

| Budget | Today | Counts |
|---|---|---|
| `MAX_GROUPS_2PLUS` | 26 | duplicate bodies with ≥ 2 declarations — the real DRY signal |
| `MAX_GROUPS_ANY` | 90 | every duplicate body, single-declaration coincidences included |

**Both directions fail.** The first build of this gate failed only when
duplication *rose*, with the budgets set exactly equal to the observed counts.
That is a freeze, not a ratchet: collapse a group and the count drops, the gate
stays green, and the slack sits there for the next commit to spend on a fresh
copy — with the gate green throughout. Duplication could never improve on paper
because nothing ever lowered the number. So:

| Observed vs budget | Verdict | Remedy |
|---|---|---|
| above | `FAIL ^ DUPLICATION ROSE` | collapse the group, or justify it and `--rebaseline --allow-raise` |
| below | `FAIL v DUPLICATION FELL` | `npm run lint:css:dupes:rebaseline` — pin the improvement |
| equal | `OK ratchet held` | — |

Re-baselining is one command, `npm run lint:css:dupes:rebaseline`, which
rewrites both constants and prints the before/after. It **lowers freely** and
**refuses to raise** without an explicit `--allow-raise`, because raising a
budget is how duplication gets laundered through a gate. The failure message
also prints the exact `file:line` and replacement line for a hand edit, computed
at runtime so it cannot go stale. The one-run escape hatch
`CSS_DUP_ALLOW_SLACK=1` mirrors `ORK3_ALLOW_LAYER_VIOLATION=1` and forgives
**only** the below-budget direction — it can never let duplication rise.

The at-rule context is the part that is easy to get wrong — two identical bodies
in two *different* `@media` blocks are not duplicates and cannot be collapsed —
and the comment stripper is string-aware so a `content: "/*"` cannot blind it.

The 22→26 / 78→90 step (F4) was a **coverage** re-baseline: lifting the admin
templates' inline CSS into `cms-admin.css` authored no new duplicate body, it
made pre-existing byte-identical copies visible to the analyser for the first
time. The four newly visible groups are enumerated at the constants.

The 91→90 step (P3) is the first **tightening**, and the first one the gate would
have demanded on its own. The largest duplicate body in the CMS CSS — seven
copies of `color: var(--cms-gold, #f0b429)` spread over ~2,300 lines of
`cms-admin.css` — is now one grouped rule, placed at the position of
`.cms-editbar-hint-dirty`: the only member whose cascade position is
load-bearing, because it overrides `.cms-editbar-hint` at equal specificity and
nothing but source order makes it win. Equivalence was **proved**: specificity is
unchanged by a move, so the only way a move can change a rendered value on any
DOM is by flipping a cascade pair that shares a property at equal specificity in
the same at-rule context. All 54,778 such ordered pairs were enumerated before
and after; exactly 4 flipped, and all 4 sit between class pairs that never appear
together on one element in any of the 4,203 distinct class attributes the repo
emits (`.cms-rail-icon` vs `.cms-crumb` / `.cms-icon-danger`,
`.cms-dash-livelink` vs `.kn-ac-item` / `.te-btn-ghost`). Resolving every
property for 4,558 modelled elements produced a byte-identical 215,133-line
snapshot before and after. The next-largest group, 6 copies of `display: flex`,
is **not** collapsible — four members in `cms-admin.css`, two in `blocks.css`,
and a selector list lives in exactly one file.

Proven live in an isolated copy of the tree, and now permanently by
`tests/cms-css/duplication_ratchet_test.php` (37 assertions, no DB, no app): a
new 2-declaration duplicate fails both budgets, a new 1-declaration duplicate
fails the any-size budget, a *removed* duplicate fails the tighten-me direction,
`--rebaseline` lowers but will not raise, `CSS_DUP_ALLOW_SLACK=1` forgives one
direction and not the other, the same body in two different `@media` contexts
correctly does **not** count, the same body in one `@media` context does, a
reflowed/re-cased copy still matches, and duplication placed after a
`content: "/*"` string is still seen.

### The runtime backstop, and why a skipped surface is now a failure

`tests/cms-css/boundary_test.php` is the runtime half of the contract: it reads
no source, fetches the real surfaces off a running app and asserts on what is
actually served. Being the check that catches what the scanner cannot, it is the
one place where a silent skip is *most* damaging — and it had two.

1. **Per-surface skip.** A surface that did not render printed
   `  note: surface not available, skipped — org home (park ambient-forest)` and
   the run carried on. Demonstrated with the park site unpublished: **227
   assertions became 194** — the entire park tier and the only `&_pfx=p`
   coverage — and the run still printed `ALL PASS`, still exited 0, and CI still
   reported an unqualified green, because the workflow detected a skip by
   grepping for a line starting `SKIP:`.
2. **Whole-run skip.** An unreachable app printed `SKIPPED (0 assertions run)`
   and exited 0, with nothing machine-readable saying how much had not run.

Both are closed the same way: **the run is accounted for, and the accounting is
machine-readable.** Every exit path — including both skips — ends with

```
SURFACES: 9 EXPECTED, 8 COVERED, 1 SKIPPED
SURFACE: <label> COVERED <n> | SKIPPED 0 — <why>
ASSERTIONS: <n> RAN, <n> FAILED
MODE: LENIENT|STRICT
SKIP-KIND: NONE|WHOLE-RUN|PARTIAL
RESULT: PASS|PASS-WITH-SKIPS|FAIL
```

`ALL PASS` is reserved for a run that covered everything.

**The expected set is derived, not pinned.** It is the `$surfaces` list plus one
single-post surface per tier that list covers — nine today — so adding a surface
extends the contract automatically and no constant can go stale. The post
surfaces are *discovered* (a slug is data), but they are **expected** all the
same: the single-post render path is the only place `.blogp-*` / `.org-post*` is
exercised, and "no post in the database" is a coverage hole whether or not
anyone chose it. A **total assertion count is deliberately not pinned**: the
per-surface count legitimately varies with how many stylesheets and same-origin
scripts a surface serves, so a pinned total would be a false-failure engine.
Section 10 asserts the property that *is* stable — every covered surface ran at
least one assertion, and every surface that ran is in the expected set — and the
per-surface counts are printed so a drop shows up in a log diff.

**Two modes.** Lenient (default) reports a skip as `RESULT: PASS-WITH-SKIPS` and
exits 0, so the script stays usable with the app down. Strict (`--strict` or
`CMS_CSS_STRICT=1`) makes **any** skip, whole-run or per-surface, a `RESULT:
FAIL` with exit 1.

**`SKIP-KIND` is the discriminator CI acts on**, and the two kinds are not the
same event. `PARTIAL` means the app *answered* and a named surface still did not
run — real coverage loss, with a real cause — so it fails the job red.
`WHOLE-RUN` means nothing answered at all: no assertions, and nothing to be
falsely confident about. That is the expected state of every CI run in this repo
(below), so it produces a `::warning` annotation, a `RUNTIME BACKSTOP DID NOT
RUN` banner and a job-summary block rather than red — a check that is always red
is a check nobody reads, and the honest signal is "this ran 0 of 9 surfaces",
stated where a reviewer sees it. A run that prints **no** summary is red too:
CI cannot tell what ran, and "cannot tell" is treated as broken.

**CI does not stand the app up, deliberately.** The docker compose files are in
the repo, so booting php + mariadb in the runner is mechanically possible; the
blocker is data. 88 of the 89 tracked `.sql` files are incremental migrations
under `db-migrations/`, and the one exception — `ork.sql` — is a 2013 phpMyAdmin
**schema** dump: 38 `CREATE TABLE`s, zero `INSERT`s, and not one `ork_cms_*`
table, the whole CMS schema postdating it. An app in CI would therefore come up
with no `ork_cms_site` rows, no kingdoms, no parks and no posts; every org-site
surface would 404 and the backstop would assert against not-found pages — the
same `WHOLE-RUN` skip, ten minutes later. Worse, "fixing" that by relaxing the
surface checks would make CI green off an empty database, which is precisely the
false confidence this gate exists to prevent. The day CI does have a populated
app, no rewrite is needed: setting `ORK_BASE_URL` for the job makes Gate 4 run
the backstop with `--strict`.

Verified end to end against local docker: lenient + full app → `RESULT: PASS`,
9/9 surfaces, 278 assertions; a surface made unavailable by a reversible DB
change → the skip named in `SURFACES:`/`SURFACE:` with `SKIP-KIND: PARTIAL`,
exit 0 lenient and **exit 1 strict**; app unreachable → `SKIP-KIND: WHOLE-RUN`,
exit 0 lenient and **exit 1 strict**; and the workflow's own Gate 4 script,
extracted from the YAML and run locally, reproduced all four plus the
no-summary case.

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
| Files the CSS gate can see | 0 of the 31 CMS PHP/JS sources — a `<link>` and a `<style>` injected from `frontdoor.js` and from `controller.Site.php` both scored exit 0 | **31 of 31** (R8/C7), plus section 9 of `boundary_test.php` reading the scripts a live org site serves |

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
