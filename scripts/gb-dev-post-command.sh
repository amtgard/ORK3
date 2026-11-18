#!/usr/bin/env bash
# GB_HOOK_POST_COMMAND (dev): sandbox DB, schema/data, app profile, health — no manual ork-db steps.
set -euo pipefail

ROOT="${GB_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
cd "$ROOT"

if [[ -f "${ROOT}/scripts/gb-greenblue-helpers.sh" ]]; then
    # shellcheck source=scripts/gb-greenblue-helpers.sh disable=SC1091
    source "${ROOT}/scripts/gb-greenblue-helpers.sh"
fi

compose_env() {
    local -a args=(compose --project-directory "$ROOT" -p "${GB_WEB_COMPOSE_PROJECT:-ork3-php8}" -f "${ROOT}/docker/compose.prod.yml")
    if [[ -f "${ROOT}/docker/compose.blue.yml" ]]; then
        args+=(-f "${ROOT}/docker/compose.blue.yml")
    fi
  args+=(-f "${ROOT}/docker/compose.dev.yml")
    if [[ -f "${ROOT}/.gb/runtime.env" ]]; then
        args+=(--env-file "${ROOT}/.gb/runtime.env")
    fi
    if [[ -f "${ROOT}/.env" ]]; then
        args+=(--env-file "${ROOT}/.env")
    fi
    docker "${args[@]}" "$@"
}

apply_sandbox_schema_delta() {
    local -a files=(
        2026-09-25-api-tally.sql
        2026-10-05-ork-idp-mailbox-challenge.sql
        2026-10-09-court-planner-consolidated.sql
        2026-10-13-awards-date-index.sql
    )
    for f in "${files[@]}"; do
        local path="${ROOT}/db-migrations/${f}"
        if [[ ! -f "$path" ]]; then
            continue
        fi
        echo "gb-dev-post-command: applying ${f} → ork_test"
        docker exec -i ork3-php8-test-db mariadb -uork -psecret ork_test <"$path" || {
            echo "gb-dev-post-command: warning — ${f} apply returned non-zero (may already be applied)" >&2
        }
    done
}

wait_mariadb() {
    local container="$1"
    local tries="${2:-60}"
    for _ in $(seq 1 "$tries"); do
        if docker exec "$container" mariadb-admin ping -uork -psecret --silent 2>/dev/null; then
            return 0
        fi
        sleep 1
    done
    echo "gb-dev-post-command: MariaDB not ready in ${container}" >&2
    return 1
}

echo "gb-dev-post-command: ensuring sandbox database on ${GB_SHARED_NETWORK:-ork3-php8-net}..."
shared_net="${GB_SHARED_NETWORK:-ork3-php8-net}"
if ! docker network inspect "$shared_net" >/dev/null 2>&1; then
    docker network create "$shared_net"
fi
if docker ps -a --format '{{.Names}}' | grep -Fxq 'ork3-php8-test-db'; then
    docker start ork3-php8-test-db >/dev/null 2>&1 || true
else
    docker compose --project-directory "$ROOT" -f "${ROOT}/docker-compose.php8.yml" up -d ork3testdb
fi
if ! docker exec ork3-php8-app getent hosts ork3-php8-test-db >/dev/null 2>&1; then
    echo "gb-dev-post-command: attaching ork3-php8-test-db to ${shared_net} (compose project mismatch)"
    docker network connect "$shared_net" ork3-php8-test-db 2>/dev/null || true
fi
if ! docker exec ork3-php8-app getent hosts ork3-php8-test-db >/dev/null 2>&1; then
    echo "gb-dev-post-command: ensuring sandbox DB DNS on ${shared_net}"
    docker network connect "$shared_net" ork3-php8-test-db 2>/dev/null || true
fi

wait_mariadb ork3-php8-test-db
wait_mariadb ork3-php8-db 30 || true

sandbox_table_exists() {
    local table="$1"
    docker exec ork3-php8-test-db mariadb -uork -psecret ork_test -N -e \
        "SELECT 1 FROM information_schema.tables WHERE table_schema='ork_test' AND table_name='${table}' LIMIT 1" \
        2>/dev/null | grep -q 1
}

sandbox_refresh_flags=()
for required in ork_idp_mailbox_challenge ork_api_tally ork_court; do
    if ! sandbox_table_exists "$required"; then
        sandbox_refresh_flags=(--force-refresh)
        echo "gb-dev-post-command: sandbox missing ${required}; will force-refresh render/apply"
        break
    fi
done
if [[ ${#sandbox_refresh_flags[@]} -eq 0 ]]; then
    if ENVIRONMENT=DEV "${ROOT}/bin/ork-db" drift-check 2>&1 | grep -q 'FAIL  unclassified'; then
        sandbox_refresh_flags=(--force-refresh)
        echo "gb-dev-post-command: unclassified migrations; will force-refresh render/apply"
    fi
fi

export ENVIRONMENT=DEV
if [[ ${#sandbox_refresh_flags[@]} -gt 0 ]]; then
    apply_sandbox_schema_delta
    sandbox_refresh_flags=()
fi
echo "gb-dev-post-command: deploy-sandbox (seed data, assets, credentials)..."
if ! "${ROOT}/bin/ork-db" deploy-sandbox --yes "${sandbox_refresh_flags[@]}"; then
    echo "gb-dev-post-command: deploy-sandbox reported errors (sandbox schema may already be current)" >&2
fi

legacy="${GB_LEGACY_CONTAINER:-ork3-php8-app}"
if docker ps --format '{{.Names}}' | grep -Fxq "$legacy"; then
    echo "gb-dev-post-command: ensuring php-fpm in ${legacy}..."
    docker exec "$legacy" bash -lc '
        POOL=/etc/php/8.1/fpm/pool.d/www.conf
        sed -i "/^env\[.*\] = \"\"$/d" "$POOL"
        sed -i "/^env\[ORK3_/d; /^env\[CF_/d; /^env\[AMAZON_/d; /^env\[DB_/d; /^env\[HOST_ENV/d; /^env\[IDP_/d" "$POOL"
        service nginx start 2>/dev/null || true
        service php8.1-fpm start 2>/dev/null || service php8.1-fpm restart
    '
fi

port="${GB_GREEN_PORT:-19080}"
if curl -sf "http://127.0.0.1:${port}/orkui/" >/dev/null 2>&1; then
    echo "gb-dev-post-command: health OK http://127.0.0.1:${port}/orkui/"
else
    echo "gb-dev-post-command: warning — curl http://127.0.0.1:${port}/orkui/ failed (container may still be starting)" >&2
fi

echo "gb-dev-post-command: local ORK is ready (sandbox DB, profile dev). Logins: megiddo / test-db-player, admin / password"