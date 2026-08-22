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

- **C0** The scanner must be able to parse the file. A comment opener that is
  never closed leaves every later line unscanned, so it is reported instead of
  passing silently. See "Comment handling" below.
- **C1** CMS CSS/markup may not name an ORK application-shell selector
  (`#theme_container`, `#newmenu`, `.ork-`). **Both** stylesheet selectors and
  template *markup* are checked — `id="theme_container"`, and a `class`
  attribute whose token list starts a token with `ork-` — and CSS identifier
  escapes (`#theme\_container`, `.ork\-x`, `#\74 heme_container`) are decoded
  before matching, because they are semantically identical CSS.
  *Scope, as built:* the **public** CMS side only — `frontdoor/css/*.css`, every
  template under `frontdoor/`, and the six public surface templates that the
  router resolves one directory up (`_index.tpl`, `Site_shell.tpl`,
  `Page_view.tpl`, `Blog_index.tpl`, `Blog_post.tpl`, `Cms_preview.tpl`;
  `Cms_preview` is listed deliberately — it renders the *public* page inside a
  preview chrome, unlike the rest of the `Cms_*` screens).
  *Exempt:* `orkshell-interop.css` (the designated coupling point) fully, and
  `cms-base.css` **narrowly** — it may name `#theme_container`, because it has
  to neutralize the container `default.theme` emits on standalone org sites,
  and nothing else. `#newmenu` and `.ork-` are still rejected there. That
  narrowness matters: `cms-base.css` is the one stylesheet a standalone org
  site loads globally, so an unbounded exemption would let the whole
  quarantined override layer migrate back into it with the gate green.
  *Out of scope:* `cms/css/cms-admin.css` and the `cms/` + `Cms_*` admin
  templates. The OGRE admin is definitionally an ORK-hosted application surface,
  renders inside the shell, and has no portability claim to protect. C2 still
  applies there.
- **C2** CMS CSS may not *define* a token in the CRM's `--ork-*` namespace.
  Reading one with `var()` is fine — `cms-admin.css` does so ~269 times. A
  declaration whose colon has been wrapped onto the following line counts as a
  definition (postcss parses it as one, and formatters produce it). Scope:
  all CMS CSS and templates, admin included.
- **C3** A content-block template may not carry a **static** inline `<style>`
  block. *As built the rule is about staticness, not novelty:* a `<style>` is
  legal only when it interpolates a PHP **variable** in a declaration-**value**
  position, because `frontdoor/blocks/columns.tpl` interpolates `$fdbCount`
  into `grid-template-columns` and therefore genuinely cannot become a
  stylesheet. A PHP tag parked between rules, or one echoing a literal
  (`<?= '' ?>`), does not launder a static block. PHP that echoes a `<style>`
  tag assembled from string fragments (`'<st' . 'yle>'`) is rejected too.
  Scope: `frontdoor/blocks/**.tpl`. It is the only surviving inline block, down
  from 20.
- **C4** Markup a standalone org site renders may not link `orkui.css`,
  `tokens.css` or `orkshell-interop.css`. Scope: `Site_shell.tpl` **and**
  `frontdoor/_assets_public.tpl`, the stylesheet partial the shell includes —
  guarding only the shell leaves the partial as a one-line detour to the same
  regression.
- **C5** CRM CSS may not name a CMS selector (`.fd-`, `.cms-`, `.org-`). Scope:
  every `.css` under `style/`, as a **directory** — a new CRM stylesheet is in
  scope the moment it lands, rather than only the three files that existed when
  the rule was written.
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
Append a rule-appropriate violation to the end of each of the 66 in-scope files
in turn and every one is detected, by the intended rule.

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
| `MAX_GROUPS_2PLUS` | 22 | duplicate bodies with ≥ 2 declarations — the real DRY signal |
| `MAX_GROUPS_ANY` | 78 | every duplicate body, single-declaration coincidences included |

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
