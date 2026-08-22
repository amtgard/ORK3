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
#
# ---------------------------------------------------------------------------
# The five rules
#
#   C1  CMS CSS/markup may not name an ORK application-shell selector
#       (#theme_container, #newmenu, .ork-).
#       Scope: the PUBLIC CMS side only — frontdoor/css/*.css, every template
#       under frontdoor/, and the public CMS surface templates that live one
#       directory up (_index, Site_shell, Page_view, Blog_index, Blog_post,
#       Cms_preview). Two files are exempt: orkshell-interop.css (the designated
#       coupling point) and cms-base.css (it must neutralize the
#       #theme_container default.theme emits on standalone org sites).
#       C1 does NOT cover cms/css/cms-admin.css or the cms/ + Cms_* admin
#       templates: the OGRE admin is definitionally an ORK-hosted application
#       surface, renders inside the shell, and has no portability claim to
#       protect. C2 still applies there.
#   C2  CMS CSS may not DEFINE a token in the CRM's --ork-* namespace.
#       Reading one with var() is fine. Scope: all CMS css + templates.
#   C3  A content-block template may not carry a STATIC inline <style> block.
#       A <style> whose body interpolates PHP is legal — blocks/columns.tpl
#       interpolates $fdbCount into grid-template-columns and therefore cannot
#       be a static stylesheet. Scope: frontdoor/blocks/**.tpl.
#   C4  Site_shell.tpl may not link orkui.css, tokens.css or
#       orkshell-interop.css. Scope: Site_shell.tpl.
#   C5  CRM CSS may not name a CMS selector (.fd-, .cms-, .org-).
#       Scope: style/orkui.css, style/tokens.css, style/reports.css.
#
# Comment text is stripped before the rules run — the files in scope discuss
# these very patterns in prose (including the literal string "<style>" inside
# PHP docblocks), and documentation is not a violation.

usage() {
    sed -n '3,28p' "$0" | sed 's/^# \{0,1\}//'
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
        CANDIDATES=$(git ls-files "$CMS_PUBLIC/*" "$CMS_ADMIN/*" "$CRM_STYLE/*" $CMS_SURFACES)
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

# Position of the first "//" that opens a line comment. A "//" preceded by a
# colon is a URL scheme ("https://…", "url(//cdn/…)"), not a comment.
function line_comment_pos(s,    p, q, abs) {
    p = 1
    while (1) {
        q = index(substr(s, p), "//")
        if (q == 0) return 0
        abs = p + q - 1
        if (abs > 1 && substr(s, abs - 1, 1) == ":") {
            p = abs + 2
            continue
        }
        return abs
    }
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
# in_block / in_html are file-scoped state, so multi-line comments carry over.
function decomment(s,    out, b, h, l, first, kind, p) {
    out = ""
    while (length(s) > 0) {
        if (in_block) {
            p = index(s, "*/")
            if (p == 0) return out
            s = substr(s, p + 2)
            in_block = 0
            continue
        }
        if (in_html) {
            p = index(s, "-->")
            if (p == 0) return out
            s = substr(s, p + 3)
            in_html = 0
            continue
        }
        b = index(s, "/*")
        h = tpl ? index(s, "<!--") : 0
        l = tpl ? line_comment_pos(s) : 0
        first = 0
        kind = ""
        if (b > 0)                              { first = b; kind = "b" }
        if (h > 0 && (first == 0 || h < first)) { first = h; kind = "h" }
        if (l > 0 && (first == 0 || l < first)) { first = l; kind = "l" }
        if (first == 0) return out s
        # A space in place of the comment keeps the tokens either side apart.
        out = out substr(s, 1, first - 1) " "
        if (kind == "l") return out
        if (kind == "b") { in_block = 1; s = substr(s, first + 2) }
        else             { in_html = 1;  s = substr(s, first + 4) }
    }
    return out
}

# C3's state machine. A <style> element is a violation only if NOTHING between
# <style> and </style> interpolates PHP — a static block belongs in a real
# stylesheet, while blocks/columns.tpl's grid-template-columns genuinely cannot
# leave the template because it interpolates the column count.
function scan_style(s,    p, q, seg) {
    while (1) {
        if (!in_style) {
            p = index(s, "<style")
            if (p == 0) return
            in_style = 1
            style_line = FNR
            style_php = 0
            s = substr(s, p + 6)
            continue
        }
        q = index(s, "</style")
        if (q == 0) {
            if (index(s, "<?") > 0) style_php = 1
            return
        }
        seg = substr(s, 1, q - 1)
        if (index(seg, "<?") > 0) style_php = 1
        if (!style_php) report("C3", style_line, C3_MSG, C3_FIX)
        in_style = 0
        s = substr(s, q + 7)
    }
}

BEGIN {
    C3_MSG = "Static inline <style> block in a content-block template."
    C3_FIX = "Block CSS belongs in frontdoor/css/blocks.css so it is cacheable, lintable and visible to duplication analysis. Only a <style> whose body interpolates PHP per instance (blocks/columns.tpl) may stay inline."
}
{
    line = decomment($0)

    if (C1 && line ~ /(#theme_container|#newmenu|\.ork-)/)
        report("C1", FNR, "CMS CSS names an ORK application-shell selector.", \
               "Standalone org sites do not load orkui.css, so this rule is dead there and couples the layers everywhere else. Move it to frontdoor/css/orkshell-interop.css.")

    # C2: DEFINING an --ork-* token. `var(--ork-x)` is a read and is fine; a
    # bare `--ork-x:` at the start of a declaration is a write.
    if (C2 && line ~ /(^|[;{ \t])--ork-[a-z0-9-]+[ \t]*:/)
        report("C2", FNR, "CMS CSS defines a token in the CRM's --ork-* namespace.", \
               "Rename it to --cms-* or --fd-* and scope it to the CMS root. Reading an --ork-* token with var() is fine; defining one is not.")

    if (C3) scan_style(line)

    if (C4 && line ~ /(orkui\.css|tokens\.css|orkshell-interop\.css)/)
        report("C4", FNR, "Site_shell.tpl links a stylesheet a standalone org site must not load.", \
               "Org sites load cms-base.css instead of the CRM stylesheets, and need no ORK-shell interop layer. Remove the link.")

    if (C5 && line ~ /(\.fd-|\.cms-|\.org-)/)
        report("C5", FNR, "CRM CSS names a CMS selector.", \
               "The CRM must not style CMS surfaces. Move the rule into the matching CMS stylesheet under frontdoor/css/ or cms/css/.")
}
END {
    # An unterminated <style> is still an inline block, and a static one.
    if (C3 && in_style && !style_php) report("C3", style_line, C3_MSG, C3_FIX)
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

    C1=0; C2=0; C3=0; C4=0; C5=0

    case "$f" in
        # The two files allowed to name an ORK selector. $INTEROP is the
        # designated coupling point; $CMS_BASE must neutralize the
        # #theme_container that default.theme emits on standalone org sites.
        # Both still get C2.
        "$INTEROP"|"$CMS_BASE") C2=1 ;;
        # Public CMS side: must be able to stand alone -> C1 + C2.
        "$CMS_PUBLIC"/*.css|"$CMS_PUBLIC"/*.tpl) C1=1; C2=1 ;;
        # OGRE admin: definitionally in-shell, so C1 does not apply. C2 does.
        "$CMS_ADMIN"/*.css|"$CMS_ADMIN"/*.tpl) C2=1 ;;
    esac
    # Public CMS surface templates, which sit one directory above frontdoor/.
    for s in $CMS_SURFACES; do
        [ "$f" = "$s" ] && { C1=1; C2=1; break; }
    done
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
