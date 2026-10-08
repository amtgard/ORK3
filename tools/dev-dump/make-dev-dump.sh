#!/usr/bin/env bash
# Build a redacted ORK database dump for a new developer.
#
#   ./make-dev-dump.sh park                 save the current local DB so you can get it back
#   ./make-dev-dump.sh load <prod-dump.sql> drop, recreate and import a production backup
#   ./make-dev-dump.sh redact               apply tools/dev-dump/redact.sql
#   ./make-dev-dump.sh verify               scan the live DB for leftover names/emails
#   ./make-dev-dump.sh logins               set known passwords (incl. an ORK admin)
#   ./make-dev-dump.sh export               write ork-redacted-<stamp>.sql.gz and scan it
#   ./make-dev-dump.sh restore <park.sql.gz> put a parked database back
#
# Runs ONLY against the local DATABASE container -- ork3-php8-db by default
# (not ork3-php8-app, whose image is confusingly named ork3-ork3app). Override
# with ORK_DEV_CONTAINER. It refuses anything else: every step here is
# destructive and must never reach production.
set -euo pipefail

CONTAINER="${ORK_DEV_CONTAINER:-ork3-php8-db}"
# Two separate trees on purpose. The snapshot holds REAL data and must never
# leave this machine; the redacted dump is the one you hand over. Keeping them
# in one directory is how the wrong file eventually gets attached to an email.
# ("park" is the verb; the folder is not called that -- in ORK-land a park is
# a chapter, and ~/db-parks read like a database OF parks.)
SNAPSHOT_DIR="${ORK_SNAPSHOT_DIR:-$HOME/ork-db-snapshots}"
OUT_DIR="${ORK_OUT_DIR:-$HOME/ork-db-redacted}"
STAMP="$(date +%Y-%m-%d-%H%M)"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

die() { echo "error: $*" >&2; exit 1; }

# Safety: the container must be the local dev one, and must not look like prod.
docker inspect "$CONTAINER" >/dev/null 2>&1 || die "container '$CONTAINER' not found (local docker only)"
case "$CONTAINER" in
  *prod*|*blue*|*green*) die "refusing to touch '$CONTAINER' -- that name looks like production" ;;
esac

dsh() { docker exec -i "$CONTAINER" sh -c "$1"; }
MYSQL='mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"'
DUMP='mariadb-dump -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" --single-transaction --quick --skip-lock-tables "$MARIADB_DATABASE"'

cmd="${1:-}"; shift || true
mkdir -p "$SNAPSHOT_DIR" "$OUT_DIR"

case "$cmd" in

park)
    f="$SNAPSHOT_DIR/ork-local-$STAMP.sql.gz"
    echo "parking $CONTAINER -> $f"
    dsh "$DUMP" | gzip -c > "$f"
    [ -s "$f" ] || die "park produced an empty file"
    echo "parked: $f ($(du -h "$f" | cut -f1))"
    echo "restore with:  $0 restore $f"
    ;;

load)
    src="${1:-}"; [ -n "$src" ] || die "usage: $0 load <prod-dump.sql[.gz]>"
    [ -f "$src" ] || die "no such file: $src"
    echo "WARNING: this DROPS the current database in the container '$CONTAINER'."
    echo "Park it first if you have not:  $0 park"
    read -r -p "Type '$CONTAINER' to continue (anything else aborts): " ok
    if [ "$ok" != "$CONTAINER" ]; then
        echo "  you typed: $ok" >&2
        echo "  expected:  $CONTAINER" >&2
        die "aborted -- nothing was changed"
    fi
    dsh 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" -e "DROP DATABASE IF EXISTS \`$MARIADB_DATABASE\`; CREATE DATABASE \`$MARIADB_DATABASE\` DEFAULT CHARACTER SET utf8mb4;"'
    # Optional headroom, straight from staging/README.md section 2. Both revert
    # on container restart; nothing pins them. The stock 128 MB pool thrashes on
    # ork_attendance secondary-index updates once the dataset outgrows it.
    # Needs the root password; skipped silently if it is not set.
    if docker exec "$CONTAINER" sh -c '[ -n "$MARIADB_ROOT_PASSWORD" ]' 2>/dev/null; then
        echo "raising buffer pool to 1 GB and relaxing flush for the import ..."
        dsh 'mariadb -u root -p"$MARIADB_ROOT_PASSWORD" -e "SET GLOBAL innodb_flush_log_at_trx_commit = 2; SET GLOBAL innodb_buffer_pool_size = 1073741824"' \
            || echo "  (could not raise them -- continuing at stock settings)"
    else
        echo "note: MARIADB_ROOT_PASSWORD not set in the container; importing at stock"
        echo "      settings (128 MB pool). Slower, but correct."
    fi

    echo "importing $src ..."
    # Batch COMMITs: prod dumps are single-row INSERTs and fsync per row is ~28x
    # slower (measured 2026-08-24: 26 KB/s vs 726 KB/s). See staging/README.md s2.
    #
    # The COMMIT must land on a STATEMENT boundary, not just every 100k lines.
    # staging/README.md says the wrapper is "harmless" on multi-row dumps -- that
    # is only true when statements are one per line. Our own park/export dumps
    # are mariadb-dump's default multi-row form, where one INSERT spans thousands
    # of lines, and a blind COMMIT lands inside a VALUES list:
    #   ERROR 1064 ... check the manual ... near 'COMMIT'
    # So: count lines, but only emit COMMIT once the current line ends in ';'.
    if [[ "$src" == *.gz ]]; then gzcat "$src"; else cat "$src"; fi \
      | awk 'BEGIN{print "SET autocommit=0;"; n=0} {print; n++} n>=100000 && /;[ \t]*$/ {print "COMMIT;"; n=0} END{print "COMMIT;"}' \
      | dsh "$MYSQL"
    echo "imported."
    echo "note: the raised buffer pool / relaxed flush revert on container restart."
    ;;

redact)
    [ -f "$HERE/redact.sql" ] || die "redact.sql missing"
    echo "applying redact.sql ..."
    dsh "$MYSQL" < "$HERE/redact.sql"
    echo "redacted."
    "$0" verify
    ;;

verify)
    echo "checking the live database for leftovers ..."
    dsh "$MYSQL -t -e \"
      select 'ork_mundane names/emails' as check_, count(*) as should_be_zero
        from ork_mundane where given_name<>'' or surname<>'' or email<>''
      union all select 'ork_mundane_myisam', count(*)
        from ork_mundane_myisam where given_name<>'' or surname<>'' or email<>''
      union all select 'ork_credential', count(*) from ork_credential
      union all select 'ork_credential_myisam', count(*) from ork_credential_myisam
      union all select 'ork_danger_audit', count(*) from ork_danger_audit
      union all select 'ork_session (live tokens)', count(*) from ork_session
      union all select 'ork_idp_auth (oauth)', count(*) from ork_idp_auth
      union all select 'ork_attendance_link', count(*) from ork_attendance_link
      union all select 'ork_selfreg_link', count(*) from ork_selfreg_link;\""
    ;;

logins)
    # Known passwords on one account per tier, including a GLOBAL ORK ADMIN --
    # which is the whole reason the session table can be wiped. Runs inside the
    # app container; the script itself refuses any DB_HOSTNAME but ork3-php8-db.
    app="${ORK_DEV_APP_CONTAINER:-ork3-php8-app}"
    docker inspect "$app" >/dev/null 2>&1 || die "app container '$app' not found"
    docker exec -i "$app" php /var/www/ork.amtgard.com/db-migrations/dev-set-test-logins.php \
      || die "dev-set-test-logins.php failed"
    ;;

export)
    f="$OUT_DIR/ork-redacted-$STAMP.sql.gz"
    echo "exporting -> $f"
    dsh "$DUMP" | gzip -c > "$f"
    [ -s "$f" ] || die "export produced an empty file"
    echo "scanning the dump itself for anything that looks like an email ..."
    n=$(gzcat "$f" | grep -coE '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' || true)
    echo "  email-shaped strings in dump: $n"
    if [ "$n" -gt 0 ]; then
        echo "  !! non-zero. Inspect before sharing:"
        gzcat "$f" | grep -oE '[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}' | sort -u | head -10 | sed 's/^/     /'
    fi
    echo "exported: $f ($(du -h "$f" | cut -f1))"
    echo
    echo "Hand over the file above. The recipient imports it, then logs in at"
    echo "http://localhost:19080/orkui/ with the credentials printed by:"
    echo "    $0 logins"
    echo "(run that BEFORE export so the dump already carries them)."
    ;;

restore)
    src="${1:-}"; [ -n "$src" ] || die "usage: $0 restore <park.sql.gz>"
    [ -f "$src" ] || die "no such file: $src"
    echo "WARNING: this DROPS the current database in the container '$CONTAINER'"
    echo "         and restores it from $src"
    read -r -p "Type '$CONTAINER' to continue (anything else aborts): " ok
    if [ "$ok" != "$CONTAINER" ]; then
        echo "  you typed: $ok" >&2
        echo "  expected:  $CONTAINER" >&2
        die "aborted -- nothing was changed"
    fi
    dsh 'mariadb -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" -e "DROP DATABASE IF EXISTS \`$MARIADB_DATABASE\`; CREATE DATABASE \`$MARIADB_DATABASE\` DEFAULT CHARACTER SET utf8mb4;"'
    if [[ "$src" == *.gz ]]; then gzcat "$src"; else cat "$src"; fi \
      | awk 'BEGIN{print "SET autocommit=0;"; n=0} {print; n++} n>=100000 && /;[ \t]*$/ {print "COMMIT;"; n=0} END{print "COMMIT;"}' \
      | dsh "$MYSQL"
    echo "restored from $src"
    ;;

*)
    sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'
    exit 1
    ;;
esac
