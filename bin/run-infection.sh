#!/bin/sh
#
# Run Infection mutation tests. Pass extra args to scope a milestone, e.g.:
#   bin/run-infection.sh --filter=CalendarServiceTest
#
# Full-repo thresholds are in infection.json5; milestone sprints should pass
# a scoped filter covering touched code. See 06-test-framework.md.
#
set -e
cd "$(dirname "$0")/.."

if [ ! -f vendor/bin/infection ]; then
    echo "Missing vendor/bin/infection — run: composer install" >&2
    exit 1
fi

export ENVIRONMENT=TEST

# Prefer pcov for coverage collection when available.
if php -m 2>/dev/null | grep -q '^pcov$'; then
    export XDEBUG_MODE=off
fi

exec php -d memory_limit=512M vendor/bin/infection --configuration=infection.json5 --show-mutations "$@"
