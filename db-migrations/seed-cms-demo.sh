#!/usr/bin/env bash
#
# seed-cms-demo.sh — populate the full CMS demo/test dataset in one command.
#
# Creates everything a tester needs to exercise Project Front Door + the CMS:
#   1. CMS schema: every db-migrations/*-cms-*.sql, applied in filename
#      (== date) order — later seeds depend on columns the foundation file
#      alone lacks (deleted_at, slug_live, site, theme, view-analytics).
#   2. Home / front-door page   (the rich landing page)
#   3. Exemplar pages           (about, join, faq, media-gallery)
#   4. Staff-roster pages       (board-of-directors, team-leads — drafts)
#   5. amtgard.com replication  (only if the .amtgard-assets staging dir exists)
#   6. Marketing nav menu       (seeded from the canonical defaults)
#   7. Nav relink (legacy)      (menu items + CTA/login -> live destinations)
#   8. Nav polish               (rename "AI Programs" -> "Programs", fill
#                                Official Resources dropdown children)
#   9. Nav relink -> CMS pages  (point each label at its replicated CMS page)
#  10. Home relink              (fix stored home CTA/card links + baked hrefs)
#  11. Exemplar blog post       (new-rules-of-play, tagged rules + documents)
#
# Every step is idempotent — safe to re-run. Order matters: ALL schema
# migrations apply before any content seed; pages are seeded before the nav
# relinks (which resolve page slugs); nav-polish runs before the CMS-page relink
# (which is hardened to accept both the pre- and post-rename label anyway).
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

step "1/11  CMS schema + all CMS migrations (idempotent, date-ordered)"
# Apply every ork_cms_* migration in filename (== date) order, not just the
# foundation file. LC_ALL=C keeps '-' < 'b' so 2026-07-07 sorts before
# 2026-07-07b, and the new 2026-07-23 page-type migration sorts last. The
# '*-cms-*.sql' glob is scoped to the CMS migrations only — it deliberately does
# NOT sweep in unrelated (and some data-mutating) app migrations in this dir.
CMS_SQL="$(ls "$HOST_MIG"/*-cms-*.sql | LC_ALL=C sort)"
for sql in $CMS_SQL; do
    printf '    applying %s\n' "$(basename "$sql")"
    docker exec -i "$DB_CONTAINER" "$CLIENT" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$sql"
done
echo "    all CMS migrations applied"

step "2/11  Home / front-door page"
docker exec "$APP_CONTAINER" php "$APP_MIG/2026-06-23-cms-seed-home.php"

step "3/11  Exemplar pages (about, join, faq, media-gallery)"
docker exec "$APP_CONTAINER" php "$APP_MIG/2026-06-23-cms-seed-exemplars.php"

step "4/11  Staff-roster pages (board-of-directors, team-leads — drafts)"
docker exec "$APP_CONTAINER" php "$APP_MIG/2026-06-27-cms-seed-staff-roster.php"

step "5/11  amtgard.com replication pages (only if staging assets present)"
if docker exec "$APP_CONTAINER" test -d "$APP_MIG/.amtgard-assets/specs"; then
    docker exec "$APP_CONTAINER" php "$APP_MIG/2026-07-08-cms-seed-amtgard.php" "$APP_MIG/.amtgard-assets"
else
    echo "    (skipped — no .amtgard-assets/specs staging dir inside the container)"
fi

step "6/11  Marketing nav menu"
docker exec "$APP_CONTAINER" php "$APP_MIG/2026-06-23-cms-seed-nav.php"

step "7/11  Nav relink (legacy: menu items + CTA/login -> live destinations)"
docker exec "$APP_CONTAINER" php "$APP_MIG/2026-06-23-cms-nav-relink.php"

step "8/11  Nav polish (rename AI Programs -> Programs; Official Resources kids)"
docker exec "$APP_CONTAINER" php "$APP_MIG/2026-07-08-cms-nav-polish.php"

step "9/11  Nav relink -> CMS pages (point each label at its replicated page)"
docker exec "$APP_CONTAINER" php "$APP_MIG/2026-07-08-cms-nav-relink-amtgard.php"

step "10/11  Home relink (fix stored home CTA/card links + baked hrefs)"
docker exec "$APP_CONTAINER" php "$APP_MIG/2026-07-08-cms-home-relink.php"

step "11/11  Exemplar blog post (new-rules-of-play)"
docker exec "$APP_CONTAINER" php "$APP_MIG/2026-06-23-cms-seed-blog.php"

printf '\n\033[1;32m✓ CMS demo data populated.\033[0m Visit the front door at:\n'
printf '    http://localhost:19080/orkui/index.php?Route=\n'
printf '  Pages:  /Page/view/about · /Page/view/join · /Page/view/faq · /Page/view/media-gallery\n'
printf '  Blog:   /Blog/index\n'
