#!/bin/bash
# tests/php/run-waiver-tests.sh — run Waiver domain tests inside docker
set -e
cd "$(dirname "$0")/../.."
docker exec -e ENVIRONMENT=DEV -w /var/www/ork.amtgard.com/tests/php ork3-php8-app php WaiverTest.php "$@"
