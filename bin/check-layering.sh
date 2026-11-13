#!/bin/sh
#
# ORK3 architectural layering gate.
#
# Enforces the post-Megiddo separation (PRs #492/#493/#494, merged 2026-08-18):
#
#   system/lib/ork3/   domain logic + ALL database access
#   orkservice/        SOAP/JSON API surface over the domain
#   orkui/             routing + presentation; talks to the backend ONLY
#                      through orkui/model/ wrappers
#
# Reference: docs/megiddo/refactor/idioms-00-charter.md  (§2 static isolation, §4.1)
#            docs/megiddo/refactor/01-code-decomposition.md
#
# Usage:
#   bin/check-layering.sh --staged             # staged blob content (pre-commit)
#   bin/check-layering.sh --range master..HEAD # files changed in a range (pre-push / pre-PR)
#   bin/check-layering.sh --files a.php b.tpl  # explicit paths (editor hook)
#   bin/check-layering.sh --all                # every tracked file in scope (audit)
#
# Exit 0 = clean, 1 = violations found, 2 = bad invocation.
#
# Escape hatch (deliberate exceptions only): ORK3_ALLOW_LAYER_VIOLATION=1
# is honoured by the git hooks that call this script, not by the script itself.

usage() {
    sed -n '3,26p' "$0" | sed 's/^# \{0,1\}//'
}

# Byte-wise matching: orkui/ carries UTF-8 content and stray non-UTF-8 bytes,
# and a locale-aware awk aborts on them.
LC_ALL=C
export LC_ALL

REPO_ROOT=$(git rev-parse --show-toplevel 2>/dev/null)
if [ -z "$REPO_ROOT" ]; then
    echo "check-layering: not inside a git repository — skipping." >&2
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
            [ -z "$RANGE" ] && { echo "check-layering: --range needs a revision range." >&2; exit 2; }
            shift
            ;;
        --files)
            MODE=files
            shift
            FILES="$*"
            break
            ;;
        -h|--help) usage; exit 0 ;;
        *) echo "check-layering: unknown argument '$1'" >&2; usage >&2; exit 2 ;;
    esac
    shift
done

[ -z "$MODE" ] && MODE=all

# ---------------------------------------------------------------------------
# Candidate file list
# ---------------------------------------------------------------------------
SYS_CONTROLLER="system/lib/system/class.Controller.php"

case "$MODE" in
    staged) CANDIDATES=$(git diff --cached --name-only --diff-filter=ACM) ;;
    range)  CANDIDATES=$(git diff --name-only --diff-filter=ACM "$RANGE") ;;
    files)
        # Accept absolute paths (editor / hook callers pass them) by rebasing
        # onto the repo root; anything outside the repo stays absolute and is
        # then dropped by the scope filter below.
        CANDIDATES=$(printf '%s\n' $FILES | sed "s|^$REPO_ROOT/||")
        ;;
    all)    CANDIDATES=$(git ls-files 'orkui/*' "$SYS_CONTROLLER") ;;
esac

[ -z "$CANDIDATES" ] && exit 0

# ---------------------------------------------------------------------------
# Domain class list — derived from the filesystem so it self-maintains as new
# system/lib/ork3/class.*.php files land. DangerAudit is handled by R4.
# ---------------------------------------------------------------------------
DOMAIN_CLASSES=$(ls system/lib/ork3/class.*.php 2>/dev/null \
    | sed 's|.*/class\.||; s|\.php$||' \
    | grep -v '^DangerAudit$' \
    | tr '\n' ' ')

# Inline JS in templates legitimately uses these builtin constructors, so they
# are dropped from R5 for .tpl/.theme only (controllers are pure PHP and keep them).
JS_BUILTIN_COLLISIONS='Map Event'

DOMAIN_CLASSES_TPL=""
for c in $DOMAIN_CLASSES; do
    keep=1
    for j in $JS_BUILTIN_COLLISIONS; do
        [ "$c" = "$j" ] && keep=0
    done
    [ "$keep" = 1 ] && DOMAIN_CLASSES_TPL="$DOMAIN_CLASSES_TPL $c"
done

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
function report(rule, msg, fix,   _) {
    printf "  %s%s%s  %s:%d\n", C_RED, rule, C_OFF, file, FNR
    printf "        %s\n", msg
    printf "        %s-> %s%s\n", C_DIM, fix, C_OFF
    hits++
}
BEGIN {
    nclasses = split(classes, C, " ")
    for (i = 1; i <= nclasses; i++) lc[i] = tolower(C[i])
}
{
    line = $0

    # Comment-only lines are documentation, not call sites (the charter itself
    # quotes these patterns in prose). Skip them.
    if (line ~ /^[ \t]*(\/\/|#|\*|\/\*)/) next

    low = tolower(line)

    if (R1 && line ~ /[$]DB->/)
        report("R1", "Raw $DB access inside orkui/ — the frontend must not touch the database.", \
               "Move the query into system/lib/ork3/class.<Domain>.php and expose it via orkui/model/model.<Domain>.php.")

    if (R2 && line ~ /Ork3::[$]Lib/)
        report("R2", "orkui/ reaches past the model layer straight into the domain lib.", \
               "Add a snake_case wrapper in orkui/model/model.<Domain>.php, then call $this->Domain->method().")

    if (R3 && line ~ /Ork3::[$]Lib/)
        report("R3", "class.Controller.php must bootstrap through models, not Ork3::$Lib.", \
               "Use $this->load_model('Name') then $this->Name->snake_case_method().")

    if (R4 && low ~ /new[ \t]+dangeraudit[ \t]*\(/)
        report("R4", "Audit trail invoked directly from a controller.", \
               "Use $this->load_model('Authorization'); $this->Authorization->audit(...).")

    # R5 is the expensive one — gate it behind a cheap prefilter.
    if (R5 && low ~ /new[ \t]+[a-z0-9_]+[ \t]*\(/) {
        for (i = 1; i <= nclasses; i++) {
            if (low ~ ("new[ \t]+" lc[i] "[ \t]*\\(")) {
                report("R5", "Domain class " C[i] "() instantiated outside orkui/model/.", \
                       "Instantiate it inside orkui/model/model.<Name>.php and expose a wrapper; controllers and templates orchestrate only.")
                break
            }
        }
    }

    if (R6 && (low ~ /(from|join|into)[ \t]+ork_/ || low ~ /update[ \t]+ork_/))
        report("R6", "Raw SQL in a template.", \
               "Templates render DTOs. Move the query into system/lib/ork3/ and pass the result in through $this->data[...].")
}
END { exit (hits > 0) ? 1 : 0 }
AWKEOF

TOTAL=0
SCANNED=0

for f in $CANDIDATES; do
    # Scope: orkui/ plus the one shared controller base the charter calls out.
    case "$f" in
        orkui/*) : ;;
        "$SYS_CONTROLLER") : ;;
        *) continue ;;
    esac
    # Only source files can carry a layering violation — this also keeps images,
    # fonts and other binary assets out of the scanner.
    case "$f" in
        *.php|*.tpl|*.theme|*.inc|*.phtml) : ;;
        *) continue ;;
    esac
    # Never scan third-party or generated code.
    case "$f" in
        */vendor/*|*/node_modules/*) continue ;;
    esac

    R1=0; R2=0; R3=0; R4=0; R5=0; R6=0
    CLASSES="$DOMAIN_CLASSES"

    case "$f" in
        orkui/*) R1=1; R2=1 ;;
    esac
    case "$f" in
        "$SYS_CONTROLLER") R3=1 ;;
    esac
    case "$f" in
        orkui/controller/*) R4=1; R5=1 ;;
    esac
    case "$f" in
        *.tpl|*.theme) R4=1; R5=1; R6=1; CLASSES="$DOMAIN_CLASSES_TPL" ;;
    esac

    if [ "$MODE" = "staged" ]; then
        git show ":$f" > "$CONTENT" 2>/dev/null || continue
    else
        [ -f "$f" ] || continue
        cat "$f" > "$CONTENT" 2>/dev/null || continue
    fi

    SCANNED=$((SCANNED + 1))
    awk -v file="$f" -v classes="$CLASSES" \
        -v C_RED="$C_RED" -v C_DIM="$C_DIM" -v C_OFF="$C_OFF" \
        -v R1="$R1" -v R2="$R2" -v R3="$R3" -v R4="$R4" -v R5="$R5" -v R6="$R6" \
        -f "$AWKPROG" "$CONTENT"
    [ $? -ne 0 ] && TOTAL=$((TOTAL + 1))
done

if [ "$TOTAL" -gt 0 ]; then
    echo ""
    echo "  Layering gate: $TOTAL file(s) violate the ORK3 layer separation."
    echo "  Rules: docs/megiddo/refactor/idioms-00-charter.md  ·  audit with: bin/check-layering.sh --all"
    exit 1
fi

exit 0
