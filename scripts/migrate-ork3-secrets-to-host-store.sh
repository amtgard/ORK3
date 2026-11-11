#!/usr/bin/env bash
# Migrate ORK3 inject secrets into host-env-vault host-store (operator / transition).
# Usage:
#   migrate-ork3-secrets-to-host-store.sh INSTALL_DIR [OPTIONS]
#
# Reads INSTALL_DIR/config.php and optional INSTALL_DIR/.dev.env, writes ork-web.env,
# then runs host-env-vault store import when the binary is available.
set -euo pipefail

usage() {
    cat <<'EOF'
Usage: migrate-ork3-secrets-to-host-store.sh INSTALL_DIR [options]

  INSTALL_DIR   ORK3 checkout (must contain config.php).

Options:
  --config PATH       config.php (default: INSTALL_DIR/config.php)
  --dev-env PATH      optional .dev.env (default: INSTALL_DIR/.dev.env if present)
  --output PATH       profile env output (default: INSTALL_DIR/.gb/ork-web.env)
  --hev-bin PATH      host-env-vault binary
  --hev-config PATH   host-config.toml for import
  --non-interactive   Pass --non-interactive to store import
  -h, --help

Stdout/stderr must not contain secret values.
EOF
}

INSTALL_DIR=""
CONFIG_PATH=""
DEV_ENV_PATH=""
OUTPUT_PATH=""
HEV_BIN=""
HEV_CONFIG=""
NON_INTERACTIVE=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        -h | --help)
            usage
            exit 0
            ;;
        --config)
            CONFIG_PATH="$2"
            shift 2
            ;;
        --dev-env)
            DEV_ENV_PATH="$2"
            shift 2
            ;;
        --output)
            OUTPUT_PATH="$2"
            shift 2
            ;;
        --hev-bin)
            HEV_BIN="$2"
            shift 2
            ;;
        --hev-config)
            HEV_CONFIG="$2"
            shift 2
            ;;
        --non-interactive)
            NON_INTERACTIVE=1
            shift
            ;;
        -*)
            echo "unknown option: $1" >&2
            usage >&2
            exit 1
            ;;
        *)
            if [[ -z "$INSTALL_DIR" ]]; then
                INSTALL_DIR="$1"
                shift
            else
                echo "unexpected argument: $1" >&2
                exit 1
            fi
            ;;
    esac
done

if [[ -z "$INSTALL_DIR" ]]; then
    usage >&2
    exit 1
fi

INSTALL_DIR="$(cd "$INSTALL_DIR" && pwd)"
ROOT="$INSTALL_DIR"
MIGRATE_PHP="${ROOT}/system/lib/system/class.Ork3SecretsMigrate.php"

if [[ ! -f "$MIGRATE_PHP" ]]; then
    echo "missing ${MIGRATE_PHP}" >&2
    exit 1
fi

if [[ -z "$CONFIG_PATH" ]]; then
    CONFIG_PATH="${ROOT}/config.php"
fi

if [[ -z "$DEV_ENV_PATH" && -f "${ROOT}/.dev.env" ]]; then
    DEV_ENV_PATH="${ROOT}/.dev.env"
fi

if [[ -z "$OUTPUT_PATH" ]]; then
    OUTPUT_PATH="${ROOT}/.gb/ork-web.env"
fi

if [[ ! -f "$CONFIG_PATH" ]]; then
    echo "missing config file: ${CONFIG_PATH}" >&2
    exit 1
fi

mkdir -p "$(dirname "$OUTPUT_PATH")"

extract_profile_env() {
    local config="$1"
    local dev_env="$2"
    local output="$3"
    ORK3_MIGRATE_ROOT="$ROOT" \
    ORK3_MIGRATE_CONFIG="$config" \
    ORK3_MIGRATE_DEV_ENV="$dev_env" \
    ORK3_MIGRATE_OUTPUT="$output" \
    php -d display_errors=0 <<'PHP'
<?php
$root = getenv('ORK3_MIGRATE_ROOT');
require_once $root . '/system/lib/system/class.Ork3SecretsMigrate.php';
$config = getenv('ORK3_MIGRATE_CONFIG');
$output = getenv('ORK3_MIGRATE_OUTPUT');
$dev = getenv('ORK3_MIGRATE_DEV_ENV');
$migrate = new Ork3SecretsMigrate();
$values = $migrate->collectInjectValues($config, $dev !== false && $dev !== '' ? $dev : null);
$migrate->writeProfileEnv($output, $values);
PHP
}

extract_profile_env "$CONFIG_PATH" "${DEV_ENV_PATH:-}" "$OUTPUT_PATH"
echo "wrote profile env: ${OUTPUT_PATH}"

resolve_hev_bin() {
    if [[ -n "$HEV_BIN" && -x "$HEV_BIN" ]]; then
        echo "$HEV_BIN"
        return
    fi
    if command -v host-env-vault >/dev/null 2>&1; then
        command -v host-env-vault
        return
    fi
    local repo="${GB_HEV_REPO:-}"
    if [[ -z "$repo" && -d "${ROOT}/../host-env-vault" ]]; then
        repo="$(cd "${ROOT}/../host-env-vault" && pwd)"
    fi
    if [[ -n "$repo" ]]; then
        for candidate in \
            "${repo}/target/release/host-env-vault" \
            "${repo}/target/debug/host-env-vault"; do
            if [[ -x "$candidate" ]]; then
                echo "$candidate"
                return
            fi
        done
    fi
    echo ""
}

HEV_BIN_RESOLVED="$(resolve_hev_bin)"
if [[ -z "$HEV_BIN_RESOLVED" ]]; then
    echo "host-env-vault binary not found (install host-env-vault or pass --hev-bin)" >&2
    exit 1
fi

if [[ -z "$HEV_CONFIG" ]]; then
    if [[ -n "${GB_HEV_CONFIG:-}" && -f "${GB_HEV_CONFIG}" ]]; then
        HEV_CONFIG="$GB_HEV_CONFIG"
    elif [[ -n "${GB_HEV_REPO:-}" && -f "${GB_HEV_REPO}/dev-shared/host-config.toml" ]]; then
        HEV_CONFIG="${GB_HEV_REPO}/dev-shared/host-config.toml"
    elif [[ -f "${ROOT}/../host-env-vault/dev-shared/host-config.toml" ]]; then
        HEV_CONFIG="$(cd "${ROOT}/../host-env-vault" && pwd)/dev-shared/host-config.toml"
    fi
fi

if [[ -z "$HEV_CONFIG" || ! -f "$HEV_CONFIG" ]]; then
    echo "missing host-config.toml; pass --hev-config" >&2
    exit 1
fi

IMPORT_DIR="$(mktemp -d "${TMPDIR:-/tmp}/ork3-hev-import.XXXXXX")"
cleanup() {
    rm -rf "$IMPORT_DIR"
}
trap cleanup EXIT

cp "$OUTPUT_PATH" "${IMPORT_DIR}/ork-web.env"
mkdir -p "${IMPORT_DIR}/files"

if [[ -f "${ROOT}/files/ga4-sa.json" ]]; then
    cp "${ROOT}/files/ga4-sa.json" "${IMPORT_DIR}/files/ga4-sa.json"
fi

echo "invoking host-env-vault store import (profile ork-web)"
(
    cd "$(dirname "$HEV_CONFIG")"
    if [[ "$NON_INTERACTIVE" -eq 1 ]]; then
        "$HEV_BIN_RESOLVED" --config "$(basename "$HEV_CONFIG")" store --non-interactive \
            import --from-dir "$IMPORT_DIR" --profile ork-web
    else
        "$HEV_BIN_RESOLVED" --config "$(basename "$HEV_CONFIG")" store \
            import --from-dir "$IMPORT_DIR" --profile ork-web
    fi
)

echo "host-store import complete"
