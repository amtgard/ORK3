#!/usr/bin/env bash
# Switch local ORK3 dev secrets posture for generic-green-blue + host-env-vault.
# Usage: ork3-secrets-mode.sh [plaintext|local|host-store|status]
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODE_FILE="${ROOT}/.gb/secrets-mode"

usage() {
    cat <<EOF
Usage: $(basename "$0") [plaintext|local|host-store|status]

  plaintext    App .env only; gb skips HEV sync/render and inject mounts.
  local        Plain profile .env under host-env-vault dev-shared/secrets (GB_HEV_SYNC_SOURCE=local).
  host-store   Encrypted store (run migrate-ork3-secrets-to-host-store.sh first).

Writes ${MODE_FILE}. Re-run gb deploy to apply.
EOF
}

cmd="${1:-status}"

case "$cmd" in
    -h | --help)
        usage
        exit 0
        ;;
    status)
        if [[ -f "$MODE_FILE" ]]; then
            echo "mode=$(tr -d '[:space:]' < "$MODE_FILE")"
        else
            echo "mode=local (default; no ${MODE_FILE})"
        fi
        if [[ -f "${ROOT}/.greenblue" ]]; then
            # shellcheck source=host-env/load-secrets-mode.sh disable=SC1091
            GB_ROOT="$ROOT"
            source "${ROOT}/host-env/load-secrets-mode.sh"
            echo "GB_HEV_SYNC_SOURCE=${GB_HEV_SYNC_SOURCE:-}"
            echo "GB_HEV_SYNC_ON_DEV_UP=${GB_HEV_SYNC_ON_DEV_UP:-}"
            echo "GB_HEV_RENDER_ON_DEV_UP=${GB_HEV_RENDER_ON_DEV_UP:-}"
            echo "GB_HEV_MOUNT_MAP=${GB_HEV_MOUNT_MAP:-<empty>}"
        fi
        exit 0
        ;;
    plaintext | local | host-store)
        mkdir -p "${ROOT}/.gb"
        printf '%s\n' "$cmd" >"$MODE_FILE"
        echo "set secrets mode: $cmd"
        if [[ "$cmd" == "host-store" ]]; then
            echo "warning: run scripts/migrate-ork3-secrets-to-host-store.sh first when moving to host-store" >&2
        fi
        echo "next: gb \"${ROOT}\" deploy"
        ;;
    *)
        usage >&2
        exit 1
        ;;
esac
