# CMS CSS

The CMS ("OGRE" — Online Gallery and Resource Engine) is a separate product
hosted inside the ORK. Its CSS is kept physically separate from the CRM's so the
two can evolve independently, and `bin/check-css-boundaries.sh` keeps it that
way.

Design: `docs/superpowers/specs/2026-08-21-cms-css-separation-design.md`

## Where things live

| Path | Owns |
|---|---|
| `orkui/template/default/style/` | **CRM.** `orkui.css`, `tokens.css`, `reports.css`. Not ours — and it must not name a CMS selector (C5). |
| `frontdoor/css/cms-base.css` | Minimal global base for **standalone** org sites, loaded instead of `tokens.css` + `orkui.css`. Base only — no components. |
| `frontdoor/css/frontdoor.css` | Front-door marketing chrome, `.fd-*`, and the `--fd-*` token defaults. |
| `frontdoor/css/blocks.css` | Content-block CSS for `frontdoor/blocks/*.tpl`. |
| `frontdoor/css/blog.css` | Blog index + single post. |
| `frontdoor/css/orgsite.css` | Per-org standalone site chrome. |
| `frontdoor/css/orkshell-interop.css` | **The only** public-side file that may name an ORK selector. |
| `cms/css/cms-admin.css` | OGRE admin. Renders inside the ORK shell, so it may *read* `--ork-*` (it does, ~269 times) but never define one. |

## Load order (it is load-bearing)

`frontdoor.css` → `blocks.css` → `blog.css` (when linked at all — see the blog
opt-in below) → then `orgsite.css` (standalone org sites) or
`orkshell-interop.css` (in-shell surfaces). `blocks.css` and `blog.css` were
split off the end of `frontdoor.css` and several of their rules win
same-specificity ties against it. Do not reorder; `blog.css` stays last of the
three.

Two partials emit the links — **add a stylesheet there, not in a page template**:

- `frontdoor/_assets_public.tpl` — `frontdoor.css`, then `blocks.css`, then
  `blog.css` **only if the including surface opted in**. Safe on every public CMS
  surface, standalone org sites included. Included by `_index.tpl`,
  `Site_shell.tpl`, `Page_view.tpl`, `Blog_index.tpl`, `Blog_post.tpl`,
  `Cms_preview.tpl`.
  - **The blog opt-in**: set `$fdWantBlog = true;` *before* the include to get
    `blog.css`. Exactly two surfaces do — `Blog_index.tpl` and `Blog_post.tpl`,
    the **in-shell** blog. They are the only templates that emit `.blog-*` /
    `.blogp-*` markup. Everywhere else the layer's selectors matched 0 nodes, so
    it was 6,811 bytes of dead CSS. The partial `unset()`s the flag so it cannot
    leak into a later include on the same request. If you add blog markup to a
    new surface, set the flag there or the page renders unstyled.
  - **`Site_shell.tpl` does not opt in, in any mode.** An earlier pass narrowed
    the flag to its `blog`/`post` modes on the assumption that those emit blog
    markup; measured against the served HTML, neither does. The `post` branch
    renders `.org-post*`, and `blog` mode renders `org_blog_index.tpl`'s
    `.org-blog-*` — both styled end to end by `orgsite.css`. Every selector in
    `blog.css` is `.blog-*` or `.blogp-*`, and `.org-blog-card` is **not** one of
    them, so an org post page and an org blog index were each downloading the
    whole layer for zero matched nodes. `tests/cms-css/boundary_test.php` asserts
    this against the running app, in both directions.
  - **`blocks.css` is deliberately unconditional**, even though the front door
    matched only 1 of its 198 selectors when measured. Block presence is
    *authored content*, not a template property: any CMS-backed surface can start
    rendering any block type the moment an author adds one, so linking it by
    current content would un-style the next edit. It is one cacheable file shared
    by all six public surfaces; the pre-refactor equivalent was inline CSS re-sent
    in the HTML of every page view.
- `frontdoor/_assets_inshell.tpl` — `orkshell-interop.css` only. Included by
  every one of the above **except `Site_shell.tpl`**, which must never link it
  (C4).

`cms-base.css` is not in either partial: `default.theme` links it (or the CRM
pair) directly, on the `$IsOrgSite` branch. `cms-admin.css` is linked by
`cms/_shell_top.tpl`.

## The three surface tiers

| Tier | Flag | In ORK shell? | Stylesheets |
|---|---|---|---|
| Standalone org site `/k/{slug}` | `$IsOrgSite` | no | `cms-base.css` + public set + `orgsite.css`. **No** `orkui.css`, `tokens.css`, `cms-admin.css` or `orkshell-interop.css`. |
| Front door `/`, CMS page, blog, CMS preview | `$IsFrontDoor` / `$IsCmsPage` | yes | `tokens.css` + `orkui.css` + public set + `orkshell-interop.css`. |
| OGRE admin `Cms/*` | — | yes | `tokens.css` + `orkui.css` + `cms-admin.css`. |

"Public set" = `frontdoor.css` + `blocks.css`, plus `blog.css` on the two
in-shell blog surfaces only (`Blog_index.tpl`, `Blog_post.tpl`). A standalone org
site never gets `blog.css` — see the blog opt-in above.

## What is in scope, and why it is a rule and not a list

Every rule below used to name the files it applied to. That made the gate
strongest on the files that already existed and blind to everything new — a
static `<style>` could go straight back into any of the nine templates this
refactor lifted CSS out of, a new partial one directory deeper defeated C4, and
a new surface template or stylesheet was born with no coverage at all. Scope is
now **derived**:

| | Rule | Consequence |
|---|---|---|
| **R1** | Everything under `frontdoor/` is CMS **public** tier; everything under `cms/` is CMS **admin** tier — at any depth. | A new subdirectory or partial is covered on creation. |
| **R2** | The router resolves `<Controller>/<action>` to `<Controller>_<action>.tpl`, so CMS page templates sit one directory up beside the CRM's. The **CMS controllers** are `Site`, `Page`, `Blog`, `Cms` — so `_index.tpl` (the front door, from the base controller) plus `Site_*.tpl`, `Page_*.tpl`, `Blog_*.tpl` are public tier, `Cms_preview*.tpl` is public tier (it renders the *public* page in preview chrome), and every other `Cms_*.tpl` is admin tier. | `Site_home.tpl`, `Blog_tag.tpl`, `Page_index.tpl`, `Cms_anything.tpl` are in scope the moment they land. **Adding a CMS controller means adding its name to `CMS_CONTROLLERS`** in the script — that is the one manual step, and it is per controller, not per file. |
| **R3** | Any `.css` under `orkui/template/` that is **not** CMS-owned is CRM CSS. | A stylesheet dropped at `orkui/template/default/probe-tween.css`, in `orkremental/`, or in `revised-frontend/style/` is guarded as CRM CSS (C5) immediately — C5 no longer watches `style/` specifically. |
| **R4** | `--all` unions `git ls-files` with `git ls-files --others --exclude-standard` and names the untracked files it pulled in. | An audit can no longer report a clean tree while an unguarded, not-yet-`git add`ed template sits in the working copy. |
| **R5** | A symlink in an in-scope path is **rejected** (C0), never scanned. | `git show :path` on a symlink returns the *link target*, one short line that passes every rule — so a staged symlink used to be a way to ship arbitrary CSS through the gate. |

## Rules (enforced by `bin/check-css-boundaries.sh`)

- **C0 — the gate must be able to parse the file.** An unterminated `/*` or
  `<!--` leaves everything after it unscanned, so it is reported rather than
  passing silently. A gate that quietly disarms itself is worse than no gate.
  A symlink in an in-scope path is reported here too (R5).
- **C1 — no ORK shell selectors *or markup* on the public CMS side.** Don't name
  `#theme_container`, `#newmenu` or `.ork-*` anywhere on the **public tier** —
  R1's `frontdoor/**` plus R2's public surface templates. **Markup counts**, not
  just stylesheet selectors: `id="theme_container"` and a `class` token starting
  `ork-` are rejected in those templates too. So are the **attribute-selector
  spellings** of the same coupling — `[id="theme_container"]`,
  `[class~="ork-card"]`, `[class^="ork-"]` — which are just different CSS
  syntax for the same thing. CSS identifier escapes (`#theme\_container`,
  `.ork\-x`, `#\74 heme_container`) are decoded first — they are the same CSS.
  Matching is **case-insensitive**, so `<div ID="theme_container">` and
  `<div CLASS="ork-card">` are caught: HTML attribute names are
  case-insensitive and that markup is not a trick, it is just markup.
  **`orkshell-interop.css` is fully exempt**, being the designated coupling
  point. **`cms-base.css` is narrowly exempt:** it may name `#theme_container`
  (it has to neutralize the container `default.theme` emits on standalone org
  sites) and nothing else — `#newmenu` and `.ork-` are rejected there like
  anywhere else. It is the one stylesheet a standalone org site loads globally,
  so a blanket exemption would let the whole quarantined override layer move
  back in with the gate green.
  **The admin tier is out of C1's scope entirely** — `cms/**` and the
  non-preview `Cms_*` templates are definitionally ORK-hosted application
  surfaces, render inside the shell, and have no portability claim to protect.
  C2 still applies to them.
- **C2 — don't *define* an `--ork-*` token.** Reading one with `var()` is fine;
  a `--ork-foo:` declaration is not. That includes every spelling of the
  definition: a formatter-wrapped colon on the next line, the *first*
  declaration of an inline style attribute (`<div style="--ork-card-bg:#f00">`),
  an `@property --ork-brand { … }` registration (which defines the token
  without ever writing `--ork-x:`), and a case variant such as `--ork-Brand` or
  `--ORK-brand`. Custom properties really are case-sensitive, so those are
  technically different tokens — but they are still CMS code writing into the
  CRM's namespace, which is the thing C2 exists to stop. Applies to every CMS
  file, both tiers, CSS and templates.
- **C3 — no *static* `<style>` block in a CMS template.** The CSS belongs in a
  stylesheet, where it is cacheable, lintable and visible to duplication
  analysis, instead of being re-sent in the HTML of every render. A PHP tag
  parked between rules, or echoing a literal (`<?= '' ?>`), does not launder a
  static block, and PHP that echoes a `<style>` tag built by string
  concatenation is rejected too. Tag matching is case-insensitive — `<STYLE>`
  and `<Style>` are valid HTML for the same element.
  **Scope: every CMS template** — R1's `frontdoor/**.tpl` and `cms/*.tpl`, and
  R2's surface templates. It used to be `frontdoor/blocks/*.tpl` plus the `cms/`
  and `Cms_*` globs, which left `_index.tpl`, `Site_shell.tpl`, `Page_view.tpl`,
  `Blog_index.tpl`, `Blog_post.tpl`, `org_header.tpl`, `render_blocks.tpl`,
  `_park_strip.tpl` and `org_blog_index.tpl` — precisely the templates this
  project lifted inline CSS *out of* — free to take it straight back.
  **A `<style>` is legal only if it passes both tests:**
  1. it interpolates a PHP **variable** into a declaration **value**
     (the `columns.tpl` exception below), and
  2. it brings no more than **8 static declarations** with it, counted
     cumulatively across the whole file.
  Test 1 alone was all-or-nothing per element, so one interpolation laundered
  an arbitrarily large static block — a single `repeat(<?= $n ?>, 1fr)` plus ten
  static rules passed. Test 2 is the budget: `columns.tpl` declares 6 static
  properties beside its interpolated one, so 8 leaves a genuine per-instance
  block two declarations of headroom and stops well short of a lifted-out
  stylesheet. It is per **file**, not per element, because N elements of 8 would
  reopen the same hole. Tune it at `C3_MAX_STATIC` in the script.
  **Residual gap, stated honestly:** up to 8 static declarations can still ride
  along in a file that has a legitimate interpolating block, and the counter
  only sees declarations — a `<style>` full of `@font-face` bodies or selectors
  with no declarations is under-counted. The budget makes the laundering *small
  and bounded*; it does not make it impossible.
  **Destinations:** `frontdoor/blocks/*.tpl` → `frontdoor/css/blocks.css`; other
  public-tier templates → the matching `frontdoor/css/` layer; every admin
  template → `cms/css/cms-admin.css`, which `cms/_shell_top.tpl` links exactly
  once for every admin surface.
  - **The one exemption is `Cms_deny.tpl`, and it is structural.**
    `Controller_Cms::_denyPermission()` `include`s that file directly and
    `exit`s: it never reaches the themed View pipeline, never includes
    `cms/_shell_top.tpl`, and emits its own `<!doctype html>` / `<head>` /
    `<body>`. It links **no stylesheet at all**, so its inline `<style>` is the
    only styling it can have. If the deny page ever starts rendering through
    the shell, drop the exemption in `bin/check-css-boundaries.sh` and lift its
    CSS like everything else.
- **C4 — nothing a standalone org site renders may pull in CRM CSS.** Two
  halves, and between them they need no include-graph walk to be reliable:
  - **C4-link** — no file on the **public tier** may link (or `@import`)
    `orkui.css`, `tokens.css` or `orkshell-interop.css`. Scope is the whole tier
    — C1's scope, stylesheets included — with exactly **one** exemption:
    `frontdoor/_assets_inshell.tpl`, the designated link point for the in-shell
    surfaces. The old scope was a two-file list, so a *new* partial
    (`frontdoor/_assets_extra.tpl` linking `orkui.css`, included from
    `Site_shell.tpl`) was the same one-line detour C4 exists to close, one
    directory deeper.
  - **C4-path** — a file on the **org-site render path** (`Site_shell.tpl` and
    everything under `frontdoor/`) may not include `_assets_inshell.tpl`, and
    may not `include` a `.tpl` that resolves **outside `frontdoor/`**. That is
    what makes C4-link's directory scope *sufficient* rather than merely likely:
    the render path is confined to a region C4-link covers completely, so a new
    partial has nowhere to hide. It resolves `$fdDir`-style bases by following
    in-file assignments (`$fdDir = DIR_TEMPLATE . 'default/frontdoor/'`),
    `__DIR__` and `DIR_TEMPLATE`, one variable hop at a time, reads PHP code
    only (prose and string contents are not statements), and is **fail-closed**:
    an include whose destination it cannot prove is reported.
    *Why not walk `Site_shell.tpl`'s transitive include graph?* Because the
    graph has a dynamic edge — `render_blocks.tpl` does `include $partial`, one
    file per block type — that no static walk can enumerate, and a closure that
    silently skips it has a hole in exactly the busiest directory. Bounding the
    path to a fully-covered region needs no enumeration at all.
- **C5 — CRM CSS must not name `.fd-*`, `.cms-*` or `.org-*`**, in the
  class-selector spelling or the attribute-selector one (`[class*="fd-"]`,
  `[class^="cms-"]`). Scope: R3 — every `.css` under `orkui/template/` that is
  not CMS-owned, so a new CRM stylesheet is guarded wherever it lands, not only
  in `style/`.
- **C6 — `default.theme` may link CRM CSS only where `$IsOrgSite` is provably
  false.** This is the rule that decides what a standalone org site actually
  downloads: the `if (empty($IsOrgSite)):` gate at `default.theme:104-110` picks
  `tokens.css` + `orkui.css` for in-shell surfaces and `cms-base.css` for org
  sites. A link added to its `else:` branch — or added unconditionally — brings
  back the 91 KB this whole separation exists to remove. The check is
  branch-aware (it follows PHP alternative-syntax `if`/`elseif`/`else`/`endif`
  nesting, so the legitimate branch is not a false positive) and fail-closed: a
  structure it cannot follow is reported, not waved through. If you restructure
  that gate, expect to teach `bin/check-css-boundaries.sh` the new shape.

Comment text is stripped before the rules run, so discussing these patterns in
prose — as this directory's files do constantly — is not a violation. The
stripper is string-aware: a quoted string that closes on its own line is never
scanned for comment openers, so `content: "/*"` no longer blinds the rest of a
file; and a quote that does *not* close on its line is not treated as a string,
so an apostrophe in prose cannot swallow one. Anything still open at EOF is C0.

Two normalisations run before any rule sees a line. **Carriage returns are
stripped**, because on a CRLF file the trailing `\r` matches none of the
`[ \t]*$` anchors these rules are built on — C2's wrapped-colon detection and
C6's branch tracker used to go quiet on exactly the files a Windows editor
produces. And **matching is case-folded**, because HTML tag and attribute names
are case-insensitive; the anchors survive (a class token must still *start*
with `ork-`), so folding cannot make `.ork-` match inside an unrelated word such
as `[class*="network-item"]`. `$IsOrgSite` is exempt from the folding — PHP
variable names are case-sensitive.

### The `columns.tpl` exception

`frontdoor/blocks/columns.tpl` is the one block template that keeps an inline
`<style>`, and C3 lets it through because its body interpolates PHP:
`grid-template-columns: repeat(<?= (int) $fdbCount ?>, 1fr)`. A stylesheet
cannot express a per-instance column count. It declares **6** static properties
beside that one, against C3's budget of 8 — so if you add three more static
declarations to that block, the gate will (correctly) tell you they belong in
`blocks.css`. Its `@media (max-width:760px)`
partner has to stay in that same `<style>` element, after the base rule, or a
stylesheet copy loaded earlier would lose the same-specificity order tie and the
phone breakpoint would stop collapsing to one column.

Note the consequence: `.fdb-columns` is one global selector, so with several
columns blocks on a page the last emission sets the column count for all of
them. Fix that properly (a per-count class, or an inline style on the wrapper)
before trying to dedupe the emissions — deduping by type drops later emissions
and re-flows earlier blocks; deduping by count reorders which emission is last.
Both change rendering.

## The runtime backstop (`tests/cms-css/boundary_test.php`)

Every hole ever found in `bin/check-css-boundaries.sh` existed because a text
scanner was outwitted by a spelling. The property the separation actually
protects is observable at runtime and cannot be spelled around:

> **A standalone public org site must serve zero bytes of ORK CRM CSS.**

`tests/cms-css/boundary_test.php` reads no source. It fetches the real surfaces
off a running app and asserts on the HTML that is served:

1. no org-site page links `tokens.css`, `orkui.css`, `reports.css`,
   `cms-admin.css` or `orkshell-interop.css` — and still links `cms-base.css` +
   `orgsite.css`, so "zero CRM CSS" cannot be won by serving no CSS at all;
2. the **in-shell** surfaces (front door, `Blog/index`, a discovered
   `Blog/post`) still link `orkui.css` + `tokens.css` + `orkshell-interop.css`,
   so satisfying (1) by unlinking the CRM stylesheets globally fails here
   instead of shipping;
3. the cascade order holds on every surface — `frontdoor.css` → `blocks.css` →
   `blog.css` → `orgsite.css`/`orkshell-interop.css` — and each layer is linked
   exactly once;
4. `blog.css` is linked on exactly the blog surfaces, cross-checked **against
   the markup rendered**: a class token starting `blog-`/`blogp-` must be
   present iff the layer is linked (`org-blog-card` is `orgsite.css`, not a
   `blog.css` hook);
5. no org-site page carries an inline `<style>` naming `#theme_container`,
   `#newmenu` or `.ork-`;
6. **authored body copy carries a non-colour link affordance on every tier** —
   the CSS each surface actually serves contains a `.fd-body-text a` rule with
   `text-decoration: underline` and a `--pk-link` colour, contains no `.fd-org`-
   scoped copy of it (one home, so the tiers cannot drift apart again), and
   carries the dark-mode `#theme_container` armour on the in-shell surfaces and
   **not** on an org site. This one fetches the linked stylesheets rather than
   only reading the HTML.
7. **no org-site page serves ORK's own analytics payload** — the gtag.js
   measurement id, the gtag.js loader, the Google Tag Manager container or the
   Cloudflare Web Analytics beacon — and the in-shell tier still serves all
   four. This one is not about CSS, but it is the same property one layer out:
   a kingdom's or park's public marketing site is not an ORK application
   surface and must not report into ORK's analytics.
8. **no org-site page loads `orkui.js`** — jQuery 1.7.1 + jQuery UI +
   tablesorter + the CRM's app code, 1,032,786 bytes render-blocking in
   `<head>`, 11x the CSS this separation removed — while it **does** still load
   its own `frontdoor.js`, so "no CRM JS" cannot be won by serving no
   behaviour at all. The in-shell tier still gets `orkui.js`. Nothing on the
   org-site render path references jQuery or any orkui.js global; the block
   templates' inline scripts (gallery lightbox, parks map) are plain DOM, and
   `CmsSanitizer` strips `<script>` and every `on*` handler from authored
   content, so an author cannot reintroduce the dependency.

It is **safe in any environment**: if nothing answers it prints
`SKIP: app not reachable …` and exits 0, and it skips a surface (with a note)
that the local DB cannot supply — post surfaces are discovered from the index
pages rather than hardcoded. Point it elsewhere with `ORK_BASE_URL`.

## Commands

```
php tests/cms-css/boundary_test.php    # runtime backstop; SKIPs cleanly if the app is down
php tests/cms-css/duplication_ratchet_test.php   # the duplication ratchet, both directions
bin/check-css-boundaries.sh --all      # audit every file in scope, untracked included
bin/check-css-boundaries.sh --staged   # what pre-commit runs
bin/check-css-boundaries.sh --files a.css b.tpl
npm run lint:css                       # stylelint + tab-indent check + duplication ratchet
npm run lint:css:fix                   # autofix what stylelint can
npm run lint:css:dupes:report          # list every duplicate group, never fails
npm run lint:css:dupes:rebaseline      # pin the observed counts (lowers freely, refuses to raise)
bin/check-css-duplication.php -v       # the same list, direct
bin/check-layering.sh --all            # the PHP layering gate, for comparison
```

**There is no runner for the `tests/cms-*/` scripts.** They are standalone
`php <file>` scripts — no PHPUnit, no bootstrap, no DB connection — deliberately
outside `phpunit.xml.dist`, so `bin/run-unit-tests.sh` does not pick them up.
Run the set by hand; this is the sign-off loop, and `boundary_test.php` belongs
in it:

```
for t in tests/cms-css/boundary_test.php tests/cms-css/duplication_ratchet_test.php \
         tests/cms-theme/tokens_test.php \
         tests/cms-site/site_test.php tests/cms-fields/allowlist_test.php \
         tests/cms-fields/officers_vacancy_test.php \
         tests/cms-sanitizer/sanitizer_test.php tests/cms-tenancy/tenancy_test.php \
         tests/cms-heraldry-color/color_test.php; do
    echo "== $t"
    php "$t" || exit 1
done
```

Every one of them exits non-zero on failure, so that loop is itself the gate —
do not pipe the run through `tail`, which would throw the status away.
`boundary_test.php` is the only one that needs the app up; it SKIPs (exit 0)
when it is not, so the loop is safe to run anywhere as written.

Both gates block `git commit` and `git push`. stylelint runs on pre-push too,
but is advisory and never blocks. Deliberate exception, shared by both gates:

```
ORK3_ALLOW_LAYER_VIOLATION=1 git commit …
ORK3_ALLOW_LAYER_VIOLATION=1 git push …
```

## The duplication ratchet

stylelint has `no-duplicate-selectors` and
`declaration-block-no-duplicate-properties`, but **no rule for a duplicate
declaration *body***: N different selectors carrying byte-identical
declarations, i.e. one component copied N times under N class prefixes. That is
the defect this directory actually accumulates — the feed-block family
(`.ko-*`, `.pm-*`, `.pe-*`, `.ke-*`, `.bf-*`, `.kp-*`, `.kpm-*`) is one
component rendered by seven block templates — and it was invisible to the gate
until `bin/check-css-duplication.php` landed.

It groups rules by **(at-rule context, normalized declaration body)**. The
at-rule context is the part that is easy to get wrong: two rules with the same
body inside two *different* `@media` blocks are not duplicates and cannot be
collapsed onto one selector list. Its comment stripper is string-aware, so a
`content: "/*"` cannot blind it to everything below.

`npm run lint:css` enforces two budgets, both pinned to the count on the day they
were last measured:

| Budget | Today | What it counts |
|---|---|---|
| `MAX_GROUPS_2PLUS` | **26** | duplicate bodies with **≥ 2 declarations** — the real DRY signal |
| `MAX_GROUPS_ANY` | **90** | every duplicate body, single-declaration coincidences included |

Both numbers live as constants at the top of `bin/check-css-duplication.php`.

### It is a ratchet, not a freeze — both directions fail

| Observed vs budget | Verdict | Remedy |
|---|---|---|
| **above** | `FAIL ^ DUPLICATION ROSE` | collapse the new group, or justify it and `--rebaseline --allow-raise` |
| **below** | `FAIL v DUPLICATION FELL` | `npm run lint:css:dupes:rebaseline` — pin the improvement |
| **equal** | `OK ratchet held` | — |

The below-budget failure is the part people find surprising, so it is worth
being blunt about why it exists. The budgets were set *equal* to the observed
counts, and only ever moved by hand. So a one-sided gate meant: collapse a group
today, the count drops, the gate stays green, and the slack you just created
sits there for the next commit to spend on a fresh copy — with the gate green the
whole time. Duplication could never improve **on paper**, because nothing ever
lowered the number. An improvement that is not pinned is an improvement the next
commit may silently give back. Now it costs one command to make it permanent,
and you cannot forget.

**Re-baselining is one command:**

```
npm run lint:css:dupes:rebaseline
```

It rewrites both constants to the observed counts and prints the before/after.
It **lowers freely** and **refuses to raise** either one unless you also pass
`--allow-raise` — raising a budget is how duplication gets laundered through a
gate, so it costs a deliberate keystroke and a sentence in the commit message.
Either way the edit lands in your working tree; commit it *with* the change that
moved the number, never on its own. The failure message also prints the exact
`file:line` and the exact replacement line if you would rather edit by hand.

**Mid-cleanup and not ready to pin a floor?** The one-run escape hatch mirrors
the layering gate's `ORK3_ALLOW_LAYER_VIOLATION=1`:

```
CSS_DUP_ALLOW_SLACK=1 npm run lint:css
```

It forgives **only** the below-budget direction. It can never let duplication
rise, and it does not print a plain `OK` — it says `slack allowed` and tells you
to pin the floor before the branch merges.

All of this is pinned by `tests/cms-css/duplication_ratchet_test.php`, which
drives a copy of the gate over a throwaway stylesheet in `/tmp` — including
`--rebaseline` rewriting the copy's own constants, so the real repo is never
touched.

### The steps taken so far

The 91→90 step (2026-08-22) is the first **tightening**, and the first one the
gate demanded on its own. The largest duplicate body in the CMS CSS was seven
copies of `color: var(--cms-gold, #f0b429)` scattered across ~2,300 lines of
`cms-admin.css`; they are now one grouped rule under a `Gold accent text`
comment, sitting at the position of `.cms-editbar-hint-dirty` — the one member
whose cascade position is load-bearing, because it overrides `.cms-editbar-hint`
at equal specificity and only source order makes it win. Equivalence was proved,
not assumed: specificity is unchanged by a move, so the only way a move can
change a rendered value is by flipping a cascade pair that shares a property at
**equal** specificity in the same at-rule context. All 54,778 such ordered pairs
in the file were enumerated before and after; exactly 4 flipped, and all 4 are
between class pairs that never appear together on one element in any of the
4,203 distinct class attributes the repo emits. The next-biggest group — 6 copies
of `display: flex` — is **not** collapsible: four live in `cms-admin.css` and two
in `blocks.css`, and a selector list lives in exactly one file, so no grouping
takes that group below two members.

The 90→91 step (2026-08-22) is the one-declaration
`color:var(--pk-link, var(--fd-accent))` shared by frontdoor.css's dark
authored-link rule and orkshell-interop.css's `#theme_container` armour for the
same links. It is deliberate and **not collapsible by selector grouping**, the
only collapse this gate accepts: a selector list lives in one file, frontdoor.css
may not name `#theme_container` (C1), and a standalone org site never loads the
interop sheet but still needs the declaration. Both rules carry a comment saying
so, and `cms-admin.css` already holds the same shape for `.cms-btn-primary`.

The 22→26 / 78→90 step was a **coverage** re-baseline, not a duplication one:
lifting the admin templates' inline `<style>` blocks into `cms-admin.css`
(C3's admin half, above) did not author a single duplicate body — it moved 185
lines that had always been byte-identical to rules already in `cms-admin.css`
out of `<style>` elements no analyser could read and into the file the analyser
reads. Four `≥ 2`-declaration groups became *visible*, all of them
`cms-admin.css` against itself (`.cms-sites-org`/`.cms-nav-label` vs
`.cms-table .cms-pg-title`; the two badge bodies; `.cms-sites-count` vs
`.cms-quick-text span`), plus a third member on the existing gold-`:hover`
group. They are collapsible by selector grouping and are the obvious next
cleanup. The constants carry the same note.

**How to collapse a group**: selector grouping, never a class rename — block
templates and authored pages depend on the existing names. Put the selectors on
one rule at the position of the *first* member, and check two traps: members in
different at-rule contexts cannot share a rule at all, and the grouped rule must
still sit **after** any base rule it overrides at the same specificity, which is
not always the first member's position. (The 820px one-column collapse in
`blocks.css` is exactly that case — it sits at the `.pe-grid` copy, not the
earlier `.pm-grid` one, because it has to follow the base grid rule.)

## Conventions

- **4-space indent, never tabs.** The CRM files are mixed; ours are not, and
  `npm run lint:css:tabs` fails the lint if a tab-indented line appears.
- **Dark mode is `html[data-theme="dark"]`**, never a bare `prefers-color-scheme`
  block, and it lives next to the rule it overrides — not in a distant theme
  section.
- **`--fd-*` defaults in `frontdoor.css` must match `CmsThemeTokens::Defaults()`.**
  They had already drifted once; `tests/cms-theme/tokens_test.php` now fails if
  they drift again.
- **New block CSS goes in `blocks.css`**, new blog CSS in `blog.css`, new
  standalone-site chrome in `orgsite.css`. `frontdoor.css` is the shared `.fd-*`
  layer — anything you add there ships to every public CMS surface.
- **Anything that styles *authored* content belongs in the shared layer, not in
  one tier's.** `.fd-body-text` is what a richtext / raw_html block renders on
  all three tiers, so its rules live in `frontdoor.css`. The link rule was
  briefly `.fd-org`-scoped in `orgsite.css`, which fixed standalone sites and
  left the front door and the blog serving orkui.css's `a { color:#333;
  text-decoration:none }` — ~1.15:1 against the body copy around it, with colour
  as the only signal (WCAG 1.4.1). Scope by *what renders it*, not by which tier
  you happened to be looking at.
- **An override only belongs in `orkshell-interop.css` if an ORK rule outranks a
  CMS rule.** If you are putting a rule there for any other reason, it belongs in
  `frontdoor.css`.
