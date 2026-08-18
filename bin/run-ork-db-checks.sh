#!/bin/sh
#
# ORK3 ork-db CI checks — migration coverage, catalog fingerprints, tool tests.
# Safe to run without Docker (drift-check --strict skips live mirror when unreachable).
#
set -e
cd "$(dirname "$0")/.."

if [ ! -f vendor/bin/phpunit ]; then
    echo "Missing vendor/bin/phpunit — run: composer install" >&2
    exit 1
fi

echo "== ork-db drift-check --strict =="
php tools/ork-db/cli.php drift-check --strict

echo ""
echo "== ork-db PHPUnit suite =="
php vendor/bin/phpunit -c phpunit.ork-db.xml.dist "$@"
