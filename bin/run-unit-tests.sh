#!/bin/sh
#
# Run the full ORK3 backend unit test suite (sign-off command — no filters).
#
# Requires PHP 8.2+, composer dev dependencies, and the docker-compose DB
# (ork3-php8-db on localhost:19306 by default). PHPUnit uses sandbox
# (ork3-php8-test-db on localhost:19307 / ork_test). See
# docs/megiddo/refactor/06-test-framework.md.
#
set -e
cd "$(dirname "$0")/.."

if [ ! -f vendor/bin/phpunit ]; then
    echo "Missing vendor/bin/phpunit — run: composer install" >&2
    exit 1
fi

# --allow-catalog-drift downgrades ONE check: the committed catalog hashes. Those cover the
# `fixed_extract` catalogs, which are extracted from the mirror into tools/ork-db/extracted/ --
# a .gitignored directory -- so the check compares a committed constant against a file every
# developer regenerates locally, and any legitimate prod reload turns it red for everyone.
#
# It had been red long enough that this script never reached PHPUnit at all (drift-check runs
# under `set -e`, above), so people ran phpunit directly and the sign-off command stopped being
# used. A permanently-red gate is not a gate. The drift is still PRINTED on every run, and
# `bin/ork-db drift-check --strict` without this flag still fails on it, so nothing is hidden --
# it just no longer blocks the tests. Every deterministic check here still blocks, including
# table references, which reads only committed sources.
echo "== ork-db drift-check --strict --allow-catalog-drift =="
php tools/ork-db/cli.php drift-check --strict --allow-catalog-drift

export ENVIRONMENT=TEST
exec php vendor/bin/phpunit -c phpunit.xml.dist "$@"
