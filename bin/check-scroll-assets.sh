#!/bin/sh
#
# The Scrollery asset guard. One entry point, called by .githooks/pre-push and by
# .github/workflows/scroll-guards.yml.
#
# It answers two questions that nothing else in the repo answers:
#
#   1. Does every asset the certificate partials reference still exist?
#      tests/Unit/ScrollAssetPathsTest.php walks the whole order x rank x variant x
#      recipe matrix and resolves every src/href. It has always been able to catch a
#      certificate that renders one broken image at exactly one order and one rank —
#      Arcanyst shipped 2.1.3 with flame and zodiac both missing their rank-0 file —
#      but until this script it ran in NO automation at all. The repo has no
#      .github/workflows/, and .githooks/pre-commit is formatting-only and exit 0s,
#      so a rebase against upstream could silently orphan art and nobody would know
#      until an officer printed it.
#
#   2. Are the WebP renditions still in step with their masters?
#      bin/build-scroll-renditions.sh --check re-hashes every master against
#      assets/scrolls/renditions.json. Added, removed or edited art without a
#      rebuild fails here.
#
# Check 2 is pure shell and always runs. Check 1 is PHPUnit, and PHPUnit cannot start
# without a database: tests/bootstrap.php requires startup.php, which constructs
# YapoMysql eagerly at startup.php:31. Verified: the Scroll suite needs the connection
# but no schema — 313 tests pass against a completely EMPTY `ork_test` — so CI needs
# nothing more than a bare MariaDB service. Locally, if the sandbox is not up, check 1
# is reported as skipped rather than failed, because "docker is not running" is not a
# broken build.
#
# Usage:
#   sh bin/check-scroll-assets.sh            # skip check 1 if the sandbox DB is down
#   sh bin/check-scroll-assets.sh --require-db   # fail instead of skipping (CI)
#
set -eu

cd "$(git rev-parse --show-toplevel 2>/dev/null)" 2>/dev/null || cd "$(dirname "$0")/.."

REQUIRE_DB=0
for arg in "$@"; do
    case "$arg" in
        --require-db) REQUIRE_DB=1 ;;
        *) echo "Unknown argument: $arg" >&2; exit 2 ;;
    esac
done

STATUS=0

# ---------------------------------------------------------------------------
# 1. Renditions in step with masters (no PHP, no DB, no ImageMagick)
# ---------------------------------------------------------------------------
echo "== scroll renditions =="
if sh bin/build-scroll-renditions.sh --check; then
    :
else
    STATUS=1
fi

# ---------------------------------------------------------------------------
# 2. Every referenced asset exists, and every ground's contrast is measured
# ---------------------------------------------------------------------------
echo ""
echo "== scroll asset paths and ground contrast =="

if [ ! -x vendor/bin/phpunit ]; then
    echo "SKIP: vendor/bin/phpunit missing — run 'composer install'."
    [ "$REQUIRE_DB" -eq 1 ] && STATUS=1
else
    DB_HOST=${ORK3_TEST_DB_HOST:-127.0.0.1}
    DB_PORT=${ORK3_TEST_DB_PORT:-19307}
    if php -r 'exit(@fsockopen($argv[1], (int) $argv[2], $e, $s, 2) ? 0 : 1);' "$DB_HOST" "$DB_PORT"; then
        ENVIRONMENT=TEST php vendor/bin/phpunit \
            --filter 'ScrollAssetPaths|ScrollGroundContrast' \
            --no-coverage || STATUS=1
    else
        echo "SKIP: sandbox DB unreachable at ${DB_HOST}:${DB_PORT}."
        echo "      Bring it up with: docker-compose -f docker-compose.php8.yml up -d"
        if [ "$REQUIRE_DB" -eq 1 ]; then
            echo "FAIL: --require-db was given." >&2
            STATUS=1
        fi
    fi
fi

echo ""
if [ "$STATUS" -eq 0 ]; then
    echo "Scroll asset guard: OK"
else
    echo "Scroll asset guard: FAILED" >&2
fi
exit "$STATUS"
