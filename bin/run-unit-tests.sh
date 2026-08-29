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

# Both phpunit configs declare a <coverage> report. With no xdebug/pcov installed PHPUnit 11
# raises a test-runner warning, and it fails the run on those by default -- so this script
# exited 1 with zero failing tests on any machine without a coverage driver. That is the same
# always-red-so-always-ignored shape the drift-check opt-out came from, so ask for coverage
# only when a driver can actually produce it.
COVERAGE_FLAG=""
if ! php -r 'exit(extension_loaded("xdebug") || extension_loaded("pcov") ? 0 : 1);'; then
    COVERAGE_FLAG="--no-coverage"
    echo "== no coverage driver (xdebug/pcov) — running with --no-coverage =="
fi

# drift-check gates the suite: unclassified migrations, code referencing tables the
# committed schema does not define, committed catalog hashes, and (when the mirror is up)
# live-mirror catalog drift. It runs under `set -e`, so a failure here stops PHPUnit.
#
# There used to be an --allow-catalog-drift opt-out here. The catalog hashes covered
# tools/ork-db/extracted/*.sql, a .gitignored directory each developer regenerated from
# their own mirror, so the check was red for everyone after any prod reload and this whole
# script never reached PHPUnit. The catalogs are now committed under
# tools/ork-db/templates/catalogs/, the hash is stable across machines, and the opt-out is
# gone with the reason for it.
echo "== ork-db drift-check --strict =="
php tools/ork-db/cli.php drift-check --strict

# The ork-db suite (tests/Unit/OrkDb + tests/Integration/OrkDb) is excluded from
# phpunit.xml.dist and lives in its own config, so until now nothing ran it -- the tests that
# prove the schema tooling works were themselves ungated. They run here, first, because
# SchemaDiffIntegrationTest bootstraps ork_test and the main suites then run against that
# freshly built sandbox.
#
# Prerequisites beyond the two docker databases: a completed `bin/ork-db extract`. The
# renderer reads extracted/mundane_real.json, configuration.sql and events.json, which are
# .gitignored because they hold real player data, so `bin/ork-db bootstrap` (or extract) has
# to have been run once on this machine.
#
# --exclude-group mirror-data drops exactly one test:
# GoldenRenderTest::testDeterministicRenderMatchesGoldenSha256, which pins a sha256 of the
# WHOLE rendered sandbox -- including the sections built from that extract. mundane_real.json
# carries `token`, `token_expires`, `password_salt` and `xtoken` for four real accounts, so
# one production login moves that hash. It is not reproducible across machines and gating it
# would recreate the permanently-red check this script used to step around. Its committed-
# source half (schema + catalogs) IS gated, as
# GoldenRenderTest::testCommittedSchemaAndCatalogsMatchGoldenSha256.
#
# Two HeraldryVisualIntegrationTest cases read app pages for sandbox-namespace rows. They
# skip unless `bin/ork-db use dev` has pointed ork3app at ork_test; that is a workstation
# mode, not something a test may assume.
echo "== ork-db suite (tests/Unit/OrkDb + tests/Integration/OrkDb) =="
php vendor/bin/phpunit -c phpunit.ork-db.xml.dist $COVERAGE_FLAG --exclude-group mirror-data

echo "== main suite =="
export ENVIRONMENT=TEST
exec php vendor/bin/phpunit -c phpunit.xml.dist $COVERAGE_FLAG "$@"
