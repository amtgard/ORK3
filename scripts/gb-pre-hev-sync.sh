#!/usr/bin/env bash
# Copy ORK3 catalog fragment and refresh merged catalog.json before host-env-vault sync.
set -euo pipefail

if [[ "${GB_PRE_HEV_SYNC_ACTIVE:-}" == "1" ]]; then
    exit 0
fi
export GB_PRE_HEV_SYNC_ACTIVE=1

ROOT="${GB_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
if [[ -f "${ROOT}/scripts/gb-greenblue-helpers.sh" ]]; then
    # shellcheck source=scripts/gb-greenblue-helpers.sh disable=SC1091
    source "${ROOT}/scripts/gb-greenblue-helpers.sh"
fi
fragment="${ROOT}/host-env/catalog.d/30-ork3.json"

if [[ ! -f "$fragment" ]]; then
    exit 0
fi

vault_root="${GB_HEV_VAULT_ROOT:-}"
if [[ -z "$vault_root" && -n "${GB_HEV_CONFIG:-}" && -f "$GB_HEV_CONFIG" ]]; then
    vault_root="$(cd "$(dirname "$GB_HEV_CONFIG")/.." && pwd)"
fi

if [[ -z "$vault_root" || ! -d "$vault_root/dev-shared/catalog.d" ]]; then
    exit 0
fi

cp "$fragment" "$vault_root/dev-shared/catalog.d/30-ork3.json"

merge="${vault_root}/scripts/merge-catalog.sh"
if [[ ! -x "$merge" ]]; then
    exit 0
fi

build_out="${vault_root}/dev-shared/build/catalog.json"
secrets_dir="${vault_root}/dev-shared/secrets"
"$merge" "$build_out"
mkdir -p "$secrets_dir"
cp "$build_out" "${secrets_dir}/catalog.json"
