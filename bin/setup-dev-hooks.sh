#!/bin/sh
#
# One-time dev setup: activate the shared git hooks in .githooks/.
# Safe to re-run. Normally this happens automatically via `npm install`
# (package.json "prepare" script) — run this only if you skip npm.
#
#   sh bin/setup-dev-hooks.sh

set -e
cd "$(git rev-parse --show-toplevel)"
git config core.hooksPath .githooks
echo "✅ git hooks activated (core.hooksPath = .githooks)."
echo "   PHP files you commit will be auto-formatted to PSR-12 via tools/php-cs-fixer.phar."
