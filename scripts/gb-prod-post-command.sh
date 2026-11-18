#!/usr/bin/env bash
# GB_HOOK_POST_DEPLOY (prod): verify secrets mount and HTTP on the active slot (no sandbox).
set -euo pipefail

ROOT="${GB_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
STATE_FILE="${GB_STATE_FILE:-${GB_STATE_DIR:-/var/lib/ork3-greenblue}/active}"

active="green"
if [[ -f "$STATE_FILE" ]]; then
    read -r active <"$STATE_FILE" || true
    active="${active:-green}"
fi

case "$active" in
    blue)
        port="${GB_BLUE_PORT:-29080}"
        container="${GB_BLUE_CONTAINER:-ork3-php8-app-blue}"
        ;;
    green)
        port="${GB_GREEN_PORT:-19080}"
        container="${GB_GREEN_CONTAINER:-ork3-php8-app-green}"
        ;;
    *)
        echo "gb-prod-post-command: unknown active slot '${active}' in ${STATE_FILE}" >&2
        exit 1
        ;;
esac

if ! docker exec "$container" test -f /run/secrets/secrets.env 2>/dev/null; then
    echo "gb-prod-post-command: missing /run/secrets/secrets.env in ${container}" >&2
    echo "gb-prod-post-command: check HEV render, GB_HEV_MOUNT_MAP, and compose secrets volume" >&2
    exit 1
fi

health_path="${GB_HEALTH_PATH:-/orkui/}"
if ! curl -sfI "http://127.0.0.1:${port}${health_path}" >/dev/null; then
    echo "gb-prod-post-command: HTTP check failed http://127.0.0.1:${port}${health_path}" >&2
    exit 1
fi

echo "gb-prod-post-command: OK active=${active} container=${container} port=${port} secrets=ok"