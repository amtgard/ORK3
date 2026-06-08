#!/bin/sh
#
# Normalize LEADING tab indentation to spaces (4 spaces per tab level) in the
# given files, in place. Only *leading* tabs are converted — tabs that appear
# after any non-tab character (e.g. inside a JS template literal or a CSS
# string) are left untouched, so this is safe to run on .css / .js / .tpl.
#
# Idempotent: running it twice produces no further changes. Trailing newline
# is preserved.
#
# Dependencies: POSIX `awk` only (no npm / node / php). Always available.
#
#   sh bin/normalize-leading-tabs.sh file1 [file2 ...]
#
# Used by the shared pre-commit hook (.githooks/pre-commit) to keep committed
# CSS/JS off hard tabs, mirroring the PSR-12 php-cs-fixer boy-scout approach.

SPACES_PER_TAB=4

status=0
for f in "$@"; do
    [ -f "$f" ] || continue
    tmp="$f.normtabs.$$"
    if awk -v n="$SPACES_PER_TAB" '
        {
            i = 0
            while (substr($0, i + 1, 1) == "\t") i++
            if (i > 0) {
                pad = sprintf("%*s", i * n, "")
                $0 = pad substr($0, i + 1)
            }
            print
        }
    ' "$f" > "$tmp"; then
        # Preserve original "no trailing newline" state: if the source did not
        # end in a newline, strip the one awk's print added.
        if [ -n "$(tail -c1 "$f")" ]; then
            printf '%s' "$(cat "$tmp")" > "$tmp.nonl" && mv "$tmp.nonl" "$tmp"
        fi
        mv "$tmp" "$f"
    else
        echo "⚠️  normalize-leading-tabs: awk failed on $f (left unchanged)." >&2
        rm -f "$tmp"
        status=1
    fi
done

exit $status
