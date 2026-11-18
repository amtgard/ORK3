#!/usr/bin/env bash
# Sourced from .greenblue after GB_HEV_* defaults. Overrides from .gb/secrets-mode.
set -euo pipefail

_ork3_secrets_mode_file="${GB_ROOT:-}/.gb/secrets-mode"
_ork3_secrets_mode="local"
if [[ -f "$_ork3_secrets_mode_file" ]]; then
    _ork3_secrets_mode="$(tr -d '[:space:]' < "$_ork3_secrets_mode_file")"
fi

case "$_ork3_secrets_mode" in
    plaintext)
        GB_HEV_SYNC_ON_DEV_UP="0"
        GB_HEV_RENDER_ON_DEV_UP="0"
        GB_HEV_SYNC_ON_DEPLOY="0"
        GB_HEV_RENDER_ON_DEPLOY="0"
        GB_HEV_MOUNT_MAP=""
        ;;
    local)
        GB_HEV_SYNC_SOURCE="local"
        GB_HEV_SYNC_ON_DEV_UP="${GB_HEV_SYNC_ON_DEV_UP:-1}"
        GB_HEV_RENDER_ON_DEV_UP="${GB_HEV_RENDER_ON_DEV_UP:-1}"
        GB_HEV_MOUNT_MAP="${GB_HEV_MOUNT_MAP:-ork-web:ORK_HEV_WEB_SECRETS}"
        ;;
    host-store)
        GB_HEV_SYNC_SOURCE="host-store"
        GB_HEV_SYNC_ON_DEV_UP="${GB_HEV_SYNC_ON_DEV_UP:-1}"
        GB_HEV_RENDER_ON_DEV_UP="${GB_HEV_RENDER_ON_DEV_UP:-1}"
        GB_HEV_MOUNT_MAP="${GB_HEV_MOUNT_MAP:-ork-web:ORK_HEV_WEB_SECRETS}"
        ;;
    *)
        echo "host-env/load-secrets-mode.sh: unknown mode '${_ork3_secrets_mode}' in ${_ork3_secrets_mode_file}" >&2
        exit 1
        ;;
esac

export GB_HEV_SYNC_SOURCE GB_HEV_SYNC_ON_DEV_UP GB_HEV_RENDER_ON_DEV_UP
export GB_HEV_SYNC_ON_DEPLOY GB_HEV_RENDER_ON_DEPLOY GB_HEV_MOUNT_MAP
