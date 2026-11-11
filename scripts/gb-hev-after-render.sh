#!/usr/bin/env bash
# GB_HOOK_HEV_AFTER_RENDER — runtime socket mount dir for compose (in addition to GB_HEV_MOUNT_MAP).
set -euo pipefail

if [[ -z "${GB_HEV_CONFIG:-}" || ! -f "$GB_HEV_CONFIG" ]]; then
    exit 0
fi

file="${GB_RUNTIME_ENV_FILE:-${GB_ROOT}/.gb/runtime.env}"
runtime_dir="$(cd "$(dirname "$GB_HEV_CONFIG")" && pwd)/run"
mkdir -p "$(dirname "$file")"
printf 'HOST_ENV_VAULT_RUNTIME_DIR=%s\n' "$runtime_dir" >>"$file"
