#!/bin/sh
#
# Normalize LEADING tab indentation to spaces (4 spaces per tab level) in the
# given files, in place. Only *leading* tabs are converted — tabs that appear
# after any non-tab character (e.g. mid-line inside a string) are left untouched.
#
# SCOPE: .css and .js only. That is what the pre-commit hook passes, and the
# limit is deliberate. "Leading" is a line-position test, not a syntactic one:
# a continuation line inside a multi-line string that happens to start with a
# tab IS rewritten, because this script cannot tell indentation from content.
# For .css/.js in this repo that is safe (no multi-line template literal or
# data: URI carries a tab-led line). It is NOT safe in general for .tpl, where
# templates embed JS template literals whose tab-led lines are emitted markup —
# see Live_index.tpl and Admin_player.tpl. Do not widen the hook's pathspec to
# .tpl without re-checking that.
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
