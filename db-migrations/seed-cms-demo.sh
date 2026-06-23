#!/usr/bin/env bash
#
# seed-cms-demo.sh — populate the full CMS demo/test dataset in one command.
#
# Creates everything a tester needs to exercise Project Front Door + the CMS:
#   1. CMS schema            (ork_cms_* tables — idempotent CREATE IF NOT EXISTS)
#   2. Home / front-door page (the rich landing page)
#   3. Exemplar pages         (about, join, faq, media-gallery)
#   4. Marketing nav menu      (seeded from the canonical defaults)
#   5. Nav relink              (points menu items + CTA/login at real targets;
#                               must run AFTER the pages exist so slugs resolve)
#   6. Exemplar blog post      (new-rules-of-play, tagged rules + documents)
#
# Every step is idempotent — safe to re-run. Order matters: pages are seeded
# before the nav relink (which resolves page slugs) and the home page before
# the relink (which updates its nav block).
#
# Usage (from the repo root, with the dev containers up):
#   db-migrations/seed-cms-demo.sh
#
# Container / DB names default to the docker-compose.php8 dev setup; override
# via environment if your local names differ:
#   APP_CONTAINER=ork3-php8-app DB_CONTAINER=ork3-php8-db \
#   DB_USER=ork DB_PASS=secret DB_NAME=ork db-migrations/seed-cms-demo.sh
#
set -euo pipefail

APP_CONTAINER="${APP_CONTAINER:-ork3-php8-app}"
DB_CONTAINER="${DB_CONTAINER:-ork3-php8-db}"
DB_USER="${DB_USER:-ork}"
DB_PASS="${DB_PASS:-secret}"
DB_NAME="${DB_NAME:-ork}"

# Path to db-migrations inside the app container.
APP_MIG="/var/www/ork.amtgard.com/db-migrations"
# Path to this directory on the host (so we can pipe the .sql into the DB).
HOST_MIG="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Pick the available MariaDB/MySQL client inside the DB container.
db_client() {
    if docker exec "$DB_CONTAINER" sh -lc 'command -v mariadb' >/dev/null 2>&1; then
        echo mariadb
    else
        echo mysql
    fi
}
CLIENT="$(db_client)"

step() { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }

step "1/6  CMS schema (ork_cms_* tables)"
docker exec -i "$DB_CONTAINER" "$CLIENT" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" \
    < "$HOST_MIG/2026-06-23-cms-foundation.sql"
echo "    schema applied"

step "2/6  Home / front-door page"
docker exec "$APP_CONTAINER" php "$APP_MIG/2026-06-23-cms-seed-home.php"

step "3/6  Exemplar pages (about, join, faq, media-gallery)"
docker exec "$APP_CONTAINER" php "$APP_MIG/2026-06-23-cms-seed-exemplars.php"

step "4/6  Marketing nav menu"
docker exec "$APP_CONTAINER" php "$APP_MIG/2026-06-23-cms-seed-nav.php"

step "5/6  Nav relink (menu items + CTA/login -> real destinations)"
docker exec "$APP_CONTAINER" php "$APP_MIG/2026-06-23-cms-nav-relink.php"

step "6/6  Exemplar blog post (new-rules-of-play)"
docker exec "$APP_CONTAINER" php "$APP_MIG/2026-06-23-cms-seed-blog.php"

printf '\n\033[1;32m✓ CMS demo data populated.\033[0m Visit the front door at:\n'
printf '    http://localhost:19080/orkui/index.php?Route=\n'
printf '  Pages:  /Page/view/about · /Page/view/join · /Page/view/faq · /Page/view/media-gallery\n'
printf '  Blog:   /Blog/index\n'
