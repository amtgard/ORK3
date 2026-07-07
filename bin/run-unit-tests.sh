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

export ENVIRONMENT=TEST
exec php vendor/bin/phpunit -c phpunit.xml.dist "$@"
