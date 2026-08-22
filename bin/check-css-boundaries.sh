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
#   bin/check-css-boundaries.sh --all                 # every tracked file in scope (audit)
#
# Exit 0 = clean, 1 = violations found, 2 = bad invocation.
#
# Escape hatch (deliberate exceptions only): ORK3_ALLOW_LAYER_VIOLATION=1 is
# honoured by the git hooks that call this script, not by the script itself.
#
# ---------------------------------------------------------------------------
# The rules
#
#   C0  The scanner must be able to parse the file. A comment opener that is
#       never closed leaves every later line unscanned, so it is reported as a
#       violation rather than passing silently. See "Comment handling" below.
#   C1  CMS CSS/markup may not name an ORK application-shell selector
#       (#theme_container, #newmenu, .ork-). BOTH stylesheet selectors and
#       template markup (id="theme_container", a class token starting "ork-")
#       are checked, and CSS identifier escapes (#theme\_container, .ork\-x,
#       #\74 heme_container) are decoded before matching.
#       Scope: the PUBLIC CMS side only — frontdoor/css/*.css, every template
#       under frontdoor/, and the public CMS surface templates that live one
#       directory up (_index, Site_shell, Page_view, Blog_index, Blog_post,
#       Cms_preview). One file is fully exempt: orkshell-interop.css, the
#       designated coupling point. cms-base.css is NARROWLY exempt — it may
#       name #theme_container (it has to neutralize the container default.theme
#       emits on standalone org sites) and nothing else; #newmenu and .ork- are
#       still rejected there.
#       C1 does NOT cover cms/css/cms-admin.css or the cms/ + Cms_* admin
#       templates: the OGRE admin is definitionally an ORK-hosted application
#       surface, renders inside the shell, and has no portability claim to
#       protect. C2 still applies there.
#   C2  CMS CSS may not DEFINE a token in the CRM's --ork-* namespace.
#       Reading one with var() is fine. A declaration whose colon has been
#       wrapped onto the following line counts as a definition. Scope: all CMS
#       css + templates.
#   C3  A CMS template may not carry a STATIC inline <style> block.
#       A <style> is legal only if it interpolates a PHP VARIABLE in a
#       declaration-value position — blocks/columns.tpl interpolates $fdbCount
#       into grid-template-columns and therefore cannot be a static stylesheet.
#       A PHP tag parked outside any declaration, or one echoing a literal,
#       does not launder a static block. PHP that echoes a <style> tag built by
#       string concatenation is rejected too.
#       Scope: frontdoor/blocks/**.tpl (destination frontdoor/css/blocks.css)
#       AND the OGRE admin templates — cms/*.tpl and $TPL_ROOT/Cms_*.tpl
#       (destination cms/css/cms-admin.css, which cms/_shell_top.tpl links
#       exactly once for every admin surface).
#       ONE EXEMPTION, and it is structural rather than stylistic:
#       Cms_deny.tpl. Controller_Cms::_denyPermission() include()s that file
#       directly and exit()s — it never goes through the themed View pipeline,
#       never includes cms/_shell_top.tpl, and emits its own <!doctype
#       html>/<head>/<body>. It therefore loads NO stylesheet at all, and its
#       inline <style> is the only styling it can have. If that ever changes
#       (the deny page starts rendering through the shell), delete the
#       exemption below and lift its CSS like everything else.
#   C4  A partial a standalone org site renders may not link orkui.css,
#       tokens.css or orkshell-interop.css. Scope: Site_shell.tpl and
#       frontdoor/_assets_public.tpl — the shell and the one stylesheet partial
#       it includes. Guarding only the shell would leave the partial as a
#       one-line detour to the same regression.
#   C5  CRM CSS may not name a CMS selector (.fd-, .cms-, .org-).
#       Scope: every .css under style/, as a DIRECTORY — a new CRM stylesheet
#       is in scope the moment it is added.
#   C6  default.theme may link a CRM stylesheet (anything under style/, plus
#       orkshell-interop.css) only from a branch where $IsOrgSite is provably
#       falsy. This is the rule that actually decides what a standalone org
#       site downloads: the $IsOrgSite gate around lines 104-110 is the whole
#       point of the separation, and a link added to its ELSE branch — or added
#       unconditionally — reintroduces 91 KB of CRM CSS on public org sites.
#       The check is branch-aware (it tracks PHP alternative-syntax
#       if/elseif/else/endif nesting and what each branch implies about
#       $IsOrgSite) and fail-closed: an unbalanced structure it cannot follow is
#       reported rather than assumed safe. Scope: $TPL_ROOT/*.theme.
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
    sed -n '3,29p' "$0" | sed 's/^# \{0,1\}//'
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

# ---------------------------------------------------------------------------
# Layout
# ---------------------------------------------------------------------------
TPL_ROOT="orkui/template/default"
CMS_PUBLIC="$TPL_ROOT/frontdoor"
CMS_ADMIN="$TPL_ROOT/cms"
CRM_STYLE="$TPL_ROOT/style"
INTEROP="$CMS_PUBLIC/css/orkshell-interop.css"
CMS_BASE="$CMS_PUBLIC/css/cms-base.css"
SITE_SHELL="$TPL_ROOT/Site_shell.tpl"
ASSETS_PUBLIC="$CMS_PUBLIC/_assets_public.tpl"
# Standalone bare-chrome page: included directly by the controller, renders its
# own <html>/<head>/<body>, never includes cms/_shell_top.tpl and so links no
# stylesheet whatsoever. Exempt from C3 — see the rule text above.
CMS_DENY="$TPL_ROOT/Cms_deny.tpl"

# The public CMS surfaces do not live under frontdoor/ — the router resolves a
# controller action to $TPL_ROOT/<Controller>_<action>.tpl, so the front door,
# the org-site shell, authored pages, the blog and the CMS preview all sit one
# directory up. They are public CMS markup all the same and C1/C2 cover them.
# Cms_preview.tpl is listed here on purpose: it renders the PUBLIC page inside
# a preview chrome, unlike the rest of the Cms_* admin screens.
CMS_SURFACES="$TPL_ROOT/_index.tpl
$SITE_SHELL
$TPL_ROOT/Page_view.tpl
$TPL_ROOT/Blog_index.tpl
$TPL_ROOT/Blog_post.tpl
$TPL_ROOT/Cms_preview.tpl"

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
        # $TPL_ROOT/*.theme is in scope for C6: default.theme is the file that
        # decides which stylesheets a standalone org site gets.
        # $TPL_ROOT/Cms_*.tpl are the OGRE admin surface templates. They sit one
        # directory above cms/ (the router resolves Cms/media to Cms_media.tpl),
        # so the $CMS_ADMIN glob never reached them and C2/C3 could not see them.
        CANDIDATES=$(git ls-files "$CMS_PUBLIC/*" "$CMS_ADMIN/*" "$CRM_STYLE/*" "$TPL_ROOT/*.theme" "$TPL_ROOT/Cms_*.tpl" $CMS_SURFACES)
        ;;
esac

[ -z "$CANDIDATES" ] && exit 0

# ---------------------------------------------------------------------------
# Scanner
# ---------------------------------------------------------------------------
AWKPROG=$(mktemp) || exit 2
CONTENT=$(mktemp) || exit 2
trap 'rm -f "$AWKPROG" "$CONTENT"' EXIT INT TERM

# Colour only when writing to a terminal — hook and CI callers capture plain text.
if [ -t 1 ]; then
    C_RED=$(printf '\033[31m'); C_DIM=$(printf '\033[2m'); C_OFF=$(printf '\033[0m')
else
    C_RED=""; C_DIM=""; C_OFF=""
fi

cat > "$AWKPROG" <<'AWKEOF'
function report(rule, lineno, msg, fix) {
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
# C3 — static inline <style> in a content-block template
# ---------------------------------------------------------------------------

# Text since the last {, } or ; — i.e. the declaration we are currently inside.
function last_seg(s,    i, c) {
    for (i = length(s); i >= 1; i--) {
        c = substr(s, i, 1)
        if (c == "{" || c == "}" || c == ";") break
    }
    return substr(s, i + 1)
}

# Consume style-element text, deciding whether the block genuinely interpolates
# PHP. It counts only when the PHP tag sits in a declaration-VALUE position and
# the expression references a PHP variable — `grid-template-columns: repeat(<?=
# (int) $fdbCount ?>, 1fr)` counts; `<?= '' ?>` parked between rules does not.
function style_consume(seg,    p, e, expr, tail) {
    while (1) {
        p = index(seg, "<?")
        if (p == 0) { style_tail = style_tail seg; break }
        style_tail = style_tail substr(seg, 1, p - 1)
        e = index(substr(seg, p), "?>")
        if (e == 0) { expr = substr(seg, p + 2); seg = "" }
        else        { expr = substr(seg, p + 2, e - 3); seg = substr(seg, p + e + 1) }
        tail = last_seg(style_tail)
        if (index(expr, "$") > 0 && tail ~ /[-a-zA-Z][-a-zA-Z0-9]*[ \t]*:/) style_php = 1
        style_tail = style_tail " "
        if (seg == "") break
    }
    if (length(style_tail) > 400) style_tail = substr(style_tail, length(style_tail) - 399)
}

function scan_style(s,    p, q, seg) {
    while (1) {
        if (!in_style) {
            p = index(s, "<style")
            if (p == 0) return
            in_style = 1
            style_line = FNR
            style_php = 0
            style_tail = ""
            s = substr(s, p + 6)
            continue
        }
        q = index(s, "</style")
        if (q == 0) { style_consume(s); return }
        seg = substr(s, 1, q - 1)
        style_consume(seg)
        if (!style_php) report("C3", style_line, C3_MSG, C3_FIX)
        in_style = 0
        s = substr(s, q + 7)
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

BEGIN {
    C3_MSG = "Static inline <style> block in a CMS template."
    C3_FIX = "This CSS belongs in " C3_DEST " so it is cacheable, lintable and visible to duplication analysis, instead of being re-sent in the HTML of every render. Only a <style> that interpolates a PHP variable into a declaration value (blocks/columns.tpl) may stay inline."
    C6_FIX = "Standalone public org sites must not download CRM application CSS. Link it only inside the `if (empty($IsOrgSite)):` branch of default.theme; org sites get frontdoor/css/cms-base.css instead."
}
{
    line = decomment($0)
    mline = css_unescape(line)

    if (C1) {
        if (C1_TC) {
            if (mline ~ /(#newmenu|\.ork-)/)
                report("C1", FNR, "CMS CSS names an ORK application-shell selector.", C1_FIX_BASE)
        } else if (mline ~ /(#theme_container|#newmenu|\.ork-)/) {
            report("C1", FNR, "CMS CSS names an ORK application-shell selector.", C1_FIX)
        }
        # Markup, not just stylesheet selectors: a public CMS template that
        # hangs ORK ids/classes on its own DOM is coupled just as hard.
        if (tpl && !C1_TC &&
            (mline ~ /id[ \t]*=[ \t]*["']?theme_container/ ||
             mline ~ /class[ \t]*=[ \t]*"[ \t]*ork-/ ||
             mline ~ /class[ \t]*=[ \t]*'[ \t]*ork-/ ||
             mline ~ /class[ \t]*=[ \t]*"[^"]*[ \t]ork-/ ||
             mline ~ /class[ \t]*=[ \t]*'[^']*[ \t]ork-/))
            report("C1", FNR, "CMS markup carries an ORK application-shell id/class.", \
                   "Standalone org sites load no orkui.css, so the hook styles nothing there and couples the layers everywhere else. Give the element a .fd-/.cms-/.org- class instead.")
    }

    # C2: DEFINING an --ork-* token. `var(--ork-x)` is a read and is fine; a
    # bare `--ork-x:` at the start of a declaration is a write. The colon is
    # allowed to have been wrapped onto the next line — postcss parses that as
    # a real declaration, and formatters produce it.
    if (C2) {
        if (mline ~ /(^|[;{ \t])--ork-[a-z0-9-]+[ \t]*:/) {
            report("C2", FNR, "CMS CSS defines a token in the CRM's --ork-* namespace.", C2_FIX)
            ork_pend = 0
        } else if (ork_pend && mline ~ /^[ \t]*:/) {
            report("C2", ork_pend_line, "CMS CSS defines a token in the CRM's --ork-* namespace (colon wrapped onto the next line).", C2_FIX)
            ork_pend = 0
        } else if (mline ~ /^[ \t]*$/) {
            # blank line: keep any pending wrap alive
        } else if (mline ~ /(^|[;{ \t])--ork-[a-z0-9-]+[ \t]*$/) {
            ork_pend = 1
            ork_pend_line = FNR
        } else {
            ork_pend = 0
        }
    }

    if (C3) {
        scan_style(line)
        # PHP that echoes a <style> tag assembled from string fragments —
        # '<st' . 'yle>' — never reaches scan_style as a literal tag.
        if (index(line, "<?") > 0 && line !~ /<style/) {
            joined = line
            gsub(/["'][ \t]*\.[ \t]*["']/, "", joined)
            if (joined ~ /<[ \t]*style/)
                report("C3", FNR, "PHP emits a <style> tag assembled from string fragments.", C3_FIX)
        }
    }

    if (C4 && line ~ /(orkui\.css|tokens\.css|orkshell-interop\.css)/)
        report("C4", FNR, "A standalone org site's markup links a stylesheet it must not load.", \
               "Org sites load cms-base.css instead of the CRM stylesheets, and need no ORK-shell interop layer. Remove the link.")

    if (C5 && mline ~ /(\.fd-|\.cms-|\.org-)/)
        report("C5", FNR, "CRM CSS names a CMS selector.", \
               "The CRM must not style CMS surfaces. Move the rule into the matching CMS stylesheet under frontdoor/css/ or cms/css/.")

    if (C6) {
        org_track(php_extract(line))
        if (line ~ /(default\/style\/[A-Za-z0-9_.-]*\.css|orkshell-interop\.css)/ && org_effective() != "F")
            report("C6", FNR, "default.theme links CRM CSS on a path a standalone org site can reach.", C6_FIX)
    }
}
END {
    # An unterminated <style> is still an inline block, and a static one.
    if (C3 && in_style && !style_php) report("C3", style_line, C3_MSG, C3_FIX)

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

    exit (hits > 0) ? 1 : 0
}
AWKEOF

TOTAL=0

for f in $CANDIDATES; do
    # Only stylesheets and templates can carry a boundary violation; this also
    # keeps images, fonts and the frontdoor/js/ bundle out of the scanner.
    case "$f" in
        *.css|*.tpl|*.theme) : ;;
        *) continue ;;
    esac
    # Never scan third-party or generated code.
    case "$f" in
        */vendor/*|*/node_modules/*) continue ;;
    esac

    C1=0; C2=0; C3=0; C4=0; C5=0; C6=0; C1_TC=0

    case "$f" in
        # The designated coupling point: fully exempt from C1.
        "$INTEROP") C2=1 ;;
        # cms-base.css is NARROWLY exempt — it must neutralize the
        # #theme_container default.theme emits on standalone org sites, and
        # that is the whole allowance. #newmenu and .ork- are still rejected:
        # it is the one stylesheet a standalone org site loads globally, so an
        # unbounded exemption would let the quarantined override layer migrate
        # straight back in with the gate green.
        "$CMS_BASE") C1=1; C1_TC=1; C2=1 ;;
        # Public CMS side: must be able to stand alone -> C1 + C2.
        "$CMS_PUBLIC"/*.css|"$CMS_PUBLIC"/*.tpl) C1=1; C2=1 ;;
        # OGRE admin: definitionally in-shell, so C1 does not apply. C2 does.
        "$CMS_ADMIN"/*.css|"$CMS_ADMIN"/*.tpl) C2=1 ;;
    esac
    # OGRE admin page templates (Cms_media.tpl, Cms_nav.tpl, ...). Same tier as
    # cms/*.tpl: C1 does not apply, C2 does. Cms_preview.tpl is deliberately NOT
    # excluded here — $CMS_SURFACES below turns C1 on for it as well, because it
    # renders the PUBLIC page inside a preview chrome.
    case "$f" in
        "$TPL_ROOT"/Cms_*.tpl) C2=1 ;;
    esac
    # Public CMS surface templates, which sit one directory above frontdoor/.
    for s in $CMS_SURFACES; do
        [ "$f" = "$s" ] && { C1=1; C2=1; break; }
    done
    # C3. Content blocks lift to frontdoor/css/blocks.css; every OGRE admin
    # template lifts to cms/css/cms-admin.css, which cms/_shell_top.tpl links
    # exactly once for the whole admin. Cms_deny.tpl is the one exemption: it
    # bypasses the shell entirely and loads no stylesheet, so inline is all it
    # has. C3_DEST names the destination in the fix hint.
    C3_DEST=""
    case "$f" in
        "$CMS_PUBLIC"/blocks/*.tpl) C3=1; C3_DEST="frontdoor/css/blocks.css" ;;
        "$CMS_DENY")                : ;;
        "$CMS_ADMIN"/*.tpl|"$TPL_ROOT"/Cms_*.tpl) C3=1; C3_DEST="cms/css/cms-admin.css" ;;
    esac
    # C4 covers the org-site shell AND the stylesheet partial it includes;
    # otherwise the partial is a one-line detour around the same rule.
    case "$f" in
        "$SITE_SHELL"|"$ASSETS_PUBLIC") C4=1 ;;
    esac
    # C5 is scoped to the CRM style DIRECTORY, not a filename list: a new
    # stylesheet dropped in style/ is guarded from the moment it lands.
    case "$f" in
        "$CRM_STYLE"/*.css) C5=1 ;;
    esac
    # C6 guards the theme file that chooses org-site vs CRM stylesheets.
    case "$f" in
        "$TPL_ROOT"/*.theme) C6=1 ;;
    esac

    [ "$C1$C2$C3$C4$C5$C6" = "000000" ] && continue

    # Templates carry // and <!-- --> comments on top of /* */; stylesheets do not.
    TPL=0
    case "$f" in
        *.tpl|*.theme) TPL=1 ;;
    esac

    if [ "$MODE" = "staged" ]; then
        git show ":$f" > "$CONTENT" 2>/dev/null || continue
    else
        [ -f "$f" ] || continue
        cat "$f" > "$CONTENT" 2>/dev/null || continue
    fi

    awk -v file="$f" -v tpl="$TPL" \
        -v C_RED="$C_RED" -v C_DIM="$C_DIM" -v C_OFF="$C_OFF" \
        -v C1="$C1" -v C2="$C2" -v C3="$C3" -v C4="$C4" -v C5="$C5" -v C6="$C6" \
        -v C1_TC="$C1_TC" -v C3_DEST="$C3_DEST" \
        -v C1_FIX="Standalone org sites do not load orkui.css, so this rule is dead there and couples the layers everywhere else. Move it to frontdoor/css/orkshell-interop.css." \
        -v C1_FIX_BASE="cms-base.css may name #theme_container and nothing else. Move anything more into frontdoor/css/orkshell-interop.css — org sites never load it, so a rule placed here would ship to every one of them." \
        -v C2_FIX="Rename it to --cms-* or --fd-* and scope it to the CMS root. Reading an --ork-* token with var() is fine; defining one is not." \
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
