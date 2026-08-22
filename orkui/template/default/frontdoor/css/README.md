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

`frontdoor.css` → `blocks.css` → `blog.css` → then `orgsite.css` (standalone org
sites) or `orkshell-interop.css` (in-shell surfaces). `blocks.css` and `blog.css`
were split off the end of `frontdoor.css` and several of their rules win
same-specificity ties against it. Do not reorder.

Two partials emit the links — **add a stylesheet there, not in a page template**:

- `frontdoor/_assets_public.tpl` — `frontdoor.css`, `blocks.css`, `blog.css`, in
  that order. Safe on every public CMS surface, standalone org sites included.
  Included by `_index.tpl`, `Site_shell.tpl`, `Page_view.tpl`, `Blog_index.tpl`,
  `Blog_post.tpl`, `Cms_preview.tpl`.
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

## Rules (enforced by `bin/check-css-boundaries.sh`)

- **C0 — the gate must be able to parse the file.** An unterminated `/*` or
  `<!--` leaves everything after it unscanned, so it is reported rather than
  passing silently. A gate that quietly disarms itself is worse than no gate.
- **C1 — no ORK shell selectors *or markup* on the public CMS side.** Don't name
  `#theme_container`, `#newmenu` or `.ork-*` in `frontdoor/css/*.css`, in any
  template under `frontdoor/`, or in the six public surface templates that sit
  one directory up (`_index.tpl`, `Site_shell.tpl`, `Page_view.tpl`,
  `Blog_index.tpl`, `Blog_post.tpl`, `Cms_preview.tpl`). **Markup counts**, not
  just stylesheet selectors: `id="theme_container"` and a `class` token starting
  `ork-` are rejected in those templates too. CSS identifier escapes
  (`#theme\_container`, `.ork\-x`, `#\74 heme_container`) are decoded first —
  they are the same CSS.
  **`orkshell-interop.css` is fully exempt**, being the designated coupling
  point. **`cms-base.css` is narrowly exempt:** it may name `#theme_container`
  (it has to neutralize the container `default.theme` emits on standalone org
  sites) and nothing else — `#newmenu` and `.ork-` are rejected there like
  anywhere else. It is the one stylesheet a standalone org site loads globally,
  so a blanket exemption would let the whole quarantined override layer move
  back in with the gate green.
  **The admin is out of C1's scope entirely** — `cms/css/cms-admin.css` and the
  `cms/` + `Cms_*` templates are definitionally ORK-hosted application surfaces,
  render inside the shell, and have no portability claim to protect. C2 still
  applies to them.
- **C2 — don't *define* an `--ork-*` token.** Reading one with `var()` is fine;
  a `--ork-foo:` declaration is not, including when a formatter has wrapped the
  colon onto the next line. Applies to all CMS CSS and templates, admin
  included.
- **C3 — no *static* `<style>` block in `frontdoor/blocks/*.tpl`.** Block CSS
  belongs in `blocks.css`, where it is cacheable, lintable and visible to
  duplication analysis. A `<style>` is legal only when it interpolates a PHP
  **variable** into a declaration **value** — see the `columns.tpl` exception
  below. A PHP tag parked between rules, or echoing a literal (`<?= '' ?>`),
  does not launder a static block, and PHP that echoes a `<style>` tag built by
  string concatenation is rejected too.
- **C4 — markup a standalone org site renders must not link** `orkui.css`,
  `tokens.css` or `orkshell-interop.css`. Scope: `Site_shell.tpl` **and**
  `frontdoor/_assets_public.tpl`, the partial it includes.
- **C5 — CRM CSS must not name `.fd-*`, `.cms-*` or `.org-*`.** Scope: every
  `.css` under `style/`, as a directory — a new CRM stylesheet is guarded the
  moment it lands.
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

### The `columns.tpl` exception

`frontdoor/blocks/columns.tpl` is the one block template that keeps an inline
`<style>`, and C3 lets it through because its body interpolates PHP:
`grid-template-columns: repeat(<?= (int) $fdbCount ?>, 1fr)`. A stylesheet
cannot express a per-instance column count. Its `@media (max-width:760px)`
partner has to stay in that same `<style>` element, after the base rule, or a
stylesheet copy loaded earlier would lose the same-specificity order tie and the
phone breakpoint would stop collapsing to one column.

Note the consequence: `.fdb-columns` is one global selector, so with several
columns blocks on a page the last emission sets the column count for all of
them. Fix that properly (a per-count class, or an inline style on the wrapper)
before trying to dedupe the emissions — deduping by type drops later emissions
and re-flows earlier blocks; deduping by count reorders which emission is last.
Both change rendering.

## Commands

```
bin/check-css-boundaries.sh --all      # audit every tracked file in scope
bin/check-css-boundaries.sh --staged   # what pre-commit runs
bin/check-css-boundaries.sh --files a.css b.tpl
npm run lint:css                       # stylelint + the tab-indent check
npm run lint:css:fix                   # autofix what stylelint can
bin/check-layering.sh --all            # the PHP layering gate, for comparison
```

Both gates block `git commit` and `git push`. stylelint runs on pre-push too,
but is advisory and never blocks. Deliberate exception, shared by both gates:

```
ORK3_ALLOW_LAYER_VIOLATION=1 git commit …
ORK3_ALLOW_LAYER_VIOLATION=1 git push …
```

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
- **An override only belongs in `orkshell-interop.css` if an ORK rule outranks a
  CMS rule.** If you are putting a rule there for any other reason, it belongs in
  `frontdoor.css`.
