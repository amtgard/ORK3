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
# $IsOrgSite gate in default.theme, which C6 below guards). The one place the
# two layers are allowed to touch is frontdoor/css/orkshell-interop.css, which
# exists precisely so that coupling is visible in one file instead of scattered.
#
# Reference: docs/superpowers/specs/2026-08-21-cms-css-separation-design.md
#
# Usage:
#   bin/check-css-boundaries.sh --staged              # staged blob content (pre-commit)
#   bin/check-css-boundaries.sh --range master..HEAD  # files changed in a range (pre-push)
#   bin/check-css-boundaries.sh --files a.css b.tpl   # explicit paths (editor hook)
#   bin/check-css-boundaries.sh --all                 # every file in scope (audit)
#   bin/check-css-boundaries.sh --list                # print the scope: one line
#                                                     # per in-scope file, with the
#                                                     # rule mask that runs on it,
#                                                     # then the count. Runs no rule.
#
# --list exists so the SIZE of the scope is a number the gate itself produces.
# The design doc and the README quote it (the liveness sweep's denominator: every
# in-scope file gets a rule-appropriate violation appended and must be caught),
# and a figure re-derived by a hand-written duplicate of the scope logic would
# drift from the scope logic — the same defect as the model glob that was
# narrower than the controller glob. One implementation, asked directly.
#
# Exit 0 = clean, 1 = violations found, 2 = bad invocation.
# --list always exits 0: it reports scope, not compliance.
#
# Escape hatch (deliberate exceptions only): ORK3_ALLOW_LAYER_VIOLATION=1 is
# honoured by the git hooks that call this script, not by the script itself.
#
# ---------------------------------------------------------------------------
# SCOPE RULES — what is in scope is decided by RULE, never by a file list
#
# Every rule below used to name the files it applied to. That made the gate
# strongest on the files that already existed and blind to everything new: a
# static <style> block could be put straight back into any of the nine
# templates whose inline CSS this refactor lifted out, a NEW partial one
# directory deeper defeated C4, and a new public surface template or a new
# stylesheet was born with no coverage at all. Scope is now derived:
#
#   R1  CMS-OWNED DIRECTORIES. Everything (at any depth) under
#         orkui/template/default/frontdoor/    -> CMS, PUBLIC tier
#         orkui/template/default/cms/          -> CMS, ADMIN tier
#
#   R2  CMS SURFACE TEMPLATES ONE DIRECTORY UP. The router resolves
#       <Controller>/<action> to $TPL_ROOT/<Controller>_<action>.tpl, so the CMS
#       controllers' page templates sit beside the CRM's rather than under
#       frontdoor/. The CMS controllers are Site, Page, Blog and Cms
#       (orkui/controller/controller.{Site,Page,Blog,Cms}.php), so the rule is:
#
#         $TPL_ROOT/_index.tpl                 front door (base Controller::index())
#         $TPL_ROOT/Site_*.tpl                 standalone org sites   } PUBLIC
#         $TPL_ROOT/Page_*.tpl                 authored CMS pages     } tier
#         $TPL_ROOT/Blog_*.tpl                 blog index / post      }
#         $TPL_ROOT/Cms_preview*.tpl           renders the PUBLIC page in preview chrome
#         $TPL_ROOT/Cms_*.tpl                  OGRE admin  -> ADMIN tier
#
#       A new Site_home.tpl, Blog_tag.tpl, Page_index.tpl or Cms_anything.tpl is
#       therefore in scope the moment it lands. Cms_preview* is the one
#       public-tier exception inside the Cms_ prefix, and it is a naming
#       contract: a Cms_ template that renders the public page must be named
#       Cms_preview<something>.
#
#       THE CONTROLLER SET IS NO LONGER A HAND-MAINTAINED LIST. It used to be,
#       documented as "the single manual step this design keeps" — and a manual
#       step is a step someone forgets, silently: a new Ogre_view.tpl linking
#       orkui.css, carrying a static <style> AND writing id="theme_container"
#       class="ork-card" passed at exit 0, with no rule switched on for it at
#       all. Three things now close that, and each covers the one before it:
#
#         DERIVED (R9)  CMS_CONTROLLERS is read off the filesystem — the
#                       controllers that use the CmsScopeContext trait, which is
#                       5 of 44 and is exactly the CMS set. Unioned with the
#                       historical list as a FLOOR, so derivation can only add.
#         EVIDENCE (R10) A $TPL_ROOT template that RENDERS CMS CHROME (includes
#                       a frontdoor/ or cms/ partial, links a stylesheet from
#                       either) is a CMS surface whoever owns its controller —
#                       the net under a CMS controller that never used the trait.
#                       The include destination is RESOLVED by the same resolver
#                       C4-PATH uses. It used to be a literal string match, which
#                       matched no include line in this repo — every one of them
#                       goes through a $fdDir assigned earlier in the file — so
#                       R10 covered nothing but docblocks and the whole of this
#                       net was open. See R10 below.
#         FAIL-CLOSED (C8) A $TPL_ROOT/<X>_<action>.tpl with no
#                       controller.<X>.php is a routed surface the gate cannot
#                       classify, and is REPORTED rather than skipped.
#
#       What remains, stated honestly: a controller with no CMS marker whose
#       template renders no CMS chrome is, on all available evidence, a CRM
#       surface — and a CRM surface linking orkui.css and naming #theme_container
#       is doing its job, not violating anything. There is nothing left to detect
#       in that case, which is why the residual is principled rather than a gap.
#
#   R3  EVERY OTHER STYLESHEET UNDER orkui/template/ IS CRM-OWNED. C5 no longer
#       watches style/ specifically; it watches "a .css that is not CMS-owned",
#       so a stylesheet dropped at orkui/template/default/probe-tween.css — or
#       anywhere else outside the two CMS directories — is CRM code and is
#       guarded as CRM code from the moment it lands.
#
#   R4  --all CONSIDERS UNTRACKED FILES. git ls-files alone cannot see a file
#       that has not been `git add`ed yet, so an audit run used to declare a
#       clean tree while an unguarded new template sat in the working copy.
#       --all now unions `git ls-files` with `git ls-files --others
#       --exclude-standard` over the same pathspecs and names the untracked
#       files it pulled in.
#
#   R5  SYMLINKS ARE REJECTED, NOT SCANNED. In --staged mode `git show :path`
#       on a symlink returns the LINK TARGET as its content — one short line
#       that trivially passes every rule — so a symlink was a way to stage
#       arbitrary CSS/markup into an in-scope path with the gate green. A
#       symlink in an in-scope path is reported (C0) in every mode.
#
#   R6  THE CRM STYLESHEET SET COMES FROM THE FILESYSTEM. C4 and C6 used to
#       name three stylesheets (orkui.css, tokens.css, orkshell-interop.css),
#       so reports.css (55 KB) and custom.css — both of which have sat in
#       style/ the whole time — were invisible to them, and tomorrow's CRM
#       stylesheet would have been too. Everything under $TPL_ROOT/style/ is
#       CRM CSS by R3, so the shell asks the filesystem and hands the list to
#       the scanner. A CMS stylesheet must therefore not reuse a CRM
#       stylesheet's basename.
#
#   R7  ASSET-BASE SEEDS, ALSO DERIVED. A partial does not assign the base it
#       is handed — _assets_public.tpl documents "Expects $fdDir and
#       $fdAssetBase already in scope" and its six includers assign them — so
#       reading it alone, its href is unresolvable, and the fail-closed rule
#       below would fire on the one file whose job is linking the CMS
#       stylesheets. Every in-scope file is therefore scanned once for
#       `$name = HTTP_TEMPLATE|DIR_TEMPLATE . '…'`, and a name the tree assigns
#       a PROVABLE prefix to resolves to that prefix when the file being
#       scanned does not assign it itself. In-file assignment always wins; an
#       assignment that cannot be resolved seeds nothing. This works in the
#       dangerous direction too: point one of those names at style/ anywhere
#       and every partial that consumes it starts reporting.
#
#   R8  CMS PHP AND CMS JS ARE IN SCOPE FOR CSS INJECTION (C7). Rules C1-C6
#       read .css, .tpl and .theme, which is every file that can DECLARE CSS —
#       and none of the files that can INJECT it. A stylesheet <link> and a
#       <style>#theme_container{}</style> were put into a live org-site page
#       from two directions, and this gate plus the layering gate both returned
#       exit 0: once from frontdoor/js/frontdoor.js via
#       document.head.insertAdjacentHTML(), once from
#       orkui/controller/controller.Site.php echoing the markup. The blind set
#       was 31 files. It is now in scope, derived the same way everything else
#       here is:
#
#         orkui/controller/controller.<C>*.php   ONE derivation, <C> in
#         orkui/controller/trait.<C>*.php        CMS_CONTROLLERS, applied to
#         orkui/model/model.<C>*.php             every directory a CMS PHP
#         system/lib/ork3/class.<C>*.php         source lives in
#         orkui/model/model.FrontDoor.php        the front door's membrane; the
#                                                one name that has to be spelled
#                                                out, because the front door is
#                                                rendered by the BASE controller
#                                                and so has no CMS prefix
#         frontdoor/**.js, cms/**.js             R1, extended to scripts
#
#       The model and domain sets used to be matched on a literal "Cms" prefix
#       while the controllers were derived, which made the model set NARROWER
#       than the controller set — and the gap was reachable. Blog and Site are
#       CMS controllers, so model.BlogZz.php echoing a <style> and a CRM
#       stylesheet <link>, and model.SiteZz.php defining --ork-brand, both
#       passed at exit 0. trait.*.php was blind from the other direction:
#       trait.CmsScope.php sits in the controller directory and is mixed into the
#       CMS controllers, but the glob only said "controller.". Deriving all four
#       from CMS_CONTROLLERS means the halves cannot drift, and it adds exactly
#       one file that exists today (trait.CmsScope.php, clean) — the rest is
#       coverage for the model.Blog.php that does not exist yet.
#
#       Only C7 runs on these files. C1-C6 are about how CSS is declared and
#       linked in stylesheets and templates, and applying them to PHP source
#       would be a category error.
#
#   R9  THE CMS CONTROLLER SET IS DERIVED, NOT LISTED. Every rule above that
#       says "for <C> in CMS_CONTROLLERS" used to rest on a hand-maintained
#       list. CMS_CONTROLLERS is now read off the filesystem — the controllers
#       carrying the CmsScopeContext trait — unioned with the historical list as
#       a floor so the set can only grow. See R2 above for why, and for the two
#       rules that back it up.
#
#  R10  A SURFACE TEMPLATE THAT RENDERS CMS CHROME IS A CMS SURFACE TEMPLATE,
#       whatever its prefix and whoever owns its controller. Direct evidence,
#       not inference: it includes a frontdoor/ or cms/ partial, or links a
#       stylesheet from either directory.
#
#       THE INCLUDE DESTINATION IS RESOLVED, not matched as a literal. This was
#       a grep for the string "frontdoor/<name>.tpl", and every CMS template in
#       this repo writes `include $fdDir . 'render_blocks.tpl';` against a
#       $fdDir assigned earlier in the file — so the literal is never on the
#       include line, and the only lines R10 matched were the DOCBLOCKS that
#       mention a partial by path. It therefore gave the idiom actually in use
#       no coverage at all. R10 now calls resolve_include(), the same resolver
#       C4-PATH calls; see the CLASSIFY block in the scanner.
#
# ---------------------------------------------------------------------------
# The rules
#
#   C0  The scanner must be able to parse the file. A comment opener that is
#       never closed leaves every later line unscanned, so it is reported as a
#       violation rather than passing silently. See "Comment handling" below.
#       A symlink in an in-scope path is reported here too (R5).
#   C1  CMS CSS/markup may not name an ORK application-shell selector
#       (#theme_container, #newmenu, .ork-). BOTH stylesheet selectors and
#       template markup (id="theme_container", a class token starting "ork-")
#       are checked; the ATTRIBUTE-SELECTOR spellings of the same coupling
#       ([id="theme_container"], [class~="ork-card"]) count as well; and CSS
#       identifier escapes (#theme\_container, .ork\-x, #\74 heme_container)
#       are decoded before matching.
#       Scope: the PUBLIC CMS tier — R1's frontdoor/** plus R2's public surface
#       templates. One file is fully exempt: orkshell-interop.css, the
#       designated coupling point. cms-base.css is NARROWLY exempt — it may
#       name #theme_container (it has to neutralize the container default.theme
#       emits on standalone org sites) and nothing else; #newmenu and .ork- are
#       still rejected there.
#       C1 does NOT cover the ADMIN tier (cms/** and the non-preview Cms_*
#       templates): the OGRE admin is definitionally an ORK-hosted application
#       surface, renders inside the shell, and has no portability claim to
#       protect. C2 still applies there.
#   C2  CMS CSS may not DEFINE a token in the CRM's --ork-* namespace.
#       Reading one with var() is fine. A declaration whose colon has been
#       wrapped onto the following line counts as a definition, so does the
#       FIRST declaration of an inline style attribute
#       (<div style="--ork-card-bg:#f00">), and so does an @property
#       registration (@property --ork-brand { initial-value: red }), which
#       defines the token without ever writing "--ork-x:". Scope: every CMS
#       file, both tiers, css and templates.
#   C3  A CMS template may not carry a STATIC inline <style> block.
#       Scope: EVERY CMS template — R1's frontdoor/**.tpl and cms/*.tpl, and
#       R2's surface templates. It used to be frontdoor/blocks/*.tpl plus the
#       cms/ and Cms_* globs, which left _index.tpl, Site_shell.tpl,
#       Page_view.tpl, Blog_index.tpl, Blog_post.tpl, org_header.tpl,
#       render_blocks.tpl, _park_strip.tpl and org_blog_index.tpl — precisely
#       the templates whose inline CSS this project lifted out — free to take
#       it straight back.
#       A <style> is legal only if it BOTH:
#         (a) interpolates a PHP VARIABLE in a declaration-value position, and
#         (b) brings no more than C3_MAX_STATIC static declarations with it,
#             counted cumulatively over the whole file.
#       (a) alone was all-or-nothing per element, so ONE interpolation
#       laundered an arbitrarily large static block (1 interpolation + 10
#       static rules passed). (b) is the budget: blocks/columns.tpl
#       interpolates $fdbCount into grid-template-columns and declares 6 static
#       properties beside it (display, gap, align-items, min-width, and the two
#       in its @media partner), so the budget is 8 — enough headroom for a
#       genuine per-instance block, far short of a lifted-out stylesheet. It is
#       per FILE, not per element, because N elements of 8 would be the same
#       hole reopened.
#       ONE EXEMPTION, and it is structural rather than stylistic:
#       Cms_deny.tpl. Controller_Cms::_denyPermission() include()s that file
#       directly and exit()s — it never goes through the themed View pipeline,
#       never includes cms/_shell_top.tpl, and emits its own <!doctype
#       html>/<head>/<body>. It therefore loads NO stylesheet at all, and its
#       inline <style> is the only styling it can have. If that ever changes
#       (the deny page starts rendering through the shell), delete the
#       exemption below and lift its CSS like everything else.
#   C4  Nothing a standalone org site renders may pull in CRM CSS. Two halves,
#       and between them they need no include-graph walk to be reliable:
#       C4-LINK  No file on the PUBLIC CMS tier may link a CRM stylesheet.
#                Scope is the whole tier (C1's scope, stylesheets included so an
#                @import cannot smuggle one in) with exactly ONE exemption:
#                frontdoor/_assets_inshell.tpl, the designated link point for
#                the in-shell surfaces. The old scope was a two-file list, so a
#                NEW partial — frontdoor/_assets_extra.tpl linking orkui.css,
#                included from Site_shell.tpl — was the same one-line detour C4
#                exists to close, one directory deeper.
#                WHAT COUNTS AS A CRM STYLESHEET IS A PATH SHAPE, not a name
#                list: any path that lands in a style/ directory, plus the
#                basenames R6 derived from the filesystem. See "WHICH
#                STYLESHEET A PATH ACTUALLY NAMES" below for why — a literal
#                prefix missed `HTTP_TEMPLATE . "default/sty" . "le/orkui.css"`,
#                `../style/orkui.css`, `default/frontdoor/../style/orkui.css`
#                and an href split across two lines, all of them spellings this
#                codebase writes without trying to evade anything. And it is
#                FAIL-CLOSED: a stylesheet href or @import whose destination
#                cannot be proved is reported, exactly as C4-PATH already does
#                for an include it cannot resolve.
#       C4-PATH  A file on the ORG-SITE RENDER PATH (Site_shell.tpl and
#                everything under frontdoor/) may not include the in-shell
#                partial, and may not include a .tpl that resolves OUTSIDE
#                frontdoor/. That is what makes C4-LINK's directory scope
#                sufficient instead of merely likely: the render path is
#                confined to a region C4-LINK covers completely, so a new
#                partial has nowhere to hide. It resolves $fdDir-style bases by
#                following in-file assignments ($fdDir = DIR_TEMPLATE .
#                'default/frontdoor/'), __DIR__, and DIR_TEMPLATE, one variable
#                hop at a time, and is fail-closed: an include whose
#                destination it cannot prove is reported.
#                Chosen over walking Site_shell.tpl's transitive include graph
#                because the graph has a dynamic edge (render_blocks.tpl does
#                `include $partial`, one file per block type) that no static
#                walk can enumerate — a closure that silently skips it is a
#                closure with a hole in exactly the busiest directory. Bounding
#                the path to a fully-covered region needs no enumeration at all.
#   C5  CRM CSS may not name a CMS selector (.fd-, .cms-, .org-), in either the
#       class-selector or the attribute-selector spelling ([class*="fd-"],
#       [class^="cms-"]).
#       Scope: R3 — every .css under orkui/template/ that is not CMS-owned.
#   C6  default.theme may link a CRM stylesheet (anything landing in style/,
#       plus orkshell-interop.css) only from a branch where $IsOrgSite is
#       provably falsy. This is the rule that actually decides what a standalone
#       org site downloads: the $IsOrgSite gate around lines 104-110 is the
#       whole point of the separation, and a link added to its ELSE branch — or
#       added unconditionally — reintroduces 91 KB of CRM CSS on public org
#       sites. The check is branch-aware (it tracks PHP alternative-syntax
#       if/elseif/else/endif nesting and what each branch implies about
#       $IsOrgSite) and fail-closed twice over: an unbalanced structure it
#       cannot follow is reported rather than assumed safe, and so is a
#       stylesheet href on an org-reachable branch whose destination it cannot
#       prove. It matches the same PATH SHAPE C4-LINK does, so every spelling
#       listed under "WHICH STYLESHEET A PATH ACTUALLY NAMES" below is one
#       rule, not eight. Scope: $TPL_ROOT/*.theme.
#   C7  CMS PHP AND CMS JS MAY NOT INJECT CSS INTO A PAGE. Scope: R8.
#
#       ONE flat rule, no tiers, no exemption list. CSS enters a CMS page
#       through exactly three sanctioned channels — the two link partials
#       (frontdoor/_assets_public.tpl, frontdoor/_assets_inshell.tpl),
#       cms/_shell_top.tpl for the admin, and default.theme's $IsOrgSite gate —
#       and it lives in a stylesheet under frontdoor/css/ or cms/css/. A
#       controller, a model, a domain class and a script bundle are none of
#       those, on either tier, which is why C7 needs no public/admin split:
#       there is no legitimate case anywhere in the set. Reported:
#
#         a <style> element, however assembled — the literal tag, a tag built
#           by string concatenation ('<sty' . 'le>' / '<sty' + 'le>'), and the
#           DOM spellings that never write the tag at all
#           (document.createElement('style'), CSSStyleSheet + insertRule,
#           adoptedStyleSheets);
#         a stylesheet <link> — a <link> that HAS an href and whose rel is not
#           one of the known non-stylesheet rels, plus createElement('link');
#         an @import;
#         an ORK application-shell selector (#theme_container, #newmenu,
#           .ork-), in the same spellings C1 reads, including the
#           attribute-selector forms and CSS identifier escapes;
#         a definition in the CRM's --ork-* namespace, in the same spellings C2
#           reads, plus the JS one C2 has no reason to know:
#           el.style.setProperty('--ork-x', v);
#         a CRM stylesheet NAME or a style/<…>.css path shape, so a href
#           assembled into a variable is caught before it ever reaches a tag.
#
#       WHY THIS IS NOT A FALSE-POSITIVE ENGINE, which is the whole difficulty.
#       Several files in scope handle CSS legitimately, as DATA:
#
#         class.CmsThemeTokens.php  BUILDS css text — Block(), ToCss(),
#                                   ToRootCss() emit `.fd-page{--fd-x:…}`
#         class.CmsTheme.php        passes that css text around
#         class.CmsSanitizer.php    inspects and STRIPS style attributes and
#                                   <style>/<link> tags from authored content
#
#       The distinction drawn here is LITERAL EMISSION, NOT MENTION, and it is
#       drawn by choosing the trigger set rather than by exempting paths:
#
#         * C7 fires on the tag OPENERS "<style" and "<link", never on the
#           WORDS "style" and "link". CmsSanitizer's blocklist is
#           array('script', 'style', … 'link', …) — bare tag NAMES — so it
#           passes on its own merits.
#         * C7 fires on the CRM namespace --ork-*, never on the CMS namespace
#           --fd-*/--cms-*. CmsThemeTokens is a --fd-* factory from end to end,
#           so it passes on its own merits. If it ever starts writing --ork-* or
#           wrapping its output in a <style> tag, that is a real finding: the
#           tag belongs in default.theme, which already emits it.
#         * Comment text is stripped first (PHP's #-to-end-of-line form
#           included, for PHP sources only — in CSS "#" starts an id selector
#           and in JS a private field), so the docblocks in CmsTheme.php that
#           say "the <style> inner CSS for the active theme" are prose, not
#           emission.
#         * A <link> with no href is not a stylesheet link. class.CmsPost.php
#           writes `'<link>' . … . '</link>'` into an RSS feed — the RSS <link>
#           ELEMENT, whose URL is its text content — and passes on its own
#           merits.
#
#       THERE IS DELIBERATELY NO PATH EXEMPTION LIST. An exemption is a
#       standing hole that rots as the exempted file grows; a trigger set
#       narrow enough that the CSS-as-data classes never touch it does not.
#       Verified: all 31 files in scope report clean today.
#
#       Fail-closed where it cannot tell: a <link rel="<?= $rel ?>" href=…>
#       whose rel is assembled at runtime is reported, because an unprovable
#       rel could be "stylesheet". C7 deliberately does NOT resolve the href —
#       for these files EVERY stylesheet link is a violation whatever it points
#       at, so there is nothing to resolve and nothing to be fail-open about.
#
#       ESCAPE-ENCODED TAGS ARE DECODED FIRST. C7's nets read "<style" and
#       "<link", and two ordinary JS spellings put a <style> element on the page
#       without ever writing a "<":
#
#         '\x3cstyle\x3e.fd-page{color:red}\x3c/style\x3e' + insertAdjacentHTML
#         String.fromCharCode(60) + 'sty' + 'le' + String.fromCharCode(62)
#
#       Both used to score exit 0. The first is not merely an attack — \x3c is
#       the routine idiom for keeping a literal </script> out of an inline
#       script, so it is a plausible ACCIDENT, which is why it is decoded rather
#       than argued about. esc_decode() and fcc_decode() (see above the rules)
#       fold \xHH, \uHHHH, \u{H…} and String.fromCharCode/fromCodePoint into the
#       characters they denote before every C7 net looks at the line, and
#       fromCharCode is emitted as a QUOTED literal so the existing
#       concatenation-joining pass splices '<' + 'sty' + 'le' + '>' into
#       '<style>'. C3's PHP-fragment net decodes the same way, on the lines that
#       contain a PHP open tag — `echo "\x3cstyle\x3e"` in a .tpl was the same
#       hole. The CSS rules do NOT decode these forms, deliberately: in CSS a
#       backslash escape means something else (`\x3c` is the letter x then "3c"),
#       and css_unescape() already handles the CSS spelling.
#
#       Not covered, stated honestly: obfuscation beyond those forms — octal
#       escapes, atob()/base64, charCodeAt arithmetic, a computed template
#       literal, String.raw, a tag assembled through an array join, anything
#       rebuilt at runtime from data. A line scanner cannot close that and
#       claiming otherwise would be worse than saying so. Likewise CSS reached
#       by a route that names none of the above — a stylesheet URL fetched from
#       the database and handed to a template that links it, say.
#       tests/cms-css/boundary_test.php remains the backstop that reads what a
#       live surface actually serves.
#
#   C8  EVERY ROUTED SURFACE TEMPLATE MUST BE CLASSIFIABLE. A
#       $TPL_ROOT/<X>_<action>.tpl with no orkui/controller/controller.<X>.php
#       is a routed surface with nothing behind it: the gate cannot tell whether
#       it is CMS or CRM, so it runs no rule on it. Reported rather than skipped,
#       because "no rule matched" and "no rule ran" look identical from the
#       outside and only one of them is safe. Zero today — all 23 distinct
#       prefixes under $TPL_ROOT resolve to a controller that exists.
#       Leading-underscore files are partials, not routed surfaces, and are
#       skipped; $TPL_ROOT/_index.tpl is in scope by name already.
#
# Case and line endings. The scanner runs under LC_ALL=C and matches lowercase
# literals, so every rule matches against a tolower() view of the line. That is
# not an anti-evasion measure — HTML tag and attribute names are
# case-insensitive, so <STYLE>, <Style>, ID="theme_container" and
# CLASS="ork-card" are ordinary markup an unsuspecting developer writes, and
# each one used to walk past C1/C3. CSS custom properties are genuinely
# case-sensitive, so --ork-Brand is a different token from --ork-brand, but it
# is still CMS code defining into the CRM's namespace and C2 folds it too. The
# anchored patterns are kept (a class token must still START with "ork-"), so
# folding cannot make .ork- match inside an unrelated word. $IsOrgSite is
# excluded from the folding: PHP variable names are case-sensitive.
# Carriage returns are stripped from every line before anything else looks at
# it. On a CRLF file the trailing \r matches none of the [ \t]*$ anchors these
# rules use, which silently disarmed C2's wrapped-colon detection and C6's
# branch tracker on exactly the files a Windows editor produces.
#
# Comment handling. Comment text is stripped before the rules run — the files
# in scope discuss these very patterns in prose (including the literal string
# "<style>" inside PHP docblocks), and documentation is not a violation. The
# stripper is STRING-AWARE: a quoted string that closes on its own line is
# copied through verbatim and never scanned for comment openers, so
# `content: "/*"` or `var s = "<!-- x"` no longer opens a phantom comment that
# blinds the rest of the file. A quote that does NOT close on its line is not
# treated as a string at all, so an apostrophe in prose ("don't") cannot
# swallow a line either. Anything that still leaves a comment open at EOF is
# reported as C0 instead of silently disarming the scanner.

usage() {
    sed -n '3,40p' "$0" | sed 's/^# \{0,1\}//'
}

# Byte-wise matching: orkui/ carries UTF-8 content and stray non-UTF-8 bytes,
# and a locale-aware awk aborts on them.
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
LIST=0

while [ $# -gt 0 ]; do
    case "$1" in
        --staged) MODE=staged ;;
        --all)    MODE=all ;;
        --list)   MODE=all; LIST=1 ;;
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

# ---------------------------------------------------------------------------
# Layout
# ---------------------------------------------------------------------------
TPL_BASE="orkui/template"
TPL_ROOT="$TPL_BASE/default"
CMS_PUBLIC="$TPL_ROOT/frontdoor"
CMS_ADMIN="$TPL_ROOT/cms"
INTEROP="$CMS_PUBLIC/css/orkshell-interop.css"
CMS_BASE="$CMS_PUBLIC/css/cms-base.css"
SITE_SHELL="$TPL_ROOT/Site_shell.tpl"
ASSETS_INSHELL="$CMS_PUBLIC/_assets_inshell.tpl"
# Standalone bare-chrome page: included directly by the controller, renders its
# own <html>/<head>/<body>, never includes cms/_shell_top.tpl and so links no
# stylesheet whatsoever. Exempt from C3 — see the rule text above.
CMS_DENY="$TPL_ROOT/Cms_deny.tpl"

CMS_CONTROLLER_DIR="orkui/controller"
CMS_MODEL_DIR="orkui/model"
CMS_DOMAIN_DIR="system/lib/ork3"

# R2/R9 — THE CMS CONTROLLER SET IS DERIVED FROM THE FILESYSTEM.
#
# This used to be a hand-maintained list, documented as "the one manual step".
# A manual step is a step someone forgets, and forgetting it was SILENT: a new
# Ogre_view.tpl linking orkui.css, carrying a static <style> AND writing
# id="theme_container" class="ork-card" passed at exit 0, because no rule was
# switched on for it at all. A new surface got NO coverage and said nothing.
#
# So the set is derived the way check-layering.sh derives DOMAIN_CLASSES from
# system/lib/ork3/class.*.php — ask the filesystem, not a list. The marker is
# the CMS scope trait: controller.{Site,Page,Blog,Cms,CmsAjax}.php each open
# with `require_once __DIR__ . '/trait.CmsScope.php';` and `use
# CmsScopeContext;`, and NO other controller in the tree does (5 of 44, exactly
# the CMS set). It is a structural marker rather than a naming convention,
# which is the point: a controller cannot participate in CMS scope resolution
# without it.
#
# The historical list is kept as a FLOOR, not as the source of truth. Union, not
# replacement: deriving alone would mean that deleting the trait from
# controller.Blog.php quietly removes every Blog_*.tpl from scope, which is the
# original failure mode wearing a different hat. The floor makes the set
# monotone — derivation can only ever ADD.
CMS_CONTROLLERS_FLOOR="Site Page Blog Cms"
CMS_CONTROLLER_MARKER="CmsScopeContext"
CMS_CONTROLLERS=$(
    {
        printf '%s\n' $CMS_CONTROLLERS_FLOOR
        grep -lF "$CMS_CONTROLLER_MARKER" "$CMS_CONTROLLER_DIR"/controller.*.php 2>/dev/null |
            sed 's|.*/controller\.||; s|\.php$||'
    } | sed '/^$/d' | sort -u | tr '\n' ' '
)

# R8 — the CMS PHP source set, for C7. ONE derivation, applied to all three
# directories, so the halves cannot drift apart.
#
# Controllers were already derived from CMS_CONTROLLERS, but models and domain
# classes were matched on a literal "Cms" prefix instead. That is a NARROWER set
# than the controller set, and the gap was reachable: Blog and Site are CMS
# controllers, so orkui/model/model.BlogZz.php echoing a <style> and a CRM
# stylesheet <link>, and orkui/model/model.SiteZz.php defining --ork-brand, both
# passed at exit 0 — CMS-owned membranes for CMS surfaces, injecting exactly what
# C7 exists to stop, invisible because nobody had written "Cms" in the filename.
# orkui/controller/trait.*.php was blind for the same reason from the other
# direction: trait.CmsScope.php sits in the controller directory and is mixed
# into the CMS controllers, but the glob only said "controller.".
#
# So every CMS PHP source is now <prefix>.<C>*.php for <C> in CMS_CONTROLLERS,
# in the directory that prefix belongs to:
#
#   orkui/controller/controller.<C>*.php   controller.CmsAjax.php, a future
#   orkui/controller/trait.<C>*.php        controller.SiteAjax.php, trait.CmsScope.php
#   orkui/model/model.<C>*.php             model.CmsPage.php, a future model.Blog.php
#   system/lib/ork3/class.<C>*.php         class.CmsThemeTokens.php, a future class.Site*.php
#
# Widening the model and domain sets to Site/Page/Blog adds no file that exists
# today (there is no model.Site*.php, model.Page*.php, model.Blog*.php or
# class.{Site,Page,Blog}*.php), so it costs nothing now and covers the next one
# on the day it lands. It is also the right rule in its own terms for the domain
# directory: a system/lib/ork3/ class emitting CSS is a layering violation
# whoever owns it.
#
# model.FrontDoor.php is the one name that still has to be written out: the front
# door is rendered by the BASE controller, so its membrane carries no CMS
# controller prefix to derive from.
# (CMS_CONTROLLER_DIR, CMS_MODEL_DIR and CMS_DOMAIN_DIR are set above the
# controller derivation, which needs the controller directory to read.)
CMS_MODEL_FRONTDOOR="orkui/model/model.FrontDoor.php"

# The static-declaration budget an interpolating <style> may carry (C3, per file).
C3_MAX_STATIC=8

# C3-TOTAL — THE TREE-WIDE INLINE-STATIC-CSS RATCHET.
#
# C3_MAX_STATIC above is per FILE, and a per-file budget reopens one level up:
# three new partials under frontdoor/ carrying 8 static declarations each are 24
# static declarations back inline, every file inside its budget, gate exit 0.
# (Proven. R1 puts a new partial in scope wherever under frontdoor/ it sits, so
# there is nothing to stop the third, the tenth or the fiftieth.) The per-file
# rule bounds one file; it cannot bound the render path, because the render path
# is the SUM.
#
# So the quantity that is actually pinned is the tree-wide one: how many static
# declarations ride along inside LEGAL (PHP-interpolating) <style> blocks across
# every CMS template, counted over the whole tree in every mode. Today that is 6
# — the six columns.tpl declares beside its interpolated grid-template-columns
# (display, gap, align-items, min-width, and the two in its @media partner) —
# and it is the ONLY file that contributes. cms/_shell_top.tpl's "<style>" is
# prose inside a PHP comment and Cms_deny.tpl is exempt from C3 altogether
# (structural: it bypasses the shell and links no stylesheet at all), so both
# contribute 0 and both keep passing.
#
# A static-ONLY <style> is not in this number: it is a plain C3 violation and is
# reported as one. This budget measures exactly the laundering channel — static
# CSS riding on a legitimate interpolation.
#
# IT IS A RATCHET, NOT A FREEZE, for the reason bin/check-css-duplication.php
# gives: a budget that only fails upward lets slack sit there for the next
# commit to spend, with the gate green throughout. Above the pin fails ("ROSE"),
# below it fails too ("FELL — re-pin"), and the failure prints the exact line of
# this file and the replacement line so re-pinning is a one-line edit. The
# below-budget direction only — never the above — is forgiven for one run by
# CSS_STATIC_ALLOW_SLACK=1, mirroring CSS_DUP_ALLOW_SLACK=1.
#
# Raising it is therefore a deliberate, reviewable act, which is the whole point:
# the fourth partial is not a judgement call about whether 8 is small, it is a
# diff that raises a pinned number.
C3_TOTAL_STATIC=6

# R6 — THE CRM STYLESHEET SET IS DERIVED FROM THE FILESYSTEM, not listed here.
# C4-link and C6 used to hardcode "orkui.css|tokens.css|orkshell-interop.css",
# which meant the two other stylesheets that have sat in style/ all along —
# reports.css (55 KB) and custom.css — were unknown to the gate, and a CRM
# stylesheet added tomorrow would be unknown to it too. Everything under
# $TPL_ROOT/style/ is CRM CSS by R3, so ask the filesystem what is there. Same
# idea as check-layering.sh deriving DOMAIN_CLASSES from system/lib/ork3/.
#
# orkshell-interop.css is appended because it is CMS-authored but exists only to
# fight ORK chrome, so a standalone org site must not load it either.
#
# The set is a SAFETY NET on top of the path-shape rule below, not the primary
# check: a stylesheet is CRM because of WHERE IT LIVES (any `style/<…>.css`
# path, however spelled), and the name list additionally catches a copy served
# from somewhere else. A CMS stylesheet must therefore not reuse a CRM
# stylesheet's basename — name it for the CMS layer it belongs to.
CRM_SHEETS=$(find "$TPL_ROOT/style" -type f -name '*.css' 2>/dev/null |
    sed 's#.*/##' | sort -u | tr '\n' ' ')
CRM_SHEETS="$CRM_SHEETS orkshell-interop.css"

# Pathspecs that describe the whole in-scope region, for --all. Globs are passed
# to git literally (see `set -f` below), because git matches `*` across `/` and
# the shell does not.
ALL_PATHSPECS="$CMS_PUBLIC
$CMS_ADMIN
$TPL_ROOT/*.theme
$TPL_ROOT/_index.tpl
$TPL_BASE/*.css
$CMS_MODEL_FRONTDOOR"
for c in $CMS_CONTROLLERS; do
    ALL_PATHSPECS="$ALL_PATHSPECS
$TPL_ROOT/${c}_*.tpl
$CMS_CONTROLLER_DIR/controller.${c}*.php
$CMS_CONTROLLER_DIR/trait.${c}*.php
$CMS_MODEL_DIR/model.${c}*.php
$CMS_DOMAIN_DIR/class.${c}*.php"
done

# ---------------------------------------------------------------------------
# Scanner
# ---------------------------------------------------------------------------
AWKPROG=$(mktemp) || exit 2
CONTENT=$(mktemp) || exit 2
# One "<file>\t<static declarations>" line per file C3 ran on, for the C3-TOTAL
# ratchet below.
STATICS=$(mktemp) || exit 2
trap 'rm -f "$AWKPROG" "$CONTENT" "$SEEDS" "$STATICS"' EXIT INT TERM

cat > "$AWKPROG" <<'AWKEOF'
function report(rule, lineno, msg, fix) {
    # CENSUS runs exist only to COUNT (C3-TOTAL). They re-scan files the caller
    # never asked about, so anything they find is not this invocation's finding
    # and must not be printed or counted — the reporting pass covers those files
    # on its own terms.
    if (CENSUS) return
    printf "  %s%s%s  %s:%d\n", C_RED, rule, C_OFF, file, lineno
    printf "        %s\n", msg
    printf "        %s-> %s%s\n", C_DIM, fix, C_OFF
    hits++
}

# ---------------------------------------------------------------------------
# Lexing
# ---------------------------------------------------------------------------

# Index of the closing quote of the string that opens at position i with quote
# character qc, or 0 if it does not close on this line. A quote that does not
# close on its own line is deliberately NOT treated as a string: otherwise an
# apostrophe in prose ("don't name #theme_container") would swallow the rest of
# the line and hide the very thing we are looking for.
function str_end(s, i, qc,    j, n, c) {
    n = length(s)
    j = i + 1
    while (j <= n) {
        c = substr(s, j, 1)
        if (c == "\\") { j = j + 2; continue }
        if (c == qc) return j
        j++
    }
    return 0
}

# Is the "//" at position i a URL rather than a line comment? "https://x",
# "@import url(//cdn/x.css)", "src=//cdn/x". Getting this wrong swallows the
# rest of the line, which is how a protocol-relative @import used to hide a
# #theme_container rule sitting after it.
function url_slashes(s, i,    p) {
    if (i <= 1) return 0
    p = substr(s, i - 1, 1)
    return (p == ":" || p == "(" || p == "=" || p == "\"" || p == "'")
}

# Return the line with comment text removed, so the rules below see code only.
# This is not cosmetic: every file in scope documents the patterns these rules
# forbid, in prose, and four block templates mention the literal string
# "<style>" inside a PHP docblock. A naive "skip lines that start with a
# comment marker" filter gets both halves wrong — it misses continuation lines
# of a multi-line /* */ block (frontdoor/css/orgsite.css names #theme_container
# in one), and, because a CSS id selector starts with "#", it would skip the
# real "#theme_container { … }" rules C1 exists to catch.
#
# CSS carries /* */ only; templates also carry // and <!-- --> comments.
# in_block / in_html are file-scoped state, so multi-line comments carry over —
# and the END rule reports C0 if either is still open at EOF.
function decomment(s,    out, i, n, c, q, p) {
    out = ""
    i = 1
    n = length(s)
    while (i <= n) {
        if (in_block) {
            p = index(substr(s, i), "*/")
            if (p == 0) return out
            i = i + p + 1
            in_block = 0
            out = out " "
            continue
        }
        if (in_html) {
            p = index(substr(s, i), "-->")
            if (p == 0) return out
            i = i + p + 2
            in_html = 0
            out = out " "
            continue
        }
        c = substr(s, i, 1)
        # A string that closes on this line is copied through verbatim and is
        # never scanned for comment openers. Copied VERBATIM, not masked, so
        # C1's markup check can still see class="ork-…".
        if (c == "\"" || c == "'") {
            q = str_end(s, i, c)
            if (q > 0) { out = out substr(s, i, q - i + 1); i = q + 1; continue }
            out = out c
            i++
            continue
        }
        if (substr(s, i, 2) == "/*") { in_block = 1; out = out " "; i = i + 2; continue }
        if (tpl && substr(s, i, 4) == "<!--") { in_html = 1; out = out " "; i = i + 4; continue }
        # PHP's third comment syntax, "#" to end of line (or to ?>). Enabled for
        # PHP SOURCES ONLY: in CSS "#" opens an id selector — the very thing C1
        # is looking for — and in JS it opens a private field. Strings were
        # already copied through above, so '#fff' and '#theme_container' as data
        # are untouched; this only strips prose, which is exactly the point:
        # `# theme_container is the shell root` is documentation, not emission.
        if (phpsrc && c == "#") {
            p = index(substr(s, i), "?>")
            out = out " "
            if (p == 0) return out
            i = i + p - 1
            continue
        }
        if (tpl && substr(s, i, 2) == "//" && !url_slashes(s, i)) {
            # A PHP // comment ends at ?>, not merely at end of line. Honouring
            # that keeps the PHP-region tracker (C6) in sync on the very common
            # `<?php if (...): // why ?>` line.
            p = index(substr(s, i), "?>")
            out = out " "
            if (p == 0) return out
            i = i + p - 1
            continue
        }
        out = out c
        i++
    }
    return out
}

function hexval(ch,    p) {
    p = index("0123456789abcdef", tolower(ch))
    return p - 1
}

# ---------------------------------------------------------------------------
# JS/PHP STRING-ESCAPE DECODING — for C7 only (the PHP and JS sources)
#
# C7's tag nets look for "<style" and "<link". Neither of these ever writes a
# "<", and both put a <style> element on the page:
#
#   '\x3cstyle\x3e.fd-page{color:red}\x3c/style\x3e'   + insertAdjacentHTML()
#   String.fromCharCode(60) + 'sty' + 'le' + String.fromCharCode(62)
#
# The first is not only an attack. \x3c is the routine JS idiom for keeping a
# literal "</script>" out of an inline script, and a developer reaches for it
# without trying to evade anything — which is exactly why it has to be decoded
# rather than argued about.
#
# Scope is deliberately C7 (PHP and JS) and NOT the CSS/template rules. In CSS
# a backslash escape means something else — `\x3c` is the CSS escape for the
# LETTER x followed by "3c", not for "<" — so decoding it there would invent
# violations. css_unescape() already handles the CSS spelling, on the CSS files.
#
# RESIDUAL, STATED HONESTLY. This decodes the forms people actually write:
# \xHH, \uHHHH, \u{H…}, and String.fromCharCode/fromCodePoint with a decimal
# argument list. It does NOT decode octal escapes, atob()/base64,
# charCodeAt arithmetic, computed template-literal parts, String.raw, a tag
# assembled through an array join, or anything reconstructed at runtime from
# data. That is not a solvable problem in a line scanner and pretending
# otherwise would be worse than saying so: tests/cms-css/boundary_test.php
# remains the backstop that reads what a live surface actually serves.
# ---------------------------------------------------------------------------

# \xHH, \uHHHH and \u{H…} -> the character. Runs on the case-folded view, so
# the hex digits are already lowercase.
function esc_decode(s,    out, i, n, c, c2, hx, v, j, k) {
    if (index(s, "\\") == 0) return s
    out = ""
    i = 1
    n = length(s)
    while (i <= n) {
        c = substr(s, i, 1)
        if (c != "\\") { out = out c; i++; continue }
        c2 = substr(s, i + 1, 1)
        if (c2 == "x" && substr(s, i + 2, 2) ~ /^[0-9a-f][0-9a-f]$/) {
            v = hexval(substr(s, i + 2, 1)) * 16 + hexval(substr(s, i + 3, 1))
            out = out ((v > 0 && v < 256) ? sprintf("%c", v) : " ")
            i += 4
            continue
        }
        if (c2 == "u" && substr(s, i + 2, 4) ~ /^[0-9a-f][0-9a-f][0-9a-f][0-9a-f]$/) {
            hx = substr(s, i + 2, 4)
            v = 0
            for (k = 1; k <= 4; k++) v = v * 16 + hexval(substr(hx, k, 1))
            out = out ((v > 0 && v < 256) ? sprintf("%c", v) : " ")
            i += 6
            continue
        }
        if (c2 == "u" && substr(s, i + 2, 1) == "{") {
            j = index(substr(s, i + 3), "}")
            hx = (j > 1) ? substr(s, i + 3, j - 1) : ""
            if (hx ~ /^[0-9a-f]+$/) {
                v = 0
                for (k = 1; k <= length(hx); k++) v = v * 16 + hexval(substr(hx, k, 1))
                out = out ((v > 0 && v < 256) ? sprintf("%c", v) : " ")
                i += 3 + j
                continue
            }
        }
        # Any other escape — "\\" very much included — copies BOTH characters
        # and steps past them, so \\x3c (an escaped backslash followed by the
        # literal text x3c) does not decode, and a CSS escape that reached here
        # (\74 ) is passed through untouched for css_unescape to handle.
        out = out c c2
        i += 2
    }
    return out
}

# String.fromCharCode(60) / .fromCodePoint(60, 115, …) -> a QUOTED literal, so
# the concatenation-joining pass downstream can splice it onto the fragments
# beside it: '<' + 'sty' + 'le' + '>' becomes '<style>'. Emitting a bare "<"
# would leave the " + '" between it and "sty" and the tag net would still miss.
function fcc_decode(s,    out, pre, lit, args, n, arr, i, v, q) {
    out = ""
    while (match(s, /(string[ \t]*\.[ \t]*)?from(charcode|codepoint)[ \t]*\([ \t]*[0-9][0-9 \t,]*\)/)) {
        pre = substr(s, 1, RSTART - 1)
        lit = substr(s, RSTART, RLENGTH)
        s   = substr(s, RSTART + RLENGTH)
        args = lit
        sub(/^[^(]*\([ \t]*/, "", args)
        sub(/[ \t]*\)$/, "", args)
        n = split(args, arr, /[ \t]*,[ \t]*/)
        v = ""
        for (i = 1; i <= n; i++) {
            if (arr[i] !~ /^[0-9]+$/ || arr[i] + 0 < 1 || arr[i] + 0 > 255) { v = ""; break }
            v = v sprintf("%c", arr[i] + 0)
        }
        if (v == "") { out = out pre lit; continue }
        # Quote with whichever quote the decoded text does not itself contain.
        q = (index(v, "'") == 0) ? "'" : ((index(v, "\"") == 0) ? "\"" : "")
        out = out pre q v q
    }
    return out s
}

# ---------------------------------------------------------------------------
# Attribute-selector forms of the couplings C1 and C5 look for
#
# `[id="theme_container"] a {}` and `[class~="ork-card"] {}` are the same
# coupling as `#theme_container a {}` and `.ork-card {}`, just written with a
# different piece of CSS syntax; `[class*="fd-"]` is the same coupling C5
# forbids. All three run on the CASE-FOLDED line, because an HTML attribute
# name is case-insensitive. The operator is optional and may be any of
# ~ ^ $ * | (CSS has no others).
# ---------------------------------------------------------------------------
function attr_sel_id(s, name) {
    return (s ~ ("\\[[ \t]*id[ \t]*[~^$*|]?=[ \t]*[\"']?" name))
}

# A class attribute selector naming the ORK namespace. The value must START a
# whitespace-separated token with "ork-": requiring that boundary is what keeps
# [class*="work-item"] from reading as an ORK class.
function attr_sel_class(s, want) {
    if (s ~ ("\\[[ \t]*class[ \t]*[~^$*|]?=[ \t]*[\"']?" want)) return 1
    return (s ~ ("\\[[ \t]*class[ \t]*[~^$*|]?=[ \t]*[\"'][^\"']*[ \t]" want))
}

# Decode CSS identifier escapes so `#theme\_container`, `.ork\-btn` and
# `#\74 heme_container` cannot walk past C1. They are all semantically
# identical CSS to the unescaped form.
function css_unescape(s,    out, i, n, c, j, hx, v, k, ch) {
    if (index(s, "\\") == 0) return s
    out = ""
    i = 1
    n = length(s)
    while (i <= n) {
        c = substr(s, i, 1)
        if (c != "\\") { out = out c; i++; continue }
        hx = ""
        j = i + 1
        while (j <= n && length(hx) < 6) {
            ch = substr(s, j, 1)
            if (ch ~ /[0-9a-fA-F]/) { hx = hx ch; j++ } else { break }
        }
        if (length(hx) > 0) {
            v = 0
            for (k = 1; k <= length(hx); k++) v = v * 16 + hexval(substr(hx, k, 1))
            if (j <= n && substr(s, j, 1) == " ") j++
            if (v >= 32 && v < 127) out = out sprintf("%c", v)
            i = j
            continue
        }
        if (i + 1 <= n) { out = out substr(s, i + 1, 1); i = i + 2 } else { i++ }
    }
    return out
}

# ---------------------------------------------------------------------------
# C3 — static inline <style> in a CMS template
#
# Two things are measured, because either one alone is a hole:
#
#   style_php     did the element interpolate a PHP VARIABLE into a
#                 declaration VALUE? A tag parked between rules, or one echoing
#                 a literal, does not count.
#   style_static  how many STATIC declarations rode along with it. This is the
#                 half that was missing: staticness was decided per element,
#                 all-or-nothing, so a single interpolation laundered an
#                 arbitrarily large block. The budget (C3_MAX_STATIC) is
#                 cumulative over the FILE, since per-element budgets just move
#                 the hole to "emit more elements".
# ---------------------------------------------------------------------------

# Text since the last {, } or ; — i.e. the declaration we are currently inside.
function last_seg(s,    i, c) {
    for (i = length(s); i >= 1; i--) {
        c = substr(s, i, 1)
        if (c == "{" || c == "}" || c == ";") break
    }
    return substr(s, i + 1)
}

# One declaration has ended (at ; or }). Count it as static or interpolated.
# Only inside a rule body: at brace depth 0 we are in a selector or an at-rule
# prelude, and `a:hover` there is not a declaration. A segment terminated by {
# is a prelude and is discarded by decl_feed rather than reaching here.
function decl_flush(    seg) {
    seg = tolower(dc_seg)
    dc_seg = ""
    if (dc_depth < 1) return
    if (seg !~ /^[ \t]*(--)?[-a-z_][-a-z0-9_]*[ \t]*:/) return
    if (index(seg, PHPMARK) > 0) style_interp++
    else style_static++
}

function decl_feed(t,    i, n, c) {
    n = length(t)
    for (i = 1; i <= n; i++) {
        c = substr(t, i, 1)
        if (c == "{")      { dc_depth++; dc_seg = "" }
        else if (c == "}") { decl_flush(); if (dc_depth > 0) dc_depth--; dc_seg = "" }
        else if (c == ";") { decl_flush() }
        else if (length(dc_seg) < 400) { dc_seg = dc_seg c }
    }
}

# Consume style-element text, deciding whether the block genuinely interpolates
# PHP. It counts only when the PHP tag sits in a declaration-VALUE position and
# the expression references a PHP variable — `grid-template-columns: repeat(<?=
# (int) $fdbCount ?>, 1fr)` counts; `<?= '' ?>` parked between rules does not.
# Every PHP expression is also fed to the declaration counter as a single
# marker character, so the declaration that contains it reads as interpolated
# and every other declaration reads as static.
function style_consume(seg,    p, e, expr, tail, lit) {
    while (1) {
        p = index(seg, "<?")
        if (p == 0) { style_tail = style_tail seg; decl_feed(seg); break }
        lit = substr(seg, 1, p - 1)
        style_tail = style_tail lit
        decl_feed(lit)
        e = index(substr(seg, p), "?>")
        if (e == 0) { expr = substr(seg, p + 2); seg = "" }
        else        { expr = substr(seg, p + 2, e - 3); seg = substr(seg, p + e + 1) }
        tail = last_seg(style_tail)
        if (index(expr, "$") > 0 && tail ~ /[-a-zA-Z][-a-zA-Z0-9]*[ \t]*:/) style_php = 1
        decl_feed(PHPMARK)
        style_tail = style_tail " "
        if (seg == "") break
    }
    if (length(style_tail) > 400) style_tail = substr(style_tail, length(style_tail) - 399)
}

# Close out one <style> element: report it, or charge its static declarations
# to the file's budget.
function style_close(    ) {
    if (!style_php) {
        report("C3", style_line, C3_MSG, C3_FIX)
        return
    }
    file_static += style_static
    if (file_static > C3_MAX_STATIC && !budget_hit) {
        budget_hit = 1
        report("C3", style_line, \
               "Inline <style> interpolates PHP, but carries " file_static " static declarations (budget " C3_MAX_STATIC ").", \
               "One interpolated value does not make a stylesheet inline-worthy. Keep the interpolated declaration here and move the static declarations to " C3_DEST ".")
    }
}

# `s` is the real text (style_consume needs its case for PHP), `ls` the same
# text case-folded. tolower() preserves length, so positions found in `ls`
# index `s` exactly — which is how <STYLE> and <Style> get found. HTML tag
# names are case-insensitive, so uppercasing one is valid markup, not
# obfuscation, and it used to walk straight past C3.
function scan_style(s, ls,    p, q, seg) {
    while (1) {
        if (!in_style) {
            p = index(ls, "<style")
            if (p == 0) return
            in_style = 1
            style_line = FNR
            style_php = 0
            style_static = 0
            style_interp = 0
            style_tail = ""
            dc_depth = 0
            dc_seg = ""
            s = substr(s, p + 6)
            ls = substr(ls, p + 6)
            continue
        }
        q = index(ls, "</style")
        if (q == 0) { style_consume(s); return }
        seg = substr(s, 1, q - 1)
        style_consume(seg)
        style_close()
        in_style = 0
        s = substr(s, q + 7)
        ls = substr(ls, q + 7)
    }
}

# ---------------------------------------------------------------------------
# C4-PATH — keep the org-site render path inside frontdoor/
#
# C4-LINK covers every file on the public CMS tier, so a new stylesheet partial
# under frontdoor/ is guarded the moment it lands. What makes that scope
# SUFFICIENT rather than merely likely is this: nothing the org-site shell
# renders may live outside that directory. So instead of enumerating the
# include graph (which has a dynamic edge — render_blocks.tpl does
# `include $partial`, one file per block type — that no static walk can
# follow), we bound it.
#
# Bases are resolved one variable hop at a time from in-file assignments, which
# is exactly how the templates are written:
#     $fdDir      = DIR_TEMPLATE . 'default/frontdoor/';
#     $fdBlockDir = DIR_TEMPLATE . 'default/frontdoor/blocks/';
#     $partial    = $fdBlockDir . $type . '.tpl';
# ---------------------------------------------------------------------------
function norm_path(p,    n, i, parts, out, k, r) {
    gsub(/\/\/+/, "/", p)
    n = split(p, parts, "/")
    k = 0
    for (i = 1; i <= n; i++) {
        if (parts[i] == "" || parts[i] == ".") continue
        if (parts[i] == "..") { if (k > 0) k--; continue }
        out[++k] = parts[i]
    }
    r = ""
    for (i = 1; i <= k; i++) r = (i == 1) ? out[i] : r "/" out[i]
    return r
}

# Same string with every quoted string's CONTENT replaced by a filler byte, the
# quotes and the length left alone so offsets still line up with the original.
# The `include`/`require` keyword is searched for in this view, so `$msg =
# 'include this'` is prose, not a statement — while the real text is what the
# path resolution below reads its literals out of.
function mask_strings(s,    out, i, n, c, e) {
    out = ""
    i = 1
    n = length(s)
    while (i <= n) {
        c = substr(s, i, 1)
        if (c == "\"" || c == "'") {
            e = str_end(s, i, c)
            if (e > 0) {
                out = out c
                while (++i < e) out = out STRMARK
                out = out c
                i = e + 1
                continue
            }
        }
        out = out c
        i++
    }
    return out
}

# The last quoted string literal on the (already decommented) expression.
function last_literal(s,    i, n, c, e, lit) {
    lit = ""
    i = 1
    n = length(s)
    while (i <= n) {
        c = substr(s, i, 1)
        if (c == "\"" || c == "'") {
            e = str_end(s, i, c)
            if (e > 0) { lit = substr(s, i + 1, e - i - 1); i = e + 1; continue }
        }
        i++
    }
    return lit
}

# Remember what a variable's value starts with, when the resolver can prove it:
# DIR_TEMPLATE . '<literal>', __DIR__ . '<literal>', or another variable already
# tracked.
#
# The DIR_TEMPLATE arm used to be hard-wired to the literal "default/frontdoor/",
# which was enough while C4-PATH was the only caller — it only ever asks whether
# an include stays inside frontdoor/. R10 now asks the same resolver WHICH CMS
# directory a template renders from, and cms/ is one of the two answers, so the
# arm resolves whichever literal it is actually handed.
#
# Only the exact `CONST . '<literal>'` shape is accepted. A base with an
# unproven segment in the middle (DIR_TEMPLATE . $sub . 'x/') stays untracked on
# purpose: half a path is not a path, and both callers are fail-closed.
function track_base(s,    seg, vn, rhs, lit) {
    if (!match(s, /\$[a-zA-Z_][a-zA-Z0-9_]*[ \t]*=[ \t]*[^=]/)) return
    seg = substr(s, RSTART + 1)
    vn = seg
    sub(/[ \t]*=.*$/, "", vn)
    rhs = seg
    sub(/^[^=]*=/, "", rhs)
    # `'a' . 'b'` is one string to PHP, so it is one string here too.
    gsub(/["'][ \t]*\.[ \t]*["']/, "", rhs)
    if (match(rhs, /^[ \t]*DIR_TEMPLATE[ \t]*\.[ \t]*["'][^"']*["'][ \t]*;?[ \t]*$/)) {
        pathbase[vn] = TPL_BASE "/" base_literal(rhs)
        return
    }
    if (match(rhs, /^[ \t]*__DIR__[ \t]*\.[ \t]*["'][^"']*["'][ \t]*;?[ \t]*$/)) {
        pathbase[vn] = filedir "/" base_literal(rhs)
        return
    }
    if (index(rhs, "__DIR__") > 0) { pathbase[vn] = filedir "/"; return }
    if (match(rhs, /\$[a-zA-Z_][a-zA-Z0-9_]*/)) {
        lit = substr(rhs, RSTART + 1, RLENGTH - 1)
        if (lit in pathbase) pathbase[vn] = pathbase[lit]
    }
}

# The single quoted literal out of a `CONST . '<literal>'` right-hand side.
function base_literal(rhs,    lit) {
    lit = rhs
    sub(/^[^"']*["']/, "", lit)
    sub(/["'][^"']*$/, "", lit)
    return lit
}

# THE INCLUDE RESOLVER — one implementation, two callers.
#
# Returns the repo-relative path the include/require on this line of PHP CODE
# lands on, or:
#     ""   the line does not include a template at all
#     "?"  it includes one and the destination cannot be proved
#
# C4-PATH JUDGES the answer ("is this inside frontdoor/?"); R10 reads the same
# answer as EVIDENCE ("which CMS directory does this template render from?").
# Both have to agree about what `include $fdDir . 'render_blocks.tpl';` means,
# and the only way to guarantee that is for there to be one implementation of
# it. R10 used to carry its own — a grep for the literal string "frontdoor/…",
# which no include line in this repo contains, because every one of them is
# written against a $fdDir assigned earlier in the file. So R10 matched nothing
# but the docblocks that happen to mention a partial by path, and added no
# protection at all against the idiom actually in use.
function resolve_include(s,    expr, p, lit, base, vn) {
    if (!match(tolower(mask_strings(s)), /(^|[^a-z0-9_$])(include|require)(_once)?[ \t(]/)) return ""
    expr = substr(s, RSTART + RLENGTH - 1)
    p = index(expr, ";")
    if (p > 0) expr = substr(expr, 1, p - 1)

    lit = last_literal(expr)
    if (lit != "" && lit !~ /\.tpl$/) return ""   # a PHP library, not a template

    base = ""
    if (index(expr, "__DIR__") > 0)           base = filedir "/"
    else if (index(expr, "DIR_TEMPLATE") > 0) base = TPL_BASE "/"
    else if (match(expr, /\$[a-zA-Z_][a-zA-Z0-9_]*/)) {
        vn = substr(expr, RSTART + 1, RLENGTH - 1)
        if (vn in pathbase) base = pathbase[vn]
    }

    if (base == "") return "?"                   # fail closed: cannot prove it
    return norm_path(base "/" lit)
}

function check_include(s,    expr, p, tgt) {
    if (!match(tolower(mask_strings(s)), /(^|[^a-z0-9_$])(include|require)(_once)?[ \t(]/)) return
    expr = substr(s, RSTART + RLENGTH - 1)
    p = index(expr, ";")
    if (p > 0) expr = substr(expr, 1, p - 1)

    if (index(expr, "_assets_inshell") > 0) {
        report("C4", FNR, "The org-site render path includes the in-shell stylesheet partial.", \
               "_assets_inshell.tpl links orkshell-interop.css, which exists only to fight ORK chrome a standalone org site never renders. Only the in-shell surfaces (_index, Page_view, Blog_index, Blog_post, Cms_preview) may include it.")
        return
    }

    tgt = resolve_include(s)
    if (tgt == "") return
    if (tgt == "?") {
        report("C4", FNR, "Cannot prove where this include on the org-site render path lands.", \
               "Everything a standalone org site renders must live under " TPL_ROOT "/frontdoor/, where C4 covers it. Build the path from $fdDir, __DIR__ or DIR_TEMPLATE . 'default/frontdoor/…' so the gate can follow it.")
        return
    }
    if (index(tgt, TPL_ROOT "/frontdoor/") != 1)
        report("C4", FNR, "The org-site render path includes a template outside frontdoor/ (" tgt ").", \
               "Everything a standalone org site renders must live under " TPL_ROOT "/frontdoor/, where C4 covers it. A partial parked elsewhere is a one-line detour around the rule.")
}

# ---------------------------------------------------------------------------
# C4-LINK / C6 — WHICH STYLESHEET A PATH ACTUALLY NAMES
#
# Both rules used to ask "does this line contain the literal `default/style/…css`
# or one of three hardcoded basenames?". Every spelling below is idiomatic in
# this codebase rather than obfuscation, and each one walked straight past:
#
#   HTTP_TEMPLATE . "default/style/" . "orkui.css"     the literal is split
#   HTTP_TEMPLATE . "default/sty" . "le/orkui.css"     …anywhere, including mid-dir
#   default/frontdoor/../style/orkui.css               a `..` hop
#   ../style/orkui.css                                 a relative spelling
#   default/style//orkui.css                           a doubled slash
#   href split across two lines                        the regex is per-line
#   default/style/reports.css                          not one of the three names
#   @import url("../../style/reports.css")             ditto, from a stylesheet
#
# The replacement matches PATH SHAPE, on a resolved path:
#
#   1. PHP is evaluated as far as string values go. Adjacent literals are
#      joined, HTTP_TEMPLATE / DIR_TEMPLATE / __DIR__ are known, and a variable
#      is followed through in-file assignments — including array literals and
#      the `foreach ($fdCssSet as $fdCssFile)` in _assets_public.tpl, whose
#      three values are enumerated and each one classified. A `<link>` tag is
#      accumulated across lines, so a split attribute is one string again.
#   2. The result is normalised (`..` resolved, `//` collapsed, query stripped,
#      scheme+authority removed) and then classified: any path landing in a
#      `style/` directory, or naming one of the CRM stylesheets the shell
#      derived from the filesystem, is CRM CSS however it was spelled.
#   3. FAIL CLOSED. A stylesheet href or @import that still contains an
#      unresolved variable or call is REPORTED — the gate cannot prove where it
#      lands, and "cannot prove" is exactly the state C4-PATH already refuses to
#      accept for an include.
#
# Scope is deliberately narrow, because fail-closed is a false-positive engine
# if it is pointed at everything: only the two vectors that make a browser
# download a stylesheet are examined — a <link> whose rel could be one (rel
# absent, or a rel that is not one of the known non-stylesheet ones) and an
# @import. An <img src>, a <script src> and an <a href> are not stylesheets and
# are left alone, which is what keeps the dynamic hrefs all over the CMS
# templates (and default.theme's own dynamic rel="canonical" / rel="alternate"
# links) from reading as violations.
#
# A stylesheet injected by JavaScript, or echoed straight out of a controller,
# used to be out of reach here: C4 reads templates, and frontdoor/js/ was not
# scanned by this gate at all. R8 + C7 below bring the CMS PHP and JS sources
# into scope for exactly that. What no text scanner can follow is a URL that
# never appears as text in the tree — tests/cms-css/boundary_test.php is the
# backstop that reads what a live surface actually serves.
# ---------------------------------------------------------------------------

# A value SET: SETSEP-separated candidate strings. A candidate that contains UNK
# has a piece the scanner could not resolve.
# Deduplicate, or the six identical `$fdAssetBase = HTTP_TEMPLATE . '…'`
# assignments the tree carries become six identical candidates and the next
# cross product blows the cap for no reason.
function set_dedup(S,    n, a, i, seen, out, m) {
    n = split(S, a, SETSEP)
    if (n <= 1) return S
    out = ""; m = 0
    for (i = 1; i <= n; i++) {
        if (a[i] in seen) continue
        seen[a[i]] = 1
        out = (m++ == 0) ? a[i] : out SETSEP a[i]
    }
    return out
}

function set_cross(A, B,    na, nb, i, j, arrA, arrB, out, n) {
    A = set_dedup(A); B = set_dedup(B)
    na = split(A, arrA, SETSEP); if (na == 0) { na = 1; arrA[1] = "" }
    nb = split(B, arrB, SETSEP); if (nb == 0) { nb = 1; arrB[1] = "" }
    if (na * nb > SETCAP) return UNK
    out = ""; n = 0
    for (i = 1; i <= na; i++)
        for (j = 1; j <= nb; j++)
            out = (n++ == 0) ? arrA[i] arrB[j] : out SETSEP arrA[i] arrB[j]
    return set_dedup(out)
}

function set_union(A, B,    S, tmp) {
    if (A == "") return B
    if (B == "") return A
    S = set_dedup(A SETSEP B)
    if (split(S, tmp, SETSEP) > SETCAP) return UNK
    return S
}

# Split a PHP expression on TOP-LEVEL "." (the concatenation operator), leaving
# quoted strings and parenthesised argument lists intact.
function expr_terms(e, terms,    i, n, c, depth, cur, k, q) {
    n = length(e); depth = 0; cur = ""; k = 0
    for (i = 1; i <= n; i++) {
        c = substr(e, i, 1)
        if (c == "\"" || c == "'") {
            q = str_end(e, i, c)
            if (q > 0) { cur = cur substr(e, i, q - i + 1); i = q; continue }
            cur = cur c
            continue
        }
        if (c == "(" || c == "[") { depth++; cur = cur c; continue }
        if (c == ")" || c == "]") { depth--; cur = cur c; continue }
        if (c == "." && depth == 0) { terms[++k] = cur; cur = ""; continue }
        cur = cur c
    }
    terms[++k] = cur
    return k
}

# The value set of ONE concatenation term.
function term_value(t,    c, q, vn) {
    gsub(/^[ \t@]+/, "", t)
    gsub(/[ \t]+$/, "", t)
    if (t == "") return ""
    c = substr(t, 1, 1)
    if (c == "\"" || c == "'") {
        q = str_end(t, 1, c)
        if (q == length(t)) return substr(t, 2, q - 2)
        return UNK
    }
    # The two constants every asset path in this codebase is built from, plus
    # __DIR__. HTTP_TEMPLATE is a URL prefix and DIR_TEMPLATE a filesystem one,
    # but both land on the same tree, so both resolve to the repo-relative root.
    if (t == "HTTP_TEMPLATE" || t == "DIR_TEMPLATE") return TPL_BASE "/"
    if (t == "__DIR__") return filedir
    if (t ~ /^\$[a-zA-Z_][a-zA-Z0-9_]*$/) {
        vn = substr(t, 2)
        if (vn in vals) return vals[vn]      # what THIS file assigns wins
        if (vn in seed) return seed[vn]      # R7: what the tree assigns it
        return UNK
    }
    return UNK
}

# R7. One `$name = HTTP_TEMPLATE|DIR_TEMPLATE . '…'` line from anywhere in the
# in-scope tree. Only fully provable right-hand sides are kept — a seed the
# scanner had to guess at would be worse than no seed.
function seed_track(s,    vn, rhs, v, p) {
    if (!match(s, /\$[a-zA-Z_][a-zA-Z0-9_]*[ \t]*=[ \t]*(HTTP_TEMPLATE|DIR_TEMPLATE)[ \t]*\./)) return
    vn = substr(s, RSTART + 1)
    sub(/[ \t]*=.*$/, "", vn)
    rhs = substr(s, RSTART)
    sub(/^[^=]*=/, "", rhs)
    p = index(rhs, ";")
    if (p > 0) rhs = substr(rhs, 1, p - 1)
    v = php_value(rhs)
    if (v == "" || index(v, UNK) > 0) return
    seed[vn] = set_union(seed[vn], v)
}

function php_value(e, terms, k, i, out) {
    k = expr_terms(e, terms)
    out = ""
    for (i = 1; i <= k; i++) out = set_cross(out, term_value(terms[i]))
    return out
}

# Every quoted literal in an array literal, as a set. Anything non-literal in
# there (a variable, a call) contributes UNK, so the set stays an
# over-approximation rather than a lie.
function array_value(rhs,    i, n, c, q, out, rest) {
    sub(/^array[ \t]*\(/, "", rhs)
    sub(/^\[/, "", rhs)
    sub(/[ \t]*[)\]][ \t]*$/, "", rhs)
    out = ""; rest = ""
    i = 1; n = length(rhs)
    while (i <= n) {
        c = substr(rhs, i, 1)
        if (c == "\"" || c == "'") {
            q = str_end(rhs, i, c)
            if (q > 0) { out = set_union(out, substr(rhs, i + 1, q - i - 1)); i = q + 1; continue }
        }
        rest = rest c
        i++
    }
    # Whatever was NOT a literal — a variable, a call — is an element the
    # scanner cannot name, so the set stays honest by carrying UNK.
    if (rest ~ /\$/ || rest ~ /[a-zA-Z_][a-zA-Z0-9_]*[ \t]*\(/) out = set_union(out, UNK)
    return (out == "") ? UNK : out
}

# Follow string-valued variables through in-file assignments. Assignment UNIONS
# rather than replaces: a variable written on both sides of an if/else holds
# either value, and over-approximating is the safe direction for a gate.
function track_vals(code,    seg, vn, rhs, p) {
    # foreach ($set as [$k =>] $v) — the loop variable takes every value in the set.
    if (match(code, /foreach[ \t]*\([ \t]*\$[a-zA-Z_][a-zA-Z0-9_]*[ \t]+as[ \t]+/)) {
        seg = substr(code, RSTART, RLENGTH)
        sub(/^foreach[ \t]*\([ \t]*\$/, "", seg)
        sub(/[ \t]+as[ \t]+$/, "", seg)
        rhs = substr(code, RSTART + RLENGTH)
        sub(/^\$[a-zA-Z_][a-zA-Z0-9_]*[ \t]*=>[ \t]*/, "", rhs)
        if (match(rhs, /^\$[a-zA-Z_][a-zA-Z0-9_]*/)) {
            vn = substr(rhs, RSTART + 1, RLENGTH - 1)
            vals[vn] = set_union(vals[vn], (seg in vals) ? vals[seg] : UNK)
        }
        return
    }
    # $x[] = expr;  and  $x = expr;
    if (match(code, /\$[a-zA-Z_][a-zA-Z0-9_]*[ \t]*(\[[ \t]*\])?[ \t]*=[ \t]*[^=]/)) {
        seg = substr(code, RSTART + 1)
        vn = seg
        sub(/[ \t]*(\[[ \t]*\])?[ \t]*=.*$/, "", vn)
        rhs = seg
        sub(/^[^=]*=/, "", rhs)
        p = index(rhs, ";")
        if (p > 0) rhs = substr(rhs, 1, p - 1)
        gsub(/^[ \t]+|[ \t]+$/, "", rhs)
        if (rhs ~ /^array[ \t]*\(/ || rhs ~ /^\[/) vals[vn] = set_union(vals[vn], array_value(rhs))
        else                                       vals[vn] = set_union(vals[vn], php_value(rhs))
    }
}

# Build the MARKUP VIEW of a line: every PHP region replaced by a token that
# stands for the string it echoes, so attribute parsing is not confused by the
# quotes inside `<?= HTTP_TEMPLATE . "default/style/" . "orkui.css" ?>`. The PHP
# code itself is handed to track_vals. State is carried across lines, because a
# PHP region (and a <link> tag) may span them.
function mview_build(s,    out, p, q, code, val) {
    out = ""
    while (length(s) > 0) {
        if (!mv_open) {
            p = index(s, "<?")
            if (p == 0) { out = out s; break }
            out = out substr(s, 1, p - 1)
            mv_open = 1
            mv_expr = ""
            s = substr(s, p + 2)
            if (substr(s, 1, 3) == "php")    { mv_echo = 0; s = substr(s, 4) }
            else if (substr(s, 1, 1) == "=") { mv_echo = 1; s = substr(s, 2) }
            else                             { mv_echo = 0 }
            continue
        }
        q = index(s, "?>")
        if (q == 0) { mv_expr = mv_expr " " s; track_vals(s); break }
        code = substr(s, 1, q - 1)
        mv_expr = mv_expr " " code
        track_vals(code)
        mv_open = 0
        phpn++
        phpval[phpn] = region_value(mv_expr)
        out = out TOK phpn TOK
        s = substr(s, q + 2)
    }
    return out
}

# What a closed PHP region ECHOES. `<?= expr ?>` echoes expr; `<?php … ?>`
# echoes only through echo/print; anything else (an if:, a foreach:, an
# assignment) contributes nothing to the markup.
function region_value(e,    t, p) {
    gsub(/^[ \t]+|[ \t]+$/, "", e)
    t = e
    if (mv_echo) {
        sub(/;[ \t]*$/, "", t)
        return php_value(t)
    }
    if (match(t, /(^|;)[ \t]*(echo|print)[ \t]/)) {
        t = substr(t, RSTART + RLENGTH)
        p = index(t, ";")
        if (p > 0) t = substr(t, 1, p - 1)
        return php_value(t)
    }
    return ""
}

# Expand a markup string containing region tokens into its candidate values.
function expand_tokens(v,    out, lit, i, n, c, j, k) {
    out = ""; lit = ""
    i = 1; n = length(v)
    while (i <= n) {
        c = substr(v, i, 1)
        if (c == TOK) {
            j = index(substr(v, i + 1), TOK)
            if (j == 0) { lit = lit UNK; i++; continue }
            k = substr(v, i + 1, j - 1) + 0
            out = set_cross(set_cross(out, lit), (k in phpval) ? phpval[k] : UNK)
            lit = ""
            i = i + j + 1
            continue
        }
        lit = lit c
        i++
    }
    return set_cross(out, lit)
}

# One attribute's raw value (tokens included), or NOATTR.
function attr_value(buf, name,    lb, rest, c, e, v) {
    lb = tolower(buf)
    if (!match(lb, "(^|[ \t/\"'])" name "[ \t]*=[ \t]*")) return NOATTR
    rest = substr(buf, RSTART + RLENGTH)
    c = substr(rest, 1, 1)
    if (c == "\"" || c == "'") {
        e = index(substr(rest, 2), c)
        return (e == 0) ? substr(rest, 2) : substr(rest, 2, e - 1)
    }
    v = rest
    sub(/[ \t>].*$/, "", v)
    return v
}

# Classify every candidate a stylesheet URL can resolve to.
#   allowed=1  this position is provably unreachable by a standalone org site
#              (C6's `if (empty($IsOrgSite))` branch), so nothing is reported.
function classify_url(v, rule, ctx, lineno, allowed,    set, n, arr, i, u, path, norm, bn) {
    set = expand_tokens(v)
    n = split(set, arr, SETSEP)
    for (i = 1; i <= n; i++) {
        u = arr[i]
        path = u
        sub(/[?#].*$/, "", path)
        gsub(/[ \t]/, "", path)
        if (path == "") continue
        if (index(path, UNK) > 0) {
            if (!allowed) report_unprovable(rule, lineno, ctx)
            continue
        }
        if (path ~ /^[a-z][a-z0-9+.-]*:\/\//) {
            sub(/^[a-z][a-z0-9+.-]*:\/\//, "", path)
            sub(/^[^\/]*/, "", path)
        } else if (path ~ /^\/\//) {
            sub(/^\/\//, "", path)
            sub(/^[^\/]*/, "", path)
        } else if (!tpl && path !~ /^\// && index(path, TPL_BASE "/") != 1) {
            # An @import inside a stylesheet resolves against that stylesheet's
            # own directory, which is knowable. A URL in a template resolves
            # against the request URL, which is not — the shape test below
            # covers it either way.
            path = filedir "/" path
        }
        norm = norm_path(path)
        bn = norm
        sub(/^.*\//, "", bn)
        if (norm ~ /(^|\/)style\/.*\.css$/ || (bn in crmsheet)) {
            if (!allowed) report_crm(rule, lineno)
        }
    }
}

# The COARSE net: a CRM stylesheet NAME or a `style/<…>.css` path shape anywhere
# on the line, after adjacent string literals have been joined. It catches the
# spellings that never reach a <link>/@import at all — a name assembled into a
# PHP variable, a path built in JS — where the resolver has nothing to resolve.
function crm_mentioned(j) {
    if (CRM_NAME_RE != "" && j ~ CRM_NAME_RE) return 1
    return (j ~ /(^|[^a-z0-9_-])style\/[a-z0-9_.\/-]*\.css/)
}

function report_crm(rule, lineno) {
    if ((rule ":" lineno) in crm_done) return
    crm_done[rule ":" lineno] = 1
    if (rule == "C6") report("C6", lineno, "default.theme links CRM CSS on a path a standalone org site can reach.", C6_FIX)
    else              report("C4", lineno, "Public CMS markup links a stylesheet a standalone org site must not load.", C4_FIX)
}

function report_unprovable(rule, lineno, ctx) {
    if ((rule ":" lineno) in unp_done) return
    unp_done[rule ":" lineno] = 1
    report(rule, lineno, "Cannot prove where this stylesheet " ctx " lands.", \
           "A stylesheet path built from an unresolvable variable or call could name any file, orkui.css included, so the gate cannot vouch for it. Build it from a literal, or from a variable this file assigns (HTTP_TEMPLATE / DIR_TEMPLATE / __DIR__ / an array of names), the way _assets_public.tpl does.")
}

# @import, in a stylesheet or inside a template's <style>.
function scan_imports(mv, rule, allowed,    lmv, p, rest, c, e, v) {
    lmv = tolower(mv)
    while ((p = index(lmv, "@import")) > 0) {
        rest = substr(mv, p + 7)
        mv   = rest
        lmv  = tolower(rest)
        sub(/^[ \t]+/, "", rest)
        if (tolower(substr(rest, 1, 4)) == "url(") {
            rest = substr(rest, 5)
            sub(/^[ \t]+/, "", rest)
        }
        c = substr(rest, 1, 1)
        if (c == "\"" || c == "'") {
            e = index(substr(rest, 2), c)
            v = (e == 0) ? substr(rest, 2) : substr(rest, 2, e - 1)
        } else {
            v = rest
            sub(/[ \t;)].*$/, "", v)
        }
        if (v != "") classify_url(v, rule, "@import", FNR, allowed)
    }
}

# <link> tags, accumulated across lines so a split attribute is one string.
function analyze_link(buf, rule,    rel, parts, i, n, v) {
    rel = attr_value(buf, "rel")
    if (rel != NOATTR && index(rel, TOK) == 0) {
        n = split(tolower(rel), parts, /[ \t]+/)
        for (i = 1; i <= n; i++)
            if (parts[i] in noncss_rel) return
    }
    v = attr_value(buf, "href")
    if (v == NOATTR || v == "") return
    classify_url(v, rule, "href", lk_line, lk_allowed)
}

function scan_sheets(mv, rule, allowed,    lmv, p, q) {
    scan_imports(mv, rule, allowed)
    lmv = tolower(mv)
    while (1) {
        if (!lk_open) {
            p = index(lmv, "<link")
            if (p == 0) return
            lk_open = 1; lk_buf = ""; lk_line = FNR; lk_rule = rule; lk_allowed = allowed
            mv = substr(mv, p + 5); lmv = substr(lmv, p + 5)
            continue
        }
        q = index(lmv, ">")
        if (q == 0) {
            lk_buf = lk_buf " " mv
            if (length(lk_buf) > 4000) { analyze_link(lk_buf, lk_rule); lk_open = 0 }
            return
        }
        lk_buf = lk_buf " " substr(mv, 1, q - 1)
        analyze_link(lk_buf, lk_rule)
        lk_open = 0
        mv = substr(mv, q + 1); lmv = substr(lmv, q + 1)
    }
}

# ---------------------------------------------------------------------------
# C6 — $IsOrgSite branch awareness in default.theme
# ---------------------------------------------------------------------------

# Pull the PHP-code portions out of a (already decommented) template line, so
# the control-structure tracker never trips over an `if (` in JavaScript or a
# brace in CSS.
function php_extract(s,    out, p, q) {
    out = ""
    while (length(s) > 0) {
        if (!php_open) {
            p = index(s, "<?")
            if (p == 0) return out
            php_open = 1
            s = substr(s, p + 2)
            if (substr(s, 1, 3) == "php")    s = substr(s, 4)
            else if (substr(s, 1, 1) == "=") s = substr(s, 2)
            continue
        }
        q = index(s, "?>")
        if (q == 0) return out " " s
        out = out " " substr(s, 1, q - 1)
        php_open = 0
        s = substr(s, q + 2)
    }
    return out
}

# What a condition being TRUE says about $IsOrgSite.
#   "T" org site, "F" not an org site, "?" unknown.
# An || anywhere makes the whole condition uninformative.
function cond_state(c) {
    if (index(c, "||") > 0) return "?"
    if (c ~ /![ \t]*empty[ \t]*\([ \t]*\$IsOrgSite[ \t]*\)/) return "T"
    if (c ~ /empty[ \t]*\([ \t]*\$IsOrgSite[ \t]*\)/) return "F"
    return "?"
}

function invert(st) {
    if (st == "T") return "F"
    if (st == "F") return "T"
    return "?"
}

# Innermost branch that says something definite about $IsOrgSite.
function org_effective(    d) {
    for (d = orgdepth; d >= 1; d--)
        if (orgst[d] != "?") return orgst[d]
    return "?"
}

# Track PHP alternative-syntax if/elseif/else/endif nesting. Brace-style blocks
# are transparent: they are identified by a trailing "{" and never open an
# endif-terminated frame, so they cannot corrupt the stack.
function org_track(code,    t, k, ne, cond, st) {
    t = code
    sub(/[ \t]+$/, "", t)
    if (t ~ /^[ \t]*$/) return

    k = t
    ne = gsub(/endif/, "", k)
    while (ne-- > 0) {
        if (orgdepth > 0) { orgdepth-- } else { org_broken = 1 }
    }

    if (t ~ /(^|[^a-zA-Z0-9_$])else[ \t]*:/) {
        if (orgdepth > 0) orgst[orgdepth] = orgelse[orgdepth]
        else org_broken = 1
        return
    }
    if (t ~ /(^|[^a-zA-Z0-9_$])elseif[ \t]*\(/ && t ~ /:[ \t]*$/) {
        if (orgdepth > 0) {
            cond = t
            sub(/^.*elseif[ \t]*\(/, "", cond)
            orgst[orgdepth] = cond_state(cond)
            orgelse[orgdepth] = "?"
        } else {
            org_broken = 1
        }
        return
    }
    if (t ~ /(^|[^a-zA-Z0-9_$])if[ \t]*\(/ && t ~ /:[ \t]*$/) {
        cond = t
        sub(/^.*[^a-zA-Z0-9_$]if[ \t]*\(/, "", cond)
        sub(/^if[ \t]*\(/, "", cond)
        st = cond_state(cond)
        orgdepth++
        orgst[orgdepth] = st
        # Only a single-term condition lets the ELSE branch be inferred.
        if (index(cond, "&&") == 0 && index(cond, "||") == 0) orgelse[orgdepth] = invert(st)
        else orgelse[orgdepth] = "?"
    }
}

# ---------------------------------------------------------------------------
# C7 — CSS injection from a CMS PHP source or a CMS script (R8)
#
# The rule is flat: none of these files may put CSS on a page, on either tier.
# See the C7 entry in the header for why that needs no exemption list — the
# trigger set is chosen so the CSS-as-DATA classes (CmsThemeTokens builds css
# text, CmsSanitizer strips style/link tags, CmsTheme passes css around) never
# touch it. Tag OPENERS, not tag NAMES; the --ork-* namespace, not --fd-*.
# ---------------------------------------------------------------------------

# Is this <link> one a browser could fetch a stylesheet from?
#
# Deliberately does NOT resolve the href. For these files EVERY stylesheet link
# is a violation whatever it points at, so there is nothing to resolve — and
# that is what lets the test survive concatenation. `'<link rel="stylesheet"
# href="' . $u . '">'` has its attribute values shredded by the PHP string
# quotes, so no scanner can read the href out of it; the attribute is still
# demonstrably PRESENT, and present is all C7 needs.
#
# Two things keep it honest rather than noisy:
#   href absent  -> not a stylesheet link. class.CmsPost.php writes the RSS
#                   <link> ELEMENT, whose URL is its TEXT CONTENT, and passes.
#   rel known    -> a rel that cannot load a stylesheet (icon, canonical,
#                   alternate, …) is skipped, sharing C4's list. An UNPROVABLE
#                   rel is analysed, because it could be "stylesheet".
function c7_link_is_sheet(buf,    rel, parts, i, n) {
    if (attr_value(buf, "href") == NOATTR) return 0
    rel = attr_value(buf, "rel")
    if (rel != NOATTR && index(rel, TOK) == 0) {
        n = split(tolower(rel), parts, /[ \t]+/)
        for (i = 1; i <= n; i++)
            if (parts[i] in noncss_rel) return 0
    }
    return 1
}

function c7_link_flush() {
    if (c7_lk_open && c7_link_is_sheet(c7_lk_buf))
        report("C7", c7_lk_line, "CMS PHP/JS emits a stylesheet <link>.", C7_LINK_FIX)
    c7_lk_open = 0
    c7_lk_buf = ""
}

# <link> tags, accumulated across lines so a tag built over several
# concatenated fragments is one string again.
function c7_links(s,    ls, p, q) {
    ls = tolower(s)
    while (1) {
        if (!c7_lk_open) {
            p = index(ls, "<link")
            if (p == 0) return
            c7_lk_open = 1; c7_lk_buf = ""; c7_lk_line = FNR
            s = substr(s, p + 5); ls = substr(ls, p + 5)
            continue
        }
        q = index(ls, ">")
        if (q == 0) {
            c7_lk_buf = c7_lk_buf " " s
            if (length(c7_lk_buf) > 4000) c7_link_flush()
            return
        }
        c7_lk_buf = c7_lk_buf " " substr(s, 1, q - 1)
        c7_link_flush()
        s = substr(s, q + 1); ls = substr(ls, q + 1)
    }
}

BEGIN {
    PHPMARK = sprintf("%c", 1)
    STRMARK = sprintf("%c", 2)
    UNK     = sprintf("%c", 3)      # "this piece could not be resolved"
    TOK     = sprintf("%c", 4)      # brackets a PHP region's index in the markup view
    SETSEP  = sprintf("%c", 5)      # separates candidate values in a value set
    NOATTR  = sprintf("%c", 6)      # attribute absent (distinct from present-and-empty)
    SETCAP  = 16                    # candidate explosion guard: beyond this, unprovable

    # rel values that CANNOT load a stylesheet. Everything else — rel absent,
    # rel="stylesheet", rel="preload", an unknown rel, a rel built in PHP — is
    # analysed. Fail-closed on the rel, narrow on the tag.
    split("icon shortcut apple-touch-icon mask-icon manifest canonical alternate " \
          "preconnect dns-prefetch author license help search me next prev " \
          "pingback profile amphtml modulepreload image_src publisher", relarr, " ")
    for (ri in relarr) noncss_rel[relarr[ri]] = 1

    # R6: the CRM stylesheet basenames the shell derived from the filesystem.
    n = split(CRM_SHEETS, sharr, /[ \t]+/)
    CRM_NAME_RE = ""
    for (si = 1; si <= n; si++) {
        if (sharr[si] == "") continue
        crmsheet[tolower(sharr[si])] = 1
        esc = tolower(sharr[si])
        gsub(/\./, "\\.", esc)
        CRM_NAME_RE = (CRM_NAME_RE == "") ? esc : CRM_NAME_RE "|" esc
    }
    if (CRM_NAME_RE != "") CRM_NAME_RE = "(^|[^a-z0-9_-])(" CRM_NAME_RE ")"

    # R7: asset-base seeds the shell collected from the whole in-scope tree.
    if (SEEDFILE != "") {
        while ((getline seedline < SEEDFILE) > 0) seed_track(seedline)
        close(SEEDFILE)
    }

    C3_MSG = "Static inline <style> block in a CMS template."
    C3_FIX = "This CSS belongs in " C3_DEST " so it is cacheable, lintable and visible to duplication analysis, instead of being re-sent in the HTML of every render. Only a <style> that interpolates a PHP variable into a declaration value (blocks/columns.tpl) may stay inline."
    C6_FIX = "Standalone public org sites must not download CRM application CSS. Link it only inside the `if (empty($IsOrgSite)):` branch of default.theme; org sites get frontdoor/css/cms-base.css instead."
    C7_WHERE = "CSS reaches a CMS page through three sanctioned channels only: frontdoor/_assets_public.tpl and frontdoor/_assets_inshell.tpl for the public tier, cms/_shell_top.tpl for the admin, and default.theme's $IsOrgSite gate. It LIVES in a stylesheet under frontdoor/css/ or cms/css/."
    C7_STYLE_FIX = "A controller, model, domain class or script must not put a <style> element on the page — it is re-sent in the HTML of every render, invisible to stylelint and to the duplication ratchet, and it is exactly the injection route this gate was blind to. " C7_WHERE " The one <style> the CMS legitimately emits is default.theme's theme-token block, whose CSS text CmsThemeTokens builds."
    C7_LINK_FIX = "A controller, model, domain class or script must not link a stylesheet. " C7_WHERE
    C7_IMPORT_FIX = "@import from a CMS PHP source or script injects a stylesheet the gate cannot account for, serially after the linking stylesheet has parsed. " C7_WHERE
    C7_SHELL_FIX = "Naming an ORK application-shell selector from CMS code couples the CMS to the ORK chrome a standalone org site never renders, where the hook styles nothing. Give the element an .fd-/.cms-/.org- class, and put any genuine coupling in frontdoor/css/orkshell-interop.css, which is the designated place for it."
    C7_TOKEN_FIX = "Rename it to --cms-* or --fd-* and scope it to the CMS root. Reading an --ork-* token is fine; defining one — with a declaration, with @property, or with element.style.setProperty() — writes into the CRM's namespace."
    C7_CRM_FIX = "A CRM stylesheet name or a style/<…>.css path built inside CMS code is a stylesheet link one step before it becomes one. " C7_WHERE
    C4_FIX = "Org sites load cms-base.css instead of the CRM stylesheets, and need no ORK-shell interop layer. The in-shell surfaces get the interop layer from frontdoor/_assets_inshell.tpl, which is the only file allowed to link it."
}
# ---------------------------------------------------------------------------
# R10 — CLASSIFY MODE: "does this $TPL_ROOT template render CMS chrome?"
#
# Same program, same lexer, same resolver, a different question. The shell runs
# this over $TPL_ROOT/*.tpl in ONE process (-v CLASSIFY=1, the templates in
# ARGV) and reads back one "PUBLIC<TAB>path" / "ADMIN<TAB>path" line per
# template that renders from frontdoor/ or cms/.
#
# It lives here, inside the scanner, rather than as a grep in the shell,
# because the question it asks IS resolve_include()'s question — and a second
# implementation of that drifts from the first. It had: the shell grepped for
# the literal string "frontdoor/<name>.tpl", while every CMS template in the
# repo writes
#
#     $fdDir = DIR_TEMPLATE . 'default/frontdoor/';
#     include $fdDir . '_assets_public.tpl';
#
# so the literal never appears on an include line and the only thing R10 ever
# matched was the DOCBLOCKS that mention a partial by path.
#
# EVIDENCE IS CODE, NOT PROSE: the line is decommented before either net reads
# it, so a template that merely documents frontdoor/render_blocks.tpl in a
# comment is no longer classified by it.
# ---------------------------------------------------------------------------
function cls_emit(tier) {
    if (tier == "PUBLIC") { if (cls_pub) return; cls_pub = 1 }
    else                  { if (cls_adm) return; cls_adm = 1 }
    print tier "\t" FILENAME
}

CLASSIFY && FNR == 1 {
    # Per-file state: one process reads every template, so the lexer's
    # carry-over flags and the resolver's variable table must be reset.
    split("", pathbase)
    in_block = 0; in_html = 0; php_open = 0
    cls_pub = 0; cls_adm = 0
    filedir = FILENAME
    sub(/\/[^\/]*$/, "", filedir)
}

CLASSIFY {
    craw = $0
    gsub(/\r/, "", craw)
    cline = decomment(craw)

    # Half one: an include destination, resolved. This is the half that was
    # missing, and it is the half every CMS template in this repo actually uses.
    cphp = php_extract(cline)
    track_base(cphp)
    ctgt = resolve_include(cphp)
    if (ctgt != "" && ctgt != "?") {
        if (index(ctgt, TPL_ROOT "/frontdoor/") == 1)    cls_emit("PUBLIC")
        else if (index(ctgt, TPL_ROOT "/cms/") == 1)     cls_emit("ADMIN")
    }

    # Half two, unchanged in substance: a stylesheet or asset path naming a CMS
    # directory outright — a <link href> built from
    # HTTP_TEMPLATE . 'default/frontdoor/css/…', say. Read on the decommented,
    # concatenation-joined line, so the two halves see the same code-only view.
    cjoin = tolower(cline)
    gsub(/["'][ \t]*\.[ \t]*["']/, "", cjoin)
    if (cjoin ~ /(default\/)?frontdoor\/[a-z0-9_.\/-]+\.(tpl|css|js)/) cls_emit("PUBLIC")
    if (cjoin ~ /(default\/)?cms\/[a-z0-9_.\/-]+\.(tpl|css|js)/)       cls_emit("ADMIN")
    next
}
{
    # CR normalisation, before anything else looks at the text. A file with
    # CRLF endings leaves a \r as the last character of every line, and \r
    # matches none of the [ \t]*$ / ^[ \t]*$ anchors these rules are built on —
    # which is how `--ork-probe` with its colon wrapped onto the next line used
    # to pass on a CRLF file and fail on the identical LF file.
    raw = $0
    gsub(/\r/, "", raw)

    line = decomment(raw)
    mline = css_unescape(line)

    # Two case-folded views, because the whole scanner runs under LC_ALL=C and
    # matches lowercase literals, and none of these couplings are only
    # writable in lowercase:
    #   lraw   decommented, folded          — HTML tag names (<STYLE>)
    #   lline  + CSS escapes decoded, folded — selectors, attributes, tokens
    # HTML tag and attribute names are case-insensitive, so <Style> and
    # ID="theme_container" are ordinary markup a developer could write without
    # trying to evade anything. CSS custom properties ARE case-sensitive, so
    # --ork-Brand is technically a different token from --ork-brand — but it is
    # still CMS code defining into the CRM's namespace, which is what C2 is
    # about, so it is folded too.
    lraw  = tolower(line)
    lline = tolower(mline)

    if (C1) {
        if (C1_TC) {
            if (lline ~ /(#newmenu|\.ork-)/ ||
                attr_sel_id(lline, "newmenu") || attr_sel_class(lline, "ork-"))
                report("C1", FNR, "CMS CSS names an ORK application-shell selector.", C1_FIX_BASE)
        } else if (lline ~ /(#theme_container|#newmenu|\.ork-)/ ||
                   attr_sel_id(lline, "theme_container") || attr_sel_id(lline, "newmenu") ||
                   attr_sel_class(lline, "ork-")) {
            report("C1", FNR, "CMS CSS names an ORK application-shell selector.", C1_FIX)
        }
        # Markup, not just stylesheet selectors: a public CMS template that
        # hangs ORK ids/classes on its own DOM is coupled just as hard.
        if (tpl && !C1_TC &&
            (lline ~ /id[ \t]*=[ \t]*["']?theme_container/ ||
             lline ~ /class[ \t]*=[ \t]*"[ \t]*ork-/ ||
             lline ~ /class[ \t]*=[ \t]*'[ \t]*ork-/ ||
             lline ~ /class[ \t]*=[ \t]*"[^"]*[ \t]ork-/ ||
             lline ~ /class[ \t]*=[ \t]*'[^']*[ \t]ork-/))
            report("C1", FNR, "CMS markup carries an ORK application-shell id/class.", \
                   "Standalone org sites load no orkui.css, so the hook styles nothing there and couples the layers everywhere else. Give the element a .fd-/.cms-/.org- class instead.")
    }

    # C2: DEFINING an --ork-* token. `var(--ork-x)` is a read and is fine; a
    # bare `--ork-x:` at the start of a declaration is a write. The colon is
    # allowed to have been wrapped onto the next line — postcss parses that as
    # a real declaration, and formatters produce it.
    if (C2) {
        # @property registers a custom property without ever writing an
        # `--ork-x:` declaration, so C2's declaration pattern cannot see it —
        # but `@property --ork-brand { initial-value: red }` defines into the
        # CRM namespace exactly like the declaration form does.
        if (lline ~ /@property[ \t]+--ork-/) {
            report("C2", FNR, "CMS CSS registers a token in the CRM's --ork-* namespace with @property.", C2_FIX)
            ork_pend = 0
        # A quote is a declaration boundary too: <div style="--ork-card-bg:#f00">
        # is a definition, and before the quote joined this character class only
        # a SECOND declaration in the same attribute — the one with a ';' in
        # front of it — was caught.
        } else if (lline ~ /(^|[;{ \t"'])--ork-[a-z0-9-]+[ \t]*:/) {
            report("C2", FNR, "CMS CSS defines a token in the CRM's --ork-* namespace.", C2_FIX)
            ork_pend = 0
        } else if (ork_pend && lline ~ /^[ \t]*:/) {
            report("C2", ork_pend_line, "CMS CSS defines a token in the CRM's --ork-* namespace (colon wrapped onto the next line).", C2_FIX)
            ork_pend = 0
        } else if (lline ~ /^[ \t]*$/) {
            # blank line: keep any pending wrap alive
        } else if (lline ~ /(^|[;{ \t"'])--ork-[a-z0-9-]+[ \t]*$/) {
            ork_pend = 1
            ork_pend_line = FNR
        } else {
            ork_pend = 0
        }
    }

    if (C3) {
        # A style element that spans lines: the newline is a token separator,
        # so feed one before this line's text joins the previous line's.
        if (in_style) decl_feed(" ")
        scan_style(line, lraw)
        # PHP that echoes a <style> tag assembled from string fragments —
        # '<st' . 'yle>' — never reaches scan_style as a literal tag. The same
        # escape spellings C7 decodes work here too (`echo "\x3cstyle\x3e"`),
        # so decode before joining. This is confined to lines that contain a
        # PHP open tag, which is why applying a PHP/JS escape rule cannot
        # misread a CSS escape: there is no CSS on this branch.
        if (index(line, "<?") > 0 && lraw !~ /<style/) {
            joined = fcc_decode(esc_decode(lraw))
            gsub(/["'][ \t]*\.[ \t]*["']/, "", joined)
            if (joined ~ /<[ \t]*style/)
                report("C3", FNR, "PHP emits a <style> tag assembled from string fragments.", C3_FIX)
        }
    }

    # The concatenation-joined view: `'default/sty' . 'le/orkui.css'` and
    # `'orkui' . '.css'` are one string to PHP, so they are one string here too
    # before the name/shape nets look at the line.
    jline = lraw
    gsub(/["'][ \t]*\.[ \t]*["']/, "", jline)

    if (C4) {
        if (crm_mentioned(jline)) report_crm("C4", FNR)
        scan_sheets(mview_build(line), "C4", 0)
    }

    # C4-PATH reads PHP CODE ONLY. Body text ("include the map below") and
    # string contents are not statements, and treating them as one would turn
    # ordinary prose into a boundary violation.
    if (C4_PATH) {
        phpcode = php_extract(line)
        track_base(phpcode)
        check_include(phpcode)
    }

    if (C5 && (lline ~ /(\.fd-|\.cms-|\.org-)/ ||
               attr_sel_class(lline, "fd-") || attr_sel_class(lline, "cms-") ||
               attr_sel_class(lline, "org-")))
        report("C5", FNR, "CRM CSS names a CMS selector.", \
               "The CRM must not style CMS surfaces. Move the rule into the matching CMS stylesheet under frontdoor/css/ or cms/css/.")

    # C7 — R8. A CMS PHP source or CMS script may not inject CSS into a page.
    if (C7) {
        # Decode the escape forms that spell a tag without ever writing a "<"
        # (\x3c, <, String.fromCharCode(60)) BEFORE anything else looks at
        # the line, then join concatenations on the decoded view — the two have
        # to compose, because fromCharCode(60) + 'sty' + 'le' needs both passes.
        # PHP joins with ".", JS with "+", so '<sty' . 'le>' and '<sty' + 'le>'
        # are one string here before the tag nets look at the line.
        d7line = fcc_decode(esc_decode(lraw))
        # css_unescape on top, for the selector and token nets below, which read
        # CSS syntax rather than tag syntax.
        l7line = css_unescape(d7line)
        j7line = d7line
        gsub(/["'][ \t]*[.+][ \t]*["']/, "", j7line)

        # The tag OPENER "<style", never the tag NAME "style": CmsSanitizer's
        # blocklist is array('script', 'style', … ) and must stay clean.
        if (d7line ~ /<[ \t]*style/ || j7line ~ /<[ \t]*style/)
            report("C7", FNR, "CMS PHP/JS emits a <style> tag.", C7_STYLE_FIX)
        # The DOM spellings that build the same element without ever writing the
        # tag. createElement('div') and el.style are untouched — the name must
        # be the style or link element itself.
        else if (j7line ~ /createelement[ \t]*\([ \t]*["'`][ \t]*(style|link)[ \t]*["'`]/ ||
                 j7line ~ /(insertrule|adoptedstylesheets)/ ||
                 j7line ~ /new[ \t]+cssstylesheet/)
            report("C7", FNR, "CMS PHP/JS builds a stylesheet in the DOM.", C7_STYLE_FIX)

        if (d7line ~ /@import/)
            report("C7", FNR, "CMS PHP/JS emits an @import.", C7_IMPORT_FIX)

        # The decoded view here too, so \x3clink … and its fromCharCode spelling
        # reach the <link> analyser. It is already case-folded, which the
        # analyser wants anyway (rel and href are case-insensitive).
        c7_links(d7line)

        # The COARSE net, shared with C4/C6: a CRM stylesheet name or a
        # style/<…>.css path shape assembled anywhere in the file, caught one
        # step before it reaches a tag.
        if (crm_mentioned(j7line))
            report("C7", FNR, "CMS PHP/JS names a CRM stylesheet or a style/ path.", C7_CRM_FIX)

        # C1's coupling, in the files C1 cannot read — as a SELECTOR, as the
        # MARKUP C1 also reads (a controller echoing <div class="ork-card"> is
        # coupled exactly as hard as a template writing it), and in the two DOM
        # spellings that reach the same elements without any CSS syntax at all.
        if (l7line ~ /(#theme_container|#newmenu|\.ork-)/ ||
            attr_sel_id(l7line, "theme_container") || attr_sel_id(l7line, "newmenu") ||
            attr_sel_class(l7line, "ork-") ||
            l7line ~ /id[ \t]*=[ \t]*["']?theme_container/ ||
            l7line ~ /class[ \t]*=[ \t]*"[ \t]*ork-/ ||
            l7line ~ /class[ \t]*=[ \t]*'[ \t]*ork-/ ||
            l7line ~ /class[ \t]*=[ \t]*"[^"]*[ \t]ork-/ ||
            l7line ~ /class[ \t]*=[ \t]*'[^']*[ \t]ork-/ ||
            l7line ~ /classname[ \t]*=[ \t]*["'`][ \t]*ork-/ ||
            l7line ~ /classlist[ \t]*\.[ \t]*[a-z]+[ \t]*\([ \t]*["'`][ \t]*ork-/ ||
            l7line ~ /getelementbyid[ \t]*\([ \t]*["'`][ \t]*(theme_container|newmenu)[ \t]*["'`]/)
            report("C7", FNR, "CMS PHP/JS names an ORK application-shell selector.", C7_SHELL_FIX)

        # C2's namespace, plus the JS spelling of a definition that C2 has no
        # reason to know about. --fd-* and --cms-* are the CMS's own namespaces
        # and are never touched, which is why CmsThemeTokens passes on merit.
        if (l7line ~ /@property[ \t]+--ork-/ ||
            l7line ~ /(^|[;{(, \t"'`])--ork-[a-z0-9-]+[ \t]*:/ ||
            l7line ~ /setproperty[ \t]*\([ \t]*["'`][ \t]*--ork-/)
            report("C7", FNR, "CMS PHP/JS defines a token in the CRM's --ork-* namespace.", C7_TOKEN_FIX)
    }

    if (C6) {
        # org_track deliberately gets the UNFOLDED code: $IsOrgSite is a PHP
        # variable name and PHP variable names are case-sensitive.
        org_track(php_extract(line))
        # allowed = this line sits on a branch where $IsOrgSite is provably
        # falsy, i.e. the one branch that MUST link the CRM stylesheets.
        c6allowed = (org_effective() == "F")
        if (!c6allowed && crm_mentioned(jline)) report_crm("C6", FNR)
        scan_sheets(mview_build(line), "C6", c6allowed)
    }
}
END {
    # CLASSIFY runs answer a question; they never report and never fail.
    if (CLASSIFY) exit 0

    # An unterminated <style> is still an inline block, and its content still
    # ships. Judge it on the same two tests.
    if (C3 && in_style) style_close()

    # A <link> tag left open at EOF is still a link. Judge what we have rather
    # than letting an unclosed tag be a place to park an href.
    if (lk_open) analyze_link(lk_buf, lk_rule)
    if (c7_lk_open) c7_link_flush()

    # C0. A comment opener that never closes leaves everything after it
    # unscanned. Failing loudly beats a gate that silently disarms itself.
    if (in_block)
        report("C0", FNR, "could not parse " file ": /* comment is never closed.", \
               "Everything after the opener goes unscanned, so the gate cannot vouch for this file. Close the comment (or, if the opener is inside a string that spans lines, keep the string on one line).")
    if (in_html)
        report("C0", FNR, "could not parse " file ": <!-- comment is never closed.", \
               "Everything after the opener goes unscanned, so the gate cannot vouch for this file. Close the comment (or, if the opener is inside a string that spans lines, keep the string on one line).")

    # C6 is fail-closed: if the if/endif structure did not balance, the branch
    # states it derived are meaningless and must not be trusted.
    if (C6 && (org_broken || orgdepth != 0))
        report("C6", FNR, "could not follow the PHP if/endif structure of " file ".", \
               "C6 must be able to prove every CRM stylesheet link sits on an $IsOrgSite-false branch. Restore alternative-syntax if/endif balance, or teach bin/check-css-boundaries.sh the new shape.")

    # C3-TOTAL. Emit this file's contribution to the tree-wide inline-static-CSS
    # count, so the shell can sum it. Appended, because each file is its own awk
    # invocation. Written whenever C3 ran, so the reporting pass and the census
    # pass count the same thing by construction rather than by a second
    # implementation that could drift.
    if (C3 && STATICOUT != "") print file "\t" file_static >> STATICOUT

    exit (CENSUS || hits == 0) ? 0 : 1
}
AWKEOF

# R10 — DIRECT EVIDENCE, for the surface template whose controller the marker
# above cannot see.
#
# The derivation closes "nobody updated the list"; it does not close "the new
# CMS controller did not use the trait". This does, from the other end, and with
# evidence rather than inference: a $TPL_ROOT surface template that RENDERS CMS
# CHROME — includes a frontdoor/ or cms/ partial, links a stylesheet from either
# directory — is a CMS surface template whoever owns its controller. That is not
# a heuristic about naming, it is the template saying what it renders.
# frontdoor/ implies the PUBLIC tier, cms/-only implies ADMIN.
#
# THE EVIDENCE IS RESOLVED, NOT PATTERN-MATCHED. This used to be a grep for the
# literal string "frontdoor/<name>.tpl" on the include line. Every CMS template
# in this repo writes its includes the other way round:
#
#     $fdDir = DIR_TEMPLATE . 'default/frontdoor/';
#     ...
#     include $fdDir . '_assets_public.tpl';
#     include $fdDir . 'render_blocks.tpl';
#
# so the literal never appears on the include line at all, and the only lines
# R10 ever matched were the DOCBLOCKS that happen to mention a partial by path.
# Against the idiom actually in use it added nothing: a brand-new routed CMS
# surface whose controller does not use the trait, including its partials the
# normal way, could link orkui.css, carry a static <style> AND write
# id="theme_container" class="ork-card" with every gate at exit 0 — reproduced,
# not theorised.
#
# So R10 now asks the SAME resolver C4-PATH asks — resolve_include() in the awk
# program above, which follows an in-file `$var = DIR_TEMPLATE . '…'` assignment
# one hop, knows __DIR__ and DIR_TEMPLATE, and fails closed when it cannot prove
# a destination. One resolver, two callers, so the two cannot drift: C4-PATH
# judges the answer, R10 reads it as evidence. Writing a second resolver here
# would be the same mistake the model/controller glob split already made.
#
# WHAT IT COVERS TODAY: 15 of the 16 CMS surface templates qualify on evidence
# (6 public — _index, Site_shell, Page_view, Blog_index, Blog_post, Cms_preview;
# 9 admin — the Cms_* screens), and NONE of the other 97 templates under
# $TPL_ROOT does. Cms_deny.tpl is the one that qualifies on nothing: it includes
# no partial and links no stylesheet, which is exactly why it is C3-exempt, and
# R2's prefix rule holds it. The prefix rules already cover all 16, so R10 still
# changes no existing file's rule mask — it is purely the net under a future
# surface, which is the only thing it was ever for.
#
# COST: this lexes every $TPL_ROOT template once per invocation (~1s here), the
# price of asking the resolver instead of grepping. Do not "optimise" it with a
# pre-filter on which templates are worth lexing — that pre-filter is a second
# opinion about what counts as evidence, and a second opinion is what this
# change exists to remove.
#
# Content is read from the working tree, the same way R6's stylesheet set and
# R7's asset-base seeds are, in every mode.
CMS_BY_CONTENT=$(awk -v CLASSIFY=1 -v tpl=1 -v phpsrc=0 \
    -v TPL_BASE="$TPL_BASE" -v TPL_ROOT="$TPL_ROOT" \
    -f "$AWKPROG" "$TPL_ROOT"/*.tpl 2>/dev/null)
CMS_BY_CONTENT_PUBLIC=$(printf '%s\n' "$CMS_BY_CONTENT" |
    awk -F'\t' '$1 == "PUBLIC" && $2 != "" {print $2}' | sort -u)
CMS_BY_CONTENT_ADMIN=$(printf '%s\n' "$CMS_BY_CONTENT" |
    awk -F'\t' '$1 == "ADMIN" && $2 != "" {print $2}' | sort -u)

in_list() {
    [ -n "$2" ] || return 1
    printf '%s\n' "$2" | grep -qxF -- "$1"
}

# ...and they have to be AUDIT candidates too, or --all would classify them
# correctly and then never be handed them.
for t in $CMS_BY_CONTENT_PUBLIC $CMS_BY_CONTENT_ADMIN; do
    ALL_PATHSPECS="$ALL_PATHSPECS
$t"
done

case "$MODE" in
    staged) CANDIDATES=$(git diff --cached --name-only --diff-filter=ACM) ;;
    range)  CANDIDATES=$(git diff --name-only --diff-filter=ACM "$RANGE") ;;
    files)
        # Accept absolute paths (editor / hook callers pass them) by rebasing
        # onto the repo root; anything outside the repo stays absolute and is
        # then dropped by the scope filter below.
        CANDIDATES=$(printf '%s\n' $FILES | sed "s|^$REPO_ROOT/||")
        ;;
    all)
        # R4: an audit that only knows about tracked files declares victory
        # while an unguarded, not-yet-added template sits in the working copy.
        set -f
        TRACKED=$(git ls-files -- $ALL_PATHSPECS)
        UNTRACKED=$(git ls-files --others --exclude-standard -- $ALL_PATHSPECS)
        set +f
        CANDIDATES=$(printf '%s\n%s\n' "$TRACKED" "$UNTRACKED" | sed '/^$/d' | sort -u)
        if [ -n "$UNTRACKED" ]; then
            echo "  note: also scanning untracked file(s) in scope:"
            printf '%s\n' "$UNTRACKED" | sed 's/^/          /'
        fi
        ;;
esac

[ -z "$CANDIDATES" ] && exit 0

# Where to point a reader who has to re-pin C3_TOTAL_STATIC. We are cd'd to the
# repo root, so $0 resolves when the script was invoked by path from there;
# otherwise fall back to the canonical location.
GATE_SELF="$0"
[ -f "$GATE_SELF" ] || GATE_SELF="bin/check-css-boundaries.sh"

# R7 — ASSET-BASE SEEDS, derived from the tree.
#
# A partial does not assign the base it is handed: _assets_public.tpl documents
# "Expects $fdDir (filesystem) and $fdAssetBase (URL) already in scope" and its
# six includers each assign them. Reading that partial alone, the base is
# unresolvable — and a fail-closed rule that reports it is a false positive on
# the one file whose whole job is linking the CMS stylesheets.
#
# So the seed is derived the same way everything else here is: every in-scope
# file is scanned for `$name = HTTP_TEMPLATE|DIR_TEMPLATE . '…'`, and a name the
# tree assigns a PROVABLE prefix to resolves to that prefix (all of them, if a
# name is assigned more than one) when the file being scanned does not assign it
# itself. An in-file assignment always wins; an assignment the scanner cannot
# resolve seeds nothing, so the variable stays unprovable.
#
# This is not a loophole: it works in the dangerous direction too. Point any of
# these names at style/ anywhere in the tree and every partial that consumes it
# starts reporting.
SEEDS=$(mktemp) || exit 2
set -f
{ git ls-files -- $ALL_PATHSPECS; git ls-files --others --exclude-standard -- $ALL_PATHSPECS; } |
    sed '/^$/d' | sort -u |
    grep -E '\.(css|tpl|theme)$' |
    tr '\n' '\0' |
    xargs -0 grep -hE '\$[a-zA-Z_][a-zA-Z0-9_]*[ \t]*=[ \t]*(HTTP_TEMPLATE|DIR_TEMPLATE)[ \t]*\.' \
    > "$SEEDS" 2>/dev/null
set +f

# Colour only when writing to a terminal — hook and CI callers capture plain text.
if [ -t 1 ]; then
    C_RED=$(printf '\033[31m'); C_DIM=$(printf '\033[2m'); C_OFF=$(printf '\033[0m')
else
    C_RED=""; C_DIM=""; C_OFF=""
fi

# Same shape as the awk report() below, for findings the shell decides (R5).
shell_report() {
    printf "  %s%s%s  %s:%d\n" "$C_RED" "$1" "$C_OFF" "$2" 0
    printf "        %s\n" "$3"
    printf "        %s-> %s%s\n" "$C_DIM" "$4" "$C_OFF"
}

TOTAL=0
LISTED=0

for f in $CANDIDATES; do
    # Stylesheets and templates DECLARE CSS (C1-C6); PHP sources and scripts can
    # INJECT it (C7, R8). Everything else — images, fonts — is dropped here. A
    # .php/.js outside the CMS source set falls out at the classification step
    # below with no rule enabled.
    case "$f" in
        *.css|*.tpl|*.theme|*.php|*.js) : ;;
        *) continue ;;
    esac
    # Never scan third-party or generated code.
    case "$f" in
        */vendor/*|*/node_modules/*) continue ;;
    esac

    # -----------------------------------------------------------------------
    # Classify by RULE (R1-R3). IS_CMS covers both tiers; IS_PUBLIC is the
    # standalone-capable public tier, which is what C1 and C4 are about.
    # -----------------------------------------------------------------------
    IS_CMS=0; IS_PUBLIC=0; IS_TPL=0

    case "$f" in
        *.tpl|*.theme) IS_TPL=1 ;;
    esac

    # R1 — the CMS-owned directories, at any depth.
    case "$f" in
        "$CMS_PUBLIC"/*.css|"$CMS_PUBLIC"/*.tpl) IS_CMS=1; IS_PUBLIC=1 ;;
        "$CMS_ADMIN"/*.css|"$CMS_ADMIN"/*.tpl)   IS_CMS=1 ;;
    esac

    # R2 — CMS surface templates one directory up, by controller prefix. The
    # front door is the base controller's _index.tpl; Cms_preview* renders the
    # PUBLIC page inside a preview chrome and so belongs to the public tier,
    # while every other Cms_* is OGRE admin.
    case "$f" in
        "$TPL_ROOT"/_index.tpl)       IS_CMS=1; IS_PUBLIC=1 ;;
        "$TPL_ROOT"/Cms_preview*.tpl) IS_CMS=1; IS_PUBLIC=1 ;;
        "$TPL_ROOT"/Cms_*.tpl)        IS_CMS=1 ;;
        *)
            for c in $CMS_CONTROLLERS; do
                case "$f" in
                    "$TPL_ROOT"/"$c"_*.tpl) IS_CMS=1; IS_PUBLIC=1; break ;;
                esac
            done
            ;;
    esac

    # R10 — direct evidence. A surface template that renders CMS chrome is a CMS
    # surface template whatever its prefix, which is the net under a CMS
    # controller that never used the scope trait the derivation looks for.
    if [ "$IS_CMS" = 0 ]; then
        case "$f" in
            "$TPL_ROOT"/*.tpl)
                if in_list "$f" "$CMS_BY_CONTENT_PUBLIC"; then
                    IS_CMS=1; IS_PUBLIC=1
                elif in_list "$f" "$CMS_BY_CONTENT_ADMIN"; then
                    IS_CMS=1
                fi
                ;;
        esac
    fi

    # R8 — the CMS PHP/JS source set. These are the files that can put CSS on a
    # CMS page without a stylesheet or a template being touched. Only C7 runs on
    # them, so IS_CMS_SRC is kept separate from IS_CMS (which drives C2/C3 with
    # template semantics).
    IS_CMS_SRC=0; IS_PHP_SRC=0
    case "$f" in
        "$CMS_PUBLIC"/*.js|"$CMS_ADMIN"/*.js)      IS_CMS_SRC=1 ;;
        "$CMS_MODEL_FRONTDOOR")                    IS_CMS_SRC=1; IS_PHP_SRC=1 ;;
        *.php)
            # ONE derivation from CMS_CONTROLLERS across all three directories,
            # so the controller half and the model/domain half cannot drift.
            for c in $CMS_CONTROLLERS; do
                case "$f" in
                    "$CMS_CONTROLLER_DIR"/controller."$c"*.php|\
                    "$CMS_CONTROLLER_DIR"/trait."$c"*.php|\
                    "$CMS_MODEL_DIR"/model."$c"*.php|\
                    "$CMS_DOMAIN_DIR"/class."$c"*.php)
                        IS_CMS_SRC=1; IS_PHP_SRC=1; break ;;
                esac
            done
            ;;
    esac

    C1=0; C2=0; C3=0; C4=0; C4_PATH=0; C5=0; C6=0; C7=0; C1_TC=0

    # C1 — public tier only, with the two documented exemptions.
    if [ "$IS_PUBLIC" = 1 ]; then
        case "$f" in
            # The designated coupling point: fully exempt from C1.
            "$INTEROP") : ;;
            # cms-base.css is NARROWLY exempt — it must neutralize the
            # #theme_container default.theme emits on standalone org sites, and
            # that is the whole allowance. #newmenu and .ork- are still
            # rejected: it is the one stylesheet a standalone org site loads
            # globally, so an unbounded exemption would let the quarantined
            # override layer migrate straight back in with the gate green.
            "$CMS_BASE") C1=1; C1_TC=1 ;;
            *) C1=1 ;;
        esac
    fi

    # C2 — every CMS file, both tiers.
    [ "$IS_CMS" = 1 ] && C2=1

    # C3 — every CMS TEMPLATE, both tiers. Cms_deny.tpl is the one exemption
    # (structural: it bypasses the shell and links no stylesheet at all).
    # C3_DEST names the destination in the fix hint.
    C3_DEST=""
    if [ "$IS_CMS" = 1 ] && [ "$IS_TPL" = 1 ] && [ "$f" != "$CMS_DENY" ]; then
        C3=1
        if [ "$IS_PUBLIC" = 1 ]; then
            case "$f" in
                "$CMS_PUBLIC"/blocks/*.tpl) C3_DEST="frontdoor/css/blocks.css" ;;
                *) C3_DEST="frontdoor/css/ (blocks.css for a content block, blog.css for blog markup, orgsite.css for standalone-site chrome, frontdoor.css otherwise — see frontdoor/css/README.md)" ;;
            esac
        else
            C3_DEST="cms/css/cms-admin.css"
        fi
    fi

    # C4-LINK — the whole public tier, minus the one designated link point.
    if [ "$IS_PUBLIC" = 1 ] && [ "$f" != "$ASSETS_INSHELL" ]; then
        C4=1
    fi
    # C4-PATH — the org-site render path: the shell plus everything under
    # frontdoor/, which is where the shell's includes are confined to.
    case "$f" in
        "$SITE_SHELL"|"$CMS_PUBLIC"/*.tpl) C4_PATH=1 ;;
    esac

    # C5 — R3: a .css under orkui/template/ that is not CMS-owned is CRM CSS.
    case "$f" in
        "$CMS_PUBLIC"/*|"$CMS_ADMIN"/*) : ;;
        "$TPL_BASE"/*.css) C5=1 ;;
    esac

    # C6 guards the theme file that chooses org-site vs CRM stylesheets.
    case "$f" in
        "$TPL_ROOT"/*.theme) C6=1 ;;
    esac

    # C7 — R8: CSS injection from a CMS PHP source or a CMS script.
    C7=$IS_CMS_SRC

    [ "$C1$C2$C3$C4$C4_PATH$C5$C6$C7" = "00000000" ] && continue

    # --list: report the scope and run nothing. The mask is printed in rule order
    # (C1 C2 C3 C4 C4-PATH C5 C6 C7) so "which files does C6 cover?" is a grep.
    if [ "$LIST" = 1 ]; then
        printf '%s%s%s%s%s%s%s%s  %s\n' \
            "$C1" "$C2" "$C3" "$C4" "$C4_PATH" "$C5" "$C6" "$C7" "$f"
        LISTED=$((LISTED + 1))
        continue
    fi

    # R5 — a symlink is never scanned. `git show :path` on one returns the link
    # TARGET, not the content it resolves to, so a staged symlink used to sail
    # through every rule while pointing at anything at all.
    if [ "$MODE" = "staged" ]; then
        SMODE=$(git ls-files --stage -- "$f" 2>/dev/null | awk 'NR==1{print $1}')
        if [ "$SMODE" = "120000" ]; then
            shell_report "C0" "$f" \
                "in-scope path is a symlink, so its content cannot be checked." \
                "git show :$f returns the link target, not the CSS/markup that ships. Commit a real file, or move the link outside the CMS/CRM style directories."
            TOTAL=$((TOTAL + 1))
            continue
        fi
    elif [ -L "$f" ]; then
        shell_report "C0" "$f" \
            "in-scope path is a symlink, so its content cannot be checked." \
            "The gate refuses to vouch for a file whose content lives somewhere else (and whose staged form is just the link target). Commit a real file, or move the link outside the CMS/CRM style directories."
        TOTAL=$((TOTAL + 1))
        continue
    fi

    # Templates carry // and <!-- --> comments on top of /* */; stylesheets do
    # not. PHP sources and scripts carry // too, so they take the same lexer.
    TPL=0
    case "$f" in
        *.tpl|*.theme|*.php|*.js) TPL=1 ;;
    esac

    FILEDIR=$(dirname "$f")

    if [ "$MODE" = "staged" ]; then
        git show ":$f" > "$CONTENT" 2>/dev/null || continue
    else
        [ -f "$f" ] || continue
        cat "$f" > "$CONTENT" 2>/dev/null || continue
    fi

    awk -v file="$f" -v tpl="$TPL" -v phpsrc="$IS_PHP_SRC" -v filedir="$FILEDIR" \
        -v TPL_BASE="$TPL_BASE" -v TPL_ROOT="$TPL_ROOT" \
        -v C_RED="$C_RED" -v C_DIM="$C_DIM" -v C_OFF="$C_OFF" \
        -v C1="$C1" -v C2="$C2" -v C3="$C3" -v C4="$C4" -v C4_PATH="$C4_PATH" \
        -v C5="$C5" -v C6="$C6" -v C7="$C7" \
        -v C1_TC="$C1_TC" -v C3_DEST="$C3_DEST" -v C3_MAX_STATIC="$C3_MAX_STATIC" \
        -v CRM_SHEETS="$CRM_SHEETS" -v SEEDFILE="$SEEDS" \
        -v CENSUS=0 -v STATICOUT="$STATICS" \
        -v C1_FIX="Standalone org sites do not load orkui.css, so this rule is dead there and couples the layers everywhere else. Move it to frontdoor/css/orkshell-interop.css." \
        -v C1_FIX_BASE="cms-base.css may name #theme_container and nothing else. Move anything more into frontdoor/css/orkshell-interop.css — org sites never load it, so a rule placed here would ship to every one of them." \
        -v C2_FIX="Rename it to --cms-* or --fd-* and scope it to the CMS root. Reading an --ork-* token with var() is fine; defining one is not." \
        -f "$AWKPROG" "$CONTENT"
    [ $? -ne 0 ] && TOTAL=$((TOTAL + 1))
done

if [ "$LIST" = 1 ]; then
    echo "$LISTED files in scope (mask columns: C1 C2 C3 C4 C4-PATH C5 C6 C7)"
    exit 0     # the EXIT trap above removes the temp files
fi

# ---------------------------------------------------------------------------
# C3-TOTAL — the tree-wide inline-static-CSS census (see C3_TOTAL_STATIC above)
#
# The loop above only counted the files THIS invocation was asked about, and the
# pinned number is a property of the whole tree — that is the entire point of it,
# since the hole it closes is "one more file, each one inside the per-file
# budget". So every remaining CMS template is re-scanned here in census mode:
# the same awk program and the same C3 counter, with reporting suppressed, so
# the census cannot drift from the rule it is measuring.
#
# Only a template whose text contains "<style" can contribute, so the candidate
# set is pre-filtered on that substring — 3 files today, not 62.
# ---------------------------------------------------------------------------
set -f
CENSUS_ALL=$( { git ls-files -- $ALL_PATHSPECS; git ls-files --others --exclude-standard -- $ALL_PATHSPECS; } |
    sed '/^$/d' | sort -u | grep -E '\.tpl$' )
set +f
COUNTED=$(awk -F'\t' '{print $1}' "$STATICS" 2>/dev/null | sort -u)

for f in $CENSUS_ALL; do
    # Cms_deny.tpl is exempt from C3 entirely (structural — it bypasses the shell
    # and links no stylesheet), so it is exempt from C3-TOTAL too.
    [ "$f" = "$CMS_DENY" ] && continue
    case "$f" in */vendor/*|*/node_modules/*) continue ;; esac
    printf '%s\n' "$COUNTED" | grep -qxF -- "$f" && continue

    if [ "$MODE" = "staged" ]; then
        git show ":$f" > "$CONTENT" 2>/dev/null || continue
    else
        [ -f "$f" ] || continue
        [ -L "$f" ] && continue
        cat "$f" > "$CONTENT" 2>/dev/null || continue
    fi
    grep -qi '<style' "$CONTENT" || continue

    awk -v file="$f" -v tpl=1 -v phpsrc=0 -v filedir="$(dirname "$f")" \
        -v TPL_BASE="$TPL_BASE" -v TPL_ROOT="$TPL_ROOT" \
        -v C1=0 -v C2=0 -v C3=1 -v C4=0 -v C4_PATH=0 -v C5=0 -v C6=0 -v C7=0 \
        -v C1_TC=0 -v C3_DEST="" -v C3_MAX_STATIC="$C3_MAX_STATIC" \
        -v CRM_SHEETS="" -v SEEDFILE="" \
        -v CENSUS=1 -v STATICOUT="$STATICS" \
        -f "$AWKPROG" "$CONTENT"
done

STATIC_TOTAL=$(awk -F'\t' '{s += $2} END {print s + 0}' "$STATICS" 2>/dev/null)
[ -z "$STATIC_TOTAL" ] && STATIC_TOTAL=0
PIN_LINE=$(grep -n '^C3_TOTAL_STATIC=' "$GATE_SELF" 2>/dev/null | head -1 | cut -d: -f1)
[ -z "$PIN_LINE" ] && PIN_LINE=0

static_contributors() {
    awk -F'\t' '$2 + 0 > 0 {printf "        %6d  %s\n", $2, $1}' "$STATICS" 2>/dev/null | sort -rn
}

STATIC_FAIL=0
if [ "$STATIC_TOTAL" -gt "$C3_TOTAL_STATIC" ]; then
    echo ""
    printf "  %sC3-TOTAL%s  %s:%s\n" "$C_RED" "$C_OFF" "$GATE_SELF" "$PIN_LINE"
    echo "        Inline static CSS across the CMS templates ROSE to $STATIC_TOTAL static declaration(s)"
    echo "        riding inside PHP-interpolating <style> blocks; the pinned budget is $C3_TOTAL_STATIC."
    echo "        Contributing files:"
    static_contributors
    printf "        %s-> The per-file budget (C3_MAX_STATIC=%s) bounds one file; it cannot bound the render\n" "$C_DIM" "$C3_MAX_STATIC"
    echo "           path, which is the SUM — N files of $C3_MAX_STATIC is the same inline stylesheet split N ways."
    echo "           Move the static declarations into a stylesheet under frontdoor/css/ or cms/css/ and"
    echo "           keep only the interpolated declaration inline. If the new inline CSS is genuinely"
    echo "           per-instance and cannot be a stylesheet, raise the pin deliberately:"
    printf "               %s:%s   C3_TOTAL_STATIC=%s%s\n" "$GATE_SELF" "$PIN_LINE" "$STATIC_TOTAL" "$C_OFF"
    STATIC_FAIL=1
elif [ "$STATIC_TOTAL" -lt "$C3_TOTAL_STATIC" ]; then
    if [ "${CSS_STATIC_ALLOW_SLACK:-0}" = "1" ]; then
        echo "  note: inline static CSS fell to $STATIC_TOTAL (budget $C3_TOTAL_STATIC) — forgiven by CSS_STATIC_ALLOW_SLACK=1."
    else
        echo ""
        printf "  %sC3-TOTAL%s  %s:%s\n" "$C_RED" "$C_OFF" "$GATE_SELF" "$PIN_LINE"
        echo "        Inline static CSS across the CMS templates FELL to $STATIC_TOTAL; the pin still says $C3_TOTAL_STATIC."
        printf "        %s-> A budget that only fails upward is a freeze, not a ratchet: the slack sits there for\n" "$C_DIM"
        echo "           the next commit to spend on fresh inline CSS with the gate green throughout. Pin the"
        echo "           improvement so it cannot be spent:"
        printf "               %s:%s   C3_TOTAL_STATIC=%s%s\n" "$GATE_SELF" "$PIN_LINE" "$STATIC_TOTAL" "$C_OFF"
        echo "        (One-run escape hatch, below-budget direction only: CSS_STATIC_ALLOW_SLACK=1)"
        STATIC_FAIL=1
    fi
fi

# ---------------------------------------------------------------------------
# C8 — EVERY SURFACE TEMPLATE MUST BE CLASSIFIABLE. Fail closed on the ones that
# are not, so a new surface forces a decision instead of silently getting no
# coverage.
#
# The derivation above answers "which controllers are CMS?" by asking the
# filesystem, and R10 answers "is this template a CMS surface?" from its
# content. Both are positive tests, and a positive test that finds nothing
# says nothing — which is exactly the failure being closed here. The router
# resolves <Controller>/<action> to $TPL_ROOT/<Controller>_<action>.tpl, so a
# $TPL_ROOT template named that way whose controller DOES NOT EXIST is a surface
# this gate cannot reason about at all: it cannot tell whether it is CMS or CRM,
# so it cannot know which rules to run, so it runs none. That is reported.
#
# Today the count is ZERO — all 23 distinct prefixes under $TPL_ROOT
# (Admin, Atlas, … Tournament, Unit) resolve to a controller.<X>.php that
# exists — so this costs nothing now and fires the moment a surface appears with
# nothing behind it. Files beginning with "_" are partials, not routed surfaces,
# and $TPL_ROOT/_index.tpl (the front door, rendered by the BASE controller) is
# in scope by name already.
# ---------------------------------------------------------------------------
set -f
SURFACE_TPLS=$( { git ls-files -- "$TPL_ROOT/*.tpl"
                  git ls-files --others --exclude-standard -- "$TPL_ROOT/*.tpl"
                } | sed '/^$/d' | sort -u )
set +f

UNCLASSIFIED=""
for t in $SURFACE_TPLS; do
    # $TPL_ROOT only — git's `*` crosses `/`, so frontdoor/ and cms/ came along.
    case "$t" in "$TPL_ROOT"/*/*) continue ;; esac
    b=${t##*/}
    b=${b%.tpl}
    case "$b" in _*) continue ;; esac
    case "$b" in *_*) : ;; *) continue ;; esac
    c=${b%%_*}
    [ -f "$CMS_CONTROLLER_DIR/controller.$c.php" ] && continue
    git cat-file -e ":$CMS_CONTROLLER_DIR/controller.$c.php" 2>/dev/null && continue
    UNCLASSIFIED="$UNCLASSIFIED
$t	$c"
done
UNCLASSIFIED=$(printf '%s\n' "$UNCLASSIFIED" | sed '/^$/d')

CLASS_FAIL=0
if [ -n "$UNCLASSIFIED" ]; then
    echo ""
    printf '%s\n' "$UNCLASSIFIED" | while IFS='	' read -r t c; do
        [ -z "$t" ] && continue
        printf "  %sC8%s  %s:1\n" "$C_RED" "$C_OFF" "$t"
        echo "        Surface template the gate cannot classify: no $CMS_CONTROLLER_DIR/controller.$c.php."
        printf "        %s-> The router resolves <Controller>/<action> to \$TPL_ROOT/<Controller>_<action>.tpl, so\n" "$C_DIM"
        echo "           this file is a routed surface with no controller behind it. The gate cannot tell"
        echo "           whether it is a CMS surface or a CRM one, so it runs NO rule on it — which is how a"
        echo "           new surface used to get zero coverage in silence. Add the controller (a CMS one uses"
        echo "           the CmsScopeContext trait, which is what puts its templates in scope automatically),"
        printf "           or rename the file to a partial (leading underscore) if it is not a routed surface.%s\n" "$C_OFF"
    done
    CLASS_FAIL=1
fi

if [ "$TOTAL" -gt 0 ]; then
    echo ""
    echo "  CSS boundary gate: $TOTAL file(s) cross the CMS/CRM line."
    echo "  Design: docs/superpowers/specs/2026-08-21-cms-css-separation-design.md"
    echo "  Audit with: bin/check-css-boundaries.sh --all"
fi

[ "$TOTAL" -gt 0 ] && exit 1
[ "$STATIC_FAIL" = "1" ] && exit 1
[ "$CLASS_FAIL" = "1" ] && exit 1

exit 0
